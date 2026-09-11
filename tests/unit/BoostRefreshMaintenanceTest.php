<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\IndexCoordinatorFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class BoostRefreshMaintenanceTest extends TestCase {
	protected function setUp(): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__ ) . '/fixtures/maintenanceRendering.php';
		require_once dirname( __DIR__ ) . '/fixtures/reconciliation.php';
		require_once dirname( __DIR__, 2 ) . '/maintenance/refreshFrauxSearchBoosts.php';
		$services = new ReconciliationTestServices();
		$services->pages = array_fill_keys( [ 1, 3, 5, 7 ], null );
		\MediaWiki\MediaWikiServices::$instance = $services;
	}

	public function testBoundedWritesUseCoordinatedIsolatedRenderingForEveryScannedId(): void {
		$command = new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts();
		$command->options = [ 'batch-size' => 1, 'start-after' => 2, 'stop-after' => 5,
			'conf' => '/fixture/LocalSettings.php' ];
		$command->execute();
		$this->assertSame( [ [ 'preflight' ], [ 'factory', null, 60, true ],
			[ 'refresh', 3, null ], [ 'heartbeat' ], [ 'write', 3 ],
			[ 'refresh', 5, null ], [ 'heartbeat' ], [ 'write', 5 ] ], IndexCoordinatorFactory::$events );
		$this->assertSame( [ [ [ 3 ], $command->options, true ], [ [ 5 ], $command->options, true ] ],
			DocumentBatchBuilder::$calls );
		$this->assertSame( $command->options, DocumentBatchBuilder::$preflightOptions );
		$this->assertFreshScans( [ [ 3 ], [ 5 ], [] ] );
	}

	public function testDryRunOnlyBuildsBoundedSourceBatchesWithoutCoordinator(): void {
		$command = new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts();
		$command->options = [ 'dry-run' => true, 'batch-size' => 2, 'stop-after' => 5 ];
		$command->execute();
		$this->assertSame( [ [ 'preflight' ] ], IndexCoordinatorFactory::$events );
		$this->assertSame( [ [ [ 1, 3 ], $command->options, false ], [ [ 5 ], $command->options, false ] ],
			DocumentBatchBuilder::$calls );
		$this->assertFreshScans( [ [ 1, 3 ], [ 5 ] ] );
	}

	public static function invalidOptions(): array {
		return [ [ [ 'batch-size' => 0 ] ], [ [ 'batch-size' => 1001 ] ], [ [ 'batch-size' => '1.5' ] ],
			[ [ 'start-after' => -1 ] ], [ [ 'stop-after' => 'bad' ] ],
			[ [ 'start-after' => 5, 'stop-after' => 5 ] ], [ [ 'start-after' => 5, 'stop-after' => 4 ] ] ];
	}

	#[DataProvider( 'invalidOptions' )]
	public function testInvalidOptionsFailBeforePreflightOrSourceAccess( array $options ): void {
		$command = new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts();
		$command->options = $options;
		try { $command->execute(); $this->fail( 'Expected invalid options.' ); }
		catch ( \InvalidArgumentException ) {
			$this->assertSame( [], IndexCoordinatorFactory::$events );
			$this->assertSame( [], \MediaWiki\MediaWikiServices::$instance->events );
		}
	}

	public function testUnavailableRendererFailsBeforeCoordinatorOrSourceAccess(): void {
		DocumentBatchBuilder::$available = false;
		try { ( new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts() )->execute(); $this->fail( 'Expected preflight failure.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( 'Renderer unavailable.', $error->getMessage() );
			$this->assertSame( [ [ 'preflight' ] ], IndexCoordinatorFactory::$events );
			$this->assertSame( [], \MediaWiki\MediaWikiServices::$instance->events );
		}
	}

	public static function renderFailures(): array { return [ [ false ], [ true ] ]; }

	#[DataProvider( 'renderFailures' )]
	public function testChildFailureStopsBeforeTheFailedPageWriteOrNextPage( bool $dryRun ): void {
		DocumentBatchBuilder::$failPage = 1;
		$command = new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts();
		$command->options = $dryRun ? [ 'dry-run' => true ] : [];
		try { $command->execute(); $this->fail( 'Expected child failure.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( 'Child failed.', $error->getMessage() );
			$this->assertNotContains( 'write', array_column( IndexCoordinatorFactory::$events, 0 ) );
			$this->assertCount( 1, DocumentBatchBuilder::$calls );
		}
	}

	public static function busyTransactions(): array {
		return [ [ 'explicitTransaction' ], [ 'pendingWrites' ], [ 'pendingCallbacks' ] ];
	}

	#[DataProvider( 'busyTransactions' )]
	public function testActiveSourceTransactionPreventsRenderingOrWrites( string $property ): void {
		$services = \MediaWiki\MediaWikiServices::$instance;
		$services->$property = true;
		try { ( new \FrauxSearch\Maintenance\RefreshFrauxSearchBoosts() )->execute(); $this->fail( 'Expected transaction rejection.' ); }
		catch ( \RuntimeException ) {
			$this->assertSame( [], $services->events );
			$this->assertSame( [], DocumentBatchBuilder::$calls );
			$this->assertNotContains( 'write', array_column( IndexCoordinatorFactory::$events, 0 ) );
		}
	}

	private function assertFreshScans( array $pages ): void {
		$expected = [];
		foreach ( $pages as $ids ) { $expected[] = [ 'snapshot' ]; $expected[] = [ 'page_scan', $ids ]; }
		$this->assertSame( $expected, \MediaWiki\MediaWikiServices::$instance->events );
	}
}

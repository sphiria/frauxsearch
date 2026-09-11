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
class RecoveryMaintenanceTest extends TestCase {
	protected function setUp(): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__ ) . '/fixtures/maintenanceRendering.php';
		require_once dirname( __DIR__, 2 ) . '/maintenance/recoverFrauxSearchIndex.php';
	}

	public static function renderingActions(): array {
		return [ [ 'drain', true, 'drain', null ], [ 'finish-run', 'run-id', 'finish', 'run-id' ],
			[ 'abort-run', 'run-id', 'abort', 'run-id' ] ];
	}

	#[DataProvider( 'renderingActions' )]
	public function testRenderingActionsUseIsolatedBuilderAndForwardHeartbeat(
		string $option, mixed $value, string $action, ?string $run
	): void {
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = [ $option => $value, 'index' => 'isolated', 'conf' => '/fixture/LocalSettings.php' ];
		$command->execute();
		$this->assertSame( [ [ 'preflight' ], [ 'factory', 'isolated', 60, true ],
			[ $action, 7, $run ], [ 'heartbeat' ], [ 'write', 7 ], [ 'status' ] ], IndexCoordinatorFactory::$events );
		$this->assertSame( [ [ [ 7 ], $command->options, true ] ], DocumentBatchBuilder::$calls );
		$this->assertSame( $command->options, DocumentBatchBuilder::$preflightOptions );
	}

	public static function nonRenderingActions(): array {
		return [ [ [], [] ], [ [ 'operation-id' => 'op', 'task-id' => '0' ], [ [ 'adopt', 'op', 0 ] ] ],
			[ [ 'confirm-not-submitted' => 'op' ], [ [ 'confirm', 'op' ] ] ] ];
	}

	#[DataProvider( 'nonRenderingActions' )]
	public function testStatusAndTaskRecoveryDoNotRequireRenderer( array $options, array $events ): void {
		DocumentBatchBuilder::$available = false;
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = $options;
		$command->execute();
		$this->assertSame( [ [ 'factory', null, 60, false ], ...$events, [ 'status' ] ], IndexCoordinatorFactory::$events );
		$this->assertSame( [], DocumentBatchBuilder::$calls );
	}

	public static function renderingOptions(): array {
		return array_map( static fn ( array $action ): array => array_slice( $action, 0, 2 ), self::renderingActions() );
	}

	#[DataProvider( 'renderingOptions' )]
	public function testUnavailableRendererPreventsRecoveryMutation( string $option, mixed $value ): void {
		DocumentBatchBuilder::$available = false;
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = [ $option => $value ];
		try { $command->execute(); $this->fail( 'Expected renderer preflight failure.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( 'Renderer unavailable.', $error->getMessage() );
			$this->assertSame( [ [ 'preflight' ] ], IndexCoordinatorFactory::$events );
		}
	}

	public static function invalidActions(): array {
		return [ [ [ 'drain' => true, 'abort-run' => 'run' ] ], [ [ 'operation-id' => 'op' ] ],
			[ [ 'task-id' => '1' ] ], [ [ 'operation-id' => 'op', 'task-id' => '-1' ] ],
			[ [ 'operation-id' => 'op', 'task-id' => '1.5' ] ] ];
	}

	#[DataProvider( 'invalidActions' )]
	public function testInvalidActionsFailBeforePreflightOrCoordinator( array $options ): void {
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = $options;
		try { $command->execute(); $this->fail( 'Expected invalid action.' ); }
		catch ( \InvalidArgumentException ) { $this->assertSame( [], IndexCoordinatorFactory::$events ); }
	}

	public function testLeaseFailurePropagatesWithoutPageWriteOrSuccessStatus(): void {
		$failure = new \RuntimeException( 'Lease ownership was lost.' );
		IndexCoordinatorFactory::$heartbeatFailure = $failure;
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = [ 'drain' => true ];
		try { $command->execute(); $this->fail( 'Expected heartbeat failure.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( $failure, $error );
			$this->assertSame( [ [ 'preflight' ], [ 'factory', null, 60, true ],
				[ 'drain', 7, null ], [ 'heartbeat' ] ], IndexCoordinatorFactory::$events );
		}
	}

	public function testChildFailurePreventsPageWriteOrSuccessStatus(): void {
		DocumentBatchBuilder::$failPage = 7;
		$command = new \FrauxSearch\Maintenance\RecoverFrauxSearchIndex();
		$command->options = [ 'finish-run' => 'run-id' ];
		try { $command->execute(); $this->fail( 'Expected child failure.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( 'Child failed.', $error->getMessage() );
			$this->assertSame( [ [ 'preflight' ], [ 'factory', null, 60, true ],
				[ 'finish', 7, 'run-id' ], [ 'heartbeat' ] ], IndexCoordinatorFactory::$events );
		}
	}
}

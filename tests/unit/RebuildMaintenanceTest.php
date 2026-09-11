<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexCoordinator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class RebuildMaintenanceTest extends TestCase {
	protected function setUp(): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__, 2 ) . '/maintenance/rebuildFrauxSearchCompletionIndex.php';
		\MediaWiki\MediaWikiServices::$instance = new RebuildTestServices();
		MaintenanceCoordinator::$events = [];
		MaintenanceCoordinator::$failBatch = false;
		MaintenanceCoordinator::$failRender = false;
		MaintenanceCoordinator::$failRenderAfterBatch = false;
	}

	private function command( bool $completionOnly ) {
		if ( $completionOnly ) {
			return new class extends \FrauxSearch\Maintenance\RebuildFrauxSearchCompletionIndex {
				use RebuildBatchFixture;
				protected function newCoordinator( string $baseIndex ): IndexCoordinator { return new MaintenanceCoordinator(); }
			};
		}
		return new class extends \FrauxSearch\Maintenance\RebuildFrauxSearchIndex {
			use RebuildBatchFixture;
			protected function newCoordinator( string $baseIndex ): IndexCoordinator { return new MaintenanceCoordinator(); }
		};
	}

	public static function modes(): array { return [ 'full' => [ false ], 'completion' => [ true ] ]; }

	#[DataProvider( 'modes' )]
	public function testBothModesOnlyActivateAfterAllBatchesFinish( bool $completionOnly ): void {
		$this->command( $completionOnly )->execute();
		$this->assertSame( [ [ 'begin', $completionOnly ], [ 'batch', 'run', [], [] ],
			[ 'ready', 'run' ], [ 'finish', 'run' ] ], MaintenanceCoordinator::$events );
	}

	#[DataProvider( 'modes' )]
	public function testPartialResetIsRejectedWithoutWrites( bool $completionOnly ): void {
		$command = $this->command( $completionOnly );
		$command->options = [ 'stop-after' => '10' ];
		try { $command->execute(); $this->fail( 'Expected unsafe mode rejection' ); }
		catch ( \InvalidArgumentException ) { $this->assertSame( [], MaintenanceCoordinator::$events ); }
	}

	#[DataProvider( 'modes' )]
	public function testDryRunDoesNotCreateCoordinatorOrChangeState( bool $completionOnly ): void {
		$command = $this->command( $completionOnly );
		$command->options = [ 'dry-run' => true, 'stop-after' => '10' ];
		$command->execute();
		$this->assertSame( [], MaintenanceCoordinator::$events );
	}

	#[DataProvider( 'modes' )]
	public function testFailedBatchCannotMarkBuildReadyOrActivate( bool $completionOnly ): void {
		MaintenanceCoordinator::$failBatch = true;
		try { $this->command( $completionOnly )->execute(); $this->fail( 'Expected batch failure' ); }
		catch ( RuntimeException ) {
			$this->assertSame( [ [ 'begin', $completionOnly ], [ 'batch', 'run', [], [] ] ], MaintenanceCoordinator::$events );
		}
	}

	#[DataProvider( 'modes' )]
	public function testFailedRendererCannotWriteOrActivateAnIncompleteGeneration( bool $completionOnly ): void {
		\MediaWiki\MediaWikiServices::$instance->pageIds = [ 2 ];
		MaintenanceCoordinator::$failRender = true;
		try {
			$this->command( $completionOnly )->execute();
			$this->fail( 'Expected renderer failure' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'render failure', $error->getMessage() );
			$this->assertSame( [ [ 'begin', $completionOnly ] ], MaintenanceCoordinator::$events );
		}
	}

	#[DataProvider( 'modes' )]
	public function testLaterRendererFailureCannotActivateAlreadyWrittenBatches( bool $completionOnly ): void {
		\MediaWiki\MediaWikiServices::$instance->pageIds = [ 2 ];
		MaintenanceCoordinator::$failRenderAfterBatch = true;
		try {
			$this->command( $completionOnly )->execute();
			$this->fail( 'Incomplete generation activated.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'later render failure', $error->getMessage() );
			$this->assertSame( [ [ 'begin', $completionOnly ], [ 'batch', 'run', [], [] ] ],
				MaintenanceCoordinator::$events );
		}
	}

	#[DataProvider( 'modes' )]
	public function testDirectResumeUsesCoordinatedRefreshEvenForUnbuildablePages( bool $completionOnly ): void {
		\MediaWiki\MediaWikiServices::$instance->pageIds = [ 2 ];
		$command = $this->command( $completionOnly );
		$command->options = [ 'no-reset' => true, 'stop-after' => '2' ];
		$command->execute();
		$this->assertSame( [ [ 'refresh', 2, $completionOnly ] ], MaintenanceCoordinator::$events );
	}

	public static function unsafeRecoveryOptions(): array {
		$recovery = [ 'recover-lost-state' => str_repeat( 'a', 32 ), 'confirm-writers-stopped' => true ];
		return [
			'missing confirmation' => [ false, [ 'recover-lost-state' => str_repeat( 'a', 32 ) ] ],
			'missing epoch' => [ false, [ 'confirm-writers-stopped' => true ] ],
			'invalid epoch' => [ false, [ 'recover-lost-state' => 'invalid', 'confirm-writers-stopped' => true ] ],
			'completion' => [ true, $recovery ],
			'dry run' => [ false, $recovery + [ 'dry-run' => true ] ],
			'in place' => [ false, $recovery + [ 'no-reset' => true ] ],
			'resume' => [ false, $recovery + [ 'start-after' => '0', 'no-reset' => true ] ],
			'bounded generation' => [ false, $recovery + [ 'index' => 'test', 'generation' => true, 'stop-after' => '10' ] ],
		];
	}

	#[DataProvider( 'unsafeRecoveryOptions' )]
	public function testUnsafeRecoveryIsRejectedBeforeAnyMutation( bool $completionOnly, array $options ): void {
		$command = $this->command( $completionOnly );
		$command->options = $options;
		try { $command->execute(); $this->fail( 'Expected recovery rejection' ); }
		catch ( \InvalidArgumentException ) { $this->assertSame( [], MaintenanceCoordinator::$events ); }
	}

	#[DataProvider( 'invalidParallelOptions' )]
	public function testInvalidParallelOptionsFailBeforeCreatingARebuild( array $options ): void {
		$command = $this->command( false );
		$command->options = $options;
		try {
			$command->execute();
			$this->fail( 'Invalid rendering options were accepted.' );
		} catch ( RuntimeException | \InvalidArgumentException ) {
			$this->assertSame( [], MaintenanceCoordinator::$events );
		}
	}

	public static function invalidParallelOptions(): array {
		return [ [ [ 'render-workers' => 0 ] ], [ [ 'render-workers' => 9 ] ],
			[ [ 'render-workers' => 'invalid' ] ], [ [ 'render-workers' => 2, 'no-reset' => true ] ] ];
	}

	public function testConfirmedLostStateRecoveryPerformsFullScanBeforeActivation(): void {
		$command = $this->command( false );
		$epoch = str_repeat( 'a', 32 );
		$command->options = [ 'recover-lost-state' => $epoch, 'confirm-writers-stopped' => true ];
		$command->execute();
		$this->assertSame( [ [ 'recover', $epoch ], [ 'batch', 'run', [], [] ],
			[ 'ready', 'run' ], [ 'finish', 'run' ] ], MaintenanceCoordinator::$events );
	}
}

trait RebuildBatchFixture {
	protected function buildBatch( array $pageIds, \Closure $onBatch ): array {
		if ( MaintenanceCoordinator::$failRender ) {
			throw new RuntimeException( 'render failure' );
		}
		$batch = array_fill_keys( $pageIds, null );
		$onBatch( $batch );
		if ( MaintenanceCoordinator::$failRenderAfterBatch ) { throw new RuntimeException( 'later render failure' ); }
		return $batch;
	}
}

class MaintenanceCoordinator extends IndexCoordinator {
	public static array $events = [];
	public static bool $failBatch = false;
	public static bool $failRender = false;
	public static bool $failRenderAfterBatch = false;
	public function __construct() {}
	public function beginRebuild( bool $completionOnly ): array {
		self::$events[] = [ 'begin', $completionOnly ]; return [ 'id' => 'run' ];
	}
	public function restartAfterStateLoss( string $epoch ): array {
		self::$events[] = [ 'recover', $epoch ]; return [ 'id' => 'run' ];
	}
	public function writeBatch( string $runId, array $documents, array $completionDocuments ): void {
		self::$events[] = [ 'batch', $runId, $documents, $completionDocuments ];
		if ( self::$failBatch ) { throw new RuntimeException( 'batch failure' ); }
	}
	public function ready( string $runId ): void { self::$events[] = [ 'ready', $runId ]; }
	public function finishRebuild( string $runId ): void { self::$events[] = [ 'finish', $runId ]; }
	public function refresh( int $pageId, ?string $title = null, bool $completionOnly = false ): void {
		self::$events[] = [ 'refresh', $pageId, $completionOnly ];
	}
}

class RebuildTestServices {
	public array $pageIds = [];
	private array $selection = [];
	public function getMainConfig(): self { return $this; }
	public function get( $key ): string { return 'wiki'; }
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function flushSnapshot( ...$args ): void {}
	public function newSelectQueryBuilder(): self { return $this; }
	public function select( array $fields ): self { $this->selection = $fields; return $this; }
	public function from( ...$args ): self { return $this; }
	public function where( ...$args ): self { return $this; }
	public function orderBy( ...$args ): self { return $this; }
	public function limit( ...$args ): self { return $this; }
	public function caller( ...$args ): self { return $this; }
	public function join( ...$args ): self { return $this; }
	public function groupBy( ...$args ): self { return $this; }
	public function getWikiPageFactory(): self { return $this; }
	public function newFromID( ...$args ) { return null; }
	public function fetchResultSet(): \ArrayIterator {
		return new \ArrayIterator( $this->selection === [ 'page_id' ]
			? array_map( static fn ( int $id ) => (object)[ 'page_id' => $id ], $this->pageIds ) : [] );
	}
}

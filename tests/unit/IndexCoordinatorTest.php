<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexCoordinator;
use FrauxSearch\MeilisearchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class IndexCoordinatorTest extends TestCase {
	private MemoryCoordinationStore $store;
	private CoordinationServer $server;
	private array $source = [];
	private array $queued = [];
	private $onBuild = null;

	protected function setUp(): void {
		$this->store = new MemoryCoordinationStore();
		$this->server = new CoordinationServer();
		$this->source[1] = $this->page( 1, 1 );
		$this->server->indexes['wiki'][1] = $this->source[1]['document'];
		$this->server->indexes['wiki_completion'][1] = $this->source[1]['document'];
	}

	private function page( int $id, int $revision, array $targets = [] ): array {
		return [ 'document' => [ 'id' => $id, 'revision_id' => $revision, 'title' => "Page $id",
			'outgoing_link_ids' => $targets, 'redirects' => [] ], 'is_redirect' => false, 'redirect_target_id' => null ];
	}

	private function worker(): IndexCoordinator {
		return new IndexCoordinator( $this->store, new CoordinationClient( $this->server ), 'wiki',
			function ( int $id ): ?array {
				$built = $this->source[$id] ?? null;
				if ( $this->onBuild !== null ) { ( $this->onBuild )( $id ); }
				return $built;
			}, function ( array $ids ): void { $this->queued = array_merge( $this->queued, $ids ); }
		);
	}

	private function failure( callable $callback, string $message ): void {
		try { $callback(); $this->fail( 'Expected failure containing ' . $message ); }
		catch ( RuntimeException $e ) { $this->assertStringContainsString( $message, $e->getMessage() ); }
	}

	private function swaps(): array {
		return array_values( array_filter( $this->server->submissions, static fn ( $task ) => $task['type'] === 'indexSwap' ) );
	}

	public function testOverlappingWriterRecordsWorkWithoutBuildingUntilLockIsFree(): void {
		$second = $this->worker();
		$this->onBuild = function () use ( $second ): void {
			$this->onBuild = null;
			$this->source[1] = $this->page( 1, 2 );
			$this->failure( fn () => $second->refresh( 1 ), 'busy' );
		};
		$this->worker()->refresh( 1 );
		$this->assertCount( 1, $this->store->changes );
		$second->drain();
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( $this->server->indexes['wiki'], $this->server->indexes['wiki_completion'] );
		$this->assertSame( [], $this->store->changes );
	}

	public function testTaskTimeoutMustSettleBeforeNewSourceIsBuiltAndWritten(): void {
		$this->server->afterAccept = function ( array $task ): void { $this->server->blockedTasks[$task['uid']] = true; };
		$this->source[1] = $this->page( 1, 2 );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'timed out' );
		$this->source[1] = $this->page( 1, 3 );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'timed out' );
		$this->assertCount( 1, $this->server->submissions );
		$this->server->afterAccept = null;
		$this->server->blockedTasks = [];
		$this->worker()->drain();
		$this->assertSame( 3, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( $this->server->indexes['wiki'], $this->server->indexes['wiki_completion'] );
		$this->assertNull( $this->store->state );
	}

	public function testLateCommitWithSmallerJournalIdIsNotAcknowledgedWithoutBeingRead(): void {
		$id = $this->store->append( 1, 'Older title', false );
		$delayed = $this->store->changes[$id];
		unset( $this->store->changes[$id] );
		$this->onBuild = function () use ( $id, $delayed ): void {
			$this->onBuild = null;
			$this->source[1] = $this->page( 1, 2 );
			$this->store->changes[$id] = $delayed;
		};
		$this->worker()->refresh( 1 );
		$this->assertSame( [ $id => $delayed ], $this->store->changes );
		$this->worker()->drain();
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( [], $this->store->changes );
	}

	public function testMissingKnownTaskRetainsIntentAndPreventsFurtherSubmissions(): void {
		$this->server->afterAccept = function ( array $task ): void { unset( $this->server->tasks[$task['uid']] ); };
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'task_not_found' );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'task_not_found' );
		$this->assertSame( 1, $this->store->state['pending']['taskUid'] );
		$this->assertCount( 1, $this->server->submissions );
	}

	public function testUnencodableLocalPayloadDoesNotCreateAnUncertainMutation(): void {
		$this->source[1]['document']['text'] = "\xFF";
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'cannot be encoded' );
		$this->assertNull( $this->store->state['pending'] );
		$this->assertSame( [], $this->server->submissions );
		$this->assertNotEmpty( $this->store->changes );
		$this->source[1]['document']['text'] = 'Fixed source text';
		$this->worker()->drain();
		$this->assertSame( 'Fixed source text', $this->server->indexes['wiki'][1]['text'] );
	}

	public function testMalformedTaskIdentityCannotClearAPendingWrite(): void {
		$this->server->afterAccept = function ( array $task ): void { $this->server->tasks[$task['uid']]['uid'] = 999; };
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'matching UID' );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'matching UID' );
		$this->assertSame( 1, $this->store->state['pending']['taskUid'] );
		$this->assertCount( 1, $this->server->submissions );
		$this->server->afterAccept = null;
		$this->server->tasks[1]['uid'] = 1;
		$this->source[1] = $this->page( 1, 2 );
		$this->worker()->drain();
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
	}

	public function testLostTaskResponseBlocksFurtherMutationsUntilOperatorAdoptsTask(): void {
		$this->server->afterAccept = function (): void { throw new RuntimeException( 'lost response' ); };
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'lost response' );
		$operation = $this->store->state['pending']['id'];
		$this->server->afterAccept = null;
		$this->source[1] = $this->page( 1, 2 );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'unknown outcome' );
		$this->assertCount( 1, $this->server->submissions );
		$this->worker()->adoptTask( $operation, 1 );
		$this->worker()->drain();
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( [], $this->store->changes );
	}

	public static function modes(): array { return [ 'full' => [ false ], 'completion' => [ true ] ]; }

	#[DataProvider( 'modes' )]
	public function testCutoverReplaysEditsDeletesCreatesAndDerivedChanges( bool $completionOnly ): void {
		foreach ( [ 2, 3 ] as $id ) { $this->source[$id] = $this->page( $id, 1 ); }
		$worker = $this->worker();
		$run = $worker->beginRebuild( $completionOnly );
		$scanned = array_column( $this->source, 'document' );
		$worker->writeBatch( $run['id'], $scanned, $scanned );
		$this->source[1] = $this->page( 1, 2 );
		unset( $this->source[2] );
		$this->source[3]['document']['boost'] = 175;
		$this->source[4] = $this->page( 4, 1 );
		foreach ( [ 1, 2, 3, 4 ] as $id ) { $this->worker()->refresh( $id ); }
		$worker->ready( $run['id'] );
		$worker->finishRebuild( $run['id'] );
		$expected = [];
		foreach ( $this->source as $id => $built ) { $expected[$id] = $built['document']; }
		$this->assertSame( $expected, $this->server->indexes['wiki_completion'] );
		$this->assertSame( $expected, $this->server->indexes['wiki'] );
		$this->assertCount( 1, $this->swaps() );
		$this->assertCount( $completionOnly ? 1 : 2, $this->swaps()[0]['body'] );
		$this->assertSame( [], $this->store->changes );
		$this->assertNull( $this->store->state );
	}

	public function testPendingLinkRemovalQueuesItsTargetBeforeOldIndexIsDeleted(): void {
		$this->source[1] = $this->page( 1, 1, [ 2 ] );
		$this->server->indexes['wiki'][1] = $this->source[1]['document'];
		$this->server->indexes['wiki_completion'][1] = $this->source[1]['document'];
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$this->source[1] = $this->page( 1, 2 );
		$this->store->append( 1, 'Page 1', false );
		$worker->ready( $run['id'] );
		$worker->finishRebuild( $run['id'] );
		$this->assertContains( 2, $this->queued );
		$this->assertSame( [], $this->server->indexes['wiki'][1]['outgoing_link_ids'] );
	}

	public function testRequestArrivingDuringSwapIsAppliedToNewActiveIndexes(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$worker->ready( $run['id'] );
		$this->server->beforeRequest = function ( $method, $path ): void {
			if ( $path === '/swap-indexes' ) {
				$this->server->beforeRequest = null;
				$this->source[1] = $this->page( 1, 3 );
				$this->failure( fn () => $this->worker()->refresh( 1 ), 'busy' );
			}
		};
		$worker->finishRebuild( $run['id'] );
		$this->assertSame( 3, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( [], $this->store->changes );
	}

	public function testLostSwapResponseCanBeRecoveredWithoutSwappingTwice(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$this->source[1] = $this->page( 1, 2 );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$worker->ready( $run['id'] );
		$this->server->afterAccept = static function ( $task ): void {
			if ( $task['type'] === 'indexSwap' ) { throw new RuntimeException( 'lost swap response' ); }
		};
		$this->failure( fn () => $worker->finishRebuild( $run['id'] ), 'lost swap response' );
		$this->server->afterAccept = null;
		$recovery = $this->worker();
		$this->failure( fn () => $recovery->finishRebuild( $run['id'] ), 'unknown outcome' );
		$recovery->adoptTask( $this->store->state['pending']['id'], $this->swaps()[0]['uid'] );
		$recovery->finishRebuild( $run['id'] );
		$this->assertCount( 1, $this->swaps() );
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertNull( $this->store->state );
	}

	public function testCrashAfterSuccessfulSwapBeforeStateWriteDoesNotReverseIt(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( true );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$worker->ready( $run['id'] );
		$this->store->beforeWrite = function ( $state ): void {
			if ( ( $state['run']['phase'] ?? null ) === 'activated' ) {
				$this->store->beforeWrite = null;
				throw new RuntimeException( 'state write interrupted' );
			}
		};
		$this->failure( fn () => $worker->finishRebuild( $run['id'] ), 'state write interrupted' );
		$this->assertNotNull( $this->store->state['pending']['taskUid'] );
		$this->worker()->finishRebuild( $run['id'] );
		$this->assertCount( 1, $this->swaps() );
		$this->assertNull( $this->store->state );
	}

	public function testCleanupFailureResumesWithoutReversingAnActivatedGeneration(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$worker->ready( $run['id'] );
		$this->server->afterAccept = function ( $task ) use ( $run ): void {
			if ( $task['type'] === 'indexDeletion' && $task['indexUid'] === $run['completion'] ) {
				$this->server->blockedTasks[$task['uid']] = true;
			}
		};
		$this->failure( fn () => $worker->finishRebuild( $run['id'] ), 'timed out' );
		$this->assertSame( 'activated', $this->store->state['run']['phase'] );
		$this->assertArrayNotHasKey( $run['full'], $this->server->indexes );
		$this->server->blockedTasks = [];
		$this->server->afterAccept = null;
		$this->worker()->finishRebuild( $run['id'] );
		$this->assertCount( 1, $this->swaps() );
		$this->assertSame( [ 'wiki', 'wiki_completion' ], array_keys( $this->server->indexes ) );
		$this->assertNull( $this->store->state );
	}

	public function testConfirmedUnsubmittedSwapReturnsToReadyBeforeRetry(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( true );
		$worker->ready( $run['id'] );
		$this->server->beforeRequest = static function ( $method, $path ): void {
			if ( $path === '/swap-indexes' ) { throw new RuntimeException( 'sender stopped before submission' ); }
		};
		$this->failure( fn () => $worker->finishRebuild( $run['id'] ), 'sender stopped' );
		$this->assertSame( [], $this->swaps() );
		$operation = $this->store->state['pending']['id'];
		$this->failure( fn () => $worker->confirmNotSubmitted( 'different-operation' ), 'operation changed' );
		$worker->confirmNotSubmitted( $operation );
		$this->assertSame( 'ready', $this->store->state['run']['phase'] );
		$this->server->beforeRequest = null;
		$this->worker()->finishRebuild( $run['id'] );
		$this->assertCount( 1, $this->swaps() );
	}

	public function testFailedGenerationTaskCannotActivateAndOldRunCannotWriteAfterAbort(): void {
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$this->server->afterAccept = function ( $task ): void {
			$this->server->tasks[$task['uid']]['status'] = 'failed';
			$this->server->tasks[$task['uid']]['error'] = [ 'message' => 'failed batch', 'code' => 'internal' ];
		};
		$this->failure( fn () => $worker->writeBatch( $run['id'], [ $this->source[1]['document'] ], [] ), 'failed batch' );
		$this->server->afterAccept = null;
		$this->assertSame( [], $this->swaps() );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
		$worker->abortRebuild( $run['id'] );
		$newRun = $worker->beginRebuild( true );
		$this->failure( fn () => $worker->writeBatch( $run['id'], [], [] ), 'ownership or phase' );
		$this->assertSame( $newRun['id'], $this->store->state['run']['id'] );
	}

	public function testCompletionResumeDoesNotWriteFullTextAndSettingsRespectRebuild(): void {
		$this->source[1] = $this->page( 1, 2 );
		$worker = $this->worker();
		$worker->refresh( 1, null, true );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( 2, $this->server->indexes['wiki_completion'][1]['revision_id'] );
		$worker->beginRebuild( false );
		$this->failure( fn () => $this->worker()->beginRebuild( true ), 'already exists' );
		$this->failure( fn () => $this->worker()->configure(), 'rebuild is active' );
	}

	public function testCompletedFullJournalEntriesDoNotPromoteACompletionOnlyResume(): void {
		$worker = $this->worker();
		$worker->beginRebuild( true );
		$worker->refresh( 1 );
		$this->source[1] = $this->page( 1, 2 );
		$worker->refresh( 1, null, true );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( 2, $this->server->indexes['wiki_completion'][1]['revision_id'] );
	}

	public function testFreshInstallationCreatesActiveNamesAndBothGenerationEndpoints(): void {
		$this->server->indexes = [];
		$this->store->state = null;
		$worker = $this->worker();
		$run = $worker->beginRebuild( false );
		$worker->writeBatch( $run['id'], [], [] );
		$worker->ready( $run['id'] );
		$worker->finishRebuild( $run['id'] );
		$this->assertSame( [ 'wiki' => [], 'wiki_completion' => [] ], $this->server->indexes );
	}

	public function testRecoveryRejectsWrongOperationAndWrongTaskType(): void {
		$this->server->afterAccept = static function (): void { throw new RuntimeException( 'lost' ); };
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'lost' );
		$this->failure( fn () => $this->worker()->adoptTask( 'wrong-id', 1 ), 'operation changed' );
		$this->server->tasks[1]['type'] = 'settingsUpdate';
		$this->failure( fn () => $this->worker()->adoptTask( $this->store->state['pending']['id'], 1 ), 'does not match' );
		$this->assertNull( $this->store->state['pending']['taskUid'] );
	}

	private function startIdle(): void {
		$this->store->state = null;
		unset( $this->server->indexes['wiki_coordination'], $this->server->primaryKeys['wiki_coordination'] );
	}

	public function testIdleStatusCreatesNothingAndLaterRefreshReopensAfterCacheClear(): void {
		$this->startIdle();
		$this->assertFalse( $this->worker()->status()['coordinationLost'] );
		$this->assertFalse( $this->worker()->hasPendingPages() );
		$this->assertSame( [], $this->server->submissions );
		$this->worker()->refresh( 1 );
		$this->assertNull( $this->store->state );
		$this->assertArrayNotHasKey( 'wiki_coordination', $this->server->indexes );
		$firstEpoch = $this->server->submissions[0]['body']['primaryKey'];
		$this->store = new MemoryCoordinationStore();
		$this->store->state = null;
		$this->source[1] = $this->page( 1, 2 );
		$this->worker()->refresh( 1 );
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertNull( $this->store->state );
		$this->assertNotSame( $firstEpoch, $this->server->submissions[4]['body']['primaryKey'] );
	}

	public function testLostActiveCacheBlocksWritesButPreservesSearchDocuments(): void {
		$run = $this->worker()->beginRebuild( false );
		$this->store->state = null;
		$this->store->changes = [];
		$before = count( $this->server->submissions );
		$this->assertTrue( $this->worker()->status()['coordinationLost'] );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'recover-lost-state' );
		$this->failure( fn () => $this->worker()->writeBatch( $run['id'], [], [] ), 'recover-lost-state' );
		$this->assertCount( $before, $this->server->submissions );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
	}

	public function testUnknownGuardDeleteIsNotRepeatedAndCanBeAdopted(): void {
		$this->server->afterAccept = static function ( array $task ): void {
			if ( $task['type'] === 'indexDeletion' && $task['indexUid'] === 'wiki_coordination' ) {
				throw new RuntimeException( 'lost guard delete response' );
			}
		};
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'lost guard delete response' );
		$pending = $this->store->state['pending'];
		$this->assertSame( 'guard-delete', $pending['purpose'] );
		$this->assertTrue( $this->store->state['closing'] );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'unknown outcome' );
		$this->assertCount( 3, $this->server->submissions );
		$this->server->afterAccept = null;
		$this->worker()->adoptTask( $pending['id'], 3 );
		$this->assertNull( $this->store->state );
		$this->assertCount( 3, $this->server->submissions );
		$this->worker()->refresh( 1 );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
	}

	public function testConcurrentProducerCannotAppendToClosingScopeAndCanRetry(): void {
		$this->server->beforeRequest = function ( string $method, string $path ): void {
			if ( $method === 'DELETE' && $path === '/indexes/wiki_coordination' ) {
				$this->server->beforeRequest = null;
				$this->source[1] = $this->page( 1, 2 );
				$this->failure( fn () => $this->worker()->refresh( 1 ), 'busy' );
				$this->assertSame( [], $this->store->changes );
			}
		};
		$this->worker()->refresh( 1 );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->worker()->refresh( 1 );
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
	}

	public function testLosingLeaseAfterGuardCreationLeavesGuardButNoDocumentWrites(): void {
		$this->startIdle();
		$this->server->onApply = function ( array $task ): void {
			if ( $task['type'] === 'indexCreation' ) { $this->store->held = false; }
		};
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'lost lock' );
		$this->assertNull( $this->store->state );
		$this->assertArrayHasKey( 'wiki_coordination', $this->server->indexes );
		$this->assertCount( 1, $this->server->submissions );
		$this->failure( fn () => $this->worker()->refresh( 1 ), 'recover-lost-state' );
	}

	public function testLostStateRecoveryKeepsActiveIndexesAndRequiresFullRebuildToRetire(): void {
		$oldRun = $this->worker()->beginRebuild( false );
		$epoch = $this->store->state['epoch'];
		$this->store->state = null;
		$this->store->changes = [];
		$this->failure( fn () => $this->worker()->restartAfterStateLoss( str_repeat( 'b', 32 ) ), 'guard changed' );
		$this->assertArrayHasKey( $oldRun['full'], $this->server->indexes );
		$this->source[1] = $this->page( 1, 2 );
		$worker = $this->worker();
		$run = $worker->restartAfterStateLoss( $epoch );
		$this->assertTrue( $run['recovery'] );
		$this->assertArrayNotHasKey( $oldRun['full'], $this->server->indexes );
		$this->assertSame( 1, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( 'epoch_' . $epoch, $this->server->primaryKeys['wiki_coordination'] );
		$this->failure( fn () => $worker->abortRebuild( $run['id'] ), 'cannot be aborted' );
		$this->failure( fn () => $worker->finishRebuild( $run['id'] ), 'ownership or phase' );
		$docs = [ $this->source[1]['document'] ];
		$worker->writeBatch( $run['id'], $docs, $docs );
		$worker->ready( $run['id'] );
		$worker->finishRebuild( $run['id'] );
		$this->assertSame( 2, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertSame( $this->server->indexes['wiki'], $this->server->indexes['wiki_completion'] );
		$this->assertNull( $this->store->state );
		$this->assertArrayNotHasKey( 'wiki_coordination', $this->server->indexes );
	}
}

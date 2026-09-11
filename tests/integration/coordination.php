<?php

namespace FrauxSearch\Integration;

use FrauxSearch\RedisCoordinationStore;
use MediaWiki\Maintenance\Maintenance;
use RuntimeException;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 4 );
require_once "$IP/maintenance/Maintenance.php";

class CheckFrauxSearchCoordination extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Validate coordination with isolated Redis keys/indexes and real server faults.' );
		$this->addOption( 'execute', 'Create isolated test resources and deliberately expire only this harness writer lease' );
	}

	public function execute() {
		if ( !$this->hasOption( 'execute' ) ) {
			throw new RuntimeException( 'Pass --execute to run isolated Redis/Meilisearch mutation tests.' );
		}
		require_once __DIR__ . '/CoordinationRuntime.php';
		$runtime = new CoordinationRuntime();
		$this->output( json_encode( [ 'event' => 'coordination_integration_started',
			'index' => $runtime->index, 'scope_hash' => hash( 'sha256', $runtime->scope ),
			'redis_keys' => $runtime->store->keyNames(),
		], JSON_UNESCAPED_SLASHES ) . "\n" );
		try {
			$runtime->install();
			$probeRun = $runtime->coordinator()->beginRebuild( false );
			$this->checkLockLoss( $runtime );
			$this->output( "PASS separate-connection lease contention, forced expiry, and stale Lua owner rejection\n" );
			$this->checkAtomicJournal( $runtime );
			$this->output( "PASS atomic journal appends, independent producers, late append, and exact acknowledgements\n" );
			$runtime->coordinator()->abortRebuild( $probeRun['id'] );
			$this->checkLostWriteResponse( $runtime );
			$this->output( "PASS accepted HTTP write with discarded response, blocking, adoption, and replay\n" );
			$this->checkIdleCacheClear( $runtime );
			$this->output( "PASS idle scoped cache clear preserves completed indexes and permits a later refresh\n" );
			$this->checkActiveCacheLoss( $runtime );
			$this->output( "PASS active cache loss blocks writes and requires a complete recovered generation pair\n" );
			$this->checkLostGuardDeleteResponse( $runtime );
			$this->output( "PASS unknown guard DELETE adopts its exact real task without resubmission\n" );
			foreach ( [ false, true ] as $completionOnly ) {
				$this->checkCutover( $runtime, $completionOnly );
				$this->output( 'PASS ' . ( $completionOnly ? 'completion' : 'full' )
					. " generation replay, lost swap response, concurrent edit, and interrupted cleanup\n" );
			}
			$this->output( json_encode( [ 'event' => 'coordination_integration_passed',
				'accepted_tasks' => count( $runtime->http->accepted ), 'state' => $runtime->store->readState(),
			], JSON_UNESCAPED_SLASHES ) . "\n" );
		} finally {
			$runtime->cleanup();
			$this->output( json_encode( [ 'event' => 'coordination_integration_cleaned',
				'index' => $runtime->index, 'accepted_tasks_including_cleanup' => count( $runtime->http->accepted ),
				'task_uids' => array_column( $runtime->http->accepted, 'uid' ),
			], JSON_UNESCAPED_SLASHES ) . "\n" );
		}
		return true;
	}

	private function checkLockLoss( CoordinationRuntime $runtime ): void {
		$store = new RedisCoordinationStore( $runtime->connection->evaluate( ... ), $runtime->scope, 1000 );
		$successor = new RedisCoordinationStore( $runtime->observer->evaluate( ... ), $runtime->scope );
		$leaseKey = $store->keyNames()[0];
		CoordinationRuntime::check( $store->lock( 0 ), 'Initial writer cannot lock.' );
		try {
			$state = $store->readState();
			$store->writeState( $state + [ 'probe' => 'original' ] );
			CoordinationRuntime::check( !$successor->lock( 0 ), 'A separate connection acquired an already held lease.' );
			$ttl = $runtime->observer->evaluate( "return redis.call('PTTL', KEYS[1])", [ $leaseKey ], [] );
			CoordinationRuntime::check( is_int( $ttl ) && $ttl > 0 && $ttl <= 1000, 'Writer lease lacks the requested short TTL.' );
			$store->assertLocked();
			CoordinationRuntime::check( $runtime->observer->evaluate( "return redis.call('PEXPIRE', KEYS[1], 0)",
				[ $leaseKey ], [] ) === 1, 'Failed to expire this run\'s writer lease.' );
			CoordinationRuntime::check( $runtime->observer->evaluate( "return redis.call('PTTL', KEYS[1])",
				[ $leaseKey ], [] ) === -2, 'Expired writer lease remains present.' );
			CoordinationRuntime::check( $successor->lock( 0 ), 'Successor could not acquire the expired lease.' );
			$successor->writeState( $state + [ 'probe' => 'successor' ] );
			try {
				$store->writeState( $state + [ 'probe' => 'stale' ] );
				throw new RuntimeException( 'Stale writer unexpectedly retained its lease.' );
			} catch ( RuntimeException $error ) {
				CoordinationRuntime::check( str_contains( $error->getMessage(), 'lost its lease' ),
					'Expected lease-loss rejection, received: ' . $error->getMessage() );
			}
			CoordinationRuntime::check( $store->readState()['probe'] === 'successor',
				'Lua ownership condition allowed a stale writer to overwrite successor state.' );
			$store->unlock();
			$successor->assertLocked();
			CoordinationRuntime::check( !$runtime->store->lock( 0 ), 'Stale cleanup released the successor lease.' );
		} finally {
			$store->unlock();
			$successor->unlock();
		}
	}

	private function checkAtomicJournal( CoordinationRuntime $runtime ): void {
		$producer = new RedisCoordinationStore( $runtime->observer->evaluate( ... ), $runtime->scope );
		$firstId = $producer->append( 10, 'Observed completion title', true );
		CoordinationRuntime::check( is_int( $firstId ), 'Open probe run refused a journal append.' );
		CoordinationRuntime::check( $runtime->store->watermark() === $firstId,
			'Append sequence was not visible to the independent reader.' );
		$entries = $runtime->store->entries( 10, $firstId );
		CoordinationRuntime::check( array_keys( $entries ) === [ $firstId ]
			&& $entries[$firstId]['title'] === 'Observed completion title' && !$entries[$firstId]['done'],
			'Append did not publish its payload and journal indexes together.' );
		CoordinationRuntime::check( $runtime->store->lock( 0 ), 'Cannot acknowledge journal test.' );
		try {
			$lateId = $producer->append( 10, 'Late full-refresh title', false );
			$otherId = $runtime->store->append( 11, null, false );
			$through = $producer->watermark();
			CoordinationRuntime::check( $firstId < $lateId && $lateId < $otherId && $through === $otherId,
				'Independent producers did not allocate strictly increasing journal IDs.' );
			$runtime->store->markDone( 10, array_keys( $entries ) );
			$remaining = $producer->entries( 10, $through );
			CoordinationRuntime::check( array_keys( $remaining ) === [ $firstId, $lateId ] && $remaining[$firstId]['done']
				&& !$remaining[$lateId]['done']
				&& !$remaining[$lateId]['completionOnly'] && $remaining[$lateId]['title'] === 'Late full-refresh title',
				'Exact acknowledgement erased the independently appended full refresh.' );
			CoordinationRuntime::check( $producer->pages( 0, $through, true ) === [ 10, 11 ],
				'Acknowledgement erased another producer\'s pending page.' );
			$runtime->store->markDone( 10, [ $lateId ] );
			$runtime->store->markDone( 11, [ $otherId ] );
			CoordinationRuntime::check( $producer->pages( 0, $through, true ) === []
				&& $producer->pages( 0, $through, false ) === [ 10, 11 ],
				'Exact acknowledgements did not preserve the history needed by the probe run.' );
		} finally { $runtime->store->unlock(); }
	}

	private function checkLostWriteResponse( CoordinationRuntime $runtime ): void {
		$coordinator = $runtime->coordinator();
		$run = $coordinator->beginRebuild( false );
		$coordinator->ready( $run['id'] );
		$coordinator->finishRebuild( $run['id'] );
		$runtime->put( CoordinationRuntime::document( 1, 'before discarded acknowledgement' ) );
		$runtime->http->afterAccept = static function ( $method, $path ) use ( $runtime ): void {
			if ( $method === 'POST' && str_contains( $path, '/documents?' ) ) {
				$runtime->http->afterAccept = null;
				throw new InjectedFault( 'Discarded actual write acknowledgement.' );
			}
		};
		$this->expectInjected( fn () => $coordinator->refresh( 1 ) );
		$pending = $coordinator->status()['pending'];
		CoordinationRuntime::check( $pending !== null && $pending['taskUid'] === null, 'Lost response lacks durable unknown intent.' );
		$accepted = end( $runtime->http->accepted );
		$task = $runtime->client->getTask( $accepted['uid'] );
		CoordinationRuntime::check( $task['indexUid'] === $runtime->index, 'Observed real task belongs to a different index.' );
		$runtime->put( CoordinationRuntime::document( 1, 'new authoritative source state' ) );
		$count = count( $runtime->http->accepted );
		try {
			$coordinator->refresh( 1 );
			throw new RuntimeException( 'Unknown task did not block a successor.' );
		} catch ( RuntimeException $error ) {
			CoordinationRuntime::check( str_contains( $error->getMessage(), 'unknown outcome' ), 'Unexpected blocking failure.' );
		}
		CoordinationRuntime::check( count( $runtime->http->accepted ) === $count, 'Unknown mutation was resubmitted.' );
		$coordinator->adoptTask( $pending['id'], $accepted['uid'] );
		$coordinator->drain();
		$this->assertDocument( $runtime, 1, 'new authoritative source state' );
	}

	private function checkIdleCacheClear( CoordinationRuntime $runtime ): void {
		$this->assertIdle( $runtime );
		$cleared = $runtime->observer->evaluate( "return redis.call('DEL', unpack(KEYS))",
			$runtime->store->keyNames(), [] );
		CoordinationRuntime::check( $cleared === 0, 'Completed indexing retained Redis keys before the idle clear.' );
		$this->assertDocument( $runtime, 1, 'new authoritative source state' );
		$runtime->put( CoordinationRuntime::document( 1, 'refreshed after idle cache clear' ) );
		$runtime->coordinator()->refresh( 1 );
		$this->assertDocument( $runtime, 1, 'refreshed after idle cache clear' );
		$this->assertIdle( $runtime );
	}

	private function checkActiveCacheLoss( CoordinationRuntime $runtime ): void {
		$coordinator = $runtime->coordinator();
		$run = $coordinator->beginRebuild( false );
		$old = $runtime->build( 1 )['document'];
		$coordinator->writeBatch( $run['id'], [ $old ], [ $old ] );
		$runtime->put( CoordinationRuntime::document( 1, 'edited before active cache loss' ) );
		$runtime->put( CoordinationRuntime::document( 2, 'created before active cache loss' ) );
		$runtime->store->append( 1, null, false );
		$runtime->store->append( 2, null, false );
		$epoch = $runtime->store->readState()['epoch'];
		$removed = $runtime->observer->evaluate( "return redis.call('DEL', unpack(KEYS))",
			$runtime->store->keyNames(), [] );
		CoordinationRuntime::check( is_int( $removed ) && $removed > 0, 'Active cache-loss fault removed no state.' );
		$status = $coordinator->status();
		CoordinationRuntime::check( $status['coordinationLost'] && $status['guardEpoch'] === $epoch,
			'Lost run and journal did not leave a visible Meilisearch recovery guard.' );
		$count = count( $runtime->http->accepted );
		$this->expectFailure( fn () => $coordinator->refresh( 1 ), 'coordination was lost' );
		$this->expectFailure( fn () => $coordinator->writeBatch( $run['id'], [ $old ], [ $old ] ), 'coordination was lost' );
		CoordinationRuntime::check( count( $runtime->http->accepted ) === $count,
			'Lost active coordination allowed another HTTP mutation.' );
		$recovered = $coordinator->restartAfterStateLoss( $epoch );
		CoordinationRuntime::check( $recovered['recovery'] === true && $recovered['full'] !== null
			&& $runtime->store->readState()['epoch'] === $epoch,
			'Recovery did not atomically require a full rebuild under the original guard.' );
		$this->expectFailure( fn () => $coordinator->abortRebuild( $recovered['id'] ), 'cannot be aborted to idle' );
		foreach ( [ $run['full'], $run['completion'] ] as $index ) {
			CoordinationRuntime::check( !$runtime->client->withIndex( $index )->indexExists(),
				'An abandoned generation survived lost-state recovery.' );
		}
		$documents = [ $runtime->build( 1 )['document'], $runtime->build( 2 )['document'] ];
		$coordinator->writeBatch( $recovered['id'], $documents, $documents );
		$coordinator->ready( $recovered['id'] );
		$coordinator->finishRebuild( $recovered['id'] );
		$this->assertDocument( $runtime, 1, 'edited before active cache loss' );
		$this->assertDocument( $runtime, 2, 'created before active cache loss' );
		foreach ( [ $runtime->index, $runtime->index . '_completion' ] as $index ) {
			$page = $runtime->client->withIndex( $index )->listDocuments( 0, 100, [ 'id' ] );
			$ids = array_column( $page['results'], 'id' );
			sort( $ids, SORT_NUMERIC );
			CoordinationRuntime::check( $page['total'] === 2 && $ids === [ 1, 2 ],
				'Recovered generation did not contain exactly the complete source set.' );
		}
		$this->assertIdle( $runtime );
		$this->output( json_encode( [ 'event' => 'coordination_active_loss_recovered', 'epoch' => $epoch,
			'lost_run' => $run['id'], 'recovery_run' => $recovered['id'], 'removed_keys' => $removed,
		], JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	private function checkLostGuardDeleteResponse( CoordinationRuntime $runtime ): void {
		$coordinator = $runtime->coordinator();
		$guardPath = '/indexes/' . rawurlencode( $runtime->index . '_coordination' );
		$runtime->put( CoordinationRuntime::document( 1, 'guard deletion acknowledgement lost' ) );
		$runtime->http->afterAccept = static function ( $method, $path ) use ( $runtime, $guardPath ): void {
			if ( $method === 'DELETE' && $path === $guardPath ) {
				$runtime->http->afterAccept = null;
				throw new InjectedFault( 'Discarded actual guard deletion acknowledgement.' );
			}
		};
		$this->expectInjected( fn () => $coordinator->refresh( 1 ) );
		$state = $runtime->store->readState();
		$pending = $state['pending'];
		CoordinationRuntime::check( $state['closing'] && $pending !== null
			&& $pending['purpose'] === 'guard-delete' && $pending['taskUid'] === null && $pending['path'] === $guardPath,
			'Unknown guard deletion did not retain a sealed scope and exact intent.' );
		$accepted = end( $runtime->http->accepted );
		$runtime->client->waitForTask( $accepted['uid'] );
		$task = $runtime->client->getTask( $accepted['uid'] );
		CoordinationRuntime::check( $task['type'] === 'indexDeletion'
			&& $task['indexUid'] === $runtime->index . '_coordination' && $task['status'] === 'succeeded',
			'Observed accepted task is not this epoch\'s completed guard deletion.' );
		$count = count( $runtime->http->accepted );
		$this->expectFailure( fn () => $coordinator->refresh( 1 ), 'unknown outcome' );
		CoordinationRuntime::check( count( $runtime->http->accepted ) === $count,
			'An unknown guard deletion was retried or a successor started early.' );
		$coordinator->adoptTask( $pending['id'], $accepted['uid'] );
		CoordinationRuntime::check( count( $runtime->http->accepted ) === $count,
			'Exact adoption resubmitted guard deletion instead of retiring the sealed scope.' );
		$this->assertDocument( $runtime, 1, 'guard deletion acknowledgement lost' );
		$this->assertIdle( $runtime );
		$this->output( json_encode( [ 'event' => 'coordination_guard_delete_adopted',
			'operation' => $pending['id'], 'task' => $accepted['uid'], 'resubmitted' => false,
		], JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	private function checkCutover( CoordinationRuntime $runtime, bool $completionOnly ): void {
		$coordinator = $runtime->coordinator();
		$runtime->put( CoordinationRuntime::document( 1, 'generation old' ) );
		$runtime->put( CoordinationRuntime::document( 2, 'will be deleted' ) );
		$coordinator->refresh( 1 );
		$coordinator->refresh( 2 );
		$run = $coordinator->beginRebuild( $completionOnly );
		$documents = [ $runtime->build( 1 )['document'], $runtime->build( 2 )['document'] ];
		$coordinator->writeBatch( $run['id'], $documents, $documents );
		$runtime->put( CoordinationRuntime::document( 1, 'edited while generation built' ) );
		$runtime->remove( 2 );
		$runtime->put( CoordinationRuntime::document( 3, 'created while generation built' ) );
		foreach ( [ 1, 2, 3 ] as $id ) { $runtime->store->append( $id, null, false ); }
		$coordinator->ready( $run['id'] );
		$observerStore = new RedisCoordinationStore( $runtime->observer->evaluate( ... ), $runtime->scope );
		$runtime->http->afterAccept = static function ( $method, $path ) use ( $runtime, $observerStore ): void {
			if ( $path === '/swap-indexes' ) {
				$runtime->http->afterAccept = null;
				$runtime->put( CoordinationRuntime::document( 1, 'edited after accepted swap' ) );
				$observerStore->append( 1, 'Prior cutover title', false );
				throw new InjectedFault( 'Discarded actual swap acknowledgement after an independent Redis append.' );
			}
		};
		$beforeSwaps = count( array_filter( $runtime->http->accepted, static fn ( $task ) => $task['path'] === '/swap-indexes' ) );
		$this->expectInjected( fn () => $coordinator->finishRebuild( $run['id'] ) );
		$pending = $coordinator->status()['pending'];
		$swapTask = end( $runtime->http->accepted )['uid'];
		CoordinationRuntime::check( $pending['path'] === '/swap-indexes' && $pending['taskUid'] === null,
			'Accepted swap did not retain unknown outcome.' );
		$coordinator->adoptTask( $pending['id'], $swapTask );
		CoordinationRuntime::check( $coordinator->status()['run']['phase'] === 'activated', 'Recovered swap lacks durable activated phase.' );
		$runtime->http->afterAccept = static function ( $method, $path ) use ( $runtime, $run ): void {
			if ( $method === 'DELETE' && in_array( $path, [ '/indexes/' . $run['full'], '/indexes/' . $run['completion'] ], true ) ) {
				$runtime->http->afterAccept = null;
				throw new InjectedFault( 'Discarded actual generation cleanup acknowledgement.' );
			}
		};
		$this->expectInjected( fn () => $coordinator->finishRebuild( $run['id'] ) );
		$pending = $coordinator->status()['pending'];
		$deleteTask = end( $runtime->http->accepted )['uid'];
		$coordinator->adoptTask( $pending['id'], $deleteTask );
		$coordinator->finishRebuild( $run['id'] );
		$this->assertDocument( $runtime, 1, 'edited after accepted swap' );
		$this->assertDocument( $runtime, 3, 'created while generation built' );
		foreach ( [ $runtime->index, $runtime->index . '_completion' ] as $index ) {
			CoordinationRuntime::check( $runtime->client->withIndex( $index )->getDocument( 2 ) === null, 'Deleted source survived cutover.' );
		}
		foreach ( array_filter( [ $run['full'], $run['completion'] ] ) as $index ) {
			CoordinationRuntime::check( !$runtime->client->withIndex( $index )->indexExists(), 'Old generation survived cleanup recovery.' );
		}
		$swaps = array_filter( $runtime->http->accepted, static fn ( $task ) => $task['path'] === '/swap-indexes' );
		CoordinationRuntime::check( count( $swaps ) === $beforeSwaps + 1, 'Recovered cutover submitted another swap.' );
		CoordinationRuntime::check( $coordinator->status()['run'] === null && $coordinator->status()['pending'] === null
			&& $runtime->store->pages( 0, $runtime->store->watermark(), true ) === [], 'Cutover left a run, pending task, or pending journal.' );
	}

	private function assertDocument( CoordinationRuntime $runtime, int $id, string $text ): void {
		$expected = CoordinationRuntime::document( $id, $text )['document'];
		foreach ( [ $runtime->index, $runtime->index . '_completion' ] as $index ) {
			$actual = $runtime->client->withIndex( $index )->getDocument( $id );
			CoordinationRuntime::check( $actual !== null && $actual['document_hash'] === $expected['document_hash']
				&& $actual['revision_id'] === 1 && $actual['text'] === $text, 'Authoritative content/hash diverged after recovery.' );
		}
	}

	private function expectInjected( \Closure $callback ): void {
		try { $callback(); } catch ( InjectedFault ) { return; }
		throw new RuntimeException( 'Expected transport fault was never injected.' );
	}

	private function expectFailure( \Closure $callback, string $message ): void {
		try { $callback(); } catch ( RuntimeException $error ) {
			CoordinationRuntime::check( str_contains( $error->getMessage(), $message ),
				'Unexpected failure: ' . $error->getMessage() );
			return;
		}
		throw new RuntimeException( 'Expected rejection was not observed: ' . $message );
	}

	private function assertIdle( CoordinationRuntime $runtime ): void {
		CoordinationRuntime::check( $runtime->store->readState() === null
			&& !$runtime->client->withIndex( $runtime->index . '_coordination' )->indexExists()
			&& $runtime->observer->evaluate( "return redis.call('EXISTS', unpack(KEYS))",
				$runtime->store->keyNames(), [] ) === 0, 'Settled indexing retained its guard or Redis scope.' );
	}
}

$maintClass = CheckFrauxSearchCoordination::class;
require_once RUN_MAINTENANCE_IF_MAIN;

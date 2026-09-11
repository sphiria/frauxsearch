<?php

namespace FrauxSearch;

use Closure;
use RuntimeException;
use Throwable;

class IndexCoordinator {
	private MeilisearchClient $client;
	private MeilisearchClient $guardClient;

	public function __construct(
		private CoordinationStore $store,
		private MeilisearchClient $rawClient,
		private string $baseIndex,
		private Closure $build,
		private Closure $queueRefreshes,
		private int $lockTimeout = 5
	) {
		$this->rawClient = $rawClient->withTaskPollHandler( $this->store->assertLocked( ... ) );
		$this->client = $this->rawClient->withMutationHandler( $this->mutate( ... ) );
		$this->guardClient = $this->rawClient->withIndex( $this->baseIndex . '_coordination' );
	}

	public function status(): array {
		if ( !$this->store->lock( 0 ) ) {
			throw new MeilisearchException( 'FrauxSearch coordinator is busy; retry the status check.', true,
				errorCode: 'coordination_busy' );
		}
		try {
			$status = $this->readStatus();
		} catch ( Throwable $e ) {
			$this->unlockAfterFailure();
			throw $e;
		}
		$this->store->unlock();
		return $status;
	}

	private function readStatus(): array {
		$this->store->assertLocked();
		$guard = $this->guardClient->getIndexMetadata();
		$this->store->assertLocked();
		try {
			$state = $this->store->readState();
		} catch ( RuntimeException $e ) {
			$this->store->assertLocked();
			return [ 'version' => 2, 'epoch' => null, 'closing' => false,
				'run' => null, 'pending' => null, 'guardEpoch' => $this->guardEpoch( $guard ),
				'coordinationLost' => true, 'error' => $e->getMessage() ];
		}
		$this->store->assertLocked();
		$lost = $state === null ? $guard !== null :
			( $guard === null ? !$state['closing'] :
				$guard['primaryKey'] !== 'epoch_' . $state['epoch'] );
		return ( $state ?? [ 'version' => 2, 'epoch' => null, 'closing' => false,
			'run' => null, 'pending' => null ] ) + [
			'guardEpoch' => $this->guardEpoch( $guard ), 'coordinationLost' => $lost,
		];
	}

	public function hasPendingPages(): bool {
		return $this->store->pages( 0, $this->store->watermark(), true ) !== [];
	}

	private function locked( Closure $callback, bool $recoverClosing = false ) {
		return $this->withWriterLock( function () use ( $callback, $recoverClosing ) {
			$this->prepareScope( $recoverClosing );
			$result = $callback();
			$this->retireIfIdle();
			return $result;
		} );
	}

	private function withWriterLock( Closure $callback ) {
		if ( !$this->store->lock( $this->lockTimeout ) ) {
			throw new MeilisearchException( 'FrauxSearch writer is busy; retry the indexing job.', true );
		}
		try {
			$result = $callback();
		} catch ( Throwable $e ) {
			$this->unlockAfterFailure();
			throw $e;
		}
		$this->store->unlock();
		return $result;
	}

	private function unlockAfterFailure(): void {
		try {
			$this->store->unlock();
		} catch ( Throwable ) {
		}
	}

	private function requireState(): array {
		return $this->store->readState()
			?? throw new RuntimeException( 'FrauxSearch temporary coordination disappeared during an operation; '
				. 'inspect the guard and recover unfinished indexing before continuing.' );
	}

	private function guardEpoch( ?array $guard ): ?string {
		$primaryKey = $guard['primaryKey'] ?? null;
		return is_string( $primaryKey ) && preg_match( '/^epoch_([a-f0-9]{32})$/D', $primaryKey, $match )
			? $match[1] : null;
	}

	private function lostState( ?array $guard ): RuntimeException {
		$epoch = $this->guardEpoch( $guard );
		return new RuntimeException( 'FrauxSearch coordination was lost while indexing work was unfinished. '
			. ( $epoch === null ? 'Inspect the coordination guard before recovery.' :
				'Stop indexing writers and their outstanding requests, then run rebuildFrauxSearchIndex.php '
				. '--recover-lost-state ' . $epoch . ' --confirm-writers-stopped.' ) );
	}

	private function prepareScope( bool $recoverClosing = false ): void {
		$state = $this->store->readState();
		if ( $state !== null && $state['closing'] && !$recoverClosing ) {
			$this->retireIfIdle();
			$state = $this->store->readState();
		}
		$this->store->assertLocked();
		$guard = $this->guardClient->getIndexMetadata();
		$this->store->assertLocked();
		if ( $state !== null ) {
			if ( $state['closing'] && $guard === null && $recoverClosing ) { return; }
			if ( $this->guardEpoch( $guard ) !== $state['epoch'] ) { throw $this->lostState( $guard ); }
			return;
		}
		if ( $guard !== null ) { throw $this->lostState( $guard ); }
		$epoch = bin2hex( random_bytes( 16 ) );
		$task = $this->guardClient->createIndexStrict( 'epoch_' . $epoch );
		$this->guardClient->waitForTask( $task );
		$guard = $this->guardClient->getIndexMetadata();
		$this->store->assertLocked();
		if ( $this->guardEpoch( $guard ) !== $epoch ) { throw $this->lostState( $guard ); }
		$this->store->initialize( $epoch );
	}

	private function retireIfIdle(): void {
		$state = $this->store->readState();
		if ( $state === null ) { return; }
		if ( !$state['closing'] && !$this->store->seal() ) { return; }
		$state = $this->requireState();
		if ( $state['pending'] !== null ) {
			if ( $state['pending']['purpose'] !== 'guard-delete' ) {
				throw new RuntimeException( 'FrauxSearch closing scope contains an unexpected write.' );
			}
			$this->settlePending();
		}
		$this->store->assertLocked();
		$guard = $this->guardClient->getIndexMetadata();
		$this->store->assertLocked();
		if ( $guard !== null ) {
			if ( $this->guardEpoch( $guard ) !== $state['epoch'] ) { throw $this->lostState( $guard ); }
			$this->submit( 'DELETE', '/indexes/' . rawurlencode( $this->baseIndex . '_coordination' ), null,
				fn (): array => [ 'taskUid' => $this->guardClient->deleteIndex() ], 'guard-delete' );
			$this->settlePending();
		}
		$this->store->retire();
	}

	private function mutate( string $method, string $path, ?array $body, Closure $send ): array {
		return $this->submit( $method, $path, $body, $send, 'write' );
	}

	private function submit( string $method, string $path, ?array $body, Closure $send, string $purpose ): array {
		$this->store->assertLocked();
		$this->settlePending();
		$state = $this->requireState();
		if ( $state['closing'] !== ( $purpose === 'guard-delete' ) ) {
			throw new RuntimeException( 'FrauxSearch scope is not open for this operation.' );
		}
		$state['pending'] = [
			'id' => bin2hex( random_bytes( 16 ) ), 'purpose' => $purpose, 'method' => $method, 'path' => $path,
			'taskUid' => null, 'startedAt' => gmdate( 'c' ),
			'swaps' => $path === '/swap-indexes' ? $body : null,
			'createdIndex' => $path === '/indexes' ? ( $body['uid'] ?? null ) : null,
		];
		$this->store->writeState( $state );
		$response = $send();
		if ( !isset( $response['taskUid'] ) || !is_int( $response['taskUid'] ) || $response['taskUid'] < 0 ) {
			throw new RuntimeException( 'FrauxSearch mutation has no task UID; recover the recorded operation.' );
		}
		$state['pending']['taskUid'] = $response['taskUid'];
		$this->store->writeState( $state );
		return $response;
	}

	private function settlePending(): void {
		$state = $this->requireState();
		$pending = $state['pending'];
		if ( $pending === null ) { return; }
		if ( $pending['taskUid'] === null ) {
			throw new RuntimeException( 'FrauxSearch operation ' . $pending['id']
				. ' has an unknown outcome. Use recoverFrauxSearchIndex.php before further writes.' );
		}
		try {
			$this->rawClient->waitForTask( $pending['taskUid'] );
		} catch ( MeilisearchException $e ) {
			if ( $e->getCompletedTaskId() === $pending['taskUid'] ) {
				$state['pending'] = null;
				if ( $pending['path'] === '/swap-indexes' && $state['run'] !== null ) {
					$state['run']['phase'] = 'ready';
				}
				$this->store->writeState( $state );
			}
			throw $e;
		}
		$state['pending'] = null;
		if ( $pending['path'] === '/swap-indexes' && $state['run'] !== null ) {
			$state['run']['phase'] = 'activated';
		}
		$this->store->writeState( $state );
	}

	public function refresh( int $pageId, ?string $title = null, bool $completionOnly = false ): void {
		if ( $pageId <= 0 ) { return; }
		$recorded = $this->store->append( $pageId, $title, $completionOnly ) !== null;
		$this->locked( function () use ( $pageId, $title, $completionOnly, $recorded ): void {
			if ( !$recorded && $this->store->append( $pageId, $title, $completionOnly ) === null ) {
				throw new RuntimeException( 'FrauxSearch could not record the page in its open scope.' );
			}
			$this->settlePending();
			$through = $this->store->watermark();
			$this->refreshRecordedPage( $pageId, $through );
			if ( $this->requireState()['run'] === null ) { $this->store->prune( $through ); }
		} );
	}

	public function refreshBatch( array|Closure $requests, Closure $buildBatch ): int {
		return $this->withWriterLock( function () use ( $requests, $buildBatch ): int {
			if ( $requests instanceof Closure ) { $requests = $requests( $this->store->assertLocked( ... ) ); }
			$this->store->assertLocked();
			if ( $requests === [] ) { return 0; }
			foreach ( $requests as $request ) {
				if ( !is_int( $request['pageId'] ?? null ) || $request['pageId'] <= 0
					|| ( isset( $request['redirectTitle'] ) && !is_string( $request['redirectTitle'] ) )
				) { throw new \InvalidArgumentException( 'Invalid page refresh request.' ); }
			}
			$this->prepareScope( false );
			$this->settlePending();
			$ids = [];
			foreach ( $requests as $request ) {
				$id = $request['pageId'];
				if ( $this->store->append( $id, $request['redirectTitle'] ?? null, false ) === null ) {
					throw new RuntimeException( 'Unable to journal refresh batch.' );
				}
				$ids[$id] = true;
			}
			$ids = array_keys( $ids );
			$through = $this->store->watermark();
			$entries = [];
			$titles = [];
			$completionOnly = [];
			foreach ( $ids as $id ) {
				$entries[$id] = $this->store->entries( $id, $through );
				$titles[$id] = array_values( array_unique( array_column( $entries[$id], 'title' ) ) );
				$completionOnly[$id] = false;
			}
			$built = $buildBatch( $ids, $this->store->assertLocked( ... ) );
			$this->store->assertLocked();
			if ( !is_array( $built ) || array_keys( $built ) !== $ids ) {
				throw new RuntimeException( 'Incomplete or mismatched refresh batch.' );
			}
			$refresher = new PageRefresher( $this->client, $this->client->withIndex( $this->baseIndex . '_completion' ),
				$this->build, $this->queueRefreshes, $this->store->assertLocked( ... ) );
			$refresher->refreshBatch( $built, $titles, $completionOnly );
			$this->settlePending();
			foreach ( $entries as $id => $pageEntries ) {
				$this->store->markDone( $id, array_keys( $pageEntries ) );
			}
			if ( $this->requireState()['run'] === null ) { $this->store->prune( $through ); }
			$this->retireIfIdle();
			return count( $ids );
		} );
	}

	private function refreshRecordedPage( int $pageId, int $through ): void {
		$entries = $this->store->entries( $pageId, $through );
		$pending = array_filter( $entries, static fn ( array $entry ) => !$entry['done'] );
		if ( $pending === [] ) { return; }
		$refresher = new PageRefresher( $this->client, $this->client->withIndex( $this->baseIndex . '_completion' ),
			$this->build, $this->queueRefreshes );
		$refresher->refresh( $pageId, null, array_values( array_unique( array_column( $entries, 'title' ) ) ),
			!in_array( false, array_column( $pending, 'completionOnly' ), true ) );
		$this->settlePending();
		$this->store->markDone( $pageId, array_keys( $pending ) );
	}

	private function eachPage( int $through, bool $pendingOnly, Closure $callback ): void {
		$after = 0;
		while ( ( $ids = $this->store->pages( $after, $through, $pendingOnly ) ) !== [] ) {
			foreach ( $ids as $id ) {
				$callback( $id );
				$after = $id;
			}
		}
	}

	private function drainThrough( int $through ): void {
		$this->eachPage( $through, true, fn ( int $id ) => $this->refreshRecordedPage( $id, $through ) );
	}

	public function drain(): void {
		$this->locked( function (): void {
			$this->settlePending();
			$through = $this->store->watermark();
			$this->drainThrough( $through );
			if ( $this->requireState()['run'] === null ) { $this->store->prune( $through ); }
		} );
	}

	public function beginRebuild( bool $completionOnly ): array {
		return $this->locked( function () use ( $completionOnly ): array {
			$this->settlePending();
			$state = $this->requireState();
			if ( $state['run'] !== null ) {
				throw new RuntimeException( 'A FrauxSearch rebuild already exists; finish or abort run ' . $state['run']['id'] );
			}
			$run = $this->newRun( $completionOnly );
			$state['run'] = $run;
			$this->store->writeState( $state );
			$this->prepareRunIndexes( $run );
			return $run;
		} );
	}

	private function newRun( bool $completionOnly ): array {
		$id = bin2hex( random_bytes( 16 ) );
		$suffix = gmdate( 'YmdHis' ) . '_' . $id;
		return [ 'id' => $id, 'phase' => 'building', 'watermark' => 0, 'recovery' => false,
			'full' => $completionOnly ? null : $this->baseIndex . '_' . $suffix,
			'completion' => $this->baseIndex . '_completion_' . $suffix ];
	}

	private function prepareRunIndexes( array $run ): void {
		foreach ( [ $this->baseIndex, $this->baseIndex . '_completion' ] as $active ) {
			$client = $this->client->withIndex( $active );
			if ( $client->createIndex() !== null ) { $client->configureIndex(); }
		}
		foreach ( array_filter( [ $run['full'], $run['completion'] ] ) as $index ) {
			$client = $this->client->withIndex( $index );
			$client->createIndex();
			$client->configureIndex();
		}
		$this->settlePending();
	}

	public function restartAfterStateLoss( string $epoch ): array {
		if ( preg_match( '/^[a-f0-9]{32}$/D', $epoch ) !== 1 ) {
			throw new \InvalidArgumentException( 'Invalid coordination recovery epoch.' );
		}
		if ( !$this->store->lock( $this->lockTimeout ) ) {
			throw new MeilisearchException( 'FrauxSearch writer is still active; recovery requires stopped writers.', true );
		}
		try {
			$guard = $this->guardClient->getIndexMetadata();
			if ( $this->guardEpoch( $guard ) !== $epoch ) {
				throw new RuntimeException( 'FrauxSearch recovery guard changed or is absent; refusing to reset it.' );
			}
			$this->settleServerTasks();
			$this->removeAbandonedGenerations();
			$this->store->assertLocked();
			if ( $this->guardEpoch( $this->guardClient->getIndexMetadata() ) !== $epoch ) {
				throw new RuntimeException( 'FrauxSearch recovery guard changed.' );
			}
			$run = $this->newRun( false );
			$run['recovery'] = true;
			$this->store->discard();
			$this->store->initialize( $epoch, $run );
			$this->prepareRunIndexes( $run );
		} catch ( Throwable $e ) {
			$this->unlockAfterFailure();
			throw $e;
		}
		$this->store->unlock();
		return $run;
	}

	private function isGeneration( string $index ): bool {
		return preg_match( '/^' . preg_quote( $this->baseIndex, '/' )
			. '(?:_completion)?_[0-9]{14}_[a-f0-9]{32}$/D', $index ) === 1;
	}

	private function ownsIndex( string $index ): bool {
		return in_array( $index, [ $this->baseIndex, $this->baseIndex . '_completion',
			$this->baseIndex . '_coordination' ], true ) || $this->isGeneration( $index );
	}

	private function settleServerTasks(): void {
		$from = null;
		$tasks = [];
		do {
			$this->store->assertLocked();
			$page = $this->rawClient->listTasks( [ 'statuses' => [ 'enqueued', 'processing' ] ], $from, 100 );
			foreach ( $page['results'] as $task ) {
				$owned = is_string( $task['indexUid'] ) && $this->ownsIndex( $task['indexUid'] );
				if ( $task['type'] === 'indexSwap' ) {
					$swaps = $task['details']['swaps'] ?? null;
					if ( !is_array( $swaps ) || !array_is_list( $swaps ) ) {
						throw new RuntimeException( 'Cannot identify the indexes of an unfinished Meilisearch swap.' );
					}
					foreach ( $swaps as $swap ) {
						$indexes = is_array( $swap ) ? ( $swap['indexes'] ?? null ) : null;
						if ( !is_array( $indexes ) || !array_is_list( $indexes ) || count( $indexes ) !== 2
							|| !is_string( $indexes[0] ) || !is_string( $indexes[1] )
						) { throw new RuntimeException( 'Invalid unfinished Meilisearch swap.' ); }
						$owned = $owned || $this->ownsIndex( $indexes[0] ) || $this->ownsIndex( $indexes[1] );
					}
				}
				if ( $owned ) { $tasks[] = $task['uid']; }
			}
			$from = $page['next'];
		} while ( $from !== null );
		foreach ( $tasks as $task ) {
			try {
				$this->rawClient->waitForTask( $task );
			} catch ( MeilisearchException $e ) {
				if ( $e->getCompletedTaskId() !== $task ) { throw $e; }
			}
		}
	}

	private function removeAbandonedGenerations(): void {
		$offset = 0;
		$generations = [];
		do {
			$this->store->assertLocked();
			$page = $this->rawClient->listIndexes( $offset, 100 );
			foreach ( $page['results'] as $index ) {
				if ( $this->isGeneration( $index['uid'] ) ) { $generations[] = $index['uid']; }
			}
			$offset += count( $page['results'] );
		} while ( $offset < $page['total'] );
		foreach ( $generations as $generation ) {
			$client = $this->rawClient->withIndex( $generation );
			$this->store->assertLocked();
			$this->rawClient->waitForTask( $client->deleteIndex() );
		}
	}

	private function requireRun( string $id, array $phases ): array {
		$run = $this->requireState()['run'];
		if ( $run === null || $run['id'] !== $id || !in_array( $run['phase'], $phases, true ) ) {
			throw new RuntimeException( 'FrauxSearch rebuild ownership or phase changed; refusing operation.' );
		}
		return $run;
	}

	public function writeBatch( string $runId, array $documents, array $completionDocuments ): void {
		$this->locked( function () use ( $runId, $documents, $completionDocuments ): void {
			$this->settlePending();
			$run = $this->requireRun( $runId, [ 'building' ] );
			if ( $run['full'] !== null ) { $this->client->withIndex( $run['full'] )->replaceDocuments( $documents ); }
			$this->client->withIndex( $run['completion'] )->replaceDocuments( $completionDocuments );
			$this->settlePending();
		} );
	}

	public function ready( string $runId ): void {
		$this->locked( function () use ( $runId ): void {
			$this->settlePending();
			$this->requireRun( $runId, [ 'building' ] );
			$state = $this->requireState();
			$state['run']['phase'] = 'ready';
			$this->store->writeState( $state );
		} );
	}

	public function finishRebuild( string $runId ): void {
		$this->locked( function () use ( $runId ): void {
			$this->settlePending();
			$run = $this->requireRun( $runId, [ 'ready', 'catchup', 'swapping', 'activated' ] );
			if ( $run['phase'] !== 'activated' ) {
				$through = $this->store->watermark();
				$state = $this->requireState();
				$state['run']['phase'] = 'catchup';
				$state['run']['watermark'] = $through;
				$this->store->writeState( $state );
				$this->drainThrough( $through );
				$this->eachPage( $through, false, function ( int $pageId ) use ( $run ): void {
					$built = ( $this->build )( $pageId );
					foreach ( [ [ $run['full'], false ], [ $run['completion'], true ] ] as [ $index, $completion ] ) {
						if ( $index === null ) { continue; }
						$client = $this->client->withIndex( $index );
						if ( $built === null || ( $completion && $built['is_redirect'] ) ) {
							$client->deleteDocument( $pageId );
						} else {
							$client->replaceDocuments( [ $built['document'] ] );
						}
					}
				} );
				$pairs = [];
				if ( $run['full'] !== null ) { $pairs[] = [ $this->baseIndex, $run['full'] ]; }
				$pairs[] = [ $this->baseIndex . '_completion', $run['completion'] ];
				foreach ( $pairs as [ $active ] ) { $this->client->withIndex( $active )->createIndex(); }
				$this->settlePending();
				$state = $this->requireState();
				$state['run']['phase'] = 'swapping';
				$this->store->writeState( $state );
				$this->client->swapIndexes( $pairs );
				$this->settlePending();
			}
			$run = $this->requireRun( $runId, [ 'activated' ] );
			$this->removeGenerations( $run );
			$state = $this->requireState();
			$state['run'] = null;
			$this->store->writeState( $state );
			$this->store->prune( $run['watermark'] );
			$through = $this->store->watermark();
			$this->drainThrough( $through );
			$this->store->prune( $through );
		} );
	}

	private function removeGenerations( array $run ): void {
		foreach ( array_filter( [ $run['full'], $run['completion'] ] ) as $index ) {
			if ( !$this->client->withIndex( $index )->indexExists() ) { continue; }
			try {
				$this->client->withIndex( $index )->deleteIndex();
				$this->settlePending();
			} catch ( MeilisearchException $e ) {
				if ( $e->getCompletedTaskId() === null || $e->getErrorCode() !== 'index_not_found' ) { throw $e; }
			}
		}
	}

	public function abortRebuild( string $runId ): void {
		$this->locked( function () use ( $runId ): void {
			$this->settlePending();
			$run = $this->requireRun( $runId, [ 'building', 'ready', 'catchup', 'aborting' ] );
			if ( $run['recovery'] ) {
				throw new RuntimeException( 'This rebuild repairs lost coordination and cannot be aborted to idle. '
					. 'Restart the full lost-state recovery if the build cannot continue.' );
			}
			$state = $this->requireState();
			$state['run']['phase'] = 'aborting';
			$this->store->writeState( $state );
			$this->removeGenerations( $run );
			$state = $this->requireState();
			$state['run'] = null;
			$this->store->writeState( $state );
			$through = $this->store->watermark();
			$this->drainThrough( $through );
			$this->store->prune( $through );
		} );
	}

	public function configure(): void {
		$this->locked( function (): void {
			$this->settlePending();
			if ( $this->requireState()['run'] !== null ) { throw new RuntimeException( 'A FrauxSearch rebuild is active.' ); }
			$this->client->configureIndex();
			$this->client->withIndex( $this->baseIndex . '_completion' )->configureIndex();
			$this->settlePending();
		} );
	}

	public function adoptTask( string $operationId, int $taskUid ): void {
		$this->locked( function () use ( $operationId, $taskUid ): void {
			$state = $this->requireState();
			$pending = $state['pending'];
			if ( $pending === null || $pending['id'] !== $operationId || $pending['taskUid'] !== null ) {
				throw new RuntimeException( 'The unresolved FrauxSearch operation changed.' );
			}
			$task = $this->rawClient->getTask( $taskUid );
			if ( $pending['path'] === '/swap-indexes' ) {
				if ( ( $task['type'] ?? null ) !== 'indexSwap'
					|| ( $task['details']['swaps'] ?? null ) !== $pending['swaps']
				) { throw new RuntimeException( 'Task does not match the recorded index swap.' ); }
			} else {
				preg_match( '#^/indexes/([^/?]+)#', $pending['path'], $match );
				$index = isset( $match[1] ) ? rawurldecode( $match[1] ) : $pending['createdIndex'];
				$type = match ( true ) {
					$pending['path'] === '/indexes' => 'indexCreation',
					str_ends_with( $pending['path'], '/settings' ) => 'settingsUpdate',
					str_contains( $pending['path'], '/documents' ) => $pending['method'] === 'DELETE'
						|| str_ends_with( $pending['path'], '/delete-batch' ) ? 'documentDeletion' : 'documentAdditionOrUpdate',
					default => 'indexDeletion',
				};
				if ( $index === null || ( $task['indexUid'] ?? null ) !== $index || ( $task['type'] ?? null ) !== $type ) {
					throw new RuntimeException( 'Task does not match the recorded index.' );
				}
			}
			$state['pending']['taskUid'] = $taskUid;
			$this->store->writeState( $state );
			$this->settlePending();
		}, true );
	}

	public function confirmNotSubmitted( string $operationId ): void {
		$this->locked( function () use ( $operationId ): void {
			$state = $this->requireState();
			$pending = $state['pending'];
			if ( $pending === null || $pending['id'] !== $operationId || $pending['taskUid'] !== null ) {
				throw new RuntimeException( 'The unresolved FrauxSearch operation changed.' );
			}
			if ( $pending['path'] === '/swap-indexes' && $state['run'] !== null ) { $state['run']['phase'] = 'ready'; }
			$state['pending'] = null;
			$this->store->writeState( $state );
		}, true );
	}
}

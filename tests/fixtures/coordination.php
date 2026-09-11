<?php

namespace FrauxSearch\Tests;

use FrauxSearch\CoordinationStore;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use RuntimeException;

class MemoryCoordinationStore implements CoordinationStore {
	public ?array $state = [ 'version' => 2, 'epoch' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'closing' => false, 'run' => null, 'pending' => null ];
	public array $changes = [];
	public bool $held = false;
	public $beforeWrite = null;
	private int $sequence = 0;
	public function lock( int $timeout = 0 ): bool {
		if ( $this->held ) { return false; }
		return $this->held = true;
	}
	public function unlock(): void { $this->held = false; }
	public function assertLocked(): void {
		if ( !$this->held ) { throw new RuntimeException( 'lost lock' ); }
	}
	public function initialize( string $epoch, ?array $run = null ): void {
		$this->assertLocked();
		if ( $this->state !== null || $this->changes !== [] ) { throw new RuntimeException( 'scope exists' ); }
		$this->state = [ 'version' => 2, 'epoch' => $epoch, 'closing' => false, 'run' => $run, 'pending' => null ];
		$this->sequence = 0;
	}
	public function readState(): ?array { return $this->state; }
	public function writeState( array $state ): void {
		$this->assertLocked();
		if ( $this->beforeWrite !== null ) { ( $this->beforeWrite )( $state ); }
		if ( $this->state === null || $this->state['epoch'] !== $state['epoch']
			|| $this->state['closing'] !== $state['closing']
		) { throw new RuntimeException( 'scope changed' ); }
		$this->state = $state;
	}
	public function append( int $pageId, ?string $title, bool $completionOnly ): ?int {
		if ( $this->state === null || $this->state['closing'] ) { return null; }
		$id = ++$this->sequence;
		$this->changes[$id] = [ 'page' => $pageId, 'title' => $title, 'completionOnly' => $completionOnly, 'done' => false ];
		return $id;
	}
	public function seal(): bool {
		$this->assertLocked();
		if ( $this->state['run'] !== null || $this->state['pending'] !== null || $this->changes !== [] ) { return false; }
		$this->state['closing'] = true;
		return true;
	}
	public function retire(): void {
		$this->assertLocked();
		if ( !$this->state['closing'] || !$this->seal() ) { throw new RuntimeException( 'not idle' ); }
		$this->discard();
	}
	public function discard(): void {
		$this->assertLocked();
		$this->state = null;
		$this->changes = [];
		$this->sequence = 0;
	}
	public function watermark(): int { return $this->changes === [] ? 0 : max( array_keys( $this->changes ) ); }
	public function pages( int $afterPageId, int $through, bool $pendingOnly ): array {
		$ids = [];
		foreach ( $this->changes as $seq => $entry ) {
			if ( $seq <= $through && $entry['page'] > $afterPageId && ( !$pendingOnly || !$entry['done'] ) ) {
				$ids[] = $entry['page'];
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return array_slice( $ids, 0, 2 );
	}
	public function entries( int $pageId, int $through ): array {
		$values = [];
		foreach ( $this->changes as $seq => $entry ) {
			if ( $seq <= $through && $entry['page'] === $pageId ) { $values[$seq] = $entry; }
		}
		return $values;
	}
	public function markDone( int $pageId, array $entryIds ): void {
		$this->assertLocked();
		foreach ( $this->changes as $seq => &$entry ) {
			if ( in_array( $seq, $entryIds, true ) && $entry['page'] === $pageId ) { $entry['done'] = true; }
		}
	}
	public function prune( int $through ): void {
		$this->assertLocked();
		foreach ( $this->changes as $seq => $entry ) {
			if ( $seq <= $through && $entry['done'] ) { unset( $this->changes[$seq] ); }
		}
	}
}

class CoordinationServer {
	public array $indexes = [ 'wiki' => [], 'wiki_completion' => [], 'wiki_coordination' => [] ];
	public array $primaryKeys = [ 'wiki_coordination' => 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ];
	public array $tasks = [];
	public array $submissions = [];
	public array $blockedTasks = [];
	public $beforeRequest = null;
	public $afterAccept = null;
	public $onApply = null;
}

class CoordinationClient extends MeilisearchClient {
	public function __construct( public CoordinationServer $server ) { parent::__construct( 'http://stub', '', 'wiki', 1 ); }
	public function waitForTask( ?int $taskUid, int $timeout = 300 ): void {
		if ( isset( $this->server->blockedTasks[$taskUid] ) ) {
			throw new MeilisearchException( "Meilisearch task $taskUid timed out", true );
		}
		parent::waitForTask( $taskUid, $timeout );
	}
	protected function performRequest( string $method, string $path, ?array $body = null ): array {
		if ( $this->server->beforeRequest !== null ) { ( $this->server->beforeRequest )( $method, $path, $body ); }
		$url = parse_url( $path );
		$parts = explode( '/', trim( $url['path'], '/' ) );
		parse_str( $url['query'] ?? '', $query );
		$index = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : null;
		if ( $method === 'GET' && $parts[0] === 'tasks' ) {
			if ( count( $parts ) === 1 ) {
				$tasks = array_values( array_filter( array_reverse( $this->server->tasks, true ),
					static fn ( array $task ): bool => in_array( $task['status'], explode( ',', $query['statuses'] ), true )
						&& ( !isset( $query['from'] ) || $task['uid'] <= (int)$query['from'] ) ) );
				$limit = (int)$query['limit'];
				return [ 'results' => array_slice( $tasks, 0, $limit ), 'total' => count( $tasks ),
					'limit' => $limit, 'from' => $tasks[0]['uid'] ?? null, 'next' => $tasks[$limit]['uid'] ?? null ];
			}
			$id = (int)$parts[1];
			if ( !isset( $this->server->tasks[$id] ) ) {
				throw new MeilisearchException( 'task_not_found', errorCode: 'task_not_found', httpStatus: 404 );
			}
			$task = &$this->server->tasks[$id];
			if ( $task['status'] === 'enqueued' ) {
				$this->apply( $task );
				if ( $this->server->onApply !== null ) { ( $this->server->onApply )( $task ); }
			}
			return $task;
		}
		if ( $method === 'GET' ) {
			if ( $parts === [ 'indexes' ] ) {
				$indexes = array_map( fn ( string $uid ): array => [ 'uid' => $uid,
					'primaryKey' => $this->server->primaryKeys[$uid] ?? 'id' ], array_keys( $this->server->indexes ) );
				return [ 'results' => array_slice( $indexes, (int)$query['offset'], (int)$query['limit'] ),
					'offset' => (int)$query['offset'], 'limit' => (int)$query['limit'], 'total' => count( $indexes ) ];
			}
			if ( !isset( $this->server->indexes[$index] ) ) {
				throw new MeilisearchException( 'index_not_found', errorCode: 'index_not_found', httpStatus: 404 );
			}
			if ( count( $parts ) === 2 ) {
				return [ 'uid' => $index, 'primaryKey' => $this->server->primaryKeys[$index] ?? 'id' ];
			}
			if ( count( $parts ) === 4 ) {
				return $this->server->indexes[$index][(int)$parts[3]]
					?? throw new MeilisearchException( 'document_not_found', errorCode: 'document_not_found', httpStatus: 404 );
			}
			$title = json_decode( substr( $query['filter'] ?? '', strlen( 'redirects = ' ) ), true );
			$documents = array_values( array_filter( $this->server->indexes[$index],
				static fn ( $doc ) => in_array( $title, $doc['redirects'] ?? [], true ) ) );
			return [ 'results' => $documents, 'total' => count( $documents ) ];
		}
		if ( $method === 'POST' && ( $parts[3] ?? null ) === 'fetch' ) {
			$docs = array_values( array_intersect_key( $this->server->indexes[$index], array_flip( $body['ids'] ) ) );
			return [ 'results' => $docs, 'offset' => 0, 'limit' => $body['limit'], 'total' => count( $docs ) ];
		}
		$id = count( $this->server->submissions ) + 1;
		$type = match ( true ) {
			$path === '/swap-indexes' => 'indexSwap', $path === '/indexes' => 'indexCreation',
			str_ends_with( $path, '/settings' ) => 'settingsUpdate',
			str_contains( $path, '/documents' ) => $method === 'DELETE' || str_ends_with( $path, '/delete-batch' )
				? 'documentDeletion' : 'documentAdditionOrUpdate',
			default => 'indexDeletion',
		};
		$this->server->tasks[$id] = [ 'uid' => $id, 'status' => 'enqueued', 'method' => $method, 'path' => $path,
			'body' => $body, 'type' => $type, 'indexUid' => $path === '/indexes' ? $body['uid'] : $index,
			'details' => $type === 'indexSwap' ? [ 'swaps' => $body ] : [] ];
		$this->server->submissions[] = $this->server->tasks[$id];
		if ( $this->server->afterAccept !== null ) { ( $this->server->afterAccept )( $this->server->tasks[$id] ); }
		return [ 'taskUid' => $id ];
	}
	private function apply( array &$task ): void {
		$index = $task['indexUid'];
		switch ( $task['type'] ) {
			case 'indexCreation':
				if ( isset( $this->server->indexes[$index] ) ) {
					$task['status'] = 'failed';
					$task['error'] = [ 'message' => 'index_already_exists', 'code' => 'index_already_exists' ];
					return;
				}
				$this->server->indexes[$index] = [];
				$this->server->primaryKeys[$index] = $task['body']['primaryKey'];
				break;
			case 'indexDeletion':
				unset( $this->server->indexes[$index], $this->server->primaryKeys[$index] );
				break;
			case 'documentAdditionOrUpdate':
				foreach ( $task['body'] as $doc ) { $this->server->indexes[$index][$doc['id']] = $doc; }
				break;
			case 'documentDeletion':
				$ids = str_ends_with( $task['path'], '/delete-batch' ) ? $task['body'] : [ (int)basename( $task['path'] ) ];
				foreach ( $ids as $id ) { unset( $this->server->indexes[$index][$id] ); }
				break;
			case 'indexSwap':
				foreach ( $task['body'] as $swap ) {
					[ $a, $b ] = $swap['indexes'];
					[ $this->server->indexes[$a], $this->server->indexes[$b] ] =
						[ $this->server->indexes[$b], $this->server->indexes[$a] ];
				}
		}
		$task['status'] = 'succeeded';
	}
}

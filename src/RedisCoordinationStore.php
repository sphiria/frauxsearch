<?php

namespace FrauxSearch;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RedisCoordinationStore implements CoordinationStore {
	private const MAX_ID = 9007199254740991;
	private const BATCH_SIZE = 500;
	private array $keys;
	private string $script;
	private ?string $token = null;
	private bool $lost = false;

	public function __construct(
		private Closure $evaluate,
		string $scope,
		private int $leaseMilliseconds = 30000
	) {
		if ( $scope === '' || $leaseMilliseconds < 1 || $leaseMilliseconds > 300000 ) {
			throw new InvalidArgumentException( 'Invalid FrauxSearch Redis scope or lease duration.' );
		}
		$prefix = 'frauxsearch:{' . hash( 'sha256', $scope ) . '}:';
		$this->keys = array_map( static fn ( string $suffix ): string => $prefix . $suffix,
			[ 'lease', 'meta', 'entries', 'all', 'pending', 'done' ] );
		$script = file_get_contents( __DIR__ . '/redis/coordination.lua' );
		if ( $script === false ) { throw new RuntimeException( 'Cannot load FrauxSearch Redis coordination script.' ); }
		$this->script = $script;
	}

	public function initialize( string $epoch, ?array $run = null ): void {
		$this->owned( 'initialize', [ $this->encodeState( [ 'version' => 2, 'epoch' => $epoch,
			'closing' => false, 'run' => $run, 'pending' => null ] ) ] );
	}

	public function keyNames(): array { return $this->keys; }

	public function lock( int $timeout = 0 ): bool {
		if ( $this->token !== null ) { return false; }
		$deadline = hrtime( true ) + max( 0, min( 60, $timeout ) ) * 1000000000;
		$token = bin2hex( random_bytes( 32 ) );
		do {
			$result = $this->call( 'lock', [], $token );
			if ( $result[0] === 1 ) {
				$this->token = $token;
				$this->lost = false;
				return true;
			}
			$remaining = $deadline - hrtime( true );
			if ( $remaining <= 0 ) { return false; }
			usleep( (int)min( 20000, max( 1, $remaining / 1000 ) ) );
		} while ( true );
	}

	public function unlock(): void {
		if ( $this->token === null ) { return; }
		$token = $this->token;
		try { $this->call( 'unlock', [], $token ); } finally {
			$this->token = null;
			$this->lost = false;
		}
	}

	public function assertLocked(): void { $this->owned( 'assert' ); }

	public function readState(): ?array {
		$json = $this->call( 'readState' )[0];
		if ( $json === 0 ) { return null; }
		$state = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->validateState( $state );
		return $state;
	}

	public function writeState( array $state ): void {
		$this->owned( 'writeState', [ $this->encodeState( $state ) ] );
	}

	public function append( int $pageId, ?string $title, bool $completionOnly ): ?int {
		$this->validateId( $pageId, false );
		$entry = json_encode( [ 'title' => $title ?? '', 'completionOnly' => $completionOnly, 'done' => false ],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$id = $this->call( 'append', [ $pageId, $entry ] )[0];
		return $id === 0 ? null : (int)$id;
	}

	public function seal(): bool { return $this->owned( 'seal' )[0] === 1; }

	public function retire(): void { $this->owned( 'retire' ); }

	public function discard(): void { $this->owned( 'discard' ); }

	public function watermark(): int { return (int)$this->call( 'watermark' )[0]; }

	public function pages( int $afterPageId, int $through, bool $pendingOnly ): array {
		$this->validateId( $afterPageId );
		$this->validateId( $through );
		$pages = [];
		do {
			[ $cursor, $exhausted, $batch ] = $this->call( 'pages',
				[ $afterPageId, $through, (int)$pendingOnly, self::BATCH_SIZE - count( $pages ) ] );
			$pages = array_merge( $pages, array_map( 'intval', $batch ) );
			$afterPageId = (int)$cursor;
		} while ( !$exhausted && count( $pages ) < self::BATCH_SIZE );
		return $pages;
	}

	public function entries( int $pageId, int $through ): array {
		$this->validateId( $pageId, false );
		$this->validateId( $through );
		$entries = [];
		$after = 0;
		do {
			$batch = $this->call( 'entries', [ $pageId, $through, $after ] )[0];
			foreach ( $batch as [ $id, $json ] ) {
				$entry = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
				if ( !is_array( $entry ) || !is_string( $entry['title'] ?? null )
					|| !is_bool( $entry['completionOnly'] ?? null ) || !is_bool( $entry['done'] ?? null )
				) { throw new RuntimeException( 'Invalid FrauxSearch Redis journal entry; refusing writes.' ); }
				$after = (int)$id;
				$entries[$after] = $entry;
			}
		} while ( count( $batch ) === self::BATCH_SIZE );
		return $entries;
	}

	public function markDone( int $pageId, array $entryIds ): void {
		$this->validateId( $pageId, false );
		foreach ( $entryIds as $id ) {
			if ( !is_int( $id ) ) { throw new InvalidArgumentException( 'Journal IDs must be integers.' ); }
			$this->validateId( $id, false );
		}
		$chunks = array_chunk( array_values( array_unique( $entryIds ) ), self::BATCH_SIZE );
		foreach ( $chunks ?: [ [] ] as $chunk ) { $this->owned( 'markDone', [ $pageId, ...$chunk ] ); }
	}

	public function prune( int $through ): void {
		$this->validateId( $through );
		do { $removed = $this->owned( 'prune', [ $through ] )[0]; } while ( $removed === self::BATCH_SIZE );
	}

	private function owned( string $operation, array $args = [] ): array {
		if ( $this->token === null || $this->lost ) {
			throw new RuntimeException( 'FrauxSearch Redis writer lease is not held or was lost.' );
		}
		try { return $this->call( $operation, $args, $this->token ); } catch ( Throwable $e ) {
			$this->lost = true;
			throw $e;
		}
	}

	/** @param array<string|int> $args */
	private function call( string $operation, array $args = [], string $token = '' ): array {
		$result = ( $this->evaluate )( $this->script, $this->keys,
			[ $operation, $token, $this->leaseMilliseconds, ...$args ] );
		if ( !is_array( $result ) || !array_is_list( $result ) || !isset( $result[0] ) ) {
			throw new RuntimeException( 'Invalid FrauxSearch Redis coordination response.' );
		}
		if ( $result[0] === 'lost' ) { throw new RuntimeException( 'FrauxSearch Redis writer lost its lease.' ); }
		if ( $result[0] === 'error' ) {
			throw new RuntimeException( 'FrauxSearch Redis coordination: ' . ( $result[1] ?? 'invalid state' ) );
		}
		if ( $result[0] !== 'ok' ) { throw new RuntimeException( 'Invalid FrauxSearch Redis coordination response.' ); }
		$values = array_slice( $result, 1 );
		$valid = match ( $operation ) {
			'pages' => count( $values ) === 3 && $this->responseId( $values[0] )
				&& in_array( $values[1], [ 0, 1 ], true ) && is_array( $values[2] )
				&& array_is_list( $values[2] ) && count( $values[2] ) <= self::BATCH_SIZE
				&& ( (int)$values[0] > $args[0] || ( $values[1] === 1 && (int)$values[0] === $args[0] ) ),
			'entries' => count( $values ) === 1 && is_array( $values[0] )
				&& array_is_list( $values[0] ) && count( $values[0] ) <= self::BATCH_SIZE,
			'readState' => count( $values ) === 1 && ( $values[0] === 0 || is_string( $values[0] ) ),
			'append' => count( $values ) === 1 && ( $values[0] === 0
				|| ( $this->responseId( $values[0] ) && (int)$values[0] > 0 ) ),
			'watermark' => count( $values ) === 1 && $this->responseId( $values[0] ),
			'lock', 'seal' => count( $values ) === 1 && in_array( $values[0], [ 0, 1 ], true ),
			'prune' => count( $values ) === 1 && is_int( $values[0] )
				&& $values[0] >= 0 && $values[0] <= self::BATCH_SIZE,
			default => $values === [ 1 ],
		};
		if ( $valid && $operation === 'pages' ) {
			$previous = $args[0];
			foreach ( $values[2] as $id ) {
				if ( !$this->responseId( $id ) || (int)$id <= $previous || (int)$id > (int)$values[0] ) {
					$valid = false;
					break;
				}
				$previous = (int)$id;
			}
		} elseif ( $valid && $operation === 'entries' ) {
			$previous = $args[2];
			foreach ( $values[0] as $entry ) {
				if ( !is_array( $entry ) || !array_is_list( $entry ) || count( $entry ) !== 2
					|| !$this->responseId( $entry[0] ) || (int)$entry[0] <= $previous
					|| (int)$entry[0] > $args[1] || !is_string( $entry[1] )
				) { $valid = false; break; }
				$previous = (int)$entry[0];
			}
		}
		if ( !$valid ) { throw new RuntimeException( 'Invalid FrauxSearch Redis coordination response.' ); }
		return $values;
	}

	private function responseId( mixed $value ): bool {
		return is_string( $value ) && ctype_digit( $value ) && (string)(int)$value === $value
			&& (int)$value >= 0 && (int)$value <= self::MAX_ID;
	}

	private function validateId( int $id, bool $allowZero = true ): void {
		if ( $id < ( $allowZero ? 0 : 1 ) || $id > self::MAX_ID ) {
			throw new InvalidArgumentException( 'FrauxSearch Redis coordination ID is outside its exact integer range.' );
		}
	}

	private function encodeState( array $state ): string {
		$this->validateState( $state );
		return json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}

	private function validateState( mixed $state ): void {
		if ( !is_array( $state ) || ( $state['version'] ?? null ) !== 2
			|| !$this->operationId( $state['epoch'] ?? null ) || !is_bool( $state['closing'] ?? null )
			|| !array_key_exists( 'run', $state ) || !array_key_exists( 'pending', $state )
			|| ( $state['run'] !== null && !is_array( $state['run'] ) )
			|| ( $state['pending'] !== null && !is_array( $state['pending'] ) )
		) { throw new RuntimeException( 'Invalid FrauxSearch Redis coordination state; refusing writes.' ); }
		$run = $state['run'];
		if ( $run !== null && ( !$this->operationId( $run['id'] ?? null )
			|| !is_bool( $run['recovery'] ?? null ) || $state['closing']
			|| !in_array( $run['phase'] ?? null, [ 'building', 'ready', 'catchup', 'swapping', 'activated', 'aborting' ], true )
			|| !is_int( $run['watermark'] ?? null ) || $run['watermark'] < 0 || $run['watermark'] > self::MAX_ID
			|| !array_key_exists( 'full', $run ) || ( $run['full'] !== null && !$this->indexName( $run['full'] ) )
			|| !$this->indexName( $run['completion'] ?? null ) || $run['full'] === $run['completion']
		) ) { throw new RuntimeException( 'Invalid FrauxSearch Redis rebuild state; refusing writes.' ); }
		$pending = $state['pending'];
		if ( $pending === null ) { return; }
		if ( !$this->operationId( $pending['id'] ?? null )
			|| !in_array( $pending['purpose'] ?? null, [ 'write', 'guard-delete' ], true )
			|| $state['closing'] !== ( $pending['purpose'] === 'guard-delete' )
			|| !in_array( $pending['method'] ?? null, [ 'POST', 'PATCH', 'DELETE' ], true )
			|| !is_string( $pending['path'] ?? null ) || $pending['path'] === '' || $pending['path'][0] !== '/'
			|| !is_string( $pending['startedAt'] ?? null ) || strtotime( $pending['startedAt'] ) === false
			|| !array_key_exists( 'taskUid', $pending )
			|| ( $pending['taskUid'] !== null && ( !is_int( $pending['taskUid'] ) || $pending['taskUid'] < 0 ) )
			|| !array_key_exists( 'swaps', $pending ) || !array_key_exists( 'createdIndex', $pending )
		) { throw new RuntimeException( 'Invalid FrauxSearch Redis pending operation; refusing writes.' ); }
		if ( $pending['purpose'] === 'guard-delete'
			&& ( $pending['method'] !== 'DELETE'
				|| preg_match( '#^/indexes/[^/?]+$#D', $pending['path'] ) !== 1 )
		) { throw new RuntimeException( 'Invalid FrauxSearch Redis guard retirement; refusing writes.' ); }
		if ( $pending['path'] === '/swap-indexes' ) {
			if ( $pending['method'] !== 'POST' || !is_array( $pending['swaps'] )
				|| !array_is_list( $pending['swaps'] ) || $pending['swaps'] === []
			) { throw new RuntimeException( 'Invalid FrauxSearch Redis pending swap; refusing writes.' ); }
			foreach ( $pending['swaps'] as $swap ) {
				$indexes = is_array( $swap ) ? ( $swap['indexes'] ?? null ) : null;
				if ( !is_array( $indexes ) || !array_is_list( $indexes ) || count( $indexes ) !== 2
					|| !$this->indexName( $indexes[0] ) || !$this->indexName( $indexes[1] ) || $indexes[0] === $indexes[1]
				) { throw new RuntimeException( 'Invalid FrauxSearch Redis pending swap pair; refusing writes.' ); }
			}
		} elseif ( $pending['swaps'] !== null ) {
			throw new RuntimeException( 'Unexpected FrauxSearch Redis pending swap data; refusing writes.' );
		}
		if ( $pending['path'] === '/indexes'
			? $pending['method'] !== 'POST' || !$this->indexName( $pending['createdIndex'] )
			: $pending['createdIndex'] !== null
		) { throw new RuntimeException( 'Invalid FrauxSearch Redis pending index creation; refusing writes.' ); }
	}

	private function operationId( mixed $id ): bool {
		return is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ) === 1;
	}

	private function indexName( mixed $name ): bool {
		return is_string( $name ) && preg_match( '/^[A-Za-z0-9_-]+$/D', $name ) === 1;
	}
}

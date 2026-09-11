<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexCoordinator;
use FrauxSearch\MeilisearchException;
use FrauxSearch\RedisCoordinationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/redisCoordination.php';
require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class CoordinatorStatusTest extends TestCase {
	private static RedisCoordinationFixture $redis;
	private RedisCoordinationTestConnection $observer;
	private RedisCoordinationStore $store;
	private RedisCoordinationStore $contender;
	private CoordinationServer $server;
	private string $scope;

	public static function setUpBeforeClass(): void { self::$redis = new RedisCoordinationFixture(); }
	public static function tearDownAfterClass(): void { self::$redis->stop(); }

	protected function setUp(): void {
		$this->scope = bin2hex( random_bytes( 16 ) );
		$this->observer = self::$redis->connect();
		$this->store = new RedisCoordinationStore( self::$redis->connect()->evaluate( ... ), $this->scope );
		$this->contender = new RedisCoordinationStore( self::$redis->connect()->evaluate( ... ), $this->scope );
		$this->server = new CoordinationServer();
		unset( $this->server->indexes['wiki_coordination'], $this->server->primaryKeys['wiki_coordination'] );
	}

	protected function tearDown(): void {
		$this->observer->command( [ 'DEL', ...$this->store->keyNames() ] );
	}

	private function coordinator( ?RedisCoordinationStore $store = null ): IndexCoordinator {
		return new IndexCoordinator( $store ?? $this->store, new CoordinationClient( $this->server ), 'wiki',
			static function (): never { throw new RuntimeException( 'Status must not build source documents.' ); },
			static function (): never { throw new RuntimeException( 'Status must not queue source refreshes.' ); }, 0 );
	}

	public function testIdleSnapshotExcludesIndependentWriterAndLeavesNoKeysOrTasks(): void {
		$reads = 0;
		$this->server->beforeRequest = function ( string $method, string $path ) use ( &$reads ): void {
			$this->assertSame( 'GET', $method );
			$this->assertSame( '/indexes/wiki_coordination', $path );
			$this->assertFalse( $this->contender->lock() );
			$this->store->assertLocked();
			$reads++;
		};
		$status = $this->coordinator()->status();
		$this->assertSame( 1, $reads );
		$this->assertFalse( $status['coordinationLost'] );
		$this->assertNull( $status['epoch'] );
		$this->assertNull( $status['guardEpoch'] );
		$this->assertNull( $status['run'] );
		$this->assertNull( $status['pending'] );
		$this->assertSame( [], $this->server->submissions );
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...$this->store->keyNames() ] ) );
		$this->assertTrue( $this->contender->lock() );
		$this->contender->unlock();
	}

	public static function existingOwners(): array { return [ 'same store' => [ true ], 'other connection' => [ false ] ]; }

	#[DataProvider( 'existingOwners' )]
	public function testBusyStatusDoesNotReadOrReleaseTheExistingOwner( bool $sameStore ): void {
		$owner = $sameStore ? $this->store : $this->contender;
		$this->assertTrue( $owner->lock() );
		$lease = $this->observer->command( [ 'GET', $this->store->keyNames()[0] ] );
		$this->server->beforeRequest = static function (): never {
			throw new RuntimeException( 'Busy status must not read an unprotected snapshot.' );
		};
		try {
			$this->coordinator()->status();
			$this->fail( 'Busy status returned a fabricated snapshot.' );
		} catch ( MeilisearchException $error ) {
			$this->assertTrue( $error->isRetryable() );
			$this->assertSame( 'coordination_busy', $error->getErrorCode() );
		}
		$this->assertSame( $lease, $this->observer->command( [ 'GET', $this->store->keyNames()[0] ] ) );
		$owner->assertLocked();
		$owner->unlock();
		$this->assertSame( [], $this->server->submissions );
	}

	public function testMetadataReadFailureReleasesTheStatusLease(): void {
		$this->server->beforeRequest = static function (): never { throw new RuntimeException( 'Metadata unavailable.' ); };
		try {
			$this->coordinator()->status();
			$this->fail( 'Failed metadata read returned a snapshot.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Metadata unavailable.', $error->getMessage() );
		}
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...$this->store->keyNames() ] ) );
		$this->assertTrue( $this->contender->lock() );
		$this->contender->unlock();
		$this->assertSame( [], $this->server->submissions );
	}

	public function testStateReadFailureRemainsUnhealthyAndReleasesTheLease(): void {
		$connection = self::$redis->connect();
		$store = new RedisCoordinationStore( static function ( $script, $keys, $args ) use ( $connection ): mixed {
			if ( $args[0] === 'readState' ) { throw new RuntimeException( 'Coordination state unavailable.' ); }
			return $connection->evaluate( $script, $keys, $args );
		}, $this->scope );
		$status = $this->coordinator( $store )->status();
		$this->assertTrue( $status['coordinationLost'] );
		$this->assertSame( 'Coordination state unavailable.', $status['error'] );
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...$store->keyNames() ] ) );
		$this->assertSame( [], $this->server->submissions );
	}

	public static function readBoundaries(): array { return [ [ 'metadata' ], [ 'state' ] ]; }

	#[DataProvider( 'readBoundaries' )]
	public function testExpiryDuringReadRejectsSnapshotAndPreservesSuccessorLease( string $boundary ): void {
		$expire = function (): void {
			$this->assertSame( 1, $this->observer->command( [ 'PEXPIRE', $this->store->keyNames()[0], 0 ] ) );
			$this->assertTrue( $this->contender->lock() );
		};
		$store = $this->store;
		if ( $boundary === 'metadata' ) {
			$this->server->beforeRequest = $expire;
		} else {
			$connection = self::$redis->connect();
			$store = new RedisCoordinationStore( static function ( $script, $keys, $args ) use ( $connection, $expire ): mixed {
				$result = $connection->evaluate( $script, $keys, $args );
				if ( $args[0] === 'readState' ) { $expire(); }
				return $result;
			}, $this->scope );
		}
		try {
			$this->coordinator( $store )->status();
			$this->fail( 'An expired reader returned a snapshot.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'lost its lease', $error->getMessage() );
		}
		$this->contender->assertLocked();
		$this->assertFalse( $store->lock() );
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...array_slice( $store->keyNames(), 1 ) ] ) );
		$this->contender->unlock();
		$this->assertSame( [], $this->server->submissions );
	}

	public function testStableGuardWithoutRedisStateStillReportsLostCoordination(): void {
		$epoch = str_repeat( 'a', 32 );
		$this->server->indexes['wiki_coordination'] = [];
		$this->server->primaryKeys['wiki_coordination'] = 'epoch_' . $epoch;
		$status = $this->coordinator()->status();
		$this->assertTrue( $status['coordinationLost'] );
		$this->assertSame( $epoch, $status['guardEpoch'] );
		$this->assertSame( [], $this->server->submissions );
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...$this->store->keyNames() ] ) );
	}

	public static function snapshotFailures(): array { return [ [ 'metadata' ], [ 'lease' ] ]; }

	#[DataProvider( 'snapshotFailures' )]
	public function testUnlockFailureCannotReplaceTheOriginalSnapshotFailure( string $boundary ): void {
		$original = new RuntimeException( "Original $boundary failure." );
		$connection = self::$redis->connect();
		$assertions = 0;
		$unlocks = 0;
		$store = new RedisCoordinationStore( static function ( $script, $keys, $args ) use (
			$connection, $original, $boundary, &$assertions, &$unlocks
		): mixed {
			if ( $args[0] === 'unlock' ) {
				$unlocks++;
				throw new RuntimeException( 'Unlock also failed.' );
			}
			if ( $args[0] === 'assert' && ++$assertions === 2 && $boundary === 'lease' ) { throw $original; }
			return $connection->evaluate( $script, $keys, $args );
		}, $this->scope );
		if ( $boundary === 'metadata' ) {
			$this->server->beforeRequest = static function () use ( $original ): never { throw $original; };
		}
		try {
			$this->coordinator( $store )->status();
			$this->fail( 'A failed snapshot was returned.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $original, $error );
		}
		$this->assertSame( 1, $unlocks );
		$this->assertSame( [], $this->server->submissions );
	}

	public function testSuccessfulSnapshotStillSurfacesFailedUnlockAcknowledgement(): void {
		$failure = new RuntimeException( 'Unlock acknowledgement lost.' );
		$connection = self::$redis->connect();
		$stateRead = false;
		$unlocks = 0;
		$store = new RedisCoordinationStore( static function ( $script, $keys, $args ) use (
			$connection, $failure, &$stateRead, &$unlocks
		): mixed {
			$result = $connection->evaluate( $script, $keys, $args );
			if ( $args[0] === 'readState' ) { $stateRead = true; }
			if ( $args[0] === 'unlock' ) { $unlocks++; throw $failure; }
			return $result;
		}, $this->scope );
		try {
			$this->coordinator( $store )->status();
			$this->fail( 'A failed unlock was silently ignored.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $failure, $error );
		}
		$this->assertTrue( $stateRead );
		$this->assertSame( 1, $unlocks );
		$this->assertSame( [], $this->server->submissions );
		$this->assertSame( 0, $this->observer->command( [ 'EXISTS', ...$store->keyNames() ] ) );
	}
}

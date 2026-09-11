<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexCoordinator;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\RedisCoordinationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/redisCoordination.php';
require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class LeaseHeartbeatTest extends TestCase {
	private static RedisCoordinationFixture $server;
	private RedisCoordinationTestConnection $observer;
	private RedisCoordinationStore $store;
	private RedisCoordinationStore $contender;

	public static function setUpBeforeClass(): void { self::$server = new RedisCoordinationFixture(); }
	public static function tearDownAfterClass(): void { self::$server->stop(); }
	protected function setUp(): void {
		$scope = bin2hex( random_bytes( 16 ) );
		$this->observer = self::$server->connect();
		$this->store = new RedisCoordinationStore( self::$server->connect()->evaluate( ... ), $scope, 500 );
		$this->contender = new RedisCoordinationStore( $this->observer->evaluate( ... ), $scope, 500 );
	}
	protected function tearDown(): void {
		$this->observer->command( [ 'DEL', ...$this->store->keyNames() ] );
	}

	public static function operations(): array { return [ [ 'configure' ], [ 'refresh' ] ]; }

	#[DataProvider( 'operations' )]
	public function testLongPollingRenewsRawAndDerivedClientsAcrossManyLeasePeriods( string $operation ): void {
		$server = new CoordinationServer();
		unset( $server->indexes['wiki_coordination'], $server->primaryKeys['wiki_coordination'] );
		$polls = [];
		$started = [];
		$elapsed = [];
		$server->beforeRequest = function ( string $method, string $path ) use ( $server, &$polls, &$started, &$elapsed ): void {
			if ( $method !== 'GET' || !str_starts_with( $path, '/tasks/' ) ) { return; }
			$id = (int)substr( $path, strlen( '/tasks/' ) );
			$this->assertFalse( $this->contender->lock(), 'An independent writer acquired a lease during task polling.' );
			$this->assertGreaterThan( 0, $this->observer->command( [ 'PTTL', $this->store->keyNames()[0] ] ) );
			if ( $server->tasks[$id]['status'] === 'succeeded' ) { return; }
			$started[$id] ??= hrtime( true );
			$polls[$id] = ( $polls[$id] ?? 0 ) + 1;
			$server->tasks[$id]['status'] = $polls[$id] < 10 ? 'processing' : 'enqueued';
			if ( $polls[$id] === 10 ) { $elapsed[$id] = hrtime( true ) - $started[$id]; }
		};
		$original = new CoordinationClient( $server );
		$worker = new IndexCoordinator( $this->store, $original, 'wiki',
			static fn ( int $id ): array => [ 'document' => [ 'id' => $id, 'title' => "Page $id",
				'revision_id' => 1, 'outgoing_link_ids' => [], 'redirects' => [] ],
				'is_redirect' => false, 'redirect_target_id' => null ], static function (): void {}, 0 );
		if ( $operation === 'refresh' ) { $worker->refresh( 1 ); } else { $worker->configure(); }
		$this->assertCount( 4, $server->submissions, 'Guard create/delete and both index writes are awaited.' );
		$this->assertCount( 4, $elapsed );
		foreach ( $elapsed as $nanoseconds ) { $this->assertGreaterThan( 500_000_000, $nanoseconds ); }
		$this->assertNull( $worker->status()['pending'] );
		$this->assertNull( $this->store->readState() );
		$this->assertArrayNotHasKey( 'wiki_coordination', $server->indexes );
		$this->assertSame( [], $this->store->pages( 0, $this->store->watermark(), true ) );
		if ( $operation === 'refresh' ) {
			$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
		}
		$this->assertTrue( $this->contender->lock(), 'Completed coordinator operation did not release its lease.' );
		$this->contender->unlock();
		$server->beforeRequest = null;
		$original->waitForTask( 1 );
	}

	public function testOwnershipLostInsideTaskReadCannotBeAcceptedAsTerminalSuccess(): void {
		$this->assertTrue( $this->store->lock() );
		$client = new class( $this->observer, $this->store->keyNames()[0] ) extends MeilisearchClient {
			public int $polls = 0;
			public function __construct( private RedisCoordinationTestConnection $redis, private string $lease ) {
				parent::__construct( 'http://unused', '', 'wiki', 1 );
			}
			public function getTask( int $taskUid ): array {
				$this->polls++;
				$this->redis->command( [ 'PEXPIRE', $this->lease, 0 ] );
				return [ 'uid' => $taskUid, 'status' => 'succeeded' ];
			}
		};
		$guarded = $client->withTaskPollHandler( $this->store->assertLocked( ... ) )
			->withIndex( 'completion' )->withMutationHandler( static fn () => [] );
		try {
			$guarded->waitForTask( 1 );
			$this->fail( 'The terminal response was accepted after lease expiry.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'lost its lease', $error->getMessage() );
			$this->assertSame( 1, $guarded->polls );
		}
		$this->store->unlock();
		$this->assertTrue( $this->contender->lock() );
		$this->contender->unlock();
	}

	public function testLostOwnershipPreventsTheNextTaskReadAndNullTaskNeedsNoLease(): void {
		$client = new class extends MeilisearchClient {
			public int $polls = 0;
			public function __construct() { parent::__construct( 'http://unused', '', 'wiki', 1 ); }
			public function getTask( int $taskUid ): array {
				$this->polls++;
				return [ 'uid' => $taskUid, 'status' => 'succeeded' ];
			}
		};
		$guarded = $client->withTaskPollHandler( $this->store->assertLocked( ... ) );
		$guarded->waitForTask( null );
		try {
			$guarded->waitForTask( 1 );
			$this->fail( 'Task read proceeded without writer ownership.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'not held', $error->getMessage() );
			$this->assertSame( 0, $guarded->polls );
		}
	}
}

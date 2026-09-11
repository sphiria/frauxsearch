<?php

namespace FrauxSearch\Tests;

use Closure;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\RedisCoordinationStore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/redisCoordination.php';
require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class RedisCoordinationStoreTest extends TestCase {
	private static RedisCoordinationFixture $server;
	private RedisCoordinationTestConnection $redis;
	private string $scope;
	private string $epoch;
	private array $stores = [];

	public static function setUpBeforeClass(): void { self::$server = new RedisCoordinationFixture(); }
	public static function tearDownAfterClass(): void { self::$server->stop(); }
	protected function setUp(): void {
		$this->redis = self::$server->connect();
		$this->scope = bin2hex( random_bytes( 16 ) );
		$this->epoch = str_repeat( 'a', 32 );
	}
	protected function tearDown(): void {
		$keys = [];
		foreach ( $this->stores as $store ) { $keys = array_merge( $keys, $store->keyNames() ); }
		if ( $keys !== [] ) { $this->redis->command( [ 'DEL', ...array_values( array_unique( $keys ) ) ] ); }
	}

	private function store( bool $initialize = false, ?Closure $evaluate = null ): RedisCoordinationStore {
		$store = new RedisCoordinationStore( $evaluate ?? self::$server->connect()->evaluate( ... ), $this->scope );
		$this->stores[] = $store;
		if ( $initialize ) {
			$this->assertTrue( $store->lock() );
			try { $store->initialize( $this->epoch ); } finally { $store->unlock(); }
		}
		return $store;
	}

	private function state( ?array $run = null, ?array $pending = null ): array {
		return [ 'version' => 2, 'epoch' => $this->epoch, 'closing' => false, 'run' => $run, 'pending' => $pending ];
	}

	private function failure( callable $callback, string $message ): void {
		try { $callback(); $this->fail( 'Expected failure containing ' . $message ); }
		catch ( RuntimeException $e ) { $this->assertStringContainsString( $message, $e->getMessage() ); }
	}

	private function expireLease( RedisCoordinationStore $store ): void {
		$key = $store->keyNames()[0];
		$this->assertSame( 1, $this->redis->command( [ 'PEXPIRE', $key, 1 ] ) );
		$deadline = hrtime( true ) + 1_000_000_000;
		do {
			if ( $this->redis->command( [ 'GET', $key ] ) === null ) { return; }
			usleep( 1000 );
		} while ( hrtime( true ) < $deadline );
		$this->fail( 'Redis did not expire the owned test lease.' );
	}

	public function testAbsentScopeIsIdleWithoutCreatingKeysAndLeasePrecedesInitialization(): void {
		$store = $this->store();
		$this->assertNull( $store->readState() );
		$this->assertNull( $store->append( 1, null, false ) );
		$this->assertSame( [], $store->pages( 50, 100, true ) );
		$this->assertSame( [], $store->entries( 1, 100 ) );
		$this->assertSame( 0, $store->watermark() );
		$this->assertSame( 0, $this->redis->command( [ 'EXISTS', ...$store->keyNames() ] ) );
		$this->failure( fn () => $store->initialize( $this->epoch ), 'not held' );
		$this->assertTrue( $store->lock() );
		$store->assertLocked();
		$this->assertSame( 1, $this->redis->command( [ 'EXISTS', ...$store->keyNames() ] ) );
		$store->initialize( $this->epoch );
		$this->assertSame( $this->state(), $store->readState() );
		$store->unlock();
	}

	public function testInitializationSeedsMandatoryRecoveryRunAtomicallyAndCannotOverwriteIt(): void {
		$store = $this->store();
		$run = $this->rebuildState();
		$run['recovery'] = true;
		$this->assertTrue( $store->lock() );
		$store->initialize( $this->epoch, $run );
		$this->assertSame( $this->state( $run ), $store->readState() );
		$this->assertFalse( $store->seal(), 'Mandatory recovery must keep its guard even without journal rows.' );
		$this->failure( fn () => $store->initialize( bin2hex( random_bytes( 16 ) ) ), 'refusing to overwrite' );
		$this->assertSame( $run, $store->readState()['run'] );
		$store->unlock();
	}

	public function testInvalidRecoveryInitializationPublishesNoStateAndRetainsLease(): void {
		$store = $this->store();
		$store->lock();
		$run = $this->rebuildState();
		unset( $run['recovery'] );
		$this->failure( fn () => $store->initialize( $this->epoch, $run ), 'Invalid FrauxSearch Redis rebuild state' );
		$this->failure( fn () => $store->initialize( 'untrusted}:epoch' ), 'Invalid FrauxSearch Redis coordination state' );
		$this->assertNull( $store->readState() );
		$this->assertSame( 0, $this->redis->command( [ 'EXISTS', ...array_slice( $store->keyNames(), 1 ) ] ) );
		$store->assertLocked();
		$store->initialize( $this->epoch );
		$this->assertSame( $this->state(), $store->readState() );
		$store->unlock();
	}

	public function testSealingExcludesConcurrentProducersAndRetirementAllowsFreshEpoch(): void {
		$store = $this->store( true );
		$producer = $this->store();
		$this->assertTrue( $store->lock() );
		$id = $producer->append( 1, 'Before seal', false );
		$this->assertFalse( $store->seal() );
		$store->markDone( 1, [ $id ] );
		$this->assertFalse( $store->seal(), 'Completed journal history must be pruned before closing.' );
		$store->prune( $id );
		$this->assertTrue( $store->seal() );
		$this->assertNull( $producer->append( 2, 'After seal', false ) );
		$this->assertSame( $id, $store->watermark() );
		$this->assertSame( [], $store->pages( 0, $id, false ) );
		$lease = $this->redis->command( [ 'GET', $store->keyNames()[0] ] );
		$store->retire();
		$this->assertNull( $store->readState() );
		$this->assertSame( 0, $this->redis->command( [ 'EXISTS', ...array_slice( $store->keyNames(), 1 ) ] ) );
		$this->assertSame( $lease, $this->redis->command( [ 'GET', $store->keyNames()[0] ] ) );
		$this->assertFalse( $producer->lock(), 'Retirement must retain ownership until finally unlocks.' );
		$epoch = bin2hex( random_bytes( 16 ) );
		$store->initialize( $epoch );
		$this->assertSame( $epoch, $store->readState()['epoch'] );
		$this->assertSame( 1, $producer->append( 2, 'Fresh epoch', false ) );
		$this->failure( fn () => $store->writeState( $this->state() ), 'epoch or closing state changed' );
		$this->assertSame( $epoch, $store->readState()['epoch'] );
		$store->unlock();
	}

	public function testSealedStateCannotBeReopenedOrRetiredWithAnUnsettledGuardDeletion(): void {
		$store = $this->store( true );
		$store->lock();
		$this->assertTrue( $store->seal() );
		$this->failure( fn () => $store->writeState( $this->state() ), 'epoch or closing state changed' );
		$store->unlock();
		$store->lock();
		$state = $store->readState();
		$state['pending'] = [ 'id' => bin2hex( random_bytes( 16 ) ), 'purpose' => 'guard-delete',
			'method' => 'DELETE', 'path' => '/indexes/wiki_coordination', 'taskUid' => 42,
			'startedAt' => gmdate( 'c' ), 'swaps' => null, 'createdIndex' => null ];
		$store->writeState( $state );
		$this->assertFalse( $store->seal() );
		$this->failure( $store->retire( ... ), 'sealed, empty' );
		$this->assertSame( $state, $store->readState() );
		$store->unlock();
	}

	public function testExpiredRetirementCannotDeleteTheSuccessorsEpochOrLease(): void {
		$first = $this->store( true );
		$second = $this->store();
		$first->lock();
		$this->assertTrue( $first->seal() );
		$this->expireLease( $first );
		$this->assertTrue( $second->lock() );
		$second->retire();
		$epoch = bin2hex( random_bytes( 16 ) );
		$second->initialize( $epoch );
		$this->failure( $first->retire( ... ), 'lost its lease' );
		$first->unlock();
		$second->assertLocked();
		$this->assertSame( $epoch, $second->readState()['epoch'] );
		$second->unlock();
	}

	public function testHashedScopesAreIndependentAndShareOnlyTheirOwnHashTag(): void {
		$first = $this->store( true );
		$this->scope = 'untrusted}:scope:{input';
		$second = $this->store( true );
		$this->assertSame( [], array_intersect( $first->keyNames(), $second->keyNames() ) );
		foreach ( $second->keyNames() as $key ) {
			$this->assertMatchesRegularExpression( '/^frauxsearch:\{[a-f0-9]{64}\}:[a-z]+$/D', $key );
		}
		$this->assertTrue( $first->lock() );
		$this->assertTrue( $second->lock() );
		$first->append( 4, null, false );
		$this->assertSame( 0, $second->watermark() );
		$first->unlock();
		$second->unlock();
	}

	public function testExpiredOwnerCannotOverwriteStateOrUnlockItsSuccessor(): void {
		$first = $this->store( true );
		$second = $this->store();
		$this->assertTrue( $first->lock() );
		$this->assertFalse( $first->lock() );
		$this->assertFalse( $second->lock() );
		$key = $first->keyNames()[0];
		$this->redis->command( [ 'PEXPIRE', $key, 1000 ] );
		$first->assertLocked();
		$this->assertGreaterThan( 20000, $this->redis->command( [ 'PTTL', $key ] ) );
		$this->expireLease( $first );
		$this->assertTrue( $second->lock() );
		$winner = $this->state( $this->rebuildState() );
		$second->writeState( $winner );
		$this->failure( fn () => $first->writeState( $this->state() ), 'lost its lease' );
		$this->assertFalse( $first->lock(), 'A failed acquisition cannot be reacquired within its stale callback.' );
		$first->unlock();
		$second->assertLocked();
		$this->assertSame( $winner, $second->readState() );
		$second->unlock();
		$this->assertTrue( $first->lock(), 'A new top-level operation may acquire a new token.' );
		$first->unlock();
	}

	public static function guardedMutations(): array { return [ [ 'markDone' ], [ 'prune' ] ]; }
	#[DataProvider( 'guardedMutations' )]
	public function testStaleJournalMutationCannotAcknowledgeOrPruneNewOwnerWork( string $method ): void {
		$first = $this->store( true );
		$second = $this->store();
		$id = $first->append( 1, 'History', false );
		$first->lock();
		if ( $method === 'prune' ) { $first->markDone( 1, [ $id ] ); }
		$this->expireLease( $first );
		$second->lock();
		$args = $method === 'prune' ? [ $id ] : [ 1, [ $id ] ];
		$this->failure( fn () => $first->$method( ...$args ), 'lost its lease' );
		$this->assertCount( 1, $second->entries( 1, $id ) );
		$this->assertSame( $method === 'prune', $second->entries( 1, $id )[$id]['done'] );
		$first->unlock();
		$second->unlock();
	}

	public function testConcurrentAppendsPreserveExactIdsTitlesModesAndSnapshotBoundaries(): void {
		$first = $this->store( true );
		$second = $this->store();
		$first->lock();
		$old = $second->append( 20, 'Old title / é', true );
		$other = $second->append( 10, null, false );
		$through = $first->watermark();
		$new = $second->append( 20, 'New title', false );
		$this->assertSame( [ 1, 2, 3 ], [ $old, $other, $new ] );
		$this->assertSame( [ $old => [ 'title' => 'Old title / é', 'completionOnly' => true, 'done' => false ] ],
			$first->entries( 20, $through ) );
		$first->markDone( 20, [ $old, $other ] );
		$this->assertSame( [ 10 ], $first->pages( 0, $through, true ) );
		$this->assertSame( [ 10, 20 ], $first->pages( 0, $new, true ) );
		$this->assertSame( [ 10, 20 ], $first->pages( 0, $through, false ) );
		$first->prune( $through );
		$this->assertSame( [ $new ], array_keys( $first->entries( 20, $new ) ) );
		$this->assertFalse( $first->entries( 10, $new )[$other]['done'] );
		$this->assertSame( 3, $first->watermark() );
		$first->unlock();
	}

	public function testPageEnumerationIsSortedUniqueAndBoundedAcrossNewerPages(): void {
		$store = $this->store( true, $this->redis->evaluate( ... ) );
		for ( $page = 1001; $page >= 1; $page-- ) { $store->append( $page, null, false ); }
		$through = $store->watermark();
		$store->append( 1, 'Duplicate source page', true );
		$this->assertSame( range( 1, 500 ), $store->pages( 0, $through, true ) );
		$this->assertSame( range( 501, 1000 ), $store->pages( 500, $through, false ) );
		$this->assertSame( [ 1001 ], $store->pages( 1000, $through, true ) );
		$this->redis->evaluations = [];
		$this->assertSame( [ 1001 ], $store->pages( 0, 1, true ) );
		$this->assertCount( 3, $this->redis->evaluations, 'Each Lua scan visits at most 500 distinct pages.' );
	}

	public function testEntriesAcknowledgementsAndPruningUseBoundedBatchesWithoutReusingIds(): void {
		$store = $this->store( true, $this->redis->evaluate( ... ) );
		$ids = [];
		for ( $i = 0; $i < 1001; $i++ ) { $ids[] = $store->append( 1, "Title $i", false ); }
		$through = $store->watermark();
		$this->redis->evaluations = [];
		$this->assertCount( 1001, $store->entries( 1, $through ) );
		$this->assertCount( 3, $this->redis->evaluations );
		$store->lock();
		$this->redis->evaluations = [];
		$store->markDone( 1, $ids );
		$this->assertCount( 3, $this->redis->evaluations );
		$store->writeState( $this->state( $this->rebuildState() ) );
		$this->assertSame( [], $store->pages( 0, $through, true ) );
		$this->assertSame( [ 1 ], $store->pages( 0, $through, false ) );
		$this->failure( fn () => $store->prune( $through ), 'rebuild still needs' );
		$store->unlock();
		$store->lock();
		$store->writeState( $this->state() );
		$new = $store->append( 1, 'After snapshot', true );
		$this->redis->evaluations = [];
		$store->prune( $through );
		$this->assertCount( 3, $this->redis->evaluations );
		$this->assertSame( [ $new ], array_keys( $store->entries( 1, $new ) ) );
		$this->assertSame( 1002, $store->watermark() );
		$store->unlock();
		foreach ( $store->keyNames() as $key ) {
			$this->assertContains( $this->redis->command( [ 'PTTL', $key ] ), [ -1, -2 ] );
		}
	}

	public static function corruption(): array {
		return [ [ 'state' ], [ 'sequence' ], [ 'missing-meta' ], [ 'missing-index' ], [ 'missing-journal' ],
			[ 'expiry' ], [ 'wrong-type' ] ];
	}
	#[DataProvider( 'corruption' )]
	public function testCorruptOrExpiringDataNeverLooksLikeAnIdleScope( string $kind ): void {
		$store = $this->store( true );
		$store->append( 1, null, false );
		$keys = $store->keyNames();
		match ( $kind ) {
			'state' => $this->redis->command( [ 'HSET', $keys[1], 'state', '{"version":99}' ] ),
			'sequence' => $this->redis->command( [ 'HSET', $keys[1], 'sequence', 'invalid' ] ),
			'missing-meta' => $this->redis->command( [ 'DEL', $keys[1] ] ),
			'missing-index' => $this->redis->command( [ 'DEL', $keys[4] ] ),
			'missing-journal' => $this->redis->command( [ 'DEL', ...array_slice( $keys, 2 ) ] ),
			'expiry' => $this->redis->command( [ 'PEXPIRE', $keys[2], 30000 ] ),
			'wrong-type' => $this->redis->command( [ 'SET', $keys[2], 'wrong' ] ),
		};
		$this->failure( $store->readState( ... ), 'coordination' );
		$this->failure( fn () => $store->append( 2, null, false ), 'coordination' );
		$this->assertTrue( $store->lock(), 'Corrupt metadata must not prevent explicit fenced recovery.' );
		$store->assertLocked();
		$this->failure( fn () => $store->initialize( $this->epoch ), 'refusing to overwrite' );
		$store->unlock();
		$store->lock();
		$lease = $this->redis->command( [ 'GET', $keys[0] ] );
		$store->discard();
		$this->assertNull( $store->readState() );
		$this->assertSame( 0, $this->redis->command( [ 'EXISTS', ...array_slice( $keys, 1 ) ] ) );
		$this->assertSame( $lease, $this->redis->command( [ 'GET', $keys[0] ] ) );
		$store->unlock();
	}

	public function testExplicitDiscardCannotBypassLeaseOwnershipEvenForInvalidState(): void {
		$first = $this->store( true );
		$second = $this->store();
		$first->lock();
		$this->redis->command( [ 'SET', $first->keyNames()[1], 'invalid state' ] );
		$this->failure( $second->discard( ... ), 'not held' );
		$this->expireLease( $first );
		$this->assertTrue( $second->lock() );
		$this->failure( $first->discard( ... ), 'lost its lease' );
		$this->assertSame( 'invalid state', $this->redis->command( [ 'GET', $first->keyNames()[1] ] ) );
		$second->discard();
		$first->unlock();
		$second->assertLocked();
		$this->assertNull( $second->readState() );
		$second->unlock();
	}

	public function testSequenceKeepsExactIntegersAndRefusesOverflowBeforePublishing(): void {
		$store = $this->store( true );
		$this->redis->command( [ 'HSET', $store->keyNames()[1], 'sequence', '9007199254740990' ] );
		$id = $store->append( 9007199254740991, null, false );
		$this->assertSame( 9007199254740991, $id );
		$this->assertSame( [ $id ], $store->pages( 0, $id, true ) );
		$this->assertSame( [ $id ], array_keys( $store->entries( $id, $id ) ) );
		$this->failure( fn () => $store->append( 1, null, false ), 'sequence exhausted' );
		$this->assertSame( $id, $store->watermark() );
	}

	public function testInvalidArgumentsDoNotReachRedis(): void {
		$store = $this->store( true, $this->redis->evaluate( ... ) );
		$this->redis->evaluations = [];
		foreach ( [ fn () => $store->append( 0, null, false ), fn () => $store->pages( -1, 0, true ),
			fn () => $store->entries( 1, PHP_INT_MAX ), fn () => $store->markDone( 1, [ '1' ] ) ] as $call ) {
			try { $call(); $this->fail( 'Expected invalid argument' ); }
			catch ( InvalidArgumentException $e ) { $this->addToAssertionCount( 1 ); }
		}
		$this->assertSame( [], $this->redis->evaluations );
	}

	private function worker( RedisCoordinationStore $store, CoordinationServer $server, ?Closure $build = null ): IndexCoordinator {
		return new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			$build ?? static fn ( int $id ): array => [ 'document' => [ 'id' => $id, 'title' => "Page $id",
				'revision_id' => 1, 'outgoing_link_ids' => [], 'redirects' => [] ],
				'is_redirect' => false, 'redirect_target_id' => null ], static function (): void {}, 0 );
	}

	private function rebuildState(): array {
		return [ 'id' => str_repeat( 'a', 32 ), 'phase' => 'building', 'watermark' => 0, 'recovery' => false,
			'full' => 'wiki_generation', 'completion' => 'wiki_completion_generation' ];
	}

	public function testIncompletePendingTaskCannotBeSettledOrAllowFurtherHttp(): void {
		$store = $this->store( true );
		$state = $this->state( pending: [ 'taskUid' => 1 ] );
		$store->lock();
		$this->failure( fn () => $store->writeState( $state ), 'Invalid FrauxSearch Redis pending operation' );
		$store->unlock();
		$this->redis->command( [ 'HSET', $store->keyNames()[1], 'state', json_encode( $state ) ] );
		$server = new CoordinationServer();
		$this->failure( fn () => $this->worker( $store, $server )->drain(), 'Invalid coordination state' );
		$this->assertSame( [], $server->submissions );
		$this->assertSame( json_encode( $state ), $this->redis->command( [ 'HGET', $store->keyNames()[1], 'state' ] ) );
	}

	public function testMalformedRunCannotSelectGenerationCleanupTargets(): void {
		$store = $this->store( true );
		$state = $this->state( [ 'id' => str_repeat( 'a', 32 ), 'phase' => 'activated' ] );
		$this->redis->command( [ 'HSET', $store->keyNames()[1], 'state', json_encode( $state ) ] );
		$server = new CoordinationServer();
		$this->failure( fn () => $this->worker( $store, $server )->finishRebuild( $state['run']['id'] ),
			'Invalid coordination state' );
		$this->assertSame( [], $server->submissions );
	}

	public function testAclFailureMidAcknowledgementCannotSilentlySkipThePendingEntry(): void {
		$store = $this->store( true );
		$id = $store->append( 1, 'Retained history', false );
		$user = 'test_' . bin2hex( random_bytes( 6 ) );
		$password = bin2hex( random_bytes( 16 ) );
		$this->redis->command( [ 'ACL', 'SETUSER', $user, 'on', '>' . $password, '~*', '+@all', '-zrem' ] );
		try {
			$restricted = self::$server->connect();
			$restricted->command( [ 'AUTH', $user, $password ] );
			$writer = $this->store( false, $restricted->evaluate( ... ) );
			$writer->lock();
			$this->failure( fn () => $writer->markDone( 1, [ $id ] ), 'Redis fixture error' );
			$writer->unlock();
			$this->assertSame( [ 1 ], $store->pages( 0, $id, true ) );
			$this->failure( fn () => $store->entries( 1, $id ), 'Journal entry and indexes disagree' );
			$server = new CoordinationServer();
			$this->failure( fn () => $this->worker( $store, $server )->drain(), 'Journal entry and indexes disagree' );
			$this->assertSame( [], $server->submissions );
		} finally {
			$this->redis->command( [ 'ACL', 'DELUSER', $user ] );
		}
	}

	public function testLeaseExpiryAfterIntentCannotLetSuccessorOvertakeAnUnknownSend(): void {
		$server = new CoordinationServer();
		$successorStore = $this->store( true );
		$successor = $this->worker( $successorStore, $server );
		$first = null;
		$injected = false;
		$first = $this->store( false, function ( $script, $keys, $args ) use ( &$first, &$injected, $successor ): mixed {
			$result = $this->redis->evaluate( $script, $keys, $args );
			if ( !$injected && $args[0] === 'writeState' && json_decode( $args[3], true )['pending'] !== null ) {
				$injected = true;
				$this->expireLease( $first );
				$this->failure( $successor->drain( ... ), 'unknown outcome' );
			}
			return $result;
		} );
		$this->failure( fn () => $this->worker( $first, $server )->refresh( 1 ), 'lost its lease' );
		$this->assertCount( 1, $server->submissions, 'The delayed original send can happen, but cannot be overtaken.' );
		$pending = $successorStore->readState()['pending'];
		$this->assertNull( $pending['taskUid'] );
		$this->assertSame( [ 1 ], $successorStore->pages( 0, $successorStore->watermark(), true ) );
		$this->failure( $successor->drain( ... ), 'unknown outcome' );
		$this->assertCount( 1, $server->submissions );
		$successor->adoptTask( $pending['id'], 1 );
		$successor->drain();
		$this->assertNull( $successorStore->readState() );
		$this->assertSame( [], $successorStore->pages( 0, $successorStore->watermark(), false ) );
		$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
	}

	public function testKnownTaskCanBeSettledBySuccessorWhileOriginalCannotContinue(): void {
		$server = new CoordinationServer();
		$successorStore = $this->store( true );
		$successor = $this->worker( $successorStore, $server );
		$first = null;
		$injected = false;
		$first = $this->store( false, function ( $script, $keys, $args ) use ( &$first, &$injected, $successor ): mixed {
			$result = $this->redis->evaluate( $script, $keys, $args );
			if ( !$injected && $args[0] === 'writeState'
				&& ( json_decode( $args[3], true )['pending']['taskUid'] ?? null ) !== null ) {
				$injected = true;
				$this->expireLease( $first );
				$successor->drain();
			}
			return $result;
		} );
		$this->failure( fn () => $this->worker( $first, $server )->refresh( 1 ), 'lost its lease' );
		$this->assertCount( 4, $server->submissions, 'Original full write, successor pair, then guard retirement.' );
		$this->assertNull( $successorStore->readState() );
		$this->assertSame( [], $successorStore->pages( 0, $successorStore->watermark(), false ) );
		$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
	}

	public function testBuilderPausedAcrossLeaseExpiryCannotSubmitStaleDocuments(): void {
		$server = new CoordinationServer();
		$first = $this->store( true );
		$successor = $this->worker( $this->store(), $server );
		$build = function ( int $id ) use ( $first, $successor ): array {
			$this->expireLease( $first );
			$successor->refresh( $id );
			return [ 'document' => [ 'id' => $id, 'title' => 'Stale', 'revision_id' => 0, 'outgoing_link_ids' => [],
				'redirects' => [] ], 'is_redirect' => false, 'redirect_target_id' => null ];
		};
		$this->failure( fn () => $this->worker( $first, $server, $build )->refresh( 1 ), 'lost its lease' );
		$this->assertCount( 3, $server->submissions );
		$this->assertSame( 1, $server->indexes['wiki'][1]['revision_id'] );
		$this->assertNull( $first->readState() );
	}

	public function testExpiredBatchCannotWriteAfterAnotherOwnerAbortsItsValidatedRun(): void {
		$server = new CoordinationServer();
		$successorStore = $this->store( true );
		$successor = $this->worker( $successorStore, $server );
		$run = $successor->beginRebuild( false );
		$reads = 0;
		$replacement = null;
		$first = null;
		$first = $this->store( false, function ( $script, $keys, $args ) use (
			&$first, &$reads, &$replacement, $successor, $run
		): mixed {
			$result = $this->redis->evaluate( $script, $keys, $args );
			if ( $args[0] === 'readState' && ++$reads === 3 ) {
				$this->expireLease( $first );
				$successor->abortRebuild( $run['id'] );
				$replacement = $successor->beginRebuild( true );
			}
			return $result;
		} );
		$this->failure( fn () => $this->worker( $first, $server )->writeBatch( $run['id'], [ [ 'id' => 1 ] ], [] ),
			'lost its lease' );
		$this->assertSame( $replacement, $successorStore->readState()['run'] );
		$this->assertArrayNotHasKey( $run['full'], $server->indexes );
		$this->assertArrayNotHasKey( $run['completion'], $server->indexes );
		$this->assertSame( [], $server->indexes[$replacement['completion']] );
		$this->failure( fn () => $successor->writeBatch( $run['id'], [], [] ), 'ownership or phase' );
	}

	public function testLostRedisIntentAcknowledgementNeverAuthorizesHttpSubmission(): void {
		$server = new CoordinationServer();
		$successorStore = $this->store( true );
		$successor = $this->worker( $successorStore, $server );
		$lost = false;
		$first = $this->store( false, function ( $script, $keys, $args ) use ( &$lost ): mixed {
			$result = $this->redis->evaluate( $script, $keys, $args );
			if ( !$lost && $args[0] === 'writeState' ) {
				$lost = true;
				throw new RuntimeException( 'Lost Redis intent response' );
			}
			return $result;
		} );
		$this->failure( fn () => $this->worker( $first, $server )->refresh( 1 ), 'Lost Redis intent response' );
		$pending = $successorStore->readState()['pending'];
		$this->assertNotNull( $pending );
		$this->assertNull( $pending['taskUid'] );
		$this->assertSame( [], $server->submissions );
		$this->failure( $successor->drain( ... ), 'unknown outcome' );
		$this->assertSame( [], $server->submissions );
	}

	public static function malformedAcknowledgements(): array {
		return [ [ [] ], [ [ 'ok' ] ], [ [ 'ok', null ] ], [ [ 'ok', false ] ], [ [ 'ok', '1' ] ] ];
	}
	#[DataProvider( 'malformedAcknowledgements' )]
	public function testMalformedIntentAcknowledgementCannotPermitHttpOrRetry( array $response ): void {
		$server = new CoordinationServer();
		$this->store( true );
		$first = $this->store( false, function ( $script, $keys, $args ) use ( $response ): mixed {
			$result = $this->redis->evaluate( $script, $keys, $args );
			return $args[0] === 'writeState' ? $response : $result;
		} );
		$this->failure( fn () => $this->worker( $first, $server )->refresh( 1 ), 'Invalid FrauxSearch Redis' );
		$this->assertSame( [], $server->submissions );
		$this->failure( fn () => $this->worker( $this->store(), $server )->drain(), 'unknown outcome' );
		$this->assertSame( [], $server->submissions );
	}
}

<?php

namespace FrauxSearch\Tests;

use Closure;
use FrauxSearch\IndexCoordinatorFactory;
use FrauxSearch\RedisCoordinationStore;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Wikimedia\ObjectCache\RedisConnectionPool;
use Wikimedia\ObjectCache\RedisConnRef;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class IndexCoordinatorFactoryTest extends TestCase {
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/redisConnection.php';
		RedisConnectionPool::$connection = new RedisConnRef();
		MediaWikiServices::$values = [
			'FrauxSearchCoordinationRedis' => null,
			'FrauxSearchCoordinationCacheType' => null,
			'MainCacheType' => 'redis',
			'ObjectCaches' => [ 'redis' => [
				'class' => 'RedisBagOStuff', 'servers' => [ 'cache-redis:6379' ],
				'password' => 'private-cache-password', 'prefix' => 'mw-cache:',
			] ],
			'FrauxSearchIndex' => 'wiki', 'FrauxSearchUrl' => 'http://127.0.0.1:7700/',
			'FrauxSearchApiKey' => '', 'FrauxSearchTaskApiKey' => '', 'FrauxSearchTimeout' => 5,
			'DBname' => 'wiki_source', 'DBprefix' => 'local_',
		];
	}

	private function assertNoOtherServicesOrScripts(): void {
		$this->assertSame( [], MediaWikiServices::$unexpectedCalls );
		$this->assertSame( [ 'setOption', 'select' ], array_column( RedisConnectionPool::$connection->calls, 0 ) );
	}

	public function testDefaultsToMainRedisCacheWithoutInitializingSourceOrCoordinationState(): void {
		$store = IndexCoordinatorFactory::createStore();
		$this->assertInstanceOf( RedisCoordinationStore::class, $store );
		$this->assertSame( 'cache-redis:6379', RedisConnectionPool::$server );
		$this->assertSame( 'private-cache-password', RedisConnectionPool::$options['password'] );
		$this->assertSame( [ 'select', [ 0 ] ], RedisConnectionPool::$connection->calls[1] );
		$this->assertNoOtherServicesOrScripts();
		RedisConnectionPool::$connection->result = [ 'ok', 0 ];
		$this->assertNull( $store->readState() );
		$args = RedisConnectionPool::$connection->calls[2][1];
		$this->assertSame( array_map( static fn ( string $key ): string => 'mw-cache:' . $key, $store->keyNames() ),
			array_slice( $args[1], 0, $args[2] ) );
		$this->assertSame( 'readState', $args[1][$args[2]] );
	}

	public function testOptionalSelectorUsesTheNamedCacheWithoutReadingMainCacheType(): void {
		MediaWikiServices::$values['FrauxSearchCoordinationCacheType'] = 'other-redis';
		unset( MediaWikiServices::$values['MainCacheType'] );
		MediaWikiServices::$values['ObjectCaches']['other-redis'] = [
			'class' => 'Wikimedia\\ObjectCache\\RedisBagOStuff', 'servers' => [ 'selected' => 'selected-redis:6380' ],
			'password' => [ 'worker', 'private-selected-password' ],
		];
		IndexCoordinatorFactory::createStore();
		$this->assertSame( 'selected-redis:6380', RedisConnectionPool::$server );
		$this->assertSame( [ 'worker', 'private-selected-password' ], RedisConnectionPool::$options['password'] );
		$this->assertNotContains( 'MainCacheType', MediaWikiServices::$requested );
		$this->assertNoOtherServicesOrScripts();
	}

	public function testExplicitRedisConfigurationTakesPrecedenceWithoutReadingCacheSettings(): void {
		MediaWikiServices::$values['FrauxSearchCoordinationRedis'] = [
			'server' => 'explicit-redis:6381', 'database' => 3, 'password' => 'private-explicit-password',
			'prefix' => 'explicit:',
		];
		unset( MediaWikiServices::$values['MainCacheType'], MediaWikiServices::$values['ObjectCaches'],
			MediaWikiServices::$values['FrauxSearchCoordinationCacheType'] );
		IndexCoordinatorFactory::createStore();
		$this->assertSame( 'explicit-redis:6381', RedisConnectionPool::$server );
		$this->assertSame( 'private-explicit-password', RedisConnectionPool::$options['password'] );
		$this->assertSame( [ 'select', [ 3 ] ], RedisConnectionPool::$connection->calls[1] );
		$this->assertNotContains( 'MainCacheType', MediaWikiServices::$requested );
		$this->assertNotContains( 'ObjectCaches', MediaWikiServices::$requested );
		$this->assertNoOtherServicesOrScripts();
	}

	public function testScopeSeparatesWikiPrefixAndIndexAndNormalizesEndpointTrailingSlash(): void {
		$keys = IndexCoordinatorFactory::createStore()->keyNames();
		MediaWikiServices::$values['FrauxSearchUrl'] = 'http://127.0.0.1:7700';
		$this->assertSame( $keys, IndexCoordinatorFactory::createStore()->keyNames() );
		$this->assertNotSame( $keys, IndexCoordinatorFactory::createStore( 'isolated' )->keyNames() );
		MediaWikiServices::$values['DBprefix'] = 'other_';
		$this->assertNotSame( $keys, IndexCoordinatorFactory::createStore()->keyNames() );
		MediaWikiServices::$values['DBprefix'] = 'local_';
		MediaWikiServices::$values['DBname'] = 'other_source';
		$this->assertNotSame( $keys, IndexCoordinatorFactory::createStore()->keyNames() );
		$this->assertSame( [], MediaWikiServices::$unexpectedCalls );
		$this->assertNotContains( 'luaEval', array_column( RedisConnectionPool::$connection->calls, 0 ) );
	}

	public function testObserverConnectionNeedsOnlyCacheConfiguration(): void {
		unset( MediaWikiServices::$values['DBname'], MediaWikiServices::$values['DBprefix'],
			MediaWikiServices::$values['FrauxSearchIndex'], MediaWikiServices::$values['FrauxSearchUrl'] );
		$connection = IndexCoordinatorFactory::createConnection();
		$this->assertNoOtherServicesOrScripts();
		$this->assertSame( 'cache-redis:6379', RedisConnectionPool::$server );
		$connection->evaluate( 'return KEYS', [ 'owned-key' ], [] );
		$this->assertSame( [ 'luaEval', [ 'return KEYS', [ 'mw-cache:owned-key' ], 1 ] ],
			RedisConnectionPool::$connection->calls[2] );
	}

	private function primaryDatabase( bool $transaction = false, ?RuntimeException $flushError = null ): object {
		return new class( $transaction, $flushError ) {
			public array $calls = [];
			public function __construct( private bool $transaction, private ?RuntimeException $flushError ) {
			}
			public function explicitTrxActive(): bool {
				$this->calls[] = 'explicitTrxActive';
				return $this->transaction;
			}
			public function flushSnapshot( string $caller ): void {
				$this->calls[] = [ 'flushSnapshot', $caller ];
				if ( $this->flushError !== null ) {
					throw $this->flushError;
				}
			}
		};
	}

	private function buildClosure( Closure $renderer ): Closure {
		$coordinator = IndexCoordinatorFactory::create( build: $renderer );
		$this->assertNoOtherServicesOrScripts();
		return ( new ReflectionProperty( $coordinator, 'build' ) )->getValue( $coordinator );
	}

	public static function renderedValues(): array {
		return [
			'complete document' => [ [
				'document' => [
					'id' => 9607, 'revision_id' => 42, 'title' => 'Example', 'redirects' => [ 'Alias' ],
					'namespace' => 0, 'incoming_links' => 3, 'outgoing_link_ids' => [ 12, 99 ],
					'boost' => 100, 'text' => 'Rendered source', 'timestamp' => '20260909000000',
					'word_count' => 2, 'byte_size' => 15, 'document_hash' => str_repeat( 'a', 64 ),
				],
				'is_redirect' => false, 'redirect_target_id' => null,
			] ],
			'missing source' => [ null ],
		];
	}

	#[DataProvider( 'renderedValues' )]
	public function testInjectedRendererRunsAfterSnapshotFlushAndForwardsItsValue( ?array $value ): void {
		$primary = $this->primaryDatabase();
		MediaWikiServices::$primaryDatabase = $primary;
		$renderedIds = [];
		$build = $this->buildClosure( function ( int $id ) use ( $primary, $value, &$renderedIds ): ?array {
			$this->assertSame( [ 'explicitTrxActive', [ 'flushSnapshot', IndexCoordinatorFactory::class . '::create' ] ],
				$primary->calls );
			$renderedIds[] = $id;
			return $value;
		} );
		$this->assertSame( [], $primary->calls );
		$this->assertSame( [], $renderedIds );
		$this->assertSame( $value, $build( 9607 ) );
		$this->assertSame( [ 9607 ], $renderedIds );
		$this->assertNoOtherServicesOrScripts();
	}

	public static function failedSourceGuards(): array {
		return [ 'active transaction' => [ true ], 'snapshot failure' => [ false ] ];
	}

	#[DataProvider( 'failedSourceGuards' )]
	public function testSourceGuardFailureNeverCallsInjectedRenderer( bool $activeTransaction ): void {
		$flushError = $activeTransaction ? null : new RuntimeException( 'Snapshot failed.' );
		$primary = $this->primaryDatabase( $activeTransaction, $flushError );
		MediaWikiServices::$primaryDatabase = $primary;
		$called = false;
		$build = $this->buildClosure( static function ( int $id ) use ( &$called ): ?array {
			$called = true;
			return null;
		} );
		try {
			$build( 9607 );
			$this->fail( 'Source guards must fail before invoking the renderer.' );
		} catch ( RuntimeException $error ) {
			if ( $activeTransaction ) {
				$this->assertSame( 'FrauxSearch refresh must run after the source transaction commits.', $error->getMessage() );
				$this->assertSame( [ 'explicitTrxActive' ], $primary->calls );
			} else {
				$this->assertSame( $flushError, $error );
				$this->assertSame( [ 'explicitTrxActive', [ 'flushSnapshot', IndexCoordinatorFactory::class . '::create' ] ],
					$primary->calls );
			}
		}
		$this->assertFalse( $called );
		$this->assertNoOtherServicesOrScripts();
	}

	public static function heartbeatResults(): array {
		return [ 'lease renewed' => [ false ], 'lease lost' => [ true ] ];
	}

	#[DataProvider( 'heartbeatResults' )]
	public function testInjectedRendererHeartbeatRenewsTheCoordinatorsLeaseAndPropagatesLoss( bool $loseLease ): void {
		$primary = $this->primaryDatabase();
		MediaWikiServices::$primaryDatabase = $primary;
		$entered = false;
		$completed = false;
		$coordinator = IndexCoordinatorFactory::create( build:
			function ( int $id, Closure $heartbeat ) use ( $primary, &$entered, &$completed ): ?array {
				$this->assertSame( 9607, $id );
				$this->assertSame( [ 'explicitTrxActive', [ 'flushSnapshot', IndexCoordinatorFactory::class . '::create' ] ],
					$primary->calls );
				$entered = true;
				$heartbeat();
				$completed = true;
				return null;
			}
		);
		$this->assertNoOtherServicesOrScripts();
		$store = ( new ReflectionProperty( $coordinator, 'store' ) )->getValue( $coordinator );
		$build = ( new ReflectionProperty( $coordinator, 'build' ) )->getValue( $coordinator );
		$connection = RedisConnectionPool::$connection;
		$connection->result = [ 'ok', 1 ];
		$this->assertTrue( $store->lock() );
		$connection->result = $loseLease ? [ 'lost' ] : [ 'ok', 1 ];
		try {
			$this->assertNull( $build( 9607 ) );
			$this->assertFalse( $loseLease, 'A failed heartbeat must stop the renderer.' );
		} catch ( RuntimeException $error ) {
			$this->assertTrue( $loseLease );
			$this->assertSame( 'FrauxSearch Redis writer lost its lease.', $error->getMessage() );
		}
		$this->assertTrue( $entered );
		$this->assertSame( !$loseLease, $completed );
		$this->assertSame( [ 'setOption', 'select', 'luaEval', 'luaEval' ], array_column( $connection->calls, 0 ) );
		[ $lockScript, $lockParams, $keyCount ] = $connection->calls[2][1];
		[ $renewScript, $renewParams, $renewKeyCount ] = $connection->calls[3][1];
		$this->assertSame( $lockScript, $renewScript );
		$this->assertSame( $keyCount, $renewKeyCount );
		$this->assertSame( array_map( static fn ( string $key ): string => 'mw-cache:' . $key, $store->keyNames() ),
			array_slice( $renewParams, 0, $renewKeyCount ) );
		$this->assertSame( 'lock', $lockParams[$keyCount] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $lockParams[$keyCount + 1] );
		$this->assertSame( [ 'assert', $lockParams[$keyCount + 1], $lockParams[$keyCount + 2] ],
			array_slice( $renewParams, $renewKeyCount ) );
		if ( $loseLease ) {
			$connection->result = [ 'ok', 1 ];
			try {
				$store->assertLocked();
				$this->fail( 'Heartbeat failure must mark the coordinator store as having lost its lease.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'FrauxSearch Redis writer lease is not held or was lost.', $error->getMessage() );
			}
			$this->assertCount( 4, $connection->calls );
		}
		$this->assertSame( [], MediaWikiServices::$unexpectedCalls );
	}

	public static function invalidSelections(): array {
		return [
			'none has no automatic fallback' => [ [ 'MainCacheType' => 0 ] ],
			'any cache has no automatic fallback' => [ [ 'MainCacheType' => -1 ] ],
			'missing named cache' => [ [ 'FrauxSearchCoordinationCacheType' => 'missing' ] ],
			'boolean selector' => [ [ 'FrauxSearchCoordinationCacheType' => false ] ],
			'array selector' => [ [ 'FrauxSearchCoordinationCacheType' => [] ] ],
			'missing cache map' => [ [ 'ObjectCaches' => null ] ],
			'malformed entry' => [ [ 'ObjectCaches' => [ 'redis' => 'redis' ] ] ],
			'SQL cache' => [ [ 'ObjectCaches' => [ 'redis' => [ 'class' => 'MediaWiki\\ObjectCache\\SqlBagOStuff' ] ] ] ],
			'multiple servers' => [ [ 'ObjectCaches' => [ 'redis' => [
				'class' => 'RedisBagOStuff', 'servers' => [ 'one', 'two' ],
			] ] ] ],
			'empty explicit config cannot fall back' => [ [ 'FrauxSearchCoordinationRedis' => [] ] ],
			'false explicit config cannot fall back' => [ [ 'FrauxSearchCoordinationRedis' => false ] ],
		];
	}

	#[DataProvider( 'invalidSelections' )]
	public function testInvalidSelectionNeverOpensRedisOrFallsBackToSql( array $changes ): void {
		MediaWikiServices::$values = array_replace( MediaWikiServices::$values, $changes );
		try {
			IndexCoordinatorFactory::createStore();
			$this->fail( 'Invalid indexing cache must fail before connecting.' );
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			$this->assertSame( [], MediaWikiServices::$unexpectedCalls );
			$this->assertSame( [], RedisConnectionPool::$options );
			$this->assertNull( RedisConnectionPool::$server );
			$this->assertSame( [], RedisConnectionPool::$connection->calls );
			$this->assertStringNotContainsString( 'private-', $error->getMessage() );
		}
	}
}

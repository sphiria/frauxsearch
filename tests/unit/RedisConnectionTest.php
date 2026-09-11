<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RedisConnection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Wikimedia\ObjectCache\RedisConnectionPool;
use Wikimedia\ObjectCache\RedisConnRef;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class RedisConnectionTest extends TestCase {
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/redisConnection.php';
		RedisConnectionPool::$connection = new RedisConnRef();
	}

	private function config(): array {
		return [ 'server' => 'private-endpoint:6379', 'database' => 2, 'password' => 'private-password' ];
	}

	public function testUsesExplicitSafePoolOptionsAndSelectsBeforeScripts(): void {
		$connection = RedisConnection::connect( $this->config() );
		$this->assertSame( [
			'connectTimeout' => 1, 'readTimeout' => 5, 'persistent' => false,
			'serializer' => 'none', 'prefix' => null, 'password' => 'private-password',
		], RedisConnectionPool::$options );
		$this->assertSame( 'private-endpoint:6379', RedisConnectionPool::$server );
		$this->assertSame( [ 'ok' ], $connection->evaluate( 'return ARGV', [ 'first', 'second' ], [ 'value', 3 ] ) );
		$this->assertSame( [
			[ 'setOption', [ Redis::OPT_MAX_RETRIES, 0 ] ],
			[ 'select', [ 2 ] ],
			[ 'luaEval', [ 'return ARGV', [ 'first', 'second', 'value', 3 ], 2 ] ],
		], RedisConnectionPool::$connection->calls );
	}

	public static function endpoints(): array {
		return [ [ '/run/redis/coordination.sock' ], [ 'redis' ], [ 'redis.example:6380' ],
			[ '[::1]:6379' ], [ 'tls://redis.example:6380' ] ];
	}

	#[DataProvider( 'endpoints' )]
	public function testPassesSupportedEndpointsAndExplicitAuthentication( string $server ): void {
		$config = array_replace( $this->config(), [
			'server' => $server, 'password' => [ 'coordination', 'private-password' ],
			'connectTimeout' => 0.5, 'readTimeout' => 2,
		] );
		RedisConnection::connect( $config );
		$this->assertSame( $server, RedisConnectionPool::$server );
		$this->assertSame( $config['password'], RedisConnectionPool::$options['password'] );
		$this->assertSame( 0.5, RedisConnectionPool::$options['connectTimeout'] );
	}

	public function testAllowsExplicitUnauthenticatedDedicatedSocket(): void {
		RedisConnection::connect( array_replace( $this->config(), [ 'password' => null ] ) );
		$this->assertNull( RedisConnectionPool::$options['password'] );
	}

	public static function objectCacheClasses(): array {
		return [ [ 'Wikimedia\\ObjectCache\\RedisBagOStuff' ], [ 'RedisBagOStuff' ],
			[ '\\Wikimedia\\ObjectCache\\RedisBagOStuff' ] ];
	}

	#[DataProvider( 'objectCacheClasses' )]
	public function testResolvesAnOrdinaryRedisObjectCacheWithoutOpeningIt( string $class ): void {
		$config = RedisConnection::configFromObjectCache( [
			'class' => $class, 'servers' => [ 'cache-redis:6379' ],
		] );
		$this->assertSame( [
			'server' => 'cache-redis:6379', 'database' => 0, 'password' => null, 'prefix' => '',
		], $config );
		$this->assertSame( [], RedisConnectionPool::$options );
		$this->assertNull( RedisConnectionPool::$server );
		RedisConnection::connect( $config );
		$this->assertNull( RedisConnectionPool::$options['password'] );
		$this->assertSame( [ 'select', [ 0 ] ], RedisConnectionPool::$connection->calls[1] );
	}

	public function testReusesTaggedCacheEndpointAuthenticationAndPrefixOnlyOnKeys(): void {
		$config = RedisConnection::configFromObjectCache( [
			'class' => 'RedisBagOStuff', 'servers' => [ 'local' => 'cache-redis:6380' ],
			'password' => [ 'cache-user', 'private-password' ], 'prefix' => 'cache:',
			'connectTimeout' => 0.5, 'persistent' => true, 'automaticFailover' => true,
			'keyspace' => 'wiki', 'loggroup' => 'objectcache',
		] );
		$connection = RedisConnection::connect( $config );
		$this->assertSame( 'cache-redis:6380', RedisConnectionPool::$server );
		$this->assertSame( [ 'cache-user', 'private-password' ], RedisConnectionPool::$options['password'] );
		$this->assertSame( 0.5, RedisConnectionPool::$options['connectTimeout'] );
		$this->assertFalse( RedisConnectionPool::$options['persistent'] );
		$this->assertNull( RedisConnectionPool::$options['prefix'], 'Avoid a second implicit Lua key prefix.' );
		$connection->evaluate( 'return KEYS', [ 'lease', 'meta' ], [ 'lease', 1 ] );
		$this->assertSame( [ 'luaEval', [
			'return KEYS', [ 'cache:lease', 'cache:meta', 'lease', 1 ], 2,
		] ], RedisConnectionPool::$connection->calls[2] );
	}

	public function testExplicitConnectionAlsoSupportsAnOptionalPrefix(): void {
		$connection = RedisConnection::connect( $this->config() + [ 'prefix' => 'explicit:' ] );
		$connection->evaluate( 'return KEYS', [ 'key' ], [] );
		$this->assertSame( [ 'luaEval', [ 'return KEYS', [ 'explicit:key' ], 1 ] ],
			RedisConnectionPool::$connection->calls[2] );
	}

	public static function invalidObjectCaches(): array {
		return [
			'non-Redis cache' => [ [ 'class' => 'MediaWiki\\ObjectCache\\SqlBagOStuff' ] ],
			'custom subclass' => [ [ 'class' => 'CustomRedisBagOStuff' ] ],
			'malformed class' => [ [ 'class' => [] ] ],
			'cache factory' => [ [ 'factory' => 'untrustedFactory' ] ],
			'empty endpoint list' => [ [ 'servers' => [] ] ],
			'sharded endpoint list' => [ [ 'servers' => [ 'one:6379', 'two:6379' ] ] ],
			'tagged multiple endpoints' => [ [ 'servers' => [ 'first' => 'one:6379', 'second' => 'two:6379' ] ] ],
			'non-array endpoints' => [ [ 'servers' => 'one:6379' ] ],
			'invalid endpoint' => [ [ 'servers' => [ 'redis://user:private-password@host' ] ] ],
			'invalid authentication' => [ [ 'password' => [ 'user' ] ] ],
			'invalid prefix' => [ [ 'prefix' => [] ] ],
			'invalid timeout' => [ [ 'connectTimeout' => 0 ] ],
			'unsupported database selection' => [ [ 'database' => 1 ] ],
			'unsupported nested connection config' => [ [ 'redisConfig' => [ 'password' => 'private-password' ] ] ],
			'nonboolean persistence' => [ [ 'persistent' => 'true' ] ],
			'nonboolean failover' => [ [ 'automaticFailover' => 'false' ] ],
		];
	}

	#[DataProvider( 'invalidObjectCaches' )]
	public function testRejectsUnsupportedObjectCachesBeforeConnecting( array $changes ): void {
		try {
			RedisConnection::configFromObjectCache( array_replace( [
				'class' => 'RedisBagOStuff', 'servers' => [ 'cache-redis:6379' ],
			], $changes ) );
			$this->fail( 'Unsupported cache configuration must not resolve.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertSame( [], RedisConnectionPool::$options );
			$this->assertNull( RedisConnectionPool::$server );
			$this->assertStringNotContainsString( 'private-password', $error->getMessage() );
		}
	}

	public static function invalidConfig(): array {
		return [
			[ 'server', null ], [ 'server', '' ], [ 'server', 'redis:0' ], [ 'server', 'redis:65536' ],
			[ 'server', 'redis://user:private-password@redis:6379' ], [ 'server', "redis\n:6379" ],
			[ 'server', '[not-ip]:6379' ], [ 'server', '/' ], [ 'database', '0' ], [ 'database', -1 ],
			[ 'database', null ], [ 'password', false ], [ 'password', '' ], [ 'password', [ 'user' ] ],
			[ 'password', [ 'username' => 'u', 'password' => 'p' ] ], [ 'password', [ 'u', '' ] ],
			[ 'connectTimeout', 0 ], [ 'connectTimeout', 31 ], [ 'readTimeout', 61 ],
			[ 'readTimeout', INF ], [ 'readTimeout', NAN ], [ 'readTimeout', '5' ],
			[ 'readTimeout', null ], [ 'persistent', true ], [ 'prefix', false ], [ 'prefix', [] ],
		];
	}

	#[DataProvider( 'invalidConfig' )]
	public function testRejectsInvalidConfigBeforeConnecting( string $key, mixed $value ): void {
		try {
			RedisConnection::connect( array_replace( $this->config(), [ $key => $value ] ) );
			$this->fail( 'Invalid configuration must fail before connecting.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertNull( RedisConnectionPool::$server );
			$this->assertStringNotContainsString( 'private-password', $error->getMessage() );
		}
	}

	public function testRequiresEveryExplicitConnectionSetting(): void {
		foreach ( [ 'server', 'database', 'password' ] as $key ) {
			$config = $this->config();
			unset( $config[$key] );
			try {
				RedisConnection::connect( $config );
				$this->fail( 'Missing configuration must fail.' );
			} catch ( InvalidArgumentException ) {
				$this->assertNull( RedisConnectionPool::$server );
			}
		}
	}

	public static function connectFailures(): array {
		return [ [ 'unavailable' ], [ 'exception' ], [ 'option' ], [ 'selection' ], [ 'selection-error' ],
			[ 'setOption-throw' ], [ 'select-throw' ] ];
	}

	#[DataProvider( 'connectFailures' )]
	public function testConnectFailuresAreSanitizedAndNeverExecuteScripts( string $failure ): void {
		$native = RedisConnectionPool::$connection;
		if ( $failure === 'unavailable' ) { RedisConnectionPool::$connection = null; }
		if ( $failure === 'exception' ) { RedisConnectionPool::$fail = true; }
		if ( $failure === 'option' ) { $native->optionResult = false; }
		if ( $failure === 'selection' ) { $native->selectResult = false; }
		if ( $failure === 'selection-error' ) { $native->errorOnSelect = 'private-password'; }
		if ( str_ends_with( $failure, '-throw' ) ) { $native->throwOn = substr( $failure, 0, -6 ); }
		try {
			RedisConnection::connect( $this->config() );
			$this->fail( 'Expected connection failure.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'FrauxSearch could not connect to coordination Redis.', $error->getMessage() );
			$this->assertNull( $error->getPrevious() );
			$this->assertNotContains( 'luaEval', array_column( $native->calls, 0 ) );
			if ( $failure === 'option' ) { $this->assertCount( 1, $native->calls ); }
		}
	}

	public static function evaluationFailures(): array {
		return [ [ 'exception' ], [ 'error-reply' ], [ 'nil' ], [ 'false' ] ];
	}

	#[DataProvider( 'evaluationFailures' )]
	public function testAmbiguousEvaluationCannotBeRetriedOnSameAdapter( string $failure ): void {
		$connection = RedisConnection::connect( $this->config() );
		$native = RedisConnectionPool::$connection;
		if ( $failure === 'exception' ) { $native->throwOn = 'luaEval'; }
		if ( $failure === 'error-reply' ) { $native->errorOnEval = 'private-password'; }
		if ( $failure === 'nil' ) { $native->result = null; }
		if ( $failure === 'false' ) { $native->result = false; }
		try {
			$connection->evaluate( 'return 1', [ 'key' ], [] );
			$this->fail( 'Expected evaluation failure.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'outcome may be unknown', $error->getMessage() );
			$this->assertStringNotContainsString( 'private-', $error->getMessage() );
			$this->assertNull( $error->getPrevious() );
		}
		try {
			$connection->evaluate( 'return 1', [ 'key' ], [] );
			$this->fail( 'Uncertain operations must not be retried.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'unresolved failure', $error->getMessage() );
			$this->assertSame( [ 'setOption', 'select', 'luaEval' ], array_column( $native->calls, 0 ) );
		}
	}

	public function testZeroIsAValidExplicitScriptStatusAndStaleErrorsAreCleared(): void {
		$connection = RedisConnection::connect( $this->config() );
		$native = RedisConnectionPool::$connection;
		$native->error = 'previous error';
		$native->result = 0;
		$this->assertSame( 0, $connection->evaluate( 'return 0', [], [] ) );
		$native->result = '0';
		$this->assertSame( '0', $connection->evaluate( 'return "0"', [], [] ) );
	}

	public function testInvalidArgumentsNeverReachRedisOrPoisonTheConnection(): void {
		$connection = RedisConnection::connect( $this->config() );
		foreach ( [ [ '', [], [] ], [ 'return 1', [ '' ], [] ], [ 'return 1', [], [ null ] ],
			[ 'return 1', [ 'key' => 'value' ], [] ], [ 'return 1', [], [ 'arg' => 'value' ] ] ] as $args
		) {
			try {
				$connection->evaluate( ...$args );
				$this->fail( 'Expected local argument failure.' );
			} catch ( InvalidArgumentException ) {
				$this->assertCount( 2, RedisConnectionPool::$connection->calls );
			}
		}
		$this->assertSame( [ 'ok' ], $connection->evaluate( 'return 1', [], [] ) );
	}
}

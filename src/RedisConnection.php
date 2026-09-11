<?php

namespace FrauxSearch;

use InvalidArgumentException;
use Redis;
use RuntimeException;
use Throwable;
use Wikimedia\ObjectCache\RedisBagOStuff;
use Wikimedia\ObjectCache\RedisConnectionPool;
use Wikimedia\ObjectCache\RedisConnRef;

final class RedisConnection {
	private bool $failed = false;

	private function __construct( private RedisConnRef $connection, private string $keyPrefix = '' ) {
	}

	public static function configFromObjectCache( #[\SensitiveParameter] array $cache ): array {
		$allowed = [ 'class', 'servers', 'connectTimeout', 'password', 'prefix', 'persistent',
			'automaticFailover', 'keyspace', 'loggroup', 'logger', 'asyncHandler', 'reportDupes', 'stats', 'telemetry' ];
		$class = $cache['class'] ?? null;
		$servers = $cache['servers'] ?? null;
		if ( array_diff( array_keys( $cache ), $allowed ) || !is_string( $class )
			|| !in_array( strtolower( ltrim( $class, '\\' ) ), [
				strtolower( RedisBagOStuff::class ), 'redisbagostuff',
			], true )
			|| !is_array( $servers ) || count( $servers ) !== 1
		) {
			throw new InvalidArgumentException( 'FrauxSearch requires a RedisBagOStuff cache with exactly one server and supported settings.' );
		}
		foreach ( [ 'persistent', 'automaticFailover' ] as $option ) {
			if ( isset( $cache[$option] ) && !is_bool( $cache[$option] ) ) {
				throw new InvalidArgumentException( 'Invalid FrauxSearch Redis object-cache configuration.' );
			}
		}
		$config = [
			'server' => array_values( $servers )[0],
			'database' => 0,
			'password' => $cache['password'] ?? null,
			'prefix' => $cache['prefix'] ?? '',
		];
		if ( isset( $cache['connectTimeout'] ) ) {
			$config['connectTimeout'] = $cache['connectTimeout'];
		}
		self::validateConfig( $config );
		return $config;
	}

	public static function connect( #[\SensitiveParameter] array $config ): self {
		self::validateConfig( $config );
		if ( !class_exists( Redis::class ) || !class_exists( RedisConnectionPool::class ) ) {
			throw new RuntimeException( 'FrauxSearch coordination requires the MediaWiki Redis client and phpredis.' );
		}
		try {
			$pool = RedisConnectionPool::singleton( [
				'connectTimeout' => $config['connectTimeout'] ?? 1,
				'readTimeout' => $config['readTimeout'] ?? 5,
				'persistent' => false,
				'serializer' => 'none',
				'prefix' => null,
				'password' => $config['password'],
			] );
			$connection = $pool->getConnection( $config['server'] );
			if ( !$connection instanceof RedisConnRef ) {
				throw new RuntimeException();
			}
			$connection->clearLastError();
			if ( $connection->setOption( Redis::OPT_MAX_RETRIES, 0 ) !== true
				|| $connection->getLastError() !== null
			) {
				throw new RuntimeException();
			}
			if ( $connection->select( $config['database'] ) !== true || $connection->getLastError() !== null ) {
				throw new RuntimeException();
			}
			return new self( $connection, $config['prefix'] ?? '' );
		} catch ( Throwable ) {
			throw new RuntimeException( 'FrauxSearch could not connect to coordination Redis.' );
		}
	}

	/**
	 * @param string[] $keys
	 * @param array<string|int> $args
	 */
	public function evaluate( string $script, array $keys, array $args ): mixed {
		if ( $this->failed ) {
			throw new RuntimeException( 'FrauxSearch coordination Redis connection has an unresolved failure.' );
		}
		if ( trim( $script ) === '' || !array_is_list( $keys ) || !array_is_list( $args ) ) {
			throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis script arguments.' );
		}
		foreach ( $keys as $key ) {
			if ( !is_string( $key ) || $key === '' ) {
				throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis script key.' );
			}
		}
		foreach ( $args as $arg ) {
			if ( !is_string( $arg ) && !is_int( $arg ) ) {
				throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis script argument.' );
			}
		}
		try {
			$this->connection->clearLastError();
			$keys = array_map( fn ( string $key ): string => $this->keyPrefix . $key, $keys );
			$result = $this->connection->luaEval( $script, [ ...$keys, ...$args ], count( $keys ) );
			if ( $this->connection->getLastError() !== null || $result === false || $result === null ) {
				throw new RuntimeException();
			}
			return $result;
		} catch ( Throwable ) {
			$this->failed = true;
			throw new RuntimeException( 'FrauxSearch coordination Redis operation failed; its outcome may be unknown.' );
		}
	}

	private static function validateConfig( #[\SensitiveParameter] array $config ): void {
		$allowed = [ 'server', 'database', 'password', 'connectTimeout', 'readTimeout', 'prefix' ];
		if ( array_diff( array_keys( $config ), $allowed )
			|| !isset( $config['server'] ) || !isset( $config['database'] ) || !array_key_exists( 'password', $config )
			|| !is_string( $config['server'] ) || !self::validServer( $config['server'] )
			|| !is_int( $config['database'] ) || $config['database'] < 0
		) {
			throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis configuration.' );
		}
		if ( isset( $config['prefix'] ) && !is_string( $config['prefix'] ) ) {
			throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis key prefix.' );
		}
		$password = $config['password'];
		if ( $password !== null && !( is_string( $password ) && $password !== '' )
			&& !( is_array( $password ) && array_is_list( $password ) && count( $password ) === 2
				&& is_string( $password[0] ) && $password[0] !== ''
				&& is_string( $password[1] ) && $password[1] !== '' )
		) {
			throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis authentication configuration.' );
		}
		foreach ( [ 'connectTimeout' => 30, 'readTimeout' => 60 ] as $option => $maximum ) {
			if ( !array_key_exists( $option, $config ) ) { continue; }
			$value = $config[$option];
			if ( !( is_int( $value ) || is_float( $value ) ) || !is_finite( $value )
				|| $value < 0.1 || $value > $maximum
			) {
				throw new InvalidArgumentException( 'Invalid FrauxSearch coordination Redis timeout configuration.' );
			}
		}
	}

	private static function validServer( string $server ): bool {
		if ( $server === '' || preg_match( '/[\x00-\x20\x7f]/', $server ) ) { return false; }
		if ( str_starts_with( $server, '/' ) ) { return strlen( $server ) > 1; }
		if ( preg_match( '/^\[([^]]+)\]:([0-9]+)$/D', $server, $parts ) ) {
			return filter_var( $parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false
				&& (int)$parts[2] > 0 && (int)$parts[2] <= 65535;
		}
		return preg_match( '/^(?:tls:\/\/)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::([0-9]+))?$/iD', $server, $parts ) === 1
			&& ( !isset( $parts[1] ) || ( (int)$parts[1] > 0 && (int)$parts[1] <= 65535 ) );
	}
}

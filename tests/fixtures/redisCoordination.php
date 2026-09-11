<?php

namespace FrauxSearch\Tests;

use RuntimeException;
use Throwable;

class RedisCoordinationFixture {
	private string $directory;
	private $process = null;
	private array $connections = [];

	public function __construct() {
		$this->directory = sys_get_temp_dir() . '/frauxsearch-redis-' . bin2hex( random_bytes( 8 ) );
		if ( !mkdir( $this->directory, 0700 ) ) { throw new RuntimeException( 'Cannot create Redis fixture directory.' ); }
		try {
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open -- Owned CLI test server, without a command shell.
			$this->process = proc_open( [
				'redis-server', '--port', '0', '--unixsocket', $this->directory . '/redis.sock',
				'--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--daemonize', 'no',
				'--maxmemory-policy', 'noeviction', '--dir', $this->directory,
			], [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $this->directory . '/redis.log', 'a' ],
				2 => [ 'file', $this->directory . '/redis.log', 'a' ] ], $pipes );
			$deadline = hrtime( true ) + 5_000_000_000;
			do {
				// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource -- proc_open returns a resource.
				if ( !is_resource( $this->process ) || !proc_get_status( $this->process )['running'] ) { break; }
				$stream = @stream_socket_client( 'unix://' . $this->directory . '/redis.sock', $errno, $error, 0.1 );
				if ( $stream !== false ) {
					fclose( $stream );
					register_shutdown_function( $this->stop( ... ) );
					return;
				}
				usleep( 10000 );
			} while ( hrtime( true ) < $deadline );
			throw new RuntimeException( 'Redis fixture failed to start; install redis-server for the unit suite. '
				. file_get_contents( $this->directory . '/redis.log' ) );
		} catch ( Throwable $e ) { $this->stop(); throw $e; }
	}

	public function connect(): RedisCoordinationTestConnection {
		$connection = new RedisCoordinationTestConnection( $this->directory . '/redis.sock' );
		$this->connections[] = $connection;
		return $connection;
	}

	public function stop(): void {
		foreach ( $this->connections as $connection ) { $connection->close(); }
		$this->connections = [];
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource -- proc_open returns a resource.
		if ( is_resource( $this->process ) ) { proc_terminate( $this->process ); proc_close( $this->process ); }
		$this->process = null;
		foreach ( [ 'redis.sock', 'redis.log' ] as $name ) {
			$path = $this->directory . '/' . $name;
			if ( file_exists( $path ) ) { unlink( $path ); }
		}
		if ( is_dir( $this->directory ) ) { rmdir( $this->directory ); }
	}
}

class RedisCoordinationTestConnection {
	private $stream;
	public array $evaluations = [];

	public function __construct( string $socket ) {
		$this->stream = stream_socket_client( 'unix://' . $socket, $errno, $error, 2 );
		if ( $this->stream === false ) { throw new RuntimeException( 'Cannot connect to Redis fixture.' ); }
		stream_set_timeout( $this->stream, 5 );
	}

	public function close(): void {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource -- Native PHP socket stream.
		if ( is_resource( $this->stream ) ) { fclose( $this->stream ); }
	}

	public function evaluate( string $script, array $keys, array $args ): mixed {
		$result = $this->command( [ 'EVAL', $script, count( $keys ), ...$keys, ...$args ] );
		$this->evaluations[] = [ 'operation' => $args[0], 'args' => $args, 'result' => $result ];
		return $result;
	}

	public function command( array $args ): mixed {
		$request = '*' . count( $args ) . "\r\n";
		foreach ( $args as $arg ) { $arg = (string)$arg; $request .= '$' . strlen( $arg ) . "\r\n" . $arg . "\r\n"; }
		$offset = 0;
		while ( $offset < strlen( $request ) ) {
			$written = fwrite( $this->stream, substr( $request, $offset ) );
			if ( !$written ) { throw new RuntimeException( 'Redis fixture write failed.' ); }
			$offset += $written;
		}
		return $this->response();
	}

	private function response(): mixed {
		$line = fgets( $this->stream );
		if ( $line === false || !str_ends_with( $line, "\r\n" ) ) {
			throw new RuntimeException( 'Redis fixture response ended unexpectedly.' );
		}
		$value = substr( $line, 1, -2 );
		switch ( $line[0] ) {
			case '+': return $value;
			case '-': throw new RuntimeException( 'Redis fixture error: ' . $value );
			case ':': return (int)$value;
			case '$':
				if ( $value === '-1' ) { return null; }
				$remaining = (int)$value + 2;
				$result = '';
				while ( $remaining > 0 ) {
					$part = fread( $this->stream, $remaining );
					if ( $part === false || $part === '' ) { throw new RuntimeException( 'Redis fixture response truncated.' ); }
					$result .= $part;
					$remaining -= strlen( $part );
				}
				return substr( $result, 0, -2 );
			case '*':
				if ( $value === '-1' ) { return null; }
				$items = [];
				for ( $i = 0; $i < (int)$value; $i++ ) { $items[] = $this->response(); }
				return $items;
		}
		throw new RuntimeException( 'Unknown Redis fixture response type.' );
	}
}

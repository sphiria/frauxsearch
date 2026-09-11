<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;
use Closure;
use Generator;
use RuntimeException;
use Throwable;

class DocumentBatchBuilder {
	private const MAX_PAGES = 100;
	private const MAX_BYTES = 67108864;

	public static function assertAvailable( array $maintenanceParameters = [] ): void {
		if ( PHP_SAPI !== 'cli' || !function_exists( 'proc_open' ) || !is_executable( PHP_BINARY ) ) {
			throw new RuntimeException( 'Isolated document rendering requires CLI PHP and proc_open.' );
		}
		self::parameters( $maintenanceParameters );
		self::workers( $maintenanceParameters );
		[ $path, $handle ] = self::newResultFile();
		fclose( $handle );
		if ( !unlink( $path ) ) {
			throw new RuntimeException( 'Unable to remove the document renderer temporary file.' );
		}
	}

	public static function build(
		array $pageIds, array $maintenanceParameters = [], ?Closure $heartbeat = null, ?Closure $onBatch = null
	): array {
		$pageIds = self::validateIds( $pageIds );
		if ( $pageIds === [] ) { return []; }
		self::assertAvailable( $maintenanceParameters );
		$parameters = self::parameters( $maintenanceParameters );
		$source = self::sourceIdentity();
		$workers = self::workers( $maintenanceParameters );
		$chunkSize = min( self::MAX_PAGES, (int)ceil( count( $pageIds ) / $workers ) );
		$chunks = array_chunk( $pageIds, $chunkSize );
		$active = [];
		$next = 0;
		$results = [];
		$fill = static function () use ( &$active, &$next, $chunks, $workers, $parameters, $source, $heartbeat ): void {
			while ( count( $active ) < $workers && $next < count( $chunks ) ) {
				$worker = self::buildChunk( $chunks[$next], $parameters, $source, $heartbeat );
				$active[$next++] = $worker;
				$worker->current();
			}
		};
		try {
			while ( $next < count( $chunks ) || $active !== [] ) {
				$fill();
				$ready = [];
				foreach ( $active as $key => $worker ) {
					$worker->next();
					if ( !$worker->valid() ) {
						$batch = $worker->getReturn();
						$results += $batch;
						$ready += $batch;
						unset( $active[$key] );
					}
				}
				$fill();
				if ( $ready !== [] ) { $onBatch?->__invoke( $ready ); }
				if ( $active !== [] ) { usleep( 100000 ); }
			}
		} finally {
			foreach ( $active as $worker ) {
				try {
					$worker->throw( new RuntimeException( 'Document rendering cancelled.' ) );
				} catch ( Throwable ) {
				}
			}
		}
		$ordered = [];
		foreach ( $pageIds as $id ) { $ordered[$id] = $results[$id]; }
		return $ordered;
	}

	/** @return Generator<int, null, mixed, array> */
	private static function buildChunk( array $ids, array $parameters, string $source, ?Closure $heartbeat ): Generator {
		[ $path, $handle ] = self::newResultFile();
		$completed = false;
		try {
			$request = [ 'version' => 1, 'source' => $source, 'ids' => $ids,
				'nonce' => bin2hex( random_bytes( 16 ) ), 'result' => $path ];
			yield from self::execute( $parameters, $request, $heartbeat );
			$stat = fstat( $handle );
			$size = $stat === false ? 0 : $stat['size'];
			if ( $size <= 0 || $size > self::MAX_BYTES ) {
				throw new RuntimeException( 'Invalid document renderer result size; reduce the maintenance batch size.' );
			}
			rewind( $handle );
			$json = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( $json === false || strlen( $json ) !== $size ) {
				throw new RuntimeException( 'Incomplete document renderer result.' );
			}
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			$result = self::validateResult( $data, $request );
			$completed = true;
			return $result;
		} finally {
			fclose( $handle );
			if ( !unlink( $path ) && $completed ) {
				throw new RuntimeException( 'Unable to remove the document renderer temporary file.' );
			}
		}
	}

	private static function workers( array $options ): int {
		$workers = filter_var( $options['render-workers'] ?? 1, FILTER_VALIDATE_INT );
		if ( $workers === false || $workers < 1 || $workers > 8 ) {
			throw new RuntimeException( '--render-workers must be an integer between 1 and 8.' );
		}
		return $workers;
	}

	/** @return Generator<int, null, mixed, void> */
	private static function execute( array $parameters, array $request, ?Closure $heartbeat ): Generator {
		$remaining = json_encode( $request, JSON_THROW_ON_ERROR );
		$heartbeat?->__invoke();
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open -- Shell::execute cannot renew the parent lease.
		$process = proc_open( [ PHP_BINARY, '-d', 'memory_limit=' . ini_get( 'memory_limit' ),
			MW_INSTALL_PATH . '/maintenance/run.php',
			dirname( __DIR__ ) . '/maintenance/renderFrauxSearchBatch.php', ...$parameters ], [
			0 => [ 'pipe', 'r' ],
			1 => [ 'file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w' ],
			2 => [ 'pipe', 'w' ],
		], $pipes );
		if ( $process === false ) { throw new RuntimeException( 'Unable to start document renderer.' ); }
		$input = $pipes[0];
		$errorOutput = $pipes[2];
		$stderr = '';
		try {
			stream_set_blocking( $input, false );
			stream_set_blocking( $errorOutput, false );
			$nextHeartbeat = hrtime( true ) + 5000000000;
			while ( true ) {
				$status = proc_get_status( $process );
				$output = stream_get_contents( $errorOutput, 65536 );
				if ( $output !== false && strlen( $stderr ) < 65536 ) {
					$stderr .= substr( $output, 0, 65536 - strlen( $stderr ) );
				}
				if ( !$status['running'] ) { break; }
				if ( $input !== null ) {
					$written = fwrite( $input, $remaining );
					if ( $written === false ) { throw new RuntimeException( 'Unable to send renderer request.' ); }
					$remaining = substr( $remaining, $written );
					if ( $remaining === '' ) { fclose( $input ); $input = null; }
				}
				if ( hrtime( true ) >= $nextHeartbeat ) {
					$heartbeat?->__invoke();
					$nextHeartbeat = hrtime( true ) + 5000000000;
				}
				yield;
			}
			$exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
			if ( $input !== null ) { fclose( $input ); $input = null; }
			fclose( $errorOutput );
			$errorOutput = null;
			proc_close( $process );
			$process = null;
			if ( $exit !== 0 || $remaining !== '' ) {
				throw self::processFailure( $request['ids'], $exit, $stderr );
			}
			$heartbeat?->__invoke();
		} finally {
			if ( $input !== null ) { fclose( $input ); }
			if ( $errorOutput !== null ) { fclose( $errorOutput ); }
			if ( $process !== null ) {
				proc_terminate( $process, 9 );
				proc_close( $process );
			}
		}
	}

	private static function processFailure( array $ids, int $exit, string $stderr ): RuntimeException {
		$message = 'Document renderer failed for page IDs ' . $ids[0] . '–' . $ids[count( $ids ) - 1]
			. ' with exit status ' . $exit . '.';
		if ( $stderr !== '' ) {
			try {
				[ $path, $handle ] = self::newResultFile();
				$written = fwrite( $handle, $stderr );
				fclose( $handle );
				if ( $written === strlen( $stderr ) ) {
					$message .= ' Private diagnostic: ' . $path;
				} else { unlink( $path ); }
			} catch ( Throwable ) {
				// Preserve the renderer failure if diagnostic storage is unavailable.
			}
		}
		return new RuntimeException( $message );
	}

	/** @internal Called by the rendering maintenance worker. */
	public static function render( array $request ): void {
		if ( ( $request['version'] ?? null ) !== 1 || !is_array( $request['ids'] ?? null )
			|| !is_string( $request['source'] ?? null ) || !is_string( $request['result'] ?? null )
			|| !is_string( $request['nonce'] ?? null ) || !preg_match( '/^[a-f0-9]{32}$/D', $request['nonce'] )
		) {
			throw new RuntimeException( 'Invalid document renderer request.' );
		}
		$ids = self::validateIds( $request['ids'] );
		if ( $ids === [] || count( $ids ) > self::MAX_PAGES || self::sourceIdentity() !== $request['source'] ) {
			throw new RuntimeException( 'Document renderer source or page range mismatch.' );
		}
		$path = $request['result'];
		if ( is_link( $path ) || realpath( $path ) !== $path || !is_file( $path )
			|| ( fileperms( $path ) & 0777 ) !== 0600 || filesize( $path ) !== 0
		) {
			throw new RuntimeException( 'Document renderer requires an empty private result file.' );
		}
		$primary = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$primary->flushSnapshot( __METHOD__ );
		$counter = new IncomingLinkCounter( true );
		$builder = new DocumentBuilder( true, $counter->getCounts( $ids ), $counter->getOutgoingTargetIds( $ids ) );
		$results = [];
		$bytes = 0;
		foreach ( $ids as $id ) {
			$built = $builder->build( $id );
			self::validateBuilt( $built, $id );
			$bytes += strlen( json_encode( $built, JSON_THROW_ON_ERROR ) );
			if ( $bytes > self::MAX_BYTES ) {
				throw new RuntimeException( 'Document renderer result exceeds 64 MiB; reduce the maintenance batch size.' );
			}
			$results[$id] = $built;
		}
		$json = json_encode( [ 'version' => 1, 'source' => $request['source'], 'nonce' => $request['nonce'],
			'ids' => $ids, 'results' => $results ], JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_BYTES || file_put_contents( $path, $json ) !== strlen( $json ) ) {
			throw new RuntimeException( 'Unable to write the complete bounded document renderer result.' );
		}
	}

	private static function parameters( array $options ): array {
		$parameters = [];
		$conf = $options['conf'] ?? ( defined( 'MW_CONFIG_FILE' ) ? MW_CONFIG_FILE : null );
		if ( $conf !== null ) {
			if ( !is_string( $conf ) || !is_readable( $conf ) || realpath( $conf ) === false ) {
				throw new RuntimeException( 'Unable to resolve the renderer configuration file.' );
			}
			$parameters = [ '--conf', realpath( $conf ) ];
		}
		foreach ( [ 'wiki', 'server', 'dbgroupdefault' ] as $name ) {
			if ( isset( $options[$name] ) ) {
				if ( !is_string( $options[$name] ) || $options[$name] === '' ) {
					throw new RuntimeException( 'Invalid renderer maintenance option: ' . $name );
				}
				$parameters[] = '--' . $name;
				$parameters[] = $options[$name];
			}
		}
		if ( isset( $options['dbuser'] ) || isset( $options['dbpass'] ) ) {
			throw new RuntimeException( 'Isolated rendering requires database credentials in configuration, not CLI overrides.' );
		}
		$parameters[] = '--memory-limit';
		$parameters[] = (string)ini_get( 'memory_limit' );
		return $parameters;
	}

	private static function sourceIdentity(): string {
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		return hash( 'sha256', json_encode( [ $services->getMainConfig()->get( 'DBtype' ),
			$db->getServer(), $db->getDomainID() ], JSON_THROW_ON_ERROR ) );
	}

	private static function validateIds( array $ids ): array {
		$ids = array_values( $ids );
		foreach ( $ids as $id ) {
			if ( !is_int( $id ) || $id <= 0 ) { throw new RuntimeException( 'Document renderer page IDs must be positive integers.' ); }
		}
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			throw new RuntimeException( 'Document renderer page IDs must be unique.' );
		}
		return $ids;
	}

	private static function validateResult( mixed $data, array $request ): array {
		if ( !is_array( $data ) || ( $data['version'] ?? null ) !== 1
			|| ( $data['source'] ?? null ) !== $request['source'] || ( $data['nonce'] ?? null ) !== $request['nonce']
			|| ( $data['ids'] ?? null ) !== $request['ids'] || !is_array( $data['results'] ?? null )
			|| array_keys( $data['results'] ) !== $request['ids']
		) { throw new RuntimeException( 'Document renderer returned a mismatched or incomplete batch.' ); }
		foreach ( $data['results'] as $id => $built ) { self::validateBuilt( $built, $id ); }
		return $data['results'];
	}

	private static function validateBuilt( mixed $built, int $id ): void {
		if ( $built === null ) { return; }
		if ( !is_array( $built ) || !is_array( $built['document'] ?? null )
			|| !is_bool( $built['is_redirect'] ?? null ) || !array_key_exists( 'redirect_target_id', $built )
			|| ( $built['redirect_target_id'] !== null && ( !is_int( $built['redirect_target_id'] ) || $built['redirect_target_id'] <= 0 ) )
		) { throw new RuntimeException( 'Document renderer returned invalid redirect metadata.' ); }
		$doc = $built['document'];
		$hash = $doc['document_hash'] ?? null;
		if ( ( $doc['id'] ?? null ) !== $id || !is_int( $doc['revision_id'] ?? null ) || $doc['revision_id'] <= 0
			|| !is_int( $doc['namespace'] ?? null ) || $doc['namespace'] < 0
			|| !is_int( $doc['incoming_links'] ?? null ) || $doc['incoming_links'] < 0
			|| !is_int( $doc['boost'] ?? null ) || !is_string( $doc['title'] ?? null )
			|| !is_string( $doc['text'] ?? null ) || !is_string( $doc['timestamp'] ?? null )
			|| ( $doc['byte_size'] ?? null ) !== strlen( $doc['text'] )
			|| ( $doc['word_count'] ?? null ) !== str_word_count( $doc['text'] )
			|| !is_array( $doc['redirects'] ?? null ) || !array_is_list( $doc['redirects'] )
			|| !is_array( $doc['outgoing_link_ids'] ?? null ) || !array_is_list( $doc['outgoing_link_ids'] )
			|| !is_string( $hash ) || !hash_equals( DocumentHash::compute( $doc ), $hash )
		) { throw new RuntimeException( 'Document renderer returned an invalid complete document or hash.' ); }
		foreach ( $doc['redirects'] as $alias ) {
			if ( !is_string( $alias ) ) { throw new RuntimeException( 'Invalid rendered redirect alias.' ); }
		}
		foreach ( $doc['outgoing_link_ids'] as $target ) {
			if ( !is_int( $target ) || $target <= 0 ) { throw new RuntimeException( 'Invalid rendered outgoing target.' ); }
		}
	}

	/** @return array{string,resource} */
	private static function newResultFile(): array {
		$path = tempnam( sys_get_temp_dir(), 'fraux-render-' );
		if ( $path === false ) { throw new RuntimeException( 'Unable to create a private renderer result file.' ); }
		$canonical = realpath( $path );
		if ( $canonical === false ) {
			unlink( $path );
			throw new RuntimeException( 'Unable to resolve the renderer result file.' );
		}
		$path = $canonical;
		if ( !chmod( $path, 0600 ) ) {
			unlink( $path );
			throw new RuntimeException( 'Unable to protect the renderer result file.' );
		}
		$handle = fopen( $path, 'r+b' );
		if ( $handle === false ) {
			unlink( $path );
			throw new RuntimeException( 'Unable to open the renderer result file.' );
		}
		return [ $path, $handle ];
	}
}

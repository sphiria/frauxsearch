<?php

namespace FrauxSearch\Integration;

use FrauxSearch\DocumentBuilder;
use FrauxSearch\IncomingLinkCounter;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use RuntimeException;
use Throwable;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 4 );
require_once "$IP/maintenance/Maintenance.php";

class MeasureFrauxSearchIndexing extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Measure real document builds and isolated generation/incremental indexing throughput.' );
		$this->addOption( 'execute', 'Create an isolated Redis scope and index pair for the measurement' );
		$this->addOption( 'pages', 'Maximum source pages sampled (1..1000; default 25)', false, true );
		$this->addOption( 'batch-size', 'Generation batch size (1..500; default 25)', false, true );
		$this->addOption( 'start-after', 'Sample page IDs after this cursor (default 0)', false, true );
	}

	public function execute() {
		if ( !$this->hasOption( 'execute' ) ) {
			throw new RuntimeException( 'Pass --execute to measure using isolated Redis and Meilisearch resources.' );
		}
		$pages = $this->integerOption( 'pages', 25, 1, 1000 );
		$batchSize = $this->integerOption( 'batch-size', 25, 1, 500 );
		$startAfter = $this->integerOption( 'start-after', 0, 0, PHP_INT_MAX );
		require_once __DIR__ . '/CoordinationRuntime.php';
		$services = MediaWikiServices::getInstance();
		$primary = $services->getConnectionProvider()->getPrimaryDatabase();
		$primary->flushSnapshot( __METHOD__ );
		$ids = array_map( 'intval', $primary->newSelectQueryBuilder()->select( 'page_id' )->from( 'page' )
			->where( 'page_id > ' . $startAfter )->orderBy( 'page_id' )->limit( $pages )->caller( __METHOD__ )->fetchFieldValues() );
		if ( $ids === [] ) { throw new RuntimeException( 'No source pages in the requested sample.' ); }
		$runtime = new CoordinationRuntime();
		$this->output( json_encode( [ 'event' => 'indexing_measurement_started', 'index' => $runtime->index,
			'scope_hash' => hash( 'sha256', $runtime->scope ), 'source_pages' => count( $ids ),
		], JSON_UNESCAPED_SLASHES ) . "\n" );
		$failure = null;
		try {
			$runtime->install();
			$coordinator = $runtime->coordinator( static function ( int $id ) use ( $primary ): ?array {
				$primary->flushSnapshot( __METHOD__ );
				return ( new DocumentBuilder( true ) )->build( $id );
			} );
			$started = hrtime( true );
			$run = $coordinator->beginRebuild( false );
			$setupSeconds = $this->seconds( $started );
			$buildSeconds = 0.0;
			$writeSeconds = 0.0;
			$fullCount = 0;
			$completionCount = 0;
			$documentBytes = 0;
			$counter = new IncomingLinkCounter( true );
			foreach ( array_chunk( $ids, $batchSize ) as $batch ) {
				$started = hrtime( true );
				$primary->flushSnapshot( __METHOD__ );
				$builder = new DocumentBuilder( true, $counter->getCounts( $batch ), $counter->getOutgoingTargetIds( $batch ) );
				$full = [];
				$completion = [];
				foreach ( $batch as $id ) {
					$built = $builder->build( $id );
					if ( $built === null ) { continue; }
					$full[] = $built['document'];
					if ( !$built['is_redirect'] ) { $completion[] = $built['document']; }
					$documentBytes += strlen( json_encode( $built['document'], JSON_THROW_ON_ERROR ) );
				}
				$buildSeconds += $this->seconds( $started );
				$fullCount += count( $full );
				$completionCount += count( $completion );
				$started = hrtime( true );
				$coordinator->writeBatch( $run['id'], $full, $completion );
				$writeSeconds += $this->seconds( $started );
			}
			$started = hrtime( true );
			$coordinator->ready( $run['id'] );
			$coordinator->finishRebuild( $run['id'] );
			$cutoverSeconds = $this->seconds( $started );
			CoordinationRuntime::check( $runtime->client->listDocuments( 0, 1, [ 'id' ] )['total'] === $fullCount,
				'Generation full-text count does not match the source sample.' );
			CoordinationRuntime::check( $runtime->client->withIndex( $runtime->index . '_completion' )
				->listDocuments( 0, 1, [ 'id' ] )['total'] === $completionCount,
				'Generation completion count does not match the source sample.' );
			$latencies = [];
			$beforeTasks = count( $runtime->http->accepted );
			foreach ( $ids as $id ) {
				$started = hrtime( true );
				$coordinator->refresh( $id );
				$latencies[] = $this->seconds( $started );
			}
			sort( $latencies, SORT_NUMERIC );
			$incrementalSeconds = array_sum( $latencies );
			$dependencyCount = count( $runtime->dependencyIds() );
			$status = $coordinator->status();
			CoordinationRuntime::check( !$status['coordinationLost'] && $status['epoch'] === null
				&& $status['guardEpoch'] === null && $status['run'] === null && $status['pending'] === null
				&& $runtime->store->readState() === null, 'Measurement did not retire coordinated work to idle.' );
			$this->output( json_encode( [
				'event' => 'indexing_measurement', 'php_version' => PHP_VERSION,
				'source_pages' => count( $ids ), 'first_page_id' => $ids[0], 'last_page_id' => end( $ids ),
				'batch_size' => $batchSize, 'full_documents' => $fullCount, 'completion_documents' => $completionCount,
				'full_document_bytes' => $documentBytes, 'setup_seconds' => $setupSeconds,
				'batched_build_seconds' => $buildSeconds, 'generation_write_seconds' => $writeSeconds,
				'cutover_seconds' => $cutoverSeconds,
				'generation_pages_per_second' => count( $ids ) / max( 0.000001, $buildSeconds + $writeSeconds ),
				'incremental_seconds' => $incrementalSeconds,
				'incremental_pages_per_second' => count( $ids ) / max( 0.000001, $incrementalSeconds ),
				'incremental_p50_seconds' => $latencies[(int)floor( ( count( $latencies ) - 1 ) * 0.50 )],
				'incremental_p95_seconds' => $latencies[(int)floor( ( count( $latencies ) - 1 ) * 0.95 )],
				'incremental_accepted_tasks' => count( $runtime->http->accepted ) - $beforeTasks,
				'incremental_includes_guard_tasks' => true,
				'isolated_dependency_ids' => $dependencyCount,
				'limitations' => 'Warm incremental pass; bounded page-ID sample; SQL source reads, Redis coordination and real HTTP; dependency IDs collected in memory, without shared job-runner overhead or processing.',
			], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
		} catch ( Throwable $error ) {
			$failure = $error;
			throw $error;
		} finally {
			try {
				$runtime->cleanup();
				$this->output( "Verified retired coordination and removed this measurement's isolated indexes.\n" );
			} catch ( Throwable $error ) {
				if ( $failure === null ) { throw $error; }
				$this->output( "Measurement cleanup also failed; preserving the original failure and isolated evidence.\n" );
			}
		}
		return true;
	}

	private function integerOption( string $name, int $default, int $minimum, int $maximum ): int {
		$value = filter_var( $this->getOption( $name, $default ), FILTER_VALIDATE_INT );
		if ( $value === false || $value < $minimum || $value > $maximum ) {
			throw new RuntimeException( "Invalid --$name (expected $minimum..$maximum)." );
		}
		return $value;
	}

	private function seconds( int $started ): float { return ( hrtime( true ) - $started ) / 1_000_000_000; }
}

$maintClass = MeasureFrauxSearchIndexing::class;
require_once RUN_MAINTENANCE_IF_MAIN;

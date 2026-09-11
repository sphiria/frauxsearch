<?php

namespace FrauxSearch\Maintenance;

use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\IndexSettingsComparator;
use FrauxSearch\ReconciliationComparator;
use FrauxSearch\RefreshPageJob;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use InvalidArgumentException;
use RuntimeException;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class ReconcileFrauxSearchIndex extends Maintenance {
	private const ISSUE_NAMES = [
		'source_unbuildable',
		'full_text_missing',
		'full_text_stale',
		'full_text_unexpected',
		'full_text_extra',
		'completion_missing',
		'completion_stale',
		'completion_unexpected',
		'completion_extra',
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Compare MediaWiki pages with FrauxSearch indexes and optionally queue repairs.' );
		$this->addOption( 'batch-size', 'Pages or indexed documents checked per batch (default 100, maximum 1000)', false, true );
		$this->addOption( 'start-after', 'Check IDs greater than this page ID', false, true );
		$this->addOption( 'stop-after', 'Check IDs up to and including this page ID', false, true );
		$this->addOption( 'queue-repairs', 'Queue authoritative refresh jobs for mismatched IDs' );
		$this->addOption( 'fail-on-drift', 'Exit nonzero when document or owned-settings drift is found' );
	}

	public function execute() {
		$batchSize = $this->integerOption( 'batch-size', 100, 1, 1000 );
		$startAfter = $this->integerOption( 'start-after', 0, 0 );
		$stopAfter = $this->integerOption( 'stop-after', 0, 0 );
		if ( $stopAfter > 0 && $stopAfter <= $startAfter ) {
			throw new InvalidArgumentException( '--stop-after must be greater than --start-after.' );
		}
		DocumentBatchBuilder::assertAvailable( $this->getParameters()->getOptions() );
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$baseIndex = (string)$config->get( 'FrauxSearchIndex' );
		$client = $this->newClient( $baseIndex );
		$completionClient = $client->withIndex( $baseIndex . '_completion' );
		$this->output( "Live audit: drain jobs and repeat before treating mismatches as persistent.\n" );
		$settingsDrift = false;
		foreach ( [ 'Full-text' => $client, 'Completion' => $completionClient ] as $name => $index ) {
			$differences = IndexSettingsComparator::differences(
				MeilisearchClient::expectedIndexSettings(), $index->getIndexSettings()
			);
			$this->output( "$name settings: " . ( $differences === [] ? 'current' : 'differ: ' . implode( ', ', $differences ) ) . "\n" );
			if ( $differences !== [] ) {
				$settingsDrift = true;
				$this->output( "Apply configureFrauxSearchIndexes.php to repair settings; page repairs do not change settings.\n" );
			}
		}

		$counters = array_fill_keys( self::ISSUE_NAMES, 0 );
		$repairIds = [];
		$database = $services->getConnectionProvider()->getPrimaryDatabase();
		$lastId = $startAfter;
		$scanned = 0;
		do {
			$this->freshSnapshot( $database );
			$rows = iterator_to_array( $database->newSelectQueryBuilder()
				->select( [ 'page_id' ] )
				->from( 'page' )
				->where( array_filter( [
					'page_id > ' . $lastId,
					$stopAfter > 0 ? 'page_id <= ' . $stopAfter : null,
				] ) )
				->orderBy( 'page_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet() );
			$pageIds = [];
			foreach ( $rows as $row ) {
				$lastId = (int)$row->page_id;
				$pageIds[] = $lastId;
			}
			$fullText = $this->indexById( $client->fetchDocuments( $pageIds ), $pageIds );
			$completion = $this->indexById( $completionClient->fetchDocuments( $pageIds ), $pageIds );
			$built = $this->buildBatch( $pageIds );
			foreach ( $pageIds as $pageId ) {
				$issues = ReconciliationComparator::compare(
					$built[$pageId],
					$fullText[$pageId] ?? null,
					$completion[$pageId] ?? null
				);
				foreach ( $issues as $issue ) {
					$counters[$issue]++;
				}
				if ( ReconciliationComparator::needsRepair( $issues ) ) {
					$repairIds[$pageId] = true;
				}
			}
			$scanned += count( $pageIds );
			if ( $pageIds !== [] ) {
				$this->output(
					"Scanned $scanned pages; mismatched IDs " . count( $repairIds ) . "; last page ID $lastId\n"
				);
			}
		} while ( count( $rows ) === $batchSize );

		$this->findExtraDocuments(
			$client,
			'full_text_extra',
			$database,
			$batchSize,
			$startAfter,
			$stopAfter,
			$counters,
			$repairIds
		);
		$this->findExtraDocuments(
			$completionClient,
			'completion_extra',
			$database,
			$batchSize,
			$startAfter,
			$stopAfter,
			$counters,
			$repairIds
		);

		ksort( $repairIds, SORT_NUMERIC );
		$queued = 0;
		if ( $this->hasOption( 'queue-repairs' ) ) {
			$event = bin2hex( random_bytes( 16 ) );
			foreach ( array_chunk( array_keys( $repairIds ), 1000 ) as $pageIds ) {
				$jobs = array_map(
					static fn ( int $pageId ) => new RefreshPageJob( [ 'pageId' => $pageId, 'reconciliationEvent' => $event ] ),
					$pageIds
				);
				$services->getJobQueueGroup()->push( $jobs );
				$queued += count( $jobs );
			}
		}

		$this->output( "Pages scanned: $scanned\n" );
		$this->output(
			"Full-text: missing={$counters['full_text_missing']} stale={$counters['full_text_stale']} "
			. "unexpected={$counters['full_text_unexpected']} extra={$counters['full_text_extra']}\n"
		);
		$this->output(
			"Completion: missing={$counters['completion_missing']} stale={$counters['completion_stale']} "
			. "unexpected={$counters['completion_unexpected']} extra={$counters['completion_extra']}\n"
		);
		$this->output( "Unbuildable source pages: {$counters['source_unbuildable']}\n" );
		$this->output( 'Unique repair candidates: ' . count( $repairIds ) . "\n" );
		$this->output( "Repair jobs queued: $queued\n" );
		if ( $this->hasOption( 'fail-on-drift' ) && ( $settingsDrift || $repairIds !== [] ) ) {
			$this->fatalError( 'FrauxSearch index drift detected; drain any queued repairs and repeat the audit.', 1 );
		}
	}

	private function findExtraDocuments(
		MeilisearchClient $client,
		string $issue,
		$database,
		int $batchSize,
		int $startAfter,
		int $stopAfter,
		array &$counters,
		array &$repairIds
	): void {
		$filter = $startAfter > 0 || $stopAfter > 0 ? 'id > ' . $startAfter : null;
		if ( $stopAfter > 0 ) {
			$filter .= ' AND id <= ' . $stopAfter;
		}
		$offset = 0;
		$seen = [];
		do {
			$this->freshSnapshot( $database );
			$response = $client->listDocuments( $offset, $batchSize, [ 'id' ], $filter );
			$documents = $response['results'];
			$pageIds = [];
			foreach ( $documents as $document ) {
				$id = $this->pageId( $document['id'] ?? null );
				if ( isset( $seen[$id] ) ) {
					throw new RuntimeException( 'Repeated indexed document ID; the index may have changed during this audit. Repeat the audit.' );
				}
				$seen[$id] = true;
				if ( $id <= $startAfter || ( $stopAfter > 0 && $id > $stopAfter ) ) {
					throw new RuntimeException( 'Meilisearch returned a document outside the requested audit bounds.' );
				}
				$pageIds[] = $id;
			}
			$existing = $pageIds === [] ? [] : array_map( 'intval', $database->newSelectQueryBuilder()
				->select( [ 'page_id' ] )
				->from( 'page' )
				->where( [ 'page_id' => $pageIds ] )
				->caller( __METHOD__ )
				->fetchFieldValues() );
			$existing = array_fill_keys( $existing, true );
			foreach ( $pageIds as $pageId ) {
				if ( !isset( $existing[$pageId] ) ) {
					$counters[$issue]++;
					$repairIds[$pageId] = true;
				}
			}
			$offset += count( $documents );
			$total = $response['total'];
		} while ( $documents !== [] && $offset < $total );
	}

	private function indexById( array $documents, array $requested ): array {
		$indexed = [];
		foreach ( $documents as $document ) {
			$id = $this->pageId( $document['id'] ?? null );
			if ( !in_array( $id, $requested, true ) || isset( $indexed[$id] ) ) {
				throw new RuntimeException( 'Meilisearch returned an unexpected or duplicate document ID.' );
			}
			$indexed[$id] = $document;
		}
		return $indexed;
	}

	private function pageId( $value ): int {
		if ( !( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) )
			|| filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) === false
		) { throw new RuntimeException( 'An indexed document has an invalid MediaWiki page ID; manual cleanup is required.' ); }
		return (int)$value;
	}

	private function integerOption( string $name, int $default, int $minimum, int $maximum = PHP_INT_MAX ): int {
		$value = filter_var( $this->getOption( $name, $default ), FILTER_VALIDATE_INT,
			[ 'options' => [ 'min_range' => $minimum, 'max_range' => $maximum ] ] );
		if ( $value === false ) { throw new InvalidArgumentException( "Invalid --$name: expected an integer from $minimum to $maximum." ); }
		return $value;
	}

	private function freshSnapshot( $database ): void {
		if ( $database->explicitTrxActive() ) {
			throw new RuntimeException( 'Reconciliation requires an idle primary transaction.' );
		}
		$database->flushSnapshot( __METHOD__ );
	}

	protected function newClient( string $baseIndex ): MeilisearchClient {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		return new MeilisearchClient( (string)$config->get( 'FrauxSearchUrl' ),
			(string)$config->get( 'FrauxSearchApiKey' ), $baseIndex, (int)$config->get( 'FrauxSearchTimeout' ),
			(string)$config->get( 'FrauxSearchTaskApiKey' ) );
	}

	/** @return array<int,?array> */
	protected function buildBatch( array $pageIds ): array {
		return DocumentBatchBuilder::build( $pageIds, $this->getParameters()->getOptions() );
	}
}

$maintClass = ReconcileFrauxSearchIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;

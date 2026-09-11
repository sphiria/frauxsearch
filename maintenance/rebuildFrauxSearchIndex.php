<?php

namespace FrauxSearch\Maintenance;

use Closure;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\IndexCoordinatorFactory;
use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\RebuildPlan;
use InvalidArgumentException;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";
require_once dirname( __DIR__ ) . '/src/MeilisearchClient.php';

class RebuildFrauxSearchIndex extends Maintenance {
	protected bool $completionOnly = false;
	public function __construct() {
		parent::__construct();
		$this->addDescription( $this->completionOnly
			? 'Rebuild the FrauxSearch completion index.'
			: 'Rebuild the complete FrauxSearch Meilisearch index.' );
		$this->addOption( 'render-workers', 'Concurrent rendering processes, 1–8 (default 1)', false, true );
		$this->addOption( 'batch-size', 'Source pages scheduled per batch', false, true );
		$this->addOption( 'max-pending', 'Maximum pending tasks (coordinated writes currently use one at a time)', false, true );
		$this->addOption( 'start-after', 'Resume after this page ID', false, true );
		$this->addOption( 'stop-after', 'Stop after this page ID without swapping a generation', false, true );
		$this->addOption( 'index', 'Override the configured base index name', false, true );
		$this->addOption( 'no-reset', 'Keep existing documents and index settings' );
		$this->addOption( 'dry-run', 'Scan and build documents without changing Meilisearch' );
		$this->addOption( 'generation', 'Allow a bounded generation rebuild for an overridden test index' );
		$this->addOption( 'recover-lost-state',
			'Restart a full rebuild after losing temporary coordination; supply the guard epoch from status', false, true );
		$this->addOption( 'confirm-writers-stopped',
			'Confirm all indexing writers and their outstanding HTTP requests have stopped before lost-state recovery' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$options = [];
		foreach ( [ 'index', 'batch-size', 'max-pending', 'start-after', 'stop-after',
			'no-reset', 'dry-run', 'generation' ] as $name
		) {
			if ( $this->hasOption( $name ) ) {
				$options[$name] = in_array( $name, [ 'no-reset', 'dry-run', 'generation' ], true )
					? true : $this->getOption( $name );
			}
		}
		$plan = new RebuildPlan( $options, (string)$config->get( 'FrauxSearchIndex' ) );
		$recoveryEpoch = $this->hasOption( 'recover-lost-state' ) ? $this->getOption( 'recover-lost-state' ) : null;
		if ( ( $recoveryEpoch !== null ) !== $this->hasOption( 'confirm-writers-stopped' ) ) {
			throw new InvalidArgumentException( 'Lost-state recovery requires both --recover-lost-state EPOCH '
				. 'and --confirm-writers-stopped.' );
		}
		if ( $recoveryEpoch !== null && (
			!is_string( $recoveryEpoch ) || preg_match( '/^[a-f0-9]{32}$/D', $recoveryEpoch ) !== 1
			|| $this->completionOnly || !$plan->generation
			|| $this->hasOption( 'start-after' ) || $this->hasOption( 'stop-after' ) || $this->hasOption( 'generation' )
		) ) {
			throw new InvalidArgumentException( 'Lost-state recovery requires a valid guard epoch and a full rebuild '
				. 'without completion-only, dry-run, no-reset, or bounded generation options.' );
		}
		if ( !$plan->dryRun && !$plan->generation && (int)$this->getOption( 'render-workers', 1 ) > 1 ) {
			throw new InvalidArgumentException( '--render-workers above 1 requires a generation rebuild or --dry-run.' );
		}
		$baseIndex = $plan->baseIndex;
		DocumentBatchBuilder::assertAvailable( $this->getParameters()->getOptions() );
		$coordinator = $plan->dryRun ? null : $this->newCoordinator( $baseIndex );
		$run = $recoveryEpoch !== null ? $coordinator->restartAfterStateLoss( $recoveryEpoch ) :
			( $plan->generation ? $coordinator->beginRebuild( $this->completionOnly ) : null );
		if ( $run !== null ) {
			$this->output( "Building FrauxSearch run {$run['id']}\n" );
		}
		$batchSize = $plan->batchSize;
		$lastId = $plan->startAfter;
		$stopAfter = $plan->stopAfter;
		$count = 0;
		$scanned = 0;
		do {
			$primary = $services->getConnectionProvider()->getPrimaryDatabase();
			$primary->flushSnapshot( __METHOD__ );
			$rows = $primary->newSelectQueryBuilder()
				->select( [ 'page_id' ] )
				->from( 'page' )
				->where( array_filter( [
					'page_id > ' . $lastId,
					$stopAfter > 0 ? 'page_id <= ' . $stopAfter : null,
				] ) )
				->orderBy( 'page_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();
			$pageRows = iterator_to_array( $rows );
			$pageIds = [];
			foreach ( $pageRows as $row ) {
				$lastId = (int)$row->page_id;
				$pageIds[] = $lastId;
			}
			$scanned += count( $pageRows );
			if ( !$plan->dryRun && !$plan->generation ) {
				foreach ( $pageIds as $pageId ) {
					$coordinator->refresh( $pageId, null, $this->completionOnly );
				}
				$count += count( $pageIds );
				$this->output( "Refreshed $count source pages; last page ID $lastId\n" );
				continue;
			}
			$consume = function ( array $batch ) use ( &$count, $run, $coordinator ): void {
				$documents = [];
				$completionDocuments = [];
				foreach ( $batch as $built ) {
					if ( $built === null ) { continue; }
					$documents[] = $built['document'];
					if ( !$built['is_redirect'] ) { $completionDocuments[] = $built['document']; }
				}
				$count += $this->completionOnly ? count( $completionDocuments ) : count( $documents );
				if ( $run !== null ) {
					$coordinator->writeBatch( $run['id'], $documents, $completionDocuments );
				}
			};
			if ( $pageIds === [] ) { $consume( [] ); }
			else { $this->buildBatch( $pageIds, $consume ); }
			$this->output( "Scanned $scanned pages; eligible $count; last page ID $lastId\n" );
		} while ( count( $pageRows ) === $batchSize );
		if ( $run !== null ) {
			$coordinator->ready( $run['id'] );
			$coordinator->finishRebuild( $run['id'] );
			$this->output( "Activated FrauxSearch run {$run['id']} and removed previous generations\n" );
		}
		$this->output( $plan->dryRun
			? "Dry run finished: scanned $scanned pages; $count eligible documents\n"
			: "Finished indexing $count pages\n" );
	}

	protected function newCoordinator( string $baseIndex ): IndexCoordinator {
		$options = $this->getParameters()->getOptions();
		return IndexCoordinatorFactory::create( $baseIndex, 60,
			static fn ( int $id, Closure $heartbeat ): ?array =>
				DocumentBatchBuilder::build( [ $id ], $options, $heartbeat )[$id]
		);
	}

	/** @return array<int,?array> */
	protected function buildBatch( array $pageIds, Closure $onBatch ): array {
		return DocumentBatchBuilder::build( $pageIds, $this->getParameters()->getOptions(), null, $onBatch );
	}

}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$maintClass = RebuildFrauxSearchIndex::class;
	require_once RUN_MAINTENANCE_IF_MAIN;
}

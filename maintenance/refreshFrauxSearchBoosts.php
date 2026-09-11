<?php

namespace FrauxSearch\Maintenance;

use Closure;
use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\IndexCoordinatorFactory;
use InvalidArgumentException;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use RuntimeException;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class RefreshFrauxSearchBoosts extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Authoritatively refresh FrauxSearch documents after boost policy changes.' );
		$this->addOption( 'batch-size', 'Pages scanned per batch', false, true );
		$this->addOption( 'start-after', 'Resume after this page ID', false, true );
		$this->addOption( 'stop-after', 'Stop after this page ID', false, true );
		$this->addOption( 'dry-run', 'Report affected pages without changing Meilisearch' );
	}

	public function execute() {
		$batchSize = $this->integerOption( 'batch-size', 1000, 1, 1000 );
		$lastId = $this->integerOption( 'start-after', 0, 0 );
		$stopAfter = $this->integerOption( 'stop-after', 0, 0 );
		if ( $stopAfter > 0 && $stopAfter <= $lastId ) {
			throw new InvalidArgumentException( '--stop-after must be greater than --start-after.' );
		}
		$options = $this->getParameters()->getOptions();
		DocumentBatchBuilder::assertAvailable( $options );
		$services = MediaWikiServices::getInstance();
		$dryRun = $this->hasOption( 'dry-run' );
		$coordinator = $dryRun ? null : IndexCoordinatorFactory::create( null, 60,
			static fn ( int $id, Closure $heartbeat ): ?array =>
				DocumentBatchBuilder::build( [ $id ], $options, $heartbeat )[$id]
		);
		$count = 0;
		do {
			$primary = $services->getConnectionProvider()->getPrimaryDatabase();
			if ( $primary->explicitTrxActive() ) {
				throw new RuntimeException( 'FrauxSearch boost refresh requires an idle primary transaction.' );
			}
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
			$pageIds = [];
			foreach ( $rows as $row ) {
				$lastId = (int)$row->page_id;
				$pageIds[] = $lastId;
			}
			if ( $dryRun ) {
				DocumentBatchBuilder::build( $pageIds, $options );
			} else {
				foreach ( $pageIds as $pageId ) { $coordinator->refresh( $pageId ); }
			}
			$count += count( $pageIds );
			$this->output( "Processed $count pages; last page ID $lastId\n" );
		} while ( count( $pageIds ) === $batchSize );
	}

	private function integerOption( string $name, int $default, int $minimum, int $maximum = PHP_INT_MAX ): int {
		$value = filter_var( $this->getOption( $name, $default ), FILTER_VALIDATE_INT );
		if ( $value === false || $value < $minimum || $value > $maximum ) {
			throw new InvalidArgumentException( "--$name must be an integer between $minimum and $maximum." );
		}
		return $value;
	}
}

$maintClass = RefreshFrauxSearchBoosts::class;
require_once RUN_MAINTENANCE_IF_MAIN;

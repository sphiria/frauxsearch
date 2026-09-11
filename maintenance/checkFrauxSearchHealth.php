<?php

namespace FrauxSearch\Maintenance;

use FrauxSearch\IndexCoordinatorFactory;
use FrauxSearch\IndexHealth;
use FrauxSearch\IndexSettingsComparator;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use InvalidArgumentException;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use Throwable;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class CheckFrauxSearchHealth extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Report index/coordinator/job health as JSON; exit nonzero on unhealthy observations.' );
		$this->addOption( 'index', 'Override the base index (job queue observations remain wiki-wide)', false, true );
		$this->addOption( 'max-pending-seconds', 'Maximum pending-operation age (default 900)', false, true );
		$this->addOption( 'max-queued', 'Maximum ready/acquired/delayed jobs per type (default 10000)', false, true );
		$this->addOption( 'require-idle', 'Also fail for pending pages, active runs/tasks, or queued work' );
	}

	public function execute() {
		$maxAge = $this->integerOption( 'max-pending-seconds', 900 );
		$maxQueued = $this->integerOption( 'max-queued', 10000 );
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$base = (string)$this->getOption( 'index', $config->get( 'FrauxSearchIndex' ) );
		try {
			$coordinator = IndexCoordinatorFactory::create( $base );
			$state = $coordinator->status();
			$pendingPages = !( $state['coordinationLost'] ?? false ) && $coordinator->hasPendingPages();
			$indexes = [];
			$client = new MeilisearchClient( (string)$config->get( 'FrauxSearchUrl' ),
				(string)$config->get( 'FrauxSearchApiKey' ), $base, (int)$config->get( 'FrauxSearchTimeout' ),
				(string)$config->get( 'FrauxSearchTaskApiKey' ) );
			foreach ( [ $base, $base . '_completion' ] as $name ) {
				try {
					$index = $client->withIndex( $name );
					$indexes[$name] = [
						'settingsDifferences' => IndexSettingsComparator::differences(
							MeilisearchClient::expectedIndexSettings(), $index->getIndexSettings() ),
						'documents' => $index->listDocuments( 0, 1, [ 'id' ] )['total'],
					];
				} catch ( Throwable $e ) { $indexes[$name] = [ 'error' => $e->getMessage() ]; }
			}
			$queues = [];
			foreach ( [ 'frauxSearchRefreshPage', 'frauxSearchScheduleIncomingRefreshes',
				'frauxSearchScheduleBoostRefreshes' ] as $type
			) {
				$queue = $services->getJobQueueGroup()->get( $type );
				$queues[$type] = [ 'ready' => $queue->getSize(), 'acquired' => $queue->getAcquiredCount(),
					'delayed' => $queue->getDelayedCount(), 'abandoned' => $queue->getAbandonedCount(),
					'delayedSupported' => $queue->delayedJobsEnabled() ];
			}
			$report = IndexHealth::report( $state, $pendingPages, $indexes, $queues,
				time(), $maxAge, $maxQueued, $this->hasOption( 'require-idle' ) );
		} catch ( Throwable $e ) {
			$report = [ 'healthy' => false, 'observedAt' => gmdate( 'c' ),
				'issues' => [ $e instanceof MeilisearchException && $e->getErrorCode() === 'coordination_busy'
					? 'coordinator_busy' : 'health_check_failed' ], 'error' => $e->getMessage() ];
		}
		$this->output( json_encode( $report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( !$report['healthy'] ) { $this->fatalError( 'FrauxSearch health check failed.', 1 ); }
	}

	private function integerOption( string $name, int $default ): int {
		$value = filter_var( $this->getOption( $name, $default ), FILTER_VALIDATE_INT,
			[ 'options' => [ 'min_range' => 1 ] ] );
		if ( $value === false ) { throw new InvalidArgumentException( "Invalid --$name: expected a positive integer." ); }
		return $value;
	}
}

$maintClass = CheckFrauxSearchHealth::class;
require_once RUN_MAINTENANCE_IF_MAIN;

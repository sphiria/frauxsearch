<?php

namespace FrauxSearch\Maintenance;

use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\RefreshQueueProcessor;
use InvalidArgumentException;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class RunFrauxSearchJobs extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Process queued FrauxSearch refreshes in batches; failed claims remain retryable.' );
		$this->addOption( 'batch-size', 'Maximum jobs claimed per batch, 1–500 (default 100)', false, true );
		$this->addOption( 'render-workers', 'Concurrent renderers, 1–8 (default 1)', false, true );
		$this->addOption( 'max-jobs', 'Stop after this many claimed jobs (default 1000)', false, true );
		$this->addOption( 'maxtime', 'Stop between batches after this many seconds (default 300)', false, true );
	}

	public function execute() {
		$batch = $this->positive( 'batch-size', 100 );
		$maxJobs = $this->positive( 'max-jobs', 1000 );
		$maxTime = $this->positive( 'maxtime', 300 );
		if ( $batch > 500 ) { throw new InvalidArgumentException( '--batch-size must not exceed 500.' ); }
		$options = $this->getParameters()->getOptions();
		DocumentBatchBuilder::assertAvailable( $options );
		$processor = RefreshQueueProcessor::create( $options );
		$started = hrtime( true );
		$jobs = 0;
		$pages = 0;
		do {
			$result = $processor->run( min( $batch, $maxJobs - $jobs ) );
			$jobs += $result['jobs'];
			$pages += $result['pages'];
			$this->output( "Completed $jobs refresh jobs; $pages page builds\n" );
		} while ( $result['jobs'] > 0 && $jobs < $maxJobs && ( hrtime( true ) - $started ) / 1e9 < $maxTime );
	}

	private function positive( string $name, int $default ): int {
		$value = filter_var( $this->getOption( $name, $default ), FILTER_VALIDATE_INT );
		if ( $value === false || $value < 1 ) { throw new InvalidArgumentException( "--$name must be positive." ); }
		return $value;
	}
}

$maintClass = RunFrauxSearchJobs::class;
require_once RUN_MAINTENANCE_IF_MAIN;

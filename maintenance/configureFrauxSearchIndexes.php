<?php

namespace FrauxSearch\Maintenance;

use FrauxSearch\IndexCoordinatorFactory;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class ConfigureFrauxSearchIndexes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Apply FrauxSearch settings to both existing indexes without rebuilding documents.' );
	}

	public function execute() {
		IndexCoordinatorFactory::create( null, 60 )->configure();
		$this->output( "Configured FrauxSearch full-text and completion indexes\n" );
	}
}

$maintClass = ConfigureFrauxSearchIndexes::class;
require_once RUN_MAINTENANCE_IF_MAIN;

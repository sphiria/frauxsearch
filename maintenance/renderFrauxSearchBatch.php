<?php

namespace FrauxSearch\Maintenance;

use FrauxSearch\DocumentBatchBuilder;
use MediaWiki\Maintenance\Maintenance;
use RuntimeException;

if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class RenderFrauxSearchBatch extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Render an isolated document batch requested by FrauxSearch maintenance.' );
	}

	public function execute() {
		$request = stream_get_contents( $this->getStdin(), 65537 );
		if ( $request === false || strlen( $request ) > 65536 ) {
			throw new RuntimeException( 'Invalid document renderer request size.' );
		}
		$data = json_decode( $request, true, 32, JSON_THROW_ON_ERROR );
		if ( !is_array( $data ) ) { throw new RuntimeException( 'Invalid document renderer request.' ); }
		DocumentBatchBuilder::render( $data );
		return true;
	}
}

$maintClass = RenderFrauxSearchBatch::class;
require_once RUN_MAINTENANCE_IF_MAIN;

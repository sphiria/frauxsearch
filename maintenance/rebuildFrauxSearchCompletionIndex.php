<?php

namespace FrauxSearch\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/rebuildFrauxSearchIndex.php';

class RebuildFrauxSearchCompletionIndex extends RebuildFrauxSearchIndex {
	protected bool $completionOnly = true;
}

$maintClass = RebuildFrauxSearchCompletionIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;

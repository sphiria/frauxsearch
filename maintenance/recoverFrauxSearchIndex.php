<?php

namespace FrauxSearch\Maintenance;

use Closure;
use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\IndexCoordinatorFactory;
use MediaWiki\Maintenance\Maintenance;
use InvalidArgumentException;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class RecoverFrauxSearchIndex extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Inspect unfinished FrauxSearch work; recover known tasks, builds, or pending pages. '
			. 'Lost temporary state requires rebuildFrauxSearchIndex.php --recover-lost-state EPOCH '
			. '--confirm-writers-stopped after stopping indexing writers and outstanding requests.' );
		$this->addOption( 'index', 'Override the base index', false, true );
		$this->addOption( 'drain', 'Finish known pending tasks and refresh a snapshot of journaled pages' );
		$this->addOption( 'finish-run', 'Resume cutover/cleanup of this completed build ID', false, true );
		$this->addOption( 'abort-run', 'Discard this incomplete generation build ID', false, true );
		$this->addOption( 'operation-id', 'Unresolved operation ID whose task was independently identified', false, true );
		$this->addOption( 'task-id', 'Matching Meilisearch task UID, verified from server history', false, true );
		$this->addOption( 'confirm-not-submitted',
			'Operation ID: sender stopped and server non-submission independently verified', false, true );
	}

	public function execute() {
		$adopt = $this->hasOption( 'operation-id' ) || $this->hasOption( 'task-id' );
		$actions = (int)$adopt;
		foreach ( [ 'drain', 'finish-run', 'abort-run', 'confirm-not-submitted' ] as $option ) {
			$actions += (int)$this->hasOption( $option );
		}
		if ( $actions > 1 || ( $adopt && !( $this->hasOption( 'operation-id' ) && $this->hasOption( 'task-id' ) ) ) ) {
			throw new InvalidArgumentException( 'Choose one recovery action; task adoption requires both IDs.' );
		}
		$task = $adopt ? filter_var( $this->getOption( 'task-id' ), FILTER_VALIDATE_INT ) : null;
		if ( $adopt && ( $task === false || $task < 0 ) ) { throw new InvalidArgumentException( 'Invalid task UID.' ); }
		$build = null;
		if ( $this->hasOption( 'drain' ) || $this->hasOption( 'finish-run' ) || $this->hasOption( 'abort-run' ) ) {
			$options = $this->getParameters()->getOptions();
			DocumentBatchBuilder::assertAvailable( $options );
			$build = static fn ( int $id, Closure $heartbeat ): ?array =>
				DocumentBatchBuilder::build( [ $id ], $options, $heartbeat )[$id];
		}
		$coordinator = IndexCoordinatorFactory::create(
			$this->hasOption( 'index' ) ? $this->getOption( 'index' ) : null, 60, $build
		);
		if ( $adopt ) { $coordinator->adoptTask( $this->getOption( 'operation-id' ), $task ); }
		if ( $this->hasOption( 'confirm-not-submitted' ) ) {
			$coordinator->confirmNotSubmitted( $this->getOption( 'confirm-not-submitted' ) );
		}
		if ( $this->hasOption( 'drain' ) ) { $coordinator->drain(); }
		if ( $this->hasOption( 'finish-run' ) ) { $coordinator->finishRebuild( $this->getOption( 'finish-run' ) ); }
		if ( $this->hasOption( 'abort-run' ) ) { $coordinator->abortRebuild( $this->getOption( 'abort-run' ) ); }
		$this->output( json_encode( $coordinator->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
}

$maintClass = RecoverFrauxSearchIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;

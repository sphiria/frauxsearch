<?php

namespace FrauxSearch;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

class ScheduleIncomingRefreshesJob extends Job implements GenericParameterJob {
	private const BATCH_SIZE = 1000;

	public function __construct( array $params ) {
		if ( !array_key_exists( 'identityEvent', $params ) ) {
			$params['identityEvent'] = bin2hex( random_bytes( 16 ) );
		}
		parent::__construct( 'frauxSearchScheduleIncomingRefreshes', $params );
		$this->removeDuplicates = true;
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	public function run(): bool {
		$namespace = $this->params['namespace'] ?? null;
		$title = $this->params['title'] ?? null;
		$startAfter = array_key_exists( 'startAfter', $this->params ) ? $this->params['startAfter'] : 0;
		$event = $this->params['identityEvent'];
		if ( !is_int( $namespace ) || $namespace < 0 || !is_string( $title ) || $title === '' ||
			!is_int( $startAfter ) || $startAfter < 0 || !is_string( $event ) || $event === ''
		) {
			throw new \InvalidArgumentException( 'Invalid incoming-refresh scheduler parameters.' );
		}

		$services = MediaWikiServices::getInstance();
		$database = $services->getConnectionProvider()->getPrimaryDatabase();
		if ( $database->explicitTrxActive() ) {
			throw new \RuntimeException( 'Incoming refresh scheduling requires an idle primary transaction.' );
		}
		$database->flushSnapshot( __METHOD__ );
		$pageIds = ( new IncomingSourceLookup( $database ) )->getSourceIds(
			$namespace, $title, $startAfter, self::BATCH_SIZE
		);
		$jobs = array_map(
			static fn ( int $pageId ) => new RefreshPageJob( [ 'pageId' => $pageId, 'identityEvent' => $event ] ),
			$pageIds
		);
		if ( $jobs !== [] ) {
			$services->getJobQueueGroup()->push( $jobs );
		}
		if ( count( $pageIds ) === self::BATCH_SIZE ) {
			$services->getJobQueueGroup()->push( new self( [
				'namespace' => $namespace,
				'title' => $title,
				'identityEvent' => $event,
				'startAfter' => end( $pageIds ),
			] ) );
		}
		wfDebugLog( 'FrauxSearch', json_encode( [
			'event' => 'incoming_refresh_batch_queued',
			'namespace' => $namespace,
			'title' => $title,
			'page_count' => count( $pageIds ),
			'start_after' => $startAfter,
		], JSON_UNESCAPED_SLASHES ) );
		return true;
	}
}

<?php

namespace FrauxSearch;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

class ScheduleBoostRefreshesJob extends Job implements GenericParameterJob {
	private const BATCH_SIZE = 1000;

	public function __construct( array $params ) {
		parent::__construct( 'frauxSearchScheduleBoostRefreshes', $params );
		$this->removeDuplicates = true;
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	public function run(): bool {
		$templates = array_values( array_unique( array_map( 'strval', $this->params['templates'] ?? [] ) ) );
		$allPages = (bool)( $this->params['allPages'] ?? false );
		if ( !$allPages && $templates === [] ) {
			return true;
		}
		$lastId = max( 0, (int)( $this->params['startAfter'] ?? 0 ) );
		$services = MediaWikiServices::getInstance();
		$query = $services->getConnectionProvider()->getPrimaryDatabase()->newSelectQueryBuilder();
		if ( $allPages ) {
			$query->select( 'page_id' )->from( 'page' )->where( 'page_id > ' . $lastId )->orderBy( 'page_id' );
		} else {
			$query->select( 'tl_from' )->distinct()->from( 'templatelinks' )
				->join( 'linktarget', null, 'tl_target_id = lt_id' )
				->where( [ 'tl_from > ' . $lastId, 'lt_namespace' => NS_TEMPLATE, 'lt_title' => $templates ] )
				->orderBy( 'tl_from' );
		}
		$pageIds = $query->limit( self::BATCH_SIZE )->caller( __METHOD__ )->fetchFieldValues();
		$event = $this->params['policyEvent'] ?? bin2hex( random_bytes( 16 ) );
		$jobs = array_map(
			static fn ( $pageId ) => new RefreshPageJob( [ 'pageId' => (int)$pageId, 'policyEvent' => $event ] ),
			$pageIds
		);
		if ( $jobs !== [] ) {
			$services->getJobQueueGroup()->push( $jobs );
		}
		if ( count( $pageIds ) === self::BATCH_SIZE ) {
			$services->getJobQueueGroup()->push( new self( [
				'templates' => $templates,
				'allPages' => $allPages,
				'policyEvent' => $event,
				'startAfter' => (int)end( $pageIds ),
			] ) );
		}
		wfDebugLog( 'FrauxSearch', json_encode( [
			'event' => 'boost_refresh_batch_queued',
			'templates' => $templates,
			'page_count' => count( $pageIds ),
			'start_after' => $lastId,
		], JSON_UNESCAPED_SLASHES ) );
		return true;
	}
}

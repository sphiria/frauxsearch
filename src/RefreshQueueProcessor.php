<?php

namespace FrauxSearch;

use Closure;
use MediaWiki\JobQueue\JobQueueRedis;
use MediaWiki\MediaWikiServices;
use RuntimeException;

class RefreshQueueProcessor {
	public function __construct(
		private Closure $pop, private Closure $ack, private Closure $refresh
	) {
	}

	public static function create( array $options = [] ): self {
		DocumentBatchBuilder::assertAvailable( $options );
		$queues = MediaWikiServices::getInstance()->getJobQueueGroup();
		if ( !$queues->get( 'frauxSearchRefreshPage' ) instanceof JobQueueRedis ) {
			throw new RuntimeException( 'Batched refresh processing requires the Redis job queue.' );
		}
		$coordinator = IndexCoordinatorFactory::create();
		return new self(
			static fn () => $queues->pop( 'frauxSearchRefreshPage' ),
			static fn ( RefreshPageJob $job ) => $queues->ack( $job ),
			static function ( Closure $requests ) use ( $coordinator, $options ): int {
				$primary = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
				if ( $primary->explicitTrxActive() ) { throw new RuntimeException( 'Refresh batch requires committed source data.' ); }
				$primary->flushSnapshot( __CLASS__ . '::create' );
				return $coordinator->refreshBatch( $requests,
					static fn ( array $ids, Closure $heartbeat ): array => DocumentBatchBuilder::build( $ids, $options, $heartbeat ) );
			}
		);
	}

	/** @return array{jobs:int,pages:int} */
	public function run( int $limit, ?RefreshPageJob $seed = null ): array {
		if ( $limit < 1 || $limit > 500 ) { throw new RuntimeException( 'Refresh batch size must be between 1 and 500.' ); }
		$jobs = $seed === null ? [] : [ $seed ];
		$pages = ( $this->refresh )( function ( Closure $heartbeat ) use ( $limit, &$jobs ): array {
			while ( count( $jobs ) < $limit ) {
				$heartbeat();
				$job = ( $this->pop )();
				if ( $job === false || $job === null ) { break; }
				if ( !$job instanceof RefreshPageJob ) { throw new RuntimeException( 'Unexpected job in refresh queue.' ); }
				$jobs[] = $job;
			}
			$requests = [];
			foreach ( $jobs as $job ) {
				$params = $job->getParams();
				if ( ( $job->getReleaseTimestamp() ?? 0 ) > time() ) {
					throw new RuntimeException( 'Claimed refresh job is not due yet.' );
				}
				$id = filter_var( $params['pageId'] ?? null, FILTER_VALIDATE_INT );
				if ( $id === false || $id < 1 ) { throw new RuntimeException( 'Invalid queued page ID.' ); }
				$requests[] = [ 'pageId' => $id, 'redirectTitle' => $params['redirectTitle'] ?? null ];
			}
			return $requests;
		} );
		foreach ( $jobs as $job ) {
			if ( $job !== $seed ) { ( $this->ack )( $job ); }
		}
		return [ 'jobs' => count( $jobs ), 'pages' => $pages ];
	}
}

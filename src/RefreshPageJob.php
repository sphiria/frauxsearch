<?php

namespace FrauxSearch;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueRedis;
use MediaWiki\MediaWikiServices;
use RuntimeException;

class RefreshPageJob extends Job implements GenericParameterJob {
	private const RETRY_DELAYS = [ 30, 60, 120, 240, 480 ];
	private bool $allowQueueRetries = true;
	private int $processedJobs = 1;

	public function __construct( array $params ) {
		parent::__construct( 'frauxSearchRefreshPage', $params );
		$this->removeDuplicates = true;
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		foreach ( [ 'dependencyEvent', 'sourceEvent', 'identityEvent', 'policyEvent' ] as $key ) {
			unset( $info['params'][$key] );
		}
		return $info;
	}

	protected function processBatch(): bool {
		$services = MediaWikiServices::getInstance();
		if ( PHP_SAPI !== 'cli'
			|| !$services->getJobQueueGroup()->get( 'frauxSearchRefreshPage' ) instanceof JobQueueRedis
		) { return false; }
		$config = $services->getMainConfig();
		$size = (int)$config->get( 'FrauxSearchRefreshBatchSize' );
		if ( $size < 1 || $size > 500 ) { throw new RuntimeException( 'FrauxSearchRefreshBatchSize must be between 1 and 500.' ); }
		if ( $size === 1 ) { return false; }
		$result = RefreshQueueProcessor::create( [
			'render-workers' => $config->get( 'FrauxSearchRefreshWorkers' ),
		] )->run( $size, $this );
		$this->processedJobs = $result['jobs'];
		wfDebugLog( 'FrauxSearch', json_encode( [ 'event' => 'refresh_batch_succeeded' ] + $result ) );
		return true;
	}

	public function workItemCount() { return $this->processedJobs; }

	public function allowRetries(): bool { return $this->allowQueueRetries; }

	protected function now(): int { return time(); }

	protected function newSearchEngine(): FrauxSearchEngine {
		return new FrauxSearchEngine();
	}

	public function run(): bool {
		$this->allowQueueRetries = true;
		$this->error = '';
		$pageId = (int)( $this->params['pageId'] ?? 0 );
		$attempt = max( 0, (int)( $this->params['retryAttempt'] ?? 0 ) );
		$redirectTitle = isset( $this->params['redirectTitle'] )
			? (string)$this->params['redirectTitle']
			: null;
		if ( $pageId <= 0 ) {
			return true;
		}
		if ( ( $this->getReleaseTimestamp() ?? 0 ) > $this->now() ) {
			$this->error = 'FrauxSearch refresh retry is not due yet.';
			$this->log( 'refresh_retry_not_due', $pageId, $attempt, null, $this->getReleaseTimestamp() );
			return false;
		}
		$search = $this->newSearchEngine();
		try {
			if ( !$this->processBatch() ) { $search->refreshPageNow( $pageId, $redirectTitle ); }
		} catch ( RuntimeException $e ) {
			if ( MeilisearchException::isRetryableThrowable( $e ) ) {
				if ( !isset( self::RETRY_DELAYS[$attempt] ) ) {
					$this->allowQueueRetries = false;
					$this->error = 'Meilisearch retry limit reached: ' . $e->getMessage();
					$this->log( 'refresh_retry_exhausted', $pageId, $attempt, $e->getMessage() );
					return false;
				}
				$queues = MediaWikiServices::getInstance()->getJobQueueGroup();
				if ( !$queues->get( 'frauxSearchRefreshPage' )->delayedJobsEnabled() ) {
					$this->error = 'Meilisearch refresh awaits native retry or journal recovery: ' . $e->getMessage();
					$this->log( 'refresh_retry_deferred', $pageId, $attempt, $e->getMessage() );
					return false;
				}
				$nextAttempt = $attempt + 1;
				$delay = self::RETRY_DELAYS[$attempt];
				$params = $this->params;
				$params['retryAttempt'] = $nextAttempt;
				$params['jobReleaseTimestamp'] = $this->now() + $delay + random_int( 0, intdiv( $delay, 4 ) );
				$queues->push( new self( $params ) );
				$this->log( 'refresh_retry_queued', $pageId, $nextAttempt, $e->getMessage(), $params['jobReleaseTimestamp'] );
				return true;
			}
			$this->allowQueueRetries = false;
			$this->log( 'refresh_failed', $pageId, $attempt, $e->getMessage() );
			throw $e;
		}
		$this->log( 'refresh_succeeded', $pageId, $attempt );
		return true;
	}

	private function log( string $event, int $pageId, int $attempt, ?string $error = null, ?int $retryAt = null ): void {
		$context = [
			'event' => $event,
			'page_id' => $pageId,
			'attempt' => $attempt,
			'operation' => 'refresh',
		];
		if ( $error !== null ) {
			$context['error'] = $error;
		}
		if ( $retryAt !== null ) { $context['retry_at'] = $retryAt; }
		wfDebugLog( 'FrauxSearch', json_encode( $context, JSON_UNESCAPED_SLASHES ) );
	}
}

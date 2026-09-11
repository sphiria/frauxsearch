<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchException;
use FrauxSearch\ScheduleBoostRefreshesJob;
use FrauxSearch\ScheduleIncomingRefreshesJob;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class RefreshPageJobTest extends TestCase {
	private RetryJobServices $services;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/retry.php';
		$this->services = new RetryJobServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	private function job( array $params = [] ): ClockedRefreshPageJob {
		return new ClockedRefreshPageJob( $params + [ 'pageId' => 42, 'identityEvent' => 'target-move' ] );
	}

	private function runLikeCore( $job ): bool {
		$this->services->database->explicitTransaction = !$job->hasExecutionFlag( $job::JOB_NO_EXPLICIT_TRX_ROUND );
		$status = $job->run();
		$this->services->database->explicitTransaction = false;
		$this->services->database->commit();
		return $status;
	}

	public function testRefreshJobWorksBeforeFrauxSearchIsTheSelectedBackend(): void {
		$job = $this->job( [ 'redirectTitle' => 'Moved before cutover' ] );
		$this->assertTrue( $this->runLikeCore( $job ) );
		$this->assertSame( [ [ 42, 'Moved before cutover' ] ], $this->services->refreshes );
		$this->assertSame( [], $this->services->searchEngineRequests );
		$this->assertSame( [], $this->services->database->callbacks );
		$this->assertSame( [], $this->services->queue->jobs );
	}

	public function testNativeDatabaseRetryCatchesTheActualRefreshBeforeRunnerCommit(): void {
		$this->services->failure = new MeilisearchException( 'busy writer', true );
		$job = $this->job( [ 'redirectTitle' => 'Moved page', 'policyEvent' => 'policy-change' ] );
		$original = $job->getParams();
		$this->assertFalse( $this->runLikeCore( $job ) );
		$this->assertTrue( $job->allowRetries() );
		$this->assertSame( [ [ 42, 'Moved page' ] ], $this->services->refreshes );
		$this->assertSame( [], $this->services->queue->jobs );
		$this->assertSame( $original, $job->getParams() );
		$this->assertSame( [], $this->services->database->callbacks );
		$this->assertStringContainsString( 'native retry or journal recovery', $job->getLastError() );
	}

	public function testReadOnlySnapshotIsClearedBeforeTheJobHandlesRefreshFailure(): void {
		$this->services->database->implicitReadSnapshot = true;
		$this->services->failure = new MeilisearchException( 'writer busy after existing read snapshot', true );
		$job = $this->job();
		$this->assertFalse( $this->runLikeCore( $job ) );
		$this->assertSame( [ [ 42, null ] ], $this->services->refreshes );
		$this->assertSame( [], $this->services->database->callbacks );
		$this->assertSame( 1, $this->services->database->snapshotFlushes );
		$this->assertFalse( $this->services->database->implicitReadSnapshot );
		$this->assertStringContainsString( 'native retry or journal recovery', $job->getLastError() );
	}

	public function testNormalSourceRefreshWaitsForExistingReadTransactionCommit(): void {
		$this->services->database->implicitReadSnapshot = true;
		( new RetryJobEngine( $this->services ) )->refreshPage( 42 );
		$this->assertSame( [], $this->services->refreshes );
		$this->assertCount( 1, $this->services->database->callbacks );
		$this->services->database->commit();
		$this->assertSame( [ [ 42, null ] ], $this->services->refreshes );
	}

	public function testSynchronousRefreshRefusesUncommittedSourceWrites(): void {
		$this->services->database->pendingWrites = true;
		try {
			( new RetryJobEngine( $this->services ) )->refreshPageNow( 42 );
			$this->fail( 'Pending source writes must not be committed by a synchronous refresh.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Cannot flush snapshot', $e->getMessage() );
			$this->assertTrue( $this->services->database->pendingWrites );
			$this->assertSame( [], $this->services->refreshes );
			$this->assertSame( 0, $this->services->database->snapshotFlushes );
		}
	}

	public function testBothSchedulersScanOutsideExplicitJobTransactions(): void {
		$incoming = new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Moved_page' ] );
		$boost = new ScheduleBoostRefreshesJob( [ 'allPages' => true, 'policyEvent' => 'policy-change' ] );
		$this->assertTrue( $this->runLikeCore( $incoming ) );
		$this->assertTrue( $this->runLikeCore( $boost ) );
		$this->assertSame( 3, $this->services->database->queries );
		$this->assertSame( [], $this->services->queue->jobs );
	}

	public function testDelayedBackendSpacesFiveRetriesAndPreservesEventIdentity(): void {
		$this->services->queue->delayed = true;
		$this->services->failure = new MeilisearchException( 'temporary task failure', true );
		$params = [ 'pageId' => 42, 'identityEvent' => 'target-move', 'policyEvent' => 'policy-change',
			'dependencyEvent' => 'removed-link', 'redirectTitle' => 'Previous title' ];
		$now = 1000;
		foreach ( [ 30, 60, 120, 240, 480 ] as $attempt => $minimumDelay ) {
			$job = $this->job( $params );
			$job->clock = $now;
			$this->assertTrue( $this->runLikeCore( $job ) );
			$retry = $this->services->queue->jobs[$attempt];
			$params = $retry->getParams();
			$this->assertSame( $attempt + 1, $params['retryAttempt'] );
			$this->assertSame( 'target-move', $params['identityEvent'] );
			$this->assertSame( 'policy-change', $params['policyEvent'] );
			$this->assertSame( 'removed-link', $params['dependencyEvent'] );
			$this->assertSame( 'Previous title', $params['redirectTitle'] );
			$this->assertGreaterThanOrEqual( $now + $minimumDelay, $retry->getReleaseTimestamp() );
			$this->assertLessThanOrEqual( $now + (int)( $minimumDelay * 1.25 ), $retry->getReleaseTimestamp() );
			$now = $retry->getReleaseTimestamp();
		}
		$last = $this->job( $params );
		$last->clock = $now;
		$this->assertFalse( $this->runLikeCore( $last ) );
		$this->assertFalse( $last->allowRetries() );
		$this->assertCount( 5, $this->services->queue->jobs );
		$this->assertStringContainsString( 'retry limit reached', $last->getLastError() );
	}

	public function testBackendSwitchCannotExecuteFutureRetryEarlyOrRequeueItImmediately(): void {
		$job = $this->job( [ 'retryAttempt' => 1, 'jobReleaseTimestamp' => 1030 ] );
		$this->assertFalse( $this->runLikeCore( $job ) );
		$this->assertTrue( $job->allowRetries() );
		$this->assertSame( [], $this->services->refreshes );
		$this->assertSame( [], $this->services->queue->jobs );
		$job->clock = 1030;
		$this->assertTrue( $this->runLikeCore( $job ) );
		$this->assertSame( [ [ 42, null ] ], $this->services->refreshes );
	}

	public function testBackendSwitchCannotResetAnExhaustedExplicitRetryBudget(): void {
		$this->services->failure = new MeilisearchException( 'still unavailable', true );
		$job = $this->job( [ 'retryAttempt' => 5 ] );
		$this->assertFalse( $this->runLikeCore( $job ) );
		$this->assertFalse( $job->allowRetries() );
		$this->assertSame( [], $this->services->queue->jobs );
	}

	public function testUnknownMutationOutcomeIsReportedWithoutAutomaticRetry(): void {
		$this->services->failure = new RuntimeException( 'FrauxSearch operation has an unknown outcome' );
		$job = $this->job();
		try {
			$this->runLikeCore( $job );
			$this->fail( 'Expected the recorded unknown outcome to stop this job.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'unknown outcome', $e->getMessage() );
			$this->assertFalse( $job->allowRetries() );
			$this->assertSame( [], $this->services->queue->jobs );
		}
	}

	public function testFailedRetryEnqueueRetainsOriginalJobAndItsNativeRetryEligibility(): void {
		$this->services->queue->delayed = true;
		$this->services->queue->failPush = true;
		$this->services->failure = new MeilisearchException( 'temporary read failure', true );
		$job = $this->job();
		try {
			$this->runLikeCore( $job );
			$this->fail( 'Expected queue failure.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'queue unavailable', $e->getMessage() );
			$this->assertTrue( $job->allowRetries() );
			$this->assertSame( [ 'pageId' => 42, 'identityEvent' => 'target-move' ], $job->getParams() );
			$this->assertSame( [], $this->services->queue->jobs );
		}
	}

	public function testSuccessfulAndNonpositivePageJobsDoNotScheduleRetries(): void {
		$this->assertTrue( $this->runLikeCore( $this->job() ) );
		$this->assertTrue( $this->runLikeCore( $this->job( [ 'pageId' => 0 ] ) ) );
		$this->assertTrue( $this->runLikeCore( $this->job( [ 'pageId' => -1 ] ) ) );
		$this->assertSame( [ [ 42, null ] ], $this->services->refreshes );
		$this->assertSame( [], $this->services->queue->jobs );
	}
}

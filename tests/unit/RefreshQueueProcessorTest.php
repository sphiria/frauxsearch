<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RefreshPageJob;
use FrauxSearch\RefreshQueueProcessor;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class RefreshQueueProcessorTest extends TestCase {
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
	}

	public function testEventsCoalesceButMovesAndRetryBudgetsRemainDistinct(): void {
		$a = new RefreshPageJob( [ 'pageId' => 1, 'dependencyEvent' => 'a', 'requestId' => 'one' ] );
		$b = new RefreshPageJob( [ 'pageId' => 1, 'identityEvent' => 'b', 'sourceEvent' => 'c', 'policyEvent' => 'd' ] );
		$this->assertSame( $a->getDeduplicationInfo(), $b->getDeduplicationInfo() );
		foreach ( [ [ 'pageId' => 2 ], [ 'redirectTitle' => 'Old name' ], [ 'retryAttempt' => 2 ] ] as $change ) {
			$c = new RefreshPageJob( $change + [ 'pageId' => 1 ] );
			$this->assertNotSame( $a->getDeduplicationInfo(), $c->getDeduplicationInfo() );
		}
	}

	public function testClaimsBeforeReadsAndAcknowledgesOnlyTheClaimedSnapshot(): void {
		$seed = new RefreshPageJob( [ 'pageId' => 1 ] );
		$second = new RefreshPageJob( [ 'pageId' => 1, 'redirectTitle' => 'Old name' ] );
		$third = new RefreshPageJob( [ 'pageId' => 2 ] );
		$later = new RefreshPageJob( [ 'pageId' => 1 ] );
		$waiting = [ $second, $third ];
		$acked = [];
		$processor = new RefreshQueueProcessor(
			static function () use ( &$waiting ) { return array_shift( $waiting ); },
			static function ( $job ) use ( &$acked ) { $acked[] = $job; },
			function ( $claim ) use ( &$waiting, &$acked, $later ) {
				$requests = $claim( static function () {} );
				$this->assertSame( [], $waiting );
				$this->assertSame( [], $acked );
				$this->assertSame( [ 1, 1, 2 ], array_column( $requests, 'pageId' ) );
				$this->assertSame( 'Old name', $requests[1]['redirectTitle'] );
				$waiting[] = $later;
				return 2;
			} );
		$this->assertSame( [ 'jobs' => 3, 'pages' => 2 ], $processor->run( 3, $seed ) );
		$this->assertSame( [ $second, $third ], $acked );
		$this->assertSame( [ $later ], $waiting );
	}

	public function testFailureLeavesEveryClaimUnacknowledged(): void {
		$waiting = [ new RefreshPageJob( [ 'pageId' => 1 ] ), new RefreshPageJob( [ 'pageId' => 2 ] ) ];
		$acked = [];
		$processor = new RefreshQueueProcessor(
			static function () use ( &$waiting ) { return array_shift( $waiting ); },
			static function ( $job ) use ( &$acked ) { $acked[] = $job; },
			static function ( $claim ) {
				$claim( static function () {} );
				throw new RuntimeException( 'task failed' );
			} );
		try {
			$processor->run( 2 );
			$this->fail( 'Expected failed batch' );
		} catch ( RuntimeException $e ) { $this->assertSame( 'task failed', $e->getMessage() ); }
		$this->assertSame( [], $acked );
		$this->assertSame( [], $waiting );
	}

	public function testBusyWriterDoesNotClaimAdditionalJobs(): void {
		$processor = new RefreshQueueProcessor(
			function () { $this->fail( 'Busy writer claimed a job.' ); },
			function () { $this->fail( 'Busy writer acknowledged a job.' ); },
			static function () { throw new RuntimeException( 'writer busy' ); } );
		$this->expectExceptionMessage( 'writer busy' );
		$processor->run( 200, new RefreshPageJob( [ 'pageId' => 1 ] ) );
	}

	public function testInvalidLimitDoesNotClaimAnything(): void {
		$processor = new RefreshQueueProcessor(
			function () { $this->fail( 'Unexpected claim' ); }, static function () {}, static fn () => 0 );
		$this->expectException( RuntimeException::class );
		$processor->run( 501 );
	}
}

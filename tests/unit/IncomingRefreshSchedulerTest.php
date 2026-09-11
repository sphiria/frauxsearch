<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RefreshPageJob;
use FrauxSearch\ScheduleIncomingRefreshesJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class IncomingRefreshSchedulerTest extends TestCase {
	private function services( int $sourceCount = 0 ): IncomingSchedulerServices {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/incoming.php';
		$database = new IncomingTestDatabase();
		$database->linktargets = [ [ 'lt_id' => 1, 'lt_namespace' => 0, 'lt_title' => 'Target' ] ];
		for ( $id = 1; $id <= $sourceCount; $id++ ) {
			$database->pagelinks[] = [ 'pl_from' => $id, 'pl_target_id' => 1 ];
		}
		$services = new IncomingSchedulerServices( $database );
		\MediaWiki\MediaWikiServices::$instance = $services;
		return $services;
	}

	public function testSchedulerQueuesBoundedSourcesBeforeContinuationWithSameEvent(): void {
		$services = $this->services( 1001 );
		$job = new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target', 'identityEvent' => 'change-1' ] );
		$this->assertTrue( $job->run() );
		$this->assertSame( [ 1000, 1 ], array_map( 'count', $services->pushes ) );
		$continuation = $services->pushes[1][0];
		$this->assertInstanceOf( ScheduleIncomingRefreshesJob::class, $continuation );
		$this->assertSame( [ 'namespace' => 0, 'title' => 'Target', 'identityEvent' => 'change-1',
			'startAfter' => 1000 ], $continuation->getParams() );
		$this->assertTrue( $continuation->run() );
		$sourceJobs = array_merge( $services->pushes[0], $services->pushes[2] );
		$this->assertSame( range( 1, 1001 ), array_map( static fn ( $job ) => $job->getParams()['pageId'], $sourceJobs ) );
		$this->assertSame( [ 'change-1' ], array_values( array_unique( array_map(
			static fn ( $job ) => $job->getParams()['identityEvent'], $sourceJobs
		) ) ) );
		$this->assertContainsOnlyInstancesOf( RefreshPageJob::class, $sourceJobs );
		$this->assertSame( 2, $services->database->snapshotFlushes );
		$this->assertCount( 4, $services->database->queries );
		foreach ( $services->database->queries as $query ) {
			$this->assertSame( 1000, $query->rowLimit );
		}
	}

	public function testGeneratedEventIsSerializedAndSurvivesRetries(): void {
		$services = $this->services( 1 );
		$first = new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target' ] );
		$event = $first->getParams()['identityEvent'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $event );
		$retry = new ScheduleIncomingRefreshesJob( $first->getParams() );
		$first->run();
		$retry->run();
		$this->assertSame( $services->pushes[0][0]->getParams(), $services->pushes[1][0]->getParams() );
		$this->assertSame( $event, $services->pushes[0][0]->getParams()['identityEvent'] );
	}

	public function testEmptyBatchDoesNotQueueSourceOrContinuation(): void {
		$services = $this->services();
		$this->assertTrue( ( new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target' ] ) )->run() );
		$this->assertSame( [], $services->pushes );
	}

	public static function invalidParameters(): array {
		return [
			'missing namespace' => [ [ 'title' => 'Target' ] ],
			'negative namespace' => [ [ 'namespace' => -1, 'title' => 'Target' ] ],
			'noninteger namespace' => [ [ 'namespace' => '0', 'title' => 'Target' ] ],
			'missing title' => [ [ 'namespace' => 0 ] ],
			'empty title' => [ [ 'namespace' => 0, 'title' => '' ] ],
			'nonstring title' => [ [ 'namespace' => 0, 'title' => [ 'Target' ] ] ],
			'negative cursor' => [ [ 'namespace' => 0, 'title' => 'Target', 'startAfter' => -1 ] ],
			'noninteger cursor' => [ [ 'namespace' => 0, 'title' => 'Target', 'startAfter' => '1' ] ],
			'null cursor' => [ [ 'namespace' => 0, 'title' => 'Target', 'startAfter' => null ] ],
			'empty event' => [ [ 'namespace' => 0, 'title' => 'Target', 'identityEvent' => '' ] ],
			'null event' => [ [ 'namespace' => 0, 'title' => 'Target', 'identityEvent' => null ] ],
		];
	}

	#[DataProvider( 'invalidParameters' )]
	public function testMalformedArgumentsFailBeforeReadingOrQueuing( array $params ): void {
		$services = $this->services();
		try {
			( new ScheduleIncomingRefreshesJob( $params ) )->run();
			$this->fail( 'Malformed scheduler parameters should fail.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [], $services->database->queries );
			$this->assertSame( 0, $services->database->snapshotFlushes );
			$this->assertSame( [], $services->pushes );
		}
	}

	public static function nonIdleTransactions(): array {
		return [ 'pending writes' => [ 'pendingWrites' ], 'pending callbacks' => [ 'pendingCallbacks' ],
			'explicit transaction' => [ 'explicitTransaction' ] ];
	}

	#[DataProvider( 'nonIdleTransactions' )]
	public function testActivePrimaryTransactionsAreNotCommittedByScheduler( string $property ): void {
		$services = $this->services();
		$services->database->$property = true;
		try {
			( new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target' ] ) )->run();
			$this->fail( 'Scheduler must defer to transaction ownership.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( $property === 'explicitTransaction' ? 'idle primary' : 'Cannot flush snapshot',
				$e->getMessage() );
			$this->assertSame( 0, $services->database->snapshotFlushes );
			$this->assertSame( [], $services->database->queries );
		}
	}

	public function testSourcePushFailureCannotAdvanceTheCursor(): void {
		$services = $this->services( 1001 );
		$services->failPushNumber = 1;
		try {
			( new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target' ] ) )->run();
			$this->fail( 'Queue failure must propagate for retry.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Queue unavailable', $e->getMessage() );
			$this->assertSame( 1, $services->pushAttempts );
			$this->assertSame( [], $services->pushes );
		}
	}

	public function testContinuationFailureRetriesTheSameBatchAndEvent(): void {
		$services = $this->services( 1001 );
		$services->failPushNumber = 2;
		$job = new ScheduleIncomingRefreshesJob( [ 'namespace' => 0, 'title' => 'Target' ] );
		try {
			$job->run();
			$this->fail( 'Continuation push failure must propagate for retry.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Queue unavailable', $e->getMessage() );
			$this->assertCount( 1, $services->pushes );
		}
		$services->failPushNumber = null;
		( new ScheduleIncomingRefreshesJob( $job->getParams() ) )->run();
		$this->assertEquals( $services->pushes[0], $services->pushes[1] );
		$this->assertInstanceOf( ScheduleIncomingRefreshesJob::class, $services->pushes[2][0] );
		$this->assertSame( 1000, $services->pushes[2][0]->getParams()['startAfter'] );
	}
}

class IncomingSchedulerServices {
	public array $pushes = [];
	public int $pushAttempts = 0;
	public ?int $failPushNumber = null;
	public function __construct( public IncomingTestDatabase $database ) {}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): IncomingTestDatabase { return $this->database; }
	public function getJobQueueGroup(): self { return $this; }
	public function push( $jobs ): void {
		$this->pushAttempts++;
		if ( $this->pushAttempts === $this->failPushNumber ) {
			throw new \RuntimeException( 'Queue unavailable' );
		}
		$this->pushes[] = is_array( $jobs ) ? $jobs : [ $jobs ];
	}
}

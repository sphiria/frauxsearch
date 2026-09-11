<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBatchBuilder;
use FrauxSearch\DocumentBuilder;
use FrauxSearch\DocumentHash;
use FrauxSearch\IncomingLinkCounter;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class DocumentBatchBuilderTest extends TestCase {
	private string $log;
	private string|false $oldInstall;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/documentBatch/services.php';
		define( 'MW_INSTALL_PATH', dirname( __DIR__ ) . '/fixtures/documentBatch' );
		$this->oldInstall = getenv( 'MW_INSTALL_PATH' );
		putenv( 'MW_INSTALL_PATH=' . MW_INSTALL_PATH );
		$this->log = tempnam( sys_get_temp_dir(), 'fraux-batch-test-' );
		putenv( 'FRAUX_BATCH_TEST_LOG=' . $this->log );
		putenv( 'FRAUX_BATCH_TEST_MODE=valid' );
		MediaWikiServices::$instance = new BatchServices();
	}

	protected function tearDown(): void {
		foreach ( $this->evidence() as $row ) {
			$this->assertFileDoesNotExist( $row['request']['result'] );
		}
		if ( is_file( $this->log . '.events' ) ) { unlink( $this->log . '.events' ); }
		unlink( $this->log );
		putenv( 'FRAUX_BATCH_TEST_LOG' );
		putenv( 'FRAUX_BATCH_TEST_MODE' );
		putenv( $this->oldInstall === false ? 'MW_INSTALL_PATH' : 'MW_INSTALL_PATH=' . $this->oldInstall );
	}

	public function testEmptyInputAndPreflightDoNotAccessMediaWikiServicesOrStartChildren(): void {
		MediaWikiServices::$instance = null;
		DocumentBatchBuilder::assertAvailable();
		$this->assertSame( [], DocumentBatchBuilder::build( [] ) );
		$this->assertSame( [], $this->evidence() );
	}

	public function testFreshProcessesReturnEveryCompleteDocumentAndExplicitNull(): void {
		$results = DocumentBatchBuilder::build( range( 1, 205 ) );
		$this->assertSame( range( 1, 205 ), array_keys( $results ) );
		$this->assertNull( $results[2] );
		$this->assertTrue( $results[3]['is_redirect'] );
		$this->assertSame( 4, $results[3]['redirect_target_id'] );
		$expected = new DocumentBuilder( true, array_fill_keys( range( 1, 205 ), 9 ),
			array_fill_keys( range( 1, 205 ), [ 900, 901 ] ) );
		$this->assertSame( $expected->build( 205 ), $results[205] );
		$this->assertSame( DocumentHash::compute( $results[205]['document'] ), $results[205]['document']['document_hash'] );
		$children = $this->evidence();
		$this->assertSame( [ 100, 100, 5 ], array_map( static fn ( $row ) => count( $row['request']['ids'] ), $children ) );
		$this->assertCount( 3, array_unique( array_column( $children, 'pid' ) ) );
		$this->assertSame( [ 0600, 0600, 0600 ], array_column( $children, 'permissions' ) );
	}

	public function testParallelChildrenOverlapWithinLimitAndReturnInputOrder(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=parallel' );
		$results = DocumentBatchBuilder::build( range( 1, 305 ), [ 'render-workers' => 2 ] );
		$this->assertSame( range( 1, 305 ), array_keys( $results ) );
		$this->assertNull( $results[2] );
		$active = 0;
		$peak = 0;
		$finished = [];
		foreach ( file( $this->log . '.events', FILE_IGNORE_NEW_LINES ) as $line ) {
			$event = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
			$active += $event['phase'] === 'start' ? 1 : -1;
			$peak = max( $peak, $active );
			if ( $event['phase'] === 'end' ) { $finished[] = $event['id']; }
		}
		$this->assertSame( 2, $peak );
		$this->assertSame( 0, $active );
		$this->assertSame( 101, $finished[0] );
		$this->assertCount( 4, $this->evidence() );
		foreach ( $this->evidence() as $child ) {
			$this->assertFileDoesNotExist( '/proc/' . $child['pid'] );
		}
	}

	public function testRenderingContinuesWhileCompletedBatchIsConsumed(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=parallel' );
		$consumed = [];
		$results = DocumentBatchBuilder::build( range( 1, 200 ), [ 'render-workers' => 2 ], null,
			function ( array $batch ) use ( &$consumed ): void {
				$consumed[] = array_key_first( $batch );
				if ( count( $consumed ) === 1 ) {
					$this->assertSame( 101, $consumed[0] );
					usleep( 600000 );
					$events = array_map( static fn ( $line ) => json_decode( $line, true ),
						file( $this->log . '.events', FILE_IGNORE_NEW_LINES ) );
					$this->assertNotEmpty( array_filter( $events,
						static fn ( $event ) => $event['id'] === 1 && $event['phase'] === 'end' ) );
				}
			}
		);
		$this->assertSame( [ 101, 1 ], $consumed );
		$this->assertSame( range( 1, 200 ), array_keys( $results ) );
	}

	public function testConsumerFailureKillsAllRemainingRenderers(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=parallel' );
		$error = new RuntimeException( 'Meilisearch write failed.' );
		try {
			DocumentBatchBuilder::build( range( 1, 200 ), [ 'render-workers' => 2 ], null,
				static function () use ( $error ): void { throw $error; } );
			$this->fail( 'Consumer failure was ignored.' );
		} catch ( RuntimeException $caught ) {
			$this->assertSame( $error, $caught );
			foreach ( $this->evidence() as $child ) {
				$this->assertFileDoesNotExist( '/proc/' . $child['pid'] );
			}
		}
	}

	public function testSmallBatchUsesAllRequestedWorkers(): void {
		$results = DocumentBatchBuilder::build( range( 1, 200 ), [ 'render-workers' => 8 ] );
		$this->assertSame( range( 1, 200 ), array_keys( $results ) );
		$this->assertCount( 8, $this->evidence() );
		foreach ( $this->evidence() as $child ) {
			$this->assertCount( 25, $child['request']['ids'] );
		}
	}

	public function testFailedParallelChildKillsAndReapsItsRunningSibling(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=parallel-failure' );
		$started = hrtime( true );
		try {
			DocumentBatchBuilder::build( range( 1, 205 ), [ 'render-workers' => 2 ] );
			$this->fail( 'Failed worker was ignored.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'exit status 23', $error->getMessage() );
			$this->assertLessThan( 10, ( hrtime( true ) - $started ) / 1e9 );
			$this->assertCount( 2, $this->evidence() );
			foreach ( $this->evidence() as $child ) {
				$this->assertFileDoesNotExist( '/proc/' . $child['pid'] );
			}
		}
	}

	#[DataProvider( 'invalidWorkers' )]
	public function testInvalidWorkerLimitFailsBeforeLaunching( mixed $workers ): void {
		$this->expectExceptionMessage( '--render-workers must be an integer between 1 and 8.' );
		DocumentBatchBuilder::build( [ 1 ], [ 'render-workers' => $workers ] );
	}

	public static function invalidWorkers(): array {
		return [ [ 0 ], [ -1 ], [ 9 ], [ '2x' ], [ 1.5 ], [ [] ] ];
	}

	public function testConfigurationAndEffectiveMemoryLimitReachTheChildWithoutShellParsing(): void {
		$results = DocumentBatchBuilder::build( [ 1 ], [ 'conf' => __FILE__, 'wiki' => 'name-prefix',
			'server' => 'https://wiki.invalid/a?b=$(false)', 'dbgroupdefault' => 'maintenance',
			'memory-limit' => 'WRONG', 'batch-size' => 999, 'no-reset' => true ] );
		$this->assertNotNull( $results[1] );
		$child = $this->evidence()[0];
		$this->assertSame( PHP_BINARY, $child['binary'] );
		$this->assertSame( [ MW_INSTALL_PATH . '/maintenance/run.php',
			dirname( __DIR__, 2 ) . '/maintenance/renderFrauxSearchBatch.php',
			'--conf', realpath( __FILE__ ), '--wiki', 'name-prefix',
			'--server', 'https://wiki.invalid/a?b=$(false)', '--dbgroupdefault', 'maintenance',
			'--memory-limit', (string)ini_get( 'memory_limit' ) ], $child['argv'] );
	}

	#[DataProvider( 'invalidIds' )]
	public function testInvalidPageIdsFailBeforeLaunching( array $ids ): void {
		$this->expectException( RuntimeException::class );
		DocumentBatchBuilder::build( $ids );
	}

	public static function invalidIds(): array {
		return [ [ [ 0 ] ], [ [ -1 ] ], [ [ '1' ] ], [ [ 1, 1 ] ], [ [ 1.0 ] ] ];
	}

	#[DataProvider( 'invalidResults' )]
	public function testFailedOrCorruptChildCannotReturnDocuments( string $mode ): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=' . $mode );
		try {
			DocumentBatchBuilder::build( [ 1, 2, 3 ] );
			$this->fail( 'Invalid renderer result was accepted: ' . $mode );
		} catch ( RuntimeException | \JsonException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
			$this->assertCount( 1, $this->evidence() );
		}
	}

	public static function invalidResults(): array {
		return array_map( static fn ( $mode ) => [ $mode ], [
			'exit-failure', 'killed', 'truncated', 'oversize', 'missing', 'extra', 'nonce',
			'source', 'ids', 'hash', 'partial', 'redirect',
		] );
	}

	public function testLaterFailedChunkDoesNotReturnAnEarlierSuccessfulChunk(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=second-failure' );
		try {
			DocumentBatchBuilder::build( range( 1, 101 ) );
			$this->fail( 'Partial result escaped.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( '23', $error->getMessage() );
			$this->assertCount( 2, $this->evidence() );
		}
	}

	public function testHeartbeatFailureKillsAndReapsChildAndPreservesTheFailure(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=wait' );
		$error = new RuntimeException( 'Lease was lost.' );
		$calls = 0;
		$started = hrtime( true );
		try {
			DocumentBatchBuilder::build( range( 1, 101 ), [ 'render-workers' => 2 ],
				static function () use ( &$calls, $error ): void {
					if ( ++$calls === 3 ) { throw $error; }
				}
			);
			$this->fail( 'Lost ownership was ignored.' );
		} catch ( RuntimeException $caught ) {
			$this->assertSame( $error, $caught );
			$this->assertSame( 3, $calls );
			$this->assertCount( 2, $this->evidence() );
			$this->assertFileDoesNotExist( '/proc/' . $this->evidence()[1]['pid'] );
			$this->assertLessThan( 12, ( hrtime( true ) - $started ) / 1e9 );
			$this->assertFileDoesNotExist( '/proc/' . $this->evidence()[0]['pid'] );
		}
	}

	public function testHeartbeatChecksBeforeLaunchAndAfterSuccessfulExit(): void {
		$calls = 0;
		DocumentBatchBuilder::build( [ 1 ], [], static function () use ( &$calls ): void { $calls++; } );
		$this->assertSame( 2, $calls );
		try {
			DocumentBatchBuilder::build( [ 1 ], [], static function (): void { throw new RuntimeException( 'Before launch' ); } );
			$this->fail( 'Failed initial heartbeat was ignored.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Before launch', $error->getMessage() );
			$this->assertCount( 1, $this->evidence() );
		}
	}

	public function testFailedFinalHeartbeatDoesNotReturnCompletedDocuments(): void {
		$calls = 0;
		$error = new RuntimeException( 'Ownership expired after rendering.' );
		try {
			DocumentBatchBuilder::build( [ 1 ], [], static function () use ( &$calls, $error ): void {
				if ( ++$calls === 2 ) { throw $error; }
			} );
			$this->fail( 'Documents returned after lease loss.' );
		} catch ( RuntimeException $caught ) {
			$this->assertSame( $error, $caught );
			$this->assertFileDoesNotExist( '/proc/' . $this->evidence()[0]['pid'] );
		}
	}

	public function testFailureDiagnosticIsBoundedPrivateAndNotExposedInException(): void {
		putenv( 'FRAUX_BATCH_TEST_MODE=stderr' );
		try {
			DocumentBatchBuilder::build( [ 1, 2, 3 ] );
			$this->fail( 'Child failure was ignored.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'page IDs 1–3 with exit status 23', $error->getMessage() );
			$this->assertStringNotContainsString( 'private-source-detail', $error->getMessage() );
			$path = explode( 'Private diagnostic: ', $error->getMessage(), 2 )[1];
			try {
				$this->assertSame( 0600, fileperms( $path ) & 0777 );
				$this->assertSame( 65536, filesize( $path ) );
				$this->assertStringStartsWith( 'private-source-detail', file_get_contents( $path ) );
			} finally { unlink( $path ); }
		}
	}

	public function testUnsupportedCredentialOverrideFailsBeforeChild(): void {
		$this->expectExceptionMessage( 'credentials in configuration' );
		DocumentBatchBuilder::assertAvailable( [ 'dbpass' => 'never-print-this' ] );
	}

	public function testWorkerPreloadsBothLinkMapsFromPrimaryOnceAndPreservesNull(): void {
		$path = tempnam( sys_get_temp_dir(), 'fraux-worker-test-' );
		chmod( $path, 0600 );
		try {
			DocumentBatchBuilder::render( $this->request( $path ) );
			$data = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame( [ [ 'counts', [ 1, 2, 3 ] ], [ 'outgoing', [ 1, 2, 3 ] ] ], IncomingLinkCounter::$calls );
			$this->assertSame( [ 1, 2, 3 ], DocumentBuilder::$ids );
			$this->assertSame( 1, MediaWikiServices::getInstance()->snapshots );
			$this->assertSame( [ 1, 2, 3 ], array_keys( $data['results'] ) );
			$this->assertNull( $data['results'][2] );
		} finally { unlink( $path ); }
	}

	#[DataProvider( 'invalidRequests' )]
	public function testWorkerRejectsWrongSourceRangeOrUnsafeResultBeforeBuilding( string $mode ): void {
		$path = tempnam( sys_get_temp_dir(), 'fraux-worker-test-' );
		chmod( $path, 0600 );
		$request = $this->request( $path );
		if ( $mode === 'source' ) { $request['source'] = 'different-domain'; }
		if ( $mode === 'range' ) { $request['ids'] = range( 1, 101 ); }
		if ( $mode === 'permissions' ) { chmod( $path, 0644 ); }
		if ( $mode === 'nonempty' ) { file_put_contents( $path, 'existing' ); }
		try {
			DocumentBatchBuilder::render( $request );
			$this->fail( 'Unsafe request accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( [], DocumentBuilder::$ids );
			$this->assertSame( [], IncomingLinkCounter::$calls );
		} finally { unlink( $path ); }
	}

	public static function invalidRequests(): array { return [ [ 'source' ], [ 'range' ], [ 'permissions' ], [ 'nonempty' ] ]; }

	private function request( string $path ): array {
		return [ 'version' => 1, 'ids' => [ 1, 2, 3 ], 'nonce' => str_repeat( 'a', 32 ), 'result' => realpath( $path ),
			'source' => hash( 'sha256', json_encode( [ 'mysql', 'fixture-db', 'fixture-wiki-prefix' ] ) ) ];
	}

	private function evidence(): array {
		return array_map( static fn ( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ),
			file( $this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
	}
}

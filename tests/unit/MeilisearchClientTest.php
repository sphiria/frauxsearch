<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\SoftwareInfoHooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MeilisearchClientTest extends TestCase {
	private static $server;
	private static int $port;
	private static string $serverLog;

	public static function setUpBeforeClass(): void {
		self::$port = random_int( 20000, 40000 );
		self::$serverLog = tempnam( sys_get_temp_dir(), 'frauxsearch-http-' );
		$command = [
			PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname( __DIR__ ) . '/fixtures/router.php',
		];
		self::$server = proc_open( $command, [
			0 => [ 'file', '/dev/null', 'r' ],
			1 => [ 'file', self::$serverLog, 'a' ],
			2 => [ 'file', self::$serverLog, 'a' ],
		], $pipes );
		$probe = curl_init( 'http://127.0.0.1:' . self::$port . '/fixture-ready' );
		curl_setopt_array( $probe, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200 ] );
		$deadline = hrtime( true ) + 5_000_000_000;
		do {
			if ( !is_resource( self::$server ) || !proc_get_status( self::$server )['running'] ) { break; }
			if ( curl_exec( $probe ) === '{"fixture":"frauxsearch-http"}' ) { return; }
			usleep( 20000 );
		} while ( hrtime( true ) < $deadline );
		$message = file_get_contents( self::$serverLog );
		self::tearDownAfterClass();
		throw new \RuntimeException( 'FrauxSearch HTTP fixture did not become ready: ' . $message );
	}

	public static function tearDownAfterClass(): void {
		if ( is_resource( self::$server ) ) {
			proc_terminate( self::$server );
			proc_close( self::$server );
		}
		if ( isset( self::$serverLog ) && is_file( self::$serverLog ) ) { unlink( self::$serverLog ); }
	}

	public static function softwareVersions(): array {
		return [
			'release' => [ 200, '{"pkgVersion":"1.15.2"}', '1.15.2' ],
			'prerelease' => [ 200, '{"pkgVersion":"1.16.0-rc.1"}', '1.16.0-rc.1' ],
			'unavailable' => [ 503, '{"message":"unavailable"}', null ],
			'permission denied' => [ 403, '{"code":"invalid_api_key"}', null ],
			'malformed JSON' => [ 200, 'not JSON', null ],
			'missing version' => [ 200, '{}', null ],
			'invalid type' => [ 200, '{"pkgVersion":123}', null ],
			'untrusted markup' => [ 200, '{"pkgVersion":"<script>alert(1)</script>"}', null ],
		];
	}

	#[DataProvider( 'softwareVersions' )]
	public function testSoftwareInfoHandlesVersionResponses( int $status, string $body, ?string $version ): void {
		$url = 'http://127.0.0.1:' . self::$port . '/fixture-response/' . $status . '/' . rawurlencode( base64_encode( $body ) );
		$client = new MeilisearchClient( $url, 'application-test-key', 'test', 1, 'task-test-key' );
		$hooks = new class( $client ) extends SoftwareInfoHooks {
			public function __construct( private MeilisearchClient $client ) {}
			protected function newClient(): MeilisearchClient { return $this->client; }
		};
		$software = [ 'PHP' => PHP_VERSION ];
		$hooks->onSoftwareInfo( $software );
		$expected = [ 'PHP' => PHP_VERSION ];
		if ( $version !== null ) { $expected['Meilisearch'] = $version; }
		$this->assertSame( $expected, $software );
	}

	public function testUnfinishedTaskWaitRemainsBoundedWithoutBusyPolling(): void {
		$client = new class extends MeilisearchClient {
			public int $polls = 0;
			public function __construct() {}
			public function getTask( int $taskUid ): array {
				$this->polls++;
				return [ 'uid' => $taskUid, 'status' => 'processing' ];
			}
		};
		$start = hrtime( true );
		try {
			$client->waitForTask( 42, 1 );
			$this->fail( 'Unfinished task was accepted.' );
		} catch ( MeilisearchException $error ) {
			$this->assertTrue( $error->isRetryable() );
			$this->assertNull( $error->getCompletedTaskId() );
			$this->assertGreaterThanOrEqual( 1, ( hrtime( true ) - $start ) / 1e9 );
			$this->assertLessThan( 3, ( hrtime( true ) - $start ) / 1e9 );
			$this->assertLessThanOrEqual( 20, $client->polls );
		}
	}

	public function testDocumentMethodsReturnTaskIdsAndWait(): void {
		$client = $this->client();
		$this->assertSame( 12, $client->addDocuments( [ [ 'id' => 1 ] ] ) );
		$this->assertSame( 12, $client->replaceDocuments( [ [ 'id' => 1 ] ] ) );
		$client->waitForTask( 12 );
		$this->addToAssertionCount( 1 );
	}

	public static function credentialRoutes(): array {
		return [
			'task read' => [ 'GET', '/tasks/12', 'task' ],
			'version read' => [ 'GET', '/version', 'application' ],
			'task documents' => [ 'GET', '/tasks/12/documents', 'application' ],
			'task query' => [ 'GET', '/tasks/12?details=true', 'application' ],
			'task list' => [ 'GET', '/tasks', 'application' ],
			'task listing query' => [ 'GET', '/tasks?limit=2&statuses=enqueued%2Cprocessing', 'task' ],
			'task listing mutation' => [ 'POST', '/tasks?statuses=enqueued', 'application' ],
			'task listing deletion' => [ 'DELETE', '/tasks?uids=12', 'application' ],
			'task cancellation' => [ 'POST', '/tasks/cancel?uids=12', 'application' ],
			'task mutation' => [ 'POST', '/tasks/12', 'application' ],
			'task deletion' => [ 'DELETE', '/tasks/12', 'application' ],
			'negative task ID' => [ 'GET', '/tasks/-1', 'application' ],
			'encoded task ID' => [ 'GET', '/tasks/%31%32', 'application' ],
			'index task path' => [ 'GET', '/indexes/test/tasks/12', 'application' ],
			'document read' => [ 'GET', '/indexes/test/documents/1', 'application' ],
			'search' => [ 'POST', '/indexes/test/search', 'application' ],
			'document replacement' => [ 'POST', '/indexes/test/documents?primaryKey=id', 'application' ],
			'settings update' => [ 'PATCH', '/indexes/test/settings', 'application' ],
			'index swap' => [ 'POST', '/swap-indexes', 'application' ],
		];
	}

	#[DataProvider( 'credentialRoutes' )]
	public function testDedicatedCredentialIsOnlySentForExactTaskReads( string $method, string $path, string $expected ): void {
		$client = new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/credential-probe',
			'application-test-key', 'test', 2, 'task-test-key' );
		$request = new ReflectionMethod( $client, 'request' );
		$response = $request->invoke( $client, $method, $path );
		$this->assertSame( $expected, $response['credential'] );
	}

	public function testFourArgumentAndEmptyTaskKeyConfigurationsKeepExistingAuthentication(): void {
		$url = 'http://127.0.0.1:' . self::$port . '/credential-probe';
		$legacy = new MeilisearchClient( $url, 'application-test-key', 'test', 2 );
		$empty = new MeilisearchClient( $url, 'application-test-key', 'test', 2, '' );
		$anonymous = new MeilisearchClient( $url, '', 'test', 2 );
		$this->assertSame( 'application', $legacy->getTask( 12 )['credential'] );
		$this->assertSame( 'application', $empty->getTask( 12 )['credential'] );
		$this->assertSame( 'none', $anonymous->getTask( 12 )['credential'] );
	}

	public function testPublicTaskListingUsesReadCredentialAndKeepsFallback(): void {
		$url = 'http://127.0.0.1:' . self::$port . '/credential-probe';
		$client = new MeilisearchClient( $url, 'application-test-key', 'test', 2, 'task-test-key' );
		$this->assertSame( 'task', $client->listTasks( [ 'statuses' => [ 'enqueued' ] ], null, 2 )['credential'] );
		$fallback = new MeilisearchClient( $url, 'application-test-key', 'test', 2 );
		$this->assertSame( 'application', $fallback->listTasks( [], null, 2 )['credential'] );
	}

	public function testClonesPreserveBothCredentialsAndMutationTracking(): void {
		$client = new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/credential-probe',
			'application-test-key', 'test', 2, 'task-test-key' );
		$recorded = [];
		$clone = $client->withIndex( 'generation' )->withMutationHandler(
			static function ( string $method, string $path, ?array $body, \Closure $send ) use ( &$recorded ): array {
				$recorded[] = [ $method, $path ];
				return $send();
			}
		);
		$this->assertSame( 'task', $clone->getTask( 12 )['credential'] );
		$this->assertSame( 'application', $clone->search( [ 'q' => 'test' ] )['credential'] );
		$this->assertSame( 12, $clone->replaceDocuments( [ [ 'id' => 1 ] ] ) );
		$clone->waitForTask( 12 );
		$this->assertSame( [ [ 'POST', '/indexes/generation/documents?primaryKey=id' ] ], $recorded );
	}

	public function testSwapReturnsTaskId(): void {
		$client = $this->client();
		$this->assertSame( 14, $client->swapWith( 'generation' ) );
	}

	public function testMutationWithoutTaskIdCannotSilentlySucceed(): void {
		$this->expectException( \FrauxSearch\MeilisearchException::class );
		$this->expectExceptionMessage( 'no valid task UID' );
		$this->client()->withIndex( 'malformed' )->replaceDocuments( [ [ 'id' => 1 ] ] );
	}

	public function testBatchDeletionNormalizesIdsAndSkipsEmptyRequests(): void {
		$this->assertSame( 12, $this->client()->deleteDocuments( [ 4 => '2', 9 => 5 ] ) );
		$this->assertNull( $this->client()->deleteDocuments( [] ) );
	}

	public function testSettingsAllowSnippetsAndExactAliasLookups(): void {
		$this->assertSame( 12, $this->client()->configureIndex() );
		$this->assertSame( MeilisearchClient::expectedIndexSettings(), $this->client()->getIndexSettings() );
	}

	public function testFindDocumentsWithRedirectValidatesExactArrayMembership(): void {
		$this->assertSame( [ 1, 101 ], $this->client()->findDocumentsWithRedirect( 'Exact Redirect' ) );
	}

	public function testRedirectFilterEscapesQuotesAndBackslashes(): void {
		$this->assertSame( [ 1, 101 ], $this->client()->findDocumentsWithRedirect( 'A "quoted" \\ title' ) );
	}

	public function testFetchDocumentsUsesRequestedIds(): void {
		$this->assertSame( [
			[ 'id' => 2, 'revision_id' => 20, 'document_hash' => 'hash-2' ],
			[ 'id' => 5, 'revision_id' => 50, 'document_hash' => 'hash-5' ],
		], $this->client()->fetchDocuments( [ 2, 5 ], [ 'id', 'revision_id', 'document_hash' ] ) );
		$this->assertSame( [], $this->client()->fetchDocuments( [], [ 'id' ] ) );
	}

	public function testUnprojectedDocumentReadsKeepUnknownStoredFields(): void {
		$this->assertSame( 'visible', $this->client()->fetchDocuments( [ 2 ] )[0]['extra_stored_field'] );
		$this->assertSame( 'visible', $this->client()->listDocuments( 0, 1 )['results'][0]['extra_stored_field'] );
	}

	public function testListDocumentsUsesPaginationAndFilter(): void {
		$this->assertSame( [
			'results' => [ [ 'id' => 7 ], [ 'id' => 8 ] ],
			'offset' => 10,
			'limit' => 2,
			'total' => 12,
			'filter' => 'id > 5 AND id <= 20',
		], $this->client()->listDocuments( 10, 2, [ 'id' ], 'id > 5 AND id <= 20' ) );
	}

	public function testHttpServerFailureIsRetryable(): void {
		$client = $this->client();
		$request = new ReflectionMethod( $client, 'request' );
		try {
			$request->invoke( $client, 'GET', '/retryable' );
			$this->fail( 'Expected request failure' );
		} catch ( \FrauxSearch\MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public function testPlainTextServerFailureIsRetryable(): void {
		$client = $this->client();
		$request = new ReflectionMethod( $client, 'request' );
		try {
			$request->invoke( $client, 'GET', '/plain-503' );
			$this->fail( 'Expected request failure' );
		} catch ( \FrauxSearch\MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertStringContainsString( 'upstream unavailable', $e->getMessage() );
		}
	}

	public function testFailedTransientTaskIsRetryable(): void {
		try {
			$this->client()->waitForTask( 50 );
			$this->fail( 'Expected task failure' );
		} catch ( \FrauxSearch\MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public function testCanceledTaskIsRetryable(): void {
		try {
			$this->client()->waitForTask( 51 );
			$this->fail( 'Expected task cancellation' );
		} catch ( \FrauxSearch\MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public static function malformedSuccesses(): array {
		return array_combine( $names = [ 'empty', 'whitespace', 'html', 'truncated', 'null', 'boolean', 'number', 'string', 'list' ],
			array_map( static fn ( string $name ) => [ $name ], $names ) );
	}

	#[DataProvider( 'malformedSuccesses' )]
	public function testMalformedSuccessIsATypedRetryableReadFailure( string $name ): void {
		$client = $this->client();
		$request = new ReflectionMethod( $client, 'request' );
		try {
			$request->invoke( $client, 'GET', '/protocol/' . $name );
			$this->fail( 'Malformed response must not look like an empty document list' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
			$this->assertStringContainsString( 'expected a JSON object', $e->getMessage() );
		}
	}

	public static function malformedErrors(): array {
		return [
			[ 'error-null', true ], [ 'error-string', true ], [ 'error-list', true ],
			[ 'error-nested', true ], [ 'error-empty', true ], [ 'error-permanent', false ],
		];
	}

	#[DataProvider( 'malformedErrors' )]
	public function testMalformedErrorBodiesPreserveHttpRetryClassification( string $name, bool $retryable ): void {
		$client = $this->client();
		$request = new ReflectionMethod( $client, 'request' );
		try {
			$request->invoke( $client, 'GET', '/protocol/' . $name );
			$this->fail( 'Expected typed HTTP failure' );
		} catch ( MeilisearchException $e ) {
			$this->assertSame( $retryable, $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
			$this->assertNull( $e->getErrorCode() );
		}
	}

	public static function invalidEnvelopes(): array {
		return [
			[ 'envelope', 'search' ], [ 'bad-hits', 'search' ],
			[ 'object-hits', 'search' ],
			[ 'envelope', 'fetchDocuments' ], [ 'list-results', 'fetchDocuments' ], [ 'scalar-document', 'fetchDocuments' ],
			[ 'object-results', 'fetchDocuments' ], [ 'array-document', 'fetchDocuments' ],
			[ 'missing-total', 'fetchDocuments' ], [ 'truncated-list', 'fetchDocuments' ], [ 'excess-list', 'fetchDocuments' ],
			[ 'envelope', 'listDocuments' ], [ 'list-results', 'listDocuments' ], [ 'scalar-document', 'listDocuments' ],
			[ 'object-results', 'listDocuments' ], [ 'array-document', 'listDocuments' ],
			[ 'missing-total', 'listDocuments' ], [ 'bad-total', 'listDocuments' ],
			[ 'truncated-list', 'listDocuments' ], [ 'excess-list', 'listDocuments' ],
			[ 'wrong-offset', 'listDocuments' ], [ 'wrong-limit', 'listDocuments' ],
			[ 'envelope', 'getDocument' ], [ 'wrong-document', 'getDocument' ], [ 'envelope', 'indexExists' ],
			[ 'padded-id', 'getDocument' ], [ 'bad-id', 'getDocument' ], [ 'zero-id', 'getDocument' ], [ 'negative-id', 'getDocument' ],
			[ 'bad-redirects', 'findDocumentsWithRedirect' ],
			[ 'padded-alias-id', 'findDocumentsWithRedirect' ],
			[ 'missing-alias-id', 'findDocumentsWithRedirect' ], [ 'empty-document', 'findDocumentsWithRedirect' ],
		];
	}

	#[DataProvider( 'invalidEnvelopes' )]
	public function testInvalidReadEnvelopeCannotSilentlyLookAbsent( string $name, string $method ): void {
		$client = $this->client()->withIndex( 'protocol-' . $name );
		$args = match ( $method ) {
			'search' => [ [ 'q' => 'test' ] ], 'fetchDocuments' => [ [ 1 ] ],
			'listDocuments' => [ 0, 10 ], 'getDocument' => [ 1 ], 'indexExists' => [],
			'findDocumentsWithRedirect' => [ 'Exact Redirect' ],
		};
		try {
			$client->$method( ...$args );
			$this->fail( 'Expected invalid response' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testAbsenceRequiresTheParsedApiErrorCode(): void {
		$this->assertNull( $this->client()->getDocument( 404 ) );
		$this->assertSame( [ 'id' => 1, 'title' => 'Page 1' ], $this->client()->getDocument( 1 ) );
		$this->assertFalse( $this->client()->withIndex( 'missing' )->indexExists() );
		$this->assertTrue( $this->client()->indexExists() );
		foreach ( [ 'error-doc-message' => 'getDocument', 'error-index-message' => 'indexExists' ] as $name => $method ) {
			try {
				$client = $this->client()->withIndex( 'protocol-' . $name );
				$client->$method( ...( $method === 'getDocument' ? [ 1 ] : [] ) );
				$this->fail( 'An upstream failure must not look like a missing document or index' );
			} catch ( MeilisearchException $e ) {
				$this->assertTrue( $e->isRetryable() );
				$this->assertSame( 'internal', $e->getErrorCode() );
			}
		}
		foreach ( [ 'error-doc-status' => 'getDocument', 'error-index-status' => 'indexExists' ] as $name => $method ) {
			try {
				$client = $this->client()->withIndex( 'protocol-' . $name );
				$client->$method( ...( $method === 'getDocument' ? [ 1 ] : [] ) );
				$this->fail( 'An error code with the wrong HTTP status must not imply absence' );
			} catch ( MeilisearchException $e ) {
				$this->assertTrue( $e->isRetryable() );
				$this->assertSame( 503, $e->getHttpStatus() );
			}
		}
	}

	public function testCanonicalNumericStringIdsRemainReadableForRepair(): void {
		$this->assertSame( [ 'id' => '1' ], $this->client()->withIndex( 'protocol-string-id' )->getDocument( 1 ) );
		$this->assertSame( [ 1 ], $this->client()->withIndex( 'protocol-string-alias-id' )->findDocumentsWithRedirect( 'Exact Redirect' ) );
	}

	public function testEmptyFieldProjectionCanReturnEmptyDocumentObjects(): void {
		$client = $this->client()->withIndex( 'protocol-empty-document' );
		$this->assertSame( [ [] ], $client->fetchDocuments( [ 1 ], [] ) );
		$this->assertSame( [ [] ], $client->listDocuments( 0, 10, [] )['results'] );
	}

	public function testCreateIndexDoesNotSuppressRetryableAlreadyExistsResponse(): void {
		$this->assertNull( $this->client()->withIndex( 'create-existing' )->createIndex() );
		try {
			$this->client()->withIndex( 'create-unavailable' )->createIndex();
			$this->fail( 'A retryable creation response must not imply the index exists' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertSame( 503, $e->getHttpStatus() );
			$this->assertSame( 'index_already_exists', $e->getErrorCode() );
		}
	}

	public function testStrictGuardCreationSubmitsOnceWithoutAnExistenceRead(): void {
		$requests = [];
		$client = new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/strict-create', '', 'guard', 2 );
		$client = $client->withMutationHandler(
			static function ( string $method, string $path, ?array $body, \Closure $send ) use ( &$requests ): array {
				$requests[] = [ $method, $path, $body ];
				return $send();
			}
		);
		$primaryKey = 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$this->assertSame( 60, $client->createIndexStrict( $primaryKey ) );
		$this->assertSame( [ [ 'POST', '/indexes', [ 'uid' => 'guard', 'primaryKey' => $primaryKey ] ] ], $requests );
		$client->waitForTask( 60 );
	}

	public function testStrictGuardCreationDoesNotTreatDuplicateResponsesAsOwnership(): void {
		foreach ( [ 'duplicate-sync', 'duplicate-async' ] as $index ) {
			$client = new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/strict-create', '', $index, 2 );
			try {
				$client->waitForTask( $client->createIndexStrict( 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ) );
				$this->fail( 'An existing guard cannot establish ownership of this epoch' );
			} catch ( MeilisearchException $e ) {
				$this->assertSame( 'index_already_exists', $e->getErrorCode() );
				$this->assertFalse( $e->isRetryable() );
				$this->assertSame( $index === 'duplicate-sync' ? null : 61, $e->getCompletedTaskId() );
			}
		}
	}

	public function testStrictCreationRequiresAValidAcknowledgement(): void {
		try {
			$this->responseClient( '{"accepted":true}' )->createIndexStrict();
			$this->fail( 'Missing task UID cannot establish guard ownership' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testMetadataReadsPreserveEpochAndRecognizeOnlyConfirmedAbsence(): void {
		foreach ( [ null, 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ] as $primaryKey ) {
			$metadata = [ 'uid' => 'test', 'primaryKey' => $primaryKey ];
			$client = $this->responseClient( json_encode( $metadata, JSON_THROW_ON_ERROR ) )->withMutationHandler(
				static function (): array { throw new \LogicException( 'Metadata reads must not record mutation intent' ); }
			);
			$this->assertSame( $metadata, $client->getIndexMetadata() );
		}
		$this->assertNull( $this->responseClient( '{"code":"index_not_found"}', 404 )->getIndexMetadata() );
		foreach ( [ [ 503, '{"code":"index_not_found"}' ], [ 404, '{"message":"index_not_found"}' ] ] as [ $status, $body ] ) {
			try {
				$this->responseClient( $body, $status )->getIndexMetadata();
				$this->fail( 'An unconfirmed absence must not allow a new coordination epoch' );
			} catch ( MeilisearchException $e ) {
				$this->assertSame( $status, $e->getHttpStatus() );
			}
		}
	}

	public static function invalidMetadata(): array {
		return [
			'missing UID' => [ '{"primaryKey":"id"}' ],
			'wrong UID' => [ '{"uid":"other","primaryKey":"id"}' ],
			'numeric UID' => [ '{"uid":42,"primaryKey":"id"}' ],
			'missing primary key' => [ '{"uid":"test"}' ],
			'array primary key' => [ '{"uid":"test","primaryKey":[]}' ],
			'object primary key' => [ '{"uid":"test","primaryKey":{}}' ],
			'malformed JSON' => [ '{"uid":' ],
		];
	}

	#[DataProvider( 'invalidMetadata' )]
	public function testInvalidMetadataFailsClosed( string $body ): void {
		try {
			$this->responseClient( $body )->getIndexMetadata();
			$this->fail( 'Invalid metadata must not be interpreted as a missing guard' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testIndexListingKeepsMetadataAndSupportsLastAndEmptyPages(): void {
		foreach ( [
			[ 'results' => [ [ 'uid' => 'old_generation', 'primaryKey' => 'id' ] ], 'offset' => 2, 'limit' => 2, 'total' => 3 ],
			[ 'results' => [], 'offset' => 3, 'limit' => 2, 'total' => 3 ],
		] as $page ) {
			$client = $this->responseClient( json_encode( $page, JSON_THROW_ON_ERROR ) );
			$this->assertSame( $page, $client->listIndexes( $page['offset'], $page['limit'] ) );
		}
	}

	public static function invalidIndexPages(): array {
		$index = [ 'uid' => 'test', 'primaryKey' => 'id' ];
		$page = [ 'results' => [ $index ], 'offset' => 0, 'limit' => 2, 'total' => 1 ];
		$cases = [
			'object results' => [ 'results' => (object)[] ],
			'array index' => [ 'results' => [ [] ] ],
			'missing metadata' => [ 'results' => [ [ 'uid' => 'test' ] ] ],
			'invalid index UID' => [ 'results' => [ [ 'uid' => 'bad/index', 'primaryKey' => null ] ] ],
			'duplicate index' => [ 'results' => [ $index, $index ], 'total' => 2 ],
			'total type' => [ 'total' => '1' ], 'negative total' => [ 'total' => -1 ],
			'wrong offset' => [ 'offset' => 1 ], 'wrong limit' => [ 'limit' => 3 ],
			'truncated page' => [ 'results' => [], 'total' => 1 ],
			'short page' => [ 'total' => 2 ],
			'excess results' => [ 'results' => [ $index, [ 'uid' => 'other', 'primaryKey' => 'id' ] ] ],
		];
		$cases = array_map( static fn ( array $change ) => array_replace( $page, $change ), $cases );
		foreach ( [ 'results', 'offset', 'limit', 'total' ] as $field ) {
			$cases['missing ' . $field] = $page;
			unset( $cases['missing ' . $field][$field] );
		}
		return array_map( static fn ( array $case ) => [ json_encode( $case, JSON_THROW_ON_ERROR ) ], $cases );
	}

	#[DataProvider( 'invalidIndexPages' )]
	public function testInvalidIndexListingCannotHideAbandonedGenerations( string $body ): void {
		try {
			$this->responseClient( $body )->listIndexes( 0, 2 );
			$this->fail( 'Invalid index listing must stop recovery' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testTaskListingFollowsDescendingCursorAndSerializesFilters(): void {
		$client = new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/task-list', '', 'test', 2 );
		$filters = [ 'statuses' => [ 'enqueued', 'processing' ] ];
		$first = $client->listTasks( $filters, null, 2 );
		$this->assertSame( [ 12, 9 ], array_column( $first['results'], 'uid' ) );
		$this->assertSame( 3, $first['next'] );
		$this->assertSame( 'enqueued,processing', $first['query']['statuses'] );
		$second = $client->listTasks( $filters, $first['next'], 2 );
		$this->assertSame( [ 3 ], array_column( $second['results'], 'uid' ) );
		$this->assertNull( $second['next'] );
		$this->assertSame( 3, $client->listTasks( [], 5, 2 )['results'][0]['uid'] );
		$this->assertSame( [], $client->listTasks( [], 0, 2 )['results'] );
		$filtered = $client->listTasks( [ 'uids' => [ 9, 3 ], 'indexUids' => [ 'test' ],
			'types' => [ 'settingsUpdate' ], 'statuses' => [ 'enqueued' ] ], null, 2 );
		$this->assertSame( [ 3 ], array_column( $filtered['results'], 'uid' ) );
		$this->assertSame( '9,3', $filtered['query']['uids'] );
	}

	private static function taskPage(): array {
		return [ 'results' => [ [ 'uid' => 12, 'type' => 'documentAdditionOrUpdate',
			'indexUid' => 'test', 'status' => 'processing', 'details' => (object)[] ] ],
			'limit' => 2, 'total' => 1, 'from' => 12, 'next' => null ];
	}

	public static function invalidTaskPages(): array {
		$page = self::taskPage();
		$cases = [
			'object results' => [ 'results' => (object)[] ], 'array task' => [ 'results' => [ [] ] ],
			'wrong total type' => [ 'total' => '1' ], 'negative total' => [ 'total' => -1 ],
			'excess results' => [ 'total' => 0 ], 'wrong limit' => [ 'limit' => 1 ],
			'wrong from type' => [ 'from' => '12' ], 'negative from' => [ 'from' => -1 ],
			'null from with results' => [ 'from' => null ], 'result above from' => [ 'from' => 11 ],
			'wrong next type' => [ 'next' => '3' ], 'negative next' => [ 'next' => -1 ],
			'empty page with more results' => [ 'results' => [] ],
			'truncated page' => [ 'total' => 2 ], 'missing continuation' => [ 'total' => 3 ],
			'empty page with next' => [ 'results' => [], 'next' => 3 ],
		];
		$cases = array_map( static fn ( array $change ) => array_replace( $page, $change ), $cases );
		foreach ( [ 'results', 'total', 'limit', 'from', 'next' ] as $field ) {
			$cases['missing ' . $field] = $page;
			unset( $cases['missing ' . $field][$field] );
		}
		foreach ( [ 'uid' => '12', 'status' => 'unknown', 'type' => 'unknown',
			'indexUid' => 12, 'details' => [] ] as $field => $value
		) {
			$cases['invalid task ' . $field] = $page;
			$cases['invalid task ' . $field]['results'][0][$field] = $value;
			$cases['missing task ' . $field] = $page;
			unset( $cases['missing task ' . $field]['results'][0][$field] );
		}
		$cases['document task without index'] = $page;
		$cases['document task without index']['results'][0]['indexUid'] = null;
		foreach ( [ 'duplicate UID' => 12, 'ascending UID' => 13, 'nonadvancing cursor' => 9 ] as $name => $uid ) {
			$cases[$name] = $page;
			$cases[$name]['results'][] = array_replace( $page['results'][0], [ 'uid' => $uid ] );
			$cases[$name]['total'] = 3;
			$cases[$name]['next'] = $name === 'nonadvancing cursor' ? 9 : 3;
		}
		foreach ( [
			'object pairs' => (object)[ '0' => [ 'indexes' => [ 'a', 'b' ] ] ],
			'array pair' => [ [] ],
			'object indexes' => [ [ 'indexes' => (object)[ '0' => 'a', '1' => 'b' ] ] ],
			'incomplete pair' => [ [ 'indexes' => [ 'a' ] ] ],
			'repeated index' => [ [ 'indexes' => [ 'a', 'a' ] ] ],
			'invalid index' => [ [ 'indexes' => [ 'a', 'bad/index' ] ] ],
		] as $name => $swaps ) {
			$cases['swap ' . $name] = $page;
			$cases['swap ' . $name]['results'][0] = array_replace( $page['results'][0], [
				'type' => 'indexSwap', 'indexUid' => null, 'details' => [ 'swaps' => $swaps ],
			] );
		}
		return array_map( static fn ( array $case ) => [ json_encode( $case, JSON_THROW_ON_ERROR ) ], $cases )
			+ [ 'malformed JSON' => [ '{"results":[' ] ];
	}

	#[DataProvider( 'invalidTaskPages' )]
	public function testInvalidTaskListingCannotHideUnfinishedWrites( string $body ): void {
		try {
			$this->responseClient( $body )->listTasks( [], null, 2 );
			$this->fail( 'Invalid task listing must stop recovery' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testTaskListingRejectsAResponseOutsideRequestedCursorOrFilter(): void {
		$body = json_encode( self::taskPage(), JSON_THROW_ON_ERROR );
		foreach ( [ [ [], 10 ], [ [ 'statuses' => [ 'enqueued' ] ], null ],
			[ [ 'indexUids' => [ 'other' ] ], null ], [ [ 'uids' => [ 9 ] ], null ] ] as [ $filters, $from ]
		) {
			try {
				$this->responseClient( $body )->listTasks( $filters, $from, 2 );
				$this->fail( 'Requested task bounds were ignored' );
			} catch ( MeilisearchException $e ) { $this->assertTrue( $e->isRetryable() ); }
		}
	}

	public function testNoOpSwapAndGlobalTaskAreValidListedTasks(): void {
		foreach ( [ [ 'type' => 'indexSwap', 'details' => [ 'swaps' => [] ] ],
			[ 'type' => 'snapshotCreation', 'details' => null ] ] as $change
		) {
			$page = self::taskPage();
			$page['results'][0] = array_replace( $page['results'][0], $change, [ 'indexUid' => null ] );
			$this->assertSame( $change['type'], $this->responseClient( json_encode( $page, JSON_THROW_ON_ERROR ) )
				->listTasks( [], null, 2 )['results'][0]['type'] );
		}
	}

	public function testListingHttpErrorsCannotLookLikeEmptyRecoveryState(): void {
		foreach ( [ [ 503, '<html>upstream unavailable</html>' ],
			[ 404, '{"code":"index_not_found"}' ] ] as [ $status, $body ]
		) {
			foreach ( [ 'listIndexes' => [ 0, 2 ], 'listTasks' => [ [], null, 2 ] ] as $method => $args ) {
				try {
					$this->responseClient( $body, $status )->$method( ...$args );
					$this->fail( 'A failed listing must not establish that recovery has no remaining work' );
				} catch ( MeilisearchException $e ) {
					$this->assertSame( $status, $e->getHttpStatus() );
					$this->assertSame( $status === 503, $e->isRetryable() );
				}
			}
		}
	}

	public static function invalidRecoveryQueries(): array {
		return [
			[ 'listIndexes', [ -1, 2 ] ], [ 'listIndexes', [ 0, 0 ] ],
			[ 'listTasks', [ [], -1, 2 ] ], [ 'listTasks', [ [], null, 0 ] ],
			[ 'listTasks', [ [ 'reverse' => true ], null, 2 ] ],
			[ 'listTasks', [ [ 'statuses' => [] ], null, 2 ] ],
			[ 'listTasks', [ [ 'statuses' => 'enqueued' ], null, 2 ] ],
			[ 'listTasks', [ [ 'statuses' => [ 'unknown' ] ], null, 2 ] ],
			[ 'listTasks', [ [ 'types' => [ 'unknown' ] ], null, 2 ] ],
			[ 'listTasks', [ [ 'indexUids' => [ 'bad/index' ] ], null, 2 ] ],
			[ 'listTasks', [ [ 'uids' => [ '12' ] ], null, 2 ] ],
			[ 'createIndexStrict', [ '' ] ],
		];
	}

	#[DataProvider( 'invalidRecoveryQueries' )]
	public function testInvalidRecoveryArgumentsFailBeforeHttp( string $method, array $args ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->responseClient( 'unexpected request' )->$method( ...$args );
	}

	public function testIndexNamedSearchDoesNotUseTheSearchResponseEnvelope(): void {
		$client = $this->client()->withIndex( 'search' );
		$this->assertTrue( $client->indexExists() );
		$this->assertNull( $client->createIndex() );
		$this->assertSame( 12, $client->deleteIndex() );
	}

	public function testMalformedTaskCannotConfirmCompletion(): void {
		foreach ( [ 52, 53, 54 ] as $id ) {
			try {
				$this->client()->waitForTask( $id );
				$this->fail( 'Expected malformed task failure' );
			} catch ( MeilisearchException $e ) {
				$this->assertTrue( $e->isRetryable() );
				$this->assertNull( $e->getCompletedTaskId() );
			}
		}
	}

	public function testConfirmedTerminalTaskDoesNotCrashOnMalformedErrorDetails(): void {
		try {
			$this->client()->waitForTask( 55 );
			$this->fail( 'Expected terminal failure' );
		} catch ( MeilisearchException $e ) {
			$this->assertFalse( $e->isRetryable() );
			$this->assertSame( 55, $e->getCompletedTaskId() );
			$this->assertSame( 'failed', $e->getErrorCode() );
		}
	}

	public function testLocalEncodingFailureOccursBeforeMutationIntent(): void {
		$recorded = false;
		$client = $this->client()->withMutationHandler( static function () use ( &$recorded ): array {
			$recorded = true;
			return [ 'taskUid' => 12 ];
		} );
		try {
			$client->replaceDocuments( [ [ 'id' => 1, 'text' => "\xB1" ] ] );
			$this->fail( 'Expected invalid local payload' );
		} catch ( MeilisearchException $e ) {
			$this->assertFalse( $e->isRetryable() );
			$this->assertFalse( $recorded );
			$this->assertNull( $e->getCompletedTaskId() );
		}
	}

	public function testRequestBodyIsSerializedOnceBeforeSubmission(): void {
		$value = new class implements \JsonSerializable {
			public int $calls = 0;
			public function jsonSerialize(): string { $this->calls++; return 'text'; }
		};
		$this->assertSame( 12, $this->client()->replaceDocuments( [ [ 'id' => 1, 'text' => $value ] ] ) );
		$this->assertSame( 1, $value->calls );
	}

	public function testMalformedMutationResponseLeavesCoordinatorIntentAndCannotBeResubmitted(): void {
		require_once dirname( __DIR__ ) . '/fixtures/coordination.php';
		$store = new MemoryCoordinationStore();
		$worker = new IndexCoordinator( $store, $this->client()->withIndex( 'protocol-truncated' ), 'protocol-truncated',
			static fn () => null, static function (): void {} );
		try {
			$worker->configure();
			$this->fail( 'Expected malformed settings mutation response' );
		} catch ( MeilisearchException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertNull( $e->getCompletedTaskId() );
		}
		$intent = $store->state['pending'];
		$this->assertSame( 'PATCH', $intent['method'] );
		$this->assertNull( $intent['taskUid'] );
		try {
			$worker->configure();
			$this->fail( 'Unknown mutation must block subsequent submissions' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'unknown outcome', $e->getMessage() );
			$this->assertSame( $intent, $store->state['pending'] );
		}
	}

	private function client(): MeilisearchClient {
		return new MeilisearchClient( 'http://127.0.0.1:' . self::$port, '', 'test', 2 );
	}

	private function responseClient( string $body, int $status = 200 ): MeilisearchClient {
		return new MeilisearchClient( 'http://127.0.0.1:' . self::$port . '/fixture-response/' . $status
			. '/' . rawurlencode( base64_encode( $body ) ), '', 'test', 2 );
	}
}

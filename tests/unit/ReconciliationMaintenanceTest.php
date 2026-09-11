<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentHash;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReconciliationMaintenanceTest extends TestCase {
	private ReconciliationTestServices $services;

	protected function setUp(): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__, 2 ) . '/maintenance/reconcileFrauxSearchIndex.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/reconciliation.php';
		$this->services = new ReconciliationTestServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	private function command( array $options = [] ) {
		$command = new class( $this->services ) extends \FrauxSearch\Maintenance\ReconcileFrauxSearchIndex {
			public string $messages = '';
			public function __construct( private ReconciliationTestServices $services ) { parent::__construct(); }
			public function output( ...$args ): void { $this->messages .= (string)$args[0]; }
			public function fatalError( $message, $exitCode = 1 ): never { throw new \RuntimeException( $message, $exitCode ); }
			protected function newClient( string $baseIndex ): MeilisearchClient {
				$this->services->events[] = [ 'new_client', $baseIndex ];
				return new ReconciliationTestClient( $this->services, $baseIndex );
			}
			protected function buildBatch( array $pageIds ): array {
				$this->services->events[] = [ 'build', $pageIds ];
				return array_intersect_key( $this->services->pages, array_fill_keys( $pageIds, true ) );
			}
		};
		$command->options = $options;
		return $command;
	}

	private function built( int $id, bool $redirect = false ): array {
		$document = [ 'id' => $id, 'revision_id' => 100 + $id, 'title' => 'Page ' . $id,
			'redirects' => [], 'namespace' => 0, 'incoming_links' => 0, 'outgoing_link_ids' => [],
			'boost' => 100, 'text' => 'Original content', 'timestamp' => '20260908000000',
			'word_count' => 2, 'byte_size' => 16 ];
		$document['document_hash'] = DocumentHash::compute( $document );
		return [ 'document' => $document, 'is_redirect' => $redirect ];
	}

	public function testDefaultAuditReadsPayloadAndReportsCorruptionWithoutQueueingRepairs(): void {
		$this->services->pages = [ 1 => $this->built( 1 ), 2 => null ];
		$original = $this->services->pages[1]['document'];
		$this->services->indexes['wiki'] = [ $original ];
		$this->services->indexes['wiki'][0]['text'] = 'Corrupted while revision and hash stayed unchanged';
		$this->services->indexes['wiki_completion'] = [ $original ];
		$command = $this->command();
		$command->execute();
		$this->assertStringContainsString( 'Full-text: missing=0 stale=1', $command->messages );
		$this->assertStringContainsString( 'Unbuildable source pages: 1', $command->messages );
		$this->assertStringContainsString( 'Unique repair candidates: 1', $command->messages );
		$this->assertSame( [], $this->services->jobBatches );
		foreach ( $this->services->events as $event ) {
			if ( $event[0] === 'fetch' ) { $this->assertNull( $event[3], 'Fetch the complete stored document.' ); }
		}
	}

	public function testRepairCandidatesDeduplicateAcrossIndexesAndQueueOnlyAfterSuccessfulScan(): void {
		$this->services->pages = [ 1 => $this->built( 1 ), 2 => $this->built( 2 ), 3 => null,
			4 => $this->built( 4, true ), 5 => $this->built( 5 ), 6 => null ];
		$this->services->indexes['wiki'] = [ $this->built( 2 )['document'], $this->built( 3 )['document'],
			$this->built( 4 )['document'], $this->built( 5 )['document'], $this->built( 9 )['document'] ];
		$this->services->indexes['wiki_completion'] = $this->services->indexes['wiki'];
		$this->services->indexes['wiki_completion'][0]['boost'] = 50;
		$command = $this->command( [ 'queue-repairs' => true, 'batch-size' => 2 ] );
		$command->execute();
		$params = $this->services->jobParams();
		$this->assertSame( [ 1, 2, 3, 4, 9 ], array_column( $params, 'pageId' ) );
		$events = array_unique( array_column( $params, 'reconciliationEvent' ) );
		$this->assertCount( 1, $events );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $events[0] );
		$this->assertSame( 'push', end( $this->services->events )[0] );
		$this->assertStringContainsString( 'Repair jobs queued: 5', $command->messages );
		$this->services->jobBatches = [];
		$this->command( [ 'queue-repairs' => true ] )->execute();
		$this->assertNotSame( $events[0], $this->services->jobParams()[0]['reconciliationEvent'] );
	}

	public function testRepairJobBatchesStayBoundedAndShareOneEvent(): void {
		for ( $id = 1; $id <= 1001; $id++ ) { $this->services->pages[$id] = $this->built( $id ); }
		$this->command( [ 'queue-repairs' => true, 'batch-size' => 1000 ] )->execute();
		$this->assertSame( [ 1000, 1 ], array_map( 'count', $this->services->jobBatches ) );
		$this->assertCount( 1, array_unique( array_column( $this->services->jobParams(), 'reconciliationEvent' ) ) );
	}

	public function testEachPageAndExtraDocumentBatchStartsWithFreshPrimarySnapshot(): void {
		for ( $id = 1; $id <= 3; $id++ ) {
			$this->services->pages[$id] = $this->built( $id );
			$this->services->indexes['wiki'][] = $this->built( $id )['document'];
		}
		$this->command( [ 'batch-size' => 2 ] )->execute();
		$reads = 0;
		foreach ( $this->services->events as $i => $event ) {
			if ( in_array( $event[0], [ 'page_scan', 'list' ], true ) ) {
				$this->assertSame( [ 'snapshot' ], $this->services->events[$i - 1] );
				$reads++;
			}
		}
		$this->assertSame( 5, $reads );
	}

	public function testBoundsApplyToSourcePagesAndBothIndexExtraScans(): void {
		foreach ( [ 1, 3, 5 ] as $id ) { $this->services->pages[$id] = $this->built( $id ); }
		$this->services->indexes = array_fill_keys( [ 'wiki', 'wiki_completion' ],
			array_map( fn ( int $id ) => $this->built( $id )['document'], [ 2, 4, 6 ] ) );
		$this->command( [ 'queue-repairs' => true, 'batch-size' => 1,
			'start-after' => 2, 'stop-after' => 5 ] )->execute();
		$this->assertSame( [ 3, 4, 5 ], array_column( $this->services->jobParams(), 'pageId' ) );
		$built = [];
		foreach ( $this->services->events as $event ) {
			if ( $event[0] === 'list' ) { $this->assertSame( 'id > 2 AND id <= 5', $event[5] ); }
			if ( $event[0] === 'build' ) { $built = array_merge( $built, $event[1] ); }
		}
		$this->assertSame( [ 3, 5 ], $built );
	}

	public static function activeTransactions(): array {
		return [ 'pending writes' => [ 'pendingWrites' ], 'pending callbacks' => [ 'pendingCallbacks' ],
			'explicit transaction' => [ 'explicitTransaction' ] ];
	}

	#[DataProvider( 'activeTransactions' )]
	public function testActivePrimaryTransactionAbortsWithoutScanningOrQueueing( string $property ): void {
		$this->services->$property = true;
		try { $this->command( [ 'queue-repairs' => true ] )->execute(); $this->fail( 'Expected transaction rejection.' ); }
		catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( $property === 'explicitTransaction' ? 'idle primary transaction' : 'Cannot flush snapshot',
				$e->getMessage() );
			$this->assertSame( [], $this->services->jobBatches );
			$this->assertNotContains( 'page_scan', array_column( $this->services->events, 0 ) );
		}
	}

	public static function invalidBounds(): array {
		return [
			'zero batch' => [ [ 'batch-size' => '0' ] ],
			'oversized batch' => [ [ 'batch-size' => '1001' ] ],
			'fractional batch' => [ [ 'batch-size' => '1.5' ] ],
			'invalid batch' => [ [ 'batch-size' => 'no' ] ],
			'negative cursor' => [ [ 'start-after' => '-1' ] ],
			'invalid upper bound' => [ [ 'stop-after' => 'no' ] ],
			'reversed bounds' => [ [ 'start-after' => '10', 'stop-after' => '9' ] ],
			'equal bounds' => [ [ 'start-after' => '10', 'stop-after' => '10' ] ],
		];
	}

	#[DataProvider( 'invalidBounds' )]
	public function testInvalidBoundsFailBeforeClientOrDatabaseAccess( array $options ): void {
		try { $this->command( $options + [ 'queue-repairs' => true ] )->execute(); $this->fail( 'Expected invalid bounds.' ); }
		catch ( \InvalidArgumentException ) {
			$this->assertSame( [], $this->services->events );
			$this->assertSame( [], $this->services->jobBatches );
		}
	}

	public function testSettingsOnlyDriftNamesOwnedSettingWithoutPageRepairsOrMutations(): void {
		$this->services->pages[1] = $this->built( 1 );
		$this->services->indexes = array_fill_keys( [ 'wiki', 'wiki_completion' ], [ $this->built( 1 )['document'] ] );
		$this->services->settings['wiki']['rankingRules'] = [ 'exactness' ];
		$this->services->settings['wiki']['unownedServerDefault'] = 'ignored';
		$command = $this->command( [ 'queue-repairs' => true ] );
		$command->execute();
		$this->assertStringContainsString( 'Full-text settings: differ: rankingRules', $command->messages );
		$this->assertStringContainsString( 'Completion settings: current', $command->messages );
		$this->assertStringContainsString( 'Unique repair candidates: 0', $command->messages );
		$this->assertSame( [], $this->services->jobBatches );
	}

	public static function invalidFetchedIds(): array {
		return [ 'missing' => [ null ], 'zero' => [ 0 ], 'negative' => [ -2 ], 'float' => [ 2.0 ],
			'leading zero' => [ '02' ], 'overflow' => [ '999999999999999999999999' ],
			'nonnumeric' => [ 'external-page' ], 'duplicate' => [ 'duplicate' ], 'unrequested' => [ 9 ] ];
	}

	#[DataProvider( 'invalidFetchedIds' )]
	public function testInvalidFetchedIdsAbortWithoutQueueingPreviouslyFoundRepairs( $value ): void {
		$this->services->pages = [ 1 => $this->built( 1 ), 2 => $this->built( 2 ) ];
		$this->services->responseOverride = static function ( $kind, $index, $ids ) use ( $value ) {
			if ( $kind !== 'fetch' || $index !== 'wiki' || $ids !== [ 2 ] ) { return null; }
			return $value === 'duplicate' ? [ [ 'id' => 2 ], [ 'id' => 2 ] ] : [ [ 'id' => $value ] ];
		};
		$command = $this->command( [ 'queue-repairs' => true, 'batch-size' => 1 ] );
		try { $command->execute(); $this->fail( 'Expected invalid response.' ); }
		catch ( \RuntimeException ) {
			$this->assertStringContainsString( 'mismatched IDs 1', $command->messages );
			$this->assertSame( [], $this->services->jobBatches );
		}
	}

	public static function invalidExtraDocuments(): array {
		return [ 'invalid ID' => [ 'invalid' ], 'outside bounds' => [ 'outside' ],
			'duplicate in page' => [ 'duplicate' ], 'repeated offset page' => [ 'repeated' ],
			'last read failed' => [ 'failure' ] ];
	}

	#[DataProvider( 'invalidExtraDocuments' )]
	public function testInvalidExtraScanAbortsWithoutQueuedRepairs( string $case ): void {
		$this->services->pages = [ 1 => $this->built( 1 ) ];
		$this->services->responseOverride = static function ( $kind, $index, $offset ) use ( $case ) {
			if ( $kind !== 'list' || $index !== 'wiki_completion' ) { return null; }
			if ( $case === 'failure' ) { throw new MeilisearchException( 'Malformed HTTP response on final scan.', true ); }
			$documents = match ( $case ) {
				'invalid' => [ [ 'id' => 0 ] ], 'outside' => [ [ 'id' => 9 ] ],
				'duplicate' => [ [ 'id' => 4 ], [ 'id' => 4 ] ], 'repeated' => [ [ 'id' => 4 ] ],
			};
			return [ 'results' => $documents, 'total' => $case === 'repeated' ? 2 : count( $documents ) ];
		};
		$command = $this->command( [ 'queue-repairs' => true, 'stop-after' => 5 ] );
		try {
			$command->execute();
			$this->fail( 'Expected incomplete audit to abort.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'mismatched IDs 1', $command->messages );
			$this->assertSame( [], $this->services->jobBatches );
			if ( $case === 'failure' ) {
				$this->assertInstanceOf( MeilisearchException::class, $e );
				$this->assertTrue( $e->isRetryable() );
			}
		}
	}

	public function testEmptyWikiStillFindsExtraDocumentsAndQueuesEachIdOnce(): void {
		$this->services->indexes = array_fill_keys( [ 'wiki', 'wiki_completion' ], [ $this->built( 9 )['document'] ] );
		$command = $this->command( [ 'queue-repairs' => true ] );
		$command->execute();
		$this->assertStringContainsString( 'Pages scanned: 0', $command->messages );
		$this->assertStringContainsString( 'Full-text: missing=0 stale=0 unexpected=0 extra=1', $command->messages );
		$this->assertStringContainsString( 'Completion: missing=0 stale=0 unexpected=0 extra=1', $command->messages );
		$this->assertSame( [ 9 ], array_column( $this->services->jobParams(), 'pageId' ) );
	}

	public function testFailOnDriftSignalsFailureAfterPersistingRequestedRepairs(): void {
		$this->services->pages[1] = $this->built( 1 );
		$command = $this->command( [ 'queue-repairs' => true, 'fail-on-drift' => true ] );
		try { $command->execute(); $this->fail( 'Expected drift exit.' ); }
		catch ( \RuntimeException $e ) {
			$this->assertSame( 1, $e->getCode() );
			$this->assertStringContainsString( 'index drift detected', $e->getMessage() );
			$this->assertSame( [ 1 ], array_column( $this->services->jobParams(), 'pageId' ) );
		}
	}

	public function testFailOnSettingsDriftDoesNotInventPageRepairs(): void {
		$this->services->settings['wiki']['rankingRules'] = [ 'exactness' ];
		try { $this->command( [ 'queue-repairs' => true, 'fail-on-drift' => true ] )->execute(); $this->fail( 'Expected drift exit.' ); }
		catch ( \RuntimeException $e ) {
			$this->assertSame( 1, $e->getCode() );
			$this->assertSame( [], $this->services->jobBatches );
		}
	}

	public function testFailOnDriftAllowsCleanIndexesWithUnbuildableAbsentSource(): void {
		$this->services->pages[1] = null;
		$command = $this->command( [ 'fail-on-drift' => true ] );
		$command->execute();
		$this->assertStringContainsString( 'Unbuildable source pages: 1', $command->messages );
		$this->assertStringContainsString( 'Unique repair candidates: 0', $command->messages );
	}
}

<?php

namespace FrauxSearch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class LifecycleHarnessTest extends TestCase {
	public function testParserCacheStoresOnlyWithinTheScenarioAndClearsAfterward(): void {
		require_once dirname( __DIR__ ) . '/fixtures/lifecycle.php';
		require_once dirname( __DIR__ ) . '/integration/LifecycleParserCache.php';
		$cache = new \FrauxSearch\Integration\LifecycleParserCache();
		$cache->set( 'outside', 'ignored' );
		$this->assertFalse( $cache->get( 'outside' ) );
		$cache->withCaching( function () use ( $cache ): void {
			$this->assertFalse( $cache->get( 'outside' ) );
			$cache->set( 'page', 'parsed text' );
			$this->assertSame( 'parsed text', $cache->get( 'page' ) );
			$this->assertSame( 1, $cache->getHits() );
		} );
		$this->assertFalse( $cache->get( 'page' ) );
		$cache->withCaching( function () use ( $cache ): void {
			$this->assertFalse( $cache->get( 'page' ) );
			$this->assertSame( 0, $cache->getHits() );
		} );
	}

	public function testParserCacheFailurePreservesExceptionAndRestoresDisabledState(): void {
		require_once dirname( __DIR__ ) . '/fixtures/lifecycle.php';
		require_once dirname( __DIR__ ) . '/integration/LifecycleParserCache.php';
		$cache = new \FrauxSearch\Integration\LifecycleParserCache();
		$failure = new \RuntimeException( 'Template refresh failed.' );
		try {
			$cache->withCaching( static function () use ( $cache, $failure ): void {
				$cache->set( 'page', 'old render' );
				throw $failure;
			} );
			$this->fail( 'The cache scope hid a scenario failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( $failure, $error );
		}
		$this->assertFalse( $cache->get( 'page' ) );
		$cache->withCaching( function () use ( $cache ): void { $this->assertFalse( $cache->get( 'page' ) ); } );
	}

	public static function renderedTextFailures(): array {
		return array_map( static fn ( string $field ) => [ $field ],
			[ 'revision_id', 'document_hash', 'old-text', 'retained-text', 'word_count', 'byte_size' ] );
	}

	#[DataProvider( 'renderedTextFailures' )]
	public function testRenderedChangeRejectsStaleTextRevisionOrMetadata( string $field ): void {
		[ $command ] = $this->cleanupFixture();
		$initial = [ 'revision_id' => 5, 'text' => 'Lead Apricot tail.', 'document_hash' => 'old',
			'word_count' => 3, 'byte_size' => strlen( 'Lead Apricot tail.' ) ];
		$current = [ 'revision_id' => 5, 'text' => 'Lead Blueberry additional visible words tail.',
			'document_hash' => 'changed', 'word_count' => 6, 'byte_size' => strlen( 'Lead Blueberry additional visible words tail.' ) ];
		$this->assertNull( $this->invoke( $command, 'assertRenderedChange', [ $initial, $current, 'Apricot', 'Blueberry' ] ) );
		switch ( $field ) {
			case 'old-text': $current['text'] = $initial['text']; break;
			case 'retained-text': $current['text'] .= ' Apricot'; break;
			case 'revision_id': $current[$field]++; break;
			case 'document_hash': $current[$field] = $initial[$field]; break;
			default: $current[$field]++;
		}
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Template-derived text, hash, or size metadata' );
		$this->invoke( $command, 'assertRenderedChange', [ $initial, $current, 'Apricot', 'Blueberry' ] );
	}

	public function testUnchangedTaskObservationExcludesGuardAndOtherWikiOperations(): void {
		[ $command ] = $this->cleanupFixture();
		$client = $this->createMock( \FrauxSearch\MeilisearchClient::class );
		$client->expects( $this->once() )->method( 'listTasks' )->with( [
			'indexUids' => [ 'lifecycle_test_abcdef012345', 'lifecycle_test_abcdef012345_completion' ],
			'types' => [ 'documentAdditionOrUpdate', 'documentEdition', 'documentDeletion' ],
		], null, 1 )->willReturn( [ 'results' => [ [ 'uid' => 42 ] ] ] );
		$this->setProperty( $command, 'client', $client );
		$this->assertSame( 42, $this->invoke( $command, 'latestDocumentTask' ) );
	}

	public static function changedPolicyScopes(): array {
		return array_map( static fn ( string $field ): array => [ $field ],
			[ 'server', 'domain', 'DBname', 'DBprefix', 'FrauxSearchUrl', 'run', 'index', 'legacy-marker', 'missing-option' ] );
	}

	#[DataProvider( 'changedPolicyScopes' )]
	public function testPolicyCleanupRejectsChangedOwnership( string $field ): void {
		[ $command, $services ] = $this->cleanupFixture();
		$command->options = [ 'policy-import-db' => 'isolated_fixture' ];
		$marker = $this->invoke( $command, 'policyOwner' );
		$redis = $this->createStub( \Redis::class );
		$redis->method( 'get' )->willReturn( $field === 'legacy-marker' ? 'isolated_fixture' : $marker );
		$this->setProperty( $command, 'redis', $redis );
		if ( in_array( $field, [ 'server', 'domain' ], true ) ) {
			$services->$field .= '_other';
		} elseif ( in_array( $field, [ 'DBname', 'DBprefix', 'FrauxSearchUrl' ], true ) ) {
			$services->config->values[$field] .= '_other';
		} elseif ( in_array( $field, [ 'run', 'index' ], true ) ) {
			$this->setProperty( $command, $field, 'changed_scope' );
		} elseif ( $field === 'missing-option' ) {
			$command->options = [];
		}
		try {
			$this->invoke( $command, 'cleanup' );
			$this->fail( 'Changed ownership must preserve the policy fixture.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( $field === 'missing-option' ? 'requires the original' : 'does not own', $error->getMessage() );
			$this->assertSame( 0, $services->deletions );
			$this->assertSame( 0, $services->queue->inspections );
		}
	}

	public function testPolicyCleanupAcceptsOnlyItsExactOriginalScope(): void {
		[ $command ] = $this->cleanupFixture();
		$command->options = [ 'policy-import-db' => 'isolated_fixture' ];
		$marker = $this->invoke( $command, 'policyOwner' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $marker );
		$redis = $this->createStub( \Redis::class );
		$redis->method( 'get' )->willReturn( $marker );
		$this->setProperty( $command, 'redis', $redis );
		$this->assertNull( $this->invoke( $command, 'assertPolicyOwner' ) );
	}

	public static function unfinishedQueues(): array {
		return [ 'claimed' => [ 'acquired', 'claimed or abandoned' ],
			'abandoned' => [ 'abandoned', 'claimed or abandoned' ],
			'delayed' => [ 'delayed', 'unhandled delayed' ] ];
	}

	#[DataProvider( 'unfinishedQueues' )]
	public function testCleanupPreservesPagesBeforeRejectingUnfinishedQueue( string $field, string $message ): void {
		[ $command, $services ] = $this->cleanupFixture();
		$services->queue->$field = 1;
		try {
			$this->invoke( $command, 'cleanup' );
			$this->fail( 'Unfinished queued work must prevent fixture deletion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( $message, $error->getMessage() );
			$this->assertGreaterThan( 0, $services->queue->inspections );
			$this->assertSame( 0, $services->deletions );
		}
	}

	public function testCleanupReachesPageDeletionAfterQueueAndCoordinationSettle(): void {
		[ $command, $services ] = $this->cleanupFixture();
		try {
			$this->invoke( $command, 'cleanup' );
			$this->fail( 'The fixture should stop at the page deletion boundary.' );
		} catch ( \DomainException $error ) {
			$this->assertSame( 'Reached the page deletion boundary.', $error->getMessage() );
			$this->assertGreaterThan( 0, $services->queue->inspections );
			$this->assertSame( 1, $services->deletions );
		}
	}

	private function cleanupFixture(): array {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__ ) . '/fixtures/lifecycle.php';
		require_once dirname( __DIR__ ) . '/integration/lifecycle.php';
		$services = new LifecycleServices();
		\MediaWiki\MediaWikiServices::$instance = $services;
		$command = new \FrauxSearch\Integration\CheckFrauxSearchLifecycle();
		$redis = $this->createMock( \Redis::class );
		$redis->method( 'get' )->willReturnCallback( static fn ( string $key ): string|false =>
			$key === 'frauxsearch:lifecycle:owner' ? 'abcdef012345' : false );
		$redis->expects( $this->never() )->method( 'flushDB' );
		$store = $this->createMock( \FrauxSearch\RedisCoordinationStore::class );
		$store->method( 'readState' )->willReturn( null );
		$store->method( 'keyNames' )->willReturn( [] );
		$store->expects( $this->never() )->method( 'lock' );
		$client = $this->createStub( \FrauxSearch\MeilisearchClient::class );
		$client->method( 'withIndex' )->willReturnSelf();
		$client->method( 'getIndexMetadata' )->willReturn( null );
		$connection = $this->createStub( \FrauxSearch\RedisConnection::class );
		$connection->method( 'evaluate' )->willReturn( 0 );
		foreach ( [ 'redis' => $redis, 'run' => 'abcdef012345', 'index' => 'lifecycle_test_abcdef012345',
			'coordinationOwned' => true, 'queueCreated' => true, 'coordinationStore' => $store,
			'client' => $client, 'coordinationConnection' => $connection,
			'titles' => [ 'Source A' => 'FrauxSearch Lifecycle abcdef012345 Source A' ] ] as $name => $value ) {
			$this->setProperty( $command, $name, $value );
		}
		return [ $command, $services ];
	}

	private function invoke( object $command, string $method, array $arguments = [] ): mixed {
		return ( new \ReflectionMethod( $command, $method ) )->invokeArgs( $command, $arguments );
	}

	private function setProperty( object $command, string $name, mixed $value ): void {
		( new \ReflectionProperty( $command, $name ) )->setValue( $command, $value );
	}

	public static function unsafePolicyFixtures(): array {
		return [
			'wrong selected engine' => [ [ 'expect-search-type' => 'CirrusSearch\\CirrusSearch' ], 0,
				'The selected search engine does not match', 0 ],
			'wrong source database' => [ [ 'policy-import-db' => 'another_database' ], 0,
				'exact --policy-import-db match', 0 ],
			'existing source pages' => [ [], 1, 'initially empty source wiki', 1 ],
			'negative delayed wait' => [ [ 'wait-delayed' => '-1' ], 0, '--wait-delayed must be an integer', 0 ],
			'excessive delayed wait' => [ [ 'wait-delayed' => '601' ], 0, '--wait-delayed must be an integer', 0 ],
			'missing interwiki prefix' => [ [], 0, 'Requested interwiki prefix is not configured', 2 ],
		];
	}

	#[DataProvider( 'unsafePolicyFixtures' )]
	public function testPolicyPreflightRejectsUnsafeSourceBeforeCreatingResources(
		array $options, int $pages, string $message, int $queries
	): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		putenv( 'FRAUXSEARCH_TEST_RUN=abcdef012345' );
		require_once dirname( __DIR__ ) . '/integration/lifecycle.php';
		$GLOBALS['wgFrauxSearchLifecycleOriginalIndex'] = 'lifecycle_preflight';
		$GLOBALS['wgFrauxSearchLifecycleQueueServer'] = [];
		$GLOBALS['wgFrauxSearchLifecycleQueueBackend'] = 'redis';
		$services = new class( $pages ) {
			public int $queries = 0;
			public function __construct( private int $pages ) {}
			public function getMainConfig(): self { return $this; }
			public function get( string $name ): mixed {
				return match ( $name ) {
					'FrauxSearchIndex' => 'lifecycle_preflight_lifecycle_test_abcdef012345',
					'DBname' => 'isolated_fixture',
					'JobTypeConf' => [ 'default' => [
						'class' => \MediaWiki\JobQueue\JobQueueRedis::class,
						'redisServer' => '127.0.0.1:6389', 'daemonized' => true,
					] ],
					default => throw new \LogicException( 'Unexpected resource configuration: ' . $name ),
				};
			}
			public function getSearchEngineFactory(): self { return $this; }
			public function create(): object { return new \stdClass(); }
			public function getConnectionProvider(): self { return $this; }
			public function getPrimaryDatabase(): self { return $this; }
			public function newSelectQueryBuilder(): self { return $this; }
			public function select( mixed $field ): self { return $this; }
			public function from( string $table ): self { return $this; }
			public function where( array $conditions ): self { return $this; }
			public function caller( string $caller ): self { return $this; }
			public function fetchField(): int { $this->queries++; return $this->pages; }
		};
		\MediaWiki\MediaWikiServices::$instance = $services;
		$command = new \FrauxSearch\Integration\CheckFrauxSearchLifecycle();
		$command->options = $options + [ 'execute' => true, 'policy-import-db' => 'isolated_fixture' ];
		try {
			$command->execute();
			$this->fail( 'Unsafe policy fixture should fail before initializing resources.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( $message, $error->getMessage() );
			$this->assertSame( $queries, $services->queries );
		}
	}
}

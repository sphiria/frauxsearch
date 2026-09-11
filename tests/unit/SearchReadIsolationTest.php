<?php

namespace FrauxSearch\Tests;

use FrauxSearch\FrauxSearchEngine;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class SearchReadIsolationTest extends TestCase {
	private $server;
	private string $serverLog;
	private string $requests;
	private \Closure $autoloadGuard;
	private FrauxSearchEngine $engine;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/searchReadIsolation.php';
		$this->autoloadGuard = static function ( string $class ): void {
			if ( str_starts_with( $class, 'FrauxSearch\\' )
				&& ( str_contains( $class, 'Coordination' ) || str_contains( $class, 'Coordinator' )
					|| $class === 'FrauxSearch\\DocumentBuilder' || $class === 'FrauxSearch\\PageRefresher'
					|| $class === 'FrauxSearch\\SearchTextExtractor' )
			) { SearchReadIsolationState::forbid( 'autoload:' . $class ); }
		};
		spl_autoload_register( $this->autoloadGuard, true, true );
		$port = random_int( 20000, 40000 );
		$this->serverLog = tempnam( sys_get_temp_dir(), 'frauxsearch-read-server-' );
		$this->requests = tempnam( sys_get_temp_dir(), 'frauxsearch-read-requests-' );
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open -- Isolated localhost HTTP transport fixture.
		$this->server = proc_open( [ PHP_BINARY, '-S', '127.0.0.1:' . $port,
			dirname( __DIR__ ) . '/fixtures/searchReadIsolation.php' ], [
			0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $this->serverLog, 'a' ], 2 => [ 'file', $this->serverLog, 'a' ],
		], $pipes, null, array_merge( getenv(), [ 'FRAUXSEARCH_READ_REQUESTS' => $this->requests ] ) );
		$url = 'http://127.0.0.1:' . $port;
		$probe = curl_init( $url . '/fixture-ready' );
		curl_setopt_array( $probe, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100 ] );
		$deadline = hrtime( true ) + 3_000_000_000;
		$ready = false;
		do {
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource -- proc_open returns a process resource.
			if ( !is_resource( $this->server ) || !proc_get_status( $this->server )['running'] ) { break; }
			if ( curl_exec( $probe ) === '{"fixture":"frauxsearch-search-read-isolation"}' ) { $ready = true; break; }
			usleep( 20000 );
		} while ( hrtime( true ) < $deadline );
		$this->assertTrue( $ready, 'Local search HTTP fixture failed: ' . file_get_contents( $this->serverLog ) );
		MediaWikiServices::$instance = new SearchReadIsolationServices( $url );
		$this->engine = new FrauxSearchEngine();
		$this->engine->setLimitOffset( 2 );
		$this->engine->setNamespaces( [ 0, 1 ] );
	}

	protected function tearDown(): void {
		if ( isset( $this->autoloadGuard ) ) { spl_autoload_unregister( $this->autoloadGuard ); }
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.is_resource -- Always stop the owned fixture process.
		if ( is_resource( $this->server ) ) { proc_terminate( $this->server ); proc_close( $this->server ); }
		foreach ( [ $this->serverLog ?? null, $this->requests ?? null ] as $path ) {
			if ( $path !== null && is_file( $path ) ) { unlink( $path ); }
		}
		$this->assertSame( [], SearchReadIsolationState::$forbidden, 'A swallowed failure still counts as forbidden access.' );
	}

	public static function searchModes(): array {
		return [ [ 'searchText', [ 'title', 'redirects', 'text' ] ], [ 'searchTitle', [ 'title', 'redirects' ] ] ];
	}

	#[DataProvider( 'searchModes' )]
	public function testSearchAndResultMetadataComeOnlyFromIndexedHits( string $method, array $attributes ): void {
		$set = $this->engine->$method( ' Narmaya ' );
		$this->assertCount( 1, $set );
		$this->assertSame( 9, $set->getTotalHits() );
		$this->assertTrue( $set->isApproximateTotalHits() );
		$this->assertTrue( $set->hasMoreResults() );
		$result = iterator_to_array( $set )[0];
		$this->assertSame( 42, $result->getTitle()->getArticleID(), 'API result IDs must not trigger source lookup.' );
		$this->assertSame( 'Narmaya', $set->extractTitles()[0]->getPrefixedText() );
		$this->assertFalse( $result->isBrokenTitle() );
		$this->assertFalse( $result->isMissingRevision() );
		$this->assertNull( $result->getFile() );
		$this->assertSame( '20260908000000', $result->getTimestamp() );
		$this->assertSame( 17, $result->getWordCount() );
		$this->assertSame( 1234, $result->getByteSize() );
		$this->assertSame( '<span class="searchmatch">Narmaya</span>', $result->getTitleSnippet() );
		$this->assertSame( 'Indexed &lt;script&gt;alert(1)&lt;/script&gt; <span class="searchmatch">Kaleidoscope</span>',
			$result->getTextSnippet() );
		$this->assertFalse( $set->searchContainedSyntax() );
		[ $exact, $ordinary ] = $this->rankedRequests();
		foreach ( [ $exact, $ordinary ] as $request ) {
			$this->assertSame( 'Narmaya', $request['q'] );
			$this->assertSame( $attributes, $request['attributesToSearchOn'] );
			$this->assertSame( 'all', $request['matchingStrategy'] );
			$this->assertSame( [ 'namespace = 0', 'namespace = 1' ], $request['filter'][0] );
			$this->assertSame( [ 'title', 'text' ], $request['attributesToHighlight'] );
			$this->assertSame( [ 'text' ], $request['attributesToCrop'] );
		}
		$this->assertSame( $exact['highlightPreTag'], $ordinary['highlightPreTag'] );
		$this->assertSame( $exact['highlightPostTag'], $ordinary['highlightPostTag'] );
		$this->assertSame( 3, $exact['hitsPerPage'] );
		$this->assertSame( 0, $ordinary['offset'] );
		$this->assertSame( 2, $ordinary['limit'] );
	}

	public static function completionModes(): array { return [ [ 'completionSearch' ], [ 'completionSearchWithVariants' ] ]; }

	#[DataProvider( 'completionModes' )]
	public function testPublicCompletionRetainsIndexedIdsAliasesAndPaginationWithoutCoreSql( string $method ): void {
		$set = $this->engine->$method( 'Naru' );
		$this->assertSame( 2, $set->getSize() );
		$this->assertTrue( $set->hasMoreResults() );
		$this->assertSame( [ 'Naru', 'Narmaya (Summer)' ], $set->map( static fn ( $s ) => $s->getText() ) );
		$this->assertSame( [ 42, 43 ], $set->map( static fn ( $s ) => $s->getSuggestedTitleID() ) );
		$this->assertSame( 42, $set->getSuggestions()[0]->getSuggestedTitle()->getArticleID() );
		$this->assertSame( 'https://wiki.invalid/wiki/Narmaya', $set->getSuggestions()[0]->getURL() );
		[ $exact, $ordinary ] = $this->rankedRequests();
		foreach ( [ $exact, $ordinary ] as $query ) {
			$this->assertSame( [ 'title', 'redirects' ], $query['attributesToSearchOn'] );
			$this->assertSame( 'all', $query['matchingStrategy'] );
			$this->assertSame( [ 'namespace = 0', 'namespace = 1' ], $query['filter'][0] );
		}
		$this->assertSame( 3, $exact['hitsPerPage'] );
		$this->assertSame( 0, $ordinary['offset'] );
		$this->assertSame( 2, $ordinary['limit'] );
	}

	public static function prefixPages(): array {
		return [ [ 10, 0, [ 42, 47, 48, 49 ] ], [ 2, 1, [ 47, 48 ] ] ];
	}

	#[DataProvider( 'prefixPages' )]
	public function testDefaultPrefixSearchFiltersAndPaginatesIndexedTitlesWithoutSql(
		int $limit, int $offset, array $expectedIds
	): void {
		$this->engine->setLimitOffset( $limit, $offset );
		$titles = $this->engine->defaultPrefixSearch( 'narmaya' );
		$this->assertSame( $expectedIds, array_map( static fn ( $title ) => $title->getArticleID(), $titles ),
			'Literal prefixes exclude fuzzy/middle matches, retain redirect pages, sort, then paginate.' );
		$query = $this->onlyRequest( '/indexes/read-isolation/search' );
		$this->assertSame( [ 'title' ], $query['attributesToSearchOn'] );
		$this->assertSame( 'all', $query['matchingStrategy'] );
		$this->assertSame( 0, $query['offset'] );
		$this->assertSame( 1000, $query['limit'] );
	}

	public static function identityModes(): array { return [ [ 'searchText' ], [ 'completionSearch' ] ]; }

	#[DataProvider( 'identityModes' )]
	public function testIndexedNamespacesColonsAndStalePageIdsDoNotRequireTitleLookup( string $method ): void {
		$this->engine->setNamespaces( [ 0, 6 ] );
		$set = $this->engine->$method( 'NamespaceCases' );
		$titles = $method === 'searchText' ? $set->extractTitles()
			: $set->map( static fn ( $suggestion ) => $suggestion->getSuggestedTitle() );
		$this->assertCount( 2, $titles );
		$this->assertSame( [ 40442, 40443 ], array_map( static fn ( $title ) => $title->getArticleID(), $titles ),
			'Even stale indexed IDs must be returned without source existence checks.' );
		$this->assertSame( [ 0, 6 ], array_map( static fn ( $title ) => $title->getNamespace(), $titles ) );
		$this->assertSame( [ 'Fate:_Grand_Order', 'Narmaya_art.png' ],
			array_map( static fn ( $title ) => $title->getDBkey(), $titles ) );
		$this->assertSame( [ 'Fate: Grand Order', 'File:Narmaya art.png' ],
			array_map( static fn ( $title ) => $title->getPrefixedText(), $titles ) );
		if ( $method === 'searchText' ) {
			$this->assertNull( iterator_to_array( $set )[1]->getFile(), 'File results must not initialize FileRepo.' );
		}
		foreach ( $this->rankedRequests() as $query ) {
			$this->assertContains( 'namespace', $query['attributesToRetrieve'] );
		}
	}

	public static function emptyTerms(): array {
		return [ [ 'completionSearch' ], [ 'completionSearchWithVariants' ], [ 'defaultPrefixSearch' ] ];
	}

	#[DataProvider( 'emptyTerms' )]
	public function testEmptyCompletionDoesNotContactEitherBackend( string $method ): void {
		$result = $this->engine->$method( '  ' );
		$this->assertSame( 0, is_array( $result ) ? count( $result ) : $result->getSize() );
		$this->assertSame( '', file_get_contents( $this->requests ) );
	}

	public static function failures(): array {
		return [ [ 'searchText' ], [ 'searchTitle' ], [ 'completionSearch' ], [ 'completionSearchWithVariants' ], [ 'defaultPrefixSearch' ] ];
	}

	#[DataProvider( 'failures' )]
	public function testMeilisearchFailureDoesNotFallBackToSqlOrInitializeWriters( string $method ): void {
		$result = $this->engine->$method( 'Unavailable' );
		$this->assertSame( 0, is_array( $result ) || $result instanceof \Countable ? count( $result ) : $result->getSize() );
		$this->assertCount( 1, SearchReadIsolationState::$logs );
		$path = $method === 'defaultPrefixSearch'
			? '/indexes/read-isolation/search' : '/indexes/read-isolation_completion/search';
		$this->onlyRequest( $path );
	}

	public static function syntaxFailures(): array {
		return [
			[ '"unfinished', 'frauxsearch-query-unclosed-quote', [] ],
			[ 'incategory:Characters', 'frauxsearch-query-unsupported-operator', [ 'incategory:' ] ],
			[ 'Narmaya OR Gran', 'frauxsearch-query-unsupported-operator', [ 'OR' ] ],
			[ "Invalid\xFFtext", 'frauxsearch-query-invalid-encoding', [] ],
		];
	}

	#[DataProvider( 'syntaxFailures' )]
	public function testInvalidSyntaxReturnsStatusForTextAndNullForTitleWithoutBackendRequests(
		string $term, string $key, array $parameters
	): void {
		$status = $this->engine->searchText( $term );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( [ [ 'type' => 'error', 'message' => $key, 'params' => $parameters ] ], $status->getErrors() );
		$this->assertNull( $this->engine->searchTitle( $term ) );
		$this->assertSame( '', file_get_contents( $this->requests ) );
		$this->assertSame( [ 0, 1 ], $this->engine->getNamespaces() );
	}

	public static function parsedTerms(): array {
		return [
			[ '"Narmaya Summer"', '"Narmaya Summer"' ],
			[ 'Narmaya -Summer', '-"Summer" Narmaya' ],
			[ '+Narmaya +Summer', 'Narmaya Summer' ],
		];
	}

	#[DataProvider( 'parsedTerms' )]
	public function testSyntaxUsesOneCanonicalRequestAndSuppressesCreatePageLinks( string $term, string $query ): void {
		$set = $this->engine->searchText( $term );
		$this->assertTrue( $set->searchContainedSyntax(), 'Core Special:Search uses this flag to suppress create-page links.' );
		$request = $this->onlyRequest( '/indexes/read-isolation_completion/search' );
		$this->assertSame( $query, $request['q'] );
		$this->assertSame( 'all', $request['matchingStrategy'] );
		$this->assertSame( [ [ 'namespace = 0', 'namespace = 1' ] ], $request['filter'] );
		$this->assertSame( 3, $request['limit'] );
	}

	public static function namespaceTerms(): array {
		return [
			[ 'File:NamespaceCases', 'NamespaceCases', [ 6 ], [ 40443 ] ],
			[ 'Image:NamespaceCases', 'NamespaceCases', [ 6 ], [ 40443 ] ],
			[ 'all:NamespaceCases', 'NamespaceCases', null, [ 40442, 40443 ] ],
		];
	}

	#[DataProvider( 'namespaceTerms' )]
	public function testNamespacePrefixAppliesOnlyToCurrentSearch(
		string $term, string $query, ?array $namespaces, array $expectedIds
	): void {
		$set = $this->engine->searchText( $term );
		$this->assertSame( $expectedIds, array_map( static fn ( $title ) => $title->getArticleID(), $set->extractTitles() ) );
		$this->assertTrue( $set->searchContainedSyntax() );
		$this->assertSame( [ 0, 1 ], $this->engine->getNamespaces() );
		foreach ( $this->rankedRequests() as $request ) {
			$this->assertSame( $query, $request['q'] );
			if ( $namespaces === null ) {
				$this->assertIsString( $request['filter'][0] );
			} else {
				$this->assertSame( array_map( static fn ( int $ns ) => "namespace = $ns", $namespaces ), $request['filter'][0] );
			}
		}
		file_put_contents( $this->requests, '' );
		$this->engine->searchTitle( 'Narmaya' );
		foreach ( $this->rankedRequests() as $request ) {
			$this->assertSame( [ 'namespace = 0', 'namespace = 1' ], $request['filter'][0] );
		}
	}

	public static function literalTerms(): array {
		return [ [ ' Fate:_Grand_Order ', 'Fate:_Grand_Order', 'Fate: Grand Order' ],
			[ ' Narmaya__Summer ', 'Narmaya__Summer', 'Narmaya Summer' ] ];
	}

	#[DataProvider( 'literalTerms' )]
	public function testNonNamespaceColonsAndUnderscoresRemainLiteral(
		string $term, string $normalized, string $exactTerm
	): void {
		$set = $this->engine->searchText( $term );
		$this->assertFalse( $set->searchContainedSyntax() );
		foreach ( $this->rankedRequests() as $request ) {
			$this->assertSame( $normalized, $request['q'] );
			$this->assertSame( [ 'namespace = 0', 'namespace = 1' ], $request['filter'][0] );
			$this->assertStringContainsString( 'title = ' . json_encode( $exactTerm ), $request['filter'][1] );
		}
	}

	public function testNamespaceQueryKeepsUnderscoredExclusionAsOneNegativeClause(): void {
		$set = $this->engine->searchText( 'File:Narmaya -Summer_art' );
		$this->assertTrue( $set->searchContainedSyntax() );
		$query = $this->onlyRequest( '/indexes/read-isolation_completion/search' );
		$this->assertSame( '-"Summer_art" Narmaya', $query['q'] );
		$this->assertSame( [ [ 'namespace = 6' ] ], $query['filter'] );
		$this->assertSame( [ 0, 1 ], $this->engine->getNamespaces() );
	}

	public static function namespaceFilterModes(): array {
		return [ [ 'searchText' ], [ 'completionSearch' ], [ 'defaultPrefixSearch' ] ];
	}

	#[DataProvider( 'namespaceFilterModes' )]
	public function testSparseNamespaceKeysSerializeAsFilterLists( string $method ): void {
		$this->engine->setNamespaces( [ 0 => 0, 2 => 6 ] );
		$this->engine->$method( 'Narmaya' );
		$requests = $method === 'defaultPrefixSearch'
			? [ $this->onlyRequest( '/indexes/read-isolation/search' ) ] : $this->rankedRequests();
		foreach ( $requests as $query ) {
			$this->assertSame( [ 'namespace = 0', 'namespace = 6' ], $query['filter'][0] );
		}
	}

	public function testOffsetAcrossExactAliasBucketRetainsCanonicalPaginationAndTotals(): void {
		$this->engine->setLimitOffset( 2, 1 );
		$set = $this->engine->searchText( 'Bucket' );
		$this->assertSame( [ 63, 64 ], array_map( static fn ( $title ) => $title->getArticleID(), $set->extractTitles() ) );
		$this->assertSame( 4, $set->getTotalHits() );
		$this->assertFalse( $set->isApproximateTotalHits() );
		$this->assertTrue( $set->hasMoreResults() );
		[ $exact, $ordinary ] = $this->rankedRequests();
		$this->assertSame( 4, $exact['hitsPerPage'] );
		$this->assertSame( 0, $ordinary['offset'] );
		$this->assertSame( 3, $ordinary['limit'] );
		$this->assertStringContainsString( 'redirects = "Bucket"', $exact['filter'][1] );
	}

	private function rankedRequests(): array {
		$requests = $this->recordedRequests( '/indexes/read-isolation_completion/search' );
		$this->assertCount( 2, $requests, 'Exact and ordinary search buckets must both use the canonical index.' );
		[ $exact, $ordinary ] = $requests;
		$this->assertSame( 1, $exact['page'] );
		$this->assertArrayNotHasKey( 'offset', $exact );
		$this->assertArrayNotHasKey( 'limit', $exact );
		$this->assertSame( 'NOT ' . end( $exact['filter'] ), end( $ordinary['filter'] ) );
		return $requests;
	}

	private function onlyRequest( string $path ): array {
		$requests = $this->recordedRequests( $path );
		$this->assertCount( 1, $requests, 'Read paths must not issue document mutations or task/coordinator requests.' );
		return $requests[0];
	}

	private function recordedRequests( string $path ): array {
		$lines = array_filter( explode( "\n", trim( file_get_contents( $this->requests ) ) ) );
		$requests = [];
		foreach ( $lines as $line ) {
			$request = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame( 'POST', $request['method'] );
			$this->assertSame( $path, $request['path'] );
			$requests[] = $request['body'];
		}
		return $requests;
	}
}

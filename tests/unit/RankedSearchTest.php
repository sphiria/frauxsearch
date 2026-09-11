<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use FrauxSearch\RankedSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RankedSearchTest extends TestCase {
	private function client( array $exact, array $ordinary, bool $exhaustive = false ): RankedSearchClient {
		return new RankedSearchClient( static function ( array $query ) use ( $exact, $ordinary, $exhaustive ): array {
			if ( isset( $query['page'] ) ) {
				return [ 'hits' => array_slice( $exact, 0, $query['hitsPerPage'] ), 'totalHits' => count( $exact ),
					'processingTimeMs' => 2 ];
			}
			return [ 'hits' => array_slice( $ordinary, $query['offset'], $query['limit'] ),
				$exhaustive ? 'totalHits' : 'estimatedTotalHits' => count( $ordinary ), 'processingTimeMs' => 3 ];
		} );
	}

	public function testEmptyExactTermsPreserveSingleQueryAndResponse(): void {
		$query = [ 'q' => 'cat -dog', 'offset' => 5, 'limit' => 2, 'filter' => 'namespace = 0' ];
		$response = [ 'hits' => [ [ 'id' => 7 ] ], 'estimatedTotalHits' => 12, 'processingTimeMs' => 4 ];
		$client = new RankedSearchClient( static fn (): array => $response );
		$this->assertSame( $response, RankedSearch::search( $client, $query ) );
		$this->assertSame( [ $query ], $client->queries );
	}

	public function testBucketsPreserveQueryFiltersFieldsAndHighlightMarkers(): void {
		$query = [ 'q' => 'User:Example', 'offset' => 0, 'limit' => 5,
			'filter' => [ [ 'namespace = 2', 'namespace = 3' ], 'boost > 0' ],
			'attributesToSearchOn' => [ 'title', 'redirects', 'text' ],
			'attributesToRetrieve' => [ 'id', 'title' ], 'attributesToHighlight' => [ 'title', 'text' ],
			'highlightPreTag' => '__open__', 'highlightPostTag' => '__close__',
			'attributesToCrop' => [ 'text' ], 'cropLength' => 50, 'matchingStrategy' => 'all' ];
		$hit = [ 'id' => 1, '_formatted' => [ 'title' => '__open__User:Example__close__' ] ];
		$client = $this->client( [ $hit ], [ [ 'id' => 2 ] ] );
		$response = RankedSearch::search( $client, $query, [ 'Example', 'User:Example', 'Example' ] );
		$filter = '(title = "Example" OR redirects = "Example" OR title = "User:Example"'
			. ' OR redirects = "User:Example")';
		$this->assertSame( [ ...$query['filter'], $filter ], $client->queries[0]['filter'] );
		$this->assertSame( [ ...$query['filter'], 'NOT ' . $filter ], $client->queries[1]['filter'] );
		$preserved = $query;
		unset( $preserved['filter'], $preserved['offset'], $preserved['limit'] );
		foreach ( $client->queries as $request ) {
			$this->assertSame( $preserved, array_intersect_key( $request, $preserved ) );
		}
		$this->assertArrayNotHasKey( 'offset', $client->queries[0] );
		$this->assertArrayNotHasKey( 'limit', $client->queries[0] );
		$this->assertSame( 1, $client->queries[0]['page'] );
		$this->assertSame( 5, $client->queries[0]['hitsPerPage'] );
		$this->assertSame( 4, $client->queries[1]['limit'] );
		$this->assertSame( $hit, $response['hits'][0] );
		$this->assertSame( 2, $response['estimatedTotalHits'] );
		$this->assertArrayNotHasKey( 'totalHits', $response );
		$this->assertSame( 5, $response['processingTimeMs'] );
	}

	public function testEqualityValuesAreEncodedWithoutChangingExistingStringFilter(): void {
		$client = $this->client( [], [] );
		RankedSearch::search( $client, [ 'q' => 'quoted title', 'filter' => 'namespace = 0 OR namespace = 1' ],
			[ 'A "quoted" \\ 猫' ] );
		$filter = '(title = "A \\"quoted\\" \\\\ 猫" OR redirects = "A \\"quoted\\" \\\\ 猫")';
		$this->assertSame( [ 'namespace = 0 OR namespace = 1', $filter ], $client->queries[0]['filter'] );
		$this->assertSame( [ 'namespace = 0 OR namespace = 1', 'NOT ' . $filter ], $client->queries[1]['filter'] );
	}

	public function testPaginationCrossesBucketBoundaryWithoutDuplicatesOrMissingResults(): void {
		$exact = [ [ 'id' => 40 ], [ 'id' => 10 ], [ 'id' => 90 ] ];
		$ordinary = array_map( static fn ( int $id ): array => [ 'id' => $id ], range( 1, 7 ) );
		$client = $this->client( $exact, $ordinary );
		$all = [];
		foreach ( range( 0, 10, 2 ) as $offset ) {
			$response = RankedSearch::search( $client, [ 'q' => 'Exact', 'offset' => $offset, 'limit' => 2 ], [ 'Exact' ] );
			$this->assertSame( $offset, $response['offset'] );
			$this->assertSame( 2, $response['limit'] );
			$this->assertSame( 10, $response['estimatedTotalHits'] );
			$all = [ ...$all, ...$response['hits'] ];
		}
		$this->assertSame( [ ...$exact, ...$ordinary ], $all );
		$this->assertSame( [ 0, 0, 1, 3, 5, 7 ], array_column(
			array_values( array_filter( $client->queries, static fn ( array $q ): bool => isset( $q['offset'] ) ) ),
			'offset' ) );
		$this->assertSame( 0, $client->queries[1]['limit'] );
		$this->assertSame( 1, $client->queries[3]['limit'] );
	}

	public function testNoExactMatchesRetainOrdinaryOffsetAndExhaustiveTotal(): void {
		$client = $this->client( [], [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ] ], true );
		$response = RankedSearch::search( $client, [ 'q' => 'Missing', 'offset' => 1, 'limit' => 2 ], [ 'Missing' ] );
		$this->assertSame( [ [ 'id' => 2 ], [ 'id' => 3 ] ], $response['hits'] );
		$this->assertSame( 1, $client->queries[1]['offset'] );
		$this->assertSame( 3, $response['totalHits'] );
		$this->assertArrayNotHasKey( 'estimatedTotalHits', $response );
	}

	public function testCombinedWindowAndReportedTotalStayWithinThousandResults(): void {
		$exact = [ [ 'id' => 5000 ], [ 'id' => 5001 ] ];
		$ordinary = array_map( static fn ( int $id ): array => [ 'id' => $id ], range( 1, 1500 ) );
		$client = $this->client( $exact, $ordinary );
		$response = RankedSearch::search( $client, [ 'offset' => 999, 'limit' => PHP_INT_MAX ], [ 'Exact' ] );
		$this->assertSame( [ [ 'id' => 998 ] ], $response['hits'] );
		$this->assertSame( 1, $response['limit'] );
		$this->assertSame( 1000, $response['estimatedTotalHits'] );
		$this->assertSame( 1000, $client->queries[0]['hitsPerPage'] );
		$response = RankedSearch::search( $client, [ 'offset' => PHP_INT_MAX, 'limit' => PHP_INT_MAX ], [ 'Exact' ] );
		$this->assertSame( [], $response['hits'] );
		$this->assertSame( 0, $response['limit'] );
		$this->assertSame( PHP_INT_MAX, $response['offset'] );
	}

	public function testZeroLimitStillReportsCombinedTotal(): void {
		$client = $this->client( [ [ 'id' => 1 ] ], [ [ 'id' => 2 ] ] );
		$response = RankedSearch::search( $client, [ 'limit' => 0 ], [ 'Exact' ] );
		$this->assertSame( [], $response['hits'] );
		$this->assertSame( 2, $response['estimatedTotalHits'] );
		$this->assertSame( 1, $client->queries[0]['hitsPerPage'] );
		$this->assertSame( 0, $client->queries[1]['limit'] );
	}

	public static function malformedExactResponses(): array {
		return [
			'estimate only' => [ [ 'hits' => [], 'estimatedTotalHits' => 0 ] ],
			'string total' => [ [ 'hits' => [], 'totalHits' => '0' ] ],
			'negative total' => [ [ 'hits' => [], 'totalHits' => -1 ] ],
			'float total' => [ [ 'hits' => [], 'totalHits' => 1.0 ] ],
			'boolean total' => [ [ 'hits' => [], 'totalHits' => false ] ],
			'incomplete page' => [ [ 'hits' => [], 'totalHits' => 1 ] ],
			'overfull page' => [ [ 'hits' => [ [ 'id' => 1 ], [ 'id' => 2 ] ], 'totalHits' => 2 ] ],
			'count exceeds total' => [ [ 'hits' => [ [ 'id' => 1 ] ], 'totalHits' => 0 ] ],
			'wrong page' => [ [ 'hits' => [], 'totalHits' => 0, 'page' => 2 ] ],
			'wrong page size' => [ [ 'hits' => [], 'totalHits' => 0, 'hitsPerPage' => 2 ] ],
		];
	}

	#[DataProvider( 'malformedExactResponses' )]
	public function testMalformedExactCounterCannotDriveOrdinaryOffset( array $response ): void {
		$client = new RankedSearchClient( static fn (): array => $response );
		try {
			RankedSearch::search( $client, [ 'limit' => 1 ], [ 'Exact' ] );
			$this->fail( 'Expected malformed exact pagination to fail' );
		} catch ( MeilisearchException ) {
			$this->assertCount( 1, $client->queries );
		}
	}

	public static function malformedOrdinaryResponses(): array {
		return [
			'missing total' => [ [ 'hits' => [] ] ],
			'null estimate' => [ [ 'hits' => [], 'estimatedTotalHits' => null ] ],
			'string estimate' => [ [ 'hits' => [], 'estimatedTotalHits' => '2' ] ],
			'negative estimate' => [ [ 'hits' => [], 'estimatedTotalHits' => -1 ] ],
			'exceeds remaining limit' => [ [ 'hits' => [ [ 'id' => 2 ] ], 'estimatedTotalHits' => 1 ] ],
		];
	}

	#[DataProvider( 'malformedOrdinaryResponses' )]
	public function testMalformedOrdinaryResponseCannotFabricateTotalOrOverfillPage( array $response ): void {
		$client = new RankedSearchClient( static fn ( array $query ): array => isset( $query['page'] )
			? [ 'hits' => [ [ 'id' => 1 ] ], 'totalHits' => 1 ] : $response );
		$this->expectException( MeilisearchException::class );
		RankedSearch::search( $client, [ 'limit' => 1 ], [ 'Exact' ] );
	}

	public function testTransportFailurePropagatesWithoutReturningPartialExactMatches(): void {
		$error = new MeilisearchException( 'offline', true );
		$client = new RankedSearchClient( static function ( array $query ) use ( $error ): array {
			if ( isset( $query['page'] ) ) { return [ 'hits' => [ [ 'id' => 1 ] ], 'totalHits' => 1 ]; }
			throw $error;
		} );
		try {
			RankedSearch::search( $client, [ 'limit' => 2 ], [ 'Exact' ] );
			$this->fail( 'Expected transport failure' );
		} catch ( MeilisearchException $actual ) {
			$this->assertSame( $error, $actual );
		}
	}

	public static function invalidQueries(): array {
		return [ 'negative offset' => [ [ 'offset' => -1 ] ], 'string offset' => [ [ 'offset' => '0' ] ],
			'negative limit' => [ [ 'limit' => -1 ] ], 'string limit' => [ [ 'limit' => '1' ] ],
			'page pagination' => [ [ 'page' => 1 ] ], 'page size' => [ [ 'hitsPerPage' => 10 ] ],
			'invalid filters' => [ [ 'filter' => false ] ] ];
	}

	#[DataProvider( 'invalidQueries' )]
	public function testInvalidPaginationFailsBeforeRequests( array $query ): void {
		$client = $this->client( [], [] );
		try {
			RankedSearch::search( $client, $query, [ 'Exact' ] );
			$this->fail( 'Expected invalid query' );
		} catch ( \InvalidArgumentException ) {
			$this->assertSame( [], $client->queries );
		}
	}
}

class RankedSearchClient extends MeilisearchClient {
	public array $queries = [];

	public function __construct( private \Closure $response ) {
	}

	public function search( array $query ): array {
		$this->queries[] = $query;
		return ( $this->response )( $query );
	}
}

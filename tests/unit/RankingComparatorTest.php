<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RankingComparator;
use PHPUnit\Framework\TestCase;

class RankingComparatorTest extends TestCase {
	public function testRankingRequestsExplicitlySelectFullTextAndCompletion(): void {
		require_once dirname( __DIR__ ) . '/integration/checkRanking.php';
		$this->assertSame( [
			'action' => 'query', 'format' => 'json', 'list' => 'search', 'srwhat' => 'text',
			'srsearch' => 'naru', 'srnamespace' => 6, 'srlimit' => 10,
		], \frauxSearchRankingRequest( [ 'type' => 'fulltext', 'query' => 'naru', 'namespace' => 6 ], 10 ) );
		$this->assertSame( [
			'action' => 'opensearch', 'format' => 'json', 'formatversion' => 2,
			'search' => 'naru', 'namespace' => 6, 'limit' => 10,
		], \frauxSearchRankingRequest( [ 'type' => 'completion', 'query' => 'naru', 'namespace' => 6 ], 10 ) );
	}

	public function testExactOrdering(): void {
		$this->assertSame( [
			'exact' => true,
			'top_match' => true,
			'position_matches' => 3,
			'overlap' => 3,
			'target_count' => 3,
			'actual_count' => 3,
		], RankingComparator::compare( [ 'A', 'B', 'C' ], [ 'A', 'B', 'C' ] ) );
	}

	public function testDifferentOrderingAndMembership(): void {
		$this->assertSame( [
			'exact' => false,
			'top_match' => false,
			'position_matches' => 0,
			'overlap' => 2,
			'target_count' => 3,
			'actual_count' => 3,
		], RankingComparator::compare( [ 'A', 'B', 'C' ], [ 'B', 'C', 'D' ] ) );
	}

	public function testRankingFixtureIsCompleteAndUnique(): void {
		$fixture = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/fixtures/ranking.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame( 10, $fixture['limit'] );
		$ids = [];
		foreach ( $fixture['cases'] as $case ) {
			$this->assertContains( $case['type'], [ 'completion', 'fulltext' ] );
			$this->assertCount( 10, $case['production'] );
			$this->assertCount( 10, $case['frauxsearch_baseline'] );
			$ids[] = $case['id'];
		}
		$this->assertCount( count( $ids ), array_unique( $ids ) );
	}
}

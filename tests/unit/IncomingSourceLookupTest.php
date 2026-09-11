<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IncomingSourceLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/incoming.php';

class IncomingSourceLookupTest extends TestCase {
	public function testUnionPaginatesWithoutLosingOverlappingOrRedirectOnlySources(): void {
		$database = new IncomingTestDatabase();
		$database->linktargets = [ [ 'lt_id' => 1, 'lt_namespace' => 0, 'lt_title' => 'Missing_target' ] ];
		foreach ( [ 9, 1, 5, 3, 3 ] as $id ) {
			$database->pagelinks[] = [ 'pl_from' => $id, 'pl_target_id' => 1 ];
		}
		foreach ( [ 10, 2, 3, 5, 6 ] as $id ) {
			$database->redirects[] = [ 'rd_from' => $id, 'rd_namespace' => 0,
				'rd_title' => 'Missing_target', 'rd_interwiki' => $id === 2 ? null : '' ];
		}
		$lookup = new IncomingSourceLookup( $database );
		$first = $lookup->getSourceIds( 0, 'Missing_target', 0, 3 );
		$second = $lookup->getSourceIds( 0, 'Missing_target', end( $first ), 3 );
		$third = $lookup->getSourceIds( 0, 'Missing_target', end( $second ), 3 );
		$this->assertSame( [ [ 1, 2, 3 ], [ 5, 6, 9 ], [ 10 ] ], [ $first, $second, $third ] );
		$this->assertSame( [], $lookup->getSourceIds( 0, 'Missing_target', 10, 3 ) );
		$this->assertCount( 8, $database->queries );
		foreach ( $database->queries as $query ) {
			$this->assertSame( 3, $query->rowLimit );
			$this->assertLessThanOrEqual( 3, $query->returnedRows );
		}
	}

	public function testMatchesExactTitleAndNamespaceAndOnlyLocalRedirectRows(): void {
		$database = new IncomingTestDatabase();
		$database->linktargets = [
			[ 'lt_id' => 1, 'lt_namespace' => 0, 'lt_title' => 'Target' ],
			[ 'lt_id' => 2, 'lt_namespace' => 1, 'lt_title' => 'Target' ],
			[ 'lt_id' => 3, 'lt_namespace' => 0, 'lt_title' => 'Other' ],
		];
		$database->pagelinks = [
			[ 'pl_from' => 1, 'pl_target_id' => 1 ],
			[ 'pl_from' => 2, 'pl_target_id' => 2 ],
			[ 'pl_from' => 3, 'pl_target_id' => 3 ],
		];
		$database->redirects = [
			[ 'rd_from' => 4, 'rd_namespace' => 0, 'rd_title' => 'Target', 'rd_interwiki' => 'w' ],
			[ 'rd_from' => 5, 'rd_namespace' => 1, 'rd_title' => 'Target', 'rd_interwiki' => '' ],
			[ 'rd_from' => 6, 'rd_namespace' => 0, 'rd_title' => 'Other', 'rd_interwiki' => '' ],
			[ 'rd_from' => 7, 'rd_namespace' => 0, 'rd_title' => 'Target', 'rd_interwiki' => null ],
			[ 'rd_from' => 8, 'rd_namespace' => 0, 'rd_title' => 'Target', 'rd_interwiki' => '' ],
		];
		$this->assertSame( [ 1, 7, 8 ], ( new IncomingSourceLookup( $database ) )->getSourceIds( 0, 'Target', 0, 10 ) );
	}

	public static function invalidLookups(): array {
		return [
			'negative namespace' => [ -1, 'Target', 0, 10 ],
			'empty title' => [ 0, '', 0, 10 ],
			'negative cursor' => [ 0, 'Target', -1, 10 ],
			'zero limit' => [ 0, 'Target', 0, 0 ],
			'negative limit' => [ 0, 'Target', 0, -1 ],
		];
	}

	#[DataProvider( 'invalidLookups' )]
	public function testInvalidLookupDoesNotReadDatabase( int $namespace, string $title, int $cursor, int $limit ): void {
		$database = new IncomingTestDatabase();
		try {
			( new IncomingSourceLookup( $database ) )->getSourceIds( $namespace, $title, $cursor, $limit );
			$this->fail( 'Invalid lookup should fail.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [], $database->queries );
		}
	}
}

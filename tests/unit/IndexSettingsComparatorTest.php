<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexSettingsComparator;
use FrauxSearch\MeilisearchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IndexSettingsComparatorTest extends TestCase {
	public function testUnownedServerDefaultsAndObjectKeyOrderAreIgnored(): void {
		$expected = MeilisearchClient::expectedIndexSettings();
		$actual = array_reverse( $expected, true );
		$actual['typoTolerance'] = array_reverse( $actual['typoTolerance'], true ) + [ 'disableOnAttributes' => [] ];
		$actual['pagination'] = [ 'maxTotalHits' => 1000 ];
		$this->assertSame( [], IndexSettingsComparator::differences( $expected, $actual ) );
	}

	public function testAttributeSetsCanBeReturnedInDifferentOrder(): void {
		$expected = MeilisearchClient::expectedIndexSettings();
		$actual = $expected;
		foreach ( [ 'displayedAttributes', 'filterableAttributes', 'sortableAttributes' ] as $name ) {
			$actual[$name] = array_reverse( $actual[$name] );
		}
		$this->assertSame( [], IndexSettingsComparator::differences( $expected, $actual ) );
	}

	public static function changes(): array {
		return [
			'priority of title matches' => [ 'searchableAttributes', [ 'text', 'redirects', 'title' ] ],
			'ranking order' => [ 'rankingRules', [ 'words', 'sort' ] ],
			'redirect filter missing' => [ 'filterableAttributes', [ 'id', 'namespace' ] ],
			'text no longer displayed' => [ 'displayedAttributes', [ 'id', 'title' ] ],
			'unexpected sortable field' => [ 'sortableAttributes', [ 'id', 'incoming_links', 'timestamp' ] ],
			'changed typo threshold' => [ 'typoTolerance', [ 'enabled' => true, 'minWordSizeForTypos' => [ 'oneTypo' => 5, 'twoTypos' => 8 ] ] ],
			'wrong setting type' => [ 'typoTolerance', false ],
		];
	}

	#[DataProvider( 'changes' )]
	public function testOwnedSettingsDriftIsReportedByName( string $field, $value ): void {
		$expected = MeilisearchClient::expectedIndexSettings();
		$actual = $expected;
		$actual[$field] = $value;
		$this->assertSame( [ $field ], IndexSettingsComparator::differences( $expected, $actual ) );
	}

	public function testMissingOwnedSettingIsReported(): void {
		$expected = MeilisearchClient::expectedIndexSettings();
		$actual = $expected;
		unset( $actual['rankingRules'] );
		$this->assertSame( [ 'rankingRules' ], IndexSettingsComparator::differences( $expected, $actual ) );
	}
}

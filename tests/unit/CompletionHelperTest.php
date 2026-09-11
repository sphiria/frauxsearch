<?php

namespace FrauxSearch\Tests;

use FrauxSearch\CompletionHelper;
use PHPUnit\Framework\TestCase;

class CompletionHelperTest extends TestCase {
	public function testSelectsFirstCaseInsensitivePrefixAlias(): void {
		$this->assertSame( 'Naru', CompletionHelper::matchingRedirect(
			' naru ',
			[ 'Narumeia', 'Naru', 'Other' ]
		) );
	}

	public function testReturnsNullWithoutPrefixAlias(): void {
		$this->assertNull( CompletionHelper::matchingRedirect( 'nier', [ 'Nia', 'Death' ] ) );
	}

	public function testFiltersRedirectPagesFromCompletionCandidates(): void {
		$this->assertSame( [ [ 'id' => 1 ], [ 'id' => 3 ] ], CompletionHelper::canonicalDocuments(
			[ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ] ],
			[ 2 => true ]
		) );
	}
}

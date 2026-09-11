<?php

namespace FrauxSearch;

use MediaWiki\Search\SearchResultSet;

class FrauxSearchResultSet extends SearchResultSet {
	private int $totalHits;

	public function __construct(
		array $results,
		int $totalHits,
		bool $hasMoreResults,
		private bool $approximateTotalHits = false,
		bool $containedSyntax = false
	) {
		parent::__construct( $containedSyntax, $hasMoreResults );
		$this->results = $results;
		$this->totalHits = $totalHits;
	}

	public function getTotalHits() {
		return $this->totalHits;
	}

	public function isApproximateTotalHits(): bool {
		return $this->approximateTotalHits;
	}
}

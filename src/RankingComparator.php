<?php

namespace FrauxSearch;

class RankingComparator {
	public static function compare( array $target, array $actual ): array {
		$positionMatches = 0;
		foreach ( $target as $position => $title ) {
			if ( ( $actual[$position] ?? null ) === $title ) {
				$positionMatches++;
			}
		}
		return [
			'exact' => $target === $actual,
			'top_match' => ( $target[0] ?? null ) === ( $actual[0] ?? null ),
			'position_matches' => $positionMatches,
			'overlap' => count( array_intersect( $target, $actual ) ),
			'target_count' => count( $target ),
			'actual_count' => count( $actual ),
		];
	}
}

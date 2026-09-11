<?php

namespace FrauxSearch;

class IndexSettingsComparator {
	/** @return string[] */
	public static function differences( array $expected, array $actual ): array {
		$differences = [];
		foreach ( $expected as $name => $value ) {
			$present = array_key_exists( $name, $actual );
			$matches = $present && self::matches( $value, $actual[$name] );
			if ( $present && in_array( $name,
				[ 'displayedAttributes', 'filterableAttributes', 'sortableAttributes' ], true )
			) {
				$matches = self::sameStringSet( $value, $actual[$name] );
			}
			if ( !$matches ) { $differences[] = $name; }
		}
		return $differences;
	}

	private static function matches( $expected, $actual ): bool {
		if ( !is_array( $expected ) || array_is_list( $expected ) ) { return $expected === $actual; }
		if ( !is_array( $actual ) ) { return false; }
		foreach ( $expected as $key => $value ) {
			if ( !array_key_exists( $key, $actual ) || !self::matches( $value, $actual[$key] ) ) { return false; }
		}
		return true;
	}

	private static function sameStringSet( array $expected, $actual ): bool {
		if ( !is_array( $actual ) || !array_is_list( $actual ) ) { return false; }
		foreach ( $actual as $value ) { if ( !is_string( $value ) ) { return false; } }
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		return $expected === $actual;
	}
}

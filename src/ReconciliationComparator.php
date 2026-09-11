<?php

namespace FrauxSearch;

class ReconciliationComparator {
	public static function compare( ?array $built, ?array $fullText, ?array $completion ): array {
		if ( $built === null ) {
			$issues = [ 'source_unbuildable' ];
			if ( $fullText !== null ) {
				$issues[] = 'full_text_unexpected';
			}
			if ( $completion !== null ) {
				$issues[] = 'completion_unexpected';
			}
			return $issues;
		}

		$issues = [];
		$expected = $built['document'];
		if ( $fullText === null ) {
			$issues[] = 'full_text_missing';
		} elseif ( !self::isCurrent( $expected, $fullText ) ) {
			$issues[] = 'full_text_stale';
		}

		if ( $built['is_redirect'] ) {
			if ( $completion !== null ) {
				$issues[] = 'completion_unexpected';
			}
		} elseif ( $completion === null ) {
			$issues[] = 'completion_missing';
		} elseif ( !self::isCurrent( $expected, $completion ) ) {
			$issues[] = 'completion_stale';
		}

		return $issues;
	}

	private static function isCurrent( array $expected, array $actual ): bool {
		if ( !isset( $actual['revision_id'] ) || !isset( $actual['document_hash'] )
			|| !is_string( $actual['document_hash'] )
			|| $actual['revision_id'] !== $expected['revision_id']
			|| !hash_equals( $expected['document_hash'], $actual['document_hash'] )
			|| count( $expected ) !== count( $actual )
		) { return false; }
		$payload = [];
		foreach ( $expected as $field => $value ) {
			if ( !array_key_exists( $field, $actual ) ) { return false; }
			$payload[$field] = $actual[$field];
		}
		try {
			return hash_equals( $expected['document_hash'], DocumentHash::compute( $payload ) );
		} catch ( \JsonException ) {
			return false;
		}
	}

	public static function needsRepair( array $issues ): bool {
		return array_diff( $issues, [ 'source_unbuildable' ] ) !== [];
	}
}

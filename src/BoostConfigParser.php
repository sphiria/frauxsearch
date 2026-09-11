<?php

namespace FrauxSearch;

class BoostConfigParser {
	public static function calculateBoost( array $rules, array $templates ): int {
		$templates = array_values( array_unique( array_map( 'strval', $templates ) ) );
		sort( $templates, SORT_STRING );
		$boost = 100;
		foreach ( $templates as $template ) {
			if ( isset( $rules[$template] ) ) {
				$boost = (int)round( $boost * $rules[$template] / 100 );
			}
		}
		return $boost;
	}

	public static function changedTemplates( array $oldRules, array $newRules ): array {
		$templates = array_unique( array_merge( array_keys( $oldRules ), array_keys( $newRules ) ) );
		return array_values( array_filter(
			$templates,
			static fn ( string $template ) => ( $oldRules[$template] ?? null ) !== ( $newRules[$template] ?? null )
		) );
	}

	public static function parse( string $text ): array {
		return self::parseWithWarnings( $text )['rules'];
	}

	public static function parseWithWarnings( string $text ): array {
		$boosts = [];
		$warnings = [];
		foreach ( preg_split( '/\R/', $text ) as $lineNumber => $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( $line === '' || str_contains( $line, '<pre>' ) || str_contains( $line, '</pre>' ) ) {
				continue;
			}
			if ( preg_match( '/^(?:Template:)?(.+?)\|(\d+)%$/', $line, $matches ) ) {
				$boosts[str_replace( ' ', '_', $matches[1] )] = (int)$matches[2];
			} else {
				$warnings[] = 'Line ' . ( $lineNumber + 1 ) . ': invalid boost rule';
			}
		}
		return [ 'rules' => $boosts, 'warnings' => $warnings ];
	}
}

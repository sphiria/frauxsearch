<?php

namespace FrauxSearch;

class CompletionHelper {
	public static function matchingRedirect( string $search, array $redirects ): ?string {
		$search = mb_strtolower( trim( $search ) );
		$prefix = null;
		foreach ( $redirects as $redirect ) {
			$normalized = mb_strtolower( $redirect );
			if ( $normalized === $search ) {
				return $redirect;
			}
			if ( $prefix === null && str_starts_with( $normalized, $search ) ) {
				$prefix = $redirect;
			}
		}
		return $prefix;
	}

	public static function canonicalDocuments( array $documents, array $redirectPageIds ): array {
		return array_values( array_filter(
			$documents,
			static fn ( array $document ) => !isset( $redirectPageIds[(int)$document['id']] )
		) );
	}
}

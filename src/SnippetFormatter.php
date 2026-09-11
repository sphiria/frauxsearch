<?php

namespace FrauxSearch;

class SnippetFormatter {
	public static function format( string $text, array $highlightTags = [] ): string {
		$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		if ( count( $highlightTags ) === 2 ) {
			$text = str_replace(
				$highlightTags,
				[ '<span class="searchmatch">', '</span>' ],
				$text
			);
		}
		return $text;
	}
}

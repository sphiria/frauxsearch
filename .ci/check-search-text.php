<?php

namespace MediaWiki\Content {
	function wfMessage( string $key ): object {
		if ( $key !== 'search-ignored-headings' ) {
			throw new \RuntimeException( "Unexpected message lookup during text extraction: $key." );
		}
		return new class {
			public function inContentLanguage(): self { return $this; }
			public function isDisabled(): bool { return true; }
		};
	}
}

namespace {
	use FrauxSearch\SearchTextExtractor;
	use FrauxSearch\SnippetFormatter;
	use MediaWiki\Parser\ParserOutput;
	use Wikimedia\Parsoid\Core\SectionMetadata;
	use Wikimedia\Parsoid\Core\TOCData;

	$output = new ParserOutput( '<p>Fish &amp; chips</p><p>Next <b>bold</b> 猫.</p>'
		. '<style>css-noise</style><div class="navigation-not-searchable">navigation-noise</div>'
		. '<div class="autocollapse">collapsed-noise</div><sup class="reference">reference-noise</sup>'
		. '<h2>Abilities</h2><table><tr><td>Attack</td><td>100%</td></tr></table>'
		. '<figure><figcaption>Portrait caption</figcaption></figure>'
		. '<p>&lt;img src=x onerror=alert(1)&gt;</p>' );
	$output->setTOCData( new TOCData( new SectionMetadata( line: '<b>Abilities</b>' ) ) );
	$expected = 'Fish & chips Next bold 猫. <img src=x onerror=alert(1)> Abilities Portrait caption Attack 100%';
	$actual = SearchTextExtractor::fromParserOutput( $output );
	if ( $actual !== $expected ) {
		throw new RuntimeException( 'Core search text extraction changed: ' . json_encode( $actual ) );
	}
	$snippet = SnippetFormatter::format( str_replace( 'bold', '__open__bold__close__', $actual ),
		[ '__open__', '__close__' ] );
	if ( str_contains( $snippet, '<img' ) || !str_contains( $snippet, '&lt;img src=x onerror=alert(1)&gt;' )
		|| !str_contains( $snippet, '<span class="searchmatch">bold</span>' )
	) {
		throw new RuntimeException( 'Extracted search text did not remain safely escaped in snippets.' );
	}
	if ( SearchTextExtractor::fromParserOutput( new ParserOutput( '' ) ) !== '' ) {
		throw new RuntimeException( 'Empty parser output did not produce empty search text.' );
	}
	try {
		SearchTextExtractor::fromParserOutput( new ParserOutput( null ) );
		throw new LogicException( 'Metadata-only parser output was accepted as search text.' );
	} catch ( RuntimeException ) {
	}
	fwrite( STDOUT, "Core search text extraction preserves headings, tables and captions; snippets remain escaped.\n" );
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\QuerySyntaxException;
use FrauxSearch\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchQueryTest extends TestCase {
	#[DataProvider( 'provideQueries' )]
	public function testNormalizesSupportedQueries( string $input, string $query, bool $syntax ): void {
		$this->assertSame( [ 'q' => $query, 'hasSyntax' => $syntax ], SearchQuery::parse( $input ) );
	}

	public static function provideQueries(): iterable {
		yield 'empty' => [ '', '', false ];
		yield 'spaces' => [ " \t ", '', false ];
		yield 'normal words' => [ "  narmaya\t summer  ", 'narmaya summer', false ];
		yield 'unicode words' => [ "ナルメア\u{2003}水着", 'ナルメア 水着', false ];
		yield 'variant title' => [ 'Narmaya (Summer)', 'Narmaya (Summer)', false ];
		yield 'literal plus' => [ 'C++ foo+bar', 'C++ foo+bar', false ];
		yield 'literal hyphen' => [ 'Sky-Blue', 'Sky-Blue', false ];
		yield 'unknown colon' => [ 'Event: New World', 'Event: New World', false ];
		yield 'url' => [ 'https://gbf.wiki/Narmaya', 'https://gbf.wiki/Narmaya', false ];
		yield 'question in title' => [ 'What Makes the Sky Blue?', 'What Makes the Sky Blue?', false ];
		yield 'question in url' => [ 'https://example.invalid/?title=Narmaya', 'https://example.invalid/?title=Narmaya', false ];
		yield 'leading tilde' => [ '~Narmaya', '~Narmaya', false ];
		yield 'literal middle tilde' => [ 'sky~blue', 'sky~blue', false ];
		yield 'quoted punctuation' => [ '"sky* blue~2 \\?"', '"sky* blue~2 \\?"', true ];
		yield 'literal boolean words' => [ 'AND NOT or', 'AND NOT or', false ];
		yield 'required' => [ '+narmaya +summer', 'narmaya summer', true ];
		yield 'required phrase' => [ '+"sky blue"', '"sky blue"', true ];
		yield 'phrase' => [ '"sky blue" sword', '"sky blue" sword', true ];
		yield 'phrase punctuation' => [ '"Sky, blue!"', '"Sky, blue!"', true ];
		yield 'quoted operator' => [ '"intitle: OR"', '"intitle: OR"', true ];
		yield 'adjacent phrase' => [ 'sky"blue sword"', 'sky"blue sword"', true ];
		yield 'adjacent literal minus' => [ '"sky"-blue', '"sky"-blue', true ];
		yield 'adjacent literal plus' => [ '"sky"+blue', '"sky"+blue', true ];
		yield 'positive after negative phrase' => [ 'sky -"blue"sword', '-"blue" sky sword', true ];
		yield 'literal minus after negative phrase' => [ '-"blue"-sword', '-"blue" "-sword"', true ];
		yield 'negative word' => [ 'narmaya -summer', '-"summer" narmaya', true ];
		yield 'negative phrase' => [ 'sword -"sky blue"', '-"sky blue" sword', true ];
		yield 'negative compound' => [ '-sky-blue sword', '-"sky-blue" sword', true ];
		yield 'negative only' => [ '-summer -"grand character"', '-"summer" -"grand character"', true ];
		yield 'exclude literal OR' => [ '-OR', '-"OR"', true ];
		yield 'late exclusion' => [
			'one two three four five six seven eight nine ten -eleven',
			'-"eleven" one two three four five six seven eight nine ten', true,
		];
	}

	#[DataProvider( 'provideInvalidQueries' )]
	public function testRejectsUnsupportedOrMalformedQueries( string $input, string $message, array $parameters ): void {
		try {
			SearchQuery::parse( $input );
			$this->fail( 'Expected syntax error' );
		} catch ( QuerySyntaxException $e ) {
			$this->assertSame( 'frauxsearch-query-' . $message, $e->getMessageKey() );
			$this->assertSame( $parameters, $e->getMessageParameters() );
		}
	}

	public static function provideInvalidQueries(): iterable {
		yield 'invalid UTF-8' => [ "\xFF", 'invalid-encoding', [] ];
		yield 'unclosed phrase' => [ 'sky "blue', 'unclosed-quote', [] ];
		yield 'empty phrase' => [ '""', 'empty-clause', [] ];
		yield 'empty unicode phrase' => [ "\"\u{2003}\"", 'empty-clause', [] ];
		yield 'empty negative phrase' => [ '-""', 'empty-clause', [] ];
		yield 'bare required' => [ '+', 'empty-clause', [] ];
		yield 'bare negative' => [ '- ', 'empty-clause', [] ];
		yield 'spaced required' => [ '+ word', 'empty-clause', [] ];
		yield 'combined signs' => [ '+-word', 'empty-clause', [] ];
		yield 'repeated sign' => [ '--word', 'empty-clause', [] ];
		yield 'escaped quote' => [ '"sky \\"blue"', 'escaped-quote', [] ];
		yield 'OR' => [ 'sky OR blue', 'unsupported-operator', [ 'OR' ] ];
		yield 'required OR' => [ '+OR', 'unsupported-operator', [ 'OR' ] ];
		yield 'suffix wildcard' => [ 'narm*', 'unsupported-operator', [ '*' ] ];
		yield 'middle wildcard' => [ 'nar*aya', 'unsupported-operator', [ '*' ] ];
		yield 'single-character wildcard' => [ 'narm\\?ya', 'unsupported-operator', [ '\\?' ] ];
		yield 'fuzzy suffix' => [ 'narmaya~2', 'unsupported-operator', [ '~' ] ];
		yield 'fuzzy default' => [ 'narmaya~', 'unsupported-operator', [ '~' ] ];
		yield 'fuzzy fractional' => [ 'narmaya~.5', 'unsupported-operator', [ '~' ] ];
		yield 'negative fuzzy' => [ '-narmaya~0.5', 'unsupported-operator', [ '~' ] ];
		yield 'proximity suffix' => [ '"sky blue"~2', 'unsupported-operator', [ '~' ] ];
		yield 'phrase fuzzy default' => [ '"sky blue"~', 'unsupported-operator', [ '~' ] ];
		foreach ( [ 'intitle', 'incategory', 'deepcat', 'hastemplate', 'insource', 'linksto',
			'subpageof', 'prefix', 'contentmodel', 'inlanguage', 'filetype', 'filemime',
			'filew', 'fileh', 'filesize', 'morelike', 'boost-templates', 'filewidth', 'fileheight',
			'fileres', 'filebits', 'onlyredirects', 'withredirects', 'pageid', 'creationdate',
			'lasteditdate', 'prefer-recent', 'articletopic', 'articlecountry', 'hasrecommendation',
			'nearcoord', 'neartitle', 'boost-nearcoord', 'boost-neartitle' ] as $operator ) {
			yield $operator => [ $operator . ':value', 'unsupported-operator', [ $operator . ':' ] ];
		}
		yield 'mixed case' => [ 'InTitle:"sky blue"', 'unsupported-operator', [ 'InTitle:' ] ];
		yield 'negative operator' => [ '-incategory:Weapons', 'unsupported-operator', [ 'incategory:' ] ];
		yield 'required operator' => [ '+intitle:Sword', 'unsupported-operator', [ 'intitle:' ] ];
	}
}

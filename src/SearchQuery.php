<?php

namespace FrauxSearch;

class SearchQuery {
	private const UNSUPPORTED_OPERATORS = [
		'intitle', 'allintitle', 'incategory', 'deepcat', 'deepcategory', 'hastemplate',
		'insource', 'linksto', 'subpageof', 'prefix', 'contentmodel', 'inlanguage',
		'filetype', 'filemime', 'filew', 'fileh', 'filesize', 'morelike', 'boost-templates',
		'filewidth', 'fileheight', 'fileres', 'filebits', 'onlyredirects', 'withredirects',
		'pageid', 'creationdate', 'lasteditdate', 'prefer-recent', 'articletopic',
		'articlecountry', 'hasrecommendation', 'nearcoord', 'neartitle',
		'boost-nearcoord', 'boost-neartitle',
	];

	/** @return array{q:string,hasSyntax:bool} */
	public static function parse( string $term ): array {
		if ( preg_match( '//u', $term ) !== 1 ) {
			throw new QuerySyntaxException( 'frauxsearch-query-invalid-encoding' );
		}
		if ( str_contains( $term, '\\"' ) ) {
			throw new QuerySyntaxException( 'frauxsearch-query-escaped-quote' );
		}
		$positive = [];
		$negative = [];
		$hasSyntax = false;
		$offset = 0;
		$length = strlen( $term );
		$boundary = true;
		$previousPositive = false;
		$previousQuoted = false;
		while ( $offset < $length ) {
			if ( preg_match( '/\G\s+/u', $term, $match, 0, $offset ) ) {
				$offset += strlen( $match[0] );
				$boundary = true;
				continue;
			}
			$sign = '';
			if ( $boundary && ( $term[$offset] === '+' || $term[$offset] === '-' ) ) {
				$sign = $term[$offset++];
				$hasSyntax = true;
			}
			if ( $offset === $length || preg_match( '/\G\s/u', $term, $match, 0, $offset ) ) {
				throw new QuerySyntaxException( 'frauxsearch-query-empty-clause' );
			}
			$quoted = $term[$offset] === '"';
			if ( $quoted ) {
				$end = strpos( $term, '"', $offset + 1 );
				if ( $end === false ) {
					throw new QuerySyntaxException( 'frauxsearch-query-unclosed-quote' );
				}
				$clause = substr( $term, $offset + 1, $end - $offset - 1 );
				$offset = $end + 1;
				$hasSyntax = true;
				if ( preg_match( '/^\s*$/u', $clause ) ) {
					throw new QuerySyntaxException( 'frauxsearch-query-empty-clause' );
				}
			} else {
				preg_match( '/\G[^\s"]+/u', $term, $match, 0, $offset );
				$clause = $match[0];
				$offset += strlen( $clause );
				self::validateWord( $clause, $sign, !$boundary && $previousQuoted );
			}
			if ( $sign === '-' ) {
				$negative[] = '-"' . $clause . '"';
				$previousPositive = false;
			} else {
				if ( !$boundary && !$previousPositive && ( $clause[0] === '+' || $clause[0] === '-' ) ) {
					$quoted = true;
				}
				$value = $quoted ? '"' . $clause . '"' : $clause;
				if ( !$boundary && $previousPositive ) {
					$positive[count( $positive ) - 1] .= $value;
				} else {
					$positive[] = $value;
				}
				$previousPositive = true;
			}
			$boundary = false;
			$previousQuoted = $quoted;
		}
		return [ 'q' => implode( ' ', array_merge( $negative, $positive ) ), 'hasSyntax' => $hasSyntax ];
	}

	private static function validateWord( string $word, string $sign, bool $afterPhrase ): void {
		if ( $sign !== '' && ( $word[0] === '+' || $word[0] === '-' ) ) {
			throw new QuerySyntaxException( 'frauxsearch-query-empty-clause' );
		}
		if ( $word === 'OR' && $sign !== '-' ) {
			throw new QuerySyntaxException( 'frauxsearch-query-unsupported-operator', [ 'OR' ] );
		}
		if ( str_contains( $word, '*' ) ) {
			throw new QuerySyntaxException( 'frauxsearch-query-unsupported-operator', [ '*' ] );
		}
		if ( str_contains( $word, '\\?' ) ) {
			throw new QuerySyntaxException( 'frauxsearch-query-unsupported-operator', [ '\\?' ] );
		}
		if ( ( $afterPhrase && $word[0] === '~' )
			|| preg_match( '/[\p{L}\p{N}]~(?:\d+(?:\.\d+)?|\.\d+)?~?$/u', $word )
		) {
			throw new QuerySyntaxException( 'frauxsearch-query-unsupported-operator', [ '~' ] );
		}
		$colon = strpos( $word, ':' );
		if ( $colon !== false ) {
			$operator = substr( $word, 0, $colon );
			if ( in_array( strtolower( $operator ), self::UNSUPPORTED_OPERATORS, true ) ) {
				throw new QuerySyntaxException( 'frauxsearch-query-unsupported-operator', [ $operator . ':' ] );
			}
		}
	}
}

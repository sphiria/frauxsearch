<?php

namespace FrauxSearch;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Content\WikiTextStructure;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;

class SearchTextExtractor {
	public static function extract( WikitextContent $content, WikiPage $page, RevisionRecord $revision ): string {
		MediaWikiServices::getInstance()->getParserOutputAccess()->clearLocalCache();
		$output = $content->getContentHandler()->getParserOutputForIndexing( $page, null, $revision );
		if ( $output === null ) {
			throw new \RuntimeException( 'Unable to render FrauxSearch indexing content.' );
		}
		return self::fromParserOutput( $output );
	}

	public static function fromParserOutput( ParserOutput $output ): string {
		if ( !$output->hasText() ) {
			throw new \RuntimeException( 'FrauxSearch indexing parser output has no text.' );
		}
		$structure = new WikiTextStructure( $output );
		$text = implode( ' ', [
			$structure->getMainText(),
			...$structure->headings(),
			...$structure->getAuxiliaryText(),
		] );
		$normalized = preg_replace( '/\s+/u', ' ', $text );
		if ( $normalized === null ) {
			throw new \RuntimeException( 'FrauxSearch indexing parser output contains invalid UTF-8.' );
		}
		return trim( $normalized );
	}
}

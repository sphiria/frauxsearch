<?php

namespace FrauxSearch;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Search\SearchResult;
use MediaWiki\Title\Title;

class FrauxSearchResult extends SearchResult {
	private ?Title $title;
	private string $titleSnippet;
	private string $textSnippet;
	private string $timestamp;
	private int $wordCount;
	private int $byteSize;

	public function __construct( array $hit, array $highlightTags = [] ) {
		$this->title = IndexedTitle::fromDocument( $hit );
		$formatted = $hit['_formatted'] ?? [];
		$this->titleSnippet = SnippetFormatter::format(
			(string)( $formatted['title'] ?? $hit['title'] ), $highlightTags
		);
		$this->textSnippet = SnippetFormatter::format( (string)( $formatted['text'] ?? '' ), $highlightTags );
		$this->timestamp = (string)( $hit['timestamp'] ?? '' );
		$this->wordCount = (int)( $hit['word_count'] ?? 0 );
		$this->byteSize = (int)( $hit['byte_size'] ?? 0 );
	}

	public function isBrokenTitle() { return $this->title === null; }
	public function isMissingRevision() { return false; }
	public function getTitle() { return $this->title; }
	public function getFile(): ?File { return null; }
	public function getTextSnippet( $terms = [] ) { return $this->textSnippet; }
	public function getTextSnippetField() { return 'text'; }
	public function getTitleSnippet() { return $this->titleSnippet; }
	public function getTitleSnippetField() { return 'title'; }
	public function getRedirectSnippet() { return ''; }
	public function getRedirectTitle() { return null; }
	public function getSectionSnippet() { return ''; }
	public function getSectionTitle() { return null; }
	public function getCategorySnippet() { return ''; }
	public function getTimestamp() { return $this->timestamp; }
	public function getWordCount() { return $this->wordCount; }
	public function getByteSize() { return $this->byteSize; }
	public function getInterwikiPrefix() { return ''; }
	public function getInterwikiNamespaceText() { return ''; }
	public function isFileMatch() { return false; }
}

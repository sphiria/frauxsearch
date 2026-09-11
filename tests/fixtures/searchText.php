<?php

namespace MediaWiki\Content;

use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;

class WikitextContent {
	public ?ParserOutput $output;
	public array $calls = [];
	public ?\Throwable $failure = null;
	public function __construct( ?ParserOutput $output ) { $this->output = $output; }
	public function getContentHandler(): self { return $this; }
	public function getTextForSearchIndex(): never { throw new \LogicException( 'Raw wikitext must not be indexed.' ); }
	public function getParserOutputForIndexing( WikiPage $page, $cache, RevisionRecord $revision ): ?ParserOutput {
		$this->calls[] = [ $page, $cache, $revision ];
		if ( $this->failure !== null ) { throw $this->failure; }
		return \MediaWiki\MediaWikiServices::getInstance()->getParserOutputAccess()->getOutput( $this->output );
	}
}

class WikiTextStructure {
	public function __construct( private ParserOutput $output ) {}
	public function getMainText(): string { return $this->output->main; }
	public function headings(): array { return $this->output->headings; }
	public function getAuxiliaryText(): array { return $this->output->auxiliary; }
}

namespace MediaWiki\Parser;

class ParserOutput {
	public function __construct( public string $main = '', public array $headings = [],
		public array $auxiliary = [], public bool $textAvailable = true
	) {}
	public function hasText(): bool { return $this->textAvailable; }
}

namespace MediaWiki\Page;

use MediaWiki\Revision\RevisionRecord;

class WikiPage {
	public function __construct( public RevisionRecord $revision ) {}
	public function getRevisionRecord(): RevisionRecord { return $this->revision; }
	public function getTitle(): self { return $this; }
	public function getPrefixedText(): string { return 'Search text fixture'; }
	public function getNamespace(): int { return 0; }
	public function isRedirect(): bool { return false; }
}

namespace MediaWiki\Revision;

class RevisionRecord {
	public function __construct( public object $content ) {}
	public function getContent( string $slot ): object { return $this->content; }
	public function getId(): int { return 123; }
	public function getTimestamp(): string { return '20260908000000'; }
}

namespace FrauxSearch\Tests;

use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;

class SearchTextParserOutputAccess {
	public ?ParserOutput $local = null;
	public ?ParserOutput $shared = null;
	public int $clears = 0;
	public int $sharedHits = 0;
	public int $renders = 0;
	public function clearLocalCache(): void { $this->local = null; $this->clears++; }
	public function getOutput( ?ParserOutput $rendered ): ?ParserOutput {
		if ( $this->local !== null ) { return $this->local; }
		if ( $this->shared !== null ) {
			$this->sharedHits++;
			return $this->local = $this->shared;
		}
		$this->renders++;
		return $this->local = $rendered;
	}
}

class SearchTextServices {
	public SearchTextParserOutputAccess $parserOutputAccess;
	public function __construct( public ?WikiPage $page = null ) {
		$this->parserOutputAccess = new SearchTextParserOutputAccess();
	}
	public function getParserOutputAccess(): SearchTextParserOutputAccess { return $this->parserOutputAccess; }
	public function getWikiPageFactory(): self { return $this; }
	public function newFromID( int $id, int $flags ): WikiPage { return $this->page ?? throw new \LogicException( 'No fixture page.' ); }
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function newSelectQueryBuilder(): self { return $this; }
	public function select( ...$args ): self { return $this; }
	public function from( ...$args ): self { return $this; }
	public function join( ...$args ): self { return $this; }
	public function leftJoin( ...$args ): self { return $this; }
	public function where( ...$args ): self { return $this; }
	public function caller( ...$args ): self { return $this; }
	public function fetchResultSet(): array { return []; }
	public function fetchField(): bool { return false; }
}

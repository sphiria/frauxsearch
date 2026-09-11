<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBuilder;
use FrauxSearch\DocumentHash;
use FrauxSearch\SearchTextExtractor;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class SearchTextExtractorTest extends TestCase {
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/searchText.php';
		MediaWikiServices::$instance = new SearchTextServices();
	}

	public function testSameRevisionRefreshRechecksSharedCacheWithoutDiscardingValidOutput(): void {
		$content = new WikitextContent( new ParserOutput( 'Should not need to render.' ) );
		$revision = new RevisionRecord( $content );
		$page = new WikiPage( $revision );
		$access = MediaWikiServices::getInstance()->getParserOutputAccess();
		$access->local = new ParserOutput( 'Previous template text.' );
		$access->shared = new ParserOutput( 'Updated template text.' );
		$this->assertSame( 'Updated template text.', SearchTextExtractor::extract( $content, $page, $revision ) );
		$this->assertSame( 1, $access->clears );
		$this->assertSame( 1, $access->sharedHits );
		$this->assertSame( 0, $access->renders );
		$this->assertSame( 'Updated template text.', SearchTextExtractor::extract( $content, $page, $revision ) );
		$this->assertSame( 2, $access->sharedHits );
		$this->assertSame( 0, $access->renders );
		$this->assertSame( [ [ $page, null, $revision ], [ $page, null, $revision ] ], $content->calls );
	}

	public function testUsesExactRevisionAndKeepsHeadingsAndAuxiliaryText(): void {
		$content = new WikitextContent( new ParserOutput(
			"Opening\nparagraph & 猫.", [ 'Abilities', 'Damage' ], [ "Attack\t100%", 'Portrait caption' ]
		) );
		$revision = new RevisionRecord( $content );
		$page = new WikiPage( $revision );
		$this->assertSame( 'Opening paragraph & 猫. Abilities Damage Attack 100% Portrait caption',
			SearchTextExtractor::extract( $content, $page, $revision ) );
		$this->assertSame( [ [ $page, null, $revision ] ], $content->calls );
	}

	public function testMissingParserOutputFailsInsteadOfIndexingRawWikitext(): void {
		$content = new WikitextContent( null );
		$revision = new RevisionRecord( $content );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to render' );
		SearchTextExtractor::extract( $content, new WikiPage( $revision ), $revision );
	}

	public function testMetadataOnlyOutputFailsButEmptyPageIsValid(): void {
		$this->assertSame( '', SearchTextExtractor::fromParserOutput( new ParserOutput() ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'has no text' );
		SearchTextExtractor::fromParserOutput( new ParserOutput( textAvailable: false ) );
	}

	public function testParserFailureIsPreserved(): void {
		$content = new WikitextContent( null );
		$content->failure = new \RuntimeException( 'Template read failed.' );
		$revision = new RevisionRecord( $content );
		try {
			SearchTextExtractor::extract( $content, new WikiPage( $revision ), $revision );
			$this->fail( 'Parser failure was hidden.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( $content->failure, $error );
		}
	}

	public function testInvalidUtf8CannotSilentlyEraseText(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'invalid UTF-8' );
		SearchTextExtractor::fromParserOutput( new ParserOutput( "Invalid\xFFtext" ) );
	}

	public function testDerivedTextChangesMetadataAndHashWithoutRevisionChange(): void {
		$content = new WikitextContent( new ParserOutput( 'First rendered paragraph.' ) );
		$builder = $this->builder( $content );
		$before = $builder->build( 7 )['document'];
		$this->assertSame( 'First rendered paragraph.', $before['text'] );
		$this->assertSame( 3, $before['word_count'] );
		$this->assertSame( strlen( $before['text'] ), $before['byte_size'] );
		$this->assertSame( DocumentHash::compute( $before ), $before['document_hash'] );
		$this->assertSame( $before, $builder->build( 7 )['document'] );
		$content->output = new ParserOutput( 'Changed template paragraph with details.' );
		$after = $builder->build( 7 )['document'];
		$this->assertSame( $before['revision_id'], $after['revision_id'] );
		$this->assertNotSame( $before['document_hash'], $after['document_hash'] );
		$this->assertSame( 5, $after['word_count'] );
		$this->assertSame( strlen( $after['text'] ), $after['byte_size'] );
		$this->assertSame( DocumentHash::compute( $after ), $after['document_hash'] );
	}

	public function testNonWikitextUsesContentModelsSearchRepresentation(): void {
		$content = new class {
			public function getTextForSearchIndex(): string { return '{"example":"<tag> & 猫"}'; }
		};
		$document = $this->builder( $content )->build( 7 )['document'];
		$this->assertSame( $content->getTextForSearchIndex(), $document['text'] );
		$this->assertSame( strlen( $document['text'] ), $document['byte_size'] );
		$this->assertSame( DocumentHash::compute( $document ), $document['document_hash'] );
	}

	private function builder( object $content ): DocumentBuilder {
		MediaWikiServices::$instance = new SearchTextServices( new WikiPage( new RevisionRecord( $content ) ) );
		return new class( true, [ 7 => 0 ], [ 7 => [] ] ) extends DocumentBuilder {
			public function loadBoostConfig(): array { return []; }
		};
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBuilder;
use FrauxSearch\MeilisearchClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class AuthoritativeWriteTest extends TestCase {
	public function testNoPartialDocumentUpdateMethodExists(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/MeilisearchClient.php' );
		$this->assertStringNotContainsString( 'function updateDocuments', $source );
	}

	public function testDeleteHookQueuesAuthoritativeDeletedPageRefresh(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Hooks.php' );
		$this->assertStringContainsString( '$this->enqueueRefresh( $pageID, $title );', $source );
		$this->assertStringContainsString( "\$params['redirectTitle'] = \$redirectTitle;", $source );
		$this->assertStringNotContainsString( 'removeRedirectAlias', $source );
		$this->assertStringNotContainsString( 'deletedRedirectTargets', $source );
	}

	public function testRedirectTargetsRefreshAfterLinkUpdatesComplete(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Hooks.php' );
		$handler = strstr( $source, 'public function onLinksUpdateComplete' );
		$this->assertStringContainsString( '$refreshIds[] = $oldTargetId;', $handler );
		$this->assertStringContainsString( '$refreshIds[] = $pageId;', $handler );
		$this->assertStringContainsString( '$refreshIds[] = $targetId;', $handler );
		$this->assertStringContainsString( '$this->enqueuePageRefreshes( $refreshIds );', $handler );
	}

	public function testResultSetPreservesApproximateTotalFlag(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/FrauxSearchResultSet.php' );
		$this->assertStringContainsString( 'return $this->approximateTotalHits;', $source );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAuthoritativeDocumentsIncludeReconciliationMetadata(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/redirects.php';
		$services = new class {
			public array $aliases = [];
			private array $targets = [];
			public function getWikiPageFactory(): self { return $this; }
			public function getTitleFactory(): self { return $this; }
			public function getConnectionProvider(): self { return $this; }
			public function getPrimaryDatabase(): self { return $this; }
			public function newFromID( int $id, int $flags ): RedirectTestPage {
				return new RedirectTestPage( 0, 'Canonical_/_é', $id );
			}
			public function makeTitle( int $namespace, string $title ): RedirectTestPage {
				return new RedirectTestPage( $namespace, $title );
			}
			public function newSelectQueryBuilder(): self { return clone $this; }
			public function select( ...$args ): self { return $this; }
			public function from( ...$args ): self { return $this; }
			public function join( ...$args ): self { return $this; }
			public function leftJoin( ...$args ): self { return $this; }
			public function caller( ...$args ): self { return $this; }
			public function where( array $conditions ): self {
				$this->targets = $conditions['target.page_id'] ?? [];
				return $this;
			}
			public function fetchResultSet(): array { return in_array( 1, $this->targets, true ) ? $this->aliases : []; }
			public function fetchField(): bool { return false; }
		};
		\MediaWiki\MediaWikiServices::$instance = $services;
		$builder = new class( true, [ 1 => 7 ], [ 1 => [ 8, 9 ] ] ) extends DocumentBuilder {
			public function loadBoostConfig(): array { return []; }
		};
		$before = $builder->build( 1 )['document'];
		$payload = [ 'id' => 1, 'revision_id' => 1001, 'title' => 'Canonical / é', 'redirects' => [],
			'namespace' => 0, 'incoming_links' => 7, 'outgoing_link_ids' => [ 8, 9 ], 'boost' => 100,
			'text' => 'Canonical content', 'timestamp' => '20260908000000', 'word_count' => 2, 'byte_size' => 17 ];
		$this->assertSame( $payload, array_diff_key( $before, [ 'document_hash' => true ] ) );
		$this->assertSame( hash( 'sha256', json_encode( $payload,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $before['document_hash'] );
		$services->aliases = [ (object)[ 'page_id' => 2, 'page_namespace' => 1, 'page_title' => 'New_alias' ] ];
		$after = $builder->build( 1 )['document'];
		$this->assertSame( $before['revision_id'], $after['revision_id'] );
		$this->assertSame( [ 'Talk:New alias' ], $after['redirects'] );
		$this->assertNotSame( $before['document_hash'], $after['document_hash'] );
		$payload['redirects'] = [ 'Talk:New alias' ];
		$this->assertSame( hash( 'sha256', json_encode( $payload,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $after['document_hash'] );
		$displayed = MeilisearchClient::expectedIndexSettings()['displayedAttributes'];
		$this->assertContains( 'revision_id', $displayed );
		$this->assertContains( 'document_hash', $displayed );
	}

	public function testIncomingLinksAreAuthoritativeAndIncrementallyRefreshed(): void {
		$builder = file_get_contents( dirname( __DIR__, 2 ) . '/src/DocumentBuilder.php' );
		$client = file_get_contents( dirname( __DIR__, 2 ) . '/src/MeilisearchClient.php' );
		$this->assertStringContainsString( "'incoming_links' => \$this->loadIncomingLinks( \$pageId )", $builder );
		$this->assertStringContainsString( "'outgoing_link_ids' => \$this->loadOutgoingLinkIds( \$pageId )", $builder );
		$this->assertStringContainsString( "'timestamp', 'incoming_links'", $client );
		$this->assertStringContainsString( "'incoming_links:desc'", $client );
	}
}

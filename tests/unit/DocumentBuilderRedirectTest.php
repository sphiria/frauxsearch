<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBuilder;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\PageRefresher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class DocumentBuilderRedirectTest extends TestCase {
	private RedirectTestDatabase $database;
	private DocumentBuilder $builder;

	protected function setUp(): void {
		if ( !extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'SQL join behavior tests require pdo_sqlite.' );
		}
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/redirects.php';
		$this->database = new RedirectTestDatabase();
		\MediaWiki\MediaWikiServices::$instance = new RedirectTestServices( $this->database );
		$this->builder = new class( true, [], [] ) extends DocumentBuilder {
			public function loadBoostConfig(): array { return []; }
		};
	}

	public static function interwikiPrefixes(): array {
		return [ 'ordinary prefix' => [ 'w' ], 'numeric prefix' => [ '0' ], 'nonempty whitespace' => [ ' ' ] ];
	}

	#[DataProvider( 'interwikiPrefixes' )]
	public function testInterwikiHopNeverResolvesToSameNamedLocalPage( string $prefix ): void {
		$this->database->page( 1, 'Target' );
		$this->database->page( 2, 'External_redirect' );
		$this->database->redirect( 2, 'Target', $prefix );
		$this->database->page( 3, 'Upstream' );
		$this->database->redirect( 3, 'External_redirect' );
		$this->assertNull( $this->builder->getRedirectTargetId( 2 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 3 ) );
		$this->assertSame( [], $this->builder->build( 1 )['document']['redirects'] );
	}

	public function testLocalNullAndEmptyPrefixesResolveAndPopulateAliasesAcrossNamespaces(): void {
		$this->database->page( 1, 'Target' );
		$this->database->page( 2, 'Alias_two', 1 );
		$this->database->redirect( 2, 'Target', null );
		$this->database->page( 3, 'Alias_one' );
		$this->database->redirect( 3, 'Alias_two', '', 1 );
		$this->database->page( 4, 'Wrong_namespace' );
		$this->database->redirect( 4, 'Target', '', 1 );
		$this->assertSame( 1, $this->builder->getRedirectTargetId( 2 ) );
		$this->assertSame( 1, $this->builder->getRedirectTargetId( 3 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 4 ) );
		$this->assertSame( [ 'Alias one', 'Talk:Alias two' ], $this->builder->build( 1 )['document']['redirects'] );
	}

	public function testBothDirectionsKeepExactlyTenHopLimit(): void {
		$this->database->page( 1, 'Canonical' );
		for ( $id = 2; $id <= 12; $id++ ) {
			$this->database->page( $id, 'Alias_' . ( $id - 1 ) );
			$this->database->redirect( $id, $id === 2 ? 'Canonical' : 'Alias_' . ( $id - 2 ), $id % 2 ? null : '' );
		}
		$this->assertSame( 1, $this->builder->getRedirectTargetId( 11 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 12 ) );
		$this->assertSame( array_map( static fn ( int $n ) => 'Alias ' . $n, range( 1, 10 ) ),
			$this->builder->build( 1 )['document']['redirects'] );
	}

	public function testBrokenRedirectsAndLoopsCannotProduceCanonicalTarget(): void {
		$this->database->page( 1, 'Broken' );
		$this->database->redirect( 1, 'Missing' );
		$this->database->page( 2, 'Before_broken' );
		$this->database->redirect( 2, 'Broken' );
		$this->database->page( 3, 'Loop_a' );
		$this->database->redirect( 3, 'Loop_b', null );
		$this->database->page( 4, 'Loop_b' );
		$this->database->redirect( 4, 'Loop_a' );
		$this->assertNull( $this->builder->getRedirectTargetId( 1 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 2 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 3 ) );
		$this->assertNull( $this->builder->getRedirectTargetId( 4 ) );
		$this->assertSame( [ 'Loop b' ], $this->builder->build( 3 )['document']['redirects'] );
	}

	public function testInterwikiRedirectStillWritesFullTextAndRemovesCompletionDocument(): void {
		$this->database->page( 1, 'Target' );
		$this->database->page( 2, 'External_redirect' );
		$this->database->redirect( 2, 'Target', 'w' );
		$full = new RedirectRecordingClient();
		$completion = new RedirectRecordingClient();
		$completion->stored[2] = [ 'id' => 2, 'title' => 'External redirect' ];
		$queued = [];
		( new PageRefresher( $full, $completion, $this->builder->build( ... ),
			static function ( array $ids ) use ( &$queued ): void { $queued = array_merge( $queued, $ids ); }
		) )->refresh( 2 );
		$this->assertCount( 1, $full->documents );
		$this->assertSame( 2, $full->documents[0]['id'] );
		$this->assertSame( '#REDIRECT [[w:Target]]', $full->documents[0]['text'] );
		$this->assertSame( [], $full->deletions );
		$this->assertSame( [], $completion->documents );
		$this->assertSame( [ 2 ], $completion->deletions );
		$this->assertSame( [], $completion->stored );
		$this->assertSame( [], $queued );
	}
}

class RedirectRecordingClient extends MeilisearchClient {
	public array $stored = [];
	public array $documents = [];
	public array $deletions = [];
	public function __construct() {}
	public function getDocument( int $id ): ?array { return $this->stored[$id] ?? null; }
	public function findDocumentsWithRedirect( string $title ): array { return []; }
	public function replaceDocuments( array $documents ): ?int { $this->documents = $documents; return 1; }
	public function deleteDocument( int $id ): ?int {
		$this->deletions[] = $id;
		unset( $this->stored[$id] );
		return 1;
	}
	public function waitForTask( ?int $taskUid, int $timeout = 300 ): void {}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchClient;
use FrauxSearch\PageRefresher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PageRefresherTest extends TestCase {
	private RefreshTestClient $full;
	private RefreshTestClient $completion;
	private PageRefresher $refresher;
	private array $source = [];
	private array $queued = [];
	private array $builds = [];
	private bool $queueFails = false;

	protected function setUp(): void {
		$this->full = new RefreshTestClient();
		$this->completion = new RefreshTestClient();
		$this->refresher = new PageRefresher( $this->full, $this->completion,
			function ( int $id ): ?array {
				$this->builds[] = $id;
				return $this->source[$id] ?? null;
			},
			function ( array $ids ): void {
				if ( $this->queueFails ) { throw new RuntimeException( 'queue unavailable' ); }
				$this->queued = array_merge( $this->queued, $ids );
			}
		);
	}

	private function page( int $id, array $targets = [], bool $redirect = false, ?int $target = null ): array {
		return [ 'document' => [ 'id' => $id, 'title' => "Page $id", 'outgoing_link_ids' => $targets ],
			'is_redirect' => $redirect, 'redirect_target_id' => $target ];
	}

	private function seed( array $pages ): void {
		foreach ( $pages as $page ) {
			$id = $page['document']['id'];
			$this->source[$id] = $page;
			$this->full->documents[$id] = $page['document'];
			if ( !$page['is_redirect'] ) { $this->completion->documents[$id] = $page['document']; }
		}
	}

	private function drain(): void {
		$count = 0;
		while ( $this->queued !== [] ) {
			if ( ++$count > 30 ) { $this->fail( 'Dependency refreshes did not converge' ); }
			$this->refresher->refresh( array_shift( $this->queued ) );
		}
	}

	public function testIndirectRefreshPreservesAnotherPagesRemovedLink(): void {
		$this->seed( [ $this->page( 1 ), $this->page( 2, [ 3 ] ), $this->page( 3 ) ] );
		$this->source[1] = $this->page( 1, [ 2 ] );
		$this->source[2] = $this->page( 2 );
		$this->source[3]['document']['incoming_links'] = 0;
		$this->refresher->refresh( 1 );
		$this->drain();
		$this->assertSame( [ 1, 2, 3 ], $this->builds );
		$this->assertSame( [], $this->full->documents[2]['outgoing_link_ids'] );
		$this->assertSame( 0, $this->full->documents[3]['incoming_links'] );
	}

	public function testQueueFailureLeavesOldHistoryInBothIndexes(): void {
		$this->seed( [ $this->page( 1, [ 2 ] ) ] );
		$this->source[1] = $this->page( 1 );
		$this->queueFails = true;
		try {
			$this->refresher->refresh( 1 );
			$this->fail( 'Expected queue failure' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'queue unavailable', $e->getMessage() );
		}
		$this->assertSame( [ 2 ], $this->full->documents[1]['outgoing_link_ids'] );
		$this->assertSame( $this->full->documents, $this->completion->documents );
	}

	public function testCompletionFailureRetainsDependencyWorkAndCanBeRetried(): void {
		$this->seed( [ $this->page( 1, [ 2 ] ) ] );
		$this->source[1] = $this->page( 1 );
		$this->completion->fail = true;
		try { $this->refresher->refresh( 1 ); } catch ( RuntimeException ) {}
		$this->assertSame( [ 2 ], $this->queued );
		$this->assertSame( [], $this->full->documents[1]['outgoing_link_ids'] );
		$this->completion->fail = false;
		$this->refresher->refresh( 1 );
		$this->assertSame( $this->full->documents, $this->completion->documents );
	}

	public function testDeletedLinkCycleConvergesThroughQueuedWork(): void {
		$this->seed( [ $this->page( 1, [ 2 ] ), $this->page( 2, [ 1 ] ) ] );
		$this->source = [];
		$this->refresher->refresh( 1 );
		$this->drain();
		$this->assertSame( [], $this->full->documents );
		$this->assertSame( [], $this->completion->documents );
	}

	public function testUnchangedLinkCycleDoesNotEnqueueMoreWork(): void {
		$this->seed( [ $this->page( 1, [ 2 ] ), $this->page( 2, [ 1 ] ) ] );
		$this->refresher->refresh( 1 );
		$this->assertSame( [], $this->queued );
		$this->assertSame( [ 1 ], $this->builds );
	}

	public function testMovedRedirectQueuesOldAndCurrentTargetsUsingBothTitles(): void {
		$this->seed( [ $this->page( 1, [], true, 2 ) ] );
		$this->source[1] = $this->page( 1, [], true, 3 );
		$this->source[1]['document']['title'] = 'Moved';
		$this->completion->aliases = [ 'Page 1' => [ 2 ], 'Moved' => [ 3 ] ];
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 2, 3 ], $this->queued );
		$this->assertArrayNotHasKey( 1, $this->completion->documents );
		$this->assertSame( [ 1 ], $this->builds );
	}

	public function testMissingFullDocumentStillUsesCompletionHistory(): void {
		$this->completion->documents[1] = $this->page( 1, [ 2 ] )['document'];
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 2 ], $this->queued );
		$this->assertSame( [], $this->completion->documents );
	}

	public function testRefreshWithDeletedTitleKeepsRestoredPage(): void {
		$this->source[1] = $this->page( 1 );
		$this->refresher->refresh( 1, 'Old deleted title' );
		$this->assertSame( $this->source[1]['document'], $this->full->documents[1] );
		$this->assertSame( $this->full->documents, $this->completion->documents );
	}
}

class RefreshTestClient extends MeilisearchClient {
	public array $documents = [];
	public array $aliases = [];
	public bool $fail = false;
	public function __construct() {}
	public function getDocument( int $id ): ?array { return $this->documents[$id] ?? null; }
	public function findDocumentsWithRedirect( string $title ): array { return $this->aliases[$title] ?? []; }
	public function replaceDocuments( array $documents ): ?int {
		if ( $this->fail ) { throw new RuntimeException( 'write failed' ); }
		foreach ( $documents as $document ) { $this->documents[$document['id']] = $document; }
		return 1;
	}
	public function deleteDocument( int $id ): ?int { unset( $this->documents[$id] ); return 1; }
	public function waitForTask( ?int $taskUid, int $timeout = 300 ): void {}
}

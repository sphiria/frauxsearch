<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentHash;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\PageRefresher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class UnchangedRefreshTest extends TestCase {
	private UnchangedRefreshClient $full;
	private UnchangedRefreshClient $completion;
	private PageRefresher $refresher;
	private ?array $built;
	private array $queued = [];
	private int $builds = 0;
	private bool $queueFails = false;

	protected function setUp(): void {
		$document = [ 'id' => 1, 'revision_id' => 10, 'title' => 'Page 1', 'text' => 'Current text',
			'boost' => 100, 'incoming_links' => 3, 'outgoing_link_ids' => [ 2 ], 'redirects' => [] ];
		$document['document_hash'] = DocumentHash::compute( $document );
		$this->built = [ 'document' => $document, 'is_redirect' => false, 'redirect_target_id' => null ];
		$this->full = new UnchangedRefreshClient();
		$this->completion = new UnchangedRefreshClient();
		$this->full->document = $document;
		$this->completion->document = $document;
		$this->refresher = new PageRefresher( $this->full, $this->completion,
			function ( int $id ): ?array {
				$this->assertSame( 1, $id );
				$this->builds++;
				return $this->built;
			},
			function ( array $ids ): void {
				if ( $this->queueFails ) { throw new RuntimeException( 'queue unavailable' ); }
				$this->queued = array_merge( $this->queued, $ids );
			}
		);
	}

	public function testCompleteUnchangedDocumentsSkipWritesButStillReadBuildAndScheduleAliases(): void {
		$this->completion->document = array_reverse( $this->completion->document, true );
		$this->completion->aliases = [ 'Old title' => [ 3 ], 'Earlier title' => [ 4 ], 'Page 1' => [ 5 ] ];
		$this->refresher->refresh( 1, 'Old title', [ 'Earlier title' ] );
		$this->assertSame( [ 3, 4, 5 ], $this->queued );
		$this->assertSame( 1, $this->builds );
		$this->assertSame( [ 1 ], $this->full->reads );
		$this->assertSame( [ 1 ], $this->completion->reads );
		$this->assertSame( [ 'Earlier title', 'Old title', 'Page 1' ], $this->completion->aliasReads );
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( [], $this->completion->mutations );
		$this->assertSame( [], $this->full->waits );
		$this->assertSame( [], $this->completion->waits );
	}

	public static function corruptCopies(): array {
		$cases = [];
		foreach ( [ 'full', 'completion' ] as $copy ) {
			foreach ( [ 'text' => 'Corrupt text', 'incoming_links' => 999, 'outgoing_link_ids' => [ 7 ],
				'boost' => '100', 'extra' => 'unexpected', 'redirects' => null ] as $field => $value
			) {
				$cases["$copy $field"] = [ $copy, $field, $value ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'corruptCopies' )]
	public function testMatchingRevisionAndDeclaredHashCannotHideCorruption( string $copy, string $field, mixed $value
	): void {
		$client = $this->$copy;
		$other = $copy === 'full' ? $this->completion : $this->full;
		if ( $value === null ) {
			unset( $client->document[$field] );
		} else {
			$client->document[$field] = $value;
		}
		$this->assertSame( $this->built['document']['document_hash'], $client->document['document_hash'] );
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 'replace' ], $client->mutations );
		$this->assertSame( [ 1 ], $client->waits );
		$this->assertSame( [], $other->mutations );
		$this->assertSame( $this->built['document'], $client->document );
		$this->assertSame( $this->built['document'], $other->document );
		$this->assertSame( $field === 'outgoing_link_ids' ? [ 2, 7 ] : [], $this->queued );
	}

	public static function copies(): array {
		return [ 'full' => [ 'full' ], 'completion' => [ 'completion' ] ];
	}

	#[DataProvider( 'copies' )]
	public function testMissingCopyIsRepairedIndependently( string $copy ): void {
		$client = $this->$copy;
		$other = $copy === 'full' ? $this->completion : $this->full;
		$client->document = null;
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 'replace' ], $client->mutations );
		$this->assertSame( [], $other->mutations );
		$this->assertSame( $other->document, $client->document );
		$this->assertSame( [ 2 ], $this->queued );
	}

	public function testDerivedChangesWithUnchangedRevisionReplaceBothCompleteDocuments(): void {
		$this->built['document']['incoming_links'] = 8;
		$this->built['document']['outgoing_link_ids'] = [ 3 ];
		$this->built['document']['boost'] = 150;
		$this->built['document']['redirects'] = [ 'Alias' ];
		$this->built['document']['document_hash'] = DocumentHash::compute( $this->built['document'] );
		$this->assertSame( $this->full->document['revision_id'], $this->built['document']['revision_id'] );
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 2, 3 ], $this->queued );
		$this->assertSame( [ 'replace' ], $this->full->mutations );
		$this->assertSame( [ 'replace' ], $this->completion->mutations );
		$this->assertSame( $this->built['document'], $this->full->document );
		$this->assertSame( $this->built['document'], $this->completion->document );
	}

	public static function presentCopies(): array {
		return [ 'neither' => [ false, false ], 'full only' => [ true, false ],
			'completion only' => [ false, true ], 'both' => [ true, true ] ];
	}

	#[DataProvider( 'presentCopies' )]
	public function testDeletedSourceOnlyDeletesPresentCopies( bool $fullPresent, bool $completionPresent ): void {
		$this->built = null;
		if ( !$fullPresent ) { $this->full->document = null; }
		if ( !$completionPresent ) { $this->completion->document = null; }
		$this->refresher->refresh( 1 );
		$this->assertSame( $fullPresent ? [ 'delete' ] : [], $this->full->mutations );
		$this->assertSame( $completionPresent ? [ 'delete' ] : [], $this->completion->mutations );
		$this->assertNull( $this->full->document );
		$this->assertNull( $this->completion->document );
		$this->assertSame( $fullPresent || $completionPresent ? [ 2 ] : [], $this->queued );
	}

	public function testSettledRedirectDoesNotRescheduleDependencies(): void {
		$this->built['is_redirect'] = true;
		$this->built['redirect_target_id'] = 3;
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 3 ], $this->queued );
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( [ 'delete' ], $this->completion->mutations );
		$this->completion->aliases = [ 'Page 1' => [ 3 ] ];
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 3 ], $this->queued );
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( [ 'delete' ], $this->completion->mutations );
	}

	public function testCompletionOnlyLeavesStaleFullDocumentUntouched(): void {
		$this->full->document['text'] = 'Stale full text';
		$this->refresher->refresh( 1, null, [], true );
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( [], $this->completion->mutations );
		$this->completion->document = null;
		$this->refresher->refresh( 1, null, [], true );
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( 'Stale full text', $this->full->document['text'] );
		$this->assertSame( [ 'replace' ], $this->completion->mutations );
		$this->assertSame( $this->built['document'], $this->completion->document );
	}

	public function testUnchangedDocumentsDoNotHideDependencyQueueFailure(): void {
		$this->completion->aliases = [ 'Page 1' => [ 3 ] ];
		$this->full->document['text'] = 'stale';
		$this->queueFails = true;
		try {
			$this->refresher->refresh( 1 );
			$this->fail( 'Expected dependency queue failure' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'queue unavailable', $e->getMessage() );
		}
		$this->assertSame( [], $this->full->mutations );
		$this->assertSame( [], $this->completion->mutations );
	}

	public function testRetryRepairsFailedCompletionWithoutRewritingSuccessfulFullCopy(): void {
		$this->built['document']['outgoing_link_ids'] = [ 3 ];
		$this->built['document']['document_hash'] = DocumentHash::compute( $this->built['document'] );
		$this->completion->fail = true;
		try {
			$this->refresher->refresh( 1 );
			$this->fail( 'Expected completion write failure' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'write failed', $e->getMessage() );
		}
		$this->assertSame( $this->built['document'], $this->full->document );
		$this->assertSame( [ 2 ], $this->completion->document['outgoing_link_ids'] );
		$this->completion->fail = false;
		$this->refresher->refresh( 1 );
		$this->assertSame( [ 2, 3, 2, 3 ], $this->queued );
		$this->assertSame( [ 'replace' ], $this->full->mutations );
		$this->assertSame( [ 'replace', 'replace' ], $this->completion->mutations );
		$this->assertSame( $this->full->document, $this->completion->document );
	}

	public function testUnchangedRefreshAcknowledgesJournalAndRetiresGuardWithoutDocumentTasks(): void {
		$store = new MemoryCoordinationStore();
		$store->state = null;
		$server = new CoordinationServer();
		unset( $server->indexes['wiki_coordination'], $server->primaryKeys['wiki_coordination'] );
		$server->indexes['wiki'][1] = $this->built['document'];
		$server->indexes['wiki_completion'][1] = $this->built['document'];
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ): ?array => $this->built,
			function ( array $ids ): void { $this->queued = array_merge( $this->queued, $ids ); }
		);
		$worker->refresh( 1 );
		$this->assertSame( [ 'indexCreation', 'indexDeletion' ], array_column( $server->submissions, 'type' ) );
		$this->assertSame( [ 'wiki_coordination', 'wiki_coordination' ],
			array_column( $server->submissions, 'indexUid' ) );
		$this->assertNull( $store->state );
		$this->assertSame( [], $store->changes );
		$this->assertFalse( $store->held );
		$this->assertSame( [], $this->queued );
		$this->assertArrayNotHasKey( 'wiki_coordination', $server->indexes );
	}

	public function testUnchangedPayloadCannotAcknowledgeJournalAfterLeaseLoss(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$server->indexes['wiki'][1] = $this->built['document'];
		$server->indexes['wiki_completion'][1] = $this->built['document'];
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			function ( int $id ) use ( $store ): ?array {
				$store->held = false;
				return $this->built;
			},
			function ( array $ids ): void { $this->queued = array_merge( $this->queued, $ids ); }
		);
		try {
			$worker->refresh( 1 );
			$this->fail( 'Expected lost ownership' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'lost lock', $e->getMessage() );
		}
		$this->assertSame( [], $server->submissions );
		$this->assertCount( 1, $store->changes );
		$this->assertFalse( $store->changes[1]['done'] );
		$this->assertFalse( $store->state['closing'] );
		$this->assertNull( $store->state['pending'] );
		$this->assertArrayHasKey( 'wiki_coordination', $server->indexes );
	}

	public function testAlreadyAppliedPayloadCannotBypassUnknownTaskIntent(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$server->indexes['wiki'][1] = $this->built['document'];
		$server->indexes['wiki'][1]['text'] = 'Corrupted text';
		$server->indexes['wiki_completion'][1] = $this->built['document'];
		$client = new CoordinationClient( $server );
		$server->afterAccept = static function ( array $task ) use ( $client ): void {
			$client->getTask( $task['uid'] );
			throw new RuntimeException( 'lost response' );
		};
		$worker = new IndexCoordinator( $store, $client, 'wiki',
			function ( int $id ): ?array {
				$this->builds++;
				return $this->built;
			},
			function ( array $ids ): void { $this->queued = array_merge( $this->queued, $ids ); }
		);
		try {
			$worker->refresh( 1 );
			$this->fail( 'Expected unknown task outcome' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'lost response', $e->getMessage() );
		}
		$this->assertSame( $this->built['document'], $server->indexes['wiki'][1] );
		$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
		$pending = $store->state['pending'];
		$server->afterAccept = null;
		try {
			$worker->refresh( 1 );
			$this->fail( 'Expected pending intent to prevent a refresh' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'unknown outcome', $e->getMessage() );
		}
		$this->assertSame( 1, $this->builds );
		$this->assertCount( 1, $server->submissions );
		$this->assertSame( $pending, $store->state['pending'] );
		$this->assertSame( [ false, false ], array_column( $store->changes, 'done' ) );
		$worker->adoptTask( $pending['id'], 1 );
		$worker->drain();
		$this->assertSame( [ 'documentAdditionOrUpdate', 'indexDeletion' ],
			array_column( $server->submissions, 'type' ) );
		$this->assertSame( 2, $this->builds );
		$this->assertNull( $store->state );
		$this->assertSame( [], $store->changes );
	}
}

class UnchangedRefreshClient extends MeilisearchClient {
	public ?array $document = null;
	public array $aliases = [];
	public array $reads = [];
	public array $aliasReads = [];
	public array $mutations = [];
	public array $waits = [];
	public bool $fail = false;

	public function __construct() {
	}

	public function getDocument( int $id ): ?array {
		$this->reads[] = $id;
		return $this->document;
	}

	public function findDocumentsWithRedirect( string $title ): array {
		$this->aliasReads[] = $title;
		return $this->aliases[$title] ?? [];
	}

	public function replaceDocuments( array $documents ): ?int {
		$this->mutations[] = 'replace';
		if ( $this->fail ) { throw new RuntimeException( 'write failed' ); }
		$this->document = $documents[0];
		return count( $this->mutations );
	}

	public function deleteDocument( int $id ): ?int {
		$this->mutations[] = 'delete';
		$this->document = null;
		return count( $this->mutations );
	}

	public function waitForTask( ?int $taskUid, int $timeout = 300 ): void {
		$this->waits[] = $taskUid;
	}
}

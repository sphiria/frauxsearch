<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentHash;
use FrauxSearch\IndexCoordinator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/fixtures/coordination.php';

class RefreshBatchTest extends TestCase {
	private function built( int $id, string $text = 'Current' ): array {
		$doc = [ 'id' => $id, 'revision_id' => 1, 'title' => "Page $id", 'text' => $text,
			'outgoing_link_ids' => [], 'redirects' => [] ];
		$doc['document_hash'] = DocumentHash::compute( $doc );
		return [ 'document' => $doc, 'is_redirect' => false, 'redirect_target_id' => null ];
	}

	public function testBusyWriterNeverInvokesQueueClaim(): void {
		$store = new MemoryCoordinationStore();
		$store->held = true;
		$worker = new IndexCoordinator( $store, new CoordinationClient( new CoordinationServer() ), 'wiki',
			static fn () => null, static function () {}, 0 );
		$this->expectExceptionMessage( 'writer is busy' );
		$worker->refreshBatch( function () { $this->fail( 'Claim invoked without ownership.' ); }, static fn () => [] );
	}

	public function testEmptyQueueReleasesLockWithoutCreatingScope(): void {
		$store = new MemoryCoordinationStore();
		$store->state = null;
		$server = new CoordinationServer();
		$server->indexes = [];
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			static fn () => null, static function () {} );
		$this->assertSame( 0, $worker->refreshBatch( function ( $heartbeat ) use ( $store ): array {
			$this->assertTrue( $store->held );
			$heartbeat();
			return [];
		}, function () { $this->fail( 'Empty queue attempted rendering.' ); } ) );
		$this->assertFalse( $store->held );
		$this->assertNull( $store->state );
		$this->assertSame( [], $server->submissions );
	}

	public function testDuplicateRequestsBuildOnceAndLaterRequestsRemainPending(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ) => $this->built( $id ), static function () {} );
		$result = $worker->refreshBatch( [ [ 'pageId' => 1 ], [ 'pageId' => 1 ], [ 'pageId' => 2 ] ],
			function ( array $ids, callable $heartbeat ) use ( $store ): array {
				$this->assertSame( [ 1, 2 ], $ids );
				$heartbeat();
				$store->append( 1, 'Later title', false );
				return [ 1 => $this->built( 1 ), 2 => $this->built( 2 ) ];
			} );
		$this->assertSame( 2, $result );
		$this->assertCount( 2, $server->submissions );
		$this->assertCount( 2, $server->submissions[0]['body'] );
		$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
		$this->assertCount( 1, $store->changes );
		$this->assertFalse( array_values( $store->changes )[0]['done'] );
	}

	public function testFailedSecondWriteRetainsJournalAndRetrySettlesBatch(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$server->blockedTasks[2] = true;
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ) => $this->built( $id ), static function () {} );
		$build = fn () => [ 1 => $this->built( 1 ), 2 => $this->built( 2 ) ];
		try {
			$worker->refreshBatch( [ [ 'pageId' => 1 ], [ 'pageId' => 2 ] ], $build );
			$this->fail( 'Expected task timeout' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'timed out', $e->getMessage() );
		}
		$this->assertSame( [ false, false ], array_column( $store->changes, 'done' ) );
		$this->assertSame( 2, $store->state['pending']['taskUid'] );
		$server->blockedTasks = [];
		$worker->refreshBatch( [ [ 'pageId' => 1 ], [ 'pageId' => 2 ] ], $build );
		$this->assertSame( $server->indexes['wiki'], $server->indexes['wiki_completion'] );
		$this->assertSame( [], $store->changes );
		$this->assertNull( $store->state );
		$this->assertCount( 3, $server->submissions );
	}

	public function testMixedBatchReplacesCompleteDocumentsAndRemovesDeletedPages(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		foreach ( [ 'wiki', 'wiki_completion' ] as $index ) {
			$server->indexes[$index] = [ 1 => $this->built( 1, 'Old' )['document'], 2 => $this->built( 2 )['document'] ];
			$server->indexes[$index][1]['obsolete'] = 'Remove me';
		}
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ) => $this->built( $id ), static function () {} );
		$worker->refreshBatch( [ [ 'pageId' => 1 ], [ 'pageId' => 2 ] ], fn () => [ 1 => $this->built( 1 ), 2 => null ] );
		foreach ( [ 'wiki', 'wiki_completion' ] as $index ) {
			$this->assertSame( [ 1 => $this->built( 1 )['document'] ], $server->indexes[$index] );
		}
		$this->assertSame( [], $store->changes );
	}

	public function testDependencyDeliveryFailurePreventsAllBatchWrites(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$built = $this->built( 1 );
		$built['document']['outgoing_link_ids'] = [ 3 ];
		$built['document']['document_hash'] = DocumentHash::compute( $built['document'] );
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			static fn () => $built, function ( array $ids ): void {
				$this->assertSame( [ 3 ], $ids );
				throw new RuntimeException( 'Queue unavailable' );
			} );
		try {
			$worker->refreshBatch( [ [ 'pageId' => 1 ] ], static fn () => [ 1 => $built ] );
			$this->fail( 'Expected queue failure' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Queue unavailable', $e->getMessage() );
		}
		$this->assertSame( [], $server->submissions );
		$this->assertSame( [ false ], array_column( $store->changes, 'done' ) );
	}

	public function testLostOwnershipDuringRenderingPreventsBatchWrites(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ) => $this->built( $id ), static function () {} );
		try {
			$worker->refreshBatch( [ [ 'pageId' => 1 ] ], function () use ( $store ): array {
				$store->held = false;
				return [ 1 => $this->built( 1 ) ];
			} );
			$this->fail( 'Expected ownership failure' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'lost lock', $e->getMessage() );
		}
		$this->assertSame( [], $server->submissions );
		$this->assertSame( [ false ], array_column( $store->changes, 'done' ) );
	}

	public function testMissingRendererResultCannotWriteOrAcknowledge(): void {
		$store = new MemoryCoordinationStore();
		$server = new CoordinationServer();
		$worker = new IndexCoordinator( $store, new CoordinationClient( $server ), 'wiki',
			fn ( int $id ) => $this->built( $id ), static function () {} );
		try {
			$worker->refreshBatch( [ [ 'pageId' => 1 ] ], static fn () => [] );
			$this->fail( 'Expected incomplete batch rejection' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Incomplete', $e->getMessage() );
		}
		$this->assertSame( [], $server->submissions );
		$this->assertSame( [ false ], array_column( $store->changes, 'done' ) );
	}
}

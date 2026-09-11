<?php

namespace FrauxSearch\Tests;

use FrauxSearch\FrauxSearchEngine;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\RefreshPageJob;
use FrauxSearch\TargetIdentityHooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class IncomingIdentityPropagationTest extends TestCase {
	private IdentityGraphServices $services;
	private CoordinationServer $server;
	private IndexCoordinator $coordinator;
	private array $pages = [];
	private array $builtIds = [];

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		require_once dirname( __DIR__ ) . '/fixtures/coordination.php';
		require_once dirname( __DIR__ ) . '/fixtures/incoming.php';
		$this->services = new IdentityGraphServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
		$this->server = new CoordinationServer();
		$this->coordinator = new IndexCoordinator( new MemoryCoordinationStore(),
			new CoordinationClient( $this->server ), 'wiki', fn ( int $id ) => $this->build( $id ),
			function ( array $ids ): void {
				$this->services->push( array_map( static fn ( int $id ) => new RefreshPageJob( [ 'pageId' => $id ] ), $ids ) );
			}
		);
		$this->services->engine = new class( $this->coordinator ) extends FrauxSearchEngine {
			public function __construct( private IndexCoordinator $coordinator ) {}
			protected function newCoordinator(): IndexCoordinator { return $this->coordinator; }
		};
	}

	private function title( string $key, int $namespace = 0 ) {
		return new class( $key, $namespace ) {
			public function __construct( private string $key, private int $namespace ) {}
			public function getDBkey(): string { return $this->key; }
			public function getNamespace(): int { return $this->namespace; }
			public function getTitle(): self { return $this; }
		};
	}

	private function createPage( int $id, string $title, int $namespace = 0 ): void {
		$this->pages[$id] = [ 'namespace' => $namespace, 'title' => $title, 'revision' => 100 + $id ];
	}

	private function link( int $source, string $title, int $namespace = 0 ): void {
		$id = count( $this->services->db->linktargets ) + 1;
		$this->services->db->linktargets[] = [ 'lt_id' => $id, 'lt_namespace' => $namespace, 'lt_title' => $title ];
		$this->services->db->pagelinks[] = [ 'pl_from' => $source, 'pl_target_id' => $id ];
	}

	private function pageAt( int $namespace, string $title ): ?int {
		foreach ( $this->pages as $id => $page ) {
			if ( $page['namespace'] === $namespace && $page['title'] === $title ) { return $id; }
		}
		return null;
	}

	private function build( int $id ): ?array {
		$this->builtIds[] = $id;
		$page = $this->pages[$id] ?? null;
		if ( $page === null ) { return null; }
		$outgoing = [];
		foreach ( $this->services->db->pagelinks as $link ) {
			if ( $link['pl_from'] !== $id ) { continue; }
			foreach ( $this->services->db->linktargets as $target ) {
				if ( $target['lt_id'] !== $link['pl_target_id'] ) { continue; }
				$targetId = $this->pageAt( $target['lt_namespace'], $target['lt_title'] );
				if ( $targetId !== null ) { $outgoing[] = $targetId; }
			}
		}
		$outgoing = array_values( array_unique( $outgoing ) );
		sort( $outgoing, SORT_NUMERIC );
		$redirect = false;
		$redirectTarget = null;
		foreach ( $this->services->db->redirects as $row ) {
			if ( $row['rd_from'] !== $id ) { continue; }
			$redirect = true;
			$redirectTarget = $this->pageAt( $row['rd_namespace'], $row['rd_title'] );
		}
		$document = [ 'id' => $id, 'title' => $page['title'], 'namespace' => $page['namespace'],
			'revision_id' => $page['revision'], 'outgoing_link_ids' => $outgoing, 'redirects' => [] ];
		$document['document_hash'] = hash( 'sha256', json_encode( $document, JSON_THROW_ON_ERROR ) );
		return [ 'document' => $document, 'is_redirect' => $redirect, 'redirect_target_id' => $redirectTarget ];
	}

	private function seed(): void {
		foreach ( array_keys( $this->pages ) as $id ) {
			$built = $this->build( $id );
			$this->server->indexes['wiki'][$id] = $built['document'];
			if ( !$built['is_redirect'] ) { $this->server->indexes['wiki_completion'][$id] = $built['document']; }
		}
		$this->builtIds = [];
	}

	private function drain(): void {
		$this->services->commit();
		$count = 0;
		while ( $this->services->jobs !== [] ) {
			if ( ++$count > 50 ) { $this->fail( 'Identity/dependency jobs did not converge' ); }
			$this->assertTrue( array_shift( $this->services->jobs )->run() );
		}
		$this->assertSame( [], $this->services->callbacks );
	}

	public static function creationModes(): array { return [ 'save' => [ false ], 'XML import' => [ true ] ]; }

	#[DataProvider( 'creationModes' )]
	public function testCreationRefreshesUneditedRedlinkAndBrokenRedirectSources( bool $import ): void {
		$this->createPage( 1, 'Source' );
		$this->createPage( 2, 'Broken_redirect' );
		$this->link( 1, 'Target' );
		$this->services->db->redirects[] = [ 'rd_from' => 2, 'rd_namespace' => 0,
			'rd_title' => 'Target', 'rd_interwiki' => '' ];
		$this->seed();
		$before = $this->server->indexes['wiki'][1];
		$this->createPage( 9, 'Target' );
		$hooks = new TargetIdentityHooks();
		if ( $import ) {
			$hooks->onAfterImportPage( $this->title( 'Target' ), $this->title( 'Foreign' ), 5, 1, [ 'id' => 900 ] );
		} else {
			$hooks->onPageSaveComplete( $this->title( 'Target' ), null, '', 0, null,
				new class { public function isNew(): bool { return true; } } );
		}
		$this->coordinator->refresh( 9 );
		$this->assertSame( [], $this->server->indexes['wiki'][1]['outgoing_link_ids'] );
		$this->drain();
		$after = $this->server->indexes['wiki'][1];
		$this->assertSame( [ 9 ], $after['outgoing_link_ids'] );
		$this->assertSame( $before['revision_id'], $after['revision_id'] );
		$this->assertNotSame( $before['document_hash'], $after['document_hash'] );
		$this->assertSame( $after, $this->server->indexes['wiki_completion'][1] );
		$this->assertContains( 2, $this->builtIds );
		$this->assertArrayNotHasKey( 2, $this->server->indexes['wiki_completion'] );
	}

	public function testDeletionAndRestorationWithDifferentIdRepairUneditedSource(): void {
		$this->createPage( 1, 'Source' );
		$this->createPage( 9, 'Target' );
		$this->link( 1, 'Target' );
		$this->seed();
		$revision = $this->server->indexes['wiki'][1]['revision_id'];
		unset( $this->pages[9] );
		$hooks = new TargetIdentityHooks();
		$hooks->onPageDeleteComplete( $this->title( 'Target' ), null, '', 9, null, null, 1 );
		$this->coordinator->refresh( 9, 'Target' );
		$this->drain();
		$this->assertSame( [], $this->server->indexes['wiki'][1]['outgoing_link_ids'] );
		$this->createPage( 22, 'Target' );
		$hooks->onPageUndeleteComplete( $this->title( 'Target' ), null, '', null, null, 1, true, [ 9 ] );
		$this->coordinator->refresh( 22 );
		$this->drain();
		$this->assertSame( [ 22 ], $this->server->indexes['wiki'][1]['outgoing_link_ids'] );
		$this->assertSame( $revision, $this->server->indexes['wiki'][1]['revision_id'] );
		$this->assertArrayNotHasKey( 9, $this->server->indexes['wiki'] );
		$this->assertSame( $this->server->indexes['wiki'], $this->server->indexes['wiki_completion'] );
	}

	public function testRapidMovesRepairIntermediateNameAndRedirectLeftAtOldName(): void {
		foreach ( [ 1 => 'Links_A', 2 => 'Links_B', 3 => 'Links_C', 9 => 'A' ] as $id => $name ) {
			$this->createPage( $id, $name );
		}
		$this->link( 1, 'A' );
		$this->link( 2, 'B', 6 );
		$this->link( 3, 'C' );
		$this->seed();
		$hooks = new TargetIdentityHooks();
		$this->pages[9]['title'] = 'B';
		$this->pages[9]['namespace'] = 6;
		$hooks->onPageMoveComplete( $this->title( 'A' ), $this->title( 'B', 6 ), null, 9, 77, '', null );
		$this->server->indexes['wiki'][2] = $this->build( 2 )['document'];
		$this->server->indexes['wiki_completion'][2] = $this->server->indexes['wiki'][2];
		$this->createPage( 77, 'A' );
		$this->services->db->redirects[] = [ 'rd_from' => 77, 'rd_namespace' => 6,
			'rd_title' => 'B', 'rd_interwiki' => '' ];
		$this->pages[9]['title'] = 'C';
		$this->pages[9]['namespace'] = 0;
		$hooks->onPageMoveComplete( $this->title( 'B', 6 ), $this->title( 'C' ), null, 9, 0, '', null );
		$this->coordinator->refresh( 9 );
		$this->assertSame( [ 9 ], $this->server->indexes['wiki'][2]['outgoing_link_ids'] );
		$this->drain();
		$this->assertSame( [ 77 ], $this->server->indexes['wiki'][1]['outgoing_link_ids'] );
		$this->assertSame( [], $this->server->indexes['wiki'][2]['outgoing_link_ids'] );
		$this->assertSame( [ 9 ], $this->server->indexes['wiki'][3]['outgoing_link_ids'] );
		$this->assertSame( 102, $this->server->indexes['wiki'][2]['revision_id'] );
		$this->assertSame( 'C', $this->server->indexes['wiki'][9]['title'] );
		$this->assertArrayNotHasKey( 77, $this->server->indexes['wiki_completion'] );
		$this->assertContains( 77, $this->builtIds );
	}
}

class IdentityGraphServices {
	public function get( string $type ): self { return $this; }
	public IncomingTestDatabase $db;
	public FrauxSearchEngine $engine;
	public array $jobs = [];
	public array $callbacks = [];
	public function __construct() {
		$this->db = new IncomingTestDatabase();
	}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function writesOrCallbacksPending(): bool { return false; }
	public function explicitTrxActive(): bool { return false; }
	public function flushSnapshot( ...$args ): void { $this->db->flushSnapshot( ...$args ); }
	public function newSelectQueryBuilder(): IncomingTestQuery { return $this->db->newSelectQueryBuilder(); }
	public function getJobQueueGroup(): self { return $this; }
	public function push( $jobs ): void {
		foreach ( is_array( $jobs ) ? $jobs : [ $jobs ] as $job ) {
			if ( $job instanceof RefreshPageJob ) {
				$job = new class( $job->getParams(), $this->engine ) extends RefreshPageJob {
					public function __construct( array $params, private FrauxSearchEngine $engine ) {
						parent::__construct( $params );
					}
					protected function newSearchEngine(): FrauxSearchEngine { return $this->engine; }
				};
			}
			$this->jobs[] = $job;
		}
	}
	public function onTransactionCommitOrIdle( $callback, $caller ): void { $this->callbacks[] = $callback; }
	public function commit(): void {
		$callbacks = $this->callbacks;
		$this->callbacks = [];
		foreach ( $callbacks as $callback ) { $callback(); }
	}
}

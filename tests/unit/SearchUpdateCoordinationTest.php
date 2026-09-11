<?php

namespace FrauxSearch\Tests;

use FrauxSearch\FrauxSearchEngine;
use FrauxSearch\IndexCoordinator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class SearchUpdateCoordinationTest extends TestCase {
	public function testEveryEntryPointWaitsForSourceCommitBeforeCoordinatedRefresh(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		$database = new class {
			public bool $pending = true;
			public array $callbacks = [];
			public function getConnectionProvider(): self { return $this; }
			public function getPrimaryDatabase(): self { return $this; }
			public function explicitTrxActive(): bool { return false; }
			public function onTransactionCommitOrIdle( $callback, $method ): void {
				if ( $this->pending ) { $this->callbacks[] = $callback; } else { $callback(); }
			}
			public function flushSnapshot( ...$args ): void {
				if ( $this->pending ) { throw new \RuntimeException( 'Cannot flush pending source writes.' ); }
			}
		};
		\MediaWiki\MediaWikiServices::$instance = $database;
		$coordinator = new class extends IndexCoordinator {
			public array $refreshes = [];
			public function __construct() {}
			public function refresh( int $pageId, ?string $title = null, bool $completionOnly = false ): void {
				$this->refreshes[] = [ $pageId, $title ];
			}
		};
		$engine = new class( $coordinator ) extends FrauxSearchEngine {
			public function __construct( private IndexCoordinator $coordinator ) {}
			protected function newCoordinator(): IndexCoordinator { return $this->coordinator; }
		};
		$engine->update( 1, 'ignored', 'stale supplied text' );
		$engine->updateTitle( 2, 'ignored' );
		$engine->delete( 3, 'Old title' );
		$this->assertSame( [], $coordinator->refreshes );
		$database->pending = false;
		foreach ( $database->callbacks as $callback ) { $callback(); }
		$engine->refreshPage( 4 );
		$this->assertSame( [ [ 1, null ], [ 2, null ], [ 3, 'Old title' ], [ 4, null ] ], $coordinator->refreshes );
	}
}

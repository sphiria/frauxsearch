<?php

namespace FrauxSearch\Tests;

use FrauxSearch\Hooks;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class LinksUpdateHookTest extends TestCase {
	public function testCanonicalPageIsQueuedAfterLinkTableCommitForEachEvent(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		$services = new class {
			public array $jobs = [];
			public array $callbacks = [];
			public function getConnectionProvider(): self { return $this; }
			public function getPrimaryDatabase(): self { return $this; }
			public function newSelectQueryBuilder(): self { return $this; }
			public function select( ...$args ): self { return $this; }
			public function from( ...$args ): self { return $this; }
			public function leftJoin( ...$args ): self { return $this; }
			public function where( ...$args ): self { return $this; }
			public function caller( ...$args ): self { return $this; }
			public function fetchField(): bool { return false; }
			public function getMainObjectStash(): self { return $this; }
			public function getJobQueueGroup(): self { return $this; }
			public function push( array $jobs ): void { $this->jobs = array_merge( $this->jobs, $jobs ); }
			public function onTransactionCommitOrIdle( $callback, $caller ): void { $this->callbacks[] = $callback; }
		};
		\MediaWiki\MediaWikiServices::$instance = $services;
		$update = new class {
			public function getPageId(): int { return 7; }
			public function getRevisionRecord() { return null; }
		};
		$hooks = new Hooks();
		$hooks->onLinksUpdateComplete( $update, null );
		$hooks->onLinksUpdateComplete( $update, null );
		$this->assertSame( [], $services->jobs );
		foreach ( $services->callbacks as $callback ) { $callback(); }
		$this->assertSame( [ 7, 7 ], array_map( static fn ( $job ) => $job->getParams()['pageId'], $services->jobs ) );
		$this->assertNotSame( $services->jobs[0]->getParams()['sourceEvent'],
			$services->jobs[1]->getParams()['sourceEvent'] );
	}
}

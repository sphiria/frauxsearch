<?php

namespace FrauxSearch\Tests;

use FrauxSearch\BoostPolicyHooks;
use FrauxSearch\ScheduleBoostRefreshesJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class BoostPolicyHooksTest extends TestCase {
	private PolicyHookServices $services;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		define( 'NS_MEDIAWIKI', 8 );
		$this->services = new PolicyHookServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	private function page( string $name = 'Frauxsearch-boost-templates', int $namespace = 8 ) {
		return new class( $name, $namespace ) {
			public function __construct( private string $name, private int $namespace ) {}
			public function getNamespace(): int { return $this->namespace; }
			public function getDBkey(): string { return $this->name; }
			public function getTitle(): self { return $this; }
		};
	}

	private function revision( string $text, int $parent = 0 ) {
		return new class( $text, $parent ) {
			public function __construct( private string $text, private int $parent ) {}
			public function getContent( $slot ): self { return $this; }
			public function getTextForSearchIndex(): string { return $this->text; }
			public function getParentId(): int { return $this->parent; }
		};
	}

	public function testSaveDiffsActualParentRevisionAndWaitsForCommit(): void {
		$this->services->revisions[10] = $this->revision( "Removed|50%\nChanged|100%\nSame|150%" );
		( new BoostPolicyHooks() )->onPageSaveComplete( $this->page(), null, '', 0,
			$this->revision( "Added|200%\nChanged|150%\nSame|150%", 10 ), null );
		$this->assertSame( [], $this->services->jobs );
		$this->services->commit();
		$params = $this->services->jobs[0]->getParams();
		$this->assertEqualsCanonicalizing( [ 'Removed', 'Changed', 'Added' ], $params['templates'] );
		$this->assertFalse( $params['allPages'] );
	}

	public static function lifecycleEvents(): array {
		return [ 'delete' => [ 'delete' ], 'move away' => [ 'away' ],
			'move into' => [ 'into' ], 'restore' => [ 'restore' ] ];
	}

	#[DataProvider( 'lifecycleEvents' )]
	public function testLifecycleSchedulesAffectedTemplatesAfterCommit( string $event ): void {
		$hooks = new BoostPolicyHooks();
		$revision = $this->revision( 'Character|150%' );
		switch ( $event ) {
			case 'delete':
				$hooks->onPageDeleteComplete( $this->page(), null, '', 1, $revision, null, 1 );
				break;
			case 'away':
				$hooks->onPageMoveComplete( $this->page(), $this->page( 'Other' ), null, 1, 2, '', $revision );
				break;
			case 'into':
				$hooks->onPageMoveComplete( $this->page( 'Other' ), $this->page(), null, 1, 2, '', $revision );
				break;
			case 'restore':
				$hooks->onPageUndeleteComplete( $this->page(), null, '', $revision, null, 1, true, [ 1 ] );
		}
		$this->assertSame( [], $this->services->jobs );
		$this->services->commit();
		$this->assertCount( 1, $this->services->jobs );
		$this->assertInstanceOf( ScheduleBoostRefreshesJob::class, $this->services->jobs[0] );
		$params = $this->services->jobs[0]->getParams();
		$this->assertSame( $event === 'into' ? [] : [ 'Character' ], $params['templates'] );
		$this->assertSame( $event === 'into', $params['allPages'] );
	}

	public function testMissingParentAndMergedRestorationUseFullRecovery(): void {
		$hooks = new BoostPolicyHooks();
		$hooks->onPageSaveComplete( $this->page(), null, '', 0, $this->revision( 'Added|150%', 99 ), null );
		$hooks->onPageUndeleteComplete( $this->page(), null, '', $this->revision( '' ), null, 1, false, [ 1 ] );
		$this->services->commit();
		$this->assertCount( 2, $this->services->jobs );
		foreach ( $this->services->jobs as $job ) {
			$this->assertTrue( $job->getParams()['allPages'] );
		}
	}

	public function testUnchangedPolicyAndUnrelatedPagesDoNotQueueWork(): void {
		$hooks = new BoostPolicyHooks();
		$this->services->revisions[10] = $this->revision( 'Character|150%' );
		$hooks->onPageSaveComplete( $this->page(), null, '', 0, $this->revision( 'Character|150%', 10 ), null );
		$hooks->onPageDeleteComplete( $this->page( 'Other' ), null, '', 1, $this->revision( 'X|100%' ), null, 1 );
		$this->assertSame( [], $this->services->callbacks );
	}

	public function testSeparatePolicyEventsCannotDeduplicateEachOther(): void {
		$hooks = new BoostPolicyHooks();
		for ( $i = 0; $i < 2; $i++ ) {
			$hooks->onPageSaveComplete( $this->page(), null, '', 0, $this->revision( 'Character|150%' ), null );
		}
		$this->services->commit();
		$this->assertNotSame( $this->services->jobs[0]->getParams()['policyEvent'],
			$this->services->jobs[1]->getParams()['policyEvent'] );
	}

	public function testImportedPolicyQueuesFullRecoveryAfterCommitUsingLocalTitle(): void {
		( new BoostPolicyHooks() )->onAfterImportPage( $this->page(), $this->page( 'Foreign_policy' ),
			5, 1, [ 'id' => 987, 'title' => 'Foreign_policy' ] );
		$this->assertSame( [], $this->services->jobs );
		$this->assertCount( 1, $this->services->callbacks );
		$this->services->commit();
		$this->assertCount( 1, $this->services->jobs );
		$job = $this->services->jobs[0];
		$this->assertInstanceOf( ScheduleBoostRefreshesJob::class, $job );
		$this->assertTrue( $job->getParams()['allPages'] );
		$this->assertSame( [], $job->getParams()['templates'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $job->getParams()['policyEvent'] );
	}

	public static function skippedImports(): array {
		return [
			'no successfully imported revision' => [ 'Frauxsearch-boost-templates', 8, 0 ],
			'negative successful revision count' => [ 'Frauxsearch-boost-templates', 8, -1 ],
			'unrelated local title' => [ 'Other', 8, 1 ],
			'policy name in wrong local namespace' => [ 'Frauxsearch-boost-templates', 0, 1 ],
		];
	}

	#[DataProvider( 'skippedImports' )]
	public function testUnsuccessfulAndUnrelatedImportsDoNotQueueRecovery( string $name, int $namespace, int $successes ): void {
		( new BoostPolicyHooks() )->onAfterImportPage( $this->page( $name, $namespace ), $this->page(),
			5, $successes, [ 'id' => 987, 'title' => 'Frauxsearch-boost-templates' ] );
		$this->assertSame( [], $this->services->callbacks );
		$this->services->commit();
		$this->assertSame( [], $this->services->jobs );
	}
}

class PolicyHookServices {
	public array $jobs = [];
	public array $callbacks = [];
	public array $revisions = [];
	public function getRevisionLookup(): self { return $this; }
	public function getRevisionById( $id, $flags ) { return $this->revisions[$id] ?? null; }
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function getJobQueueGroup(): self { return $this; }
	public function push( $job ): void { $this->jobs[] = $job; }
	public function onTransactionCommitOrIdle( $callback, $caller ): void { $this->callbacks[] = $callback; }
	public function commit(): void {
		foreach ( $this->callbacks as $callback ) { $callback(); }
		$this->callbacks = [];
	}
}

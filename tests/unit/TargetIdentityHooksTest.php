<?php

namespace FrauxSearch\Tests;

use FrauxSearch\ScheduleIncomingRefreshesJob;
use FrauxSearch\TargetIdentityHooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class TargetIdentityHooksTest extends TestCase {
	private IdentityHookServices $services;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		$this->services = new IdentityHookServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	private function title( string $key, int $namespace = 0 ) {
		return new class( $namespace, $key ) {
			public function __construct( public int $namespace, public string $key ) {}
			public function getNamespace(): int { return $this->namespace; }
			public function getDBkey(): string { return $this->key; }
			public function getTitle(): self { return $this; }
		};
	}

	private function editResult( bool $new ) {
		return new class( $new ) {
			public function __construct( private bool $new ) {}
			public function isNew(): bool { return $this->new; }
		};
	}

	public static function events(): array {
		return [ 'create' => [ 'create' ], 'delete' => [ 'delete' ],
			'restore' => [ 'restore' ], 'restore into existing' => [ 'merge' ], 'import' => [ 'import' ] ];
	}

	#[DataProvider( 'events' )]
	public function testLifecycleQueuesTitleAfterCommitEvenIfPageChangesAgain( string $event ): void {
		$hooks = new TargetIdentityHooks();
		$title = $this->title( 'Target_with_underscores', 6 );
		switch ( $event ) {
			case 'create':
				$hooks->onPageSaveComplete( $title, null, '', 0, null, $this->editResult( true ) );
				break;
			case 'delete':
				$hooks->onPageDeleteComplete( $title, null, '', 42, null, null, 1 );
				break;
			case 'import':
				$hooks->onAfterImportPage( $title, $this->title( 'Foreign_name' ), 5, 1, [ 'id' => 999 ] );
				break;
			default:
				$hooks->onPageUndeleteComplete( $title, null, '', null, null, 1, $event === 'restore', [ 42 ] );
		}
		$this->assertSame( [], $this->services->jobs );
		$title->key = 'Moved_again';
		$title->namespace = 0;
		$this->services->commit();
		$this->assertCount( 1, $this->services->jobs );
		$job = $this->services->jobs[0];
		$this->assertInstanceOf( ScheduleIncomingRefreshesJob::class, $job );
		$this->assertSame( 6, $job->getParams()['namespace'] );
		$this->assertSame( 'Target_with_underscores', $job->getParams()['title'] );
		$this->assertNotEmpty( $job->getParams()['identityEvent'] );
	}

	public function testRapidMovesRetainBothSidesAndIntermediateNamesAcrossNamespaces(): void {
		$hooks = new TargetIdentityHooks();
		$a = $this->title( 'A' );
		$b = $this->title( 'B', 6 );
		$c = $this->title( 'C' );
		$hooks->onPageMoveComplete( $a, $b, null, 42, 0, '', null );
		$hooks->onPageMoveComplete( $b, $c, null, 42, 99, '', null );
		$this->assertSame( [], $this->services->jobs );
		$this->services->commit();
		$params = array_map( static fn ( $job ) => $job->getParams(), $this->services->jobs );
		$this->assertSame( [ [ 0, 'A' ], [ 6, 'B' ], [ 6, 'B' ], [ 0, 'C' ] ],
			array_map( static fn ( $p ) => [ $p['namespace'], $p['title'] ], $params ) );
		$this->assertSame( $params[0]['identityEvent'], $params[1]['identityEvent'] );
		$this->assertSame( $params[2]['identityEvent'], $params[3]['identityEvent'] );
		$this->assertNotSame( $params[0]['identityEvent'], $params[2]['identityEvent'] );
	}

	public function testRepeatedCreationEventsAreNotDeduplicatedAgainstOlderWork(): void {
		$hooks = new TargetIdentityHooks();
		for ( $i = 0; $i < 2; $i++ ) {
			$hooks->onPageSaveComplete( $this->title( 'Recreated' ), null, '', 0, null, $this->editResult( true ) );
		}
		$this->services->commit();
		$this->assertNotSame( $this->services->jobs[0]->getParams()['identityEvent'],
			$this->services->jobs[1]->getParams()['identityEvent'] );
	}

	public function testOrdinaryEditsAndNullEditsDoNotScanIncomingSources(): void {
		( new TargetIdentityHooks() )->onPageSaveComplete( $this->title( 'Existing' ), null, '', 0, null,
			$this->editResult( false ) );
		$this->assertSame( [], $this->services->callbacks );
		$this->assertSame( [], $this->services->jobs );
	}

	public function testImportWithoutSuccessfullyImportedRevisionsDoesNotScanSources(): void {
		( new TargetIdentityHooks() )->onAfterImportPage( $this->title( 'Already_present' ), null, 5, 0, [] );
		$this->assertSame( [], $this->services->callbacks );
		$this->assertSame( [], $this->services->jobs );
	}

	public function testRolledBackTransactionDoesNotQueueTheIdentityEvent(): void {
		( new TargetIdentityHooks() )->onPageSaveComplete( $this->title( 'Rolled_back' ), null, '', 0, null,
			$this->editResult( true ) );
		$this->services->callbacks = [];
		$this->services->commit();
		$this->assertSame( [], $this->services->jobs );
	}

	public function testQueueFailurePropagatesFromCommitCallback(): void {
		( new TargetIdentityHooks() )->onPageDeleteComplete( $this->title( 'Deleted' ), null, '', 42, null, null, 1 );
		$this->services->failPush = true;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'queue unavailable' );
		$this->services->commit();
	}

	public function testExtensionRegistersAllLifecycleHandlersAndScheduler(): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/extension.json' ), true, 512,
			JSON_THROW_ON_ERROR );
		foreach ( [ 'PageSaveComplete', 'PageMoveComplete', 'PageDeleteComplete', 'PageUndeleteComplete', 'AfterImportPage' ] as $hook ) {
			$this->assertContains( TargetIdentityHooks::class, (array)$manifest['Hooks'][$hook] );
		}
		$this->assertSame( ScheduleIncomingRefreshesJob::class,
			$manifest['JobClasses']['frauxSearchScheduleIncomingRefreshes']['class'] );
		$this->assertFalse( $manifest['JobClasses']['frauxSearchScheduleIncomingRefreshes']['needsPage'] );
	}
}

class IdentityHookServices {
	public array $jobs = [];
	public array $callbacks = [];
	public bool $failPush = false;
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function getJobQueueGroup(): self { return $this; }
	public function push( array $jobs ): void {
		if ( $this->failPush ) { throw new RuntimeException( 'queue unavailable' ); }
		$this->jobs = array_merge( $this->jobs, $jobs );
	}
	public function onTransactionCommitOrIdle( $callback, $caller ): void { $this->callbacks[] = $callback; }
	public function commit(): void {
		$callbacks = $this->callbacks;
		$this->callbacks = [];
		foreach ( $callbacks as $callback ) { $callback(); }
	}
}

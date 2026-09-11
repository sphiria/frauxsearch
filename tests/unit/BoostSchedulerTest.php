<?php

namespace FrauxSearch\Tests;

use FrauxSearch\ScheduleBoostRefreshesJob;
use FrauxSearch\RefreshPageJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class BoostSchedulerTest extends TestCase {
	public static function modes(): array { return [ 'templates' => [ false ], 'recovery' => [ true ] ]; }

	#[DataProvider( 'modes' )]
	public function testSchedulerPaginatesAndPreservesEventAcrossBatches( bool $allPages ): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		define( 'NS_TEMPLATE', 10 );
		$services = new class {
			public array $jobs = [];
			public array $tables = [];
			private int $startAfter = 0;
			public function getConnectionProvider(): self { return $this; }
			public function getPrimaryDatabase(): self { return $this; }
			public function newSelectQueryBuilder(): self { return $this; }
			public function select( ...$args ): self { return $this; }
			public function distinct(): self { return $this; }
			public function from( $table ): self { $this->tables[] = $table; return $this; }
			public function join( ...$args ): self { return $this; }
			public function where( $conditions ): self {
				$this->startAfter = (int)explode( ' > ', is_array( $conditions ) ? $conditions[0] : $conditions )[1];
				return $this;
			}
			public function orderBy( ...$args ): self { return $this; }
			public function limit( ...$args ): self { return $this; }
			public function caller( ...$args ): self { return $this; }
			public function fetchFieldValues(): array { return $this->startAfter === 0 ? range( 1, 1000 ) : [ 1001 ]; }
			public function getJobQueueGroup(): self { return $this; }
			public function push( $jobs ): void { $this->jobs = array_merge( $this->jobs, is_array( $jobs ) ? $jobs : [ $jobs ] ); }
		};
		\MediaWiki\MediaWikiServices::$instance = $services;
		$job = new ScheduleBoostRefreshesJob( [ 'templates' => $allPages ? [] : [ 'Character' ],
			'allPages' => $allPages, 'policyEvent' => 'event-123' ] );
		$this->assertTrue( $job->run() );
		$continuation = array_pop( $services->jobs );
		$this->assertInstanceOf( ScheduleBoostRefreshesJob::class, $continuation );
		$this->assertSame( 1000, $continuation->getParams()['startAfter'] );
		$this->assertTrue( $continuation->run() );
		$this->assertCount( 1001, $services->jobs );
		$this->assertSame( [ $allPages ? 'page' : 'templatelinks', $allPages ? 'page' : 'templatelinks' ], $services->tables );
		$first = $services->jobs[0];
		$last = $services->jobs[1000];
		$this->assertInstanceOf( RefreshPageJob::class, $last );
		$this->assertSame( [ 'pageId' => 1001, 'policyEvent' => 'event-123' ], $last->getParams() );
		$this->assertSame( $first->getParams()['policyEvent'], $last->getParams()['policyEvent'] );
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IncomingLinkCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class IncomingLinkCounterTest extends TestCase {
	private LinkCounterTestDatabase $database;
	private LinkCounterTestServices $services;

	protected function setUp(): void {
		if ( !extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'SQL join and aggregate tests require pdo_sqlite.' );
		}
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/linkCounter.php';
		$this->database = new LinkCounterTestDatabase();
		$this->services = new LinkCounterTestServices( $this->database );
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	private function seedLinks(): void {
		$this->database->page( 10, 'Target' );
		$this->database->page( 11, 'Target', 1 );
		$this->database->page( 12, 'Unlinked' );
		$this->database->page( 13, 'No_linktarget' );
		$this->database->target( 100, 'Target' );
		$this->database->target( 101, 'Target', 1 );
		$this->database->target( 102, 'Missing_page' );
		$this->database->target( 103, 'Unlinked' );
		foreach ( [ [ 1, 101 ], [ 1, 100 ], [ 1, 100 ], [ 1, 100 ], [ 2, 100 ],
			[ 1, 101 ], [ 1, 102 ], [ 1, 102 ], [ 3, 999 ] ] as [ $source, $target ]
		) {
			$this->database->link( $source, $target );
		}
	}

	public function testCountsDistinctSourcesPerExactTargetWithoutChangingStoredRows(): void {
		$this->seedLinks();
		$before = $this->database->connection->query( 'SELECT * FROM pagelinks ORDER BY rowid' )
			->fetchAll( \PDO::FETCH_ASSOC );
		$counts = ( new IncomingLinkCounter( true ) )->getCounts( [ 10, '10', 11, 12, 13, 999, 0, 'invalid' ] );
		$this->assertSame( [ 10 => 2, 11 => 1, 12 => 0, 13 => 0, 999 => 0 ], $counts );
		$this->assertSame( 1, $this->database->queries, 'All target counts come from one aggregate query.' );
		$this->assertCount( 9, $before );
		$this->assertSame( $before, $this->database->connection->query( 'SELECT * FROM pagelinks ORDER BY rowid' )
			->fetchAll( \PDO::FETCH_ASSOC ) );
	}

	public function testOutgoingIdsRemainSortedAndUniqueAndExcludeUnresolvedTargets(): void {
		$this->seedLinks();
		$this->assertSame( [ 1 => [ 10, 11 ], 2 => [ 10 ], 3 => [], 4 => [] ],
			( new IncomingLinkCounter( true ) )->getOutgoingTargetIds( [ 1, 2, 3, 4 ] ) );
		$this->assertSame( 1, $this->database->queries );
	}

	public static function readModes(): array {
		return [ 'replica default' => [ false ], 'latest primary' => [ true ] ];
	}

	#[DataProvider( 'readModes' )]
	public function testReadsSelectedDatabase( bool $latest ): void {
		$this->seedLinks();
		$counter = $latest ? new IncomingLinkCounter( true ) : new IncomingLinkCounter();
		$this->assertSame( [ 10 => 2 ], $counter->getCounts( [ 10 ] ) );
		$this->assertSame( $latest ? 1 : 0, $this->services->primaryReads );
		$this->assertSame( $latest ? 0 : 1, $this->services->replicaReads );
	}

	#[DataProvider( 'readModes' )]
	public function testEmptyAndZeroInputDoesNotConnectOrQuery( bool $latest ): void {
		$counter = new IncomingLinkCounter( $latest );
		$this->assertSame( [], $counter->getCounts( [] ) );
		$this->assertSame( [], $counter->getCounts( [ 0, '0', false, null, 'invalid' ] ) );
		$this->assertSame( 0, $this->services->primaryReads );
		$this->assertSame( 0, $this->services->replicaReads );
		$this->assertSame( 0, $this->database->queries );
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class HealthMaintenanceTest extends TestCase {
	public static function failedSnapshots(): array {
		return [ 'busy' => [ true, 'coordinator_busy' ], 'read failure' => [ false, 'health_check_failed' ] ];
	}

	#[DataProvider( 'failedSnapshots' )]
	public function testUnavailableSnapshotIsNonzeroWithoutInventingIdleState( bool $busy, string $issue ): void {
		putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__ ) . '/fixtures/mediawiki' );
		require_once dirname( __DIR__ ) . '/fixtures/health.php';
		require_once dirname( __DIR__, 2 ) . '/maintenance/checkFrauxSearchHealth.php';
		$services = new class {
			public object $coordinator;
			public function getMainConfig(): self { return $this; }
			public function get( string $name ): string {
				if ( $name !== 'FrauxSearchIndex' ) { throw new \LogicException( 'Unexpected configuration read.' ); }
				return 'health_test';
			}
		};
		$services->coordinator = new class( $busy ) {
			public function __construct( private bool $busy ) {}
			public function status(): never {
				if ( $this->busy ) {
					throw new MeilisearchException( 'Status is busy.', true, errorCode: 'coordination_busy' );
				}
				throw new RuntimeException( 'Status read failed.' );
			}
		};
		\MediaWiki\MediaWikiServices::$instance = $services;
		$command = new class extends \FrauxSearch\Maintenance\CheckFrauxSearchHealth {
			public string $output = '';
			public function output( ...$args ): void { $this->output .= $args[0]; }
			public function fatalError( string $message, int $exitCode ): never {
				throw new \DomainException( $message, $exitCode );
			}
		};
		try {
			$command->execute();
			$this->fail( 'An unavailable status snapshot was reported as healthy.' );
		} catch ( \DomainException $error ) {
			$this->assertSame( 1, $error->getCode() );
		}
		$report = json_decode( $command->output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertFalse( $report['healthy'] );
		$this->assertSame( [ $issue ], $report['issues'] );
		$this->assertArrayNotHasKey( 'run', $report );
		$this->assertArrayNotHasKey( 'pending', $report );
		$this->assertSame( $busy ? 'Status is busy.' : 'Status read failed.', $report['error'] );
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\IndexHealth;
use PHPUnit\Framework\TestCase;

class IndexHealthTest extends TestCase {
	private const NOW = 1788820200;

	public function testOrdinaryWorkIsReportedWithoutDeclaringAnOutage(): void {
		$state = [ 'run' => [ 'phase' => 'building' ], 'pending' => [
			'taskUid' => 12, 'startedAt' => gmdate( 'c', self::NOW - 20 ) ] ];
		$report = IndexHealth::report( $state, true,
			[ 'wiki' => [ 'documents' => 25, 'settingsDifferences' => [] ] ],
			[ 'refresh' => [ 'ready' => 2, 'acquired' => 1, 'delayed' => 0, 'abandoned' => 0 ] ], self::NOW );
		$this->assertTrue( $report['healthy'] );
		$this->assertSame( $state['pending'], $report['pending'] );
		$this->assertTrue( $report['pendingJournal'] );
	}

	public function testUnknownOutcomeIsUnhealthyImmediately(): void {
		$report = IndexHealth::report( [ 'run' => null, 'pending' => [
			'taskUid' => null, 'startedAt' => gmdate( 'c', self::NOW ) ] ], true, [], [], self::NOW );
		$this->assertSame( [ 'operation_outcome_unknown' ], $report['issues'] );
		$this->assertFalse( $report['healthy'] );
	}

	public function testOverdueKnownTaskAndExhaustedJobsAreUnhealthy(): void {
		$report = IndexHealth::report( [ 'run' => null, 'pending' => [
			'taskUid' => 12, 'startedAt' => gmdate( 'c', self::NOW - 901 ) ] ], true, [],
			[ 'refresh' => [ 'ready' => 0, 'acquired' => 0, 'delayed' => 0, 'abandoned' => 1 ] ], self::NOW );
		$this->assertSame( [ 'operation_overdue', 'refresh:retries_exhausted' ], $report['issues'] );
		$this->assertFalse( $report['healthy'] );
	}

	public function testSettingsUnavailableIndexAndCombinedBacklogAreReported(): void {
		$report = IndexHealth::report( [ 'run' => null, 'pending' => null ], false,
			[ 'wiki' => [ 'settingsDifferences' => [ 'rankingRules' ] ], 'completion' => [ 'error' => 'unavailable' ] ],
			[ 'refresh' => [ 'ready' => 7, 'acquired' => 2, 'delayed' => 2, 'abandoned' => 0 ] ], self::NOW, 900, 10 );
		$this->assertSame( [ 'wiki:settings_drift', 'completion:unavailable', 'refresh:backlog' ], $report['issues'] );
	}

	public function testRequireIdleAlsoRejectsDelayedJobsAndPendingJournal(): void {
		$report = IndexHealth::report( [ 'run' => null, 'pending' => null ], true, [],
			[ 'refresh' => [ 'ready' => 0, 'acquired' => 0, 'delayed' => 1, 'abandoned' => 0 ] ],
			self::NOW, requireIdle: true );
		$this->assertSame( [ 'refresh:not_idle', 'coordinator_not_idle' ], $report['issues'] );
	}

	public function testLostStateIsUnhealthyAndReportsRecoveryEpoch(): void {
		$epoch = str_repeat( 'a', 32 );
		$report = IndexHealth::report( [ 'run' => null, 'pending' => null,
			'coordinationLost' => true, 'guardEpoch' => $epoch ], false, [], [], self::NOW );
		$this->assertFalse( $report['healthy'] );
		$this->assertSame( [ 'coordination_state_lost' ], $report['issues'] );
		$this->assertSame( $epoch, $report['guardEpoch'] );
	}

	public function testGuardRetirementStillCountsAsUnfinishedWork(): void {
		$report = IndexHealth::report( [ 'run' => null, 'pending' => null, 'closing' => true ],
			false, [], [], self::NOW, requireIdle: true );
		$this->assertSame( [ 'coordinator_not_idle' ], $report['issues'] );
	}
}

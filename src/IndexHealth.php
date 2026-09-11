<?php

namespace FrauxSearch;

class IndexHealth {
	public static function report(
		array $state,
		bool $pendingPages,
		array $indexes,
		array $queues,
		int $now,
		int $maxPendingSeconds = 900,
		int $maxQueued = 10000,
		bool $requireIdle = false
	): array {
		$issues = [];
		if ( $state['coordinationLost'] ?? false ) { $issues[] = 'coordination_state_lost'; }
		$pending = $state['pending'];
		if ( $pending !== null ) {
			if ( $pending['taskUid'] === null ) { $issues[] = 'operation_outcome_unknown'; }
			$started = isset( $pending['startedAt'] ) ? strtotime( $pending['startedAt'] ) : false;
			if ( $started === false || $now - $started > $maxPendingSeconds ) {
				$issues[] = 'operation_overdue';
			}
		}
		foreach ( $indexes as $name => $index ) {
			if ( ( $index['settingsDifferences'] ?? [] ) !== [] ) { $issues[] = "$name:settings_drift"; }
			if ( isset( $index['error'] ) ) { $issues[] = "$name:unavailable"; }
		}
		foreach ( $queues as $type => $queue ) {
			if ( $queue['abandoned'] > 0 ) { $issues[] = "$type:retries_exhausted"; }
			if ( $queue['ready'] + $queue['acquired'] + $queue['delayed'] > $maxQueued ) {
				$issues[] = "$type:backlog";
			}
			if ( $requireIdle && $queue['ready'] + $queue['acquired'] + $queue['delayed'] > 0 ) {
				$issues[] = "$type:not_idle";
			}
		}
		if ( $requireIdle && ( $state['run'] !== null || $pending !== null || $pendingPages ||
			( $state['closing'] ?? false ) ) ) {
			$issues[] = 'coordinator_not_idle';
		}
		return [ 'healthy' => $issues === [], 'observedAt' => gmdate( 'c', $now ),
			'issues' => $issues, 'run' => $state['run'], 'pending' => $pending,
			'guardEpoch' => $state['guardEpoch'] ?? null, 'closing' => $state['closing'] ?? false,
			'pendingJournal' => $pendingPages, 'indexes' => $indexes, 'queues' => $queues ];
	}
}

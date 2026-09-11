<?php

namespace FrauxSearch;

class JobRunReport {
	public static function succeeded( array $report ): bool {
		if ( !isset( $report['jobs'] ) || !isset( $report['reached'] ) || !is_array( $report['jobs'] )
			|| !array_is_list( $report['jobs'] )
			|| !in_array( $report['reached'], [ 'none-ready', 'job-limit', 'time-limit' ], true )
		) { return false; }
		foreach ( $report['jobs'] as $job ) {
			if ( !is_array( $job ) || ( $job['status'] ?? null ) !== 'ok' ) { return false; }
		}
		return true;
	}
}

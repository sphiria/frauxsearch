<?php

namespace FrauxSearch\Tests;

use FrauxSearch\JobRunReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobRunReportTest extends TestCase {
	public static function reports(): array {
		return [
			'empty queue' => [ [ 'jobs' => [], 'reached' => 'none-ready' ], true ],
			'bounded success' => [ [ 'jobs' => [ [ 'status' => 'ok' ] ], 'reached' => 'job-limit' ], true ],
			'time bound' => [ [ 'jobs' => [ [ 'status' => 'ok' ] ], 'reached' => 'time-limit' ], true ],
			'failed job with exit zero' => [ [ 'jobs' => [ [ 'status' => 'failed' ] ], 'reached' => 'none-ready' ], false ],
			'caught exception' => [ [ 'jobs' => [], 'reached' => 'exception' ], false ],
			'unsupported job' => [ [ 'jobs' => [], 'reached' => 'none-possible' ], false ],
			'read only' => [ [ 'jobs' => [], 'reached' => 'read-only' ], false ],
			'replica lag' => [ [ 'jobs' => [], 'reached' => 'replica-lag-limit' ], false ],
			'memory bound' => [ [ 'jobs' => [], 'reached' => 'memory-limit' ], false ],
			'missing status' => [ [ 'jobs' => [ [] ], 'reached' => 'none-ready' ], false ],
			'malformed' => [ [], false ],
		];
	}

	#[DataProvider( 'reports' )]
	public function testSchedulerCannotMistakeFailedRunForSuccess( array $report, bool $success ): void {
		$this->assertSame( $success, JobRunReport::succeeded( $report ) );
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RebuildPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RebuildPlanTest extends TestCase {
	public function testDefaultUsesGeneration(): void {
		$plan = new RebuildPlan( [], 'wiki' );
		$this->assertTrue( $plan->generation );
		$this->assertFalse( $plan->dryRun );
		$this->assertSame( 'wiki', $plan->baseIndex );
	}

	public function testBoundedDryRunDoesNotCreateGeneration(): void {
		$plan = new RebuildPlan( [ 'dry-run' => true, 'stop-after' => '10' ], 'wiki' );
		$this->assertTrue( $plan->dryRun );
		$this->assertFalse( $plan->generation );
	}

	public function testExplicitResumePreservesActiveIndex(): void {
		$plan = new RebuildPlan( [ 'no-reset' => true, 'start-after' => '10' ], 'wiki' );
		$this->assertFalse( $plan->generation );
		$this->assertSame( 10, $plan->startAfter );
	}

	public function testIsolatedBoundedGenerationIsAllowed(): void {
		$plan = new RebuildPlan( [ 'generation' => true, 'index' => 'test', 'stop-after' => '10' ], 'wiki' );
		$this->assertTrue( $plan->generation );
	}

	#[DataProvider( 'unsafeOptions' )]
	public function testRejectsUnsafeOptionsBeforeRebuild( array $options ): void {
		$this->expectException( InvalidArgumentException::class );
		new RebuildPlan( $options, 'wiki' );
	}

	public static function unsafeOptions(): array {
		return [
			'partial reset' => [ [ 'stop-after' => '10' ] ],
			'resume reset' => [ [ 'start-after' => '10' ] ],
			'explicit zero start reset' => [ [ 'start-after' => '0' ] ],
			'live bounded swap' => [ [ 'generation' => true, 'index' => 'wiki', 'stop-after' => '10' ] ],
			'live completion swap' => [ [ 'generation' => true, 'index' => 'wiki_completion', 'stop-after' => '10' ] ],
			'generation without isolation' => [ [ 'generation' => true, 'stop-after' => '10' ] ],
			'generation without bound' => [ [ 'generation' => true, 'index' => 'test' ] ],
			'zero bound' => [ [ 'dry-run' => true, 'stop-after' => '0' ] ],
			'reversed bounds' => [ [ 'dry-run' => true, 'start-after' => '10', 'stop-after' => '5' ] ],
			'invalid bound' => [ [ 'dry-run' => true, 'stop-after' => 'ten' ] ],
			'invalid batch' => [ [ 'batch-size' => '0' ] ],
			'invalid queue' => [ [ 'max-pending' => '-1' ] ],
			'invalid index' => [ [ 'index' => '' ] ],
		];
	}
}

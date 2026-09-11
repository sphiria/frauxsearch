<?php

namespace FrauxSearch\Tests;

use FrauxSearch\RedirectChainResolver;
use PHPUnit\Framework\TestCase;

class RedirectChainResolverTest extends TestCase {
	public function testResolvesCanonicalTargetAcrossMultipleHops(): void {
		$edges = [ 1 => 2, 2 => 3 ];
		$this->assertSame( 3, RedirectChainResolver::resolve(
			1,
			static fn ( int $id ) => $edges[$id] ?? null
		) );
	}

	public function testReturnsNullForNonRedirect(): void {
		$this->assertNull( RedirectChainResolver::resolve( 1, static fn () => null ) );
	}

	public function testDetectsLoop(): void {
		$edges = [ 1 => 2, 2 => 1 ];
		$this->assertNull( RedirectChainResolver::resolve(
			1,
			static fn ( int $id ) => $edges[$id] ?? null
		) );
	}

	public function testRejectsChainsBeyondMaximumDepth(): void {
		$edges = [ 1 => 2, 2 => 3, 3 => 4 ];
		$this->assertNull( RedirectChainResolver::resolve(
			1,
			static fn ( int $id ) => $edges[$id] ?? null,
			2
		) );
	}

	public function testAcceptsExactlyMaximumHops(): void {
		$edges = [ 1 => 2, 2 => 3 ];
		$this->assertSame( 3, RedirectChainResolver::resolve(
			1, static fn ( int $id ) => $edges[$id] ?? null, 2
		) );
	}

	public function testDoesNotTreatBrokenRedirectAsCanonical(): void {
		$edges = [ 1 => 2, 2 => 0 ];
		$this->assertNull( RedirectChainResolver::resolve(
			1, static fn ( int $id ) => $edges[$id] ?? null
		) );
	}
}

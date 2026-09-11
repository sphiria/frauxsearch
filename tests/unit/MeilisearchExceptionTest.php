<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MeilisearchExceptionTest extends TestCase {
	public static function retryableMessages(): iterable {
		yield 'transport' => [ 'Meilisearch request failed: connection refused', true ];
		yield 'timeout' => [ 'Meilisearch task 12 timed out', true ];
		yield '408' => [ 'Meilisearch request failed (http_408): timeout', true ];
		yield '429' => [ 'Meilisearch request failed (http_429): throttled', true ];
		yield '503' => [ 'Meilisearch request failed (http_503): unavailable', true ];
		yield '400' => [ 'Meilisearch request failed (http_400): invalid', false ];
		yield 'unrelated' => [ 'database failed', false ];
	}

	#[DataProvider( 'retryableMessages' )]
	public function testClassifiesWrappedRuntimeExceptions( string $message, bool $expected ): void {
		$this->assertSame(
			$expected,
			MeilisearchException::isRetryableThrowable( new RuntimeException( $message ) )
		);
	}

	public function testPreservesExplicitRetryability(): void {
		$this->assertTrue( MeilisearchException::isRetryableThrowable(
			new MeilisearchException( 'custom', true )
		) );
		$this->assertFalse( MeilisearchException::isRetryableThrowable(
			new MeilisearchException( 'custom', false )
		) );
	}
}

<?php

namespace FrauxSearch;

use RuntimeException;

class MeilisearchException extends RuntimeException {
	public function __construct( string $message, private bool $retryable = false,
		private ?int $completedTaskId = null, private ?string $errorCode = null, private ?int $httpStatus = null
	) {
		parent::__construct( $message );
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}

	public function getCompletedTaskId(): ?int {
		return $this->completedTaskId;
	}

	public function getErrorCode(): ?string {
		return $this->errorCode;
	}

	public function getHttpStatus(): ?int {
		return $this->httpStatus;
	}

	public static function isRetryableThrowable( RuntimeException $e ): bool {
		if ( $e instanceof self ) {
			return $e->isRetryable();
		}
		$message = $e->getMessage();
		return str_starts_with( $message, 'Meilisearch request failed:' )
			|| str_contains( $message, 'Meilisearch request failed (http_408)' )
			|| str_contains( $message, 'Meilisearch request failed (http_429)' )
			|| preg_match( '/Meilisearch request failed \(http_5\d\d\)/', $message ) === 1
			|| str_contains( $message, 'Meilisearch task' ) && str_ends_with( $message, 'timed out' );
	}

	public static function isRetryableCode( string $code ): bool {
		return in_array( $code, [
			'internal',
			'io_error',
			'no_space_left_on_device',
			'too_many_open_files',
			'remote_timeout',
			'too_many_search_requests',
		], true );
	}
}

<?php

namespace FrauxSearch;

use InvalidArgumentException;

class QuerySyntaxException extends InvalidArgumentException {
	public function __construct( private string $messageKey, private array $messageParameters = [] ) {
		parent::__construct( $messageKey );
	}

	public function getMessageKey(): string {
		return $this->messageKey;
	}

	public function getMessageParameters(): array {
		return $this->messageParameters;
	}
}

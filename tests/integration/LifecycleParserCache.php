<?php

namespace FrauxSearch\Integration;

use Wikimedia\ObjectCache\HashBagOStuff;

class LifecycleParserCache extends HashBagOStuff {
	private bool $enabled = false;
	private int $hits = 0;

	public function withCaching( callable $callback ): mixed {
		if ( $this->enabled ) { throw new \LogicException( 'The lifecycle parser cache is already active.' ); }
		$this->clear();
		$this->hits = 0;
		$this->enabled = true;
		try {
			return $callback();
		} finally {
			$this->enabled = false;
			$this->clear();
		}
	}

	public function getHits(): int { return $this->hits; }

	protected function doGet( $key, $flags = 0, &$casToken = null ) {
		if ( !$this->enabled ) { $casToken = null; return false; }
		$value = parent::doGet( $key, $flags, $casToken );
		if ( $value !== false ) { $this->hits++; }
		return $value;
	}

	protected function doSet( $key, $value, $exptime = 0, $flags = 0 ) {
		return !$this->enabled || parent::doSet( $key, $value, $exptime, $flags );
	}
}

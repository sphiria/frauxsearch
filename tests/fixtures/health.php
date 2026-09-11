<?php

namespace FrauxSearch;

class IndexCoordinatorFactory {
	public static function create( ?string $base = null ): object {
		return \MediaWiki\MediaWikiServices::getInstance()->coordinator;
	}
}

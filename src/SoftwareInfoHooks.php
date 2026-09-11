<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;

class SoftwareInfoHooks {
	public function onSoftwareInfo( &$software ): void {
		try {
			$software['Meilisearch'] = $this->newClient()->getVersion();
		} catch ( MeilisearchException ) {
		}
	}

	protected function newClient(): MeilisearchClient {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		return new MeilisearchClient( (string)$config->get( 'FrauxSearchUrl' ),
			(string)$config->get( 'FrauxSearchApiKey' ), (string)$config->get( 'FrauxSearchIndex' ),
			max( 1, min( 2, (int)$config->get( 'FrauxSearchTimeout' ) ) ) );
	}
}

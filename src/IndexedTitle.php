<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;

class IndexedTitle {
	public static function fromDocument( array $document ): ?Title {
		$id = $document['id'] ?? null;
		$namespace = $document['namespace'] ?? null;
		$text = $document['title'] ?? null;
		if ( !is_int( $id ) || $id <= 0 || !is_int( $namespace ) || $namespace < 0
			|| !is_string( $text ) || $text === ''
			|| !MediaWikiServices::getInstance()->getNamespaceInfo()->exists( $namespace )
		) { return null; }
		if ( $namespace !== 0 ) {
			$separator = strpos( $text, ':' );
			if ( $separator === false || $separator === 0 ) { return null; }
			$text = substr( $text, $separator + 1 );
		}
		if ( $text === '' || preg_match( '/[\x00-\x1f\x7f#<>\[\]|{}]/u', $text ) !== 0 ) { return null; }
		$value = TitleValue::tryNew( $namespace, $text );
		if ( $value === null ) { return null; }
		return Title::newFromPageIdentity( PageIdentityValue::localIdentity( $id, $namespace, $value->getDBkey() ) );
	}
}

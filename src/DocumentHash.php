<?php

namespace FrauxSearch;

class DocumentHash {
	public static function compute( array $document ): string {
		unset( $document['document_hash'] );
		return hash( 'sha256', json_encode( $document,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}

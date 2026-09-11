<?php

namespace FrauxSearch\Tests;

class BatchServices {
	public int $snapshots = 0;
	public function getMainConfig(): self { return $this; }
	public function get( string $key ): string {
		if ( $key !== 'DBtype' ) { throw new \LogicException( 'Unexpected configuration access.' ); }
		return 'mysql';
	}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function getServer(): string { return 'fixture-db'; }
	public function getDomainID(): string { return 'fixture-wiki-prefix'; }
	public function flushSnapshot( string $caller ): void { $this->snapshots++; }
}

namespace FrauxSearch;

class IncomingLinkCounter {
	public static array $calls = [];
	public function __construct( bool $latest ) {
		if ( !$latest ) { throw new \LogicException( 'Replica source used.' ); }
	}
	public function getCounts( array $ids ): array {
		self::$calls[] = [ 'counts', $ids ];
		return array_fill_keys( $ids, 9 );
	}
	public function getOutgoingTargetIds( array $ids ): array {
		self::$calls[] = [ 'outgoing', $ids ];
		return array_fill_keys( $ids, [ 900, 901 ] );
	}
}

class DocumentBuilder {
	public static array $ids = [];
	public function __construct( private bool $latest, private array $incoming, private array $outgoing ) {
		if ( !$latest ) { throw new \LogicException( 'Replica document used.' ); }
	}
	public function build( int $id ): ?array {
		self::$ids[] = $id;
		if ( $id === 2 ) { return null; }
		$text = 'Rendered <tag> & 猫, page ' . $id;
		$document = [
			'id' => $id, 'revision_id' => 400 + $id, 'title' => 'Page ' . $id,
			'redirects' => [ 'Alias ' . $id ], 'namespace' => 0,
			'incoming_links' => $this->incoming[$id], 'outgoing_link_ids' => $this->outgoing[$id],
			'boost' => 125, 'text' => $text, 'timestamp' => '20260909000000',
			'word_count' => str_word_count( $text ), 'byte_size' => strlen( $text ),
		];
		$document['document_hash'] = DocumentHash::compute( $document );
		return [ 'document' => $document, 'is_redirect' => $id === 3, 'redirect_target_id' => $id === 3 ? 4 : null ];
	}
}

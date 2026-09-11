<?php

namespace FrauxSearch;

class IncomingSourceLookup {
	public function __construct( private $database ) {
	}

	/** @return int[] */
	public function getSourceIds( int $namespace, string $title, int $startAfter, int $limit ): array {
		if ( $namespace < 0 || $title === '' || $startAfter < 0 || $limit < 1 ) {
			throw new \InvalidArgumentException( 'Invalid incoming-source lookup bounds or title.' );
		}
		$linkSources = $this->database->newSelectQueryBuilder()
			->select( 'pl_from' )->distinct()->from( 'pagelinks' )
			->join( 'linktarget', null, 'pl_target_id = lt_id' )
			->where( [ 'pl_from > ' . $startAfter, 'lt_namespace' => $namespace, 'lt_title' => $title ] )
			->orderBy( 'pl_from' )->limit( $limit )->caller( __METHOD__ )->fetchFieldValues();
		$redirectSources = $this->database->newSelectQueryBuilder()
			->select( 'rd_from' )->distinct()->from( 'redirect' )
			->where( [
				'rd_from > ' . $startAfter,
				'rd_namespace' => $namespace,
				'rd_title' => $title,
				"(rd_interwiki IS NULL OR rd_interwiki = '')",
			] )
			->orderBy( 'rd_from' )->limit( $limit )->caller( __METHOD__ )->fetchFieldValues();
		$sources = array_values( array_unique( array_map( 'intval', array_merge( $linkSources, $redirectSources ) ) ) );
		sort( $sources, SORT_NUMERIC );
		return array_slice( $sources, 0, $limit );
	}
}

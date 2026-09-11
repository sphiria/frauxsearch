<?php

namespace FrauxSearch;

class RankedSearch {
	private const MAX_RESULTS = 1000;

	/** @param string[] $exactTerms */
	public static function search( MeilisearchClient $client, array $query, array $exactTerms = [] ): array {
		if ( $exactTerms === [] ) { return $client->search( $query ); }
		$offset = $query['offset'] ?? 0;
		$limit = $query['limit'] ?? 20;
		if ( !is_int( $offset ) || $offset < 0 || !is_int( $limit ) || $limit < 0
			|| isset( $query['page'] ) || isset( $query['hitsPerPage'] )
		) { throw new \InvalidArgumentException( 'Ranked search requires a nonnegative integer offset and limit.' ); }
		$filters = $query['filter'] ?? [];
		if ( is_string( $filters ) ) { $filters = $filters === '' ? [] : [ $filters ]; }
		if ( !is_array( $filters ) || !array_is_list( $filters ) ) {
			throw new \InvalidArgumentException( 'Ranked search filters must be a string or list.' );
		}
		$conditions = [];
		foreach ( $exactTerms as $term ) {
			if ( !is_string( $term ) || $term === '' ) {
				throw new \InvalidArgumentException( 'Exact search terms must be nonempty strings.' );
			}
			$encoded = json_encode( $term, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
			$conditions[] = "title = $encoded";
			$conditions[] = "redirects = $encoded";
		}
		$exactFilter = '(' . implode( ' OR ', array_unique( $conditions ) ) . ')';
		$limit = min( $limit, self::MAX_RESULTS - min( $offset, self::MAX_RESULTS ) );
		$exactLimit = max( 1, min( self::MAX_RESULTS, min( $offset, self::MAX_RESULTS ) + $limit ) );
		$exactQuery = $query;
		unset( $exactQuery['offset'], $exactQuery['limit'] );
		$exactQuery['page'] = 1;
		$exactQuery['hitsPerPage'] = $exactLimit;
		$exactQuery['filter'] = [ ...$filters, $exactFilter ];
		$exact = $client->search( $exactQuery );
		$exactTotal = self::total( $exact, 'totalHits' );
		if ( count( $exact['hits'] ) !== min( $exactTotal, $exactLimit )
			|| ( array_key_exists( 'page', $exact ) && $exact['page'] !== 1 )
			|| ( array_key_exists( 'hitsPerPage', $exact ) && $exact['hitsPerPage'] !== $exactLimit )
		) {
			throw new MeilisearchException( 'Meilisearch exact search returned incomplete pagination.', true );
		}
		$hits = array_slice( $exact['hits'], min( $offset, self::MAX_RESULTS ), $limit );
		$ordinaryQuery = $query;
		$ordinaryQuery['offset'] = max( 0, min( $offset, self::MAX_RESULTS ) - $exactTotal );
		$ordinaryQuery['limit'] = $limit - count( $hits );
		$ordinaryQuery['filter'] = [ ...$filters, 'NOT ' . $exactFilter ];
		$ordinary = $client->search( $ordinaryQuery );
		$totalField = array_key_exists( 'totalHits', $ordinary ) ? 'totalHits' : 'estimatedTotalHits';
		$ordinaryTotal = self::total( $ordinary, $totalField );
		if ( count( $ordinary['hits'] ) > $ordinaryQuery['limit'] || count( $ordinary['hits'] ) > $ordinaryTotal ) {
			throw new MeilisearchException( 'Meilisearch ordinary search returned inconsistent pagination.', true );
		}
		$ordinary['hits'] = [ ...$hits, ...$ordinary['hits'] ];
		$ordinary['offset'] = $offset;
		$ordinary['limit'] = $limit;
		unset( $ordinary['totalHits'], $ordinary['estimatedTotalHits'] );
		$ordinary[$totalField] = min( self::MAX_RESULTS,
			min( self::MAX_RESULTS, $exactTotal ) + min( self::MAX_RESULTS, $ordinaryTotal ) );
		if ( isset( $exact['processingTimeMs'] ) && isset( $ordinary['processingTimeMs'] )
			&& is_int( $exact['processingTimeMs'] ) && $exact['processingTimeMs'] >= 0
			&& is_int( $ordinary['processingTimeMs'] ) && $ordinary['processingTimeMs'] >= 0
			&& $exact['processingTimeMs'] <= PHP_INT_MAX - $ordinary['processingTimeMs']
		) { $ordinary['processingTimeMs'] += $exact['processingTimeMs']; }
		return $ordinary;
	}

	private static function total( array $response, string $field ): int {
		if ( !isset( $response[$field] ) || !is_int( $response[$field] ) || $response[$field] < 0 ) {
			throw new MeilisearchException( 'Meilisearch search returned no valid ' . $field . '.', true );
		}
		return $response[$field];
	}
}

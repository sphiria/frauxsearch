<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\Search\SearchEngine;
use MediaWiki\Search\SearchSuggestion;
use MediaWiki\Search\SearchSuggestionSet;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use Throwable;

class FrauxSearchEngine extends SearchEngine {
	private const PREFIX_CANDIDATE_LIMIT = 1000;
	private MeilisearchClient $client;
	private MeilisearchClient $completionClient;

	public function __construct() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->client = new MeilisearchClient(
			(string)$config->get( 'FrauxSearchUrl' ),
			(string)$config->get( 'FrauxSearchApiKey' ),
			(string)$config->get( 'FrauxSearchIndex' ),
			(int)$config->get( 'FrauxSearchTimeout' ),
			(string)$config->get( 'FrauxSearchTaskApiKey' )
		);
		$this->completionClient = $this->client->withIndex(
			(string)$config->get( 'FrauxSearchIndex' ) . '_completion'
		);
	}

	protected function doSearchText( $term ) {
		try {
			return $this->runSearch( (string)$term, [ 'title', 'redirects', 'text' ] );
		} catch ( QuerySyntaxException $e ) {
			return Status::newFatal( $e->getMessageKey(), ...$e->getMessageParameters() );
		}
	}

	protected function doSearchTitle( $term ) {
		try {
			return $this->runSearch( (string)$term, [ 'title', 'redirects' ] );
		} catch ( QuerySyntaxException ) {
			return null;
		}
	}

	protected function processCompletionResults( $search, SearchSuggestionSet $suggestions ) {
		$suggestions->shrink( $this->limit );
		return $suggestions;
	}

	protected function simplePrefixSearch( $search ) {
		if ( $this->limit <= 0 || $this->offset >= self::PREFIX_CANDIDATE_LIMIT ) { return []; }
		$prefix = str_replace( '_', ' ', trim( (string)$search ) );
		$query = [ 'q' => $prefix, 'attributesToSearchOn' => [ 'title' ],
			'attributesToRetrieve' => [ 'id', 'title', 'namespace' ],
			'matchingStrategy' => 'all', 'limit' => self::PREFIX_CANDIDATE_LIMIT, 'offset' => 0 ];
		if ( $this->namespaces !== null && $this->namespaces !== [] ) {
			$query['filter'] = [ array_values( array_map( static fn ( int $ns ) => "namespace = $ns", $this->namespaces ) ) ];
		}
		try {
			$response = $this->client->search( $query );
		} catch ( Throwable $e ) {
			wfDebugLog( 'FrauxSearch', $e->getMessage() );
			return [];
		}
		$services = MediaWikiServices::getInstance();
		$titles = [];
		foreach ( $response['hits'] as $hit ) {
			$title = IndexedTitle::fromDocument( $hit );
			if ( $title === null ) { continue; }
			$normalized = $services->getNamespaceInfo()->isCapitalized( $title->getNamespace() )
				? $services->getContentLanguage()->ucfirst( $prefix ) : $prefix;
			if ( str_starts_with( $title->getText(), $normalized ) ) { $titles[] = $title; }
		}
		usort( $titles, static fn ( Title $a, Title $b ) => strcmp( $a->getDBkey(), $b->getDBkey() )
			?: $a->getNamespace() <=> $b->getNamespace() );
		return array_slice( $titles, max( 0, $this->offset ), $this->limit );
	}

	protected function completionSearchBackend( $search ) {
		$search = self::normalizeTerm( (string)$search, true );
		$query = [
			'q' => $search,
			'attributesToSearchOn' => [ 'title', 'redirects' ],
			'matchingStrategy' => 'all',
			'limit' => $this->limit,
			'offset' => $this->offset,
		];
		if ( $this->namespaces !== null && $this->namespaces !== [] ) {
			$query['filter'] = [
				array_values( array_map( static fn ( int $namespace ) => "namespace = $namespace", $this->namespaces ) ),
			];
		}
		$query['attributesToRetrieve'] = [ 'id', 'title', 'namespace', 'redirects' ];
		try {
			$response = RankedSearch::search(
				$this->completionClient, $query, $this->exactTerms( $search, $this->namespaces )
			);
		} catch ( Throwable $e ) {
			wfDebugLog( 'FrauxSearch', $e->getMessage() );
			return new SearchSuggestionSet( [] );
		}
		$suggestions = [];
		foreach ( array_slice( $response['hits'] ?? [], 0, $this->limit ) as $position => $hit ) {
			$title = IndexedTitle::fromDocument( $hit );
			if ( $title !== null ) {
				$displayText = CompletionHelper::matchingRedirect( $search, $hit['redirects'] ?? [] )
					?? $title->getPrefixedText();
				$suggestions[] = new SearchSuggestion(
					$this->limit - $position,
					$displayText,
					$title,
					(int)( $hit['id'] ?? 0 )
				);
			}
		}
		return new SearchSuggestionSet( $suggestions );
	}

	public function update( $id, $title, $text ) {
		$this->refreshPage( (int)$id );
	}

	public function updateTitle( $id, $title ) {
		$this->refreshPage( (int)$id );
	}

	public function delete( $id, $title ) {
		$this->refreshPage( (int)$id, $title instanceof Title ? $title->getPrefixedText() : (string)$title );
	}

	public function refreshPage( int $pageId, ?string $redirectTitle = null ): void {
		if ( $pageId <= 0 ) { return; }
		$primary = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$primary->onTransactionCommitOrIdle(
			fn () => $this->refreshPageNow( $pageId, $redirectTitle ), __METHOD__
		);
	}

	public function refreshPageNow( int $pageId, ?string $redirectTitle = null ): void {
		if ( $pageId <= 0 ) { return; }
		$primary = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		if ( $primary->explicitTrxActive() ) {
			throw new \RuntimeException( 'Synchronous FrauxSearch refresh requires an idle primary transaction.' );
		}
		$primary->flushSnapshot( __METHOD__ );
		$this->newCoordinator()->refresh( $pageId, $redirectTitle );
	}

	protected function newCoordinator(): IndexCoordinator {
		return IndexCoordinatorFactory::create();
	}

	public function supports( $feature ) {
		return $feature === 'search-update' || parent::supports( $feature );
	}

	private function runSearch( string $term, array $attributes ): FrauxSearchResultSet {
		$term = self::normalizeTerm( $term );
		$prefixed = str_starts_with( $term, 'all:' )
			? [ substr( $term, 4 ), null ] : self::parseNamespacePrefixes( $term, false, false );
		$namespaces = $prefixed === false ? $this->namespaces : $prefixed[1];
		$parsed = SearchQuery::parse( $prefixed === false ? $term : $prefixed[0] );
		$marker = bin2hex( random_bytes( 16 ) );
		$highlightTags = [ "__fs_{$marker}_open__", "__fs_{$marker}_close__" ];
		try {
			$response = RankedSearch::search(
				$this->completionClient,
				$this->query( $parsed['q'], $attributes, $this->limit, $highlightTags, $namespaces ),
				$parsed['hasSyntax'] ? [] : $this->exactTerms( $parsed['q'], $namespaces )
			);
		} catch ( Throwable $e ) {
			wfDebugLog( 'FrauxSearch', $e->getMessage() );
			return new FrauxSearchResultSet( [], 0, false );
		}
		$results = array_map(
			static fn ( array $hit ) => new FrauxSearchResult( $hit, $highlightTags ),
			$response['hits'] ?? []
		);
		$approximate = isset( $response['estimatedTotalHits'] ) && !isset( $response['totalHits'] );
		$total = (int)( $response['totalHits'] ?? $response['estimatedTotalHits'] ?? count( $results ) );
		return new FrauxSearchResultSet(
			$results,
			$total,
			$this->offset + count( $results ) < $total,
			$approximate,
			$parsed['hasSyntax'] || $prefixed !== false
		);
	}

	private static function normalizeTerm( string $term, bool $replaceUnderscores = false ): string {
		if ( $replaceUnderscores ) { $term = str_replace( '_', ' ', $term ); }
		return preg_replace( '/\s+/u', ' ', trim( $term ) ) ?? $term;
	}

	/** @return string[] */
	private function exactTerms( string $term, ?array $namespaces ): array {
		$term = self::normalizeTerm( $term, true );
		if ( $term === '' ) { return []; }
		$terms = [ $term ];
		$names = MediaWikiServices::getInstance()->getContentLanguage()->getNamespaces();
		foreach ( $namespaces ?: array_keys( $names ) as $namespace ) {
			if ( $namespace > 0 && isset( $names[$namespace] ) && $names[$namespace] !== '' ) {
				$terms[] = str_replace( '_', ' ', $names[$namespace] ) . ':' . $term;
			}
		}
		return array_values( array_unique( $terms ) );
	}

	private function query(
		string $term, array $attributes, int $limit, array $highlightTags, ?array $namespaces
	): array {
		$query = [
			'q' => trim( $term ),
			'attributesToSearchOn' => $attributes,
			'matchingStrategy' => 'all',
			'attributesToRetrieve' => [ 'id', 'title', 'namespace', 'timestamp', 'word_count', 'byte_size' ],
			'attributesToHighlight' => [ 'title', 'text' ],
			'highlightPreTag' => $highlightTags[0],
			'highlightPostTag' => $highlightTags[1],
			'attributesToCrop' => [ 'text' ],
			'cropLength' => 50,
			'limit' => $limit,
			'offset' => $this->offset,
		];
		if ( $namespaces !== null && $namespaces !== [] ) {
			$query['filter'] = [
				array_values( array_map( static fn ( int $namespace ) => "namespace = $namespace", $namespaces ) ),
			];
		}
		return $query;
	}

}

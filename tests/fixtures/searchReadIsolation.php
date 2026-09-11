<?php

namespace {
	if ( PHP_SAPI === 'cli-server' ) {
		$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		header( 'Content-Type: application/json' );
		if ( $path === '/fixture-ready' ) {
			echo '{"fixture":"frauxsearch-search-read-isolation"}';
			return;
		}
		$body = json_decode( file_get_contents( 'php://input' ), true, 512, JSON_THROW_ON_ERROR );
		file_put_contents( getenv( 'FRAUXSEARCH_READ_REQUESTS' ), json_encode( [
			'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'body' => $body,
		], JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX );
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST'
			|| !in_array( $path, [ '/indexes/read-isolation/search', '/indexes/read-isolation_completion/search' ], true )
			|| ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'Bearer application-test-key'
		) {
			http_response_code( 403 );
			echo '{"code":"forbidden","message":"Only application-key search requests are allowed."}';
			return;
		}
		if ( $body['q'] === 'Unavailable' ) {
			http_response_code( 503 );
			echo '{"code":"internal","message":"Search temporarily unavailable."}';
			return;
		}
		if ( $body['q'] === 'Empty' ) {
			echo '{"hits":[],"totalHits":0}';
			return;
		}
		$open = $body['highlightPreTag'] ?? '';
		$close = $body['highlightPostTag'] ?? '';
		$hits = [ [ 'id' => 42, 'title' => 'Narmaya', 'namespace' => 0, 'redirects' => [ 'Naru' ],
			'timestamp' => '20260908000000', 'word_count' => 17, 'byte_size' => 1234,
			'_formatted' => [ 'title' => $open . 'Narmaya' . $close,
				'text' => 'Indexed <script>alert(1)</script> ' . $open . 'Kaleidoscope' . $close ],
		] ];
		if ( !in_array( 'timestamp', $body['attributesToRetrieve'] ?? [], true ) ) {
			$hits[] = [ 'id' => 43, 'title' => 'Narmaya (Summer)', 'namespace' => 0, 'redirects' => [] ];
			$hits[] = [ 'id' => 44, 'title' => 'Narmaya (Holiday)', 'namespace' => 0, 'redirects' => [] ];
		}
		if ( $body['q'] === 'Bucket' ) {
			$hits[0]['title'] = 'Canonical alias target';
			$hits[0]['redirects'] = [ 'Bucket' ];
			$hits[] = [ 'id' => 63, 'title' => 'Bucket companion', 'namespace' => 0, 'redirects' => [] ];
			$hits[] = [ 'id' => 64, 'title' => 'Bucket guide', 'namespace' => 0, 'redirects' => [] ];
			$hits[] = [ 'id' => 65, 'title' => 'Bucket notes', 'namespace' => 0, 'redirects' => [] ];
		}
		if ( $body['q'] === 'NamespaceCases' ) {
			$hits = [
				[ 'id' => 40442, 'title' => 'Fate: Grand Order', 'namespace' => 0, 'redirects' => [] ],
				[ 'id' => 40443, 'title' => 'File:Narmaya art.png', 'namespace' => 6, 'redirects' => [] ],
			];
		}
		if ( $path === '/indexes/read-isolation/search'
			&& ( $body['attributesToRetrieve'] ?? [] ) === [ 'id', 'title', 'namespace' ]
		) {
			$hits = [
				[ 'id' => 48, 'title' => 'Narmaya (Summer)', 'namespace' => 0 ],
				[ 'id' => 45, 'title' => 'About Narmaya', 'namespace' => 0 ],
				[ 'id' => 46, 'title' => 'Narmaia', 'namespace' => 0 ],
				[ 'id' => 49, 'title' => 'Narmaya redirect', 'namespace' => 0, 'is_redirect' => true ],
				[ 'id' => 47, 'title' => 'Narmaya (Holiday)', 'namespace' => 0 ],
				[ 'id' => 42, 'title' => 'Narmaya', 'namespace' => 0 ],
			];
		}
		$excludedExact = 0;
		foreach ( $body['filter'] ?? [] as $filter ) {
			if ( is_array( $filter ) ) {
				$namespaces = array_map( static function ( string $expression ): int {
					if ( !preg_match( '/^namespace = ([0-9]+)$/', $expression, $match ) ) {
						throw new \LogicException( 'Unexpected namespace filter.' );
					}
					return (int)$match[1];
				}, $filter );
				$hits = array_values( array_filter( $hits,
					static fn ( array $hit ) => in_array( $hit['namespace'], $namespaces, true ) ) );
				continue;
			}
			preg_match_all( '/(?:title|redirects) = ("(?:[^"\\\\]|\\\\.)*")/u', $filter, $matches );
			if ( $matches[1] === [] ) { throw new \LogicException( 'Unexpected exact bucket filter.' ); }
			$terms = array_map( static fn ( string $value ) => json_decode( $value, true, 512, JSON_THROW_ON_ERROR ), $matches[1] );
			$not = str_starts_with( $filter, 'NOT ' );
			$before = count( $hits );
			$hits = array_values( array_filter( $hits, static function ( array $hit ) use ( $terms, $not ): bool {
				$exact = array_intersect( [ $hit['title'], ...( $hit['redirects'] ?? [] ) ], $terms ) !== [];
				return $not ? !$exact : $exact;
			} ) );
			if ( $not ) { $excludedExact = $before - count( $hits ); }
		}
		$total = count( $hits );
		$hits = array_slice( $hits, (int)( $body['offset'] ?? 0 ),
			(int)( $body['hitsPerPage'] ?? $body['limit'] ?? count( $hits ) ) );
		if ( isset( $body['attributesToRetrieve'] ) ) {
			$fields = array_fill_keys( [ ...$body['attributesToRetrieve'], '_formatted' ], true );
			$hits = array_map( static fn ( array $hit ) => array_intersect_key( $hit, $fields ), $hits );
		}
		$exactTotal = isset( $body['hitsPerPage'] ) || $body['q'] === 'Bucket';
		echo json_encode( [ 'hits' => $hits, $exactTotal ? 'totalHits' : 'estimatedTotalHits' =>
			$exactTotal ? $total : 9 - $excludedExact ], JSON_THROW_ON_ERROR );
		return;
	}

	function wfDebugLog( string $group, string $message ): void {
		\FrauxSearch\Tests\SearchReadIsolationState::$logs[] = [ $group, $message ];
	}
}

namespace FrauxSearch\Tests {
	class SearchReadIsolationState {
		public static array $forbidden = [];
		public static array $logs = [];
		public static function forbid( string $operation ): never {
			self::$forbidden[] = $operation;
			throw new \LogicException( 'Search attempted forbidden source/storage access: ' . $operation );
		}
	}

	class SearchReadIsolationServices {
		public function __construct( private string $url ) {}
		public function getMainConfig(): self { return $this; }
		public function get( string $name ) {
			return match ( $name ) {
				'FrauxSearchUrl' => $this->url,
				'FrauxSearchApiKey' => 'application-test-key',
				'FrauxSearchTaskApiKey' => 'task-test-key',
				'FrauxSearchIndex' => 'read-isolation',
				'FrauxSearchTimeout' => 2,
				default => SearchReadIsolationState::forbid( 'configuration:' . $name ),
			};
		}
		public function getUrlUtils(): self { return $this; }
		public function expand( string $url, $protocol = null ): string { return $url; }
		public function getContentLanguage(): self { return $this; }
		public function getNamespaces(): array { return [ 0 => '', 1 => 'Talk', 6 => 'File' ]; }
		public function getNsIndex( string $name ): int|false {
			return [ 'talk' => 1, 'file' => 6, 'image' => 6 ][strtolower( $name )] ?? false;
		}
		public function getNamespaceInfo(): self { return $this; }
		public function exists( int $namespace ): bool { return in_array( $namespace, [ 0, 1, 6 ], true ); }
		public function isCapitalized( int $namespace ): bool { return true; }
		public function ucfirst( string $text ): string { return ucfirst( $text ); }
		public function getLanguageConverterFactory(): self { return $this; }
		public function getLanguageConverter( $language ): self { return $this; }
		public function autoConvertToAllVariants( string $search ): array { return [ $search ]; }
		public function __call( string $method, array $arguments ): never {
			SearchReadIsolationState::forbid( 'service:' . $method );
		}
	}
}

namespace MediaWiki {
	class MediaWikiServices {
		public static object $instance;
		public static function getInstance(): object { return self::$instance; }
	}
}

namespace MediaWiki\Page {
	class PageIdentityValue {
		public function __construct( private int $id, private int $namespace, private string $dbkey ) {}
		public static function localIdentity( int $id, int $namespace, string $dbkey ): self {
			return new self( $id, $namespace, $dbkey );
		}
		public function getId(): int { return $this->id; }
		public function getNamespace(): int { return $this->namespace; }
		public function getDBkey(): string { return $this->dbkey; }
	}
}

namespace MediaWiki\Title {
	class TitleValue {
		private function __construct( private string $dbkey ) {}
		public static function tryNew( int $namespace, string $text ): ?self {
			if ( preg_match( '/^[_ ]|[\r\n\t]|[_ ]$/', $text ) || ( $text === '' && $namespace !== 0 ) ) { return null; }
			return new self( str_replace( ' ', '_', $text ) );
		}
		public function getDBkey(): string { return $this->dbkey; }
	}

	class Title {
		private int $id = -1;
		public function __construct( private string $text, private int $namespace = 0 ) {}
		public static function newFromText( string $text ): never {
			\FrauxSearch\Tests\SearchReadIsolationState::forbid( 'Title::newFromText interwiki parsing' );
		}
		public static function newFromPageIdentity( $page ): self {
			$title = new self( str_replace( '_', ' ', $page->getDBkey() ), $page->getNamespace() );
			$title->id = $page->getId();
			return $title;
		}
		public function getNamespace(): int { return $this->namespace; }
		public function getText(): string { return $this->text; }
		public function getDBkey(): string { return str_replace( ' ', '_', $this->text ); }
		public function getPrefixedText(): string {
			return ( [ 0 => '', 1 => 'Talk:', 6 => 'File:' ][$this->namespace] ) . $this->text;
		}
		public function getFullURL(): string {
			return 'https://wiki.invalid/wiki/' . rawurlencode( str_replace( ' ', '_', $this->getPrefixedText() ) );
		}
		public function getArticleID(): int {
			if ( $this->id < 0 ) { \FrauxSearch\Tests\SearchReadIsolationState::forbid( 'Title::getArticleID' ); }
			return $this->id;
		}
		public function isKnown(): bool { return $this->getArticleID() > 0; }
		public function __call( string $method, array $arguments ): never {
			\FrauxSearch\Tests\SearchReadIsolationState::forbid( 'Title::' . $method );
		}
	}
}

namespace MediaWiki\Search {
	use MediaWiki\MediaWikiServices;
	use MediaWiki\Status\Status;
	use MediaWiki\Title\Title;

	class SearchEngine {
		protected int $limit = 20;
		protected int $offset = 0;
		protected ?array $namespaces = null;
		public function setLimitOffset( int $limit, int $offset = 0 ): void { $this->limit = $limit; $this->offset = $offset; }
		public function setNamespaces( array $namespaces ): void { $this->namespaces = $namespaces; }
		public function getNamespaces(): ?array { return $this->namespaces; }
		public static function parseNamespacePrefixes(
			$query, $withAllKeyword = true, $withPrefixSearchExtractNamespaceHook = false
		) {
			if ( $withAllKeyword || $withPrefixSearchExtractNamespaceHook ) {
				\FrauxSearch\Tests\SearchReadIsolationState::forbid( 'Namespace localization or extension hooks' );
			}
			$colon = strpos( $query, ':' );
			if ( $colon === false ) { return false; }
			$prefix = str_replace( ' ', '_', substr( $query, 0, $colon ) );
			$namespace = MediaWikiServices::getInstance()->getContentLanguage()->getNsIndex( $prefix );
			return $namespace === false ? false : [ substr( $query, $colon + 1 ), [ $namespace ] ];
		}
		public function searchText( $term ) { return $this->paginate( fn () => $this->doSearchText( $term ) ); }
		public function searchTitle( $term ) { return $this->paginate( fn () => $this->doSearchTitle( $term ) ); }
		private function paginate( callable $callback ) {
			$this->limit++;
			try { $results = $callback(); } finally { $this->limit--; }
			$set = $results instanceof Status ? $results->getValue() : $results;
			if ( $set instanceof SearchResultSet ) { $set->shrink( $this->limit ); }
			return $results;
		}
		protected function normalizeNamespaces( $search ) { return $search; }
		protected function completionSearchBackendOverfetch( $search ) {
			$this->limit++;
			try { return $this->completionSearchBackend( $search ); } finally { $this->limit--; }
		}
		public function completionSearch( $search ) {
			if ( trim( $search ) === '' ) { return new SearchSuggestionSet( [] ); }
			$search = $this->normalizeNamespaces( $search );
			return $this->processCompletionResults( $search, $this->completionSearchBackendOverfetch( $search ) );
		}
		public function completionSearchWithVariants( $search ) {
			if ( trim( $search ) === '' ) { return new SearchSuggestionSet( [] ); }
			$search = $this->normalizeNamespaces( $search );
			$results = $this->completionSearchBackendOverfetch( $search );
			if ( $results->getSize() < $this->limit + 1 ) {
				$services = MediaWikiServices::getInstance();
				$services->getLanguageConverterFactory()->getLanguageConverter( $services->getContentLanguage() )
					->autoConvertToAllVariants( $search );
			}
			return $this->processCompletionResults( $search, $results );
		}
		protected function processCompletionResults( $search, SearchSuggestionSet $suggestions ) {
			MediaWikiServices::getInstance()->getLinkBatchFactory();
			throw new \LogicException( 'Core SQL completion postprocessing was not replaced.' );
		}
		public function defaultPrefixSearch( $search ) {
			if ( trim( $search ) === '' ) { return []; }
			return $this->simplePrefixSearch( $this->normalizeNamespaces( $search ) );
		}
		protected function simplePrefixSearch( $search ) {
			MediaWikiServices::getInstance()->getConnectionProvider();
			throw new \LogicException( 'Core SQL prefix lookup was not replaced.' );
		}
		public function extractTitles( SearchSuggestionSet $set ): array {
			return $set->map( static fn ( $suggestion ) => $suggestion->getSuggestedTitle() );
		}
	}

	class SearchResult {}

	class SearchResultSet implements \Countable, \IteratorAggregate {
		protected array $results = [];
		public function __construct( private bool $containedSyntax = false, private bool $hasMoreResults = false ) {}
		public function count(): int { return count( $this->results ); }
		public function extractResults(): array { return $this->results; }
		public function extractTitles(): array { return array_map( static fn ( $r ) => $r->getTitle(), $this->results ); }
		public function hasMoreResults(): bool { return $this->hasMoreResults; }
		public function searchContainedSyntax(): bool { return $this->containedSyntax; }
		public function shrink( int $limit ): void {
			if ( count( $this->results ) > $limit ) { $this->results = array_slice( $this->results, 0, $limit ); $this->hasMoreResults = true; }
		}
		public function getIterator(): \ArrayIterator { return new \ArrayIterator( $this->results ); }
	}

	class SearchSuggestion {
		private string $url;
		public function __construct( private $score, private $text = null, private ?Title $suggestedTitle = null,
			private $suggestedTitleID = null
		) {
			$this->url = MediaWikiServices::getInstance()->getUrlUtils()->expand( $suggestedTitle->getFullURL() );
		}
		public function getText() { return $this->text; }
		public function getScore() { return $this->score; }
		public function getSuggestedTitle(): ?Title { return $this->suggestedTitle; }
		public function getSuggestedTitleID() { return $this->suggestedTitleID; }
		public function getURL(): string { return $this->url; }
	}

	class SearchSuggestionSet {
		public function __construct( private array $suggestions, private bool $hasMoreResults = false ) {}
		public function getSuggestions(): array { return $this->suggestions; }
		public function getSize(): int { return count( $this->suggestions ); }
		public function map( callable $callback ): array { return array_map( $callback, $this->suggestions ); }
		public function hasMoreResults(): bool { return $this->hasMoreResults; }
		public function shrink( int $limit ): void {
			if ( count( $this->suggestions ) > $limit ) {
				$this->suggestions = array_slice( $this->suggestions, 0, $limit ); $this->hasMoreResults = true;
			}
		}
	}
}

namespace MediaWiki\Status {
	class Status {
		private function __construct( private string $key, private array $parameters ) {}
		public static function newFatal( string $key, ...$parameters ): self { return new self( $key, $parameters ); }
		public function isGood(): bool { return false; }
		public function getValue(): null { return null; }
		public function getErrors(): array {
			return [ [ 'type' => 'error', 'message' => $this->key, 'params' => $this->parameters ] ];
		}
	}
}

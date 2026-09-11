<?php

use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use FrauxSearch\RankedSearch;
use FrauxSearch\SearchQuery;
use FrauxSearch\SnippetFormatter;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 4 );
require_once $IP . '/maintenance/Maintenance.php';

class FrauxSearchBehaviorCheck extends Maintenance {
	private ?int $pendingTask = null;
	private bool $unknownSubmission = false;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check search behavior in a temporary Meilisearch index.' );
		$this->addOption( 'execute', 'Create and remove an isolated test index.', false, false );
	}

	public function execute() {
		if ( !$this->hasOption( 'execute' ) ) {
			$this->fatalError( 'Use --execute on a local test wiki.' );
		}
		$config = $this->getConfig();
		$base = $config->get( 'FrauxSearchIndex' );
		if ( !is_string( $base ) || preg_match( '/^[A-Za-z0-9_-]+$/D', $base ) !== 1 ) {
			$this->fatalError( 'Invalid configured Meilisearch index base.' );
		}
		$index = $base . '_search_test_' . bin2hex( random_bytes( 6 ) );
		$client = new MeilisearchClient(
			(string)$config->get( 'FrauxSearchUrl' ), (string)$config->get( 'FrauxSearchApiKey' ),
			$index, 30, (string)$config->get( 'FrauxSearchTaskApiKey' )
		);
		$this->output( "Search behavior index: $index\n" );
		$created = false;
		$failure = null;
		try {
			$this->mutate( $client, $client->createIndexStrict( ... ) );
			$created = true;
			$this->mutate( $client, $client->configureIndex( ... ) );
			$this->mutate( $client, fn () => $client->replaceDocuments( $this->documents() ) );
			$this->check( $this->ids( $client, 'n' ), [ 4, 2, 3, 1, 7, 5, 6 ], 'short prefix popularity' );
			$this->check( $this->ids( $client, 'narmaya' ), [ 1, 2, 3, 7 ], 'exact title before variants' );
			$this->check( $this->ids( $client, 'naru' )[0], 1, 'exact alias before variant aliases' );
			$this->check( $this->ids( $client, 'atk' )[0], 10, 'exact alias before title prefixes' );
			$this->check( $this->ids( $client, 'Narmaya/Lore' )[0], 7, 'exact utility title remains discoverable' );
			$all = $this->ids( $client, 'narmaya' );
			$paged = [];
			for ( $offset = 0; $offset < count( $all ); $offset += 2 ) {
				$paged = array_merge( $paged, $this->ids( $client, 'narmaya', [ 'limit' => 2, 'offset' => $offset ] ) );
			}
			$this->check( $paged, $all, 'pagination across exact and ordinary matches' );
			$this->check( $this->ids( $client, 'NARMAYA' ), $all, 'case-insensitive exact title' );
			$this->check( $this->ids( $client, 'narmaya', [ 'filter' => 'namespace = 6' ],
				[ 'narmaya', 'File:narmaya' ] ), [ 8 ], 'namespace scope' );

			$options = [ 'filter' => 'namespace = 100', 'attributesToSearchOn' => [ 'text' ] ];
			$this->checkSorted( $this->ids( $client, 'sky sword', $options ), [ 101, 103, 105, 106 ], 'all terms required' );
			$this->check( $this->ids( $client, '+sky +swor', $options ),
				$this->ids( $client, 'sky swor', $options ), 'required terms keep prefix matching' );
			$this->check( $this->ids( $client, '+swoord', $options ),
				$this->ids( $client, 'swoord', $options ), 'required terms keep typo matching' );
			$this->checkSorted( $this->ids( $client, '"sky blue"', $options ), [ 101, 106 ], 'phrase adjacency' );
			$this->check( $this->ids( $client, '"sky blu"', $options ), [], 'phrases do not prefix-match' );
			$this->checkSorted( $this->ids( $client, 'sky -blue', $options ), [ 102, 104, 105 ], 'negative exact word' );
			$this->checkSorted( $this->ids( $client, 'sky -"blue sword"', $options ),
				[ 102, 103, 104, 105 ], 'negative phrase' );
			$this->check( $this->ids( $client, 'sky -blue-sword', $options ),
				$this->ids( $client, 'sky -"blue sword"', $options ), 'negative compound normalization' );
			$this->check( $this->ids( $client, 'one two three four five six seven eight nine ten -banned', $options ),
				[ 108 ], 'trailing exclusion survives native positive-term limit' );
			$snippet = RankedSearch::search( $client, [
				'q' => 'hostile', 'matchingStrategy' => 'all', 'attributesToSearchOn' => [ 'text' ],
				'attributesToRetrieve' => [ 'id', 'title' ], 'attributesToHighlight' => [ 'text' ],
				'attributesToCrop' => [ 'text' ], 'cropLength' => 50,
				'highlightPreTag' => '__open__', 'highlightPostTag' => '__close__', 'limit' => 1,
			] );
			$html = SnippetFormatter::format( $snippet['hits'][0]['_formatted']['text'] ?? '', [ '__open__', '__close__' ] );
			$this->check( str_contains( $html, '<script>' ), false, 'snippet HTML escaping' );
			$this->check( str_contains( $html, '&lt;script&gt;' ), true, 'cropped text returned outside projection' );
			$this->check( str_contains( $html, '<span class="searchmatch">hostile</span>' ), true, 'snippet highlighting' );
		} catch ( Throwable $error ) {
			$failure = $error;
		}
		if ( $this->unknownSubmission || $this->pendingTask !== null ) {
			$this->output( "Preserving $index: unresolved task " . ( $this->pendingTask ?? 'UID unknown' ) . ".\n" );
		} elseif ( $created ) {
			try {
				$this->mutate( $client, $client->deleteIndex( ... ) );
				$this->check( $client->indexExists(), false, 'isolated index cleanup' );
			} catch ( Throwable $error ) {
				$this->output( "Cleanup incomplete for $index: " . $error->getMessage() . "\n" );
				$failure ??= $error;
			}
		}
		if ( $failure !== null ) { throw $failure; }
		$this->output( "Search behavior checks passed.\n" );
	}

	private function mutate( MeilisearchClient $client, Closure $submit ): void {
		$this->unknownSubmission = true;
		$task = $submit();
		if ( !is_int( $task ) || $task < 0 ) {
			throw new RuntimeException( 'Mutation returned no task UID; preserving test resources.' );
		}
		$this->pendingTask = $task;
		$this->unknownSubmission = false;
		$this->output( "Task $task accepted.\n" );
		try {
			$client->waitForTask( $task );
		} catch ( MeilisearchException $error ) {
			if ( $error->getCompletedTaskId() === $task ) { $this->pendingTask = null; }
			throw $error;
		}
		$this->pendingTask = null;
	}

	private function ids( MeilisearchClient $client, string $term, array $options = [], ?array $exactTerms = null ): array {
		$parsed = SearchQuery::parse( $term );
		$query = array_replace( [ 'q' => $parsed['q'], 'matchingStrategy' => 'all',
			'attributesToSearchOn' => [ 'title', 'redirects' ], 'attributesToRetrieve' => [ 'id' ],
			'filter' => 'namespace = 0', 'limit' => 20, 'offset' => 0 ], $options );
		$result = RankedSearch::search( $client, $query, $parsed['hasSyntax'] ? [] : ( $exactTerms ?? [ $term ] ) );
		return array_column( $result['hits'], 'id' );
	}

	private function check( mixed $actual, mixed $expected, string $name ): void {
		if ( $actual !== $expected ) {
			throw new RuntimeException( "$name: expected " . json_encode( $expected ) . ', got ' . json_encode( $actual ) );
		}
		$this->output( "PASS $name\n" );
	}

	private function checkSorted( array $actual, array $expected, string $name ): void {
		sort( $actual );
		sort( $expected );
		$this->check( $actual, $expected, $name );
	}

	private function documents(): array {
		$rows = [
			[ 1, 'Narmaya', [ 'Glasswing Waltzer', 'Naru', 'Narumeia' ], 10, 0, '' ],
			[ 2, 'Narmaya (Grand)', [ 'Grand Naru' ], 100, 0, '' ],
			[ 3, 'Narmaya (Valentine)', [ 'Naru (Valentine)' ], 90, 0, '' ],
			[ 4, 'Nier', [], 200, 0, '' ],
			[ 5, 'N Summons List', [], 1, 0, '' ],
			[ 6, 'F.A.N.G', [], 0, 0, '' ],
			[ 7, 'Narmaya/Lore', [], 2, 0, '' ],
			[ 8, 'File:Narmaya', [], 999, 6, '' ],
			[ 10, 'Damage Formula', [ 'ATK' ], 10, 0, '' ],
			[ 11, 'ATK Up', [], 100, 0, '' ],
			[ 101, 'One', [], 1, 100, 'sky blue sword' ],
			[ 102, 'Two', [], 1, 100, 'sky silver blade' ],
			[ 103, 'Three', [], 1, 100, 'sword blue sky' ],
			[ 104, 'Four', [], 1, 100, 'sky blueblade' ],
			[ 105, 'Five', [], 1, 100, 'sky green sword' ],
			[ 106, 'Six', [], 1, 100, 'sky blue sword shield' ],
			[ 107, 'Seven', [], 1, 100, 'one two three four five six seven eight nine ten banned' ],
			[ 108, 'Eight', [], 1, 100, 'one two three four five six seven eight nine ten safe' ],
			[ 109, 'Nine', [], 1, 100, 'hostile <script>alert(1)</script> text' ],
		];
		return array_map( static fn ( array $row ) => [ 'id' => $row[0], 'title' => $row[1],
			'redirects' => $row[2], 'incoming_links' => $row[3], 'namespace' => $row[4],
			'text' => $row[5], 'boost' => 100 ], $rows );
	}
}

$maintClass = FrauxSearchBehaviorCheck::class;
require_once RUN_MAINTENANCE_IF_MAIN;

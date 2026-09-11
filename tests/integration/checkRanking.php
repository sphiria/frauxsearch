<?php

use FrauxSearch\RankingComparator;

function frauxSearchRankingRequest( array $case, int $limit ): array {
	if ( $case['type'] === 'completion' ) {
		return [
			'action' => 'opensearch',
			'format' => 'json',
			'formatversion' => 2,
			'search' => $case['query'],
			'namespace' => $case['namespace'],
			'limit' => $limit,
		];
	}
	return [
		'action' => 'query',
		'format' => 'json',
		'list' => 'search',
		'srwhat' => 'text',
		'srsearch' => $case['query'],
		'srnamespace' => $case['namespace'],
		'srlimit' => $limit,
	];
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) !== __FILE__ ) { return; }

require_once dirname( __DIR__, 2 ) . '/src/RankingComparator.php';

$fixture = json_decode(
	file_get_contents( dirname( __DIR__ ) . '/fixtures/ranking.json' ),
	true,
	512,
	JSON_THROW_ON_ERROR
);
$baseUrl = $argv[1] ?? 'http://127.0.0.1:8080/api.php';
$failed = false;
foreach ( $fixture['cases'] as $case ) {
	$params = frauxSearchRankingRequest( $case, $fixture['limit'] );
	$handle = curl_init( $baseUrl . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
	curl_setopt_array( $handle, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 15,
	] );
	$response = curl_exec( $handle );
	if ( $response === false || curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) !== 200 ) {
		fwrite( STDERR, $case['id'] . ': API request failed: ' . curl_error( $handle ) . "\n" );
		$failed = true;
		continue;
	}
	$data = json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
	$actual = $case['type'] === 'completion'
		? ( $data[1] ?? [] )
		: array_column( $data['query']['search'] ?? [], 'title' );
	$baseline = RankingComparator::compare( $case['frauxsearch_baseline'], $actual );
	$production = RankingComparator::compare( $case['production'], $actual );
	printf(
		"%s baseline=%s production_top=%s production_positions=%d/%d production_overlap=%d/%d\n",
		$case['id'],
		$baseline['exact'] ? 'exact' : 'changed',
		$production['top_match'] ? 'match' : 'different',
		$production['position_matches'],
		$production['target_count'],
		$production['overlap'],
		$production['target_count']
	);
	if ( !$baseline['exact'] ) {
		fwrite( STDERR, $case['id'] . ': expected ' . json_encode(
			$case['frauxsearch_baseline'],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n" );
		fwrite( STDERR, $case['id'] . ': actual   ' . json_encode(
			$actual,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n" );
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );

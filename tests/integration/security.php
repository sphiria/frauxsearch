<?php

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

require_once dirname( __DIR__, 2 ) . '/src/MeilisearchException.php';
require_once dirname( __DIR__, 2 ) . '/src/MeilisearchClient.php';
$options = getopt( '', [ 'url:', 'key-file:', 'task-key-file:', 'base:', 'execute' ] );
if ( !is_array( $options ) ) {
	throw new InvalidArgumentException( 'Unable to parse authentication probe options.' );
}
foreach ( [ 'url', 'key-file', 'task-key-file', 'base', 'execute' ] as $required ) {
	if ( !array_key_exists( $required, $options ) ) {
		fwrite( STDERR, "Required: --url URL --key-file FILE --task-key-file FILE --base ISOLATED_BASE --execute\n" ); exit( 2 );
	}
}
foreach ( [ 'url', 'key-file', 'task-key-file', 'base' ] as $name ) {
	if ( !is_string( $options[$name] ) || $options[$name] === '' ) {
		throw new InvalidArgumentException( "Expected exactly one nonempty --$name value." );
	}
}
if ( $options['execute'] !== false ) {
	throw new InvalidArgumentException( 'Expected one --execute flag without a value.' );
}
$url = rtrim( $options['url'], '/' );
$urlParts = parse_url( $url );
if ( !is_array( $urlParts ) || !in_array( $urlParts['scheme'] ?? null, [ 'http', 'https' ], true )
	|| !isset( $urlParts['host'] ) || filter_var( $url, FILTER_VALIDATE_URL ) === false
	|| isset( $urlParts['user'] ) || isset( $urlParts['pass'] ) || isset( $urlParts['query'] ) || isset( $urlParts['fragment'] )
) {
	throw new InvalidArgumentException( 'Expected an HTTP(S) base URL without credentials, query, or fragment.' );
}
$base = $options['base'];
if ( !preg_match( '/^frauxsearch_auth_[a-z0-9]+$/D', $base ) ) {
	throw new InvalidArgumentException( 'Expected a unique isolated authentication test base.' );
}
$readKey = static function ( string $path ): string {
	if ( !is_file( $path ) || !is_readable( $path ) ) {
		throw new InvalidArgumentException( 'Key file must be a readable regular file.' );
	}
	$contents = file_get_contents( $path );
	if ( $contents === false ) { throw new RuntimeException( 'Unable to read key file.' ); }
	$key = trim( $contents );
	if ( $key === '' || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
		throw new InvalidArgumentException( 'Key must be a nonempty token without embedded whitespace.' );
	}
	return $key;
};
$key = $readKey( $options['key-file'] );
$taskKey = $readKey( $options['task-key-file'] );
$client = new FrauxSearch\MeilisearchClient( $url, $key, $base, 10, $taskKey );
$requestStatus = static function (
	string $path, string $credential = '', string $method = 'GET', ?array $body = null
) use ( $url ): int {
	$curl = curl_init( $url . $path );
	if ( $curl === false ) { throw new RuntimeException( 'Unable to initialize authentication probe transport.' ); }
	$headers = [ 'Content-Type: application/json' ];
	if ( $credential !== '' ) { $headers[] = 'Authorization: Bearer ' . $credential; }
	$curlOptions = [ CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
		CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method ];
	if ( $body !== null ) { $curlOptions[CURLOPT_POSTFIELDS] = json_encode( $body, JSON_THROW_ON_ERROR ); }
	if ( !curl_setopt_array( $curl, $curlOptions ) ) {
		throw new RuntimeException( 'Unable to configure authentication probe transport.' );
	}
	if ( curl_exec( $curl ) === false ) { throw new RuntimeException( 'Authentication probe transport failed.' ); }
	return curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
};
$check = static function ( bool $condition, string $message ): void {
	if ( !$condition ) { throw new RuntimeException( $message ); }
};
$check( $requestStatus( '/health' ) === 200, 'Public process health failed.' );
$check( $requestStatus( '/indexes/' . $base . '/documents' ) === 401, 'Unauthenticated documents were not rejected.' );
$check( $requestStatus( '/keys', $key ) === 403, 'Application key can administer credentials.' );
$check( $requestStatus( '/indexes/unrelated_private_index/documents', $key ) === 403, 'Application key can access another index.' );
$created = [];
$failure = null;
$cleanupFailures = [];
try {
	$indexes = [ $base, $base . '_completion', $base . '_generation' ];
	foreach ( $indexes as $index ) {
		$check( !$client->withIndex( $index )->indexExists(), 'Test index already exists: ' . $index );
	}
	foreach ( $indexes as $index ) {
		$indexClient = $client->withIndex( $index );
		$created[] = $index;
		$indexClient->waitForTask( $indexClient->createIndex() );
		$indexClient->waitForTask( $indexClient->configureIndex() );
		$check( $indexClient->getIndexSettings()['rankingRules'] === FrauxSearch\MeilisearchClient::expectedIndexSettings()['rankingRules'],
			'Application key cannot configure/read settings.' );
		$indexClient->waitForTask( $indexClient->replaceDocuments( [ [ 'id' => 1, 'title' => 'Auth probe',
			'text' => $index, 'boost' => 100, 'incoming_links' => 0, 'redirects' => [] ] ] ) );
		$check( $indexClient->getDocument( 1 )['text'] === $index, 'Application document read/write failed.' );
		$check( count( $indexClient->search( [ 'q' => 'Auth probe' ] )['hits'] ) === 1, 'Application search failed.' );
	}
	foreach ( [ '/keys', '/indexes/' . $base . '/documents', '/indexes/' . $base . '/settings' ] as $path ) {
		$check( $requestStatus( $path, $taskKey ) === 403, 'Task reader can access ' . $path );
	}
	$check( $requestStatus( '/indexes/' . $base . '/search', $taskKey, 'POST', [ 'q' => 'Auth probe' ] ) === 403,
		'Task reader can search documents.' );
	$check( $requestStatus( '/indexes/' . $base . '/documents', $taskKey, 'POST', [ [ 'id' => 2 ] ] ) === 403,
		'Task reader can write documents.' );
	$swapUid = $client->swapWith( $base . '_generation' );
	$check( $swapUid !== null, 'Swap returned no task UID.' );
	$check( $requestStatus( '/tasks/' . $swapUid, $key ) === 404, 'Expected scoped key to hide the global swap task.' );
	$check( $requestStatus( '/tasks/' . $swapUid, $taskKey ) === 200, 'Task reader cannot observe the accepted swap.' );
	$client->waitForTask( $swapUid );
	$check( $client->getDocument( 1 )['text'] === $base . '_generation', 'Scoped generation swap failed.' );
	$client->waitForTask( $client->deleteDocument( 1 ) );
	$check( $client->getDocument( 1 ) === null, 'Scoped deletion failed.' );
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	foreach ( array_reverse( $created ) as $index ) {
		$indexClient = $client->withIndex( $index );
		try {
			$indexClient->waitForTask( $indexClient->deleteIndex() );
		} catch ( Throwable $cleanupError ) {
			$cleanupFailures[] = $index;
			fwrite( STDERR, "Cleanup failed for $index: " . $cleanupError->getMessage() . "\n" );
		}
	}
}
if ( $failure !== null ) { throw $failure; }
if ( $cleanupFailures !== [] ) {
	throw new RuntimeException( 'Authentication probe cleanup failed for: ' . implode( ', ', $cleanupFailures ) );
}
echo "PASS production authentication, denied anonymous/admin/unrelated access, scoped index operations, separate task reader, verified swap and cleanup.\n";

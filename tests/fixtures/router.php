<?php

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
header( 'Content-Type: application/json' );

if ( $path === '/fixture-ready' ) {
	echo json_encode( [ 'fixture' => 'frauxsearch-http' ] );
	return;
}

if ( preg_match( '#^/fixture-response/([0-9]{3})/([^/]+)/#', $path, $match ) ) {
	http_response_code( (int)$match[1] );
	echo base64_decode( rawurldecode( $match[2] ), true );
	return;
}

if ( str_starts_with( $path, '/strict-create/' ) ) {
	if ( $path === '/strict-create/indexes' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ( $body['primaryKey'] ?? null ) !== 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ) {
			http_response_code( 400 );
			echo json_encode( [ 'code' => 'wrong_primary_key' ] );
		} elseif ( ( $body['uid'] ?? null ) === 'duplicate-sync' ) {
			http_response_code( 409 );
			echo json_encode( [ 'code' => 'index_already_exists' ] );
		} else {
			echo json_encode( [ 'taskUid' => ( $body['uid'] ?? null ) === 'duplicate-async' ? 61 : 60 ] );
		}
	} elseif ( $path === '/strict-create/tasks/61' ) {
		echo json_encode( [ 'uid' => 61, 'status' => 'failed',
			'error' => [ 'code' => 'index_already_exists', 'message' => 'Guard already exists' ] ] );
	} elseif ( $path === '/strict-create/tasks/60' ) {
		echo json_encode( [ 'uid' => 60, 'status' => 'succeeded' ] );
	} else {
		http_response_code( 400 );
		echo json_encode( [ 'code' => 'unexpected_guard_precheck' ] );
	}
	return;
}

if ( $path === '/task-list/tasks' ) {
	$tasks = [
		[ 'uid' => 12, 'type' => 'indexSwap', 'indexUid' => null, 'status' => 'processing',
			'details' => [ 'swaps' => [ [ 'indexes' => [ 'test', 'generation' ] ] ] ] ],
		[ 'uid' => 9, 'type' => 'documentAdditionOrUpdate', 'indexUid' => 'test', 'status' => 'enqueued',
			'details' => [ 'receivedDocuments' => 1, 'indexedDocuments' => null ] ],
		[ 'uid' => 3, 'type' => 'settingsUpdate', 'indexUid' => 'test', 'status' => 'enqueued',
			'details' => (object)[] ],
	];
	$tasks = array_values( array_filter( $tasks, static function ( array $task ): bool {
		if ( isset( $_GET['from'] ) && $task['uid'] > (int)$_GET['from'] ) { return false; }
		foreach ( [ 'statuses' => 'status', 'types' => 'type', 'indexUids' => 'indexUid', 'uids' => 'uid' ] as $filter => $field ) {
			if ( isset( $_GET[$filter] ) && !in_array( (string)$task[$field], explode( ',', $_GET[$filter] ), true ) ) {
				return false;
			}
		}
		return true;
	} ) );
	$limit = (int)( $_GET['limit'] ?? 20 );
	echo json_encode( [ 'results' => array_slice( $tasks, 0, $limit ), 'limit' => $limit,
		'total' => count( $tasks ), 'from' => isset( $_GET['from'] ) ? (int)$_GET['from'] : ( $tasks[0]['uid'] ?? null ),
		'next' => $tasks[$limit]['uid'] ?? null, 'query' => $_GET ] );
	return;
}

if ( $path === '/indexes/protocol-truncated_coordination' && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	echo json_encode( [ 'uid' => 'protocol-truncated_coordination',
		'primaryKey' => 'epoch_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ] );
	return;
}

if ( str_starts_with( $path, '/credential-probe/' ) ) {
	$credential = match ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) {
		'Bearer application-test-key' => 'application',
		'Bearer task-test-key' => 'task',
		'' => 'none',
		default => 'unexpected',
	};
	echo json_encode( [ 'credential' => $credential, 'uid' => 12, 'status' => 'succeeded',
		'taskUid' => 12, 'hits' => [], 'results' => [], 'total' => 0,
		'limit' => (int)( $_GET['limit'] ?? 20 ), 'from' => null, 'next' => null ] );
	return;
}

if ( preg_match( '#^/(?:protocol/|indexes/protocol-)([^/]+)#', $path, $match ) ) {
	$responses = [
		'empty' => [ 200, '' ],
		'whitespace' => [ 200, '   ' ],
		'html' => [ 200, '<html>upstream unavailable</html>' ],
		'truncated' => [ 200, '{"results":[' ],
		'null' => [ 200, 'null' ],
		'boolean' => [ 200, 'true' ],
		'number' => [ 200, '42' ],
		'string' => [ 200, '"unexpected"' ],
		'list' => [ 200, '[]' ],
		'envelope' => [ 200, '{"unexpected":true}' ],
		'list-results' => [ 200, '{"results":{"first":{"id":1}},"total":1}' ],
		'object-results' => [ 200, '{"results":{},"total":0}' ],
		'object-hits' => [ 200, '{"hits":{}}' ],
		'array-document' => [ 200, '{"results":[[]],"total":1}' ],
		'empty-document' => [ 200, '{"results":[{}],"total":1}' ],
		'scalar-document' => [ 200, '{"results":["unexpected"],"total":1}' ],
		'missing-total' => [ 200, '{"results":[]}' ],
		'bad-total' => [ 200, '{"results":[],"total":"0"}' ],
		'truncated-list' => [ 200, '{"results":[],"total":1}' ],
		'excess-list' => [ 200, '{"results":[{"id":1},{"id":2}],"total":1}' ],
		'wrong-offset' => [ 200, '{"results":[],"total":0,"offset":1}' ],
		'wrong-limit' => [ 200, '{"results":[],"total":0,"limit":1}' ],
		'bad-hits' => [ 200, '{"hits":["unexpected"]}' ],
		'bad-redirects' => [ 200, '{"results":[{"id":1,"redirects":"Exact Redirect"}],"total":1}' ],
		'wrong-document' => [ 200, '{"id":2}' ],
		'string-id' => [ 200, '{"id":"1"}' ],
		'padded-id' => [ 200, '{"id":"01"}' ],
		'bad-id' => [ 200, '{"id":"1x"}' ],
		'zero-id' => [ 200, '{"id":0}' ],
		'negative-id' => [ 200, '{"id":-1}' ],
		'string-alias-id' => [ 200, '{"results":[{"id":"1","redirects":["Exact Redirect"]}],"total":1}' ],
		'padded-alias-id' => [ 200, '{"results":[{"id":"01","redirects":["Exact Redirect"]}],"total":1}' ],
		'missing-alias-id' => [ 200, '{"results":[{"redirects":["Different Redirect"]}],"total":1}' ],
		'error-null' => [ 503, 'null' ],
		'error-string' => [ 503, '"upstream unavailable"' ],
		'error-list' => [ 503, '["upstream unavailable"]' ],
		'error-nested' => [ 503, '{"code":{},"message":[]}' ],
		'error-empty' => [ 503, '' ],
		'error-permanent' => [ 400, '{"code":[],"message":{}}' ],
		'error-doc-message' => [ 503, '{"message":"document_not_found from upstream","code":"internal"}' ],
		'error-index-message' => [ 503, '{"message":"index_not_found from upstream","code":"internal"}' ],
		'error-doc-status' => [ 503, '{"code":"document_not_found"}' ],
		'error-index-status' => [ 503, '{"code":"index_not_found"}' ],
	];
	[ $status, $body ] = $responses[$match[1]];
	http_response_code( $status );
	echo $body;
	return;
}

if ( $path === '/indexes/test/settings' && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	echo json_encode( \FrauxSearch\MeilisearchClient::expectedIndexSettings() );
	return;
}

if ( str_starts_with( $path, '/indexes/create-' ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	http_response_code( 404 );
	echo json_encode( [ 'code' => 'index_not_found' ] );
	return;
}
if ( $path === '/indexes' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$body = json_decode( file_get_contents( 'php://input' ), true );
	http_response_code( $body['uid'] === 'create-existing' ? 409 : 503 );
	echo json_encode( [ 'code' => 'index_already_exists' ] );
	return;
}

if ( $path === '/indexes/test/settings' && $_SERVER['REQUEST_METHOD'] === 'PATCH' ) {
	$settings = json_decode( file_get_contents( 'php://input' ), true );
	if ( !in_array( 'text', $settings['displayedAttributes'] ?? [], true )
		|| !in_array( 'redirects', $settings['filterableAttributes'] ?? [], true )
	) {
		http_response_code( 400 );
		echo json_encode( [ 'message' => 'Required snippet/redirect settings are missing' ] );
		return;
	}
	echo json_encode( [ 'taskUid' => 12 ] );
	return;
}

if ( $path === '/indexes/malformed/documents' ) {
	echo json_encode( [ 'accepted' => true ] );
	return;
}
if ( $path === '/indexes/test/documents/delete-batch' ) {
	$ids = json_decode( file_get_contents( 'php://input' ), true );
	if ( $ids !== [ 2, 5 ] ) {
		http_response_code( 400 );
		echo json_encode( [ 'message' => 'Expected a JSON list of integer IDs' ] );
		return;
	}
	echo json_encode( [ 'taskUid' => 12 ] );
	return;
}

if ( $path === '/indexes/test/documents' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	echo json_encode( [ 'taskUid' => 12 ] );
	return;
}
if ( $path === '/indexes/test/documents' && $_SERVER['REQUEST_METHOD'] === 'PUT' ) {
	echo json_encode( [ 'taskUid' => 13 ] );
	return;
}
if ( $path === '/indexes/test/documents/fetch' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$body = json_decode( file_get_contents( 'php://input' ), true );
	$results = array_map( static fn ( int $id ) => [
		'id' => $id,
		'revision_id' => $id * 10,
		'document_hash' => 'hash-' . $id,
	], $body['ids'] ?? [] );
	if ( !array_key_exists( 'fields', $body ) ) {
		$results = array_map( static fn ( array $doc ) => $doc + [ 'extra_stored_field' => 'visible' ], $results );
	}
	echo json_encode( [
		'results' => $results,
		'offset' => 0,
		'limit' => (int)( $body['limit'] ?? 0 ),
		'total' => count( $results ),
	] );
	return;
}
if ( $path === '/indexes/test/documents' && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	if ( !array_key_exists( 'fields', $_GET ) ) {
		echo json_encode( [ 'results' => [ [ 'id' => 7, 'extra_stored_field' => 'visible' ] ], 'total' => 1 ] );
		return;
	}
	if ( str_starts_with( $_GET['filter'] ?? '', 'redirects = ' ) ) {
		$title = json_decode( substr( $_GET['filter'], strlen( 'redirects = ' ) ), true, flags: JSON_THROW_ON_ERROR );
		$offset = (int)( $_GET['offset'] ?? 0 );
		echo json_encode( [
			'results' => $offset === 0 ? [
				[ 'id' => 1, 'redirects' => [ $title ] ],
				[ 'id' => 2, 'redirects' => [ $title . ' Extra' ] ],
				[ 'id' => 1, 'redirects' => [ $title ] ],
			] : [ [ 'id' => 101, 'redirects' => [ $title ] ] ],
			'total' => 4,
		] );
		return;
	}
	echo json_encode( [
		'results' => [ [ 'id' => 7 ], [ 'id' => 8 ] ],
		'offset' => (int)( $_GET['offset'] ?? 0 ),
		'limit' => (int)( $_GET['limit'] ?? 0 ),
		'total' => 12,
		'filter' => $_GET['filter'] ?? null,
	] );
	return;
}
if ( $path === '/swap-indexes' ) {
	echo json_encode( [ 'taskUid' => 14 ] );
	return;
}
if ( $path === '/indexes/test/search' ) {
	echo json_encode( [ 'hits' => [
		[ 'id' => 1, 'redirects' => [ 'Exact Redirect' ] ],
		[ 'id' => 2, 'redirects' => [ 'Exact Redirect Extra' ] ],
		[ 'id' => 1, 'redirects' => [ 'Exact Redirect' ] ],
	] ] );
	return;
}
if ( $path === '/tasks/12' || $path === '/tasks/13' || $path === '/tasks/14' ) {
	echo json_encode( [ 'uid' => (int)basename( $path ), 'status' => 'succeeded' ] );
	return;
}
if ( $path === '/tasks/50' ) {
	echo json_encode( [
		'uid' => 50,
		'status' => 'failed',
		'error' => [ 'message' => 'temporary failure', 'code' => 'internal' ],
	] );
	return;
}
if ( $path === '/tasks/51' ) {
	echo json_encode( [ 'uid' => 51, 'status' => 'canceled' ] );
	return;
}
if ( $path === '/tasks/52' ) {
	echo json_encode( [ 'uid' => 52, 'unexpected' => true ] );
	return;
}
if ( $path === '/tasks/53' ) {
	echo json_encode( [ 'uid' => 54, 'status' => 'failed' ] );
	return;
}
if ( $path === '/tasks/54' ) {
	echo json_encode( [ 'status' => 'succeeded' ] );
	return;
}
if ( $path === '/tasks/55' ) {
	echo json_encode( [ 'uid' => 55, 'status' => 'failed', 'error' => [ 'message' => [], 'code' => [] ] ] );
	return;
}
if ( $path === '/indexes/test' ) {
	echo json_encode( [ 'uid' => 'test' ] );
	return;
}
if ( $path === '/indexes/search' ) {
	echo json_encode( $_SERVER['REQUEST_METHOD'] === 'GET' ? [ 'uid' => 'search' ] : [ 'taskUid' => 12 ] );
	return;
}
if ( $path === '/indexes/missing' ) {
	http_response_code( 404 );
	echo json_encode( [ 'code' => 'index_not_found' ] );
	return;
}
if ( $path === '/indexes/test/documents/1' ) {
	echo json_encode( [ 'id' => 1, 'title' => 'Page 1' ] );
	return;
}
if ( $path === '/retryable' ) {
	http_response_code( 503 );
	echo json_encode( [ 'message' => 'unavailable', 'code' => 'http_503' ] );
	return;
}
if ( $path === '/plain-503' ) {
	http_response_code( 503 );
	header( 'Content-Type: text/plain' );
	echo 'upstream unavailable';
	return;
}

http_response_code( 404 );
echo json_encode( [ 'message' => 'not found', 'code' => 'document_not_found' ] );

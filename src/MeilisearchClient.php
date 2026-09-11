<?php

namespace FrauxSearch;

class MeilisearchClient {
	private ?\Closure $mutationHandler = null;
	private ?\Closure $taskPollHandler = null;
	private ?string $encodedRequestBody = null;
	private string $url;
	private string $apiKey;
	private string $taskApiKey;
	private string $index;
	private int $timeout;

	public function __construct( string $url, string $apiKey, string $index, int $timeout, string $taskApiKey = '' ) {
		$this->url = rtrim( $url, '/' );
		$this->apiKey = $apiKey;
		$this->taskApiKey = $taskApiKey;
		$this->index = $index;
		$this->timeout = $timeout;
	}

	public function getVersion(): string {
		$version = $this->request( 'GET', '/version' )['pkgVersion'] ?? null;
		if ( !is_string( $version ) || preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?$/D', $version ) !== 1 ) {
			throw $this->invalidResponse( 'version has no valid pkgVersion' );
		}
		return $version;
	}

	public function search( array $query ): array {
		$response = $this->request( 'POST', $this->indexPath( '/search' ), $query );
		$this->validateDocuments( $response['hits'] ?? null, 'search hits' );
		return $response;
	}

	/** @return int[] */
	public function findDocumentsWithRedirect( string $title ): array {
		$ids = [];
		$offset = 0;
		try {
			$filter = 'redirects = ' . json_encode( $title, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
		} catch ( \JsonException $e ) {
			throw new MeilisearchException( 'Meilisearch redirect filter cannot be encoded: ' . $e->getMessage() );
		}
		do {
			$response = $this->listDocuments( $offset, 100, [ 'id', 'redirects' ], $filter );
			$documents = $response['results'];
			foreach ( $documents as $hit ) {
				$id = self::pageId( $hit['id'] ?? null );
				if ( $id === null ) {
					throw $this->invalidResponse( 'redirect document has no valid page ID' );
				}
				if ( isset( $hit['redirects'] ) && !is_array( $hit['redirects'] ) ) {
					throw $this->invalidResponse( 'document redirects are not an array' );
				}
				if ( in_array( $title, $hit['redirects'] ?? [], true ) ) {
					$ids[] = $id;
				}
			}
			$offset += count( $documents );
		} while ( $documents !== [] && $offset < $response['total'] );
		return array_values( array_unique( $ids ) );
	}

	public function getDocument( int $id ): ?array {
		try {
			$document = $this->request( 'GET', $this->indexPath( '/documents/' . $id ) );
			if ( self::pageId( $document['id'] ?? null ) !== $id ) {
				throw $this->invalidResponse( 'document has no matching page ID' );
			}
			return $document;
		} catch ( MeilisearchException $e ) {
			if ( $e->getHttpStatus() === 404 && $e->getErrorCode() === 'document_not_found' ) {
				return null;
			}
			throw $e;
		}
	}

	public function fetchDocuments( array $ids, ?array $fields = null ): array {
		if ( $ids === [] ) {
			return [];
		}
		$body = [
			'ids' => array_values( array_map( 'intval', $ids ) ),
			'limit' => count( $ids ),
		];
		if ( $fields !== null ) { $body['fields'] = array_values( $fields ); }
		$response = $this->request( 'POST', $this->indexPath( '/documents/fetch' ), $body );
		$this->validateDocuments( $response['results'] ?? null, 'fetched documents' );
		$this->validateDocumentPagination( $response, 0, count( $ids ) );
		if ( count( $response['results'] ) !== $response['total'] ) {
			throw $this->invalidResponse( 'fetched documents are incomplete' );
		}
		return $response['results'];
	}

	public function listDocuments(
		int $offset,
		int $limit,
		?array $fields = null,
		?string $filter = null
	): array {
		$query = [
			'offset' => max( 0, $offset ),
			'limit' => max( 1, $limit ),
		];
		if ( $fields !== null ) { $query['fields'] = implode( ',', $fields ); }
		if ( $filter !== null && $filter !== '' ) {
			$query['filter'] = $filter;
		}
		$response = $this->request(
			'GET',
			$this->indexPath( '/documents?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) )
		);
		$this->validateDocuments( $response['results'] ?? null, 'listed documents' );
		$this->validateDocumentPagination( $response, $query['offset'], $query['limit'] );
		return $response;
	}

	public function withIndex( string $index ): self {
		$client = clone $this;
		$client->index = $index;
		return $client;
	}

	public function withMutationHandler( \Closure $handler ): self {
		$client = clone $this;
		$client->mutationHandler = $handler;
		return $client;
	}

	public function withTaskPollHandler( \Closure $handler ): self {
		$client = clone $this;
		$client->taskPollHandler = $handler;
		return $client;
	}

	public function getTask( int $taskUid ): array {
		$task = $this->request( 'GET', '/tasks/' . $taskUid );
		if ( ( $task['uid'] ?? null ) !== $taskUid ) {
			throw $this->invalidResponse( "task $taskUid has no matching UID" );
		}
		if ( !in_array( $task['status'] ?? null, [ 'enqueued', 'processing', 'succeeded', 'failed', 'canceled' ], true ) ) {
			throw $this->invalidResponse( "task $taskUid returned invalid status" );
		}
		return $task;
	}

	public function listTasks( array $filters, ?int $from, int $limit ): array {
		if ( $limit < 1 || ( $from !== null && $from < 0 ) ) {
			throw new \InvalidArgumentException( 'Invalid Meilisearch task pagination.' );
		}
		$query = [ 'limit' => $limit ];
		if ( $from !== null ) { $query['from'] = $from; }
		foreach ( $filters as $name => $values ) {
			if ( !in_array( $name, [ 'statuses', 'types', 'indexUids', 'uids' ], true )
				|| !is_array( $values ) || !array_is_list( $values ) || $values === []
			) { throw new \InvalidArgumentException( 'Unsupported Meilisearch task filter.' ); }
			foreach ( $values as $value ) {
				$valid = match ( $name ) {
					'statuses' => in_array( $value, self::taskStatuses(), true ),
					'types' => in_array( $value, self::taskTypes(), true ),
					'indexUids' => self::validIndexUid( $value ),
					'uids' => is_int( $value ) && $value >= 0,
				};
				if ( !$valid ) { throw new \InvalidArgumentException( 'Invalid Meilisearch task filter value.' ); }
			}
			$query[$name] = implode( ',', $values );
		}
		$response = $this->request( 'GET', '/tasks?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		$this->validateDocuments( $response['results'] ?? null, 'listed tasks' );
		if ( !isset( $response['total'] ) || !is_int( $response['total'] ) || $response['total'] < 0
			|| ( $response['limit'] ?? null ) !== $limit
		) { throw $this->invalidResponse( 'task list has invalid pagination' ); }
		foreach ( [ 'from', 'next' ] as $field ) {
			if ( !array_key_exists( $field, $response ) || ( $response[$field] !== null
				&& ( !is_int( $response[$field] ) || $response[$field] < 0 ) )
			) { throw $this->invalidResponse( 'task list has an invalid cursor' ); }
		}
		$count = count( $response['results'] );
		if ( $count > $limit || $count > $response['total']
			|| ( $from === null && $count < min( $limit, $response['total'] ) )
			|| ( $count === 0 && $response['next'] !== null )
			|| ( $response['next'] !== null && ( $count !== $limit || $response['total'] <= $count ) )
			|| ( $from === null && $response['next'] === null && $response['total'] > $count )
		) { throw $this->invalidResponse( 'task list has inconsistent pagination' ); }
		$previous = null;
		foreach ( $response['results'] as $task ) {
			$this->validateListedTask( $task );
			if ( ( $previous !== null && $task['uid'] >= $previous )
				|| ( $from !== null && $task['uid'] > $from )
				|| $response['from'] === null || $task['uid'] > $response['from']
			) { throw $this->invalidResponse( 'task list is outside its descending cursor' ); }
			foreach ( [ 'statuses' => 'status', 'types' => 'type', 'indexUids' => 'indexUid', 'uids' => 'uid' ]
				as $filter => $field
			) {
				if ( isset( $filters[$filter] ) && !in_array( $task[$field], $filters[$filter], true ) ) {
					throw $this->invalidResponse( 'task list does not match its filters' );
				}
			}
			$previous = $task['uid'];
		}
		if ( ( $from !== null && $response['from'] !== null && $response['from'] > $from )
			|| ( $response['next'] !== null && $response['next'] >= $previous )
		) { throw $this->invalidResponse( 'task list cursor does not advance' ); }
		return $response;
	}

	public function addDocuments( array $documents ): ?int {
		return $this->replaceDocuments( $documents );
	}

	public function replaceDocuments( array $documents ): ?int {
		if ( $documents === [] ) {
			return null;
		}
		$response = $this->request( 'POST', $this->indexPath( '/documents?primaryKey=id' ), $documents );
		return $this->taskUid( $response );
	}

	public function deleteDocument( int $id ): ?int {
		$response = $this->request( 'DELETE', $this->indexPath( '/documents/' . $id ) );
		return $this->taskUid( $response );
	}

	public function deleteDocuments( array $ids ): ?int {
		if ( $ids === [] ) {
			return null;
		}
		$response = $this->request( 'POST', $this->indexPath( '/documents/delete-batch' ),
			array_values( array_map( 'intval', $ids ) ) );
		return $this->taskUid( $response );
	}

	public function createIndex(): ?int {
		if ( $this->indexExists() ) { return null; }
		try {
			$response = $this->request( 'POST', '/indexes', [
				'uid' => $this->index,
				'primaryKey' => 'id',
			] );
			return $this->taskUid( $response );
		} catch ( MeilisearchException $e ) {
			if ( !in_array( $e->getHttpStatus(), [ 400, 409 ], true ) || $e->getErrorCode() !== 'index_already_exists' ) {
				throw $e;
			}
			return null;
		}
	}

	public function createIndexStrict( string $primaryKey = 'id' ): int {
		if ( $primaryKey === '' ) { throw new \InvalidArgumentException( 'An index primary key is required.' ); }
		return $this->taskUid( $this->request( 'POST', '/indexes', [
			'uid' => $this->index, 'primaryKey' => $primaryKey,
		] ) );
	}

	public function getIndexMetadata(): ?array {
		try {
			$index = $this->request( 'GET', $this->indexPath( '' ) );
			$this->validateIndexMetadata( $index );
			if ( $index['uid'] !== $this->index ) { throw $this->invalidResponse( 'index has no matching UID' ); }
			return $index;
		} catch ( MeilisearchException $e ) {
			if ( $e->getHttpStatus() === 404 && $e->getErrorCode() === 'index_not_found' ) { return null; }
			throw $e;
		}
	}

	public function listIndexes( int $offset, int $limit ): array {
		if ( $offset < 0 || $limit < 1 ) {
			throw new \InvalidArgumentException( 'Invalid Meilisearch index pagination.' );
		}
		$response = $this->request( 'GET', '/indexes?' . http_build_query( [
			'offset' => $offset, 'limit' => $limit,
		], '', '&', PHP_QUERY_RFC3986 ) );
		$this->validateDocuments( $response['results'] ?? null, 'listed indexes' );
		$this->validateDocumentPagination( $response, $offset, $limit );
		if ( ( $response['offset'] ?? null ) !== $offset || ( $response['limit'] ?? null ) !== $limit
			|| count( $response['results'] ) !== min( $limit, max( 0, $response['total'] - $offset ) )
		) { throw $this->invalidResponse( 'index list has inconsistent pagination' ); }
		$seen = [];
		foreach ( $response['results'] as $index ) {
			$this->validateIndexMetadata( $index );
			if ( isset( $seen[$index['uid']] ) ) { throw $this->invalidResponse( 'index list contains a duplicate UID' ); }
			$seen[$index['uid']] = true;
		}
		return $response;
	}

	public function indexExists(): bool {
		try {
			$index = $this->request( 'GET', $this->indexPath( '' ) );
			if ( ( $index['uid'] ?? null ) !== $this->index ) {
				throw $this->invalidResponse( 'index has no matching UID' );
			}
			return true;
		} catch ( MeilisearchException $e ) {
			if ( $e->getHttpStatus() === 404 && $e->getErrorCode() === 'index_not_found' ) { return false; }
			throw $e;
		}
	}

	public function configureIndex(): ?int {
		$response = $this->request( 'PATCH', $this->indexPath( '/settings' ), self::expectedIndexSettings() );
		return $this->taskUid( $response );
	}

	public function getIndexSettings(): array {
		return $this->request( 'GET', $this->indexPath( '/settings' ) );
	}

	public static function expectedIndexSettings(): array {
		return [
			'searchableAttributes' => [ 'title', 'redirects', 'text' ],
			'displayedAttributes' => [
				'id', 'revision_id', 'document_hash', 'title', 'text', 'redirects', 'namespace', 'boost',
				'incoming_links', 'outgoing_link_ids', 'timestamp', 'word_count', 'byte_size',
			],
			'filterableAttributes' => [ 'id', 'namespace', 'title', 'redirects' ],
			'sortableAttributes' => [ 'timestamp', 'incoming_links' ],
			'rankingRules' => [
				'words', 'typo', 'proximity', 'attribute', 'boost:desc', 'incoming_links:desc',
				'exactness', 'sort',
			],
			'typoTolerance' => [
				'enabled' => true,
				'minWordSizeForTypos' => [
					'oneTypo' => 4,
					'twoTypos' => 8,
				],
			],
		];
	}

	public function deleteAllDocuments(): ?int {
		$response = $this->request( 'DELETE', $this->indexPath( '/documents' ) );
		return $this->taskUid( $response );
	}

	public function swapWith( string $index ): ?int {
		return $this->swapIndexes( [ [ $this->index, $index ] ] );
	}

	public function swapIndexes( array $pairs ): ?int {
		$response = $this->request( 'POST', '/swap-indexes', array_map(
			static fn ( array $pair ) => [ 'indexes' => array_values( $pair ) ],
			$pairs
		) );
		return $this->taskUid( $response );
	}

	public function deleteIndex(): ?int {
		$response = $this->request( 'DELETE', $this->indexPath( '' ) );
		return $this->taskUid( $response );
	}

	public function waitForTask( ?int $taskUid, int $timeout = 300 ): void {
		if ( $taskUid === null ) {
			return;
		}
		$deadline = hrtime( true ) + max( 0, $timeout ) * 1_000_000_000;
		$delayMicroseconds = 10000;
		do {
			if ( $this->taskPollHandler !== null ) { ( $this->taskPollHandler )(); }
			$task = $this->getTask( $taskUid );
			if ( $this->taskPollHandler !== null ) { ( $this->taskPollHandler )(); }
			$status = $task['status'] ?? null;
			if ( $status === 'succeeded' ) {
				return;
			}
			if ( $status === 'failed' || $status === 'canceled' ) {
				$error = is_array( $task['error'] ?? null ) ? $task['error'] : [];
				$message = is_string( $error['message'] ?? null ) ? $error['message'] : 'unknown error';
				$code = is_string( $error['code'] ?? null ) ? $error['code'] : $status;
				throw new MeilisearchException(
					"Meilisearch task $taskUid failed ($code): $message",
					$status === 'canceled' || MeilisearchException::isRetryableCode( $code ),
					$taskUid,
					$code
				);
			}
			$remainingNanoseconds = $deadline - hrtime( true );
			if ( $remainingNanoseconds > 0 ) {
				usleep( (int)min( $delayMicroseconds, $remainingNanoseconds / 1000 ) );
				$delayMicroseconds = min( 100000, $delayMicroseconds * 2 );
			}
		} while ( hrtime( true ) < $deadline );
		throw new MeilisearchException( "Meilisearch task $taskUid timed out", true );
	}

	private function indexPath( string $suffix ): string {
		return '/indexes/' . rawurlencode( $this->index ) . $suffix;
	}

	private static function pageId( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) { return $value; }
		if ( is_string( $value ) && (int)$value > 0 && (string)(int)$value === $value ) { return (int)$value; }
		return null;
	}

	private function taskUid( array $response ): int {
		if ( !isset( $response['taskUid'] ) || !is_int( $response['taskUid'] ) || $response['taskUid'] < 0 ) {
			throw $this->invalidResponse( 'mutation returned no valid task UID' );
		}
		return $response['taskUid'];
	}

	private function invalidResponse( string $reason ): MeilisearchException {
		return new MeilisearchException( 'Meilisearch returned an invalid response: ' . $reason, true );
	}

	private static function validIndexUid( mixed $uid ): bool {
		return is_string( $uid ) && preg_match( '/^[a-zA-Z0-9_-]+$/D', $uid ) === 1;
	}

	private function validateIndexMetadata( array $index ): void {
		if ( !self::validIndexUid( $index['uid'] ?? null ) || !array_key_exists( 'primaryKey', $index )
			|| ( $index['primaryKey'] !== null && !is_string( $index['primaryKey'] ) )
		) { throw $this->invalidResponse( 'index metadata has an invalid UID or primary key' ); }
	}

	private static function taskStatuses(): array {
		return [ 'enqueued', 'processing', 'succeeded', 'failed', 'canceled' ];
	}

	private static function taskTypes(): array {
		return [ 'documentAdditionOrUpdate', 'documentEdition', 'documentDeletion', 'settingsUpdate',
			'indexCreation', 'indexUpdate', 'indexDeletion', 'indexSwap', 'taskCancelation', 'taskDeletion',
			'dumpCreation', 'snapshotCreation', 'export', 'upgradeDatabase', 'indexCompaction' ];
	}

	private function validateListedTask( array $task ): void {
		if ( !isset( $task['uid'] ) || !is_int( $task['uid'] ) || $task['uid'] < 0
			|| !in_array( $task['status'] ?? null, self::taskStatuses(), true )
			|| !in_array( $task['type'] ?? null, self::taskTypes(), true )
			|| !array_key_exists( 'indexUid', $task )
			|| ( $task['indexUid'] !== null && !self::validIndexUid( $task['indexUid'] ) )
			|| !array_key_exists( 'details', $task )
			|| ( $task['details'] !== null && ( !is_array( $task['details'] )
				|| ( $task['details'] !== [] && array_is_list( $task['details'] ) ) ) )
		) { throw $this->invalidResponse( 'listed task has invalid identity, status or details' ); }
		$global = in_array( $task['type'], [ 'indexSwap', 'taskCancelation', 'taskDeletion',
			'dumpCreation', 'snapshotCreation', 'export', 'upgradeDatabase' ], true );
		if ( $global !== ( $task['indexUid'] === null ) ) {
			throw $this->invalidResponse( 'listed task has an inconsistent index UID' );
		}
		if ( $task['type'] === 'indexSwap' ) {
			$swaps = $task['details']['swaps'] ?? null;
			if ( !is_array( $swaps ) || !array_is_list( $swaps ) ) {
				throw $this->invalidResponse( 'listed swap task has invalid pairs' );
			}
			$seen = [];
			foreach ( $swaps as $swap ) {
				$indexes = is_array( $swap ) ? ( $swap['indexes'] ?? null ) : null;
				if ( !is_array( $indexes ) || !array_is_list( $indexes ) || count( $indexes ) !== 2 ) {
					throw $this->invalidResponse( 'listed swap task has an invalid pair' );
				}
				foreach ( $indexes as $index ) {
					if ( !self::validIndexUid( $index ) || isset( $seen[$index] ) ) {
						throw $this->invalidResponse( 'listed swap task has an invalid or duplicate index' );
					}
					$seen[$index] = true;
				}
			}
		}
	}

	private function validateDocuments( mixed $documents, string $context ): void {
		if ( !is_array( $documents ) || !array_is_list( $documents ) ) {
			throw $this->invalidResponse( "$context are not a list" );
		}
		foreach ( $documents as $document ) {
			if ( !is_array( $document ) || ( $document !== [] && array_is_list( $document ) ) ) {
				throw $this->invalidResponse( "$context contain a non-object document" );
			}
		}
	}

	private function validateDocumentPagination( array $response, int $offset, int $limit ): void {
		if ( !isset( $response['total'] ) || !is_int( $response['total'] ) || $response['total'] < 0 ) {
			throw $this->invalidResponse( 'document list has no valid total' );
		}
		$count = count( $response['results'] );
		if ( $count > $limit || $count > max( 0, $response['total'] - $offset )
			|| ( $count === 0 && $response['total'] > $offset )
			|| ( array_key_exists( 'offset', $response ) && $response['offset'] !== $offset )
			|| ( array_key_exists( 'limit', $response ) && $response['limit'] !== $limit )
		) {
			throw $this->invalidResponse( 'document list has inconsistent pagination' );
		}
	}

	private function encodeBody( array $body ): string {
		try {
			return json_encode( $body, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new MeilisearchException( 'Meilisearch request cannot be encoded: ' . $e->getMessage() );
		}
	}

	private static function jsonArrays( mixed $value ): mixed {
		if ( $value instanceof \stdClass ) { $value = (array)$value; }
		if ( is_array( $value ) ) {
			foreach ( $value as &$item ) { $item = self::jsonArrays( $item ); }
		}
		return $value;
	}

	private function request( string $method, string $path, ?array $body = null ): array {
		$read = $method === 'GET' || ( $method === 'POST'
			&& ( str_ends_with( $path, '/search' ) || str_ends_with( $path, '/documents/fetch' ) ) );
		$encoded = $body === null ? null : $this->encodeBody( $body );
		$send = function () use ( $method, $path, $body, $encoded, $read ): array {
			$previous = $this->encodedRequestBody;
			$this->encodedRequestBody = $encoded;
			try {
				$response = $this->performRequest( $method, $path, $body );
				if ( !$read ) { $this->taskUid( $response ); }
				return $response;
			} finally {
				$this->encodedRequestBody = $previous;
			}
		};
		if ( !$read && $this->mutationHandler !== null ) {
			return ( $this->mutationHandler )( $method, $path, $body, $send );
		}
		return $send();
	}

	protected function performRequest( string $method, string $path, ?array $body = null ): array {
		$handle = curl_init( $this->url . $path );
		if ( $handle === false ) {
			throw new MeilisearchException( 'Unable to initialize cURL', true );
		}

		$headers = [ 'Content-Type: application/json' ];
		$taskRead = $method === 'GET' && ( preg_match( '#^/tasks/[0-9]+$#D', $path ) === 1
			|| str_starts_with( $path, '/tasks?' ) );
		$apiKey = $this->taskApiKey !== '' && $taskRead
			? $this->taskApiKey : $this->apiKey;
		if ( $apiKey !== '' ) {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}
		$options = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $this->timeout,
		];
		if ( $body !== null ) {
			$options[CURLOPT_POSTFIELDS] = $this->encodedRequestBody ?? $this->encodeBody( $body );
		}
		curl_setopt_array( $handle, $options );
		$response = curl_exec( $handle );
		$status = curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $handle );

		if ( $response === false ) {
			throw new MeilisearchException( 'Meilisearch request failed: ' . $error, true );
		}
		if ( $status < 200 || $status >= 300 ) {
			$data = [];
			if ( $response !== '' ) {
				try {
					$data = json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
				} catch ( \JsonException ) {
				}
			}
			$data = is_array( $data ) ? $data : [];
			$message = is_string( $data['message'] ?? null ) ? $data['message'] : $response;
			$apiCode = is_string( $data['code'] ?? null ) && $data['code'] !== '' ? $data['code'] : null;
			$code = $apiCode ?? 'http_' . $status;
			throw new MeilisearchException(
				"Meilisearch request failed ($code): $message",
				$status === 0 || $status === 408 || $status === 429 || $status >= 500,
				null,
				$apiCode,
				$status
			);
		}
		try {
			$data = json_decode( $response, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw $this->invalidResponse( 'expected a JSON object' );
		}
		if ( !$data instanceof \stdClass ) {
			throw $this->invalidResponse( 'expected a JSON object' );
		}
		$listKey = match ( true ) {
			$method === 'POST' && preg_match( '#^/indexes/[^/]+/search$#', $path ) === 1 => 'hits',
			$method === 'POST' && preg_match( '#^/indexes/[^/]+/documents/fetch$#', $path ) === 1 => 'results',
			$method === 'GET' && preg_match( '#^/indexes/[^/]+/documents(?:\?|$)#', $path ) === 1 => 'results',
			$method === 'GET' && preg_match( '#^/(?:indexes|tasks)\?#', $path ) === 1 => 'results',
			default => null,
		};
		if ( $listKey !== null ) {
			if ( !is_array( $data->$listKey ?? null ) ) {
				throw $this->invalidResponse( "$listKey are not a list" );
			}
			foreach ( $data->$listKey as $document ) {
				if ( !$document instanceof \stdClass ) {
					throw $this->invalidResponse( "$listKey contain a non-object document" );
				}
				if ( $method === 'GET' && str_starts_with( $path, '/tasks?' )
					&& isset( $document->details ) && !$document->details instanceof \stdClass
				) { throw $this->invalidResponse( 'task details are not an object or null' ); }
				if ( $method === 'GET' && str_starts_with( $path, '/tasks?' )
					&& ( $document->type ?? null ) === 'indexSwap'
				) {
					$swaps = $document->details->swaps ?? null;
					if ( !is_array( $swaps ) ) { throw $this->invalidResponse( 'task swap pairs are not a list' ); }
					foreach ( $swaps as $swap ) {
						if ( !$swap instanceof \stdClass || !is_array( $swap->indexes ?? null ) ) {
							throw $this->invalidResponse( 'task swap pairs have invalid JSON types' );
						}
					}
				}
			}
		}
		return self::jsonArrays( $data );
	}
}

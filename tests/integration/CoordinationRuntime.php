<?php

namespace FrauxSearch\Integration;

use Closure;
use FrauxSearch\DocumentHash;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\IndexCoordinatorFactory;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\MeilisearchException;
use FrauxSearch\RedisConnection;
use FrauxSearch\RedisCoordinationStore;
use MediaWiki\MediaWikiServices;
use RuntimeException;

class CoordinationRuntime {
	public readonly string $index;
	public readonly string $scope;
	public readonly RedisConnection $connection;
	public readonly RedisConnection $observer;
	public readonly RedisCoordinationStore $store;
	public readonly ObservedMeilisearchClient $client;
	public readonly HttpObservation $http;
	private array $sources = [];
	private array $dependencies = [];
	private bool $installed = false;

	public function __construct() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$token = bin2hex( random_bytes( 6 ) );
		$base = $config->get( 'FrauxSearchIndex' );
		self::check( is_string( $base ) && preg_match( '/^[A-Za-z0-9_-]+$/D', $base ) === 1,
			'Invalid configured Meilisearch index base.' );
		$this->index = $base . '_coordination_test_' . $token;
		$this->connection = IndexCoordinatorFactory::createConnection();
		$this->observer = IndexCoordinatorFactory::createConnection();
		$url = (string)$config->get( 'FrauxSearchUrl' );
		$this->scope = json_encode( [ $config->get( 'DBname' ), $config->get( 'DBprefix' ),
			rtrim( $url, '/' ), $this->index ], JSON_THROW_ON_ERROR );
		$this->store = new RedisCoordinationStore( $this->connection->evaluate( ... ), $this->scope );
		$this->http = new HttpObservation();
		$this->client = new ObservedMeilisearchClient( $url, (string)$config->get( 'FrauxSearchApiKey' ),
			$this->index, max( 5, (int)$config->get( 'FrauxSearchTimeout' ) ), $this->http,
			(string)$config->get( 'FrauxSearchTaskApiKey' ) );
	}

	public function install(): void {
		foreach ( [ $this->index, $this->index . '_completion', $this->index . '_coordination' ] as $index ) {
			self::check( !$this->client->withIndex( $index )->indexExists(), 'Random test index already exists.' );
		}
		$observerStore = new RedisCoordinationStore( $this->observer->evaluate( ... ), $this->scope );
		self::check( $observerStore->readState() === null && $this->store->readState() === null,
			'Random test scope already has coordination state.' );
		self::check( $this->observer->evaluate( "return redis.call('EXISTS', unpack(KEYS))",
			$this->store->keyNames(), [] ) === 0, 'Random test scope already contains Redis keys.' );
		$this->installed = true;
	}

	public function coordinator( ?Closure $build = null ): IndexCoordinator {
		return new IndexCoordinator( $this->store, $this->client, $this->index,
			$build ?? $this->build( ... ), $this->queueDependencies( ... ), 0 );
	}

	public function put( array $built ): void {
		$this->sources[$built['document']['id']] = $built;
	}

	public function remove( int $id ): void {
		unset( $this->sources[$id] );
	}

	public function build( int $id ): ?array {
		return $this->sources[$id] ?? null;
	}

	public function queueDependencies( array $ids ): void {
		foreach ( $ids as $id ) { $this->dependencies[$id] = true; }
	}

	public function dependencyIds(): array {
		$ids = array_keys( $this->dependencies );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public function cleanup(): void {
		if ( !$this->installed ) { return; }
		$this->http->afterAccept = null;
		foreach ( $this->http->accepted as $task ) {
			try { $this->client->waitForTask( $task['uid'] ); } catch ( MeilisearchException $error ) {
				if ( $error->getCompletedTaskId() !== $task['uid'] ) { throw $error; }
			}
		}
		self::check( $this->store->lock( 0 ), 'Cannot lock test scope for cleanup; preserving resources.' );
		try {
			$state = $this->store->readState();
			self::check( $state === null
				&& !$this->client->withIndex( $this->index . '_coordination' )->indexExists(),
				'Test scope or guard still contains unresolved work; preserving resources.' );
			foreach ( array_keys( $this->http->createdIndexes ) as $index ) {
				self::check( $index === $this->index || str_starts_with( $index, $this->index . '_' ),
					'Refusing cleanup outside this run.' );
				$this->store->assertLocked();
				$client = $this->client->withIndex( $index );
				if ( $client->indexExists() ) { $client->waitForTask( $client->deleteIndex() ); }
			}
			$this->installed = false;
		} finally { $this->store->unlock(); }
		self::check( $this->observer->evaluate( "return redis.call('EXISTS', unpack(KEYS))",
			$this->store->keyNames(), [] ) === 0, 'Owned Redis keys survived protocol retirement and cleanup.' );
	}

	public static function document( int $id, string $text ): array {
		$document = [ 'id' => $id, 'revision_id' => 1, 'title' => 'Isolated source ' . $id,
			'text' => $text, 'redirects' => [], 'namespace' => 0, 'boost' => 100,
			'incoming_links' => 0, 'outgoing_link_ids' => [], 'timestamp' => '20260908000000',
			'word_count' => str_word_count( $text ), 'byte_size' => strlen( $text ) ];
		$document['document_hash'] = DocumentHash::compute( $document );
		return [ 'document' => $document, 'is_redirect' => false, 'redirect_target_id' => null ];
	}

	public static function check( bool $condition, string $message ): void {
		if ( !$condition ) { throw new RuntimeException( $message ); }
	}
}

class HttpObservation {
	public array $accepted = [];
	public array $createdIndexes = [];
	public ?Closure $afterAccept = null;
}

class ObservedMeilisearchClient extends MeilisearchClient {
	public function __construct(
		string $url, string $key, string $index, int $timeout, private HttpObservation $observation,
		string $taskApiKey = ''
	) {
		parent::__construct( $url, $key, $index, $timeout, $taskApiKey );
	}

	protected function performRequest( string $method, string $path, ?array $body = null ): array {
		$response = parent::performRequest( $method, $path, $body );
		if ( isset( $response['taskUid'] ) ) {
			$this->observation->accepted[] = [ 'uid' => $response['taskUid'], 'method' => $method, 'path' => $path ];
			if ( $method === 'POST' && $path === '/indexes' ) {
				$this->observation->createdIndexes[$body['uid']] = true;
			}
			if ( $this->observation->afterAccept !== null ) {
				( $this->observation->afterAccept )( $method, $path, $body, $response );
			}
		}
		return $response;
	}
}

class InjectedFault extends RuntimeException {}

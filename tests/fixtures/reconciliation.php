<?php

namespace FrauxSearch\Tests;

use FrauxSearch\MeilisearchClient;

class ReconciliationTestServices {
	public array $pages = [];
	public array $indexes = [ 'wiki' => [], 'wiki_completion' => [] ];
	public array $settings = [];
	public array $events = [];
	public array $jobBatches = [];
	public ?\Closure $responseOverride = null;
	public bool $pendingWrites = false;
	public bool $pendingCallbacks = false;
	public bool $explicitTransaction = false;

	public function __construct() {
		$this->settings = array_fill_keys( [ 'wiki', 'wiki_completion' ], MeilisearchClient::expectedIndexSettings() );
	}
	public function getMainConfig(): self { return $this; }
	public function get( string $key ): string { return 'wiki'; }
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): self { return $this; }
	public function explicitTrxActive(): bool { return $this->explicitTransaction; }
	public function flushSnapshot( ...$args ): void {
		if ( $this->pendingWrites || $this->pendingCallbacks || $this->explicitTransaction ) {
			throw new \RuntimeException( 'Cannot flush snapshot; writes or callbacks are still pending.' );
		}
		$this->events[] = [ 'snapshot' ];
	}
	public function newSelectQueryBuilder(): ReconciliationTestQuery { return new ReconciliationTestQuery( $this ); }
	public function getJobQueueGroup(): self { return $this; }
	public function push( array $jobs ): void {
		$this->events[] = [ 'push', count( $jobs ) ];
		$this->jobBatches[] = $jobs;
	}
	public function jobParams(): array {
		return array_map( static fn ( $job ) => $job->getParams(), array_merge( [], ...$this->jobBatches ) );
	}
}

class ReconciliationTestQuery {
	private array $conditions = [];
	private int $rowLimit = PHP_INT_MAX;
	public function __construct( private ReconciliationTestServices $services ) {}
	public function select( array $fields ): self { return $this; }
	public function from( string $table ): self { return $this; }
	public function where( array $conditions ): self { $this->conditions = $conditions; return $this; }
	public function orderBy( string $field ): self { return $this; }
	public function limit( int $limit ): self { $this->rowLimit = $limit; return $this; }
	public function caller( string $caller ): self { return $this; }
	private function ids(): array {
		$ids = array_keys( $this->services->pages );
		sort( $ids, SORT_NUMERIC );
		$ids = array_values( array_filter( $ids, function ( int $id ): bool {
			foreach ( $this->conditions as $field => $condition ) {
				if ( $field === 'page_id' ) {
					if ( !in_array( $id, $condition, true ) ) { return false; }
				} elseif ( preg_match( '/^page_id (>|<=) ([0-9]+)$/D', $condition, $matches ) ) {
					if ( $matches[1] === '>' ? $id <= (int)$matches[2] : $id > (int)$matches[2] ) { return false; }
				} else { throw new \LogicException( 'Unexpected fixture predicate.' ); }
			}
			return true;
		} ) );
		return array_slice( $ids, 0, $this->rowLimit );
	}
	public function fetchResultSet(): \ArrayIterator {
		$ids = $this->ids();
		$this->services->events[] = [ 'page_scan', $ids ];
		return new \ArrayIterator( array_map( static fn ( int $id ) => (object)[ 'page_id' => $id ], $ids ) );
	}
	public function fetchFieldValues(): array {
		$this->services->events[] = [ 'page_exists', $this->conditions['page_id'] ];
		return array_map( 'strval', $this->ids() );
	}
}

class ReconciliationTestClient extends MeilisearchClient {
	public function __construct( private ReconciliationTestServices $services, private string $testIndex ) {}
	public function withIndex( string $index ): MeilisearchClient {
		return new self( $this->services, $index );
	}
	public function getIndexSettings(): array {
		$this->services->events[] = [ 'settings', $this->testIndex ];
		return $this->services->settings[$this->testIndex];
	}
	public function fetchDocuments( array $ids, ?array $fields = null ): array {
		$this->services->events[] = [ 'fetch', $this->testIndex, $ids, $fields ];
		$override = $this->services->responseOverride === null ? null
			: ( $this->services->responseOverride )( 'fetch', $this->testIndex, $ids );
		if ( $override !== null ) { return $override; }
		return array_values( array_filter( $this->services->indexes[$this->testIndex],
			static fn ( array $document ) => in_array( $document['id'], $ids, true ) ) );
	}
	public function listDocuments( int $offset, int $limit, ?array $fields = null, ?string $filter = null ): array {
		$this->services->events[] = [ 'list', $this->testIndex, $offset, $limit, $fields, $filter ];
		$override = $this->services->responseOverride === null ? null
			: ( $this->services->responseOverride )( 'list', $this->testIndex, $offset );
		if ( $override !== null ) { return $override; }
		$documents = array_values( $this->services->indexes[$this->testIndex] );
		if ( $filter !== null ) {
			if ( !preg_match( '/^id > ([0-9]+)(?: AND id <= ([0-9]+))?$/D', $filter, $bounds ) ) {
				throw new \LogicException( 'Unexpected fixture document filter.' );
			}
			$documents = array_values( array_filter( $documents,
				static fn ( array $document ) => $document['id'] > (int)$bounds[1]
					&& ( !isset( $bounds[2] ) || $document['id'] <= (int)$bounds[2] ) ) );
		}
		return [ 'results' => array_slice( $documents, $offset, $limit ), 'total' => count( $documents ) ];
	}
	protected function performRequest( string $method, string $path, ?array $body = null ): array {
		throw new \LogicException( 'Reconciliation must never mutate the index or call real HTTP.' );
	}
}

<?php

namespace FrauxSearch\Tests;

use FrauxSearch\FrauxSearchEngine;
use FrauxSearch\IndexCoordinator;
use FrauxSearch\RefreshPageJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\Search\SearchEngine;
use RuntimeException;

class ClockedRefreshPageJob extends RefreshPageJob {
	public int $clock = 1000;
	protected function now(): int { return $this->clock; }
	protected function newSearchEngine(): FrauxSearchEngine {
		return new RetryJobEngine( MediaWikiServices::getInstance() );
	}
}

class OtherSelectedSearchEngine extends SearchEngine {}

class RetryJobServices {
	public RetryJobDatabase $database;
	public RetryJobQueue $queue;
	public ?RuntimeException $failure = null;
	public array $refreshes = [];
	public array $searchEngineRequests = [];
	public function __construct() {
		$this->database = new RetryJobDatabase();
		$this->queue = new RetryJobQueue();
	}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): RetryJobDatabase { return $this->database; }
	public function getJobQueueGroup(): RetryJobQueue { return $this->queue; }
	public function getSearchEngineFactory(): self { return $this; }
	public function create( string $class ): SearchEngine {
		$this->searchEngineRequests[] = $class;
		return new OtherSelectedSearchEngine();
	}
}

class RetryJobEngine extends FrauxSearchEngine {
	public function __construct( private RetryJobServices $services ) {}
	protected function newCoordinator(): IndexCoordinator { return new RetryJobCoordinator( $this->services ); }
}

class RetryJobCoordinator extends IndexCoordinator {
	public function __construct( private RetryJobServices $services ) {}
	public function refresh( int $pageId, ?string $title = null, bool $completionOnly = false ): void {
		$this->services->refreshes[] = [ $pageId, $title ];
		if ( $this->services->failure !== null ) { throw $this->services->failure; }
	}
}

class RetryJobQueue {
	public bool $delayed = false;
	public bool $failPush = false;
	public array $jobs = [];
	public function get( string $type ): self { return $this; }
	public function delayedJobsEnabled(): bool { return $this->delayed; }
	public function push( $jobs ): void {
		if ( $this->failPush ) { throw new RuntimeException( 'queue unavailable' ); }
		foreach ( is_array( $jobs ) ? $jobs : [ $jobs ] as $job ) {
			if ( !$this->delayed && $job->getReleaseTimestamp() !== null ) {
				throw new RuntimeException( 'This backend does not support delayed jobs.' );
			}
			$this->jobs[] = $job;
		}
	}
}

class RetryJobDatabase {
	public bool $explicitTransaction = false;
	public bool $implicitReadSnapshot = false;
	public bool $pendingWrites = false;
	public array $callbacks = [];
	public int $queries = 0;
	public int $snapshotFlushes = 0;
	public function explicitTrxActive(): bool { return $this->explicitTransaction; }
	public function onTransactionCommitOrIdle( $callback, $caller ): void {
		if ( $this->explicitTransaction || $this->implicitReadSnapshot || $this->pendingWrites || $this->callbacks !== [] ) {
			$this->callbacks[] = $callback;
		} else { $callback(); }
	}
	public function commit(): void {
		$callbacks = $this->callbacks;
		$this->callbacks = [];
		$this->implicitReadSnapshot = false;
		$this->pendingWrites = false;
		foreach ( $callbacks as $callback ) { $callback(); }
	}
	public function flushSnapshot( ...$args ): void {
		if ( $this->explicitTransaction || $this->pendingWrites || $this->callbacks !== [] ) {
			throw new RuntimeException( 'Cannot flush snapshot; writes or callbacks are still pending.' );
		}
		$this->implicitReadSnapshot = false;
		$this->snapshotFlushes++;
	}
	public function newSelectQueryBuilder(): RetryJobQuery {
		if ( $this->explicitTransaction ) { throw new RuntimeException( 'Scheduler is inside an explicit transaction.' ); }
		$this->queries++;
		return new RetryJobQuery();
	}
}

class RetryJobQuery {
	public function select( ...$args ): self { return $this; }
	public function distinct(): self { return $this; }
	public function from( ...$args ): self { return $this; }
	public function join( ...$args ): self { return $this; }
	public function where( ...$args ): self { return $this; }
	public function orderBy( ...$args ): self { return $this; }
	public function limit( ...$args ): self { return $this; }
	public function caller( ...$args ): self { return $this; }
	public function fetchFieldValues(): array { return []; }
}

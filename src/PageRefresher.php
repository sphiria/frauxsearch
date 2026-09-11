<?php

namespace FrauxSearch;

use Closure;
use RuntimeException;

class PageRefresher {
	public function __construct(
		private MeilisearchClient $client,
		private MeilisearchClient $completionClient,
		private Closure $build,
		private Closure $queueRefreshes,
		private ?Closure $heartbeat = null
	) {
	}

	public function refresh( int $pageId, ?string $previousTitle = null, array $additionalTitles = [],
		bool $completionOnly = false
	): void {
		if ( $pageId <= 0 ) {
			return;
		}
		$old = $this->client->getDocument( $pageId );
		$oldCompletion = $this->completionClient->getDocument( $pageId );
		$built = ( $this->build )( $pageId );
		$plan = $this->plan( $pageId, $built, $old, $oldCompletion,
			array_merge( $additionalTitles, [ $previousTitle ] ), $completionOnly );
		if ( $plan['dependencies'] !== [] ) { ( $this->queueRefreshes )( $plan['dependencies'] ); }
		foreach ( [ [ $this->client, 'full' ], [ $this->completionClient, 'completion' ] ] as [ $client, $copy ] ) {
			if ( $plan[$copy] === 'delete' ) { $client->waitForTask( $client->deleteDocument( $pageId ) ); }
			if ( $plan[$copy] === 'replace' ) { $client->waitForTask( $client->replaceDocuments( [ $built['document'] ] ) ); }
		}
	}

	public function refreshBatch( array $built, array $titles, array $completionOnly ): void {
		$ids = array_keys( $built );
		$full = $this->indexedBatch( $this->client, $ids );
		$completion = $this->indexedBatch( $this->completionClient, $ids );
		$plans = [];
		$dependencies = [];
		foreach ( $built as $id => $document ) {
			$this->heartbeat?->__invoke();
			$plan = $this->plan( $id, $document, $full[$id] ?? null, $completion[$id] ?? null,
				$titles[$id] ?? [], $completionOnly[$id] ?? false );
			$plans[$id] = $plan;
			$dependencies = array_merge( $dependencies, $plan['dependencies'] );
		}
		$dependencies = self::ids( $dependencies );
		if ( $dependencies !== [] ) { ( $this->queueRefreshes )( $dependencies ); }
		foreach ( [ [ $this->client, 'full' ], [ $this->completionClient, 'completion' ] ] as [ $client, $copy ] ) {
			$replace = [];
			$delete = [];
			$bytes = 2;
			foreach ( $plans as $id => $plan ) {
				if ( $plan[$copy] === 'delete' ) { $delete[] = $id; }
				if ( $plan[$copy] !== 'replace' ) { continue; }
				$document = $built[$id]['document'];
				$size = strlen( json_encode( $document, JSON_THROW_ON_ERROR ) ) + 1;
				if ( $replace !== [] && $bytes + $size > 8388608 ) {
					$client->waitForTask( $client->replaceDocuments( $replace ) );
					$replace = [];
					$bytes = 2;
				}
				$replace[] = $document;
				$bytes += $size;
			}
			if ( $replace !== [] ) { $client->waitForTask( $client->replaceDocuments( $replace ) ); }
			if ( $delete !== [] ) { $client->waitForTask( $client->deleteDocuments( $delete ) ); }
		}
	}

	private function indexedBatch( MeilisearchClient $client, array $ids ): array {
		$result = [];
		foreach ( $client->fetchDocuments( $ids ) as $document ) {
			$id = $document['id'] ?? null;
			if ( !is_int( $id ) || !in_array( $id, $ids, true ) || isset( $result[$id] ) ) {
				throw new RuntimeException( 'Unexpected or duplicate indexed page in refresh batch.' );
			}
			$result[$id] = $document;
		}
		return $result;
	}

	private function plan( int $pageId, ?array $built, ?array $old, ?array $oldCompletion,
		array $previousTitles, bool $completionOnly
	): array {
		$document = $built['document'] ?? null;
		$issues = ReconciliationComparator::compare( $built, $old, $oldCompletion );
		$isRedirect = $built !== null && $built['is_redirect'];
		$refreshIds = [];
		$newTargets = self::ids( $document['outgoing_link_ids'] ?? [] );
		$copies = [ $old ];
		if ( !$isRedirect || $oldCompletion !== null ) { $copies[] = $oldCompletion; }
		foreach ( $copies as $indexed ) {
			$oldTargets = self::ids( $indexed['outgoing_link_ids'] ?? [] );
			if ( $oldTargets !== $newTargets ) { $refreshIds = array_merge( $refreshIds, $oldTargets, $newTargets ); }
		}
		$previousTitles = array_values( array_unique( array_filter( $previousTitles,
			static fn ( $title ) => $title !== null && $title !== '' ) ) );
		$changed = $issues !== [] || $previousTitles !== [];
		if ( $changed || $isRedirect ) {
			$titles = array_unique( array_filter( array_merge( $previousTitles, [
				$old['title'] ?? null, $oldCompletion['title'] ?? null, $document['title'] ?? null,
			] ), static fn ( $title ) => $title !== null && $title !== '' ) );
			foreach ( $titles as $title ) {
				$targets = self::ids( $this->completionClient->findDocumentsWithRedirect( $title ) );
				$expected = $built !== null && $built['is_redirect'] && $built['redirect_target_id'] !== null ? [ $built['redirect_target_id'] ] : [];
				if ( $changed || $targets !== $expected ) {
					$refreshIds = array_merge( $refreshIds, $targets, $expected );
				}
			}
		}
		return [
			'dependencies' => array_values( array_diff( self::ids( $refreshIds ), [ $pageId ] ) ),
			'full' => $completionOnly ? null : self::action( $issues, 'full_text' ),
			'completion' => self::action( $issues, 'completion' ),
		];
	}

	private static function action( array $issues, string $copy ): ?string {
		if ( in_array( $copy . '_unexpected', $issues, true ) ) { return 'delete'; }
		if ( in_array( $copy . '_missing', $issues, true ) || in_array( $copy . '_stale', $issues, true ) ) {
			return 'replace';
		}
		return null;
	}

	private static function ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ),
			static fn ( int $id ) => $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}
}

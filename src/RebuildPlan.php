<?php

namespace FrauxSearch;

use InvalidArgumentException;

class RebuildPlan {
	public readonly string $baseIndex;
	public readonly bool $dryRun;
	public readonly bool $generation;
	public readonly int $startAfter;
	public readonly int $stopAfter;
	public readonly int $batchSize;
	public readonly int $maxPending;

	public function __construct( array $options, string $configuredIndex ) {
		$this->baseIndex = (string)( $options['index'] ?? $configuredIndex );
		$this->dryRun = isset( $options['dry-run'] );
		$this->startAfter = self::integer( $options, 'start-after', 0, 0 );
		$this->stopAfter = self::integer( $options, 'stop-after', 0, 1 );
		$this->batchSize = self::integer( $options, 'batch-size', 500, 1 );
		$this->maxPending = self::integer( $options, 'max-pending', 4, 1 );
		if ( !preg_match( '/^[A-Za-z0-9_-]+$/D', $this->baseIndex ) ) {
			throw new InvalidArgumentException( 'Invalid Meilisearch index name.' );
		}
		if ( $this->baseIndex === $configuredIndex . '_completion' ) {
			throw new InvalidArgumentException( 'The active completion index cannot be used as a base index.' );
		}
		if ( $this->stopAfter > 0 && $this->stopAfter <= $this->startAfter ) {
			throw new InvalidArgumentException( '--stop-after must be greater than --start-after.' );
		}
		$bounded = isset( $options['start-after'] ) || isset( $options['stop-after'] );
		$testGeneration = isset( $options['generation'] );
		if ( $testGeneration && (
			!isset( $options['index'] ) || !isset( $options['stop-after'] )
			|| $this->baseIndex === $configuredIndex
			|| $this->baseIndex === $configuredIndex . '_completion'
			|| isset( $options['start-after'] ) || isset( $options['no-reset'] )
		) ) {
			throw new InvalidArgumentException(
				'--generation requires --stop-after and an isolated --index, without --start-after or --no-reset.'
			);
		}
		if ( $bounded && !$this->dryRun && !isset( $options['no-reset'] ) && !$testGeneration ) {
			throw new InvalidArgumentException(
				'Bounded/resumed writes require --no-reset; use --dry-run to scan without writing.'
			);
		}
		$this->generation = !$this->dryRun && !isset( $options['no-reset'] );
	}

	private static function integer( array $options, string $name, int $default, int $minimum ): int {
		if ( !isset( $options[$name] ) ) {
			return $default;
		}
		$value = filter_var( $options[$name], FILTER_VALIDATE_INT );
		if ( $value === false || $value < $minimum ) {
			throw new InvalidArgumentException( "--$name must be an integer >= $minimum." );
		}
		return $value;
	}
}

<?php

namespace FrauxSearch\Tests;

class IncomingTestDatabase {
	public array $linktargets = [];
	public array $pagelinks = [];
	public array $redirects = [];
	public array $queries = [];
	public int $snapshotFlushes = 0;
	public bool $pendingWrites = false;
	public bool $pendingCallbacks = false;
	public bool $explicitTransaction = false;

	public function explicitTrxActive(): bool { return $this->explicitTransaction; }
	public function flushSnapshot( ...$args ): void {
		if ( $this->pendingWrites || $this->pendingCallbacks || $this->explicitTransaction ) {
			throw new \RuntimeException( 'Cannot flush snapshot; writes or callbacks are still pending.' );
		}
		$this->snapshotFlushes++;
	}
	public function newSelectQueryBuilder(): IncomingTestQuery {
		$query = new IncomingTestQuery( $this );
		$this->queries[] = $query;
		return $query;
	}
}

class IncomingTestQuery {
	public string $field;
	public string $table;
	public array $joins = [];
	public array $conditions;
	public string $order;
	public int $rowLimit;
	public bool $unique = false;
	public int $returnedRows = 0;

	public function __construct( private IncomingTestDatabase $database ) {}
	public function select( string $field ): self { $this->field = $field; return $this; }
	public function distinct(): self { $this->unique = true; return $this; }
	public function from( string $table ): self { $this->table = $table; return $this; }
	public function join( string $table, $alias, $condition ): self {
		$this->joins[] = [ $table, $alias, $condition ];
		return $this;
	}
	public function where( array $conditions ): self { $this->conditions = $conditions; return $this; }
	public function orderBy( string $field ): self { $this->order = $field; return $this; }
	public function limit( int $limit ): self { $this->rowLimit = $limit; return $this; }
	public function caller( string $caller ): self { return $this; }

	public function fetchFieldValues(): array {
		$rows = match ( $this->table ) {
			'pagelinks' => $this->database->pagelinks,
			'redirect' => $this->database->redirects,
			default => throw new \LogicException( 'Unsupported fixture table: ' . $this->table ),
		};
		foreach ( $this->joins as [ $table, $alias, $condition ] ) {
			if ( $table !== 'linktarget' || $condition !== 'pl_target_id = lt_id' ) {
				throw new \LogicException( 'Unexpected join; this fixture has no target page table.' );
			}
			$joined = [];
			foreach ( $rows as $row ) {
				foreach ( $this->database->linktargets as $target ) {
					if ( $row['pl_target_id'] === $target['lt_id'] ) {
						$joined[] = $row + $target;
					}
				}
			}
			$rows = $joined;
		}
		$rows = array_filter( $rows, function ( array $row ): bool {
			foreach ( $this->conditions as $field => $value ) {
				if ( is_string( $field ) ) {
					if ( !array_key_exists( $field, $row ) || $row[$field] !== $value ) {
						return false;
					}
				} elseif ( preg_match( '/^(pl_from|rd_from) > ([0-9]+)$/D', $value, $matches ) ) {
					if ( $row[$matches[1]] <= (int)$matches[2] ) {
						return false;
					}
				} elseif ( $value === "(rd_interwiki IS NULL OR rd_interwiki = '')" ) {
					if ( $row['rd_interwiki'] !== null && $row['rd_interwiki'] !== '' ) {
						return false;
					}
				} else {
					throw new \LogicException( 'Unsupported fixture condition.' );
				}
			}
			return true;
		} );
		usort( $rows, fn ( array $a, array $b ) => $a[$this->order] <=> $b[$this->order] );
		$values = array_column( $rows, $this->field );
		if ( $this->unique ) {
			$values = array_values( array_unique( $values ) );
		}
		$values = array_slice( $values, 0, $this->rowLimit );
		$this->returnedRows = count( $values );
		return array_map( 'strval', $values );
	}
}

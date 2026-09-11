<?php

namespace FrauxSearch\Tests;

class LinkCounterTestDatabase {
	public \PDO $connection;
	public int $queries = 0;

	public function __construct() {
		$this->connection = new \PDO( 'sqlite::memory:', null, null, [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_STRINGIFY_FETCHES => true,
		] );
		$this->connection->exec( 'CREATE TABLE page (
			page_id INTEGER PRIMARY KEY, page_namespace INTEGER, page_title TEXT
		); CREATE TABLE linktarget (
			lt_id INTEGER PRIMARY KEY, lt_namespace INTEGER, lt_title TEXT
		); CREATE TABLE pagelinks (
			pl_from INTEGER, pl_from_namespace INTEGER, pl_target_id INTEGER
		)' );
	}

	public function page( int $id, string $title, int $namespace = 0 ): void {
		$this->connection->prepare( 'INSERT INTO page VALUES (?, ?, ?)' )->execute( [ $id, $namespace, $title ] );
	}
	public function target( int $id, string $title, int $namespace = 0 ): void {
		$this->connection->prepare( 'INSERT INTO linktarget VALUES (?, ?, ?)' )->execute( [ $id, $namespace, $title ] );
	}
	public function link( int $source, int $target ): void {
		$this->connection->prepare( 'INSERT INTO pagelinks VALUES (?, 0, ?)' )->execute( [ $source, $target ] );
	}
	public function newSelectQueryBuilder(): LinkCounterTestQuery {
		$this->queries++;
		return new LinkCounterTestQuery( $this->connection );
	}
}

class LinkCounterTestQuery {
	private array $fields;
	private string $table;
	private array $joins = [];
	private array $conditions = [];
	private ?string $group = null;
	private array $order = [];

	public function __construct( private \PDO $connection ) {}
	public function select( array $fields ): self { $this->fields = $fields; return $this; }
	public function from( string $table, ?string $alias = null ): self {
		$this->table = $table . ( $alias === null ? '' : ' AS ' . $alias );
		return $this;
	}
	public function join( string $table, ?string $alias, array|string $conditions ): self {
		$this->joins[] = 'JOIN ' . $table . ( $alias === null ? '' : ' AS ' . $alias )
			. ' ON (' . implode( ') AND (', (array)$conditions ) . ')';
		return $this;
	}
	public function where( array $conditions ): self { $this->conditions = $conditions; return $this; }
	public function groupBy( string $field ): self { $this->group = $field; return $this; }
	public function orderBy( array $fields ): self { $this->order = $fields; return $this; }
	public function straightJoinOption(): self { return $this; }
	public function caller( string $caller ): self { return $this; }
	public function fetchResultSet(): array {
		$fields = [];
		foreach ( $this->fields as $alias => $expression ) {
			$fields[] = $expression . ( is_int( $alias ) ? '' : ' AS ' . $alias );
		}
		$conditions = [];
		foreach ( $this->conditions as $field => $values ) {
			$conditions[] = $field . ' IN (' . implode( ', ', array_map( 'intval', $values ) ) . ')';
		}
		$sql = 'SELECT ' . implode( ', ', $fields ) . ' FROM ' . $this->table
			. ' ' . implode( ' ', $this->joins ) . ' WHERE ' . implode( ' AND ', $conditions );
		if ( $this->group !== null ) { $sql .= ' GROUP BY ' . $this->group; }
		if ( $this->order !== [] ) { $sql .= ' ORDER BY ' . implode( ', ', $this->order ); }
		return $this->connection->query( $sql )->fetchAll( \PDO::FETCH_OBJ );
	}
}

class LinkCounterTestServices {
	public int $primaryReads = 0;
	public int $replicaReads = 0;
	public function __construct( public LinkCounterTestDatabase $database ) {}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): LinkCounterTestDatabase {
		$this->primaryReads++;
		return $this->database;
	}
	public function getReplicaDatabase(): LinkCounterTestDatabase {
		$this->replicaReads++;
		return $this->database;
	}
}

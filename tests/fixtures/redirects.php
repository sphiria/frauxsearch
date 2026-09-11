<?php

namespace FrauxSearch\Tests;

class RedirectTestDatabase {
	public \PDO $connection;
	public array $pages = [];
	public array $redirects = [];
	public int $queries = 0;

	public function __construct() {
		$this->connection = new \PDO( 'sqlite::memory:', null, null,
			[ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$this->connection->exec( 'CREATE TABLE page (
			page_id INTEGER PRIMARY KEY, page_namespace INTEGER, page_title TEXT
		)' );
		$this->connection->exec( 'CREATE TABLE redirect (
			rd_from INTEGER PRIMARY KEY, rd_namespace INTEGER, rd_title TEXT, rd_interwiki TEXT
		)' );
	}

	public function page( int $id, string $title, int $namespace = 0 ): void {
		$this->connection->prepare( 'INSERT INTO page VALUES (?, ?, ?)' )
			->execute( [ $id, $namespace, $title ] );
		$this->pages[$id] = [ $namespace, $title ];
	}

	public function redirect( int $id, string $target, ?string $interwiki = '', int $namespace = 0 ): void {
		$this->connection->prepare( 'INSERT INTO redirect VALUES (?, ?, ?, ?)' )
			->execute( [ $id, $namespace, $target, $interwiki ] );
		$this->redirects[$id] = true;
	}

	public function newSelectQueryBuilder(): RedirectTestQuery {
		$this->queries++;
		return new RedirectTestQuery( $this->connection );
	}
}

class RedirectTestQuery {
	private string $fields;
	private string $table;
	private array $joins = [];
	private array $conditions = [];

	public function __construct( private \PDO $connection ) {}
	public function select( array $fields ): self { $this->fields = implode( ', ', $fields ); return $this; }
	public function from( string $table, ?string $alias = null ): self {
		$this->table = $table . ( $alias === null ? '' : ' AS ' . $alias );
		return $this;
	}
	public function join( string $table, ?string $alias, array|string $conditions ): self {
		return $this->addJoin( 'JOIN', $table, $alias, $conditions );
	}
	public function leftJoin( string $table, ?string $alias, array|string $conditions ): self {
		return $this->addJoin( 'LEFT JOIN', $table, $alias, $conditions );
	}
	private function addJoin( string $kind, string $table, ?string $alias, array|string $conditions ): self {
		$this->joins[] = "$kind $table" . ( $alias === null ? '' : ' AS ' . $alias )
			. ' ON ' . $this->conditions( (array)$conditions );
		return $this;
	}
	public function where( array $conditions ): self { $this->conditions = $conditions; return $this; }
	public function caller( string $caller ): self { return $this; }
	public function fetchResultSet(): array { return $this->execute()->fetchAll( \PDO::FETCH_OBJ ); }
	public function fetchField() {
		$row = $this->execute()->fetch( \PDO::FETCH_NUM );
		return $row === false ? false : $row[0];
	}
	private function execute(): \PDOStatement {
		return $this->connection->query( 'SELECT ' . $this->fields . ' FROM ' . $this->table
			. ' ' . implode( ' ', $this->joins ) . ' WHERE ' . $this->conditions( $this->conditions ) );
	}
	private function conditions( array $conditions ): string {
		$expressions = [];
		foreach ( $conditions as $field => $value ) {
			if ( is_int( $field ) ) {
				$expressions[] = '(' . $value . ')';
			} elseif ( is_array( $value ) ) {
				$expressions[] = $field . ' IN (' . implode( ', ', array_map( $this->quote( ... ), $value ) ) . ')';
			} else {
				$expressions[] = $field . ' = ' . $this->quote( $value );
			}
		}
		return implode( ' AND ', $expressions );
	}
	private function quote( int|string $value ): string {
		return is_int( $value ) ? (string)$value : $this->connection->quote( $value );
	}
}

class RedirectTestServices {
	public function __construct( public RedirectTestDatabase $database ) {}
	public function getConnectionProvider(): self { return $this; }
	public function getPrimaryDatabase(): RedirectTestDatabase { return $this->database; }
	public function getReplicaDatabase(): RedirectTestDatabase { return $this->database; }
	public function getWikiPageFactory(): self { return $this; }
	public function getTitleFactory(): self { return $this; }
	public function newFromID( int $id, int $flags ): ?RedirectTestPage {
		if ( !isset( $this->database->pages[$id] ) ) { return null; }
		[ $namespace, $title ] = $this->database->pages[$id];
		return new RedirectTestPage( $namespace, $title, $id, isset( $this->database->redirects[$id] ) );
	}
	public function makeTitle( int $namespace, string $title ): RedirectTestPage {
		return new RedirectTestPage( $namespace, $title );
	}
}

class RedirectTestPage {
	public function __construct( private int $namespace, private string $title,
		private int $id = 0, private bool $redirect = false
	) {}
	public function getRevisionRecord(): self { return $this; }
	public function getContent( $slot ): self { return $this; }
	public function getTitle(): self { return $this; }
	public function getId(): int { return 1000 + $this->id; }
	public function isRedirect(): bool { return $this->redirect; }
	public function getTextForSearchIndex(): string { return $this->redirect ? '#REDIRECT [[w:Target]]' : 'Canonical content'; }
	public function getTimestamp(): string { return '20260908000000'; }
	public function getNamespace(): int { return $this->namespace; }
	public function getPrefixedText(): string {
		return ( $this->namespace === 1 ? 'Talk:' : '' ) . str_replace( '_', ' ', $this->title );
	}
}

<?php

namespace MediaWiki\Maintenance;

define( 'RUN_MAINTENANCE_IF_MAIN', __FILE__ );

class Maintenance {
	public array $options = [];
	public function __construct() {}
	public function addDescription( ...$args ): void {}
	public function addOption( ...$args ): void {}
	public function output( ...$args ): void {}
	public function hasOption( string $name ): bool { return array_key_exists( $name, $this->options ); }
	public function getOption( string $name, $default = null ) { return $this->options[$name] ?? $default; }
	public function getParameters(): object {
		return new class( $this->options ) {
			public function __construct( private array $options ) {}
			public function getOptions(): array { return $this->options; }
		};
	}
}

namespace MediaWiki;

class MediaWikiServices {
	public static $instance;
	public static function getInstance() { return self::$instance; }
}

namespace MediaWiki\Search;

class SearchEngine {}

namespace Wikimedia\Rdbms;

interface IDBAccessObject {
	public const READ_LATEST = 1;
}

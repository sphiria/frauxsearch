<?php

namespace {
	if ( !class_exists( Redis::class ) ) {
		class Redis {
			public const OPT_MAX_RETRIES = 11;
		}
	}
}

namespace Wikimedia\ObjectCache {
	use RuntimeException;

	class RedisConnectionPool {
		public static array $options = [];
		public static ?string $server = null;
		public static ?RedisConnRef $connection = null;
		public static bool $fail = false;
		public static function singleton( array $options ): self {
			self::$options = $options;
			return new self();
		}
		public function getConnection( string $server ): RedisConnRef|false {
			self::$server = $server;
			if ( self::$fail ) { throw new RuntimeException( 'private-endpoint private-password' ); }
			return self::$connection ?? false;
		}
	}

	class RedisConnRef {
		public array $calls = [];
		public mixed $result = [ 'ok' ];
		public ?string $error = null;
		public ?string $errorOnEval = null;
		public ?string $errorOnSelect = null;
		public ?string $throwOn = null;
		public bool $optionResult = true;
		public bool $selectResult = true;
		public function clearLastError(): void { $this->error = null; }
		public function getLastError(): ?string { return $this->error; }
		public function setOption( int $option, mixed $value ): bool {
			$this->record( 'setOption', [ $option, $value ] );
			return $this->optionResult;
		}
		public function select( int $database ): bool {
			$this->record( 'select', [ $database ] );
			$this->error = $this->errorOnSelect;
			return $this->selectResult;
		}
		public function luaEval( string $script, array $params, int $numKeys ): mixed {
			$this->record( 'luaEval', [ $script, $params, $numKeys ] );
			$this->error = $this->errorOnEval;
			return $this->result;
		}
		private function record( string $method, array $args ): void {
			$this->calls[] = [ $method, $args ];
			if ( $this->throwOn === $method ) { throw new RuntimeException( 'private-endpoint private-password' ); }
		}
	}
}

namespace MediaWiki {
	use RuntimeException;

	class MediaWikiServices {
		public static array $values = [];
		public static array $requested = [];
		public static array $unexpectedCalls = [];
		public static ?object $primaryDatabase = null;
		public static function getInstance(): self { return new self(); }
		public function getMainConfig(): object {
			return new class {
				public function get( string $name ): mixed {
					MediaWikiServices::$requested[] = $name;
					if ( !array_key_exists( $name, MediaWikiServices::$values ) ) {
						throw new RuntimeException( "Unexpected configuration access: $name" );
					}
					return MediaWikiServices::$values[$name];
				}
			};
		}
		public function __call( string $method, array $args ): mixed {
			if ( $method === 'getConnectionProvider' && $args === [] && self::$primaryDatabase !== null ) {
				return new class( self::$primaryDatabase ) {
					public function __construct( private object $database ) {
					}
					public function getPrimaryDatabase(): object {
						return $this->database;
					}
				};
			}
			self::$unexpectedCalls[] = $method;
			throw new RuntimeException( "Factory must not initialize SQL, source services or cache factories: $method" );
		}
	}
}

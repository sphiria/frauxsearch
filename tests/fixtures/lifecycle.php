<?php

namespace {

	if ( !class_exists( Redis::class ) ) {
		class Redis {
			public function get( string $key ): mixed { return false; }
			public function flushDB(): bool { return true; }
		}
	}
}

namespace FrauxSearch {

	class RedisConnection {
		public function evaluate( string $script, array $keys, array $args ): int { return 0; }
	}

	class IndexCoordinatorFactory {
		public static function create(): object {
			return \MediaWiki\MediaWikiServices::getInstance()->coordinator;
		}
	}
}

namespace MediaWiki\Deferred {

	class DeferredUpdates {
		public static function doUpdates(): void {}
	}
}

namespace MediaWiki\Title {

	class Title {
		public static function newFromText( string $text ): self { return new self(); }
		public function exists(): bool { return true; }
	}
}

namespace Wikimedia\ObjectCache {

	class HashBagOStuff {
		private array $values = [];
		public function get( string $key ): mixed { return $this->doGet( $key ); }
		public function set( string $key, mixed $value ): bool { return $this->doSet( $key, $value ); }
		public function clear(): void { $this->values = []; }
		protected function doGet( $key, $flags = 0, &$casToken = null ) { return $this->values[$key] ?? false; }
		protected function doSet( $key, $value, $exptime = 0, $flags = 0 ) { $this->values[$key] = $value; return true; }
	}
}

namespace FrauxSearch\Tests {

	class LifecycleServices {
		public string $server = 'source-db:3306';
		public string $domain = 'isolated_fixture-prefix_';
		public int $deletions = 0;
		public object $config;
		public object $queue;
		public object $coordinator;

		public function __construct() {
			$this->config = new class {
				public array $values = [ 'DBname' => 'isolated_fixture', 'DBprefix' => 'prefix_',
					'FrauxSearchUrl' => 'http://meili:7700' ];
				public function get( string $name ): mixed { return $this->values[$name]; }
			};
			$this->queue = new class {
				public int $acquired = 0;
				public int $abandoned = 0;
				public int $delayed = 0;
				public int $inspections = 0;
				public function getSize(): int { $this->inspections++; return 0; }
				public function getAcquiredCount(): int { return $this->acquired; }
				public function getAbandonedCount(): int { return $this->abandoned; }
				public function getDelayedCount(): int { return $this->delayed; }
			};
			$this->coordinator = new class {
				public function status(): array {
					return [ 'coordinationLost' => false, 'run' => null, 'pending' => null ];
				}
				public function drain(): void {}
			};
		}

		public function getMainConfig(): object { return $this->config; }
		public function getConnectionProvider(): self { return $this; }
		public function getPrimaryDatabase(): self { return $this; }
		public function getServer(): string { return $this->server; }
		public function getDomainID(): string { return $this->domain; }
		public function getJobQueueGroup(): self { return $this; }
		public function getQueueSizes(): array { return [ 'frauxSearchRefreshPage' => 0 ]; }
		public function getQueueTypes(): array { return [ 'frauxSearchRefreshPage' ]; }
		public function get( string $type ): object { return $this->queue; }
		public function getDBLoadBalancerFactory(): self { return $this; }
		public function commitPrimaryChanges( string $caller ): void {}
		public function flushSnapshot( string $caller ): void {}
		public function getLinkCache(): self { return $this; }
		public function clear(): void {}
		public function getWikiPageFactory(): never {
			$this->deletions++;
			throw new \DomainException( 'Reached the page deletion boundary.' );
		}
	}
}

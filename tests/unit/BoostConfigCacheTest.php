<?php

namespace FrauxSearch\Tests;

use FrauxSearch\DocumentBuilder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class BoostConfigCacheTest extends TestCase {
	private PolicyCacheServices $services;
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/maintenance/Maintenance.php';
		require_once dirname( __DIR__ ) . '/fixtures/mediawiki/Jobs.php';
		define( 'NS_MEDIAWIKI', 8 );
		$this->services = new PolicyCacheServices();
		\MediaWiki\MediaWikiServices::$instance = $this->services;
	}

	public function testNewRevisionAndDeletionIgnoreCachedOldPolicy(): void {
		$this->assertSame( [ 'Old' => 150 ], ( new DocumentBuilder() )->loadBoostConfig() );
		$this->services->revisionId = 2;
		$this->assertSame( [ 'New' => 50 ], ( new DocumentBuilder() )->loadBoostConfig() );
		$this->services->revisionId = 0;
		$this->assertSame( [], ( new DocumentBuilder() )->loadBoostConfig() );
		$this->services->revisionId = 1;
		$this->assertSame( [ 'Old' => 150 ], ( new DocumentBuilder() )->loadBoostConfig() );
	}

	public function testLateCachePopulationCannotReplaceNewPolicy(): void {
		$this->services->duringRead = function (): void {
			$this->services->revisionId = 2;
			$this->assertSame( [ 'New' => 50 ], ( new DocumentBuilder() )->loadBoostConfig() );
		};
		$this->assertSame( [ 'Old' => 150 ], ( new DocumentBuilder() )->loadBoostConfig() );
		$this->assertSame( [ 'New' => 50 ], ( new DocumentBuilder() )->loadBoostConfig() );
	}

	public function testUnreadablePolicyDoesNotSilentlyRemoveAllBoosts(): void {
		$this->services->revisionId = 99;
		$this->expectException( \RuntimeException::class );
		( new DocumentBuilder() )->loadBoostConfig();
	}
}

class PolicyCacheServices {
	public int $revisionId = 1;
	public array $cache = [];
	public $duringRead = null;
	public function getPageStore(): self { return $this; }
	public function getPageByName( $namespace, $title, $flags ) {
		if ( $flags !== 1 ) { throw new \RuntimeException( 'Expected primary read' ); }
		return $this->revisionId > 0 ? $this : null;
	}
	public function getLatest(): int { return $this->revisionId; }
	public function getRevisionLookup(): self { return $this; }
	public function getRevisionById( $id, $flags ) {
		if ( $id === 99 ) { return null; }
		return new class( $id, $this ) {
			public function __construct( private int $id, private PolicyCacheServices $services ) {}
			public function getId(): int { return $this->id; }
			public function getContent( $slot ): self {
				$callback = $this->services->duringRead;
				$this->services->duringRead = null;
				if ( $callback !== null ) { $callback(); }
				return $this;
			}
			public function getTextForSearchIndex(): string { return $this->id === 1 ? 'Old|150%' : 'New|50%'; }
		};
	}
	public function getMainObjectStash(): self { return $this; }
	public function makeKey( ...$parts ): string { return implode( ':', $parts ); }
	public function get( $key ) { return $this->cache[$key] ?? false; }
	public function set( $key, $value, $ttl ): void { $this->cache[$key] = $value; }
}

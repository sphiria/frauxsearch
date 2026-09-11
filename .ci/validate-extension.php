<?php

$mediaWiki = getenv( 'MW_INSTALL_PATH' );
if ( !$mediaWiki || !is_file( $mediaWiki . '/includes/AutoLoader.php' ) ) {
	fwrite( STDERR, "Set MW_INSTALL_PATH to the pinned MediaWiki source tree.\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/vendor/autoload.php';
require $mediaWiki . '/includes/AutoLoader.php';

try {
	$validator = new MediaWiki\Registration\ExtensionJsonValidator(
		static function ( string $message ): never { throw new RuntimeException( $message ); }
	);
	$validator->checkDependencies();
	$manifestPath = dirname( __DIR__ ) . '/extension.json';
	$validator->validate( $manifestPath );
	fwrite( STDOUT, "Extension manifest validates against the supplied MediaWiki schema.\n" );
	$manifest = json_decode( file_get_contents( $manifestPath ), true, 512, JSON_THROW_ON_ERROR );
	$processor = new MediaWiki\Registration\ExtensionProcessor();
	$processor->extractInfo( $manifestPath, $manifest, $manifest['manifest_version'] );
	$registry = new class( $processor->getExtractedInfo() ) implements MediaWiki\HookContainer\HookRegistry {
		public function __construct( private array $extracted ) {
		}
		public function getGlobalHooks(): array {
			return $this->extracted['globals']['wgHooks'] ?? [];
		}
		public function getExtensionHooks(): array {
			return $this->extracted['attributes']['Hooks'] ?? [];
		}
		public function getDeprecatedHooks(): MediaWiki\HookContainer\DeprecatedHooks {
			return new MediaWiki\HookContainer\DeprecatedHooks();
		}
	};
	$services = new class implements Psr\Container\ContainerInterface {
		public function get( string $id ): never {
			throw new RuntimeException( "Hook registration smoke must not initialize service $id." );
		}
		public function has( string $id ): bool {
			return false;
		}
	};
	$hooks = new MediaWiki\HookContainer\HookContainer(
		$registry, new Wikimedia\ObjectFactory\ObjectFactory( $services )
	);
	$getHandlers = new ReflectionMethod( $hooks, 'getHandlers' );
	$count = 0;
	foreach ( $manifest['Hooks'] as $hook => $declarations ) {
		$handlers = $getHandlers->invoke( $hooks, $hook, [] );
		if ( count( $handlers ) !== count( (array)$declarations ) ) {
			throw new RuntimeException( "MediaWiki dropped one or more declared $hook handlers." );
		}
		foreach ( $handlers as $handler ) {
			if ( !is_callable( $handler['callback'] ) ) {
				throw new RuntimeException( "MediaWiki registered a non-callable $hook handler." );
			}
			$count++;
		}
	}
	if ( $getHandlers->invoke( $hooks, 'LoadExtensionSchemaUpdates', [ 'noServices' => true ] ) !== [] ) {
		throw new RuntimeException( 'FrauxSearch must not register SQL schema updates.' );
	}
	$updater = new class {
		public function __call( string $method, array $arguments ): never {
			throw new RuntimeException( "FrauxSearch attempted SQL schema access: $method." );
		}
	};
	$hooks->run( 'LoadExtensionSchemaUpdates', [ $updater ], [ 'noServices' => true ] );
	fwrite( STDOUT, "MediaWiki normalized $count callable hook handlers; no SQL schema updates registered or executed.\n" );
	require __DIR__ . '/check-search-text.php';
	require __DIR__ . '/check-search-engine.php';
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}

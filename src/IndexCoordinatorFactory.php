<?php

namespace FrauxSearch;

use Closure;
use MediaWiki\MediaWikiServices;
use RuntimeException;

class IndexCoordinatorFactory {
	public static function create(
		?string $baseIndex = null, int $lockTimeout = 5, ?Closure $build = null
	): IndexCoordinator {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$baseIndex ??= (string)$config->get( 'FrauxSearchIndex' );
		$url = (string)$config->get( 'FrauxSearchUrl' );
		$client = new MeilisearchClient( $url, (string)$config->get( 'FrauxSearchApiKey' ),
			$baseIndex, (int)$config->get( 'FrauxSearchTimeout' ), (string)$config->get( 'FrauxSearchTaskApiKey' ) );
		$store = self::createStore( $baseIndex );
		return new IndexCoordinator(
			$store,
			$client, $baseIndex,
			static function ( int $id ) use ( $services, $build, $store ): ?array {
				$primary = $services->getConnectionProvider()->getPrimaryDatabase();
				if ( $primary->explicitTrxActive() ) {
					throw new RuntimeException( 'FrauxSearch refresh must run after the source transaction commits.' );
				}
				$primary->flushSnapshot( __CLASS__ . '::create' );
				return $build !== null
					? $build( $id, $store->assertLocked( ... ) )
					: ( new DocumentBuilder( true ) )->build( $id );
			},
			static function ( array $pageIds ) use ( $services, $store ): void {
				$event = bin2hex( random_bytes( 16 ) );
				foreach ( array_chunk( $pageIds, 1000 ) as $batch ) {
					$store->assertLocked();
					$services->getJobQueueGroup()->push( array_map( static fn ( int $id ) => new RefreshPageJob( [
						'pageId' => $id, 'dependencyEvent' => $event,
					] ), $batch ) );
				}
			},
			$lockTimeout
		);
	}

	public static function createStore( ?string $baseIndex = null ): RedisCoordinationStore {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$connection = self::createConnection();
		$baseIndex ??= (string)$config->get( 'FrauxSearchIndex' );
		$scope = json_encode( [ $config->get( 'DBname' ), $config->get( 'DBprefix' ),
			rtrim( (string)$config->get( 'FrauxSearchUrl' ), '/' ), $baseIndex ], JSON_THROW_ON_ERROR );
		return new RedisCoordinationStore( $connection->evaluate( ... ), $scope );
	}

	public static function createConnection(): RedisConnection {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$redisConfig = $config->get( 'FrauxSearchCoordinationRedis' );
		if ( $redisConfig === null ) {
			$cacheType = $config->get( 'FrauxSearchCoordinationCacheType' ) ?? $config->get( 'MainCacheType' );
			$objectCaches = $config->get( 'ObjectCaches' );
			if ( !( is_int( $cacheType ) || is_string( $cacheType ) )
				|| !is_array( $objectCaches ) || !isset( $objectCaches[$cacheType] )
				|| !is_array( $objectCaches[$cacheType] )
			) {
				throw new RuntimeException( 'FrauxSearch indexing requires a configured Redis object cache; no SQL fallback is used.' );
			}
			$redisConfig = RedisConnection::configFromObjectCache( $objectCaches[$cacheType] );
		}
		if ( !is_array( $redisConfig ) ) {
			throw new RuntimeException( 'FrauxSearchCoordinationRedis must be null or an explicit Redis configuration.' );
		}
		return RedisConnection::connect( $redisConfig );
	}
}

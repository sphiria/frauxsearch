<?php

namespace FrauxSearch;

use Closure;
use RuntimeException;

class DocumentBatchBuilder {
	public static bool $available = true;
	public static ?int $failPage = null;
	public static array $calls = [];
	public static array $preflightOptions = [];

	public static function assertAvailable( array $options = [] ): void {
		self::$preflightOptions = $options;
		IndexCoordinatorFactory::$events[] = [ 'preflight' ];
		if ( !self::$available ) { throw new RuntimeException( 'Renderer unavailable.' ); }
	}

	public static function build( array $ids, array $options, ?Closure $heartbeat = null ): array {
		self::$calls[] = [ $ids, $options, $heartbeat !== null ];
		$heartbeat?->__invoke();
		if ( in_array( self::$failPage, $ids, true ) ) { throw new RuntimeException( 'Child failed.' ); }
		return array_combine( $ids, array_map( static fn ( int $id ) =>
			[ 'document' => [ 'id' => $id ], 'is_redirect' => false ], $ids ) );
	}
}

class IndexCoordinatorFactory {
	public static array $events = [];
	public static ?\Throwable $heartbeatFailure = null;

	public static function create( ?string $index = null, int $timeout = 5, ?Closure $build = null ): object {
		self::$events[] = [ 'factory', $index, $timeout, $build !== null ];
		return new class( $build ) {
			public function __construct( private ?Closure $build ) {}
			public function refresh( int $id ): void { $this->render( 'refresh', $id ); }
			public function drain(): void { $this->render( 'drain', 7 ); }
			public function finishRebuild( string $id ): void { $this->render( 'finish', 7, $id ); }
			public function abortRebuild( string $id ): void { $this->render( 'abort', 7, $id ); }
			public function adoptTask( string $id, int $task ): void {
				IndexCoordinatorFactory::$events[] = [ 'adopt', $id, $task ];
			}
			public function confirmNotSubmitted( string $id ): void {
				IndexCoordinatorFactory::$events[] = [ 'confirm', $id ];
			}
			public function status(): array {
				IndexCoordinatorFactory::$events[] = [ 'status' ];
				return [ 'run' => null, 'pending' => null ];
			}
			private function render( string $action, int $id, ?string $run = null ): void {
				IndexCoordinatorFactory::$events[] = [ $action, $id, $run ];
				$built = ( $this->build )( $id, static function (): void {
					IndexCoordinatorFactory::$events[] = [ 'heartbeat' ];
					if ( IndexCoordinatorFactory::$heartbeatFailure !== null ) {
						throw IndexCoordinatorFactory::$heartbeatFailure;
					}
				} );
				IndexCoordinatorFactory::$events[] = [ 'write', $built['document']['id'] ];
			}
		};
	}
}

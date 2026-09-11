<?php

namespace FrauxSearch;

interface CoordinationStore {
	public function lock( int $timeout = 0 ): bool;
	public function unlock(): void;
	public function assertLocked(): void;
	public function initialize( string $epoch, ?array $run = null ): void;
	public function readState(): ?array;
	public function writeState( array $state ): void;
	public function append( int $pageId, ?string $title, bool $completionOnly ): ?int;
	public function seal(): bool;
	public function retire(): void;
	public function discard(): void;
	public function watermark(): int;
	/** @return int[] */
	public function pages( int $afterPageId, int $through, bool $pendingOnly ): array;
	/** @return array<int,array{title:string,completionOnly:bool,done:bool}> */
	public function entries( int $pageId, int $through ): array;
	public function markDone( int $pageId, array $entryIds ): void;
	public function prune( int $through ): void;
}

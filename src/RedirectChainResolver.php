<?php

namespace FrauxSearch;

class RedirectChainResolver {
	public static function resolve( int $pageId, callable $nextTarget, int $maxHops = 10 ): ?int {
		$visited = [ $pageId => true ];
		$current = $pageId;
		for ( $hop = 0; $hop <= $maxHops; $hop++ ) {
			$next = $nextTarget( $current );
			if ( $next === null ) {
				return $current === $pageId ? null : $current;
			}
			if ( $next <= 0 || $hop === $maxHops || isset( $visited[$next] ) ) {
				return null;
			}
			$visited[$next] = true;
			$current = $next;
		}
		return null;
	}
}

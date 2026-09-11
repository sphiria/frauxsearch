<?php

if ( PHP_SAPI !== 'cli' || !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

$frauxLifecycleBaseConf = getenv( 'FRAUXSEARCH_TEST_BASE_CONF' );
$frauxLifecycleRun = getenv( 'FRAUXSEARCH_TEST_RUN' );
if ( !is_string( $frauxLifecycleBaseConf ) || !is_file( $frauxLifecycleBaseConf )
	|| !is_string( $frauxLifecycleRun ) || !preg_match( '/^[a-f0-9]{12}$/D', $frauxLifecycleRun )
) {
	throw new RuntimeException( 'Set FRAUXSEARCH_TEST_BASE_CONF and a 12-hex FRAUXSEARCH_TEST_RUN.' );
}
require $frauxLifecycleBaseConf;
require_once __DIR__ . '/LifecycleParserCache.php';

$wgFrauxSearchLifecycleOriginalIndex = $wgFrauxSearchIndex;
if ( !is_string( $wgFrauxSearchLifecycleOriginalIndex )
	|| !preg_match( '/^[A-Za-z0-9_-]+$/D', $wgFrauxSearchLifecycleOriginalIndex )
) {
	throw new RuntimeException( 'The base configuration must supply a valid index.' );
}
$wgFrauxSearchCoordinationCacheType ??= $wgMainCacheType;
$wgFrauxSearchIndex = $wgFrauxSearchLifecycleOriginalIndex . '_lifecycle_test_' . $frauxLifecycleRun;
$wgFrauxSearchLifecycleQueuePrefix = 'fslife_' . $frauxLifecycleRun . '_';
$wgFrauxSearchLifecycleQueueServer = ( $wgDBservers[0] ?? [] ) + [
	'type' => $wgDBtype, 'host' => $wgDBserver, 'user' => $wgDBuser,
	'password' => $wgDBpassword, 'dbname' => $wgDBname, 'ssl' => $wgDBssl,
];
$wgFrauxSearchLifecycleQueueServer['flags'] = 0;
$wgFrauxSearchLifecycleQueueServer['tablePrefix'] = $wgFrauxSearchLifecycleQueuePrefix;
$wgFrauxSearchLifecycleQueueBackend = getenv( 'FRAUXSEARCH_TEST_REDIS_SERVER' ) ? 'redis' : 'sql';
if ( $wgFrauxSearchLifecycleQueueBackend === 'redis' ) {
	if ( getenv( 'FRAUXSEARCH_TEST_REDIS_SERVER' ) !== '127.0.0.1:6389' ) {
		throw new RuntimeException( 'Redis lifecycle tests require their private loopback sidecar on port 6389.' );
	}
	$wgJobTypeConf = [ 'default' => [
		'class' => MediaWiki\JobQueue\JobQueueRedis::class,
		'redisServer' => '127.0.0.1:6389', 'redisConfig' => [], 'daemonized' => true, 'order' => 'fifo',
	] ];
} else {
	$wgJobTypeConf = [ 'default' => [
		'class' => MediaWiki\JobQueue\JobQueueDB::class,
		'server' => $wgFrauxSearchLifecycleQueueServer,
		'order' => 'fifo',
	] ];
}
$wgJobRunRate = 0;
$wgEnableEmail = false;
$wgEnableUserEmail = false;
$wgEnotifUserTalk = false;
$wgEnotifWatchlist = false;
$wgRCFeeds = [];
$wgPingback = false;
$wgCheckUserSuggestedInvestigationsEnabled = false;
$wgCheckUserWriteToCentralIndex = false;
$wgMainCacheType = CACHE_NONE;
$wgMainStash = CACHE_NONE;
$wgObjectCaches['frauxsearch-lifecycle-parser'] = [ 'class' => FrauxSearch\Integration\LifecycleParserCache::class ];
$wgParserCacheType = 'frauxsearch-lifecycle-parser';
$wgMainWANCache = 'frauxsearch-lifecycle';
$wgWANObjectCaches['frauxsearch-lifecycle'] = [ 'class' => Wikimedia\ObjectCache\WANObjectCache::class,
	'cacheId' => CACHE_NONE ];
$wgLocalDatabases = [];

$GLOBALS['frauxSearchLifecycleHooks'] = [];
foreach ( [ 'PageSaveComplete', 'PageMoveComplete', 'PageDeleteComplete', 'PageUndeleteComplete', 'AfterImportPage' ] as $frauxLifecycleHook ) {
	$wgHooks[$frauxLifecycleHook][] = static function ( ...$args ) use ( $frauxLifecycleHook ): void {
		$GLOBALS['frauxSearchLifecycleHooks'][] = $frauxLifecycleHook;
	};
}
$GLOBALS['frauxSearchLifecycleImports'] = [];
$wgHooks['AfterImportPage'][] = static function ( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ): void {
	$GLOBALS['frauxSearchLifecycleImports'][] = [ 'local_title' => $title->getPrefixedDBkey(),
		'successful_revisions' => $sRevCount, 'foreign_page_id' => $pageInfo['id'] ?? null ];
};

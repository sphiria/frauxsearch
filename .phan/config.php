<?php

$mediaWiki = getenv( 'MW_INSTALL_PATH' );
if ( !$mediaWiki || !is_file( $mediaWiki . '/includes/MediaWikiServices.php' )
	|| !is_file( $mediaWiki . '/vendor/autoload.php' )
) {
	throw new RuntimeException( 'Set MW_INSTALL_PATH to MediaWiki 1.46 with its vendor dependencies installed.' );
}

$config = require dirname( __DIR__ ) . '/vendor/mediawiki/mediawiki-phan-config/src/config.php';
$config['minimum_target_php_version'] = '8.3';
$config['target_php_version'] = '8.3';
if ( !extension_loaded( 'redis' ) ) {
	$config['file_list'][] = '.phan/redis.php';
}
$config['exclude_analysis_directory_list'][] = 'tests';
return $config;

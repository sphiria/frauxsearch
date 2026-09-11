<?php

use FrauxSearch\DocumentHash;
use FrauxSearch\Tests\BatchServices;
use MediaWiki\MediaWikiServices;

require dirname( __DIR__, 4 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/mediawiki/maintenance/Maintenance.php';
require dirname( __DIR__ ) . '/services.php';
MediaWikiServices::$instance = new BatchServices();
$request = json_decode( stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
$mode = getenv( 'FRAUX_BATCH_TEST_MODE' ) ?: 'valid';
$evidence = [ 'pid' => getmypid(), 'argv' => $argv, 'request' => $request,
	'permissions' => fileperms( $request['result'] ) & 0777, 'binary' => PHP_BINARY ];
file_put_contents( getenv( 'FRAUX_BATCH_TEST_LOG' ), json_encode( $evidence ) . "\n", FILE_APPEND | LOCK_EX );
if ( $mode === 'parallel' ) {
	$event = static function ( string $phase ) use ( $request ): void {
		file_put_contents( getenv( 'FRAUX_BATCH_TEST_LOG' ) . '.events',
			json_encode( [ 'phase' => $phase, 'id' => $request['ids'][0], 'time' => hrtime( true ) ] ) . "\n",
			FILE_APPEND | LOCK_EX );
	};
	$event( 'start' );
	$deadline = hrtime( true ) + 3000000000;
	while ( count( file( getenv( 'FRAUX_BATCH_TEST_LOG' ) ) ) < 2 ) {
		if ( hrtime( true ) > $deadline ) { exit( 24 ); }
		usleep( 10000 );
	}
	usleep( $request['ids'][0] === 1 ? 400000 : 50000 );
	$event( 'end' );
}
if ( $mode === 'parallel-failure' ) {
	if ( $request['ids'][0] === 1 ) { sleep( 60 ); }
	usleep( 300000 );
	exit( 23 );
}
if ( $mode === 'wait' ) { sleep( 60 ); }
if ( $mode === 'slow' ) { usleep( 1300000 ); }
if ( $mode === 'killed' ) { exit( 137 ); }
if ( $mode === 'stderr' ) { fwrite( STDERR, str_repeat( 'private-source-detail ', 10000 ) ); exit( 23 ); }

require $argv[1];
$worker = new class( $request ) extends \FrauxSearch\Maintenance\RenderFrauxSearchBatch {
	public function __construct( private array $request ) { parent::__construct(); }
	public function getStdin() {
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, json_encode( $this->request, JSON_THROW_ON_ERROR ) );
		rewind( $stream );
		return $stream;
	}
};
$worker->execute();
if ( $mode === 'exit-failure' || ( $mode === 'second-failure' && $request['ids'][0] > 100 ) ) { exit( 23 ); }
$path = $request['result'];
$data = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
switch ( $mode ) {
	case 'truncated': file_put_contents( $path, '{"version":' ); exit( 0 );
	case 'oversize':
		$handle = fopen( $path, 'r+b' );
		ftruncate( $handle, 67108865 );
		fclose( $handle );
		exit( 0 );
	case 'missing': unset( $data['results'][2] ); break;
	case 'extra': $data['results'][999] = null; break;
	case 'nonce': $data['nonce'] = str_repeat( '0', 32 ); break;
	case 'source': $data['source'] = 'another-wiki'; break;
	case 'ids': $data['ids'] = array_reverse( $data['ids'] ); break;
	case 'hash': $data['results'][1]['document']['title'] = 'Corruption'; break;
	case 'partial':
		unset( $data['results'][1]['document']['text'] );
		$data['results'][1]['document']['document_hash'] = DocumentHash::compute( $data['results'][1]['document'] );
		break;
	case 'redirect': $data['results'][3]['redirect_target_id'] = '4'; break;
}
file_put_contents( $path, json_encode( $data, JSON_THROW_ON_ERROR ) );

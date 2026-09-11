<?php

use FrauxSearch\FrauxSearchEngine;
use FrauxSearch\FrauxSearchResultSet;
use MediaWiki\Search\SearchEngine;
use MediaWiki\Status\Status;

require_once $mediaWiki . '/includes/Defines.php';

$engine = ( new ReflectionClass( FrauxSearchEngine::class ) )->newInstanceWithoutConstructor();
$engine->setLimitOffset( 2, 1 );
foreach ( [
	[ '"unfinished', 'frauxsearch-query-unclosed-quote', [] ],
	[ 'Narmaya OR Gran', 'frauxsearch-query-unsupported-operator', [ 'OR' ] ],
	[ '""', 'frauxsearch-query-empty-clause', [] ],
	[ "Invalid\xFFtext", 'frauxsearch-query-invalid-encoding', [] ],
] as [ $term, $key, $parameters ] ) {
	$status = $engine->searchText( $term );
	if ( !$status instanceof Status || $status->isGood()
		|| $status->getErrors() !== [ [ 'type' => 'error', 'message' => $key, 'params' => $parameters ] ]
		|| $engine->searchTitle( $term ) !== null
	) {
		throw new RuntimeException( 'Native MediaWiki search wrappers did not preserve syntax errors.' );
	}
}
if ( SearchEngine::parseNamespacePrefixes( 'Narmaya', false, false ) !== false ) {
	throw new RuntimeException( 'Core namespace parsing changed for a plain title.' );
}
foreach ( [ false, true ] as $syntax ) {
	if ( ( new FrauxSearchResultSet( [], 0, false, false, $syntax ) )->searchContainedSyntax() !== $syntax ) {
		throw new RuntimeException( 'Native result sets lost the contained-syntax flag.' );
	}
}
fwrite( STDOUT, "Core search wrappers preserve syntax Status/title null and result-set syntax flags.\n" );

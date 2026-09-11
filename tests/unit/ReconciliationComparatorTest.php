<?php

namespace FrauxSearch\Tests;

use FrauxSearch\ReconciliationComparator;
use FrauxSearch\DocumentHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReconciliationComparatorTest extends TestCase {
	public function testCurrentCanonicalDocumentHasNoIssues(): void {
		$built = $this->built( false );
		$this->assertSame( [], ReconciliationComparator::compare(
			$built,
			$built['document'],
			$built['document']
		) );
	}

	public function testMissingAndStaleDocumentsAreReported(): void {
		$built = $this->built( false );
		$this->assertSame(
			[ 'full_text_missing', 'completion_stale' ],
			ReconciliationComparator::compare( $built, null, [
				'id' => 1,
				'revision_id' => 9,
				'document_hash' => 'old',
			] )
		);
	}

	public function testRedirectInCompletionIsUnexpected(): void {
		$built = $this->built( true );
		$this->assertSame(
			[ 'completion_unexpected' ],
			ReconciliationComparator::compare( $built, $built['document'], $built['document'] )
		);
	}

	public function testLegacyDocumentWithoutAuditFieldsIsStale(): void {
		$built = $this->built( false );
		$this->assertSame(
			[ 'full_text_stale', 'completion_stale' ],
			ReconciliationComparator::compare( $built, [ 'id' => 1 ], [ 'id' => 1 ] )
		);
	}

	public function testUnbuildableSourceReportsUnexpectedDocuments(): void {
		$this->assertSame(
			[ 'source_unbuildable', 'full_text_unexpected', 'completion_unexpected' ],
			ReconciliationComparator::compare( null, [ 'id' => 1 ], [ 'id' => 1 ] )
		);
	}

	public function testUnbuildableSourceAloneDoesNotQueueAnUnrepairableJob(): void {
		$this->assertFalse( ReconciliationComparator::needsRepair(
			ReconciliationComparator::compare( null, null, null )
		) );
		$this->assertTrue( ReconciliationComparator::needsRepair(
			ReconciliationComparator::compare( null, [ 'id' => 1 ], null )
		) );
		$this->assertFalse( ReconciliationComparator::needsRepair( [] ) );
	}

	public static function corruptions(): array {
		return [ 'changed text' => [ 'text', 'corrupt' ], 'changed derived count' => [ 'incoming_links', 900 ],
			'removed array entries' => [ 'outgoing_link_ids', [] ], 'wrong scalar type' => [ 'boost', '100' ],
			'extra field' => [ 'foreign_field', 'unexpected' ], 'missing field' => [ 'text', null ] ];
	}

	#[DataProvider( 'corruptions' )]
	public function testUnchangedStoredHashCannotHideAlteredPayload( string $field, $value ): void {
		$built = $this->built( false );
		$actual = $built['document'];
		if ( $value === null ) { unset( $actual[$field] ); } else { $actual[$field] = $value; }
		$this->assertSame( [ 'full_text_stale', 'completion_stale' ],
			ReconciliationComparator::compare( $built, $actual, $actual ) );
	}

	public function testRemoteObjectKeyOrderDoesNotRequireReindexing(): void {
		$built = $this->built( false );
		$actual = array_reverse( $built['document'], true );
		$this->assertSame( [], ReconciliationComparator::compare( $built, $actual, $actual ) );
	}

	public function testHasherPreservesExistingBuilderFormatAndExcludesTheHashField(): void {
		$document = [ 'id' => 1, 'title' => 'École / Example', 'outgoing_link_ids' => [ 7, 8 ] ];
		$legacy = hash( 'sha256', json_encode( $document,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( $legacy, DocumentHash::compute( $document + [ 'document_hash' => 'ignored' ] ) );
	}

	private function built( bool $isRedirect ): array {
		$document = [ 'id' => 1, 'revision_id' => 10, 'title' => 'Example', 'text' => 'Current text',
			'boost' => 100, 'incoming_links' => 1, 'outgoing_link_ids' => [ 7, 8 ], 'redirects' => [] ];
		$document['document_hash'] = DocumentHash::compute( $document );
		return [
			'document' => $document,
			'is_redirect' => $isRedirect,
		];
	}
}

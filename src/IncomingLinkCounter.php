<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;

class IncomingLinkCounter {
	public function __construct( private bool $readLatest = false ) {
	}

	/** @return array<int,int> */
	public function getCounts( array $pageIds ): array {
		$pageIds = array_values( array_unique( array_filter( array_map( 'intval', $pageIds ) ) ) );
		if ( $pageIds === [] ) {
			return [];
		}
		$counts = array_fill_keys( $pageIds, 0 );
		$provider = MediaWikiServices::getInstance()->getConnectionProvider();
		$database = $this->readLatest ? $provider->getPrimaryDatabase() : $provider->getReplicaDatabase();
		$rows = $database->newSelectQueryBuilder()
			->select( [ 'target.page_id', 'incoming_links' => 'COUNT(DISTINCT pl_from)' ] )
			->from( 'page', 'target' )
			->join( 'linktarget', null, [
				'lt_namespace = target.page_namespace',
				'lt_title = target.page_title',
			] )
			->join( 'pagelinks', null, 'pl_target_id = lt_id' )
			->where( [ 'target.page_id' => $pageIds ] )
			->groupBy( 'target.page_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $rows as $row ) {
			$counts[(int)$row->page_id] = (int)$row->incoming_links;
		}
		return $counts;
	}

	/** @return array<int,int[]> */
	public function getOutgoingTargetIds( array $pageIds ): array {
		$pageIds = array_values( array_unique( array_filter( array_map( 'intval', $pageIds ) ) ) );
		if ( $pageIds === [] ) {
			return [];
		}
		$targets = array_fill_keys( $pageIds, [] );
		$provider = MediaWikiServices::getInstance()->getConnectionProvider();
		$database = $this->readLatest ? $provider->getPrimaryDatabase() : $provider->getReplicaDatabase();
		$rows = $database->newSelectQueryBuilder()
			->select( [ 'pl_from', 'target_id' => 'target.page_id' ] )
			->from( 'pagelinks' )
			->join( 'linktarget', null, 'pl_target_id = lt_id' )
			->join( 'page', 'target', [
				'target.page_namespace = lt_namespace',
				'target.page_title = lt_title',
			] )
			->where( [ 'pl_from' => $pageIds ] )
			->straightJoinOption()
			->orderBy( [ 'pl_from', 'target.page_id' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $rows as $row ) {
			$targets[(int)$row->pl_from][] = (int)$row->target_id;
		}
		foreach ( $targets as &$targetIds ) {
			$targetIds = array_values( array_unique( $targetIds ) );
		}
		return $targets;
	}
}

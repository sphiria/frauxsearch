<?php

namespace FrauxSearch;

use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Import\Hook\AfterImportPageHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

class TargetIdentityHooks implements PageSaveCompleteHook, PageDeleteCompleteHook,
	PageMoveCompleteHook, PageUndeleteCompleteHook, AfterImportPageHook
{
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( $editResult->isNew() ) {
			$this->schedule( [ $wikiPage->getTitle() ] );
		}
	}

	public function onPageDeleteComplete( $page, $deleter, $reason, $pageID, $deletedRev,
		$logEntry, $archivedRevisionCount
	) {
		$this->schedule( [ $page ] );
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$this->schedule( [ $old, $new ] );
	}

	public function onPageUndeleteComplete( $page, $restorer, $reason, $restoredRev, $logEntry,
		$restoredRevisionCount, $created, $restoredPageIds
	): void {
		$this->schedule( [ $page ] );
	}

	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ) {
		if ( $sRevCount > 0 ) {
			$this->schedule( [ $title ] );
		}
	}

	private function schedule( array $pages ): void {
		$targets = [];
		foreach ( $pages as $page ) {
			$namespace = $page->getNamespace();
			$title = $page->getDBkey();
			$targets[$namespace . ':' . $title] = [ 'namespace' => $namespace, 'title' => $title ];
		}
		$event = bin2hex( random_bytes( 16 ) );
		$jobs = array_map( static fn ( array $target ) => new ScheduleIncomingRefreshesJob(
			$target + [ 'identityEvent' => $event ]
		), array_values( $targets ) );
		$services = MediaWikiServices::getInstance();
		$services->getConnectionProvider()->getPrimaryDatabase()->onTransactionCommitOrIdle(
			static function () use ( $services, $jobs, $targets ): void {
				$services->getJobQueueGroup()->push( $jobs );
				wfDebugLog( 'FrauxSearch', json_encode( [
					'event' => 'incoming_refresh_scheduler_queued',
					'targets' => array_values( $targets ),
				], JSON_UNESCAPED_SLASHES ) );
			}, __METHOD__
		);
	}
}

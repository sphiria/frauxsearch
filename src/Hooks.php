<?php

namespace FrauxSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\Deferred\Hook\LinksUpdateCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\MultiContentSaveHook;

class Hooks implements LinksUpdateCompleteHook, PageDeleteCompleteHook, MultiContentSaveHook {
	public function onMultiContentSave(
		$renderedRevision,
		$user,
		$summary,
		$flags,
		$status
	) {
		$plannedRevision = $renderedRevision->getRevision();
		$pageId = $plannedRevision->getPageId();
		if ( $pageId > 0 ) {
			$stash = MediaWikiServices::getInstance()->getMainObjectStash();
			$parentId = (int)$plannedRevision->getParentId();
			$stateKey = $this->saveStateCacheKey( $stash, $pageId, $parentId );
			$oldTargetId = ( new DocumentBuilder( true ) )->getRedirectTargetId( $pageId );
			$stash->set(
				$stateKey,
				[ 'oldTargetId' => $oldTargetId ],
				300
			);
		}
	}

	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$services = MediaWikiServices::getInstance();
		$title = $services->getTitleFactory()->newFromPageReference( $page )->getPrefixedText();
		$this->enqueueRefresh( $pageID, $title );
	}

	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		$pageId = (int)$linksUpdate->getPageId();
		if ( $pageId <= 0 ) {
			return;
		}

		$targetId = ( new DocumentBuilder( true ) )->getRedirectTargetId( $pageId );
		$revision = $linksUpdate->getRevisionRecord();
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		$stateKey = $revision === null ? null : $this->saveStateCacheKey(
			$stash,
			$pageId,
			(int)$revision->getParentId()
		);
		$saveState = $stateKey === null ? false : $stash->get( $stateKey );
		$oldTargetId = is_array( $saveState ) && isset( $saveState['oldTargetId'] )
			? (int)$saveState['oldTargetId']
			: null;
		$refreshIds = [ $pageId ];
		if ( $oldTargetId !== null && $oldTargetId !== $targetId ) {
			$refreshIds[] = $oldTargetId;
		}
		if ( $targetId !== null ) {
			$refreshIds[] = $pageId;
			$refreshIds[] = $targetId;
		}
		$this->enqueuePageRefreshes( $refreshIds );

		if ( $stateKey !== null ) {
			$stash->delete( $stateKey );
		}
	}

	private function enqueueRefresh( int $pageId, ?string $redirectTitle = null ): void {
		$services = MediaWikiServices::getInstance();
		$params = [ 'pageId' => $pageId, 'sourceEvent' => bin2hex( random_bytes( 16 ) ) ];
		if ( $redirectTitle !== null ) {
			$params['redirectTitle'] = $redirectTitle;
		}
		$job = new RefreshPageJob( $params );
		$services->getConnectionProvider()->getPrimaryDatabase()->onTransactionCommitOrIdle(
			static function () use ( $services, $job, $pageId ) {
				$services->getJobQueueGroup()->push( $job );
				wfDebugLog( 'FrauxSearch', json_encode( [
					'event' => 'refresh_queued',
					'page_id' => $pageId,
					'operation' => 'refresh',
				], JSON_UNESCAPED_SLASHES ) );
			},
			__METHOD__
		);
	}

	private function enqueuePageRefreshes( array $pageIds ): void {
		$pageIds = array_values( array_unique( array_filter( array_map( 'intval', $pageIds ) ) ) );
		if ( $pageIds === [] ) {
			return;
		}
		$services = MediaWikiServices::getInstance();
		$event = bin2hex( random_bytes( 16 ) );
		$jobs = array_map(
			static fn ( int $pageId ) => new RefreshPageJob( [ 'pageId' => $pageId, 'sourceEvent' => $event ] ),
			$pageIds
		);
		$services->getConnectionProvider()->getPrimaryDatabase()->onTransactionCommitOrIdle(
			static function () use ( $services, $jobs, $pageIds ) {
				$services->getJobQueueGroup()->push( $jobs );
				wfDebugLog( 'FrauxSearch', json_encode( [
					'event' => 'refresh_batch_queued',
					'page_ids' => $pageIds,
					'operation' => 'refresh',
				], JSON_UNESCAPED_SLASHES ) );
			},
			__METHOD__
		);
	}

	private function saveStateCacheKey( $stash, int $pageId, int $parentId ): string {
		return $stash->makeKey( 'frauxsearch', 'save-state', (string)$pageId, (string)$parentId );
	}

}

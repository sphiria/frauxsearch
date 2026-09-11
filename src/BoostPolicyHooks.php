<?php

namespace FrauxSearch;

use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Import\Hook\AfterImportPageHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Rdbms\IDBAccessObject;

class BoostPolicyHooks implements PageSaveCompleteHook, PageDeleteCompleteHook,
	PageMoveCompleteHook, PageUndeleteCompleteHook, AfterImportPageHook
{
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( !$this->isPolicy( $wikiPage->getTitle() ) ) {
			return;
		}
		$parentId = (int)$revisionRecord->getParentId();
		$old = $parentId > 0 ? MediaWikiServices::getInstance()->getRevisionLookup()
			->getRevisionById( $parentId, IDBAccessObject::READ_LATEST ) : null;
		$this->schedule( $parentId > 0 ? $this->rules( $old ) : [], $this->rules( $revisionRecord ) );
	}

	public function onPageDeleteComplete( $page, $deleter, $reason, $pageID, $deletedRev,
		$logEntry, $archivedRevisionCount
	) {
		if ( $this->isPolicy( $page ) ) {
			$this->schedule( $this->rules( $deletedRev ), [] );
		}
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		if ( $this->isPolicy( $old ) ) {
			$this->schedule( $this->rules( $revision ), [] );
		} elseif ( $this->isPolicy( $new ) ) {
			$this->schedule( null, $this->rules( $revision ) );
		}
	}

	public function onPageUndeleteComplete( $page, $restorer, $reason, $restoredRev, $logEntry,
		$restoredRevisionCount, $created, $restoredPageIds
	): void {
		if ( $this->isPolicy( $page ) ) {
			$this->schedule( $created ? [] : null, $this->rules( $restoredRev ) );
		}
	}

	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ) {
		if ( $sRevCount > 0 && $this->isPolicy( $title ) ) {
			$this->schedule( null, null );
		}
	}

	private function isPolicy( $page ): bool {
		return $page->getNamespace() === NS_MEDIAWIKI
			&& $page->getDBkey() === 'Frauxsearch-boost-templates';
	}

	private function rules( $revision ): ?array {
		$content = $revision?->getContent( SlotRecord::MAIN );
		return $content === null ? null : BoostConfigParser::parse( $content->getTextForSearchIndex() );
	}

	private function schedule( ?array $old, ?array $new ): void {
		if ( $old === null || $new === null ) {
			$allPages = true;
			$templates = [];
		} else {
			$allPages = false;
			$templates = BoostConfigParser::changedTemplates( $old, $new );
		}
		if ( !$allPages && $templates === [] ) {
			return;
		}
		$services = MediaWikiServices::getInstance();
		$job = new ScheduleBoostRefreshesJob( [
			'templates' => $templates,
			'allPages' => $allPages,
			'policyEvent' => bin2hex( random_bytes( 16 ) ),
		] );
		$services->getConnectionProvider()->getPrimaryDatabase()->onTransactionCommitOrIdle(
			static function () use ( $services, $job, $templates, $allPages ): void {
				$services->getJobQueueGroup()->push( $job );
				wfDebugLog( 'FrauxSearch', json_encode( [
					'event' => 'boost_refresh_scheduler_queued',
					'templates' => $templates, 'all_pages' => $allPages,
				], JSON_UNESCAPED_SLASHES ) );
			}, __METHOD__
		);
	}
}

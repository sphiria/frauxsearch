<?php

namespace FrauxSearch;

use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Rdbms\IDBAccessObject;

class DocumentBuilder {
	private const BOOST_CONFIG_CACHE_VERSION = 2;
	private ?array $boostConfig = null;
	public function __construct(
		private bool $readLatest = false,
		private ?array $incomingLinkCounts = null,
		private ?array $outgoingLinkIds = null
	) {
	}

	public function build( int $pageId ): ?array {
		$services = MediaWikiServices::getInstance();
		$page = $services->getWikiPageFactory()->newFromID( $pageId, IDBAccessObject::READ_LATEST );
		$revision = $page?->getRevisionRecord();
		$content = $revision?->getContent( SlotRecord::MAIN );
		$title = $page?->getTitle();
		if ( $revision === null || $content === null || $title === null ) {
			return null;
		}

		$text = $content instanceof WikitextContent
			? SearchTextExtractor::extract( $content, $page, $revision )
			: $content->getTextForSearchIndex();
		$document = [
			'id' => $pageId,
			'revision_id' => (int)$revision->getId(),
			'title' => $title->getPrefixedText(),
			'redirects' => $this->loadRedirects( $pageId ),
			'namespace' => $title->getNamespace(),
			'incoming_links' => $this->loadIncomingLinks( $pageId ),
			'outgoing_link_ids' => $this->loadOutgoingLinkIds( $pageId ),
			'boost' => $this->loadBoost( $pageId ),
			'text' => $text,
			'timestamp' => $revision->getTimestamp(),
			'word_count' => str_word_count( $text ),
			'byte_size' => strlen( $text ),
		];
		$document['document_hash'] = DocumentHash::compute( $document );
		return [
			'document' => $document,
			'is_redirect' => $page->isRedirect(),
			'redirect_target_id' => $this->getRedirectTargetId( $pageId ),
		];
	}

	public function loadBoostConfig(): array {
		if ( $this->boostConfig !== null ) {
			return $this->boostConfig;
		}
		$services = MediaWikiServices::getInstance();
		$policy = $services->getPageStore()->getPageByName(
			NS_MEDIAWIKI, 'Frauxsearch-boost-templates', IDBAccessObject::READ_LATEST
		);
		if ( $policy === null ) {
			return $this->boostConfig = [];
		}
		$revision = $services->getRevisionLookup()->getRevisionById(
			$policy->getLatest(), IDBAccessObject::READ_LATEST
		);
		if ( $revision === null ) {
			throw new \RuntimeException( 'Unable to read the current FrauxSearch boost policy revision.' );
		}
		$stash = $services->getMainObjectStash();
		$cacheKey = $stash->makeKey( 'frauxsearch', 'boost-config',
			(string)self::BOOST_CONFIG_CACHE_VERSION, (string)$revision->getId() );
		$cached = $stash->get( $cacheKey );
		if ( is_array( $cached ) ) {
			return $this->boostConfig = $cached;
		}
		$content = $revision->getContent( SlotRecord::MAIN );
		if ( $content === null ) {
			throw new \RuntimeException( 'Unable to read the current FrauxSearch boost policy content.' );
		}
		$parsed = BoostConfigParser::parseWithWarnings( $content->getTextForSearchIndex() );
		foreach ( $parsed['warnings'] as $warning ) {
			wfDebugLog( 'FrauxSearch', json_encode( [
				'event' => 'invalid_boost_rule', 'warning' => $warning,
			], JSON_UNESCAPED_SLASHES ) );
		}
		$stash->set( $cacheKey, $parsed['rules'], 3600 );
		return $this->boostConfig = $parsed['rules'];
	}

	private function loadRedirects( int $pageId ): array {
		$services = MediaWikiServices::getInstance();
		$db = $this->getDatabase();
		$redirects = [];
		$targets = [ $pageId ];
		$visited = [ $pageId => true ];
		for ( $hop = 0; $hop < 10 && $targets !== []; $hop++ ) {
			$rows = $db->newSelectQueryBuilder()
				->select( [ 'source.page_id', 'source.page_namespace', 'source.page_title' ] )
				->from( 'page', 'target' )
				->join( 'redirect', null, [
					'rd_namespace = target.page_namespace',
					'rd_title = target.page_title',
					"(rd_interwiki IS NULL OR rd_interwiki = '')",
				] )
				->join( 'page', 'source', 'source.page_id = rd_from' )
				->where( [ 'target.page_id' => $targets ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			$targets = [];
			foreach ( $rows as $row ) {
				$sourceId = (int)$row->page_id;
				if ( isset( $visited[$sourceId] ) ) {
					continue;
				}
				$visited[$sourceId] = true;
				$targets[] = $sourceId;
				$redirects[] = $services->getTitleFactory()->makeTitle(
					(int)$row->page_namespace,
					(string)$row->page_title
				)->getPrefixedText();
			}
		}
		sort( $redirects, SORT_NATURAL | SORT_FLAG_CASE );
		return $redirects;
	}

	private function loadBoost( int $pageId ): int {
		$templateBoosts = $this->loadBoostConfig();
		if ( $templateBoosts === [] ) {
			return 100;
		}
		$rows = $this->getDatabase()
			->newSelectQueryBuilder()
			->select( [ 'lt_title' ] )
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'tl_target_id = lt_id' )
			->where( [
				'tl_from' => $pageId,
				'lt_namespace' => NS_TEMPLATE,
				'lt_title' => array_keys( $templateBoosts ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return BoostConfigParser::calculateBoost( $templateBoosts, $rows );
	}

	private function loadIncomingLinks( int $pageId ): int {
		if ( $this->incomingLinkCounts !== null ) {
			return (int)( $this->incomingLinkCounts[$pageId] ?? 0 );
		}
		$counts = ( new IncomingLinkCounter( $this->readLatest ) )->getCounts( [ $pageId ] );
		return (int)( $counts[$pageId] ?? 0 );
	}

	private function loadOutgoingLinkIds( int $pageId ): array {
		if ( $this->outgoingLinkIds !== null ) {
			return $this->outgoingLinkIds[$pageId] ?? [];
		}
		$targets = ( new IncomingLinkCounter( $this->readLatest ) )->getOutgoingTargetIds( [ $pageId ] );
		return $targets[$pageId] ?? [];
	}

	public function getRedirectTargetId( int $pageId ): ?int {
		return RedirectChainResolver::resolve(
			$pageId,
			fn ( int $sourceId ) => $this->getDirectRedirectTargetId( $sourceId )
		);
	}

	private function getDirectRedirectTargetId( int $pageId ): ?int {
		$db = $this->getDatabase();
		$targetId = $db->newSelectQueryBuilder()
			->select( [ 'target.page_id' ] )
			->from( 'redirect' )
			->leftJoin( 'page', 'target', [
				'target.page_namespace = rd_namespace',
				'target.page_title = rd_title',
				"(rd_interwiki IS NULL OR rd_interwiki = '')",
			] )
			->where( [ 'rd_from' => $pageId ] )
			->caller( __METHOD__ )
			->fetchField();
		return $targetId === false ? null : (int)$targetId;
	}

	private function getDatabase() {
		$provider = MediaWikiServices::getInstance()->getConnectionProvider();
		return $this->readLatest ? $provider->getPrimaryDatabase() : $provider->getReplicaDatabase();
	}
}

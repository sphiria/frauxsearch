<?php

namespace MediaWiki\JobQueue;

interface GenericParameterJob {}
class Job {
	public const JOB_NO_EXPLICIT_TRX_ROUND = 1;
	protected bool $removeDuplicates = false;
	protected int $executionFlags = 0;
	protected ?string $error = null;
	public function __construct( public string $type, protected array $params ) {}
	public function getDeduplicationInfo() {
		$params = $this->params;
		foreach ( [ 'rootJobSignature', 'rootJobTimestamp', 'jobReleaseTimestamp', 'requestId' ] as $key ) {
			unset( $params[$key] );
		}
		ksort( $params );
		return [ 'type' => $this->type, 'params' => $params ];
	}
	public function getParams(): array { return $this->params; }
	public function hasExecutionFlag( int $flag ): bool { return ( $this->executionFlags & $flag ) === $flag; }
	public function getReleaseTimestamp(): ?int {
		return isset( $this->params['jobReleaseTimestamp'] ) ? (int)$this->params['jobReleaseTimestamp'] : null;
	}
	public function getLastError(): ?string { return $this->error; }
	public function allowRetries(): bool { return true; }
}

namespace MediaWiki\Storage\Hook;
interface PageSaveCompleteHook {
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult );
}
interface MultiContentSaveHook {}

namespace MediaWiki\Deferred\Hook;
interface LinksUpdateCompleteHook {}

namespace MediaWiki\Page\Hook;
interface PageDeleteCompleteHook {}
interface PageUndeleteCompleteHook {
	public function onPageUndeleteComplete( $page, $restorer, $reason, $restoredRev, $logEntry,
		$restoredRevisionCount, $created, $restoredPageIds
	): void;
}

namespace MediaWiki\Hook;
interface PageMoveCompleteHook {
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision );
}

namespace MediaWiki\Revision;
class SlotRecord { public const MAIN = 'main'; }

namespace MediaWiki\Import\Hook;
interface AfterImportPageHook {
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo );
}

namespace FrauxSearch;
function wfDebugLog( ...$args ): void {}

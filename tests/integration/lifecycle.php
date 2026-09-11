<?php

namespace FrauxSearch\Integration;

use FrauxSearch\DocumentBuilder;
use FrauxSearch\IndexCoordinatorFactory;
use FrauxSearch\Maintenance\ReconcileFrauxSearchIndex;
use FrauxSearch\MeilisearchClient;
use FrauxSearch\RedisConnection;
use FrauxSearch\RedisCoordinationStore;
use FrauxSearch\ReconciliationComparator;
use FrauxSearch\RefreshPageJob;
use FrauxSearch\RefreshQueueProcessor;
use FrauxSearch\SearchTextExtractor;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Import\ImportStreamSource;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 4 );
require_once "$IP/maintenance/Maintenance.php";

class CheckFrauxSearchLifecycle extends Maintenance {
	private const MAX_JOBS = 500;
	private const REDIS_OWNER = 'frauxsearch:lifecycle:owner';
	private const POLICY_OWNER = 'frauxsearch:lifecycle:policy-db';
	private const INERT_CHECKUSER_JOB = 'checkuserSuggestedInvestigationsMatchSignalsAgainstUserJob';
	private const EXCLUDED_GLOBAL_JOBS = [ 'checkuserPruneCheckUserDataJob',
		'checkuserUpdateUserCentralIndexJob', 'recentChangesUpdate', 'EchoNotificationDeleteJob' ];
	private const ALLOWED_JOBS = [ 'searchUpdate', 'refreshLinks', 'refreshLinksPrioritized',
		'refreshLinksDynamic', 'htmlCacheUpdate', 'categoryMembershipChange', 'RecordLintJob',
		'frauxSearchRefreshPage', 'frauxSearchScheduleIncomingRefreshes', 'frauxSearchScheduleBoostRefreshes' ];
	private const CIRRUS_TITLE_JOBS = [
		'cirrusSearchDeletePages' => \CirrusSearch\Job\DeletePages::class,
		'cirrusSearchIncomingLinkCount' => \CirrusSearch\Job\IncomingLinkCount::class,
		'cirrusSearchLinksUpdate' => \CirrusSearch\Job\LinksUpdate::class,
		'cirrusSearchLinksUpdatePrioritized' => \CirrusSearch\Job\LinksUpdate::class,
	];
	private array $titles = [];
	private array $executedJobs = [];
	private array $executedPageRefreshes = [];
	private array $renderedChecks = [];
	private array $excludedJobs = [];
	private object $queueDatabase;
	private ?\Redis $redis = null;
	private string $queueBackend;
	private MeilisearchClient $client;
	private User $user;
	private bool $queueCreated = false;
	private bool $indexesCreated = false;
	private bool $coordinationOwned = false;
	private RedisCoordinationStore $coordinationStore;
	private RedisConnection $coordinationConnection;
	private string $index;
	private string $run;
	private ?array $bootstrap = null;
	private string $searchType;
	private ?int $dropRefreshPage = null;
	private int $droppedRefreshes = 0;
	private array $incomingBatches = [];
	private array $incomingPages = [];
	private array $policyRecoveryEvents = [];
	private int $waitDelayedSeconds = 0;
	private string $interwikiPrefix;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check real MediaWiki lifecycle hooks through a dedicated SQL/Redis queue and isolated indexes.' );
		$this->addOption( 'execute', 'Create/delete random test pages and isolated test queue/indexes' );
		$this->addOption( 'cleanup-only', 'Clean a previous isolated run using its exact original run token' );
		$this->addOption( 'interwiki-prefix', 'Existing external interwiki prefix (default w)', false, true );
		$this->addOption( 'expect-search-type', 'Require this selected search engine class before writing fixture pages', false, true );
		$this->addOption( 'overlap-bootstrap', 'Deliver lifecycle changes while the initial generation is building' );
		$this->addOption( 'fanout', 'Create 1,001 additional source pages and check incoming continuation and reconciliation recovery' );
		$this->addOption( 'policy-import-db', 'Import the fixed boost policy only in this explicitly named, initially empty source database', false, true );
		$this->addOption( 'wait-delayed', 'Wait up to this many seconds for native chron to release delayed Cirrus incoming-link jobs (0-600)', false, true );
	}

	public function execute() {
		global $wgFrauxSearchLifecycleQueueServer, $wgFrauxSearchLifecycleOriginalIndex, $wgFrauxSearchLifecycleQueueBackend;
		$this->run = (string)getenv( 'FRAUXSEARCH_TEST_RUN' );
		$this->index = (string)$wgFrauxSearchLifecycleOriginalIndex . '_lifecycle_test_' . $this->run;
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$this->queueBackend = $wgFrauxSearchLifecycleQueueBackend ?? 'sql';
		$queue = $config->get( 'JobTypeConf' )['default'] ?? [];
		$isolatedQueue = $this->queueBackend === 'sql'
			? ( $queue['server']['tablePrefix'] ?? null ) === 'fslife_' . $this->run . '_'
			: ( $this->queueBackend === 'redis' && ( $queue['redisServer'] ?? null ) === '127.0.0.1:6389'
				&& ( $queue['class'] ?? null ) === \MediaWiki\JobQueue\JobQueueRedis::class && ( $queue['daemonized'] ?? null ) === true );
		if ( !$this->hasOption( 'execute' ) || !preg_match( '/^[a-f0-9]{12}$/D', $this->run )
			|| !is_string( $wgFrauxSearchLifecycleOriginalIndex )
			|| !preg_match( '/^[A-Za-z0-9_-]+$/D', $wgFrauxSearchLifecycleOriginalIndex )
			|| $config->get( 'FrauxSearchIndex' ) !== $this->index || !isset( $wgFrauxSearchLifecycleQueueServer )
			|| $wgFrauxSearchLifecycleOriginalIndex === $this->index
			|| !$isolatedQueue
			|| count( $config->get( 'JobTypeConf' ) ) !== 1
		) {
			throw new RuntimeException( 'Use lifecycleConf.php with an isolated run token and --execute.' );
		}
		$this->searchType = get_class( $services->getSearchEngineFactory()->create() );
		$this->check( !$this->hasOption( 'expect-search-type' )
			|| $this->getOption( 'expect-search-type' ) === $this->searchType, 'The selected search engine does not match --expect-search-type.' );
		$wait = $this->getOption( 'wait-delayed', 0 );
		$this->check( ( is_string( $wait ) || is_int( $wait ) ) && preg_match( '/^(0|[1-9][0-9]*)$/D', (string)$wait )
			&& (int)$wait <= 600, '--wait-delayed must be an integer from 0 to 600.' );
		$this->waitDelayedSeconds = (int)$wait;
		if ( $this->hasOption( 'policy-import-db' ) ) {
			$this->check( $this->queueBackend === 'redis' && $this->getOption( 'policy-import-db' ) === $config->get( 'DBname' ),
				'Policy import requires the private Redis queue and an exact --policy-import-db match.' );
			if ( !$this->hasOption( 'cleanup-only' ) ) {
				$this->check( (int)$services->getConnectionProvider()->getPrimaryDatabase()->newSelectQueryBuilder()
					->select( 'COUNT(*)' )->from( 'page' )->caller( __METHOD__ )->fetchField() === 0,
					'Fixed-title policy import requires an initially empty source wiki.' );
			}
		}
		if ( !$this->hasOption( 'cleanup-only' ) ) {
			$prefix = $this->getOption( 'interwiki-prefix', 'w' );
			$this->check( is_string( $prefix ) && preg_match( '/^[a-zA-Z0-9_-]+$/D', $prefix ), 'Invalid interwiki prefix.' );
			$this->check( (bool)$services->getConnectionProvider()->getPrimaryDatabase()->newSelectQueryBuilder()
				->select( 'iw_prefix' )->from( 'interwiki' )->where( [ 'iw_prefix' => $prefix ] )
				->caller( __METHOD__ )->fetchField(), 'Requested interwiki prefix is not configured.' );
			$this->interwikiPrefix = $prefix;
		}
		$this->coordinationStore = IndexCoordinatorFactory::createStore();
		$this->coordinationConnection = IndexCoordinatorFactory::createConnection();
		$this->queueDatabase = $services->getDatabaseFactory()->create( 'mysql', $wgFrauxSearchLifecycleQueueServer );
		if ( $this->queueBackend === 'redis' ) {
			$this->redis = new \Redis();
			$this->check( $this->redis->connect( '127.0.0.1', 6389, 2 ), 'Private Redis sidecar is unavailable.' );
		}
		$this->client = new MeilisearchClient( $config->get( 'FrauxSearchUrl' ), $config->get( 'FrauxSearchApiKey' ),
			$this->index, max( 5, (int)$config->get( 'FrauxSearchTimeout' ) ),
			(string)$config->get( 'FrauxSearchTaskApiKey' ) );
		$lock = $this->queueDatabase->addQuotes( 'frauxsearch-lifecycle-' . $this->run );
		$this->check( (int)$this->queueDatabase->query( "SELECT GET_LOCK($lock, 0)", __METHOD__ )->fetchRow()[0] === 1,
			'This lifecycle run is already active.' );
		$failure = null;
		try {
			if ( $this->hasOption( 'cleanup-only' ) ) {
				$this->prepareCleanup();
			} else {
				$this->prepare();
				$this->exercise();
				if ( $this->hasOption( 'fanout' ) ) { $this->exerciseFanout(); }
				if ( $this->hasOption( 'policy-import-db' ) ) { $this->exercisePolicyImport(); }
				$this->finishBootstrap();
				$this->exerciseRenderedText();
				if ( $this->redis !== null ) { $this->exerciseRefreshBurst(); }
				$this->output( json_encode( [ 'event' => 'lifecycle_passed', 'index' => $this->index,
					'queue_backend' => $this->queueBackend,
					'search_type' => $this->searchType, 'overlap_bootstrap' => $this->hasOption( 'overlap-bootstrap' ),
					'fanout_sources' => $this->hasOption( 'fanout' ) ? 1001 : 0,
					'jobs' => $this->executedJobs, 'excluded_global_jobs' => $this->excludedJobs,
					'observed_hooks' => array_count_values( $GLOBALS['frauxSearchLifecycleHooks'] ),
					'policy_import' => $this->hasOption( 'policy-import-db' ),
					'rendered_text' => $this->renderedChecks,
				], JSON_UNESCAPED_SLASHES ) . "\n" );
			}
		} catch ( Throwable $error ) {
			$failure = $error;
			throw $error;
		} finally {
			try {
				$this->cleanup();
			} catch ( Throwable $error ) {
				if ( $failure === null ) { throw $error; }
				$this->output( "Lifecycle cleanup also failed; preserving the original failure and remaining isolated resources.\n" );
			} finally {
				$this->queueDatabase->query( "SELECT RELEASE_LOCK($lock)", __METHOD__ );
				if ( $this->redis !== null ) { $this->redis->close(); }
			}
		}
		return true;
	}

	private function prepare(): void {
		$services = MediaWikiServices::getInstance();
		$primary = $services->getConnectionProvider()->getPrimaryDatabase();
		if ( $this->redis === null ) {
			$this->check( !$this->queueDatabase->tableExists( 'job', __METHOD__ ), 'Test queue already exists; inspect this run before reusing its token.' );
			$this->check( $primary->tableExists( 'job', __METHOD__ ), 'The source wiki must have the core job schema.' );
		} else {
			$this->check( $this->redis->dbSize() === 0, 'Private Redis must be empty before beginning a new lifecycle run.' );
		}
		foreach ( [ $this->index, $this->index . '_completion', $this->index . '_coordination' ] as $index ) {
			$this->check( !$this->client->withIndex( $index )->indexExists(), 'Test index already exists.' );
		}
		$this->initializeTitles( true );
		$this->assertCoordinationIdle();
		$this->coordinationOwned = true;
		$this->output( json_encode( [ 'event' => 'lifecycle_started', 'index' => $this->index,
			'queue_backend' => $this->queueBackend,
			'queue_prefix' => 'fslife_' . $this->run . '_', 'titles' => $this->titles ], JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( $this->redis === null ) {
			$this->queueDatabase->query( 'CREATE TABLE ' . $this->queueDatabase->tableName( 'job' )
				. ' LIKE ' . $primary->tableName( 'job' ), __METHOD__ );
		} else {
			$this->check( $this->redis->set( self::REDIS_OWNER, $this->run, [ 'nx' ] ) === true, 'Cannot acquire private Redis ownership.' );
			if ( $this->hasOption( 'policy-import-db' ) ) {
				$this->check( $this->redis->set( self::POLICY_OWNER, $this->policyOwner(), [ 'nx' ] ) === true,
					'Cannot record isolated policy-page ownership.' );
			}
		}
		$this->queueCreated = true;
		$this->user = User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		$coordinator = IndexCoordinatorFactory::create();
		$run = $coordinator->beginRebuild( false );
		$this->indexesCreated = true;
		if ( $this->hasOption( 'overlap-bootstrap' ) ) {
			$this->bootstrap = $run;
		} else {
			$coordinator->ready( $run['id'] );
			$coordinator->finishRebuild( $run['id'] );
		}
	}

	private function initializeTitles( bool $mustBeAbsent ): void {
		foreach ( [ 'Source A', 'Source B', 'Source C', 'Target A', 'Target B', 'Imported Target',
			'Broken Redirect', 'Interwiki Redirect', 'Interwiki Chain' ] as $name ) {
			$title = Title::newFromText( 'FrauxSearch Lifecycle ' . $this->run . ' ' . $name );
			$this->check( $title !== null && ( !$mustBeAbsent || !$title->exists() ), 'Random lifecycle title already exists.' );
			$this->titles[$name] = $title->getPrefixedText();
		}
		$this->titles['Foreign Import Title'] = 'Talk:' . $this->titles['Imported Target'];
		$this->check( !$mustBeAbsent || !$this->title( 'Foreign Import Title' )->exists(), 'Random foreign import title already exists.' );
		foreach ( [ 'Rendered Template' => 'Template:FrauxSearch Lifecycle ' . $this->run . ' Rendered Template',
			'Rendered Source' => 'FrauxSearch Lifecycle ' . $this->run . ' Rendered Source' ] as $name => $text ) {
			$title = Title::newFromText( $text );
			$this->check( $title !== null && ( !$mustBeAbsent || !$title->exists() ), 'Rendered-text fixture title already exists.' );
			$this->titles[$name] = $title->getPrefixedText();
		}
		if ( $this->hasOption( 'fanout' ) ) {
			foreach ( array_merge( [ 'Fanout Target', 'Fanout Recovery Target' ],
				array_map( static fn ( int $id ) => "Fanout Source $id", range( 1, 1001 ) ) ) as $name ) {
				$title = Title::newFromText( 'FrauxSearch Lifecycle ' . $this->run . ' ' . $name );
				$this->check( $title !== null && ( !$mustBeAbsent || !$title->exists() ), 'Random fan-out title already exists.' );
				$this->titles[$name] = $title->getPrefixedText();
			}
		}
		if ( $this->hasOption( 'policy-import-db' ) ) {
			foreach ( [ 'Boost Template' => 'Template:FrauxSearch Lifecycle ' . $this->run . ' Boost',
				'Boosted Source' => 'FrauxSearch Lifecycle ' . $this->run . ' Boosted Source',
				'Boost Control' => 'FrauxSearch Lifecycle ' . $this->run . ' Boost Control',
				'Boost Policy' => 'MediaWiki:Frauxsearch-boost-templates' ] as $name => $text ) {
				$title = Title::newFromText( $text );
				$this->check( $title !== null && ( !$mustBeAbsent || !$title->exists() ), 'Policy fixture title already exists.' );
				$this->titles[$name] = $title->getPrefixedText();
			}
		}
	}

	private function prepareCleanup(): void {
		if ( $this->redis === null ) {
			$this->check( $this->queueDatabase->tableExists( 'job', __METHOD__ ), 'No dedicated queue exists for this cleanup token.' );
		} else {
			$this->assertRedisOwner();
		}
		$this->assertPolicyOwner();
		$this->assertCoordinationResolved();
		$this->initializeTitles( false );
		$this->user = User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		$this->queueCreated = true;
		$this->indexesCreated = true;
		$this->coordinationOwned = true;
		$this->output( json_encode( [ 'event' => 'lifecycle_cleanup_started', 'index' => $this->index,
			'queue_prefix' => 'fslife_' . $this->run . '_', 'titles' => $this->titles ], JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	private function exercise(): void {
		$this->save( 'Source A', '[[' . $this->titles['Target A'] . ']]' );
		$this->save( 'Source B', '[[' . $this->titles['Target B'] . ']]' );
		$this->save( 'Source C', '[[' . $this->titles['Imported Target'] . ']]' );
		$this->save( 'Broken Redirect', '#REDIRECT [[' . $this->titles['Target B'] . ']]' );
		$this->drain();
		$sourceA = $this->document( 'Source A' );
		$sourceB = $this->document( 'Source B' );
		$sourceC = $this->document( 'Source C' );
		$this->check( $sourceA['outgoing_link_ids'] === [] && $sourceB['outgoing_link_ids'] === [], 'Redlinks unexpectedly resolved.' );
		$this->assertAbsentCompletion( 'Broken Redirect' );
		if ( $this->bootstrap !== null ) {
			IndexCoordinatorFactory::create()->writeBatch( $this->bootstrap['id'],
				[ $sourceA, $sourceB, $sourceC, $this->document( 'Broken Redirect' ) ], [ $sourceA, $sourceB, $sourceC ] );
		}
		$this->save( 'Source A', '[[' . $this->titles['Target A'] . ']] Existing source edited before search cutover.' );
		$this->drain();
		$edited = $this->document( 'Source A' );
		$this->check( $edited['revision_id'] !== $sourceA['revision_id']
			&& str_contains( $edited['text'], 'Existing source edited before search cutover.' ),
			'An existing source edit was not delivered by the selected backend and registered hooks.' );
		$sourceA = $edited;
		$this->output( "PASS existing source edit with selected search backend {$this->searchType}\n" );

		$this->save( 'Target A', 'Created by the isolated lifecycle test.' );
		$this->drain();
		$targetId = $this->id( 'Target A' );
		$this->assertDerivedSource( 'Source A', $sourceA, [ $targetId ], true );
		$this->output( "PASS redlink source refresh after target creation without editing its source\n" );

		$services = MediaWikiServices::getInstance();
		$status = $services->getMovePageFactory()->newMovePage( $this->title( 'Target A' ), $this->title( 'Target B' ) )
			->move( $this->user, 'FrauxSearch isolated lifecycle test', false );
		$this->check( $status->isOK(), 'Move failed.' );
		$this->drain();
		$this->check( $this->id( 'Target B' ) === $targetId, 'Move changed the canonical page ID.' );
		$this->assertDerivedSource( 'Source A', $sourceA, [], false );
		$this->assertDerivedSource( 'Source B', $sourceB, [ $targetId ], true );
		$this->assertAlias( $targetId, $this->titles['Broken Redirect'], true );
		$this->output( "PASS old/new incoming sources and broken redirect after target move\n" );

		$this->delete( 'Target B' );
		$this->drain();
		$this->assertDerivedSource( 'Source B', $sourceB, [], false );
		$this->check( $this->client->getDocument( $targetId ) === null, 'Deleted target survived full-text cleanup.' );
		$this->check( $this->client->withIndex( $this->index . '_completion' )->getDocument( $targetId ) === null,
			'Deleted target survived completion cleanup.' );
		$page = $services->getWikiPageFactory()->newFromTitle( $this->title( 'Target B' ) );
		$status = $services->getUndeletePageFactory()->newUndeletePage( $page, $this->user )
			->undeleteUnsafe( 'FrauxSearch isolated lifecycle test' );
		$this->check( $status->isGood(), 'Undeletion failed.' );
		$this->drain();
		$targetId = $this->id( 'Target B' );
		$this->assertDerivedSource( 'Source B', $sourceB, [ $targetId ], true );
		$this->assertAlias( $targetId, $this->titles['Broken Redirect'], true );
		$this->output( "PASS deleted/restored target identity propagates to unchanged sources\n" );

		$this->importTarget();
		$this->drain();
		$importedId = $this->id( 'Imported Target' );
		$this->assertDerivedSource( 'Source C', $sourceC, [ $importedId ], true );
		$imports = $GLOBALS['frauxSearchLifecycleImports'];
		$observed = end( $imports );
		$this->check( $observed['successful_revisions'] === 1 && $observed['local_title'] === $this->title( 'Imported Target' )->getPrefixedDBkey(),
			'Actual XML importer did not deliver the expected successful local-title hook.' );
		$this->check( $importedId !== 999999999, 'Test accidentally reused the foreign page ID.' );
		$this->check( !$this->title( 'Foreign Import Title' )->exists(), 'Import wrote the foreign title instead of remapping it.' );
		$this->output( "PASS real XML import updates incoming sources using local page identity\n" );

		$prefix = $this->interwikiPrefix;
		$primary = $services->getConnectionProvider()->getPrimaryDatabase();
		$this->save( 'Interwiki Redirect', '#REDIRECT [[' . $prefix . ':' . $this->titles['Target B'] . ']]' );
		$this->save( 'Interwiki Chain', '#REDIRECT [[' . $this->titles['Interwiki Redirect'] . ']]' );
		$this->drain();
		$this->check( $primary->newSelectQueryBuilder()->select( 'rd_interwiki' )->from( 'redirect' )
			->where( [ 'rd_from' => $this->id( 'Interwiki Redirect' ) ] )->caller( __METHOD__ )->fetchField() === $prefix,
			'The parser did not create a real interwiki redirect row.' );
		$this->assertAbsentCompletion( 'Interwiki Redirect' );
		$this->assertAbsentCompletion( 'Interwiki Chain' );
		$this->assertAlias( $targetId, $this->titles['Interwiki Redirect'], false );
		$this->assertAlias( $targetId, $this->titles['Interwiki Chain'], false );
		foreach ( [ 'PageSaveComplete', 'PageMoveComplete', 'PageDeleteComplete', 'PageUndeleteComplete', 'AfterImportPage' ] as $hook ) {
			$this->check( in_array( $hook, $GLOBALS['frauxSearchLifecycleHooks'], true ), 'Expected actual core hook was not observed: ' . $hook );
		}
		$this->check( ( $this->executedJobs['frauxSearchScheduleIncomingRefreshes'] ?? 0 ) > 0
			&& ( $this->executedJobs['frauxSearchRefreshPage'] ?? 0 ) > 0, 'Lifecycle work bypassed the actual queued scheduler/page jobs.' );
		$this->output( "PASS real interwiki redirect and chain never become local aliases/completions\n" );
	}

	private function exerciseRefreshBurst(): void {
		$services = MediaWikiServices::getInstance();
		$queues = $services->getJobQueueGroup();
		$queue = $queues->get( 'frauxSearchRefreshPage' );
		$this->check( $queue->getSize() === 0 && $queue->getAcquiredCount() === 0, 'Burst requires an idle queue.' );
		$ids = array_map( $this->id( ... ), [ 'Source A', 'Source B', 'Target B', 'Broken Redirect' ] );
		$jobs = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$jobs[] = new class( [ 'pageId' => $ids[$i % 4], 'dependencyEvent' => bin2hex( random_bytes( 16 ) ) ] )
				extends RefreshPageJob {
				public function ignoreDuplicates() { return false; }
			};
		}
		$queues->push( $jobs );
		$this->check( $queue->getSize() === 200, 'Legacy duplicate burst was not queued.' );
		$owner = IndexCoordinatorFactory::createStore();
		$this->check( $owner->lock(), 'Unable to reserve the writer for contention test.' );
		try {
			try {
				RefreshQueueProcessor::create()->run( 200 );
				throw new RuntimeException( 'Competing runner acquired a held writer lock.' );
			} catch ( \FrauxSearch\MeilisearchException $error ) {
				$this->check( str_contains( $error->getMessage(), 'writer is busy' ), 'Unexpected contention failure.' );
			}
			$this->check( $queue->getSize() === 200 && $queue->getAcquiredCount() === 0,
				'Busy runner claimed jobs before acquiring the writer lock.' );
		} finally {
			$owner->unlock();
		}
		$start = hrtime( true );
		$result = RefreshQueueProcessor::create( [ 'render-workers' => 2 ] )->run( 200 );
		$seconds = ( hrtime( true ) - $start ) / 1e9;
		$this->check( $result === [ 'jobs' => 200, 'pages' => 4 ], 'Legacy duplicate batch did not coalesce.' );
		$this->check( $queue->getSize() === 0 && $queue->getAcquiredCount() === 0,
			'Settled batch generated more dependencies or retained claims.' );
		foreach ( $ids as $id ) {
			$built = ( new DocumentBuilder( true ) )->build( $id );
			$this->check( ReconciliationComparator::compare( $built, $this->client->getDocument( $id ),
				$this->client->withIndex( $this->index . '_completion' )->getDocument( $id ) ) === [],
				'Burst changed the complete document payload.' );
		}
		$jobs = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$jobs[] = new RefreshPageJob( [ 'pageId' => $ids[$i % 4], 'dependencyEvent' => bin2hex( random_bytes( 16 ) ) ] );
		}
		$queues->push( $jobs );
		$this->check( $queue->getSize() === 4, 'New events did not coalesce in native Redis.' );
		$seed = $queues->pop( 'frauxSearchRefreshPage' );
		$this->check( $seed !== false, 'Unable to claim native batch seed.' );
		$native = $services->getJobRunner()->executeJob( $seed );
		$this->check( $native['status'] === true, 'Native batched job failed: ' . $native['error'] );
		$queues->ack( $seed );
		$this->check( $queue->getSize() === 0 && $queue->getAcquiredCount() === 0,
			'Native batch did not settle all four jobs.' );
		$this->save( 'Source A', 'Edited after overlapping refresh burst.' );
		$this->drain();
		$this->check( str_contains( $this->document( 'Source A' )['text'], 'Edited after overlapping refresh burst.' ),
			'An edit after the burst was lost.' );
		$this->assertCoordinationIdle();
		$this->output( json_encode( [ 'event' => 'refresh_burst_passed', 'legacy_jobs' => 200,
			'page_builds' => 4, 'render_workers' => 2, 'elapsed_seconds' => $seconds,
			'new_jobs_coalesced' => 196, 'native_batch' => true, 'subsequent_edit' => true ] ) . "\n" );
	}

	private function save( string $name, string $text ): void {
		$title = $this->title( $name );
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$updater = $page->newPageUpdater( $this->user );
		$updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $text, $title ) );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'FrauxSearch isolated lifecycle test' ), EDIT_SUPPRESS_RC | EDIT_FORCE_BOT );
		$this->check( $updater->getStatus()->isOK(), 'Page save failed.' );
		$this->flush();
	}

	private function exerciseFanout(): void {
		$initial = [];
		for ( $number = 1; $number <= 1001; $number++ ) {
			$name = "Fanout Source $number";
			$text = '[[' . $this->titles['Fanout Target'] . ']]';
			if ( $number === 1 ) { $text .= ' [[' . $this->titles['Fanout Recovery Target'] . ']]'; }
			$this->save( $name, $text );
			if ( $number % 25 === 0 || $number === 1001 ) { $this->drain(); }
			if ( $number % 100 === 0 || $number === 1001 ) { $this->output( "Prepared $number of 1,001 fan-out source pages.\n" ); }
		}
		$sourceIds = [];
		for ( $number = 1; $number <= 1001; $number++ ) { $sourceIds[] = $this->id( "Fanout Source $number" ); }
		sort( $sourceIds, SORT_NUMERIC );
		foreach ( array_chunk( $sourceIds, 100 ) as $ids ) {
			foreach ( $this->client->fetchDocuments( $ids ) as $document ) { $initial[$document['id']] = $document; }
		}
		$this->check( count( $initial ) === 1001, 'Fan-out source documents are missing before target creation.' );
		$this->save( 'Fanout Target', 'Target created after 1,001 referring source revisions.' );
		$this->drain();
		$targetId = $this->id( 'Fanout Target' );
		$events = array_filter( $this->incomingBatches,
			fn ( array $batch ) => $batch['title'] === $this->title( 'Fanout Target' )->getDBkey() );
		$this->check( count( $events ) === 1, 'Fan-out target must have one serialized identity event.' );
		$event = array_key_first( $events );
		$this->check( $events[$event]['cursors'] === [ 0, $sourceIds[999] ], 'Incoming continuation skipped or repeated a source batch.' );
		$delivered = array_keys( $this->incomingPages[$event] ?? [] );
		sort( $delivered, SORT_NUMERIC );
		$this->check( $delivered === $sourceIds, 'Incoming continuation did not deliver all 1,001 source IDs with the same event.' );
		foreach ( array_chunk( $sourceIds, 100 ) as $ids ) {
			$full = $this->client->fetchDocuments( $ids );
			$completion = array_column( $this->client->withIndex( $this->index . '_completion' )->fetchDocuments( $ids ), null, 'id' );
			$this->check( count( $full ) === count( $ids ) && count( $completion ) === count( $ids ), 'Fan-out removed source documents.' );
			foreach ( $full as $document ) {
				$old = $initial[$document['id']];
				$this->check( $document['revision_id'] === $old['revision_id'] && $document['outgoing_link_ids'] === [ $targetId ]
					&& $document['document_hash'] !== $old['document_hash']
					&& $completion[$document['id']]['document_hash'] === $document['document_hash'],
					'Fan-out failed to refresh unchanged source revisions in both indexes.' );
			}
		}
		$this->output( "PASS 1,001 incoming sources across the real scheduler continuation boundary\n" );
		$this->exerciseDroppedRefresh();
	}

	private function exerciseDroppedRefresh(): void {
		$name = 'Fanout Source 1';
		$old = $this->document( $name );
		$this->dropRefreshPage = $old['id'];
		try {
			$this->save( 'Fanout Recovery Target', 'Target whose incoming refresh delivery is deliberately dropped.' );
			$this->drain();
		} finally { $this->dropRefreshPage = null; }
		$this->check( $this->droppedRefreshes > 0 && $this->document( $name ) === $old,
			'The isolated dropped-delivery fixture did not retain its stale indexed revision.' );
		$audit = $this->createChild( ReconcileFrauxSearchIndex::class,
			dirname( __DIR__, 2 ) . '/maintenance/reconcileFrauxSearchIndex.php' );
		$audit->setOption( 'start-after', $old['id'] - 1 );
		$audit->setOption( 'stop-after', $old['id'] );
		$audit->execute();
		$this->check( $this->queueIsEmpty() && $this->document( $name ) === $old, 'Read-only reconciliation changed the isolated index or queue.' );
		$audit->setOption( 'queue-repairs', true );
		$audit->execute();
		$this->check( !$this->queueIsEmpty(), 'Reconciliation failed to persist the missing source refresh.' );
		$this->drain();
		$current = $this->document( $name );
		$completion = $this->client->withIndex( $this->index . '_completion' )->getDocument( $old['id'] );
		$outgoing = [ $this->id( 'Fanout Target' ), $this->id( 'Fanout Recovery Target' ) ];
		sort( $outgoing, SORT_NUMERIC );
		$this->check( $current['revision_id'] === $old['revision_id'] && $current['outgoing_link_ids'] === $outgoing
			&& $current['document_hash'] !== $old['document_hash']
			&& $completion !== null && $completion['document_hash'] === $current['document_hash'],
			'Reconciliation did not repair the dropped source delivery in both indexes.' );
		$this->output( "PASS dropped source delivery detected read-only and repaired through real reconciliation jobs\n" );
	}

	private function finishBootstrap(): void {
		if ( $this->bootstrap === null ) { return; }
		$expected = [];
		foreach ( [ $this->index, $this->index . '_completion' ] as $index ) {
			$expected[$index] = $this->indexDocuments( $index );
		}
		$coordinator = IndexCoordinatorFactory::create();
		$this->check( $this->coordinationStore->watermark() > 0, 'Bootstrap overlap did not retain source changes for replay.' );
		$coordinator->ready( $this->bootstrap['id'] );
		$coordinator->finishRebuild( $this->bootstrap['id'] );
		foreach ( $expected as $index => $documents ) {
			$this->check( $this->indexDocuments( $index ) == $documents, 'Generation activation lost or changed delivered lifecycle documents.' );
		}
		foreach ( [ $this->bootstrap['full'], $this->bootstrap['completion'] ] as $index ) {
			$this->check( !$this->client->withIndex( $index )->indexExists(), 'An old lifecycle generation survived activation.' );
		}
		$this->bootstrap = null;
		$this->assertCoordinationIdle();
		$this->output( "PASS bootstrap replay and paired activation preserve all delivered lifecycle changes\n" );
	}

	private function exercisePolicyImport(): void {
		$this->save( 'Boost Template', 'Isolated boost template.' );
		$this->save( 'Boosted Source', '{{' . $this->title( 'Boost Template' )->getText() . '}} Boosted source.' );
		$this->save( 'Boost Control', 'Control source without the boosted template.' );
		$this->drain();
		$initial = $this->document( 'Boosted Source' );
		$control = $this->document( 'Boost Control' );
		$this->check( $initial['boost'] === 100 && $control['boost'] === 100, 'Unexpected initial boost policy.' );
		$policy = htmlspecialchars( $this->titles['Boost Policy'], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$rule = htmlspecialchars( $this->title( 'Boost Template' )->getDBkey() . '|150%', ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$xml = '<?xml version="1.0"?><mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">'
			. '<siteinfo><sitename>Isolated policy import</sitename><dbname>foreign</dbname><base>https://example.invalid/</base>'
			. '<generator>MediaWiki 1.46</generator><case>first-letter</case><namespaces>'
			. '<namespace key="8" case="first-letter">MediaWiki</namespace></namespaces></siteinfo>'
			. '<page><title>' . $policy . '</title><ns>8</ns><id>999999998</id><revision><id>888888887</id>'
			. '<timestamp>2026-09-08T00:00:01Z</timestamp><contributor><ip>192.0.2.1</ip></contributor>'
			. '<model>wikitext</model><format>text/x-wiki</format><text xml:space="preserve">' . $rule
			. '</text></revision></page></mediawiki>';
		$this->importXml( $xml, NS_MEDIAWIKI );
		$imports = $GLOBALS['frauxSearchLifecycleImports'];
		$observed = end( $imports );
		$this->check( $observed['successful_revisions'] === 1 && $observed['local_title'] === $this->title( 'Boost Policy' )->getPrefixedDBkey(),
			'Actual importer did not deliver the successful local policy-title hook.' );
		$this->drain();
		$this->check( $this->policyRecoveryEvents !== [], 'Policy import did not execute the all-pages recovery scheduler.' );
		$current = $this->document( 'Boosted Source' );
		$completion = $this->client->withIndex( $this->index . '_completion' )->getDocument( $current['id'] );
		$this->check( $current['revision_id'] === $initial['revision_id'] && $current['boost'] === 150
			&& $current['document_hash'] !== $initial['document_hash']
			&& $completion !== null && $completion['document_hash'] === $current['document_hash'],
			'Policy import did not update boost and hash in both indexes without editing its source.' );
		$this->check( $this->document( 'Boost Control' ) === $control, 'Policy import changed an unrelated control document.' );
		$this->output( "PASS real fixed-title policy import queues full recovery and updates unchanged source boosts\n" );
	}

	private function exerciseRenderedText(): void {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getObjectCacheFactory()->getInstance( 'frauxsearch-lifecycle-parser' );
		$this->check( $cache instanceof LifecycleParserCache, 'Rendered-text checks require the private lifecycle parser cache.' );
		$access = $services->getParserOutputAccess();
		try {
			$cache->withCaching( function () use ( $services, $cache, $access ): void {
				$beforeText = 'Apricot' . $this->run;
				$afterText = 'Blueberry' . $this->run . ' additional visible words';
				$this->save( 'Rendered Template', $beforeText );
				$this->save( 'Rendered Source', 'Lead {{' . $this->title( 'Rendered Template' )->getText() . '}} tail.' );
				$this->drain();
				$initial = $this->document( 'Rendered Source' );
				$this->check( str_contains( $initial['text'], $beforeText ), 'Initial template text was not rendered for indexing.' );
				$this->assertAuthoritativePair( $initial );
				$id = $initial['id'];
				$page = $services->getWikiPageFactory()->newFromID( $id, IDBAccessObject::READ_LATEST );
				$options = $page->makeParserOptions( 'canonical' );
				$options->setRenderReason( 'ParserOutputForIndexing' );
				$revision = $page->getRevisionRecord();
				$this->check( $access->getParserOutput( $page, $options, $revision )->isGood(), 'Unable to warm the private parser cache.' );
				$access->clearLocalCache();
				$hits = $cache->getHits();
				$cached = $access->getCachedParserOutput( $page, $options, $revision );
				$this->check( $cached !== null && $cache->getHits() > $hits
					&& str_contains( SearchTextExtractor::fromParserOutput( $cached ), $beforeText ),
					'The initial rendered text was not read from the private parser cache.' );
				$refreshes = $this->executedPageRefreshes[$id] ?? 0;
				$this->save( 'Rendered Template', $afterText );
				$this->drain();
				$current = $this->document( 'Rendered Source' );
				$this->assertRenderedChange( $initial, $current, $beforeText, $afterText );
				$this->check( ( $this->executedPageRefreshes[$id] ?? 0 ) > $refreshes,
					'The template edit did not execute an automatic refresh job for its transcluding article.' );
				$automaticRefreshes = $this->executedPageRefreshes[$id] - $refreshes;
				$this->check( (int)$services->getConnectionProvider()->getPrimaryDatabase()->newSelectQueryBuilder()
					->select( 'page_latest' )->from( 'page' )->where( [ 'page_id' => $id ] )
					->caller( __METHOD__ )->fetchField() === $initial['revision_id'], 'The transcluding source revision changed.' );
				$this->assertAuthoritativePair( $current );
				$this->output( "PASS template edit refreshes rendered text and hash without editing its cached transcluding article\n" );
				$latestTask = $this->latestDocumentTask();
				$refreshes = $this->executedPageRefreshes[$id];
				$services->getJobQueueGroup()->push( new RefreshPageJob( [
					'pageId' => $id, 'sourceEvent' => bin2hex( random_bytes( 16 ) ),
				] ) );
				$this->drain();
				$this->check( $this->executedPageRefreshes[$id] > $refreshes,
					'The unchanged refresh job was not executed by the native runner.' );
				$this->check( $this->latestDocumentTask() === $latestTask,
					'An unchanged refresh submitted a new document write task.' );
				$this->check( $this->document( 'Rendered Source' ) === $current, 'An unchanged refresh altered its full document.' );
				$this->assertAuthoritativePair( $current );
				$this->assertCoordinationIdle();
				$this->renderedChecks = [ 'source_id' => $id, 'source_revision' => $initial['revision_id'],
					'parser_cache_hits' => $cache->getHits(), 'automatic_refreshes' => $automaticRefreshes,
					'unchanged_data_write_tasks' => 0 ];
				$this->output( "PASS native unchanged refresh executes and retires without full/completion document tasks\n" );
			} );
		} finally {
			$access->clearLocalCache();
		}
	}

	private function assertRenderedChange( array $initial, array $current, string $beforeText, string $afterText ): void {
		$this->check( $current['revision_id'] === $initial['revision_id']
			&& $current['document_hash'] !== $initial['document_hash']
			&& str_contains( $current['text'], $afterText ) && !str_contains( $current['text'], $beforeText )
			&& $current['byte_size'] === strlen( $current['text'] ) && $current['byte_size'] !== $initial['byte_size']
			&& $current['word_count'] === str_word_count( $current['text'] ) && $current['word_count'] !== $initial['word_count'],
			'Template-derived text, hash, or size metadata did not change at the same source revision.' );
	}

	private function assertAuthoritativePair( array $full ): void {
		$completion = $this->client->withIndex( $this->index . '_completion' )->getDocument( $full['id'] );
		$built = ( new DocumentBuilder( true ) )->build( $full['id'] );
		$this->check( ReconciliationComparator::compare( $built, $full, $completion ) === [],
			'Full/completion documents differ from the complete current rendered payload.' );
	}

	private function latestDocumentTask(): int {
		$tasks = $this->client->listTasks( [ 'indexUids' => [ $this->index, $this->index . '_completion' ],
			'types' => [ 'documentAdditionOrUpdate', 'documentEdition', 'documentDeletion' ] ], null, 1 );
		return $tasks['results'][0]['uid'] ?? -1;
	}

	private function indexDocuments( string $index ): array {
		$documents = [];
		$offset = 0;
		do {
			$page = $this->client->withIndex( $index )->listDocuments( $offset, 100 );
			foreach ( $page['results'] as $document ) { $documents[$document['id']] = $document; }
			$offset += count( $page['results'] );
		} while ( $offset < $page['total'] );
		ksort( $documents, SORT_NUMERIC );
		return $documents;
	}

	private function delete( string $name ): void {
		$services = MediaWikiServices::getInstance();
		$title = $this->title( $name );
		if ( !$title->exists() ) { return; }
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$status = $services->getDeletePageFactory()->newDeletePage( $page, $this->user )
			->forceImmediate( true )->deleteUnsafe( 'FrauxSearch isolated lifecycle cleanup' );
		$this->check( $status->isOK(), 'Page deletion failed.' );
		$this->flush();
	}

	private function importTarget(): void {
		$title = htmlspecialchars( $this->titles['Imported Target'], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$xml = '<?xml version="1.0"?><mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11" xml:lang="en">'
			. '<siteinfo><sitename>Isolated import source</sitename><dbname>foreign</dbname><base>https://example.invalid/</base>'
			. '<generator>MediaWiki 1.46</generator><case>first-letter</case><namespaces><namespace key="0" case="first-letter" />'
			. '<namespace key="1" case="first-letter">Talk</namespace></namespaces></siteinfo>'
			. '<page><title>Talk:' . $title . '</title><ns>1</ns><id>999999999</id><revision><id>888888888</id>'
			. '<timestamp>2026-09-08T00:00:00Z</timestamp><contributor><ip>192.0.2.1</ip></contributor>'
			. '<comment>FrauxSearch isolated import</comment><model>wikitext</model><format>text/x-wiki</format>'
			. '<text xml:space="preserve">Real XML import for isolated lifecycle validation.</text></revision></page></mediawiki>';
		$this->importXml( $xml, NS_MAIN );
	}

	private function importXml( string $xml, int $namespace ): void {
		$handle = fopen( 'php://temp', 'w+' );
		$this->check( $handle !== false, 'Unable to open the isolated XML fixture.' );
		fwrite( $handle, $xml );
		rewind( $handle );
		try {
			$importer = MediaWikiServices::getInstance()->getWikiImporterFactory()->getWikiImporter(
				new ImportStreamSource( $handle ), new UltimateAuthority( $this->user ) );
			$this->check( $importer->setTargetNamespace( $namespace ), 'Unable to set the local import namespace.' );
			$this->check( $importer->doImport(), 'XML import failed.' );
		} finally { fclose( $handle ); }
		$this->flush();
	}

	private function flush(): void {
		$services = MediaWikiServices::getInstance();
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		DeferredUpdates::doUpdates();
		$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );
		$services->getConnectionProvider()->getPrimaryDatabase()->flushSnapshot( __METHOD__ );
		$services->getLinkCache()->clear();
		gc_collect_cycles();
	}

	private function drain(): void {
		$this->flush();
		$services = MediaWikiServices::getInstance();
		$limit = $this->hasOption( 'fanout' ) ? 10000 : self::MAX_JOBS;
		$delayDeadline = null;
		for ( $count = 0; $count < $limit; $count++ ) {
			$row = $this->nextQueuedJob();
			if ( $row === false ) {
				if ( $this->redis !== null && !$this->queueIsEmpty( $this->waitDelayedSeconds > 0 ) ) {
					if ( $this->nextQueuedJob() !== false ) { $count--; continue; }
					if ( $delayDeadline === null ) {
						$delayDeadline = hrtime( true ) + $this->waitDelayedSeconds * 1000000000;
						$this->output( "Waiting for native chron to release delayed Cirrus incoming-link jobs.\n" );
					}
					$this->check( hrtime( true ) < $delayDeadline, 'Delayed Cirrus jobs did not become ready within --wait-delayed; preserve the private queue.' );
					usleep( 200000 );
					$count--;
					continue;
				}
				IndexCoordinatorFactory::create()->drain();
				$this->flush();
				if ( $this->queueIsEmpty() ) {
					if ( $this->bootstrap === null ) {
						$this->assertCoordinationIdle();
					} else {
						$status = IndexCoordinatorFactory::create()->status();
						$this->check( !$status['coordinationLost'] && $status['pending'] === null
							&& ( $status['run']['id'] ?? null ) === $this->bootstrap['id']
							&& $status['run']['phase'] === 'building', 'Lifecycle jobs changed the active bootstrap ownership or phase.' );
					}
					return;
				}
				continue;
			}
			$delayDeadline = null;
			$type = $row->job_cmd;
			if ( in_array( $type, self::EXCLUDED_GLOBAL_JOBS, true ) ) {
				if ( $this->redis === null ) {
					$table = explode( '.', str_replace( '`', '', $this->queueDatabase->tableName( 'job' ) ) );
					$this->check( end( $table ) === 'fslife_' . $this->run . '_job', 'Refusing exclusion outside the exact isolated queue.' );
					$this->queueDatabase->newDeleteQueryBuilder()->deleteFrom( 'job' )
						->where( [ 'job_id' => (int)$row->job_id, 'job_cmd' => $type ] )->caller( __METHOD__ )->execute();
					$this->check( $this->queueDatabase->affectedRows() === 1, 'Isolated excluded job changed during removal.' );
				} else {
					$this->assertRedisOwner();
					$job = $services->getJobQueueGroup()->pop( $type );
					$this->check( $job !== false, 'Private Redis excluded job could not be popped.' );
					$services->getJobQueueGroup()->ack( $job );
				}
				$this->excludedJobs[$type] = ( $this->excludedJobs[$type] ?? 0 ) + 1;
				continue;
			}
			if ( $type === self::INERT_CHECKUSER_JOB ) {
				$this->check( $services->getMainConfig()->get( 'CheckUserSuggestedInvestigationsEnabled' ) === false,
					'The reviewed CheckUser job must have its signal feature disabled in this test process.' );
			} else {
				$this->check( in_array( $type, self::ALLOWED_JOBS, true ) || isset( self::CIRRUS_TITLE_JOBS[$type] ),
					'Unexpected isolated job type; review before execution: ' . $type );
			}
			$job = $services->getJobQueueGroup()->pop( $type );
			$this->check( $job !== false, 'An isolated job remains claimed or cannot be popped.' );
			$params = $job->getParams();
			if ( isset( self::CIRRUS_TITLE_JOBS[$type] ) ) {
				$this->check( get_class( $job ) === self::CIRRUS_TITLE_JOBS[$type]
					&& in_array( $job->getTitle()->getPrefixedText(), $this->titles, true )
					&& !isset( $params['external-index'] ), 'Cirrus job is outside this lifecycle run: ' . $type );
			}
			if ( $this->dropRefreshPage !== null && $type === 'frauxSearchRefreshPage'
				&& ( $params['pageId'] ?? null ) === $this->dropRefreshPage
			) {
				$services->getJobQueueGroup()->ack( $job );
				$this->droppedRefreshes++;
				continue;
			}
			if ( $type === 'RecordLintJob' ) {
				$this->check( in_array( $job->getTitle()->getPrefixedText(), $this->titles, true ), 'Linter job belongs to another page.' );
			}
			if ( str_starts_with( $type, 'frauxSearch' ) ) {
				$this->check( $job->hasExecutionFlag( $job::JOB_NO_EXPLICIT_TRX_ROUND ), 'FrauxSearch job lacks its required real JobRunner execution flag.' );
			}
			$result = $services->getJobRunner()->executeJob( $job );
			$this->check( $result['status'] === true, 'Actual JobRunner failed: ' . $type . ': ' . $result['error'] );
			$services->getJobQueueGroup()->ack( $job );
			if ( $type === 'frauxSearchRefreshPage' ) {
				$pageId = (int)$params['pageId'];
				$this->executedPageRefreshes[$pageId] = ( $this->executedPageRefreshes[$pageId] ?? 0 ) + 1;
			}
			if ( $type === 'frauxSearchScheduleIncomingRefreshes' ) {
				$event = $params['identityEvent'];
				$this->incomingBatches[$event]['title'] = $params['title'];
				$this->incomingBatches[$event]['cursors'][] = $params['startAfter'] ?? 0;
			} elseif ( $type === 'frauxSearchRefreshPage' && isset( $params['identityEvent'] ) ) {
				$this->incomingPages[$params['identityEvent']][$params['pageId']] = true;
			} elseif ( $type === 'frauxSearchScheduleBoostRefreshes' && ( $params['allPages'] ?? false ) === true ) {
				$this->policyRecoveryEvents[$params['policyEvent']][] = $params['startAfter'] ?? 0;
			}
			$this->executedJobs[$type] = ( $this->executedJobs[$type] ?? 0 ) + 1;
			gc_collect_cycles();
		}
		throw new RuntimeException( 'Isolated lifecycle jobs did not converge within the work limit.' );
	}

	private function assertRedisOwner(): void {
		$this->check( $this->redis !== null && $this->redis->get( self::REDIS_OWNER ) === $this->run,
			'Private Redis ownership does not match this lifecycle run.' );
	}

	private function assertPolicyOwner(): void {
		$owner = $this->redis?->get( self::POLICY_OWNER );
		if ( !$this->hasOption( 'policy-import-db' ) ) {
			$this->check( $owner === null || $owner === false, 'Policy fixture cleanup requires the original --policy-import-db option.' );
			return;
		}
		$this->check( $owner === $this->policyOwner(),
			'This private queue does not own the fixed policy page; preserve the original run options.' );
	}

	private function policyOwner(): string {
		$services = MediaWikiServices::getInstance();
		$primary = $services->getConnectionProvider()->getPrimaryDatabase();
		$server = $primary->getServer();
		$domain = $primary->getDomainID();
		$this->check( is_string( $server ) && $server !== '' && $domain !== '', 'The policy fixture requires an identifiable source database.' );
		$config = $services->getMainConfig();
		return hash( 'sha256', json_encode( [ $server, $domain, $config->get( 'DBname' ), $config->get( 'DBprefix' ),
			$this->run, $this->index, $config->get( 'FrauxSearchUrl' ) ], JSON_THROW_ON_ERROR ) );
	}

	private function nextQueuedJob(): object|false {
		if ( $this->redis === null ) {
			return $this->queueDatabase->newSelectQueryBuilder()->select( [ 'job_id', 'job_cmd' ] )->from( 'job' )
				->orderBy( 'job_id' )->limit( 1 )->caller( __METHOD__ )->fetchRow();
		}
		$this->assertRedisOwner();
		$sizes = MediaWikiServices::getInstance()->getJobQueueGroup()->getQueueSizes();
		ksort( $sizes, SORT_STRING );
		foreach ( $sizes as $type => $size ) {
			if ( $size > 0 ) { return (object)[ 'job_cmd' => $type ]; }
		}
		return false;
	}

	private function queueIsEmpty( bool $allowDelayed = false ): bool {
		if ( $this->redis === null ) {
			return (int)$this->queueDatabase->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'job' )
				->caller( __METHOD__ )->fetchField() === 0;
		}
		$this->assertRedisOwner();
		$queues = MediaWikiServices::getInstance()->getJobQueueGroup();
		$delayed = false;
		foreach ( $queues->getQueueTypes() as $type ) {
			$queue = $queues->get( $type );
			if ( $queue->getSize() > 0 ) { return false; }
			$this->check( $queue->getAcquiredCount() === 0 && $queue->getAbandonedCount() === 0,
				'Private Redis contains claimed or abandoned work: ' . $type );
			if ( $queue->getDelayedCount() > 0 ) {
				$this->check( $allowDelayed && $type === 'cirrusSearchIncomingLinkCount', 'Private Redis contains unhandled delayed work: ' . $type );
				$delayed = true;
			}
		}
		return !$delayed;
	}

	private function title( string $name ): Title {
		MediaWikiServices::getInstance()->getLinkCache()->clear();
		return Title::newFromText( $this->titles[$name] );
	}

	private function id( string $name ): int {
		$title = $this->title( $name );
		$id = (int)MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()
			->newSelectQueryBuilder()->select( 'page_id' )->from( 'page' )
			->where( [ 'page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey() ] )
			->caller( __METHOD__ )->fetchField();
		$this->check( $id > 0, 'Expected live test page: ' . $name );
		return $id;
	}

	private function document( string $name ): array {
		$document = $this->client->getDocument( $this->id( $name ) );
		$this->check( $document !== null, 'Expected indexed source: ' . $name );
		return $document;
	}

	private function assertDerivedSource( string $name, array $initial, array $outgoing, bool $changed ): void {
		$current = $this->document( $name );
		$this->check( $current['revision_id'] === $initial['revision_id'] && $current['outgoing_link_ids'] === $outgoing,
			'Unchanged source revision did not receive current resolved IDs: ' . $name );
		$this->check( ( $current['document_hash'] !== $initial['document_hash'] ) === $changed, 'Derived source hash did not track target identity: ' . $name );
		$completion = $this->client->withIndex( $this->index . '_completion' )->getDocument( $current['id'] );
		$this->check( $completion !== null && $completion['document_hash'] === $current['document_hash'], 'Full/completion source state diverged.' );
	}

	private function assertAbsentCompletion( string $name ): void {
		$this->check( $this->client->withIndex( $this->index . '_completion' )->getDocument( $this->id( $name ) ) === null,
			'Noncanonical redirect entered completion: ' . $name );
	}

	private function assertAlias( int $id, string $alias, bool $expected ): void {
		$document = $this->client->withIndex( $this->index . '_completion' )->getDocument( $id );
		$this->check( $document !== null && in_array( $alias, $document['redirects'], true ) === $expected,
			'Canonical alias membership is incorrect: ' . $alias );
	}

	private function assertCoordinationResolved(): void {
		$state = IndexCoordinatorFactory::create()->status();
		$this->check( !$state['coordinationLost'] && $state['run'] === null && $state['pending'] === null,
			'Preserving test evidence because coordinated work remains unresolved or active state was lost.' );
	}

	private function assertCoordinationIdle( bool $leaseHeld = false ): void {
		$this->check( $this->coordinationStore->readState() === null
			&& $this->client->withIndex( $this->index . '_coordination' )->getIndexMetadata() === null,
			'Test coordination did not retire its Redis state and Meilisearch guard.' );
		$keys = $this->coordinationStore->keyNames();
		if ( $leaseHeld ) {
			$this->coordinationStore->assertLocked();
			$keys = array_slice( $keys, 1 );
		}
		$this->check( $this->coordinationConnection->evaluate( "return redis.call('EXISTS', unpack(KEYS))", $keys, [] ) === 0,
			'Unexpected retained Redis coordination keys; inspect this run before proceeding.' );
	}

	private function cleanup(): void {
		if ( !$this->coordinationOwned ) { return; }
		$store = $this->coordinationStore;
		$this->assertCoordinationResolved();
		$this->assertPolicyOwner();
		if ( $this->queueCreated ) {
			$this->drain();
			foreach ( array_chunk( array_reverse( array_keys( $this->titles ) ), 25 ) as $names ) {
				foreach ( $names as $name ) { $this->delete( $name ); }
				$this->drain();
			}
			$this->drain();
		}
		$this->check( $store->lock( 0 ), 'Cannot acquire the isolated coordinator for cleanup.' );
		try {
			$this->assertCoordinationIdle( true );
			if ( $this->indexesCreated ) {
				foreach ( [ $this->index, $this->index . '_completion' ] as $index ) {
					$store->assertLocked();
					$client = $this->client->withTaskPollHandler( $store->assertLocked( ... ) )->withIndex( $index );
					if ( !$client->indexExists() ) { continue; }
					$this->check( $client->listDocuments( 0, 1, [ 'id' ] )['total'] === 0, 'Test documents survived page/job cleanup.' );
					$store->assertLocked();
					$client->waitForTask( $client->deleteIndex() );
				}
			}
		} finally { $store->unlock(); }
		$this->assertCoordinationIdle();
		$this->coordinationOwned = false;
		if ( !$this->queueCreated ) { return; }
		if ( $this->redis === null ) {
			$this->queueDatabase->query( 'DROP TABLE ' . $this->queueDatabase->tableName( 'job' ), __METHOD__ );
		} else {
			$this->assertRedisOwner();
			$this->check( $this->queueIsEmpty(), 'Private Redis is not empty at cleanup.' );
			$this->check( $this->redis->flushDB() === true, 'Failed to remove private Redis test metadata.' );
		}
		$this->output( json_encode( [ 'event' => 'lifecycle_cleanup_finished', 'index' => $this->index,
			'queue_backend' => $this->queueBackend,
			'jobs' => $this->executedJobs, 'excluded_global_jobs' => $this->excludedJobs ], JSON_UNESCAPED_SLASHES ) . "\n" );
		$this->output( "Removed random live test pages, drained their dedicated queue/journal, and removed isolated indexes/queue state.\n" );
		$this->output( "MediaWiki deletion logs and archived test revisions remain as normal deletion history.\n" );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) { throw new RuntimeException( $message ); }
	}
}

$maintClass = CheckFrauxSearchLifecycle::class;
require_once RUN_MAINTENANCE_IF_MAIN;

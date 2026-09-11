# Integration checks

`composer test:integration` runs search behavior, coordinator fault checks,
MediaWiki lifecycle checks, and ranking, stopping at the first failure. Use a local test wiki: the
lifecycle checks create, move, delete, restore, and import pages.

## Prepared helper and wrapper

The wrapper requires an existing helper Pod with:

- A Ready, Running wiki container using the completed deployment's image and
  running `sleep DURATION` or `sleep infinity`.
- Exactly one other container, named `redis`, bound to `127.0.0.1:6389`.
- No host networking, PVC, or hostPath volumes.
- An empty private Redis database 0 for lifecycle jobs.
- A base configuration with wiki database access, Meilisearch credentials, and
  supported coordination Redis settings. Disable extension-specific outgoing
  notifications, such as Discord, in that configuration.

From the extension checkout:

```sh
export FRAUXSEARCH_TEST_POD=your-prepared-test-pod
export FRAUXSEARCH_TEST_INTERWIKI_PREFIX=verified_external_prefix
composer test:integration
```

Use an external prefix present in that wiki's `interwiki` table. The wrapper
checks the helper and deployment before creating resources; it creates neither.

| Environment variable | Default or requirement |
| --- | --- |
| `FRAUXSEARCH_TEST_POD` | Required helper Pod name |
| `FRAUXSEARCH_TEST_INTERWIKI_PREFIX` | Required existing external prefix |
| `FRAUXSEARCH_TEST_CONTAINER` | `maintenance` |
| `FRAUXSEARCH_TEST_BASE_CONF` | `/var/www/html/LocalSettings.php`; absolute path |
| `FRAUXSEARCH_TEST_RUN` | Wrapper generates a fresh 12-character lowercase hex token |
| `KUBE_CONTEXT` | `minikube` |
| `KUBE_NAMESPACE` | `default` |
| `MEDIAWIKI_DEPLOYMENT` | `yuisis` |
| `MEDIAWIKI_CONTAINER` | `yuisis` |

Ranking alone needs no helper:

```sh
composer test:ranking
```

By default it uses the installed script and fixture with
`http://127.0.0.1:8080/api.php` inside the selected wiki container. To run the
checkout's script and `tests/fixtures/ranking.json` against an existing local API
connection, replace `PORT` with its port:

```sh
FRAUXSEARCH_RANKING_API_URL=http://127.0.0.1:PORT/api.php composer test:ranking
```

This override uses the local PHP executable and creates no connection or port
forward itself. Full-text cases explicitly request `srwhat=text`. Normal tests
must not contact production.

## Standalone commands

With the helper and configuration above:

```sh
kubectl --context minikube -n default exec "$FRAUXSEARCH_TEST_POD" \
  -c "${FRAUXSEARCH_TEST_CONTAINER:-maintenance}" -- \
  php84 extensions/FrauxSearch/tests/integration/searchBehavior.php \
  --conf /var/www/html/LocalSettings.php --execute

kubectl --context minikube -n default exec "$FRAUXSEARCH_TEST_POD" \
  -c "${FRAUXSEARCH_TEST_CONTAINER:-maintenance}" -- \
  php84 extensions/FrauxSearch/tests/integration/coordination.php \
  --conf /var/www/html/LocalSettings.php --execute

kubectl --context minikube -n default exec "$FRAUXSEARCH_TEST_POD" \
  -c "${FRAUXSEARCH_TEST_CONTAINER:-maintenance}" -- \
  php84 extensions/FrauxSearch/tests/integration/performance.php \
  --conf /var/www/html/LocalSettings.php --execute \
  --pages 25 --batch-size 25 --start-after 0
```

`searchBehavior.php` uses `BASE_search_test_<12 random hex>` to check exact titles
and aliases, popularity, pagination, namespaces, phrase and exclusion syntax,
and escaped snippets against real Meilisearch. It creates no wiki pages or Redis
state and removes its test index after all tasks settle. Unknown submissions or
unfinished tasks preserve the printed index for inspection.

The coordinator and performance checks generate `BASE_coordination_test_<12 random hex>` indexes and print their
identifiers. They read source data and mutate only their isolated indexes and
coordination keys. The application key needs index creation, settings, document,
swap, and deletion access under that prefix; task requests use the configured
task key. Redis fault checks additionally use `PTTL`, `PEXPIRE`, and deletion of
the exact owned keys. See [configuration](../../docs/reference.md#configuration).

For a standalone lifecycle run, set `FRAUXSEARCH_TEST_RUN` to a fresh 12-character
lowercase hex token, then:

```sh
kubectl --context minikube -n default exec "$FRAUXSEARCH_TEST_POD" \
  -c "${FRAUXSEARCH_TEST_CONTAINER:-maintenance}" -- \
  env FRAUXSEARCH_TEST_BASE_CONF=/var/www/html/LocalSettings.php \
  FRAUXSEARCH_TEST_RUN="$FRAUXSEARCH_TEST_RUN" \
  FRAUXSEARCH_TEST_REDIS_SERVER=127.0.0.1:6389 \
  php84 extensions/FrauxSearch/tests/integration/lifecycle.php \
  --conf /var/www/html/extensions/FrauxSearch/tests/integration/lifecycleConf.php \
  --interwiki-prefix "$FRAUXSEARCH_TEST_INTERWIKI_PREFIX" --execute
```

Lifecycle indexes use `BASE_lifecycle_test_<token>`. Existing indexes, guards,
Redis keys, queues, or live test titles reject a fresh run.

Optional lifecycle checks:

| Option | Check |
| --- | --- |
| `--expect-search-type 'CirrusSearch\CirrusSearch'` | Require the actual selected backend before writing fixture pages |
| `--overlap-bootstrap` | Deliver edits, moves, deletions, restores, and imports during the initial generation; compare both indexes after cutover |
| `--fanout` | Create 1,001 additional referring pages, verify scheduler continuation, and repair deliberately dropped delivery through reconciliation |
| `--policy-import-db NAME` | Import the fixed boost-policy title; requires the exact initially empty source database and the private Redis queue |
| `--wait-delayed SECONDS` | Wait 0–600 seconds for separately running native Redis chron to release delayed Cirrus incoming-link jobs |

The base configuration selects the search engine. CirrusSearch runs require its
local Elasticsearch indexes and reviewed queue job types. The policy-import
option must use a separate empty source wiki; isolated Meilisearch indexes alone
do not isolate the wiki's policy page.

## Coverage

The coordinator checks exercise real Redis and Meilisearch:

- Lease exclusion, stale-owner rejection, and safe unlock after lease loss.
- Journal sequencing and exact acknowledgement while producers append.
- Recovery of unknown document, swap, and guard-deletion responses without
  duplicate submissions.
- Full/completion cutover with source edits, deletions, and new pages.
- Idle cache loss followed by a fresh update; active state loss followed by a
  blocked write and explicit full recovery under the retained guard.
- Final document payloads, task completion, retired state, and generation cleanup.

Faults discard real HTTP acknowledgements after acceptance. They do not simulate
server failover or host crashes.

Lifecycle checks use native MediaWiki hooks, queue serialization, and `JobRunner`.
They verify existing-page edits and derived source changes after target creation,
moves, deletion, restoration, imports, and interwiki redirects. The optional
checks above cover bootstrap overlap, incoming fan-out, reconciliation recovery,
and fixed-title policy imports. Delayed retries, claim recycling, and exhaustion
require the separate native-queue checks.

All job-type overrides point to the private loopback Redis queue. Main, stash,
and WAN caches are disabled; the inherited coordination cache selection is retained.
The template-refresh scenario enables a private in-process parser cache to verify
that cached transclusions update at the same source revision and unchanged
refreshes submit no document tasks. Parser caching is disabled outside that scenario.
Email, RC feeds, and pingbacks are disabled. The optional
SQL queue mode is not used by the wrapper; lifecycle source changes and the
per-run advisory lock still use the wiki database.

Only allowed page/link/search jobs execute. CheckUser signal jobs run with their
feature disabled. `checkuserPruneCheckUserDataJob`,
`checkuserUpdateUserCentralIndexJob`, and `recentChangesUpdate` are excluded from
the private queue and reported separately. Other unexpected job types stop the
run.

Cirrus `DeletePages`, `IncomingLinkCount`, and normal/prioritized `LinksUpdate`
jobs require this run's fixture titles. Queued Elastica writes, external-index
updates, archive jobs, mass indexing, and sanity-check jobs stop the run. Delayed
incoming-link jobs require native chron on the private Redis sidecar; the harness
does not advance their release timestamps.

`performance.php` reports build/write/cutover times, document bytes, task counts,
throughput, and incremental P50/P95 latency. Its incremental pass uses a warm
cache and includes temporary guard tasks. Dependency IDs are collected in memory;
shared job submission and processing are excluded. A bounded sample does not
predict whole-corpus throughput.

## Cleanup and interrupted runs

Successful runs wait for tasks, drain their jobs, and verify that Redis state and
the Meilisearch guard retired. Cleanup removes only owned indexes and private
queue metadata. Lifecycle deletion leaves normal MediaWiki logs and archived test
revisions.

Unknown tasks, active rebuilds, lost active state, or claimed/delayed/abandoned
jobs prevent cleanup. Keep the helper, its queue, and the printed run identifiers
until the cause is resolved. Do not clear retained state or delete a coordination
guard to bypass recovery; use the [recovery procedure](../../docs/coordination.md#recovery-commands).

After resolving a lifecycle failure, repeat the standalone lifecycle command with
its **original token, helper, base configuration, Redis endpoint, and options**, adding
`--cleanup-only`. This removes the old run without rerunning its behavior checks.
Do not use `run.sh`: its empty-queue preflight rejects retained jobs.

Absent Redis with no guard is ordinary idle. A retained guard with missing Redis
requires active-state recovery before cleanup. Coordinator cleanup never erases
unresolved state; lifecycle cleanup requires zero ready, claimed, delayed, and
abandoned jobs before clearing its private queue metadata.

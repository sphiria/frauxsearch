# Index coordination and recovery

Redis holds temporary writer locks, page requests, rebuild progress, and pending
tasks. Active work also has an empty Meilisearch index, `BASE_coordination`, whose
primary key identifies its epoch. Successful completion removes both the guard
and Redis state. Later indexing starts automatically.

## Storage and scope

`FrauxSearchCoordinationCacheType` selects a single-server `RedisBagOStuff` entry
from MediaWiki's `ObjectCaches`, defaulting to `MainCacheType`. Other cache types,
custom factories, and multiple Redis servers are unsupported.

An optional explicit connection overrides the cache selection:

```php
$wgFrauxSearchCoordinationRedis = [
    'server' => 'redis:6379',
    'database' => 0,
    'password' => getenv( 'REDIS_PASSWORD' ),
];
```

`password` accepts a string, an ACL `[username, password]` pair, or `null` for no
authentication. Connection/read timeouts default to one/five seconds. The
configured cache prefix is applied to coordination keys.

All writers for an index pair must use the same Redis configuration, wiki
database/prefix, Meilisearch URL, and base index. Stop old writers before changing
these settings or replacing their protocol. Restoring an older Redis snapshot
can resurrect obsolete operations; it is not equivalent to clearing idle state.

Clearing Redis at idle requires no recovery. Clearing or evicting active state
leaves the Meilisearch guard and blocks new writes. Clearing MediaWiki's job queue
also loses undelivered jobs; those require source reconciliation.

## Writes and rebuilds

Each mutation records an intent before submission, records its task UID, and waits
for success. Unknown outcomes block further mutations. Refreshes acknowledge only
the journal entries they processed. Retirement seals the journal, records guard
deletion, waits for that task, and removes Redis state.

Full rebuilds replay delivered page changes and swap full-text and completion
generations together. Completion-only rebuilds swap completion alone. Swap success
is recorded before old-generation cleanup. Undelivered jobs and direct database
changes are outside the journal; drain workers and reconcile after rebuilding.

Only one mutation is outstanding per pair. Opening and retiring a scope adds two
Meilisearch tasks. The 30-second writer lease renews during guarded operations and
task polls; a blocking source build or HTTP call has no background heartbeat.
A call that outlasts the lease loses ownership.

## Recovery commands

Run from the MediaWiki root. Add `--index BASE` consistently when overriding the
configured index.

Read status or drain known pending tasks and page requests:

```sh
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php --drain
```

Finish a ready/interrupted cutover, or abort an ordinary incomplete build:

```sh
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php --finish-run RUN_ID
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php --abort-run RUN_ID
```

A run interrupted during its initial scan must be aborted and rebuilt; it cannot
be activated with `--finish-run`. An activated run needs finish/cleanup, not abort.

For an unknown response, stop the sender and independently identify the exact
task in Meilisearch history before attaching it:

```sh
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php \
  --operation-id OPERATION_ID --task-id TASK_UID
```

The command checks index/type or exact swap pairs. Two document tasks for the same
index/type still require independent identification. Retain task history while
operations are pending.

Only after stopping the sender and proving that submission never occurred:

```sh
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php \
  --confirm-not-submitted OPERATION_ID
```

A timeout or `task_not_found` does not prove non-submission. Repeating a successful
swap reverses it. Repeating an ambiguous guard deletion can delete a later epoch's
guard.

## Lost state during active work

Status reports `coordinationLost` when a guard remains but Redis state is missing
or inconsistent. Searches continue; index writes stop.

Stop **all indexing senders and outstanding HTTP requests** first. An empty task
queue alone is insufficient. Then use the exact `guardEpoch` from status:

```sh
php extensions/FrauxSearch/maintenance/rebuildFrauxSearchIndex.php \
  --recover-lost-state EPOCH --confirm-writers-stopped
```

Recovery verifies the epoch, settles server tasks, removes abandoned generations,
and starts a full source rebuild under the same guard. Active indexes remain until
both replacements are ready. Recovery rejects completion-only, bounded, no-reset,
and dry-run modes. It cannot be aborted into idle; if the scan fails, restart full
recovery with writers stopped. Do not delete the guard to bypass recovery.

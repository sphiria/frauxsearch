# Search and maintenance reference

## Configuration

Set these in `LocalSettings.php`; workers and maintenance commands need the same
values.

| Setting | Default | Meaning |
| --- | --- | --- |
| `FrauxSearchUrl` | `http://127.0.0.1:7700` | Meilisearch endpoint |
| `FrauxSearchApiKey` | Empty | Search, document, settings, index, and swap access to `BASE` and `BASE_*` |
| `FrauxSearchTaskApiKey` | Empty | `tasks.get` with index scope `*` for global swap status; empty uses the application key |
| `FrauxSearchIndex` | `mediawiki` | Base index; completion, generations, and the temporary coordination guard use its prefix |
| `FrauxSearchTimeout` | `5` | HTTP timeout in seconds |
| `FrauxSearchCoordinationCacheType` | `null` | Redis `ObjectCaches` entry; null uses `MainCacheType` |
| `FrauxSearchCoordinationRedis` | `null` | Optional explicit Redis connection overriding cache selection |

Redis connection options and recovery commands are in [coordination](coordination.md).

## Search behavior

FrauxSearch supports standard search, title search, namespace filters, highlighted
snippets, and OpenSearch completion.

For MediaWiki API `action=query&list=search` requests, use `srwhat=text` for
full-text search or `srwhat=title` for title/alias search. Omitting `srwhat` uses
MediaWiki's title-search default.

Full-text queries use the following syntax:

| Query | Meaning |
| --- | --- |
| `sky blue` | Require both words, with normal typo tolerance and final-word prefix matching |
| `+sky +blue` | The same required words; `+` does not disable typo or prefix matching |
| `"sky blue"` | Require the normalized word sequence, without typo or prefix matching |
| `sword -summer` | Require `sword` and exclude the exact normalized word `summer` |
| `sword -"sky blue"` | Require `sword` and exclude the normalized phrase |
| `File:sword` | Search the recognized namespace, overriding the selected namespaces |
| `all:sword` | Search all searchable namespaces |

Namespace prefixes must lead the query. Localized and canonical namespace names
are recognized; unrelated colons in page titles and URLs remain ordinary text.
Phrase matching follows Meilisearch's case, accent, and punctuation normalization;
it does not compare literal bytes. Exclusions have no typo or prefix expansion.
An excluded compound such as `-sky-blue` is treated as a quoted negative phrase.

Meilisearch considers at most ten positive query terms; its tokenizer, rather
than whitespace alone, determines those terms. Exclusions are sent before the
positive terms so that reaching this limit does not discard trailing exclusions.
Keep queries short. OpenSearch completion uses its own matching behavior.

Uppercase `OR`, known CirrusSearch operators such as `intitle:`, `incategory:`,
`insource:`, and `prefix:`, unquoted `*` and `\?` wildcards, and fuzzy/proximity
suffixes such as `word~2` and `"sky blue"~2` produce a search error. Ordinary `?`
characters, parenthesized variant titles, and internal `+` or `-` remain text.
`AND`, `NOT`, and lowercase `or` are ordinary search words, not Boolean operators;
quote `"OR"` to search for that word. Empty phrases, unmatched quotes,
backslash-escaped quotes, and missing or repeated unary signs also produce errors.

AdvancedSearch's form controls require CirrusSearch operators. Leave
`wfLoadExtension( 'AdvancedSearch' )` disabled while selecting FrauxSearch as the
search backend; its Composer package may remain installed for switching back.
MediaWiki's normal Special:Search form and namespace controls remain available.
AdvancedSearch's per-user disable preference does not disable its form for
anonymous users.

Full-text search, title search, and completion return canonical non-redirect
pages, matching their titles and local redirect aliases. Full-text search also
matches page content.
Namespace filters apply to the canonical target. Redirect chains follow at most
10 hops; loops, longer chains, broken redirects, and interwiki redirects are
excluded. Literal prefix search can still return redirect page titles.

Whole-title and whole-alias matches rank first for plain queries. Other results
rank by matched words, typos, proximity, matching attribute, template boost, and
incoming links, with token exactness breaking later ties. Variant and subpage
titles receive no automatic penalty; template boost rules can adjust their
priority. Search results are limited to the first 1,000 matches.

Literal prefix search filters and sorts the first 1,000 Meilisearch candidates.
Matches outside that window can be omitted; offsets at or above 1,000 return no
results. Normal full-text search and completion have separate pagination.

Incoming-link counts represent distinct linking pages rather than page views. Target
creation, moves, deletion, restoration, and imports update affected source
documents asynchronously. Workers must process `frauxSearchScheduleIncomingRefreshes`,
`frauxSearchScheduleBoostRefreshes`, and `frauxSearchRefreshPage`.

Wikitext is rendered with MediaWiki's indexing parser options. Searchable text
includes visible prose, headings, tables, and captions, excluding navigation,
styles, and reference markers. Other content models retain their own search
representation. Mark content `navigation-not-searchable` to omit it from indexed
text. CSS-only tooltip text is otherwise included, and spacing supplied only by
CSS is not preserved. Template freshness follows MediaWiki's parser invalidation and
update jobs; time-dependent template output changes only when a page is refreshed.
Rendering can make indexing template-heavy pages substantially slower than
reading their source. Switching an existing index to rendered text requires a
full rebuild.

## Index maintenance

Run scripts from the MediaWiki root. Script paths below are relative to
`extensions/FrauxSearch/maintenance/`.

| Script | Action |
| --- | --- |
| `rebuildFrauxSearchIndex.php` | Build and atomically activate full-text and completion generations |
| `rebuildFrauxSearchCompletionIndex.php` | Build and activate completion only |
| `configureFrauxSearchIndexes.php` | Apply index settings to both active indexes |
| `refreshFrauxSearchBoosts.php` | Refresh source documents after policy changes or missed updates |
| `reconcileFrauxSearchIndex.php` | Audit both active indexes; read-only by default |
| `recoverFrauxSearchIndex.php --drain` | Settle known tasks and refresh journaled pages |
| `checkFrauxSearchHealth.php --require-idle` | Check index settings and outstanding coordination/jobs |

Rebuilds leave the active indexes available until activation. Run workers, drain
the journal, and reconcile afterward. Changes to indexed fields or derived values
require a rebuild or reconciliation repairs; settings-only changes use the
configuration command.

Rebuild options:

| Option | Behavior |
| --- | --- |
| `--dry-run` | Build source documents and report progress without index mutations |
| `--no-reset` | Update active indexes in place |
| `--start-after PAGE_ID` | Resume a dry run or an in-place update |
| `--stop-after PAGE_ID` | Bound a dry run or `--no-reset` update |
| `--index BASE` | Override the base index |
| `--max-pending N` | Accepted upper bound; values above one do not increase mutation concurrency |

For bounded generation tests, combine `--generation --index TEST --stop-after ID`
with an isolated base. Never activate a partial generation over normal indexes.
An interrupted generation uses [recovery](coordination.md#recovery-commands), not
an in-place resume.

## Template boosts

Create `MediaWiki:Frauxsearch-boost-templates` with one rule per line:

```text
Template:Character|150%
Template:Weapon|150%
Template:BackOneLevel|50%
```

Comments start with `#`. Multipliers apply in bytewise template-name order, rounding
after each multiplication. Malformed rules are ignored and logged as
`invalid_boost_rule` warnings in the `FrauxSearch` log.

Policy edits queue affected pages. If the previous policy is unavailable,
including successful policy imports, recovery scans all pages. For a manual
refresh:

```sh
php extensions/FrauxSearch/maintenance/refreshFrauxSearchBoosts.php --batch-size 1000
```

`--batch-size` controls source scans; documents are refreshed individually.
`--start-after`, `--stop-after`, and `--dry-run` are also supported.

## Reconciliation

```sh
php extensions/FrauxSearch/maintenance/reconcileFrauxSearchIndex.php
```

Reports missing, extra, stale, unbuildable, and unexpected documents, plus index
settings drift. It compares complete stored payloads, including derived fields
that can change without a new page revision.

Incremental refreshes also compare complete payloads and skip already-current
copies. Missing or corrupted copies are repaired independently; dependency jobs
are still scheduled before any document changes.

`--queue-repairs` queues one refresh per mismatched page ID after the entire audit
succeeds. It does not change settings; use `configureFrauxSearchIndexes.php` for
those. An unbuildable page absent from both indexes gets no repair job.

Use `--batch-size` (default 100, range 1–1000), `--start-after`, and `--stop-after`
for bounded checks. Bounds must be nonnegative integers, with a nonzero stop
greater than the start. Smaller batches reduce memory and transfer size for large
documents.

Malformed/incomplete responses, duplicate IDs, and invalid page IDs abort before
queuing repairs. Noncanonical document IDs require manual inspection.

The audit is live, not a shared snapshot of MediaWiki and Meilisearch. Concurrent
changes can produce or hide mismatches. Drain schedulers, page jobs, and index
tasks, then repeat during a stable window. Crashes before lifecycle jobs are
persisted and direct SQL changes may also require reconciliation.

## Testing

Unit tests use a local HTTP fixture and a temporary Redis UNIX-socket server.
They require `pdo_sqlite` and the `redis-server` executable:

```sh
composer install
composer test
```

`composer test:ranking` checks the local wiki against frozen expectations in
`tests/fixtures/ranking.json`. Review intentional order changes before updating
those expectations.

`composer test:integration` runs coordinator faults, MediaWiki lifecycle changes,
and ranking against a prepared local test Pod. See the
[integration guide](../tests/integration/COORDINATION_TESTS.md) for its requirements,
commands, and cleanup. Normal tests must not contact production.

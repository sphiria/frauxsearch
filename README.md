# FrauxSearch

### UNSTABLE, DON'T USE IN PRODUCTION.

A Meilisearch search backend for MediaWiki, with full-text search, title search,
namespace filters, highlighted snippets, completion, and incremental indexing.

## Installation

Requires MediaWiki >= 1.46, PHP >= 8.3 with curl and Redis extensions,
Meilisearch, a configured Redis object cache, and MediaWiki job workers.
Bulk maintenance commands require PHP CLI with `proc_open` enabled.

Merge these entries into the wiki's Composer configuration:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/sphiria/frauxsearch" }
  ],
  "require": { "sphiria/frauxsearch": "dev-main" },
  "config": { "allow-plugins": { "composer/installers": true } }
}
```

Run `composer update sphiria/frauxsearch`. The package installs at
`extensions/FrauxSearch`.

Load and configure FrauxSearch while keeping the existing search backend selected:

```php
wfLoadExtension( 'FrauxSearch' );
$wgFrauxSearchUrl = getenv( 'MEILI_URL' );
$wgFrauxSearchApiKey = getenv( 'MEILI_API_KEY' );
$wgFrauxSearchTaskApiKey = getenv( 'MEILI_TASK_API_KEY' );
$wgFrauxSearchIndex = 'mediawiki';
```

Redis defaults to `$wgMainCacheType`; the optional
`$wgFrauxSearchCoordinationCacheType` selects another existing cache.

From the MediaWiki root, build both indexes:

```sh
php extensions/FrauxSearch/maintenance/rebuildFrauxSearchIndex.php
```

Run MediaWiki workers for ordinary core jobs, including `htmlCacheUpdate` and
`refreshLinks` for template invalidation, as well as
`frauxSearchScheduleIncomingRefreshes`, `frauxSearchScheduleBoostRefreshes`, and
`frauxSearchRefreshPage`. Wait for those queues to drain, then drain the indexing
journal and audit the result:

```sh
php extensions/FrauxSearch/maintenance/recoverFrauxSearchIndex.php --drain
php extensions/FrauxSearch/maintenance/reconcileFrauxSearchIndex.php
php extensions/FrauxSearch/maintenance/checkFrauxSearchHealth.php --require-idle
```

Repeat the audit after any follow-up jobs finish. Once searches, completion,
and edit delivery are verified, select the new backend:

```php
$wgSearchType = 'FrauxSearch';
```

Leave AdvancedSearch unloaded when selecting FrauxSearch; its form requires
CirrusSearch operators. Its Composer package can remain installed.
See [search behavior](docs/reference.md#search-behavior) for query syntax,
ranking, redirects, and prefix limits.

# AGENTS.md — Noiiolelo

Guidance for AI agents and developers working in this repository. Topic docs live in `docs/` — start with `docs/README.md` for the index (ingestion, index structures, searching, save manager).

## General guidance
- Use skills where available
- Prefer codegraph for code exploration
- Prefer fd and rg over find and ls -R

## Coding guidelines
- Failures in configuration or infrastructure are to cause fast and loud failure so they can be fixed, not fallback to defaults
- Prefer object-oriented development
- Avoid redundancy and duplication. Use class inheritance, shared helper classes or utility functions. Look for existing shared packages and for opportunities to separate out shared packages that can be used by other projects. Most shared packages have source at /var/www/html/src but there may be a few top-level projects like /var/www/html/idp-client.
- Avoid explicit SQL other than in transient test files. Encapsulate into class methods.

## Test guidelines
- There must be a tests/run-tests.sh [-v/--verbose] [--diagnose] script that runs all tests and generates a json summary in tests/reports
- Results of test runs are published through tests/index.php
- Test suites are to cover functionality, not ephemeral aspects like color, font, exact wording, etc which are likely to change and which should not break the test suite
- If there are any SQL script files for creating tables and indices, the test suite should validate that they are consistent with current database schema

## Search provider philosophy

The existence of four parallel search providers is an artifact of an ongoing exploration into which one works best - most functionality and accuracy, good resource management. Eventually all but one will be retired. That means each one must be completely independent and able to do all indexing, verification and searches without reliance on any other provider. In the meantime, to save resource usage and time, there are scripts like createindex.php to bootstrap one from another. As a consequence, there should never be a requirement that one provider exists in order to deliver or verify correctness of another, although during the current development we do that frequently as part of the ongoing assessment.

The four search providers today: MySQL, Postgres, Elasticsearch, OpenSearch (see `PROVIDERS` in `scripts/updatenoiiolelo.sh`)

Each search provider has its own indices/database

A fifth directory, `providers/Neo4j/`, is an entity/relationship graph provider on a separate interface (`GraphSearchProviderInterface`) — not one of the four data providers above.

## Project organization

Hawaiian-language corpus search: web sources are scraped, indexed into the four backends, and searched through a PHP web frontend (`index.php`) and JSON API (`api.php`). `docs/` holds the topic docs (ingestion, searching, index structures, grammar patterns, web pages) — this section is the orientation map.

| Directory | Contents |
|---|---|
| `lib/` | Shared provider-agnostic code: `SearchProviderInterface`, `AbstractSearchProvider`, `ProviderFactory`, `SaveManagerFactory`, `provider.php` (`getProvider()` resolution), `EnvConfig`, grammar scanner, embedding client |
| `providers/` | One self-contained dir per backend — `Elasticsearch/` (largest: `src/`, `python/`, `docs/`), `OpenSearch/`, `MySQL/`, `Postgres/`, `Neo4j/` |
| `db/` | Legacy data layer: `funcs.php` (`Laana` MySQL class, `DB` PDO wrapper), `parsehtml.php` (HTML→sentences + per-source subclasses), `PostgresFuncs.php` (`PostgresLaana`), `ulukau/` (Node.js Express scraper service) |
| `scripts/` | CLI tools — `save.php` (ingest), `createindex.php` (ES/OS index build), `search.php`, `pg_import.php` (PG backfill), `migrate_es_to_os.php`, `populate_grammar_patterns.php`, `updatenoiiolelo.sh` (cron) |
| `ops/` | HTTP/AJAX endpoints behind `.htaccess` rewrites — `getPageHtml.php` (infinite scroll), `resultcount.php`, `getGrammarPatterns.php`, `getSourcesHtml.php`, word cleanup, graph views |
| `tests/` | PHPUnit 10 suite + `run-tests.sh` runner + custom OpenSearch runner — see `tests/README.md` |
| repo root | Web entry points (`index.php`, `api.php`, `provider-dashboard.php`, `context.php`, raw views), legacy browser-driven ingest pages (`extract*.php`, `*pages.php` — MySQL only), static info HTML |

Smaller dirs: `static/` (assets), `data/` (name lists, word-cleanup overrides), `review/` (standalone document-review app), `bin/` (`auth`, `os-api-key.sh`), `docs/`, `logs/`, `php/php/` (two one-off utilities).

### How the pieces fit

- Search: `index.php` / `ops/getPageHtml.php` → `getProvider()` (request param → `.env PROVIDER` → MySQL default) → `ProviderFactory` → backend-specific `search()`/`getSentences()`.
- Ingest: `scripts/save.php --provider=X --parser=Y` → `SaveManagerFactory` → per-provider SaveManager. Bootstraps: `createindex.php` (MySQL→ES/OS), `pg_import.php` (MySQL→PG), `migrate_es_to_os.php` (ES→OS). The cron driver `scripts/updatenoiiolelo.sh` loops 3 parsers × 4 providers, then the grammar-pattern backstop for MySQL/Postgres.
- Provider implementation is not independent: `PostgresProvider extends MySQLProvider`; `OpenSearchProvider extends ElasticsearchProvider` — MySQL and Elasticsearch are the two base implementations.
- Adding a provider: implement `SearchProviderInterface`, add a SaveManager if it ingests, register in `ProviderFactory::create()`, `SaveManagerFactory::create()`, and `getKnownProviders()` (`lib/provider.php`), and add it to `tests/BaseTestCase::$validProviders` for parity coverage.

## Key style elements

No formatter/linter is configured anywhere (no `.editorconfig`, phpcs, eslint, prettier) — style is de-facto. Match the file you're in; for new code:

- 4-space indent; short arrays `[]`; typed properties/params/returns where practical; camelCase methods — never copy legacy lowercase names (`getsentences`, `getsources`).
- Brace style is mixed (Allman in `lib/` + tests, K&R in `db/` + older providers). Use K&R in all new files. When making substantial changes to a file with Allman style, change it to K&R.
- SQL only via PDO prepared statements with named placeholders through `DB`/`getDBRows()`/`executePrepared()` — never interpolate input into SQL.
- Errors: log-and-continue dominates — `\Avisitor\Monolog\Logger::logError()` in `lib/`, `debuglog()` in `db/` (silenced under `PHPUNIT_RUNNING`). `\RuntimeException` for vector/hybrid search failures.
- Pure-PHP class files omit the closing `?>`; older web/script files keep it.
- JS/CSS have no build step or modules; `static/helpers.js` and `highlightterms.js` are served as PHP (embedded `<?...?>` tags); `infinite-query.js` is vendored — don't hand-edit.

## External dependencies

Composer (`composer.json`):

- `elasticsearch/elasticsearch` ^8.13 and `opensearch-project/opensearch-php` ^2.5 — official low-level clients behind our own `ElasticsearchClient`/`OpenSearchClient` wrappers.
- `guzzlehttp/guzzle` ^7.9 (HTTP); `symfony/dom-crawler` + `symfony/css-selector` ^7.4 (scraping/parsing).
- `nlp-tools/nlp-tools` (dev-master) — NLP helpers.
- `avisitor/*` (dbbase, dbuser, authz-client, idp-client, monolog-context, env-loader) — private packages from `github.com/avisitor` VCS repos: DB abstraction, authz/IDP integration, contextual logging, `.env` loading. `composer install` needs access to those repos.
- dev: `phpunit/phpunit` ^10.
- PSR-4: `Noiiolelo\` → `lib/`, `Noiiolelo\Providers\` → `providers/`, `HawaiianSearch\` → `providers/{Elasticsearch,OpenSearch}/src/`, `Noiiolelo\Tests\` → `tests/`.
- `config.audit.ignore` waives `PKSA-4dtf-ym9h-t41j` deliberately — leave it.

Node: root `package.json` is `express` + `puppeteer`/`puppeteer-extra` (browser-driven ingestion). `db/ulukau/` has its own npm deps, installed by the composer post-install hook.

Python: `scripts/ingest_embeddings.py` (sentence-transformers; see `docs/PARALLEL_EMBEDDINGS.md`), `providers/Elasticsearch/python/`, and `providers/Elasticsearch/docs/embedding_service_requirements.txt` for the embedding service.

## Commands

```bash
composer install                                    # also npm-installs db/ulukau, symlinks bin/auth
./tests/run-tests.sh                                # full suite + JSON report in tests/reports/
./vendor/bin/phpunit --testsuite Search             # single suite (root phpunit.xml is the config)
php scripts/test_lint.php                           # syntax check
php scripts/check-index-sync.php                    # MySQL vs ES source-ID parity; exit 1 = drift
php scripts/search.php --query=X --provider=OpenSearch --mode=hybrid # command-line search
php scripts/save.php --provider=es --parser=ulukau  # ingest from raw sources on the web
php scripts/createindex.php --recreate --verbose    # full ES/OS index build from data in MySQL or Postgres
bash ops/neo4j.sh start|status|stop
```

- No CI anywhere; the only automation is the cron driver `scripts/updatenoiiolelo.sh` (`PARSERS`/`PROVIDERS`/`GRAMMAR_PROVIDERS` at the top of that file). `scripts/u.sh` is a dev/filtered variant (live calls commented out) — don't confuse the two.
- `composer test` is broken: it points at `tests/phpunit.xml`, which doesn't exist. Use the root `phpunit.xml` via `vendor/bin/phpunit` or `tests/run-tests.sh`.
- Tests hit live services configured in `.env` (`NOIIOLELO_TEST_BASE_URL` required); unreachable providers are skipped. Parity is asserted per provider via DataProviders (`BaseTestCase::$validProviders` = MySQL, Elasticsearch, Postgres) and checked visually via `provider-dashboard.php`.

## Gotchas (details in docs/)

- Opensearch and Elasticsearch keyword fields are mapped directly — `foo.keyword` silently returns nothing; the keyword variant of `text` is `text.raw`. The OpenSearch client strips `.keyword` at request time, the Elasticsearch client does not. Exception: the `processing-logs` index genuinely uses `.keyword`.
- `createindex.php --recreate` wipes entire indices regardless of `--source-id`/`--group-name` — never combine them. Scoped reindex (`--group-name` without `--recreate`) only adds/overwrites; it never purges stale docs.
- Postgres mirroring from MySQL must run for every selected source — never gate on sentence count (the MySQL pass runs first, so existing sources report 0).
- `REFRESH MATERIALIZED VIEW` runs outside any transaction, exactly once per run, from the run driver — never inside `processSource()`/`scanGrammarPatterns()`.
- A Postgres sync failure must not abort the batch — MySQL already succeeded; report and continue.
- OpenSearch auth: `Authorization: apikey <token>`, not `Bearer`; tokens expire (≤90 days, shown once); wildcard index requests must exclude `.*` system indices. See `docs/README.md`.
- Unknown CLI `--provider` values exit 1 loudly (`SaveManagerFactory` throws — no silent fallback); the web-side `getProvider()` instead logs and falls back to MySQL.
- The 384-dim document vector is dead; live doc vectors are 1024-dim (`embedding_1024`); sentence vectors are 384-dim.
- `scripts/ingest_embeddings.py --workers >1` skips frequency calculation — use `--workers 1` when frequencies matter, but the python code is mostly abandoned; check for PHP options
- Grammar scans never fully converge (zero-match sentences get no `sentence_patterns` row, so delta runs re-select them) — expected, not a bug.
- Superseded, removed scripts (see `docs/INGESTION.md`): `repopulate_pg_from_mysql.php`, `pg_indexer.php`, `backfill_pg_doc_vectors_1024.php` → replaced by `pg_import.php`; `nupepa_scraper.php` → replaced by the `nupepa` parser.

*Structure/style/dependency review generated 2026-09-03 from main@204d5a6.*

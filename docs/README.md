# Noiʻiʻōlelo Documentation

Noiʻiʻōlelo is a Hawaiian-language corpus search system. Text is collected from
Hawaiian-language web sources, stored in MySQL (the Laana database) and/or
indexed directly into Elasticsearch/OpenSearch, and searched through a PHP web
frontend and a JSON API.

## Core documentation

| Document | Contents |
|---|---|
| [INGESTION.md](INGESTION.md) | Command-line scripts for ingesting, copying, and maintaining data (web → ES, web → MySQL, MySQL → ES, Postgres backfill, grammar-pattern population, entity/graph tooling, deletion and cleanup) |
| [SEARCHING.md](SEARCHING.md) | How search works: providers, search modes per provider, ordering, the JSON API, and search statistics |
| [WEB_PAGES.md](WEB_PAGES.md) | Every web page / view: the index.php tabs (search, sources, resources, grammar, stats), context/raw pages, dashboards, and the browser-driven ingestion pages |
| [INDEX_STRUCTURES.md](INDEX_STRUCTURES.md) | Storage layouts for each provider: Elasticsearch/OpenSearch indices and mappings, the MySQL Laana schema, and the Postgres schema |
| [GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md) | The grammar pattern system: pattern definitions, scanners, pattern storage per backend, population scripts, and the grammar search view |
| [ELASTICSEARCH_SAVE_MANAGER.md](ELASTICSEARCH_SAVE_MANAGER.md) | Web → Elasticsearch ingestion class (`ElasticsearchSaveManager`, driven by `scripts/save.php --provider=es`) |
| [PARALLEL_EMBEDDINGS.md](PARALLEL_EMBEDDINGS.md) | Parallel embeddings ingestion (`scripts/ingest_embeddings.py --workers N`) |

## Provider directories

- `providers/Elasticsearch/` — PHP client, QueryBuilder, CorpusIndexer, SaveManager.
  - `providers/Elasticsearch/docs/DELETE_AND_REINDEX.md` — detailed `createindex.php` reference (reindex, aliases, content-only ingest).
  - `providers/Elasticsearch/docs/embedding_service_requirements.txt` — Python packages for the embedding service.
- `providers/OpenSearch/` — OpenSearch client (extends the Elasticsearch client; strips `.keyword` suffixes at request time).
- `providers/MySQL/` — MySQL/Laana provider and save manager.
- `providers/Postgres/` — Postgres provider, corpus/sentence/document indexers.
- `providers/Neo4j/` — entity/relationship graph provider (see its README).

## Design plans

`docs/plans/` contains dated design documents for completed work items
(entity auto-classification, data-integrity fixes, alias management,
OpenSearch bootstrap). They are historical records of design decisions, not
operational docs.

## Environment

Provider selection and connection settings live in `.env` (`PROVIDER`,
`ES_HOST`/`ES_PORT`/`ES_API_KEY`, `OS_HOST`/`OS_PORT`/`OS_USER`/`OS_PASS`,
`DB_*` for MySQL, `PG_*` for Postgres, `EMBEDDING_SERVICE_URL`,
`NOIIOLELO_API_BASE_URL`). See `lib/provider.php` for how a provider is
chosen and constructed.

### OpenSearch API keys

OpenSearch has no Elasticsearch-style `_security/api_key` endpoint; it uses
its own API tokens. `bin/os-api-key.sh` mints one and caches it in
`~/.cache/opensearch-api-key-<name>`, so repeated invocations (e.g. from
`~/opensearch/env`) return the same key instead of minting a new one each
run — revoked tokens still count toward the cluster's `max_tokens` cap, so
minting per call would eventually wedge the cluster. Use `--force` to rotate
deliberately. Send the key as `Authorization: apikey <token>` — **not**
`Bearer` — and note tokens expire (90-day maximum) and are shown only once,
so the cached copy is the only recoverable one.

Two restrictions on what a token can access:

- The token authenticates as `token:<name>` with no inherited roles. Its only
  grants are read/monitor plus alias/mapping reads (see the payload in the
  script); widen them there if a consumer needs more.
- Requests that span all indices must exclude the protected system indices
  (`.*`) — use `_cat/indices/*,-.*` and `/*,-.*/_alias` rather than the bare
  forms. Otherwise they fail with 403 `indices:monitor/settings/get`, even
  with full `*` grants.

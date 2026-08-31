# Data Ingestion and Copying — Command-Line Scripts

All scripts run from the project root unless noted. Connection settings come
from `.env`. The embedding service (`EMBEDDING_SERVICE_URL`, default
`http://localhost:5000`) must be running for anything that generates vectors.

```
External web sites (Ulukau, Nupepa, Kauakukalahale, Ke Ao Lama, ...)
        │
        │  scripts/save.php --provider=mysql     (parsers in db/parsehtml.php)
        │  or browser-driven extract*.php pages  (see WEB_PAGES.md)
        ▼
   MySQL Laana DB  ──── scripts/createindex.php ────►  Elasticsearch indices
        │              (reads via the site API,        (documents, sentences,
        │               provider=MySQL)                 content, metadata)
        │
        └── scripts/save.php --provider=es ───────────►  Elasticsearch indices
            (scrapes the web directly, skips MySQL)
```

## Ingesting from the web

### `scripts/save.php` — unified web ingestion driver

Scrapes a source site with a per-site parser and stores the result in either
MySQL or Elasticsearch.

```bash
# Scrape the web straight into Elasticsearch (documents + sentences + embeddings)
php scripts/save.php --provider=es --parser=nupepa
php scripts/save.php --provider=es --parser=ulukau --maxrows=50 --force
php scripts/save.php --provider=es --parser=nupepa --sourceid=45678

# Same parsers, but store into the MySQL Laana DB instead
php scripts/save.php --provider=mysql --parser=nupepa
```

| Option | Meaning |
|---|---|
| `--provider=es\|mysql` | Storage backend (default `mysql`). `es` uses `ElasticsearchSaveManager`, `mysql` uses `MySQLSaveManager` |
| `--parser=KEY` | Site parser key (required): `nupepa`, `ulukau`, `ulukaulocal`, `keaolama`, `kauakukalahale`, `kapaamoolelo`, `baibala`, `ehooululahui`, `kaiwakiloumoku`, `kaulanapilina` — defined in `scripts/parsers.php` |
| `--sourceid=ID` | Process a single source |
| `--minsourceid=/--maxsourceid=` | Source ID range |
| `--maxrows=N` | Max documents (default 20000) |
| `--force` | Re-process already-saved documents |
| `--resplit` | Re-run sentence splitting |
| `--local` | Use the local/queued variant of the parser where supported |
| `--doclist-save[=PATH]` | Save the parser's document list to JSON (default `scripts/doclists/<parser>.json`) |
| `--doclist-file=PATH` | Run against a previously saved document list |
| `--doclist-only` | Save the doc list and exit without fetching documents |
| `--debug`, `--verbose` | Output control |

Engine details: [ELASTICSEARCH_SAVE_MANAGER.md](ELASTICSEARCH_SAVE_MANAGER.md).

## Building the Elasticsearch corpus from MySQL

### `scripts/createindex.php` — corpus indexer (MySQL → Elasticsearch)

Reads the source list, plain text, and raw HTML from the site's HTTP API with
`provider=MySQL` (`NOIIOLELO_API_BASE_URL` in `.env`), then sentence-splits,
computes Hawaiian word ratios, generates embeddings, and bulk-indexes.

```bash
php scripts/createindex.php --recreate --verbose      # full rebuild (drops indices)
php scripts/createindex.php                           # incremental: skips already-indexed sources
php scripts/createindex.php --source-id=52441         # force one source
php scripts/createindex.php --group-name=kauakukalahale
php scripts/createindex.php --import-raw              # hawaiian-content index only
php scripts/createindex.php --aliases-only            # recreate production aliases only
php scripts/createindex.php --provider=opensearch     # target OpenSearch instead of Elasticsearch
```

Other flags: `--dryrun`, `--max-documents=N` / `--limit=N`, `--batch-size=N`,
`--sentence-batch-size=N`, `--checkpoint-interval=N`, `--no-split-indices`,
`--collection-name=NAME`, `--no-aliases`, `--quiet`, `--help`.

Behavior notes:
- Split-indices mode (default) writes `hawaiian_documents_new`,
  `hawaiian_sentences_new`, `hawaiian-content`, and `hawaiian-source-metadata`.
- Raw web content is ingested inline with each document; a re-run backfills
  content for already-indexed sources that are missing it.
- Ctrl+C stops gracefully at the next batch boundary; a second Ctrl+C aborts.
- `--recreate` is global — it drops the indices regardless of `--source-id`
  or `--group-name` (see
  [providers/Elasticsearch/docs/DELETE_AND_REINDEX.md](providers/Elasticsearch/docs/DELETE_AND_REINDEX.md)
  for the full reference, including the groupname-scoped delete caveat).

## Grammar patterns (post-index enrichment)

### `scripts/populate_grammar_patterns.php`

Runs `GrammarScanner` over sentences and stores matched pattern types per
backend: an array field on each Elasticsearch document, or rows in the SQL
`sentence_patterns` table.

```bash
php scripts/populate_grammar_patterns.php --provider=elasticsearch --force
php scripts/populate_grammar_patterns.php --provider=mysql
php scripts/populate_grammar_patterns.php --provider=postgres
php scripts/populate_grammar_patterns.php --provider=elasticsearch --sourceid=52441
```

| Option | Meaning |
|---|---|
| `--provider=` | `elasticsearch`/`es`, `mysql`, or `postgres` (default `mysql`) |
| `--force` | Re-process sentences that already have patterns (ES: reprocesses all; SQL: delta-scan everything) |
| `--sourceid=ID` | Restrict to one source |
| `--batch=N` | SQL delta-scan batch size (default 5000) |

Without `--force`, the SQL path scans only sentences without patterns. After a
SQL run the `grammar_pattern_counts` summary table is refreshed. See
[GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md).

## Embeddings and metrics backfill

### `scripts/ingest_embeddings.py`

Standalone embeddings/metrics ingestion for SQL-backed stores (see
[PARALLEL_EMBEDDINGS.md](PARALLEL_EMBEDDINGS.md)):

```bash
python3 scripts/ingest_embeddings.py sentences 100 --workers 4
python3 scripts/ingest_embeddings.py documents 100 --workers 4
```

### `scripts/pg_indexer.php`

Postgres backfill for sentences/documents missing embeddings or metrics
(see [PG_INDEXER_README.md](PG_INDEXER_README.md)):

```bash
php scripts/pg_indexer.php --write            # default is dryrun without --write
php scripts/pg_indexer.php --write --sentences
php scripts/pg_indexer.php --write --documents --force
```

### `scripts/rebuild_source_metadata.php`

Rebuilds the `hawaiian-source-metadata` index from the documents index —
recovery tool for lost/corrupted source metadata.

## Copying and migrating data

| Script | Purpose |
|---|---|
| `scripts/migrate_es_to_os.php` | One-shot migration of indices from Elasticsearch to OpenSearch |
| `scripts/repopulate_pg_from_mysql.php` | Copy corpus data from MySQL into Postgres |
| `scripts/pg_indexer.php` | Backfill Postgres embeddings/metrics (above) |
| `scripts/backfill_1024.php`, `scripts/backfill_1024_vectors.php` (`providers/Elasticsearch/scripts/`) | Backfill 1024-dim document vectors after a model switch |

## Entities and graphs (Neo4j)

| Script | Purpose |
|---|---|
| `scripts/backfill_entities.php` | Extract entities/relationships from existing ES documents into Neo4j |
| `scripts/process_new_documents.php <docId>` | Process one newly ingested document into the graph |
| `scripts/auto_classify_entities.php --file=<overrides.json> [--dry-run]` | Classify name-list entities |
| `scripts/rebuild_graph_from_local.php` | Rebuild the graph from local data |
| `scripts/neo4j_backfill_demo.php` / `neo4j_backfill_working.php` | Small demos |

See `providers/Neo4j/README.md`.

## Deletion and cleanup

| Script | Purpose |
|---|---|
| `scripts/deleteSource.php <groupname>` | Delete a group's rows from MySQL `sentences`/`contents`/`sources` |
| `scripts/savedocument.php` | Single-document save/delete tool (`--sourceid=`, `--delete`, `--force`, …) — also the place where groupname-scoped Elasticsearch deletes live |
| `scripts/cleanup.php` | Remove MySQL `sentences`/`contents` rows whose sourceid has no `sources` row |
| `scripts/empty.php` | Report sources missing `contents.text` / `contents.html` |
| `scripts/deleteSource.php` + `ElasticsearchClient::deleteByGroupname()` | Elasticsearch group deletion (used via `savedocument.php`) |

## Related web endpoints (not CLI)

The browser-driven ingestion pages (`extractUlukau.php`, `extractBase.php`,
`addsentences.php`, `ulukau.php`, …) scrape into MySQL interactively — see
[WEB_PAGES.md](WEB_PAGES.md).

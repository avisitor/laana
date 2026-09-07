# Data Ingestion and Copying — Command-Line Scripts

All scripts run from the project root unless noted. Connection settings come
from `.env`. The embedding service (`EMBEDDING_SERVICE_URL`, default
`http://localhost:5000`) must be running for anything that generates vectors.

```
External web sites (Ulukau, Nupepa, Kauakukalahale, Ke Ao Lama, ...)
        │
        ├── scripts/save.php --provider=es ──────────►  Elasticsearch indices
        │   (scrapes the web directly, skips MySQL;    (documents, sentences,
        │    parsers in db/parsehtml.php)               content, metadata)
        │
        ├── scripts/save.php --provider=opensearch ──►  OpenSearch indices
        │   (same scrape flow with an OpenSearch        (documents, sentences,
        │    client; grammar patterns computed          content, metadata —
        │    at index time)                             same layout as ES)
        │
        ├── scripts/save.php --provider=postgres ────►  Postgres laana schema
        │   (scrapes via the MySQL flow — MySQL stays   (data + vectors +
        │    the catalog of record for ID parity —      metrics + grammar
        │    then mirrors each source into Postgres)    patterns)
        │                                                 ▲
        │  scripts/save.php --provider=mysql              │ --source=postgres
        │  or browser-driven extract*.php pages           │ (stored vectors,
        │  (see WEB_PAGES.md)                             │  no live embedding)
        ▼                                                 │
   MySQL Laana DB  ──── scripts/createindex.php ──────────┘
        │             (reads via the site API with
        │              provider=MySQL)
        │
        └── scripts/pg_import.php ──►  Postgres laana schema
            (copies sources/contents/sentences from MySQL, then generates
             sentence embeddings + metrics, document metrics, and
             1024-dim document vectors — everything a --source=postgres
             run needs)
```

## Ingesting from the web

### `scripts/save.php` — unified web ingestion driver

Scrapes a source site with a per-site parser and stores the result in MySQL,
Elasticsearch, OpenSearch, or Postgres.

```bash
# Scrape the web straight into Elasticsearch (documents + sentences + embeddings)
php scripts/save.php --provider=es --parser=nupepa
php scripts/save.php --provider=es --parser=ulukau --maxrows=50 --force
php scripts/save.php --provider=es --parser=nupepa --sourceid=45678

# Single source / range
php scripts/save.php --provider=es --parser=nupepa --sourceid=45678

# Without --parser, the parser key is resolved from the source's groupname
php scripts/save.php --provider=es --sourceid=45678

# Re-catalog just one source's metadata from its live page (no content fetch)
php scripts/save.php --provider=mysql --remote=45678

# Wipe a parser group's existing data, then re-save it (5-second cancel window)
php scripts/save.php --provider=es --parser=nupepa --delete-existing

# Preview what a run would do without writing anything
php scripts/save.php --provider=es --parser=nupepa --dryrun

# Same parsers, but store into the MySQL Laana DB instead
php scripts/save.php --provider=mysql --parser=nupepa

# Same scrape flow, OpenSearch instead of Elasticsearch
php scripts/save.php --provider=os --parser=nupepa

# Mirror a scrape into Postgres (data + vectors + metrics + grammar patterns)
php scripts/save.php --provider=postgres --parser=keaolama
```

| Option | Meaning |
|---|---|
| `--provider=mysql\|postgres\|es\|elasticsearch\|os\|opensearch` | Storage backend (default `mysql`). `mysql` → `MySQLSaveManager`, `es`/`elasticsearch` → `ElasticsearchSaveManager`, `os`/`opensearch` → `OpenSearchSaveManager` (same flow, OpenSearch client), `postgres` → `PostgresSaveManager`. Any other value fails loudly on STDERR with exit 1 — there is no silent fallback to MySQL. `--provider=postgres` scrapes through the MySQL flow (MySQL stays the catalog of record, keeping IDs in parity with `pg_import.php`), then mirrors every selected source into Postgres (data, 384/1024-dim vectors, metrics, grammar patterns) in one transaction per source and refreshes `grammar_pattern_counts` once per run; its Summary JSON reports `pg_mirror_failures` and `patterns_saved` |
| `--parser=KEY` | Site parser key (optional when `--sourceid` resolves it from the source's registered groupname): `nupepa`, `ulukau`, `ulukaulocal`, `keaolama`, `kauakukalahale`, `kapaamoolelo`, `baibala`, `ehooululahui`, `kaiwakiloumoku`, `kaulanapilina` — defined in `scripts/parsers.php` |
| `--sourceid=ID` | Process a single source; with no `--parser`, the parser key is resolved from the source's groupname in the selected backend |
| `--minsourceid=/--maxsourceid=` | Source ID range |
| `--remote=ID` | Re-catalog just the metadata for this sourceid from its live page (no content fetch) |
| `--delete-existing` | Delete all existing documents for the parser's groupname before re-saving (5-second cancel window; press Ctrl+C to abort). Works per provider: MySQL deletes Laana rows, Elasticsearch/OpenSearch delete index entries, Postgres deletes through the MySQL catalog of record |
| `--dryrun` | Print what the run would do — provider, parser, document-list preview (first 10 entries), delete/report decisions — without writing anything. Script-level only: it does not simulate per-document new/updated/skip decisions (that logic lives inside the managers and writes as a side effect of evaluating it). The doc-list preview reads the parser's live listing page; combine with `--doclist-file` for a fully offline preview |
| `--maxrows=N` | Max documents (default 20000) |
| `--force` | Re-process already-saved documents |
| `--resplit` | Re-run sentence splitting |
| `--local` | Use the local/queued variant of the parser where supported |
| `--doclist-save[=PATH]` | Save the parser's document list to JSON (default `scripts/doclists/<parser>.json`) |
| `--doclist-file=PATH` | Run against a previously saved document list |
| `--doclist-only` | Save the doc list and exit without fetching documents (requires `--doclist-save`) |
| `--debug`, `--verbose` | Output control |
| `--help` | Show the full usage block and exit 0 |

With no `--parser`, `--sourceid`, source ID range, or `--remote`, the script prints usage and exits 1 instead of starting a full-corpus run.

Engine details: [ELASTICSEARCH_SAVE_MANAGER.md](ELASTICSEARCH_SAVE_MANAGER.md).

## Building the Elasticsearch corpus from MySQL

### `scripts/createindex.php` — corpus indexer (MySQL → Elasticsearch)

Reads the source list, plain text, and raw HTML from the site's HTTP API with
`provider=MySQL` (`NOIIOLELO_API_BASE_URL` in `.env`), then sentence-splits,
computes Hawaiian word ratios, generates embeddings, and bulk-indexes.

```bash
php scripts/createindex.php --recreate --verbose      # full rebuild into *_staging indices, atomic switch on completion
php scripts/createindex.php --resume --verbose        # continue an interrupted --recreate (reads its persisted state)
php scripts/createindex.php                           # incremental: skips already-indexed sources
php scripts/createindex.php --source-id=52441         # force one source
php scripts/createindex.php --group-name=kauakukalahale
php scripts/createindex.php --import-raw              # hawaiian-content index only
php scripts/createindex.php --aliases-only            # recreate production aliases only
php scripts/createindex.php --provider=opensearch     # target OpenSearch instead of Elasticsearch
php scripts/createindex.php --source=postgres         # read text/vectors from Postgres (see below)
```

Other flags: `--dryrun`, `--max-documents=N` / `--limit=N`, `--unlimited`,
`--batch-size=N`, `--sentence-batch-size=N`, `--checkpoint-interval=N`,
`--no-split-indices`, `--collection-name=NAME`, `--no-aliases`, `--quiet`,
`--help`.

### Source selection: `--source=api` (default) vs `--source=postgres`

`--source` chooses where documents, sentences, and vectors come from; it is
independent of `--provider` (both Elasticsearch and OpenSearch work with
either source):

- **`api`** (default) — reads the source list, plain text, and raw HTML from
  the site's HTTP API (`NOIIOLELO_API_BASE_URL` with `provider=MySQL`), then
  sentence-splits, computes word ratios, and embeds live via the embedding
  service.
- **`postgres`** — reads text, sentences, document vectors, and metrics
  directly from the Postgres `laana` schema (`PG_*` in `.env`), using the
  vectors already stored there (`sentences.embedding` 384-dim,
  `contents.embedding_1024` 1024-dim). No live
  embedding is done unless a document's 1024-dim vector is missing, in which
  case it is computed on the fly as a fallback. The legacy 384-dim document
  vector (`contents.embedding`) is no longer populated or consulted.

The indexer does not hard-code these behaviors per source type: each source
provider declares its capabilities (`sentenceVectors`, `documentVector384`,
`documentVector1024`, `rawHtml` — see
`providers/Elasticsearch/src/SourceCapabilities.php`), and the indexer queries
the assigned provider and falls back per capability. Adding a new source
backend means implementing `SourceProviderInterface`, not touching the indexer.

Prerequisites for `--source=postgres`: the Postgres `laana` schema must be
fully populated — corpus data plus sentence embeddings, metrics, and
1024-dim document vectors. `scripts/pg_import.php` (below) produces all of
it in one pass; run it to completion before switching the indexer over.
Otherwise missing 1024-dim vectors are embedded live one document at a time
(slower).

Behavior notes:
- All runtime reads and writes go through the production aliases
  (`ES_DOCUMENTS_ALIAS`/`ES_SENTENCES_ALIAS`/`ES_CONTENT_ALIAS`/
  `ES_SOURCE_METADATA_ALIAS`/`ES_METADATA_ALIAS`, defaulting to
  `hawaiian_documents`, `hawaiian_sentences`, `hawaiian_content`,
  `hawaiian_source_metadata`, `hawaiian_metadata`). Physical index names only
  matter for index creation/deletion.
- Split-indices mode (default) writes the documents, sentences, content, and
  source-metadata indices (via those aliases).
- Raw web content is ingested inline with each document; a re-run backfills
  content for already-indexed sources that are missing it.
- Ctrl+C stops gracefully at the next batch boundary; a second Ctrl+C aborts.
- `--recreate` is full-corpus only — the script aborts immediately if it is
  combined with `--group-name`, `--source-id` or `--max-documents` (those are
  for incremental ingestion). The rebuild goes into temporary
  `*_staging` indices while the current indices keep serving search; when the
  run completes, the production aliases are atomically switched to the staging
  indices and the old physical indices are deleted. An interrupted run leaves
  production untouched; `--recreate` persists its provider/source/collection
  settings to `logs/createindex-staging-state.json` when it starts, so
  `php scripts/createindex.php --resume` continues the interrupted run — it
  adds the sources still missing from the staging indices (already-staged
  sources are skipped), then switches the aliases. `--resume` takes no options
  except `--verbose`, and exits when there is no staging state to resume. The
  state file is removed when `--recreate` or `--resume` completes the switch.
  Use `--no-aliases` to build staging without ever switching (the state file
  is kept, so `--resume` can complete the switch later). See
  [providers/Elasticsearch/docs/DELETE_AND_REINDEX.md](providers/Elasticsearch/docs/DELETE_AND_REINDEX.md)
  for the full reference, including the groupname-scoped delete caveat.

## Postgres import and backfill (MySQL → Postgres)

### `scripts/pg_import.php` — unified Postgres importer

One script that fills the Postgres `laana` schema from MySQL and generates
every derived vector and metric. It replaces the former three-step pipeline
(`repopulate_pg_from_mysql.php` → `pg_indexer.php` →
`backfill_pg_doc_vectors_1024.php`, all removed). Per source it:

1. Copies the source row, its `contents` row, and its `sentences` rows from
   MySQL (upserts; sentence IDs carried over unchanged).
2. Embeds sentences with the small embedding model (**384-dim**,
   `passage: ` prefix) via `lib/EmbeddingClient.php` and writes
   `sentences.embedding vector(384)` in one batched statement per source:
   all vectors for the source are staged into a per-transaction temp table
   (chunked multi-row INSERTs, 250 rows per statement) and applied with a
   single `UPDATE ... FROM staging`.
3. Computes sentence metrics (`hawaiian_word_ratio`, `word_count`, `length`,
   `entity_count`, `frequency`) with `lib/MetricsComputer.php`
   (`hawaiian_words.txt`) and upserts `sentence_metrics` in batched
   multi-row statements (250 rows per statement).
4. Computes document metrics and upserts `document_metrics`.
5. Embeds the document text with the large model (**1024-dim**,
   `intfloat/multilingual-e5-large-instruct`, `passage: ` prefix) and writes
   `contents.embedding_1024 vector(1024)`.
6. Delta-scans grammar patterns for the source's sentences and upserts
   `sentence_patterns` (same transaction).

The legacy 384-dim document vector (`contents.embedding`) is not populated.

Each source is processed as ONE unit inside a single Postgres transaction
(shared with the embedding writes via `PostgresLaana` from
`db/PostgresFuncs.php`): migrate → sentence vectors/metrics → document
metric/vector → grammar scan → commit. If interrupted, every source already
committed is complete (data + vectors + metrics + patterns); the next run
resumes with the rest. Per-source errors are reported on STDERR, the run
continues, and the exit code is 1 if anything failed.

Grammar patterns are part of that per-source unit (no separate populate
pass needed). After the loop, any non-dryrun run refreshes
`grammar_pattern_counts` unconditionally with
`REFRESH MATERIALIZED VIEW CONCURRENTLY` — exactly once per run, outside
all transactions; a failed refresh warns on STDERR but does not fail the
run.

`--force` runs also manage the **derivative search indexes** on
`laana.sentences`, managed by
`providers/Postgres/SentenceSearchIndexManager.php`. Drop targets are
discovered live — every index on the table except constraint-backed ones
(the primary key) and the keep-list (`idx_sentences_source`, used by the
import's per-source selects) — so indexes added to the schema later are
handled without code changes. Before dropping, each index's executable
definition (`pg_get_indexdef`) is captured to
`logs/pg-import-index-state.json`; recreation restores exactly what was
captured, so index changes survive the cycle unchanged. The hard-coded
fallback DDL in the class (used only when the state file is lost) is pinned
to the live definitions by a drift-alarm test
(`tests/Source/SentenceSearchIndexManagerTest.php`). The import never reads
these indexes, but every sentence rewrite pays for them (each non-HOT
UPDATE inserts into the ivfflat index — scanning every IVF centroid — and
both GIN indexes), so they are dropped right after the truncate and
recreated at the end of the run. Incremental runs do the same when the
pending data migration is large (≥ 100,000 sentences by the per-source
signature diff): a bulk backfill is a bulk load. Interruption safety:
SIGINT/SIGTERM recreate missing indexes before exiting, a shutdown hook
covers fatal errors, and a non-force run recreates any still missing at
startup (repairing an uncatchable death such as SIGKILL or power loss). A
recreate failure is counted as a run error.

```bash
php scripts/pg_import.php                    # incremental backfill (writes by default)
php scripts/pg_import.php --status           # report what a full run would do, then exit
php scripts/pg_import.php --dryrun           # everything except Postgres writes (rolled back)
php scripts/pg_import.php --source-id=52441  # one source
php scripts/pg_import.php --limit=50         # at most 50 sources (lowest sourceIDs first)
php scripts/pg_import.php --sentences        # only sentence embeddings/metrics
php scripts/pg_import.php --documents        # only document metrics/vectors
php scripts/pg_import.php --force            # RESET corpus tables + full rebuild
php scripts/pg_import.php --recreate         # alias for --force
```

| Option | Meaning |
|---|---|
| *(no options)* | Backfill only what is missing: missing sources/sentences/contents are copied from MySQL; only rows lacking embeddings/metrics/doc vectors are processed |
| `--status` | Print what a full run would do (rows to add, rows missing vectors/metrics) and exit before anything is touched. Ignores every other option and does not need the embedding service |
| `--force` / `--recreate` | First truncate the corpus tables (`sources`, `contents`, `sentences`, `sentence_metrics`, `document_metrics`, `sentence_patterns` — `searchstats`/`processing_log` are preserved) and refresh `grammar_pattern_counts`, then rebuild everything from scratch. Also drops the derivative search indexes for the load and recreates them at the end. Guarded so `--dryrun` never truncates |
| `--dryrun` | Do everything except write to Postgres (all transactions rolled back). **The default without `--dryrun` is WRITE** |
| `--sentences` | Only sentence embeddings/metrics (parents still migrated) |
| `--documents` | Only document metrics/vectors (parents still migrated) |
| `--limit=N` | Process at most N sources, lowest sourceIDs first |
| `--source-id=ID` | Process only this source |
| `--verbose` / `--quiet` | Per-source detail / suppress non-error output |

`--sentences` and `--documents` are mutually exclusive; omitting both does
both. Source/content rows are always migrated (they are the parents) — the
two flags only scope which child vectors/metrics are (re)generated.

Example `--status` output:

```
Status: what a full run would do
===================================================
Documents to add:                                 0
Sentences to add:                                 0
Existing sentences to get vectors:               400
  missing vector / missing metrics:               400 / 400
Existing documents to get vectors:                15
Existing documents to get metrics:                15

(Status only — nothing was written.)
```

A write run prints per-source lines (`[n/total] sourceID=… group=…`) and a
summary (`Sources processed`, `Sentences migrated`, `Sentence vectors`,
`Sentence metrics`, `Patterns scanned`, `Document metrics`,
`Document vectors`, `Errors`).

Connection settings: `DB_*` (MySQL source), `PG_*` (Postgres target),
`EMBEDDING_SERVICE_URL` — all from `.env`. Schema details: see
[INDEX_STRUCTURES.md](INDEX_STRUCTURES.md).

### Legacy: `scripts/pg_index_sentences.php`

The older sentence-only Postgres indexer, built on the
`providers/Postgres/` classes (`PostgresClient`,
`PostgresSentenceIterator`, `PostgresSentenceIndexer` — the same classes the
Postgres search provider uses). Dry-run by default (`--write` to apply),
with `--limit`, `--verbose`/`--quiet`, `--ids-out=FILE` (processed-ID
snapshot) and `--out-json=FILE` (IDs + timing counts). Superseded by
`scripts/pg_import.php`, which also handles documents and data migration;
kept for one-off sentence re-embedding runs.

### `scripts/ingest_embeddings.py`

Standalone embeddings/metrics ingestion for SQL-backed stores (see
[PARALLEL_EMBEDDINGS.md](PARALLEL_EMBEDDINGS.md)):

```bash
python3 scripts/ingest_embeddings.py sentences 100 --workers 4
python3 scripts/ingest_embeddings.py documents 100 --workers 4
```

## Grammar patterns (population and enrichment)

**Automation.** Pattern population is automatic on all four backends — the
populate script below is the manual delta backstop:

- **MySQL** — scanned at save time: `MySQLSaveManager` calls
  `GrammarScanner::updateSourcePatterns()` for every ingested source (the
  browser-driven `addsentences.php` pages do the same).
- **Elasticsearch / OpenSearch** — computed at index time by the shared
  indexing client (`grammar_patterns` field on each sentence document;
  counts are a live terms aggregation, nothing to refresh).
- **Postgres** — scanned at save time via `PostgresSaveManager` and the
  `pg_import.php` pipeline, inside each source's transaction;
  `grammar_pattern_counts` is refreshed once per run.

`scripts/populate_grammar_patterns.php` remains the delta backstop for the
two SQL providers — the step `scripts/updatenoiiolelo.sh` runs after its
save loop.

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

### `scripts/findpatterns.py`

Standalone Python scanner for the SQL backends: loads patterns from
`lib/grammar_patterns.json`, builds a grammar-marker "fingerprint" per
sentence, and stores matched patterns in the `sentence_patterns` table.

```bash
python3 scripts/findpatterns.py --provider=Postgres --force
python3 scripts/findpatterns.py --provider=MySQL
```

## Copying and migrating data

| Script | Purpose |
|---|---|
| `scripts/pg_import.php` | Copy corpus from MySQL into Postgres and backfill all vectors/metrics (above) |
| `scripts/migrate_es_to_os.php` | One-shot migration of indices from Elasticsearch to OpenSearch |
| `providers/Elasticsearch/scripts/backfill_1024.php`, `backfill_1024_vectors.php` | Backfill 1024-dim document vectors after a model switch |

## Scheduled and ops helpers

| Script | Purpose |
|---|---|
| `scripts/updatenoiiolelo.sh` | Scheduled ingestion driver: saves each periodic parser's document list (`--doclist-only`/`--doclist-file`), runs `save.php` for each parser across the configured providers, populates grammar patterns for the SQL providers, then emails a summary log. Writes `/tmp/noiiolelo.log` |
| `scripts/u.sh` | Ad-hoc variant of the above with the run steps commented out — filters and prints a summary from an existing `/tmp/noiiolelo.log` |
| `scripts/getwordcounts.sh` | Runs `wordcounts.sql` against MySQL and writes the single-row result to `data/wordcounts.json` |
| `scripts/install_opensearch.sh` | Install and bootstrap OpenSearch on AlmaLinux 9 (single-node configuration) |
| `scripts/php-report-errors.sh` | Mails the contents of `php_errorlog` |

## Parser and document utilities

| Script | Purpose |
|---|---|
| `scripts/getList.php --parser=KEY [--debug]` | Dump a parser's document list (debug tool for parser development) |
| `scripts/updatedocument.php <sourceID>` | Re-fetch and update one document through `saveFuncs.php` |
| `scripts/cleanulukau.php <file>` | Clean scraped Ulukau text lines with the same filtering logic as the site's render.js |
| `scripts/nupepa_scraper.php` | Standalone Nupepa.org scraper prototype (Guzzle + DomCrawler) — superseded by the `nupepa` parser in `scripts/parsers.php` |

## Entities and graphs (Neo4j)

| Script | Purpose |
|---|---|
| `scripts/backfill_entities.php` | Extract entities/relationships from existing ES documents into Neo4j |
| `scripts/process_new_documents.php <docId>` | Process one newly ingested document into the graph |
| `scripts/auto_classify_entities.php --file=<overrides.json> [--dry-run]` | Classify name-list entities |
| `scripts/rebuild_graph_from_local.php` | Rebuild the graph from local data |
| `scripts/neo4j_backfill_demo.php` / `neo4j_backfill_working.php` / `entity_extraction_demo.php` | Small demos |

See `providers/Neo4j/README.md`.

## Deletion and cleanup

| Script | Purpose |
|---|---|
| `scripts/deleteSource.php <groupname>` | Delete a group's rows from MySQL `sentences`/`contents`/`sources` |
| `scripts/save.php --delete-existing --parser=KEY` | Delete a group's data from the selected backend (5-second cancel window), then re-save it: MySQL deletes Laana rows; Elasticsearch/OpenSearch delete index entries via `ElasticsearchClient::deleteByGroupname()`; Postgres deletes through the MySQL catalog of record. Replaces the retired `scripts/savedocument.php` |
| `scripts/cleanup.php` | Remove MySQL `sentences`/`contents` rows whose sourceid has no `sources` row |
| `scripts/empty.php` | Report `sources` rows with no matching `sentences.hawaiiantext` / `contents.text` data |

## Related web endpoints (not CLI)

The browser-driven ingestion pages (`extractUlukau.php`, `extractBase.php`,
`addsentences.php`, `ulukau.php`, …) scrape into MySQL interactively — see
[WEB_PAGES.md](WEB_PAGES.md). `scripts/search.php` is the JSON API endpoint,
not an ingestion tool — see [SEARCHING.md](SEARCHING.md).

The remaining `test*.php` / `test_*.php` files are developer smoke tests,
not operational tooling.

# Index and Storage Structures per Provider

Field names below are verified against the live mappings and the code. The
critical convention: **keyword fields are mapped directly (no `.keyword`
subfields)**, and `text`'s keyword variant is **`text.raw`**. Code that
queries `foo.keyword` against these indices fails silently (empty results) —
the OpenSearch client strips `.keyword` suffixes at request time, the
Elasticsearch client does not.

## Elasticsearch / OpenSearch

Both engines have identical structures (OpenSearch config in
`providers/OpenSearch/config/`, Elasticsearch in
`providers/Elasticsearch/config/`). The OpenSearch client
(`OpenSearchClient extends ElasticsearchClient`) wraps requests with
`rewriteQuery()`, which maps `text.keyword` → `text.raw` and drops other
`.keyword` suffixes.

Base name comes from `--collection-name` / `indexName` (default `hawaiian`).

### `hawaiian_documents_new` (documents; `config/documents_mapping.json`)

| Field | Type | Notes |
|---|---|---|
| `doc_id`, `sourceid`, `groupname`, `sourcename`, `authors`, `link` | keyword | Direct keyword fields |
| `text` | text | subfields: `folded` (folding analyzer), `raw` (keyword, `ignore_above: 50000`), and on documents also `keyword` |
| `text_chunks` | nested | Long-document regex support: `chunk_text` (+ `.raw`), chunk offsets |
| `text_vector` | dense_vector | 384-dim (multilingual-e5-small) |
| `text_vector_1024` | dense_vector | 1024-dim (multilingual-e5-large-instruct) |
| `date` | date | format `yyyy-MM-dd` |
| `length`, `word_count`, `sentence_count`, `entity_count` | integer | Aggregated from sentence metadata |
| `hawaiian_word_ratio`, `boilerplate_score`, `quality_score` | float | |
| `grammar_patterns` | keyword | Array of pattern types (see [GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md)) |
| `title`, `created_at` | text / date | |

### `hawaiian_sentences_new` (sentences; `config/sentences_mapping.json`)

One doc per sentence: `doc_id`, `sourceid`, `position`, `text` (+ `folded`,
`raw`), `vector` (dense_vector 384), `groupname`, `sourcename`, `authors`,
`date` (date), `link`, `length`/`word_count` (integer),
`hawaiian_word_ratio`/`entity_count`/`frequency`/`boilerplate_score`/
`quality_score` (float/integer), `grammar_patterns` (keyword array),
`sentence_id`/`sentence_hash` (keyword), `created_at`, `metadata` (object).

### `hawaiian-content` (raw web content; `getContentName()`)

Raw original HTML per sourceid, ingested inline during indexing
(`ingestRawContentForSources`) or in bulk via `createindex.php --import-raw`.
Serves `rawpage.php`.

### `hawaiian-source-metadata` (checkpoint/metadata; `metadata_mapping.json`)

One record per source: processed/discarded/english-only bookkeeping used to
resume interrupted runs, plus source display metadata (`groupname`,
`sourcename`, `authors`, `date`, `sentencecount`, `quality`, `link`, …).
Rebuildable from the documents index via `scripts/rebuild_source_metadata.php`.

### `processing-logs` (operation log)

Dynamically mapped: `status`, `operation_type`, `groupname`, `source_id`,
`error_message`, `parser_key` are text **with** `.keyword` subfields (this is
the one index where `.keyword` names are correct). Written by
`providers/Elasticsearch/ElasticsearchProcessingLogger.php` via
`lib/ProcessingLogger.php` (start/finish processing-log operations around
ingestion runs).

### `hawaiian-searchstats`

Search statistics: `searchterm` (text+keyword), `pattern`, `results`, `sort`,
`elapsed`, `created`.

### Aliases

`ElasticsearchClient::createProductionAliases()` (exposed by
`scripts/createindex.php --aliases-only`) maintains stable aliases over the
`_<suffix>` indices so the app can use fixed names across reindex runs.

## MySQL (Laana DB; `db/funcs.php`, `createtables.sql`)

Connection: `DB_*` in `.env` (default db `laana`). Provider class
`providers/MySQL/MySQLProvider.php`; data access in `db/funcs.php` (`Laana`,
`DB`).

| Table | Purpose |
|---|---|
| `sources` | `sourceid`, `sourcename`, `groupname`, `date`, `authors`, `title`, `link` |
| `sentences` | `sentenceid`, `sourceid`, `hawaiianText`, `simplified` |
| `contents` | `sourceid`, `html` (raw page), `text` (plain text) |
| `sentence_patterns` | Grammar-pattern matches: one row per (sentence, pattern_type) |
| `grammar_pattern_counts` | Summary counts per pattern (refreshed via `refresh_grammar_counts()`); powers the grammar dropdown for MySQL |
| `searchstats` | Recorded searches (term, pattern, count, order, elapsed) |
| `stats` | Legacy aggregate stats |
| `processing_log` | Fetch/parse/ingest operation log (MySQL counterpart of the ES processing-logs index) |

Grammar-pattern queries join `sentence_patterns` with `sentences`/`sources`
when date filtering, else read `grammar_pattern_counts`.

## Postgres (schema `laana`; `pgschema.sql`)

Connection: `PG_*` in `.env`. Provider `providers/Postgres/PostgresProvider.php`
with `PostgresClient`, `PostgresCorpusIndexer`, `PostgresSentenceIndexer`,
`PostgresDocumentIterator`/`PostgresSentenceIterator`.

Tables: `sources`, `contents`, `sentences`, `documents`,
`sentence_metrics`, `document_metrics`, `sentence_patterns`,
`processing_log`, `searchstats`. Sentence/document embeddings and metrics
live in the `*_metrics` tables (pgvector-backed); `scripts/pg_import.php`
copies the corpus over from MySQL and backfills embeddings, metrics, and
`contents.embedding_1024` (and `scripts/ingest_embeddings.py` backfills
embeddings/metrics in parallel).

## Neo4j

Entities and relationships extracted from documents (see
`providers/Neo4j/README.md`); populated by `scripts/backfill_entities.php` and
`scripts/process_new_documents.php`.

## Field-name gotcha (history)

Older deployments dynamically mapped keyword fields as text+`.keyword`, and
code still referencing `foo.keyword` only works on such indices (or on
OpenSearch, where the wrapper rewrites it). All current mappings declare
keyword fields directly; the grammar search, group counts, sorts, and
deletes were fixed in 2026-08 to use the plain names / `text.raw`. When
adding queries, target the field names above.

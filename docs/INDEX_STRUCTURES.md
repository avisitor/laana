# Index and Storage Structures per Provider

Field names below are verified against the live mappings and the code. The
critical convention: **keyword fields are mapped directly (no `.keyword`
subfields)**, and `text`'s keyword variant is **`text.raw`**. Code that
queries `foo.keyword` against these indices fails silently (empty results) —
the OpenSearch client strips `.keyword` suffixes at request time, the
Elasticsearch client does not.

## Elasticsearch / OpenSearch

Both engines have the same logical structures; the configs live in
`providers/Elasticsearch/config/` and `providers/OpenSearch/config/`. The
OpenSearch client (`OpenSearchClient extends ElasticsearchClient`) wraps
requests with `rewriteQuery()`, which maps `text.keyword` → `text.raw` and
drops other `.keyword` suffixes.

Base name comes from `--collection-name` / `indexName` (default `hawaiian`).
Physical names below assume that default; the searchable names are the stable
aliases `hawaiian_documents`, `hawaiian_sentences`, `hawaiian_content`,
`hawaiian_source_metadata` and `hawaiian_metadata` (see “Aliases” below).

On OpenSearch the vector fields are `knn_vector` (Lucene HNSW, cosine) with
`mode: on_disk` and `compression_level: 32x`: the HNSW graph searches 1-bit
quantized vectors (the counterpart of Elasticsearch's `bbq_hnsw`), and the
full-precision vectors are kept on disk to rescore the top candidates.
OpenSearch mappings exclude the vectors from `_source`, so they cannot be
copied with `_reindex`. A mapping change needs a rebuild from stored vectors
instead (`createindex.php --recreate --provider=opensearch --source=postgres`).

### `hawaiian_documents_new` (documents; `config/documents_mapping.json`)

| Field | Type | Notes |
|---|---|---|
| `doc_id`, `sourceid`, `groupname`, `sourcename`, `authors`, `link` | keyword | Direct keyword fields |
| `text` | text | subfields: `keyword` (`ignore_above: 256`), `raw` (keyword, `ignore_above: 50000`), `folded` (folding analyzer) |
| `text_chunks` | nested | Long-document regex support: `chunk_index`, `chunk_text` (+ `raw`, `folded`), `chunk_start`, `chunk_length` |
| `text_vector` | dense_vector | Legacy 384-dim slot (no `dims` declared in the ES mapping; 384 in the OpenSearch mapping). Not written for new documents; kept only when the source already carries it |
| `text_vector_1024` | dense_vector | Live document vector: 1024-dim (`intfloat/multilingual-e5-large-instruct`); `dims: 1024` |
| `date` | date | format `yyyy-MM-dd` in the mappings that declare one |
| `word_count`, `sentence_count`, `entity_count` | integer | Aggregated from sentence metadata at index time |
| `hawaiian_word_ratio`, `boilerplate_score` | float | Document score / max sentence score |
| `title` | text | |
| `created_at` | date | Declared in the mapping; not written by the split-index document builder |

The document `_source` built by `CorpusIndexer` contains `doc_id`, `sourceid`,
`groupname`, `sourcename`, `title`, `text`, `text_chunks`,
`text_vector_1024`, `date`, `authors`, `link`, `hawaiian_word_ratio`,
`sentence_count`, `word_count`, `entity_count` and `boilerplate_score` (plus
legacy `text_vector` when the source already has it). It has **no** `length`,
`quality_score` or `grammar_patterns` — those are sentence-level.
`quality_score` in particular is duplicated from the sentence's
`hawaiian_word_ratio`; it does not exist on the document record.

### `hawaiian_sentences_new` (sentences; `config/sentences_mapping.json`)

One doc per sentence, `_id` = `<sourceid>_<position>`. Fields written to
`_source` by `CorpusIndexer::buildSplitSentenceSource()`:

`doc_id`, `sourceid`, `position`, `chunk_id`, `text` (+ `raw`, `folded`),
`vector` (dense_vector 384 / knn_vector 384), `groupname`, `sourcename`,
`authors`, `date` (date), `title`, `frequency`, `length`, `word_count`,
`entity_count`, `hawaiian_word_ratio`, `quality_score`, `boilerplate_score`
(float/integer), `grammar_patterns` (keyword array).

The mapping also declares `sentence_id` and `sentence_hash` (keyword),
`created_at` (date) and `metadata` (object); the split-index builder does not
populate them. Grammar patterns are computed per sentence at index time with
the client's `calculateGrammarPatterns()` (see
[GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md)).

### `hawaiian-content` (raw web content; `getContentName()`)

`html` and `text` (`text`, `index: false`), `sourceid` (keyword) and
`created_at` (date). Raw original HTML per sourceid, ingested inline during
indexing (`ingestRawContentForSources`) or in bulk via
`createindex.php --import-raw`. Serves `rawpage.php`.

### `hawaiian-source-metadata` (`config/source_metadata_mapping.json`)

One record per source: `sourceid`, `sourcename`, `groupname`, `authors`,
`date`, `link`, `title` (+ `title.keyword`), `created_at`, and the
discarded/empty/english-only/vectors-missing booleans (`discarded`, `empty`,
`english_only`, `vectors_missing`) plus `quality` (float). Used to
resume interrupted runs and to show source display metadata. Rebuildable from
the documents index via `scripts/rebuild_source_metadata.php`.

Note: this index uses `source_metadata_mapping.json`, not
`metadata_mapping.json`; it has no `sentencecount` field.

### `hawaiian-metadata` (`config/metadata_mapping.json`)

Sentence-metadata side index keyed by sentence hash:
`doc_id`, `sentence_hash`, `frequency` (integer), `metadata` (object).
Written through `MetadataCache` / `ElasticsearchClient::updateMetadata()`;
rebuildable from the sentences index with
`scripts/rebuild_metadata_from_sentences.php`.

### `processing-logs` (operation log)

Dynamically mapped: `status`, `operation_type`, `groupname`, `source_id`,
`error_message`, `parser_key` are text **with** `.keyword` subfields (this is
the one index where `.keyword` names are correct). Written by
`providers/Elasticsearch/ElasticsearchProcessingLogger.php` via
`lib/ProcessingLogger.php` (start/finish processing-log operations around
ingestion runs).

### `hawaiian-searchstats`

Search statistics (`config/searchstats_mapping.json`): `searchterm`
(text with `.keyword`), `pattern`/`sort` (keyword), `results` (integer),
`elapsed` (float), `created` (date).

### Aliases

`ElasticsearchClient::createAliases()` maintains stable production aliases
over the concrete indices so the app can use fixed names across reindex runs:

| Alias | Concrete index |
|---|---|
| `hawaiian_documents` | `<base>_documents_new` |
| `hawaiian_sentences` | `<base>_sentences_new` |
| `hawaiian_content` | `<base>-content` |
| `hawaiian_source_metadata` | `<base>-source-metadata` |
| `hawaiian_metadata` | `<base>-metadata` |

Alias names are overridable via `ES_*_ALIAS` in `.env`. During a
`--recreate` run the concrete names alternate with a `_staging` suffix; after
the atomic switch the live physical index keeps whichever build name it had.
`scripts/createindex.php --aliases-only` exposes the operation through the
local `CliAliasClient` / `CliOpenSearchAliasClient` subclasses, whose
`createProductionAliases()` wrapper calls `createAliases()`.

## MySQL (Laana DB; `db/funcs.php`, `createtables.sql` at the repo root)

Connection: `DB_*` in `.env` (default db `laana`). Provider class
`providers/MySQL/MySQLProvider.php`; data access in `db/funcs.php` (`Laana`,
`DB`).

| Table | Purpose / columns |
|---|---|
| `sources` | `sourceID`, `sourceName`, `groupname`, `date`, `authors`, `title`, `link` |
| `sentences` | `sentenceID`, `sourceID`, `hawaiianText`, `englishText`, `simplified`, `wordCount` |
| `contents` | `sourceID`, `html` (raw page), `text` (plain text), `wordCount` |
| `sentence_patterns` | Grammar-pattern matches: one row per (sentence, pattern_type) with `signature`, `confidence` |
| `grammar_pattern_counts` | Summary counts per pattern, refreshed by `refresh_grammar_counts()` (also on an hourly event); powers the grammar dropdown for MySQL |
| `searchstats` | Recorded searches (term, pattern, count, order, elapsed) |
| `stats` | Legacy aggregate counters maintained by triggers |
| `processing_log` | Fetch/parse/ingest operation log (MySQL counterpart of the ES processing-logs index) |

Grammar-pattern queries join `sentence_patterns` with `sentences`/`sources`
when date filtering, else read `grammar_pattern_counts`.

## Postgres (schema `laana`; `pgschema.sql`)

Connection: `PG_*` in `.env`. Provider `providers/Postgres/PostgresProvider.php`
with `PostgresClient`, `PostgresCorpusIndexer`, `PostgresSentenceIndexer`,
`PostgresDocumentIndexer`, `PostgresDocumentIterator`/`PostgresSentenceIterator`,
`PostgresSourceIterator` and `PostgresSourcePipeline`.

Tables: `sources`, `contents`, `sentences`, `sentence_metrics`,
`document_metrics`, `sentence_patterns`, `processing_log`, `searchstats`; the
materialized view `grammar_pattern_counts` backs grammar-dropdown counts.
There is no `documents` table — the old `laana.documents` table has been
dropped, and document text/metadata come from `contents` + `sources`.

Vectors live on the corpus rows, not in the metrics tables:

- `sentences.embedding` — `vector(384)` (multilingual-e5-small), with an
  ivfflat cosine index.
- `contents.embedding_1024` — `vector(1024)`
  (multilingual-e5-large-instruct). There is no 384-dim document vector
  column in the current schema.

`sentence_metrics` and `document_metrics` hold only scalar metrics
(`hawaiian_word_ratio`, `word_count`, `length`, `entity_count`, and
`sentence_metrics.frequency`), keyed by `sentenceid` / `sourceid`.
`scripts/pg_import.php` copies the corpus over from MySQL and fills the
missing vectors and metrics; `scripts/ingest_embeddings.py` is the older
parallel Python backfill (largely abandoned — prefer `pg_import.php`).

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

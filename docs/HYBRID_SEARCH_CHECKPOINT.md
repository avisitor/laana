# Hybrid Search with Postgres — Checkpoint (2025-12-04)

## Overview
- Goal: Achieve reasonable hybrid search quality in Postgres using the same data and embeddings as Elasticsearch, with transactional ingestion and reliable progress/visibility.
- Scope: Postgres-only ingestion path, sentence-first indexing, full metrics, batch writes, verification/revert tooling, live progress snapshots.

## What We Implemented
- Postgres-only indexer:
  - `PostgresSentenceIndexer`: Batches sentences missing embeddings or metrics; computes embeddings via HTTP service; computes metrics; writes atomically.
  - Transaction guarantees: Embeddings and metrics upserted in a single transaction per batch; rollback on any mismatch.
  - Visibility: Verbose batch logs; per-batch JSON snapshots including intent totals and cumulative processed IDs.
- Clients/iterators:
  - `PostgresClient`: PDO adapter; `bulkUpdateSentenceEmbeddings` with pgvector cast; `upsertSentenceMetrics`; helpers to count totals and fetch candidate IDs.
  - `PostgresSentenceIterator`: Selects sentences missing embeddings or metrics, ordered by `sentenceid`.
  - `EmbeddingClient`: Talks to embedding service from `.env` (`EMBEDDING_SERVICE_URL` / `EMBEDDING_ENDPOINT`).
  - `MetricsComputer`: hawaiian_word_ratio, word_count, length, entity_count, frequency.
- Ops tools:
  - `pg_index_sentences.php`: Runner with `--write`, `--limit`, `--verbose`, `--out-json`. Prints totals, live progress, writes final summary.
  - `pg_next_sentences.php`: Preview next IDs (JSON/CSV).
  - `pg_check_sentences.php`: Verify emb/metrics presence by IDs (reads JSON/plain).
  - `pg_revert_sentences.php`: Transactionally null embeddings and delete metrics by IDs.
- Instrumentation: Timing per batch and aggregated (embed_ms, metrics_ms, db_ms, total_ms), throughput computed.

## Current Status
- Indexing performance:
  - 100 sentences: ~10s total; embedding time dominates (>99%).
  - 1000 sentences: ~95s total; ~10–11 sent/s throughput; DB time small.
- Transactional writes: Verified that partial writes roll back; mismatches/error paths log to stderr.
- Progress reporting: Pre-run totals and per-batch "processed / total" lines; JSON snapshots include `intent.count` (planned) and `progress.ids` (cumulative processed).
- Safety and control: `--limit` caps processing; revert and verify tools in place.

## Differences vs Elasticsearch Path
- Ordering: Postgres sentence selection is order-agnostic and by `sentenceid`; ES earlier assumed document-first flow; we now operate sentence-first.
- Metrics: Computed locally in PHP consistently; no ES-dependent fields.
- Index behavior: ES offered built-in scoring and custom analyzers; Postgres will rely on vector similarity and SQL filtering/boosting.

## Known Gaps (to revisit)
- Coverage: Need embeddings for all or most sentences to enable robust hybrid search quality comparisons.
- Progress snapshots at scale: Full intent IDs are capped to avoid memory blow-ups; large runs store intent count only.
- Query layer parity: Postgres hybrid ranking (vector + lexical + metrics) needs careful calibration to mirror ES scoring.
- Analyzer/tokenization parity: ES analyzers differ; need consistent tokenization for lexical components in Postgres.

## Ideas to Improve Quality (Postgres parity with ES)
- Vector search tuning:
  - Use `pgvector` distance consistent with embedding training: cosine vs inner product; normalize vectors if needed.
  - Add ANN indexes (`ivfflat`) with appropriate lists and reindex parameters per corpus size.
- Lexical component:
  - Build term frequency/inverse document frequency tables to emulate TF-IDF; or leverage `tsvector` + `tsquery` with tuned dictionaries.
  - Ensure consistent tokenization and stopword handling aligned with ES analyzers.
- Hybrid scoring:
  - Combine vector similarity with lexical score and metrics via a weighted linear model.
  - Learn weights using a small labeled relevance set; store model weights in config.
  - Consider query-dependent boosts (e.g., higher weight for lexical when query length is longer).
- Metrics usage:
  - Integrate `hawaiian_word_ratio`, `entity_count`, `length`, and `frequency` as normalization/boost terms.
  - Penalize boilerplate by length/frequency heuristics.
- Query-time optimizations:
  - Pre-filter by metrics to reduce candidate set (e.g., exclude extremely short/long sentences).
  - Use materialized views or cached candidate sets for common queries.
- Evaluation harness:
  - Create a side-by-side comparison script that runs identical queries against ES and Postgres, collects top-k, and computes overlap/ndcg.
  - Freeze the embedding service version and configuration for reproducibility.

## Operational Notes
- Env/Config: Embedding endpoint loaded from `.env` (`EMBEDDING_SERVICE_URL` / `EMBEDDING_ENDPOINT`).
- Error reporting: Premature exits and write errors are sent to stderr; JSON snapshots can be extended with `warnings` if desired.
- Large runs: Consider `ANALYZE` after major updates; manage `pgvector` index maintenance.

## Restart Checklist
1. Ensure embedding service is reachable and consistent (same model and prefix).
2. Run a capped write to validate pipeline: `--limit 1000 --write --verbose`.
3. Verify coverage with `pg_check_sentences.php` on sampled IDs.
4. Scale up in batches; monitor throughput and DB times; consider ANN index config.
5. Begin query parity experiments with a small labeled set; tune hybrid weights.

## File Pointers
- Indexer: `noiiolelo/lib/PostgresSentenceIndexer.php`
- Client: `noiiolelo/lib/PostgresClient.php`
- Iterator: `noiiolelo/lib/PostgresSentenceIterator.php`
- Metrics: `noiiolelo/lib/MetricsComputer.php`
- Runner: `noiiolelo/ops/pg_index_sentences.php`
- Preview/Check/Revert: `noiiolelo/ops/pg_next_sentences.php`, `noiiolelo/ops/pg_check_sentences.php`, `noiiolelo/ops/pg_revert_sentences.php`

# Postgres source fidelity fixes for `--source=postgres`

**Date:** 2026-09-01
**Status:** Approved (abort-on-failure semantics; optional fixes included)
**Scope:** `scripts/createindex.php --source=postgres` default-run fidelity vs the laana Postgres source.

## Problem summary

Evaluation of the postgres path found five gaps between the source repository
(laana) and the target Elasticsearch/OpenSearch indices:

1. **Sentences with missing vectors are dropped with no fallback** — a sentence
   whose `laana.sentences.embedding` is NULL/`[]`/wrong-dim is silently skipped
   (CorpusIndexer::processSourceWithStoredVectors). If *every* sentence of a doc
   lacks a vector, the whole doc is dropped with the misleading
   `english_only` flag. Re-runs never heal the gap (sourceids in
   source-metadata are always skipped).
2. **`boilerplate_score` is always 0/null** — laana `sentence_metrics` has no
   such column, and the postgres path never computes it (the api path computes
   it live via MetadataExtractor/CorpusScanner).
3. **`sources.title` is never transferred** — both the iterator and
   PostgresSourceReader SELECT it, but the target uses `sourcename` as `title`.
4. **Sentence `_id`/`chunk_id` desync from `position`** — dropped sentences
   compact the array index used for `_id`/`chunk_id`, while the `position`
   field keeps the source ordinal.
5. **`hawaiian-metadata` index stays empty** in postgres mode (no
   `bulkSaveSentenceMetadata` call).

## Design decisions

- **Sentence re-embed failure aborts the run** (mirrors the existing doc-vector
  1024 fallback): transport failure after `retryEmbeddingOperation` retries
  propagates. Fail-fast + checkpoint resume beats silent loss.
- **Capability-driven, not hard-coded**: whether the source supplies
  per-sentence `boilerplate_score` is a queried `SourceCapabilities` flag. The
  indexer computes it only when the source does not supply it.
- **`vectors_missing` becomes a distinct source-metadata flag**; `english_only`
  remains exclusively the Hawaiian-ratio gate. Sources flagged
  `vectors_missing` are reprocessed on re-runs (self-heal) instead of skipped.

## Fixes

### 1. Sentence vector re-embed fallback (P0)

In `processSourceWithStoredVectors()` (providers/Elasticsearch/src/CorpusIndexer.php):

- Sentences failing the 384-dim check are collected (text + metrics) instead of
  dropped. Sentences with empty/non-string text are dropped (unembeddable).
- Collected sentences are re-embedded via
  `getEmbeddingClient()->embedSentences()` in `sentenceBatchSize` chunks, each
  wrapped in `retryEmbeddingOperation()` — mirroring the doc-vector fallback.
- Vectors that come back valid join the sentence objects; invalid responses are
  dropped with a logged count.
- Failure after retries throws → run aborts (resumable at checkpoints).

### 2. `vectors_missing` flag + self-heal (P0)

- When sentences exist but none survive the fallback, set
  `sourceMeta[sourceid]['vectors_missing'] = true` (not `english_only`).
  Sources with zero sentences at all set `empty = true` (field already exists
  in the mapping).
- Both skip sites — `createSplitIndexObjects()` and
  `processSourceWithStoredVectors()` — reprocess sources whose stored meta has
  `vectors_missing` truthy instead of skipping. On successful processing the
  flag is cleared in memory so the next checkpoint persists the recovery.
- Add `vectors_missing` (boolean) to `source_metadata_mapping.json` (ES,
  OpenSearch, and test copies) for explicit mapping.

### 3. Capability-gated boilerplate fill-in (P1)

- `SourceCapabilities`: add `public bool $sentenceBoilerplateScore = false;`
  ("source supplies per-sentence boilerplate_score").
- `PostgresSourceIterator::getCapabilities()` leaves it `false` with a comment:
  laana `sentence_metrics` has no such column today; if one lands, flip to
  `true` + SELECT it in `readSource()` and the fill-in disables itself.
- In `processSourceWithStoredVectors()`, when the capability is false, compute
  missing metrics per sentence via `metadataExtractor->analyzeSentence()` and
  merge **only fields laana did not supply** (boilerplate_score is always the
  missing one; sentence_metrics values are authoritative).
- Doc-level aggregation in `createSplitIndexObjects()` then yields real doc
  `boilerplate_score` unchanged.
- api path unchanged (it already computes via `analyzeSentence`).

### 4. Carry `sources.title` (P1)

- `assembleDocSource()`: add
  `"title" => $source['title'] ?? ($source['sourcename'] ?? 'N/A')`.
- `createSplitIndexObjects()`: use `$docData['_source']['title']` (fallback
  sourcename) for the document `title` and sentence `title`.
- No new DB work: both iterator and reader already SELECT title; the target
  mapping already accepts `title`.

### 5. Stable sentence IDs (P2, optional — approved)

`createSplitIndexObjects()` uses the sentence's source ordinal
(`$sentence['position']`) for `_id` and `chunk_id` instead of the compacted
array index, ending the `_id`↔`position` desync. Re-indexing replaces cleanly
via `deleteByDocId()`.

### 6. Metadata-index parity (P2, optional — approved)

`processSourceWithStoredVectors()` batches analyzed sentences into
`bulkSaveSentenceMetadata()` (skipped in dryrun), so `hawaiian-metadata` is
populated in postgres mode just like api mode.

## Non-goals

- No change to the 0.1 doc / 0.5 sentence Hawaiian-ratio gates or to the
  English-only exclusion policy (same as `--source=api`).
- No change to bulk indexing, checkpoints, aliases, or raw-content ingestion.
- Legacy 384-dim document vector stays unpopulated (laana no longer stores it).

## Verification plan

1. Unit tests beside `tests/Indexing/AssembleDocSourceTest.php` and
   `tests/Source/PostgresSourceIteratorTest.php`: capability flags; boilerplate
   fill-in merge (source values win; missing ones computed); fallback embed
   path (stub embedding client); `vectors_missing` flag + self-heal skip logic;
   title propagation; stable sentence IDs.
2. `composer test` (phpunit suite) green.
3. `php -l` on all changed files.
4. Manual: `--dryrun`, then a small `--source-id` live run against laana;
   compare laana `sources`/`sentences` counts vs indexed docs.

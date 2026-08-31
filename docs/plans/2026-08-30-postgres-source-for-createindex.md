# Postgres-as-Source for createindex.php Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let `scripts/createindex.php` ingest documents, sentences, document embeddings, and sentence embeddings from Postgres (the `laana` schema) instead of from the MySQL HTTP API + live embedding service, while still indexing into Elasticsearch (default) or OpenSearch via `--provider`.

**Architecture:** Add a `--source=postgres` flag (default `api` keeps today's behavior, so no regression). A new `PostgresSourceIterator` (same `getSize()`/`getNext()` seam as `SourceIterator`) feeds source metadata; a new `PostgresSourceReader` reads text/sentences/vectors/metrics straight from Postgres and returns the exact normalized structure `CorpusIndexer::processSource()` already assembles. The embedding computation in `processSource()` is factored behind a shared `assembleDocSource()` helper so both the API path and the Postgres path produce an identical `_source` object and hit the same validation + indexing tail. Because the ES/OS client is injected into `CorpusIndexer`, the Postgres source automatically works for both providers. The only schema addition is a 1024-dim document vector column (Postgres only stores 384 today), which is backfilled with the large embedding model.

**Tech Stack:** PHP 8.x, PDO/pgsql, pgvector (`vector(384)`/`vector(1024)`), Guzzle `EmbeddingClient` (HawaiianSearch namespace, `MODEL_LARGE`), PHPUnit (`vendor/bin/phpunit`), existing `PostgresClient`/`PostgresLaana` connection layer.

---

## Key constraints discovered (read before implementing)

1. **Dimension mismatch (blocker):** ES/OS requires `text_vector_1024` == **1024** and `sentence.vector` == **384**. Postgres stores ALL vectors as `vector(384)`, including document vectors (`contents.embedding`, `documents.text_vector`). → We must add `contents.embedding_1024 vector(1024)` and backfill it. Sentence vectors need no schema change.
2. **Embeddings are not populated yet** (live DB: 0/14,267 docs, 0/2.78M sentences have vectors). A working "read from Postgres" path requires backfilling BOTH 384-dim (sentences + `contents.embedding`) AND 1024-dim (`contents.embedding_1024`).
3. **No "read stored embedding" helper exists.** The current Postgres provider only skips already-embedded rows (`s.embedding IS NULL`). We write this reader from scratch and parse pgvector text format `[0.1,0.2,...]`.
4. **Provider-agnostic output:** `CorpusIndexer` only calls client methods; `--provider=opensearch` just swaps the injected client. No Postgres-path code differs by provider.
5. **`documents` table is empty (0 rows);** document text/metadata come from `contents` + `sources`. `sentences` has no `position` column → order by `sentenceid` and assign `position` = sequential index.
6. **`.env` already loaded by `createindex.php`**, so `new PostgresClient()` picks up `PG_*` vars automatically (do NOT pass `PG_DSN`/`PG_USER`/`PG_PASSWORD` in the config array — `PostgresLaana::connect()` ignores them).

---

## Task 1: pgvector text parser (pure, unit-tested)

**Files:**
- Create: `providers/Elasticsearch/src/PostgresSourceReader.php`
- Test: `tests/Source/PostgresSourceReaderTest.php`

**Step 1: Write the failing test**

```php
<?php
namespace HawaiianSearch\Tests;
use HawaiianSearch\PostgresSourceReader;
use PHPUnit\Framework\TestCase;

class PostgresSourceReaderTest extends TestCase
{
    public function test_pgvector_to_array_parses_brackets_and_spaces(): void
    {
        $this->assertEquals(
            [0.1, 0.2, 0.3],
            PostgresSourceReader::pgvectorToArray('[0.1, 0.2, 0.3]')
        );
    }

    public function test_pgvector_to_array_empty(): void
    {
        $this->assertSame([], PostgresSourceReader::pgvectorToArray('[]'));
        $this->assertSame([], PostgresSourceReader::pgvectorToArray(null));
    }

    public function test_pgvector_to_array_1024_dims(): void
    {
        $vec = '[' . implode(',', array_fill(0, 1024, '0.5')) . ']';
        $this->assertCount(1024, PostgresSourceReader::pgvectorToArray($vec));
    }
}
```

**Step 2: Run test to verify it fails** — `vendor/bin/phpunit tests/Source/PostgresSourceReaderTest.php`
Expected: FAIL (class `PostgresSourceReader` not found).

**Step 3: Write minimal implementation** (in `PostgresSourceReader.php`, HawaiianSearch namespace):

```php
<?php
namespace HawaiianSearch;

class PostgresSourceReader
{
    public static function pgvectorToArray($pg): array
    {
        if ($pg === null || $pg === '') {
            return [];
        }
        $s = trim((string)$pg);
        if (str_starts_with($s, '[')) {
            $s = substr($s, 1);
        }
        if (str_ends_with($s, ']')) {
            $s = substr($s, 0, -1);
        }
        if ($s === '') {
            return [];
        }
        return array_map(static fn($v) => (float)$v, explode(',', $s));
    }
}
```

**Step 4: Run test to verify it passes** — `vendor/bin/phpunit tests/Source/PostgresSourceReaderTest.php`
Expected: PASS.

**Step 5: Commit**
```bash
git add providers/Elasticsearch/src/PostgresSourceReader.php tests/Source/PostgresSourceReaderTest.php
git commit -m "feat(indexer): add pgvector text parser for Postgres source path"
```

---

## Task 2: PostgresSourceReader — read doc + sentences + vectors + metrics

**Files:**
- Modify: `providers/Elasticsearch/src/PostgresSourceReader.php`
- Test: `tests/Source/PostgresSourceReaderTest.php`

Design: `PostgresSourceReader` takes a `\PostgresLaana`/`PostgresClient` (PDO available as `$pg->conn`). Method `readSource(int $sourceId): ?array` returns a normalized structure:

```php
[
  'sourceid' => int,
  'sourcename' => string, 'groupname' => string, 'authors' => string,
  'date' => string, 'link' => string, 'title' => string,
  'text' => string,            // contents.text (plain)
  'html' => string|null,       // contents.html (for --import-raw)
  'text_vector_1024' => ?array,// contents.embedding_1024 (1024) or null
  'hawaiian_word_ratio' => ?float, // document_metrics
  'sentences' => [             // ordered by sentenceid
     [ 'text' => string, 'vector' => array(384), 'position' => int,
       'hawaiian_word_ratio' => ?float, 'word_count' => ?int,
       'entity_count' => ?int, 'frequency' => ?int, 'length' => ?int ],
     ...
  ],
]
```

**Step 1: Write the failing test** (mock the PDO via a fake client object):

```php
public function test_readSource_maps_rows_to_normalized_shape(): void
{
    $pg = $this->createMock(\PostgresLaana::class);
    $stmt = $this->createMock(\PDOStatement::class);
    // first query: source + content; second: sentences join metrics
    $pg->conn = $this->createMock(\PDO::class);
    $pg->conn->method('prepare')->willReturn($stmt);
    $stmt->method('execute')->willReturn(true);
    $stmt->method('fetch')->willReturnOnConsecutiveCalls(
        ['sourceid'=>1,'sourcename'=>'N','groupname'=>'g','authors'=>'a',
         'date'=>'2020-01-01','link'=>'l','title'=>'t','text'=>'aloha kākou',
         'html'=>'<p>aloha</p>','embedding_1024'=>'['.implode(',',array_fill(0,1024,'0.1')).']',
         'doc_ratio'=>0.9],
        false,
        ['sentenceid'=>10,'hawaiiantext'=>'aloha','embedding'=>'[0.1,0.2,0.3]',
         'sent_ratio'=>0.8,'word_count'=>2,'entity_count'=>1,'frequency'=>3,'length'=>5],
        false
    );
    $reader = new PostgresSourceReader($pg);
    $out = $reader->readSource(1);
    $this->assertSame(1, $out['sourceid']);
    $this->assertCount(1024, $out['text_vector_1024']);
    $this->assertCount(1, $out['sentences']);
    $this->assertSame(384, count($out['sentences'][0]['vector']));
    $this->assertSame(0, $out['sentences'][0]['position']);
    $this->assertSame(0.8, $out['sentences'][0]['hawaiian_word_ratio']);
}
```

**Step 2: Run** — FAIL (method missing).

**Step 3: Implement** the `readSource()` method with two prepared queries:
- Doc+content+metrics (LEFT JOIN `document_metrics` ON `document_metrics.sourceid = contents.sourceid`):
  ```sql
  SELECT s.sourceid, s.sourcename, s.groupname, s.authors, s.date, s.link, s.title,
         c.text, c.html, c.embedding_1024,
         dm.hawaiian_word_ratio AS doc_ratio
  FROM sources s
  JOIN contents c ON c.sourceid = s.sourceid
  LEFT JOIN document_metrics dm ON dm.sourceid = c.sourceid
  WHERE s.sourceid = :sid
  ```
- Sentences + metrics (LEFT JOIN `sentence_metrics`):
  ```sql
  SELECT st.sentenceid, st.hawaiiantext, st.embedding,
         sm.hawaiian_word_ratio AS sent_ratio, sm.word_count,
         sm.entity_count, sm.frequency, sm.length
  FROM sentences st
  LEFT JOIN sentence_metrics sm ON sm.sentenceid = st.sentenceid
  WHERE st.sourceid = :sid
  ORDER BY st.sentenceid
  ```
  Map each row: `vector = pgvectorToArray($row['embedding'])`, `position = $idx`, copy metrics (null if absent). Return `null` if no `contents` row.

**Step 4: Run** — PASS.

**Step 5: Commit**
```bash
git commit -am "feat(indexer): read document/sentences/vectors/metrics from Postgres"
```

---

## Task 3: PostgresSourceIterator (HawaiianSearch seam)

**Files:**
- Create: `providers/Elasticsearch/src/PostgresSourceIterator.php`
- Test: `tests/Source/PostgresSourceIteratorTest.php`

Mirrors `SourceIterator` (getSize/getNext) but queries Postgres. Implements `getSize(): int`, `getNext(): ?array` returning `[$source]` batches. Supports `sourceId` / `groupName` filters. Returns keys: `sourceid, sourcename, groupname, authors, date, link, title` (exactly what `processSource()` consumes).

**Step 1: Write failing test** — asserts `getSize()` returns count and `getNext()` yields arrays with `sourceid`, filters by groupName.

**Step 2: Run** — FAIL.

**Step 3: Implement** (namespace `HawaiianSearch`; `use` global `\PostgresLaana`):
- Constructor: `$this->pg = new \PostgresLaana();` load all source IDs/metadata once via
  `SELECT sourceid, sourcename, groupname, authors, date, link, title FROM sources` (optionally `WHERE groupname = :g` / `WHERE sourceid = :id`), cache in `$this->sources`, set `$this->estimatedSize = count`.
- `getSize(): int { return $this->estimatedSize; }`
- `getNext(): ?array { return $this->position >= count($this->sources) ? null : [$this->sources[$this->position++]]; }`

(Reuse `PostgresClient::fetchSources()` if available; otherwise inline the SELECT. Note the existing `providers/Postgres/PostgresSourceIterator.php` is NOT in the `HawaiianSearch` namespace and returns `getSize()==0`; do not reuse it directly — create this new one so it fits `CorpusIndexer`'s seam.)

**Step 4: Run** — PASS.

**Step 5: Commit**
```bash
git commit -am "feat(indexer): PostgresSourceIterator for --source=postgres"
```

---

## Task 4: Schema migration — add 1024-dim document vector

**Files:**
- Create: `db/migrations/2026-08-30-add-doc-vector-1024.sql`
- Test: `tests/Source/PgSchema1024Test.php` (skipped unless `PG_HOST` reachable)

```sql
-- Add a 1024-dim document embedding column to mirror ES text_vector_1024.
ALTER TABLE laana.contents
  ADD COLUMN IF NOT EXISTS embedding_1024 public.vector(1024);
-- (Optional, only if you also populate laana.documents)
-- ALTER TABLE laana.documents
--   ADD COLUMN IF NOT EXISTS text_vector_1024 public.vector(1024);
```

**Step 1: Write a test** that connects via `PostgresClient` and asserts the column exists:
```php
$pg = new \PostgresLaana();
$cols = $pg->conn->query(
  "SELECT column_name FROM information_schema.columns
   WHERE table_schema='laana' AND table_name='contents' AND column_name='embedding_1024'"
)->fetchAll();
$this->assertNotEmpty($cols);
```
Guard with `if (!getenv('PG_HOST')) $this->markTestSkipped('No PG');`.

**Step 2: Run** — FAIL (column missing).

**Step 3: Apply migration** against the DB:
```bash
psql "host=$PG_HOST port=$PG_PORT dbname=$PG_DATABASE user=$PG_USER password=$PG_PASSWORD" \
  -f db/migrations/2026-08-30-add-doc-vector-1024.sql
```

**Step 4: Run** — PASS.

**Step 5: Commit**
```bash
git add db/migrations/2026-08-30-add-doc-vector-1024.sql tests/Source/PgSchema1024Test.php
git commit -m "schema: add contents.embedding_1024 (1024-dim) for ES/OS doc vectors"
```

---

## Task 5: Backfill 1024-dim document vectors

**Files:**
- Create: `scripts/backfill_pg_doc_vectors_1024.php`
- Test: `tests/Source/Backfill1024Test.php` (subset / dry-run assertion)

Uses `HawaiianSearch\EmbeddingClient` (has `MODEL_LARGE = intfloat/multilingual-e5-large-instruct` → 1024). Reads `contents` rows where `embedding_1024 IS NULL`, embeds `text` with prefix `'passage: '`, writes back via the same staging-table pattern used by `PostgresClient::bulkUpdateDocumentEmbeddings` but casting to `vector(1024)`.

```php
// core loop (keyset pagination on sourceid)
$vec = (new \HawaiianSearch\EmbeddingClient())->embedText($text, 'passage: ', \HawaiianSearch\EmbeddingClient::MODEL_LARGE);
$pg->conn->exec("CREATE TEMP TABLE IF NOT EXISTS staging_doc1024 (sourceid bigint, embedding vector(1024)) ON COMMIT DROP");
$ins = $pg->conn->prepare("INSERT INTO staging_doc1024 VALUES (:sid, (:e)::vector(1024))");
// bind (:e) as '[' . implode(',', $vec) . ']'
$pg->conn->exec("UPDATE contents c SET embedding_1024 = s.embedding FROM staging_doc1024 s WHERE c.sourceid = s.sourceid");
```

**Step 1: Test** — run on `--limit 5` and assert 5 rows now have non-null `embedding_1024`.

**Step 2: Run** — `php scripts/backfill_pg_doc_vectors_1024.php --limit 5` then test.

**Step 3: Implement** full script with `--limit`, `--dryrun`, checkpointing, and the same retry/health logic style as `CorpusIndexer::retryEmbeddingOperation`.

**Step 4: Run** full backfill (no limit) and verify `SELECT count(*) FROM laana.contents WHERE embedding_1024 IS NULL` == 0.

**Step 5: Commit**
```bash
git add scripts/backfill_pg_doc_vectors_1024.php tests/Source/Backfill1024Test.php
git commit -m "feat: backfill 1024-dim document vectors into Postgres"
```

---

## Task 6: Prerequisite — backfill 384-dim vectors (reuse existing tooling)

The 384-dim sentence and `contents.embedding` vectors must also exist before the Postgres path can serve them. These are NOT yet populated.

**Step 1: Verify current state**
```bash
psql ... -c "SELECT count(*) FROM laana.sentences WHERE embedding IS NULL;
             SELECT count(*) FROM laana.contents WHERE embedding IS NULL;"
```

**Step 2: Backfill using existing tooling** (do not reinvent):
- Sentences: `php scripts/pg_index_sentences.php` (uses `PostgresSentenceIndexer`, writes `sentences.embedding` 384).
- Document 384 (if `contents.embedding` is needed as a fallback for the doc vector): `php scripts/pg_indexer.php` (writes `contents.embedding` 384).
- Or the Python path: `EMBED_MODEL=intfloat/multilingual-e5-small python scripts/ingest_embeddings.py`.

**Step 3: Assert** both counts are 0.

**Step 4: Commit** — no code change; record the run in a short ops note (do NOT commit DB data). If you add a small idempotent wrapper script `scripts/backfill_pg_vectors_384.php`, commit that:
```bash
git commit -am "ops: idempotent 384-dim Postgres backfill wrapper"
```

---

## Task 7: Refactor CorpusIndexer — shared assembly + Postgres dispatch

**Files:**
- Modify: `providers/Elasticsearch/src/CorpusIndexer.php`
- Test: `tests/Indexing/AssembleDocSourceTest.php`

**Step 1: Extract the tail of `processSource()` (lines ~742-802) into a helper** that both paths call:
```php
private function assembleDocSource(
    array $source, string $originalText, array $chunks,
    $textVector1024, array $sentenceObjects, float $docHawaiianWordRatio
): ?array {
    // exact existing logic: build $docSource, validate text_vector_1024 === 1024,
    // validate each sentence vector === 384, return $doc or null (unchanged).
}
```
Add a thin `indexDocument(array $doc): void` that performs the existing `$this->client->...` indexing call the caller previously did inline (keep `createSplitIndexObjects()`/inline behavior identical).

**Step 2: Add the Postgres path:**
```php
private function processSourceFromPostgres(array $source, int $indexCounter): ?array
{
    $reader = new PostgresSourceReader($this->postgresClient);
    $data = $reader->readSource((int)($source['sourceid'] ?? $source['doc_id']));
    if ($data === null) { $this->sourceMeta[...]['discarded'] = true; return null; }

    $sentenceObjects = [];
    foreach ($data['sentences'] as $s) {
        if (count($s['vector']) !== 384) { continue; } // mirror existing skip
        $obj = [
            'text' => $s['text'], 'vector' => $s['vector'],
            'position' => $s['position'], 'doc_id' => (string)$data['sourceid'],
        ];
        foreach (['hawaiian_word_ratio','word_count','entity_count','boilerplate_score','length','frequency'] as $m) {
            if (isset($s[$m])) { $obj[$m] = $s[$m]; }
        }
        $sentenceObjects[] = $obj;
    }

    $textVector1024 = $data['text_vector_1024'];
    if (empty($textVector1024) || count($textVector1024) !== 1024) {
        // Graceful fallback: compute live (keeps plan robust if 1024 not backfilled)
        $textVector1024 = $this->retryEmbeddingOperation(
            fn() => $this->client->getEmbeddingClient()->embedText($data['text'], 'passage: ', EmbeddingClient::MODEL_LARGE),
            'embedText', 'text (large)');
    }
    $ratio = $data['hawaiian_word_ratio'] ?? $this->calculateHawaiianWordRatio($data['text']);

    return $this->assembleDocSource(
        $source, $data['text'], $this->buildTextChunks($data['text']),
        $textVector1024, $sentenceObjects, (float)$ratio
    );
}
```
Also extract `buildTextChunks(string $text): array` from the existing chunking block (lines ~695-738) so both paths use identical chunking.

**Step 3: Dispatch.** Add a `processSourceDispatch($source, $indexCounter)` that returns `($this->config['source'] === 'postgres') ? $this->processSourceFromPostgres($source,$indexCounter) : $this->processSource($source,$indexCounter);`. Replace the two `processSource(...)` call sites (main loop + `createSplitIndexObjects`) with the dispatcher. Add `$this->postgresClient = ($config['source']==='postgres') ? new \PostgresLaana() : null;` in the constructor.

**Step 4: Test** — `AssembleDocSourceTest` builds inputs and asserts the returned `_source` has `text_vector_1024` (1024), `sentences[].vector` (384), and all metadata keys — proving the API and Postgres paths produce identical structure.

**Step 5: Run** — `vendor/bin/phpunit tests/Indexing/AssembleDocSourceTest.php` PASS.

**Step 6: Commit**
```bash
git commit -am "refactor(indexer): share doc assembly; add Postgres source path"
```

---

## Task 8: Wire `--source` flag in createindex.php

**Files:**
- Modify: `scripts/createindex.php`
- Test: `tests/Cli/SourceFlagTest.php` (parse-only)

**Step 1: Add flag + config.**
- In `$longOptions` (lines 77-99) add: `'source:',`
- After parsing (lines 142-158) add:
  ```php
  'source' => $options['source'] ?? 'api',   // 'api' (default) | 'postgres'
  ```
- Pass through to `$config` (already assembled there).

**Step 2: Branch the iterator.** In `CorpusIndexer::fetchSourceIterator()` (lines 299-306):
```php
if ($this->config['source'] === 'postgres') {
    return new PostgresSourceIterator($sourceid, $this->groupName);
}
// existing api / reindex branches unchanged
```

**Step 3: Test parse** — `SourceFlagTest` invokes `getopt` simulation or asserts `createindex.php --source=postgres --dryrun --help` does not error and that `source` is threaded (can assert via a wrapper that prints config). Minimal: run `php scripts/createindex.php --source=postgres --dryrun --max-documents 1` and confirm it attempts Postgres iteration (no "MySQL API" fetch errors).

**Step 4: Run** — dry-run with `--source=postgres --max-documents 2` (requires PG + backfilled embeddings from Tasks 4-6). Expected: logs "Processing batch …" from `PostgresSourceIterator`, no dimension errors.

**Step 5: Commit**
```bash
git commit -am "cli: add --source=postgres flag to createindex.php"
```

---

## Task 9: Postgres raw HTML for `--import-raw`

**Files:**
- Modify: `providers/Elasticsearch/src/PostgresSourceReader.php` (add `fetchRaw(int $sourceId): ?string`)
- Modify: `scripts/createindex.php` importRaw path OR `CorpusIndexer::importOneRaw()` to use Postgres when `source===postgres`.
- Test: `tests/Source/PostgresRawTest.php`

`fetchRaw()` returns `contents.html`. In `importOneRaw()` branch: if `$this->config['source']==='postgres'`, read HTML from `PostgresSourceReader::fetchRaw($sourceid)` instead of `SourceRetriever`.

**Step 1: Test** — mocked PDO returns html for a sourceid; assert `fetchRaw` returns it.

**Step 2: Run** — FAIL then implement.

**Step 3: Run** — `php scripts/createindex.php --source=postgres --import-raw --max-documents 2` indexes raw content from Postgres.

**Step 4: Commit**
```bash
git commit -am "feat(indexer): read raw HTML from Postgres for --import-raw"
```

---

## Task 10: End-to-end verification (both providers)

**Step 1: Elasticsearch (default)**
```bash
php scripts/createindex.php --source=postgres --recreate --verbose --max-documents 20
```
Expected: index builds, `IndexSchemaValidator` passes, no `text_vector_1024`/sentence dimension errors.

**Step 2: OpenSearch**
```bash
php scripts/createindex.php --source=postgres --provider=opensearch --recreate --verbose --max-documents 20
```
Expected: same success on the OS client (proves provider-agnostic output).

**Step 3: Full test suite**
```bash
vendor/bin/phpunit
```
Expected: all green (including the new Postgres tests; DB-dependent ones skip cleanly without `PG_HOST`).

**Step 4: Commit** (if any fixtures/changes needed) — else mark complete.

---

## Task 11: Docs + final commit

**Files:**
- Modify: `docs/PG_INDEXER_README.md` (or `docs/INGESTION.md`) — document `--source=postgres`, the required backfills (Tasks 4-6), and the 1024-dim column.
- Modify: `scripts/createindex.php` `printUsage()` (lines 342-389) — add the `--source=postgres` line.

**Step 1: Update usage text** and a short "Postgres as source" section.

**Step 2: Commit**
```bash
git commit -am "docs: document --source=postgres ingestion path"
```

---

## Verification checklist (before claiming done)
- [ ] `vendor/bin/phpunit` passes (DB tests skip without `PG_HOST`).
- [ ] `php scripts/createindex.php --source=postgres --dryrun --max-documents 2` iterates Postgres, no errors.
- [ ] Postgres `contents.embedding_1024` populated for all rows (Task 5).
- [ ] Postgres `sentences.embedding` + `contents.embedding` populated (Task 6).
- [ ] Both `--provider=elasticsearch` (default) and `--provider=opensearch` runs succeed with `--source=postgres`.
- [ ] Default behavior unchanged: `php scripts/createindex.php --dryrun` (no `--source`) still uses the MySQL API path.

## Notes / risks
- The 1024 backfill (Task 5) is the single hard prerequisite; the graceful fallback in `processSourceFromPostgres()` keeps the plan runnable incrementally even before it finishes.
- If you would rather NOT add a 1024 column, the alternative is to change the ES/OS document mapping to 384 for `text_vector_1024` — out of scope here and changes search semantics; not recommended.
- Sentence ordering uses `sentenceid`; if a `position` column is added to `laana.sentences` later, switch the `ORDER BY` accordingly.

---

## Post-implementation note (2026-08-30)

Tasks 1-11 were implemented with one design change requested during review:
**capability-driven dispatch**. Instead of `processSource()` branching on the
`source === 'postgres'` config string, each source provider now implements
`SourceProviderInterface::getCapabilities(): SourceCapabilities`
(`providers/Elasticsearch/src/SourceCapabilities.php`: `sentenceVectors`,
`documentVector384`, `documentVector1024`, `rawHtml`). The config flag only
*assigns* the provider (`fetchSourceIterator()`); the indexer queries the
provider and falls back per capability (e.g. stored 1024 vector missing ->
live `MODEL_LARGE` embed; no rawHtml capability -> SourceRetriever API path).
`processSourceFromPostgres()` was therefore named
`processSourceWithStoredVectors()`. Consequence: adding a new source backend
requires only a new `SourceProviderInterface` implementation.

Also fixed en route: the PHP 384-dim backfill toolchain was broken
(`providers/Postgres/PostgresClient.php` require paths, missing
`providers/Postgres/MetricsComputer.php` require, wrong namespaces in
`scripts/pg_indexer.php`, `hawaiian_words.txt` paths) — repaired in commit
`21ceb2c` and verified with subset backfills. `runIndexing()` passes
`sourceid = 0` to mean "no filter", which `PostgresSourceIterator` originally
treated as a literal filter — fixed in commit `f467865`.

Commits: `83351da` (parser), `ea87c62` (readSource), `6398a58` (iterator),
`42afc3c` (1024 migration), `bc5d3e8` (1024 backfill), `21ceb2c` (toolchain
fix), `2cd61a9` + `bc5b25d` (capability dispatch), `f467865` (--source flag),
`f830950` (raw HTML).

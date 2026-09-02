# Save-Provider Parity + Automatic Postgres Grammar Patterns — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make `scripts/save.php --provider=postgres` and `--provider=opensearch` real ingestion paths (as `scripts/updatenoiiolelo.sh` already assumes), with grammar-pattern assignments and counts updating automatically on the Postgres path.

**Architecture:** Extract `pg_import.php`'s per-source Postgres pipeline (migrate → embeddings → metrics → **grammar scan → counts refresh**) into a reusable `PostgresSourcePipeline` class. `PostgresSaveManager extends MySQLSaveManager` reuses MySQL scraping/cataloging (source and sentence IDs stay MySQL-allocated, so the daily path and the `pg_import` bootstrap path can never diverge on IDs) and runs the pipeline per saved source. `OpenSearchSaveManager extends ElasticsearchSaveManager` swapping only the client (`OpenSearchClient extends ElasticsearchClient`, so index-time grammar fields and live aggregations are inherited). `save.php` gains explicit dispatch; unknown providers fail loudly instead of silently writing MySQL.

**Tech Stack:** PHP 8 (PDO pgsql/mysql), pgvector, PHPUnit (`tests/`, env-gated integration tests following `tests/Indexing/ElasticsearchClientTest.php`'s `markTestSkipped` pattern), bash driver scripts.

---

## Background the implementer must know

Read `docs/INGESTION.md` (pipeline map) and `docs/GRAMMAR_PATTERNS.md` first. Key facts:

1. **Today there is no Postgres or OpenSearch save path.** `scripts/save.php:86-92` dispatches only `es` → `ElasticsearchSaveManager`; everything else falls back to `MySQLSaveManager`. The daily driver `scripts/updatenoiiolelo.sh:7` passes `mysql postgres elasticsearch opensearch`, so the `postgres` and `opensearch` runs silently scrape into MySQL and index nothing.
2. **Grammar state per provider:**
   - MySQL: rows in `sentence_patterns`, written at save time (`MySQLSaveManager.php:190-193` → `GrammarScanner::updateSourcePatterns`); counts in `grammar_pattern_counts` table refreshed by hourly MySQL EVENT `hourly_grammar_refresh` (`createtables.sql:96-107`).
   - Elasticsearch/OpenSearch: `grammar_patterns` array field computed **at index time** (`ElasticsearchClient.php:1302` in `processSentencesForIndexing`); counts are a **live terms aggregation** (`ElasticsearchClient.php:2131` `getGrammarPatterns`). Nothing to refresh.
   - Postgres: rows in `laana.sentence_patterns` (UNIQUE (sentenceid, pattern_type), `pgschema.sql:208`); counts in materialized view `laana.grammar_pattern_counts` (`pgschema.sql:269-276`, unique index present so `REFRESH ... CONCURRENTLY` works). **`REFRESH MATERIALIZED VIEW CONCURRENTLY` cannot run inside a transaction block** — always after commit.
3. **`pg_import.php` per-source unit** (`scripts/pg_import.php:477-618`): one transaction per source — upsert source/contents/sentences from MySQL (IDs carried over, `ON CONFLICT` at lines 380-393) → 384-dim sentence embeddings via staging table → `sentence_metrics` → `document_metrics` → 1024-dim `contents.embedding_1024` → commit. It currently does **not** touch `sentence_patterns` and only refreshes the counts view in the `--force` truncate branch (line 339).
4. **ID-parity constraint (design decision):** `PostgresSaveManager` deliberately scrapes through the MySQL path first. Inventing Postgres-side source/sentence IDs would collide with `pg_import` bootstrap upserts (same source, different sentenceids → duplicate sentences). MySQL remains the catalog of record; Postgres is a derived store that can be fed daily by this path or bootstrapped by `pg_import` — both produce identical IDs.
5. **GrammarScanner** (`lib/GrammarScanner.php`): `updateSourcePatterns($sourceId, $force=false)` delta-scans sentences of one source that have no `sentence_patterns` rows; needs a Laana-style DB wrapper (`getDBRows`/`executePrepared`) — `PostgresLaana` provides it. The scanner only reads sentences inserted in the same transaction — visible, since it must run on the **same PDO connection** that the pipeline writes through (assert `$pg === $pgLaana->conn`).

**Env gates for tests** (follow `tests/Indexing/ElasticsearchClientTest.php:17` pattern): PG tests skip unless `PG_HOST`+`PG_DATABASE` set; OpenSearch tests skip unless `OS_HOST` set; MySQL tests skip unless `DB_HOST` set. Run the suite with `tests/run-tests.sh` (see `tests/README.md`).

---

### Task 1: Extract `PostgresSourcePipeline` from `pg_import.php` (pure refactor, no behavior change)

**Files:**
- Create: `providers/Postgres/PostgresSourcePipeline.php`
- Modify: `scripts/pg_import.php`

**Step 1: Write the skeleton test (env-gated, asserts construction + counters shape)**

Create `tests/Database/PostgresSourcePipelineTest.php`:

```php
<?php
namespace Noiiolelo\Tests\Database;

use Noiiolelo\Tests\BaseTestCase;

class PostgresSourcePipelineTest extends BaseTestCase
{
    private function requirePg(): void
    {
        // The pipeline reads source rows from MySQL as well, so both DBs must
        // be configured — otherwise the constructor fails instead of skipping.
        if (!getenv('PG_HOST') || !getenv('PG_DATABASE') || !getenv('DB_HOST') || !getenv('DB_DATABASE')) {
            $this->markTestSkipped('PG_HOST, PG_DATABASE, DB_HOST and DB_DATABASE must be set for PostgresSourcePipeline tests');
        }
    }

    public function testProcessSourceReturnsCounterShape(): void
    {
        $this->requirePg();
        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline([
            'dryrun' => true,
        ]);
        // Counters must always exist, even in dryrun with an unknown source.
        $out = $pipeline->processSource(999999999);
        foreach (['sentences_data','sentence_vectors','sentence_metrics','document_metrics','document_vectors','patterns'] as $k) {
            $this->assertArrayHasKey($k, $out);
            $this->assertSame(0, $out[$k]);
        }
    }
}
```

**Step 2: Run it to verify it fails**

Run: `php vendor/bin/phpunit tests/Database/PostgresSourcePipelineTest.php`
Expected: FAIL — class `PostgresSourcePipeline` not found.

**Step 3: Create the pipeline class**

Move the per-source unit verbatim out of `scripts/pg_import.php` into `providers/Postgres/PostgresSourcePipeline.php`. Structure (constructor deps injected so both `pg_import` and the save manager can supply connections):

```php
<?php
namespace Noiiolelo\Providers\Postgres;

require_once __DIR__ . '/PostgresClient.php';

use Noiiolelo\EmbeddingClient;                            // sentence embeddings (384-dim, small model)
use HawaiianSearch\EmbeddingClient as DocEmbeddingClient; // document vectors (1024-dim, MODEL_LARGE)

/**
 * Per-source Postgres pipeline: MySQL -> laana schema (data, vectors,
 * metrics) plus grammar-pattern scan and counts refresh.
 *
 * One processSource() call = ONE Postgres transaction; the source is a
 * complete unit on commit. Used by scripts/pg_import.php (bootstrap) and
 * providers/Postgres/PostgresSaveManager.php (daily ingestion).
 */
class PostgresSourcePipeline
{
    private \PDO $pg;               // MUST be the same connection as $pgLaana->conn
    private \PDO $mysql;
    private \PostgresLaana $pgLaana;
    private EmbeddingClient $embed;           // 384-dim sentence model
    private DocEmbeddingClient $docEmbed;     // 1024-dim document model (HawaiianSearch\EmbeddingClient alias)
    private \Noiiolelo\MetricsComputer $metrics;
    private bool $dryrun;
    private bool $force;
    private bool $doSentences;
    private bool $doDocuments;

    public function __construct(array $config = []) { /* ... */ }

    /**
     * Migrate one source from MySQL and derive vectors/metrics/patterns.
     * Fetches the source row from MySQL by id (reusing the source query).
     * If the sourceid does not exist in MySQL, returns zero counters
     * WITHOUT opening a Postgres transaction. Otherwise: one transaction;
     * the source is a complete unit on commit. Throws on failure.
     */
    public function processSource(int $sourceId): array
    {
        // [MOVE VERBATIM from scripts/pg_import.php:483-618]
        // beginTransaction -> sourceUpsert/contentUpsert/sentenceUpsert loop
        // -> sentence staging embeddings + sentenceMetricsUpsert
        // -> documentMetricsUpsert + docVectorUpdate -> commit (or rollBack if dryrun).
        // BEFORE commit: $this->scanGrammarPatterns($sourceId);
        // AFTER commit: return counters;
    }

    /** Delta-scan sentence_patterns for one source. Runs INSIDE the open tx. */
    public function scanGrammarPatterns(int $sourceId): int
    {
        // $scanner = new \Noiiolelo\GrammarScanner($this->pgLaana);
        // return $scanner->updateSourcePatterns($sourceId, false);
    }

    /** Refresh the counts materialized view. MUST be called outside a tx. */
    public function refreshGrammarPatternCounts(): bool
    {
        return $this->pgLaana->refreshGrammarPatternCounts();
    }
}
```

Move, do not rewrite: the prepared statements (`sourceUpsert`, `contentUpsert`, `sentenceUpsert`, `sentenceNeedsWork`, `allSentencesForSource`, `docNeedsMetrics`, `docNeedsVector`, `docTextForSource`, `sentenceMetricsUpsert`, `documentMetricsUpsert`, `docVectorUpdate` — `scripts/pg_import.php:377-447`), `vecLiteral()` (450-455), and the loop body (477-618) become instance code. Prepared statements are built once in the constructor. `vecLiteral` stays a global helper in `pg_import.php` too or moves here and is imported — prefer moving it into the class as `public static function vecLiteral(array $vec): string` and updating the two call sites.

Constructor wiring rules:
- If `$config` supplies explicit `pg`/`mysql` PDOs, use them; otherwise construct `PostgresLaana` (its `conn`) and a MySQL PDO from `.env` `DB_*` exactly as `pg_import.php` does today (move that setup block verbatim).
- **Assert** `$this->pg === $this->pgLaana->conn` in the constructor; throw `RuntimeException` if not (grammar scan transaction-visibility depends on it). The scan must run on the `PostgresLaana` connection: `GrammarScanner` uses unqualified table names (`sentences`, `sentence_patterns`), which resolve via the `SET search_path TO laana, public` that `PostgresLaana::connect` issues (`db/PostgresFuncs.php:34`).
- Options: `dryrun` (default false), `force`, `sentences` (default true), `documents` (default true).

**Step 4: Make `pg_import.php` delegate**

Replace the inline loop body with:

```php
$pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline([
    'pg'        => $pg,
    'mysql'     => $mysql,
    'pgLaana'   => $pgLaana,
    'dryrun'    => $dryrun,
    'force'     => $force,
    'sentences' => $doSentences,
    'documents' => $doDocuments,
]);

foreach ($sources as $source) {
    // ... existing [n/total] say() line ...
    try {
        $out = $pipeline->processSource($sid);
        $totals['sentences_data']   += $out['sentences_data'];
        $totals['sentence_vectors'] += $out['sentence_vectors'];
        $totals['sentence_metrics'] += $out['sentence_metrics'];
        $totals['document_metrics'] += $out['document_metrics'];
        $totals['document_vectors'] += $out['document_vectors'];
    } catch (Throwable $e) {
        // existing error handling (rollback + $totals['errors']++) stays
    }
}
```

`--status` and `--dryrun`/`--force` CLI handling, source selection, and the summary section stay untouched in `pg_import.php`.

**Step 5: Verify the refactor is behavior-neutral**

Run:
```bash
php scripts/pg_import.php --status
php scripts/pg_import.php --dryrun --limit=1 --verbose
```
Expected: `--status` output identical to before the change; dry-run processes one source and reports `(dryrun, rolled back)` with the same counter lines as before. (Requires live MySQL + Postgres; skip with a note if unavailable in this environment.)

**Step 6: Run the new test**

Run: `php vendor/bin/phpunit tests/Database/PostgresSourcePipelineTest.php`
Expected: PASS (dryrun counters are all zero).

**Step 7: Commit**

```bash
git add providers/Postgres/PostgresSourcePipeline.php scripts/pg_import.php tests/Database/PostgresSourcePipelineTest.php
git commit -m "refactor: extract per-source Postgres pipeline from pg_import"
```

---

### Task 2: Grammar-pattern scan + counts refresh in the pipeline (new behavior)

**Files:**
- Modify: `providers/Postgres/PostgresSourcePipeline.php`
- Test: `tests/Database/PostgresSourcePipelineTest.php`

**Step 1: Write the failing test**

`GrammarScanner::savePatterns()` writes **no row** for a sentence with zero matches (`lib/GrammarScanner.php:78: if (!$this->db || empty($matches)) return 0;`), so coverage cannot be asserted as "every sentence has a pattern row." The correct invariant is **consistency**: a sentence has exactly the pattern rows its text produces under in-memory `scanSentence()` — no more, no fewer. The test computes the expected state independently and compares.

Append to `PostgresSourcePipelineTest`:

```php
public function testProcessSourcePatternStateMatchesRescan(): void
{
    $this->requirePg();
    // Pick any sourceid that exists in MySQL with sentences (dev DBs are seeded).
    $sid = (int)($GLOBALS['__TEST_SOURCE_ID'] ?? 0);
    if (!$sid) { $this->markTestSkipped('Set __TEST_SOURCE_ID to a MySQL sourceid with sentences'); }

    $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline();
    $out = $pipeline->processSource($sid);
    $this->assertGreaterThanOrEqual(0, $out['patterns']);

    $scanner = new \Noiiolelo\GrammarScanner();          // in-memory only: no db -> savePatterns unused
    $pdo = $pipeline->pg();

    $stmt = $pdo->prepare(
        'SELECT sentenceid, hawaiiantext FROM laana.sentences
         WHERE sourceid = :sid AND hawaiiantext IS NOT NULL AND hawaiiantext <> \'\'
         ORDER BY sentenceid'
    );
    $stmt->execute([':sid' => $sid]);
    $del = $pdo->prepare('SELECT pattern_type FROM laana.sentence_patterns WHERE sentenceid = :sid2 ORDER BY pattern_type');

    $checked = 0;
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $expected = [];
        foreach ($scanner->scanSentence($row['hawaiiantext']) as $m) {
            $expected[] = $m['pattern_type'];
        }
        $expected = array_values(array_unique($expected));
        sort($expected);

        $del->execute([':sid2' => $row['sentenceid']]);
        $actual = $del->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame($expected, $actual,
            "pattern rows diverge from rescan for sentenceid {$row['sentenceid']}");
        $checked++;
    }
    $this->assertGreaterThan(0, $checked, 'test source should have sentences');
}
```

Separately assert counts-view freshness — call the refresh explicitly so the test is deterministic (the pipeline itself never refreshes mid-run; see Step 3):

```php
public function testRefreshGrammarPatternCountsSyncsView(): void
{
    $this->requirePg();
    $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline();
    $this->assertTrue($pipeline->refreshGrammarPatternCounts());

    $pdo = $pipeline->pg();
    $table = $pdo->query(
        'SELECT pattern_type, count(*) FROM laana.sentence_patterns GROUP BY pattern_type'
    )->fetchAll(\PDO::FETCH_KEY_PAIR);
    $view = $pdo->query(
        'SELECT pattern_type, total_count FROM laana.grammar_pattern_counts'
    )->fetchAll(\PDO::FETCH_KEY_PAIR);
    foreach ($table as $type => $count) {
        $this->assertSame((int)$count, (int)($view[$type] ?? 0), "counts view stale for {$type}");
    }
}
```

Add a small `public function pg(): \PDO { return $this->pg; }` accessor to the pipeline for tests.

**Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Database/PostgresSourcePipelineTest.php`
Expected: FAIL — pattern rows are absent (scan not yet called in `processSource`), so the rescan comparison finds rows missing for matched sentences.

**Step 3: Implement**

In `processSource()`, after the document-vector step and **before** `$this->pg->commit()` (line ~614 in the moved code):

```php
// --- 4. Grammar patterns (delta scan; rows visible in this tx) ---
$out['patterns'] = $this->scanGrammarPatterns($sid);
```

`scanGrammarPatterns()` is already in place from Task 1 (`GrammarScanner::updateSourcePatterns($sid, false)` — delta: only sentences with no pattern rows).

**Counts-refresh policy (single, enforced):** `processSource()` and `scanGrammarPatterns()` NEVER refresh the materialized view — `REFRESH MATERIALIZED VIEW CONCURRENTLY` cannot run inside a transaction and per-source refreshes hammer it. Refresh happens exactly once per run, at the run driver, outside any transaction:

- `pg_import.php`: in the summary section (after the `foreach`, ~line 640), add unconditionally (this also completes Task 3):

```php
if (!$dryrun && $pipeline->refreshGrammarPatternCounts()) {
    say("grammar_pattern_counts refreshed.\n", $quiet);
}
```

And delete the `--force`-branch refresh at `scripts/pg_import.php:338-342` (now redundant).

- `PostgresSaveManager` (Task 5): refreshes once per run via overridden `getAllDocuments()` / `processOneSource()`, not in `saveContents()`.

**Step 4: Run tests**

Run: `php vendor/bin/phpunit tests/Database/PostgresSourcePipelineTest.php`
Expected: PASS.

**Step 5: Commit**

```bash
git add providers/Postgres/PostgresSourcePipeline.php scripts/pg_import.php tests/Database/PostgresSourcePipelineTest.php
git commit -m "feat: grammar pattern scan + counts refresh in Postgres per-source pipeline"
```

---

### Task 3: `ElasticsearchSaveManager::createClient()` factory extraction (pure refactor)

**Files:**
- Modify: `providers/Elasticsearch/ElasticsearchSaveManager.php:42-51`

**Step 1: Extract**

Replace the hardcoded construction in the constructor:

```php
// Initialize Elasticsearch client
$this->client = new ElasticsearchClient([
    'verbose' => $options['verbose'] ?? false,
    'SPLIT_INDICES' => true
]);
```

with:

```php
$this->client = $this->createClient($options);
```

and add:

```php
/**
 * Client factory — overridden by OpenSearchSaveManager to target OpenSearch.
 */
protected function createClient(array $options): ElasticsearchClient
{
    return new ElasticsearchClient([
        'verbose' => $options['verbose'] ?? false,
        'SPLIT_INDICES' => true,
    ]);
}
```

**Step 2: Verify no behavior change**

Run: `php vendor/bin/phpunit tests/Indexing/`
Expected: existing tests pass (env-gated ones skip without ES_HOST).

**Step 3: Commit**

```bash
git add providers/Elasticsearch/ElasticsearchSaveManager.php
git commit -m "refactor: extract createClient factory in ElasticsearchSaveManager"
```

---

### Task 4: `OpenSearchSaveManager`

**Files:**
- Create: `providers/OpenSearch/OpenSearchSaveManager.php`
- Test: `tests/Provider/OpenSearchSaveManagerTest.php`

**Step 1: Write the failing test**

```php
<?php
namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

class OpenSearchSaveManagerTest extends BaseTestCase
{
    public function testUsesOpenSearchClient(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        $this->assertInstanceOf(\HawaiianSearch\OpenSearchClient::class, $mgr->getClient());
        $this->assertInstanceOf(\HawaiianSearch\ElasticsearchClient::class, $mgr->getClient());
    }
}
```

**Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Provider/OpenSearchSaveManagerTest.php`
Expected: FAIL — class not found.

**Step 3: Implement**

`providers/OpenSearch/OpenSearchSaveManager.php`:

```php
<?php
namespace Noiiolelo\Providers\OpenSearch;

require_once __DIR__ . '/../Elasticsearch/ElasticsearchSaveManager.php';
require_once __DIR__ . '/src/OpenSearchClient.php';

use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;
use HawaiianSearch\OpenSearchClient;

/**
 * OpenSearch ingestion: identical to the Elasticsearch save manager except
 * the client targets OpenSearch (OS_* env). Grammar patterns are computed
 * at index time by the shared ElasticsearchClient code, and pattern counts
 * come from a live terms aggregation — no extra bookkeeping here.
 */
class OpenSearchSaveManager extends ElasticsearchSaveManager
{
    protected function createClient(array $options): \HawaiianSearch\ElasticsearchClient
    {
        return new OpenSearchClient([
            'verbose' => $options['verbose'] ?? false,
            'SPLIT_INDICES' => true,
        ]);
    }
}
```

(If the parent's return type on `createClient` conflicts with `getClient()`'s doc type, drop the return type here — but prefer keeping it on both.)

**Step 4: Run test**

Run: `php vendor/bin/phpunit tests/Provider/OpenSearchSaveManagerTest.php`
Expected: PASS (or SKIP without OS_HOST — in that case also smoke-check `php -l`).

**Step 5: Commit**

```bash
git add providers/OpenSearch/OpenSearchSaveManager.php tests/Provider/OpenSearchSaveManagerTest.php
git commit -m "feat: OpenSearch save manager (reuses Elasticsearch save flow)"
```

---

### Task 5: `PostgresSaveManager`

**Files:**
- Create: `providers/Postgres/PostgresSaveManager.php`
- Test: `tests/Provider/PostgresSaveManagerTest.php`

**Step 1: Write the failing test**

```php
<?php
namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

class PostgresSaveManagerTest extends BaseTestCase
{
    private function requireDbs(): void
    {
        if (!getenv('DB_HOST') || !getenv('PG_HOST') || !getenv('PG_DATABASE')) {
            $this->markTestSkipped('DB_HOST, PG_HOST and PG_DATABASE must be set for PostgresSaveManager tests');
        }
    }

    public function testIsAMySqlSaveManager(): void
    {
        $this->requireDbs();
        $mgr = new \Noiiolelo\Providers\Postgres\PostgresSaveManager(['verbose' => false]);
        $this->assertInstanceOf(\Noiiolelo\Providers\MySQL\MySQLSaveManager::class, $mgr);
        $this->assertSame(0, $mgr->getPatternsSaved());
    }

    public function testProcessOneSourceMirrorsToPostgresWithPatterns(): void
    {
        $this->requireDbs();
        $sid = (int)($GLOBALS['__TEST_SOURCE_ID'] ?? 0);
        if (!$sid) { $this->markTestSkipped('Set __TEST_SOURCE_ID to a MySQL sourceid with sentences'); }

        // Uses the public API: scrapes into MySQL (as MySQLSaveManager does),
        // then mirrors to Postgres and scans patterns.
        $mgr = new \Noiiolelo\Providers\Postgres\PostgresSaveManager([
            'force' => true, 'verbose' => false,
        ]);
        $mgr->processOneSource($sid);

        $pdo = (new \PostgresLaana())->conn;
        $sentences = (int)$pdo->query(
            "SELECT count(*) FROM laana.sentences WHERE sourceid = {$sid}"
        )->fetchColumn();
        $this->assertGreaterThan(0, $sentences);

        // Consistency, not coverage: rows must match an in-memory rescan
        // (zero-match sentences legitimately have no rows).
        $scanner = new \Noiiolelo\GrammarScanner();
        $stmt = $pdo->prepare(
            'SELECT sentenceid, hawaiiantext FROM laana.sentences
             WHERE sourceid = :sid AND hawaiiantext IS NOT NULL AND hawaiiantext <> \'\'
             ORDER BY sentenceid LIMIT 25'
        );
        $stmt->execute([':sid' => $sid]);
        $del = $pdo->prepare('SELECT pattern_type FROM laana.sentence_patterns WHERE sentenceid = :sid2 ORDER BY pattern_type');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $expected = [];
            foreach ($scanner->scanSentence($row['hawaiiantext']) as $m) { $expected[] = $m['pattern_type']; }
            $expected = array_values(array_unique($expected)); sort($expected);
            $del->execute([':sid2' => $row['sentenceid']]);
            $this->assertSame($expected, $del->fetchAll(\PDO::FETCH_COLUMN),
                "divergence for sentenceid {$row['sentenceid']}");
        }
    }
}
```

(If the test source's URL is unreachable in the test environment, note that `processOneSource` may fetch live content — pick a stable local source or accept the network dependency explicitly; the env gates keep this out of CI without services.)

**Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Provider/PostgresSaveManagerTest.php`
Expected: FAIL — class not found.

**Step 3: Widen the parent options visibility**

`MySQLSaveManager::$options` is `private` (`providers/MySQL/MySQLSaveManager.php:21`), so a subclass cannot read it. Change that one declaration:

```php
private $options = [];
```
to
```php
protected $options = [];
```

No behavior change; it lets `PostgresSaveManager` (and future save managers) honor `force`/`resplit`. (Alternative: capture options in a child constructor before `parent::__construct()` — only if touching the parent is unacceptable.)

**Step 4: Implement**

`providers/Postgres/PostgresSaveManager.php`:

```php
<?php
namespace Noiiolelo\Providers\Postgres;

require_once __DIR__ . '/../MySQL/MySQLSaveManager.php';
require_once __DIR__ . '/PostgresSourcePipeline.php';

use Noiiolelo\Providers\MySQL\MySQLSaveManager;

/**
 * Daily Postgres ingestion. Scrapes exactly like MySQLSaveManager (MySQL
 * stays the catalog of record: sourceIDs and sentenceIDs are allocated
 * there, keeping this path ID-identical to scripts/pg_import.php bootstrap),
 * then mirrors the saved source into Postgres — data, vectors, metrics,
 * grammar patterns — via PostgresSourcePipeline.
 *
 * Counts policy: refreshGrammarPatternCounts() runs exactly ONCE per run,
 * after the run loop (never per source, never inside a transaction).
 */
class PostgresSaveManager extends MySQLSaveManager
{
    protected $logName = "PostgresSaveManager";
    private ?PostgresSourcePipeline $pipeline = null;
    private int $patternsSaved = 0;
    private bool $mirroredAnything = false;

    private function pipeline(): PostgresSourcePipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = new PostgresSourcePipeline([
                'dryrun'    => false,
                'force'     => (bool)($this->options['force'] ?? false),
                'sentences' => true,
                'documents' => true,
            ]);
        }
        return $this->pipeline;
    }

    public function saveContents($parser, $source)
    {
        $count = parent::saveContents($parser, $source);
        $sourceID = (int)($source['sourceid'] ?? 0);

        // ALWAYS mirror every selected source. Do NOT gate on $count > 0:
        // in the daily driver (updatenoiiolelo.sh) the mysql pass runs first,
        // so this pass typically sees sentences already present and parent
        // saveContents() returns 0 — gating on the return value would skip
        // the mirror exactly when it matters. The pipeline is delta-safe
        // (ON CONFLICT upserts; embeddings only for missing vectors; grammar
        // scan only for patternless sentences), so an already-mirrored source
        // costs one small read-only-in-practice transaction.
        // NOTE: no counts refresh here — it happens once per run (below).
        if ($sourceID > 0) {
            try {
                $out = $this->pipeline()->processSource($sourceID);
                $this->patternsSaved += $out['patterns'];
                $this->mirroredAnything = true;
                $this->log("PG mirror sourceID {$sourceID}: {$out['sentences_data']} sentences, "
                    . "{$out['patterns']} patterns");
            } catch (\Throwable $e) {
                // MySQL save already succeeded; PG sync failure must not
                // abort the batch — report and continue.
                $this->log("PG mirror failed for sourceID {$sourceID}: " . $e->getMessage());
                \Avisitor\Monolog\Logger::logError("PostgresSaveManager PG mirror: " . $e->getMessage());
            }
        }
        return $count;
    }

    /** Once-per-run counts refresh, outside any transaction.
     *  Consumption guard: getAllDocuments() delegates to processOneSource()
     *  for single-source runs (MySQLSaveManager.php:598-601), and both are
     *  overridden — clearing the flag here makes the refresh idempotent
     *  under that nesting. */
    private function refreshCountsOnce(): void
    {
        if (!$this->mirroredAnything) { return; }
        $this->mirroredAnything = false;
        if ($this->pipeline()->refreshGrammarPatternCounts()) {
            $this->log("grammar_pattern_counts refreshed");
        } else {
            \Avisitor\Monolog\Logger::logError("PostgresSaveManager: grammar_pattern_counts refresh failed");
        }
    }

    public function getAllDocuments()
    {
        $summary = parent::getAllDocuments();
        $this->refreshCountsOnce();
        return $summary;
    }

    public function processOneSource($sourceid)
    {
        $summary = parent::processOneSource($sourceid);
        $this->refreshCountsOnce();
        return $summary;
    }

    /** Postgres patterns assigned during this run (for buildSummary). */
    public function getPatternsSaved(): int
    {
        return $this->patternsSaved;
    }
}
```

Notes:
- `parent::saveContents()` already writes MySQL grammar patterns (`MySQLSaveManager.php:190-193`) — untouched.
- The pipeline is delta-safe, so calling it per source is cheap when nothing changed.
- Do **not** override `addSentences` — the PG mirror is per-source, not per-sentence.
- If `getAllDocuments`/`processOneSource` signatures in `MySQLSaveManager` differ from the above (e.g., return types), match the parent signatures exactly.

**Step 4: Run test**

Run: `php vendor/bin/phpunit tests/Provider/PostgresSaveManagerTest.php`
Expected: PASS (or SKIP without DB env).

**Step 5: Commit**

```bash
git add providers/Postgres/PostgresSaveManager.php tests/Provider/PostgresSaveManagerTest.php
git commit -m "feat: PostgresSaveManager — daily ingestion mirror with grammar patterns"
```

---

### Task 6: `save.php` dispatch + `SaveManagerFactory`

**Files:**
- Create: `lib/SaveManagerFactory.php`
- Modify: `scripts/save.php:85-92`
- Test: `tests/Cli/SaveManagerFactoryTest.php`

**Step 1: Write the failing test**

```php
<?php
namespace Noiiolelo\Tests\Cli;

use PHPUnit\Framework\TestCase;

class SaveManagerFactoryTest extends TestCase
{
    public function testUnknownProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Noiiolelo\SaveManagerFactory::create('bogus', []);
    }

    public function testProviderNameNormalization(): void
    {
        $this->assertSame(
            'elasticsearch',
            \Noiiolelo\SaveManagerFactory::normalize('ES')
        );
        $this->assertSame(
            'opensearch',
            \Noiiolelo\SaveManagerFactory::normalize('os')
        );
        $this->assertSame(
            'postgres',
            \Noiiolelo\SaveManagerFactory::normalize('Postgres')
        );
    }
}
```

**Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Cli/SaveManagerFactoryTest.php`
Expected: FAIL — class not found.

**Step 3: Implement the factory**

`lib/SaveManagerFactory.php`:

```php
<?php
namespace Noiiolelo;

require_once __DIR__ . '/../providers/MySQL/MySQLSaveManager.php';
require_once __DIR__ . '/../providers/Elasticsearch/ElasticsearchSaveManager.php';
require_once __DIR__ . '/../providers/Postgres/PostgresSaveManager.php';
require_once __DIR__ . '/../providers/OpenSearch/OpenSearchSaveManager.php';

use Noiiolelo\Providers\MySQL\MySQLSaveManager;
use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;
use Noiiolelo\Providers\Postgres\PostgresSaveManager;
use Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager;

class SaveManagerFactory
{
    public static function normalize(string $provider): string
    {
        $p = strtolower(trim($provider));
        if ($p === 'es') { return 'elasticsearch'; }
        if ($p === 'os') { return 'opensearch'; }
        return $p;
    }

    /** Supported save backends, for help text and validation. */
    public static function supported(): array
    {
        return ['mysql', 'postgres', 'elasticsearch', 'opensearch'];
    }

    /**
     * @param string $provider raw --provider value ('' -> mysql default)
     * @throws \InvalidArgumentException on unsupported providers — callers
     *         must NOT silently fall back to MySQL.
     */
    public static function create(string $provider, array $options): object
    {
        $normalized = self::normalize($provider ?: 'mysql');
        switch ($normalized) {
            case 'mysql':         return new MySQLSaveManager($options);
            case 'postgres':      return new PostgresSaveManager($options);
            case 'elasticsearch': return new ElasticsearchSaveManager($options);
            case 'opensearch':    return new OpenSearchSaveManager($options);
            default:
                throw new \InvalidArgumentException(
                    "Unsupported save provider '{$provider}'. Supported: "
                    . implode(', ', self::supported())
                );
        }
    }
}
```

**Step 4: Update `scripts/save.php`**

Replace lines 85-92 (`if ($providerName === 'elasticsearch' ...) { ... } else { ... }`) with:

```php
require_once __DIR__ . '/../lib/SaveManagerFactory.php';

try {
    $manager = \Noiiolelo\SaveManagerFactory::create($providerName, $managerOptions);
    echo "Using " . \Noiiolelo\SaveManagerFactory::normalize($providerName ?: 'mysql') . " provider\n";
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
```

Merge into the surrounding `try` as appropriate — keep `--doclist-save` handling and the `Summary:` JSON output (`save.php:128-146`) unchanged; the summary already carries the provider label via `$providerLabel`.

**Step 5: Update `scripts/savedocument.php`? No.** It constructs `SaveManager` (MySQL) directly — that stays the single-source MySQL tool. Only `save.php` dispatches.

**Step 6: Run tests**

Run: `php vendor/bin/phpunit tests/Cli/SaveManagerFactoryTest.php`
Expected: PASS.

**Step 7: Commit**

```bash
git add lib/SaveManagerFactory.php scripts/save.php tests/Cli/SaveManagerFactoryTest.php
git commit -m "feat: explicit save.php dispatch for postgres/opensearch via SaveManagerFactory"
```

---

### Task 7: Verify the daily driver wiring

**Files:**
- Verify (likely no change): `scripts/updatenoiiolelo.sh`

**Step 1: Walk the driver logic**

`updatenoiiolelo.sh` already:
- loops `PROVIDERS="mysql postgres elasticsearch opensearch"` calling `save.php --provider=$provider` (lines 33-37) — now each is a real backend;
- runs `updateGrammarPatterns()` for `GRAMMAR_PROVIDERS="mysql postgres"` (lines 41-47) — keep it: it is the delta backstop for both SQL providers (e.g., sentences saved by `addsentences.php` or older runs) and refreshes the MySQL counts proc + PG matview;
- `summarize()` greps `Using provider: Postgres` (line 53) — confirm the populate script still prints that (`scripts/populate_grammar_patterns.php:24` prints `Using provider: <getName()>`; `PostgresProvider::getName()` must return `Postgres` — verify with `php -r` and adjust the grep only if needed).

**Step 2: Smoke the new dispatch through the driver's exact commands**

```bash
php scripts/save.php --provider=postgres --parser=keaolama --sourceid=<TEST_ID> --verbose
php scripts/save.php --provider=opensearch --parser=keaolama --sourceid=<TEST_ID> --verbose
```
Expected: first line `Using postgres provider` / `Using opensearch provider`; summaries printed; no fallback to MySQL for the OS run (verify by checking OS docs exist via `ops/` endpoints or curl to OS `_search`).

**Step 3: Commit (only if the script needed edits)**

```bash
git add scripts/updatenoiiolelo.sh
git commit -m "chore: align updatenoiiolelo.sh comments with real save.php dispatch"
```

---

### Task 8: Documentation

**Files:**
- Modify: `docs/INGESTION.md`
- Modify: `docs/GRAMMAR_PATTERNS.md`

**Step 1: INGESTION.md**

- Pipeline map (lines 7-27): add web → Postgres and web → OpenSearch arrows via `save.php --provider=postgres|opensearch`.
- `save.php` option table (line 48): replace "Any other value silently falls back to MySQL" with the supported list and loud failure; note `--provider=postgres` mirrors through MySQL for ID parity and updates `sentence_patterns` + `grammar_pattern_counts` automatically.
- Grammar section (lines 233-257): note the automation — MySQL at save time, ES/OS at index time, PG at save time via `PostgresSaveManager`/`pg_import` — and that `populate_grammar_patterns.php` remains the delta backstop.
- `pg_import.php` section: note per-source grammar scan and the unconditional end-of-run counts refresh.

**Step 2: GRAMMAR_PATTERNS.md**

Add a "Keeping counts current" subsection: per-provider table (MySQL: save-time + hourly event; PG: save-time scan + once-per-run CONCURRENTLY refresh; ES/OS: index-time + live aggregation) and the cron/backstop story.

**Step 3: Verify the docs changes**

```bash
git diff --check                                        # no whitespace errors
grep -o '](\([^)]*\.md\))' docs/INGESTION.md docs/GRAMMAR_PATTERNS.md \
  | sed 's/.*](\(.*\))/\1/' | sort -u \
  | while read -r f; do d=$(dirname "${f%%#*}"); test -e "docs/$f" || test -e "$f" || echo "BROKEN LINK: $f"; done
# Expect: no output (all relative .md links resolve)
grep -c "postgres" docs/INGESTION.md   # >= 4 (map, option table, grammar section, pg_import section)
! grep -qi "silently falls back" docs/INGESTION.md   # fallback language removed
grep -q "OpenSearchSaveManager" docs/INGESTION.md && grep -q "PostgresSaveManager" docs/INGESTION.md
```

Expected: `git diff --check` silent; no `BROKEN LINK` lines; the two `grep -q` checks succeed.

**Step 4: Commit**

```bash
git add docs/INGESTION.md docs/GRAMMAR_PATTERNS.md
git commit -m "docs: save.php provider parity and automatic grammar pattern updates"
```

---

### Task 9: End-to-end verification

**Step 1: Full test suite**

Run: `bash tests/run-tests.sh`
Expected: no new failures (env-gated tests skip where services are absent). Fix anything your changes broke; name pre-existing unrelated failures without widening scope.

**Step 2: One-source matrix against live services (where reachable)**

For a single test sourceID with known sentences:

| Check | Command / method | Expected |
|---|---|---|
| MySQL patterns | `SELECT count(*) FROM sentence_patterns WHERE sentenceid IN (SELECT sentenceID FROM sentences WHERE sourceID=ID)` | > 0 after `--provider=mysql` |
| PG sentences + patterns | **Rescan-consistency** (as in Task 2/Task 5 tests): for each sentence, in-memory `GrammarScanner::scanSentence()` result must equal its DB pattern rows — zero-match sentences legitimately have no rows | identical for every sampled sentence |
| PG counts view | counts-view vs `GROUP BY` comparison (as in Task 2 test) | identical |
| ES grammar field | `curl $ES_HOST/_search` on sentences index, `_source: grammar_patterns` | non-empty array for matching sentences |
| OS grammar field | same against OS | same |
| ES/OS counts | `ops/getGrammarPatterns.php?provider=Elasticsearch` / `OpenSearch` | buckets present |
| PG counts endpoint | `ops/getGrammarPatterns.php?provider=Postgres` | rows match view |

**Step 3: Driver rehearsal**

Run `php scripts/save.php --provider=postgres --parser=keaolama --maxrows=2 --verbose` then the same for opensearch; eyeball `/tmp`-style output for per-source `PG mirror` log lines and correct provider labels in summaries.

**Step 4: Final commit / tag**

```bash
git status   # confirm clean or only intended files
```

---

## Risks and known limitations

- **Delta-scan non-convergence (pre-existing, documented):** sentences with zero pattern matches never get a `sentence_patterns` row (`GrammarScanner.php:78` returns early on empty matches), so the `NOT EXISTS` delta query re-selects them on every run. This is already true of `populate_grammar_patterns.php --provider=postgres` (`updateAllPatternsDelta`) and `findpatterns.py`; this plan does not worsen it — per-source scans (`updateSourcePatterns`) bound the re-scan to one source's sentences. A future fix would add a scanned-state marker (e.g., a `laana.grammar_scan_log(sentenceid)` table) — deliberately out of scope here (schema change, affects all three SQL tools).
- **Resplit drift (PG):** `pg_import` and the pipeline upsert but never delete PG sentences absent from MySQL after a `--resplit` shrinks a sentence set. Pre-existing behavior; unchanged here. A follow-up "delete PG sentences not in MySQL for mirrored source" task could address it.
- **`REFRESH ... CONCURRENTLY` cost:** refresh runs exactly once per run (end of `pg_import` run; end of `PostgresSaveManager::getAllDocuments`/`processOneSource`). If the view grows, the `CONCURRENTLY` refresh is the only cost and can be moved to cron — the policy is one refresh point per run, so relocation is trivial.
- **`MySQLSaveManager` method visibility:** `saveContents` is public (safe override point). If the PG manager needs other internals (e.g., `addSentences`), widen `private` → `protected` in the parent rather than duplicating logic.
- **Double scrape in the daily driver:** with all four providers active, the same parser's documents are fetched once per provider save run. MySQL-cataloged runs skip already-saved docs, so the extra `postgres` run after `mysql` mostly no-ops on MySQL and syncs PG — that is the desired behavior, just not free. `PROVIDERS` ordering in `updatenoiiolelo.sh` (mysql before postgres) already keeps the MySQL work minimal.
- **Always-mirror cost:** `PostgresSaveManager` mirrors every selected source each run (no count gate — see Task 5). For already-mirrored sources this is one small per-source transaction of `ON CONFLICT` no-op upserts plus delta queries; at doclist scale (hundreds of sources) this adds seconds-to-minutes per provider pass. If profiling shows it matters, add a cheap pre-check (PG sentence count for the source == MySQL count AND patterns complete) before invoking `processSource` — but only with evidence.

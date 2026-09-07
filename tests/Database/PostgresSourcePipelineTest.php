<?php
namespace Noiiolelo\Tests\Database;

use Noiiolelo\Tests\BaseTestCase;

class PostgresSourcePipelineTest extends BaseTestCase
{
    private string $mySqlConnectError = '';

    private function requirePg(): void
    {
        // The pipeline reads source rows from MySQL as well, so both DBs must
        // be configured — otherwise the constructor fails instead of skipping.
        if (!getenv('PG_HOST') || !getenv('PG_DATABASE') || !getenv('DB_HOST') || !getenv('DB_DATABASE')) {
            $this->markTestSkipped('PG_HOST, PG_DATABASE, DB_HOST and DB_DATABASE must be set for PostgresSourcePipeline tests');
        }
        $this->skipIfProviderUnavailable('Postgres');
        $this->skipIfProviderUnavailable('MySQL');
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

    /**
     * Pick a deterministic sourceid for integration runs: TEST_SOURCE_ID env
     * wins, then $GLOBALS['__TEST_SOURCE_ID'], then the newest sourceid that
     * exists in BOTH Postgres and MySQL (processSource mirrors MySQL -> PG,
     * so the source must be real on the MySQL side). Returns 0 when nothing
     * usable is found (the caller skips).
     */
    private function resolveTestSourceId(): int
    {
        $env = getenv('TEST_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (int)$env;
        }
        if (isset($GLOBALS['__TEST_SOURCE_ID']) && (int)$GLOBALS['__TEST_SOURCE_ID'] > 0) {
            return (int)$GLOBALS['__TEST_SOURCE_ID'];
        }

        require_once __DIR__ . '/../../db/PostgresFuncs.php';
        $pgLaana = new \PostgresLaana();
        if (!$pgLaana->conn) {
            return 0;
        }
        $pgIds = $pgLaana->conn->query(
            'SELECT DISTINCT sourceid FROM laana.sentences ORDER BY sourceid DESC LIMIT 5'
        )->fetchAll(\PDO::FETCH_COLUMN);

        $mysql = self::connectTestMySql();
        if ($mysql === null) {
            return 0;
        }
        $exists = $mysql->prepare('SELECT COUNT(*) FROM sentences WHERE sourceID = :sid');
        foreach ($pgIds as $sid) {
            $exists->execute([':sid' => (int)$sid]);
            if ((int)$exists->fetchColumn() > 0) {
                return (int)$sid;
            }
        }
        return 0;
    }

    private static function connectTestMySql(): ?\PDO
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = (string)getenv('DB_DATABASE');
        $user = (string)getenv('DB_USER');
        $pass = (string)getenv('DB_PASSWORD');
        if ($db === '') {
            return null;
        }
        try {
            return new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->mySqlConnectError = $e->getMessage();
            return null;
        }
    }

    public function testProcessSourcePatternStateMatchesRescan(): void
    {
        $this->requirePg();
        $sid = $this->resolveTestSourceId();
        if (!$sid) {
            $this->markTestSkipped($this->mySqlConnectError !== ''
                ? "MySQL connection failed: {$this->mySqlConnectError}"
                : 'No MySQL+Postgres source with sentences found; set TEST_SOURCE_ID to override');
        }

        // processSource() computes real embeddings when work is pending, so the
        // embedding service must be reachable for this test to run.
        if ((new \Noiiolelo\EmbeddingClient())->embedText('ping') === null) {
            $this->markTestSkipped('embedding service unavailable');
        }

        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline();
        $pdo = $pipeline->pg();

        // The delta scan only writes rows for sentences that have none, so a
        // seeded corpus would make this test vacuous. Clear the source's rows
        // to re-establish the unscanned state; the scan under test recreates
        // them (a populate_grammar_patterns run would too).
        // Assumes a disposable dev DB: until the scan below recreates the rows,
        // pattern searches over this source come up empty for a few seconds.
        $pdo->exec(
            "DELETE FROM laana.sentence_patterns WHERE sentenceid IN "
            . "(SELECT sentenceid FROM laana.sentences WHERE sourceid = {$sid})"
        );

        $out = $pipeline->processSource($sid);

        // Expected state comes from an in-memory scan ONLY (no db passed to
        // the constructor, so savePatterns can never write from here).
        $scanner = new \Noiiolelo\GrammarScanner();

        $stmt = $pdo->prepare(
            'SELECT sentenceid, hawaiiantext FROM laana.sentences
             WHERE sourceid = :sid AND hawaiiantext IS NOT NULL AND hawaiiantext <> \'\'
             ORDER BY sentenceid'
        );
        $stmt->execute([':sid' => $sid]);
        $del = $pdo->prepare('SELECT pattern_type FROM laana.sentence_patterns WHERE sentenceid = :sid2 ORDER BY pattern_type');

        $checked = 0;
        $withMatches = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $expected = [];
            foreach ($scanner->scanSentence($row['hawaiiantext']) as $m) {
                $expected[] = $m['pattern_type'];
            }
            $expected = array_values(array_unique($expected));
            sort($expected);
            if ($expected) {
                $withMatches++;
            }

            $del->execute([':sid2' => $row['sentenceid']]);
            $actual = $del->fetchAll(\PDO::FETCH_COLUMN);

            $this->assertSame($expected, $actual,
                "pattern rows diverge from rescan for sentenceid {$row['sentenceid']}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'test source should have sentences');
        $this->assertGreaterThan(0, $withMatches,
            'test source has no pattern matches; pick another TEST_SOURCE_ID or the scan is untestable');
        // Post-DELETE every examined sentence was delta-selected, so the
        // counter must have observed at least the manufactured delta.
        $this->assertGreaterThanOrEqual($checked, $out['patterns'],
            "patterns counter {$out['patterns']} did not observe the delta (>= {$checked} sentences selected)");
    }

    /**
     * Pick the smallest source (by MySQL sentence count) that exists in both
     * databases. Force-mode tests re-embed every sentence of the source, so
     * the smallest keeps the test fast and the embedding request small.
     * Returns 0 when nothing usable is found (the caller skips).
     */
    private function resolveSmallestTestSourceId(): int
    {
        $env = getenv('TEST_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (int)$env;
        }

        require_once __DIR__ . '/../../db/PostgresFuncs.php';
        $pgLaana = new \PostgresLaana();
        if (!$pgLaana->conn) {
            return 0;
        }
        $pgIds = array_flip($pgLaana->conn->query(
            'SELECT DISTINCT sourceid FROM laana.sentences'
        )->fetchAll(\PDO::FETCH_COLUMN));

        $mysql = self::connectTestMySql();
        if ($mysql === null) {
            return 0;
        }
        foreach ($mysql->query(
            'SELECT sourceID, COUNT(*) AS n FROM sentences GROUP BY sourceID ORDER BY n ASC, sourceID ASC LIMIT 10'
        ) as $row) {
            if (isset($pgIds[(int)$row['sourceID']])) {
                return (int)$row['sourceID'];
            }
        }
        return 0;
    }

    /**
     * Force mode must re-embed every sentence of the source through the
     * batched write path (staged vectors + ONE update) and rewrite every
     * metrics row through the batched upsert — with the data intact
     * afterwards. Uses the smallest available source to stay fast; the
     * rewritten values are recomputed deterministically (same model, same
     * PHP metrics), so the corpus state is unchanged by this test.
     */
    public function testProcessSourceForceRewritesEmbeddingsAndMetrics(): void
    {
        $this->requirePg();
        $sid = $this->resolveSmallestTestSourceId();
        if (!$sid) {
            $this->markTestSkipped($this->mySqlConnectError !== ''
                ? "MySQL connection failed: {$this->mySqlConnectError}"
                : 'No MySQL+Postgres source with sentences found; set TEST_SOURCE_ID to override');
        }
        if ((new \Noiiolelo\EmbeddingClient())->embedText('ping') === null) {
            $this->markTestSkipped('embedding service unavailable');
        }

        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline(['force' => true]);
        $pdo = $pipeline->pg();

        $count = static function (string $sql) use ($pdo, $sid): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid' => $sid]);
            return (int)$stmt->fetchColumn();
        };
        $expected = $count(
            'SELECT COUNT(*) FROM laana.sentences WHERE sourceid = :sid '
            . "AND hawaiiantext IS NOT NULL AND hawaiiantext <> ''"
        );
        if ($expected === 0) {
            $this->markTestSkipped("source {$sid} has no non-empty sentences");
        }

        $out = $pipeline->processSource($sid);

        $this->assertSame($expected, $out['sentences_data'], 'all sentences must be mirrored');
        $this->assertSame($expected, $out['sentence_vectors'], 'force re-embeds every non-empty sentence');
        $this->assertSame($expected, $out['sentence_metrics'], 'force rewrites every metrics row');
        $this->assertSame(
            $expected,
            $count('SELECT COUNT(*) FROM laana.sentences WHERE sourceid = :sid AND embedding IS NOT NULL'),
            'the single batched update must have written an embedding for every work sentence'
        );
        $this->assertSame(
            $expected,
            $count('SELECT COUNT(*) FROM laana.sentence_metrics m JOIN laana.sentences s ON s.sentenceid = m.sentenceid WHERE s.sourceid = :sid'),
            'the batched metrics upsert must cover every work sentence'
        );
    }

    /**
     * Incremental mode must not re-upsert data for a source whose sentence
     * signature already matches on both sides: sentences_data stays 0 (the
     * per-sentence upsert loop never ran) while the grammar delta still runs.
     * This is what keeps backfill and cron passes over a built corpus from
     * rewriting every already-mirrored row.
     */
    public function testProcessSourceSkipsDataUpsertsForAlreadyMirroredSource(): void
    {
        $this->requirePg();
        $sid = $this->resolveAlreadyMirroredSourceId();
        if (!$sid) {
            $this->markTestSkipped($this->mySqlConnectError !== ''
                ? "MySQL connection failed: {$this->mySqlConnectError}"
                : 'No MySQL+Postgres source with matching sentence signatures and a contents row found; set TEST_SOURCE_ID to override');
        }

        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline();
        $out = $pipeline->processSource($sid);

        $this->assertSame(0, $out['sentences_data'],
            "already-mirrored source {$sid} must skip the data upserts");
        $this->assertTrue($out['skipped_data']);
        $this->assertTrue($out['has_content']);
        $this->assertSame(0, $out['sentence_vectors'],
            'a mirrored source has no sentence gaps to fill');
        $this->assertArrayHasKey('patterns', $out);
    }

    /**
     * A source the skip fast-path can actually fire on: per-source sentence
     * signatures (count, checksum) identical on both sides plus a Postgres
     * contents row. resolveTestSourceId() deliberately picks the newest
     * sources, which are the actively-ingested ones — a re-scrape that
     * shrinks a source in MySQL leaves stale sentences in Postgres, the
     * signatures diverge permanently, and the skip can never fire for them.
     */
    private function resolveAlreadyMirroredSourceId(): int
    {
        $env = getenv('TEST_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (int)$env;
        }
        if (isset($GLOBALS['__TEST_SOURCE_ID']) && (int)$GLOBALS['__TEST_SOURCE_ID'] > 0) {
            return (int)$GLOBALS['__TEST_SOURCE_ID'];
        }

        require_once __DIR__ . '/../../db/PostgresFuncs.php';
        $pgLaana = new \PostgresLaana();
        if (!$pgLaana->conn) {
            return 0;
        }
        $mysql = self::connectTestMySql();
        if ($mysql === null) {
            return 0;
        }

        $pgSigs = [];
        foreach ($pgLaana->conn->query(
            'SELECT sourceid, COUNT(*) AS n, SUM(sentenceid) AS chk FROM laana.sentences GROUP BY sourceid'
        ) as $row) {
            $pgSigs[(int)$row['sourceid']] = [(int)$row['n'], (int)$row['chk']];
        }
        $mySigs = [];
        foreach ($mysql->query(
            'SELECT sourceID AS sourceid, COUNT(*) AS n, SUM(sentenceID) AS chk FROM sentences GROUP BY sourceID'
        ) as $row) {
            $mySigs[(int)$row['sourceid']] = [(int)$row['n'], (int)$row['chk']];
        }
        $contents = [];
        foreach ($pgLaana->conn->query('SELECT sourceid FROM laana.contents') as $row) {
            $contents[(int)$row['sourceid']] = true;
        }

        $candidates = [];
        foreach ($pgSigs as $sid => $sig) {
            if (isset($mySigs[$sid], $contents[$sid]) && $mySigs[$sid] === $sig) {
                $candidates[] = $sid;
            }
        }
        sort($candidates);
        return $candidates[0] ?? 0;
    }

    /**
     * Smoke test for the bulk-load decision input: the pending-migration
     * count must load both signature maps and return a sane total (the live
     * corpus has a known backlog, but the exact size moves run to run, so
     * only the type and non-negativity are asserted).
     */
    public function testPendingSentenceMigrationLoadsSignaturesAndCounts(): void
    {
        $this->requirePg();
        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline();
        $pending = $pipeline->pendingSentenceMigration();
        $this->assertIsInt($pending);
        $this->assertGreaterThanOrEqual(0, $pending);
    }

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
}

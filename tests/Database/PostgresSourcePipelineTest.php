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
        }        $exists = $mysql->prepare('SELECT COUNT(*) FROM sentences WHERE sourceID = :sid');
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

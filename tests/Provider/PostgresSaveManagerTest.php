<?php
namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

class PostgresSaveManagerTest extends BaseTestCase
{
    private string $mySqlConnectError = '';

    private function requireDbs(): void
    {
        if (!getenv('DB_HOST') || !getenv('PG_HOST') || !getenv('PG_DATABASE')) {
            $this->markTestSkipped('DB_HOST, PG_HOST and PG_DATABASE must be set for PostgresSaveManager tests');
        }
    }

    /**
     * Construct a PostgresSaveManager for testing. MySQLSaveManager's
     * constructor loads scripts/parsers.php via require_once, so only the
     * FIRST construction per process sees $parsermap; every later one raises
     * "Undefined variable $parsermap" (MySQLSaveManager.php:37) and builds
     * no parser map — a pre-existing parent limitation that never shows in
     * production (one manager per process). Swallow exactly that diagnostic
     * (PHPUnit's own error handler stacks beneath ours and everything else
     * still passes through), and re-arm the map from the global the parser
     * script itself maintains so multi-test runs behave like real processes.
     */
    private function newSaveManager(array $options): \Noiiolelo\Providers\Postgres\PostgresSaveManager
    {
        set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
            return $errno === E_WARNING
                && $errstr === 'Undefined variable $parsermap'
                && str_contains($errfile, 'MySQLSaveManager.php');
        });
        try {
            $mgr = new \Noiiolelo\Providers\Postgres\PostgresSaveManager($options);
        } finally {
            restore_error_handler();
        }
        $parsers = new \ReflectionProperty(\Noiiolelo\Providers\MySQL\MySQLSaveManager::class, 'parsers');
        $parsers->setValue($mgr, $GLOBALS['parsermap'] ?? []);
        return $mgr;
    }

    public function testIsAMySqlSaveManager(): void
    {
        $this->requireDbs();
        $mgr = $this->newSaveManager(['verbose' => false]);
        $this->assertInstanceOf(\Noiiolelo\Providers\MySQL\MySQLSaveManager::class, $mgr);
        $this->assertSame(0, $mgr->getPatternsSaved());
    }

    public function testProcessOneSourceMirrorsToPostgresWithPatterns(): void
    {
        $this->requireDbs();
        $sid = $this->resolveTestSourceId();
        if (!$sid) {
            $this->markTestSkipped($this->mySqlConnectError !== ''
                ? "MySQL connection failed: {$this->mySqlConnectError}"
                : 'No suitable test source found; set TEST_SOURCE_ID to override');
        }

        // Construct first: the parent constructor loads the parser map
        // (scripts/parsers.php sets $GLOBALS['parsermap']). Requiring it in
        // test-method scope instead would shadow the constructor's include
        // and leave $this->parsers undefined.
        $mgr = $this->newSaveManager(['force' => true, 'verbose' => false]);

        // The parent resolves the parser from the source's groupname; without
        // one, processOneSource would no-op and the mirror would never run.
        $groupname = $this->groupnameForSource($sid);
        if ($groupname === '' || !isset($GLOBALS['parsermap'][$groupname])) {
            $this->markTestSkipped("No parser for groupname '{$groupname}' (sourceid {$sid}); set TEST_SOURCE_ID to a parsed source");
        }

        // force=true re-scrapes and re-embeds the whole source, so the
        // embedding service must be reachable for the mirror to complete.
        if ((new \Noiiolelo\EmbeddingClient())->embedText('ping') === null) {
            $this->markTestSkipped('embedding service unavailable');
        }

        try {
            $summary = $mgr->processOneSource($sid);
        } catch (\Throwable $e) {
            if (self::isFetchError($e)) {
                $this->markTestSkipped('live fetch failed: ' . $e->getMessage());
            }
            throw $e;
        }
        $this->assertIsArray($summary);
        // Guard against a vacuous pass: 1 proves the source actually went
        // through updateSource + saveContents (and so the PG mirror ran).
        $this->assertSame(1, $summary['documents_processed']);

        require_once __DIR__ . '/../../db/PostgresFuncs.php';
        $pdo = (new \PostgresLaana())->conn;
        $sentences = (int)$pdo->query(
            "SELECT count(*) FROM laana.sentences WHERE sourceid = {$sid}"
        )->fetchColumn();
        $this->assertGreaterThan(0, $sentences);

        // CONSISTENCY (not coverage): zero-match sentences legitimately have
        // no pattern rows. Expected state comes from an in-memory scan only.
        $scanner = new \Noiiolelo\GrammarScanner();
        $stmt = $pdo->prepare(
            'SELECT sentenceid, hawaiiantext FROM laana.sentences
             WHERE sourceid = :sid AND hawaiiantext IS NOT NULL AND hawaiiantext <> \'\'
             ORDER BY sentenceid LIMIT 25'
        );
        $stmt->execute([':sid' => $sid]);
        $del = $pdo->prepare('SELECT pattern_type FROM laana.sentence_patterns WHERE sentenceid = :sid2 ORDER BY pattern_type');
        $checked = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $expected = [];
            foreach ($scanner->scanSentence($row['hawaiiantext']) as $m) { $expected[] = $m['pattern_type']; }
            $expected = array_values(array_unique($expected)); sort($expected);
            $del->execute([':sid2' => $row['sentenceid']]);
            $this->assertSame($expected, $del->fetchAll(\PDO::FETCH_COLUMN),
                "divergence for sentenceid {$row['sentenceid']}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Pick a deterministic sourceid for integration runs: TEST_SOURCE_ID env
     * wins, then $GLOBALS['__TEST_SOURCE_ID'], then the newest sourceid that
     * exists in BOTH Postgres (with sentences) and MySQL (with sentences).
     * Returns 0 when nothing usable is found (the caller skips). MySQL
     * connection failures are recorded distinctly instead of being reported
     * as "no source found".
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

        $mysql = $this->connectTestMySql();
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

    private function groupnameForSource(int $sid): string
    {
        $mysql = $this->connectTestMySql();
        if ($mysql === null) {
            return '';
        }
        $stmt = $mysql->prepare('SELECT groupname FROM sources WHERE sourceID = :sid');
        $stmt->execute([':sid' => $sid]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private function connectTestMySql(): ?\PDO
    {
        try {
            return new \PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: 'localhost')
                . ';port=' . (getenv('DB_PORT') ?: '3306')
                . ';dbname=' . (string)getenv('DB_DATABASE') . ';charset=utf8mb4',
                (string)getenv('DB_USER'),
                (string)getenv('DB_PASSWORD'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->mySqlConnectError = $e->getMessage();
            return null;
        }
    }

    /**
     * Fetch/transport failures (Guzzle request exceptions, cURL, DNS, TLS,
     * timeouts) are environmental — skip rather than fail. Anything else
     * (including programming errors) must propagate.
     */
    private static function isFetchError(\Throwable $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        foreach (['curl error', 'could not resolve', 'connection refused', 'connection reset', 'timed out', 'timeout'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}

<?php

namespace Noiiolelo\Tests\Integration;

use Noiiolelo\Tests\BaseTestCase;

/**
 * End-to-end tests for scripts/save.php --dryrun against live MySQL.
 *
 * Uses a parser key that maps to no parser, so the previewed document list
 * is empty and the red-phase fallthrough (before --dryrun existed) runs
 * getAllDocuments with zero documents — no scraping, no backend writes
 * beyond a processing-log row. Once --dryrun exists, the run stops at the
 * preview and touches nothing at all.
 */
class SaveScriptDryRunTest extends BaseTestCase
{
    private const NO_SUCH_PARSER = '__no_such_cli_parser__';

    private function runCli(string $args): array
    {
        $php = PHP_BINARY;
        $script = __DIR__ . '/../../scripts/save.php';
        $cmd = sprintf('%s %s %s 2>&1', escapeshellarg($php), escapeshellarg($script), $args);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [implode("\n", $output), $code];
    }

    private function requireLiveMysql(): void
    {
        if (!getenv('DB_HOST')) {
            $this->markTestSkipped('DB_HOST must be set for save.php --dryrun tests');
        }
        try {
            new \Noiiolelo\Providers\MySQL\MySQLSaveManager(['verbose' => false]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL unreachable: ' . $e->getMessage());
        }
    }

    public function testDryRunPreviewsWithoutProcessing(): void
    {
        $this->requireLiveMysql();

        [$output, $code] = $this->runCli('--provider=mysql --dryrun --parser=' . self::NO_SUCH_PARSER);

        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('Would fetch and process 0 of 0 documents', $output);
        // The dry run must stop at the preview: no processing summary.
        $this->assertStringNotContainsString('Summary:', $output);
    }

    public function testDryRunWithDeleteExistingSkipsTheDelete(): void
    {
        $this->requireLiveMysql();

        [$output, $code] = $this->runCli(
            '--provider=mysql --dryrun --parser=' . self::NO_SUCH_PARSER . ' --delete-existing'
        );

        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString(
            "Would delete all existing documents with groupname '" . self::NO_SUCH_PARSER . "'",
            $output
        );
        // Real-run wording must be absent: the delete itself must not happen.
        $this->assertStringNotContainsString('WARNING: About to delete', $output);
        $this->assertStringNotContainsString('Deleted:', $output);
    }

    /**
     * Newest sourceid whose groupname maps to a parser. TEST_MYSQL_SOURCE_ID
     * env wins for determinism (same convention as
     * SaveManagerSourceResolutionTest).
     */
    private function resolvableSourceId(\Noiiolelo\Providers\MySQL\MySQLSaveManager $mgr): int
    {
        $env = getenv('TEST_MYSQL_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (int)$env;
        }
        $rows = $mgr->getLaana()->getsources();
        foreach (array_reverse($rows) as $row) {
            if (!empty($row['groupname']) && $mgr->getParser($row['groupname'])) {
                return (int)$row['sourceid'];
            }
        }
        return 0;
    }

    /**
     * Green-phase only: before --dryrun existed, this combination ran
     * processOneSource, which scrapes the live page and writes to the
     * backend — it could not be watched fail safely.
     */
    public function testDryRunWithSourceidReportsResolvedParser(): void
    {
        $this->requireLiveMysql();
        $mgr = new \Noiiolelo\Providers\MySQL\MySQLSaveManager(['verbose' => false]);
        $sid = $this->resolvableSourceId($mgr);
        if (!$sid) {
            $this->markTestSkipped('No source with a parser-mapped groupname found in MySQL');
        }

        [$output, $code] = $this->runCli("--provider=mysql --dryrun --sourceid={$sid}");

        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString("Would process source {$sid} with parser '", $output);
        $this->assertStringContainsString('Resolved parser', $output);
        $this->assertStringNotContainsString('Summary:', $output);
    }

    /**
     * Green-phase only before the bare --doclist-save fix: the fallthrough
     * ran the doclist block, which fataled (exit 255) on the valueless flag.
     */
    public function testDryRunWithBareDoclistSavePreviewsDefaultPath(): void
    {
        $this->requireLiveMysql();

        [$output, $code] = $this->runCli(
            '--provider=mysql --dryrun --parser=' . self::NO_SUCH_PARSER . ' --doclist-save'
        );

        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString(
            'Would save the doc list to scripts/doclists/' . self::NO_SUCH_PARSER . '.json',
            $output
        );
        $this->assertStringNotContainsString('Saved document list', $output);
    }
}

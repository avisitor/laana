<?php

namespace Noiiolelo\Tests\Integration;

use Noiiolelo\Tests\BaseTestCase;

/**
 * End-to-end tests for scripts/save.php --doclist-save against live MySQL.
 *
 * Uses a parser key that maps to no parser: the doc-list step finds no
 * documents, so no doclist file is written and no document processing
 * happens. This covers the empty-list path end to end (including the bare
 * --doclist-save flag, which getopt reports as false); the default-path
 * write itself is exercised at preview level by
 * SaveScriptDryRunTest::testDryRunWithBareDoclistSavePreviewsDefaultPath.
 */
class SaveScriptDoclistTest extends BaseTestCase
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
            $this->markTestSkipped('DB_HOST must be set for save.php doclist tests');
        }
        try {
            new \Noiiolelo\Providers\MySQL\MySQLSaveManager(['verbose' => false]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL unreachable: ' . $e->getMessage());
        }
    }

    public function testBareDoclistSaveUsesDefaultPath(): void
    {
        $this->requireLiveMysql();

        [$output, $code] = $this->runCli(
            '--provider=mysql --parser=' . self::NO_SUCH_PARSER . ' --doclist-save --doclist-only'
        );

        // getopt returns false for a valueless optional arg; the default-path
        // check must treat that like the empty string, not fatal (exit 255).
        $this->assertNotSame(255, $code, "Fatal error (exit 255), got: {$output}");
        $this->assertSame(1, $code, "Expected exit 1 from the empty doc list, got: {$output}");
        $this->assertStringContainsString('parser returned empty document list', $output);
        $this->assertStringNotContainsString('Saved document list', $output);
    }
}

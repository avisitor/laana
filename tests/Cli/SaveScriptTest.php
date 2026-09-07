<?php

namespace Noiiolelo\Tests\Cli;

use Noiiolelo\Tests\BaseTestCase;

/**
 * Argument-gating smoke tests for scripts/save.php.
 *
 * These run the real CLI with argument combinations that exit during argument
 * parsing (--help) or validation (missing parser, --delete-existing without
 * a parser), so no database or search-backend connection is ever attempted.
 * --provider=bogus is a safety net: if a gate were missing, the factory
 * rejects the unknown provider before any backend is contacted instead of
 * falling through to a full-corpus run.
 */
class SaveScriptTest extends BaseTestCase
{
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

    public function testHelpExitsZeroAndListsOptions(): void
    {
        // --help must print usage and exit 0 before any provider validation
        // or backend connection.
        [$output, $code] = $this->runCli('--provider=bogus --help');
        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('--provider=', $output);
        $this->assertStringContainsString('--parser=KEY', $output);
        $this->assertStringContainsString('--sourceid=ID', $output);
        $this->assertStringContainsString('--delete-existing', $output);
        $this->assertStringContainsString('--remote=ID', $output);
        $this->assertStringContainsString('--doclist-save', $output);
    }

    public function testNoSelectionPrintsUsageAndFails(): void
    {
        // Without --parser, --sourceid, a sourceid range, or --remote there
        // is nothing to process: the script must print usage and exit 1
        // instead of silently starting a full-corpus run.
        [$output, $code] = $this->runCli('--provider=bogus');
        $this->assertSame(1, $code, "Expected exit 1, got: {$output}");
        $this->assertStringContainsString('Specify a parser', $output);
    }

    public function testDeleteExistingWithoutParserIsRejected(): void
    {
        // --delete-existing wipes a whole groupname; without a parser key it
        // must be refused before any manager (and backend) is created.
        [$output, $code] = $this->runCli('--provider=bogus --delete-existing');
        $this->assertSame(1, $code, "Expected exit 1, got: {$output}");
        $this->assertStringContainsString('--delete-existing requires --parser', $output);
    }

    public function testDoclistOnlyWithoutDoclistSaveIsRejected(): void
    {
        // --doclist-only is only meaningful together with --doclist-save;
        // without it the flag would be silently ignored and the run would
        // fall through to full document processing.
        [$output, $code] = $this->runCli('--provider=bogus --doclist-only');
        $this->assertSame(1, $code, "Expected exit 1, got: {$output}");
        $this->assertStringContainsString('--doclist-only requires --doclist-save', $output);
    }
}

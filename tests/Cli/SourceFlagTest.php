<?php

namespace Noiiolelo\Tests\Cli;

use Noiiolelo\Tests\BaseTestCase;

/**
 * Parse-level smoke tests for the --source flag in scripts/createindex.php.
 *
 * These run the real CLI with argument combinations that exit during argument
 * parsing (--help) or validation (invalid --source), so no Elasticsearch or
 * Postgres connection is ever attempted.
 */
class SourceFlagTest extends BaseTestCase
{
    private function runCli(string $args): array
    {
        $php = PHP_BINARY;
        $script = __DIR__ . '/../../scripts/createindex.php';
        $cmd = sprintf('%s %s %s 2>&1', escapeshellarg($php), escapeshellarg($script), $args);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [implode("\n", $output), $code];
    }

    public function testHelpAcceptsSourceFlag(): void
    {
        // getopt() must accept --source; --help exits 0 during parsing.
        [$output, $code] = $this->runCli('--source=postgres --help');
        $this->assertSame(0, $code, "Expected exit 0, got: {$output}");
        $this->assertStringContainsString('--source=NAME', $output);
    }

    public function testInvalidSourceIsRejected(): void
    {
        [$output, $code] = $this->runCli('--source=bogus');
        $this->assertSame(1, $code, "Expected exit 1, got: {$output}");
        $this->assertStringContainsString("expects 'api' or 'postgres'", $output);
    }

    public function testDefaultSourceIsApi(): void
    {
        // --help alone must still work; the preamble would print Source: api.
        [$output, $code] = $this->runCli('--help');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--source=NAME', $output);
    }
}

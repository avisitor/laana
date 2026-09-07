<?php

namespace Noiiolelo\Tests\Integration;

use Noiiolelo\Tests\BaseTestCase;

/**
 * End-to-end test for scripts/save.php --delete-existing against live MySQL.
 *
 * Uses a parser key that maps to no groupname, so the group delete matches
 * zero rows and the run deletes nothing. --doclist-save --doclist-only then
 * stops the run after the delete (the unknown parser has no document list,
 * so the doc-list step exits 1 before any document processing and no
 * doclist file is written).
 *
 * Deliberately green-phase only: before the --delete-existing port existed,
 * running this combination made save.php fall through to a full-corpus run,
 * so it could not be watched fail safely.
 */
class SaveScriptDeleteTest extends BaseTestCase
{
    private const NO_SUCH_GROUP = '__no_such_group_cli_delete_test__';

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
            $this->markTestSkipped('DB_HOST must be set for save.php --delete-existing tests');
        }
        try {
            new \Noiiolelo\Providers\MySQL\MySQLSaveManager(['verbose' => false]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL unreachable: ' . $e->getMessage());
        }
    }

    public function testDeleteExistingReportsZeroedStatsForUnknownGroup(): void
    {
        $this->requireLiveMysql();

        [$output, $code] = $this->runCli(
            '--provider=mysql --parser=' . self::NO_SUCH_GROUP . ' --delete-existing --doclist-save --doclist-only'
        );

        $this->assertStringContainsString(
            "WARNING: About to delete all existing documents with groupname '" . self::NO_SUCH_GROUP . "'",
            $output,
            "Expected the delete warning, got: {$output}"
        );
        $this->assertStringContainsString('Sources: 0', $output);
        $this->assertStringContainsString('Contents: 0', $output);
        $this->assertStringContainsString('Sentences: 0', $output);

        // The delete itself succeeded; the nonzero exit comes from the
        // --doclist-only step (unknown parser => empty document list).
        $this->assertSame(1, $code, "Expected exit 1 from the empty doc list, got: {$output}");
    }
}

<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * Guards scripts/pg_import.php against schema drift: every laana.<table>
 * referenced in the script must exist in the live Postgres schema.
 *
 * Regression test for the 2026-09-01 drop of laana.documents
 * (db/migrations/2026-09-01-drop-dead-vector-columns.sql), which left a
 * stale entry in pg_import.php's reset list and made `--force` die on an
 * uncaught "relation does not exist" mid-count — before the TRUNCATE ran.
 * Skipped when PG_HOST is not reachable.
 */
class PgImportResetTablesTest extends BaseTestCase
{
    public function testAllLaanaTablesReferencedByPgImportExist(): void
    {
        if (!getenv('PG_HOST')) {
            $this->markTestSkipped('No PG_HOST');
        }
        $this->skipIfProviderUnavailable('Postgres');

        $script = file_get_contents(__DIR__ . '/../../scripts/pg_import.php');
        $this->assertNotFalse($script, 'Cannot read scripts/pg_import.php');

        // The only laana.<table> literals in pg_import.php are the reset-list
        // entries; the rest of its SQL relies on search_path.
        preg_match_all('/laana\.[a-z_]+/', $script, $matches);
        $tables = array_values(array_unique($matches[0]));
        $this->assertNotEmpty($tables, 'No laana.<table> references found in pg_import.php');

        $pg = new \PostgresLaana();
        $this->assertNotNull($pg->conn, 'PostgresLaana connection failed');

        // Base tables and views come from information_schema; materialized
        // views (e.g. grammar_pattern_counts) only appear in pg_matviews.
        $present = [];
        foreach ($pg->conn->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'laana'"
        ) as $row) {
            $present[$row['table_name']] = true;
        }
        foreach ($pg->conn->query(
            "SELECT matviewname FROM pg_matviews WHERE schemaname = 'laana'"
        ) as $row) {
            $present[$row['matviewname']] = true;
        }

        foreach ($tables as $qualified) {
            $table = substr($qualified, strlen('laana.'));
            $this->assertArrayHasKey(
                $table,
                $present,
                "{$qualified} is referenced by scripts/pg_import.php but does not exist in the live schema"
            );
        }
    }
}

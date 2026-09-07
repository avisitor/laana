<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * Validates that the live laana schema matches pgschema.sql — the canonical
 * creation script — in both directions:
 *   1. every object declared in pgschema.sql exists in the live schema;
 *   2. every live laana object is declared in pgschema.sql;
 *   3. every laana object referenced by scripts/pg_import.php is declared
 *      there too.
 * This catches schema drift of the kind that broke pg_import.php --force
 * when the documents table was dropped by migration while the script's
 * reset list still referenced it. Skipped when PG_HOST is not set.
 */
class PgSchemaMatchesPgschemaSqlTest extends BaseTestCase
{
    public function testDeclaredObjectsExistInLiveSchema(): void
    {
        $conn = $this->postgresConn();
        $declared = self::declaredObjects();
        $live = self::liveObjects($conn);

        foreach (['table', 'view', 'matview'] as $kind) {
            $missing = array_diff($declared[$kind], $live[$kind]);
            $this->assertSame(
                [],
                array_values($missing),
                "pgschema.sql declares laana {$kind}(s) missing from the live schema: "
                . implode(', ', $missing)
            );
        }
    }

    public function testLiveLaanaObjectsAreDeclaredInPgschemaSql(): void
    {
        $conn = $this->postgresConn();
        $declared = self::declaredObjects();
        $live = self::liveObjects($conn);

        foreach (['table', 'view', 'matview'] as $kind) {
            $undeclared = array_diff($live[$kind], $declared[$kind]);
            $this->assertSame(
                [],
                array_values($undeclared),
                "Live laana {$kind}(s) not declared in pgschema.sql — update the schema "
                . 'file or remove the object: ' . implode(', ', $undeclared)
            );
        }
    }

    public function testPgImportReferencesAreDeclaredInPgschemaSql(): void
    {
        $script = file_get_contents(__DIR__ . '/../../scripts/pg_import.php');
        $this->assertNotFalse($script, 'Cannot read scripts/pg_import.php');

        preg_match_all('/laana\.[a-z_]+/', $script, $matches);
        $referenced = array_unique($matches[0]);

        $declared = self::declaredObjects();
        $known = array_map(
            static fn (string $name): string => "laana.{$name}",
            array_merge($declared['table'], $declared['view'], $declared['matview'])
        );

        $unknown = array_diff($referenced, $known);
        $this->assertSame(
            [],
            array_values($unknown),
            'scripts/pg_import.php references laana objects that pgschema.sql does not declare: '
            . implode(', ', $unknown)
        );
    }

    private function postgresConn(): \PDO
    {
        $this->skipIfProviderUnavailable('Postgres');
        if (!getenv('PG_HOST')) {
            $this->markTestSkipped('No PG_HOST');
        }

        $pg = new \PostgresLaana();
        $this->assertNotNull($pg->conn, 'PostgresLaana connection failed');

        return $pg->conn;
    }

    /**
     * Objects declared by pgschema.sql, keyed by kind. The only
     * laana-qualified CREATE statements in that file are for base tables,
     * views, and materialized views.
     *
     * @return array{table: string[], view: string[], matview: string[]}
     */
    private static function declaredObjects(): array
    {
        $sql = file_get_contents(__DIR__ . '/../../pgschema.sql');
        if ($sql === false) {
            return ['table' => [], 'view' => [], 'matview' => []];
        }

        preg_match_all('/CREATE TABLE\s+laana\.([a-z_]+)/i', $sql, $tables);
        preg_match_all('/CREATE (?:OR REPLACE )?VIEW\s+laana\.([a-z_]+)/i', $sql, $views);
        preg_match_all('/CREATE MATERIALIZED VIEW\s+laana\.([a-z_]+)/i', $sql, $matviews);

        return [
            'table' => array_values(array_unique($tables[1])),
            'view' => array_values(array_unique($views[1])),
            'matview' => array_values(array_unique($matviews[1])),
        ];
    }

    /**
     * Objects present in the live laana schema, keyed by kind. Materialized
     * views are absent from information_schema.tables, hence pg_matviews.
     *
     * @return array{table: string[], view: string[], matview: string[]}
     */
    private static function liveObjects(\PDO $conn): array
    {
        $tables = [];
        $views = [];
        foreach ($conn->query(
            "SELECT table_name, table_type FROM information_schema.tables
              WHERE table_schema = 'laana'"
        ) as $row) {
            if ($row['table_type'] === 'BASE TABLE') {
                $tables[] = $row['table_name'];
            } elseif ($row['table_type'] === 'VIEW') {
                $views[] = $row['table_name'];
            }
        }

        $matviews = [];
        foreach ($conn->query(
            "SELECT matviewname FROM pg_matviews WHERE schemaname = 'laana'"
        ) as $row) {
            $matviews[] = $row['matviewname'];
        }

        return ['table' => $tables, 'view' => $views, 'matview' => $matviews];
    }
}

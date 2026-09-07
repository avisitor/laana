<?php

namespace Noiiolelo\Tests\Database;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/funcs.php';

/**
 * Validates that the live MySQL database matches createtables.sql — the
 * canonical creation script — in both directions:
 *   1. every table declared in createtables.sql exists in the live database;
 *   2. every live table is declared there or is a documented external table.
 * This catches schema drift of the kind that broke pg_import.php --force on
 * the Postgres side (a dropped table still referenced by application code).
 * Skipped when no MySQL connection settings are present.
 */
class MysqlSchemaMatchesCreatetablesSqlTest extends BaseTestCase
{
    /**
     * Live tables created outside this repo's schema files, owned by the
     * avisitor idp-client/authz packages (their own migrations). Nothing in
     * this repo references their DDL, so createtables.sql cannot declare them.
     */
    private const EXTERNAL_TABLES = ['app_roles', 'user_profiles'];

    public function testDeclaredTablesExistInLiveDatabase(): void
    {
        $conn = $this->mysqlConn();
        $declared = self::declaredTables();
        $live = self::liveTables($conn);

        $missing = array_diff($declared, $live);
        $this->assertSame(
            [],
            array_values($missing),
            'createtables.sql declares tables missing from the live MySQL database: '
            . implode(', ', $missing)
        );
    }

    public function testLiveTablesAreDeclaredOrKnownExternal(): void
    {
        $conn = $this->mysqlConn();
        $declared = self::declaredTables();
        $live = self::liveTables($conn);

        $undeclared = array_diff($live, $declared, self::EXTERNAL_TABLES);
        $this->assertSame(
            [],
            array_values($undeclared),
            'Live MySQL tables neither declared in createtables.sql nor listed in '
            . 'EXTERNAL_TABLES — update the schema file or document the external owner: '
            . implode(', ', $undeclared)
        );
    }

    private function mysqlConn(): \PDO
    {
        if (!getenv('DB_DATABASE')) {
            $this->markTestSkipped('No DB_DATABASE');
        }
        $this->skipIfProviderUnavailable('MySQL');

        $mysql = new \Laana();
        $this->assertNotNull($mysql->conn, 'Laana (MySQL) connection failed');

        return $mysql->conn;
    }

    /**
     * Table names declared by the CREATE TABLE statements in createtables.sql.
     *
     * @return string[]
     */
    private static function declaredTables(): array
    {
        $sql = file_get_contents(__DIR__ . '/../../createtables.sql');
        if ($sql === false) {
            return [];
        }

        preg_match_all('/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches);
        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Tables and views present in the live database (current connection's
     * schema). MySQL lowercases identifiers, so comparisons are lowercase.
     *
     * @return string[]
     */
    private static function liveTables(\PDO $conn): array
    {
        $names = [];
        foreach ($conn->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        ) as $row) {
            $names[] = strtolower($row['table_name']);
        }
        return $names;
    }
}

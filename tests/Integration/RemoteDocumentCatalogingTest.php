<?php

namespace Noiiolelo\Tests\Integration;

require_once __DIR__ . '/../../env-loader.php';

use Noiiolelo\Providers\MySQL\MySQLSaveManager;
use Noiiolelo\Tests\BaseTestCase;
use PDO;

class RemoteDocumentCatalogingTest extends BaseTestCase
{
    private static string $testDbName = '';
    private static string $testEnvFile = '';
    private static ?PDO $adminPdo = null;

    private function isVerboseCataloging(): bool
    {
        $flag = $_ENV['VERBOSE'] ?? getenv('VERBOSE') ?? $_ENV['CATALOG_TEST_VERBOSE'] ?? getenv('CATALOG_TEST_VERBOSE') ?? '0';
        return in_array(strtolower((string)$flag), ['1', 'true', 'yes', 'on'], true);
    }

    private function logCatalogStep(string $message): void
    {
        if (!$this->isVerboseCataloging()) {
            return;
        }
        fwrite(STDOUT, '[catalog-test ' . date('H:i:s') . '] ' . $message . PHP_EOL);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $baseEnv = loadEnv(__DIR__ . '/../../.env');
        $host = $baseEnv['DB_HOST'] ?? 'localhost';
        $port = $baseEnv['DB_PORT'] ?? '3306';
        $user = $baseEnv['DB_USER'] ?? '';
        $pass = $baseEnv['DB_PASSWORD'] ?? '';

        if (!$user) {
            throw new \RuntimeException('DB_USER must be set in .env for cataloging tests');
        }

        self::$testDbName = 'noiiolelo_test_' . uniqid();
        self::$adminPdo = \Common\DB\DBBase::createConnection([
            'dsn' => "mysql:host={$host};port={$port};charset=utf8mb4",
            'username' => $user,
            'password' => $pass,
        ]);
        self::$adminPdo->exec("CREATE DATABASE `" . self::$testDbName . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbPdo = \Common\DB\DBBase::createConnection([
            'host' => $host,
            'port' => $port,
            'dbname' => self::$testDbName,
            'username' => $user,
            'password' => $pass,
        ]);
        self::createMinimalSchema($dbPdo);

        self::$testEnvFile = sys_get_temp_dir() . '/noiiolelo_test_' . uniqid() . '.env';
        $envContents = implode("\n", [
            "DB_HOST={$host}",
            "DB_PORT={$port}",
            "DB_USER={$user}",
            "DB_PASSWORD={$pass}",
            "DB_DATABASE=" . self::$testDbName,
        ]) . "\n";
        file_put_contents(self::$testEnvFile, $envContents);
        $_ENV['NOIIOLELO_ENV_FILE'] = self::$testEnvFile;
        putenv('NOIIOLELO_ENV_FILE=' . self::$testEnvFile);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$adminPdo && self::$testDbName) {
            self::$adminPdo->exec('DROP DATABASE IF EXISTS `' . self::$testDbName . '`');
        }
        if (self::$testEnvFile && file_exists(self::$testEnvFile)) {
            @unlink(self::$testEnvFile);
        }
        unset($_ENV['NOIIOLELO_ENV_FILE']);
        putenv('NOIIOLELO_ENV_FILE');

        parent::tearDownAfterClass();
    }

    private static function createMinimalSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE sources (
            sourceID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sourceName VARCHAR(200) NOT NULL,
            authors TEXT NULL,
            link TEXT NOT NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            groupname VARCHAR(20) NOT NULL,
            title VARCHAR(200) NULL,
            date DATE NULL,
            UNIQUE KEY uniq_link (link(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE contents (
            sourceID INT NOT NULL PRIMARY KEY,
            html MEDIUMTEXT NULL,
            text MEDIUMTEXT NULL,
            wordCount INT DEFAULT 0,
            created DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE sentences (
            sentenceID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sourceID INT NOT NULL,
            hawaiianText TEXT NULL,
            englishText VARCHAR(255) NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            simplified TEXT NULL,
            wordCount INT DEFAULT 0,
            KEY idx_source (sourceID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE processing_log (
            log_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            operation_type VARCHAR(50) NOT NULL,
            source_id INT NULL,
            groupname VARCHAR(50) NULL,
            parser_key VARCHAR(50) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'started',
            sentences_count INT DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            error_message TEXT NULL,
            metadata TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function testRemoteCatalogingAndAcquisition(): void
    {
        $provider = $_ENV['CATALOG_TEST_PROVIDER'] ?? 'MySQL';
        if ($provider !== 'MySQL') {
            $this->markTestSkipped('Configured catalog provider is not MySQL');
        }

        $parserKey = $_ENV['CATALOG_TEST_PARSER'] ?? 'keaolama';
        $docLimit = max(1, (int)($_ENV['CATALOG_TEST_DOC_LIMIT'] ?? 5));

        $this->logCatalogStep("Starting remote cataloging test (provider=$provider, parser=$parserKey, docLimit=$docLimit)");

        $manager = new MySQLSaveManager([
            'parserkey' => $parserKey,
            'maxrows' => 1,
            'verbose' => false,
        ]);

        $t0 = microtime(true);
        $parser = $manager->getParser($parserKey);
        $this->logCatalogStep('Parser loaded in ' . round((microtime(true) - $t0), 3) . 's');
        $this->assertNotNull($parser, "Parser {$parserKey} must be configured");

        $t0 = microtime(true);
        $this->logCatalogStep('Fetching remote document list...');
        $docs = $parser->getDocumentList();
        $this->logCatalogStep('Fetched ' . count($docs) . ' documents in ' . round((microtime(true) - $t0), 3) . 's');
        $this->assertNotEmpty($docs, 'Remote document list should not be empty');
        $this->assertGreaterThanOrEqual($docLimit, count($docs), "Expected at least {$docLimit} documents from remote catalog");

        $limit = min($docLimit, count($docs));
        for ($i = 0; $i < $limit; $i++) {
            $doc = $docs[$i];
            $this->assertNotEmpty($doc['groupname'] ?? null, 'Catalog entry missing groupname');
            $this->assertNotEmpty($doc['url'] ?? $doc['link'] ?? null, 'Catalog entry missing URL');
            $this->assertNotEmpty($doc['sourcename'] ?? null, 'Catalog entry missing sourcename');
        }

        $laana = $manager->getLaana();
        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $doc = $docs[$i];
            $link = $doc['url'] ?? $doc['link'] ?? '';
            $this->assertNotEmpty($link, "Document #{$i} link missing");

            $this->logCatalogStep('Processing remote document #' . ($i + 1) . ': ' . ($doc['sourcename'] ?? '(unknown)') . ' | ' . $link);
            $t0 = microtime(true);
            $manager->processOneDocument($doc, $i);
            $this->logCatalogStep('Document #' . ($i + 1) . ' processing completed in ' . round((microtime(true) - $t0), 3) . 's');

            $this->logCatalogStep('Verifying persistence for document #' . ($i + 1) . ' link: ' . $link);
            $source = $laana->getSourceByLink($link);
            $this->assertNotEmpty($source['sourceid'] ?? null, 'Source was not persisted to test database');

            $raw = $laana->getRawText($source['sourceid']);
            $this->assertNotEmpty($raw, 'Raw HTML was not saved for acquired document');
            $this->logCatalogStep('Persistence checks passed for sourceid=' . ($source['sourceid'] ?? 'unknown'));
            $processed++;
        }

        $this->assertSame($limit, $processed, "Expected to process {$limit} documents");
    }
}

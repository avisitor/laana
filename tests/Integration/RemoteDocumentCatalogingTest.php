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
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        self::$adminPdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        self::$adminPdo->exec("CREATE DATABASE `" . self::$testDbName . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbDsn = "mysql:host={$host};port={$port};dbname=" . self::$testDbName . ";charset=utf8mb4";
        $dbPdo = new PDO($dbDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
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
        $docLimit = (int)($_ENV['CATALOG_TEST_DOC_LIMIT'] ?? 1);

        $manager = new MySQLSaveManager([
            'parserkey' => $parserKey,
            'maxrows' => 1,
            'verbose' => false,
        ]);

        $parser = $manager->getParser($parserKey);
        $this->assertNotNull($parser, "Parser {$parserKey} must be configured");

        $docs = $parser->getDocumentList();
        $this->assertNotEmpty($docs, 'Remote document list should not be empty');

        $limit = min($docLimit, count($docs));
        for ($i = 0; $i < $limit; $i++) {
            $doc = $docs[$i];
            $this->assertNotEmpty($doc['groupname'] ?? null, 'Catalog entry missing groupname');
            $this->assertNotEmpty($doc['url'] ?? $doc['link'] ?? null, 'Catalog entry missing URL');
            $this->assertNotEmpty($doc['sourcename'] ?? null, 'Catalog entry missing sourcename');
        }

        $firstDoc = $docs[0];
        $this->assertNotEmpty($firstDoc['groupname'] ?? null, 'First catalog entry missing groupname');

        $manager->processOneDocument($firstDoc, 0);

        $link = $firstDoc['url'] ?? $firstDoc['link'] ?? '';
        $this->assertNotEmpty($link, 'First document link missing');

        $laana = $manager->getLaana();
        $source = $laana->getSourceByLink($link);
        $this->assertNotEmpty($source['sourceid'] ?? null, 'Source was not persisted to test database');

        $raw = $laana->getRawText($source['sourceid']);
        $this->assertNotEmpty($raw, 'Raw HTML was not saved for acquired document');
    }
}

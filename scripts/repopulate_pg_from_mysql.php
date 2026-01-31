<?php
declare(strict_types=1);

require_once __DIR__ . '/../env-loader.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

function envValue(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $default;
    }
    return $value;
}

function connectMySql(): PDO {
    $host = envValue('DB_HOST', 'localhost');
    $port = envValue('DB_PORT', '3306');
    $db   = envValue('DB_DATABASE');
    $user = envValue('DB_USER');
    $pass = envValue('DB_PASSWORD');
    $socket = envValue('DB_SOCKET');

    if ($socket !== '') {
        $socket = trim($socket, "\"'");
    }

    if ($db === '') {
        throw new RuntimeException('DB_DATABASE is not set.');
    }

    if ($socket !== '' && file_exists($socket)) {
        $dsn = "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }

    return new PDO($dsn, $user, $pass, $options);
}

function connectPostgres(): PDO {
    $host = envValue('PG_HOST', 'localhost');
    $port = envValue('PG_PORT', '5432');
    $db   = envValue('PG_DATABASE', envValue('PG_DB'));
    $user = envValue('PG_USER');
    $pass = envValue('PG_PASSWORD');
    $dsnOverride = envValue('PG_DSN');

    if ($db === '') {
        throw new RuntimeException('PG_DATABASE (or PG_DB) is not set.');
    }

    $dsn = $dsnOverride !== ''
        ? $dsnOverride
        : "pgsql:host={$host};port={$port};dbname={$db}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET client_encoding TO 'UTF8'");
    $pdo->exec("SET search_path TO laana, public");

    return $pdo;
}

$mysql = connectMySql();
$pg = connectPostgres();

$totalSources = (int) $mysql->query('SELECT COUNT(*) FROM sources')->fetchColumn();

$sourceStmt = $mysql->prepare(
    'SELECT sourceID AS sourceid, sourceName AS sourcename, authors, link, created, groupname, title, date '
    . 'FROM sources ORDER BY sourceID'
);
$sentenceStmt = $mysql->prepare(
    'SELECT sentenceID AS sentenceid, sourceID AS sourceid, hawaiianText AS hawaiiantext, '
    . 'englishText AS englishtext, created '
    . 'FROM sentences WHERE sourceID = :sourceid ORDER BY sentenceID'
);
$contentStmt = $mysql->prepare(
    'SELECT sourceID AS sourceid, html, text, created FROM contents WHERE sourceID = :sourceid'
);

$sourceUpsert = $pg->prepare(
    'INSERT INTO sources (sourceid, sourcename, authors, link, created, groupname, title, date) '
    . 'VALUES (:sourceid, :sourcename, :authors, :link, :created, :groupname, :title, :date) '
    . 'ON CONFLICT (sourceid) DO UPDATE SET '
    . 'sourcename = EXCLUDED.sourcename, authors = EXCLUDED.authors, link = EXCLUDED.link, '
    . 'created = EXCLUDED.created, groupname = EXCLUDED.groupname, title = EXCLUDED.title, date = EXCLUDED.date'
);

$sentenceUpsert = $pg->prepare(
    'INSERT INTO sentences (sentenceid, sourceid, hawaiiantext, englishtext, created) '
    . 'VALUES (:sentenceid, :sourceid, :hawaiiantext, :englishtext, :created) '
    . 'ON CONFLICT (sentenceid) DO UPDATE SET '
    . 'sourceid = EXCLUDED.sourceid, hawaiiantext = EXCLUDED.hawaiiantext, '
    . 'englishtext = EXCLUDED.englishtext, created = EXCLUDED.created'
);

$contentUpsert = $pg->prepare(
    'INSERT INTO contents (sourceid, html, text, created) '
    . 'VALUES (:sourceid, :html, :text, :created) '
    . 'ON CONFLICT (sourceid) DO UPDATE SET '
    . 'html = EXCLUDED.html, text = EXCLUDED.text, created = EXCLUDED.created'
);

$sourceStmt->execute();
$sources = $sourceStmt->fetchAll();
$index = 0;

foreach ($sources as $source) {
    $index++;
    $sourceId = (int) $source['sourceid'];
    echo "[{$index}/{$totalSources}] sourceID={$sourceId} group={$source['groupname']}\n";

    $pg->beginTransaction();
    try {
        $sourceUpsert->execute($source);

        $contentStmt->execute([':sourceid' => $sourceId]);
        $content = $contentStmt->fetch();
        if ($content) {
            $contentUpsert->execute($content);
        }

        $sentenceStmt->execute([':sourceid' => $sourceId]);
        $sentenceCount = 0;
        while ($sentence = $sentenceStmt->fetch()) {
            $sentenceUpsert->execute($sentence);
            $sentenceCount++;
        }

        $pg->commit();

        $contentStatus = $content ? 'yes' : 'no';
        echo "  inserted sentences: {$sentenceCount}, content: {$contentStatus}\n";
    } catch (Throwable $e) {
        $pg->rollBack();
        echo "  ERROR sourceID={$sourceId}: {$e->getMessage()}\n";
    }

    if (function_exists('flush')) {
        flush();
    }
}

echo "Done. Processed {$index} sources.\n";
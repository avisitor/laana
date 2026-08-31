<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load(__DIR__ . '/../.env');
}
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

    $config = [
        'host' => $host,
        'port' => $port,
        'dbname' => $db,
        'username' => $user,
        'password' => $pass,
    ];
    if ($socket !== '' && file_exists($socket)) {
        $config['socket'] = $socket;
    }

    return \Common\DB\DBBase::createConnection($config);
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

    $config = [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => $port,
        'dbname' => $db,
        'username' => $user,
        'password' => $pass,
    ];
    if ($dsnOverride !== '') {
        $config['dsn'] = $dsnOverride;
    }

    $pdo = \Common\DB\DBBase::createConnection($config);
    $pdo->exec("SET client_encoding TO 'UTF8'");
    $pdo->exec("SET search_path TO laana, public");

    return $pdo;
}

$mysql = connectMySql();
$pg = connectPostgres();

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------
// --reset          TRUNCATE all laana corpus tables (children + parents in one
//                  FK-safe statement) before importing; also refreshes the
//                  grammar_pattern_counts materialized view.
// --limit=N        Import at most N sources (lowest sourceids first).
// --source-id=ID   Import a single source (overrides --limit for selection).
$reset = in_array('--reset', $argv, true);
$limit = 0;
$sourceId = 0;
foreach ($argv as $i => $arg) {
    if ($arg === '--limit' && isset($argv[$i + 1])) { $limit = (int)$argv[$i + 1]; }
    if (strpos($arg, '--limit=') === 0) { $limit = (int)substr($arg, 8); }
    if ($arg === '--source-id' && isset($argv[$i + 1])) { $sourceId = (int)$argv[$i + 1]; }
    if (strpos($arg, '--source-id=') === 0) { $sourceId = (int)substr($arg, 12); }
}
if ($limit < 0 || $sourceId < 0) {
    fwrite(STDERR, "Error: --limit/--source-id expect non-negative integers.\n");
    exit(1);
}

if ($reset) {
    // Children before parents is irrelevant within a single TRUNCATE
    // statement (all listed tables are truncated together, which satisfies
    // the FK constraints). table_row_counts is a view and recomputes itself;
    // searchstats/processing_log are operational and deliberately kept.
    $resetTables = [
        'laana.sentence_patterns',
        'laana.sentence_metrics',
        'laana.document_metrics',
        'laana.documents',
        'laana.sentences',
        'laana.contents',
        'laana.sources',
    ];
    echo "Reset: truncating corpus tables\n";
    foreach ($resetTables as $t) {
        $n = $pg->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "  {$t}: {$n} rows\n";
    }
    $pg->exec('TRUNCATE TABLE ' . implode(', ', $resetTables));
    try {
        $pg->exec('REFRESH MATERIALIZED VIEW laana.grammar_pattern_counts');
    } catch (Throwable $e) {
        echo "  (could not refresh grammar_pattern_counts: {$e->getMessage()})\n";
    }
    echo "Reset done.\n\n";
}

$sourceSql = 'SELECT sourceID AS sourceid, sourceName AS sourcename, authors, link, created, groupname, title, date '
    . 'FROM sources';
$sourceParams = [];
if ($sourceId > 0) {
    $sourceSql .= ' WHERE sourceID = :sourceid';
    $sourceParams[':sourceid'] = $sourceId;
}
$sourceSql .= ' ORDER BY sourceID';
if ($limit > 0) {
    // Validated non-negative int above; inlined so it can't be a placeholder
    // quirk across MySQL server versions.
    $sourceSql .= ' LIMIT ' . $limit;
}
$sourceStmt = $mysql->prepare($sourceSql);
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

$sourceStmt->execute($sourceParams);
$sources = $sourceStmt->fetchAll();
$totalSources = count($sources);
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
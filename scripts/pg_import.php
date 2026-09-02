<?php
declare(strict_types=1);

/**
 * Unified Postgres importer: repopulate from MySQL + generate all vectors/metrics.
 *
 * Combines:
 *   - repopulate_pg_from_mysql.php  (sources/contents/sentences MySQL -> Postgres)
 *   - pg_indexer.php                (sentence embeddings + sentence/document metrics)
 *   - backfill_pg_doc_vectors_1024.php (contents.embedding_1024 document vectors)
 *
 * Default (no --force): backfill only what is missing — missing sources/sentences/
 * contents are copied from MySQL, and only rows lacking embeddings/metrics/doc
 * vectors are processed.
 *
 * --force: first RESET (truncate the corpus tables), then repopulate everything
 * from MySQL and regenerate every vector and metric from scratch.
 *
 * Each source is processed as ONE unit: its rows are migrated, then its sentence
 * embeddings+metrics, then its document metric + 1024-dim document vector, and
 * only then committed. If interrupted, every source already committed is complete
 * (data + sentence vectors + document vector); the next run resumes with the rest.
 *
 * Options:
 *   --status           Report what a full run would do, then exit (no writes).
 *   --force            Reset corpus tables, then full rebuild.
 *   --dryrun           Do everything except write to Postgres (default is write).
 *   --verbose          Per-source detail.
 *   --quiet            Suppress non-error output.
 *   --sentences        Only process sentences (skip document metrics/vectors).
 *   --documents        Only process documents (skip sentence embeddings/metrics).
 *   --limit=N          Process at most N sources (lowest sourceIDs first).
 *   --source-id=ID     Process only this source.
 *
 * --status ignores every other option: it always describes a full, unfiltered
 * backfill run over the whole corpus, and exits before anything is touched.
 *
 * --sentences and --documents are mutually exclusive; omitting both does both.
 * Source/content rows are always migrated (they are the parents); --sentences /
 * --documents scope only which child vectors/metrics are (re)generated.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db/PostgresFuncs.php';

if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load(__DIR__ . '/../.env');
}

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Env + connections
// ---------------------------------------------------------------------------

function envValue(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $default;
    }
    return (string)$value;
}

function connectMySql(): PDO {
    $host   = envValue('DB_HOST', 'localhost');
    $port   = envValue('DB_PORT', '3306');
    $db     = envValue('DB_DATABASE');
    $user   = envValue('DB_USER');
    $pass   = envValue('DB_PASSWORD');
    $socket = envValue('DB_SOCKET');

    if ($socket !== '') {
        $socket = trim($socket, "\"'");
    }
    if ($db === '') {
        throw new RuntimeException('DB_DATABASE is not set.');
    }

    $config = [
        'host'     => $host,
        'port'     => $port,
        'dbname'   => $db,
        'username' => $user,
        'password' => $pass,
    ];
    if ($socket !== '' && file_exists($socket)) {
        $config['socket'] = $socket;
    }

    return \Common\DB\DBBase::createConnection($config);
}

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------

$status  = in_array('--status', $argv, true);
$force   = in_array('--force', $argv, true);
$dryrun  = in_array('--dryrun', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$quiet   = in_array('--quiet', $argv, true);
$doSentences = in_array('--sentences', $argv, true);
$doDocuments = in_array('--documents', $argv, true);
$limit = 0;
$sourceId = 0;

if ($status) {
    // --status reports on the whole corpus and exits before any work, so every
    // other option is discarded here rather than validated. Clearing --force is
    // what guarantees a status run can never reach the reset below.
    $force = false;
    $dryrun = false;
    $doSentences = true;
    $doDocuments = true;
} else {
    if ($doSentences && $doDocuments) {
        fwrite(STDERR, "Error: --sentences and --documents are mutually exclusive.\n");
        exit(1);
    }
    // Omitting both means both.
    if (!$doSentences && !$doDocuments) {
        $doSentences = true;
        $doDocuments = true;
    }

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
}

function say(string $msg, bool $quiet): void {
    if (!$quiet) { echo $msg; }
}

// ---------------------------------------------------------------------------
// --status report
// ---------------------------------------------------------------------------

/**
 * Count how many sentences a run would insert.
 *
 * Sentence IDs are carried over from MySQL unchanged, so a per-source
 * (row count, sum of sentence IDs) signature identifies sources whose two sides
 * already hold the same rows. Only sources whose signatures disagree — a new or
 * partially imported source, or one that was re-scraped under new IDs — pay for
 * an exact ID diff, which keeps this off the 2.7M-row hot path.
 */
function countSentencesToAdd(PDO $mysql, PDO $pg): int {
    $pgSignature = [];
    $pgRows = $pg->query('SELECT sourceid, COUNT(*) AS n, SUM(sentenceid) AS chk FROM sentences GROUP BY sourceid');
    foreach ($pgRows as $row) {
        $pgSignature[(int)$row['sourceid']] = [(int)$row['n'], (int)$row['chk']];
    }

    $mysqlIds = $mysql->prepare('SELECT sentenceID FROM sentences WHERE sourceID = :sourceid');
    $pgIds    = $pg->prepare('SELECT sentenceid FROM sentences WHERE sourceid = :sourceid');

    $toAdd = 0;
    $mysqlRows = $mysql->query(
        'SELECT sourceID AS sourceid, COUNT(*) AS n, SUM(sentenceID) AS chk FROM sentences GROUP BY sourceID'
    );
    foreach ($mysqlRows as $row) {
        $sid = (int)$row['sourceid'];
        $signature = [(int)$row['n'], (int)$row['chk']];

        if (!isset($pgSignature[$sid])) {   // source absent from Postgres: all new
            $toAdd += $signature[0];
            continue;
        }
        if ($pgSignature[$sid] === $signature) {   // same rows on both sides
            continue;
        }

        $pgIds->execute([':sourceid' => $sid]);
        $present = array_flip(array_map('intval', $pgIds->fetchAll(PDO::FETCH_COLUMN)));
        $mysqlIds->execute([':sourceid' => $sid]);
        foreach ($mysqlIds->fetchAll(PDO::FETCH_COLUMN) as $sentenceId) {
            if (!isset($present[(int)$sentenceId])) { $toAdd++; }
        }
    }

    return $toAdd;
}

/**
 * Print what a full backfill run would do, without touching anything.
 *
 * Rows that would be inserted are reported separately from rows that already
 * exist in Postgres and are missing vectors or metrics; a run does both.
 */
function printStatusReport(PDO $mysql, PDO $pg): void {
    // Documents to insert: MySQL contents rows with no Postgres counterpart.
    // Only rows carrying text go on to earn a vector and metrics.
    $present = array_flip(array_map(
        'intval',
        $pg->query('SELECT sourceid FROM contents')->fetchAll(PDO::FETCH_COLUMN)
    ));
    $docsToAdd = 0;
    $docsToAddWithText = 0;
    $mysqlDocs = $mysql->query(
        'SELECT sourceID AS sourceid, (text IS NOT NULL AND LENGTH(text) > 0) AS has_text FROM contents'
    );
    foreach ($mysqlDocs as $row) {
        if (isset($present[(int)$row['sourceid']])) { continue; }
        $docsToAdd++;
        if ((int)$row['has_text'] === 1) { $docsToAddWithText++; }
    }

    $sentencesToAdd = countSentencesToAdd($mysql, $pg);

    // Existing sentences the run would re-embed. It embeds every row it picks
    // up, so a sentence that only lacks metrics still gets a fresh vector.
    $sentenceWork = $pg->query(
        'SELECT COUNT(*) FILTER (WHERE s.embedding IS NULL) AS missing_vector, '
        . 'COUNT(*) FILTER (WHERE m.sentenceid IS NULL) AS missing_metrics, '
        . 'COUNT(*) FILTER (WHERE s.embedding IS NULL OR m.sentenceid IS NULL) AS total '
        . 'FROM sentences s LEFT JOIN sentence_metrics m ON m.sentenceid = s.sentenceid '
        . 'WHERE s.hawaiiantext IS NOT NULL AND octet_length(s.hawaiiantext) > 0'
    )->fetch(PDO::FETCH_ASSOC);

    // Existing documents missing a 1024-dim vector or their metrics row.
    $documentWork = $pg->query(
        'SELECT COUNT(*) FILTER (WHERE c.embedding_1024 IS NULL) AS missing_vector, '
        . 'COUNT(*) FILTER (WHERE m.sourceid IS NULL OR m.entity_count < 0) AS missing_metrics '
        . 'FROM contents c LEFT JOIN document_metrics m ON m.sourceid = c.sourceid '
        . 'WHERE c.text IS NOT NULL AND octet_length(c.text) > 0'
    )->fetch(PDO::FETCH_ASSOC);

    $line = static function (string $label, int $count): void {
        printf("%-40s %10s\n", $label, number_format($count));
    };

    echo "Status: what a full run would do\n" . str_repeat('=', 51) . "\n";
    $line('Documents to add:', $docsToAdd);
    if ($docsToAdd !== $docsToAddWithText) {
        printf("%-40s %10s\n", '  of those, with text:', number_format($docsToAddWithText));
    }
    $line('Sentences to add:', $sentencesToAdd);
    $line('Existing sentences to get vectors:', (int)$sentenceWork['total']);
    if ((int)$sentenceWork['total'] > 0) {
        printf(
            "%-40s %10s\n",
            '  missing vector / missing metrics:',
            number_format((int)$sentenceWork['missing_vector']) . ' / '
                . number_format((int)$sentenceWork['missing_metrics'])
        );
    }
    $line('Existing documents to get vectors:', (int)$documentWork['missing_vector']);
    $line('Existing documents to get metrics:', (int)$documentWork['missing_metrics']);
    echo "\n(Status only — nothing was written.)\n";
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

$mysql = connectMySql();

// PostgresLaana owns the Postgres connection on the laana schema; reuse it so the
// embedding writes and the corpus writes share one transaction per source.
$pgLaana = new PostgresLaana();
if (!$pgLaana->conn) {
    fwrite(STDERR, "ERROR: Postgres connection failed.\n");
    exit(1);
}
$pg = $pgLaana->conn;
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Report and stop, before the embedding clients are built (a status run must not
// depend on the embedding service) and before --force could reset anything.
if ($status) {
    printStatusReport($mysql, $pg);
    exit(0);
}

say("Unified Postgres Import\n", $quiet);
say("=======================\n", $quiet);
say("Mode:       " . ($dryrun ? "DRY RUN (no writes)" : "WRITE") . "\n", $quiet);
say("Rebuild:    " . ($force ? "YES (reset + full rebuild)" : "NO (backfill missing)") . "\n", $quiet);
$scopeLabel = ($doSentences && $doDocuments) ? "sentences + documents"
    : ($doSentences ? "sentences only" : "documents only");
say("Processing: {$scopeLabel}\n", $quiet);
if ($sourceId > 0) { say("Source:     {$sourceId}\n", $quiet); }
if ($limit > 0)    { say("Limit:      {$limit} sources\n", $quiet); }
say("\n", $quiet);

// ---------------------------------------------------------------------------
// Reset (only under --force). Guarded so a dry run never destroys data.
// ---------------------------------------------------------------------------

if ($force) {
    // All corpus tables truncated together (FK-safe within one statement).
    // searchstats/processing_log are operational and deliberately preserved.
    $resetTables = [
        'laana.sentence_patterns',
        'laana.sentence_metrics',
        'laana.document_metrics',
        'laana.documents',
        'laana.sentences',
        'laana.contents',
        'laana.sources',
    ];
    if ($dryrun) {
        say("Reset (dryrun — no truncate performed):\n", $quiet);
        foreach ($resetTables as $t) {
            $n = $pg->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            say("  would truncate {$t}: {$n} rows\n", $quiet);
        }
        say("\n", $quiet);
    } else {
        say("Reset: truncating corpus tables\n", $quiet);
        foreach ($resetTables as $t) {
            $n = $pg->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            say("  {$t}: {$n} rows\n", $quiet);
        }
        $pg->exec('TRUNCATE TABLE ' . implode(', ', $resetTables));
        say("Reset done.\n\n", $quiet);
    }
}

// ---------------------------------------------------------------------------
// Source selection (MySQL)
// ---------------------------------------------------------------------------

// MySQL reads
$sourceSql = 'SELECT sourceID AS sourceid, sourceName AS sourcename, authors, link, created, groupname, title, date '
    . 'FROM sources';
$sourceParams = [];
if ($sourceId > 0) {
    $sourceSql .= ' WHERE sourceID = :sourceid';
    $sourceParams[':sourceid'] = $sourceId;
}
$sourceSql .= ' ORDER BY sourceID';
if ($limit > 0) {
    // Inlined (validated non-negative int); LIMIT placeholders are unreliable
    // across MySQL server/emulation settings.
    $sourceSql .= ' LIMIT ' . $limit;
}
$sourceStmt = $mysql->prepare($sourceSql);

// ---------------------------------------------------------------------------
// Per-source pipeline (extracted from this script into
// providers/Postgres/PostgresSourcePipeline.php). One processSource() call =
// one Postgres transaction; the source is a complete unit on commit.
// ---------------------------------------------------------------------------

$pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline([
    'pg'        => $pg,
    'mysql'     => $mysql,
    'pgLaana'   => $pgLaana,
    'dryrun'    => $dryrun,
    'force'     => $force,
    'sentences' => $doSentences,
    'documents' => $doDocuments,
]);

// ---------------------------------------------------------------------------
// Main loop — one transaction per source, complete unit on commit.
// ---------------------------------------------------------------------------

$sourceStmt->execute($sourceParams);
$sources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
$totalSources = count($sources);
$index = 0;

$totals = [
    'sources'          => 0,
    'sentences_data'   => 0,
    'sentence_vectors' => 0,
    'sentence_metrics' => 0,
    'document_metrics' => 0,
    'document_vectors' => 0,
    'errors'           => 0,
    'skipped'          => 0,
];

foreach ($sources as $source) {
    $index++;
    $sid = (int)$source['sourceid'];
    $group = $source['groupname'] ?? 'N/A';
    say("[{$index}/{$totalSources}] sourceID={$sid} group={$group}\n", $quiet);

    try {
        $out = $pipeline->processSource($sid);
        $totals['sources']++;
        $totals['sentences_data']   += $out['sentences_data'];
        $totals['sentence_vectors'] += $out['sentence_vectors'];
        $totals['sentence_metrics'] += $out['sentence_metrics'];
        $totals['document_metrics'] += $out['document_metrics'];
        $totals['document_vectors'] += $out['document_vectors'];

        if ($verbose && !$quiet) {
            echo "  data: {$out['sentences_data']} sentences, content=" . ($out['has_content'] ? 'yes' : 'no')
               . " | vectors: sent={$out['sentence_vectors']} doc={$out['document_vectors']}"
               . " | metrics: sent={$out['sentence_metrics']} doc={$out['document_metrics']}"
               . ($dryrun ? " (dryrun, rolled back)" : "") . "\n";
        }
    } catch (Throwable $e) {
        if ($pg->inTransaction()) {
            $pg->rollBack();
        }
        $totals['errors']++;
        fwrite(STDERR, "  ERROR sourceID={$sid}: {$e->getMessage()}\n");
    }

    if (function_exists('flush')) { flush(); }
}

// Counts policy: the materialized view is refreshed exactly once per run,
// outside any transaction (REFRESH ... CONCURRENTLY cannot run inside one).
if (!$dryrun && $pipeline->refreshGrammarPatternCounts()) {
    say("grammar_pattern_counts refreshed.\n", $quiet);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

if (!$quiet) {
    echo "\nSummary\n" . str_repeat('=', 40) . "\n";
    echo "Sources processed:  {$totals['sources']} / {$totalSources}\n";
    echo "Sentences migrated: {$totals['sentences_data']}\n";
    echo "Sentence vectors:   {$totals['sentence_vectors']}\n";
    echo "Sentence metrics:   {$totals['sentence_metrics']}\n";
    echo "Document metrics:   {$totals['document_metrics']}\n";
    echo "Document vectors:   {$totals['document_vectors']}\n";
    echo "Errors:             {$totals['errors']}\n";
    if ($dryrun) {
        echo "\n(DRY RUN — all transactions rolled back, no data written.)\n";
    }
}

exit($totals['errors'] > 0 ? 1 : 0);

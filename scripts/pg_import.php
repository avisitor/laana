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
 * In --force mode the derivative search indexes on laana.sentences (the
 * ivfflat embedding index and the two GIN full-text indexes) are dropped
 * before loading and recreated at the end: the import never reads them, but
 * every sentence rewrite pays for them. Interruption safety: SIGINT/SIGTERM
 * recreate missing indexes before exiting, a shutdown hook covers fatal
 * errors, uncatchable deaths are repaired by the startup check of the next
 * run, and normal completion always ensures they exist.
 *
 * A schema sanity check runs before any work (all modes, --status included):
 * the corpus tables and the grammar_pattern_counts materialized view must
 * exist, otherwise the script exits 1 before touching anything.
 *
 * Options:
 *   --status           Report what a full run would do, then exit (no writes).
 *   --force            Reset corpus tables, then full rebuild.
 *   --recreate         Alias for --force (createindex.php terminology).
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
    // Fail loudly when the MySQL read-side connection config is missing
    // instead of silently connecting to localhost with empty credentials.
    $host   = \Noiiolelo\EnvConfig::firstEnv('MySQL host', ['DB_HOST']);
    $port   = \Noiiolelo\EnvConfig::firstEnv('MySQL port', ['DB_PORT']);
    $db     = \Noiiolelo\EnvConfig::firstEnv('MySQL database', ['DB_DATABASE']);
    $user   = \Noiiolelo\EnvConfig::firstEnv('MySQL user', ['DB_USER']);
    $pass   = \Noiiolelo\EnvConfig::firstEnv('MySQL password', ['DB_PASSWORD']);
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

// Corpus tables every run reads and writes. Single source of truth for the
// schema sanity check below and the --force reset. The legacy documents
// table is deliberately absent: it was dropped by
// db/migrations/2026-09-01-drop-dead-vector-columns.sql.
$corpusTables = [
    'laana.sentence_patterns',
    'laana.sentence_metrics',
    'laana.document_metrics',
    'laana.sentences',
    'laana.contents',
    'laana.sources',
];

/**
 * Fail loudly if any required Postgres object is missing from the laana
 * schema: the corpus tables above plus the grammar_pattern_counts
 * materialized view the run refreshes at the end. Runs before any work
 * (including --status) so schema drift exits fast instead of mid-run.
 */
function verifyRequiredTables(PDO $pg, array $tables): void {
    $names = [];
    foreach ($tables as $qualified) {
        $names[] = substr($qualified, strlen('laana.'));
    }
    $placeholders = implode(', ', array_fill(0, count($names), '?'));
    $stmt = $pg->prepare(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = 'laana' AND table_name IN ($placeholders)"
    );
    $stmt->execute($names);
    $present = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $missing = [];
    foreach ($names as $name) {
        if (!isset($present[$name])) { $missing[] = "laana.{$name}"; }
    }

    // Materialized views are absent from information_schema.tables.
    $stmt = $pg->prepare(
        "SELECT 1 FROM pg_matviews WHERE schemaname = 'laana' AND matviewname = :name"
    );
    $stmt->execute([':name' => 'grammar_pattern_counts']);
    if (!$stmt->fetchColumn()) {
        $missing[] = 'laana.grammar_pattern_counts (materialized view)';
    }

    if ($missing !== []) {
        fwrite(STDERR, "ERROR: missing required Postgres objects in the laana schema:\n");
        foreach ($missing as $name) {
            fwrite(STDERR, "  - {$name}\n");
        }
        fwrite(STDERR, "Apply pending migrations in db/migrations/ before running this script.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------

$status  = in_array('--status', $argv, true);
$force   = in_array('--force', $argv, true);
// --recreate: alias for --force, matching createindex.php terminology.
if (in_array('--recreate', $argv, true)) { $force = true; }
$dryrun  = in_array('--dryrun', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$quiet   = in_array('--quiet', $argv, true);
$doSentences = in_array('--sentences', $argv, true);
$doDocuments = in_array('--documents', $argv, true);
$help    = in_array('--help', $argv, true);
$limit = 0;
$sourceId = 0;

/**
 * Print the usage/help message.
 */
function printUsage(): void {
    $usage = <<<USAGE
Unified Postgres Import — fills the Postgres laana schema from MySQL and
generates every derived vector and metric (sentence embeddings, sentence and
document metrics, 1024-dim document vectors, grammar patterns).

Usage:
  php scripts/pg_import.php [options]

Options:
  (no options)               Incremental backfill: copy missing sources/contents/
                             sentences from MySQL and process existing rows
                             lacking embeddings/metrics/doc vectors
  --status                   Print what a full run would do, then exit (read-only)
  --dryrun                   Everything except Postgres writes (transactions
                             rolled back)
  --force                    First truncate the corpus tables (sources, contents,
                             sentences, sentence_metrics, document_metrics,
                             sentence_patterns), then rebuild
                             everything from scratch. Guarded: --dryrun never
                             truncates
  --recreate                 Alias for --force (createindex.php terminology)
  --sentences                Only sentence embeddings/metrics (parents still
                             migrate)
  --documents                Only document metrics/vectors (parents still
                             migrate)
  --limit=N                  Process at most N sources (lowest sourceIDs first)
  --source-id=ID             Process only this source
  --verbose                  Per-source detail output
  --quiet                    Suppress non-error output
  --help                     Show this help message

Notes:
  --sentences and --documents are mutually exclusive; omitting both does both.
  Each source is processed as ONE unit inside a single Postgres transaction.
  Grammar patterns are scanned per source and grammar_pattern_counts is
  refreshed once at the end of a write run.
  In --force mode the derivative search indexes on laana.sentences (the
  ivfflat embedding index and the two GIN full-text indexes) are dropped
  for the load and recreated at the end (the ivfflat rebuild can take a
  while on a full corpus). Interrupted runs are repaired: SIGINT/SIGTERM
  recreate the indexes before exiting, and the next run recreates any
  still missing at startup.
  A schema check runs first: the required laana corpus tables and the
  grammar_pattern_counts materialized view must exist, otherwise the script
  exits 1 before touching anything.

Examples:
  php scripts/pg_import.php --status
  php scripts/pg_import.php --dryrun
  php scripts/pg_import.php --source-id=52441
  php scripts/pg_import.php --limit=50
  php scripts/pg_import.php --force

Exit codes:
  0  success (including --status and --help)
  1  bad options, connection failure, or one or more sources failed

Connection settings come from .env: DB_* (MySQL source), PG_* (Postgres
target), EMBEDDING_SERVICE_URL. See docs/INGESTION.md for details.

USAGE;
    echo $usage;
}

if ($help) {
    printUsage();
    exit(0);
}

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

// Fail fast on schema drift before any work (a status run included).
verifyRequiredTables($pg, $corpusTables);
say("Schema:     OK (" . count($corpusTables) . " corpus tables + grammar_pattern_counts matview)\n", $quiet);

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
// Derivative search indexes (ivfflat + 2 GIN on laana.sentences).
//
// Search-only indexes the import never reads, but every sentence rewrite
// pays for them: each non-HOT UPDATE inserts into the ivfflat index
// (scanning every IVF centroid) and both GIN indexes. --force drops them
// for the load and recreates them at the end. The drop targets are
// discovered live and each dropped definition is captured to
// logs/pg-import-index-state.json BEFORE it is dropped; recreation
// restores exactly what was captured (SentenceSearchIndexManager).
// Every interruption path recreates whatever is missing:
//   * SIGINT/SIGTERM handler below (Ctrl-C, kill)
//   * shutdown function (fatal errors)
//   * the startup check here (a previous run died uncatchably)
//   * the end-of-run ensure (normal completion)
// ---------------------------------------------------------------------------

$indexManager = new \Noiiolelo\Providers\Postgres\SentenceSearchIndexManager($pg);
$indexesDroppedByThisRun = false;

$recreateIndexes = static function () use ($indexManager): void {
    foreach ($indexManager->ensureAll() as $name) {
        fwrite(STDERR, "  recreated index {$name}\n");
    }
};

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = static function (int $sig) use ($pg, $recreateIndexes): void {
        fwrite(STDERR, "\nInterrupted (" . ($sig === SIGINT ? 'SIGINT' : 'SIGTERM')
            . ") — recreating missing derivative indexes before exit\n");
        if ($pg->inTransaction()) {
            $pg->rollBack();
        }
        try {
            $recreateIndexes();
        } catch (Throwable $e) {
            fwrite(STDERR, 'WARNING: could not recreate derivative indexes: ' . $e->getMessage() . "\n");
            fwrite(STDERR, "The next pg_import run will repair them at startup.\n");
        }
        exit($sig === SIGINT ? 130 : 143);
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);
}

// Fatal-error safety net: if this run dropped the indexes and died before
// the end-of-run recreate, rebuild them while shutting down. No-op on
// normal exits (the indexes are present by then) and in dryrun (nothing
// was ever dropped).
register_shutdown_function(static function () use (&$indexesDroppedByThisRun, $indexManager, $recreateIndexes): void {
    if (!$indexesDroppedByThisRun) {
        return;
    }
    try {
        $missing = $indexManager->missing();
        if ($missing === []) {
            return;
        }
        fwrite(STDERR, "\nRecreating derivative indexes left missing by this aborted run: "
            . implode(', ', $missing) . "\n");
        $recreateIndexes();
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARNING: shutdown recreate of derivative indexes failed: ' . $e->getMessage() . "\n");
        fwrite(STDERR, "The next pg_import run will repair them at startup.\n");
    }
});

// Repair path for uncatchable deaths (SIGKILL, power loss) of a previous
// --force run: any non-force run recreates missing indexes up front. A
// following --force run instead drops them again and recreates at the end.
if (!$force) {
    $missing = $indexManager->missing();
    if ($missing !== []) {
        if ($dryrun) {
            fwrite(STDERR, "note: derivative search indexes are missing (previous run interrupted); "
                . "--dryrun leaves them untouched\n");
        } else {
            fwrite(STDERR, 'WARNING: derivative search indexes are missing — a previous run was interrupted '
                . 'before recreating them: ' . implode(', ', $missing) . "\n");
            fwrite(STDERR, "Recreating now (the ivfflat build can take a while on a large corpus)...\n");
            $recreateIndexes();
        }
    }
}

// ---------------------------------------------------------------------------
// Reset (only under --force). Guarded so a dry run never destroys data.
// ---------------------------------------------------------------------------

if ($force) {
    // All corpus tables truncated together (FK-safe within one statement);
    // the same list the schema sanity check validated above.
    // searchstats/processing_log are operational and deliberately preserved.
    $resetTables = $corpusTables;
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
        say("Reset done.\n", $quiet);

        // Bulk-load window: derivative search indexes are pure write tax
        // here (see the block comment above). Recreated at end of run; the
        // signal/shutdown hooks repair an interrupted load.
        $dropped = $indexManager->dropAll();
        $indexesDroppedByThisRun = $dropped !== [];
        say("Dropped derivative search indexes: "
            . ($dropped === [] ? '(none present)' : implode(', ', $dropped)) . "\n\n", $quiet);
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

// Bulk incremental loads pay the same per-row index tax as --force (every
// backlog INSERT maintains the ivfflat/GIN indexes and recomputes the
// generated tsvector columns), so when the pending migration is large the
// indexes are dropped for the load too. Same capture/recreate safety net
// as --force: definitions stashed before the drop, recreated at the end,
// repaired after an interruption.
$bulkIndexDropSentenceThreshold = 100000;
if (!$force && !$dryrun && $pipeline->pendingSentenceMigration() >= $bulkIndexDropSentenceThreshold) {
    $dropped = $indexManager->dropAll();
    $indexesDroppedByThisRun = $dropped !== [];
    say("Pending incremental migration is large — dropped derivative search indexes for the load: "
        . ($dropped === [] ? '(none present)' : implode(', ', $dropped)) . "\n", $quiet);
}

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
    'patterns'         => 0,
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
        $totals['patterns']         += $out['patterns'];

        if ($verbose && !$quiet) {
            echo "  data: {$out['sentences_data']} sentences, content=" . ($out['has_content'] ? 'yes' : 'no')
               . " | vectors: sent={$out['sentence_vectors']} doc={$out['document_vectors']}"
               . " | metrics: sent={$out['sentence_metrics']} doc={$out['document_metrics']}"
               . ($out['skipped_data'] ? ' | already mirrored, data upserts skipped' : '')
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

// Derivative search indexes: recreate whatever the bulk load dropped
// (no-op when the indexes were never dropped). --dryrun never touches
// them. A failure counts as a run error: search would be degraded.
if (!$dryrun) {
    try {
        $created = $indexManager->ensureAll();
        if ($created !== []) {
            say("Derivative search indexes recreated: " . implode(', ', $created) . "\n", $quiet);
        }
    } catch (Throwable $e) {
        $totals['errors']++;
        fwrite(STDERR, 'ERROR: recreating derivative search indexes failed: ' . $e->getMessage() . "\n");
    }
}

// Counts policy: the materialized view is refreshed exactly once per run,
// outside any transaction (REFRESH ... CONCURRENTLY cannot run inside one).
if (!$dryrun && $pipeline->refreshGrammarPatternCounts()) {
    say("grammar_pattern_counts refreshed.\n", $quiet);
} elseif (!$dryrun) {
    fwrite(STDERR, "warning: could not refresh grammar_pattern_counts\n");
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
    echo "Patterns scanned:   {$totals['patterns']}\n";
    echo "Document metrics:   {$totals['document_metrics']}\n";
    echo "Document vectors:   {$totals['document_vectors']}\n";
    echo "Errors:             {$totals['errors']}\n";
    if ($dryrun) {
        echo "\n(DRY RUN — all transactions rolled back, no data written.)\n";
    }
}

exit($totals['errors'] > 0 ? 1 : 0);

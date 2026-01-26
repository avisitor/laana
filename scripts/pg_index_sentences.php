<?php
// Safe runner scaffold: Postgres-only sentence indexing, dry-run by default.

require_once __DIR__ . '/../providers/Postgres/PostgresClient.php';
require_once __DIR__ . '/../providers/Postgres/PostgresSentenceIndexer.php';

use Noiiolelo\Providers\Postgres\PostgresClient;
use Noiiolelo\Providers\Postgres\PostgresSentenceIndexer;

$config = [
    'verbose' => in_array('--verbose', $argv, true),
    'quiet' => in_array('--quiet', $argv, true),
    'PG_DSN' => getenv('PG_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=noiiolelo',
    'PG_USER' => getenv('PG_USER') ?: 'laana',
    'PG_PASSWORD' => getenv('PG_PASSWORD') ?: '',
    'EMBEDDING_ENDPOINT' => getenv('EMBEDDING_ENDPOINT') ?: '',
    // Sentence-first processing, no Elasticsearch dependencies
    'SPLIT_INDICES' => true,
    'dryrun' => !in_array('--write', $argv, true), // default dryrun; enable writes with --write
    'BATCH_SIZE' => 25,
    'MAX_RECORDS' => (function($argv){
        foreach ($argv as $i => $arg) {
            if ($arg === '--limit' && isset($argv[$i+1])) { return (int)$argv[$i+1]; }
            if (strpos($arg, '--limit=') === 0) { return (int)substr($arg, 8); }
        }
        return 0;
    })($argv),
];

// Optional: write processed IDs snapshot to a file
$idsOut = null;
// Optional: write full JSON summary (ids + timing + counts)
$outJson = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--ids-out' && isset($argv[$i+1])) { $idsOut = $argv[$i+1]; }
    if (strpos($arg, '--ids-out=') === 0) { $idsOut = substr($arg, 10); }
    if ($arg === '--out-json' && isset($argv[$i+1])) { $outJson = $argv[$i+1]; }
    if (strpos($arg, '--out-json=') === 0) { $outJson = substr($arg, 11); }
}

// Report totals before running: total sentences and missing embeddings (candidates)
$pgClient = new PostgresClient($config);
$total = $pgClient->countTotalSentences();
$missing = $pgClient->countMissingEmbeddings();
$willProcess = $config['MAX_RECORDS'] > 0 ? min($missing, (int)$config['MAX_RECORDS']) : $missing;
echo "Totals: all_records={$total}, without_embeddings={$missing}, will_process={$willProcess}\n";

// Pass planned total and out_json to indexer for progress reporting
$config['WILL_PROCESS'] = $willProcess;
$config['out_json'] = $outJson;

// Precompute full intent IDs list (guard against huge memory usage)
$intentCap = (int)(getenv('INTENT_IDS_CAP') ?: 100000);
$intentIds = [];
if ($willProcess > 0 && $willProcess <= $intentCap) {
    $intentIds = $pgClient->fetchCandidateSentenceIds($willProcess);
}
$config['INTENT_IDS'] = $intentIds;

// If out_json provided, write initial snapshot with intent list or count only
if ($outJson) {
    $dir = dirname($outJson);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $initial = [
        'intent' => ['count' => $willProcess, 'ids' => $intentIds],
        'progress' => ['processed' => 0, 'total' => $willProcess],
        'created_at' => date('c'),
    ];
    file_put_contents($outJson, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Initialize sentence-first indexer (pass out_json for live progress updates)
$runner = new PostgresSentenceIndexer($config);

echo ($config['dryrun'] ? "Dry-run: compute embeddings + metrics, no DB writes\n" : "Write mode: embeddings + full metrics will be upserted\n");
//echo "Use --write to enable upserts after validation.\n";

// Run with error guard to ensure premature exit reasons are surfaced
try {
    $summary = $runner->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Premature exit: unhandled exception in runner: ' . $e->getMessage() . "\n");
    $summary = [
        'processed' => 0,
        'embeddings' => 0,
        'metrics' => 0,
        'errors' => 0,
        'timing' => ['embed_ms'=>0,'metrics_ms'=>0,'db_ms'=>0,'total_ms'=>0],
        'ids' => [],
    ];
}

// If we exited before processing intended total, report to stderr
if (($config['WILL_PROCESS'] ?? 0) > 0 && ($summary['processed'] ?? 0) < ($config['WILL_PROCESS'] ?? 0)) {
    fwrite(STDERR, sprintf(
        "Premature exit: processed %d / %d intended.\n",
        (int)($summary['processed'] ?? 0), (int)($config['WILL_PROCESS'] ?? 0)
    ));
}

// Persist IDs snapshot if requested
if ($idsOut && !empty($summary['ids'])) {
    $dir = dirname($idsOut);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    file_put_contents($idsOut, implode("\n", array_map('strval', $summary['ids'])) . "\n");
    echo "Wrote processed IDs: " . count($summary['ids']) . " -> $idsOut\n";
}

// Persist final JSON summary if requested
if ($outJson) {
    $dir = dirname($outJson);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $out = [
        'processed' => $summary['processed'] ?? 0,
        'embeddings' => $summary['embeddings'] ?? 0,
        'metrics' => $summary['metrics'] ?? 0,
        'errors' => $summary['errors'] ?? 0,
        'timing' => $summary['timing'] ?? [],
        'ids' => $summary['ids'] ?? [],
        'created_at' => date('c'),
    ];
    file_put_contents($outJson, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Wrote JSON summary -> $outJson\n";
}

$t = $summary['timing'] ?? ['embed_ms'=>0,'metrics_ms'=>0,'db_ms'=>0,'total_ms'=>0];
$elapsed_ms = (int)($t['total_ms'] ?? 0);
$embed_ms = (int)($t['embed_ms'] ?? 0);
$metrics_ms = (int)($t['metrics_ms'] ?? 0);
$db_ms = (int)($t['db_ms'] ?? 0);
$proc = max(1, (int)($summary['processed'] ?? 0));
$eps = $elapsed_ms > 0 ? sprintf('%.1f', ($proc / ($elapsed_ms / 1000.0))) : 'n/a';
echo "Processed: {$summary['processed']}, embeddings: {$summary['embeddings']}, metrics: {$summary['metrics']}, errors: {$summary['errors']}\n";
echo "Timing: total={$elapsed_ms}ms (embed={$embed_ms}ms, metrics={$metrics_ms}ms, db={$db_ms}ms), throughput={$eps} sent/s\n";

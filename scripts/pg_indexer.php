<?php
// Unified Postgres indexer for sentences and documents.
// Default: index only missing records (incremental).
// Use --force to reindex all records.
// Use --sentences or --documents to process only one type (default: both).

require_once __DIR__ . '/../lib/PostgresClient.php';
require_once __DIR__ . '/../lib/PostgresSentenceIndexer.php';
require_once __DIR__ . '/../lib/PostgresDocumentIndexer.php';

use HawaiianSearch\PostgresClient;
use HawaiianSearch\PostgresSentenceIndexer;
use HawaiianSearch\PostgresDocumentIndexer;

// Parse arguments
$args = [
    'verbose' => in_array('--verbose', $argv, true),
    'quiet' => in_array('--quiet', $argv, true),
    'dryrun' => !in_array('--write', $argv, true),
    'force' => in_array('--force', $argv, true),
    'sentences' => in_array('--sentences', $argv, true),
    'documents' => in_array('--documents', $argv, true),
];

// If neither --sentences nor --documents is specified, do both
if (!$args['sentences'] && !$args['documents']) {
    $args['sentences'] = true;
    $args['documents'] = true;
}

// Parse limit
$limit = 0;
foreach ($argv as $i => $arg) {
    if ($arg === '--limit' && isset($argv[$i+1])) { $limit = (int)$argv[$i+1]; }
    if (strpos($arg, '--limit=') === 0) { $limit = (int)substr($arg, 8); }
}

// Parse output files
$idsOut = null;
$outJson = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--ids-out' && isset($argv[$i+1])) { $idsOut = $argv[$i+1]; }
    if (strpos($arg, '--ids-out=') === 0) { $idsOut = substr($arg, 10); }
    if ($arg === '--out-json' && isset($argv[$i+1])) { $outJson = $argv[$i+1]; }
    if (strpos($arg, '--out-json=') === 0) { $outJson = substr($arg, 11); }
}

// Load environment variables
$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[$parts[0]] = $parts[1];
        }
    }
}

// Configuration
$config = [
    'verbose' => $args['verbose'],
    'quiet' => $args['quiet'],
    'PG_DSN' => getenv('PG_DSN') ?: ($env['PG_DSN'] ?? 'pgsql:host=' . ($env['PG_HOST'] ?? 'localhost') . ';port=' . ($env['PG_PORT'] ?? '5432') . ';dbname=' . ($env['PG_DATABASE'] ?? 'noiiolelo')),
    'PG_USER' => getenv('PG_USER') ?: ($env['PG_USER'] ?? 'laana'),
    'PG_PASSWORD' => getenv('PG_PASSWORD') ?: ($env['PG_PASSWORD'] ?? ''),
    'EMBEDDING_SERVICE_URL' => getenv('EMBEDDING_SERVICE_URL') ?: ($env['EMBEDDING_SERVICE_URL'] ?? 'http://localhost:5000'),
    'SPLIT_INDICES' => true,
    'dryrun' => $args['dryrun'],
    'BATCH_SIZE' => 25,
    'MAX_RECORDS' => $limit,
];

if (!$args['quiet']) {
    echo "Unified Postgres Indexer\n";
    echo "========================\n";
    echo "Mode: " . ($args['dryrun'] ? "DRY RUN (no writes)" : "WRITE MODE") . "\n";
    echo "Force: " . ($args['force'] ? "YES (reindex all)" : "NO (incremental)") . "\n";
    echo "Processing: ";
    $types = [];
    if ($args['sentences']) $types[] = "sentences";
    if ($args['documents']) $types[] = "documents";
    echo implode(", ", $types) . "\n";
    if ($limit > 0) echo "Limit: $limit records\n";
    echo "\n";
}

// Initialize client
$pgClient = new PostgresClient($config);

// Summary results
$totalResults = [
    'sentences' => ['processed' => 0, 'embeddings' => 0, 'metrics' => 0, 'errors' => 0, 'timing' => []],
    'documents' => ['processed' => 0, 'embeddings' => 0, 'metrics' => 0, 'errors' => 0, 'timing' => []],
];

// Process sentences
if ($args['sentences']) {
    if (!$args['quiet']) echo "Processing Sentences\n" . str_repeat("-", 40) . "\n";
    
    $totalSentences = $pgClient->countTotalSentences();
    $missingSentences = $pgClient->countMissingEmbeddings();
    $willProcessSentences = $limit > 0 ? min($missingSentences, $limit) : $missingSentences;
    
    if (!$args['quiet']) {
        echo "Total sentences: $totalSentences\n";
        echo "Missing embeddings/metrics: $missingSentences\n";
        echo "Will process: $willProcessSentences\n\n";
    }
    
    if ($willProcessSentences > 0 || $args['force']) {
        $sentenceConfig = $config;
        $sentenceConfig['WILL_PROCESS'] = $willProcessSentences;
        $sentenceConfig['out_json'] = $outJson ? dirname($outJson) . '/sentences_' . basename($outJson) : null;
        
        // Precompute intent IDs if reasonable
        $intentCap = (int)(getenv('INTENT_IDS_CAP') ?: 100000);
        $intentIds = [];
        if ($willProcessSentences > 0 && $willProcessSentences <= $intentCap) {
            $intentIds = $pgClient->fetchCandidateSentenceIds($willProcessSentences);
        }
        $sentenceConfig['INTENT_IDS'] = $intentIds;
        
        // Write initial snapshot
        if ($sentenceConfig['out_json']) {
            $dir = dirname($sentenceConfig['out_json']);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $initial = [
                'intent' => ['count' => $willProcessSentences, 'ids' => $intentIds],
                'progress' => ['processed' => 0, 'total' => $willProcessSentences],
                'created_at' => date('c'),
            ];
            file_put_contents($sentenceConfig['out_json'], json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        $sentenceIndexer = new PostgresSentenceIndexer($sentenceConfig);
        
        try {
            $sentenceResults = $sentenceIndexer->run();
            $totalResults['sentences'] = $sentenceResults;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error processing sentences: ' . $e->getMessage() . "\n");
            $totalResults['sentences']['errors'] = 1;
        }
        
        if (!$args['quiet']) {
            $t = $sentenceResults['timing'] ?? ['total_ms'=>0];
            echo "\nSentence Results:\n";
            echo "  Processed: {$sentenceResults['processed']}\n";
            echo "  Embeddings: {$sentenceResults['embeddings']}\n";
            echo "  Metrics: {$sentenceResults['metrics']}\n";
            echo "  Errors: {$sentenceResults['errors']}\n";
            echo "  Time: " . number_format($t['total_ms'] ?? 0, 1) . "ms\n\n";
        }
    } else {
        if (!$args['quiet']) echo "No sentences to process.\n\n";
    }
}

// Process documents
if ($args['documents']) {
    if (!$args['quiet']) echo "Processing Documents\n" . str_repeat("-", 40) . "\n";
    
    $totalDocuments = $pgClient->countTotalDocuments();
    $missingDocuments = $pgClient->countMissingDocumentEmbeddings();
    $willProcessDocuments = $limit > 0 ? min($missingDocuments, $limit) : $missingDocuments;
    
    if (!$args['quiet']) {
        echo "Total documents: $totalDocuments\n";
        echo "Missing embeddings/metrics: $missingDocuments\n";
        echo "Will process: $willProcessDocuments\n\n";
    }
    
    if ($willProcessDocuments > 0 || $args['force']) {
        $documentConfig = $config;
        $documentConfig['WILL_PROCESS'] = $willProcessDocuments;
        $documentConfig['out_json'] = $outJson ? dirname($outJson) . '/documents_' . basename($outJson) : null;
        
        // Precompute intent IDs if reasonable
        $intentCap = (int)(getenv('INTENT_IDS_CAP') ?: 100000);
        $intentIds = [];
        if ($willProcessDocuments > 0 && $willProcessDocuments <= $intentCap) {
            $intentIds = $pgClient->fetchCandidateDocumentIds($willProcessDocuments);
        }
        $documentConfig['INTENT_IDS'] = $intentIds;
        
        // Write initial snapshot
        if ($documentConfig['out_json']) {
            $dir = dirname($documentConfig['out_json']);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $initial = [
                'intent' => ['count' => $willProcessDocuments, 'ids' => $intentIds],
                'progress' => ['processed' => 0, 'total' => $willProcessDocuments],
                'created_at' => date('c'),
            ];
            file_put_contents($documentConfig['out_json'], json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        $documentIndexer = new PostgresDocumentIndexer($documentConfig);
        
        try {
            $documentResults = $documentIndexer->run();
            $totalResults['documents'] = $documentResults;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error processing documents: ' . $e->getMessage() . "\n");
            $totalResults['documents']['errors'] = 1;
        }
        
        if (!$args['quiet']) {
            $t = $documentResults['timing'] ?? ['total_ms'=>0];
            echo "\nDocument Results:\n";
            echo "  Processed: {$documentResults['processed']}\n";
            echo "  Embeddings: {$documentResults['embeddings']}\n";
            echo "  Metrics: {$documentResults['metrics']}\n";
            echo "  Errors: {$documentResults['errors']}\n";
            echo "  Time: " . number_format($t['total_ms'] ?? 0, 1) . "ms\n\n";
        }
    } else {
        if (!$args['quiet']) echo "No documents to process.\n\n";
    }
}

// Final summary
if (!$args['quiet']) {
    echo "\nOverall Summary\n";
    echo str_repeat("=", 40) . "\n";
    echo "Sentences - Processed: {$totalResults['sentences']['processed']}, ";
    echo "Embeddings: {$totalResults['sentences']['embeddings']}, ";
    echo "Metrics: {$totalResults['sentences']['metrics']}, ";
    echo "Errors: {$totalResults['sentences']['errors']}\n";
    echo "Documents - Processed: {$totalResults['documents']['processed']}, ";
    echo "Embeddings: {$totalResults['documents']['embeddings']}, ";
    echo "Metrics: {$totalResults['documents']['metrics']}, ";
    echo "Errors: {$totalResults['documents']['errors']}\n";
}

// Write combined JSON output if requested
if ($outJson) {
    $dir = dirname($outJson);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $combined = [
        'sentences' => $totalResults['sentences'],
        'documents' => $totalResults['documents'],
        'created_at' => date('c'),
    ];
    file_put_contents($outJson, json_encode($combined, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (!$args['quiet']) echo "\nWrote combined JSON summary -> $outJson\n";
}

// Exit with error code if any errors occurred
$totalErrors = ($totalResults['sentences']['errors'] ?? 0) + ($totalResults['documents']['errors'] ?? 0);
exit($totalErrors > 0 ? 1 : 0);

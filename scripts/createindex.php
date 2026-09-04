#!/usr/bin/env php
<?php
/**
 * CLI entry point for the Hawaiian Search CorpusIndexer.
 *
 * Runs the CorpusIndexer from the command line with configurable options.
 *
 * Usage examples:
 *   php scripts/createindex.php --dryrun
 *   php scripts/createindex.php --recreate --verbose --max-documents 10
 *   php scripts/createindex.php --recreate --verbose
 *   php scripts/createindex.php --group-name=kauakukalahale --dryrun
 *   php scripts/createindex.php --aliases-only
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Path resolution (__DIR__ based) and bootstrap
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at {$autoloadPath}.\n");
    fwrite(STDERR, "Run 'composer install' in {$projectRoot} first.\n");
    exit(1);
}

require_once $autoloadPath;

// Load .env using the same pattern as ElasticsearchClient
if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load($projectRoot . '/.env');
}

use HawaiianSearch\CorpusIndexer;
use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\IndexSchemaValidator;
use HawaiianSearch\OpenSearchClient;

// ElasticsearchClient exposes alias creation via a protected method. This
// small local subclass makes it available to the CLI without changing the
// library client's public API.
class CliAliasClient extends ElasticsearchClient
{
    public function createProductionAliases(): void
    {
        $this->createAliases();
    }
}

// OpenSearchClient extends ElasticsearchClient, so the same alias-exposing
// subclass pattern applies for the OpenSearch provider.
class CliOpenSearchAliasClient extends OpenSearchClient
{
    public function createProductionAliases(): void
    {
        $this->createAliases();
    }
}

/**
 * Create the provider-appropriate alias client.
 */
function createAliasClient(array $options, string $provider): ElasticsearchClient
{
    if (in_array(strtolower($provider), ['opensearch', 'os'], true)) {
        return new CliOpenSearchAliasClient($options);
    }
    return new CliAliasClient($options);
}

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
$longOptions = [
    'recreate',                 // Rebuild into *_staging indices, atomic alias switch on completion
    'dryrun',                   // Dry run: show what would happen without indexing
    'dry-run',                  // Alias for --dryrun
    'verbose',                  // Verbose output
    'quiet',                    // Suppress non-error output
    'help',                     // Show usage
    'max-documents:',           // Stop after indexing N documents
    'limit:',                   // Alias for --max-documents
    'source-id:',               // Only index the source with this ID
    'group-name:',              // Only index sources in this group
    'groupname:',               // Alias for --group-name
    'batch-size:',              // Documents per batch
    'sentence-batch-size:',     // Sentences per embedding request
    'checkpoint-interval:',     // Sources between checkpoints
    'split-indices',            // Use separate document/sentence indices (default)
    'no-split-indices',         // Use a single combined index
    'collection-name:',         // Base collection/index name
    'aliases-only',             // Only recreate aliases without touching indices
    'no-aliases',               // Skip alias creation after index creation
    'provider:',                // Search provider: Elasticsearch or OpenSearch
    'source:',                  // Source: api (MySQL HTTP API) or postgres (stored vectors)
    'import-raw',               // Ingest only the raw-content index (hawaiian-content) without touching other indices
];

$options = getopt('', $longOptions);

if ($options === false) {
    fwrite(STDERR, "Error: Failed to parse command-line arguments.\n");
    printUsage();
    exit(1);
}

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

// Read an integer option, supporting aliases. Exits with code 1 on bad input.
$intOption = function (array $names, ?int $default = null) use ($options): ?int {
    foreach ($names as $name) {
        if (isset($options[$name])) {
            $value = $options[$name];
            if (!is_numeric($value)) {
                fwrite(STDERR, "Error: --{$name} expects a numeric value, got '{$value}'.\n");
                exit(1);
            }
            return (int)$value;
        }
    }
    return $default;
};

$dryrun = isset($options['dryrun']) || isset($options['dry-run']);
$recreate = isset($options['recreate']);
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);
$aliasesOnly = isset($options['aliases-only']);
$noAliases = isset($options['no-aliases']);
$importRaw = isset($options['import-raw']);

// --recreate rebuilds the entire corpus and switches it in atomically on
// completion; the scoped/limited options only make sense for incremental
// ingestion. Abort immediately (before connecting to anything) rather than
// risk swapping a partial corpus into production at the end of the run.
if ($recreate) {
    $used = array_values(array_intersect(
        ['group-name', 'groupname', 'source-id', 'max-documents', 'limit'],
        array_keys($options)
    ));
    if ($used) {
        fwrite(STDERR, "Error: --recreate cannot be combined with --" . implode(', --', $used) . ".\n");
        fwrite(STDERR, "--group-name, --source-id and --max-documents are for incremental ingestion only.\n");
        fwrite(STDERR, "Run --recreate on its own to rebuild the whole corpus.\n");
        exit(1);
    }
}

// Staging runs: on --recreate, ingest into temporary *_staging indices and
// switch the production aliases over atomically when the run completes, so
// the live indices are never wiped mid-run. (Dry runs never enable staging.)
$stagingRun = $recreate && !$dryrun;

// Search provider: --provider flag, falling back to the PROVIDER env var,
// then to the default Elasticsearch.
$provider = $options['provider'] ?? $_ENV['PROVIDER'] ?? 'Elasticsearch';
$isOpenSearch = in_array(strtolower($provider), ['opensearch', 'os'], true);

// Source: --source selects where documents, sentences, and vectors come from.
// 'api' (default) reads from the MySQL HTTP API and embeds live; 'postgres'
// reads text, sentences, and stored vectors from the laana Postgres schema.
$source = $options['source'] ?? 'api';
if (!in_array($source, ['api', 'postgres'], true)) {
    fwrite(STDERR, "Error: --source expects 'api' or 'postgres', got '{$source}'.\n");
    exit(1);
}

$config = [
    'COLLECTION_NAME' => $options['collection-name'] ?? 'hawaiian',
    'SPLIT_INDICES' => isset($options['no-split-indices']) ? false : true,
    'BATCH_SIZE' => $intOption(['batch-size'], 1),
    'SENTENCE_BATCH_SIZE' => $intOption(['sentence-batch-size'], 100),
    'CHECKPOINT_INTERVAL' => $intOption(['checkpoint-interval'], 50),
    'MAX_DOCUMENTS' => $intOption(['max-documents', 'limit']),
    'SOURCE_ID' => $intOption(['source-id']),
    'groupName' => $options['group-name'] ?? $options['groupname'] ?? null,
    'verbose' => $verbose,
    'quiet' => $quiet,
    'updateProperties' => false,
    'updateMetadata' => false,
    'updateSourceMetadata' => false,
    'importRaw' => $importRaw,
    'dryrun' => $dryrun,
    'source' => $source,
];

// ---------------------------------------------------------------------------
// Preamble: show the configuration being used (suppressed by --quiet)
// ---------------------------------------------------------------------------
if (!$quiet) {
    echo "========================================\n";
    echo " Hawaiian Search Corpus Indexer\n";
    echo "========================================\n";
    echo "Collection name:      {$config['COLLECTION_NAME']}\n";
    echo "Provider:             {$provider}\n";
    echo "Source:               {$source}\n";
    echo "Split indices:        " . ($config['SPLIT_INDICES'] ? 'yes' : 'no') . "\n";
    echo "Batch size:           {$config['BATCH_SIZE']}\n";
    echo "Sentence batch size:  {$config['SENTENCE_BATCH_SIZE']}\n";
    echo "Checkpoint interval:  {$config['CHECKPOINT_INTERVAL']}\n";
    echo "Max documents:        " . ($config['MAX_DOCUMENTS'] ?? 'unlimited') . "\n";
    echo "Source ID:            " . ($config['SOURCE_ID'] ?? 'all') . "\n";
    echo "Group name:           " . ($config['groupName'] ?? 'all') . "\n";
    echo "Verbose:              " . ($config['verbose'] ? 'yes' : 'no') . "\n";
    echo "Quiet:                " . ($config['quiet'] ? 'yes' : 'no') . "\n";
    echo "Recreate index:       " . ($recreate ? ($stagingRun ? 'yes (staging indices, atomic switch on completion)' : 'yes') : 'no') . "\n";
    echo "Dry run:              " . ($dryrun ? 'yes' : 'no') . "\n";
    echo "Aliases only:         " . ($aliasesOnly ? 'yes' : 'no') . "\n";
    echo "Skip aliases:         " . ($noAliases ? 'yes' : 'no') . "\n";
    echo "Import raw content:   " . ($importRaw ? 'yes (content index only)' : 'yes (inline with each document)') . "\n";
    echo "----------------------------------------\n";
}

// ---------------------------------------------------------------------------
// Aliases-only mode: recreate production aliases without touching indices
// ---------------------------------------------------------------------------
if ($aliasesOnly) {
    if (!$quiet) {
        echo "Creating aliases only...\n";
    }
    try {
        $aliasClient = createAliasClient([
            'indexName'     => $config['COLLECTION_NAME'],
            'verbose'       => $verbose,
            'quiet'         => $quiet,
            'SPLIT_INDICES' => $config['SPLIT_INDICES'],
        ], $provider);
        $aliasClient->createProductionAliases();
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: Could not create aliases: " . $e->getMessage() . "\n");
        if ($verbose) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
        exit(1);
    }
    if (!$quiet) {
        echo "Done.\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Pre-flight: validate index schemas before any changes are made
// ---------------------------------------------------------------------------
if (!$quiet) {
    echo "\n";
}

try {
    $clientOptions = [
        'indexName'     => $config['COLLECTION_NAME'],
        'verbose'       => $verbose,
        'quiet'         => $quiet,
        'SPLIT_INDICES' => $config['SPLIT_INDICES'],
    ];
    $esClient = $isOpenSearch
        ? new OpenSearchClient($clientOptions)
        : new ElasticsearchClient($clientOptions);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: Could not connect to " . ($isOpenSearch ? 'OpenSearch' : 'Elasticsearch') . ": " . $e->getMessage() . "\n");
    if ($verbose) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}

// Only run schema validation for indexing modes (not metadata-only updates)
$isIndexingMode = !$config['updateProperties']
    && !$config['updateMetadata']
    && !$config['updateSourceMetadata']
    && !$config['importRaw'];

if ($isIndexingMode) {
    $validator = new IndexSchemaValidator($esClient, $verbose);
    $schemaValid = $validator->validate($recreate);

    if (!$schemaValid) {
        fwrite(STDERR, "Pre-flight validation failed. Aborting.\n");
        fwrite(STDERR, "Use --recreate to recreate indices, or fix the schema issues above.\n");
        exit(1);
    }
} elseif (!$quiet) {
    echo "ℹ️  Skipping schema validation (metadata-only mode).\n\n";
}

// Enable staging AFTER validation so the pre-flight checks run against the
// current production indices. From here on the client routes every read and
// write to the *_staging indices; the production aliases keep serving the
// live corpus until switchStagingToProduction() runs below.
if ($stagingRun) {
    $esClient->setStagingMode(true);
    if (!$quiet) {
        echo "🔁 Staging mode: indices will be rebuilt as *_staging and switched into production on completion.\n";
    }
}

// $esClient is passed to CorpusIndexer below — do NOT unset it here.

// ---------------------------------------------------------------------------
// Signal handlers for graceful shutdown (SIGINT, SIGTERM)
// ---------------------------------------------------------------------------
$shutdownRequested = false;
$shutdownSignal = null;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = function (int $signo) use (&$shutdownRequested, &$shutdownSignal): void {
        // A second signal forces an immediate exit (the documented "Ctrl+C twice" abort).
        // Without this, installing a SIGINT handler disables the default terminate-on-Ctrl+C
        // behavior, so the process would otherwise run to completion no matter how many
        // times Ctrl+C is pressed.
        if ($shutdownRequested) {
            $name = $signo === SIGINT ? 'SIGINT (Ctrl+C)' : 'SIGTERM';
            fwrite(STDERR, "\nReceived {$name} again — forcing immediate exit.\n");
            exit($signo === SIGTERM ? 143 : 130);
        }
        $shutdownRequested = true;
        $shutdownSignal = $signo;
        $name = $signo === SIGINT ? 'SIGINT (Ctrl+C)' : 'SIGTERM';
        fwrite(STDERR, "\nReceived {$name} — finishing current batch gracefully...\n");
        fwrite(STDERR, "Press Ctrl+C again to force quit.\n");
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);
} else {
    fwrite(STDERR, "Warning: pcntl extension not available; graceful shutdown disabled.\n");
}

// ---------------------------------------------------------------------------
// Run the indexer
// ---------------------------------------------------------------------------
try {
    $indexer = new CorpusIndexer($config, $recreate, $dryrun, null, $esClient);
    // Let the indexer observe the shutdown flag so the first Ctrl+C stops it
    // gracefully at the next batch boundary (the second Ctrl+C force-exits from
    // the signal handler above).
    $indexer->setShutdownChecker(function (): bool {
        return $GLOBALS['shutdownRequested'] ?? false;
    });
    $indexer->runIndexing();

    // Note: in a normal (full) run, hawaiian-content is now ingested inline
    // together with each source's document/sentences (see
    // CorpusIndexer::ingestRawContentForSources()), so there is no separate
    // content pass to run here. In --import-raw mode, runIndexing() already
    // performed the content-only ingestion and returned.

    // Switch-over: in a staging (--recreate) run, atomically repoint the
    // production aliases at the completed *_staging indices and delete the
    // old production indices. Skipped when the run was interrupted (an
    // incomplete staging corpus must never replace production) or when
    // --no-aliases was given. In content-only staging runs only the pairs
    // whose staging index exists are switched (e.g. just hawaiian-content).
    if ($stagingRun) {
        if ($noAliases) {
            fwrite(STDERR, "Warning: --no-aliases given - staging indices were built but NOT switched into production.\n");
            fwrite(STDERR, "The production indices are unchanged. Re-run without --no-aliases to complete the switch.\n");
        } elseif ($shutdownRequested) {
            fwrite(STDERR, "Shutdown requested - staging indices were built but NOT switched into production.\n");
            fwrite(STDERR, "The production indices are unchanged. Re-run with --recreate to complete the rebuild.\n");
        } else {
            if (!$quiet) {
                echo "Switching staging indices into production (atomic alias swap)...\n";
            }
            $esClient->switchStagingToProduction();
        }
    } elseif (!$noAliases && !$config['importRaw']) {
        // Ensure production aliases exist after index creation (unless --no-aliases).
        // In content-only mode we must not touch the other indices' aliases.
        if (!$quiet) {
            echo "Ensuring production aliases...\n";
        }
        $aliasClient = createAliasClient([
            'indexName'     => $config['COLLECTION_NAME'],
            'verbose'       => $verbose,
            'quiet'         => $quiet,
            'SPLIT_INDICES' => $config['SPLIT_INDICES'],
        ], $provider);
        $aliasClient->createProductionAliases();
    }

    if ($shutdownRequested) {
        $code = $shutdownSignal === SIGTERM ? 143 : 130;
        fwrite(STDERR, "Shutdown requested — exiting with code {$code}.\n");
        exit($code);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    if ($verbose) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    if ($stagingRun) {
        fwrite(STDERR, "Note: this was a staging (--recreate) run - the production indices are unchanged.\n");
        fwrite(STDERR, "Staging indices may be left behind; re-run with --recreate to try again.\n");
    }
    exit(1);
}

/**
 * Print the usage/help message.
 */
function printUsage(): void
{
    $usage = <<<USAGE
Hawaiian Search Corpus Indexer — CLI entry point for CorpusIndexer.

Usage:
  php scripts/createindex.php [options]

Options:
  --recreate                 Rebuild into temporary *_staging indices, then atomically
                             switch the production aliases when done (the live
                             indices are never wiped mid-run). Full-corpus only:
                             cannot be combined with --group-name, --source-id or
                             --max-documents
  --dryrun                   Dry run: show what would happen without indexing
  --dry-run                  Alias for --dryrun
  --verbose                  Verbose output
  --quiet                    Suppress non-error output
  --max-documents=N          Stop after indexing N documents (incremental ingestion only)
  --limit=N                  Alias for --max-documents
  --source-id=N              Only index the source with this ID (incremental ingestion only)
  --group-name=NAME          Only index sources in this group (incremental ingestion only)
  --groupname=NAME           Alias for --group-name
  --batch-size=N             Documents per batch (default: 1)
  --sentence-batch-size=N    Sentences per embedding request (default: 100)
  --checkpoint-interval=N    Sources between checkpoints (default: 50)
  --split-indices            Use separate document/sentence indices (default)
  --no-split-indices         Use a single combined index
  --collection-name=NAME     Base collection/index name (default: hawaiian)
  --aliases-only             Only recreate aliases without touching indices
  --no-aliases               Skip alias creation after index creation
  --provider=NAME            Search provider: Elasticsearch or OpenSearch (default: Elasticsearch)
  --source=NAME              Source of documents/vectors: api (default) or postgres
  --import-raw               Ingest ONLY the raw-content index (hawaiian-content) without touching the other indices
  --help                     Show this help message

Examples:
  php scripts/createindex.php --dryrun
  php scripts/createindex.php --recreate --verbose
  php scripts/createindex.php --verbose --max-documents 10
  php scripts/createindex.php --group-name=kauakukalahale --dryrun
  php scripts/createindex.php --aliases-only
  php scripts/createindex.php --recreate --provider=opensearch
  php scripts/createindex.php --recreate --source=postgres --dryrun

Exit codes:
  0    Success
  1    Error
  130  Interrupted by SIGINT
  143  Interrupted by SIGTERM

USAGE;
    echo $usage;
}
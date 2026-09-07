#!/usr/bin/env php
<?php
/**
 * Check synchronization between a relational source database (MySQL or
 * Postgres) and the source-metadata index of a search provider
 * (Elasticsearch or OpenSearch), via the production alias.
 *
 * Lightweight identification tool (detection only — no remediation):
 *   - Queries source IDs via the existing API (NOIIOLELO_API_BASE_URL)
 *   - Queries the provider's source-metadata index through its production
 *     alias (ES_SOURCE_METADATA_ALIAS, default hawaiian_source_metadata)
 *   - Reports source IDs missing on either side
 *   - Reports any *_staging indices found on the search provider
 *     (informational only — leftover staging indices from an interrupted
 *     --recreate run do not affect the exit code)
 *
 * Usage:
 *   php scripts/check-index-sync.php [--source=mysql|postgres]
 *                                    [--provider=elasticsearch|opensearch]
 *                                    [--verbose] [--help]
 *
 * Exit codes:
 *   0  All source IDs are synchronized
 *   1  Mismatches found (missing on either side) or an error occurred
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

// Load .env using the same pattern as the provider clients
if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load($projectRoot . '/.env');
}

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Read an option in either --name=value or --name value form.
 */
function cliOption(array $argv, string $name, ?string $default = null): ?string
{
    for ($i = 1, $n = count($argv); $i < $n; $i++) {
        if (str_starts_with($argv[$i], "{$name}=")) {
            return substr($argv[$i], strlen($name) + 1);
        }
        if ($argv[$i] === $name && array_key_exists($i + 1, $argv)) {
            return $argv[$i + 1];
        }
    }
    return $default;
}

/**
 * List *_staging indices with their document counts. Uses the transport-level
 * client (the wrapped OpenSearch client silently swallows indices() calls).
 * The response shape differs between the ES v8 client (response object) and
 * the raw OpenSearch client (plain array) — normalize to an array.
 */
function findStagingIndices(ElasticsearchClient $client): array
{
    // NB: no ignore_unavailable here — the OpenSearch PHP client rejects it,
    // and wildcard expressions that match nothing simply return no entries.
    $response = $client->getTransportClient()->indices()->stats([
        'index' => '*_staging',
    ]);
    if (is_object($response) && method_exists($response, 'asArray')) {
        $response = $response->asArray();
    }

    $staging = [];
    foreach ($response['indices'] ?? [] as $name => $stats) {
        $staging[(string)$name] = (int)($stats['primaries']['docs']['count'] ?? 0);
    }
    ksort($staging);
    return $staging;
}

/**
 * Print the usage/help message.
 */
function printUsage(): void
{
    $usage = <<<USAGE
MySQL/Postgres vs Elasticsearch/OpenSearch index sync check — detection only
(no remediation). Compares source IDs in a relational database against the
source-metadata index of a search provider and reports the differences.

Usage:
  php scripts/check-index-sync.php [options]

Options:
  (no options)               MySQL vs Elasticsearch (defaults)
  --source=mysql|postgres    Relational database providing the source IDs
                             (default: mysql)
  --provider=elasticsearch|opensearch
                             Search provider to check against (default:
                             elasticsearch)
  --verbose                  Per-source detail and client-level output
  --help                     Show this help message

Notes:
  Source IDs are fetched via the API (NOIIOLELO_API_BASE_URL). The provider
  side reads the production source-metadata alias (ES_SOURCE_METADATA_ALIAS,
  default hawaiian_source_metadata), so it keeps working after a staging
  (--recreate) run switches the corpus to new physical indices.
  Any *_staging indices found on the search provider are listed —
  informational only; leftovers from an interrupted --recreate run do not
  affect the exit code.

Examples:
  php scripts/check-index-sync.php
  php scripts/check-index-sync.php --source=postgres --provider=opensearch
  php scripts/check-index-sync.php --provider=opensearch --verbose

Exit codes:
  0  All source IDs are synchronized (also for --help)
  1  Mismatches found (missing on either side), unknown option values, or an
     error occurred

Connection settings come from .env: NOIIOLELO_API_BASE_URL, ES_*/API_KEY
(Elasticsearch), OS_*/OPENSEARCH_* (OpenSearch).

USAGE;
    echo $usage;
}

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
$verbose = in_array('--verbose', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

$sources = ['mysql' => 'MySQL', 'postgres' => 'Postgres'];
$providers = ['elasticsearch' => 'Elasticsearch', 'opensearch' => 'OpenSearch'];

$sourceOption = strtolower((string)cliOption($argv, '--source', 'mysql'));
$providerOption = strtolower((string)cliOption($argv, '--provider', 'elasticsearch'));

if ($help) {
    printUsage();
    exit(0);
}

if (!isset($sources[$sourceOption])) {
    fwrite(STDERR, "Error: Unknown --source '{$sourceOption}'. Valid values: "
        . implode(', ', array_keys($sources)) . "\n");
    exit(1);
}
if (!isset($providers[$providerOption])) {
    fwrite(STDERR, "Error: Unknown --provider '{$providerOption}'. Valid values: "
        . implode(', ', array_keys($providers)) . "\n");
    exit(1);
}

$sourceLabel = $sources[$sourceOption];
$providerLabel = $providers[$providerOption];

$apiBaseUrl = $_ENV['NOIIOLELO_API_BASE_URL'] ?? getenv('NOIIOLELO_API_BASE_URL') ?? '';

if (!$apiBaseUrl) {
    fwrite(STDERR, "Error: NOIIOLELO_API_BASE_URL must be set in .env\n");
    exit(1);
}

// The provider clients validate connection config and credentials themselves
// and fail loudly. Skip their embedding-service check and filter side effects:
// this script is a read-only detection tool and must not touch either.
$_ENV['SKIP_EMBEDDING_VALIDATION'] = '1';
$_ENV['SKIP_FILTER_CHECKS'] = '1';
putenv('SKIP_EMBEDDING_VALIDATION=1');
putenv('SKIP_FILTER_CHECKS=1');

echo "=== {$sourceLabel} vs {$providerLabel} Sync Check ===\n\n";

// ---------------------------------------------------------------------------
// 1. Fetch source IDs from the relational database via the API
// ---------------------------------------------------------------------------
echo "Fetching {$sourceLabel} source IDs...\n";
$apiUrl = $apiBaseUrl . '?path=sources&provider=' . $sourceLabel;
$response = @file_get_contents($apiUrl);
if ($response === false) {
    fwrite(STDERR, "Error: Failed to fetch from API: {$apiUrl}\n");
    exit(1);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    fwrite(STDERR, "Error: Invalid API response from {$apiUrl}\n");
    exit(1);
}

// The API returns {"sourceids": [...]}. Fall back to a flat array if present.
$sourceIds = [];
if (isset($data['sourceids']) && is_array($data['sourceids'])) {
    foreach ($data['sourceids'] as $id) {
        if ($id !== null && $id !== '') {
            $sourceIds[(string)$id] = true;
        }
    }
} else {
    foreach ($data as $source) {
        $id = $source['sourceid'] ?? $source['id'] ?? null;
        if ($id !== null && $id !== '') {
            $sourceIds[(string)$id] = true;
        }
    }
}

echo "Found " . count($sourceIds) . " sources in {$sourceLabel}\n\n";

// ---------------------------------------------------------------------------
// 2. Fetch source metadata IDs from the search provider
// ---------------------------------------------------------------------------
echo "Fetching {$providerLabel} source metadata...\n";

try {
    $client = $providerOption === 'elasticsearch'
        ? new ElasticsearchClient(['verbose' => $verbose, 'quiet' => true])
        : new OpenSearchClient(['verbose' => $verbose, 'quiet' => true]);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Failed to create {$providerLabel} client: " . $e->getMessage() . "\n");
    exit(1);
}

// Resolve through the production alias so this keeps working after a
// staging (--recreate) run switches the corpus to new physical indices.
$metadataIndex = $client->getSourceMetadataName();

if (!$client->indexExists($metadataIndex)) {
    fwrite(STDERR, "Error: Source metadata index '{$metadataIndex}' not found on {$providerLabel}.\n");
    exit(1);
}

// Scan all documents in the metadata index (getAllRecords scrolls internally).
$providerSourceIds = [];
foreach ($client->getAllRecords($metadataIndex) as $hit) {
    $sourceid = $hit['_source']['sourceid'] ?? null;
    if ($sourceid !== null && $sourceid !== '') {
        $providerSourceIds[(string)$sourceid] = true;
    }
}

echo "Found " . count($providerSourceIds) . " sources in {$providerLabel} metadata\n\n";

// Staging indices are the *_staging variants a --recreate run writes into;
// leftovers mean an interrupted rebuild (the next --recreate wipes them).
$stagingIndices = [];
$stagingError = null;
try {
    $stagingIndices = findStagingIndices($client);
} catch (\Throwable $e) {
    $stagingError = $e->getMessage();
}

// ---------------------------------------------------------------------------
// 3. Compare
// ---------------------------------------------------------------------------
$missingInProvider = array_diff_key($sourceIds, $providerSourceIds);
$missingInSource = array_diff_key($providerSourceIds, $sourceIds);

echo "=== Results ===\n\n";

if (empty($missingInProvider) && empty($missingInSource)) {
    echo "All sources are synchronized!\n";
    echo "  {$sourceLabel}: " . count($sourceIds) . " sources\n";
    echo "  {$providerLabel}: " . count($providerSourceIds) . " sources\n";
} else {
    if (!empty($missingInProvider)) {
        echo "Missing in {$providerLabel} (" . count($missingInProvider) . "):\n";
        foreach (array_keys($missingInProvider) as $id) {
            echo "  - {$id}\n";
        }
        echo "\n";
    }

    if (!empty($missingInSource)) {
        echo "Missing in {$sourceLabel} (" . count($missingInSource) . "):\n";
        foreach (array_keys($missingInSource) as $id) {
            echo "  - {$id}\n";
        }
        echo "\n";
    }
}

echo "\n=== Staging Indices ===\n\n";
if ($stagingError !== null) {
    echo "Error scanning for staging indices: {$stagingError}\n";
} elseif (empty($stagingIndices)) {
    echo "No staging indices found.\n";
} else {
    echo count($stagingIndices) . " staging index(es) found:\n";
    foreach ($stagingIndices as $name => $docCount) {
        echo "  - {$name} (" . number_format($docCount) . " docs)\n";
    }
}

echo "\n=== Summary ===\n";
echo "{$sourceLabel} sources: " . count($sourceIds) . "\n";
echo "{$providerLabel} sources: " . count($providerSourceIds) . "\n";
echo "Missing in {$providerLabel}: " . count($missingInProvider) . "\n";
echo "Missing in {$sourceLabel}: " . count($missingInSource) . "\n";
echo "Staging indices: " . ($stagingError !== null ? 'error' : count($stagingIndices)) . "\n";

exit(empty($missingInProvider) && empty($missingInSource) ? 0 : 1);

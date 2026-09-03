#!/usr/bin/env php
<?php
/**
 * Check synchronization between MySQL source IDs and the Elasticsearch
 * `hawaiian-source-metadata` index.
 *
 * Lightweight identification tool (detection only — no remediation):
 *   - Queries MySQL source IDs via the existing API (NOIIOLELO_API_BASE_URL)
 *   - Queries the ES `hawaiian-source-metadata` index
 *   - Reports source IDs missing in ES and missing in MySQL
 *
 * Usage:
 *   php php/check-index-sync.php [--verbose]
 *
 * Exit codes:
 *   0  All source IDs are synchronized
 *   1  Mismatches found (missing in ES and/or missing in MySQL)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Path resolution (__DIR__ based) and bootstrap
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__, 2);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at {$autoloadPath}.\n");
    fwrite(STDERR, "Run 'composer install' in {$projectRoot} first.\n");
    exit(1);
}

require_once $autoloadPath;

// Load .env using the same pattern as createindex.php / ElasticsearchClient
if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load($projectRoot . '/.env');
}

use Elastic\Elasticsearch\ClientBuilder;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
$verbose = in_array('--verbose', $argv, true);

$apiBaseUrl = $_ENV['NOIIOLELO_API_BASE_URL'] ?? getenv('NOIIOLELO_API_BASE_URL') ?? '';
// Fail loudly on missing Elasticsearch connection config instead of silently
// probing localhost.
$esHost     = \Noiiolelo\EnvConfig::firstEnv('Elasticsearch host', ['ES_HOST']);
$esPort     = \Noiiolelo\EnvConfig::firstEnv('Elasticsearch port', ['ES_PORT']);
$esScheme   = \Noiiolelo\EnvConfig::firstEnv('Elasticsearch scheme', ['ES_SCHEME']);
$esApiKey   = \Noiiolelo\EnvConfig::firstEnv('Elasticsearch API key', ['ES_API_KEY', 'API_KEY']);

if (!$apiBaseUrl) {
    fwrite(STDERR, "Error: NOIIOLELO_API_BASE_URL must be set in .env\n");
    exit(1);
}

$metadataIndex = 'hawaiian-source-metadata';

echo "=== MySQL vs Elasticsearch Sync Check ===\n\n";

// ---------------------------------------------------------------------------
// 1. Fetch MySQL source IDs via the API
// ---------------------------------------------------------------------------
echo "Fetching MySQL source IDs...\n";
$apiUrl = $apiBaseUrl . '?path=sources&provider=MySQL';
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
$mysqlSourceIds = [];
if (isset($data['sourceids']) && is_array($data['sourceids'])) {
    foreach ($data['sourceids'] as $id) {
        if ($id !== null && $id !== '') {
            $mysqlSourceIds[(string)$id] = true;
        }
    }
} else {
    foreach ($data as $source) {
        $id = $source['sourceid'] ?? $source['id'] ?? null;
        if ($id !== null && $id !== '') {
            $mysqlSourceIds[(string)$id] = true;
        }
    }
}

echo "Found " . count($mysqlSourceIds) . " sources in MySQL\n\n";

// ---------------------------------------------------------------------------
// 2. Fetch ES source metadata IDs
// ---------------------------------------------------------------------------
echo "Fetching Elasticsearch source metadata...\n";

$builder = ClientBuilder::create()
    ->setHosts(["{$esScheme}://{$esHost}:{$esPort}"])
    ->setSSLVerification(false)
    ->setHttpClientOptions([
        'timeout' => 300,
        'connect_timeout' => 30,
        'http_errors' => false,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
    ]);

if ($esApiKey !== '') {
    $esApiKey = trim($esApiKey);
    if (strpos($esApiKey, ':') !== false) {
        [$id, $key] = explode(':', $esApiKey, 2);
        $builder->setApiKey(trim($key), trim($id));
    } else {
        $builder->setApiKey($esApiKey);
    }
}

$esClient = $builder->build();

// Scan all documents in the metadata index (handle pagination via scroll).
$esSourceIds = [];
$scrollTime = '1m';
$searchParams = [
    'index' => $metadataIndex,
    'size' => 1000,
    'scroll' => $scrollTime,
    '_source' => ['sourceid'],
    'body' => [
        'query' => ['match_all' => (object)[]],
    ],
];

try {
    $response = $esClient->search($searchParams);
    $scrollId = $response['_scroll_id'] ?? null;

    $collect = function (array $hits) use (&$esSourceIds): void {
        foreach ($hits as $hit) {
            $sourceid = $hit['_source']['sourceid'] ?? null;
            if ($sourceid !== null && $sourceid !== '') {
                $esSourceIds[(string)$sourceid] = true;
            }
        }
    };

    $collect($response['hits']['hits'] ?? []);

    while ($scrollId !== null) {
        $scrollResponse = $esClient->scroll([
            'scroll_id' => $scrollId,
            'scroll' => $scrollTime,
        ]);
        $hits = $scrollResponse['hits']['hits'] ?? [];
        if (empty($hits)) {
            break;
        }
        $collect($hits);
        $scrollId = $scrollResponse['_scroll_id'] ?? null;
    }

    // Clear the scroll context to free server resources.
    if ($scrollId !== null) {
        try {
            $esClient->clearScroll(['scroll_id' => $scrollId]);
        } catch (\Throwable $e) {
            // Best-effort cleanup; ignore failures.
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Failed to query Elasticsearch: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Found " . count($esSourceIds) . " sources in ES metadata\n\n";

// ---------------------------------------------------------------------------
// 3. Compare
// ---------------------------------------------------------------------------
$missingInEs = array_diff_key($mysqlSourceIds, $esSourceIds);
$missingInMySql = array_diff_key($esSourceIds, $mysqlSourceIds);

echo "=== Results ===\n\n";

if (empty($missingInEs) && empty($missingInMySql)) {
    echo "All sources are synchronized!\n";
    echo "  MySQL: " . count($mysqlSourceIds) . " sources\n";
    echo "  Elasticsearch: " . count($esSourceIds) . " sources\n";
    echo "\n=== Summary ===\n";
    echo "MySQL sources: " . count($mysqlSourceIds) . "\n";
    echo "ES sources: " . count($esSourceIds) . "\n";
    echo "Missing in ES: 0\n";
    echo "Missing in MySQL: 0\n";
    exit(0);
}

if (!empty($missingInEs)) {
    echo "Missing in Elasticsearch (" . count($missingInEs) . "):\n";
    foreach (array_keys($missingInEs) as $id) {
        echo "  - {$id}\n";
    }
    echo "\n";
}

if (!empty($missingInMySql)) {
    echo "Missing in MySQL (" . count($missingInMySql) . "):\n";
    foreach (array_keys($missingInMySql) as $id) {
        echo "  - {$id}\n";
    }
    echo "\n";
}

echo "=== Summary ===\n";
echo "MySQL sources: " . count($mysqlSourceIds) . "\n";
echo "ES sources: " . count($esSourceIds) . "\n";
echo "Missing in ES: " . count($missingInEs) . "\n";
echo "Missing in MySQL: " . count($missingInMySql) . "\n";

exit(1);

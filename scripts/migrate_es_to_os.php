<?php
/**
 * Migration script to jumpstart OpenSearch provider with content from Elasticsearch.
 * 
 * Usage: php migrate_es_to_os.php
 * 
 * Environment variables:
 * - ES_HOST, ES_PORT, API_KEY (for source)
 * - OS_HOST, OS_PORT, OS_USER, OS_PASS (for target)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;
use HawaiianSearch\ElasticsearchScrollIterator;

// Load environment
$env = loadEnv();
foreach ($env as $key => $value) {
    $_ENV[$key] = $value;
    putenv("$key=$value");
}

/**
 * Fetch all existing IDs from an index to avoid re-indexing.
 */
function getExistingIds($client, $index) {
    echo "Fetching existing IDs from $index...\n";
    $ids = [];
    try {
        $iterator = new ElasticsearchScrollIterator(
            $client,
            $index,
            '5m',
            1000, // Smaller batch
            ['doc_id'], // Fetch at least one small field
            [],
            true  // returnFullHit to get _id
        );
        
        while ($hits = $iterator->getNext()) {
            foreach ($hits as $hit) {
                $ids[$hit['_id']] = true;
            }
            echo "Loaded " . count($ids) . " IDs...\r";
        }
        echo "\n✓ Loaded " . count($ids) . " existing IDs.\n";
    } catch (Exception $e) {
        echo "⚠ Could not fetch existing IDs (index might be empty): " . $e->getMessage() . "\n";
    }
    return $ids;
}

// Configuration
$sourceIndexBase = 'hawaiian';
$targetIndexBase = 'hawaiian';

echo "==========================================================\n";
echo "OpenSearch Jumpstart: Migrating from Elasticsearch\n";
echo "==========================================================\n";

// Initialize Source Client (Elasticsearch)
try {
    $esClient = new ElasticsearchClient([
        'indexName' => $sourceIndexBase,
        'verbose' => false,
        'quiet' => true
    ]);
    echo "✓ Connected to Source (Elasticsearch)\n";
} catch (Exception $e) {
    echo "✗ Failed to connect to Source: " . $e->getMessage() . "\n";
    exit(1);
}

// Initialize Target Client (OpenSearch)
// Ensure OS_PORT is set
if (!isset($_ENV['OS_PORT']) && !getenv('OS_PORT')) {
    echo "Warning: OS_PORT not set, defaulting to 9201.\n";
    $_ENV['OS_PORT'] = 9201;
}

try {
    $osClient = new OpenSearchClient([
        'indexName' => $targetIndexBase,
        'verbose' => false,
        'quiet' => true,
        'OS_HOST' => $_ENV['OS_HOST'] ?? 'localhost',
        'OS_PORT' => $_ENV['OS_PORT'] ?? 9201,
        'OS_USER' => $_ENV['OS_USER'] ?? 'admin',
        'OS_PASS' => $_ENV['OS_PASS'] ?? ''
    ]);
    echo "✓ Connected to Target (OpenSearch) on port " . ($_ENV['OS_PORT'] ?? 9201) . "\n";
} catch (Exception $e) {
    echo "✗ Failed to connect to Target: " . $e->getMessage() . "\n";
    exit(1);
}

// 0. Create Search Pipeline in OpenSearch
echo "\nCreating search pipeline in OpenSearch...\n";
try {
    $osClient->createSearchPipeline('norm-pipeline');
} catch (Exception $e) {
    echo "⚠ Warning creating search pipeline: " . $e->getMessage() . "\n";
}

// List of indices to migrate
$indices = [
    'documents' => [
        'src' => $esClient->getDocumentsIndexName(),
        'dest' => $osClient->getDocumentsIndexName(),
        'type' => 'documents'
    ],
    'sentences' => [
        'src' => $esClient->getSentencesIndexName(),
        'dest' => $osClient->getSentencesIndexName(),
        'type' => 'sentences'
    ],
    'source-metadata' => [
        'src' => $esClient->getSourceMetadataName(),
        'dest' => $osClient->getSourceMetadataName(),
        'type' => 'source-metadata'
    ]
];

foreach ($indices as $key => $info) {
    $srcIndex = $info['src'];
    $destIndex = $info['dest'];
    
    echo "\nMigrating $key index: $srcIndex -> $destIndex\n";
    
    // 1. Create target index with correct mapping (don't recreate if it exists)
    echo "Ensuring target index $destIndex exists...\n";
    try {
        $osClient->createIndex(false, $info['type']);
    } catch (Exception $e) {
        echo "⚠ Warning ensuring index: " . $e->getMessage() . "\n";
    }

    // 1.5 Get existing IDs to skip
    $existingIds = getExistingIds($osClient, $destIndex);

    // 2. Scroll through source and index into target
    echo "Copying documents...\n";
    
    // We want ALL fields, so we pass ['*'] for includes and [] for excludes
    $iterator = new ElasticsearchScrollIterator(
        $esClient, 
        $srcIndex, 
        '5m', 
        500, // Increased batch size for speed
        ['*'], 
        [],
        true // returnFullHit
    );
    
    $total = $iterator->getSize();
    echo "Total documents in source: $total\n";
    
    $count = 0;
    $skipped = 0;
    while ($hits = $iterator->getNext()) {
        foreach ($hits as $hit) {
            if (!isset($hit['_source'])) continue;
            
            $id = $hit['_id'];
            
            // Idempotency: Skip if already exists in target
            if (isset($existingIds[$id])) {
                $skipped++;
                if (($skipped + $count) % 10000 == 0) {
                    echo "Progress: " . ($skipped + $count) . " / $total (Skipped: $skipped, Indexed: $count)\n";
                }
                continue;
            }

            // Fix date format if it's just YYYY
            if (isset($hit['_source']['date']) && preg_match('/^\d{4}$/', $hit['_source']['date'])) {
                $hit['_source']['date'] = $hit['_source']['date'] . '-01-01';
            }
            
            try {
                $osClient->index($hit['_source'], $id, $destIndex);
                $count++;
                
                if (($skipped + $count) % 100 == 0) {
                    echo "Progress: " . ($skipped + $count) . " / $total (" . round((($skipped + $count)/$total)*100, 1) . "%)\n";
                }
            } catch (Exception $e) {
                echo "✗ Error indexing document $id: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✓ Finished migrating $destIndex. Indexed: $count, Skipped: $skipped.\n";
    
    // Free memory
    unset($existingIds);
    
    // Refresh target index
    $osClient->refresh($destIndex);
}

echo "\n==========================================================\n";
echo "Jumpstart Complete!\n";
echo "==========================================================\n";

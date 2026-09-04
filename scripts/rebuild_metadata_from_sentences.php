#!/usr/bin/env php
<?php
/**
 * Rebuild the hawaiian-metadata index from hawaiian_sentences_new.
 *
 * Phase 1: Scroll all sentences, aggregate by text hash in memory.
 * Phase 2: Batch-save the aggregated metadata.
 *
 * Usage:
 *   php php/php/rebuild_metadata_from_sentences.php --provider=ElasticSearch
 *   php php/php/rebuild_metadata_from_sentences.php --provider=OpenSearch
 *   php php/php/rebuild_metadata_from_sentences.php --provider=ElasticSearch --dryrun
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at {$autoloadPath}.\n");
    fwrite(STDERR, "Run 'composer install' in {$projectRoot} first.\n");
    exit(1);
}

require_once $autoloadPath;

if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load($projectRoot . '/.env');
}

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
$provider = 'Elasticsearch';
$dryrun = false;
$scrollBatch = 1000;

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (preg_match('/^--provider=(.+)$/i', $arg, $m)) {
        $provider = $m[1];
    } elseif ($arg === '--dryrun') {
        $dryrun = true;
    } elseif (preg_match('/^--batch-size=(\d+)$/i', $arg, $m)) {
        $scrollBatch = (int)$m[1];
    }
}

echo "Provider: {$provider}\n";
echo "Dry run: " . ($dryrun ? 'yes' : 'no') . "\n\n";

// ---------------------------------------------------------------------------
// Create the provider client
// ---------------------------------------------------------------------------
$clientConfig = ['verbose' => true];

if (in_array(strtolower($provider), ['opensearch', 'os'], true)) {
    $client = new OpenSearchClient($clientConfig);
    echo "Using OpenSearch client\n";
} else {
    $client = new ElasticsearchClient($clientConfig);
    echo "Using Elasticsearch client\n";
}

$sentencesIndex = $client->getSentencesIndexName();
// Physical (staging-aware) name: this script deletes and recreates the index.
$metadataIndex  = $client->getMetadataConcreteName();

echo "Sentences index: {$sentencesIndex}\n";
echo "Metadata index:  {$metadataIndex}\n\n";

// ---------------------------------------------------------------------------
// Step 1: Recreate the metadata index
// ---------------------------------------------------------------------------
echo "Step 1: Recreating metadata index...\n";

if (!$dryrun) {
    $client->deleteIndex($metadataIndex);
    echo "  Deleted existing metadata index\n";
}

$mapping = [
    'mappings' => [
        'properties' => [
            'sentence_hash'       => ['type' => 'keyword'],
            'frequency'           => ['type' => 'integer'],
            'length'              => ['type' => 'integer'],
            'entity_count'        => ['type' => 'integer'],
            'word_count'          => ['type' => 'integer'],
            'hawaiian_word_ratio' => ['type' => 'float'],
            'boilerplate_score'   => ['type' => 'float'],
            'metadata'            => [
                'type'       => 'object',
                'properties' => [
                    'doc_ids'   => ['type' => 'keyword'],
                    'positions' => ['type' => 'integer'],
                ],
            ],
        ],
    ],
];

if (!$dryrun) {
    $tc = $client->getTransportClient();
    $r = $tc->indices()->create(['index' => $metadataIndex, 'body' => $mapping]);
    if (is_object($r) && method_exists($r, 'wait')) {
        $r->wait();
    }
    echo "  Created metadata index\n";
} else {
    echo "  [DRY RUN] Would create metadata index\n";
}

// ---------------------------------------------------------------------------
// Step 2: Scroll all sentences and aggregate by text hash
// ---------------------------------------------------------------------------
echo "\nStep 2: Scrolling sentences and aggregating by hash...\n";

$tc = $client->getTransportClient();

$response = $tc->search([
    'scroll' => '60s',
    'size'   => $scrollBatch,
    'index'  => $sentencesIndex,
    'body'   => [
        'query'   => ['match_all' => (object)[]],
        '_source' => ['text', 'doc_id', 'position', 'hawaiian_word_ratio',
                       'word_count', 'entity_count', 'boilerplate_score',
                       'length', 'frequency'],
    ],
]);

if (is_object($response) && method_exists($response, 'asArray')) {
    $response = $response->asArray();
}

$scrollId  = $response['_scroll_id'];
$hits      = $response['hits']['hits'];
$totalHits = $response['hits']['total']['value'];

echo "  Total sentences: {$totalHits}\n";

$processed  = 0;
$sentences  = []; // hash => aggregated metadata

while (!empty($hits)) {
    foreach ($hits as $hit) {
        $s = $hit['_source'];
        $text   = $s['text'] ?? '';
        $docId  = $s['doc_id'] ?? '';
        $pos    = $s['position'] ?? 0;
        // Must match CorpusScanner::hashSentence(): md5(strtolower(trim($text)))
        $hash   = md5(strtolower(trim($text)));

        if (!isset($sentences[$hash])) {
            $sentences[$hash] = [
                'sentence_hash'       => $hash,
                'frequency'           => $s['frequency'] ?? 1,
                'length'              => $s['length'] ?? strlen($text),
                'entity_count'        => $s['entity_count'] ?? 0,
                'word_count'          => $s['word_count'] ?? 0,
                'hawaiian_word_ratio' => $s['hawaiian_word_ratio'] ?? 0.0,
                'boilerplate_score'   => $s['boilerplate_score'] ?? 0.0,
                'metadata'            => [
                    'doc_ids'   => [],
                    'positions' => [],
                ],
            ];
        }

        $m = &$sentences[$hash]['metadata'];
        if (!in_array($docId, $m['doc_ids'], true)) {
            $m['doc_ids'][] = $docId;
        }
        $m['positions'][] = $pos;

        // Frequency = number of docs containing this sentence
        $sentences[$hash]['frequency'] = count($m['doc_ids']);

        $processed++;
    }

    // Next scroll page
    $response = $tc->scroll([
        'scroll_id' => $scrollId,
        'scroll'    => '60s',
    ]);
    if (is_object($response) && method_exists($response, 'asArray')) {
        $response = $response->asArray();
    }
    $scrollId = $response['_scroll_id'];
    $hits     = $response['hits']['hits'];
}

// Clear scroll
if (!$dryrun) {
    try { $tc->clearScroll(['scroll_id' => $scrollId]); } catch (\Throwable $e) {}
}

$uniqueHashes = count($sentences);
echo "  Aggregated: {$processed} sentences → {$uniqueHashes} unique hashes\n";

// ---------------------------------------------------------------------------
// Step 3: Bulk-save the metadata
// ---------------------------------------------------------------------------
echo "\nStep 3: Bulk-saving metadata...\n";

if ($dryrun) {
    echo "  [DRY RUN] Would index " . count($sentences) . " metadata documents\n";
} else {
    $batch   = [];
    $saved   = 0;
    $errors  = 0;

    foreach ($sentences as $meta) {
        $batch[] = ['index' => ['_index' => $metadataIndex, '_id' => $meta['sentence_hash']]];
        $batch[] = $meta;

        if (count($batch) >= 2000) { // 1000 docs per bulk
            $saved += flushBulk($tc, $batch, $errors);
            $batch = [];
        }
    }

    if (!empty($batch)) {
        $saved += flushBulk($tc, $batch, $errors);
    }

    echo "  Indexed {$saved} metadata documents ({$errors} errors)\n";

    // Recreating the physical index drops the alias that pointed at it; re-point
    // the production metadata alias so reads follow the rebuilt index.
    $client->createAlias($client->getMetadataAlias(), $metadataIndex);
    echo "  Repointed alias '{$client->getMetadataAlias()}' at '{$metadataIndex}'\n";
}

echo "\nDone.\n";

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function flushBulk($tc, array &$batch, int &$errors): int
{
    if (empty($batch)) return 0;

    $result = $tc->bulk(['body' => $batch]);
    if (is_object($result) && method_exists($result, 'asArray')) {
        $result = $result->asArray();
    }

    $count = intdiv(count($batch), 2);
    $batch = [];

    if (isset($result['errors']) && $result['errors'] === true) {
        foreach ($result['items'] as $item) {
            if (isset($item['index']['error'])) {
                $errors++;
                if ($errors <= 5) {
                    echo "    Error: " . json_encode($item['index']['error']) . "\n";
                }
            }
        }
    }

    return $count;
}

<?php
/**
 * Backfill script to generate 1024-dimension vectors for existing documents.
 * This script iterates through documents that don't have text_vector_1024
 * and generates them using the large-instruct model.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../src/EmbeddingClient.php';
require_once __DIR__ . '/../src/ElasticsearchClient.php';

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;
use HawaiianSearch\EmbeddingClient;
use GuzzleHttp\Promise\Utils;

// Increase memory limit for large batches
ini_set('memory_limit', '1G');

// Parse command line arguments
$options = getopt("", ["provider:", "limit:", "id:"]);
$provider = $options['provider'] ?? 'elasticsearch';
$limit = isset($options['limit']) ? (int)$options['limit'] : null;
$targetId = $options['id'] ?? null;

if ($provider === 'opensearch') {
    echo "Using OpenSearch provider\n";
    $client = new OpenSearchClient(['verbose' => true]);
} else {
    echo "Using Elasticsearch provider\n";
    $client = new ElasticsearchClient(['verbose' => true]);
}

$embeddingClient = new EmbeddingClient();

$index = $client->getDocumentsIndexName();
echo "🚀 Starting backfill of 1024-dim vectors for index: $index\n";

$batchSize = 100; // Increased batch size for faster processing

if ($targetId) {
    echo "🎯 Targeting specific document ID: $targetId\n";
    $params = [
        'index' => $index,
        'body' => [
            'query' => [
                'ids' => [
                    'values' => [$targetId]
                ]
            ],
            '_source' => ['text']
        ]
    ];
} else {
    $params = [
        'index' => $index,
        'scroll' => '10m',
        'size' => $batchSize,
        'body' => [
            'query' => [
                'bool' => [
                    'must_not' => [
                        'exists' => ['field' => 'text_vector_1024']
                    ]
                ]
            ],
            '_source' => ['text']
        ]
    ];
}

try {
    $response = $client->getRawClient()->search($params)->asArray();
    $scrollId = $response['_scroll_id'] ?? null;
    $hits = $response['hits']['hits'];
    $totalToProcess = $response['hits']['total']['value'] ?? 0;

    if ($targetId && count($hits) === 0) {
        echo "❌ Document ID $targetId not found.\n";
        exit(0);
    }

    echo "Found $totalToProcess documents needing backfill.\n";

    $processed = 0;
    $httpClient = $embeddingClient->getHttpClient();
    $baseUrl = $embeddingClient->getBaseUrl();

    while (count($hits) > 0) {
        $promises = [];
        $batchIds = [];
        $skipped = 0;
        
        foreach ($hits as $hit) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            
            $id = $hit['_id'];
            $text = $hit['_source']['text'] ?? '';
            
            if (empty($text)) {
                $skipped++;
                continue;
            }
            
            $batchIds[] = $id;
            $promises[$id] = $httpClient->postAsync($baseUrl . '/embed', [
                'json' => [
                    'text' => $text,
                    'prefix' => 'passage: ',
                    'model' => EmbeddingClient::MODEL_LARGE
                ]
            ]);
            
            $processed++;
        }
        
        if ($skipped > 0) {
            echo "⚠️ Skipped $skipped empty documents in this batch.\n";
        }
        
        if (empty($promises)) {
            if (count($hits) > 0 && ($limit === null || $processed < $limit)) {
                // All docs in this batch were empty, continue to next batch
                goto next_batch;
            }
            break;
        }

        echo "[$processed/$totalToProcess] Requesting " . count($promises) . " embeddings in parallel... ";
        $results = Utils::settle($promises)->wait();
        echo "Done.\n";

        $bulkParams = ['body' => []];
        foreach ($results as $id => $result) {
            if ($result['state'] === 'fulfilled') {
                $response = $result['value'];
                $data = json_decode($response->getBody()->getContents(), true);
                $vector = $data['embedding'] ?? null;
                
                if ($vector && is_array($vector) && count($vector) === 1024) {
                    $bulkParams['body'][] = [
                        'update' => [
                            '_index' => $index,
                            '_id' => $id,
                            'retry_on_conflict' => 3
                        ]
                    ];
                    $bulkParams['body'][] = [
                        'doc' => [
                            'text_vector_1024' => $vector
                        ]
                    ];
                } else {
                    echo "❌ $id: Invalid vector returned\n";
                }
            } else {
                echo "❌ $id: Error: " . $result['reason']->getMessage() . "\n";
            }
        }
        
        if (!empty($bulkParams['body'])) {
            $response = $client->getRawClient()->bulk($bulkParams);
            $bulkData = is_array($response) ? $response : $response->asArray();
            
            if (isset($bulkData['errors']) && $bulkData['errors']) {
                echo "❌ Bulk errors detected!\n";
                foreach ($bulkData['items'] as $item) {
                    $op = array_keys($item)[0];
                    if (isset($item[$op]['error'])) {
                        echo "  ID {$item[$op]['_id']}: " . json_encode($item[$op]['error']) . "\n";
                    }
                }
            } else {
                echo "✅ Bulk update successful (" . (count($bulkParams['body'])/2) . " docs)\n";
            }
        }

        if ($limit !== null && $processed >= $limit) {
            echo "\nReached limit of $limit documents. Stopping.\n";
            break;
        }

        if ($targetId) {
            break;
        }
        
        next_batch:
        if ($targetId) {
            break;
        }
        // Get next batch
        $response = $client->getRawClient()->scroll([
            'scroll_id' => $scrollId,
            'scroll' => '10m'
        ])->asArray();
        
        $scrollId = $response['_scroll_id'];
        $hits = $response['hits']['hits'];
    }

    // Clean up scroll
    if ($scrollId) {
        $client->getRawClient()->clearScroll(['scroll_id' => $scrollId]);
    }

} catch (\Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✨ Backfill completed! Processed $processed documents.\n";

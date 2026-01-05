<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\EmbeddingClient;

// Increase memory limit for large embeddings
ini_set('memory_limit', '1G');

$client = new ElasticsearchClient();
$embeddingClient = $client->getEmbeddingClient();

$index = $client->getDocumentsIndexName();
echo "Backfilling 1024-dim vectors for index: $index\n";

$params = [
    'index' => $index,
    'scroll' => '5m',
    'size' => 50,
    'body' => [
        'query' => [
            'bool' => [
                'must_not' => [
                    'exists' => ['field' => 'text_vector_1024']
                ]
            ]
        ]
    ]
];

try {
    $response = $client->getRawClient()->search($params)->asArray();
} catch (\Exception $e) {
    die("Initial search failed: " . $e->getMessage() . "\n");
}

$totalUpdated = 0;

while (count($response['hits']['hits']) > 0) {
    $scroll_id = $response['_scroll_id'];
    $bulkParams = ['body' => []];

    foreach ($response['hits']['hits'] as $hit) {
        $id = $hit['_id'];
        $text = $hit['_source']['text'] ?? '';
        
        if (empty($text)) {
            echo "Skipping $id: No text\n";
            continue;
        }

        echo "Embedding $id (" . strlen($text) . " chars)...\n";
        try {
            $vector = $embeddingClient->embedText($text, 'passage: ', EmbeddingClient::MODEL_LARGE);
            
            if ($vector && is_array($vector) && count($vector) === 1024) {
                $bulkParams['body'][] = [
                    'update' => [
                        '_index' => $index,
                        '_id' => $id
                    ]
                ];
                $bulkParams['body'][] = [
                    'doc' => [
                        'text_vector_1024' => $vector
                    ]
                ];
            } else {
                echo "❌ Failed to get valid 1024D vector for $id\n";
            }
        } catch (\Exception $e) {
            echo "❌ Error embedding $id: " . $e->getMessage() . "\n";
        }
    }

    if (!empty($bulkParams['body'])) {
        try {
            $client->getRawClient()->bulk($bulkParams);
            $count = count($bulkParams['body']) / 2;
            $totalUpdated += $count;
            echo "✅ Updated $count documents (Total: $totalUpdated)\n";
        } catch (\Exception $e) {
            echo "❌ Bulk update failed: " . $e->getMessage() . "\n";
        }
    }

    try {
        $response = $client->getRawClient()->scroll([
            'scroll_id' => $scroll_id,
            'scroll' => '5m'
        ])->asArray();
    } catch (\Exception $e) {
        echo "❌ Scroll failed: " . $e->getMessage() . "\n";
        break;
    }
}

echo "Finished! Total documents updated: $totalUpdated\n";

<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../providers/Elasticsearch/src/EmbeddingClient.php';
require_once __DIR__ . '/../providers/Elasticsearch/src/ElasticsearchClient.php';
require_once __DIR__ . '/../providers/OpenSearch/src/OpenSearchClient.php';

use HawaiianSearch\OpenSearchClient;

$client = new OpenSearchClient(['indexName' => 'hawaiian']);
$modes = ["vectorsentence", "hybridsentence", "knnsentence", "vector", "hybrid", "knn"];
$queryText = "aloha";

foreach ($modes as $mode) {
    echo "Testing mode: $mode\n";
    try {
        $results = $client->search($queryText, $mode, ['size' => 1]);
        if ($results && isset($results['hits']['total']['value'])) {
            echo "✅ Success: Found " . $results['hits']['total']['value'] . " results\n";
        } else {
            echo "❌ Failed: No results or invalid response\n";
            echo "Response: " . json_encode($results) . "\n";
        }
    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
    echo "-------------------\n";
}

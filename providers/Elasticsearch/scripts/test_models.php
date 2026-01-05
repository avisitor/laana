<?php
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../src/EmbeddingClient.php';

use HawaiianSearch\EmbeddingClient;

$client = new EmbeddingClient();

echo "Testing Small Model (384D)...\n";
$small = $client->embedText("Aloha", "query: ", EmbeddingClient::MODEL_SMALL);
if ($small) {
    echo "✅ Small: " . count($small) . " dimensions\n";
} else {
    echo "❌ Small failed\n";
}

echo "Testing Large Model (1024D)...\n";
$large = $client->embedText("Aloha", "query: ", EmbeddingClient::MODEL_LARGE);
if ($large) {
    echo "✅ Large: " . count($large) . " dimensions\n";
} else {
    echo "❌ Large failed\n";
}

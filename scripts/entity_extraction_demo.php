<?php
// Demo script to show entity recognition and relationship extraction
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/ProviderFactory.php';

use Noiiolelo\ProviderFactory;
use Noiiolelo\Providers\Neo4j\Neo4jProvider;

// Example text with entities
$text = "Kamehameha founded Lahainaluna School. Kamehameha lived in Hawaii. 
         Lahainaluna School is located in Maui. The school was established in 1831.";

echo "Original text:\n";
echo $text . "\n\n";

// Create a basic Neo4j provider
$neo4j = new Neo4jProvider([
    'uri' => 'bolt://localhost:7687', 
    'username' => 'neo4j',
    'password' => 'password'
]);

// Extract entities
$entities = $neo4j->extractEntities($text);
echo "Extracted entities:\n";
foreach ($entities as $entity) {
    echo "- {$entity['name']} ({$entity['type']}) with ID: {$entity['id']}\n";
}
echo "\n";

// Extract relationships
$relationships = $neo4j->extractRelationships($text);
echo "Extracted relationships:\n";
foreach ($relationships as $rel) {
    echo "- {$rel['source']} -> {$rel['relation']} -> {$rel['target']}\n";
}
echo "\n";

// Show what a complete entity+relationship structure would look like
echo "Complete entity/relationship JSON structure:\n";
$completeResult = [
    'entities' => $entities,
    'relationships' => $relationships
];
echo json_encode($completeResult, JSON_PRETTY_PRINT);
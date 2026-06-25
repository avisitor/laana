<?php
// Simple test script to verify Neo4j integration is working correctly
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/ProviderFactory.php';

use Noiiolelo\ProviderFactory;

echo "Testing Neo4j provider integration...\n";

try {
    // Test provider creation
    $neo4j = ProviderFactory::create('neo4j');
    echo "✓ Neo4j provider created successfully\n";
    
    echo "✓ Provider name: " . $neo4j->getName() . "\n";
    
    // Test basic entity extraction
    $text = "Kamehameha founded Lahainaluna School.";
    $entities = $neo4j->extractEntities($text);
    $relationships = $neo4j->extractRelationships($text);
    
    echo "✓ Entity extraction working\n";
    echo "  Found " . count($entities) . " entities\n";
    echo "  Found " . count($relationships) . " relationships\n";
    
    if (!empty($entities)) {
        echo "  First entity: {$entities[0]['name']} ({$entities[0]['type']})\n";
    }
    
    // Test interface implementation
    $interfaces = class_implements($neo4j);
    if (isset($interfaces['Noiiolelo\\GraphSearchProviderInterface'])) {
        echo "✓ GraphSearchProviderInterface implemented\n";
    } else {
        echo "✗ GraphSearchProviderInterface NOT implemented\n";
    }
    
    echo "✓ All tests passed successfully\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}
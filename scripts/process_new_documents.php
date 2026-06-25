<?php
// Script to process new documents as they're ingested
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/ProviderFactory.php';
require_once __DIR__ . '/../lib/EntityExtractionService.php';

use Noiiolelo\ProviderFactory;
use Noiiolelo\EntityExtractionService;

// Initialize providers
$neo4jProvider = ProviderFactory::create('neo4j', [
    'uri' => 'bolt://localhost:7687',
    'username' => 'neo4j', 
    'password' => 'password'
]);

$elasticProvider = ProviderFactory::create('elasticsearch');

// Create entity extraction service
$service = new EntityExtractionService($neo4jProvider, $elasticProvider);

// Process a new document that has been ingested
if (isset($argv[1])) {
    $docId = $argv[1];
    echo "Processing document {$docId}...\n";
    
    $result = $service->processDocument($docId);
    
    echo "Processed: {$result['status']}\n";
    echo "Entities extracted: {$result['entities']}\n";
    echo "Relationships extracted: {$result['relationships']}\n";
    echo "Entities added: " . ($result['entities_added'] ? 'Yes' : 'No') . "\n";
    echo "Relationships added: " . ($result['relationships_added'] ? 'Yes' : 'No') . "\n";
} else {
    echo "Usage: php process_new_documents.php <document_id>\n";
    echo "Example: php process_new_documents.php doc123\n";
}
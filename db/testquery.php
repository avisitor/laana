#!/usr/bin/php
<?php
include_once __DIR__ . '/parsehtml.php';

echo "Testing BaibalaHTML class...\n";

try {
    $parser = new BaibalaHTML();
    echo "BaibalaHTML instance created successfully.\n";
    
    $documents = $parser->getDocumentList();
    echo "Number of documents found: " . count($documents) . "\n";
    
    if (count($documents) > 0) {
        echo "First document: " . print_r($documents[0], true) . "\n";
        
        $testUrl = $documents[0]['url'];
        echo "Testing URL: $testUrl\n";
        
        $sentences = $parser->extractSentences($testUrl);
        echo "Number of sentences extracted: " . count($sentences) . "\n";
        
        // Show first few sentences for verification
        for ($i = 0; $i < min(10, count($sentences)); $i++) {
            echo "Sentence " . ($i + 1) . ": " . $sentences[$i] . "\n";
        }
    } else {
        echo "No documents found in BaibalaHTML.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
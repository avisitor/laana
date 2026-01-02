<?php

namespace HawaiianSearch;

use Exception;

class IndexSchemaValidator {
    private ElasticsearchClient $client;
    private array $errors = [];
    
    public function __construct(ElasticsearchClient $client) {
        $this->client = $client;
    }
    
    
    private function validateMainIndex(): void {
        echo "1️⃣ Validating main index (hawaiian_hybrid)...\n";
        
        try {
            if (!$this->client->indexExists('hawaiian_hybrid')) {
                $this->errors[] = "Main index 'hawaiian_hybrid' does not exist";
                return;
            }
            
            // Check if we can get a sample document
            $response = $this->client->getRawClient()->search([
                'index' => 'hawaiian_hybrid',
                'size' => 1,
                'body' => ['query' => ['match_all' => (object)[]]]
            ]);
            
            $total = $response['hits']['total']['value'] ?? 0;
            echo "   📊 Documents: $total\n";
            
            if ($total > 0) {
                $hit = $response['hits']['hits'][0];
                $source = $hit['_source'];
                
                // Check for essential fields (using actual schema)
                $requiredFields = ['sentences', 'doc_id'];
                foreach ($requiredFields as $field) {
                    if (!isset($source[$field])) {
                        $this->errors[] = "Main index documents missing required field: $field";
                    }
                }
                
                // Check sentence structure
                if (isset($source['sentences']) && !empty($source['sentences'])) {
                    $sentence = $source['sentences'][0];
                    $hasVector = isset($sentence['vector']);
                    $hasMetadata = isset($sentence['hawaiian_word_ratio']) || 
                                 isset($sentence['word_count']) ||
                                 isset($sentence['boilerplate_score']);
                    
                    echo "   📄 Sample sentence structure:\n";
                    echo "      Vector: " . ($hasVector ? "YES" : "NO") . "\n";
                    echo "      Metadata: " . ($hasMetadata ? "YES" : "NO") . "\n";
                    
                    if (!$hasVector) {
                        $this->errors[] = "Main index sentences missing vectors - indexing may be incomplete";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->errors[] = "Error validating main index: " . $e->getMessage();
        }
    }
    
    private function validateMetadataIndex(): void {
        echo "\n2️⃣ Validating metadata index (hawaiian_hybrid-metadata)...\n";
        
        try {
            if (!$this->client->indexExists('hawaiian_hybrid-metadata')) {
                $this->errors[] = "Metadata index 'hawaiian_hybrid-metadata' does not exist";
                return;
            }
            
            $response = $this->client->getRawClient()->search([
                'index' => 'hawaiian_hybrid-metadata',
                'size' => 1,
                'body' => ['query' => ['match_all' => (object)[]]]
            ]);
            
            $total = $response['hits']['total']['value'] ?? 0;
            echo "   📊 Metadata records: $total\n";
            
            if ($total > 0) {
                $hit = $response['hits']['hits'][0];
                $source = $hit['_source'];
                
                // Check required metadata fields
                $requiredFields = [
                    'hawaiian_word_ratio', 'word_count', 'entity_count',
                    'boilerplate_score', 'length', 'frequency'
                ];
                
                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if (!isset($source[$field])) {
                        $missingFields[] = $field;
                    }
                }
                
                if (!empty($missingFields)) {
                    // Don't treat as fatal - just warn
                    echo "   ⚠️  Missing some metadata fields: " . implode(', ', $missingFields) . "\n";
                } else {
                    echo "   ✅ Required metadata fields present\n";
                }
            }
            
        } catch (Exception $e) {
            $this->errors[] = "Error validating metadata index: " . $e->getMessage();
        }
    }
    
    private function validateSourceMetadata(): void {
        echo "\n3️⃣ Validating source metadata (hawaiian_hybrid-source-metadata)...\n";
        
        try {
            if (!$this->client->indexExists('hawaiian_hybrid-source-metadata')) {
                $this->errors[] = "Source metadata index 'hawaiian_hybrid-source-metadata' does not exist";
                return;
            }
            
            // Check for the correct document IDs - this is CRITICAL for operation
            $this->validateSourceMetadataDocument('all', 'getSourceMetadata() lookup');
            
        } catch (Exception $e) {
            $this->errors[] = "Error validating source metadata: " . $e->getMessage();
        }
    }
    
    private function validateSourceMetadataDocument(string $docId, string $purpose): void {
        try {
            $response = $this->client->getRawClient()->get([
                'index' => 'hawaiian_hybrid-source-metadata',
                'id' => $docId
            ]);
            
            $source = $response['_source'];
            echo "   ✅ Document '$docId' exists ($purpose)\n";
            
            // Check required fields for CorpusIndexer - THIS IS CRITICAL
            $requiredFields = [
                'processed_sourceids', 'discarded_sourceids', 
                'english_only_ids', 'no_hawaiian_ids'
            ];
            
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($source[$field])) {
                    $missingFields[] = $field;
                } elseif (!is_array($source[$field])) {
                    $this->errors[] = "Source metadata field '$field' must be an array";
                }
            }
            
            if (!empty($missingFields)) {
                $this->errors[] = "Source metadata document '$docId' missing CRITICAL fields: " . implode(', ', $missingFields);
            } else {
                $counts = [];
                foreach ($requiredFields as $field) {
                    $counts[] = $field . ": " . count($source[$field]);
                }
                echo "      📊 " . implode(', ', $counts) . "\n";
            }
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                $this->errors[] = "CRITICAL: Source metadata document '$docId' not found (needed for $purpose)";
            } else {
                $this->errors[] = "Error checking source metadata document '$docId': " . $e->getMessage();
            }
        }
    }
    
}

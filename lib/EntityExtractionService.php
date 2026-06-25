<?php
namespace Noiiolelo;

use Noiiolelo\Providers\Neo4j\Neo4jProvider;
use Noiiolelo\Providers\Elasticsearch\ElasticsearchProvider;

class EntityExtractionService
{
    private $neo4jProvider;
    private $elasticProvider;
    
    public function __construct(Neo4jProvider $neo4jProvider, ElasticsearchProvider $elasticProvider)
    {
        $this->neo4jProvider = $neo4jProvider;
        $this->elasticProvider = $elasticProvider;
    }
    
    /**
     * Process documents from Elasticsearch and extract entities/relationships
     * 
     * @param array $options Options for processing (limit, sourceID, etc.)
     * @return array Processing results
     */
    public function processExistingDocuments(array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $sourceId = $options['source_id'] ?? null;
        $offset = $options['offset'] ?? 0;
        
        try {
            // Get documents from Elasticsearch
            $documents = $this->elasticProvider->getAllSourceIds();
            
            // Process documents
            $processedCount = 0;
            $entityCount = 0;
            $relationshipCount = 0;
            
            // Get first few documents to process
            $docLimit = min($limit, count($documents));
            for ($i = $offset; $i < $docLimit; $i++) {
                $docId = $documents[$i];
                
                // Get document text (simulating what would be retrieved)
                $doc = $this->elasticProvider->getDocument($docId, 'text');
                if (!$doc) {
                    continue;
                }
                $text = $doc['content'] ?? $doc['text'] ?? (is_string($doc) ? $doc : '');
                if (empty($text)) {
                    continue;
                }
                
                // Extract entities and relationships
                $entities = $this->neo4jProvider->extractEntities($text);
                $relationships = $this->neo4jProvider->extractRelationships($text);
                
                // Store in Neo4j
                if (!empty($entities)) {
                    $this->neo4jProvider->addEntities($entities);
                    $entityCount += count($entities);
                }
                
                if (!empty($relationships)) {
                    $this->neo4jProvider->addRelationships($relationships);
                    $relationshipCount += count($relationships);
                }
                
                $processedCount++;
            }
            
            return [
                'processed' => $processedCount,
                'entities' => $entityCount,
                'relationships' => $relationshipCount,
                'status' => 'completed'
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process a single document for entity extraction
     * 
     * @param string $docId Document ID to process
     * @return array Processing results
     */
    public function processDocument(string $docId): array
    {
        try {
            // Get document text from Elasticsearch (or another source)
            $doc = $this->elasticProvider->getDocument($docId, 'text');
            if (!$doc || !isset($doc['content'])) {
                return [
                    'status' => 'error',
                    'message' => 'Document not found'
                ];
            }
            
            $text = $doc['content'];
            
            // Extract entities and relationships
            $entities = $this->neo4jProvider->extractEntities($text);
            $relationships = $this->neo4jProvider->extractRelationships($text);
            
            // Store in Neo4j
            $entityAdded = false;
            $relationshipAdded = false;
            
            if (!empty($entities)) {
                $entityAdded = $this->neo4jProvider->addEntities($entities);
            }
            
            if (!empty($relationships)) {
                $relationshipAdded = $this->neo4jProvider->addRelationships($relationships);
            }
            
            return [
                'doc_id' => $docId,
                'entities' => count($entities),
                'relationships' => count($relationships),
                'entities_added' => $entityAdded,
                'relationships_added' => $relationshipAdded,
                'status' => 'completed'
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Set up automatic processing during ingestion
     * 
     * @param callable $callback Function to call after processing
     * @return void
     */
    public function setupAutomaticProcessing(callable $callback = null): void
    {
        // This would be integrated with the ingestion pipeline
        // For now it just demonstrates the concept
        if ($callback) {
            $callback('Entity extraction service initialized');
        }
    }
    
    /**
     * Get statistics about entity database
     * 
     * @return array Database statistics
     */
    public function getDatabaseStats(): array
    {
        try {
            // Query Neo4j for entity/relationship counts
            $entities = $this->neo4jProvider->graphQuery("MATCH (n) RETURN count(n) as count");
            $relationships = $this->neo4jProvider->graphQuery("MATCH ()-[r]->() RETURN count(r) as count");
            
            return [
                'entities' => $entities[0]['count'] ?? 0,
                'relationships' => $relationships[0]['count'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'entities' => 0,
                'relationships' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
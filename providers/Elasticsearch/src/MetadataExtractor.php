<?php

namespace HawaiianSearch;

class MetadataExtractor
{
    private ElasticsearchClient $client;
    private CorpusScanner $scanner;
    
    public function __construct(ElasticsearchClient $client, array $hawaiianWordSet = [])
    {
        $this->client = $client;
        
        // Initialize CorpusScanner with Hawaiian word set for shared functionality
        $options = [
            'hawaiianWords' => $hawaiianWordSet,
            'dryrun' => false
        ];
        $this->scanner = new CorpusScanner($client, $options);
    }
    
    /**
     * Delegated methods to CorpusScanner for shared functionality
     */
    public static function hashSentence(string $text): string
    {
        return CorpusScanner::hashSentence($text);
    }
    
    public static function normalizeWord(string $word): string
    {
        return CorpusScanner::normalizeWord($word);
    }
    
    public function calculateHawaiianWordRatio(string $text): float
    {
        return $this->scanner->calculateHawaiianWordRatio($text);
    }
    
    public function calculateEntityCount(string $text): int
    {
        return $this->scanner->calculateEntityCount($text);
    }
    
    public function computeBoilerplateScore(string $text, int $entityCount): float
    {
        return $this->scanner->computeBoilerplateScore($text, $entityCount);
    }
    
    /**
     * Analyze a sentence and return metadata - delegates to CorpusScanner
     */
    public function analyzeSentence(string $text, string $docId, array $existingMetadata = null): array
    {
        return $this->scanner->analyzeSentence($text, $docId, $existingMetadata);
    }
    
    /**
     * Save sentence metadata to the metadata index
     */
    
    /**
     * Get existing sentence metadata
     */
    public function getSentenceMetadata(string $text): ?array
    {
        $sentenceHash = self::hashSentence($text);
        
        try {
            // Get the underlying Elasticsearch client
            $reflection = new \ReflectionClass($this->client);
            $clientProperty = $reflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $esClient = $clientProperty->getValue($this->client);
            
            $response = $esClient->get([
                'index' => $this->client->getIndexName() . '-metadata',
                'id' => $sentenceHash
            ]);
            
            return $response['_source'];
            
        } catch (\Exception $e) {
            // Document doesn't exist or other error
            return null;
        }
    }
    
    /**
     * Bulk save multiple sentence metadata documents
     */
    public function bulkSaveSentenceMetadata(array $sentencesData): void
    {
        if (empty($sentencesData)) {
            return;
        }
        
        try {
            // Get the underlying Elasticsearch client
            $reflection = new \ReflectionClass($this->client);
            $clientProperty = $reflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $esClient = $clientProperty->getValue($this->client);
            
            $actions = [];
            foreach ($sentencesData as $sentenceData) {
                $text = $sentenceData['text'];
                $docId = $sentenceData['doc_id'];
                $position = $sentenceData['position'] ?? 0;
                
                $sentenceHash = self::hashSentence($text);
                $existingMetadata = $this->getSentenceMetadata($text);
                $metadata = $this->analyzeSentence($text, $docId, $existingMetadata);
                
                // Update position information
                $metadata['metadata']['positions'] = [$position];
                
                $actions[] = [
                    'index' => [
                        '_index' => $this->client->getIndexName() . '-metadata',
                        '_id' => $sentenceHash
                    ]
                ];
                $actions[] = $metadata;
            }
            
            if (!empty($actions)) {
                $esClient->bulk(['body' => $actions]);
            }
            
        } catch (\Exception $e) {
            error_log("Failed to bulk save sentence metadata: " . $e->getMessage());
        }
    }
    
    /**
     * Create the metadata index with proper mapping
     */
    public function createMetadataIndex(bool $recreate = false): void
    {
        try {
            // Get the underlying Elasticsearch client
            $reflection = new \ReflectionClass($this->client);
            $clientProperty = $reflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $esClient = $clientProperty->getValue($this->client);
            
            $indexName = $this->client->getIndexName() . '-metadata';
            
            if ($recreate && $esClient->indices()->exists(['index' => $indexName])) {
                $esClient->indices()->delete(['index' => $indexName]);
                echo "🗑️  Deleted existing metadata index: {$indexName}\n";
            }
            
            if (!$esClient->indices()->exists(['index' => $indexName])) {
                $mapping = [
                    'mappings' => [
                        'properties' => [
                            'sentence_hash' => ['type' => 'keyword'],
                            'frequency' => ['type' => 'integer'],
                            'length' => ['type' => 'integer'],
                            'entity_count' => ['type' => 'integer'],
                            'word_count' => ['type' => 'integer'],
                            'hawaiian_word_ratio' => ['type' => 'float'],
                            'boilerplate_score' => ['type' => 'float'],
                            'metadata' => [
                                'type' => 'object',
                                'properties' => [
                                    'doc_ids' => ['type' => 'keyword'],
                                    'positions' => ['type' => 'integer']
                                ]
                            ]
                        ]
                    ]
                ];
                
                $esClient->indices()->create([
                    'index' => $indexName,
                    'body' => $mapping
                ]);
                
                echo "✅ Created metadata index: {$indexName}\n";
            }
            
        } catch (\Exception $e) {
            error_log("Failed to create metadata index: " . $e->getMessage());
            throw $e;
        }
    }

    public function recalculateSentenceMetadata(): void
    {
        echo "Recalculating sentence metadata for all documents...\n";
        $iterator = new ElasticsearchScrollIterator($this->client, $this->client->getIndexName());
        $sentenceBatch = [];
        $batchSize = 100;

        while ($docs = $iterator->getNext()) {
            foreach ($docs as $doc) {
                if (isset($doc['_source']['sentences']) && is_array($doc['_source']['sentences'])) {
                    foreach ($doc['_source']['sentences'] as $sentence) {
                        $sentenceBatch[] = [
                            'text' => $sentence['text'],
                            'doc_id' => $doc['_source']['doc_id'],
                            'position' => $sentence['position']
                        ];

                        if (count($sentenceBatch) >= $batchSize) {
                            $this->bulkSaveSentenceMetadata($sentenceBatch);
                            $sentenceBatch = [];
                        }
                    }
                }
            }
        }

        if (!empty($sentenceBatch)) {
            $this->bulkSaveSentenceMetadata($sentenceBatch);
        }

        echo "Finished recalculating sentence metadata.\n";
    }
}

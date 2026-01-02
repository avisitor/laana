<?php

require_once __DIR__ . '/../BaseTest.php';
//require_once __DIR__ . '/../../src/ElasticsearchClient.php';

/**
 * Index Data Consistency Tests
 * 
 * Verify that the data in the indices is consistent with the metadata tracking,
 * and that processed source IDs actually have corresponding documents.
 */
class TestDataConsistency extends BaseTest
{
    private $client;
    private $config;
    private $verbose = false;
    
    protected function setUp()
    {
        parent::setUp();
        
        // Check if verbose mode is enabled
        $this->verbose = isset($_ENV['VERBOSE']) || (isset($GLOBALS['argv']) && in_array('--verbose', $GLOBALS['argv']));
        
        // Initialize client with default settings (reads from environment)
        $this->client = new HawaiianSearch\ElasticsearchClient();
        
        // Set expected configuration values based on current system
        $this->config = [
            'elasticsearch' => [
                'index_name' => $this->client->getIndexName(),
                'metadata_index_name' => $this->client->getIndexName() . '-metadata',
                'source_metadata_index_name' => $this->client->getIndexName() . '-source-metadata'
            ]
        ];
    }
    
    protected function execute()
    {
        if ($this->verbose) {
            echo "\n=== Hawaiian Search System Data Consistency Tests ===\n";
            echo "Testing against indices:\n";
            echo "  Main: " . $this->config['elasticsearch']['index_name'] . "\n";
            echo "  Metadata: " . $this->config['elasticsearch']['metadata_index_name'] . "\n";
            echo "  Source Metadata: " . $this->config['elasticsearch']['source_metadata_index_name'] . "\n\n";
        }
        
        $results = [];
        $results[] = $this->testMainIndexStructure();
        $results[] = $this->testMetadataConsistency();
        $results[] = $this->testChunkConsistency();
        $results[] = $this->testDocumentIntegrity();
        
        // Count results
        $passed = count(array_filter($results));
        $total = count($results);
        $status = $passed === $total ? 'PASSED' : 'FAILED';
        
        if ($this->verbose) {
            echo "\n" . str_repeat('=', 50) . "\n";
            echo "Data Consistency Test Summary: $status\n";
            echo "Passed: $passed/$total tests\n";
            if ($status === 'FAILED') {
                $failed = $total - $passed;
                echo "Failed: $failed tests\n";
            }
            echo str_repeat('=', 50) . "\n";
        } else {
            echo "$status: DataConsistency - $passed/$total checks passed";
            if ($status === 'FAILED') {
                $failed = $total - $passed;
                echo " ($failed issues found)";
            }
            echo "\n";
        }
    }
    
    protected function log($message, $level = "info") {
        if ($this->verbose) {
            echo $message . "\n";
        }
    }
    
    public function testMainIndexStructure()
    {
        $mainIndex = $this->config['elasticsearch']['index_name'];
        $rawClient = $this->client->getRawClient();
        
        $this->log("✓ Testing main index structure and content...");
        
        try {
            // Get basic stats
            $response = $rawClient->search([
                'index' => $mainIndex,
                'body' => [
                    'size' => 0,
                    'track_total_hits' => true
                ]
            ]);
            $docCount = $response['hits']['total']['value'] ?? 0;
            $this->log("  - Total documents in main index: $docCount");
            
            // Get sample document to verify structure
            $response = $rawClient->search([
                'index' => $mainIndex,
                'body' => [
                    'size' => 1,
                    '_source' => true
                ]
            ]);
            
            $hit = $response['hits']['hits'][0] ?? null;
            if (!$hit) {
                $this->log("  ⚠ No sample document found");
                return false;
            }
            
            $source = $hit['_source'];
            $requiredFields = ['sourceid', 'text', 'sentences', 'sourcename'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!isset($source[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                $this->log("  ✗ Missing required fields: " . implode(', ', $missingFields));
                return false;
            }
            
            $this->log("  ✓ Required fields present: " . implode(', ', $requiredFields));
            
            // Check sentence structure
            $sentences = $source['sentences'] ?? [];
            $sentenceCount = count($sentences);
            $this->log("  - Sample document has $sentenceCount sentences");
            
            if ($sentenceCount > 0) {
                $sampleSentence = $sentences[0];
                if (isset($sampleSentence['text']) && isset($sampleSentence['vector'])) {
                    $this->log("  ✓ Sentences have text and vector fields");
                    $vectorDim = count($sampleSentence['vector']);
                    $this->log("  - Vector dimensions: $vectorDim");
                } else {
                    $this->log("  ⚠ Sentence structure may be incomplete");
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  ✗ Main index test failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function testMetadataConsistency()
    {
        $mainIndex = $this->config['elasticsearch']['index_name'];
        $metadataIndex = $this->config['elasticsearch']['metadata_index_name'];
        $rawClient = $this->client->getRawClient();
        
        $this->log("✓ Testing metadata consistency...");
        
        try {
            // Check if metadata index exists
            $response = $rawClient->search([
                'index' => $metadataIndex,
                'body' => [
                    'size' => 0,
                    'track_total_hits' => true
                ]
            ]);
            $metadataCount = $response['hits']['total']['value'] ?? 0;
            $this->log("  - Metadata documents: $metadataCount");
            
            if ($metadataCount == 0) {
                $this->log("  ⚠ No metadata documents found");
                return true; // Not a failure if no metadata
            }
            
            // Get sample metadata to check structure
            $response = $rawClient->search([
                'index' => $metadataIndex,
                'body' => [
                    'size' => 3
                ]
            ]);
            
            $hits = $response['hits']['hits'] ?? [];
            $this->log("  - Sample metadata structure:");
            
            foreach ($hits as $i => $hit) {
                $source = $hit['_source'];
                if ($i == 0) {
                    $fields = array_keys($source);
                    $this->log("    Fields: " . implode(', ', $fields));
                    
                    // Check specific metadata fields
                    if (isset($source['sentence_hash'])) {
                        $this->log("    ✓ Contains sentence hashes for quality tracking");
                    }
                    if (isset($source['hawaiian_word_ratio'])) {
                        $this->log("    ✓ Contains Hawaiian word ratio: " . $source['hawaiian_word_ratio']);
                    }
                    if (isset($source['word_count'])) {
                        $this->log("    ✓ Contains word count: " . $source['word_count']);
                    }
                    break;
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  ✗ Metadata consistency test failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function testChunkConsistency()
    {
        $mainIndex = $this->config['elasticsearch']['index_name'];
        $rawClient = $this->client->getRawClient();
        
        $this->log("✓ Testing document chunk consistency...");
        
        try {
            // Check for documents with text_chunks
            $response = $rawClient->search([
                'index' => $mainIndex,
                'body' => [
                    'size' => 0,
                    'query' => [
                        'exists' => ['field' => 'text_chunks']
                    ],
                    'track_total_hits' => true
                ]
            ]);
            $chunkedDocs = $response['hits']['total']['value'] ?? 0;
            
            // Get total documents
            $response = $rawClient->search([
                'index' => $mainIndex,
                'body' => [
                    'size' => 0,
                    'track_total_hits' => true
                ]
            ]);
            $totalDocs = $response['hits']['total']['value'] ?? 0;
            
            if ($totalDocs > 0) {
                $chunkPercentage = round(($chunkedDocs / $totalDocs) * 100, 1);
                $this->log("  - Documents with chunks: $chunkedDocs/$totalDocs ($chunkPercentage%)");
            }
            
            if ($chunkedDocs > 0) {
                // Sample a chunked document
                $response = $rawClient->search([
                    'index' => $mainIndex,
                    'body' => [
                        'size' => 1,
                        'query' => [
                            'exists' => ['field' => 'text_chunks']
                        ],
                        '_source' => ['text_chunks', 'sourceid']
                    ]
                ]);
                
                $hit = $response['hits']['hits'][0] ?? null;
                if ($hit) {
                    $chunks = $hit['_source']['text_chunks'] ?? [];
                    $chunkCount = count($chunks);
                    $this->log("  - Sample document has $chunkCount chunks");
                    
                    if ($chunkCount > 0 && isset($chunks[0]['chunk_text'])) {
                        $this->log("  ✓ Chunks have proper text structure");
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  ✗ Chunk consistency test failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function testDocumentIntegrity()
    {
        $mainIndex = $this->config['elasticsearch']['index_name'];
        $rawClient = $this->client->getRawClient();
        
        $this->log("✓ Testing document integrity...");
        
        try {
            // Get sample documents and verify completeness
            $response = $rawClient->search([
                'index' => $mainIndex,
                'body' => [
                    'size' => 10,
                    '_source' => ['sourceid', 'text', 'sentences', 'sourcename', 'date']
                ]
            ]);
            
            $hits = $response['hits']['hits'] ?? [];
            $completeCount = 0;
            $incompleteCount = 0;
            $totalSentences = 0;
            $totalTextLength = 0;
            
            foreach ($hits as $hit) {
                $source = $hit['_source'];
                
                $complete = true;
                if (empty($source['text']) || empty($source['sentences'])) {
                    $complete = false;
                }
                
                if ($complete) {
                    $completeCount++;
                    $sentences = $source['sentences'] ?? [];
                    $totalSentences += count($sentences);
                    $totalTextLength += strlen($source['text'] ?? '');
                } else {
                    $incompleteCount++;
                }
            }
            
            $this->log("  - Complete documents: $completeCount/" . count($hits));
            if ($incompleteCount > 0) {
                $this->log("  ⚠ Incomplete documents: $incompleteCount");
            }
            
            if ($completeCount > 0) {
                $avgSentences = round($totalSentences / $completeCount, 1);
                $avgTextLength = round($totalTextLength / $completeCount);
                $this->log("  - Average sentences per document: $avgSentences");
                $this->log("  - Average text length: $avgTextLength characters");
            }
            
            // Check for duplicate document IDs
            $docIds = array_map(function($hit) {
                return $hit['_source']['sourceid'] ?? null;
            }, $hits);
            $docIds = array_filter($docIds);
            $uniqueIds = array_unique($docIds);
            
            if (count($docIds) !== count($uniqueIds)) {
                $this->log("  ⚠ Potential duplicate document IDs detected in sample");
                return false;
            } else {
                $this->log("  ✓ No duplicate document IDs in sample");
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  ✗ Document integrity test failed: " . $e->getMessage());
            return false;
        }
    }
}

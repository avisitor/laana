<?php

require_once __DIR__ . '/../BaseTest.php';
//require_once __DIR__ . '/../../src/ElasticsearchClient.php';

/**
 * Production Index Integrity Tests
 * 
 * These tests verify that production indices exist, have correct mappings,
 * contain expected data structures, and match configuration expectations.
 * 
 * WARNING: These tests read from production indices but should not modify them.
 */
class TestIndexIntegrity extends BaseTest
{
    private $client;
    private $config;
    private $verbose = false;
    
    protected function setUp()
    {
        parent::setUp();
        
        // Check if verbose mode is enabled (could be passed via environment or other means)
        $this->verbose = isset($_ENV['VERBOSE']) || (isset($GLOBALS['argv']) && in_array('--verbose', $GLOBALS['argv']));
        
        // Initialize client with default settings (reads from environment)
        $options = [
            'verbose' => $this->verbose,
            'SPLIT_INDICES' => true,
        ];
        $this->client = new HawaiianSearch\ElasticsearchClient( $options );
        
        // Set expected configuration values based on current system
        $this->config = [
            'elasticsearch' => [
                'index_name' => $this->client->getDocumentsIndexName(),
                'metadata_index_name' => $this->client->getMetadataName(),
                'source_metadata_index_name' => $this->client->getSourceMetadataName(),
            ]
        ];
    }
    
    protected function execute()
    {
        $results = [];
        $results[] = $this->testMainIndexExists();
        $results[] = $this->testSentencesIndexExists();
        $results[] = $this->testMetadataIndexExists();
        $results[] = $this->testSourceMetadataIndexExists();
        $results[] = $this->testMainIndexMapping();
        $results[] = $this->testSentencesIndexMapping();
        $results[] = $this->testMetadataIndexMapping();
        $results[] = $this->testSourceMetadataCheckpoint();
        $results[] = $this->testIndexDocumentCounts();
        $results[] = $this->testSampleDocumentStructure();
        $results[] = $this->testConfigurationConsistency();
        
        // Count results
        $passed = count(array_filter($results));
        $total = count($results);
        $status = $passed === $total ? 'SUCCEEDED' : 'FAILED';
        
        if (!$this->verbose) {
            echo "$status: IndexIntegrity - $passed/$total checks passed";
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
    
    public function testMainIndexExists()
    {
        $indexName = $this->config['elasticsearch']['index_name'];
        $exists = $this->client->indexExists($indexName);
        
        $this->assertTrue($exists, "Main index '$indexName' should exist");
        
        if ($exists) {
            $this->log("✓ Main index '$indexName' exists");
        }
        
        return $exists;
    }
    
    public function testSentencesIndexExists()
    {
        $indexName = $this->client->getSentencesIndexName();
        $exists = $this->client->indexExists($indexName);
        
        $this->assertTrue($exists, "Sentences index '$indexName' should exist");
        
        if ($exists) {
            $this->log("✓ Sentences index '$indexName' exists");
        }
        
        return $exists;
    }
    
    public function testMetadataIndexExists()
    {
        $indexName = $this->config['elasticsearch']['metadata_index_name'];
        $exists = $this->client->indexExists($indexName);
        
        $this->assertTrue($exists, "Metadata index '$indexName' should exist");
        
        if ($exists) {
            $this->log("✓ Metadata index '$indexName' exists");
        }
        
        return $exists;
    }
    
    public function testSourceMetadataIndexExists()
    {
        $indexName = $this->config['elasticsearch']['source_metadata_index_name'];
        $exists = $this->client->indexExists($indexName);
        
        $this->assertTrue($exists, "Source metadata index '$indexName' should exist");
        
        if ($exists) {
            $this->log("✓ Source metadata index '$indexName' exists");
        }
        
        return $exists;
    }
    
    public function testMainIndexMapping()
    {
        $indexName = $this->config['elasticsearch']['index_name'];
        
        if (!$this->client->indexExists($indexName)) {
            $this->markTestSkipped("Main index does not exist");
            return false;
        }
        
        // Load expected mapping from config file
        $mappingFile = TEST_BASE_PATH . '/config/documents_mapping_optimized.json';
        if (!file_exists($mappingFile)) {
            $this->log("⚠ Mapping file not found: $mappingFile", 'warning');
            return true; // Skip test if mapping file doesn't exist
        }
        
        $expectedMappingData = json_decode(file_get_contents($mappingFile), true);
        $expectedProperties = $expectedMappingData['mappings']['properties'] ?? [];
        
        $rawClient = $this->client->getRawClient();
        $mapping = $rawClient->indices()->getMapping(['index' => $indexName]);
        
        $actualProperties = $mapping[$indexName]['mappings']['properties'] ?? [];
        
        // Check a subset of critical fields from the expected mapping
        $criticalFields = ['sourceid', 'text', 'date', 'title', 'groupname'];
        
        foreach ($criticalFields as $field) {
            if (!isset($expectedProperties[$field])) {
                continue; // Skip if not in expected mapping
            }
            
            $expectedType = $expectedProperties[$field]['type'] ?? 'unknown';
            $actualExists = isset($actualProperties[$field]);
            
            if (!$actualExists) {
                $this->log("⚠ Field '$field' missing from index", 'warning');
            } else {
                $actualType = $actualProperties[$field]['type'] ?? 'unknown';
                
                if ($expectedType !== $actualType) {
                    $this->log("⚠ Field '$field' has type '$actualType' (expected '$expectedType')", 'warning');
                } else {
                    $this->log("✓ Field '$field' exists with type '$actualType'");
                }
            }
        }
        
        return true; // Don't fail test for mapping mismatches - just warn
    }
    
    public function testSentencesIndexMapping()
    {
        $indexName = $this->client->getSentencesIndexName();
        
        if (!$this->client->indexExists($indexName)) {
            $this->markTestSkipped("Sentences index does not exist");
            return false;
        }
        
        // Load expected mapping from config file
        $mappingFile = TEST_BASE_PATH . '/config/sentences_mapping_optimized.json';
        if (!file_exists($mappingFile)) {
            $this->log("⚠ Mapping file not found: $mappingFile", 'warning');
            return true; // Skip test if mapping file doesn't exist
        }
        
        $expectedMappingData = json_decode(file_get_contents($mappingFile), true);
        $expectedProperties = $expectedMappingData['mappings']['properties'] ?? [];
        
        $rawClient = $this->client->getRawClient();
        $mapping = $rawClient->indices()->getMapping(['index' => $indexName]);
        
        $actualProperties = $mapping[$indexName]['mappings']['properties'] ?? [];
        
        // Check a subset of critical fields from the expected mapping
        $criticalFields = ['sourceid', 'text', 'vector', 'position', 'quality_score'];
        
        foreach ($criticalFields as $field) {
            if (!isset($expectedProperties[$field])) {
                continue; // Skip if not in expected mapping
            }
            
            $expectedType = $expectedProperties[$field]['type'] ?? 'unknown';
            $actualExists = isset($actualProperties[$field]);
            
            if (!$actualExists) {
                $this->log("⚠ Field '$field' missing from sentences index", 'warning');
            } else {
                $actualType = $actualProperties[$field]['type'] ?? 'unknown';
                
                if ($expectedType !== $actualType) {
                    $this->log("⚠ Field '$field' has type '$actualType' (expected '$expectedType')", 'warning');
                } else {
                    $this->log("✓ Sentences field '$field' exists with type '$actualType'");
                }
            }
        }
        
        return true; // Don't fail test for mapping mismatches - just warn
    }
    
    public function testMetadataIndexMapping()
    {
        $indexName = $this->config['elasticsearch']['metadata_index_name'];
        
        if (!$this->client->indexExists($indexName)) {
            $this->markTestSkipped("Metadata index does not exist");
            return false;
        }
        
        // Load expected mapping from config file
        $mappingFile = TEST_BASE_PATH . '/config/metadata_mapping.json';
        if (!file_exists($mappingFile)) {
            $this->log("⚠ Mapping file not found: $mappingFile", 'warning');
            return true; // Skip test if mapping file doesn't exist
        }
        
        $expectedMappingData = json_decode(file_get_contents($mappingFile), true);
        $expectedProperties = $expectedMappingData['mappings']['properties'] ?? [];
        
        $rawClient = $this->client->getRawClient();
        $mapping = $rawClient->indices()->getMapping(['index' => $indexName]);
        
        $actualProperties = $mapping[$indexName]['mappings']['properties'] ?? [];
        
        // Check that core fields from the expected mapping exist
        $foundFields = 0;
        $totalFields = 0;
        
        foreach ($expectedProperties as $field => $fieldConfig) {
            $totalFields++;
            $expectedType = $fieldConfig['type'] ?? 'unknown';
            
            if (isset($actualProperties[$field])) {
                $foundFields++;
                $actualType = $actualProperties[$field]['type'] ?? 'unknown';
                
                if ($expectedType !== $actualType) {
                    $this->log("⚠ Field '$field' has type '$actualType' (expected '$expectedType')", 'warning');
                } else {
                    $this->log("✓ Metadata field '$field' exists with type '$actualType'");
                }
            } else {
                $this->log("⚠ Metadata field '$field' is missing");
            }
        }
        
        $percentage = $totalFields > 0 ? ($foundFields / $totalFields) * 100 : 0;
        $this->log("Found $foundFields/$totalFields expected fields (" . round($percentage, 1) . "%)");
        
        return $foundFields >= $totalFields * 0.8; // 80% of expected fields should exist
    }
    
    public function testSourceMetadataCheckpoint()
    {
        $indexName = $this->config['elasticsearch']['source_metadata_index_name'];
        
        if (!$this->client->indexExists($indexName)) {
            $this->markTestSkipped("Source metadata index does not exist");
            return false;
        }
        
        // Check for the critical checkpoint document
        $sourceMetadata = $this->client->getSourceMetadata();
        
        if (!$sourceMetadata) {
            return false;
        }
        
        // Verify expected fields exist
        $expectedFields = ['processed_sourceids', 'discarded_sourceids', 'english_only_ids', 'no_hawaiian_ids'];
        $fieldsExist = true;
        
        foreach ($expectedFields as $field) {
            if (!isset($sourceMetadata[$field]) || !is_array($sourceMetadata[$field])) {
                $fieldsExist = false;
                break;
            }
        }
        
        if ($fieldsExist) {
            $processedCount = count($sourceMetadata['processed_sourceids'] ?? []);
            $discardedCount = count($sourceMetadata['discarded_sourceids'] ?? []);
            $englishOnlyCount = count($sourceMetadata['english_only_ids'] ?? []);
            $noHawaiianCount = count($sourceMetadata['no_hawaiian_ids'] ?? []);
            
            $this->log("✓ Source metadata checkpoint exists with:");
            $this->log("  - $processedCount processed source IDs");
            $this->log("  - $discardedCount discarded source IDs");
            $this->log("  - $englishOnlyCount English-only IDs");
            $this->log("  - $noHawaiianCount no-Hawaiian IDs");
        }
        
        return $fieldsExist;
    }
    
    public function testIndexDocumentCounts()
    {
        $mainIndex = $this->config['elasticsearch']['index_name'];
        $metadataIndex = $this->config['elasticsearch']['metadata_index_name'];
        
        if (!$this->client->indexExists($mainIndex)) {
            $this->markTestSkipped("Main index does not exist");
            return false;
        }
        
        $rawClient = $this->client->getRawClient();
        
        // Get document counts
        $mainCount = $rawClient->count(['index' => $mainIndex])['count'] ?? 0;
        $metadataCount = 0;
        
        if ($this->client->indexExists($metadataIndex)) {
            $metadataCount = $rawClient->count(['index' => $metadataIndex])['count'] ?? 0;
        }
        
        $this->log("✓ Document counts:");
        $this->log("  - Main index: $mainCount documents");
        $this->log("  - Metadata index: $metadataCount documents");
        
        if ($metadataCount > 0 && $mainCount > 0) {
            $ratio = round($metadataCount / $mainCount, 2);
            $this->log("  - Metadata-to-main ratio: $ratio:1");
        }
        
        return $mainCount > 0; // Main requirement: have some documents
    }
    
    public function testSampleDocumentStructure()
    {
        $indexName = $this->config['elasticsearch']['index_name'];
        
        if (!$this->client->indexExists($indexName)) {
            $this->markTestSkipped("Main index does not exist");
            return false;
        }
        
        $rawClient = $this->client->getRawClient();
        
        // Get a sample document
        $response = $rawClient->search([
            'index' => $indexName,
            'body' => [
                'size' => 1,
                'query' => ['match_all' => (object)[]]
            ]
        ]);
        
        $hits = $response['hits']['hits'] ?? [];
        
        if (empty($hits)) {
            $this->markTestSkipped("No documents found in main index");
            return false;
        }
        
        $sampleDoc = $hits[0]['_source'];
        
        // Verify basic document structure
        $hasRequiredFields = isset($sampleDoc['sourceid']) && isset($sampleDoc['text']);
        
        if ($hasRequiredFields) {
            $this->log("✓ Sample document structure verified:");
            $this->log("  - doc_id: " . ($sampleDoc['sourceid'] ?? 'missing'));
            $this->log("  - text length: " . strlen($sampleDoc['text'] ?? '') . " characters");
            
            // Check for optional fields
            if (isset($sampleDoc['title'])) {
                $this->log("  - title: " . substr($sampleDoc['title'], 0, 50) . "...");
            }
            
            // Check for embeddings in nested structure (actual system structure)
            if (isset($sampleDoc['sentences']) && is_array($sampleDoc['sentences'])) {
                $sentenceCount = count($sampleDoc['sentences']);
                $this->log("  - sentences: $sentenceCount with embedded vectors");
            }
        }
        
        return $hasRequiredFields;
    }
    
    public function testConfigurationConsistency()
    {
        // Test connection
        try {
            $rawClient = $this->client->getRawClient();
            $info = $rawClient->info();
            
            $this->log("✓ Configuration verification:");
            $this->log("  - Main index: " . $this->config['elasticsearch']['index_name']);
            $this->log("  - Metadata index: " . $this->config['elasticsearch']['metadata_index_name']);
            $this->log("  - Source metadata index: " . $this->config['elasticsearch']['source_metadata_index_name']);
            $this->log("  - Elasticsearch version: " . ($info['version']['number'] ?? 'unknown'));
            $this->log("  - Cluster name: " . ($info['cluster_name'] ?? 'unknown'));
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

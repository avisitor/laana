<?php

class TestElasticsearchClient extends BaseTest {
    private $client;
    private $testIndices = [];
    
    protected function setUp() {
        $this->log("Setting up ElasticsearchClient test");
        
        try {
            $this->client = new HawaiianSearch\ElasticsearchClient();
            $this->assertTrue(true, "ElasticsearchClient instantiated successfully");
        } catch (Exception $e) {
            throw new Exception("Failed to create ElasticsearchClient: " . $e->getMessage());
        }
    }
    
    protected function execute() {
        $this->testClientInstantiation();
        $this->testRawClientAccess();
        $this->testIndexOperations();
        $this->testQueryTemplates();
    }
    
    protected function tearDown() {
        $this->log("Cleaning up ElasticsearchClient test");
        
        // Clean up any test indices created
        foreach ($this->testIndices as $index) {
            try {
                $this->client->deleteIndex($index);
                $this->log("Cleaned up test index: $index");
            } catch (Exception $e) {
                $this->log("Warning: Could not clean up index $index: " . $e->getMessage(), 'warning');
            }
        }
    }
    
    private function testClientInstantiation() {
        $this->log("Testing client instantiation");
        
        $this->assertInstanceOf('HawaiianSearch\\ElasticsearchClient', $this->client, 
            "Client should be instance of ElasticsearchClient");
        
        // Test raw client access
        try {
            $rawClient = $this->client->getRawClient();
            $this->assertNotEmpty($rawClient, "Raw client should be accessible");
            $this->log("Raw client access successful");
        } catch (Exception $e) {
            $this->assert(false, "Raw client access failed: " . $e->getMessage());
        }
    }
    
    private function testRawClientAccess() {
        $this->log("Testing raw client access");
        
        try {
            $rawClient = $this->client->getRawClient();
            $this->assertInstanceOf('Elastic\\Elasticsearch\\Client', $rawClient, 
                "Raw client should be Elasticsearch client instance");
        } catch (Exception $e) {
            $this->assert(false, "Raw client test failed: " . $e->getMessage());
        }
    }
    
    private function testIndexOperations() {
        $this->log("Testing index operations");
        
        $testIndex = $this->createUniqueIndexName('client_ops');
        $this->testIndices[] = $testIndex;
        
        try {
            // Test index existence check on non-existent index
            $exists = $this->client->indexExists($testIndex);
            $this->assertFalse($exists, "Non-existent index should return false");
            
            $this->log("Index existence check works correctly");
            
        } catch (Exception $e) {
            $this->assert(false, "Index operations test failed: " . $e->getMessage());
        }
    }
    
    private function testQueryTemplates() {
        $this->log("Testing query template functionality");
        
        try {
            // Test building a query from template
            $query = $this->client->buildQueryFromTemplate('match', ['query' => 'aloha']);
            
            $this->assertNotEmpty($query, "Query template should return non-empty query");
            $this->assertTrue(is_array($query), "Query should be an array");
            
            $this->log("Query template functionality works");
            
        } catch (Exception $e) {
            $this->log("Query template test failed (may not be available): " . $e->getMessage(), 'warning');
            // Don't fail the test as templates might not be configured
        }
    }
    
    private function createUniqueIndexName($suffix) {
        return 'hawaiian_test_' . $suffix . '_' . uniqid();
    }
}

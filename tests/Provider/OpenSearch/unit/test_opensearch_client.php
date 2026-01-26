<?php

require_once __DIR__ . '/../BaseTest.php';

class TestOpenSearchClient extends OpenSearchBaseTest {
    private $client;
    
    protected function setUp() {
        $this->log("Setting up OpenSearchClient test");
        
        // Set dummy environment variables for testing if not present
        if (!getenv('API_KEY')) putenv('API_KEY=dummy_key');
        
        try {
            $this->client = new HawaiianSearch\OpenSearchClient([
                'indexName' => 'test_index',
                'verbose' => true
            ]);
            $this->log("OpenSearchClient instantiated successfully");
        } catch (Exception $e) {
            $this->log("Failed to create OpenSearchClient: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    protected function execute() {
        $this->testClientType();
        $this->testQueryBuilderType();
        $this->testHybridQuerySyntax();
    }
    
    private function testClientType() {
        $this->log("Testing client type");
        $this->assertInstanceOf('HawaiianSearch\\OpenSearchClient', $this->client);
        $this->assertInstanceOf('HawaiianSearch\\ElasticsearchClient', $this->client);
    }
    
    private function testQueryBuilderType() {
        $this->log("Testing query builder type");
        // We need to use reflection to check the protected property
        $reflection = new ReflectionClass($this->client);
        $property = $reflection->getProperty('queryBuilder');
        $property->setAccessible(true);
        $qb = $property->getValue($this->client);
        
        $this->assertInstanceOf('HawaiianSearch\\OpenSearchQueryBuilder', $qb);
        $this->assertInstanceOf('HawaiianSearch\\QueryBuilder', $qb);
    }

    private function testHybridQuerySyntax() {
        $this->log("Testing hybrid query syntax");
        $reflection = new ReflectionClass($this->client);
        $property = $reflection->getProperty('queryBuilder');
        $property->setAccessible(true);
        $qb = $property->getValue($this->client);
        
        $query = $qb->hybridQuery("test query", ['k' => 5]);
        
        $this->assertArrayHasKey('hybrid', $query, "Query should have 'hybrid' key");
        $this->assertArrayHasKey('queries', $query['hybrid'], "Hybrid query should have 'queries' key");
        $this->assertEquals(2, count($query['hybrid']['queries']), "Hybrid query should have 2 sub-queries");
        
        $this->log("Hybrid query syntax is correct");
    }
}

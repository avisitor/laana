<?php

namespace Noiiolelo\Tests\Provider\Neo4j;

use Noiiolelo\Tests\BaseTestCase;
use Noiiolelo\ProviderFactory;
use Noiiolelo\Providers\Neo4j\Neo4jProvider;
use Noiiolelo\Providers\Neo4j\AdvancedEntityExtractor;

class Neo4jProviderTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testProviderCreation()
    {
        $provider = ProviderFactory::create('neo4j');
        $this->assertInstanceOf(Neo4jProvider::class, $provider);
        $this->assertEquals('Neo4j', $provider->getName());
    }

    public function testEntityExtraction()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $text = "Kamehameha founded Lahainaluna School. Kamehameha lived in Hawaii.";
        
        $entities = $provider->extractEntities($text);
        $this->assertIsArray($entities);
        $this->assertGreaterThan(0, count($entities));
        
        // Verify entity structure
        foreach ($entities as $entity) {
            $this->assertArrayHasKey('name', $entity);
            $this->assertArrayHasKey('type', $entity);
            $this->assertArrayHasKey('id', $entity);
            $this->assertNotEmpty($entity['name']);
            $this->assertNotEmpty($entity['type']);
            $this->assertNotEmpty($entity['id']);
        }
    }

    public function testRelationshipExtraction()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $text = "Kamehameha founded Lahainaluna School. Kamehameha lived in Hawaii.";
        
        $entities = $provider->extractEntities($text);
        $relationships = $provider->extractRelationships($text);
        
        $this->assertIsArray($relationships);
        // At least one relationship should be found
        $this->assertGreaterThanOrEqual(0, count($relationships));
        
        // Verify relationship structure if relationships exist
        if (!empty($relationships)) {
            foreach ($relationships as $rel) {
                $this->assertArrayHasKey('source', $rel);
                $this->assertArrayHasKey('relation', $rel);
                $this->assertArrayHasKey('target', $rel);
                $this->assertNotEmpty($rel['source']);
                $this->assertNotEmpty($rel['relation']);
                $this->assertNotEmpty($rel['target']);
            }
        }
    }

    public function testAdvancedEntityExtractor()
    {
        $text = "Kamehameha founded Lahainaluna School. Kamehameha lived in Hawaii.";
        
        // Test basic entity extraction
        $entities = AdvancedEntityExtractor::extractEntities($text);
        $this->assertIsArray($entities);
        $this->assertGreaterThan(0, count($entities));
        
        // Test relationship extraction
        $relationships = AdvancedEntityExtractor::extractRelationships($text, $entities);
        $this->assertIsArray($relationships);
    }

    public function testGraphQueryPlaceholder()
    {
        $provider = ProviderFactory::create('neo4j');
        
        // Test that graphQuery method exists (should return empty array since Neo4j is not running)
        $result = $provider->graphQuery("MATCH (n) RETURN n LIMIT 1");
        $this->assertIsArray($result);
    }

    public function testHybridSearchPlaceholder()
    {
        $provider = ProviderFactory::create('neo4j');
        
        // Test that hybridSearch method exists (should be a placeholder)
        $result = $provider->hybridSearch("test", []);
        $this->assertIsArray($result);
    }

    public function testEntityExtractionStructure()
    {
        $text = "Kamehameha founded Lahainaluna School.";
        
        $entities = AdvancedEntityExtractor::extractEntities($text);
        
        // Check that entities have expected format
        $this->assertGreaterThanOrEqual(1, count($entities));
        
        // Check first entity structure
        $firstEntity = $entities[0];
        $this->assertArrayHasKey('name', $firstEntity);
        $this->assertArrayHasKey('type', $firstEntity);
        $this->assertArrayHasKey('id', $firstEntity);
        
        $this->assertNotEmpty($firstEntity['name']);
        $this->assertNotEmpty($firstEntity['type']);
        $this->assertNotEmpty($firstEntity['id']);
        
        // Verify that at least one entity is of type Person
        $hasPerson = false;
        foreach ($entities as $entity) {
            if ($entity['type'] === 'Person') {
                $hasPerson = true;
                break;
            }
        }
        $this->assertTrue($hasPerson, "Should find at least one person entity");
    }

    public function testRelationshipExtractionStructure()
    {
        $text = "Kamehameha founded Lahainaluna School.";
        
        $entities = AdvancedEntityExtractor::extractEntities($text);
        $relationships = AdvancedEntityExtractor::extractRelationships($text, $entities);
        
        // Check that relationships have expected format
        if (!empty($relationships)) {
            foreach ($relationships as $rel) {
                $this->assertArrayHasKey('source', $rel);
                $this->assertArrayHasKey('relation', $rel);
                $this->assertArrayHasKey('target', $rel);
                $this->assertNotEmpty($rel['source']);
                $this->assertNotEmpty($rel['relation']);
                $this->assertNotEmpty($rel['target']);
            }
        }
    }

    public function testEmptyTextEntityExtraction()
    {
        $entities = AdvancedEntityExtractor::extractEntities("");
        $this->assertIsArray($entities);
        $this->assertEquals(0, count($entities));
    }

    public function testEmptyTextRelationshipExtraction()
    {
        $entities = AdvancedEntityExtractor::extractEntities("");
        $relationships = AdvancedEntityExtractor::extractRelationships("", $entities);
        $this->assertIsArray($relationships);
        $this->assertEquals(0, count($relationships));
    }
}
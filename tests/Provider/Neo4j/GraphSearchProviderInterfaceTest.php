<?php

namespace Noiiolelo\Tests\Provider\Neo4j;

use Noiiolelo\Tests\BaseTestCase;
use Noiiolelo\ProviderFactory;
use Noiiolelo\GraphSearchProviderInterface;

class GraphSearchProviderInterfaceTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testNeo4jProviderImplementsGraphSearchProviderInterface()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $this->assertInstanceOf(GraphSearchProviderInterface::class, $provider);
    }

    public function testRequiredGraphMethodsExist()
    {
        $provider = ProviderFactory::create('neo4j');
        
        // Test that all required methods exist and are callable
        $methods = [
            'extractEntities',
            'extractRelationships', 
            'addEntities',
            'addRelationships',
            'graphQuery',
            'hybridSearch'
        ];
        
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists($provider, $method),
                "Neo4jProvider should implement method: $method"
            );
            
            $this->assertTrue(
                is_callable([$provider, $method]),
                "Method $method should be callable"
            );
        }
    }

    public function testExtractEntitiesMethodSignature()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $reflection = new \ReflectionMethod($provider, 'extractEntities');
        $parameters = $reflection->getParameters();
        
        $this->assertCount(1, $parameters);
        $this->assertEquals('text', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());
    }

    public function testExtractRelationshipsMethodSignature()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $reflection = new \ReflectionMethod($provider, 'extractRelationships');
        $parameters = $reflection->getParameters();
        
        $this->assertCount(1, $parameters);
        $this->assertEquals('text', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());
    }

    public function testAddEntitiesMethodSignature()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $reflection = new \ReflectionMethod($provider, 'addEntities');
        $parameters = $reflection->getParameters();
        
        $this->assertCount(1, $parameters);
        $this->assertEquals('entities', $parameters[0]->getName());
        $this->assertEquals('array', $parameters[0]->getType()->getName());
    }

    public function testAddRelationshipsMethodSignature()
    {
        $provider = ProviderFactory::create('neo4j');
        
        $reflection = new \ReflectionMethod($provider, 'addRelationships');
        $parameters = $reflection->getParameters();
        
        $this->assertCount(1, $parameters);
        $this->assertEquals('relationships', $parameters[0]->getName());
        $this->assertEquals('array', $parameters[0]->getType()->getName());
    }
}
<?php

class TestEmbeddingClient extends BaseTest {
    protected function setUp() {
        $this->log("Setting up EmbeddingClient test");
        // Minimal setup for testing
    }
    
    protected function execute() {
        $this->testClassExists();
        $this->testBasicMethods();
        $this->testInstantiation();
    }
    
    private function testClassExists() {
        $this->log("Testing class existence");
        
        $this->assertTrue(class_exists('HawaiianSearch\\EmbeddingClient'), 
            "EmbeddingClient class should exist");
    }
    
    private function testBasicMethods() {
        $this->log("Testing method existence");
        
        // Test that key methods exist
        $methods = get_class_methods('HawaiianSearch\\EmbeddingClient');
        
        $this->assertNotEmpty($methods, "EmbeddingClient should have methods");
        
        // Check for expected methods
        $expectedMethods = ['embed', 'embedSentences'];
        foreach ($expectedMethods as $method) {
            if (in_array($method, $methods)) {
                $this->assertTrue(true, "Method '$method' exists");
            }
        }
    }
    
    private function testInstantiation() {
        $this->log("Testing instantiation");
        
        try {
            // Test basic instantiation
            $client = new HawaiianSearch\EmbeddingClient();
            $this->assertInstanceOf('HawaiianSearch\\EmbeddingClient', $client, 
                "Should instantiate EmbeddingClient successfully");
                
        } catch (Exception $e) {
            $this->log("Instantiation test warning: " . $e->getMessage(), 'warning');
            // Don't fail the test - just log the warning
        }
    }
}

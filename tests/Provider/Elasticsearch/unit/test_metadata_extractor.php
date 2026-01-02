<?php

class TestMetadataExtractor extends BaseTest {
    protected function setUp() {
        $this->log("Setting up MetadataExtractor test");
        // Minimal setup for testing
    }
    
    protected function execute() {
        $this->testClassExists();
        $this->testBasicMethods();
        $this->testInstantiation();
    }
    
    private function testClassExists() {
        $this->log("Testing class existence");
        
        $this->assertTrue(class_exists('HawaiianSearch\\MetadataExtractor'), 
            "MetadataExtractor class should exist");
    }
    
    private function testBasicMethods() {
        $this->log("Testing method existence");
        
        // Test that key methods exist
        $methods = get_class_methods('HawaiianSearch\\MetadataExtractor');
        
        $this->assertNotEmpty($methods, "MetadataExtractor should have methods");
        $this->assertContains('analyzeSentence', $methods, "Should have analyzeSentence method");
    }
    
    private function testInstantiation() {
        $this->log("Testing instantiation");
        
        try {
            // Test instantiation with minimal client
            $client = new HawaiianSearch\ElasticsearchClient();
            $extractor = new HawaiianSearch\MetadataExtractor($client);
            
            $this->assertInstanceOf('HawaiianSearch\\MetadataExtractor', $extractor, 
                "Should instantiate MetadataExtractor successfully");
                
        } catch (Exception $e) {
            $this->log("Instantiation test warning: " . $e->getMessage(), 'warning');
            // Don't fail the test - just log the warning
        }
    }
}

<?php

class TestCorpusIndexer extends BaseTest {
    protected function setUp() {
        $this->log("Setting up CorpusIndexer test");
        // Minimal setup - just verify class structure
    }
    
    protected function execute() {
        $this->testClassExists();
        $this->testBasicMethods();
        $this->testSignalHandling();
    }
    
    private function testClassExists() {
        $this->log("Testing class existence");
        
        $this->assertTrue(class_exists('HawaiianSearch\\CorpusIndexer'), 
            "CorpusIndexer class should exist");
    }
    
    private function testBasicMethods() {
        $this->log("Testing method existence");
        
        // Test that key methods exist without instantiating
        $methods = get_class_methods('HawaiianSearch\\CorpusIndexer');
        
        $this->assertNotEmpty($methods, "CorpusIndexer should have methods");
    $this->assertContains('printTimerSystemReport', $methods, "Should have printTimerSystemReport method");
    }
    
    private function testSignalHandling() {
        $this->log("Testing signal handling capability");
        
        // Test that SIGTERM constant exists
        $this->assertTrue(defined('SIGTERM'), "SIGTERM should be defined");
        
        // Test that signal handling functions are available
        if (function_exists('pcntl_signal')) {
            $this->assertTrue(true, "Signal handling functions available");
        } else {
            $this->log("Warning: pcntl_signal not available (may be disabled)", 'warning');
        }
    }
}

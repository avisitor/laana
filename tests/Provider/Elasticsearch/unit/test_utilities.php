<?php

class TestUtilities extends BaseTest {
    protected function setUp() {
        $this->log("Setting up utilities test");
    }
    
    protected function execute() {
        $this->testBasicFunctionality();
        $this->testStringHelpers();
        $this->testArrayHelpers();
    }
    
    private function testBasicFunctionality() {
        $this->log("Testing basic functionality");
        
        // Test basic assertions work
        $this->assertTrue(true, "Basic true assertion");
        $this->assertFalse(false, "Basic false assertion");
        $this->assertEquals(1, 1, "Basic equality assertion");
        $this->assertNotEmpty("test", "Basic non-empty assertion");
    }
    
    private function testStringHelpers() {
        $this->log("Testing string helper functions");
        
        // Test string contains functionality
        $this->assertContains("aloha", "Say aloha to everyone", "String should contain substring");
        $this->assertContains("test", ["one", "test", "three"], "Array should contain element");
    }
    
    private function testArrayHelpers() {
        $this->log("Testing array helper functions");
        
        $testArray = ["a", "b", "c"];
        $this->assertEquals(3, count($testArray), "Array should have correct count");
        $this->assertContains("b", $testArray, "Array should contain expected element");
    }
}

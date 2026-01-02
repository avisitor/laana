<?php

require_once __DIR__ . '/../TestBase.php';
use HawaiianSearch\EmbeddingClient;

class TestEmbeddingIntegration extends TestBase {

    private $embeddingClient;

    protected function setUp() {
        parent::setUp();
        // Use the client that is already created in the TestBase
        $this->embeddingClient = $this->client->getEmbeddingClient();
    }

    protected function execute() {
        $this->testCanConnectToEmbeddingService();
        $this->testVectorDimensions();
    }

    private function testCanConnectToEmbeddingService() {
        $this->log("Testing connection to embedding service");
        try {
            $vector = $this->embeddingClient->embedText("test");
            $this->assertIsArray($vector, "Embedding service should return an array");
        } catch (Exception $e) {
            $this->fail("Failed to connect to embedding service: " . $e->getMessage());
        }
    }

    private function testVectorDimensions() {
        $this->log("Testing vector dimensions");
        $vector = $this->embeddingClient->embedText("test");
        $this->assertEquals(384, count($vector), "Embedding vector should have 384 dimensions");
    }
}
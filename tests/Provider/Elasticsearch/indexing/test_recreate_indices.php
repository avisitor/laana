<?php

require_once __DIR__ . '/../TestBase.php';

class TestRecreateIndices extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->createDocumentsIndex($this->documentsIndex);
    }

    protected function execute() {
        $this->testRecreateIndex();
    }

    private function testRecreateIndex() {
        $this->log("Testing index recreation");

        // Index a document
        $doc = [
            '_index' => $this->documentsIndex,
            '_id' => 'doc1',
            '_source' => ['sourceid' => 'doc1', 'text' => 'Initial document']
        ];
        $this->client->bulkIndex([$doc]);
        $this->client->refresh($this->documentsIndex);

        // Verify it's there
        $result = $this->client->getDocument('doc1', $this->documentsIndex);
        $this->assertNotNull($result, "Document should exist before recreation");

        // Recreate the index
        $this->createDocumentsIndex($this->documentsIndex);
        $this->client->refresh($this->documentsIndex);

        // Verify the document is gone
        $resultAfterRecreation = $this->client->getDocument('doc1', $this->documentsIndex);
        $this->assertNull($resultAfterRecreation, "Document should be gone after index recreation");
    }
}

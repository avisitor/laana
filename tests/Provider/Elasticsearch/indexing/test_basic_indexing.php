<?php

require_once __DIR__ . '/../TestBase.php';

class TestBasicIndexing extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
    }

    protected function execute() {
        $this->testDocumentIsIndexed();
    }

    private function testDocumentIsIndexed() {
        $this->log("Testing basic indexing functionality");

        $doc = [
            '_index' => $this->documentsIndex,
            '_id' => 'test_doc_1',
            '_source' => [
                'sourceid' => 'test_doc_1',
                'text' => 'This is a test document.',
                'hawaiian_word_ratio' => 0.0,
            ]
        ];

        $this->client->bulkIndex([$doc]);
        $this->client->refresh($this->documentsIndex);

        $result = $this->client->getDocument('test_doc_1', $this->documentsIndex);
        $this->assertNotNull($result, "Document should be indexed and retrievable");
        $this->assertEquals('This is a test document.', $result['text'], "Indexed document should have the correct content");
    }
}

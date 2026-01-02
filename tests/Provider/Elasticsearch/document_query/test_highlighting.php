<?php

require_once __DIR__ . '/../TestBase.php';

class TestHighlighting extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->documentsIndex);
    }

    private function indexTestDocuments() {
        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This is a test document for testing highlighting.',
                ]
            ],
        ];
        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testHighlighting();
    }

    private function testHighlighting() {
        $this->log("Testing highlighting");
        $results = $this->client->search('highlighting', 'match', ['highlight' => true, 'k' => 10, 'offset' => 0]);
        $this->assertTrue(count($results) > 0, "Should find the document with 'highlighting'");
        $this->assertStringContainsString('<mark>highlighting</mark>', $results[0]['highlighted_text'], "Should contain highlight tags");
    }
}
<?php

require_once __DIR__ . '/../TestBase.php';

class TestSearchPipeline extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->indexTestDocuments();
    }

    private function indexTestDocuments() {
        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'search_test_doc',
                '_source' => [
                    'sourceid' => 'search_test_doc',
                    'text' => 'This is a test for the search pipeline. Aloha.',
                ]
            ]
        ];
        $this->client->bulkIndex($documents);
        $this->client->refresh($this->documentsIndex);
    }

    protected function execute() {
        $this->testSearchPipeline();
    }

    private function testSearchPipeline() {
        $this->log("Testing search pipeline");
        $results = $this->client->search('Aloha', 'match', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Search pipeline should return results");
        $this->assertEquals('search_test_doc', $results[0]['sourceid'], "Search pipeline should return the correct document");
    }
}

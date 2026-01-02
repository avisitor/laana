<?php

require_once __DIR__ . '/../TestBase.php';

class TestRegexSearch extends TestBase {
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
                    'text' => 'This document contains the word aloha.',
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testBasicRegex();
    }

    private function testBasicRegex() {
        $this->log("Testing basic regex search");
        $results = $this->client->search('.*al.ha.*', 'regexp', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Regex '.*al.ha.*' should find 'aloha'");
        $this->assertEquals('doc1', $results[0]['sourceid'], "Regex '.*al.ha.*' should find the correct document");
    }
}
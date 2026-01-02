<?php

require_once __DIR__ . '/../TestBase.php';

class TestBasicSearch extends TestBase {
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
                    'text' => 'This is a test document about Hawaiian culture. Aloha and Mahalo.',
                    'hawaiian_word_ratio' => 0.2,
                ]
            ],
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc2',
                '_source' => [
                    'sourceid' => 'doc2',
                    'text' => 'Another test document, this one is about the geography of the islands.',
                    'hawaiian_word_ratio' => 0.1,
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testMatchQuery();
        $this->testTermQuery();
        $this->testPhraseQuery();
    }

    private function testMatchQuery() {
        $this->log("Testing basic match query");
        $results = $this->client->search('Hawaiian culture', 'match', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Match query should return results for 'Hawaiian culture'");
        $this->assertEquals('doc1', $results[0]['sourceid'], "Match query should find the correct document");
    }

    private function testTermQuery() {
        $this->log("Testing basic term query");
        $results = $this->client->search('geography', 'term', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Term query should return results for 'geography'");
        $this->assertEquals('doc2', $results[0]['sourceid'], "Term query should find the correct document");
    }

    private function testPhraseQuery() {
        $this->log("Testing basic phrase query");
        $results = $this->client->search('test document', 'phrase', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Phrase query should return results for 'test document'");
    }
}
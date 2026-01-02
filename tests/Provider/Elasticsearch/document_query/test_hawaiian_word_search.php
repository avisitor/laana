<?php

require_once __DIR__ . '/../TestBase.php';

class TestHawaiianWordSearch extends TestBase {
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
                '_id' => 'doc_hawaiian',
                '_source' => [
                    'sourceid' => 'doc_hawaiian',
                    'text' => 'This document is full of Hawaiian words. Aloha, Mahalo, Ohana, Keiki, Kupuna.',
                    'hawaiian_word_ratio' => 0.8,
                ]
            ],
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc_english',
                '_source' => [
                    'sourceid' => 'doc_english',
                    'text' => 'This document has some Hawaiian words, but is mostly English. Aloha.',
                    'hawaiian_word_ratio' => 0.1,
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testHawaiianWordRanking();
    }

    private function testHawaiianWordRanking() {
        $this->log("Testing that documents with higher hawaiian_word_ratio are ranked higher");
        $results = $this->client->search('Aloha', 'match', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 1, "Should get at least two results for 'Aloha'");
        $this->assertEquals('doc_hawaiian', $results[0]['sourceid'], "Document with higher hawaiian_word_ratio should be ranked first");
    }
}
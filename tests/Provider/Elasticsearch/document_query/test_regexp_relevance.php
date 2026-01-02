<?php

require_once __DIR__ . '/../TestBase.php';

class TestRegexpRelevance extends TestBase {
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
                '_id' => 'doc_high_ratio',
                '_source' => [
                    'sourceid' => 'doc_high_ratio',
                    'text' => 'aloha aloha aloha',
                    'hawaiian_word_ratio' => 0.9,
                ]
            ],
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc_low_ratio',
                '_source' => [
                    'sourceid' => 'doc_low_ratio',
                    'text' => 'aloha',
                    'hawaiian_word_ratio' => 0.1,
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testRegexpRelevanceRanking();
    }

    private function testRegexpRelevanceRanking() {
        $this->log("Testing regexp relevance ranking");
        $results = $this->client->search('.*al.ha.*', 'regexp', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 1, "Should find both documents");
        $this->assertEquals('doc_high_ratio', $results[0]['sourceid'], "Document with higher hawaiian_word_ratio should be ranked first for regexp query");
    }
}

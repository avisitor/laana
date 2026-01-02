<?php

require_once __DIR__ . '/../TestBase.php';

class TestQualityScoring extends TestBase {
    private $sentencesIndex;

    protected function setUp() {
        parent::setUp();
        $this->sentencesIndex = $this->client->getSentencesIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->sentencesIndex);
    }

    private function indexTestDocuments() {
        $sentences = [
            [
                '_index' => $this->sentencesIndex,
                '_id' => 'sent_high_quality',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This is a high quality sentence with many Hawaiian words like Aloha and Mahalo.',
                    'hawaiian_word_ratio' => 0.8,
                ]
            ],
            [
                '_index' => $this->sentencesIndex,
                '_id' => 'sent_low_quality',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This is a low quality sentence.',
                    'hawaiian_word_ratio' => 0.1,
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testQualityRanking();
    }

    private function testQualityRanking() {
        $this->log("Testing quality scoring and ranking");
        $results = $this->client->search('sentence', 'matchsentence', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 1, "Should find both sentences");
        $this->assertEquals('sent_high_quality', $results[0]['_id'], "High quality sentence should be ranked first");
    }
}

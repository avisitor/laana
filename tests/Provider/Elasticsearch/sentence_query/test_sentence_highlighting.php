<?php

require_once __DIR__ . '/../TestBase.php';

class TestSentenceHighlighting extends TestBase {
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
                '_id' => 'sent1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This sentence is for testing highlighting.',
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testHighlighting();
    }

    private function testHighlighting() {
        $this->log("Testing sentence highlighting");
        $options = array_merge($this->defaultSearchOptions, ['sentence_highlight' => true]);
        $results = $this->client->search('highlighting', 'matchsentence', $options);
        $this->assertTrue(count($results) > 0, "Should find the sentence with 'highlighting'");
        $this->assertStringContainsString('<mark>highlighting</mark>', $results[0]['highlighted_text'], "Should contain highlight tags");
    }
}

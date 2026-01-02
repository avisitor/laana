<?php

require_once __DIR__ . '/../TestBase.php';

class TestSentenceRegex extends TestBase {
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
                    'text' => 'This sentence contains the word aloha.',
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testRegexOnSentences();
    }

    private function testRegexOnSentences() {
        $this->log("Testing regex on sentences");
        $results = $this->client->search('.*al.ha.*', 'regexpsentence', $this->defaultSearchOptions);
        $this->log("Sentence regex results: " . count($results) . " matches");
        if (count($results) > 0) {
            $this->log("First result text: " . $results[0]['text']);
        }
        $this->assertTrue(count($results) > 0, "Regex '.*al.ha.*' should find 'aloha' in sentences");
    }
}
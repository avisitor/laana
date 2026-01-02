<?php

require_once __DIR__ . '/../TestBase.php';

class TestRegexpFunctionality extends TestBase {
    private $documentsIndex;
    private $sentencesIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->sentencesIndex = $this->client->getSentencesIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->documentsIndex);
        $this->client->refresh($this->sentencesIndex);
    }

    private function indexTestDocuments() {
        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'Document with aloha.',
                ]
            ],
        ];
        $this->client->bulkIndex($documents);

        $sentences = [
            [
                '_index' => $this->sentencesIndex,
                '_id' => 'sent1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'Sentence with mahalo.',
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testRegexpDocument();
        $this->testRegexpSentence();
    }

    private function testRegexpDocument() {
        $this->log("Testing regexp on documents");
        $results = $this->client->search('.*al.ha.*', 'regexp', $this->defaultSearchOptions);
        $this->log("Document regexp results: " . count($results) . " matches");
        if (count($results) > 0) {
            $this->log("First result: " . json_encode($results[0]));
        }
        $this->assertTrue(count($results) > 0, "Should find 'aloha' in documents");
        $this->assertEquals('doc1', $results[0]['sourceid'], "Should find the correct document");
    }

    private function testRegexpSentence() {
        $this->log("Testing regexpsentence on sentences");
        $results = $this->client->search('.*ma.alo.*', 'regexpsentence', $this->defaultSearchOptions);
        $this->log("Sentence regexp results: " . count($results) . " matches");
        if (count($results) > 0) {
            $this->log("First result: " . json_encode($results[0]));
        }
        $this->assertTrue(count($results) > 0, "Should find 'mahalo' in sentences");
        $this->assertEquals('doc1', $results[0]['sourceid'], "Should find the correct document for the sentence");
    }
}

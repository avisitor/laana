<?php

require_once __DIR__ . '/../TestBase.php';

class TestIndexingPipeline extends TestBase {
    private $documentsIndex;
    private $sentencesIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->sentencesIndex = $this->client->getSentencesIndexName();
    }

    protected function execute() {
        $this->testIndexingPipeline();
    }

    private function testIndexingPipeline() {
        $this->log("Testing indexing pipeline");

        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'pipeline_test_doc',
                '_source' => [
                    'sourceid' => 'pipeline_test_doc',
                    'text' => 'Aloha world. This is a test.',
                ]
            ]
        ];
        $this->client->bulkIndex($documents);

        $this->client->refresh($this->documentsIndex);

        $results = $this->client->search('Aloha world', 'match', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Document should be searchable after indexing pipeline");
    }
}
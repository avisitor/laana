<?php

require_once __DIR__ . '/../TestBase.php';

class TestSentenceMetadata extends TestBase {
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
                    'text' => 'doc text',
                    'groupname' => 'test_group',
                    'sourcename' => 'test_source',
                ]
            ]
        ];
        $this->client->bulkIndex($documents);

        $sentences = [
            [
                '_index' => $this->sentencesIndex,
                '_id' => 'sent1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This is a test sentence.',
                    'groupname' => 'test_group',
                    'sourcename' => 'test_source',
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testMetadataIsReturned();
    }

    private function testMetadataIsReturned() {
        $this->log("Testing that sentence search results include document metadata");
        $results = $this->client->search('test', 'matchsentence', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Should find the test sentence");
        $this->assertEquals('test_group', $results[0]['groupname'], "Result should include groupname from document");
        $this->assertEquals('test_source', $results[0]['sourcename'], "Result should include sourcename from document");
    }
}

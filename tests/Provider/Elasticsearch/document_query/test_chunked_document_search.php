<?php

require_once __DIR__ . '/../TestBase.php';

class TestChunkedDocumentSearch extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->documentsIndex);
    }

    private function indexTestDocuments() {
        $longText = str_repeat("This is a very long document that needs to be chunked. ", 1000);
        $longText .= "The keyword 'supercalifragilisticexpialidocious' is hidden deep inside the text.";

        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'long_doc',
                '_source' => [
                    'sourceid' => 'long_doc',
                    'text' => $longText,
                    'hawaiian_word_ratio' => 0.0,
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testSearchInChunkedDocument();
    }

    private function testSearchInChunkedDocument() {
        $this->log("Testing search within a chunked document");
        $results = $this->client->search('supercalifragilisticexpialidocious', 'match', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Should find the keyword in the chunked document");
        $this->assertEquals('long_doc', $results[0]['sourceid'], "Should return the correct document ID for the chunked document");
    }
}
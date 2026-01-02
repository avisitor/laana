<?php

require_once __DIR__ . '/../TestBase.php';

class TestBatchIndexing extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
    }

    protected function execute() {
        $this->testBatchIndexing();
    }

    private function testBatchIndexing() {
        $this->log("Testing batch indexing functionality");

        $documents = [];
        for ($i = 1; $i <= 10; $i++) {
            $documents[] = [
                '_index' => $this->documentsIndex,
                '_id' => 'batch_doc_' . $i,
                '_source' => [
                    'sourceid' => 'batch_doc_' . $i,
                    'text' => "This is test document {$i}.",
                    'hawaiian_word_ratio' => 0.0,
                ]
            ];
        }

        $this->client->bulkIndex($documents);
        $this->client->refresh($this->documentsIndex);

        for ($i = 1; $i <= 10; $i++) {
            $result = $this->client->getDocument('batch_doc_' . $i, $this->documentsIndex);
            $this->assertNotNull($result, "Document batch_doc_{$i} should be indexed");
        }
        
        $countResponse = $this->client->getRawClient()->count(['index' => $this->documentsIndex]);
        $this->assertEquals(10, $countResponse['count'], "There should be 10 documents in the index after batch indexing");
    }
}

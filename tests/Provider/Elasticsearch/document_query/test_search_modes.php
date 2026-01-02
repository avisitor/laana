<?php

require_once __DIR__ . '/../TestBase.php';

class TestSearchModes extends TestBase {
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
        $embeddingClient = new \HawaiianSearch\EmbeddingClient();
        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'This is a test document about Hawaiian culture. Aloha and Mahalo.',
                    'text_vector' => $embeddingClient->embedText('This is a test document about Hawaiian culture. Aloha and Mahalo.'),
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
                    'text' => 'This is a sentence about Hawaiian culture.',
                    'vector' => $embeddingClient->embedText('This is a sentence about Hawaiian culture.'),
                ]
            ],
        ];
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testAllSearchModes();
    }

    private function testAllSearchModes() {
        $modes = HawaiianSearch\QueryBuilder::MODES;
        foreach ($modes as $mode) {
            if ($mode === 'wildcard') {
                // Wildcard is not a standard mode, skipping
                continue;
            }
            $this->log("Testing search mode: {$mode}");
            $results = $this->client->search('Hawaiian', $mode, $this->defaultSearchOptions);
            $this->assertTrue(is_array($results), "Search results for mode '{$mode}' should be an array.");
        }
    }
}
<?php

require_once __DIR__ . '/BaseTest.php';

use HawaiianSearch\ElasticsearchClient;

abstract class TestBase extends OpenSearchBaseTest {
    /** @var ElasticsearchClient */
    protected $client;
    protected $createdIndices = [];
    protected $defaultSearchOptions;

    // Centralized mapping file names using the global constant
    protected const DOCUMENTS_MAPPING = TEST_BASE_PATH . '/config/documents_mapping_optimized.json';
    protected const SENTENCES_MAPPING = TEST_BASE_PATH . '/config/sentences_mapping_optimized.json';
    protected const SOURCE_METADATA_MAPPING = TEST_BASE_PATH . '/config/source_metadata_mapping.json';

    protected function setUp() {
        $this->log("Setting up " . get_class($this));
        $baseIndexName = 'test_index_' . uniqid();
        
        $this->client = new ElasticsearchClient([
            'indexName' => $baseIndexName,
            'SPLIT_INDICES' => true,
            'verbose' => in_array('--verbose', $_SERVER['argv'] ?? []),
        ]);

        $this->defaultSearchOptions = ['k' => 10, 'offset' => 0];
        $this->createDocumentsIndex($this->client->getDocumentsIndexName());
        $this->createSentencesIndex($this->client->getSentencesIndexName());
    }

    protected function createDocumentsIndex(string $indexName) {
        $this->client->createIndex(true, 'documents', $indexName, self::DOCUMENTS_MAPPING);
        $this->createdIndices[] = $indexName;
        $this->log("Created and registered documents index: {$indexName}");
    }

    protected function createSentencesIndex(string $indexName) {
        $this->client->createIndex(true, 'sentences', $indexName, self::SENTENCES_MAPPING);
        $this->createdIndices[] = $indexName;
        $this->log("Created and registered sentences index: {$indexName}");
    }

    protected function tearDown() {
        $this->log("Tearing down " . get_class($this));
        if ($this->client && !empty($this->createdIndices)) {
            foreach ($this->createdIndices as $index) {
                try {
                    $this->client->deleteIndex($index);
                    $this->log("Cleaned up test index: {$index}");
                } catch (Exception $e) {
                    $this->log("Warning: Could not clean up index {$index}: " . $e->getMessage(), 'warning');
                }
            }
        }
        $this->createdIndices = [];
    }
}
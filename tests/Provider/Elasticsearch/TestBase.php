<?php

require_once __DIR__ . '/BaseTest.php';

use HawaiianSearch\ElasticsearchClient;

abstract class TestBase extends BaseTest {
    /** @var ElasticsearchClient */
    protected $client;
    protected $createdIndices = [];
    protected $defaultSearchOptions;
    /** @var array<string,string|null> alias env values saved by setUp() */
    protected $savedEnv = [];

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
        // Concrete (physical) names: the test harness creates isolated
        // test_index_* indices; the production aliases must not be touched.
        $this->createDocumentsIndex($this->client->getDocumentsConcreteName());
        $this->createSentencesIndex($this->client->getSentencesConcreteName());
        // Route the client's active-name resolution (searches, lookups) to
        // the test indices for the duration of the test. This replaces the
        // previous behavior of repointing the PRODUCTION aliases at the test
        // indices, which destroyed those aliases on tearDown.
        $this->savedEnv['ES_DOCUMENTS_ALIAS'] = $_ENV['ES_DOCUMENTS_ALIAS'] ?? null;
        $this->savedEnv['ES_SENTENCES_ALIAS'] = $_ENV['ES_SENTENCES_ALIAS'] ?? null;
        $_ENV['ES_DOCUMENTS_ALIAS'] = $this->client->getDocumentsConcreteName();
        $_ENV['ES_SENTENCES_ALIAS'] = $this->client->getSentencesConcreteName();
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
        // Restore the production alias environment before the next test.
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->savedEnv = [];
    }
}
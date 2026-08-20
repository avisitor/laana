<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\Tests\BaseTestCase;

class ElasticsearchClientTest extends BaseTestCase
{
    private ElasticsearchClient $esClient;

    protected function setUp(): void
    {
        $host = $_ENV['ES_HOST'] ?? null;
        $port = $_ENV['ES_PORT'] ?? null;
        if (!$host || !$port) {
            $this->markTestSkipped('ES_HOST and ES_PORT must be set for ElasticsearchClient tests');
        }

        $this->esClient = new ElasticsearchClient([
            'hawaiian_documents_index' => 'hawaiian_documents_new',
            'hawaiian_sentences_index' => 'hawaiian_sentences_new',
            'hawaiian_source_metadata_index' => 'hawaiian-source-metadata',
            'vector_dimensions' => 384,
            'quiet' => true,
        ]);
    }

    public function testDeleteByDocIdReturnsExpectedKeys(): void
    {
        ob_start();
        try {
            $result = $this->esClient->deleteByDocId('nonexistent_doc_id_for_test');
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail('deleteByDocId threw exception: ' . $e->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsInt($result['deleted']);
        $this->assertIsArray($result['errors']);
    }

    public function testBulkIndexReturnsEmptyArrayForEmptyInput(): void
    {
        ob_start();
        try {
            $result = $this->esClient->bulkIndex([]);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail('bulkIndex threw exception on empty input: ' . $e->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}

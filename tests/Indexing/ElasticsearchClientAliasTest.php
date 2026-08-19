<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\Tests\BaseTestCase;

class ElasticsearchClientAliasTest extends BaseTestCase
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

    public function testGetDocumentsAliasReturnsConfiguredValue(): void
    {
        $expected = $_ENV['ES_DOCUMENTS_ALIAS'] ?? 'hawaiian_documents';
        $this->assertSame($expected, $this->esClient->getDocumentsAlias());
    }

    public function testGetSentencesAliasReturnsConfiguredValue(): void
    {
        $expected = $_ENV['ES_SENTENCES_ALIAS'] ?? 'hawaiian_sentences';
        $this->assertSame($expected, $this->esClient->getSentencesAlias());
    }

    public function testAliasExistsReturnsFalseForNonexistentAlias(): void
    {
        $aliasName = 'nonexistent_alias_for_test_' . uniqid();
        $this->assertFalse($this->esClient->aliasExists($aliasName));
    }

    public function testCreateAliasCreatesAlias(): void
    {
        $testAlias = 'test_alias_' . uniqid();
        $documentsIndex = $this->esClient->getDocumentsIndexName();

        $this->esClient->createAlias($testAlias, $documentsIndex);
        $this->assertTrue($this->esClient->aliasExists($testAlias));

        $this->esClient->removeAlias($testAlias);
        $this->assertFalse($this->esClient->aliasExists($testAlias));
    }
}
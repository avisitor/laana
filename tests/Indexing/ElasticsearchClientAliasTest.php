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
        // Aliases must point at a physical index, so use the concrete name.
        $documentsIndex = $this->esClient->getDocumentsConcreteName();

        $this->esClient->createAlias($testAlias, $documentsIndex);
        $this->assertTrue($this->esClient->aliasExists($testAlias));

        $this->esClient->removeAlias($testAlias);
        $this->assertFalse($this->esClient->aliasExists($testAlias));
    }

    public function testActiveNamesResolveToAliasesOutsideStaging(): void
    {
        $this->assertSame($_ENV['ES_DOCUMENTS_ALIAS'] ?? 'hawaiian_documents', $this->esClient->getDocumentsIndexName());
        $this->assertSame($_ENV['ES_SENTENCES_ALIAS'] ?? 'hawaiian_sentences', $this->esClient->getSentencesIndexName());
        $this->assertSame($_ENV['ES_CONTENT_ALIAS'] ?? 'hawaiian_content', $this->esClient->getContentName());
        $this->assertSame($_ENV['ES_SOURCE_METADATA_ALIAS'] ?? 'hawaiian_source_metadata', $this->esClient->getSourceMetadataName());
        $this->assertSame($_ENV['ES_METADATA_ALIAS'] ?? 'hawaiian_metadata', $this->esClient->getMetadataName());
    }

    public function testStagingModeRoutesActiveNamesToStagingIndices(): void
    {
        $this->esClient->setStagingMode(true);
        try {
            $this->assertSame('hawaiian_documents_new_staging', $this->esClient->getDocumentsIndexName());
            $this->assertSame('hawaiian_sentences_new_staging', $this->esClient->getSentencesIndexName());
            $this->assertSame('hawaiian-content_staging', $this->esClient->getContentName());
            $this->assertSame('hawaiian-source-metadata_staging', $this->esClient->getSourceMetadataName());
            $this->assertSame('hawaiian-metadata_staging', $this->esClient->getMetadataName());

            // Concrete getters expose the same staging names during the run...
            $this->assertSame('hawaiian_documents_new_staging', $this->esClient->getDocumentsConcreteName());
            // ...and the production physical names outside staging mode.
            $this->esClient->setStagingMode(false);
            $this->assertSame('hawaiian_documents_new', $this->esClient->getDocumentsConcreteName());
            $this->assertSame('hawaiian-content', $this->esClient->getContentConcreteName());
            $this->assertSame('hawaiian-source-metadata', $this->esClient->getSourceMetadataConcreteName());
            $this->assertSame('hawaiian-metadata', $this->esClient->getMetadataConcreteName());
        } finally {
            $this->esClient->setStagingMode(false);
        }
    }
}
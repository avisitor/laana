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
        $this->skipIfProviderUnavailable('Elasticsearch');

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

    /**
     * A --recreate staging run must build into the physical name that is NOT
     * currently serving production. After a completed switch the live indices
     * are the *_staging ones, so the next run alternates back to the plain
     * names; always targeting *_staging would rebuild over (and switch away
     * from nothing but) the live corpus.
     */
    public function testStagingModeTargetsTheNonLivePhysicalIndex(): void
    {
        $pairs = [
            ['alias' => $this->esClient->getDocumentsAlias(),      'base' => 'hawaiian_documents_new',   'get' => 'getDocumentsConcreteName',      'active' => 'getDocumentsIndexName'],
            ['alias' => $this->esClient->getSentencesAlias(),      'base' => 'hawaiian_sentences_new',   'get' => 'getSentencesConcreteName',      'active' => 'getSentencesIndexName'],
            ['alias' => $this->esClient->getContentAlias(),        'base' => 'hawaiian-content',         'get' => 'getContentConcreteName',        'active' => 'getContentName'],
            ['alias' => $this->esClient->getSourceMetadataAlias(), 'base' => 'hawaiian-source-metadata', 'get' => 'getSourceMetadataConcreteName', 'active' => 'getSourceMetadataName'],
            ['alias' => $this->esClient->getMetadataAlias(),       'base' => 'hawaiian-metadata',        'get' => 'getMetadataConcreteName',       'active' => 'getMetadataName'],
        ];
        $live = [];
        foreach ($pairs as $p) {
            $live[$p['alias']] = $this->esClient->{$p['get']}();
        }

        $this->esClient->setStagingMode(true);
        try {
            foreach ($pairs as $p) {
                $staging = $this->esClient->{$p['get']}();
                $this->assertContains($staging, [$p['base'], $p['base'] . '_staging'], "{$p['alias']}: staging target must be a known physical name");
                $this->assertNotSame($live[$p['alias']], $staging, "{$p['alias']}: staging target must not be the live index");
                $this->assertSame($staging, $this->esClient->{$p['active']}(), "{$p['alias']}: writes during staging go to the staging target");
            }
        } finally {
            $this->esClient->setStagingMode(false);
        }

        // Outside staging mode the concrete getters resolve to existing physicals.
        foreach ($pairs as $p) {
            $this->assertTrue($this->esClient->indexExists($this->esClient->{$p['get']}()), "{$p['get']}() must name an existing physical index");
        }
    }
}
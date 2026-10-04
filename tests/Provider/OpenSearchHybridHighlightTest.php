<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

/**
 * OpenSearch's neural-search `hybrid` query is extremely slow to highlight
 * (~6.5 s fetch phase for 5 hits vs ~4 ms query phase). OpenSearchClient
 * therefore runs the hybrid query without highlighting and highlights the
 * returned ids in a second, plain request. Highlights and hybrid ranking must
 * both survive that split.
 */
final class OpenSearchHybridHighlightTest extends BaseTestCase
{
    private const TERM = 'ke aliʻi nui';

    private function provider(): \Noiiolelo\Providers\Elasticsearch\ElasticsearchProvider
    {
        if (!($_ENV['OS_HOST'] ?? getenv('OS_HOST'))) {
            $this->markTestSkipped('No OS_HOST');
        }
        try {
            return getProvider('OpenSearch');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Provider OpenSearch was not available: ' . $e->getMessage());
        }
    }

    public function testHybridDocResultsAreHighlighted(): void
    {
        $results = $this->provider()->getSentences(self::TERM, 'hybriddoc', 0, []);
        $this->assertNotEmpty($results, 'hybriddoc must return results');
        foreach ($results as $r) {
            $this->assertNotSame('', $r['hawaiiantext'], 'every hybriddoc hit needs highlighted text');
        }
        $marked = array_filter($results, fn($r) => str_contains($r['hawaiiantext'], '<mark>'));
        $this->assertNotEmpty($marked, 'lexical matches must be highlighted');
    }

    public function testHighlightingDoesNotChangeHybridRanking(): void
    {
        $provider = $this->provider();
        $client = (new \ReflectionProperty($provider, 'client'))->getValue($provider);
        $raw = $client->getRawOsClient();
        $params = [
            'index' => 'hawaiian_documents',
            'search_pipeline' => 'norm-pipeline',
            'body' => [
                'size' => 5,
                '_source' => false,
                'query' => ['hybrid' => ['queries' => [
                    ['match' => ['text' => ['query' => self::TERM]]],
                    ['knn' => ['text_vector_1024' => [
                        'vector' => $client->getEmbeddingClient()->embedText(self::TERM, 'query: ', \HawaiianSearch\EmbeddingClient::MODEL_LARGE),
                        'k' => 5,
                    ]]],
                ], 'pagination_depth' => 100]],
            ],
        ];
        $plainIds = array_column($raw->search($params)['hits']['hits'], '_id');

        $params['body']['highlight'] = ['fields' => ['text' => ['number_of_fragments' => 3]]];
        $start = microtime(true);
        $res = $client->getRawClient()->search($params)->asArray();
        $elapsed = microtime(true) - $start;

        $this->assertSame($plainIds, array_column($res['hits']['hits'], '_id'), 'ranking must match the un-highlighted hybrid query');
        foreach ($res['hits']['hits'] as $hit) {
            $this->assertNotEmpty($hit['highlight']['text'] ?? [], "hit {$hit['_id']} lost its highlight");
        }
        $this->assertLessThan(3.0, $elapsed, 'hybrid + highlight must not use the slow in-query highlighter');
    }
}

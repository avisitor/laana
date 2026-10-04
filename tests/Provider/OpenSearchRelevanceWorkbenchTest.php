<?php

namespace Noiiolelo\Tests\Provider;

use HawaiianSearch\OpenSearchClient;
use HawaiianSearch\RelevanceWorkbench;
use HawaiianSearch\RemoteEmbeddingModel;
use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

/**
 * OpenSearch relevance tooling: query-set selection, rating CSV parsing,
 * optimizer-variant -> pipeline translation (pure), plus live checks that
 * the installed hybrid pipeline and vector mappings match the config files
 * and that the ML Commons remote embedding model agrees with EmbeddingClient.
 */
final class OpenSearchRelevanceWorkbenchTest extends BaseTestCase
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp && file_exists($this->tmp)) {
            unlink($this->tmp);
        }
        parent::tearDown();
    }

    public function testTopQueriesRanksByFrequencyAndCleansTerms(): void
    {
        $rows = [
            ['searchterm' => 'make', 'pattern' => 'any'],
            ['searchterm' => 'Make', 'pattern' => 'exact'],
            ['searchterm' => '"aia paha"', 'pattern' => 'exact'],
            ['searchterm' => 'aia paha', 'pattern' => 'any'],
            ['searchterm' => 'aia paha', 'pattern' => 'any'],
            ['searchterm' => 'ka.*', 'pattern' => 'regex'],
            ['searchterm' => 'x', 'pattern' => 'any'],
            ['searchterm' => 'kumu', 'pattern' => 'any'],
        ];
        $this->assertSame(['aia paha', 'make', 'kumu'], RelevanceWorkbench::topQueries($rows, 10));
        $this->assertSame(['aia paha'], RelevanceWorkbench::topQueries($rows, 1));
    }

    public function testReadRatingsAcceptsBlankAndRejectsOutOfRange(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'ratings');
        $this->writeCsv([['aloha', '1', 'S', '2000-01-01', 'snip', '3'], ['aloha', '2', 'S', '2000-01-01', 'snip', '']]);
        $rows = RelevanceWorkbench::readRatings($this->tmp);
        $this->assertSame([3, null], array_column($rows, 'rating'));

        $this->writeCsv([['aloha', '1', 'S', '2000-01-01', 'snip', '4']]);
        $this->expectException(\RuntimeException::class);
        RelevanceWorkbench::readRatings($this->tmp);
    }

    public function testPipelineBodyForNormalizationAndRrfVariants(): void
    {
        $norm = RelevanceWorkbench::pipelineBodyFor(['normalization' => 'z_score', 'combination' => 'arithmetic_mean', 'weights' => [0.6, 0.4]]);
        $proc = $norm['phase_results_processors'][0]['normalization-processor'];
        $this->assertSame('z_score', $proc['normalization']['technique']);
        $this->assertSame([0.6, 0.4], $proc['combination']['parameters']['weights']);

        $rrf = RelevanceWorkbench::pipelineBodyFor(['combination' => 'rrf', 'rank_constant' => 60]);
        $this->assertSame(['technique' => 'rrf', 'rank_constant' => 60], $rrf['phase_results_processors'][0]['score-ranker-processor']['combination']);
    }

    public function testCurrentPipelineConfigRoundTripsThroughVariantTranslation(): void
    {
        $installed = OpenSearchClient::searchPipelineConfig()['body']['phase_results_processors'];
        $proc = $installed[0]['normalization-processor'] ?? null;
        if ($proc === null) {
            $this->markTestSkipped('Installed pipeline is RRF');
        }
        $variant = ['normalization' => $proc['normalization']['technique'], 'combination' => $proc['combination']['technique'],
            'weights' => $proc['combination']['parameters']['weights'] ?? []];
        $this->assertEquals($installed, RelevanceWorkbench::pipelineBodyFor($variant)['phase_results_processors']);
    }

    public function testInstalledPipelineMatchesConfig(): void
    {
        $installed = $this->client()->getInstalledSearchPipeline();
        $this->assertNotNull($installed, 'hybrid search pipeline is not installed on the cluster');
        $this->assertEquals(OpenSearchClient::searchPipelineConfig()['body'], $installed);
    }

    public function testLiveVectorMappingsMatchConfig(): void
    {
        $client = $this->client();
        $checks = [
            ['config' => 'documents_mapping.json', 'index' => $client->getDocumentsIndexName(), 'fields' => ['text_vector', 'text_vector_1024']],
            ['config' => 'sentences_mapping.json', 'index' => $client->getSentencesIndexName(), 'fields' => ['vector']],
        ];
        foreach ($checks as $c) {
            $config = json_decode(file_get_contents(__DIR__ . '/../../providers/OpenSearch/config/' . $c['config']), true)['mappings']['properties'];
            $live = array_values($client->rawRequest('GET', "/{$c['index']}/_mapping"))[0]['mappings']['properties'];
            foreach ($c['fields'] as $f) {
                $want = array_intersect_key($config[$f], array_flip(['type', 'dimension', 'mode', 'compression_level']));
                $have = array_intersect_key($live[$f] ?? [], $want);
                if ($have != $want && file_exists(__DIR__ . '/../../logs/createindex-staging-state.json')) {
                    $this->markTestSkipped("{$c['index']}.$f differs from config while a --recreate rebuild is in progress");
                }
                $this->assertEquals($want, $have, "{$c['index']}.$f mapping drifted from {$c['config']}");
            }
        }
    }

    public function testRemoteEmbeddingModelMatchesEmbeddingClient(): void
    {
        $client = $this->client();
        $model = new RemoteEmbeddingModel($client);
        $remote = $model->embed($model->ensureDeployed(), 'ke aliʻi nui');
        $direct = $client->getEmbeddingClient()->embedText('ke aliʻi nui', 'query: ', \HawaiianSearch\EmbeddingClient::MODEL_LARGE);
        $this->assertCount(1024, $remote);
        $dot = 0.0;
        foreach ($remote as $i => $x) {
            $dot += $x * $direct[$i];
        }
        $this->assertGreaterThan(0.9999, $dot, 'neural queries must embed exactly like the PHP client');
    }

    private function client(): OpenSearchClient
    {
        if (!($_ENV['OS_HOST'] ?? getenv('OS_HOST'))) {
            $this->markTestSkipped('No OS_HOST');
        }
        try {
            return new OpenSearchClient();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenSearch unavailable: ' . $e->getMessage());
        }
    }

    private function writeCsv(array $rows): void
    {
        $f = fopen($this->tmp, 'w');
        fputcsv($f, RelevanceWorkbench::CSV_HEADER);
        foreach ($rows as $r) {
            fputcsv($f, $r);
        }
        fclose($f);
    }
}

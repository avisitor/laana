<?php
namespace Noiiolelo\Tests\Provider;

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;
use Noiiolelo\Tests\BaseTestCase;

class OpenSearchSaveManagerTest extends BaseTestCase
{
    public function testUsesOpenSearchClient(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        $client = $mgr->getClient();
        $this->assertInstanceOf(OpenSearchClient::class, $client);
        $this->assertInstanceOf(ElasticsearchClient::class, $client);
    }

    /**
     * processOneSource() must resolve parser + source metadata from the
     * documents index alone (no MySQL) and run the standard per-document
     * save flow. Existing, fully indexed source with force=false: the run
     * is an internal skip, but the summary must report the single processed
     * document instead of fataling on a missing method.
     */
    public function testProcessOneSourceSummarizesExistingSource(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        $sid = $this->indexedSourceId($mgr);
        if (!$sid) {
            $this->markTestSkipped('No source with a parser-mapped groupname found in the documents index');
        }

        try {
            $summary = $mgr->processOneSource($sid);
        } catch (\Throwable $e) {
            if (self::isFetchError($e)) {
                $this->markTestSkipped('live fetch failed: ' . $e->getMessage());
            }
            throw $e;
        }

        $this->assertIsArray($summary);
        $this->assertSame(1, $summary['documents_processed']);
        $this->assertArrayHasKey('documents_new_or_updated', $summary);
        $this->assertArrayHasKey('sentences_new', $summary);
    }

    /**
     * An unknown sourceid must not fatal: zeroed summary, mirroring
     * MySQLSaveManager::processOneSource's not-found path.
     */
    public function testProcessOneSourceUnknownIdReturnsZeroedSummary(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        // The manager echoes the MySQL-parity wording "Error: Source X not
        // found"; capture it so tests/junit_to_json.py's output heuristic
        // (which flags any passed test whose output contains 'Error:') does
        // not mislabel this passing test in the JSON report.
        ob_start();
        $summary = $mgr->processOneSource(999999999);
        ob_end_clean();

        $this->assertIsArray($summary);
        $this->assertSame(0, $summary['documents_processed']);
        $this->assertSame(0, $summary['documents_new_or_updated']);
        $this->assertSame(0, $summary['sentences_new']);
    }

    /**
     * Newest sourceid in the documents index whose groupname maps to a
     * parser. TEST_SOURCE_ID env wins for determinism (mirrors
     * PostgresSaveManagerTest).
     */
    private function indexedSourceId(\Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager $mgr): string
    {
        $env = getenv('TEST_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (string)(int)$env;
        }
        try {
            $res = $mgr->getClient()->getTransportClient()->search([
                'index' => $mgr->getClient()->getDocumentsIndexName(),
                'body' => [
                    'size' => 5,
                    'query' => ['match_all' => new \stdClass()],
                    'sort' => [['sourceid' => ['order' => 'desc']]],
                    '_source' => ['groupname'],
                ],
            ]);
        } catch (\Throwable $e) {
            return '';
        }
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            $groupname = $hit['_source']['groupname'] ?? '';
            if ($groupname && $mgr->getParser($groupname)) {
                return (string)$hit['_id'];
            }
        }
        return '';
    }

    /**
     * Fetch/transport failures (Guzzle request exceptions, cURL, DNS, TLS,
     * timeouts) are environmental — skip rather than fail. Anything else
     * (including programming errors) must propagate.
     */
    private static function isFetchError(\Throwable $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        foreach (['curl error', 'could not resolve', 'connection refused', 'connection reset', 'timed out', 'timeout'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}

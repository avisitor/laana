<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

/**
 * Tests for the save-manager parser resolution backing scripts/save.php's
 * "--sourceid without --parser" path (resolveParserForSource) and the
 * deleteByGroupname wiring behind --delete-existing.
 *
 * Runs against the live backends configured in .env and skips when they are
 * unreachable. deleteByGroupname() is only exercised with a groupname that
 * matches no rows, so it deletes nothing.
 */
class SaveManagerSourceResolutionTest extends BaseTestCase
{
    private const NO_SUCH_GROUP = '__no_such_group_for_tests__';
    private const NO_SUCH_SOURCE_ID = 999999999;

    private function makeMysqlManager(): ?\Noiiolelo\Providers\MySQL\MySQLSaveManager
    {
        if (!getenv('DB_HOST')) {
            $this->markTestSkipped('DB_HOST must be set for MySQLSaveManager tests');
        }
        try {
            return new \Noiiolelo\Providers\MySQL\MySQLSaveManager(['verbose' => false]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL unreachable: ' . $e->getMessage());
        }
    }

    private function makeElasticsearchManager(): ?\Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager
    {
        if (!getenv('ES_HOST')) {
            $this->markTestSkipped('ES_HOST must be set for ElasticsearchSaveManager tests');
        }
        try {
            return new \Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager(['verbose' => false]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Elasticsearch unreachable: ' . $e->getMessage());
        }
    }

    /**
     * Newest sourceid whose groupname maps to a parser. TEST_MYSQL_SOURCE_ID
     * env wins for determinism (distinct from the OpenSearch suite's
     * TEST_SOURCE_ID, which selects from the documents index).
     */
    private function resolvableMysqlSourceId(\Noiiolelo\Providers\MySQL\MySQLSaveManager $mgr): int
    {
        $env = getenv('TEST_MYSQL_SOURCE_ID');
        if ($env !== false && (int)$env > 0) {
            return (int)$env;
        }
        $rows = $mgr->getLaana()->getsources();
        foreach (array_reverse($rows) as $row) {
            if (!empty($row['groupname']) && $mgr->getParser($row['groupname'])) {
                return (int)$row['sourceid'];
            }
        }
        return 0;
    }

    /**
     * Newest sourceid in the documents index whose groupname maps to a
     * parser. TEST_ES_SOURCE_ID env wins for determinism.
     */
    private function resolvableElasticsearchSourceId(\Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager $mgr): string
    {
        $env = getenv('TEST_ES_SOURCE_ID');
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

    public function testMysqlResolveParserForSourceReturnsGroupname(): void
    {
        $mgr = $this->makeMysqlManager();
        $sid = $this->resolvableMysqlSourceId($mgr);
        if (!$sid) {
            $this->markTestSkipped('No source with a parser-mapped groupname found in MySQL');
        }

        $groupname = $mgr->resolveParserForSource($sid);

        $this->assertNotNull($groupname, "resolveParserForSource($sid) returned null");
        $this->assertNotSame('', $groupname);
        $this->assertNotNull($mgr->getParser($groupname), "Resolved groupname '$groupname' does not map to a parser");
    }

    public function testMysqlResolveParserForSourceUnknownIdReturnsNull(): void
    {
        $mgr = $this->makeMysqlManager();
        ob_start();
        $groupname = $mgr->resolveParserForSource(self::NO_SUCH_SOURCE_ID);
        ob_end_clean();

        $this->assertNull($groupname);
    }

    public function testMysqlDeleteByGroupnameNoopOnUnknownGroup(): void
    {
        $mgr = $this->makeMysqlManager();
        ob_start();
        $stats = $mgr->deleteByGroupname(self::NO_SUCH_GROUP);
        ob_end_clean();

        $this->assertIsArray($stats);
        $this->assertNotEmpty($stats);
        $this->assertSame(0, array_sum(array_map('intval', $stats)), 'Unknown groupname must delete nothing');
    }

    public function testElasticsearchResolveParserForSourceReturnsGroupname(): void
    {
        $mgr = $this->makeElasticsearchManager();
        $sid = $this->resolvableElasticsearchSourceId($mgr);
        if (!$sid) {
            $this->markTestSkipped('No source with a parser-mapped groupname found in the Elasticsearch documents index');
        }

        $groupname = $mgr->resolveParserForSource($sid);

        $this->assertNotNull($groupname, "resolveParserForSource($sid) returned null");
        $this->assertNotSame('', $groupname);
        $this->assertNotNull($mgr->getParser($groupname), "Resolved groupname '$groupname' does not map to a parser");
    }

    public function testElasticsearchResolveParserForSourceUnknownIdReturnsNull(): void
    {
        $mgr = $this->makeElasticsearchManager();
        ob_start();
        $groupname = $mgr->resolveParserForSource((string)self::NO_SUCH_SOURCE_ID);
        ob_end_clean();

        $this->assertNull($groupname);
    }
}

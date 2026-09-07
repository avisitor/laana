<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

/**
 * The index-page information view calls getCorpusStats() and
 * getTotalSourceGroupCounts(). For OpenSearch the documents index may not
 * exist on the cluster; getTotalSourceGroupCounts() must tolerate that and
 * return an empty array instead of letting the 404 escape (it used to:
 * its catch (Exception) matched the nonexistent \HawaiianSearch\Exception,
 * so every failure escaped and blanked the overview).
 */
final class OpenSearchOverviewTest extends BaseTestCase
{
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

    public function testTotalSourceGroupCountsToleratesMissingDocumentsIndex(): void
    {
        $counts = $this->provider()->getTotalSourceGroupCounts();
        $this->assertIsArray($counts);
        // Reads must follow the official alias (hawaiian_documents), not the
        // legacy concrete hawaiian_documents_new — which does not exist on a
        // cluster whose alias points at a switched-in staging index.
        $this->assertNotEmpty($counts, 'Group counts must come from the official alias, not hawaiian_documents_new');
    }

    public function testSourceGroupCountsFollowsOfficialAlias(): void
    {
        $counts = $this->provider()->getSourceGroupCounts();
        $this->assertIsArray($counts);
        $this->assertNotEmpty($counts, 'Source group counts must come from the official alias');
    }

    public function testLatestSourceDatesFollowsOfficialAlias(): void
    {
        $dates = $this->provider()->getLatestSourceDates();
        $this->assertIsArray($dates);
        $this->assertNotEmpty($dates, 'Latest source dates must come from the official alias');
    }

    public function testCorpusStatsShape(): void
    {
        $stats = $this->provider()->getCorpusStats();
        $this->assertArrayHasKey('sentence_count', $stats);
        $this->assertArrayHasKey('source_count', $stats);
    }
}

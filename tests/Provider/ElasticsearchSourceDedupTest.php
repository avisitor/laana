<?php
namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;
use Noiiolelo\Tests\BaseTestCase;

/**
 * Second-level source dedup in the Elasticsearch save flow (MySQL parity).
 *
 * MySQLSaveManager::processOneDocument dedups scraped documents in two
 * levels: exact link first, then sourcename ("double-check if it is an
 * updated link"), updating the stored link and invalidating the indexed
 * content when matched by name. The Elasticsearch flow historically only
 * had the link lookup, so a parser URL change (e.g. staradvertiser.com
 * moving columns between /editorial/ and /hawaii-news/ paths) made every
 * re-scraped article be added as a brand-new sourceid — the September
 * 2026 duplicate-sources incident (ES sourceids 64661-64718).
 *
 * Runs against the live Elasticsearch configured in .env; skips when it
 * is unreachable. All seeded data uses throwaway IDs/names and is removed
 * in finally blocks.
 */
class ElasticsearchSourceDedupTest extends BaseTestCase
{
    private const SEED_ID_BASE = 987650000;

    private ?ElasticsearchSaveManager $mgr = null;

    private function mgr(): ElasticsearchSaveManager
    {
        if ($this->mgr === null) {
            $this->mgr = new ElasticsearchSaveManager(['verbose' => false]);
        }
        return $this->mgr;
    }

    private function requireElasticsearch(): void
    {
        if (!getenv('ES_HOST')) {
            $this->markTestSkipped('ES_HOST must be set for ElasticsearchSaveManager tests');
        }
        $this->skipIfProviderUnavailable('Elasticsearch');
    }

    /**
     * Seed one throwaway source-metadata document.
     */
    private function seedSource(int $sourceId, string $sourcename, string $link): void
    {
        $this->mgr()->getClient()->saveSourceMetadata([[
            'sourceid' => $sourceId,
            'sourcename' => $sourcename,
            'link' => $link,
            'groupname' => 'deduptest',
            'date' => '2099-01-01',
            'title' => 'dedup test seed',
            'authors' => '',
            'discarded' => false,
            'empty' => false,
            'quality' => null,
        ]]);
        $this->mgr()->getClient()->refresh(
            $this->mgr()->getClient()->getSourceMetadataName()
        );
    }

    private function cleanupSource(int $sourceId): void
    {
        try {
            $this->mgr()->getClient()->deleteSourceMetadata((string)$sourceId);
        } catch (\Throwable $e) {
            // ignore cleanup failures
        }
        try {
            $this->mgr()->getClient()->deleteSourceContent((string)$sourceId);
        } catch (\Throwable $e) {
            // ignore cleanup failures
        }
    }

    /**
     * Read raw content through the public API (getDocumentRaw resolves the
     * active content index — production alias or staging concrete).
     */
    private function rawContent(string $sid): ?string
    {
        return $this->mgr()->getClient()->getDocumentRaw($sid);
    }

    public function testGetSourceByNameFindsSeededSource(): void
    {
        $this->requireElasticsearch();
        $sid = self::SEED_ID_BASE + random_int(1, 999);
        $name = 'ZZDedupTest: ' . uniqid();
        $link = 'https://deduptest.example.org/' . uniqid() . '/';

        try {
            $this->seedSource($sid, $name, $link);

            $byName = $this->mgr()->getClient()->getSourceByName($name);
            $this->assertNotNull($byName, 'getSourceByName must find a source seeded by sourcename');
            $this->assertEquals($sid, $byName['sourceid']);
            $this->assertSame($link, $byName['link']);

            $this->assertNull(
                $this->mgr()->getClient()->getSourceByName('ZZDedupTest: no-such-name-' . uniqid()),
                'getSourceByName must return null for an unknown sourcename'
            );
        } finally {
            $this->cleanupSource($sid);
        }
    }

    /**
     * The core regression: when the scraped link is unknown but the
     * sourcename matches an existing source, resolveExistingSource() must
     * return the EXISTING sourceid (no new id), update the stored link to
     * the scraped one, and invalidate the raw content so saveContents()
     * re-fetches from the new link.
     */
    public function testResolveExistingSourceMatchesBySourcenameWhenLinkChanged(): void
    {
        $this->requireElasticsearch();
        $mgr = $this->mgr();
        $client = $mgr->getClient();
        $sid = self::SEED_ID_BASE + random_int(1000, 1999);
        $name = 'ZZDedupTest: ' . uniqid();
        $oldLink = 'https://deduptest.example.org/old/' . uniqid() . '/';
        $newLink = 'https://deduptest.example.org/new/' . uniqid() . '/';

        try {
            $this->seedSource($sid, $name, $oldLink);
            $client->indexRaw($sid, '<html>old content</html>');
            // indexRaw does not refresh; content index refreshes are
            // scheduled, so force one for immediate read visibility.
            $client->refresh($client->getContentName());
            $this->assertNotNull(
                $this->rawContent((string)$sid),
                'seeded raw content must exist before the resolve'
            );

            $resolved = $mgr->resolveExistingSource($name, $newLink);

            $this->assertNotNull(
                $resolved,
                'a changed link for a known sourcename must resolve to the existing source'
            );
            $this->assertEquals($sid, $resolved['sourceid'], 'must reuse the existing sourceid, not mint a new one');

            // Stored metadata now carries the new link.
            $stored = $client->getSourceByName($name);
            $this->assertNotNull($stored);
            $this->assertSame($newLink, $stored['link'], 'stored link must be updated to the scraped link');

            // Content invalidated so the save flow re-fetches from the new link.
            $this->assertNull(
                $this->rawContent((string)$sid),
                'raw content must be invalidated after a link change'
            );

            // The old link no longer resolves to anything; the new one does.
            $this->assertNull($client->getSourceByLink($oldLink));
            $this->assertNotNull($client->getSourceByLink($newLink));
        } finally {
            $this->cleanupSource($sid);
        }
    }

    /**
     * A source that is genuinely new (unknown link AND unknown sourcename)
     * must not match anything and must not write anything.
     */
    public function testResolveExistingSourceReturnsNullForGenuinelyNewSource(): void
    {
        $this->requireElasticsearch();
        $name = 'ZZDedupTest: unknown-' . uniqid();
        $link = 'https://deduptest.example.org/unknown/' . uniqid() . '/';

        $this->assertNull(
            $this->mgr()->resolveExistingSource($name, $link),
            'unknown link + unknown sourcename must not resolve'
        );
    }
}

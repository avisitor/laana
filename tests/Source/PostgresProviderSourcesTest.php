<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

/**
 * The Sources tab (ops/getSourcesHtml.php) for the Postgres provider.
 *
 * PostgresLaana inherits Laana::getsources(), whose MySQL-only SQL breaks
 * on Postgres twice: the sentencecount alias cannot appear in HAVING, and
 * o.* may only be grouped when the grouped column is the table's primary
 * key (it is — sources.sourceid — so GROUP BY o.sourceid is valid). This
 * pins the Postgres behavior to the same contract the MySQL tab relies on.
 */
final class PostgresProviderSourcesTest extends BaseTestCase
{
    private function provider(): ?\Noiiolelo\Providers\Postgres\PostgresProvider
    {
        if (!($_ENV['PG_HOST'] ?? getenv('PG_HOST'))) {
            $this->markTestSkipped('No PG_HOST');
        }
        $this->skipIfProviderUnavailable('Postgres');
        return getProvider('Postgres');
    }

    public function testGetSourcesReturnsRowsWithSourceViewFields(): void
    {
        $provider = $this->provider();
        $sources = $provider->getSources('');

        $this->assertNotEmpty($sources, 'Postgres sources table has data; getSources must return rows');
        $row = $sources[0];
        foreach (['sourcename', 'sourceid', 'groupname', 'link', 'date', 'authors', 'sentencecount'] as $key) {
            $this->assertArrayHasKey($key, $row, "Sources rows must expose '$key' for the Sources tab");
        }
    }

    public function testGetSourcesByGroupnameFilters(): void
    {
        $provider = $this->provider();
        $all = $provider->getSources('');
        $this->assertNotEmpty($all, 'No sources to filter');

        $groupname = $all[0]['groupname'];
        $this->assertNotEmpty($groupname, 'First source must have a groupname to test filtering');

        $filtered = $provider->getSources($groupname);
        $this->assertNotEmpty($filtered, "Filtering by '$groupname' must return at least the seed source");
        $this->assertLessThanOrEqual(count($all), count($filtered));
        foreach (array_slice($filtered, 0, 20) as $row) {
            $this->assertSame($groupname, $row['groupname']);
        }
    }

    public function testGetSourcesProjectsRequestedProperties(): void
    {
        $provider = $this->provider();
        $sources = $provider->getSources('', ['sourcename']);

        $this->assertNotEmpty($sources, 'Property projection must not empty the result');
        foreach (array_slice($sources, 0, 20) as $row) {
            $this->assertArrayHasKey('sourcename', $row);
            $this->assertArrayNotHasKey('groupname', $row, 'Only requested properties are returned');
        }
    }
}

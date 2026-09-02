<?php
namespace Noiiolelo\Tests\Database;

use Noiiolelo\Tests\BaseTestCase;

class PostgresSourcePipelineTest extends BaseTestCase
{
    private function requirePg(): void
    {
        // The pipeline reads source rows from MySQL as well, so both DBs must
        // be configured — otherwise the constructor fails instead of skipping.
        if (!getenv('PG_HOST') || !getenv('PG_DATABASE') || !getenv('DB_HOST') || !getenv('DB_DATABASE')) {
            $this->markTestSkipped('PG_HOST, PG_DATABASE, DB_HOST and DB_DATABASE must be set for PostgresSourcePipeline tests');
        }
    }

    public function testProcessSourceReturnsCounterShape(): void
    {
        $this->requirePg();
        $pipeline = new \Noiiolelo\Providers\Postgres\PostgresSourcePipeline([
            'dryrun' => true,
        ]);
        // Counters must always exist, even in dryrun with an unknown source.
        $out = $pipeline->processSource(999999999);
        foreach (['sentences_data','sentence_vectors','sentence_metrics','document_metrics','document_vectors','patterns'] as $k) {
            $this->assertArrayHasKey($k, $out);
            $this->assertSame(0, $out[$k]);
        }
    }
}

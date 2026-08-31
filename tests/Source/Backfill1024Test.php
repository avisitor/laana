<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * Verify that backfill_pg_doc_vectors_1024.php populates
 * the embedding_1024 column for rows that have text.
 *
 * MUTATES the live DB (populates a handful of rows) — bounded and intentional.
 * Skipped when PG_HOST is not set or embedding service is unreachable.
 */
class Backfill1024Test extends BaseTestCase
{
    public function testBackfillDocVectors1024PopulatesRows(): void
    {
        if (!getenv('PG_HOST')) {
            $this->markTestSkipped('No PG_HOST');
        }

        // Require the script so backfillDocVectors1024() is defined but NOT auto-run
        require_once __DIR__ . '/../../scripts/backfill_pg_doc_vectors_1024.php';

        // Guard: embedding service must be reachable
        try {
            $ec = new \HawaiianSearch\EmbeddingClient();
            $probe = $ec->embedText('test probe', 'passage: ', \HawaiianSearch\EmbeddingClient::MODEL_LARGE);
            if ($probe === null || !is_array($probe) || count($probe) !== 1024) {
                $this->markTestSkipped('Embedding service unreachable or returns wrong dims');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Embedding service unreachable: ' . $e->getMessage());
        }

        $pg = new \PostgresLaana();
        $this->assertNotNull($pg->conn, 'PostgresLaana connection failed');

        // Pick up to 5 sourceids whose embedding_1024 is NULL. On a mostly
        // backfilled corpus there may be none — the test then has nothing to
        // prove and skips rather than failing on an arbitrary row count.
        $stmt = $pg->conn->query(
            "SELECT sourceid FROM contents
              WHERE embedding_1024 IS NULL
                AND text IS NOT NULL
                AND text <> ''
              ORDER BY sourceid
              LIMIT 5"
        );
        $before = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($before) === 0) {
            $this->markTestSkipped('No rows with NULL embedding_1024 to backfill');
        }
        $targetCount = count($before);

        $beforeIds = array_map('intval', $before);

        // Run the backfill for exactly these rows (same ORDER BY sourceid)
        $updated = backfillDocVectors1024($targetCount, false);
        $this->assertGreaterThanOrEqual($targetCount, $updated, 'backfillDocVectors1024 should have updated all targeted rows');

        // Verify all targeted rows now have non-null embedding_1024
        $placeholders = implode(',', array_fill(0, count($beforeIds), '?'));
        $check = $pg->conn->prepare(
            "SELECT count(*) FROM contents
              WHERE sourceid IN ($placeholders)
                AND embedding_1024 IS NOT NULL"
        );
        $check->execute($beforeIds);
        $count = (int) $check->fetchColumn();
        $this->assertEquals($targetCount, $count, 'All targeted rows should now have non-null embedding_1024');
    }
}

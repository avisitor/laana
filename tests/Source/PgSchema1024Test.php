<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * Verify that laana.contents has the 1024-dim document embedding column.
 * Skipped when PG_HOST is not reachable.
 */
class PgSchema1024Test extends BaseTestCase
{
    public function testContentsHasEmbedding1024Column(): void
    {
        if (!getenv('PG_HOST')) {
            $this->markTestSkipped('No PG_HOST');
        }

        $pg = new \PostgresLaana();

        $this->assertNotNull($pg->conn, 'PostgresLaana connection failed');

        $rows = $pg->conn->query(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'laana'
                AND table_name   = 'contents'
                AND column_name  = 'embedding_1024'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty(
            $rows,
            'laana.contents.embedding_1024 column not found'
        );
    }
}

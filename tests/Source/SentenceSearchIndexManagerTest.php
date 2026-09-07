<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Providers\Postgres\SentenceSearchIndexManager;
use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * Round-trips SentenceSearchIndexManager against a throwaway schema (created
 * and dropped by the test) rather than the live laana indexes, with an
 * injected state file so the production logs/ state is never touched.
 *
 * Covers: ensure/drop idempotency and restoration, the capture-before-drop
 * stash (executable pg_get_indexdef fidelity), stash-driven missing()
 * semantics (stashed names are owed back; non-stashed absences are not
 * resurrected while a stash exists; with no stash the fallback list is the
 * baseline), and a drift alarm that pins the fallback DDL to the live laana
 * definitions.
 *
 * Skipped when PG_HOST is not reachable. Requires CREATE privilege on the
 * database (the laana role has it) to build the scratch schema.
 */
class SentenceSearchIndexManagerTest extends BaseTestCase
{
    private ?\PDO $pg = null;
    private string $schema = '';
    private string $stateFile = '';

    protected function setUp(): void
    {
        if (!getenv('PG_HOST')) {
            $this->markTestSkipped('No PG_HOST');
        }
        $this->skipIfProviderUnavailable('Postgres');

        $this->pg = (new \PostgresLaana())->conn;
        $this->assertNotNull($this->pg, 'PostgresLaana connection failed');
        $this->pg->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // PID suffix keeps concurrent phpunit runs from colliding.
        $this->schema = 'pgimport_idx_test_' . getmypid();

        $this->dropScratchSchema();
        $this->pg->exec('CREATE SCHEMA ' . $this->schema);
        // Minimal stand-in for laana.sentences: only the columns the
        // derivative indexes reference.
        $this->pg->exec(
            'CREATE TABLE ' . $this->schema . '.sentences ('
            . 'sentenceid bigint PRIMARY KEY,'
            . 'sourceid integer,'
            . 'hawaiiantext text,'
            . 'hawaiian_tsv tsvector,'
            . 'hawaiian_unaccent_tsv tsvector,'
            . 'embedding public.vector(384))'
        );
        // CREATE INDEX only takes an unqualified name: placement follows
        // search_path (same convention production relies on).
        $this->pg->exec('SET search_path = ' . $this->schema);

        $this->stateFile = sys_get_temp_dir() . '/pgimport-index-state-test-' . getmypid() . '.json';
        @unlink($this->stateFile);
    }

    protected function tearDown(): void
    {
        if ($this->pg !== null) {
            $this->dropScratchSchema();
        }
        $this->pg = null;
        $this->schema = '';
        if ($this->stateFile !== '') {
            @unlink($this->stateFile);
            $this->stateFile = '';
        }
    }

    private function dropScratchSchema(): void
    {
        $this->pg->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
    }

    private function newManager(): SentenceSearchIndexManager
    {
        return new SentenceSearchIndexManager($this->pg, $this->schema, $this->stateFile);
    }

    /**
     * @return array<int, string>
     */
    private function allThree(): array
    {
        return [
            'sentences_embedding_ivfflat',
            'sentences_hawaiian_tsv_gin',
            'sentences_hawaiian_unaccent_tsv_gin',
        ];
    }

    public function testDropAllThenEnsureAllRestoresEveryDerivativeIndex(): void
    {
        $manager = $this->newManager();

        // Seed via the manager itself — the same path production uses.
        $this->assertSame($this->allThree(), $manager->ensureAll(), 'ensureAll on an empty table creates all three');
        $this->assertSame([], $manager->missing(), 'nothing missing after ensureAll');
        $this->assertSame([], $manager->ensureAll(), 'ensureAll is idempotent');

        $this->assertSame($this->allThree(), $manager->dropAll(), 'dropAll drops all three');
        $this->assertSame([], $manager->existing(), 'nothing present after dropAll');
        $this->assertSame([], $manager->dropAll(), 'dropAll is idempotent');

        $this->assertSame($this->allThree(), $manager->ensureAll(), 'ensureAll restores all three after drop');
        $this->assertSame([], $manager->missing(), 'nothing missing after restore');
    }

    public function testDropAllCapturesDefinitionsBeforeDropping(): void
    {
        $manager = $this->newManager();
        $manager->ensureAll();

        $this->assertFileDoesNotExist($this->stateFile, 'no stash before any drop');
        $manager->dropAll();

        $this->assertFileExists($this->stateFile);
        $stash = json_decode((string)file_get_contents($this->stateFile), true);
        $this->assertSame($this->schema, $stash['schema']);
        $this->assertCount(3, $stash['indexes']);
        foreach ($this->allThree() as $name) {
            $this->assertMatchesRegularExpression(
                '/^CREATE INDEX ' . $name . ' ON /',
                $stash['indexes'][$name],
                "stash must hold the executable pg_get_indexdef definition for {$name}"
            );
        }

        $created = $manager->ensureAll();
        $this->assertSame($this->allThree(), $created);
        $this->assertFileDoesNotExist($this->stateFile, 'fully-paid stash is cleared');
    }

    public function testMissingUsesStashWhenPresentAndFallsBackWhenAbsent(): void
    {
        $manager = $this->newManager();
        $manager->ensureAll(); // seed all three; stash cleared on success

        // No stash: the fallback list is the baseline — a manually dropped
        // index is still reported as missing and recreated via the fallback.
        $this->pg->exec('DROP INDEX ' . $this->schema . '.sentences_hawaiian_tsv_gin');
        $this->assertSame(['sentences_hawaiian_tsv_gin'], $manager->missing());
        $this->assertSame(['sentences_hawaiian_tsv_gin'], $manager->ensureAll());

        // Stash non-empty: only stashed names are owed back. Seed a stash
        // holding ONLY the ivfflat entry, then drop a non-stashed index —
        // it must NOT be reported (dropping it was someone's intent).
        $stash = [
            'schema' => $this->schema,
            'indexes' => [
                'sentences_embedding_ivfflat' => 'CREATE INDEX sentences_embedding_ivfflat ON '
                    . $this->schema . '.sentences USING ivfflat (embedding public.vector_cosine_ops)'
                    . " WITH (lists='1000')",
            ],
        ];
        file_put_contents($this->stateFile, json_encode($stash));
        $this->pg->exec('DROP INDEX ' . $this->schema . '.sentences_hawaiian_tsv_gin');
        $this->assertSame([], $manager->missing(), 'non-stashed absences are not resurrected while a stash exists');
    }

    /**
     * Drift alarm: the fallback DDL must keep reproducing the live laana
     * definitions exactly. Builds each fallback index on the scratch schema
     * and compares pg_get_indexdef with the live index after substituting
     * the schema name. Fails loudly when a migration or pgschema.sql change
     * alters an index without the fallback list being updated.
     */
    public function testFallbackDdlMatchesLiveLaanaDefinitions(): void
    {
        $manager = $this->newManager();
        $manager->ensureAll();

        $live = $this->pg->prepare(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'laana' AND indexname = ?"
        );
        $scratch = $this->pg->prepare(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?'
        );

        foreach ($manager->indexDdl() as $name => $ddl) {
            $live->execute([$name]);
            $liveDef = $live->fetchColumn();
            $this->assertNotFalse($liveDef, "live laana.{$name} is missing — update the fallback DDL and pgschema.sql together");

            $scratch->execute([$this->schema, $name]);
            $scratchDef = $scratch->fetchColumn();
            $this->assertNotFalse($scratchDef, "fallback DDL failed to create {$name}");

            $this->assertSame($liveDef, str_replace("{$this->schema}.", 'laana.', $scratchDef),
                "fallback DDL for {$name} drifted from the live definition");
        }
    }
}

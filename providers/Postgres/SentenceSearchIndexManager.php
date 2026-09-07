<?php
declare(strict_types=1);

namespace Noiiolelo\Providers\Postgres;

/**
 * Drop/recreate the derivative search indexes on the laana.sentences table.
 *
 * These indexes serve search only — the import pipeline never reads them —
 * yet every sentence rewrite pays for them: each non-HOT UPDATE inserts into
 * the ivfflat index (scanning every IVF centroid) and into any GIN indexes.
 * Full `pg_import --force` rebuilds therefore drop them before loading and
 * recreate them at the end; interrupted runs are repaired by pg_import's
 * signal handlers/shutdown hook or by the startup check of the next run.
 *
 * Drift-proofing: the drop targets are DISCOVERED live (every index on the
 * table except constraint-backed ones and the keep-list below), and each
 * dropped index's executable definition (pg_get_indexdef) is captured into
 * a state file BEFORE it is dropped. Recreation restores exactly what was
 * captured, so a changed lists= or a newly added search index survives the
 * drop/recreate cycle unchanged. The hard-coded DDL in indexDdl() is only
 * a fallback for runs whose state file was lost; tests/Source/
 * SentenceSearchIndexManagerTest.php keeps it pinned to the live schema.
 *
 * pgschema.sql is documentation, not a runtime source: it does not list the
 * GIN indexes, and the live database is the authoritative state.
 *
 * Expects the PDO to throw on SQL errors (PDO::ERRMODE_EXCEPTION), which is
 * how pg_import.php and PostgresSourcePipeline configure the connection.
 */
class SentenceSearchIndexManager
{
    /**
     * Indexes the import itself depends on and must never be dropped.
     * Constraint-backed indexes (the primary key) are protected separately
     * by the discovery query.
     */
    private const KEEP_INDEXES = ['idx_sentences_source'];

    private const STATE_FILENAME = 'pg-import-index-state.json';

    private \PDO $pg;
    private string $schema;
    private string $stateFile;

    public function __construct(\PDO $pg, string $schema = 'laana', ?string $stateFile = null)
    {
        $this->pg = $pg;
        $this->schema = $schema;
        $this->stateFile = $stateFile
            ?? dirname(__DIR__, 2) . '/logs/' . self::STATE_FILENAME;
    }

    /**
     * Fallback recreate DDL, used only when the state file is missing or
     * holds no entry for an index. Definitions must keep matching the live
     * laana indexes (ivfflat lists=1000 as built by pgschema.sql section 9;
     * vector_cosine_ops is qualified with public because that is where the
     * pgvector extension is installed). CREATE INDEX only accepts an
     * unqualified index name, so placement follows search_path — the same
     * convention the import pipeline's unqualified SQL already relies on.
     *
     * @return array<string, string> unqualified index name => CREATE statement
     */
    public function indexDdl(): array
    {
        $table = "{$this->schema}.sentences";
        return [
            'sentences_embedding_ivfflat' =>
                'CREATE INDEX IF NOT EXISTS sentences_embedding_ivfflat'
                . " ON {$table} USING ivfflat (embedding public.vector_cosine_ops)"
                . ' WITH (lists = 1000)',
            'sentences_hawaiian_tsv_gin' =>
                'CREATE INDEX IF NOT EXISTS sentences_hawaiian_tsv_gin'
                . " ON {$table} USING gin (hawaiian_tsv)",
            'sentences_hawaiian_unaccent_tsv_gin' =>
                'CREATE INDEX IF NOT EXISTS sentences_hawaiian_unaccent_tsv_gin'
                . " ON {$table} USING gin (hawaiian_unaccent_tsv)",
        ];
    }

    /**
     * All drop-eligible indexes currently on the sentences table: every
     * index except constraint-backed ones and the keep-list. Discovered
     * live, so indexes added to the schema later are handled without
     * touching this class.
     *
     * @return array<string, string> index name => executable definition
     */
    public function droppableIndexes(): array
    {
        $keepPlaceholders = implode(', ', array_fill(0, count(self::KEEP_INDEXES), '?'));
        $stmt = $this->pg->prepare(
            "SELECT c.relname AS indexname, pg_get_indexdef(c.oid) AS indexdef
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_index i ON i.indexrelid = c.oid
             JOIN pg_class t ON t.oid = i.indrelid
             LEFT JOIN pg_constraint con ON con.conindid = c.oid
             WHERE n.nspname = ? AND t.relname = 'sentences' AND c.relkind = 'i'
               AND con.oid IS NULL
               AND c.relname NOT IN ($keepPlaceholders)
             ORDER BY c.relname"
        );
        $stmt->execute(array_merge([$this->schema], self::KEEP_INDEXES));

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[$row['indexname']] = $row['indexdef'];
        }
        return $out;
    }

    /**
     * Names of the drop-eligible indexes currently on the table.
     *
     * @return array<int, string>
     */
    public function existing(): array
    {
        return array_keys($this->droppableIndexes());
    }

    /**
     * Names of the indexes this system owes back to the table but that are
     * currently absent. Primary source is the state file (what a previous
     * run dropped); with an empty or schema-mismatched stash the fallback
     * DDL list is the baseline instead, so a lost state file still gets
     * repaired. Indexes that are neither stashed nor in the fallback list
     * are not reported — an absent stash entry means no run ever dropped
     * them, so their absence is intentional.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $existing = array_flip($this->existing());
        $stash = $this->readStash();
        $stashIndexes = $this->stashIndexesForSchema($stash);

        $expected = $stashIndexes !== []
            ? array_keys($stashIndexes)
            : array_keys($this->indexDdl());

        $missing = [];
        foreach ($expected as $name) {
            if (!isset($existing[$name])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * Drop every drop-eligible index that exists, after capturing its
     * executable definition into the state file. Returns the names dropped
     * (empty when none were present).
     *
     * @return array<int, string>
     */
    public function dropAll(): array
    {
        $droppable = $this->droppableIndexes();
        if ($droppable === []) {
            return [];
        }

        // Capture BEFORE dropping: this file is what guarantees the
        // recreate matches the dropped definitions exactly. Merged into
        // any existing stash so an interrupted earlier drop is not lost.
        $stash = $this->readStash();
        $stashIndexes = $this->stashIndexesForSchema($stash);
        foreach ($droppable as $name => $def) {
            $stashIndexes[$name] = $def;
        }
        $this->writeStash([
            'schema' => $this->schema,
            'table' => "{$this->schema}.sentences",
            'captured_at' => date(DATE_ATOM),
            'indexes' => $stashIndexes,
        ]);

        foreach (array_keys($droppable) as $name) {
            $this->pg->exec("DROP INDEX IF EXISTS {$this->schema}.{$name}");
        }
        return array_keys($droppable);
    }

    /**
     * Recreate every missing index: from the captured state-file definition
     * when one exists (exact fidelity), otherwise from the fallback DDL.
     * Returns the names created (empty when all present) and clears the
     * state file once nothing is missing. Throws on SQL failure — callers
     * decide whether that is fatal. The ivfflat build over a full corpus
     * can take minutes; that cost is inherent to restoring search.
     *
     * @return array<int, string>
     */
    public function ensureAll(): array
    {
        $missing = $this->missing();
        if ($missing === []) {
            // Nothing owed: a fully-paid stash is stale, drop it.
            if ($this->stashExists()) {
                $this->clearStash();
            }
            return [];
        }

        $stash = $this->readStash();
        $stashIndexes = $this->stashIndexesForSchema($stash);
        $fallback = $this->indexDdl();

        $created = [];
        foreach ($missing as $name) {
            $ddl = $stashIndexes[$name] ?? $fallback[$name] ?? null;
            if ($ddl === null) {
                throw new \RuntimeException("no stored definition available to recreate index {$name}");
            }
            $this->pg->exec($ddl);
            $created[] = $name;
        }

        $this->clearStash();
        return $created;
    }

    /**
     * Stash index entries that belong to the manager's schema; entries
     * captured for a different schema are ignored (stashed DDL names that
     * schema's table and must never be applied elsewhere).
     *
     * @param array<string, mixed> $stash
     * @return array<string, string>
     */
    private function stashIndexesForSchema(array $stash): array
    {
        if (($stash['schema'] ?? '') !== $this->schema
            || !isset($stash['indexes']) || !is_array($stash['indexes'])
        ) {
            return [];
        }
        $out = [];
        foreach ($stash['indexes'] as $name => $def) {
            if (is_string($name) && is_string($def) && $def !== '') {
                $out[$name] = $def;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readStash(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = file_get_contents($this->stateFile);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function stashExists(): bool
    {
        return $this->stashIndexesForSchema($this->readStash()) !== [];
    }

    /**
     * @param array<string, mixed> $stash
     */
    private function writeStash(array $stash): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create directory for index state file {$this->stateFile}");
        }
        $tmp = $this->stateFile . '.tmp.' . getmypid();
        if (file_put_contents($tmp, json_encode($stash, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException("cannot write index state file {$tmp}");
        }
        if (!rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new \RuntimeException("cannot replace index state file {$this->stateFile}");
        }
    }

    private function clearStash(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }
}

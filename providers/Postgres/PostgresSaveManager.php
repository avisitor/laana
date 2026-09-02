<?php
namespace Noiiolelo\Providers\Postgres;

use Noiiolelo\Providers\MySQL\MySQLSaveManager;

/**
 * Daily Postgres ingestion. Scrapes exactly like MySQLSaveManager (MySQL
 * stays the catalog of record: sourceIDs and sentenceIDs are allocated
 * there, keeping this path ID-identical to scripts/pg_import.php bootstrap),
 * then mirrors the saved source into Postgres — data, vectors, metrics,
 * grammar patterns — via PostgresSourcePipeline.
 *
 * Counts policy: refreshGrammarPatternCounts() runs exactly ONCE per run,
 * after the run loop (never per source, never inside a transaction).
 */
class PostgresSaveManager extends MySQLSaveManager
{
    protected $logName = "PostgresSaveManager";
    private ?PostgresSourcePipeline $pipeline = null;
    private int $patternsSaved = 0;
    private int $mirrorFailures = 0;
    private bool $mirroredAnything = false;

    private function pipeline(): PostgresSourcePipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = new PostgresSourcePipeline([
                'dryrun'    => false,
                'force'     => (bool)($this->options['force'] ?? false),
                'sentences' => true,
                'documents' => true,
            ]);
        }
        return $this->pipeline;
    }

    public function saveContents($parser, $source)
    {
        $count = parent::saveContents($parser, $source);
        $sourceID = (int)($source['sourceid'] ?? 0);

        // ALWAYS mirror every selected source. Do NOT gate on $count > 0:
        // in the daily driver (updatenoiiolelo.sh) the mysql pass runs first,
        // so this pass typically sees sentences already present and parent
        // saveContents() returns 0 — gating on the return value would skip
        // the mirror exactly when it matters. The pipeline is delta-safe
        // (ON CONFLICT upserts; embeddings only for missing vectors; grammar
        // scan only for patternless sentences), so an already-mirrored source
        // costs one small read-only-in-practice transaction, except
        // zero-match sentences are re-scanned each run (documented delta
        // non-convergence).
        // NOTE: no counts refresh here — it happens once per run (below).
        if ($sourceID > 0) {
            try {
                $out = $this->pipeline()->processSource($sourceID);
                $this->patternsSaved += $out['patterns'];
                $this->mirroredAnything = true;
                $this->log("PG mirror sourceID {$sourceID}: {$out['sentences_data']} sentences, "
                    . "{$out['patterns']} patterns");
            } catch (\Throwable $e) {
                // MySQL save already succeeded; PG sync failure must not
                // abort the batch — report and continue.
                $this->mirrorFailures++;
                $this->log("PG mirror failed for sourceID {$sourceID}: " . $e->getMessage());
                \Avisitor\Monolog\Logger::logError("PostgresSaveManager PG mirror: " . $e->getMessage());
            }
        }
        return $count;
    }

    /** Once-per-run counts refresh, outside any transaction.
     *  Consumption guard: getAllDocuments() delegates to processOneSource()
     *  for single-source runs (MySQLSaveManager.php:598-601), and both are
     *  overridden — clearing the flag here makes the refresh idempotent
     *  under that nesting. */
    private function refreshCountsOnce(): void
    {
        if (!$this->mirroredAnything) { return; }
        $this->mirroredAnything = false;
        if ($this->pipeline()->refreshGrammarPatternCounts()) {
            $this->log("grammar_pattern_counts refreshed");
        } else {
            \Avisitor\Monolog\Logger::logError("PostgresSaveManager: grammar_pattern_counts refresh failed");
        }
    }

    public function getAllDocuments()
    {
        // "Current run" counters restart per batch entry, so the summary
        // keys below (and getPatternsSaved()) are batch-scoped even if an
        // instance ever runs two batches. getAllDocuments() delegates to
        // processOneSource() for single-source runs; the inner reset then
        // zeroes an already-zero counter and the outer append wins.
        $this->patternsSaved = 0;
        $this->mirrorFailures = 0;
        $summary = parent::getAllDocuments();
        $this->refreshCountsOnce();
        $summary['pg_mirror_failures'] = $this->mirrorFailures;
        $summary['patterns_saved'] = $this->patternsSaved;
        return $summary;
    }

    public function processOneSource($sourceid)
    {
        $this->patternsSaved = 0;
        $this->mirrorFailures = 0;
        $summary = parent::processOneSource($sourceid);
        $this->refreshCountsOnce();
        $summary['pg_mirror_failures'] = $this->mirrorFailures;
        $summary['patterns_saved'] = $this->patternsSaved;
        return $summary;
    }

    /** Patterns assigned to Postgres during the current run (also surfaced
     *  as $summary['patterns_saved']). */
    public function getPatternsSaved(): int
    {
        return $this->patternsSaved;
    }
}

<?php
/**
 * Backfill 1024-dim document vectors into Postgres (laana.contents.embedding_1024).
 *
 * Reads rows where embedding_1024 IS NULL, embeds their text with the
 * multilingual-e5-large-instruct model (1024 dims), writes back via a
 * temporary staging table.
 *
 * Usage:
 *   php scripts/backfill_pg_doc_vectors_1024.php [--limit=N] [--dryrun] [--sourceid=N]
 *
 * The function can be require'd by tests without auto-running.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db/PostgresFuncs.php';

use HawaiianSearch\EmbeddingClient;

/**
 * Backfill embedding_1024 for rows that are currently NULL.
 *
 * @param int       $limit     Max rows to process
 * @param bool      $dryrun    If true, embed but do not write to DB
 * @param int|null  $sourceId  If set, only process this specific sourceid
 * @return int Number of rows updated (or would be updated in dryrun)
 */
function backfillDocVectors1024(int $limit, bool $dryrun, ?int $sourceId = null): int
{
    $pg = new \PostgresLaana();
    if (!$pg->conn) {
        echo "ERROR: Postgres connection failed.\n";
        return 0;
    }

    $ec = new EmbeddingClient();

    // Keyset-pagination-ready: ORDER BY sourceid LIMIT :limit
    $sql = "SELECT sourceid, text FROM contents
             WHERE embedding_1024 IS NULL
               AND text IS NOT NULL
               AND text <> ''";
    $params = [];

    if ($sourceId !== null) {
        $sql .= " AND sourceid = :sid";
        $params[':sid'] = $sourceId;
    }

    $sql .= " ORDER BY sourceid LIMIT :limit";
    $params[':limit'] = $limit;

    $stmt = $pg->conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "No rows needing backfill found.\n";
        return 0;
    }

    $count = count($rows);
    echo "Found $count rows to backfill" . ($dryrun ? ' (dryrun)' : '') . ".\n";

    $updated = 0;
    $batch = []; // [sourceid => vec]
    $batchIds = [];

    foreach ($rows as $i => $row) {
        $sid = (int) $row['sourceid'];
        $text = $row['text'];

        if (empty(trim($text))) {
            echo "  [$sid] skipped (empty text)\n";
            continue;
        }

        try {
            $vec = $ec->embedText($text, 'passage: ', EmbeddingClient::MODEL_LARGE);
        } catch (\Throwable $e) {
            echo "  [$sid] WARNING: embedding failed: " . $e->getMessage() . "\n";
            continue;
        }

        if ($vec === null || !is_array($vec) || count($vec) !== 1024) {
            echo "  [$sid] WARNING: unexpected embedding (null or wrong dims)\n";
            continue;
        }

        $batch[$sid] = $vec;
        $batchIds[] = $sid;
        $updated++;

        if (($i + 1) % 10 === 0 || $i === $count - 1) {
            echo "  Embedded " . ($i + 1) . "/$count\n";
        }

        // Flush in batches of 50
        if (count($batch) >= 50) {
            if (!$dryrun) {
                flushBatch($pg, $batch);
            }
            $batch = [];
            $batchIds = [];
        }
    }

    // Flush remaining
    if (!empty($batch)) {
        if (!$dryrun) {
            flushBatch($pg, $batch);
        }
    }

    $label = $dryrun ? 'would update' : 'updated';
    echo "Done. $label $updated rows.\n";
    return $updated;
}

/**
 * Write a batch of (sourceid => vector) via a temporary staging table.
 */
function flushBatch(\PostgresLaana $pg, array $batch): void
{
    $pg->conn->exec("CREATE TEMP TABLE IF NOT EXISTS staging_doc1024 (sourceid bigint, embedding vector(1024))");
    $pg->conn->exec("TRUNCATE staging_doc1024");

    $ins = $pg->conn->prepare("INSERT INTO staging_doc1024 VALUES (:sid, (:e)::vector(1024))");

    foreach ($batch as $sid => $vec) {
        $vecStr = '[' . implode(',', $vec) . ']';
        $ins->execute([':sid' => $sid, ':e' => $vecStr]);
    }

    $pg->conn->exec(
        "UPDATE contents c
            SET embedding_1024 = s.embedding
           FROM staging_doc1024 s
          WHERE c.sourceid = s.sourceid"
    );
}

// Auto-run only when executed directly from CLI, not when require'd
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $options = getopt('', ['limit:', 'dryrun', 'sourceid:']);
    $limit   = isset($options['limit']) ? (int) $options['limit'] : 100;
    $dryrun  = isset($options['dryrun']);
    $sid     = isset($options['sourceid']) ? (int) $options['sourceid'] : null;

    echo "backfill_pg_doc_vectors_1024 — limit=$limit dryrun=" . ($dryrun ? 'yes' : 'no')
       . ($sid !== null ? " sourceid=$sid" : '') . "\n";

    $n = backfillDocVectors1024($limit, $dryrun, $sid);
    echo "\nTotal: $n rows processed.\n";
}

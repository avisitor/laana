<?php
declare(strict_types=1);

namespace Noiiolelo\Providers\Postgres;

require_once __DIR__ . '/PostgresClient.php';

use HawaiianSearch\EmbeddingClient as DocEmbeddingClient; // document vectors (1024-dim, MODEL_LARGE)
use Noiiolelo\EmbeddingClient;                            // sentence embeddings (384-dim, small model)

/**
 * Per-source Postgres pipeline: MySQL -> laana schema (data, vectors,
 * metrics, grammar-pattern assignments — delta-scanned inside the same
 * transaction). The counts materialized view is refreshed by the run
 * driver, once per run and outside any transaction.
 *
 * One processSource() call = ONE Postgres transaction; the source is a
 * complete unit on commit. Used by scripts/pg_import.php (bootstrap) and
 * providers/Postgres/PostgresSaveManager.php (daily ingestion).
 */
class PostgresSourcePipeline
{
    private \PDO $pg;               // MUST be the same connection as $pgLaana->conn
    private \PDO $mysql;
    private \PostgresLaana $pgLaana;
    private EmbeddingClient $embed;           // 384-dim sentence model
    private DocEmbeddingClient $docEmbed;     // 1024-dim document model (HawaiianSearch\EmbeddingClient alias)
    private \Noiiolelo\MetricsComputer $metrics;
    private bool $dryrun;
    private bool $force;
    private bool $doSentences;
    private bool $doDocuments;

    // MySQL reads
    private \PDOStatement $sourceByIdStmt;
    private \PDOStatement $contentStmt;
    private \PDOStatement $sentenceStmt;

    // Postgres upserts (parents/data)
    private \PDOStatement $sourceUpsert;
    private \PDOStatement $contentUpsert;
    private \PDOStatement $sentenceUpsert;

    // Postgres check statements (for incremental backfill)
    private \PDOStatement $sentenceNeedsWork;
    private \PDOStatement $allSentencesForSource;
    private \PDOStatement $docNeedsMetrics;
    private \PDOStatement $docNeedsVector;
    private \PDOStatement $docTextForSource;

    // Document metric + 1024-dim vector writes (one call per source).
    private \PDOStatement $documentMetricsUpsert;
    private \PDOStatement $docVectorUpdate;

    /**
     * Rows per batched write statement. Vector literals run several KB each,
     * so this bounds query size while keeping round trips ~WRITE_BATCH_SIZE×
     * rarer than per-row statements.
     */
    private const WRITE_BATCH_SIZE = 250;

    // Incremental fast-path state (dataAlreadyMirrored), built lazily on the
    // first source: per-source sentence signatures on both sides plus the
    // Postgres contents sourceid set.
    private ?array $pgSentenceSignatures = null;
    private ?array $mysqlSentenceSignatures = null;
    private ?array $pgContentSourceIds = null;

    public function __construct(array $config = [])
    {
        // PostgresLaana owns the Postgres connection on the laana schema; reuse it so the
        // embedding writes and the corpus writes share one transaction per source.
        if (isset($config['pgLaana'])) {
            $this->pgLaana = $config['pgLaana'];
        } else {
            $this->pgLaana = new \PostgresLaana();
        }
        if (!$this->pgLaana->conn) {
            throw new \RuntimeException('Postgres connection failed.');
        }

        $this->pg = $config['pg'] ?? $this->pgLaana->conn;
        if ($this->pg !== $this->pgLaana->conn) {
            throw new \RuntimeException(
                'PostgresSourcePipeline requires the same PDO for "pg" and "pgLaana->conn".'
            );
        }
        $this->pg->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Strict mode: the vendor layer's default (log + falsy return) would
        // swallow scan/migration failures mid-transaction, silently voiding
        // the "complete unit on commit" contract. Errors must throw here so
        // the per-source catch rolls back and the run counts an error.
        $this->pgLaana->throwOnError = true;

        $this->mysql = $config['mysql'] ?? self::connectMySql();

        $this->dryrun = (bool)($config['dryrun'] ?? false);
        $this->force = (bool)($config['force'] ?? false);
        $this->doSentences = (bool)($config['sentences'] ?? true);
        $this->doDocuments = (bool)($config['documents'] ?? true);

        $embedUrl = self::envValue('EMBEDDING_SERVICE_URL') ?: null;
        $this->embed = new EmbeddingClient($embedUrl);              // 384-dim sentence embeddings
        $this->docEmbed = new DocEmbeddingClient($embedUrl);         // 1024-dim document vectors (MODEL_LARGE)
        $this->metrics = new \Noiiolelo\MetricsComputer(dirname(__DIR__, 2) . '/hawaiian_words.txt');

        $this->prepareStatements();
    }

    /**
     * Migrate one source from MySQL and derive vectors/metrics/patterns.
     * Fetches the source row from MySQL by id (same column list as the
     * pg_import source query). If the sourceid does not exist in MySQL,
     * returns zero counters WITHOUT opening a Postgres transaction.
     * Otherwise: one transaction; the source is a complete unit on commit.
     * Throws on failure.
     *
     * Returns the per-source counters (sentences_data, sentence_vectors,
     * sentence_metrics, document_metrics, document_vectors, patterns) plus
     * has_content (whether a MySQL contents row exists) so run drivers can
     * report per-source detail.
     */
    public function processSource(int $sourceId): array
    {
        $out = [
            'sentences_data'   => 0,
            'sentence_vectors' => 0,
            'sentence_metrics' => 0,
            'document_metrics' => 0,
            'document_vectors' => 0,
            'patterns'         => 0,
            'has_content'      => false,
            'skipped_data'     => false,
        ];

        $this->sourceByIdStmt->execute([':sourceid' => $sourceId]);
        $source = $this->sourceByIdStmt->fetch(\PDO::FETCH_ASSOC);
        $this->sourceByIdStmt->closeCursor();
        if ($source === false) {
            // Unknown source: nothing to migrate, and no transaction to open.
            return $out;
        }

        $this->pg->beginTransaction();
        try {
            // --- 1. Migrate parent rows. In incremental mode a source whose
            // sentence signature already matches on both sides is skipped:
            // its upserts would only rewrite identical rows, which is the
            // dominant cost of backfill/cron passes over a built corpus.
            // The delta scans below still run for skipped sources.
            $skipData = !$this->force && $this->dataAlreadyMirrored($sourceId);
            $sentenceDataCount = 0;
            if ($skipData) {
                $content = true;
            } else {
                $this->sourceUpsert->execute($source);

                $this->contentStmt->execute([':sourceid' => $sourceId]);
                $content = $this->contentStmt->fetch(\PDO::FETCH_ASSOC);
                if ($content) {
                    $this->contentUpsert->execute($content);
                }

                $this->sentenceStmt->execute([':sourceid' => $sourceId]);
                while ($row = $this->sentenceStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $this->sentenceUpsert->execute($row);
                    $sentenceDataCount++;
                }
            }

            $svec = 0; $smet = 0; $dmet = 0; $dvec = 0;

            // --- 2. Sentence embeddings + metrics ---
            if ($this->doSentences) {
                if ($this->force) {
                    $this->allSentencesForSource->execute([':sourceid' => $sourceId]);
                    $work = $this->allSentencesForSource->fetchAll(\PDO::FETCH_ASSOC);
                } else {
                    $this->sentenceNeedsWork->execute([':sourceid' => $sourceId]);
                    $work = $this->sentenceNeedsWork->fetchAll(\PDO::FETCH_ASSOC);
                }

                if (!empty($work)) {
                    $texts = [];
                    foreach ($work as $w) { $texts[] = (string)$w['hawaiiantext']; }

                    // One embedding batch per source (passage prefix, small model = 384-dim).
                    $vecs = $this->dryrun ? [] : $this->embed->embedSentences($texts, 'passage: ');
                    if (!$this->dryrun) {
                        if (!is_array($vecs) || count($vecs) !== count($texts)) {
                            throw new \RuntimeException(
                                "sentence embedding count mismatch for source {$sourceId}: got "
                                . (is_array($vecs) ? count($vecs) : 'non-array')
                                . " expected " . count($texts)
                            );
                        }
                    }

                    $vectorLiterals = [];
                    if (!$this->dryrun) {
                        // Validate every vector BEFORE any write so a bad
                        // embedding aborts the source before staging rows land.
                        foreach ($work as $i => $w) {
                            $vec = $vecs[$i] ?? null;
                            if (!is_array($vec) || count($vec) !== 384) {
                                throw new \RuntimeException(
                                    'invalid 384-dim vector for sentence ' . (int)$w['sentenceid']
                                );
                            }
                            $vectorLiterals[(int)$w['sentenceid']] = self::vecLiteral($vec);
                        }
                    }

                    if (!$this->dryrun) {
                        // Batched write: stage every vector for the source, then
                        // ONE update. The old per-sentence loop spent four
                        // statements (temp create + insert + update + delete)
                        // per sentence, and each update rewrote the row plus
                        // all of its indexes — the dominant cost of rebuilds.
                        $this->pg->exec(
                            'CREATE TEMP TABLE IF NOT EXISTS staging_sent384 (sentenceid bigint, embedding vector(384)) ON COMMIT DROP'
                        );
                        $this->insertStagingVectors($vectorLiterals);
                        $this->pg->exec(
                            'UPDATE sentences s SET embedding = st.embedding '
                            . 'FROM staging_sent384 st WHERE s.sentenceid = st.sentenceid'
                        );
                        $svec = count($vectorLiterals);
                    }

                    // Metrics: computed in PHP for every sentence, written in
                    // batched multi-row upserts.
                    $metricRows = [];
                    foreach ($work as $w) {
                        $m = $this->metrics->computeSentenceMetrics((string)$w['hawaiiantext']);
                        $metricRows[] = [
                            'sid'   => (int)$w['sentenceid'],
                            'ratio' => (float)($m['hawaiian_word_ratio'] ?? 0),
                            'wc'    => (int)($m['word_count'] ?? 0),
                            'len'   => (int)($m['length'] ?? 0),
                            'ec'    => (int)($m['entity_count'] ?? 0),
                            'freq'  => (float)($m['frequency'] ?? 0),
                        ];
                    }
                    $smet = count($metricRows);
                    if (!$this->dryrun && $metricRows !== []) {
                        $this->upsertSentenceMetricsBatch($metricRows);
                    }
                }
            }

            // --- 3. Document metric + 1024-dim document vector ---
            if ($this->doDocuments && $content) {
                // Metric
                $needMetric = $this->force;
                if (!$needMetric) {
                    $this->docNeedsMetrics->execute([':sourceid' => $sourceId]);
                    $needMetric = (bool)$this->docNeedsMetrics->fetchColumn();
                }
                if ($needMetric) {
                    $this->docTextForSource->execute([':sourceid' => $sourceId]);
                    $docText = (string)($this->docTextForSource->fetchColumn() ?: '');
                    if ($docText !== '') {
                        $dm = $this->metrics->computeDocumentMetrics($docText);
                        if (!$this->dryrun) {
                            $this->documentMetricsUpsert->execute([
                                ':sid'   => $sourceId,
                                ':ratio' => (float)($dm['hawaiian_word_ratio'] ?? 0),
                                ':wc'    => (int)($dm['word_count'] ?? 0),
                                ':len'   => (int)($dm['length'] ?? 0),
                                ':ec'    => (int)($dm['entity_count'] ?? 0),
                            ]);
                        }
                        $dmet++;
                    }
                }

                // 1024-dim vector
                $vecText = null;
                if ($this->force) {
                    $this->docTextForSource->execute([':sourceid' => $sourceId]);
                    $vecText = $this->docTextForSource->fetchColumn();
                } else {
                    $this->docNeedsVector->execute([':sourceid' => $sourceId]);
                    $vecText = $this->docNeedsVector->fetchColumn();
                }
                if ($vecText !== null && $vecText !== false && trim((string)$vecText) !== '') {
                    if (!$this->dryrun) {
                        $dvecVal = $this->docEmbed->embedText((string)$vecText, 'passage: ', DocEmbeddingClient::MODEL_LARGE);
                        if (!is_array($dvecVal) || count($dvecVal) !== 1024) {
                            throw new \RuntimeException("invalid 1024-dim document vector for source {$sourceId}");
                        }
                        $this->docVectorUpdate->execute([':sid' => $sourceId, ':e' => self::vecLiteral($dvecVal)]);
                    }
                    $dvec++;
                }
            }

            // --- 4. Grammar patterns (delta scan; rows visible in this tx) ---
            // pgLaana runs with throwOnError enabled (constructor), so a failed
            // scan statement (deadlock, timeout) throws here and forces the
            // rollback path instead of aborting the tx silently.
            $out['patterns'] = $this->scanGrammarPatterns($sourceId);

            if ($this->dryrun) {
                $this->pg->rollBack();
            } else {
                $this->pg->commit();
            }

            $out['sentences_data']   = $sentenceDataCount;
            $out['sentence_vectors'] = $svec;
            $out['sentence_metrics'] = $smet;
            $out['document_metrics'] = $dmet;
            $out['document_vectors'] = $dvec;
            $out['has_content']      = (bool)$content;
            $out['skipped_data']     = $skipData;

            return $out;
        } catch (\Throwable $e) {
            if ($this->pg->inTransaction()) {
                $this->pg->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delta-scan sentence_patterns for one source. Runs INSIDE the open tx.
     * Returns the number of delta-selected sentences processed (those of the
     * source with no pattern rows yet); zero-match sentences legitimately
     * get no rows (GrammarScanner::savePatterns writes nothing for them).
     */
    public function scanGrammarPatterns(int $sourceId): int
    {
        $scanner = new \Noiiolelo\GrammarScanner($this->pgLaana);
        return $scanner->updateSourcePatterns($sourceId, false);
    }

    /**
     * The shared Postgres connection (test support: lets integration tests
     * inspect pipeline-written state on the same connection).
     */
    public function pg(): \PDO
    {
        return $this->pg;
    }

    /**
     * Refresh the counts materialized view. MUST be called outside a tx.
     */
    public function refreshGrammarPatternCounts(): bool
    {
        return $this->pgLaana->refreshGrammarPatternCounts();
    }

    /** Format a numeric vector as a pgvector literal. */
    public static function vecLiteral(array $vec): string
    {
        return '[' . implode(',', array_map(
            static function ($v) { return is_int($v) ? (string)$v : (string)(float)$v; },
            $vec
        )) . ']';
    }

    /**
     * Sum of sentences the incremental path would add: every source whose
     * signature mismatches or that is absent from Postgres contributes its
     * full MySQL sentence count (matches countSentencesToAdd()'s fast path).
     * Run drivers use this to decide whether an incremental load is big
     * enough to be worth dropping the derivative search indexes. Loads the
     * signature maps.
     */
    public function pendingSentenceMigration(): int
    {
        $this->loadSignatureMaps();
        $pending = 0;
        foreach ($this->mysqlSentenceSignatures as $sid => $sig) {
            if (!isset($this->pgSentenceSignatures[$sid])
                || $this->pgSentenceSignatures[$sid] !== $sig
            ) {
                $pending += $sig[0];
            }
        }
        return $pending;
    }

    /**
     * True when this source's sentence rows are already mirrored unchanged:
     * the same per-source (row count, sentenceid checksum) on both sides —
     * the same signature countSentencesToAdd() uses in pg_import --status —
     * plus a contents row present in Postgres. Step 1's upserts would then
     * only rewrite identical rows. Only the data upserts are skipped; the
     * delta scans (sentence gaps, doc gaps, grammar patterns) still run.
     */
    private function dataAlreadyMirrored(int $sourceId): bool
    {
        $this->loadSignatureMaps();
        if (!isset($this->mysqlSentenceSignatures[$sourceId], $this->pgSentenceSignatures[$sourceId])) {
            return false;
        }
        if ($this->mysqlSentenceSignatures[$sourceId] !== $this->pgSentenceSignatures[$sourceId]) {
            return false;
        }
        return isset($this->pgContentSourceIds[$sourceId]);
    }

    /**
     * Per-source sentence signatures on both sides plus the Postgres
     * contents sourceid set, built once per process. Staleness is safe:
     * a source mirrored after the snapshot looks unmirrored and takes the
     * full path (identical-row upserts), never the reverse.
     */
    private function loadSignatureMaps(): void
    {
        if ($this->pgSentenceSignatures !== null) {
            return;
        }

        $this->pgSentenceSignatures = [];
        foreach ($this->pg->query(
            'SELECT sourceid, COUNT(*) AS n, SUM(sentenceid) AS chk FROM sentences GROUP BY sourceid'
        ) as $row) {
            $this->pgSentenceSignatures[(int)$row['sourceid']] = [(int)$row['n'], (int)$row['chk']];
        }

        $this->mysqlSentenceSignatures = [];
        foreach ($this->mysql->query(
            'SELECT sourceID AS sourceid, COUNT(*) AS n, SUM(sentenceID) AS chk FROM sentences GROUP BY sourceID'
        ) as $row) {
            $this->mysqlSentenceSignatures[(int)$row['sourceid']] = [(int)$row['n'], (int)$row['chk']];
        }

        $this->pgContentSourceIds = [];
        foreach ($this->pg->query('SELECT sourceid FROM contents') as $row) {
            $this->pgContentSourceIds[(int)$row['sourceid']] = true;
        }
    }

    /**
     * Stage sentence vectors for the current source's transaction: chunked
     * multi-row INSERTs into the session temp table staging_sent384; the
     * caller then updates sentences from it in ONE statement.
     *
     * @param array<int, string> $literals sentenceid => pgvector literal
     */
    private function insertStagingVectors(array $literals): void
    {
        $chunk = [];
        foreach ($literals as $sentenceId => $literal) {
            $chunk[$sentenceId] = $literal;
            if (count($chunk) >= self::WRITE_BATCH_SIZE) {
                $this->flushStagingChunk($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->flushStagingChunk($chunk);
        }
    }

    /**
     * @param array<int, string> $literals sentenceid => pgvector literal
     */
    private function flushStagingChunk(array $literals): void
    {
        $values = [];
        $params = [];
        $i = 0;
        foreach ($literals as $sentenceId => $literal) {
            $values[] = "(:s{$i}, (:e{$i})::vector(384))";
            $params[":s{$i}"] = $sentenceId;
            $params[":e{$i}"] = $literal;
            $i++;
        }
        $stmt = $this->pg->prepare(
            'INSERT INTO staging_sent384 (sentenceid, embedding) VALUES ' . implode(', ', $values)
        );
        $stmt->execute($params);
    }

    /**
     * Batched multi-row sentence_metrics upsert: one statement per
     * WRITE_BATCH_SIZE rows instead of one per sentence.
     *
     * @param array<int, array{sid:int, ratio:float, wc:int, len:int, ec:int, freq:float}> $rows
     */
    private function upsertSentenceMetricsBatch(array $rows): void
    {
        foreach (array_chunk($rows, self::WRITE_BATCH_SIZE) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i => $row) {
                $values[] = "(:sid{$i}, :ratio{$i}, :wc{$i}, :len{$i}, :ec{$i}, :freq{$i}, CURRENT_TIMESTAMP)";
                foreach (['sid', 'ratio', 'wc', 'len', 'ec', 'freq'] as $key) {
                    $params[":{$key}{$i}"] = $row[$key];
                }
            }
            $stmt = $this->pg->prepare(
                'INSERT INTO sentence_metrics (sentenceid, hawaiian_word_ratio, word_count, length, entity_count, frequency, updated_at) '
                . 'VALUES ' . implode(', ', $values) . ' '
                . 'ON CONFLICT (sentenceid) DO UPDATE SET '
                . 'hawaiian_word_ratio = EXCLUDED.hawaiian_word_ratio, word_count = EXCLUDED.word_count, '
                . 'length = EXCLUDED.length, entity_count = EXCLUDED.entity_count, '
                . 'frequency = EXCLUDED.frequency, updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute($params);
        }
    }

    // -------------------------------------------------------------------------
    // Env + connections (moved verbatim from scripts/pg_import.php)
    // -------------------------------------------------------------------------

    private static function envValue(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $default;
        }
        return (string)$value;
    }

    private static function connectMySql(): \PDO
    {
        // Fail loudly when the MySQL read-side connection config is missing
        // instead of silently connecting to localhost with empty credentials.
        $host   = \Noiiolelo\EnvConfig::firstEnv('MySQL host', ['DB_HOST']);
        $port   = \Noiiolelo\EnvConfig::firstEnv('MySQL port', ['DB_PORT']);
        $db     = \Noiiolelo\EnvConfig::firstEnv('MySQL database', ['DB_DATABASE']);
        $user   = \Noiiolelo\EnvConfig::firstEnv('MySQL user', ['DB_USER']);
        $pass   = \Noiiolelo\EnvConfig::firstEnv('MySQL password', ['DB_PASSWORD']);
        $socket = self::envValue('DB_SOCKET');

        if ($socket !== '') {
            $socket = trim($socket, "\"'");
        }

        $config = [
            'host'     => $host,
            'port'     => $port,
            'dbname'   => $db,
            'username' => $user,
            'password' => $pass,
        ];
        if ($socket !== '' && file_exists($socket)) {
            $config['socket'] = $socket;
        }

        return \Common\DB\DBBase::createConnection($config);
    }

    private function prepareStatements(): void
    {
        // MySQL reads
        $this->sourceByIdStmt = $this->mysql->prepare(
            'SELECT sourceID AS sourceid, sourceName AS sourcename, authors, link, created, groupname, title, date '
            . 'FROM sources WHERE sourceID = :sourceid'
        );
        $this->contentStmt = $this->mysql->prepare(
            'SELECT sourceID AS sourceid, html, text, created FROM contents WHERE sourceID = :sourceid'
        );
        $this->sentenceStmt = $this->mysql->prepare(
            'SELECT sentenceID AS sentenceid, sourceID AS sourceid, hawaiianText AS hawaiiantext, '
            . 'englishText AS englishtext, created '
            . 'FROM sentences WHERE sourceID = :sourceid ORDER BY sentenceID'
        );

        // Postgres upserts (parents/data)
        $this->sourceUpsert = $this->pg->prepare(
            'INSERT INTO sources (sourceid, sourcename, authors, link, created, groupname, title, date) '
            . 'VALUES (:sourceid, :sourcename, :authors, :link, :created, :groupname, :title, :date) '
            . 'ON CONFLICT (sourceid) DO UPDATE SET '
            . 'sourcename = EXCLUDED.sourcename, authors = EXCLUDED.authors, link = EXCLUDED.link, '
            . 'created = EXCLUDED.created, groupname = EXCLUDED.groupname, title = EXCLUDED.title, date = EXCLUDED.date'
        );
        $this->contentUpsert = $this->pg->prepare(
            'INSERT INTO contents (sourceid, html, text, created) '
            . 'VALUES (:sourceid, :html, :text, :created) '
            . 'ON CONFLICT (sourceid) DO UPDATE SET '
            . 'html = EXCLUDED.html, text = EXCLUDED.text, created = EXCLUDED.created'
        );
        $this->sentenceUpsert = $this->pg->prepare(
            'INSERT INTO sentences (sentenceid, sourceid, hawaiiantext, englishtext, created) '
            . 'VALUES (:sentenceid, :sourceid, :hawaiiantext, :englishtext, :created) '
            . 'ON CONFLICT (sentenceid) DO UPDATE SET '
            . 'sourceid = EXCLUDED.sourceid, hawaiiantext = EXCLUDED.hawaiiantext, '
            . 'englishtext = EXCLUDED.englishtext, created = EXCLUDED.created'
        );

        // Postgres check statements (for incremental backfill)
        $this->sentenceNeedsWork = $this->pg->prepare(
            'SELECT s.sentenceid, s.hawaiiantext '
            . 'FROM sentences s LEFT JOIN sentence_metrics m ON m.sentenceid = s.sentenceid '
            . 'WHERE s.sourceid = :sourceid '
            . '  AND s.hawaiiantext IS NOT NULL AND octet_length(s.hawaiiantext) > 0 '
            . '  AND (s.embedding IS NULL OR m.sentenceid IS NULL) '
            . 'ORDER BY s.sentenceid'
        );
        $this->allSentencesForSource = $this->pg->prepare(
            'SELECT sentenceid, hawaiiantext FROM sentences '
            . 'WHERE sourceid = :sourceid AND hawaiiantext IS NOT NULL AND octet_length(hawaiiantext) > 0 '
            . 'ORDER BY sentenceid'
        );
        $this->docNeedsMetrics = $this->pg->prepare(
            'SELECT 1 FROM contents c LEFT JOIN document_metrics m ON m.sourceid = c.sourceid '
            . 'WHERE c.sourceid = :sourceid '
            . '  AND c.text IS NOT NULL AND octet_length(c.text) > 0 '
            . '  AND (m.sourceid IS NULL OR m.entity_count < 0) '
            . 'LIMIT 1'
        );
        $this->docNeedsVector = $this->pg->prepare(
            'SELECT text FROM contents '
            . 'WHERE sourceid = :sourceid AND embedding_1024 IS NULL '
            . '  AND text IS NOT NULL AND text <> \'\' '
            . 'LIMIT 1'
        );
        $this->docTextForSource = $this->pg->prepare(
            'SELECT text FROM contents WHERE sourceid = :sourceid AND text IS NOT NULL AND octet_length(text) > 0'
        );

        // Document metrics + 1024-dim document vector.
        $this->documentMetricsUpsert = $this->pg->prepare(
            'INSERT INTO document_metrics (sourceid, hawaiian_word_ratio, word_count, length, entity_count, updated_at) '
            . 'VALUES (:sid, :ratio, :wc, :len, :ec, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT (sourceid) DO UPDATE SET '
            . 'hawaiian_word_ratio = EXCLUDED.hawaiian_word_ratio, word_count = EXCLUDED.word_count, '
            . 'length = EXCLUDED.length, entity_count = EXCLUDED.entity_count, updated_at = CURRENT_TIMESTAMP'
        );
        $this->docVectorUpdate = $this->pg->prepare(
            'UPDATE contents SET embedding_1024 = (:e)::vector(1024) WHERE sourceid = :sid'
        );
    }
}

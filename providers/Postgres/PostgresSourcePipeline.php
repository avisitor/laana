<?php
declare(strict_types=1);

namespace Noiiolelo\Providers\Postgres;

require_once __DIR__ . '/PostgresClient.php';

use HawaiianSearch\EmbeddingClient as DocEmbeddingClient; // document vectors (1024-dim, MODEL_LARGE)
use Noiiolelo\EmbeddingClient;                            // sentence embeddings (384-dim, small model)

/**
 * Per-source Postgres pipeline: MySQL -> laana schema (data, vectors,
 * metrics). The grammar-pattern scan is a stub until Task 2; the counts
 * materialized view is refreshed by the run driver, once per run and
 * outside any transaction.
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

    // Sentence embedding write (384-dim) via staging, scoped to current tx.
    private \PDOStatement $sentenceMetricsUpsert;
    private \PDOStatement $documentMetricsUpsert;
    private \PDOStatement $docVectorUpdate;

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
            'patterns'         => 0, // TODO(Task 2): real count once the grammar scan runs pre-commit.
            'has_content'      => false,
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
            // --- 1. Migrate parent rows (always) ---
            $this->sourceUpsert->execute($source);

            $this->contentStmt->execute([':sourceid' => $sourceId]);
            $content = $this->contentStmt->fetch(\PDO::FETCH_ASSOC);
            if ($content) {
                $this->contentUpsert->execute($content);
            }

            $this->sentenceStmt->execute([':sourceid' => $sourceId]);
            $sentenceDataCount = 0;
            while ($row = $this->sentenceStmt->fetch(\PDO::FETCH_ASSOC)) {
                $this->sentenceUpsert->execute($row);
                $sentenceDataCount++;
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

                    foreach ($work as $i => $w) {
                        $sentenceId = (int)$w['sentenceid'];
                        $text = (string)$w['hawaiiantext'];

                        if (!$this->dryrun) {
                            $vec = $vecs[$i] ?? null;
                            if (!is_array($vec) || count($vec) !== 384) {
                                throw new \RuntimeException("invalid 384-dim vector for sentence {$sentenceId}");
                            }
                            $this->pg->exec(
                                'CREATE TEMP TABLE IF NOT EXISTS staging_sent384 (sentenceid bigint, embedding vector(384)) ON COMMIT DROP'
                            );
                            $stg = $this->pg->prepare('INSERT INTO staging_sent384 VALUES (:sid, (:e)::vector(384))');
                            $stg->execute([':sid' => $sentenceId, ':e' => self::vecLiteral($vec)]);
                            $this->pg->exec(
                                'UPDATE sentences s SET embedding = st.embedding '
                                . 'FROM staging_sent384 st WHERE s.sentenceid = st.sentenceid'
                            );
                            $this->pg->exec('DELETE FROM staging_sent384');
                            $svec++;
                        }

                        $m = $this->metrics->computeSentenceMetrics($text);
                        if (!$this->dryrun) {
                            $this->sentenceMetricsUpsert->execute([
                                ':sid'   => $sentenceId,
                                ':ratio' => (float)($m['hawaiian_word_ratio'] ?? 0),
                                ':wc'    => (int)($m['word_count'] ?? 0),
                                ':len'   => (int)($m['length'] ?? 0),
                                ':ec'    => (int)($m['entity_count'] ?? 0),
                                ':freq'  => (float)($m['frequency'] ?? 0),
                            ]);
                        }
                        $smet++;
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

            return $out;
        } catch (\Throwable $e) {
            if ($this->pg->inTransaction()) {
                $this->pg->rollBack();
            }
            throw $e;
        }
    }

    /**
     * TODO(Task 2): delta-scan sentence_patterns for one source, INSIDE the
     * open tx after the document-vector step and before commit. Stub until
     * then: always returns 0.
     */
    public function scanGrammarPatterns(int $sourceId): int
    {
        // TODO(Task 2): $scanner = new \Noiiolelo\GrammarScanner($this->pgLaana);
        //               return $scanner->updateSourcePatterns($sourceId, false);
        return 0;
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
        $host   = self::envValue('DB_HOST', 'localhost');
        $port   = self::envValue('DB_PORT', '3306');
        $db     = self::envValue('DB_DATABASE');
        $user   = self::envValue('DB_USER');
        $pass   = self::envValue('DB_PASSWORD');
        $socket = self::envValue('DB_SOCKET');

        if ($socket !== '') {
            $socket = trim($socket, "\"'");
        }
        if ($db === '') {
            throw new \RuntimeException('DB_DATABASE is not set.');
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

        // Sentence embedding write (384-dim) via staging, scoped to current tx.
        $this->sentenceMetricsUpsert = $this->pg->prepare(
            'INSERT INTO sentence_metrics (sentenceid, hawaiian_word_ratio, word_count, length, entity_count, frequency, updated_at) '
            . 'VALUES (:sid, :ratio, :wc, :len, :ec, :freq, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT (sentenceid) DO UPDATE SET '
            . 'hawaiian_word_ratio = EXCLUDED.hawaiian_word_ratio, word_count = EXCLUDED.word_count, '
            . 'length = EXCLUDED.length, entity_count = EXCLUDED.entity_count, '
            . 'frequency = EXCLUDED.frequency, updated_at = CURRENT_TIMESTAMP'
        );
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

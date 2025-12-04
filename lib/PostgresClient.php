<?php

namespace HawaiianSearch;

use PDO;
use PDOException;

require_once __DIR__ . '/../db/PostgresFuncs.php';
require_once __DIR__ . '/EmbeddingClient.php';

class PostgresClient extends \PostgresLaana
{
    private array $config;
    private EmbeddingClient $embeddingClient;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        parent::__construct();

        // Load endpoint from .env if present
        $env = function_exists('loadEnv') ? loadEnv(__DIR__ . '/../.env') : [];
        $endpoint = $config['EMBEDDING_SERVICE_URL']
            ?? ($env['EMBEDDING_SERVICE_URL'] ?? null)
            ?? getenv('EMBEDDING_SERVICE_URL')
            ?? ($config['EMBEDDING_ENDPOINT'] ?? $env['EMBEDDING_ENDPOINT'] ?? getenv('EMBEDDING_ENDPOINT') ?? 'http://localhost:5000');
        $this->embeddingClient = new EmbeddingClient($endpoint);
    }

    public function countTotalSentences(): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM sentences WHERE hawaiianText IS NOT NULL AND octet_length(hawaiianText) > 0';
        $stmt = $this->conn->query($sql);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }

    public function countMissingEmbeddings(): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM sentences s LEFT JOIN sentence_metrics m ON m.sentenceid = s.sentenceid WHERE s.hawaiianText IS NOT NULL AND octet_length(s.hawaiianText) > 0 AND (s.embedding IS NULL OR m.sentenceid IS NULL)';
        $stmt = $this->conn->query($sql);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }

    public function fetchCandidateSentenceIds(int $limit = 0): array
    {
        $sql = 'SELECT s.sentenceid
                FROM sentences s
                LEFT JOIN sentence_metrics m ON m.sentenceid = s.sentenceid
                WHERE s.hawaiianText IS NOT NULL AND octet_length(s.hawaiianText) > 0
                  AND (s.embedding IS NULL OR m.sentenceid IS NULL)
                ORDER BY s.sentenceid';
        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
        }
        $stmt = $this->conn->prepare($sql);
        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_map(function($r){ return (int)$r['sentenceid']; }, $rows);
    }

    public function getEmbeddingClient(): EmbeddingClient
    {
        return $this->embeddingClient;
    }

    public function fetchSources(int $offset, int $limit): array
    {
        $sql = 'SELECT sourceID as sourceid, sourceName as sourcename, groupname, authors, date, link, title
                FROM sources
                ORDER BY sourceID
                OFFSET :offset LIMIT :limit';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function fetchPlainText(string $sourceid): ?string
    {
        // Prefer contents.text for document-level text
        $sql = 'SELECT text FROM contents WHERE sourceID = :sid';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':sid', $sourceid);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['text'])) return $row['text'];
        // Fallback: concatenate sentences if contents.text is missing
        $sql2 = 'SELECT hawaiianText FROM sentences WHERE sourceID = :sid';
        $stmt2 = $this->conn->prepare($sql2);
        $stmt2->bindValue(':sid', $sourceid);
        $stmt2->execute();
        $rows2 = $stmt2->fetchAll();
        if (!$rows2) return null;
        $parts = [];
        foreach ($rows2 as $r2) {
            $t = $r2['hawaiianText'] ?? '';
            if ($t !== '') $parts[] = $t;
        }
        return count($parts) ? implode(' ', $parts) : null;
    }

    public function bulkUpdateSentenceEmbeddings(array $embeddings): int
    {
        if (empty($embeddings)) return 0;
        $this->conn->exec('CREATE TEMP TABLE IF NOT EXISTS staging_embeddings (sentenceid bigint, embedding vector(384)) ON COMMIT DROP');
        $ins = $this->conn->prepare('INSERT INTO staging_embeddings(sentenceid, embedding) VALUES (:sid, (:emb)::vector(384))');
        foreach ($embeddings as $sid => $vec) {
            $ins->bindValue(':sid', (int)$sid, PDO::PARAM_INT);
            $ins->bindValue(':emb', '[' . implode(',', array_map(function($v){ return is_int($v) ? (string)$v : (string)(float)$v; }, $vec)) . ']');
            $ins->execute();
        }
        $updated = $this->conn->exec('UPDATE sentences s SET embedding = st.embedding FROM staging_embeddings st WHERE s.sentenceid = st.sentenceid');
        return (int)$updated;
    }

    public function upsertSentenceMetrics(array $metricsRows): int
    {
        if (empty($metricsRows)) return 0;
                $sql = 'INSERT INTO sentence_metrics (sentenceid, hawaiian_word_ratio, word_count, length, entity_count, frequency, updated_at)
                                VALUES (:sid, :ratio, :wc, :len, :ec, :freq, CURRENT_TIMESTAMP)
                ON CONFLICT (sentenceid) DO UPDATE SET
                  hawaiian_word_ratio = EXCLUDED.hawaiian_word_ratio,
                                    word_count = EXCLUDED.word_count,
                                    length = EXCLUDED.length,
                                    entity_count = EXCLUDED.entity_count,
                                    frequency = EXCLUDED.frequency,
                                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->conn->prepare($sql);
        $count = 0;
        foreach ($metricsRows as $m) {
            $stmt->bindValue(':sid', (int)$m['sentenceid'], PDO::PARAM_INT);
            $stmt->bindValue(':ratio', (float)($m['hawaiian_word_ratio'] ?? 0));
                        $stmt->bindValue(':wc', (int)($m['word_count'] ?? 0), PDO::PARAM_INT);
                        $stmt->bindValue(':len', (int)($m['length'] ?? 0), PDO::PARAM_INT);
                        $stmt->bindValue(':ec', (int)($m['entity_count'] ?? 0), PDO::PARAM_INT);
                        $stmt->bindValue(':freq', (float)($m['frequency'] ?? 0));
            $stmt->execute();
            $count++;
        }
        return $count;
    }

    /**
     * Resolve sentenceid by document id and position.
     * Assumes sentences table stores doc/source id and position per sentence.
     */
    public function getSentenceIdByDocAndPosition(string $docId, int $position): ?int
    {
        // Try by sourceID + position first
        $sql = 'SELECT sentenceid FROM sentences WHERE sourceID = :sid AND position = :pos';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':sid', $docId);
        $stmt->bindValue(':pos', $position, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && isset($row['sentenceid'])) {
            return (int)$row['sentenceid'];
        }
        // Fallback: if column names differ, attempt using doc_id
        $sql2 = 'SELECT sentenceid FROM sentences WHERE doc_id = :sid AND position = :pos';
        $stmt2 = $this->conn->prepare($sql2);
        $stmt2->bindValue(':sid', $docId);
        $stmt2->bindValue(':pos', $position, PDO::PARAM_INT);
        $stmt2->execute();
        $row2 = $stmt2->fetch();
        if ($row2 && isset($row2['sentenceid'])) {
            return (int)$row2['sentenceid'];
        }
        return null;
    }

    /**
     * Resolve sentenceid by document/source id and exact sentence text.
     */
    public function getSentenceIdByDocAndText(string $docId, string $text): ?int
    {
        $sql = 'SELECT sentenceid FROM sentences WHERE sourceID = :sid AND hawaiianText = :txt LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':sid', $docId);
        $stmt->bindValue(':txt', $text);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && isset($row['sentenceid'])) {
            return (int)$row['sentenceid'];
        }
        return null;
    }
}

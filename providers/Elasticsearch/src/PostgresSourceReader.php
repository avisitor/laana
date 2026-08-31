<?php

namespace HawaiianSearch;

class PostgresSourceReader
{
    /** @var object{conn: \PDO} */
    private $pg;

    /**
     * @param object{conn: \PDO} $pg PostgresLaana or PostgresClient instance (public $conn PDO)
     */
    public function __construct(object $pg)
    {
        $this->pg = $pg;
    }

    /**
     * Parse a Postgres pgvector text representation into a PHP float array.
     *
     * Handles the format: '[0.1, 0.2, 0.3]'
     *
     * @param string|null $pg Raw vector string from Postgres
     * @return array<int, float> Parsed float values
     */
    public static function pgvectorToArray(string|null $pg): array
    {
        if ($pg === null || $pg === '' || $pg === '[]') {
            return [];
        }

        // Strip surrounding brackets
        $inner = trim($pg, '[]');

        if ($inner === '') {
            return [];
        }

        $parts = explode(',', $inner);
        $result = [];

        foreach ($parts as $part) {
            $result[] = (float) trim($part);
        }

        return $result;
    }

    /**
     * Read a source's document, sentences, vectors, and metrics from Postgres.
     *
     * @param int $sourceId Source ID to look up
     * @return array|null Normalized structure or null if no contents row exists
     */
    public function readSource(int $sourceId): ?array
    {
        // PDOExceptions propagate; CorpusIndexer catches per source.
        $conn = $this->pg->conn;

        // Query 1: source + content + document metrics
        $docSql = <<<'SQL'
SELECT s.sourceid, s.sourcename, s.groupname, s.authors, s.date, s.link, s.title,
       c.text, c.html, c.embedding_1024,
       dm.hawaiian_word_ratio AS doc_ratio
FROM sources s
JOIN contents c ON c.sourceid = s.sourceid
LEFT JOIN document_metrics dm ON dm.sourceid = c.sourceid
WHERE s.sourceid = :sid
SQL;

        $docStmt = $conn->prepare($docSql);
        $docStmt->execute([':sid' => $sourceId]);
        $docRow = $docStmt->fetch(\PDO::FETCH_ASSOC);

        if ($docRow === false) {
            return null;
        }

        // Query 2: sentences + sentence metrics
        $sentSql = <<<'SQL'
SELECT st.sentenceid, st.hawaiiantext, st.embedding,
       sm.hawaiian_word_ratio AS sent_ratio, sm.word_count,
       sm.entity_count, sm.frequency, sm.length
FROM sentences st
LEFT JOIN sentence_metrics sm ON sm.sentenceid = st.sentenceid
WHERE st.sourceid = :sid
ORDER BY st.sentenceid
SQL;

        $sentStmt = $conn->prepare($sentSql);
        $sentStmt->execute([':sid' => $sourceId]);
        $sentRows = $sentStmt->fetchAll(\PDO::FETCH_ASSOC);

        usort($sentRows, fn(array $a, array $b): int => $a['sentenceid'] <=> $b['sentenceid']);

        $sentences = [];
        foreach ($sentRows as $idx => $row) {
            $sentences[] = [
                'text' => $row['hawaiiantext'],
                'vector' => $row['embedding'] !== null ? self::pgvectorToArray($row['embedding']) : null,
                'position' => $idx,
                'hawaiian_word_ratio' => $row['sent_ratio'] !== null ? (float) $row['sent_ratio'] : null,
                'word_count' => $row['word_count'] !== null ? (int) $row['word_count'] : null,
                'entity_count' => $row['entity_count'] !== null ? (int) $row['entity_count'] : null,
                'frequency' => $row['frequency'] !== null ? (int) $row['frequency'] : null,
                'length' => $row['length'] !== null ? (int) $row['length'] : null,
            ];
        }

        return [
            'sourceid' => (int) $docRow['sourceid'],
            'sourcename' => $docRow['sourcename'],
            'groupname' => $docRow['groupname'],
            'authors' => $docRow['authors'],
            'date' => $docRow['date'],
            'link' => $docRow['link'],
            'title' => $docRow['title'],
            'text' => $docRow['text'],
            'html' => $docRow['html'],
            'text_vector_1024' => $docRow['embedding_1024'] !== null
                ? self::pgvectorToArray($docRow['embedding_1024'])
                : null,
            'hawaiian_word_ratio' => $docRow['doc_ratio'] !== null
                ? (float) $docRow['doc_ratio']
                : null,
            'sentences' => $sentences,
        ];
    }

    /**
     * Fetch raw HTML for a source from laana.contents (for --import-raw).
     *
     * @param int $sourceId Source ID to look up
     * @return string|null Raw HTML, or null when the row/HTML is missing or empty
     */
    public function fetchRaw(int $sourceId): ?string
    {
        $conn = $this->pg->conn;

        $sql = 'SELECT html FROM contents WHERE sourceid = :sid';
        $stmt = $conn->prepare($sql);
        $stmt->execute([':sid' => $sourceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || !isset($row['html']) || $row['html'] === null || $row['html'] === '') {
            return null;
        }

        return (string) $row['html'];
    }
}

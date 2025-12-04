<?php

namespace HawaiianSearch;

use PDO;

class PostgresSentenceIterator
{
    private PostgresClient $client;
    private int $lastId = 0;
    private int $batchSize;

    public function __construct(PostgresClient $client, int $batchSize = 100)
    {
        $this->client = $client;
        $this->batchSize = $batchSize;
    }

        public function getNext(): ?array
        {
                $sql = 'SELECT s.sentenceid, s.sourceID, s.hawaiianText
                                FROM sentences s
                                LEFT JOIN sentence_metrics m ON m.sentenceid = s.sentenceid
                                WHERE s.hawaiianText IS NOT NULL AND octet_length(s.hawaiianText) > 0
                                    AND (s.embedding IS NULL OR m.sentenceid IS NULL)
                                    AND s.sentenceid > :last_id
                                ORDER BY s.sentenceid
                                LIMIT :limit';
                $stmt = $this->client->conn->prepare($sql);
                $stmt->bindValue(':last_id', $this->lastId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) return null;
                $this->lastId = (int)end($rows)['sentenceid'];
                return $rows;
        }
}

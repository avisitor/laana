<?php

namespace Noiiolelo\Providers\Postgres;

use PDO;

class PostgresDocumentIterator
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
        $sql = 'SELECT c.sourceID, c.html, c.text
                FROM contents c
                LEFT JOIN document_metrics m ON m.sourceid = c.sourceID
                WHERE c.text IS NOT NULL AND octet_length(c.text) > 0
                    AND (c.embedding IS NULL OR m.sourceid IS NULL OR m.entity_count < 0)
                    AND c.sourceID > :last_id
                ORDER BY c.sourceID
                LIMIT :limit';
        $stmt = $this->client->conn->prepare($sql);
        $stmt->bindValue(':last_id', $this->lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return null;
        $this->lastId = (int)end($rows)['sourceid'];
        return $rows;
    }
}

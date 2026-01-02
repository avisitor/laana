<?php

namespace Noiiolelo\Providers\Postgres;

class PostgresSourceIterator
{
    private PostgresClient $client;
    private int $cursor = 0;
    private int $batchSize;
    private int $estimatedSize;

    public function __construct(PostgresClient $client, int $batchSize = 50)
    {
        $this->client = $client;
        $this->batchSize = $batchSize;
        $this->estimatedSize = 0;
    }

    public function getSize(): int
    {
        return $this->estimatedSize;
    }

    public function getNext(): ?array
    {
        $rows = $this->client->fetchSources($this->cursor, $this->batchSize);
        if (empty($rows)) {
            return null;
        }
        $this->cursor += count($rows);
        return $rows;
    }
}

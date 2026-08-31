<?php

namespace HawaiianSearch;

require_once __DIR__ . '/../../../db/PostgresFuncs.php';

class PostgresSourceIterator implements SourceProviderInterface
{
    private $client;
    private $sources = [];
    private $position = 0;

    public function __construct(?int $sourceId = null, ?string $groupName = null, $client = null)
    {
        if ($client === null) {
            $client = new \PostgresLaana();
        }
        $this->client = $client;
        $this->fetchSources($sourceId, $groupName);
    }

    public function getCapabilities(): SourceCapabilities
    {
        $caps = new SourceCapabilities();
        $caps->sentenceVectors = true;
        $caps->documentVector384 = true;
        $caps->documentVector1024 = true;
        $caps->rawHtml = true;
        return $caps;
    }

    public function getSize(): int
    {
        return count($this->sources);
    }

    public function getNext(): ?array
    {
        if ($this->position >= count($this->sources)) {
            return null;
        }

        return [$this->sources[$this->position++]];
    }

    private function fetchSources(?int $sourceId, ?string $groupName): void
    {
        $sql = 'SELECT sourceID as sourceid, sourceName as sourcename, groupname, authors, date, link, title FROM sources';
        $params = [];

        if ($sourceId !== null) {
            $sql .= ' WHERE sourceID = :sourceid';
            $params[':sourceid'] = $sourceId;
        } elseif ($groupName !== null) {
            $sql .= ' WHERE groupname = :groupname';
            $params[':groupname'] = $groupName;
        }

        $sql .= ' ORDER BY sourceID';

        try {
            $stmt = $this->client->conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $this->sources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Degrade to empty like SourceIterator; CorpusIndexer skips empty batches.
            $this->sources = [];
        }
    }
}

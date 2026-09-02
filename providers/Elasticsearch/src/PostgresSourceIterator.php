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
        // runIndexing passes 0 to mean "no filter" (SourceIterator convention).
        $this->fetchSources($sourceId ?: null, $groupName);
    }

    public function getCapabilities(): SourceCapabilities
    {
        $caps = new SourceCapabilities();
        $caps->sentenceVectors = true;
        // The legacy 384-dim document vector (contents.embedding) is no longer
        // populated or consulted; the authoritative document vector is the
        // 1024-dim contents.embedding_1024 column.
        $caps->documentVector384 = false;
        $caps->documentVector1024 = true;
        $caps->rawHtml = true;
        // laana.sentence_metrics has no boilerplate_score column today, so the
        // indexer computes it via MetadataExtractor. If the column is added,
        // select it in PostgresSourceReader::readSource(), surface it in the
        // sentence rows, and flip this to true to disable the fill-in.
        $caps->sentenceBoilerplateScore = false;
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

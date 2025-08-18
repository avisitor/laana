<?php

namespace NoiOlelo;

interface SearchProviderInterface
{
    /**
     * Performs a search query.
     * @return array ['hits' => [], 'total' => 0]
     */
    public function search(string $query, string $mode, int $limit, int $offset): array;

    /**
     * Retrieves a specific document's content.
     * @return array|null Document data or null if not found.
     */
    public function getDocument(string $docId, string $format = 'text'): ?array;

    /**
     * Retrieves a list of all sources and their metadata.
     * @return array
     */
    public function getSourceMetadata(): array;

    /**
     * Retrieves statistics about the corpus.
     * @return array
     */
    public function getCorpusStats(): array;

    /**
     * Logs a search query for statistical purposes.
     */
    public function logQuery(array $params): void;

    /**
     * Retrieves a list of available search modes.
     * @return array
     */
    public function getAvailableSearchModes(): array;
}

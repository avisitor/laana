<?php

namespace Noiiolelo;

interface SearchProviderInterface
{
    public function getName(): string;
    
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

    public function getGrammarPatterns(): array;

    public function providesHighlights(): bool;

    public function providesNoDiacritics(): bool;

    public function formatLogMessage( $msg, $intro = "" );
    
    public function debuglog( $msg, $intro = "" );

    public function normalizeString( $term );

    public function normalizeMode( $mode );

    public function checkStripped( $hawaiianText );

    public function processText( $hawaiianText );

    public function getRandomWord();

    public function getSourceGroupCounts();
    
    public function getSource( $sourceid );
    
    public function getText( $sourceid );
    
    public function getRawText( $sourceid );
    
    public function getSentencesBySourceID( $sourceid );
    
    /**
     * Add a search statistic entry
     */
    public function addSearchStat( string $searchterm, string $pattern, int $results, string $order, float $elapsed ): bool;
    
    /**
     * Get all search statistics ordered by creation date
     */
    public function getSearchStats(): array;
    
    /**
     * Get summary of search statistics grouped by pattern
     */
    public function getSummarySearchStats(): array;
    
    /**
     * Get the timestamp of the first search
     */
    public function getFirstSearchTime(): string;
}

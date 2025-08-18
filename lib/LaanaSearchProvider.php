<?php
namespace NoiOlelo;

require_once __DIR__ . '/../../noiiolelo/db/funcs.php';
require_once __DIR__ . '/SearchProviderInterface.php';

class LaanaSearchProvider implements SearchProviderInterface
{
    private \Laana $laana;
    public int $pageSize;

    public function __construct()
    {
        $this->laana = new \Laana();
        $this->pageSize = $this->laana->pageSize;
    }

    // Direct pass-through methods
    public function getLatestSourceDates() { return $this->laana->getLatestSourceDates(); }
    public function getSources($groupname = '') { return $this->laana->getSources($groupname); }
    public function getTotalSourceGroupCounts() { return $this->laana->getTotalSourceGroupCounts(); }
    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) { return $this->laana->getSentences($term, $pattern, $pageNumber, $options); }
    public function getRawText($sourceid) { return $this->laana->getRawText($sourceid); }
    public function getText($sourceid) { return $this->laana->getText($sourceid); }
    public function getSentence($sentenceid) { return $this->laana->getSentence($sentenceid); }
    public function addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ) { return $this->laana->addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ); }
    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) { return $this->laana->getMatchingSentenceCount( $term, $pattern, $pageNumber, $options ); }

    // Interface-required methods
    public function getSourceMetadata(): array { return $this->laana->getSources(); }
    public function getCorpusStats(): array {
        return [
            'sentence_count' => $this->laana->getSentenceCount(),
            'source_count' => $this->laana->getSourceCount()
        ];
    }
    public function search(string $query, string $mode, int $limit, int $offset): array {
        $pageNumber = floor($offset / $this->pageSize);
        $hits = $this->getSentences($query, $mode, $pageNumber, []);
        return ['hits' => $hits, 'total' => count($hits)];
    }
    public function getDocument(string $docId, string $format = 'text'): ?array {
        if ($format === 'html') {
            return ['content' => $this->getRawText($docId)];
        }
        return ['content' => $this->getText($docId)];
    }
    public function logQuery(array $params): void {
        $this->addSearchStat(
            $params['searchterm'],
            $params['pattern'],
            $params['results'],
            $params['sort'],
            $params['elapsed']
        );
    }

    public function getAvailableSearchModes(): array
    {
        return ['exact', 'any', 'all', 'regex'];
    }
}

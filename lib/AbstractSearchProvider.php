<?php

namespace Noiiolelo;

abstract class AbstractSearchProvider implements SearchProviderInterface
{
    public function search(string $query, string $mode, int $limit = 10, int $offset = 0): array
    {
        return ['hits' => [], 'total' => 0];
    }

    public function getDocument(string $docId, string $format = 'text'): ?array
    {
        return null;
    }

    public function getSources($groupname = '', $properties = [], $sortBy = '', $sortDir = 'asc')
    {
        return [];
    }

    public function getSourceMetadata(): array
    {
        return ['sources' => [], 'groups' => [], 'authors' => []];
    }

    public function getCorpusStats(): array
    {
        return [];
    }

    public function logQuery(array $params): void
    {
        $searchTerm = (string)($params['searchterm'] ?? '');
        $pattern = (string)($params['pattern'] ?? '');
        $results = (int)($params['results'] ?? 0);
        $order = (string)($params['sort'] ?? '');
        $elapsed = (float)($params['elapsed'] ?? 0);

        $this->addSearchStat(
            $searchTerm,
            $pattern,
            $results,
            $order,
            $elapsed
        );
    }

    public function getAvailableSearchModes(): array
    {
        return [
            'match' => 'Match any of the words',
            'matchall' => 'Match all words in any order',
            'phrase' => 'Match exact phrase',
            'regex' => 'Regular expression search',
            'hybrid' => 'Hybrid semantic search on sentences',
            'hybriddoc' => 'Hybrid semantic search on documents',
        ];
    }

    public function getGrammarPatterns($options = []): array
    {
        return [];
    }

    public function getGrammarMatches($pattern, $limit, $offset, $options = []): array
    {
        return [];
    }

    public function providesHighlights(): bool
    {
        return false;
    }

    public function providesNoDiacritics(): bool
    {
        return false;
    }

    public function formatLogMessage($msg, $intro = '')
    {
        if (is_object($msg) || is_array($msg)) {
            $msg = var_export($msg, true);
        }
        $defaultTimezone = 'Pacific/Honolulu';
        $now = new \DateTimeImmutable('now', new \DateTimeZone($defaultTimezone));
        $now = $now->format('Y-m-d H:i:s');
        $out = "$now " . ($_SERVER['SCRIPT_NAME'] ?? 'cli');
        if ($intro) {
            $out .= " $intro:";
        }
        return "$out $msg";
    }

    public function debuglog($msg, $intro = '')
    {
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            $msg = $this->formatLogMessage($msg, $intro);
            error_log("$msg\n");
        }
    }

    public function normalizeString($term)
    {
        $from = ['ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', "'", 'ʻ', '‘'];
        $to = ['o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', '', '', ''];
        return str_replace($from, $to, (string)$term);
    }

    public function normalizeMode($mode)
    {
        return $mode;
    }

    public function checkStripped($hawaiianText)
    {
        return true;
    }

    public function processText($hawaiianText)
    {
        return $hawaiianText;
    }

    public function getRandomWord()
    {
        return '';
    }

    public function getSourceGroupCounts()
    {
        return [];
    }

    public function getSource($sourceid)
    {
        return null;
    }

    public function getText($sourceid)
    {
        return null;
    }

    public function getRawText($sourceid)
    {
        return null;
    }

    public function getSentencesBySourceID($sourceid)
    {
        return [];
    }

    public function addSearchStat(string $searchterm, string $pattern, int $results, string $order, float $elapsed): bool
    {
        return false;
    }

    public function getSearchStats(): array
    {
        return [];
    }

    public function getSummarySearchStats(): array
    {
        return [];
    }

    public function getFirstSearchTime(): string
    {
        return '';
    }

    public function getSentenceWordCounts(): array
    {
        return [];
    }

    public function getDocumentWordCounts(): array
    {
        return [];
    }
}

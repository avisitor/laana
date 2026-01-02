<?php

namespace Noiiolelo;

class MetricsComputer
{
    private const MIN_DOC_HAWAIIAN_WORD_RATIO = 0.1;
    private const MIN_SENTENCE_HAWAIIAN_WORD_RATIO = 0.5;
    private const SENTENCE_SPLIT_PATTERN = '/(?<=[.?!])\s+/';
    private const WORD_SPLIT_PATTERN = '/\s+/';
    private const DIACRITIC_PATTERN = '/[āĀēĒīĪōŌūŪ\‘]/u';

    private array $hawaiianWordSet = [];
    private array $ratioCache = [];

    public function __construct(string $hawaiianWordsPath)
    {
        $this->hawaiianWordSet = $this->loadHawaiianWords($hawaiianWordsPath);
    }

    private function loadHawaiianWords(string $path): array
    {
        $set = [];
        if (!is_readable($path)) return $set;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $w) {
            $set[mb_strtolower(trim($w))] = true;
        }
        return $set;
    }

    public function calculateHawaiianWordRatio(string $text): float
    {
        $hash = md5($text);
        if (isset($this->ratioCache[$hash])) return $this->ratioCache[$hash];
        $words = preg_split(self::WORD_SPLIT_PATTERN, trim($text));
        $total = 0; $hawaiian = 0;
        foreach ($words as $w) {
            if ($w === '') continue;
            $total++;
            $norm = mb_strtolower(preg_replace(self::DIACRITIC_PATTERN, '', $w));
            if (isset($this->hawaiianWordSet[$norm])) $hawaiian++;
        }
        $ratio = ($total > 0) ? ($hawaiian / $total) : 0.0;
        $this->ratioCache[$hash] = $ratio;
        return $ratio;
    }

    public function splitSentences(string $text): array
    {
        return preg_split(self::SENTENCE_SPLIT_PATTERN, $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function filterValidSentences(array $sentences): array
    {
        $valid = [];
        foreach ($sentences as $idx => $sText) {
            $ratio = $this->calculateHawaiianWordRatio($sText);
            if ($ratio >= self::MIN_SENTENCE_HAWAIIAN_WORD_RATIO) {
                $valid[$idx] = $sText;
            }
        }
        return $valid;
    }

    public function computeSentenceMetrics(string $sentence): array
    {
        $ratio = $this->calculateHawaiianWordRatio($sentence);
        $wordCount = count(preg_split(self::WORD_SPLIT_PATTERN, trim($sentence))); 
        $length = mb_strlen($sentence);
        $entityCount = $this->countEntities($sentence);
        // Placeholders for parity fields (no ES dependency):
        $boilerplateScore = 0.0;
        $frequency = 0.0;
        return [
            'hawaiian_word_ratio' => $ratio,
            'word_count' => $wordCount,
            'entity_count' => $entityCount,
            'boilerplate_score' => $boilerplateScore,
            'length' => $length,
            'frequency' => $frequency,
        ];
    }

    public function computeDocumentMetrics(string $text): array
    {
        $ratio = $this->calculateHawaiianWordRatio($text);
        $wordCount = count(preg_split(self::WORD_SPLIT_PATTERN, trim($text)));
        $length = mb_strlen($text);
        $entityCount = $this->countEntities($text);
        return [
            'hawaiian_word_ratio' => $ratio,
            'word_count' => $wordCount,
            'length' => $length,
            'entity_count' => $entityCount,
        ];
    }

    /**
     * Count named entities (proper nouns) in text.
     * Detects capitalized words that are likely proper nouns (names, places, etc.)
     * Excludes sentence-initial words and common non-entity capitalized words.
     */
    private function countEntities(string $text): int
    {
        // Common capitalized words that are not entities
        $excludeWords = [
            'I', 'A', 'E', 'O', 'Ua', 'He', 'Ka', 'Ke', 'Nā', 'Na', 'No', 'Ma',
            'The', 'And', 'But', 'Or', 'For', 'In', 'On', 'At', 'To', 'Of', 'By',
            'Aloha', 'Mahalo'
        ];
        $excludeSet = array_flip(array_map('mb_strtolower', $excludeWords));
        
        // Split into sentences to exclude sentence-initial capitals
        $sentences = preg_split('/[.!?]+\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $entityCount = 0;
        
        foreach ($sentences as $sentence) {
            $words = preg_split(self::WORD_SPLIT_PATTERN, trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
            
            foreach ($words as $idx => $word) {
                // Skip first word of sentence (sentence-initial capitalization)
                if ($idx === 0) continue;
                
                // Check if word starts with capital letter
                $firstChar = mb_substr($word, 0, 1);
                if ($firstChar !== mb_strtoupper($firstChar)) continue;
                
                // Skip if entire word is uppercase (likely acronym or shouting)
                if ($word === mb_strtoupper($word) && mb_strlen($word) > 1) continue;
                
                // Skip if it's a common non-entity word
                if (isset($excludeSet[mb_strtolower($word)])) continue;
                
                // Skip single letters
                if (mb_strlen($word) <= 1) continue;
                
                // This is likely an entity
                $entityCount++;
            }
        }
        
        return $entityCount;
    }

    public function documentMeetsThreshold(string $text): bool
    {
        return $this->calculateHawaiianWordRatio($text) >= self::MIN_DOC_HAWAIIAN_WORD_RATIO;
    }
}

<?php

namespace HawaiianSearch;

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
        // Placeholders for parity fields (no ES dependency):
        $entityCount = 0;
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

    public function documentMeetsThreshold(string $text): bool
    {
        return $this->calculateHawaiianWordRatio($text) >= self::MIN_DOC_HAWAIIAN_WORD_RATIO;
    }
}

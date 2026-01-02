<?php

namespace HawaiianSearch;

class CorpusScanner {
    private EmbeddingClient $embeddingClient;
    private MetadataCache $metadataCache;
    private array $hawaiianWords; // Now a hash set
    private bool $dryrun;

    // Patterns for text analysis
    private const DIACRITIC_PATTERN = '/[āĀēĒīĪōŌūŪ\']/u';
    private const WORD_PATTERN = '/\b\w+\b/u';

    public function __construct(ElasticsearchClient $esClient, array $options = []) {
        $this->embeddingClient = $esClient->getEmbeddingClient();
        $this->metadataCache = new MetadataCache($esClient, $options);
        $this->hawaiianWords = isset($options['hawaiianWords']) ? $options['hawaiianWords'] : [];
        $this->dryrun = isset($options['dryrun']) ? $options['dryrun'] : false;
    }

    /**
     * Hash a sentence text to create consistent IDs
     */
    public static function hashSentence(string $text): string {
        return md5(strtolower(trim($text)));
    }

    /**
     * Normalize Hawaiian word for dictionary lookup
     */
    public static function normalizeWord(string $word): string {
        // Remove okina and apostrophes
        $word = str_replace(["'", "'"], "", $word);
        
        // Convert macrons to plain vowels
        $macrons = ['ā', 'ē', 'ī', 'ō', 'ū', 'Ā', 'Ē', 'Ī', 'Ō', 'Ū'];
        $plain = ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U'];
        $word = str_replace($macrons, $plain, $word);
        
        return strtolower(trim($word));
    }

    /**
     * Calculate Hawaiian word ratio for text
     */
    public function calculateHawaiianWordRatio(string $text): float {
        if (empty(trim($text))) {
            return 0.0;
        }
        
        preg_match_all(self::WORD_PATTERN, $text, $matches);
        $words = $matches[0];
        $wordCount = count($words);
        
        if ($wordCount === 0) {
            return 0.0;
        }

        $hawaiianWordCount = 0;

        foreach ($words as $word) {
            // Check for diacritical marks first (strong indicator of Hawaiian)
            if (preg_match(self::DIACRITIC_PATTERN, $word)) {
                $hawaiianWordCount++;
            } else {
                // Check against Hawaiian word dictionary
                $normalizedWord = self::normalizeWord($word);
                if (isset($this->hawaiianWords[$normalizedWord])) {
                    $hawaiianWordCount++;
                }
            }
        }
        
        return $hawaiianWordCount / $wordCount;
    }

    /**
     * Calculate basic entity count using simple pattern matching
     */
    public function calculateEntityCount(string $text): int {
        $entityCount = 0;
        
        // Count proper nouns (capitalized words that aren't at sentence start)
        $sentences = preg_split('/[.!?]+/', $text);
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;
            
            preg_match_all('/\b[A-Z][a-z]+\b/', $sentence, $matches);
            $capitalizedWords = $matches[0];
            
            // Skip the first word if it's capitalized (likely sentence start)
            $words = preg_split('/\s+/', trim($sentence));
            if (!empty($words) && !empty($capitalizedWords)) {
                $firstWord = $words[0];
                $capitalizedWords = array_filter($capitalizedWords, function($word) use ($firstWord) {
                    return $word !== $firstWord;
                });
            }
            
            $entityCount += count($capitalizedWords);
        }
        
        // Count dates (simple patterns)
        $entityCount += preg_match_all('/\b\d{1,4}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text);
        
        // Count numbers that might be significant
        $entityCount += preg_match_all('/\b\d{4}\b/', $text); // Years
        
        return $entityCount;
    }

    /**
     * Compute boilerplate score (quality metric)
     * Lower score = better quality (0.0 to 1.0)
     */
    public function computeBoilerplateScore(string $text, int $entityCount): float {
        $score = 0.0;
        
        // Penalize very short text
        if (strlen($text) < 40) {
            $score += 0.5;
        }
        
        // Penalize text with no entities
        if ($entityCount === 0) {
            $score += 0.5;
        }
        
        // Additional heuristics for boilerplate detection
        $text_lower = strtolower($text);
        
        // Penalize repetitive patterns
        if (preg_match('/(.{20,})\1{2,}/', $text)) { // Repeated 20+ char patterns
            $score += 0.3;
        }
        
        // Penalize common boilerplate phrases
        $boilerplatePatterns = [
            '/copyright|all rights reserved/i',
            '/click here|more info/i', 
            '/terms of service|privacy policy/i',
            '/subscribe|newsletter/i'
        ];
        
        foreach ($boilerplatePatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $score += 0.2;
                break; // Don't double-penalize
            }
        }
        
        return round(min($score, 1.0), 2);
    }

    /**
     * Full sentence analysis with all metrics
     */
    public function analyzeSentence(string $text, string $docId = '', array $existingMetadata = null): array {
        $hash = self::hashSentence($text);

        // Start with existing metadata or default values
        $metadata = $existingMetadata ?: ['frequency' => 0];
        
        // Increment frequency count
        $metadata['frequency'] = ($metadata['frequency'] ?? 0) + 1;

        // Calculate basic metrics locally
        preg_match_all(self::WORD_PATTERN, $text, $matches);
        $words = $matches[0];
        $wordCount = count($words);
        
        $entityCount = $this->calculateEntityCount($text);
        $hawaiianWordRatio = $this->calculateHawaiianWordRatio($text);
        $boilerplateScore = $this->computeBoilerplateScore($text, $entityCount);

        // Update metadata with all calculated metrics
        $metadata['sentence_hash'] = $hash;
        $metadata['length'] = strlen($text);
        $metadata['entity_count'] = $entityCount;
        $metadata['word_count'] = $wordCount;
        $metadata['hawaiian_word_ratio'] = $hawaiianWordRatio;
        $metadata['boilerplate_score'] = $boilerplateScore;

        // Track document IDs and positions where this sentence appears
        if (!isset($metadata['metadata'])) {
            $metadata['metadata'] = ['doc_ids' => [], 'positions' => []];
        }
        
        // Add current document and position if provided and not already present
        if (!empty($docId) && !in_array($docId, $metadata['metadata']['doc_ids'])) {
            $metadata['metadata']['doc_ids'][] = $docId;
            $metadata['metadata']['positions'][] = 0; // Position would need to be passed in
        }

        // Save to the metadata cache if not in dry run mode
        if (!$this->dryrun) {
            $this->metadataCache->set($hash, $metadata);
        }

        return $metadata;
    }

    /**
     * Legacy method for backward compatibility - calls the enhanced analyzeSentence
     */

    public function finish(): void {
        $this->metadataCache->finish();
    }


    public function setHawaiianWords(array $words): void {
        $this->hawaiianWords = $words;
    }
}

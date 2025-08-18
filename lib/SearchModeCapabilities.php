<?php

/**
 * Defines search mode capabilities for proper highlighting and behavior
 */
class SearchModeCapabilities 
{
    const EXACT_MATCH = 'exact_match';           // Exact phrase/word matching
    const FUZZY_MATCH = 'fuzzy_match';           // Loose word matching  
    const SEMANTIC_MATCH = 'semantic_match';     // Meaning-based matching
    const REGEX_MATCH = 'regex_match';           // Pattern matching
    const SENTENCE_LEVEL = 'sentence_level';     // Returns individual sentences
    const DOCUMENT_LEVEL = 'document_level';     // Returns document chunks
    
    /**
     * Mode capability definitions
     * Each mode defines its matching behavior and result level
     */
    public static function getModeCapabilities(): array 
    {
        return [
            // Sentence-level modes (preferred for getSentences)
            'hybridsentence' => [
                'capabilities' => [self::FUZZY_MATCH, self::SEMANTIC_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'fuzzy',
                'description' => 'Hybrid keyword + semantic search on sentences'
            ],
            'vectorsentence' => [
                'capabilities' => [self::SEMANTIC_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'semantic', 
                'description' => 'Semantic sentence search'
            ],
            'matchsentence' => [
                'capabilities' => [self::FUZZY_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'fuzzy',
                'description' => 'Match words in sentences'
            ],
            'termsentence' => [
                'capabilities' => [self::EXACT_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'exact',
                'description' => 'Exact terms in sentences'
            ],
            'phrasesentence' => [
                'capabilities' => [self::EXACT_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'exact',
                'description' => 'Exact phrases in sentences'
            ],
            'regexpsentence' => [
                'capabilities' => [self::REGEX_MATCH, self::SENTENCE_LEVEL],
                'highlight_style' => 'regex',
                'description' => 'Regular expressions on sentences'
            ],
            
            // Document-level modes
            'match' => [
                'capabilities' => [self::FUZZY_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'fuzzy',
                'description' => 'Match words anywhere'
            ],
            'phrase' => [
                'capabilities' => [self::EXACT_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'exact',
                'description' => 'Exact phrase matching'
            ],
            'term' => [
                'capabilities' => [self::EXACT_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'exact',
                'description' => 'Exact term matching'
            ],
            'wildcard' => [
                'capabilities' => [self::REGEX_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'regex',
                'description' => 'Wildcard pattern search'
            ],
            'regexp' => [
                'capabilities' => [self::REGEX_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'regex',
                'description' => 'Regular expression search'
            ],
            'hybrid' => [
                'capabilities' => [self::FUZZY_MATCH, self::SEMANTIC_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'fuzzy',
                'description' => 'Hybrid keyword + semantic search'
            ],
            'vector' => [
                'capabilities' => [self::SEMANTIC_MATCH, self::DOCUMENT_LEVEL],
                'highlight_style' => 'semantic',
                'description' => 'Semantic vector search'
            ]
        ];
    }
    
    /**
     * Get highlighting style for a mode
     */
    public static function getHighlightStyle(string $mode): string
    {
        $capabilities = self::getModeCapabilities();
        return $capabilities[$mode]['highlight_style'] ?? 'fuzzy';
    }
    
    /**
     * Check if a mode has a specific capability
     */
    public static function hasCapability(string $mode, string $capability): bool
    {
        $capabilities = self::getModeCapabilities();
        return in_array($capability, $capabilities[$mode]['capabilities'] ?? []);
    }
    
    /**
     * Get preferred mode for getSentences (sentence-level preferred)
     */
    public static function getPreferredSentenceMode(array $availableModes): ?string
    {
        // Preference order for sentence-level modes
        $preferredOrder = ['hybridsentence', 'vectorsentence', 'matchsentence', 'phrasesentence', 'termsentence'];
        
        foreach ($preferredOrder as $preferredMode) {
            if (in_array($preferredMode, $availableModes)) {
                return $preferredMode;
            }
        }
        
        // Fallback to any sentence-level mode
        foreach ($availableModes as $mode) {
            if (self::hasCapability($mode, self::SENTENCE_LEVEL)) {
                return $mode;
            }
        }
        
        return null;
    }
}
?>

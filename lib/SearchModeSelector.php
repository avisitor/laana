<?php
require_once __DIR__ . '/SearchModeCapabilities.php';

/**
 * Helps getPageHTML select appropriate search modes based on user intent and provider capabilities
 */
class SearchModeSelector
{
    /**
     * Select best mode for getSentences based on user intent
     * 
     * @param string $userIntent - User's search intent ('exact', 'fuzzy', 'semantic', 'regex')
     * @param array $availableModes - Provider's available modes
     * @return string - Best matching mode
     */
    public static function selectModeForSentences(string $userIntent, array $availableModes): string
    {
        // First try to get a sentence-level mode matching the intent
        $preferredMode = self::findModeByIntent($userIntent, $availableModes, true);
        if ($preferredMode) {
            return $preferredMode;
        }
        
        // Fallback to any sentence-level mode
        $sentenceMode = SearchModeCapabilities::getPreferredSentenceMode($availableModes);
        if ($sentenceMode) {
            return $sentenceMode;
        }
        
        // Last resort: any mode matching intent
        $anyMode = self::findModeByIntent($userIntent, $availableModes, false);
        if ($anyMode) {
            return $anyMode;
        }
        
        // Final fallback
        return $availableModes[0] ?? 'match';
    }
    
    /**
     * Find mode by user intent
     */
    private static function findModeByIntent(string $intent, array $availableModes, bool $sentenceLevelOnly): ?string
    {
        $intentToCapability = [
            'exact' => SearchModeCapabilities::EXACT_MATCH,
            'fuzzy' => SearchModeCapabilities::FUZZY_MATCH,
            'semantic' => SearchModeCapabilities::SEMANTIC_MATCH,
            'regex' => SearchModeCapabilities::REGEX_MATCH,
        ];
        
        $requiredCapability = $intentToCapability[$intent] ?? SearchModeCapabilities::FUZZY_MATCH;
        
        foreach ($availableModes as $mode) {
            $hasIntent = SearchModeCapabilities::hasCapability($mode, $requiredCapability);
            $isSentenceLevel = SearchModeCapabilities::hasCapability($mode, SearchModeCapabilities::SENTENCE_LEVEL);
            
            if ($hasIntent && (!$sentenceLevelOnly || $isSentenceLevel)) {
                return $mode;
            }
        }
        
        return null;
    }
    
    /**
     * Map legacy patterns to user intents
     */
    public static function legacyPatternToIntent(string $pattern): string
    {
        switch($pattern) {
            case 'exact':
            case 'order':
                return 'exact';
            case 'any':
            case 'all':
                return 'fuzzy';
            case 'regex':
                return 'regex';
            default:
                return 'fuzzy';
        }
    }
    
    /**
     * Get highlighting approach for a mode
     */
    public static function getHighlightingApproach(string $mode, string $userIntent): array
    {
        $style = SearchModeCapabilities::getHighlightStyle($mode);
        
        return [
            'style' => $style,
            'needs_regex' => $style === 'regex',
            'needs_expansion' => $style === 'fuzzy' && $userIntent !== 'exact',
            'case_sensitive' => false, // Hawaiian text is typically case-insensitive
        ];
    }
}
?>

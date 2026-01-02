<?php

namespace HawaiianSearch;

/**
 * Utility class for loading and processing Hawaiian word dictionaries
 */
class HawaiianWordLoader
{
    /**
     * Load Hawaiian words from file as a hash set for O(1) lookups
     */
    public static function loadAsHashSet(string $filePath): array
    {
        if (!file_exists($filePath)) {
            error_log("Hawaiian words file not found: {$filePath}");
            return [];
        }
        
        $words = file_get_contents($filePath);
        if ($words === false) {
            error_log("Failed to read Hawaiian words file: {$filePath}");
            return [];
        }
        
        $lines = explode("\n", $words);
        $wordSet = []; // Use associative array as hash set for O(1) lookups
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $commaParts = array_map('trim', explode(',', $line));
            foreach ($commaParts as $part) {
                $wordParts = explode(' ', $part);
                foreach ($wordParts as $word) {
                    $word = trim($word);
                    if ($word && strtolower($word) !== 'a' && strtolower($word) !== 'i') {
                        $normalized = CorpusScanner::normalizeWord($word);
                        $wordSet[$normalized] = true; // Hash set - key exists = true
                    }
                }
            }
        }
        
        return $wordSet;
    }
    
    /**
     * Load Hawaiian words with verbose output for scripts
     */
    public static function loadAsHashSetWithOutput(string $filePath, bool $verbose = true): array
    {
        if ($verbose) {
            echo "🔧 Loading Hawaiian words from: {$filePath}\n";
        }
        
        $wordSet = self::loadAsHashSet($filePath);
        
        if ($verbose) {
            echo "🔧 Loaded " . count($wordSet) . " Hawaiian words into hash set\n";
        }
        
        return $wordSet;
    }
}

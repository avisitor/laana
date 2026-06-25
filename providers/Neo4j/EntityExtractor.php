<?php
namespace Noiiolelo\Providers\Neo4j;

class EntityExtractor
{
    /**
     * Extract entities from text using basic recognition rules
     * 
     * @param string $text Text to process
     * @param array $options Recognition options
     * @return array Entities found in text
     */
    public static function extractEntities(string $text, array $options = []): array
    {
        $entities = [];
        $text = trim($text);
        
        if (empty($text)) {
            return $entities;
        }
        
        // Simple regex-based entity detection 
        // This is more basic than what might be needed for full NER
        
        // Detect capitalized words that might be proper nouns
        preg_match_all('/\b[A-Z][a-z]+(?:[A-Z][a-z]+)*\b/', $text, $matches, PREG_OFFSET_CAPTURE);
        
        $processedPositions = [];
        
        foreach ($matches[0] as $match) {
            $word = $match[0];
            $position = $match[1];
            
            // Skip if position already processed (for overlapping matches)
            if (in_array($position, $processedPositions)) {
                continue;
            }
            
            // Add to processed positions (check a few characters before and after)
            for ($i = max(0, $position - 2); $i < min(strlen($text), $position + strlen($word) + 2); $i++) {
                $processedPositions[] = $i;
            }
            
            // Basic rule: if it looks like a person name
            if (self::isPersonName($word)) {
                $entities[] = [
                    'name' => $word,
                    'type' => 'Person',
                    'id' => self::generateEntityId($word, 'Person')
                ];
            } 
            // Basic rule: if it looks like a location
            elseif (self::isLocationName($word)) {
                $entities[] = [
                    'name' => $word,
                    'type' => 'Location',
                    'id' => self::generateEntityId($word, 'Location')
                ];
            }
        }
        
        // Basic pattern matching for dates
        preg_match_all('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text, $dateMatches);
        foreach ($dateMatches[0] as $date) {
            $entities[] = [
                'name' => $date,
                'type' => 'Date',
                'id' => self::generateEntityId($date, 'Date')
            ];
        }
        
        // Basic pattern matching for years
        preg_match_all('/\b\d{4}\b/', $text, $yearMatches);
        foreach ($yearMatches[0] as $year) {
            $entities[] = [
                'name' => $year,
                'type' => 'Year',
                'id' => self::generateEntityId($year, 'Year')
            ];
        }
        
        return array_unique($entities, SORT_REGULAR);
    }
    
    /**
     * Simple check for person name patterns
     * 
     * @param string $word Word to check
     * @return bool Whether it is likely a person name
     */
    private static function isPersonName(string $word): bool
    {
        // Common suffixes for names (very basic)
        $nameSuffixes = [' Jr', ' Sr', ' III', ' IV', ' II'];
        foreach ($nameSuffixes as $suffix) {
            if (str_ends_with($word, $suffix)) {
                return true;
            }
        }
        
        // Common male names for basic matching
        $maleNames = [
            'John', 'James', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph',
            'Thomas', 'Charles', 'Christopher', 'Daniel', 'Matthew', 'Donald', 'Mark', 
            'Paul', 'Steven', 'Andrew', 'Joshua', 'Kenneth', 'Kevin', 'Brian', 'George',
            'Timothy', 'Ronald', 'Jason', 'Jeffrey', 'Ryan', 'Jacob', 'Gary', 'Nicholas'
        ];
        
        // Common female names for basic matching
        $femaleNames = [
            'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan',
            'Jessica', 'Sarah', 'Karen', 'Nancy', 'Lisa', 'Betty', 'Helen', 'Sandra',
            'Donna', 'Carol', 'Ruth', 'Sharon', 'Michelle', 'Laura', 'Sarah', 'Kimberly',
            'Deborah', 'Lisa', 'Dorothy', 'Lisa', 'Nancy', 'Betty', 'Helen', 'Sandra'
        ];
        
        // Basic check for common first names
        return in_array($word, array_merge($maleNames, $femaleNames));
    }
    
    /**
     * Simple check for location name patterns
     * 
     * @param string $word Word to check
     * @return bool Whether it is likely a location name
     */
    private static function isLocationName(string $word): bool
    {
        // Common location suffixes
        $locationSuffixes = ['City', 'Town', 'Village', 'State', 'County', 'Island'];
        foreach ($locationSuffixes as $suffix) {
            if (str_ends_with($word, $suffix)) {
                return true;
            }
        }
        
        // Common place names
        $placeKeywords = ['University', 'College', 'School', 'Institute', 'Center'];
        foreach ($placeKeywords as $keyword) {
            if (stripos($word, $keyword) !== false) {
                return true;
            }
        }
        
        // If it's not a common name and not a common word, it might be location
        return !in_array(strtolower($word), [
            'the', 'and', 'for', 'of', 'in', 'on', 'at', 'to', 'by', 'is', 'was', 'were'
        ]);
    }
    
    /**
     * Generate a unique entity ID
     * 
     * @param string $name Name of entity
     * @param string $type Type of entity
     * @return string Unique entity ID
     */
    private static function generateEntityId(string $name, string $type): string
    {
        $baseId = $type . '_' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        return self::generateUniqueId($baseId);
    }
    
    /**
     * Generate unique ID from base
     * 
     * @param string $baseId Base ID
     * @return string Unique ID
     */
    private static function generateUniqueId(string $baseId): string
    {
        // Generate a unique suffix to prevent collisions
        return $baseId . '_' . uniqid();
    }
    
    /**
     * Extract relationships between entities in text
     * 
     * @param string $text Text to process
     * @param array $entities Entities found in text
     * @return array Relationships found
     */
    public static function extractRelationships(string $text, array $entities = []): array
    {
        $relationships = [];
        
        if (empty($text)) {
            return $relationships;
        }
        
        // Simple relationship extraction based on common sentence structures
        // This looks for common patterns like "X founded Y" or "X visited Y"
        
        // Looking for pattern: person + verb + person/location
        preg_match_all('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)(?:\s+)(?:founded|started|established|visited|created|built|lived|studied|attended)(?:\s+)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*?)/i', $text, $matches);
        
        for ($i = 0; $i < count($matches[0]); $i++) {
            $source = $matches[1][$i]; 
            $target = $matches[2][$i];
            $relation = $matches[0][$i];
            
            // Match to existing entities
            $sourceEntity = null;
            $targetEntity = null;
            
            foreach ($entities as $entity) {
                if (stripos($entity['name'], $source) !== false) {
                    $sourceEntity = $entity;
                }
                if (stripos($entity['name'], $target) !== false) {
                    $targetEntity = $entity;
                }
            }
            
            if ($sourceEntity && $targetEntity) {
                $relationships[] = [
                    'source' => $sourceEntity['id'],
                    'relation' => self::determineRelationType($relation),
                    'target' => $targetEntity['id']
                ];
            }
        }
        
        // Looking for pattern: X is located in Y
        preg_match_all('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)(?:\s+)(?:is|was)(?:\s+)(?:located|situated|found)(?:\s+in)(?:\s+)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*?)/i', $text, $matches2);
        
        for ($i = 0; $i < count($matches2[0]); $i++) {
            $source = $matches2[1][$i]; 
            $target = $matches2[2][$i];
            
            // Match to existing entities
            $sourceEntity = null;
            $targetEntity = null;
            
            foreach ($entities as $entity) {
                if (stripos($entity['name'], $source) !== false) {
                    $sourceEntity = $entity;
                }
                if (stripos($entity['name'], $target) !== false) {
                    $targetEntity = $entity;
                }
            }
            
            if ($sourceEntity && $targetEntity) {
                $relationships[] = [
                    'source' => $sourceEntity['id'],
                    'relation' => 'LOCATED_IN',
                    'target' => $targetEntity['id']
                ];
            }
        }
        
        return $relationships;
    }
    
    /**
     * Determine relation type from text match
     * 
     * @param string $relationText Matched text
     * @return string Relation type
     */
    private static function determineRelationType(string $relationText): string
    {
        $relationText = strtolower($relationText);
        
        if (strpos($relationText, 'founded') !== false) {
            return 'FOUNDED';
        } elseif (strpos($relationText, 'visited') !== false) {
            return 'VISITED';
        } elseif (strpos($relationText, 'lived') !== false) {
            return 'LIVES_IN';
        } elseif (strpos($relationText, 'studied') !== false) {
            return 'STUDIED_AT';
        } elseif (strpos($relationText, 'attended') !== false) {
            return 'ATTENDED';
        } else {
            return 'RELATED';
        }
    }
}
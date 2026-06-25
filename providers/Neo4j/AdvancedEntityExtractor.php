<?php
namespace Noiiolelo\Providers\Neo4j;

class AdvancedEntityExtractor
{
    private const MAX_SENTENCE_RELATION_PAIRS = 200;

    /**
     * Extract entities using a more sophisticated approach
     * 
     * @param string $text Text to process
     * @param array $options Extraction options
     * @return array Entities found in text
     */
    public static function extractEntities(string $text, array $options = []): array
    {
        $entities = [];
        $text = trim($text);
        
        if (empty($text)) {
            return $entities;
        }
        
        // Check for specific patterns based on corpus content
        // This mimics the pattern used in existing codebase
        
        // Extract person names (basic implementation using common patterns)
        $personPattern = '/\b(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/';
        preg_match_all($personPattern, $text, $matches, PREG_OFFSET_CAPTURE);
        
        $processedPositions = [];
        foreach ($matches[0] as $match) {
            $word = $match[0];
            $position = $match[1];
            
            // Skip if already processed (overlapping matches)
            if (in_array($position, $processedPositions, true)) {
                continue;
            }
            
            // Mark as processed
            for ($i = max(0, $position - 1); $i < min(strlen($text), $position + strlen($word) + 1); $i++) {
                $processedPositions[] = $i;
            }
            
            // Skip common non-entity words
            $commonWords = ['the', 'and', 'for', 'of', 'in', 'on', 'at', 'to', 'by', 'is', 'was', 'were', 'are'];
            if (in_array(strtolower($word), $commonWords)) {
                continue;
            }
            
            // Check if it's a potential person name
            if (self::isLikelyPersonName($word)) {
                $entities[] = [
                    'name' => $word,
                    'type' => 'Person',
                    'id' => self::generateEntityId($word, 'Person')
                ];
            } else {
                // Otherwise treat as a generic entity based on context
                $context = self::getContext($text, $position, $word);
                if (self::isLocationContext($context)) {
                    $entities[] = [
                        'name' => $word,
                        'type' => 'Location',
                        'id' => self::generateEntityId($word, 'Location')
                    ];
                } else {
                    $entities[] = [
                        'name' => $word,
                        'type' => 'Entity',
                        'id' => self::generateEntityId($word, 'Entity')
                    ];
                }
            }
        }
        
        // Extract dates (more robust)
        $datePattern = '/\b(?:\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4})\b/';
        preg_match_all($datePattern, $text, $dateMatches);
        foreach ($dateMatches[0] as $date) {
            $entities[] = [
                'name' => $date,
                'type' => 'Date',
                'id' => self::generateEntityId($date, 'Date')
            ];
        }
        
        // Remove duplicate entities by name and type
        $uniqueEntities = [];
        $seen = [];
        foreach ($entities as $entity) {
            $key = $entity['name'] . '#' . $entity['type'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueEntities[] = $entity;
            }
        }
        
        return $uniqueEntities;
    }
    
    /**
     * Check if a word is likely a person name
     */
    private static function isLikelyPersonName(string $word): bool
    {
        // Common first names in Hawaiian context or English
        $firstNames = [
            'John', 'James', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph',
            'Thomas', 'Charles', 'Christopher', 'Daniel', 'Matthew', 'Donald', 'Mark', 
            'Paul', 'Steven', 'Andrew', 'Joshua', 'Kenneth', 'Kevin', 'Brian', 'George',
            'Timothy', 'Ronald', 'Jason', 'Jeffrey', 'Ryan', 'Jacob', 'Gary', 'Nicholas',
            'Kamehameha', 'Lahainaluna', 'Kalani', 'Hale', 'Kealoha', 'Nāmānā', 'Kauanui'  // Hawaiian names
        ];
        
        return in_array($word, $firstNames);
    }
    
    /**
     * Get context around a word 
     */
    private static function getContext(string $text, int $position, string $word): string
    {
        $start = max(0, $position - 20);
        $end = min(strlen($text), $position + strlen($word) + 20);
        $context = substr($text, $start, $end - $start);
        return trim($context);
    }
    
    /**
     * Check if context suggests location
     */
    private static function isLocationContext(string $context): bool
    {
        $locationIndicators = ['island', 'province', 'city', 'town', 'village', 'school', 'campus'];
        foreach ($locationIndicators as $indicator) {
            if (stripos($context, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Generate a unique entity ID
     */
    private static function generateEntityId(string $name, string $type): string
    {
        $normalizedType = strtoupper(trim($type));
        $normalizedName = self::normalizeEntityName($name);
        $hash = substr(sha1($normalizedType . '|' . $normalizedName), 0, 24);
        return $normalizedType . '_' . $hash;
    }

    private static function normalizeEntityName(string $name): string
    {
        $name = trim(mb_strtolower($name, 'UTF-8'));
        $name = str_replace(['ʻ', "'"], '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return $name ?? '';
    }

    private static function addRelationship(array &$relationships, array &$relationshipSet, string $sourceId, string $relation, string $targetId): void
    {
        if ($sourceId === '' || $targetId === '' || $sourceId === $targetId) {
            return;
        }

        $undirected = in_array($relation, ['CO_OCCURS_WITH', 'CO_MENTIONED_WITH'], true);
        if ($undirected && strcmp($sourceId, $targetId) > 0) {
            [$sourceId, $targetId] = [$targetId, $sourceId];
        }

        $key = $sourceId . '|' . $relation . '|' . $targetId;
        if (isset($relationshipSet[$key])) {
            return;
        }
        $relationshipSet[$key] = true;

        $relationships[] = [
            'source' => $sourceId,
            'relation' => $relation,
            'target' => $targetId,
        ];
    }
    
    /**
     * Extract relationships between entities in a text
     * 
     * @param string $text Text to process
     * @param array $entities Entities found in text
     * @return array Relationships found
     */
    public static function extractRelationships(string $text, array $entities = []): array
    {
        $relationships = [];
        $relationshipSet = [];
        
        if (empty($text)) {
            return $relationships;
        }

        $entityPhrase = '([A-Z][\\p{L}]+(?:\\s+[A-Z][\\p{L}]+){0,4})';
        $relationshipPatterns = [
            ['relation' => 'FOUNDED', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:founded|established|created|built|started|ho['ʻ]?okumu|ho['ʻ]?okumu\\s+ia)\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'LIVES_IN', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:lives?|lived|resides?|resided)\\s+in\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'LIVES_IN', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:noho|noho\\s+ma)\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'VISITED', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:visited|travel(?:ed|led)\\s+to|went\\s+to|kipa\\s+i|hele\\s+i)\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'STUDIED_AT', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:studied|attended|went\\s+to\\s+school\\s+at|went\\s+to\\s+college\\s+at|a['ʻ]?o\\s+ma|a['ʻ]?o\\s+i)\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'WORKED_AT', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:worked\\s+at|works\\s+at|served\\s+at|hana\\s+ma)\\s+" . $entityPhrase . "/iu"],
            ['relation' => 'LEADS', 'regex' => "/\\b" . $entityPhrase . "\\s+(?:led|heads?|directs?)\\s+" . $entityPhrase . "/iu"],
        ];

        foreach ($relationshipPatterns as $pattern) {
            preg_match_all($pattern['regex'], $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $i => $match) {
                $sourceName = trim($matches[1][$i][0] ?? '');
                $targetName = trim($matches[2][$i][0] ?? '');
                if ($sourceName === '' || $targetName === '') {
                    continue;
                }

                $sourceEntity = self::findEntity($sourceName, $entities);
                $targetEntity = self::findEntity($targetName, $entities);
                if (!$sourceEntity || !$targetEntity) {
                    continue;
                }

                self::addRelationship(
                    $relationships,
                    $relationshipSet,
                    (string)($sourceEntity['id'] ?? ''),
                    $pattern['relation'],
                    (string)($targetEntity['id'] ?? '')
                );
            }
        }

        // Fallback: infer undirected co-occurrence edges for entities appearing in the same sentence.
        $pairCount = 0;
        $sentences = preg_split('/[.!?;\n]+/u', $text) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $sentenceEntityIds = [];
            foreach ($entities as $entity) {
                $entityName = (string)($entity['name'] ?? '');
                $entityId = (string)($entity['id'] ?? '');
                if ($entityName === '' || $entityId === '') {
                    continue;
                }
                if (stripos($sentence, $entityName) !== false) {
                    $sentenceEntityIds[$entityId] = true;
                }
            }

            $ids = array_keys($sentenceEntityIds);
            $count = count($ids);
            if ($count < 2) {
                continue;
            }

            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    self::addRelationship($relationships, $relationshipSet, $ids[$i], 'CO_OCCURS_WITH', $ids[$j]);
                    $pairCount++;
                    if ($pairCount >= self::MAX_SENTENCE_RELATION_PAIRS) {
                        break 3;
                    }
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Find entity by name among given entities
     */
    private static function findEntity(string $name, array $entities): ?array
    {
        $needle = self::normalizeEntityName($name);
        if ($needle === '') {
            return null;
        }

        foreach ($entities as $entity) {
            $entityName = (string)($entity['name'] ?? '');
            $normalizedEntityName = self::normalizeEntityName($entityName);
            if ($normalizedEntityName === '') {
                continue;
            }

            if ($normalizedEntityName === $needle ||
                str_contains($normalizedEntityName, $needle) ||
                str_contains($needle, $normalizedEntityName)) {
                return $entity;
            }
        }
        return null;
    }
}
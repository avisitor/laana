<?php
namespace Noiiolelo;

require_once __DIR__ . '/grammar_patterns.php';

class GrammarScanner {
    protected $patterns;
    protected $db;

    public function __construct($db = null) {
        $this->patterns = getGrammarPatterns();
        $this->db = $db;
    }

    public function setDB($db) {
        $this->db = $db;
    }

    /**
     * Creates a 'DNA' string of grammatical markers.
     * Ported from findpatterns.py
     */
    public function generateFingerprint($text) {
        $multi_markers = ['i ka', 'i ke', 'ma ka', 'ma ke', 'e ana', 'ua pau'];
        $single_markers = ['he', 'o', 'ma', 'i', 'no', 'na', 'ai', 'ana', 'ua', 'ke', 'aia', 'aole', 'hiki', 'nei', 'ala', 'mai', 'aku', 'iho', 'ae'];
        
        $text_clean = mb_strtolower(trim($text));
        // Remove punctuation but keep Hawaiian characters
        $text_no_punct = preg_replace('/[^\w\sʻāēīōū]/u', '', $text_clean);
        
        $found = [];
        $remaining_text = $text_no_punct;
        
        // Check for multi-word markers first
        foreach ($multi_markers as $m) {
            if (mb_strpos($remaining_text, $m) !== false) {
                $found[] = str_replace(' ', '_', $m);
                $remaining_text = str_replace($m, ' ', $remaining_text);
            }
        }
        
        // Check for single-word markers
        preg_match_all('/[\wʻāēīōū]+/u', $remaining_text, $matches);
        if (!empty($matches[0])) {
            foreach ($matches[0] as $t) {
                if (in_array($t, $single_markers)) {
                    $found[] = $t;
                }
            }
        }
        
        return !empty($found) ? implode('-', $found) : "plain";
    }

    /**
     * Scans a sentence for grammatical patterns.
     */
    public function scanSentence($text) {
        $matches = [];
        $fingerprint = $this->generateFingerprint($text);
        
        foreach ($this->patterns as $p_type => $meta) {
            if (preg_match($meta['regex'], $text)) {
                $signature = $meta['signature'] . " | " . $fingerprint;
                $matches[] = [
                    'pattern_type' => $p_type,
                    'signature' => $signature
                ];
            }
        }
        return $matches;
    }

    /**
     * Saves pattern matches for a sentence to the database.
     */
    public function savePatterns($sentenceId, $matches) {
        if (!$this->db || empty($matches)) return;
        
        $driver = $this->db->conn->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        foreach ($matches as $match) {
            if ($driver === 'pgsql') {
                $sql = "INSERT INTO sentence_patterns (sentenceid, pattern_type, signature) 
                        VALUES (:sentenceid, :pattern_type, :signature)
                        ON CONFLICT (sentenceid, pattern_type) DO NOTHING";
            } else {
                $sql = "INSERT IGNORE INTO sentence_patterns (sentenceid, pattern_type, signature) 
                        VALUES (:sentenceid, :pattern_type, :signature)";
            }
            
            $this->db->executePrepared($sql, [
                'sentenceid' => $sentenceId,
                'pattern_type' => $match['pattern_type'],
                'signature' => $match['signature']
            ]);
        }
    }
    
    /**
     * Scans a sentence and saves any matches.
     */
    public function scanAndSave($sentenceId, $text) {
        $matches = $this->scanSentence($text);
        $this->savePatterns($sentenceId, $matches);
    }

    /**
     * Updates patterns for all sentences of a specific source.
     * @param int $sourceId The source ID to process
     * @param bool $force If true, deletes existing patterns for these sentences first.
     *                    If false, only processes sentences that have no patterns.
     */
    public function updateSourcePatterns($sourceId, $force = false) {
        if (!$this->db) return 0;
        
        if ($force) {
            // Delete existing patterns for all sentences in this source
            $deleteSql = "DELETE FROM sentence_patterns WHERE sentenceid IN (SELECT sentenceID FROM sentences WHERE sourceID = :sourceID)";
            $this->db->executePrepared($deleteSql, ['sourceID' => $sourceId]);
            
            $sql = "SELECT sentenceID, hawaiianText FROM sentences WHERE sourceID = :sourceID";
        } else {
            // Only select sentences that don't have any patterns yet
            $sql = "SELECT s.sentenceID, s.hawaiianText 
                    FROM sentences s 
                    LEFT JOIN sentence_patterns sp ON s.sentenceID = sp.sentenceid 
                    WHERE s.sourceID = :sourceID AND sp.sentenceid IS NULL";
        }
        
        $sentences = $this->db->getDBRows($sql, ['sourceID' => $sourceId]);
        $count = 0;
        foreach ($sentences as $row) {
            $this->scanAndSave($row['sentenceid'], $row['hawaiiantext']);
            $count++;
        }
        return $count;
    }

    /**
     * Updates patterns for all sentences in the database.
     * @param bool $force If true, deletes all existing patterns first.
     *                    If false, only processes sentences that have no patterns.
     */
    public function updateAllPatterns($force = false) {
        if (!$this->db) return 0;

        if ($force) {
            $this->db->executePrepared("TRUNCATE TABLE sentence_patterns");
            $sql = "SELECT sentenceID, hawaiianText FROM sentences";
        } else {
            $sql = "SELECT s.sentenceID, s.hawaiianText 
                    FROM sentences s 
                    LEFT JOIN sentence_patterns sp ON s.sentenceID = sp.sentenceid 
                    WHERE sp.sentenceid IS NULL";
        }

        // For large databases, we should probably use a cursor or process in batches
        // but for now we'll follow the existing pattern.
        $sentences = $this->db->getDBRows($sql);
        $count = 0;
        foreach ($sentences as $row) {
            $this->scanAndSave($row['sentenceid'], $row['hawaiiantext']);
            $count++;
            if ($count % 1000 == 0) {
                error_log("Processed $count sentences...");
            }
        }
        return $count;
    }
}

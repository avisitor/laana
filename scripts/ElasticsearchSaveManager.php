<?php
/**
 * ElasticsearchSaveManager - Save documents to Elasticsearch instead of MySQL
 * 
 * This class mirrors the functionality of SaveManager but uses Elasticsearch
 * as the backend instead of the Laana MySQL database.
 * 
 * Usage:
 *   $options = [
 *       'parserkey' => 'nupepa',
 *       'debug' => true,
 *       'maxrows' => 100
 *   ];
 *   $manager = new ElasticsearchSaveManager($options);
 *   $manager->getAllDocuments();
 */

require_once __DIR__ . '/../../elasticsearch/vendor/autoload.php';
require_once __DIR__ . '/../../elasticsearch/php/src/ElasticsearchClient.php';
require_once __DIR__ . '/../../elasticsearch/php/src/EmbeddingClient.php';
require_once __DIR__ . '/../db/parsehtml.php';
require_once __DIR__ . '/parsers.php';
use HawaiianSearch\ElasticsearchClient;

class ElasticsearchSaveManager {
    private $client;
    private $parsers;
    private $debug = false;
    private $maxrows = 20000;
    private $options = [];
    private $parser = null;
    protected $logName = "ElasticsearchSaveManager";
    protected $funcName = "";

    public function __construct($options = []) {
        $this->funcName = "__construct";
        global $parsermap;
        
        // Initialize Elasticsearch client
        $this->client = new ElasticsearchClient([
            'verbose' => $options['verbose'] ?? false,
            'SPLIT_INDICES' => true
        ]);
        
        $this->parsers = $parsermap;
        
        if (isset($options['debug'])) {
            $this->setDebug($options['debug']);
        }
        if (isset($options['maxrows'])) {
            $this->maxrows = $options['maxrows'];
        }
        if (isset($options['parserkey'])) {
            $this->parser = $this->getParser($options['parserkey']);
            $this->log($this->parser, "parser");
        }
        $this->options = $options;
        $this->log($this->options, "options");
        
        echo "ElasticsearchSaveManager initialized\n";
        echo "Documents index: {$this->client->getDocumentsIndexName()}\n";
        echo "Sentences index: {$this->client->getSentencesIndexName()}\n";
        echo "Processing logs: Elasticsearch (processing-logs index)\n";
        
        // Run integrity check
        $integrity = $this->client->checkSourceIntegrity();
        if ($integrity['status'] === 'warning' || $integrity['status'] === 'error') {
            echo "\n⚠️  SOURCE INTEGRITY CHECK:\n";
            echo "Status: {$integrity['status']}\n";
            echo "Counter: {$integrity['counter_value']}, ";
            echo "Max in metadata: {$integrity['max_in_metadata']}, ";
            echo "Max in documents: {$integrity['max_in_documents']}, ";
            echo "Max in sentences: {$integrity['max_in_sentences']}\n";
            foreach ($integrity['warnings'] as $warning) {
                echo "  - $warning\n";
            }
            echo "\n";
        }
        echo "\n";
    }

    public function getParserKeys() {
        return join(",", array_keys($this->parsers));
    }
    
    public function getParser($key) {
        if (isset($this->parsers[$key])) {
            return $this->parsers[$key];
        }
        return null;
    }

    protected function formatLog($obj, $prefix = "") {
        if ($prefix && !is_string($prefix)) {
            $prefix = json_encode($prefix);
        }
        if ($this->funcName) {
            $func = $this->logName . ":" . $this->funcName;
            $prefix = ($prefix) ? "$func:$prefix" : $func;
        }
        return $prefix;
    }
    
    public function log($obj, $prefix = "") {
        $prefix = $this->formatLog($obj, $prefix);
        debuglog($obj, $prefix);
    }
    
    public function debugPrint($obj, $prefix = "") {
        if ($this->debug) {
            $text = $this->formatLog($obj, $prefix);
            printObject($obj, $text);
        }
    }
    
    private function setDebug($debug) {
        $this->debug = $debug;
        setDebug($debug);
    }



    /**
     * Calculate Hawaiian word ratio using simple heuristics
     */
    private function calculateHawaiianWordRatio(string $text): float {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return 0.0;
        }
        
        $hawaiianCount = 0;
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (empty($cleanWord)) {
                continue;
            }
            
            // Check for Hawaiian indicators
            if (preg_match('/[ʻāēīōūĀĒĪŌŪ]/', $cleanWord)) {
                $hawaiianCount++;
            } elseif (preg_match('/^[aeiouAEIOUhklmnpwHKLMNPW]+$/', $cleanWord)) {
                $hawaiianCount++;
            }
        }
        
        return $hawaiianCount / count($words);
    }

    /**
     * Fetch and save raw HTML content
     */
    private function saveRaw($parser, $source) {
        $this->funcName = "saveRaw";
        $title = $source['title'];
        $sourceName = $source['sourcename'];
        $sourceID = $source['sourceid'];
        $url = $source['link'];
        $this->log("SourceID: $sourceID, Title: $title; SourceName: $sourceName; Link: $url");

        if (!$url) {
            $this->log("No url for sourceid $sourceID");
            return null;
        }

        $text = $parser->getContents($url, []);
        $this->log("Read " . strlen($text) . " characters from $url");

        // Check if content is actually empty (network/URL error)
        $trimmedText = trim($text);
        if ($trimmedText === '' || strlen($trimmedText) < 50) {
            $this->log("No content fetched (empty or < 50 chars), storing empty marker");
            $this->client->indexRaw($sourceID, "");
            return null; // Signal to caller that no content to process
        }

        // Save raw HTML to index
        // Note: If no Hawaiian sentences are found, saveContents() will replace this with empty marker
        $this->client->indexRaw($sourceID, $text);
        $this->log(strlen($text) . " characters saved to content index");

        return $text;
    }

    /**
     * Extract sentences and index to Elasticsearch
     */
    private function indexSentences($parser, $sourceID, $source, $link, $text) {
        $this->funcName = "indexSentences";
        $this->log("({$parser->logName},$link," . strlen($text) . " characters)");
        
        if ($text) {
            $sentences = $parser->extractSentencesFromHTML($text);
        } else {
            $sentences = $parser->extractSentences($link);
        }
        
        $this->log(sizeof($sentences) . " sentences");

        if (empty($sentences)) {
            $this->log("No sentences extracted");
            return 0;
        }

        // Verify all sentences are valid UTF-8 (should be handled by parser, but check anyway)
        foreach ($sentences as $i => $sentence) {
            if (!mb_check_encoding($sentence, 'UTF-8')) {
                $this->log("WARNING: Sentence $i has invalid UTF-8 encoding after extraction");
                // This should not happen if TextProcessor.convertEncoding() is working correctly
                error_log("Invalid UTF-8 in sentence $i from sourceID $sourceID: " . bin2hex(substr($sentence, 0, 50)));
            }
        }

        // Calculate Hawaiian word ratio
        $fullText = implode(" ", $sentences);
        $hawaiianWordRatio = $this->calculateHawaiianWordRatio($fullText);
        
        $this->log("Hawaiian word ratio: $hawaiianWordRatio");

        // Prepare source data
        $sourceData = [
            'sourceid' => $sourceID,
            'sourcename' => $source['sourcename'] ?? '',
            'groupname' => $source['groupname'] ?? $parser->groupname ?? '',
            'authors' => $source['authors'] ?? $source['author'] ?? '',
            'date' => $source['date'] ?? '',
            'title' => $source['title'] ?? '',
            'link' => $link
        ];

        try {
            // Index document and sentences to Elasticsearch
            $this->client->indexDocumentAndSentences(
                $sourceID,
                $sourceData,
                $fullText,
                $sentences,
                $hawaiianWordRatio
            );
            
            $this->log("Successfully indexed " . count($sentences) . " sentences for sourceID $sourceID");
            return count($sentences);
            
        } catch (Exception $e) {
            $this->log("Error indexing to Elasticsearch: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if raw HTML content exists in the content index
     */
    private function hasRaw($sourceID) {
        try {
            $raw = $this->client->getDocumentRaw($sourceID);
            return ($raw !== null && $raw !== '');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if retrieval was attempted (null = never tried, "" or content = was attempted)
     */
    private function wasRetrievalAttempted($sourceID) {
        try {
            $raw = $this->client->getDocumentRaw($sourceID);
            return ($raw !== null); // null = never tried, "" or content = was attempted
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get raw HTML content from the content index
     */
    private function getRawText($sourceID) {
        try {
            return $this->client->getDocumentRaw($sourceID);
        } catch (Exception $e) {
            $this->log("Error getting raw text: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if document already exists in Elasticsearch documents index
     */
    private function documentExists($sourceID) {
        try {
            $doc = $this->client->getDocumentOutline($sourceID);
            return ($doc !== null);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get sentence count for a sourceID
     */
    private function getSentenceCount($sourceID) {
        try {
            $result = $this->client->getSentencesBySourceID($sourceID);
            return $result && isset($result['sentences']) ? count($result['sentences']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Process and save a single document
     */
    public function saveContents($parser, $sourceID) {
        $this->funcName = "saveContents";
        $this->log("({$parser->logName},$sourceID)");
        
        $force = $this->options['force'] ?? false;
        $resplit = $this->options['resplit'] ?? false;
        
        if ($sourceID <= 0) {
            $this->log("Invalid sourceID $sourceID");
            return 0;
        }

        // For Elasticsearch, we get source info from the parser/options
        // since we don't have a MySQL database to query
        $source = $this->options['sources'][$sourceID] ?? [];
        if (empty($source)) {
            $this->log("No source information found for sourceID $sourceID");
            return 0;
        }
        
        // If this is a newly created source, treat it as if force=true
        $isNewlyCreated = $source['_newly_created'] ?? false;
        if ($isNewlyCreated) {
            $force = true;
            $this->log("Source was just created - forcing initial processing");
        }

        $link = $source['link'];
        $sourceName = $source['sourcename'];
        $groupname = $source['groupname'] ?? $parser->groupname ?? null;
        
        $sentenceCount = $this->getSentenceCount($sourceID);
        $hasRawContent = $this->hasRaw($sourceID);
        $wasAttempted = $this->wasRetrievalAttempted($sourceID);
        $hasDocument = $this->documentExists($sourceID);
        
        $msg = ($sentenceCount) ? "$sentenceCount Sentences present for $sourceID" : "No sentences for $sourceID";
        $msg .= ", link: $link";
        $msg .= ", hasRaw: " . (($hasRawContent) ? "yes" : "no");
        $msg .= ", wasAttempted: " . (($wasAttempted) ? "yes" : "no");
        $msg .= ", hasDocument: " . (($hasDocument) ? "yes" : "no");
        $msg .= ", resplit: " . (($resplit) ? "yes" : "no");
        $msg .= ", force: " . (($force) ? "yes" : "no");
        $this->log($msg);

        // Use the processing logger to track this operation
        return $this->client->loggedOperation(
            'save_contents',
            function() use ($parser, $source, $sourceID, $link, $hasRawContent, $wasAttempted, $sentenceCount, $force, $resplit) {
                $text = "";
                $didWork = 0;
                $didFetchRaw = false;
                
                // Decide whether to fetch HTML:
                // - If never attempted (!$wasAttempted), always fetch
                // - If attempted but empty ($wasAttempted && !$hasRawContent), only re-fetch with --force
                // - If has content ($hasRawContent), only re-fetch with --force
                if (!$wasAttempted || $force) {
                    $this->log("Fetching HTML (wasAttempted=$wasAttempted, hasRaw=$hasRawContent, force=$force)");
                    $parser->initialize($link);
                    $text = $this->saveRaw($parser, $source);
                    $this->log("Fetched " . strlen($text) . " bytes of HTML");
                    $didFetchRaw = true;
                } else {
                    if ($hasRawContent) {
                        // Only retrieve raw text if we need to reprocess sentences
                        if (!$sentenceCount || $resplit) {
                            $this->log("Retrieving existing raw text to reprocess sentences");
                            $text = $this->getRawText($sourceID);
                        } else {
                            $this->log("Raw text and sentences already exist, skipping (use --force to reprocess)");
                        }
                    } else {
                        // Empty marker means "attempted but no Hawaiian content"
                        $this->log("Source was attempted but had no Hawaiian content, skipping (use --force to retry)");
                    }
                }

                // If --force is specified, always process sentences
                // Otherwise, only process if not present or resplit requested
                $this->log("About to index sentences: text=" . strlen($text) . " bytes, sentenceCount=$sentenceCount, force=$force");
                if ($text && (!$sentenceCount || $force || $resplit)) {
                    $this->log("Calling indexSentences");
                    $didWork = $this->indexSentences($parser, $sourceID, $source, $link, $text);
                    $this->log("indexSentences returned: $didWork");
                    
                    // If no sentences were extracted and we just fetched raw content,
                    // replace it with empty marker to indicate "attempted but no Hawaiian content"
                    if ($didWork == 0 && $didFetchRaw && $text) {
                        $this->log("No Hawaiian sentences found in fetched content, storing empty marker");
                        $this->client->indexRaw($sourceID, "");
                    }
                } else {
                    $this->log("Skipping indexSentences (text is empty or conditions not met)");
                }
                
                return $didWork;
            },
            [
                'sourceID' => $sourceID,
                'groupname' => $groupname,
                'parserKey' => $parser->logName ?? null,
                'metadata' => ['link' => $link, 'sourcename' => $sourceName, 'force' => $force, 'resplit' => $resplit]
            ]
        );
    }

    /**
     * Process documents from a parser's document list
     */
    public function getAllDocuments() {
        $this->funcName = "getAllDocuments";
        $this->log($this->options, "options");

        $force = $this->options['force'] ?? false;
        $parserkey = $this->options['parserkey'] ?? '';
        $singleSourceID = $this->options['sourceid'] ?? 0;
        $minSourceID = $this->options['minsourceid'] ?? 0;
        $maxSourceID = $this->options['maxsourceid'] ?? PHP_INT_MAX;
        
        // Start batch processing log
        $batchLogID = $this->client->startProcessingLog(
            'get_all_documents',
            null,
            null,
            $parserkey,
            ['options' => $this->options]
        );

        $parser = null;
        if ($parserkey) {
            $parser = $this->getParser($parserkey);
        }

        if (!$parser) {
            $this->log("No parser specified or found");
            return;
        }

        // Get document list from parser
        $docs = [];
        if ($singleSourceID) {
            // Process single source - fetch metadata from Elasticsearch
            $sourceMetadata = $this->client->getSourceById($singleSourceID);
            
            if (!$sourceMetadata) {
                $this->log("No source metadata found for sourceID $singleSourceID");
                echo "ERROR: Source $singleSourceID not found in Elasticsearch\n";
                return;
            }
            
            // Add to sources array for processing
            $this->options['sources'][$singleSourceID] = $sourceMetadata;
            $docs = [$sourceMetadata];
            echo "Processing single source: {$sourceMetadata['sourcename']} (ID: $singleSourceID)\n";
        } else {
            // Get all documents from parser
            $docs = $parser->getDocumentList();
        }

        echo sizeof($docs) . " documents found\n";
        $this->debugPrint($docs);

        $indexed = 0;
        $skipped = 0;
        $errors = 0;
        $i = 0;

        foreach ($docs as $source) {
            if ($i >= $this->maxrows) {
                echo "Ending because reached maxrows {$this->maxrows}\n";
                break;
            }

            $sourceName = $source['sourcename'] ?? 'Unknown';
            $link = $source['url'] ?? $source['link'] ?? '';
            
            if (!$link) {
                $this->log("Skipping item with no URL: $sourceName");
                $skipped++;
                continue;
            }
            
            $source['link'] = $link;
            
            // Check if source already exists by link
            if (!isset($source['sourceid'])) {
                $existingSource = $this->client->getSourceByLink($link);
                if ($existingSource) {
                    $source['sourceid'] = $existingSource['sourceid'];
                }
            }
            $sourceID = $source['sourceid'] ?? null;

            if ($sourceID && ($sourceID < $minSourceID || $sourceID > $maxSourceID)) {
                $this->log("Skipping sourceid $sourceID (out of range)");
                $skipped++;
                continue;
            }

            $this->log($source, "page");
            $title = $source['title'] ?? '';
            $author = $source['author'] ?? $source['authors'] ?? '';
            
            if (!$sourceID) {
                // New source - need to create it
                echo "[" . ($i + 1) . "/" . sizeof($docs) . "] New source: $sourceName... ";
                
                $params = [
                    'link' => $link,
                    'groupname' => $parser->groupname,
                    'date' => $source['date'] ?? null,
                    'title' => $title,
                    'authors' => $author,
                ];
                
                $this->log($params, "Adding source $sourceName");
                $sourceID = $this->client->addSource($sourceName, $params);
                
                if ($sourceID) {
                    $this->log("Added sourceID $sourceID");
                    $source['sourceid'] = $sourceID;
                    $source['_newly_created'] = true; // Mark as newly created to force processing
                    echo "Created ID: $sourceID... ";
                } else {
                    echo "FAILED to create source\n";
                    $this->log("Failed to add source");
                    $errors++;
                    continue;
                }
            } else {
                echo "[" . ($i + 1) . "/" . sizeof($docs) . "] Processing: $sourceName (ID: $sourceID)... ";
            }
            
            // Store source info for later use
            $this->options['sources'][$sourceID] = $source;
            
            try {
                $count = $this->saveContents($parser, $sourceID);
                
                if ($count > 0) {
                    echo "SUCCESS ($count sentences)\n";
                    $indexed++;
                } else {
                    echo "SKIP (no sentences)\n";
                    $skipped++;
                }
                
            } catch (Exception $e) {
                echo "ERROR: " . $e->getMessage() . "\n";
                $this->log("Error processing $sourceID: " . $e->getMessage());
                $errors++;
            }

            $i++;
            usleep(100000); // 100ms delay to avoid overwhelming services
        }
        
        // Complete batch processing log
        if ($batchLogID) {
            $this->client->completeProcessingLog($batchLogID, 'completed', $indexed);
        }

        echo "\n=== Indexing Summary ===\n";
        echo "Total sources: " . sizeof($docs) . "\n";
        echo "Indexed: $indexed\n";
        echo "Skipped: $skipped\n";
        echo "Errors: $errors\n";
    }

    /**
     * Delete existing documents by groupname before re-indexing
     */
    public function deleteByGroupname($groupname) {
        $this->funcName = "deleteByGroupname";
        $this->log("Deleting all documents with groupname: $groupname");
        
        try {
            $stats = $this->client->deleteByGroupname($groupname);
            $this->log("Deleted {$stats['documents']} documents, {$stats['sentences']} sentences, {$stats['metadata']} metadata");
            return $stats;
        } catch (Exception $e) {
            $this->log("Error deleting by groupname: " . $e->getMessage());
            throw $e;
        }
    }
}
?>

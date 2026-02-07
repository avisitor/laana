<?php
namespace Noiiolelo\Providers\Elasticsearch;

use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\GrammarScanner;

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

require_once __DIR__ . '/../../db/parsehtml.php';
require_once __DIR__ . '/../../db/LoggingTrait.php';
require_once __DIR__ . '/../../scripts/parsers.php';
use Noiiolelo\LoggingTrait;

class ElasticsearchSaveManager {
    use LoggingTrait;
    
    private $client;
    private $parsers;
    private $debug = false;
    private $verbose = true;
    private $maxrows = 20000;
    private $options = [];
    private $integrityReport = null;
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
        
        if (isset($GLOBALS['parsermap']) && is_array($GLOBALS['parsermap'])) {
            $this->parsers = $GLOBALS['parsermap'];
        } else {
            $this->parsers = is_array($parsermap) ? $parsermap : [];
        }
        
        if (isset($options['debug'])) {
            $this->setDebug($options['debug']);
            // Set global debug flag for parsehtml.php
            setDebug($options['debug']);
        }
        if (isset($options['verbose'])) {
            $this->verbose = $options['verbose'];
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
        
        $this->outputLine("ElasticsearchSaveManager initialized");
        $this->outputLine("Documents index: {$this->client->getDocumentsIndexName()}");
        $this->outputLine("Sentences index: {$this->client->getSentencesIndexName()}");
        $this->outputLine("Processing logs: Elasticsearch (processing-logs index)");
        
        
        // Only run integrity check if orphan check is requested
        // Note: PHP converts hyphens to underscores in option names
        $checkOrphans = (isset($options['check-orphans']) && $options['check-orphans']) || 
                        (isset($options['check_orphans']) && $options['check_orphans']);
        
        $this->integrityReport = null;
        if ($checkOrphans) {
            $integrity = $this->client->checkSourceIntegrity(true);
            $this->integrityReport = $integrity; // Store for later use
        } else {
            $integrity = null;
        }
        
        if ($integrity && ($integrity['status'] === 'warning' || $integrity['status'] === 'error')) {
            $this->outputLine("\n⚠️  SOURCE INTEGRITY CHECK:");
            $this->outputLine("Status: {$integrity['status']}");
            $this->output("Counter: {$integrity['counter_value']}, ");
            $this->output("Max in metadata: {$integrity['max_in_metadata']}, ");
            $this->output("Max in documents: {$integrity['max_in_documents']}, ");
            $this->outputLine("Max in sentences: {$integrity['max_in_sentences']}");
            
            foreach ($integrity['warnings'] as $warning) {
                $this->outputLine("  - $warning");
            }
            
            // Display orphan details if any
            if (!empty($integrity['orphaned_documents'])) {
                $this->outputLine("\n🔴 Orphaned documents (in documents index but not in metadata): " . count($integrity['orphaned_documents']));
                $this->output("   IDs: " . implode(', ', array_slice($integrity['orphaned_documents'], 0, 10)));
                if (count($integrity['orphaned_documents']) > 10) {
                    $this->output(" ... and " . (count($integrity['orphaned_documents']) - 10) . " more");
                }
                $this->outputLine("");
            }
            
            if (!empty($integrity['orphaned_sentences'])) {
                $this->outputLine("🔴 Sources with orphaned sentences (in sentences index but not in metadata): " . count($integrity['orphaned_sentences']));
                $this->output("   Source IDs: " . implode(', ', array_slice($integrity['orphaned_sentences'], 0, 10)));
                if (count($integrity['orphaned_sentences']) > 10) {
                    $this->output(" ... and " . (count($integrity['orphaned_sentences']) - 10) . " more");
                }
                $this->outputLine("");
            }
            
            if (!empty($integrity['empty_metadata'])) {
                $this->outputLine("🔴 Empty metadata records (in metadata but no documents or sentences): " . count($integrity['empty_metadata']));
                $this->output("   Source IDs: " . implode(', ', array_slice($integrity['empty_metadata'], 0, 10)));
                if (count($integrity['empty_metadata']) > 10) {
                    $this->output(" ... and " . (count($integrity['empty_metadata']) - 10) . " more");
                }
                $this->outputLine("");
            }
            
            $this->outputLine("");
        }
        $this->outputLine("");
    }

    public function getParserKeys() {
        return join(",", array_keys($this->parsers));
    }
    
    public function getParser($key) {
        $normalizedKey = is_string($key) ? strtolower(trim($key)) : $key;
        if (is_string($normalizedKey) && $normalizedKey === '') {
            $normalizedKey = null;
        }
        if ($normalizedKey !== null && isset($this->parsers[$normalizedKey])) {
            $parser = $this->parsers[$normalizedKey];
            // Set debug flag on parser if manager has it enabled
            if ($this->debug && method_exists($parser, 'setDebug')) {
                $parser->setDebug($this->debug);
            }
            return $parser;
        }
        return null;
    }

    public function getDocumentListForParser(string $parserKey): array
    {
        $parser = $this->getParser($parserKey);
        if (!$parser) {
            return [];
        }
        return $parser->getDocumentList();
    }

    /**
     * Get the stored integrity report
     */
    public function getIntegrityReport() {
        return $this->integrityReport;
    }

    /**
     * Get the maxrows setting
     */
    public function getMaxrows() {
        return $this->maxrows;
    }

    /**
     * Get the Elasticsearch client
     */
    public function getClient() {
        return $this->client;
    }

    /**
     * Public method to output a message
     */
    public function out($message) {
        $this->output($message);
    }

    /**
     * Public method to output a message with newline
     */
    public function outLine($message) {
        $this->outputLine($message);
    }

    protected function buildSummary($parserName, $documentsProcessed, $documentsNewOrUpdated, $sentencesNew) {
        return [
            'parser' => $parserName,
            'documents_processed' => (int)$documentsProcessed,
            'documents_new_or_updated' => (int)$documentsNewOrUpdated,
            'sentences_new' => (int)$sentencesNew,
        ];
    }
    
    /**
     * Fix integrity issues found in the integrity check
     */
    public function fixIntegrityIssues(): array {
        if (!$this->integrityReport) {
            return ['error' => 'No integrity report available'];
        }
        
        $hasIssues = !empty($this->integrityReport['orphaned_documents']) ||
                     !empty($this->integrityReport['orphaned_sentences']) ||
                     !empty($this->integrityReport['empty_metadata']);
        
        if (!$hasIssues) {
            return ['message' => 'No integrity issues to fix'];
        }
        
        $this->outputLine("🔧 Fixing integrity issues...");
        $results = $this->client->fixIntegrityIssues($this->integrityReport);
        
        $this->outputLine("✅ Fixed:");
        $this->outputLine("   - Deleted {$results['orphaned_documents_deleted']} orphaned document(s)");
        $this->outputLine("   - Deleted {$results['orphaned_sentences_deleted']} orphaned sentence(s)");
        $this->outputLine("   - Deleted {$results['empty_metadata_deleted']} empty metadata record(s)");
        
        return $results;
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
        $this->outputLine("Fetched HTML: " . strlen($text) . " characters");
        
        if ($text) {
            $sentences = $parser->extractSentencesFromHTML($text);
        } else {
            $sentences = $parser->extractSentences($link);
        }
        
        $this->log(sizeof($sentences) . " sentences");

        if (empty($sentences)) {
            $this->log("No sentences extracted - creating document record with 0 sentences");
            
            // Even with no sentences, create a document record to avoid orphaned metadata
            $sourceData = [
                'sourceid' => $sourceID,
                'sourcename' => $source['sourcename'] ?? '',
                'groupname' => $source['groupname'] ?? $parser->groupname ?? '',
                'authors' => $source['authors'] ?? $source['author'] ?? '',
                'date' => !empty($source['date']) ? $source['date'] : null,
                'title' => $source['title'] ?? '',
                'link' => $link
            ];
            
            try {
                // Create document with empty text and 0 sentences
                $this->client->indexDocumentToIndex($sourceID, $sourceData, '', 0.0, 0);
                $this->log("Created document record for sourceID $sourceID with 0 sentences");
            } catch (Exception $e) {
                $this->log("Error creating document record: " . $e->getMessage());
            }
            
            return 0;
        }

        // Verify and fix any sentences with invalid UTF-8 (defense in depth)
        foreach ($sentences as $i => $sentence) {
            if (!mb_check_encoding($sentence, 'UTF-8')) {
                $this->log("WARNING: Sentence $i has invalid UTF-8 encoding after extraction - fixing");
                error_log("Invalid UTF-8 in sentence $i from sourceID $sourceID before fix: " . bin2hex(substr($sentence, 0, 50)));
                
                // Fix invalid UTF-8
                $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $sentence);
                if ($cleaned !== false) {
                    $sentences[$i] = $cleaned;
                } else {
                    // Fallback: use mb_convert_encoding
                    $sentences[$i] = mb_convert_encoding($sentence, 'UTF-8', 'UTF-8');
                }
                
                error_log("Fixed UTF-8 in sentence $i: " . substr($sentences[$i], 0, 50));
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
            'date' => !empty($source['date']) ? $source['date'] : null,
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
            $result = $this->client->getSentencesBySourceID((string)$sourceID);
            if (!$result) {
                $this->debugPrint("  DEBUG: getSentenceCount: No result for sourceID $sourceID");
                return 0;
            }
            if (!isset($result['sentences'])) {
                $this->debugPrint("  DEBUG: getSentenceCount: No sentences key in result for sourceID $sourceID. Keys: " . implode(', ', array_keys($result)));
                return 0;
            }
            $count = count($result['sentences']);
            $this->debugPrint("  DEBUG: getSentenceCount: Found $count sentences for sourceID $sourceID");
            return $count;
        } catch (Exception $e) {
            $this->output("  DEBUG: getSentenceCount: Exception for sourceID $sourceID: " . $e->getMessage() );
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
                $textLen = is_string($text) ? strlen($text) : 0;
                $textType = gettype($text);
                $textBool = $text ? 'truthy' : 'falsy';
                $condition1 = !$sentenceCount ? 'true' : 'false';
                $condition2 = $force ? 'true' : 'false';
                $condition3 = $resplit ? 'true' : 'false';
                $this->log("About to index sentences: text=$textLen bytes (type=$textType, bool=$textBool), sentenceCount=$sentenceCount, force=$force, resplit=$resplit");
                $this->log("Condition breakdown: \$text=$textBool, !sentenceCount=$condition1, force=$condition2, resplit=$condition3");
                
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
                
                $this->log("saveContents returning: $didWork");
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
            $this->log("No parser specified or found - skipping document processing");
            // If only checking orphans, that's fine - no actual indexing needed
            if (empty($this->options['check-orphans'])) {
                $availableParsers = implode(', ', array_keys($this->parsers ?? []));
                $this->outputLine("ERROR: Parser is required for document indexing");
                $this->outputLine("Provided parser: " . ($parserkey ?: '[none]'));
                $this->outputLine("Available parsers: " . ($availableParsers ?: '[none]'));
            }
            return $this->buildSummary($parserkey ?: null, 0, 0, 0);
        }

        // Get document list from parser
        $docs = [];
        if ($singleSourceID) {
            // Process single source - fetch metadata from Elasticsearch
            $sourceMetadata = $this->client->getSourceById($singleSourceID);
            
            if (!$sourceMetadata) {
                $this->log("No source metadata found for sourceID $singleSourceID");
                $this->outputLine("ERROR: Source $singleSourceID not found in Elasticsearch");
                return $this->buildSummary($parser->logName ?? ($parserkey ?: null), 0, 0, 0);
            }
            
            // Add to sources array for processing
            $this->options['sources'][$singleSourceID] = $sourceMetadata;
            $docs = [$sourceMetadata];
            $this->outputLine("Processing single source: {$sourceMetadata['sourcename']} (ID: $singleSourceID)");
        } else {
            if (!empty($this->options['documents']) && is_array($this->options['documents'])) {
                $docs = $this->options['documents'];
                $this->outputLine("Using provided document list (" . count($docs) . " items)");
            } else {
                // Get all documents from parser
                $this->outputLine("Fetching document list from parser {$parser->logName}...");
                $docs = $parser->getDocumentList();
            }
        }

        $this->outputLine(sizeof($docs) . " documents found");
        $this->debugPrint($docs);

        $indexed = 0;
        $skipped = 0;
        $errors = 0;
        $documentsNewOrUpdated = 0;
        $sentencesNew = 0;
        $i = 0;

        foreach ($docs as $source) {
            if ($i >= $this->maxrows) {
                $this->outputLine("Ending because reached maxrows {$this->maxrows}");
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
            
            $wasCreated = false;
            if (!$sourceID) {
                // New source - need to create it
                $this->output("[" . ($i + 1) . "/" . sizeof($docs) . "] New source: $sourceName... ");
                
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
                    $wasCreated = true;
                    $this->output("Created ID: $sourceID... ");
                } else {
                    $this->outputLine("FAILED to create source");
                    $this->outputLine("  Link: $link");
                    $this->outputLine("  Groupname: {$parser->groupname}");
                    $this->outputLine("  Parser: " . get_class($parser));
                    
                    // Get the actual error from the client
                    $lastError = $this->client->getLastError();
                    if ($lastError) {
                        $this->outputLine("  Error: " . $lastError->getMessage());
                        $this->outputLine("  Exception: " . get_class($lastError));
                        if ($lastError->getCode()) {
                            $this->outputLine("  Code: " . $lastError->getCode());
                        }
                    }
                    
                    // Check if source already exists by link
                    $existingSource = $this->client->getSourceByLink($link);
                    if ($existingSource) {
                        $this->outputLine("  Existing source ID: {$existingSource['sourceid']}");
                        $this->outputLine("  Existing source name: {$existingSource['sourcename']}");
                    }
                    
                    $this->log("Failed to add source: $sourceName, link: $link, groupname: {$parser->groupname}");
                    $errors++;
                    continue;
                }
            } else {
                $this->output("[" . ($i + 1) . "/" . sizeof($docs) . "] Processing: $sourceName (ID: $sourceID)... ");
            }
            
            // Store source info for later use
            $this->options['sources'][$sourceID] = $source;
            
            // Wrap all processing in try-catch to clean up newly created sources if anything fails
            try {
                $count = $this->saveContents($parser, $sourceID);
                $sentencesNew += (int)$count;
                
                if ($count > 0) {
                    $this->outputLine("SUCCESS ($count sentences)");
                    $indexed++;
                    $documentsNewOrUpdated++;
                } else {
                    // Check if sentences already exist
                    $sentenceCount = $this->getSentenceCount($sourceID);
                    if ($sentenceCount > 0) {
                        $this->outputLine("SKIP (already indexed with $sentenceCount sentences)");
                    } else {
                        $this->output("SKIP (no sentences extracted)");
                        
                        // Only delete metadata for newly created sources if processing completely failed
                        // (i.e., no document/raw content was created). If retrieval was attempted (even if 
                        // result was empty), it means we successfully processed the source but found no 
                        // Hawaiian content, so we should keep the metadata to avoid reprocessing it repeatedly.
                        if (!empty($source['_newly_created'])) {
                            $hasDocument = $this->documentExists($sourceID);
                            $wasAttempted = $this->wasRetrievalAttempted($sourceID);
                            
                            if (!$hasDocument && !$wasAttempted) {
                                // Complete failure - no document was created and retrieval was never attempted
                                try {
                                    $this->client->deleteSourceMetadata($sourceID);
                                    $this->outputLine(" - cleaned up empty metadata");
                                    $this->log("Deleted empty metadata for failed newly created source $sourceID");
                                } catch (Exception $e) {
                                    $this->outputLine(" - WARNING: failed to clean up metadata");
                                    $this->log("Failed to delete empty metadata for $sourceID: " . $e->getMessage());
                                }
                            } else {
                                // Successfully processed but no sentences found (empty marker stored)
                                $this->outputLine(" - source processed but has no Hawaiian sentences");
                                $this->log("Source $sourceID was successfully processed but contains no Hawaiian sentences");
                                if ($wasCreated) {
                                    $documentsNewOrUpdated++;
                                }
                            }
                        } else {
                            $this->outputLine("");
                        }
                    }
                    $skipped++;
                }
                
            } catch (Exception $e) {
                $this->outputLine("ERROR: " . $e->getMessage());
                $this->log("Error processing $sourceID: " . $e->getMessage());
                
                // If this was a newly created source that failed, delete the metadata
                if (!empty($source['_newly_created'])) {
                    try {
                        $this->client->deleteSourceMetadata($sourceID);
                        $this->outputLine("  Cleaned up metadata for failed source");
                        $this->log("Deleted metadata for failed newly created source $sourceID");
                    } catch (Exception $cleanupError) {
                        $this->outputLine("  WARNING: Failed to clean up metadata");
                        $this->log("Failed to delete metadata after error for $sourceID: " . $cleanupError->getMessage());
                    }
                }
                
                $errors++;
            }

            $i++;
            usleep(100000); // 100ms delay to avoid overwhelming services
        }
        
        // Complete batch processing log
        if ($batchLogID) {
            $this->client->completeProcessingLog($batchLogID, 'completed', $indexed);
        }

        $this->outputLine("\n=== Indexing Summary ===");
        $this->outputLine("Total sources: " . sizeof($docs));
        $this->outputLine("Indexed: $indexed");
        $this->outputLine("Skipped: $skipped");
        $this->outputLine("Errors: $errors");

        $parserName = $parser->logName ?? ($parserkey ?: null);
        return $this->buildSummary($parserName, $i, $documentsNewOrUpdated, $sentencesNew);
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

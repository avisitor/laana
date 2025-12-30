<?php
include_once __DIR__ . '/../db/parsehtml.php';
include_once __DIR__ . '/../scripts/parsers.php';
include_once __DIR__ . '/../db/LoggingTrait.php';

class SaveManager {
    use LoggingTrait;
    
    private $laana;
    private $parsers;
    private $debug = false;
    private $verbose = true;
    private $maxrows = 20000;
    private $options = [];
    private $parser = null;
    protected $logName = "SaveManager";
    protected $funcName = "";
    protected $batchLogID = null;
    protected $updates = 0;
    protected $sentenceCount = 0;
    protected $docCount = 0;
    protected $docsFound = 0;
    protected $processed = [];

    public function __construct($options = []) {
        $this->funcName = "_construct";
        global $parsermap;
        $this->laana = new Laana();
        $this->parsers = $parsermap;
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
            $this->parser = $this->getParser( $options['parserkey'] );
            $this->log( $this->parser, "parser" );
        }
        $this->options = $options;
        $this->log( $this->options, "options" );
        
        // Output initialization info
        $this->outputLine("SaveManager initialized");
        $this->outputLine("Database: laana");
        $this->outputLine("Processing logs: Database (processing_log table)");
        $this->outputLine("");
    }

    public function getParserKeys() {
        $values = join( ",", array_keys( $this->parsers ) );
        return $values;
    }
    
   public function getParser($key) {
        if (isset($this->parsers[$key])) {
            return $this->parsers[$key];
        }
        return null;
    }

    public function verboseLog( $message = '', $prefix = '' ) {
        if( $this->verbose ) {
            $this->log( $message, $prefix );
        }
    }
    
    public function verboseOutput($message) {
        //if (!isset($this->verbose) || $this->verbose !== false) {
        if( $this->verbose ) {
            echo $message;
        }
    }
    
    /**
     * Get the maxrows setting
     */
    public function getMaxrows() {
        return $this->maxrows;
    }

    /**
     * Get the Laana instance
     */
    public function getLaana() {
        return $this->laana;
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
        if( $message && !is_string( $message ) ) {
            $message = json_encode( $message );
        }
        $this->outputLine($message);
    }

    public function outBoth($message='', $prefix='') {
        $msg = '';
        if( $message && !is_string( $message ) ) {
            $msg = json_encode( $message );
        }
        if( $msg && $prefix ) {
            $msg = "$prefix: $msg";
        }
        $this->outputLine($msg);
        $this->log($message, $prefix);
    }

    // Logging methods now provided by LoggingTrait
    // (formatLog, log, debugPrint, setDebug, output, outputLine, etc.)

    private function saveRaw($parser, $source) {
        $this->funcName = "saveRaw";
        $title = $source['title'];
        $sourceName = $source['sourcename'];
        $sourceID = $source['sourceid'];
        $url = $source['link'];
        $this->log("SourceID: $sourceID, Title: $title; SourceName: $sourceName; Link: $url");

        if (!$url) {
            $this->log("No url for sourceid $sourceID");
            return;
        }

        $text = $parser->getContents($url, []);
        $this->log("Read " . strlen($text) . " characters from $url");

        $this->laana->removeContents($sourceID);
        $count = $this->laana->addRawText($sourceID, $text);

        $this->log("$count characters added to raw text table");
        return $text;
    }

    private function addSentences($parser, $sourceID, $link, $text) {
        $this->funcName = "addSentences";
        $this->log("({$parser->logName},$link," . strlen($text) . " characters)");
        if ($text) {
            $sentences = $parser->extractSentencesFromHTML($text);
        } else {
            $sentences = $parser->extractSentences($link);
        }
        $this->log(sizeof($sentences) . " sentences");

        $this->laana->removeSentences($sourceID);
        $count = $this->laana->addSentences($sourceID, $sentences);
        $this->log("$count sentences added to sentence table with sourceID $sourceID");

        $count = $this->laana->addFullText($sourceID, $sentences);
        $this->log("$count sentences added to full text table");
        $this->log("Returning from addSentences");
        return $count;
    }

    public function getFailedDocuments($parser=null) {
        $this->funcName = "getFailedDocuments";
        if( !$parser ) {
            $parser = $this->parser;
        }
        $this->log( "{$parser->logName}" );
        $db = new DB();
        $sql = "select c.sourceid, link from " . CONTENTS . " c, sources s where c.sourceid = s.sourceid and s.groupname = '{$parser->groupname}' and not c.sourceid in (select distinct sourceid from " . SENTENCES . ") order by sourceid";
        $rows = $db->getDBRows($sql);
        $this->log( sizeof($rows) . " sourceids with content but no sentences" );
        $added = 0;
        $addedDocs = 0;
        foreach ($rows as $row) {
            $this->log($row);
            $sourceID = $row['sourceid'];
            $link = $row['link'];
            $text = $this->laana->getRawText($sourceID);
            $this->log(strlen($text) . " chars of raw text read" );
            $count = $this->addSentences($parser, $sourceID, $link, $text);
            if( $count ) {
                $added += $count;
                $addedDocs++;
            }
        }
        $this->funcName = "getFailedDocuments";
        $this->log( count($rows) . " docs processed, {$added} sentences added for {$addedDocs} docs" );
    }

    public function saveContents($parser, $source) {
        $sourceID = $source['sourceid'] ?? 0;
        $this->funcName = "saveContents";
        $this->log("({$parser->logName},$sourceID)");
        $force = $this->options['force'];
        $resplit = $this->options['resplit'];
        
        if ($sourceID <= 0) {
            $this->outBoth("Invalid sourceID $sourceID");
            return 0;
        }

        $this->log($source, "source");

        $link = $source['link'];
        $sourceName = $source['sourcename'];
        $sentenceCount = $this->laana->getSentenceCountBySourceID($sourceID);
        $present = $this->laana->hasRaw($sourceID);
        $hasText = $this->laana->hasText($sourceID);

        $msg = ($sentenceCount) ? "$sentenceCount Sentences present for $sourceID" : "No sentences for $sourceID";
        $msg .= ", link: $link";
        $msg .= ", hasRaw: " . (($present) ? "yes" : "no");
        $msg .= ", hasText: " . (($hasText) ? "yes" : "no");
        $msg .= ", resplit: " . (($resplit) ? "yes" : "no");
        $msg .= ", force: " . (($force) ? "yes" : "no");
        $this->verboseLog($msg);

        // Start processing log (debug output suppressed)
        $logID = $this->laana->startProcessingLog(
            'save_contents',
            $sourceID,
            $source['groupname'] ?? null,
            $parser->logName ?? null,
            ['link' => $link, 'sourcename' => $sourceName, 'force' => $force, 'resplit' => $resplit]
        );

        $text = "";
        $finalSentenceCount = 0;
        $error = null;
        
        try {
            // Fetch HTML if: no HTML exists OR force flag is set
            if (!$present || $force) {
                $this->outputLine( "Saving raw HTML" );
                $text = $this->saveRaw($parser, $source);
            } else {
                // HTML exists - load it from database
                $text = $this->laana->getRawText($sourceID);
                $this->log("Loaded " . strlen($text) . " characters of raw HTML from database");
                $this->verboseLog("DEBUG: \$text type=" . gettype($text) . ", empty=" . (empty($text) ? "YES" : "NO") . ", bool=" . ($text ? "TRUE" : "FALSE"));
            }

            // Extract sentences if: we have HTML AND (no sentences exist OR resplit flag OR force flag)
            $this->verboseLog("DEBUG: Checking if should extract: \$text=" . ($text ? "TRUE" : "FALSE") . ", \$sentenceCount=$sentenceCount, \$resplit=$resplit, \$force=$force");
            if ($text && (!$sentenceCount || $resplit || $force)) {
                $this->log( "Saving sentences" );
                $finalSentenceCount = $this->addSentences($parser, $sourceID, $link, $text);
            } else {
                // Sentences already exist and not forcing re-extraction
                $this->verboseLog("$sourceName already has $sentenceCount sentences");
                $finalSentenceCount = 0;
            }
            
            // Complete processing log with success
            if ($logID) {
                $this->laana->completeProcessingLog($logID, 'completed', $finalSentenceCount);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            $this->log("Error in saveContents: $error");
            if ($logID) {
                $this->laana->completeProcessingLog($logID, 'failed', $finalSentenceCount, $error);
            }
            throw $e;
        }
        
        return $finalSentenceCount;
    }

    private function updateSource($doc, $source) {
        $this->funcName = "updateSource";
        $force = isset($this->options['force']) ? $this->options['force'] : false;
        $this->debuglog($source, "Parameters found in source");
        $this->debuglog($doc, "Parameters found by parser");
        // Extract valid keys
        $fields = Laana::getFields( 'sources' );
        $params = [];
        foreach( $fields as $key ) {
            $params[$key] = $doc[$key] ?? $source[$key] ?? '';
        }

        // Check if there are changes
        $doUpdate = false;
        foreach( $params as $key => $paramskey) {
            if( $paramskey ) {
                $sourcekey = $source[$key] ?? '';
                if( !$sourcekey || ($sourcekey !== $paramskey) ) {
                    $this->log("$key is to change from |$sourcekey| to |$paramskey| for " . $source['sourceid'] . " " . $source['link']);
                    $this->outputLine("$key is to change from |$sourcekey| to |$paramskey| for " . $source['sourceid'] . " " . $source['link']);
                    $source[$key] = $paramskey;
                    $doUpdate = true;
                }
            }
        }

        if ($doUpdate || $force) {
            $this->verboseLog($source, "About to call updatesourceByID");
            $this->laana->updateSourceByID($params);
        }
        return $doUpdate;
    }

    public function updateDocument($sourceID) {
        $this->funcName = "updateDocument";
        $source = $this->laana->getSource($sourceID);
        if (isset($source['groupname'])) {
            $groupname = $source['groupname'];
            $parser = $this->getParser($groupname);
            if ($parser) {
                $this->log("($sourceID,$groupname)");
                $parser->initialize($source['link']);
                $updatedSource = $this->updateSource($parser->metadata, $source);
                if ($updatedSource) {
                    $this->log("Updated source record");
                    return true;
                } else {
                    $this->verboseLog("No change to source record");
                }
            } else {
                $this->log("No parser for $groupname");
            }
        } else {
            $this->log("No registered source for $sourceID");
        }
        return false;
    }

    public function processOneSource( $sourceid ) {
        $source = $this->laana->getSource($sourceid);
        if (!$source || !isset($source['sourceid'])) {
            $this->outputLine("Error: Source $sourceid not found");
            if ($this->batchLogID) {
                $this->laana->completeProcessingLog($this->batchLogID, 'failed', 0, 'Source not found');
            }
            return;
        }
        $this->verbosePrint( $source, "Source" );
        
        $sourceID = $source['sourceid'];
        $sourceName = $source['sourcename'];
        $link = $source['link'];
        $parser = $this->getParser($source['groupname']);
        
        if (!$parser) {
            $this->outBoth("Error: No parser found for groupname '{$source['groupname']}'");
            if ($this->batchLogID) {
                $this->laana->completeProcessingLog($this->batchLogID, 'failed', 0, 'Parser not found');
            }
            return;
        }
        
        $this->verbosePrint( "Initializing parser from $link\n" );
        $parser->initialize($link);
        $this->verbosePrint( $parser->metadata, "Parser metadata" );

        $this->outputLine("Processing source: $sourceName (ID: $sourceID)");
        $this->outputLine("");
        
        $this->out("[0] Processing $sourceName (ID: $sourceID)... ");
        $count = 0;
        try {
            $updatedSource = $this->updateSource($parser->metadata, $source);
            if ($updatedSource) {
                $this->outputLine("Updated source record");
            } else {
                $this->outputLine("No change to source record");
            }
            $count = $this->saveContents($parser, $source);
            if ($count > 0) {
                $this->outputLine("SUCCESS (added $count sentences)");
            } else {
                $this->outputLine("SKIP (no new sentences)");
            }
        } catch (Exception $e) {
            $this->outputLine("ERROR: " . $e->getMessage());
        }
        
        if ($this->batchLogID) {
            $this->laana->completeProcessingLog($this->batchLogID, 'completed', $count);
        }
        
        $this->outputLine("");
        $this->outputLine("getAllDocuments: processed 1 document with $count sentences");
    }

    public function processOneDocument( $doc, $i ) {
        $this->verbosePrint($doc, "Document");
        $sourceName = $doc['sourcename'];
        if( isset( $this->processed[$sourceName] ) ) {
            $sourceUrl = $doc['url'];
            $sourceLink = $this->processed[$sourceName];
            $this->outLine( "Skipping already processed $sourceName $sourceUrl (sticking with $sourceLink)" );
            return 0;
        }
        
        $link = $doc['url'] ?? $doc['link'] ?? '';
        if (!$link) {
            $this->outBoth("Skipping item with no URL: $sourceName");
            return 0;
        }
        
        $doc['link'] = $link;
        $source = $this->laana->getSourceByLink($link);
        if( !isset($source['sourceid']) || !$source['sourceid'] ) {
            $this->outLine( "No sourceid registered for $link, checking for $sourceName" );
            // Double-check if it is an updated link
            $row = $this->laana->getSourceByName( $sourceName );
            if( isset( $row['link'] ) && $row['link'] ) {
                // Same source but new link
                $row['link'] = $link;
                $this->outBoth($row, "New link for source $sourceName" );
                //$this->verbosePrint($row, "New link for source $sourceName");
                $this->laana->updateSourceByID($row);
                $source = $row;
                // Contents may have changed, reprocess the document
                $this->laana->removecontents( $source['sourceid'] );
                $this->laana->removesentences( $source['sourceid'] );
            } else {
                $this->outLine( "No sourceName $sourceName found" );
            }
        }
        $sourceID = $source['sourceid'] ?? 0;

        if ($sourceID && ($sourceID < $this->options['minsourceid'] || $sourceID > $this->options['maxsourceid'])) {
            $this->outBoth("Skipping out of range sourceid $sourceID");
            return 0;
        }

        $this->verboseLog($source, "page");
        $parser = $this->getParser($source['groupname']);
        if (!$parser) {
            $this->outBoth($source['groupname'], "parser not found for");
            return 0;
        }
        $this->verbosePrint( "Initializing parser from $link\n" );
        $parser->initialize($link);

        if (!$sourceID) {
            // New source
            $this->outBoth($doc, "Adding source $sourceName");
            $source = $this->laana->addSource($sourceName, $params);
            //$this->outputLine("From addSource: ". var_export( $row, true ));
            $sourceID = $source['sourceid'] ?? '';
            if ($sourceID) {
                $this->outBoth("Added sourceID $sourceID");
                $index = $i + 1;
                $this->out("[$index/{$this->docsFound}] Processing $sourceName (ID: $sourceID)... ");
                try {
                    $count = $this->saveContents($parser, $source);
                    if ($count > 0) {
                        $this->outputLine("Added ($count sentences)");
                        $this->sentenceCount += $count;
                        $this->docCount++;
                    } else {
                        $this->verboseOutput("SKIP (no new sentences)\n");
                    }
                } catch (Exception $e) {
                        $this->outputLine("ERROR: " . $e->getMessage());
                }
                $this->updates++;
                return 1;
            } else {
                $this->outBoth("Failed to add source");
                return 0;
            }
        }
        
        // Known source
        // Is it within a specified range?
        if ($sourceID < $this->options['minsourceid'] || $sourceID > $this->options['maxsourceid']) {
            return 0;
        }
        
        $action = "";
        $local = $this->options['local'] ?? false;
        if (!$local) {
            // Comparing what is the parser found to what is in the DB
            $updatedSource = $this->updateSource($doc, $source);
            if ($updatedSource) {
                $this->updates++;
                $action = "Updated source record";
            } else {
                $action = "No change to source record";
            }
        }
        $index = $i + 1;
        try {
            // Check if the remote content has changed
            $count = $this->saveContents($parser, $source);
            if ($count > 0) {
                $this->outputLine("[$index/{$this->docsFound}] Processing $sourceName (ID: $sourceID) $action... SUCCESS ($count sentences)");
                $this->sentenceCount += $count;
            } else {
                $this->verboseOutput("[$index/{$this->docsFound}] Processing $sourceName (ID: $sourceID) $action... SKIP (no new sentences)\n");
            }
        } catch (Exception $e) {
            $this->outputLine("ERROR: " . $e->getMessage());
        }
        $this->log($sourceID, "Updated contents for sourceID");

        $this->processed[$sourceName] = $source['link'];
        return 1;
    }
    
    public function getAllDocuments() {
        $this->funcName = "getAllDocuments";
        $this->log($this->options, "options");

        $force = $this->options['force'] ?? false;
        $local = $this->options['local'] ?? false;
        $parserkey = $this->options['parserkey'] ?? '';
        $singleSourceID = $this->options['sourceid'] ?? 0;
        $singleSourceID = $this->options['sourceid'] ?? 0;
        $remoteID = $this->options['remote'] ?? 0;

        if( $remoteID ) {
            $source = $this->laana->getSource($remoteID);
            $link = $source['link'] ?? '';
            if( $link ) {
                $parser = $this->getParser($source['groupname']);
                $parser->initialize( $link );
                $this->updateSource( $parser->metadata, $source );
            }
            return 0;
        }
        
        // Start processing log for the batch operation (debug output suppressed)
        $this->batchLogID = $this->laana->startProcessingLog(
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

        // If processing a single source, handle it directly without bulk operations
        if ($singleSourceID) {
            return $this->processOneSource( $singleSourceID );
        }

        $this->outputLine("Starting document processing...");
        $this->outputLine("");
        
        if ($parser) {
            $this->outputLine("Fetching document list from parser {$parser->logName}...");
        } else {
            $this->outputLine("Fetching document list from database...");
        }

        $docs = []; // Sources in the wild
        $items = []; // Sources in the DB
        if ($parserkey && $local) {
            $items = $this->laana->getSources($parserkey);
            if (sizeof($items) > 0 && isset($items[0]['sourceid'])) {
                usort($items, function ($a, $b) {
                    return $a['sourceid'] <=> $b['sourceid'];
                });
            }
        } else if ($parser) {
            $docs = $parser->getDocumentList();
        } else if ($local || ($this->options['minsourceid'] > 0 && $this->options['maxsourceid'] < PHP_INT_MAX)) {
            $items = $this->laana->getSources();
        }

        if (sizeof($docs) < 1) {
            // Operating off of info in the DB
            foreach ($items as $item) {
                $parser = $this->getParser($item['groupname']);
                if (!$parser) {
                    $this->log("no source found for {$item['sourceid']}");
                } else if ($item['sourceid'] >= $this->options['minsourceid'] && $item['sourceid'] <= $this->options['maxsourceid']) {
                    $item['url'] = $item['link'];
                    array_push($docs, $item);
                    $this->verboseLog($item, "adding page to process");
                }
            }
        }

        $this->docsFound = sizeof($docs);
        $this->outputLine($this->docsFound . " documents found");
        $this->outputLine("...");
        $this->verbosePrint($docs);

        $i = 0;
        $processed = [];
        foreach ($docs as $source) {
            $this->processOneDocument( $source, $i );
            $i++;
            if ($i >= $this->maxrows) {
                $this->outputLine("Ending because reached maxrows {$this->maxrows}");
                break;
            }
        }
        $this->outputLine( "" );
        
        // Complete batch processing log
        if ($this->batchLogID) {
            $this->laana->completeProcessingLog($this->batchLogID, 'completed', $this->sentenceCount);
        }
        
        $this->outputLine("$this->funcName: updated {$this->updates} source definitions, {$this->docCount} documents and {$this->sentenceCount} sentences");
    }

    /**
     * Delete all records for a given groupname
     * 
     * @param string $groupname The groupname to delete
     * @return array Statistics about what was deleted
     */
    public function deleteByGroupname($groupname) {
        $this->funcName = "deleteByGroupname";
        $this->log("Deleting all records for groupname: $groupname");
        
        $stats = $this->laana->removeByGroupname($groupname);
        
        $this->log("Deleted {$stats['sentences']} sentence records, {$stats['contents']} content records, {$stats['sources']} source records");
        
        return $stats;
    }
}
?>

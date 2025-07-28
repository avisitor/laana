<?php
include_once __DIR__ . '/../db/parsehtml.php';
include_once __DIR__ . '/../scripts/parsers.php';

class SaveManager {
    private $laana;
    private $parsers;
    private $debug = false;
    private $maxrows = 20000;
    private $options = [];
    private $parser = null;
    protected $logName = "SaveManager";

    public function __construct($options = []) {
        $this->funcName = "_construct";
        global $parsermap;
        $this->laana = new Laana();
        $this->parsers = $parsermap;
        if (isset($options['debug'])) {
            $this->setDebug($options['debug']);
        }
        if (isset($options['maxrows'])) {
            $this->maxrows = $options['maxrows'];
        }
        if (isset($options['parserkey'])) {
            $this->parser = $this->getParser( $options['parserkey'] );
            $this->log( $this->parser, "parser" );
            //echo( "_construct {$options['parserkey']}: " . var_export( $this->parser, true ) . "\n" );
        }
        $this->options = $options;
        $this->log( $this->options, "options" );
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

    protected function formatLog( $obj, $prefix="" ) {
        if($prefix && !is_string($prefix)) {
            $prefix = json_encode( $prefix );
        }
        if( $this->funcName ) {
            $func = $this->logName . ":" . $this->funcName;
            $prefix = ($prefix) ? "$func:$prefix" : $func;
        }
        return $prefix;
    }
    
    public function log( $obj, $prefix="") {
        $prefix = $this->formatLog( $obj, $prefix );
        debuglog( $obj, $prefix );
    }
    public function debugPrint( $obj, $prefix="" ) {
        if( $this->debug ) {
            $text = $this->formatLog( $obj, $prefix );
            printObject( $obj, $text );
        }
    }
    private function setDebug($debug) {
        $this->debug = $debug;
        setDebug($debug);
    }

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

    public function saveContents($parser, $sourceID) {
        $this->funcName = "saveContents";
        $this->log("({$parser->logName},$sourceID)");
        $force = $this->options['force'];
        $resplit = $this->options['resplit'];
        
        if ($sourceID <= 0) {
            $this->log("Invalid sourceID $sourceID");
            return;
        }

        $source = $this->laana->getSource($sourceID);
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
        $this->log($msg);

        $didWork = 0;
        $text = "";
        if (!$present || $force) {
            $parser->initialize($link);
            $text = $this->saveRaw($parser, $source);
        } else {
            if ($present) {
                $this->log("$sourceName already has raw text");
                if (!$sentenceCount || $resplit) {
                    $text = $this->laana->getRawText($sourceID);
                }
            }
        }

        if ($text) {
            $didWork = 1;
            $this->addSentences($parser, $sourceID, $link, $text);
        }
        return $didWork;
    }

    private function updateSource($parser, $source) {
        $this->funcName = "updateSource";
        $force = isset($this->options['force']) ? $this->options['force'] : false;
        $params = [
            'title' => $parser->title,
            'date' => $parser->date,
        ];
        if ($parser->authors && !$source['author']) {
            $params['author'] = $parser->authors;
        }
        $date = $source['date'] ?? '';
        if ($parser->date && ($parser->date != $date)) {
            $params['date'] = $parser->date;
        }
        $this->log($params, "Parameters found by parser");
        
        $doUpdate = false;
        foreach (array_keys($params) as $key) {
            if (!isset($source[$key]) || ($source[$key] !== $params[$key] && $params[$key])) {
                $sourcekey = $source[$key] ?? '';
                $paramskey = $params[$key] ?? '';
                $this->log("$key is to change from $sourcekey to $paramskey");
                $source[$key] = $params[$key];
                $doUpdate = true;
            }
        }

        if ($doUpdate || $force) {
            $this->log($source, "About to call updatesourceByID");
            $this->laana->updateSourceByID($source);
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
                $updatedSource = $this->updateSource($parser, $source);
                if ($updatedSource) {
                    $this->log("Updated source record");
                } else {
                    $this->log("No change to source record");
                }
            } else {
                $this->log("No parser for $groupname");
            }
        } else {
            $this->log("No registered source for $sourceID");
        }
    }

    public function getAllDocuments() {
        $this->funcName = "getAllDocuments";
        $this->log($this->options, "options");

        $force = $this->options['force'] ?? false;
        $local = $this->options['local'] ?? false;
        $parserkey = $this->options['parserkey'] ?? '';
        $singleSourceID = $this->options['sourceid'] ?? 0;

        $parser = null;
        if ($parserkey) {
            $parser = $this->getParser($parserkey);
        }

        $pages = []; // Sources in the wild
        $items = []; // Sources in the DB
        if ($singleSourceID) {
            $items = [$this->laana->getSource($singleSourceID)];
        } else if ($parserkey && $local) {
            $items = $this->laana->getSources($parserkey);
        } else if ($parser) {
            $pages = $parser->getPageList();
        } else if ($local || ($this->options['minsourceid'] > 0 && $this->options['maxsourceid'] < PHP_INT_MAX)) {
            $items = $this->laana->getSources();
        }

        if (sizeof($pages) < 1) {
            // Operating off of info in the DB
            foreach ($items as $item) {
                $parser = $this->getParser($item['groupname']);
                if (!$parser) {
                    $this->log("no source found for {$item['sourceid']}");
                } else if ($item['sourceid'] >= $this->options['minsourceid'] && $item['sourceid'] <= $this->options['maxsourceid']) {
                    $item['url'] = $item['link'];
                    if (!isset($item['author']) || !$item['author']) {
                        $item['author'] = $item['authors'];
                    }
                    array_push($pages, $item);
                    $this->log($item, "adding page to process");
                }
            }
        }

        if (sizeof($pages) > 0 && isset($pages[0]['sourceid'])) {
            usort($pages, function ($a, $b) {
                return $a['sourceid'] <=> $b['sourceid'];
            });
        }
        echo sizeof($pages) . " pages found\n...\n";
        $this->debugPrint($pages);

        $docs = 0;
        $updates = 0;
        $i = 0;
        foreach ($pages as $source) {
            $sourceName = $source['sourcename'];
            $link = $source['url'] ?? '';
            if (!$link) {
                $this->log("Skipping item with no URL: $sourceName");
                continue;
            }
            $source['link'] = $link;
            if (!isset($source['sourceid'])) {
                $src = $this->laana->getSourceByLink($link);
                if (isset($src['sourceid'])) {
                    $source['sourceid'] = $src['sourceid'];
                }
            }
            $sourceID = $source['sourceid'] ?? '';

            if ($sourceID && ($sourceID < $this->options['minsourceid'] || $sourceID > $this->options['maxsourceid'])) {
                $this->log("Skipping sourceid $sourceID");
                continue;
            }

            $this->log($source, "page");
            $title = $source['title'];
            $author = $source['author'] ?? '';
            $parser = $this->getParser($source['groupname']);
            if (!$parser) {
                $this->log($source['groupname'], "parser for $sourceID groupname");
                continue;
            }
            if (!$local) {
                $parser->initialize($link);
            }

            if (!$sourceID) {
                // New source
                $params = [
                    'link' => $link,
                    'groupname' => $parser->groupname,
                    'date' => $parser->date,
                    'title' => $title,
                    'authors' => $author,
                    'sourcename' => $sourceName,
                ];
                $this->log($params, "Adding source $sourceName");
                $row = $this->laana->addSource($sourceName, $params);
                $sourceID = isset($row['sourceid']) ? $row['sourceid'] : '';
                if ($sourceID) {
                    $this->log("Added sourceID $sourceID");
                    $docs += $this->saveContents($parser, $sourceID);
                    $updates++;
                } else {
                    $this->log("Failed to add source");
                }
            } else {
                // Known source
                if ($sourceID >= $this->options['minsourceid'] && $sourceID <= $this->options['maxsourceid']) {
                    if (!$local) {
                        $updatedSource = $this->updateSource($parser, $source);
                        if ($updatedSource) {
                            $updates++;
                            $this->log("Updated source record");
                        } else {
                            $this->log("No change to source record");
                        }
                    }
                    $docs += $this->saveContents($parser, $sourceID);
                    $this->log($sourceID, "Updated contents for sourceID");
                } else {
                    $this->log($sourceID, "Out of range");
                }
            }

            $i++;
            if ($i >= $this->maxrows) {
                echo "Ending because reached maxrows {$this->maxrows}\n";
                break;
            }
        }
        echo "$this->funcName: updated $updates source definitions and $docs documents
";
    }
}
?>

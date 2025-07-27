<?php
include_once __DIR__ . '/../db/parsehtml.php';
include_once __DIR__ . '/../scripts/parsers.php';

function getParser( $key ) {
    global $parsermap;
    if( isset( $parsermap[$key] ) ) {
        return $parsermap[$key];
    } else {
        return null;
    }
}

function printBoth( $obj, $intro ) {
    printObject( $obj, $intro );
    debuglog( $obj, $intro );
}

function saveRaw( $parser, $source ) {
    $funcName = "saveRaw";
    $title = $source['title'];
    $sourceName = $source['sourcename'];
    $sourceID = $source['sourceid'];
    $url = $source['link'];
    printBoth( "SourceID: $sourceID, Title: $title; SourceName: $sourceName; Link: $url", $funcName );
    //printObject( $parser, $funcName );
    if( !$url ) {
        printObject( "No url", $funcName );
        debuglog( "No url", $funcName );
        return;
    }

    $laana = new Laana();
    //$parser->initialize( $url );
    $text = $parser->getContents( $url, [] );
    printBoth( "Read " . strlen($text) . " characters from $url", $funcName );
    //echo "$text\n";
    
    $laana->removeContents( $sourceID );
    $count = $laana->addRawText( $sourceID, $text );
    
    printBoth( "$count characters added to raw text table", $funcName );
    return $text;
}

function addSentences( $parser, $sourceID, $link, $text ) {
    $funcName = "addSentences";
    printBoth( "({$parser->logName},$link," . strlen($text) . " characters)", $funcName );
    if( $text ) {
        //echo "$text\n";
        $sentences = $parser->extractSentencesFromHTML( $text );
    } else {
        $sentences = $parser->extractSentences( $link );
    }
    printBoth( sizeof($sentences) . " sentences", $funcName );
    
    $laana = new Laana();
    $laana->removeSentences( $sourceID );
    $count = $laana->addSentences( $sourceID, $sentences);
    printBoth( "$count sentences added to sentence table with sourceID $sourceID", $funcName );

    $count = $laana->addFullText( $sourceID, $sentences);
    printBoth( "$count sentences added to full text table", $funcName );
    printBoth( "Returning from addSentences", $funcName );
}

// A few documents we failed to extract sentences from
function getFailedDocuments( $parser ) {
    $funcName = "getFailedDocuments";
    $db = new DB();
    $sql = 'select sourceid from " . CONTENTS . " where sourceid not in (select distinct sourceid from " . SENTENCES . ") order by sourceid';
    $rows = $db->getDBRows( $sql );
    //echo( "getFailedDocuments: " . var_export( $rows, true ) . "\n" );
    foreach( $rows as $row ) {
        printBoth( $row, $funcName );
        $sourceID = $row['sourceid'];
        $link = $row['link'];
        $laana = new Laana();
        $text = $laana->getRawText( $sourceID );
        printBoth( strlen($text) . " chars of raw text read", $funcName  );
        addSentences( $parser, $sourceID, $link, $text );
    }
}

function saveContents( $parser, $sourceID, $options ) {
    $funcName = "saveContents";
    printBoth( "({$parser->logName},$sourceID)", $funcName );
    //printBoth( $parser, "$funcName parser" );
    $force = $options['force'];
    $resplit = $options['resplit'];
    $debug = $options['debug'];
    setDebug( $debug );

    if( $sourceID <= 0 ) {
        printBoth( "Invalid sourceID $sourceID", $funcName );
        return;
    }
    
    $laana = new Laana();
    $source = $laana->getSource( $sourceID );
    printBoth( $source, "$funcName source" );
    
    $link = $source['link'];
    $sourceName = $source['sourcename'];
    $sentenceCount = $laana->getSentenceCountBySourceID( $sourceID );
    $text = "";
    $present = $laana->hasRaw( $sourceID );
    $hasText = $laana->hasText( $sourceID );
    $msg = ($sentenceCount) ? "$sentenceCount Sentences present for $sourceID" : "No sentences for $sourceID";
    $msg .= ", link: $link";
    $msg .= ", hasRaw: " . (($present) ? "yes" : "no");
    $msg .= ", hasText: " . (($hasText) ? "yes" : "no");
    $msg .=  ", resplit: " .  (($resplit) ? "yes" : "no");
    $msg .=  ", force: " .  (($force) ? "yes" : "no");
    printBoth( $msg, $funcName );

    $didWork = 0;
    $text = "";
    if( !$present || $force ) {
        $parser->initialize( $link );
        //printBoth( $parser, "parser after initialize $link" );
        $text = saveRaw( $parser, $source );
    } else {
        if( $present ) {
            printBoth( "$sourceName already has raw text", $funcName );
            if( !$sentenceCount || $resplit ) {
                $text = $laana->getRawText( $sourceID );
            }
        }
    }
    if( $text ) {
        $didWork = 1;
        addSentences( $parser, $sourceID, $link, $text );
    }
   return $didWork;
}

function updateSource( $parser, $source, $options ) {
    $funcName = "updateSource";
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $params = [
        'title' => $parser->title,
        'date' => $parser->date,
    ];
    if( $parser->authors && !$source['author'] ) {
        $params['author'] = $parser->authors;
    }
    $date = $source['date'] ?? '';
    if( $parser->date && ($parser->date != $date ) ) {
        $params['date'] = $parser->date;
    }
    printBoth( "Parameters found by parser: " . var_export( $params, true ),
               $funcName );
    $doUpdate = false;
    foreach( array_keys( $params ) as $key ) {
        if( !isset($source[$key]) || ($source[$key] !== $params[$key] && $params[$key]) ) {
            printBoth( "$key is to change from {$source[$key]} to {$params[$key]}", $funcName );
            $source[$key] = $params[$key];
            $doUpdate = true;
        }
    }

    if( $doUpdate || $force ) {
        // Only update the source record on force and if the values have changed in the field
        printBoth( "About to call updatesourceByID: " .
                   var_export( $source, true ), $funcName );
        $laana = new Laana();
        $laana->updateSourceByID( $source );
    }
    return $doUpdate;
}

function updateDocument( $sourceID, $options ) {
    $funcName = "updateDocument";
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $laana = new Laana();
    $source = $laana->getSource( $sourceID );
    if( isset( $source['groupname'] ) ) {
        $groupname = $source['groupname'];
        $parser = getParser( $groupname );
        if( $parser ) {
            printBoth( "($sourceID,$groupname)", $funcName );
            $parser->initialize( $source['link'] );
            //printBoth( $parser, "parser after initialize" );
            $updatedSource = updateSource( $parser, $source, $options );
            if( $updatedSource ) {
                printBoth( "Updated source record", $funcName );
            } else {
                printBoth( "No change to source record", $funcName );
            }
        } else {
            printBoth( "No parser for $groupname", $funcName );
        }
    } else {
        printBoth( "No registered source for $sourceID", $funcName );
    }
}

$maxrows = 10000;
//$maxrows = 10;
function getAllDocuments( $options ) {
    global $maxrows;
    global $parsermap;
    $funcName = "getAllDocuments";

    printBoth( $options, "$funcName options" );
    $force = $options['force'] ?? false;
    $debug = $options['debug'] ?? false;
    $local = $options['local'] ?? false;
    $parserkey = $options['parserkey'] ?? '';
    $singleSourceID = $options['sourceid'] ?? 0;
    setDebug( $debug );
    $laana = new Laana();
    if( $parserkey ) {
        $parser = $parsermap[$parserkey];
    }

    // First get metadata for the document(s)
    
    $pages = [];
    $items = [];
    if( $singleSourceID ) {
        // One document already known
        $items = [$laana->getSource( $singleSourceID )];
    } else if( $parserkey && $local ) {
        // Only process already known documents for a particular parser
        $items = $laana->getSources( $parserkey );
    } else if( $parserkey ) {
        // Get all documents in the wild for the parser
        $pages = $parser->getPageList();
        //echo( var_export( $pages, true ) . "\n" );
    } else if( $local || ($options['minsourceid'] > 0 && $options['maxsourceid'] < PHP_INT_MAX) ) {
        // Only process already known documents for all parsers
        $items = $laana->getSources();
    }

    // If getting all documents in the wild for a parser, that is already
    // done at this point
    if( sizeof( $pages ) < 1 ) {
        foreach( $items as $item ) {
            $parser = getParser( $item['groupname'] );
            if( !$parser ) {
                printBoth( $item['sourceid'], "$funcName no source for");
            } else if( $item['sourceid'] >= $options['minsourceid'] &&
                       $item['sourceid'] <= $options['maxsourceid'] ) {
                $item['url'] = $item['link'];
                if( !isset($item['author']) || !$item['author'] ) {
                    $item['author'] = $item['authors'];
                }
                //echo( var_export( $items[$i], true ) . "\n" );
                $sourcename = $item['sourcename'];
                //echo "$sourcename\n";
                //$page = [$sourcename => $item];
                $page = $item;
                array_push( $pages, $page );
                printBoth( $page, "$funcName adding page to process" );
            }
        }
    }

    // Sort by sourceid
    if( sizeof( $pages ) > 0 && isset($pages[0]['sourceid'] ) ) {
        usort($pages, function($sourcea, $sourceb) {
            /*
        $keys = array_keys( $a );
        $sourcea = $a[$keys[0]];
        $keys = array_keys( $b );
        $sourceb = $b[$keys[0]];
        */
        return $sourcea['sourceid'] <=> $sourceb['sourceid'];
    });
    }
    echo sizeof($pages) . " pages found\n";
    if( $debug ) {
        printObject( $pages, $funcName );
    }

    // Now get the content for each document and parse and store it
    
    $docs = 0;
    $updates = 0;
    $i = 0;
    foreach( $pages as $source ) {
        //$keys = array_keys( $page );
        //echo "page keys: " . var_export( $keys, true ) . "\n";
        //$sourceName = $keys[0];
        //$source = $page[$sourceName];
        printObject( $source, "source" );
        $sourceName = $source['sourcename'];
        $link = $source['url'];
        if( !$link ) {
            printBoth( "Skipping item with no URL: $sourceName", $funcName );
            continue;
        }
        if( !isset($source['sourceid']) ) {
            $src = $laana->getSourceByLink( $link );
            if( isset( $src['sourceid'] ) ) {
                $source['sourceid'] = $src['sourceid'];
            }
        }
        $sourceID = $source['sourceid'] ?? '';

        if( $sourceID && 
            ($sourceID < $options['minsourceid'] ||
             $sourceID > $options['maxsourceid']) ) {
            printBoth( "Skipping sourceid $sourceID", $funcName );
            continue;
        }
        
        printBoth( $source,  "$funcName page" );
        $title = $source['title'];
        $author = $source['author'] ?? '';
        $parser = getParser( $source['groupname'] );
        if( !$parser ) {
            printBoth( $source['groupname'], "$funcName no parser for $sourceID groupname" );
            return;
        }
        if( !$local ) {
            $parser->initialize( $link );
        }
        //printBoth( $parser, "parser after initialize $link" );

        if( !$sourceID ) {
            // New source
            $params = [
                'link' => $link,
                'groupname' => $parser->groupname,
                'date' => $parser->date,
                'title' => $title,
                'authors' => $author,
                'sourcename' => $sourceName,
            ];
            printBoth( "Adding source $sourceName: " . var_export( $params, true ), $funcName );
            $row = $laana->addSource( $sourceName, $params );
            $sourceID = isset( $row['sourceid'] ) ? $row['sourceid'] : '';
            if( $sourceID ) {
                printBoth( "Added sourceID $sourceID", $funcName );
                $docs += saveContents( $parser, $sourceID, $options );
                $updates++;
            } else {
                printBoth( "Failed to add source", $funcName );
            }
        } else {
            // Known source
            if( $sourceID >= $options['minsourceid'] &&
                $sourceID <= $options['maxsourceid'] ) {
                if( !$local ) {
                    $updatedSource = updateSource( $parser, $source, $options );
                    if( $updatedSource ) {
                        $updates++;
                        printBoth( "Updated source record", $funcName );
                    } else {
                        printBoth( "No change to source record", $funcName );
                    }
                    printBoth( $updatedSource, "Current source" );
                }                
                
                $docs += saveContents( $parser, $sourceID, $options );
                printBoth( $sourceID, "Updated contents for sourceID" );
            } else {
                printBoth( $sourceID, "Out of range" );
            }
        }

        $i++;
        if( $i > $maxrows ) {
            break;
        }
    }
    echo "$funcName: updated $updates source definitions and $docs documents\n";
}

?>

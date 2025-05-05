<?php
include_once '../db/parsehtml.php';
include_once 'parsers.php';

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
    printBoth( "Title: $title; SourceName: $sourceName; Link: $url", $funcName );
    //printObject( $parser, $funcName );
    if( !$url ) {
        printObject( "No url", $funcName );
        debuglog( "No url", $funcName );
        return;
    }

    $laana = new Laana();
    //$parser->initialize( $url );
    $text = $parser->getContents( $url, [] );
    printBoth( "Read " . strlen($text) . " characters", $funcName );
    //echo "$text\n";
    
    $laana->removeContents( $sourceID );
    $count = $laana->addRawText( $sourceID, $text );
    
    printBoth( "$count characters added to raw text table", $funcName );
    return $text;
}

function checkRaw( $url ) {
    global $parserkey;
    $funcName = "checkRaw";
    $laana = new Laana();
    $source = $laana->getSourceByLink( $url );
    // printObject( $source, $funcname );
    $sourceID = isset( $source['sourceid'] ) ? $source['sourceid'] : '';
    if( $sourceID === '' ) {
        printBoth( "No source registered for $url", $funcName );
        return false;
    } else {
        $present = $laana->hasRaw( $sourceID );
        $sourceName = $source['sourceName'];
        if( $present >= 0 ) {
            printBoth( "$sourceName already has HTML", $funcName );
            return true;
        } else {
            printBoth( "$sourceName does not have HTML yet", $funcName );
            return false;
        }
    }
}

function hasSentences( $sourceID ) {
    $laana = new Laana();
    $sentences = $laana->getSentencesBySourceID( $sourceID );
    return ($sentences && sizeof( $sentences ) > 0 );
}

function addSentences( $parser, $sourceID, $link, $text ) {
    $funcName = "addSentences";
    printBoth( "($parser->groupname,$link," . strlen($text) . " characters)", $funcName );
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

function saveDocument( $parser, $sourceID, $options ) {
    $funcName = "saveDocument";
    printBoth( "(" . $parser->groupname . ",$sourceID)", $funcName );
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $debug = isset( $options['debug'] ) ? $options['debug'] : false;
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
    $hasSentences = hasSentences( $sourceID );
    $text = "";
    $present = $laana->hasRaw( $sourceID );
    $msg = ($hasSentences) ? "Sentences present for $sourceID" : "No sentences for $sourceID";
    $msg .= ", hasRaw: ";
    $msg .= ($present) ? "yes" : "no";
    $msg .=  ", force: ";
    $msg .=  ($force) ? "yes" : "no";
    printBoth( $msg, $funcName );

    $didWork = 0;
    if( !$present || $force ) {
        $parser->initialize( $link );
        $sourceName = $parser->getSourceName( '', $link );
        $text = saveRaw( $parser, $source );
        $didWork = 1;
        if( $text ) {
            addSentences( $parser, $sourceID, $link, $text );
        }
    } else {
        if( $present ) {
            printBoth( "$sourceName already has raw text", $funcName );
            if( !$hasSentences ) {
                $text = $laana->getRawText( $sourceID );
                addSentences( $parser, $sourceID, $link, $text );
                $didWork = 1;
            }
        }
    }
    return $didWork;
}

function updateSource( $parser, $source, $options ) {
    $funcName = "updateSource";
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $params = [
        'sourcename' => $parser->getSourceName( '', $source['link'] ),
        'title' => $parser->title,
        'date' => $parser->date,
    ];
    if( $parser->authors && !$source['authors'] ) {
        $params['authors'] = $parser->authors;
    }
    if( $parser->date && ($parser->date != $source['date']) ) {
        $params['date'] = $parser->date;
    }
    printBoth( "Parameters found by parser: " . var_export( $params, true ),
               $funcName );
    $doUpdate = false;
    foreach( array_keys( $params ) as $key ) {
        if( $source[$key] !== $params[$key] && $params[$key] ) {
            $source[$key] = $params[$key];
            $doUpdate = true;
        }
    }
    unset( $source['sentencecount'] );

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
    $funcName = "getAllDocuments";

    $parserkey = isset( $options['parserkey'] ) ? $options['parserkey'] : false;
    if( !$parserkey ) {
        printBoth( "Missing required parserkey", $funcName );
        return 0;
    }
    $parser = getParser( $parserkey );
    if( !$parserkey ) {
        printBoth( "Invalid parserkey", $funcName );
        return 0;
    }
    printBoth( $options, "$funcName options" );
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $debug = isset( $options['debug'] ) ? $options['debug'] : false;
    $local = isset( $options['local'] ) ? $options['local'] : false;
    $singleSourceID = isset( $options['sourceid'] ) ? $options['sourceid'] : 0;
    setDebug( $debug );
    $laana = new Laana();

    if( $singleSourceID ) {
        $pages = [];
        $items = [$laana->getSource( $singleSourceID )];
    } else if( $local ) {
        $items = $laana->getSources( $parserkey );
    }
    if( $singleSourceID || $local ) {
        $pages = [];
        for( $i = 0; $i < sizeof( $items ); $i++ ) {
            if( $items[$i]['sourceid'] >= $options['minsourceid'] &&
                $items[$i]['sourceid'] <= $options['maxsourceid'] ) {
            
                $items[$i]['url'] = $items[$i]['link'];
                $items[$i]['author'] = $items[$i]['authors'];
                //echo( var_export( $items[$i], true ) . "\n" );
                $sourcename = $items[$i]['sourcename'];
                //echo "$sourcename\n";
                $page = [$sourcename => $items[$i]];
                array_push( $pages, $page );
            }
        }
    } else {
        $pages = $parser->getPageList();
    }

    echo sizeof($pages) . " pages found\n";
    if( $debug ) {
        printObject( $pages, $funcName );
    }

    $docs = 0;
    $updates = 0;
    $i = 0;
    foreach( $pages as $page ) {
        $keys = array_keys( $page );
        //echo "page keys: " . var_export( $keys, true ) . "\n";
        $sourceName = $keys[0];
        $source = $page[$sourceName];
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
        $sourceID = isset( $source['sourceid'] ) ? $source['sourceid'] : '';

        if( $sourceID && 
            ($sourceID < $options['minsourceid'] ||
            $sourceID > $options['maxsourceid']) ) {
            continue;
        }
        
        echo "page: " . var_export( $source, true ) . "\n";
        $title = $source['title'];
        $authors = isset( $source['authors'] ) ? $source['authors'] : '';
        $parser->initialize( $link );

        if( !$sourceID ) {
            $params = [
                'link' => $link,
                'groupname' => $parser->groupname,
                'date' => $parser->date,
                'title' => $title,
                'authors' => $authors,
                'sourcename' => $sourceName,
            ];
            printBoth( "Adding source $sourceName: " . var_export( $params, true ), $funcName );
            $row = $laana->addSource( $sourceName, $params );
            $sourceID = isset( $row['sourceid'] ) ? $row['sourceid'] : '';
            if( $sourceID ) {
                printBoth( "Added sourceID $sourceID", $funcName );
                $updates++;
            } else {
                printBoth( "Failed to add source", $funcName );
            }
        } else {
            if( $sourceID >= $options['minsourceid'] &&
                $sourceID <= $options['maxsourceid'] ) {
                $updatedSource = updateSource( $parser, $source, $options );
                if( $updatedSource ) {
                    $updates++;
                    printBoth( "Updated source record", $funcName );
                } else {
                    printBoth( "No change to source record", $funcName );
                }
            }
        }

        if( $sourceID >= $options['minsourceid'] &&
            $sourceID <= $options['maxsourceid'] ) {

            $source = $laana->getSourceByLink( $link );
            echo( "Current source: " . var_export( $source, true ) );
            
            $docs += saveDocument( $parser, $sourceID, $options );
        }

        $i++;
        if( $i > $maxrows ) {
            break;
        }
    }
    echo "$funcName: updated $updates source definitions and $docs documents\n";
}

?>

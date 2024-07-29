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
    if( $text ) {
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
    printBoth( ($hasSentences) ? "Sentences present for $sourceID" : "No sentences for $sourceID", $funcName );
    //continue;
    
    $text = "";
    $present = $laana->hasRaw( $sourceID );
    $msg = "hasRaw: ";
    $msg .= ($present) ? "yes" : "no";
    $msg .= ", force: ";
    $msg .= ($force) ? "yes" : "no";
    printBoth( $msg, $funcName );

    $didWork = 0;
    if( !$present || $force ) {
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

function updateDocument( $sourceID, $options ) {
    $funcName = "updateDocument";
    $force = isset( $options['force'] ) ? $options['force'] : false;
    $laana = new Laana();
    $source = $laana->getSource( $sourceID );
    if( isset( $source['groupname'] ) ) {
        $groupname = $source['groupname'];
        $parser = getParser( $groupname );
        printBoth( "($sourceID,$groupname)", $funcName );
        if( $parser && $force ) {
            // Only update the source record on force and if the values have changed in the field
            $parser->initialize( $source['link'] );
            $params = [
                'sourcename' => $parser->getSourceName( '', $source['link'] ),
                'title' => $parser->title,
                'date' => $parser->date,
            ];
            if( $parser->authors && !$source['authors'] ) {
                $params['authors'] = $parser->authors;
            }
            if( $parser->date && !$source['date'] ) {
                $params['date'] = $parser->date;
            }
            $doUpdate = false;
            foreach( array_keys( $params ) as $key ) {
                if( $source[$key] !== $params[$key] ) {
                    $doUpdate = true;
                    break;
                }
            }
            if( $doUpdate ) {
                printBoth( "About to call updatesourceByID: " .
                           var_export( $source, true ), $funcName );
                $laana->updateSourceByID( $source );
            }
            saveDocument( $parser, $sourceID, $options );
        } else {
            if( !$parser ) {
                printBoth( "No parser for $groupname", $funcName );
            } else {
                printBoth( "No change to source record", $funcName );
            }
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

    printBoth( $options, "$funcName options" );
    $debug = isset( $options['debug'] ) ? $options['debug'] : false;
    $singleSourceID = isset( $options['sourceid'] ) ? $options['sourceid'] : 0;
    setDebug( $debug );

    if( $singleSourceID ) {
        return updateDocument( $singleSourceID, $options );
    }
    
    $parserkey = isset( $options['parserkey'] ) ? $options['parserkey'] : false;
    $force = isset( $options['force'] ) ? $options['force'] : false;

    $laana = new Laana();
    $parser = getParser( $parserkey );
    $pages = $parser->getPageList();
    if( $debug ) {
        printObject( $pages, $funcName );
    }

    $docs = 0;
    $i = 0;
    foreach( $pages as $page ) {
        //$parser = getParser( $parserkey );
        //echo "page: " . var_export( $page, true ) . "\n";
        $keys = array_keys( $page );
        //echo "page keys: " . var_export( $keys, true ) . "\n";
        $sourceName = $keys[0];
        $item = $page[$sourceName];
        $link = $item['url'];
        if( !$link ) {
            printBoth( "Skipping item with no URL - $sourceName", $funcName );
            continue;
        }
        $title = $item['title'];
        $authors = isset( $item['authors'] ) ? $item['authors'] : '';
        $parser->initialize( $link );
        if( !$authors ) {
            $authors = $parser->authors;
        }
        //printObject( $parser );
        $source = $laana->getSourceByLink( $link );
        $sourceID = isset( $source['sourceid'] ) ? $source['sourceid'] : '';
        if( !$sourceID ) {
            $laana = new Laana();
            $params = [
                'link' => $link,
                'groupname' => $parser->groupname,
                'date' => $parser->date,
                'title' => $title,
                'authors' => $authors,
            ];
            printBoth( "Adding source $sourceName: " . var_export( $params, true ), $funcName );
            $row = $laana->addSource( $sourceName, $params );
            $sourceID = isset( $row['sourceid'] ) ? $row['sourceid'] : '';
            if( $sourceID ) {
                printBoth( "Added sourceID $sourceID", $funcName );
            } else {
                printBoth( "Failed to add source", $funcName );
            }
        } else if( $authors && !$source['authors'] ) {
            $source['authors'] = $authors;
            $row = $laana->addSource( $sourceName, $source );
            $sourceID = isset( $row['sourceid'] ) ? $row['sourceid'] : '';
            if( $sourceID ) {
                printBoth( "Added authors $authors to $sourceID", $funcName );
            } else {
                printBoth( "Failed to add authors to source", $funcName );
            }
        }

        echo "$funcName: \n" .
             "$i: $sourceName\n" .
             "Title: $title\n" .
             "SourceName: $sourceName\n" .
             "Link: $link\n" .
             "SourceID: $sourceID\n";
        if( $singleSourceID ) {
            echo "$funcName: singleSourceID: $singleSourceID\n";
        }

        $i++;
        //continue;

        if( $singleSourceID && $singleSourceID != $sourceID ) {
            continue;
        }
        
        $docs += saveDocument( $parser, $sourceID, $options );

        if( $i >= $maxrows ) {
            break;
        }
        if( $singleSourceID ) {
            break;
        }
    }
    echo "$funcName: updated $docs documents\n";
}

?>

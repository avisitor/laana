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

function saveRaw( $parser, $url, $sourceName ) {
    //$parser = new KaPaaMooleloHTML();
    //$parser->initialize( $url );
    $title = $parser->title;
    echo "saveRaw - Title: $title; SourceName: $sourceName; Link: $url\n";
    //printObject( $parser );
    if( !$url ) {
        echo "saveRaw: No url\n";
        return;
    }

    $laana = new Laana();
    $source = $laana->getSourceByName( $sourceName );
    //echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'] ?: '';

    //$text = $parser->getRaw( $url, [] );
    //$text = $parser->getRawText( $url, [] );
     $text = $parser->getContents( $url, [] );
    echo "Read " . strlen($text) . " characters\n";
    //echo "$text\n";
    
    $laana->removeContents( $sourceID );
    $count = $laana->addRawText( $sourceID, $text );
    
    echo "$count characters added to raw text table\n";
    return $text;
}

function checkRaw( $url ) {
    global $parserkey;
    $laana = new Laana();
    $parser = getParser( $parserkey );
    $parser->initialize( $url );
    $title = $parser->title;
    $sourceName = $parser->getSourceName( '', $url );
    $source = $laana->getSourceByName( $sourceName );
    //echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'] ?: '';
    if( !$sourceID ) {
        echo "No source registered for $title\n";
        return false;
    } else {
        $present = $laana->hasRaw( $sourceID );
        if( $present ) {
            echo "$sourceName already has HTML\n";
            return true;
        } else {
            echo "$sourceName does not have HTML yet\n";
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
    if( $text ) {
        //$sentences = $parser->extractSentencesFromString( $text );
        $sentences = $parser->extractSentencesFromHTML( $text );
    } else {
        $sentences = $parser->extractSentences( $link );
    }
    echo( sizeof($sentences) . " sentences\n" );
    //echo( var_export($sentences, true) . "\n" );
    
    $laana = new Laana();
    $laana->removeSentences( $sourceID );
    $count = $laana->addSentences( $sourceID, $sentences);
    echo "$count sentences added to sentence table with sourceID $sourceID\n";
    $count = $laana->addFullText( $sourceID, $sentences);
    echo "$count sentences added to full text table\n";
}

// A few documents we failed to extract sentences from
function getFailedDocuments( $parser ) {
    $db = new DB();
    $sql = 'select sourceid from " . CONTENTS . " where sourceid not in (select distinct sourceid from " . SENTENCES . ") order by sourceid';
    $rows = $db->getDBRows( $sql );
    //echo( var_export( $rows, true ) . "\n" );
    foreach( $rows as $row ) {
        echo( var_export( $row, true ) . "\n" );
        //echo "${row['sourcename']}\n";
        $sourceID = $row['sourceid'];
        $link = $row['link'];
        //$text = saveRaw( $parser, $row['link'], $row['sourcename'] );
        $laana = new Laana();
        $text = $laana->getRawText( $sourceID );
        echo( strlen($text) . " chars of raw text read\n" );
        addSentences( $parser, $sourceID, $link, $text );
    }
}

function saveDocument( $parser, $sourceID, $options ) {
    echo "saveDocument(" . $parser->groupname . ",$sourceID)\n";
    //printObject( $parser );
    $force = $options['force'] ?: false;
    $debug = $options['debug'] ?: false;
    setDebug( $debug );

    $laana = new Laana();
    $source = $laana->getSource( $sourceID );
    echo( "saveDocument: source: " . var_export( $source, true ) . "\n" );
    
    $link = $source['link'];
    $sourceName = $source['sourcename'];

    $hasSentences = hasSentences( $sourceID );
    echo( $hasSentences ? "saveDocument: Sentences present for $sourceID\n" : "No sentences for $sourceID\n" );
    //continue;
    
    $text = "";
    $present = $laana->hasRaw( $sourceID );
    echo "saveDocument: hasRaw: $present\n";

    $didWork = 0;
    if( !$present || $force ) {
        $text = saveRaw( $parser, $link, $sourceName );
        $didWork = 1;
        if( $text ) {
            addSentences( $parser, $sourceID, $link, $text );
            $didWork = 1;
        }
    } else {
        if( $present ) {
            echo "saveDocument: $sourceName already has raw text\n";
            if( !$hasSentences || $force ) {
                $text = $laana->getRawText( $sourceID );
                addSentences( $parser, $sourceID, $link, $text );
                $didWork = 1;
            }
        }
    }
    return $didWork;
}

function updateDocument( $sourceID, $options ) {
    $laana = new Laana();
    $source = $laana->getSource( $sourceID );
    if( isset( $source['groupname'] ) ) {
        $groupname = $source['groupname'];
        $parser = getParser( $groupname );
        echo "updateDocument($sourceID,$groupname)\n";
        if( $parser ) {
            $parser->initialize( $source['link'] );
            if( $parser->authors && !$source['authors'] ) {
                $source['authors'] = $parser->authors;
                echo "updateDocument adding authors " . $source['authors'] . "\n";
                $laana->addSource( $source['sourcename'], $source );
            }
            if( $parser->date && !$source['date'] ) {
                $source['date'] = $parser->date;
                echo "updateDocument adding date " . $source['date'] . "\n";
                $laana->addSource( $source['sourcename'], $source );
            }
            saveDocument( $parser, $sourceID, $options );
        } else {
            echo "updateDocument: No parser for $groupname\n";
        }
    } else {
        echo "updateDocument: No registered source for $sourceID\n";
    }
}

$maxrows = 10000;
//$maxrows = 2;
function getAllDocuments( $options ) {
    global $maxrows;

    echo( "getAllDocuments:options: " . var_export( $options, true ) . "\n" );
    $debug = $options['debug'] ?: false;
    $singleSourceID = $options['sourceid'] ?: 0;
    setDebug( $debug );

    if( $singleSourceID ) {
        return updateDocument( $singleSourceID, $options );
    }
    
    $parserkey = $options['parserkey'] ?: false;
    $force = $options['force'] ?: false;

    $laana = new Laana();
    $parser = getParser( $parserkey );
    $pages = $parser->getPageList();
    if( $debug ) {
        echo( var_export( $pages, true ) . "\n" );
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
        $title = $item['title'];
        $authors = ($item['authors']) ? $item['authors'] : '';
        $parser->initialize( $link );
        if( !$authors ) {
            $authors = $parser->authors;
        }
        //printObject( $parser );
        $source = $laana->getSourceByName( $sourceName );
        $sourceID = $source['sourceid'];
        if( !$sourceID ) {
            $laana = new Laana();
            $params = [
                'link' => $link,
                'groupname' => $parser->groupname,
                'date' => $parser->date,
                'title' => $title,
                'authors' => $authors,
            ];
            $sourceID = $laana->addSource( $sourceName, $params );
            if( $sourceID ) {
                echo "Added sourceID $sourceID\n";
            } else {
                echo "Failed to add source\n";
            }
        } else if( $authors && !$source['authors'] ) {
            $source['authors'] = $authors;
            $sourceID = $laana->addSource( $sourceName, $source );
            if( $sourceID ) {
                echo "Added authors $authors to $sourceID\n";
            } else {
                echo "Failed to add authors to source\n";
            }
        }

        echo "$i: $sourceName\n";
        
        echo "Title: $title\n";
        echo "SourceName: $sourceName\n";
        echo "Link: $link\n";
        echo "SourceID: $sourceID\n";
        if( $singleSourceID ) {
            echo "singleSourceID: $singleSourceID\n";
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
    echo "getAllDocuments: updated $docs documents\n";
}

?>

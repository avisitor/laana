<?php
include 'db/parsehtml.php';

function getParser() {
    //$parser = new CBHtml();
    //$parser = new UlukauHtml();
    $parser = new AoLamaHTML();
    return $parser;
}

function hasRaw( $sourceid ) {
    $sql = "select sources.sourceid sourceid,sources.sourcename from sources,contents where sources.sourceid=contents.sourceid and sources.sourceid = :sourceid and not html is null";
    $values = [
        'sourceid' => $sourceid,
    ];
    echo "$sql [$sourceid]\n";
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    echo( var_export( $row, true ) . "\n" );
    $id = $row['sourceid'];
    return $id;
}

function saveSource( $parser, $url, $title ) {
    $sourceName = $parser->getSourceName( $title, $url );
    $laana = new Laana();
    $sourceID = $laana->addSource( $sourceName, $url );
    if( $sourceID ) {
        echo "Added sourceID $sourceID\n";
    } else {
        echo "Failed to add source\n";
    }
    return $sourceID;
}

function saveRaw( $parser, $url, $sourceName ) {
    $parser->initialize( $url );
    $title = $parser->title;
    echo "saveRaw - Title: $title; SourceName: $sourceName; Link: $url\n";

    $laana = new Laana();
    $source = $laana->getSourceByName( $sourceName );
    //echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'] ?: '';

    $text = $parser->getRawText( $url );
    echo "Read " . strlen($text) . " characters\n";
    //echo "$text\n";
    
    $count = $laana->addRawText( $sourceID, $text );
    echo "$count characters added to raw text table\n";
    return $text;
}

function checkRaw( $url ) {
    $laana = new Laana();
    $parser = getParser();
    $parser->initialize( $url );
    $title = $parser->title;
    $sourceName = $parser->getSourceName( '' );
    $source = $laana->getSourceByName( $sourceName );
    //echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'] ?: '';
    if( !$sourceID ) {
        echo "No source registered for $title\n";
        return false;
    } else {
        $present = hasRaw( $sourceID );
        if( $present ) {
            echo "$sourceName already has HTML\n";
            return true;
        } else {
            echo "$sourceName does not have HTML yet\n";
            return false;
        }
    }
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
    $count = $laana->addSentences( $sourceID, $sentences);
    echo "$count sentences added to sentence table\n";
    $count = $laana->addFullText( $sourceID, $sentences);
    echo "$count sentences added to full text table\n";
}

// A few documents we failed to extract sentences from
function getFailedUlukauDocuments() {
    $db = new DB();
    $sql = 'select * from sources where sourceid not in (select distinct sourceid from sentences)';
    $rows = $db->getDBRows( $sql );
    //echo( var_export( $rows, true ) . "\n" );
    $parser = new UlukauHtml();
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

$maxrows = 10000;
function getAllDocuments() {
    global $maxrows;
    $laana = new Laana();
    $parser = getParser();
    $pages = $parser->getPageList();
    //echo( var_export( $pages, true ) . "\n" );

    $i = 0;
    foreach( $pages as $page ) {
        $parser = getParser();
        $keys = array_keys( $page );
        $title = $keys[0];
        $item = $page[$title];
        $link = $item['url'];

        $parser->initialize( $link );
        $sourceName = $parser->getSourceName( $title, $link );
        echo "Title: $title\n";
        echo "SourceName: $sourceName\n";
        echo "Link: $link\n";
        
        $source = $laana->getSourceByName( $sourceName );
        echo( var_export( $source, true ) . "\n" );
        $sourceID = $source['sourceid'];
        if( !$sourceID ) {
            $sourceID = saveSource( $parser, $link, $title );
            //$sourceID = $laana->addSource( $sourceName, $link );
        }

        $text = "";
        $present = hasRaw( $sourceID );
        echo "hasRaw: $present\n";
        if( !$present ) {
            $text = saveRaw( $parser, $link, $sourceName );
            addSentences( $parser, $sourceID, $link, $text );
        } else {
            echo "$sourceName already has raw text\n";
            //$text = $laana->getRawText( $sourceID );
        }

        $i++;
        if( $i >= $maxrows ) {
            break;
        }
    }
}


getAllDocuments();

//getFailedUlukauDocuments();


?>

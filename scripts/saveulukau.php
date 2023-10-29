<?php
include 'db/parsehtml.php';

function getParser() {
    $parser = new CBHtml();
    //$parser = new UlukauHtml();
    //$parser = new AoLamaHTML();
    return $parser;
}

function hasRaw( $sourceid ) {
    $sql = "select sources.sourceid,sources.sourcename from sources,contents where sources.sourceid=contents.sourceid and sources.sourceid = :sourceid and not html is null";
    $values = [
        'sourceid' => $sourceid,
    ];
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    $id = $row['sourceid'];
    return $id;
}

function saveSource( $parser, $url ) {
    $sourceName = $parser->getSourceName( '' );
    $laana = new Laana();
    $sourceID = $laana->addSource( $sourceName, $url );
    if( $sourceID ) {
        echo "Added sourceID $sourceID\n";
    } else {
        echo "Failed to add source\n";
    }
    return $sourceID;
}

function saveRaw( $url ) {
    $parser = getParser();
    $parser->initialize( $url );
    $title = $parser->title;
    $sourceName = $parser->getSourceName( $title, $url );
    echo "saveRaw - Title: $title; SourceName: $sourceName; Link: $$url\n";

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
        $sentences = $parser->extractSentencesFromString( $text );
    } else {
        $sentences = $parser->extractSentences( $link );
    }
    echo( sizeof($sentences) . "\n" );
    
    $laana = new Laana();
    $count = $laana->addSentences( $sourceID, $sentences);
    echo "$count sentences added to sentence table\n";
    $count = $laana->addFullText( $sourceID, $sentences);
    echo "$count sentences added to full text table\n";
}


$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-KAMALII1&e=-------en-20--1--txt-txPT-----------";
$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-MELELAHUI&e=-------en-20--1--txt-txPT-----------";

$parser = getParser();
$pages = $parser->getPageList();
$laana = new Laana();
//echo( var_export( $pages, true ) . "\n" );

foreach( $pages as $page ) {
    $parser = getParser();
    $keys = array_keys( $page );
    $title = $keys[0];
    $item = $page[$title];
    $link = $item['url'];

    $parser->initialize( $link );
    $sourceName = $parser->getSourceName();
    echo $title . "\n";
    echo $sourceName . "\n";
    echo $link . "\n";
    
    $source = $laana->getSourceByName( $sourceName );
    //echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'];
    if( !$sourceID || strlen($sourceID) < 1 ) {
        $sourceID = saveSource( $parser, $link );
        $sourceID = $laana->addSource( $sourceName );
    }

    $text = "";
    $present = hasRaw( $sourceID );
    if( !$present ) {
        $text = saveRaw( $link );
    } else {
        echo "$sourcename already has raw text\n";
        $text = $laana->getRawText( $sourceID );
    }

    //addSentences( $parser, $sourceID, $link, $text );
}
?>

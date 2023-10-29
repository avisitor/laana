<?php
include 'db/parsehtml.php';

function getParser() {
    $parser = new CBHtml();
    //$parser = new AoLamaHTML();
    return $parser;
}

$parser = getParser();

$pageextract = "extractCB";

$sourceName = $parser->getSourceName();
$pages = $parser->getPageList();
$laana = new Laana();
//echo( var_export( $pages, true ) . "\n" );

function updateSource( $sourceName, $link ) {
    $laana = new Laana();
    $sourceID = $laana->addSource( $sourceName, $link );
    echo "sourceID: $sourceID\n";
}

function addSentences( $parser, $sourceID, $link ) {
    $sentences = $parser->extractSentences( $link );
    echo( sizeof($sentences) . "\n" );
    
    $laana = new Laana();
    if( !$sourceID || strlen($sourceID) < 1 ) {
        $sourceID = $laana->addSource( $sourceName );
        if( $sourceID ) {
            echo "Added sourceID $sourceID\n";
        } else {
            echo "Failed to add source\n";
        }
    }
    $count = $laana->addSentences( $sourceID, $sentences);
    echo "$count sentences added to sentence table\n";
    $count = $laana->addFullText( $sourceID, $sentences);
    echo "$count sentences added to full text table\n";
}

foreach( $pages as $page ) {
    $parser = getParser();
    $keys = array_keys( $page );
    $title = $keys[0];
    $item = $page[$title];
    $link = $item['url'];

    $sourceName = $parser->getSourceName( $title, $link );
    echo $title . "\n";
    echo $sourceName . "\n";
    echo $link . "\n";
    
    $source = $laana->getSourceByName( $sourceName );
    echo( var_export( $source, true ) . "\n" );
    $sourceID = $source['sourceid'];

    updateSource( $sourceName, $link );

    //addSentences( $parser, $sourceID, $link );
}
?>

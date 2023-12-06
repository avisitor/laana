<?php
include '../db/parsehtml.php';
include 'parsers.php';

function testParse( $key ) {
    global $parsermap, $urlmap;
    $parser = $parsermap[$key];
    $url = $urlmap[$key];
    $options = [];
    if( $key == 'baibala' ) {
        // It contains a huge number of pages, takes a long time to read them all
        $options = ['continue'=>false,];
    }
    $sentences = $parser->extractSentences( $url, $options );
    var_export( $sentences );
}

$parserkey = (isset( $argv[1] ) ) ? $argv[1] : '';
$parser = $parsermap[$parserkey];
if( !$parser ) {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a parser: $values\n";
} else {
    setDebug( true );
    testParse( $parserkey );
}
?>

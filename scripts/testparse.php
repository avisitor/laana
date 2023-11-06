<?php
include '../db/parsehtml.php';
include 'parsers.php';

function testParse( $key ) {
    global $parsermap, $urlmap;
    $parser = $parsermap[$key];
    $url = $urlmap[$key];
    $sentences = $parser->extractSentences( $url );
    var_export( $sentences );
}

$parserkey = (isset( $argv[1] ) ) ? $argv[1] : '';
$parser = $parsermap[$parserkey];
if( !$parser ) {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a parser: $values\n";
} else {
    testParse( $parserkey );
}
?>

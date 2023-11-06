<?php
include '../db/parsehtml.php';
include 'parsers.php';

function testDate( $key ) {
    global $parsermap, $urlmap;
    $parser = $parsermap[$key];
    $url = $urlmap[$key];
    $dom = $parser->getDOM( $url );
    $date = $parser->extractDate( $dom );
    echo "$date\n";
}

$parserkey = (isset( $argv[1] ) ) ? $argv[1] : '';
$parser = $parsermap[$parserkey];
if( !$parser ) {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a parser: $values\n";
} else {
    setDebug( true );
    testDate( $parserkey );
}
?>

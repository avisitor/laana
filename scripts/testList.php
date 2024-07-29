<?php
include 'saveFuncs.php';

$longopts = [
    "debug",
    'parser:',
];
$args = getopt( "", $longopts );
$debug = isset( $args['debug'] ) ? true : false;
$parserkey = isset( $args['parser'] ) ? $args['parser'] : '';
$options = [
    'debug' => $debug,
];

$parser = ($parserkey) ? $parsermap[$parserkey] : null;
if( !$parser ) {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a parser: $values\n";
    echo "testList [--debug] [--force] --parser=parsername ($values)\n";
} else {
    setDebug( $debug );
    $parser = getParser( $parserkey );
    $pages = $parser->getPageList();
    echo( var_export( $pages, true ) . "\n" );
}
?>

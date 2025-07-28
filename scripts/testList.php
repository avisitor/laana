<?php
include_once __DIR__ . '/../scripts/saveFuncs.php';

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

$saveManager = new SaveManager( $options );
$parser = $saveManager->getParser($parserkey);
if( !$parser ) {
    $values = $saveManager->getParserKeys();
    echo "Specify a parser: $values\n";
    echo "testList [--debug] --parser=parsername\n";
} else {
    setDebug( $debug );
    $pages = $parser->getPageList();
    echo( var_export( $pages, true ) . "\n" );
}
?>

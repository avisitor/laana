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
    'verbose' => true,
];

$saveManager = new SaveManager( $options );
$parser = $saveManager->getParser($parserkey);
if( !$parser ) {
    $values = $saveManager->getParserKeys();
    $saveManager->output( "Specify a parser: $values" );
    $saveManager->output( "testList [--debug] --parser=parsername" );
} else {
    setDebug( $debug );
    $docs = $parser->getDocumentList();
    $saveManager->output( var_export( $docs, true ) );
}
?>

<?php
include 'saveFuncs.php';

$longopts = [
    "force",
    "debug",
    'sourceid:',
    'parser:',
];
$args = getopt( "", $longopts );
$force = isset( $args['force'] ) ? true : false;
$debug = isset( $args['debug'] ) ? true : false;
$sourceid = isset( $args['sourceid'] ) ? $args['sourceid'] : 0;
$parserkey = isset( $args['parser'] ) ? $args['parser'] : '';
$options = [
    'force' => $force,
    'debug' => $debug,
    'sourceid' => $sourceid,
];

$parser = $parsermap[$parserkey];
if( !$parser && !$sourceid ) {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a parser: $values or a sourceid\n";
    echo "savedocument [--debug] [--force] --parser=parsername ($values)\n" .
         "savedocument [--debug] [--force] --sourceid=sourceid\n";
} else {
    $options['parserkey'] = $parserkey;
    getAllDocuments( $options );
    //getFailedDocuments( $parser );
}
?>

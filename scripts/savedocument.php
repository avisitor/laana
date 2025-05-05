<?php
include 'saveFuncs.php';

$longopts = [
    "force",
    "debug",
    "local",
    'sourceid:',
    'minsource:',
    'maxsource:',
    'parser:',
];
$args = getopt( "", $longopts );
$force = isset( $args['force'] ) ? true : false;
$debug = isset( $args['debug'] ) ? true : false;
$local = isset( $args['local'] ) ? true : false;
$sourceid = isset( $args['sourceid'] ) ? $args['sourceid'] : 0;
$minsourceid = isset( $args['minsource'] ) ? $args['minsource'] : 0;
$maxsourceid = isset( $args['maxsource'] ) ? $args['maxsource'] : PHP_INT_MAX;
$parserkey = isset( $args['parser'] ) ? $args['parser'] : '';

// For running in the debugger without command line arguments
$parserkey = ($parserkey) ? $parserkey : "kauakukalahale";
#$sourceid = ($sourceid) ? $sourceid : 24308;

$options = [
    'force' => $force,
    'debug' => $debug,
    'local' => $local,
    'sourceid' => $sourceid,
    'minsourceid' => $minsourceid,
    'maxsourceid' => $maxsourceid,
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

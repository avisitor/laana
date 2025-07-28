<?php
include_once __DIR__ . '/../scripts/saveFuncs.php';

$longopts = [
    "force",
    "debug",
    "local",
    'sourceid:',
    'minsourceid:',
    'maxsourceid:',
    'parser:',
    'resplit',
];
$args = getopt( "", $longopts );
$parserkey = $args['parser'] ?? '';

$options = [
    'force' => isset( $args['force'] ) ? true : false,
    'debug' => isset( $args['debug'] ) ? true : false,
    'local' => isset( $args['local'] ) ? true : false,
    'resplit' => isset( $args['resplit'] ) ? true : false,
    'sourceid' => $args['sourceid'] ?? 0,
    'minsourceid' => $args['minsourceid'] ?? 0,
    'maxsourceid' => $args['maxsourceid'] ?? PHP_INT_MAX,
];

// If a parser is not specified, look it up if a sourceid was provided
if( !$parserkey && $options['sourceid'] ) {
    $url = "https://noiiolelo.org/api.php/source/{$options['sourceid']}";
    $text = file_get_contents( $url );
    if( $text ) {
        $source = (array)json_decode( $text, true );
        if( $source['groupname'] == null ) {
            echo "Sourceid {$options['sourceid']} not found\n";
        } else {
            $parserkey = $source['groupname'];
        }
    }
}
if( $parserkey ) {
    $options['parserkey'] = $parserkey;
}

$saveManager = new SaveManager( $options );
if( !$parserkey && !$options['minsourceid'] ) {
    $values = $saveManager->getParserKeys();
    echo "Specify a parser: $values or a sourceid\n";
    echo "savedocument [--debug] [--force] [--local] [--resplit] [--minsourceid=minsourceid] [--maxsourceid=maxsourceid] --parser=parsername ($values)\n" .
         "savedocument [--debug] [--force] [--local] [--resplit] --sourceid=sourceid\n" .
         "savedocument [--debug] [--force] [--local] [--resplit] --minsourceid=minsourceid --maxsourceid=maxsourceid [--parser=parsername] ($values)\n";
    echo "Received options: " . json_encode( $options ) . "\n";
} else {
    $saveManager->getAllDocuments();
    //getFailedDocuments( $parser );
}
?>

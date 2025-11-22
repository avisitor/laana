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
    'delete-existing',
];
$args = getopt( "", $longopts );
$parserkey = $args['parser'] ?? '';

$options = [
    'force' => isset( $args['force'] ) ? true : false,
    'debug' => isset( $args['debug'] ) ? true : false,
    'local' => isset( $args['local'] ) ? true : false,
    'resplit' => isset( $args['resplit'] ) ? true : false,
    'verbose' => true, // Always show output by default
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

try {
    // Initialize manager upfront
    $saveManager = new SaveManager( $options );
    
    if( !$parserkey && !$options['minsourceid'] ) {
        $values = $saveManager->getParserKeys();
        $saveManager->outLine("Specify a parser: $values or a sourceid");
        $saveManager->outLine("savedocument [--debug] [--force] [--local] [--resplit] [--delete-existing] [--minsourceid=minsourceid] [--maxsourceid=maxsourceid] --parser=parsername ($values)");
        $saveManager->outLine("savedocument [--debug] [--force] [--local] [--resplit] --sourceid=sourceid");
        $saveManager->outLine("savedocument [--debug] [--force] [--local] [--resplit] --minsourceid=minsourceid --maxsourceid=maxsourceid [--parser=parsername] ($values)");
        $saveManager->outLine("savedocument [--debug] [--force] [--local] [--resplit] [--delete-existing] --parser=parsername");
        $saveManager->outLine("Received options: " . json_encode( $options ));
    } else {
        // Delete existing documents if requested
        if (isset($args['delete-existing']) && $parserkey) {
            $saveManager->outLine("WARNING: About to delete all existing documents with groupname '$parserkey'");
            $saveManager->outLine("Press Ctrl+C within 5 seconds to cancel...");
            sleep(5);
            
            $stats = $saveManager->deleteByGroupname($parserkey);
            $saveManager->outLine("Deleted:");
            $saveManager->outLine("  Sources: {$stats['sources']}");
            $saveManager->outLine("  Contents: {$stats['contents']}");
            $saveManager->outLine("  Sentences: {$stats['sentences']}");
            $saveManager->outLine("");
        }
        
        $saveManager->getAllDocuments();
        //getFailedDocuments( $parser );
    }
} catch (Exception $e) {
    if (isset($saveManager)) {
        $saveManager->outLine("");
        $saveManager->outLine("✗ Error: " . $e->getMessage());
        $saveManager->outLine($e->getTraceAsString());
    } else {
        echo "\n✗ Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}
?>

<?php
include 'saveFuncs.php';

$sourceID = (isset( $argv[1] ) ) ? $argv[1] : '';
if( $sourceID ) {
    $options = [
        'force' => true,
        'debug' => true,
    ];
    updateDocument( $sourceID, $options );
} else {
    echo "Specify a sourceID\n";
}
?>

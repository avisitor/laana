<?php
header('Content-type: text/plain');
include_once 'db/funcs.php';
include_once 'lib/GrammarScanner.php';
//echo( var_export( $_POST, true) . "\n" );
$sourceID = $_POST['sourceid'];
$sourceName = $_POST['sourcename'];
$text = $_POST['sentences'];
echo "sourceID: $sourceID  length=" . strlen($sourceID) . "\n";
echo "sourceName: $sourceName\n";
$laana = new Laana();
if( !$sourceID || strlen($sourceID) < 1 ) {
    $row = $laana->addSource( $sourceName );
    if( isset( $row['sourceid'] ) ) {
        $sourceid = $row['sourceid'];
        echo "Added sourceID $sourceID\n";
    } else {
        echo "Failed to add source\n";
    }
}
$sentences = explode( "\n", $text);
$count = $laana->addSentences( $sourceID, $sentences);
echo "$count sentences added\n";

$scanner = new \Noiiolelo\GrammarScanner($laana);
$patternCount = $scanner->updateSourcePatterns($sourceID);
echo "$patternCount grammar patterns saved\n";
?>

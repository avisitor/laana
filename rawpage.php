<?php
include 'db/parsehtml.php';

$sourceID = $_GET['id'] ?: '';
$type = isset($_GET['simplified']) ? 'text' : 'html';

if( $sourceID ) {
    $sql = "select $type from contents where sourceid = :sourceid";
    $values = [
        'sourceid' => $sourceID,
    ];
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    if( $row[$type] ) {
        echo str_replace( "\n", "<br />\n", $row[$type] );
    }
}
?>

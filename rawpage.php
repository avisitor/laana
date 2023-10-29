<?php
include 'db/parsehtml.php';

$sourceID = $_GET['id'] ?: '';

if( $sourceID ) {
    $sql = "select html from contents where sourceid = :sourceid";
    $values = [
        'sourceid' => $sourceID,
    ];
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    if( $row['html'] ) {
        echo $row['html'];
    }
}
?>

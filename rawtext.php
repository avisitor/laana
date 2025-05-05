<?php
include 'db/funcs.php';

$sourceID = $_GET['id'] ?: '';
if( $sourceID ) {
    $sql = "select text from " . CONTENTS . " where sourceid = :sourceid";
    $values = [
        'sourceid' => $sourceID,
    ];
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    echo $row['text'] . "\n";
}
?>

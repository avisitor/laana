<?php
include 'db/funcs.php';

$sourceID = $_GET['id'] ?: '';
$type = isset($_GET['simplified']) ? 'text' : 'html';

if( $sourceID ) {
    $sql = "select $type from contents where sourceid = :sourceid";
    $values = [
        'sourceid' => $sourceID,
    ];
    $db = new DB();
    $row = $db->getOneDBRow( $sql, $values );
    $text = '';
    if( $row[$type] ) {
        if( $type == 'text' ) {
            $text = str_replace( "\n", "<br />\n", $row[$type] );
        } else {
            $text = $row[$type] . "\n";
        }
    }
    echo <<<EOF
<!DOCTYPE html>
<html lang="en" class="">
    <head>
EOF;
    include 'common-head.html';
    echo <<<EOF
        <title>$sourceID $type - Noiʻiʻōlelo</title>
        <style>
            body {
            padding: .2em;
            }
        </style>
    </head>
    <body><div class="rawtext">
$text
EOF;
}
echo <<<EOF
    </div></body>
</html>
EOF;
?>

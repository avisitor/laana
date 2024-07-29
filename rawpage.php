<?php
include 'db/funcs.php';

$sourceID = $_GET['id'] ?: '';
$type = isset($_GET['simplified']) ? 'text' : 'html';

if( $sourceID ) {
    $sql = "select $type from " . CONTENTS . " where sourceid = :sourceid";
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
?>

<!DOCTYPE html>
<html lang="en" class="">
    <head>

<?php include 'common-head.html'; ?>

        <title><?=$sourceID?> <?=$type?> - Noiʻiʻōlelo</title>
        <style>
            body {
            padding: .2em;
            }
        </style>
        <script>
            $(document).ready(function() {
                setTimeout( function() {console.log( "reveal" );reveal();}, 1 );
                $('img').css( 'max-width', '100%' );
            });
        </script>
    </head>
    <body>
      <div class="rawtext">
      <?=$text?>

<?php
}
?>

      </div>
    </body>
</html>

<?php
include 'db/funcs.php';

$sql = "select sourceid from " . CONTENTS . " where length(text) > 10";
$db = new DB();
$rows = $db->getDBRows( $sql );
foreach( $rows as $row ) {
    echo $row['sourceid'] . "\n";
}
?>

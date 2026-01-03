<?php
// Delete records in the sentences and contents tables that do not have corresponding records in sources

$dir = dirname(__DIR__, 1);
require_once $dir . '/db/funcs.php';

ini_set('memory_limit', '2048M');

function cleanup( $table ) {
    echo "Cleaning up orphan source IDs in $table\n";
    $db = new DB();
    $sql = "select c.sourceid from $table c left join sources s on c.sourceid = s.sourceid where isnull(link)";
    $rows = $db->getDBRows( $sql );
    foreach( $rows as $row ) {
        $sourceid = $row['sourceid'];
        echo "$sourceid\n";
        $sql = "delete from $table where sourceid = $sourceid";
        $db->executePrepared( $sql );
    }
}
cleanup( "sentences" );
cleanup( "contents" );
?>

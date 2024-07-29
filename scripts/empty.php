<?php
// Find records in sources that do not have data in contents

$dir = dirname(__DIR__, 1);
require_once $dir . '/db/funcs.php';

ini_set('memory_limit', '2048M');

function findMissing( $table, $field ) {
    echo "Finding missing source IDs in $table\n";
    $db = new DB();
    $sql = "select s.sourceid from sources s left join $table c on c.sourceid = s.sourceid where isnull($field) order by s.sourceid";
    $rows = $db->getDBRows( $sql );
    foreach( $rows as $row ) {
        $sourceid = $row['sourceid'];
        echo "$sourceid\n";
        //$sql = "delete from $table where sourceid = $sourceid";
        //$db->executeSQL( $sql );
    }
}
findMissing( "sentences", "hawaiiantext" );
findMissing( "contents", "text" );


?>

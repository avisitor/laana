<?php
include 'saveFuncs.php';

$groupname = (isset( $argv[1] ) ) ? $argv[1] : '';
if( $groupname ) {
    $db = new DB();
    foreach( [SENTENCES, CONTENTS, SOURCES] as $table ) {
        $sql = "delete from $table where sourceid in (select sourceid from " . SOURCES . " where groupname = '$groupname')";
        $values = [
            'table' => $table,
            'groupname' => $groupname,
        ];
        echo "$sql\n";
        $result = $db->executeSQL( $sql );
        echo "Result for $table: $result\n";
    }
} else {
    $values = join( ",", array_keys( $parsermap ) );
    echo "Specify a groupname ($values)\n";
}
?>

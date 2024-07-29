<?php
include '../db/funcs.php';
debuglog( $_REQUEST );
$start = (isset($_REQUEST['start'])) ? $_REQUEST['start'] : '';
$step = (isset($_REQUEST['step'])) ? $_REQUEST['step'] : '';
$laana = new Laana();
if( $start != '' && $step != '' ) {
    $end = $start + $step;
    $sql = "select o.*,count(sentenceID) count from sources o,sentences s where o.sourceID = s.sourceID and o.sourceID >= $start and o.sourceID < $end group by o.sourceID order by sourceName";
    $rows = $laana->getDBRows( $sql );
    if( sizeof( $rows ) > 0 ) {
        echo json_encode( $rows );
    }
    return;
}

echo json_encode( $laana->getSources() );
return;

$start = 0;
$step = 1000;
do {
    $sql = "select o.*,count(sentenceID) count from sources o,sentences s where o.sourceID = s.sourceID group by o.sourceID order by sourceName limit $step offset $start";
    $rows = $laana->getDBRows( $sql );
    if( sizeof( $rows ) > 0 ) {
        echo json_encode( $rows );
        ob_implicit_flush();
        ob_flush();
        flush();
    }
    $start += step;
} while( sizeof( $rows ) > 0 );
?>
    

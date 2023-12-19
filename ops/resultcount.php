<?php
header('Content-type: text/plain');
include '../db/funcs.php';
$word = isset($_GET['search']) ? $_GET['search'] : "";
$pattern = isset($_GET['searchpattern']) ? $_GET['searchpattern'] : "any";
if( $word ) {
    if( $pattern == 'regex' ) {
        $word = urlencode( $word );
    }
}
$nodiacriticals = ( isset( $_REQUEST['nodiacriticals'] ) && $_REQUEST['nodiacriticals'] == 1 ) ? 1 : 0;
$options = [];
if( $nodiacriticals ) {
    $options['nodiacriticals'] = true;
}
$options['count'] = true;
$count = 0;
if( $word && $pattern ) {
    $laana = new Laana();
    $count = $laana->getMatchingSentenceCount( $word, $pattern, -1, $options );
}
echo "$count";
?>

<?php
header('Content-type: text/plain');
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/utils.php';

$word = isset($_GET['search']) ? $_GET['search'] : "";
$pattern = isset($_GET['searchpattern']) ? $_GET['searchpattern'] : "any";
$nodiacriticals = ( isset( $_REQUEST['nodiacriticals'] ) && $_REQUEST['nodiacriticals'] == 1 ) ? 1 : 0;
$options = [];
if( $nodiacriticals ) {
    $options['nodiacriticals'] = true;
}
$options['count'] = true;
$count = 0;
if( $word && $pattern ) {
    $count = $provider->getMatchingSentenceCount( $word, $pattern, -1, $options );
}
echo "$count";
?>

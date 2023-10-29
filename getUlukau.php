<?php
include_once 'db/parsehtml.php';
include_once 'db/funcs.php';

set_time_limit(120);

$url = $_GET['url'];
if( $url ) {
    $parser = new UlukauHtml( ['synchronousOutput' => true] );
    $sentences = $parser->getFullText( $url );
} else {
    echo "";
}
?>

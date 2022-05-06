<?php
include '../db/parsehtml.php';
header('Content-Type: text/plain; charset=utf-8');
$parser = new TextParse();
$text = $_POST['text'];
$sentences = [];
if( $text ) {
    $sentences = $parser->getSentences( $text );
}
echo json_encode( $sentences, JSON_PRETTY_PRINT );
?>

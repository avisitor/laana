<?php
include 'db/parsehtml.php';
$parser = new AoLamaHtml();

//$pages = $parser->getPageList();
//var_export( $ );

$url = "https://keaolama.org/2022/05/03/05-02-22/";
$sentences = $parser->extractSentences( $url );
var_export( $sentences );
?>

<?php
include '../db/parsehtml.php';
$parser = new AoLamaHtml();

//$pages = $parser->getPageList();
//var_export( $ );

$url = "https://keaolama.org/2022/05/03/05-02-22/";

$dom = $parser->fetch( $url );
$date = $parser->extractDate( $dom );
echo "$date\n";
return;


$sentences = $parser->extractSentences( $url );
var_export( $sentences );
?>

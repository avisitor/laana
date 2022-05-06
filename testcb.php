<?php
include 'db/parsehtml.php';
$parser = new CBHtml();


$text = "Ua kākoʻo ʻia kēia papahana e ka ʻOhana o Harry Nathaniel, Levani Lipton, ka ʻOhana Mar, a me Lisa Kleissner.";
$newtext = $parser->prepareRaw( $text );
echo "CBHtml::process after prepareRaw: " . $newtext . "\n";
return;

//$pages = $parser->getPageList();
//var_export( $ );

$url = "https://keaolama.org/2022/05/03/05-02-22/";
$url = "https://www.civilbeat.org/2022/05/hoʻoulu-ka-pani-alahele-ma-ke-awawa-o-waipiʻo-i-ka-huliamahi-o-ke-kaiaulu-no-ka-paipai-kanawai/";
$url = "https://www.civilbeat.org/2022/04/niele-%ca%bbia-ka-kai-kahele-%ca%bboihana-ma-hawaiian-airlines/";
$sentences = $parser->extractSentences( $url );
var_export( $sentences );
?>

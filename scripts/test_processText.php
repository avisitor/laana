<?php
require_once __DIR__ . '/../db/parsehtml.php';
require_once __DIR__ . '/../scripts/parsers.php';

echo "PHP_BINARY: " . PHP_BINARY . "\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";
//xdebug_break();

// Usage: php db/test_processText.php <sourceid>
if ($argc < 2) {
    fwrite(STDERR, "Usage: php db/test_processText.php <sourceid>\n");
    exit(1);
}
setDebug(true);
$sourceid = $argv[1];

// Fetch metadata for the sourceid
$meta_url = "https://noiiolelo.worldspot.org/api.php/source/$sourceid?details";
$meta_json = file_get_contents($meta_url);
if ($meta_json === false) {
    fwrite(STDERR, "Failed to fetch metadata for sourceid $sourceid\n");
    exit(2);
}
$meta = json_decode($meta_json, true);
if (!isset($meta['groupname'])) {
    fwrite(STDERR, "No groupname found in metadata for sourceid $sourceid\n");
    exit(3);
}
$groupname = $meta['groupname'];
$parser = isset($parsermap[$groupname]) ? $parsermap[$groupname] : new HtmlParse();
printf("groupname: %s\n", $groupname);
printf("parser class: %s\n", get_class($parser));
$sentences = $parser->extractSentencesFromDatabase($sourceid, ['preprocess' => true]);

/*
$text = file_get_contents($url);
//echo "Fetched HTML content from: $url\n$text\n";
$obj = json_decode($text, true);
if ( !$obj ) {
    fwrite(STDERR, "No content found for sourceid $sourceid\n");
    exit(4);
}
$text = $obj['html'];
if (empty($text)) {
    fwrite(STDERR, "No HTML content found for sourceid $sourceid\n");
    exit(4);
}
$sentences = $parser->extractSentencesFrom($text, ['preprocess' => true]);
*/


foreach ($sentences as $i => $line) {
    printf("[%d] %s\n", $i+1, $line);
}
echo "\n\n";
foreach ($parser->discarded as $i => $line) {
     printf("DISCARDED [%d] %s\n", $i+1   , $line);
}


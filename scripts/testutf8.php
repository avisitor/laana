<?php
//include 'saveFuncs.php';

function show( $var ) {
    echo( var_export( $var, true ) . "\n" );
}

$dir = dirname(__DIR__, 1);
require_once $dir . '/db/funcs.php';
include_once $dir . '/db/parsehtml.php';
include_once $dir . '/scripts/parsers.php';

setDebug( true );
$laana = new Laana();

function getParser( $source ) {
    global $parsermap;
    echo "getRemoteRaw()\n";
    $groupname = $source['groupname'];
    $url = $source['link'];
    $parser = isset($parsermap[$groupname]) ? $parsermap[$groupname] : new HtmlParse();
    $parser->initialize( $url );
    show( $parser );
    printf("groupname: %s\n", $groupname);
    printf("parser class: %s\n", get_class($parser));
    printf("url: %s\n", $url);
    return $parser;
}

function getSentences( $source ) {
    $url = $source['link'];
    $parser = getParser( $source );
    echo "getSentences()\n";
    $sentences = $parser->extractSentences( $url );
    return $sentences;
}

function getContent( $source ) {
    $url = $source['link'];
    $parser = getParser( $source );
    echo "getContent()\n";
    $text = $parser->getContents( $url, [] );
    return $text;
}

function getRawContent( $source ) {
    $url = $source['link'];
    $parser = getParser( $source );
    echo "getRawContent()\n";
    $text = $parser->getRaw( $url, [] );
    return $text;
}

function getSourceByRest( $sourceid ) {
    $url = "https://noiiolelo.org/api.php/source/$sourceid";
    $text = file_get_contents( $url );
    $source = (array)json_decode( $text );
    return $source;
}

$groupname = 'ulukau';
//$sources = $laana->getSources( $groupname );
//show( $sources );
//return;

$sourceid = 6524;


$source = $laana->getSource( $sourceid );
show( $source );
#return;

$sentences = getSentences( $source );
show( $sentences );
return;

$text = getRawContent( $source );
echo strip_tags($text) . "\n";
return;


$text = $laana->getRawText( $sourceid );
$corrupt_string = $text;
echo "$text\n";
$bin = chunk_split(bin2hex($text), 2, ' ');
echo "$bin\n";
$text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
echo "$text\n";
$bin = chunk_split(bin2hex($text), 2, ' ');
echo "$bin\n";
$fix_map = [
    // Corrupt "Ä€"  => Correct "ā"
    "\xC3\x84\xC2\x80" => "\xC4\x81",

    // Corrupt "â€˜"  => Correct "‘" (ʻokina)
    "\xC3\xA2\xC2\x80\xC2\x98" => "\xE2\x80\x98",

    // Corrupt "Å«"   => Correct "ū"
    "\xC3\x85\xC2\xAB" => "\xC5\xAB",

    // This sequence "Â" appears in your hex dump for "ū"
    "\xC2\x8D" => "",
];

$fixed_string = str_replace(array_keys($fix_map), array_values($fix_map), $corrupt_string);

echo "$fixed_string\n";

return;


$groupname = 'nupepa';
$groupname = 'ulukau';
$sources = $laana->getSources( $groupname );
///show( $sources );
foreach( $sources as $source ) {
    $sourceid = $source['sourceid'];
    $text = $laana->getText( $sourceid );
    $isutf8 = (mb_check_encoding($text, 'UTF-8')) ? "clean" : "dirty";
    echo "$groupname $sourceid $isutf8\n";
    //show( $source );
    //break;
}
return;

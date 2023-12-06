<?php
include 'saveFuncs.php';

setDebug( true );

$url =  'https://ehooululahui.maui.hawaii.edu/?page_id=65';
$url = 'https://ehooululahui.maui.hawaii.edu/?page_id=1327'; // Kalapana
// ‘Aukelenuia‘īkū
$laana = new Laana();
$sourceid = 7688;
$sourceid = 7684;
$parser = new EhoouluLahuiHTML();
$parser->initialize( $url );
$text = $laana->getRawText( $sourceid );
$sentences = $parser->extractSentencesFromHTML( $text );
echo( var_export( $sentences, true ) . "\n" );
return;


$url = "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-";
$parser = new BaibalaHTML();

$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-MAKANA&e=-------en-20--1--txt-txPT-----------";
$parser = new UlukauHTML();
$options = ['doXML'=>false,];
//$options = ['doXML'=>true,];

//$text = $parser->getRawText( $url, $options );
//file_put_contents( "/tmp/raw.html", $text );
//return;

$text = file_get_contents( "/tmp/raw.html" );
//$text = $parser->preprocessHTML($text);


//$sentences = $parser->extractSentencesFromHTML( $text );
$sentences = $parser->extractSentences( $url );
echo( var_export( $sentences, true ) . "\n" );
return;


echo "$text\n";

//return;

//$text = $parser->convertEncoding($text);
//$text = $parser->preprocessHTML($text);
//echo "$text\n";

$text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
$dom = $parser->getDOMFromString( $text, $options );
$text = $dom->saveHTML();
$text = $parser->preprocessHTML($text);
//$text = $parser->convertEncoding($text);
echo "$text\n";

//$parser = new HTMLParse();
//$text = $parser->preprocessHTML( $text );
//echo "$text\n";
return;


$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-MAKANA&e=-------en-20--1--txt-txPT-----------";
$text = file_get_contents( $url );
$dom = new DOMDocument;
$dom->encoding = 'utf-8';
libxml_use_internal_errors(true);
$dom->loadHTML( $text );
$xpath = new DOMXpath($dom);
$filter = '//div[contains(@class,"label")]';
$filter = '//div[text()="Author(s):"]';
$p = $xpath->query( $filter );
if( $p->length > 0 ) {
    $element = $p->item(0);
    //$outerHTML = $element->ownerDocument->saveHTML($element);
    //echo "outerHTML: $outerHTML\n";
    $next = $element->nextSibling;
    if( $next ) {
        //$outerHTML = $next->ownerDocument->saveHTML($next);
        //echo "outerHTML: $outerHTML\n";
        $authors = $next->nodeValue;
        echo "authors: $authors\n";
    }
}

return;


$laana = new Laana();
$sourcename = 'Ulukau: ‘O Wai‘anae: ko‘u wahi noho (Wai‘anae: where I live)';
$source = $laana->getSourceByName( $sourcename );
echo var_export( $source, true ) . "\n";
$source['authors'] = 'Julie Stewart Willia';
$laana->addSource( $sourcename, $source );
$source = $laana->getSourceByName( $sourcename );
echo var_export( $source, true ) . "\n";

return;

$url = "https://www.civilbeat.org/2021/04/ke-unuhi-aku-nei-%ca%bbo-civil-beat-i-mau-mo%ca%bbolelo-ma-ka-%ca%bbolelo-hawai%ca%bbi/";
$url = "https://www.civilbeat.org/2023/10/koikoi-%ca%bbo-tokuda-e-poina-%ca%bbole-%ca%bbo-maui-i-ka-%ca%bbaha%ca%bbolelo-lahui/";
$text = file_get_contents( $url );
$dom = new DOMDocument;
$dom->encoding = 'utf-8';
libxml_use_internal_errors(true);
$dom->loadHTML( $text );
$xpath = new DOMXpath($dom);
$filter = '//meta[contains(@property, "article:published_time")]';
$p = $xpath->query( $filter );
foreach( $p as $element ) {
    $outerHTML = $element->ownerDocument->saveHTML($element);
    echo "outerHTML: $outerHTML\n";
    $parts = explode( "T", $element->getAttribute( 'content' ) );
    $date = $parts[0];
    echo "$date\n";
}

return;

$url =
    "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-";
//$text = file_get_contents( $url );

$laana = new Laana();
$sourceid = 2709;
$text = $laana->getRawText( $sourceid );
$dom = new DOMDocument;
$dom->encoding = 'utf-8';
libxml_use_internal_errors(true);
$dom->loadHTML( $text );
$xpath = new DOMXpath($dom);
$filters = [
    "//img" => "src",
    "//a" => "href",
];
$attrs = [
    "src",
    "href",
];
$basename = "https://nupepa.org";
foreach( $filters as $filter => $name ) {
    $p = $xpath->query( $filter );
    foreach( $p as $element ) {
        $outerHTML = $element->ownerDocument->saveHTML($element);
        echo "outerHTML: $outerHTML\n";
        $changed = 0;
        foreach( $attrs as $attr ) {
            $url = $element->getAttribute($attr);
            if( preg_match( '/^\//', $url ) ) {
                $url = $basename . $url;
                $element->setAttribute( $attr, $url );
                $changed++;
            }
        }
        if( $changed ) {
            $outerHTML = $element->ownerDocument->saveHTML($element);
            echo "new outerHTML: $outerHTML\n";
        }
    }
}
$text = $dom->saveHTML();
$laana->addRawText( $sourceid, $text );
//echo "$text\n";

return;

$parser = new UlukauHTML();
$url = "https://nupepa.org/gsdl2.5/cgi-bin/nupepa?e=p-0nupepa--00-0-0--010---4-----text---0-1l--1haw-Zz-1---20-about---0003-1-0000utfZz-8-00&a=d&cl=CL2/gsdl2.5/cgi-bin/nupepa?e=d-0nupepa--00-0-0--010---4-----text---0-1l--1haw-Zz-1---20-about---0003-1-0000utfZz-8-00&a=d&cl=CL2.12&d=HASH01d2061975f8458eb0589566&gg=text";
$sourceName = "Ka Hae Hawaii: KA HAE HAWAII. Buke I, Helu 4, Aoao 13. Malaki 26, 1856.";
$text = saveRaw( $parser, $url, $sourceName );
$laana = new Laana();
$source = $laana->getSourceByName( $sourceName );
$sourceID = $source['sourceid'];
if( $text && $sourceID ) {
    addSentences( $parser, $sourceID, $link, $text );
}
return;


/*
   $pages = $parser->getPageList();
   printObject( $pages );
 */
$url = "https://www2.hawaii.edu/~kroddy/moolelo/20,000legue/helu1.htm";
$url = "https://www2.hawaii.edu/~kroddy/moolelo/ivanaho/mokuna1.htm";
$sentences = $parser->extractSentences( $url );
printObject( $sentences );
return;


$parser = new NupepaHtml();
$url = "https://nupepa.org/gsdl2.5/cgi-bin/nupepa?e=d-0nupepa--00-0-0--010---4-----text---0-1l--1haw-Zz-1---20-about---0003-1-0000utfZz-8-00&a=d&cl=CL2.24&d=HASHc7a09b5ca7f327b32ba22a&gg=text";
$sentences = $parser->extractSentences( $url );
printObject( $sentences );
return;

/*
   $baseurl = "https://www.staradvertiser.com/category/editorial/kauakukalahale/";
   $text = '';
   $pagenr = 2;
   while( strpos( $text, "NOT FOUND" ) === false ) {
   echo "$pagenr\n";
   $url = $baseurl . $pagenr;
   $text = $parser->getRaw( $url );
   $pagenr++;
   }
 */
$pages = $parser->getPageList();
echo( var_export( $pages, true ) . "\n" );
return;

$url = 'https://www.staradvertiser.com/2023/10/21/editorial/kauakukalahale/column-e-hoaei-paha-i-ke-one-o-luhi/';
$sentences = $parser->extractSentences( $url );
echo( var_export( $sentences, true ) . "\n" );
return;

$pages = $parser->getPageList();
echo( var_export( $pages, true ) . "\n" );
return;

$pat = '/(kek[aĀā]hi|w[aĀā]hi)/ui';
$repl = '<span class="match">$1</span>';
$hawaiiantext = 'Ua ike no au aole loa koʻu kaikuaana i mare me kekahi wahine a hiki wale i kona make ana, wahi a ua lapuwale la me ka naka ana o kona kino.';
$sentence = preg_replace($pat, $repl, $hawaiiantext );
echo "$sentence\n";
return;

$text = '  “A inā ʻaʻole ʻo Keawemauhili kou inoa, a laila, ʻo wai lā hoʻi kou inoa, e kēia kanaka kūlana aliʻi?” “ ʻO Keaweʻōpala koʻu inoa, a ʻaʻole hoʻi ʻo Keawemauhili.” Ia manawa koke nō i pane koke mai ai kekahi wahi kanaka i waena o kēlā poʻe e kū mai ana a ʻākeʻakeʻa i ko lākou nei alahele, “ ʻEā, ua pololei ka ʻōlelo a kēlā aliʻi, ʻo Keaweʻōpala ʻiʻo nō kēia, ʻoiai, aia nō hoʻi ka pōpō ʻōpala ke kau maila i kona maka hema. E hoʻokuʻu kākou iā ia nei me kāna ʻohana, a ʻaʻole hoʻi e kau aku ko kākou mau lima ma luna o ia nei, o lohe aku auaneʻi ʻo Paiʻea i kēia hana a kākou, a papapau kākou i ka make ma muli o ka limanui ʻana i ko ia ala hulu makua kāne.” Ma muli o kēlā ʻōlelo a kēlā wahi koa i kona poʻe, ua ʻano kau ʻiʻo maila ke ʻano makaʻu i waena o kēia mau koa lanakila, a ʻo ko lākou hoʻokaʻawale aʻela nō ia i ke alahele no lākou nei e hele aku nei. Hala aʻela hoʻi kēia puʻumake o lākou nei, akā, ʻaʻole ia he kumu e hoʻopau ai ʻo Kapiʻolani i ka uē ʻana, a ua lilo ihola i mea hoʻokaumaha loa i ka manaʻo o Keawemauhili, ʻoiai, ua ʻike ihola nō ʻo ia i ko lākou pōʻino ma muli o kēia hana mau o ke kaikamahine i ka uē, a lilo ʻiʻo nō paha ia i mea kāhea aku i ka poʻe koa lanakila e ʻimi nei i loko o kēia wao kele i ka poʻe pio.';
$text = 'A inā ʻaʻole ʻo Keawemauhili kou inoa, a laila, ʻo wai lā hoʻi kou inoa, e kēia kanaka kūlana aliʻi? ʻO Keaweʻōpala koʻu inoa, a ʻaʻole hoʻi ʻo Keawemauhili. Ia manawa koke nō i pane koke mai ai kekahi wahi kanaka i waena o kēlā poʻe e kū mai ana a ʻākeʻakeʻa i ko lākou nei alahele, ʻEā, ua pololei ka ʻōlelo a kēlā aliʻi, ʻo Keaweʻōpala ʻiʻo nō kēia, ʻoiai, aia nō hoʻi ka pōpō ʻōpala ke kau maila i kona maka hema.';
$lines = preg_split('/(?<=[.?!])\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
//$lines = preg_split('/[.?!]/g', $text, -1, PREG_SPLIT_NO_EMPTY);
//$lines = preg_split('/[.?!]/g', $text, -1);
//$lines = preg_split('/[\s]+/', $text, -1);
//echo "$text\n";
echo( var_export( $lines, true ) . "\n" );
//print_r( $lines );
$lines = preg_split( '(?<=[!?.])(?:$|\s+(?=\p{Lu}\p{Ll}*\b))', $text, -1, PREG_SPLIT_NO_EMPTY );
echo( var_export( $lines, true ) . "\n" );

?>

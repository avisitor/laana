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
//setDebug( false );
$laana = new Laana();

function extractFormatted($str) {
    // Match the date portion: day month year
    if (preg_match('/^(.*?):\s*(\d{4}-\d{2}-\d{2})$/', $str, $matches)) {
        $title = trim($matches[1]);
        $formattedDate = $matches[2];
        return "$title: $formattedDate";
    } else if (preg_match('/\b(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})\b/', $str, $matches, PREG_OFFSET_CAPTURE)) {
        $day = $matches[1][0];
        $month = $matches[2][0];
        $year = $matches[3][0];
        $dateStr = "$day $month $year";

        // Convert to YYYY-MM-DD
        $date = DateTime::createFromFormat('j F Y', $dateStr);
        $formattedDate = $date ? $date->format('Y-m-d') : 'Invalid date';

        // Extract title before the date match
        $titleRaw = substr($str, 0, $matches[0][1]);

        // Remove trailing punctuation and weekday names
        $title = preg_replace('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),?\s*$/i', '', $titleRaw);
        $title = rtrim($title, ", ");
        //echo "Title: $title\n";
        //echo "Date: $formattedDate\n\n";
        return "$title: $formattedDate";
    } else {
        echo "No date found in: $str\n\n";
        return "";
    }
}

function convertNupepaSourceNames() {
    $groupname = 'nupepa';
    $laana = new Laana();
    $sources = $laana->getSources( $groupname );
    $db = new DB();
    foreach( $sources as $source ) {
        $sourceid = $source['sourceid'];
        $sourcename = $source['sourcename'];
        $revised = extractFormatted( $sourcename );
        if( $revised ) {
            $sql = "update sources set sourcename = '$revised' where sourceid = $sourceid";
            echo "$sql\n";
            $db->executeSQL( $sql );
        }
    }
}
convertNupepaSourceNames();
return;


$parser = new NupepaHTML();
$url = "https://nupepa.org/?a=d&d=KNK18630606-01.1.3&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0";
$url = "https://nupepa.org/?a=d&d=KNK18630606-01.1.1&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0";
$parser->initialize( $url );
$text = $parser->getRawText( $url );
echo "$text\n";
return;

$parser = new UlukauLocal();
$url = (isset( $argv[1] ) ) ? $argv[1] : '';
if( $url ) {
    echo "$url\n";
    //url = $dir . "/ulukau/EBOOK-AIAIHEA.html";
    $sentences = $parser->extractSentences( $url );
    show( $sentences );
}
return;

foreach( $pageList as $sourcename => $values ) {
    echo "$sourcename\n";
    $sentences = $parser->extractSentences( $values['url'] );
    show( $sentences );
}
return;

$pageList = $parser->getPageList();
show( $pageList );
return;

$parser = new TextParse();
$filename = "/webapps/worldspot.com/worldspot/render-proxy/output/EBOOK-HOAKAKAOLELO.txt";
$sentences = $parser->extractSentences( $filename );
show( $sentences );
return;

$url = 'https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-APLC04&e=-------en-20--1--txt-txPT-----------';
$parser = new UlukauHTML();
//$parser->initialize( $url );
$contents = $parser->getRawText( $url );
echo "$contents\n";
$options = [];
//$sentences = $parser->extractSentencesFromHTML( $contents, $options );
//show( $sentences );
return;

function getSentences( $source ) {
    global $parsermap;
    echo "getSentences()\n";
    $groupname = $source['groupname'];
    $url = $source['link'];
    $parser = isset($parsermap[$groupname]) ? $parsermap[$groupname] : new HtmlParse();
    $parser->initialize( $url );
    show( $parser );
    printf("groupname: %s\n", $groupname);
    printf("parser class: %s\n", get_class($parser));
    printf("url: %s\n", $url);
    //return;
    $sentences = $parser->extractSentences( $url );
    show( $sentences );
}

$sourceid = 14350;
if( $argc > 1 ) {
    $sourceid = $argv[1];
}
echo "sourceid: $sourceid\n";
$url = "https://noiiolelo.org/api.php/source/$sourceid";
$text = file_get_contents( $url );
$source = (array)json_decode( $text );
show( $source );
getSentences( $source );
return;




$rows = $laana->getSourceGroupCounts();
show( $rows );
return;

$groupname = 'nupepa';
$sources = $laana->getSources( $groupname );
show( $sources );
return;

function multipleSourceIDs() {
    $sql = "select link from sources group by link having count(*) > 1";
    //echo "$sql\n";
    $db = new DB();
    $rows = $db->getDBRows( $sql );
    foreach( $rows as $row ) {
        $link = $row['link'];
        echo "$link\n";
        //echo "  sourceid  sentenceCount  html text created\n";
        printf( "%8s %22s %10s %10s %20s\n", "sourceid", "sentenceCount", "html", "text", "created" );
        $sql = "select sourceid from sources where link = '$link' order by created desc";
        //echo "$sql\n";
        $idrows = $db->getDBRows( $sql );
        $count = 0;
        foreach( $idrows as $idrow ) {
            $sourceid = $idrow['sourceid'];
            $sql = "select count(*) c from sentences where sourceid = $sourceid";
            $sentenceRow = $db->getOneDBRow( $sql );
            $sentenceCount = $sentenceRow['c'];
            $sql = "select length(html) html,length(text) text,created from contents where sourceid = $sourceid";
            $contentsRow = $db->getOneDBRow( $sql );
            //echo "  $sourceid  $sentenceCount  {$contentsRow['html']} {$contentsRow['text']} {$contentsRow['created']}\n";
            printf( "%8d %22d %10d %10d %20s\n", $sourceid, $sentenceCount, $contentsRow['html'], $contentsRow['text'], $contentsRow['created'] );
            if( $count > 0 ) {
                $sql = "delete from sentences where sourceid = $sourceid";
                echo "$sql\n";
                $db->executeSQL( $sql );
                $sql = "delete from contents where sourceid = $sourceid";
                echo "$sql\n";
                $db->executeSQL( $sql );
                $sql = "delete from sources where sourceid = $sourceid";
                echo "$sql\n";
                $db->executeSQL( $sql );
            }
            $count++;
        }
    }
}

multipleSourceIDs();
return;

function sampleText() {
    $text = <<<EOF
<!DOCTYPE HTML>
<html lang="en">
    <head lang="en-US">
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="preload" href="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/fonts/source-serif-pro-v11-latin/source-serif-pro-v11-latin-600.woff2" as="font" crossorigin="anonymous" type="font/woff2">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

	<!-- This site is optimized with the Yoast SEO plugin v22.5 - https://yoast.com/wordpress/plugins/seo/ -->
	<title>Column: Ka hopena o ka \'eli\'eli noa | Honolulu Star-Advertiser</title>
	<link rel="canonical" href="https://www.staradvertiser.com/2024/07/27/editorial/kauakukalahale/column-ka-hopena-o-ka-aeeliaeeli-noa/">
	<meta property="og:locale" content="en_US">
	<meta property="og:type" content="article">
	<meta property="og:title" content="Column: Ka hopena o ka \'eli\'eli noa | Honolulu Star-Advertiser">
	<meta property="og:description" content="Synopsis: Deep-sea mining can deplete the amount of oxygen necessary to sustain life on the ocean floor. It makes sense to place a moratorium on such activity until the consequences are better understood.">
	<meta property="og:url" content="https://www.staradvertiser.com/2024/07/27/editorial/kauakukalahale/column-ka-hopena-o-ka-aeeliaeeli-noa/">
	<meta property="og:site_name" content="Honolulu Star-Advertiser">
	<meta property="article:publisher" content="https://www.facebook.com/staradvertiser/">
	<meta property="article:published_time" content="2024-07-27T10:05:00+00:00">
	<meta property="article:modified_time" content="2024-07-27T06:10:58+00:00">
	<meta property="og:image" content="https://www.staradvertiser.com/wp-content/uploads/2024/07/web1_USATSI_23769537.jpg">
	<meta property="og:image:width" content="760">
	<meta property="og:image:height" content="407">
	<meta property="og:image:type" content="image/jpeg">
	<meta name="author" content="None">
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:creator" content="@staradvertiser">
	<meta name="twitter:site" content="@staradvertiser">
	<meta name="twitter:label1" content="Written by">
	<meta name="twitter:data1" content="None">
	<meta name="twitter:label2" content="Est. reading time">
	<meta name="twitter:data2" content="4 minutes">
	
	<!-- / Yoast SEO plugin. -->


<link rel="dns-prefetch" href="//securepubads.g.doubleclick.net">
<link rel="dns-prefetch" href="//d3plfjw9uod7ab.cloudfront.net">
<link rel="dns-prefetch" href="//static.chartbeat.com">
<link rel="dns-prefetch" href="//product.instiengage.com">
<link rel="dns-prefetch" href="//a.teads.tv">
<link rel="dns-prefetch" href="//s.ntv.io">
<link rel="alternate" type="application/rss+xml" title="Honolulu Star-Advertiser  Feed" href="https://www.staradvertiser.com/feed/">
<link rel="alternate" type="application/rss+xml" title="Honolulu Star-Advertiser  Comments Feed" href="https://www.staradvertiser.com/comments/feed/">
    
    <!-- Google Tag Manager -->
    
    <!-- End Google Tag Manager -->
    <link rel="alternate" type="application/rss+xml" title="Honolulu Star-Advertiser  Column: Ka hopena o ka \'eli\'eli noa Comments Feed" href="https://www.staradvertiser.com/2024/07/27/editorial/kauakukalahale/column-ka-hopena-o-ka-aeeliaeeli-noa/feed/">

<style type="text/css">
img.wp-smiley,
img.emoji {
	display: inline !important;
	border: none !important;
	box-shadow: none !important;
	height: 1em !important;
	width: 1em !important;
	margin: 0 0.07em !important;
	vertical-align: -0.1em !important;
	background: none !important;
	padding: 0 !important;
}
</style>
	<style id="elasticpress-related-posts-style-inline-css" type="text/css">
.editor-styles-wrapper .wp-block-elasticpress-related-posts ul,.wp-block-elasticpress-related-posts ul{list-style-type:none;padding:0}.editor-styles-wrapper .wp-block-elasticpress-related-posts ul li a>div{display:inline}

</style>
<style id="classic-theme-styles-inline-css" type="text/css">
/*! This file is auto-generated */
.wp-block-button__link{color:#fff;background-color:#32373c;border-radius:9999px;box-shadow:none;text-decoration:none;padding:calc(.667em + 2px) calc(1.333em + 2px);font-size:1.125em}.wp-block-file__button{background:#32373c;color:#fff;text-decoration:none}
</style>
<style id="global-styles-inline-css" type="text/css">
body{--wp--preset--color--black: #000000;--wp--preset--color--cyan-bluish-gray: #abb8c3;--wp--preset--color--white: #ffffff;--wp--preset--color--pale-pink: #f78da7;--wp--preset--color--vivid-red: #cf2e2e;--wp--preset--color--luminous-vivid-orange: #ff6900;--wp--preset--color--luminous-vivid-amber: #fcb900;--wp--preset--color--light-green-cyan: #7bdcb5;--wp--preset--color--vivid-green-cyan: #00d084;--wp--preset--color--pale-cyan-blue: #8ed1fc;--wp--preset--color--vivid-cyan-blue: #0693e3;--wp--preset--color--vivid-purple: #9b51e0;--wp--preset--gradient--vivid-cyan-blue-to-vivid-purple: linear-gradient(135deg,rgba(6,147,227,1) 0%,rgb(155,81,224) 100%);--wp--preset--gradient--light-green-cyan-to-vivid-green-cyan: linear-gradient(135deg,rgb(122,220,180) 0%,rgb(0,208,130) 100%);--wp--preset--gradient--luminous-vivid-amber-to-luminous-vivid-orange: linear-gradient(135deg,rgba(252,185,0,1) 0%,rgba(255,105,0,1) 100%);--wp--preset--gradient--luminous-vivid-orange-to-vivid-red: linear-gradient(135deg,rgba(255,105,0,1) 0%,rgb(207,46,46) 100%);--wp--preset--gradient--very-light-gray-to-cyan-bluish-gray: linear-gradient(135deg,rgb(238,238,238) 0%,rgb(169,184,195) 100%);--wp--preset--gradient--cool-to-warm-spectrum: linear-gradient(135deg,rgb(74,234,220) 0%,rgb(151,120,209) 20%,rgb(207,42,186) 40%,rgb(238,44,130) 60%,rgb(251,105,98) 80%,rgb(254,248,76) 100%);--wp--preset--gradient--blush-light-purple: linear-gradient(135deg,rgb(255,206,236) 0%,rgb(152,150,240) 100%);--wp--preset--gradient--blush-bordeaux: linear-gradient(135deg,rgb(254,205,165) 0%,rgb(254,45,45) 50%,rgb(107,0,62) 100%);--wp--preset--gradient--luminous-dusk: linear-gradient(135deg,rgb(255,203,112) 0%,rgb(199,81,192) 50%,rgb(65,88,208) 100%);--wp--preset--gradient--pale-ocean: linear-gradient(135deg,rgb(255,245,203) 0%,rgb(182,227,212) 50%,rgb(51,167,181) 100%);--wp--preset--gradient--electric-grass: linear-gradient(135deg,rgb(202,248,128) 0%,rgb(113,206,126) 100%);--wp--preset--gradient--midnight: linear-gradient(135deg,rgb(2,3,129) 0%,rgb(40,116,252) 100%);--wp--preset--font-size--small: 13px;--wp--preset--font-size--medium: 20px;--wp--preset--font-size--large: 36px;--wp--preset--font-size--x-large: 42px;--wp--preset--spacing--20: 0.44rem;--wp--preset--spacing--30: 0.67rem;--wp--preset--spacing--40: 1rem;--wp--preset--spacing--50: 1.5rem;--wp--preset--spacing--60: 2.25rem;--wp--preset--spacing--70: 3.38rem;--wp--preset--spacing--80: 5.06rem;--wp--preset--shadow--natural: 6px 6px 9px rgba(0, 0, 0, 0.2);--wp--preset--shadow--deep: 12px 12px 50px rgba(0, 0, 0, 0.4);--wp--preset--shadow--sharp: 6px 6px 0px rgba(0, 0, 0, 0.2);--wp--preset--shadow--outlined: 6px 6px 0px -3px rgba(255, 255, 255, 1), 6px 6px rgba(0, 0, 0, 1);--wp--preset--shadow--crisp: 6px 6px 0px rgba(0, 0, 0, 1);}:where(.is-layout-flex){gap: 0.5em;}:where(.is-layout-grid){gap: 0.5em;}body .is-layout-flow > .alignleft{float: left;margin-inline-start: 0;margin-inline-end: 2em;}body .is-layout-flow > .alignright{float: right;margin-inline-start: 2em;margin-inline-end: 0;}body .is-layout-flow > .aligncenter{margin-left: auto !important;margin-right: auto !important;}body .is-layout-constrained > .alignleft{float: left;margin-inline-start: 0;margin-inline-end: 2em;}body .is-layout-constrained > .alignright{float: right;margin-inline-start: 2em;margin-inline-end: 0;}body .is-layout-constrained > .aligncenter{margin-left: auto !important;margin-right: auto !important;}body .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width: var(--wp--style--global--content-size);margin-left: auto !important;margin-right: auto !important;}body .is-layout-constrained > .alignwide{max-width: var(--wp--style--global--wide-size);}body .is-layout-flex{display: flex;}body .is-layout-flex{flex-wrap: wrap;align-items: center;}body .is-layout-flex > *{margin: 0;}body .is-layout-grid{display: grid;}body .is-layout-grid > *{margin: 0;}:where(.wp-block-columns.is-layout-flex){gap: 2em;}:where(.wp-block-columns.is-layout-grid){gap: 2em;}:where(.wp-block-post-template.is-layout-flex){gap: 1.25em;}:where(.wp-block-post-template.is-layout-grid){gap: 1.25em;}.has-black-color{color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-color{color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-color{color: var(--wp--preset--color--white) !important;}.has-pale-pink-color{color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-color{color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-color{color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-color{color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-color{color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-color{color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-color{color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-color{color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-color{color: var(--wp--preset--color--vivid-purple) !important;}.has-black-background-color{background-color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-background-color{background-color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-background-color{background-color: var(--wp--preset--color--white) !important;}.has-pale-pink-background-color{background-color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-background-color{background-color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-background-color{background-color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-background-color{background-color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-background-color{background-color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-background-color{background-color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-background-color{background-color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-background-color{background-color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-background-color{background-color: var(--wp--preset--color--vivid-purple) !important;}.has-black-border-color{border-color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-border-color{border-color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-border-color{border-color: var(--wp--preset--color--white) !important;}.has-pale-pink-border-color{border-color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-border-color{border-color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-border-color{border-color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-border-color{border-color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-border-color{border-color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-border-color{border-color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-border-color{border-color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-border-color{border-color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-border-color{border-color: var(--wp--preset--color--vivid-purple) !important;}.has-vivid-cyan-blue-to-vivid-purple-gradient-background{background: var(--wp--preset--gradient--vivid-cyan-blue-to-vivid-purple) !important;}.has-light-green-cyan-to-vivid-green-cyan-gradient-background{background: var(--wp--preset--gradient--light-green-cyan-to-vivid-green-cyan) !important;}.has-luminous-vivid-amber-to-luminous-vivid-orange-gradient-background{background: var(--wp--preset--gradient--luminous-vivid-amber-to-luminous-vivid-orange) !important;}.has-luminous-vivid-orange-to-vivid-red-gradient-background{background: var(--wp--preset--gradient--luminous-vivid-orange-to-vivid-red) !important;}.has-very-light-gray-to-cyan-bluish-gray-gradient-background{background: var(--wp--preset--gradient--very-light-gray-to-cyan-bluish-gray) !important;}.has-cool-to-warm-spectrum-gradient-background{background: var(--wp--preset--gradient--cool-to-warm-spectrum) !important;}.has-blush-light-purple-gradient-background{background: var(--wp--preset--gradient--blush-light-purple) !important;}.has-blush-bordeaux-gradient-background{background: var(--wp--preset--gradient--blush-bordeaux) !important;}.has-luminous-dusk-gradient-background{background: var(--wp--preset--gradient--luminous-dusk) !important;}.has-pale-ocean-gradient-background{background: var(--wp--preset--gradient--pale-ocean) !important;}.has-electric-grass-gradient-background{background: var(--wp--preset--gradient--electric-grass) !important;}.has-midnight-gradient-background{background: var(--wp--preset--gradient--midnight) !important;}.has-small-font-size{font-size: var(--wp--preset--font-size--small) !important;}.has-medium-font-size{font-size: var(--wp--preset--font-size--medium) !important;}.has-large-font-size{font-size: var(--wp--preset--font-size--large) !important;}.has-x-large-font-size{font-size: var(--wp--preset--font-size--x-large) !important;}
.wp-block-navigation a:where(:not(.wp-element-button)){color: inherit;}
:where(.wp-block-post-template.is-layout-flex){gap: 1.25em;}:where(.wp-block-post-template.is-layout-grid){gap: 1.25em;}
:where(.wp-block-columns.is-layout-flex){gap: 2em;}:where(.wp-block-columns.is-layout-grid){gap: 2em;}
.wp-block-pullquote{font-size: 1.5em;line-height: 1.6;}
</style>
<link rel="stylesheet" id="vfb-pro-css" href="https://staradvertiser.wpenginepowered.com/wp-content/plugins_redesign/vfb-pro/public/assets/css/vfb-style.min.css?ver=2019.05.10" type="text/css" media="all">
<link rel="stylesheet" id="bootstrap-min-stylesheet-css" href="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/css/bootstrap.min.css?ver=5.9.8" type="text/css" media="">
<link rel="stylesheet" id="main-stylesheet-css" href="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/css/style.css?ver=6.0.3" type="text/css" media="">
<link rel="stylesheet" id="weather-icons-css" href="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/css/weather-icons.min.css?ver=1.0.0" type="text/css" media="">
<link rel="stylesheet" id="icomoon-css" href="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/icomoon/style.css?ver=1.0.0" type="text/css" media="">
<link rel="stylesheet" id="elasticpress-facets-css" href="https://staradvertiser.wpenginepowered.com/wp-content/plugins_redesign/elasticpress/dist/css/facets-styles.css?ver=7d568203f3965dc85d8a" type="text/css" media="all">









<link rel="https://api.w.org/" href="https://www.staradvertiser.com/wp-json/"><link rel="alternate" type="application/json" href="https://www.staradvertiser.com/wp-json/wp/v2/posts/1337296"><link rel="EditURI" type="application/rsd+xml" title="RSD" href="https://www.staradvertiser.com/xmlrpc.php?rsd">
<link rel="shortlink" href="https://www.staradvertiser.com/?p=1337296">
<link rel="alternate" type="application/json+oembed" href="https://www.staradvertiser.com/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fwww.staradvertiser.com%2F2024%2F07%2F27%2Feditorial%2Fkauakukalahale%2Fcolumn-ka-hopena-o-ka-aeeliaeeli-noa%2F">
<link rel="alternate" type="text/xml+oembed" href="https://www.staradvertiser.com/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fwww.staradvertiser.com%2F2024%2F07%2F27%2Feditorial%2Fkauakukalahale%2Fcolumn-ka-hopena-o-ka-aeeliaeeli-noa%2F&amp;format=xml">

    
<meta name="keywords" content="Featured Columns">        
    </head>
    <body class="post-template-default single single-post postid-1337296 single-format-standard">
        <!-- Google Tag Manager (noscript) -->
    
    <!-- End Google Tag Manager (noscript) -->                    <div class="int-container">
                            <!-- /Hawaii/HSA/INT -->
            <div id="div-gpt-ad-int">
                
            </div>
                    </div>

            <div class="promo-container promo-top bg-dark p-3 text-center">
                            <!-- /Hawaii/HSA/Sliding -->
            <div id="div-gpt-ad-sliding" class="w-100">
                
            </div>
                    </div>
        
        <div class="full-page-wrapper bg-white">
            <!-- Menu -->
            <nav class="navbar navbar-expand-lg navbar-dark p-0 sticky-top" style="flex-wrap: wrap;">
                <div class="container" style="padding-top: 0.5rem; padding-bottom: 0.5rem;">
                    <button class="navbar-toggler d-inline-block mr-2" type="button" data-bs-toggle="collapse" href="#flyoutMenu" role="button" aria-expanded="false" aria-controls="flyoutMenu" aria-label="Toggle Sections" title="Sections" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Burger Icon\'}); return false">
                        <span class="icon-navicon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="icons navbar-nav me-auto">
                            <li class="nav-item mx-2"><a title="Search" class="nav-link" href="https://www.staradvertiser.com/search" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Search Icon\'});"><span class="icon-search"></span></a></li>
                            <li class="nav-item mx-2"><a title="Print Replica" class="nav-link" href="https://printreplica.staradvertiser.com" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Print Replica Icon\'});"><span class="icon-newspaper-o"></span></a></li>
                                                    </ul>

                        <ul class="navbar-nav">
                            <li class="nav-item mx-2"><a class="align-middle" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Tile\', \'event_label\': \'Hawaii Marketplace\'});" href="https://www.hawaii.com/market/?utm_source=opi-sa&amp;utm_medium=website&amp;utm_campaign=marketplace" target="_blank"><img class="align-middle" src="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/images/hcom-marketplace-button.png" alt="Hawaii Marketplace" width="115" height="19" border="0"></a></li>
                            <li class="nav-item mx-2"><a class="align-middle" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Tile\', \'event_label\': \'Longs\'});" href="https://longs.staradvertiser.com/" target="_blank"><img class="longs-logo align-middle" src="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/images/longs-drugs-logo-button-v2.png" alt="Longs Drugs" width="108" height="19.81" border="0"></a></li>
                                                            <li class="nav-item mx-2"><a onclick="matherSubcribeClickEvent(); hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Top Subscribe\'});" class="btn btn-primary" href="https://gateway.staradvertiser.com/index.html?flow_type=subscribe" target="_blank">Subscribe</a></li>
                                <li class="nav-item mx-2"><a onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Top Login\'});" class="btn btn-secondary" href="https://www.staradvertiser.com/user-access/?redirect_to=https%3A%2F%2Fwww.staradvertiser.com%2F2024%2F07%2F27%2Feditorial%2Fkauakukalahale%2Fcolumn-ka-hopena-o-ka-aeeliaeeli-noa%2F">Log In</a></li>
                                                    </ul>
                    </div>

                    <div id="navbar-logo" class="mx-auto">
                        <a href="https://www.staradvertiser.com/" title="Honolulu Star-Advertiser"><img src="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/images/sa-logo-white-small.png" alt="Honolulu Star-Advertiser" width="164" height="35" border="0"></a>
                    </div>
                </div><!-- /container -->

                <div id="flyoutMenu" class="collapse" style="padding-top: 0; width: 100%;">
                    <div class="offcanvas-header d-flex p-2">
                        <button type="button" class="flyoutMenuClose ms-auto" data-bs-toggle="collapse" href="#flyoutMenu" role="button" aria-controls="flyoutMenu" aria-label="Close"><span class="icon-times-circle"></span></button>
                    </div><!-- /offcanvas-header -->
                    <div class="offcanvas-body" style="font-size: 0.667em;">
                        <div class="container">
                            <div class="row gx-5" style="padding-bottom: 8rem;">
                                                                <ul id="flyoutMenuSubMenu" class="col-12 d-lg-none" style="margin-bottom:0px !important;">
                                    <li class="menu-item menu-item-type-custom menu-item-object-custom current-menu-item current_page_item">
                                        <a href="https://www.staradvertiser.com/user-access/?redirect_to=https%3A%2F%2Fwww.staradvertiser.com%2F2024%2F07%2F27%2Feditorial%2Fkauakukalahale%2Fcolumn-ka-hopena-o-ka-aeeliaeeli-noa%2F" onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Nav\', \'event_label\': \'Top Login\'});" class="bg-white">Log In</a>
                                    </li>
                                </ul>
                                                                
                                <ul id="flyoutMenuSubMenu" class="col-12 col-lg-4 mb-5 mb-lg-0"><li class="menu-item menu-item-type-custom menu-item-object-custom current-menu-item current_page_item"><a href="https://www.staradvertiser.com/" target="">Home</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/breaking-news/" target="">Top News</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/election-2024/" target="">Election 2024</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://hawaiiobituaries.com/us/obituaries/hawaiiobituaries/browse" target="_blank">Obituaries</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/hawaii-news/">Hawaii News</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#hawaii-news" aria-expanded="false" aria-controls="hawaii-news"><span class="icon-chevron-right"></span></a><ul id="hawaii-news" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="hawaii-news" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/maui-wildfires/" target="">Maui Fires</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/red-hill-water-crisis/" target="">Red Hill Water Crisis</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/crime/" target="">Crime in Hawaii</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/column/" target="">Columnist</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/hawaii-weather/" target="">Weather</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/traffic/" target="">Traffic</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/editorial/">Editorial</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#editorial" aria-expanded="false" aria-controls="editorial"><span class="icon-chevron-right"></span></a><ul id="editorial" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="editorial" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/category/letters/" target="">Letters to the Editor</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/hawaii-news/kokua-line/" target="">Kokua Line</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/category/editorial/our-view/" target="">Our View</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/category/editorial/island-voices/" target="">Island Voices</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/business/" target="">Business</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/sports/">Sports</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#sports" aria-expanded="false" aria-controls="sports"><span class="icon-chevron-right"></span></a><ul id="sports" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="sports" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/sports/sports-breaking/" target="">Sports Breaking</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/sports/scoreboard/" target="">Scoreboard</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/sports/tv-radio/" target="">TV Radio</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/sports/hawaii-prep-world/" target="">Hawaii Prep World</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/crave/">Food</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#food" aria-expanded="false" aria-controls="food"><span class="icon-chevron-right"></span></a><ul id="food" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="food" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/crave/" target="">Crave</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://dining.staradvertiser.com/" target="_blank">Dining Out</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/recipes/" target="">Recipes</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="#">News</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#news" aria-expanded="false" aria-controls="news"><span class="icon-chevron-right"></span></a><ul id="news" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="news" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/politics/" target="">Politics</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-post_tag"><a href="https://www.staradvertiser.com/tag/national-news/" target="">National News</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/world-news" target="">World News</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/russia-attacks-ukraine/" target="">Russia Attacks Ukraine</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/america-in-turmoil/" target="">America in Turmoil</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://oahupublications-hi.newsmemory.com/?special=Star+Channels" target="">Star Channels</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page"><a href="https://www.staradvertiser.com/photo-galleries/" target="">Photo Galleries</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/calendar/" target="">Calendar</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/video/">Video</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#video" aria-expanded="false" aria-controls="video"><span class="icon-chevron-right"></span></a><ul id="video" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="video" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.youtube.com/playlist?list=PL4hYTOAQ-Qk4vjQm5q6qrwlhMgnCF6_7s" target="">Star News Live</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children"><a href="https://www.staradvertiser.com/puzzles/">Fun and Games</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#fun-and-games" aria-expanded="false" aria-controls="fun-and-games"><span class="icon-chevron-right"></span></a><ul id="fun-and-games" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="fun-and-games" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-post_type menu-item-object-page"><a href="https://www.staradvertiser.com/comics/" target="">Comics</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/comics/?ckpage=political" target="">Political Cartoons</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/comics/?ckpage=horoscopes" target="">Horoscopes</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page"><a href="https://www.staradvertiser.com/puzzles/" target="">Puzzles</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/puzzles/?puzzleType=sud_classic_king" target="">Sudoku</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/puzzles/?puzzleType=sheffer" target="">Crosswords</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="?puzzleType=wg_guesstionary" target="">Word Games</a></li>
</ul>
</li>
<li class="menu-item menu-item-type-custom menu-item-object-custom current-menu-item current_page_item menu-item-has-children"><a href="https://www.staradvertiser.com/">More</a><a href="#" class="toggle-custom collapsed" data-bs-toggle="collapse" data-bs-target="#more" aria-expanded="false" aria-controls="more"><span class="icon-chevron-right"></span></a><ul id="more" class="sub-menu ps-0 accordion-collapse collapse" aria-labelledby="more" data-bs-parent="#flyoutMenuSubMenu"><li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/travel/" target="">Travel</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/tag/entertainment/" target="">Entertainment</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/live-well/" target="">Live Well</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/staradvertiser-poll/" target="">The Big Q</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/corrections/" target="">Corrections</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/back-issues/" target="">Archives</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://statelegals.staradvertiser.com/" target="_blank">State Legals</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://staradvertiser-hi.newsmemory.com/ssindex.php" target="_blank">Special Sections</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category"><a href="https://www.staradvertiser.com/category/special-sections/" target="">Special Section Archives</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://hawaiirenovation.staradvertiser.com" target="">Hawaii Renovation</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://hawaiiislandhomes.com" target="_blank">Homes</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://hawaiicars.com" target="_blank">Cars</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://hawaiijobs.staradvertiser.com/" target="_blank">Jobs</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.hawaiisclassifieds.com" target="">Classifieds</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://store.staradvertiser.com/?utm_source=opi-sa&amp;utm_medium=site&amp;utm_campaign=store" target="">Store</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://market.hawaii.com/" target="_blank">Hawaii Marketplace</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/partner-content/" target="">Partner Content</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom"><a href="https://www.staradvertiser.com/partner-videos/" target="">Partner Videos</a></li>
</ul>
</li>
</ul><ul id="menu-flyout-vertical-menu-left-column" class="menu-box col-12 col-md-6 col-lg-4 mb-5 mb-lg-0">
                                                            <li><strong class="text-uppercase text-dark">Our Company</strong></li>
                                                            <li class="menu-item menu-item-type-post_type menu-item-object-page menu-item"><a href="https://www.staradvertiser.com/about/" target="">About Us</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item"><a href="https://www.staradvertiser.com/contact/" target="">Contact Us</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://gateway.staradvertiser.com/faq.php" target="">FAQs</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://www.oahupublications.com/" target="">Advertise</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-privacy-policy menu-item"><a href="https://www.staradvertiser.com/about/privacy-policy/" target="">Privacy Policy</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item"><a href="https://www.staradvertiser.com/about/terms-of-service/" target="">Terms of Service</a></li>

                                                        </ul><div class="col-12 col-md-6 col-lg-4 mb-5 pb-5 mb-md-0 pb-md-0"><ul id="menu-flyout-vertical-menu-right-column" class="p-0 menu-box">
                                                            <li><strong class="text-uppercase text-dark">Subscribers</strong></li>
                                                            <li class="menu-item menu-item-type-post_type menu-item-object-page menu-item"><a href="https://www.staradvertiser.com/search/" target="">Search</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://gateway.staradvertiser.com/myaccount/login.php" target="">Manage My Account</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item"><a href="https://www.staradvertiser.com/user-access/print-replica/" target="">Print Replica</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://www.staradvertiser.com/download/" target="">Mobile App</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://www.staradvertiser.com/news-alerts-signup/" target="">Email Newsletters</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item"><a href="https://www.staradvertiser.com/web-notifications/" target="">Web Push Notifications</a></li>

                                                        </ul></div>                            </div><!-- /row -->
                        </div><!-- /container -->
                    </div><!-- /offcanvas-body -->
                </div><!-- /flyoutMenu -->
            </nav><!-- /navbar -->
            <header class="top-nav">
                <nav class="top-sub-nav navbar navbar-expand-lg navbar-dark d-none d-lg-flex">
                    <div class="container">
                    <ul id="menu-top-navigation" class="navbar-nav d-block mx-auto"><li class="menu-item menu-item-type-custom menu-item-object-custom current-menu-item current_page_item nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/" target="">Home</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/category/breaking-news/" target="">Top News</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/hawaii-news/" target="">Hawaii News</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/sports/" target="">Sports</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/editorial/" target="">Editorial</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://hawaiiobituaries.com/us/obituaries/hawaiiobituaries/browse" target="_blank">Obituaries</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/crave/" target="">Crave</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/video/" target="">Videos</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://printreplica.staradvertiser.com/" target="">Print Replica</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://statelegals.staradvertiser.com/" target="_blank">Legal Ads</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page nav-item mx-2 font-weight-bold"><a class="nav-link" href="https://www.staradvertiser.com/hawaii-weather/" target="">Weather</a></li>
</ul>                    </div><!-- /container -->
                </nav><!-- /top-sub-nav -->

                <!--BREAKING ALERT BAR-->
            </header>

            <div class="masthead container my-3 mb-md-0">
                <div class="row">
                                            <div class="d-none d-lg-block col-4">
                                        <!-- /Hawaii/HSA/Ear left -->
            <div id="div-gpt-ad-ear-left" class="promo-sm-container" style="width:320px">
                
            </div>
                                </div><!-- /col -->
                                        <div class="d-none d-md-block col-6 col-lg-4 mx-auto text-lg-center pt-1">
                        <h1>
                            <a href="https://www.staradvertiser.com"><img class="w-100" src="https://sa-media.s3.us-east-1.amazonaws.com/images/sa-logo.svg" alt="Honolulu Star-Advertiser" title="Honolulu Star-Advertiser" border="0" width="416" height="88.75" fetchpriority="high"></a>
                        </h1>
                        <p class="mt-2">
                            <small class="d-flex justify-content-around">
                                <span>
                                    Saturday, July 27, 2024                                </span>
                                <span>
                                                                    <a class="no-underline" href="https://www.staradvertiser.com/hawaii-weather">
                                        <i class="wi wi-day-sunny-overcast h6"></i>
                                        83&deg;
                                    </a>
                                                                    </span>
                                <span>
                                    <a href="https://printreplica.staradvertiser.com/">Today\'s Paper</a>
                                </span>
                            </small>
                        </p>
                    </div><!-- /col -->
                                            <div class="d-none d-lg-block col-12 col-md-6 col-lg-4 text-md-end">
                                        <!-- /Hawaii/HSA/Ear right -->
            <div id="div-gpt-ad-ear-right" class="promo-sm-container mx-auto mx-md-0 ms-md-auto" style="width:320px;">
                
            </div>
                                </div><!-- /col -->
                                    </div><!-- /row -->
                <hr>
            </div><!-- /masthead -->            <!-- Full-width 2-col -->
            <div class="container my-5">
                <div class="row">
                    <!-- Content Starts Here -->
                    <div class="post-entry-wrapper col-12 col-lg-8 col-xl-8 mb-5 mb-lg-0">
                        <a class="tag btn" href="https://www.staradvertiser.com/category/editorial">Editorial</a><a class="tag btn" href="https://www.staradvertiser.com/category/kauakukalahale">Kauakukalahale</a>
                        <h1 class="story-title">Column: Ka hopena o ka \'eli\'eli noa</h1>

                        <div class="clearfix mb-3 mb-md-5">
                                                        <div class="meta-info pt-2 float-start">
                                                                <p class="author mb-2 custom_byline">By Laiana Wong</p>
                                                                <p class="post-meta">
                                    <em>
                                        <span class="pub-date">
                                            Today                                        </span>
                                                                                &bull;
                                        <span class="edit-date">
                                            Updated
                                            8:10 p.m.                                        </span>
                                                                            </em>
                                </p>
                                <p class="post-meta d-none d-lg-block"><a class="tag" href="https://www.staradvertiser.com/tag/column">Featured Columns</a></p>                            </div><!-- /meta-info -->
                        </div><!-- /clearfix -->

                        <div class="post-entry row">
                            <ul class="share col-12 col-md-1 sticky-top sticky-md-top">
                                <li class="dropdown">
                                    <a class="dropdown-toggle" title="Share this story" href="#" role="button" id="shareSocialMediaLink" data-bs-toggle="dropdown" aria-expanded="false"><span class="icon-share-alt"></span></a>
                                    <ul class="dropdown-menu" aria-labelledby="shareSocialMediaLink">
                                        <li><a class="dropdown-item" href="https://www.facebook.com/sharer/sharer.php?u=https://www.staradvertiser.com/2024/07/27/editorial/kauakukalahale/column-ka-hopena-o-ka-aeeliaeeli-noa/" target="_blank" title="Share on Facebook"><span class="icon-facebook-square"></span> Share on Facebook</a></li>
                                        <li><a class="dropdown-item" href="https://twitter.com/share?url=https://www.staradvertiser.com/2024/07/27/editorial/kauakukalahale/column-ka-hopena-o-ka-aeeliaeeli-noa/&amp;text=Column:%20Ka%20hopena%20o%20ka%20%E2%80%98eli%E2%80%98eli%20noa%20via%20@staradvertiser" target="_blank" title="Share on X"><span class="icon-x"></span> Share on X</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="javascript:window.location=\'mailto:?subject=Here%20is%20a%20Honolulu%20Star-Advertiser%20article%20you%20might%20like&amp;body=I saw this article in the Honolulu Star-Advertiser and thought you might find it interesting: \' + window.location; return false" target="_blank" title="Share by email"><span class="icon-envelope-o"></span> Share by email</a></li>
                                    </ul>
                                </li>
                                <li><a title="Comment on this story" href="#section-before-comments"><span class="icon-commenting"></span></a></li>
                                <li><a title="Print this story" href="#" onclick="window.print();"><span class="icon-print"></span></a></li>
                                <!--<li><a title="Bookmark this story" href="#"><span class="icon-bookmark"></span></a></li>-->
                            </ul><!-- /share -->

                            <div id="article-content" class="post col-12 col-md-11 clearfix">
                                                                    <div class="row">
                                        <div class="col-12 d-flex flex-column">
                                            <div class="col-12">
                                                <div id="mainImageContainer" class="my-auto" style="touch-action: none;">
                                                    <!-- Main Image -->
                                                                                                    </div>
                                            </div>
                                                                                    </div>
                                    </div>
                                    
                                <div id="paywall-screen" class="modal fade show blur" role="dialog" aria-hidden="false" aria-modal="true" style="display: block;">
                                    <div class="modal-dialog bg-white p-3 p-xl-4">
                                        <div class="container text-center px-2 px-lg-4">
                                            <div class="row gx-5">
                                                <div class="col-12">
                                                    <a href="https://www.staradvertiser.com" title="The Honolulu Star-Advertiser">
                                                        <img class="logo" src="https://sa-media.s3.us-east-1.amazonaws.com/images/sa-logo.svg" alt="Honolulu Star-Advertiser" title="Honolulu Star-Advertiser" border="0">
                                                    </a>

                                                    <h4>Select an option below to continue reading this premium story.</h4>
                                                    <p class="mb-4">Already a <em>Honolulu Star-Advertiser</em> subscriber? <a onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Paywall Story\', \'event_label\': \'Log In Button\'});" href="https://www.staradvertiser.com/user-access/">Log in now</a> to continue reading.</p>
                                                </div><!-- /col -->

                                                <div class="col-12 col-lg-6 mx-auto">
                                                    <div class="bg-primary p-3 p-lg-4 rounded">
                                                        <h5 class="mt-0">Get unlimited access</h5>
                                                        <p class="d-none d-lg-block"><em class="text-success">From as low as $12.95 /mo.</em></p>
                                                        <a onclick="hsaGTMEvent(\'event\', \'click\', {\'event_category\': \'Paywall Story\', \'event_label\': \'Subscribe Now Button\'}); matherSubcribeClickEvent();" class="d-block d-lg-inline-block btn btn-primary px-4" href="https://gateway.staradvertiser.com/index.html?flow_type=subscribe">Subscribe Now</a>
                                                    </div>
                                                </div><!-- /col -->
                                            </div><!-- /row -->
                                        </div><!-- /container -->
                                    </div><!-- /modal-dialog -->
                                </div><!-- /modal -->

                                <div id="hsa-paywall-content" style="display:block" class="hsa-paywall">
                                    <p>Synopsis: Deep-sea mining can deplete the amount of oxygen necessary to sustain life on the ocean floor. It makes sense to place a moratorium on such activity until the consequences are better understood.</p>
<p>Aloha mai k&#257;kou e n&#257; hoa heluhelu. Eia n&#333; ua kupu hou mai nei kahi kumuhana i h&#257;pai &lsquo;ia ma loko o Kauak&#363;kalahale i ka l&#257; 6 o k&#275;l&#257; mahina aku nei, &lsquo;o ia ho&lsquo;i, ka &lsquo;eli&lsquo;eli &lsquo;ia o ka papak&#363; o ka moana i mea e &lsquo;ohi mai ai i kauwahi &lsquo;ano metala waiwai, e la&lsquo;a ke kopalaka, ka likiuma, a me ke keleawe (copper). Ua hele kekahi Hawai&lsquo;i, &lsquo;o Solomon Kahoohalahala o L&#257;na&lsquo;i, i ka h&#257;l&#257;wai nui a ka ISA (International Seabed Authority) i mea e h&#333;&lsquo;ike ai i ka mana&lsquo;o Hawai&lsquo;i e k&#363;&lsquo;&#275;&lsquo;&#275; ana i ia hana. A &lsquo;oiai ua kokoke loa ia hana i Hawai&lsquo;i nei, he 500 wale n&#333; mile ka mamao mai ko k&#257;kou kapakai aku ma ka hikina hema, a he hana ho&lsquo;i ia e emi mai ai ka &lsquo;okikene a n&#257; mea ola o ka moana e "hanu" ai, aia ke ola pono o ia kaiaola a huki like k&#257;kou a pau i ke kaupale aku i ia hana ake k&#257;l&#257;. Aia ka pono &lsquo;o ka lilo &lsquo;ana o ka papak&#363; o ka moana i wahi kapu, &lsquo;a&lsquo;ole e &lsquo;eli&lsquo;eli noa &lsquo;ia. &lsquo;E&#257;, &lsquo;o ke kuleana k&#275;ia o n&#257; k&#257;naka a pau o ka honua nei.</p>
<p>Ua pili k&#275;ia &lsquo;atikala i ia mea he &lsquo;okikene pouli (dark oxygen). He &lsquo;okikene n&#333; ia i ho&lsquo;okumu &lsquo;ia ma kahi hohonu loa o ka moana e p&#257; &lsquo;ole mai ai n&#257; kukuna o ka l&#257;, &lsquo;a&lsquo;ole n&#333; e like me ke &lsquo;ano o ke k&#257;&lsquo;ama&lsquo;ai ma luna o ka &lsquo;&#257;ina. Ke hui kekahi mau pu&lsquo;upu&lsquo;u metala li&lsquo;ili&lsquo;i o ka papak&#363; o ka moana, e la&lsquo;a me k&#275;l&#257; mau mea i h&#333;&lsquo;ike &lsquo;ia a&lsquo;ela, me ka wai kai, he kohu iho uila ka hoa like nona mai ka hu&lsquo;ahu&lsquo;a &lsquo;okikene. &lsquo;O ka mea &lsquo;&#257;piki, he mea ko&lsquo;iko&lsquo;i ua mau metala nei ma ka hana &lsquo;ana i kahi w&#257;wahie li&lsquo;ili&lsquo;i wale n&#333; o ke karabona a hapa mai ho&lsquo;i o kona ho&lsquo;ohaumia &lsquo;ana i ke ea. A no laila, e lilo ko l&#257;kou &lsquo;ohi&lsquo;ohi &lsquo;ia mai i mea e pi&lsquo;i ai ka waiwai ai n&#257; hui a me n&#257; moku&lsquo;&#257;ina puni k&#257;l&#257;. Eia na&lsquo;e, i loko n&#333; o ka loa&lsquo;a &lsquo;ana o kekahi mau hopena maika&lsquo;i o ia hana, aia n&#333; kekahi mau hopena &lsquo;ino e no&lsquo;ono&lsquo;o ai. Eia ho&lsquo;i kekahi, ke hapa mai ka &lsquo;okikene, e make ana paha kauwahi mea ola o ka papak&#363; o ka moana. Wahi a n&#257; po&lsquo;e &lsquo;epekema n&#257;na i lawelawe i kekahi papahana noi&lsquo;i no k&#275;ia n&#299;nau, in&#257; e &lsquo;ae &lsquo;ia ka &lsquo;eli&lsquo;eli &lsquo;ia &lsquo;ana o ia mau metala, e pilikia ana paha ke kaiaola ma ka papak&#363; o ka moana i ka hapa mai o ka &lsquo;okikene e pono ai n&#257; mea ola o laila.</p>
<p>Ma ia papahana noi&lsquo;i, ua waiho &lsquo;ia kekahi mau pahu ma lalo o ka papak&#363; o ka moana, ma kahi ho&lsquo;i o ka 2.6 mile ka hohonu, no ka m&#257;lama &lsquo;ana i n&#257; pu&lsquo;upu&lsquo;u metala o laila. Ma ke ana &lsquo;ana i ka &lsquo;okikene, ua &lsquo;ike &lsquo;ia ka m&#257;huahua &lsquo;ana o ia mau pu&lsquo;upu&lsquo;u me ka hala &lsquo;ana o ka w&#257;. &lsquo;O ia ho&lsquo;i, ua &lsquo;oi aku ka nui o ka &lsquo;okikene e haku &lsquo;ia ana ma mua o ka nui o ka &lsquo;okikene o ka ho&lsquo;ohana &lsquo;ia &lsquo;ana. I mea ho&lsquo;i ia &lsquo;okikene e ola ai n&#257; mea ola o ka papak&#363; o ka moana. A in&#257; e &lsquo;eli&lsquo;eli &lsquo;ia ua mau metala nei, mali&lsquo;a o pilikia auane&lsquo;i ke kaiaola o ka papak&#363; o ka moana. I pilikia ka moana, pilikia p&#363; n&#333; ho&lsquo;i me k&#257;kou a pau. A no laila, e m&#257;lama k&#257;kou i ka pono o ka &lsquo;&#257;ina ma luna a ma lalo ho&lsquo;i o ka &lsquo;ili o ke kai.</p>
<p>I k&#275;ia w&#257; a n&#257; aupuni o ke ao e nalu nei i k&#275;ia n&#299;nau, aia he 800 a &lsquo;oi po&lsquo;e &lsquo;epekema i p&#363;lima i kekahi palapala ho&lsquo;opi&lsquo;i i k&#275;ia hana &lsquo;o ka &lsquo;eli&lsquo;eli metala waiwai mai ka papak&#363; mai o ka moana. E ho&lsquo;ok&#363; &lsquo;ia n&#333; ho&lsquo;i i mea e noi&lsquo;i hou ai i ka maika&lsquo;i a me ka &lsquo;ole o ia hana. Ua lawa n&#333; paha ka nui o ka &lsquo;ino i ili mai ma luna o ka moana ma muli o n&#257; hana ake k&#257;l&#257; a k&#257;kou k&#257;naka, e la&lsquo;a ka loli &lsquo;ana o ke aniau, ka lawe i&lsquo;a &lsquo;ana ma o ka huki &lsquo;ia o ka &lsquo;upena ma luna o ka papak&#363; o ka moana, a me n&#257; &lsquo;ano ho&lsquo;ohaumia like &lsquo;ole &lsquo;&#275; a&lsquo;e. E m&#257;lama k&#257;kou i ka moana!</p>
<hr>
<p><b>E ho\'ouna \'ia mai na &#257; leka i&#257; m&#257;ua, \'o ia ho\'i \'o Laiana Wong a me Kekeha Solis ma ka pahu leka uila ma lalo nei:</b></p>
<p><b>&gt;&gt; kwong@hawaii.edu</b></p>
<p><b>&gt;&gt; rsolis@hawaii.edu</b></p>
<p><b>a i \'ole ia, ma ke kelepona:</b></p>
<p><b>&gt;&gt; 808-956-2627 (Laiana)</b></p>
<p><b>&gt;&gt; 808-956-2627 (Kekeha)</b></p>
<p><b>This column is coordinated by Kawaihuelani Center for Hawaiian Language at the University of Hawai\'i at M&#257;noa.</b></p>
<hr>
                                                                    </div>

                                <div class="modal-backdrop fade show"></div>
                            </div><!-- /post -->
                        </div><!-- /post-entry -->
                    </div><!-- /post-entry-wrapper -->
                </div><!-- /row -->
            </div><!-- /container -->

            
            </div><!-- /full-page-wrapper -->                        <!-- /Hawaii/HSA/Leaderboard 5 -->
            <div id="div-gpt-ad-leaderboard-5" class="promo-container bg-light p-3 text-center">
                
            </div>
        

            <footer class="bg-dark">
                <div class="container py-5">
                    <div class="row">
                        <div class="col-12 col-lg-4 text-center text-lg-start mb-md-5 mb-lg-0">
                            <img class="mb-4" src="https://staradvertiser.wpenginepowered.com/wp-content/themes/hsa-redesign/images/sa-logo-white-small.png" alt="Honolulu Star-Advertiser" width="164" height="35" loading="lazy">
                            <p class="mb-4">500 Ala Moana Blvd. #7-500<br>Honolulu, HI 96813<br>(808) 529-4747</p>
                            <ul class="social">
                                <li><a href="https://www.facebook.com/staradvertiser" title="Facebook" target="_blank"><span class="icon-facebook-square"></span></a></li>
                                <li><a href="https://twitter.com/staradvertiser" title="Twitter" target="_blank"><span class="icon-x"></span></a></li>
                                <li><a href="https://www.instagram.com/staradvertiser/" title="Instagram" target="_blank"><span class="icon-instagram"></span></a></li>
                                <li><a href="https://www.youtube.com/StarAdvertiser" title="YouTube" target="_blank"><span class="icon-youtube-play"></span></a></li>
                                <li><a href="https://www.linkedin.com/company/honolulu-star-advertiser" title="LinkedIn" target="_blank"><span class="icon-linkedin"></span></a></li>
                            </ul>
                        </div><!-- /col -->

                        <div class="col-2 d-none d-lg-block">
                             
                        </div><!-- /col -->

                        <div class="col-4 col-lg-2 d-none d-md-block">
                            <p class="fw-bold">Our Company</p>
                            <ul id="menu-footer-menu-vertical-left-col">
                                                                    <li id="menu-item-1320027" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-1320027"><a href="https://www.staradvertiser.com/about/">About Us</a></li>
<li id="menu-item-1320028" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-1320028"><a href="https://www.staradvertiser.com/contact/">Contact Us</a></li>
<li id="menu-item-1320029" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320029"><a href="https://www.oahupublications.com/">Advertise</a></li>
<li id="menu-item-1320030" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-privacy-policy menu-item-1320030"><a rel="privacy-policy" href="https://www.staradvertiser.com/about/privacy-policy/">Privacy Policy</a></li>
<li id="menu-item-1320031" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-1320031"><a href="https://www.staradvertiser.com/about/terms-of-service/">Terms of Service</a></li>

                                                                </ul>                        </div><!-- /col -->

                        <div class="col-4 col-lg-2 d-none d-md-block">
                            <p class="fw-bold">Subscribers</p>
                            <ul id="menu-footer-menu-vertical-middle-col">
                                                                    <li id="menu-item-1320033" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320033"><a href="https://gateway.staradvertiser.com/myaccount/login.php?ref=">My Account</a></li>
<li id="menu-item-1320034" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320034"><a href="https://gateway.staradvertiser.com/account_lookup.php">Activate Digital Account</a></li>
<li id="menu-item-1320035" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-1320035"><a href="https://www.staradvertiser.com/user-access/print-replica/">Print Replica</a></li>
<li id="menu-item-1320036" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320036"><a href="https://gateway.staradvertiser.com/customer-service/">Customer Service</a></li>
<li id="menu-item-1320037" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320037"><a href="https://gateway.staradvertiser.com/faq.php">FAQs</a></li>

                                                                </ul>                        </div><!-- /col -->

                        <div class="col-4 col-lg-2 d-none d-md-block">
                            <p class="fw-bold">More</p>
                            <ul id="menu-footer-menu-vertical-right-col">
                                                                    <li id="menu-item-1320038" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320038"><a target="_blank" rel="noopener" href="https://www.staradvertiser.com/download/">Mobile App</a></li>
<li id="menu-item-1320039" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320039"><a href="https://www.staradvertiser.com/news-alerts-signup/">Email Newsletters</a></li>
<li id="menu-item-1320040" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320040"><a href="https://www.staradvertiser.com/web-notifications/">Web Push Notifications</a></li>
<li id="menu-item-1320041" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320041"><a href="https://www.staradvertiser.com/search/">Search</a></li>
<li id="menu-item-1320042" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1320042"><a href="https://www.staradvertiser.com/back-issues/">Archives</a></li>

                                                                </ul>                        </div><!-- /col -->
                    </div><!-- /row -->
                </div><!-- /container -->

                <div class="disclaimer bg-black text-center p-3">
                    Copyright &copy; <a href="https://www.staradvertiser.com">StarAdvertiser.com</a>. All rights reserved. <a href="https://www.oahupublications.com/privacy_policy/" target="_blank">Privacy Policy</a> | <a href="https://www.oahupublications.com/terms-of-service/" target="_blank">Terms of Service</a> |  <div id="ccpa-optout" style="display: inline;"></div>
                </div><!-- /disclaimer -->
            </footer>

            <!-- A/B TESTING -->
            <!-- <script src="https://cdn.jsdelivr.net/npm/statsig-js/build/statsig-prod-web-sdk.min.js" async></script>
            <script>
                document.addEventListener(\'DOMContentLoaded\', async function() {
                    await statsig.initialize(
                        "client-dH9JOcKmHQVveprHYF712WhXX5j5tUvWBxxnpgmNR0L",
                        { userID: "1722121704" }
                    );

                    // Now that statsig is initialized, you can use it
                    const experiment = statsig.getExperiment("read_time");
                    var readTime = false;
                    // Execute based on the experiment
                    if (experiment && experiment.get("read_time_enabled", false) ) {
                        readTime = true;
                        jQuery(\'.read_time_ab_test\').show();
                    }

                    // Log article clicks and if read time is enabled log the estimated read time
                    jQuery(\'article\').on(\'click\', function() {
                        statsig.logEvent(
                            \'article_click\', 
                            experiment.get("read_time_enabled", false),
                            { 
                                read_time: readTime ? jQuery(this).find(\'.read_time_ab_test\').text() : null
                            }
                        );
                    });
                });
            </script> -->
        
            			
					
		















            

            
                    <!-- /full-page-wrapper -->
    </body>
</html>
EOF;
return $text;
}

$text = sampleText();
$sourceID = 23577;
$present = $laana->hasRaw( $sourceID );
$answer = ($present !== false) ? "yes" : "no";
echo "present: $answer\n";

$count = $laana->addRawText( $sourceID, $text );
echo "$count characters added\n";
return;

$present = $laana->hasRaw( $sourceID );
$answer = ($present !== false) ? "yes" : "no";
//show( $present );
//echo( "sizeof(row): " . sizeof( $present ) . "\n");
echo "present: $answer\n";
//echo "present: $present\n";
return;


$sources = $laana->getSources( 'nupepa' );
//show( $sources );
foreach( $sources as $source ) {
    $sourceid = $source['sourceid'];
    $title = $source['sourcename'];
    if( strpos( $title, "Hawaii Holomua " ) !== false ) {
        echo "$title\n";
        $title = preg_replace( '/Hawaii Holomua /', '', $title );
        $date = preg_replace( '/ Edition 02/', '', $title );
        $date = date("Y-m-d", strtotime($date));
        echo "$date\n";
        $sql = "update sources set date = '$date' where sourceid = $sourceid";
        $db = new DB();
        $db->executeSQL( $sql );
    }
}
return;

$date = '12 June 1896';
$date = date("Y-m-d", strtotime($date));
echo "$date\n";
return;

$link = 'https://www.staradvertiser.com/2024/07/20/editorial/kauakukalahale/column-no-ke-ala-kakahiaka-nui-a-me-ke-ala-aumoe/';
$parser = new KauakukalahaleHTML();
$parser->initialize( $link );
show( $parser );
return;

$parser = new NupepaNewHTML();

$url = "https://nupepa.org/?a=d&d=OHK18691001-01";
$parser->initialize( $url );
echo "date=" . $parser->extractDate() . "\n";
return;

$pages = $parser->getPageList();
echo( var_export( $pages, true ) . "\n" );
$key = array_keys( $pages[0] )[0];
//echo "key = " . var_export( $key, true ) . "\n";
//echo "key = $key\n";
//$key = $key[0];
echo "key = $key\n";
//echo var_export( $pages[$key], true ) . "\n";
$url = $pages[0][$key]['url'];
echo "url = $url\n";
$parser->initialize( $url );
$sourceName = $parser->getSourceName( '', $url );
echo "sourceName = $sourceName\n";
//return;

$sentences = $parser->extractSentences( $url );
show( $sentences );
return;

$text = $parser->getContents( $url, [] );
echo "$text\n";
return;

//setDebug( true );
$url = 'https://noiiolelo.org/ops/getPageHtml.php?word=mele&pattern=exact&page=0&order=alpha';
$url = 'https://noiiolelo.org/ops/g.php?word=mele&pattern=exact&page=0&order=alpha';
$text = file_get_contents( $url );
echo strlen( $text ) . "\n";
return;

$sentences = $laana->getSentences( "mele", "exact", 0 );
echo sizeof( $sentences ) . "\n";
return;

function getUlukauDate( $link ) {
    $parser = new UlukauHTML();
    $dom = $parser->getDOM( $link );
    $date = $parser->extractDate( $dom );
    $xpath = new DOMXpath($dom);
    $query = '//div[@class="content"]';
    $items = $xpath->query( $query );
    foreach( $items as $item ) {
        $text = $item->ownerDocument->saveHTML($item);
        $text = $item->nodeValue;
        if( preg_match( '/Copyright/', $text ) ) {
            //echo "Raw: $text\n";
            $text = preg_replace( '/Copyright ©/', '', $text );
            $text = preg_match( '/([0-9]{4})/', $text, $matches );
            if( sizeof( $matches ) > 0 ) {
                return $matches[0];
            }
        }
    }
    return '';
}

$parser = new UlukauHTML();
$sources = $laana->getSources( 'ulukau' );
foreach( $sources as $source ) {
    $link = $source['link'];
    $sourceid = $source['sourceid'];
    $dom = $parser->getDOM( $link );
    $date = $parser->extractDate( $dom );
    echo "Date: $date\n";
    /*
    $date = $source['date'];
    if( !$date ) {
        echo "$sourceid {$source['sourcename']} $link\n";
        $date = getUlukauDate( $link );
        if( $date ) {
            $date .= "-01-01";
            $source['date'] = $date;
            //echo( var_export( $source, true ) . "\n\n" );
            $laana->updateSource( $source );
            $source = $laana->getSource( $sourceid );
            echo( "After update: " . var_export( $source, true ) . "\n\n" );
        }
    }
    */
    //break;
}

return;
    
$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-HSI2&e=-------en-20--1--txt-txPT-----------";
getUlukauDate( $url );
return;





function getNupepaDate( $sourceid ) {
    $date = '';
    $laana = new Laana();
    $parser = new HtmlParse();
    $link = "https://noiiolelo.org/rawpage?id=$sourceid";
    $dom = $parser->getDOM( $link );
    $xpath = new DOMXpath($dom);
    $query = '//h3';
    $items = $xpath->query( $query );
    if( $items->length > 0 ) {
        $item = $items->item( 0 );
        $outerHTML = $item->ownerDocument->saveHTML($item);
        //echo "outerHTML: $outerHTML\n";
        //$text = $item->nodeValue;
        $text = preg_replace( '/.*<br>/', '', $outerHTML );
        $text = preg_replace( '/<\/h3>/', '', $text );
        $text = preg_replace( "/'/", "", $text );
        $parts = explode( " ", $text );
        $months = $parser->months;
        $date = "${parts[2]}-${months[$parts[1]]}-${parts[0]}";
        //echo "$date\n";
    }
    return $date;
}

function addOneDate( $sourceid ) {
    $laana = new Laana();
    $date = getNupepaDate( $sourceid );
    //echo "$date\n";
    $source = $laana->getSource( $sourceid );
    $source['date'] = $date;
    //echo( var_export( $source, true ) . "\n\n" );
    $laana->updateSource( $source );
    $source = $laana->getSource( $sourceid );
    echo( "After update: " . var_export( $source, true ) . "\n\n" );
}

function addDatesToNupepa() {
    $laana = new Laana();
    $sources = $laana->getSources( 'nupepa' );
    foreach( $sources as $source ) {
        $sourceid = $source['sourceid'];
        addOneDate( $sourceid );
    }
}

//addDatesToNupepa();
foreach( [1916,1922,1925,1932,1930,1931,1931,1950] as $sourceid ) {
    addOneDate( $sourceid );
}
return;



$parser = new KauakukalahaleHTML();
$laana = new Laana();
$sourceids = $laana->getSourceIDs( 'kauakukalahale' );
echo( var_export( $sourceids, true ) . "\n" );
foreach( $sourceids as $sourceid ) {
    $source = $laana->getSource( $sourceid );
    $url = $source['link'];
    $dom = $parser->getDOM( $url );
    $author = $parser->extractAuthor( $dom );
    $source['authors'] = $author;
    $date = $parser->extractDate( $dom );
    $source['date'] = $date;
    echo( var_export( $source, true ) . "\n\n" );
    $laana->updateSource( $source );
    $source = $laana->getSource( $sourceid );
    echo( "After update: " . var_export( $source, true ) . "\n\n" );
}
return;


$url = "https://www.staradvertiser.com/2023/10/14/editorial/kauakukalahale/column-aeaaeohe-malama-pau-i-ka-crb/";
$parser = new KauakukalahaleHTML();
$dom = $parser->getDOM( $url );
$author = $parser->extractAuthor( $dom );
echo "$author\n";
return;

$parser = new KauakukalahaleHTML();
$laana = new Laana();

$url = "https://www.staradvertiser.com/category/editorial/kauakukalahale/";

$dom = $parser->getDOM( $url );
$xpath = new DOMXpath($dom);
$query = '//article[contains(@class, "story")]/div/a';
$query = '//article[contains(@class, "story")]';
$articles = $xpath->query( $query );
        //echo( "KauakukalahaleHtml::getPageList paragraphs: " . $paragraphs->length . "\n" );
$pages = [];
foreach( $articles as $article ) {
    $outerHTML = $article->ownerDocument->saveHTML($article);
    //echo "outerHTML: $outerHTML\n";
    $paragraphs = $xpath->query( "div/a", $article );
    if( $paragraphs->length > 0 ) {
        $p = $paragraphs->item( 0 );
        $outerHTML = $p->ownerDocument->saveHTML($p);
        //echo "outerHTML: $outerHTML\n";
        $url = $p->getAttribute( 'href' );
        $title = $p->getAttribute( 'title' );
            //$title = $this->basename . ": " . str_replace( "Column: ", "", $title );
            //$date = str_replace( $this->domain, "", $url );
            //$date = substr( $date, 0, 10 );
            //$sourcename = $this->basename . ": " . $date;
        $childNodes = $p->getElementsByTagName( 'img' );
        if( $childNodes->length > 0 ) {
            $img = $childNodes->item( 0 )->getAttribute( 'data-src' );
        }
        $lis = $xpath->query( "*/li[contains(@class, 'custom_byline')]", $article );
        $authors = '';
        if( $lis->length > 0 ) {
            $li = $lis->item( 0 );
            $authors = str_replace( "By na ", "", trim( $li->nodeValue ) );
        } else {
            echo "No custom_byline found\n";
        }
        $pages[] = [
            $sourcename => [
                'url' => $url,
                'title' => $title,
                'date' => $date,
                'image' => $img,
                'authors' => $authors,
            ]
        ];
    }
    break;
}
echo( var_export( $pages, true ) . "\n" );
return;




$sourceids = $laana->getSourceIDs( 'kauakukalahale' );
echo( var_export( $sourceids, true ) . "\n" );
foreach( $sourceids as $sourceid ) {
    $source = $laana->getSource( $sourceid );
    $url = $source['link'];
    $dom = $parser->getDOM( $url );
    $date = $parser->extractDate( $dom );
    $source['date'] = $date;
    echo( var_export( $source, true ) . "\n\n" );
    $laana->updateSource( $source );
}
return;

function extractDate( $dom ) {
    $date = '';
        $xpath = new DOMXpath( $dom );
    $query = '//meta[contains(@property, "article:published_time")]';
    $query = "//li[contains(@class, 'postdate')]";
    //$query = "//li"
    $paragraphs = $xpath->query( $query );
    if( $paragraphs->length > 0 ) {
        $p = $paragraphs->item(0);
        $outerHTML = $p->ownerDocument->saveHTML($p);
        $date = $outerHTML;
        $date = trim( $p->nodeValue );
        $date = date("Y-m-d", strtotime($date));
    }
    return $date;
}

$url = "https://www.staradvertiser.com/2023/10/14/editorial/kauakukalahale/column-aeaaeohe-malama-pau-i-ka-crb/";
$parser = new KauakukalahaleHTML();
$dom = $parser->getDOM( $url );
$date = $parser->extractDate( $dom );
echo "$date\n";
return;


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
$source = $laana->addSource( $sourcename, $source );
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

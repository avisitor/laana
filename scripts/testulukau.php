<?php
require_once( ../'db/parsehtml.php' );

$u = new UlukauHTML();
$fulltext = file_get_contents( "/tmp/fulltext.html" );
$fulltext = preg_replace( '/\x9d/', '', $fulltext );
$fulltext = preg_replace( '/\x94/', ' ', $fulltext );
$fulltext = preg_replace( '/\xc5\x8c/', '&#332;', $fulltext );
$sentences = $u->extractSentencesFromString( $fulltext );
echo( var_export( $sentences, true ) . "\n" );
return;

function parseRaw( $sourceid ) {
    $laana = new Laana();
    $raw = $laana->getRawText( $sourceid );
    if( !$raw ) {
        echo "No raw for $sourceid\n";
        return;
    }
    //echo "$raw\n";
    $raw = mb_convert_encoding($raw, 'HTML-ENTITIES', "UTF-8");
    //echo "$raw\n";
    $u = new UlukauHTML();
    $dom = new DOMDocument;
    $dom->encoding = 'utf-8';
    libxml_use_internal_errors(false);
    $dom->loadHTML( $raw );
    $paragraphs = $u->extract( $dom );
    //debuglog( var_export( $paragraphs, true ) . " count=" . $paragraphs->count() );
    $sentences = $u->process( $paragraphs );
    $s = [];
    foreach( $sentences as $sentence ) {
        if( str_word_count( $sentence ) > 4 ) {
            $s[] = $sentence;
        }
    }
    $laana->addSentences( $sourceid, $s );
    /*
       foreach( $sentences as $sentence ) {
       if( str_word_count( $sentence ) > 4 ) {
       echo "$sentence\n";
       }
       }
     */
    //echo( var_export( $sentences, true ) . "\n" );
    //$joined = implode( "\n", $sentences );
    //$laana->addText( $sourceid, $joined );
}
$laana = new Laana();
$sourceIDs = $laana->getSourceIDs();
foreach( $sourceIDs as $sourceid ) {
    $sentences = $laana->getSentencesbySourceID( $sourceid );
    //$text = $laana->getText( $sourceid );
    if( sizeof( $sentences ) < 1 ) {
        //if( !$text ) {
        echo "$sourceid\n";
        parseRaw( $sourceid );
        //}
    }
}
//echo( var_export( $sourceIDs, true ) . "\n" );
return;

if( $argc > 1 ) {
    $sourceid = $argv[1];
    parseRaw( $sourceid );
}
return;

$contents = <<<EOF
var documentOID = 'EBOOK-MALY5';
var imageserverPageTileImageRequestBase = '/ulukau-books/cgi-bin/imageserver.pl?width%3c=256&colours=all&ext=jpg';
var pageTitles = { '1.1':'<h2>Front Cover<\/h2>','1.2':'<h2>Back Cover<\/h2>','1.3':'<h2>Page I<\/h2>','1.4':'<h2>Page II<\/h2>','1.5':'<h2>Page III<\/h2>','1.6':'<h2>Page IV<\/h2>','1
.7':'<h2>Page 1 Interview Methodology<\/h2>','1.8':'<h2>Page 2<\/h2>','1.9':'<h2>Page 3 Contributors to the Oral History Interviews<\/h2>','1.10':'<h2>Page 4<\/h2>','1.11':'<h2>Page 5
An Overview of Hawaiian Settlement<\/h2>','1.12':'<h2>Page 6 An Account of the Naming of Kolo and &#699;&#332;lelomoana (Human Bone Used to Make Fishhooks)<\/h2>','1.13':'<h2>Page 7 Th
e Journal of Chester S. Lyman (A Journey along the Coast of Kapalilua in 1846)<\/h2>','1.14':'<h2>Page 8<\/h2>','1.15':'<h2>Page 9 Ka&#699;ao Ho&#699;oniua Pu&#699;uwai no Ka-Miki&mdas
h; The Heart Stirring Story of Ka-Miki (recorded in 1914-1917)<\/h2>','1.16':'<h2>Page 10<\/h2>','1.17':'<h2>Page 11<\/h2>','1.18':'<h2>Page 12<\/h2>','1.19':'<h2>Page 13<\/h2>','1.20'
                  :'<h2>Page 14<\/h2>','1.21':'<h2>Page 15<\/h2>','1.22':'<h2>Page 16<\/h2>','1.23':'<h2>Page 17<\/h2>','1.24':'<h2>Page 18<\/h2>','1.25':'<h2>Page 19<\/h2>','1.26':'<h2>Page 20 Accounts
 of Niuhi Shark Hunting in &mdash;"He Moolelo Kaao no Kekuhaupio, Ke Koa Kaulana ke Au Kamehameha ka Nui"<\/h2>','1.27':'<h2>Page 21<\/h2>','1.28':'<h2>Page 22<\/h2>','1.29':'<h2>Page
23<\/h2>','1.30':'<h2>Page 24 H.W. Kinney\'s "Visitor\'s Guide"(1913)<\/h2>','1.31':'<h2>Page 25<\/h2>','1.32':'<h2>Page 26 KAPALILUA &mdash; FISHERY RIGHTS AND LAND TENURE DEFINED<\/h
2>','1.33':'<h2>Page 27<\/h2>','1.34':'<h2>Page 28<\/h2>','1.35':'<h2>Page 29<\/h2>','1.36':'<h2>Page 30<\/h2>','1.37':'<h2>Page 31 M&#257;hele &#699;&#256;ina: Development of Fee-Simp
le Property and Fishery Rights (ca.1846-1855)<\/h2>','1.38':'<h2>Page 32<\/h2>','1.39':'<h2>Page 33<\/h2>','1.40':'<h2>Page 34<\/h2>','1.41':'<h2>Page 35<\/h2>','1.42':'<h2>Page 36 Kap
alilua-Boundary Commission Testimonies (ca. 1873-1882)<\/h2>','1.43':'<h2>Page 37<\/h2>','1.44':'<h2>Page 38 Kapalilua in Hawaiian Kingdom Survey Records<\/h2>','1.45':'<h2>Page 39<\/h
2>','1.46':'<h2>Page 40<\/h2>','1.47':'<h2>Page 41 FAMILIES OF KAPALILUA IN THE PRESENT-DAY<\/h2>','1.48':'<h2>Page 42 <\/h2>','1.49':'<h2>Page 43<\/h2>','1.50':'<h2>Page 43 <\/h2>','1
.51':'<h2>Page 44 <\/h2>','1.52':'<h2>Page 45<\/h2>','1.53':'<h2>Page 46<\/h2>','1.54':'<h2>Page 47<\/h2>','1.55':'<h2>Page 48<\/h2>','1.56':'<h2>Page 49<\/h2>','1.57':'<h2>Page 50<\/h
2>','1.58':'<h2>Page 51<\/h2>','1.59':'<h2>Page 52<\/h2>','1.60':'<h2>Page 53<\/h2>','1.61':'<h2>Page 54<\/h2>','1.62':'<h2>Page 55<\/h2>','1.63':'<h2>Page 56<\/h2>','1.64':'<h2>Page 5
7<\/h2>','1.65':'<h2>Page 58<\/h2>','1.66':'<h2>Page 59<\/h2>','1.67':'<h2>Page 60<\/h2>','1.68':'<h2>Page 61<\/h2>','1.69':'<h2>Page 62<\/h2>','1.70':'<h2>Page 63<\/h2>','1.71':'<h2>P
age 64<\/h2>','1.72':'<h2>Page 65<\/h2>','1.73':'<h2>Page 66<\/h2>','1.74':'<h2>Page 67<\/h2>','1.75':'<h2>Page 68<\/h2>','1.76':'<h2>Page 69<\/h2>','1.77':'<h2>Page 70<\/h2>','1.78':'
<h2>Page 71<\/h2>','1.79':'<h2>Page 72<\/h2>','1.80':'<h2>Page 73<\/h2>','1.81':'<h2>Page 74 <\/h2>','1.82':'<h2>Page 75<\/h2>','1.83':'<h2>Page 76<\/h2>','1.84':'<h2>Page 77<\/h2>','1
.85':'<h2>Page 78<\/h2>','1.86':'<h2>Page 79<\/h2>','1.87':'<h2>Page 80<\/h2>','1.88':'<h2>Page 81<\/h2>','1.89':'<h2>Page 82<\/h2>','1.90':'<h2>Page 83<\/h2>','1.91':'<h2>Page 84<\/h2
>','1.92':'<h2>Page 85<\/h2>','1.93':'<h2>Page 86<\/h2>','1.94':'<h2>Page 87<\/h2>','1.95':'<h2>Page 88<\/h2>','1.96':'<h2>Page 89<\/h2>','1.97':'<h2>Page 90 <\/h2>','1.98':'<h2>Page 9
1<\/h2>','1.99':'<h2>Page 92<\/h2>','1.100':'<h2>Page 93<\/h2>','1.101':'<h2>Page 94<\/h2>','1.102':'<h2>Page 95<\/h2>','1.103':'<h2>Page 96<\/h2>','1.104':'<h2>Page 97<\/h2>','1.105':
                   '<h2>Page 98<\/h2>','1.106':'<h2>Page 99<\/h2>','1.107':'<h2>Page 100<\/h2>','1.108':'<h2>Page 101<\/h2>','1.109':'<h2>Page 102<\/h2>','1.110':'<h2>Page 103<\/h2>','1.111':'<h2>Page 10
4<\/h2>','1.112':'<h2>Page 105<\/h2>','1.113':'<h2>Page 106<\/h2>','1.114':'<h2>Page 107<\/h2>','1.115':'<h2>Page 108<\/h2>','1.116':'<h2>Page 109 <\/h2>','1.117':'<h2>Page 110<\/h2>',
                   '1.118':'<h2>Page 111<\/h2>','1.119':'<h2>Page 112<\/h2>','1.120':'<h2>Page 113<\/h2>','1.121':'<h2>Page 114 <\/h2>','1.122':'<h2>Page 115<\/h2>','1.123':'<h2>Page 116<\/h2>','1.124':'
<h2>Page 117<\/h2>','1.125':'<h2>Page 118<\/h2>','1.126':'<h2>Page 119<\/h2>','1.127':'<h2>Page 120<\/h2>','1.128':'<h2>Page 121<\/h2>','1.129':'<h2>Page 122<\/h2>','1.130':'<h2>Page 1
23<\/h2>','1.131':'<h2>Page 124<\/h2>','1.132':'<h2>Page 125<\/h2>','1.133':'<h2>Page 126<\/h2>','1.134':'<h2>Page 127<\/h2>','1.135':'<h2>Page 128<\/h2>','1.136':'<h2>Page 129<\/h2>',
                   '1.137':'<h2>Page 130<\/h2>','1.138':'<h2>Page 131<\/h2>','1.139':'<h2>Page 132<\/h2>','1.140':'<h2>Page 133<\/h2>','1.141':'<h2>Page 134<\/h2>','1.142':'<h2>Page 135<\/h2>','1.143':'<
h2>Page 136<\/h2>','1.144':'<h2>Page 137 <\/h2>','1.145':'<h2>Page 138<\/h2>','1.146':'<h2>Page 139<\/h2>','1.147':'<h2>Page 140<\/h2>','1.148':'<h2>Page 141<\/h2>','1.149':'<h2>Page 1
42<\/h2>','1.150':'<h2>Page 143<\/h2>','1.151':'<h2>Page 144<\/h2>','1.152':'<h2>Page 145<\/h2>','1.153':'<h2>Page 146<\/h2>','1.154':'<h2>Page 147<\/h2>','1.155':'<h2>Page 148<\/h2>',
                   '1.156':'<h2>Page 149<\/h2>','1.157':'<h2>Page 150<\/h2>','1.158':'<h2>Page 151<\/h2>','1.159':'<h2>Page 152<\/h2>','1.160':'<h2>Page 153<\/h2>','1.161':'<h2>Page 154<\/h2>','1.162':'<
h2>Page 155<\/h2>','1.163':'<h2>Page 156<\/h2>','1.164':'<h2>Page 157<\/h2>','1.165':'<h2>Page 158<\/h2>','1.166':'<h2>Page 159<\/h2>','1.167':'<h2>Page 160<\/h2>','1.168':'<h2>Page 16
1<\/h2>','1.169':'<h2>Page 162<\/h2>','1.170':'<h2>Page 163<\/h2>','1.171':'<h2>Page 164<\/h2>','1.172':'<h2>Page 165 <\/h2>','1.173':'<h2>Page 166<\/h2>','1.174':'<h2>Page 167<\/h2>',
                   '1.175':'<h2>Page 168<\/h2>','1.176':'<h2>Page 169<\/h2>','1.177':'<h2>Page 170<\/h2>','1.178':'<h2>Page 171<\/h2>','1.179':'<h2>Page 172<\/h2>','1.180':'<h2>Page 173<\/h2>','1.181':'<
h2>Page 174<\/h2>','1.182':'<h2>Page 175<\/h2>','1.183':'<h2>Page 176<\/h2>','1.184':'<h2>Page 177<\/h2>','1.185':'<h2>Page 178<\/h2>','1.186':'<h2>Page 179<\/h2>','1.187':'<h2>Page 18
0<\/h2>','1.188':'<h2>Page 181<\/h2>','1.189':'<h2>Page 182<\/h2>','1.190':'<h2>Page 183<\/h2>','1.191':'<h2>Page 184<\/h2>','1.192':'<h2>Page 185<\/h2>','1.193':'<h2>Page 186<\/h2>','
1.194':'<h2>Page 187<\/h2>','1.195':'<h2>Page 188<\/h2>','1.196':'<h2>Page 189<\/h2>','1.197':'<h2>Page 190<\/h2>','1.198':'<h2>Page 191<\/h2>','1.199':'<h2>Page 192<\/h2>','1.200':'<h
2>Page 193<\/h2>','1.201':'<h2>Page 194<\/h2>','1.202':'<h2>Page 195<\/h2>','1.203':'<h2>Page 196<\/h2>','1.204':'<h2>Page 197<\/h2>','1.205':'<h2>Page 198<\/h2>','1.206':'<h2>Page 199
<\/h2>','1.207':'<h2>Page 200<\/h2>','1.208':'<h2>Page 201<\/h2>','1.209':'<h2>Page 202<\/h2>','1.210':'<h2>Page 203<\/h2>','1.211':'<h2>Page 204<\/h2>','1.212':'<h2>Page 205<\/h2>','1
.213':'<h2>Page 206<\/h2>','1.214':'<h2>Page 207<\/h2>','1.215':'<h2>Page 208<\/h2>','1.216':'<h2>Page 209<\/h2>','1.217':'<h2>Page 210<\/h2>','1.218':'<h2>Page 211<\/h2>','1.219':'<h2
>Page 212<\/h2>','1.220':'<h2>Page 213<\/h2>','1.221':'<h2>Page 214<\/h2>','1.222':'<h2>Page 215<\/h2>','1.223':'<h2>Page 216 <\/h2>','1.224':'<h2>Page 217<\/h2>','1.225':'<h2>Page 218
<\/h2>','1.226':'<h2>Page 219<\/h2>','1.227':'<h2>Page 220<\/h2>','1.228':'<h2>Page 221<\/h2>','1.229':'<h2>Page 222<\/h2>','1.230':'<h2>Page 223<\/h2>','1.231':'<h2>Page 224<\/h2>','1
.232':'<h2>Page 225<\/h2>','1.233':'<h2>Page 226<\/h2>','1.234':'<h2>Page 227<\/h2>','1.235':'<h2>Page 228<\/h2>','1.236':'<h2>Page 229<\/h2>','1.237':'<h2>Page 230<\/h2>','1.238':'<h2
>Page 231<\/h2>','1.239':'<h2>Page 232<\/h2>','1.240':'<h2>Page 233<\/h2>','1.241':'<h2>Page 234<\/h2>','1.242':'<h2>Page 235<\/h2>','1.243':'<h2>Page 236<\/h2>','1.244':'<h2>Page 237<
\/h2>','1.245':'<h2>Page 238<\/h2>','1.246':'<h2>Page 239<\/h2>','1.247':'<h2>Page 240<\/h2>','1.248':'<h2>Page 241<\/h2>','1.249':'<h2>Page 242<\/h2>','1.250':'<h2>Page 243<\/h2>','1.
251':'<h2>Page 244<\/h2>','1.252':'<h2>Page 245<\/h2>','1.253':'<h2>Page 246<\/h2>','1.254':'<h2>Page 247 <\/h2>','1.255':'<h2>Page 248<\/h2>','1.256':'<h2>Page 249<\/h2>','1.257':'<h2
>Page 250<\/h2>','1.258':'<h2>Page 251<\/h2>','1.259':'<h2>Page 252<\/h2>','1.260':'<h2>Page 253<\/h2>','1.261':'<h2>Page 254<\/h2>','1.262':'<h2>Page 255<\/h2>','1.263':'<h2>Page 256<
\/h2>','1.264':'<h2>Page 257<\/h2>','1.265':'<h2>Page 258<\/h2>','1.266':'<h2>Page 259<\/h2>','1.267':'<h2>Page 260<\/h2>','1.268':'<h2>Page 261<\/h2>','1.269':'<h2>Page 262<\/h2>','1.
270':'<h2>Page 263<\/h2>','1.271':'<h2>Page 264<\/h2>','1.272':'<h2>Page 265<\/h2>','1.273':'<h2>Page 266<\/h2>','1.274':'<h2>Page 267<\/h2>','1.275':'<h2>Page 268<\/h2>','1.276':'<h2>
Page 269<\/h2>','1.277':'<h2>Page 270<\/h2>','1.278':'<h2>Page 271<\/h2>','1.279':'<h2>Page 272<\/h2>','1.280':'<h2>Page 273<\/h2>','1.281':'<h2>Page 274<\/h2>','1.282':'<h2>Page 275<\
/h2>','1.283':'<h2>Page 276<\/h2>','1.284':'<h2>Page 277<\/h2>','1.285':'<h2>Page 278<\/h2>','1.286':'<h2>Page 279<\/h2>','1.287':'<h2>Page 280<\/h2>','1.288':'<h2>Page 281<\/h2>','1.2
89':'<h2>Page 282 <\/h2>','1.290':'<h2>Page 283<\/h2>','1.291':'<h2>Page 284<\/h2>','1.292':'<h2>Page 285<\/h2>','1.293':'<h2>Page 286<\/h2>','1.294':'<h2>Page 287<\/h2>','1.295':'<h2>
Page 288<\/h2>','1.296':'<h2>Page 289<\/h2>','1.297':'<h2>Page 290<\/h2>','1.298':'<h2>Page 291<\/h2>','1.299':'<h2>Page 292<\/h2>','1.300':'<h2>Page 293<\/h2>','1.301':'<h2>Page 294<\
/h2>','1.302':'<h2>Page 295<\/h2>','1.303':'<h2>Page 296<\/h2>','1.304':'<h2>Page 297<\/h2>','1.305':'<h2>Page 298<\/h2>','1.306':'<h2>Page 299<\/h2>','1.307':'<h2>Page 300<\/h2>','1.3
08':'<h2>Page 301<\/h2>','1.309':'<h2>Page 302<\/h2>','1.310':'<h2>Page 303<\/h2>','1.311':'<h2>Page 304<\/h2>','1.312':'<h2>Page 305<\/h2>','1.313':'<h2>Page 306<\/h2>','1.314':'<h2>P
age 307<\/h2>','1.315':'<h2>Page 308<\/h2>','1.316':'<h2>Page 309 REFERENCES CITED<\/h2>','1.317':'<h2>Page 310<\/h2>' };

// ULUKAUBOOKS CUSTOMISATION: Adding the pageToArticleMap hash map so I can hack the next/previous document buttons

EOF;
//$contents = preg_replace( "/\n/", "", $contents );
//$contents = preg_replace( "/\s+/", " ", $contents );
//$contents = str_replace( "-", "_", $contents );
$contents = str_replace( '"', "&quot;", $contents );
//$contents = str_replace( ",", ",\n", $contents );
//echo "$contents\n\n";
preg_match( '/var pageTitles = (\{.*\})/', $contents, $m );
$pagetitles = $m[1];
$pagetitles = str_replace( "'", '"', $pagetitles );
//echo "$pagetitles\n\n";
$titles = json_decode( $pagetitles, true );
echo( var_export( $titles, true ) . "\n" );
return;

$url = "https://puke.ulukau.org/ulukau-books/?a=p&p=bookbrowser&e=-------en-20--1--txt-txPT-----------";

//$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-MELELAHUI.2.1.1&e=-------en-20--1--txt-txPT-----------";
$parser = new UlukauHTML();


$contents = $parser->getContents( $url );
//echo "$contents\n";
$dom = new DOMDocument;
$dom->encoding = 'utf-8';
libxml_use_internal_errors(false);
$dom->loadHTML( $contents );

$xpath = new DOMXpath($dom);

/*
   $divs = $xpath->query( '//div' );
   echo $divs->length . " divs\n";
   $topdiv = $dom->getElementById("documentleveltabmetadataarea");
   $metadiv = $xpath->query( '//div[contains(@class, "divtable metadatadisplay")]' );
   echo $metadiv->length . " subdivs\n";
   $div = $metadiv->item(0);

   //$subdivs = $xpath->query( 'div[5]/div[2]', $div );
   $subdivs = $xpath->query( '//div[contains(@class, "divtable metadatadisplay")]/div[5]/div[2]' );
   echo $subdivs->length . " subdivs " . $subdivs->item(0)->nodeValue . "\n";
 */
$divs = $xpath->query( '//div[contains(@class, "ulukaubooks-book-browser-row-right")]' );
foreach( $divs as $div1 ) {
    echo "{$div1->nodeName} - {$div1->nodeValue}\n";
    $langdivs = $xpath->query( 'div[contains(@class, "la")]', $div1 );
    if( $langdivs->length > 0 ) {
        foreach( $langdivs as $div ) {
            if( strstr( $div->textContent, 'Language' ) ) {
                echo "{$div->nodeName} - {$div->textContent}\n";
            }
        }
    }
}
return;

echo "{$div->nodeName} - {$div->nodeValue}\n";


if( $metadiv->length > 0 ) {
    $langdivs = $div->getElementsByTagName('div');
    //$langdivs = $xpath->query( '[contains(text(), "Language:")]', $metadiv->item(0) );

    foreach( $langdivs as $div ) {
        if( $div->nodeValue == 'Language:' ) {
            echo "{$div->nodeName} - {$div->nodeValue}\n";
        }
    }
}

return;



$pages = $parser->getPageList();
var_export( $pages );
return;



$fulltext = file_get_contents( "/tmp/raw.html" );
//$fulltext = "<html><body>\n" . file_get_contents( "/tmp/raw.html" ) . "</body></html>\n";
//echo "$fulltext\n";
//libxml_use_internal_errors(true);
$olddom = new DOMDocument;
$olddom->encoding = 'utf-8';
$olddom->loadHTML( $fulltext );
//libxml_use_internal_errors(false);

$dom = new DOMDocument();
$atStart = false;
foreach( $olddom->getElementsByTagName( "body" ) as $body ) {
    foreach ($body->childNodes as $childNode) {
        $p = null;
        if( $childNode->nodeName == "h2" || $childNode->nodeName == "h3" ) {
            //echo $childNode->nodeName . " - " . $childNode->nodeValue . "\n";
            $atStart = true;
            $prevP = null;
            $p = $dom->createElement($childNode->nodeName, $childNode->nodeValue);
        }
        if( $childNode->nodeName == "p" ) {
            if( $atStart ) {
                $atStart = false;
                $value = preg_replace( "/\n+/", "\n", $childNode->nodeValue );
                $p = $dom->createElement($childNode->nodeName, $value);
            } else {
                if( preg_match( "#([^\?\.\!\"])$#", $childNode->nodeValue ) ) {
                    $prevP = $childNode;
                    //echo "abbreviated: " . $childNode->nodeValue . "\n";
                } else {
                    $thisValue = preg_replace( "/\n+/", "\n", $childNode->nodeValue );
                    if( $prevP ) {
                        $prevValue = preg_replace( "/\n+/", "\n", $prevP->nodeValue );
                        $newValue = $prevValue . " " . $thisValue;
                        $p = $dom->createElement($childNode->nodeName, $newValue);
                        //echo "combined: $newValue\n";
                        $prevP = null;
                    } else {
                        $p = $dom->createElement($childNode->nodeName, $thisValue);
                        //echo "normal: " . $childNode->nodeValue . "\n";
                    }
                }
            }
        }
        if( $p ) {
            $dom->appendChild( $p );
            //echo "Appending " . $p->nodeName . "\n";
        }
    }
}
//$htmlString = $dom->saveHTML();
//echo $htmlString;

foreach( $dom->childNodes as $childNode ) {
    //foreach ($body->childNodes as $childNode) {
    echo $childNode->nodeName . " - " . $childNode->nodeValue . "\n";
    //}
}
foreach( $dom->getElementsByTagName( "body" ) as $body ) {
    foreach ($body->childNodes as $childNode) {
        echo $childNode->nodeName . " - " . $childNode->nodeValue . "\n";
    }
}
/*
   foreach ($dom->childNodes as $childNode) {
   echo $childNode->nodeName . "\n";
   foreach ($childNode->childNodes as $child) {
   echo $child->nodeName . "\n";
   }
   }
 */
//$paragraphs = $parser->extract( $dom );
return;

// Save the first line
$firstline = preg_split('#</p>#', $fulltext, 2)[0] . "</p>\n";
$therest = substr($fulltext, strpos($fulltext, "</p>") + 4);
$fulltext = $firstline . preg_replace( "/([^\?\.\!\"])\<\/p\>\n\<p\>/", "$1 ", $therest );
//echo "$therest\n";
echo "$fulltext\n";

return;


$url = "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-HK2&e=-------en-20--1--txt-txPT-----------";
$fulltext = $parser->getFullText( $url );
echo "$fulltext\n";
return;


$pages = $parser->getPageList();
var_export( $pages );
return;

$oid = "EBOOK-HK2";
$initialpage = ".2.3.1";
$baseurl = "https://puke.ulukau.org/ulukau-books/?a=d&d=" . $oid . $initialpage . "&e=-------en-20--1--txt-txPT-----------";
$parser->initialize( $baseurl );

?>

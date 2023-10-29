<?php
include_once 'funcs.php';

function debugPrint( $text ) {
    //echo "$text\n";
}

class HtmlParse {
    protected $Amarker = '&#256;'; //'xXx_XxX';
    protected $date = '';
    protected $url = '';
    public $title = "";
    protected $minWords = 1;
    protected $mergeRows = true;
    protected $options = [];

    public function __construct( $options = [] ) {
        $this->options = $options;
    }
    public function getSourceName( $title = '', $url = '' ) {
        return "What's my source name?";
    }
    public function initialize( $baseurl ) {
    }
    public function getRaw( $url ) {
        debugPrint( "HtmlParse::getRaw( $url )" );
        $text = file_get_contents( $url );
        file_put_contents( "/tmp/raw.html", $text );
        debuglog( "URL: $url" );
        debuglog( "Raw: " . $text );
        return $text;
    }
    public function getRawText( $url ) {
        return $this->getRaw( $url );
    }
    public function getContents( $url ) {
        debugPrint( "HtmlParse::getContents( $url )" );
        $text = $this->getRaw( $url );
        $text = $this->preprocessHTML( $text );
        return $text;
    }
    public function getDOMFromString( $text, $doXML=true ) {
        debugPrint( "HtmlParse::getDOMFromString(,$doXML)" );
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(false);
        if( $text ) {
            if( $doXML ) {
                $dom->loadXML( $text );
            } else {
                $dom->loadHTML( $text );
            }
        }
        return $dom;
    }
    public function getDOM( $url, $doXML=true ) {
        debugPrint( "HtmlParse::getDOM()" );
        $text = $this->getContents( $url );
        return $this->getDOMFromString( $text, $doXML );
    }
    public function fetch( $url, $doXML=true ) {
        debugPrint( "HtmlParse::fetch($url, $doXML)" );
        $dom = $this->getDOM( $url, $doXML );
        $this->url = $url;
        return $dom;
    }
    public function checkSentence( $sentence ) {
        if( str_word_count($sentence) < $this->minWords ) {
            return false;
        }
        $reduced = preg_replace( '/\s+/', '', $sentence );
        if( strlen( $reduced ) < 2 ) {
            return false;
        }
        if( strlen( preg_replace( '/\d+/', '', $reduced ) ) < 2 ) {
            return false;
        }
        return true;
    }
    public function prepareRaw( $text ) {
        //echo "HtmlParse::prepareRow before: $text\n";
        //$text = preg_replace( '/\xef|\xbb|\xbf|\xef/u', ' ', $text );
        $text = preg_replace( '/\s*\<br\s*\\*\>/', '\n', $text );
        // Restore the removed Ā
        $text = str_replace( $this->Amarker, 'Ā', $text );
        $text = str_replace( '&nbsp;', ' ', $text );
        //echo "HtmlParse::prepareRow after: $text\n";
        return $text;
    }
    public function preprocessHTML( $text ) {
        // DomDocument can't handle Ā, it appears
        $text = str_replace( 'Ā', $this->Amarker, $text );
        //$text = mb_convert_encoding($text, "UTF-8", "ISO-8859-15");
        $text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
        //mb_detect_encoding($text, "UTF-8, ISO-8859-1, ISO-8859-15", true));
        //echo "$text";
        file_put_contents( "/tmp/converted.html", $text );
        //$notags = trim(strip_tags( $text ));
        $text = preg_replace( '/\xbfxbd/', $this->Amarker, $text );
        // This is some kind of extended dash which also is rendered incorrectly
        $text = str_replace( '&#8211;', '-', $text );
        $text = preg_replace( '/\xc2\xa0/', ' ', $text );
        //$hexString = bin2hex($text);
        $text = preg_replace( '/ \x93/ ', ' - ', $text );
        $text = preg_replace( '/\x94/', '-', $text );
        $text = preg_replace( '/["“”‘’\\n]/', '', $text );

        return $text;
    }
    public function processText( $text ) {
        debugPrint( "HtmlParse::processText()" );
        $results = [];
        $text = html_entity_decode( $text );
        //debuglog( "UlukauParse::processText: " . $text );
        $text = str_replace( "\n", " ", $text );
        //debuglog( "HtmlParse::processText after replacing /n: " . $text );
        $lines = preg_split('/(?<=[.?!])\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
        //debuglog( $lines );
        foreach( $lines as $sentence ) {
            //debuglog( "before trim: " . $sentence );
            $sentence = trim( $sentence );
            //debuglog( "after trim: " . $sentence );
            if( $this->checkSentence( $sentence ) ) {
                array_push( $results, $sentence );
            }
        }
        return $results;
    }
    public function checkElement( $p ) {
        return true;
    }
    public function process( $contents ) {
        debugPrint( "HtmlParse::process()" );
        $rawResults = "";
        foreach( $contents as $p ) {
            if( $this->checkElement( $p ) ) {
                $text = trim( strip_tags( $p->nodeValue ) );
                //debuglog( "HtmlParse::process before prepareRaw: " . $text );
                $text = $this->prepareRaw( $text );
                //debuglog( "HtmlParse::process after prepareRaw: " . $text );
                $rawResults .= "\n" . $text;
            }
        }
        //echo "CBHtml::process after prepareRaw: " . $rawResults . "\n";
        $results = $this->processText( $rawResults );
        return $results;
    }
    function unpackNodeList( $nodeList ) {
        $text = "";
        foreach ($nodeList as $node) {
            $text .= $node->nodeValue . "\n";
        }
        return $text;
    }
    public function extractSentencesFromString( $fulltext ) {
        debugPrint( "HtmlParse::extractSentencesFromString(" . strlen($fulltext) . " chars)" );
        libxml_use_internal_errors(true);
        $dom = $this->getDOMFromString( $fulltext );
        $dom->encoding = 'utf-8';
        //$dom->loadHTML( $fulltext );
        $dom->loadXML( $fulltext );
        $this->extractDate( $dom );
        //$dom = $this->adjustDOM( $dom );
        libxml_use_internal_errors(false);
        $paragraphs = $this->extract( $dom );
        debuglog( var_export( $paragraphs, true ) . " count=" . $paragraphs->count() );
        $sentences = $this->process( $paragraphs );
        return $sentences;
    }
    public function extractSentencesFromHTML( $fulltext ) {
        return $this->extractSentencesFromString( $fulltext );
    }
    public function extractSentences( $url ) {
        debugPrint( "HtmlParse::extractSentences($url)" );
        $this->url = $url;
        $contents = $this->getContents( $url );
        $sentences = $this->extractSentencesFromString( $contents );
        return $sentences;
    }
    public function extractDate( $dom ) {
        debugPrint( "HtmlParse::extractDate()" );
    }
    public function getPageList() {
        return [];
    }
}

class CBHtml extends HtmlParse {
    private $sourceName = 'Ka Ulana Pilina';
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "CBHtml::getSourceName($title,$url)" );
        $name = $this->sourceName;
        debuglog( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        debugPrint( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        if( !$this->date ) {
            if( $url ) {
                $this->url = $url;
                $dom = $this->getDOM( $this->url, false );
                $this->extractDate( $dom );
            }
        }
        if( $this->date ) {
            $name .= " " . $this->date;
        }
        return $name;
    }
    public function fetch( $url, $doXML = false ) {
        debugPrint( "CBHtml::fetch($url)" );
        return parent::fetch( $url, false );
    }
    public function checkSentence( $sentence ) {
        if( !parent::checkSentence( $sentence ) ) {
            return false;
        }

        // There are some irrelevant phrases among the sentences
        $toSkip = [
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
        foreach( $toSkip as $pattern ) {
            $result = strpos( $sentence, $pattern );
            if( $result === false ) {
            } else {
                //echo "Pattern found at $result\n";
                //echo "Testing '" . $pattern . "' vs '" . $sentence . "'\n";
                return false;
            }
        }
        return true;
    }
    public function checkElement( $p ) {
        $result = ( strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) === false );
        /*
           debuglog( "checkElement: " . $p->parentNode->getAttribute('class') . " - " .
           strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) . " - " .
           $p->nodeValue . " - " . $result );
         */
        return $result;
    }
    public function prepareRaw( $text ) {
        debugPrint( "CBHtml::prepareRaw()" );
        $text = parent::prepareRaw( $text );
        return $text;
    }
    public function extract( $dom ) {
        debugPrint( "CBHtml::extract()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        return $paragraphs;
    }
    public function extractDate( $dom ) {
        debugPrint( "CBHtml::extractDate()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//time' );
        foreach( $paragraphs as $p ) {
            if( $p->getAttribute( 'datetime' ) ) {
                $parts = explode( " ", $p->getAttribute( 'datetime' ) );
                $this->date = str_replace( "-", "", $parts[0] );
                break;
            }
        }
        debuglog( "CBHtml:extractDate: " . $this->date );
        debugPrint( "CBHtml:extractDate: " . $this->date );
        return $this->date;
    }
    public function getPageList() {
        debugPrint( "CBHtml::getPageList()" );
        $baseurl = "https://www.civilbeat.org/projects/ka-ulana-pilina/";
        $dom = $this->getDOM( $baseurl, false );
        echo( "CBHtml::getPageList dom: " . $dom->childNodes->length . "\n" );
        //$html = $dom->saveHTML();
        //echo( "CBHtml::getPageList dom HTMLS: " . "$html\n" );
        $xpath = new DOMXpath($dom);
        $query = '//h2[contains(@class, "headline")]/a';
        $query = '//div[contains(@class, "archive cb-richtext")]/p/a';
        $query = '//div[contains(@class, "archive")]/p/a';
        $paragraphs = $xpath->query( $query );
        //echo( "CBHtml::getPageList paragraphs: " . $paragraphs->length . "\n" );
        $pages = [];

        foreach( $paragraphs as $p ) {
            //echo( "CBHtml::getPageList paragraph: " . $p->nodeValue . "\n" );
            $url = $p->getAttribute( 'href' );
            $text = trim( $this->prepareRaw( $p->nodeValue ) );
            $pages[] = [
                $text => [
                    'url' => $url,
                    'image' => '',
                ]
            ];  
        }

        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public function getSourceName( $title = '', $url = '' ) {
        if( $title ) {
            $this->title = $title;
        }
        if( $this->title ) {
            $parts = explode( '/', $this->title );
            if( sizeof($parts) > 2 ) {
                return $this->basename . ' ' . $parts[0] . $parts[1] . $parts[2];
            }
        }
        $parts = explode( '/', parse_url( $this->url, PHP_URL_PATH ) );
        if( sizeof($parts) > 3 ) {
            return $this->basename . ' ' . $parts[1] . $parts[2] . $parts[3];
        }
        return $this->basename;
    }
    public function fetch( $url, $doXML = false ) {
        debugPrint( "AolamaHtml::fetch($url)" );
        return parent::fetch( $url, false );
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        return $paragraphs;
    }
    public function extractDate( $dom ) {
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $this->date = $parts[1] . $parts[2] . $parts[3];
        }
        return $this->date;
    }
    public function prepareRaw( $text ) {
        $text = parent::prepareRaw( $text );
        //$text = preg_replace( '/\xc2/u', '', $text );
        //$text = preg_replace( '/\xa0/u', ' ', $text );
        return $text;
    }
    public function getPageList() {
        $page = 0;
        $baseurl = "https://keaolama.org/?infinity=scrolling&page=";
        $pages = [];
        while( true ) {
            $contents = file_get_contents( $baseurl . $page );
            $response = json_decode( $contents );
            if( $response->type != 'success' ) {
                break;
            }
            $urls = array_keys( (array)$response->postflair );
            foreach( $urls as $u ) {
                $value = [];
                $text = str_replace( 'https://keaolama.org/', '', $u );
                $pages[] = [
                    $text => [
                        'url' => $u,
                        'image' => '',
                    ]
                ];  
            }
            $page++;
        }
        return $pages;
    }
}

class UlukauHTML extends HtmlParse {
    private $basename = "Ulukau";
    private $baseurl = "https://puke.ulukau.org/ulukau-books/?a=p&p=bookbrowser&e=-------en-20--1--txt-txPT-----------";
    private $domain = "https://puke.ulukau.org";
    private $pageURL = "https://puke.ulukau.org/ulukau-books/?a=da&command=getSectionText&d=";
    private $pageURLSuffix = "&f=AJAX&e=-------en-20--1--txt-txPT-----------";
    private $pagemap;
    private $pagetitles;
    private $image = "";
    public $oid = 'EBOOK-HK2';

    public function getSourceName( $title = '', $url = '' ) {
        return ($title)?:$this->title;
    }
    public function extract( $dom ) {
        debugPrint( "UlukauHTML::extract(" . $dom->childnodes->length . " nodes)" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//p' );
        if( $paragraphs->count() < 1 ) {
            $paragraphs = $xpath->query( '//div' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom ) {
        debugPrint( "UlukauHTML::extractDate()" );
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $this->date = $parts[1] . $parts[2] . $parts[3];
        }
        return $this->date;
    }
    public function prepareRaw( $text ) {
        //$text = parent::prepareRaw( $text );
        $text = preg_replace( '/\xc2/u', '', $text );
        $text = preg_replace( '/\xa0/u', ' ', $text );
        $text = preg_replace( '/‚Äî/', '—', $text );
        $text = preg_replace( "/\&nbsp\;/", " ", $text );
        $text = preg_replace( "/\s+/", " ", $text );
        //$text = preg_replace( "/\s\s+/", " ", $text );
        $text = preg_replace( '/“|”/', '"', $text );
        $text = preg_replace( "/(\.|\?|\!)\s+(\S)/", "$1\n$2", $text );
        $text = preg_replace( "/||â/", "", $text );
        $text = str_replace( "", "'", $text );
        $text = str_replace( '"', "", $text );
        if( $this->mergeRows ) {
            $text = str_replace( "\n", " ", $text );
        }
        return trim( $text );
    }
    public function process( $contents ) {
        debugPrint( "UlukauHTML::process( " . $contents->childNodes->length . ")" );
        $rawResults = "";
        foreach( $contents as $p ) {
            if( $this->checkElement( $p ) ) {
                $text = trim( strip_tags( $p->nodeValue ) );
                //debuglog( "UlukauHtml::process before prepareRaw: " . $text );
                $text = $this->prepareRaw( $text );
                //debuglog( "UlukauHtml::process after prepareRaw: " . $text );
                /*
                   // Sometimes a sentence is split between pages
                   if( !preg_match( "/[\!\.\?\"]$/", $rawResults ) ) {
                   $rawResults .= " " . $text;
                   } else {
                   $rawResults .= "\n" . $text;
                   }
                 */
                $rawResults .= "\n" . $text;
            }
        }
        //echo "UlukauHtml::process after prepareRaw: " . $rawResults . "\n";
        $results = $this->processText( $rawResults );
        return $results;
    }
    public function processText( $text ) {
        debugPrint( "UlukauHTML::processText(" . strlen($text) . " bytes)" );
        $results = [];
        $text = html_entity_decode( $text );
        //debuglog( "UlukauHtml::processText: " . $text );
        $text = str_replace( "\n", " ", $text );
        //debuglog( "HtmlParse::processText after replacing /n: " . $text );
        $lines = preg_split('/(?<=[.?!])\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
        //$lines = preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        //debuglog( $lines );
        foreach( $lines as $sentence ) {
            $sentence = trim( $sentence );
            //$sentence = str_replace( "'", "''", $sentence );
            if( $this->checkSentence( $sentence ) ) {
                array_push( $results, $sentence );
            }
        }
        return $results;
    }
    public function getPageList() {
        debugPrint( "UlukauHTML::getPageList()" );
        $pages = [];
        $dom = $this->getDOM( $this->baseurl, false );
        $xpath = new DOMXpath($dom);
        $docdivs = $xpath->query( '//div[contains(@class, "ulukaubooks-book-browser-row")]' );
        foreach( $docdivs as $docdiv ) {
            $rightdivs = $xpath->query( 'div[contains(@class, "ulukaubooks-book-browser-row-right")]', $docdiv );
            $rightdiv = $rightdivs->item(0);
            $otherdivs = $xpath->query( 'div[contains(text(), "Language")]', $rightdiv );
            if( $otherdivs->length > 0 ) {
                $html = $otherdivs->item(0)->nodeValue;
                if( !strstr( $html, 'Hawaiian') ) {
                    continue;
                }
            }
            $count = $docdiv->childNodes->length;
            $leftdiv = $docdiv->childNodes->item(1);
            $otherdivs = $xpath->query( 'a/img', $leftdiv );
            if( $otherdivs->length > 0 ) {
                $this->image = $this->domain . $otherdivs->item(0)->getAttribute( 'data-src' );
            }
            $anchors = $xpath->query( 'div[contains(@class, "tt")]/b/a', $docdiv );
            if( $anchors->count() < 1 ) {
                continue;
            }
            $metadivs = $xpath->query( 'div[contains(@class, "la")]', $docdiv );
            foreach( $metadivs as $metadiv ) {
                if( strstr( $metadiv->textContent, 'Language') ) {
                    $langtext = $metadiv->nodeValue;
                    if( strpos( $langtext, "awaiian" ) ) {
                        $a = $anchors->item(0);
                        $author = $docdiv->getAttribute( "data-author" );
                        $year = $docdiv->getAttribute( "data-year" );

                        $u = $a->getAttribute( "href" );
                        $text = $a->nodeValue;
                        if( $author ) {
                            $text .= ", $author";
                        }
                        if( $year ) {
                            $text .= ", $year";
                        }
                        $pages[] = [
                            $text => [
                                'url' => $this->domain . $u,
                                'image' => $this->image,
                            ]
                        ];
                    }
                }
            }
        }
        ksort( $pages );
        debuglog( "Pages: " . var_export( $pages, true ) );
        debugPrint( "Pages: " . var_export( $pages, true ) );
        return $pages;
    }

    public function initialize( $baseurl ) {
        debugPrint( "UlukauHTML::initialize($baseurl)" );
        $contents = $this->getContents( $baseurl );
        file_put_contents( "/tmp/raw.html", $contents );

        $dom = $this->getDOM( $baseurl, false );
        $xpath = new DOMXpath($dom);
        $query = '//head/title';
        //$query = '//h2[contains(@class, "headline")]/a';
        $titles = $xpath->query( $query );
        $this->title = trim( $titles->item(0)->nodeValue );

        //        preg_match( '/\<title\>(.*)\<\/title\>/', $contents, $m );
        //        $this->title = html_entity_decode( $m[1] );

        $pattern = '/\bdocumentOID\s*=\s*(.*?);/s';
        if (preg_match($pattern, $contents, $matches)) {
            $this->oid = trim( $matches[1], "'" );
        }

        $pattern = '/\bpageToArticleMap\s*=\s*(.*?);/s';
        if (preg_match($pattern, $contents, $matches)) {
            $pagemap = $matches[1];
            $pagemap = str_replace( "'", '"', $pagemap );
            $this->pagemap = json_decode( $pagemap, true );
        }

        $contents = mb_convert_encoding($contents, 'HTML-ENTITIES', "UTF-8");
        $pattern = '/\bpageTitles\s*=\s*\{(.*?)\};/s';
        if (preg_match($pattern, $contents, $matches)) {
            $pagetitles = "{" . $matches[1] . "}";
            $pagetitles = preg_replace( "/\n/", "", $pagetitles );
            $pagetitles = str_replace( '"', "&quot;", $pagetitles );
            $pagetitles = str_replace( "'", '"', $pagetitles );
            $this->pagetitles = json_decode( $pagetitles, true );
        }

        // No useful date in these files
        //preg_match( '/\bCopyright(.*?)(\d+).*/', $contents, $matches );
        //$this->date = $matches[1];

        $this->url = $baseurl;
    }
    
    public function fixDiacriticals( $text ) {
        $pairs = array(
            "\xc5\xab" => "&#x16b;", // u
            "\xc5\x8d" => "&#x14d;",  // o
            "\xc5\x8c" => '&#332;', // O
            "\xca\xbb" => "&#x02bb;", // okina
            "\x80\x98" => "&#x02bb;", // okina
            "\xc4\x81" => "&#x101;",  // a
            "\xc4\x93" => "&#x113;",  // e
            "\xe2\x80\xb2" => "&#x02bb;",  // okina
            "\xc4\xab" => "&#x12b;", // i
            "\x9d" => '',
            "\x94" => ' ',
            "Ī" => "&#298;",
            //"ʻ" => "&quot;&quot;",
        );
        $replace = [
            '/\\\xe2/ui' => '&#x101;', // a
            '/\\\xe7/ui' => '&#x113;', // e
            '/\\\xf4/ui' => '&#x14d;', // o
            '/\\\xee/ui' => '&#x12b;', // i
            '/\\\xce/ui' => '&#x12c;', // I
            '/\\\xc7/ui' => '&#x112;', // E
            '/\\\x95/ui' => '&bull;',
            /*
               "/\\\xc5\\\xab/ui" => "&#x16b;", // u
               "/\\\xc5\\\x8d/ui" => "&#x14d;",  // o
               "/\\\xca\\\xbb/ui" => "&#x02bb;", // okina
               "/\\\x80\\\x98/ui" => "&#x02bb;", // okina
               "/\\\xc4\\\x81/ui" => "&#x101;",  // a
               "/\\\xc4\\\x93/ui" => "&#x113;",  // e
               "/\\\xe2\\\x80\xb2/ui" => "&#x02bb;",  // okina
               "/\\\xc4\\\xab/ui" => "&#x12b;", // i
             */
        ];
        $text = strtr($text, $pairs);
        //$text = preg_replace( array_keys( $replace ), array_values( $replace ), $text );
        return $text;
    }

    public function getRawText( $url ) {
        debugPrint( "UlukauHTML::getRawText($url)" );
        return $this->getFullText( $url );
        
        $fulltext = "";
        $done = 0;
        foreach( $this->pagemap as $key => $value ) {
            if( !$done ) {
                $title = $this->pagetitles[$key];
                // Skip cover page, back page, bibliography, etc
                if( preg_match( "/Page \d+\s*.*(\<|\&lt)/", $title ) ) {
                    $url = $this->pageURL . $this->oid . "." .
                           $value . $this->pageURLSuffix;
                    debuglog( "UlukauHtml getRawText fetching: '" .
                              $title . "' url: $url" );
                    $text .= $this->getDOM( $url )->saveXML();
                    $fulltext .= $text;
                }
            }
        }
        $text = html_entity_decode( $fulltext );
        $text = str_replace( $this->Amarker, 'Ā', $text );
        return $text;
    }

    public function getFullText( $url ) {
        debugPrint( "UlukauHTML::getFullText($url)" );
        $this->initialize( $url );
        $showRaw = $this->options['boxContent'] ?: false;
        $showLive = $this->options['synchronousOutput'] ?: $showRaw;
        if( $showRaw ) {
?>
            <script>
            function scrollDown() {
                let textarea = document.getElementById("raw");
                if( textarea ) {
                    textarea.scrollTop = textarea.scrollHeight;
                }
            }
            var repeatScroll = setInterval( scrollDown, 1000 );
            </script>
            <div style='width:100%;height:10em;overflow:auto;border:solid red 1px;padding:3px;' id='raw'>
                <h4 style=color:red>Source document</h4>
         <?php
         }
         $fulltext = "";
         $htmltext = "";
         $done = 0;
         foreach( $this->pagemap as $key => $value ) {
             if( !$done ) {
                 $title = $this->pagetitles[$key];
                 // Skip cover page, back page, bibliography, etc
                 if( preg_match( "/Page \d+\s*.*(\<|\&lt)/", $title ) ) {
                     $url = $this->pageURL . $this->oid . "." .
                            $value . $this->pageURLSuffix;
                     debuglog( "UlukauHtml getFullText fetching: '" .
                               $title . "' url: $url" );
                     $dom = $this->getDOM( $url, false );
                     //$htmltext .= $dom->saveHTML();
                     //echo "$html\n";
                     $xpath = new DOMXpath( $dom );
                     $contents = $xpath->query( '//sectiontext' );
                     //$contents = $xpath->query( 'div' );
                     foreach( $contents as $p ) {
                         $text = trim( preg_replace( "/\n+/", "\n", $p->nodeValue ) );
                         $text = $this->fixDiacriticals( $text );
                         // Skip anything at the end starting with the glossary
                         if( preg_match( "/Papa\s*Wehewehe\s*Hua/", $text ) ||
                             preg_match( "/Papa\s*Hua/", $text ) ) {
                             $done = 1;
                         } else if( !$done ) {
                             //debuglog( "XPath: " . $text );
                             if( $showLive ) {
                                 echo "$text\n";
                             }
                             $fulltext .= $text;
                         }
                     }
                 }
             }
         }
         if( $showRaw ) {
         ?>
             <script>
              clearInterval( repeatScroll );
             </script>
            </div>
    <?php
    }
    //file_put_contents( "/tmp/htmltext.html", $htmltext );
    file_put_contents( "/tmp/fulltext.html", $fulltext );
    return $fulltext;
}

public function adjustParagraphs( $fulltext ) {
    debugPrint( "UlukauHTML::adjustParagraphs(" . strlen($fulltext) . " bytes)" );
    //debuglog( "UlukauHtml::adjustParagraphs fullText:\n" . $fulltext );
    $fulltext = preg_replace( "/\n+/", "\n", $fulltext );
    $fulltext = preg_replace( '/\s*align="*(justify|right|left)"*\s*/', '', $fulltext );
    // Save the first line
    $firstline = preg_split('#</p>#', $fulltext, 2)[0] . "</p>\n";
    $therest = substr($fulltext, strpos($fulltext, "</p>") + 4);
    // Join paragraphs which were split by page boundaries
    $fulltext = $firstline . preg_replace( "/([^\?\.\!\"])\<\/p\>\n\<p\>/", "$1 ", $therest );
    file_put_contents( "/tmp/" . "simple" . ".html", $fulltext );
    return $fulltext;
}
public function adjustDom( $olddom ) {
    debugPrint( "UlukauHTML::adjustDom(" . $olddom->childNodes->length . " nodes)" );
    // Patch together elements split by a page, drop attributes
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
            if( $childNode->nodeName == "p" || $childNode->nodeName == "div" ) {
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
    return $dom;
}
public function extractSentencesFromHTML( $text ) {
    debugPrint( "UlukauHTML::extractSentencesFromHTML(" . strlen($text) . " chars)" );
    $text = trim( strip_tags( $text ) );
    $text = $this->prepareRaw( $text );
    $sentences = $this->processText( $text );
    return $sentences;
}
public function extractSentencesFromString( $fulltext ) {
    debugPrint( "UlukauHTML::extractSentencesFromString(" . strlen($fulltext) . " chars)" );
    //debugPrint( $fulltext );
    $sentences = [];
    $htmlString = "<html><body></body></html>";
    if( $fulltext ) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        $dom->loadHTML( $fulltext );
        //$dom = $this->adjustDOM( $dom );
        libxml_use_internal_errors(false);
        $htmlString = $dom->saveHTML();
        $paragraphs = $this->extract( $dom );
        debuglog( var_export( $paragraphs, true ) . " count=" . $paragraphs->count() );
        $sentences = $this->process( $paragraphs );
    }
    file_put_contents( "/tmp/simple.html", $htmlString );
    return $sentences;
}
public function extractSentences( $url ) {
    debugPrint( "UlukauHTML::extractSentences($url)" );
    $fulltext = $this->getFullText( $url );
    return $this->extractSentencesFromString( $fulltext );
}
}

class TextParse extends HtmlParse {
    public function getSentences( $text ) {
        return $this->processText( $text );
    }
}
?>

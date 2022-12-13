<?php

class HtmlParse {
    protected $Amarker = 'xXxXxX';
    protected $date = '';
    protected $url = '';

    public function getSourceName( $title = '' ) {
        return "What's my source name?";
    }
    public function fetch( $url ) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $text = file_get_contents( $url );
        //echo "HtmlParse::fetch after file_get_contents: $text\n";
        $text = $this->preprocessHTML( $text );
        //echo "HtmlParse::fetch after preprocessHTML: $text\n";
        $dom->encoding = 'utf-8';
        $dom->loadHTML( $text );
        libxml_use_internal_errors(false);
        $this->url = $url;
        return $dom;
    }
    public function checkSentence( $sentence ) {
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
        $text = preg_replace( '/\x94/', '-', $text );
        $text = preg_replace( '/\s*\<br\s*\\*\>/', '\n', $text );
        $text = preg_replace( '/["“”‘’\\n]/', '', $text );
        // Restore the removed Ā
        $text = str_replace( $this->Amarker, 'Ā', $text );
        $text = str_replace( '&nbsp;', ' ', $text );
        //echo "HtmlParse::prepareRow after: $text\n";
        return $text;
    }
    public function preprocessHTML( $text ) {
        // DomDocument can't handle Ā, it appears
        $text = str_replace( 'Ā', $this->Amarker, $text );
        // This is some kind of extended dash which also is rendered incorrectly
        $text = str_replace( '&#8211;', '-', $text );
        return $text;
    }
    public function processText( $text ) {
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
    public function extractSentences( $url ) {
        $this->url = $url;
        $dom = $this->fetch( $url );
        //echo "HtmlParse::extractSentences after fetch: " . var_export( $dom, true ) . "\n";
        $contents = $this->extract( $dom );
        $this->extractDate( $dom );
        //echo "HtmlParse::extractSentences after extract: " . $this->unpackNodeList( $contents ) . "\n";
        $sentences = $this->process( $contents );
        return $sentences;
    }
    public function extractDate( $dom ) {
    }
    public function getPageList() {
        return [];
    }
}

class CBHtml extends HtmlParse {
    private $sourceName = 'Ka Ulana Pilina';
    public function getSourceName( $title = '' ) {
        $name = $this->sourceName;
        debuglog( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        if( $this->date ) {
            $name .= " " . $this->date;
        }
        return $name;
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
        $text = parent::prepareRaw( $text );
        $text = preg_replace( '/\xc2\xa0/', ' ', $text );

        /*
           $text = preg_replace( '/\xef|\xbb|\xbf|\xef|\xbb|\xbf/u', ' ', $text );
           $text = preg_replace( '/\xef/u', ' ', $text );
           $text = preg_replace( '/\xbb/u', ' ', $text );
         */

        return $text;
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        return $paragraphs;
    }
    public function extractDate( $dom ) {
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
        return $this->date;
    }
    public function getPageList() {
        $baseurl = "https://www.civilbeat.org/projects/ka-ulana-pilina/";
        $dom = $this->fetch( $baseurl );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//h2[contains(@class, "headline")]//a' );
        $pages = [];
        foreach( $paragraphs as $p ) {
            $url = $p->getAttribute( 'href' );
            $text = trim( $this->prepareRaw( $p->nodeValue ) );
            $value = [];
            $value[$text] = $url;
            array_push( $pages, $value );
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public function getSourceName( $title = '' ) {
        if( $title ) {
            $parts = explode( '/', $title );
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
                $value[$text] = $u;
                array_push( $pages, $value );
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
    private $pagemap;
    private $pagetitles;
    private $title = "";
    public $oid = 'EBOOK-HK2';
    public function getSourceName( $title = '' ) {
        return ($title)?:$this->title;
    }
    public function fetch( $url ) {
        libxml_use_internal_errors(true);
        $text = file_get_contents( $url );
        debuglog( "URL: $url" );
        debuglog( "Raw: " . $text );
        //echo "$text";
        //$text = $this->preprocessHTML( $text );
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        // This one is XML while the others are HTML
        $dom->loadXML( $text );
        libxml_use_internal_errors(false);
        $this->url = $url;
        return $dom;
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//p' );
        if( $paragraphs->count() < 1 ) {
            $paragraphs = $xpath->query( '//div' );
        }
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
        //$text = parent::prepareRaw( $text );
        $text = preg_replace( '/\xc2/u', '', $text );
        $text = preg_replace( '/\xa0/u', ' ', $text );
        $text = preg_replace( "/\&nbsp\;/", " ", $text );
        $text = preg_replace( "/\s\s+/", " ", $text );
        $text = preg_replace( "/(\.|\?|\!)\s+(\S)/", "$1\n$2", $text );
        return trim( $text );
    }
    public function process( $contents ) {
        $rawResults = "";
        foreach( $contents as $p ) {
            if( $this->checkElement( $p ) ) {
                $text = trim( strip_tags( $p->nodeValue ) );
                $text = str_replace( "\n", " ", $text );
                $text = preg_replace( "/||â/", "", $text );
                $text = str_replace( "", "'", $text );
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
        $results = [];
        $text = html_entity_decode( $text );
        //debuglog( "UlukauHtml::processText: " . $text );
        $lines = preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        //debuglog( $lines );
        foreach( $lines as $sentence ) {
            $sentence = trim( $sentence );
            if( $this->checkSentence( $sentence ) ) {
                array_push( $results, $sentence );
            }
        }
        return $results;
    }
    public function getPageList() {
        $pages = [];
        $parser = new HtmlParse();
        $dom = $parser->fetch( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $divs = $xpath->query( '//div[contains(@class, "ulukaubooks-book-browser-row")]' );
        foreach( $divs as $div ) {
            $anchors = $xpath->query( 'div/div[contains(@class, "tt")]/*/a', $div );
            if( $anchors->count() < 1 ) {
                continue;
            }
            $langdivs = $xpath->query( 'div/div[contains(@class, "la")]', $div );
            if( $langdivs->count() > 0 ) {
                $lang = $langdivs->item(0);
                $langtext = $lang->nodeValue;
                if( strpos( $langtext, "awaiian" ) == false ) {
                    continue;
                }
            }
            $a = $anchors->item(0);
            $author = $div->getAttribute( "data-author" );
            $year = $div->getAttribute( "data-year" );

            $u = $a->getAttribute( "href" );
            $text = $a->nodeValue;
            if( $author ) {
                $text .= ", $author";
            }
            if( $year ) {
                $text .= ", $year";
            }
            $value = [];
            $value[$text] = $this->domain . $u;
            array_push( $pages, $value );
        }
        ksort( $pages );
        debuglog( "Pages: " . var_export( $pages, true ) );
        return $pages;
    }


    public function initialize( $baseurl ) {
        $contents = file_get_contents( $baseurl );
        preg_match( '/\<title\>(.*)\<\/title\>/', $contents, $m );
        $this->title = html_entity_decode( $m[1] );
        preg_match( '/.*var documentOID = \'(.*)\';/', $contents, $m );
        $this->oid = $m[1];
        preg_match( '/var pageToArticleMap = (\{.*\})/', $contents, $m );
        $pagemap = $m[1];
        $pagemap = str_replace( "'", '"', $pagemap );
        $this->pagemap = json_decode( $pagemap, true );
        preg_match( '/var pageTitles = (\{.*\})/', $contents, $m );
        $pagetitles = $m[1];
        $pagetitles = str_replace( "'", '"', $pagetitles );
        $this->pagetitles = json_decode( $pagetitles, true );
        preg_match( '/.*Copyright © (\d+).*/', $contents, $m );
        $this->date = $m[1];
    }
    public function getFullText( $url ) {
        $this->initialize( $url );
        $this->url = $url;
        $fulltext = "";
        $pairs = array(
            "\xc5\xab" => "&#x16b;", // u
            "\xc5\x8d" => "&#x14d;",  // o
            "\xca\xbb" => "&#x02bb;", // okina
            "\x80\x98" => "&#x02bb;", // okina
            "\xc4\x81" => "&#x101;",  // a
            "\xc4\x93" => "&#x113;",  // e
            "\xe2\x80\xb2" => "&#x02bb;",  // okina
            "\xc4\xab" => "&#x12b;", // i
        );
        $done = 0;
        foreach( $this->pagemap as $key => $value ) {
            if( !$done ) {
                $title = $this->pagetitles[$key];
                // Skip cover page, back page, bibliograph, etc
                if( preg_match( "/Page \d+\s*(\<|\&lt)/", $title ) ) {
                    $url = "https://puke.ulukau.org/ulukau-books/" .
                           "?a=da&command=getSectionText&d=" .
                           $this->oid . "." . $value .
                           "&f=AJAX&e=-------en-20--1--txt-txPT-----------";
                    debuglog( "UlukauHtml extractSentences fetching: '" .
                              $title . "' url: $url" );
                    $dom = $this->fetch( $url );
                    $xpath = new DOMXpath( $dom );
                    $contents = $xpath->query( '//SectionText' );
                    foreach( $contents as $p ) {
                        $text = strtr($p->nodeValue, $pairs);
                        // Skip anything at the end starting with the glossary
                        if( preg_match( "/Papa\s*Wehewehe\s*Hua/", $text ) ||
                            preg_match( "/Papa\s*Hua/", $text ) ) {
                            $done = 1;
                        } else if( !$done ) {
                            //debuglog( "XPath: " . $text );
                            $fulltext .= $text;
                        }
                    }
                }
            }
        }
        file_put_contents( "/tmp/raw.html", $fulltext );
        return $fulltext;
    }
    public function adjustParagraphs( $fulltext ) {
        //debuglog( "UlukauHtml::extractSentences fullText:\n" . $fulltext );
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
    public function extractSentences( $url ) {
        $fulltext = $this->getFullText( $url );
        $fulltext = $this->adjustParagraphs( $fulltext );
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        $dom->loadHTML( $fulltext );
        libxml_use_internal_errors(false);
        $paragraphs = $this->extract( $dom );
        debuglog( var_export( $paragraphs, true ) . " count=" . $paragraphs->count() );
        $sentences = $this->process( $paragraphs );
        return $sentences;
    }
}

class TextParse extends HtmlParse {
    public function getSentences( $text ) {
        return $this->processText( $text );
    }
}
?>

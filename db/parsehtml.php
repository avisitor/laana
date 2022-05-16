<?php
require_once( 'funcs.php' );

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
        if( strlen( preg_replace( '/\s+/', '', $sentence ) ) < 2 ) {
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
        //debuglog( "HtmlParse::processText: " . $text );
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

class TextParse extends HtmlParse {
    public function getSentences( $text ) {
        return $this->processText( $text );
    }
}

?>

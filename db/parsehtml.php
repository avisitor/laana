<?php

class HtmlParse {
    private $Amarker = 'xXxXxX';
    public function getSourceName() {
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
        return $dom;
    }
    public function checkSentence( $sentence ) {
        if( strlen( preg_replace( '/\s+/', '', $sentence ) ) < 1 ) {
            return false;
        }
        return true;
    }
    public function prepareRaw( $text ) {
        #echo "HtmlParse::prepareRow before: $text\n";
        $text = preg_replace( '/\s*\<br\s*\\*\>/', '\n', $text );
        $text = preg_replace( '/["“”]/', '', $text );
        // Restore the removed Ā
        $text = str_replace( $this->Amarker, 'Ā', $text );
        #echo "HtmlParse::prepareRow after: $text\n";
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
        $lines = explode( '\n', $text );
        foreach( $lines as $line ) {
            // What to do with sentences ending in a question mark?
            if( preg_match( '/\?/', $line ) ) {
                //echo "? found in '" . $line . "\n";
            }
            $sentences = explode( ".", $line );
            foreach( $sentences as $sentence ) {
                $sentence = trim( $sentence );
                if( $this->checkSentence( $sentence ) ) {
                    array_push( $results, $sentence . "." );
                }
            }
        }
        return $results;
    }
    public function process( $contents ) {
        $rawResults = "";
        foreach( $contents as $p ) {
            $text = strip_tags( $p->nodeValue );
            //echo "HtmlParse::process before prepareRaw: " . $text . "\n";
            $text = $this->prepareRaw( $text );
            //echo "HtmlParse::process after prepareRaw: " . $newtext . "\n";
            $rawResults .= "\n" . $text;
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
        $dom = $this->fetch( $url );
        //echo "HtmlParse::extractSentences after fetch: " . var_export( $dom, true ) . "\n";
        $contents = $this->extract( $dom );
        //echo "HtmlParse::extractSentences after extract: " . $this->unpackNodeList( $contents ) . "\n";
        $sentences = $this->process( $contents );
        return $sentences;
    }
    public function getPageList() {
        return [];
    }
}

class CBHtml extends HtmlParse {
    public function getSourceName() {
        return 'Ka Ulana Pilina';
    }
    public function checkSentence( $sentence ) {
        if( !parent::checkSentence( $sentence ) ) {
            return false;
        }

        // There are some irrelevant phrases among the sentences
        $toSkip = [
            "Click here to read",
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
    public function prepareRaw( $text ) {
        $text = parent::prepareRaw( $text );
        $text = preg_replace( '/\xc2/u', '', $text );
        $text = preg_replace( '/\xa0/u', ' ', $text );
        return $text;
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        return $paragraphs;
    }
    public function getPageList() {
        $baseurl = "https://www.civilbeat.org/projects/ka-ulana-pilina/";
        $dom = $this->fetch( $baseurl );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//h2[contains(@class, "headline")]//a' );
        $pages = [];
        foreach( $paragraphs as $p ) {
            $url = $p->getAttribute( 'href' );
            $text = trim( $p->nodeValue );
            $value = [];
            $value[$text] = $url;
            array_push( $pages, $value );
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    public function getSourceName() {
        return "Ke Aolama";
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        return $paragraphs;
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

<?php
include_once 'funcs.php';

$debugFlag = false;
function setDebug( $debug ) {
    global $debugFlag;
    $debugFlag = $debug;
}

function debugPrint( $text ) {
    global $debugFlag;
    if( $debugFlag ) {
        echo "$text\n";
    }
}

class HtmlParse {
    protected $Amarker = '&#256;';
    public $date = '';
    protected $url = '';
    protected $urlBase = '';
    public $baseurl = '';
    protected $dom;
    public $title = "";
    public $authors = "";
    protected $minWords = 1;
    protected $mergeRows = true;
    protected $options = [];
    public $groupname = "";
    protected $endMarkers = [
        "applesauce",
        "O KA HOOMAOPOPO ANA I NA KII",
        "TRANSLATION OF INTRODUCTION",
        "After we pray",
        "Look up",
        "Share this",
    ];
    protected $startMarker = "";
    protected $toSkip = [];
    protected $badTags = [
        "//noscript",
        "//script"
    ];
    protected $badIDs = [
    ];
    protected $badClasses = [
    ];
    public $months = [
        'Malaki' => '03',
        "Nowemapa" => '11',
        "Mei" => '05',
        "Iulai" => '07',
        "Apelila" => '04',
        "Aperila" => '04',
        "Pepeluali" => '02',
        "Ianuali" => '01',
        "Kekemapa" => '12',
        "Okakopa" => '10',
        "Kepakemapa" => '09',
        "Aukake" => '08',
        "Iune" => '06',
    ];

    public function __construct( $options = [] ) {
        $this->options = $options;
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "HtmlParse::getSourceName($title,$url)" );
        return $title ?: "What's my source name?";
    }
    
    public function initialize( $baseurl ) {
        $this->url = $this->domainUrl = $this->baseurl = $baseurl;
    }
    
    // This just does an HTTP fetch
    public function getRaw( $url, $options=[] ) {
        debugPrint( "HtmlParse::getRaw( $url )" );
        debuglog( "HtmlParse::getRaw( $url )" );
        $text = file_get_contents( $url );
        //file_put_contents( "/tmp/raw.html", $text );
        return $text;
    }
    
    // This is called to fetch the entire text of a document, which could have several pages
    public function getRawText( $url, $options=[] ) {
        return $this->getRaw( $url, $options );
    }
    
    // Called after preprocessHTML
    protected function cleanup( $text ) {
        return $text;
    }

    // This fetches the entire text of a document and does character set cleanup
    public function getContents( $url, $options=[] ) {
        debugPrint( "HtmlParse::getContents( $url )" );
        // Get entire document, which could span multiple pages
        $text = $this->getRawText( $url, $options );
        $nchars = strlen( $text );
        debugPrint( "HtmlParse::getContents() got $nchars characters from getRawText" );
        // Take care of any character cleanup
        $text = $this->preprocessHTML( $text );
        $text = $this->cleanup( $text );
        //debugPrint( "HtmlParse::getContents() $text" );
        debugPrint( "HtmlParse::getContents() finished" );
        return $text;
    }
    
    // Returns DOM from string, either assuming HTML
    public function getDOMFromString( $text, $options=[] ) {
        $nchars = strlen( $text );
        debugPrint( "HtmlParse::getDOMFromString($nchars characters)" );
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        if( $text ) {
            $text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
            $dom->loadHTML( $text );
            $errors = "";
            foreach (libxml_get_errors() as $error) {
                $errors .= "; " . $error->message;
            }
            if( $errors ) {
                debuglog( "HtmlParse::getDOMFromString XML errors: $errors" );
                //debugPrint( "HtmlParse::getDOMFromString XML errors: $errors" );
            }
            libxml_clear_errors();

            //$text = $dom->saveHTML();
            //debugPrint( $text );
        }
        debugPrint( "HtmlParse::getDOMFromString() finished" );
        return $dom;
    }
    
    // Returns DOM from URL, either assuming XML or HTML
    public function getDOM( $url, $options = [] ) {
        debugPrint( "HtmlParse::getDOM()" );
        //$this->initialize( $url );
        if( !isset($options['preprocess']) || $options['preprocess'] ) {
            $text = $this->getContents( $url, $options );
        } else {
            $text = $this->getRaw( $url, $options );
        }
        $this->dom = $this->getDOMFromString( $text, $options );
        debugPrint( "HtmlParse::getDOM() finished" );
        return $this->dom;
    }
    
    // Evaluate if a sentence should be retained
    public function checkSentence( $sentence ) {
        //debugPrint( "HtmlParse:checksentence(" . strlen($sentence) . " characters)" );
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
        foreach( $this->toSkip as $pattern ) {
            if( strpos( $sentence, $pattern ) !== false ) {
                //echo "Pattern found at $result\n";
                //echo "Testing '" . $pattern . "' vs '" . $sentence . "'\n";
                return false;
            }
        }
        return true;
    }
    
    // Split text into lines by inserting \n
    public function prepareRaw( $text ) {
        //debugPrint( "HtmlParse::prepareRaw(" . strlen($text) . " characters" );
        $text = preg_replace( '/\s*\<br\s*\\*\>/', '\n', $text );
        $text = preg_replace( '/"/', '', $text );
        // Restore the removed Ā
        $text = str_replace( $this->Amarker, 'Ā', $text );
        $text = str_replace( '&nbsp;', ' ', $text );
        $text = trim( $text );
        //echo "HtmlParse::prepareRaw after: $text\n";
        return $text;
    }
    
    // Attempt to replace all problematic characters
    // Not currently used because converting to UTF-8 before inserting into DOM works better
    /*
    public function fixDiacriticals( $text ) {
        // DomDocument can't handle Ā, it appears
        $pairs = array(
            "\xc5\xab" => "&#x16b;", // u
            //"\xc5\xab" => "ū", // u
            "\xc5\x8d" => "&#x14d;",  // o
            "\xc5\x8c" => '&#332;', // O
            //"\xca\xbb" => "&#x02bb;", // okina
            "\xca\xbb" => "'",
            "\x80\x99" => "'",
            "\x80\x98" => "&#x02bb;", // okina
            //"\xc4\x81" => "&#x101;",  // a
            "\xc4\x81" => "'",
            "\xc4\x93" => "&#x113;",  // e
            "\xe2\x80\xb2" => "&#x02bb;",  // okina
            "\xc4\xab" => "&#x12b;", // i
            "\x9d" => '',
            "\x94" => ' ',
            "Ī" => "&#298;",
            //"ʻ" => "&quot;&quot;",
            "Ā" => $this->Amarker,
            "\xbf\xbd" => $this->Amarker,
            "&#8211;" => "-",
            "\xc2\xa0" => " ",
            "\xc2" => "",
            "\xc4" => "",
            "\x80" => "",
            "\x81" => "",
            " \x93" => " - ",
            "\x94" => "-",
            "\x99" => "",
            "\xBB" => "‘",
            //"\xAB" => "ū",
            //"\x8C" => "Ō",
            //'"' => "",
            "“" => "",
            "”" => "",
            "‘" => "",
            "’" => "",
            "''" => "",
            "&quot;" =>"",
            "&#145;" => "",
            "&#146;" => "",
            "&#147;" => "",
            "&#148;" => "",
            "\n" => "",
            "ä" => "ā",
            "ë" => "ē",
            "ï" => "ī",
            "ö" => "ō",
            "ü" => "ū",
            "Ä" => "Ā",
            "Ë" => "Ē",
            "Ï" => "Ī",
            "Ö" => "Ō",
            "Ü" => "Ū",
            "æ" => '‘',
            ".) " => ") ",
            "“" => "",
            "”" => "",
            "‘" => "",
            "’" => "",
        );
        $text = strtr($text, $pairs);

           $replace = [
           '/\\\xe2/ui' => '&#x101;', // a
           '/\\\xe7/ui' => '&#x113;', // e
           '/\\\xf4/ui' => '&#x14d;', // o
           '/\\\xee/ui' => '&#x12b;', // i
           '/\\\xce/ui' => '&#x12c;', // I
           '/\\\xc7/ui' => '&#x112;', // E
           '/\\\x95/ui' => '&bull;',
           ];
           $text = preg_replace( array_keys( $replace ), array_values( $replace ), $text );

        return $text;
    }
    */

    // Unused potential character set conversion
/*
    public function moreCharacters( $text ) {
        $pairs = array(
            "ä" => "ā",
            "ë" => "ē",
            "ï" => "ī",
            "ö" => "ō",
            "ü" => "ū",
            "Ä" => "Ā",
            "Ë" => "Ē",
            "Ï" => "Ī",
            "Ö" => "Ō",
            "Ü" => "Ū",
            "æ" => '‘',
            ".) " => ") ",
            "“" => "",
            "”" => "",
            "‘" => "",
            "’" => "",
        );
        $text = trim( strtr($text, $pairs) );
        return $text;
    }
*/

    public function removeElements( $text ) {
        debugPrint( "HtmlParse::removeElements(" . strlen($text) . " characters)" );
        debuglog( "HtmlParse::removeElements(" . strlen($text) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        $dom->loadHTML( $text );
        $xpath = new DOMXpath($dom);
        $changed = 0;
        foreach( $this->badTags as $filter ) {
            debugPrint( "HtmlParse::removeElements() looking for tag $filter" );
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                debugPrint( "HtmlParse::removeElements() found tag $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badIDs as $filter ) {
            debugPrint( "HtmlParse::removeElements() looking for ID $filter" );
            $p = $xpath->query( "//div[@id='$filter']" );
            foreach( $p as $element ) {
                debugPrint( "HtmlParse::removeElements() found ID $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badClasses as $filter ) {
            $query = "//div[contains(@class,'$filter')]";
            //$query = "//div[class*='$filter']";
            debugPrint( "HtmlParse::removeElements() looking for $query" );
            $p = $xpath->query( $query );
            foreach( $p as $element ) {
                debugPrint( "HtmlParse::removeElements() found class $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        debugPrint( "HtmlParse::removeElements() removed $changed tags" );
        debuglog( "HtmlParse::removeElements() removed $changed tags" );
        $text = $dom->saveHTML();
        return $text;
    }
    
    public function updateLinks( $text ) {
        debugPrint( "HtmlParse::updateLinks(" . strlen($text) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        $dom->loadHTML( $text );
        $xpath = new DOMXpath($dom);
        $filters = [
            "//img",
            "//a",
        ];
        $attrs = [
            "src",
            "href",
        ];
        $changed = 0;
        foreach( $filters as $filter ) {
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                //$outerHTML = $element->ownerDocument->saveHTML($element);
                //echo "outerHTML: $outerHTML\n";
                foreach( $attrs as $attr ) {
                    $url = $element->getAttribute($attr);
                    if( preg_match( '/^\//', $url ) ) {
                        $url = $this->urlBase . $url;
                        $element->setAttribute( $attr, $url );
                        $changed++;
                    }
                }
                //$outerHTML = $element->ownerDocument->saveHTML($element);
                //echo "new outerHTML: $outerHTML\n";
            }
        }
        if( $changed ) {
            $text = $dom->saveHTML();
        }
        return $text;
    }
    
    // This is to settle issues with character encoding upfront, on reading the raw HTML from
    // the source
    public function convertEncoding( $text ) {
        debugPrint( "HtmlParse::convertEncoding(" . strlen($text) . " characters)" );
        if( !$text ) {
            return $text;
        }
        
        // Works better to convert the text to use entities with UTF-8 and then swap them out
        // Too much variability in encoding before the entity conversion
        $text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");

        $pairs = array(
            '&raquo;' => '',
            '&laquo;' => '',
            '&mdash;' => '-',
            '&nbsp;' => ' ',
            "&Ecirc;" => '‘',
            "&Aring;" => 'ū',
            '&Atilde;&#133;&Acirc;&#140;' => 'Ō',
            '&Atilde;&#133;&Acirc;' => 'ū',
            '&Atilde;&#132;&Acirc;' => 'ā',
            '&Atilde;&#138;&Acirc;&raquo;' => '‘',
            "&Acirc;" => '',
            "&acirc;" => '',
            '&lsquo;' => "'",
            '&rsquo;' => "'",
            '&rdquo;' => '"',
            '&ldquo;' => '"',

            "&auml;" => "ā",
            "&Auml;" => "Ā",
            "&Euml;" => "Ē",
            "&euml;" => "ē",
            "&Iuml;" => "Ī",
            "&iuml;" => "ī",
            "&ouml;" => "ō",
            "&Ouml;" => "Ō",
            "&Uuml" => "Ū",
            "&uuml;" => "ū",
            "&aelig;" => '‘',

            "&#128;&#148;" => '-',
            "&#128;&#152;" => '‘',
            "&#129;" => "",
            "&#140;" => "Ō",
            "&#146;" => "'",
            "&#256;" => "Ā",
            "&#257;" => "ā",
            "&#274;" => "Ē",
            "&#275;" => "ē",
            "&#298;" => "Ī",
            "&#299;" => "ī",
            "&#332;" => "Ō",
            "&#333;" => "ō",
            "&#362;" => "Ū",
            "&#363;" => "ū",
            "&#699;" => '‘',
        );
        // Convert back to UTF-8
        $text = strtr($text, $pairs);

        return $text;
    }

    // This is to settle issues with character encoding upfront, on reading the raw HTML from
    // the source, to remove some unused tags and to complete any partial links
    public function preprocessHTML( $text ) {
        debugPrint( "HtmlParse::preprocessHTML(" . strlen($text) . " characters)" );
        if( !$text ) {
            return $text;
        }
        
        // Remove a few elements
        $text = $this->removeElements( $text );

        // Complete links
        $text = $this->updateLinks( $text );

        // Make sure the character set works
        $text = $this->convertEncoding( $text );

        return $text;
    }

    // Split text into an array of lines
    public function processText( $text ) {
        $nchars = strlen($text);
        $results = [];

        //$text = $this->preprocessHTML( $text );
        $text = html_entity_decode( $text );

        //debugPrint( "HTMLParse::processText: " . $text );

        // Consolidate multiple space characters
        $replace = [
           '/ /' =>             ' ',
           '/\n|\r/' =>         ' ',
           "/\s+/" =>           " ",
           "/\s+(\!|\;|\:)/"=> '$1',
        ];
        $text = preg_replace( array_keys( $replace ), array_values( $replace ), $text );

        $letters = 'āĀĒēĪīōŌŪūa-zA-Z0-9‘';
        $lettersAll = $letters . '"';
        $letters = "[$letters]";
        $lettersAll = "[$lettersAll]";

        ///$pattern = '(?<!\w\.\w.)(?<![A-Z][a-z]\.)(?<=\.|\?|\!\:)\s+|\p{Cc}+|\p{Cf}+';
        $pattern = '/(?<=[.?!])\s*/';
        //$pattern = '/[^.!?\s][^.!?\n]*(?:[.!?](?![\'"]?\s|$)[^.!?]*)*[.!?]?[\'"]?(?=\s|$)/';
        $pattern = '~(' . $letters . '([.?!]"?|"?[.?!]))\K\s+(?=' . $lettersAll . ')~';
        $lines = preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );
        
        debuglog( "HtmlParse::processText $nchars characters, " . sizeof($lines) . " lines" );
        $carryOver = "";
        $beyondStart = false;
        if( $this->startMarker ) {
            // If the startMarker is not present, consume the whole text
            foreach( $lines as $sentence ) {
                $beyondStart = strpos( $sentence, $this->startMarker );
                if( $beyondStart !== false ) {
                    break;
                }
            }
            if( !$beyondStart ) {
                // Not present
                $beyondStart = true;
            }
        }
        foreach( $lines as $sentence ) {
            $sentence = trim( $sentence );
            // Check for start of usable text
            if( $this->startMarker && !$beyondStart ) {
                $beyondStart = strpos( $sentence, $this->startMarker );
                if( $beyondStart === false ) {
                    //debugPrint( "HtmlParse::processText did not match start marker " .
                    //"|{$this->startMarker}|\n$sentence" );
                    continue;
                } else {
                    debugPrint( "HtmlParse::processText matched start marker |{$this->startMarker}|" );
                    $beyondStart = true;
                }

            }
            // Check for end of usable text
            foreach( $this->endMarkers as $pattern ) {
                //debugPrint( "HtmlParse::processText comparing |$pattern| to |$sentence|" );
                $result = strpos( $sentence, $pattern );
                if( $result !== false ) {
                    debugPrint( "HtmlParse::processText matched end marker |$pattern|" );
                    return $results;
                }
            }
            // Check for valid sentence
            if( $this->checkSentence( $sentence ) ) {
                // Check for splitting off e.g. "Mr." as if it was the end of a sentence
                if( $carryOver ) {
                    $results[sizeof($results)-1] = $carryOver . $results[sizeof($results)-1];
                    $carryOver = "";
                } else {
                    if( preg_match( '/^(\w{1,4}?\.)+$/', $sentence ) ) {
                        $carryOver = $sentence;
                        //echo "Carrying over $sentence\n";
                    } else if ( preg_match( '/.*?(Mr|Mrs|St)\.$/', $sentence ) ) {
                        $carryOver = $sentence . ' ';
                        //echo "Carrying over $sentence\n";
                    } else {
                        //echo "Not carrying over $sentence\n";
                        array_push( $results, $sentence );
                    }
                }
            }
        }
        return $results;
    }

    // Extract text from a DOM node
    public function checkElement( $p ) {
        //debugPrint( "HtmlParse::checkElement()" );
        $text = trim( strip_tags( $p->nodeValue ) );
        return $text;
    }

    // Extract all text from a list of DOM nodes
    public function process( $contents ) {
        debugPrint( "HtmlParse::process()" );
        $rawResults = "";
        foreach( $contents as $p ) {
            if( $text = $this->checkElement( $p ) ) {
                //debugPrint( "HtmlParse::process before prepareRaw: " . $text );
                $text = $this->prepareRaw( $text );
                //debugPrint( "HtmlParse::process after prepareRaw: " . $text );
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
        //echo "HtmlParse::process after prepareRaw: " . $rawResults . "\n";
        $results = $this->processText( $rawResults );
        return $results;
    }

    // Extract an array of lines by looking at the text in all tags
/*
    public function extractSentencesFromString( $text ) {
        debugPrint( "HtmlParse::extractSentencesFromString(" . strlen($text) . " chars)" );
        debuglog( "HtmlParse::extractSentencesFromString(" . strlen($text) . " chars)" );
        $text = trim( strip_tags( $text ) );
        $text = $this->prepareRaw( $text );
        $sentences = $this->processText( $text );
        return $sentences;
    }
*/

    // Extract an array of lines by converting the text into a DOM document and examining all DOM nodes
    public function extractSentencesFromHTML( $text, $options=[] ) {
        debugPrint( "HtmlParse::extractSentencesFromHTML(" . strlen($text) . " chars)" );
        debuglog( "HtmlParse::extractSentencesFromHTML(" . strlen($text) . " chars)" );
        libxml_use_internal_errors(true);
        $dom = $this->getDOMFromString( $text, $options );
        $this->extractDate( $dom );
        //$dom = $this->adjustDOM( $dom );
        libxml_use_internal_errors(false);
        $paragraphs = $this->extract( $dom );
        debuglog( var_export( $paragraphs, true ) . " count=" . $paragraphs->count() );
        $sentences = $this->process( $paragraphs );
        return $sentences;
    }

    // Extract an array of lines by looking at the text in all tags from an URL
    public function extractSentences( $url, $options=[] ) {
        debugPrint( "HtmlParse::extractSentences($url)" );
        $this->url = $this->domainUrl = $url;
        $contents = $this->getContents( $url, $options );
        $sentences = $this->extractSentencesFromHTML( $contents, $options );
        return $sentences;
    }

    // Stub function to extract the date from the DOM of a document
    public function extractDate( $dom ) {
        debugPrint( "HtmlParse::extractDate()" );
        return $this->date;
    }

    // Get list of all documents on the Web for a particular parser type
    public function getPageList() {
        debugPrint( "HtmlParse::getPageList()" );
        return [];
    }
}

class CBHtml extends HtmlParse {
    public function __construct( $options = [] ) {
        $this->toSkip = [
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
        $this->urlBase = "https://www.civilbeat.org";
        $this->baseurl = "https://www.civilbeat.org/projects/ka-ulana-pilina/";
    }
    private $sourceName = 'Ka Ulana Pilina';
    public $groupname = "kaulanapilina";

    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "CBHtml::getSourceName($title,$url)" );
        $name = $this->sourceName;
        debuglog( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        debugPrint( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        //if( !$this->date ) {
        if( $url ) {
            $this->url = $url;
            $dom = $this->getDOM( $this->url );
            $this->extractDate( $dom );
        }
        //}
        if( $this->date ) {
            $name .= ": " . $this->date;
        }
        return $name;
    }

    public function checkElement( $p ) {
        $result = ( strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) === false );
        return ($result) ? parent::checkElement( $p ) : '';
    }
    
    public function extract( $dom ) {
        debugPrint( "CBHtml::extract()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        return $paragraphs;
    }

    public function extractDate( $dom ) {
        debugPrint( "CBHtml::extractDate()" );
        $this->date = '';
        $xpath = new DOMXpath( $dom );
        $query = '//meta[contains(@property, "article:published_time")]';
        $paragraphs = $xpath->query( $query );
        if( $paragraphs->length > 0 ) {
            $p = $paragraphs->item(0);
            //$outerHTML = $p->ownerDocument->saveHTML($p);
            //debuglog( "CBHtml:extractDate: " . $outerHTML );
            $parts = explode( "T", $p->getAttribute( 'content' ) );
            $this->date = $parts[0];
        }
        debuglog( "CBHtml:extractDate: " . $this->date );
        debugPrint( "CBHtml:extractDate: " . $this->date );
        return $this->date;
    }
    
    public function getPageList() {
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        debugPrint( "CBHtml::getPageList dom: " . $dom->childElementCount . "\n" );
        //$html = $dom->saveHTML();
        //echo( "CBHtml::getPageList dom HTMLS: " . "$html\n" );
        $xpath = new DOMXpath($dom);
        $query = '//div[contains(@class, "archive")]/p/a';
        $paragraphs = $xpath->query( $query );
        $pages = [];

        foreach( $paragraphs as $p ) {
            $pp = $p->parentNode->parentNode;
            //$outerHTML = $pp->ownerDocument->saveHTML($pp);
            $url = $p->getAttribute( 'href' );
            debugPrint( "CBHtml::getPageList checking: $url" );
            $pagedom = $this->getDom( $url );
            $date = $this->extractDate( $pagedom );
            $sourcename = $this->sourceName . ": " . $date;
            $text = trim( $this->prepareRaw( $p->nodeValue ) );
            $pages[] = [
                $sourcename => [
                    'url' => $url,
                    'image' => '',
                    'title' => $text,
                    //'html' => $outerHTML,
                ]
            ];  
        }

        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public $groupname = "keaolama";
    private $urlbase = 'https://keaolama.org/';
    public function __construct( $options = [] ) {
        $this->urlBase = $this->urlbase;
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://keaolama.org/?infinity=scrolling&page=";
    }

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
    
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        return $paragraphs;
    }
    
    public function extractDate( $dom ) {
        debugPrint( "AolamaHTML::extractDate url=" . $this->url );
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $dateparts = explode( '-', $parts[4] );
            $this->date = $parts[1] . "-" . $dateparts[0] . "-" . $dateparts[1];
        }
        debugPrint( "AolamaHTML::extractDate " . $this->date );
        return $this->date;
    }
    
    public function getPageList() {
        debugPrint( "AolamanHTML::getPageList()" );
        $page = 0;
        $pages = [];
        while( true ) {
            $contents = file_get_contents( $this->baseurl . $page );
            $response = json_decode( $contents );
            if( $response->type != 'success' ) {
                break;
            }
            $urls = array_keys( (array)$response->postflair );
            foreach( $urls as $u ) {
                $value = [];
                $text = str_replace( $this->urlbase, '', $u );
                $parts = explode( '/', $text );
                //echo( var_export( $parts, true ) . "\n" );
                if( sizeof($parts) > 3 ) {
                    $dateparts = explode( '-', $parts[3] );
                    $text = '20' . $parts[2] . "-" . $dateparts[0] . "-" . $dateparts[1];
                }
                $text = $this->basename . ": $text";
                $pages[] = [
                    $text => [
                        'url' => $u,
                        'image' => '',
                        'title' => $text,
                    ]
                ];  
            }
            $page++;
        }
        return $pages;
    }
}

class KauakukalahaleHTML extends HtmlParse {
    private $basename = "Kauakukalahale";
    public $groupname = "kauakukalahale";
    private $domain = "https://www.staradvertiser.com/";
    private $sourceName = 'Kauakukalahale';
    public function __construct( $options = [] ) {
        $this->endMarkers = [
            "This column",
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://www.staradvertiser.com/category/editorial/kauakukalahale/";
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "KauakukalahaleHTML::getSourceName($title,$url)" );
        $name = $this->sourceName;
        debuglog( "KauakukalahaleHTML::getSourceName: url = " . $this->url . ", date = " . $this->date );
        debugPrint( "KauakukalahaleHTML::getSourceName: url = " . $this->url . ", date = " . $this->date );
        if( $url ) {
            $this->url = $url;
            $date = str_replace( $this->domain, "", $url );
            $this->date = substr( $date, 0, 10 );
        }
        if( $this->date ) {
            $name .= " " . $this->date;
        }
        return $name;
    }

    protected function cleanup( $text ) {
        debugPrint( "KauakukalahaleHtml::cleanup()" );
        //debugPrint( "KauakukalahaleHtml::cleanup()\n$text" );
        $pairs = array(
            '&mdash;' => '',
        );
        $text = strtr($text, $pairs);
        return $text;
    }

    public function updateVisibility( $text ) {
        $nChars = strlen($text);
        debugPrint( "KauakalahaleParse::updateVisibility($nChars characters)" );
        if( !$text ) {
            return $text;
        }
        //debugPrint( "KauakalahaleParse::updateVisibility() received\n$text" );
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        $dom->loadHTML( $text );
        $xpath = new DOMXpath($dom);
        $changed = 0;
        $p = $xpath->query( '//div[contains(@id, "hsa-paywall-content")]' );
        foreach( $p as $element ) {
            $element->setAttribute( 'style', 'display:block' );
            $changed++;
        }
        $p = $xpath->query( '//div[contains(@class, "paywall-subscribe")]' );
        foreach( $p as $element ) {
            $element->setAttribute( 'style', 'display:none' );
            $changed++;
        }
        if( $changed ) {
            $text = $dom->saveHTML();
        }
        debuglog( "KauakalahaleParse::updateVisibility($nChars characters); changed $changed tags" );
        //debugPrint( "KauakalahaleParse::updateVisibility() returning\n$text" );
        return $text;
    }
    
    public function extractDate( $dom ) {
        debugPrint( "KauakukalahaleHTML::extractDate()" );
        $this->date = '';
        $xpath = new DOMXpath( $dom );
        $query = "//li[contains(@class, 'postdate')]";
        $paragraphs = $xpath->query( $query );
        if( $paragraphs->length > 0 ) {
            $p = $paragraphs->item(0);
            $date = trim( $p->nodeValue );
            $this->date = date("Y-m-d", strtotime($date));
        }
        debuglog( "KauakukalahaleHTML:extractDate: " . $this->date );
        debugPrint( "KauakukalahaleHTML:extractDate: " . $this->date );
        return $this->date;
    }
    
    public function extractAuthor( $dom ) {
        debugPrint( "KauakukalahaleHTML::extractAuthor()" );
        $author = '';
        $xpath = new DOMXpath( $dom );
        $query = '//article[contains(@id, "article-container")]';
        $query = '//article';
        $articles = $xpath->query( $query );
        if( $articles->length > 0 ) {
            $article = $articles->item( 0 );
            $lis = $xpath->query( "*/li[contains(@class, 'custom_byline')]", $article );
            $lis = $xpath->query( "//li[contains(@class, 'custom_byline')]", $article );
            //$lis = $xpath->query( "//li", $article );
            if( $lis->length > 0 ) {
                $li = $lis->item( 0 );
                $author = trim( $li->nodeValue );
                $author = preg_replace( '/(By na |By |Na )/', '', $author );
            } else {
                /*
                echo "No custom_byline\n";
                $outerHTML = $article->ownerDocument->saveHTML($article);
                echo "outerHTML: $outerHTML\n";
                */
            }
        } else {
            //echo "No articles\n";
        }
        return $author;
    }
    
    public function getContents( $url, $options=[] ) {
        $text = parent::getContents( $url, $options );
        $text = $this->updateVisibility( $text );
        $nchars = strlen( $text );
        debuglog( "KauakukahaleHTML::getContents after updateVisibility - $nchars characters()" );
        return $text;
    }

    public function getPageList() {
        debugPrint( "KauakukalahaleHtml::getPageList()" );
        $pagenr = 1;
        $pages = [];
        while( ($morepages = $this->getSomePages( $pagenr )) && (sizeof($morepages) > 0) ) {
            $pages = array_merge( $pages, $morepages );
            $pagenr++;
        }
        return $pages;
    }
    
    public function getSomePages( $pagenr ) {
        if( $pagenr == 1 ) {
            $url = $this->baseurl;
        } else {
            $url = $this->baseurl . 'page/' . $pagenr;
        }
        debugPrint( "KauakukalahaleHtml::getSomePages($pagenr): $url" );
        $dom = $this->getDOM( $url );
        $xpath = new DOMXpath($dom);
        
        $query = '//article[contains(@class, "story")]';
        $articles = $xpath->query( $query );

        foreach( $articles as $article ) {
            //$outerHTML = $article->ownerDocument->saveHTML($article);
            //echo "outerHTML: $outerHTML\n";
            $paragraphs = $xpath->query( "div/a", $article );
            if( $paragraphs->length > 0 ) {
                $p = $paragraphs->item( 0 );
                //$outerHTML = $p->ownerDocument->saveHTML($p);
                //echo "outerHTML: $outerHTML\n";
                $url = $p->getAttribute( 'href' );
                $title = $p->getAttribute( 'title' );
                $title = $this->basename . ": " . str_replace( "Column: ", "", $title );
                $date = str_replace( $this->domain, "", $url );
                $date = substr( $date, 0, 10 );
                $sourcename = $this->basename . ": " . $date;
                $childNodes = $p->getElementsByTagName( 'img' );
                if( $childNodes->length > 0 ) {
                    $img = $childNodes->item( 0 )->getAttribute( 'data-src' );
                }
                $lis = $xpath->query( "*/li[contains(@class, 'custom_byline')]", $article );
                $author = '';
                if( $lis->length > 0 ) {
                    $li = $lis->item( 0 );
                    $author = trim( $li->nodeValue );
                    $author = preg_replace( '/(By na |By |Na )/', '', $author );
                }
                $pages[] = [
                    $sourcename => [
                        'url' => $url,
                        'title' => $title,
                        'date' => $date,
                        'image' => $img,
                        'authors' => $author,
                    ]
                ];
            }
        }
        //echo "Pages found: " . sizeof($pages) . "\n";
        return $pages;
    }

    public function extract( $dom ) {
        debuglog( "KauakukalahaleHTML::extract({$dom->childElementCount} nodes)" );
        debugPrint( "KauakukalahaleHTML::extract({$dom->childElementCount} nodes)" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "hsa-paywall")]//p' );
        return $paragraphs;
    }
    
    public function checkElement( $p ) {
        $text = trim( strip_tags( $p->nodeValue ) );
        //debugPrint( "KauakukalahaleHTML::checkElement $text" );
        if( preg_match( "/^Synopsis:/", $text ) ) {
            return '';
        }
        return parent::checkElement( $p );
    }
    
    public function process( $contents ) {
        debugPrint( "KauakukalahaleHTML::process({$contents->length} nodes)" );
        $rawResults = "";
        foreach( $contents as $p ) {
            if( $text = $this->checkElement( $p ) ) {
                $text = $this->prepareRaw( $text );
                // Skip anything at the end starting with the glossary
                if( preg_match( "/^(E hoouna ia mai na|E ho‘ouna ‘ia mai na ā leka)/", $text ) ) {
                    break;
                } else {
                    $rawResults .= "\n" . $text;
                }
            }
        }
        //echo "CBHtml::process after prepareRaw: " . $rawResults . "\n";
        $results = $this->processText( $rawResults );
        return $results;
    }
}

class NupepaHTML extends HtmlParse {
    private $basename = "Nupepa";
    public $groupname = "nupepa";
    private $domain = "https://nupepa.org/";
    private $sourceName = 'Nupepa';
    public function __construct( $options = [] ) {
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://nupepa.org/gsdl2.5/cgi-bin/nupepa?e=p-0nupepa--00-0-0--010---4-----text---0-1l--1haw-Zz-1---20-about---0003-1-0000utfZz-8-00&a=d&cl=CL2";
    }
    
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $xpath = new DOMXpath( $this->dom );
        $query = '//head/title';
        $titles = $xpath->query( $query );
        $this->title = trim( $titles->item(0)->nodeValue );
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "NupepaHTML::getSourceName($title,$url)" );
        $name = $this->sourceName;
        if( $url ) {
            $this->url = $url;
            $date = str_replace( $this->domain, "", $url );
            $this->date = substr( $date, 0, 10 );
        }
        if( $title ) {
            $this->sourceName = $title;
        }
        debuglog( "NupepaHTML::getSourceName: url = " . $this->url . ", date = " . $this->date );
        debugPrint( "NupepaHTML::getSourceName: url = " . $this->url . ", date = " . $this->date );
        return $this->sourceName;
    }
    
    public function getPageList() {
        debugPrint( "NupepaHTML::getPageList()" );
        $pagenr = 1;
        $pages = [];
        $maxPageNr = 103;
        while( $pagenr <= $maxPageNr ) {
            $morepages = $this->getSomePages( $pagenr );
            if( $morepages && sizeof( $morepages ) > 0 ) {
                $pages = array_merge( $pages, $morepages );
            }
            $pagenr++;
        }
        return $pages;
    }
    
    public function getSomePages( $pagenr ) {
        if( $pagenr == 1 ) {
            $url = $this->baseurl;
        } else {
            $url = $this->baseurl . '.' . $pagenr;
        }
        debugPrint( "NupepaHTML::getSomePages($pagenr): $url" );
        $dom = $this->getDOM( $url );
        $xpath = new DOMXpath($dom);
        $query = '//node()[img/@alt="view text"]';
        $paragraphs = $xpath->query( $query );
        $pages = [];

        $months = [
            'Malaki' => '03',
            "Nowemapa" => '11',
            "Mei" => '05',
            "Iulai" => '07',
            "Apelila" => '04',
            "Pepeluali" => '02',
            "Ianuali" => '01',
            "Kekemapa" => '12',
            "Okakopa" => '10',
            "Kepakemapa" => '09',
            "Aukake" => '08',
            "Iune" => '06',
        ];
        
        foreach( $paragraphs as $p ) {
            //echo( "NupepaHTML::getPageList paragraph: " . $p->nodeValue . "\n" );
            $url = trim( $this->baseurl, '/' ) . $p->getAttribute( 'href' );
            $parentnode = $p->parentNode->parentNode; // tr
            $tds = $xpath->query( "td", $parentnode );
            //debugPrint( $tds->length . " tds, " . $tds->item(4)->nodeValue );
            $title = $tds->item(3)->nodeValue;
            // Skip the English language newspapers where possible
            if( preg_match( '/Honolulu Times/', $title ) ) {
                continue;
            }
            $date = $tds->item(4)->nodeValue;
            $date = str_replace( 'ʻ', '', $date );
            $parts = explode( " ", $date );
            $date = "${parts[2]}-${months[$parts[1]]}-${parts[0]}";
            $img = '';
            
            $pages[] = [
                $title => [
                    'url' => $url,
                    'title' => $title,
                    'date' => $date,
                    'image' => $img,
                ]
            ];
            //debugPrint( var_export( $pages, true ) );
        }

        //echo "Pages found: " . sizeof($pages) . "\n";
        return $pages;
    }
    
    public function getRawText( $url, $options=[] ) {
        $continue = (!isset($options['continue']) || $options['continue']) ? 1 : 0;
        $fulltext = "";
        $parts = explode( "/", $url );
        $baseurl = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/";
        $seen = [];
        $hasHead = false;
        while( $url ) {
            if( in_array( $url, $seen ) ) {
                break;
            }
            $seen[] = $url;
            debugPrint( "NupepaHTML::getRawText( $url,$continue)" );
            $text = $this->getRaw( $url, $options );
            $dom = $this->getDOMFromString( $text, $options );
            $xpath = new DOMXpath($dom);
            if( !$hasHead ) {
                // Only want one <html> and one <head>
                $query = '//head';
                $p = $xpath->query( $query );
                if( $p->length > 0 ) {
                    $node = $p->item(0);
                    $outerHTML = $node->ownerDocument->saveHTML($node);
                    //echo "outerHTML: $outerHTML\n";
                    $fulltext .= "<html>" . $outerHTML;
                } else {
                    echo "No head\n";
                }
                $hasHead = true;
            }
            $query = '//body';
            $p = $xpath->query( $query );
            if( $p->length > 0 ) {
                $node = $p->item(0);
                $text = $node->ownerDocument->saveHTML($node);
                //echo "Body: $text\n";
            } else {
                $text = "";
            }
            $fulltext .= $text;

            if( $continue ) {
                // Check if there is a continuation link
                $query = '//a[contains(text(), "ao a")]';
                $p = $xpath->query( $query );
                if( $p->length > 0 ) {
                    $url = $baseurl . $p->item(0)->getAttribute( 'href' );
                    debugPrint( "NupepaHTML::getRawText continuation: $url" );
                } else {
                    $url = '';
                    debugPrint( "NupepaHTML::getRawText no continuation for $query" );
                }
            } else {
                $url = '';
                $fulltext .= '</html>';
            }
        }
        return $fulltext;
    }

    public function extract( $dom ) {
        debuglog( "NupepaHTML::extract({$dom->childElementCount} nodes)" );
        debugPrint( "NupepaHTML::extract({$dom->childElementCount} nodes)" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//sec/div/p' );
        // Very inconsistent DOM for these documents
        if( $paragraphs->length < 1 ) {
            $paragraphs = $xpath->query( '//p/span' );
        }
        if( $paragraphs->length < 1 ) {
            $paragraphs = $xpath->query( '//td/div/div' );
        }
        if( $paragraphs->length < 1 ) {
            $paragraphs = $xpath->query( '//center/table/tr/td/*' );
        }
        if( $paragraphs->length < 1 ) {
            $paragraphs = $xpath->query( '//sec/div/p' );
        }
        return $paragraphs;
    }

    public function extractDate( $dom ) {
        debugPrint( "NupepaHTML::extractDate url=" . $this->url );
        $date = '';
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
        $this->date = $date;
        debugPrint( "NupepaHTML::extractDate found [{$this->date}]" );
        return $this->date;
    }
    
/*    
    public function checkElement( $p ) {
        // Can probably move this up into ParseHTML
        // &nbsp;
        $text = preg_replace( '/\xc2\xa0/', '', $p->nodeValue );
        $text = preg_replace( '/\s+/', ' ', $text );
        return $text;
    }
*/
}

class UlukauHTML extends HtmlParse {
    private $basename = "Ulukau";
    public $groupname = "ulukau";
    private $domain = "https://puke.ulukau.org";
    private $pageURL = "https://puke.ulukau.org/ulukau-books/?a=da&command=getSectionText&d=";
    private $pageURLSuffix = "&f=AJAX&e=-------en-20--1--txt-txPT-----------";
    private $pagemap;
    private $pagetitles;
    private $image = "";
    public $oid = 'EBOOK-HK2';
    public function __construct( $options = [] ) {
        //$this->mergeRows = false;
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://puke.ulukau.org/ulukau-books/?a=p&p=bookbrowser&e=-------en-20--1--txt-txPT-----------";
        // There are some irrelevant phrases among the sentences
        $this->toSkip = [
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
    }

    public function getPageTitles() {
        return $this->pagetitles;
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        return ($title)?:$this->title;
    }
    
    public function extract( $dom ) {
        debugPrint( "UlukauHTML::extract(" . $dom->childElementCount . " nodes)" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//p' );
        if( $paragraphs->count() < 1 ) {
            $paragraphs = $xpath->query( '//div' );
        }
        return $paragraphs;
    }
    
    public function extractDate( $dom ) {
        debugPrint( "UlukauHTML::extractDate url=" . $this->url );
        $this->date = '';
        $xpath = new DOMXpath($dom);
        $query = '//div[@class="content"]';
        $items = $xpath->query( $query );
        foreach( $items as $item ) {
            $text = $item->nodeValue;
            if( preg_match( '/Copyright/', $text ) ) {
                //echo "Raw: $text\n";
                $text = preg_replace( '/Copyright ©/', '', $text );
                $text = preg_match( '/([0-9]{4})/', $text, $matches );
                if( sizeof( $matches ) > 0 ) {
                    $this->date = $matches[0] . "-01-01";
                    break;
                }
            }
        }
        debugPrint( "UlukauHTML::extractDate found [{$this->date}]" );
        return $this->date;
    }
    
    public function getPageList() {
        debugPrint( "UlukauHTML::getPageList()" );
        $pages = [];
        $dom = $this->getDOM( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $docdivs = $xpath->query( '//div[contains(@data-language,"haw")]' );
        foreach( $docdivs as $docdiv ) {
            $author = $docdiv->getAttribute( "data-author" );
            $title = $docdiv->getAttribute( "data-title" );
            $title = $this->convertEncoding( $title );

            $subject = $docdiv->getAttribute( "data-subject" );
            $language = $docdiv->getAttribute( "data-language" );
            echo "author=$author, title=$title, subject=$subject, language=$language\n";

            $count = $docdiv->childNodes->length;
            $leftdiv = $docdiv->childNodes->item(1);
            $otherdivs = $xpath->query( 'a/img', $leftdiv );
            if( $otherdivs->length > 0 ) {
                $this->image = $this->domain . $otherdivs->item(0)->getAttribute( 'data-src' );
            }
            echo "image: $this->image\n";
            $anchors = $xpath->query( 'div/div[contains(@class, "tt")]/b/a', $docdiv );
            if( $anchors->count() < 1 ) {
            echo "No anchors under tt/b/a\n";
                continue;
            }
            $a = $anchors->item(0);

            $u = $a->getAttribute( "href" );
            $text = $title;
            if( $author ) {
                $text .= ", $author";
            }
            $text = $this->basename . ": " . $text; //$this->preprocessHTML( $text );
            $pages[] = [
                $text => [
                    'url' => $this->domain . $u,
                    'image' => $this->image,
                    'title' => $text,
                    'language' => $language,
                    'author' => $author,
                    'subject' => $subject,
                ]
            ];
        }
        ksort( $pages );
        debuglog( "Pages: " . var_export( $pages, true ) );
        debugPrint( "Pages: " . var_export( $pages, true ) );
        return $pages;
    }


    public function getDOM( $url, $options = [] ) {
        debugPrint( "UlukauParse::getDOM($url)" );
        return parent::getDOM( $url, ['preprocess' => false,] );
    }


    public function initialize( $baseurl ) {
        debugPrint( "UlukauHTML::initialize($baseurl)" );
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $baseurl );
        $xpath = new DOMXpath( $this->dom );
        $query = '//head/title';
        //$query = '//h2[contains(@class, "headline")]/a';
        $titles = $xpath->query( $query );
        $this->title = $this->preprocessHTML( trim( $titles->item(0)->nodeValue ) );
        $pattern = '/\bdocumentOID\s*=\s*(.*?);/s';
        if (preg_match($pattern, $contents, $matches)) {
            $this->oid = trim( $matches[1], "'" );
        }
        
        $query = '//div[text()="Author(s):"]';
        $p = $xpath->query( $query );
        if( $p->length > 0 ) {
            $element = $p->item(0);
            $next = $element->nextSibling;
            if( $next ) {
                $this->authors = $next->nodeValue;
            }
        }

        $contents = $this->dom->saveHTML();
        $pattern = '/\bpageToArticleMap\s*=\s*(.*?);/s';
        if (preg_match($pattern, $contents, $matches)) {
            $pagemap = $matches[1];
            $pagemap = str_replace( "'", '"', $pagemap );
            $this->pagemap = json_decode( $pagemap, true );
        }

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

        debugPrint( "UlukauHTML::initialize() finished " /*. var_export( $this->pagemap, true )*/ );
    }

    private function liveIntro() {
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

    private function liveClose() {
?>
    <script>
     clearInterval( repeatScroll );
    </script>
        </div>
<?php
    }

    public function getRawText( $url, $options=[] ) {
        debugPrint( "UlukauHTML::getRawText($url)" );
        $this->initialize( $url );
        $showRaw = $this->options['boxContent'] ?: false;
        $showLive = $this->options['synchronousOutput'] ?: $showRaw;
        if( $showRaw ) {
            $this->liveIntro();
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
                    debuglog( "UlukauHtml getRawText fetching: '" .
                              $title . "' url: $url" );
                    debugPrint( "UlukauHtml getRawText fetching: '" .
                                $title . "' url: $url" );
                    $dom = $this->getDOM( $url, $options );
                    //$htmltext .= $dom->saveHTML();
                    //echo "$html\n";
                    $xpath = new DOMXpath( $dom );
                    $contents = $xpath->query( '//sectiontext' );
                    //$contents = $xpath->query( 'div' );
                    foreach( $contents as $p ) {
                        $text = trim( preg_replace( "/\n+/", "\n", $p->nodeValue ) );
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
            $this->liveClose()  ;
        }
        debugPrint( "UlukauHTML::getRawText() finished" );
        return $fulltext;
    }

    // Not currently used
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
        return $fulltext;
    }

    // Not currently used
    public function adjustDom( $olddom ) {
        debugPrint( "UlukauHTML::adjustDom(" . $olddom->childElementCount . " nodes)" );
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
}

class KaPaaMooleloHTML extends HtmlParse {
    private $basename = "Ka Paa Moolelo";
    public $groupname = "kapaamoolelo";
    protected $baseurls = [
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_kaao.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_moolelo.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/kaao_unuhiia.htm",
    ];
    private $domain = "https://www2.hawaii.edu/~kroddy/";
    public function __construct( $options = [] ) {
        $this->endMarkers = [
            "HOPENA",
        ];
        $this->urlBase = trim( $this->domain, "/" );
    }
    
    private function validLink( $url ) {
        $result = strpos( $url, "file:" ) === false &&
                  strpos( $url, "mailto:" ) === false &&
                  strpos( $url, "papa_kuhikuhi" ) === false &&
                  strpos( implode( "", $this->baseurls ), $url ) === false;
        return $result;
    }
    
    public function getOnePageList( $url ) {
        $pages = [];
        $dom = $this->getDOM( $url, ['continue'=>false] );
        $xpath = new DOMXpath($dom);
        $query = '//p/a|//p/font/a';
        $paragraphs = $xpath->query( $query );
        $pages = [];

        foreach( $paragraphs as $p ) {
            $url = $p->getAttribute( 'href' );
            if( $this->validLink( $url ) ) {
                $text = preg_replace( "/\n|\r/", " ", $p->firstChild->nodeValue );
                $text = $this->basename . ": " . preg_replace( "/\s+/", " ", $text );
                $pages[] = [
                    $text => [
                        'url' => $this->domain . "moolelo/" . $url,
                        'title' => $text,
                        'image' => '',
                    ],
                ];
            }
        }
        return $pages;
    }

    public function getPageList() {
        $pages = [];
        foreach( $this->baseurls as $baseurl ) {
            $more = $this->getOnePageList( $baseurl );
            $pages = array_merge( $pages, $more );
        }
        return $pages;
    }

    public function extract( $dom ) {
        debuglog( "KaPaaMooleloHTML::extract({$dom->childElementCount} nodes)" );
        debugPrint( "KaPaaMooleloHTML::extract({$dom->childElementCount} nodes)" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//p/font|//dt/font|//sec/div/p' );
        return $paragraphs;
    }

    protected function nextUrl( $baseurl, $dom, $seen ) {
        debugPrint( "KaPaaMooleloHTML::nextUrl($baseurl,{$dom->childElementCount} nodes," . sizeof($seen) . "seen)" );
        $url = '';
        $xpath = new DOMXpath($dom);
        $query = '//a';
        $ps = $xpath->query( $query );
        foreach( $ps as $p ) {
            $url = $baseurl . $p->getAttribute( 'href' );
            if( in_array( $url, $seen ) ) {
                continue;
            }
            debugPrint( "KaPaaMooleloHTML::nextUrl continuation: $url" );
            return $url;
        }
        //debugPrint( "KaPaaMooleloHTML::nextUrl no continuation for $query\n$text" );
        return $url;
    }
    
    public function checkElement( $p ) {
        $text = trim( strip_tags( $p->nodeValue ) );
        //debugPrint( "KaPaaMooleloHTML::checkElement |$text|" );
        if( preg_match( "/\[.*Mokuna/", $text ) ) {
            return '';
        }
        $pattern = "pai hou ia:";
        if( strstr( $text, $pattern ) ) {
            //debugPrint( "Returning a hit on |$pattern|" );
            return '';
        }
        return parent::checkElement( $p );
    }

    protected function cleanup( $text ) {
        $nchars = strlen( $text );
        debugPrint( "KaPaaMooleloHTML::cleanup() $nchars" );
        //debugPrint( "KaPaaMooleloHTML::cleanup before:\n$text" );
        $entities = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
        //debugPrint( "KaPaaMooleloHTML::cleanup encoded:\n$entities" );
        $pairs = array(
            '\xad' => '',
            '\x5f' => '',
            '&#4294967295;' => '',
        );
        $text = strtr($entities, $pairs);
        $text = html_entity_decode( $text );
        //debugPrint( "KaPaaMooleloHTML::after:\n$text" );
        //debugPrint( "KaPaaMooleloHTML::cleanup() returning $nchars" );
        return $text;
    }

    public function getRawText( $url, $options=[] ) {
        $continue = (!isset($options['continue']) || $options['continue']) ? 1 : 0;
        $fulltext = "";
        $parts = explode( "/", $url );
        $baseurl = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/";
        $seen = [];
        while( $url ) {
            /*
               if( in_array( $url, $seen ) ) {
               break;
               }
             */
            $seen[] = $url;
            $text = parent::getRaw( $url, $options );
            $nchars = strlen( $text );
            debugPrint( "KaPaaMooleloHTML::getRawText( $url,$continue) read $nchars" );
            $fulltext .= $text;

            $url = '';
            if( $continue && !preg_match( '/papa_kaao|papa_moolelo/', $url ) ) {
                // Check if there is a continuation link
                $dom = $this->getDOMFromString( $text, $options );
                $url = $this->nextUrl( $baseurl, $dom, $seen );
            }
        }
        return $fulltext;
    }

    protected function nextPageUrl( $dom ) {
        $xpath = new DOMXpath($dom);
        $p = $xpath->query( '//p/font/i//a|//p/font//a|//p/a' );
        $url = '';
        if( $p->length > 0 ) {
            $url = $p->item(0)->getAttribute( 'href' );
            $parts = explode( "/", $this->url );
            $url = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/$url";
            debugPrint( "KaPaaMooleloHTML::extract continuation: $url" );
        }
        return $url;
    }
}

class BaibalaHTML extends HtmlParse {
    private $basename = "Baibala";
    public $groupname = "baibala";
    private $documentname = "Baibala (full bible, 2012 edition)";
    private $domain = "https://baibala.org/";
    protected $startMarker = "Baibala (full bible)";
    public function __construct( $options = [] ) {
        $this->endMarkers = [
        ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl =
        "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-";
    }
    
    private function validLink( $url ) {
        $result = strpos( $url, "file:" ) === false &&
                  strpos( $url, "mailto:" ) === false &&
                  strpos( $url, "papa_kuhikuhi" ) === false &&
                  strpos( implode( "", $this->baseurls ), $url ) === false;
        return $result;
    }
    
    public function getPageList() {
        $pages = [];
        $text = $this->documentname;
        // It's a single document
        $pages[] = [
            $text => [
                'url' => $this->baseurl,
                'title' => $text,
                'image' => '',
            ],
        ];
        return $pages;
    }

    public function extract( $dom ) {
        debuglog( "BaibalaHTML::extract({$dom->childElementCount} nodes)" );
        debugPrint( "BaibalaHTML::extract({$dom->childElementCount} nodes)" );

        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//td' );
        return $paragraphs;
    }

    public function getRawText( $url, $options=[] ) {
        $text = $this->getRaw( $url, $options );
        $nchars = strlen( $text );
        $continue = (!isset($options['continue']) || $options['continue']) ? 1 : 0;

        $fulltext = "";
        $parts = explode( "/", $url );
        $baseurl = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/";
        $seen = [];
        while( $url ) {
            if( in_array( $url, $seen ) ) {
                break;
            }
            $seen[] = $url;
            $text = parent::getRaw( $url, $options );
            $nchars = strlen( $text );
            debugPrint( "BaibalaHTML::getRawText( $url,$continue) read $nchars" );
            $fulltext .= $text;

            $url = '';
            if( $continue ) {
                // Check if there is a continuation link
                $dom = $this->getDOMFromString( $text, $options );
                $url = $this->nextUrl( $dom, $seen );
            }
        }
 
        return $fulltext;

    }

    protected function nextUrl( $dom, $seen ) {
        debugPrint( "BaibalaHTML::nextUrl" );
        $xpath = new DOMXpath($dom);
        $p = $xpath->query( "//a[contains(@target, 'top')]" );
        $url = '';
        foreach( $p as $element ) {
            $url = $element->getAttribute( 'href' );
            $url = $this->domain . $url;
            $html = $element->ownerDocument->saveHTML($element->firstChild);
            //debugPrint( "BaibalaHTML::nextUrl first level frameset ($html): $url" );
            if( strpos( $html, "next" ) != 0 ) {
                debugPrint( "BaibalaHTML::nextUrl skipping" );
                continue;
            }
            $dom = $this->getDom( $url );
            $xpath = new DOMXpath($dom);
            $p = $xpath->query( "//frame[contains(@name, 'main')]" );
            if( $p->length > 0 ) {
                $url = $p->item(0)->getAttribute( 'src' );
                $url = $this->domain . $url;
                //debugPrint( "BaibalaHTML::nextUrl second level frameset: $url" );
                $dom = $this->getDom( $url );
                $xpath = new DOMXpath($dom);
                $p = $xpath->query( "//frame[contains(@name, 'main')]" );
                if( $p->length > 0 ) {
                    $url = $p->item(0)->getAttribute( 'src' );
                    $url = $this->domain . $url;
                    debugPrint( "\nBaibalaHTML::nextUrl continuation: $url\n" );
                }
            }
        }
        if( !$url ) {
            debugPrint( "\nBaibalaHTML::nextUrl did not find next iframe\n" );
        }
        if( in_array( $url, $seen ) ) {
            debugPrint( "BaibalaHTML::nextUrl already did this one" );
            $url = "";
        }
        return $url;
    }
}

class EhoouluLahuiHTML extends HtmlParse {
    private $basename = "Ehooulu Lahui";
    public $groupname = "ehooululahui";
    private $urlbase = 'https://ehooululahui.maui.hawaii.edu/';
    public function __construct( $options = [] ) {
        $this->urlBase = $this->urlbase;
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://ehooululahui.maui.hawaii.edu/?page_id=354";
        $this->badTags = [
            "//noscript",
            "//script",
            "//header",
        ];
        $this->badIDs = [
            'bottom',
            'header',
        ];
        $this->badClasses = [
            'error g-box-full g-background-default g-shadow-inset',
            'g-shadow-inset',
        ];
    }

    public function getSourceName( $title = '', $url = '' ) {
        if( $title ) {
            $this->title = $title;
        }
        if( $this->title ) {
            return $this->basename . ' / ' . $this->title;
        }
        return $this->basename;
    }
    
    public function extract( $dom ) {
        debugPrint( "EhoouluLahuiHTML::extract {$dom->childElementCount} nodes" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "cmsmasters_text")]/p' );
        //$paragraphs = $xpath->query( '//p' );
        debugPrint( "EhoouluLahuiHTML::extract found {$paragraphs->length} nodes" );
        return $paragraphs;
    }
    
    public function extractDate( $dom ) {
        debugPrint( "EhoouluLahuiHTML::extractDate url=" . $this->url );
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $dateparts = explode( '-', $parts[4] );
            $this->date = $parts[1] . "-" . $dateparts[0] . "-" . $dateparts[1];
        }
        debugPrint( "EhoouluLahuiHTML::extractDate found [{$this->date}]" );
        return $this->date;
    }
    
    public function getPageList() {
        debugPrint( "EhoouluLahuiHTML::getPageList()" );
        // Only a few of the documents there are really interesting and it is difficult to
        // determine automatically, so just list them here.
        return [
            [
            '‘Aukelenuia‘īkū' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=65',
                    'image' => '',
                    'title' => '‘Aukelenuia‘īkū',
                ],
            ],
            [
            'Lonoikamakahiki' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=67',
                    'image' => '',
                    'title' => 'Lonoikamakahiki',
                ],
            ],
            [
            'Punia' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=69',
                    'image' => '',
                    'title' => 'Punia',
                ],
            ],
            [
            '‘Umi' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=71',
                    'image' => '',
                    'title' => '‘Umi',
                ],
            ],
            /*
            [
            'Kalapana' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=1327',
                    'image' => '',
                    'title' => 'Kalapana',
                ],
            ],
            */
        ];
        
        $desired = [
	        '‘Aukelenuia‘īkū',
	        'Lonoikamakahiki',
	        'Punia',
	        '‘Umi',
	        'Kalapana',
        ];
        $pages = [];
        $page = 0;
        $dom = $this->getDom( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $p = $xpath->query( "//div[contains(@class, 'cmsmasters_1212')]/*/a[contains(@href, 'https://ehooululahui.maui.hawaii.edu/')]" );
        $p = $xpath->query( "//a[contains(@href, 'https://ehooululahui.maui.hawaii.edu/')]" );
        //$p = $xpath->query( '//a' );
        foreach( $p as $a ) {
            $url = $a->getAttribute( 'href' );
            $title = trim( $a->parentNode->nodeValue );
            if( !in_array( $title, $desired ) ) {
                echo "Skipping |$title|\n";
                continue;
            }
            $pages[] = [
                $title => [
                    'url' => $url,
                    'image' => '',
                    'title' => $title,
                ]
            ];
            $page++;
        }
        return $pages;
    }
    public function getRawText( $url, $options=[] ) {
        $dirtext = $this->getRaw( $url, $options );
        $nchars = strlen( $dirtext );
        debugPrint( "EhoouluLahuiHTML::getRawText($url) $nchars characters" );
        
        $dirdom = $this->getDOMFromString( $dirtext, $options );
        //echo "dirdom: " . var_export( $dirdom, true ) . " nodecount: {$dirdom->childElementCount}\n";

        $hasHead = false;
        $fulltext = "";
        $xpath = new DOMXpath($dirdom);
        //$p = $xpath->query( "//a[contains(text(), 'Mokuna ʻEkahi'))]" );
        $p = $xpath->query( '//a' );
        foreach( $p as $a ) {
            $url = $a->getAttribute( 'href' );
            if( preg_match( '/^http/', $url ) ) {
                $title = trim( $a->parentNode->nodeValue );
                if( preg_match( '/^Mokuna /', $title ) ) {

                    $text = $this->getRaw( $url, $options );
                    $dom = $this->getDOMFromString( $text, $options );
                    $xpath = new DOMXpath($dom);
                    if( !$hasHead ) {
                        // Only want one <html> and one <head>
                        $query = '//head';
                        $nodes = $xpath->query( $query );
                        if( $nodes->length > 0 ) {
                            $node = $nodes->item(0);
                            $outerHTML = $node->ownerDocument->saveHTML($node);
                            //echo "outerHTML: $outerHTML\n";
                            $fulltext .= "<html>" . $outerHTML;
                        } else {
                           // echo "No head\n";
                        }
                        $hasHead = true;
                    }

                    $query = '//body';
                    $nodes = $xpath->query( $query );
                    if( $nodes->length > 0 ) {
                        $node = $nodes->item(0);
                        $text = $node->ownerDocument->saveHTML($node);
                        //echo "Body: $text\n";
                    } else {
                        $text = "";
                    }
                    
                    //echo "url: $url\ntext: $title\n";
                    //$fulltext .= $this->getRaw( $url, $options );
                    $fulltext .= $text;

                }
            }
        }
        return $fulltext;
    }
}

class TextParse extends HtmlParse {
    public function getSentences( $text ) {
        return $this->processText( $text );
    }
}
?>

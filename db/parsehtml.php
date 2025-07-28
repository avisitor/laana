<?php
include_once __DIR__ . '/../db/funcs.php';

require __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$debugFlag = false;
function setDebug( $debug ) {
    global $debugFlag;
    $debugFlag = $debug;
}
class HtmlParse {
    public $logName = "HtmlParse";
    protected $funcName = "";
    protected $Amarker = '&#256;';
    protected $placeholder = '[[DOT]]';
    public $date = '';
    protected $url = '';
    protected $urlBase = '';
    public $baseurl = '';
    protected $html = '';
    protected $dom;
    public $title = "";
    public $authors = "";
    public $discarded = [];
    protected $minWords = 1;
    protected $mergeRows = true;
    protected $options = [];
    protected $semanticSplit = false; // Whether to use semantic splitting
    public $groupname = "";
    protected $ignoreMarkers = [];
    // Stop collecting text when one of these is encountered
    protected $endMarkers = [
        "applesauce",
        "O KA HOOMAOPOPO ANA I NA KII",
        "TRANSLATION OF INTRODUCTION",
        "After we pray",
        "Look up",
        "Share this",
    ];
    // Skip everything before this
    protected $startMarker = "";
    // Skip any sentence containing one of these
    protected $toSkip = [
        "About Us",
        "Our Partners",
        "Terms of Use",
        "Privacy",
        "Contact Us",
        "Skip to main content",
        "Explore Ulukau",
        "Menu",
        "Help",
        "English currently selected",
        "Hawai‘i",
        "Hawai\u2018i",
        "Article titles: ",
        "Article translations: some articles have been translated, this is the text of those translations.",
        "Dedication text: This text was added by people who transcribed Page text as a dedication for their transcription work.",
        "This text is divided into pages and is available for",
        "Is printed and Published",
        "THE NATIONAL HERALD",
        "This text is available",
        "All Orders Promptly Attended to",
        "Select an option below",
        "Already a Honolulu",
        "Log in now",
        "Get unlimited access",
        "Subscribe Now",
        "All rights reserved",
        "The developer is preparing the property",
        "demolition process",
        "Stay in touch with ",
        "It's FREE!",
        "Don't miss out on what's happening!",
        "This form is protected by reCAPTCHA.",
        "next book",
        "cursor:",
        "font-size:",
        "Look up any word",
        "Copyright",
        "Acknowledgments",
        "More Information",
        "text-align:",
        "margin-bottom:",
        "Vote Hawaii's Best",
    ];
    // Skip a sentence consisting entirely of one of these
    protected $skipMatches = [
        "OLELO HOOLAHA.",
    ];
    protected $badTags = [
        "//noscript",
        "//script"
    ];
    protected $badIDs = [];
    protected $badClasses = [];
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
    // Add a static abbreviation list for sentence splitting
    protected static $abbreviations = [
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'DR.', 'Prof.', 'Sr.', 'Jr.',
        'St.', 'Mt.', 'Ave.', 'H.K.Aloha', 'Lt.', 'Col.', 'Gen.',
        'Capt.', 'Sgt.', 'Rev.', 'Hon.', 'Pres.', 'Gov.', 'Sen.',
        'Rep.', 'Adm.', 'Maj.', 'Ph.D.', 'M.D.', 'D.D.S.', 'B.A.',
        'M.A.', 'D.C.', 'U.S.', 'A.M.', 'P.M.', 'Inc.', 'Ltd.',
        'Co.', 'No.', 'Dept.', 'Univ.', 'etc.', 'Vol.', 'Wm.',
        'Robt.', 'Geo.', 'GEO.', 'JNO.',
    ];
    // Add a configurable max sentence length
    const MAX_SENTENCE_LENGTH = 1000;
    const RESPLIT_SENTENCE_LENGTH = 400;
    public function __construct( $options = [] ) {
        $this->options = $options;
    }
    protected function formatLog( $obj, $prefix="" ) {
        if($prefix && !is_string($prefix)) {
            $prefix = json_encode( $prefix );
        }
        if( $this->funcName ) {
            $func = $this->logName . ":" . $this->funcName;
            $prefix = ($prefix) ? "$func:$prefix" : $func;
        }
        return $prefix;
    }
    public function log( $obj, $prefix="") {
        $prefix = $this->formatLog( $obj, $prefix );
        debuglog( $obj, $prefix );
    }
    public function debugPrint( $obj, $prefix="" ) {
        global $debugFlag;
        if( $debugFlag ) {
            $text = $this->formatLog( $obj, $prefix );
            printObject( $obj, $text );
        }
    }
    public function debugPrintObject( $obj, $intro ) {
        global $debugFlag;
        if( $debugFlag ) {
            printObject( $obj, $intro );
        }
    }
    public function print( $obj, $prefix="" ) {
        $this->log( $obj, $prefix );
        $this->debugPrint( $obj, $prefix );
    }
    public function getSourceName( $title = '', $url = '' ) {
        $this->debugPrint( "HtmlParse::getSourceName($title,$url)" );
        return $title ?: "What's my source name?";
    }
    public function initialize( $baseurl ) {
        $this->url = $this->baseurl = $baseurl;
    }
    // This just does an HTTP fetch
    public function getRaw( $url ) {
        $this->funcName = "getRaw";
        $this->print( $url );
        $this->html = ($url) ? file_get_contents( $url ) : "";
        return $this->html;
    }
    // This is called to fetch the entire text of a document, which could have several pages
    public function getRawText( $url, ) {
        $this->funcName = "getRawText";
        $this->print( $url);
        return $this->getRaw( $url );
    }
    // This fetches the entire text of a document and does character set
    // cleanup if options['preprocess'] is not set or is true
    public function getContents( $url ) {
        $funcName = $this->funcName = "getContents";
        $this->print( $this->options, $url );
        // Get entire document, which could span multiple pages
        $text = $this->getRawText( $url );
        $nchars = strlen( $text );
        $this->debugPrint( "got $nchars characters from getRawText" );
        // Take care of any character cleanup
        if( !isset($this->options['preprocess']) || $this->options['preprocess'] ) {
            $text = $this->preprocessHTML( $text );
            //$this->debugPrint( $text );
            $nchars = strlen( $text );
            $this->debugPrint( "$nchars characters after preprocessHTML" );
        }
        return $text;
    }
    // Returns DOM from string, assuming HTML
    public function getDOMFromString( $text ) {
        $funcName = $this->funcName = "getDOMFromString";
        $nchars = strlen( $text );
        $this->print( "$nchars characters" );
        $dom = new DOMDocument();
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        if( $text ) {
            $text = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');
            $pos = strpos($text, "<!DOCTYPE ");
            if ($pos === false) {
                $text = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $text . '</body></html>';
            }
            libxml_clear_errors();
            $dom->loadHTML($text);
            // Log all XML errors?
            if( 0 ) {
                $errors = "";
                foreach (libxml_get_errors() as $error) {
                    $errors .= "; " . $error->message;
                }
                if( $errors ) {
                    $this->log( $errors, "XML errors" );
                    //$this->debugPrint( "XML errors: $errors" );
                }
            }
            libxml_clear_errors();
        }
        return $dom;
    }
    
    // Returns DOM from URL, either assuming HTML
    public function getDOM( $url ) {
        $this->funcName = "getDOM";
        $text = $this->getContents( $url );
        $this->print( strlen($text) . " characters read from $url" );
        $dom = $this->getDOMFromString( $text );
        return $dom;
    }
    
    // Read and concatenate HTML pages, with a single <head>
    public function collectSubPages( $urls ) {
        $this->funcName = "collectSubPages";
        $this->print( sizeof($urls) . " urls" );
        $hasHead = false;
        $count = 0;
        $fulltext = "";
        foreach( $urls as $url ) {
            $this->debugPrint( $url );
            $text = $this->getRaw( $url );
            $dom = $this->getDOMFromString( $text );
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
                $fulltext .= $text;
            }
            $count++;
        }
        if( $count ) {
            $fulltext .= '</html>';
        } else {
            $fulltext = "";
        }
        return $fulltext;
    }
    
    public function containsEndMarker($line) {
        foreach ($this->endMarkers as $marker) {
            if (strpos($line, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
    
    // Evaluate if a sentence should be retained
    public function checkSentence( $sentence ) {
        $this->funcName = "checkSentence";
        $this->log( strlen($sentence) . " characters" );

        // Okina sometimes is the only thing in an element from nupepa.org
        $pos = strpos($sentence, "‘");
        if ($pos !== false) {
            return true;
        } else {
            $i = $pos;
        }
        if( str_word_count($sentence) < $this->minWords ) {
            return false;
        }
        // Remove all whitespace and digits, then check if at least 2 non-digit, non-whitespace characters remain
        if (preg_match_all('/[^\d\s]/u', $sentence, $matches) < 3) {
        }
        // Exclude lines like "No. 20." (No followed only by whitespace, numbers, or punctuation)
        if (preg_match('/^No\.?[\s\d[:punct:]]*$/u', trim($sentence))) {
            return false;
        }
        // Exclude lines like "P. 20." (P followed only by whitespace, numbers, or punctuation)
        if (preg_match('/^P\.?[\s\d[:punct:]]*$/u', trim($sentence))) {
            return false;
        }
        foreach( $this->toSkip as $pattern ) {
            if( strpos( $sentence, $pattern ) !== false ) {
                return false;
            }
        }
        foreach( $this->skipMatches as $pattern ) {
            if( $sentence === $pattern ) {
                return false;
            }
        }
        return true;
    }
    
    public function cleanSentence( $sentence ) {
        $this->funcName = "cleanSentence";
        // Restore periods in abbreviations/initials and digit/period refs, and clean up linefeeds in each sentence
        $sentence = str_replace($this->placeholder, '.', $sentence);
        // Remove whitespace before punctuation marks
        $sentence = preg_replace('/(\s+)(\?|\!)/', '$2', $sentence);
        return $sentence;
    }
    
    // Converts text to a DOM and removes tags by tag name, ID, or class
    // as defined in the properties of this class, then returns the
    // cleaned HTML
    public function removeElements( $text ) {
        $this->funcName = "removeElements";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $dom = $this->getDOMFromString( $text );
        libxml_use_internal_errors(true);
        $xpath = new DOMXpath($dom);
        $changed = 0;
        foreach( $this->badTags as $filter ) {
            $this->debugPrint( "looking for tag $filter" );
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                $this->debugPrint( "found tag $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badIDs as $filter ) {
            $this->debugPrint( "looking for ID $filter" );
            $p = $xpath->query( "//div[@id='$filter']" );
            foreach( $p as $element ) {
                $this->debugPrint( "found ID $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badClasses as $filter ) {
            $query = "//div[contains(@class,'$filter')]";
            //$query = "//div[class*='$filter']";
            $this->debugPrint( "looking for $query" );
            $p = $xpath->query( $query );
            foreach( $p as $element ) {
                $this->debugPrint( "found class $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        $this->print( "removed $changed tags" );
        $text = $dom->saveHTML();
        return $text;
    }

    // Make relative URLs absolute
    public function updateLinks( $text ) {
        $this->funcName = "updateLinks";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $dom = $this->getDOMFromString( $text );
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
                    if (!($element instanceof DOMElement)) continue;
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
    
    // This is to settle issues with character encoding upfront, on
    // reading the raw HTML from the source
    public function convertEncoding( $text ) {
        $this->funcName = "convertEncoding";
        if (!is_string($text)) {
            $text = (string)$text;
        }
        $this->print( strlen($text) . " characters" );
        //$this->log( $text, "Incoming HTML" );
        if( !$text ) {
            return $text;
        }

        // Works better to convert the text to use entities with UTF-8 and then swap them out
        // Too much variability in encoding before the entity conversion

        // Note: saveHTML at the end of removeTags does entity encoding
        //$text = htmlentities($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pairs = [
            '&Auml;&#129;' => "ā",
            "&Auml;&#128;" => "Ā",
            '&Ecirc;&raquo;' => '‘',
            '&Aring;&#141;' => 'ū',
            '&Auml;&ordf;' => 'Ī',
            '&Auml;&#147;' => 'ē',
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
            "&#128;&brvbar;" => '-',
            "&#128;&#147;" => '-',
            "&#128;&#148;" => '-',
            "&#128;&#152;" => '‘',
            "&#128;&#156;" => '"',
            "&#128;&#157;" => '-',
            '&#157;&#x9D;' => '-',
            "&#128;" => "",
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
        ];
        // Convert back to UTF-8
        $text = strtr($text, $pairs);

        // Regex-based replacement table for problematic sequences and
        // normalizing white space; line feeds are preserved
        $replace = [
            '/\x80\x99/u' => ' ', // Non-breaking space
            '/\x80\x9C/u' => ' ',
            '/\x80\x9D/u' => ' ',
            '/&nbsp;/' => ' ',
            '/"/' => '', // Remove double quotes
            '/' . preg_quote($this->Amarker, '/') . '/' => 'Ā',
            '/[\x{0080}\x{00A6}\x{009C}\x{0099}]/u' => '.', // Unicode codepoints
        ];
        $raw = $text;
        $text = preg_replace(array_keys($replace), array_values($replace), $text);
        if ($text === null || $text === '') {
            echo('preg_replace failed: ' . preg_last_error() . ": $raw\n");
            return '';
        }
        // Normalize all whitespace (including non-breaking spaces) to a
        // regular space
        ////$text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        //$this->log( $text, "HTML after convert but before decode" );

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        //$this->log( $text, "HTML after convert and after  decode" );
        return $text;
    }
    
    // This is to settle issues with character encoding upfront, on
    // reading the raw HTML from the source, to remove some unused tags
    // and to complete any partial links
    public function preprocessHTML( $text ) {
        $this->funcName = "preprocessHTML";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return $text;
        }

        // Remove a few elements; this incidentally also does html entity encoding
        $text = $this->removeElements( $text );
        // Complete links
        $text = $this->updateLinks( $text );
        // Make sure the character set works
        $text = $this->convertEncoding( $text );
        return $text;
    }
    
    // Extract text from a DOM node or return '' if the element is to be
    // ignored
    public function checkElement( $p ) {
        $this->funcName = "checkElement";
        // For <br> tags, treat as a line break (empty string)
        if ($p->nodeName === 'br') {
            return '';
        }
        $text = trim(strip_tags($p->nodeValue));
        return $text;
    }
    
    // Protect abbreviations and initials by replacing their periods with
    // a placeholder
    protected function protectAbbreviations( $text ) {
        $this->funcName = "protectAbbreviations";
        $this->print( strlen($text) . " characters" );
        $placeholder = $this->placeholder;
        $abbrPattern = implode('|', array_map(function($abbr) {
            return preg_quote($abbr, '/');
        }, self::$abbreviations));
        // Replace periods in explicit abbreviations
        $text = preg_replace_callback('/\b(' . $abbrPattern . ')/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        // Replace periods in capitalized initials (e.g., E.S., J.K.L.)
        $text = preg_replace_callback('/\b([A-Z](?:\.[A-Z]){1,}\.)/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        // Protect single capital letter abbreviations (e.g., W., A., J.)
        $text = preg_replace_callback('/\b([A-Z])\.(?=\s)/', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        // Generalized: protect two- or three-letter capitalized abbreviations (e.g., Wm., Geo., Jos.)
        $text = preg_replace_callback('/\b([A-Z][a-z]{1,2})\./', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        // Protect digit/period references (e.g., '25 7-10.') by replacing the period with a placeholder
        $text = preg_replace_callback('/(\d[\d\s\-]*\.)\s*(?=\d)/', function($matches) use ($placeholder) {
            return rtrim($matches[1], '.') . $placeholder;
        }, $text);
        return $text;
    }
    
    // Break up sentences by semantics
    public function splitSentences( $paragraphs ) {
        $this->funcName = "splitSentences";
        $this->print( count($paragraphs) . " paragraphs" );
        // Combine paragraphs into a single text block
        $text = ($paragraphs && count($paragraphs) > 0) ?
                implode( "\n", $paragraphs ) : "";
        $this->debugPrint( strlen($text) . " characters" );
        if( empty( $text ) ) {
            return [];
        }
        // All character cleanup is now in preprocessHTML
        //$this->log($text, "Original text before splitting");

        // Protect abbreviations and initials by replacing their periods
        // with a placeholder
        $text = $this->protectAbbreviations($text);

        $lines = $this->splitLines($text);
        $this->print( count($lines) . " lines after preg_split" );
        //$this->log($lines, "Lines after preg_split");
        
        $results = [];
        foreach ($lines as $line) {
            $raw = "1: $line";
            if( $line && !empty($line) ) {
                $raw = "5: $line";
                // Now remove whitespace around glottal marks
                // Done at the very end instead?
                //  $line = preg_replace('/\s+([ʻ‘’\x{2018}\x{02BB}])\s+/u', '$1', $line);
            }
            if( $line && !empty($line) ) {
                $results[] = $line;
            } else {
                $this->discarded[] = $raw; // Discard empty lines
            }
        }
        $lines = $results;
        // Join orphaned ‘ or ʻ (with or without leading/trailing
        // whitespace or HTML tags) to next line,
        // then join to previous line
        $this->debugPrint( count($lines) . " lines before connectLines" );
        $lines = $this->connectLines($lines);
        // Merge lines that are likely to be part of the same sentence
        $this->debugPrint( count($lines) . " lines before mergeLines" );
        $lines = $this->mergeLines($lines);
        $this->debugPrint( count($lines) . " lines after mergeLines" );

        foreach ($lines as &$sentence) {
            $sentence = $this->cleanSentence( $sentence );
        }
        unset($sentence);
        $joined = $lines;
        $lines = [];
        foreach ($joined as $sentence) {
            if ($this->checkSentence($sentence)) {
                $lines[] = $sentence;
            } else if( !empty($sentence) ) {
                $this->discarded[] = $sentence;
            }
        }
        return $lines;
    }
    
    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters in line" );
        // Split at .!? followed by whitespace and a capital/ʻ/diacritic, but avoid splitting
        // before/after connectors or glottal lines
        // (suggestion: add negative lookahead/lookbehind for connectors/glottal)
        $pattern = '/(?<=[.?!])\s+(?=(?![‘ʻ\x{2018}\x{02BB}])[A-ZāĀĒēĪīōŌŪū])/u';
        $lines = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);
        if( count($lines) < 2 ) {
            // If we have only one line, split by comma
            $this->debugPrint( "found " . strlen($text) .
                        " characters in line after splitting by period, trying comma split" );
            $lines = preg_split('/\,+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            foreach ($lines as $index => $line) {
                $this->debugPrint( "new line $index " . strlen($line) . " characters: $line" );
            }
        }
        return $lines;
    }

    protected function mergeLines( $lines ) {
        $this->funcName = "mergeLines";
        $this->print( count($lines) . " lines" );
        $merged = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $buffer = trim($lines[$i]);
            $j = $i + 1;
            // Start merging if this line is a likely name/initial fragment
            if (
                preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', $buffer) || // e.g. "Rev.", "Kaona", "K.-, ko makou..."
                preg_match('/^[A-Z]\\.$/u', $buffer) ||                     // e.g. "J."
                preg_match('/^[A-Z]\\.-$/u', $buffer)                       // e.g. "K.-"
            ) {
                // Merge all following lines that are also likely name/initial fragments
                while (
                    $j < $n &&
                    (
                        preg_match('/^[A-Z]\\.$/u', trim($lines[$j])) ||      // single initial
                        preg_match('/^[A-Z]\\.-$/u', trim($lines[$j])) ||     // initial+hyphen
                        preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', trim($lines[$j])) || // short, capitalized
                        (mb_strlen(trim($lines[$j])) <= 30 && preg_match('/^[A-Z]/u', trim($lines[$j])))
                    )
                ) {
                    $buffer .= ' ' . trim($lines[$j]);
                    $j++;
                }
                $merged[] = $buffer;
                $i = $j;
            } else {
                $merged[] = trim($lines[$i]);
                $i++;
            }
        }
        return $merged;
    }
    
    protected function DOMToStringArray( $contents ) {
        $this->funcName = "DOMToStringArray";
        $count = count($contents);
        $this->print( "$count elements" );
        $paragraphs = [];
        for ($idx = 0; $idx < $count; $idx++) {
            $node = $contents[$idx];
            if (!($node instanceof DOMNode)) {
                $this->debugPrint("Skipping non-DOMNode at index $idx");
                continue;
            }
            $text = $this->checkElement($node);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }
    
    public function process( $contents ) {
        $this->funcName = "process";
        $count = count($contents);
        $this->print( "$count elements" );
        $finalLines = [];
        if( $count > 0 ) {
            if( !is_string( $contents[0] ) ) {
                $paragraphs = $this->DOMToStringArray($contents);
            } else {
                $paragraphs = $contents;
            }
            //$this->debugPrint( "HtmlParse::process() got $count paragraphs" );
            // How should we split the contents - semantically or by
            // HTML elements?
            if( $this->semanticSplit ) {
                $this->debugPrint("using semantic splitting");
                $lines = $this->splitSentences( $paragraphs );
            } else {
                $this->debugPrint("using HTML element splitting");
                $lines = $this->splitByElements( $paragraphs );
                // Split long lines
                foreach ($lines as $index => $line) {
                    if (strlen($line) > self::RESPLIT_SENTENCE_LENGTH) {
                        $split = $this->splitSentences([$line]);
                        array_splice($lines, $index, 1, $split);
                    }
                }
            }
            // Have to validate each sentence and remove redundant white space
            // after splitting
            foreach ($lines as $line) {
                if ($this->checkSentence($line)) {
                    // Normalize all whitespace to a single space
                    $line = preg_replace('/\s+/u', ' ', $line);
                    // Remove whitespace around apostrophes representing glottal marks
                    $line = preg_replace('/\s+([\'‘])\s+/u', '$1', $line);
                    $line = substr($line, 0, self::MAX_SENTENCE_LENGTH);
                    // Remove all linefeeds and carriage returns
                    //$line = preg_replace('/[\n\r]+/', ' ', $line);
                    if( $this->containsEndMarker( $line ) ) {
                        break;
                    }
                    $finalLines[] = $line;
                } else {
                    $this->discarded[] = $line;
                }
            }
        }
        return $finalLines;
    }
    
    // Extract all text from a list of DOM nodes
    // Split by HTML element into sentences
    public function splitByElements( $contents ) {
        $this->funcName = "splitByElements";
        $count = count($contents);
        $this->print( "$count elements" );
        $lines = [];
        $saved = '';
        for ($idx = 0; $idx < $count; $idx++) {
            $text = $contents[$idx];
            //$this->log( $text, "Processing text from DOM node");
            // Normalize all whitespace (including non-breaking spaces) to
            // a regular space
            $text = preg_replace('/\s+/u', ' ', $text);
            if (trim($text) === '') continue;
            // Remove all linefeeds and carriage returns
            $text = preg_replace('/[\n\r]+/', ' ', $text); 
            //$this->log( $text, "after removing linefeeds and carriage returns" );
            // Remove ALL spaces before ? or !
            $text = preg_replace('/\s+([?!])/', '$1', $text);
            $lines[] = $text;
        }
        // Reconnect lines that are likely to be part of the same sentence
        $lines = $this->connectLines($lines);
        return $lines;
    }
    
    // Extract an array of lines by converting the text into a DOM document and examining all DOM nodes    
    public function extractSentencesFromHTML( $text ) {
        $this->funcName = "extractSentencesFromHTML";
        $this->print( strlen($text) . " chars" );
        if( !$text ) {
            return [];
        }
        //libxml_use_internal_errors(true);
        $dom = $this->getDOMFromString( $text );
        $this->extractDate( $dom );
        //$dom = $this->adjustDOM( $dom );
        libxml_use_internal_errors(false);
        $contents = $this->extract( $dom );
        $paraCount = count($contents);
        $this->print( "paragraph count=" . $paraCount );
        //$paragraphs = $this->DOMToStringArray($contents);
        $sentences = $this->process( $contents );
        return $sentences;
    }
    
    // Extract an array of lines by looking at the text in all tags
    // from an URL    
    public function extractSentences( $url ) {
        $this->funcName = "extractSentences";
        $this->print( $url );
        $contents = $this->getContents( $url );
        $sentences = $this->extractSentencesFromHTML( $contents );
        return $sentences;
    }
    
    // Extract an array of lines by looking at the text in all tags
    // from an URL    
    public function extractSentencesFromDatabase( $sourceid ) {
        $this->funcName = "extractSentencesFromDatabase";
        $this->log( $sourceid );
        $url = "https://noiiolelo.org/api.php/source/$sourceid/html";
        $this->debugPrint( $url );
        $text = $this->getRaw( $url );
        $obj = json_decode($text, true);
        if ( !$obj ) {
            fwrite(STDERR, "No content found for sourceid $sourceid\n");
            return;
        }
        $text = $obj['html'] ?? '';
        if (empty($text)) {
            fwrite(STDERR, "No HTML content found for sourceid $sourceid\n");
            return;
        }
        // Take care of any character cleanup
        $text = $this->preprocessHTML( $text );
        //$this->log( $text, "\nafter preprocessHTML and html_entity_decode" );

        $sentences = $this->extractSentencesFromHTML( $text );
        return $sentences;
    }
    
    // Stub function to extract the date from the DOM of a document    
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->debugPrint( $this->date );
        $this->date = '1970-01-01';
        return $this->date;
    }
    
    // Get list of all documents on the Web for a particular parser type    
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->debugPrint( "" );
        return [];
    }
    
    // Default extract method for HtmlParse base class to avoid undefined
    // method errors.
    // Derived classes should override this.
    public function extract($dom) {
        $this->funcName = "extract";
        $this->print( "" );
        // Return all <p>, <br>, and <div> elements in document order
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query('//*[self::p or self::br or self::div]');
        return $paragraphs;
    }
    
    // Connect lines using connector patterns (glottals, conjunctions, etc.)
    // $lines: array of input lines
    // Returns: array of joined lines
    public function connectLines(array $lines) {
        $this->funcName = "connectLines";
        $this->print( count($lines) . " lines" );
        return $lines;
    }
}

// --- BEGIN PARSER CLASSES ---

// Class for parsing plain text
class TextParse extends HTMLParse {
    public function __construct( $options = [] ) {
        if( count($options) < 1 ) {
            $options = [
                'preprocess' => false,
            ];
        }
        $this->options = $options;
    }
    public function extractSentencesFromHTML( $text ) {
        $this->funcName = "extractSentencesFromHTML";
        $this->print( strlen($text) . " chars" );
        if( !$text ) {
            return [];
        }
        // The text is already plain, not HTML
        $sentences = $this->process( [$text] );
        return $sentences;
    }
}

class UlukauLocal extends HTMLParse {
    protected $baseDir = __DIR__ . '/../ulukau';
    public function __construct( $options = [] ) {
        $this->semanticSplit = true; // Use semantic splitting
        $this->options = $options;
        $this->endMarkers[] = "No part of";
        $fqdn = trim(shell_exec('hostname -f'));
        if( strpos( $fqdn, 'noiiolelo' ) === false ) {
            $fqdn .= "/noiiolelo";
        }
        $this->urlBase = "https://" . $fqdn . "/ulukau";
        $this->groupname = "ulukau";
    }
    protected $pageListFile = "ulukau-books.json";
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print("");
        $pageList = json_decode( file_get_contents( "$this->baseDir/$this->pageListFile" ), true );
        for( $i = 0; $i < count($pageList); $i++ ) {
            //$pageList[$i]['url'] = "$this->baseDir/{$pageList[$i]['oid']}.html";
            $pageList[$i]['url'] = "$this->urlBase/{$pageList[$i]['oid']}.html";
            $pageList[$i]['link'] = $pageList[$i]['url'];
            //$pageList[$i][$sourceName]['groupname'] = "ulukaulocal";
        }
        return $pageList;
    }
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( "" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query('//div[contains(@class, "ulukaupagetextview")]//span');
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function preprocessHTML( $text ) {
        $this->funcName = "preprocessHTML";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return "";
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s{2,}/u', ' ', $text);
        $lines = preg_split('/\R/u', $text);

        $filtered = array_filter($lines, function ($line) {
            $line = trim($line);
            if ($line === '') return false;
            if (preg_match('/^\(No text\)$/iu', $line)) return false;
            if (preg_match('/^Page \d{1,4}$/u', $line)) return false;
            if (preg_match('/^[\s()\/\'0-9;.,–—\-]+$/u', $line)) return false;
            if (preg_match('/^\s*[ivxlcdmIVXLCDM]+\s*$/u', $line)) return false;
            return true;
        });

        $text = implode("\n", $filtered);
        return $text;
    }
    public function cleanSentence( $sentence ) {
        $this->funcName = "cleanSentence";
        $sentence = parent::cleanSentence( $sentence );

        // Remove TOC entries
        $pattern = '/\.{5,}[\s\x{00A0}\d]*/u';
        if (preg_match($pattern, $sentence, $matches)) {
            //echo "✅ Pattern matched:\n" . $matches[0] . "\n";
            $sentence = preg_replace($pattern, '', $sentence);
            //echo "\nReplaced with |$sentence|\n";
        }
        return $sentence;
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
        $this->logName = "CBHtml";
        $this->options = $options;
    }
    private $basename = "Ka Ulana Pilina";
    private $sourceName = 'Ka Ulana Pilina';
    public $groupname = "kaulanapilina";
    public function initialize( $baseurl ) {
        $this->funcName = "initialize";
        $this->print( "" );
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
        $this->log( "url = " . $this->url . ", date = " . $this->date );
    }
    public function getSourceName( $title = '', $url = '' ) {
        $this->funcName = "getSourceName";
        $this->print( "($title,$url)" );
        $name = $this->sourceName;
        $this->print( "url = " . $this->url . ", date = " . $this->date );
        if( $url ) {
            $this->url = $url;
            $dom = $this->getDOM( $this->url );
            $this->extractDate( $dom );
        }
        if( $this->date ) {
            $name .= ": " . $this->date;
        }
        return $name;
    }
    public function checkElement( $p ) {
        $this->funcName = "checkElement";
        $result = ( strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) === false );
        return ($result) ? parent::checkElement( $p ) : '';
    }
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( "" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->print( "" );
        $this->date = '';
        if( !$dom ) {
            $dom = $this->dom;
        }
        if( $dom ) {
            $xpath = new DOMXpath( $dom );
            $query = '//meta[contains(@property, "article:published_time")]';
            $paragraphs = $xpath->query( $query );
            if( $paragraphs->length > 0 ) {
                $p = $paragraphs->item(0);
                if ($p instanceof DOMElement) {
                    $parts = explode( "T", $p->getAttribute( 'content' ) );
                    $this->date = $parts[0];
                }
            }
            $this->print( $this->date );
        } else {
            $this->print( "No DOM" );
        }
        return $this->date;
    }
    public function getPageList() {
        $this->funcName = "getPageList";
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        $this->print( $dom->childElementCount . " child elements\n" );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( ['//h2[contains(@class, "headline")]/a',
                  '//div[contains(@class, "archive")]/p/a'] as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $pp = $p->parentNode->parentNode;
                $url = $p->getAttribute( 'href' );
                $this->debugPrint( "checking: $url" );
                $pagedom = $this->getDom( $url );
                $date = $this->extractDate( $pagedom );
                $sourcename = $this->sourceName . ": " . $date;
                $text = trim( $this->preprocessHTML( $p->nodeValue ) );
                $pages[] = [
                    'sourcename' => $sourcename,
                    'url' => $url,
                    'image' => '',
                    'title' => $text,
                    'groupname' => $this->groupname,
                    'author' => $this->authors,
                ];
            }
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public $groupname = "keaolama";
    public function __construct( $options = [] ) {
        $this->urlBase = 'https://keaolama.org/';
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://keaolama.org/?infinity=scrolling&page=";
        $this->logName = "AoLamaHTML";
        $this->options = $options;
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->print( "" );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
    }
    public function getSourceName( $title = '', $url = '' ) {
        $this->funcName = "getSourceName";
        if( $title ) {
            $this->title = $title;
        }
        $this->print( "title=" . $this->title . ", url=$url" );
        $sourceName = "";
        $parts = explode( '/', parse_url( $this->url, PHP_URL_PATH ) );
        if( sizeof($parts) > 3 ) {
            $sourceName = "{$this->basename} {$parts[1]}-{$parts[2]}-{$parts[3]}";
            $this->debugPrint( "sourceName=" . $sourceName );
        }
        if( !$sourceName ) {
            $sourceName = $this->basename;
            $this->title = $sourceName;
        }
        return $sourceName;
    }
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( "" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->print( "url=" . $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $dateparts = explode( '-', $parts[4] );
            $this->date = $parts[1] . "-" . $dateparts[0] . "-" . $dateparts[1];
        }
        $this->debugPrint( "found [{$this->date}]" );
        return $this->date;
    }
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print( "" );
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
                $text = str_replace( $this->urlBase, '', $u );
                $parts = explode( '/', $text );
                if( sizeof($parts) > 3 ) {
                    $text = $parts[0] . "-" . $parts[1] . "-" . $parts[2];
                }
                $sourcename = $this->basename . ": $text";
                $pages[] = [
                    'sourcename' => $sourcename,
                    'url' => $u,
                    'image' => '',
                    'title' => $text,
                    'groupname' => $this->groupname,
                    'author' => $this->authors,
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
    public $logName = "KauakukalahaleHTML";
    protected $sources = [];
    public function __construct( $options = [] ) {
        $this->urlBase = 'https://www.kauakukalahale.com/';
        $this->baseurl = $this->urlBase;
        $this->semanticSplit = true; // Use semantic splitting
        $this->logName = "KauakukalahaleHTML";
        $this->endMarkers = [
            "This column",
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
        $this->ignoreMarkers = [
            "Correction:",
        ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://www.staradvertiser.com/category/editorial/kauakukalahale/";
        $this->options = $options;
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->funcName = "initialize";
        $this->print( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        if( $title ) {
            $this->title = $title;
        }
        $name = $this->sourceName;
        if( $this->date ) {
            $name .= " " . $this->date;
        }
        return $name;
    }

    public function updateVisibility( $text ) {
        $this->funcName = "updateVisibility";
        $nChars = strlen($text);
        $this->print( "$nChars characters" );
        if( !$text ) {
            return $text;
        }
        //$this->print( "received\n$text" );
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
        $this->print( "$nChars characters; changed $changed tags" );
        //debugPrint( "KauakalahaleParse::updateVisibility() returning\n$text" );
        return $text;
    }
    
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->print( "" );
        if( $this->url ) {
            $date = str_replace( $this->domain, "", $this->url );
            $this->date = substr( $date, 0, 10 );
        } else {
            if( !$dom ) {
                $dom = $this->dom;
            }
            $this->date = '';
            if( $dom ) {
                $xpath = new DOMXpath( $dom );
                $query = "//li[contains(@class, 'postdate')]";
                $paragraphs = $xpath->query( $query );
                if( $paragraphs->length > 0 ) {
                    $p = $paragraphs->item(0);
                    $date = trim( $p->nodeValue );
                    $this->date = date("Y-m-d", strtotime($date));
                }
            }
        }
        return $this->date;
    }
    
    public function extractAuthor( $dom ) {
        $this->funcName = "extractAuthor";
        $this->print( "" );
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
        $funcName = "getContents";
        $this->print( $url );
        $text = parent::getContents( $url, $options );
        $text = $this->updateVisibility( $text );
        $nchars = strlen( $text );
        $this->log( "after updateVisibility - $nchars characters()" );
        return $text;
    }

    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print( "" );
        $pagenr = 1;
        $pages = [];
        while( ($morepages = $this->getSomePages( $pagenr )) &&
               (sizeof($morepages) > 0) ) {
            $pages = array_merge( $pages, $morepages );
            $pagenr++;
        }
        return $pages;
    }
    
    public function getSomePages( $pagenr ) {
        $this->funcName = "getSomePages";
        if( $pagenr == 1 ) {
            $url = $this->baseurl;
        } else {
            $url = $this->baseurl . 'page/' . $pagenr;
        }
        $this->print( "($pagenr): $url" );
        $dom = $this->getDOM( $url );
        $xpath = new DOMXpath($dom);
        
        $query = '//article[contains(@class, "story")]';
        $articles = $xpath->query( $query );

        $pages = [];
        foreach( $articles as $article ) {
            //$outerHTML = $article->ownerDocument->saveHTML($article);
            //echo "outerHTML: $outerHTML\n";
            $paragraphs = $xpath->query( "div/a", $article );
            if( $paragraphs->length > 0 ) {
                $p = $paragraphs->item( 0 );
                //$outerHTML = $p->ownerDocument->saveHTML($p);
                //echo "outerHTML: $outerHTML\n";
                $url = $p->getAttribute( 'href' );
                if( strpos( $url, "kauakukalahale" ) !== false ) {
                    $title = $p->getAttribute( 'title' );
                    $title = $this->basename . ": " . str_replace( "Column: ", "", $title );
                    $date = str_replace( $this->domain, "", $url );
                    $date = substr( $date, 0, 10 );
                    $sourcename = $this->basename . ": " . $date;
                    if( array_key_exists( $sourcename, $this->sources ) ) {
                        //echo "skipping $sourcename\n";
                        continue;
                    }
                    $this->sources[$sourcename] = 1;
                    $childNodes = $p->getElementsByTagName( 'img' );
                    if( $childNodes->length > 0 ) {
                        $img = $childNodes->item( 0 )->getAttribute( 'data-src' );
                    }
                    $lis = $xpath->query( "*/li[contains(@class, 'custom_byline')]", $article );
                    $author = '';
                    if( $lis->length > 0 ) {
                        $li = $lis->item( 0 );
                        $author = trim( $li->nodeValue );
                        $author = preg_replace( '/(^By na |^By |^Na )/i', '', $author );
                    }
                    $pages[] = [
                        $sourcename => [
                            'url' => $url,
                            'title' => $title,
                            'date' => $date,
                            'image' => $img,
                            'authors' => $author,
                            'groupname' => $this->groupname,
                        ]
                    ];
                }
            }
        }
        //echo "Pages found: " . sizeof($pages) . "\n";
        return $pages;
    }

    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( "{$dom->childElementCount} nodes" );
        $xpath = new DOMXpath($dom);
        $pattern = '//div[contains(@class, "hsa-paywall")]//p';
        $paragraphs = $xpath->query( $pattern );
        return $paragraphs;
    }
    
    public function checkElement( $p ) {
        $this->funcName = "checkElement";
        $text = trim( strip_tags( $p->nodeValue ) );
        //debugPrint( "KauakukalahaleHTML::checkElement $text" );
        if( preg_match( "/^Synopsis:/", $text ) ) {
            return '';
        }
        return parent::checkElement( $p );
    }
    
    public function process( $contents ) {
        $this->funcName = "process";
        $this->print( "{$contents->length} nodes" );
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
        $results = $this->processText( $rawResults );
        return $results;
    }
}

class NupepaHTML extends HtmlParse {
    private $basename = "Nupepa";
    public $groupname = "nupepa";
    private $domain = "https://nupepa.org/";
    private $sourceName = 'Nupepa';
    private $skipTitles = [
        "Ka Wai Ola - Office of Hawaiian Affairs",
    ];  // Almost no Hawaiian text
    protected $pagemap;
    protected $pagetitles = [];
    protected $pageToArticleMap = [];
    protected $documentOID = '';
    public function __construct( $options = [] ) {
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://nupepa.org/?a=cl&cl=CL2";
        $this->semanticSplit = true; // Use semantic splitting
        if( count($options) < 1 ) {
            $options = [
                'preprocess' => false,
            ];
        }
        $this->options = $options;
        $this->logName = "NupepaHTML";
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->funcName = "initialize";
        $this->print( $baseurl );
        $text = $this->getRaw( $baseurl );
        $this->dom = $this->getDOMFromString( $text );
        $this->extractTitle( $this->dom );
        $this->extractDate( $this->dom );
        $contents = $this->dom->saveHTML();
        //echo "$contents\n";
        //exit;
        // Get the list of pages of this document
        $pattern = '/\bpageTitles:\s*\{(.*?)\};/s';
        $pattern = '/pageTitles.*?\{(.*?)\}/s';
        if (preg_match($pattern, $contents, $matches)) {
            $pagetitles = "{" . $matches[1] . "}";
            $pagetitles = preg_replace( "/\n/", "", $pagetitles );
            //$pagetitles = str_replace( '"', "&quot;", $pagetitles );
            $pagetitles = str_replace( "'", '"', $pagetitles );
            $this->print( "json_decoding pattern " . $pagetitles );
            $this->pagetitles = json_decode( $pagetitles, true );
        } else {
            $this->print( "no pattern $pattern" );
        }
        $this->print( $this->pagetitles, "pageTitles" );
        $pattern = '/"documentOID"\s*:\s*"([^"]+)/';

        if (preg_match($pattern, $contents, $matches)) {
            $this->documentOID = $matches[1];
            $this->print( "documentOID = " .  $this->documentOID );
        } else {
            $this->print( "no pattern $pattern" );
        }
    }
    
    public function getSourceName( $title = '', $url = '' ) {
        $this->funcName = "getSourceName";
        $this->print("title=$title, url=$url");
        $this->sourceName = '';
        if( $title ) {
            $this->sourceName = $title;
        } else if( $url ) {
            $this->url = $url;
            $this->dom = $this->getDOM( $this->url );
        }
        $this->print( "url = " . $this->url . ", sourceName = " . $this->sourceName );
        return $this->sourceName;
    }
    
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print($this->baseurl);
        //$this->initialize( $this->baseurl );
        $client = new Client(['base_uri' => 'https://nupepa.org']);

        $startUrl = '/?a=cl&cl=CL2&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0';

        // Fix #2: Validate start page
        $response = $client->get($startUrl);
        if ($response->getStatusCode() !== 200) {
            die("Failed to retrieve start page");
        }

        $html = (string) $response->getBody();
        $crawler = new Crawler($html);

        $results = [];

        $crawler->filter('#datebrowserrichardtoplevelcalendar > div')->each(function ($yearBlock) use ($client, &$results) {
            $yearHeaderNode = $yearBlock->filter('h2');
            $yearHeader = $yearHeaderNode->count() ? $yearHeaderNode->text() : 'Unknown Year';

            if( 0 ) {
                echo "\n=== Debug: Year Block HTML ===\n";
                echo $yearBlock->html() . "\n";
            }

            $yearBlock->filter('a[href*="a=cl&cl=CL2."]')->each(function ($linkNode) use ($client, $yearHeader, &$results) {
                $monthText = trim($linkNode->text());
                $monthUrl = $linkNode->attr('href');

                $monthResponse = $client->get($monthUrl);
                $monthHtml = (string) $monthResponse->getBody();
                $monthCrawler = new Crawler($monthHtml);

                $monthCrawler->filter('.datebrowserrichardmonthlevelcalendardaycellcontents')->each(function ($dayCell) use (&$results) {
                    if (!$dayCell->filter('.datebrowserrichardmonthdaynumdocs')->count()) return;

                    $dateNode = $dayCell->filter('b.hiddenwhennotsmall');
                    $dateText = $dateNode->count() ? trim($dateNode->text()) : null;
                    
                    $dayCell->filter('li.list-group-item')->each(function ($itemNode) use ($dateText, &$results) {
                        $linkNode = $itemNode->filter('a[href*="a=d&"]');
                        if (!$linkNode->count()) return;

                        $href = $linkNode->attr('href');
                        $titleText = trim($linkNode->text());
                        $fullUrl = 'https://nupepa.org' . $href;
                        $ok = true;
                        foreach( $this->skipTitles as $pattern ) {
                            if( strpos( $titleText, $pattern ) !== false ) {
                                $ok = false;
                                break;
                            }
                        }
                        if( $ok ) {

                            // Image (if present in this block)
                            $imgNode = $itemNode->filter('img');
                            $imgSrc = $imgNode->count() ? $imgNode->attr('src') : null;
                            if ($imgSrc && !str_starts_with($imgSrc, 'http')) {
                                $imgSrc = 'https://nupepa.org' . $imgSrc;
                            }

                            // Placeholder for author (if there's some nearby identifier—custom logic could go here)
                            $authorText = null; // Not structured reliably, likely needs OCR or advanced parsing
                            $dateText = preg_replace('/^\(\w+, (.*?)\)$/', '$1', $dateText);

                            $results[] = [
                                'sourcename' => "{$titleText} {$dateText}",
                                'url' => $fullUrl,
                                'image' => $imgSrc,
                                'title' => "{$titleText} {$dateText}",
                                'date' => $dateText,
                                'author' => $authorText,
                                'groupname' => 'nupepa',
                            ];
                        }
                    });
                });
            });
        });
        return $results;
    }

    // This gets a full page from several sections
    public function getRawText( $pageurl ) {
        $this->funcName = "getRawText";
        $this->print( $pageurl );
        $fulltext = "";
        $hasHead = false;
        $count = 0;
        $this->debugPrint( $this->pagetitles, "pagetitles" );
        // Some page lists distinguish between cover material and contents
        $hasCover = false;
        foreach( $this->pagetitles as $key => $title ) {
            if( preg_match( "/Page /", $title ) ) {
                $hasCover = true;
                break;
            }
        }
        $parts = parse_url($pageurl);
        parse_str($parts['query'], $queryParams);
        $eValue = $queryParams['e'] ?? '';
        $urls = [];
        foreach( $this->pagetitles as $key => $title ) {
            $titleText = strip_tags($title);
            $this->debugPrint( "$key " . " => " . $titleText );
            $url = "https://nupepa.org/?a=d&d=" .  $this->documentOID . "." . $key;
            $url .= "&srpos=&dliv=none&st=1";
            if( $eValue ) {
                $url .= "&e=" . $eValue;
            }
            $this->debugPrint( "found page $url" );
            $urls[] = $url;
        }

        $fulltext = $this->collectSubPages( $urls );
        return $fulltext;
    }

    // Inconsistent document structure
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( $dom->childElementCount . " nodes" );
        $xpath = new DOMXpath($dom);
        $patterns = [
            '//sec/div/p',
            '//p/span',
            '//td/div/div',
            '//center/table/tr/td/*',
            '//sec/div/p',
            '//sec/div/p',
            '//p/span',
            '//td/div/div',
            '//center/table/tr/td/*',
            '//sec/div/p',
        ];
        foreach( $patterns as $pattern ) {
            $paragraphs = $xpath->query( $pattern );
            if( $paragraphs && count($paragraphs) > 0 ) {
                $this->print( "found " . count($paragraphs) . " for $pattern" );
                return $paragraphs;
            }
        }
        $this->print( $patterns, "found no matching elements" );
        return [];
    }
    
    public function extractTitle( $dom ) {
        $this->funcName = "extractTitle";
        $this->print( $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        if( $dom ) {
            $xpath = new DOMXpath($dom);
            $query = '//head/title';
            $titles = $xpath->query( $query );
            if( $titles && $titles->length >= 1 ) {
                $title = $titles->item(0)->nodeValue;
                $title = htmlentities( $title );
                $title = preg_replace( '/ \&mdash\;.*/', '', $title );
                $this->title = $title;
            } else {
                $this->print( "no title found" );
            }
        }
        return $this->title;
    }
    
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->print( $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        $title = $this->extractTitle( $dom );
        if( $title ) {
            $parts = explode( " ", $title );
            $len = sizeof( $parts );
            $date = "{$parts[$len-3]} {$parts[$len-2]} {$parts[$len-1]}";
            $date = date( 'Y-m-d', strtotime( $date ) );
            $this->date = $date;
            $this->print( "found [{$this->date}]" );
        } else {
            $this->print( "no title found" );
        }
        return $this->date;
    }
    
    // Connect lines using connector patterns (glottals, conjunctions, etc.)
    // $lines: array of input lines
    // Returns: array of joined lines
    public function connectLines(array $lines) {
        $this->funcName = "connectLines";
        $this->print( sizeof($lines) . " lines" );
        $connectors = [
            // Glottal: no space before/after
            "‘" => "",
            "ʻ" => "",
            // Capitalized conjunctions: one or more dashes, all caps and spaces, with optional spaces
            // Regex: e.g., -- A ME NA --
            '/^-{1,2}\s*[A-ZĀĒĪŌŪ ]+\s*-{1,2}$/' => " ", // one space before and after
        ];
        $joined = [];
        $lastWasConnector = false;
        $lastConnectorInsert = '';
        foreach ($lines as $line) {
            $line = trim($line);
            $foundConnector = false;
            $connectorInsert = '';
            foreach ($connectors as $pattern => $insert) {
                if ($pattern[0] === '/' && substr($pattern, -1) === '/') {
                    // Regex pattern
                    if (preg_match($pattern, $line)) {
                        $foundConnector = true;
                        $connectorInsert = $insert;
                        break;
                    }
                } else {
                    // Literal string
                    if ($line === $pattern) {
                        $foundConnector = true;
                        $connectorInsert = $insert;
                        break;
                    }
                }
            }
            if ($foundConnector) {
                if (!empty($joined)) {
                    $joined[count($joined) - 1] .= $connectorInsert . $line;// . $connectorInsert;
                } else {
                    $joined[] = $line;
                }
                $lastWasConnector = true;
                $lastConnectorInsert = $connectorInsert;
            } else {
                if ($lastWasConnector && !empty($joined)) {
                    $joined[count($joined) - 1] .= $lastConnectorInsert . $line;
                } else {
                    $joined[] = $line;
                }
                $lastWasConnector = false;
                $lastConnectorInsert = '';
            }
        }
        return $joined;
    }
}

// This does not work with ulukau-books because that page requires dynamic
// unfolding with javascript
//class UlukauHTML extends HtmlParse {
class UlukauHTML extends NupepaHTML {
    protected $hostname = "";
    public function __construct( $options = [] ) {
        $this->logName = "UlukauHTML";
        $this->baseurl = "https://puke.ulukau.org/ulukau-books/";
        $this->groupname = "ulukau";
        $this->options = $options;
        if( count($options) < 1 ) {
            $options = [
                'preprocess' => false,
            ];
        }
        $this->options = $options;
    }

    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->funcName = "initialize";
        $this->print("");
        // Extract host URL from baseurl
        $parts = parse_url($baseurl);
        $this->hostname =
            isset($parts['scheme'], $parts['host']) 
               ? $parts['scheme'] . '://' . $parts['host'] : '';
    }
    
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print("");
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        $this->print( "dom child element count: " . $dom->childElementCount );
        $xpath = new DOMXpath($dom);
        $pages = [];
        $host = $this->hostname;

        $pattern = '//div[contains(@class, "ulukaubooks-book-browser-row") and @data-title]';
        $nodes = $xpath->query($pattern);
        $this->print( "Found: " . $nodes->length . " book rows" );

        foreach ($nodes as $node) {
            if (!$node->hasAttribute('data-title')) {
                continue; // skip malformed or partial nodes
            }

            $language = $node->hasAttribute('data-language')
            ? html_entity_decode(trim($node->getAttribute('data-language')), ENT_QUOTES, 'UTF-8')
                      : '';
            if( $language != 'haw' ) {
                continue;
            }

            // ✅ Title from data-title (never truncate!)
            $title = $node->hasAttribute('data-title')
            ? html_entity_decode(trim($node->getAttribute('data-title')), ENT_QUOTES, 'UTF-8')
                   : '';

            // ✅ Author from visible text
            $authorNode = $xpath->query('.//div[contains(@class,"la")][contains(., "Author")]', $node);
            $author = '';
            if ($authorNode->length > 0) {
                preg_match('/Author\(s\):\s*(.+)/u', $authorNode->item(0)->textContent, $matches);
                $author = isset($matches[1])
                ? html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8')
                        : '';
            }

            // ✅ URL from link
            $linkNode = $xpath->query('.//div[contains(@class,"tt")]//a', $node);
            $rel_url = $linkNode->length
            ? html_entity_decode(trim($linkNode->item(0)->getAttribute('href')), ENT_QUOTES, 'UTF-8')
                     : '';
            $full_url = $rel_url ? rtrim($host, '/') . $rel_url : $this->baseurl;

            // ✅ Image from data-src
            $imgNode = $xpath->query('.//img[contains(@class,"lozad")]', $node);
            $imagePath = $imgNode->length
            ? html_entity_decode(trim($imgNode->item(0)->getAttribute('data-src')), ENT_QUOTES, 'UTF-8')
                       : '';
            $full_image = $imagePath ? rtrim($host, '/') . $imagePath : $this->baseurl;

            // ✅ Unique key from book ID
            preg_match('/d=([^&]+)/', $rel_url, $keyMatch);
            $sourcename = "Ulukau: " . (isset($keyMatch[1]) ? $keyMatch[1] : uniqid());

            //$this->debugPrint( $node->C14N(), "Node" ); // Canonical raw HTML

            // ✅ Final result
            $pages = [
                'sourcename' => $sourcename,
                'url' => $full_url,
                'image' => $full_image,
                'title' => $title,
                'groupname' => $this->groupname,
                'author' => $author,
                'language' => $language,
            ];
            
        }
        return $pages;
    }
    
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( $dom->childElementCount . " child elements\n" );
        $xpath = new DOMXpath($dom);
        $pattern = '//div[@id="ulukaupagetextview"]//p';
        $pattern = '//p';
        $paragraphs = $xpath->query( $pattern );
        return $paragraphs;
    }
    
    // A document page has a number of links to constituent pages that have to be retrieved
    public function getRawText( $pageurl ) {
        $this->funcName = "getRawText";
        $this->print( $pageurl );
        $this->initialize( $pageurl );
        $this->funcName = "getRawText";
        $this->print( $this->pagetitles, "pagetitles" );
        $urls = [];
        $base = 'https://puke.ulukau.org/ulukau-books/?a=d&d=' . $this->documentOID;
        foreach( $this->pageToArticleMap as $key => $value ) {
            $urls[] = $base . "." . $value . "&e=-------en-20--1--txt-txPT-----------";
        }
        $this->print( $urls, "page urls" );

        // To get the Ulukau content we have to use a puppeteer proxy
        $loc = "/webapps/worldspot.com/worldspot/ulukau-proxy/ulukau.js";
        foreach( $urls as $url ) {
            $this->debugPrint( "node $loc $url" );
            $text = shell_exec("node $loc $url");
            $this->log( $text, $url );
            $dom = $this->getDOMFromString( $text );
            libxml_use_internal_errors(false);
            $contents = $this->extract( $dom );
            $paraCount = count($contents);
            $this->print( "paragraph count=" . $paraCount );
            $sentences = $this->process( $contents );
            $this->debugPrint( $sentences );
        }
        return "";

        $fulltext = $this->collectSubPages( $urls );
        return $fulltext;
    }
}

class KaPaaMooleloHTML extends HtmlParse {
    private $basename = "Ka Paa Moolelo";
    public $groupname = "kapaamoolelo";
    public $logName = "KaPaaMooleloHtml";
    private $domain = "https://www2.hawaii.edu/~kroddy/";
    protected $baseurls = [
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_kaao.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_moolelo.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/kaao_unuhiia.htm",
    ];
    public function __construct( $options = [] ) {
        $this->endMarkers = [
            "HOPENA",
        ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->options =  ['continue'=>false];
    }
    private function validLink( $url ) {
        $result = strpos( $url, "file:" ) === false &&
                  strpos( $url, "mailto:" ) === false &&
                  strpos( $url, "papa_kuhikuhi" ) === false &&
                  strpos( implode( "", $this->baseurls ), $url ) === false;
        return $result;
    }
    
    public function getOnePageList( $url ) {
        $this->funcName = "getOnePageList";
        $this->print( $url );
        $pages = [];
        $dom = $this->getDOM( $url );
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
                    'sourcename' => $text,
                    'url' => $this->domain . "moolelo/" . $url,
                    'title' => $text,
                    'image' => '',
                    'author' => $this->authors,
                    'groupname' => $this->groupname,
                ];
            }
        }
        return $pages;
    }

    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print( "" );
        // Website no longer available
        // Kevin Roddy passed away April 9, 2025 in Honolulu
        return [];
        
        $pages = [];
        foreach( $this->baseurls as $baseurl ) {
            $more = $this->getOnePageList( $baseurl );
            $pages = array_merge( $pages, $more );
        }
        return $pages;
    }

    public function getSourceName( $title = '', $url = '' ) {
        $this->funcName = "getSourceName";
        $this->print( "($title,$url)" );
        $name = "Ka Paa Moolelo";
        if( $url ) {
            $this->url = $url;
            $dom = $this->getDOM( $this->url );
            $this->extractDate( $dom );
        }
        if( $this->date ) {
            $name .= ": " . $this->date;
        }
        return $name;
    }
    public function initialize( $baseurl ) {
        $this->url = $this->baseurl = $baseurl;
    }
    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->debugPrint( "" );
        $xpath = new DOMXpath( $dom );
        $pattern = '//div[contains(@class, "entry-content")]//p';
        $paragraphs = $xpath->query( $pattern );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->debugPrint( "" );
        $this->date = '';
        if( !$dom ) {
            $dom = $this->dom;
        }
        $xpath = new DOMXpath( $dom );
        $query = '//meta[contains(@property, "article:published_time")]';
        $paragraphs = $xpath->query( $query );
        if( $paragraphs->length > 0 ) {
            $p = $paragraphs->item(0);
            if ($p instanceof DOMElement) {
                $parts = explode( "T", $p->getAttribute( 'content' ) );
                $this->date = $parts[0];
            }
        }
        $this->log( $this->date );
        $this->debugPrint( $this->date );
        return $this->date;
    }

    public function getRawText( $url, $options=[] ) {
        $this->funcName = "getRawText";
        $continue = (!isset($this->options['continue']) ||
                     $this->options['continue']) ? 1 : 0;
        $fulltext = "";
        $parts = explode( "/", $url );
        $baseurl = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/";
        $seen = [];
        while( $url ) {
            $seen[] = $url;
            $text = parent::getRaw( $url, $options );
            $nchars = strlen( $text );
            $this->print( "($url,$continue) read $nchars" );
            $fulltext .= $text;

            $url = '';
            $pattern = '/papa_kaao|papa_moolelo/';
            if( $continue && !preg_match( $pattern, $url ) ) {
                // Check if there is a continuation link
                $dom = $this->getDOMFromString( $text, $options );
                $url = $this->nextUrl( $baseurl, $dom, $seen );
            }
        }
        return $fulltext;
    }
}

class BaibalaHTML extends HtmlParse {
    private $basename = "Baibala";
    public $groupname = "baibala";
    public $logName = "BaibalaHtml";
    private $documentname = "Baibala (full bible, 2012 edition)";
    private $domain = "https://baibala.org/";
    protected $startMarker = "Baibala (full bible)";
    
    public function __construct( $options = [] ) {
        $this->endMarkers = [
        ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl =
        "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-";
        $this->semanticSplit = true; // Use semantic splitting
        $this->options = $options;
    }
    public function getSourceName( $title = '', $url = '' ) {
        $this->funcName = "getSourceName";
        $this->print( "($title,$url)" );
        return $this->documentname;
    }
    
    // The bible document was conveniently split with "<br>" which was translated to "\n" earlier
    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters" );
        $lines = explode( "\n", $text );
        return $lines;
    }
    
    public function getPageList() {
        $pages = [];
        $text = $this->documentname;
        // It's a single document
        $pages[] = [
            'sourcename' => $text,
            'url' => $this->baseurl,
            'title' => $text,
            'image' => '',
            'author' => $this->authors,
            'groupname' => $this->groupname,
        ];
        return $pages;
    }

    public function extract( $dom ) {
        $this->funcName = "extract";
        $this->print( "" );
        $xpath = new DOMXpath( $dom );
        $pattern = '//div[contains(@class, "entry-content")]//p';
        $pattern = '//td';
        $paragraphs = $xpath->query( $pattern );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $pattern = '//body';
            $paragraphs = $xpath->query( $pattern );
        }
        return $paragraphs;
    }

    public function getDOM( $url ) {
        $this->funcName = "getDOM";
        $text = $this->getRaw( $url );
        $this->print( strlen($text) . " characters read from $url" );
        $dom = $this->getDOMFromString( $text );
        return $dom;
    }
    
    public function getRawText( $url ) {
        $this->funcName = "getRawText";
        $this->print( $url );
        $text = $this->getRaw( $url );
        $nchars = strlen( $text );
        $continue = (!isset($this->options['continue']) || $this->options['continue']) ? 1 : 0;

        $fulltext = "";
        $parts = explode( "/", $url );
        $baseurl = implode( "/", array_slice( $parts, 0, sizeof($parts) - 1 ) ) . "/";
        $seen = [];
        while( $url ) {
            if( in_array( $url, $seen ) ) {
                break;
            }
            $seen[] = $url;
            $text = parent::getRaw( $url );
            $nchars = strlen( $text );
            $this->print( "($url,$continue) read $nchars" );
            $fulltext .= $text;

            $url = '';
            if( $continue ) {
                // Check if there is a continuation link
                $dom = $this->getDOMFromString( $text );
                $url = $this->nextUrl( $dom, $seen );
            }
            echo "url: |$url|\n";
        }
 
        return $fulltext;
    }

    protected function nextUrl( $dom, $seen ) {
        $this->funcName = "nextUrl";
        $this->print( $seen, "seen" );
        $xpath = new DOMXpath($dom);
        $p = $xpath->query( "//a[contains(@target, 'top')]" );
        $url = '';
        foreach( $p as $element ) {
            $url = $element->getAttribute( 'href' );
            $url = $this->domain . $url;
            $html = $element->ownerDocument->saveHTML($element->firstChild);
            //debugPrint( "BaibalaHTML::nextUrl first level frameset ($html): $url" );
            if( strpos( $html, "next" ) != 0 ) {
                $this->print( "skipping" );
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
                    $this->print( "\ncontinuation: $url\n" );
                }
            }
        }
        if( !$url ) {
            $this->print( "\ndid not find next iframe\n" );
        }
        if( in_array( $url, $seen ) ) {
            $this->print( "already did this one" );
            $url = "";
        }
        return $url;
    }
}

class EhoouluLahuiHTML extends HtmlParse {
    private $basename = "Ehooulu Lahui";
    public $groupname = "ehooululahui";
    public function __construct( $options = [] ) {
        $this->logName = "EhoouluLahuiHTML";
        $this->urlBase = 'https://ehooululahui.maui.hawaii.edu/';
        $this->options = $options;
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
        $this->funcName = "extract";
        $this->print( "dom child elements: {$dom->childElementCount} nodes" );
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "cmsmasters_text")]/p' );
        //$paragraphs = $xpath->query( '//p' );
        $this->debugPrint( "EhoouluLahuiHTML::extract found {$paragraphs->length} nodes" );
        return $paragraphs;
    }
    
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $this->print( "url=" . $this->url );
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $dateparts = explode( '-', $parts[4] );
            $this->date = $parts[1] . "-" . $dateparts[0] . "-" . $dateparts[1];
        }
        $this->print( "found [{$this->date}]" );
        return $this->date;
    }
    
    public function getPageList() {
        $this->funcName = "getPageList";
        $this->print( "" );
        // Only a few of the documents there are really interesting and it is difficult to
        // determine automatically, so just list them here.
        return [
            [
                'sourcename' => '‘Aukelenuia‘īkū',
                'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=65',
                'image' => '',
                'title' => '‘Aukelenuia‘īkū',
                'author' => '',
                'groupname' => $this->groupname,
            ],
            [
                'sourcename' => 'Lonoikamakahiki',
                'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=67',
                'image' => '',
                'title' => 'Lonoikamakahiki',
                'author' => '',
                'groupname' => $this->groupname,
            ],
            [
                'sourcename' => 'Punia',
                'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=69',
                'image' => '',
                'title' => 'Punia',
                'author' => '',
                'groupname' => $this->groupname,
            ],
            [
                '‘Umi' =>
                    [
                        'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=71',
                        'image' => '',
                        'title' => '‘Umi',
                        'author' => '',
                        'groupname' => $this->groupname,
                    ],
            ],
            /*
               [
               'Kalapana' =>
               [
               'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=1327',
               'image' => '',
               'title' => 'Kalapana',
               'author' => '',
               'groupname' => $this->groupname,
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
        $p = $xpath->query( "//a[contains(@href, 'https://ehooululahui.maui.hawaii.edu/')]" );
        //$p = $xpath->query( '//a' );
        foreach( $p as $a ) {
            if ($a instanceof DOMElement) {
                $url = $a->getAttribute( 'href' );
                $title = trim( $a->parentNode->nodeValue );
                if( !in_array( $title, $desired ) ) {
                    $this->print( "Skipping |$title|" );
                    continue;
                }
            } else {
                continue;
            }
            $pages[] = [
                'sourcename' => $title,
                'url' => $url,
                'image' => '',
                'title' => $title,
                'author' => $this->authors,
                'groupname' => $this->groupname,
            ];
            $page++;
        }
        return $pages;
    }
    public function getRawText( $url ) {
        $this->funcName = "getRawText";
        $this->print( $url );
        $dirtext = $this->getRaw( $url );
        $nchars = strlen( $dirtext );
        $this->log( "$nchars characters" );
        
        $dirdom = $this->getDOMFromString( $dirtext );
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

                    $text = $this->getRaw( $url );
                    $dom = $this->getDOMFromString( $text );
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
                    //$fulltext .= $this->getRaw( $url );
                    $fulltext .= $text;

                }
            }
        }
        return $fulltext;
    }
}

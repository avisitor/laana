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
    public $metadata = [];
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
    protected $extractPatterns = ["//p", "//body"];
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
        $this->resetMetadata();
    }
    public function resetMetadata() {
        $this->metadata = [
            'source_urls' => [],
            'processed_pages' => 0,
            'original_char_count' => 0,
            'final_html_char_count' => 0,
            'sentence_count' => 0,
            'final_text_char_count' => 0,
            'title' => '',
            'author' => '',
            'date' => '1970-01-01',
            'sourcename' => '',
        ];
    }
    public function getMetadata() {
        return $this->metadata;
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
    public function initialize( $baseurl ) {
        $this->url = $this->baseurl = $baseurl;
    }
    public function getDateFromURL( $url ) {
        preg_match('/\/(\d{4})\/(\d{2})\/(\d{2})\//', $url, $matches);
        $date =
            isset($matches[1], $matches[2], $matches[3]) ?
            "{$matches[1]}-{$matches[2]}-{$matches[3]}" : '';
        return $date;
    }
    public function getRaw($url, $trackMetadata = true) {
        $this->funcName = "getRaw";
        $url = trim( $url );
        $this->print($url);
        $html = "";
        // Use Guzzle or use file_get_contents?
        if( 0 ) {
            $client = new Client();;
            $response = $client->get($url);
            if ($response->getStatusCode() === 200) {
                $html = (string)$response->getBody();
            }
        } else {
            $html = ($url) ? file_get_contents($url) : "";
        }
        if ($trackMetadata) {
            $this->html = $html;
            $this->metadata['original_char_count'] += strlen($this->html);
            if ($url) {
                $this->metadata['source_urls'][] = $url;
            }
        }
        return $html;
    }

    public final function getRawText($url) {
        $this->funcName = "getRawText";
        $this->print($url);
        $this->resetMetadata();

        $documentUrls = $this->getDocumentPageUrls($url);
        $this->metadata['processed_pages'] = count($documentUrls);

        if (count($documentUrls) > 1) {
            return $this->collectSubPages($documentUrls);
        } elseif (!empty($documentUrls)) {
            return $this->getRaw(reset($documentUrls));
        }
        return "";
    }

    protected function getDocumentPageUrls($initialUrl) {
        return [$initialUrl];
    }

    public function collectSubPages($urls) {
        $this->funcName = "collectSubPages";
        $this->print(sizeof($urls) . " urls");
        $hasHead = false;
        $fulltext = "<html>";
        $bodyContent = "";

        foreach ($urls as $url) {
            $this->debugPrint($url);
            $text = $this->getRaw($url); // Metadata is tracked here
            $dom = $this->getDOMFromString($text);
            $xpath = new DOMXpath($dom);

            if (!$hasHead) {
                $headNode = $xpath->query('//head')->item(0);
                if ($headNode) {
                    $fulltext .= $headNode->ownerDocument->saveHTML($headNode);
                } else {
                    $fulltext .= '<head><meta charset="UTF-8"></head>';
                }
                $fulltext .= "<body>";
                $hasHead = true;
            }

            $bodyNode = $xpath->query('//body')->item(0);
            if ($bodyNode) {
                foreach ($bodyNode->childNodes as $child) {
                    $bodyContent .= $child->ownerDocument->saveHTML($child);
                }
            }
        }

        $fulltext .= $bodyContent . "</body></html>";
        $this->metadata['final_html_char_count'] = strlen($fulltext);
        return $fulltext;
    }

    public function getContents( $url ) {
        $funcName = $this->funcName = "getContents";
        $this->print( $this->options, $url );
        $text = $this->getRawText( $url );
        $nchars = strlen( $text );
        $this->debugPrint( "got $nchars characters from getRawText" );
        if( !isset($this->options['preprocess']) || $this->options['preprocess'] ) {
            $text = $this->preprocessHTML( $text );
            $nchars = strlen( $text );
            $this->debugPrint( "$nchars characters after preprocessHTML" );
        }
        return $text;
    }

    public function getDOMFromString( $text ) {
        $funcName = $this->funcName = "getDOMFromString";
        $nchars = strlen( $text );
        $this->print( "$nchars characters" );
        $dom = new DOMDocument();
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        if( $text ) {
            $text = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');
            $prefix = "";
            $pos = strpos($text, "<!DOCTYPE ");
            if ($pos === false) {
                $prefix = '<!DOCTYPE html>';
                $pos = strpos($text, "<html");
                if ($pos === false) {
                    $prefix .= '<html>';
                }
                $text = $prefix . $text;
                $pos = strpos($text, "</html");
                if ($pos === false) {
                    $text .= "</html>";
                }
                //$text = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $text . '</body></html>';
            }
            libxml_clear_errors();
            $dom->loadHTML($text);
            libxml_clear_errors();
        }
        return $dom;
    }
    
    protected function getDomForDiscovery($url) {
        $html = $this->getRaw($url, false);
        if (!$html) {
            return null;
        }
        $html = $this->preprocessHTML($html);
        return $this->getDOMFromString($html);
    }

    public function getDOM( $url ) {
        $this->funcName = "getDOM";
        $text = $this->getContents( $url );
        $this->print( strlen($text) . " characters read from $url" );
        $dom = $this->getDOMFromString( $text );
        return $dom;
    }
    
    public function containsEndMarker($line) {
        foreach ($this->endMarkers as $marker) {
            if (strpos($line, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
    
    public function checkSentence( $sentence ) {
        $this->funcName = "checkSentence";
        $this->log( strlen($sentence) . " characters" );

        $pos = strpos($sentence, "‘");
        if ($pos !== false) {
            return true;
        }
        if( str_word_count($sentence) < $this->minWords ) {
            return false;
        }
        if (preg_match_all('/[^\d\s]/u', $sentence, $matches) < 3) {
        }
        if (preg_match('/^No\.?[
\d[:punct:]]*$/u', trim($sentence))) {
            return false;
        }
        if (preg_match('/^P\.?[
\d[:punct:]]*$/u', trim($sentence))) {
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
        $sentence = str_replace($this->placeholder, '.', $sentence);
        $sentence = preg_replace('/(\s+)(\?|\!)/', '$2', $sentence);
        return $sentence;
    }
    
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
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badIDs as $filter ) {
            $p = $xpath->query( "//div[@id='$filter']" );
            foreach( $p as $element ) {
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badClasses as $filter ) {
            $query = "//div[contains(@class,'$filter')]";
            $p = $xpath->query( $query );
            foreach( $p as $element ) {
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        $this->print( "removed $changed tags" );
        $text = $dom->saveHTML();
        return $text;
    }

    public function updateLinks( $text ) {
        $this->funcName = "updateLinks";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $dom = $this->getDOMFromString( $text );
        $xpath = new DOMXpath($dom);
        $filters = [ "//img", "//a" ];
        $attrs = [ "src", "href" ];
        $changed = 0;
        foreach( $filters as $filter ) {
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                foreach( $attrs as $attr ) {
                    if (!($element instanceof DOMElement)) continue;
                    $url = $element->getAttribute($attr);
                    if( preg_match( '/^\//', $url ) ) {
                        $url = $this->urlBase . $url;
                        $element->setAttribute( $attr, $url );
                        $changed++;
                    }
                }
            }
        }
        if( $changed ) {
            $text = $dom->saveHTML();
        }
        return $text;
    }
    
    public function convertEncoding( $text ) {
        $this->funcName = "convertEncoding";
        if (!is_string($text)) {
            $text = (string)$text;
        }
        $this->print( strlen($text) . " characters" );
        if( !$text ) {
            return $text;
        }
        $pairs = [
            '&Auml;&#129;' => "ā", "&Auml;&#128;" => "Ā", '&Ecirc;&raquo;' => '‘',
            '&Aring;&#141;' => 'ū', '&Auml;&ordf;' => 'Ī', '&Auml;&#147;' => 'ē',
            '&raquo;' => '', '&laquo;' => '', '&mdash;' => '-', '&nbsp;' => ' ',
            "&Ecirc;" => '‘', "&Aring;" => 'ū', '&Atilde;&#133;&Acirc;&#140;' => 'Ō',
            '&Atilde;&#133;&Acirc;' => 'ū', '&Atilde;&#132;&Acirc;' => 'ā',
            '&Atilde;&#138;&Acirc;&raquo;' => '‘', "&Acirc;" => '', "&acirc;" => '',
            '&lsquo;' => "'", '&rsquo;' => "'", '&rdquo;' => '"', '&ldquo;' => '"',
            "&auml;" => "ā", "&Auml;" => "Ā", "&Euml;" => "Ē", "&euml;" => "ē",
            "&Iuml;" => "Ī", "&iuml;" => "ī", "&ouml;" => "ō", "&Ouml;" => "Ō",
            "&Uuml" => "Ū", "&uuml;" => "ū", "&aelig;" => '‘', "&#128;&brvbar;" => '-',
            "&#128;&#147;" => '-', "&#128;&#148;" => '-', "&#128;&#152;" => '‘',
            "&#128;&#156;" => '"', "&#128;&#157;" => '-', '&#157;&#x9D;' => '-',
            "&#128;" => "", "&#129;" => "", "&#140;" => "Ō", "&#146;" => "'",
            "&#256;" => "Ā", "&#257;" => "ā", "&#274;" => "Ē", "&#275;" => "ē",
            "&#298;" => "Ī", "&#299;" => "ī", "&#332;" => "Ō", "&#333;" => "ō",
            "&#362;" => "Ū", "&#363;" => "ū", "&#699;" => '‘',
        ];
        $text = strtr($text, $pairs);
        $replace = [
            '/\x80\x99/u' => ' ', '/\x80\x9C/u' => ' ', '/\x80\x9D/u' => ' ',
            '/&nbsp;/' => ' ', '/"/' => '',
            '/' . preg_quote($this->Amarker, '/') . '/' => 'Ā',
            '/[\x{0080}\x{00A6}\x{009C}\x{0099}]/u' => '.',
        ];
        $raw = $text;
        $text = preg_replace(array_keys($replace), array_values($replace), $text);
        if ($text === null || $text === '') {
            echo('preg_replace failed: ' . preg_last_error() . ": $raw\n");
            return '';
        }
        $text = trim($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
    }
    
    public function preprocessHTML( $text ) {
        $this->funcName = "preprocessHTML";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return $text;
        }
        $text = $this->removeElements( $text );
        $this->metadata['final_html_char_count'] = strlen($text);
        $text = $this->updateLinks( $text );
        $text = $this->convertEncoding( $text );
        return $text;
    }
    
    public function checkElement( $p ) {
        $this->funcName = "checkElement";
        if ($p->nodeName === 'br') {
            return '';
        }
        $text = trim(strip_tags($p->nodeValue));
        return $text;
    }
    
    protected function protectAbbreviations( $text ) {
        $this->funcName = "protectAbbreviations";
        $this->print( strlen($text) . " characters" );
        $placeholder = $this->placeholder;
        $abbrPattern = implode('|', array_map(function($abbr) {
            return preg_quote($abbr, '/');
        }, self::$abbreviations));
        $text = preg_replace_callback('/\b(' . $abbrPattern . ')/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        $text = preg_replace_callback('/\b([A-Z](?:\.[A-Z]){1,}\.)/', function($matches) use ($placeholder) {
            return str_replace('.', $placeholder, $matches[1]);
        }, $text);
        $text = preg_replace_callback('/\b([A-Z])\.(?=\s)/', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        $text = preg_replace_callback('/\b([A-Z][a-z]{1,2})\./', function($matches) use ($placeholder) {
            return $matches[1] . $placeholder;
        }, $text);
        $text = preg_replace_callback('/(\d[\d\s\-]*\.)\s*(?=\d)/', function($matches) use ($placeholder) {
            return rtrim($matches[1], '.') . $placeholder;
        }, $text);
        return $text;
    }
    
    public function splitSentences( $paragraphs ) {
        $this->funcName = "splitSentences";
        $this->print( count($paragraphs) . " paragraphs" );
        $text = ($paragraphs && count($paragraphs) > 0) ? implode( "\n", $paragraphs ) : "";
        $this->debugPrint( strlen($text) . " characters" );
        if( empty( $text ) ) {
            return [];
        }
        $text = $this->protectAbbreviations($text);
        $lines = $this->splitLines($text);
        $this->print( count($lines) . " lines after preg_split" );
        
        $results = [];
        foreach ($lines as $line) {
            if( $line && !empty($line) ) {
                $results[] = $line;
            } else {
                $this->discarded[] = $line;
            }
        }
        $lines = $results;
        $this->debugPrint( count($lines) . " lines before connectLines" );
        $lines = $this->connectLines($lines);
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
        $pattern = '/(?<=[.?!])\s+(?=(?![‘ʻ\x{2018}\x{02BB}])[A-ZāĀĒēĪīōŌŪū])/u';
        $lines = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);
        if( count($lines) < 2 ) {
            $this->debugPrint( "found " . strlen($text) . " characters in line after splitting by period, trying comma split" );
            $lines = preg_split('/\,+/', $text, -1, PREG_SPLIT_NO_EMPTY);
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
            if ( preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', $buffer) || preg_match('/^[A-Z]\\.$/u', $buffer) || preg_match('/^[A-Z]\\.-$/u', $buffer) ) {
                while ( $j < $n && ( preg_match('/^[A-Z]\\.$/u', trim($lines[$j])) || preg_match('/^[A-Z]\\.-$/u', trim($lines[$j])) || preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', trim($lines[$j])) || (mb_strlen(trim($lines[$j])) <= 30 && preg_match('/^[A-Z]/u', trim($lines[$j]))) ) ) {
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
            if( $this->semanticSplit ) {
                $this->debugPrint("using semantic splitting");
                $lines = $this->splitSentences( $paragraphs );
            } else {
                $this->debugPrint("using HTML element splitting");
                $lines = $this->splitByElements( $paragraphs );
                foreach ($lines as $index => $line) {
                    if (strlen($line) > self::RESPLIT_SENTENCE_LENGTH) {
                        $split = $this->splitSentences([$line]);
                        array_splice($lines, $index, 1, $split);
                    }
                }
            }
            foreach ($lines as $line) {
                if ($this->checkSentence($line)) {
                    $line = preg_replace('/\s+/u', ' ', $line);
                    $line = preg_replace('/\s+([\'‘])\s+/u', '$1', $line);
                    $line = substr($line, 0, self::MAX_SENTENCE_LENGTH);
                    if( $this->containsEndMarker( $line ) ) {
                        break;
                    }
                    $finalLines[] = $line;
                } else {
                    $this->discarded[] = $line;
                }
            }
        }
        $this->metadata['sentence_count'] = count($finalLines);
        $this->metadata['final_text_char_count'] = strlen(implode("\n", $finalLines));
        return $finalLines;
    }
    
    public function splitByElements( $contents ) {
        $this->funcName = "splitByElements";
        $count = count($contents);
        $this->print( "$count elements" );
        $lines = [];
        $saved = '';
        for ($idx = 0; $idx < $count; $idx++) {
            $text = $contents[$idx];
            $text = preg_replace('/\s+/u', ' ', $text);
            if (trim($text) === '') continue;
            $text = preg_replace('/[\n\r]+/', ' ', $text); 
            $text = preg_replace('/\s+([?!])/', '$1', $text);
            $lines[] = $text;
        }
        $lines = $this->connectLines($lines);
        return $lines;
    }
    
    public function extractSentencesFromHTML( $text ) {
        $this->funcName = "extractSentencesFromHTML";
        $this->print( strlen($text) . " chars" );
        if( !$text ) {
            return [];
        }
        $this->resetMetadata();
        $dom = $this->getDOMFromString( $text );
        $this->extractMetadata( $dom );
        libxml_use_internal_errors(false);
        $contents = $this->extract( $dom );
        $paraCount = count($contents);
        $this->print( "paragraph count=" . $paraCount );
        $sentences = $this->process( $contents );
        return $sentences;
    }
    
    public function extractSentences( $url = "" ) {
        $this->funcName = "extractSentences";
        if( !$url ) {
            $url = $this->baseurl;
        }
        $this->print( $url );
        $contents = $this->getContents( $url );
        $sentences = $this->extractSentencesFromHTML( $contents );
        return $sentences;
    }
    
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
        $text = $this->preprocessHTML( $text );
        $sentences = $this->extractSentencesFromHTML( $text );
        return $sentences;
    }
    
    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata";
        $this->debugPrint( "Base extractMetadata" );
        $this->metadata['date'] = '1970-01-01';
    }
    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->debugPrint( "" );
        return [];
    }
    
    public final function extract($dom) {
        $this->funcName = "extract";
        $this->print( "" );
        $xpath = new DOMXpath($dom);
        foreach ($this->extractPatterns as $pattern) {
            $paragraphs = $xpath->query($pattern);
            if ($paragraphs && $paragraphs->length > 0) {
                $this->print("found " . $paragraphs->length . " for $pattern");
                return $paragraphs;
            }
        }
        $this->print($this->extractPatterns, "found no matching elements");
        return [];
    }
    
    public function connectLines(array $lines) {
        $this->funcName = "connectLines";
        $this->print( count($lines) . " lines" );
        return $lines;
    }
}

// --- BEGIN PARSER CLASSES ---

class TextParse extends HTMLParse {
    public function __construct( $options = [] ) {
        parent::__construct($options);
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
        $sentences = $this->process( [$text] );
        return $sentences;
    }
}

class UlukauLocal extends HTMLParse {
    protected $baseDir = __DIR__ . '/../ulukau';
    protected $pageListFile = "ulukau-books.json";
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->semanticSplit = true;
        $this->options = $options;
        $this->endMarkers[] = "No part of";
        $dir = dirname(__DIR__, 1);
        if( strpos( $dir, "worldspot.com" ) !== false ) {
            $fqdn = "noiiolelo.org";
        } else {
            $fqdn = trim(shell_exec('hostname -f')) . "/noiiolelo";
        }
        $this->urlBase = "https://" . $fqdn . "/ulukau";
        $this->groupname = "ulukau";
        $this->extractPatterns = ['//div[contains(@class, "ulukaupagetextview")]//span', '//body'];
    }
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print("");
        $pageList = json_decode( $this->getRaw( "$this->baseDir/$this->pageListFile", false ), true );
        for( $i = 0; $i < count($pageList); $i++ ) {
            $pageList[$i]['link'] =
                $pageList[$i]['url'] =
                    "$this->urlBase/{$pageList[$i]['oid']}.html";
        }
        return $pageList;
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
        $pattern = '/\.{5,}[\s\x{00A0}\d]*/u';
        if (preg_match($pattern, $sentence, $matches)) {
            $sentence = preg_replace($pattern, '', $sentence);
        }
        return $sentence;
    }
}

class CBHtml extends HtmlParse {
    private $basename = "Ka Ulana Pilina";
    private $documentListPatterns = [
            '//h2[contains(@class, "headline")]/a',
            '//div[contains(@class, "archive")]/p/a'
    ];
    private $skipTitles = [
        "Translating Some",
    ];
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->toSkip = [
            "Kā ka luna hoʻoponopono nota", "Click here", "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana", "ʻO kā Civil Beat kūkala nūhou ʻana i",
        ];
        $this->urlBase = "https://www.civilbeat.org";
        $this->baseurl = "https://www.civilbeat.org/projects/ka-ulana-pilina/";
        $this->logName = "CBHtml";
        $this->groupname = "kaulanapilina";
        $this->extractPatterns = [
            '//div[contains(@class,"cb-share-content")]//p',
             '//body'
        ];
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDomForDiscovery( $this->url );
        $this->extractMetadata( $this->dom );
        $this->metadata['title'] = $this->basename . ' ' . $this->metadata['date'];
        $this->log( "url = " . $this->url . ", date = " . $this->metadata['date'] );
    }
    protected function getDomForDiscovery($url) {
        $html = $this->getRaw($url, false);
        if (!$html) {
            return null;
        }
        return $this->getDOMFromString($html);
    }
    public function checkElement( $p ) {
        $result = ( strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) === false );
        return ($result) ? parent::checkElement( $p ) : '';
    }
    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata";
        $this->metadata['date'] = '';
        if( !$dom ) $dom = $this->dom;
        if( $dom ) {
            $xpath = new DOMXpath( $dom );
            $query = '//meta[contains(@property, "article:published_time")]';
            $p = $xpath->query( $query )->item(0);
            if ($p instanceof DOMElement) {
                $parts = explode( "T", $p->getAttribute( 'content' ) );
                $this->metadata['date'] = $parts[0];
            }
            $this->metadata['sourcename'] =
                $this->basename . ": " . $this->metadata['date'];
            $query = "//h1[@class='page-title']/text()";
            $nodes = $xpath->query($query);
            if ($nodes->length > 0) {
                $this->metadata['title'] = $nodes->item(0)->nodeValue;
            } else {
                $this->metadata['title'] =  $this->metadata['sourcename'];
            }
            $query = "//meta[@property='article:author']/@content";
            $nodes = $xpath->query($query);
            if ($nodes->length > 0) {
                $this->metadata['author'] = $nodes->item(0)->nodeValue;
            }
            $this->print( $this->metadata );
        } else {
            $this->print( "No DOM" );
        }
    }
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $dom = $this->getDomForDiscovery( $this->baseurl );
        $this->extractMetadata( $dom );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( $this->documentListPatterns as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $url = $p->getAttribute( 'href' );
                $pagedom = $this->getDomForDiscovery( $url );
                $this->extractMetadata( $pagedom );
                $ok = true;
                foreach( $this->skipTitles as $skip ) {
                    if( strpos( $this->metadata['title'], $skip) !== false ) {
                        $ok = false;
                        break;
                    }
                }
                if( $ok ) {
                    $pages[] = [
                        'sourcename' => $this->metadata['sourcename'],
                        'url' => $url,
                        'image' => '',
                        'title' => $this->metadata['title'],
                        'groupname' => $this->groupname,
                        'author' => $this->metadata['author'],
                    ];
                }
            }
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->urlBase = 'https://keaolama.org/';
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://keaolama.org/?infinity=scrolling&page=";
        $this->logName = "AoLamaHTML";
        $this->groupname = "keaolama";
        $this->extractPatterns = ['//div[contains(@class, "entry-content")]//p'];
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDomForDiscovery( $this->url );
        $this->extractMetadata( $this->dom );
        $this->metadata['title'] = $this->basename . ' ' . $this->metadata['date'];
    }
    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata";
        if( !$dom ) {
            $dom = $this->dom;
        }
        $this->metadata['date'] = $this->getDateFromUrl( $this->url );
        $this->debugPrint( "found [{$this->metadata['date']}]" );
        $sourceName = (sizeof($parts) > 3) ? "{$this->basename} {$parts[1]}-{$parts[2]}-{$parts[3]}" : $this->basename;
        $this->metadata['sourcename'] = $sourceName;
    }
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $page = 0;
        $pages = [];
        while( true ) {
            $contents = $this->getRaw( $this->baseurl . $page, false );
            $response = json_decode( $contents );
            if( $response->type != 'success' ) {
                break;
            }
            $urls = array_keys( (array)$response->postflair );
            foreach( $urls as $u ) {
                $text = str_replace( $this->urlBase, '', $u );
                $parts = explode( '/', $text );
                if( sizeof($parts) > 3 ) {
                    $text = $parts[0] . "-" . $parts[1] . "-" . $parts[2];
                }
                $date = $this->getDateFromUrl( $u );
                $pages[] = [
                    'sourcename' => $this->basename . ": $text",
                    'url' => $u,
                    'image' => '',
                    'title' => $this->basename . ": $text",
                    'date' => $date,
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
    private $domain = "https://www.staradvertiser.com/";
    protected $sources = [];
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = $this->domain . "category/editorial/kauakukalahale/";
        $this->semanticSplit = true;
        $this->logName = "KauakukalahaleHTML";
        $this->groupname = "kauakukalahale";
        $this->endMarkers = [
            "This column",
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
            "E hoouna ia mai na",
            "E ho‘ouna ‘ia mai na ā leka",
        ];
        $this->toSkip[] = "Synopsis:";
        $this->ignoreMarkers = [
            "Correction:",
        ];
        $this->extractPatterns = [
            '//div[contains(@class, "hsa-paywall")]//p',
        ];
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDomForDiscovery( $this->url );
        $this->extractMetadata( $this->dom );
    }

    public function updateVisibility( $text ) {
        $this->funcName = "updateVisibility";
        if( !$text ) return $text;
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
        if( $changed ) $text = $dom->saveHTML();
        return $text;
    }
    
    public function getRaw( $url, $trackMetadata = true ) {
        $funcName = "getRaw";
        $this->print( $url );
        $text = parent::getRaw( $url, $trackMetadata );
        $text = $this->updateVisibility( $text );
        $nchars = strlen( $text );
        $this->log( "after updateVisibility - $nchars characters" );
        return $text;
    }

    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata"; 
        if( !$dom ) {
            $dom = $this->dom;
        }
        $xpath = null;
        if( $dom ) {
            $xpath = new DOMXpath( $dom );
        }
        if( $this->url ) {
            $this->metadata['date'] = $this->getDateFromUrl( $this->url );
        }
        if( $xpath ) {
            // Title: from <h1 class="story-title">
            $titleNode = $xpath->query("//h1[@class='story-title']");
            $title = $titleNode->length ? trim($titleNode[0]->nodeValue) : '';
            $title = str_replace( "Column: ", "", $title );
            $this->metadata['title'] = $title;

            // Author: from <p class="author custom_byline"> — stripping "By "
            $authorNode = $xpath->query("//p[contains(@class,'author')]");
            $author = $authorNode->length ? trim($authorNode[0]->nodeValue) : '';
            $this->metadata['author'] =
                preg_replace( '/(By na |By |Na )/', '', $author );
        }
        $this->metadata['sourcename'] =
            $this->basename . " " . $this->metadata['date'];
        $this->metadata['title'] = ($this->metadata['title']) ??
                                   $this->metadata['sourcename'];
    }

    public function getContents( $url, $options=[] ) {
        $text = parent::getContents( $url, $options );
        return $this->updateVisibility( $text );
    }

    public function getDocumentList() {
        $this->funcName = "getDocumentList";
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
        $pages = [];
        $url = ($pagenr == 1) ? $this->baseurl :
               $this->baseurl . 'page/' . $pagenr;
        $this->print( "($pagenr): $url" );
        $dom = $this->getDomForDiscovery( $url );
        if( !$dom ) {
            return $pages;
        }
        $xpath = new DOMXpath($dom);
        $query = '//article[contains(@class, "story")]';
        $articles = $xpath->query( $query );
        foreach( $articles as $article ) {
            $query = ".//h3[@class='story-title']//a";
            $titleNode = $xpath->query($query, $article);
            // Href and formatted date
            $url = $titleNode->length ? $titleNode[0]->getAttribute('href') : '';
            if( strpos( $url, "kauakukalahale" ) === false ) {
                continue;
            }

            // Date
            $date = $this->getDateFromUrl( $url );

            $sourcename = $this->basename . ": " . $date;
            if( array_key_exists( $sourcename, $this->sources ) ) {
                // Multiple references to the same article?
                continue;
            }

            // Title
            $title = $titleNode->length ? trim($titleNode[0]->nodeValue) : '';
            $title = str_replace( "Column: ", "", $title );

            // Author (search upward from article)
            $query = ".//li[contains(@class,'custom_byline')]";
            $authorNode = $xpath->query($query, $article->parentNode);
            $author = $authorNode->length ? trim($authorNode[0]->nodeValue) : '';
            $author = preg_replace( '/(^By na |^By |^Na )/i', '', $author );

            // Image URL
            $query = ".//div[contains(@class,'thumbnail')]//img";
            $imgNode = $xpath->query($query, $article);
            $img = $imgNode->length ? $imgNode[0]->getAttribute('src') : '';

            $pages[] = [
                'sourcename' => $sourcename,
                'url' => $url,
                'title' => $title,
                'date' => $date,
                'image' => $img,
                'author' => $author,
                'groupname' => $this->groupname,
            ];

        }
        return $pages;
    }
}

class NupepaHTML extends HtmlParse {
    private $basename = "Nupepa";
    private $domain = "https://nupepa.org/";
    private $skipTitles = [ "Ka Wai Ola - Office of Hawaiian Affairs" ];
    protected $pagetitles = [];
    protected $documentOID = '';
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://nupepa.org/?a=cl&cl=CL2";
        $this->baseurl = 'https://nupepa.org/?a=cl&cl=CL2&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0';
        $this->semanticSplit = true;
        $this->options = $options['preprocess'] ?? false;
        $this->logName = "NupepaHTML";
        $this->groupname = "nupepa";
        $this->extractPatterns = [
            '//sec/div/p', '//p/span', '//td/div/div', '//center/table/tr/td/*',
        ];
    }
    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $html = $this->getRaw( $this->baseurl );
        $crawler = new Crawler($html);
        $results = [];
        $crawler->filter('#datebrowserrichardtoplevelcalendar > div')->each(function ($yearBlock) use (&$results) {
            $yearBlock->filter('a[href*="a=cl&cl=CL2."]')->each(function ($linkNode) use (&$results) {
                $monthUrl = $linkNode->attr('href');
                $monthHtml = $this->getRaw( $this->domain . $monthUrl );
                $monthCrawler = new Crawler($monthHtml);
                $monthCrawler->filter('.datebrowserrichardmonthlevelcalendardaycellcontents')->each(function ($dayCell) use (&$results) {
                    if (!$dayCell->filter('.datebrowserrichardmonthdaynumdocs')->count()) return;
                    $dateText = $dayCell->filter('b.hiddenwhennotsmall')->count() ? trim($dayCell->filter('b.hiddenwhennotsmall')->text()) : null;
                    $dayCell->filter('li.list-group-item')->each(function ($itemNode) use ($dateText, &$results) {
                        $linkNode = $itemNode->filter('a[href*="a=d&"]');
                        if (!$linkNode->count()) return;
                        $titleText = trim($linkNode->text());
                        $ok = true;
                        foreach( $this->skipTitles as $pattern ) {
                            if( strpos( $titleText, $pattern ) !== false ) {
                                $ok = false;
                                break;
                            }
                        }
                        if( $ok ) {
                            $href = $linkNode->attr('href');
                            $fullUrl = 'https://nupepa.org' . $href;
                            $imgNode = $itemNode->filter('img');
                            $imgSrc = $imgNode->count() ? 'https://nupepa.org' . $imgNode->attr('src') : null;
                            $dateText = preg_replace('/^\(\w+, (.*?)\)$/', '$1', $dateText);
                            $results[] = [
                                'sourcename' => "{$titleText} {$dateText}",
                                'url' => $fullUrl,
                                'image' => $imgSrc,
                                'title' => "{$titleText} {$dateText}",
                                'date' => $dateText,
                                'author' => '',
                                'groupname' => 'nupepa',
                            ];
                        }
                    });
                });
            });
        });
        return $results;
    }

    protected function getDocumentPageUrls($pageurl) {
        $this->funcName = "getDocumentPageUrls";
        $firstPageHtml = $this->getRaw($pageurl, false);
        if (!$firstPageHtml) return [$pageurl];

        if (preg_match('/pageTitles.*?\{(.*?)\}/s', $firstPageHtml, $matches)) {
            $pagetitlesJson = "{" . preg_replace("/\n/", "", str_replace("'", '"', $matches[1])) . "}";
            $this->pagetitles = json_decode($pagetitlesJson, true);
        }
        if (preg_match('/"documentOID"\s*:\s*"([^"]+)/', $firstPageHtml, $matches)) {
            $this->documentOID = $matches[1];
        }
        if (empty($this->pagetitles) || empty($this->documentOID)) return [$pageurl];

        parse_str(parse_url($pageurl, PHP_URL_QUERY), $queryParams);
        $eValue = $queryParams['e'] ?? '';
        $urls = [];
        foreach ($this->pagetitles as $key => $title) {
            $url = "https://nupepa.org/?a=d&d=" . $this->documentOID . "." . $key . "&srpos=&dliv=none&st=1";
            if ($eValue) $url .= "&e=" . $eValue;
            $urls[] = $url;
        }
        return $urls;
    }
    
    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata";
        if( !$dom ) $dom = $this->dom;
        $xpath = new DOMXpath($dom);
        $titleNode = $xpath->query( '//head/title' )->item(0);
        if( $titleNode ) {
            $title = htmlentities( $titleNode->nodeValue );
            $this->metadata['title'] = preg_replace( '/ &mdash\;.*/', '', $title );
        }
        if( $this->metadata['title'] ) {
            $parts = explode( " ", $this->metadata['title'] );
            $len = sizeof( $parts );
            $date = "{$parts[$len-3]} {$parts[$len-2]} {$parts[$len-1]}";
            $this->metadata['date'] = date( 'Y-m-d', strtotime( $date ) );
        }
        $this->metadata['sourcename'] = $this->metadata['title'];
    }
    
    public function connectLines(array $lines) {
        $this->funcName = "connectLines";
        $connectors = [ "‘" => "", "ʻ" => "", '/^-{1,2}\s*[A-ZĀĒĪŌŪ ]+\s*-{1,2}$/' => " " ];
        $joined = [];
        $lastWasConnector = false;
        $lastConnectorInsert = '';
        foreach ($lines as $line) {
            $line = trim($line);
            $foundConnector = false;
            $connectorInsert = '';
            foreach ($connectors as $pattern => $insert) {
                if (($pattern[0] === '/' && preg_match($pattern, $line)) || $line === $pattern) {
                    $foundConnector = true;
                    $connectorInsert = $insert;
                    break;
                }
            }
            if ($foundConnector) {
                if (!empty($joined)) $joined[count($joined) - 1] .= $connectorInsert . $line;
                else $joined[] = $line;
                $lastWasConnector = true;
                $lastConnectorInsert = $connectorInsert;
            } else {
                if ($lastWasConnector && !empty($joined)) $joined[count($joined) - 1] .= $lastConnectorInsert . $line;
                else $joined[] = $line;
                $lastWasConnector = false;
            }
        }
        return $joined;
    }
}

// This no longer works because they require human-like navigation to return
// real content
// Instead, a node script runs puppeteer independently and then UlukauLocal
// reads its output later on
class UlukauHTML extends NupepaHTML {
    protected $hostname = "";
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->logName = "UlukauHTML";
        $this->baseurl = "https://puke.ulukau.org/ulukau-books/";
        $this->groupname = "ulukau";
        $this->extractPatterns = ['//div[@id="ulukaupagetextview"]//p', '//p'];
    }

    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $parts = parse_url($baseurl);
        $this->hostname = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
    }
    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $dom = $this->getDomForDiscovery( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $pages = [];
        $host = $this->hostname;
        $nodes = $xpath->query('//div[contains(@class, "ulukaubooks-book-browser-row") and @data-title]');
        foreach ($nodes as $node) {
            if (!$node->hasAttribute('data-title')) continue;
            $language = $node->getAttribute('data-language');
            if( $language != 'haw' ) continue;
            $title = html_entity_decode(trim($node->getAttribute('data-title')), ENT_QUOTES, 'UTF-8');
            $authorNode = $xpath->query('.//div[contains(@class,"la")][contains(., "Author")]', $node)->item(0);
            $author = $authorNode ? (preg_match('/Author\(s\):\s*(.+)/u', $authorNode->textContent, $matches) ? html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8') : '') : '';
            $linkNode = $xpath->query('.//div[contains(@class,"tt")]//a', $node)->item(0);
            $rel_url = $linkNode ? html_entity_decode(trim($linkNode->getAttribute('href')), ENT_QUOTES, 'UTF-8') : '';
            $full_url = $rel_url ? rtrim($host, '/') . $rel_url : $this->baseurl;
            $imgNode = $xpath->query('.//img[contains(@class,"lozad")]', $node)->item(0);
            $imagePath = $imgNode ? html_entity_decode(trim($imgNode->getAttribute('data-src')), ENT_QUOTES, 'UTF-8') : '';
            $full_image = $imagePath ? rtrim($host, '/') . $imagePath : '';
            preg_match('/d=([^&]+)/', $rel_url, $keyMatch);
            $sourcename = "Ulukau: " . ($keyMatch[1] ?? uniqid());
            $pages[] = [
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
}

// The website no longer exists as of 2025, so we can no longer pull
// documents from there, but we have them in our database
class KaPaaMooleloHTML extends HtmlParse {
    private $basename = "Ka Paa Moolelo";
    private $domain = "https://www2.hawaii.edu/~kroddy/";
    protected $baseurls = [
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_kaao.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/papa_moolelo.htm",
        "https://www2.hawaii.edu/~kroddy/moolelo/kaao_unuhiia.htm",
    ];
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->endMarkers = [ "HOPENA" ];
        $this->urlBase = trim( $this->domain, "/" );
        $this->options =  ['continue'=>false];
        $this->logName = "KaPaaMooleloHtml";
        $this->groupname = "kapaamoolelo";
        $this->extractPatterns = ['//p'];
    }
    private function validLink( $url ) {
        return strpos( $url, "file:" ) === false && strpos( $url, "mailto:" ) === false &&
               strpos( $url, "papa_kuhikuhi" ) === false &&
               strpos( implode( "", $this->baseurls ), $url ) === false;
    }
    
    public function getOneDocumentList( $url ) {
        $pages = [];
        $dom = $this->getDomForDiscovery( $url );
        $xpath = new DOMXpath($dom);
        $query = '//p/a|//p/font/a';
        $paragraphs = $xpath->query( $query );
        foreach( $paragraphs as $p ) {
            $url = $p->getAttribute( 'href' );
            if( $this->validLink( $url ) ) {
                $sourcename =
                    $this->basename . ": " .
                    preg_replace( "/\s+/", " ", preg_replace( "/\n|\r/", " ", $p->firstChild->nodeValue ) );
                $pages[] = [
                    'sourcename' => $sourcename,
                    'url' => $this->domain . "moolelo/" . $url,
                    'title' => $sourcename,
                    'image' => '',
                    'author' => $this->authors,
                    'groupname' => $this->groupname,
                ];
            }
        }
        return $pages;
    }
    
    public function getDocumentList() {
        $pages = [];
        // Return an empty list to prevent crawling
        return $pages;
        foreach( $this->baseurls as $url ) {
            $pages = array_merge( $pages, $this->getOneDocumentList( $url ) );
        }
        return $pages;
    }

    protected function getDocumentPageUrls($initialUrl) {
        $urls = [];
        $currentUrl = $initialUrl;
        $processedUrls = [];
        while ($currentUrl && !in_array($currentUrl, $processedUrls)) {
            $urls[] = $currentUrl;
            $processedUrls[] = $currentUrl;
            $html = $this->getRaw($currentUrl, false);
            if (!$html) break;
            $dom = $this->getDOMFromString($html);
            $xpath = new DOMXpath($dom);
            $nextNode = $xpath->query("//a[contains(., '>>')]")->item(0);
            if ($nextNode) {
                $href = $nextNode->getAttribute('href');
                if ($href && $href !== '#') {
                    $currentUrl = rtrim(dirname($currentUrl), '/') . '/' . $href;
                } else {
                    $currentUrl = null;
                }
            } else {
                $currentUrl = null;
            }
        }
        return $urls;
    }
}

class BaibalaHTML extends HtmlParse {
    private $basename = "Baibala";
    private $documentname = "Baibala (full bible, 2012 edition)";
    private $domain = "https://baibala.org/";
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl =
            "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-";
        $this->semanticSplit = true; // Use semantic splitting
        $this->logName = "BaibalaHTML";
        $this->groupname = "baibala";
        $this->extractPatterns = ['//td', '//body'];
    }
    
    public function extractMetadata( $dom = null ) {
        if (!$dom) $dom = $this->dom;
        $this->metadata['title'] = $this->documentname;
        $this->metadata['sourcename'] = $this->documentname;
        $this->metadata['date'] = '2012-01-01';
    }
    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print( "" );
        // It's a single document
        $pages[] = [
            'sourcename' => $this->documentname,
            'url' => $this->baseurl,
            'title' => $this->documentname,
            'image' => '',
            'author' => '',
            'groupname' => $this->groupname,
        ];
        return $pages;
    }

    protected function getDocumentPageUrls($initialUrl) {
        $urls = [];
        $currentUrl = $initialUrl;
        $processedUrls = [];
        while ($currentUrl && !in_array($currentUrl, $processedUrls)) {
            $urls[] = $currentUrl;
            $processedUrls[] = $currentUrl;
            $html = $this->getRaw($currentUrl, false);
            if (!$html) break;
            $dom = $this->getDOMFromString($html);
            $xpath = new DOMXpath($dom);
            $nextNode = $xpath->query('//a[contains(@class, "right")]')->item(0);
            if ($nextNode) {
                $href = $nextNode->getAttribute('href');
                if ($href && $href !== '#') {
                    $currentUrl = $this->urlBase . "/" . ltrim($href, '/');
                } else {
                    $currentUrl = null;
                }
            }
            else {
                $currentUrl = null;
            }
        }
        return $urls;
    }
}

class EhoouluLahuiHTML extends HtmlParse {
    private $basename = "E Hooulu Lahui";
    private $domain = "https://ehooululahui.com/";
    public function __construct( $options = [] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = $this->domain;
        $this->logName = "EhoouluLahuiHTML";
        $this->groupname = "ehooululahui";
        $this->extractPatterns =
            ['//div[contains(@class, "cmsmasters_text")]/p'];
    }

    public function extractMetadata( $dom = null ) {
        if (!$dom) $dom = $this->dom;
        $xpath = new DOMXpath($dom);
        $titleNode = $xpath->query('//h1[contains(@class, "entry-title")]')->item(0);
        if ($titleNode) {
            $this->metadata['title'] = $titleNode->nodeValue;
        }
        $this->metadata['sourcename'] = "{$this->basename}: {$this->metadata['title']}";
    }
    
    public function getDocumentList() {
        $this->funcName = "getPageList";
        $this->print( "" );
        // Only a few of the documents there are really interesting and it is difficult to
        // determine automatically, so just list them here.
        $indexes = [
            [
                'title' => '‘Aukelenuia‘īkū',
                'id' => 65,
            ],
            [
                'title' => 'Lonoikamakahiki',
                'id' => 67,
            ],
            [
                'title' => 'Punia',
                'id' => 69,
            ],
            [
                'title' => 'Umi',
                'id' => 71,
            ],
            [
                'title' => 'Kaao no Aiai',
                'id' => 265,
            ],
            [
                'title' => 'Kaao no Eleio',
                'id' => 261,
            ],
            [
                'title' => 'Cinikalela',
                'id' => 96,
            ],
            [
                'title' => 'Ka Makani Kaili Aloha',
                'id' => 268,
            ],
            [
                'title' => 'Lupekapuikeahomakaliʻi',
                'id' => 279,
            ],
            [
                'title' => 'Kapaʻahu',
                'id' => 283,
            ],
            [
                'title' => 'No Hema',
                'id' => 291,
            ],
            [
                'title' => 'Waiʻānapanapa',
                'id' => 296,
            ],
        ];
        $pages = [];
        foreach( $indexes as $index ) {
            $pages[] = [
                'sourcename' => $index['title'],
                'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=' . $index['id'],
                'image' => '',
                'title' => $index['title'],
                'author' => '',
                'groupname' => $this->groupname,
            ];
        }
        return $pages;
                
        
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

    protected function getDocumentPageUrls($initialUrl) {
        $this->funcName = "getDocumentPageUrls";
        $this->print($initialUrl);

        $html = $this->getRaw($initialUrl, false);
        if (!$html) {
            return [$initialUrl]; // Return the base URL if fetching fails
        }

        $dom = $this->getDOMFromString($html);
        $xpath = new DOMXpath($dom);

        $query = '//div[@class="cmsmasters_text"]/h3/a';
        $linkNodes = $xpath->query($query);

        $urls = [];
        foreach ($linkNodes as $linkNode) {
            if (strpos(trim($linkNode->nodeValue), 'Mokuna ') === 0) {
                $urls[] = $linkNode->getAttribute('href');
            }
        }

        // If no chapter links are found, it might be a single-page document.
        if (empty($urls)) {
            return [$initialUrl];
        }

        return $urls;
    }
}

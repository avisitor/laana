<?php
include_once __DIR__ . '/../db/funcs.php';
include_once __DIR__ . '/ContentFetcher.php';
include_once __DIR__ . '/DomParser.php';
include_once __DIR__ . '/TextProcessor.php';

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
    protected $contentFetcher;
    protected $domParser;
    protected $textProcessor;
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
        "Javascript",
        "Internet",
        "Scripting",
        "Warning",
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
    // Add a configurable max sentence length
    const MAX_SENTENCE_LENGTH = 1000;
    const RESPLIT_SENTENCE_LENGTH = 400;
    public function __construct( $options = [] ) {
        $this->options = $options;
        $this->contentFetcher = new ContentFetcher();
        $this->domParser = new DomParser();
        $this->textProcessor = new TextProcessor();
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
        $this->dom = $this->getDomForDiscovery( $this->url );
        $this->extractMetadata( $this->dom );
    }
    public function getDateFromURL( $url ) {
        preg_match('/\/(\d{4})\/(\d{2})\/(\d{2})\//', $url, $matches);
        $date =
            isset($matches[1], $matches[2], $matches[3]) ?
            "{$matches[1]}-{$matches[2]}-{$matches[3]}" : '';
        $this->log( "getDateFromUrl($url) = $date" );
        return $date;
    }
    public function getRaw($url, $trackMetadata = true) {
        $this->funcName = "getRaw";
        $this->print($url);
        $html = $this->contentFetcher->fetch($url);

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
        $this->funcName = "getDOMFromString";
        $this->print( strlen($text) . " characters" );
        return $this->domParser->parse($text);
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
            printObject("Rejected [too few words: " . str_word_count($sentence) . "]: " . substr($sentence, 0, 200), $this->formatLog(""));
            return false;
        }
        if (preg_match_all('/[^\d\s]/u', $sentence, $matches) < 3) {
        }
        if (preg_match('/^No\.?[\d[:punct:]]*$/u', trim($sentence))) {
            printObject("Rejected [matches 'No.' pattern]: " . substr($sentence, 0, 200), $this->formatLog(""));
            return false;
        }
        if (preg_match('/^P\.?[\d[:punct:]]*$/u', trim($sentence))) {
            printObject("Rejected [matches 'P.' pattern]: " . substr($sentence, 0, 200), $this->formatLog(""));
            return false;
        }
        foreach( $this->toSkip as $pattern ) {
            if( strpos( $sentence, $pattern ) !== false ) {
                printObject("Rejected [toSkip pattern '$pattern']: " . substr($sentence, 0, 200), $this->formatLog(""));
                return false;
            }
        }
        foreach( $this->skipMatches as $pattern ) {
            if( $sentence === $pattern ) {
                printObject("Rejected [skipMatches exact match]: " . substr($sentence, 0, 200), $this->formatLog(""));
                return false;
            }
        }
        return true;
    }
    
    public function cleanSentence( $sentence ) {
        $this->funcName = "cleanSentence";
        return $this->textProcessor->cleanSentence($sentence);
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
        $this->print( strlen($text) . " characters" );
        if( !$text ) {
            return $text;
        }
        return $this->textProcessor->convertEncoding($text);
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
        return $this->textProcessor->protectAbbreviations($text);
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
        $this->debugPrint( count($lines) . " lines after checkSentence" );
        return $lines;
    }
    
    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters in line" );
        return $this->textProcessor->splitLines($text);
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
            if ( preg_match('/^[A-Z][a-z]*\.?(-[a-z, ]+)?$/u', $buffer) || preg_match('/^[A-Z]\.$/u', $buffer) || preg_match('/^[A-Z]\.-$/u', $buffer) ) {
                while ( $j < $n && ( preg_match('/^[A-Z]\.$/u', trim($lines[$j])) || preg_match('/^[A-Z]\.-$/u', trim($lines[$j])) || preg_match('/^[A-Z][a-z]*\.?(-[a-z, ]+)?$/u', trim($lines[$j])) || (mb_strlen(trim($lines[$j])) <= 30 && preg_match('/^[A-Z]/u', trim($lines[$j]))) ) ) {
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
        
        // For Ulukau content with <nobr> elements, we need to concatenate fragments
        $currentParagraph = '';
        
        for ($idx = 0; $idx < $count; $idx++) {
            $node = $contents[$idx];
            if (!($node instanceof DOMNode)) {
                $this->debugPrint("Skipping non-DOMNode at index $idx");
                continue;
            }
            
            // Get the HTML to check for nobr elements
            $html = $node->ownerDocument->saveHTML($node);
            $text = $this->checkElement($node);
            
            if ($text !== '') {
                // Check if this is a nobr fragment that should be concatenated
                if (strpos($html, '<nobr>') !== false) {
                    // This is a nobr element - add to current paragraph
                    if ($currentParagraph !== '') {
                        $currentParagraph .= ' ';
                    }
                    $currentParagraph .= trim($text);
                    
                    // Check if this completes a sentence (ends with . ! ?)
                    if (preg_match('/[.!?]\s*$/', $currentParagraph)) {
                        $paragraphs[] = $currentParagraph;
                        $currentParagraph = '';
                    }
                } else {
                    // Regular element - finish any pending paragraph first
                    if ($currentParagraph !== '') {
                        $paragraphs[] = $currentParagraph;
                        $currentParagraph = '';
                    }
                    $paragraphs[] = $text;
                }
            }
        }
        
        // Add any remaining paragraph
        if ($currentParagraph !== '') {
            $paragraphs[] = $currentParagraph;
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
            error_log("DEBUG process(): About to filter " . count($lines) . " lines");
            foreach ($lines as $line) {
                if ($this->checkSentence($line)) {
                    $line = preg_replace('/
+/u', ' ', $line);
                    $line = preg_replace('/
+([\'
‘])\n+/u', '$1', $line);
                    $line = substr($line, 0, self::MAX_SENTENCE_LENGTH);
                    if( $this->containsEndMarker( $line ) ) {
                        break;
                    }
                    $finalLines[] = $line;
                    error_log("ACCEPTED: " . substr($line, 0, 100));
                } else {
                    $this->discarded[] = $line;
                    error_log("REJECTED by checkSentence: " . substr($line, 0, 100));
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
            $text = preg_replace('/
+/u', ' ', $text);
            if (trim($text) === '') continue;
            $text = preg_replace('/[\n\r]+/u', ' ', $text); 
            $text = preg_replace('/\n+([?!])/u', '$1', $text);
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
        $text = $this->preprocessHTML( $text );
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
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
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

class UlukauHTML extends HTMLParse {
    protected $boilerplatePatterns = [];
    protected $publisherKeywords = [];
    private $base = 'http://localhost:3000/';
    private $docUrl;
    private $metadataUrl;
    private static $hawaiianWords = null;
    
    /**
     * Normalize Hawaiian diacritics for comparison
     * Removes macrons (ā→a) and ʻokina (ʻ→empty)
     * Input should already be lowercased
     */
    private static function normalizeDiacritics($text) {
        $replacements = [
            'ā' => 'a', 'ē' => 'e', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u',
            'ʻ' => '', '`' => ''
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
    
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->logName = "UlukauHtml";
        $this->docUrl = $this->base . 'doc?url=';
        
        // Load Hawaiian word list once
        if (self::$hawaiianWords === null) {
            $wordFile = __DIR__ . '/../../elasticsearch/hawaiian_words.txt';
            if (file_exists($wordFile)) {
                $words = file($wordFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // Create a set for fast lookup, normalize to lowercase and remove diacritics
                self::$hawaiianWords = [];
                foreach ($words as $w) {
                    $w = trim($w);
                    if (!empty($w) && substr($w, 0, 1) !== '-' && substr($w, 0, 9) !== 'raw_head') {
                        $normalized = self::normalizeDiacritics(strtolower($w));
                        self::$hawaiianWords[$normalized] = true;
                    }
                }
            } else {
                self::$hawaiianWords = [];
            }
        }
        $this->metadataUrl = $this->base . 'metadata';
        $this->semanticSplit = true;
        // Removed incorrect "No part of" end marker - copyright text should be filtered as boilerplate, not end processing
        
        // Enhanced boilerplate detection patterns
        $this->boilerplatePatterns = [
            // Copyright and publication info
            '/No part of this book may be reproduced/u', // English copyright text
            '/The Center provides professional and material resources necessary to address this goal/u', // Institutional description
            '/Kuleana Kope|nona nā kuleana a pau|ʻAʻole e hana kope/u',
            '/Hale Kuamoʻo.*University of Hawaiʻi/u',
            '/Ka Haka ʻUla O Keʻelikōlani/u',
            '/Paʻi ʻia e ka.*Kulanui o Hawaiʻi/u',
            '/Hoʻopuka ʻia e ka.*Hale Kuamoʻo/u',
            '/Hoʻopuka ʻia e/u', // Published by
            '/Ko.*olauloa Early Education Program/u',
            '/KA HUI SIWILA HAWAIʻI O KOʻOLAULOA/u',
            
            // Contact information
            '/\(\d{3}\)\s*\d{3}-\d{4}/u', // Phone numbers
            '/\d+ West .* Street.*Hilo.*Hawaii.*\d{5}/u', // Addresses
            '/@.*\.edu|www\./u', // Email/web addresses
            
            // Author/editor credits  
            '/Kākau a paʻi kiʻi ʻia na/u',
            '/Hoʻokele ʻia na.*lāua/u',
            '/Loihape ʻia na/u',
            '/Hakulau ʻia na.*lāua/u',
            
            // Funding and institutional acknowledgments
            '/ke kālā haʻawina na ka.*Pekelala/u',
            '/ʻOihana Hoʻonaʻauao Pekelala/u',
            '/University of Hawaiʻi Foundation/u',
            '/Inā makemake ʻoe e kākoʻo/u',
            
            // Generic publisher boilerplate
            '/Na ka Hale Kuamoʻo e hoʻomohala/u',
            '/Ua hoʻokumu ʻia ka Hale Kuamoʻo/u',
            '/ʻO ka Hale Kuamoʻo ke keʻena/u',
            
            // New patterns for book-specific content
            '/^For .* and .* in our lives/u', // Dedication text
            '/\.indd \d+ \d+\/\d+\/\d+ \d+:\d+:\d+ [AP]M/u', // File metadata
            '/\w+ Book v\d+\.\d+\.indd/u', // Generic book file metadata pattern
            '/Kamehameha Schools.*established.*\d{4}/u', // School history
            '/Students benefit from.*classrooms/u', // Educational descriptions
            '/What happens after.*\?/u', // Study questions
            '/Why does.*\?/u', // Study questions
            '/How can.*\?/u', // Study questions
            '/FIVE TIPS|FIve tIPs/u', // Educational sections
            '/kaMehaMeha PUblIshIng/u', // Publisher names
            '/Amplifying Hawaiian Perspectives/u', // Publisher taglines
            '/^Printed in [A-Z][a-z]+$/u', // "Printed in Korea" etc.
            '/^ISBN:\s*\d+/u', // ISBN numbers
            '/ISBN \d+-\d+-\d+-\d+/u', // ISBN format like "0-9760892-1-1"
            '/He Aha Ka Mea .*Ai No Ka .*Aina Awakea\?/u', // Educational question pattern
            '/Kuleana Kope.*\d{4}/u', // Copyright with year
            
            // Enhanced patterns for severely corrupted text formatting
            '/^[A-Z\s]{20,}$/u', // Lines of mostly uppercase letters and spaces
            '/^.*A division of Kamehameha Schools.*$/u', // Publisher attribution
            '/^.*Hale Kuamo.o.*$/u', // Publisher attribution
            '/^[A-Z\s\']{30,}$/u', // Long strings of uppercase, spaces, and apostrophes
            '/Printed in [A-Z][a-z]+\s*\$\d+\.\d+/u', // "Printed in China $14.95"
            '/^Ho omohala ia na ka Hale Kuamo o/u', // Publishing boilerplate with spacing issues
            '/^MALIA KRUGER.*$/u', // Corrupted author/title metadata - eliminate entirely
            '/Inquiries should be addressed to:/u', // Contact info
            
            // School and institutional descriptions
            '/Its mission: ka mālama/u', // Mission statements
            '/Founded in \d{4}.*laboratory public charter school/u', // School founding descriptions
            '/The school partners with community groups/u', // Partnership descriptions
            '/The school carries the name of.*\(\d{4}–\d{2,4}\)/u', // Biographical information
            '/.*life symbolizes integral qualities.*contemporary Native Hawaiians/u', // Cultural aspirations
            '/aboUt thIs book.*cReatIon/u', // Book creation sections
            '/The story was developed to create meaningful connections/u', // Educational methodology
            '/students learned about composition.*texture/u', // Art curriculum descriptions
            '/Haumāna were guided through.*scaffolding/u', // Teaching methodology
            '/students endured draft after draft/u', // Process descriptions
            '/Born was a story based on moʻokaʻao/u', // Story origin explanations
            '/According to Kumu.*creative drawing and writing processes/u', // Teacher quotes
            '/children discovered that the retelling/u', // Learning outcomes
            '/Today, Kamehameha Schools is a comprehensive/u', // Current institutional descriptions
            '/Kamehameha Schools also provides.*additional students/u', // Services descriptions
            '/no ke kUla$/u', // Section headers
            
            // Song book specific patterns
            '/^SONG TO HAWAIIAN\.$/u', // English song titles
            '/HAWAIIAN MISSION CHILDREN\'S SOCIETY/u', // Organization names
            '/^The wind from over the sou$/u', // English song lyrics start
            '/^Sing\'s sweetly aloha to me$/u', // English song lyrics
            '/^The waves as they fall on the sand$/u', // English song lyrics
            '/^[A-Z][A-Z ]+\.$/', // All caps titles like "PUA MELEKULE."
            
            // Academic/institutional publishing patterns
            '/Historical Society \d+ [A-Z][a-z]+ Street/u', // Street addresses
            '/Hawaiian Historical Society/u', // Organization names
            '/Hawaiʻi and the Pacific Islands\./u', // Geographic descriptions
            '/CommitteeHawaiian Historical/u', // Committee variations
            '/For this assistance we thank [A-Z][a-z]+/u', // Acknowledgment patterns
            '/We are grateful to Dr\./u', // Gratitude expressions
            '/Hawaiian language newspapers carried out by/u', // Research descriptions
            '/withfunding from the University/u', // Funding acknowledgments
            '/Hawai-ian Historical Society\./u', // Society references with hyphens
            '/Honolulu, Hawaiʻi \d{5}/u', // City, state, zip format
            '/Photographer unknown\./u', // Photo credits
            '/Courtesy [A-Z][a-z]+ [A-Z]\. [A-Z][a-z]+\./u', // Courtesy credits
            '/Hawaiian Language Reprint/u', // Publication series
            '/Foundation$/u', // Foundation names
            '/Committee$/u', // Committee names
            '/Publications$/u', // Publication departments
            '/ACKNOWLEDGMENTS/u', // Section headers
            '/FOREWORD/u', // Section headers
            '/INTRODUCTION/u', // Section headers
            '/FOR FURTHER STUDY/u', // Bibliography sections
            '/Ph\.D\. dissertation/u', // Academic citations
            '/University Press/u', // Publisher names
            '/Originally published:/u', // Reprint citations
            '/Manuscript in Library of Congress/u', // Archive references
            '/Hawaiian Journal of History/u', // Journal names
            '/Associated University Presses/u', // Publisher names
            '/^\d{2}—\d{2}\.$/u', // Page ranges like "27—42."
            '/^\[\d{4}—\d{2}\]$/u', // Year ranges in brackets
            '/^Page$/u', // Page headers
            '/^Date$/u', // Date headers
            '/palapala mele\/sheet music/u', // Sheet music references
            '/^Ka [A-Z][a-z]+ \d{2}-\d{2}-\d{4}$/u', // Hawaiian newspaper citations with dates
            '/^ʻaoʻao inoa mele paʻi ʻia ma lā$/u', // Hawaiian citation format
            '/^[A-Z][a-z]+ \d{2}-\d{2}-\d{4} \d+ [A-Z][a-z]+$/u', // Publication citations
            '/San Francisco: [A-Z][a-z]+ and [A-Z][a-z]+ Model$/u', // Publisher citations
            '/Honolulu: [A-Z][a-z]+ [A-Z][a-z]+, \d{4}/u', // Honolulu publisher citations
            '/Washington, D\.C\., \d{4}\.$/u', // DC publication citations
            '/Rutland, VT: [A-Z][a-z]+ [A-Z]\. [A-Z][a-z]+/u', // Vermont publisher citations
            '/Video, \d+ min\., \d{4}\.$/u', // Video production info
            '/^\d+ \([A-Z][a-z]+\), \d+—\d+\.$/u', // Journal volume/page citations
            '/\[\s*[a-z]+\s*\]$/u', // Page markers in brackets
            '/Lāʻie: Institute for Polynesian Studies/u', // Institute citations
            '/^Committee is assisting$/u', // Committee references
            '/These purposes are not limited to particular/u', // Mission statements
            '/^Membership is open to all$/u', // Membership statements
            '/Associate$/u', // Academic titles
            '/Curator, Hawaiian Collection/u', // Job titles
            '/Hamilton Library, University/u', // Institution names
            '/teacher at t$/u', // Incomplete teacher references
            '/and [A-Z][a-z]+ [A-Z][a-z]+, with$/u', // Name lists with prepositions
            
            // Bishop Museum and institutional references
            '/Bishop.*Museum/u', // Bishop Museum references
            '/^\s*Bishop\s*\[\s*[xiv]+\s*\]\s*Museum\s*$/u', // "Bishop [ xxiii ] Museum"
            
            // Newspaper publication patterns
            '/^Published in Date\s*\d*\s*[A-Z][a-z]*$/u', // "Published in Date 1 Mele"
            '/^Ka Makaainana \d{2}-\d{2}-\d{4}\s*\d*\s*[A-Z][a-z]*$/u', // "Ka Makaainana 05-06-1895 7 Hua"
            '/^Spencer \d{4}\s*\d*\s*[A-Z][a-z]*$/u', // "Spencer 1895 15 Aloalo"
            '/^Nakanaela-[IVX]+ \d{4}\s*\d*\s*[A-Z][a-z\s]*$/u', // "Nakanaela-VI 1890 48 Lei"
            '/^Ka Lei Momi \d{2}-\d{2}-\d{4}\s*[A-Z][a-z]*$/u', // "Ka Lei Momi 08-17-1893 Ka"
            '/^Momi \d{2}-\d{2}-\d{4}.*ʻaoʻao inoa mele/u', // "Momi 08-21-1893 ʻaoʻao inoa mele paʻi ʻia ma lā Page"
            '/Typescript edited by.*Hart.*King.*Hawaiʻi State/u', // Typescript editing credits
            '/Archives.*Honolulu.*———\./u', // Archive citations
            '/^[A-Z][a-z]+ O [A-Z][a-z]+$/u', // Hawaiian titles like "Nihoniho O Uwesanana"
            '/^[A-Z][a-z]+ O Ka [A-Z][a-z]+ [A-Z][a-z]+$/u', // "Ala O Ka Mea Pohihihi"
        ];
        
        $this->publisherKeywords = [
            'Hale Kuamoʻo', 'University of Hawaiʻi', 'Kulanui o Hawaiʻi',
            'Ka Haka ʻUla O Keʻelikōlani', 'Kuleana Kope', 'copyright',
            'Kelepona:', 'Kelepaʻi:', 'hale kuamoo@', 'www.olelo.hawaii.edu',
            'Kamehameha Schools', 'Princess Bernice Pauahi', 'charter school',
            'kindergarten', 'first-grade', 'classroom', 'students', 'teachers',
            'Koʻolauloa Early Education Program', 'KA HUI SIWILA HAWAIʻI',
            'Hoʻopuka ʻia e', 'ISBN', 'Kaipāpaʻu', 'Aina Awakea',
            'mission', 'founded', 'laboratory', 'curriculum', 'community groups',
            'Paepae o Heʻeia', 'Aloha ʻĀina Health Center', 'Ulupō Heiau',
            'Samuel Mānaiakalani Kamakau', 'genealogist', 'historian', 'legislator',
            'composition', 'color blending', 'scaffolding', 'vocabulary', 'fluency',
            'moʻokaʻao', 'oral tradition', 'comprehensive educational system',
            'preschool sites', 'financial aid', 'scholarships',
            
            // Academic/institutional publishing keywords
            'Historical Society', 'Foundation', 'Committee', 'Publications',
            'acknowledgments', 'foreword', 'introduction', 'bibliography',
            'dissertation', 'University Press', 'originally published',
            'manuscript', 'library', 'congress', 'archives', 'journal',
            'associated', 'presses', 'photographer', 'courtesy', 'reprint',
            'series', 'street', 'address', 'zip', 'cover', 'photograph',
            'assistance', 'grateful', 'thank', 'funding', 'curator', 'collection',
            'hamilton', 'mānoa', 'associate', 'professor', 'research', 'carried out',
            'newspapers', 'basham', 'bobbit', 'makekau', 'stillman', 'pacific islands',
            
            // Museum and newspaper references
            'Bishop Museum', 'Museum', 'Archives', 'Honolulu', 'typescript',
            'edited by', 'Ka Makaainana', 'Spencer', 'Nakanaela', 'Ka Lei Momi',
            'Momi', 'published in', 'date', 'ʻaoʻao inoa mele', 'paʻi ʻia ma lā'
        ];
        
        $dir = dirname(__DIR__, 1);
        if( strpos( $dir, "worldspot.com" ) !== false ) {
            $fqdn = "noiiolelo.org";
        } else {
            $fqdn = trim(shell_exec('hostname -f')) . "/noiiolelo";
        }
        $this->urlBase = "https://" . $fqdn . "/ulukau";
        $this->groupname = "ulukau";
        $this->extractPatterns = [
            '//div[contains(@class, "ulukaupagetextview")]//div[contains(@class, "pBodycopy")]',
            '//div[contains(@class, "ulukaupagetextview")]//p',
            '//div[contains(@class, "ulukaupagetextview")]//td//div',
            '//div[contains(@class, "ulukaupagetextview")]//span',
            '//div[contains(@class, "ulukaupagetextview")]',
            '//body'
        ];
    }
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print("");
        $pageList = json_decode( $this->getRaw(
            $this->metadataUrl, false ), true );
        /*
        for( $i = 0; $i < count($pageList); $i++ ) {
            $pageList[$i]['link'] =
                $pageList[$i]['url'] =
                    "$this->urlBase/{$pageList[$i]['oid']}.html";
        }
        */
        return $pageList;
    }
    public function getContents( $url ) {
        $url = $this->docUrl . urlencode( $url );
        return parent::getContents( $url );
    }

    public function preprocessHTML( $text ) {
        $this->funcName = "preprocessHTML";
        $this->print( (($text) ? strlen($text) : 0) . " characters)" );
        if( !$text ) {
            return "";
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/
{2,}/u', " ", $text);
        $lines = preg_split('/
/u', $text);

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
        
        // Remove file metadata that gets appended to sentences
        // Pattern: "Hauwahine Book v4.2.indd 3 7/9/08 3:44:03 PM" or similar
        $sentence = preg_replace('/\s+\w+ Book v\d+\.\d+\.indd \d+ \d+\/\d+\/\d+ \d+:\d+:\d+ [AP]M.*$/u', '', $sentence);
        
        // Remove other file metadata patterns
        $sentence = preg_replace('/\s+\w+\.indd \d+ \d+\/\d+\/\d+ \d+:\d+:\d+ [AP]M.*$/u', '', $sentence);
        
        // Fix malformed double-diacritic patterns from OCR errors
        // Examples: "kanuiaāi" should be "kanuia ai", "huiiaāi" should be "huiia ai"
        // Also handles cases with embedded space/quote characters: "kanuia ™i" -> "kanuia ai"
        $sentence = preg_replace('/([aeiou])iaā.{0,3}i/u', '$1ia ai', $sentence);
        
        // Remove trailing section/page numbers (e.g. "49.", "50.", "53.")
        $sentence = preg_replace('/\s+\d{1,3}\.\s*$/u', '', $sentence);
        
        // Remove trailing page numbers and metadata
        $pattern = '/\. {5,}[\s\x{00A0}\d]*/u';
        if (preg_match($pattern, $sentence, $matches)) {
            $sentence = preg_replace($pattern, '', $sentence);
        }
        
        return trim($sentence);
    }
    
    /**
     * Check if a sentence appears to be boilerplate/publisher content
     */
    protected function isBoilerplate($sentence) {
        $result = $this->checkBoilerplate($sentence);
        return $result['is_boilerplate'];
    }
    
    protected function checkBoilerplate($sentence) {
        $sentence = trim($sentence);
        
        // Skip very short sentences that are likely not meaningful content
        // But be more lenient for Hawaiian content - allow shorter meaningful phrases
        if (strlen($sentence) < 8) {
            return ['is_boilerplate' => true, 'reason' => 'too_short (<8 chars)'];
        }
        
        // Allow short Hawaiian phrases but filter out obvious fragments
        if (strlen($sentence) < 20) {
            // Check Hawaiian word list for short phrases
            if (!empty(self::$hawaiianWords)) {
                $words = preg_split('/\s+/u', strtolower($sentence));
                $hawaiianWordCount = 0;
                foreach ($words as $word) {
                    $word = preg_replace('/[^\w]/u', '', $word);
                    $normalized = self::normalizeDiacritics($word);
                    if (strlen($word) > 1 && isset(self::$hawaiianWords[$normalized])) {
                        $hawaiianWordCount++;
                    }
                }
                // If at least 2 Hawaiian words or 50% of words are Hawaiian, allow it
                if ($hawaiianWordCount >= 2 || ($hawaiianWordCount / max(1, count($words))) >= 0.5) {
                    return ['is_boilerplate' => false, 'reason' => 'Hawaiian words found'];
                }
            }
            
            // Allow if it contains Hawaiian content indicators
            if (preg_match('/[ʻāēīōū]|\\b(ka|ke|o|a|i|ma|no|me|lā|na|ia|eia|oia)\\b/u', $sentence)) {
                // But still filter out obvious English fragments
                if (preg_match('/^(written by|illustrated by|edited by|translated by|and|by)$/i', $sentence)) {
                    return ['is_boilerplate' => true, 'reason' => 'English fragment'];
                }
                return ['is_boilerplate' => false, 'reason' => 'Hawaiian content']; // Allow Hawaiian content
            }
            return ['is_boilerplate' => true, 'reason' => 'short non-Hawaiian (<20 chars)']; // Filter out other short content
        }
        
        // Check against boilerplate patterns
        foreach ($this->boilerplatePatterns as $pattern) {
            if (preg_match($pattern, $sentence)) {
                // Special handling for all-caps pattern - check if it contains Hawaiian words
                if ($pattern === '/^[A-Z][A-Z ]+\.$/') {
                    if (!empty(self::$hawaiianWords)) {
                        $words = preg_split('/\s+/u', strtolower($sentence));
                        $hawaiianWordCount = 0;
                        foreach ($words as $word) {
                            $word = preg_replace('/[^\w]/u', '', $word);
                            $normalized = self::normalizeDiacritics($word);
                            if (strlen($word) > 1 && isset(self::$hawaiianWords[$normalized])) {
                                $hawaiianWordCount++;
                            }
                        }
                        // If contains Hawaiian words, don't reject it
                        if ($hawaiianWordCount >= 2) {
                            continue; // Skip this pattern, check others
                        }
                    }
                }
                return ['is_boilerplate' => true, 'reason' => 'pattern: ' . substr($pattern, 0, 50)];
            }
        }
        
        // Check for addresses and street patterns more broadly
        if (preg_match('/\d+\s+[A-Z][a-z]+.*Street/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'street address'];
        }
        
        // Check for institutional organization names
        if (preg_match('/Hawaiian Historical Society/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'Hawaiian Historical Society'];
        }
        
        // Check for geographic/institutional descriptions
        if (preg_match('/Hawaiʻi and the Pacific Islands/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'Pacific Islands description'];
        }
        
        // Check for committee/organization fragment patterns
        if (preg_match('/Committee.*Hawaiian.*Historical/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'Committee pattern'];
        }
        
        // Check for acknowledgment patterns
        if (preg_match('/For this assistance.*thank|We are grateful.*Dr\./u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'acknowledgment'];
        }
        
        // Check for funding/research patterns
        if (preg_match('/Hawaiian language newspapers.*carried out|funding.*University/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'funding/research'];
        }
        
        // Check for sentences that are primarily or entirely institutional metadata
        if (preg_match('/^\s*[A-Z][a-z]*\s*(Historical\s*)?Society.*[A-Z][a-z]+.*Street\s*$/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'institutional metadata'];
        }
        
        // Check for incomplete sentences ending with academic titles
        if (preg_match('/teacher at t$|Associate$|Curator.*Collection$/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'incomplete academic title'];
        }
        
        // Check for dedication patterns more specifically
        if (preg_match('/^For .+? and .+? in our lives/u', $sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'dedication'];
        }
        
        // Check for high concentration of publisher keywords
        $keywordCount = 0;
        $sentenceWords = preg_split('/\s+/u', $sentence);
        $wordCount = count($sentenceWords);
        
        foreach ($this->publisherKeywords as $keyword) {
            if (stripos($sentence, $keyword) !== false) {
                $keywordCount++;
            }
        }
        
        // If more than 20% of the words are publisher-related, likely boilerplate
        // Increased threshold to be less aggressive for mixed content
        if ($wordCount > 5 && ($keywordCount / $wordCount) > 0.20) {
            return ['is_boilerplate' => true, 'reason' => 'publisher keywords (' . round(($keywordCount / $wordCount) * 100) . '%)'];
        }
        
        // Check for sentences that are primarily English or mixed with lots of English
        if ($this->isPrimarilyEnglish($sentence)) {
            return ['is_boilerplate' => true, 'reason' => 'primarily English'];
        }
        
        return ['is_boilerplate' => false, 'reason' => 'passed all checks'];
    }
    
    /**
     * Check if a sentence is primarily English rather than Hawaiian
     */
    protected function isPrimarilyEnglish($sentence) {
        // First check for Hawaiian diacriticals - if present, it's Hawaiian
        if (preg_match('/[ʻāēīōū]/u', $sentence)) {
            return false;
        }
        
        // Use the Hawaiian word list to check if sentence contains Hawaiian words
        if (!empty(self::$hawaiianWords)) {
            $words = preg_split('/\s+/u', strtolower($sentence));
            $hawaiianWordCount = 0;
            $totalWords = 0;
            
            foreach ($words as $word) {
                $word = preg_replace('/[^\w]/u', '', $word); // Remove punctuation
                if (strlen($word) > 1) { // Count substantial words
                    $totalWords++;
                    $normalized = self::normalizeDiacritics($word);
                    if (isset(self::$hawaiianWords[$normalized])) {
                        $hawaiianWordCount++;
                    }
                }
            }
            
            // If 30% or more words are in Hawaiian dictionary, it's Hawaiian
            if ($totalWords > 0 && ($hawaiianWordCount / $totalWords) >= 0.3) {
                return false;
            }
        }
        
        // Check for common Hawaiian particle patterns that wouldn't appear in English
        // Multiple Hawaiian particles in sequence is a strong indicator
        if (preg_match('/\\b(o|i|a|e|ka|ke|na|he|mai|aku|ana|ia)\\s+(o|i|a|e|ka|ke|na|he|mai|aku|ana|ia)\\b/ui', $sentence)) {
            return false;
        }
        
        // Check for Hawaiian verb forms with repeated patterns (oia, eia, ua ... ana, etc.)
        if (preg_match('/\\b(oia|eia|pela|penei|pela\\s+no|oia\\s+no)\\b/ui', $sentence) ||
            preg_match('/\\bua\\s+\\w+ia\\b/ui', $sentence) ||  // ua + word + ia (passive)
            preg_match('/\\be\\s+\\w+(ana|ia|ina)\\b/ui', $sentence)) {  // e + word + ana/ia/ina
            return false;
        }
        
        // Common English words that shouldn't appear in Hawaiian text
        $englishWords = [
            'copyright', 'university', 'foundation', 'published', 'printed',
            'street', 'phone', 'email', 'website', 'www', 'http', 'com', 'edu',
            'author', 'editor', 'illustrator', 'funded', 'supported', 'grant',
            'the', 'and', 'that', 'with', 'for', 'was', 'were', 'are', 'they',
            'have', 'from', 'this', 'what', 'when', 'where', 'how', 'why',
            'students', 'classroom', 'school', 'teacher', 'grade', 'learning',
            'book', 'story', 'chapter', 'page', 'text', 'lesson', 'activity',
            'project', 'research', 'community', 'family', 'children', 'youth'
        ];
        
        $englishCount = 0;
        $totalWords = 0;
        $words = preg_split('/\s+/u', strtolower($sentence));
        
        // Check for Hawaiian dialect markers that indicate this is Hawaiian content
        $hawaiianDialectMarkers = ['ta', 'te', 'tana', 'tona', 'ta mata', 'ta alii', 'ta aina'];
        $hasHawaiianDialect = false;
        foreach ($hawaiianDialectMarkers as $marker) {
            if (strpos(strtolower($sentence), $marker) !== false) {
                $hasHawaiianDialect = true;
                break;
            }
        }
        
        // If this has Hawaiian dialect markers (like Niihau "ta" for "ka"), don't flag as English
        if ($hasHawaiianDialect) {
            return false;
        }
        
        foreach ($words as $word) {
            $word = preg_replace('/[^\w]/u', '', $word); // Remove punctuation
            if (strlen($word) > 2) { // Only count substantial words
                $totalWords++;
                if (in_array($word, $englishWords)) {
                    $englishCount++;
                }
            }
        }
        
        // If more than 50% of substantial words are English, likely not pure Hawaiian content
        // Increased threshold to be less aggressive
        if ($totalWords > 0 && ($englishCount / $totalWords) > 0.5) {
            return true;
        }
        
        // Also check for purely English sentences (no Hawaiian characters or patterns)
        if (preg_match('/^[A-Za-z\s\.,\!\?\:\;\-\'\"0-9\(\)]+$/', $sentence)) {
            // Check if it contains common Hawaiian words - if not, likely English
            $hawaiianMarkers = ['ʻ', 'ā', 'ē', 'ī', 'ō', 'ū'];
            $hasHawaiianMarkers = false;
            foreach ($hawaiianMarkers as $marker) {
                if (strpos($sentence, $marker) !== false) {
                    $hasHawaiianMarkers = true;
                    break;
                }
            }
            
            // Also check for Hawaiian words regardless of diacritics
            $hawaiianWords = ['aloha', 'mahalo', 'ohana', 'keiki', 'kane', 'wahine', 'alii', 'aina', 'pono', 'mana', 'hula', 'makai', 'mauka', 'pau', 'wiki'];
            foreach ($hawaiianWords as $hawaiianWord) {
                if (stripos($sentence, $hawaiianWord) !== false) {
                    $hasHawaiianMarkers = true;
                    break;
                }
            }
            
            if (!$hasHawaiianMarkers && $totalWords > 3) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Override process method to filter out boilerplate content
     */
    public function process( $contents ) {
        $this->funcName = "process";
        $count = count($contents);
        $this->print( "$count elements" );
        
        if( $count == 0 ) {
            return [];
        }
        
        $paragraphs = $this->DOMToStringArray( $contents );
        $sentences = $this->splitSentences( $paragraphs );
        // Removed verbose debug output - processing happens silently
        $finalLines = [];
        
        $acceptedCount = 0;
        $rejectedCount = 0;
        $emptyCount = 0;
        $endMarkerCount = 0;
        
        foreach( $sentences as $i => $line ) {
            $originalLine = $line;
            $line = $this->cleanSentence( $line );

            if( $this->containsEndMarker( $line ) ) {
                $endMarkerCount++;
                break;
            }

            $line = trim($line);
            if( strlen($line) > 0 ) {
                // Apply boilerplate filtering - but be more lenient for page number patterns
                $isPageNumber = preg_match('/^\d+$/', $line) || preg_match('/^Page \d+$/', $line);

                $boilerplateCheck = $this->isBoilerplate($line);
                if (!$boilerplateCheck['is_boilerplate'] && !$isPageNumber) {
                    $line = preg_replace('/[\s\n\r]+/u', ' ', $line);
                    $line = preg_replace('/\n+([\'ʻ])\n+/u', '$1', $line);
                    $line = substr($line, 0, self::MAX_SENTENCE_LENGTH);
                    $finalLines[] = $line;
                    $acceptedCount++;
                } else {
                    $this->discarded[] = $line;
                    $rejectedCount++;
                    if ($boilerplateCheck['is_boilerplate']) {
                        $reason = $boilerplateCheck['reason'] ?? 'unknown';
                        $this->debugPrint("Rejected [$reason]: " . substr($line, 0, 100) . "...");
                    } else {
                        $this->debugPrint("Discarded page number: " . substr($line, 0, 100) . "...");
                    }
                }
            } else {
                $emptyCount++;
                $this->discarded[] = $line;
            }
        }
        
        $this->debugPrint(count($finalLines) . " sentences remain after filtering.");

        // Debug output removed - results tracked in metadata        $this->metadata['sentence_count'] = count($finalLines);
        $this->metadata['final_text_char_count'] = strlen(implode("\n", $finalLines));
        
        // If we have very few sentences left, this might be a document with no real content
        if (count($finalLines) < 3) {
            $this->debugPrint("Warning: Only " . count($finalLines) . " sentences remain after filtering. Document may have no substantial Hawaiian content.");
        }
        
        return $finalLines;
    }
    
    /**
     * Custom splitLines method to handle numbered sentences in Ulukau content
     */
    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters in line" );
        
        // First, fix common spacing issues from source text processing
        $text = preg_replace('/([a-z])([A-Z][a-z])/u', '$1 $2', $text); // Add space between "thatspecifically" -> "that specifically"
        $text = preg_replace('/([a-z])([A-Z][a-z])/u', '$1 $2', $text); // Run twice to catch multiple consecutive cases
        
        // Fix specific known patterns that cause issues
        $text = str_replace('thatspecifically', 'that specifically', $text);
        $text = str_replace('counterrevo-lution', 'counterrevolution', $text);
        $text = str_replace('QueenLiliʻuokalani', 'Queen Liliʻuokalani', $text);
        $text = str_replace('ahe kuhi w', 'a he kuhi waiwai', $text);
        $text = str_replace('puke, ahe', 'puke, a he', $text);
        $text = str_replace('1895 a me', '1895, a me', $text);
        $text = str_replace('1895,he hōʻike', '1895, he hōʻike', $text);
        
        // Fix Hawaiian word tokenization issues - words that got spaces inserted incorrectly
        $text = str_replace('Po oinoa', 'Poʻoinoa', $text);
        $text = str_replace('Pūka ina', 'Pūkaʻina', $text);
        $text = str_replace('Ku una', 'Kuʻuna', $text);
        $text = str_replace('Mo olelo', 'Moʻolelo', $text);
        $text = str_replace('O opu', 'Oʻopu', $text);
        $text = str_replace('Īlio Moo', 'Īlio Moʻo', $text);
        $text = str_replace('Ka ao', 'Kaʻao', $text);
        $text = str_replace(' Anae', ' ʻAnae', $text);
        $text = str_replace('no ka Anae', 'no ka ʻAnae', $text);
        
        // Preserve page markers and similar bracketed content within sentences
        // Don't split on page markers like [ xv ] or [ xxiii ] within text
        $text = preg_replace('/\s*\[\s*([xivlcdmXIVLCDM]+)\s*\]\s*/u', ' [$1] ', $text);
        
        // Don't split on quotes or parenthetical content that continues a sentence
        // Handle cases like '"text continues' where quote doesn't end the sentence
        $preserveQuotes = [];
        if (preg_match_all('/"[^"]*"[^.!?]*[a-z]/u', $text, $matches)) {
            foreach ($matches[0] as $i => $match) {
                $placeholder = "<<PRESERVE_QUOTE_$i>>";
                $preserveQuotes[$placeholder] = $match;
                $text = str_replace($match, $placeholder, $text);
            }
        }
        
        // Handle numbered sentences that appear in learning materials
        // Pattern: "1 Sentence text. 2 Another sentence. 3 More text."
        $text = preg_replace('/(\.)(\s*)(\d+\s+)/u', '$1<SENTENCE_BREAK>$3', $text);
        
        // Handle song titles and section breaks (lines that end with dashes or are standalone titles)
        $text = preg_replace('/(————————————)(\s*)/u', '$1<SENTENCE_BREAK>', $text);
        
        // Split on sentence endings followed by capital letters, but be more conservative
        // Don't split if there's a page marker or quote continuation
        $text = preg_replace('/([.!?])(\s+)([A-ZĀĒĪŌŪÆØÅÑŚČŽĆẼ])(?![a-z]*\s*\[)/u', '$1<SENTENCE_BREAK>$3', $text);
        
        // Don't split on periods followed by lowercase (abbreviations, initials, etc.)
        // Don't split within quotes unless there's a clear sentence ending
        
        // Split very long verses by splitting on line breaks that separate verses/stanzas
        // But be more conservative - only split if there are clear verse patterns
        $text = preg_replace('/([.!?])\s*\n\s*([A-ZĀĒĪŌŪ][a-z]+)/u', '$1<SENTENCE_BREAK>$2', $text);
        
        // Split on the markers we created
        $lines = explode('<SENTENCE_BREAK>', $text);
        
        // Clean up each line and restore preserved content
        $cleanedLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // Restore preserved quotes
                foreach ($preserveQuotes as $placeholder => $original) {
                    $line = str_replace($placeholder, $original, $line);
                }
                
                // Remove leading numbers if they exist (like "2 " at the start)
                $line = preg_replace('/^\d+\s+/u', '', $line);
                
                // Clean up excessive spacing and separators
                $line = preg_replace('/\s+/u', ' ', $line);
                $line = preg_replace('/^—+\s*/u', '', $line); // Remove leading dashes
                $line = preg_replace('/\s*—+$/u', '', $line); // Remove trailing dashes
                
                // Fix any remaining spacing issues
                $line = preg_replace('/\s*\[\s*([xivlcdmXIVLCDM]+)\s*\]\s*/u', ' [$1] ', $line);
                $line = preg_replace('/\s+/u', ' ', $line); // Clean up multiple spaces
                
                // Apply final specific fixes
                $line = str_replace('thatspecifically', 'that specifically', $line);
                $line = str_replace('QueenLiliʻuokalani', 'Queen Liliʻuokalani', $line);
                
                if (!empty(trim($line))) {
                    $cleanedLines[] = $line;
                }
            }
        }
        
        return $cleanedLines;
    }
    
    /**
     * Override checkSentence to handle Hawaiian content better
     */
    public function checkSentence( $sentence ) {
        $this->funcName = "checkSentence";
        $this->log( strlen($sentence) . " characters" );

        // Check for Hawaiian apostrophe (ʻokina) or regular apostrophe
        $hasApostrophe = (strpos($sentence, "'") !== false || strpos($sentence, "ʻ") !== false);
        
        if( str_word_count($sentence) < $this->minWords ) {
            return false;
        }
        if (preg_match_all('/[^\d\s]/u', $sentence, $matches) < 3) {
        }
        if (preg_match('/^No\.?[\d[:punct:]]*$/u', trim($sentence))) {
            return false;
        }
        if (preg_match('/^P\.?[\d[:punct:]]*$/u', trim($sentence))) {
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
        
        // Return true if we have apostrophe/okina (Hawaiian text indicator)
        if ($hasApostrophe) {
            return true;
        }
        
        // Check for Hawaiian words/patterns even without apostrophe
        if (preg_match('/\b(ka|ke|o|a|i|ma|no|me|lā|ola|ali|aina|mau|pau|kauikeaouli|kamehameha|hawaii|honolulu|kona|maui|molokai|lanai|kohala)\b/ui', $sentence)) {
            return true;
        }
        
        // Check for Hawaiian diacritical marks
        if (preg_match('/[āēīōūĀĒĪŌŪ]/u', $sentence)) {
            return true;
        }
        
        return false;
    }
}

class UlukauLocal extends UlukauHTML {
    protected $baseDir = __DIR__ . '/ulukau';
    protected $pageListFileName = "ulukau.json";
    protected $pageListFile;
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->logName = "UlukauLocal";
        $this->pageListFile = "{$this->baseDir}/output/{$this->pageListFileName}";
    }

    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print("");
        $pageList = json_decode( file_get_contents($this->pageListFile), true );
        for( $i = 0; $i < count($pageList); $i++ ) {
            $pageList[$i]['link'] =
                $pageList[$i]['url'] =
                    "$this->urlBase/{$pageList[$i]['oid']}.html";
        }
        return $pageList;
    }

    public function getContents( $url ) {
        $this->funcName = "getContents";
        $this->print("");
        $oid = null;
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $params);
            // Return the 'd' parameter which contains the OID
            $oid = $params['d'] ?? null;
        }
        if( !$oid ) {
            $this->print( "No OID found in URL: $url" );
            return "";
        }
        $filename = "{$this->baseDir}/output/$oid.html";
        return file_get_contents( $filename );
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
    public function __construct( $options = ['preprocess' => false,] ) {
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
                        'date' => $this->metadata['date'],
                    ];
                }
            }
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->urlBase = 'https://keaolama.org/';
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://keaolama.org/?infinity=scrolling&page=";
        $this->logName = "AoLamaHTML";
        $this->groupname = "keaolama";
        $this->extractPatterns = ['//div[contains(@class, "entry-content")]//p'];
    }
    public function extractMetadata( $dom = null ) {
        $this->funcName = "extractMetadata";
        if( !$dom ) {
            $dom = $this->dom;
        }
        $xpath = new DOMXPath($dom);
        $node = $xpath->query('//meta[@property="og:title"]')->item(0);
        if ($node) {
            $raw = $node->getAttribute('content');   // "11-21-25"

            $date = DateTime::createFromFormat('m-d-y', $raw);
            if ($date) {
                $formatted = $date->format('Y-m-d');
                 $this->metadata['date'] = $formatted;   // 2025-11-21
            }
        } else {
            $this->metadata['date'] = $this->getDateFromUrl( $this->url );
        }
        $this->metadata['title'] =
            $this->basename . ' ' . $this->metadata['date'];
        $this->metadata['sourcename'] =
            $this->basename . ': ' . $this->metadata['date'];
    }
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $page = 0;
        $pages = [];
        $seen = [];
        while( true ) {
            $contents = $this->getRaw( $this->baseurl . $page, false );
            $response = json_decode( $contents );
            if( $response->type != 'success' ) {
                break;
            }
            $urls = array_keys( (array)$response->postflair );
            foreach( $urls as $u ) {
                $p = new AoLamaHTML();
                $p->initialize( $u );
                $p->extractMetadata();
                $date = $p->metadata['date'];
                $sourcename = $p->metadata['sourcename'];
                $title = $p->metadata['title'];
                if( isset( $seen[$sourcename] ) ) {
                    //echo "Already processed $sourcename\n";
                    continue;
                }
                $pages[] = [
                    'sourcename' => $sourcename,
                    'url' => $u,
                    'image' => '',
                    'title' => $title,
                    'date' => $date,
                    'groupname' => $this->groupname,
                    'author' => $this->authors,
                ];
                $seen[$sourcename] = $u;
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
            "This column is coordinated",
            "Kā ka luna hoʻoponopono nota",
            "Click here",
            "nota: Unuhi ʻia na",
            "Ua kākoʻo ʻia kēia papahana",
            "ʻO kā Civil Beat kūkala nūhou ʻana i",
            "E hoouna ia mai na",
            "E ho‘ouna ‘ia mai na ā leka",
        ];
//         $this->toSkip[] = "Synopsis:";
        $this->ignoreMarkers = [
            "Correction:",
        ];
        $this->extractPatterns = [
            '//div[contains(@class, "hsa-paywall")]//p',
        ];
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
            $this->basename . ": " . $this->metadata['date'];
        $this->metadata['title'] = ($this->metadata['title']) ??
            $this->basename . " " . $this->metadata['date'];
    }

    public function getContents( $url, $options=[] ) {
        $text = parent::getContents( $url, $options );
        return $this->updateVisibility( $text );
    }
    
    public function extractSentencesFromHTML( $text ) {
        $this->funcName = "extractSentencesFromHTML";
        // Apply visibility fixes before processing
        $text = $this->updateVisibility( $text );
        return parent::extractSentencesFromHTML( $text );
    }

    public function getAllBlurbs() {
        $categoryUrl = 'https://www.staradvertiser.com/category/editorial/kauakukalahale/';
        $archiveType = 'category';
        $archiveSlug = 'kauakukalahale';
        
        // Fetch the initial page to extract dynamicLoadMore config
        $initialPage = file_get_contents($categoryUrl);
        if ($initialPage === false) {
            $this->print("Failed to fetch initial page");
            return '';
        }
        
        // Extract dynamicLoadMore JSON from the page
        if (preg_match('/var dynamicLoadMore = ({[^;]+});/', $initialPage, $matches)) {
            $config = json_decode($matches[1], true);
            $ajaxUrl = $config['ajaxUrl'] ?? 'https://www.staradvertiser.com/wp-content/themes/hsa-redesign/inc/ajax/load-more-posts.php';
            $nonce = $config['nonce'] ?? '';
            $startPage = (int)($config['currentPage'] ?? 1);
            
            $this->print("Extracted nonce: $nonce, starting page: $startPage");
        } else {
            $this->print("Could not extract dynamicLoadMore config, using defaults");
            $ajaxUrl = 'https://www.staradvertiser.com/wp-content/themes/hsa-redesign/inc/ajax/load-more-posts.php';
            $nonce = '';
            $startPage = 0;
        }
        
        $allHtml = '';

        while (true) {
            $postData = http_build_query([
                'action'      => 'load_more_posts',
                'pagenum'     => $startPage,
                'currentPage'     => $startPage,
                'archiveType' => $archiveType,
                'archiveSlug' => $archiveSlug,
                'security'    => $nonce,
                'none'        => $nonce,
                'ajaxUrl'     => $ajaxUrl,
            ]);

            $options = [
                'http' => [
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => $postData,
                ]
            ];

            $context = stream_context_create($options);
            $result = file_get_contents($ajaxUrl, false, $context);

            if ($result === false) {
                $this->print( "Request failed on page $startPage" );
                break;
            }

            $response = json_decode($result, true);

            if (!isset($response['success']) || !$response['success']) {
                $this->print( "No more posts after page $startPage" );
                break;
            }

            $allHtml .= $response['data'];
            $this->print( "Fetched blurb segment $startPage" );
            $startPage++;
        }
        return $allHtml;
    }

    public function extractPages( $htmlFragment ) {
        // Wrap in full HTML structure with UTF-8 meta tag
        $wrappedHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Extract</title>
</head>
<body>
$htmlFragment
</body>
</html>
HTML;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Extract articles
        $articles = $xpath->query('//article[contains(@class, "news-story")]');

        $items = [];
        foreach ($articles as $article) {
            $imgurl = '';
            $title = '';
            $author = '';
            $date = '';
            $url = '';

            // Image URL
            $img = $xpath->query('.//img', $article);
            if ($img->length > 0) {
                $imgurl = $img->item(0)->getAttribute('src');
            }

            // Title and Article URL
            $titleNode = $xpath->query('.//h3[contains(@class, "story-title")]//a', $article);
            if ($titleNode->length > 0) {
                $title = $titleNode->item(0)->getAttribute('title');
                $title = preg_replace('/^Column:\s*/u', '', $title);
                $url = $titleNode->item(0)->getAttribute('href');
            }

            // Author
            $authorNode = $xpath->query('.//li[contains(@class, "custom_byline")]', $article);
            if ($authorNode->length > 0) {
                $author = trim(preg_replace('/^By\s*/u', '', $authorNode->item(0)->nodeValue));
            }

            // Date
            $dateNode = $xpath->query('.//ul[contains(@class, "byline")]/li[2]', $article);
            if ($dateNode->length > 0) {
                $rawDate = trim($dateNode->item(0)->nodeValue);
                $rawDate = preg_replace('/\s+/', ' ', $rawDate); // Normalize spacing
                $dateObj = DateTime::createFromFormat('M. j, Y', $rawDate);
                if (!$dateObj) {
                    $dateObj = DateTime::createFromFormat('M j, Y', $rawDate); // fallback if no dot
                }
                $date = $dateObj ? $dateObj->format('Y-m-d') : $rawDate;
            }
            
            // If date not found, extract from URL as fallback
            if (empty($date) && !empty($url)) {
                // URL format: https://www.staradvertiser.com/2025/05/10/editorial/kauakukalahale/...
                if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $url, $matches)) {
                    $date = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                }
            }

            $sourcename = "Kauakukalahale: " . $date;
            $groupname = "kauakukalahale";
            $items[] = [
                "title" => $title,
                "author" => $author,
                "date" => $date,
                "image" => $imgurl,
                "url" => $url,
                "sourcename" => $sourcename,
                "groupname" => $groupname,
            ];
        }
        $this->print( "Found " . count($items) . " articles" );
        return $items;
    }

    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $htmlFragment = $this->getAllBlurbs();

        $pages = $this->extractPages( $htmlFragment );
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
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://nupepa.org/?a=cl&cl=CL2";
        $this->baseurl = 'https://nupepa.org/?a=cl&cl=CL2&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0';
        $this->semanticSplit = true;
        $this->logName = "NupepaHTML";
        $this->groupname = "nupepa";
        $this->extractPatterns = [
            '//sec/div/p', '//p/span', '//td/div/div', '//center/table/tr/td/*',
        ];
    }
    public function extractFormattedDate($title) {
        // Match patterns like "1 August 1893", "2 May 1891", "20 November 1891"
        if (preg_match('/\b(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})\b/', $title, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthName = ucfirst(strtolower($matches[2]));
            $year = $matches[3];

            // Convert month name to number
            $monthNum = date('m', strtotime($monthName));

            return "$year-$monthNum-$day";
        }

        return "1970-01-01";
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
                            $normalizedDate = $this->extractFormattedDate( $dateText );
                            $dateText = preg_replace('/^\(\w+, (.*?)\)$/', '$1', $dateText);
                            $source = [
                                'sourcename' => "{$titleText}: {$normalizedDate}",
                                'url' => $fullUrl,
                                'image' => $imgSrc,
                                'title' => "{$titleText} {$dateText}",
                                'date' => $normalizedDate,
                                'author' => '',
                                'groupname' => 'nupepa',
                            ];
                            //echo( var_export( $source, true ) . "\n" );
                            $results[] = $source;
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
            $this->metadata['date'] = $this->extractFormattedDate( $this->metadata['title'] );
        }
        $this->metadata['sourcename'] = $this->metadata['title'];
    }
    
    public function connectLines(array $lines) {
        $this->funcName = "connectLines";
        $connectors = [ "‘" => "", "ʻ" => "", '/^-\{1,2}\s*[A-ZĀĒĪŌŪ ]+\s*-\{1,2}$/' => " " ];
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
class UlukauOld extends NupepaHTML {
    protected $hostname = "";
    public function __construct( $options = ['preprocess' => false,] ) {
        parent::__construct($options);
        $this->logName = "UlukauHTML";
        $this->baseurl = "https://puke.ulukau.org/ulukau-books/";
        $this->groupname = "ulukau";
        $this->extractPatterns = ['//div[@id="ulukaupagetextview"]//p', '//p'];
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
    private $domain = "https://web.archive.org/web/20160215022435/https://www2.hawaii.edu/~kroddy/";
    protected $baseurls = [
        "https://web.archive.org/web/20160215022435/https://www2.hawaii.edu/~kroddy/moolelo/papa_kaao.htm",
        "https://web.archive.org/web/20160215022435/https://www2.hawaii.edu/~kroddy/moolelo/papa_moolelo.htm",
        "https://web.archive.org/web/20160215022435/https://www2.hawaii.edu/~kroddy/moolelo/kaao_unuhiia.htm",
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
        //return $pages;
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
        $this->semanticSplit = false; // Use element-based splitting to preserve <br> structure
        $this->logName = "BaibalaHTML";
        $this->groupname = "baibala";
        $this->extractPatterns = ['//td', '//body'];
    }
    
    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters in line" );
        
        // Split on <br> tags for Baibala content - handle both <br> and <br />
        $lines = preg_split('/<br\s*\/?>/i', $text);
        
        // Clean up each line and also split chapter titles from verses
        $cleanedLines = [];
        foreach ($lines as $line) {
            $line = trim(strip_tags($line));
            if (empty($line)) continue;
            
            // Check if this line contains a chapter title followed by verse content
            if (preg_match('/^(MOKUNA\s+[IVXLCDM]+\.?)\s+(.+)$/', $line, $matches)) {
                // Split into chapter title and verse content
                $cleanedLines[] = trim($matches[1]); // Chapter title only
                $cleanedLines[] = trim($matches[2]); // Verse content
            } else {
                $cleanedLines[] = $line;
            }
        }
        
        return $cleanedLines;
    }
    
    public function checkElement( $p ) {
        $this->funcName = "checkElement";
        if ($p->nodeName === 'br') {
            return '';
        }
        // For Baibala, preserve <br> tags in the text instead of stripping all HTML
        $html = $p->ownerDocument->saveHTML($p);
        // Keep only <br> tags (including <br />, <br/>, <br>), strip everything else
        $text = strip_tags($html, '<br>');
        
        // Decode HTML entities to preserve ¶ and other special characters
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Filter out navigation elements - only keep cells that contain biblical content
        $trimmedText = trim($text);
        
        // Skip cells that are likely navigation (short text without verse numbers or content)
        if (strlen($trimmedText) < 10) {
            return '';
        }
        
        // Skip cells that contain only navigation text
        $navigationPatterns = [
            'Baibala (full bible)',
            'Old Testament',
            'New Testament',
            'Genesis',
            'Exodus',
            'Leviticus',
            // Add other book names as needed
        ];
        
        foreach ($navigationPatterns as $pattern) {
            if (strpos($trimmedText, $pattern) !== false && strlen($trimmedText) < 50) {
                return '';
            }
        }
        
        return $trimmedText;
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
            
            // For Baibala, we need to handle mixed content in table cells
            $html = $node->ownerDocument->saveHTML($node);
            
            // Extract different types of content separately
            $this->extractBaibalaContent($html, $paragraphs);
        }
        
        return $paragraphs;
    }
    
    protected function extractBaibalaContent($html, &$paragraphs) {
        // Create DOM from the HTML to analyze structure
        $dom = $this->getDOMFromString('<div>' . $html . '</div>');
        if (!$dom) return;
        
        $xpath = new DOMXpath($dom);
        
        // Remove navigation links first (book/chapter links)
        $navLinks = $xpath->query('//a[contains(@href, "d=NULL") and contains(@target, "main")]');
        foreach ($navLinks as $link) {
            $link->parentNode->removeChild($link);
        }
        
        // Get the cleaned HTML after removing navigation
        $cleanedHtml = $dom->saveHTML();
        
        // Now process to separate titles and add line breaks
        // Replace <p><span>TITLE</span></p> with <br />TITLE<br />
        $cleanedHtml = preg_replace('/<p[^>]*><span[^>]*>([^<]+)<\/span><\/p>/', "<br />$1<br />", $cleanedHtml);
        
        // Strip all remaining HTML tags except <br>
        $content = strip_tags($cleanedHtml, '<br>');
        
        // Clean up the content
        $content = trim($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove navigation patterns like "Acts Acts 1"
        $content = preg_replace('/\b([A-Za-z]+)\s+\1\s+\d+\b/', '', $content);
        
        // Clean up extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/\s*<br\s*\/?>\s*/', "<br />", $content);
        
        if (!empty($content) && strlen($content) > 10) {
            $paragraphs[] = $content;
        }
    }
    
    public function extractMetadata( $dom = null ) {
        if (!$dom) $dom = $this->dom;
        $this->metadata['title'] = $this->metadata['title'] ?? $this->documentname;
        $this->metadata['sourcename'] = $this->documentname;
        $this->metadata['date'] = '2012-01-01';
    }
    
    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print("Fetching dynamic document list from overview page");
        
        $overviewUrl = "https://baibala.org/cgi-bin/bible";
        $pages = [];
        
        try {
            // Extract the main frame content from overview page
            $mainFrameContent = $this->extractOverviewFrameContent($overviewUrl);
            if (!$mainFrameContent) {
                $this->print("Failed to extract main frame content, falling back to static list");
                return $this->getStaticDocumentList();
            }
            
            // Parse the main frame content to extract book links
            $dom = $this->getDOMFromString($mainFrameContent);
            if (!$dom) {
                $this->print("Failed to parse main frame HTML, falling back to static list");
                return $this->getStaticDocumentList();
            }
            
            $xpath = new DOMXpath($dom);
            
            // Find all book links in the table
            $bookLinks = $xpath->query('//table//td[@class="booklinkbox"]//a[@target="_blank"]');
            
            if (!$bookLinks || $bookLinks->length === 0) {
                $this->print("No book links found, falling back to static list");
                return $this->getStaticDocumentList();
            }
            
            $this->print("Found " . $bookLinks->length . " book links");
            
            foreach ($bookLinks as $link) {
                $href = $link->getAttribute('href');
                $titleText = trim($link->textContent);
                
                if (empty($href) || empty($titleText)) {
                    continue;
                }
                
                // Convert relative URL to absolute
                if (strpos($href, 'http') !== 0) {
                    $href = $this->urlBase . $href;
                }
                
                // Transform the URL to direct content access using your specification
                $directUrl = $this->transformToDirectUrl($href);
                if (!$directUrl) {
                    $this->print("Failed to transform URL: " . $href);
                    continue;
                }
                
                // Extract the English title (part before the slash if present)
                $title = $titleText;
                if (strpos($titleText, ' / ') !== false) {
                    $parts = explode(' / ', $titleText, 2);
                    $title = trim($parts[0]);
                }
                
                $pages[] = [
                    'sourcename' => $this->documentname . " " . $title,
                    'url' => $directUrl,
                    'title' => $title,
                    'image' => '',
                    'author' => '',
                    'groupname' => $this->groupname,
                ];
            }
            
            if (empty($pages)) {
                $this->print("No valid pages extracted, falling back to static list");
                return $this->getStaticDocumentList();
            }
            
            $this->print("Successfully extracted " . count($pages) . " books from overview page");
            return $pages;
            
        } catch (Exception $e) {
            $this->print("Exception occurred: " . $e->getMessage() . ", falling back to static list");
            return $this->getStaticDocumentList();
        }
    }
    
    protected function transformToDirectUrl($originalUrl) {
        $this->print("Building direct URL from: " . $originalUrl);
        
        // Extract only the d parameter from the original URL
        $dParameter = null;
        if (preg_match('/[&?]d=([^&]*)/', $originalUrl, $matches)) {
            $dParameter = $matches[1];
            $this->print("Extracted d parameter: " . $dParameter);
        } else {
            $this->print("No d parameter found in original URL");
            return null;
        }
        
        // Build the URL with exact parameter order as specified
        $baseUrl = "https://baibala.org/cgi-bin/bible";
        $eParameter = "d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-home-main-home----011-01994v1--210-0-2-utfZz-8";
        
        $directUrl = $baseUrl . 
            "?e=" . $eParameter .
            "&cl=" .
            "&d=" . $dParameter .
            "&cid=" .
            "&d2=1" .
            "&toc=0" .
            "&exp=1-" .
            "&gg=text" .
            "#a1-";
        
        $this->print("Built direct URL: " . $directUrl);
        return $directUrl;
    }
    
    protected function extractOverviewFrameContent($overviewUrl) {
        $this->funcName = "extractOverviewFrameContent";
        $this->print("Extracting main frame from overview page: " . $overviewUrl);
        
        // Fetch the overview page
        $html = parent::getRaw($overviewUrl, false);
        if (!$html) {
            $this->print("Failed to fetch overview page");
            return null;
        }
        
        // Check if this is a frameset page
        if (strpos($html, '<frameset') === false) {
            $this->print("No frameset found in overview page");
            return null;
        }
        
        $dom = $this->getDOMFromString($html);
        if (!$dom) {
            $this->print("Failed to parse overview HTML");
            return null;
        }
        
        $xpath = new DOMXpath($dom);
        
        // Find the main frame in the frameset
        $mainFrame = $xpath->query('//frame[@name="main"]')->item(0);
        if (!$mainFrame) {
            $this->print("No main frame found in overview page");
            return null;
        }
        
        $mainFrameSrc = $mainFrame->getAttribute('src');
        if (!$mainFrameSrc) {
            $this->print("Main frame has no src attribute");
            return null;
        }
        
        // Convert relative URL to absolute
        if (strpos($mainFrameSrc, 'http') !== 0) {
            $mainFrameSrc = $this->urlBase . $mainFrameSrc;
        }
        
        $this->print("Fetching main frame content: " . $mainFrameSrc);
        
        // Fetch the main frame content
        $mainFrameHtml = parent::getRaw($mainFrameSrc, false);
        if (!$mainFrameHtml) {
            $this->print("Failed to fetch main frame content");
            return null;
        }
        
        return $mainFrameHtml;
    }
    
    protected function getStaticDocumentList() {
        $this->print("Using static document list");
        // Fallback to the original static list with correct URL format
        $baseUrlPattern = "https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-home-main-home----011-01994v1--210-0-2-utfZz-8&cl=&d={D_PARAM}&cid=&d2=1&toc=0&exp=1-&gg=text#a1-";
        
        $books = [
            ['title' => 'Genesis', 'd' => 'NULL.2.1.1'],
            ['title' => 'Exodus', 'd' => 'NULL.2.1.2'],
            ['title' => 'Leviticus', 'd' => 'NULL.2.1.3'],
            ['title' => 'Numbers', 'd' => 'NULL.2.1.4'],
            ['title' => 'Deuteronomy', 'd' => 'NULL.2.1.5'],
            ['title' => 'Joshua', 'd' => 'NULL.2.2.1'],
            ['title' => 'Judges', 'd' => 'NULL.2.2.2'],
            ['title' => 'Ruth', 'd' => 'NULL.2.2.3'],
            ['title' => 'Samuel', 'd' => 'NULL.2.2.4'],
            ['title' => 'Samuel 2', 'd' => 'NULL.2.2.5'],
            ['title' => 'Kings', 'd' => 'NULL.2.2.6'],
            ['title' => 'Kings 2', 'd' => 'NULL.2.2.7'],
            ['title' => 'Chronicles', 'd' => 'NULL.2.2.8'],
            ['title' => 'Chronicles 2', 'd' => 'NULL.2.2.9'],
        ];
        
        $pages = [];
        foreach ($books as $book) {
            $url = str_replace('{D_PARAM}', $book['d'], $baseUrlPattern);
            $pages[] = [
                'sourcename' => $this->documentname . " " . $book['title'],
                'url' => $url,
                'title' => $book['title'],
                'image' => '',
                'author' => '',
                'groupname' => $this->groupname,
            ];
        }
        
        return $pages;
    }
}

class EhoouluLahuiHTML extends HtmlParse {
    private $basename = "E Hooulu Lahui";
    private $domain = "https://ehooululahui.com/";
    public function __construct( $options = ['preprocess' => false,] ) {
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

class KaiwakiloumokuHTML extends HtmlParse {
    private $basename = "Kaiwakiloumoku";
    private $domain = "https://kaiwakiloumoku.ksbe.edu/";
    private $replaceChars = [];
    private $documentCache = null;

    public function __construct( $options = ['preprocess' => false] ) {
        parent::__construct($options);
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = $this->domain . "moolelo";
        $this->logName = "KawakiloumokuHTML";
        $this->groupname = "kaiwakiloumoku";
        $this->extractPatterns = ['//div[contains(@class, "large-8")]//h1 | //div[contains(@class, "large-8")]//p'];
        $this->semanticSplit = true;
        $this->replaceChars = [
            '“' => '"',
            '”' => '"',
            '‘' => "'",
            '’' => "'",
        ];
    }

    private function makeAbsoluteUrl($url) {
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }
        return rtrim($this->domain, '/') . $url;
    }

    public function getDocumentList() {
        $this->funcName = "getDocumentList";
        $this->print( "" );
        $dom = $this->getDomForDiscovery( $this->baseurl );
        if (!$dom) {
            return [];
        }
        $xpath = new DOMXpath($dom);
        $query = '//div[@class="grid-x"]//div[contains(@class, "large-12")]';
        $divs = $xpath->query( $query );
        $moolelo = [];
        $pages = [];

        foreach ($divs as $div) {
            $links = $xpath->query('.//a', $div);
            if ($links->length === 0) {
                continue;
            }
            $link = $links->item(0);
            $href = $this->makeAbsoluteUrl($link->getAttribute('href'));
            $title = trim($link->nodeValue);
            
            if (empty($title)) continue;

            $descriptionNode = $xpath->query('.//text()[normalize-space()]', $div);
            $description = '';
            if ($descriptionNode->length > 1) {
                $description = trim($descriptionNode->item(1)->nodeValue);
            }

            $author = '';
            if (preg_match('/by (.*?)\./', $description, $matches)) {
                $author = $matches[1];
            }

            $date = '';
            if (preg_match('/(\d{1,2} \w+ \d{4})/', $description, $matches)) {
                $date = date('Y-m-d', strtotime($matches[1]));
            }

            $baseTitle = trim(preg_replace('/(?: - Helu \d+| - Mokuna \d+)$/', '', $title));
            $baseHref = preg_replace('/(?:-helu-\d+|-mokuna-\d+)$/', '', $href);

            if (!isset($moolelo[$baseHref])) {
                $moolelo[$baseHref] = [
                    'sourcename' => $this->basename . ": " . $baseTitle,
                    'url' => $href,
                    'title' => $baseTitle,
                    'image' => '',
                    'author' => $author,
                    'date' => $date,
                    'groupname' => $this->groupname,
                    'chapters' => [],
                ];
            }
            $moolelo[$baseHref]['chapters'][] = ['title' => $title, 'url' => $href];
        }

        foreach ($moolelo as $story) {
            if (empty($story['title'])) continue;
            $pages[] = $story;
        }
        
        // Cache the document list for getDocumentPageUrls
        $this->documentCache = [];
        foreach ($pages as $doc) {
            $chapterUrls = [];
            if (!empty($doc['chapters'])) {
                foreach ($doc['chapters'] as $chapter) {
                    $chapterUrls[] = $chapter['url'];
                }
            } else {
                $chapterUrls[] = $doc['url'];
            }
            // Map all URLs within a document to the same list of chapter URLs
            foreach ($chapterUrls as $url) {
                $this->documentCache[$url] = $chapterUrls;
            }
        }

        return $pages;
    }

    protected function getDocumentPageUrls($initialUrl) {
        $this->funcName = "getDocumentPageUrls";
        $this->print($initialUrl);

        // If the cache isn't populated, run getDocumentList to build it.
        if ($this->documentCache === null) {
            $this->print("Cache miss, running getDocumentList()");
            $this->getDocumentList();
        }

        if (isset($this->documentCache[$initialUrl])) {
            $urls = $this->documentCache[$initialUrl];
            $this->print(count($urls) . " URLs found in cache for $initialUrl");
            return $urls;
        }

        $this->print("URL not found in cache, returning initial URL.");
        return [$initialUrl];
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

    public function replaceChars( $text ) {
        foreach ($this->replaceChars as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        return $text;
    }

    public function splitSentences( $paragraphs ) {
        $this->funcName = "splitSentences";
        $this->print( count($paragraphs) . " paragraphs" );
        $text = ($paragraphs && count($paragraphs) > 0) ? implode( " ", $paragraphs ) : "";
        $this->debugPrint( strlen($text) . " characters" );
        if( empty( $text ) ) {
            return [];
        }
        
        $text = $this->replaceChars($text);
        $text = preg_replace('/\s*\(sic\)\s*/iu', ' ', $text);
        
        $bracketedSentences = [];
        $text = preg_replace_callback(
            '/\[(.*?)\]/u',
            function ($matches) use (&$bracketedSentences) {
                $placeholder = "[[BRACKETED_" . count($bracketedSentences) . "]]";
                $bracketedSentences[$placeholder] = trim($matches[1]);
                return $placeholder;
            },
            $text
        );

        $text = $this->protectAbbreviations($text);
        $lines = $this->splitLines($text);
        
        $finalLines = [];
        foreach ($lines as $line) {
            if (strpos($line, '[[BRACKETED_') !== false) {
                foreach ($bracketedSentences as $placeholder => $sentence) {
                    if (strpos($line, $placeholder) !== false) {
                        $parts = explode($placeholder, $line);
                        if (!empty(trim($parts[0]))) {
                            $finalLines[] = trim($parts[0]);
                        }
                        $finalLines[] = $sentence;
                        $line = trim($parts[1]);
                    }
                }
            }

            if (!empty(trim($line))) {
                $cleaned = $this->cleanSentence($line);
                if ($this->checkSentence($cleaned)) {
                    $finalLines[] = $cleaned;
                } else if (!empty($cleaned)) {
                    $this->discarded[] = $cleaned;
                }
            }
        }
        return $finalLines;
    }

    public function splitLines( $text )  {
        $this->funcName = "splitLines";
        $this->print( strlen($text) . " characters in line" );

        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/([.?!])\s/u', "$1<SPLIT_MARKER>", $text);
        $text = preg_replace('/([.?!"])\s/u', "$1<SPLIT_MARKER>", $text);

        return array_values(array_filter(array_map('trim', explode('<SPLIT_MARKER>', $text))));
    }

    protected function protectAbbreviations( $text ) {
        return preg_replace('/\b([A-Z])\./u', '$1' . $this->placeholder, $text);
    }
}

?>

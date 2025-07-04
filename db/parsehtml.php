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
function debugPrintObject( $obj, $intro ) {
    global $debugFlag;
    if( $debugFlag ) {
        printObject( $obj, $intro );
    }
}
class HtmlParse {
    protected $logName = "HtmlParse";
    protected $funcName = "";
    protected $Amarker = '&#256;';
    public $date = '';
    protected $url = '';
    protected $urlBase = '';
    public $baseurl = '';
    protected $dom;
    public $title = "";
    public $authors = "";
    public $discarded = [];
    protected $minWords = 1;
    protected $mergeRows = true;
    protected $options = [];
    public $groupname = "";
    protected $ignoreMarkers = [];
    protected $endMarkers = [
        "applesauce",
        "O KA HOOMAOPOPO ANA I NA KII",
        "TRANSLATION OF INTRODUCTION",
        "After we pray",
        "Look up",
        "Share this",
    ];
    protected $startMarker = "";
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
        // Add more as needed
    ];
    protected $skipMatches = [
        "OLELO HOOLAHA.",
    ];
    protected $badTags = [
        "//noscript",
        "//script"
    ];
    protected $badIDs = [    ];
    protected $badClasses = [    ];
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
    const MAX_SENTENCE_LENGTH = 400;
    public function __construct( $options = [] ) {
        $this->options = $options;
    }
    public function log( $obj, $prefix="") {
        if( $this->funcName ) {
            $func = $this->logName . ":" . $this->funcName;
            $prefix = ($prefix) ? "$func:$prefix" : $func;
        }
        debuglog( $obj, $prefix );
    }
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "HtmlParse::getSourceName($title,$url)" );
        return $title ?: "What's my source name?";
    }
    public function initialize( $baseurl ) {
        $this->url = $this->baseurl = $baseurl;
    }
        // This just does an HTTP fetch
    public function getRaw( $url, $options=[] ) {
        $this->funcName = "getRaw";
        $this->log( $url, $options );
        debugPrint( "{$this->funcName}( $url )" );
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
        $funcName = $this->funcName = "getContents";
        $this->log( $url, $options );
        debugPrintObject( $options, "{this->funcName}( $url, )" );
        // Get entire document, which could span multiple pages
        $text = $this->getRawText( $url, $options );
        $nchars = strlen( $text );
        debugPrint( "$funcName got $nchars characters from getRawText" );
        // Take care of any character cleanup
        $text = $this->preprocessHTML( $text );
        $text = $this->cleanup( $text );
        //debugPrint( "HtmlParse::getContents() $text" );
        debugPrint( "$funcName finished" );
        return $text;
    }
        // Returns DOM from string, either assuming HTML
    public function getDOMFromString( $text, $options=[] ) {
        $funcName = $this->funcName = "getDOMFromString";
        $fullName = $this->logName . ":" . $this->funcName;
        $this->log( $text, $options );
        $nchars = strlen( $text );
        debugPrint( "$fullName($nchars characters)" );
        $dom = new DOMDocument;
        $dom->encoding = 'utf-8';
        libxml_use_internal_errors(true);
        if( $text ) {
            // DO NOT encode HTML entities here; DOMDocument expects raw HTML
            //$text = htmlentities($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
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
        debugPrint( "$fullName finished" );
        return $dom;
    }
        // Returns DOM from URL, either assuming XML or HTML
    public function getDOM( $url, $options = [] ) {
        $this->funcName = "getDOM";
        $fullName = $this->logName . ":" . $this->funcName;
        $this->log( $url, $options );
        debugPrint( $fullName );
        //$this->initialize( $url );
        if( !isset($options['preprocess']) || $options['preprocess'] ) {
            $text = $this->getContents( $url, $options );
        } else {
            $text = $this->getRaw( $url, $options );
        }
        $this->dom = $this->getDOMFromString( $text, $options );
        debugPrint( "$fullName finished" );
        return $this->dom;
    }
    // Evaluate if a sentence should be retained
    public function checkSentence( $sentence ) {
        $this->funcName = "checkSentence";
        $fullName = $this->logName . ":" . $this->funcName;
        $this->log( $sentence );
        //debugPrint( "$fullName(" . strlen($sentence) . " characters)" );
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
                //echo "Pattern found at $result\n";
                //echo "Testing '" . $pattern . "' vs '" . $sentence . "'\n";
                return false;
            }
        }
        foreach( $this->skipMatches as $pattern ) {
            if( $sentence === $pattern ) {
                //echo "Pattern found at $result\n";
                //echo "Testing '" . $pattern . "' vs '" . $sentence . "'\n";
                return false;
            }
        }
        return true;
    }
        // Split text into lines by inserting \n
    public function prepareRaw( $text ) {
        $this->funcName = "prepareRaw";
        $fullName = $this->logName . ":" . $this->funcName;
        $this->log( $text );
        if ($text === null) {
            // Debug: trace where null is coming from
            debuglog("WARNING: prepareRaw called with null text" );
            return '';
        }
        if (!is_string($text)) {
            $text = (string)$text;
        }
        // Regex-based replacement table for problematic sequences and their replacements
        $replace = [
            '/<U\+0080>¦/' => '.',
            '/&#128;&brvbar;/' => '.',
            '/\x80\xA6/' => '.',
            '/\xC2\xA6/' => '.',
            '/\x80¦/' => '.',
            '/\xA6/' => '.',
            '/<U\+009C>/' => 'œ',
            '/\x9C/' => 'œ',
            '/<U\+0099>/' => '.',
            '/\x99/' => '.',
            '/&nbsp;/' => ' ',
            '/"/' => '',
            '/' . preg_quote($this->Amarker, '/') . '/' => 'Ā',
            '/[\x{0080}\x{00A6}\x{009C}\x{0099}]/u' => '.', // Unicode codepoints
        ];
        $text = preg_replace(array_keys($replace), array_values($replace), $text);
        if ($text === null || $text === '') {
            return '';
        }
        // Normalize whitespace and line breaks
        $text = preg_replace('/[\n\r]+/', ' ', $text); // Remove all linefeeds and carriage returns
        $text = preg_replace('/ +/', ' ', $text); // Collapse multiple spaces
        $text = trim($text);
        return $text;
    }
    public function removeElements( $text ) {
        $this->funcName = "removeElements";
        $fullName = $this->logName . ":" . $this->funcName;
        $this->log( strlen($text) . " characters)" );
        debugPrint( "$fullName(" . strlen($text) . " characters)" );
        debuglog( "$fullName(" . strlen($text) . " characters)" );
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
            debugPrint( "$fullName looking for tag $filter" );
            $p = $xpath->query( $filter );
            foreach( $p as $element ) {
                debugPrint( "$fullName found tag $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badIDs as $filter ) {
            debugPrint( "$fullName looking for ID $filter" );
            $p = $xpath->query( "//div[@id='$filter']" );
            foreach( $p as $element ) {
                debugPrint( "$fullName found ID $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        foreach( $this->badClasses as $filter ) {
            $query = "//div[contains(@class,'$filter')]";
            //$query = "//div[class*='$filter']";
            debugPrint( "$fullName looking for $query" );
            $p = $xpath->query( $query );
            foreach( $p as $element ) {
                debugPrint( "$fullName found class $filter" );
                $element->parentNode->removeChild( $element );
                $changed++;
            }
        }
        debugPrint( "$fullName removed $changed tags" );
        $this->log( "removed $changed tags" );
        $text = $dom->saveHTML();
        return $text;
    }

    public function updateLinks( $text ) {
        $this->funcName = "updateLinks";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName " . strlen($text) . " characters)" );
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
        // This is to settle issues with character encoding upfront, on reading the raw HTML from
    // the source
    public function convertEncoding( $text ) {
        $this->funcName = "convertEncoding";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName(" . strlen($text) . " characters)" );
        if( !$text ) {
            return $text;
        }

                // Works better to convert the text to use entities with UTF-8 and then swap them out
        // Too much variability in encoding before the entity conversion
        $text = htmlentities($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            "&#128;&#147;" => '-',
            "&#128;&#148;" => '-',
            "&#128;&#152;" => '‘',
            "&#128;&#157;" => '-',
            '&#157;&#x9D;' => '-',
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
        $this->funcName = "preprocessHTML";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName(" . strlen($text) . " characters)" );
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
    // Extract text from a DOM node
    public function checkElement( $p ) {
        // For <br> tags, treat as a line break (empty string)
        if ($p->nodeName === 'br') {
            return '';
        }
        $text = trim(strip_tags($p->nodeValue));
        return $text;
    }
    // Break up sentences by semantics
    public function splitSentences( $paragraphs ) {
        $text = implode( "\n", $paragraphs );
        $this->funcName = "splitSentences";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName(" . strlen($text) . " characters)" );
        $lines = [];
        if( !$text ) {
            return $lines;
        }
        // DEBUG: Print original text before splitting
        $this->log($text, "Original text before splitting");
        // All character cleanup is now in prepareRaw

        // After extracting and cleaning up the text:
        $text = strip_tags($text); // Remove all HTML tags
        // Normalize all whitespace (including non-breaking spaces) to a regular space
        $text = preg_replace('/\s+/u', ' ', $text);

        // Protect abbreviations and initials by replacing their periods with a placeholder
        $placeholder = '[[DOT]]';
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

        // --- End abbreviation and initial protection ---

        // Now split at .!? followed by whitespace and a capital/ʻ/diacritic, but avoid splitting before/after connectors or glottal lines
        // (suggestion: add negative lookahead/lookbehind for connectors/glottal)
        //$pattern = '/(?<=[.?!])\s+(?=[A-Z' . "āĀĒēĪīōŌŪūʻ‘'" . '])/u';
        //$pattern = '/(?<=[.?!])\s+(?=[A-ZāĀĒēĪīōŌŪū])/u';
        $pattern = '/(?<=[.?!])\s+(?=(?![‘ʻ\x{2018}\x{02BB}])[A-ZāĀĒēĪīōŌŪū])/u';
        $lines = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);
        // DEBUG: Print lines after preg_split
        $this->log($lines, "Lines after preg_split");
        
        $results = [];
        foreach ($lines as $line) {

            if ($line !== '') {
                $line = $this->prepareRaw($line);

                // Now remove whitespace around glottal marks
                $line = preg_replace('/\s*([ʻ‘’\x{2018}\x{02BB}])\s*/u', '$1', $line);

                $results[] = $line; // One line per HTML block element
            }
        }
        $lines = $results;
        $merged = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $buffer = trim($lines[$i]);
            $j = $i + 1;
            // Start merging if this line is a likely name/initial fragment
            if (
                preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', $buffer) || // e.g. "Rev.", "Kaona", "K.-, ko makou..."
                preg_match('/^[A-Z]\\.$/', $buffer) ||                     // e.g. "J."
                preg_match('/^[A-Z]\\.-$/', $buffer)                       // e.g. "K.-"
            ) {
                // Merge all following lines that are also likely name/initial fragments
                while (
                    $j < $n &&
                    (
                        preg_match('/^[A-Z]\\.$/', trim($lines[$j])) ||      // single initial
                        preg_match('/^[A-Z]\\.-$/', trim($lines[$j])) ||     // initial+hyphen
                        preg_match('/^[A-Z][a-z]*\\.?(-[a-z, ]+)?$/u', trim($lines[$j])) || // short, capitalized
                        (mb_strlen(trim($lines[$j])) <= 30 && preg_match('/^[A-Z]/', trim($lines[$j])))
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
        $lines = $merged;
        // Restore periods in abbreviations/initials and digit/period refs, and clean up linefeeds in each sentence
        foreach ($lines as &$sentence) {
            $sentence = str_replace($placeholder, '.', $sentence);
            $sentence = trim($sentence);
        }
        unset($sentence);
        // Post-process: join orphaned ‘ or ʻ (with or without leading/trailing whitespace or HTML tags) to next line, then join to previous line
        // DEBUG: Print lines before joining logic
        $this->log($lines, "Lines before joining logic");
        $joined = [];
        $buffer = '';
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = trim($lines[$i]);
            $plain = trim(strip_tags($line));
            $isGlottal = preg_match('/^[‘ʻ\x{2018}\x{02BB}]$/u', $plain);
            $isConj = preg_match('/^-{1,2}\s*[A-ZĀĒĪŌŪ]+(?:\s+[A-ZĀĒĪŌŪ]+)*\s*-{1,2}$/u', str_replace(' ', '', $line));
            $nextIsGlottalOrConj = false;
            $nextLine = ($i + 1 < $n) ? trim($lines[$i + 1]) : '';
            $nextPlain = trim(strip_tags($nextLine));
            $nextIsGlottal = preg_match('/^[‘ʻ\x{2018}\x{02BB}]$/u', $nextPlain);
            $nextIsConj = preg_match('/^-{1,2}\s*[A-ZĀĒĪŌŪ]+(?:\s+[A-ZĀĒĪŌŪ]+)*\s*-{1,2}$/u', str_replace(' ', '', $nextLine));
            $nextIsGlottalOrConj = $nextIsGlottal || $nextIsConj;

            if ($isGlottal || $isConj) {
                // Buffer the conjunction/glottal
                $buffer = rtrim($buffer) . ' ' . $line;
                // If next is not a conjunction/glottal, append it and commit
                if (!$nextIsGlottalOrConj && $nextLine !== '') {
                    $buffer .= ' ' . $nextLine;
                    $joined[] = trim($buffer);
                    $buffer = '';
                    $i++; // skip next line
                }
                // Otherwise, keep buffering
                continue;
            } else {
                if ($buffer !== '') {
                    $joined[] = trim($buffer);
                    $buffer = '';
                }
                $buffer = $line;
                // If next is not a conjunction/glottal, commit
                if (!$nextIsGlottalOrConj) {
                    $joined[] = trim($buffer);
                    $buffer = '';
                }
            }
        }
        if ($buffer !== '') {
            $joined[] = trim($buffer);
        }
        // Optionally, split joined lines into sentences if needed, or just return $joined
        $results = [];
        foreach ($joined as $sentence) {
            if ($this->checkSentence($sentence)) {
                $results[] = $sentence;
            } else {
                $this->discarded[] = $sentence;
            }
        }
        return $results;
    }
    protected function DOMToStringArray( $contents ) {
        $count = count($contents);
        $paragraphs = [];
        for ($idx = 0; $idx < $count; $idx++) {
            $text = $this->checkElement($contents[$idx]);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }
    public function process( $contents ) {
        $this->funcName = "process";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint("$fullName()");
        $paragraphs = $this->DOMToStringArray($contents);
        //debugPrint( "HtmlParse::process() got $count paragraphs" );
        // How should we split the contents - semantically or by
        // HTML elements?
        return $this->splitSentences( $paragraphs );
        //return $this->splitByElements( $paragraphs );
    }
    // Extract all text from a list of DOM nodes
    // Split by HTML element
    public function splitByElements( $contents ) {
        $this->funcName = "splitByElements";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint("$fullName() (one sentence per HTML block element)");
        $lines = [];
        $count = count($contents);
        $saved = '';
        for ($idx = 0; $idx < $count; $idx++) {
            $text = $contents[$idx];
            $this->log( $text, "Processing text from DOM node");
            // Normalize all whitespace (including non-breaking spaces) to a regular space
            $text = preg_replace('/\s+/u', ' ', $text);
            if (trim($text) === '') continue;
            $text = $this->prepareRaw($text);
            $this->log( $text, "after prepareRaw");
            // Remove ALL spaces before ? or !
            $text = preg_replace('/\s+([?!])/', '$1', $text);
            if ($this->checkSentence($text)) {
                $text = preg_replace('/([^\s])\s+([‘ʻ\x{2018}\x{02BB}])/u', '$1$2', $text);            
                $text = trim($text);
                $this->log( $text, "after removing space");
                $lines[] = $text;
            } else {
                $this->discarded[] = $text;
            }
        }
        $lines = $this->connectLines($lines);
        return $lines;
    }
 
    // Extract an array of lines by converting the text into a DOM document and examining all DOM nodes    
    public function extractSentencesFromHTML( $text, $options=[] ) {
        $this->funcName = "extractSentencesFromHTML";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName(" . strlen($text) . " chars)" );
        $this->log( strlen($text) . " chars" );
        if( !$text ) {
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = $this->getDOMFromString( $text, $options );
        $this->extractDate( $dom );
        //$dom = $this->adjustDOM( $dom );
        libxml_use_internal_errors(false);
        $contents = $this->extract( $dom );
        $paraCount = count($contents);
        $this->log( "paragraph count=" . $paraCount );
        //$paragraphs = $this->DOMToStringArray($contents);
        $sentences = $this->process( $contents );
        return $sentences;
    }
    // Extract an array of lines by looking at the text in all tags from an URL    
    public function extractSentences( $url, $options=[] ) {
        $this->funcName = "extractSentences";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName::extractSentences($url)" );
        $this->initialize( $url );
        $contents = $this->getContents( $url, $options );
        $sentences = $this->extractSentencesFromHTML( $contents, $options );
        return $sentences;
    }
    // Stub function to extract the date from the DOM of a document    
    public function extractDate( $dom = null ) {
        $this->funcName = "extractDate";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( $fullName . ": $this->date" );
        return $this->date;
    }
    // Get list of all documents on the Web for a particular parser type    
    public function getPageList() {
        $this->funcName = "getPageList";
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint( "$fullName()" );
        return [];
    }
    // Default extract method for HtmlParse base class to avoid undefined method errors.
    // Derived classes should override this.
    public function extract($dom) {
        $this->funcName = "extract";
        $fullName = $this->logName . ":" . $this->funcName;
        // Return all <p>, <br>, and <div> elements in document order
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query('//*[self::p or self::br or self::div]');
        return $paragraphs;
    }
    // Connect lines using connector patterns (glottals, conjunctions, etc.)
    // $lines: array of input lines
    // Returns: array of joined lines
    public function connectLines(array $lines) {
        return $lines;
    }
}

// --- BEGIN PARSER CLASSES ---

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
    private $basename = "Ka Ulana Pilina";
    private $sourceName = 'Ka Ulana Pilina';
    public $groupname = "kaulanapilina";
    protected $logName = "CBHtml";
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
        debuglog( "CBHtml::initialize: url = " . $this->url . ", date = " . $this->date );
    }
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "CBHtml::getSourceName($title,$url)" );
        $name = $this->sourceName;
        debuglog( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
        debugPrint( "CBHtml::getSourceName: url = " . $this->url . ", date = " . $this->date );
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
        $result = ( strpos( $p->parentNode->getAttribute('class'), 'sidebar-body-content' ) === false );
        return ($result) ? parent::checkElement( $p ) : '';
    }
    public function extract( $dom ) {
        debugPrint( "CBHtml::extract()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "cb-share-content")]//p' );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        debugPrint( "CBHtml::extractDate()" );
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
        debuglog( "CBHtml:extractDate: " . $this->date );
        debugPrint( "CBHtml:extractDate: " . $this->date );
        return $this->date;
    }
    public function getPageList() {
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        debugPrint( "CBHtml::getPageList dom: " . $dom->childElementCount . "\n" );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( ['//h2[contains(@class, "headline")]/a',
                  '//div[contains(@class, "archive")]/p/a'] as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $pp = $p->parentNode->parentNode;
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
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
                    ]
                ];
            }
        }
        return $pages;
    }
}

class AoLamaHTML extends HtmlParse {
    private $basename = "Ke Aolama";
    public $groupname = "keaolama";
    private $urlbase = 'https://keaolama.org/';
    protected $logName = "AoLamaHtml";
    public function __construct( $options = [] ) {
        $this->urlBase = $this->urlbase;
        $this->startMarker = "penei ka nūhou";
        $this->baseurl = "https://keaolama.org/?infinity=scrolling&page=";
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
    }
    public function getSourceName( $title = '', $url = '' ) {
        if( $title ) {
            $this->title = $title;
        }
        debugPrint( "AolamaHTML::getSourceName title=" . $this->title );
        $sourceName = "";
        $parts = explode( '/', parse_url( $this->url, PHP_URL_PATH ) );
        if( sizeof($parts) > 3 ) {
            $sourceName = "{$this->basename} {$parts[1]}-{$parts[2]}-{$parts[3]}";
            debugPrint( "AolamaHTML::getSourceName sourceName=" . $sourceName );
        }
        if( !$sourceName ) {
            $sourceName = $this->basename;
            $this->title = $sourceName;
        }
        return $sourceName;
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        debugPrint( "AolamaHTML::extractDate url=" . $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        $path = parse_url( $this->url, PHP_URL_PATH );
        $parts = explode( '/', $path );
        if( sizeof($parts) > 3 ) {
            $dateparts = explode( '-', $parts[4] );
            $this->date = $parts[1] . "-" . $dateparts[0] . "-" . $dateparts[1];
        }
        debugPrint( "AolamaHTML::extractDate found [{$this->date}]" );
        return $this->date;
    }
    public function getPageList() {
        debugPrint( "AolamaHTML::getPageList()" );
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
                if( sizeof($parts) > 3 ) {
                    $text = $parts[0] . "-" . $parts[1] . "-" . $parts[2];
                }
                $text = $this->basename . ": $text";
                $pages[] = [
                    $text => [
                        'url' => $u,
                        'image' => '',
                        'title' => $text,
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
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
    private $urlbase = 'https://www.kauakukalahale.com/';
    protected $logName = "KauakukalahaleHTML";
    public function __construct( $options = [] ) {
        $this->urlBase = $this->urlbase;
        $this->baseurl = $this->urlbase;
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        $this->dom = $this->getDOM( $this->url );
        $this->extractDate( $this->dom );
        $this->title = $this->basename . ' ' . $this->date;
    }
    public function getSourceName( $title = '', $url = '' ) {
        if( $title ) {
            $this->title = $title;
        }
        $sourceName = $this->basename;
        return $sourceName;
    }
    public function extract( $dom ) {
        $xpath = new DOMXpath($dom);
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        if( !$dom ) {
            $dom = $this->dom;
        }
        $xpath = new DOMXpath($dom);
        $query = '//time';
        $times = $xpath->query( $query );
        if( $times->length > 0 ) {
            $date = $times->item(0)->nodeValue;
            $this->date = date('Y-m-d', strtotime($date));
        }
        return $this->date;
    }
    public function getPageList() {
        $dom = $this->getDOM( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $pages = [];
        $query = '//h2[@class="entry-title"]/a';
        $paragraphs = $xpath->query( $query );
        foreach( $paragraphs as $p ) {
            if ($p instanceof DOMElement) {
                $url = $p->getAttribute( 'href' );
                $text = trim( $this->prepareRaw( $p->nodeValue ) );
                $pages[] = [
                    $text => [
                        'url' => $url,
                        'image' => '',
                        'title' => $text,
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
                    ]
                ];
              }
        }
        return $pages;
    }
}

class NupepaHTML extends HtmlParse {
    private $basename = "Nupepa";
    public $groupname = "nupepa";
    private $domain = "https://nupepa.org/";
    private $sourceName = 'Nupepa';
    protected $logName = "NupepaHTML";
    private $skipTitles = [
        "Ka Wai Ola - Office of Hawaiian Affairs",
    ];  // Almost no Hawaiian text
    private $pagemap;
    private $pagetitles;
    public function __construct( $options = [] ) {
        $this->urlBase = trim( $this->domain, "/" );
        $this->baseurl = "https://nupepa.org/?a=cl&cl=CL2";
    }
    public function initialize( $baseurl ) {
        parent::initialize( $baseurl );
        debugPrint( "$this->logName::initialize($baseurl)" );
        $this->dom = $this->getDOM( $baseurl );
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
            debugPrint( "$this->logName::initialize json_decoding pattern " . $pagetitles );
            $this->pagetitles = json_decode( $pagetitles, true );
        } else {
            debugPrint( "$this->logName::initialize no pattern $pattern" );
        }
        printObject( $this->pagetitles, "$this->logName pageTitles" );
    }
        public function getDOM( $url, $options = [] ) {
        debugPrint( "$this->logName::getDOM($url)" );
        return parent::getDOM( $url, ['preprocess' => false,] );
    }
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "$this->logName::getSourceName($title,$url)" );
        $this->sourceName = '';
        if( $title ) {
            $this->sourceName = $title;
        } else if( $url ) {
            $this->url = $url;
            $this->dom = $this->getDOM( $this->url );
        }
        if( !$this->sourceName && $this->dom ) {
            $title = $this->extractTitle( $this->dom );
            $this->sourceName = $title;
        }
        debuglog( "$this->logName::getSourceName: url = " . $this->url . ", sourceName = " . $this->sourceName );
        debugPrint( "$this->logName::getSourceName: url = " . $this->url . ", sourceName = " . $this->sourceName );
        return $this->sourceName;
    }
     public function getPageList() {
        debugPrint( "$this->logName::getPageList()" );
        $dom = $this->getDOM( $this->baseurl );
        $xpath = new DOMXpath($dom);
        $query = '//a[@class="nav-link"]';
        $months = $xpath->query( $query );
        $pages = [];
        foreach( $months as $month ) {
            $monthUrl = $this->urlBase . $month->getAttribute( 'href' );
            //$monthUrl = $month->getAttribute( 'href' );
            debugPrint( "monthUrl = $monthUrl" );

            $dom = $this->getDOM( $monthUrl );
            if( $dom ) {
                $xpath = new DOMXpath($dom);
                $query = "//li[@class='list-group-item']/a";
                $issues = $xpath->query( $query );
                foreach( $issues as $issue ) {
                    $issueUrl = $issue->getAttribute( 'href' );
                    $issueUrl = preg_replace( '/\&e=.*/', '', $issueUrl );
                    $issueUrl = $this->urlBase . $issueUrl;
                    debugPrint( $issueUrl );
                    $issuedom = $this->getDOM( $issueUrl );
                    $title = $this->extractTitle( $issuedom );
                    $ok = true;
                    foreach( $this->skipTitles as $pattern ) {
                        if( strpos( $title, $pattern ) !== false ) {
                            $ok = false;
                            break;
                        }
                    }
                    if( $ok ) {
                        $date = $this->extractDate( $issuedom );
                        $pages[] = [
                            $title => [
                                'url' => $issueUrl,
                                'image' => '',
                                'title' => $title,
                                'date' => $date,
                                'author' => $this->authors,
                                'groupname' => $this->groupname,
                            ]
                        ];
                    }
                }
            }
            if( sizeof( $pages ) >= 100 ) {
                //break;
            }
        }
        debuglog( "Pages: " . var_export( $pages, true ) );
        debugPrintObject( $pages, "$this->logName::getPageList()" );
        return $pages;
    }
    public function getRawText( $pageurl, $options=[] ) {
        debugPrint( "$this->logName::getRawText( $pageurl," . var_export( $options, true ) . ")" );
        $options = ['preprocess' => false,];
        $fulltext = "";
        $hasHead = false;
        $count = 0;
        debugPrintObject( $this->pagetitles, "$this->logName::getRawText pagetitles" );
        // Some page lists distinguish between cover material and contents
        $hasCover = false;
        foreach( $this->pagetitles as $key => $title ) {
            if( preg_match( "/Page /", $title ) ) {
                $hasCover = true;
                break;
            }
        }
        foreach( $this->pagetitles as $key => $title ) {
            debugPrint( "$this->logName::getRawText( $key " . " => " . $title );
            if( !$hasCover || preg_match( "/Page \d+\s*.*(\<|\&lt)/", $title ) ) {
            //if( preg_match( "/\&lt/", $title ) ) {
                $url = "$pageurl.$key&st=1&dliv=none";
                echo "$url\n";
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
                $count++;
            }
        }
        if( $count ) {
            $fulltext .= '</html>';
        } else {
            $fulltext = "";
        }
        return $fulltext;
    }
    public function extract( $dom ) {
        debuglog( "$this->logName::extract({$dom->childElementCount} nodes)" );
        debugPrint( "$this->logName::extract({$dom->childElementCount} nodes)" );
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
    public function extractTitle( $dom ) {
        debugPrint( "$this->logName::extractTitle url=" . $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        $xpath = new DOMXpath($dom);
        $query = '//head/title';
        $titles = $xpath->query( $query );
        $title = $titles->item(0)->nodeValue;
        $title = htmlentities( $title );
        $title = preg_replace( '/ \&mdash\;.*/', '', $title );
        $this->title = $title;
        return $this->title;
    }
    public function extractDate( $dom = null ) {
        debugPrint( "$this->logName::extractDate url=" . $this->url );
        if( !$dom ) {
            $dom = $this->dom;
        }
        $title = $this->extractTitle( $dom );
        $parts = explode( " ", $title );
        $len = sizeof( $parts );
        $date = "{$parts[$len-3]} {$parts[$len-2]} {$parts[$len-1]}";
        $date = date( 'Y-m-d', strtotime( $date ) );
        $this->date = $date;
        debugPrint( "$this->logName::extractDate found [{$this->date}]" );
        return $this->date;
    }
    public function process( $contents ) {
        $fullName = $this->logName . ":" . $this->funcName;
        debugPrint("$fullName()");
        $paragraphs = $this->DOMToStringArray($contents);
        // How should we split the contents - semantically or by
        // HTML elements?
        return $this->splitSentences( $paragraphs );
        //return $this->splitByElements( $paragraphs );
    }
    // Connect lines using connector patterns (glottals, conjunctions, etc.)
    // $lines: array of input lines
    // Returns: array of joined lines
    public function connectLines(array $lines) {
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

class UlukauHTML extends HtmlParse {
    protected $logName = "UlukauHTML";
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "UlukauHTML::getSourceName($title,$url)" );
        $name = "Ulukau";
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
    //public function extract( $dom ) {
    //}
    public function extractDate( $dom = null ) {
        debugPrint( "UlukauHTML::extractDate()" );
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
        debuglog( "UlukauHTML:extractDate: " . $this->date );
        debugPrint( "UlukauHTML:extractDate: " . $this->date );
        return $this->date;
    }
    public function getPageList() {
        debugPrint( "UlukauHTML::getPageList()" );
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        debugPrint( "UlukauHTML::getPageList dom: " . $dom->childElementCount . "\n" );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( ['//h2[contains(@class, "headline")]/a',
                  '//div[contains(@class, "archive")]/p/a'] as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $pp = $p->parentNode->parentNode;
                $url = $p->getAttribute( 'href' );
                debugPrint( "UlukauHTML::getPageList checking: $url" );
                $pagedom = $this->getDom( $url );
                $date = $this->extractDate( $pagedom );
                $sourcename = "Ulukau: " . $date;
                $text = trim( $this->prepareRaw( $p->nodeValue ) );
                $pages[] = [
                    $sourcename => [
                        'url' => $url,
                        'image' => '',
                        'title' => $text,
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
                    ]
                ];
              }
        }
        return $pages;
    }
}

class KaPaaMooleloHTML extends HtmlParse {
    protected $logName = "KaPaaMooleloHTML";
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "KaPaaMooleloHTML::getSourceName($title,$url)" );
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
        debugPrint( "KaPaaMooleloHTML::extract()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        debugPrint( "KaPaaMooleloHTML::extractDate()" );
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
        debuglog( "KaPaaMooleloHTML:extractDate: " . $this->date );
        debugPrint( "KaPaaMooleloHTML:extractDate: " . $this->date );
        return $this->date;
    }
    public function getPageList() {
        debugPrint( "KaPaaMooleloHTML::getPageList()" );
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        debugPrint( "KaPaaMooleloHTML::getPageList dom: " . $dom->childElementCount . "\n" );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( ['//h2[contains(@class, "headline")]/a',
                  '//div[contains(@class, "archive")]/p/a'] as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $pp = $p->parentNode->parentNode;
                $url = $p->getAttribute( 'href' );
                debugPrint( "KaPaaMooleloHTML::getPageList checking: $url" );
                $pagedom = $this->getDom( $url );
                $date = $this->extractDate( $pagedom );
                $sourcename = "Ka Paa Moolelo: " . $date;
                $text = trim( $this->prepareRaw( $p->nodeValue ) );
                $pages[] = [
                    $sourcename => [
                        'url' => $url,
                        'image' => '',
                        'title' => $text,
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
                    ]
                ];
              }
        }
        return $pages;
    }
}

class BaibalaHTML extends HtmlParse {
    protected $logName = "BaibalaHTML";
    public function getSourceName( $title = '', $url = '' ) {
        debugPrint( "BaibalaHTML::getSourceName($title,$url)" );
        $name = "Baibala";
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
        debugPrint( "BaibalaHTML::extract()" );
        $xpath = new DOMXpath( $dom );
        $paragraphs = $xpath->query( '//div[contains(@class, "entry-content")]//p' );
        if( count( $paragraphs ) < 1 ) {
            // Try a different query when reading from the already downloaded HTML
            $paragraphs = $xpath->query( '//body' );
        }
        return $paragraphs;
    }
    public function extractDate( $dom = null ) {
        debugPrint( "BaibalaHTML::extractDate()" );
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
        debuglog( "BaibalaHTML:extractDate: " . $this->date );
        debugPrint( "BaibalaHTML:extractDate: " . $this->date );
        return $this->date;
    }
    public function getPageList() {
        debugPrint( "BaibalaHTML::getPageList()" );
        $dom = $this->getDOM( $this->baseurl );
        $this->extractDate( $dom );
        debugPrint( "BaibalaHTML::getPageList dom: " . $dom->childElementCount . "\n" );
        $xpath = new DOMXpath($dom);
        $pages = [];
        foreach( ['//h2[contains(@class, "headline")]/a',
                  '//div[contains(@class, "archive")]/p/a'] as $query ) {
            $paragraphs = $xpath->query( $query );
            foreach( $paragraphs as $p ) {
                if (!($p instanceof DOMElement)) continue;
                $pp = $p->parentNode->parentNode;
                $url = $p->getAttribute( 'href' );
                debugPrint( "BaibalaHTML::getPageList checking: $url" );
                $pagedom = $this->getDom( $url );
                $date = $this->extractDate( $pagedom );
                $sourcename = "Baibala: " . $date;
                $text = trim( $this->prepareRaw( $p->nodeValue ) );
                $pages[] = [
                    $sourcename => [
                        'url' => $url,
                        'image' => '',
                        'title' => $text,
                        'groupname' => $this->groupname,
                        'author' => $this->authors,
                    ]
                ];
              }
        }
        return $pages;
    }
}

class EhoouluLahuiHTML extends HtmlParse {
    private $basename = "Ehooulu Lahui";
    public $groupname = "ehooululahui";
    protected $logName = "EhoouluLahuiHTML";
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
    
    public function extractDate( $dom = null ) {
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
                    'author' => '',
                    'groupname' => $this->groupname,
                ],
            ],
            [
            'Lonoikamakahiki' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=67',
                    'image' => '',
                    'title' => 'Lonoikamakahiki',
                    'author' => '',
                    'groupname' => $this->groupname,
                ],
            ],
            [
            'Punia' =>
                [
                    'url' => 'https://ehooululahui.maui.hawaii.edu/?page_id=69',
                    'image' => '',
                    'title' => 'Punia',
                    'author' => '',
                    'groupname' => $this->groupname,
                ],
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
                    'author' => $this->authors,
                    'groupname' => $this->groupname,
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
    protected $logName = "TextParse";
    public function getSentences( $text ) {
        return $this->splitSentences( $text );
    }
}

<?php
namespace Noiiolelo;

require_once __DIR__ . '/../../noiiolelo/db/funcs.php';
require_once __DIR__ . '/SearchProviderInterface.php';

class LaanaSearchProvider implements SearchProviderInterface
{
    private \Laana $laana;
    public int $pageSize;
    public $sentenceMask;

    public function __construct( $options )
    {
        $this->laana = new \Laana();
        $this->pageSize = $this->laana->pageSize;
    }

    public function getName(): string {
        return "Laana";
    }
    
    // Direct pass-through methods
    public function getLatestSourceDates() { return $this->laana->getLatestSourceDates(); }
    public function getSources($groupname = '') { return $this->laana->getSources($groupname); }
    public function getTotalSourceGroupCounts() { return $this->laana->getTotalSourceGroupCounts(); }
    public function getSource( $sourceid ) { return $this->laana->getSource( $sourceid ); }    
    public function getSentencesBySourceID( $sourceid ) { return $this->laana->getSentencesBySourceID( $sourceid ); }
    public function getText( $sourceid ) { return $this->laana->getText( $sourceid ); }
    public function getRawText( $sourceid ) { return $this->laana->getRawText( $sourceid ); }
    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) {
        // Prepare masking parameters for processing each result
        $this->getSentenceMask( $term, $pattern, $options );
        return $this->laana->getSentences($term, $pattern, $pageNumber, $options);
    }
    public function getSentence($sentenceid) { return $this->laana->getSentence($sentenceid); }
    public function addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ) { return $this->laana->addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ); }
    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) { return $this->laana->getMatchingSentenceCount( $term, $pattern, $pageNumber, $options ); }

    // Interface-required methods
    public function getSourceMetadata(): array { return $this->laana->getSources(); }
    public function getCorpusStats(): array {
        return [
            'sentence_count' => $this->laana->getSentenceCount(),
            'source_count' => $this->laana->getSourceCount()
        ];
    }
    public function search(string $query, string $mode, int $limit, int $offset): array {
        $pageNumber = floor($offset / $this->pageSize);
        $hits = $this->getSentences($query, $mode, $pageNumber, []);
        return ['hits' => $hits, 'total' => count($hits)];
    }
    public function getDocument(string $docId, string $format = 'text'): ?array {
        if ($format === 'html') {
            return ['content' => $this->getRawText($docId)];
        }
        return ['content' => $this->getText($docId)];
    }
    public function logQuery(array $params): void {
        $this->addSearchStat(
            $params['searchterm'],
            $params['pattern'],
            $params['results'],
            $params['sort'],
            $params['elapsed']
        );
    }

    public function getAvailableSearchModes(): array
    {
        return ['exact' => 'Exact match of one or more words',
                'any' => 'Match any of the words',
                'all' => 'Match all of the words in any order',
                'regex' => 'Regular expression match',
        ];
    }

    public function providesHighlights(): bool
    {
        // The MySql search client provides no highlights for matches
        return false;
    }

    public function providesNoDiacritics(): bool
    {
        // The MySql search client provides no support for diacritic insensitivity
        return false;
    }
    public function formatLogMessage( $msg, $intro = "" )
    {
        return formatLogMessage( $msg, $intro );
    }
    
    public function debuglog( $msg, $intro = "" )
    {
        return $this->laana->debuglog( $msg, $intro );
    }
    
    public function normalizeString( $term ) {
        return normalizeString( $term );
    }

    public function getSentenceMask( $word, $pattern, $params ) {
        $this->debuglog( "($pattern,$word," . json_encode($params) . ")", "getSentenceMask" );
        // We have to figure out the highlighting here
        // This is actually both for highlight support and for diacritic insensitivity
        $replace = [
            /*
               "/a|ā|Ā/" => "‘|ʻ*[aĀā]",
               "/e|ē|Ē/" => "‘|ʻ*[eĒē]",
               "/i|ī|Ī/" => "‘|ʻ*[iĪī]",
               "/o|ō|Ō/" => "‘|ʻ*[oŌō]",
               "/u|ū|Ū/" => "‘|ʻ*[uŪū]",
             */
            "/a|ā|Ā/" => "ʻ*[aĀā]",
            "/e|ē|Ē/" => "ʻ*[eĒē]",
            "/i|ī|Ī/" => "ʻ*[iĪī]",
            "/o|ō|Ō/" => "ʻ*[oŌō]",
            "/u|ū|Ū/" => "ʻ*[uŪū]",
        ];
        $repl = "<span>$1</span>";
        $target = ($params['nodiacriticals']) ? $this->normalizeString( $word ) :  $word;
        //$target = $provider->normalizeString( $word );
        if( $pattern != 'exact' && $pattern != 'regex' ) {
            $target = str_replace( 'ʻ', 'ʻ*', $target );
        }
        $targetwords = preg_split( "/[\s]+/",  $target );
        $tw = '';
        $pat = '';
        $stripped = '';
        if( $pattern == 'exact' || $pattern == 'regex' ) {
            $target = preg_replace( '/^‘/', '', $target );
        }
        if( $pattern == 'exact' ) {
            $tw = "\\b$target\\b";
        } else if( $pattern == 'any' || $pattern == 'all' ) {
            $quoted = preg_match( '/^".*"$/', $target );
            if( $quoted ) {
                $stripped = preg_replace( '/^"/', '', $target );
                $stripped = preg_replace( '/"$/', '', $stripped );
                $tw = '\\b' . $stripped . '\\b';
            } else {
                $tw = '\\b' . implode( '\\b|\\b', $targetwords ) . '\\b';
            }
        } else if( $pattern == 'order' ) {
            $tw = '\\b' . implode( '\\b.*\\b', $targetwords ) . '\\b';
        } else if( $pattern == 'regex' ) {
            $tw = str_replace( "[[:>:]]", "\\b",
                               str_replace("[[:<:]]", "\\b", $target) );
        }
        
        //$this->debuglog( "target: $target, targetwords: " . json_encode($targetwords) . ", tw: $tw", "getSentenceMask" );
        if( $tw ) {
            $expanded = "/(" . preg_replace( array_keys( $replace ),
                                             array_values( $replace ),
                                             $tw ) . ")/ui";
            
            $pat = "/(" . $tw . ")/ui";
            if( $pattern != 'exact' && $pattern != 'regex' ) {
                $pat = $expanded;
            }
            $repl = '<span class="match">$1</span>';
            $this->debuglog( "getPageHTML highlight: target=$target, pat=$pat, repl=$repl");
        }
        $this->sentenceMask = [
            'pat' => $pat,
            'stripped' => $stripped,
            'repl' => $repl,
        ];
        $this->debuglog( $this->sentenceMask, "sentenceMask" );
        return $this->sentenceMask;
    }

    public function checkStripped( $hawaiianText ) {
        if( $this->sentenceMask &&
            $this->sentenceMask['stripped'] &&
            !preg_match( '/' . $sentenceMask['stripped'] . '/', $hawaiiantext ) ) {
            $provider->debuglog("Skipping sentence: stripped={$this['sentenceMask']['stripped']}, hawaiiantext=$hawaiiantext");
            return false;
        }
        return true;
    }
    
    public function processText( $hawaiiantext ) {
        if ( $this->sentenceMask ) {
            return preg_replace($this->sentenceMask['pat'], $this->sentenceMask['repl'], $hawaiiantext );
        }
        return $hawaiiantext;
    }
    
    // Mapping between mysql search modes and elastic search modes for pattern matching
    public function normalizeMode( $mode ) {
        $map = [
            'term' => 'exact',
            'termsentence' => 'exact',
            'phrase' => 'order',
            'phrasesentence' => 'order',
            'match' => 'any',
            'matchsentence' => 'any',
            'regexp' => 'regex',
            'regexpsentence' => 'regex',
        ];
        return $map[$mode] ?? $mode;
    }

    public function getSourceGroupCounts() {
        return $this->laana->getSourceGroupCounts();
    }

    public function getRandomWord() {
        return $this->laana->getRandomWord();
    }
}

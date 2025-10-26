<?php
namespace Noiiolelo;

require_once __DIR__ . '/SearchProviderInterface.php';
require_once __DIR__ . '/../../elasticsearch/php/src/ElasticsearchClient.php';
require_once __DIR__ . '/../../elasticsearch/php/src/EmbeddingClient.php';
require_once __DIR__ . '/../../elasticsearch/php/src/MetadataCache.php';

// For source metadata, for now
require_once __DIR__ . '/../db/funcs.php';

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\EmbeddingClient;
use HawaiianSearch\MetadataCache;
use Dotenv\Dotenv;

class ElasticsearchProvider implements SearchProviderInterface {
    private ElasticsearchClient $client;
    public int $pageSize = 5;
    private bool $verbose;
    private bool $quiet = true;

    public function __construct( $options ) {
        /*
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
         */
        $this->verbose = $options['verbose'] ?? false;
        $this->client = new ElasticsearchClient([
            'verbose' => $this->verbose,
            'quiet' => true,
        ]);
    }

    public function getName(): string {
        return "Elasticsearch";
    }
    
    public function print( $msg, $prefix="" ) {
        if( $this->quiet ) {
            return;
        }
        if( is_object( $msg ) || is_array( $msg ) ) {
            $msg = var_export( $msg, true );
        }
        if( $prefix ) {
            $msg = "$prefix: $msg";
        }
        echo "$msg\n";
    }
    
    public function search(string $query, string $mode, int $limit=10, int $offset=0, array $source_excludes = [], array $source_includes = []): array {
        $this->print( "search($query,$mode,$limit,$offset)" );
        try {
            // Note: client->search signature is (query, mode, maxResults, offset, indexName, sortOptions)
            // It does not support source filtering directly. This would need to be added to QueryBuilder.
            $response = $this->client->search($query, $mode, [
                'k' => $limit,
                'offset' => $offset
            ]);
            if ($response === null) {
                return ['hits' => [], 'total' => 0];
            }

            // The response from the client is already a flat array of results.
            return [
                'hits' => $response,
                'total' => count($response)
            ];
        } catch (\Exception $e) {
            error_log("Elasticsearch search failed: " . $e->getMessage());
            return ['hits' => [], 'total' => 0];
        }
    }

    public function getDocument(string $docId, string $format = 'text'): ?array {
        $this->print( "getDocument($docId,$format)" );

        return ($format === 'text') ? $this->client->getDocumentText( $docId ) : $this->client->getDocumentRaw( $docId );
    }

    protected function documentToSentenceMode( $mode ) {
        $pattern = $mode;
        if( strpos($mode, 'sentence') !== false ) {
            $this->debuglog( "documentToSentenceMode: $mode already is a sentence mode" );
            return $mode;
        }
        switch ($mode) {
            case 'phrase':
                $mode = 'phrasesentence';
                break;
            case 'hybrid':
                $mode = 'hybridsentence';
                break;
            case 'match':
                $mode = 'matchsentence';
                break;
            case 'matchall':
                $mode = 'matchsentence_all'; // Use the new "all words" (AND) mode.
                break;
            case 'regex':
                $mode = 'regexpsentence';
                break;
            default:
                $mode = 'matchsentence';
                break;
        }
        $this->debuglog( "documentToSentenceMode: converted $pattern to $mode" );
        return $mode;
    }
   
    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) {
        // Date filtering support
        $dateFilter = null;
        if (!empty($options['from']) || !empty($options['to'])) {
            $dateFilter = ['range' => ['date' => []]];
            if (!empty($options['from'])) {
                $dateFilter['range']['date']['gte'] = $options['from'];
            }
            if (!empty($options['to'])) {
                $dateFilter['range']['date']['lte'] = $options['to'];
            }
        }
        $this->print( "getSentences($term, pattern: $pattern, page: $pageNumber)" );

        $limit = $options['limit'] ?? ($this->pageSize > 0 ? $this->pageSize : 10);
        $offset = 0;
        if ($pageNumber > 0) {
            $offset = $pageNumber * $limit;
        }

        // Map the pattern from getPageHtml.php to a mode understood by ElasticsearchClient's QueryBuilder.
        $mode = $this->documentToSentenceMode( $pattern );

        $sortOptions = [];
        $orderByString = "";
        error_log('ElasticsearchProvider getSentences: options[orderby]=' . json_encode($options['orderby'] ?? null));
        if (isset($options['orderby'])) {
            $orderByString = $options['orderby'];
            $sortParts = explode(',', $orderByString);
            foreach ($sortParts as $part) {
                $part = strtolower(trim($part));
                $direction = 'asc';
                if ($part === '') continue;
                if (str_ends_with($part, ' desc')) {
                    $direction = 'desc';
                    $part = strtolower(trim(str_replace(' desc', '', $part)));
                }
                error_log('ElasticsearchProvider sort parsing: part=' . json_encode($part) . ', direction=' . json_encode($direction));

                // Map provider field names to Elasticsearch field names and special sorts
                switch ($part) {
                    case 'hawaiiantext':
                    case 'alpha':
                        // For sentence-level queries, use 'text.keyword' and respect direction
                        $sortOptions['text.keyword'] = $direction;
                        break;
                    case 'sourcename':
                    case 'source':
                        $sortOptions['sourcename'] = $direction;
                        break;
                    case 'date':
                        $sortOptions['date'] = $direction;
                        break;
                    case 'length':
                        $sortOptions['length'] = $direction;
                        break;
                    case 'rand':
                    case 'rand()':
                        $sortOptions['_special'] = 'random';
                        break;
                    case 'none':
                        $sortOptions['_special'] = 'none';
                        break;
                    case 'score':
                        $sortOptions['_special'] = 'score';
                        break;
                    default:
                        if (strpos($part, 'length(hawaiiantext)') !== false) {
                            $sortOptions['length'] = $direction;
                        }
                        break;
                }
            }
            // Debug: print sortOptions to error log
            error_log('ElasticsearchProvider getSentences sortOptions: ' . json_encode($sortOptions));
        }

        $searchOptions = [
            'k' => $limit,
            'offset' => $offset,
            'sentence_highlight' => true,
        ];
        if (!empty($sortOptions)) {
            $searchOptions['sort'] = $sortOptions;
        }
        if (!empty($dateFilter)) {
            $searchOptions['date_filter'] = $dateFilter;
        }
        $rawResults = $this->client->search($term, $mode, $searchOptions);
        $this->print( "getSentences: using mode='$mode', got " . count($rawResults ?: []) . " results" );

        $formattedResults = [];
        if ($rawResults && is_array($rawResults)) {
            foreach ($rawResults as $hit) {
                if( $this->verbose ) {
                    echo "ElasticsearchProvider getSentences: " . json_encode( $hit ) . "\n";
                }
                $formattedResults[] = [
                    "sentenceid" => $hit["_id"], // This needs to be unique, will be fixed in ElasticsearchClient
                    "sourcename" => $hit["sourcename"] ?? 'unknown',
                    "authors" => is_array($hit["authors"]) ? implode(', ', $hit["authors"]) : ($hit["authors"] ?? ""),
                    "sourceid" => $hit["sourceid"] ?? $hit["_id"] ?? "",
                    "date" => $hit["date"] ?? "",
                    //"hawaiiantext" => trim($hit["text"] ?? ''),
                    "hawaiiantext" => trim($hit["highlighted_text"] ?? ''),
                    "link" => ""
                ];
            }
        }

        $this->print( "Formatted sentence results: " . count($formattedResults) . " individual sentences" );
        return $formattedResults;
    }

    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $pattern = $this->documentToSentenceMode( $pattern );
        $this->print( "getMatchingSentenceCount($term,$pattern)" );
        $this->debuglog( "getMatchingSentenceCount($term,$pattern)" );
        return $this->client->getMatchingSentenceCount( $term, $pattern );
    }

    public function getSourceMetadata(): array {
        $this->print( "getSourceMetadata" );
        try {
            $params = [
                'index' => $this->index,
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'sources' => [
                            'terms' => [
                                'field' => 'sourcename',
                                'size' => 1000
                            ]
                        ],
                        'groups' => [
                            'terms' => [
                                'field' => 'groupname',
                                'size' => 1000
                            ]
                        ],
                        'authors' => [
                            'terms' => [
                                'field' => 'authors',
                                'size' => 1000
                            ]
                        ]
                    ]
                ]
            ];

            $response = $this->client->search($params);
            $aggregations = $response['aggregations'] ?? [];
            
            return [
                'sources' => array_column($aggregations['sources']['buckets'] ?? [], 'key'),
                'groups' => array_column($aggregations['groups']['buckets'] ?? [], 'key'),
                'authors' => array_column($aggregations['authors']['buckets'] ?? [], 'key')
            ];
        } catch (\Exception $e) {
            error_log("Failed to get source metadata: " . $e->getMessage());
            return ['sources' => [], 'groups' => [], 'authors' => []];
        }
    }

    public function getCorpusStats(): array {
        return $this->client->getCorpusStats();
    }

    public function getTotalSourceGroupCounts(): array
    {
        return $this->client->getTotalSourceGroupCounts();
    }

    public function logQuery(array $params): void
    {
        // TODO: Replace this with writing to a new index in Elasticsearch
        $logFile = '/tmp/php_errorlog';
        $message = date('Y-m-d H:i:s') . " " . json_encode($params) . "\n";
        file_put_contents($logFile, $message, FILE_APPEND);
    }

    public function getAvailableSearchModes(): array
    {
        return [
            'match' => 'Match words anywhere in sentences', 
            'matchall' => 'Match all words anywhere in sentences', 
            'phrase' => 'Match exact phrase in sentences',
            'term' => 'Match one word',
            'hybrid' => 'Hybrid keyword + semantic search on sentences',
        ];
    }

    public function getLatestSourceDates(): array
    {
        return $this->client->getLatestSourceDates();
    }

    public function providesHighlights(): bool
    {
        // The elastic search client provides highlights for matches
        return true;
    }

    public function providesNoDiacritics(): bool
    {
        // The elastic search client provides support for diacritic insensitivity
        return true;
    }
    public function formatLogMessage( $msg, $intro = "" )
    {
        if( is_object( $msg ) || is_array( $msg ) ) {
            $msg = var_export( $msg, true );
        }
        $defaultTimezone = 'Pacific/Honolulu';
        $now = new \DateTimeImmutable( "now", new \DateTimeZone( $defaultTimezone ) );
        $now = $now->format( 'Y-m-d H:i:s' );
        $out = "$now " . $_SERVER['SCRIPT_NAME'];
        if( $intro ) {
            $out .= " $intro:";
        }
        return "$out $msg";
   }
    
    public function debuglog( $msg, $intro = "" )
    {
        $msg = $this->formatLogMessage( $msg, $intro );
        error_log( "$msg\n" );
    }

    public function checkStripped( $hawaiianText ) {
        return true;
    }

    public function processText( $hawaiiantext ) {
        // Replace elastic search highlight markup with our own
        $text = str_replace( ['<mark>', '</mark>'], ['<span class="match">', '</span>'], $hawaiiantext );
        //echo "processText: converted |$hawaiiantext| to |$text|\n";
        return $text;
    }
    
    public function normalizeString( $term ) {
        $a = array( 'ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', '‘', 'ʻ' );
        $b = array('o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', '', '' );
        return str_replace($a, $b, $term);
    }
    
    // Mapping between mysql search modes and elastic search modes for pattern matching
    public function normalizeMode( $mode ) {
        return $mode;
    }

    // Fix this
    public function getRandomWord() {
        return $this->client->getRandomWord();
    }

    public function getSourceGroupCounts() {
        return $this->client->getTotalSourceGroupCounts();
    }

    public function getSources( $groupname ) {
        $sources = $this->client->getAllRecords( $this->client->getSourceMetadataName() );
        return array_column( $sources, "_source" );
    }

    public function getSentencesBySourceID( $sourceid ) {
        $sentences = $this->client->getSentencesBySourceID( $sourceid );
        return $sentences;
    }

    public function getSource( $sourceid ) {
        return $this->client->getDocumentOutline( $sourceid );
    }

    public function getText( $sourceid ) {
        return $this->client->getDocumentText( $sourceid );
    }
    
    public function getRawText( $sourceid ) {
        return $this->client->getDocumentRaw( $sourceid );
    }
}

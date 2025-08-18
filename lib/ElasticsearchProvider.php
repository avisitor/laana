<?php
namespace NoiOlelo;

require_once __DIR__ . '/SearchProviderInterface.php';
require_once __DIR__ . '/../../elasticsearch/php/src/ElasticsearchClient.php';
require_once __DIR__ . '/../../elasticsearch/php/src/EmbeddingClient.php';
require_once __DIR__ . '/../../elasticsearch/php/src/MetadataCache.php';

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\EmbeddingClient;
use HawaiianSearch\MetadataCache;
use Dotenv\Dotenv;

class ElasticsearchProvider implements SearchProviderInterface {
    private ElasticsearchClient $client;
    public int $pageSize = 5;
    private bool $verbose;
    private bool $quiet = true;

    public function __construct() {
        /*
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
         */
        $this->verbose = false;
        $this->client = new ElasticsearchClient([
            'verbose' => $this->verbose,
            'quiet' => true,
        ]);
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
            $response = $this->client->search($query, $mode, $limit, $offset);
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
        return $this->client->getDocument( $docId );
    }

    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) {
        $this->print( "getSentences($term, pattern: $pattern, page: $pageNumber)" );

        $limit = $this->pageSize > 0 ? $this->pageSize : 10;
        $offset = 0;
        if ($pageNumber > 0) {
            $offset = $pageNumber * $limit;
        }

        // Map the pattern from getPageHtml.php to a mode understood by ElasticsearchClient's QueryBuilder.
        $mode = 'matchsentence'; // default
        switch ($pattern) {
            case 'exact':
            case 'phrase':
                $mode = 'phrasesentence';
                break;
            case 'any':
                $mode = 'matchsentence'; // Assumes 'matchsentence' is OR
                break;
            case 'all':
                $mode = 'matchsentence_all'; // Assumes a mode for AND logic exists.
                break;
            case 'regex':
            case 'order': // Treat 'order' as regex for now, as getPageHtml does.
                $mode = 'regexp_sentence'; // Assumes a mode for regex on sentences.
                break;
        }

        $sortOptions = [];
        if (isset($options['orderby'])) {
            $orderByString = $options['orderby'];
            // Translate SQL-like order to ES sort array
            $sortParts = explode(',', $orderByString);
            foreach ($sortParts as $part) {
                $part = trim($part);
                $direction = 'asc';
                if (str_ends_with($part, ' desc')) {
                    $direction = 'desc';
                    $part = trim(str_replace(' desc', '', $part));
                }

                // Map provider field names to Elasticsearch field names
                switch ($part) {
                    case 'hawaiianText':
                        // This requires the .keyword field which is missing.
                        // For now, we cannot sort by text.
                        // $sortOptions[] = ['sentences.text.keyword' => ['order' => $direction, 'nested' => ['path' => 'sentences']]];
                        break;
                    case 'sourcename':
                        $sortOptions['sourcename'] = $direction;
                        break;
                    case 'date':
                        $sortOptions['date'] = $direction;
                        break;
                }
            }
        }

        $rawResults = $this->client->search($term, $mode, $limit, $offset, null, $sortOptions);
        $this->print( "getSentences: using mode='$mode', got " . count($rawResults ?: []) . " results" );

        $formattedResults = [];
        if ($rawResults && is_array($rawResults)) {
            foreach ($rawResults as $hit) {
                $formattedResults[] = [
                    "sentenceid" => $hit["_id"], // This needs to be unique, will be fixed in ElasticsearchClient
                    "sourcename" => $hit["sourcename"] ?? 'unknown',
                    "authors" => is_array($hit["authors"]) ? implode(', ', $hit["authors"]) : ($hit["authors"] ?? ""),
                    "sourceid" => $hit["sourceid"] ?? $hit["_id"] ?? "",
                    "date" => $hit["date"] ?? "",
                    "hawaiiantext" => trim($hit["text"] ?? ''),
                    "link" => ""
                ];
            }
        }

        $this->print( "Formatted sentence results: " . count($formattedResults) . " individual sentences" );
        return $formattedResults;
    }

    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $this->print( "getMatchingSentenceCount($term,$pattern,$pageNumber)" );
        return  $this->client->getMatchingSentenceCount( $term, $pattern );
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
        $this->print( "getCorpusStats" );
        try {
            $params = [
                'index' => $this->index,
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'total_docs' => ['value_count' => ['field' => 'doc_id']],
                        'avg_hawaiian_ratio' => ['avg' => ['field' => 'hawaiian_word_ratio']],
                        'date_range' => ['stats' => ['field' => 'date']]
                    ]
                ]
            ];

            $response = $this->client->search($params);
            $aggs = $response['aggregations'] ?? [];
            
            return [
                'total_documents' => $aggs['total_docs']['value'] ?? 0,
                'average_hawaiian_ratio' => $aggs['avg_hawaiian_ratio']['value'] ?? 0,
                'date_range' => $aggs['date_range'] ?? []
            ];
        } catch (\Exception $e) {
            error_log("Failed to get corpus stats: " . $e->getMessage());
            return [];
        }
    }

    public function getTotalSourceGroupCounts(): array
    {
        try {
            $params = [
                'index' => $this->index,
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'by_group' => [
                            'terms' => [
                                'field' => 'groupname',
                                'size' => 100
                            ]
                        ]
                    ]
                ]
            ];

            $response = $this->client->search($params);
            $buckets = $response['aggregations']['by_group']['buckets'] ?? [];
            
            $counts = [];
            foreach ($buckets as $bucket) {
                $counts[$bucket['key']] = $bucket['doc_count'];
            }
            
            return $counts;
        } catch (\Exception $e) {
            error_log("Failed to get source group counts: " . $e->getMessage());
            return [];
        }
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
            'matchsentence' => 'Match words anywhere in sentences', 
            'phrasesentence' => 'Match exact phrase in sentences',
            'termsentence' => 'Match one word',
            'hybridsentence' => 'Hybrid keyword + semantic search on sentences',
        ];
    }

    public function getLatestSourceDates(): array
    {
        return $this->client->getLatestSourceDates();
    }
}

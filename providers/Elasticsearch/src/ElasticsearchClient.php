<?php

namespace HawaiianSearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Dotenv\Dotenv;

require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/ElasticsearchScrollIterator.php';
require_once __DIR__ . '/SourceIterator.php';

use HawaiianSearch\ElasticsearchScrollIterator;
use HawaiianSearch\SourceIterator;
use HawaiianSearch\QueryBuilder;

class ElasticsearchClient {
    protected $client;
    protected string $indexName;
    protected string $sourceMetadataIndexName;
    public float $similarity_threshold = 0.80;
    protected bool $verbose;
    protected bool $quiet;
    protected bool $splitIndices;
    protected bool $vectorDimensionsValidated = false;
    protected EmbeddingClient $embeddingClient;
    protected QueryBuilder $queryBuilder;
    protected \Noiiolelo\GrammarScanner $grammarScanner;
    private $standardIncludes =  ['sourcename', 'groupname', 'authors', 'date', 'text', 'title', 'link'];
    private ?\Exception $lastError = null;

    protected function getArrayResponse($response) {
        if (is_array($response)) {
            return $response;
        }
        if (method_exists($response, 'asArray')) {
            return $response->asArray();
        }
        return (array)$response;
    }

    // Model configuration mapping (like Python MODEL_CONFIG)
    private const MODEL_CONFIG = [
        'all-MiniLM-L6-v2' => [
            'dims' => 384,
            'query_prefix' => '',
            'passage_prefix' => ''
        ],
        'BERT-base' => [
            'dims' => 768,
            'query_prefix' => '',
            'passage_prefix' => ''
        ],
        'intfloat/multilingual-e5-small' => [
            'dims' => 384,
            'query_prefix' => 'query: ',
            'passage_prefix' => 'passage: '
        ],
        'OpenAI Ada v2' => [
            'dims' => 1536,
            'query_prefix' => '',
            'passage_prefix' => ''
        ]
    ];
    
    // Expected model (should match Python TRANSFORMER_MODEL)
    private const EXPECTED_MODEL = 'intfloat/multilingual-e5-small';

    public function __construct(array $options = []) {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
        $DEFAULT_INDEX = 'hawaiian'; // This is just the base of the index names

        $this->indexName =
            (isset($options['indexName']) && !empty($options['indexName'])) ? $options['indexName'] : $DEFAULT_INDEX;

        $this->verbose = $options['verbose'] ?? false;
        $this->quiet = $options['quiet'] ?? false;
        $this->splitIndices = $options['SPLIT_INDICES'] ?? true; // Always use split indices by default
        $this->printVerbose( "indexName: {$this->indexName}, " .
                      "documentIndexName: {$this->getDocumentsIndexName()}, " .
                      "sentencesIndexName: {$this->getSentencesIndexName()}, " .
                      "metadataIndexName: {$this->getMetadataName()}, " .
                      "sourcemetadataIndexName: {$this->getSourceMetadataName()}" );
        $apiKey = $options['apiKey'] ?? $_ENV['API_KEY'] ?? getenv('API_KEY') ?? null;

        if (!$apiKey) {
            throw new RuntimeException('API_KEY environment variable not set.');
        }

        $host = $_ENV['ES_HOST'] ?? 'localhost';
        $port = $_ENV['ES_PORT'] ?? 9200;

        $this->client = ClientBuilder::create()
            ->setHosts(["https://{$host}:{$port}"])
            ->setApiKey(trim($apiKey)) // Pass the key directly, trimming whitespace
            ->setSSLVerification(false)
            ->setHttpClientOptions([
                'timeout' => 300,
                'connect_timeout' => 30,
                'http_errors' => false
            ])
            ->build();
            
        $this->embeddingClient = new EmbeddingClient();
        $this->queryBuilder = new QueryBuilder($this->embeddingClient);
        $this->grammarScanner = new \Noiiolelo\GrammarScanner();

        // Validate embedding service on startup (skip if SKIP_EMBEDDING_VALIDATION is set)
        if (!getenv('SKIP_EMBEDDING_VALIDATION') && !($_ENV['SKIP_EMBEDDING_VALIDATION'] ?? false)) {
            $this->validateEmbeddingService();
        }

        // Skip filter checks if SKIP_FILTER_CHECKS is set (for read-only access)
        if (!getenv('SKIP_FILTER_CHECKS') && !($_ENV['SKIP_FILTER_CHECKS'] ?? false)) {
            $this->checkFilters();
        }
        $this->queryVector = [];
        $this->queryTerm = '';
    }

    public function isFilterPresent( $filterName ): array
    {
        $present = [];
        foreach( [$this->getDocumentsIndexName(),
                  $this->getSentencesIndexName(),
                  $this->getSourceMetadataName() ] as $index ) {
            if( $this->indexExists( $index ) ) {
                $response = $this->client->indices()->getSettings([
                    'index' => $index
                ]);

                $settings = $response->asArray();

                // Navigate to the char_filter definition
                $charFilters = $settings[$index]['settings']['index']['analysis']['char_filter'] ?? [];

                $present[$index] = isset($charFilters[$filterName]);
            }
        }
        return $present;
    }
    
    public function createFilter( $index, $filterName, $mappings ): void
    {
        if( $this->indexExists( $index ) ) {
            $this->print( "Creating filter $filterName in $index" );
            $this->client->indices()->close(['index' => $index]);

            $this->client->indices()->putSettings([
                'index' => $index,
                'body' => [
                    'analysis' => [
                        'char_filter' => [
                            'remove_okina' => [
                                'type' => 'mapping',
                                'mappings' => $mappings
                            ]
                        ]
                    ]
                ]
            ]);

            $this->client->indices()->open(['index' => $index]);
        }
    }
    
    public function checkFilters(): void
    {
        $filterName = 'remove_okina';
        $present = $this->isFilterPresent( $filterName );
        foreach( $present as $index => $inPlace ) {
            $this->printVerbose( "Filter $filterName is " . (($inPlace) ? "" : "not ") . "in place in $index" );
            if( !$inPlace ) {
                $this->createFilter( $index, $filterName,  ['ʻ=>'] );
            }
        }
    }
    
    public function getMetadataName( $indexName = null ) {
        $index = $indexName ?? $this->getIndexName();
        return $index . '-metadata';
    }
    
    public function getContentName( $indexName = null ) {
        $index = $indexName ?? $this->getIndexName();
        return $index . '-content';
    }
    
    public function getDocumentText(string $id, string $indexName = null): ?string
    {
        $index = $indexName ?? $this->getDocumentsIndexName();
        try {
            $response = $this->client->get([
                'index' => $index,
                'id'    => $id,
                '_source_includes' => $this->standardIncludes,
                '_source_excludes' => ['sentences', 'text_vector', 'text_chunks']
            ])->asArray();

            return $response['_source']['text'];
        } catch (ClientResponseException $e) {
            return null;
        }
    }
    
    public function getDocumentRaw(string $id, string $indexName = null): ?string
    {
        $index = $indexName ?? $this->getIndexName();
        $index = $this->getContentName( $index );
        try {
            $response = $this->client->get([
                'index' => $index,
                'id'    => $id,
            ])->asArray();

            return $response['_source']['html'] ?? null;
        } catch (ClientResponseException $e) {
            // Return null if document not found
            return null;
        }
    }
    
    public function  getSourceMetadataName( $indexName = null ) {
        $index = $indexName ?? $this->getIndexName();
        return $index . '-source-metadata';
    }
    
    public function getSearchStatsName( $indexName = null ) {
        $index = $indexName ?? $this->getIndexName();
        return $index . '-searchstats';
    }
    
    protected function print( $msg ) {
        if( !$this->quiet ) {
            echo( "ElasticSearchClient: $msg\n" );
        }
    }
    
    protected function debuglog( $msg ) {
        error_log( "ElasticSearchClient: $msg\n" );
    }
    
    protected function printVerbose( $msg ) {
        if ($this->verbose) {
            $this->print( $msg );
            error_log( $msg );
        }
    }
    
    /**
     * Get expected vector dimensions from current model configuration
     */
    private function getExpectedVectorDimensions(): int {
        return self::MODEL_CONFIG[self::EXPECTED_MODEL]['dims'];
    }

    /**
     * Check if embedding service is available for operations
     */
    private function ensureEmbeddingServiceAvailable(): void {
        try {
            // Quick test to see if service responds
            $testResult = $this->embeddingClient->embedText("test", "query: ");
            if (!$testResult) {
                throw new \RuntimeException("Embedding service is not responsive");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Embedding service unavailable: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate embedding service on startup
     */
    private function validateEmbeddingService(): void {
        try {
            // Test embedding service by making an embedding request
            $testEmbedding = $this->embeddingClient->embedText("test text", "passage: ");
            if (!$testEmbedding || !is_array($testEmbedding)) {
                throw new \RuntimeException("Embedding service did not return valid embedding data");
            }
            
            $expectedDims = self::MODEL_CONFIG[self::EXPECTED_MODEL]['dims'];
            $actualDims = count($testEmbedding);
            
            if ($actualDims !== $expectedDims) {
                throw new \RuntimeException("Vector dimension mismatch. Expected: {$expectedDims}, Got: {$actualDims}");
            }
            
            $this->printVerbose( "Embedding service validated: dimensions={$actualDims}" );
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Embedding service validation failed: " . $e->getMessage(), 0, $e);
        }
    }

    protected function getQueryVector() {
        return $this->queryVector;
    }
    
    protected function getQueryTerm() {
        return $this->queryTerm;
    }
    
    /**
     * Expose the raw client for advanced queries (e.g., aggregations)
     */
    public function getRawClient()
    {
        return $this->client;
    }

    public function getEmbeddingClient(): EmbeddingClient {
        return $this->embeddingClient;
    }

    /**
     * Load shared configuration from JSON file
     */
    protected function loadConfig(string $configFile): array {
        $configPath = __DIR__ . '/../config/' . $configFile;
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: $configPath");
        }
        $content = file_get_contents($configPath);
        $config = json_decode($content, true);
        if ($config === null) {
            throw new \RuntimeException("Invalid JSON in configuration file: $configPath");
        }
        return $config;
    }

    /**
     * Create index using shared configuration
     */
    public function createIndex(bool $recreate = false,
                                string $indexType = 'all',
                                string $customIndexName = '',
                                string $customMappingFile = ''): void
    {
        switch ($indexType) {
            case 'documents':
                $this->createDocumentsIndex($recreate, $customIndexName, $customMappingFile);
                break;
            case 'sentences':
                $this->createSentencesIndex($recreate, $customIndexName, $customMappingFile);
                break;
            case 'source-metadata':
                $this->createSourceMetadataIndex($recreate, $customIndexName ?: $this->indexName);
                break;
            case 'all':
                $this->createDocumentsIndex($recreate);
                $this->createSentencesIndex($recreate);
                $this->createSourceMetadataIndex($recreate, $this->indexName);
                break;
            default:
                // Backward compatibility: create the original combined index
                $this->createLegacyIndex($recreate, $customIndexName, $customMappingFile);
                break;
        }
    }

    protected function createDocumentsIndex(bool $recreate = false, string $customIndexName = '', string $customMappingFile = ''): void
    {
        $indexName = $customIndexName ?: $this->getDocumentsIndexName();
        $mappingFile = $customMappingFile ?: __DIR__ . '/../config/documents_mapping.json';
        
        $this->print("Creating documents index: {$indexName}");
        $this->createIndexFromMapping($indexName, $mappingFile, $recreate);
    }

    protected function createSentencesIndex(bool $recreate = false, string $customIndexName = '', string $customMappingFile = ''): void
    {
        $indexName = $customIndexName ?: $this->getSentencesIndexName();
        $mappingFile = $customMappingFile ?: __DIR__ . '/../config/sentences_mapping.json';
        
        $this->print("Creating sentences index: {$indexName}");
        $this->createIndexFromMapping($indexName, $mappingFile, $recreate);
    }

    protected function createLegacyIndex(bool $recreate = false, string $customIndexName = '', string $customMappingFile = ''): void
    {
        // Original createIndex logic for backward compatibility
        $indexName = $customIndexName ?: $this->indexName;
        $mappingFile = $customMappingFile ?: __DIR__ . '/../config/index_mapping.json';
        
        $this->print("Creating legacy combined index: {$indexName}");
        $this->createIndexFromMapping($indexName, $mappingFile, $recreate);
    }

    protected function createIndexFromMapping(string $indexName, string $mappingFile, bool $recreate = false): void
    {
        if (empty($indexName)) {
            $this->print("createIndex: empty index name");
            return;
        }

        if ($recreate && $this->indexExists($indexName)) {
            $this->print("Deleting existing index: {$indexName}");
            $this->deleteIndex($indexName);
            if ($indexName === $this->indexName) {
                $metadataIndexName = $this->getMetadataName($indexName);
                if ($this->indexExists($metadataIndexName)) {
                    $this->deleteIndex($metadataIndexName);
                }
            }
        }

        if ($this->indexExists($indexName)) {
            $this->print("Index {$indexName} already exists");
            // Should check for metadata index as well if a single-index setup
            return;
        }

        if (!file_exists($mappingFile)) {
            throw new \RuntimeException("Mapping file not found: {$mappingFile}");
        }

        $mappingJson = file_get_contents($mappingFile);
        $mapping = json_decode($mappingJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in mapping file: {$mappingFile}");
        }

        try {
            $this->client->indices()->create([
                'index' => $indexName,
                'body' => $mapping
            ]);
            $this->print("Created index: {$indexName}");
            
            // Also create metadata index if this is a legacy index creation
            if ($indexName === $this->indexName) {
                $metadataIndexName = $this->getMetadataName($indexName);
                if (!$this->indexExists($metadataIndexName)) {
                    $this->createMetadataIndex($indexName);
                }
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create index {$indexName}: " . $e->getMessage());
        }
    }

    /**
     * Create metadata index using shared configuration
     */
    protected function createMetaIndex( $filename, $indexname ): bool {
        $mappingConfig = $this->loadConfig($filename);
        $params = [
            'index' => $indexname,
            'pipeline' => 'add_created_at',
            'body' => $mappingConfig
        ];
        $this->client->indices()->create($params);
        $this->print( "Index '{$indexname}' created using shared configuration." );
        return true;
    }

    /**
     * Create metadata index using shared configuration
     */
    public function createMetadataIndex( $indexName = "" ): bool {
        $indexName = $indexName ?? $this->getIndexName();
        $metadataIndexName = $this->getMetadataName( $indexName );
        return $this->createMetaIndex( 'metadata_mapping.json', $metadataIndexName );
    }

    public function createMainIndex( $indexName = "" ): bool {
        $indexName = $indexName ?? $this->getIndexName();
        return $this->createMetaIndex( 'index_mapping.json', $indexName );
    }

    public function createSourceMetadataIndex( $recreate = false, $indexName = "" ): bool {
        $status = false;
        if (empty($indexName)) {
            $indexName = $this->getIndexName();
        }
        $sourceMetadataIndexName = $this->getSourceMetadataName( $indexName );
        if ($recreate && $this->indexExists($sourceMetadataIndexName)) {
            $this->print("Deleting existing index: {$sourceMetadataIndexName}");
            $this->deleteIndex($sourceMetadataIndexName);
        }
        if( !$this->indexExists($sourceMetadataIndexName) ) {
            $status = $this->createMetaIndex( 'source_metadata_mapping.json', $sourceMetadataIndexName );
        }
        return $status;
    }

    public function createContentIndex( $indexName = "" ): bool {
        if (empty($indexName)) {
            $indexName = $this->getIndexName();
        }
        $contentIndexName = $this->getContentName( $indexName );
        $status = $this->createMetaIndex( 'content_mapping.json', $contentIndexName );
        return $status;
    }

    public function indexRaw( $sourceid, $text ) {
        $contentName = $this->getContentName();
        $this->index([
            'html' => $text,
            'sourceid' => $sourceid,
        ], $sourceid, $contentName );
    }
    
    /**
     * Create search stats index using shared configuration
     */
    public function createSearchStatsIndex( $recreate = false, $indexName = "" ): bool {
        $status = false;
        if (empty($indexName)) {
            $indexName = $this->getIndexName();
        }
        $searchStatsIndexName = $this->getSearchStatsName( $indexName );
        if ($recreate && $this->indexExists($searchStatsIndexName)) {
            $this->print("Deleting existing index: {$searchStatsIndexName}");
            $this->deleteIndex($searchStatsIndexName);
        }
        if( !$this->indexExists($searchStatsIndexName) ) {
            $status = $this->createMetaIndex( 'searchstats_mapping.json', $searchStatsIndexName );
        }
        return $status;
    }
    
    /**
     * Add a search stat entry to the search stats index
     */
    public function addSearchStat( string $searchterm, string $pattern, int $results, string $order, float $elapsed ): bool {
        $searchStatsIndexName = $this->getSearchStatsName();
        
        // Create index if it doesn't exist
        if (!$this->indexExists($searchStatsIndexName)) {
            $this->createSearchStatsIndex();
        }
        
        $document = [
            'searchterm' => $searchterm,
            'pattern' => $pattern,
            'results' => $results,
            'sort' => $order,
            'elapsed' => $elapsed,
            'created' => date('c') // ISO 8601 format
        ];
        
        try {
            $this->client->index([
                'index' => $searchStatsIndexName,
                'body' => $document
            ]);
            return true;
        } catch (\Exception $e) {
            $this->debuglog("Failed to add search stat: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all search stats ordered by creation date
     */
    public function getSearchStats(): array {
        $searchStatsIndexName = $this->getSearchStatsName();
        
        if (!$this->indexExists($searchStatsIndexName)) {
            return [];
        }
        
        try {
            $response = $this->client->search([
                'index' => $searchStatsIndexName,
                'body' => [
                    'query' => ['match_all' => (object)[]],
                    'sort' => ['created' => 'asc'],
                    'size' => 10000
                ]
            ])->asArray();
            
            $results = [];
            foreach ($response['hits']['hits'] as $hit) {
                $results[] = $hit['_source'];
            }
            return $results;
        } catch (\Exception $e) {
            $this->debuglog("Failed to get search stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get summary of search stats grouped by pattern
     */
    public function getSummarySearchStats(): array {
        $searchStatsIndexName = $this->getSearchStatsName();
        
        if (!$this->indexExists($searchStatsIndexName)) {
            return [];
        }
        
        try {
            $response = $this->client->search([
                'index' => $searchStatsIndexName,
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'patterns' => [
                            'terms' => [
                                'field' => 'pattern',
                                'size' => 100,
                                'order' => ['_key' => 'asc']
                            ]
                        ]
                    ]
                ]
            ])->asArray();
            
            $results = [];
            if (isset($response['aggregations']['patterns']['buckets'])) {
                foreach ($response['aggregations']['patterns']['buckets'] as $bucket) {
                    $results[] = [
                        'pattern' => $bucket['key'],
                        'count' => $bucket['doc_count']
                    ];
                }
            }
            return $results;
        } catch (\Exception $e) {
            $this->debuglog("Failed to get summary search stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get the timestamp of the first search
     */
    public function getFirstSearchTime(): string {
        $searchStatsIndexName = $this->getSearchStatsName();
        
        if (!$this->indexExists($searchStatsIndexName)) {
            return '';
        }
        
        try {
            $response = $this->client->search([
                'index' => $searchStatsIndexName,
                'body' => [
                    'size' => 1,
                    'sort' => ['created' => 'asc'],
                    '_source' => ['created']
                ]
            ])->asArray();
            
            if (isset($response['hits']['hits'][0]['_source']['created'])) {
                return $response['hits']['hits'][0]['_source']['created'];
            }
            return '';
        } catch (\Exception $e) {
            $this->debuglog("Failed to get first search time: " . $e->getMessage());
            return '';
        }
    }
    
    public function cosineSimilarity(array $a, array $b): float {
        $dot = 0;
        $normA = 0;
        $normB = 0;
        foreach ($a as $i => $val) {
            $dot += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Build query from shared template
     */
    public function buildQueryFromTemplate(string $templateName, array $variables = []): array {
        $templates = $this->loadConfig('query_templates.json');
        
        if (!isset($templates[$templateName])) {
            throw new \RuntimeException("Query template not found: $templateName");
        }
        
        $template = $templates[$templateName];
        $queryJson = json_encode($template);
        
        // Replace template variables
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            if (is_array($value)) {
                $queryJson = str_replace('"' . $placeholder . '"', json_encode($value), $queryJson);
            } else {
                $queryJson = str_replace($placeholder, $value, $queryJson);
            }
        }
        
        return json_decode($queryJson, true);
    }

    /**
     * Delete an index
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function deleteIndex(string $indexName): void {
        $params = ['index' => $indexName];
        $this->client->indices()->delete($params);
        $this->print( "Index '{$indexName}' deleted." );
    }

    /**
     * Check if index exists
     */
    public function indexExists(string $indexName): bool {
        return $this->client->indices()->exists(['index' => $indexName])->asBool();
    }

    /**
     * Delete all documents with a specific groupname from documents, sentences, and metadata indices
     * @param string $groupname The groupname to filter by
     * @return array Statistics about the deletion (documents, sentences, metadata counts)
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function deleteByGroupname(string $groupname): array {
        $stats = [
            'documents' => 0,
            'sentences' => 0,
            'metadata' => 0
        ];

        // Delete from documents index
        $documentsIndex = $this->getDocumentsIndexName();
        if ($this->indexExists($documentsIndex)) {
            $response = $this->client->deleteByQuery([
                'index' => $documentsIndex,
                'body' => [
                    'query' => [
                        'term' => [
                            'groupname.keyword' => $groupname
                        ]
                    ]
                ],
                'refresh' => true  // Force immediate refresh
            ]);
            $stats['documents'] = $response['deleted'] ?? 0;
            $this->print("Deleted {$stats['documents']} documents with groupname '{$groupname}' from {$documentsIndex}");
        }

        // Delete from sentences index
        $sentencesIndex = $this->getSentencesIndexName();
        if ($this->indexExists($sentencesIndex)) {
            $response = $this->client->deleteByQuery([
                'index' => $sentencesIndex,
                'body' => [
                    'query' => [
                        'term' => [
                            'groupname.keyword' => $groupname
                        ]
                    ]
                ],
                'refresh' => true  // Force immediate refresh
            ]);
            $stats['sentences'] = $response['deleted'] ?? 0;
            $this->print("Deleted {$stats['sentences']} sentences with groupname '{$groupname}' from {$sentencesIndex}");
        }

        // Delete from metadata index
        $metadataIndex = $this->getMetadataName();
        if ($this->indexExists($metadataIndex)) {
            $response = $this->client->deleteByQuery([
                'index' => $metadataIndex,
                'body' => [
                    'query' => [
                        'term' => [
                            'groupname.keyword' => $groupname
                        ]
                    ]
                ],
                'refresh' => true  // Force immediate refresh
            ]);
            $stats['metadata'] = $response['deleted'] ?? 0;
            $this->print("Deleted {$stats['metadata']} metadata records with groupname '{$groupname}' from {$metadataIndex}");
        }

        return $stats;
    }

    /**
     * Update metadata in bulk
     */
    public function updateMetadata(array $metadataBuffer): void {
        if (empty($metadataBuffer)) {
            return;
        }
        
        $metadataIndexName = $this->getMetadataName();
        $params = ['body' => []];
        foreach ($metadataBuffer as $hash => $metadata) {
            $params['body'][] = [
                'index' => [
                    '_index' => $metadataIndexName,
                    '_id' => $hash
                ]
            ];
            $params['body'][] = $metadata;
        }

        $this->client->bulk($params);
    }

    /**
     * Index a single document with text, sentences, and Hawaiian word ratio
     * @throws MissingParameterException
     * @throws ServerResponseException
     * @throws ClientResponseException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    // This method does not appear to be in use; instead, CorpusIndexser uses bulkIndex
    // For the single-index model as well as the split index model (calling it twice in the latter case)
    // Actually it used by two of the highlighting tests
    public function indexDocument(string $docId, array $sourceData, string $text, array $sentences, float $hawaiianWordRatio): void {
        // Ensure embedding service is available
        $this->ensureEmbeddingServiceAvailable();

        // 1. Get embeddings from the Python service
        $textVector = $this->embeddingClient->embedText($text, 'passage: ');
        $sentenceVectors = $this->embeddingClient->embedSentences($sentences, 'passage: ');

        if (!$textVector || !$sentenceVectors || count($sentences) !== count($sentenceVectors)) {
            $this->print( "Could not get embeddings for doc {$docId}. Skipping." );
            return;
        }

        // 2. Prepare sentence objects
        $sentenceObjects = [];
        foreach ($sentences as $idx => $sentenceText) {
            $sentenceObjects[] = [
                'text' => $sentenceText,
                'vector' => $sentenceVectors[$idx],
                'position' => $idx,
                'doc_id' => $docId
            ];
        }

        // 3. Prepare the main document for bulk indexing
        $params = ['body' => []];
        $params['body'][] = [
            'index' => [
                '_index' => $this->indexName,
                '_id' => $docId
            ]
        ];
        $params['body'][] = [
            'doc_id' => $docId,
            'groupname' => $sourceData['groupname'] ?? 'N/A',
            'sourcename' => $sourceData['sourcename'] ?? 'N/A',
            'text' => $text,
            'text_vector' => $textVector,
            
            'sentences' => $sentenceObjects,
            'date' => !empty($sourceData['date']) ? $sourceData['date'] : null,
            'authors' => $sourceData['authors'] ?? '',
            'hawaiian_word_ratio' => $hawaiianWordRatio
        ];

        // 4. Use the bulk helper to index the document
        $this->client->bulk($params);
    }

    /**
     * Bulk index multiple actions
     */

    /**
     * Index document and sentences to separate indices
     */
    public function indexDocumentAndSentences(string $docId, array $sourceData, string $text, array $sentences, float $hawaiianWordRatio): void 
    {
        // Process sentences and get sentence objects with vectors and metadata
        $processedSentences = $this->processSentencesForIndexing($docId, $sentences, $sourceData);
        
        // Index to documents index
        $this->indexDocumentToIndex($docId, $sourceData, $text, $hawaiianWordRatio, count($sentences));
        
        // Index sentences to sentences index  
        $this->indexSentencesToIndex($docId, $processedSentences);
    }

    /**
     * Index document to documents index
     */
    public function indexDocumentToIndex(string $docId, array $sourceData, string $text, float $hawaiianWordRatio, int $sentenceCount): void
    {
        // Ensure embedding service is available
        $this->ensureEmbeddingServiceAvailable();

        // Get document-level vector
        $textVector = $this->embeddingClient->embedText($text, 'passage: ');
        if (!$textVector) {
            $this->print("Could not get document embedding for doc {$docId}. Skipping document index.");
            return;
        }

        // Prepare document for indexing
        // Sanitize date field to prevent empty string errors
        $sanitizedSourceData = $sourceData;
        if (!isset($sanitizedSourceData['date']) || $sanitizedSourceData['date'] === '' || $sanitizedSourceData['date'] === null) {
            $sanitizedSourceData['date'] = null;
        }
        $documentData = array_merge($sanitizedSourceData, [
            'doc_id' => $docId,
            'text' => $text,
            'text_vector' => $textVector,
            'hawaiian_word_ratio' => $hawaiianWordRatio,
            'sentence_count' => $sentenceCount
        ]);

        // Add text chunks if text is long
        if (strlen($text) > 32000) {
            $documentData['text_chunks'] = $this->createTextChunks($text);
        }

        try {
            $this->client->index([
                'index' => $this->getDocumentsIndexName(),
                'id' => $docId,
                'body' => $documentData
            ]);
            $this->print("Indexed document {$docId} to documents index");
        } catch (\Exception $e) {
            $this->print("Failed to index document {$docId}: " . $e->getMessage());
        }
    }

    /**
     * Index sentences to sentences index
     * @throws \Exception if indexing fails
     */
    private function indexSentencesToIndex(string $docId, array $processedSentences): void
    {
        if (empty($processedSentences)) {
            return;
        }

        // Batch sentences to avoid request size limits (413 errors)
        // Each sentence with vector is ~2KB, so 500 sentences = ~1MB
        $batchSize = 500;
        $totalSentences = count($processedSentences);
        $totalIndexed = 0;
        $totalErrors = 0;
        $failedBatches = [];
        
        for ($i = 0; $i < $totalSentences; $i += $batchSize) {
            $batch = array_slice($processedSentences, $i, $batchSize);
            $actions = [];
            
            foreach ($batch as $sentence) {
                $sentenceId = $docId . '_sentence_' . $sentence['position'];
                
                $actions[] = [
                    'index' => [
                        '_index' => $this->getSentencesIndexName(),
                        '_id' => $sentenceId
                    ]
                ];
                $actions[] = array_merge($sentence, ['doc_id' => $docId]);
            }

            try {
                $response = $this->client->bulk(['body' => $actions]);
                
                // Check for errors in the bulk response
                if (!empty($response['errors'])) {
                    $errorCount = 0;
                    foreach ($response['items'] as $item) {
                        if (isset($item['index']['error'])) {
                            $errorCount++;
                            $error = $item['index']['error'];
                            $this->print("Bulk index error for {$item['index']['_id']}: {$error['type']} - {$error['reason']}");
                        }
                    }
                    $totalErrors += $errorCount;
                    $this->print("WARNING: Batch had errors: $errorCount out of " . count($batch) . " failed");
                } else {
                    $totalIndexed += count($batch);
                }
            } catch (\Exception $e) {
                $totalErrors += count($batch);
                $failedBatches[] = "Batch starting at position $i: " . $e->getMessage();
                $this->print("CRITICAL: Failed to bulk index batch for doc {$docId}: " . $e->getMessage());
            }
        }
        
        // If any sentences failed to index, throw an exception
        if ($totalErrors > 0) {
            $errorMessage = "Failed to index $totalErrors out of $totalSentences sentences for doc {$docId}";
            if (!empty($failedBatches)) {
                $errorMessage .= ". Failed batches: " . implode("; ", $failedBatches);
            }
            $this->print("ERROR: " . $errorMessage);
            throw new \RuntimeException($errorMessage);
        }
        
        $this->print("Indexed $totalSentences sentences for doc {$docId}");
    }

    /**
     * Process sentences to include vectors and metadata
     */
    private function processSentencesForIndexing(string $docId, array $sentences, array $sourceData = []): array
    {
        if (empty($sentences)) {
            return [];
        }

        // Get embeddings for all sentences
        $sentenceVectors = $this->embeddingClient->embedSentences($sentences, 'passage: ');
        if (!$sentenceVectors || count($sentences) !== count($sentenceVectors)) {
            $this->print("Could not get sentence embeddings for doc {$docId}. Skipping sentences.");
            return [];
        }

        $processedSentences = [];
        foreach ($sentences as $idx => $sentenceText) {
            $sentenceHash = md5($sentenceText);
            
            // Create sentence object with all required fields including source metadata
            $sentenceData = [
                'sentence_id' => $docId . '_sentence_' . $idx,
                'text' => $sentenceText,
                'position' => $idx,
                'vector' => $sentenceVectors[$idx],
                'sentence_hash' => $sentenceHash,
                'frequency' => 1, // Default frequency
                'hawaiian_word_ratio' => $this->calculateHawaiianWordRatio($sentenceText),
                'entity_count' => $this->calculateEntityCount($sentenceText),
                'boilerplate_score' => $this->calculateBoilerplateScore($sentenceText),
                'grammar_patterns' => $this->calculateGrammarPatterns($sentenceText),
                'length' => mb_strlen($sentenceText),
                'groupname' => $sourceData['groupname'] ?? '',
                'sourcename' => $sourceData['sourcename'] ?? '',
                'title' => $sourceData['title'] ?? '',
                'authors' => $sourceData['authors'] ?? '',
                'link' => $sourceData['link'] ?? '',
                'date' => !empty($sourceData['date']) ? $sourceData['date'] : null,
                'metadata' => [] // Additional metadata can be added here
            ];
            
            $processedSentences[] = $sentenceData;
        }

        return $processedSentences;
    }

    /**
     * Create text chunks for long documents
     */
    private function createTextChunks(string $text, int $chunkSize = 30000): array
    {
        $chunks = [];
        $textLength = strlen($text);
        $chunkIndex = 0;
        
        for ($start = 0; $start < $textLength; $start += $chunkSize) {
            $chunkText = substr($text, $start, $chunkSize);
            $chunks[] = [
                'chunk_index' => $chunkIndex++,
                'chunk_text' => $chunkText,
                'chunk_start' => $start,
                'chunk_length' => strlen($chunkText)
            ];
        }
        
        return $chunks;
    }

    /**
     * Calculate Hawaiian word ratio for text
     */
    private function calculateHawaiianWordRatio(string $text): float
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return 0.0;
        }
        
        $hawaiianCount = 0;
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (empty($cleanWord)) {
                continue;
            }
            
            // Check for Hawaiian indicators
            if (preg_match('/[ʻāēīōūĀĒĪŌŪ]/', $cleanWord)) {
                $hawaiianCount++;
            } elseif (preg_match('/^[aeiouAEIOUhklmnpwHKLMNPW]+$/', $cleanWord)) {
                $hawaiianCount++;
            }
        }
        
        return $hawaiianCount / count($words);
    }

    /**
     * Calculate entity count for a text
     */
    private function calculateEntityCount(string $text): int
    {
        // Simple entity count based on capitalized words
        preg_match_all('/\b[A-Z][a-z]+\b/', $text, $matches);
        return count($matches[0]);
    }

    /**
     * Calculate boilerplate score for a text  
     */
    private function calculateBoilerplateScore(string $text): float
    {
        $length = strlen($text);
        if ($length == 0) return 1.0;
        
        // Simple heuristic: ratio of repeated common words
        $commonWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
        $totalWords = str_word_count($text);
        $commonWordCount = 0;
        
        foreach ($commonWords as $word) {
            $commonWordCount += substr_count(strtolower($text), ' ' . $word . ' ');
        }
        
        return $totalWords > 0 ? min(1.0, $commonWordCount / $totalWords) : 0.0;
    }

    /**
     * Calculate grammar patterns for a text
     */
    public function calculateGrammarPatterns(string $text): array
    {
        return $this->grammarScanner->scanSentence($text);
    }

    private function validateVectorDimensions( $actions ) {
        // Validate vector dimensions for first document with vectors
        if( $this->vectorDimensionsValidated ) {
            return true;
        }
        foreach ($actions as $action) {
            if (isset($action['_source']['text_vector']) || 
                (isset($action['_source']['sentences']) && 
                 isset($action['_source']['sentences'][0]['vector']))
            ) {
                
                $expectedDims = $this->getExpectedVectorDimensions();
                
                if (isset($action['_source']['text_vector'])) {
                    if (!is_array($action['_source']['text_vector'])) {
                        throw new \RuntimeException("Text vector must be an array, got: " . gettype($action['_source']['text_vector']));
                    }
                    $textVectorDims = count($action['_source']['text_vector']);
                    if ($textVectorDims !== $expectedDims) {
                        throw new 
                        \RuntimeException("Text vector dimension mismatch! Expected: {$expectedDims}, Got: {$textVectorDims}");
                    }
                }
                
                if (isset($action['_source']['sentences'][0]['vector'])) {
                    if (!is_array($action['_source']['sentences'][0]['vector'])) {
                        throw new \RuntimeException("Sentence vector must be an array, got: " . gettype($action['_source']['sentences'][0]['vector']));
                    }
                    $sentenceVectorDims = count($action['_source']['sentences'][0]['vector']);
                    if ($sentenceVectorDims !== $expectedDims) {
                        throw new 
                        \RuntimeException("Sentence vector dimension mismatch! Expected: {$expectedDims}, Got: {$sentenceVectorDims}");
                    }
                }
                $this->vectorDimensionsValidated = true;
                $this->print( "✅ Vector dimensions validated: text and sentence vectors match expected {$expectedDims} dimensions" );
                break; // Only check first document with vectors
            }
        }
        return  $this->vectorDimensionsValidated;
    }

    // This is for adding documents and sentences to a single index
    public function bulkIndex(array $actions): void {
        $this->print( "ElasticsearchIndex::bulkIndex: " . sizeof($actions) . " actions" );
        if (empty($actions)) {
            return;
        }

        $validDimensions = $this->validateVectorDimensions( $actions );
        
        $params = ['body' => []];
        foreach ($actions as $action) {
            $params['body'][] = [
                'index' => [
                    '_index' => $action['_index'],
                    '_id' => $action['_id']
                ]
            ];
            $params['body'][] = $action['_source'];
        }
        try {
            $this->print( "🔍 Debug: Attempting bulk index with " . count($actions) . " documents, estimated size: " . number_format(strlen(json_encode($params))) . " bytes" );
            $response = $this->client->bulk($params);
            
            // Check the bulk response for errors
            $responseData = $response->asArray();
            if (isset($responseData['errors']) && $responseData['errors']) {
                $this->print( "⚠️  Bulk response contains errors:" );
                $this->print( "First action: " . json_encode( $params['body'][0]) .
                              "\n" . json_encode( $params['body'][1], JSON_PRETTY_PRINT) );
                foreach ($responseData['items'] as $item) {
                    if (isset($item['index']['error'])) {
                        $error = $item['index']['error'];
                        $this->print( "   - Document ID " . $item['index']['_id'] . ": " . $error['type'] . " - " . $error['reason'] );
                    }
                }
            } else {
                $this->print( "✅ Bulk index completed successfully with no errors" );
                if( $this->verbose ) {
                    if (isset($responseData['items'])) {
                        foreach ($responseData['items'] as $item) {
                            if (isset($item['index']['_id'])) {
                                $this->print( "   - Indexed document ID: " . $item['index']['_id'] . " (status: " . $item['index']['status'] . ")" );
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->print( "❌ Bulk index failed: " . $e->getMessage() );
            $this->print( "📊 Request size: " . number_format(strlen(json_encode($params))) . " bytes" );
            throw $e;
        }
    }

    /**
     * Save source metadata for checkpointing
     */
    public function saveSourceMetadata(array $meta): void {
        $metadataIndexName = $this->getSourceMetadataName();
        //print_r( $meta );
        $bulkBody = [];
        foreach ($meta as $record) {
            $data = $record['_source'] ?? $record;
            $bulkBody[] = [
                'index' => [
                    '_index' => $metadataIndexName,
                    '_id' => $data['sourceid']
                ]
            ];
            //echo( var_export( $meta, true ) . "\n" );
            $bulkBody[] = $data;
            //if( isset($data['discarded']) && $data['discarded'] ) {
            //    echo "Discarded {$data['sourceid']}\n";
            //}
        }

        // Send the bulk request
        $response = $this->client->bulk([
            'body' => $bulkBody
        ]);

        // Optional: check for errors
        if (isset($response['errors']) && $response['errors'] === true) {
            foreach ($response['items'] as $item) {
                if (isset($item['index']['error'])) {
                    echo "Error indexing document " . $item['index']['_id'] . ": ";
                    print_r($item['index']['error']);
                }
            }
        }
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    public function getDocumentsIndexName( $indexName = '' ): string
    {
        $index = empty($indexName) ? $this->getIndexName() : $indexName;
        return $index . "_documents_new";
    }

    public function getSentencesIndexName( $indexName = '' ): string
    {
        $index = empty($indexName) ? $this->getIndexName() : $indexName;
        return $index . "_sentences_new";
    }

    public function getDocument(string $id, string $indexName = null): ?array
    {
        $index = empty($indexName) ? $this->getIndexName() : $indexName;
        try {
            $response = $this->client->get([
                'index' => $index,
                'id'    => $id
            ])->asArray();
            
            return $response['_source'];
        } catch (ClientResponseException $e) {
            return null;
        }
    }

    public function getDocumentOutline(string $id, string $indexName = ''): ?array
    {
        //$index = empty($indexName) ? $this->getIndexName() : $indexName;
        $index = $this->getDocumentsIndexName( $indexName );
        try {
            $response = $this->client->get([
                'index' => $index,
                'id'    => $id,
                '_source' => [
                    'sourcename',
                    'groupname',
                    'authors',
                    'date',
                    'title',
                    'link',
                    'created',
                ],
            ])->asArray();
            
            $source = $response['_source'];
            $source['sourceid'] = $id;
            return $source;
        } catch (ClientResponseException $e) {
            return null;
        }
    }

    public function getSentencesBySourceID(string $sourceid, string $indexName = ''): ?array
    {
        //$index = empty($indexName) ? $this->getIndexName() : $indexName;
        $index = empty($indexName) ? $this->getSentencesIndexName() : $indexName;
        $params = [
            'index' => $index,
            'body' => [
                'size' => 10000,
                '_source' => [
                    'exclude' => ['vector'],
                ],
                'query' => [
                    'term' => [
                        'doc_id.keyword' => $sourceid
                    ]
                ],
                'sort' => [
                    'position' => 'asc'
                ]
            ]
        ];

        try {
            $response = $this->client->search($params);
            $first = $response['hits']['hits'][0]['_source'] ?? [];

            $sentences = [];
            // Loop through results
            foreach ($response['hits']['hits'] as $hit) {
                //echo var_export( $hit, true ) . "\n";
                $source = $hit['_source'];
                $sentences[] = [
                    'sentenceid' => $hit['_id'],
                    'text' => $source['text'],
                    'position' => $source['position'],
                    'quality' => $source['quality_score'] ?? 0,
                    'boilerplate' => $source['boilerplate_score'],
                ];
            }

            $results = [
                'sourceid' => $first['doc_id'] ?? $sourceid,
                'groupname' => $first['groupname'] ?? '',
                'sourcename' => $first['sourcename'] ?? '',
                'title' => $first['title'] ?? '',
                'authors' => $first['authors'] ?? '',
                'date' => $first['date'] ?? '',
                'sentences' => $sentences,
            ];
            return $results;
        } catch (ClientResponseException $e) {
            return null;
        }
    }

    public function documentExists(string $id, string $indexName = ''): bool
    {
        $index = empty($indexName) ? $this->getIndexName() : $indexName;
        try {
            $response = $this->client->exists([
                'index' => $index,
                'id'    => $id
            ]);
            return $response->asBool();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateDocumentProperties(string $docId, array $properties): void
    {
        $params = [
            'index' => $this->getDocumentsIndexName(),
            'id'    => $docId,
            'body'  => [
                'doc' => $properties
            ]
        ];

        try {
            $this->client->update($params);
        } catch (\Exception $e) {
            if ($this->verbose) {
                error_log("Failed to update properties for doc {$docId}: " . $e->getMessage());
            }
        }
    }

    public function updateManyDocumentProperties( $records ) {
        $bulkBody = [];
        foreach ($records as $data) {
            $bulkBody[] = [
                'index' => [
                    '_index' => $this->getIndexName(),
                    '_id' => $data['sourceid']
                ]
            ];
            $bulkBody[] = $data;
        }

        // Send the bulk request
        $response = $this->client->bulk([
            'body' => $bulkBody
        ]);

        // Optional: check for errors
        if (isset($response['errors']) && $response['errors'] === true) {
            foreach ($response['items'] as $item) {
                if (isset($item['index']['error'])) {
                    echo "Error indexing document " . $item['index']['_id'] . ": ";
                    print_r($item['index']['error']);
                }
            }
        }
    }

    // Note that $indexName is for the main or base index
    public function getSourceMetadata(string $indexName = null): ?array
    {
        $index = $indexName ?? $this->getIndexName();
        $index = $this->getSourceMetadataName( $index );
        $this->print( "getSourceMetadata($index)" );
        $records = $this->getAllRecords( $index );
        $this->print( "Read " . count($records) . " source metadata records" );
        return $records;
    }

    public function getCorpusStats(): array
    {
        $sentenceCount = $this->getTotalSentenceCount();
        $sourceCount = $this->getTotalDocumentCount();
        return [
            'sentence_count' => $sentenceCount,
            'source_count' => $sourceCount,
        ];
    }

    public function getLatestSourceDates( $indexName = null ): array
    {
        $index = $indexName ?? $this->getIndexName();
        $index = $this->getDocumentsIndexName( $index );
        $params = [
            'index' => $index,
            'body' => [
                'aggs' => [
                    'group_by_groupname' => [
                        'terms' => [
                            'field' => 'groupname.keyword',
                            'size' => 10000 // Adjust size as needed
                        ],
                        'aggs' => [
                            'max_date' => [
                                'max' => [
                                    'field' => 'date'
                                ]
                            ]
                        ]
                    ]
                ],
                'size' => 0 // We only need aggregations
            ]
        ];

        try {
            $response = $this->client->search($params)->asArray();
            if ($this->verbose) {
                error_log("Raw response from getLatestSourceDates: " . json_encode($response, JSON_PRETTY_PRINT));
            }
            $latestDates = [];
            $buckets = $response['aggregations']['group_by_groupname']['buckets'] ?? [];
            foreach ($buckets as $bucket) {
                if (isset($bucket['max_date']['value'])) {
                    $latestDates[] = [
                        'groupname' => $bucket['key'],
                        'date' => date('Y-m-d', $bucket['max_date']['value'] / 1000), // Convert ms to date
                    ];
                }
            }
            return $latestDates;
        } catch (\Exception $e) {
            if ($this->verbose) {
                error_log("Error in getLatestSourceDates: " . $e->getMessage());
            }
            return [];
        }
    }

    public function getTotalSourceGroupCounts( $indexName = null ): array
    {
        $index = $indexName ?? $this->getIndexName();
        $index = $this->getDocumentsIndexName( $index );
        $params = [
            'index' => $index,
            'body' => [
                'aggs' => [
                    'group_by_groupname' => [
                        'terms' => [
                            'field' => 'groupname.keyword',
                            'size' => 10000 // Adjust size as needed
                        ]
                    ]
                ],
                'size' => 0 // We only need aggregations
            ]
        ];

        try {
            $response = $this->client->search($params)->asArray();
            $groupCounts = [];
            foreach ($response['aggregations']['group_by_groupname']['buckets'] as $bucket) {
                $groupCounts[$bucket['key']] = $bucket['doc_count'];
            }
            return $groupCounts;
        } catch (Exception $e) {
            // Log the error or handle it appropriately
            return [];
        }
    }

    // Returns -1 if counts cannot be estimated for $mode
    public function getMatchingSentenceCount(string $query, string $mode, array $options = []): int
    {
        // For KNN and vector odes, an exact count is not feasible and returning even a
        // reasonable estimate is prohibitively expensive, so just return -1
        if( $this->isVectorSearchMode($mode)) {
            return -1;
        }

        if (!QueryBuilder::isSentenceLevelSearchMode($mode) && !empty(trim($query))) {
            $this->debuglog( "getMatchingSentenceCount: $mode is not a sentence level search mode" );
            $this->print( "getMatchingSentenceCount: $mode is not a sentence level search mode" );
            return -1;
        }

        $params = $this->queryBuilder->buildCountQuery($mode, $query, $this->getSentencesIndexName(), $options);

        if ($params === null) {
            $this->print( "Could not create count query for $mode, $query" );
            return -1;
        }

        $this->printVerbose(
            "--- Elasticsearch Count Query ---\n" .
            json_encode($params, JSON_PRETTY_PRINT) .
            "\n--------------------------"
        );

        try {
            $response = $this->client->count($params)->asArray();
            // Using count API, the result is in 'count' field
            return $response['count'] ?? 0;
        } catch (Exception $e) {
            if ($this->verbose) {
                error_log("Sentence count error: " . $e->getMessage());
            }
            return 0;
        }
    }

    public function getTotalSentenceCount(array $options = []): int
    {
        // Use simple GET count without body to avoid POST requests (for read-only nginx proxies)
        $index = $this->getSentencesIndexName();
        try {
            $response = $this->client->count(['index' => $index])->asArray();
            return $response['count'] ?? 0;
        } catch (\Exception $e) {
            if ($this->verbose) {
                error_log("Sentence count error: " . $e->getMessage());
            }
            return 0;
        }
    }

    public function getTotalDocumentCount( $indexName = '' ): int
    {
        $index = empty($indexName) ? $this->getDocumentsIndexName() : $indexName;
        try {
            $response = $this->client->count(['index' => $index])->asArray();
            return $response['count'] ?? 0;
        } catch (\Exception $e) {
            if ($this->verbose) {
                error_log("Document count error: " . $e->getMessage());
            }
            return 0;
        }
    }

    public function getGrammarPatterns(array $options = []): array {
        $params = [
            'index' => $this->getSentencesIndexName(),
            'body' => [
                'size' => 0,
                'aggs' => [
                    'patterns' => [
                        'terms' => [
                            'field' => 'grammar_patterns.keyword',
                            'size' => 1000
                        ]
                    ]
                ]
            ]
        ];

        // Add date filters if provided
        if (!empty($options['from']) || !empty($options['to'])) {
            $filter = ['bool' => ['must' => []]];
            if (!empty($options['from'])) {
                $filter['bool']['must'][] = ['range' => ['date' => ['gte' => $options['from'] . '-01-01']]];
            }
            if (!empty($options['to'])) {
                $filter['bool']['must'][] = ['range' => ['date' => ['lte' => $options['to'] . '-12-31']]];
            }
            $params['body']['query'] = $filter;
        }

        try {
            $response = $this->client->search($params)->asArray();
            $buckets = $response['aggregations']['patterns']['buckets'] ?? [];
            $results = [];
            foreach ($buckets as $bucket) {
                $results[] = [
                    'pattern_type' => $bucket['key'],
                    'count' => $bucket['doc_count']
                ];
            }
            return $results;
        } catch (\Exception $e) {
            $this->debuglog("Failed to get grammar patterns: " . $e->getMessage());
            return [];
        }
    }

    public function getGrammarMatches(string $pattern, int $limit = 0, int $offset = 0, array $options = []): array {
        $params = [
            'index' => $this->getSentencesIndexName(),
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['grammar_patterns.keyword' => $pattern]]
                        ]
                    ]
                ]
            ]
        ];

        if ($limit > 0) {
            $params['body']['size'] = $limit;
            $params['body']['from'] = $offset;
        }

        // Add date filters if provided
        if (!empty($options['from'])) {
            $params['body']['query']['bool']['must'][] = ['range' => ['date' => ['gte' => $options['from'] . '-01-01']]];
        }
        if (!empty($options['to'])) {
            $params['body']['query']['bool']['must'][] = ['range' => ['date' => ['lte' => $options['to'] . '-12-31']]];
        }

        // Add ordering
        $order = $options['order'] ?? 'rand';
        if ($order === 'alpha') {
            $params['body']['sort'] = [['text.keyword' => 'asc']];
        } else if ($order === 'alpha desc') {
            $params['body']['sort'] = [['text.keyword' => 'desc']];
        } else if ($order === 'date') {
            $params['body']['sort'] = [['date' => 'asc'], ['text.keyword' => 'asc']];
        } else if ($order === 'date desc') {
            $params['body']['sort'] = [['date' => 'desc'], ['text.keyword' => 'desc']];
        } else if ($order === 'source') {
            $params['body']['sort'] = [['sourcename.keyword' => 'asc'], ['text.keyword' => 'asc']];
        } else if ($order === 'source desc') {
            $params['body']['sort'] = [['sourcename.keyword' => 'desc'], ['text.keyword' => 'asc']];
        } else if ($order === 'length') {
            $params['body']['sort'] = [['length' => 'asc']];
        } else if ($order === 'length desc') {
            $params['body']['sort'] = [['length' => 'desc']];
        } else if ($order === 'rand') {
            $params['body']['query']['bool']['must'][] = [
                'function_score' => [
                    'random_score' => (object)[]
                ]
            ];
        }

        try {
            $response = $this->client->search($params)->asArray();
            $hits = $response['hits']['hits'] ?? [];
            $results = [];
            foreach ($hits as $hit) {
                $source = $hit['_source'];
                // Map ES fields to the format expected by the UI (matching SQL output)
                $results[] = [
                    'sentenceid' => $hit['_id'],
                    'hawaiiantext' => $source['text'],
                    'englishtext' => $source['englishtext'] ?? '',
                    'sourceid' => $source['doc_id'],
                    'sourcename' => $source['sourcename'],
                    'date' => $source['date'],
                    'authors' => $source['authors'],
                    'link' => $source['link'] ?? '',
                    'pattern_type' => $pattern
                ];
            }
            return $results;
        } catch (\Exception $e) {
            $this->debuglog("Failed to get grammar matches: " . $e->getMessage());
            return [];
        }
    }

    public function search(string $query, string $mode, array $options = []): ?array
    {
        $this->queryTerm = $query;
        
        // `from` is not allowed when `search_after` is used
        if (!empty($options['search_after'])) {
            $options['offset'] = 0;
        }
        // Use split index names for QueryBuilder
        $options['documentsIndex'] = $this->getDocumentsIndexName();
        $options['sentencesIndex'] = $this->getSentencesIndexName();

        // Compose an elastic search query
        $params = $this->queryBuilder->build($mode, $query, $options);

        $this->printVerbose(
            "--- Elasticsearch Query ---\n" .
            json_encode($params, JSON_PRETTY_PRINT) .
            "\n--------------------------" );

        try {
            $startTime = microtime(true);
            $response = $this->client->search($params)->asArray();
            $this->printVerbose( "Elasticsearch Response: " . json_encode($response, JSON_PRETTY_PRINT) );
            $response['query'] = $query;
            $endTime = microtime(true);
            $elapsedTime = round(($endTime - $startTime) * 1000, 2);
            
            $this->printVerbose(
                "Elasticsearch query took {$elapsedTime} ms for mode: {$mode}"
            );

            if( $this->isVectorSearchMode($mode)) {
                $this->queryVector = $this->embeddingClient->embedText($query);
            }
           // print_r( $response );

            $results = $this->formatResults($response, $mode, $options['sort'] ?? []);
            // Slicing is now handled in the query.php loop when paging
            if (empty($options['search_after'])) {
                $results = array_slice($results, 0, $options['k'] ?? 10);
            }
            return $results;
            
        } catch (Exception $e) {
            error_log("Search error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Filter out newspaper headers and metadata from sentences/text
     */
    private function isGoodContent($text) {
        if (empty($text) || strlen($text) < 20) return false;
        
        // Skip if all caps (likely headers)
        if (preg_match('/^[A-Z\s\d\.]+$/', $text)) return false;
        
        // Skip newspaper headers/metadata
        if (preg_match('/Ka Nupepa Kuokoa|BUKE|HELU|HONOLULU|POAONO|DEKEMABA|\d{4}|NA HELU A PAU/', $text)) return false;
        
        // Skip if mostly numbers/punctuation
        if (preg_match('/^[\d\s\.\/\\-,]+$/', $text)) return false;
        
        return true;
    }

    /**
     * Extract good sentences from a document, avoiding headers
     */

    /**
     * Extract good content from full text, avoiding headers
     */

private function formatResults(array $response, string $mode,
                               array $sortOptions = []): array
    {
        $this->printVerbose(
            "isSentenceLevelSearchMode: " . QueryBuilder::isSentenceLevelSearchMode($mode) . "\n" .
            "isVectorSearchMode: " . $this->isVectorSearchMode($mode)
        );
        $results = [];
        $queryVector = ($this->isVectorSearchMode($mode)) ? $this->getQueryVector() : [];
        
        // Track seen documents for document-level deduplication
        $seenDocuments = [];

        $hits = $response['hits']['hits'] ?? [];

        $i = 0;
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $score = $hit['_score'] ?? 0;

            // This section is the same for all sentences in a document
            $metadata = [
                '_id' => $hit['_id'] ?? '',
                'sourceid' => $source['sourceid'] ?? $source['doc_id'] ?? 'unknown',
                'sourcename' => $source['sourcename'] ?? 'unknown',
                'groupname' => $source['groupname'] ?? '',
                'authors' => $source['authors'] ?? [],
                'date' => $source['date'] ?? '',
                'title' => $source['title'] ?? '',
                'mode' => $mode,
                'score' => $score
            ];
            if (isset($hit['sort'])) {
                $metadata['sort_values'] = $hit['sort'];
            }
            if (!empty($sortOptions)) {
                $metadata['sort_options'] = $sortOptions;
            }
            $pattern = '/\b' . preg_quote($this->getQueryTerm(), '/') . '\b/i';

            // Sentence-level modes
            if (QueryBuilder::isSentenceLevelSearchMode($mode)) {
                if( $this->isVectorSearchMode($mode)) {
                    // Check if we have inner_hits (for hybrid modes with text search + vectors)
                    $innerHits = $hit['inner_hits']['sentences']['hits']['hits'] ?? [];
                    
                    if (!empty($innerHits)) {
                        // Process inner_hits (e.g., for hybridsentence mode)
                        foreach ($innerHits as $innerHit) {
                            $sentenceText = $innerHit['_source']['text'] ?? '';
                            $metadata['_id'] = ($hit['_id'] ?? '') . '_' . ($innerHit['_source']['position'] ?? 'X');
                            $metadata['text'] = $sentenceText;
                            // Check for sentence-level highlighting in inner_hits
                            $hasHighlighting = false;
                            $highlightedText = $sentenceText;
                            if (isset($innerHit['highlight']['sentences.text'][0])) {
                                $highlightedText = $innerHit['highlight']['sentences.text'][0];
                                $hasHighlighting = true;
                            } elseif (isset($innerHit['highlight']['sentences.text.folded'][0])) {
                                $highlightedText = $innerHit['highlight']['sentences.text.folded'][0];
                                $hasHighlighting = true;
                            }
                            $metadata['highlighted_text'] = $this->convertHighlightMarkersToHtml($highlightedText);
                            $metadata['has_highlighting'] = $hasHighlighting;
                            // For hybrid modes, we may not have vectors in inner_hits, so use similarity from the hit
                            $similarity = $hit['_score'] ?? 0; // Use document score as approximation
                            $quality = 1.0; // Default quality for hybrid results
                            $len = strlen($metadata['text']);
                            // Simplified scoring for hybrid inner_hits
                            $normalizedLength = min($len / 500, 1.0);
                            $lengthPenalty = ($len > 300) ? 0.85 : 1.0;
                            $boilerplatePhrases = ['in conclusion', 'as previously mentioned', 'it is important to note'];
                            $boilerplatePenalty = 1.0;
                            foreach ($boilerplatePhrases as $phrase) {
                                if (stripos($metadata['text'], $phrase) !== false) {
                                    $boilerplatePenalty = 0.8;
                                    break;
                                }
                            }
                            preg_match_all($pattern, $metadata['text'] ?? '', $matches);
                            $tokenCount = count($matches[0]);
                            $combinedScore = (
                                0.40 * $quality +
                                0.60 * $normalizedLength
                            ) * $lengthPenalty * $boilerplatePenalty;
                            $metadata['metrics'] = [
                                'position' => $innerHit['_source']['position'] ?? '?',
                                'cosine_score' => $similarity / 10, // Normalize document score
                                'snippet_length' => $len,
                                'quality_score' => $quality,
                                'token_matches' => $tokenCount,
                                'combined_score' => $combinedScore,
                                'document_score' => $score,
                            ];
                            $results[] = $metadata;
                        }
                    } else {
                        // Original vector processing path (for pure vector modes like knnsentence/vectorsentence)
                        $sentences = isset($source['sentences']) ? $source['sentences'] : [$source];
                        foreach ($sentences as $sentence) {
                            $metadata['_id'] = ($hit['_id'] ?? '') . '_' . ($sentence['position'] ?? 'X');
                            $metadata['text'] = $sentence['text'] ?? '';
                            // Highlight extraction for knnsentence: check for highlight['text'] in hit
                            if (isset($hit['highlight']['text'][0])) {
                                $metadata['highlighted_text'] = $this->convertHighlightMarkersToHtml($hit['highlight']['text'][0]);
                                $metadata['has_highlighting'] = true;
                            } else {
                                $metadata['highlighted_text'] = $metadata['text'];
                                $metadata['has_highlighting'] = false;
                            }
                            // For KNN queries, use the score from Elasticsearch instead of recalculating
                            // Vector is not returned in _source by default for efficiency
                            if (isset($sentence['vector']) && is_array($sentence['vector']) && !empty($queryVector)) {
                                $similarity = $this->cosineSimilarity($queryVector, $sentence['vector']);
                            } else {
                                // Use ES score directly (already a similarity measure for KNN)
                                $similarity = $score;
                            }
                            $quality = $sentence['quality_score'] ?? 1.0;
                            $len =  strlen( $metadata['text'] );
                            // Normalize length (cap at 500 characters)
                            $normalizedLength = min($len / 500, 1.0);
                            // Penalty for overly long sentences (e.g. > 300 characters)
                            $lengthPenalty = ($len > 300) ? 0.85 : 1.0;
                            // Penalty for boilerplate phrases
                            $boilerplatePhrases = ['in conclusion', 'as previously mentioned', 'it is important to note'];
                            $boilerplatePenalty = 1.0;
                            foreach ($boilerplatePhrases as $phrase) {
                                if (stripos($metadata['text'], $phrase) !== false) {
                                    $boilerplatePenalty = 0.8;
                                    break;
                                }
                            }
                            preg_match_all($pattern, $metadata['text'] ?? '', $matches);
                            $tokenCount = count($matches[0]);
                            $combinedScore = (
                                0.75 * $similarity +
                                0.10 * $quality +
                                0.15 * $normalizedLength
                            );
                            // Final weighted score with penalties applied
                            $combinedScore = (
                                $combinedScore
                                * $lengthPenalty
                                * $boilerplatePenalty
                            );
                            $metadata['metrics'] = [
                                'position' => $sentence['position'] ?? '?',
                                'cosine_score' => $similarity,
                                'snippet_length' => $len,
                                'quality_score' => $quality,
                                'token_matches' => $tokenCount,
                                'combined_score' => $combinedScore,
                                'document_score' => $score,
                            ];
                            $results[] = $metadata;
                            $i++;
                        }
                    }
                } else {
                    // This handles direct queries to the sentences index (no inner_hits)
                        $metadata['text'] = $source['text'] ?? '';
                        if (isset($hit['highlight']['text'][0])) {
                            $metadata['highlighted_text'] = $this->convertHighlightMarkersToHtml($hit['highlight']['text'][0]);
                            $metadata['has_highlighting'] = true;
                        } else {
                            $metadata['highlighted_text'] = $metadata['text'];
                            $metadata['has_highlighting'] = false;
                        }

                    // Calculate metrics for sorting
                    $len = strlen($metadata['text']);
                    $normalizedLength = min($len / 500, 1.0);
                    $quality = $source['hawaiian_word_ratio'] ?? 1.0;
                    $combinedScore = $quality * $normalizedLength;
                    $metadata['metrics'] = [
                        'position' => $source['position'] ?? '?',
                        'snippet_length' => $len,
                        'quality_score' => $quality,
                        'token_matches' => preg_match_all($pattern, $metadata['text'] ?? '', $matches),
                        'combined_score' => $combinedScore,
                        'document_score' => $score,
                    ];
                    
                    $results[] = $metadata;
                }
            } else {
                // Document-level modes - only return one result per document
                // Skip if we've already seen this document ID
                if (isset($seenDocuments[$metadata['sourceid']])) {
                    continue;
                }
                $seenDocuments[$metadata['sourceid']] = true;
                
                //print_r( $hit );
                $snippets = [];
                $highlightedSnippets = [];

                if (isset($hit['highlight']['text'])) {
                    // Preserve highlighted versions
                    $highlightedSnippets = $hit['highlight']['text'];
                    
                    // Create clean versions for backward compatibility
                    $snippets = array_map(function ($snippet) {
                        return str_replace(['__START_HIGHLIGHT__', '__END_HIGHLIGHT__'], '', $snippet);
                    }, $hit['highlight']['text']);
                } elseif (isset($source['text'])) {
                    // For hybrid/vector searches without highlights, extract meaningful excerpts
                    $fullText = $source['text'];
                    
                    // Try to find text containing query terms
                    $queryTerms = explode(' ', $this->getQueryTerm());
                    $bestSnippets = [];
                    
                    foreach ($queryTerms as $term) {
                        $term = trim($term);
                        if (strlen($term) < 3) continue;
                        
                        $pos = stripos($fullText, $term);
                        if ($pos !== false) {
                            // Extract context around the match
                            $start = max(0, $pos - 200);
                            $length = 500;
                            $snippet = substr($fullText, $start, $length);
                            
                            // Clean up start/end
                            if ($start > 0) $snippet = '...' . $snippet;
                            if ($start + $length < strlen($fullText)) $snippet .= '...';
                            
                            // Highlight the term manually
                            $snippet = preg_replace('/(' . preg_quote($term, '/') . ')/ui', '__START_HIGHLIGHT__$1__END_HIGHLIGHT__', $snippet);
                            $highlightedSnippets[] = $snippet;
                            $bestSnippets[] = str_replace(['__START_HIGHLIGHT__', '__END_HIGHLIGHT__'], '', $snippet);
                            break; // Use first good match
                        }
                    }
                    
                    // If no term matches found, extract first meaningful chunk
                    if (empty($bestSnippets)) {
                        $snippet = substr($fullText, 0, 500);
                        if (strlen($fullText) > 500) $snippet .= '...';
                        $snippets[] = $snippet;
                        $highlightedSnippets[] = $snippet;
                    } else {
                        $snippets = $bestSnippets;
                    }
                }

                // Ensure we always have at least one snippet
                if (empty($snippets)) {
                    if (isset($source['text']) && !empty($source['text'])) {
                        $snippet = substr($source['text'], 0, 500);
                        if (strlen($source['text']) > 500) $snippet .= '...';
                        $snippets[] = $snippet;
                        $highlightedSnippets[] = $snippet;
                    } else {
                        // Fallback: create a placeholder snippet with available metadata
                        $snippet = 'Document: ' . ($metadata['sourcename'] ?? 'unknown');
                        if (!empty($metadata['title'])) {
                            $snippet .= ' - ' . $metadata['title'];
                        }
                        $snippets[] = $snippet;
                        $highlightedSnippets[] = $snippet;
                    }
                }

                // For document-level, only use the first snippet
                $snippet = $snippets[0];
                
                // Skip completely empty snippets
                if (empty(trim($snippet))) {
                    continue;
                }
                
                $len =  strlen( $snippet );
                //$quality = $sentence['quality_score'] ?? 1.0;
                $quality = 1;
                $metadata['text'] = $snippet;
                
                // Add highlighting information if available
                if (isset($highlightedSnippets[0])) {
                    $metadata['highlighted_text'] = $this->convertHighlightMarkersToHtml($highlightedSnippets[0]);
                    $metadata['has_highlighting'] = true;
                } else {
                    $metadata['highlighted_text'] = $snippet;
                    $metadata['has_highlighting'] = false;
                }
                
                preg_match_all($pattern, $metadata['text'] ?? '', $matches);
                $tokenCount = count($matches[0]);
                $combinedScore = (
                    1.00 * $score +
                    0.0
                );
                $metadata['metrics'] = [
                    'snippet_length' => $len,
                    'quality_score' => $quality,
                    'token_matches' => $tokenCount,
                    'document_score' => $score,
                    'combined_score' => $combinedScore,
                ];
                $results[] = $metadata;
            }
        }

        // Only sort by combined_score if no explicit sort is provided
        if (empty($sortOptions)) {
            usort($results, fn($a, $b) => $b['metrics']['combined_score'] <=> $a['metrics']['combined_score']);
        }

        return $results;
    }

    /**
    * These are the ones where results are evaluated for quality based on cosine
    * similarity
    */
    private function isVectorSearchMode(string $mode): bool
    {
        return \HawaiianSearch\QueryBuilder::isVectorSearchMode( $mode );
    }

    public function getAvailableSearchModes(): array
    {
        return \HawaiianSearch\QueryBuilder::MODES;
    }

    public function index( $doc, $id, $indexName = "" ) {
        $indexName = $indexName ?? $this->getIndexName();
        $this->client->index([
            'index' => $indexName,
            'id' => $id,
            'body' => $doc
        ]);
    }
    
    public function refresh( $indexName = "" ) {
        if( $indexName ) {
            $this->client->indices()->refresh(['index' => $indexName]);
        } else {
            foreach( [$this->getDocumentsIndexName(),
                      $this->getSentencesIndexName(),
                      $this->getSourceMetadataName()] as $indexName ) {
                $this->client->indices()->refresh(['index' => $indexName]);
            }
        }
    }
        
 public function getAllRecords( $index, $limit = 10000 ): array
    {
        try {
            $params = [
                'index' => $index,
                'scroll' => '1m', // Scroll context valid for 1 minute
                'size' => $limit,   // Number of docs per batch
                'from' => 0,
                '_source' => true,
                'size' => $limit,
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass(), // Match all documents
                    ],
                ],
            ];

            $response = $this->client->search($params);

            // Collect initial batch
            $documents = $response['hits']['hits'];
            $scrollId = $response['_scroll_id'];

            // Continue scrolling
            while (count($response['hits']['hits']) > 0) {
                $response = $this->client->scroll([
                    'scroll_id' => $scrollId,
                    'scroll' => '1m'
                ]);
                //$newdocs = $response['hits']['hits'];
                $newdocs = $response['hits']['hits'];
                //$this->print( "Received " . count($newdocs) . " documents" );
                //$this->print( var_export( $newdocs, true ) );

                $documents = array_merge($documents, $newdocs);
                $scrollId = $response['_scroll_id'];
            }

            return $documents;
        } catch (ClientResponseException |
                 ConnectException | NoNodeAvailableException $e) {
            $this->print( "Error getting source IDs: " . $e->getMessage() );
            return [];
        }
    }
    
    /**
     * Get all source IDs (doc_ids) present in the main index
     */
    public function getAllSourceIds( $index = '', $batchsize = 100 ): array
    {
        $index = empty($index) ? $this->getDocumentsIndexName() : $index;
        try {

            $params = [
                'index' => $index,
                'scroll' => '1m', // Scroll context valid for 1 minute
                'size' => $batchsize,   // Number of docs per batch
                'body' => [
                    '_source' => [
                        'includes' => [
                            'doc_id',
                        ],
                        'excludes' => [
                            'hawaiian_word_ratio',
                            'text',
                            'sentences.text',
                            'sentences.position',
                            'text_chunks',
                            
                            'text_vector',
                            'sentences.vector',
                        ],
                    ],
                    'query' => [
                        'match_all' => new \stdClass(), // Match all documents
                    ],
                ],
                'filter_path' => ['_scroll_id', 'hits.hits._source'],
            ];

            $response = $this->client->search($params);

            // Collect initial batch
            $documents = $response['hits']['hits'];
            $scrollId = $response['_scroll_id'];

            // Continue scrolling
            while (count($response['hits']['hits']) > 0) {
                $response = $this->client->scroll([
                    'scroll_id' => $scrollId,
                    'scroll' => '1m'
                ]);
                //$newdocs = $response['hits']['hits'];
                $newdocs = array_column( $response['hits']['hits'], '_source' );
                //$this->print( "Received " . count($newdocs) . " documents" );
                //$this->print( var_export( $newdocs, true ) );

                $documents = array_merge($documents, $newdocs);
                $scrollId = $response['_scroll_id'];
            }

            return $documents;
        } catch (ClientResponseException |
                 ConnectException | NoNodeAvailableException $e) {
            $this->print( "Error getting source IDs: " . $e->getMessage() );
            return [];
        }
    }
    
    /**
     * Check if a specific source ID exists in the main index
     */
    public function hasSourceId(string $sourceId): bool
    {
        return $this->documentExists($sourceId);
    }

    /**
     * Convert highlight markers to HTML mark tags
     */
    private function convertHighlightMarkersToHtml(string $text): string
    {
        return str_replace(
            ['__START_HIGHLIGHT__', '__END_HIGHLIGHT__'], 
            ['<mark>', '</mark>'], 
            $text
        );
    }

    public function getRandomWord(): string
    {
        // Get a random sentence from the sentences index
        $params = [
            'index' => $this->getSentencesIndexName(),
            'body' => [
                'size' => 1,
                'query' => [
                    'function_score' => [
                        'query' => [
                            'match_all' => new \stdClass()
                        ],
                        'random_score' => new \stdClass()
                    ]
                ],
                '_source' => ['text']
            ]
        ];

        $response = $this->client->search($params);
        $data = $response->asArray();
        $this->debuglog( "getRandomWord: " . var_export( $data, true ) );

        $hits = $data['hits']['hits'] ?? [];
        $longestWord = '';
        if (!empty($hits) && isset($hits[0]['_source']['text'])) {

            $sentence = $hits[0]['_source']['text'];

            // Split sentence into words
            $rawWords = preg_split('/\s+/', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);

            // Clean punctuation and filter out empty results
            $words = array_filter(array_map(function ($word) {
                // Remove leading/trailing punctuation
                $clean = trim($word, " \t\n\r\0\x0B.,!?;:\"'()[]{}<>ʻ");
                return preg_match('/\w/u', $clean) ? $clean : null;
            }, $rawWords));

            // Find the longest word
            foreach ($words as $word) {
                if (mb_strlen($word) > mb_strlen($longestWord)) {
                    $longestWord = $word;
                }
            }
        }
        return $longestWord;
    }

    /**
     * Query the source metadata index to find a source by its link field
     * 
     * @param string $link The link to search for
     * @return array|null The source metadata record if found, null otherwise
     */
    public function getSourceByLink(string $link): ?array
    {
        try {
            $params = [
                'index' => $this->getSourceMetadataName(),
                'body' => [
                    'query' => [
                        'term' => [
                            'link' => $link
                        ]
                    ],
                    'size' => 1
                ]
            ];

            $response = $this->client->search($params);
            $data = $response->asArray();
            
            $hits = $data['hits']['hits'] ?? [];
            if (!empty($hits)) {
                return $hits[0]['_source'];
            }
            
            return null;
        } catch (\Exception $e) {
            $this->debuglog("Error getting source by link: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get source metadata by sourceID
     * 
     * @param int $sourceID The source ID to retrieve
     * @return array|null The source metadata or null if not found
     */
    public function getSourceById(int $sourceID): ?array
    {
        try {
            $params = [
                'index' => $this->getSourceMetadataName(),
                'body' => [
                    'query' => [
                        'term' => [
                            'sourceid' => $sourceID
                        ]
                    ],
                    'size' => 1
                ]
            ];

            $response = $this->client->search($params);
            $data = $response->asArray();
            
            $hits = $data['hits']['hits'] ?? [];
            if (!empty($hits)) {
                $source = $hits[0]['_source'];
                // Ensure sourceid is set in the returned data
                $source['sourceid'] = $sourceID;
                return $source;
            }
            
            return null;
        } catch (\Exception $e) {
            $this->debuglog("Error getting source by ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all source metadata, optionally filtered by groupname
     * Uses scroll API to retrieve all results regardless of count
     * 
     * @param string|null $groupname Optional groupname filter
     * @return array Array of source metadata documents
     */
    public function getAllSources(?string $groupname = null): array
    {
        try {
            $query = [
                'bool' => [
                    'must_not' => [
                        ['term' => ['_id' => '_sourceid_counter']]
                    ]
                ]
            ];
            
            if ($groupname) {
                $query['bool']['must'] = [
                    ['term' => ['groupname' => $groupname]]
                ];
            }
            
            $params = [
                'index' => $this->getSourceMetadataName(),
                'scroll' => '2m',
                'body' => [
                    'size' => 1000,
                    '_source' => ['sourceid', 'sourcename', 'groupname', 'link', 'title', 'authors', 'date'],
                    'query' => $query,
                    'sort' => [['sourceid' => ['order' => 'asc']]]
                ]
            ];
            
            $response = $this->client->search($params);
            $data = $response->asArray();
            
            $sources = [];
            $scrollId = $data['_scroll_id'] ?? null;
            
            // Get first batch
            $hits = $data['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $sources[] = $hit['_source'];
            }
            
            // Continue scrolling until no more results
            while (!empty($hits) && $scrollId) {
                $response = $this->client->scroll([
                    'scroll_id' => $scrollId,
                    'scroll' => '2m'
                ]);
                $data = $response->asArray();
                $hits = $data['hits']['hits'] ?? [];
                $scrollId = $data['_scroll_id'] ?? null;
                
                foreach ($hits as $hit) {
                    $sources[] = $hit['_source'];
                }
            }
            
            // Clear scroll context
            if ($scrollId) {
                try {
                    $this->client->clearScroll(['scroll_id' => $scrollId]);
                } catch (\Exception $e) {
                    // Ignore errors clearing scroll
                }
            }
            
            return $sources;
        } catch (\Exception $e) {
            $this->debuglog("Error getting all sources: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate the next available sourceID using a counter document
     * This is the single source of truth, similar to MySQL AUTO_INCREMENT
     * Returns the ID as a string (as they are stored in Elasticsearch)
     * 
     * @return string The next available sourceID as a string
     */
    public function getNextSourceId(): string
    {
        $counterDocId = '_sourceid_counter';
        $maxRetries = 5;
        
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            try {
                // Get the current counter value
                $response = $this->client->get([
                    'index' => $this->getSourceMetadataName(),
                    'id' => $counterDocId
                ]);
                
                $currentCounter = intval($response['_source']['counter'] ?? 0);
                $seqNo = $response['_seq_no'];
                $primaryTerm = $response['_primary_term'];
                
                $nextId = $currentCounter + 1;
                
                // Try to update with optimistic concurrency control
                $this->client->index([
                    'index' => $this->getSourceMetadataName(),
                    'id' => $counterDocId,
                    'if_seq_no' => $seqNo,
                    'if_primary_term' => $primaryTerm,
                    'body' => [
                        'counter' => $nextId,
                        'updated_at' => date('c')
                    ]
                ]);
                
                // Success - return the next ID
                return (string)$nextId;
                
            } catch (ClientResponseException $e) {
                // If counter doesn't exist, initialize it
                if ($e->getCode() === 404) {
                    try {
                        // Initialize counter based on existing data
                        $maxId = $this->findMaxSourceIdFromData();
                        
                        $this->client->index([
                            'index' => $this->getSourceMetadataName(),
                            'id' => $counterDocId,
                            'op_type' => 'create',
                            'body' => [
                                'counter' => $maxId,
                                'created_at' => date('c'),
                                'updated_at' => date('c')
                            ]
                        ]);
                        
                        // Refresh to make it visible
                        $this->client->indices()->refresh([
                            'index' => $this->getSourceMetadataName()
                        ]);
                        
                        // Retry getting next ID
                        continue;
                        
                    } catch (\Exception $e2) {
                        // If create failed due to race condition, retry
                        if ($retry < $maxRetries - 1) {
                            usleep(100000); // 100ms delay
                            continue;
                        }
                    }
                } elseif ($e->getCode() === 409) {
                    // Conflict - another process updated the counter, retry
                    if ($retry < $maxRetries - 1) {
                        usleep(50000); // 50ms delay
                        continue;
                    }
                }
                
                throw $e;
            }
        }
        
        throw new \RuntimeException("Failed to get next source ID after $maxRetries retries");
    }
    
    /**
     * Find the maximum sourceID from existing data across all indices
     * Used only for initializing the counter
     * 
     * @return int The maximum sourceID found
     */
    private function findMaxSourceIdFromData(): int
    {
        $maxId = 0;
        
        try {
            // Check source metadata index
            if ($this->indexExists($this->getSourceMetadataName())) {
                $params = [
                    'index' => $this->getSourceMetadataName(),
                    'body' => [
                        'size' => 0,
                        'aggs' => [
                            'max_sourceid' => [
                                'max' => [
                                    'script' => [
                                        'source' => "Integer.parseInt(doc['sourceid'].value)"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
                $response = $this->client->search($params);
                $data = $response->asArray();
                $sourceMetaMax = intval($data['aggregations']['max_sourceid']['value'] ?? 0);
                if ($sourceMetaMax > $maxId) {
                    $maxId = $sourceMetaMax;
                }
            }
        } catch (\Exception $e) {
            $this->debuglog("Could not check source metadata max ID: " . $e->getMessage());
        }
        
        return $maxId;
    }
    
    /**
     * Verify referential integrity between source metadata and other indices
     * Checks that all documents/sentences have corresponding source metadata
     * 
     * @return array Report with warnings if integrity issues found
     */
    public function checkSourceIntegrity($checkOrphans = true): array
    {
        $report = [
            'status' => 'ok',
            'warnings' => [],
            'counter_value' => 0,
            'max_in_metadata' => 0,
            'max_in_documents' => 0,
            'max_in_sentences' => 0,
            'orphaned_documents' => [],
            'orphaned_sentences' => [],
            'empty_metadata' => []
        ];
        
        try {
            // Get counter value
            $counterDocId = '_sourceid_counter';
            try {
                $response = $this->client->get([
                    'index' => $this->getSourceMetadataName(),
                    'id' => $counterDocId
                ]);
                $report['counter_value'] = intval($response['_source']['counter'] ?? 0);
            } catch (\Exception $e) {
                $report['warnings'][] = "No sourceID counter found - will be initialized on first addSource()";
            }
            
            // Check for orphaned records if requested - this will also collect max IDs
            if ($checkOrphans) {
                $this->checkOrphanedRecords($report);
            }
            
        } catch (\Exception $e) {
            $report['status'] = 'error';
            $report['warnings'][] = "Error checking integrity: " . $e->getMessage();
        }
        
        return $report;
    }
    
    /**
     * Check for orphaned documents, sentences, and empty metadata
     * Also calculates max IDs in each index
     */
    private function checkOrphanedRecords(array &$report): void
    {
        $startTime = microtime(true);
        $this->debuglog("Starting orphan detection...");
        $this->print("Starting integrity check with orphan detection...");
        
        try {
            // Get all source IDs from metadata using scroll API
            $this->debuglog("Phase 1/4: Scanning metadata index...");
            $this->print("Phase 1/4: Scanning metadata index...");
            $metadataSourceIds = [];
            $metadataCount = 0;
            $maxInMetadata = 0;
            if ($this->indexExists($this->getSourceMetadataName())) {
                $params = [
                    'index' => $this->getSourceMetadataName(),
                    'scroll' => '5m',
                    'body' => [
                        'size' => 1000,
                        '_source' => ['sourceid'],
                        'query' => [
                            'bool' => [
                                'must_not' => [
                                    ['term' => ['_id' => '_sourceid_counter']]
                                ]
                            ]
                        ]
                    ]
                ];
                $response = $this->client->search($params);
                $scrollId = $response['_scroll_id'];
                
                // Process first batch
                foreach ($response['hits']['hits'] ?? [] as $hit) {
                    $sourceId = $hit['_source']['sourceid'] ?? null;
                    if ($sourceId) {
                        $metadataSourceIds[$sourceId] = true;
                        $metadataCount++;
                        if ($sourceId > $maxInMetadata) {
                            $maxInMetadata = $sourceId;
                        }
                    }
                }
                
                // Continue scrolling
                while (count($response['hits']['hits']) > 0) {
                    $response = $this->client->scroll([
                        'scroll_id' => $scrollId,
                        'scroll' => '5m'
                    ]);
                    
                    foreach ($response['hits']['hits'] ?? [] as $hit) {
                        $sourceId = $hit['_source']['sourceid'] ?? null;
                        if ($sourceId) {
                            $metadataSourceIds[$sourceId] = true;
                            $metadataCount++;
                            if ($sourceId > $maxInMetadata) {
                                $maxInMetadata = $sourceId;
                            }
                        }
                    }
                    
                    if (empty($response['hits']['hits'])) {
                        break;
                    }
                    
                    if ($metadataCount % 5000 == 0) {
                        $this->print("  Scanned $metadataCount metadata records...");
                    }
                }
                
                // Clear scroll
                try {
                    $this->client->clearScroll(['scroll_id' => $scrollId]);
                } catch (\Exception $e) {
                    // Ignore errors clearing scroll
                }
            }
            $report['max_in_metadata'] = $maxInMetadata;
            $this->print("  Found $metadataCount metadata records, max ID: $maxInMetadata");
            
            // Check documents for orphans using scroll API for large result sets
            $this->print("Phase 2/4: Scanning documents index...");
            $documentsScanned = 0;
            $maxInDocuments = 0;
            if ($this->indexExists($this->getDocumentsIndexName())) {
                $params = [
                    'index' => $this->getDocumentsIndexName(),
                    'scroll' => '5m',
                    'body' => [
                        'size' => 1000,
                        '_source' => false,
                        'query' => ['match_all' => new \stdClass()]
                    ]
                ];
                $response = $this->client->search($params);
                $scrollId = $response['_scroll_id'];
                
                // Process first batch
                foreach ($response['hits']['hits'] ?? [] as $hit) {
                    $docId = intval($hit['_id']);
                    $documentsScanned++;
                    if ($docId > $maxInDocuments) {
                        $maxInDocuments = $docId;
                    }
                    if (!isset($metadataSourceIds[$docId])) {
                        $report['orphaned_documents'][] = $docId;
                    }
                }
                
                // Continue scrolling until no more results
                while (count($response['hits']['hits']) > 0) {
                    $response = $this->client->scroll([
                        'scroll_id' => $scrollId,
                        'scroll' => '5m'
                    ]);
                    
                    foreach ($response['hits']['hits'] ?? [] as $hit) {
                        $docId = intval($hit['_id']);
                        $documentsScanned++;
                        if ($docId > $maxInDocuments) {
                            $maxInDocuments = $docId;
                        }
                        if (!isset($metadataSourceIds[$docId])) {
                            $report['orphaned_documents'][] = $docId;
                        }
                    }
                    
                    if (empty($response['hits']['hits'])) {
                        break;
                    }
                    
                    if ($documentsScanned % 5000 == 0) {
                        $orphanCount = count($report['orphaned_documents']);
                        $this->print("  Scanned $documentsScanned documents, found $orphanCount orphans...");
                    }
                }
                
                // Clear scroll
                try {
                    $this->client->clearScroll(['scroll_id' => $scrollId]);
                } catch (\Exception $e) {
                    // Ignore errors clearing scroll
                }
            }
            $report['max_in_documents'] = $maxInDocuments;
            $this->print("  Scanned $documentsScanned documents, found " . count($report['orphaned_documents']) . " orphans, max ID: $maxInDocuments");
            
            // Check sentences for orphans using aggregation on doc_id.keyword
            $this->debuglog("Phase 3/4: Aggregating unique doc_ids from sentences index...");
            $this->print("Phase 3/4: Aggregating unique doc_ids from sentences index...");
            $maxInSentences = 0;
            if ($this->indexExists($this->getSentencesIndexName())) {
                $orphanedSources = [];
                
                // Use composite aggregation to get all unique doc_ids (handles more than 10k)
                $params = [
                    'index' => $this->getSentencesIndexName(),
                    'body' => [
                        'size' => 0,
                        'aggs' => [
                            'unique_doc_ids' => [
                                'composite' => [
                                    'size' => 10000,
                                    'sources' => [
                                        ['doc_id' => ['terms' => ['field' => 'doc_id.keyword']]]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
                
                $afterKey = null;
                $totalChecked = 0;
                
                do {
                    if ($afterKey) {
                        $params['body']['aggs']['unique_doc_ids']['composite']['after'] = $afterKey;
                    }
                    
                    $response = $this->client->search($params);
                    $buckets = $response['aggregations']['unique_doc_ids']['buckets'] ?? [];
                    
                    foreach ($buckets as $bucket) {
                        $sourceId = intval($bucket['key']['doc_id']);
                        $totalChecked++;
                        if ($sourceId > $maxInSentences) {
                            $maxInSentences = $sourceId;
                        }
                        if (!isset($metadataSourceIds[$sourceId])) {
                            $orphanedSources[$sourceId] = true;
                        }
                    }
                    
                    if ($totalChecked % 10000 == 0) {
                        $orphanCount = count($orphanedSources);
                        $this->print("  Checked $totalChecked unique source IDs, found $orphanCount orphans...");
                    }
                    
                    $afterKey = $response['aggregations']['unique_doc_ids']['after_key'] ?? null;
                } while ($afterKey);
                
                $report['orphaned_sentences'] = array_keys($orphanedSources);
            }
            $report['max_in_sentences'] = $maxInSentences;
            $this->print("  Checked " . ($totalChecked ?? 0) . " unique source IDs, found " . count($report['orphaned_sentences']) . " orphaned sources, max ID: $maxInSentences");
            
            // Check for empty metadata (sources with no documents or sentences)
            // We can do this efficiently by building sets from the data we already collected
            $this->debuglog("Phase 4/4: Checking for empty metadata records...");
            $this->print("Phase 4/4: Checking for empty metadata records...");
            
            // Build sets of source IDs that have documents or sentences
            $sourcesWithDocs = [];
            $sourcesWithSentences = [];
            
            // Get all document IDs (we need to scan again but just for IDs)
            if ($this->indexExists($this->getDocumentsIndexName())) {
                $params = [
                    'index' => $this->getDocumentsIndexName(),
                    'scroll' => '5m',
                    'body' => [
                        'size' => 1000,
                        '_source' => false,
                        'query' => ['match_all' => new \stdClass()]
                    ]
                ];
                $response = $this->client->search($params);
                $scrollId = $response['_scroll_id'];
                
                foreach ($response['hits']['hits'] ?? [] as $hit) {
                    $sourcesWithDocs[$hit['_id']] = true;
                }
                
                while (count($response['hits']['hits']) > 0) {
                    $response = $this->client->scroll([
                        'scroll_id' => $scrollId,
                        'scroll' => '5m'
                    ]);
                    
                    foreach ($response['hits']['hits'] ?? [] as $hit) {
                        $sourcesWithDocs[$hit['_id']] = true;
                    }
                    
                    if (empty($response['hits']['hits'])) {
                        break;
                    }
                }
                
                try {
                    $this->client->clearScroll(['scroll_id' => $scrollId]);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            
            // Get all unique doc_ids from sentences (reuse the aggregation approach)
            if ($this->indexExists($this->getSentencesIndexName())) {
                $params = [
                    'index' => $this->getSentencesIndexName(),
                    'body' => [
                        'size' => 0,
                        'aggs' => [
                            'unique_doc_ids' => [
                                'composite' => [
                                    'size' => 10000,
                                    'sources' => [
                                        ['doc_id' => ['terms' => ['field' => 'doc_id.keyword']]]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
                
                $afterKey = null;
                do {
                    if ($afterKey) {
                        $params['body']['aggs']['unique_doc_ids']['composite']['after'] = $afterKey;
                    }
                    
                    $response = $this->client->search($params);
                    $buckets = $response['aggregations']['unique_doc_ids']['buckets'] ?? [];
                    
                    foreach ($buckets as $bucket) {
                        $sourcesWithSentences[$bucket['key']['doc_id']] = true;
                    }
                    
                    $afterKey = $response['aggregations']['unique_doc_ids']['after_key'] ?? null;
                } while ($afterKey);
            }
            
            // Now quickly check each metadata record
            $checkedCount = 0;
            foreach (array_keys($metadataSourceIds) as $sourceId) {
                $checkedCount++;
                $hasDoc = isset($sourcesWithDocs[$sourceId]);
                $hasSentences = isset($sourcesWithSentences[$sourceId]);
                
                if (!$hasDoc && !$hasSentences) {
                    $report['empty_metadata'][] = $sourceId;
                }
            }
            $this->print("  Checked $checkedCount metadata records, found " . count($report['empty_metadata']) . " empty");
            
            $elapsed = microtime(true) - $startTime;
            $this->debuglog(sprintf("Orphan detection completed in %.2f seconds", $elapsed));
            
            // Check for max ID integrity issues
            $maxAcrossAll = max($report['max_in_metadata'], $report['max_in_documents'], $report['max_in_sentences']);
            
            if ($report['counter_value'] > 0 && $report['counter_value'] < $maxAcrossAll) {
                $report['status'] = 'warning';
                $report['warnings'][] = "Counter value ({$report['counter_value']}) is less than max ID in use ({$maxAcrossAll})";
            }
            
            if ($report['max_in_documents'] > $report['max_in_metadata']) {
                $report['status'] = 'warning';
                $report['warnings'][] = "Documents exist with IDs higher than source metadata (max doc: {$report['max_in_documents']}, max meta: {$report['max_in_metadata']})";
            }
            
            if ($report['max_in_sentences'] > $report['max_in_metadata']) {
                $report['status'] = 'warning';
                $report['warnings'][] = "Sentences exist with IDs higher than source metadata (max sentence: {$report['max_in_sentences']}, max meta: {$report['max_in_metadata']})";
            }
            
            // Update status and warnings for orphans
            if (!empty($report['orphaned_documents'])) {
                $report['status'] = 'warning';
                $count = count($report['orphaned_documents']);
                $report['warnings'][] = "$count orphaned document(s) found (documents without source metadata)";
            }
            if (!empty($report['orphaned_sentences'])) {
                $report['status'] = 'warning';
                $count = count($report['orphaned_sentences']);
                $report['warnings'][] = "$count source(s) with orphaned sentences (sentences without source metadata)";
            }
            if (!empty($report['empty_metadata'])) {
                $report['status'] = 'warning';
                $count = count($report['empty_metadata']);
                $report['warnings'][] = "$count empty metadata record(s) found (metadata without documents or sentences)";
            }
        } catch (\Exception $e) {
            $report['warnings'][] = "Error checking orphaned records: " . $e->getMessage();
        }
    }
    
    /**
     * Fix integrity issues by removing orphaned records
     */
    public function fixIntegrityIssues(array $integrityReport): array
    {
        $results = [
            'orphaned_documents_deleted' => 0,
            'orphaned_sentences_deleted' => 0,
            'empty_metadata_deleted' => 0
        ];
        
        // Delete orphaned documents
        foreach ($integrityReport['orphaned_documents'] ?? [] as $docId) {
            try {
                $this->client->delete([
                    'index' => $this->getDocumentsIndexName(),
                    'id' => $docId
                ]);
                $results['orphaned_documents_deleted']++;
            } catch (\Exception $e) {
                $this->debuglog("Failed to delete orphaned document $docId: " . $e->getMessage());
            }
        }
        
        // Delete orphaned sentences (by source ID)
        foreach ($integrityReport['orphaned_sentences'] ?? [] as $sourceId) {
            try {
                $response = $this->client->deleteByQuery([
                    'index' => $this->getSentencesIndexName(),
                    'body' => [
                        'query' => [
                            'term' => [
                                'doc_id.keyword' => $sourceId
                            ]
                        ]
                    ]
                ]);
                $results['orphaned_sentences_deleted'] += ($response['deleted'] ?? 0);
            } catch (\Exception $e) {
                $this->debuglog("Failed to delete orphaned sentences for source $sourceId: " . $e->getMessage());
            }
        }
        
        // Delete empty metadata
        foreach ($integrityReport['empty_metadata'] ?? [] as $sourceId) {
            try {
                $this->client->delete([
                    'index' => $this->getSourceMetadataName(),
                    'id' => $sourceId
                ]);
                $results['empty_metadata_deleted']++;
            } catch (\Exception $e) {
                $this->debuglog("Failed to delete empty metadata $sourceId: " . $e->getMessage());
            }
        }
        
        return $results;
    }

    /**
     * Create a new source metadata record with a generated sourceID
     * Emulates MySQL auto-increment behavior
     * 
     * @param string $sourceName The name of the source
     * @param array $params Additional parameters (groupname, authors, date, link, title, etc.)
     * @return string|null The generated sourceID if successful, null otherwise
     */
    public function addSource(string $sourceName, array $params = []): ?string
    {
        try {
            // Check if source with this link already exists to prevent duplicates
            if (!empty($params['link'])) {
                $existing = $this->getSourceByLink($params['link']);
                if ($existing) {
                    $this->debuglog("Source with link {$params['link']} already exists with ID {$existing['sourceid']}");
                    return $existing['sourceid'];
                }
            }
            
            // Generate the next sourceID
            $sourceId = $this->getNextSourceId();
            
            // Prepare the document
            $document = [
                'sourceid' => $sourceId,
                'sourcename' => $sourceName,
                'groupname' => $params['groupname'] ?? '',
                'authors' => $params['authors'] ?? '',
                'date' => !empty($params['date']) ? $params['date'] : null,
                'link' => $params['link'] ?? '',
                'title' => $params['title'] ?? '',
                'created_at' => date('c'), // ISO 8601 format
                'discarded' => $params['discarded'] ?? false,
                'empty' => $params['empty'] ?? false,
                'quality' => $params['quality'] ?? null
            ];
            
            // Index the document with the sourceID as the document ID
            $this->client->index([
                'index' => $this->getSourceMetadataName(),
                'id' => $sourceId,
                'body' => $document
            ]);
            
            // Refresh the index to make the new source immediately visible
            $this->client->indices()->refresh([
                'index' => $this->getSourceMetadataName()
            ]);
            
            return $sourceId;
        } catch (\Exception $e) {
            $this->lastError = $e;
            $this->debuglog("Error adding source '$sourceName': " . $e->getMessage());
            $this->debuglog("  Link: " . ($params['link'] ?? 'none'));
            $this->debuglog("  Groupname: " . ($params['groupname'] ?? 'none'));
            $this->debuglog("  Exception class: " . get_class($e));
            if ($e->getCode()) {
                $this->debuglog("  Error code: " . $e->getCode());
            }
            error_log("ElasticsearchClient::addSource failed for '$sourceName' (link: " . ($params['link'] ?? 'none') . "): " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a source metadata record
     * 
     * @param string $sourceId The source ID to delete
     * @return bool True if deleted successfully, false otherwise
     */
    public function deleteSourceMetadata(string $sourceId): bool
    {
        try {
            $this->client->delete([
                'index' => $this->getSourceMetadataName(),
                'id' => $sourceId
            ]);
            
            $this->debuglog("Deleted source metadata for ID: $sourceId");
            return true;
        } catch (\Exception $e) {
            $this->lastError = $e;
            $this->debuglog("Error deleting source metadata '$sourceId': " . $e->getMessage());
            error_log("ElasticsearchClient::deleteSourceMetadata failed for '$sourceId': " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the last error that occurred
     * @return \Exception|null The last exception or null if no error
     */
    public function getLastError(): ?\Exception
    {
        return $this->lastError;
    }
    
    /**
     * Manually set the source ID counter to a specific value
     * Use this to fix counter drift or set it to a safe value above existing IDs
     * 
     * @param int $newCounter The new counter value to set
     * @return bool True on success, false on failure
     */
    public function setSourceCounter(int $newCounter): bool
    {
        $counterDocId = '_sourceid_counter';
        
        try {
            $this->client->index([
                'index' => $this->getSourceMetadataName(),
                'id' => $counterDocId,
                'body' => [
                    'counter' => $newCounter,
                    'updated_at' => date('c'),
                    'note' => 'Manually set to prevent ID overlap'
                ]
            ]);
            
            // Refresh to make it visible immediately
            $this->client->indices()->refresh([
                'index' => $this->getSourceMetadataName()
            ]);
            
            $this->debuglog("Source counter set to $newCounter");
            return true;
            
        } catch (\Exception $e) {
            $this->debuglog("Error setting source counter: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ==================================================================
     * PROCESSING LOGS - Track document processing operations
     * ==================================================================
     */

    /**
     * Start a processing log entry
     * 
     * @param string $operationType Type of operation (e.g., 'save_contents', 'reindex', 'batch_process')
     * @param int|null $sourceID Source ID being processed
     * @param string|null $groupname Parser/group name
     * @param string|null $parserKey Parser key
     * @param array|null $metadata Additional metadata
     * @return string|null Log ID for later reference, or null on failure
     */
    public function startProcessingLog($operationType, $sourceID = null, $groupname = null, $parserKey = null, $metadata = null) {
        try {
            $logDoc = [
                'operation_type' => $operationType,
                'source_id' => $sourceID,
                'groupname' => $groupname,
                'parser_key' => $parserKey,
                'status' => 'started',
                'sentences_count' => 0,
                'started_at' => date('c'),
                'completed_at' => null,
                'error_message' => null,
                'metadata' => $metadata
            ];
            
            // Use a unique ID that we can reference later
            $logId = uniqid('log_', true);
            $this->index($logDoc, $logId, 'processing-logs');
            return $logId;
        } catch (\Exception $e) {
            error_log("Failed to start processing log: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Complete a processing log entry
     * 
     * @param string|null $logID The log ID returned from startProcessingLog
     * @param string $status Status: 'completed', 'failed', 'skipped'
     * @param int $sentencesCount Number of sentences processed
     * @param string|null $errorMessage Error message if status is 'failed'
     * @return bool Success status
     */
    public function completeProcessingLog($logID, $status = 'completed', $sentencesCount = 0, $errorMessage = null) {
        if (!$logID) return false;
        
        try {
            // First, get the existing document to preserve fields
            $existing = $this->getDocument($logID, 'processing-logs');
            
            if ($existing) {
                // Merge the updates with existing data
                $existing['status'] = $status;
                $existing['sentences_count'] = $sentencesCount;
                $existing['completed_at'] = date('c');
                $existing['error_message'] = $errorMessage;
                
                // Re-index the complete document
                $this->index($existing, $logID, 'processing-logs');
            } else {
                // Document doesn't exist, just log the error
                error_log("Processing log document $logID not found for update");
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("Failed to complete processing log: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get metadata about the processing logs index
     * 
     * @return array Metadata including available statuses, operation types, and timestamp range
     */
    public function getProcessingLogsMetadata() {
        try {
            $response = $this->client->search([
                'index' => 'processing-logs',
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'statuses' => [
                            'terms' => ['field' => 'status.keyword', 'size' => 100]
                        ],
                        'operation_types' => [
                            'terms' => ['field' => 'operation_type.keyword', 'size' => 100]
                        ],
                        'groupnames' => [
                            'terms' => ['field' => 'groupname.keyword', 'size' => 100]
                        ],
                        'first_timestamp' => [
                            'min' => ['field' => 'started_at']
                        ],
                        'last_timestamp' => [
                            'max' => ['field' => 'started_at']
                        ]
                    ]
                ]
            ])->asArray();
            
            $aggs = $response['aggregations'] ?? [];
            
            return [
                'statuses' => array_column($aggs['statuses']['buckets'] ?? [], 'key'),
                'operation_types' => array_column($aggs['operation_types']['buckets'] ?? [], 'key'),
                'groupnames' => array_column($aggs['groupnames']['buckets'] ?? [], 'key'),
                'first_timestamp' => $aggs['first_timestamp']['value_as_string'] ?? null,
                'last_timestamp' => $aggs['last_timestamp']['value_as_string'] ?? null,
                'total_logs' => $response['hits']['total']['value'] ?? 0
            ];
        } catch (\Exception $e) {
            error_log("Failed to get processing logs metadata: " . $e->getMessage());
            return [
                'statuses' => [],
                'operation_types' => [],
                'groupnames' => [],
                'first_timestamp' => null,
                'last_timestamp' => null,
                'total_logs' => 0
            ];
        }
    }
    
    /**
     * Get processing logs with optional filters
     * 
     * @param array $options Filtering options:
     *   - operation_type: Filter by operation type
     *   - groupname: Filter by groupname
     *   - status: Filter by status
     *   - from: Start timestamp (ISO 8601 format or strtotime compatible)
     *   - to: End timestamp (ISO 8601 format or strtotime compatible)
     *   - limit: Maximum number of results (default 100)
     * @return array Array of log entries
     */
    public function getProcessingLogs($options = []) {
        try {
            $query = ['match_all' => (object)[]];
            $filters = [];
            
            if (isset($options['operation_type'])) {
                $filters[] = ['term' => ['operation_type.keyword' => $options['operation_type']]];
            }
            if (isset($options['groupname'])) {
                $filters[] = ['term' => ['groupname.keyword' => $options['groupname']]];
            }
            if (isset($options['status'])) {
                $filters[] = ['term' => ['status.keyword' => $options['status']]];
            }
            
            // Add timestamp range filter if from/to provided
            if (isset($options['from']) || isset($options['to'])) {
                $rangeFilter = ['range' => ['started_at' => []]];
                
                // Get system timezone from environment or default to UTC
                $systemTz = getenv('TZ') ?: 'UTC';
                
                if (isset($options['from'])) {
                    // Parse as local time (using system timezone) and convert to UTC for Elasticsearch query
                    $fromDateTime = new \DateTime($options['from'], new \DateTimeZone($systemTz));
                    $fromDateTime->setTimezone(new \DateTimeZone('UTC'));
                    $rangeFilter['range']['started_at']['gte'] = $fromDateTime->format('c');
                }
                
                if (isset($options['to'])) {
                    // Parse as local time (using system timezone) and convert to UTC for Elasticsearch query
                    $toDateTime = new \DateTime($options['to'], new \DateTimeZone($systemTz));
                    $toDateTime->setTimezone(new \DateTimeZone('UTC'));
                    $rangeFilter['range']['started_at']['lte'] = $toDateTime->format('c');
                }
                
                if (!empty($rangeFilter['range']['started_at'])) {
                    $filters[] = $rangeFilter;
                }
            }
            
            if (!empty($filters)) {
                $query = [
                    'bool' => [
                        'must' => $query,
                        'filter' => $filters
                    ]
                ];
            }
            
            // Sort ascending (oldest first) when using 'from', otherwise descending (newest first)
            $sortOrder = isset($options['from']) ? 'asc' : 'desc';
            
            $searchParams = [
                'index' => 'processing-logs',
                'body' => [
                    'query' => $query,
                    'sort' => [['started_at' => ['order' => $sortOrder]]],
                    'size' => $options['limit'] ?? 100
                ]
            ];
            
            $response = $this->client->search($searchParams)->asArray();
            $hits = $response['hits']['hits'] ?? [];
            
            return array_map(function($hit) {
                return array_merge(['log_id' => $hit['_id']], $hit['_source']);
            }, $hits);
            
        } catch (\Exception $e) {
            error_log("Failed to get processing logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Execute a processing operation with automatic logging
     * 
     * @param string $operationType Type of operation being performed
     * @param callable $operation The operation to execute
     * @param array $context Additional context for logging (sourceID, groupname, parserKey, metadata)
     * @return mixed The result of the operation
     */
    public function loggedOperation($operationType, callable $operation, array $context = []) {
        $logID = $this->startProcessingLog(
            $operationType,
            $context['sourceID'] ?? null,
            $context['groupname'] ?? null,
            $context['parserKey'] ?? null,
            $context['metadata'] ?? null
        );
        
        try {
            $result = $operation();
            
            // Determine sentence count from result if it's numeric
            $sentencesCount = is_numeric($result) ? $result : 0;
            
            if ($logID) {
                $this->completeProcessingLog($logID, 'completed', $sentencesCount);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            if ($logID) {
                $this->completeProcessingLog($logID, 'failed', 0, $e->getMessage());
            }
            throw $e;
        }
    }
}

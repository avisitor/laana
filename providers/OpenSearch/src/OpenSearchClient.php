<?php

namespace HawaiianSearch;

use OpenSearch\ClientBuilder;
use HawaiianSearch\OpenSearchQueryBuilder;

require_once __DIR__ . '/OpenSearchQueryBuilder.php';
class OpenSearchClient extends ElasticsearchClient
{
    protected $rawOsClient;

    public function __construct(array $options = [])
    {
        // Load environment variables if not already loaded
        if (!isset($_ENV['OS_HOST'])) {
            if (class_exists('Avisitor\\Env\\Loader')) {
                \Avisitor\Env\Loader::load(__DIR__ . '/../../../.env');
            }
        }

        // We don't call parent::__construct because it initializes the Elasticsearch client
        // Instead, we replicate the initialization logic for OpenSearch
        
        $DEFAULT_INDEX = 'hawaiian';
        $this->indexName = (isset($options['indexName']) && !empty($options['indexName'])) ? $options['indexName'] : $DEFAULT_INDEX;
        $this->sourceMetadataIndexName = $this->indexName . "_source_metadata";
        $this->verbose = $options['verbose'] ?? false;
        $this->quiet = $options['quiet'] ?? false;
        $this->splitIndices = $options['SPLIT_INDICES'] ?? true;
        $this->vectorDimensionsValidated = false;

        $apiKey = $options['apiKey']
            ?? $_ENV['OS_API_KEY'] ?? getenv('OS_API_KEY')
            ?? $_ENV['OPENSEARCH_API_KEY'] ?? getenv('OPENSEARCH_API_KEY')
            ?? $_ENV['API_KEY'] ?? getenv('API_KEY')
            ?? null;
        $bearerToken = $options['bearerToken']
            ?? $_ENV['OS_BEARER_TOKEN'] ?? getenv('OS_BEARER_TOKEN')
            ?? $_ENV['OPENSEARCH_BEARER_TOKEN'] ?? getenv('OPENSEARCH_BEARER_TOKEN')
            ?? null;
        $host = $_ENV['OS_HOST'] ?? $_ENV['ES_HOST'] ?? 'localhost';
        $port = $_ENV['OS_PORT'] ?? $_ENV['ES_PORT'] ?? 9200;
        $user = $options['username']
            ?? $_ENV['OS_USER'] ?? getenv('OS_USER')
            ?? $_ENV['ES_USER'] ?? getenv('ES_USER')
            ?? $_ENV['ELASTIC_USER'] ?? getenv('ELASTIC_USER')
            ?? null;
        $pass = $options['password']
            ?? $_ENV['OS_PASS'] ?? getenv('OS_PASS')
            ?? $_ENV['ES_PASS'] ?? getenv('ES_PASS')
            ?? $_ENV['ELASTIC_PASSWORD'] ?? getenv('ELASTIC_PASSWORD')
            ?? null;

        $builder = ClientBuilder::create()
            ->setHosts(["https://{$host}:{$port}"])
            ->setSSLVerification(false);

        $connectionParams = ['client' => ['headers' => []]];

        if ($user && $pass) {
            $builder->setBasicAuthentication(trim($user), trim($pass));
        } elseif ($bearerToken) {
            $connectionParams['client']['headers']['Authorization'] = 'Bearer ' . trim($bearerToken);
        } elseif ($apiKey) {
            $apiKey = trim($apiKey);
            if (strpos($apiKey, ':') !== false) {
                $apiKey = base64_encode($apiKey);
            }
            $connectionParams['client']['headers']['Authorization'] = 'ApiKey ' . $apiKey;
        }

        if (!empty($connectionParams['client']['headers'])) {
            $builder->setConnectionParams($connectionParams);
        }

        $osClient = $builder->build();

        // Wrap the OpenSearch client to provide compatibility with Elasticsearch v8 response objects
        $this->client = $this->wrapClient($osClient);
        $this->rawOsClient = $osClient;
            
        $this->embeddingClient = new EmbeddingClient();
        $this->queryBuilder = new OpenSearchQueryBuilder($this->embeddingClient);
        $this->grammarScanner = new \Noiiolelo\GrammarScanner();
    }

    public function search($query, string $mode = '', array $options = []): ?array
    {
        // We need to override search to ensure we don't use .keyword suffixes in OpenSearch
        // and to handle any other OS-specific search logic
        return parent::search($query, $mode, $options);
    }

    /**
     * Override createIndexFromMapping to handle OpenSearch specific mapping requirements
     */
    protected function createIndexFromMapping(string $indexName, string $mappingFile, bool $recreate = false): void
    {
        if (empty($indexName)) {
            return;
        }

        if (!file_exists($mappingFile)) {
            throw new \RuntimeException("Mapping file not found: $mappingFile");
        }

        $mapping = json_decode(file_get_contents($mappingFile), true);
        
        // Convert Elasticsearch dense_vector to OpenSearch knn_vector
        if (isset($mapping['mappings']['properties'])) {
            foreach ($mapping['mappings']['properties'] as $name => &$prop) {
                if (isset($prop['type']) && $prop['type'] === 'dense_vector') {
                    $prop['type'] = 'knn_vector';
                    $prop['dimension'] = $prop['dims'] ?? 384;
                    
                    // Handle 1024-dim vectors specifically if they appear in mapping
                    if (strpos($name, '1024') !== false) {
                        $prop['dimension'] = 1024;
                    }
                    
                    unset($prop['dims']);
                    
                    // OpenSearch uses method for KNN
                    $prop['method'] = [
                        'name' => 'hnsw',
                        'space_type' => 'cosinesimil',
                        'engine' => 'lucene'
                    ];
                    
                    // Enable KNN in settings
                    $mapping['settings']['index.knn'] = true;
                }
            }
        }

        if ($recreate && $this->indexExists($indexName)) {
            $this->deleteIndex($indexName);
            $this->print("Index '{$indexName}' deleted.");
        } elseif (!$recreate && $this->indexExists($indexName)) {
            $this->print("✓ Index $indexName already exists, skipping creation.");
            return;
        }

        // Use rawOsClient directly — the wrapped client silently swallows
        // indices()->create() calls on OpenSearch
        $result = $this->rawOsClient->indices()->create([
            'index' => $indexName,
            'body' => $mapping
        ]);
        if (is_object($result) && method_exists($result, 'wait')) {
            $result->wait();
        }
        $this->print("Created index: {$indexName}");
    }

    /**
     * Create the search pipeline required for hybrid search
     */
    public function createSearchPipeline(string $pipelineName = 'norm-pipeline'): void
    {
        $this->print("Creating search pipeline: $pipelineName");
        
        try {
            $this->rawOsClient->transport->performRequest(
                'PUT',
                "/_search/pipeline/$pipelineName",
                [],
                [
                    'description' => 'Post-processor for hybrid search',
                    'phase_results_processors' => [
                        [
                            'normalization-processor' => [
                                'normalization' => [
                                    'technique' => 'min_max'
                                ],
                                'combination' => [
                                    'technique' => 'arithmetic_mean',
                                    'parameters' => [
                                        'weights' => [0.3, 0.7]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            );
            $this->print("✓ Search pipeline $pipelineName created.");
        } catch (\Exception $e) {
            $this->print("⚠ Warning creating search pipeline: " . $e->getMessage());
        }
    }

    /**
     * Override createMetaIndex to remove Elasticsearch-specific parameters
     */
    protected function createMetaIndex($filename, $indexname): bool
    {
        $mappingConfig = $this->loadConfig($filename);
        $params = [
            'index' => $indexname,
            'body' => $mappingConfig
        ];
        
        // Use rawOsClient directly — the wrapped client silently swallows
        // indices()->create() calls on OpenSearch
        $result = $this->rawOsClient->indices()->create($params);
        if (is_object($result) && method_exists($result, 'wait')) {
            $result->wait();
        }
        $this->print("Index '{$indexname}' created in OpenSearch.");
        return true;
    }

    public function getRawOsClient(): \OpenSearch\Client
    {
        return $this->rawOsClient;
    }

    /**
     * Override getDocument to catch OpenSearch-specific exceptions (Missing404Exception)
     * that aren't covered by the parent's Elastic\Elasticsearch\ClientResponseException catch.
     */
    public function getDocument(string $id, string $indexName = null): ?array
    {
        $index = empty($indexName) ? $this->getIndexName() : $indexName;
        try {
            $response = $this->client->get([
                'index' => $index,
                'id'    => $id
            ])->asArray();

            return $response['_source'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getRawClient()
    {
        return $this->client;
    }

    /**
     * Override to return the raw OpenSearch client for low-level operations.
     * The wrapped client silently swallows indices/write calls.
     */
    public function getTransportClient()
    {
        return $this->rawOsClient;
    }

    /**
     * Override the wrapClient to also handle query rewriting
     */
    private function wrapClient($client)
    {
        $outer = $this;
        return new class($client, $outer) {
            private $inner;
            private $outer;
            public function __construct($inner, $outer) { 
                $this->inner = $inner; 
                $this->outer = $outer;
            }
            
            public function search($params) {
                $params = $this->outer->rewriteQuery($params);
                $res = $this->inner->search($params);
                return $this->wrapResponse($res);
            }

            public function __call($name, $args) {
                $res = call_user_func_array([$this->inner, $name], $args);
                return $this->wrapResponse($res);
            }

            public function indices() {
                return new class($this->inner->indices(), $this->outer) {
                    private $indices;
                    private $outer;
                    public function __construct($indices, $outer) { 
                        $this->indices = $indices; 
                        $this->outer = $outer;
                    }
                    public function __call($name, $args) {
                        $res = call_user_func_array([$this->indices, $name], $args);
                        return $this->wrapResponse($res);
                    }
                    private function wrapResponse($res) {
                        return new class($res) {
                            private $res;
                            public function __construct($res) { $this->res = $res; }
                            public function asArray() { return $this->res; }
                            public function asBool() { return (bool)$this->res; }
                            public function __toString() { return json_encode($this->res); }
                        };
                    }
                };
            }

            private function wrapResponse($res) {
                if (is_object($res) && method_exists($res, 'asArray')) {
                    return $res;
                }
                return new class($res) {
                    private $res;
                    public function __construct($res) { $this->res = $res; }
                    public function asArray() { return $this->res; }
                    public function asBool() { return (bool)$this->res; }
                    public function __toString() { return json_encode($this->res); }
                };
            }
        };
    }

    /**
     * Override deleteIndex to use raw HTTP transport for OpenSearch compatibility.
     * The OpenSearch PHP client's indices()->delete() silently fails.
     * Must call ->wait() on the FutureArray to ensure the delete completes.
     */
    public function deleteIndex(string $indexName): void
    {
        $result = $this->rawOsClient->transport->performRequest(
            'DELETE',
            "/{$indexName}",
            [],
            null
        );
        // The OpenSearch client returns a FutureArray — wait for it to complete
        if (is_object($result) && method_exists($result, 'wait')) {
            $result->wait();
        }
        $this->print("Index '{$indexName}' deleted.");
    }

    /**
     * Override indexExists to use rawOsClient directly
     */
    public function indexExists(string $indexName): bool {
        return $this->rawOsClient->indices()->exists(['index' => $indexName]);
    }

    /**
     * Override aliasExists to use rawOsClient directly
     */
    public function aliasExists(string $aliasName): bool
    {
        try {
            return $this->rawOsClient->indices()->existsAlias(['name' => $aliasName]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Override createAlias to use OpenSearch-compatible POST /_aliases API
     */
    public function createAlias(string $aliasName, string $indexName): void
    {
        if ($this->aliasExists($aliasName)) {
            $this->removeAlias($aliasName);
        }
        
        $this->rawOsClient->indices()->putAlias([
            'index' => $indexName,
            'name' => $aliasName
        ]);
        $this->print("Created alias '{$aliasName}' for index '{$indexName}'");
    }

    /**
     * Override removeAlias to use raw HTTP transport for OpenSearch compatibility
     */
    public function removeAlias(string $aliasName): void
    {
        if ($this->aliasExists($aliasName)) {
            try {
                // Use raw HTTP DELETE to remove alias from all indices
                $this->rawOsClient->transport->performRequest(
                    'DELETE',
                    "/_aliases/{$aliasName}",
                    [],
                    null
                );
                $this->print("Removed alias '{$aliasName}'");
            } catch (\Exception $e) {
                $this->print("Warning: Could not remove alias '{$aliasName}': " . $e->getMessage());
            }
        }
    }

    /**
     * Override createAliases to use rawOsClient directly for all operations
     */
    public function createAliases(): void
    {
        $this->print("Creating production aliases (OpenSearch)...");

        $aliases = [
            $this->getDocumentsAlias() => $this->getDocumentsIndexName(),
            $this->getSentencesAlias() => $this->getSentencesIndexName(),
        ];

        foreach ($aliases as $alias => $index) {
            if ($this->indexExists($index)) {
                $this->createAlias($alias, $index);
            } else {
                $this->print("Warning: index '{$index}' does not exist, skipping alias '{$alias}'");
            }
        }
    }

    /**
     * Recursively remove .keyword suffix from field names in the query
     */
    public function rewriteQuery(array $params): array
    {
        return $this->recursiveRemoveKeyword($params);
    }

    private function recursiveRemoveKeyword($item)
    {
        if (is_array($item)) {
            $newItem = [];
            foreach ($item as $key => $value) {
                $newKey = $key;
                if (is_string($key) && strpos($key, '.keyword') !== false) {
                    if (strpos($key, 'text.keyword') !== false) {
                        $newKey = str_replace('text.keyword', 'text.raw', $key);
                    } else {
                        $newKey = str_replace('.keyword', '', $key);
                    }
                }
                $newItem[$newKey] = $this->recursiveRemoveKeyword($value);
            }
            return $newItem;
        } elseif (is_string($item) && strpos($item, '.keyword') !== false) {
            if (strpos($item, 'text.keyword') !== false) {
                return str_replace('text.keyword', 'text.raw', $item);
            } else {
                return str_replace('.keyword', '', $item);
            }
        }
        return $item;
    }
}

<?php
namespace Noiiolelo\Providers\OpenSearch;

use Noiiolelo\Providers\Elasticsearch\ElasticsearchProvider;
use HawaiianSearch\OpenSearchClient;

class OpenSearchProvider extends ElasticsearchProvider {
    
    public function __construct( $options ) {
        $this->verbose = $options['verbose'] ?? false;
        // We override the client with OpenSearchClient
        // Note: We need to make sure the namespace for OpenSearchClient is correctly handled
        $this->client = new OpenSearchClient([
            'verbose' => $this->verbose,
            'quiet' => true,
        ]);
        // We can still use the same processing logger if it's compatible
        // ElasticsearchProcessingLogger uses the client's methods which we've wrapped
        $this->processingLogger = new \Noiiolelo\Providers\Elasticsearch\ElasticsearchProcessingLogger($this->client);
    }

    public function getName(): string {
        return "OpenSearch";
    }
}

<?php
namespace Noiiolelo\Providers\OpenSearch;

use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;
use HawaiianSearch\OpenSearchClient;

/**
 * OpenSearch ingestion: identical to the Elasticsearch save manager except
 * the client targets OpenSearch (OS_* env config, no ES API key).
 * Grammar patterns are computed at index time by the shared
 * ElasticsearchClient code, and pattern counts come from a live terms
 * aggregation — no extra bookkeeping needed here.
 */
class OpenSearchSaveManager extends ElasticsearchSaveManager
{
    protected function createClient(array $options): \HawaiianSearch\ElasticsearchClient
    {
        return new OpenSearchClient([
            'verbose' => $options['verbose'] ?? false,
            'SPLIT_INDICES' => true,
        ]);
    }
}

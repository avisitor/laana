<?php

namespace Noiiolelo;

use Noiiolelo\Providers\Elasticsearch\ElasticsearchProvider;
use Noiiolelo\Providers\OpenSearch\OpenSearchProvider;
use Noiiolelo\Providers\Postgres\PostgresProvider;
use Noiiolelo\Providers\MySQL\MySQLProvider;
use Noiiolelo\Providers\Neo4j\Neo4jProvider;

class ProviderFactory {
    public static function create(string $providerName, array $options = []): SearchProviderInterface {
        switch (strtolower($providerName)) {
            case 'elasticsearch':
            case 'es':
                return new ElasticsearchProvider($options);
            case 'opensearch':
            case 'os':
                return new OpenSearchProvider($options);
            case 'postgres':
            case 'pg':
                return new PostgresProvider($options);
            case 'mysql':
            case 'laana':
                return new MySQLProvider($options);
            case 'neo4j':
                return new Neo4jProvider($options);
            default:
                throw new \InvalidArgumentException("Unknown provider: $providerName");
        }
    }
}

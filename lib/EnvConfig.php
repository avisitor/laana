<?php

namespace Noiiolelo;

/**
 * Startup configuration validation.
 *
 * Policy: when a component starts up it must check that it has all of its
 * required configuration and FAIL LOUDLY if it does not, instead of silently
 * continuing with fallback defaults. Silent fallbacks caused a production
 * incident: OpenSearchClient fell back to ES_HOST/ES_PORT (and Elasticsearch's
 * API_KEY), pointing OpenSearch operations at the Elasticsearch cluster.
 *
 * Shared entry points that call these checks: ElasticsearchClient,
 * OpenSearchClient, Laana (MySQL), PostgresLaana, both EmbeddingClients, and
 * Neo4jProvider — each after loading .env in its own constructor/connect().
 */
final class EnvConfig
{
    private function __construct()
    {
    }

    /**
     * Return the first non-empty value among the candidate env keys, or throw
     * a RuntimeException naming every key that was checked. Values are trimmed.
     *
     * @param string   $what human-readable description used in the error message
     * @param string[] $keys candidate env keys, in priority order
     * @return string
     */
    public static function firstEnv(string $what, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value !== false && $value !== null && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        throw new \RuntimeException(
            'Missing required configuration (' . $what . '): none of '
            . implode(', ', $keys) . ' is set. Set it in .env or the environment.'
            . ' Refusing to start with fallback defaults.'
        );
    }
}

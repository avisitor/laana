<?php
namespace Noiiolelo;

use Noiiolelo\Providers\MySQL\MySQLSaveManager;
use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;
use Noiiolelo\Providers\Postgres\PostgresSaveManager;
use Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager;

/**
 * Dispatch for scripts/save.php: maps --provider to the right save manager.
 *
 * Supported backends: mysql (default), postgres, elasticsearch (es),
 * opensearch (os). Unknown providers THROW — save.php must never silently
 * fall back to MySQL (the pre-2026-09 behavior that made the daily driver's
 * postgres/opensearch passes write MySQL instead).
 */
class SaveManagerFactory
{
    public static function normalize(string $provider): string
    {
        $p = strtolower(trim($provider));
        if ($p === '') { return 'mysql'; }
        if ($p === 'es') { return 'elasticsearch'; }
        if ($p === 'os') { return 'opensearch'; }
        return $p;
    }

    /** Supported save backends, for help text and validation. */
    public static function supported(): array
    {
        return ['mysql', 'postgres', 'elasticsearch', 'opensearch'];
    }

    /**
     * @param string $provider raw --provider value ('' -> mysql default)
     * @throws \InvalidArgumentException on unsupported providers
     */
    public static function create(string $provider, array $options): object
    {
        $normalized = self::normalize($provider ?: 'mysql');
        switch ($normalized) {
            case 'mysql':         return new MySQLSaveManager($options);
            case 'postgres':      return new PostgresSaveManager($options);
            case 'elasticsearch': return new ElasticsearchSaveManager($options);
            case 'opensearch':    return new OpenSearchSaveManager($options);
            default:
                throw new \InvalidArgumentException(
                    "Unsupported save provider '{$provider}'. Supported: "
                    . implode(', ', self::supported())
                );
        }
    }
}

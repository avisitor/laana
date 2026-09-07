<?php

namespace Noiiolelo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case with shared configuration for all Noiiolelo tests
 */
abstract class BaseTestCase extends TestCase
{
    /**
     * List of valid provider names to test
     * Modify this array to add, change, or remove provider names in one place
     */
    protected static array $validProviders = [
        'MySQL',
        'Elasticsearch',
        'Postgres'
    ];

    /**
     * Get the list of valid provider names
     */
    protected function getValidProviders(): array
    {
        return self::$validProviders;
    }

    /**
     * Get the default provider name (first in list)
     */
    protected function getDefaultProvider(): string
    {
        return self::$validProviders[0];
    }

    /**
     * Check if a provider name is valid
     */
    protected function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::$validProviders);
    }

    /**
     * Skip the current test unless the provider's backend is reachable.
     *
     * For tests that do not go through getTestProvider() (direct client
     * construction, HTTP endpoint tests, ...): reports "Provider X was not
     * available" in the skip message so reports state the outage plainly.
     */
    protected function skipIfProviderUnavailable(string $provider): void
    {
        require_once __DIR__ . '/../lib/provider.php';
        if (!isProviderAvailable($provider)) {
            $this->markTestSkipped("Provider $provider was not available");
        }
    }
}

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
        'Laana',
        'Elasticsearch'
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
}

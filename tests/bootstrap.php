<?php
/**
 * PHPUnit Test Bootstrap
 * Initializes the testing environment for noiiolelo
 */

// Define constant to signal we're running under PHPUnit
// This can be used to suppress debug output in the application
define('PHPUNIT_RUNNING', true);

// Optionally redirect error_log to a file instead of stderr
// This keeps test output clean while preserving debug logs for inspection
ini_set('error_log', __DIR__ . '/../tests/debug.log');

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env through the canonical avisitor/env-loader package (replaces the
// previous app-local env-loader.php copy, which auto-ran loadEnv() on include).
\Avisitor\Env\Loader::load(__DIR__ . '/../.env');

// Set test environment variables
$_ENV['PROVIDER'] = 'MySQL'; // Default for tests
if (!getenv('NOIIOLELO_TEST_BASE_URL')) {
    throw new RuntimeException('NOIIOLELO_TEST_BASE_URL must be set for API tests.');
}

/**
 * Helper function to get test provider.
 *
 * Checks provider availability first: if the provider's backend cannot be
 * reached, the provider-specific test is skipped with a clear message
 * (surfaced in tests/reports as "Provider X was not available") instead of
 * erroring with a raw transport exception.
 */
function getTestProvider(string $providerName = null)
{
    if ($providerName !== null) {
        $_REQUEST['provider'] = $providerName;
    }
    try {
        $provider = getProvider($providerName);
    } catch (\Noiiolelo\ProviderUnavailableException $e) {
        $name = $providerName ?? $e->getProviderName();
        $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        \PHPUnit\Framework\Assert::markTestSkipped(
            "Provider $name was not available: $reason"
        );
    }
    // Laana-backed providers (MySQL, Postgres) report a failed connect() as a
    // null PDO rather than throwing; probe it as isProviderAvailable() does.
    $logger = $provider->getProcessingLogger();
    if ($logger instanceof \Laana && $logger->conn === null) {
        $name = $providerName ?? $provider->getName();
        \PHPUnit\Framework\Assert::markTestSkipped(
            "Provider $name was not available: database connection not established"
        );
    }
    return $provider;
}

/**
 * Helper function to reset request parameters
 */
function resetRequest()
{
    $_REQUEST = [];
    $_GET = [];
    $_POST = [];
}

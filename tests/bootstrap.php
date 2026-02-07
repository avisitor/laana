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

// Load existing .env handling
require_once __DIR__ . '/../env-loader.php';

// Set test environment variables
$_ENV['PROVIDER'] = 'MySQL'; // Default for tests
if (!getenv('NOIIOLELO_TEST_BASE_URL')) {
    throw new RuntimeException('NOIIOLELO_TEST_BASE_URL must be set for API tests.');
}

/**
 * Helper function to get test provider
 */
function getTestProvider(string $providerName = null)
{
    if ($providerName !== null) {
        $_REQUEST['provider'] = $providerName;
    }
    return getProvider($providerName);
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

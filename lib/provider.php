<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Noiiolelo\ProviderFactory;

function getKnownProviders(): array {
    return [
        'MySQL' => 'MySQL',
        'Elasticsearch' => 'Elasticsearch',
        'OpenSearch' => 'OpenSearch',
        'Postgres' => 'Postgres',
    ];
}

function isValidProvider(string $name): bool {
    $known = getKnownProviders();
    if (array_key_exists($name, $known) || $name === 'Laana') {
        return true;
    }

    // Case-insensitive check
    foreach ($known as $key => $value) {
        if (strtolower($name) === strtolower($key)) {
            return true;
        }
    }

    return strtolower($name) === 'laana';
}

/** Cookie that remembers the provider a visitor explicitly selected. */
function getProviderCookieName(): string {
    return 'noiiolelo_provider';
}

/**
 * Canonical provider name for this request, without constructing anything:
 * explicit argument > ?provider= parameter > remembered-provider cookie >
 * .env PROVIDER > MySQL. Unknown names log and fall back to MySQL
 * (web-side policy; CLI consumers of ProviderFactory exit loudly instead).
 */
function resolveProviderName($searchProvider = null): string {
    if ($searchProvider === null) {
        $searchProvider = $_REQUEST['provider'] ?? null;
    }

    if ($searchProvider === null) {
        $searchProvider = $_COOKIE[getProviderCookieName()] ?? null;
    }

    if ($searchProvider === null) {
        $env = \Avisitor\Env\Loader::load(__DIR__ . '/../.env');
        $searchProvider = $env['PROVIDER'] ?? null;
    }

    if ($searchProvider === null) {
        $searchProvider = 'MySQL';  // Default to MySQL if nothing else specified
    }

    $providers = getKnownProviders();

    // Try exact match first
    if (isset($providers[$searchProvider])) {
        return $providers[$searchProvider];
    }

    // Try case-insensitive match
    foreach ($providers as $key => $value) {
        if (strtolower($searchProvider) === strtolower($key)) {
            return $value;
        }
    }

    // Short aliases matching ProviderFactory's accepted names
    if (strtolower($searchProvider) === 'es') {
        return 'Elasticsearch';
    }
    if (strtolower($searchProvider) === 'os') {
        return 'OpenSearch';
    }
    if (strtolower($searchProvider) === 'laana') {
        return 'MySQL';
    }

    error_log("Unknown search provider requested: $searchProvider; falling back to MySQL");
    return 'MySQL';
}

/**
 * Persist a deliberate ?provider= selection in a cookie so other tabs and
 * later visits keep it. No-op when the request carries no (valid) provider
 * parameter, or when headers are already sent / not a web request.
 */
function rememberProviderSelection(): void {
    static $done = false;
    if ($done) {
        return;
    }
    if (!isset($_REQUEST['provider']) || !isValidProvider((string) $_REQUEST['provider'])) {
        return;
    }
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $done = true;
    setcookie(getProviderCookieName(), resolveProviderName(), [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
}

function getProvider($searchProvider = null) {
    $providerKey = resolveProviderName($searchProvider);

    // A provider named in the request is a deliberate selection: remember it
    // in a cookie so other tabs and later visits keep it. Precedence stays
    // request parameter > cookie > .env PROVIDER > MySQL.
    rememberProviderSelection();

    $options = [ 'verbose' => false ];

    // A backend that cannot be reached must surface as a typed, catchable
    // exception so web entry points can render a clear "provider unavailable"
    // response (lib/web_error.php) instead of a fatal error / blank screen.
    try {
        return ProviderFactory::create($providerKey, $options);
    } catch (\Throwable $e) {
        $message = "Search provider '$providerKey' is unavailable: " . $e->getMessage();
        \Avisitor\Monolog\Logger::logError("getProvider: $message");
        throw new \Noiiolelo\ProviderUnavailableException($providerKey, $message, $e);
    }
}

/**
 * Cheap liveness probe: "available" means the provider's backend is
 * reachable and initialized. Used by the web error page (to offer a
 * dropdown of providers that actually work) and by the test suite (to skip
 * provider-specific tests with a clear message).
 *
 * Construction alone is not proof for the Laana-backed providers (MySQL,
 * Postgres): their connect() reports failures as a null PDO instead of
 * throwing, so the connection itself is probed. The Elasticsearch/
 * OpenSearch constructors fail loudly on an unreachable backend, so
 * reaching here proves those.
 */
function isProviderAvailable(string $providerName): bool {
    try {
        $provider = getProvider($providerName);
        $logger = $provider->getProcessingLogger();
        if ($logger instanceof \Laana && $logger->conn === null) {
            return false;
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Names of the providers whose backends are currently reachable, in
 * getKnownProviders() order.
 */
function getAvailableProviders(): array {
    $available = [];
    foreach (getKnownProviders() as $name) {
        if (isProviderAvailable($name)) {
            $available[] = $name;
        }
    }
    return $available;
}

<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';

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

function getProvider($searchProvider = null) {
    // Priority: parameter > $_REQUEST > .env > default to MySQL
    if ($searchProvider === null) {
        $searchProvider = $_REQUEST['provider'] ?? null;
    }
    
    if ($searchProvider === null) {
        $env = loadEnv();
        $searchProvider = $env['PROVIDER'] ?? null;
    }
    
    if ($searchProvider === null) {
        $searchProvider = 'MySQL';  // Default to MySQL if no provider specified
    }
    
    $options = [ 'verbose' => false ];

    $providers = getKnownProviders();
    $providerKey = null;

    // Try exact match first
    if (isset($providers[$searchProvider])) {
        $providerKey = $providers[$searchProvider];
    } else {
        // Try case-insensitive match
        foreach ($providers as $key => $value) {
            if (strtolower($searchProvider) === strtolower($key)) {
                $providerKey = $value;
                break;
            }
        }
    }
    
    if ($providerKey === null && strtolower($searchProvider) === 'laana') {
        $providerKey = 'MySQL';
    }

    if ($providerKey === null) {
        $valid = implode(", ", array_keys($providers));
        throw new \Exception("Invalid search provider: $searchProvider. Must be one of $valid");
    }
    
    return ProviderFactory::create($providerKey, $options);
}

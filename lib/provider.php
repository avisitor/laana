<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';

function getKnownProviders(): array {
    return [
        'Laana' => [
            'file' => __DIR__ . '/LaanaSearchProvider.php',
            'class' => 'Noiiolelo\\LaanaSearchProvider',
        ],
        'Elasticsearch' => [
            'file' => __DIR__ . '/ElasticsearchProvider.php',
            'class' => 'Noiiolelo\\ElasticsearchProvider',
        ],
        'Postgres' => [
            'file' => __DIR__ . '/PostgresSearchProvider.php',
            'class' => 'Noiiolelo\\PostgresSearchProvider',
        ],
    ];
}

function isValidProvider(string $name): bool {
    return array_key_exists($name, getKnownProviders());
}

function getProvider($searchProvider = null) {
    // Priority: parameter > $_REQUEST > .env > default to Laana
    if ($searchProvider === null) {
        $searchProvider = $_REQUEST['provider'] ?? null;
    }
    
    if ($searchProvider === null) {
        $env = loadEnv();
        $searchProvider = $env['PROVIDER'] ?? null;
    }
    
    if ($searchProvider === null) {
        $searchProvider = 'Laana';  // Default to Laana if no provider specified
    }
    
    $options = [ 'verbose' => true ];

    $providers = getKnownProviders();
    if (!isset($providers[$searchProvider])) {
        $valid = implode(", ", array_keys($providers));
        throw new \Exception("Invalid search provider: $searchProvider. Must be one of $valid");
    }
    $meta = $providers[$searchProvider];
    require_once $meta['file'];
    $class = $meta['class'];
    $provider = new $class($options);
    
    return $provider;
}

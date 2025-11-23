<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';

function getProvider($searchProvider = null) {
    // Priority: parameter > $_REQUEST > .env
    if ($searchProvider === null) {
        $searchProvider = $_REQUEST['provider'] ?? null;
    }
    
    if ($searchProvider === null) {
        $env = loadEnv();
        $searchProvider = $env['PROVIDER'] ?? null;
    }
    
    if ($searchProvider === null) {
        throw new \Exception('No search provider specified in parameter, request, or .env file');
    }
    
    $options = [
        'verbose' => true,
    ];
    
    if ($searchProvider === 'Laana') {
        require_once __DIR__ . '/LaanaSearchProvider.php';
        $provider = new Noiiolelo\LaanaSearchProvider( $options );
    } else if ($searchProvider === 'Elasticsearch') {
        require_once __DIR__ . '/ElasticsearchProvider.php';
        $provider = new Noiiolelo\ElasticsearchProvider( $options );
    } else {
        throw new \Exception("Invalid search provider: $searchProvider. Must be 'Elasticsearch' or 'Laana'");
    }
    
    return $provider;
}

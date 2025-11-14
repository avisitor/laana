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
        $searchProvider = $env['PROVIDER'] ?? 'Laana';
    }
    
    $options = [
        'verbose' => true,
    ];
    if ($searchProvider === 'Laana') {
        require_once __DIR__ . '/LaanaSearchProvider.php';
        $provider = new Noiiolelo\LaanaSearchProvider( $options );
    } else {
        require_once __DIR__ . '/ElasticsearchProvider.php';
        $provider = new Noiiolelo\ElasticsearchProvider( $options );
    }
    return $provider;
}

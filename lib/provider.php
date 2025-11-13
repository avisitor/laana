<?php
require_once __DIR__ . '/../../vendor/autoload.php';

function getProvider( $searchProvider = 'Laana' /*'Elasticsearch'*/ ) {
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

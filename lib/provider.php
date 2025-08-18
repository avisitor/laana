<?php
require_once __DIR__ . '/../../vendor/autoload.php';

$searchProvider = 'Laana'; // Switch between 'Laana' and 'Elasticsearch'
$searchProvider = 'Elasticsearch';

if ($searchProvider === 'Laana') {
    require_once __DIR__ . '/LaanaSearchProvider.php';
    $provider = new NoiOlelo\LaanaSearchProvider();
} else {
    require_once __DIR__ . '/ElasticsearchProvider.php';
    $provider = new NoiOlelo\ElasticsearchProvider();
}

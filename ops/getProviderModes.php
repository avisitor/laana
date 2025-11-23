<?php
header('Content-Type: application/json');

$providerName = $_GET['provider'] ?? null;

if ($providerName === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Provider parameter is required']);
    exit;
}

if (!in_array($providerName, ['Elasticsearch', 'Laana'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid provider']);
    exit;
}

// Return search modes - MUST match the actual provider implementations
$modes = [];

if ($providerName === 'Elasticsearch') {
    // From ElasticsearchProvider::getAvailableSearchModes()
    $modes = [
        'match' => 'Match any of the words anywhere', 
        'matchall' => 'Match all words anywhere in sentences', 
        'phrase' => 'Match exact phrase in sentences',
        'regex' => 'Regular expression search',
        'hybrid' => 'Hybrid keyword + semantic search on sentences',
    ];
} else if ($providerName === 'Laana') {
    // From LaanaSearchProvider::getAvailableSearchModes()
    $modes = [
        'exact' => 'Exact match of one or more words',
        'any' => 'Match any of the words',
        'all' => 'Match all of the words in any order',
        'regex' => 'Regular expression match',
    ];
}

echo json_encode($modes);

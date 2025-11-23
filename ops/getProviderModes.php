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
        'phrase' => 'Match exact phrase',
        'match' => 'Match any of the words', 
        'matchall' => 'Match all words in any order', 
        'regex' => 'Regular expression search',
        'hybrid' => 'Hybrid keyword + semantic search',
    ];
} else if ($providerName === 'Laana') {
    // From LaanaSearchProvider::getAvailableSearchModes()
    $modes = [
        'exact' => 'Match exact phrase',
        'any' => 'Match any of the words',
        'all' => 'Match all words in any order',
        'regex' => 'Regular expression search',
    ];
}

echo json_encode($modes);

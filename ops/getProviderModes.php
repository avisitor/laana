<?php
header('Content-Type: application/json');

$providerName = $_GET['provider'] ?? null;

if ($providerName === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Provider parameter is required']);
    exit;
}

require_once __DIR__ . '/../lib/provider.php';
if (!isValidProvider($providerName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid provider']);
    exit;
}

// Normalize provider name for the switch/if below
if (strtolower($providerName) === 'laana') {
    $providerName = 'MySQL';
} else {
    $known = getKnownProviders();
    foreach ($known as $key => $value) {
        if (strtolower($providerName) === strtolower($key)) {
            $providerName = $value;
            break;
        }
    }
}

// Return search modes - MUST match the actual provider implementations
$modes = [];

if ($providerName === 'Elasticsearch') {
    // From ElasticsearchProvider::getAvailableSearchModes()
    $modes = [
        'match' => 'Match any of the words', 
        'matchall' => 'Match all words in any order', 
        'phrase' => 'Match exact phrase',
        'regex' => 'Regular expression search',
        'hybrid' => 'Hybrid semantic search on sentences',
        'hybriddoc' => 'Hybrid semantic search on documents',
    ];
} else if ($providerName === 'MySQL' || $providerName === 'Laana') {
    // From MySQLProvider::getAvailableSearchModes()
    $modes = [
        'exact' => 'Match exact phrase',
        'any' => 'Match any of the words',
        'all' => 'Match all words in any order',
        'regex' => 'Regular expression search',
    ];
} else if ($providerName === 'Postgres') {
    // From PostgresSearchProvider::getAvailableSearchModes()
    $modes = [
        'exact' => 'Match exact phrase',
        'any' => 'Match any of the words',
        'all' => 'Match all words in any order',
        'near' => 'Words adjacent in order',
        'regex' => 'Regular expression search',
        'hybrid' => 'Hybrid: keyword + vector + quality',
    ];
}

echo json_encode($modes) . "\n";

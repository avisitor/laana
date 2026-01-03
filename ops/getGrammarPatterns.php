<?php
require_once __DIR__ . '/../lib/provider.php';

header('Content-Type: application/json');

$providerName = $_REQUEST['provider'] ?? '';
$from = $_REQUEST['from'] ?? '';
$to = $_REQUEST['to'] ?? '';

try {
    $provider = $providerName ? getProvider($providerName) : getProvider();
    
    // Build options array for date filtering
    $options = [];
    if ($from) $options['from'] = $from;
    if ($to) $options['to'] = $to;
    
    $patterns = $provider->getGrammarPatterns($options);
    
    echo json_encode($patterns);
    error_log("getGrammarPatterns.php: Returned " . count($patterns) . " patterns for provider '" . $provider->getName() . "'");
    
} catch (Exception $e) {
    error_log("Error in getGrammarPatterns.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

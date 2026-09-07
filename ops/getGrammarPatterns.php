<?php
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/web_error.php';

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
    \Avisitor\Monolog\Logger::logError("getGrammarPatterns.php: Returned " . count($patterns) . " patterns for provider '" . $provider->getName() . "'");
    
} catch (\Throwable $e) {
    // 503 + friendly message for a provider outage, 500 otherwise; the raw
    // error detail goes to the log, not the response body.
    noiiolelo_render_error('json', $e);
}

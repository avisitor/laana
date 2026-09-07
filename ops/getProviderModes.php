<?php
header('Content-Type: application/json');

$providerName = $_GET['provider'] ?? null;

if ($providerName === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Provider parameter is required']);
    exit;
}

require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/web_error.php';
if (!isValidProvider($providerName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid provider']);
    exit;
}
try {
    $modes = getProvider($providerName)->getAvailableSearchModes();
} catch (\Throwable $e) {
    noiiolelo_render_error('json', $e);  // exits with a clear 503/500 JSON error
}
echo json_encode($modes) . "\n";

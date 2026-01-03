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
$modes = getProvider($providerName)->getAvailableSearchModes();
echo json_encode($modes) . "\n";

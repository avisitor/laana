<?php
use Noiiolelo\ProviderFactory;

require_once __DIR__ . '/../lib/provider.php';

$providerName = $_GET['provider'] ?? null;

if ($providerName === null) {
    http_response_code(400);
    echo "Provider parameter is required";
    exit;
}

if (!isValidProvider($providerName)) {
    http_response_code(400);
    echo "Invalid provider";
    exit;
}

// Normalize provider name
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

$provider = ProviderFactory::create($providerName);
echo \Noiiolelo\ProviderHelp::getSearchOptionsHtml($provider, "Search options ({$provider->getName()} Provider)");

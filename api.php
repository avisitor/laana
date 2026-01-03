<?php
// REST API for sources
//require_once __DIR__ . '/db/funcs.php';
require_once __DIR__ . '/lib/provider.php';

header('Content-Type: application/json');

// Parse query string properly to handle provider parameter
// This handles cases where REQUEST_URI might have the query string embedded
$queryString = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
if ($queryString) {
    parse_str($queryString, $queryParams);
    // Merge with existing REQUEST parameters, with parsed params taking precedence
    $_REQUEST = array_merge($_REQUEST, $queryParams);
    $_GET = array_merge($_GET, $queryParams);
}

// Get provider from URL parameter, default to 'MySQL'
$providerName = isset($_REQUEST['provider']) ? $_REQUEST['provider'] : 'MySQL';
if (!isValidProvider($providerName)) {
    error_response('Invalid provider. Must be one of: ' . implode(', ', array_keys(getKnownProviders())), 400);
}
$provider = getProvider($providerName);
$method = $_SERVER['REQUEST_METHOD'];

// Improved path parsing to handle rewrites and subdirectories
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME']; // e.g., /noiiolelo/api.php

// If called via rewrite (e.g., /noiiolelo/api/providers), PATH_INFO might be set
$path = $_SERVER['PATH_INFO'] ?? '';

if (!$path) {
    // Fallback: look for /api/ in the URI and take everything after it
    $apiPos = strpos($requestUri, '/api/');
    if ($apiPos !== false) {
        $path = substr($requestUri, $apiPos + 5);
    } else {
        $path = str_replace($scriptName, '', $requestUri);
    }
}

$path = trim($path, '/');
$parts = explode('/', $path);

function error_response($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($parts[0] === 'providers') {
    echo json_encode(array_values(getKnownProviders()));
    exit;
}

if ($parts[0] === 'sources') {
    // GET /sources or /sources?group=...
    //if ($method !== 'GET') error_response('Method not allowed', 405);
    $group = isset($_REQUEST['group']) ? $_REQUEST['group'] : '';
    $properties = isset($_REQUEST['properties']) ? $_REQUEST['properties'] : '';
    $details = isset($_REQUEST['details']);
    if( $details ) {
        $properties = ($properties) ? explode( ",", $properties ) : [];
        $result = ['sources' => $provider->getSources($group, $properties)];
    } else {
        $result = ['sourceids' => $provider->getSourceIDs($group)];
    }
    echo json_encode($result);
    exit;
}
if ($parts[0] === 'source' && isset($parts[1])) {
    $sourceid = $parts[1];
    if (!preg_match('/^\d+$/', $sourceid)) error_response('Invalid sourceid', 400);
    if (!isset($parts[2])) {
        // GET /source/{sourceid}
        //if ($method !== 'GET') error_response('Method not allowed', 405);
        $info = $provider->getSource($sourceid);
        if (!$info) error_response('Source not found', 404);
        echo json_encode($info);
        exit;
    } elseif ($parts[2] === 'html') {
        // GET /source/{sourceid}/html
        //if ($method !== 'GET') error_response('Method not allowed', 405);
        $html = $provider->getRawText($sourceid);
        if ($html === null) error_response('Source HTML not found', 404);
        echo json_encode(['html' => $html]);
        exit;
    } elseif ($parts[2] === 'plain') {
        // GET /source/{sourceid}/plain
        //if ($method !== 'GET') error_response('Method not allowed', 405);
        $text = $provider->getText($sourceid);
        if ($text === null) error_response('Source text not found', 404);
        echo json_encode(['text' => $text]);
        exit;
    } elseif ($parts[2] === 'sentences') {
        // GET /source/{sourceid}/sentences
        //if ($method !== 'GET') error_response('Method not allowed', 405);
        $text = $provider->getSentencesBySourceID($sourceid);
        if ($text === null || count($text) < 1) error_response('Source sentences not found', 404);
        echo json_encode($text);
        exit;
    } else {
        error_response('Unknown endpoint', 404);
    }
}
if ($parts[0] === 'search') {
    $query = $_REQUEST['query'] ?? '';
    $mode = $_REQUEST['mode'] ?? '';
    $limit = $_REQUEST['limit'] ?? 10;
    $offset = $_REQUEST['offset'] ?? 0;
    //echo "query=$query, mode=$mode, limit=$limit, offset=$offset, provider=$providerName\n";

    $result = ['sources' => $provider->search($query, $mode, $limit, $offset)];
    echo json_encode($result);
    exit;
}
error_response('Unknown endpoint', 404);
?>


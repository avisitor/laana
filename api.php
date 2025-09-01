<?php
// REST API for sources
//require_once __DIR__ . '/db/funcs.php';
require_once __DIR__ . '/lib/provider.php';

header('Content-Type: application/json');

$provider = getProvider( 'Laana' );
#$provider = getProvider( 'Elasticsearch' );
$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : (isset($_GET['path']) ? $_GET['path'] : '');
if (!$path) {
    $path = str_replace('/api.php', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
}
$path = trim($path, '/');
$parts = explode('/', $path);

function error_response($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
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
error_response('Unknown endpoint', 404);
?>


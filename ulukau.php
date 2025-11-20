<?php
/**
 * Ulukau Document Retrieval Endpoint
 * 
 * Accepts a full Ulukau URL, extracts the OID, and uses ulukau.js to scrape the document content.
 * Uses the same approach as ulukaupages.php - passthru with cd to script directory.
 */

$basedir = __DIR__ . '/db/ulukau';
$nodescript = "$basedir/ulukau.js";

// Set a long timeout since puppeteer can take time
set_time_limit(300);

// Get and validate URL parameter
if (!isset($_GET['url']) || empty($_GET['url'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameter: url']);
    exit;
}

$url = $_GET['url'];

// Extract OID from URL (pattern: d=<OID>&)
if (!preg_match('/[?&]d=([^&]+)/', $url, $matches)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not extract OID from URL. Expected format: ?d=<OID>&']);
    exit;
}

$oid = $matches[1];

// Validate OID format (alphanumeric, hyphens, underscores)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $oid)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid OID format']);
    exit;
}
$oid_arg = " --oid=" . escapeshellarg($oid);

if( false ) {
header('X-Accel-Buffering: no'); // Disable nginx buffering for streaming
header("Content-Type: text/html; charset=utf-8");
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();
$fp = fopen("http://localhost:3000/doc?oid=$oid", "r");
if (!$fp) {
    die("Error: could not connect to service");
}
fpassthru($fp);   // streams directly to browser
fclose($fp);
} else {
header('Content-Type: application/json');
header('X-Accel-Buffering: no'); // Disable nginx buffering for streaming

// Stream output directly to client
/*
$cmd = "cd " . escapeshellarg($basedir) . " && /usr/bin/node " . escapeshellarg($nodescript) . " --quiet" . $oid_arg . " 2>&1";
passthru($cmd, $return_value);
*/

// URL of your Node service
$url = "http://localhost:3000/doc?oid=$oid&json=true";

// Fetch the JSON response
$response = file_get_contents($url);
if ($response === false) {
    die("Error: could not contact service");
}

// Decode JSON into an associative array
$data = json_decode($response, true);
if ($data === null) {
    die("Error: invalid JSON returned");
}

// Print only the HTML portion
echo $data['html'];
}
?>

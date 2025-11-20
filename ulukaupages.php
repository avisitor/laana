<?php
/*
echo "<h2>Hawaiian Language Documents at Ulukau</h2>\n";
require_once( 'db/parsehtml.php' );
$parser = new UlukauHtml();
$pageextract = "extractUlukau";
require_once( 'pagelist.php' );
 */
$basedir = __DIR__ . '/db/ulukau';
$nodescript = "$basedir/ulukau-metadata.js";

// Set a long timeout since the script makes many network requests
set_time_limit(300);

// Execute node script with optional oid or limit parameter
$oid = isset($_GET['oid']) ? trim($_GET['oid']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;

$oid_arg = $oid ? " --oid=" . escapeshellarg($oid) : "";
$limit_arg = (!$oid && $limit > 0) ? " --limit=" . escapeshellarg($limit) : "";

header('Content-Type: application/json');
header('X-Accel-Buffering: no'); // Disable nginx buffering for streaming

// Stream output directly to client
$cmd = "cd " . escapeshellarg($basedir) . " && /usr/bin/node " . escapeshellarg($nodescript) . " --quiet" . $oid_arg . $limit_arg . " 2>&1";
passthru($cmd, $return_value);

if ($return_value !== 0) {
    error_log("ulukaupages.php: Script failed with exit code $return_value");
    http_response_code(500);
    echo json_encode(['error' => 'Script execution failed', 'exit_code' => $return_value]);
}

?>


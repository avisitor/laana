<?php
require_once __DIR__ . '/lib/provider.php';
require_once __DIR__ . '/lib/web_error.php';
try {
    $providerName = isset($_REQUEST['provider']) ? $_REQUEST['provider'] : getProvider()->getName();
    $provider = getProvider( $providerName );
} catch (\Throwable $e) {
    noiiolelo_render_error('json', $e);  // exits with a clear 503/500 JSON error
}
$rows = $provider->getSourceGroupCounts();
//\Avisitor\Monolog\Logger::logError( "groupcounts from $providerName: " . var_export( $rows, true ) );
echo json_encode( $rows );
?>

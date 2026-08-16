<?php
require_once __DIR__ . '/lib/provider.php';
$providerName = isset($_REQUEST['provider']) ? $_REQUEST['provider'] : getProvider()->getName();
$provider = getProvider( $providerName );
$rows = $provider->getSourceGroupCounts();
//\Avisitor\Monolog\Logger::logError( "groupcounts from $providerName: " . var_export( $rows, true ) );
echo json_encode( $rows );
?>

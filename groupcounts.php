<?php
require_once __DIR__ . '/lib/provider.php';
$provider = getProvider();

$rows = $provider->getSourceGroupCounts();
echo json_encode( $rows );
?>

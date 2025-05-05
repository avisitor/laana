<?php
$dir = dirname(__DIR__);
require_once 'db/funcs.php';

$laana = new Laana();

$rows = $laana->getSourceGroupCounts();
echo json_encode( $rows );
?>

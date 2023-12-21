<?php
include '../db/funcs.php';
//header('Content-Type: text/plain; charset=utf-8');
$search = $_POST['search'];
$pattern = $_POST['searchpattern'];
$count = $_POST['count'];
$order = $_POST['order'];
$elapsed = $_POST['elapsed'];
echo "Recording - search: $search, pattern: $pattern, count: $count, order: $order, elapsed: $elapsed\n";
$laana = new Laana();
$laana->addSearchStat( $search, $pattern, $count, $order, $elapsed );
?>

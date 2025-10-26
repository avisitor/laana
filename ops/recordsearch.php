<?php
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/utils.php';

$search = $_POST['search'];
$searchpattern = $_POST['searchpattern'];
$count = $_POST['count'];
$order = $_POST['order'];
$elapsed = $_POST['elapsed'];
if( $search && $searchpattern && isset($count) ) {
    $provider = getProvider();
    $provider->logQuery( [
        'searchterm' => $search,
        'pattern' => $searchpattern,
        'results' => $count,
        'sort' => $order,
        'elapsed' => $elapsed,
    ] );
    echo "Search recorded";
} else {
    echo "Missing parameters";
}
?>

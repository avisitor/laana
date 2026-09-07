<?php
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/web_error.php';
require_once __DIR__ . '/../lib/utils.php';

$search = $_POST['search'];
$searchpattern = $_POST['searchpattern'];
$count = $_POST['count'];
$order = $_POST['order'];
$elapsed = $_POST['elapsed'];
$providerName = $_POST['provider'] ?? null;

if( $search && $searchpattern && isset($count) ) {
    try {
        $provider = getProvider($providerName);
    } catch (\Throwable $e) {
        noiiolelo_render_error('text', $e);  // plain-text error, then exit
    }
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

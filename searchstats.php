<!DOCTYPE html>
<html lang="en" class="">
    <head>
<?php include 'common-head.html'; ?>
<title>Noiʻiʻōlelo searches</title>
<style>
body {
    padding: .2em;
}
</style>
<script>
	$(document).ready(function () {
        $('#table').DataTable({
            paging: false,
            order: [[ 3, "desc" ]],
            ordering: true,
        }
        );
	});
</script>
</head>
<?php
include 'db/funcs.php';
function changeTimeZone( $date ) {
    $datetime = new DateTime( $date );
    $newtime = new DateTimeZone('Pacific/Honolulu');
    $datetime->setTimezone($newtime);
    $date = $datetime->format('Y-m-d H:i:s');
    return $date;
}
$laana = new Laana();
$rows = $laana->getSearchStats();
$stats = $laana->getSummarySearchStats();
$total = 0;
foreach( $stats as $stat ) {
    $total += $stat['count'];
}
$first = changeTimeZone( $laana->getFirstSearchTime() );
?>
<body>
    <h2>Searches on Noiʻiʻōlelo since <?=$first?></h2>
    <div style="padding:1em;">
        <h3>Summary</h3>
        <table>
            <thead>
                <tr><th style="width:6em">Type</th><th>Count</th></tr>
            </thead>
            <tbody>
<?php
foreach( $stats as $row ) {
    echo "<tr><td>{$row['pattern']}</td><td>{$row['count']}</td></tr>\n";
}
?>
            </tbody>
        </table>
        <h6 style="margin-top:.5em;">Total: <?=$total?></h>
    </div>
    <div class="sentences">
        <table id="table">
            <thead><tr><th>Search term</th><th>Type</th><th>Results</th><th>Time</th></tr></thead>
            <tbody>

<?php
foreach( $rows as $row ) {
    echo "<tr><td>{$row['searchterm']}</td><td>{$row['pattern']}</td><td>{$row['results']}</td><td>" . changeTimeZone( $row['created'] ) . "</td></tr>\n";
}
?>
            <tbody>
        </table>
    </div></body>
</html>

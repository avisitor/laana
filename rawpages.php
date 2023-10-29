<?php
include 'db/parsehtml.php';

$sql = "select sources.sourceid,sources.sourcename from sources,contents where sources.sourceid=contents.sourceid and not html is null order by sources.sourcename";
$db = new DB();
$rows = $db->getDBRows( $sql );
?>
<html>
    <head>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" type="text/css" href="static/main.css">
        <style>
            body {
                padding: 1em;
            }
        </style>
    </head> 
    <body>
        <h2>Ulukau documents</h2>
        <ul>

<?php
foreach( $rows as $row ) {
    $title = $row['sourcename'];
    $sourceid = $row['sourceid'];
    $link = "rawpage?id=$sourceid";
    $item = "<li><a href='$link'>$title</a></li>";
    echo "$item\n";
}
?>
</ul>
</body>
</html>


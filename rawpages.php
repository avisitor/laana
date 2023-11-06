<?php
include 'db/parsehtml.php';

$sql = "select sources.sourceid,sources.sourcename,sources.link from sources,contents where sources.sourceid=contents.sourceid and not html is null order by sources.sourcename";
$db = new DB();
$rows = $db->getDBRows( $sql );
$count = sizeof( $rows );
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
        <h2>Stored Documents</h2>
        <h5><?=$count?> files</h5>
        <ul>

            <?php
            foreach( $rows as $row ) {
                $title = $row['sourcename'];
                $sourceid = $row['sourceid'];
                $link = "rawpage?id=$sourceid";
                $link2 = $link . "&simplified";
                $source = $row['link'];
                $item = "<li>$title<br/>" .
                        "<a href='$link' target='_blank'>HTML</a>&nbsp;|&nbsp;" .
                        "<a href='$link2' target='_blank'>Simplified</a>&nbsp;|&nbsp;" .
                        "<a href='$source' target='_blank'>Source</a>" .
                        "</li>";
                echo "$item\n";
            }
            ?>
</ul>
</body>
</html>


<?php
include 'db/parsehtml.php';

$sql = "select sources.sourceid,sources.sourcename,sources.link from sources,contents where sources.sourceid=contents.sourceid and not html is null order by sources.sourcename";
$db = new DB();
$rows = $db->getDBRows( $sql );
$count = sizeof( $rows );
?>
<html>
    <head>
        <?php include 'common-head.html'; ?>
        <title>Saved Noiiolelo Documents</title>
        <style>
         body {
             padding: 1em;
             color: white;
             background-image: linear-gradient(to bottom, rgba(50,50,255,0.9), rgba(80,151,255,1));
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


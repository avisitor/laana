<?php
$base = preg_replace( '/\?.*/', '', $_SERVER["REQUEST_URI"] );
$sourceName = $parser->getSourceName();
$pages = $parser->getPageList();
?>
<!DOCTYPE html>
<html lang="en" class="">
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      
      <title><?=$sourceName?> articles</title>
      
     <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/css/bootstrap.min.css" rel="stylesheet">
      
      <link rel="stylesheet" type="text/css" href="./static/main.css">
      <link rel="stylesheet" type="text/css" href="./static/fonts.css">
      <!-- Icons-->
      <link rel="apple-touch-icon" sizes="180x180" href="./static/icons/180.png">
      <link rel="icon" type="image/png" sizes="32x32" href="./static/icons/32.png">
      <link rel="icon" type="image/png" sizes="16x16" href="./static/icons/16.png">

     <style>
     body {
    padding: 0.5em;
 }
     </style>
   </head>
   <body>
     <h1><?=$sourceName?> articles</h1>
     <ul>
     <?php
     foreach( $pages as $page ) {
         $keys = array_keys( $page );
         $title = $keys[0];
         $link = $page[$title];
         $getsentences = "extractCB?url=$link";
     ?>
         <li><a href="<?=$link?>"><?=$title?></a><br><a href="<?=$getsentences?>">Parse it</a></li><br />
     <?php
     }
     ?>
     </ul>
   </body>
</html>

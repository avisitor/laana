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
      
      <title><?=$sourceName?></title>
      
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
     .box {
        display: flex;
        flex-flow: row wrap;
      }
      .item {
         max-width:13em;
         text-align: center;
         margin: 1em;
      }
      .parselink {
         border: 1px solid black;
         padding: .1em;
      }
      .button {
      border-top: 1px solid #96d1f8;
      background: #65a9d7;
      background: -webkit-gradient(linear, left top, left bottom, from(#3e779d), to(#65a9d7));
      background: -webkit-linear-gradient(top, #3e779d, #65a9d7);
      background: -moz-linear-gradient(top, #3e779d, #65a9d7);
      background: -ms-linear-gradient(top, #3e779d, #65a9d7);
      background: -o-linear-gradient(top, #3e779d, #65a9d7);
      padding: 5px 10px;
      -webkit-border-radius: 8px;
      -moz-border-radius: 8px;
      border-radius: 8px;
      -webkit-box-shadow: rgba(0,0,0,1) 0 1px 0;
      -moz-box-shadow: rgba(0,0,0,1) 0 1px 0;
      box-shadow: rgba(0,0,0,1) 0 1px 0;
      text-shadow: rgba(0,0,0,.4) 0 1px 0;
      color: white;
      font-size: 14px;
      font-family: Georgia, serif;
      text-decoration: none;
      vertical-align: middle;
      }
   .button:hover {
      border-top-color: #28597a;
      background: #28597a;
      color: #ccc;
      }
   .button:active {
      border-top-color: #1b435e;
      background: #1b435e;
      }
   </style>
   </head>
   <body>
     <h1 style='text-align:center;'><?=$sourceName?></h1>
     <div class='box'>
     <?php
     foreach( $pages as $page ) {
         $keys = array_keys( $page );
         $title = $keys[0];
         $item = $page[$title];
         $link = $item['url'];
         $image = $item['image'];
         if( $image ) {
            $image = "<img src='$image' style='max-height:150px;'/><br />";
         }
         $getsentences = "$pageextract?url=" . urlencode($link) . "&title=" . urlencode($title);
     ?>
         <!-- <tr><td style='width:100%;text-align:center;padding-bottom:1em;'><?=$image?><a href="<?=$link?>"><?=$title?></a><br><a href="<?=$getsentences?>">Parse it</td></tr> -->
         <div class='item'><?=$image?><a href="<?=$link?>"><?=$title?></a><br><span ><a class="button" href="<?=$getsentences?>">Parse it</a></span></div>
     <?php
     }
     ?>
     </div>
   </body>
</html>

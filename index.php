<?php
include 'db/funcs.php';
$word = $_GET['search'];
$normalizedWord = normalizeString( $word );
$pattern = $_GET['searchpattern'];
if( !$pattern ) {
    if ($word == $normalizedWord) {
        $pattern = 'any';
    } else {
        $pattern = 'exact';
    }
}
$laana = new Laana();
debuglog( "pattern: $pattern; word: $word" );
$base = preg_replace( '/\?.*/', '', $_SERVER["REQUEST_URI"] );
//error_log( var_export( $_SERVER, true ) );
?>
<!DOCTYPE html>
<html lang="en" class="">
   <head>
    <meta property="og:image" itemprop="image primaryImageOfPage" content="./static/images/previewimage.jpg">
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <!-- Sorry! I promise the tracker is so I can make the website better! -->
<!--
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-0FCELMK3D7"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-0FCELMK3D7');
    </script>
-->
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      
      <title><?=$word?> - Laʻana!</title>
      <meta name="description" content="Hawaiian dictionary search for malie">
      
      <link rel="stylesheet" type="text/css" href="./static/main.css">
          <!--
      <link rel="stylesheet" type="text/css" href="./static/fonts.css">
          -->
      <!-- Icons-->
      <link rel="apple-touch-icon" sizes="180x180" href="./static/icons/180.png">
      <link rel="icon" type="image/png" sizes="32x32" href="./static/icons/32.png">
      <link rel="icon" type="image/png" sizes="16x16" href="./static/icons/16.png">

     <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
      <script src="https://unpkg.com/infinite-scroll@4/dist/infinite-scroll.pkgd.js"></script>
      <script type="text/javascript" src="./static/helpers.js"></script>

<script>
  function changeid() {
    var theBody = document.getElementById("fadein")
    if (window.location.pathname=='/') {
      theBody.style.opacity='1'
    }
    else {
      theBody.id='nofadein'
    }
  }
</script>

<head>
  <div class="headerlinks">
    <a href="?about"><button class="linkbutton" type="button">About</button></a>
    <a href="?sources"><button class="linkbutton" type="button">Sources</button></a>
  </div>
</head>

   <body id=fadein onload="changeid()">
       <a href="<?=$base?>" class="nostyle">
         <div class="titletext">
           <center>
             <h1><font size="7">Laʻana!</font></h1>
             <p>Hawaiian Example Sentences</p>
           </center>
         </div>
       </a>
        
       <center>
         <form method="get">
          <input type="hidden" id="search-pattern" name="searchpattern" value="<?=$pattern?>" />
            <div class="search-bar">
                <input name="search" id="searchbar" type="text" size="40" style="width:40em;" placeholder="Enter anything in Hawaiian!" value="<?=$word?>" required />
                <button type="submit" class="search-button">
                    <i>Go!</i>
                </button>
            </div>
         </form>

		<button type="button" class="dd-button" onclick="displaySearchOptions()">Search Options</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ā')">ā</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ē')">ē</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ī')">ī</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ō')">ō</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ū')">ū</button>
        <button class="character-insert-button" type="button" onclick="insertcharacter('ʻ')">ʻ</button>
        <div id="search-options" style="display:none;">
			<ul class="dd-menu">
          <!--
			    <li class="character-insert-button" onclick="insertcharacter(' #minlength ')">Minimum Length (number)</li>
			    <li class="character-insert-button" onclick="insertcharacter(' #maxlength ')">Maximum Length (number)</li>
          -->
          <li>Match type:&nbsp;&nbsp;
          <label for="exact">Exact</label>
          <input id="exact" type="radio" name="pattern" value="Exact" onclick="setPattern('exact')"/>
          &nbsp;&nbsp;
          <label for="order">Order</label>
          <input id="order" type="radio" name="pattern" value="Order"  onclick="setPattern('order')"/>
          &nbsp;&nbsp;
          <label for="any">Any</label>
          <input id="any" type="radio" name="pattern" value="Any"  onclick="setPattern('any')"/>
          &nbsp;&nbsp;
          <label for="regex">Regex</label>
          <input id="regex" type="radio" name="pattern" value="Regex"  onclick="setPattern('regex')"/>
          </li>
			</ul>
		</div>
    </center>
    <script>
     let el = document.getElementById('<?=$pattern?>');
     if( el ) {
         el.checked = true;
     }
     <?php
     if( $word ) {
         if( $pattern == 'regex' ) {
             $word = urlencode( $word );
         }
     ?>
         $(document).ready(function() {
             var pageNumber = 0;
             let $container = $('.sentences').infiniteScroll({
                 path: 'ops/getPageHtml.php?word=<?=$word?>&pattern=<?=$pattern?>&page={{#}}',
                 history: false,
                 prefill: true,
                 debug: true,
                 //responseBody: 'json',
                 append: 'div.hawaiiansentence',
             });
         });
     <?php
     }
     ?>
    </script>
     <div class="sentences">
<?php

    if( !$word ) {
        if( isset( $_GET['sources'] ) ) {
            $rows = $laana->getSources();
            //var_export( $rows );
            foreach( $rows as $row ) {
                $source = $row['sourcename'];
                $authors = $row['authors'];
                $link = $row['link'];
                $sourcelink = "<a href='$link' target='_blank'>$source</a>";
                $count = $row['count'];
 ?>
          <div class="hawaiiansentence">
              <p class='title'><?=$sourcelink?></p>
              <p class='authors'>Authors: <?=$authors?></p>
          </div>
          <div class="engsentence">
              <p class='source'><?=$count?> sentences</p>
          </div>
          <hr class='sources'>
<?php
}
} else {
    if( isset( $_GET['about'] ) ) {
        include 'about.html';
    } else {
        $sentenceCount = number_format($laana->getSentenceCount());
        $sourceCount = number_format($laana->getSourceCount());
            include 'overview.html';
        }
        }
        }
?>
          </div>
        
   </body>
</html>

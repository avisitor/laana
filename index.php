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
error_log( "pattern: $pattern" );
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
      <link rel="stylesheet" type="text/css" href="./static/fonts.css">
      <!-- Icons-->
      <link rel="apple-touch-icon" sizes="180x180" href="./static/icons/180.png">
      <link rel="icon" type="image/png" sizes="32x32" href="./static/icons/32.png">
      <link rel="icon" type="image/png" sizes="16x16" href="./static/icons/16.png">

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
          <input type="hidden" id="search-pattern" name="searchpattern" />
            <div class="search-bar">
                <input name="search" id="searchbar" type="text" placeholder="Enter anything in Hawaiian!" value="<?=$word?>" required />
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
			    <li class="character-insert-button" onclick="insertcharacter(' #minlength ')">Minimum Length (number)</li>
			    <li class="character-insert-button" onclick="insertcharacter(' #maxlength ')">Maximum Length (number)</li>
          <li>Match type:&nbsp;&nbsp;
          <label for="exact">Exact</label>
          <input id="exact" type="radio" name="pattern" value="Exact" onclick="setPattern('exact')"/>
          &nbsp;&nbsp;
          <label for="order">Order</label>
          <input id="order" type="radio" name="pattern" value="Order"  onclick="setPattern('order')"/>
          &nbsp;&nbsp;
          <label for="any">Any</label>
          <input id="any" type="radio" name="pattern" value="Any"  onclick="setPattern('any')"/>
          </li>
			</ul>
		</div>
    </center>
    <script>
          document.getElementById('<?=$pattern?>').checked = true;
    </script>
    <div class="sentences">

<?php
    if( isset( $_GET['sources'] ) ) {
        $rows = $laana->getSources();
    } else if( $word ) {
        $rows = $laana->getSentences( $word, $pattern );
    }

    //var_export( $rows );
    foreach( $rows as $row ) {
        $source = $row['sourcename'];
        $authors = $row['authors'];
        if( isset( $_GET['sources'] ) ) {
            $count = $row['count'];
            $link = $row['link'];
            ?>
            <div class="hawaiiansentence">
              <p><a href="<?=$link?>" target="_blank"><?=$source?></a></p>
              <p>Authors: <?=$authors?></p>
            </div>
            <div class="engsentence">
              <p><?=$count?> sentences.</p>
            </div>
            <hr>

<?php
        } else {
            $sentence = $row['hawaiiantext'];
            $result = "";
            $target = ($pattern == 'exact') ? $word : $normalizedWord;
            $tw = $target;
            $targetwords = preg_split( "/[\s]+/",  $target );
            $words = preg_split( "/[\s,]+/",  $sentence );
            foreach( $words as $w ) {
                //$normalized = ($word == $normalizedWord) ? normalizeString( $w ) : $w;
                $normalized = normalizeString( $w );
                foreach( $targetwords as $tw ) {
                    $sourceword = ( preg_match( "/[ōīēūāŌĪĒŪĀ‘ʻ]/", $tw ) ) ? $w : $normalized;
                    //error_log( "index.php: comparing $tw to $sourceword" );
                    if( !strcasecmp( $tw, $sourceword ) ) {
                        //error_log( "index.php: matched $tw to $sourceword" );
                        $w = '<strong>' . $w . '</strong>';
                    }
                }
                $result .= $w . ' ';
            }
            $sentence = $result;
 ?>
                  
            <div class="hawaiiansentence">
              <p><?=$sentence?></p>
              <p class='source'><?=$source?></p>
              <p class='source'><?=$authors?></p>
            </div>
            <hr>

<?php
        }
   }
if( sizeof( $rows ) < 1 ) {
    $sentenceCount = $laana->getSentenceCount();
    if( isset( $_GET['about'] ) ) {
?>
           <h2>About Laʻana!</h2>
              <div style="font-size: 18px;">
                <p>Laʻana is a tool for learners of Hawaiian to search example sentences. Our goal is to allow learners to see how words are used in context, similar to <a href="https://tatoeba.org/en/" target="_blank">The Tatoeba Project.</a> This is useful for things like adding sentences to flashcards, or just seeing more context to better understand a word.</p>
                <p>This project also serves a secondary purpose of being the first Hawaiian sentence corpus. This means that we can eventually begin collecting some interesting data on things like word frequency and common search terms.</p>
                <p>Laʻana! is a pet project by <a href="https://github.com/MeijiIshinIsLame" target="_blank">Zachary Silva</a>. I don't know why I am compelled to use the word "we" since I am the only person working on this. I am not making any money on this project. This was simply made for fun and because I have an interest in prepetuating the Hawaiian language.</p>
                <p>If you have any questions or suggestions, please email <b>laanadev@gmail.com</b>. Any advice or criticism is appreciated.</p>
              </div>
<?php
    } else {
?>
           <h3>What is Laʻana?</h3>
              <p>Laʻana is a tool for learners of Hawaiian to search example sentences.</p>
              <p>Donʻt know where to start? Try these examples!</p>
              <ul>
                 <li><a href="?search=kumu">Kumu</a></li>
                 <li><a href="?search=makuakāne"> Makuakāne</a></li>
                 <li><a href="?search=<?=$laana->getRandomWord()?>">Search for a random sentence!</a></li>
              </ul>
              <h3>Why use Laʻana?</h3>
              <p>Hawaiian is a living language, and learners should be able to look up real examples of how it is used.</p>
              <p>These examples can be great for making flashcards, checking nuance, checking usage frequency, etc.</p>
              <h3>Other Resources</h3>
              <ul>
                <li><a href="https://ulukau.org/index.php?l=en" target="_blank">Ulukau - Hawaiian Dictionary</a></li>
                <li><a href="http://ulukau.org/kaniaina/?a=cl&cl=CL1&sp=A&ai=1&e=-------en-20--1--txt-tpIN%7ctpTI%7ctpTA%7ctpCO%7ctpTY%7ctpLA%7ctpKE%7ctpPR%7ctpSG%7ctpTO%7ctpTG%7ctpSM%7ctpTR%7ctpSP%7ctpCT%7ctpET%7ctpHT%7ctpDT%7ctpOD%7ctpDF-----------------" target="_blank">Ka Leo Hawaiʻi - Radio interviews with native speakers of Hawaiian</a></li>
                <li><a href="https://nupepa.org/" target="_blank">Nūpepa - Newspaper archives in Hawaiian</a></li>
                <li><a href="https://fluxhawaii.com/section/olelo-hawaii/" target="_blank">Flux Hawaii - Modern articles in Hawaiian</a>
                </li>
                <li><a href="https://www.civilbeat.org/projects/ka-ulana-pilina/" target="_blank">Civil Beat Articles in Hawaiian</a></li>
                <li><a href="http://www.ulukau.org/elib/collect/spoken/index/assoc/D0.dir/book.pdf" target="_blank">Ulukau - Hawaiian Dictionary</a></li>
                <li><a href="https://ulukau.org/index.php?l=en" target="_blank">Spoken Hawaiian - Great book for beginner grammar. (People who have learned Japanese can consider this the Tae Kim of Hawaiian.)</a></li>
                <li><a href="https://manomano.io/" target="_blank">Manomano - Dictionary with flashcards</a></li>
                <li><a href="https://hawaiian-grammar.org/current/#h.3dy6vkm" target="_blank">Hawaiian Grammar - In depth grammar guide from a linguist perspective.</a></li>

               </ul>
               <h3>Questions or concerns?</h3>
               <p>Please email <b>laanadev@gmail.com</b> with any bugs, feature requests, suggestions, etc.</p>
               <h4>There are currently <?=$sentenceCount?> sentences in the database. Please email if you would like to help! I am in need of sources!</h4>
<?php
    }
}
?>

            <!--
              <p><a href="/results/2?search=malie">More Sentences ></a></p>
        -->
          </div>
        
   </body>
</html>

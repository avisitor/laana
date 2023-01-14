<?php
include '../db/funcs.php';
$word = $_REQUEST['word'];
$pattern = $_REQUEST['pattern'];
$page = $_REQUEST['page'];
// For some reason infiniteScroll skips the requests for the first two pages
if( $page >= 2 ) {
    $page -= 2;
}
debuglog( $_REQUEST );
$laana = new Laana();
$output = "";
if( $word ) {
    $target = ($pattern == 'exact') ? $word : normalizeString( $word );
    $targetwords = preg_split( "/[\s]+/",  $target );
    $tw = ($pattern == 'exact') ? $target : implode( '|', $targetwords );
    $pat = "/(" . $tw . ")/";
    $repl = '<span class="match">$1</span>';
    $rows = $laana->getSentences( $word, $pattern, $page );
    foreach( $rows as $row ) {
        $source = $row['sourcename'];
        $authors = $row['authors'];
        $link = $row['link'];
        $sourcelink = "<a href='$link' target='_blank'>$source</a>";
        $sentence = preg_replace($pat, $repl, $row['hawaiiantext'] );
        $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=" .
                   $row['hawaiiantext'];
        $output .= <<<EOF
                  
            <div class="hawaiiansentence">
              <p class='title'>$sentence</p>
              <p class='source'>$sourcelink</p>
              <p class='source'>$authors</p>
              <p class='source'><a target='_blank' href='$translate'>translate</a></p>
            </div>
            <hr>
 EOF;
    }
}
echo $output;
///echo json_encode( $output );
//echo json_encode( $sentences );
?>
    

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
    $replace = [
        "/a|ā|Ā/" => "[aĀā]",
        //        "/ā/" => "[aĀā]",
        //        "/Ā/" => "[aĀā]",
    ];
    $repl = "<span>$1</span>";
    $target = ($pattern == 'exact') ? $word : normalizeString( $word );
    $target = normalizeString( $word );
    $targetwords = preg_split( "/[\s]+/",  $target );
    $tw = ($pattern == 'exact') ? $target : implode( '|', $targetwords );
    $expanded = "/(" . preg_replace( array_keys( $replace ),
                                     array_values( $replace ),
                                     $tw ) . ")/ui";
    
    $pat = "/(" . $tw . ")/ui";
    $pat = $expanded;
    $repl = '<span class="match">$1</span>';
    $rows = $laana->getSentences( $word, $pattern, $page );
    foreach( $rows as $row ) {
        $source = $row['sourcename'];
        $sentenceid = $row['sentenceid'];
        $authors = $row['authors'];
        $link = $row['link'];
        $sourcelink = "<a href='$link' target='_blank'>$source</a>";
        $idlink = "<a href='context?id=$sentenceid&raw' target='_blank'>Context</a>";
        $simplified = "<a href='context?id=$sentenceid' target='_blank'>Simplified</a>";
        $sentence = preg_replace($pat, $repl, $row['hawaiiantext'] );
        error_log( "pat: $pat, repl: $repl");
        $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=" .
                     $row['hawaiiantext'];
        $output .= <<<EOF
                  
            <div class="hawaiiansentence">
              <p class='title'>$sentence</p>
              <p style='font-size:0.6em;margin-bottom:0;'>
              <span class='hawaiiansentence source'>$sourcelink</span>&nbsp;&nbsp;
              </p><p style='font-size:0.5em;margin-top:0.1em;'>
              <span class='source'>$idlink</span>&nbsp;&nbsp;
              <span class='source'>$simplified</span>&nbsp;&nbsp;
              <span class='source'>$authors</span>&nbsp;&nbsp;
              <span class='source'><a target='_blank' href='$translate'>translate</a>
              </p>
            </div>
            <hr>
 EOF;
    }
}
echo $output;
///echo json_encode( $output );
//echo json_encode( $sentences );
?>
    

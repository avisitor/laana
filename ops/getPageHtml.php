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
    $normalizedWord = normalizeString( $word );
    $rows = $laana->getSentences( $word, $pattern, $page );
    foreach( $rows as $row ) {
        $source = $row['sourcename'];
        $authors = $row['authors'];
        $link = $row['link'];
        $sourcelink = "<a href='$link' target='_blank'>$source</a>";
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
                    $w = '<span class="match">' . $w . '</span>';
                }
            }
            $result .= $w . ' ';
        }
        $sentence = $result;
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
    

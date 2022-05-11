<?php
include '../db/funcs.php';
$word = $_REQUEST['word'];
$pattern = $_REQUEST['pattern'];
$page = $_REQUEST['page'];
debuglog( $_REQUEST );
$laana = new Laana();
$output = "";
if( $word ) {
    $sentences = $laana->getSentences( $word, $pattern, $page );
    //echo json_encode( $sentences );
    if( sizeof( $sentences ) > 0 ) {
        $output = "<div class='hawaiiansentence'>";
        foreach( $sentences as $sentence ) {
            $output .=
                    "<p>" . $sentence['hawaiiantext'] . "</p>\n" .
                    "<p class='source'>" . $sentence['sourcename'] . "</p>\n" .
                    "<p class='source'>" . $sentence['authors'] . "</p>" . "\n";
        };
        $output .= "</div>";
    }
}
//echo $output;
///echo json_encode( $output );
echo json_encode( $sentences );
?>
    

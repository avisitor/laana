<?php
include '../db/funcs.php';
$word = $_REQUEST['word'];
$pattern = strtolower( $_REQUEST['pattern'] );
$page = $_REQUEST['page'];
$nodiacriticals = isset( $_REQUEST['nodiacriticals'] );

// For some reason infiniteScroll skips the requests for the first two pages
if( $page >= 2 ) {
    $page -= 2;
}
debuglog( $_REQUEST );
$laana = new Laana();
$output = "";
if( $word ) {
    $replace = [
        /*
           "/a|ā|Ā/" => "‘|ʻ*[aĀā]",
           "/e|ē|Ē/" => "‘|ʻ*[eĒē]",
           "/i|ī|Ī/" => "‘|ʻ*[iĪī]",
           "/o|ō|Ō/" => "‘|ʻ*[oŌō]",
           "/u|ū|Ū/" => "‘|ʻ*[uŪū]",
         */
        "/a|ā|Ā/" => "ʻ*[aĀā]",
        "/e|ē|Ē/" => "ʻ*[eĒē]",
        "/i|ī|Ī/" => "ʻ*[iĪī]",
        "/o|ō|Ō/" => "ʻ*[oŌō]",
        "/u|ū|Ū/" => "ʻ*[uŪū]",
    ];
    $repl = "<span>$1</span>";
    $target = ($pattern == 'exact') ? $word : normalizeString( $word );
    //$target = normalizeString( $word );
    if( $pattern != 'exact' ) {
        $target = str_replace( 'ʻ', 'ʻ*', $target );
    }
    $targetwords = preg_split( "/[\s]+/",  $target );
    $tw = '';
    if( $pattern == 'exact' ) {
        $tw = "\\b$target\\b";
    } else if( $pattern == 'any' ) {
        $tw = '\\b' . implode( '\\b|\\b', $targetwords ) . '\\b';
    } else {
        // order
        $tw = '\\b' . implode( '\\b.*\\b', $targetwords ) . '\\b';
    }
    $expanded = "/(" . preg_replace( array_keys( $replace ),
                                     array_values( $replace ),
                                     $tw ) . ")/ui";
    
    $pat = "/(" . $tw . ")/ui";
    if( $pattern != 'exact' ) {
        $pat = $expanded;
    }
    $repl = '<span class="match">$1</span>';
    debuglog( "getPageHTML highlight: target=$target, pat=$pat, repl=$repl");
    $options = [];
    if( $nodiacriticals ) {
        $options['nodiacriticals'] = true;
    }
    $rows = $laana->getSentences( $word, $pattern, $page, $options );
    foreach( $rows as $row ) {
        //debuglog( "getPageHTML source: " . var_export( $row, true ) );
        $source = $row['sourcename'];
        $sentenceid = $row['sentenceid'];
        $authors = $row['authors'];
        $sourceid = $row['sourceid'];
        $link = isset($row['link']) ? $row['link'] : '';
        $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
        $idlink = "<a class='fancy' href='context?id=$sentenceid&raw' target='_blank'>Context</a>";
        $simplified = "<a class='fancy' href='context?id=$sentenceid' target='_blank'>Simplified</a>";
        $snapshot = "<a class='fancy' href='rawpage?id=$sourceid' target='_blank'>Snapshot</a>";
        if( $pattern == 'regex' ) {
            $sentence = $row['hawaiiantext'];
        } else {
            $sentence = preg_replace($pat, $repl, $row['hawaiiantext'] );
        }
        $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=" .
                     $row['hawaiiantext'];
        $output .= <<<EOF
                  
            <div class="hawaiiansentence">
              <p class='title'>$sentence</p>
              <p style='font-size:0.6em;margin-bottom:0;'>
              <span class='hawaiiansentence source'>$sourcelink</span>&nbsp;&nbsp;$snapshot
              </p><p style='font-size:0.5em;margin-top:0.1em;'>
              <span class='source'>$authors</span>&nbsp;&nbsp;
              <span class='source'>$idlink</span>&nbsp;&nbsp;
              <span class='source'>$simplified</span>&nbsp;&nbsp;
              <span class='source'><a class='fancy' target='_blank' href='$translate'>translate</a>
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


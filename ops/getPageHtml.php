<?php
include '../db/funcs.php';

// Allow invocation over HTTP as well as from the command line
function getParameters() {
    $params = [];
    if( $_REQUEST && sizeof($_REQUEST) > 0 ) {
        $params['word'] = $_REQUEST['word'];
        $params['pattern'] = strtolower( $_REQUEST['pattern'] );
        $params['order'] = strtolower( $_REQUEST['order'] );
        $params['page'] = isset($_REQUEST['page']) ? $_REQUEST['page'] : 0;
        $params['raw'] = isset($_REQUEST['raw']) ? $_REQUEST['raw'] : 0;
        $params['nodiacriticals'] = isset( $_REQUEST['nodiacriticals'] ) || ($pattern == 'any') || ($pattern == 'all');
        $params['from'] = isset($_GET['from']) ? $_GET['from'] : "";
        $params['to'] = isset($_GET['to']) ? $_GET['to'] : "";
    } else {
        $longopts = [
            "word:",
            "pattern:",
            'order:',
            'page:',
            'nodiacriticals:',
            'from:',
            'to:',
            'raw',
        ];
        $args = getopt( "", $longopts );
        $params['raw'] = isset( $args['raw'] ) ? true : false;
        $params['word'] = $args['word'] ?: '';
        $params['pattern'] = $args['pattern'] ?: '';
        $params['order'] = $args['order'] ?: '';
        $params['from'] = $args['from'] ?: '';
        $params['to'] = $args['to'] ?: '';
        $params['nodiacriticals'] = $args['nodiacriticals'] ?: 0;
        if( !($params['word'] && $params['pattern']) ) {
            echo "Usage: getPageHTML.php --word SEARCHTERM --pattern PATTERN [--order ORDER] [--nodiacriticals=1] [--raw=1]\n";
            return '';
        }
    }
    return $params;
}

function getPage( $params ) {
    $word = $params['word'];
    $pattern = $params['pattern'];
    $order = $params['order'];
    $page = $params['page'];
    $nodiacriticals = $params['nodiacriticals'];
    $raw = $params['raw'];
    $from = $params['from'];
    $to = $params['to'];
    
    // For some reason infiniteScroll skips the requests for the first two pages
    if( $page >= 2 ) {
        $page -= 2;
    }
    $orders = [
        'alpha' => "hawaiianText",
        'rand' => 'rand()',
        'length' => 'length(hawaiianText),hawaiianText',
        'length desc' => 'length(hawaiianText) desc,hawaiianText',
        'source' => 'sourcename,hawaiianText',
        'source desc' => 'sourcename desc,hawaiianText',
        'date' => 'date,hawaiianText',
        'date desc' => 'date desc,hawaiianText',
    ];
    $orderBy = (isset($orders[$order])) ? $orders[$order] : '';
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
        $target = ($nodiacriticals) ? normalizeString( $word ) :  $word;
        //$target = normalizeString( $word );
        if( $pattern != 'exact' && $pattern != 'regex' ) {
            $target = str_replace( 'ʻ', 'ʻ*', $target );
        }
        $targetwords = preg_split( "/[\s]+/",  $target );
        $tw = '';
        $pat = '';
        $stripped = '';
        if( $pattern == 'exact' || $pattern == 'regex' ) {
            $target = preg_replace( '/^‘/', '', $target );
        }
        if( $pattern == 'exact' ) {
            $tw = "\\b$target\\b";
        } else if( $pattern == 'any' || $pattern == 'all' ) {
            $quoted = preg_match( '/^".*"$/', $target );
            if( $quoted ) {
                $stripped = preg_replace( '/^"/', '', $target );
                $stripped = preg_replace( '/"$/', '', $stripped );
                $tw = '\\b' . $stripped . '\\b';
            } else {
                $tw = '\\b' . implode( '\\b|\\b', $targetwords ) . '\\b';
            }
        } else if( $pattern == 'order' ) {
            $tw = '\\b' . implode( '\\b.*\\b', $targetwords ) . '\\b';
        } else if( $pattern == 'regex' ) {
            $tw = str_replace( "[[:>:]]", "\\b",
                               str_replace("[[:<:]]", "\\b", $target) );
        }
        if( $tw ) {
            $expanded = "/(" . preg_replace( array_keys( $replace ),
                                             array_values( $replace ),
                                             $tw ) . ")/ui";
            
            $pat = "/(" . $tw . ")/ui";
            if( $pattern != 'exact' && $pattern != 'regex' ) {
                $pat = $expanded;
            }
            $repl = '<span class="match">$1</span>';
            debuglog( "getPageHTML highlight: target=$target, pat=$pat, repl=$repl");
        } else {
            debuglog( "getPageHTML highlight: nothing to match" );
        }
        $options = [];
        if( $nodiacriticals ) {
            $options['nodiacriticals'] = true;
        }
        if( $orderBy ) {
            $options['orderby'] = $orderBy;
        }
        if( $from ) {
            $options['from'] = $from;
        }
        if( $to ) {
            $options['to'] = $to;
        }
        $rows = $laana->getSentences( $word, $pattern, $page, $options );
        if( $raw ) {
            echo json_encode( $rows );
            return;
        }
        foreach( $rows as $row ) {
            //debuglog( "getPageHTML source: " . var_export( $row, true ) );
            $source = $row['sourcename'];
            $sentenceid = $row['sentenceid'];
            $authors = $row['authors'];
            $sourceid = $row['sourceid'];
            $date = $row['date'];
            $hawaiiantext = $row['hawaiiantext'];
            // mysql fulltext search returns some matches where the words of
            // the search phrase are not contiguous
            if( $stripped && !preg_match( '/' . $stripped . '/', $hawaiiantext ) ) {
                //continue;
            }
            $link = isset($row['link']) ? $row['link'] : '';
            $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
            $idlink = "<a class='fancy' href='context?id=$sentenceid&raw' target='_blank'>Context</a>";
            $simplified = "<a class='fancy' href='context?id=$sentenceid' target='_blank'>Simplified</a>";
            $snapshot = "<a class='fancy' href='rawpage?id=$sourceid' target='_blank'>Snapshot</a>";
            if( !$pat ) {
                $sentence = $hawaiiantext;
            } else {
                $sentence = preg_replace($pat, $repl, $hawaiiantext );
            }
            $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=$hawaiiantext";
            $output .= <<<EOF
                  
            <div class="hawaiiansentence">
              <p class='title'>$sentence</p>
              <p style='font-size:0.6em;margin-bottom:0;'>
              <span class='hawaiiansentence source'>$sourcelink&nbsp;&nbsp;$snapshot</span>
              </p><p style='font-size:0.5em;margin-top:0.1em;'>
              <span class='date'>$date</span>&nbsp;&nbsp;
              <span class='author'>$authors</span>&nbsp;&nbsp;
              <span class='source'>$idlink</span>&nbsp;&nbsp;
              <span class='source'>$simplified</span>&nbsp;&nbsp;
              <span class='source'><a class='fancy' target='_blank' href='$translate'>Translate</a>
              </p>
            </div>
            <hr>
 EOF;
        }
    }
    echo $output;
}

$params = getParameters();
if( $params ) {
    getPage( $params );
}
?>


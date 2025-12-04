<?php
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/utils.php';

// Allow invocation over HTTP as well as from the command line
function getParameters() {
    $params = [];
    if( $_REQUEST && sizeof($_REQUEST) > 0 ) {
        $params['word'] = $_REQUEST['word'];
        $params['pattern'] = strtolower( $_REQUEST['pattern'] );
        $params['order'] = strtolower( $_REQUEST['order'] );
        $params['page'] = $_REQUEST['page'] ?? 0;
        $params['raw'] = $_REQUEST['raw'] ?? 0;
        $params['nodiacriticals'] = isset( $_REQUEST['nodiacriticals'] ) ||
                                    ($params['pattern'] == 'any') ||
                                    ($params['pattern'] == 'match') ||
                                    ($params['pattern'] == 'all');
        $params['from'] = $_GET['from'] ?? "";
        $params['to'] = $_GET['to'] ?? "";
        $params['limit'] = $_GET['limit'] ?? 20;
        $params['verbose'] = 0;
        if( isset( $_REQUEST['provider'] ) ) {
            $params['provider'] = $_REQUEST['provider'];
        }
    } else {
        $longopts = [
            "word:",
            "pattern:",
            'order:',
            'page:',
            'nodiacriticals',
            'from:',
            'to:',
            'raw',
            'verbose',
            'limit:',
            'provider:',
        ];
        $args = getopt( "", $longopts );
        $params['raw'] = isset( $args['raw'] );
        $params['word'] = $args['word'] ?? '';
        $params['pattern'] = $args['pattern'] ?? '';
        $params['order'] = $args['order'] ?? 'source';
        $params['from'] = $args['from'] ?? '';
        $params['to'] = $args['to'] ?? '';
        $params['page'] = $args['page'] ?? 0;
        $params['limit'] = $args['limit'] ?? 20;
        $params['verbose'] = isset($args['verbose']);
        if( isset( $args['provider'] ) ) {
            $params['provider'] = $args['provider'];
        }
        $params['nodiacriticals'] = isset( $args['nodiacriticals'] ) ||
                                    ($params['pattern'] == 'any') ||
                                    ($params['pattern'] == 'match') ||
                                    ($params['pattern'] == 'all');

        if( !($params['word'] && $params['pattern']) ) {
            echo "Usage: getPageHTML.php --word SEARCHTERM --pattern PATTERN [--order ORDER] [--nodiacriticals=1] [--raw=1]\n";
            return '';
        }
    }
    return $params;
}

function getOrderBy( $order, $providerName = '' ) {
    $orders = [
        'alpha' => "hawaiianText",
        'alpha desc' => "hawaiianText desc",
        'rand' => 'rand()',
        'length' => 'length(hawaiianText),hawaiianText',
        'length desc' => 'length(hawaiianText) desc,hawaiianText',
        'source' => 'sourcename,hawaiianText',
        'source desc' => 'sourcename desc,hawaiianText',
        'date' => 'date,hawaiianText',
        'date desc' => 'date desc,hawaiianText',
    ];
    $orderBy = $orders[$order] ?? '';
    // Adjust function names for Postgres
    if ($providerName === 'Postgres' && $orderBy === 'rand()') {
        $orderBy = 'random()';
    }
    return $orderBy;
}

function getPage( $params, $provider ) {
    $word = $params['word'];
    //$pattern = $provider->normalizeMode( $params['pattern'] );
    $pattern = $params['pattern'];
    $page = $params['page'];
    // For some reason infiniteScroll skips the requests for the first two pages
    if( $page >= 2 ) {
        $page -= 2;
    }
    $orderBy = getOrderBy( $params['order'], $provider->getName() );
    $output = "";
    if( $word ) {
        $options = [
            'nodiacriticals' => $params['nodiacriticals'],
            'orderby' => $orderBy,
            'from' => $params['from'],
            'to' => $params['to'],
            'limit' => $params['limit'],
            'sentence_highlight' => true,
        ];
        if( $params['verbose'] ) {
            echo "Call getSentences($word,$pattern,$page," . json_encode($options) . ")\n";
        }
        $rows = $provider->getSentences( $word, $pattern, $page, $options );
        $provider->debuglog( "getPageHTML rows: " . var_export( $rows, true ) );
        foreach( $rows as $row ) {
            //$provider->debuglog( "getPageHTML source: " . var_export( $row, true ) );
            if( $params['verbose'] ) {
                echo json_encode( $row ) . "\n";
            }
            $source = $row['sourcename'];
            $sentenceid = $row['sentenceid'];
            $authors = $row['authors'];
            $sourceid = $row['sourceid'];
            $date = $row['date'];
            $hawaiiantext = $row['hawaiiantext'];
            // mysql fulltext search returns some matches where the words of
            // the search phrase are not contiguous
            if( !$provider->checkStripped( $hawaiiantext ) ) {
                //continue;
            }
            $link = isset($row['link']) ? $row['link'] : '';
            $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
            
            // Need to mark up the sentence and replace diacritics?
            $displaySentence = $provider->processText( $hawaiiantext );

            $encodedSentence = urlencode($hawaiiantext);
            $idlink = "<a class='fancy' href='context?id=$sentenceid&raw&highlight_text=$encodedSentence' target='_blank'>Context</a>";
            $simplified = "<a class='fancy' href='context?id=$sentenceid&highlight_text=$encodedSentence' target='_blank'>Simplified</a>";
            $snapshot = "<a class='fancy' href='rawpage?id=$sourceid' target='_blank'>Snapshot</a>";
            
            $haw = urlencode( $hawaiiantext );
            $sentence = $displaySentence; // Assign to $sentence for the EOF block
            $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=$haw";
            if( $params['raw'] ) {
                echo "\n" . json_encode( $row, JSON_PRETTY_PRINT ) . "\n";
                echo "$sentence\n";
                echo "\n$link | rawpage?id=$sourceid | context?id=$sentenceid&raw&highlight_text=$encodedSentence | context?id=$sentenceid&highlight_text=$encodedSentence | $translate\n";
            } else {
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
    }
    echo $output;
}

$params = getParameters();
if( $params ) {
    print_r( $params );
    $providerName = $params['provider'] ?? 'Elasticsearch';
    if( !isValidProvider( $providerName ) ) {
        $valid = implode(', ', array_keys( getKnownProviders() ) );
        echo "Invalid provider name; must be one of $valid\n";
    } else {
        $provider = getProvider( $providerName );
        if( $_REQUEST && sizeof($_REQUEST) > 0 ) {
            $provider->debuglog( $_REQUEST, '_REQUEST' );
        }
        $modes = array_keys( $provider->getAvailableSearchModes() );
        if( !in_array( $params['pattern'], $modes ) ) {
            echo "Invalid search mode for this provider; must be one of " . json_encode(  $modes ) . "\n";
            $provider->debuglog( "Invalid search mode for provider $providerName; must be one of " . json_encode(  $modes ) );
        } else {
            getPage( $params, $provider );
        }
    }
}
?>


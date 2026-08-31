<?php
require_once __DIR__ . '/../lib/provider.php';
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/grammar_patterns.php';

// Get parameters
$pattern = $_REQUEST['pattern'] ?? '';
$page = intval($_REQUEST['page'] ?? 0);
$limit = intval($_REQUEST['limit'] ?? 20);
$providerName = $_REQUEST['provider'] ?? '';
$from = $_REQUEST['from'] ?? '';
$to = $_REQUEST['to'] ?? '';
$order = $_REQUEST['order'] ?? 'rand';

if (!$pattern) {
    echo "<!-- No pattern specified -->";
    exit;
}

// Get the provider
$provider = $providerName ? getProvider($providerName) : getProvider();

// Get regex for the pattern
$patterns = getGrammarPatterns();
$regex = $patterns[$pattern]['regex'] ?? null;

// For some reason infiniteScroll skips the requests for the first two pages
if ($page >= 2) {
    $page -= 2;
}

$offset = $page * $limit;
$output = '';

try {
    // Get matches from provider with additional options
    $options = [
        'limit' => $limit,
        'offset' => $offset,
    ];
    
    if ($from) {
        $options['from'] = $from;
    }
    if ($to) {
        $options['to'] = $to;
    }
    if ($order) {
        $options['order'] = $order;
    }
    
    $matches = $provider->getGrammarMatches($pattern, $limit, $offset, $options);
    
    // Render each sentence using the same format as regular sentence search
    foreach ($matches as $row) {
        $sentenceid = $row['sentenceid'];
        $sourceid = $row['sourceid'];
        $source = $row['sourcename'] ?? 'Unknown';
        $date = $row['date'] ?? '';
        $authors = $row['authors'] ?? '';
        $hawaiiantext = $row['hawaiiantext'];
        $link = isset($row['link']) ? $row['link'] : '';
        
        // Highlight the match if regex is available
        $displaySentence = $hawaiiantext;
        if ($regex) {
            $displaySentence = preg_replace_callback($regex, function($matches) {
                $full = $matches[0];
                $prefix = '';
                $suffix = '';
                // When the match is mid-sentence, the regex's leading group
                // (^|[,:;])\s* consumes a preceding delimiter (and whitespace).
                // Keep that delimiter + whitespace OUTSIDE the highlight so we
                // emphasize the grammatical pattern, not the punctuation.
                if (preg_match('/^[,:;]\s*/u', $full, $pre)) {
                    $prefix = $pre[0];
                    $full = substr($full, strlen($prefix));
                }
                // Likewise, when the match is followed by a delimiter, keep the
                // trailing , ; or : (and any whitespace just before it) outside
                // the highlight.
                if (preg_match('/\s*[,:;]$/u', $full, $post)) {
                    $suffix = $post[0];
                    $full = substr($full, 0, -strlen($suffix));
                }
                return $prefix . "<span class='match'>" . $full . "</span>" . $suffix;
            }, $hawaiiantext);
        }
        
        $sourcelink = "<a class='fancy' href='$link' target='_blank'>$source</a>";
        $encodedSentence = urlencode($hawaiiantext);
        $idlink = "<a class='fancy' href='context?id=$sentenceid&raw&highlight_text=$encodedSentence' target='_blank'>Context</a>";
        $simplified = "<a class='fancy' href='context?id=$sentenceid&highlight_text=$encodedSentence' target='_blank'>Simplified</a>";
        $snapshot = "<a class='fancy' href='rawpage?id=$sourceid' target='_blank'>Snapshot</a>";
        $haw = urlencode($hawaiiantext);
        $translate = "https://translate.google.com/?sl=auto&tl=en&op=translate&text=$haw";
        
        $output .= <<<EOF
                  
            <div class="hawaiiansentence">
              <p class='title'>$displaySentence</p>
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
    
    if (count($matches) === 0) {
        $output .= "<!-- No more results -->";
    }
    
    echo $output;
    
} catch (Exception $e) {
    \Avisitor\Monolog\Logger::logError("Error in getGrammarMatchesHtml.php: " . $e->getMessage());
    \Avisitor\Monolog\Logger::logError($e->getTraceAsString());
    echo "<!-- Error: " . htmlspecialchars($e->getMessage()) . " -->";
}

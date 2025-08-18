<!DOCTYPE html>
<html lang="en" class="">
    <head>
        <?php include 'common-head.html'; ?>
        <title>Noiʻiʻōlelo Context</title>
    </head>
    <style>
        body {
        padding: 1em;
        }
     #logo-section-IE-only {
         display: none;
     }
     #logo-section img {
         width: 300px;
     }
     h1 {
         font-size: 1.5em;
     }
    </style>
    <body>

        <?php
        require_once __DIR__ . '/lib/provider.php';
        require_once __DIR__ . '/lib/utils.php';

        $sentenceID = $_GET['id'] ?: '';
        $raw = isset($_GET['raw']) ? 1 : 0;
        $highlightText = isset($_GET['highlight_text']) ? urldecode($_GET['highlight_text']) : '';

        if( $sentenceID ) {
            $doc = $provider->getDocument( $sentenceID );
            if( $doc ) {
                $sentence = $doc['text'] ?? ''; // Assuming 'text' holds the sentence content
                $sourceid = $doc['doc_id'] ?? ''; // Assuming 'doc_id' is the source ID
                $sourcename = $doc['sourcename'] ?? ''; // Assuming 'sourcename' is available

                debuglog( "context: $sentenceID=$sentence" );
                if( $raw ) {
                    $classtext = "";
                    $titletext = "";
                    $text = $doc['text'] ?? ''; // Use the full text from the document
                    $text = str_replace( "\n", " ", $text );
                } else {
                    $classtext = "class='rawtext'";
                    $titletext = "<h1 class='title'>$sourcename</h1>\n";
                    $text = $doc['text'] ?? ''; // Use the full text from the document
                    if( $text ) {
                        $text = "<p>" . str_replace( "\n", "</p><p>", $text ) . "</p>";
                    }
                }
                if( $text ) {
                    $originalDocumentText = $text; // Store original text before any normalization for display

                    // Prepare document text for matching: strip HTML, decode entities, normalize whitespace, remove control chars
                    $normalizedDocumentText = strip_tags($originalDocumentText); // Strip HTML tags first
                    $normalizedDocumentText = mb_convert_encoding($normalizedDocumentText, 'HTML-ENTITIES', "UTF-8");
                    $normalizedDocumentText = html_entity_decode( $normalizedDocumentText );
                    $normalizedDocumentText = preg_replace('/\\s+/', ' ', $normalizedDocumentText); // Normalize whitespace
                    $normalizedDocumentText = preg_replace('/[[:cntrl:]]/u', '', $normalizedDocumentText); // Remove control characters

                    if (!empty($highlightText)) {
                        // highlightText is now expected to be plain text
                        $strippedHighlightTextForMatching = html_entity_decode($highlightText); // Decode entities
                        $strippedHighlightTextForMatching = preg_replace('/\\s+/', ' ', $strippedHighlightTextForMatching); // Normalize whitespace
                        $strippedHighlightTextForMatching = preg_replace('/[[:cntrl:]]/u', '', $strippedHighlightTextForMatching); // Remove control characters

                        // Debugging: Output hex representation of strings to separate files
                        $debugLogFile = __DIR__ . '/highlight_debug.log';
                        $strippedHighlightTextFile = __DIR__ . '/stripped_highlight_text.log';
                        $normalizedDocumentTextFile = __DIR__ . '/normalized_document_text.log';

                        $debugMessage = "\n---" . date('Y-m-d H:i:s') . "---\n";
                        $debugMessage .= "Highlighting debug: highlightText (hex) = " . bin2hex($highlightText) . "\n";
                        $debugMessage .= "Highlighting debug: strippedHighlightTextForMatching (hex) = " . bin2hex($strippedHighlightTextForMatching) . "\n";
                        $debugMessage .= "Highlighting debug: normalizedDocumentText (partial hex) = " . bin2hex(mb_substr($normalizedDocumentText, 0, 500)) . "\n"; // Log partial normalized text
                        
                        file_put_contents($strippedHighlightTextFile, $strippedHighlightTextForMatching);
                        file_put_contents($normalizedDocumentTextFile, $normalizedDocumentText);

                        $regexPattern = preg_quote($strippedHighlightTextForMatching, '/');
                        $regexPattern = str_replace(' ', '\\s+', $regexPattern); // Allow multiple spaces
                        $regexPattern = '/' . $regexPattern . '/ui'; // 'u' for UTF-8, 'i' for case-insensitivity
                        $debugMessage .= "Highlighting debug: Final regex pattern = " . $regexPattern . "\n";

                        // Check if the pattern matches in the normalized text
                        $matchFound = preg_match($regexPattern, $normalizedDocumentText, $matches);
                        $debugMessage .= "Highlighting debug: preg_match result = " . ($matchFound ? "Match found: " . bin2hex($matches[0]) : "No match") . "\n";
                        file_put_contents($debugLogFile, $debugMessage, FILE_APPEND);

                        if ($matchFound) {
                            // Replace in the original $originalDocumentText using the flexible regex
                            $text = preg_replace( $regexPattern, '<p id="start" class="highlight">' . 
                                                            $highlightText . '</p>', $originalDocumentText, 1 ); // Limit to 1 replacement
                            file_put_contents($debugLogFile, "Highlighting debug: Replacement performed using flexible regex on original text.\n", FILE_APPEND);
                        } else {
                            file_put_contents($debugLogFile, "Highlighting debug: No replacement performed.\n", FILE_APPEND);
                            $text = $originalDocumentText; // Revert to original if no highlight
                        }
                    } else {
                        $text = $originalDocumentText; // Revert to original if no highlightText
                    }
                    echo "<div $classtext>\n" . 
                         "<!-- sourceid=$sourceid\nsentence=$sentence -->\n" . 
                         "$titletext" . 
                         "$text\n" . 
                         "</div>\n";
                }
            }
        }
        ?>
        <script>
             const element = document.getElementById("start");
         if( element ) {
             setTimeout( function() {
                 element.scrollIntoView({
                     behavior: 'auto',
                     block: 'center',
                     inline: 'center'
                 });
             }, 100 );
         }
        </script>
    </body>
</html>
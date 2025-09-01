<?php
require_once __DIR__ . '/lib/provider.php';
require_once __DIR__ . '/lib/utils.php';

$provider = getProvider();
$sourceID = $_GET['id'] ?: '';
$type = isset($_GET['simplified']) ? 'text' : 'html';
$text = '';

if( $sourceID ) {
    $doc = $provider->getDocument( $sourceID, $type );
    $provider->debuglog( "rawpage doc for $sourceID: " . var_export( $doc, true ) );
    $content = $doc['content'] ?? $doc['text'] ?? '';
    if( $content ) {
        if( $type == 'text' ) {
            $text = str_replace( "\n", "<br />\n", $content );
        } else {
            $text = $content . "\n";
        }
    }
}
if( !$text ) {
    $text = "<p>No content found</p>";
}
?>

<!DOCTYPE html>
<html lang="en" class="">
    <head>

<?php include 'common-head.html'; ?>

        <title><?=$sourceID?> <?=$type?> - Noiʻiʻōlelo</title>
        <style>
            body {
            padding: .2em;
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
        <script>
            $(document).ready(function() {
                setTimeout( function() {console.log( "reveal" );reveal();}, 1 );
                $('img').css( 'max-width', '100%' );
            });
        </script>
    </head>
    <body>
      <div class="rawtext">
      <?=$text?>
      </div>
    </body>
</html>

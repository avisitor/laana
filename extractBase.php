<?php
$laana = new Laana();
$title = $_GET['title'] ?: '';
$url = $_GET['url'];
if( $url ) {
    $sentences = $parser->extractSentences( $url );
}
$sourceName = $parser->getSourceName( $title );
$source = $laana->getSourceByName( $sourceName );
$sourceID = $source['sourceid'];
$sourceLink = $source['link'] ?: '';
?>
<!DOCTYPE html>
<html lang="en" class="">
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      
      <title><?=$sourceName?> articles</title>
      
     <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" type="text/css" href="./static/main.css">
      <link rel="stylesheet" type="text/css" href="./static/fonts.css">
      <!-- Icons-->
      <link rel="apple-touch-icon" sizes="180x180" href="./static/icons/180.png">
      <link rel="icon" type="image/png" sizes="32x32" href="./static/icons/32.png">
      <link rel="icon" type="image/png" sizes="16x16" href="./static/icons/16.png">

     <style>
     body {
    padding: 0.5em;
 }
     </style>
     <script>
     var includeSql = false;
     function showHideSql( show ) {
         var textArea = document.getElementById( 'sentences' );
         var oldText = textArea.value;
         var sourceID = '<?=$sourceID?>';
         if( show ) {
             if( oldText.indexOf( 'insert into' ) == 0 ) {
                 return;
             }
             var lines = oldText.split( "\n" );
             var text = "";
             if( sourceID ) {
             } else {
                 text += "insert ignore into sources(sourceName,link) values('<?=$sourceName?>', '<?=$url?>');\n";
             }
             lines.forEach( function(sentence) {
                 if( sentence.length > 0 ) {
                     text += "insert into sentences(hawaiianText,sourceID) ";
                     if( sourceID ) {
                         text += "values('" + sentence + "'," + sourceID + ");\n";
                     } else {
                         text += "select '" + sentence + "',sourceID from sources where " +
                               "sourceName = '<?=$sourceName?>';\n";
                     }
                 }
             });
         } else {
             if( oldText.indexOf( 'insert into' ) < 0 ) {
                 return;
             }
             var lines = oldText.split( "\n" );
             var text = "";
             lines.forEach( function(sentence) {
                 if( sentence.length > 0 ) {
                     if( sentence.indexOf( 'into sources' ) < 0 ) {
                         if( sentence.indexOf( 'values(' ) >= 0 ) {
                             text += sentence.replace( /(.*values\(\')(.*)(\',.*)/, '$2\n' );
                         } else {
                             text += sentence.replace( /(.*select \')(.*?)(\'.*)/, '$2\n' );
                         }
                     }
                 }
             });
         }
         textArea.value = text;
         includeSql = show;
     }
     function copyTextToClipboard() {
         var textArea = document.getElementById( 'sentences' );
  
         // Avoid scrolling to bottom
         //textArea.style.top = "0";
         //textArea.style.left = "0";
         //textArea.style.position = "fixed";

         textArea.focus();
         textArea.select();

         try {
             var successful = document.execCommand('copy');
             var msg = successful ? 'successful' : 'unsuccessful';
             console.log('Fallback: Copying text command was ' + msg);
         } catch (err) {
             console.error('Fallback: Oops, unable to copy', err);
         }
         if( oldText ) {
             textArea.value = oldText;
         }
     }
     </script>
   </head>
   <body>
     <h1><?=$sourceName?> articles</h1>

<?php
$text = "";
$rows = 0;
if( $url ) {
    echo "<h5><a href='$url'>$url</a></h5>\n";
    if( !$source || sizeof($source) < 1 ) {
        echo "<h6>Do this first:<br />\ninsert into sources(sourceName,link) values('$sourceName', '$url');<br /></h6>\n";
    }
    //$sentences = $parser->extractSentences( $url );
    $rows = sizeof( $sentences );
    $text = implode( "\n", $sentences );
}
$maxrows = 30;
$rows = ($rows > $maxrows) ? $maxrows : $rows;
?>
    <button style="margin-top:0;margin-bottom:0.5em;" value="Copy" onClick='copyTextToClipboard();'>Copy</button><span> (to clipboard)</span>&nbsp;&nbsp;
    <button style="margin-top:0;margin-bottom:0.5em;" value="Toggle SQL" onClick='showHideSql(!includeSql);'>Toggle SQL</button>
<textarea id="sentences" style="width:100%; height:100%;" rows=<?=$rows?>><?=$text?></textarea>
</body>
</html>

<?php
require_once __DIR__ . '/db/funcs.php';
if (!defined('SOURCES')) {
    define('SOURCES', 'sources');
}
$laana = new Laana();
$title = $_GET['title'] ?: '';
$url = $_GET['url'];
/*
if( $url ) {
    $sentences = $parser->extractSentences( $url );
}
*/
$sourceName = $parser->getSourceName( $title, $url );
$source = $laana->getSourceByName( $sourceName );
$sourceID = $source['sourceid'];
$sourceLink = $source['link'] ?: '';
?>
<!DOCTYPE html>
<html lang="en" class="">
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      
      <title><?=$sourceName?></title>
      
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
    h5 {
        font-size: 1rem;
        margin-bottom: 0;
    }
    .source {
        font-size: 0.8rem;
    }
     </style>
     <script>
     var includeSql = false;
     function showHideSql( show ) {
        let initial = document.getElementById( 'initial' );
         var textArea = document.getElementById( 'sentences' );
         var oldText = textArea.value;
         var sourceID = '<?=$sourceID?>';
         if( show ) {
             if( oldText.indexOf( 'insert into' ) == 0 ) {
                 return;
             }
             var lines = initial.value.split( "\n" );
             var text = "";
             if( sourceID ) {
             } else {
                 text += "insert ignore into " . SOURCES . "(sourceName,link) values('<?=$sourceName?>', '<?=$url?>');\n";
             }
             lines.forEach( function(sentence) {
                 if( sentence.length > 0 ) {
                     text += "insert into " . SENTENCES . "(hawaiianText,sourceID) ";
                     if( sourceID ) {
                         text += "values('" + sentence + "'," + sourceID + ");\n";
                     } else {
                         text += "select '" + sentence + "',sourceID from " . SOURCES . " where " +
                               "sourceName = '<?=$sourceName?>';\n";
                     }
                 }
             });
             textArea.value = text;
         } else {
            minWordChanged();
         }
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
     function minWordChanged() {
        let input = document.getElementById( 'minwords' ).value;
        let minWords = parseInt(input, 10);
        let sentences = document.getElementById( 'sentences' );
        let initial = document.getElementById( 'initial' );
        const lines = initial.value.split('\n');
        const filteredLines = lines.filter(line => {
            const words = line.split(/\s+/);
            return words.length >= minWords;
        });
        sentences.value = filteredLines.join('\n');
        let nSentences = (filteredLines && filteredLines.length) ? filteredLines.length : 0;
        showSentenceCount( nSentences );
     }
     function showSentenceCount( len = 0 ) {
        let countField = document.getElementById( 'sentencecount' );
        countField.innerText = len;
        /*
        if( len ) {
            countField.innerText = len;
        } else {
            let sentences = document.getElementById( 'sentences' );
            const lines = sentences.value.split('\n');
            countField.innerText = lines.length;
        }
        */
     }
     function submitForm() {
        let form = document.getElementById('sentenceForm');
        let f = form;
        return true;
     }
     </script>
   </head>
   <body>
     <h1><?=$sourceName?></h1>

<?php
$text = "";
$rows = 0;
if( $url ) {
    echo "<h5><a href='$url'>$url</a></h5>\n";
    if( !$source || sizeof($source) < 1 ) {
        echo "<h6>Do this first:<br />\ninsert into " . SOURCES . "(sourceName,link) values('$sourceName', '$url');</h6><br />\n";
    } else {
        echo "<span class='source'>$sourceName - $sourceID</span><br />\n";
    }
    $sentences = $parser->extractSentences( $url );
    $rows = sizeof( $sentences );
    $text = implode( "\n", $sentences );
}
$maxrows = 30;
$rows = ($rows > $maxrows) ? $maxrows : $rows;
?>
<form action="addsentences" id="sentenceForm" method="post" onSubmit="return submitForm()">
    <button style="margin-top:0;margin-bottom:0.5em;" value="Copy" onClick='copyTextToClipboard();'>Copy</button><span> (to clipboard)</span>&nbsp;&nbsp;
    <button style="margin-top:0;margin-bottom:0.5em;" value="Toggle SQL" onClick='showHideSql(!includeSql);'>Toggle SQL</button>&nbsp;&nbsp;
    <span class='source'>Min words</span>&nbsp;<input type='number' style='width:3em;' id='minwords' value='4' min="1" oninput="minWordChanged()"/>&nbsp;
    <span class='source'>Sentences:</span>&nbsp;<span id="sentencecount" style="width:5em;"></span>&nbsp;
    <input type="hidden" name="sourcename" value="<?=$sourceName?>" />
    <input type="hidden" name="sourceid" value="<?=$sourceID?>" />
    <input type="submit" />
    <textarea id="sentences" wrap="hard" name="sentences" style="width:100%; height:100%; white-space: pre-wrap" rows="<?=$rows?>"><?=$text?></textarea>
</form>
    <textarea id="initial" style="display:none" ></textarea>
</body>
<script>
(function() {
    //const sentences = <?=json_encode($sentences)?>;
    //const text = sentences.join( "\n" );
    document.getElementById('initial').value = 
    document.getElementById('sentences').value; // = text; // = "<?=$sentences?>";
    minWordChanged();
    //showSentenceCount();
})();
</script>
</html>

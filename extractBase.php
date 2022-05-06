<?php
$laana = new Laana();
$sourceName = $parser->getSourceName();
$source = $laana->getSourceByName( $sourceName );
$sourceID = $source['sourceid'];
$sourceLink = $source['link'];
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
     function copyTextToClipboard() {
         var textArea = document.getElementById( 'sentences' );
         var text = textArea.value;
  
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
     }
     </script>
   </head>
   <body>
     <h1><?=$sourceName?> articles</h1>

<?php
$url = $_GET['url'];
$text = "";
$rows = 0;
if( $url ) {
    echo "<h5><a href='$url'>$url</a></h5>\n";
    if( sizeof($source) < 1 ) {
        echo "<h6>Do this first:<br />\ninsert into sources(sourceName,link) values('$sourceName', 'LINK');<br /><span style='font-style:italic;'>(where LINK is the URL for $sourceName)</span></h6>\n";
    } else {
        $sentences = $parser->extractSentences( $url );
        foreach( $sentences as $sentence ) {
            $text .= "insert into sentences(hawaiianText,sourceID) values('$sentence',$sourceID);\n";
            $rows++;
        }
    }
}
$maxrows = 30;
$rows = ($rows > $maxrows) ? $maxrows : $rows;
?>
    <button style="margin-top:0;margin-bottom:0.5em;" value="Copy" onClick='copyTextToClipboard();'>Copy</button><span> (to clipboard)</span>
<textarea id="sentences" style="width:100%; height:100%;" rows=<?=$rows?>><?=$text?></textarea>
</body>
</html>

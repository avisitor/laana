<!DOCTYPE html>
<html lang="en" class="">
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      
      <title>Extract sentences from pasted text</title>
      
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
     <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
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
     function parseText() {
         var sourceArea = document.getElementById( 'text' );
         var targetArea = document.getElementById( 'sentences' );
         var text = sourceArea.value;
         var params = {
             text: text,
         };
         $.post("ops/parsetext",
                params,
                function(data, status){
                    data = JSON.parse( data );
                    var text = "";
                    var select = document.getElementById( 'source' );
                    var sourceID = select.value;
                    for( var i = 0; i < data.length; i++ ) {
                        var line = "insert into sentences(hawaiianText,sourceID) values('" + data[i] +
                                 "'," + sourceID + ");\n";
                        text += line;
                    }
                    targetArea.value = text;
                });
     }
     </script>
   </head>
   <body>
     <h1>Paste text</h1>
         <label for="source">Source</label>
<select id="source">
     <?php
include 'db/funcs.php';
include 'db/parsehtml.php';
     $laana = new Laana();
     $sources = $laana->getSources();
     $i = 0;
     foreach( $sources as $source ) {
         $name = $source['sourcename'];
         $id = $source['sourceid'];
         $selected = ($i == 0 ) ? "selected" : "";
    echo "<option value='$id' $selected>$name</option>\n";
$i++;
}
?>
</select><br />
<?php
$text = "";
$rows = 20;
$parser = new TextParse();
?>
    <button style="margin-top:0.5em;margin-bottom:0.5em;" value="Parse" onClick='parseText();'>Parse</button>&nbsp;&nbsp;
<textarea id="text" style="width:100%; height:100%;" rows=<?=$rows?>></textarea>
    <button style="margin-top:0.5em;margin-bottom:0.5em;" value="Copy" onClick='copyTextToClipboard();'>Copy</button><span> (to clipboard)</span>
<textarea id="sentences" style="width:100%; height:100%;" rows=<?=$rows?>></textarea>
</body>
</html>

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
    </style>
    <body>

        <?php
        include 'db/parsehtml.php';

        $sentenceID = $_GET['id'] ?: '';
        $raw = isset($_GET['raw']) ? 1 : 0;

        if( $sentenceID ) {
            $laana = new Laana();
            $row = $laana->getSentence( $sentenceID );
            if( $row['sourceid'] ) {
                $sentence = $row['hawaiiantext'];
                $sentence = trim( $sentence, "- " );
                $sourceid = $row['sourceid'];
                $source = $laana->getSource( $sourceid );
                $sourcename = $source['sourcename'];
                debuglog( "context: $sentenceID=$sentence" );
                if( $raw ) {
                    $classtext = "";
                    $titletext = "";
                    $text = $laana->getRawText( $sourceid );
                    $text = str_replace( "\n", " ", $text );
                } else {
                    $classtext = "class='rawtext'";
                    $titletext = "<h1 class='title'>$sourcename</h1>\n";
                    $text = $laana->getText( $sourceid );
                    if( $text ) {
                        $text = "<p>" . str_replace( "\n", "</p><p>", $text ) . "</p>";
                    }
                }
                if( $text ) {
                    $text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
                    $text = html_entity_decode( $text );
                    $text = str_replace( $sentence, '<p id="start" class="highlight">' .
                                                    $sentence . '</p>', $text );
                    echo "<div $classtext>\n" .
                         "<!-- sourceid=$sourceid\nsentence=$sentence -->\n" .
                         $titletext .
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

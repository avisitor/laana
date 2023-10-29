<!DOCTYPE html>
<html lang="en" class="">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
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
                $sourceid = $row['sourceid'];
                if( $raw ) {
                    $text = $laana->getRawText( $sourceid );
                } else {
                    $text = $laana->getText( $sourceid );
                }
                if( $text ) {
                    $text = "<p>" . str_replace( "\n", "</p><p>", $text ) . "</p>";
                    $text = str_replace( $sentence, '<p id="start" style="background-color:yellow">' . $sentence . '</p>', $text );
                    $text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");
                    echo $text;
                }
            }
        }
        ?>
        <script>
             const element = document.getElementById("start");
             if( element ) {
                 element.scrollIntoView(true);
             }
        </script>
    </body>
</html>

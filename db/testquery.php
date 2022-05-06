<?php
include 'funcs.php';

$laana = new Laana();
$word = $laana->getRandomWord();
echo "$word\n";
return;
$rows = $laana->getSentences( 'hale', 'any' );
var_export( $rows );
?>


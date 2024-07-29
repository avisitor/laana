#!/usr/bin/php
<?php
include 'funcs.php';

$longopts = [
    "pattern:",
    "method:",
    'sort:',
];
$args = getopt( "", $longopts );
$pattern = isset( $args['pattern'] ) ? $args['pattern']: 'hale';
$method = isset( $args['method'] ) ? $args['method']: 'any';
$sort = isset( $args['sort'] ) ? $args['sort']: '';

$options = [];
if( $sort ) {
    $options['orderby'] = $sort;
}

$laana = new Laana();
/*
$word = $laana->getRandomWord();
echo "$word\n";
return;
*/
$rows = $laana->getSentences( $pattern, $method, 0, $options );
var_export( $rows );
?>


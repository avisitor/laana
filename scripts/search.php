#!/usr/bin/php
<?php
$dir = dirname(__DIR__, 1);
require $dir . '/db/funcs.php';

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
$rows = $laana->getSentences( $pattern, $method, 0, $options );
//var_export( $rows );
//return;
$i = 0;
foreach( $rows as $row ) {
    echo "$i: {$row['hawaiiantext']}\n";
    echo "{$row['sourcename']},{$row['date']}\n";
    echo "\n";
    $i++;
}
?>


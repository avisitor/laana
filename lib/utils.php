<?php

function formatLogMessage( $msg, $intro = "" ) {
    if( is_object( $msg ) || is_array( $msg ) ) {
        $msg = var_export( $msg, true );
    }
    $defaultTimezone = 'Pacific/Honolulu';
    $now = new DateTimeImmutable( "now", new DateTimeZone( $defaultTimezone ) );
    $now = $now->format( 'Y-m-d H:i:s' );
    $out = "$now " . $_SERVER['SCRIPT_NAME'];
    if( $intro ) {
        $out .= " $intro:";
    }
    return "$out $msg";
}

function debuglog( $msg, $intro = "" ) {
    $msg = formatLogMessage( $msg, $intro );
    error_log( "$msg 
" );
    return;
}

$a = array( 'ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', '‘', 'ʻ' );
$b = array('o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', '', '' );

function normalizeString( $term ) {
    global $a, $b;
    return str_replace($a, $b, $term);
}

?>
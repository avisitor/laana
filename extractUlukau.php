<?php
include_once 'db/parsehtml.php';
include_once 'db/funcs.php';

set_time_limit(120);

$parser = new UlukauHtml( ['boxContent' => true] );

include 'extractBase.php';
?>

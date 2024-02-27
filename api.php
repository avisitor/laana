<?php
?>
<html>
<head>
</head>
<body>
<?php
foreach( $_REQUEST as $key => $value ) {
    echo "<p>$key = $value</p>\n";
}
$object = $_REQUEST['object'] ? $_REQUEST['object'] : '';
$action = $_REQUEST['action'] ? $_REQUEST['action'] : '';
$options = $_REQUEST['options'] ? $_REQUEST['options'] : '';
if( $object == 'sentences' ) {
}
?>
</body>
</html>
    

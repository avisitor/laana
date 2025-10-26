<?php
$home ="/" . basename(__DIR__);

$dbconfig = [
    //'servername' => "worldspot.com",
    'servername' => "localhost",
    'socket' => "/opt/local/var/run/mariadb-10.7/mysqld.sock",
    'username' => "laana",
    'password' => '0$o7Z&93',
    'db' => "laana",
];
// Single database for all tables
define('SOURCES', 'sources');
define('SENTENCES', 'sentences');
define('STATS', 'stats');
define('CONTENTS', 'contents');
define('SEARCHSTATS', 'searchstats');
?>


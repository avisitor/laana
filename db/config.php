<?php
$home ="/" . basename(__DIR__);
$hostname = $_SERVER['SERVER_NAME'] ?: "mauihikes.org";
$baseurl = "https://$hostname$home";

$adminemail = "info@$hostname";
$orgname = "Sierra Club Maui Hikes";
$orgemail = "info@mauisierraclub.org ";

$erroremail = "robw@worldspot.com";

$dbconfig = [
    'servername' => "localhost",
    'socket' => "/opt/local/var/run/mariadb-10.7/mysqld.sock",
    'username' => "root",
    'password' => "",
    'db' => "laana",
];

?>

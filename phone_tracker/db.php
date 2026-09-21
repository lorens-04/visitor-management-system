<?php
date_default_timezone_set("Asia/Manila");

$host = "localhost";
$dbname = "phone_tracker";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
?>

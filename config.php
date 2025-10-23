<?php
$servername = "localhost";
$username = "root";  // Default in XAMPP
$password = "";      // Default empty in XAMPP
$dbname = "time_tracking";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
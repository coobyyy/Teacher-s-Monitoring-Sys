<?php
// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$database = "tms_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset("utf8");

// Return connection
$connection = $conn;
?>

<?php
$host = 'localhost';
$username = 'root';
$password = 'pavan4583'; // Replace with your MySQL root password
$dbname = 'file_management';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

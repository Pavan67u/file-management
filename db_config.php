<?php
// db_config.php: Database Configuration for Railway Deployment

// Check if running on Railway (environment variables set)
if (getenv('MYSQL_HOST') || getenv('MYSQLHOST')) {
    // Railway MySQL environment variables
    $host = getenv('MYSQL_HOST') ?: getenv('MYSQLHOST');
    $username = getenv('MYSQL_USER') ?: getenv('MYSQLUSER');
    $password = getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD');
    $dbname = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE');
    $port = getenv('MYSQL_PORT') ?: getenv('MYSQLPORT') ?: 3306;
} else {
    // Local development (XAMPP)
    $host = 'localhost';
    $username = 'root';
    $password = ''; // XAMPP default - empty password
    $dbname = 'file_management';
    $port = 3306;
}

// Create connection
$conn = new mysqli($host, $username, $password, $dbname, $port);

// Check connection
if ($conn->connect_error) {
    if (getenv('RAILWAY_ENVIRONMENT')) {
        die("Database connection failed. Please try again later.");
    } else {
        die("Connection failed: " . $conn->connect_error);
    }
}

// Set charset
$conn->set_charset("utf8mb4");
?>

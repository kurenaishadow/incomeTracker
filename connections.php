<?php
// Database configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'kurenaigui_user'); // Your database username
define('DB_PASSWORD', 'GJ!2HkA^6n#Azx}+');     // Your database password
define('DB_NAME', 'kurenaigui_dashboard_db'); // Your database name


// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

?>
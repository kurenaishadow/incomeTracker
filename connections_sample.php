<?php
// Database configuration
define('DB_SERVER', '');
define('DB_USERNAME', ''); // Your database username
define('DB_PASSWORD', '');     // Your database password
define('DB_NAME', ''); // Your database name


// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

?>
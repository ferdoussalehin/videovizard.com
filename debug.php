<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP is working!<br>";
echo "PHP Version: " . phpversion() . "<br>";

// Test database connection
if (file_exists('dbconnect_hdb.php')) {
    echo "✓ dbconnect_hdb.php exists<br>";
    require_once 'dbconnect_hdb.php';
    
    if (isset($conn)) {
        echo "✓ Database connection object created<br>";
        if ($conn->connect_error) {
            echo "✗ Connection failed: " . $conn->connect_error . "<br>";
        } else {
            echo "✓ Database connected successfully!<br>";
        }
    } else {
        echo "✗ Connection object not created<br>";
    }
} else {
    echo "✗ dbconnect_hdb.php not found<br>";
}

// Check PHPMailer
if (file_exists('PHPMailer/src/PHPMailer.php')) {
    echo "✓ PHPMailer found<br>";
} else {
    echo "✗ PHPMailer not found<br>";
}
?>
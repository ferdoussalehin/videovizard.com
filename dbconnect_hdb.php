<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$dbhost = "localhost";
// $dbase  = "hypnotherapy_db";
// $dbuser = "inaamalvi1403";
// $dbpass = "AllahuAkbar786";
$dbase = "user_hypnotherapy_db2"; 
$dbuser = "user_inaamalvi1403"; 
$dbpass = "AllahuAkbar786";

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);
$conn->set_charset("utf8mb4");
$conn->query("SET NAMES utf8mb4");

if ($conn->connect_error) {
    echo "Connection Failed: " . $conn->connect_error;
}

try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbase;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("DB CONNECTION FAILED: " . $e->getMessage() . "\n", 3, __DIR__ . "/a_debug.log");
    die("Database connection failed");
}

if ($conn) {
    if (!mysqli_set_charset($conn, "utf8mb4")) {
        error_log("Error setting character set: " . mysqli_error($conn));
    }
}
?>

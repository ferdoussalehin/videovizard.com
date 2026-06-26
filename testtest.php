
<?php
echo "Testing...";
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';
echo "Config loaded. API Key exists: " . (isset($apiKey) ? 'Yes' : 'No');
?>
<?php

ob_start();
header('Content-Type: application/json');

require_once __DIR__ . 'db_connect_hdb.php';

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

function logError($msg) {
    error_log(date("Y-m-d H:i:s") . " - " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL) {
        ob_clean();
        echo json_encode(["success"=>false,"error"=>"Fatal error: ".$error['message'],"topics"=>[]]);
    }
});










?>
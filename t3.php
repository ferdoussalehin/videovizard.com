<?php
ob_start();
require_once 'dbconnect_hdb.php';
if (empty($chatgpt_api_key) && file_exists(__DIR__.'/config.php')) {
    require_once __DIR__.'/config.php';
}
function jsonOut($d){ ob_end_clean(); header('Content-Type: application/json'); echo json_encode($d); exit; }
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'test') {
        jsonOut(array('ok'=>true,'key'=>!empty($chatgpt_api_key),'conn'=>($conn?'yes':'no')));
    }
    jsonOut(array('ok'=>false,'msg'=>'unknown action'));
}
echo 'Page loaded OK - try ?action=test';
?>

<?php
ob_start();
require_once 'dbconnect_hdb.php';
$out = ob_get_clean();
echo json_encode(array('ok'=>true,'stray_output'=>$out,'conn'=>($conn?'yes':'no')));
?>

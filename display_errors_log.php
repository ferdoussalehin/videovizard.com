<?php
// read_log.php - place on GoDaddy root
$log = '/home/syjy0p3q5yjb/public_html/videovizard.com/a_errors.log';
echo "<pre>";
echo file_exists($log) ? file_get_contents($log) : "Log file not found or empty";
echo "</pre>";
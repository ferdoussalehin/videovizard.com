<?php
// Simple logger that writes to the PHP error log
if (isset($_POST['message'])) {
    error_log("BROWSER LOG: " . $_POST['message']);
}
?>
<?php
declare(strict_types=1);

session_start();


header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tokensFile = __DIR__ . '/tokens.json';
if (is_file($tokensFile)) {
    @unlink($tokensFile);
}

session_unset();
session_destroy();


header('Location: index.php?logged_out=1&t=' . time());
exit;

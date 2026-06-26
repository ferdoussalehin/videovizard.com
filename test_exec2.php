<?php
echo "PHP version: " . PHP_VERSION . "\n";
echo "shell_exec exists: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";
echo "exec exists: " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
$r = shell_exec('whoami');
echo "whoami: " . $r . "\n";
$r2 = shell_exec('which php');
echo "which php: " . $r2 . "\n";

<?php
echo "<pre>";

// Check disabled functions
$disabled = ini_get('disable_functions');
echo "Disabled functions:\n$disabled\n\n";

// Check if shell_exec specifically works
echo "shell_exec test: ";
$r = @shell_exec('echo hello');
echo ($r !== null ? "✅ WORKS (got: " . trim($r) . ")" : "❌ BLOCKED") . "\n\n";

// Check proc_open
echo "proc_open available: " . (function_exists('proc_open') ? "✅ YES" : "❌ NO") . "\n";

// Check exec
echo "exec available: ";
$o = []; $rc = 0;
@exec('echo test', $o, $rc);
echo ($rc === 0 ? "✅ WORKS" : "❌ BLOCKED") . "\n";

// Check PHP binary
echo "PHP_BINARY: " . PHP_BINARY . "\n";
echo "php_sapi_name: " . php_sapi_name() . "\n";
echo "PHP version: " . PHP_VERSION . "\n\n";

// Check allow_url_fopen (needed for file_get_contents HTTP)
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? "✅ ON" : "❌ OFF") . "\n";

// Check curl
echo "curl available: " . (function_exists('curl_init') ? "✅ YES" : "❌ NO") . "\n";

echo "</pre>";

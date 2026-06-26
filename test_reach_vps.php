<?php
// test_reach_vps.php - place on GoDaddy root
echo "<pre>";

// Test 1: Basic connection
$ch = curl_init('http://187.124.249.46/videovizard.com/vps_convert.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['secret_key' => 'wrong', 'action' => 'convert']);
$r = curl_exec($ch);
echo "Test 1 - Basic reach:\n";
echo "HTTP: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "Error: " . (curl_error($ch) ?: 'none') . "\n";
echo "Response: $r\n\n";
curl_close($ch);

// Test 2: file_get_contents
echo "Test 2 - file_get_contents:\n";
$r2 = @file_get_contents('http://187.124.249.46/videovizard.com/vps_convert.php');
echo "Result: " . ($r2 ?: 'FAILED/BLOCKED') . "\n";

echo "</pre>";
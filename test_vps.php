<?php
$ch = curl_init('http://187.124.249.46/videovizard.com/vps_convert.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'secret_key' => 'VS_FFmpeg_2026_Secret!',
    'action' => 'convert',
    'podcast_id' => 999,
    'webm_url' => 'https://videovizard.com/test'
]);
$r = curl_exec($ch);
echo 'HTTP: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . '<br>';
echo 'Error: ' . curl_error($ch) . '<br>';
echo 'Response: ' . $r;
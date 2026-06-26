<?php
// Check if scene clips exist
$podcast_id  = $_GET['podcast_id'] ?? 123;
$scene_count = $_GET['scene_count'] ?? 5;

echo "<pre>";

// Check clips
echo "=== Scene Clips ===\n";
for ($i = 0; $i < $scene_count; $i++) {
    $path = __DIR__ . "/published_videos/podcast_{$podcast_id}_scene_{$i}.webm";
    if (file_exists($path)) {
        echo "scene $i — " . round(filesize($path)/1024/1024, 2) . " MB ✓\n";
    } else {
        echo "scene $i — MISSING ✗\n";
    }
}

// Check if curl is available (needed to call VPS)
echo "\n=== curl ===\n";
echo function_exists('curl_init') ? "curl: OK ✓\n" : "curl: NOT available ✗\n";

// Check if VPS is reachable
echo "\n=== VPS Reachability ===\n";
$ch = curl_init('http://187.124.249.46/videovizard.com/vps_stitch.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP code : $code\n";
echo "curl error: " . ($err ?: 'none') . "\n";
echo "Response  : " . substr($resp, 0, 200) . "\n";

// Check published_videos is writable
echo "\n=== Permissions ===\n";
echo "published_videos writable: " . (is_writable(__DIR__.'/published_videos/') ? "YES ✓" : "NO ✗") . "\n";

echo "</pre>";
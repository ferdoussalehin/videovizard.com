<?php
// test_convert.php
$VPS_URL    = 'http://187.124.249.46/videovizard.com/vps_convert.php';
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
$podcast_id = 279;
$webm_path  = '/home/syjy0p3q5yjb/public_html/videovizard.com/published_videos/podcast_' . $podcast_id . '.webm';
$mp4_path   = '/home/syjy0p3q5yjb/public_html/videovizard.com/published_videos/podcast_' . $podcast_id . '.mp4';

echo "<pre>";
echo "Step 1: Sending WebM to VPS...\n";
echo "File size: " . round(filesize($webm_path)/1024/1024, 2) . " MB\n\n";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => $VPS_URL,
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 600,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_HTTPHEADER     => ['Expect:'],
    CURLOPT_POSTFIELDS     => [
        'secret_key' => $SECRET_KEY,
        'action'     => 'convert',
        'podcast_id' => $podcast_id,
        'video'      => new CURLFile($webm_path, 'video/webm', 'podcast_' . $podcast_id . '.webm')
    ]
]);

$response  = curl_exec($curl);
$curl_err  = curl_error($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$time      = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
curl_close($curl);

echo "HTTP Code: $http_code\n";
echo "Time: {$time}s\n";
echo "cURL Error: " . ($curl_err ?: 'none') . "\n";
echo "VPS Response: $response\n\n";

$data = json_decode($response, true);

if ($data && $data['success'] && !empty($data['mp4_url'])) {
    echo "Step 2: Downloading MP4 from VPS...\n";
    $mp4_data = file_get_contents($data['mp4_url']);
    
    if ($mp4_data && strlen($mp4_data) > 1000) {
        file_put_contents($mp4_path, $mp4_data);
        echo "✅ MP4 saved! Size: " . round(filesize($mp4_path)/1024/1024, 2) . " MB\n";
        
        // Delete WebM
        @unlink($webm_path);
        echo "✅ WebM deleted from GoDaddy\n";
        
        // Cleanup VPS
        @file_get_contents($VPS_URL . '?action=cleanup&secret_key=' . urlencode($SECRET_KEY) .
            '&job_id=test&podcast_id=' . $podcast_id);
        echo "✅ VPS cleaned up\n";
    } else {
        echo "❌ Failed to download MP4\n";
    }
} else {
    echo "❌ VPS conversion failed\n";
}
echo "</pre>";
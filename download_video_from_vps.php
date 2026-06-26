<?php
// manual_download.php - place on GoDaddy root
$VPS_URL       = 'http://187.124.249.46/videovizard.com/vps_convert.php';
$SECRET_KEY    = 'VS_FFmpeg_2026_Secret!';
$PUBLISHED_DIR = '/home/syjy0p3q5yjb/public_html/videovizard.com/published_videos/';
$podcast_id    = 280;

echo "<pre>";
$mp4_url  = 'http://187.124.249.46/videovizard.com/published_videos/podcast_' . $podcast_id . '.mp4';
$mp4_path = $PUBLISHED_DIR . 'podcast_' . $podcast_id . '.mp4';
$webm_path = $PUBLISHED_DIR . 'podcast_' . $podcast_id . '.webm';

echo "Downloading MP4 from VPS...\n";
echo "URL: $mp4_url\n\n";

$mp4_data = file_get_contents($mp4_url);

if ($mp4_data && strlen($mp4_data) > 1000) {
    file_put_contents($mp4_path, $mp4_data);
    echo "✅ MP4 saved! Size: " . round(filesize($mp4_path)/1024/1024, 2) . " MB\n";
    
    @unlink($webm_path);
    echo "✅ WebM deleted from GoDaddy\n";

    // Cleanup VPS
    @file_get_contents($VPS_URL . '?action=cleanup&secret_key=' . urlencode($SECRET_KEY) .
        '&job_id=manual&podcast_id=' . $podcast_id);
    echo "✅ VPS cleaned up\n";
} else {
    echo "❌ Failed to download MP4\n";
}
echo "</pre>";
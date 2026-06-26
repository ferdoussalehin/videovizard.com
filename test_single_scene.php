<?php
/**
 * Test single video generation time
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';

define('SADTALKER_URL', 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run/generate');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');

// Get one scene
$result = mysqli_query($conn, "SELECT id, image_file, image_folder, audio_file FROM hdb_video_gen WHERE status = 'pending' LIMIT 1");
$scene = mysqli_fetch_assoc($result);

if (!$scene) {
    die("No pending scenes found");
}

$image_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';
$image_path = __DIR__ . '/' . $image_folder . '/' . $scene['image_file'];
$audio_path = PODCAST_AUDIOS_FOLDER . $scene['audio_file'];

echo "<h2>Testing Single Video Generation Time</h2>";
echo "<p>Scene ID: {$scene['id']}</p>";
echo "<p>Image: " . basename($image_path) . "</p>";
echo "<p>Audio: " . basename($audio_path) . "</p>";

$start_time = microtime(true);

$ch = curl_init();
$postData = [
    'source_image' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path)),
    'driven_audio' => new CURLFile($audio_path, mime_content_type($audio_path), basename($audio_path)),
    'preprocess' => 'full',
    'still_mode' => 'true',
    'use_enhancer' => 'false',
    'size' => '256',
    'batch_size' => '8',
    'head_motion' => 'true',
    'motion_intensity' => '1.0'
];

curl_setopt_array($ch, [
    CURLOPT_URL => SADTALKER_URL,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

$duration = round(microtime(true) - $start_time, 2);

echo "<h3>Results:</h3>";
echo "<p>HTTP Code: $http_code</p>";
echo "<p>Duration: " . floor($duration/60) . " minutes " . ($duration % 60) . " seconds ($duration seconds)</p>";

if ($curl_error) {
    echo "<p style='color:red'>Error: $curl_error</p>";
} elseif ($http_code == 200 && strlen($response) > 5000) {
    $filename = 'test_single_' . time() . '.mp4';
    file_put_contents(__DIR__ . '/user_videos/' . $filename, $response);
    echo "<p style='color:green'>✓ Video generated successfully!</p>";
    echo "<p>File size: " . round(strlen($response)/1024/1024, 2) . " MB</p>";
    echo "<video width='400' controls autoplay><source src='/user_videos/$filename' type='video/mp4'></video>";
} else {
    echo "<p style='color:red'>Failed: " . substr($response, 0, 200) . "</p>";
}

echo "<hr>";
echo "<h3>Estimated time for 7 scenes:</h3>";
echo "<p>If running sequentially: " . floor(($duration * 7)/60) . " minutes " . (($duration * 7) % 60) . " seconds</p>";
echo "<p>If running in parallel (theoretically): ~$duration seconds (same as one video)</p>";
echo "<p><a href='sadtalker_job_parallel.php'>Run Parallel Processing for all scenes →</a></p>";
?>
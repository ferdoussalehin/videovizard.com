<?php
/**
 * Reset failed scenes and retry them one by one with detailed logging
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 0);

include 'config.php';

define('SADTALKER_URL', 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run/generate');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

function logMessage($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'retry_debug_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
    echo $msg . "<br>";
}

echo "<h2>Reset and Retry Failed Scenes</h2>";

// First, let's see what scenes we have
$all_scenes = mysqli_query($conn, "SELECT id, status, phase, retry_count, max_retries, image_file, audio_file, error_msg FROM hdb_video_gen WHERE podcast_id = 312 ORDER BY id");

echo "<h3>Current Scenes Status:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Status</th><th>Phase</th><th>Retry/Max</th><th>Image</th><th>Audio</th><th>Error</th></tr>";

while ($row = mysqli_fetch_assoc($all_scenes)) {
    $color = $row['status'] == 'done' ? 'green' : ($row['status'] == 'failed' ? 'red' : 'orange');
    echo "<tr style='color:$color'>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['status']}</td>";
    echo "<td>{$row['phase']}</td>";
    echo "<td>{$row['retry_count']}/{$row['max_retries']}</td>";
    echo "<td>" . basename($row['image_file']) . "</td>";
    echo "<td>" . basename($row['audio_file']) . "</td>";
    echo "<td>" . htmlspecialchars(substr($row['error_msg'] ?? '', 0, 50)) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Reset failed scenes to pending
echo "<h3>Resetting failed scenes...</h3>";
mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', retry_count = 0, error_msg = NULL WHERE status = 'failed' AND podcast_id = 312");

// Get scenes to retry
$to_retry = mysqli_query($conn, "SELECT id, image_file, image_folder, audio_file, script_text FROM hdb_video_gen WHERE status = 'pending' AND podcast_id = 312 ORDER BY id");

if (mysqli_num_rows($to_retry) == 0) {
    die("No scenes to retry");
}

echo "<h3>Retrying scenes one by one:</h3>";

while ($scene = mysqli_fetch_assoc($to_retry)) {
    $scene_id = $scene['id'];
    $image_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';
    $image_path = __DIR__ . '/' . $image_folder . '/' . $scene['image_file'];
    $audio_path = PODCAST_AUDIOS_FOLDER . $scene['audio_file'];
    
    echo "<hr>";
    echo "<h4>Scene ID: $scene_id</h4>";
    
    // Check files
    $image_exists = file_exists($image_path);
    $audio_exists = file_exists($audio_path);
    
    echo "Image: " . basename($image_path) . " - " . ($image_exists ? "✓ EXISTS" : "✗ MISSING") . "<br>";
    echo "Audio: " . basename($audio_path) . " - " . ($audio_exists ? "✓ EXISTS" : "✗ MISSING") . "<br>";
    
    if (!$image_exists || !$audio_exists) {
        logMessage("Scene $scene_id: Missing files - skipping", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = 'Missing image or audio file' WHERE id = $scene_id");
        continue;
    }
    
    // Check file sizes
    $image_size = round(filesize($image_path) / 1024, 2);
    $audio_size = round(filesize($audio_path) / 1024, 2);
    echo "Image size: {$image_size} KB<br>";
    echo "Audio size: {$audio_size} KB<br>";
    
    // Update status to processing
    mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'processing', started_at = NOW() WHERE id = $scene_id");
    
    echo "Sending to SadTalker API...<br>";
    $start_time = microtime(true);
    
    // Prepare API request
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
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_VERBOSE => true
    ]);
    
    // Capture verbose output
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $duration = round(microtime(true) - $start_time, 2);
    
    rewind($verbose);
    $verbose_log = stream_get_contents($verbose);
    fclose($verbose);
    
    curl_close($ch);
    
    echo "HTTP Code: $http_code<br>";
    echo "Duration: {$duration} seconds<br>";
    
    if ($curl_error) {
        echo "<span style='color:red'>CURL Error: $curl_error</span><br>";
        logMessage("Scene $scene_id CURL Error: $curl_error", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = 'CURL: $curl_error' WHERE id = $scene_id");
        
        // Save verbose log for debugging
        $log_file = LOG_FOLDER . "curl_debug_{$scene_id}.log";
        file_put_contents($log_file, $verbose_log);
        echo "Verbose log saved to: $log_file<br>";
        
    } elseif ($http_code == 200) {
        $response_size = strlen($response);
        echo "Response size: " . round($response_size / 1024 / 1024, 2) . " MB<br>";
        
        if ($response_size < 5000) {
            echo "<span style='color:red'>Response too small! Possible error response.</span><br>";
            $error_preview = substr($response, 0, 500);
            echo "Response preview: " . htmlspecialchars($error_preview) . "<br>";
            mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = 'Response too small' WHERE id = $scene_id");
        } else {
            // Save video
            $filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
            $output_path = USER_VIDEOS_FOLDER . $filename;
            file_put_contents($output_path, $response);
            $file_size = round(filesize($output_path) / 1024 / 1024, 2);
            
            mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'done', phase = 'ffmpeg', output_file = '$filename', completed_at = NOW() WHERE id = $scene_id");
            
            echo "<span style='color:green'>✓ SUCCESS! Video saved: $filename ({$file_size}MB)</span><br>";
            echo "<video width='400' controls autoplay><source src='/user_videos/$filename' type='video/mp4'></video><br>";
        }
    } else {
        $error_response = json_decode($response, true);
        $error_msg = isset($error_response['detail']) ? $error_response['detail'] : "HTTP $http_code";
        echo "<span style='color:red'>API Error: $error_msg</span><br>";
        
        // Show full error response for debugging
        echo "<details><summary>Full error response</summary><pre>" . htmlspecialchars(substr($response, 0, 1000)) . "</pre></details><br>";
        
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = '$error_msg' WHERE id = $scene_id");
    }
    
    // Delay between retries
    echo "Waiting 3 seconds before next scene...<br>";
    sleep(3);
}

echo "<hr>"; 
echo "<h3>Final Status:</h3>";
$final = mysqli_query($conn, "SELECT status, COUNT(*) as count FROM hdb_video_gen WHERE podcast_id = 312 GROUP BY status");
while ($row = mysqli_fetch_assoc($final)) {
    echo "{$row['status']}: {$row['count']} scenes<br>";
}

echo "<br><a href='sadtalker_job_batch.php'>Run Batch Processor →</a>";
?>
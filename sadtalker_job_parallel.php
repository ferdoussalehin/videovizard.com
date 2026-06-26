<?php
/**
 * SadTalker Video Generator - Batch Parallel Processing
 * Processes scenes in small batches to avoid API overload
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

if (!$conn) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed']));
}

// Configuration
define('SADTALKER_URL', 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run/generate');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');
define('BATCH_SIZE', 3); // Process 3 at a time
define('DELAY_BETWEEN_BATCHES', 5); // 5 second delay between batches

if (!file_exists(USER_VIDEOS_FOLDER)) mkdir(USER_VIDEOS_FOLDER, 0755, true);
if (!file_exists(LOG_FOLDER)) mkdir(LOG_FOLDER, 0755, true);

function logMessage($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'sadtalker_batch_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$batch_size = isset($_GET['batch_size']) ? (int)$_GET['batch_size'] : BATCH_SIZE;

// Get ALL pending scenes
$sql = "SELECT id, podcast_id, story_id, image_file, image_folder, audio_file, retry_count, max_retries
        FROM hdb_video_gen 
        WHERE status = 'pending' 
        AND phase = 'sadtalker'
        AND image_file IS NOT NULL 
        AND image_file != ''
        AND audio_file IS NOT NULL 
        AND audio_file != ''";

if ($podcast_id > 0) {
    $sql .= " AND podcast_id = $podcast_id";
}

$sql .= " ORDER BY id ASC";

$result = mysqli_query($conn, $sql);
$all_scenes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $image_folder = !empty($row['image_folder']) ? $row['image_folder'] : 'podcast_images';
    $image_path = __DIR__ . '/' . $image_folder . '/' . $row['image_file'];
    $audio_path = PODCAST_AUDIOS_FOLDER . $row['audio_file'];
    
    if (file_exists($image_path) && file_exists($audio_path)) {
        $row['image_path'] = $image_path;
        $row['audio_path'] = $audio_path;
        $all_scenes[] = $row;
    } else {
        logMessage("Scene {$row['id']} skipped - missing files", 'WARNING');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = 'Missing image or audio file' WHERE id = {$row['id']}");
    }
}

if (empty($all_scenes)) {
    echo json_encode(['success' => true, 'message' => 'No pending scenes found', 'total' => 0]);
    exit;
}

$total_scenes = count($all_scenes);
logMessage("Total scenes to process: $total_scenes", 'INFO');

// Split into batches
$batches = array_chunk($all_scenes, $batch_size);
$all_results = [];
$total_start_time = microtime(true);

foreach ($batches as $batch_num => $batch) {
    $batch_start = microtime(true);
    logMessage("Processing batch " . ($batch_num + 1) . " of " . count($batches) . " (" . count($batch) . " scenes)", 'INFO');
    
    // Update scenes in this batch to processing
    foreach ($batch as $scene) {
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'processing', started_at = NOW() WHERE id = {$scene['id']}");
    }
    
    // Process batch in parallel
    $multi_handle = curl_multi_init();
    $curl_handles = [];
    $scene_data = [];
    
    foreach ($batch as $scene) {
        $ch = curl_init();
        
        $postData = [
            'source_image' => new CURLFile($scene['image_path'], mime_content_type($scene['image_path']), basename($scene['image_path'])),
            'driven_audio' => new CURLFile($scene['audio_path'], mime_content_type($scene['audio_path']), basename($scene['audio_path'])),
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
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        curl_multi_add_handle($multi_handle, $ch);
        $curl_handles[(int)$ch] = $ch;
        $scene_data[(int)$ch] = [
            'scene' => $scene,
            'start_time' => microtime(true)
        ];
    }
    
    // Execute batch
    $running = null;
    do {
        curl_multi_exec($multi_handle, $running);
        curl_multi_select($multi_handle);
    } while ($running > 0);
    
    // Process batch results
    foreach ($curl_handles as $ch) {
        $ch_id = (int)$ch;
        $scene_info = $scene_data[$ch_id];
        $scene = $scene_info['scene'];
        $scene_id = $scene['id'];
        $start_time = $scene_info['start_time'];
        
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $duration = round(microtime(true) - $start_time, 2);
        
        curl_multi_remove_handle($multi_handle, $ch);
        curl_close($ch);
        
        if ($curl_error) {
            handleFailure($conn, $scene, "CURL Error: $curl_error", $duration);
            $all_results[] = ['success' => false, 'scene_id' => $scene_id, 'duration' => $duration, 'error' => $curl_error];
        } elseif ($http_code !== 200) {
            $errorData = json_decode($response, true);
            $error_msg = isset($errorData['detail']) ? $errorData['detail'] : "HTTP $http_code";
            handleFailure($conn, $scene, $error_msg, $duration);
            $all_results[] = ['success' => false, 'scene_id' => $scene_id, 'duration' => $duration, 'error' => $error_msg];
        } elseif (strlen($response) < 5000) {
            handleFailure($conn, $scene, "Response too small (" . strlen($response) . " bytes)", $duration);
            $all_results[] = ['success' => false, 'scene_id' => $scene_id, 'duration' => $duration, 'error' => 'Response too small'];
        } else {
            $output_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
            $output_path = USER_VIDEOS_FOLDER . $output_filename;
            file_put_contents($output_path, $response);
            $file_size = round(filesize($output_path) / 1024 / 1024, 2);
            
            mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'done', phase = 'ffmpeg', output_file = '$output_filename', completed_at = NOW() WHERE id = $scene_id");
            
            logMessage("Scene $scene_id ✓ COMPLETED in {$duration}s ({$file_size}MB)", 'SUCCESS');
            $all_results[] = ['success' => true, 'scene_id' => $scene_id, 'duration' => $duration, 'output_file' => $output_filename, 'size_mb' => $file_size];
        }
    }
    
    curl_multi_close($multi_handle);
    
    $batch_duration = round(microtime(true) - $batch_start, 2);
    logMessage("Batch " . ($batch_num + 1) . " completed in {$batch_duration}s", 'INFO');
    
    // Delay between batches (except after last batch)
    if ($batch_num < count($batches) - 1) {
        logMessage("Waiting " . DELAY_BETWEEN_BATCHES . " seconds before next batch...", 'INFO');
        sleep(DELAY_BETWEEN_BATCHES);
    }
}

function handleFailure($conn, $scene, $error_msg, $duration) {
    $scene_id = $scene['id'];
    $error_esc = mysqli_real_escape_string($conn, $error_msg);
    
    // Increment retry count
    mysqli_query($conn, "UPDATE hdb_video_gen SET retry_count = retry_count + 1 WHERE id = $scene_id");
    
    $retry_result = mysqli_query($conn, "SELECT retry_count, max_retries FROM hdb_video_gen WHERE id = $scene_id");
    $retry_data = mysqli_fetch_assoc($retry_result);
    
    if ($retry_data && $retry_data['retry_count'] >= $retry_data['max_retries']) {
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = '$error_esc' WHERE id = $scene_id");
        logMessage("Scene $scene_id ✗ FAILED after {$duration}s (max retries): $error_msg", 'ERROR');
    } else {
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', error_msg = '$error_esc' WHERE id = $scene_id");
        logMessage("Scene $scene_id ✗ FAILED after {$duration}s (will retry): $error_msg", 'WARNING');
    }
}

$total_duration = round(microtime(true) - $total_start_time, 2);
$successful = array_filter($all_results, function($r) { return $r['success']; });
$failed = array_filter($all_results, function($r) { return !$r['success']; });

$summary = [
    'success' => true,
    'total_scenes' => $total_scenes,
    'successful' => count($successful),
    'failed' => count($failed),
    'batch_size' => $batch_size,
    'total_batches' => count($batches),
    'total_time_seconds' => $total_duration,
    'average_time_per_scene' => round($total_duration / $total_scenes, 2),
    'results' => $all_results
];

header('Content-Type: application/json');
echo json_encode($summary, JSON_PRETTY_PRINT);

logMessage("=== FINAL SUMMARY: $total_scenes scenes, " . count($successful) . " successful, " . count($failed) . " failed, Total time: {$total_duration}s ===", 'INFO');

mysqli_close($conn);
?>
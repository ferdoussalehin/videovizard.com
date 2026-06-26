<?php
/**
 * Smart Video Processor
 * When video is complete: Updates BOTH hdb_video_gen AND hdb_podcast_stories
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

// Configuration


define('SADTALKER_URL', 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run/generate');
//define('SADTALKER_URL', 'https://inaamalvi1--automated-sadtalker-916-fastapi-app.modal.run/');



define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

function smartLog($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'smart_processor_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// Get pending scenes
//AND phase = 'sadtalker'  
$pending = mysqli_query($conn, 
    "SELECT id, story_id, podcast_id, image_file, image_folder, audio_file 
     FROM hdb_video_gen 
     WHERE status = 'pending' 
     ORDER BY id ASC LIMIT 5");

if (mysqli_num_rows($pending) == 0) {
    echo json_encode(['success' => true, 'message' => 'No pending scenes']);
    exit;
}

$success_count = 0;

while ($scene = mysqli_fetch_assoc($pending)) {
    $scene_id = $scene['id'];
    $story_id = $scene['story_id'];
    $podcast_id = $scene['podcast_id'];
    
    // Get image and audio paths
    $image_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';
    $image_path = __DIR__ . '/' . $image_folder . '/' . $scene['image_file'];
    $audio_path = PODCAST_AUDIOS_FOLDER . $scene['audio_file'];
    
    smartLog("Processing scene $scene_id (story: $story_id)", 'INFO');
    
    // Update status to processing
    mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'processing', started_at = NOW() WHERE id = $scene_id");
    
    // Send to SadTalker API
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
    curl_close($ch);
    
    if ($http_code == 200 && strlen($response) > 10000) {
        // Video generated successfully
        $video_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
        $output_path = USER_VIDEOS_FOLDER . $video_filename;
        file_put_contents($output_path, $response);
        
        // STEP 1: Update hdb_video_gen
        mysqli_query($conn, "UPDATE hdb_video_gen 
            SET status = 'done', 
                phase = 'complete',
                output_file = '$video_filename',
                completed_at = NOW() 
            WHERE id = $scene_id");
        
        // STEP 2: Update hdb_podcast_stories using story_id
        // Update image_file with video filename and image_folder with '/user_videos/'
        mysqli_query($conn, "UPDATE hdb_podcast_stories 
            SET image_file = '$video_filename',
                image_folder = '/user_videos/'
            WHERE id = $story_id AND podcast_id = $podcast_id");
        
        smartLog("✓ Scene $scene_id COMPLETE - Updated story $story_id with video: /user_videos/$video_filename", 'SUCCESS');
        $success_count++;
        
    } else {
        // Failed - will retry
        mysqli_query($conn, "UPDATE hdb_video_gen 
            SET status = 'pending', 
                retry_count = retry_count + 1,
                error_msg = 'HTTP $http_code' 
            WHERE id = $scene_id");
        smartLog("✗ Scene $scene_id FAILED - will retry", 'ERROR');
    }
    
    sleep(2); // Delay between scenes
}

echo json_encode([
    'success' => true,
    'processed' => mysqli_num_rows($pending),
    'successful' => $success_count
]);

mysqli_close($conn);
?>
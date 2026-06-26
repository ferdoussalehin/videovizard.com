<?php
/**
 * SadTalker Video Generator - Working with your actual table structure
 * Your table has: script_text column (not text)
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Simple response function
function sendResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// Include config
include 'config.php';

// Check database connection
if (!$conn) {
    sendResponse(false, 'Database connection failed: ' . mysqli_connect_error());
}

// Set charset
mysqli_set_charset($conn, 'utf8mb4');

// Configuration
define('SADTALKER_URL', 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER', __DIR__ . '/logs/');

// Create folders if needed
if (!file_exists(USER_VIDEOS_FOLDER)) mkdir(USER_VIDEOS_FOLDER, 0755, true);
if (!file_exists(LOG_FOLDER)) mkdir(LOG_FOLDER, 0755, true);

function logMessage($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'sadtalker_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// Get parameters
$action = isset($_GET['action']) ? $_GET['action'] : '';
$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

// Handle retry action
if ($action === 'retry') {
    logMessage("Retrying failed scenes", 'INFO');
    mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', phase = 'sadtalker', error_msg = NULL WHERE status = 'failed'");
    sendResponse(true, 'Reset failed scenes for retry');
}

// Get scenes to process - USING CORRECT COLUMN NAMES
$sql = "SELECT id, podcast_id, story_id, admin_id, team_lead_id,
               image_file, image_folder, audio_file,
               script_text, status, phase, retry_count, max_retries, output_file
        FROM hdb_video_gen 
        WHERE status = 'pending' 
        AND phase = 'sadtalker'
        AND script_text IS NOT NULL 
        AND script_text != ''
        AND image_file IS NOT NULL 
        AND image_file != ''
        AND retry_count < max_retries
        ORDER BY id ASC 
        LIMIT 10";

if ($podcast_id > 0) {
    $sql = "SELECT id, podcast_id, story_id, admin_id, team_lead_id,
                   image_file, image_folder, audio_file,
                   script_text, status, phase, retry_count, max_retries, output_file
            FROM hdb_video_gen 
            WHERE status = 'pending' 
            AND phase = 'sadtalker'
            AND podcast_id = $podcast_id
            AND script_text IS NOT NULL 
            AND script_text != ''
            AND image_file IS NOT NULL 
            AND image_file != ''
            AND retry_count < max_retries
            ORDER BY id ASC 
            LIMIT 10";
}

logMessage("Executing query for pending scenes", 'INFO');
$result = mysqli_query($conn, $sql);

if (!$result) {
    sendResponse(false, 'Query failed: ' . mysqli_error($conn));
}

$scenes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $scenes[] = $row;
}

if (empty($scenes)) {
    // Get counts for debug
    $total_pending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM hdb_video_gen WHERE status = 'pending'"));
    $with_script = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM hdb_video_gen WHERE script_text IS NOT NULL AND script_text != ''"));
    $with_image = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM hdb_video_gen WHERE image_file IS NOT NULL AND image_file != ''"));
    
    sendResponse(true, 'No pending scenes found', [
        'total_pending' => $total_pending['count'],
        'with_script_text' => $with_script['count'],
        'with_image' => $with_image['count']
    ]);
}

logMessage("Found " . count($scenes) . " scenes to process", 'INFO');

$results = [];
foreach ($scenes as $scene) {
    $scene_id = $scene['id'];
    $script_text = $scene['script_text'];
    $image_file = $scene['image_file'];
    $image_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';
    
    // Build image path
    $image_path = __DIR__ . '/' . $image_folder . '/' . $image_file;
    
    // Try alternative paths if not found
    if (!file_exists($image_path)) {
        $alt_paths = [
            __DIR__ . '/podcast_images/' . $image_file,
            __DIR__ . '/images/' . $image_file,
            __DIR__ . '/uploads/' . $image_file
        ];
        
        foreach ($alt_paths as $alt) {
            if (file_exists($alt)) {
                $image_path = $alt;
                break;
            }
        }
    }
    
    // Validate image exists
    if (!file_exists($image_path)) {
        $error = "Image not found: " . $image_file;
        logMessage("Scene $scene_id ERROR: $error (looked in: $image_path)", 'ERROR');
        
        $error_esc = mysqli_real_escape_string($conn, $error);
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = '$error_esc' WHERE id = $scene_id");
        
        $results[] = ['success' => false, 'scene_id' => $scene_id, 'error' => $error];
        continue;
    }
    
    // Validate script text
    if (empty($script_text)) {
        $error = "No script text found for scene $scene_id";
        logMessage("Scene $scene_id ERROR: $error", 'ERROR');
        
        $error_esc = mysqli_real_escape_string($conn, $error);
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = '$error_esc' WHERE id = $scene_id");
        
        $results[] = ['success' => false, 'scene_id' => $scene_id, 'error' => $error];
        continue;
    }
    
    $output_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
    $output_path = USER_VIDEOS_FOLDER . $output_filename;
    
    logMessage("Processing scene $scene_id: Image=$image_file, Script=" . substr($script_text, 0, 100) . "...", 'INFO');
    
    // Update status to processing
    mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'processing', started_at = NOW() WHERE id = $scene_id");
    
    try {
        // Generate video using SadTalker API
        $ch = curl_init();
        
        $postData = [
            'source_image' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path)),
            'input_text' => $script_text,
            'preprocess' => 'crop',
            'still_mode' => 'true',
            'size' => '256'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => SADTALKER_URL . '/generate',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("CURL Error: $curl_error");
        }
        
        if ($httpCode != 200) {
            $errorData = json_decode($response, true);
            $errorMsg = isset($errorData['detail']) ? $errorData['detail'] : "HTTP $httpCode";
            throw new Exception("API Error: $errorMsg");
        }
        
        // Save video
        file_put_contents($output_path, $response);
        
        // Update status to done
        $update = "UPDATE hdb_video_gen SET 
                   status = 'done', 
                   phase = 'ffmpeg',
                   output_file = '$output_filename', 
                   completed_at = NOW() 
                   WHERE id = $scene_id";
        mysqli_query($conn, $update);
        
        logMessage("Scene $scene_id completed: $output_filename", 'SUCCESS');
        $results[] = [
            'success' => true,
            'scene_id' => $scene_id,
            'output_file' => $output_filename
        ];
        
    } catch (Exception $e) {
        $error_msg = mysqli_real_escape_string($conn, $e->getMessage());
        logMessage("Scene $scene_id failed: $error_msg", 'ERROR');
        
        // Increment retry count
        mysqli_query($conn, "UPDATE hdb_video_gen SET retry_count = retry_count + 1 WHERE id = $scene_id");
        
        // Get updated retry count
        $retry_result = mysqli_query($conn, "SELECT retry_count, max_retries FROM hdb_video_gen WHERE id = $scene_id");
        $retry_data = mysqli_fetch_assoc($retry_result);
        
        if ($retry_data && $retry_data['retry_count'] >= $retry_data['max_retries']) {
            // Max retries reached
            mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = '$error_msg' WHERE id = $scene_id");
            logMessage("Scene $scene_id reached max retries, marked as failed", 'WARNING');
        } else {
            // Reset to pending for retry
            mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', error_msg = '$error_msg' WHERE id = $scene_id");
            logMessage("Scene $scene_id will be retried (attempt {$retry_data['retry_count']}/{$retry_data['max_retries']})", 'INFO');
        }
        
        $results[] = [
            'success' => false,
            'scene_id' => $scene_id,
            'error' => $e->getMessage()
        ];
    }
    
    // Delay between requests
    sleep(2);
}

// Send response
$success_count = 0;
foreach ($results as $r) {
    if ($r['success']) $success_count++;
}

sendResponse(true, 'Processing complete', [
    'total' => count($scenes),
    'successful' => $success_count,
    'failed' => count($scenes) - $success_count,
    'results' => $results
]);

mysqli_close($conn);
?>
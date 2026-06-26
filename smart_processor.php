<?php
/**
 * Smart Video Processor - UPDATED
 * When video is complete: Updates BOTH hdb_video_gen AND hdb_podcast_stories
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

// Configuration
define('SADTALKER_URL', 'https://inaamalvi1--automated-sadtalker-916-fastapi-app.modal.run/generate');
define('SADTALKER_BASE', 'https://inaamalvi1--automated-sadtalker-916-fastapi-app.modal.run');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

function smartLog($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'smart_processor_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// ─── RECOVERY: Reset stuck 'processing' rows older than 20 minutes ───────────
mysqli_query($conn,
    "UPDATE hdb_video_gen 
     SET status = 'pending' 
     WHERE status = 'processing' 
     AND started_at < NOW() - INTERVAL 20 MINUTE");

$recovered = mysqli_affected_rows($conn);
if ($recovered > 0) {
    smartLog("Recovered $recovered stuck 'processing' rows back to 'pending'", 'WARN');
}

// ─── Get pending scenes (skip rows that exceeded max_retries) ─────────────────
$pending = mysqli_query($conn,
    "SELECT id, story_id, podcast_id, image_file, image_folder, audio_file,
            retry_count, max_retries
     FROM hdb_video_gen 
     WHERE status = 'pending' 
     AND (max_retries IS NULL OR retry_count < max_retries)
     ORDER BY id ASC LIMIT 5");

if (!$pending || mysqli_num_rows($pending) == 0) {
    echo json_encode(['success' => true, 'message' => 'No pending scenes']);
    exit;
}


$success_count = 0;

while ($scene = mysqli_fetch_assoc($pending)) {
    $scene_id  = $scene['id'];
    $story_id  = $scene['story_id'];
    $podcast_id = $scene['podcast_id'];

    // Phase 1: SadTalker — image_file aur image_folder use karo
    $img_file   = $scene['image_file'];
    $img_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';

    $image_path = __DIR__ . '/' . $img_folder . '/' . $img_file;
    $audio_path = PODCAST_AUDIOS_FOLDER . $scene['audio_file'];



    // File existence check
    if (!file_exists($image_path) || !file_exists($audio_path)) {
        $missing = !file_exists($image_path) ? "image: $image_path" : "audio: $audio_path";
        smartLog("Scene $scene_id - File missing: $missing", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'failed', error_msg = 'Files missing' WHERE id = $scene_id");
        continue;
    }

    smartLog("Processing scene $scene_id (story: $story_id)", 'INFO');
    mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'processing', started_at = NOW() WHERE id = $scene_id");

    // ─── STEP 1: Send to SadTalker API with Retry Logic (handles 503 cold-start)
    $max_retries = 3;
    $retry_delay = 30; // seconds to wait before retrying
    $response    = '';
    $http_code   = 0;

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL        => SADTALKER_URL,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => [
                'source_image' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path)),
                'driven_audio' => new CURLFile($audio_path, mime_content_type($audio_path), basename($audio_path)),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 900,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            // Success — break out of retry loop
            break;
        }

        if ($http_code === 503) {
            // Modal cold-start — wait and retry
            smartLog("Scene $scene_id - 503 cold-start (attempt $attempt/$max_retries). Waiting {$retry_delay}s...", 'WARN');
            sleep($retry_delay);
        } else {
            // Some other error — no point retrying
            break;
        }
    }

    if ($http_code !== 200) {
        smartLog("Scene $scene_id - API failed after $max_retries attempts. Last HTTP: $http_code", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', retry_count = retry_count + 1, error_msg = 'HTTP $http_code after retries' WHERE id = $scene_id");
        sleep(2);
        continue;
    }

    // ─── STEP 2: Extract video URL from response ─────────────────────────────
    // New API returns JSON like: {"video_url": "/video/abc123"} OR raw binary
    $video_download_url = null;

    $json = json_decode($response, true);
    if ($json && isset($json['video_url'])) {
        // Response is JSON with a URL path
        $video_download_url = SADTALKER_BASE . $json['video_url'];
        smartLog("Scene $scene_id - Got video URL: $video_download_url", 'INFO');
    } elseif (strlen($response) > 10000) {
        // Response is raw binary video data (old-style fallback)
        $video_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
        $output_path    = USER_VIDEOS_FOLDER . $video_filename;
        file_put_contents($output_path, $response);

        // Update DB directly
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'done', phase = 'complete', output_file = '$video_filename', completed_at = NOW() WHERE id = $scene_id");
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = '$video_filename', image_folder = '/user_videos/' WHERE id = $story_id AND podcast_id = $podcast_id");
        smartLog("✓ Scene $scene_id COMPLETE (binary) - /user_videos/$video_filename", 'SUCCESS');
        $success_count++;
        sleep(2);
        continue;
    } else {
        smartLog("Scene $scene_id - Unexpected response: " . substr($response, 0, 200), 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', retry_count = retry_count + 1, error_msg = 'Bad response' WHERE id = $scene_id");
        sleep(2);
        continue;
    }

    // ─── STEP 3: Download actual MP4 from the returned URL ───────────────────
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL            => $video_download_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $video_data      = curl_exec($ch2);
    $download_code   = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($download_code === 200 && strlen($video_data) > 10000) {
        $video_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
        $output_path    = USER_VIDEOS_FOLDER . $video_filename;
        file_put_contents($output_path, $video_data);

        // Update hdb_video_gen
        mysqli_query($conn, "UPDATE hdb_video_gen 
            SET status = 'done', 
                phase = 'complete',
                output_file = '$video_filename',
                completed_at = NOW() 
            WHERE id = $scene_id");

        // Update hdb_podcast_stories
        mysqli_query($conn, "UPDATE hdb_podcast_stories 
            SET image_file = '$video_filename',
                image_folder = '/user_videos/'
            WHERE id = $story_id AND podcast_id = $podcast_id");

        smartLog("✓ Scene $scene_id COMPLETE - /user_videos/$video_filename", 'SUCCESS');
        $success_count++;

    } else {
        smartLog("Scene $scene_id - Video download failed: HTTP $download_code", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_video_gen SET status = 'pending', retry_count = retry_count + 1, error_msg = 'Download failed HTTP $download_code' WHERE id = $scene_id");
    }

    sleep(2);
}

echo json_encode([
    'success'    => true,
    'processed'  => mysqli_num_rows($pending),
    'successful' => $success_count
]);

mysqli_close($conn);
?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

// --- LTX-2 Engine Modal Configuration ---
define('SADTALKER_URL', 'https://inaamwithalvi1958--prompt-to-video-ltx-fastapi-app.modal.run/generate');
define('MODAL_API_KEY', 'sk-wan-video-studio-8293-f2a1'); 
define('MODAL_BASE_URL', 'https://inaamwithalvi1958--prompt-to-video-ltx-fastapi-app.modal.run');

define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

function smartLog($msg, $type = 'INFO') {
    if (!file_exists(LOG_FOLDER)) {
        @mkdir(LOG_FOLDER, 0777, true);
    }
    $logFile = LOG_FOLDER . 'ltx_processor_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
    // If CLI mode, print to stdout as well
    if (php_sapi_name() === 'cli') {
        echo $entry;
    }
}

function ensure_db_connection() {
    global $conn;
    if (!@mysqli_ping($conn)) {
        smartLog("DB disconnected. Reconnecting...", "WARN");
        @mysqli_close($conn);
        include 'config.php'; 
    }
}

ensure_db_connection();

// 1. Guard against parallel processing
$active_check = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 2 LIMIT 1");
if ($active_check && mysqli_num_rows($active_check) > 0) {
    $active_row = mysqli_fetch_assoc($active_check);
    $active_id = (int)$active_row['id'];
    smartLog("Another podcast (ID: $active_id) is currently generating. Exiting to prevent parallel processing.", "INFO");
    echo json_encode([
        'success' => false,
        'message' => "Podcast ID: $active_id is currently generating video. Exiting to prevent concurrency.",
        'active_processing_id' => $active_id
    ]);
    exit;
}

// 2. Fetch the next pending podcast
$podcast_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");

if (!$podcast_res || mysqli_num_rows($podcast_res) == 0) {
    // Check for recently completed podcasts (videogen_flag = 0)
    $completed_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 0 ORDER BY id DESC LIMIT 5");
    $completed_ids = [];
    if ($completed_res) {
        while ($row = mysqli_fetch_assoc($completed_res)) {
            $completed_ids[] = (int)$row['id'];
        }
    }

    echo json_encode([
        'success' => true, 
        'message' => 'No podcasts pending for video generation',
        'active_processing_ids' => [],
        'recent_completed_ids' => $completed_ids
    ]);
    exit;
}

$podcast = mysqli_fetch_assoc($podcast_res);
$podcast_id = (int)$podcast['id'];

smartLog("Processing Podcast ID: $podcast_id via LTX Engine", 'INFO');

// Mark podcast as processing
mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 2 WHERE id = $podcast_id");

// 3. Read stories
$stories_res = mysqli_query($conn, "SELECT id, video_prompt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id");

if (!$stories_res || mysqli_num_rows($stories_res) == 0) {
    smartLog("No stories found for Podcast ID: $podcast_id", 'WARN');
    mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 0 WHERE id = $podcast_id"); 
    echo json_encode(['success' => true, 'message' => 'No stories for this podcast', 'podcast_id' => $podcast_id]);
    exit;
}

$success_count = 0;
$total_stories = mysqli_num_rows($stories_res);
smartLog("Found $total_stories stories for Podcast ID: $podcast_id", 'INFO');

while ($story = mysqli_fetch_assoc($stories_res)) {
    $story_id = $story['id'];
    $prompt = trim($story['video_prompt']);

    if (empty($prompt)) {
        smartLog("Story $story_id has no prompt, skipping.", 'WARN');
        continue;
    }

    ensure_db_connection();
    smartLog("Generating video for Story $story_id (Podcast $podcast_id) using LTX Engine...", 'INFO');

    // --- Call Modal API (LTX Mode) ---
    $postData = [
        'mode'   => 'ltx',
        'prompt' => $prompt
    ];

    $ch = curl_init(SADTALKER_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 1800 
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || !$response) {
        smartLog("Modal API Error for Story $story_id: $curl_err", 'ERROR');
        continue;
    }

    // --- Parse JSON Stream for video_url ---
    $final_video_url = '';
    $lines = explode("\n", trim($response));
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $data = json_decode($line, true);
        if (isset($data['video_url'])) {
            $final_video_url = MODAL_BASE_URL . $data['video_url'];
            break;
        }
    }

    if (empty($final_video_url)) {
        smartLog("Failed to get video URL for Story $story_id. Response: " . substr($response, 0, 100), 'ERROR');
        continue;
    }

    // --- Download Video ---
    $video_filename = 'ltx_' . $podcast_id . '_' . $story_id . '.mp4';
    if (!file_exists(USER_VIDEOS_FOLDER)) {
        @mkdir(USER_VIDEOS_FOLDER, 0777, true);
    }
    $save_path = USER_VIDEOS_FOLDER . $video_filename;

    $dl = curl_init($final_video_url);
    $fp = fopen($save_path, 'wb');
    curl_setopt_array($dl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    curl_exec($dl);
    curl_close($dl);
    fclose($fp);

    $file_size = file_exists($save_path) ? filesize($save_path) : 0;
    if ($file_size < 1000) {
        smartLog("Download failed or file too small for Story $story_id", 'ERROR');
        continue;
    }

    // --- Update Database ---
    ensure_db_connection();
    $safe_filename = mysqli_real_escape_string($conn, $video_filename);
    
    $update_res = mysqli_query($conn, "UPDATE hdb_podcast_stories 
                         SET image_file = '$safe_filename', image_folder = '/user_videos/' 
                         WHERE id = $story_id");

    if ($update_res) {
        $success_count++;
        smartLog("Story $story_id completed using LTX Engine: $video_filename", 'SUCCESS');
    } else {
        smartLog("Failed to update database for Story $story_id", 'ERROR');
    }
}

// Reset processing flag to 0 (done)
mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 0 WHERE id = $podcast_id");

echo json_encode([
    'success' => true,
    'message' => 'Podcast processed successfully',
    'processed_stories' => $success_count,
    'total_stories' => $total_stories,
    'podcast_id' => $podcast_id,
    'engine' => 'ltx'
]);
?>
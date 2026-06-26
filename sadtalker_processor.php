<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

// Base URL
define('SADTALKER_URL', 'https://irfan-growen--automated-sadtalker-4k-revert-fastapi-app.modal.run/generate');
define('MODAL_API_KEY', 'sad-tk-8yNcfGp152Qf');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

function smartLog($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'smart_processor_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// Function to ensure DB is connected before any query (Auto-Reconnect)
function ensure_db_connection() {
    global $conn;
    if (!@mysqli_ping($conn)) {
        smartLog("DB disconnected. Reconnecting...", "WARN");
        @mysqli_close($conn);
        include 'config.php'; // Reconnects DB
    }
}

ensure_db_connection();

$pending = mysqli_query($conn,
    "SELECT id, story_id, podcast_id, image_file, image_folder, audio_file, video_type
     FROM hdb_video_gen
     WHERE status = 'pending'
     ORDER BY id ASC LIMIT 5");

if (!$pending) {
    smartLog("DB query failed: " . mysqli_error($conn), 'ERROR');
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit;
}

if (mysqli_num_rows($pending) == 0) {
    echo json_encode(['success' => true, 'message' => 'No pending scenes']);
    exit;
}

// Sub rows ko pehle array mein save kar lein taake DB timeout result-set ko kharab na kare
$scenes_to_process = [];
while ($scene = mysqli_fetch_assoc($pending)) {
    $scenes_to_process[] = $scene;
}

$success_count = 0;

foreach ($scenes_to_process as $scene) {
    $scene_id = $scene['id'];
    $story_id  = $scene['story_id'];
    $v_type    = trim($scene['video_type']);

    ensure_db_connection();

    smartLog("Scene $scene_id: video_type='$v_type'", 'INFO');
    mysqli_query($conn, "UPDATE hdb_video_gen SET status='processing' WHERE id=$scene_id");

    if ($v_type == 'sadtalker') {

        $image_folder = !empty($scene['image_folder']) ? $scene['image_folder'] : 'podcast_images';
        $image_path   = __DIR__ . '/' . $image_folder . '/' . $scene['image_file'];
        $audio_path   = PODCAST_AUDIOS_FOLDER . $scene['audio_file'];

        if (!file_exists($image_path)) {
            ensure_db_connection();
            mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='image not found' WHERE id=$scene_id");
            continue;
        }

        if (!file_exists($audio_path)) {
            ensure_db_connection();
            mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='audio not found' WHERE id=$scene_id");
            continue;
        }

        // --- Call Modal API ---
        $postData = [
            'source_image' => new CURLFile($image_path),
            'driven_audio' => new CURLFile($audio_path),
            'pose_style'   => '12',
            'exp_scale'    => '1.1',
            'still_mode'   => 'false'
        ];

        $ch = curl_init(SADTALKER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 1800 // Timeout 30 mins tak badha diya hai
        ]);

        smartLog("Sending request to Modal for scene $scene_id... This may take a while.", 'INFO');
        $response  = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_err || !$response) {
            ensure_db_connection();
            mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='curl_error' WHERE id=$scene_id");
            continue;
        }

        // --- Parse JSON ---
        $final_video_url = '';
        $lines = explode("\n", trim($response));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $data = json_decode($line, true);
            if (isset($data['video_url'])) {
                $final_video_url = 'https://irfan-growen--automated-sadtalker-4k-revert-fastapi-app.modal.run' . $data['video_url'];
                break;
            }
        }

        if (empty($final_video_url)) {
            ensure_db_connection();
            mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='no_video_url' WHERE id=$scene_id");
            continue;
        }

        // --- Download Video ---
        $video_filename = 'sadtalker_' . $scene_id . '_' . time() . '.mp4';
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
            ensure_db_connection();
            mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='download_failed' WHERE id=$scene_id");
            continue;
        }

        // --- Update DB (status = completed) ---
        ensure_db_connection(); // UPDATE se foran pehle dobara check
        $safe_filename = mysqli_real_escape_string($conn, $video_filename);
        
        mysqli_query($conn, "UPDATE hdb_video_gen SET status='completed', output_file='$safe_filename' WHERE id=$scene_id");
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$safe_filename', image_folder='/user_videos/' WHERE id=$story_id");

        $success_count++;
        smartLog("Scene $scene_id done: $video_filename. Status marked as completed.", 'SUCCESS');

    } else {
        ensure_db_connection();
        mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed', error_msg='unknown_type' WHERE id=$scene_id");
    }
}

echo json_encode(['success' => true, 'processed' => $success_count]);
?>
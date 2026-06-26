<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

$script_start_time = date('Y-m-d H:i:s');
include 'config.php';

define('SADTALKER_URL', 'https://irfan-growen--automated-sadtalker-4k-revert-fastapi-app.modal.run/generate');
define('MODAL_API_KEY', 'sad-tk-8yNcfGp152Qf');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('PODCAST_AUDIOS_FOLDER', __DIR__ . '/podcast_audios/');
define('LOG_FOLDER', __DIR__ . '/logs/');

if (!is_dir(LOG_FOLDER)) mkdir(LOG_FOLDER, 0777, true);

function smartLog($msg, $type = 'INFO') {
    $logFile = LOG_FOLDER . 'debug_log_' . date('Y-m-d') . '.log';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

function ensure_db_connection() {
    global $conn;
    if (!$conn || !@mysqli_ping($conn)) {
        smartLog("Attempting to reconnect to DB...", "WARN");
        include 'config.php';
    }
}

ensure_db_connection();

// Row uthana
$res = mysqli_query($conn, "SELECT id, story_id, podcast_id, image_file, image_folder, audio_file, video_type FROM hdb_video_gen WHERE status = 'pending' ORDER BY id ASC LIMIT 1");

if (!$res || mysqli_num_rows($res) == 0) {
    die(json_encode(['success' => true, 'message' => 'No pending tasks']));
}

$scene = mysqli_fetch_assoc($res);
$sid = $scene['id'];
$story_id = $scene['story_id'];
$podcast_id = $scene['podcast_id'];

smartLog("Processing Scene ID: $sid", "INFO");

// Processing status set karna
mysqli_query($conn, "UPDATE hdb_video_gen SET status='processing' WHERE id='$sid'");

// Modal API Call
$postData = [
    'source_image' => new CURLFile(__DIR__ . '/' . ($scene['image_folder'] ?: 'podcast_images') . '/' . $scene['image_file']),
    'driven_audio' => new CURLFile(PODCAST_AUDIOS_FOLDER . $scene['audio_file']),
    'pose_style' => '12', 'exp_scale' => '1.1', 'still_mode' => 'false'
];

$ch = curl_init(SADTALKER_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['X-API-Key: ' . MODAL_API_KEY], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 1800
]);

$resp = curl_exec($ch);
curl_close($ch);

$video_url = '';
foreach (explode("\n", trim($resp)) as $line) {
    $d = json_decode(trim($line), true);
    if (isset($d['video_url'])) {
        $video_url = 'https://irfan-growen--automated-sadtalker-4k-revert-fastapi-app.modal.run' . $d['video_url'];
        break;
    }
}

if ($video_url) {
    $fname = "video_{$podcast_id}-{$story_id}.mp4";
    $spath = USER_VIDEOS_FOLDER . $fname;
    
    // Video save karna
    if (file_put_contents($spath, file_get_contents($video_url))) {
        
        ensure_db_connection();
        $safe_fname = mysqli_real_escape_string($conn, $fname);
        
        // --- DB UPDATES WITH ERROR LOGGING ---
        
        // 1. Update hdb_video_gen
        $q1 = "UPDATE hdb_video_gen SET status='done', output_file='$safe_fname' WHERE id='$sid'";
        if (mysqli_query($conn, $q1)) {
            smartLog("Table hdb_video_gen updated successfully for ID $sid", "SUCCESS");
        } else {
            smartLog("Table hdb_video_gen UPDATE FAILED: " . mysqli_error($conn), "ERROR");
        }

        // 2. Update hdb_podcast_stories
        $q2 = "UPDATE hdb_podcast_stories SET image_file='$safe_fname', image_folder='user_videos' WHERE id='$story_id'";
        if (mysqli_query($conn, $q2)) {
            smartLog("Table hdb_podcast_stories updated successfully for ID $story_id", "SUCCESS");
        } else {
            smartLog("Table hdb_podcast_stories UPDATE FAILED: " . mysqli_error($conn), "ERROR");
        }

        echo json_encode(['success' => true, 'video' => $fname, 'start' => $script_start_time, 'end' => date('Y-m-d H:i:s')]);
    } else {
        smartLog("Failed to save video file to $spath", "ERROR");
    }
} else {
    ensure_db_connection();
    mysqli_query($conn, "UPDATE hdb_video_gen SET status='failed' WHERE id='$sid'");
    smartLog("Modal API did not return a video URL. Response: $resp", "ERROR");
}

exit;
?>
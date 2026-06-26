<?php
/**
 * client_wan.php - All-in-One Polling Video Generator
 *
 * Flow:
 *   1. videogen_flag = 1 → Pick podcast
 *   2. Generate video for each story (sequentially, in background)
 *   3. After each video is created → update image_file + image_folder
 *   4. All done → videogen_flag = 2
 *
 * Usage:
 *   ?action=start                    → Start processing all stories (responds immediately)
 *   ?action=status&podcast_id=123    → Check current status (poll every 10 sec)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);
ignore_user_abort(true); // Keep script running even if browser is closed

include 'config.php';

define('MODAL_GENERATE_URL', 'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run/generate');
define('MODAL_API_KEY',      'sk-wan-video-studio-8293-f2a1');
define('MODAL_BASE_URL',     'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER',         __DIR__ . '/logs/');

header('Content-Type: application/json');

$action = isset($_GET['action']) ? trim($_GET['action']) : 'start';

if ($action === 'status') {
    handle_status();
} elseif ($action === 'process_next') {
    handle_process_next();
} else {
    handle_start();
}
exit;

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: STATUS — For polling (call every 10 seconds)
// ══════════════════════════════════════════════════════════════════════════════
function handle_status() {
    global $conn;

    $podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

    if ($podcast_id <= 0) {
        $res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $podcast_id = (int)mysqli_fetch_assoc($res)['id'];
        } else {
            echo json_encode(['success' => true, 'status' => 'idle', 'message' => 'No pending podcast found.']);
            return;
        }
    }

    $stories_res = mysqli_query($conn,
        "SELECT id, image_file, image_folder, videogen_flag FROM hdb_podcast_stories 
         WHERE podcast_id = $podcast_id ORDER BY id ASC"
    );

    $total = $done = $pending = 0;
    $stories = [];

    if ($stories_res) {
        while ($s = mysqli_fetch_assoc($stories_res)) {
            $total++;
            $story_flag = (int)($s['videogen_flag'] ?? 0);
            
            if ($story_flag == 2) {
                $state = 'done'; $done++;
            } else {
                $state = 'pending'; $pending++;
            }
            $stories[] = [
                'story_id'   => (int)$s['id'],
                'state'      => $state,
                'video_file' => ($state === 'done') ? $s['image_folder'] . $s['image_file'] : null
            ];
        }
    }

    $p    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT videogen_flag FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1"));
    $flag = $p ? (int)$p['videogen_flag'] : -1;

    if ($flag == 2) {
        $status  = 'all_done';
        $message = "All $done/$total videos are ready! videogen_flag is now 2.";
    } elseif ($flag == 1 && $done > 0) {
        $status  = 'processing';
        $message = "$done/$total videos generated. Processing remaining...";
    } elseif ($flag == 1 && $done == 0) {
        $status  = 'processing';
        $message = "Generating the first video...";
    } else {
        $status  = 'unknown';
        $message = "Flag=$flag, Done=$done, Pending=$pending";
    }

    echo json_encode([
        'success'         => true,
        'podcast_id'      => $podcast_id,
        'status'          => $status,
        'videogen_flag'   => $flag,
        'message'         => $message,
        'total_stories'   => $total,
        'done_stories'    => $done,
        'pending_stories' => $pending,
        'stories'         => $stories
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: START — Initialize and trigger background chain
// ══════════════════════════════════════════════════════════════════════════════
function handle_start() {
    global $conn;

    ensure_db();

    $podcast_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");

    if (!$podcast_res || mysqli_num_rows($podcast_res) == 0) {
        echo json_encode(['success' => true, 'status' => 'idle', 'message' => 'No pending podcast found.']);
        return;
    }

    $podcast_id = (int)mysqli_fetch_assoc($podcast_res)['id'];
    $total_row  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id"));
    $total_count = (int)$total_row['cnt'];

    // Respond to browser IMMEDIATELY
    echo json_encode([
        'success'       => true,
        'status'        => 'started',
        'podcast_id'    => $podcast_id,
        'total_stories' => $total_count,
        'message'       => "Started processing $total_count stories for Podcast $podcast_id. Poll ?action=status&podcast_id=$podcast_id."
    ]);

    // Trigger background process asynchronously
    trigger_async_background();
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: PROCESS NEXT — Background worker (Processes ONLY 1 story per execution)
// ══════════════════════════════════════════════════════════════════════════════
function handle_process_next() {
    global $conn;
    ensure_db();

    $podcast_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
    if (!$podcast_res || mysqli_num_rows($podcast_res) == 0) return; // All done
    $podcast_id = (int)mysqli_fetch_assoc($podcast_res)['id'];

    // Find ONLY ONE pending story
    $story_res = mysqli_query($conn, "SELECT id, video_prompt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id AND videogen_flag = 1 ORDER BY id ASC LIMIT 1");
    
    if (!$story_res || mysqli_num_rows($story_res) == 0) {
        // No more stories, mark podcast as done
        mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 2 WHERE id = $podcast_id");
        wanLog("Podcast $podcast_id: COMPLETE → videogen_flag=2", 'SUCCESS');
        return;
    }

    $story = mysqli_fetch_assoc($story_res);
    $story_id = (int)$story['id'];
    $prompt   = trim($story['video_prompt']);

    if (empty($prompt)) {
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = 'skipped_no_prompt', videogen_flag = 2 WHERE id = $story_id");
        wanLog("Story $story_id: empty prompt — skipped", 'WARN');
        trigger_async_background(); // Call next
        return;
    }

    wanLog("Story $story_id: generation started (Podcast $podcast_id)", 'INFO');

    // ── Modal API call ────────────────────────────────────────────────────
    $ch = curl_init(MODAL_GENERATE_URL);
    $video_url = '';
    $last_ping_time = time();

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['mode' => 'wan', 'prompt' => $prompt, 'resolution' => '720*1280'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) use (&$video_url, &$last_ping_time, &$conn) {
            foreach (explode("\n", trim($data)) as $line) {
                $d = json_decode(trim($line), true);
                if (isset($d['video_url'])) $video_url = MODAL_BASE_URL . $d['video_url'];
            }
            if (time() - $last_ping_time > 15) { @mysqli_ping($conn); $last_ping_time = time(); }
            return strlen($data);
        }
    ]);
    
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err || empty($video_url)) {
        wanLog("Modal ERROR Story $story_id: $curl_err | No URL found.", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET videogen_flag = 3, image_file = 'api_error' WHERE id = $story_id");
        trigger_async_background(); // Call next
        return;
    }

    // ── Download Video ───────────────────────────────────────────────
    $filename  = 'wan_' . $podcast_id . '_' . $story_id . '.mp4';
    if (!file_exists(USER_VIDEOS_FOLDER)) @mkdir(USER_VIDEOS_FOLDER, 0777, true);
    $save_path = USER_VIDEOS_FOLDER . $filename;

    $dl = curl_init($video_url);
    $fp = fopen($save_path, 'wb');
    
    $last_dl_ping = time();
    curl_setopt_array($dl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) use (&$last_dl_ping, &$conn, $fp) {
            fwrite($fp, $data);
            if (time() - $last_dl_ping > 15) { @mysqli_ping($conn); $last_dl_ping = time(); }
            return strlen($data);
        }
    ]);
    curl_exec($dl); curl_close($dl); fclose($fp);

    $file_size = file_exists($save_path) ? filesize($save_path) : 0;
    ensure_db();

    if ($file_size > 1000) {
        $safe = mysqli_real_escape_string($conn, $filename);
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = '$safe', image_folder = '/user_videos/', videogen_flag = 2 WHERE id = $story_id");
        wanLog("Story $story_id DONE: $filename ({$file_size} bytes) ✅", 'SUCCESS');
    } else {
        wanLog("Story $story_id download failed (size: $file_size bytes)", 'ERROR');
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET videogen_flag = 3, image_file = 'download_failed' WHERE id = $story_id");
    }

    // Trigger next story
    trigger_async_background();
}

function trigger_async_background() {
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?action=process_next";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Only wait 1 second
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function respond_now($data) {
    $json = json_encode($data);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($json));
    echo $json;
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

function ensure_db() {
    global $conn;
    if (!@mysqli_ping($conn)) { @mysqli_close($conn); include 'config.php'; }
}

function wanLog($msg, $type = 'INFO') {
    if (!file_exists(LOG_FOLDER)) @mkdir(LOG_FOLDER, 0777, true);
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents(LOG_FOLDER . 'wan_' . date('Y-m-d') . '.log', $entry, FILE_APPEND);
}
?>
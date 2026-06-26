<?php
/**
 * wan_worker.php — Background video generation worker
 * Called by wan_text2_video_api.php non-blocking.
 * Generates exactly ONE scene, downloads it, updates DB, sets flag=2.
 * Never called directly by the browser.
 */

set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Close connection to caller immediately so server doesn't wait
if (ob_get_level()) ob_end_clean();
header('Connection: close');
header('Content-Length: 0');
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

include 'config.php';

define('MODAL_GENERATE_URL', 'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run/generate');
define('MODAL_API_KEY',      'sk-wan-video-studio-8293-f2a1');
define('MODAL_BASE_URL',     'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER',         __DIR__ . '/logs/');

global $conn;
global $db_host, $db_user, $db_pass, $db_name;

if (!defined('DB_HOST')) {
    $db_host = $db_host ?? 'localhost'; $db_user = $db_user ?? '';
    $db_pass = $db_pass ?? '';          $db_name = $db_name ?? '';
} else {
    $db_host = DB_HOST; $db_user = DB_USER;
    $db_pass = DB_PASS; $db_name = DB_NAME;
}

$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

if ($podcast_id <= 0) {
    wanLog("Worker called with no podcast_id — aborting", 'ERROR');
    exit;
}

wanLog("=== WORKER START for Podcast $podcast_id ===", 'INFO');
run_worker($podcast_id);
wanLog("=== WORKER END for Podcast $podcast_id ===", 'INFO');
exit;

// ══════════════════════════════════════════════════════════════════════════════
function run_worker($podcast_id) {
    ensure_db();
    global $conn;

    if (!file_exists(USER_VIDEOS_FOLDER)) @mkdir(USER_VIDEOS_FOLDER, 0777, true);

    // ── Pick FIRST pending story ──────────────────────────────────────────────
    $story_res = mysqli_query($conn,
        "SELECT id, video_prompt FROM hdb_podcast_stories
         WHERE podcast_id = $podcast_id
           AND (image_file IS NULL OR image_file = '')
         ORDER BY id ASC LIMIT 1"
    );

    if (!$story_res || mysqli_num_rows($story_res) == 0) {
        wanLog("Podcast $podcast_id: no pending story found — setting flag=2", 'WARN');
        finish($podcast_id);
        return;
    }

    $story    = mysqli_fetch_assoc($story_res);
    $story_id = (int)$story['id'];
    $prompt   = trim($story['video_prompt'] ?? '');

    if (empty($prompt)) {
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = 'skipped_no_prompt' WHERE id = $story_id");
        wanLog("Story $story_id: empty prompt — skipped", 'WARN');
        finish($podcast_id);
        return;
    }

    wanLog("Story $story_id: prompt found — calling Modal API", 'INFO');
    wanLog("Story $story_id: prompt = " . substr($prompt, 0, 120), 'DEBUG');

    // ── Call Modal API ────────────────────────────────────────────────────────
    $ch = curl_init(MODAL_GENERATE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'mode'       => 'wan',
            'prompt'     => $prompt,
            'resolution' => '720*1280'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 1800   // 30 min max
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || !$response) {
        wanLog("Story $story_id: Modal cURL ERROR (HTTP $http_code): $curl_err", 'ERROR');
        finish($podcast_id);
        return;
    }

    wanLog("Story $story_id: Modal HTTP=$http_code response=" . substr($response, 0, 300), 'DEBUG');

    // ── Parse video_url — handles NDJSON streaming or single JSON blob ────────
    $video_url = '';

    // Try line-by-line (streaming NDJSON)
    foreach (explode("\n", trim($response)) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $d = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($d['video_url'])) {
            $raw = $d['video_url'];
            $video_url = (strpos($raw, 'http') === 0) ? $raw : MODAL_BASE_URL . $raw;
            break;
        }
    }

    // Fallback: try whole response as single JSON
    if (empty($video_url)) {
        $d = json_decode(trim($response), true);
        if (json_last_error() === JSON_ERROR_NONE && isset($d['video_url'])) {
            $raw = $d['video_url'];
            $video_url = (strpos($raw, 'http') === 0) ? $raw : MODAL_BASE_URL . $raw;
        }
    }

    if (empty($video_url)) {
        wanLog("Story $story_id: video_url NOT found in response. Full: " . substr($response, 0, 500), 'ERROR');
        finish($podcast_id);
        return;
    }

    wanLog("Story $story_id: video_url = $video_url", 'INFO');

    // ── Download video file ───────────────────────────────────────────────────
    $filename  = 'wan_' . $podcast_id . '_' . $story_id . '_' . time() . '.mp4';
    $save_path = USER_VIDEOS_FOLDER . $filename;

    $fp = fopen($save_path, 'wb');
    if (!$fp) {
        wanLog("Story $story_id: cannot open file for writing: $save_path", 'ERROR');
        finish($podcast_id);
        return;
    }

    $dl = curl_init($video_url);
    curl_setopt_array($dl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    curl_exec($dl);                               // exec FIRST
    $dl_err  = curl_error($dl);
    $dl_code = curl_getinfo($dl, CURLINFO_HTTP_CODE);
    curl_close($dl);
    fclose($fp);

    if ($dl_err) {
        wanLog("Story $story_id: download cURL error (HTTP $dl_code): $dl_err", 'ERROR');
    }

    $file_size = file_exists($save_path) ? filesize($save_path) : 0;
    wanLog("Story $story_id: download complete | file=$filename | size=$file_size bytes | HTTP=$dl_code", 'INFO');

    // ── Update DB ─────────────────────────────────────────────────────────────
    ensure_db();
    global $conn;

    if ($file_size > 10000) {
        $safe = mysqli_real_escape_string($conn, $filename);
        $sql  = "UPDATE hdb_podcast_stories
                 SET image_file = '$safe', image_folder = 'user_videos/'
                 WHERE id = $story_id";

        $ok = mysqli_query($conn, $sql);
        if (!$ok) {
            wanLog("Story $story_id: DB UPDATE FAIL (1st): " . mysqli_error($conn), 'ERROR');
            ensure_db(true);
            $ok = mysqli_query($conn, $sql);
            if (!$ok) {
                wanLog("Story $story_id: DB UPDATE FAIL (2nd): " . mysqli_error($conn), 'ERROR');
            } else {
                wanLog("Story $story_id: DB UPDATE OK (retry) ✅", 'SUCCESS');
            }
        } else {
            $affected = mysqli_affected_rows($conn);
            wanLog("Story $story_id: DB UPDATE OK | affected=$affected | file=$filename ✅", 'SUCCESS');
        }
    } else {
        wanLog("Story $story_id: file too small ($file_size bytes) — removing, DB not updated", 'ERROR');
        @unlink($save_path);
    }

    finish($podcast_id);
}

// ── Set videogen_flag=2 and log completion ────────────────────────────────────
function finish($podcast_id) {
    ensure_db();
    global $conn;
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 2 WHERE id = $podcast_id");
    if ($ok) {
        wanLog("Podcast $podcast_id: videogen_flag=2 SET ✅", 'SUCCESS');
    } else {
        wanLog("Podcast $podcast_id: failed to set flag=2: " . mysqli_error($conn), 'ERROR');
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function ensure_db($force = false) {
    global $conn, $db_host, $db_user, $db_pass, $db_name;
    if ($force || !$conn || !@mysqli_ping($conn)) {
        if ($conn) @mysqli_close($conn);
        $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
        if (!$conn) { wanLog("DB connect FAIL: " . mysqli_connect_error(), 'ERROR'); return; }
        mysqli_set_charset($conn, 'utf8mb4');
    }
}

function wanLog($msg, $type = 'INFO') {
    if (!file_exists(LOG_FOLDER)) @mkdir(LOG_FOLDER, 0777, true);
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents(LOG_FOLDER . 'wan_' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
}
?>

<?php
/**
 * wan_text2_video_api.php
 * Actions:
 *   ?action=start&podcast_id=123   → Set flag=1, fire background worker, return immediately
 *   ?action=status&podcast_id=123  → Poll current status from DB
 *   ?action=reset&podcast_id=123   → Reset flag + clear first story
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include 'config.php';

define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER',         __DIR__ . '/logs/');
define('WORKER_URL',         'https://www.videovizard.com/wan_worker.php');

global $conn;
global $db_host, $db_user, $db_pass, $db_name;

if (!defined('DB_HOST')) {
    $db_host = $db_host ?? 'localhost'; $db_user = $db_user ?? '';
    $db_pass = $db_pass ?? '';          $db_name = $db_name ?? '';
} else {
    $db_host = DB_HOST; $db_user = DB_USER;
    $db_pass = DB_PASS; $db_name = DB_NAME;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action     = isset($_GET['action'])     ? trim($_GET['action'])     : 'start';
$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

if ($action === 'status') {
    handle_status($podcast_id);
} elseif ($action === 'reset') {
    handle_reset($podcast_id);
} else {
    handle_start($podcast_id);
}
exit;

// ══════════════════════════════════════════════════════════════════════════════
// STATUS — read DB and return current state
// ══════════════════════════════════════════════════════════════════════════════
function handle_status($podcast_id) {
    ensure_db();
    global $conn;

    if ($podcast_id <= 0) {
        $res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $podcast_id = (int)mysqli_fetch_assoc($res)['id'];
        } else {
            echo json_encode(['success' => true, 'status' => 'idle', 'message' => 'No podcast processing.']);
            return;
        }
    }

    $stories_res = mysqli_query($conn,
        "SELECT id, image_file, image_folder FROM hdb_podcast_stories
         WHERE podcast_id = $podcast_id ORDER BY id ASC"
    );

    $total = $done = $pending = 0;
    $stories = [];

    if ($stories_res) {
        while ($s = mysqli_fetch_assoc($stories_res)) {
            $total++;
            $img = trim($s['image_file'] ?? '');
            if (!empty($img) && $img !== 'skipped_no_prompt' && strpos($img, 'wan_') === 0) {
                $state = 'done'; $done++;
            } else {
                $state = 'pending'; $pending++;
            }
            $folder = rtrim($s['image_folder'] ?? '', '/') . '/';
            $stories[] = [
                'story_id'   => (int)$s['id'],
                'state'      => $state,
                'video_file' => ($state === 'done') ? $folder . $img : null
            ];
        }
    }

    $p    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT videogen_flag FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1"));
    $flag = $p ? (int)$p['videogen_flag'] : -1;

    if ($flag == 2 && $done > 0) {
        $status = 'all_done';
        $message = "Scene generated successfully!";
    } elseif ($flag == 1) {
        $status = 'processing';
        $message = "$done/$total done — Generating scene, please wait...";
    } else {
        $status = 'idle';
        $message = "Ready. Press Generate.";
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
// RESET
// ══════════════════════════════════════════════════════════════════════════════
function handle_reset($podcast_id) {
    ensure_db();
    global $conn;

    if ($podcast_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'podcast_id required.']);
        return;
    }

    mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 0 WHERE id = $podcast_id");
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET image_file = NULL, image_folder = NULL
         WHERE podcast_id = $podcast_id ORDER BY id ASC LIMIT 1"
    );

    wanLog("Podcast $podcast_id RESET", 'INFO');
    echo json_encode(['success' => true, 'status' => 'reset_done', 'podcast_id' => $podcast_id]);
}

// ══════════════════════════════════════════════════════════════════════════════
// START — set flag=1, clear first story, fire worker, return immediately
// ══════════════════════════════════════════════════════════════════════════════
function handle_start($podcast_id) {
    ensure_db();
    global $conn;

    if ($podcast_id > 0) {
        $res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1");
    } else {
        $res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
    }

    if (!$res || mysqli_num_rows($res) == 0) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Podcast not found.']);
        return;
    }

    $podcast_id = (int)mysqli_fetch_assoc($res)['id'];

    // Set flag=1 and clear first story so worker picks it up
    mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 1 WHERE id = $podcast_id");
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET image_file = NULL, image_folder = NULL
         WHERE podcast_id = $podcast_id ORDER BY id ASC LIMIT 1"
    );

    wanLog("Podcast $podcast_id: START — firing background worker", 'INFO');

    // Fire worker non-blocking (timeout=3 so we don't wait for it)
    $worker_url = WORKER_URL . '?podcast_id=' . $podcast_id;
    $wc = curl_init($worker_url);
    curl_setopt_array($wc, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($wc);
    curl_close($wc);

    // Return immediately to browser — worker runs independently
    echo json_encode([
        'success'    => true,
        'status'     => 'started',
        'podcast_id' => $podcast_id,
        'message'    => "Worker started for Podcast $podcast_id. Poll /status to track progress."
    ]);
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

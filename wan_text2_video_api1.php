<?php
/**
 * wan_text2_video_api.php - Video Generator API
 *
 * Actions:
 *   ?action=start&podcast_id=123          → Reset flag to 1, clear old videos, start generation
 *   ?action=status&podcast_id=123         → Poll current status
 *   ?action=reset&podcast_id=123          → Just reset without starting (optional)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);
ignore_user_abort(true);

include 'config.php';

define('MODAL_GENERATE_URL', 'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run/generate');
define('MODAL_API_KEY',      'sk-wan-video-studio-8293-f2a1');
define('MODAL_BASE_URL',     'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER',         __DIR__ . '/logs/');

global $conn;
global $db_host, $db_user, $db_pass, $db_name;

if (!defined('DB_HOST')) {
    $db_host = $db_host ?? 'localhost';
    $db_user = $db_user ?? '';
    $db_pass = $db_pass ?? '';
    $db_name = $db_name ?? '';
} else {
    $db_host = DB_HOST;
    $db_user = DB_USER;
    $db_pass = DB_PASS;
    $db_name = DB_NAME;
}

header('Content-Type: application/json');

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
// ACTION: STATUS
// ══════════════════════════════════════════════════════════════════════════════
function handle_status($podcast_id) {
    global $conn;
    ensure_db();

    // If no podcast_id given, find one that is actively processing (flag=1)
    if ($podcast_id <= 0) {
        $res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $podcast_id = (int)mysqli_fetch_assoc($res)['id'];
        } else {
            echo json_encode(['success' => true, 'status' => 'idle', 'message' => 'Koi podcast processing mein nahi hai.']);
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
            // Only count as done if it has a real video file (not skipped/error)
            if (!empty($img) && $img !== 'skipped_no_prompt' && strpos($img, 'wan_') === 0) {
                $state = 'done'; $done++;
            } else {
                $state = 'pending'; $pending++;
            }
            $stories[] = [
                'story_id'   => (int)$s['id'],
                'state'      => $state,
                'video_file' => ($state === 'done') ? ($s['image_folder'] . $s['image_file']) : null
            ];
        }
    }

    $p_res = mysqli_query($conn, "SELECT videogen_flag FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1");
    $p     = $p_res ? mysqli_fetch_assoc($p_res) : null;
    $flag  = $p ? (int)$p['videogen_flag'] : -1;

    // flag=2 AND all stories actually have videos = truly done
    if ($flag == 2 && $done == $total && $total > 0) {
        $status  = 'all_done';
        $message = "Sab $done/$total videos tayyar! videogen_flag=2 ho gaya.";
    } elseif ($flag == 1) {
        $status  = 'processing';
        $message = $done > 0
            ? "$done/$total videos ban gayi hain. Baqi ban rahi hain..."
            : "Pehli video generate ho rahi hai...";
    } else {
        $status  = 'idle';
        $message = "Flag=$flag. Start karne ke liye button dabao.";
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
// ACTION: RESET — Clear old data so fresh generation can run
// ══════════════════════════════════════════════════════════════════════════════
function handle_reset($podcast_id) {
    global $conn;
    ensure_db();

    if ($podcast_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'podcast_id required for reset.']);
        return;
    }

    // Reset flag to 1 (processing)
    mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 1 WHERE id = $podcast_id");

    // Clear old video references so stories are picked up again
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET image_file = NULL, image_folder = NULL
         WHERE podcast_id = $podcast_id"
    );

    wanLog("Podcast $podcast_id RESET: flag=1, stories cleared", 'INFO');

    echo json_encode([
        'success'    => true,
        'status'     => 'reset_done',
        'podcast_id' => $podcast_id,
        'message'    => "Podcast $podcast_id reset ho gaya. Ab generation shuru ho sakti hai."
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: START — Reset + generate all stories one by one in background
// ══════════════════════════════════════════════════════════════════════════════
function handle_start($podcast_id) {
    global $conn;
    ensure_db();

    // If podcast_id given, use it directly. Otherwise find one with flag=1.
    if ($podcast_id > 0) {
        $podcast_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1");
    } else {
        $podcast_res = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE videogen_flag = 1 LIMIT 1");
    }

    if (!$podcast_res || mysqli_num_rows($podcast_res) == 0) {
        respond_now(['success' => false, 'status' => 'error', 'message' => 'Podcast nahi mila.']);
        return;
    }

    $podcast_id = (int)mysqli_fetch_assoc($podcast_res)['id'];

    // ── RESET: flag=1, clear old video refs so fresh generation starts ────────
    mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 1 WHERE id = $podcast_id");
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET image_file = NULL, image_folder = NULL
         WHERE podcast_id = $podcast_id"
    );
    wanLog("Podcast $podcast_id: START called — reset complete, beginning generation", 'INFO');

    // Count total stories
    $total_res   = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id");
    $total_count = (int)mysqli_fetch_assoc($total_res)['cnt'];

    if ($total_count == 0) {
        mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 2 WHERE id = $podcast_id");
        respond_now(['success' => true, 'status' => 'all_done', 'podcast_id' => $podcast_id, 'message' => 'Koi story nahi mili.']);
        return;
    }

    // ── Respond to browser immediately — background work begins below ─────────
    respond_now([
        'success'       => true,
        'status'        => 'started',
        'podcast_id'    => $podcast_id,
        'total_stories' => $total_count,
        'message'       => "Podcast $podcast_id ki $total_count stories ka process shuru. Status poll karo."
    ]);
    // Browser got its response. Now generate videos silently in background.

    if (!file_exists(USER_VIDEOS_FOLDER)) @mkdir(USER_VIDEOS_FOLDER, 0777, true);

    $success_count = 0;

    while (true) {
        ensure_db();

        // Pick next story with no video yet
        $story_res = mysqli_query($conn,
            "SELECT id, video_prompt FROM hdb_podcast_stories
             WHERE podcast_id = $podcast_id
               AND (image_file IS NULL OR image_file = '')
             ORDER BY id ASC LIMIT 1"
        );

        if (!$story_res) {
            wanLog("DB query failed: " . mysqli_error($conn), 'ERROR');
            break;
        }

        if (mysqli_num_rows($story_res) == 0) {
            wanLog("Podcast $podcast_id: no more pending stories — loop exit", 'INFO');
            break;
        }

        $story    = mysqli_fetch_assoc($story_res);
        $story_id = (int)$story['id'];
        $prompt   = trim($story['video_prompt']);

        if (empty($prompt)) {
            mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = 'skipped_no_prompt' WHERE id = $story_id");
            wanLog("Story $story_id: prompt empty — skipped", 'WARN');
            continue;
        }

        wanLog("Story $story_id: sending to Modal API (Podcast $podcast_id)", 'INFO');

        // ── Call Modal API ────────────────────────────────────────────────────
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
            CURLOPT_TIMEOUT        => 1800  // 30 min — Modal can be slow
        ]);
        $response  = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_err || !$response) {
            wanLog("Modal cURL ERROR Story $story_id (HTTP $http_code): $curl_err", 'ERROR');
            continue;
        }

        wanLog("Story $story_id Modal response (HTTP $http_code): " . substr($response, 0, 300), 'DEBUG');

        // ── Parse video_url from streaming/JSON response ──────────────────────
        $video_url = '';
        foreach (explode("\n", trim($response)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $d = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($d['video_url'])) {
                $video_url = MODAL_BASE_URL . $d['video_url'];
                break;
            }
        }

        // Also try parsing the whole response as one JSON blob
        if (empty($video_url)) {
            $d = json_decode(trim($response), true);
            if (isset($d['video_url'])) {
                $video_url = MODAL_BASE_URL . $d['video_url'];
            }
        }

        if (empty($video_url)) {
            wanLog("Story $story_id: video_url not found in response. Full: " . substr($response, 0, 500), 'ERROR');
            continue;
        }

        wanLog("Story $story_id: video_url received — $video_url", 'INFO');

        // ── Download video ────────────────────────────────────────────────────
        $filename  = 'wan_' . $podcast_id . '_' . $story_id . '_' . time() . '.mp4';
        $save_path = USER_VIDEOS_FOLDER . $filename;

        $fp = fopen($save_path, 'wb');
        if (!$fp) {
            wanLog("Story $story_id: cannot open file for writing — $save_path", 'ERROR');
            continue;
        }

        $dl = curl_init($video_url);
        curl_setopt_array($dl, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . MODAL_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        $dl_err  = curl_error($dl);
        $dl_code = curl_getinfo($dl, CURLINFO_HTTP_CODE);
        curl_exec($dl);
        curl_close($dl);
        fclose($fp);

        if ($dl_err) {
            wanLog("Story $story_id: download error (HTTP $dl_code): $dl_err", 'ERROR');
        }

        $file_size = file_exists($save_path) ? filesize($save_path) : 0;
        wanLog("Story $story_id: downloaded $filename | size={$file_size} bytes | HTTP=$dl_code", 'INFO');

        // ── Only update DB AFTER confirming file downloaded successfully ───────
        ensure_db();

        if ($file_size > 10000) {  // must be > 10KB — real video
            $safe_name    = mysqli_real_escape_string($conn, $filename);
            $image_folder = 'user_videos/';

            $sql = "UPDATE hdb_podcast_stories
                    SET image_file = '$safe_name', image_folder = '$image_folder'
                    WHERE id = $story_id";

            $ok = mysqli_query($conn, $sql);

            if (!$ok) {
                wanLog("Story $story_id DB UPDATE FAIL (1st): " . mysqli_error($conn), 'ERROR');
                ensure_db(true);
                $ok = mysqli_query($conn, $sql);
                if (!$ok) {
                    wanLog("Story $story_id DB UPDATE FAIL (2nd): " . mysqli_error($conn), 'ERROR');
                } else {
                    wanLog("Story $story_id DB UPDATE OK (retry): affected=" . mysqli_affected_rows($conn) . " ✅", 'SUCCESS');
                    $success_count++;
                }
            } else {
                $affected = mysqli_affected_rows($conn);
                wanLog("Story $story_id DB UPDATE OK: affected=$affected | file=$filename ✅", 'SUCCESS');
                if ($affected == 0) {
                    wanLog("Story $story_id WARNING: 0 rows affected — check story_id", 'WARN');
                } else {
                    $success_count++;
                }
            }
        } else {
            wanLog("Story $story_id: file too small ($file_size bytes) — download failed, skipping DB update", 'ERROR');
            @unlink($save_path);
            // Don't update DB — story stays pending so it can be retried next run
        }

    } // end while

    // ── Set flag=2 ONLY after all stories are processed ──────────────────────
    ensure_db();
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag = 2 WHERE id = $podcast_id");
    if ($ok) {
        wanLog("Podcast $podcast_id: COMPLETE → videogen_flag=2 | $success_count/$total_count successful ✅", 'SUCCESS');
    } else {
        wanLog("Podcast $podcast_id: failed to set videogen_flag=2: " . mysqli_error($conn), 'ERROR');
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
        if (!$conn) { wanLog("DB reconnect FAIL: " . mysqli_connect_error(), 'ERROR'); return; }
        mysqli_set_charset($conn, 'utf8mb4');
    }
}

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

function wanLog($msg, $type = 'INFO') {
    if (!file_exists(LOG_FOLDER)) @mkdir(LOG_FOLDER, 0777, true);
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents(LOG_FOLDER . 'wan_' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
}
?>

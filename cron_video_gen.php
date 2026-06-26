<?php
// ============================================================
// cron_video_gen.php — Video Generation Queue Processor
// Cron: * * * * * php /path/to/cron_video_gen.php >> /path/to/cron_video.log 2>&1
//
// hdb_video_gen_que.videogen_flag:
//   1 = ready / pending (not yet submitted)
//   2 = modal processing (in progress, modal jobs only)
//   3 = video generated (done)
//   4 = error (failed 3+ times)
//   5 = fal.ai result ready (webhook stored result_video_url, awaiting ingest)
//   6 = fal.ai submitted, awaiting webhook callback (request_id stored)
//   7 = fal.ai finishing (claimed by a cron run for download + ingest)
//
// fal.ai async flow:
//   submit (flag 1->6) -> fal_webhook.php stores url (flag 6->5)
//   -> cron downloads+ingests (flag 5->7->3)
//
// hdb_video_gen_que.gen_mode:
//   fal.ai (or blank) = fal.ai, async via queue.fal.run + webhook (default)
//   modal             = Modal/WAN, synchronous blocking
//
// hdb_podcast_stories.videogen_flag:
//   3 = video generated (done)
//
// hdb_podcasts.videogen_flag:
//   3 = all stories done
// ============================================================

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/video_generation.log');
error_reporting(E_ALL);
ignore_user_abort(true);
set_time_limit(600); // 10 min max

// ── Status endpoint — called with ?status=1 ──────────────────
if (isset($_GET['status'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/dbconnect_hdb.php';
    header('Content-Type: application/json');

    $podcast_id = (int)($_GET['podcast_id'] ?? 0);
    $admin_id   = (int)($_SESSION['admin_id'] ?? 0);

    // Work in progress = modal processing (2), fal awaiting webhook (6),
    // fal result ready (5) or fal ingesting (7)
    $running_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, podcast_id, scene_id, updated_at FROM hdb_video_gen_que
         WHERE videogen_flag IN (2,5,6,7) LIMIT 1"));
    $is_running = !empty($running_row);

    // Queue stats
    $stats = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT
            SUM(videogen_flag=1)            AS pending,
            SUM(videogen_flag IN (2,5,6,7)) AS processing,
            SUM(videogen_flag=6)            AS awaiting_webhook,
            SUM(videogen_flag=5)            AS result_ready,
            SUM(videogen_flag=3)            AS done,
            COUNT(*)                        AS total
         FROM hdb_video_gen_que" .
        ($podcast_id ? " WHERE podcast_id=$podcast_id" : "")));

    // This podcast progress
    $pod_progress = null;
    if ($podcast_id) {
        $pod_progress = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                SUM(videogen_flag=3) AS done,
                COUNT(*) AS total
             FROM hdb_podcast_stories
             WHERE podcast_id=$podcast_id"));
    }

    // Last 5 log lines
    $log_file = __DIR__ . '/video_generation.log';
    $log_tail = [];
    if (file_exists($log_file)) {
        $lines = array_filter(array_slice(file($log_file), -20));
        foreach (array_reverse(array_values($lines)) as $line) {
            $line = trim($line);
            if (!$line) continue;
            // Only show relevant lines
            if (preg_match('/\[(FAL|WAN|PROCESSING|SAVED|DB_UPDATE|PODCAST_DONE|IDLE|FATAL|ERROR|RECOVERY|SKIP)\]/', $line)) {
                $log_tail[] = $line;
                if (count($log_tail) >= 5) break;
            }
        }
    }

    echo json_encode([
        'cron_running'   => $is_running,
        'current_job'    => $running_row ?: null,
        'queue'          => $stats,
        'podcast'        => $pod_progress,
        'log'            => array_reverse($log_tail),
        'checked_at'     => date('H:i:s'),
    ]);
    exit;
}

// ── Browser trigger: spawn as background CLI process ─────────
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/dbconnect_hdb.php';

    // Read queue before launching
    $pending = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as c, GROUP_CONCAT(DISTINCT gen_mode) as modes
         FROM hdb_video_gen_que WHERE videogen_flag=1"));
    $processing = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as c FROM hdb_video_gen_que WHERE videogen_flag=2"));
    $pending_count    = (int)($pending['c'] ?? 0);
    $processing_count = (int)($processing['c'] ?? 0);
    $modes            = $pending['modes'] ?? 'none';

    $php_bin = file_exists('/usr/bin/php') ? '/usr/bin/php' : (file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php');
    $script  = escapeshellarg(__FILE__);
    $log     = __DIR__ . '/video_generation.log';
    $log_arg = escapeshellarg($log);
    // Ensure log file is writable
    if (!file_exists($log)) touch($log);
    chmod($log, 0666);
    // setsid fully detaches into a new session so the spawned PHP process
    // survives this HTTP request ending — without it, some PHP-FPM/Apache
    // setups kill the child the moment this request finishes, which can
    // happen right after the flag=2 lock but before the curl call to fal.ai
    // ever fires (looks like "stuck at flag=2, nothing on fal.ai").
    $setsid_bin = file_exists('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
    $cmd = "{$setsid_bin}{$php_bin} -d error_reporting=0 -d display_errors=0 {$script} >> {$log_arg} 2>&1 < /dev/null &";
    shell_exec($cmd);
    logCron("Spawned via browser trigger | cmd: $cmd", 'BOOT');

    header("Content-Type: text/plain");
    echo "VIDEO CRON launched as background process at " . date('Y-m-d H:i:s') . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Queue status:\n";
    echo "  ⏳ Pending   : $pending_count rows (flag=1)\n";
    echo "  ⚙️  Processing: $processing_count rows (flag=2)\n";
    echo "  🎬 Mode(s)   : $modes\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    if ($processing_count > 0) {
        echo "⚠ Another cron is already running — this launch will exit immediately.\n";
    } elseif ($pending_count === 0) {
        echo "⚠ No pending rows found — cron will exit immediately.\n";
    } else {
        echo "✓ Cron will process $pending_count job(s) via $modes\n";
    }
    echo "Log : " . __DIR__ . "/video_generation.log\n";
    exit;
}

require_once __DIR__ . '/dbconnect_hdb.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/media_ingest.php';

// Same fallback chain vizard_scriptgen_2.php uses for the OpenAI key —
// needed here too now that finished videos get GPT-4o vision tagged via
// mediaIngest() instead of just being dropped on disk.
$apiKey = (!empty($apiKey) ? $apiKey : null)
       ?? (!empty($myApiKey) ? $myApiKey : null)
       ?? (!empty($api_Key) ? $api_Key : null)
       ?? (!empty($openai_key) ? $openai_key : null)
       ?? null;

// Same fallback chain vizard_scriptgen_2.php uses — config.php may define
// the key under a different variable name than $falApiKey. Without this,
// a name mismatch silently makes every fal.ai call fail with "No API key"
// before any curl request is ever sent.
$falApiKey = (!empty($falApiKey) ? $falApiKey : null)
          ?? (!empty($fal_api_key) ? $fal_api_key : null)
          ?? null;

// ── Verify DB connection ─────────────────────────────────────
if (!$conn || mysqli_connect_errno()) {
    logCron("FATAL: DB connect failed — " . mysqli_connect_error(), 'DB_ERROR');
    exit(1);
}
$db_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATABASE() as db"))['db'] ?? '?';
$que_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_video_gen_que WHERE videogen_flag=1"))['c'] ?? '?';
logCron("DB connected | db=$db_name | pending_rows=$que_count", 'INFO');
logCron("falApiKey resolved: " . (!empty($falApiKey) ? 'YES (len=' . strlen($falApiKey) . ')' : 'NO — fal.ai calls will fail immediately'), 'INFO');

// ── Ensure tracking columns exist ───────────────────────────
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS api_cost_usd DECIMAL(8,4) DEFAULT 0");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS model_used VARCHAR(100) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending'");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS error_msg TEXT DEFAULT NULL");
// Async fal.ai webhook flow — fal.ai's queue request id and the result URL it
// posts back to fal_webhook.php.
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS request_id VARCHAR(255) DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS result_video_url TEXT DEFAULT NULL");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_credit_charge_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL, team_lead_id INT NOT NULL DEFAULT 0,
    company_id INT NOT NULL DEFAULT 0, podcast_id INT NOT NULL DEFAULT 0,
    brief_id INT NOT NULL DEFAULT 0, credits_charged INT NOT NULL DEFAULT 0,
    balance_before INT NOT NULL DEFAULT 0, balance_after INT NOT NULL DEFAULT 0,
    charge_type VARCHAR(50) NOT NULL DEFAULT 'video_generation',
    video_track VARCHAR(20) DEFAULT NULL, gen_mode VARCHAR(20) DEFAULT NULL,
    duration_sec INT DEFAULT 0, model_used VARCHAR(100) DEFAULT NULL,
    description VARCHAR(300) DEFAULT NULL, api_cost_usd DECIMAL(8,4) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_id), INDEX idx_podcast (podcast_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Fatal error catcher ──────────────────────────────────────
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logCron("FATAL: " . $e['message'] . " in " . $e['file'] . " line " . $e['line'], 'FATAL');
    }
});

// ============================================================
// LOGGING
// ============================================================
function logCron($message, $type = 'INFO') {
    $ts    = date('Y-m-d H:i:s') . '.' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));
    $entry = "[$ts] [VCRON][$type] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/video_generation.log', $entry, FILE_APPEND | LOCK_EX);
}

// Returns human-readable elapsed time
function elapsed($ref_time) {
    $e    = round(microtime(true) - $ref_time, 3);
    $mins = floor($e / 60);
    $secs = round($e - ($mins * 60), 3);
    return $mins > 0 ? "{$mins}m " . number_format($secs, 1) . "s" : number_format($secs, 3) . "s";
}

// ============================================================
// VIDEO GENERATION — fal.ai LTX Video 2.3 (primary)
// ============================================================
function generateWithFalVideo($prompt, $duration = 6, $aspect_ratio = '9:16', $resolution = '1080p', $fps = 25, $image_path = '') {
    global $falApiKey;
    $t_func    = microtime(true);
    $has_image = !empty($image_path) && file_exists($image_path);
    // Kling v2.1 standard preserves outfit/subject from input image much better than LTX
    $endpoint  = $has_image
        ? 'https://fal.run/fal-ai/kling-video/v2.1/standard/image-to-video'
        : 'https://fal.run/fal-ai/ltx-2.3/text-to-video';

    logCron("FAL: Sending | mode=" . ($has_image?'image-to-video':'text-to-video') . " prompt_len=" . strlen($prompt) . " duration={$duration}s res={$resolution} | time=" . date('H:i:s'), 'FAL');

    if (empty($falApiKey)) {
        logCron("FAL: No API key — skipping", 'FAL_ERROR');
        return ['success' => false, 'error' => 'No API key', 'source' => 'fal.ai/ltx-2.3', 'wait_time' => 0, 'connect_time' => 0];
    }

    $payload = [
        'prompt'         => $prompt,
        'duration'       => $duration,
        'resolution'     => $resolution,
        'aspect_ratio'   => $aspect_ratio,
        'fps'            => $fps,
        'generate_audio' => false,
    ];

    if ($has_image) {
        $img_data = base64_encode(file_get_contents($image_path));
        $img_ext  = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
        $mime     = $img_ext === 'png' ? 'image/png' : 'image/jpeg';
        // Kling params — duration must be "5" or "10" as string
        $kling_dur = $duration >= 8 ? "10" : "5";
        $payload = [
            'prompt'      => $prompt,
            'image_url'   => "data:{$mime};base64,{$img_data}",
            'duration'    => $kling_dur,
            'aspect_ratio'=> '9:16',
        ];
        logCron("FAL: Kling image attached | " . round(filesize($image_path)/1024) . "KB | kling_dur={$kling_dur}s", 'FAL');
    } else {
        // LTX text-to-video params
        $payload = [
            'prompt'         => $prompt,
            'duration'       => $duration,
            'resolution'     => $resolution,
            'aspect_ratio'   => $aspect_ratio,
            'fps'            => $fps,
            'generate_audio' => false,
        ];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Key {$falApiKey}",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $t_sent     = microtime(true);
    $response   = curl_exec($ch);
    $t_received = microtime(true);

    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $connectTime = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3);
    $curlError   = curl_error($ch);
    curl_close($ch);

    $waitTime = round($t_received - $t_sent, 3);
    logCron("FAL: Response | HTTP=$httpCode connect={$connectTime}s wait={$waitTime}s resp_len=" . strlen((string)$response), 'FAL');

    if ($curlError) {
        logCron("FAL: cURL error: $curlError", 'FAL_ERROR');
        return ['success' => false, 'error' => $curlError, 'source' => 'fal.ai/ltx-2.3', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }
    if ($httpCode !== 200) {
        $parsed   = json_decode($response, true);
        $errorMsg = $parsed['detail'] ?? $parsed['error'] ?? $parsed['message'] ?? "HTTP $httpCode";
        if (is_array($errorMsg)) $errorMsg = json_encode($errorMsg);
        logCron("FAL: HTTP error $httpCode — $errorMsg | body=" . substr((string)$response, 0, 200), 'FAL_ERROR');
        return ['success' => false, 'error' => $errorMsg, 'source' => 'fal.ai/ltx-2.3', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    $result = json_decode($response, true);

    // Kling returns video.url, LTX also returns video.url
    $video_url = $result['video']['url'] ?? $result['video_url'] ?? null;
    if (empty($video_url)) {
        logCron("FAL: No video URL in response | body=" . substr((string)$response, 0, 300), 'FAL_ERROR');
        return ['success' => false, 'error' => 'No video URL in response', 'source' => $has_image ? 'fal.ai/kling-v2.1' : 'fal.ai/ltx-2.3', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    $source = $has_image ? 'fal.ai/kling-v2.1' : 'fal.ai/ltx-2.3';
    logCron("FAL: Got video URL | source=$source url=$video_url | total=" . elapsed($t_func), 'FAL_SUCCESS');

    // Download the video file
    logCron("FAL: Downloading video...", 'FAL');
    $t_dl = microtime(true);
    $dl_ch = curl_init($video_url);
    curl_setopt_array($dl_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $video_data = curl_exec($dl_ch);
    $dl_http    = curl_getinfo($dl_ch, CURLINFO_HTTP_CODE);
    $dl_size    = curl_getinfo($dl_ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($dl_ch);

    if ($dl_http !== 200 || !$video_data) {
        logCron("FAL: Video download failed | HTTP=$dl_http", 'FAL_ERROR');
        return ['success' => false, 'error' => "Video download failed HTTP $dl_http", 'source' => $source, 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    logCron("FAL: Video downloaded | size=" . round($dl_size / 1024 / 1024, 2) . "MB | dl_time=" . elapsed($t_dl), 'FAL');

    return [
        'success'      => true,
        'video_data'   => $video_data,
        'video_size'   => $dl_size,
        'source'       => $source,
        'wait_time'    => $waitTime,
        'connect_time' => $connectTime,
        'video_url'    => $video_url,
    ];
}

// ============================================================
// VIDEO GENERATION -- WAN 2.2 via Modal.com
// ============================================================
define('WAN_MODAL_URL',  'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run/generate');
define('WAN_MODAL_KEY',  'sk-wan-video-studio-8293-f2a1');
define('WAN_MODAL_BASE', 'https://inaamwithalvi1958--prompt-to-video-wan-fastapi-app.modal.run');

function generateWithWanModal($prompt, $vid_folder, $podcast_id, $scene_id, $duration = 5) {
    global $conn;
    $t_func    = microtime(true);
    $video_url = '';
    $last_ping = time();

    logCron('WAN: Sending | prompt_len=' . strlen($prompt) . ' time=' . date('H:i:s'), 'WAN');

    $ch = curl_init(WAN_MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['mode' => 'wan', 'prompt' => $prompt, 'resolution' => '720*1280', 'duration' => $duration],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . WAN_MODAL_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) use (&$video_url, &$last_ping, &$conn) {
            foreach (explode("\n", trim($data)) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $d = json_decode($line, true);
                if (isset($d['video_url'])) {
                    $video_url = WAN_MODAL_BASE . $d['video_url'];
                    logCron('WAN: Got video_url: ' . $video_url, 'WAN');
                }
                if (isset($d['status'])) {
                    logCron('WAN: status=' . $d['status'] . (isset($d['message']) ? ' msg=' . $d['message'] : ''), 'WAN');
                }
            }
            if (time() - $last_ping > 15) { @mysqli_ping($conn); $last_ping = time(); }
            return strlen($data);
        }
    ]);

    $t_sent      = microtime(true);
    curl_exec($ch);
    $wait_time    = round(microtime(true) - $t_sent, 3);
    $curl_err     = curl_error($ch);
    $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $connect_time = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3);
    curl_close($ch);

    logCron('WAN: Stream done | HTTP=' . $http_code . ' wait=' . $wait_time . 's', 'WAN');

    if ($curl_err) {
        logCron('WAN: cURL error: ' . $curl_err, 'WAN_ERROR');
        return ['success'=>false,'error'=>$curl_err,'source'=>'modal/wan2.2','wait_time'=>$wait_time,'connect_time'=>$connect_time];
    }
    if (empty($video_url)) {
        logCron('WAN: No video_url in response', 'WAN_ERROR');
        return ['success'=>false,'error'=>'No video_url in response','source'=>'modal/wan2.2','wait_time'=>$wait_time,'connect_time'=>$connect_time];
    }

    // Download video directly to disk
    logCron('WAN: Downloading from ' . $video_url, 'WAN');
    $t_dl         = microtime(true);
    $filename     = 'wan_' . $podcast_id . '_' . $scene_id . '.mp4';
    if (!is_dir($vid_folder)) { mkdir($vid_folder, 0755, true); }
    $save_path    = rtrim($vid_folder, '/') . '/' . $filename;
    $fp           = fopen($save_path, 'wb');
    $last_dl_ping = time();

    $dl = curl_init($video_url);
    curl_setopt_array($dl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . WAN_MODAL_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) use (&$last_dl_ping, &$conn, $fp) {
            fwrite($fp, $data);
            if (time() - $last_dl_ping > 15) { @mysqli_ping($conn); $last_dl_ping = time(); }
            return strlen($data);
        }
    ]);
    curl_exec($dl);
    $dl_http = curl_getinfo($dl, CURLINFO_HTTP_CODE);
    curl_close($dl);
    fclose($fp);

    $file_size = file_exists($save_path) ? filesize($save_path) : 0;
    $size_mb   = round($file_size / 1024 / 1024, 2);
    logCron('WAN: Download | HTTP=' . $dl_http . ' size=' . $size_mb . 'MB dl_time=' . elapsed($t_dl), 'WAN');

    if ($file_size < 1000) {
        @unlink($save_path);
        logCron('WAN: File too small (' . $file_size . ' bytes) -- failed', 'WAN_ERROR');
        return ['success'=>false,'error'=>'Download failed size=' . $file_size,'source'=>'modal/wan2.2','wait_time'=>$wait_time,'connect_time'=>$connect_time];
    }

    logCron('WAN: SUCCESS | file=' . $filename . ' size=' . $size_mb . 'MB total=' . elapsed($t_func), 'WAN_SUCCESS');
    return [
        'success'      => true,
        'video_data'   => null,
        'video_size'   => $file_size,
        'video_file'   => $filename,
        'source'       => 'modal/wan2.2',
        'wait_time'    => $wait_time,
        'connect_time' => $connect_time,
        'video_url'    => $video_url,
        'saved_path'   => $save_path,
    ];
}

// ============================================================
// fal.ai ASYNC — find the scene image to use as Kling reference
// ============================================================
function falFindSceneImage($conn, $scene_id) {
    $img_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT image_folder, image_file FROM hdb_podcast_stories WHERE id=" . (int)$scene_id . " LIMIT 1"));
    if (!$img_row || empty($img_row['image_file'])) return '';
    $web_root   = '/var/www/html/videovizard.com';
    $img_folder = trim($img_row['image_folder'] ?? '', '/');
    foreach ([
        $web_root . '/' . $img_folder . '/' . $img_row['image_file'],
        __DIR__ . '/' . $img_folder . '/' . $img_row['image_file'],
    ] as $c) {
        if (file_exists($c) && filesize($c) > 0) return $c;
    }
    return '';
}

// ============================================================
// fal.ai ASYNC — SUBMIT phase (flag 1 -> 6)
// POST each pending fal.ai job to queue.fal.run with ?fal_webhook=...
// so fal.ai calls fal_webhook.php when the video is ready. Non-blocking:
// the cron does NOT wait for the video here.
// ============================================================
function falSubmitPendingJobs($conn, $falApiKey, $limit = 25) {
    if (empty($falApiKey)) { logCron('SUBMIT: no fal key — skipping submit phase', 'FAL_ERROR'); return; }

    // Recover jobs that were submitted but never got a webhook back (flag=6 > 30 min)
    $stale6 = mysqli_query($conn,
        "SELECT id, retry_count FROM hdb_video_gen_que
         WHERE videogen_flag=6 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    while ($stale6 && $r = mysqli_fetch_assoc($stale6)) {
        $rc = (int)$r['retry_count'] + 1;
        if ($rc >= 3) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$rc,
                error_msg='No webhook after 30min (3 attempts)', updated_at=NOW() WHERE id=" . (int)$r['id']);
            logCron('SUBMIT: id=' . $r['id'] . ' got no webhook in 30min x3 — error (flag=4)', 'ERROR');
        } else {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, retry_count=$rc, updated_at=NOW() WHERE id=" . (int)$r['id']);
            logCron('SUBMIT: id=' . $r['id'] . ' got no webhook in 30min — re-queue (attempt ' . $rc . '/3)', 'RECOVERY');
        }
    }
    // Recover ingest jobs that stalled mid-finish (flag=7 > 15 min)
    mysqli_query($conn,
        "UPDATE hdb_video_gen_que SET videogen_flag=5, updated_at=NOW()
         WHERE videogen_flag=7 AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

    // Pending fal.ai jobs = flag=1 and gen_mode is NOT modal (blank defaults to fal.ai)
    $pend = mysqli_query($conn,
        "SELECT * FROM hdb_video_gen_que
         WHERE videogen_flag=1
           AND COALESCE(NULLIF(TRIM(gen_mode),''),'fal.ai') NOT IN ('modal','modal.com')
         ORDER BY podcast_id ASC, id ASC LIMIT " . (int)$limit);
    if (!$pend) { logCron('SUBMIT: DB error: ' . mysqli_error($conn), 'DB_ERROR'); return; }
    if (mysqli_num_rows($pend) === 0) { logCron('SUBMIT: no pending fal.ai jobs', 'INFO'); return; }
    logCron('SUBMIT: ' . mysqli_num_rows($pend) . ' pending fal.ai job(s)', 'INFO');

    while ($row = mysqli_fetch_assoc($pend)) {
        $que_id     = (int)$row['id'];
        $podcast_id = (int)$row['podcast_id'];
        $scene_id   = (int)$row['scene_id'];
        $prompt     = $row['prompt'];

        // fal.ai LTX accepts 6/8/10 — round to nearest
        $duration = isset($row['duration']) && (int)$row['duration'] > 0 ? (int)$row['duration'] : 6;
        $dfal = 6;
        foreach ([6, 8, 10] as $v) { if (abs($v - $duration) < abs($dfal - $duration)) $dfal = $v; }
        $duration = $dfal;

        // Claim atomically so overlapping cron runs don't double-submit
        $claim = mysqli_query($conn,
            "UPDATE hdb_video_gen_que SET videogen_flag=6, request_id=NULL, result_video_url=NULL,
             status='submitting', updated_at=NOW() WHERE id=$que_id AND videogen_flag=1");
        if (!$claim || mysqli_affected_rows($conn) === 0) {
            logCron("SUBMIT: id=$que_id grabbed by another run — skip", 'SKIP'); continue;
        }

        $image_path = falFindSceneImage($conn, $scene_id);
        $has_image  = $image_path !== '';
        if ($has_image) {
            $endpoint = 'https://queue.fal.run/fal-ai/kling-video/v2.1/standard/image-to-video';
            $img_data = base64_encode(file_get_contents($image_path));
            $mime     = strtolower(pathinfo($image_path, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
            $payload  = [
                'prompt'       => $prompt,
                'image_url'    => "data:{$mime};base64,{$img_data}",
                'duration'     => $duration >= 8 ? "10" : "5",
                'aspect_ratio' => '9:16',
            ];
            $model = 'fal.ai/kling-v2.1';
        } else {
            $endpoint = 'https://queue.fal.run/fal-ai/ltx-2.3/text-to-video';
            $payload  = [
                'prompt'         => $prompt,
                'duration'       => $duration,
                'resolution'     => '1080p',
                'aspect_ratio'   => '9:16',
                'fps'            => 25,
                'generate_audio' => false,
            ];
            $model = 'fal.ai/ltx-2.3';
        }

        // fal.ai will POST the result to this URL. Our token + que_id ride along
        // in the query string so the webhook can authenticate and locate the row.
        $webhook    = FAL_WEBHOOK_URL . '?token=' . urlencode(FAL_WEBHOOK_TOKEN) . '&que_id=' . $que_id;
        $submit_url = $endpoint . '?fal_webhook=' . urlencode($webhook);

        logCron("SUBMIT: id=$que_id scene=$scene_id " . ($has_image ? 'image-to-video' : 'text-to-video') . " dur={$duration}s -> $endpoint", 'FAL');

        $ch = curl_init($submit_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Key {$falApiKey}"],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        $request_id = '';
        if (!$cerr && ($code === 200 || $code === 201)) {
            $j = json_decode($resp, true);
            $request_id = $j['request_id'] ?? '';
        }

        if ($request_id !== '') {
            $rid = mysqli_real_escape_string($conn, $request_id);
            $mdl = mysqli_real_escape_string($conn, $model);
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET request_id='$rid', model_used='$mdl',
                status='submitted', updated_at=NOW() WHERE id=$que_id");
            logCron("SUBMIT: id=$que_id queued OK | request_id=$request_id model=$model", 'FAL_SUCCESS');
        } else {
            $emsg = $cerr ?: ('HTTP ' . $code . ' ' . substr((string)$resp, 0, 200));
            $rc   = (int)($row['retry_count'] ?? 0) + 1;
            $em   = mysqli_real_escape_string($conn, $emsg);
            if ($rc >= 3) {
                mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$rc, error_msg='$em', updated_at=NOW() WHERE id=$que_id");
                logCron("SUBMIT: id=$que_id failed $rc times — error (flag=4) | $emsg", 'ERROR');
            } else {
                mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, retry_count=$rc, error_msg='$em', updated_at=NOW() WHERE id=$que_id");
                logCron("SUBMIT: id=$que_id submit failed (attempt $rc/3) — back to pending | $emsg", 'ERROR');
            }
        }
    }
}

// ============================================================
// fal.ai ASYNC — FINISH phase (flag 5 -> 7 -> 3)
// fal_webhook.php has stored result_video_url and set flag=5. Here we
// download the finished video and run the SAME ingest + DB updates the
// blocking path used to do.
// ============================================================
function falFinishReadyJobs($conn, $apiKey, $limit = 25) {
    $ready = mysqli_query($conn,
        "SELECT * FROM hdb_video_gen_que WHERE videogen_flag=5 ORDER BY podcast_id ASC, id ASC LIMIT " . (int)$limit);
    if (!$ready) { logCron('FINISH: DB error: ' . mysqli_error($conn), 'DB_ERROR'); return; }
    if (mysqli_num_rows($ready) === 0) { logCron('FINISH: no result-ready fal.ai jobs', 'INFO'); return; }
    logCron('FINISH: ' . mysqli_num_rows($ready) . ' result-ready fal.ai job(s)', 'INFO');

    while ($row = mysqli_fetch_assoc($ready)) {
        $que_id     = (int)$row['id'];
        $podcast_id = (int)$row['podcast_id'];
        $scene_id   = (int)$row['scene_id'];
        $prompt     = $row['prompt'];
        $duration   = isset($row['duration']) && (int)$row['duration'] > 0 ? (int)$row['duration'] : 6;
        $video_url  = $row['result_video_url'] ?? '';
        $model_used = !empty($row['model_used']) ? $row['model_used'] : 'fal.ai/ltx-2.3';

        // Claim atomically (flag 5 -> 7)
        $claim = mysqli_query($conn,
            "UPDATE hdb_video_gen_que SET videogen_flag=7, updated_at=NOW() WHERE id=$que_id AND videogen_flag=5");
        if (!$claim || mysqli_affected_rows($conn) === 0) {
            logCron("FINISH: id=$que_id grabbed by another run — skip", 'SKIP'); continue;
        }
        if (empty($video_url)) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, error_msg='result_video_url empty', updated_at=NOW() WHERE id=$que_id");
            logCron("FINISH: id=$que_id no result_video_url — error (flag=4)", 'ERROR'); continue;
        }

        $t_job = microtime(true);
        logCron("FINISH: id=$que_id scene=$scene_id downloading $video_url", 'FAL');

        $dl = curl_init($video_url);
        curl_setopt_array($dl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_FOLLOWLOCATION => true]);
        $video_data = curl_exec($dl);
        $dl_http    = curl_getinfo($dl, CURLINFO_HTTP_CODE);
        $dl_size    = curl_getinfo($dl, CURLINFO_SIZE_DOWNLOAD);
        curl_close($dl);

        if ($dl_http !== 200 || !$video_data) {
            $rc = (int)($row['retry_count'] ?? 0) + 1;
            if ($rc >= 3) {
                mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$rc, error_msg='download failed HTTP $dl_http x3', updated_at=NOW() WHERE id=$que_id");
                logCron("FINISH: id=$que_id download failed HTTP=$dl_http x$rc — error (flag=4)", 'ERROR');
            } else {
                mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=5, retry_count=$rc, updated_at=NOW() WHERE id=$que_id");
                logCron("FINISH: id=$que_id download failed HTTP=$dl_http (attempt $rc/3) — back to ready", 'ERROR');
            }
            continue;
        }

        $tmp = sys_get_temp_dir() . '/fal_video_' . getmypid() . '_' . $que_id . '_' . time() . '.mp4';
        if (file_put_contents($tmp, $video_data) === false) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=5, updated_at=NOW() WHERE id=$que_id");
            logCron("FINISH: id=$que_id file_put_contents failed — back to ready", 'ERROR'); continue;
        }
        logCron("FINISH: id=$que_id downloaded " . round($dl_size / 1024 / 1024, 2) . "MB", 'FAL');

        // Reconnect if the download outlived MySQL's wait_timeout
        if (!@mysqli_ping($conn)) { @mysqli_close($conn); require __DIR__ . '/dbconnect_hdb.php'; }

        $pod = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT admin_id, company_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
        $vid_admin_id   = (int)($pod['admin_id']   ?? 0);
        $vid_company_id = (int)($pod['company_id'] ?? 0);

        $ingest = mediaIngest([
            'local_path'      => $tmp,
            'admin_id'        => $vid_admin_id,
            'company_id'      => $vid_company_id,
            'image_folder'    => 'podcast_images',
            'video_folder'    => 'podcast_videos',
            'thumb_folder'    => 'podcast_thumbnails',
            'filename_prefix' => 'scene',
            'context'         => substr($prompt, 0, 300),
            'skip_tagging'    => false,
            'is_ai_generated' => true,
        ], $conn, $apiKey ?: '');
        @unlink($tmp);

        if (empty($ingest['success'])) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=5, updated_at=NOW() WHERE id=$que_id");
            logCron("FINISH: id=$que_id mediaIngest failed: " . ($ingest['message'] ?? '?') . " — back to ready", 'ERROR'); continue;
        }

        $filename   = $ingest['filename'];
        $vid_folder = $ingest['folder'];
        $esc_file   = mysqli_real_escape_string($conn, $filename);
        $esc_folder = mysqli_real_escape_string($conn, $vid_folder);
        $now        = date('Y-m-d H:i:s');

        // Cost: LTX $0.04/sec, Kling $0.07/sec
        $rate     = ['fal.ai/ltx-2.3' => 0.04, 'fal.ai/kling-v2.1' => 0.07][$model_used] ?? 0.04;
        $api_cost = round($duration * $rate, 4);
        $src_esc  = mysqli_real_escape_string($conn, $model_used);

        mysqli_query($conn, "UPDATE hdb_podcast_stories SET duration=$duration WHERE id=$scene_id");
        mysqli_query($conn, "UPDATE hdb_video_gen_que
            SET videogen_flag=3, api_cost_usd=$api_cost, model_used='$src_esc',
                status='completed', completed_at='$now', updated_at='$now' WHERE id=$que_id");

        $admin_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT admin_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
        $vid_admin = (int)($admin_row['admin_id'] ?? 0);
        if ($vid_admin && $api_cost > 0) {
            $src_desc = mysqli_real_escape_string($conn, "Video gen · $model_used · {$duration}s · scene #$scene_id · podcast #$podcast_id");
            mysqli_query($conn, "INSERT INTO hdb_credit_charge_history
                (admin_id, team_lead_id, company_id, podcast_id, brief_id,
                 credits_charged, balance_before, balance_after,
                 charge_type, gen_mode, duration_sec, model_used, description)
                VALUES ($vid_admin, $vid_admin, $vid_company_id, $podcast_id, 0, 0, 0, 0,
                 'video_generation', '$src_esc', $duration, '$src_esc', '$src_desc')");
        }

        mysqli_query($conn, "UPDATE hdb_podcast_stories
            SET videogen_flag=3, video_gen_flag=1, image_file='$esc_file', image_folder='$esc_folder' WHERE id=$scene_id");

        $cnt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS total, SUM(CASE WHEN videogen_flag=3 THEN 1 ELSE 0 END) AS done
             FROM hdb_podcast_stories WHERE podcast_id=$podcast_id"));
        $total = (int)$cnt['total']; $done = (int)$cnt['done'];
        if ($total > 0 && $done === $total) {
            mysqli_query($conn, "UPDATE hdb_podcasts SET videogen_flag=3, updated_at='$now' WHERE id=$podcast_id");
            logCron("FINISH: podcast $podcast_id — ALL $total scenes done!", 'PODCAST_DONE');
        }

        logCron("FINISH: id=$que_id DONE | $vid_folder/$filename " . round($dl_size / 1024 / 1024, 2) . "MB cost=\$$api_cost | " . elapsed($t_job) . " | podcast $podcast_id $done/$total", 'SAVED');
    }
}

// ============================================================
// CRON MAIN
// ============================================================
$t_script_start = microtime(true);
$job_count      = 0;
$job_log        = [];
$max_runtime    = 540;

logCron("", 'INFO');
logCron("╔══════════════════════════════════════════════╗", 'INFO');
logCron("║       VIDEO CRON SCRIPT STARTED              ║", 'INFO');
logCron("║  " . date('Y-m-d H:i:s') . "                          ║", 'INFO');
logCron("╚══════════════════════════════════════════════╝", 'INFO');

// ── fal.ai async phases (run once per invocation, non-blocking) ──
//   1) FINISH: ingest videos fal.ai already returned via webhook (flag 5 -> 3)
//   2) SUBMIT: fire new fal.ai jobs to queue.fal.run with webhook (flag 1 -> 6)
// The webhook (fal_webhook.php) bridges the two by moving flag 6 -> 5.
falFinishReadyJobs($conn, $apiKey, 10);   // ingest is slow (download + GPT-4o tag); cap per run
falSubmitPendingJobs($conn, $falApiKey, 25); // submit is fast (queue accept only)

// ── Modal/WAN synchronous loop below — fal.ai never enters it ────
while (true) {

    // Safety: stop if approaching PHP time limit
    if ((microtime(true) - $t_script_start) > $max_runtime) {
        logCron("Max runtime {$max_runtime}s reached — exiting. Cron will pick up remaining jobs next minute.", 'INFO');
        break;
    }

    // ── STEP 1: Reset stuck jobs (flag=2 for >15 min) ───────
    mysqli_query($conn,
        "UPDATE hdb_video_gen_que
         SET videogen_flag = 1, updated_at = NOW()
         WHERE videogen_flag = 2
           AND updated_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)"
    );
    $stuck = mysqli_affected_rows($conn);
    if ($stuck > 0) logCron("Reset $stuck stuck job(s) (flag=2 >15min) back to pending", 'RECOVERY');

    // ── Check if already processing (flag = 2) ──────────────
    $t_db = microtime(true);
    $chk  = mysqli_query($conn,
        "SELECT id, podcast_id, scene_id FROM hdb_video_gen_que WHERE videogen_flag = 2 LIMIT 1"
    );

    if (!$chk) {
        logCron("DB ERROR on flag=2 check: " . mysqli_error($conn), 'DB_ERROR');
        break;
    }

    if (mysqli_num_rows($chk) > 0) {
        $row = mysqli_fetch_assoc($chk);
        logCron("Already processing (flag=2) | id=" . $row['id'] . " podcast_id=" . $row['podcast_id'] . " scene_id=" . $row['scene_id'] . " — exiting script.", 'SKIP');
        break;
    }

    // ── STEP 2: Get next pending MODAL record (flag = 1) ─────
    // fal.ai jobs are handled async by falSubmitPendingJobs/falFinishReadyJobs
    // above — this blocking loop only serves modal/WAN.
    $next = mysqli_query($conn,
        "SELECT * FROM hdb_video_gen_que
         WHERE videogen_flag = 1
           AND (gen_mode = 'modal' OR gen_mode = 'modal.com')
         ORDER BY podcast_id ASC, id ASC
         LIMIT 1"
    );

    if (!$next) {
        logCron("DB ERROR fetching flag=1 row: " . mysqli_error($conn), 'DB_ERROR');
        break;
    }

    if (mysqli_num_rows($next) === 0) {
        logCron("No pending records (flag=1) — queue empty, exiting loop.", 'IDLE');
        break;
    }

    $job_count++;
    $t_start    = microtime(true);
    $row        = mysqli_fetch_assoc($next);
    $que_id     = (int)$row['id'];
    $podcast_id = (int)$row['podcast_id'];
    $scene_id   = (int)$row['scene_id'];
    $prompt     = $row['prompt'];
    $vid_folder = !empty($row['video_folder']) ? rtrim($row['video_folder'], '/') : 'user_videos';
    $media_type = $row['media_type'];
    $gen_mode   = !empty($row['gen_mode']) ? trim($row['gen_mode']) : 'fal.ai';

    // Video settings — fixed for now, can be added as columns later
    $duration     = isset($row['duration']) && (int)$row['duration'] > 0 ? (int)$row['duration'] : 6;
    // fal.ai LTX only accepts 6, 8, 10 seconds — round to nearest valid value
    $fal_durations = [6, 8, 10];
    $duration_fal  = 6;
    foreach ($fal_durations as $v) {
        if (abs($v - $duration) < abs($duration_fal - $duration)) $duration_fal = $v;
    }
    if ($gen_mode !== 'modal' && $gen_mode !== 'modal.com') $duration = $duration_fal;
    $aspect_ratio = '9:16';
    $resolution   = '1080p';
    $fps          = 25;

    logCron("", 'INFO');
    logCron("╔══════════════════════════════════════════════╗", 'INFO');
    logCron("║         JOB #$job_count STARTED                       ║", 'INFO');
    logCron("║  " . date('Y-m-d H:i:s') . "                          ║", 'INFO');
    logCron("╚══════════════════════════════════════════════╝", 'INFO');
    logCron("── QUEUE RECORD ──────────────────────────────────", 'INFO');
    logCron("   que_id     : $que_id",                            'INFO');
    logCron("   podcast_id : $podcast_id",                        'INFO');
    logCron("   scene_id   : $scene_id",                          'INFO');
    logCron("   media_type : $media_type",                        'INFO');
    logCron("   folder     : $vid_folder",                        'INFO');
    logCron("   prompt_len : " . strlen($prompt) . " chars",      'INFO');
    logCron("   gen_mode   : $gen_mode",                          'INFO');
    logCron("   duration   : {$duration}s",                       'INFO');
    logCron("   ratio      : $aspect_ratio",                      'INFO');
    logCron("   resolution : $resolution",                        'INFO');
    logCron("   db_lookup  : " . elapsed($t_db),                  'INFO');
    logCron("──────────────────────────────────────────────────", 'INFO');

    // ── STEP 3: Lock the record — set flag = 2 ──────────────
    $lock = mysqli_query($conn,
        "UPDATE hdb_video_gen_que
         SET videogen_flag = 2,
             updated_at    = '" . date('Y-m-d H:i:s') . "'
         WHERE id = $que_id AND videogen_flag = 1"
    );

    if (!$lock || mysqli_affected_rows($conn) === 0) {
        logCron("Could not lock id=$que_id — another process grabbed it. Trying next...", 'SKIP');
        continue;
    }
    logCron("Record locked (flag=2) | id=$que_id", 'PROCESSING');

    // -- STEP 4: Generate video (route by gen_mode) -----------
    logCron('-- VIDEO GENERATION ---------------------------------', 'INFO');
    logCron('   gen_mode   : ' . $gen_mode,                        'INFO');
    logCron('   Sending at : ' . date('H:i:s'),                    'INFO');

    $t_gen = microtime(true);

    if ($gen_mode === 'modal' || $gen_mode === 'modal.com') {
        logCron('   Provider   : WAN 2.2 / Modal.com',             'INFO');
        // Save to a temp path — mediaIngest() below moves the finished file
        // into the real company-scoped library folder and tags it.
        $result = generateWithWanModal($prompt, sys_get_temp_dir(), $podcast_id, $scene_id, $duration);
    } else {
        logCron('   Provider   : fal.ai LTX Video 2.3 (image-to-video)', 'INFO');
        // Use the scene's actual generated image (hdb_podcast_stories) as the
        // Kling reference. NOT hdb_video_gen_que.video_file — that column is
        // this video job's own (not-yet-created) output filename, not an
        // input image, so checking it here could never have found anything.
        $image_path = '';
        $img_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT image_folder, image_file FROM hdb_podcast_stories WHERE id=$scene_id LIMIT 1"));
        if ($img_row && !empty($img_row['image_file'])) {
            $web_root   = '/var/www/html/videovizard.com';
            $img_folder = trim($img_row['image_folder'] ?? '', '/');
            $candidates = [
                $web_root . '/' . $img_folder . '/' . $img_row['image_file'],
                __DIR__ . '/' . $img_folder . '/' . $img_row['image_file'],
            ];
            foreach ($candidates as $c) {
                if (file_exists($c) && filesize($c) > 0) { $image_path = $c; break; }
            }
            if ($image_path) {
                logCron("FAL: Image found | path=$image_path size=" . round(filesize($image_path)/1024) . "KB", 'FAL');
            } else {
                logCron("FAL: Image NOT found | expected=$img_folder/" . $img_row['image_file'] . " — using text-to-video", 'FAL');
            }
        } else {
            logCron("FAL: No image on hdb_podcast_stories for scene_id=$scene_id — using text-to-video", 'FAL');
        }
        $result = generateWithFalVideo($prompt, $duration, $aspect_ratio, $resolution, $fps, $image_path);
    }

    if (!$result['success']) {
        $err_msg = is_array($result['error']) ? json_encode($result['error']) : ($result['error'] ?? 'unknown');
        // Check retry count — if failed 3+ times, mark as error (flag=4) to stop retrying
        $retry_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT retry_count FROM hdb_video_gen_que WHERE id=$que_id LIMIT 1"));
        $retry_count = (int)($retry_row['retry_count'] ?? 0) + 1;
        if ($retry_count >= 3) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$retry_count,
                error_msg='" . mysqli_real_escape_string($conn, $err_msg) . "', updated_at=NOW() WHERE id=$que_id");
            logCron("Generation FAILED $retry_count times — marking as error (flag=4) | id=$que_id podcast=$podcast_id | error: $err_msg", 'ERROR');
        } else {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, retry_count=$retry_count,
                error_msg='" . mysqli_real_escape_string($conn, $err_msg) . "', updated_at=NOW() WHERE id=$que_id");
            logCron("Generation FAILED (attempt $retry_count/3) — reset to pending | id=$que_id podcast=$podcast_id | error: $err_msg", 'ERROR');
        }
        continue;
    }

    // -- STEP 5: Save video to a temp path, then ingest it --------
    $t_save = microtime(true);

    if (!empty($result['video_file'])) {
        // WAN/Modal — we pointed generateWithWanModal() at the system temp
        // dir above, so saved_path is already a temp file ready to ingest.
        $tmp_video_path = $result['saved_path'];
        logCron('Video on disk (WAN, temp) | path=' . $tmp_video_path, 'SAVED');
    } else {
        // fal.ai: video_data is in memory — write it to a temp path now.
        $tmp_video_path = sys_get_temp_dir() . '/fal_video_' . getmypid() . '_' . time() . '.mp4';
        if (file_put_contents($tmp_video_path, $result['video_data']) === false) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, updated_at='" . date('Y-m-d H:i:s') . "' WHERE id=$que_id");
            logCron('file_put_contents FAILED path=' . $tmp_video_path . ' -- reset to flag=1', 'ERROR');
            continue;
        }
        logCron('Video saved (fal, temp) | path=' . $tmp_video_path . ' save_time=' . elapsed($t_save), 'SAVED');
    }

    // ── Cost calculation ─────────────────────────────────────
    $vid_size_mb = isset($result['video_size']) ? round($result['video_size'] / 1024 / 1024, 2) : 0;
    $gen_time    = elapsed($t_gen);

    // Cost per second by provider
    // Cost per second: LTX $0.04/sec, Kling $0.07/sec, WAN Modal billed separately
    $cost_rates = ['fal.ai/ltx-2.3' => 0.04, 'fal.ai/kling-v2.1' => 0.07, 'modal/wan2.2' => 0.00];
    $rate       = $cost_rates[$result['source']] ?? 0.04;
    $api_cost   = round($duration * $rate, 4);
    $est_cost   = '$' . number_format($api_cost, 4) . ' (' . ($result['source'] ?? '') . ' @$' . $rate . '/sec)';
    logCron("── DB UPDATES ────────────────────────────────────", 'INFO');
    $t_db_update = microtime(true);

    // Only reconnect if the connection actually went stale. A long fal.ai
    // generation can exceed MySQL's wait_timeout, but if it's still alive
    // there's no reason to risk a reconnect hiccup losing the flag=3 update
    // for a video that already succeeded and is sitting on disk.
    if (!@mysqli_ping($conn)) {
        logCron("   Connection stale — reconnecting...", 'DB');
        @mysqli_close($conn);
        $reconnected = false;
        for ($i = 1; $i <= 3; $i++) {
            require __DIR__ . '/dbconnect_hdb.php';
            if ($conn && !mysqli_connect_errno()) { $reconnected = true; break; }
            logCron("   Reconnect attempt $i/3 failed — " . mysqli_connect_error() . " — retrying...", 'DB_WARN');
            sleep(2);
        }
        if (!$reconnected) {
            logCron("FATAL: DB reconnect failed after 3 attempts — " . mysqli_connect_error() . " | que_id=$que_id video sitting at temp path $tmp_video_path, not yet ingested — left at flag=2 for manual recovery", 'DB_ERROR');
            break;
        }
        logCron("   DB reconnected | reconnect_time=" . elapsed($t_db_update), 'DB');
    } else {
        logCron("   Connection still alive — no reconnect needed", 'DB');
    }

    // ── Ingest the finished video — same pipeline as user uploads and
    // scene images: lands in the company-scoped library folder, gets
    // GPT-4o vision tagged + embedded, and is inserted into hdb_image_data
    // so future scenes can reuse it instead of generating a near-duplicate.
    $pod_meta_row   = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT admin_id, company_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    $vid_admin_id   = (int)($pod_meta_row['admin_id']   ?? 0);
    $vid_company_id = (int)($pod_meta_row['company_id'] ?? 0);

    $ingest = mediaIngest([
        'local_path'      => $tmp_video_path,
        'admin_id'        => $vid_admin_id,
        'company_id'      => $vid_company_id,
        'image_folder'    => 'podcast_images',
        'video_folder'    => 'podcast_videos',
        'thumb_folder'    => 'podcast_thumbnails',
        'filename_prefix' => 'scene',
        'context'         => substr($prompt, 0, 300),
        'skip_tagging'    => false,
        'is_ai_generated' => true,
    ], $conn, $apiKey ?: '');
    @unlink($tmp_video_path);

    if (empty($ingest['success'])) {
        mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, updated_at='" . date('Y-m-d H:i:s') . "' WHERE id=$que_id");
        logCron('mediaIngest FAILED: ' . ($ingest['message'] ?? 'unknown') . ' -- reset to flag=1', 'ERROR');
        continue;
    }
    // mediaIngest() assigns its own filename/folder — use those from here
    // on, not the old deterministic scene_{podcast}_{scene}.mp4 guess.
    $filename   = $ingest['filename'];
    $vid_folder = $ingest['folder'];
    logCron("Video ingested | id=" . ($ingest['image_id'] ?? '?') . " $vid_folder/$filename tagged=" . (!empty($ingest['tagged']) ? 'Y' : 'N'), 'SAVED');

    $esc_file   = mysqli_real_escape_string($conn, $filename);
    $esc_folder = mysqli_real_escape_string($conn, $vid_folder);
    $now        = date('Y-m-d H:i:s');
    // Update story duration to match actual generated video duration
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET duration = $duration WHERE id = $scene_id");
    logCron('Updated hdb_podcast_stories.duration=' . $duration . 's | scene_id=' . $scene_id, 'DB_UPDATE');

    // ── STEP 6: Update que record — flag = 3 (done) + cost ──
    $t_q     = microtime(true);
    $src_esc = mysqli_real_escape_string($conn, $result['source'] ?? '');
    $upd_que = mysqli_query($conn,
        "UPDATE hdb_video_gen_que
         SET videogen_flag  = 3,
             api_cost_usd   = $api_cost,
             model_used     = '$src_esc',
             status         = 'completed',
             completed_at   = '$now',
             updated_at     = '$now'
         WHERE id = $que_id"
    );
    if (!$upd_que) {
        logCron("   [1/3] hdb_video_gen_que UPDATE FAILED | id=$que_id | error: " . mysqli_error($conn) . " | video already ingested as $vid_folder/$filename but flag stuck at 2 — manual fix needed", 'DB_ERROR');
    } elseif (mysqli_affected_rows($conn) === 0) {
        logCron("   [1/3] hdb_video_gen_que UPDATE matched 0 rows | id=$que_id — row may have been deleted or id changed", 'DB_WARN');
    } else {
        logCron("   [1/3] hdb_video_gen_que updated    | id=$que_id flag=3 cost=\$$api_cost | " . elapsed($t_q), 'DB_UPDATE');
    }

    // ── Log actual API cost to charge history ────────────────
    $admin_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT admin_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    $vid_admin = (int)($admin_row['admin_id'] ?? 0);
    if ($vid_admin && $api_cost > 0) {
        $src_desc = mysqli_real_escape_string($conn,
            "Video gen · " . ($result['source'] ?? 'fal.ai') . " · {$duration}s · scene #$scene_id · podcast #$podcast_id");
        mysqli_query($conn, "INSERT INTO hdb_credit_charge_history
            (admin_id, team_lead_id, company_id, podcast_id, brief_id,
             credits_charged, balance_before, balance_after,
             charge_type, gen_mode, duration_sec, model_used, description)
            VALUES
            ($vid_admin, $vid_admin, 0, $podcast_id, 0,
             0, 0, 0,
             'video_generation', '$src_esc', $duration, '$src_esc', '$src_desc')")
            or logCron("Credit history insert warn: " . mysqli_error($conn), 'DB_WARN');
    }

    // ── STEP 7: Update hdb_podcast_stories ──────────────────
    $t_s            = microtime(true);
    $update_stories = mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET videogen_flag = 3,
             video_gen_flag = 1,
             image_file    = '$esc_file',
             image_folder  = '$esc_folder'
         WHERE id = $scene_id"
    );

    if (!$update_stories || mysqli_affected_rows($conn) === 0) {
        logCron("   [2/3] hdb_podcast_stories WARN: not updated | scene_id=$scene_id | error: " . mysqli_error($conn), 'DB_WARN');
    } else {
        logCron("   [2/3] hdb_podcast_stories updated  | scene_id=$scene_id file=$filename | " . elapsed($t_s), 'DB_UPDATE');
    }

    // ── STEP 8: Check if all stories done ───────────────────
    $t_p      = microtime(true);
    $all_done = mysqli_query($conn,
        "SELECT
             COUNT(*)                                             AS total,
             SUM(CASE WHEN videogen_flag = 3 THEN 1 ELSE 0 END) AS done
         FROM hdb_podcast_stories
         WHERE podcast_id = $podcast_id"
    );

    if (!$all_done) {
        logCron("   [3/3] DB ERROR checking progress | podcast_id=$podcast_id: " . mysqli_error($conn), 'DB_ERROR');
    } else {
        $counts    = mysqli_fetch_assoc($all_done);
        $total     = (int)$counts['total'];
        $done      = (int)$counts['done'];
        $remaining = $total - $done;

        logCron("   [3/3] Podcast progress | podcast_id=$podcast_id done=$done / total=$total remaining=$remaining | " . elapsed($t_p), 'PROGRESS');

        if ($total > 0 && $done === $total) {
            $update_podcast = mysqli_query($conn,
                "UPDATE hdb_podcasts
                 SET videogen_flag = 3,
                     updated_at    = '$now'
                 WHERE id = $podcast_id"
            );
            if (!$update_podcast || mysqli_affected_rows($conn) === 0) {
                logCron("   [3/3] hdb_podcasts WARN: not updated | podcast_id=$podcast_id | error: " . mysqli_error($conn), 'DB_WARN');
            } else {
                logCron("   [3/3] hdb_podcasts updated — ALL $total scenes done! | podcast_id=$podcast_id videogen_flag=3", 'PODCAST_DONE');
            }
        }
    }

    logCron("   Total DB update time : " . elapsed($t_db_update), 'INFO');
    logCron("──────────────────────────────────────────────────", 'INFO');

    // ── JOB SUMMARY ─────────────────────────────────────────
    $total_elapsed = round(microtime(true) - $t_start, 3);
    $t_mins        = floor($total_elapsed / 60);
    $t_secs        = round($total_elapsed - ($t_mins * 60), 1);
    $human_total   = $t_mins > 0 ? "{$t_mins}m {$t_secs}s" : "{$t_secs}s";

    logCron("╔══════════════════════════════════════════════╗",  'INFO');
    logCron("║         VIDEO JOB COMPLETE                   ║",  'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  que_id       : $que_id",                         'INFO');
    logCron("║  podcast_id   : $podcast_id",                     'INFO');
    logCron("║  scene_id     : $scene_id",                       'INFO');
    logCron("║  file         : $filename",                       'INFO');
    logCron("║  size         : {$vid_size_mb}MB",                'INFO');
    logCron("║  provider     : " . $result['source'],            'INFO');
    logCron("║  gen_mode     : $gen_mode",                       'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  connect time : " . $result['connect_time'] . "s", 'INFO');
    logCron("║  API wait     : " . $result['wait_time'] . "s",   'INFO');
    logCron("║  gen time     : $gen_time",                       'INFO');
    logCron("║  db time      : " . elapsed($t_db_update),        'INFO');
    logCron("║  TOTAL time   : $human_total",                    'INFO');
    logCron("║  est. cost    : $est_cost",                       'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  started      : " . date('Y-m-d H:i:s', (int)$t_start), 'INFO');
    logCron("║  ended        : " . date('Y-m-d H:i:s'),          'INFO');
    logCron("╚══════════════════════════════════════════════╝",  'INFO');

    // Record job stats for final summary
    $job_log[] = [
        'job'      => $job_count,
        'scene_id' => $scene_id,
        'size_mb'  => $vid_size_mb,
        'gen_mode' => $gen_mode,
        'provider' => $result['source'],
        'api_wait' => $result['wait_time'] . 's',
        'total'    => $gen_time,
        'cost'     => $est_cost,
    ];

} // ── END WHILE LOOP ─────────────────────────────────────────

// ── SCRIPT SUMMARY ──────────────────────────────────────────
$script_elapsed = round(microtime(true) - $t_script_start, 1);
$s_mins         = floor($script_elapsed / 60);
$s_secs         = round($script_elapsed - ($s_mins * 60), 1);
$human_script   = $s_mins > 0 ? "{$s_mins}m {$s_secs}s" : "{$s_secs}s";

// Total estimated cost for this run
$total_cost = array_sum(array_map(function($j) {
    return (float)str_replace('$', '', $j['cost']);
}, $job_log));

logCron("", 'INFO');
logCron("╔══════════════════════════════════════════════════════════════╗", 'INFO');
logCron("║           VIDEO CRON SCRIPT FINISHED                         ║", 'INFO');
logCron("╠══════════════════════════════════════════════════════════════╣", 'INFO');
logCron("║  Jobs processed : $job_count",                                 'INFO');
logCron("║  Script runtime : $human_script",                              'INFO');
logCron("║  Total est cost : $" . number_format($total_cost, 2),          'INFO');
logCron("║  Ended at       : " . date('Y-m-d H:i:s'),                     'INFO');
logCron("╠══════════════════════════════════════════════════════════════╣", 'INFO');
logCron("║  #  │ scene  │ mode   │  size    │ provider      │ api wait │ cost  │ total  ║", 'INFO');
logCron("║─────┼────────┼────────┼──────────┼───────────────┼──────────┼───────┼───────║", 'INFO');

foreach ($job_log as $j) {
    $num      = str_pad($j['job'],              3,  ' ', STR_PAD_LEFT);
    $scene    = str_pad($j['scene_id'],         6,  ' ', STR_PAD_LEFT);
    $mode     = str_pad($j['gen_mode'],         6,  ' ', STR_PAD_RIGHT);
    $size     = str_pad($j['size_mb'] . 'MB',   8,  ' ', STR_PAD_LEFT);
    $provider = str_pad($j['provider'],         13, ' ', STR_PAD_RIGHT);
    $wait     = str_pad($j['api_wait'],         8,  ' ', STR_PAD_LEFT);
    $cost     = str_pad($j['cost'],             5,  ' ', STR_PAD_LEFT);
    $total    = str_pad($j['total'],            6,  ' ', STR_PAD_LEFT);
    logCron("║  $num │ $scene │ $mode │ $size │ $provider │ $wait │ $cost │ $total  ║", 'INFO');
}

logCron("╚══════════════════════════════════════════════════════════════╝", 'INFO');

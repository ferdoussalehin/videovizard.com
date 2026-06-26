<?php
// ============================================================
// pexels_download.php
// Accepts POST: url, file, group, subgroup
// Saves video to podcast_videos/ on server — NO streaming to browser
// Returns JSON response
// ============================================================
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
set_time_limit(300);

// Catch fatal errors and return as JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line']
        ]);
    }
});

include 'config.php';

if (!file_exists(__DIR__ . '/media_ingest.php')) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'media_ingest.php not found at ' . __DIR__]);
    exit;
}
require_once __DIR__ . '/media_ingest.php';

header('Content-Type: application/json');

$openAiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;
$SAVE_DIR  = __DIR__ . '/podcast_videos';
if (!is_dir($SAVE_DIR)) mkdir($SAVE_DIR, 0755, true);

function pd_log(string $msg): void {
    file_put_contents(__DIR__ . '/a_errors.log',
        date('[Y-m-d H:i:s] ') . "[pexels_download] $msg\n", FILE_APPEND);
}

// ── Debug ──────────────────────────────────────────────────
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    $ff = trim(shell_exec('which ffmpeg 2>/dev/null') ?: 'NOT FOUND');
    echo "ffmpeg:              $ff\n";
    echo "ffmpeg exists:       " . (file_exists($ff) ? 'YES' : 'NO') . "\n";
    echo "exec() available:    " . (function_exists('exec')      ? 'YES' : 'NO') . "\n";
    echo "curl available:      " . (function_exists('curl_init') ? 'YES' : 'NO') . "\n";
    echo "podcast_videos/:     " . (is_writable($SAVE_DIR)             ? 'WRITABLE' : 'NOT WRITABLE') . "\n";
    echo "podcast_thumbnails/: " . (is_writable(__DIR__.'/podcast_thumbnails') ? 'WRITABLE' : 'NOT WRITABLE') . "\n";
    echo "pexel_key set:       " . (!empty($pexel_key) ? 'YES (' . strlen($pexel_key) . ' chars)' : 'NO') . "\n";
    echo "openai key set:      " . (!empty($openAiKey) ? 'YES' : 'NO') . "\n";
    echo "_POST keys:          " . implode(', ', array_keys($_POST)) . "\n";
    exit;
}

// ── Read params ────────────────────────────────────────────
$video_url      = trim($_POST['url']      ?? $_GET['url']      ?? '');
$filename       = trim($_POST['file']     ?? $_GET['file']     ?? '');
$promo_group    = trim($_POST['group']    ?? $_GET['group']    ?? '');
$promo_subgroup = trim($_POST['subgroup'] ?? $_GET['subgroup'] ?? '');

pd_log("request: url=" . substr($video_url, 0, 80) . " file=$filename");

if (!$video_url) { echo json_encode(['success'=>false,'message'=>'Missing url parameter']);  exit; }
if (!$filename)  { echo json_encode(['success'=>false,'message'=>'Missing filename parameter']); exit; }

$filename  = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($filename));
if (!preg_match('/\.mp4$/i', $filename)) $filename .= '.mp4';

$save_path = $SAVE_DIR . '/' . $filename;

// ── File already on disk? Skip the download, but still let ──
// ── mediaIngest() decide (per group/subgroup) whether a new ──
// ── DB row is needed — don't short-circuit on disk alone.   ──
$already_on_disk = (file_exists($save_path) && filesize($save_path) > 10000);
if ($already_on_disk) {
    pd_log("file already on disk, will still check DB for this group: $filename");
}

$tmp = $save_path; // default: reuse the on-disk copy as the ingest source

if (!$already_on_disk) {
    // ── Download raw video from Pexels ────────────────────────
    $tmp = sys_get_temp_dir() . '/px_raw_' . getmypid() . '_' . time() . '.mp4';
    pd_log("downloading from Pexels...");

    $fp = fopen($tmp, 'wb');
    if (!$fp) {
        echo json_encode(['success'=>false,'message'=>'Cannot write to temp dir: ' . sys_get_temp_dir()]);
        exit;
    }

    $ch = curl_init($video_url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => 'VideoVizard-MediaIngest/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($http_code !== 200 || $curl_err || !file_exists($tmp) || filesize($tmp) < 1000) {
        @unlink($tmp);
        pd_log("curl failed HTTP $http_code: $curl_err");
        echo json_encode(['success'=>false,'message'=>"Failed to fetch video (HTTP $http_code)" . ($curl_err ? ": $curl_err" : '')]);
        exit;
    }

    pd_log("downloaded " . round(filesize($tmp)/1048576, 2) . " MB");
}

// ── Run through media_ingest ───────────────────────────────
// admin_id=0, company_id=0 → shared stock library
// mediaIngest() does its own dedupe check scoped to
// image_name + promo_group + promo_subgroup + admin_id + company_id,
// so the same filename can legitimately be linked to multiple groups.
$result = mediaIngest([
    'local_path'      => $tmp,
    'dedupe_filename' => $filename,
    'admin_id'        => 0,
    'company_id'      => 0,
    'promo_group'     => $promo_group,
    'promo_subgroup'  => $promo_subgroup,
    'filename_prefix' => 'pexels',
    'video_folder'    => 'podcast_videos',
    'image_folder'    => 'podcast_images',
    'thumb_folder'    => 'podcast_thumbnails',
    'root_dir'        => __DIR__,
    'max_video_sec'   => $already_on_disk ? 0 : 10,
    'reencode'        => !$already_on_disk, // already trimmed/encoded if it was already on disk
    'skip_if_exists'  => true,
], $conn, $openAiKey ?? '');

if (!$already_on_disk) {
    @unlink($tmp);
}

pd_log("ingest result: success=" . ($result['success']?'yes':'no') .
       " id=" . ($result['image_id']??0) .
       " tagged=" . ($result['tagged']?'yes':'no'));

echo json_encode($result);
exit;

<?php
/**
 * stitch_podcast_video.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Standalone script: fetches all scenes from hdb_podcast_stories for a given
 * podcast_id, uploads each image + audio to the VPS, then instructs the VPS
 * to FFmpeg-stitch them into one long MP4.
 *
 * Usage (CLI):
 *   php stitch_podcast_video.php --podcast_id=42
 *
 * Usage (browser):
 *   https://videovizard.com/stitch_podcast_video.php?podcast_id=42&secret=VS_StitchLocal!
 *
 * The VPS needs a companion endpoint: vps_stitch.php  (see bottom of this file)
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);   // long-running

$isCLI = (php_sapi_name() === 'cli');

// Allow browser access only with a local secret
if (!$isCLI) {
    $BROWSER_SECRET = 'VS_StitchLocal!';
    if (($_GET['secret'] ?? '') !== $BROWSER_SECRET) {
        http_response_code(403);
        die('Forbidden');
    }
}

// ── Config ────────────────────────────────────────────────────────────────────
include 'dbconnect_hdb.php';

define('VPS_STITCH_URL', 'http://187.124.249.46/videovizard.com/vps_stitch.php');
define('SECRET_KEY',     'VS_FFmpeg_2026_Secret!');

// Absolute server path to videovizard public root
define('VV_ROOT',    '/home/syjy0p3q5yjb/public_html/videovizard.com');

// Public base URL (used so the VPS can pull files)
define('VV_BASE_URL', 'https://videovizard.com');

define('LOG_FILE',   VV_ROOT . '/a_errors.log');

// ── Helpers ───────────────────────────────────────────────────────────────────
function logMsg(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . '[stitch] ' . $msg . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function vpsPost(string $url, array $fields, int $timeout = 30): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $fields,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { logMsg("cURL error: $err"); return null; }
    $data = json_decode($resp, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMsg("VPS non-JSON response: " . substr($resp, 0, 300));
        return null;
    }
    return $data;
}

// ── Get podcast_id ────────────────────────────────────────────────────────────
$podcast_id = $isCLI
    ? (int)(getopt('', ['podcast_id:'])['podcast_id'] ?? 0)
    : (int)($_GET['podcast_id'] ?? 0);

if (!$podcast_id) {
    die("ERROR: podcast_id required.\n  CLI:     php stitch_podcast_video.php --podcast_id=42\n  Browser: ?podcast_id=42&secret=...\n");
}

logMsg("=== START stitch for podcast_id=$podcast_id ===");

// ── DB connect ────────────────────────────────────────────────────────────────
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) die('DB connect failed: ' . mysqli_connect_error() . "\n");
mysqli_set_charset($conn, 'utf8mb4');

// ── Load podcast row ──────────────────────────────────────────────────────────
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
if (!$row) die("Podcast #$podcast_id not found.\n");

$img_folder = trim(trim($row['image_folder'] ?? ''), '/') ?: 'podcast_images';
$podcast_title = $row['title'] ?? "Podcast $podcast_id";
logMsg("Title: $podcast_title | img_folder: $img_folder");

// ── Load scenes ───────────────────────────────────────────────────────────────
$scenes = [];
$q = mysqli_query($conn,
    "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
while ($r = mysqli_fetch_assoc($q)) $scenes[] = $r;

if (!$scenes) die("No scenes found for podcast #$podcast_id\n");
logMsg("Loaded " . count($scenes) . " scenes");

// ── Build scene manifest for VPS ──────────────────────────────────────────────
// Each scene needs:
//   image_url  → public URL of the background image (or video clip)
//   audio_url  → public URL of the scene's TTS audio
//   duration   → seconds (derived from audio; fallback to 5s)
//   text       → caption text (optional, for future burn-in)

$scene_manifest = [];

foreach ($scenes as $idx => $sc) {
    $scene_num = $idx + 1;

    // ── Resolve background: prefer video_file, then image_file ───────────────
    $bg_file = trim($sc['video_file'] ?? '');
    if (!$bg_file) {
        // Pick first enabled image slot
        $img_slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
        foreach ($img_slots as $slot) {
            $f = trim($sc[$slot] ?? '');
            if ($f) { $bg_file = $f; break; }
        }
    }

    // Per-scene image_folder override (if column exists)
    $scene_folder = trim($sc['image_folder'] ?? '') ?: $img_folder;

    $bg_url = '';
    if ($bg_file) {
        $local_path = VV_ROOT . '/' . $scene_folder . '/' . $bg_file;
        if (file_exists($local_path)) {
            $bg_url = VV_BASE_URL . '/' . $scene_folder . '/' . rawurlencode($bg_file);
        } else {
            logMsg("  Scene $scene_num: bg file not found locally: $local_path");
        }
    }

    if (!$bg_url) {
        logMsg("  Scene $scene_num: WARNING — no background image/video, skipping");
        continue;
    }

    // ── Resolve audio ─────────────────────────────────────────────────────────
    $audio_file = trim($sc['audio_file'] ?? '');
    $audio_url  = '';
    $duration   = 5; // fallback seconds

    if ($audio_file) {
        // audio files are typically in /tts_audio/ or same folder
        $audio_candidates = [
            VV_ROOT . '/tts_audio/' . $audio_file,
            VV_ROOT . '/' . $scene_folder . '/' . $audio_file,
            VV_ROOT . '/' . $audio_file,
        ];
        foreach ($audio_candidates as $ap) {
            if (file_exists($ap)) {
                // Determine public sub-path relative to VV_ROOT
                $rel = str_replace(VV_ROOT . '/', '', $ap);
                $audio_url = VV_BASE_URL . '/' . $rel;

                // Estimate duration from file size (rough: ~16kB/s for MP3)
                $size_kb = filesize($ap) / 1024;
                $duration = max(2, round($size_kb / 16));
                break;
            }
        }
        if (!$audio_url) {
            logMsg("  Scene $scene_num: audio file not found: $audio_file");
        }
    }

    $scene_manifest[] = [
        'scene_num' => $scene_num,
        'story_id'  => (int)$sc['id'],
        'bg_url'    => $bg_url,
        'audio_url' => $audio_url,
        'duration'  => $duration,
        'text'      => strip_tags($sc['story_text'] ?? $sc['text'] ?? ''),
    ];

    logMsg("  Scene $scene_num: bg=" . basename($bg_url) . " | audio=" . ($audio_url ? basename($audio_url) : 'NONE') . " | dur={$duration}s");
}

if (!$scene_manifest) die("No valid scenes could be built — aborting.\n");
logMsg("Built manifest with " . count($scene_manifest) . " scenes");

// ── Send to VPS ───────────────────────────────────────────────────────────────
$output_filename = 'podcast_' . $podcast_id . '_stitched.mp4';

logMsg("Sending stitch job to VPS...");
$vps_resp = vpsPost(VPS_STITCH_URL, [
    'secret_key'      => SECRET_KEY,
    'action'          => 'stitch',
    'podcast_id'      => $podcast_id,
    'output_filename' => $output_filename,
    'scenes_json'     => json_encode($scene_manifest),
], timeout: 30);

if (!$vps_resp) {
    die("ERROR: No response from VPS.\n");
}

logMsg("VPS response: " . json_encode($vps_resp));

if (empty($vps_resp['success'])) {
    die("VPS ERROR: " . ($vps_resp['message'] ?? 'unknown') . "\n");
}

$job_id = $vps_resp['job_id'] ?? '';
logMsg("Job started — job_id=$job_id");

// ── Poll until done ───────────────────────────────────────────────────────────
$poll_interval = 8;   // seconds between polls
$max_polls     = 120; // ~16 minutes max
$polls         = 0;

logMsg("Polling VPS for completion...");

while ($polls < $max_polls) {
    sleep($poll_interval);
    $polls++;

    $status_url = VPS_STITCH_URL . '?action=status&secret_key=' . urlencode(SECRET_KEY) . '&job_id=' . urlencode($job_id);
    $status_resp = @file_get_contents($status_url);
    if (!$status_resp) { logMsg("  Poll $polls: no response, retrying..."); continue; }

    $status = json_decode($status_resp, true);
    $state  = $status['status'] ?? 'unknown';
    logMsg("  Poll $polls: status=$state " . ($status['progress'] ?? ''));

    if ($state === 'done') {
        // Download MP4 from VPS to GoDaddy
        $mp4_url  = $status['mp4_url'] ?? '';
        $mp4_path = VV_ROOT . '/published_videos/' . $output_filename;

        if (!is_dir(dirname($mp4_path))) mkdir(dirname($mp4_path), 0755, true);

        logMsg("Downloading MP4 from VPS: $mp4_url");
        $mp4_data = @file_get_contents($mp4_url);

        if (!$mp4_data || strlen($mp4_data) < 1000) {
            die("ERROR: Failed to download MP4 from VPS ($mp4_url)\n");
        }

        file_put_contents($mp4_path, $mp4_data);
        $mb = round(filesize($mp4_path) / 1024 / 1024, 2);
        logMsg("MP4 saved: $mp4_path ({$mb} MB)");

        // Update DB
        $esc = mysqli_real_escape_string($conn, $output_filename);
        mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET video_filename='$esc', published_video='$esc', video_status='ready', updated_at=NOW()
             WHERE id=$podcast_id");
        logMsg("DB updated — video_filename=$output_filename, video_status=ready");

        // Cleanup VPS temp files
        @file_get_contents(VPS_STITCH_URL . '?action=cleanup&secret_key=' . urlencode(SECRET_KEY) . '&job_id=' . urlencode($job_id));
        logMsg("VPS cleanup sent.");

        logMsg("=== DONE — podcast_id=$podcast_id | file=$output_filename | {$mb} MB ===");
        echo "\n✅ SUCCESS: $output_filename ({$mb} MB)\n";
        exit(0);
    }

    if ($state === 'error') {
        $err = $status['message'] ?? 'unknown VPS error';
        logMsg("VPS reported error: $err");
        die("ERROR: $err\n");
    }
}

die("TIMEOUT: VPS did not complete in time. Check job_id=$job_id manually.\n");


/*
 * ═════════════════════════════════════════════════════════════════════════════
 * VPS COMPANION: vps_stitch.php
 * Deploy this file on your Hostinger VPS at:
 *   /home/user/public_html/videovizard.com/vps_stitch.php
 *
 * It receives the scene manifest, downloads each file, and runs FFmpeg to
 * concatenate everything into one MP4.
 * ═════════════════════════════════════════════════════════════════════════════

<?php
// vps_stitch.php  — deploy on Hostinger VPS

$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
$WORK_DIR   = '/tmp/vv_stitch/';
$OUTPUT_DIR = '/home/user/public_html/videovizard.com/stitched_videos/'; // public-accessible
$OUTPUT_URL = 'http://187.124.249.46/videovizard.com/stitched_videos/';
$STATUS_DIR = '/tmp/vv_stitch_status/';
$LOG_FILE   = '/tmp/vv_stitch.log';

@mkdir($WORK_DIR,   0755, true);
@mkdir($OUTPUT_DIR, 0755, true);
@mkdir($STATUS_DIR, 0755, true);

function vlog($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

function jsonOut($data) { header('Content-Type: application/json'); echo json_encode($data); exit; }

$secret = $_REQUEST['secret_key'] ?? '';
if ($secret !== $SECRET_KEY) { http_response_code(403); jsonOut(['success'=>false,'message'=>'Forbidden']); }

$action = $_REQUEST['action'] ?? $_POST['action'] ?? 'stitch';

// ── Status check ──────────────────────────────────────────────────────────────
if ($action === 'status') {
    $job_id = $_GET['job_id'] ?? '';
    $sf = $STATUS_DIR . preg_replace('/[^a-z0-9_\-]/i', '', $job_id) . '.json';
    if (!file_exists($sf)) jsonOut(['status'=>'pending']);
    jsonOut(json_decode(file_get_contents($sf), true));
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
if ($action === 'cleanup') {
    $job_id = $_GET['job_id'] ?? '';
    $safe   = preg_replace('/[^a-z0-9_\-]/i', '', $job_id);
    array_map('unlink', glob($WORK_DIR . $safe . '_*'));
    @unlink($STATUS_DIR . $safe . '.json');
    jsonOut(['success'=>true]);
}

// ── Stitch ────────────────────────────────────────────────────────────────────
if ($action === 'stitch') {
    $podcast_id      = (int)($_POST['podcast_id'] ?? 0);
    $scenes_json     = $_POST['scenes_json'] ?? '[]';
    $output_filename = preg_replace('/[^a-z0-9_\-\.]/i', '', $_POST['output_filename'] ?? "podcast_{$podcast_id}_stitched.mp4");
    $scenes          = json_decode($scenes_json, true) ?: [];

    if (!$scenes) jsonOut(['success'=>false,'message'=>'No scenes in manifest']);

    $job_id = 'stitch_' . $podcast_id . '_' . time();
    $safe   = preg_replace('/[^a-z0-9_\-]/i', '', $job_id);

    // Respond immediately so GoDaddy doesn't time out
    jsonOut(['success'=>true,'job_id'=>$job_id]);

    // ── Background processing ─────────────────────────────────────────────────
    // (PHP continues after response is sent because connection is closed)
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    function setStatus($STATUS_DIR, $safe, $data) {
        file_put_contents($STATUS_DIR . $safe . '.json', json_encode($data));
    }

    setStatus($STATUS_DIR, $safe, ['status'=>'processing','progress'=>'Downloading scenes']);
    vlog("Job $job_id started — " . count($scenes) . " scenes");

    $scene_inputs = [];  // [{video_path, audio_path, duration}, ...]

    foreach ($scenes as $sc) {
        $snum    = $sc['scene_num'];
        $bg_url  = $sc['bg_url']    ?? '';
        $aud_url = $sc['audio_url'] ?? '';
        $dur     = max(1, (int)($sc['duration'] ?? 5));

        // Download background
        $bg_ext  = pathinfo(parse_url($bg_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $bg_path = $WORK_DIR . "{$safe}_scene{$snum}_bg.{$bg_ext}";
        file_put_contents($bg_path, file_get_contents($bg_url));
        vlog("  Scene $snum bg downloaded (" . filesize($bg_path) . " bytes)");

        // Download audio (optional)
        $aud_path = '';
        if ($aud_url) {
            $aud_ext  = pathinfo(parse_url($aud_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp3';
            $aud_path = $WORK_DIR . "{$safe}_scene{$snum}_audio.{$aud_ext}";
            file_put_contents($aud_path, file_get_contents($aud_url));
            vlog("  Scene $snum audio downloaded (" . filesize($aud_path) . " bytes)");

            // Get real audio duration via ffprobe
            $probe = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($aud_path) . " 2>/dev/null");
            if ($probe && is_numeric(trim($probe))) {
                $dur = (float)trim($probe);
                vlog("  Scene $snum real audio duration: {$dur}s");
            }
        }

        $scene_inputs[] = [
            'bg_path'  => $bg_path,
            'bg_ext'   => $bg_ext,
            'aud_path' => $aud_path,
            'duration' => $dur,
            'scene_num'=> $snum,
        ];
    }

    setStatus($STATUS_DIR, $safe, ['status'=>'processing','progress'=>'Encoding scenes']);

    // ── Build one clip per scene ───────────────────────────────────────────────
    $clip_paths   = [];
    $concat_list  = $WORK_DIR . "{$safe}_concat.txt";

    foreach ($scene_inputs as $sc) {
        $snum      = $sc['scene_num'];
        $clip_path = $WORK_DIR . "{$safe}_clip{$snum}.mp4";
        $bg        = escapeshellarg($sc['bg_path']);
        $dur       = $sc['duration'];
        $aud       = $sc['aud_path'];
        $isVideo   = in_array(strtolower($sc['bg_ext']), ['mp4','webm','mov','avi','mkv','m4v']);

        if ($isVideo) {
            // Trim/loop video to audio duration, re-encode to consistent format
            if ($aud) {
                $cmd = "ffmpeg -y -stream_loop -1 -i $bg -i " . escapeshellarg($aud)
                     . " -map 0:v -map 1:a -shortest"
                     . " -vf \"scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2\""
                     . " -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k"
                     . " " . escapeshellarg($clip_path) . " 2>>/tmp/vv_stitch.log";
            } else {
                $cmd = "ffmpeg -y -stream_loop -1 -i $bg -t $dur"
                     . " -vf \"scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2\""
                     . " -c:v libx264 -preset fast -crf 23 -an"
                     . " " . escapeshellarg($clip_path) . " 2>>/tmp/vv_stitch.log";
            }
        } else {
            // Image → video with Ken Burns-style zoom
            if ($aud) {
                $cmd = "ffmpeg -y -loop 1 -i $bg -i " . escapeshellarg($aud)
                     . " -map 0:v -map 1:a -shortest"
                     . " -vf \"scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,"
                     .        "zoompan=z='min(zoom+0.0005,1.05)':d=" . round($dur * 25) . ":s=1280x720\""
                     . " -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k"
                     . " " . escapeshellarg($clip_path) . " 2>>/tmp/vv_stitch.log";
            } else {
                $cmd = "ffmpeg -y -loop 1 -i $bg -t $dur"
                     . " -vf \"scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,"
                     .        "zoompan=z='min(zoom+0.0005,1.05)':d=" . round($dur * 25) . ":s=1280x720\""
                     . " -c:v libx264 -preset fast -crf 23 -an"
                     . " " . escapeshellarg($clip_path) . " 2>>/tmp/vv_stitch.log";
            }
        }

        vlog("  Scene $snum FFmpeg: $cmd");
        shell_exec($cmd);

        if (!file_exists($clip_path) || filesize($clip_path) < 1000) {
            vlog("  Scene $snum FAILED — clip not created");
            continue;
        }

        $clip_paths[] = $clip_path;
        file_put_contents($concat_list, "file '" . $clip_path . "'\n", FILE_APPEND);
        vlog("  Scene $snum clip OK (" . round(filesize($clip_path)/1024) . " KB)");
    }

    if (!$clip_paths) {
        setStatus($STATUS_DIR, $safe, ['status'=>'error','message'=>'All scene clips failed to encode']);
        vlog("Job $job_id FAILED — no clips");
        exit;
    }

    // ── Concatenate all clips ─────────────────────────────────────────────────
    setStatus($STATUS_DIR, $safe, ['status'=>'processing','progress'=>'Concatenating clips']);

    $out_path = $OUTPUT_DIR . $output_filename;
    $concat_cmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg($concat_list)
                . " -c copy " . escapeshellarg($out_path)
                . " 2>>/tmp/vv_stitch.log";
    vlog("Concat: $concat_cmd");
    shell_exec($concat_cmd);

    if (!file_exists($out_path) || filesize($out_path) < 1000) {
        // Fallback: re-encode concat (handles mismatched streams)
        vlog("Copy-concat failed, retrying with re-encode...");
        $concat_cmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg($concat_list)
                    . " -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k"
                    . " " . escapeshellarg($out_path) . " 2>>/tmp/vv_stitch.log";
        shell_exec($concat_cmd);
    }

    $mb = file_exists($out_path) ? round(filesize($out_path)/1024/1024, 2) : 0;
    vlog("Output: $out_path ($mb MB)");

    if ($mb < 0.01) {
        setStatus($STATUS_DIR, $safe, ['status'=>'error','message'=>'Final concat failed, output empty']);
        vlog("Job $job_id FAILED — empty output");
        exit;
    }

    // ── Done ──────────────────────────────────────────────────────────────────
    setStatus($STATUS_DIR, $safe, [
        'status'    => 'done',
        'job_id'    => $job_id,
        'mp4_url'   => $OUTPUT_URL . $output_filename,
        'mp4_size_mb' => $mb,
    ]);
    vlog("Job $job_id DONE — $output_filename ($mb MB)");
}
*/

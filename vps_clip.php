<?php
// vps_clip.php - simple local ffmpeg processor
// Input:  /var/www/html/videovizard.com/podcast_videos_old/<filename>
// Output: /var/www/html/videovizard.com/podcast_videos_new/<filename>

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/vps_clip_errors.log');
error_reporting(E_ALL);

define('SECRET_KEY', 'VS_FFmpeg_2026_Secret!');
define('INPUT_DIR',  __DIR__ . '/podcast_videos_old/');
define('OUTPUT_DIR', __DIR__ . '/podcast_videos_new/');
define('FFMPEG',     '/usr/bin/ffmpeg');
define('FFPROBE',    '/usr/bin/ffprobe');
define('LOG_FILE',   __DIR__ . '/vps_clip_errors.log');

header('Content-Type: application/json; charset=utf-8');

function jout(array $d): void { echo json_encode($d); exit; }
function vlog(string $m): void { file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $m . "\n", FILE_APPEND); }

// Ensure folders exist and are writable
foreach ([INPUT_DIR, OUTPUT_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_writable($dir)) chmod($dir, 0777);
}

// Auth
if (($_POST['secret_key'] ?? '') !== SECRET_KEY) {
    http_response_code(403);
    jout(['success' => false, 'message' => 'Unauthorized']);
}

$action = trim($_POST['action'] ?? '');
vlog("action=$action");

// ── check_file ────────────────────────────────────────────────
if ($action === 'check_file') {
    $filename = basename(trim($_POST['filename'] ?? ''));
    $path     = INPUT_DIR . $filename;
    $exists   = file_exists($path) && filesize($path) > 0;
    vlog("check_file $filename exists=" . ($exists ? 'yes' : 'no'));
    jout(['success' => true, 'exists' => $exists]);
}

// ── upload_file ───────────────────────────────────────────────
if ($action === 'upload_file') {
    $filename = basename(trim($_POST['filename'] ?? ''));
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jout(['success' => false, 'message' => 'Upload error: ' . ($_FILES['file']['error'] ?? 'no file')]);
    }
    $dest = INPUT_DIR . $filename;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        jout(['success' => false, 'message' => 'Failed to save file to podcast_videos_old/']);
    }
    vlog("upload_file OK $filename size=" . filesize($dest));
    jout(['success' => true, 'filename' => $filename]);
}

// ── start_clip ────────────────────────────────────────────────
if ($action === 'start_clip') {
    $filename = basename(trim($_POST['filename'] ?? ''));
    if (!$filename) jout(['success' => false, 'message' => 'Missing filename']);

    $in  = INPUT_DIR  . $filename;
    $out = OUTPUT_DIR . $filename;

    if (!file_exists($in) || filesize($in) === 0) {
        jout(['success' => false, 'message' => 'File not found in podcast_videos_old/: ' . $filename]);
    }

    // Check for audio stream
    $has_audio = trim((string)shell_exec(FFPROBE . ' -v error -select_streams a:0 -show_entries stream=codec_type -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($in) . ' 2>/dev/null'));
    $audio = $has_audio ? '-c:a aac -b:a 128k' : '-an';

    // Check duration
    $dur = (float)trim((string)shell_exec(FFPROBE . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($in) . ' 2>/dev/null'));
    $trim = $dur > 10 ? '-t 10' : '';

    // Build and run ffmpeg
    $cmd = FFMPEG
        . ' -y'
        . ' -i ' . escapeshellarg($in)
        . ' ' . $trim
        . ' -vf scale=-2:720'
        . ' -r 30'
        . ' -c:v libx264 -crf 28 -preset slow'
        . ' ' . $audio
        . ' -movflags +faststart'
        . ' ' . escapeshellarg($out)
        . ' 2>>' . escapeshellarg(LOG_FILE);

    vlog("running: $cmd");
    exec($cmd, $output, $ret);
    vlog("ffmpeg exit=$ret");

    if (file_exists($out) && filesize($out) > 1000) {
        vlog("DONE $filename size=" . filesize($out));
        jout([
            'success'     => true,
            'status'      => 'done',
            'output_size' => filesize($out),
        ]);
    }

    jout(['success' => false, 'message' => 'FFmpeg failed (exit=' . $ret . ') — check vps_clip_errors.log']);
}

jout(['success' => false, 'message' => 'Invalid action: ' . $action]);

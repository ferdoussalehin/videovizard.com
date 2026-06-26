<?php
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
$work_dir   = '/var/www/html/videovizard.com/published_videos/';
$jobs_dir   = '/var/www/html/videovizard.com/ffmpeg_jobs/';

if (!is_dir($jobs_dir)) mkdir($jobs_dir, 0775, true);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$key = $_POST['secret_key'] ?? $_GET['secret_key'] ?? '';
if ($key !== $SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'convert';

if ($action === 'log') {
    $job_id   = $_GET['job_id'] ?? '';
    $log_file = $jobs_dir . $job_id . '.log';
    if (file_exists($log_file)) {
        $lines = file($log_file);
        $last  = array_slice($lines, -3);
        echo implode(' | ', array_map('trim', $last));
    } else {
        echo 'Starting...';
    }
    exit;
}

if ($action === 'status') {
    $job_id   = $_GET['job_id'] ?? '';
    $job_file = $jobs_dir . $job_id . '.json';
    if (!file_exists($job_file)) {
        echo json_encode(['success' => false, 'status' => 'processing', 'message' => 'Job running...']);
        exit;
    }
    echo file_get_contents($job_file);
    exit;
}

if ($action === 'cleanup') {
    $job_id     = $_GET['job_id'] ?? '';
    $podcast_id = (int)($_GET['podcast_id'] ?? 0);
    @unlink($work_dir . 'podcast_' . $podcast_id . '.mp4');
    @unlink($jobs_dir . $job_id . '.json');
    @unlink($jobs_dir . $job_id . '.sh');
    @unlink($jobs_dir . $job_id . '.log');
    echo json_encode(['success' => true, 'message' => 'Cleaned up']);
    exit;
}

if ($action === 'convert') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $webm_url   = $_POST['webm_url'] ?? '';

    if (!$podcast_id || !$webm_url) {
        echo json_encode(['success' => false, 'message' => 'Missing podcast_id or webm_url']);
        exit;
    }

    $job_id    = 'job_' . $podcast_id . '_' . time();
    $webm_file = $work_dir . 'podcast_' . $podcast_id . '_tmp.webm';
    $mp4_file  = $work_dir . 'podcast_' . $podcast_id . '.mp4';
    $job_file  = $jobs_dir . $job_id . '.json';
    $log_file  = $jobs_dir . $job_id . '.log';
    $sh_file   = $jobs_dir . $job_id . '.sh';

    // Write initial job status
file_put_contents('/tmp/vps_debug.log', "job_file=$job_file\njobs_dir=$jobs_dir\nwritable=" . (is_writable($jobs_dir) ? 'yes' : 'no') . "\n", FILE_APPEND);
    file_put_contents('/tmp/vps_debug.log', "job_file=$job_file
jobs_dir=$jobs_dir
writable=".(is_writable($jobs_dir)?'yes':'no')."
", FILE_APPEND);
file_put_contents($job_file, json_encode([
        'success'    => false,
        'status'     => 'processing',
        'job_id'     => $job_id,
        'podcast_id' => $podcast_id,
        'started_at' => date('Y-m-d H:i:s'),
        'message'    => 'Downloading WebM from GoDaddy...'
    ]));

    // Build shell script using PHP to write each line safely
    $sh = '#!/bin/bash' . "\n";
    $sh .= 'LOG="' . $log_file . '"' . "\n";
    $sh .= 'JOB="' . $job_file . '"' . "\n";
    $sh .= 'WEBM="' . $webm_file . '"' . "\n";
    $sh .= 'MP4="' . $mp4_file . '"' . "\n";
    $sh .= 'PODCAST_ID=' . $podcast_id . "\n";
    $sh .= 'JOB_ID="' . $job_id . '"' . "\n";
    $sh .= 'VPS_BASE="http://187.124.249.46/videovizard.com"' . "\n";
    $sh .= "\n";
    $sh .= 'echo "[$(date)] Downloading: ' . $webm_url . '" >> "$LOG"' . "\n";
    $sh .= 'curl -L --max-time 300 -o "$WEBM" "' . $webm_url . '" >> "$LOG" 2>&1' . "\n";
    $sh .= 'DL=$?' . "\n";
    $sh .= 'SZ=$(stat -c%s "$WEBM" 2>/dev/null || echo 0)' . "\n";
    $sh .= "\n";
    $sh .= 'if [ $DL -ne 0 ] || [ $SZ -lt 1000 ]; then' . "\n";
    $sh .= '  echo "[$(date)] Download FAILED dl=$DL sz=$SZ" >> "$LOG"' . "\n";
    $sh .= '  echo \'{"success":false,"status":"failed","message":"Download failed"}\' > "$JOB"' . "\n";
    $sh .= '  exit 1' . "\n";
    $sh .= 'fi' . "\n";
    $sh .= "\n";
    $sh .= 'echo "[$(date)] Download OK sz=$SZ Running FFmpeg..." >> "$LOG"' . "\n";
    $sh .= '/usr/bin/ffmpeg -y -i "$WEBM" -vsync 0 -c:v libx264 -preset ultrafast -crf 28 -c:a aac -b:a 128k -movflags +faststart "$MP4" >> "$LOG" 2>&1' . "\n";
    $sh .= 'FF=$?' . "\n";
    $sh .= 'rm -f "$WEBM"' . "\n";
    $sh .= "\n";
    $sh .= 'if [ $FF -eq 0 ] && [ -f "$MP4" ]; then' . "\n";
    $sh .= '  SZ2=$(stat -c%s "$MP4")' . "\n";
    $sh .= '  echo "[$(date)] FFmpeg OK sz=$SZ2" >> "$LOG"' . "\n";
    $sh .= '  printf \'{"success":true,"status":"done","job_id":"%s","podcast_id":%d,"filename":"podcast_%d.mp4","mp4_url":"%s/published_videos/podcast_%d.mp4","mp4_size":%d}\' "$JOB_ID" $PODCAST_ID $PODCAST_ID "$VPS_BASE" $PODCAST_ID $SZ2 > "$JOB"' . "\n";
    $sh .= 'else' . "\n";
    $sh .= '  echo "[$(date)] FFmpeg FAILED ff=$FF" >> "$LOG"' . "\n";
    $sh .= '  echo \'{"success":false,"status":"failed","message":"FFmpeg failed"}\' > "$JOB"' . "\n";
    $sh .= 'fi' . "\n";

    file_put_contents($sh_file, $sh);
    chmod($sh_file, 0755);
    exec('bash ' . escapeshellarg($sh_file) . ' > /dev/null 2>&1 &');

    echo json_encode([
        'success'    => true,
        'status'     => 'processing',
        'job_id'     => $job_id,
        'podcast_id' => $podcast_id,
        'message'    => 'Job started — VPS downloading WebM'
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);

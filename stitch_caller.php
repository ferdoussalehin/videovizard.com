<?php
/**
 * stitch_caller.php
 * -----------------
 * Place this on GoDaddy alongside videomaker.php.
 * Call it from browser or CLI to stitch scene clips into one MP4.
 *
 * Usage (browser):
 *   https://yourdomain.com/stitch_caller.php?podcast_id=123&scene_count=5
 *
 * Usage (CLI):
 *   php stitch_caller.php podcast_id=123 scene_count=5
 *
 * The VPS will:
 *   1. Download scene clips from GoDaddy  (podcast_123_scene_0.webm, _1.webm …)
 *   2. Stitch them with ffmpeg
 *   3. Convert to MP4
 *   4. Copy MP4 to published_videos/ on the VPS
 *   (You then need to copy/sync back to GoDaddy — see note at bottom)
 */

// ── CONFIG — edit these ──────────────────────────────────────────────────────

// VPS stitch script URL
define('VPS_URL',      'http://187.124.249.46/videovizard.com/vps_stitch.php');

// Secret must match SECRET in vps_stitch.php
define('VPS_SECRET',   'VS_FFmpeg_2026_Secret!');

// Base URL of THIS GoDaddy site (where the VPS will download clips from)
define('SITE_BASE_URL', 'https://videovizard.com/');

// Folder on GoDaddy where scene clips are saved  (relative to site root)
define('CLIP_DIR',     'published_videos/');

// Folder on GoDaddy where finished MP4 should land  (relative to site root)
define('OUTPUT_DIR',   'published_videos/');

// How long to wait for the VPS to finish  (seconds)
define('MAX_WAIT_SEC', 600);

// ── END CONFIG ───────────────────────────────────────────────────────────────

header('Content-Type: text/plain; charset=utf-8');
// Flush output as we go so the browser shows progress live
if (ob_get_level()) ob_end_flush();

// Accept params from GET, POST, or CLI argv
$params = array_merge($_GET, $_POST);
if (php_sapi_name() === 'cli') {
    parse_str(implode('&', array_slice($argv, 1)), $params);
}

$podcast_id  = 780;  //(int)($params['podcast_id']  ?? 0);
$scene_count = 4; //(int)($params['scene_count'] ?? 0);

if (!$podcast_id || $scene_count < 1) {
    die("ERROR: podcast_id and scene_count are required.\n"
      . "Example: stitch_caller.php?podcast_id=123&scene_count=5\n");
}

log_line("=== Stitch caller started ===");
log_line("Podcast : $podcast_id");
log_line("Scenes  : $scene_count");
log_line("VPS     : " . VPS_URL);

// ── Step 1: verify scene clips exist on GoDaddy ──────────────────────────────
log_line("\n[1/4] Checking scene clips exist...");
$missing = [];
for ($i = 0; $i < $scene_count; $i++) {
    $path = __DIR__ . '/' . CLIP_DIR . "podcast_{$podcast_id}_scene_{$i}.webm";
    if (!file_exists($path) || filesize($path) < 1000) {
        $missing[] = $i;
    } else {
        $mb = round(filesize($path) / 1024 / 1024, 2);
        log_line("  scene $i — {$mb} MB ✓");
    }
}
if (!empty($missing)) {
    die("ERROR: Missing or empty clips for scenes: " . implode(', ', $missing) . "\n");
}
log_line("  All clips present.");

// ── Step 2: send job to VPS ───────────────────────────────────────────────────
log_line("\n[2/4] Sending stitch job to VPS...");
$post_data = [
    'secret'      => VPS_SECRET,
    'podcast_id'  => $podcast_id,
    'scene_count' => $scene_count,
    'base_url'    => SITE_BASE_URL,
    'clip_dir'    => CLIP_DIR,
    'output_dir'  => OUTPUT_DIR,
];

$ch = curl_init(VPS_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    die("ERROR: Could not reach VPS — $err\n");
}

$json = json_decode($resp, true);
if (!$json || empty($json['success'])) {
    die("ERROR: VPS rejected job — " . ($json['message'] ?? $resp) . "\n");
}

$job_id = $json['job_id'];
log_line("  Job accepted: $job_id");

// ── Step 3: poll until done ───────────────────────────────────────────────────
log_line("\n[3/4] Waiting for VPS to stitch and convert...");
$started  = time();
$interval = 5; // poll every 5 seconds
$status   = 'queued';

while (time() - $started < MAX_WAIT_SEC) {
    sleep($interval);

    $ch = curl_init(VPS_URL . '?action=status&job_id=' . urlencode($job_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp2 = curl_exec($ch);
    curl_close($ch);

    $poll = json_decode($resp2, true);
    $status = $poll['status'] ?? 'unknown';
    $elapsed = time() - $started;

    log_line("  [{$elapsed}s] status: $status" . ($poll['message'] ? " — {$poll['message']}" : ''));

    if ($status === 'done') break;
    if ($status === 'error') {
        die("ERROR: VPS reported error — " . ($poll['message'] ?? 'unknown') . "\n");
    }

    flush();
}

if ($status !== 'done') {
    die("ERROR: Timed out after " . MAX_WAIT_SEC . "s. Job: $job_id\n");
}

$mp4_size = $poll['mp4_size_mb'] ?? '?';
$mp4_url  = $poll['mp4_url']     ?? '';
log_line("  VPS done — MP4: {$mp4_size} MB");

// ── Step 4: pull MP4 from VPS back to GoDaddy ────────────────────────────────
log_line("\n[4/4] Downloading MP4 from VPS to GoDaddy...");

$mp4_filename   = "podcast_{$podcast_id}.mp4";
$mp4_remote_url = rtrim(VPS_URL, '/vps_stitch.php') . '/' . ltrim($mp4_url, '/');
// Simpler: construct directly
$mp4_remote_url = 'http://187.124.249.46/videovizard.com/' . ltrim(OUTPUT_DIR, '/') . $mp4_filename;
$mp4_local_path = __DIR__ . '/' . OUTPUT_DIR . $mp4_filename;

log_line("  Fetching: $mp4_remote_url");

$ch = curl_init($mp4_remote_url);
$fh = fopen($mp4_local_path, 'wb');
curl_setopt_array($ch, [
    CURLOPT_FILE    => $fh,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_FOLLOWLOCATION => true,
]);
$ok  = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
fclose($fh);

if (!$ok || $err || !file_exists($mp4_local_path) || filesize($mp4_local_path) < 1000) {
    die("ERROR: Failed to download MP4 — $err\n");
}

$final_mb = round(filesize($mp4_local_path) / 1024 / 1024, 2);
log_line("  Saved to: $mp4_local_path ({$final_mb} MB)");

// ── Done ─────────────────────────────────────────────────────────────────────
log_line("\n=== All done ===");
log_line("MP4 is at: " . SITE_BASE_URL . OUTPUT_DIR . $mp4_filename);
log_line("Job ID   : $job_id");

// ── helper ────────────────────────────────────────────────────────────────────
function log_line(string $msg): void {
    echo $msg . "\n";
    flush();
}

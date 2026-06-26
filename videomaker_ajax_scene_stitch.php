<?php
/**
 * videomaker_ajax_scene_stitch.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Two new ajax_action handlers for per-scene recording + server stitch.
 * Paste the contents of this file into videomaker_ajax.php alongside the
 * existing ajax_action handlers (e.g. next to 'save_published_video').
 *
 * Requires:
 *   - $podcast_id  (already available in videomaker_ajax.php context)
 *   - $VPS_URL     (already defined: 'http://187.124.249.46/..../vps_convert.php')
 *   - $SECRET_KEY  (already defined in videomaker.php)
 *   - published_videos/ directory writable by web server
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Handler 1: save_scene_clip
 *   Receives one WebM blob for a single scene, saves it as:
 *     published_videos/podcast_{id}_scene_{index}.webm
 *
 * Handler 2: stitch_scenes
 *   Sends all scene clips to vps_convert.php with action=stitch_scenes.
 *   vps_convert.php is expected to:
 *     1. Receive the file list + warmup_ms trim amount
 *     2. Run FFmpeg:  trim first warmup_ms from each clip, then concat
 *     3. Output:  published_videos/podcast_{id}.mp4
 *     4. Return the same {success, job_id} / {status:'done'|'failed'} JSON
 *        that the existing start_mp4_convert / poll_mp4_convert use
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Handler: save_scene_clip ──────────────────────────────────────────────────
if ($ajax_action === 'save_scene_clip') {
    header('Content-Type: application/json');

    $scene_index = (int)($_POST['scene_index'] ?? -1);
    $warmup_ms   = (int)($_POST['warmup_ms']   ?? 500);

    if ($scene_index < 0) {
        echo json_encode(['success' => false, 'message' => 'Missing scene_index']);
        exit;
    }

    if (empty($_FILES['video']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'No video file received']);
        exit;
    }

    $dir      = __DIR__ . '/published_videos/';
    $filename = 'podcast_' . $podcast_id . '_scene_' . $scene_index . '.webm';
    $dest     = $dir . $filename;

    // Create dir if needed
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save clip to ' . $dest]);
        exit;
    }

    $size_mb = round(filesize($dest) / 1024 / 1024, 2);
    error_log("[stitch] Saved scene {$scene_index} clip: {$filename} ({$size_mb} MB)");

    echo json_encode([
        'success'     => true,
        'filename'    => $filename,
        'scene_index' => $scene_index,
        'size_mb'     => $size_mb,
    ]);
    exit;
}

// ── Handler: stitch_scenes ────────────────────────────────────────────────────
if ($ajax_action === 'stitch_scenes') {
    header('Content-Type: application/json');

    $scene_count = (int)($_POST['scene_count'] ?? 0);
    $warmup_ms   = (int)($_POST['warmup_ms']   ?? 500);

    if ($scene_count <= 0) {
        echo json_encode(['success' => false, 'message' => 'scene_count must be > 0']);
        exit;
    }

    $dir = __DIR__ . '/published_videos/';

    // Verify all clips exist
    $missing = [];
    for ($i = 0; $i < $scene_count; $i++) {
        $f = $dir . 'podcast_' . $podcast_id . '_scene_' . $i . '.webm';
        if (!file_exists($f) || filesize($f) < 100) {
            $missing[] = $i;
        }
    }

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing clips for scenes: ' . implode(', ', $missing),
        ]);
        exit;
    }

    // Build the list of clip paths to send to VPS
    $clips = [];
    for ($i = 0; $i < $scene_count; $i++) {
        $clips[] = 'podcast_' . $podcast_id . '_scene_' . $i . '.webm';
    }

    // Call VPS convert with action=stitch_scenes
    // vps_convert.php already handles start_mp4_convert; we add stitch_scenes
    // which it will process the same way (async job + poll).
    $payload = [
        'action'      => 'stitch_scenes',
        'podcast_id'  => $podcast_id,
        'scene_count' => $scene_count,
        'warmup_ms'   => $warmup_ms,
        'clips'       => implode(',', $clips),       // comma-separated filenames
        'output'      => 'podcast_' . $podcast_id . '.mp4',
        'secret'      => $SECRET_KEY,
    ];

    $ch = curl_init($VPS_URL);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("[stitch] VPS curl error: {$err}");
        echo json_encode(['success' => false, 'message' => 'VPS connection failed: ' . $err]);
        exit;
    }

    $vps = json_decode($response, true);
    if (!$vps || empty($vps['success'])) {
        $msg = $vps['message'] ?? ('VPS error: ' . substr($response, 0, 200));
        error_log("[stitch] VPS rejected stitch: {$msg}");
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    error_log("[stitch] Stitch job started — podcast {$podcast_id}, {$scene_count} scenes, job_id=" . ($vps['job_id'] ?? 'n/a'));

    echo json_encode([
        'success'  => true,
        'job_id'   => $vps['job_id'] ?? null,
        'message'  => 'Stitch job queued',
    ]);
    exit;
}

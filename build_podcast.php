<?php
if (php_sapi_name() === 'cli') {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
} else {
    session_start();
}
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
ini_set('max_execution_time', 600);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

include __DIR__ . '/dbconnect_hdb.php';
// Also load config for API keys
if (file_exists(__DIR__ . '/config.php')) include __DIR__ . '/config.php';

$podcast_id = (int)($_GET['podcast_id'] ?? 0);
$mode       = $_GET['mode'] ?? 'stream';

if (!$podcast_id) { respondError('Missing podcast_id'); }

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1"));
if (!$row) { respondError("Podcast $podcast_id not found"); }

$admin_id    = (int)$row['admin_id'];
$lang_code   = $row['lang_code']   ?? 'en';
$host_voice  = $row['host_voice']  ?: 'openai:alloy';
$guest_voice = $row['guest_voice'] ?: $host_voice;
$rate        = (float)($row['voice_rate']  ?: 1.0);
$media_type  = $row['video_media'] ?: 'stock_images';

// Build the base URL for HTTP calls to wizard_step2.php
// Detect from server vars
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir      = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$base_url = $scheme . '://' . $host . $dir;
$s2_url   = $base_url . '/wizard_step2.php';
$session_id = session_id();

error_log("BUILD: base_url=$base_url s2_url=$s2_url session=$session_id");

// ── SSE setup ────────────────────────────────────────────────
if ($mode === 'stream') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level()) ob_end_clean();
}

$log = []; $success = true;

function streamLog($msg, $type = 'info') {
    global $log, $mode, $podcast_id;
    $log[] = ['msg' => $msg, 'type' => $type];
    error_log("BUILD[$podcast_id]: [$type] $msg");
    if ($mode === 'stream') {
        echo "data: " . json_encode(['log' => $msg, 'type' => $type]) . "\n\n";
        flush();
    }
}

function respondError($msg) {
    global $mode;
    error_log("BUILD ERROR: $msg");
    if ($mode === 'stream') {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
        }
        echo "data: " . json_encode(['log' => "❌ $msg", 'type' => 'error', 'done' => true, 'success' => false]) . "\n\n";
        flush();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    exit;
}

// HTTP POST to wizard_step2.php — no include, no session conflicts
function callWizard(array $post) {
    global $s2_url, $session_id, $admin_id;
    $post['admin_id'] = $admin_id; // fallback auth

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: PHPSESSID=' . $session_id,
        ]),
        'content' => http_build_query($post),
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);

    $out = @file_get_contents($s2_url, false, $ctx);
    if ($out === false) {
        error_log("BUILD callWizard FAILED for action={$post['action']}: file_get_contents returned false");
        return '{"success":false,"error":"HTTP request failed"}';
    }

    // Strip non-JSON prefix
    $ps = strpos($out, '{'); $pa = strpos($out, '[');
    if ($ps === false) $ps = PHP_INT_MAX;
    if ($pa === false) $pa = PHP_INT_MAX;
    $start = min($ps, $pa);
    if ($start !== PHP_INT_MAX && $start > 0) $out = substr($out, $start);

    return $out;
}

// ── Step 1: Create scenes ─────────────────────────────────────
streamLog("🚀 Starting build for podcast #$podcast_id");
streamLog("🎙 Voice: $host_voice | Rate: {$rate}x | Media: $media_type");
streamLog("📝 Step 1: Creating scenes from script…");

$out = callWizard([
    'action'      => 'create_scenes_from_podcast',
    'podcast_id'  => $podcast_id,
    'host_voice'  => $host_voice,
    'guest_voice' => $guest_voice,
    'rate'        => $rate,
    'lang_code'   => $lang_code,
]);
$s2 = json_decode($out, true);
if (!$s2 || empty($s2['success'])) {
    streamLog("❌ Scene creation failed: " . ($s2['error'] ?? $s2['message'] ?? substr($out, 0, 200)), 'error');
    $success = false;
} else {
    streamLog("✅ {$s2['scene_count']} scenes created", 'success');
}

// ── Step 2: Get scenes ────────────────────────────────────────
$scenes = [];
if ($success) {
    $out    = callWizard(['action' => 'get_scenes', 'podcast_id' => $podcast_id]);
    $scenes = json_decode($out, true) ?? [];
    streamLog("📋 " . count($scenes) . " scenes loaded");
    if (!count($scenes)) {
        streamLog("❌ No scenes returned — cannot continue", 'error');
        $success = false;
    }
}

// ── Step 3: Audio ─────────────────────────────────────────────
$audio_done = 0; $audio_fail = 0;
if ($success && count($scenes)) {
    streamLog("🎤 Step 2: Generating audio for " . count($scenes) . " scenes…");
    foreach ($scenes as $i => $scene) {
        $seq   = $i + 1;
        $txt   = trim(preg_replace('/<break[^>]*>/i', '', $scene['text_contents'] ?? ''));
        if (!$txt) { $audio_done++; continue; }
        $voice = (($scene['actor'] ?? '') === 'guest') ? $guest_voice : $host_voice;
        $out = callWizard([
            'action'      => 'generate_scene_audio',
            'scene_id'    => $scene['id'],
            'podcast_id'  => $podcast_id,
            'seq_no'      => $seq,
            'lang_code'   => $lang_code,
            'voice_id'    => $voice,
            'rate'        => $rate,
            'text'        => $txt,
        ]);
        $ad = json_decode($out, true);
        if (!empty($ad['success'])) {
            $audio_done++;
            streamLog("✓ Scene $seq audio OK", 'success');
        } else {
            $audio_fail++;
            streamLog("✗ Scene $seq: " . ($ad['error'] ?? substr($out, 0, 100)), 'error');
        }
    }
    streamLog("🎤 Audio: $audio_done OK, $audio_fail failed", $audio_fail ? 'warning' : 'success');
}

// ── Step 4: Media ─────────────────────────────────────────────
$media_done = 0; $media_fail = 0;
if ($success && count($scenes)) {
    streamLog("🖼 Step 3: Assigning media ($media_type)…");
    $used = [];
    foreach ($scenes as $i => $scene) {
        $seq = $i + 1;
        if ($media_type === 'unique_images') {
            $out = callWizard([
                'action'     => 'generate_image',
                'prompt'     => $scene['prompt'] ?? $scene['text_contents'] ?? '',
                'scene_id'   => $scene['id'],
                'podcast_id' => $podcast_id,
            ]);
            $imgd = json_decode($out, true);
            if (!empty($imgd['success']) && !empty($imgd['filename'])) {
                doAssign($scene['id'], $imgd['filename'], $podcast_id, $seq, $scene['hashtags'] ?? '', 0, 0, 1, $scene['prompt'] ?? '');
                $media_done++;
                streamLog("✓ Scene $seq AI image", 'success');
            } else {
                $media_fail++;
                streamLog("✗ Scene $seq image: " . ($imgd['error'] ?? ''), 'error');
            }
        } else {
            $nl      = array_filter(array_map('trim', explode('|', $scene['natural_language_tags'] ?? '')));
            $queries = count($nl) ? array_values($nl) : [explode(',', $scene['hashtags'] ?? '')[0] ?? ''];
            $found   = [];
            foreach ($queries as $q) {
                if (!trim($q)) continue;
                $out = callWizard(['action' => 'search_images', 'hashtags' => $q]);
                $pa = strpos($out, '[');
                if ($pa !== false && $pa > 0) $out = substr($out, $pa);
                $found = json_decode($out, true) ?? [];
                if (count($found)) break;
            }
            $unique = array_values(array_filter($found, fn($f) => !in_array($f['filename'], $used)));
            if (count($unique)) {
                $pick  = $unique[0]; $used[] = $pick['filename'];
                $fi = count(array_filter($found, fn($f) => ($f['type'] ?? '') === 'image'));
                $fv = count(array_filter($found, fn($f) => ($f['type'] ?? '') === 'video'));
                doAssign($scene['id'], $pick['filename'], $podcast_id, $seq, $scene['hashtags'] ?? '', $fi, $fv, 0, '');
                $media_done++;
                streamLog("✓ Scene $seq: {$pick['filename']}", 'success');
            } else {
                $media_fail++;
                streamLog("⚠ Scene $seq: no media found", 'warning');
            }
        }
    }
    streamLog("🖼 Media: $media_done assigned, $media_fail not found", $media_fail ? 'warning' : 'success');
}

// ── Done ──────────────────────────────────────────────────────
$ok = $success && ($audio_fail === 0);
streamLog($ok ? "🎉 Build complete!" : "⚠ Build finished with some issues.", $ok ? 'success' : 'warning');

$summary = [
    'success'    => $ok,
    'podcast_id' => $podcast_id,
    'audio_done' => $audio_done, 'audio_fail' => $audio_fail,
    'media_done' => $media_done, 'media_fail' => $media_fail,
    'videomaker' => "videomaker.php?podcast_id=$podcast_id",
    'done'       => true,
    'log'        => $log,
];
if ($mode === 'stream') {
    echo "data: " . json_encode($summary) . "\n\n";
    flush();
} else {
    header('Content-Type: application/json');
    echo json_encode($summary);
}

function doAssign($sid, $fname, $pid, $sno, $htags, $fi, $fv, $ai, $aip) {
    callWizard(['action' => 'assign_image', 'scene_id' => $sid, 'filename' => $fname]);
    callWizard([
        'action'        => 'log_media_search',
        'podcast_id'    => $pid,
        'scene_id'      => $sid,
        'scene_no'      => $sno,
        'hashtags'      => $htags,
        'found_images'  => $fi,
        'found_videos'  => $fv,
        'selected_file' => $fname,
        'selected_type' => preg_match('/\.(mp4|webm|mov)$/i', $fname) ? 'video' : 'image',
        'was_duplicate' => 0,
        'ai_generated'  => $ai,
        'ai_prompt'     => $aip,
    ]);
}

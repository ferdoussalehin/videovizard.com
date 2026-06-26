<?php
// ============================================================
// cron_image_gen.php — Image Generation Queue Processor
// Cron: * * * * * php /path/to/cron_image_gen.php >> /path/to/cron.log 2>&1
//
// hdb_image_gen_que.videogen_flag:
//   1 = ready / pending
//   2 = processing (in progress)
//   3 = image generated (done)
//
// hdb_image_gen_que.gen_mode:
//   flux   = FLUX Dev via Modal (default)
//   fal.ai = fal.ai FLUX Schnell (fast, no cold start)
//
// hdb_podcast_stories.videogen_flag:
//   3 = image generated (done)
//
// hdb_podcasts.videogen_flag:
//   3 = all stories done
// ============================================================

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
ignore_user_abort(true);
set_time_limit(600); // 10 min max — FLUX/Modal cold start can take 2-3 min

// ── Browser trigger: spawn as background CLI process ─────────
// When called from a browser, launches THIS script via CLI in
// the background and immediately returns a response to the browser.
// The CLI process runs completely outside Apache — no 503, no timeout.
// No effect when running as CLI already (cron/direct).
if (php_sapi_name() !== 'cli') {
    $php_bin = trim(shell_exec('which php'));
    if (empty($php_bin)) $php_bin = '/usr/bin/php'; // fallback
    $script  = escapeshellarg(__FILE__);
    $log     = escapeshellarg(__DIR__ . '/image_generation.log');
    $cmd     = "env -i HOME=/root {$php_bin} -d error_log=" . escapeshellarg(__DIR__ . '/a_errors.log') . " {$script} >> {$log} 2>&1 &";
    shell_exec($cmd);
    header("Content-Type: text/plain");
    echo "CRON launched as background process at " . date('Y-m-d H:i:s') . "\n";
    echo "PHP binary : $php_bin\n";
    echo "Script     : " . __FILE__ . "\n";
    echo "Log        : " . __DIR__ . "/image_generation.log\n";
    echo "Check the log file for live progress.\n";
    exit;
}

require_once __DIR__ . '/dbconnect_hdb.php';
require_once __DIR__ . '/config.php';

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
    $entry = "[$ts] [CRON][$type] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/image_generation.log', $entry, FILE_APPEND | LOCK_EX);
    file_put_contents(__DIR__ . '/a_errors.log',         $entry, FILE_APPEND | LOCK_EX);
}

// Returns human-readable elapsed time: "2m 22.3s" or "3.142s"
function elapsed($ref_time) {
    $e    = round(microtime(true) - $ref_time, 3);
    $mins = floor($e / 60);
    $secs = round($e - ($mins * 60), 3);
    return $mins > 0 ? "{$mins}m " . number_format($secs, 1) . "s" : number_format($secs, 3) . "s";
}

// ============================================================
// IMAGE GENERATION — FLUX via Modal.com (primary)
// ============================================================
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

function generateWithFlux($prompt, $maxRetries = 2, $img_width = 512, $img_height = 768) {
    global $MODAL_URL;
    $t_func  = microtime(true);
    $payload = json_encode([
        'prompt' => $prompt,
        'style'  => 'cinematic',
        'width'  => $img_width,
        'height' => $img_height,
    ]);

    logCron("FLUX: Request queued | prompt_len=" . strlen($prompt) . " size={$img_width}x{$img_height}", 'FLUX');

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        logCron("FLUX: Sending request — attempt $attempt/$maxRetries | time=" . date('H:i:s'), 'FLUX');

        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,  // follow HTTP 303 redirects from Modal
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $t_sent     = microtime(true);
        $response   = curl_exec($ch);
        $t_received = microtime(true);

        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $connectTime = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3);
        $curlErrno   = curl_errno($ch);
        $curlErrStr  = curl_error($ch);
        curl_close($ch);

        $waitTime = round($t_received - $t_sent, 3);
        logCron("FLUX: Response | attempt=$attempt HTTP=$httpCode connect={$connectTime}s wait={$waitTime}s resp_len=" . strlen((string)$response), 'FLUX');

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['image'])) {
                logCron("FLUX: SUCCESS attempt=$attempt total=" . elapsed($t_func), 'FLUX_SUCCESS');
                return ['success' => true, 'image' => $data['image'], 'source' => 'FLUX/Modal', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
            }
            logCron("FLUX: HTTP 200 but no image key. Body: " . substr((string)$response, 0, 200), 'FLUX_WARN');
        } else {
            $is_overload = in_array($httpCode, [429, 502, 503, 504]);
            logCron("FLUX: FAILED attempt=$attempt | HTTP=$httpCode curlErr=$curlErrno ($curlErrStr)" . ($is_overload ? ' [server overloaded]' : ''), $is_overload ? 'FLUX_OVERLOAD' : 'FLUX_ERROR');
        }

        if ($attempt < $maxRetries) {
            $wait = in_array($httpCode, [429, 502, 503, 504]) ? 10 : 3;
            logCron("FLUX: Waiting {$wait}s before retry...", 'FLUX');
            sleep($wait);
        }
    }

    logCron("FLUX: All $maxRetries attempts FAILED | total=" . elapsed($t_func), 'FLUX_ERROR');
    return ['success' => false, 'source' => 'FLUX/Modal', 'wait_time' => 0, 'connect_time' => 0];
}

// ============================================================
// IMAGE GENERATION — fal.ai FLUX Schnell (secondary fallback)
// ============================================================
function generateWithFal($prompt, $img_width = 512, $img_height = 768) {
    global $falApiKey;
    $t_func = microtime(true);
    logCron("FAL: Sending request | prompt_len=" . strlen($prompt) . " | time=" . date('H:i:s'), 'FAL');

    if (empty($falApiKey)) {
        logCron("FAL: No API key — skipping", 'FAL_ERROR');
        return ['success' => false, 'error' => 'No API key', 'source' => 'fal.ai', 'wait_time' => 0, 'connect_time' => 0];
    }

    // Map width/height ratio to nearest fal.ai named size
    $ratio = $img_width / $img_height;
    if ($ratio >= 1.7)      $image_size = 'landscape_16_9';
    elseif ($ratio >= 1.2)  $image_size = 'landscape_4_3';
    elseif ($ratio <= 0.6)  $image_size = 'portrait_16_9';
    elseif ($ratio <= 0.8)  $image_size = 'portrait_4_3';
    else                    $image_size = 'square_hd';

    logCron("FAL: image_size=$image_size (ratio=" . round($ratio, 2) . ")", 'FAL');

    $ch = curl_init('https://fal.run/fal-ai/flux/schnell');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Key {$falApiKey}",
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt'                => $prompt,
            'image_size'            => $image_size,
            'num_inference_steps'   => 4,
            'num_images'            => 1,
            'sync_mode'             => true,
            'enable_safety_checker' => false,
            'output_format'         => 'png',
        ]),
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
        return ['success' => false, 'error' => $curlError, 'source' => 'fal.ai', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }
    if ($httpCode !== 200) {
        $parsed   = json_decode($response, true);
        $errorMsg = $parsed['detail'] ?? $parsed['error'] ?? "HTTP $httpCode";
        logCron("FAL: HTTP error $httpCode — $errorMsg", 'FAL_ERROR');
        return ['success' => false, 'error' => $errorMsg, 'source' => 'fal.ai', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    $result = json_decode($response, true);

    // sync_mode=true: may return data URI base64
    if (!empty($result['images'][0]['url']) && strpos($result['images'][0]['url'], 'data:') === 0) {
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $result['images'][0]['url']);
        logCron("FAL: SUCCESS (base64 data URI) | total=" . elapsed($t_func), 'FAL_SUCCESS');
        return ['success' => true, 'image' => $base64, 'source' => 'fal.ai/schnell', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    // sync_mode=true: may return image URL — download it
    if (!empty($result['images'][0]['url'])) {
        $img_url = $result['images'][0]['url'];
        logCron("FAL: Got image URL — downloading | url=$img_url", 'FAL');
        $img_ch = curl_init($img_url);
        curl_setopt_array($img_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $img_data = curl_exec($img_ch);
        $img_http = curl_getinfo($img_ch, CURLINFO_HTTP_CODE);
        curl_close($img_ch);

        if ($img_http === 200 && $img_data) {
            logCron("FAL: SUCCESS (URL download) | total=" . elapsed($t_func), 'FAL_SUCCESS');
            return ['success' => true, 'image' => base64_encode($img_data), 'source' => 'fal.ai/schnell', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
        }
        logCron("FAL: Image download failed | HTTP=$img_http", 'FAL_ERROR');
        return ['success' => false, 'error' => "Image download failed HTTP $img_http", 'source' => 'fal.ai', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    logCron("FAL: No image in response | body=" . substr((string)$response, 0, 200), 'FAL_ERROR');
    return ['success' => false, 'error' => 'No image in response', 'source' => 'fal.ai', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
}

// ============================================================
// IMAGE GENERATION — OpenAI gpt-image-1 (last resort fallback)
// ============================================================
function generateWithOpenAI($prompt, $img_width = 512, $img_height = 768) {
    global $apiKey;
    $t_func = microtime(true);
    logCron("OPENAI: Sending request | prompt_len=" . strlen($prompt) . " | time=" . date('H:i:s'), 'OPENAI');

    if (empty($apiKey)) {
        logCron("OPENAI: No API key — skipping", 'OPENAI_ERROR');
        return ['success' => false, 'error' => 'No API key', 'source' => 'OpenAI', 'wait_time' => 0, 'connect_time' => 0];
    }

    // Map to nearest valid OpenAI size
    $ratio = $img_width / $img_height;
    if ($ratio > 1.2)       $resolution = '1536x1024';
    elseif ($ratio < 0.8)   $resolution = '1024x1536';
    else                    $resolution = '1024x1024';

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer $apiKey",
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'         => 'gpt-image-1',
            'prompt'        => $prompt,
            'size'          => $resolution,
            'quality'       => 'medium',
            'output_format' => 'png',
            'n'             => 1,
        ]),
    ]);

    $t_sent     = microtime(true);
    $response   = curl_exec($ch);
    $t_received = microtime(true);

    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $connectTime = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3);
    $curlError   = curl_error($ch);
    curl_close($ch);

    $waitTime = round($t_received - $t_sent, 3);
    logCron("OPENAI: Response | HTTP=$httpCode connect={$connectTime}s wait={$waitTime}s resp_len=" . strlen((string)$response), 'OPENAI');

    if ($curlError) {
        logCron("OPENAI: cURL error: $curlError", 'OPENAI_ERROR');
        return ['success' => false, 'error' => $curlError, 'source' => 'OpenAI', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }
    if ($httpCode !== 200) {
        $parsed   = json_decode($response, true);
        $errorMsg = $parsed['error']['message'] ?? "HTTP $httpCode";
        logCron("OPENAI: HTTP error $httpCode — $errorMsg", 'OPENAI_ERROR');
        return ['success' => false, 'error' => $errorMsg, 'source' => 'OpenAI', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    $result = json_decode($response, true);
    if (!isset($result['data'][0]['b64_json'])) {
        logCron("OPENAI: No b64_json in response", 'OPENAI_ERROR');
        return ['success' => false, 'error' => 'No image data', 'source' => 'OpenAI', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
    }

    logCron("OPENAI: SUCCESS | total=" . elapsed($t_func), 'OPENAI_SUCCESS');
    return ['success' => true, 'image' => $result['data'][0]['b64_json'], 'source' => 'OpenAI gpt-image-1', 'wait_time' => $waitTime, 'connect_time' => $connectTime];
}

// ============================================================
// PRIMARY FALLBACK CHAIN: Modal FLUX → fal.ai → OpenAI
// ============================================================
function generateImageWithFallback($prompt, $img_width = 512, $img_height = 768) {
    // 1st: FLUX Dev via Modal
    $result = generateWithFlux($prompt, 2, $img_width, $img_height);
    if ($result['success']) return $result;

    // 2nd: fal.ai FLUX Schnell
    logCron("MAIN: FLUX/Modal failed — trying fal.ai Schnell...", 'MAIN_FALLBACK');
    $falResult = generateWithFal($prompt, $img_width, $img_height);
    if ($falResult['success']) {
        logCron("MAIN: fal.ai succeeded", 'MAIN_SUCCESS');
        return $falResult;
    }

    // 3rd: OpenAI gpt-image-1 (last resort)
    logCron("MAIN: fal.ai failed — falling back to OpenAI (last resort)...", 'MAIN_FALLBACK');
    $openaiResult = generateWithOpenAI($prompt, $img_width, $img_height);
    if ($openaiResult['success']) {
        logCron("MAIN: OpenAI fallback succeeded", 'MAIN_SUCCESS');
        return $openaiResult;
    }

    logCron("MAIN: ALL providers FAILED", 'MAIN_ERROR');
    return ['success' => false, 'error' => 'All providers failed', 'source' => 'none', 'wait_time' => 0, 'connect_time' => 0];
}

// ============================================================
// CRON MAIN
// ============================================================
$t_script_start = microtime(true);
$job_count      = 0;
$job_log        = [];
$max_runtime    = 540; // stop looping after 9 min so script exits before set_time_limit(600)

logCron("", 'INFO');
logCron("╔══════════════════════════════════════════════╗", 'INFO');
logCron("║         CRON SCRIPT STARTED                  ║", 'INFO');
logCron("║  " . date('Y-m-d H:i:s') . "                          ║", 'INFO');
logCron("╚══════════════════════════════════════════════╝", 'INFO');

// ── MAIN LOOP: process jobs back-to-back until queue is empty ──
while (true) {

    // Safety: stop if approaching PHP time limit
    if ((microtime(true) - $t_script_start) > $max_runtime) {
        logCron("Max runtime {$max_runtime}s reached — exiting. Cron will pick up remaining jobs next minute.", 'INFO');
        break;
    }

    // ── STEP 1: Check if already processing (flag = 2) ──────
    $t_db = microtime(true);
    $chk  = mysqli_query($conn,
        "SELECT id, podcast_id, scene_id FROM hdb_image_gen_que WHERE videogen_flag = 2 LIMIT 1"
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

    // ── STEP 2: Get next pending record (flag = 1) ───────────
    $next = mysqli_query($conn,
        "SELECT * FROM hdb_image_gen_que
         WHERE videogen_flag = 1
         ORDER BY podcast_id ASC
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
    $img_folder = rtrim($row['image_folder'], '/');
    $media_type = $row['media_type'];
    $image_name = $row['image_name'];
    $img_width  = !empty($row['img_width'])  ? (int)$row['img_width']  : 512;
    $img_height = !empty($row['img_height']) ? (int)$row['img_height'] : 768;
    $gen_mode   = !empty($row['gen_mode'])   ? trim($row['gen_mode'])  : 'flux';

    logCron("", 'INFO');
    logCron("╔══════════════════════════════════════════════╗", 'INFO');
    logCron("║         JOB #$job_count STARTED                       ║", 'INFO');
    logCron("║  " . date('Y-m-d H:i:s') . "                          ║", 'INFO');
    logCron("╚══════════════════════════════════════════════╝", 'INFO');
    logCron("── QUEUE RECORD ──────────────────────────────────", 'INFO');
    logCron("   que_id     : $que_id",                           'INFO');
    logCron("   podcast_id : $podcast_id",                       'INFO');
    logCron("   scene_id   : $scene_id",                         'INFO');
    logCron("   media_type : $media_type",                       'INFO');
    logCron("   folder     : $img_folder",                       'INFO');
    logCron("   prompt_len : " . strlen($prompt) . " chars",     'INFO');
    logCron("   img_width  : $img_width px",                     'INFO');
    logCron("   img_height : $img_height px",                    'INFO');
    logCron("   gen_mode   : $gen_mode",                         'INFO');
    logCron("   db_lookup  : " . elapsed($t_db),                 'INFO');
    logCron("──────────────────────────────────────────────────", 'INFO');

    // ── STEP 3: Lock the record — set flag = 2 ──────────────
    $lock = mysqli_query($conn,
        "UPDATE hdb_image_gen_que
         SET videogen_flag = 2,
             updated_at    = '" . date('Y-m-d H:i:s') . "'
         WHERE id = $que_id AND videogen_flag = 1"
    );

    if (!$lock || mysqli_affected_rows($conn) === 0) {
        logCron("Could not lock id=$que_id — another process grabbed it. Trying next...", 'SKIP');
        continue;
    }
    logCron("Record locked (flag=2) | id=$que_id", 'PROCESSING');

    // ── STEP 4: Generate image ───────────────────────────────
    logCron("── IMAGE GENERATION ──────────────────────────────", 'INFO');
    logCron("   Sending at : " . date('H:i:s'),                  'INFO');

    $t_gen = microtime(true);

    if ($gen_mode === 'fal.ai') {
        // fal.ai FLUX Schnell — fast, no cold start
        logCron("   Mode: fal.ai Schnell (direct)", 'INFO');
        $result = generateWithFal($prompt, $img_width, $img_height);
        if (!$result['success']) {
            logCron("   fal.ai failed — falling back to OpenAI...", 'MAIN_FALLBACK');
            $result = generateWithOpenAI($prompt, $img_width, $img_height);
        }
    } else {
        // flux (default) — Modal FLUX Dev → fal.ai → OpenAI
        logCron("   Mode: FLUX/Modal (fal.ai → OpenAI fallback)", 'INFO');
        $result = generateImageWithFallback($prompt, $img_width, $img_height);
    }

    if (!$result['success']) {
        mysqli_query($conn,
            "UPDATE hdb_image_gen_que
             SET videogen_flag = 1,
                 updated_at    = '" . date('Y-m-d H:i:s') . "'
             WHERE id = $que_id"
        );
        logCron("Generation FAILED | id=$que_id reset to flag=1 | error: " . ($result['error'] ?? 'unknown'), 'ERROR');
        continue;
    }

    $gen_time = elapsed($t_gen);
    logCron("   Received at    : " . date('H:i:s'),              'INFO');
    logCron("   Provider used  : " . $result['source'],          'INFO');
    logCron("   Mode           : $gen_mode",                     'INFO');
    logCron("   Connect time   : " . $result['connect_time'] . "s", 'INFO');
    logCron("   API wait time  : " . $result['wait_time'] . "s", 'INFO');
    logCron("   Total gen time : $gen_time",                     'INFO');

    // Mode-specific notes
    if ($gen_mode === 'fal.ai') {
        logCron("   ★ fal.ai Schnell — no cold start, 4-step inference", 'INFO');
    } else {
        if ((float)$result['wait_time'] > 100) {
            logCron("   ⚠ Cold start detected — first request after idle", 'INFO');
        } else {
            logCron("   ✓ Warm container — full inference speed",          'INFO');
        }
    }
    logCron("──────────────────────────────────────────────────", 'INFO');

    // ── STEP 5: Decode and validate image ───────────────────
    $t_decode    = microtime(true);
    $img_data    = base64_decode($result['image']);
    $img_size_kb = round(strlen((string)$img_data) / 1024, 1);

    if ($img_data === false || strlen($img_data) < 1000) {
        mysqli_query($conn,
            "UPDATE hdb_image_gen_que
             SET videogen_flag = 1,
                 updated_at    = '" . date('Y-m-d H:i:s') . "'
             WHERE id = $que_id"
        );
        logCron("base64_decode failed or image too small ({$img_size_kb}KB) — reset to flag=1", 'ERROR');
        continue;
    }
    logCron("Image decoded | size={$img_size_kb}KB | decode_time=" . elapsed($t_decode), 'INFO');

    // ── STEP 6: Save image to folder ────────────────────────
    if (!is_dir($img_folder)) {
        mkdir($img_folder, 0755, true);
        logCron("Created folder: $img_folder", 'FS');
    }

    $filename    = 'scene_' . $podcast_id . '_' . $scene_id  . '.png';
    $output_path = $img_folder . '/' . $filename;
    $t_save      = microtime(true);

    if (file_put_contents($output_path, $img_data) === false) {
        mysqli_query($conn,
            "UPDATE hdb_image_gen_que
             SET videogen_flag = 1,
                 updated_at    = '" . date('Y-m-d H:i:s') . "'
             WHERE id = $que_id"
        );
        logCron("file_put_contents FAILED path=$output_path — reset to flag=1", 'ERROR');
        continue;
    }
    logCron("Image saved | path=$output_path size={$img_size_kb}KB | save_time=" . elapsed($t_save), 'SAVED');

    // ── RECONNECT: MySQL may have timed out during generation ─
    logCron("── DB UPDATES ────────────────────────────────────", 'INFO');
    $t_db_update = microtime(true);
    logCron("   Reconnecting to DB...", 'DB');
    mysqli_close($conn);
    require __DIR__ . '/dbconnect_hdb.php';

    if (!$conn || mysqli_connect_errno()) {
        logCron("FATAL: DB reconnect failed — " . mysqli_connect_error(), 'DB_ERROR');
        break;
    }
    logCron("   DB reconnected | reconnect_time=" . elapsed($t_db_update), 'DB');

    $esc_file   = mysqli_real_escape_string($conn, $filename);
    $esc_folder = mysqli_real_escape_string($conn, $img_folder);
    $now        = date('Y-m-d H:i:s');

    // ── STEP 7: Update que record — flag = 3 (done) ─────────
    $t_q = microtime(true);
    mysqli_query($conn,
        "UPDATE hdb_image_gen_que
         SET videogen_flag = 3,
             updated_at    = '$now'
         WHERE id = $que_id"
    );
    logCron("   [1/3] hdb_image_gen_que updated    | id=$que_id flag=3 | " . elapsed($t_q), 'DB_UPDATE');

    // ── STEP 8: Update hdb_podcast_stories ──────────────────
    $t_s            = microtime(true);
    $update_stories = mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET videogen_flag = 3,
             image_file    = '$esc_file',
             image_folder  = '$esc_folder',
             thumbnail     = '$esc_file'
         WHERE id = $scene_id"
    );

    if (!$update_stories || mysqli_affected_rows($conn) === 0) {
        logCron("   [2/3] hdb_podcast_stories WARN: not updated | scene_id=$scene_id | error: " . mysqli_error($conn), 'DB_WARN');
    } else {
        logCron("   [2/3] hdb_podcast_stories updated  | scene_id=$scene_id file=$filename thumbnail=$filename | " . elapsed($t_s), 'DB_UPDATE');
    }

    // ── STEP 9: Check if all stories done ───────────────────
    $t_p      = microtime(true);
    $all_done = mysqli_query($conn,
        "SELECT
             COUNT(*)                                            AS total,
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
            // Fetch the first scene's image_file to use as the podcast thumbnail
            $first_scene_res = mysqli_query($conn,
                "SELECT image_file FROM hdb_podcast_stories
                 WHERE podcast_id = $podcast_id
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $first_thumbnail = '';
            if ($first_scene_res && $first_row = mysqli_fetch_assoc($first_scene_res)) {
                $first_thumbnail = mysqli_real_escape_string($conn, $first_row['image_file']);
            }

            $update_podcast = mysqli_query($conn,
                "UPDATE hdb_podcasts
                 SET videogen_flag = 3,
                     thumbnail     = '$first_thumbnail',
                     updated_at    = '$now'
                 WHERE id = $podcast_id"
            );
            if (!$update_podcast || mysqli_affected_rows($conn) === 0) {
                logCron("   [3/3] hdb_podcasts WARN: not updated | podcast_id=$podcast_id | error: " . mysqli_error($conn), 'DB_WARN');
            } else {
                logCron("   [3/3] hdb_podcasts updated — ALL $total scenes done! | podcast_id=$podcast_id flag=3 thumbnail=$first_thumbnail", 'PODCAST_DONE');
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
    logCron("║         CRON JOB COMPLETE                    ║",  'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  que_id       : $que_id",                         'INFO');
    logCron("║  podcast_id   : $podcast_id",                     'INFO');
    logCron("║  scene_id     : $scene_id",                       'INFO');
    logCron("║  file         : $filename",                       'INFO');
    logCron("║  size         : {$img_size_kb}KB",                'INFO');
    logCron("║  provider     : " . $result['source'],            'INFO');
    logCron("║  gen_mode     : $gen_mode",                       'INFO');
    logCron("║  resolution   : {$img_width}x{$img_height}",      'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  connect time : " . $result['connect_time'] . "s", 'INFO');
    logCron("║  API wait     : " . $result['wait_time'] . "s",   'INFO');
    logCron("║  gen time     : $gen_time",                       'INFO');
    logCron("║  db time      : " . elapsed($t_db_update),        'INFO');
    logCron("║  TOTAL time   : $human_total",                    'INFO');
    logCron("╠══════════════════════════════════════════════╣",  'INFO');
    logCron("║  started      : " . date('Y-m-d H:i:s', (int)$t_start), 'INFO');
    logCron("║  ended        : " . date('Y-m-d H:i:s'),          'INFO');
    logCron("╚══════════════════════════════════════════════╝",  'INFO');

    // Record job stats for final summary table
    $job_log[] = [
        'job'        => $job_count,
        'scene_id'   => $scene_id,
        'resolution' => "{$img_width}x{$img_height}",
        'gen_mode'   => $gen_mode,
        'size_kb'    => $img_size_kb,
        'provider'   => $result['source'],
        'api_wait'   => $result['wait_time'] . 's',
        'total'      => $gen_time,
    ];

} // ── END WHILE LOOP ─────────────────────────────────────────

// ── SCRIPT SUMMARY ──────────────────────────────────────────
$script_elapsed = round(microtime(true) - $t_script_start, 1);
$s_mins         = floor($script_elapsed / 60);
$s_secs         = round($script_elapsed - ($s_mins * 60), 1);
$human_script   = $s_mins > 0 ? "{$s_mins}m {$s_secs}s" : "{$s_secs}s";

logCron("", 'INFO');
logCron("╔══════════════════════════════════════════════════════════════╗", 'INFO');
logCron("║              CRON SCRIPT FINISHED                            ║", 'INFO');
logCron("╠══════════════════════════════════════════════════════════════╣", 'INFO');
logCron("║  Jobs processed : $job_count",                                 'INFO');
logCron("║  Script runtime : $human_script",                              'INFO');
logCron("║  Ended at       : " . date('Y-m-d H:i:s'),                     'INFO');
logCron("╠══════════════════════════════════════════════════════════════╣", 'INFO');
logCron("║  #  │ scene  │ mode   │  res      │  size    │ provider      │ api wait │ total time  ║", 'INFO');
logCron("║─────┼────────┼────────┼───────────┼──────────┼───────────────┼──────────┼────────────║", 'INFO');

foreach ($job_log as $j) {
    $num      = str_pad($j['job'],              3,  ' ', STR_PAD_LEFT);
    $scene    = str_pad($j['scene_id'],         6,  ' ', STR_PAD_LEFT);
    $mode     = str_pad($j['gen_mode'],         6,  ' ', STR_PAD_RIGHT);
    $res      = str_pad($j['resolution'],       9,  ' ', STR_PAD_RIGHT);
    $size     = str_pad($j['size_kb'] . 'KB',   8,  ' ', STR_PAD_LEFT);
    $provider = str_pad($j['provider'],         13, ' ', STR_PAD_RIGHT);
    $wait     = str_pad($j['api_wait'],         8,  ' ', STR_PAD_LEFT);
    $total    = str_pad($j['total'],            11, ' ', STR_PAD_LEFT);
    logCron("║  $num │ $scene │ $mode │ $res │ $size │ $provider │ $wait │ $total  ║", 'INFO');
}

logCron("╚══════════════════════════════════════════════════════════════╝", 'INFO');

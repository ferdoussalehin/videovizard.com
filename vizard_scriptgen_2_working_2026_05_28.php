<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Must set session params BEFORE session_start — only when no session is active yet
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);   // 180 days
    ini_set('session.cookie_lifetime', 15552000);  // 180 days
    session_set_cookie_params(15552000);
    session_start();
}

if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }
include 'dbconnect_hdb.php';
include 'config.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$plan_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1"));
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// ── Resolve credit balance (team member → use team lead's balance) ─────
$_user_row       = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$_credit_user_id = (!empty($_user_row) && trim((string)($_user_row['role'] ?? '')) === 'Team Member' && (int)($_user_row['team_lead_id'] ?? 0) > 0)
    ? (int)$_user_row['team_lead_id']
    : $admin_id;
if ($_credit_user_id !== $admin_id) {
    $_lead_row       = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credit_balance FROM hdb_users WHERE id=$_credit_user_id LIMIT 1"));
    $user_credit_balance = (int)($_lead_row['credit_balance'] ?? 0);
} else {
    $user_credit_balance = (int)($_user_row['credit_balance'] ?? 0);
}
$credits_required    = 6;
$has_enough_credits  = ($user_credit_balance >= $credits_required);

// ── Fetch company details ─────────────────────────────────────
$company = null;
if ($company_id > 0) {
    $co_res = mysqli_query($conn,
        "SELECT companyname, description, brand_name, logo_file, website, phone, address
         FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
if (!$company) {
    $co_res = mysqli_query($conn,
        "SELECT companyname, description, brand_name, logo_file, website, phone, address
         FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
$co_name  = htmlspecialchars($company['companyname'] ?? '');
$co_brand = htmlspecialchars($company['brand_name']  ?? '');
$co_desc  = htmlspecialchars($company['description'] ?? '');
$co_logo  = htmlspecialchars($company['logo_file']   ?? '');
$co_web   = htmlspecialchars($company['website']     ?? '');
$co_phone = htmlspecialchars($company['phone']       ?? '');
$co_addr  = htmlspecialchars($company['address']     ?? '');

// ── All companies for dropdown ────────────────────────────────
$all_companies = [];
$acq = mysqli_query($conn,
    "SELECT id, companyname FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC");
if ($acq) while ($acr = mysqli_fetch_assoc($acq)) $all_companies[] = $acr;

// ── Handle company switch via GET ─────────────────────────────
if (isset($_GET['company_id'])) {
    $switched = (int)$_GET['company_id'];
    $valid = false;
    foreach ($all_companies as $c) { if ($c['id'] == $switched) { $valid = true; break; } }
    if ($valid) {
        $_SESSION['company_id'] = $switched;
        mysqli_query($conn, "UPDATE hdb_users SET last_company_id=$switched WHERE id=$admin_id");
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Support multiple common API key variable names
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

// Add debug check - log if key missing
if (!$apiKey) {
    error_log("[movie_gen] WARNING: No API key found in config.php");
}

$apiUrl = "https://api.openai.com/v1/chat/completions";
$response = "";
$step = $_POST['step'] ?? $_GET['step'] ?? "0";

// ── Fresh start: clear previous session data ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['step']) && !isset($_GET['company_id'])) {
    unset($_SESSION['suggestions'], $_SESSION['story'], $_SESSION['script'],
          $_SESSION['selected'], $_SESSION['business'], $_SESSION['additional_info'],
          $_SESSION['cta_text'], $_SESSION['error']);
}

// === LOGGING FUNCTION ==========================================
// ═══════════════════════════════════════════════════════════════
// LOGGING — writes to image_generation.log AND a_errors_log
// with microsecond timestamps so we can measure every step
// ═══════════════════════════════════════════════════════════════
function logGeneration($message, $type = 'INFO') {
    $ts    = date('Y-m-d H:i:s') . '.' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));
    $entry = "[$ts] [$type] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/image_generation.log', $entry, FILE_APPEND | LOCK_EX);
    file_put_contents(__DIR__ . '/a_errors_log',         $entry, FILE_APPEND | LOCK_EX);
}

// Convenience: log with elapsed seconds since a reference microtime
function logTimed($message, $type, $ref_time) {
    $elapsed = round(microtime(true) - $ref_time, 3);
    logGeneration("(+{$elapsed}s) $message", $type);
}

// ═══════════════════════════════════════════════════════════════
// MODAL / FLUX CONFIGURATION
// ═══════════════════════════════════════════════════════════════
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

// ── Warmup: sends a real tiny inference so Modal actually boots ─
// HEAD requests do nothing on Modal — we need a real POST.
// Free plan cold-start can be 30-60 s, so timeout is 90 s.
function warmupModal() {
    global $MODAL_URL;
    $t0 = microtime(true);
    logGeneration("WARMUP: Sending real inference warmup (tiny 128×128 image)...", "WARMUP");

    $payload = json_encode([
        'prompt' => 'plain white background, minimal',
        'style'  => 'cinematic',
        'width'  => 128,
        'height' => 128,
    ]);

    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,          // A100 cold start typically 30-60s
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);

    $data    = $resp ? json_decode($resp, true) : [];
    $is_warm = ($curlErr === 0 && $httpCode === 200 && !empty($data['image']));
    logTimed("WARMUP: HTTP=$httpCode curlErr=$curlErr is_warm=" . ($is_warm ? 'YES' : 'NO'), "WARMUP", $t0);
    return $is_warm;
}

// ═══════════════════════════════════════════════════════════════
// SINGLE IMAGE — FLUX (one attempt, timed, no inner retry loop)
// Retries are handled by the batch/fallback layer above this.
// ═══════════════════════════════════════════════════════════════
function generateWithFlux($prompt, $maxRetries = 2) {
    global $MODAL_URL;
    $t_func = microtime(true);
    logGeneration("FLUX: Starting | prompt_len=" . strlen($prompt), "FLUX");

    $payload = json_encode([
        'prompt' => $prompt,
        'style'  => 'cinematic',
        'width'  => 768,
        'height' => 1344,
    ]);

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $t_att = microtime(true);
        logGeneration("FLUX: Attempt $attempt/$maxRetries — firing request...", "FLUX");

        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            // 2 containers × ~20s each + queue wait for 6 scenes = up to 60s queue
            // + 20s generation + 20s buffer = 100s. Use 180s to be safe.
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $curlErrno = curl_errno($ch);
        $curlErrStr= curl_error($ch);
        curl_close($ch);

        logTimed(
            "FLUX: Attempt $attempt done | HTTP=$httpCode curlErr=$curlErrno curlTime={$totalTime}s resp_len=" . strlen((string)$response),
            "FLUX", $t_att
        );

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['image'])) {
                logTimed("FLUX: SUCCESS attempt=$attempt", "FLUX_SUCCESS", $t_func);
                return ['success' => true, 'image' => $data['image'], 'source' => 'FLUX/Modal'];
            }
            $body_preview = substr((string)$response, 0, 200);
            logGeneration("FLUX: HTTP 200 but no image key. Body: $body_preview", "FLUX_WARN");
        } else {
            // Detect Modal-specific overload / rate-limit codes
            $is_overload = in_array($httpCode, [429, 502, 503, 504]);
            $tag = $is_overload ? "FLUX_OVERLOAD" : "FLUX_ERROR";
            logGeneration("FLUX: FAIL attempt=$attempt | HTTP=$httpCode curlErr=$curlErrno ($curlErrStr)" . ($is_overload ? " [Modal overloaded — will retry with longer wait]" : ""), $tag);
        }

        if ($attempt < $maxRetries) {
            // Back off longer on overload so Modal container has time to free up
            $wait = in_array($httpCode, [429, 502, 503, 504]) ? 10 : 3;
            logGeneration("FLUX: Waiting {$wait}s before retry " . ($attempt+1) . "...", "FLUX");
            sleep($wait);
        }
    }

    logTimed("FLUX: All $maxRetries attempts FAILED", "FLUX_ERROR", $t_func);
    return ['success' => false, 'source' => 'FLUX/Modal'];
}

// ═══════════════════════════════════════════════════════════════
// OPENAI gpt-image-1 FALLBACK — single image, timed
// ═══════════════════════════════════════════════════════════════
function generateWithOpenAI($prompt, $resolution = "1024x1536") {
    global $apiKey;
    $t_func = microtime(true);
    logGeneration("OPENAI: Starting fallback | prompt_len=" . strlen($prompt), "OPENAI");

    if (empty($apiKey)) {
        logGeneration("OPENAI: No API key — skipping", "OPENAI_ERROR");
        return ['success' => false, 'error' => 'No API key', 'source' => 'OpenAI'];
    }

    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey",
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            "model"         => "gpt-image-1",
            "prompt"        => $prompt,
            "size"          => $resolution,
            "quality"       => "medium",
            "output_format" => "png",
            "n"             => 1,
        ]),
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $curlError = curl_error($ch);
    curl_close($ch);

    logTimed("OPENAI: HTTP=$httpCode curlTime={$totalTime}s resp_len=" . strlen((string)$response), "OPENAI", $t_func);

    if ($curlError) {
        logGeneration("OPENAI: cURL error: $curlError", "OPENAI_ERROR");
        return ['success' => false, 'error' => $curlError, 'source' => 'OpenAI'];
    }
    if ($httpCode !== 200) {
        $parsed   = json_decode($response, true);
        $errorMsg = $parsed['error']['message'] ?? "HTTP $httpCode";
        logGeneration("OPENAI: HTTP error $httpCode — $errorMsg", "OPENAI_ERROR");
        return ['success' => false, 'error' => $errorMsg, 'source' => 'OpenAI'];
    }

    $result = json_decode($response, true);
    if (!isset($result['data'][0]['b64_json'])) {
        logGeneration("OPENAI: No b64_json in response", "OPENAI_ERROR");
        return ['success' => false, 'error' => 'No image data', 'source' => 'OpenAI'];
    }

    logTimed("OPENAI: SUCCESS", "OPENAI_SUCCESS", $t_func);
    return ['success' => true, 'image' => $result['data'][0]['b64_json'], 'source' => 'OpenAI gpt-image-1'];
}

// ═══════════════════════════════════════════════════════════════
// PARALLEL BATCH GENERATION — up to $concurrency at a time
// Each item: ['prompt'=>string, 'scene_num'=>int]
// Returns: array keyed by scene_num => ['success','image','source','elapsed']
// ═══════════════════════════════════════════════════════════════
function generateBatchWithFlux(array $items, int $concurrency = 10): array {
    global $MODAL_URL;
    $t_batch = microtime(true);
    $total   = count($items);
    logGeneration("BATCH_FLUX: Starting | scenes=$total concurrency=$concurrency", "BATCH");

    $payload_template = ['style' => 'cinematic', 'width' => 768, 'height' => 1344];
    $results = [];

    // Process in chunks
    $chunks = array_chunk($items, $concurrency);
    foreach ($chunks as $chunk_idx => $chunk) {
        $chunk_size = count($chunk);
        $t_chunk    = microtime(true);
        logGeneration("BATCH_FLUX: Chunk " . ($chunk_idx+1) . "/" . count($chunks) . " | $chunk_size scenes in parallel", "BATCH");

        $mh      = curl_multi_init();
        $handles = [];
        $t_scene = [];

        foreach ($chunk as $item) {
            $sn      = $item['scene_num'];
            $payload = json_encode(array_merge($payload_template, ['prompt' => $item['prompt']]));

            $ch = curl_init($MODAL_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 150,     // warm container should finish in <60 s
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$sn] = $ch;
            $t_scene[$sn] = microtime(true);
            logGeneration("BATCH_FLUX: Fired scene=$sn", "BATCH");
        }

        // Execute all in parallel
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.5);
        } while ($active > 0 && $mrc === CURLM_OK);

        // Collect results
        foreach ($handles as $sn => $ch) {
            $response  = curl_multi_getcontent($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $curlErrno = curl_errno($ch);
            $elapsed   = round(microtime(true) - $t_scene[$sn], 3);

            logGeneration(
                "BATCH_FLUX: Result scene=$sn | HTTP=$httpCode curlErr=$curlErrno curlTime={$totalTime}s elapsed={$elapsed}s resp_len=" . strlen((string)$response),
                "BATCH"
            );

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($curlErrno === 0 && $httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (!empty($data['image'])) {
                    logGeneration("BATCH_FLUX: SUCCESS scene=$sn elapsed={$elapsed}s", "BATCH_SUCCESS");
                    $results[$sn] = ['success' => true, 'image' => $data['image'], 'source' => 'FLUX/Modal', 'elapsed' => $elapsed];
                    continue;
                }
                $body_preview = substr((string)$response, 0, 200);
                logGeneration("BATCH_FLUX: HTTP 200 but no image key scene=$sn. Body: $body_preview", "BATCH_WARN");
            }

            logGeneration("BATCH_FLUX: FAIL scene=$sn elapsed={$elapsed}s", "BATCH_ERROR");
            $results[$sn] = ['success' => false, 'source' => 'FLUX/Modal', 'elapsed' => $elapsed];
        }

        curl_multi_close($mh);
        logTimed("BATCH_FLUX: Chunk " . ($chunk_idx+1) . " done | chunk_size=$chunk_size", "BATCH", $t_chunk);
    }

    logTimed("BATCH_FLUX: All chunks done | total_scenes=$total", "BATCH", $t_batch);
    return $results;
}

// ═══════════════════════════════════════════════════════════════
// PRIMARY SINGLE-IMAGE FUNCTION (used by generate_scene_image AJAX)
// Warmup → FLUX (with retries) → OpenAI fallback
// ═══════════════════════════════════════════════════════════════
function generateImageWithFallback($prompt, $maxFluxRetries = 2) {
    $t0 = microtime(true);
    logGeneration("=== generateImageWithFallback START ===", "MAIN");

    $result = generateWithFlux($prompt, $maxFluxRetries);
    if ($result['success']) {
        logTimed("MAIN: FLUX succeeded", "MAIN_SUCCESS", $t0);
        return $result;
    }

    logGeneration("MAIN: FLUX failed — FALLING BACK TO OPENAI ($$$ costs money!) ...", "MAIN_FALLBACK");
    $openaiResult = generateWithOpenAI($prompt);
    if ($openaiResult['success']) {
        logTimed("MAIN: OpenAI fallback succeeded", "MAIN_SUCCESS", $t0);
        return $openaiResult;
    }

    logTimed("MAIN: BOTH providers FAILED", "MAIN_ERROR", $t0);
    return ['success' => false, 'error' => 'Both providers failed', 'source' => 'none'];
}

function callAI($apiUrl, $apiKey, $systemPrompt, $userInput, $temp = 0.92, $timeout = 90) {
    if (!$apiKey) { error_log("[callAI] No API key"); return null; }
    $data = [
        "model"       => "gpt-4o-mini",
        "messages"    => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user",   "content" => $userInput]
        ],
        "temperature" => $temp
    ];
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST,       true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result   = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false) { error_log("[callAI] cURL error: $curl_err"); return null; }
    if ($http !== 200)     { error_log("[callAI] HTTP $http: " . substr($result, 0, 200)); return null; }
    $json = json_decode($result, true);
    if (isset($json['error'])) { error_log("[callAI] API error: " . ($json['error']['message'] ?? 'unknown')); return null; }
    return $json["choices"][0]["message"]["content"] ?? null;
}

// ── AJAX: Upload music file ────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_music') {
    header('Content-Type: application/json');
    $folder = "user_media/user_id_{$admin_id}_company_id_{$company_id}";
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    $allowed = ['mp3','mp4','wav','ogg','m4a','aac'];
    if (empty($_FILES['music_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file received']); exit;
    }
    $ext = strtolower(pathinfo($_FILES['music_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type']); exit;
    }
    $filename = 'music_' . time() . '_' . preg_replace('/[^a-z0-9_.-]/', '', strtolower($_FILES['music_file']['name']));
    $dest = $folder . '/' . $filename;
    if (move_uploaded_file($_FILES['music_file']['tmp_name'], $dest)) {
        echo json_encode(['success' => true, 'file' => $dest, 'name' => $_FILES['music_file']['name']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
    }
    exit;
}

// ── AJAX: List music library ───────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'list_music_library') {
    header('Content-Type: application/json');
    $folder = 'podcast_music';
    $allowed = ['mp3','mp4','wav','ogg','m4a','aac'];
    $files = [];
    if (is_dir($folder)) {
        foreach (scandir($folder) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $files[] = ['name' => $f, 'file' => $folder . '/' . $f, 'size' => filesize($folder . '/' . $f)];
            }
        }
    }
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// ── AJAX: Raw Modal test — shows exactly what Modal returns ───
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'test_modal_raw') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(200);
    $t0 = microtime(true);

    $test_payload = json_encode([
        'prompt' => 'a red apple on a white table, photorealistic',
        'style'  => 'cinematic',
        'width'  => 256,
        'height' => 256,
    ]);

    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $test_payload,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_VERBOSE        => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $curlErrno = curl_errno($ch);
    $curlErrStr= curl_error($ch);
    curl_close($ch);

    $elapsed    = round(microtime(true) - $t0, 2);
    $parsed     = $response ? json_decode($response, true) : null;
    $has_image  = !empty($parsed['image']);
    $body_preview = $response ? substr($response, 0, 500) : '(empty)';

    $result = [
        'url'          => $MODAL_URL,
        'http_code'    => $httpCode,
        'curl_errno'   => $curlErrno,
        'curl_error'   => $curlErrStr ?: null,
        'total_time_s' => $totalTime,
        'elapsed_s'    => $elapsed,
        'has_image'    => $has_image,
        'response_len' => strlen((string)$response),
        'response_keys'=> $parsed ? array_keys($parsed) : null,
        'body_preview' => $has_image ? '(base64 image data — OK)' : $body_preview,
        'verdict'      => $has_image
            ? "✅ FLUX working — {$elapsed}s"
            : "❌ FLUX not returning image — HTTP=$httpCode curlErr=$curlErrno",
    ];

    logGeneration("TEST_MODAL_RAW: " . json_encode($result), "TEST");
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// ── AJAX: Warmup Modal endpoint ────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'warmup_modal') {
    header('Content-Type: application/json');
    set_time_limit(120);
    logGeneration("WARMUP AJAX: Triggered", "WARMUP");
    $t0 = microtime(true);

    $is_warm = warmupModal();   // uses real inference POST, 90 s timeout

    $elapsed = round(microtime(true) - $t0, 2);
    logGeneration("WARMUP AJAX: Done in {$elapsed}s — is_warm=" . ($is_warm ? 'YES' : 'NO'), "WARMUP");

    echo json_encode([
        'success' => $is_warm,
        'message' => $is_warm
            ? "Modal is warm and ready ({$elapsed}s)"
            : "Modal cold start triggered — container booting ({$elapsed}s). Try generating now; first image may be slower.",
        'elapsed' => $elapsed,
    ]);
    exit;
}

// ── AJAX: Get more options for a specific parameter ───────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'more_options') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$apiKey) {
        echo json_encode(['success' => false, 'error' => 'API key not found']);
        exit;
    }
    
    $business  = trim($_POST['business']  ?? $_SESSION['business'] ?? '');
    $field     = trim($_POST['field']     ?? '');
    $existing  = trim($_POST['existing']  ?? '');
    $additional= trim($_POST['additional']?? $_SESSION['additional_info'] ?? '');

    $valid_fields = ['title', 'sentiment', 'hook', 'character', 'setting', 'audio_mood', 'hero_element', 'emotional_outcome'];
    if (!$field || !in_array($field, $valid_fields)) {
        echo json_encode(['success' => false, 'error' => 'Invalid field']);
        exit;
    }

    $field_labels = [
        'title'             => 'video title (short, catchy, max 8 words)',
        'sentiment'         => 'overall emotional tone (short phrase, max 8 words)',
        'hook'              => 'opening line that grabs attention in 2 seconds (max 8 words)',
        'character'         => 'character or persona the viewer relates to (max 8 words)',
        'setting'           => 'visual setting and ambience (max 8 words)',
        'audio_mood'        => 'music style and feel (max 8 words)',
        'hero_element'      => 'hero element — the key service moment or visual star (max 8 words)',
        'emotional_outcome' => 'emotional outcome — how viewer feels at the very end (max 8 words)',
    ];

    $add_block = $additional ? "Additional context: $additional\n" : "";
    
    $systemPrompt = "You are a cinematic video strategist for short-form social media videos.
Generate 4 NEW and DIFFERENT options for the '$field' parameter for a short-form video.
Business/Niche: $business
{$add_block}
Already shown options (DO NOT repeat these): $existing

Return ONLY a JSON array of exactly 4 strings. No markdown, no explanation, no extra text.
Each option max 8 words. Make them varied and creative.
Example: [\"Option one here\",\"Option two here\",\"Option three here\",\"Option four here\"]";

    $result = callAI($apiUrl, $apiKey, $systemPrompt, "Generate 4 more options for: " . $field_labels[$field], 0.95);
    $options = $result ? json_decode($result, true) : null;

    if (!is_array($options) || count($options) < 2) {
        echo json_encode(['success' => false, 'error' => 'Could not generate options']);
        exit;
    }

    echo json_encode(['success' => true, 'options' => array_slice($options, 0, 4)]);
    exit;
}

// ── AJAX: Regenerate scene with user suggestion ──────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'suggest_scene') {
    header('Content-Type: application/json; charset=utf-8');
    $wan_prompt = trim($_POST['wan_prompt'] ?? '');
    $suggestion = trim($_POST['suggestion'] ?? '');

    if (!$wan_prompt || !$suggestion) {
        echo json_encode(['success' => false, 'error' => 'Missing prompt or suggestion']);
        exit;
    }

    $systemPrompt = 'You are a cinematic video prompt engineer.
The user wants to change a specific element in the scene. You MUST apply this change fully and explicitly.

CRITICAL RULES:
- If the suggestion changes a person — FULLY replace ALL character descriptions
- If the suggestion changes background/location — replace ALL environment descriptions
- The change must be OBVIOUS and DOMINANT in the new prompt.

Return ONLY this JSON (no markdown, no explanation):
{
  "video_prompt": "updated WAN 2.2 cinematic video prompt with the change fully applied",
  "image_prompt": "updated photorealistic image prompt with the change fully applied"
}';

    $userInput = "ORIGINAL VIDEO PROMPT:\n" . $wan_prompt . "\n\nUSER SUGGESTION:\n" . $suggestion;

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $apiKey", "Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode([
            "model"       => "gpt-4o-mini",
            "messages"    => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user",   "content" => $userInput],
            ],
            "temperature" => 0.8,
            "max_tokens"  => 600,
        ]),
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $raw = trim(json_decode($res, true)["choices"][0]["message"]["content"] ?? "");
    $raw = preg_replace(["/^```json\s*/i", "/```\s*$/i"], "", $raw);
    $parsed = json_decode(trim($raw), true);

    if (!$parsed || empty($parsed["video_prompt"])) {
        echo json_encode(["success" => false, "error" => "AI could not update prompts"]);
        exit;
    }
    echo json_encode(["success" => true, "video_prompt" => $parsed["video_prompt"], "image_prompt" => $parsed["image_prompt"] ?? $parsed["video_prompt"]]);
    exit;
}

// ── AJAX: Save cinematic video to DB ──────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_cinematic_to_db') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(180);

    try {
        // ── Resolve team_lead_id ──────────────────────────────────────
        $_u = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id FROM hdb_users WHERE id = $admin_id LIMIT 1"));
        $team_lead_id = (!empty($_u) && trim((string)($_u['role'] ?? '')) === 'Team Member' && (int)($_u['team_lead_id'] ?? 0) > 0)
            ? (int)$_u['team_lead_id']
            : $admin_id;

        // ── Get user caption/header/footer/logo settings ─────────────
        // Minimal inline version (pulls from hdb_user_settings)
        $user_settings = [];
        $def_cap = [
            'fontfamily'=>'Arial','fontsize'=>28,'fontcolor'=>'#ffffff','fontweight'=>'normal',
            'font_italic'=>0,'font_underline'=>0,'caption_alignment'=>'center','text_align_v'=>'bottom',
            'fontcolor_bg'=>'#000000','fontbg_enable'=>0,'stroke_color'=>'#000000','stroke_width'=>0,
            'shadow_color'=>'#000000','gradient_color'=>'#ff6600','_anim_style'=>'none','_anim_speed'=>1.0,
            '_text_fx'=>'none','_text_fx_col'=>'#ffffff','caption_style'=>'none','caption_position'=>'bottom',
            'display_mode'=>'full','position_x'=>50,'position_y'=>200,'width'=>500,'is_enabled'=>1,
        ];
        $def_hdr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>16,'text_align_v'=>'top']);
        $def_ftr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>555,'text_align_v'=>'bottom']);
        $def_logo = ['logo_enabled'=>0,'logo_name'=>'','logo_file'=>'','logo_size_pct'=>15,'position_x'=>960,'position_y'=>20,'width'=>162];

        $us_res = mysqli_query($conn,
            "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$company_id ORDER BY id DESC LIMIT 1");
        if ($us_res && $us_row = mysqli_fetch_assoc($us_res)) {
            $from_db = [
                'fontfamily'      => $us_row['fontfamily']      ?? $def_cap['fontfamily'],
                'fontsize'        => (int)($us_row['fontsize']  ?? $def_cap['fontsize']),
                'fontcolor'       => $us_row['fontcolor']       ?? $def_cap['fontcolor'],
                'fontweight'      => $us_row['fontweight']      ?? $def_cap['fontweight'],
                'font_italic'     => (int)($us_row['font_italic']    ?? 0),
                'font_underline'  => (int)($us_row['font_underline'] ?? 0),
                'caption_alignment'=> $us_row['caption_alignment'] ?? 'center',
                'text_align_v'    => $us_row['text_align_v']    ?? 'bottom',
                'fontcolor_bg'    => $us_row['fontcolor_bg']    ?? '#000000',
                'fontbg_enable'   => (int)($us_row['fontbg_enable'] ?? 0),
                'stroke_color'    => $us_row['stroke_color']    ?? '#000000',
                'stroke_width'    => (int)($us_row['stroke_width'] ?? 0),
                'shadow_color'    => $us_row['shadow_color']    ?? '#000000',
                'gradient_color'  => $us_row['gradient_color']  ?? '#ff6600',
                '_anim_style'     => $us_row['text_animation']  ?? $us_row['animation_style'] ?? 'none',
                '_anim_speed'     => is_numeric($us_row['animation_speed'] ?? null) ? (float)$us_row['animation_speed'] : 1.0,
                '_text_fx'        => $us_row['text_effect']     ?? 'none',
                '_text_fx_col'    => $us_row['text_effect_color'] ?? '#ffffff',
                'caption_style'   => $us_row['caption_style']   ?? 'none',
                'caption_position'=> $us_row['caption_position'] ?? 'bottom',
                'display_mode'    => $us_row['display_mode']    ?? 'full',
                'position_x'      => (int)($us_row['position_x'] ?? 50),
                'position_y'      => (int)($us_row['position_y'] ?? 200),
                'width'           => (int)($us_row['width']     ?? 500),
                'is_enabled'      => 1,
                'caption_text'    => $us_row['caption_text']    ?? '',
            ];
            $cap_settings = array_merge($def_cap, $from_db);
            $hdr_settings = array_merge($def_hdr, [
                'is_enabled'   => (int)($us_row['header_enabled'] ?? 0),
                'caption_text' => $us_row['header_text'] ?? '',
                'fontfamily'   => $us_row['header_fontfamily'] ?? $def_cap['fontfamily'],
                'fontsize'     => (int)($us_row['header_fontsize'] ?? 24),
                'fontcolor'    => $us_row['header_fontcolor'] ?? '#ffffff',
            ]);
            $ftr_settings = array_merge($def_ftr, [
                'is_enabled'   => (int)($us_row['footer_enabled'] ?? 0),
                'caption_text' => $us_row['footer_text'] ?? '',
                'fontfamily'   => $us_row['footer_fontfamily'] ?? $def_cap['fontfamily'],
                'fontsize'     => (int)($us_row['footer_fontsize'] ?? 20),
                'fontcolor'    => $us_row['footer_fontcolor'] ?? '#ffffff',
            ]);
            $logo_settings = array_merge($def_logo, [
                'logo_enabled'   => (int)($us_row['logo_flag'] ?? 0),
                'logo_name'      => $us_row['logo_file'] ?? '',
                'logo_file'      => $us_row['logo_file'] ?? '',
                'logo_size_pct'  => (int)($us_row['logo_size_pct'] ?? 15),
                'position_x'     => (int)($us_row['logo_position_x'] ?? 960),
                'position_y'     => (int)($us_row['logo_position_y'] ?? 20),
                'width'          => (int)($us_row['logo_width'] ?? 162),
            ]);
        } else {
            $cap_settings  = $def_cap;
            $hdr_settings  = $def_hdr;
            $ftr_settings  = $def_ftr;
            $logo_settings = $def_logo;
        }
        $user_settings = ['caption'=>$cap_settings,'header'=>$hdr_settings,'footer'=>$ftr_settings,'logo'=>$logo_settings];

        // ── Parse incoming scenes from JS ─────────────────────────────
        $scenes_json = $_POST['scenes'] ?? '[]';
        $scenes_in   = json_decode($scenes_json, true);
        if (!is_array($scenes_in) || empty($scenes_in)) {
            echo json_encode(['success'=>false,'message'=>'No scene data received']);
            exit;
        }

        $title_raw  = trim($_POST['title']   ?? ($_SESSION['selected']['title'] ?? 'Cinematic Video'));
        $niche      = mysqli_real_escape_string($conn, trim($_POST['business'] ?? ($_SESSION['business'] ?? '')));
        $esc_title  = mysqli_real_escape_string($conn, $title_raw);
        $lang_code  = 'en';
        $reel_type  = 'cinematic';
        $category   = 'cinematic';
        $topic_key  = mysqli_real_escape_string($conn, $niche ?: 'cinematic');
        $today      = date('Y-m-d');
        $co_id      = (int)($_SESSION['company_id'] ?? $admin_id);

        // Build hashtags/keywords from scene captions
        $all_text  = implode(' ', array_column($scenes_in, 'caption'));
        $stop      = ['the','and','for','you','your','with','that','this','are','can','will','have',
                      'from','they','their','what','about','there','more','some','would','could',
                      'should','been','were','was','one','two','first','then','than','very','just',
                      'like','into','over','also','after','other','only'];
        $words     = array_diff(str_word_count(strtolower($all_text), 1), $stop);
        $kw_arr    = array_slice(array_unique(array_values($words)), 0, 10);
        $ht_arr    = array_map(fn($w) => '#'.$w, array_slice($kw_arr, 0, 7));
        if ($niche) { $kw_arr[] = strtolower($niche); $ht_arr[] = '#'.preg_replace('/\s+/','',$niche); }
        $hashtags  = mysqli_real_escape_string($conn, implode(', ', array_unique($ht_arr)));
        $keywords  = mysqli_real_escape_string($conn, implode(', ', array_unique($kw_arr)));

        // ── INSERT hdb_podcasts ────────────────────────────────────────
        $sql_pod = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
             created_date, updated_at, niche, category, topic_key, hashtags, keywords,
             host_voice, guest_voice, voice_rate, is_campaign,
             logo_flag, facebook_status, tiktok_status, instagram_status,
             youtube_status, twitter_status, linkedin_status,
             schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name,
             videogen_flag)
            VALUES
            ($admin_id, $team_lead_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'draft',
             '$today', NOW(), '$niche', '$category', '$topic_key', '$hashtags', '$keywords',
             '', '', 1.0, 0,
             0, 'pending', 'pending', 'pending',
             'pending', 'pending', 'pending',
             '$today', '09:00', '$today', 'vertical', 'video', '', '',
             1)";

        if (!mysqli_query($conn, $sql_pod)) {
            echo json_encode(['success'=>false,'message'=>'Podcast insert failed: '.mysqli_error($conn)]);
            exit;
        }
        $podcast_id    = mysqli_insert_id($conn);
        $success_count = 0;
        $story_id_map  = [];   // scene_num => story_id

        foreach ($scenes_in as $i => $scene) {
            $seq_no      = $i + 1;
            $scene_num   = (int)($scene['num'] ?? $seq_no);  // use JS scene num if present
            $caption_raw = trim($scene['caption'] ?? '');
            $wan_prompt  = trim($scene['wan']     ?? '');
            $image_file  = trim($scene['file']    ?? '');

            // Use caption as main text; fall back to wan prompt excerpt
            $text   = $caption_raw ?: substr($wan_prompt, 0, 200);
            if (empty($text)) continue;

            $te  = mysqli_real_escape_string($conn, $text);
            $de  = mysqli_real_escape_string($conn, substr($text, 0, 50).(strlen($text)>50?'...':''));
            $pe  = mysqli_real_escape_string($conn, $wan_prompt);
            $vpe = mysqli_real_escape_string($conn, $wan_prompt);
            $ife = mysqli_real_escape_string($conn, $image_file);
            $tke = mysqli_real_escape_string($conn, $title_raw);

            // Duration estimate (5 sec per scene for cinematic)
            $duration = 5;

            // ── INSERT hdb_podcast_stories ────────────────────────────
            $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt, video_prompt, visual_type,
                 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
                 voice_id, voice_rate, image_file, videogen_flag)
                VALUES
                ($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', 'host',
                 '$te', '$de', $duration, '$pe', '$vpe', 'image',
                 'PENDING', NOW(), $seq_no, 0, '', '',
                 '', 1.0, '$ife', 1)";

            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                $story_id_map[$scene_num] = $story_id;
                // ── INSERT hdb_captions (caption row for this scene) ──
                if (!empty($text)) {
                    $cap = $user_settings['caption'];
                    $hdr = $user_settings['header'];
                    $ftr = $user_settings['footer'];

                    // Caption
                    if ((int)($cap['is_enabled'] ?? 1)) {
                        $words_arr = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
                        $cap_text  = count($words_arr) > 10
                            ? implode(' ', array_slice($words_arr, 0, 10)).'…'
                            : $text;
                        $ct  = mysqli_real_escape_string($conn, $cap_text);
                        $ff  = mysqli_real_escape_string($conn, $cap['fontfamily']    ?? 'Arial');
                        $fc  = mysqli_real_escape_string($conn, $cap['fontcolor']     ?? '#ffffff');
                        $fw  = mysqli_real_escape_string($conn, $cap['fontweight']    ?? 'normal');
                        $fst = ((int)($cap['font_italic'] ?? 0)) ? 'italic' : 'normal';
                        $uline = (int)($cap['font_underline'] ?? 0);
                        $ta  = mysqli_real_escape_string($conn, $cap['caption_alignment'] ?? 'center');
                        $tav = mysqli_real_escape_string($conn, $cap['text_align_v']   ?? 'bottom');
                        $bgc = mysqli_real_escape_string($conn, $cap['fontcolor_bg']   ?? '#000000');
                        $bge = (int)($cap['fontbg_enable'] ?? 0);
                        $sc  = mysqli_real_escape_string($conn, $cap['stroke_color']   ?? '#000000');
                        $sw  = (int)($cap['stroke_width']  ?? 0);
                        $se  = $sw > 0 ? 1 : 0;
                        $shc = mysqli_real_escape_string($conn, $cap['shadow_color']   ?? '#000000');
                        $gc  = mysqli_real_escape_string($conn, $cap['gradient_color'] ?? '#ff6600');
                        $ast = mysqli_real_escape_string($conn, $cap['_anim_style']    ?? 'none');
                        $asp = is_numeric($cap['_anim_speed'] ?? null) ? (float)$cap['_anim_speed'] : 1.0;
                        $tfx = mysqli_real_escape_string($conn, $cap['_text_fx']       ?? 'none');
                        $tfc = mysqli_real_escape_string($conn, $cap['_text_fx_col']   ?? '#ffffff');
                        $cst = mysqli_real_escape_string($conn, $cap['caption_style']  ?? 'none');
                        $cpv = mysqli_real_escape_string($conn, $cap['caption_position'] ?? 'bottom');
                        $dm  = mysqli_real_escape_string($conn, $cap['display_mode']   ?? 'full');
                        $px  = (int)($cap['position_x'] ?? 50);
                        $py  = (int)($cap['position_y'] ?? 200);
                        $pw  = min((int)($cap['width']   ?? 500), 350);
                        $fs  = (int)($cap['fontsize']    ?? 28);
                        mysqli_query($conn, "INSERT INTO hdb_captions
                            (podcast_id, story_id, caption_type, caption_name, text_content,
                             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
                             text_align, text_align_v, bg_color, bg_enabled,
                             stroke_color, stroke_width, stroke_enabled,
                             shadow_color, gradient_color, text_effects, text_effect_colors,
                             panning_zooming_type, panning_zooming_speed,
                             position_x, position_y, width, rotation,
                             animation_style, animation_speed,
                             caption_style, caption_position, display_mode,
                             text_decoration, is_visible, z_index)
                            VALUES
                            ($podcast_id, $story_id, 'caption', 'main', '$ct',
                             '$ff', $fs, '$fc', '$fw', '$fst', $uline,
                             '$ta', '$tav', '$bgc', $bge,
                             '$sc', $sw, $se,
                             '$shc', '$gc', '$tfx', '$tfc',
                             0, 0,
                             $px, $py, $pw, 0,
                             '$ast', $asp,
                             '$cst', '$cpv', '$dm',
                             'none', 1, 1)");
                    }

                    // Header
                    if ((int)($hdr['is_enabled'] ?? 0) && !empty($hdr['caption_text'])) {
                        $ht = mysqli_real_escape_string($conn, $hdr['caption_text']);
                        $ff = mysqli_real_escape_string($conn, $hdr['fontfamily']  ?? 'Arial');
                        $fc = mysqli_real_escape_string($conn, $hdr['fontcolor']   ?? '#ffffff');
                        $fw = mysqli_real_escape_string($conn, $hdr['fontweight']  ?? 'normal');
                        $fs = (int)($hdr['fontsize'] ?? 24);
                        $pw = min((int)($hdr['width'] ?? 1080), 350);
                        mysqli_query($conn, "INSERT INTO hdb_captions
                            (podcast_id, story_id, caption_type, caption_name, text_content,
                             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
                             text_align, text_align_v, bg_color, bg_enabled,
                             stroke_color, stroke_width, stroke_enabled,
                             shadow_color, gradient_color, text_effects, text_effect_colors,
                             panning_zooming_type, panning_zooming_speed,
                             position_x, position_y, width, rotation,
                             animation_style, animation_speed,
                             caption_style, caption_position, display_mode,
                             text_decoration, is_visible, z_index)
                            VALUES
                            ($podcast_id, $story_id, 'header', 'header', '$ht',
                             '$ff', $fs, '$fc', '$fw', 'normal', 0,
                             'center', 'top', '#000000', 0,
                             '#000000', 0, 0,
                             '#000000', '#ff6600', 'none', '#ffffff',
                             0, 0,
                             50, 16, $pw, 0,
                             'none', 1.0,
                             'none', 'top', 'full',
                             'none', 1, 2)");
                    }

                    // Footer
                    if ((int)($ftr['is_enabled'] ?? 0) && !empty($ftr['caption_text'])) {
                        $ft = mysqli_real_escape_string($conn, $ftr['caption_text']);
                        $ff = mysqli_real_escape_string($conn, $ftr['fontfamily']  ?? 'Arial');
                        $fc = mysqli_real_escape_string($conn, $ftr['fontcolor']   ?? '#ffffff');
                        $fw = mysqli_real_escape_string($conn, $ftr['fontweight']  ?? 'normal');
                        $fs = (int)($ftr['fontsize'] ?? 20);
                        $pw = min((int)($ftr['width'] ?? 1080), 350);
                        mysqli_query($conn, "INSERT INTO hdb_captions
                            (podcast_id, story_id, caption_type, caption_name, text_content,
                             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
                             text_align, text_align_v, bg_color, bg_enabled,
                             stroke_color, stroke_width, stroke_enabled,
                             shadow_color, gradient_color, text_effects, text_effect_colors,
                             panning_zooming_type, panning_zooming_speed,
                             position_x, position_y, width, rotation,
                             animation_style, animation_speed,
                             caption_style, caption_position, display_mode,
                             text_decoration, is_visible, z_index)
                            VALUES
                            ($podcast_id, $story_id, 'footer', 'footer', '$ft',
                             '$ff', $fs, '$fc', '$fw', 'normal', 0,
                             'center', 'bottom', '#000000', 0,
                             '#000000', 0, 0,
                             '#000000', '#ff6600', 'none', '#ffffff',
                             0, 0,
                             50, 555, $pw, 0,
                             'none', 1.0,
                             'none', 'bottom', 'full',
                             'none', 1, 3)");
                    }
                }
                $success_count++;
            } else {
                // Fallback without natural_language_tags / image_file / videogen_flag
                $ins2 = "INSERT INTO hdb_podcast_stories
                    (podcast_id, lang_code, category, topic_key, title, actor,
                     text_contents, text_display, duration, prompt, video_prompt, visual_type,
                     status, created_date, seq_no, logo_flag, hashtags,
                     voice_id, voice_rate)
                    VALUES
                    ($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', 'host',
                     '$te', '$de', $duration, '$pe', '$vpe', 'image',
                     'PENDING', NOW(), $seq_no, 0, '',
                     '', 1.0)";
                if (mysqli_query($conn, $ins2)) {
                    $story_id_map[$scene['num'] ?? $seq_no] = mysqli_insert_id($conn);
                    $success_count++;
                }
            }
        }

        // Deduct 1 credit
        $cred_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $deduct_from = ($cred_row && $cred_row['role'] === 'Team Member' && (int)($cred_row['team_lead_id'] ?? 0) > 0)
            ? (int)$cred_row['team_lead_id'] : $admin_id;
        mysqli_query($conn, "UPDATE hdb_users SET credits = GREATEST(0, credits - 1) WHERE id=$deduct_from");

        echo json_encode([
            'success'      => true,
            'podcast_id'   => $podcast_id,
            'scene_count'  => $success_count,
            'story_id_map' => $story_id_map,   // { scene_num: story_id } for image update
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: Deduct credits for cinematic video ──────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'deduct_cinematic_credits') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Resolve who to deduct from
        $_u2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $deduct_from = (!empty($_u2) && trim((string)($_u2['role'] ?? '')) === 'Team Member' && (int)($_u2['team_lead_id'] ?? 0) > 0)
            ? (int)$_u2['team_lead_id']
            : $admin_id;

        // Re-check balance in real time to avoid race
        $bal_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1"));
        $balance = (int)($bal_row['credit_balance'] ?? 0);

        if ($balance < 6) {
            echo json_encode(['success'=>false,'message'=>'Insufficient credits','balance'=>$balance]);
            exit;
        }

        mysqli_query($conn,
            "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - 6) WHERE id=$deduct_from");

        $new_balance = $balance - 6;
        echo json_encode(['success'=>true,'deducted'=>6,'balance'=>$new_balance]);
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: Generate scene image using FLUX with OpenAI fallback ──
if (isset($_POST['ajax_action']) && ($_POST['ajax_action'] === 'generate_scene_image' || $_POST['ajax_action'] === 'generate_scene_image_modal')) {
    set_time_limit(600); // 2 retries × 180s FLUX timeout + enhance + save = up to ~420s worst case
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $wan_prompt  = trim($_POST['wan_prompt']  ?? '');
    $caption     = trim($_POST['caption']     ?? '');
    $scene_num   = (int)($_POST['scene_num']  ?? 0);
    $podcast_id  = (int)($_POST['podcast_id'] ?? 0);
    $story_id    = (int)($_POST['story_id']   ?? 0);

    // ── Folder: always flat podcast_images/ ──────────────────
    $save_folder     = 'podcast_images';
    $image_folder_db = 'podcast_images';

    if (!$wan_prompt) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No prompt provided', 'fail_stage' => 'input']);
        exit;
    }

    $t_req = microtime(true);
    logGeneration("=== SCENE REQUEST scene=$scene_num podcast=$podcast_id story=$story_id | prompt_len=" . strlen($wan_prompt) . " ===", "REQUEST");

    // NOTE: warmup is handled by generateAllScenes() JS before this is called.
    // DO NOT call warmupModal() here — it blocks 30-90s per parallel request.

    // ── Step 1: Enhance the prompt via OpenAI ─────────────────
    $t_enhance = microtime(true);
    $enhance_system = "You are a world-class cinematic stills director. Convert the scene description into a detailed photorealistic image prompt.
CRITICAL: Images MUST be BRIGHT with COOL-NEUTRAL lighting (5500K-6500K). NO warm tones, NO yellow cast.
Include: bright natural daylight, cool-neutral lighting, vivid colors, cinematic realism, ultra photorealistic, 8K, shallow depth of field, bokeh.
IMPORTANT: Do NOT add any text, captions, subtitles, watermarks, or written words anywhere on the image.
OUTPUT: One flowing paragraph, minimum 150 words.";

    $character_context = '';
    if (isset($_SESSION['selected'])) {
        $sel = $_SESSION['selected'];
        $character_context = "CHARACTER: " . ($sel['character'] ?? '') . "\nSETTING: " . ($sel['setting'] ?? '');
    }
    $story_context   = isset($_SESSION['story']) ? "STORY CONTEXT: " . substr($_SESSION['story'], 0, 300) : '';
    $full_user_input = $story_context . "\n" . $character_context . "\nSCENE: " . $wan_prompt;

    $enhanced_prompt = $wan_prompt; // fallback if enhance fails
    if ($apiKey) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => $enhance_system],
                    ['role' => 'user',   'content' => $full_user_input],
                ],
                'temperature' => 0.85,
                'max_tokens'  => 800,
            ]),
        ]);
        $res           = curl_exec($ch);
        $enhance_http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $enhance_curlT = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $enhance_err   = curl_errno($ch);
        curl_close($ch);
        $parsed_enhance = json_decode($res, true);
        if ($enhance_err === 0 && $enhance_http === 200 && !empty($parsed_enhance['choices'][0]['message']['content'])) {
            $enhanced_prompt = $parsed_enhance['choices'][0]['message']['content'];
            logTimed("SCENE $scene_num: Prompt enhanced | HTTP=$enhance_http curlTime={$enhance_curlT}s len=" . strlen($enhanced_prompt), "ENHANCE", $t_enhance);
        } else {
            $enhance_err_msg = $parsed_enhance['error']['message'] ?? "HTTP=$enhance_http curlErr=$enhance_err";
            logGeneration("SCENE $scene_num: Prompt enhance FAILED ($enhance_err_msg) — using raw WAN prompt", "ENHANCE_WARN");
        }
    } else {
        logGeneration("SCENE $scene_num: No API key — skipping prompt enhancement, using raw WAN prompt", "ENHANCE_WARN");
    }

    // ── Step 2: Generate image ────────────────────────────────
    // Append no-text instruction directly to prompt — FLUX reads this literally
    $enhanced_prompt .= ' No text, no captions, no subtitles, no watermarks, no written words on the image.';
    $t_gen  = microtime(true);
    $result = generateImageWithFallback($enhanced_prompt);
    $elapsed_gen = round(microtime(true) - $t_gen, 2);
    logTimed(
        "SCENE $scene_num: Generation done | success=" . ($result['success'] ? 'YES' : 'NO') .
        " source=" . ($result['source'] ?? 'N/A') .
        " elapsed={$elapsed_gen}s" .
        (!$result['success'] ? " error=" . ($result['error'] ?? 'unknown') : ''),
        "REQUEST", $t_gen
    );

    if (!$result['success']) {
        $fail_reason = $result['error'] ?? 'Both FLUX and OpenAI failed';
        logGeneration("SCENE $scene_num: FINAL FAILURE — $fail_reason", "SCENE_FAIL");
        ob_end_clean();
        echo json_encode([
            'success'     => false,
            'error'       => $fail_reason,
            'fail_stage'  => 'generation',
            'scene'       => $scene_num,
            'elapsed'     => round(microtime(true) - $t_req, 2),
        ]);
        exit;
    }

    // ── Step 3: Save image to podcast_images/ ────────────────
    if (!is_dir($save_folder)) mkdir($save_folder, 0755, true);

    // Filename: scene_{podcast_id}_{scene_num}_{timestamp}.png
    $safe_name = 'scene_' . ($podcast_id ?: 'x') . '_' . $scene_num . '_' . time();
    $filename  = $safe_name . '.png';
    $filepath  = $save_folder . '/' . $filename;

    $save_ok = file_put_contents($filepath, base64_decode($result['image']));
    if ($save_ok === false) {
        logGeneration("SCENE $scene_num: FILE SAVE FAILED — path=$filepath", "SCENE_FAIL");
        ob_end_clean();
        echo json_encode([
            'success'    => false,
            'error'      => 'Image generated but failed to save to disk: ' . $filepath,
            'fail_stage' => 'save',
            'scene'      => $scene_num,
        ]);
        exit;
    }
    logTimed("SCENE $scene_num: Saved to $filepath ({$save_ok} bytes) source={$result['source']}", "SAVE", $t_req);

    // ── Step 4: Update hdb_podcast_stories if story_id given ─
    $db_updated = false;
    if ($story_id > 0) {
        $esc_file   = mysqli_real_escape_string($conn, $filename);
        $esc_folder = mysqli_real_escape_string($conn, $image_folder_db);

        // Try full update first
        $upd = mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET image_file   = '$esc_file',
                 image_name   = '$esc_file',
                 image_folder = '$esc_folder',
                 status       = 'IMAGE_READY',
                 updated_at   = NOW()
             WHERE id = $story_id AND podcast_id = $podcast_id"
        );

        if (!$upd) {
            $mysql_err = mysqli_error($conn);
            logGeneration("SCENE $scene_num: DB update FAILED (full) story_id=$story_id — $mysql_err", "DB_WARN");

            // Fallback: try without image_name and updated_at in case columns don't exist
            $upd2 = mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                 SET image_file   = '$esc_file',
                     image_folder = '$esc_folder',
                     status       = 'IMAGE_READY'
                 WHERE id = $story_id AND podcast_id = $podcast_id"
            );
            if ($upd2) {
                $db_updated = true;
                logGeneration("SCENE $scene_num: DB updated (fallback, no image_name/updated_at) story_id=$story_id", "DB_UPDATE");
            } else {
                logGeneration("SCENE $scene_num: DB update FAILED (fallback) story_id=$story_id — " . mysqli_error($conn), "DB_WARN");
            }
        } else {
            $db_updated = true;
            logGeneration("SCENE $scene_num: DB updated story_id=$story_id image_file=$filename image_folder=$image_folder_db", "DB_UPDATE");
        }
    } else {
        logGeneration("SCENE $scene_num: story_id=0 — DB not updated (storyIdMap may have key mismatch)", "DB_WARN");
    }

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'file'        => $filepath,
        'filename'    => $filename,
        'folder'      => $image_folder_db,
        'scene'       => $scene_num,
        'story_id'    => $story_id,
        'caption'     => $caption,
        'source'      => $result['source'],
        'elapsed'     => round(microtime(true) - $t_req, 2),
        'db_updated'  => $db_updated,
    ]);
    exit;
}

// ── STEP 1: AI suggests all parameters from niche ─────────────
if ($step == "1" && isset($_POST['business']) && !empty(trim($_POST['business']))) {
    $business = trim($_POST['business']);
    $additional_info = trim($_POST['additional_info'] ?? '');
    $cta_text = trim($_POST['cta_text'] ?? '');
    $_SESSION['business'] = $business;
    $_SESSION['additional_info'] = $additional_info;
    $_SESSION['cta_text'] = $cta_text;

    $systemPrompt = "You are a cinematic video strategist for short-form social media videos.
Given a business niche, generate EXACTLY the following JSON with NO extra text.
Each field must have 'selected' and 'options' (4 alternatives).

Fields: title, sentiment, hook, character, setting, audio_mood, hero_element, emotional_outcome

Return pure JSON only:
{
  \"title\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"sentiment\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"character\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"setting\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hero_element\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"emotional_outcome\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}";
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, "Business/Niche: $business", 0.85);

    if ($raw) {
        $raw = preg_replace('/^```json\s*/i', '', trim($raw));
        $raw = preg_replace('/^```\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/i', '', $raw);
        $raw = trim($raw);
    }

    $suggestions = $raw ? json_decode($raw, true) : null;

    if ($suggestions && isset($suggestions['title'])) {
        $_SESSION['suggestions'] = $suggestions;
    } else {
        $debug = $raw ? substr($raw, 0, 200) : ($apiKey ? 'callAI returned null' : 'No API key found');
        error_log("[movie_gen step1] Failed: $debug");
        $_SESSION['error'] = "AI could not generate suggestions. Please try again.";
    }
}

// ── STEP 2: Generate Story from selected params ────────────────
if ($step == "2" && isset($_SESSION['suggestions'])) {
    $s = $_SESSION['suggestions'];
    $business = $_SESSION['business'] ?? '';

    $sel = [
        'title' => trim($_POST['sel_title'] ?? $s['title']['selected'] ?? ''),
        'sentiment' => trim($_POST['sel_sentiment'] ?? $s['sentiment']['selected'] ?? ''),
        'hook' => trim($_POST['sel_hook'] ?? $s['hook']['selected'] ?? ''),
        'character' => trim($_POST['sel_character'] ?? $s['character']['selected'] ?? ''),
        'setting' => trim($_POST['sel_setting'] ?? $s['setting']['selected'] ?? ''),
        'audio_mood' => trim($_POST['sel_audio_mood'] ?? $s['audio_mood']['selected'] ?? ''),
        'hero_element' => trim($_POST['sel_hero_element'] ?? $s['hero_element']['selected'] ?? ''),
        'emotional_outcome' => trim($_POST['sel_emotional_outcome'] ?? $s['emotional_outcome']['selected'] ?? ''),
    ];
    $_SESSION['selected'] = $sel;

    $paramBlock = "Business: $business\nTitle: {$sel['title']}\nSentiment: {$sel['sentiment']}\nHook: {$sel['hook']}\nCharacter: {$sel['character']}\nSetting: {$sel['setting']}\nAudio Mood: {$sel['audio_mood']}\nHero Element: {$sel['hero_element']}\nEmotional Outcome: {$sel['emotional_outcome']}";

    $systemPrompt = "You are a world-class cinematic story director.
Create a powerful story concept using the parameters provided.

Return in EXACTLY this format:

TITLE: [title]
SENTIMENT: [sentiment]
HOOK: [hook]
CHARACTER: [character]
SETTING: [setting]
AUDIO MOOD: [audio mood]
HERO ELEMENT: [hero element]
EMOTIONAL OUTCOME: [emotional outcome]

CORE STORY:
[One powerful cinematic paragraph — emotional journey from problem to resolution.]";
    
    $additional = $_SESSION['additional_info'] ?? '';
    if ($additional) $paramBlock .= "\nAdditional Instructions: $additional";
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $paramBlock);
    if ($response) {
        $_SESSION['story'] = $response;
        unset($_SESSION['error']);
    } else {
        $_SESSION['error'] = "Story generation failed — API timeout or key issue. Please try again.";
        error_log("[vizard step2] callAI returned null for story generation");
    }
}

// ── STEP 3: Generate Full Video Script ────────────────────────
if ($step == "3" && isset($_SESSION['story'])) {
    $story = $_SESSION['story'];
    $sel = $_SESSION['selected'] ?? [];
    $business = $_SESSION['business'] ?? '';

    $systemPrompt = "You are an expert cinematic AI film director.
Convert the approved story into a complete 30-40 second faceless cinematic video script.

# OUTPUT STRUCTURE

## 1. TITLE
## 2. OVERALL VISUAL STYLE
## 3. AUDIO DESIGN
## 4. SCENE BREAKDOWN (5-7 scenes)

Each scene MUST follow:
### SCENE (n)
Scene Description: [brief visual explanation]

WAN 2.2 CINEMATIC PROMPT:
[One detailed paragraph — minimum 80 words — describing this scene for AI video generation]

CAMERA MOVEMENT: [describe motion]
LIGHTING STYLE: [define lighting]
EMOTIONAL INTENT: [describe progression]
ON-SCREEN TEXT: [short caption or NONE]

## 5. FINAL EMOTIONAL RESOLUTION

STRICT RULES:
- No talking, no dialogue
- Must be faceless or POV-based
- Consistent character across all scenes
- Always use bright cool-neutral lighting (NO warm tones)";

    $additional = $_SESSION['additional_info'] ?? '';
    $cta = $_SESSION['cta_text'] ?? '';
    $additional_block = $additional ? "\n\nADDITIONAL INSTRUCTIONS:\n$additional" : '';
    $cta_block = $cta ? "\n\nCTA / BRAND SIGN-OFF (last scene):\n$cta" : '';
    $input = "Business: $business\nAPPROVED STORY:\n$story" . $additional_block . $cta_block;
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $input, 0.92, 120);
    if ($response) {
        $_SESSION['script'] = $response;
        unset($_SESSION['error']);
    } else {
        $_SESSION['error'] = "Script generation failed — API timeout or key issue. Please try again.";
        error_log("[vizard step3] callAI returned null for script generation");
    }
}

// ── STEP 4: Regenerate suggestions ─────────────────────────────
if ($step == "4" && isset($_SESSION['business'])) {
    $_POST['business'] = $_SESSION['business'];
    $_POST['step'] = "1";
    $step = "1";

    $business = $_SESSION['business'];
    $systemPrompt = "You are a cinematic video strategist.
Given a business niche, generate a COMPLETELY DIFFERENT set of parameters.
Return EXACTLY this JSON:
{
  \"title\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"sentiment\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"character\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"setting\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hero_element\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"emotional_outcome\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}";
    $additional = $_SESSION['additional_info'] ?? '';
    $step4_input = "Business/Niche: $business" . ($additional ? "\nAdditional Instructions: $additional" : '');
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, $step4_input, 0.95);
    $suggestions = $raw ? json_decode($raw, true) : null;
    if ($suggestions) {
        $_SESSION['suggestions'] = $suggestions;
        unset($_SESSION['story'], $_SESSION['script'], $_SESSION['selected']);
    }
}

$suggestions = $_SESSION['suggestions'] ?? null;
$business = $_SESSION['business'] ?? '';
$story = $_SESSION['story'] ?? '';
$script = $_SESSION['script'] ?? '';
$additional_info = $_SESSION['additional_info'] ?? '';
$cta_text = $_SESSION['cta_text'] ?? '';

function paramCard($key, $label, $description, $icon, $data) {
    $selected = $data['selected'] ?? '';
    $options = $data['options'] ?? [];
    $inputId = "sel_$key";
    echo "<div class='param-card'>";
    echo "<div class='param-header'>";
    echo "<span class='param-icon'>$icon</span>";
    echo "<div style='flex:1;'>";
    echo "<div style='display:flex; justify-content:space-between;'>";
    echo "<div class='param-label'>$label</div>";
    echo "<button type='button' class='more-opts-btn' id='more_btn_$key' onclick=\"moreOptions('$key')\">+ More</button>";
    echo "</div>";
    echo "<div class='param-desc'>$description</div>";
    echo "</div></div>";
    echo "<input type='hidden' name='$inputId' id='$inputId' value='" . htmlspecialchars($selected) . "'>";
    echo "<div class='selected-val' id='display_$key'>" . htmlspecialchars($selected) . "</div>";
    echo "<div class='options-row' id='opts_$key'>";
    foreach ($options as $opt) {
        echo "<button type='button' class='opt-chip' data-key='$key' data-val='" . htmlspecialchars($opt) . "' onclick=\"selectOpt(this)\">" . htmlspecialchars($opt) . "</button>";
    }
    echo "</div>";

    // Audio mood extras: Upload + Library
    if ($key === 'audio_mood') {
        echo "<div class='audio-actions'>";
        echo "<input type='file' id='music_upload_input' accept='audio/*' style='display:none' onchange='handleMusicUpload(this)'>";
        echo "<button type='button' class='audio-btn' onclick='document.getElementById(\"music_upload_input\").click()'>⬆️ Upload</button>";
        echo "<button type='button' class='audio-btn' onclick='openMusicLibrary()'>🎵 Library</button>";
        echo "</div>";
        echo "<div id='music_player_wrap' style='display:none; margin-top:10px;'>";
        echo "<div class='music-player'>";
        echo "<span class='music-name' id='music_file_name'>—</span>";
        echo "<audio id='music_audio_player' controls style='width:100%;margin-top:6px;'></audio>";
        echo "<input type='hidden' name='selected_music' id='selected_music_path'>";
        echo "</div></div>";

        // Library modal
        echo "<div id='musicLibraryModal' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;'>";
        echo "<div style='background:#fff;border-radius:16px;padding:24px;width:90%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;gap:12px;'>";
        echo "<div style='display:flex;justify-content:space-between;align-items:center;'><strong style='font-size:15px;'>🎵 Music Library</strong><button type='button' onclick='closeMusicLibrary()' style='background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;'>✕</button></div>";
        echo "<div id='music_library_list' style='overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:8px;'><p style='color:#94a3b8;font-size:13px;'>Loading...</p></div>";
        echo "</div></div>";
    }

    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Cinematic Business Video</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;  --mid-blue: #143b63;   --accent: #5fd1ff;
  --orange: #f59e0b;     --orange-lt: #fef3c7;   --orange-dk: #d97706;
  --green: #10b981;      --green-lt: #d1fae5;
  --purple: #8b5cf6;     --purple-lt: #ede9fe;
  --red: #ef4444;
  --text: #1e293b;       --muted: #64748b;
  --border: #e2e8f0;     --bg: #f8fafc;
  --card: #ffffff;       --shadow: 0 4px 12px rgba(0,0,0,0.08);
}

/* ── Base ───────────────────────────────────────────────────── */
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

/* ── Sticky header ──────────────────────────────────────────── */
.vidora-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: linear-gradient(90deg,#0f2a44,#143b63); color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 1000; }
.brand-link  { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.brand-icon  { font-size: 24px; }
.brand-name  { font-size: 18px; font-weight: 700; }
.brand-video { color: #fff; }
.brand-vizard{ color: #5fd1ff; }
.back-link   { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.75); text-decoration: none; display: flex; align-items: center; gap: 5px; transition: color .15s; }
.back-link:hover { color: #fff; }

/* ── Page wrap ──────────────────────────────────────────────── */
.page-wrap { flex: 1; padding: 28px 16px 60px; display: flex; flex-direction: column; align-items: center; }
.container  { width: 100%; max-width: 860px; }

/* ── Page title strip (matches header dark blue) ────────────── */
.page-title-strip {
  background: linear-gradient(90deg, #0f2a44, #143b63);
  border-radius: 14px;
  padding: 20px 24px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.page-title-icon { font-size: 32px; flex-shrink: 0; }
.page-title-strip h1 { font-size: 20px; font-weight: 700; color: #fff; margin: 0 0 3px; }
.page-title-strip p  { font-size: 13px; color: rgba(255,255,255,.75); margin: 0; }


/* ── Cards ──────────────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 22px 24px; margin-bottom: 16px; box-shadow: var(--shadow); }
.card-title { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 16px; display: flex; align-items: center; gap: 6px; }
.card-title::before { content: ''; width: 3px; height: 14px; background: var(--orange); border-radius: 2px; display: inline-block; }
.badge { font-size: 10px; font-weight: 500; color: var(--muted); text-transform: none; letter-spacing: 0; background: var(--orange-lt); border-radius: 99px; padding: 2px 8px; }

/* ── Form elements ──────────────────────────────────────────── */
.field-label { display: block; font-size: 12px; font-weight: 700; color: var(--dark-blue); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .05em; }
.business-row { display: flex; gap: 10px; }
.business-input { flex: 1; background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 11px 14px; font-size: 14px; font-family: 'Inter', sans-serif; color: var(--text); outline: none; transition: border-color .15s; }
.business-input:focus { border-color: var(--orange); }
textarea.business-input { resize: vertical; }

/* ── Buttons ────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; font-family: 'Inter', sans-serif; transition: all .15s; white-space: nowrap; }
.btn-primary { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; }
.btn-primary:hover { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.btn-blue    { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; }
.btn-blue:hover { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.btn-green   { background: linear-gradient(135deg, #059669, var(--green)); color: #fff; }
.btn-green:hover { box-shadow: 0 4px 12px rgba(16,185,129,.3); }
.btn-orange  { background: linear-gradient(135deg, var(--orange-dk), var(--orange)); color: #fff; }
.btn-orange:hover { box-shadow: 0 4px 12px rgba(245,158,11,.35); }
.btn-gray    { background: var(--bg); color: var(--muted); border: 1.5px solid var(--border); }
.btn-gray:hover { border-color: var(--orange); color: var(--orange-dk); }
.btn:disabled { opacity: .55; cursor: not-allowed; box-shadow: none !important; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

/* ── Param cards (Step 2 grid) ──────────────────────────────── */
.params-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media(max-width:620px) { .params-grid { grid-template-columns: 1fr; } }
.param-card  { background: var(--bg); border: 1.5px solid var(--border); border-radius: 12px; padding: 14px; transition: border-color .15s; }
.param-card:focus-within { border-color: var(--orange); }
.param-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.param-icon  { font-size: 20px; flex-shrink: 0; }
.param-label { font-size: 11px; font-weight: 700; color: var(--dark-blue); text-transform: uppercase; letter-spacing: .06em; }
.param-desc  { font-size: 11px; color: var(--muted); margin-top: 2px; }
.selected-val { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600; margin-bottom: 10px; min-height: 36px; }
.options-row { display: flex; gap: 6px; flex-wrap: wrap; }
.opt-chip    { background: var(--card); border: 1.5px solid var(--border); padding: 5px 11px; border-radius: 99px; font-size: 11px; cursor: pointer; transition: all .15s; color: var(--text); font-family: 'Inter', sans-serif; }
.opt-chip:hover  { border-color: var(--orange); color: var(--orange-dk); background: var(--orange-lt); }
.opt-chip.picked { border-color: var(--orange); color: var(--orange-dk); background: var(--orange-lt); font-weight: 600; }
.more-opts-btn { background: var(--bg); border: 1.5px dashed var(--border); padding: 2px 10px; border-radius: 99px; font-size: 10px; font-weight: 600; cursor: pointer; color: var(--muted); font-family: 'Inter', sans-serif; transition: all .15s; }
.more-opts-btn:hover { border-color: var(--orange); color: var(--orange-dk); }

/* ── Output box ─────────────────────────────────────────────── */
.output-box { background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 18px; white-space: pre-wrap; font-size: 13px; line-height: 1.7; max-height: 600px; overflow-y: auto; margin-top: 14px; color: var(--text); }

/* ── Provider selector ──────────────────────────────────────── */
.provider-selector { background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 8px 16px; display: inline-flex; align-items: center; gap: 14px; font-size: 13px; font-weight: 600; color: var(--text); }

/* ── Progress bar (storyboard) ──────────────────────────────── */
.progress-track { background: var(--border); border-radius: 99px; height: 8px; }
.progress-fill  { height: 100%; background: linear-gradient(90deg, var(--orange-dk), var(--orange)); border-radius: 99px; transition: width .3s; }

/* ── Spinner ────────────────────────────────────────────────── */
/* ── Company card ───────────────────────────────────────────── */
.company-card {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  background: var(--card);
  border: 1px solid var(--border);
  border-left: 4px solid var(--orange);
  border-radius: 12px;
  padding: 14px 18px;
  margin-bottom: 16px;
  box-shadow: var(--shadow);
}
.company-logo {
  width: 50px; height: 50px; border-radius: 10px; flex-shrink: 0;
  background: var(--orange-lt); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 700; color: var(--orange-dk); overflow: hidden;
}
.company-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 9px; }
.company-info { flex: 1; min-width: 0; }
.company-name  { font-size: 15px; font-weight: 700; color: var(--dark-blue); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.company-brand { font-size: 11px; font-weight: 600; color: var(--orange-dk); background: var(--orange-lt); border-radius: 99px; padding: 2px 8px; display: inline-block; margin-bottom: 5px; }
.company-desc  { font-size: 12px; color: var(--muted); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.company-meta  { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 5px; }
.company-meta span { font-size: 11px; color: var(--muted); display: flex; align-items: center; gap: 3px; }
/* Switcher */
.company-switcher { position: relative; flex-shrink: 0; align-self: center; }
.company-switch-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  background: var(--orange-lt); color: var(--orange-dk);
  border: 1.5px solid #fde68a; cursor: pointer; font-family: 'Inter', sans-serif;
  transition: all .15s; white-space: nowrap;
}
.company-switch-btn:hover { background: #fde68a; }
.company-switch-btn .chevron { font-size: 9px; transition: transform .2s; }
.company-switch-btn.open .chevron { transform: rotate(180deg); }
.company-dropdown {
  display: none; position: absolute; top: calc(100% + 6px); right: 0;
  background: var(--card); border: 1px solid var(--border); border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.14); min-width: 200px; z-index: 999; overflow: hidden;
}
.company-dropdown.open { display: block; animation: coSlide .18s ease; }
@keyframes coSlide { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.co-dropdown-header { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; padding: 10px 14px 6px; }
.co-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 9px 14px; font-size: 13px; color: var(--text); text-decoration: none;
  transition: background .12s; gap: 8px;
}
.co-item:hover { background: var(--orange-lt); color: var(--orange-dk); }
.co-item.active { background: var(--orange-lt); color: var(--orange-dk); font-weight: 600; }
.co-check { color: var(--orange-dk); font-size: 12px; }

@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 5px; }
.btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Script formatted output ─────────────────────────────────── */
.script-header-block { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 18px; display: flex; flex-direction: column; gap: 8px; }
.script-meta-row { display: flex; align-items: flex-start; gap: 10px; }
.script-meta-label { font-size: 11px; font-weight: 700; color: var(--dark-blue); text-transform: uppercase; letter-spacing: .05em; min-width: 90px; padding-top: 1px; flex-shrink: 0; }
.script-meta-val { font-size: 13px; color: var(--text); }
.scene-cards-grid { display: flex; flex-direction: column; gap: 14px; margin-bottom: 18px; }
.scene-card { border: 1.5px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--card); }
.scene-card-header { background: linear-gradient(90deg, var(--dark-blue), var(--mid-blue)); padding: 10px 16px; display: flex; align-items: center; gap: 12px; }
.scene-num { font-size: 12px; font-weight: 800; color: #fff; background: rgba(255,255,255,.15); border-radius: 6px; padding: 3px 10px; white-space: nowrap; }
.scene-desc-title { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.9); }
.scene-field { padding: 10px 16px; border-bottom: 1px solid var(--border); }
.scene-field p { font-size: 13px; color: var(--text); line-height: 1.6; margin-top: 4px; }
.scene-field-inline { display: flex; align-items: baseline; gap: 8px; padding: 8px 16px; }
.scene-field-inline span:last-child { font-size: 13px; color: var(--text); }
.scene-field-label { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; min-width: 72px; flex-shrink: 0; }
.scene-caption { background: var(--orange-lt); padding: 8px 16px; font-size: 12px; font-weight: 600; color: var(--orange-dk); }
.script-resolution { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #86efac; border-radius: 10px; padding: 14px 16px; margin-bottom: 18px; }
.script-resolution p { font-size: 13px; color: #166534; line-height: 1.6; margin-top: 6px; }

/* ── Audio mood extras ───────────────────────────────────────── */
.audio-actions { display:flex; gap:8px; margin-top:10px; }
.audio-btn { flex:1; padding:7px 10px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; border:1.5px solid var(--border); background:var(--bg); color:var(--dark-blue); font-family:'Inter',sans-serif; transition:all .15s; }
.audio-btn:hover { border-color:var(--orange); color:var(--orange-dk); background:var(--orange-lt); }
.music-player { background:var(--bg); border:1.5px solid var(--border); border-radius:10px; padding:10px 12px; }
.music-name { font-size:12px; font-weight:600; color:var(--dark-blue); display:block; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lib-item { display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border:1.5px solid var(--border); border-radius:10px; background:var(--bg); gap:10px; }
.lib-item-name { font-size:13px; font-weight:500; color:var(--text); flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lib-select-btn { padding:5px 12px; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer; border:none; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; white-space:nowrap; }
</style>
</head>
<body>

<!-- ── Sticky header ───────────────────────────────────────────── -->
<header class="vidora-header">
  <a class="brand-link" href="dashboard.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
  <a class="back-link" href="vizard_scriptgen.php">← Choose Video Type</a>
</header>

<div class="page-wrap">
<div class="container">

<!-- ── Page title ──────────────────────────────────────────────── -->
<div class="page-title-strip">
  <span class="page-title-icon">🏢</span>
  <div>
    <h1>Cinematic Business Video</h1>
    <p>Type your business — AI suggests everything — you just click to customise</p>
  </div>
</div>

<?php if ($company): ?>
<!-- ── Company card ─────────────────────────────────────────────── -->
<div class="company-card">
  <!-- Logo / initial -->
  <div class="company-logo">
    <?php if ($co_logo && file_exists($co_logo)): ?>
      <img src="<?= $co_logo ?>" alt="<?= $co_name ?>">
    <?php else: ?>
      <?= mb_strtoupper(mb_substr(strip_tags($co_name), 0, 1)) ?: '🏢' ?>
    <?php endif; ?>
  </div>

  <!-- Info -->
  <div class="company-info">
    <div class="company-name"><?= $co_name ?></div>
    <?php if ($co_brand && $co_brand !== $co_name): ?>
      <span class="company-brand"><?= $co_brand ?></span>
    <?php endif; ?>
    <?php if ($co_desc): ?>
      <div class="company-desc"><?= $co_desc ?></div>
    <?php endif; ?>
    <?php if ($co_web || $co_phone || $co_addr): ?>
    <div class="company-meta">
      <?php if ($co_web):  ?><span>🌐 <?= $co_web ?></span><?php endif; ?>
      <?php if ($co_phone):?><span>📞 <?= $co_phone ?></span><?php endif; ?>
      <?php if ($co_addr): ?><span>📍 <?= $co_addr ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Company switcher (only shown when user has multiple companies) -->
  <?php if (count($all_companies) > 1): ?>
  <div class="company-switcher">
    <button class="company-switch-btn" id="coSwitchBtn" onclick="toggleCoDropdown()">
      🏢 Switch <span class="chevron">▼</span>
    </button>
    <div class="company-dropdown" id="coDropdown">
      <div class="co-dropdown-header">Switch Company</div>
      <?php foreach ($all_companies as $c): ?>
        <a href="?company_id=<?= (int)$c['id'] ?>"
           class="co-item <?= ((int)$c['id'] === $company_id) ? 'active' : '' ?>">
          🏢 <?= htmlspecialchars($c['companyname']) ?>
          <?php if ((int)$c['id'] === $company_id): ?>
            <span class="co-check">✓</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; // end company card ?>

<?php if (!$suggestions): ?>
<div class="card">
    <div class="card-title">Step 1 — Your Business</div>
    <form method="POST">
        <input type="hidden" name="step" value="1">
        <input class="business-input" type="text" name="business" value="<?= htmlspecialchars($business) ?>" placeholder="e.g. Marriage bureau for Pakistani community in Toronto" style="width:100%;margin-bottom:12px;">
        <div style="margin-top:12px;">
            <label class="field-label">💡 Additional Instructions</label>
            <textarea name="additional_info" rows="2" class="business-input" style="width:100%;margin-top:4px;"><?= htmlspecialchars($additional_info) ?></textarea>
        </div>
        <div style="margin-top:12px;">
            <label class="field-label">📣 CTA / Brand Sign-Off</label>
            <textarea name="cta_text" rows="2" class="business-input" style="width:100%;margin-top:4px;"><?= htmlspecialchars($cta_text) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:18px;justify-content:center;padding:14px;">✨ Get AI Suggestions</button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div style="background:#fef2f2; border:1.5px solid #fca5a5; border-radius:12px; padding:14px 18px; margin-bottom:16px; color:#dc2626; font-size:13px; font-weight:600;">
    ⚠️ <?= htmlspecialchars($_SESSION['error']) ?>
    <?php unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<?php if ($suggestions && $step >= 1 && !$story): ?>
<form method="POST" id="paramsForm">
    <input type="hidden" name="step" value="2">
    <div class="card">
        <div class="card-title">Step 2 — AI Suggested Parameters <span class="badge">Click any option to change</span></div>
        <div class="params-grid">
            <?php paramCard('title', 'Title', 'The name of your video', '🎬', $suggestions['title'] ?? []); ?>
            <?php paramCard('sentiment', 'Sentiment', 'Overall emotional tone', '💫', $suggestions['sentiment'] ?? []); ?>
            <?php paramCard('hook', 'Hook', 'Opening line that grabs attention', '🎯', $suggestions['hook'] ?? []); ?>
            <?php paramCard('character', 'Character', 'Who the viewer relates to', '👤', $suggestions['character'] ?? []); ?>
            <?php paramCard('setting', 'Setting', 'Where the story takes place', '🌆', $suggestions['setting'] ?? []); ?>
            <?php paramCard('audio_mood', 'Audio Mood', 'Music style and feel', '🎵', $suggestions['audio_mood'] ?? []); ?>
            <?php paramCard('hero_element', 'Hero Element', 'The key service moment', '⭐', $suggestions['hero_element'] ?? []); ?>
            <?php paramCard('emotional_outcome', 'Emotional Outcome', 'How viewer feels at the end', '❤️', $suggestions['emotional_outcome'] ?? []); ?>
        </div>
        <div class="actions">
            <button type="submit" class="btn btn-green">📖 Generate Story Concept</button>
            <button type="button" class="btn btn-orange" onclick="freshSuggestions()">🔀 More Ideas</button>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</form>
<form method="POST" id="freshForm"><input type="hidden" name="step" value="4"><input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>"><input type="hidden" name="additional_info" value="<?= htmlspecialchars($additional_info) ?>"><input type="hidden" name="cta_text" value="<?= htmlspecialchars($cta_text) ?>"></form>
<?php endif; ?>

<?php if ($story && $step >= 2 && !$script): ?>
<div class="card">
    <div class="card-title">Step 3 — Story Concept</div>
    <div class="output-box"><?= nl2br(htmlspecialchars($story)) ?></div>
    <div class="actions">
        <form method="POST"><input type="hidden" name="step" value="3"><button type="submit" class="btn btn-green">🎥 Generate Full Script</button></form>
        <form method="POST"><input type="hidden" name="step" value="2"><?php if (isset($_SESSION['selected'])) foreach ($_SESSION['selected'] as $k => $v) echo '<input type="hidden" name="sel_'.$k.'" value="'.htmlspecialchars($v).'">'; ?><button type="submit" class="btn btn-orange">🔁 Regenerate Story</button></form>
        <a href="https://videovizard.com/vizard_scriptgen_2.php?" class="btn btn-gray">🔄 Start Over</a>
    </div>
</div>
<?php endif; ?>

<?php if ($script && $step >= 3): ?>
<div class="card">
    <div class="card-title">Step 4 — Full Video Script</div>

    <?php
    // ── Parse script into structured sections ─────────────────
    $scriptText = $script;

    // Extract header sections (title, visual style, audio)
    $header_html = '';
    if (preg_match('/##\s*1\.\s*TITLE[^\n]*\n(.*?)(?=##\s*2\.)/si', $scriptText, $m))
        $header_html .= '<div class="script-meta-row"><span class="script-meta-label">🎬 Title</span><span class="script-meta-val">' . htmlspecialchars(trim($m[1])) . '</span></div>';
    if (preg_match('/##\s*2\.\s*OVERALL VISUAL STYLE[^\n]*\n(.*?)(?=##\s*3\.)/si', $scriptText, $m))
        $header_html .= '<div class="script-meta-row"><span class="script-meta-label">🎨 Visual Style</span><span class="script-meta-val">' . htmlspecialchars(trim($m[1])) . '</span></div>';
    if (preg_match('/##\s*3\.\s*AUDIO DESIGN[^\n]*\n(.*?)(?=##\s*4\.)/si', $scriptText, $m))
        $header_html .= '<div class="script-meta-row"><span class="script-meta-label">🎵 Audio</span><span class="script-meta-val">' . htmlspecialchars(trim($m[1])) . '</span></div>';

    if ($header_html) echo '<div class="script-header-block">' . $header_html . '</div>';

    // Extract scenes
    $scene_re = '/(?:#{1,3}\s*)?(?:\*{1,2})?\bSCENE\s+(\d+)\b(?:\*{1,2})?[^\n]*/i';
    preg_match_all($scene_re, $scriptText, $scene_matches, PREG_OFFSET_CAPTURE);
    $markers = $scene_matches[0];

    if ($markers) {
        echo '<div class="scene-cards-grid">';
        for ($i = 0; $i < count($markers); $i++) {
            $start  = $markers[$i][1] + strlen($markers[$i][0]);
            $end    = ($i + 1 < count($markers)) ? $markers[$i+1][1] : strlen($scriptText);
            $block  = substr($scriptText, $start, $end - $start);
            $num    = $scene_matches[1][$i][0];

            // Extract fields
            $wan = '';
            if (preg_match('/WAN\s*2\.2[\s\S]*?PROMPT\s*[:\n]([\s\S]+?)(?=CAMERA|LIGHTING|EMOTIONAL|ON.?SCREEN|##|$)/i', $block, $wm))
                $wan = trim(preg_replace('/\n+/', ' ', $wm[1]));

            $camera = '';
            if (preg_match('/CAMERA\s*MOVEMENT\s*[:\-]?\s*([^\n]+)/i', $block, $cm)) $camera = trim($cm[1]);

            $lighting = '';
            if (preg_match('/LIGHTING\s*STYLE\s*[:\-]?\s*([^\n]+)/i', $block, $lm)) $lighting = trim($lm[1]);

            $emotion = '';
            if (preg_match('/EMOTIONAL\s*INTENT\s*[:\-]?\s*([^\n]+)/i', $block, $em)) $emotion = trim($em[1]);

            $caption = '';
            if (preg_match('/ON[.\s-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*([^\n]+)/i', $block, $capm))
                $caption = trim(preg_replace('/^(NONE|none|-|N\/A)$/i', '', $capm[1]));

            $desc = '';
            if (preg_match('/Scene\s*Description\s*[:\-]?\s*([^\n]+)/i', $block, $dm)) $desc = trim($dm[1]);

            echo '<div class="scene-card">';
            echo '<div class="scene-card-header"><span class="scene-num">Scene ' . $num . '</span>' . ($desc ? '<span class="scene-desc-title">' . htmlspecialchars($desc) . '</span>' : '') . '</div>';
            if ($wan)      echo '<div class="scene-field"><span class="scene-field-label">🎬 Prompt</span><p>' . htmlspecialchars($wan) . '</p></div>';
            if ($camera)   echo '<div class="scene-field scene-field-inline"><span class="scene-field-label">📷 Camera</span><span>' . htmlspecialchars($camera) . '</span></div>';
            if ($lighting) echo '<div class="scene-field scene-field-inline"><span class="scene-field-label">💡 Lighting</span><span>' . htmlspecialchars($lighting) . '</span></div>';
            if ($emotion)  echo '<div class="scene-field scene-field-inline"><span class="scene-field-label">❤️ Emotion</span><span>' . htmlspecialchars($emotion) . '</span></div>';
            if ($caption)  echo '<div class="scene-caption">💬 ' . htmlspecialchars($caption) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        // Fallback: plain text
        echo '<div class="output-box" id="scriptBox">' . nl2br(htmlspecialchars($script)) . '</div>';
    }

    // Final resolution
    if (preg_match('/##\s*5\.\s*FINAL EMOTIONAL RESOLUTION[^\n]*\n([\s\S]+?)$/i', $scriptText, $fm))
        echo '<div class="script-resolution"><span class="script-meta-label">🌟 Final Resolution</span><p>' . htmlspecialchars(trim($fm[1])) . '</p></div>';
    ?>

    <!-- ── Credits notice ─────────────────────────────────────── -->
    <?php if (!$has_enough_credits): ?>
    <div style="background:linear-gradient(135deg,#fef2f2,#fee2e2); border:1.5px solid #fca5a5; border-radius:12px; padding:14px 18px; margin-top:18px; display:flex; align-items:center; gap:12px;">
        <span style="font-size:24px; flex-shrink:0;">🚫</span>
        <div>
            <div style="font-size:13px; font-weight:700; color:#991b1b;">Insufficient Credits</div>
            <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                You need <strong><?= $credits_required ?> credits</strong> to generate this video but only have <strong><?= $user_credit_balance ?></strong>.
                <a href="/upgrade.php" style="color:#dc2626; font-weight:700; text-decoration:underline;">Upgrade your plan →</a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7); border:1.5px solid #fde68a; border-radius:12px; padding:14px 18px; margin-top:18px; display:flex; align-items:center; gap:12px;">
        <span style="font-size:24px; flex-shrink:0;">💳</span>
        <div>
            <div style="font-size:13px; font-weight:700; color:#92400e;">This video will use <?= $credits_required ?> credits. <span style="font-weight:500;">(You have <?= $user_credit_balance ?>)</span></div>
            <div style="font-size:12px; color:#b45309; margin-top:3px;">Image generation happens immediately. Video rendering runs in the background — you can close this page and check back later.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="actions" style="justify-content:space-between; flex-wrap:wrap; margin-top:20px;">
        <div class="provider-selector">
            <span>🎨 Image Provider:</span>
            <label><input type="radio" name="image_provider" value="auto" checked> Auto (FLUX→OpenAI)</label>
            <span style="font-size:10px; color:var(--orange);">FLUX first, fallback to OpenAI</span>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-green" onclick="generateAllScenes()" id="btnGenScenes" <?= !$has_enough_credits ? 'disabled title="Not enough credits"' : '' ?>>🎬 Generate Scene Images</button>
            <button type="button" class="btn btn-gray" onclick="warmupModalManually()" title="Costs 1 Modal GPU call — only use if container is cold">🔥 Pre-warm Modal</button>
            <button type="button" class="btn btn-gray" onclick="testModalRaw()" id="btnTestModal">🧪 Test Modal</button>
            <a href="https://videovizard.com/vizard_scriptgen_2.php?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</div>
<div id="storyboardSection" style="display:none; margin-top:20px;">
    <div class="card">
        <div class="card-title">🎞️ Scene Storyboard</div>
        <!-- ── Storyboard info notice ─────────────────────────── -->
        <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1.5px solid #93c5fd; border-radius:10px; padding:12px 16px; margin-bottom:14px; display:flex; align-items:center; gap:10px;">
            <span style="font-size:20px; flex-shrink:0;">ℹ️</span>
            <div style="font-size:12px; color:#1e40af; line-height:1.5;">
                <strong>How it works:</strong> Scene images will be generated and shown here first. Once all images are ready, video clips will be created from them automatically in the background.
            </div>
        </div>
        <div id="sceneProgress" style="display:none; margin-bottom:14px;">
            <div class="progress-track"><div id="sceneProgressBar" class="progress-fill" style="width:0%;"></div></div>
            <div style="font-size:11px; margin-top:6px; color:var(--muted);" id="sceneProgressLabel">Generating scenes...</div>
        </div>
        <div id="storyboardGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:16px;"></div>
    </div>
</div>
<script>const FULL_SCRIPT = <?= json_encode($script) ?>;</script>
<?php endif; ?>
</div><!-- /.container -->
</div><!-- /.page-wrap -->

<script>
function btnLoading(btn, text) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span>' + text; }
function btnReset(btn) { btn.disabled = false; btn.innerHTML = btn.dataset.orig || btn.innerHTML; }
function selectOpt(btn) { const key = btn.dataset.key, val = btn.dataset.val; document.getElementById('sel_' + key).value = val; document.getElementById('display_' + key).textContent = val; document.querySelectorAll('#opts_' + key + ' .opt-chip').forEach(c => c.classList.toggle('picked', c === btn)); }
function freshSuggestions() { const btn = document.querySelector('[onclick="freshSuggestions()"]'); if (btn) btnLoading(btn, 'Getting suggestions…'); document.getElementById('freshForm').submit(); }
async function moreOptions(key) {
    const moreBtn = document.getElementById('more_btn_' + key), optsRow = document.getElementById('opts_' + key);
    if (!moreBtn || !optsRow) return;
    const existing = Array.from(optsRow.querySelectorAll('.opt-chip')).map(c => c.textContent.trim()).join(' | ');
    moreBtn.disabled = true; moreBtn.textContent = '…';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'more_options'); fd.append('field', key); fd.append('business', document.querySelector('[name=business]')?.value || '');
        fd.append('existing', existing); fd.append('additional', document.querySelector('[name=additional_info]')?.value || '');
        const response = await fetch('', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success && data.options) {
            data.options.forEach(opt => {
                if (Array.from(optsRow.querySelectorAll('.opt-chip')).some(c => c.textContent.trim().toLowerCase() === opt.toLowerCase())) return;
                const chip = document.createElement('button'); chip.type = 'button'; chip.className = 'opt-chip'; chip.dataset.key = key; chip.dataset.val = opt; chip.textContent = opt; chip.onclick = function() { selectOpt(this); }; optsRow.appendChild(chip);
            });
            moreBtn.textContent = '+ More';
        } else { moreBtn.textContent = '+ More'; }
    } catch(e) { moreBtn.textContent = '+ More'; }
    moreBtn.disabled = false;
}
function debugScript() { if (typeof FULL_SCRIPT === 'undefined') { alert('No script found'); return; } const scenes = parseScenes(FULL_SCRIPT); alert('Found ' + scenes.length + ' scenes'); }
function copyScript() { const box = document.getElementById('scriptBox'); if (!box) return; navigator.clipboard.writeText(box.innerText).then(() => { const btn = event.currentTarget; const orig = btn.innerHTML; btn.innerHTML = '✅ Copied!'; setTimeout(() => btn.innerHTML = orig, 2000); }); }
function parseScenes(scriptText) {
    const scenes = [], markers = [];
    const re = /(?:#{1,3}\s*)?(?:\*{1,2})?\bSCENE\s+(\d+)\b(?:\*{1,2})?[^\n]*/gi;
    let m; while ((m = re.exec(scriptText)) !== null) markers.push({ index: m.index, num: parseInt(m[1]), header: m[0] });
    if (!markers.length) return scenes;
    for (let i = 0; i < markers.length; i++) {
        const start = markers[i].index + markers[i].header.length, end = i + 1 < markers.length ? markers[i+1].index : scriptText.length, block = scriptText.substring(start, end), num = markers[i].num;
        let wanPrompt = '';
        const wanRe = /WAN\s*2\.2[\s\S]*?PROMPT\s*[:\n]([\s\S]+?)(?=CAMERA|LIGHTING|EMOTIONAL|ON.?SCREEN|##|$)/i;
        let wm = block.match(wanRe);
        if (wm && wm[1].trim().length > 15) wanPrompt = wm[1].trim().replace(/\n+/g, ' ').substring(0, 1200);
        if (!wanPrompt || wanPrompt.length < 20) wanPrompt = block.replace(/#{1,3}|\*{1,2}/g, '').replace(/\n+/g, ' ').trim().substring(0, 800);
        let caption = '';
        const capRe = /ON[.\s-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*([^\n]+)/i, capM = block.match(capRe);
        if (capM) caption = capM[1].trim().replace(/^(NONE|none|-|N\/A)$/i, '').trim();
        let title = markers[i].header.replace(/#{1,3}|\*{1,2}|\bSCENE\s*\d+\b/gi,'').replace(/[—\-–:]/g,'').trim();
        if (!title) title = block.trim().split('\n')[0].substring(0,60) || ('Scene ' + num);
        scenes.push({ num, title, wan: wanPrompt, caption });
    }
    return scenes;
}
async function generateAllScenes() {
    if (typeof FULL_SCRIPT === 'undefined') return;
    const scenes = parseScenes(FULL_SCRIPT);
    if (!scenes.length) { alert('No scenes found in script.'); return; }

    const btn = document.getElementById('btnGenScenes');
    btnLoading(btn, 'Processing…');

    // ── Step 1: Deduct 6 credits ──────────────────────────────
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'deduct_cinematic_credits');
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        if (!data.success) {
            btnReset(btn);
            alert('Could not deduct credits: ' + (data.message || 'Unknown error'));
            return;
        }
    } catch(e) {
        btnReset(btn);
        alert('Credit deduction error: ' + e.message);
        return;
    }

    // ── Step 2: Show storyboard + placeholders IMMEDIATELY ────
    // Must paint before any await — use double rAF to force browser repaint
    document.getElementById('storyboardSection').style.display = 'block';
    document.getElementById('sceneProgress').style.display = 'block';
    const grid = document.getElementById('storyboardGrid');
    grid.innerHTML = '';
    scenes.forEach(sc => {
        const card = document.createElement('div');
        card.id = 'scene-card-' + sc.num;
        card.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);';
        card.innerHTML = `
            <div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f8fafc,#e2e8f0);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;gap:8px;">
                <div style="width:32px;height:32px;border:3px solid #e2e8f0;border-top-color:#0f2a44;border-radius:50%;animation:spin .8s linear infinite;"></div>
                <div style="font-size:12px;font-weight:700;color:#0f2a44;text-align:center;">Scene ${sc.num}</div>
                <div id="scene-status-${sc.num}" style="font-size:10px;color:#94a3b8;text-align:center;">Waiting to start…</div>
            </div>
            <div style="padding:8px 10px;">
                <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sc.num}</div>
                <div style="font-size:10px;color:#64748b;margin-top:2px;">${(sc.caption||sc.title||'').substring(0,45)}</div>
            </div>`;
        grid.appendChild(card);
    });
    document.getElementById('sceneProgressBar').style.width = '2%';
    document.getElementById('sceneProgressLabel').textContent = 'Starting up…';

    // Force the browser to actually paint the cards before we block on fetch
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    // Small yield so browser commits the paint
    await new Promise(r => setTimeout(r, 50));

    // ── Step 3: No warmup call — Modal queues requests natively ─
    // Warmup image costs the same as a real image on A100.
    // Modal will cold-start on the first scene request if needed.
    // The DB save step below gives ~2-3s for Modal to start booting.
    scenes.forEach(sc => {
        const st = document.getElementById('scene-status-' + sc.num);
        if (st) st.textContent = 'Queued…';
    });
    document.getElementById('sceneProgressLabel').textContent = `Creating DB record then generating ${scenes.length} scenes…`;
    document.getElementById('sceneProgressBar').style.width = '5%';
    const warmupPromise = Promise.resolve(); // no-op, kept so await warmupPromise below still works

    // ── Step 4: Save podcast shell to DB FIRST ────────────────
    // We need podcast_id + story_id per scene BEFORE generating,
    // so the generate_scene_image handler can UPDATE image_file immediately.
    document.getElementById('sceneProgressLabel').textContent = 'Creating podcast record in DB…';
    document.getElementById('sceneProgressBar').style.width = '5%';

    let podcastId  = 0;
    let storyIdMap = {};  // { scene_num: story_id }

    try {
        let titleVal = '';
        if (typeof FULL_SCRIPT !== 'undefined') {
            const tm = FULL_SCRIPT.match(/##\s*1\.\s*TITLE[^\n]*\n([^\n]+)/i);
            if (tm) titleVal = tm[1].trim();
        }
        const businessVal = document.querySelector('[name=business]')?.value || '';
        const shellScenes = scenes.map(sc => ({ num: sc.num, wan: sc.wan, caption: sc.caption, file: '' }));
        const fd0  = new FormData();
        fd0.append('ajax_action', 'save_cinematic_to_db');
        fd0.append('scenes',   JSON.stringify(shellScenes));
        fd0.append('title',    titleVal);
        fd0.append('business', businessVal);
        const r0 = await fetch('', { method:'POST', body:fd0 });
        const d0 = await r0.json();
        if (d0.success) {
            podcastId  = d0.podcast_id   || 0;
            storyIdMap = d0.story_id_map || {};
            document.getElementById('sceneProgressLabel').textContent =
                `Podcast #${podcastId} created — now generating ${scenes.length} scenes…`;
        } else {
            document.getElementById('sceneProgressLabel').textContent =
                '⚠️ DB record failed (' + (d0.message||'unknown') + ') — generating without DB link';
        }
    } catch(e) {
        document.getElementById('sceneProgressLabel').textContent =
            '⚠️ DB error: ' + e.message + ' — generating anyway';
    }

    // ── Step 5: Fire all scenes staggered + parallel ──────────
    const generatedScenes = [];
    let completed    = 0;
    let successCount = 0;

    function setSceneStatus(num, text) {
        const el = document.getElementById('scene-status-' + num);
        if (el) el.textContent = text;
    }

    function updateProgress() {
        const pct = 5 + Math.round((completed / scenes.length) * 85);
        document.getElementById('sceneProgressBar').style.width = pct + '%';
        document.getElementById('sceneProgressLabel').textContent =
            `Generating… ${completed}/${scenes.length} done  ✅ ${successCount}  ❌ ${completed - successCount}`;
    }

    function renderCard(sc, data) {
        const card = document.getElementById('scene-card-' + sc.num);
        if (!card) return;
        if (data && data.success) {
            const isOpenAI = (data.source || '').includes('OpenAI');
            const dbIcon   = data.db_updated ? '💾' : '⚠️ no DB';
            card.innerHTML = `
                <div style="position:relative;aspect-ratio:9/16;">
                    <img src="${data.file}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                    <div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;
                        font-size:10px;font-weight:700;letter-spacing:.02em;
                        background:${isOpenAI ? 'rgba(220,38,38,.88)' : 'rgba(5,150,105,.88)'};color:#fff;">
                        ${isOpenAI ? '💸 OpenAI' : '⚡ FLUX'}
                    </div>
                    <div style="position:absolute;bottom:4px;right:5px;background:rgba(0,0,0,.65);color:#fff;font-size:9px;padding:2px 6px;border-radius:4px;">${data.elapsed ?? '?'}s</div>
                    <div style="position:absolute;bottom:4px;left:5px;font-size:9px;background:rgba(0,0,0,.55);color:#fff;padding:2px 5px;border-radius:4px;">${dbIcon}</div>
                </div>
                <div style="padding:8px 10px;">
                    <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sc.num}</div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">${(sc.caption||sc.title||'').substring(0,45)}</div>
                </div>`;
            generatedScenes.push({ num:sc.num, wan:sc.wan, caption:sc.caption, file:data.file, source:data.source||'FLUX/Modal' });
        } else {
            // Show exact failure stage + reason
            const stage   = data?.fail_stage || 'unknown';
            const reason  = data?.error       || 'Unknown error';
            const storyId = storyIdMap[String(sc.num)] || storyIdMap[sc.num] || 0;
            card.innerHTML = `
                <div style="aspect-ratio:9/16;display:flex;flex-direction:column;align-items:center;justify-content:center;
                    background:#fef2f2;gap:6px;padding:14px;text-align:center;">
                    <span style="font-size:26px;">❌</span>
                    <div style="font-size:11px;font-weight:700;color:#dc2626;">Scene ${sc.num} failed</div>
                    <div style="font-size:10px;color:#7f1d1d;background:#fee2e2;padding:4px 8px;border-radius:6px;width:100%;word-break:break-word;">
                        <strong>Stage:</strong> ${stage}<br>
                        ${reason.substring(0,90)}
                    </div>
                    <button onclick="retryScene(${sc.num},'${encodeURIComponent(sc.wan)}','${encodeURIComponent(sc.caption||'')}','${encodeURIComponent(sc.title||'')}',${podcastId},${storyId})"
                        style="padding:6px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;margin-top:2px;">
                        🔁 Retry
                    </button>
                </div>`;
        }
    }

    async function genScene(sc) {
        setSceneStatus(sc.num, 'Generating…');
        const fd = new FormData();
        fd.append('ajax_action', 'generate_scene_image');
        fd.append('wan_prompt',  sc.wan);
        fd.append('caption',     sc.caption || '');
        fd.append('scene_num',   sc.num);
        fd.append('podcast_id',  podcastId);
        fd.append('story_id',    storyIdMap[String(sc.num)] || storyIdMap[sc.num] || 0);
        try {
            const resp = await fetch('', { method:'POST', body:fd });
            const data = await resp.json();
            renderCard(sc, data);
            completed++;
            if (data.success) successCount++;
            updateProgress();
            return data.success;
        } catch(e) {
            renderCard(sc, { success:false, error: e.message, fail_stage: 'network' });
            completed++;
            updateProgress();
            return false;
        }
    }

    // Modal has 2 GPU containers — it queues requests natively.
    // Use a tiny 500ms stagger just to avoid hitting the web_api HTTP
    // layer (also 2 containers) with all requests in the same millisecond.
    const STAGGER_MS = 500;
    await Promise.all(
        scenes.map((sc, idx) =>
            new Promise(r => setTimeout(r, idx * STAGGER_MS)).then(() => genScene(sc))
        )
    );

    // Wait for warmup promise to finish cleanly
    await warmupPromise;

    // ── Step 6: Final summary ─────────────────────────────────
    document.getElementById('sceneProgressBar').style.width = '100%';
    const failCount  = scenes.length - successCount;
    const editorLink = podcastId
        ? ` <a href="podcast_edit.php?id=${podcastId}" style="color:var(--orange-dk);font-weight:700;text-decoration:none;">Open in Editor →</a>`
        : '';
    document.getElementById('sceneProgressLabel').innerHTML =
        `Done! ${successCount}/${scenes.length} images generated` +
        (failCount > 0 ? ` — <strong style="color:#dc2626;">${failCount} failed</strong> (click 🔁 on failed cards)` : ' ✅') +
        editorLink;

    btn.disabled = false;
    btn.innerHTML = successCount > 0 ? '🔁 Regenerate All' : '🔁 Retry All';
}
async function saveScenesToDB(sceneData, parsedScenes) {
    // Show saving indicator
    const label = document.getElementById('sceneProgressLabel');
    if (label) label.textContent = '💾 Saving to database…';

    // Resolve title from parsed script
    let titleVal = '';
    if (typeof FULL_SCRIPT !== 'undefined') {
        const tm = FULL_SCRIPT.match(/##\s*1\.\s*TITLE[^\n]*\n([^\n]+)/i);
        if (tm) titleVal = tm[1].trim();
    }
    const businessVal = document.querySelector('[name=business]')?.value || '';

    const fd = new FormData();
    fd.append('ajax_action', 'save_cinematic_to_db');
    fd.append('scenes', JSON.stringify(sceneData));
    fd.append('title', titleVal);
    fd.append('business', businessVal);

    try {
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        if (data.success) {
            if (label) label.innerHTML = `✅ Saved! <strong>${data.scene_count} scenes</strong> stored. <a href="podcast_edit.php?id=${data.podcast_id}" style="color:var(--orange-dk);font-weight:700;">Open in Editor →</a>`;
        } else {
            if (label) label.textContent = '⚠️ DB save failed: ' + (data.message || 'unknown error');
        }
    } catch(e) {
        if (label) label.textContent = '⚠️ DB save error: ' + e.message;
    }
}
async function retryScene(sceneNum, encodedWan, encodedCaption, encodedTitle, podcastId = 0, storyId = 0) {
    const wan     = decodeURIComponent(encodedWan);
    const caption = decodeURIComponent(encodedCaption);
    const title   = decodeURIComponent(encodedTitle);
    const card    = document.getElementById('scene-card-' + sceneNum);
    if (!card) return;

    card.innerHTML = `
        <div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f8fafc,#e2e8f0);display:flex;flex-direction:column;
            align-items:center;justify-content:center;padding:16px;gap:8px;">
            <div style="width:32px;height:32px;border:3px solid #e2e8f0;border-top-color:#0f2a44;border-radius:50%;animation:spin .8s linear infinite;"></div>
            <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sceneNum}</div>
            <div style="font-size:10px;color:#94a3b8;">Retrying…</div>
        </div>
        <div style="padding:8px 10px;"><div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sceneNum}</div></div>`;

    const fd = new FormData();
    fd.append('ajax_action', 'generate_scene_image');
    fd.append('wan_prompt',  wan);
    fd.append('caption',     caption);
    fd.append('scene_num',   sceneNum);
    fd.append('podcast_id',  podcastId);
    fd.append('story_id',    storyId);
    try {
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        if (data && data.success) {
            const isOpenAI = (data.source || '').includes('OpenAI');
            const dbIcon   = data.db_updated ? '💾' : '⚠️ no DB';
            card.innerHTML = `
                <div style="position:relative;aspect-ratio:9/16;">
                    <img src="${data.file}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                    <div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;
                        font-size:10px;font-weight:700;
                        background:${isOpenAI ? 'rgba(220,38,38,.88)' : 'rgba(5,150,105,.88)'};color:#fff;">
                        ${isOpenAI ? '💸 OpenAI' : '⚡ FLUX'}
                    </div>
                    <div style="position:absolute;bottom:4px;right:5px;background:rgba(0,0,0,.65);color:#fff;font-size:9px;padding:2px 6px;border-radius:4px;">${data.elapsed ?? '?'}s</div>
                    <div style="position:absolute;bottom:4px;left:5px;font-size:9px;background:rgba(0,0,0,.55);color:#fff;padding:2px 5px;border-radius:4px;">${dbIcon}</div>
                </div>
                <div style="padding:8px 10px;">
                    <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sceneNum} ✅ Retry OK</div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">${caption.substring(0,45)}</div>
                </div>`;
        } else {
            const stage  = data?.fail_stage || 'unknown';
            const reason = data?.error || 'Generation error';
            card.innerHTML = `
                <div style="aspect-ratio:9/16;display:flex;flex-direction:column;align-items:center;justify-content:center;
                    background:#fef2f2;gap:6px;padding:14px;text-align:center;">
                    <span style="font-size:26px;">❌</span>
                    <div style="font-size:11px;font-weight:700;color:#dc2626;">Scene ${sceneNum} failed again</div>
                    <div style="font-size:10px;color:#7f1d1d;background:#fee2e2;padding:4px 8px;border-radius:6px;width:100%;word-break:break-word;">
                        <strong>Stage:</strong> ${stage}<br>${reason.substring(0,90)}
                    </div>
                    <button onclick="retryScene(${sceneNum},'${encodedWan}','${encodedCaption}','${encodedTitle}',${podcastId},${storyId})"
                        style="padding:6px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;margin-top:2px;">
                        🔁 Retry again
                    </button>
                </div>`;
        }
    } catch(e) {
        card.innerHTML = `
            <div style="aspect-ratio:9/16;display:flex;flex-direction:column;align-items:center;justify-content:center;
                background:#fef2f2;gap:6px;padding:14px;text-align:center;">
                <span style="font-size:26px;">❌</span>
                <div style="font-size:10px;color:#dc2626;"><strong>Stage:</strong> network<br>${e.message.substring(0,80)}</div>
                <button onclick="retryScene(${sceneNum},'${encodedWan}','${encodedCaption}','${encodedTitle}',${podcastId},${storyId})"
                    style="padding:6px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;margin-top:2px;">
                    🔁 Retry again
                </button>
            </div>`;
    }
}
async function warmupModalManually() {
    const btn = event.currentTarget; const orig = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span> Warming...'; btn.disabled = true;
    try { const resp = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ajax_action=warmup_modal' }); const data = await resp.json(); alert(data.message || (data.success ? 'Modal ready!' : 'Warmup attempted')); } catch(e) { alert('Warmup error'); }
    finally { btn.innerHTML = orig; btn.disabled = false; }
}
async function testModalRaw() {
    const btn = document.getElementById('btnTestModal');
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Testing…';
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'test_modal_raw');
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();

        const ok    = data.has_image;
        const color = ok ? '#166534' : '#991b1b';
        const bg    = ok ? '#dcfce7' : '#fee2e2';
        const msg   = [
            `<strong>Verdict:</strong> ${data.verdict}`,
            `<strong>URL:</strong> ${data.url}`,
            `<strong>HTTP:</strong> ${data.http_code} | <strong>cURL err:</strong> ${data.curl_errno || 'none'}`,
            `<strong>Time:</strong> ${data.elapsed_s}s`,
            `<strong>Response keys:</strong> ${data.response_keys ? data.response_keys.join(', ') : 'none'}`,
            `<strong>Body preview:</strong> ${data.body_preview}`,
        ].join('<br>');

        // Show result inline below the button row
        let box = document.getElementById('modalTestResult');
        if (!box) {
            box = document.createElement('div');
            box.id = 'modalTestResult';
            btn.closest('.actions').after(box);
        }
        box.style.cssText = `background:${bg};border:1.5px solid ${ok?'#86efac':'#fca5a5'};border-radius:10px;padding:12px 16px;margin-top:10px;font-size:12px;color:${color};line-height:1.8;`;
        box.innerHTML = msg;
    } catch(e) {
        alert('Test error: ' + e.message);
    } finally {
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}
async function saveMovieToDB() { if (typeof saveScenesToDB === 'function') saveScenesToDB([], []); }

// ── Music Upload ───────────────────────────────────────────────
async function handleMusicUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('ajax_action', 'upload_music');
    fd.append('music_file', file);
    const btn = document.querySelector('.audio-btn');
    const origText = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<span class="spinner"></span>Uploading…'; btn.disabled = true; }
    try {
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            setMusicPlayer(data.file, data.name);
        } else {
            alert('Upload failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) { alert('Upload error'); }
    finally { if (btn) { btn.innerHTML = origText; btn.disabled = false; } }
}

function setMusicPlayer(filePath, fileName) {
    document.getElementById('selected_music_path').value = filePath;
    document.getElementById('music_file_name').textContent = fileName;
    const audio = document.getElementById('music_audio_player');
    audio.src = filePath;
    document.getElementById('music_player_wrap').style.display = 'block';
    closeMusicLibrary();
}

// ── Music Library ──────────────────────────────────────────────
async function openMusicLibrary() {
    const modal = document.getElementById('musicLibraryModal');
    modal.style.display = 'flex';
    const list = document.getElementById('music_library_list');
    list.innerHTML = '<p style="color:#94a3b8;font-size:13px;">Loading…</p>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'list_music_library');
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success && data.files.length > 0) {
            list.innerHTML = data.files.map(f => `
                <div class="lib-item">
                    <span class="lib-item-name">🎵 ${f.name}</span>
                    <audio controls style="height:28px;flex-shrink:0;"></audio>
                    <button type="button" class="lib-select-btn" onclick="setMusicPlayer('${f.file}','${f.name}')">Select</button>
                </div>
            `).join('');
            // Wire up audio previews
            list.querySelectorAll('.lib-item').forEach((item, i) => {
                item.querySelector('audio').src = data.files[i].file;
            });
        } else {
            list.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No music files found in library.</p>';
        }
    } catch(e) { list.innerHTML = '<p style="color:#ef4444;font-size:13px;">Could not load library.</p>'; }
}

function closeMusicLibrary() {
    document.getElementById('musicLibraryModal').style.display = 'none';
}
document.querySelectorAll('form button[type=submit]').forEach(btn => { btn.addEventListener('click', function() { setTimeout(() => btnLoading(this, 'Processing…'), 10); }); });
// Auto-warmup removed — was firing on every page load, wasting Modal GPU credits.
// Warmup now only fires when user clicks Generate Scene Images.


// ── Company dropdown ──────────────────────────────────────────
function toggleCoDropdown() {
    const dd  = document.getElementById('coDropdown');
    const btn = document.getElementById('coSwitchBtn');
    if (!dd) return;
    dd.classList.toggle('open');
    btn.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const sw = document.querySelector('.company-switcher');
    if (sw && !sw.contains(e.target)) {
        document.getElementById('coDropdown')?.classList.remove('open');
        document.getElementById('coSwitchBtn')?.classList.remove('open');
    }
});
</script>
</body>
</html>
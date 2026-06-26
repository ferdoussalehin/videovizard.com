<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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


$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$plan_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1"));
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// ── Resolve credit balance (team member → use team lead's balance) ─────
$_user_row       = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$_credit_user_id = (!empty($_user_row) && trim((string)($_user_row['role'] ?? '')) === 'Team Member' && (int)($_user_row['team_lead_id'] ?? 0) > 0)
    ? (int)$_user_row['team_lead_id'] : $admin_id;
if ($_credit_user_id !== $admin_id) {
    $_lead_row           = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credit_balance FROM hdb_users WHERE id=$_credit_user_id LIMIT 1"));
    $user_credit_balance = (int)($_lead_row['credit_balance'] ?? 0);
} else {
    $user_credit_balance = (int)($_user_row['credit_balance'] ?? 0);
}
$credits_required   = 6;
$has_enough_credits = ($user_credit_balance >= $credits_required);

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

include 'config.php';

// Support multiple common API key variable names
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

if (!$apiKey) {
    error_log("[product_movie_gen] WARNING: No API key found in config.php");
}

$apiUrl = "https://api.openai.com/v1/chat/completions";
$response = "";
$step = $_POST['step'] ?? $_GET['step'] ?? "0";

// === LOGGING — microsecond timestamps, writes to both log files ===
function logGeneration($message, $type = 'INFO') {
    $ts    = date('Y-m-d H:i:s') . '.' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));
    $entry = "[$ts] [$type] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/product_movie_gen.log', $entry, FILE_APPEND | LOCK_EX);
    file_put_contents(__DIR__ . '/a_errors_log',          $entry, FILE_APPEND | LOCK_EX);
}

function logTimed($message, $type, $ref_time) {
    $elapsed = round(microtime(true) - $ref_time, 3);
    logGeneration("(+{$elapsed}s) $message", $type);
}

// === PRODUCT IMAGE HELPER =====================================
// Returns base64-encoded product image from session if available
function getProductImageBase64() {
    $path = $_SESSION['product_image_path'] ?? null;
    if ($path && file_exists($path)) {
        $data = file_get_contents($path);
        if ($data) return base64_encode($data);
    }
    return null;
}

function getProductImageMime() {
    $path = $_SESSION['product_image_path'] ?? null;
    if (!$path) return 'image/jpeg';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    return $map[$ext] ?? 'image/jpeg';
}

// === MODAL/FLUX CONFIGURATION =================================
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

// ── Warmup: sends a real tiny inference — HEAD requests do nothing on Modal ──
function warmupModal() {
    global $MODAL_URL;
    $t0 = microtime(true);
    logGeneration("WARMUP: Sending real inference warmup (tiny 128×128 image)...", "WARMUP");
    $payload = json_encode(['prompt' => 'plain white background, minimal', 'style' => 'cinematic', 'width' => 128, 'height' => 128]);
    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
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

function generateWithFlux($prompt, $maxRetries = 2) {
    global $MODAL_URL;
    $t_func   = microtime(true);
    $deadline = $t_func + 330; // stop retrying 20s before PHP timeout (350s)
    logGeneration("FLUX: Starting | prompt_len=" . strlen($prompt), "FLUX");

    $payload = json_encode(['prompt' => $prompt, 'style' => 'cinematic', 'width' => 768, 'height' => 1344]);
    $attempt = 0;

    while (microtime(true) < $deadline) {
        $attempt++;
        $t_att       = microtime(true);
        $time_left   = max(10, (int)($deadline - $t_att));
        logGeneration("FLUX: Attempt $attempt | time_left={$time_left}s — firing request...", "FLUX");

        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $time_left,  // dynamic — never exceeds deadline
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $res       = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $curlErrno = curl_errno($ch);
        $curlErrStr= curl_error($ch);
        curl_close($ch);

        logTimed(
            "FLUX: Attempt $attempt done | HTTP=$httpCode curlErr=$curlErrno curlTime={$totalTime}s resp_len=" . strlen((string)$res),
            "FLUX", $t_att
        );

        // ── Success ───────────────────────────────────────────
        if ($curlErrno === 0 && $httpCode === 200 && $res) {
            $data = json_decode($res, true);
            if (!empty($data['image'])) {
                logTimed("FLUX: SUCCESS attempt=$attempt", "FLUX_SUCCESS", $t_func);
                return ['success' => true, 'image' => $data['image'], 'source' => 'FLUX/Modal'];
            }
            logGeneration("FLUX: HTTP 200 but no image key. Body: " . substr((string)$res, 0, 200), "FLUX_WARN");
            // Treat as transient — retry
            $wait = 5;

        // ── 503 / 502 / 504 — Modal container not ready yet ───
        } elseif (in_array($httpCode, [503, 502, 504])) {
            $elapsed_total = round(microtime(true) - $t_func, 1);
            logGeneration("FLUX: HTTP $httpCode (Modal booting) attempt=$attempt elapsed={$elapsed_total}s — will retry...", "FLUX_BOOTING");
            $wait = 10; // wait 10s then probe again

        // ── 429 — rate limited ────────────────────────────────
        } elseif ($httpCode === 429) {
            logGeneration("FLUX: HTTP 429 rate limited attempt=$attempt — waiting 15s...", "FLUX_RATELIMIT");
            $wait = 15;

        // ── curl timeout (CURLE_OPERATION_TIMEDOUT = 28) ──────
        } elseif ($curlErrno === 28) {
            // The single request timed out — this means Modal held the connection
            // open but didn't respond in time_left seconds. This shouldn't happen
            // with dynamic timeout, but if it does, stop retrying.
            logGeneration("FLUX: curl timeout (errno 28) after {$totalTime}s — giving up", "FLUX_ERROR");
            break;

        // ── Other errors ──────────────────────────────────────
        } else {
            logGeneration("FLUX: FAIL attempt=$attempt | HTTP=$httpCode curlErr=$curlErrno ($curlErrStr)", "FLUX_ERROR");
            $wait = 5;
        }

        // Check if we still have time to retry
        if (microtime(true) + $wait >= $deadline) {
            logGeneration("FLUX: Not enough time for another retry — stopping", "FLUX_ERROR");
            break;
        }
        logGeneration("FLUX: Waiting {$wait}s before retry " . ($attempt + 1) . "...", "FLUX");
        sleep($wait);
    }

    $total_elapsed = round(microtime(true) - $t_func, 1);
    logTimed("FLUX: All attempts FAILED after {$total_elapsed}s ($attempt attempts)", "FLUX_ERROR", $t_func);
    return ['success' => false, 'error' => "FLUX failed after $attempt attempts ({$total_elapsed}s)", 'source' => 'FLUX/Modal'];
}

// === OPENAI gpt-image-1 GENERATION (FALLBACK) =================
function generateWithOpenAI($prompt, $resolution = "1024x1536") {
    global $apiKey;
    $t_func = microtime(true);
    logGeneration("OPENAI: Starting fallback | prompt_len=" . strlen($prompt), "OPENAI");
    if (empty($apiKey)) { logGeneration("OPENAI: No API key — skipping", "OPENAI_ERROR"); return ['success' => false, 'error' => 'No API key', 'source' => 'OpenAI']; }
    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["model" => "gpt-image-1", "prompt" => $prompt, "size" => $resolution, "quality" => "medium", "output_format" => "png", "n" => 1]),
    ]);
    $res       = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $curlError = curl_error($ch);
    curl_close($ch);
    logTimed("OPENAI: HTTP=$httpCode curlTime={$totalTime}s", "OPENAI", $t_func);
    if ($curlError) { logGeneration("OPENAI: cURL error: $curlError", "OPENAI_ERROR"); return ['success' => false, 'error' => $curlError, 'source' => 'OpenAI']; }
    if ($httpCode !== 200) { $parsed = json_decode($res, true); $errMsg = $parsed['error']['message'] ?? "HTTP $httpCode"; logGeneration("OPENAI: HTTP error $httpCode — $errMsg", "OPENAI_ERROR"); return ['success' => false, 'error' => $errMsg, 'source' => 'OpenAI']; }
    $result = json_decode($res, true);
    if (!isset($result['data'][0]['b64_json'])) { logGeneration("OPENAI: No b64_json", "OPENAI_ERROR"); return ['success' => false, 'error' => 'No image data', 'source' => 'OpenAI']; }
    logTimed("OPENAI: SUCCESS", "OPENAI_SUCCESS", $t_func);
    return ['success' => true, 'image' => $result['data'][0]['b64_json'], 'source' => 'OpenAI gpt-image-1'];
}

// === PRIMARY GENERATION FUNCTION ==============================
function generateImageWithFallback($prompt, $maxFluxRetries = 2) {
    $t0 = microtime(true);
    logGeneration("=== generateImageWithFallback START ===", "MAIN");
    $result = generateWithFlux($prompt, $maxFluxRetries);
    if ($result['success']) { logTimed("MAIN: FLUX succeeded", "MAIN_SUCCESS", $t0); return $result; }
    logGeneration("MAIN: FLUX failed — FALLING BACK TO OPENAI (\$\$\$ costs money!) ...", "MAIN_FALLBACK");
    $openaiResult = generateWithOpenAI($prompt);
    if ($openaiResult['success']) { logTimed("MAIN: OpenAI fallback succeeded", "MAIN_SUCCESS", $t0); return $openaiResult; }
    logTimed("MAIN: BOTH providers FAILED", "MAIN_ERROR", $t0);
    return ['success' => false, 'error' => 'Both providers failed', 'source' => 'none'];
}


function callAI($apiUrl, $apiKey, $systemPrompt, $userInput, $temp = 0.92) {
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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer " . $apiKey],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data)
    ]);
    $result  = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false) { error_log("[callAI] cURL error: $curlErr"); return null; }
    if ($http !== 200)     { error_log("[callAI] HTTP $http: " . substr($result, 0, 200)); return null; }
    $json = json_decode($result, true);
    if (isset($json['error'])) { error_log("[callAI] API error: " . ($json['error']['message'] ?? 'unknown')); return null; }
    return $json["choices"][0]["message"]["content"] ?? null;
}

// === AI CALL WITH VISION (sends product image) ================
function callAIWithVision($apiKey, $systemPrompt, $userText, $imageBase64, $imageMime = 'image/jpeg', $temp = 0.85) {
    if (!$apiKey) { error_log("[callAIWithVision] No API key"); return null; }
    $messages = [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => [
            ["type" => "image_url", "image_url" => ["url" => "data:{$imageMime};base64,{$imageBase64}"]],
            ["type" => "text", "text" => $userText]
        ]]
    ];
    $data = ["model" => "gpt-4o-mini", "messages" => $messages, "temperature" => $temp, "max_tokens" => 1000];
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer " . $apiKey],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data)
    ]);
    $result  = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false || $http !== 200) { error_log("[callAIWithVision] Error HTTP $http: $curlErr"); return null; }
    $json = json_decode($result, true);
    return $json["choices"][0]["message"]["content"] ?? null;
}

// ── AJAX: Warmup Modal ─────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'warmup_modal') {
    header('Content-Type: application/json');
    set_time_limit(120);
    logGeneration("WARMUP AJAX: Triggered", "WARMUP");
    $t0 = microtime(true);
    $is_warm = warmupModal();
    $elapsed = round(microtime(true) - $t0, 2);
    logGeneration("WARMUP AJAX: Done in {$elapsed}s — is_warm=" . ($is_warm ? 'YES' : 'NO'), "WARMUP");
    echo json_encode([
        'success' => $is_warm,
        'message' => $is_warm
            ? "Modal is warm and ready ({$elapsed}s)"
            : "Modal cold start triggered — container booting ({$elapsed}s). First image may be slower.",
        'elapsed' => $elapsed,
    ]);
    exit;
}

// ── AJAX: Upload product image ─────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_product_image') {
    header('Content-Type: application/json');
    if (empty($_FILES['product_image']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }
    $file     = $_FILES['product_image'];
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime     = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Use JPG, PNG, WEBP, or GIF.']);
        exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
        exit;
    }
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $saveDir  = 'product_images/uploads';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    $savePath = $saveDir . '/product_' . time() . '_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        exit;
    }
    $_SESSION['product_image_path'] = $savePath;
    $_SESSION['product_image_name'] = htmlspecialchars($file['name']);

    // Use vision to auto-describe the product
    $imgBase64 = getProductImageBase64();
    $imgMime   = getProductImageMime();
    $autoDesc  = null;
    if ($imgBase64 && $apiKey) {
        $autoDesc = callAIWithVision(
            $apiKey,
            "You are a product analyst. Describe this product image in 2-3 sentences covering: product type, key visual features (shape, color, packaging, materials), and any visible branding. Be factual and concise.",
            "Describe this product for a video shoot brief.",
            $imgBase64,
            $imgMime,
            0.5
        );
        if ($autoDesc) $_SESSION['product_auto_desc'] = $autoDesc;
    }
    echo json_encode(['success' => true, 'path' => $savePath, 'name' => $file['name'], 'auto_desc' => $autoDesc]);
    exit;
}

// ── AJAX: Clear product image ──────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clear_product_image') {
    header('Content-Type: application/json');
    $path = $_SESSION['product_image_path'] ?? null;
    if ($path && file_exists($path)) @unlink($path);
    unset($_SESSION['product_image_path'], $_SESSION['product_image_name'], $_SESSION['product_auto_desc']);
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: More options for a parameter ────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'more_options') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$apiKey) { echo json_encode(['success' => false, 'error' => 'API key not found']); exit; }

    $product   = trim($_POST['product']   ?? $_SESSION['product']   ?? '');
    $field     = trim($_POST['field']     ?? '');
    $existing  = trim($_POST['existing']  ?? '');
    $additional= trim($_POST['additional']?? $_SESSION['additional_info'] ?? '');

    $valid_fields = ['title', 'brand_tone', 'hook', 'target_customer', 'use_scene', 'audio_mood', 'product_hero', 'desire_outcome'];
    if (!$field || !in_array($field, $valid_fields)) {
        echo json_encode(['success' => false, 'error' => 'Invalid field']); exit;
    }

    $field_labels = [
        'title'           => 'video title (short, catchy, max 8 words)',
        'brand_tone'      => 'brand tone and emotion (max 8 words)',
        'hook'            => 'opening line that grabs attention in 2 seconds (max 8 words)',
        'target_customer' => 'target customer persona (max 8 words)',
        'use_scene'       => 'visual setting / usage scene (max 8 words)',
        'audio_mood'      => 'music style and feel (max 8 words)',
        'product_hero'    => 'the key product hero shot or feature (max 8 words)',
        'desire_outcome'  => 'the desire/emotion viewer feels after watching (max 8 words)',
    ];

    $add_block = $additional ? "Additional context: $additional\n" : "";
    $systemPrompt = "You are a product video strategist for short-form social media.
Generate 4 NEW and DIFFERENT options for the '$field' parameter for a product showcase video.
Product: $product
{$add_block}
Already shown options (DO NOT repeat): $existing

Return ONLY a JSON array of exactly 4 strings. No markdown, no explanation.
Each option max 8 words. Make them varied and creative.
Example: [\"Option one here\",\"Option two here\",\"Option three here\",\"Option four here\"]";

    $result  = callAI($apiUrl, $apiKey, $systemPrompt, "Generate 4 more options for: " . $field_labels[$field], 0.95);
    $options = $result ? json_decode($result, true) : null;
    if (!is_array($options) || count($options) < 2) {
        echo json_encode(['success' => false, 'error' => 'Could not generate options']); exit;
    }
    echo json_encode(['success' => true, 'options' => array_slice($options, 0, 4)]);
    exit;
}

// ── AJAX: Suggest scene changes ────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'suggest_scene') {
    header('Content-Type: application/json; charset=utf-8');
    $wan_prompt = trim($_POST['wan_prompt'] ?? '');
    $suggestion = trim($_POST['suggestion'] ?? '');
    if (!$wan_prompt || !$suggestion) {
        echo json_encode(['success' => false, 'error' => 'Missing prompt or suggestion']); exit;
    }
    $systemPrompt = 'You are a product video prompt engineer.
The user wants to change a specific element in the scene. Apply this change fully and explicitly.

CRITICAL RULES:
- If suggestion changes product angle/lighting — fully replace those details
- If suggestion changes background/location — replace ALL environment descriptions
- Keep the product visually prominent and accurate
- The change must be OBVIOUS and DOMINANT in the new prompt

Return ONLY this JSON (no markdown, no explanation):
{
  "video_prompt": "updated WAN 2.2 product video prompt with change fully applied",
  "image_prompt": "updated photorealistic product image prompt with change fully applied"
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
    $res    = curl_exec($ch); curl_close($ch);
    $raw    = trim(json_decode($res, true)["choices"][0]["message"]["content"] ?? "");
    $raw    = preg_replace(["/^```json\s*/i", "/```\s*$/i"], "", $raw);
    $parsed = json_decode(trim($raw), true);
    if (!$parsed || empty($parsed["video_prompt"])) {
        echo json_encode(["success" => false, "error" => "AI could not update prompts"]); exit;
    }
    echo json_encode(["success" => true, "video_prompt" => $parsed["video_prompt"], "image_prompt" => $parsed["image_prompt"] ?? $parsed["video_prompt"]]);
    exit;
}

// ── AJAX: Raw Modal test ───────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'test_modal_raw') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(200);
    $t0 = microtime(true);
    $test_payload = json_encode(['prompt' => 'a red apple on a white table, photorealistic', 'style' => 'cinematic', 'width' => 256, 'height' => 256]);
    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $test_payload, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $curlErrno = curl_errno($ch);
    $curlErrStr= curl_error($ch);
    curl_close($ch);
    $elapsed   = round(microtime(true) - $t0, 2);
    $parsed    = $response ? json_decode($response, true) : null;
    $has_image = !empty($parsed['image']);
    echo json_encode([
        'url' => $MODAL_URL, 'http_code' => $httpCode, 'curl_errno' => $curlErrno, 'curl_error' => $curlErrStr ?: null,
        'total_time_s' => $totalTime, 'elapsed_s' => $elapsed, 'has_image' => $has_image,
        'response_keys' => $parsed ? array_keys($parsed) : null,
        'body_preview'  => $has_image ? '(base64 image data — OK)' : substr((string)$response, 0, 500),
        'verdict'       => $has_image ? "✅ FLUX working — {$elapsed}s" : "❌ FLUX not returning image — HTTP=$httpCode curlErr=$curlErrno",
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── AJAX: Deduct credits ──────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'deduct_cinematic_credits') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $_u2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $deduct_from = (!empty($_u2) && trim((string)($_u2['role'] ?? '')) === 'Team Member' && (int)($_u2['team_lead_id'] ?? 0) > 0)
            ? (int)$_u2['team_lead_id'] : $admin_id;
        $bal_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1"));
        $balance = (int)($bal_row['credit_balance'] ?? 0);
        if ($balance < 6) { echo json_encode(['success' => false, 'message' => 'Insufficient credits', 'balance' => $balance]); exit; }
        mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - 6) WHERE id=$deduct_from");
        echo json_encode(['success' => true, 'deducted' => 6, 'balance' => $balance - 6]);
    } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// ── AJAX: Save cinematic podcast to DB ────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_cinematic_to_db') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(180);
    try {
        $_u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $team_lead_id = (!empty($_u) && trim((string)($_u['role'] ?? '')) === 'Team Member' && (int)($_u['team_lead_id'] ?? 0) > 0)
            ? (int)$_u['team_lead_id'] : $admin_id;

        // Load user caption settings
        $def_cap = ['fontfamily'=>'Arial','fontsize'=>28,'fontcolor'=>'#ffffff','fontweight'=>'normal','font_italic'=>0,'font_underline'=>0,'caption_alignment'=>'center','text_align_v'=>'bottom','fontcolor_bg'=>'#000000','fontbg_enable'=>0,'stroke_color'=>'#000000','stroke_width'=>0,'shadow_color'=>'#000000','gradient_color'=>'#ff6600','_anim_style'=>'none','_anim_speed'=>1.0,'_text_fx'=>'none','_text_fx_col'=>'#ffffff','caption_style'=>'none','caption_position'=>'bottom','display_mode'=>'full','position_x'=>50,'position_y'=>200,'width'=>500,'is_enabled'=>1];
        $def_hdr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>16,'text_align_v'=>'top']);
        $def_ftr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>555,'text_align_v'=>'bottom']);
        $cap_settings = $def_cap; $hdr_settings = $def_hdr; $ftr_settings = $def_ftr;
        $us_res = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$company_id ORDER BY id DESC LIMIT 1");
        if ($us_res && $us_row = mysqli_fetch_assoc($us_res)) {
            $cap_settings = array_merge($def_cap, ['fontfamily'=>$us_row['fontfamily']??'Arial','fontsize'=>(int)($us_row['fontsize']??28),'fontcolor'=>$us_row['fontcolor']??'#ffffff','fontweight'=>$us_row['fontweight']??'normal','font_italic'=>(int)($us_row['font_italic']??0),'font_underline'=>(int)($us_row['font_underline']??0),'caption_alignment'=>$us_row['caption_alignment']??'center','text_align_v'=>$us_row['text_align_v']??'bottom','fontcolor_bg'=>$us_row['fontcolor_bg']??'#000000','fontbg_enable'=>(int)($us_row['fontbg_enable']??0),'stroke_color'=>$us_row['stroke_color']??'#000000','stroke_width'=>(int)($us_row['stroke_width']??0),'shadow_color'=>$us_row['shadow_color']??'#000000','gradient_color'=>$us_row['gradient_color']??'#ff6600','_anim_style'=>$us_row['text_animation']??$us_row['animation_style']??'none','_anim_speed'=>is_numeric($us_row['animation_speed']??null)?(float)$us_row['animation_speed']:1.0,'_text_fx'=>$us_row['text_effect']??'none','_text_fx_col'=>$us_row['text_effect_color']??'#ffffff','caption_style'=>$us_row['caption_style']??'none','caption_position'=>$us_row['caption_position']??'bottom','display_mode'=>$us_row['display_mode']??'full','position_x'=>(int)($us_row['position_x']??50),'position_y'=>(int)($us_row['position_y']??200),'width'=>(int)($us_row['width']??500),'is_enabled'=>1]);
            $hdr_settings = array_merge($def_hdr, ['is_enabled'=>(int)($us_row['header_enabled']??0),'caption_text'=>$us_row['header_text']??'','fontfamily'=>$us_row['header_fontfamily']??'Arial','fontsize'=>(int)($us_row['header_fontsize']??24),'fontcolor'=>$us_row['header_fontcolor']??'#ffffff']);
            $ftr_settings = array_merge($def_ftr, ['is_enabled'=>(int)($us_row['footer_enabled']??0),'caption_text'=>$us_row['footer_text']??'','fontfamily'=>$us_row['footer_fontfamily']??'Arial','fontsize'=>(int)($us_row['footer_fontsize']??20),'fontcolor'=>$us_row['footer_fontcolor']??'#ffffff']);
        }

        $scenes_in  = json_decode($_POST['scenes'] ?? '[]', true);
        if (!is_array($scenes_in) || empty($scenes_in)) { echo json_encode(['success'=>false,'message'=>'No scene data received']); exit; }

        $title_raw  = trim($_POST['title']    ?? ($_SESSION['selected']['title'] ?? 'Product Video'));
        $niche      = mysqli_real_escape_string($conn, trim($_POST['product'] ?? ($_SESSION['product'] ?? '')));
        $esc_title  = mysqli_real_escape_string($conn, $title_raw);
        $lang_code  = 'en'; $reel_type = 'cinematic'; $category = 'cinematic';
        $topic_key  = mysqli_real_escape_string($conn, $niche ?: 'product');
        $today      = date('Y-m-d');
        $co_id      = (int)($_SESSION['company_id'] ?? $admin_id);

        $all_text   = implode(' ', array_column($scenes_in, 'caption'));
        $stop       = ['the','and','for','you','your','with','that','this','are','can','will','have','from','they','their','what','about','there','more','some','would','could','should','been','were','was','one','two','first','then','than','very','just','like','into','over','also','after','other','only'];
        $words      = array_diff(str_word_count(strtolower($all_text), 1), $stop);
        $kw_arr     = array_slice(array_unique(array_values($words)), 0, 10);
        $ht_arr     = array_map(fn($w) => '#'.$w, array_slice($kw_arr, 0, 7));
        if ($niche) { $kw_arr[] = strtolower($niche); $ht_arr[] = '#'.preg_replace('/\s+/','',$niche); }
        $hashtags   = mysqli_real_escape_string($conn, implode(', ', array_unique($ht_arr)));
        $keywords   = mysqli_real_escape_string($conn, implode(', ', array_unique($kw_arr)));

        $sql_pod = "INSERT INTO hdb_podcasts (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status, created_date, updated_at, niche, category, topic_key, hashtags, keywords, host_voice, guest_voice, voice_rate, is_campaign, logo_flag, facebook_status, tiktok_status, instagram_status, youtube_status, twitter_status, linkedin_status, schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name, videogen_flag)
            VALUES ($admin_id, $team_lead_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'draft', '$today', NOW(), '$niche', '$category', '$topic_key', '$hashtags', '$keywords', '', '', 1.0, 0, 0, 'pending', 'pending', 'pending', 'pending', 'pending', 'pending', '$today', '09:00', '$today', 'vertical', 'video', '', '', 1)";
        if (!mysqli_query($conn, $sql_pod)) { echo json_encode(['success'=>false,'message'=>'Podcast insert failed: '.mysqli_error($conn)]); exit; }
        $podcast_id    = mysqli_insert_id($conn);
        $success_count = 0;
        $story_id_map  = [];

        foreach ($scenes_in as $i => $scene) {
            $seq_no    = $i + 1;
            $scene_num = (int)($scene['num'] ?? $seq_no);
            $cap_raw   = trim($scene['caption'] ?? '');
            $wan       = trim($scene['wan']     ?? '');
            $img_file  = trim($scene['file']    ?? '');
            // Duration = 30s / total scenes, clamped 3-10s
            $total_secs = 30; $sc_tot = count($scenes_in);
            $duration   = max(3, min(10, ($sc_tot > 0 ? (int)floor($total_secs / $sc_tot) : 5)));
            $text      = $cap_raw ?: substr($wan, 0, 200);
            if (empty($text)) continue;
            $te  = mysqli_real_escape_string($conn, $text);
            $de  = mysqli_real_escape_string($conn, substr($text,0,50).(strlen($text)>50?'...':''));
            $pe  = mysqli_real_escape_string($conn, $wan);
            $ife = mysqli_real_escape_string($conn, $img_file);
            $tke = mysqli_real_escape_string($conn, $title_raw);
            $ins = "INSERT INTO hdb_podcast_stories (podcast_id, lang_code, category, topic_key, title, actor, text_contents, text_display, duration, prompt, video_prompt, visual_type, status, created_date, seq_no, logo_flag, hashtags, natural_language_tags, voice_id, voice_rate, image_file, videogen_flag)
                VALUES ($podcast_id,'$lang_code','$category','$topic_key','$tke','host','$te','$de',$duration,'$pe','$pe','image','PENDING',NOW(),$seq_no,0,'','','',1.0,'$ife',1)";
            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                $story_id_map[$scene_num] = $story_id;
                $success_count++;
                // Insert caption row
                if ((int)($cap_settings['is_enabled'] ?? 1)) {
                    $cap  = $cap_settings;
                    $words_arr = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
                    $ct   = mysqli_real_escape_string($conn, count($words_arr)>10 ? implode(' ',array_slice($words_arr,0,10)).'…' : $text);
                    $ff=mysqli_real_escape_string($conn,$cap['fontfamily']??'Arial'); $fc=mysqli_real_escape_string($conn,$cap['fontcolor']??'#ffffff'); $fw=mysqli_real_escape_string($conn,$cap['fontweight']??'normal');
                    $fst=((int)($cap['font_italic']??0))?'italic':'normal'; $uline=(int)($cap['font_underline']??0);
                    $ta=mysqli_real_escape_string($conn,$cap['caption_alignment']??'center'); $tav=mysqli_real_escape_string($conn,$cap['text_align_v']??'bottom');
                    $bgc=mysqli_real_escape_string($conn,$cap['fontcolor_bg']??'#000000'); $bge=(int)($cap['fontbg_enable']??0);
                    $sc2=mysqli_real_escape_string($conn,$cap['stroke_color']??'#000000'); $sw=(int)($cap['stroke_width']??0); $se=$sw>0?1:0;
                    $shc=mysqli_real_escape_string($conn,$cap['shadow_color']??'#000000'); $gc=mysqli_real_escape_string($conn,$cap['gradient_color']??'#ff6600');
                    $ast=mysqli_real_escape_string($conn,$cap['_anim_style']??'none'); $asp=is_numeric($cap['_anim_speed']??null)?(float)$cap['_anim_speed']:1.0;
                    $tfx=mysqli_real_escape_string($conn,$cap['_text_fx']??'none'); $tfc=mysqli_real_escape_string($conn,$cap['_text_fx_col']??'#ffffff');
                    $cst=mysqli_real_escape_string($conn,$cap['caption_style']??'none'); $cpv=mysqli_real_escape_string($conn,$cap['caption_position']??'bottom'); $dm=mysqli_real_escape_string($conn,$cap['display_mode']??'full');
                    $px=(int)($cap['position_x']??50); $py=(int)($cap['position_y']??200); $pw=min((int)($cap['width']??500),350); $fs=(int)($cap['fontsize']??28);
                    mysqli_query($conn,"INSERT INTO hdb_captions (podcast_id,story_id,caption_type,caption_name,text_content,fontfamily,fontsize,fontcolor,fontweight,fontstyle,underline,text_align,text_align_v,bg_color,bg_enabled,stroke_color,stroke_width,stroke_enabled,shadow_color,gradient_color,text_effects,text_effect_colors,panning_zooming_type,panning_zooming_speed,position_x,position_y,width,rotation,animation_style,animation_speed,caption_style,caption_position,display_mode,text_decoration,is_visible,z_index) VALUES ($podcast_id,$story_id,'caption','main','$ct','$ff',$fs,'$fc','$fw','$fst',$uline,'$ta','$tav','$bgc',$bge,'$sc2',$sw,$se,'$shc','$gc','$tfx','$tfc',0,0,$px,$py,$pw,0,'$ast',$asp,'$cst','$cpv','$dm','none',1,1)");
                }
            } else {
                $ins2 = "INSERT INTO hdb_podcast_stories (podcast_id,lang_code,category,topic_key,title,actor,text_contents,text_display,duration,prompt,video_prompt,visual_type,status,created_date,seq_no,logo_flag,hashtags,voice_id,voice_rate) VALUES ($podcast_id,'$lang_code','$category','$topic_key','$tke','host','$te','$de',$duration,'$pe','$pe','image','PENDING',NOW(),$seq_no,0,'','',1.0)";
                if (mysqli_query($conn, $ins2)) { $story_id_map[$scene_num] = mysqli_insert_id($conn); $success_count++; }
            }
        }

        echo json_encode(['success'=>true,'podcast_id'=>$podcast_id,'scene_count'=>$success_count,'story_id_map'=>$story_id_map]);
    } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Generate scene image ─────────────────────────────────
// -- AJAX: get_queue_status -----------------------------------------------
// -- AJAX: get_scene_count -- ETA for mode selector ----------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scene_count') {
    ob_start(); ini_set('display_errors', 0);
    $pid         = (int)($_POST['podcast_id'] ?? 0);
    $scene_count = (int)($_POST['scene_count'] ?? 6);
    if ($pid) {
        $sc_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id=$pid"));
        $scene_count = (int)($sc_row['cnt'] ?? $scene_count);
    }
    $iq_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_image_gen_que WHERE videogen_flag IN (1,2)"));
    $img_queue_depth = (int)($iq_row['cnt'] ?? 0);
    $vq_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag = 1"));
    $vid_queue_depth = (int)($vq_row['cnt'] ?? 0);
    $fast_img_mins = max(1, (int)ceil(($scene_count * 8) / 60));
    $std_img_mins  = max(1, (int)ceil((($img_queue_depth * 25) + 200 + ($scene_count * 25)) / 60));
    $fast_vid_mins = max(1, $scene_count * 1);
    $std_vid_mins  = max(1, ($vid_queue_depth + $scene_count) * 3);
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'         => true,
        'scene_count'     => $scene_count,
        'img_queue_depth' => $img_queue_depth,
        'vid_queue_depth' => $vid_queue_depth,
        'fast_img_mins'   => $fast_img_mins,
        'fast_img_label'  => '~' . $fast_img_mins . ' min',
        'std_img_mins'    => $std_img_mins,
        'std_img_label'   => '~' . $std_img_mins . ' min (' . $img_queue_depth . ' jobs ahead)',
        'fast_vid_mins'   => $fast_vid_mins,
        'fast_vid_label'  => '~' . $fast_vid_mins . ' min',
        'std_vid_mins'    => $std_vid_mins,
        'std_vid_label'   => '~' . $std_vid_mins . ' min (' . $vid_queue_depth . ' in queue)',
    ]);
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_queue_status') {
    ob_start(); ini_set('display_errors', 0);
    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'No podcast_id']); exit; }

    $img_scenes_q = mysqli_query($conn, "SELECT id,scene_id,videogen_flag,image_name,image_folder FROM hdb_image_gen_que WHERE podcast_id=$pid ORDER BY id ASC");
    $img_scenes = [];
    while ($r = mysqli_fetch_assoc($img_scenes_q)) { $img_scenes[] = $r; }
    $img_total   = count($img_scenes);
    $img_done    = count(array_filter($img_scenes, function($r){ return (int)$r['videogen_flag']===3; }));
    $img_pending = $img_total - $img_done;
    $my_first_pending_id = 0;
    foreach ($img_scenes as $s) { if ((int)$s['videogen_flag']!==3){ $my_first_pending_id=(int)$s['id']; break; } }
    $img_ahead = 0;
    if ($my_first_pending_id > 0) {
        $ar = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_image_gen_que WHERE videogen_flag IN (1,2) AND id < $my_first_pending_id"));
        $img_ahead = (int)($ar['cnt'] ?? 0);
    }
    $gr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM hdb_image_gen_que WHERE videogen_flag IN (1,2)"));
    $img_global_pending = (int)($gr['total'] ?? 0);
    $img_eta = $img_ahead + $img_pending;

    $vr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM hdb_video_gen_que WHERE videogen_flag = 1"));
    $vid_total=$vid_pending=(int)($vr['total']??0);
    $vid_done=$vid_ahead=$vid_global_pending=$vid_total;
    $vid_eta=$vid_total*1; // ~1 min per video via fal.ai

    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'img_total'=>$img_total,'img_done'=>$img_done,'img_pending'=>$img_pending,'img_global_pending'=>$img_global_pending,'img_ahead'=>$img_ahead,'img_eta_mins'=>$img_eta,'img_scenes'=>$img_scenes,'vid_total'=>$vid_total,'vid_done'=>$vid_done,'vid_pending'=>$vid_pending,'vid_ahead'=>$vid_ahead,'vid_global_pending'=>$vid_global_pending,'vid_eta_mins'=>$vid_eta,'all_images_done'=>($img_done>=$img_total&&$img_total>0)]);
    exit;
}

// -- AJAX: Queue scene for image + video generation -----------------------
if (isset($_POST['ajax_action']) && ($_POST['ajax_action'] === 'generate_scene_image')) {
    ob_start(); ini_set('display_errors', 0);
    $video_prompt = trim($_POST['video_prompt'] ?? trim($_POST['wan_prompt'] ?? ''));
    if (!$video_prompt) $video_prompt = trim($_POST['wan_prompt'] ?? '');
    $wan_prompt   = $video_prompt
        ? $video_prompt . ' Static establishing shot from the first frame, thumbnail-optimized, no motion blur.'
        : '';
    $scene_num    = (int)($_POST['scene_num']   ?? 0);
    $podcast_id   = (int)($_POST['podcast_id']  ?? 0);
    $story_id     = (int)($_POST['story_id']    ?? 0);

    if (!$wan_prompt) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'No prompt provided','scene'=>$scene_num]); exit;
    }
    if (!$podcast_id || !$story_id) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'Missing podcast_id or story_id','podcast_id'=>$podcast_id,'story_id'=>$story_id,'scene'=>$scene_num]); exit;
    }

    $now='Y-m-d H:i:s'; $now=date($now);
    $gen_mode    = trim($_POST['gen_mode'] ?? 'modal.com');
    $image_folder='podcast_images'; $video_folder='video_ai'; $media_type='cinematic';
    $image_name  ='scene_'.$podcast_id.'_'.$story_id.'.png';
    $video_file  ='scene_'.$podcast_id.'_'.$story_id.'.mp4';
    $pe=mysqli_real_escape_string($conn,$wan_prompt);
    $vpe=mysqli_real_escape_string($conn,$video_prompt);
    $ife=mysqli_real_escape_string($conn,$image_folder);
    $vfe=mysqli_real_escape_string($conn,$video_folder);
    $mte=mysqli_real_escape_string($conn,$media_type);
    $ine=mysqli_real_escape_string($conn,$image_name);
    $vne=mysqli_real_escape_string($conn,$video_file);
    $gme=mysqli_real_escape_string($conn,$gen_mode);
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_image_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    $sc_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id=$podcast_id"));
    $sc_count    = max(1, (int)($sc_row['cnt'] ?? 6));
    $scene_dur   = max(3, min(10, (int)floor(30 / $sc_count)));

    // Image queue
    $img_row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT id,videogen_flag FROM hdb_image_gen_que WHERE podcast_id=$podcast_id AND scene_id=$story_id LIMIT 1"));
    $img_queued=false;
    if ($img_row) {
        if ((int)$img_row['videogen_flag'] != 2) {
            mysqli_query($conn,"UPDATE hdb_image_gen_que SET prompt='$pe',image_folder='$ife',media_type='$mte',image_name='$ine',videogen_flag=1,gen_mode='$gme',updated_at='$now' WHERE id=".(int)$img_row['id']);
            $img_queued=true;
        }
    } else {
        $r=mysqli_query($conn,"INSERT INTO hdb_image_gen_que (podcast_id,scene_id,prompt,image_folder,media_type,image_name,videogen_flag,gen_mode,duration,created_at,updated_at) VALUES ($podcast_id,$story_id,'$pe','$ife','$mte','$ine',1,'$gme',$scene_dur,'$now','$now')");
        if (!$r) $r=mysqli_query($conn,"INSERT INTO hdb_image_gen_que (podcast_id,scene_id,prompt,image_folder,media_type,image_name,videogen_flag,created_at,updated_at) VALUES ($podcast_id,$story_id,'$pe','$ife','$mte','$ine',1,'$now','$now')");
        if ($r){ $img_queued=true; } else { logGeneration("QUEUE: img INSERT FAILED podcast=$podcast_id scene=$story_id err=".mysqli_error($conn),"QUEUE_ERROR"); }
    }

    // Video queue
    $vid_row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT id,videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$story_id LIMIT 1"));
    $vid_queued=false;
    if ($vid_row) {
        if ((int)$vid_row['videogen_flag'] != 2) {
            mysqli_query($conn,"UPDATE hdb_video_gen_que SET prompt='$vpe',video_folder='$vfe',media_type='$mte',video_file='$vne',videogen_flag=1,gen_mode='$gme',duration=$scene_dur,updated_at='$now' WHERE id=".(int)$vid_row['id']);
            $vid_queued=true;
        }
    } else {
        $r=mysqli_query($conn,"INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,duration,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','$mte','$vne',1,'$gme',$scene_dur,'$now','$now')");
        if (!$r) $r=mysqli_query($conn,"INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','$mte','$vne',1,'$now','$now')");
        if ($r){ $vid_queued=true; } else { logGeneration("QUEUE: vid INSERT FAILED podcast=$podcast_id scene=$story_id err=".mysqli_error($conn),"QUEUE_ERROR"); }
    }

    if ($podcast_id && ($img_queued || $vid_queued)) {
        mysqli_query($conn, "UPDATE hdb_podcasts SET internal_status='scenes_ready', updated_at='$now' WHERE id=$podcast_id");
    }

    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'queued'=>true,'img_queued'=>$img_queued,'vid_queued'=>$vid_queued,'scene'=>$scene_num,'story_id'=>$story_id,'podcast_id'=>$podcast_id,'image_folder'=>$image_folder,'image_name'=>$image_name,'img_error'=>$img_queued?null:mysqli_error($conn),'vid_error'=>$vid_queued?null:mysqli_error($conn),'message'=>'Scene '.$scene_num.' queued']);
    exit;
}

if ($step == "1" && isset($_POST['product']) && !empty(trim($_POST['product']))) {
    $product         = trim($_POST['product']);
    $additional_info = trim($_POST['additional_info'] ?? '');
    $cta_text        = trim($_POST['cta_text'] ?? '');
    $_SESSION['product']         = $product;
    $_SESSION['additional_info'] = $additional_info;
    $_SESSION['cta_text']        = $cta_text;

    // If product image was just uploaded and we have an auto-description, weave it in
    $auto_desc = $_SESSION['product_auto_desc'] ?? '';
    $image_block = $auto_desc ? "\nProduct Visual Description (from uploaded image): $auto_desc" : "";

    $systemPrompt = "You are a product video strategist for short-form social media.
Given a product, generate EXACTLY the following JSON with NO extra text.
Each field must have 'selected' and 'options' (4 alternatives).
Focus on commercial appeal, virality, and product desirability.

Fields: title, brand_tone, hook, target_customer, use_scene, audio_mood, product_hero, desire_outcome

Return pure JSON only:
{
  \"title\":           {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"brand_tone\":      {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\":            {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"target_customer\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"use_scene\":       {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\":      {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"product_hero\":    {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"desire_outcome\":  {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}";

    $user_input = "Product: $product{$image_block}" . ($additional_info ? "\nAdditional context: $additional_info" : "");
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, $user_input, 0.85);
    if ($raw) {
        $raw = preg_replace('/^```json\s*/i', '', trim($raw));
        $raw = preg_replace('/^```\s*/i',    '', $raw);
        $raw = preg_replace('/```\s*$/i',    '', $raw);
        $raw = trim($raw);
    }
    $suggestions = $raw ? json_decode($raw, true) : null;
    if ($suggestions && isset($suggestions['title'])) {
        $_SESSION['suggestions'] = $suggestions;
    } else {
        $debug = $raw ? substr($raw, 0, 200) : ($apiKey ? 'callAI returned null' : 'No API key found');
        error_log("[product_movie_gen step1] Failed: $debug");
        $_SESSION['error'] = "AI could not generate suggestions. Please try again.";
    }
}

// ── STEP 2: Generate Product Narrative ────────────────────────
if ($step == "2" && isset($_SESSION['suggestions'])) {
    $s       = $_SESSION['suggestions'];
    $product = $_SESSION['product'] ?? '';

    $sel = [
        'title'           => trim($_POST['sel_title']           ?? $s['title']['selected']           ?? ''),
        'brand_tone'      => trim($_POST['sel_brand_tone']      ?? $s['brand_tone']['selected']      ?? ''),
        'hook'            => trim($_POST['sel_hook']            ?? $s['hook']['selected']            ?? ''),
        'target_customer' => trim($_POST['sel_target_customer'] ?? $s['target_customer']['selected'] ?? ''),
        'use_scene'       => trim($_POST['sel_use_scene']       ?? $s['use_scene']['selected']       ?? ''),
        'audio_mood'      => trim($_POST['sel_audio_mood']      ?? $s['audio_mood']['selected']      ?? ''),
        'product_hero'    => trim($_POST['sel_product_hero']    ?? $s['product_hero']['selected']    ?? ''),
        'desire_outcome'  => trim($_POST['sel_desire_outcome']  ?? $s['desire_outcome']['selected']  ?? ''),
    ];
    $_SESSION['selected'] = $sel;

    $paramBlock = "Product: $product
Title: {$sel['title']}
Brand Tone: {$sel['brand_tone']}
Hook: {$sel['hook']}
Target Customer: {$sel['target_customer']}
Usage Scene: {$sel['use_scene']}
Audio Mood: {$sel['audio_mood']}
Product Hero Shot: {$sel['product_hero']}
Desire Outcome: {$sel['desire_outcome']}";

    $additional = $_SESSION['additional_info'] ?? '';
    if ($additional) $paramBlock .= "\nAdditional Instructions: $additional";

    $auto_desc   = $_SESSION['product_auto_desc'] ?? '';
    $image_block = $auto_desc ? "\nProduct Visual Reference: $auto_desc" : "";

    $systemPrompt = "You are a world-class product video director and copywriter.
Create a powerful product narrative using the parameters provided.
This is for a short-form social media video — no dialogue, no voiceover, visual storytelling only.

Return in EXACTLY this format:

TITLE: [title]
BRAND TONE: [tone]
HOOK: [hook]
TARGET CUSTOMER: [customer]
USAGE SCENE: [scene]
AUDIO MOOD: [audio]
PRODUCT HERO SHOT: [hero]
DESIRE OUTCOME: [outcome]

PRODUCT NARRATIVE:
[One powerful commercial-grade paragraph — journey from desire/problem → product enters → transformation → viewer craves it.]

VISUAL DIRECTION:
[One paragraph describing the overall visual look, color palette, and style of the video.]";

    $response = callAI($apiUrl, $apiKey, $systemPrompt, $paramBlock . $image_block);
    $_SESSION['narrative'] = $response;
}

// ── STEP 3: Generate Full Product Video Script ────────────────
if ($step == "3" && isset($_SESSION['narrative'])) {
    $narrative = $_SESSION['narrative'];
    $sel       = $_SESSION['selected'] ?? [];
    $product   = $_SESSION['product'] ?? '';
    $auto_desc = $_SESSION['product_auto_desc'] ?? '';

    $product_visual = $auto_desc ? "\nPRODUCT VISUAL REFERENCE (from uploaded image): $auto_desc" : "";

    $systemPrompt = "You are an expert commercial AI film director.
Convert the approved product narrative into a complete 30-40 second faceless cinematic product video script.
The product must appear prominently in every scene.

# OUTPUT STRUCTURE

## 1. TITLE
## 2. OVERALL VISUAL STYLE
## 3. AUDIO DESIGN
## 4. SCENE BREAKDOWN (5-7 scenes)

Recommended scene arc:
- Scene 1: Pain point / desire (product NOT yet visible — tease the need)
- Scene 2: Product REVEAL — dramatic hero shot
- Scene 3: Key feature or benefit in use (close-up)
- Scene 4: Lifestyle moment — target customer enjoying the product
- Scene 5: Transformation / result
- Scene 6: (Optional) Social proof or comparison
- Final Scene: CTA + brand sign-off

Each scene MUST follow EXACTLY:
### SCENE (n) — [Scene Name]
Scene Description: [brief visual explanation]

WAN 2.2 CINEMATIC PROMPT:
[One detailed paragraph — minimum 80 words — describing this scene for AI video generation. Product must be visually accurate and prominent.]

CAMERA MOVEMENT: [describe motion]
LIGHTING STYLE: [define lighting — always bright, cool-neutral, commercial-grade]
EMOTIONAL INTENT: [what viewer feels]
ON-SCREEN TEXT: [short caption or NONE]

## 5. FINAL CALL TO ACTION

STRICT RULES:
- No talking heads, no dialogue
- Product must appear in scenes 2 onward — clearly visible, accurate to the real product
- Always bright cool-neutral lighting (5500K–6500K, NO warm tones)
- Cinematic product photography aesthetic throughout";

    $additional    = $_SESSION['additional_info'] ?? '';
    $cta           = $_SESSION['cta_text'] ?? '';
    $add_block     = $additional ? "\n\nADDITIONAL INSTRUCTIONS:\n$additional" : '';
    $cta_block     = $cta       ? "\n\nCTA / BRAND SIGN-OFF (final scene):\n$cta" : '';
    $input         = "Product: $product{$product_visual}\n\nAPPROVED NARRATIVE:\n$narrative" . $add_block . $cta_block;
    $response      = callAI($apiUrl, $apiKey, $systemPrompt, $input);
    $_SESSION['script'] = $response;
}

// ── STEP 4: Regenerate suggestions ────────────────────────────
if ($step == "4" && isset($_SESSION['product'])) {
    $product     = $_SESSION['product'];
    $auto_desc   = $_SESSION['product_auto_desc'] ?? '';
    $image_block = $auto_desc ? "\nProduct Visual Description: $auto_desc" : "";

    $systemPrompt = "You are a product video strategist.
Given a product, generate a COMPLETELY DIFFERENT set of creative parameters.
Return EXACTLY this JSON:
{
  \"title\":           {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"brand_tone\":      {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\":            {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"target_customer\": {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"use_scene\":       {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\":      {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"product_hero\":    {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"desire_outcome\":  {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}";
    $additional  = $_SESSION['additional_info'] ?? '';
    $step4_input = "Product: $product{$image_block}" . ($additional ? "\nAdditional: $additional" : '');
    $raw         = callAI($apiUrl, $apiKey, $systemPrompt, $step4_input, 0.95);
    $suggestions = $raw ? json_decode($raw, true) : null;
    if ($suggestions) {
        $_SESSION['suggestions'] = $suggestions;
        unset($_SESSION['narrative'], $_SESSION['script'], $_SESSION['selected']);
    }
    $step = "1";
}

// === LOAD SESSION DATA FOR VIEW ===============================
$suggestions     = $_SESSION['suggestions']      ?? null;
$product         = $_SESSION['product']          ?? '';
$narrative       = $_SESSION['narrative']        ?? '';
$script          = $_SESSION['script']           ?? '';
$additional_info = $_SESSION['additional_info']  ?? '';
$cta_text        = $_SESSION['cta_text']         ?? '';
$product_img     = $_SESSION['product_image_name'] ?? null;
$product_auto    = $_SESSION['product_auto_desc']  ?? '';

// === PARAM CARD RENDERER =====================================
function paramCard($key, $label, $description, $icon, $data) {
    $selected  = $data['selected'] ?? '';
    $options   = $data['options']  ?? [];
    $inputId   = "sel_$key";
    echo "<div class='param-card'>";
    echo "<div class='param-header'>";
    echo "<span class='param-icon'>$icon</span>";
    echo "<div style='flex:1;'>";
    echo "<div style='display:flex; justify-content:space-between;'>";
    echo "<div class='param-label'>$label</div>";
    echo "<button type='button' class='more-opts-btn' onclick=\"moreOptions('$key')\">+ More</button>";
    echo "</div>";
    echo "<div class='param-desc'>$description</div>";
    echo "</div></div>";
    echo "<input type='hidden' name='$inputId' id='$inputId' value='" . htmlspecialchars($selected) . "'>";
    echo "<div class='selected-val' id='display_$key'>" . htmlspecialchars($selected) . "</div>";
    echo "<div class='options-row' id='opts_$key'>";
    foreach ($options as $opt) {
        echo "<button type='button' class='opt-chip' data-key='$key' data-val='" . htmlspecialchars($opt) . "' onclick=\"selectOpt(this)\">" . htmlspecialchars($opt) . "</button>";
    }
    echo "</div></div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Product Promotion Video</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;  --mid-blue: #143b63;   --accent: #5fd1ff;
  --purple: #8b5cf6;     --purple-lt: #ede9fe;
  --green: #10b981;      --green-lt: #d1fae5;
  --orange: #f59e0b;     --orange-lt: #fef3c7;
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

/* ── Page title strip ───────────────────────────────────────── */
.page-title-strip {
  background: linear-gradient(90deg, var(--dark-blue), var(--mid-blue));
  border-radius: 14px;
  padding: 20px 24px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.page-title-icon { font-size: 32px; flex-shrink: 0; }
.page-title-strip h1 { font-size: 20px; font-weight: 700; color: #fff; margin: 0 0 3px; }
.page-title-strip p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 0; }

/* ── Step progress bar ──────────────────────────────────────── */
.steps { display: flex; align-items: center; margin-bottom: 22px; padding: 14px 20px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); }
.step-item  { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; color: var(--muted); }
.step-item.active { color: var(--dark-blue); }
.step-item.done   { color: var(--green); }
.step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; border: 2px solid var(--border); background: var(--bg); color: var(--muted); }
.step-dot.active { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); border-color: var(--dark-blue); color: #fff; }
.step-dot.done   { background: var(--green); border-color: var(--green); color: #fff; }
.step-line { flex: 1; height: 2px; background: var(--border); margin: 0 8px; min-width: 20px; }
.step-line.done { background: var(--green); }

/* ── Cards ──────────────────────────────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 22px 24px; margin-bottom: 16px; box-shadow: var(--shadow); }
.card-title { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 16px; display: flex; align-items: center; gap: 6px; }
.card-title::before { content: ''; width: 3px; height: 14px; background: var(--green); border-radius: 2px; display: inline-block; }

/* ── Form elements ──────────────────────────────────────────── */
.field-label { display: block; font-size: 12px; font-weight: 700; color: var(--dark-blue); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .05em; }
.field-label .hint { font-weight: 400; color: var(--muted); text-transform: none; letter-spacing: 0; }
.input-row { display: flex; gap: 10px; }
.text-input { flex: 1; background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 11px 14px; font-size: 14px; font-family: 'Inter', sans-serif; color: var(--text); outline: none; transition: border-color .15s; }
.text-input:focus { border-color: var(--green); }
textarea.text-input { resize: vertical; }

/* ── Buttons ────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; font-family: 'Inter', sans-serif; transition: all .15s; white-space: nowrap; }
.btn-primary { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; }
.btn-primary:hover { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.btn-green   { background: linear-gradient(135deg, #059669, var(--green)); color: #fff; }
.btn-green:hover { box-shadow: 0 4px 12px rgba(16,185,129,.3); }
.btn-orange  { background: linear-gradient(135deg, #d97706, var(--orange)); color: #fff; }
.btn-blue    { background: linear-gradient(135deg, #1d4ed8, #3b82f6); color: #fff; }
.btn-gray    { background: var(--bg); color: var(--muted); border: 1.5px solid var(--border); }
.btn-gray:hover { border-color: var(--purple); color: var(--purple); }
.btn-red     { background: linear-gradient(135deg, #dc2626, #f87171); color: #fff; }
.btn-purple  { background: linear-gradient(135deg, var(--purple), #a78bfa); color: #fff; }
.btn:disabled { opacity: .55; cursor: not-allowed; box-shadow: none !important; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

/* ── Param cards (Step 2 grid) ──────────────────────────────── */
.params-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media(max-width:620px) { .params-grid { grid-template-columns: 1fr; } }
.param-card   { background: var(--bg); border: 1.5px solid var(--border); border-radius: 12px; padding: 14px; transition: border-color .15s; }
.param-card:focus-within { border-color: var(--green); }
.param-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.param-icon   { font-size: 20px; flex-shrink: 0; }
.param-label  { font-size: 11px; font-weight: 700; color: var(--dark-blue); text-transform: uppercase; letter-spacing: .06em; }
.param-desc   { font-size: 11px; color: var(--muted); margin-top: 2px; }
.selected-val { background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600; margin-bottom: 10px; min-height: 36px; }
.options-row  { display: flex; gap: 6px; flex-wrap: wrap; }
.opt-chip     { background: var(--card); border: 1.5px solid var(--border); padding: 5px 11px; border-radius: 99px; font-size: 11px; cursor: pointer; transition: all .15s; color: var(--text); font-family: 'Inter', sans-serif; }
.opt-chip:hover  { border-color: var(--green); color: #059669; background: var(--green-lt); }
.opt-chip.picked { border-color: var(--green); color: #059669; background: var(--green-lt); font-weight: 600; }
.more-opts-btn { background: var(--bg); border: 1.5px dashed var(--border); padding: 2px 10px; border-radius: 99px; font-size: 10px; font-weight: 600; cursor: pointer; color: var(--muted); font-family: 'Inter', sans-serif; transition: all .15s; }
.more-opts-btn:hover { border-color: var(--green); color: var(--green); }

/* ── Output box (narrative / script) ───────────────────────── */
.output-box { background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 18px; white-space: pre-wrap; font-size: 13px; line-height: 1.7; max-height: 600px; overflow-y: auto; margin-top: 14px; color: var(--text); }

/* ── Provider selector ──────────────────────────────────────── */
.provider-selector { background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 8px 16px; display: inline-flex; align-items: center; gap: 14px; font-size: 13px; font-weight: 600; color: var(--text); }

/* ── Upload zone ────────────────────────────────────────────── */
.upload-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 22px; text-align: center; cursor: pointer; transition: all .15s; background: var(--bg); position: relative; }
.upload-zone:hover { border-color: var(--green); background: var(--green-lt); }
.upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.upload-zone .upload-label { font-size: 13px; color: var(--dark-blue); font-weight: 600; }
.upload-zone .upload-sub   { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* ── Product preview strip ──────────────────────────────────── */
.product-preview { display: flex; align-items: center; gap: 14px; background: var(--green-lt); border: 1.5px solid #6ee7b7; border-radius: 12px; padding: 12px 16px; margin-top: 12px; }
.product-preview img { width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
.product-preview .preview-info { flex: 1; }
.product-preview .preview-name { font-size: 13px; font-weight: 600; color: #065f46; }
.product-preview .preview-desc { font-size: 11px; color: var(--muted); margin-top: 3px; line-height: 1.4; }

/* ── Vision badge ───────────────────────────────────────────── */
.vision-badge { display: inline-block; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 99px; margin-left: 6px; vertical-align: middle; }

/* ── Progress bar (storyboard) ──────────────────────────────── */
.progress-track { background: var(--border); border-radius: 99px; height: 8px; }
.progress-fill  { height: 100%; background: linear-gradient(90deg, var(--green), #34d399); border-radius: 99px; transition: width .3s; }

/* ── Spinner ────────────────────────────────────────────────── */
/* ── Company card ───────────────────────────────────────────── */
.company-card {
  display: flex; align-items: flex-start; gap: 16px;
  background: var(--card); border: 1px solid var(--border);
  border-left: 4px solid var(--green); border-radius: 12px;
  padding: 14px 18px; margin-bottom: 16px; box-shadow: var(--shadow);
}
.company-logo {
  width: 50px; height: 50px; border-radius: 10px; flex-shrink: 0;
  background: var(--green-lt); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 700; color: #065f46; overflow: hidden;
}
.company-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 9px; }
.company-info { flex: 1; min-width: 0; }
.company-name  { font-size: 15px; font-weight: 700; color: var(--dark-blue); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.company-brand { font-size: 11px; font-weight: 600; color: #065f46; background: var(--green-lt); border-radius: 99px; padding: 2px 8px; display: inline-block; margin-bottom: 5px; }
.company-desc  { font-size: 12px; color: var(--muted); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.company-meta  { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 5px; }
.company-meta span { font-size: 11px; color: var(--muted); display: flex; align-items: center; gap: 3px; }
/* Switcher */
.company-switcher { position: relative; flex-shrink: 0; align-self: center; }
.company-switch-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  background: var(--green-lt); color: #065f46;
  border: 1.5px solid #6ee7b7; cursor: pointer; font-family: 'Inter', sans-serif;
  transition: all .15s; white-space: nowrap;
}
.company-switch-btn:hover { background: #a7f3d0; }
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
.co-item:hover  { background: var(--green-lt); color: #065f46; }
.co-item.active { background: var(--green-lt); color: #065f46; font-weight: 600; }
.co-check { color: var(--green); font-size: 12px; }

@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 5px; }
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
  <span class="page-title-icon">📦</span>
  <div>
    <h1>Product Promotion Video</h1>
    <p>Upload your product → AI builds a cinematic script → generate scene images</p>
  </div>
</div>

<?php if ($company): ?>
<!-- ── Company card ─────────────────────────────────────────────── -->
<div class="company-card">
  <div class="company-logo">
    <?php if ($co_logo && file_exists($co_logo)): ?>
      <img src="<?= $co_logo ?>" alt="<?= $co_name ?>">
    <?php else: ?>
      <?= mb_strtoupper(mb_substr(strip_tags($co_name), 0, 1)) ?: '📦' ?>
    <?php endif; ?>
  </div>
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
<?php endif; ?>

<!-- ── Step progress ───────────────────────────────────────────── -->
<div class="steps">
    <div class="step-item <?= $step=='0'?'active':($step>='1'?'done':'') ?>">
        <div class="step-dot <?= $step=='0'?'active':($step>='1'?'done':'') ?>">1</div><span>Product</span>
    </div>
    <div class="step-line <?= $step>='1'?'done':'' ?>"></div>
    <div class="step-item <?= $step=='1'?'active':($step>='2'?'done':'') ?>">
        <div class="step-dot <?= $step=='1'?'active':($step>='2'?'done':'') ?>">2</div><span>Parameters</span>
    </div>
    <div class="step-line <?= $step>='2'?'done':'' ?>"></div>
    <div class="step-item <?= $step=='2'?'active':($step>='3'?'done':'') ?>">
        <div class="step-dot <?= $step=='2'?'active':($step>='3'?'done':'') ?>">3</div><span>Narrative</span>
    </div>
    <div class="step-line <?= $step>='3'?'done':'' ?>"></div>
    <div class="step-item <?= $step=='3'?'active':'' ?>">
        <div class="step-dot <?= $step=='3'?'active':'' ?>">4</div><span>Script</span>
    </div>
</div>

<!-- ═══════════ STEP 1: PRODUCT INPUT ════════════════════ -->
<div class="card">
    <div class="card-title">Step 1 — Your Product</div>


    <!-- Product Image Upload -->
    <div style="margin-bottom:16px;">
        <label style="font-size:11px; font-weight:700; display:block; margin-bottom:8px; text-transform:uppercase; letter-spacing:.05em; color:var(--dark-blue);">📸 Product Image <span style="color:var(--muted); font-weight:400; text-transform:none;">(optional — helps AI match your product's look)</span></label>

        <?php if ($product_img): ?>
        <div class="product-preview" id="productPreview">
            <img src="<?= htmlspecialchars($_SESSION['product_image_path'] ?? '') ?>" alt="Product" id="previewImg">
            <div class="preview-info">
                <div class="preview-name">✅ <?= htmlspecialchars($product_img) ?> <span class="vision-badge">👁 Vision-enhanced</span></div>
                <?php if ($product_auto): ?>
                <div class="preview-desc"><?= htmlspecialchars(substr($product_auto, 0, 180)) ?>…</div>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-red" style="padding:6px 12px; font-size:11px;" onclick="clearProductImage()">✕ Remove</button>
        </div>
        <?php else: ?>
        <div class="upload-zone" id="uploadZone">
            <input type="file" id="productImageInput" accept="image/jpeg,image/png,image/webp,image/gif" onchange="uploadProductImage(this)">
            <div class="upload-label">📦 Click or drag your product image here</div>
            <div class="upload-sub">JPG, PNG, WEBP, GIF — max 10MB</div>
        </div>
        <div id="uploadStatus" style="font-size:12px; color:var(--green); margin-top:8px; display:none;"></div>
        <div id="productPreview" style="display:none;" class="product-preview">
            <img id="previewImg" src="" alt="Product" style="width:64px;height:64px;object-fit:cover;border-radius:8px;">
            <div class="preview-info">
                <div class="preview-name" id="previewName"></div>
                <div class="preview-desc" id="previewDesc"></div>
            </div>
            <button type="button" class="btn btn-red" style="padding:6px 12px; font-size:11px;" onclick="clearProductImage()">✕ Remove</button>
        </div>
        <?php endif; ?>
    </div>

    <form method="POST">
        <input type="hidden" name="step" value="1">
        <div class="input-row">
            <input class="text-input" type="text" name="product" value="<?= htmlspecialchars($product) ?>" placeholder="e.g. Organic cold-press turmeric ginger shots">
            <button type="submit" class="btn btn-primary">✨ Get AI Suggestions</button>
        </div>
        <div style="margin-top:12px;">
            <label class="field-label">💡 Additional Instructions / Key Benefits</label>
            <textarea name="additional_info" rows="2" class="text-input" style="width:100%; margin-top:4px;"><?= htmlspecialchars($additional_info) ?></textarea>
        </div>
        <div style="margin-top:12px;">
            <label class="field-label">📣 CTA / Brand Sign-Off</label>
            <textarea name="cta_text" rows="2" class="text-input" style="width:100%; margin-top:4px;"><?= htmlspecialchars($cta_text) ?></textarea>
        </div>
    </form>
</div>

<!-- ═══════════ STEP 2: PARAMETERS ════════════════════════ -->
<?php if ($suggestions && $step >= 1): ?>
<form method="POST" id="paramsForm">
    <input type="hidden" name="step" value="2">
    <div class="card">
        <div class="card-title">Step 2 — AI Suggested Parameters <span style="font-weight:400; text-transform:none; letter-spacing:0; color:var(--muted);">— click any chip to select</span></div>
        <div class="params-grid">
            <?php paramCard('title',           'Title',           'Short catchy video title',                       '🎬', $suggestions['title']           ?? []); ?>
            <?php paramCard('brand_tone',      'Brand Tone',      'Emotion and energy of the brand',                '💎', $suggestions['brand_tone']      ?? []); ?>
            <?php paramCard('hook',            'Hook',            'Opening line that grabs attention in 2 seconds', '🎯', $suggestions['hook']            ?? []); ?>
            <?php paramCard('target_customer', 'Target Customer', 'Who is holding / using the product',             '👤', $suggestions['target_customer'] ?? []); ?>
            <?php paramCard('use_scene',       'Usage Scene',     'Where and how the product is used',              '🌆', $suggestions['use_scene']       ?? []); ?>
            <?php paramCard('audio_mood',      'Audio Mood',      'Music style and feel',                           '🎵', $suggestions['audio_mood']      ?? []); ?>
            <?php paramCard('product_hero',    'Product Hero',    'The key product shot or standout feature',       '⭐', $suggestions['product_hero']    ?? []); ?>
            <?php paramCard('desire_outcome',  'Desire Outcome',  'What the viewer craves after watching',          '❤️', $suggestions['desire_outcome']  ?? []); ?>
        </div>
        <div class="actions">
            <button type="submit" class="btn btn-green">📖 Generate Product Narrative</button>
            <button type="button" class="btn btn-orange" onclick="freshSuggestions()">🔀 More Ideas</button>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</form>
<form method="POST" id="freshForm">
    <input type="hidden" name="step" value="4">
    <input type="hidden" name="product" value="<?= htmlspecialchars($product) ?>">
    <input type="hidden" name="additional_info" value="<?= htmlspecialchars($additional_info) ?>">
    <input type="hidden" name="cta_text" value="<?= htmlspecialchars($cta_text) ?>">
</form>
<?php endif; ?>

<!-- ═══════════ STEP 3: NARRATIVE ═════════════════════════ -->
<?php if ($narrative && $step >= 2): ?>
<div class="card">
    <div class="card-title">Step 3 — Product Narrative</div>
    <div class="output-box"><?= nl2br(htmlspecialchars($narrative)) ?></div>
    <div class="actions">
        <form method="POST">
            <input type="hidden" name="step" value="3">
            <button type="submit" class="btn btn-green">🎥 Generate Full Script</button>
        </form>
        <form method="POST">
            <input type="hidden" name="step" value="2">
            <?php if (isset($_SESSION['selected'])) foreach ($_SESSION['selected'] as $k => $v) echo '<input type="hidden" name="sel_'.$k.'" value="'.htmlspecialchars($v).'">'; ?>
            <button type="submit" class="btn btn-orange">🔁 Regenerate Narrative</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════ STEP 4: FULL SCRIPT + IMAGE GEN ═══════════ -->
<?php if ($script && $step >= 3): ?>
<div class="card">
    <div class="card-title">
        Step 4 — Full Product Video Script
        <?php if ($product_img): ?><span class="vision-badge">👁 Vision-enhanced scenes</span><?php endif; ?>
    </div>
    <div class="output-box" id="scriptBox"><?= nl2br(htmlspecialchars($script)) ?></div>

    <!-- ── Credits notice ────────────────────────── -->
    <?php if (!$has_enough_credits) { ?>

        <?php if ($plan_type == 'free_trial') { ?>
        <!-- FREE TRIAL: show subscription options only -->
        <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);border-radius:16px;padding:24px 28px;margin-top:18px;text-align:center;">
            <div style="font-size:32px;margin-bottom:12px;">🚀</div>
            <div style="font-size:17px;font-weight:800;color:#fff;margin-bottom:10px;">You've reached your free trial limit.</div>
            <div style="font-size:13px;color:rgba(255,255,255,.78);line-height:1.65;margin-bottom:18px;">
                Thanks for trying VideoVizard! Choose a subscription plan to keep creating with unlimited generations, no watermarks, and full workspace features.
            </div>
            <a href="/pricing_free_trial.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(59,130,246,.35);">
                View Subscription Plans →
            </a>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:12px;">Free Trial &middot; No credits remaining</div>
        </div>

        <?php } else if ($plan_type == 'agency') { ?>
        <!-- AGENCY: top plan, Pay As You Go top-up only -->
        <div style="background:linear-gradient(135deg,#064e3b,#065f46);border-radius:16px;padding:24px 28px;margin-top:18px;text-align:center;">
            <div style="font-size:32px;margin-bottom:12px;">⚡</div>
            <div style="font-size:17px;font-weight:800;color:#fff;margin-bottom:10px;">You've run out of credits.</div>
            <div style="font-size:13px;color:rgba(255,255,255,.78);line-height:1.65;margin-bottom:18px;">
                You're on our Agency plan. Top up instantly with a Pay As You Go credit pack &mdash; your subscription stays unchanged.
            </div>
            <a href="/pricing_agency.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(34,197,94,.35);">
                Buy Credit Pack →
            </a>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:12px;">Agency Plan &middot; No credits remaining</div>
        </div>

        <?php } else { ?>
        <!-- PERSONAL: two options - Pay As You Go or upgrade to Agency -->
        <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);border-radius:16px;padding:24px 28px;margin-top:18px;text-align:center;">
            <div style="font-size:32px;margin-bottom:12px;">💳</div>
            <div style="font-size:17px;font-weight:800;color:#fff;margin-bottom:10px;">You've run out of credits.</div>
            <div style="font-size:13px;color:rgba(255,255,255,.78);line-height:1.65;margin-bottom:18px;">
                You're on the Personal plan. Top up instantly with Pay As You Go credits, or upgrade to Agency for 10&times; the monthly volume.
            </div>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="/pricing_personal.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(34,197,94,.3);">
                    Buy Credits (Pay As You Go) →
                </a>
                <a href="/pricing_personal.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block;background:rgba(255,255,255,.15);color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;border:1.5px solid rgba(255,255,255,.3);">
                    Upgrade to Agency →
                </a>
            </div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:12px;">Personal Plan &middot; No credits remaining</div>
        </div>

        <?php } ?>

    <?php } else { ?>
    <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #fde68a;border-radius:12px;padding:14px 18px;margin-top:18px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:24px;flex-shrink:0;">💳</span>
        <div>
            <div style="font-size:13px;font-weight:700;color:#92400e;">This video will use <?php echo $credits_required; ?> credits. <span style="font-weight:500;">(You have <?php echo $user_credit_balance; ?>)</span></div>
            <div style="font-size:12px;color:#b45309;margin-top:3px;">Image generation happens immediately. Video rendering runs in the background — you can close this page and check back later.</div>
        </div>
    </div>
    <?php } ?>

    <div class="actions" style="justify-content:space-between;flex-wrap:wrap;margin-top:20px;">
        <!-- Generation mode selector -->
        <div id="genModeSelector" style="width:100%;margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">🎬 Choose Generation Mode</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <label id="modeFastLabel" style="cursor:pointer;">
                    <input type="radio" name="gen_mode" value="fal.ai" id="modeFast" style="display:none;" onchange="updateModeUI()">
                    <div id="modeFastCard" style="border:2px solid var(--border);border-radius:14px;padding:16px;background:#fff;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">⚡</span>
                            <div><div style="font-size:14px;font-weight:800;color:#0f2a44;">Fast Mode</div>
                            <div style="font-size:10px;color:#64748b;">via Fal.ai</div></div>
                            <div style="margin-left:auto;background:#f59e0b;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;">6 credits</div>
                        </div>
                        <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Fastest generation. Best for quick previews.</div>
                        <div style="background:#fef3c7;border-radius:8px;padding:8px 10px;font-size:11px;color:#92400e;">
                            ✨ ETA: <strong id="fastEta">calculating...</strong>
                        </div>
                    </div>
                </label>
                <label id="modeStandardLabel" style="cursor:pointer;">
                    <input type="radio" name="gen_mode" value="modal.com" id="modeStandard" checked style="display:none;" onchange="updateModeUI()">
                    <div id="modeStandardCard" style="border:2px solid #3b82f6;border-radius:14px;padding:16px;background:linear-gradient(135deg,#eff6ff,#dbeafe);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">🎯</span>
                            <div><div style="font-size:14px;font-weight:800;color:#0f2a44;">Standard Mode</div>
                            <div style="font-size:10px;color:#64748b;">via Modal.com</div></div>
                            <div style="margin-left:auto;background:#3b82f6;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;">4 credits</div>
                        </div>
                        <div style="font-size:11px;color:#1e40af;margin-bottom:8px;">Higher quality. Best for final production.</div>
                        <div style="background:#dbeafe;border-radius:8px;padding:8px 10px;font-size:11px;color:#1e40af;">
                            ⏱ ETA: <strong id="standardEta">calculating...</strong>
                        </div>
                    </div>
                </label>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-blue" onclick="copyScript()">📋 Copy Script</button>
            <button class="btn btn-green" onclick="generateAllScenes()" id="btnGenScenes" <?= !$has_enough_credits ? 'disabled title="Not enough credits"' : '' ?>>🎬 Generate Scene Images</button>
            <button class="btn btn-gray" onclick="debugScript()">🔍 Debug Script</button>
            <button type="button" class="btn btn-gray" onclick="warmupModalManually()" title="Costs 1 Modal GPU call — only use if container is cold">🔥 Pre-warm Modal</button>
            <button type="button" class="btn btn-gray" onclick="testModalRaw()" id="btnTestModal">🧪 Test Modal</button>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</div>

<!-- Storyboard -->
<div id="storyboardSection" style="display:none;margin-top:20px;">
    <div class="card">
        <div class="card-title">🎞️ Product Scene Storyboard</div>
        <div id="queueStatusPanel" style="display:none;margin-bottom:16px;">
            <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);border-radius:14px;padding:16px 20px;margin-bottom:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;">🖼️</span>
                        <div><div style="font-size:13px;font-weight:800;color:#fff;">Image Generation</div>
                        <div style="font-size:11px;color:rgba(255,255,255,.65);margin-top:2px;" id="imgQueueDetail">Calculating queue...</div></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:20px;font-weight:900;color:#5fc3ff;" id="imgEtaDisplay">--</div>
                        <div style="font-size:10px;color:rgba(255,255,255,.5);">estimated wait</div>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.15);border-radius:6px;height:6px;margin-top:12px;overflow:hidden;">
                    <div id="imgQueueBar" style="background:linear-gradient(90deg,#3b82f6,#5fc3ff);height:100%;width:0%;border-radius:6px;transition:width .5s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:5px;">
                    <span style="font-size:9px;color:rgba(255,255,255,.4);" id="imgQueueProgress">0 / 0 done</span>
                    <span style="font-size:9px;color:rgba(255,255,255,.4);" id="imgPollStatus">Polling every 30s…</span>
                </div>
            </div>
            <div style="background:linear-gradient(135deg,#064e3b,#065f46);border-radius:14px;padding:16px 20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;">🎬</span>
                        <div><div style="font-size:13px;font-weight:800;color:#fff;">Video Generation</div>
                        <div style="font-size:11px;color:rgba(255,255,255,.65);margin-top:2px;" id="vidQueueDetail">Starts after images are ready</div></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:20px;font-weight:900;color:#6ee7b7;" id="vidEtaDisplay">--</div>
                        <div style="font-size:10px;color:rgba(255,255,255,.5);">estimated wait</div>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.15);border-radius:6px;height:6px;margin-top:12px;overflow:hidden;">
                    <div id="vidQueueBar" style="background:linear-gradient(90deg,#22c55e,#6ee7b7);height:100%;width:0%;border-radius:6px;transition:width .5s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:5px;">
                    <span style="font-size:9px;color:rgba(255,255,255,.4);" id="vidQueueProgress">0 / 0 done</span>
                    <span style="font-size:9px;color:rgba(255,255,255,.4);">~3 min per video</span>
                </div>
            </div>
        </div>
        <div id="sceneProgress" style="display:none;margin-bottom:14px;">
            <div class="progress-track"><div id="sceneProgressBar" class="progress-fill" style="width:0%;"></div></div>
            <div style="font-size:11px;margin-top:6px;color:var(--muted);" id="sceneProgressLabel">Queuing scenes...</div>
        </div>
        <div id="storyboardGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;"></div>
    </div>
</div>
<script>const FULL_SCRIPT = <?= json_encode($script) ?>;</script>
<?php endif; ?>

</div><!-- /.container -->
</div><!-- /.page-wrap -->

<script>
// ── UI helpers ────────────────────────────────────────────────
function btnLoading(btn, text) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span>' + text; }
function btnReset(btn) { btn.disabled = false; btn.innerHTML = btn.dataset.orig || btn.innerHTML; }

function selectOpt(btn) {
    const key = btn.dataset.key, val = btn.dataset.val;
    document.getElementById('sel_' + key).value = val;
    document.getElementById('display_' + key).textContent = val;
    document.querySelectorAll('#opts_' + key + ' .opt-chip').forEach(c => c.classList.toggle('picked', c === btn));
}

function freshSuggestions() {
    const btn = document.querySelector('[onclick="freshSuggestions()"]');
    if (btn) btnLoading(btn, 'Getting ideas…');
    document.getElementById('freshForm').submit();
}

// ── Product image upload ──────────────────────────────────────
async function uploadProductImage(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const status = document.getElementById('uploadStatus');
    status.style.display = 'block';
    status.textContent = '⏳ Uploading and analysing product image…';

    const fd = new FormData();
    fd.append('ajax_action', 'upload_product_image');
    fd.append('product_image', file);

    try {
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            // Show preview
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('previewImg').src = e.target.result;
                document.getElementById('previewName').innerHTML = '✅ ' + data.name + ' <span class="vision-badge">👁 Vision-enhanced</span>';
                document.getElementById('previewDesc').textContent = data.auto_desc ? data.auto_desc.substring(0, 180) + '…' : '';
                document.getElementById('productPreview').style.display = 'flex';
                document.getElementById('uploadZone').style.display = 'none';
                status.style.display = 'none';
            };
            reader.readAsDataURL(file);
        } else {
            status.textContent = '❌ ' + (data.error || 'Upload failed');
        }
    } catch(e) {
        status.textContent = '❌ Upload error — please try again';
    }
}

async function clearProductImage() {
    const fd = new FormData();
    fd.append('ajax_action', 'clear_product_image');
    await fetch('', { method: 'POST', body: fd });
    document.getElementById('productPreview').style.display = 'none';
    document.getElementById('uploadZone').style.display = 'block';
    const status = document.getElementById('uploadStatus');
    if (status) { status.style.display = 'none'; status.textContent = ''; }
    const input = document.getElementById('productImageInput');
    if (input) input.value = '';
}

// ── More options (AJAX) ───────────────────────────────────────
async function moreOptions(key) {
    const moreBtn = document.getElementById('more_btn_' + key);
    const optsRow = document.getElementById('opts_' + key);
    if (!optsRow) return;
    const existing = Array.from(optsRow.querySelectorAll('.opt-chip')).map(c => c.textContent.trim()).join(' | ');
    const btn = document.querySelector(`[onclick="moreOptions('${key}')"]`);
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'more_options');
        fd.append('field', key);
        fd.append('product', document.querySelector('[name=product]')?.value || '');
        fd.append('existing', existing);
        fd.append('additional', document.querySelector('[name=additional_info]')?.value || '');
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success && data.options) {
            data.options.forEach(opt => {
                if (Array.from(optsRow.querySelectorAll('.opt-chip')).some(c => c.textContent.trim().toLowerCase() === opt.toLowerCase())) return;
                const chip = document.createElement('button');
                chip.type = 'button'; chip.className = 'opt-chip';
                chip.dataset.key = key; chip.dataset.val = opt;
                chip.textContent = opt; chip.onclick = function() { selectOpt(this); };
                optsRow.appendChild(chip);
            });
        }
    } catch(e) { /* silent */ }
    if (btn) { btn.textContent = '+ More'; btn.disabled = false; }
}

// ── Script tools ──────────────────────────────────────────────
function debugScript() {
    if (typeof FULL_SCRIPT === 'undefined') { alert('No script found'); return; }
    const scenes = parseScenes(FULL_SCRIPT);
    alert('Found ' + scenes.length + ' scenes in script');
}

function copyScript() {
    const box = document.getElementById('scriptBox');
    if (!box) return;
    navigator.clipboard.writeText(box.innerText).then(() => {
        const btn = event.currentTarget; const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copied!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

// ── Scene parser ──────────────────────────────────────────────
function parseScenes(scriptText) {
    const scenes = [], markers = [];
    const re = /(?:#{1,3}\s*)?(?:\*{1,2})?\bSCENE\s+(\d+)\b(?:\*{1,2})?[^\n]*/gi;
    let m;
    while ((m = re.exec(scriptText)) !== null) markers.push({ index: m.index, num: parseInt(m[1]), header: m[0] });
    if (!markers.length) return scenes;
    for (let i = 0; i < markers.length; i++) {
        const start = markers[i].index + markers[i].header.length;
        const end   = i + 1 < markers.length ? markers[i+1].index : scriptText.length;
        const block = scriptText.substring(start, end);
        const num   = markers[i].num;

        let wanPrompt = '';
        const wanRe = /WAN\s*2\.2[\s\S]*?PROMPT\s*[:\n]([\s\S]+?)(?=CAMERA|LIGHTING|EMOTIONAL|ON.?SCREEN|##|$)/i;
        let wm = block.match(wanRe);
        if (wm && wm[1].trim().length > 15) wanPrompt = wm[1].trim().replace(/\n+/g, ' ').substring(0, 1200);
        if (!wanPrompt || wanPrompt.length < 20) wanPrompt = block.replace(/#{1,3}|\*{1,2}/g, '').replace(/\n+/g, ' ').trim().substring(0, 800);

        let caption = '';
        const capRe = /ON[.\s\-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*([^\n]+)/i;
        const capM  = block.match(capRe);
        if (capM) caption = capM[1].trim().replace(/^(NONE|none|-|N\/A)$/i, '').trim();

        let title = markers[i].header.replace(/#{1,3}|\*{1,2}|\bSCENE\s*\d+\b/gi,'').replace(/[—\-–:]/g,'').trim();
        if (!title) title = block.trim().split('\n')[0].substring(0,60) || ('Scene ' + num);

        scenes.push({ num, title, wan: wanPrompt, caption });
    }
    return scenes;
}

// ── Generate all scene images ─────────────────────────────────
// -- Generation mode UI + ETA ------------------------------------------
function updateModeUI() {
    const mode = document.querySelector('input[name="gen_mode"]:checked')?.value || 'modal.com';
    const fc = document.getElementById('modeFastCard');
    const sc = document.getElementById('modeStandardCard');
    if (!fc || !sc) return;
    if (mode === 'fal.ai') {
        fc.style.border = '2px solid #f59e0b'; fc.style.background = 'linear-gradient(135deg,#fffbeb,#fef3c7)';
        sc.style.border = '2px solid var(--border)'; sc.style.background = '#fff';
    } else {
        sc.style.border = '2px solid #3b82f6'; sc.style.background = 'linear-gradient(135deg,#eff6ff,#dbeafe)';
        fc.style.border = '2px solid var(--border)'; fc.style.background = '#fff';
    }
}

async function loadModeEtas() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_scene_count');
        fd.append('scene_count', '6');
        const resp = await fetch('', { method:'POST', body:fd });
        const d    = await resp.json();
        if (!d.success) return;
        const fastEl = document.getElementById('fastEta');
        const stdEl  = document.getElementById('standardEta');
        if (fastEl) fastEl.textContent =
            '🖼️ Images: ' + d.fast_img_label + ' (' + d.scene_count + ' @ ~8s each)'
            + '  •  🎬 Videos: ' + d.fast_vid_label + ' (' + d.scene_count + ' @ ~1 min each via fal.ai)';
        if (stdEl)  stdEl.textContent  =
            '🖼️ Images: ' + d.std_img_label + ' (' + d.scene_count + ' @ ~25s + cold start)'
            + '  •  🎬 Videos: ' + d.std_vid_label + ' (' + d.scene_count + ' @ ~3 min each via Modal)';
    } catch(e) {
        const fastEl = document.getElementById('fastEta');
        const stdEl  = document.getElementById('standardEta');
        if (fastEl) fastEl.textContent = 'unable to calculate';
        if (stdEl)  stdEl.textContent  = 'unable to calculate';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadModeEtas();
    updateModeUI();
    document.querySelectorAll('input[name="gen_mode"]').forEach(function(r) {
        r.addEventListener('change', function() { updateModeUI(); loadModeEtas(); });
    });
});

async function generateAllScenes() {
    if (typeof FULL_SCRIPT === 'undefined') return;
    const scenes = parseScenes(FULL_SCRIPT);
    if (!scenes.length) { alert('No scenes found. Check script format.'); return; }

    const btn = document.getElementById('btnGenScenes');
    btnLoading(btn, 'Processing…');

    // Step 1: Deduct credits
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'deduct_cinematic_credits');
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        if (!data.success) { btnReset(btn); alert('Could not deduct credits: ' + (data.message || 'Unknown error')); return; }
    } catch(e) { btnReset(btn); alert('Credit deduction error: ' + e.message); return; }

    // Step 2: Show storyboard + placeholder cards
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
                <div id="scene-status-${sc.num}" style="font-size:10px;color:#94a3b8;text-align:center;">Queuing…</div>
            </div>
            <div style="padding:8px 10px;">
                <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sc.num}</div>
                <div style="font-size:10px;color:#64748b;margin-top:2px;">${(sc.caption||sc.title||'').substring(0,45)}</div>
            </div>`;
        grid.appendChild(card);
    });
    document.getElementById('sceneProgressBar').style.width = '2%';
    document.getElementById('sceneProgressLabel').textContent = 'Starting up…';
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    await new Promise(r => setTimeout(r, 50));

    // Step 3: Save podcast shell to DB
    document.getElementById('sceneProgressLabel').textContent = 'Creating podcast record in DB…';
    let podcastId  = 0;
    let storyIdMap = {};
    try {
        let titleVal = '';
        if (typeof FULL_SCRIPT !== 'undefined') {
            const tm = FULL_SCRIPT.match(/##\s*1\.\s*TITLE[^\n]*\n([^\n]+)/i);
            if (tm) titleVal = tm[1].trim();
        }
        const productVal = document.querySelector('[name=product]')?.value || '';
        const shellScenes = scenes.map(sc => ({ num: sc.num, wan: sc.wan, caption: sc.caption, file: '' }));
        const fd0 = new FormData();
        fd0.append('ajax_action', 'save_cinematic_to_db');
        fd0.append('scenes',  JSON.stringify(shellScenes));
        fd0.append('title',   titleVal);
        fd0.append('product', productVal);
        const r0 = await fetch('', { method:'POST', body:fd0 });
        const d0 = await r0.json();
        if (d0.success) {
            podcastId  = d0.podcast_id   || 0;
            storyIdMap = d0.story_id_map || {};
            _storyIdMap = storyIdMap;
            document.getElementById('sceneProgressLabel').textContent = `Podcast #${podcastId} created — queuing ${scenes.length} scenes…`;
        } else {
            document.getElementById('sceneProgressLabel').textContent = '⚠️ DB record failed — queuing without DB link';
        }
    } catch(e) {
        document.getElementById('sceneProgressLabel').textContent = '⚠️ DB error — queuing anyway';
    }

    // Step 4: Queue all scenes
    let completed = 0, successCount = 0;

    function updateProgress() {
        const pct = 5 + Math.round((completed / scenes.length) * 85);
        document.getElementById('sceneProgressBar').style.width = pct + '%';
        document.getElementById('sceneProgressLabel').textContent =
            `Queuing… ${completed}/${scenes.length} queued  ✅ ${successCount}  ❌ ${completed - successCount}`;
    }

    function renderCard(sc, data) {
        const card = document.getElementById('scene-card-' + sc.num);
        if (!card) return;
        if (data && data.success) {
            card.innerHTML = `
                <div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f0fdf4,#dcfce7);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;gap:8px;">
                    <div style="font-size:28px;">✅</div>
                    <div style="font-size:12px;font-weight:800;color:#166534;text-align:center;">Scene ${sc.num} Queued</div>
                    <div style="font-size:10px;color:#15803d;text-align:center;line-height:1.5;">Image &amp; video generation queued.<br>Check back in a moment.</div>
                    <div style="font-size:9px;color:#16a34a;background:rgba(22,163,74,.1);padding:3px 8px;border-radius:12px;margin-top:4px;">
                        🖼️ img: ${data.img_queued ? 'queued' : 'already in queue'} &nbsp;•&nbsp; 🎬 vid: ${data.vid_queued ? 'queued' : 'already in queue'}
                    </div>
                </div>
                <div style="padding:8px 10px;">
                    <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${sc.num}</div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">${(sc.caption||sc.title||'').substring(0,45)}</div>
                </div>`;
        } else {
            const stage  = data?.fail_stage || 'unknown';
            const reason = data?.error       || 'Unknown error';
            const storyId = storyIdMap[String(sc.num)] || storyIdMap[sc.num] || 0;
            card.innerHTML = `
                <div style="aspect-ratio:9/16;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fef2f2;gap:6px;padding:14px;text-align:center;">
                    <span style="font-size:26px;">❌</span>
                    <div style="font-size:11px;font-weight:700;color:#dc2626;">Scene ${sc.num} failed</div>
                    <div style="font-size:10px;color:#7f1d1d;background:#fee2e2;padding:4px 8px;border-radius:6px;width:100%;word-break:break-word;">
                        <strong>Stage:</strong> ${stage}<br>${reason.substring(0,80)}
                    </div>
                    <button onclick="retryScene(${sc.num},'${encodeURIComponent(sc.wan)}','${encodeURIComponent(sc.caption||'')}','${encodeURIComponent(sc.title||'')}',${podcastId},${storyId})"
                        style="padding:6px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;margin-top:2px;">
                        🔁 Retry
                    </button>
                </div>`;
        }
    }

    async function genScene(sc) {
        const fd = new FormData();
        fd.append('ajax_action',  'generate_scene_image');
        fd.append('wan_prompt',   sc.wan);
        fd.append('video_prompt', sc.video_prompt || sc.wan);
        fd.append('gen_mode',     document.querySelector('input[name="gen_mode"]:checked')?.value || 'modal.com');
        fd.append('caption',      sc.caption || '');
        fd.append('scene_num',    sc.num);
        fd.append('podcast_id',   podcastId);
        fd.append('story_id',     storyIdMap[String(sc.num)] || storyIdMap[sc.num] || 0);
        try {
            const resp = await fetch('', { method:'POST', body:fd });
            const data = await resp.json();
            renderCard(sc, data);
            completed++;
            if (data.success) {
                successCount++;
                if (!_pollingStarted && podcastId) {
                    _pollingStarted = true;
                    document.getElementById('queueStatusPanel').style.display = 'block';
                    fetchQueueStatus(podcastId, true);
                    startQueuePolling(podcastId);
                }
            }
            updateProgress();
            return data.success;
        } catch(e) {
            renderCard(sc, { success:false, error:e.message, fail_stage:'network' });
            completed++;
            updateProgress();
            return false;
        }
    }

    // Queue all scenes sequentially (no parallel - just writing to DB)
    for (let i = 0; i < scenes.length; i++) {
        await genScene(scenes[i]);
    }

    // Step 5: Final summary
    document.getElementById('sceneProgressBar').style.width = '100%';
    const failCount  = scenes.length - successCount;
    const editorLink = podcastId ? ` <a href="podcast_edit.php?id=${podcastId}" style="color:#f59e0b;font-weight:700;text-decoration:none;">Open in Editor →</a>` : '';
    document.getElementById('sceneProgressLabel').innerHTML =
        `✅ ${successCount}/${scenes.length} scenes queued!` +
        (failCount > 0 ? ` — <strong style="color:#dc2626;">${failCount} failed</strong> (click 🔁 on failed cards)` : '') +
        editorLink;
    btn.disabled = false;
    btn.innerHTML = successCount > 0 ? '🔁 Regenerate All' : '🔁 Retry All';

    if (podcastId && !_pollingStarted) {
        _pollingStarted = true;
        document.getElementById('queueStatusPanel').style.display = 'block';
        fetchQueueStatus(podcastId, true);
        startQueuePolling(podcastId);
    }
    _pollingStarted = false;
}

// -- Queue polling globals -------------------------------------------------
let _pollTimer      = null;
let _pollPodcastId  = 0;
let _pollingStarted = false;
let _storyIdMap     = {};
const POLL_INTERVAL_MS = 30000;

function fmtMins(mins) {
    if (mins <= 0)  return 'Almost done!';
    if (mins === 1) return '~1 min';
    if (mins < 60)  return '~' + mins + ' min';
    const h = Math.floor(mins/60), m = mins%60;
    return m > 0 ? '~'+h+'h '+m+'m' : '~'+h+'h';
}

async function fetchQueueStatus(podcastId, isFirst) {
    isFirst = isFirst || false;
    console.log('[Queue] fetchQueueStatus podcast=' + podcastId + ' time=' + new Date().toLocaleTimeString());
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_queue_status');
        fd.append('podcast_id',  podcastId);
        const resp = await fetch('', { method:'POST', body:fd });
        const d    = await resp.json();
        console.log('[Queue] response:', JSON.stringify(d));
        if (!d.success) { console.warn('[Queue] failed', d); return; }

        const imgDone    = d.img_done    || 0;
        const imgTotal   = d.img_total   || 0;
        const imgPending = d.img_pending || 0;
        const imgAhead   = d.img_ahead   || 0;
        const imgEta     = d.img_eta_mins || 0;
        const imgPct     = imgTotal > 0 ? Math.round((imgDone/imgTotal)*100) : 0;

        document.getElementById('imgQueueBar').style.width      = imgPct + '%';
        document.getElementById('imgQueueProgress').textContent = imgDone + ' / ' + imgTotal + ' done';
        document.getElementById('imgEtaDisplay').textContent    = fmtMins(imgEta);

        updateStoryboardWithImages(d.img_scenes || []);

        if (d.all_images_done) {
            document.getElementById('imgQueueDetail').textContent = 'All ' + imgTotal + ' images generated ✅';
            document.getElementById('imgEtaDisplay').textContent  = 'Done!';
            document.getElementById('imgQueueBar').style.width    = '100%';
            document.getElementById('imgPollStatus').textContent  = 'Complete';
            stopQueuePolling();
        } else {
            const processing = (d.img_scenes||[]).filter(function(s){ return s.videogen_flag==2; }).length;
            var parts = [];
            parts.push(imgDone + ' of ' + imgTotal + ' images done');
            if (processing > 0) parts.push(processing + ' generating now');
            if (imgAhead > 0)   parts.push(imgAhead + ' other image' + (imgAhead!==1?'s':'') + ' ahead in queue');
            if (imgPending > 0) parts.push(imgPending + ' of yours waiting');
            document.getElementById('imgQueueDetail').textContent = parts.join(' • ');
            document.getElementById('imgPollStatus').textContent  =
                isFirst ? 'Polling every 30s…' : 'Last checked: ' + new Date().toLocaleTimeString();
        }

        const vidDone    = d.vid_done    || 0;
        const vidTotal   = d.vid_total   || 0;
        const vidPending = d.vid_pending || 0;
        const vidEta     = d.vid_eta_mins || 0;
        const vidPct     = vidTotal > 0 ? Math.round((vidDone/vidTotal)*100) : 0;

        document.getElementById('vidQueueBar').style.width      = vidPct + '%';
        document.getElementById('vidQueueProgress').textContent = vidDone + ' / ' + vidTotal + ' done';
        document.getElementById('vidEtaDisplay').textContent    = vidTotal > 0 ? fmtMins(vidEta) : '--';

        if (vidDone >= vidTotal && vidTotal > 0) {
            document.getElementById('vidQueueDetail').textContent = 'All ' + vidTotal + ' videos generated ✅';
        } else if (vidTotal > 0) {
            var vp = [];
            vp.push(vidDone + ' of ' + vidTotal + ' videos done');
            if (vidPending > 0) vp.push(vidPending + ' of yours waiting (~3 min each)');
            document.getElementById('vidQueueDetail').textContent = vp.join(' • ');
        } else {
            document.getElementById('vidQueueDetail').textContent = 'Starts after images are ready';
        }
    } catch(e) {
        document.getElementById('imgPollStatus').textContent = 'Poll error: ' + e.message;
    }
}

function startQueuePolling(podcastId) {
    stopQueuePolling();
    _pollPodcastId = podcastId;
    console.log('[Queue] Poll started every ' + (POLL_INTERVAL_MS/1000) + 's podcast=' + podcastId);
    _pollTimer = setInterval(function() {
        fetchQueueStatus(_pollPodcastId, false);
    }, POLL_INTERVAL_MS);
}

function stopQueuePolling() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
}

function updateStoryboardWithImages(imgScenes) {
    var storyToScene = {};
    var mapToUse = (Object.keys(_storyIdMap).length > 0) ? _storyIdMap : storyIdMap;
    Object.keys(mapToUse).forEach(function(sn) {
        storyToScene[String(mapToUse[sn])] = sn;
    });
    console.log('[Queue] updateStoryboard map:', storyToScene);
    imgScenes.forEach(function(sc) {
        if (sc.videogen_flag != 3 || !sc.image_name) return;
        var sceneNum = storyToScene[String(sc.scene_id)];
        if (!sceneNum) { console.warn('[Queue] No sceneNum for scene_id=' + sc.scene_id); return; }
        var card = document.getElementById('scene-card-' + sceneNum);
        if (!card) { console.warn('[Queue] Card not found: scene-card-' + sceneNum); return; }
        if (card.querySelector('img')) return;
        var folder  = sc.image_folder || 'podcast_images';
        var imgPath = folder + '/' + sc.image_name + '?t=' + Date.now();
        console.log('[Queue] Rendering image scene=' + sceneNum + ' src=' + imgPath);
        card.innerHTML =
            '<div style="position:relative;aspect-ratio:9/16;">'
          + '<img src="' + imgPath + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy">'
          + '<div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(5,150,105,.88);color:#fff;">⚡ Generated</div>'
          + '</div>'
          + '<div style="padding:8px 10px;"><div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ' + sceneNum + '</div></div>';
    });
}


async function warmupModalManually() {
    const btn = event.currentTarget; const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Warming…'; btn.disabled = true;
    try {
        const resp = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_action=warmup_modal' });
        const data = await resp.json();
        alert(data.message || (data.success ? 'Modal ready!' : 'Warmup attempted'));
    } catch(e) { alert('Warmup error'); }
    finally { btn.innerHTML = orig; btn.disabled = false; }
}

async function testModalRaw() {
    const btn = document.getElementById('btnTestModal');
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Testing…'; btn.disabled = true;
    try {
        const fd = new FormData(); fd.append('ajax_action', 'test_modal_raw');
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        const ok = data.has_image, color = ok ? '#166534' : '#991b1b', bg = ok ? '#dcfce7' : '#fee2e2';
        const msg = [`<strong>Verdict:</strong> ${data.verdict}`,`<strong>URL:</strong> ${data.url}`,`<strong>HTTP:</strong> ${data.http_code} | <strong>cURL err:</strong> ${data.curl_errno||'none'}`,`<strong>Time:</strong> ${data.elapsed_s}s`,`<strong>Response keys:</strong> ${data.response_keys?data.response_keys.join(', '):'none'}`,`<strong>Body preview:</strong> ${data.body_preview}`].join('<br>');
        let box = document.getElementById('modalTestResult');
        if (!box) { box = document.createElement('div'); box.id = 'modalTestResult'; btn.closest('.actions').after(box); }
        box.style.cssText = `background:${bg};border:1.5px solid ${ok?'#86efac':'#fca5a5'};border-radius:10px;padding:12px 16px;margin-top:10px;font-size:12px;color:${color};line-height:1.8;`;
        box.innerHTML = msg;
    } catch(e) { alert('Test error: ' + e.message); }
    finally { btn.innerHTML = orig; btn.disabled = false; }
}

// Auto-warmup removed — was firing on every page load, wasting Modal GPU credits.
// Warmup now only fires when user clicks Generate Scene Images or Pre-warm Modal.


// Submit button loading state
document.querySelectorAll('form button[type=submit]').forEach(btn => {
    btn.addEventListener('click', function() { setTimeout(() => btnLoading(this, 'Processing…'), 10); });
});

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

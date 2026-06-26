<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start(); // prevent warnings from corrupting AJAX JSON

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
require_once __DIR__ . '/media_ingest.php';

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
@mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN IF NOT EXISTS target_location VARCHAR(255) DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN IF NOT EXISTS target_audience VARCHAR(255) DEFAULT NULL");
$company = null;
if ($company_id > 0) {
    $co_res = mysqli_query($conn,
        "SELECT companyname, description, brand_name, logo_file, website, phone, address, ai_group, ai_subgroup, cta, target_location, target_audience
         FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
if (!$company) {
    $co_res = mysqli_query($conn,
        "SELECT companyname, description, brand_name, logo_file, website, phone, address, ai_group, ai_subgroup, cta, target_location, target_audience
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
$co_cta   = trim($company['cta'] ?? '');
// ai_group/ai_subgroup are NOT NULL varchar columns on hdb_companies — existing
// rows may hold '' rather than NULL, so treat both as "not set".
$co_ai_group    = trim($company['ai_group']    ?? '');
$co_ai_subgroup = trim($company['ai_subgroup'] ?? '');
$co_profile_set = ($co_ai_group !== '' && $co_ai_subgroup !== '');
$co_target_location = trim($company['target_location'] ?? '');
$co_target_audience = trim($company['target_audience'] ?? '');
$co_target_set      = ($co_target_location !== '' || $co_target_audience !== '');



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

// Support multiple common API key variable names (PHP 8 safe)
$apiKey = (isset($apiKey) ? $apiKey : null) ?? (isset($myApiKey) ? $myApiKey : null) ?? (isset($api_Key) ? $api_Key : null) ?? (isset($openai_key) ? $openai_key : null) ?? null;

// Resolve fal.ai API key from config.php
$falApiKey = (!empty($falApiKey) ? $falApiKey : null)
          ?? (!empty($fal_api_key) ? $fal_api_key : null)
          ?? null;

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
          $_SESSION['promoting_item'], $_SESSION['cta_text'], $_SESSION['error'],
          $_SESSION['promoting_item_photo_path']);
}

// === LOGGING FUNCTION ==========================================
// ═══════════════════════════════════════════════════════════════
// LOGGING — writes to image_generation.log AND a_errors_log
// with microsecond timestamps so we can measure every step
// ═══════════════════════════════════════════════════════════════
function logGeneration($message, $type = 'INFO') {
    $ts    = date('Y-m-d H:i:s') . '.' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));
    $entry = "[$ts] [$type] $message" . PHP_EOL;
    @file_put_contents(__DIR__ . '/image_generation.log', $entry, FILE_APPEND | LOCK_EX);
    @file_put_contents(__DIR__ . '/a_errors_log',         $entry, FILE_APPEND | LOCK_EX);
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

// ── User media library match — searches one media type at a time ───────────
// Scoped strictly to this admin_id+company_id: media a user uploaded or that
// was generated for their videos belongs to them, never shared across
// different accounts/companies. Caller decides tier order (video first, then
// image) by calling this twice with different $media_type values — a miss on
// both falls through to FAL/Modal generation exactly as before this existed.
// $exclude_podcast_id, when set, skips any asset already assigned to another
// scene within the SAME movie — otherwise a small library would put the same
// clip in 5 different scenes of one video, which looks broken on playback.
function findUserMediaMatch(string $searchText, int $admin_id, int $company_id, $conn, string $openai_key, string $media_type = 'image', int $exclude_podcast_id = 0, string $promo_group = '', string $promo_subgroup = ''): ?array {
    if (!$searchText || !$openai_key || $admin_id <= 0 || $company_id <= 0) return null;
    if (!in_array($media_type, ['image', 'video'], true)) return null;

    $vec = mi_embed($searchText, $openai_key);
    if (!$vec) return null;
    $dims = count($vec);
    // 0.25 was far too low — generic embeddings of completely unrelated
    // categories (e.g. food photos vs. party-wear fashion) routinely score
    // above that, which is exactly how Food & Drink images got matched to
    // a party-wear video. Raised to a level where only genuinely related
    // content clears the bar.
    $floor = 0.45;
    $mte = mysqli_real_escape_string($conn, $media_type);

    // Filenames already used by another scene in this same movie — skip these
    // so one short clip doesn't end up repeated across the whole video.
    $already_used = [];
    if ($exclude_podcast_id > 0) {
        $used_q = mysqli_query($conn,
            "SELECT DISTINCT image_file FROM hdb_podcast_stories
             WHERE podcast_id = $exclude_podcast_id AND image_file IS NOT NULL AND image_file != ''"
        );
        if ($used_q) {
            while ($u = mysqli_fetch_assoc($used_q)) {
                $already_used[$u['image_file']] = true;
            }
        }
    }

    // Hard category gate: filter on BOTH promo_group and promo_subgroup when
    // known, not just group. "Food & Drink / Bakery" media should not match
    // a "Food & Drink / Restaurant" video either — group alone is too coarse.
    $group_filter = '';
    if ($promo_group !== '') {
        $pg_e = mysqli_real_escape_string($conn, $promo_group);
        $group_filter .= " AND promo_group = '$pg_e'";
    }
    if ($promo_subgroup !== '') {
        $psg_e = mysqli_real_escape_string($conn, $promo_subgroup);
        $group_filter .= " AND promo_subgroup = '$psg_e'";
    }

    $sql = "SELECT id, image_name, media_type, natural_language_tags, embedding, thumbnail, image_folder, is_ai_generated
            FROM hdb_image_data
            WHERE admin_id = $admin_id AND company_id = $company_id
              AND media_type = '$mte'
              AND embedding IS NOT NULL AND embedding != ''
              AND status = 'active'
              $group_filter
            ORDER BY id DESC
            LIMIT 300";
    $res = mysqli_query($conn, $sql);
    if (!$res) return null;

    $best = null; $bestScore = $floor;

    while ($row = mysqli_fetch_assoc($res)) {
        if (isset($already_used[$row['image_name']])) continue; // already used elsewhere in this movie

        $rvec = json_decode($row['embedding'], true);
        if (!is_array($rvec) || count($rvec) !== $dims) continue;

        $dot = 0.0; $na = 0.0; $nb = 0.0;
        foreach ($rvec as $k => $v) {
            $dot += $v * $vec[$k];
            $na  += $v * $v;
            $nb  += $vec[$k] * $vec[$k];
        }
        $score = ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
            $best['score'] = $score;
        }
    }

    return $best;
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


// ── AJAX: get_promo_groups — chip list for Step 1 of business profile picker ──
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_promo_groups') {
    ob_start(); ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    mysqli_set_charset($conn, 'utf8mb4');
    $q = mysqli_query($conn,
        "SELECT category_name, category_icon FROM hdb_promo_categories WHERE is_active=1 ORDER BY sort_order ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = ['group' => $r['category_name'], 'icon' => $r['category_icon'] ?? ''];
    }
    ob_end_clean();
    echo json_encode(['success' => true, 'groups' => $rows]);
    exit;
}

// ── AJAX: get_promo_subgroups — chip list for Step 2, filtered by chosen group ──
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_promo_subgroups') {
    ob_start(); ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    mysqli_set_charset($conn, 'utf8mb4');
    $group = trim($_POST['group'] ?? '');
    if (!$group) { ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Missing group']); exit; }
    $ge = mysqli_real_escape_string($conn, $group);
    $q = mysqli_query($conn,
        "SELECT promo_subgroup FROM hdb_promo_subcategories
         WHERE promo_group='$ge' AND is_active=1
         ORDER BY display_order ASC, promo_subgroup ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = $r['promo_subgroup'];
    }
    ob_end_clean();
    echo json_encode(['success' => true, 'subgroups' => $rows]);
    exit;
}

// ── AJAX: upload_user_media — saves to user_media/.../podcast_images|videos
// and ingests into hdb_image_data (thumbnail, GPT-4o vision tags, embedding)
// via the shared mediaIngest() library, scoped to this company so it never
// mixes with another restaurant's paid-for media.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_user_media') {
    ob_start(); ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['media']) || empty($_FILES['media']['tmp_name'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No file received']);
        exit;
    }
    if ($company_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No company selected']);
        exit;
    }

    $result = mediaIngest([
        'file'            => $_FILES['media'],
        'admin_id'        => $admin_id,
        'company_id'      => $company_id,
        'promo_group'     => $co_ai_group,
        'promo_subgroup'  => $co_ai_subgroup,
        'image_folder'    => 'podcast_images',
        'video_folder'    => 'podcast_videos',
        'thumb_folder'    => 'podcast_thumbnails',
        'max_video_sec'   => 6, // trim to 6s — matches our scene length
        'filename_prefix' => 'user',
        'context'         => trim($co_ai_group . ' ' . $co_ai_subgroup),
    ], $conn, $apiKey ?: '');

    ob_end_clean();
    echo json_encode($result);
    exit;
}
// ── AJAX: upload_product_photo — saves the merchant's REAL product photo
// (e.g. the actual watch/purse being sold). Stashed in session through the
// wizard the same way $_SESSION['promoting_item'] is, then persisted onto
// hdb_podcasts.product_photo_path at save_cinematic_to_db so every later
// scene-image call for this podcast can find it and EDIT it (generateWithFalEdit)
// instead of generating a fake product from text (generateWithFalAI).
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_product_photo') {
    ob_start(); ini_set('display_errors', 0);
    set_time_limit(120); // the auto-sharpen step (fal.ai upload + ESRGAN + download) can run well past PHP's default 30s limit
    header('Content-Type: application/json; charset=utf-8');

    $file = $_FILES['product_photo'] ?? null;
    if (!$file || empty($file['tmp_name'])) {
        ob_end_clean(); echo json_encode(['success' => false, 'error' => 'No file received']); exit;
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Invalid file type — use jpg, png, or webp']); exit;
    }
    if ($file['size'] > 15 * 1024 * 1024) {
        ob_end_clean(); echo json_encode(['success' => false, 'error' => 'File too large (max 15MB)']); exit;
    }

    $photo_dir = __DIR__ . "/user_media/user_id_{$admin_id}_company_id_{$company_id}/product_images/";
    if (!is_dir($photo_dir)) @mkdir($photo_dir, 0777, true);

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $filename = 'product_' . $admin_id . '_' . uniqid() . '.' . $ext;
    $abs_path = $photo_dir . $filename;

    $moved = move_uploaded_file($file['tmp_name'], $abs_path) || @copy($file['tmp_name'], $abs_path);
    if (!$moved || !file_exists($abs_path)) {
        ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Could not save uploaded file']); exit;
    }

    // ── Sharpness check — clients often can't easily get a studio-quality
    // photo, so rather than rejecting a soft upload outright, auto-sharpen
    // it once here so every scene built from it starts from a clean source.
    // Wrapped in try/catch as a safety net for anything unexpected in this
    // new step — a failure here must never take down the whole upload; the
    // original (already-saved) photo is always a valid fallback.
    $prev_memory_limit = ini_get('memory_limit');
    ini_set('memory_limit', '256M'); // GD decoding a large phone photo can be memory-hungry
    $sharpened = false;
    try {
        $sharpness_score = vv_image_sharpness_score($abs_path);
        if ($sharpness_score !== null) {
            logGeneration("PRODUCT PHOTO: sharpness score=$sharpness_score (threshold=" . VV_BLUR_VARIANCE_THRESHOLD . ") path=$abs_path", "PRODUCT");
            if ($sharpness_score < VV_BLUR_VARIANCE_THRESHOLD && !empty($falApiKey)) {
                $upscale = vv_fal_upscale_image($abs_path, $falApiKey);
                if (!empty($upscale['success'])) {
                    // Output is always PNG from fal-ai/esrgan — update the
                    // extension/filename to match so the file is what it claims to be.
                    $sharp_filename = 'product_' . $admin_id . '_' . uniqid() . '.png';
                    $sharp_path     = $photo_dir . $sharp_filename;
                    if (@file_put_contents($sharp_path, $upscale['image_bytes'])) {
                        @unlink($abs_path);
                        $abs_path  = $sharp_path;
                        $filename  = $sharp_filename;
                        $sharpened = true;
                        logGeneration("PRODUCT PHOTO: auto-sharpened via fal-ai/esrgan -> $abs_path", "PRODUCT");
                    } else {
                        logGeneration("PRODUCT PHOTO: upscale succeeded but could not write $sharp_path — keeping original", "PRODUCT_ERROR");
                    }
                } else {
                    // Enhancement failing shouldn't block the upload — the
                    // original (soft) photo is still usable, just not improved.
                    logGeneration("PRODUCT PHOTO: upscale failed (" . ($upscale['error'] ?? 'unknown') . ") — keeping original", "PRODUCT_ERROR");
                }
            }
        } else {
            logGeneration("PRODUCT PHOTO: sharpness check skipped (GD unavailable, unreadable, or oversized image) path=$abs_path", "PRODUCT");
        }
    } catch (\Throwable $e) {
        logGeneration("PRODUCT PHOTO: sharpen/upscale step threw — keeping original. " . $e->getMessage(), "PRODUCT_ERROR");
    }
    ini_set('memory_limit', $prev_memory_limit);

    $_SESSION['promoting_item_photo_path'] = $abs_path;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($photo_dir, '/')), '/') . '/';

    logGeneration("PRODUCT PHOTO: saved $abs_path for admin=$admin_id company=$company_id", "PRODUCT");
    ob_end_clean();
    echo json_encode([
        'success'    => true,
        'photo_path' => $abs_path,
        'photo_url'  => $protocol . '://' . $host . $web_path . $filename,
        'sharpened'  => $sharpened,
    ]);
    exit;
}


// Powers the "Set Business Profile" gate: until both are set, scene→media
// matching has no category/subcategory to scope the library search by.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_company_industry') {
    ob_start(); ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    $field   = trim($_POST['field'] ?? '');
    $val     = trim($_POST['value'] ?? '');
    $allowed = ['ai_group', 'ai_subgroup', 'target_location', 'target_audience'];
    if (!in_array($field, $allowed, true)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Bad field']);
        exit;
    }
    if ($company_id <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No company selected']);
        exit;
    }

    $fe = mysqli_real_escape_string($conn, $val);
    $ok = mysqli_query($conn, "UPDATE hdb_companies SET `$field`='$fe' WHERE id=$company_id AND admin_id=$admin_id LIMIT 1");

    ob_end_clean();
    echo json_encode([
        'success'  => (bool)$ok,
        'field'    => $field,
        'value'    => $val,
        'affected' => $ok ? mysqli_affected_rows($conn) : 0,
    ]);
    exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'more_options') {
    ob_clean();
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
    ob_clean();
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
    ob_clean();
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

        // Build hashtags/keywords from scene captions + business + location
        $all_text  = implode(' ', array_column($scenes_in, 'caption'));
        $stop      = ['the','and','for','you','your','with','that','this','are','can','will','have',
                      'from','they','their','what','about','there','more','some','would','could',
                      'should','been','were','was','one','two','first','then','than','very','just',
                      'like','into','over','also','after','other','only','area','near','local'];

        // Parse business string into business part and location part
        // e.g. 'matrimonial services in milton area' -> business='matrimonial services', location='milton'
        $niche_raw  = trim($_POST['business'] ?? ($_SESSION['business'] ?? ''));
        $additional = trim($_POST['additional'] ?? ($_SESSION['additional_info'] ?? ''));
        $biz_part   = $niche_raw;
        // The company's Target Location field is the authoritative source —
        // only fall back to guessing one out of the niche string / additional
        // instructions when the company hasn't set it via the TARGET modal.
        $loc_part = $co_target_location ?? '';
        if ($loc_part === '') {
            // Extract location after 'in', 'near', 'at', '@' keywords
            if (preg_match('/\b(?:in|near|at|@)\s+(.+)$/i', $niche_raw, $loc_match)) {
                $loc_part = trim(preg_replace('/\b(area|city|town|region|district|province|county)\b/i', '', $loc_match[1]));
                $biz_part = trim(preg_replace('/\b(?:in|near|at|@)\s+.+$/i', '', $niche_raw));
            }
            // Also extract from additional_info if it looks like a location
            if (!$loc_part && $additional && preg_match('/^[a-zA-Z\s,]+$/', $additional) && strlen($additional) < 60) {
                $loc_part = trim($additional);
            }
        }
        // Target Audience — also from the company profile, e.g. "young
        // professionals aged 25-40" feeds keywords/hashtags the same way.
        $aud_part = $co_target_audience ?? '';

        // Build keyword array: content words + business + location
        $words   = array_diff(str_word_count(strtolower($all_text), 1), $stop);
        $kw_arr  = array_slice(array_unique(array_values($words)), 0, 8);

        // Add business keywords (each word separately + full phrase)
        if ($biz_part) {
            $biz_words = array_diff(str_word_count(strtolower($biz_part), 1), $stop);
            foreach ($biz_words as $bw) { if (strlen($bw) > 2) $kw_arr[] = $bw; }
            $kw_arr[] = strtolower($biz_part); // full phrase too
        }
        // Add location keywords
        if ($loc_part) {
            $loc_words = array_diff(str_word_count(strtolower($loc_part), 1), $stop);
            foreach ($loc_words as $lw) { if (strlen($lw) > 2) $kw_arr[] = $lw; }
            $kw_arr[] = strtolower(trim($loc_part)); // full location
        }
        // Add target audience keywords
        if ($aud_part) {
            $aud_words = array_diff(str_word_count(strtolower($aud_part), 1), $stop);
            foreach ($aud_words as $aw) { if (strlen($aw) > 2) $kw_arr[] = $aw; }
            $kw_arr[] = strtolower(trim($aud_part)); // full audience phrase
        }
        $kw_arr = array_unique(array_values($kw_arr));

        // Build hashtags: content words + business hashtag + location hashtag + combined
        $ht_arr = array_map(function($w){ return '#'.preg_replace('/\s+/','',$w); }, array_slice($kw_arr, 0, 7));
        if ($biz_part)         { $ht_arr[] = '#'.preg_replace('/\s+/','',strtolower($biz_part)); }
        if ($loc_part)         { $ht_arr[] = '#'.preg_replace('/\s+/','',strtolower(trim($loc_part))); }
        if ($aud_part)         { $ht_arr[] = '#'.preg_replace('/\s+/','',strtolower(trim($aud_part))); }
        if ($biz_part && $loc_part) {
            // Combined: e.g. #matrimonialservicesmilton
            $combined_words = array_filter(array_merge(
                str_word_count(strtolower($biz_part), 1),
                str_word_count(strtolower(trim($loc_part)), 1)
            ), function($w){ return strlen($w) > 2; });
            $ht_arr[] = '#' . implode('', array_unique(array_values($combined_words)));
        }
        $ht_arr   = array_unique($ht_arr);
        $hashtags = mysqli_real_escape_string($conn, implode(', ', $ht_arr));
        $keywords = mysqli_real_escape_string($conn, implode(', ', $kw_arr));

        // ── INSERT hdb_podcasts ────────────────────────────────────────
        @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_group VARCHAR(255) DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_subgroup VARCHAR(255) DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS product_photo_path VARCHAR(500) DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");
        // One global choice for the whole video — every scene gets the same
        // videogen_flag. 1 = dynamic/AI-animated (fal.ai gets fired per
        // scene when the user explicitly clicks "Generate AI Video" later),
        // 0 = static (the picked image IS the final scene, no animation).
        $video_mode    = trim($_POST['video_mode'] ?? 'static');
        $videogen_flag = ($video_mode === 'dynamic') ? 1 : 0;

        $pod_ai_group_e    = mysqli_real_escape_string($conn, $co_ai_group);
        $pod_ai_subgroup_e = mysqli_real_escape_string($conn, $co_ai_subgroup);
        // Product-ad mode: a real uploaded photo of the actual item being sold
        // (e.g. the specific watch/purse), set via upload_product_photo above.
        $product_photo_path   = trim($_POST['product_photo_path'] ?? ($_SESSION['promoting_item_photo_path'] ?? ''));
        $product_photo_path_e = ($product_photo_path !== '' && file_exists($product_photo_path))
            ? mysqli_real_escape_string($conn, $product_photo_path)
            : null;
        $product_photo_sql = $product_photo_path_e !== null ? "'$product_photo_path_e'" : 'NULL';
        $sql_pod = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
             created_date, updated_at, niche, category, topic_key, hashtags, keywords,
             host_voice, guest_voice, voice_rate, is_campaign,
             logo_flag, facebook_status, tiktok_status, instagram_status,
             youtube_status, twitter_status, linkedin_status,
             schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name,
             videogen_flag, ai_group, ai_subgroup, product_photo_path)
            VALUES
            ($admin_id, $team_lead_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'scenes_ready',
             '$today', NOW(), '$niche', '$category', '$topic_key', '$hashtags', '$keywords',
             '', '', 1.0, 0,
             0, 'pending', 'pending', 'pending',
             'pending', 'pending', 'pending',
             '$today', '09:00', '$today', 'vertical', 'video', '', '',
             $videogen_flag, '$pod_ai_group_e', '$pod_ai_subgroup_e', $product_photo_sql)";

        if (!mysqli_query($conn, $sql_pod)) {
            $db_err = mysqli_error($conn);
            echo json_encode(['success'=>false,'message'=>'hdb_podcasts INSERT failed: ' . $db_err]);
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

            // Move the chosen draft candidate into its permanent location —
            // by the time we get here the image already exists (generated
            // during the pick/preview phase via generate_draft_image), we're
            // just relocating it out of the session-scoped draft folder.
            $draft_file   = trim($scene['draft_file']   ?? '');
            $draft_folder = trim($scene['draft_folder'] ?? '');
            $image_file   = '';
            $image_folder = '';
            if ($draft_file !== '' && $draft_folder !== '') {
                $draft_abs = __DIR__ . '/' . $draft_folder . '/' . $draft_file;
                if (file_exists($draft_abs)) {
                    $final_folder_rel = "user_media/user_id_{$admin_id}_company_id_{$company_id}/product_photos";
                    $final_dir = __DIR__ . '/' . $final_folder_rel . '/';
                    if (!is_dir($final_dir)) @mkdir($final_dir, 0755, true);
                    // Temp name now (story_id doesn't exist yet) — renamed to the
                    // standard scene_{podcast_id}_{story_id}.png convention right
                    // after insert, below, once we know the real story_id.
                    $temp_name = "scene_{$podcast_id}_{$seq_no}_" . uniqid() . '.png';
                    if (@rename($draft_abs, $final_dir . $temp_name) || (@copy($draft_abs, $final_dir . $temp_name) && @unlink($draft_abs))) {
                        $image_file   = $temp_name;
                        $image_folder = $final_folder_rel;
                    } else {
                        logGeneration("SAVE: could not move draft file for scene=$scene_num from $draft_abs", "PRODUCT_ERROR");
                    }
                } else {
                    logGeneration("SAVE: draft file not found for scene=$scene_num at $draft_abs", "PRODUCT_ERROR");
                }
            }

            // Use caption as main text; fall back to wan prompt excerpt, then scene label
            $text = $caption_raw ?: substr($wan_prompt, 0, 200);
            if (empty($text)) $text = 'Scene ' . $scene_num;

            $te  = mysqli_real_escape_string($conn, $text);
            $de  = mysqli_real_escape_string($conn, substr($text, 0, 50).(strlen($text)>50?'...':''));
            $pe  = mysqli_real_escape_string($conn, $wan_prompt);
            $vpe = mysqli_real_escape_string($conn, $wan_prompt);
            $ife = mysqli_real_escape_string($conn, $image_file);
            $ifo = mysqli_real_escape_string($conn, $image_folder);
            $tke = mysqli_real_escape_string($conn, $title_raw);

            // Duration estimate (5 sec per scene for cinematic)
            // Total video = 30s divided equally across scenes
            $total_video_secs = 30;
            $scene_total      = count($scenes_in);
            $duration         = ($scene_total > 0) ? (int)floor($total_video_secs / $scene_total) : 5;
            $duration         = max(3, min($duration, 10)); // clamp between 3s and 10s

            // ── INSERT hdb_podcast_stories ────────────────────────────
            $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt, video_prompt, visual_type,
                 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
                 voice_id, voice_rate, image_file, image_folder, videogen_flag)
                VALUES
                ($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', 'host',
                 '$te', '$de', $duration, '$pe', '$vpe', 'image',
                 'PENDING', NOW(), $seq_no, 0, '', '',
                 '', 1.0, '$ife', '$ifo', $videogen_flag)";

            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                $story_id_map[$scene_num] = $story_id;
                logGeneration("STORY INSERT OK: podcast=$podcast_id scene=$scene_num story=$story_id", "DB");

                // Rename to the standard scene_{podcast_id}_{story_id}.png
                // convention now that the real story_id is known.
                if ($image_file !== '') {
                    $final_dir = __DIR__ . '/' . $image_folder . '/';
                    $std_name  = "scene_{$podcast_id}_{$story_id}.png";
                    if (@rename($final_dir . $image_file, $final_dir . $std_name)) {
                        $image_file = $std_name;
                        $ife_std = mysqli_real_escape_string($conn, $std_name);
                        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$ife_std' WHERE id=$story_id");
                    }
                }

                // Scene 1 -> copy into podcast_thumbnails/ and set hdb_podcasts.thumbnail
                if ($seq_no === 1 && $image_file !== '') {
                    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS thumbnail VARCHAR(255) DEFAULT NULL");
                    $thumb_dir = __DIR__ . '/podcast_thumbnails/';
                    if (!is_dir($thumb_dir)) @mkdir($thumb_dir, 0755, true);
                    if (@copy(__DIR__ . '/' . $image_folder . '/' . $image_file, $thumb_dir . $image_file)) {
                        $thumb_e = mysqli_real_escape_string($conn, $image_file);
                        mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='$thumb_e' WHERE id=$podcast_id");
                    }
                }

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
                // Primary INSERT failed — log the error
                logGeneration("STORY INSERT FAILED: podcast=$podcast_id scene=$scene_num err=" . mysqli_error($conn) . " sql=" . substr($ins, 0, 300), "DB_ERROR");
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
                } else {
                    logGeneration("STORY FALLBACK INSERT FAILED: podcast=$podcast_id scene=$scene_num err=" . mysqli_error($conn), "DB_ERROR");
                }
            }
        }

        // If NO story rows were saved, return failure with the DB error so JS can show it
        if (empty($story_id_map)) {
            echo json_encode(['success'=>false,'message'=>'All story inserts failed. Check image_generation.log for DB errors. Last MySQL error: ' . mysqli_error($conn)]);
            exit;
        }

        // Deduct 1 credit
        $cred_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $deduct_from = ($cred_row && $cred_row['role'] === 'Team Member' && (int)($cred_row['team_lead_id'] ?? 0) > 0)
            ? (int)$cred_row['team_lead_id'] : $admin_id;
        mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - 1) WHERE id=$deduct_from");

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
    ob_clean();
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

        $gen_mode    = trim($_POST['gen_mode'] ?? 'modal.com');
        $credits_use = ($gen_mode === 'fal.ai') ? 6 : 4;
        mysqli_query($conn,
            "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $credits_use) WHERE id=$deduct_from");

        $new_balance = $balance - $credits_use;
        echo json_encode(['success'=>true,'deducted'=>6,'balance'=>$new_balance]);
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── FAL.AI DIRECT IMAGE GENERATION ────────────────────────────
// Calls fal-ai/flux/dev directly (single sync HTTP call, no queue/poll),
// downloads/decodes the image, saves to disk, returns it immediately.
// No cron needed for images. Video still goes to hdb_video_gen_que for cron.

/**
 * generateWithFalAI()
 * Calls fal-ai/flux/dev directly (sync_mode=true, single HTTP call — no
 * queue/poll round-trip). This matches the working pattern used in
 * vizard_ai_tools.php's text-to-image tool. FAL can return either a normal
 * https:// CDN URL or an inline data:image/...;base64,... URI when
 * sync_mode is true — both are handled here.
 */
function generateWithFalAI(string $prompt, string $falApiKey, int $maxRetries = 2): array {
    $t0 = microtime(true);
    logGeneration("FAL: Starting flux/dev | prompt_len=" . strlen($prompt), "FAL");

    $payload = json_encode([
        'prompt'                => $prompt,
        'image_size'            => ['width' => 768, 'height' => 1344], // portrait 9:16, matches scene image usage
        'num_images'            => 1,
        'sync_mode'              => true,
        'guidance_scale'         => 3.5,
        'num_inference_steps'    => 28,
        'enable_safety_checker'  => false,
    ]);

    $httpCode  = 0;
    $curlError = '';

    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        if ($attempt > 0) { sleep(3); logGeneration("FAL: retry attempt $attempt", "FAL"); }

        $ch = curl_init('https://fal.run/fal-ai/flux/dev');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $res       = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 402) {
            logGeneration("FAL: Insufficient credits (402)", "FAL_ERROR");
            return ['success' => false, 'error' => 'Insufficient fal.ai credits', 'source' => 'fal.ai'];
        }
        if ($curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300 || !$res) {
            logGeneration("FAL: submit attempt=$attempt HTTP=$httpCode err=$curlError", "FAL_WARN");
            continue;
        }

        $data     = json_decode($res, true);
        $imageUrl = $data['images'][0]['url'] ?? null;

        if (!$imageUrl) {
            logGeneration("FAL: No image URL in response attempt=$attempt resp=" . substr((string)$res, 0, 300), "FAL_WARN");
            continue;
        }

        // FAL may return a real CDN URL, or — since sync_mode is true — an
        // inline data: URI. cURL can't fetch a data: URI as an HTTP request,
        // so decode it directly instead of downloading.
        $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $dataUriMatch);
        if ($isDataUri) {
            $imageBytes = base64_decode($dataUriMatch[1]);
            $dlOk       = ($imageBytes !== false && strlen($imageBytes) > 0);
        } else {
            $ich = curl_init($imageUrl);
            curl_setopt_array($ich, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $imageBytes = curl_exec($ich);
            $imgHttp    = curl_getinfo($ich, CURLINFO_HTTP_CODE);
            $imgErrno   = curl_errno($ich);
            curl_close($ich);
            $dlOk = ($imgErrno === 0 && $imgHttp === 200 && $imageBytes !== false && strlen($imageBytes) >= 1000);
        }

        if (!$dlOk) {
            logGeneration("FAL: image download/decode FAILED attempt=$attempt isDataUri=" . ($isDataUri ? 'Y' : 'N'), "FAL_WARN");
            continue;
        }

        $elapsed = round(microtime(true) - $t0, 2);
        logGeneration("FAL: SUCCESS attempt=$attempt total={$elapsed}s bytes=" . strlen($imageBytes), "FAL_SUCCESS");
        return ['success' => true, 'image' => base64_encode($imageBytes), 'source' => 'fal.ai/flux-dev'];
    }

    logGeneration("FAL: All $maxRetries attempts failed (last HTTP=$httpCode err=$curlError)", "FAL_ERROR");
    return ['success' => false, 'error' => 'fal.ai failed after retries', 'source' => 'fal.ai'];
}

// ═══════════════════════════════════════════════════════════════
// PRODUCT-AD SUPPORT — upload the merchant's real product photo to fal.ai's
// storage, then EDIT it per-scene instead of generating a fake product from
// text. generateWithFalAI()/generateWithModalAI() above are pure text→image
// (Flux/Modal) — fine for restaurant/lifestyle b-roll, but for a specific
// SKU (a watch, a purse) the buyer needs to see the REAL item, not an AI's
// guess at "a luxury watch". This pair of functions is the product-photo
// equivalent: same {success, image, source} return shape, so everything
// downstream (mediaIngest, scene save, video queueing) is unchanged.
// ═══════════════════════════════════════════════════════════════
function vv_fal_upload_for_proxy(string $path): array {
    // Calls fal.ai's storage API directly — same direct-curl pattern as
    // generateWithFalAI() below, no self-referencing HTTP hop through
    // fal_proxy.php (that loopback was the source of the HTTP 404s: a
    // server calling its own public hostname is fragile — vhost/proxy
    // mismatches, missing Host header handling, etc. — and script2 never
    // needed this pattern since it only ever did text→image, never an
    // image upload step). This inlines the same two fal.ai calls
    // fal_proxy.php was making (auth token, then byte upload).
    global $falApiKey;

    $fileBytes = @file_get_contents($path);
    if ($fileBytes === false) {
        return [null, 0, 'Could not read local file: ' . $path];
    }

    // Step 1: get a short-lived upload token from fal.ai
    $ch = curl_init('https://rest.alpha.fal.ai/storage/auth/token?storage_type=fal-cdn-v3');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER     => ['Authorization: Key ' . $falApiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $tokenRes  = curl_exec($ch);
    $tokenHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tokenErr  = curl_error($ch);
    curl_close($ch);

    if ($tokenErr || $tokenHttp !== 200) {
        return [null, $tokenHttp, 'Token fetch failed: ' . ($tokenErr ?: $tokenRes)];
    }
    $tokenData = json_decode($tokenRes, true);
    $token     = $tokenData['token']    ?? '';
    $baseUrl   = $tokenData['base_url'] ?? '';
    if (!$token || !$baseUrl) {
        return [null, $tokenHttp, 'Invalid token response: ' . $tokenRes];
    }

    // Step 2: upload the file bytes straight to fal.ai's CDN with that token
    $uploadUrl = rtrim($baseUrl, '/') . '/files/upload?file_name=' . urlencode(basename($path));
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fileBytes,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . mime_content_type($path),
            'Content-Length: ' . strlen($fileBytes),
        ],
        CURLOPT_TIMEOUT => 120,
    ]);
    $uploadRes  = curl_exec($ch);
    $uploadHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadErr  = curl_error($ch);
    curl_close($ch);

    if ($uploadErr || $uploadHttp >= 400) {
        return [null, $uploadHttp, $uploadErr ?: $uploadRes];
    }

    $uploadData = json_decode($uploadRes, true);
    $url = $uploadData['access_url'] ?? $uploadData['url'] ?? null;
    return [$url, $uploadHttp, $url ? '' : ('No URL in response: ' . $uploadRes)];
}

// ── Sharpness check (Laplacian variance) — runs once at upload time ────────
// Standard blur-detection technique: apply a Laplacian edge kernel, then
// measure the variance of the result. Sharp images have lots of strong,
// varied edges (high variance); blurry images produce a flat, low-variance
// response. Returns null if GD isn't available — skip the check rather than
// fail the upload over a missing PHP extension.
// NOTE: the BLUR_VARIANCE_THRESHOLD below is a reasonable starting point,
// not a precisely calibrated value — Laplacian-variance scores vary with
// image content/lighting/size. Watch the logged scores against real
// merchant uploads and adjust the threshold if it's flagging too
// many/too few photos as blurry in practice.
const VV_BLUR_VARIANCE_THRESHOLD = 150;

function vv_image_sharpness_score(string $path) {
    if (!extension_loaded('gd') || !function_exists('imageconvolution')) return null;
    $info = @getimagesize($path);
    if (!$info) return null;

    // Safety cap — decoding a huge original into GD (uncompressed bitmap in
    // memory) can exhaust memory_limit on large phone-camera photos. Skip
    // the check rather than risk a fatal (memory-exhaustion fatals are NOT
    // catchable by try/catch, so this guard has to happen before decoding).
    $megapixels = ((float)$info[0] * (float)$info[1]) / 1_000_000;
    if ($megapixels > 40) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        default: return null;
    }
    if (!$src) return null;

    // Downscale for speed — the sharpness signal doesn't need full resolution.
    $w = imagesx($src); $h = imagesy($src);
    $maxDim = 500;
    if (max($w, $h) > $maxDim) {
        $ratio = $maxDim / max($w, $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $resized;
        $w = $nw; $h = $nh;
    }

    imagefilter($src, IMG_FILTER_GRAYSCALE);
    $laplacian = [[0, 1, 0], [1, -4, 1], [0, 1, 0]];
    if (!imageconvolution($src, $laplacian, 1, 128)) {
        imagedestroy($src);
        return null;
    }

    $sum = 0.0; $sumSq = 0.0; $n = 0;
    // Cap sample count for speed on larger images rather than reading every pixel.
    $stepX = max(1, (int)($w / 200));
    $stepY = max(1, (int)($h / 200));
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            $gray = imagecolorat($src, $x, $y) & 0xFF; // grayscale: R=G=B
            $sum   += $gray;
            $sumSq += $gray * $gray;
            $n++;
        }
    }
    imagedestroy($src);
    if ($n === 0) return null;
    $mean = $sum / $n;
    return ($sumSq / $n) - ($mean * $mean); // variance
}

// ── Upscale/sharpen via fal.ai when the uploaded photo is too soft ─────────
// Uses Real-ESRGAN (fal-ai/esrgan) deliberately — it's a faithful
// super-resolution model, not a diffusion/"creative" upscaler. Creative
// upscalers can subtly invent/alter details, which is exactly what we can't
// risk on a real product photo that needs to stay recognizably accurate.
function vv_fal_upscale_image(string $localPath, string $falApiKey): array {
    [$sourceUrl, $uploadHttp, $uploadErr] = vv_fal_upload_for_proxy($localPath);
    if (!$sourceUrl) {
        return ['success' => false, 'error' => "Upload for upscale failed (HTTP $uploadHttp): $uploadErr"];
    }

    $payload = json_encode([
        'image_url'     => $sourceUrl,
        'scale'         => 2,
        'model'         => 'RealESRGAN_x4plus',
        'output_format' => 'png',
    ]);

    $ch = curl_init('https://fal.run/fal-ai/esrgan');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $http < 200 || $http >= 300 || !$res) {
        return ['success' => false, 'error' => "Upscale request failed (HTTP $http): " . ($err ?: $res)];
    }

    $data     = json_decode($res, true);
    $imageUrl = $data['image']['url'] ?? null;
    if (!$imageUrl) {
        return ['success' => false, 'error' => 'No image URL in upscale response: ' . substr((string)$res, 0, 200)];
    }

    $ich = curl_init($imageUrl);
    curl_setopt_array($ich, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $bytes = curl_exec($ich);
    curl_close($ich);
    if (!$bytes) {
        return ['success' => false, 'error' => 'Could not download upscaled image'];
    }

    return ['success' => true, 'image_bytes' => $bytes];
}

/**
 * generateWithFalEdit()
 * Edits a REAL product photo (nano-banana-2/edit) instead of generating one
 * from scratch. $prompt should describe only the background/composition/
 * lighting/camera angle for this scene — the product itself is preserved
 * by instruction, not regenerated. Mirrors generateWithFalAI()'s return
 * shape exactly so the caller doesn't need to branch on which was used.
 */
function generateWithFalEdit(string $prompt, string $sourceImagePath, string $falApiKey, int $maxRetries = 2): array {
    $t0 = microtime(true);
    logGeneration("FAL_EDIT: Starting nano-banana-2/edit | prompt_len=" . strlen($prompt) . " src=" . basename($sourceImagePath), "FAL_EDIT");

    [$sourceUrl, $uploadHttp, $uploadErr] = vv_fal_upload_for_proxy($sourceImagePath);
    if (!$sourceUrl) {
        logGeneration("FAL_EDIT: source upload failed HTTP=$uploadHttp err=$uploadErr", "FAL_EDIT_ERROR");
        return ['success' => false, 'error' => "Failed to upload product photo (HTTP $uploadHttp)", 'source' => 'fal.ai/nano-banana-2-edit'];
    }

    $fullPrompt = $prompt
        . ' Keep the product itself — shape, proportions, logo, materials, and color — exactly unchanged. '
        . 'Only change the background, surface, props, composition, and lighting around it. '
        . 'If a human element is needed, any part of the body below the chin is fine — hand, arm, '
        . 'shoulder, or torso carrying/wearing/holding the product — exactly like real product ad '
        . 'photography. Never show the face or head. '
        . 'Photorealistic, commercial product photography quality, sharp focus.';

    $payload = json_encode([
        'prompt'       => $fullPrompt,
        'image_urls'   => [$sourceUrl],
        'aspect_ratio' => 'auto',
        'resolution'   => '2K',
        'num_images'   => 1,
    ]);

    $httpCode = 0; $curlError = '';
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        if ($attempt > 0) { sleep(3); logGeneration("FAL_EDIT: retry attempt $attempt", "FAL_EDIT"); }

        $ch = curl_init('https://fal.run/fal-ai/nano-banana-2/edit');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        ]);
        $res       = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 402) {
            logGeneration("FAL_EDIT: Insufficient credits (402)", "FAL_EDIT_ERROR");
            return ['success' => false, 'error' => 'Insufficient fal.ai credits', 'source' => 'fal.ai/nano-banana-2-edit'];
        }
        if ($curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300 || !$res) {
            logGeneration("FAL_EDIT: submit attempt=$attempt HTTP=$httpCode err=$curlError", "FAL_EDIT_WARN");
            continue;
        }

        $data     = json_decode($res, true);
        $imageUrl = $data['images'][0]['url'] ?? null;
        if (!$imageUrl) {
            logGeneration("FAL_EDIT: No image URL in response attempt=$attempt resp=" . substr((string)$res, 0, 300), "FAL_EDIT_WARN");
            continue;
        }

        $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $m);
        if ($isDataUri) {
            $imageBytes = base64_decode($m[1]);
            $dlOk       = ($imageBytes !== false && strlen($imageBytes) > 0);
        } else {
            $ich = curl_init($imageUrl);
            curl_setopt_array($ich, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $imageBytes = curl_exec($ich);
            $imgHttp    = curl_getinfo($ich, CURLINFO_HTTP_CODE);
            $imgErrno   = curl_errno($ich);
            curl_close($ich);
            $dlOk = ($imgErrno === 0 && $imgHttp === 200 && $imageBytes !== false && strlen($imageBytes) >= 1000);
        }

        if (!$dlOk) {
            logGeneration("FAL_EDIT: image download/decode FAILED attempt=$attempt isDataUri=" . ($isDataUri ? 'Y' : 'N'), "FAL_EDIT_WARN");
            continue;
        }

        $elapsed = round(microtime(true) - $t0, 2);
        logGeneration("FAL_EDIT: SUCCESS attempt=$attempt total={$elapsed}s bytes=" . strlen($imageBytes), "FAL_EDIT_SUCCESS");
        return ['success' => true, 'image' => base64_encode($imageBytes), 'source' => 'fal.ai/nano-banana-2-edit'];
    }

    logGeneration("FAL_EDIT: All $maxRetries attempts failed (last HTTP=$httpCode err=$curlError)", "FAL_EDIT_ERROR");
    return ['success' => false, 'error' => 'Product photo edit failed after retries', 'source' => 'fal.ai/nano-banana-2-edit'];
}
/**
 * generateWithModalAI()
 * Calls the Modal/FLUX image endpoint directly (single sync HTTP call).
 * Mirrors generateWithFalAI()'s return shape — {success, image: base64,
 * source} — so the same downstream save/queue code works for both providers.
 */
function generateWithModalAI(string $prompt, int $maxRetries = 2): array {
    global $MODAL_URL;
    $t0 = microtime(true);
    logGeneration("MODAL: Starting | prompt_len=" . strlen($prompt), "MODAL");

    $payload = json_encode([
        'prompt' => $prompt,
        'style'  => 'cinematic',
        'width'  => 768,
        'height' => 1344,
    ]);

    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        if ($attempt > 0) { sleep(3); logGeneration("MODAL: retry attempt $attempt", "MODAL"); }

        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($payload),
            ],
        ]);
        $res       = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $httpCode !== 200 || !$res) {
            logGeneration("MODAL: attempt=$attempt HTTP=$httpCode err=$curlError", "MODAL_WARN");
            continue;
        }

        $data = json_decode($res, true);
        if (empty($data['image'])) {
            logGeneration("MODAL: attempt=$attempt no image field in response", "MODAL_WARN");
            continue;
        }

        $elapsed = round(microtime(true) - $t0, 2);
        logGeneration("MODAL: SUCCESS attempt=$attempt total={$elapsed}s", "MODAL_SUCCESS");
        return ['success' => true, 'image' => $data['image'], 'source' => 'Modal/FLUX'];
    }

    logGeneration("MODAL: All $maxRetries attempts failed — server may be cold-starting", "MODAL_ERROR");
    return ['success' => false, 'error' => 'Modal API failed after retries — the server may be cold-starting, try again shortly', 'source' => 'modal'];
}


// -- AJAX: Get queue status for a podcast ------------------------------------
// -- AJAX: get_scene_count -- ETA for mode selector ----------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scene_count') {
    ob_start(); ini_set('display_errors', 0);
    $pid         = (int)($_POST['podcast_id'] ?? 0);
    $scene_count = (int)($_POST['scene_count'] ?? 6);
    if ($pid) {
        $sc_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id = $pid AND (is_selected = 0 OR is_selected IS NULL)"
        ));
        $scene_count = (int)($sc_row['cnt'] ?? $scene_count);
    }
    // Images are generated synchronously now (no cron/queue), so there's no
    // real queue depth to measure — always 0.
    $img_queue_depth = 0;
    // Image ETA
    $fast_img_mins = max(1, (int)ceil(($scene_count * 8) / 60));
    $std_img_mins  = max(1, (int)ceil((($img_queue_depth * 25) + 200 + ($scene_count * 25)) / 60));

    // Video ETA: fal.ai = 1 min/video, modal.com = 3 min/video
    $_vid_q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag = 1");
    $vid_queue_depth = $_vid_q ? (int)(mysqli_fetch_assoc($_vid_q)['cnt'] ?? 0) : 0;
    $fast_vid_mins = max(1, $scene_count * 1);           // fal.ai ~1 min/video
    $std_vid_mins  = max(1, ($vid_queue_depth + $scene_count) * 3); // modal ~3 min/video + queue

    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'          => true,
        'scene_count'      => $scene_count,
        'img_queue_depth'  => $img_queue_depth,
        'vid_queue_depth'  => $vid_queue_depth,
        'fast_img_mins'    => $fast_img_mins,
        'fast_img_label'   => '~' . $fast_img_mins . ' min',
        'std_img_mins'     => $std_img_mins,
        'std_img_label'    => '~' . $std_img_mins . ' min (' . $img_queue_depth . ' jobs ahead)',
        'fast_vid_mins'    => $fast_vid_mins,
        'fast_vid_label'   => '~' . $fast_vid_mins . ' min',
        'std_vid_mins'     => $std_vid_mins,
        'std_vid_label'    => '~' . $std_vid_mins . ' min (' . $vid_queue_depth . ' in queue)',
    ]);
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_queue_status') {
    ob_start();
    ini_set('display_errors', 0);
    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => 'No podcast_id']); exit; }

    // hdb_podcast_stories was never created with an image_folder column —
    // it only gets backfilled by the generate_scene_image handler's UPDATE.
    // A podcast that hasn't generated any scene yet (or predates that guard)
    // won't have the column, so the SELECT below would silently fail.
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    // -- This podcast's scenes, read directly from hdb_podcast_stories --
    // Images are generated synchronously (no cron/queue for them), so
    // "done" is simply whether image_file is set on the scene's own row.
    $img_scenes_q = mysqli_query($conn,
        "SELECT id AS scene_id, seq_no, image_file AS image_name, image_folder, is_selected FROM hdb_podcast_stories
         WHERE podcast_id = $pid ORDER BY seq_no ASC, id ASC"
    );
    if ($img_scenes_q === false) {
        logGeneration("QUEUE STATUS: query failed | podcast=$pid err=" . mysqli_error($conn), "QUEUE_ERROR");
    }
    $img_scenes = [];
    while ($img_scenes_q && ($r = mysqli_fetch_assoc($img_scenes_q))) {
        $has_image = trim($r['image_name'] ?? '') !== '';
        $img_scenes[] = [
            'scene_id'      => $r['scene_id'],
            'seq_no'        => (int)$r['seq_no'],
            'is_selected'   => (int)$r['is_selected'],
            'videogen_flag' => $has_image ? 3 : 1, // 3=done, 1=not started — no in-between state to track
            'image_name'    => $r['image_name'],
            'image_folder'  => $r['image_folder'],
        ];
    }
    // Only the SELECTED variant per scene counts toward "is this video's
    // image generation done" — extra candidate variants don't block completion.
    $selected_scenes = array_values(array_filter($img_scenes, function($r){ return $r['is_selected'] === 0; }));
    $img_total   = count($selected_scenes);
    $img_done    = count(array_filter($selected_scenes, function($r){ return (int)$r['videogen_flag'] === 3; }));
    $img_pending = $img_total - $img_done;

    // No real queue for images anymore (synchronous, no cron) — nothing to be "ahead" of.
    $img_ahead          = 0;
    $img_global_pending = 0;
    $img_eta            = $img_pending; // ~1 min per remaining image, generated on demand

    // -- Video queue: all pending rows globally, 3 min per video --
    $vid_row            = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total FROM hdb_video_gen_que WHERE videogen_flag = 1"
    ));
    $vid_total          = (int)($vid_row['total'] ?? 0);
    $vid_pending        = $vid_total;
    $vid_done           = 0;
    $vid_ahead          = 0;
    $vid_global_pending = $vid_total;
    $vid_eta            = $vid_total * 3;

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'      => true,
        'img_total'    => $img_total,
        'img_done'     => $img_done,
        'img_pending'  => $img_pending,
        'img_global_pending' => $img_global_pending,
        'img_ahead'    => $img_ahead,
        'img_eta_mins' => $img_eta,
        'img_scenes'   => $img_scenes,
        'vid_total'    => $vid_total,
        'vid_done'     => $vid_done,
        'vid_pending'  => $vid_pending,
        'vid_ahead'    => $vid_ahead,
        'vid_global_pending' => $vid_global_pending,
        'vid_eta_mins' => $vid_eta,
        'all_images_done' => ($img_done >= $img_total && $img_total > 0),
    ]);
    exit;
}

// ── AJAX: generate_draft_image ───────────────────────────────────────────
// NEW preview-then-save model: generates ONE candidate image for a scene
// slot with NO database writes at all — no podcast_id, no story_id, no
// hdb_podcast_stories row. Images live in a session-scoped draft folder
// until the user clicks "Continue" and picks their final 6, at which point
// save_cinematic_to_db moves the chosen files into their permanent location
// and creates the real rows. Reusable for both the first image AND
// "Generate More" — there's no DB-level distinction between them anymore,
// since nothing is "selected" until Continue.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_draft_image') {
    ob_start();
    ini_set('display_errors', 0);
    set_time_limit(180);
    ignore_user_abort(true);
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'PHP fatal: ' . $err['message'], 'fail_stage' => 'php_fatal']);
        }
    });

    $scene_num     = (int)($_POST['scene_num'] ?? 0);
    $video_prompt  = trim($_POST['video_prompt'] ?? trim($_POST['wan_prompt'] ?? ''));
    $shot_type     = trim($_POST['shot_type'] ?? '');
    $custom_prompt = trim($_POST['custom_prompt'] ?? '');
    // Ad style is now a single global choice set once at upload time
    // (Step 1's "Ad Style" field), not a per-scene/per-request value —
    // applied automatically here so every scene stays visually consistent.
    $ad_style = trim($_SESSION['ad_style'] ?? '');

    if (!$scene_num || (!$video_prompt && !$custom_prompt)) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Missing scene_num or prompt', 'scene' => $scene_num, 'fail_stage' => 'input']);
        exit;
    }

    if ($custom_prompt !== '') {
        // User saw the auto-built prompt and edited it directly — use it
        // verbatim, don't re-append shot_type/ad_style on top of edits
        // that may have already removed or changed those exact phrases.
        $image_prompt = $custom_prompt;
    } else {
        $image_prompt = $video_prompt . ' Static establishing shot from the first frame, thumbnail-optimized, no motion blur.';
        if ($shot_type !== '') $image_prompt .= " Shot type for this variant: $shot_type.";
        if ($ad_style  !== '') $image_prompt .= " Overall ad style/mood: $ad_style.";
    }

    if (empty($falApiKey)) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'fal.ai API key not configured in config.php', 'scene' => $scene_num, 'fail_stage' => 'config']);
        exit;
    }

    $product_photo_path = trim($_SESSION['promoting_item_photo_path'] ?? '');
    $is_product_ad       = ($product_photo_path !== '' && file_exists($product_photo_path));

    if ($is_product_ad) {
        $falResult = generateWithFalEdit($image_prompt, $product_photo_path, $falApiKey, 2);
        if (!$falResult['success'] || empty($falResult['image'])) {
            // Deliberately no fallback for product-ad mode — see generateWithFalEdit's
            // own handler for the reasoning (a fake generic product is worse than failing loudly).
            ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $falResult['error'] ?? 'Product photo edit failed', 'scene' => $scene_num, 'fail_stage' => 'image_edit']);
            exit;
        }
    } else {
        $falResult = generateWithFalAI($image_prompt, $falApiKey, 2);
        if (!$falResult['success']) {
            $falResult = generateWithOpenAI($image_prompt);
        }
        if (!$falResult['success'] || empty($falResult['image'])) {
            ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $falResult['error'] ?? 'Image generation failed', 'scene' => $scene_num, 'fail_stage' => 'image_gen']);
            exit;
        }
    }

    // Draft folder scoped to this browser session — nothing in the DB
    // references these files yet, so they're cleaned up implicitly the
    // next time this session starts a fresh podcast (old drafts just sit
    // unused; not wired to an automatic cleanup job yet).
    $draft_token  = session_id();
    $draft_folder = "user_media/user_id_{$admin_id}_company_id_{$company_id}/product_photos/_draft_{$draft_token}";
    $draft_dir    = __DIR__ . '/' . $draft_folder . '/';
    if (!is_dir($draft_dir)) @mkdir($draft_dir, 0755, true);

    $draft_name = 'draft_scene' . $scene_num . '_' . uniqid() . '.png';
    if (!@file_put_contents($draft_dir . $draft_name, base64_decode($falResult['image']))) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Could not save draft image to disk', 'scene' => $scene_num, 'fail_stage' => 'save']);
        exit;
    }

    logGeneration("DRAFT IMAGE: scene=$scene_num saved $draft_folder/$draft_name source=" . $falResult['source'], "FAL");

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'      => true,
        'scene'        => $scene_num,
        'draft_file'   => $draft_name,
        'draft_folder' => $draft_folder,
        'image_url'    => $draft_folder . '/' . $draft_name,
        'source'       => $falResult['source'],
    ]);
    exit;
}


if (isset($_POST['ajax_action']) && ($_POST['ajax_action'] === 'generate_scene_image' || $_POST['ajax_action'] === 'generate_scene_image_modal')) {
    ob_start();
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    set_time_limit(300);
    // Flush headers early so nginx/Apache don't timeout the connection
    ignore_user_abort(true);
    // Register shutdown to catch fatal errors / timeouts and return valid JSON
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'error'=>'PHP fatal: '.$err['message'],'fail_stage'=>'php_fatal']);
        }
    });
    // mysqli is configured (in config.php/dbconnect_hdb.php) to throw
    // mysqli_sql_exception on query errors instead of just emitting a
    // warning. The shutdown function above only catches plain PHP fatals —
    // an *uncaught exception* needs its own handler, or it crashes this
    // whole AJAX response with a raw dump instead of clean JSON.
    set_exception_handler(function($e) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage(),'fail_stage'=>'db_error']);
        exit;
    });

    // PHP's default session handler holds an exclusive lock on the session
    // file for the whole request. All 6 scene-image requests share the same
    // session (same browser tab), so without this they'd run one at a time
    // instead of in parallel — easily explaining why only 1 of 6 finished
    // before something else (browser/proxy timeout) gave up. This handler
    // never reads or writes $_SESSION, so it's safe to release immediately.
    session_write_close();

    $t_handler = microtime(true);

    // ── Inputs ────────────────────────────────────────────────────
    $video_prompt = trim($_POST['video_prompt'] ?? trim($_POST['wan_prompt'] ?? ''));
    if (!$video_prompt) $video_prompt = trim($_POST['wan_prompt'] ?? '');
    // image prompt = video prompt + static-shot suffix for a sharp first-frame thumbnail
    $image_prompt = $video_prompt
        ? $video_prompt . ' Static establishing shot from the first frame, thumbnail-optimized, no motion blur.'
        : '';

    $scene_num  = (int)($_POST['scene_num']  ?? 0);
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $story_id   = (int)($_POST['story_id']   ?? 0);

    if (!$image_prompt) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'No prompt provided', 'scene' => $scene_num, 'fail_stage' => 'input']);
        exit;
    }

    // Resolve story_id from podcast + seq_no if not sent directly
    if ($podcast_id && !$story_id && $scene_num) {
        $fb = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_podcast_stories WHERE podcast_id=$podcast_id AND seq_no=$scene_num LIMIT 1"
        ));
        if ($fb) $story_id = (int)$fb['id'];
    }
    if (!$podcast_id || !$story_id) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Missing podcast_id or story_id', 'podcast_id' => $podcast_id, 'story_id' => $story_id, 'scene' => $scene_num, 'fail_stage' => 'db_lookup']);
        exit;
    }

    // Category gate for library matching — use this podcast's own snapshot
    // (set at creation) so media never crosses between unrelated niches,
    // even if the company's live profile has since changed. Falls back to
    // the company's current ai_group/ai_subgroup for podcasts created before
    // these columns existed.
    $pod_cat_row  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT ai_group, ai_subgroup, product_photo_path FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    $pod_ai_group    = trim($pod_cat_row['ai_group']    ?? '');
    $pod_ai_subgroup = trim($pod_cat_row['ai_subgroup'] ?? '');
    if ($pod_ai_group === '')    $pod_ai_group    = $co_ai_group;
    if ($pod_ai_subgroup === '') $pod_ai_subgroup = $co_ai_subgroup;

    // Product-ad mode: a real photo of the actual item is attached to this
    // podcast. In this mode we skip library matching entirely below — an
    // old library image (even a great match) is necessarily a DIFFERENT
    // physical item, which is exactly what we must not show — and route
    // straight to editing this exact photo per scene instead.
    $product_photo_path = trim($pod_cat_row['product_photo_path'] ?? '');
    $is_product_ad       = ($product_photo_path !== '' && file_exists($product_photo_path));


    // ── Already has an image? Skip everything below — nothing to do ──────
    // image_folder was never part of the original INSERT for this table —
    // guard it here too since this SELECT runs before the later ALTER block.
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");
    $existing_story = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT image_file, image_folder FROM hdb_podcast_stories WHERE id=$story_id LIMIT 1"
    ));
    $existing_image_file = trim($existing_story['image_file'] ?? '');

    if ($existing_image_file === '') {
      if (!$is_product_ad) {
        // ── Tier 1: search this company's own VIDEO library first ────────
        $libMatch = findUserMediaMatch($video_prompt, $admin_id, $company_id, $conn, $apiKey ?: '', 'video', $podcast_id, $pod_ai_group, $pod_ai_subgroup);

        // ── Tier 2: own IMAGE library (only if no video match) ────────────
        if (!$libMatch) {
            $libMatch = findUserMediaMatch($video_prompt, $admin_id, $company_id, $conn, $apiKey ?: '', 'image', $podcast_id, $pod_ai_group, $pod_ai_subgroup);
        }

        if ($libMatch) {
            $lib_folder = trim($libMatch['image_folder'] ?? '');
            $lib_name   = trim($libMatch['image_name']   ?? '');
            $lib_now    = date('Y-m-d H:i:s');
            $lib_fe     = mysqli_real_escape_string($conn, $lib_folder);
            $lib_ne     = mysqli_real_escape_string($conn, $lib_name);

            mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$lib_ne', image_folder='$lib_fe', updated_at='$lib_now' WHERE id=$story_id");

            logGeneration("LIBRARY MATCH: tier=" . $libMatch['media_type'] . " score=" . round($libMatch['score'], 3) . " file=$lib_name ai_generated=" . (!empty($libMatch['is_ai_generated']) ? 'Y' : 'N') . " | podcast=$podcast_id scene=$story_id", "LIBRARY");

            // Tier 1 (video) already has a usable video — nothing to queue.
            // Tier 2 (image) only gets queued for video generation if THAT
            // image is itself AI-generated. A real uploaded photo matched
            // from the library stays static — no video job for it.
            $lib_vid_queued = false;
            if ($libMatch['media_type'] === 'image' && !empty($libMatch['is_ai_generated'])) {
                @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
                $lib_vid_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id, videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$story_id LIMIT 1"
                ));
                $lib_vpe = mysqli_real_escape_string($conn, $video_prompt);
                $lib_vfe = mysqli_real_escape_string($conn, 'video_ai');
                $lib_vne = mysqli_real_escape_string($conn, 'scene_' . $podcast_id . '_' . $story_id . '.mp4');
                $lib_scene_count_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id AND (is_selected = 0 OR is_selected IS NULL)"
                ));
                $lib_scene_count = max(1, (int)($lib_scene_count_row['cnt'] ?? 6));
                $lib_duration    = max(3, min(10, (int)floor(30 / $lib_scene_count)));
                if ($lib_vid_row) {
                    if ((int)$lib_vid_row['videogen_flag'] != 2) {
                        mysqli_query($conn, "UPDATE hdb_video_gen_que SET prompt='$lib_vpe', video_folder='$lib_vfe', media_type='cinematic', video_file='$lib_vne', videogen_flag=1, gen_mode='', duration=$lib_duration, updated_at='$lib_now' WHERE id=" . (int)$lib_vid_row['id']);
                        $lib_vid_queued = true;
                    }
                } else {
                    $lib_ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,duration,created_at,updated_at) VALUES ($podcast_id,$story_id,'$lib_vpe','$lib_vfe','cinematic','$lib_vne',1,'',$lib_duration,'$lib_now','$lib_now')");
                    $lib_vid_queued = (bool)$lib_ins;
                }
                logGeneration("LIBRARY MATCH: image is AI-generated — video queued=" . ($lib_vid_queued ? 'Y' : 'N') . " | podcast=$podcast_id scene=$story_id", "LIBRARY");
            }

            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'      => true,
                'img_queued'   => true,
                'vid_queued'   => $lib_vid_queued,
                'image_ready'  => true,
                'image_file'   => $lib_name,
                'image_folder' => $lib_folder,
                'image_url'    => $lib_folder . '/' . $lib_name,
                'scene'        => $scene_num,
                'story_id'     => $story_id,
                'podcast_id'   => $podcast_id,
                'source'       => 'Your media library (' . $libMatch['media_type'] . ', score ' . round($libMatch['score'], 2) . ')',
                'from_library' => true,
                'message'      => 'Scene ' . $scene_num . ' matched from your media library — no credits used',
            ]);
            exit;
        }
        // No match in either tier — fall through to FAL/Modal generation below, unchanged.
      } // end !$is_product_ad — product-ad mode skips straight to generation below
    } else {
        // Image already set for this scene from a previous run — nothing to do.
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'      => true,
            'img_queued'   => true,
            'vid_queued'   => false,
            'image_ready'  => true,
            'image_file'   => $existing_image_file,
            'image_folder' => trim($existing_story['image_folder'] ?? ''),
            'image_url'    => trim($existing_story['image_folder'] ?? '') . '/' . $existing_image_file,
            'scene'        => $scene_num,
            'story_id'     => $story_id,
            'podcast_id'   => $podcast_id,
            'source'       => 'Already generated',
            'message'      => 'Scene ' . $scene_num . ' already has an image',
        ]);
        exit;
    }
    // ── Config ────────────────────────────────────────────────────
    $now          = date('Y-m-d H:i:s');
    // 'generate_scene_image_modal' (or an explicit gen_mode=modal.com POST field)
    // routes image generation through Modal/FLUX instead of fal.ai.
    $use_modal    = ($_POST['ajax_action'] === 'generate_scene_image_modal')
                 || (trim((string)($_POST['gen_mode'] ?? '')) === 'modal.com');
    $gen_mode     = $use_modal ? 'modal.com' : 'fal.ai';
    $image_folder = 'podcast_images';
    $video_folder = 'video_ai';
    $media_type   = 'cinematic';
    $image_name   = 'scene_' . $podcast_id . '_' . $story_id . '.png';
    $video_file   = 'scene_' . $podcast_id . '_' . $story_id . '.mp4';

    $pe  = mysqli_real_escape_string($conn, $image_prompt);
    $vpe = mysqli_real_escape_string($conn, $video_prompt);
    $ife = mysqli_real_escape_string($conn, $image_folder);
    $vfe = mysqli_real_escape_string($conn, $video_folder);
    $mte = mysqli_real_escape_string($conn, $media_type);
    $ine = mysqli_real_escape_string($conn, $image_name);
    $vne = mysqli_real_escape_string($conn, $video_file);
    $gme = mysqli_real_escape_string($conn, $gen_mode);

    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");

    $scene_count_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id AND (is_selected = 0 OR is_selected IS NULL)"
    ));
    $scene_count_val = max(1, (int)($scene_count_row['cnt'] ?? 6));
    $scene_duration  = max(3, min(10, (int)floor(30 / $scene_count_val)));

    // ── Check provider key/config ─────────────────────────────────
    if (empty($falApiKey)) {
        logGeneration("FAL HANDLER: No falApiKey in config.php | podcast=$podcast_id scene=$story_id", "FAL_ERROR");
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'fal.ai API key not configured in config.php', 'scene' => $scene_num, 'fail_stage' => 'config']);
        exit;
    }

    // ── Generate image: product-ad mode EDITS the real uploaded photo;
    // everything else generates from text via FAL (unchanged). ──────────────
    if ($is_product_ad) {
        logGeneration("IMAGE HANDLER: PRODUCT-AD mode — editing real photo $product_photo_path | podcast=$podcast_id story=$story_id scene=$scene_num", "FAL_EDIT");
        $falResult = generateWithFalEdit($image_prompt, $product_photo_path, $falApiKey, 2);
        if (!$falResult['success'] || empty($falResult['image'])) {
            // Deliberately NOT falling back to text-to-image here — a fake
            // generic product would be worse than failing loudly, since the
            // whole point of this mode is showing the buyer the real item.
            logGeneration("FAL_EDIT HANDLER: Edit failed, no fallback (by design) | podcast=$podcast_id scene=$story_id error=" . ($falResult['error'] ?? ''), "FAL_EDIT_ERROR");
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => false,
                'error'      => $falResult['error'] ?? 'Product photo edit failed',
                'scene'      => $scene_num,
                'fail_stage' => 'image_edit',
            ]);
            exit;
        }
    } else {
        // ── Generate image via FAL (always — the fal.ai/modal.com toggle only
        // ── applies to video generation, not images) ─────────────────────────
        logGeneration("IMAGE HANDLER: Starting | provider=fal.ai (images are always FAL) podcast=$podcast_id story=$story_id scene=$scene_num", "FAL");
        $falResult = generateWithFalAI($image_prompt, $falApiKey, 2);

        // Fallback to OpenAI if the primary provider fails
        if (!$falResult['success']) {
            logGeneration("IMAGE HANDLER: $gen_mode failed (" . ($falResult['error'] ?? '') . ") — trying OpenAI fallback", "FAL_FALLBACK");
            $falResult = generateWithOpenAI($image_prompt);
        }

        if (!$falResult['success'] || empty($falResult['image'])) {
            logGeneration("FAL HANDLER: Both providers failed | podcast=$podcast_id scene=$story_id error=" . ($falResult['error'] ?? ''), "FAL_ERROR");

            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => false,
                'error'      => $falResult['error'] ?? 'Image generation failed',
                'scene'      => $scene_num,
                'fail_stage' => 'image_gen',
            ]);
            exit;
        }
    }

    // ── Save image directly to product_photos/ — no mediaIngest ─────────
    // Scriptgen scenes are one-off edits of a single product photo, not
    // reusable library assets — routing them through mediaIngest() (GPT-4o
    // vision tagging + embedding into hdb_image_data) showed up as noisy,
    // not-clearly-useful matches for OTHER unrelated videos via
    // findUserMediaMatch(). So these just get written straight to disk,
    // same folder/convention as the original uploaded product photo.
    $imageData    = base64_decode($falResult['image']);
    // image_folder stores the FULL relative path from site root — vps_ffmpeg_stitch.php
    // resolves files as $ROOT_DIR/$image_folder/$image_file, so a bare "product_photos"
    // here would point at the wrong place. This must match the save dir exactly.
    $image_folder = "user_media/user_id_{$admin_id}_company_id_{$company_id}/product_photos";
    $image_name   = "scene_{$podcast_id}_{$story_id}.png";
    $save_dir     = __DIR__ . '/' . $image_folder . '/';

    if (!is_dir($save_dir)) {
        @mkdir($save_dir, 0755, true);
    }

    if (!@file_put_contents($save_dir . $image_name, $imageData)) {
        logGeneration("FAL HANDLER: Could not write scene image to $save_dir$image_name", "FAL_ERROR");
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Could not save image to disk', 'scene' => $scene_num, 'fail_stage' => 'save']);
        exit;
    }

    $ine = mysqli_real_escape_string($conn, $image_name);
    $ife = mysqli_real_escape_string($conn, $image_folder);
    logGeneration("FAL HANDLER: Image saved locally $image_folder/$image_name | podcast=$podcast_id scene=$story_id", "FAL");

    // ── Update hdb_podcast_stories with image file ────────────────
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$ine', image_folder='$ife', updated_at='$now' WHERE id=$story_id");
    logGeneration("FAL HANDLER: image done, stories table updated | podcast=$podcast_id scene=$story_id", "FAL");

    // ── Scene 1 ONLY: copy into podcast_thumbnails/ and set hdb_podcasts.thumbnail ──
    // Other parts of the app (podcast list/grid view) load the thumbnail from
    // this exact folder and can't be pointed elsewhere, so the file has to
    // physically exist here — not just be referenced via image_folder/image_name.
    if ($scene_num === 1) {
        @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS thumbnail VARCHAR(255) DEFAULT NULL");

        $thumb_dest_dir = __DIR__ . '/podcast_thumbnails/';
        if (!is_dir($thumb_dest_dir)) {
            @mkdir($thumb_dest_dir, 0755, true);
        }

        if (@copy($save_dir . $image_name, $thumb_dest_dir . $image_name)) {
            $thumb_e = mysqli_real_escape_string($conn, $image_name);
            mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='$thumb_e', updated_at='$now' WHERE id=$podcast_id");
            logGeneration("FAL HANDLER: thumbnail copied to podcast_thumbnails/$image_name and hdb_podcasts.thumbnail set | podcast=$podcast_id", "FAL");
        } else {
            logGeneration("FAL HANDLER: thumbnail copy FAILED from $save_dir$image_name | podcast=$podcast_id", "FAL_ERROR");
        }
    }


    // ── Write hdb_video_gen_que with flag=1 (pending for cron) ───
    // IMPORTANT: only queue a (paid) video-clip job for the variant that's
    // currently SELECTED for this scene slot. With multiple image variants
    // per seq_no now possible, queuing video gen for every generated
    // candidate would pay for clips on images that never make the final
    // cut. Unselected variants get queued later, in select_scene_variant,
    // if/when the user picks them instead.
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS is_selected TINYINT(1) NULL DEFAULT NULL");
    $sel_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_selected FROM hdb_podcast_stories WHERE id=$story_id LIMIT 1"));
    // DB semantics: 1 = excluded, 0 or NULL = selected/included. Default to
    // "selected" (1) if the row can't be found at all, matching the original
    // (non-variant) flow where every freshly generated scene should queue.
    $is_selected_now = ($sel_row && (int)$sel_row['is_selected'] === 1) ? 0 : 1;

    $vid_queued = false;
    $gme_blank  = '';
    if ($is_selected_now) {
    $vid_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$story_id LIMIT 1"
    ));
    if ($vid_row) {
        $vid_flag = (int)$vid_row['videogen_flag'];
        if ($vid_flag != 2) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET prompt='$vpe', video_folder='$vfe', media_type='$mte', video_file='$vne', videogen_flag=1, gen_mode='$gme_blank', duration=$scene_duration, updated_at='$now' WHERE id=" . (int)$vid_row['id']);
            $vid_queued = true;
            logGeneration("FAL HANDLER: video_gen_que UPDATED flag=1 | podcast=$podcast_id scene=$story_id", "FAL");
        } else {
            logGeneration("FAL HANDLER: video_gen_que already processing (flag=2) | podcast=$podcast_id scene=$story_id", "FAL");
        }
    } else {
        $ins_vid = mysqli_query($conn, "INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,duration,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','$mte','$vne',1,'$gme_blank',$scene_duration,'$now','$now')");
        if (!$ins_vid) {
            // Fallback without duration column (older schema)
            $ins_vid = mysqli_query($conn, "INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','$mte','$vne',1,'$gme_blank','$now','$now')");
        }
        if ($ins_vid) {
            $vid_queued = true;
            logGeneration("FAL HANDLER: video_gen_que INSERTED flag=1 dur={$scene_duration}s | podcast=$podcast_id scene=$story_id", "FAL");
        } else {
            logGeneration("FAL HANDLER: video_gen_que INSERT FAILED | " . mysqli_error($conn), "FAL_ERROR");
        }
    }
    } else {
        logGeneration("FAL HANDLER: skipped video queue — this variant is not the selected one for its scene | podcast=$podcast_id scene=$story_id", "FAL");
    }

    // ── Update podcast status ─────────────────────────────────────
    mysqli_query($conn, "UPDATE hdb_podcasts SET internal_status='scenes_ready', updated_at='$now' WHERE id=$podcast_id");

    $totalTime = round(microtime(true) - $t_handler, 2);
    logGeneration("FAL HANDLER: Complete total={$totalTime}s source=" . $falResult['source'] . " | podcast=$podcast_id scene=$story_id", "FAL_SUCCESS");

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'      => true,
        'img_queued'   => true,         // kept for JS compat — image is actually done now
        'vid_queued'   => $vid_queued,
        'image_ready'  => true,         // new: image is already on disk
        'image_file'   => $image_name,
        'image_folder' => $image_folder,
        'image_url'    => $image_folder . '/' . $image_name,
        'scene'        => $scene_num,
        'story_id'     => $story_id,
        'podcast_id'   => $podcast_id,
        'source'       => $falResult['source'],
        'elapsed_s'    => $totalTime,
        'message'      => 'Scene ' . $scene_num . ' image generated (' . $falResult['source'] . ') · video queued',
    ]);
    exit;
}

// ── AJAX: reset_current_podcast ──────────────────────────────────────────
// Full wipe of one podcast: all hdb_podcast_stories rows (every scene AND
// every variant), their hdb_captions rows, any hdb_video_gen_que jobs, the
// hdb_podcasts row itself, every image/video file those rows pointed to,
// the thumbnail copy, and the session state that was tracking it — so a
// reload afterward starts genuinely clean instead of trying to resume
// something that no longer exists.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reset_current_podcast') {
    ob_start(); ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    $podcast_id = (int)($_POST['podcast_id'] ?? ($_SESSION['active_podcast_id'] ?? 0));
    if (!$podcast_id) {
        // Nothing to delete server-side — still clear session so the form resets.
        unset($_SESSION['active_podcast_id'], $_SESSION['story'], $_SESSION['script'],
              $_SESSION['suggestions'], $_SESSION['selected'], $_SESSION['business'],
              $_SESSION['additional_info'], $_SESSION['promoting_item'],
              $_SESSION['promoting_item_photo_path'], $_SESSION['cta_text'], $_SESSION['ad_style']);
        ob_end_clean();
        echo json_encode(['success' => true, 'deleted' => false, 'message' => 'Nothing to delete — session cleared']);
        exit;
    }

    $own = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1"));
    if (!$own) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Podcast not found or not owned by this account']);
        exit;
    }

    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    // Collect every file referenced by every scene/variant row before deleting the rows.
    $files_deleted = 0;
    $stories_q = mysqli_query($conn,
        "SELECT id, image_file, image_folder FROM hdb_podcast_stories WHERE podcast_id=$podcast_id");
    $story_ids = [];
    while ($stories_q && ($r = mysqli_fetch_assoc($stories_q))) {
        $story_ids[] = (int)$r['id'];
        $fname = trim($r['image_file'] ?? '');
        $folder = trim($r['image_folder'] ?? '');
        if ($fname !== '' && $folder !== '') {
            $fpath = __DIR__ . '/' . $folder . '/' . $fname;
            if (file_exists($fpath) && @unlink($fpath)) $files_deleted++;
        }
    }

    // Video files queued for these scenes
    $vidq_q = mysqli_query($conn,
        "SELECT video_file, video_folder FROM hdb_video_gen_que WHERE podcast_id=$podcast_id");
    while ($vidq_q && ($r = mysqli_fetch_assoc($vidq_q))) {
        $fname = trim($r['video_file'] ?? '');
        $folder = trim($r['video_folder'] ?? '');
        if ($fname !== '' && $folder !== '') {
            $fpath = __DIR__ . '/' . $folder . '/' . $fname;
            if (file_exists($fpath) && @unlink($fpath)) $files_deleted++;
        }
    }

    // Thumbnail copy
    $thumb_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT thumbnail FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if (!empty($thumb_row['thumbnail'])) {
        $thumb_path = __DIR__ . '/podcast_thumbnails/' . $thumb_row['thumbnail'];
        if (file_exists($thumb_path) && @unlink($thumb_path)) $files_deleted++;
    }

    // DB rows — captions first (FK on story_id), then video queue, then stories, then the podcast itself.
    if (!empty($story_ids)) {
        $ids_csv = implode(',', $story_ids);
        mysqli_query($conn, "DELETE FROM hdb_captions WHERE story_id IN ($ids_csv)");
    }
    mysqli_query($conn, "DELETE FROM hdb_video_gen_que WHERE podcast_id=$podcast_id");
    mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id=$podcast_id");
    mysqli_query($conn, "DELETE FROM hdb_podcasts WHERE id=$podcast_id");

    logGeneration("RESET: deleted podcast=$podcast_id (" . count($story_ids) . " story rows, $files_deleted files) for admin=$admin_id", "PRODUCT");

    // Clear every piece of session state tied to this flow so the page
    // genuinely starts fresh — including the resume mechanism, so a
    // reload doesn't try to bring back what we just deleted.
    unset($_SESSION['active_podcast_id'], $_SESSION['story'], $_SESSION['script'],
          $_SESSION['suggestions'], $_SESSION['selected'], $_SESSION['business'],
          $_SESSION['additional_info'], $_SESSION['promoting_item'],
          $_SESSION['promoting_item_photo_path'], $_SESSION['cta_text'], $_SESSION['ad_style']);

    ob_end_clean();
    echo json_encode([
        'success'        => true,
        'deleted'        => true,
        'podcast_id'     => $podcast_id,
        'stories_deleted'=> count($story_ids),
        'files_deleted'  => $files_deleted,
    ]);
    exit;
}

// queue.fal.run with ?fal_webhook=fal_webhook.php. Once kicked, the whole
// pipeline is browser-independent: fal.ai calls the webhook when each
// video is ready, the next cron run downloads + ingests it into
// user_media/.../podcast_videos and updates hdb_podcast_stories.
// A short lockfile stops a burst of clicks forking a storm; the cron's own
// atomic flag claim already prevents double work if two runs overlap.
if (!function_exists('vsg_kick_video_cron')) {
    function vsg_kick_video_cron() {
        $dir  = __DIR__;
        $lock = $dir . '/video_cron_kick.lock';
        if (file_exists($lock) && (time() - filemtime($lock)) < 15) return;
        @touch($lock);
        $php_bin = file_exists('/usr/bin/php') ? '/usr/bin/php'
                 : (file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php');
        $setsid  = file_exists('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
        $script  = escapeshellarg($dir . '/cron_video_gen.php');
        $log     = escapeshellarg($dir . '/video_generation.log');
        @shell_exec("{$setsid}{$php_bin} -d error_reporting=0 -d display_errors=0 {$script} >> {$log} 2>&1 < /dev/null &");
    }
}

// ── AJAX: start_ai_videos ───────────────────────────────────────
// "AI Video Scenes" bulk button. Ensures every AI-generated scene of this
// podcast has a pending (flag=1) row in hdb_video_gen_que, then kicks the
// cron worker so all of them get submitted to fal.ai at once with their
// image (image_folder + image_file) and video prompt. fal.ai returns each
// finished clip via fal_webhook.php → user_media/.../podcast_videos and
// hdb_podcast_stories is updated. Closing the browser does NOT stop it.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'start_ai_videos') {
    ob_start();
    ini_set('display_errors', 0);

    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'No podcast_id']); exit;
    }

    // Ownership — the podcast must exist (and, when company scoping is in play,
    // belong to this admin). Mirrors the lightweight check other handlers use.
    $own = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT admin_id, company_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if (!$own) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Podcast not found']); exit;
    }

    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    $scenes_q = mysqli_query($conn,
        "SELECT id, seq_no, image_file, image_folder, video_prompt, prompt
         FROM hdb_podcast_stories WHERE podcast_id=$podcast_id AND (is_selected = 0 OR is_selected IS NULL) ORDER BY seq_no ASC, id ASC");
    $scene_count    = $scenes_q ? mysqli_num_rows($scenes_q) : 0;
    // ~30s total split across scenes, clamped to fal.ai's usable 3–10s range.
    $scene_duration = max(3, min(10, (int)floor(30 / max(1, $scene_count))));

    $now      = date('Y-m-d H:i:s');
    $queued   = 0;   // newly queued / re-queued (flag set to 1)
    $existing = 0;   // already done or in-flight — left alone
    $skipped  = 0;   // no AI-generated image / no prompt — not eligible

    while ($scenes_q && ($s = mysqli_fetch_assoc($scenes_q))) {
        $sid      = (int)$s['id'];
        $img_file = trim($s['image_file'] ?? '');
        if ($img_file === '') { $skipped++; continue; }   // image not generated yet

        // Only AI-generated images become video — never a real uploaded photo.
        // Folder convention now makes this a pure path check: generated scene
        // images always live under .../product_photos, uploaded product
        // photos always live under .../product_images. No DB lookup needed.
        $img_folder_s = trim($s['image_folder'] ?? '');
        $is_ai = (strpos($img_folder_s, 'product_photos') !== false);
        if (!$is_ai) { $skipped++; continue; }

        $vp = trim((string)($s['video_prompt'] ?? ''));
        if ($vp === '') $vp = trim((string)($s['prompt'] ?? ''));
        if ($vp === '') { $skipped++; continue; }

        $vpe = mysqli_real_escape_string($conn, $vp);
        $vfe = mysqli_real_escape_string($conn, 'video_ai');
        $vne = mysqli_real_escape_string($conn, 'scene_' . $podcast_id . '_' . $sid . '.mp4');

        $vid_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$sid LIMIT 1"));
        if ($vid_row) {
            $flag = (int)$vid_row['videogen_flag'];
            // Leave anything already done (3) or in-flight (2,5,6,7) untouched.
            if (in_array($flag, [2, 3, 5, 6, 7], true)) { $existing++; continue; }
            mysqli_query($conn, "UPDATE hdb_video_gen_que
                SET prompt='$vpe', video_folder='$vfe', media_type='cinematic', video_file='$vne',
                    videogen_flag=1, gen_mode='', duration=$scene_duration, error_msg=NULL, updated_at='$now'
                WHERE id=" . (int)$vid_row['id']);
            $queued++;
        } else {
            $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
                (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,duration,created_at,updated_at)
                VALUES ($podcast_id,$sid,'$vpe','$vfe','cinematic','$vne',1,'',$scene_duration,'$now','$now')");
            if (!$ins) { // fallback for older schema without duration column
                $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
                    (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,created_at,updated_at)
                    VALUES ($podcast_id,$sid,'$vpe','$vfe','cinematic','$vne',1,'','$now','$now')");
            }
            if ($ins) $queued++;
        }
    }

    mysqli_query($conn, "UPDATE hdb_podcasts SET internal_status='videos_generating', updated_at='$now' WHERE id=$podcast_id");

    // Fire the worker now — survives this request and the browser closing.
    vsg_kick_video_cron();

    logGeneration("START AI VIDEOS: podcast=$podcast_id queued=$queued existing=$existing skipped=$skipped scenes=$scene_count", "FAL");

    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'     => true,
        'podcast_id'  => $podcast_id,
        'queued'      => $queued,
        'existing'    => $existing,
        'skipped'     => $skipped,
        'scene_count' => $scene_count,
        'in_progress' => $queued + $existing,
        'message'     => ($queued + $existing) > 0
            ? ($queued + $existing) . ' scene video(s) generating in the background via fal.ai'
            : 'No eligible AI-generated scenes to turn into video',
    ]);
    exit;
}

// ── AJAX: generate_scene_video ──────────────────────────────────
// Per-card "Generate video" button. The actual rendering still happens
// via the existing hdb_video_gen_que cron worker (queued automatically
// right after the image is made) — this endpoint just (a) makes sure a
// queue row exists for this scene if one isn't already there, and
// (b) reports whether the resulting file is on disk yet. Safe to call
// repeatedly — used both for the initial click and for polling.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_video') {
    ob_start();
    ini_set('display_errors', 0);

    $podcast_id   = (int)($_POST['podcast_id'] ?? 0);
    $story_id     = (int)($_POST['story_id']   ?? 0);
    $scene_num    = (int)($_POST['scene_num']  ?? 0);
    $video_prompt = trim($_POST['wan_prompt'] ?? trim($_POST['video_prompt'] ?? ''));
    $gen_mode_in  = trim((string)($_POST['gen_mode'] ?? 'modal.com'));
    $gen_mode     = ($gen_mode_in === 'fal.ai') ? 'fal.ai' : 'modal.com';

    if ($podcast_id && !$story_id && $scene_num) {
        $fb = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_podcast_stories WHERE podcast_id=$podcast_id AND seq_no=$scene_num LIMIT 1"
        ));
        if ($fb) $story_id = (int)$fb['id'];
    }
    if (!$podcast_id || !$story_id) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Missing podcast_id or story_id', 'scene' => $scene_num]);
        exit;
    }

    // ── Only AI-generated images get a video queued — never a real photo ──
    // Folder convention: generated scene images live under .../product_photos,
    // uploaded product photos live under .../product_images. No DB lookup needed.
    $img_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT image_file, image_folder FROM hdb_podcast_stories WHERE id=$story_id LIMIT 1"
    ));
    $scene_is_ai_generated = false;
    if ($img_row && !empty($img_row['image_file'])) {
        $img_folder_chk = trim($img_row['image_folder'] ?? '');
        $scene_is_ai_generated = (strpos($img_folder_chk, 'product_photos') !== false);
    }
    if (!$scene_is_ai_generated) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'This scene uses a real photo, not an AI-generated image — video generation is not available for it.', 'scene' => $scene_num]);
        exit;
    }

    // Same naming convention the image handler already used when it
    // auto-queued this scene's video — must match exactly so we're
    // checking the same file the worker will eventually write.
    $video_folder = 'video_ai';
    $video_file   = 'scene_' . $podcast_id . '_' . $story_id . '.mp4';
    $webRoot      = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');
    $videoPath    = $webRoot . '/' . $video_folder . '/' . $video_file;

    // ── Already rendered? Done — no DB lookups needed. ────────────
    if (is_file($videoPath) && filesize($videoPath) > 1000) {
        ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => true,
            'video_ready' => true,
            'video_url'   => $video_folder . '/' . $video_file . '?t=' . time(),
            'scene'       => $scene_num,
        ]);
        exit;
    }

    // ── Not on disk yet — make sure a queue row exists ────────────
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    $vid_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$story_id LIMIT 1"
    ));

    if (!$vid_row) {
        if (!$video_prompt) {
            ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'No video job queued yet and no prompt supplied to start one', 'scene' => $scene_num]);
            exit;
        }
        $now             = date('Y-m-d H:i:s');
        $scene_count_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM hdb_podcast_stories WHERE podcast_id = $podcast_id AND (is_selected = 0 OR is_selected IS NULL)"
        ));
        $scene_count_val = max(1, (int)($scene_count_row['cnt'] ?? 6));
        $scene_duration  = max(3, min(10, (int)floor(30 / $scene_count_val)));
        $vpe = mysqli_real_escape_string($conn, $video_prompt);
        $vfe = mysqli_real_escape_string($conn, $video_folder);
        $vne = mysqli_real_escape_string($conn, $video_file);
        // gen_mode left blank — fal.ai only; cron defaults blank to fal.ai.
        $gme = '';
        $ins_vid = mysqli_query($conn, "INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,duration,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','cinematic','$vne',1,'$gme',$scene_duration,'$now','$now')");
        if (!$ins_vid) {
            $ins_vid = mysqli_query($conn, "INSERT INTO hdb_video_gen_que (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,created_at,updated_at) VALUES ($podcast_id,$story_id,'$vpe','$vfe','cinematic','$vne',1,'$gme','$now','$now')");
        }
        logGeneration("MANUAL VIDEO QUEUE: " . ($ins_vid ? "inserted" : "FAILED " . mysqli_error($conn)) . " | podcast=$podcast_id scene=$story_id", "FAL");
        $flag = 1;
    } else {
        $flag = (int)$vid_row['videogen_flag'];
    }

    ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'     => true,
        'video_ready' => false,
        'status'      => $flag == 2 ? 'processing' : 'queued',
        'scene'       => $scene_num,
        'message'     => $flag == 2 ? 'Video is being generated…' : 'Video queued — generation starts shortly…',
    ]);
    exit;
}

// ── Detect & emphasize special ambience/casting instructions ──────
// additional_info often contains casting (ethnicity/people) or
// background/ambience directives buried in free text — e.g. "use Indian
// and Pakistani people" or "set it at sunset, not daylight". The AI
// calls below already receive this text, but burying it at the end of
// a longer instructions blob is easy to under-weight — especially since
// the script-generation prompt has its own hardcoded style rules
// (faceless, cool-neutral lighting) that can silently override what the
// user actually asked for. This pulls out any such directives and
// restates them as an explicit override so every step honors them.
function buildSpecialInstructionsBlock($additional) {
    $additional = trim((string)$additional);
    if ($additional === '') return '';

    $castingPattern  = '/\b(indian|pakistani|south\s*asian|bangladeshi|sri\s*lankan|african|black|white|caucasian|latino|latina|hispanic|asian|east\s*asian|middle\s*eastern|arab|european|ethnicity|nationality|cast|actor|actress|model|people|men|women|man|woman|family|couple|kids|children)\b/i';
    $ambiencePattern = '/\b(background|backdrop|setting|location|ambience|ambiance|mood|vibe|sunset|sunrise|golden\s*hour|night\s*time|daytime|indoor|outdoor|warm\s*tone|cool\s*tone|color\s*tone|lighting|cafe|café|restaurant|beach|forest|street|studio|kitchen|garden|rooftop)\b/i';
    $hasCasting  = (bool)preg_match($castingPattern, $additional);
    $hasAmbience = (bool)preg_match($ambiencePattern, $additional);

    $block = "\n\nADDITIONAL INSTRUCTIONS (from user):\n$additional";

    if ($hasCasting || $hasAmbience) {
        $parts = [];
        if ($hasCasting)  $parts[] = "casting/people";
        if ($hasAmbience) $parts[] = "background/ambience";
        $block .= "\n\n⚠️ OVERRIDE — the instructions above include specific " . implode(' and ', $parts)
                . " directives. These take priority over any default style rules elsewhere in this prompt. "
                . "Apply them consistently and explicitly in every scene — not just the overall story, but the "
                . "literal visual description of each scene (who/what is shown, the setting, lighting, mood).";
    }
    return $block;
}

// ── Product-ad awareness — when a real product photo is attached
// ($_SESSION['promoting_item_photo_path'], set by upload_product_photo),
// every script-writing prompt needs to know two things: (1) the product is
// a literal photographed object, not something to invent, and (2) the face
// is always off-limits (inventing one risks looking unrelated to the real
// photographed item), but anything below the chin — hand, wrist, shoulder,
// torso — is fair game and should be used for variety, matching how real
// product ads mix worn/carried lifestyle shots with plain styled shots.
function buildProductPhotoBlock() {
    if (empty($_SESSION['promoting_item_photo_path']) || !file_exists($_SESSION['promoting_item_photo_path'])) return '';
    $ad_style = trim($_SESSION['ad_style'] ?? '');
    $ad_style_line = $ad_style !== ''
        ? "- Overall ad style for EVERY scene in this video: \"$ad_style\". Keep this consistent across all scenes — same mood/tone throughout, not a different style per scene.\n"
        : '';
    return "\n\nPRODUCT-AD MODE: A real photo of the actual physical product is attached and will be edited "
         . "(background/lighting/composition only) for every scene — never regenerated from scratch. So:\n"
         . "- hero_element and setting must describe things that can be PHOTOGRAPHED AROUND the real product "
         . "(surfaces, props, backdrops, lighting), not a redesigned product.\n"
         . "- For 'character', never propose a full face or head shot. Anything below the chin is fine — and "
         . "should be used across the scenes for variety: e.g. a hand adjusting it, a wrist/arm carrying it, a "
         . "shoulder wearing it, or a torso holding it — matching real product-ad photography (think e-commerce "
         . "lifestyle shots, not just a plain studio product shot).\n"
         . "- REQUIRED, not optional: at least 2 of the scenes must show a person actually using/wearing/carrying "
         . "the product in a real-world lifestyle setting (e.g. walking, at the office, out with friends) — not "
         . "just holding it in a studio. The remaining scenes can mix in pure styled-background/product-only shots. "
         . "Don't make every scene identical.\n"
         . $ad_style_line
         . "- Captions/hook/title should read like a premium ad campaign for this exact item — sensory and "
         . "benefit-driven, no generic filler like 'great quality, buy now'.";
}

// ── STEP 1: AI suggests all parameters from niche ─────────────
$_step1_business_posted = trim($_POST['business'] ?? '');
$_step1_business_derived = $_step1_business_posted !== ''
    ? $_step1_business_posted
    : trim(trim($co_ai_subgroup) . ($co_ai_group !== '' ? ' (' . $co_ai_group . ')' : ''));
if ($step == "1" && !empty($_step1_business_derived)) {
    $business = $_step1_business_derived;
    $additional_info = trim($_POST['additional_info'] ?? '');
    $promoting_item  = trim($_POST['promoting_item']  ?? '');
    $cta_text = trim($_POST['cta_text'] ?? '');
    if ($cta_text === '') {
        $cta_text = $co_cta;
    }
    $ad_style = trim($_POST['ad_style'] ?? '');
    $_SESSION['business']        = $business;
    $_SESSION['additional_info'] = $additional_info;
    $_SESSION['promoting_item']  = $promoting_item;
    $_SESSION['cta_text']        = $cta_text;
    $_SESSION['ad_style']        = $ad_style;

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
    $step1_input = "Business/Niche: $business";
    if ($promoting_item) {
        $step1_input .= "\nWhat We Are Promoting Today: $promoting_item\nIMPORTANT: This is the specific item/dish/service being promoted in this particular video. Every field you generate (title, hook, setting, hero_element, etc.) must be anchored to THIS specific item, not the business in general. For example, if the business is \"Indian Restaurant\" and the promoted item is \"Masala Dosa\", the hero_element should be the dosa itself, the hook should reference the dosa specifically, and the title should mention it by name — not generic Indian food.";
    }
    $step1_input .= buildSpecialInstructionsBlock($additional_info);
    $step1_input .= buildProductPhotoBlock();
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, $step1_input, 0.85);

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

// ── STEP 2: Generate Full Video Script directly from selected params ──────────
// (Story concept step removed — parameters go straight to script generation)
if ($step == "2" && isset($_SESSION['suggestions'])) {
    $s = $_SESSION['suggestions'];
    $business = $_SESSION['business'] ?? '';

    $sel = [
        'title'            => trim($_POST['sel_title']            ?? $s['title']['selected']            ?? ''),
        'sentiment'        => trim($_POST['sel_sentiment']        ?? $s['sentiment']['selected']        ?? ''),
        'hook'             => trim($_POST['sel_hook']             ?? $s['hook']['selected']             ?? ''),
        'character'        => trim($_POST['sel_character']        ?? $s['character']['selected']        ?? ''),
        'setting'          => trim($_POST['sel_setting']          ?? $s['setting']['selected']          ?? ''),
        'audio_mood'       => trim($_POST['sel_audio_mood']       ?? $s['audio_mood']['selected']       ?? ''),
        'hero_element'     => trim($_POST['sel_hero_element']     ?? $s['hero_element']['selected']     ?? ''),
        'emotional_outcome'=> trim($_POST['sel_emotional_outcome'] ?? $s['emotional_outcome']['selected'] ?? ''),
    ];
    $_SESSION['selected'] = $sel;

    $additional     = $_SESSION['additional_info'] ?? '';
    $promoting_item = $_SESSION['promoting_item']  ?? '';
    $cta            = $_SESSION['cta_text']        ?? '';

    $paramBlock = "Business: $business\nTitle: {$sel['title']}\nSentiment: {$sel['sentiment']}\nHook: {$sel['hook']}\nCharacter: {$sel['character']}\nSetting: {$sel['setting']}\nAudio Mood: {$sel['audio_mood']}\nHero Element: {$sel['hero_element']}\nEmotional Outcome: {$sel['emotional_outcome']}";
    if ($promoting_item) $paramBlock .= "\nWhat We Are Promoting Today: $promoting_item\nThe entire video must revolve around this specific item — it is the hero of every scene.";
    $paramBlock .= buildSpecialInstructionsBlock($additional);

    $promoting_block = $promoting_item ? "\n\nPROMOTING: $promoting_item\nBuild the entire video around showcasing this specific product/service. Every scene must feature or reference it." : '';
    $promoting_block .= buildProductPhotoBlock();
    $additional_block = buildSpecialInstructionsBlock($additional);
    $cta_block = $cta ? "\n\nCTA / BRAND SIGN-OFF (last scene):\n$cta" : '';

    $systemPrompt = "You are an expert cinematic AI film director.
Convert the approved parameters into a complete 30-40 second faceless cinematic video script.

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
- Default to faceless or POV-based shots UNLESS ADDITIONAL INSTRUCTIONS below specifies particular people/cast to show on screen — if it does, show them as instructed, consistently, in every relevant scene
- Consistent character across all scenes
- Default to bright cool-neutral lighting (NO warm tones) UNLESS ADDITIONAL INSTRUCTIONS below specifies a different time of day, mood, or ambience — if it does, follow that instead";

    $input = "Business: $business\n" . $paramBlock . $promoting_block . $additional_block . $cta_block;
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $input, 0.92, 120);
    if ($response) {
        $_SESSION['script'] = $response;
        // Store a minimal story stub so downstream $story checks still pass
        $_SESSION['story']  = $response;
        unset($_SESSION['error']);
    } else {
        $_SESSION['error'] = "Script generation failed — API timeout or key issue. Please try again.";
        error_log("[vizard step2-direct] callAI returned null for script generation");
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
- Default to faceless or POV-based shots UNLESS ADDITIONAL INSTRUCTIONS below specifies particular people/cast to show on screen — if it does, show them as instructed, consistently, in every relevant scene
- Consistent character across all scenes
- Default to bright cool-neutral lighting (NO warm tones) UNLESS ADDITIONAL INSTRUCTIONS below specifies a different time of day, mood, or ambience — if it does, follow that instead";

    $additional      = $_SESSION['additional_info'] ?? '';
    $promoting_item  = $_SESSION['promoting_item']  ?? '';
    $cta = $_SESSION['cta_text'] ?? '';
    $promoting_block  = $promoting_item ? "\n\nPROMOTING: $promoting_item\nBuild the entire video around showcasing this specific product/service. Every scene must feature or reference it." : '';
    $promoting_block .= buildProductPhotoBlock();
    $additional_block = buildSpecialInstructionsBlock($additional);
    $cta_block = $cta ? "\n\nCTA / BRAND SIGN-OFF (last scene):\n$cta" : '';
    $input = "Business: $business\nAPPROVED STORY:\n$story" . $promoting_block . $additional_block . $cta_block;
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
    $promoting_item  = $_SESSION['promoting_item'] ?? '';
    $step4_input = "Business/Niche: $business" . ($promoting_item ? "\nWhat We Are Promoting Today: $promoting_item\nIMPORTANT: Anchor every field (title, hook, setting, hero_element, etc.) to THIS specific item, not the business in general — e.g. if promoting \"Masala Dosa\" at an Indian Restaurant, the hero_element should be the dosa itself, not generic Indian food." : '') . buildSpecialInstructionsBlock($additional) . buildProductPhotoBlock();
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, $step4_input, 0.95);
    $suggestions = $raw ? json_decode($raw, true) : null;
    if ($suggestions) {
        $_SESSION['suggestions'] = $suggestions;
        unset($_SESSION['story'], $_SESSION['script'], $_SESSION['selected']);
    }
}

$suggestions = $_SESSION['suggestions'] ?? null;
$business = $_SESSION['business'] ?? '';
if ($business === '') {
    $business = trim(trim($co_ai_subgroup) . ($co_ai_group !== '' ? ' (' . $co_ai_group . ')' : ''));
}
$story = $_SESSION['story'] ?? '';
$script = $_SESSION['script'] ?? '';
$additional_info = $_SESSION['additional_info'] ?? '';
$promoting_item  = $_SESSION['promoting_item']  ?? '';
$cta_text = $_SESSION['cta_text'] ?? '';
if ($cta_text === '') {
    $cta_text = $co_cta;
}

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
<title>VideoVizard — Cinematic Business Video </title>
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
    <h1>Cinematic Business Video 1</h1>
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

<!-- ── Business Profile bar — opens the Business Settings overlay ─────────── -->
<?php if ($company): ?>
<div class="settings-bar" onclick="openBizSettings()" style="margin-bottom:6px;cursor:pointer;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 18px;">
  <span style="font-size:11px;font-weight:700;color:var(--muted);letter-spacing:.5px;">BUSINESS</span>
  <?php if ($co_profile_set): ?>
    <span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;"><?= htmlspecialchars($co_ai_group) ?></span>
    <span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;"><?= htmlspecialchars($co_ai_subgroup) ?></span>
  <?php else: ?>
    <span style="font-size:12px;color:var(--muted);font-style:italic;">Not set</span>
  <?php endif; ?>
  <span style="margin-left:auto;font-size:12px;color:var(--dark-blue);font-weight:600;">Edit ›</span>
</div>

<!-- ── Target Location/Audience bar — opens a small edit modal ─────────────── -->
<div class="settings-bar" onclick="openTargetSettings()" style="margin-bottom:6px;cursor:pointer;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 18px;">
  <span style="font-size:11px;font-weight:700;color:var(--muted);letter-spacing:.5px;">TARGET</span>
  <?php if ($co_target_set): ?>
    <?php if ($co_target_location !== ''): ?>
      <span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">📍 <?= htmlspecialchars($co_target_location) ?></span>
    <?php endif; ?>
    <?php if ($co_target_audience !== ''): ?>
      <span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">👥 <?= htmlspecialchars($co_target_audience) ?></span>
    <?php endif; ?>
  <?php else: ?>
    <span style="font-size:12px;color:var(--muted);font-style:italic;">Not set</span>
  <?php endif; ?>
  <span style="margin-left:auto;font-size:12px;color:var(--dark-blue);font-weight:600;">Edit ›</span>
</div>

<!-- ── Target Settings overlay — simple two-field modal, no wizard needed ──── -->
<div id="targetOverlay" class="settings-overlay" onclick="targetOverlayClick(event)">
  <div class="settings-panel" style="max-width:420px;">
    <div class="settings-header">
      <span class="settings-title">🎯 Target Audience &amp; Location</span>
      <button class="settings-close" onclick="closeTargetSettings()">✕</button>
    </div>
    <div class="setting-group">
      <div class="setting-label">📍 Target Location</div>
      <input type="text" id="targetLocationInput" class="business-input" style="width:100%;"
          placeholder="e.g. Toronto, Canada — or Nationwide">
    </div>
    <div class="setting-group" style="margin-bottom:8px;">
      <div class="setting-label">👥 Target Audience</div>
      <input type="text" id="targetAudienceInput" class="business-input" style="width:100%;"
          placeholder="e.g. Young professionals aged 25-40">
    </div>
    <div class="biz-footer">
      <button class="biz-done-btn" id="targetSaveBtn" onclick="saveTargetSettings()">Save</button>
    </div>
  </div>
</div>

<!-- ── Business Settings overlay ────────────────────────────────────────────── -->
<div id="bizOverlay" class="settings-overlay" onclick="bizOverlayClick(event)">
  <div class="settings-panel">
    <div class="settings-header">
      <span class="settings-title">🏢 Business Settings</span>
      <button class="settings-close" onclick="closeBizSettings()">✕</button>
    </div>
    <div id="biz-content"></div>
    <p style="font-size:11px;color:#bbb;margin-top:6px;text-align:center;">Saved to your company profile</p>
  </div>
</div>

<style>
.settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.settings-overlay.open { display: flex; }
.settings-panel { background: #fff; border-radius: 16px; padding: 28px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.settings-title  { font-size: 17px; font-weight: 700; color: var(--dark-blue); }
.settings-close  { background: none; border: none; font-size: 22px; color: var(--muted); cursor: pointer; }
.setting-group   { margin-bottom: 20px; }
.setting-label   { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
.setting-opts    { display: flex; flex-wrap: wrap; gap: 7px; }
.sopt { padding: 7px 13px; border: 1.5px solid var(--border); border-radius: 7px; background: #fff; color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.sopt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.sopt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }
.biz-back-btn { background: none; border: none; color: var(--purple); font-size: 12px; font-weight: 700; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 3px; }
.biz-back-btn:hover { text-decoration: underline; }
.biz-step-dots { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 16px; }
.biz-sdot { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.biz-sdot-icon { width: 26px; height: 26px; border-radius: 50%; background: var(--border); border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #aaa; transition: all .2s; }
.biz-sdot-label { font-size: 10px; color: var(--muted); white-space: nowrap; }
.biz-sdot-active .biz-sdot-icon { background: var(--dark-blue); border-color: var(--dark-blue); color: #fff; }
.biz-sdot-active .biz-sdot-label { color: var(--dark-blue); font-weight: 700; }
.biz-sdot-done .biz-sdot-icon { background: var(--green); border-color: var(--green); color: #fff; }
.biz-sdot-done .biz-sdot-label { color: var(--green); }
.biz-sdot-line { flex: 1; height: 2px; background: var(--border); margin: 0 6px 14px; min-width: 28px; }
.biz-breadcrumb { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; background: #f7f9fc; border-radius: 8px; padding: 7px 12px; margin-bottom: 14px; font-size: 12px; min-height: 34px; }
.biz-bc-item { color: var(--muted); }
.biz-bc-active { color: var(--dark-blue); font-weight: 700; }
.biz-bc-sep { color: #ccc; font-size: 10px; }
.biz-footer { margin-top: 18px; display: flex; justify-content: flex-end; border-top: 1px solid #f0f0f0; padding-top: 14px; }
.biz-done-btn { padding: 11px 28px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s; }
.biz-done-btn:hover:not(:disabled) { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.biz-done-btn:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; }
.loading { display: flex; align-items: center; gap: 8px; justify-content: center; padding: 20px 0; font-size: 13px; color: var(--muted); }
.loading .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--purple); animation: bizDotPulse 1s infinite ease-in-out; }
.loading .dot:nth-child(2) { animation-delay: .15s; }
.loading .dot:nth-child(3) { animation-delay: .3s; }
@keyframes bizDotPulse { 0%, 80%, 100% { opacity: .3; } 40% { opacity: 1; } }
</style>

<script>
const bizTemp = { group: <?= json_encode($co_ai_group) ?>, subgroup: <?= json_encode($co_ai_subgroup) ?> };

function bizEsc(s)     { return String(s).replace(/"/g,'&quot;'); }
function bizEscHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function openBizSettings() {
    bizTemp.group    = <?= json_encode($co_ai_group) ?>;
    bizTemp.subgroup = <?= json_encode($co_ai_subgroup) ?>;
    document.getElementById('bizOverlay').classList.add('open');

    if (!window._bizGroups || window._bizGroups.length === 0) {
        _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading…</span></div>`);
        try {
            const fd = new FormData(); fd.append('ajax_action', 'get_promo_groups');
            const res = await fetch('', { method: 'POST', body: fd });
            const d = await res.json();
            window._bizGroups = d.success ? (d.groups || []) : [];
        } catch (e) { window._bizGroups = []; }
    }
    _renderBizGroupPanel();
}

function _renderBizPanel(html) {
    document.getElementById('biz-content').innerHTML = html;
}

function _bizBreadcrumb() {
    const parts = [bizTemp.group, bizTemp.subgroup].filter(Boolean);
    if (!parts.length) return '';
    return `<div class="biz-breadcrumb">
      ${parts.map((p,i) => `<span class="biz-bc-item${i===parts.length-1?' biz-bc-active':''}">${bizEscHtml(p)}</span>`).join('<span class="biz-bc-sep">›</span>')}
    </div>`;
}

function _bizStepDots(active) {
    const steps = ['Category','Subcategory'];
    return `<div class="biz-step-dots">
      ${steps.map((s,i) => `<div class="biz-sdot${i<active?' biz-sdot-done':i===active?' biz-sdot-active':''}">
        <span class="biz-sdot-icon">${i<active?'✓':i+1}</span>
        <span class="biz-sdot-label">${s}</span>
      </div>`).join('<div class="biz-sdot-line"></div>')}
    </div>`;
}

// ── PANEL 1: Category ────────────────────────────────────────────────────────
function _renderBizGroupPanel() {
    const groups = window._bizGroups || [];
    const chips = groups.map(g =>
        `<div class="sopt${bizTemp.group===g.group?' sel':''}"
            onclick="bizSelectGroup(this)"
            data-v="${bizEsc(g.group)}">${g.icon ? bizEscHtml(g.icon) + ' ' : ''}${bizEscHtml(g.group)}</div>`
    ).join('');

    _renderBizPanel(`
      ${_bizStepDots(0)}
      ${_bizBreadcrumb()}
      <div class="setting-group">
        <div class="setting-label">Select your industry group</div>
        <div class="setting-opts" id="biz-group-opts">${chips}</div>
      </div>
      <div class="biz-footer">
        <button class="biz-done-btn" id="biz-done-group" onclick="_bizGroupDone()"
            ${bizTemp.group ? '' : 'disabled'}>Done →</button>
      </div>`);
}

function bizSelectGroup(el) {
    document.querySelectorAll('#biz-group-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    const newGroup = el.dataset.v;
    if (bizTemp.group !== newGroup) {
        bizTemp.group = newGroup;
        bizTemp.subgroup = '';
        delete window['_bizSgCache_' + newGroup];
    }
    document.getElementById('biz-done-group').disabled = false;
}

async function _bizGroupDone() {
    if (!bizTemp.group) return;
    await _renderBizSubgroupPanel();
}

// ── PANEL 2: Subcategory ─────────────────────────────────────────────────────
async function _renderBizSubgroupPanel() {
    const cacheKey = '_bizSgCache_' + bizTemp.group;
    _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading…</span></div>`);

    if (!window[cacheKey]) {
        try {
            const fd = new FormData(); fd.append('ajax_action', 'get_promo_subgroups'); fd.append('group', bizTemp.group);
            const res = await fetch('', { method: 'POST', body: fd });
            const d = await res.json();
            window[cacheKey] = d.success ? (d.subgroups || []) : [];
        } catch (e) { window[cacheKey] = []; }
    }

    const subs = window[cacheKey];
    const chips = subs.map(sg =>
        `<div class="sopt${bizTemp.subgroup===sg?' sel':''}"
            onclick="bizSelectSubgroup(this)"
            data-v="${bizEsc(sg)}">${bizEscHtml(sg)}</div>`
    ).join('');

    _renderBizPanel(`
      ${_bizStepDots(1)}
      ${_bizBreadcrumb()}
      <div class="setting-group">
        <div class="setting-label">Select your industry
          <button class="biz-back-btn" style="margin-left:8px;" onclick="_renderBizGroupPanel()">← Back</button>
        </div>
        <div class="setting-opts" id="biz-subgroup-opts">${chips}</div>
      </div>
      <div class="biz-footer">
        <button class="biz-done-btn" id="biz-done-subgroup" onclick="_bizSubgroupDone()"
            ${bizTemp.subgroup ? '' : 'disabled'}>Done →</button>
      </div>`);
}

function bizSelectSubgroup(el) {
    document.querySelectorAll('#biz-subgroup-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    bizTemp.subgroup = el.dataset.v;
    document.getElementById('biz-done-subgroup').disabled = false;
}

async function _bizSubgroupDone() {
    if (!bizTemp.subgroup) return;
    await _bizSaveAndClose();
}

// ── Final commit — save category + subcategory, then close ──────────────────
async function _bizSaveAndClose() {
    try {
        const fd1 = new FormData(); fd1.append('ajax_action','save_company_industry'); fd1.append('field','ai_group');    fd1.append('value', bizTemp.group);
        const fd2 = new FormData(); fd2.append('ajax_action','save_company_industry'); fd2.append('field','ai_subgroup'); fd2.append('value', bizTemp.subgroup);
        await fetch('', { method:'POST', body: fd1 });
        await fetch('', { method:'POST', body: fd2 });
    } catch (e) {}
    closeBizSettings();
    location.reload();
}

function closeBizSettings() {
    document.getElementById('bizOverlay').classList.remove('open');
}

function bizOverlayClick(e) {
    if (e.target === document.getElementById('bizOverlay')) closeBizSettings();
}

// ── Target Location/Audience — simple two-field modal ───────────────────────
function openTargetSettings() {
    document.getElementById('targetLocationInput').value = <?= json_encode($co_target_location) ?>;
    document.getElementById('targetAudienceInput').value = <?= json_encode($co_target_audience) ?>;
    document.getElementById('targetOverlay').classList.add('open');
}
function closeTargetSettings() {
    document.getElementById('targetOverlay').classList.remove('open');
}
function targetOverlayClick(e) {
    if (e.target === document.getElementById('targetOverlay')) closeTargetSettings();
}
async function saveTargetSettings() {
    const loc = document.getElementById('targetLocationInput').value.trim();
    const aud = document.getElementById('targetAudienceInput').value.trim();
    const btn = document.getElementById('targetSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const fd1 = new FormData(); fd1.append('ajax_action','save_company_industry'); fd1.append('field','target_location'); fd1.append('value', loc);
        const fd2 = new FormData(); fd2.append('ajax_action','save_company_industry'); fd2.append('field','target_audience'); fd2.append('value', aud);
        await fetch('', { method:'POST', body: fd1 });
        await fetch('', { method:'POST', body: fd2 });
    } catch (e) {}
    closeTargetSettings();
    location.reload();
}
</script>
<?php endif; ?>




<?php if (!$suggestions): ?>
<div class="card">
    <div class="card-title">Step 1 — What Are You Promoting</div>
    <form method="POST">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>">
        <div style="margin-top:12px;">
            <label class="field-label">🎯 What Are You Promoting?</label>
            <input type="text" name="promoting_item" class="business-input" style="width:100%;margin-top:4px;"
                placeholder="e.g. Masala Dosa, Weekend Brunch Special, New Summer Collection"
                value="<?= htmlspecialchars($promoting_item) ?>">
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">The video will be built entirely around showcasing this specific product or service.</div>
        </div>
        <div style="margin-top:12px;border:1.5px solid var(--purple);border-radius:10px;padding:12px 14px;background:var(--purple-lt, #ede9fe);" id="productPhotoCard">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:13px;font-weight:600;color:var(--dark-blue);">🏷️ This Exact Product's Photo</span>
                <button type="button" class="btn btn-gray" style="margin-left:auto;font-size:12px;padding:7px 14px;" onclick="document.getElementById('productPhotoInput').click()">+ Upload</button>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">Upload a real photo of the actual item being sold. Every scene in this video will EDIT this exact photo (new background/lighting/angle) instead of generating a different-looking product — the buyer sees what they're actually getting.</div>
            <input type="file" id="productPhotoInput" accept="image/*" style="display:none;" onchange="handleProductPhotoUpload(this)">
            <div id="productPhotoResult" style="margin-top:10px;"></div>
        </div>
        <?php if (!empty($_SESSION['promoting_item_photo_path']) && file_exists($_SESSION['promoting_item_photo_path'])): ?>
        <script>document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('productPhotoResult').innerHTML =
                '<div style="font-size:12px;color:var(--dark-blue);font-weight:600;">✓ Product photo attached — every scene will edit this exact photo.</div>';
        });</script>
        <?php endif; ?>
        <div style="margin-top:12px;">
            <label class="field-label">🎬 Ad Style (applies to the whole video)</label>
            <select name="ad_style" class="business-input" style="width:100%;margin-top:4px;">
                <option value="">Auto — let AI decide per scene</option>
                <option value="Luxury ad" <?= ($_SESSION['ad_style'] ?? '') === 'Luxury ad' ? 'selected' : '' ?>>Luxury ad</option>
                <option value="Minimalist ad" <?= ($_SESSION['ad_style'] ?? '') === 'Minimalist ad' ? 'selected' : '' ?>>Minimalist ad</option>
                <option value="Gift ad" <?= ($_SESSION['ad_style'] ?? '') === 'Gift ad' ? 'selected' : '' ?>>Gift ad</option>
                <option value="Business/professional ad" <?= ($_SESSION['ad_style'] ?? '') === 'Business/professional ad' ? 'selected' : '' ?>>Business/professional ad</option>
                <option value="Sports ad" <?= ($_SESSION['ad_style'] ?? '') === 'Sports ad' ? 'selected' : '' ?>>Sports ad</option>
                <option value="UGC-style ad" <?= ($_SESSION['ad_style'] ?? '') === 'UGC-style ad' ? 'selected' : '' ?>>UGC-style ad</option>
            </select>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">One consistent ad style/mood for every scene in this video — set once here instead of per scene.</div>
        </div>
        <div style="margin-top:12px;">
            <label class="field-label">📝 Brief Description / Special Instructions</label>
            <textarea name="additional_info" rows="3" class="business-input" style="width:100%;margin-top:4px;" placeholder="e.g. A cozy weekend brunch spot known for its outdoor garden seating. Use Indian and Pakistani people in the video. Set it at golden hour instead of daylight."><?= htmlspecialchars($additional_info) ?></textarea>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">Describe the product/service in more detail, and/or give any specific instructions for casting, background, or ambience — these will be applied across the whole video.</div>
        </div>
        <div style="margin-top:12px;">
            <label class="field-label">📣 CTA / Brand Sign-Off</label>
            <textarea name="cta_text" rows="2" class="business-input" style="width:100%;margin-top:4px;"><?= htmlspecialchars($cta_text) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:18px;justify-content:center;padding:14px;">✨ Get AI Suggestions</button>
    </form>
</div>
<?php endif; ?>
<script>
async function handleProductPhotoUpload(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    const resultEl = document.getElementById('productPhotoResult');
    resultEl.innerHTML = '<div style="font-size:12px;color:var(--muted);">Uploading…</div>';

    const fd = new FormData();
    fd.append('ajax_action', 'upload_product_photo');
    fd.append('product_photo', file);

    try {
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.success) {
            resultEl.innerHTML = '<div style="font-size:12px;color:#dc2626;">✗ ' + (data.error || 'Upload failed') + '</div>';
            return;
        }
        resultEl.innerHTML =
            '<div style="display:flex;align-items:center;gap:10px;">' +
              '<img src="' + data.photo_url + '" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid var(--border);">' +
              '<div style="font-size:12px;color:var(--dark-blue);font-weight:600;">✓ Attached — every scene will edit this exact photo.</div>' +
            '</div>';
        // Carry the path through Step 1's own form submit too, in case the
        // session cookie is ever unavailable (e.g. some proxy setups).
        let hidden = document.getElementById('productPhotoPathHidden');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'productPhotoPathHidden';
            hidden.name = 'product_photo_path';
            input.closest('form').appendChild(hidden);
        }
        hidden.value = data.photo_path;
    } catch (e) {
        resultEl.innerHTML = '<div style="font-size:12px;color:#dc2626;">✗ Network error — try again</div>';
    }
}
</script>

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
            <button type="submit" class="btn btn-green">🎬 Generate Video Script</button>
            <button type="button" class="btn btn-orange" onclick="freshSuggestions()">🔀 More Ideas</button>
            <button type="button" class="btn btn-gray" onclick="resetPodcast()">🗑️ Reset Everything</button>
        </div>
    </div>
</form>
<form method="POST" id="freshForm"><input type="hidden" name="step" value="4"><input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>"><input type="hidden" name="additional_info" value="<?= htmlspecialchars($additional_info) ?>"><input type="hidden" name="promoting_item" value="<?= htmlspecialchars($promoting_item) ?>"><input type="hidden" name="cta_text" value="<?= htmlspecialchars($cta_text) ?>"></form>
<?php endif; ?>

<?php if ($script && $step >= 2): ?>
<div class="card">
    <div class="card-title">Step 3 — Full Video Script</div>

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
    <?php if (!$has_enough_credits) { ?>

        <?php if ($plan_type == 'free_trial') { ?>
        <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a); border-radius:16px; padding:24px 28px; margin-top:18px; text-align:center;">
            <div style="font-size:32px; margin-bottom:12px;">🚀</div>
            <div style="font-size:17px; font-weight:800; color:#fff; margin-bottom:10px;">You've reached your free trial limit.</div>
            <div style="font-size:13px; color:rgba(255,255,255,.78); line-height:1.65; margin-bottom:18px;">
                Thanks for trying VideoVizard! Choose a subscription plan to keep creating with unlimited generations, no watermarks, and full workspace features.
            </div>
            <a href="/pricing_free_trial.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block; background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; padding:12px 28px; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; box-shadow:0 4px 16px rgba(59,130,246,.35);">
                View Subscription Plans &rarr;
            </a>
            <div style="font-size:11px; color:rgba(255,255,255,.45); margin-top:12px;">Free Trial &middot; No credits remaining</div>
        </div>

        <?php } else if ($plan_type == 'agency') { ?>
        <div style="background:linear-gradient(135deg,#064e3b,#065f46); border-radius:16px; padding:24px 28px; margin-top:18px; text-align:center;">
            <div style="font-size:32px; margin-bottom:12px;">⚡</div>
            <div style="font-size:17px; font-weight:800; color:#fff; margin-bottom:10px;">You've run out of credits.</div>
            <div style="font-size:13px; color:rgba(255,255,255,.78); line-height:1.65; margin-bottom:18px;">
                You're on our Agency plan. Top up instantly with a Pay As You Go credit pack &mdash; your subscription stays unchanged.
            </div>
            <a href="/pricing_agency.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block; background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; padding:12px 28px; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; box-shadow:0 4px 16px rgba(34,197,94,.35);">
                Buy Credit Pack &rarr;
            </a>
            <div style="font-size:11px; color:rgba(255,255,255,.45); margin-top:12px;">Agency Plan &middot; No credits remaining</div>
        </div>

        <?php } else { ?>
        <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a); border-radius:16px; padding:24px 28px; margin-top:18px; text-align:center;">
            <div style="font-size:32px; margin-bottom:12px;">💳</div>
            <div style="font-size:17px; font-weight:800; color:#fff; margin-bottom:10px;">You've run out of credits.</div>
            <div style="font-size:13px; color:rgba(255,255,255,.78); line-height:1.65; margin-bottom:18px;">
                You're on the Personal plan. Top up instantly with Pay As You Go credits, or upgrade to Agency for 10&times; the monthly volume.
            </div>
            <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <a href="/pricing_personal.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block; background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; padding:12px 24px; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; box-shadow:0 4px 16px rgba(34,197,94,.3);">
                    Buy Credits (Pay As You Go) &rarr;
                </a>
                <a href="/pricing_personal.php?return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="display:inline-block; background:rgba(255,255,255,.15); color:#fff; padding:12px 24px; border-radius:10px; font-size:14px; font-weight:700; text-decoration:none; border:1.5px solid rgba(255,255,255,.3);">
                    Upgrade to Agency &rarr;
                </a>
            </div>
            <div style="font-size:11px; color:rgba(255,255,255,.45); margin-top:12px;">Personal Plan &middot; No credits remaining</div>
        </div>

        <?php } ?>

    <?php } else { ?>
    <!-- fal.ai only — hidden radio kept for JS gen_mode compat -->
    <input type="radio" name="gen_mode" value="fal.ai" id="modeFast" style="display:none;" checked>
    <!-- MODAL.COM OPTION COMMENTED OUT
    <input type="radio" name="gen_mode" value="modal.com" id="modeStandard" style="display:none;">
    -->
    <?php } ?>

    <!-- ── Action buttons ────────────────────────────────────────────────── -->
    <div class="actions" style="justify-content:space-between; flex-wrap:wrap; margin-top:20px;">
        <div id="genModeSelector" style="width:100%;margin-bottom:14px;"></div><!-- kept for JS compat, now empty -->
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-green" onclick="generateAllScenes()" id="btnGenScenes" <?= !$has_enough_credits ? 'disabled title="Not enough credits"' : '' ?>>🖼️ Generate Scene Images</button>
            <button type="button" class="btn btn-gray" onclick="resetPodcast()">🗑️ Reset Everything</button>
        </div>
    </div>
</div>
<div id="storyboardSection" style="display:none; margin-top:20px;">
    <div class="card">
        <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
            <button type="button" class="btn btn-gray" onclick="resetPodcast()" style="font-size:12px;">🗑️ Reset Everything</button>
        </div>
        <div class="card-title">🎞️ Scene Storyboard</div>

        <!-- Queue status panel -->
        <div id="queueStatusPanel" style="display:none; margin-bottom:16px;">
            <!-- Image queue -->
            <div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);border-radius:14px;padding:16px 20px;margin-bottom:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;">🖼️</span>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#fff;">Image Generation</div>
                            <div style="font-size:11px;color:rgba(255,255,255,.65);margin-top:2px;" id="imgQueueDetail">Calculating queue...</div>
                        </div>
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
            <!-- Video queue -->
            <div id="vidQueueCard" style="display:none; background:linear-gradient(135deg,#064e3b,#065f46);border-radius:14px;padding:16px 20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;">🎬</span>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#fff;">Video Generation</div>
                            <div style="font-size:11px;color:rgba(255,255,255,.65);margin-top:2px;" id="vidQueueDetail">Starts after images are ready</div>
                        </div>
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
                    <span style="font-size:9px;color:rgba(255,255,255,.4);" id="vidRateLabel">~3 min per video</span>
                </div>
            </div>
        </div>

        <!-- Queuing progress bar (while JS writes rows) -->
        <div id="sceneProgress" style="display:none; margin-bottom:14px;">
            <div class="progress-track"><div id="sceneProgressBar" class="progress-fill" style="width:0%;"></div></div>
            <div style="font-size:11px; margin-top:6px; color:var(--muted);" id="sceneProgressLabel">Queuing scenes...</div>
        </div>
        <div id="storyboardGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:16px;"></div>

        <!-- ── Video cost card — revealed by JS after images are shown ─────── -->
        <div id="videoCostCard" style="display:none; margin-top:20px;">
            <div style="border:1.5px solid var(--border);border-radius:14px;padding:18px 20px;background:#fff;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <!-- Option 1: Static image video -->
                    <button type="button" onclick="goToEditorStaticImages()" id="btnStaticImages"
                        style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:14px 16px;border:2px solid var(--border);border-radius:12px;background:#fff;cursor:pointer;text-align:left;transition:all .15s;"
                        onmouseover="this.style.borderColor='#0f2a44';this.style.background='#f8fafc';"
                        onmouseout="this.style.borderColor='var(--border)';this.style.background='#fff';">
                        <div style="display:flex;align-items:center;gap:8px;width:100%;">
                            <span style="font-size:20px;">🖼️</span>
                            <span style="font-size:13px;font-weight:800;color:#0f2a44;">Static Image Video</span>
                            <span style="margin-left:auto;background:#0f2a44;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;">10 cr</span>
                        </div>
                        <div style="font-size:11px;color:#64748b;line-height:1.5;margin-top:4px;">Use your generated scene images as-is. Click <strong>View Editor</strong> to edit and generate the final video.</div>
                    </button>
                    <!-- Option 2: AI video scenes -->
                    <button type="button" onclick="generateAllVideos()" id="btnGenerateVideos"
                        style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:14px 16px;border:2px solid #f59e0b;border-radius:12px;background:linear-gradient(135deg,#fffbeb,#fef3c7);cursor:pointer;text-align:left;transition:all .15s;"
                        onmouseover="this.style.borderColor='#d97706';this.style.background='#fef3c7';"
                        onmouseout="this.style.borderColor='#f59e0b';this.style.background='linear-gradient(135deg,#fffbeb,#fef3c7)';">
                        <div style="display:flex;align-items:center;gap:8px;width:100%;">
                            <span style="font-size:20px;">🎬</span>
                            <span style="font-size:13px;font-weight:800;color:#0f2a44;" id="aiVideoBtnLabel">AI Video Scenes</span>
                            <span style="margin-left:auto;background:#f59e0b;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;white-space:nowrap;" id="videoCostBadgeBtn">— cr</span>
                        </div>
                        <div style="font-size:11px;color:#92400e;line-height:1.5;margin-top:4px;" id="aiVideoBtnDesc">Your videos for each scene are generated in the background — it takes about 5-6 minutes. Meanwhile, you can go to the video editor and view the video with static images, then reload after 5 minutes.</div>
                    </button>
                </div>

                <!-- Status message shown once AI video generation has been started -->
                <div id="videoGenStatusMsg" style="display:none; margin-top:14px; background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1.5px solid #93c5fd; border-radius:12px; padding:14px 16px;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <span style="font-size:20px;">⏳</span>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#1e3a5f;">Your videos are being generated — this will take a few minutes.</div>
                            <div style="font-size:12px;color:#3b5a78;margin-top:4px;line-height:1.5;">You can wait here to see the videos appear, or go to the editor now and start editing with the stock images while they finish.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="videoEditorBar" style="display:none; margin-top:20px; text-align:center;">
            <a id="videoEditorLink" href="#" class="btn btn-green" style="text-decoration:none; display:inline-block;">🎬 Go to video editor</a>
        </div>
    </div>
</div>

<!-- Video player modal -->
<div id="videoPlayerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;">
    <div style="position:relative;width:92%;max-width:380px;">
        <button type="button" onclick="closeVideoModal()" style="position:absolute;top:-38px;right:0;background:none;border:none;font-size:26px;cursor:pointer;color:#fff;">✕</button>
        <video id="videoPlayerEl" controls autoplay playsinline style="width:100%;border-radius:12px;background:#000;display:block;"></video>
    </div>
</div>
<script>const FULL_SCRIPT = <?= json_encode($script) ?>;</script>
<script>const GLOBAL_AD_STYLE = <?= json_encode(trim($_SESSION['ad_style'] ?? '')) ?>;</script>
<?php endif; ?>
</div><!-- /.container -->
</div><!-- /.page-wrap -->

<script>
function btnLoading(btn, text) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span>' + text; }
function btnReset(btn) { btn.disabled = false; btn.innerHTML = btn.dataset.orig || btn.innerHTML; }

// encodeURIComponent leaves apostrophes (') unescaped, which breaks any
// onclick="fn('${encoded}')" handler the moment a prompt contains "it's" /
// "model's" / etc. Base64 has no quote characters at all, so it's safe to
// embed inside a single-quoted inline attribute regardless of content.
function safeEnc(str) { try { return btoa(encodeURIComponent(str || '')); } catch(e) { return ''; } }
function safeDec(str) { try { return decodeURIComponent(atob(str || '')); } catch(e) { return ''; } }
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
        if (!wanPrompt || wanPrompt.length < 20) {
            // Strip ON-SCREEN TEXT / CAPTION lines before using block as fallback
            var cleanBlock = block
                .replace(/ON[.\s-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*[^\n]+/gi, '')
                .replace(/CAPTION\s*[:\-]?\s*[^\n]+/gi, '')
                .replace(/#{1,3}|\*{1,2}/g, '')
                .replace(/\n+/g, ' ').trim();
            wanPrompt = cleanBlock.substring(0, 800);
        }
        let caption = '';
        const capRe = /ON[.\s-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*([^\n]+)/i, capM = block.match(capRe);
        if (capM) caption = capM[1].trim().replace(/^(NONE|none|-|N\/A)$/i, '').trim();
        let title = markers[i].header.replace(/#{1,3}|\*{1,2}|\bSCENE\s*\d+\b/gi,'').replace(/[—\-–:]/g,'').trim();
        if (!title) title = block.trim().split('\n')[0].substring(0,60) || ('Scene ' + num);
        scenes.push({ num, title, wan: wanPrompt, caption });
    }
    return scenes;
}
// -- Generation mode UI + ETA loading ------------------------------------
// updateModeUI — no-op now: modal.com card removed, always fal.ai
function updateModeUI() {}

// loadModeEtas — no-op: single mode card removed, cost shown in revealVideoCostCard()
async function loadModeEtas() {}

// Load ETAs when page is ready
document.addEventListener('DOMContentLoaded', function() {
    loadModeEtas();
    updateModeUI();
    // Also trigger on radio click since DOMContentLoaded fires once
    document.querySelectorAll('input[name="gen_mode"]').forEach(function(r) {
        r.addEventListener('change', function() { updateModeUI(); loadModeEtas(); });
    });
});

// Shared option lists for the "Generate More" shot-type / ad-type pickers.
const SHOT_TYPE_OPTIONS = [
    ['', 'Shot type: Auto'],
    ['Rotating product shot on a dark background', 'Rotating, dark background'],
    ['Macro close-up shot highlighting fine details and texture', 'Macro close-up'],
    ['In-hand / wrist or hand-held shot showing scale and real-world use', 'In-hand / wrist shot'],
    ['Lifestyle scene — office, driving, or coffee shop setting', 'Lifestyle scene'],
    ['Waterproof / durability demonstration shot', 'Waterproof demo'],
    ['Premium hero reveal shot', 'Luxury reveal'],
];
function buildSelectOptionsHTML(options) {
    return options.map(o => `<option value="${o[0].replace(/"/g,'&quot;')}">${o[1]}</option>`).join('');
}

// ── NEW preview-then-save model ──────────────────────────────────────────
// Nothing is written to the database during generation/picking. Every
// candidate image lives only in `draftScenes` (this array) and in a
// session-scoped draft folder on disk. Only when the user clicks Continue
// does save_cinematic_to_db get called — once — with the final 6 picks and
// the static/dynamic choice. There is no podcast_id, no story_id, and
// nothing to "resume" before that point: a reload here just starts over,
// which is an accepted tradeoff for how much simpler this makes everything.
//
// Ad style is now a single global choice (set once in Step 1, applied
// automatically server-side via $_SESSION['ad_style']) — GLOBAL_AD_STYLE
// here is just for display, so the user can see it on every scene without
// having to set it per scene. Shot type stays per-scene since different
// scenes genuinely want different shots (macro vs lifestyle vs wrist, etc).
let draftScenes = []; // [{ num, wan, caption, title, candidates:[{file,folder,source,shotType}], selectedIndex }]

function buildDraftCardHTML(scene) {
    const cand = scene.candidates[scene.selectedIndex];
    const imgPath = cand.folder + '/' + cand.file + '?t=' + Date.now();
    const badge = '<div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(5,150,105,.88);color:#fff;">⚡ Generated</div>';
    const shotBadge = cand.shotType
        ? `<div style="position:absolute;top:5px;right:5px;padding:3px 8px;border-radius:5px;font-size:9px;font-weight:700;background:rgba(15,42,68,.78);color:#fff;max-width:65%;text-align:right;">${cand.shotType}</div>`
        : '';

    const thumbs = scene.candidates.map((c, idx) => `
        <img src="${c.folder}/${c.file}?t=${Date.now()}"
             class="variant-thumb${idx === scene.selectedIndex ? ' is-selected' : ''}"
             title="${(c.shotType || 'Auto').replace(/"/g,'&quot;')}"
             style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid ${idx === scene.selectedIndex ? '#0f2a44' : 'transparent'};cursor:pointer;flex-shrink:0;"
             onclick="selectDraftCandidate(${scene.num}, ${idx})">
    `).join('');

    return `
        <div style="position:relative;aspect-ratio:9/16;" id="main-media-${scene.num}">
            <img src="${imgPath}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
            ${badge}
            ${shotBadge}
        </div>
        <div style="padding:8px 10px;">
            <div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ${scene.num}</div>
            <div style="font-size:10px;color:#64748b;margin-top:2px;">${(scene.caption||scene.title||'').substring(0,45)}</div>
            ${GLOBAL_AD_STYLE ? `<div style="font-size:9px;color:var(--muted);margin-top:3px;">🎬 ${GLOBAL_AD_STYLE} (applies to whole video)</div>` : ''}
        </div>
        <div class="variant-strip" id="variant-strip-${scene.num}" style="display:flex;gap:6px;padding:0 10px 8px;overflow-x:auto;align-items:center;">
            ${thumbs}
        </div>
        <div class="variant-controls" id="variant-controls-${scene.num}" style="display:flex;gap:5px;padding:0 10px 10px;flex-wrap:wrap;align-items:center;">
            <select id="shot-type-${scene.num}" style="font-size:10px;padding:5px 4px;border-radius:6px;border:1px solid var(--border);background:var(--card);color:var(--text);max-width:140px;">
                ${buildSelectOptionsHTML(SHOT_TYPE_OPTIONS)}
            </select>
            <button type="button" class="variant-more-btn" onclick="openPromptPreview(${scene.num})"
                 style="flex-shrink:0;height:30px;padding:0 10px;border-radius:6px;border:1.5px dashed var(--border);background:var(--bg);font-size:10px;font-weight:700;color:var(--muted);cursor:pointer;white-space:nowrap;">
                 ✨ Generate More
            </button>
        </div>
        <div id="prompt-preview-${scene.num}"></div>`;
}

// Pure client-side — nothing is "selected" in the DB sense until Continue.
function selectDraftCandidate(sceneNum, idx) {
    const scene = draftScenes.find(s => s.num === sceneNum);
    if (!scene || !scene.candidates[idx]) return;
    scene.selectedIndex = idx;
    const card = document.getElementById('scene-card-' + sceneNum);
    if (card) card.innerHTML = buildDraftCardHTML(scene);
}

// Builds the exact prompt text that WILL be sent to the image model —
// shown to the user before generating so they can edit it first, rather
// than firing it blind.
function buildPromptPreviewText(scene, shotType) {
    let p = scene.wan + ' Static establishing shot from the first frame, thumbnail-optimized, no motion blur.';
    if (shotType) p += ' Shot type for this variant: ' + shotType + '.';
    if (GLOBAL_AD_STYLE) p += ' Overall ad style/mood: ' + GLOBAL_AD_STYLE + '.';
    return p;
}

// ── Show the prompt before generating, so the user can edit it first ────
function openPromptPreview(sceneNum) {
    const scene = draftScenes.find(s => s.num === sceneNum);
    if (!scene) return;
    const shotSel  = document.getElementById('shot-type-' + sceneNum);
    const shotType = shotSel ? shotSel.value : '';
    const previewText = buildPromptPreviewText(scene, shotType);

    const holder = document.getElementById('prompt-preview-' + sceneNum);
    if (!holder) return;
    holder.innerHTML = `
        <div style="padding:10px;border-top:1px solid var(--border);background:var(--bg);">
            <div style="font-size:10px;font-weight:700;color:var(--text);margin-bottom:5px;">Prompt for the next image (edit if you like):</div>
            <textarea id="prompt-edit-${sceneNum}" rows="4" style="width:100%;font-size:11px;padding:6px;border-radius:6px;border:1px solid var(--border);background:var(--card);color:var(--text);resize:vertical;">${previewText}</textarea>
            <div style="display:flex;gap:6px;margin-top:6px;">
                <button type="button" class="btn btn-green" style="font-size:11px;padding:6px 14px;" onclick="confirmGenerateFromPreview(${sceneNum}, this)">✨ Generate this</button>
                <button type="button" class="btn btn-gray" style="font-size:11px;padding:6px 14px;" onclick="document.getElementById('prompt-preview-${sceneNum}').innerHTML=''">Cancel</button>
            </div>
        </div>`;
}

async function confirmGenerateFromPreview(sceneNum, btnEl) {
    const scene = draftScenes.find(s => s.num === sceneNum);
    if (!scene) return;
    const shotSel    = document.getElementById('shot-type-' + sceneNum);
    const shotType   = shotSel ? shotSel.value : '';
    const promptBox  = document.getElementById('prompt-edit-' + sceneNum);
    const finalPrompt = promptBox ? promptBox.value.trim() : '';
    if (!finalPrompt) { alert('Prompt is empty.'); return; }

    const orig = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<span class="spinner" style="width:10px;height:10px;border-width:2px;"></span> Generating…';

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'generate_draft_image');
        fd.append('scene_num', sceneNum);
        fd.append('custom_prompt', finalPrompt);
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            scene.candidates.push({ file: data.draft_file, folder: data.draft_folder, source: data.source, shotType: shotType });
            scene.selectedIndex = scene.candidates.length - 1;
            const card = document.getElementById('scene-card-' + sceneNum);
            if (card) card.innerHTML = buildDraftCardHTML(scene);
        } else {
            alert('Generation failed: ' + (data.error || 'Unknown error'));
            btnEl.disabled = false;
            btnEl.innerHTML = orig;
        }
    } catch (e) {
        alert('Network error generating image');
        btnEl.disabled = false;
        btnEl.innerHTML = orig;
    }
}


// ── Continue — shows the one-time static/dynamic choice for the whole video ──
function showContinueChoice() {
    const incomplete = draftScenes.filter(s => !s.candidates.length);
    if (incomplete.length) {
        alert('Scene(s) ' + incomplete.map(s => s.num).join(', ') + ' don\'t have an image yet — generate or retry them first.');
        return;
    }
    let box = document.getElementById('continueChoiceBox');
    if (!box) {
        box = document.createElement('div');
        box.id = 'continueChoiceBox';
        document.getElementById('storyboardGrid').parentElement.appendChild(box);
    }
    box.style.cssText = 'margin-top:16px;padding:16px;border:1.5px solid var(--border);border-radius:12px;background:var(--card);';
    box.innerHTML = `
        <div style="font-weight:700;font-size:13px;margin-bottom:10px;color:var(--text);">How should this video play?</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-gray" onclick="continueAndSave('static')">🖼️ Static Images (faster, cheaper)</button>
            <button type="button" class="btn btn-green" onclick="continueAndSave('dynamic')">🎬 AI-Animated (each scene becomes a motion clip)</button>
        </div>`;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Final commit — the ONLY point any of this gets written to the DB ────
async function continueAndSave(videoMode) {
    const box = document.getElementById('continueChoiceBox');
    if (box) box.innerHTML = '<div style="font-size:13px;font-weight:700;color:var(--text);">💾 Saving your video…</div>';

    let titleVal = '';
    if (typeof FULL_SCRIPT !== 'undefined') {
        const tm = FULL_SCRIPT.match(/##\s*1\.\s*TITLE[^\n]*\n([^\n]+)/i);
        if (tm) titleVal = tm[1].trim();
    }
    const businessVal = document.querySelector('[name=business]')?.value || '';

    const finalScenes = draftScenes.map(s => {
        const c = s.candidates[s.selectedIndex];
        return { num: s.num, wan: s.wan, caption: s.caption, draft_file: c.file, draft_folder: c.folder };
    });

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_cinematic_to_db');
        fd.append('scenes', JSON.stringify(finalScenes));
        fd.append('title', titleVal);
        fd.append('business', businessVal);
        fd.append('video_mode', videoMode);
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.success) {
            if (box) box.innerHTML = '<div style="color:#dc2626;font-size:13px;">❌ Save failed: ' + (data.message || 'Unknown error') + '</div>';
            return;
        }
        podcastId = data.podcast_id || 0;
        let html = `<div style="font-size:13px;font-weight:700;color:#166534;">✅ Saved! Podcast #${podcastId} created.</div>`;
        if (videoMode === 'dynamic') {
            html += `<button type="button" class="btn btn-green" style="margin-top:10px;" onclick="alert('AI video generation isn\\'t wired up yet — coming soon.')">🎬 Generate AI Video</button>`;
        }
        html += ` <a href="podcast_edit.php?id=${podcastId}" style="color:var(--orange-dk);font-weight:700;text-decoration:none;margin-left:8px;">Open in Editor →</a>`;
        if (box) box.innerHTML = html;
    } catch (e) {
        if (box) box.innerHTML = '<div style="color:#dc2626;font-size:13px;">❌ Network error during save: ' + e.message + '</div>';
    }
}

// ── Reset Everything — full wipe of the current podcast (DB rows + files)
// and the session state tracking it. Confirms first since this is
// destructive and irreversible.
async function resetPodcast() {
    if (!confirm('This permanently deletes this video\'s scenes, images, and history. This cannot be undone. Continue?')) {
        return;
    }
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'reset_current_podcast');
        if (typeof podcastId !== 'undefined' && podcastId) fd.append('podcast_id', podcastId);
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            alert('Reset failed: ' + (data.error || 'Unknown error'));
            return;
        }
        // Reload to a clean URL so the page renders the blank initial form —
        // session has been cleared server-side, so this won't bring anything back.
        window.location.href = window.location.pathname;
    } catch (e) {
        alert('Network error during reset — please try again.');
    }
}

async function generateAllScenes() {
    console.log('[Gen] FULL_SCRIPT type:', typeof FULL_SCRIPT, typeof FULL_SCRIPT !== 'undefined' ? String(FULL_SCRIPT).substring(0,80) : 'UNDEFINED');
    if (typeof FULL_SCRIPT === 'undefined' || !FULL_SCRIPT) {
        alert('No script found - please generate a script first (Step 3).');
        return;
    }
    const scenes = parseScenes(FULL_SCRIPT);
    console.log('[Gen] parsed', scenes.length, 'scenes:', scenes.map(s=>s.num));
    if (!scenes.length) {
        alert('No scenes found. Script preview:\n\n' + String(FULL_SCRIPT).substring(0,400));
        return;
    }

    const btn = document.getElementById('btnGenScenes');
    btnLoading(btn, 'Processing…');

    // ── Step 1: Deduct credits ─────────────────────────────────
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'deduct_cinematic_credits');
        fd.append('gen_mode', document.querySelector('input[name="gen_mode"]:checked')?.value || 'modal.com');
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

    // ── Step 2: Show storyboard + placeholders immediately ─────
    document.getElementById('storyboardSection').style.display = 'block';
    document.getElementById('sceneProgress').style.display = 'block';
    const grid = document.getElementById('storyboardGrid');
    grid.innerHTML = '';
    const existingBox = document.getElementById('continueChoiceBox');
    if (existingBox) existingBox.remove();

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
    document.getElementById('sceneProgressBar').style.width = '5%';
    document.getElementById('sceneProgressLabel').textContent = `Generating ${scenes.length} scene image(s)…`;

    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    await new Promise(r => setTimeout(r, 50));

    // ── Step 3: Reset draft state — nothing is saved to the DB yet ──
    draftScenes = scenes.map(sc => ({ num: sc.num, wan: sc.wan, caption: sc.caption, title: sc.title, candidates: [], selectedIndex: 0 }));

    let completed    = 0;
    let successCount = 0;

    function setSceneStatus(num, text) {
        const el = document.getElementById('scene-status-' + num);
        if (el) el.textContent = text;
    }

    function updateProgress() {
        const pct = 5 + Math.round((completed / scenes.length) * 90);
        document.getElementById('sceneProgressBar').style.width = pct + '%';
        document.getElementById('sceneProgressLabel').textContent =
            `Generating… ${completed}/${scenes.length}  ✅ ${successCount}  ❌ ${completed - successCount}`;
    }

    async function genScene(sc) {
        setSceneStatus(sc.num, 'Generating…');
        const fd = new FormData();
        fd.append('ajax_action', 'generate_draft_image');
        fd.append('scene_num', sc.num);
        fd.append('video_prompt', sc.wan);
        try {
            const resp = await fetch('', { method:'POST', body:fd });
            const data = await resp.json();
            const card = document.getElementById('scene-card-' + sc.num);
            const draftScene = draftScenes.find(s => s.num === sc.num);
            if (data.success && draftScene) {
                draftScene.candidates.push({ file: data.draft_file, folder: data.draft_folder, source: data.source });
                draftScene.selectedIndex = 0;
                if (card) card.innerHTML = buildDraftCardHTML(draftScene);
                successCount++;
            } else if (card) {
                const stage  = data?.fail_stage || 'unknown';
                const reason = data?.error || 'Unknown error';
                card.innerHTML = `
                    <div style="aspect-ratio:9/16;display:flex;flex-direction:column;align-items:center;justify-content:center;
                        background:#fef2f2;gap:6px;padding:14px;text-align:center;">
                        <span style="font-size:26px;">❌</span>
                        <div style="font-size:11px;font-weight:700;color:#dc2626;">Scene ${sc.num} failed</div>
                        <div style="font-size:10px;color:#7f1d1d;background:#fee2e2;padding:4px 8px;border-radius:6px;width:100%;word-break:break-word;">
                            <strong>Stage:</strong> ${stage}<br>${String(reason).substring(0,90)}
                        </div>
                        <button onclick="retryDraftScene(${sc.num})"
                            style="padding:6px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;margin-top:2px;">
                            🔁 Retry
                        </button>
                    </div>`;
            }
            completed++;
            updateProgress();
            return data.success;
        } catch(e) {
            completed++;
            updateProgress();
            return false;
        }
    }

    // Small stagger to avoid hammering the same endpoint in the same instant.
    const STAGGER_MS = 500;
    await Promise.all(
        scenes.map((sc, idx) =>
            new Promise(r => setTimeout(r, idx * STAGGER_MS)).then(() => genScene(sc))
        )
    );

    document.getElementById('sceneProgressBar').style.width = '100%';
    const failCount = scenes.length - successCount;
    document.getElementById('sceneProgressLabel').innerHTML =
        `✅ ${successCount}/${scenes.length} scenes generated!` +
        (failCount > 0 ? ` — <strong style="color:#dc2626;">${failCount} failed</strong> (click 🔁 on failed cards)` : '');

    btn.disabled = false;
    btn.innerHTML = '🔁 Regenerate All';

    // Nothing is saved yet — show the Continue button so the user can pick
    // their favorites, generate more options per scene, and only then commit.
    if (successCount === scenes.length) {
        let box = document.getElementById('continueChoiceBox');
        if (!box) {
            box = document.createElement('div');
            box.id = 'continueChoiceBox';
            grid.parentElement.appendChild(box);
        }
        box.style.cssText = 'margin-top:16px;text-align:center;';
        box.innerHTML = `<button type="button" class="btn btn-green" onclick="showContinueChoice()" style="font-size:14px;padding:12px 28px;">✅ Continue with these images</button>`;
    }
}

async function retryDraftScene(sceneNum) {
    const draftScene = draftScenes.find(s => s.num === sceneNum);
    if (!draftScene) return;
    const card = document.getElementById('scene-card-' + sceneNum);
    if (card) card.innerHTML = `<div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f8fafc,#e2e8f0);display:flex;align-items:center;justify-content:center;"><span class="spinner"></span></div>`;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'generate_draft_image');
        fd.append('scene_num', sceneNum);
        fd.append('video_prompt', draftScene.wan);
        const resp = await fetch('', { method:'POST', body:fd });
        const data = await resp.json();
        if (data.success) {
            draftScene.candidates.push({ file: data.draft_file, folder: data.draft_folder, source: data.source });
            draftScene.selectedIndex = draftScene.candidates.length - 1;
            if (card) card.innerHTML = buildDraftCardHTML(draftScene);
        } else if (card) {
            card.innerHTML = `<div style="padding:14px;color:#dc2626;font-size:11px;">❌ Retry failed: ${(data.error||'Unknown error').substring(0,80)} <button onclick="retryDraftScene(${sceneNum})" style="display:block;margin:8px auto 0;padding:6px 16px;background:#0f2a44;color:#fff;border:none;border-radius:7px;font-size:11px;cursor:pointer;">🔁 Retry</button></div>`;
        }
    } catch (e) {
        if (card) card.innerHTML = `<div style="padding:14px;color:#dc2626;font-size:11px;">❌ Network error</div>`;
    }
}

// -- Queue status polling functions ----------------------------------------
let _pollTimer      = null;
let _pollPodcastId  = 0;
let _pollingStarted = false;
let _storyIdMap     = {};  // module-level copy of storyIdMap for polling access
let podcastId       = 0;   // current podcast — set by generateAllScenes(), read by generateAllVideos()
let storyIdMap      = {};  // { scene_num: story_id } — module-level for the same reason
const POLL_INTERVAL_MS = 30000; // poll every 30 seconds

function fmtMins(mins) {
    if (mins <= 0)  return 'Almost done!';
    if (mins === 1) return '~1 min';
    if (mins < 60)  return '~' + mins + ' min';
    const h = Math.floor(mins / 60), m = mins % 60;
    return m > 0 ? '~' + h + 'h ' + m + 'm' : '~' + h + 'h';
}

async function fetchQueueStatus(podcastId, isFirst) {
    isFirst = isFirst || false;
    console.log('[Queue] fetchQueueStatus podcast=' + podcastId + ' time=' + new Date().toLocaleTimeString());
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_queue_status');
        fd.append('podcast_id',  podcastId);
        const resp = await fetch('', { method: 'POST', body: fd });
        const d    = await resp.json();
        console.log('[Queue] response:', JSON.stringify(d));
        if (!d.success) { console.warn('[Queue] failed', d); return; }

        // -- Image queue UI --
        const imgDone    = d.img_done    || 0;
        const imgTotal   = d.img_total   || 0;
        const imgPending = d.img_pending || 0;
        const imgAhead   = d.img_ahead   || 0;
        const imgEta     = d.img_eta_mins || 0;
        const imgPct     = imgTotal > 0 ? Math.round((imgDone / imgTotal) * 100) : 0;

        document.getElementById('imgQueueBar').style.width      = imgPct + '%';
        document.getElementById('imgQueueProgress').textContent = imgDone + ' / ' + imgTotal + ' done';
        document.getElementById('imgEtaDisplay').textContent    = fmtMins(imgEta);

        // Update storyboard cards on every poll (shows images as they arrive one by one)
        updateStoryboardWithImages(d.img_scenes || []);

        if (d.all_images_done) {
            document.getElementById('imgQueueDetail').textContent = 'All ' + imgTotal + ' images generated ✅';
            document.getElementById('imgEtaDisplay').textContent  = 'Done!';
            document.getElementById('imgQueueBar').style.width    = '100%';
            document.getElementById('imgPollStatus').textContent  = 'Complete';
            stopQueuePolling();
            // Show video cost card and calculate how many credits are needed
            revealVideoCostCard(d.img_scenes || [], imgTotal);
        } else {
            const processing = (d.img_scenes || []).filter(function(s){ return s.videogen_flag == 2; }).length;
            var parts = [];
            parts.push(imgDone + ' of ' + imgTotal + ' images done');
            if (processing > 0) parts.push(processing + ' generating now');
            if (imgAhead > 0)   parts.push(imgAhead + ' other image' + (imgAhead !== 1 ? 's' : '') + ' ahead in queue');
            if (imgPending > 0) parts.push(imgPending + ' of yours waiting');
            document.getElementById('imgQueueDetail').textContent = parts.join(' • ');
            document.getElementById('imgPollStatus').textContent  =
                isFirst ? 'Polling every 30s…' : 'Last checked: ' + new Date().toLocaleTimeString();
        }

        // -- Video queue UI -- only shown once this podcast's images are done;
        // a "Generate Videos" button (coming in a later step) is what will
        // actually kick off video generation from here.
        document.getElementById('vidQueueCard').style.display = d.all_images_done ? 'block' : 'none';

        const vidDone    = d.vid_done    || 0;
        const vidTotal   = d.vid_total   || 0;
        const vidPending = d.vid_pending || 0;
        const vidAhead   = d.vid_ahead   || 0;
        const vidEta     = d.vid_eta_mins || 0;
        const vidPct     = vidTotal > 0 ? Math.round((vidDone / vidTotal) * 100) : 0;

        document.getElementById('vidQueueBar').style.width      = vidPct + '%';
        document.getElementById('vidQueueProgress').textContent = vidDone + ' / ' + vidTotal + ' done';
        document.getElementById('vidEtaDisplay').textContent    = vidTotal > 0 ? fmtMins(vidEta) : '--';

        if (vidDone >= vidTotal && vidTotal > 0) {
            document.getElementById('vidQueueDetail').textContent = 'All ' + vidTotal + ' videos generated ✅';
        } else if (vidTotal > 0) {
            var vidParts = [];
            vidParts.push(vidDone + ' of ' + vidTotal + ' videos done');
            if (vidAhead > 0)   vidParts.push(vidAhead + ' other video' + (vidAhead !== 1 ? 's' : '') + ' ahead in queue');
            if (vidPending > 0) vidParts.push(vidPending + ' of yours waiting (~3 min each)');
            document.getElementById('vidQueueDetail').textContent = vidParts.join(' • ');
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
        console.log('[Queue] Poll tick podcast=' + _pollPodcastId);
        fetchQueueStatus(_pollPodcastId, false);
    }, POLL_INTERVAL_MS);
}

function stopQueuePolling() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
}

// When images are done, update storyboard cards.
// Cards use scene NUMBER (1,2,3); DB scene_id is the story_id (large int).
// Invert storyIdMap { sceneNum -> storyId } to find the right card.
function updateStoryboardWithImages(imgScenes) {
    // Build reverse lookup: storyId -> sceneNum
    var storyToScene = {};
    var mapToUse = (Object.keys(_storyIdMap).length > 0) ? _storyIdMap : storyIdMap;
    Object.keys(mapToUse).forEach(function(sn) {
        storyToScene[String(mapToUse[sn])] = sn;
    });
    console.log('[Queue] updateStoryboard storyToScene:', storyToScene, 'mapSize:', Object.keys(mapToUse).length);

    imgScenes.forEach(function(sc) {
        if (sc.videogen_flag != 3 || !sc.image_name) return;
        var sceneNum = storyToScene[String(sc.scene_id)];
        if (!sceneNum) {
            console.warn('[Queue] No sceneNum for scene_id=' + sc.scene_id + ' map:', storyToScene);
            return;
        }
        var card = document.getElementById('scene-card-' + sceneNum);
        if (!card) { console.warn('[Queue] Card not found: scene-card-' + sceneNum); return; }
        if (card.querySelector('img') || card.querySelector('video')) return; // already showing
        var folder  = sc.image_folder || 'podcast_images';
        var imgPath = folder + '/' + sc.image_name + '?t=' + Date.now();
        var isVideo = /\.(mp4|mov|webm|m4v)$/i.test(sc.image_name);
        console.log('[Queue] Rendering ' + (isVideo ? 'video' : 'image') + ' scene=' + sceneNum + ' src=' + imgPath);
        var mediaTag = isVideo
            ? '<video src="' + imgPath + '" style="width:100%;height:100%;object-fit:cover;" muted playsinline loop autoplay></video>'
            : '<img src="' + imgPath + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy">';
        card.innerHTML =
            '<div style="position:relative;aspect-ratio:9/16;">'
          + mediaTag
          + '<div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(5,150,105,.88);color:#fff;">⚡ Generated</div>'
          + '</div>'
          + '<div style="padding:8px 10px;"><div style="font-size:11px;font-weight:700;color:#0f2a44;">Scene ' + sceneNum + '</div>'
          + '<div style="font-size:9px;color:#94a3b8;margin-top:4px;font-family:monospace;word-break:break-all;" title="' + folder + '/' + sc.image_name + '">' + sc.image_name + '</div></div>';
    });
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
    const wan     = safeDec(encodedWan);
    const caption = safeDec(encodedCaption);
    const title   = safeDec(encodedTitle);
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
                </div>` + genVideoButtonHTML(sceneNum, podcastId, storyId, wan);
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

// ── Video player modal ──────────────────────────────────────────
function openVideoModal(url) {
    const modal = document.getElementById('videoPlayerModal');
    const vid   = document.getElementById('videoPlayerEl');
    vid.src = url;
    modal.style.display = 'flex';
    vid.play().catch(()=>{});
}
function closeVideoModal() {
    const modal = document.getElementById('videoPlayerModal');
    const vid   = document.getElementById('videoPlayerEl');
    vid.pause();
    vid.removeAttribute('src');
    vid.load();
    modal.style.display = 'none';
}

// ── "Go to video editor" bar — shown once we have a podcast_id ──
function showVideoEditorBar(podcastId) {
    if (!podcastId) return;
    const bar  = document.getElementById('videoEditorBar');
    const link = document.getElementById('videoEditorLink');
    if (!bar || !link) return;
    link.href = 'videomaker.php?podcast_id=' + podcastId;
    bar.style.display = 'block';
}

// ── Video cost card — shown immediately after images are queued ─────────────
// Always visible after generation starts. Pricing:
//   20 credits per scene that needs a video generated
//   Minimum 20 credits even if all videos already exist in library
// When called with empty imgScenes (initial reveal), shows full cost.
// When called again from polling with real scene data, updates the count
// if library matches reduce the number that need generation.
function revealVideoCostCard(imgScenes, totalScenes) {
    const card = document.getElementById('videoCostCard');
    if (!card) return;

    const total = totalScenes || (imgScenes || []).length || 6;

    // Count scenes that already have a video from the library — those are free
    const videoMatches   = (imgScenes || []).filter(function(s) {
        return s.media_type === 'video' && s.from_library;
    }).length;
    const needGeneration = Math.max(0, total - videoMatches);
    const creditCost     = Math.max(20, needGeneration * 20);

    const costBadgeBtn = document.getElementById('videoCostBadgeBtn');
    if (costBadgeBtn) costBadgeBtn.textContent = creditCost + ' cr';
    card.dataset.creditCost = creditCost;
    card.style.display      = 'block';
}

// ── Static image video — 10 credits, go straight to editor ─────────────────
function goToEditorStaticImages() {
    if (!podcastId) { alert('No podcast found — please generate scenes first.'); return; }
    const btn = document.getElementById('btnStaticImages');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; }
    window.location.href = 'videomaker.php?podcast_id=' + podcastId;
}

// ── Trigger video generation — queue every scene + kick the fal.ai worker ──
// POSTs to start_ai_videos, which queues all AI-generated scenes and fires
// cron_video_gen.php in the background. From there it's webhook-driven and
// browser-independent — fal.ai returns each clip to fal_webhook.php and the
// cron saves it to user_media/.../podcast_videos + updates hdb_podcast_stories.
async function generateAllVideos() {
    const btn   = document.getElementById('btnGenerateVideos');
    const label = document.getElementById('aiVideoBtnLabel');
    const desc  = document.getElementById('aiVideoBtnDesc');

    if (!podcastId) { alert('No podcast found — please generate the scene images first.'); return; }

    if (btn)   { btn.disabled = true; btn.style.opacity = '0.7'; btn.style.cursor = 'default'; }
    if (label) label.textContent = 'Starting…';
    if (desc)  desc.textContent  = 'Sending all scenes to fal.ai…';

    const resetBtn = (msg) => {
        if (label) label.textContent = 'AI Video Scenes';
        if (desc && msg) desc.textContent = msg;
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
    };

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'start_ai_videos');
        fd.append('podcast_id',  podcastId);
        const resp = await fetch('', { method: 'POST', body: fd });
        const data = await resp.json();

        if (!data.success) { resetBtn('❌ ' + (data.error || 'Could not start video generation')); return; }
        if ((data.in_progress || 0) === 0) { resetBtn(data.message || 'No eligible scenes for video generation.'); return; }

        if (label) label.textContent = 'Videos Generating ⏳';
        if (desc)  desc.textContent  =
            data.in_progress + ' scene videos are being generated in the background via fal.ai. ' +
            'You can safely close this tab — they keep generating and will appear in the editor when ready.';
    } catch (e) {
        resetBtn('❌ Network error: ' + e.message);
        return;
    }

    showVideoEditorBar(podcastId);
    const statusMsg = document.getElementById('videoGenStatusMsg');
    if (statusMsg) statusMsg.style.display = 'block';

    // Watch for finished clips and swap each scene card to its video.
    startVideoCompletionPolling(podcastId);
}

// ── Poll for finished videos and swap scene cards in place ──────────────────
// The cron's FINISH phase rewrites hdb_podcast_stories.image_file to the .mp4
// once a clip lands, so get_queue_status reports a video filename per scene.
// We swap that scene's card from its still image to an inline <video>.
let _videoCompletionTimer = null;
function startVideoCompletionPolling(podcastId) {
    if (_videoCompletionTimer) { clearInterval(_videoCompletionTimer); _videoCompletionTimer = null; }

    const tick = async () => {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'get_queue_status');
            fd.append('podcast_id',  podcastId);
            const resp = await fetch('', { method: 'POST', body: fd });
            const d    = await resp.json();
            if (!d.success) return;

            const scenes   = d.img_scenes || [];
            const total    = scenes.length;
            let   videoCnt = 0;

            // Reverse map: storyId -> sceneNum (cards are keyed by scene number).
            const mapToUse = (Object.keys(_storyIdMap).length > 0) ? _storyIdMap : storyIdMap;
            const storyToScene = {};
            Object.keys(mapToUse).forEach(sn => { storyToScene[String(mapToUse[sn])] = sn; });

            scenes.forEach(sc => {
                const name = sc.image_name || '';
                if (!/\.(mp4|mov|webm|m4v)$/i.test(name)) return; // not a video yet
                videoCnt++;
                const sceneNum = storyToScene[String(sc.scene_id)];
                if (!sceneNum) return;
                const card = document.getElementById('scene-card-' + sceneNum);
                if (!card || card.dataset.videoReady === '1') return;

                const folder = sc.image_folder || 'podcast_videos';
                const src    = folder + '/' + name + '?t=' + Date.now();
                const html =
                    '<div style="position:relative;aspect-ratio:9/16;cursor:pointer;" onclick="openVideoModal(\'' + src + '\')">'
                  + '<video src="' + src + '" style="width:100%;height:100%;object-fit:cover;" muted playsinline loop autoplay preload="metadata"></video>'
                  + '<div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(15,42,68,.88);color:#fff;">🎬 Video ready</div>'
                  + '</div>';
                const mediaWrap = card.querySelector('div[style*="aspect-ratio:9/16"]');
                if (mediaWrap) mediaWrap.outerHTML = html;
                else card.insertAdjacentHTML('afterbegin', html);
                card.dataset.videoReady = '1';
                const va = document.getElementById('video-action-' + sceneNum);
                if (va) va.remove(); // drop any per-card "Generate video" button
            });

            const desc  = document.getElementById('aiVideoBtnDesc');
            const label = document.getElementById('aiVideoBtnLabel');
            if (total > 0 && videoCnt >= total) {
                if (label) label.textContent = 'Videos Ready ✅';
                if (desc)  desc.textContent  = '✅ All ' + total + ' scene videos generated!';
                clearInterval(_videoCompletionTimer); _videoCompletionTimer = null;
            } else if (total > 0 && desc) {
                desc.textContent = videoCnt + ' of ' + total + ' scene videos ready — the rest are generating in the background…';
            }
        } catch (e) { /* transient — keep polling */ }
    };

    tick();
    _videoCompletionTimer = setInterval(tick, 20000); // every 20s
}

// ── Per-card "Generate video" button + polling ───────────────────
const _videoPollTimers = {}; // { sceneNum: intervalId }

function videoThumbHTML(sceneNum, videoUrl) {
    return `
        <div style="position:relative;aspect-ratio:9/16;cursor:pointer;" onclick="openVideoModal('${videoUrl}')">
            <video src="${videoUrl}" style="width:100%;height:100%;object-fit:cover;" muted playsinline preload="metadata"></video>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
                <div style="width:46px;height:46px;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;">
                    <div style="width:0;height:0;border-style:solid;border-width:9px 0 9px 14px;border-color:transparent transparent transparent #fff;margin-left:3px;"></div>
                </div>
            </div>
            <div style="position:absolute;top:5px;left:5px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(15,42,68,.88);color:#fff;">🎬 Video ready</div>
        </div>`;
}

function genVideoButtonHTML(sceneNum, podcastId, storyId, wanPrompt) {
    const encodedWan = safeEnc(wanPrompt);
    return `
        <div id="video-action-${sceneNum}" style="padding:0 10px 10px;">
            <button type="button" onclick="generateSceneVideo(${sceneNum},${podcastId},${storyId},'${encodedWan}')"
                style="width:100%;padding:7px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;">
                🎬 Generate video
            </button>
            <div id="video-status-${sceneNum}" style="font-size:9px;color:#94a3b8;text-align:center;margin-top:4px;"></div>
        </div>`;
}

async function generateSceneVideo(sceneNum, podcastId, storyId, encodedWan) {
    const wan        = safeDec(encodedWan);
    const card        = document.getElementById('scene-card-' + sceneNum);
    const actionBox    = document.getElementById('video-action-' + sceneNum);
    const statusEl    = document.getElementById('video-status-' + sceneNum);
    const btn         = actionBox ? actionBox.querySelector('button') : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Starting…'; }

    async function poll() {
        const fd = new FormData();
        fd.append('ajax_action', 'generate_scene_video');
        fd.append('podcast_id',  podcastId);
        fd.append('story_id',    storyId);
        fd.append('scene_num',   sceneNum);
        fd.append('wan_prompt',  wan);
        fd.append('gen_mode',    document.querySelector('input[name="gen_mode"]:checked')?.value || 'modal.com');
        try {
            const resp = await fetch('', { method:'POST', body:fd });
            const data = await resp.json();
            if (data.success && data.video_ready) {
                if (_videoPollTimers[sceneNum]) { clearInterval(_videoPollTimers[sceneNum]); delete _videoPollTimers[sceneNum]; }
                const mediaWrap = card ? card.querySelector('div[style*="aspect-ratio:9/16"]') : null;
                if (mediaWrap) mediaWrap.outerHTML = videoThumbHTML(sceneNum, data.video_url);
                if (actionBox) actionBox.remove();
                return true;
            }
            if (data.success) {
                if (statusEl) statusEl.textContent = data.message || 'Generating…';
                if (btn) btn.innerHTML = '⏳ ' + (data.status === 'processing' ? 'Processing…' : 'Queued…');
            } else {
                if (statusEl) statusEl.textContent = data.error || 'Could not start video';
                if (btn) { btn.disabled = false; btn.innerHTML = '🔁 Retry video'; }
                if (_videoPollTimers[sceneNum]) { clearInterval(_videoPollTimers[sceneNum]); delete _videoPollTimers[sceneNum]; }
            }
        } catch(e) {
            if (statusEl) statusEl.textContent = 'Network error — will retry…';
        }
        return false;
    }

    const ready = await poll();
    if (!ready && !_videoPollTimers[sceneNum]) {
        _videoPollTimers[sceneNum] = setInterval(poll, 15000); // poll every 15s
    }
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
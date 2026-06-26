<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'config.php';

// Support multiple common API key variable names
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

// Add debug check - log if key missing
if (!$apiKey) {
    error_log("[movie_gen] WARNING: No API key found in config.php");
}

$apiUrl = "https://api.openai.com/v1/chat/completions";
$response = "";
$step = $_POST['step'] ?? $_GET['step'] ?? "0";

// === LOGGING FUNCTION ==========================================
function logGeneration($message, $type = 'INFO') {
    $logFile = __DIR__ . '/image_generation.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    error_log("[IMAGE_GEN] $message");
}

// === MODAL/FLUX CONFIGURATION =================================
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

function generateWithFlux($prompt, $maxRetries = 2) {
    global $MODAL_URL;
    
    logGeneration("FLUX: Starting generation with prompt length: " . strlen($prompt), "FLUX");
    
    $payload = json_encode([
        'prompt' => $prompt,
        'style' => 'cinematic',
        'width' => 768,
        'height' => 1344
    ]);
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        logGeneration("FLUX: Attempt $attempt of $maxRetries", "FLUX");
        
        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['image'])) {
                logGeneration("FLUX: SUCCESS on attempt $attempt (HTTP $httpCode)", "FLUX_SUCCESS");
                return ['success' => true, 'image' => $data['image'], 'source' => 'FLUX/Modal'];
            }
        }
        
        if ($attempt < $maxRetries) {
            logGeneration("FLUX: Waiting 3 seconds before retry...", "FLUX");
            sleep(3);
        }
    }
    
    logGeneration("FLUX: All $maxRetries attempts failed", "FLUX_ERROR");
    return ['success' => false, 'source' => 'FLUX/Modal'];
}

// === OPENAI gpt-image-1 GENERATION (FALLBACK) =================
function generateWithOpenAI($prompt, $resolution = "1024x1536") {
    global $apiKey;
    
    logGeneration("OpenAI: Starting fallback generation", "OPENAI");
    
    if (empty($apiKey)) {
        logGeneration("OpenAI: No API key available", "OPENAI_ERROR");
        return ['success' => false, 'error' => 'No API key', 'source' => 'OpenAI'];
    }
    
    $data = [
        "model"          => "gpt-image-1",
        "prompt"         => $prompt,
        "size"           => $resolution,
        "quality"        => "medium",
        "output_format"  => "png",
        "n"              => 1
    ];
    
    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        logGeneration("OpenAI: cURL error: $curlError", "OPENAI_ERROR");
        return ['success' => false, 'error' => $curlError, 'source' => 'OpenAI'];
    }
    
    if ($httpCode !== 200) {
        $result = json_decode($response, true);
        $errorMsg = isset($result['error']['message']) ? $result['error']['message'] : "HTTP $httpCode";
        logGeneration("OpenAI: HTTP error $httpCode - $errorMsg", "OPENAI_ERROR");
        return ['success' => false, 'error' => $errorMsg, 'source' => 'OpenAI'];
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['data'][0]['b64_json'])) {
        logGeneration("OpenAI: No b64_json in response", "OPENAI_ERROR");
        return ['success' => false, 'error' => 'No image data', 'source' => 'OpenAI'];
    }
    
    logGeneration("OpenAI: SUCCESS - Image generated", "OPENAI_SUCCESS");
    return [
        'success' => true,
        'image' => $result['data'][0]['b64_json'],
        'source' => 'OpenAI gpt-image-1'
    ];
}

// === PRIMARY GENERATION FUNCTION ===============================
function generateImageWithFallback($prompt, $maxFluxRetries = 2) {
    logGeneration("=== Starting image generation (FLUX primary, OpenAI fallback) ===", "MAIN");
    
    $result = generateWithFlux($prompt, $maxFluxRetries);
    
    if ($result['success']) {
        logGeneration("FLUX succeeded! Using FLUX image.", "MAIN_SUCCESS");
        return $result;
    }
    
    logGeneration("FLUX failed. Falling back to OpenAI...", "MAIN_FALLBACK");
    $openaiResult = generateWithOpenAI($prompt);
    
    if ($openaiResult['success']) {
        logGeneration("OpenAI fallback succeeded!", "MAIN_SUCCESS");
        return $openaiResult;
    }
    
    logGeneration("BOTH FLUX and OpenAI failed.", "MAIN_ERROR");
    return ['success' => false, 'error' => 'Both providers failed', 'source' => 'none'];
}

function warmupModal() {
    global $MODAL_URL;
    logGeneration("Warmup: Sending warmup request", "WARMUP");
    
    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
        CURLOPT_NOBODY => true
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logGeneration("Warmup: HTTP $httpCode", "WARMUP");
    return $httpCode > 0;
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        45);
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

// ── AJAX: Warmup Modal endpoint ────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'warmup_modal') {
    header('Content-Type: application/json');
    logGeneration("Manual warmup triggered", "WARMUP");

    $test_payload = json_encode([
        'prompt' => 'a simple white square, minimal, test image',
        'style'  => 'cinematic',
        'width'  => 128,
        'height' => 128,
    ]);

    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $test_payload,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);

    $is_warm = ($curlErr === 0 && $httpCode === 200 && !empty(json_decode($resp, true)['image']));
    logGeneration("Warmup result: HTTP=$httpCode, is_warm=" . ($is_warm ? 'YES' : 'NO'), "WARMUP");

    echo json_encode([
        'success' => $is_warm,
        'message' => $is_warm ? 'Modal server is warm and ready' : 'Modal server is cold — cold start triggered',
        'http'    => $httpCode,
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

// ── AJAX: Generate scene image using FLUX with OpenAI fallback ──
if (isset($_POST['ajax_action']) && ($_POST['ajax_action'] === 'generate_scene_image' || $_POST['ajax_action'] === 'generate_scene_image_modal')) {
    set_time_limit(180);
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $wan_prompt  = trim($_POST['wan_prompt']  ?? '');
    $caption     = trim($_POST['caption']     ?? '');
    $scene_num   = (int)($_POST['scene_num']  ?? 0);
    $save_folder = 'podcast_images/scenes';

    if (!$wan_prompt) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No prompt']);
        exit;
    }

    logGeneration("=== NEW SCENE GENERATION: Scene $scene_num ===", "REQUEST");

    // Enhance the prompt
    $business_context = $_SESSION['business'] ?? '';
    
    $enhance_system = "You are a world-class cinematic stills director. Convert the scene description into a detailed photorealistic image prompt.
CRITICAL: Images MUST be BRIGHT with COOL-NEUTRAL lighting (5500K-6500K). NO warm tones, NO yellow cast.
Include: bright natural daylight, cool-neutral lighting, vivid colors, cinematic realism, ultra photorealistic, 8K, shallow depth of field, bokeh.
OUTPUT: One flowing paragraph, minimum 150 words.";

    $character_context = '';
    if (isset($_SESSION['selected'])) {
        $sel = $_SESSION['selected'];
        $character_context = "CHARACTER: " . ($sel['character'] ?? '') . "\nSETTING: " . ($sel['setting'] ?? '');
    }
    $story_context = isset($_SESSION['story']) ? "STORY CONTEXT: " . substr($_SESSION['story'], 0, 300) : '';
    $full_user_input = $story_context . "\n" . $character_context . "\nSCENE: " . $wan_prompt;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $enhance_system],
                ['role' => 'user', 'content' => $full_user_input],
            ],
            'temperature' => 0.85,
            'max_tokens' => 800,
        ]),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $enhanced_prompt = json_decode($res, true)['choices'][0]['message']['content'] ?? $wan_prompt;
    
    logGeneration("Enhanced prompt length: " . strlen($enhanced_prompt), "ENHANCE");

    // Generate image with fallback
    $result = generateImageWithFallback($enhanced_prompt);
    
    if (!$result['success']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Image generation failed: ' . ($result['error'] ?? 'Unknown')]);
        exit;
    }

    // Save image
    if (!is_dir($save_folder)) mkdir($save_folder, 0755, true);
    $filename = $save_folder . '/scene_' . time() . '_' . $scene_num . '.png';
    file_put_contents($filename, base64_decode($result['image']));

    logGeneration("Image saved: $filename (Source: {$result['source']})", "SAVE");

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'file' => $filename,
        'scene' => $scene_num,
        'caption' => $caption,
        'enhanced' => $enhanced_prompt,
        'source' => $result['source']
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
[One powerful cinematic paragraph — emotional journey from problem to resolution.]

URDU TRANSLATION:
[Translate the CORE STORY into Urdu script.]";
    
    $additional = $_SESSION['additional_info'] ?? '';
    if ($additional) $paramBlock .= "\nAdditional Instructions: $additional";
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $paramBlock);
    $_SESSION['story'] = $response;
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
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $input);
    $_SESSION['script'] = $response;
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
<title>Cinematic AI Script Generator</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4ff; color: #1e293b; padding: 24px 16px; }
.container { max-width: 900px; margin: 0 auto; }
.hero { background: linear-gradient(135deg, #1d4ed8 0%, #f97316 100%); border-radius: 18px; padding: 36px 28px; margin-bottom: 24px; text-align: center; }
.hero h1 { font-size: 26px; font-weight: 800; color: #fff; }
.hero p { color: rgba(255,255,255,.82); font-size: 13px; margin-top: 8px; }
.card { background: #fff; border: 1.5px solid #dde4f5; border-radius: 14px; padding: 22px; margin-bottom: 18px; }
.card-title { font-size: 11px; font-weight: 700; color: #f97316; text-transform: uppercase; margin-bottom: 16px; }
.business-row { display: flex; gap: 10px; }
.business-input { flex: 1; background: #f8faff; border: 2px solid #dde4f5; border-radius: 10px; padding: 12px 16px; font-size: 14px; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 11px 22px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; }
.btn-blue { background: linear-gradient(135deg,#1d4ed8,#3b82f6); color: #fff; }
.btn-green { background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff; }
.btn-orange { background: linear-gradient(135deg,#ea580c,#f97316); color: #fff; }
.btn-gray { background: #f0f4ff; color: #64748b; border: 1.5px solid #dde4f5; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
.params-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:620px) { .params-grid { grid-template-columns: 1fr; } }
.param-card { background: #f8faff; border: 1.5px solid #dde4f5; border-radius: 12px; padding: 14px; }
.param-label { font-size: 12px; font-weight: 700; color: #1d4ed8; text-transform: uppercase; }
.param-desc { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.selected-val { background: linear-gradient(135deg,#1d4ed8,#3b82f6); color: #fff; border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600; margin-bottom: 10px; }
.options-row { display: flex; gap: 6px; flex-wrap: wrap; }
.opt-chip { background: #fff; border: 1.5px solid #dde4f5; padding: 5px 11px; border-radius: 99px; font-size: 11px; cursor: pointer; }
.opt-chip:hover { border-color: #f97316; color: #ea580c; }
.more-opts-btn { background: #f0f4ff; border: 1.5px solid #dde4f5; padding: 2px 9px; border-radius: 99px; font-size: 10px; cursor: pointer; }
.steps { display: flex; align-items: center; margin-bottom: 22px; }
.step-item { display: flex; align-items: center; gap: 7px; font-size: 12px; }
.step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
.step-dot.active { background: linear-gradient(135deg,#f97316,#fb923c); color: #fff; }
.step-dot.done { background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff; }
.step-dot.idle { background: #f0f4ff; color: #94a3b8; border: 1.5px solid #dde4f5; }
.step-line { flex: 1; height: 2px; background: #dde4f5; margin: 0 8px; }
.output-box { background: #f8faff; border: 1.5px solid #dde4f5; border-radius: 10px; padding: 18px; white-space: pre-wrap; font-size: 13px; max-height: 600px; overflow-y: auto; margin-top: 14px; }
.provider-selector { background: #f0f4ff; border-radius: 30px; padding: 6px 16px; display: inline-flex; align-items: center; gap: 16px; font-size: 13px; font-weight: 600; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 6px; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
</style>
</head>
<body>
<div class="container">
<div class="hero">
    <h1>🎬 Cinematic AI Script Generator</h1>
    <p>Type your business — AI suggests everything — you just click to customize</p>
</div>

<div class="steps">
    <div class="step-item"><div class="step-dot <?= $step=='0'?'active':($step>='1'?'done':'idle') ?>">1</div><span class="step-label">Business</span></div>
    <div class="step-line"></div>
    <div class="step-item"><div class="step-dot <?= $step=='1'?'active':($step>='2'?'done':'idle') ?>">2</div><span class="step-label">Parameters</span></div>
    <div class="step-line"></div>
    <div class="step-item"><div class="step-dot <?= $step=='2'?'active':($step>='3'?'done':'idle') ?>">3</div><span class="step-label">Story</span></div>
    <div class="step-line"></div>
    <div class="step-item"><div class="step-dot <?= $step=='3'?'active':'idle' ?>">4</div><span class="step-label">Script</span></div>
</div>

<div class="card">
    <div class="card-title">Step 1 — Your Business</div>
    <form method="POST">
        <input type="hidden" name="step" value="1">
        <div class="business-row">
            <input class="business-input" type="text" name="business" value="<?= htmlspecialchars($business) ?>" placeholder="e.g. Marriage bureau for Pakistani community in Toronto">
            <button type="submit" class="btn btn-blue">✨ Get AI Suggestions</button>
        </div>
        <div style="margin-top:12px;">
            <label style="font-size:11px;font-weight:700;">💡 Additional Instructions</label>
            <textarea name="additional_info" rows="2" style="width:100%;background:#f8faff;border:1.5px solid #dde4f5;border-radius:10px;padding:10px;margin-top:4px;"><?= htmlspecialchars($additional_info) ?></textarea>
        </div>
        <div style="margin-top:12px;">
            <label style="font-size:11px;font-weight:700;">📣 CTA / Brand Sign-Off</label>
            <textarea name="cta_text" rows="2" style="width:100%;background:#f8faff;border:1.5px solid #dde4f5;border-radius:10px;padding:10px;margin-top:4px;"><?= htmlspecialchars($cta_text) ?></textarea>
        </div>
    </form>
</div>

<?php if ($suggestions && $step >= 1): ?>
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

<?php if ($story && $step >= 2): ?>
<div class="card">
    <div class="card-title">Step 3 — Story Concept</div>
    <div class="output-box"><?= nl2br(htmlspecialchars($story)) ?></div>
    <div class="actions">
        <form method="POST"><input type="hidden" name="step" value="3"><button type="submit" class="btn btn-green">🎥 Generate Full Script</button></form>
        <form method="POST"><input type="hidden" name="step" value="2"><?php if (isset($_SESSION['selected'])) foreach ($_SESSION['selected'] as $k => $v) echo '<input type="hidden" name="sel_'.$k.'" value="'.htmlspecialchars($v).'">'; ?><button type="submit" class="btn btn-orange">🔁 Regenerate Story</button></form>
        <button type="button" class="btn-gray" onclick="warmupModalManually()">🔥 Warmup Modal</button>
    </div>
</div>
<?php endif; ?>

<?php if ($script && $step >= 3): ?>
<div class="card">
    <div class="card-title">Step 4 — Full Video Script</div>
    <div class="output-box" id="scriptBox"><?= nl2br(htmlspecialchars($script)) ?></div>
    <div class="actions" style="justify-content: space-between; flex-wrap: wrap;">
        <div class="provider-selector">
            <span>🎨 Image Provider:</span>
            <label><input type="radio" name="image_provider" value="auto" checked> Auto (FLUX→OpenAI)</label>
            <span style="font-size:10px; color:#f97316;">FLUX first, fallback to OpenAI</span>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-blue" onclick="copyScript()">📋 Copy Script</button>
            <button class="btn btn-green" onclick="generateAllScenes()" id="btnGenScenes">🎬 Generate Scene Images</button>
            <button class="btn btn-orange" onclick="saveMovieToDB()" id="btnSaveDB">💾 Save to VideoVizard</button>
            <button class="btn btn-gray" onclick="debugScript()">🔍 Debug Script</button>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</div>
<div id="storyboardSection" style="display:none; margin-top:20px;">
    <div class="card">
        <div class="card-title">🎞️ Scene Storyboard</div>
        <div id="sceneProgress" style="display:none; margin-bottom:14px;">
            <div style="background:#f0f4ff; border-radius:99px; height:8px;"><div id="sceneProgressBar" style="height:100%; background:linear-gradient(90deg,#1d4ed8,#f97316); width:0%; border-radius:99px;"></div></div>
            <div style="font-size:11px; margin-top:5px;" id="sceneProgressLabel">Generating scenes...</div>
        </div>
        <div id="storyboardGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:16px;"></div>
    </div>
</div>
<script>const FULL_SCRIPT = <?= json_encode($script) ?>;</script>
<?php endif; ?>
</div>

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
    if (!scenes.length) { alert('No scenes found.'); return; }
    const provider = document.querySelector('input[name="image_provider"]:checked')?.value || 'auto';
    const actionName = 'generate_scene_image';
    const btn = document.getElementById('btnGenScenes'); btnLoading(btn, 'Starting…');
    document.getElementById('storyboardSection').style.display = 'block'; document.getElementById('sceneProgress').style.display = 'block';
    document.getElementById('storyboardGrid').innerHTML = '';
    scenes.forEach(sc => {
        const card = document.createElement('div'); card.id = 'scene-card-' + sc.num; card.style.cssText = 'background:#f8faff;border:1.5px solid #dde4f5;border-radius:12px;overflow:hidden;';
        card.innerHTML = `<div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f0f4ff,#e8eeff);display:flex;align-items:center;justify-content:center;padding:16px;"><span style="font-size:28px">🎬</span><div id="scene-status-${sc.num}" style="margin-left:10px;font-size:11px;">⏳</div></div>`;
        document.getElementById('storyboardGrid').appendChild(card);
    });
    let completed = 0, successCount = 0;
    function onDone(wasSuccess) { if (wasSuccess) successCount++; completed++; const pct = Math.round(completed / scenes.length * 100); document.getElementById('sceneProgressBar').style.width = pct + '%'; document.getElementById('sceneProgressLabel').textContent = successCount + ' of ' + scenes.length + ' scenes done…'; if (completed === scenes.length) { btn.disabled = false; btn.innerHTML = '🔁 Regenerate Scenes'; } }
    function renderCard(sc, data) {
        const card = document.getElementById('scene-card-' + sc.num); if (!card) return;
        if (data && data.success) {
            card.innerHTML = `<div style="position:relative;aspect-ratio:9/16;"><img src="${data.file}" style="width:100%;height:100%;object-fit:cover;"></div><div style="padding:8px;"><div style="font-size:11px;font-weight:700;">Scene ${sc.num}</div><div style="font-size:10px;color:#64748b;">${sc.title.substring(0,40)}</div><div style="font-size:9px;color:#10b981;margin-top:4px;">✅ Source: ${data.source || 'Auto'}</div></div>`;
        } else { card.innerHTML = `<div style="aspect-ratio:9/16;display:flex;align-items:center;justify-content:center;"><div style="text-align:center"><span style="font-size:20px">❌</span><div style="font-size:11px;">Failed</div></div></div>`; }
        onDone(data && data.success);
    }
    async function genScene(sc) {
        const st = document.getElementById('scene-status-' + sc.num); if (st) st.innerHTML = '<span class="spinner"></span>';
        try {
            const fd = new FormData(); fd.append('ajax_action', actionName); fd.append('wan_prompt', sc.wan); fd.append('caption', sc.caption); fd.append('scene_num', sc.num);
            const resp = await fetch('', { method:'POST', body:fd }); const data = await resp.json();
            renderCard(sc, data); return data.success === true;
        } catch(e) { renderCard(sc, { success:false }); return false; }
    }
    document.getElementById('sceneProgressLabel').textContent = 'Generating scenes...';
    for (const sc of scenes) { await genScene(sc); await new Promise(r => setTimeout(r, 2000)); }
}
async function warmupModalManually() {
    const btn = event.currentTarget; const orig = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span> Warming...'; btn.disabled = true;
    try { const resp = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ajax_action=warmup_modal' }); const data = await resp.json(); alert(data.message || (data.success ? 'Modal ready!' : 'Warmup attempted')); } catch(e) { alert('Warmup error'); }
    finally { btn.innerHTML = orig; btn.disabled = false; }
}
async function saveMovieToDB() { alert('Save function - implement as needed'); }
document.querySelectorAll('form button[type=submit]').forEach(btn => { btn.addEventListener('click', function() { setTimeout(() => btnLoading(this, 'Processing…'), 10); }); });
if (typeof FULL_SCRIPT !== 'undefined' && FULL_SCRIPT) { setTimeout(() => { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ajax_action=warmup_modal' }).catch(e => console.warn('Warmup error:', e)); }, 1000); }
</script>
</body>
</html>
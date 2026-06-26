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

// === MODAL/FLUX CONFIGURATION =================================
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

function generateWithModal($prompt, $maxRetries = 2) {
    global $MODAL_URL;
    
    $payload = json_encode([
        'prompt' => $prompt,
        'style' => 'cinematic',
        'width' => 768,
        'height' => 1344   // 9:16 portrait
    ]);
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
                return $data['image']; // base64 encoded image
            }
        }
        
        if ($attempt < $maxRetries) sleep(3);
    }
    return null;
}


// Warmup function to keep Modal/FLUX endpoint alive or trigger cold start early
function warmupModal() {
    global $MODAL_URL;
    
    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,        // short timeout – just to wake the server
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
        CURLOPT_NOBODY => true
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log warmup attempt (optional)
    file_put_contents(__DIR__.'/modal_warmup.log', date('Y-m-d H:i:s')." | Warmup HTTP $httpCode\n", FILE_APPEND);
    
    return $httpCode > 0;
}
// ==============================================================

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

    // Send a real lightweight test generation to check if Modal is warm
    // Use a simple 1-word prompt with tiny dimensions so it returns fast if warm
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
        CURLOPT_TIMEOUT        => 25,   // if warm it responds in <10s; cold start fails here
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);

    $is_warm = ($curlErr === 0 && $httpCode === 200 && !empty(json_decode($resp, true)['image']));
    error_log("[warmup_modal] httpCode=$httpCode curlErr=$curlErr is_warm=" . ($is_warm?'yes':'no'));

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
- If the suggestion changes a person (e.g. "African man", "South Asian woman", "elderly woman") — FULLY replace ALL character descriptions in the prompt with the new character. Describe their skin tone, hair, face, clothing in detail.
- If the suggestion changes background/location — replace ALL environment descriptions.
- If the suggestion changes colors, lighting, mood — replace those fully.
- Do NOT keep old character descriptions if a new character is specified.
- The change must be OBVIOUS and DOMINANT in the new prompt.
- Image prompt must be photorealistic, bright cool-neutral lighting (5500K), no yellow cast, vivid colors.

Return ONLY this JSON (no markdown, no explanation):
{
  "video_prompt": "updated WAN 2.2 cinematic video prompt — one detailed paragraph with the change fully applied",
  "image_prompt": "updated photorealistic DSLR image prompt — one detailed paragraph with the change fully applied, end with: cinematic realism, ultra photorealistic, 8K, HDR, shallow depth of field, bokeh, Shot on Sony A7R V 85mm lens, bright cool-neutral lighting, no yellow cast"
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

// ── AJAX: Generate scene image using OpenAI ─────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_image') {
    set_time_limit(180);
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    if (!$apiKey) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'API key not found in config.php']);
        exit;
    }
    $wan_prompt  = trim($_POST['wan_prompt']  ?? '');
    $caption     = trim($_POST['caption']     ?? '');
    $scene_num   = (int)($_POST['scene_num']  ?? 0);
    $save_folder = 'podcast_images/scenes';

    if (!$wan_prompt) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'No prompt']);
        exit;
    }

    // Enhance the WAN prompt for image generation
    $business_context = $_SESSION['business'] ?? '';
    $selected_context = isset($_SESSION['selected']) ? json_encode($_SESSION['selected']) : '{}';

    $enhance_system = "You are a world-class cinematic stills director and AI image prompt engineer.
Your task is to convert a scene description into a single ultra-detailed, visually rich, photorealistic image generation prompt.

BUSINESS CONTEXT: {$business_context}

CRITICAL BRIGHTNESS & COLOR RULES — MANDATORY FOR EVERY PROMPT:
- Images MUST be BRIGHT, CLEAN, and WELL-LIT — never dark, never dim, never murky
- Color temperature MUST be COOL-NEUTRAL (5500K-6500K) — NO warm tones, NO yellow cast, NO orange glow, NO amber light
- FORBIDDEN words: golden hour, warm, cozy, amber, candlelight, incandescent, glowing warm, orange light, yellow light
- REQUIRED words to always include: bright natural daylight, cool-neutral lighting, crisp clean colors, vibrant true-to-life colors, no yellow cast, color accurate
- Lighting must be: soft diffused natural light OR bright studio softbox OR clean overhead LED — always bright and color-accurate
- Skin tones must look natural and true-to-life — not orange, not yellow, not oversaturated

CHARACTER & CONTINUITY RULES (CRITICAL):
- SAME main character across ALL scenes — same face structure, same age, same body type
- Define character FULLY every time: exact age, skin tone, hair color and style, eye color, facial features
- Clothing CONSISTENT across scenes — describe every detail: fabric, color, cut, accessories, shoes
- Expression and emotional state match the scene intent

VISUAL RICHNESS RULES:
- Colors VIVID, RICH, SATURATED but COOL-TONED — blues, whites, greens, cool grays preferred over warm yellows
- Describe every visible detail: textures, reflections, surfaces, depth
- Environment fully described: architecture, furniture, decor, props, wall colors, floor colors
- Foreground elements blurred for depth
- Background richly detailed, never plain or empty

FASHION & STYLING:
- Clothing described in full: brand style, fabric, color, cut, accessories, shoes
- Hair, makeup, jewelry fully described
- Premium, aspirational, culturally appropriate to business

CAMERA & COMPOSITION:
- Specify lens: 24mm wide / 35mm standard / 85mm portrait
- Specify framing: wide / medium waist-up / close-up / POV
- Shallow depth of field, crisp subject, beautifully blurred bokeh background
- Off-center composition with negative space

END EVERY PROMPT WITH THESE EXACT QUALITY TAGS:
bright clean lighting, cool neutral daylight 5500K, no warm cast, no yellow tones, color accurate, vivid saturation, cinematic realism, ultra photorealistic, 8K resolution, HDR, film grain, shallow depth of field, bokeh, Shot on Sony A7R V 85mm lens, professional color grade, magazine quality

OUTPUT: One single flowing paragraph — minimum 200 words. No bullet points. No labels. No explanation. Just the prompt.";

    // Build rich user input with full scene context
    $character_context = '';
    if (isset($_SESSION['selected'])) {
        $sel = $_SESSION['selected'];
        $character_context  = "CHARACTER: " . ($sel['character'] ?? '') . "\n";
        $character_context .= "SETTING: "   . ($sel['setting']   ?? '') . "\n";
        $character_context .= "SENTIMENT: " . ($sel['sentiment'] ?? '') . "\n";
        $character_context .= "AUDIO MOOD: ". ($sel['audio_mood']?? '') . "\n";
    }
    $story_context   = isset($_SESSION['story']) ? "STORY CONTEXT: " . substr($_SESSION['story'], 0, 300) . "\n\n" : '';
    $full_user_input = $story_context . $character_context . "\nSCENE TO RENDER:\n" . $wan_prompt;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
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
    $res  = curl_exec($ch);
    curl_close($ch);
    $enhanced_prompt = json_decode($res, true)['choices'][0]['message']['content'] ?? $wan_prompt;

    // Generate image (portrait 9:16) with OpenAI
    $ch2 = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'  => 'gpt-image-1',
            'prompt' => $enhanced_prompt,
            'size'   => '1024x1536',
        ]),
    ]);
    $res2    = curl_exec($ch2);
    curl_close($ch2);
    $b64 = json_decode($res2, true)['data'][0]['b64_json'] ?? null;

    if (!$b64) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'OpenAI image generation failed']);
        exit;
    }

    // Save image
    if (!is_dir($save_folder)) mkdir($save_folder, 0755, true);
    $filename  = $save_folder . '/scene_' . time() . '_' . $scene_num . '.png';
    file_put_contents($filename, base64_decode($b64));

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'file'     => $filename,
        'scene'    => $scene_num,
        'caption'  => $caption,
        'enhanced' => $enhanced_prompt,
    ]);
    exit;
}

// ── AJAX: Generate scene image using Modal/FLUX ──────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_image_modal') {
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

    // --- ENHANCE PROMPT using OpenAI (same logic as above) ---
    $business_context = $_SESSION['business'] ?? '';
    $selected_context = isset($_SESSION['selected']) ? json_encode($_SESSION['selected']) : '{}';

    $enhance_system = "You are a world-class cinematic stills director and AI image prompt engineer.
Your task is to convert a scene description into a single ultra-detailed, visually rich, photorealistic image generation prompt.

BUSINESS CONTEXT: {$business_context}

CRITICAL BRIGHTNESS & COLOR RULES — MANDATORY FOR EVERY PROMPT:
- Images MUST be BRIGHT, CLEAN, and WELL-LIT — never dark, never dim, never murky
- Color temperature MUST be COOL-NEUTRAL (5500K-6500K) — NO warm tones, NO yellow cast, NO orange glow, NO amber light
- FORBIDDEN words: golden hour, warm, cozy, amber, candlelight, incandescent, glowing warm, orange light, yellow light
- REQUIRED words to always include: bright natural daylight, cool-neutral lighting, crisp clean colors, vibrant true-to-life colors, no yellow cast, color accurate
- Lighting must be: soft diffused natural light OR bright studio softbox OR clean overhead LED — always bright and color-accurate
- Skin tones must look natural and true-to-life — not orange, not yellow, not oversaturated

CHARACTER & CONTINUITY RULES (CRITICAL):
- SAME main character across ALL scenes — same face structure, same age, same body type
- Define character FULLY every time: exact age, skin tone, hair color and style, eye color, facial features
- Clothing CONSISTENT across scenes — describe every detail: fabric, color, cut, accessories, shoes
- Expression and emotional state match the scene intent

VISUAL RICHNESS RULES:
- Colors VIVID, RICH, SATURATED but COOL-TONED — blues, whites, greens, cool grays preferred over warm yellows
- Describe every visible detail: textures, reflections, surfaces, depth
- Environment fully described: architecture, furniture, decor, props, wall colors, floor colors
- Foreground elements blurred for depth
- Background richly detailed, never plain or empty

FASHION & STYLING:
- Clothing described in full: brand style, fabric, color, cut, accessories, shoes
- Hair, makeup, jewelry fully described
- Premium, aspirational, culturally appropriate to business

CAMERA & COMPOSITION:
- Specify lens: 24mm wide / 35mm standard / 85mm portrait
- Specify framing: wide / medium waist-up / close-up / POV
- Shallow depth of field, crisp subject, beautifully blurred bokeh background
- Off-center composition with negative space

END EVERY PROMPT WITH THESE EXACT QUALITY TAGS:
bright clean lighting, cool neutral daylight 5500K, no warm cast, no yellow tones, color accurate, vivid saturation, cinematic realism, ultra photorealistic, 8K resolution, HDR, film grain, shallow depth of field, bokeh, Shot on Sony A7R V 85mm lens, professional color grade, magazine quality

OUTPUT: One single flowing paragraph — minimum 200 words. No bullet points. No labels. No explanation. Just the prompt.";

    $character_context = '';
    if (isset($_SESSION['selected'])) {
        $sel = $_SESSION['selected'];
        $character_context  = "CHARACTER: " . ($sel['character'] ?? '') . "\n";
        $character_context .= "SETTING: "   . ($sel['setting']   ?? '') . "\n";
        $character_context .= "SENTIMENT: " . ($sel['sentiment'] ?? '') . "\n";
        $character_context .= "AUDIO MOOD: ". ($sel['audio_mood']?? '') . "\n";
    }
    $story_context   = isset($_SESSION['story']) ? "STORY CONTEXT: " . substr($_SESSION['story'], 0, 300) . "\n\n" : '';
    $full_user_input = $story_context . $character_context . "\nSCENE TO RENDER:\n" . $wan_prompt;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
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
    $res  = curl_exec($ch);
    curl_close($ch);
    $enhanced_prompt = json_decode($res, true)['choices'][0]['message']['content'] ?? $wan_prompt;

    // --- GENERATE IMAGE VIA MODAL/FLUX ---
    $base64_image = generateWithModal($enhanced_prompt);
    
    if (!$base64_image) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Modal/FLUX generation failed. Server may be cold – try again in a moment.']);
        exit;
    }

    // Save image
    if (!is_dir($save_folder)) mkdir($save_folder, 0755, true);
    $filename = $save_folder . '/scene_modal_' . time() . '_' . $scene_num . '.png';
    file_put_contents($filename, base64_decode($base64_image));

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'file'     => $filename,
        'scene'    => $scene_num,
        'caption'  => $caption,
        'enhanced' => $enhanced_prompt,
        'source'   => 'Modal/FLUX'
    ]);
    exit;
}

// ── STEP 1: AI suggests all parameters from niche ─────────────
if ($step == "1" && isset($_POST['business']) && !empty(trim($_POST['business']))) {
    $business     = trim($_POST['business']);
    $additional_info = trim($_POST['additional_info'] ?? '');
    $cta_text     = trim($_POST['cta_text'] ?? '');
    $_SESSION['business']      = $business;
    $_SESSION['additional_info'] = $additional_info;
    $_SESSION['cta_text']      = $cta_text;

    $systemPrompt = "
You are a cinematic video strategist for short-form social media videos.
Given a business niche, generate EXACTLY the following JSON with NO extra text, NO markdown, NO backticks.
Each field must have:
  - 'selected': the best single suggestion (a short phrase, max 8 words)
  - 'options': array of 4 alternative options (short phrases, max 8 words each), NOT including the selected value

Fields to generate:
- title
- sentiment
- hook
- character
- setting
- audio_mood
- hero_element
- emotional_outcome

Return pure JSON only:
{
  \"title\":            {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"sentiment\":        {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\":             {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"character\":        {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"setting\":          {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\":       {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hero_element\":     {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"emotional_outcome\":{\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}
";
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, "Business/Niche: $business", 0.85);

    // Strip markdown fences if AI wrapped JSON
    if ($raw) {
        $raw = preg_replace('/^```json\s*/i', '', trim($raw));
        $raw = preg_replace('/^```\s*/i',     '', $raw);
        $raw = preg_replace('/```\s*$/i',     '', $raw);
        $raw = trim($raw);
    }

    $suggestions = $raw ? json_decode($raw, true) : null;

    if ($suggestions && isset($suggestions['title'])) {
        $_SESSION['suggestions'] = $suggestions;
    } else {
        $debug = $raw ? substr($raw, 0, 200) : ($apiKey ? 'callAI returned null' : 'No API key found');
        error_log("[movie_gen step1] Failed: $debug");
        $_SESSION['error'] = "AI could not generate suggestions. (" . htmlspecialchars(substr($debug, 0, 100)) . ") — Please try again.";
    }
}

// ── STEP 2: Generate Story from selected params ────────────────
if ($step == "2" && isset($_SESSION['suggestions'])) {
    $s = $_SESSION['suggestions'];
    $business = $_SESSION['business'] ?? '';

    // Read user-selected values from POST (fallback to AI selected)
    $sel = [
        'title'            => trim($_POST['sel_title']            ?? $s['title']['selected']            ?? ''),
        'sentiment'        => trim($_POST['sel_sentiment']        ?? $s['sentiment']['selected']        ?? ''),
        'hook'             => trim($_POST['sel_hook']             ?? $s['hook']['selected']             ?? ''),
        'character'        => trim($_POST['sel_character']        ?? $s['character']['selected']        ?? ''),
        'setting'          => trim($_POST['sel_setting']          ?? $s['setting']['selected']          ?? ''),
        'audio_mood'       => trim($_POST['sel_audio_mood']       ?? $s['audio_mood']['selected']       ?? ''),
        'hero_element'     => trim($_POST['sel_hero_element']     ?? $s['hero_element']['selected']     ?? ''),
        'emotional_outcome'=> trim($_POST['sel_emotional_outcome']?? $s['emotional_outcome']['selected']?? ''),
    ];
    $_SESSION['selected'] = $sel;

    $paramBlock = "
Business/Niche   : $business
Title            : {$sel['title']}
Sentiment        : {$sel['sentiment']}
Hook             : {$sel['hook']}
Character/Persona: {$sel['character']}
Setting/Ambience : {$sel['setting']}
Audio Mood       : {$sel['audio_mood']}
Hero Element     : {$sel['hero_element']}
Emotional Outcome: {$sel['emotional_outcome']}
";

    $systemPrompt = "
You are a world-class cinematic story director for short-form video content.
Create a powerful story concept for any service business using the parameters provided.

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
[One powerful cinematic paragraph — emotional journey from problem to resolution. No scenes. No technical details. Pure narrative.]

URDU TRANSLATION:
[Translate the CORE STORY paragraph above into Urdu script (nastaliq). Keep the emotional tone intact.]
";
    $additional = $_SESSION['additional_info'] ?? trim($_POST['additional_info'] ?? '');
    if ($additional) $paramBlock .= "\nAdditional Instructions: $additional";
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $paramBlock);
    $_SESSION['story'] = $response;
}

// ── STEP 3: Generate Full Video Script ────────────────────────
if ($step == "3" && isset($_SESSION['story'])) {
    $story   = $_SESSION['story'];
    $sel     = $_SESSION['selected'] ?? [];
    $business= $_SESSION['business'] ?? '';

    $systemPrompt = "
You are an expert cinematic AI film director and short-form video production designer.
You will receive an APPROVED STORY CONCEPT.
Your task is to convert it into a complete 30-40 second faceless cinematic video for TikTok, Instagram Reels, YouTube Shorts, and Facebook Reels.

# GLOBAL CONTINUITY LOCK (MANDATORY)
Maintain strict continuity across ALL scenes:
1. Character Consistency
   - Same main character across all scenes
   - No change in face, age, body type, clothing style unless explicitly required by story
   - Only emotional state and actions may evolve
2. World Consistency
   - Same environment universe (city, location type, architecture style)
   - Same weather and atmospheric tone throughout
3. Cinematic Style Consistency
   - Same color grading across all scenes
   - Same film aesthetic (realistic cinematic, shallow depth of field, film grain, HDR realism)
   - Same camera language across entire video
4. Emotional Continuity
   - Each scene must feel like a continuation of the previous emotional state
   - The story must feel like ONE continuous short film

# OUTPUT STRUCTURE

## 1. TITLE (Cinematic Film Title)

## 2. OVERALL VISUAL STYLE
Define:
- Cinematic style
- Color grading (warm, cold, neutral, moody, etc.)
- Film pacing (slow emotional / dynamic / hybrid)

## 3. AUDIO DESIGN
Include:
- Music style (piano, ambient, orchestral, lo-fi, etc.)
- Tempo (slow / medium / rising)
- Emotional purpose of music
- Ambient sound design (rain, city noise, room tone, etc.)

## 4. SCENE BREAKDOWN (5-7 SCENES)
Each scene MUST follow this structure:

### SCENE (n)
Scene Description: Brief visual explanation of what is happening.

WAN 2.2 CINEMATIC PROMPT:
[Write ONE detailed flowing paragraph — minimum 80 words — describing this scene for AI video generation. Include:
- The main character's exact appearance, clothing, expression
- What they are doing (action in motion)
- The full environment: location, decor, colors, textures
- Lighting: MUST be bright, cool-neutral daylight (5500K-6500K) — NO warm tones, NO yellow, NO golden hour
- Camera: shot type, lens (24mm/35mm/50mm), movement
- End with: bright cool-neutral lighting, no yellow cast, color accurate, cinematic realism, ultra realistic, shallow depth of field, film grain, motion blur, HDR lighting, photorealistic]
DO NOT use bullet points inside the WAN prompt — write it as one rich paragraph.
NEVER use warm, golden, amber, cozy, incandescent — always use bright, clean, cool, crisp, daylight.

CAMERA MOVEMENT: Describe motion clearly.
LIGHTING STYLE: Define cinematic lighting precisely.
EMOTIONAL INTENT: Describe emotional progression (e.g. doubt to hope to realization to joy).
ON-SCREEN TEXT: Short viral caption 2-6 words max, or NONE.

## 5. FINAL EMOTIONAL RESOLUTION
Describe:
- Final emotional transformation
- Viewer takeaway
- Emotional closure of the story

# STRICT RULES
- No talking, no dialogue
- Must remain faceless or POV-based where possible
- Must feel like ONE continuous cinematic short film
- Must NOT change character identity across scenes
- Must be optimized for AI video generation (WAN 2.2 ready)
- Must prioritize emotional storytelling over advertisement tone
- Always use bright cool-neutral lighting — NEVER warm, golden, amber, cozy tones
- If a CTA / Brand Sign-Off is provided, the FINAL SCENE must be a cinematic branded end card:
  * Background: elegant abstract visuals — geometric shapes, light rays, gradient colors, soft bokeh particles or flowing fabric — NO people
  * Display the CTA text prominently centered on screen in large bold typography
  * Camera: slow zoom out from center or gentle drift
  * Lighting: dramatic studio lighting, rich deep colors, premium brand feel
";
    $additional = $_SESSION['additional_info'] ?? '';
    $cta        = $_SESSION['cta_text']       ?? '';
    $additional_block = $additional ? "\n\n# ADDITIONAL SPECIFIC INSTRUCTIONS (MANDATORY — override defaults if needed):\n$additional" : '';
    $cta_block        = $cta ? "\n\n# CTA / BRAND SIGN-OFF (MANDATORY LAST SCENE):\nCreate a final cinematic brand sign-off scene with elegant visual design — gradients, geometric shapes, or light effects as background. Display this text prominently:\n$cta" : '';
    $input = "Business: $business\nAPPROVED STORY:\n$story" . $additional_block . $cta_block;
    $response = callAI($apiUrl, $apiKey, $systemPrompt, $input);
    $_SESSION['script'] = $response;
}

// ── STEP 4: Regenerate suggestions (new variation) ─────────────
if ($step == "4" && isset($_SESSION['business'])) {
    // Just re-run step 1 with same business
    $_POST['business'] = $_SESSION['business'];
    $_POST['step'] = "1";
    $step = "1";

    $business = $_SESSION['business'];
    $systemPrompt = "
You are a cinematic video strategist.
Given a business niche, generate a COMPLETELY DIFFERENT set of parameters from before.
Fresh angle, different emotional journey. Return EXACTLY this JSON with NO extra text:
{
  \"title\":            {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"sentiment\":        {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hook\":             {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"character\":        {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"setting\":          {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"audio_mood\":       {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"hero_element\":     {\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]},
  \"emotional_outcome\":{\"selected\": \"...\", \"options\": [\"...\",\"...\",\"...\",\"...\"]}
}
";
    $additional = $_SESSION['additional_info'] ?? '';
    $step4_input = "Business/Niche: $business" . ($additional ? "\nAdditional Instructions: $additional" : '');
    $raw = callAI($apiUrl, $apiKey, $systemPrompt, $step4_input, 0.95);
    $suggestions = $raw ? json_decode($raw, true) : null;
    if ($suggestions) {
        $_SESSION['suggestions'] = $suggestions;
        unset($_SESSION['story'], $_SESSION['script'], $_SESSION['selected']);
    }
}

$suggestions      = $_SESSION['suggestions']    ?? null;
$business         = $_SESSION['business']       ?? '';
$story            = $_SESSION['story']          ?? '';
$script           = $_SESSION['script']         ?? '';
$additional_info  = $_SESSION['additional_info']?? '';
$cta_text         = $_SESSION['cta_text']       ?? '';

// Helper: render a parameter card
function paramCard($key, $label, $description, $icon, $data) {
    $selected = $data['selected'] ?? '';
    $options  = $data['options']  ?? [];
    $inputId  = "sel_$key";
    echo "<div class='param-card'>";
    echo "<div class='param-header'>";
    echo "<span class='param-icon'>$icon</span>";
    echo "<div style='flex:1; min-width:0;'>";
    echo "<div style='display:flex; align-items:center; justify-content:space-between; gap:4px; flex-wrap:wrap;'>";
    echo "<div class='param-label'>$label</div>";
    echo "<button type='button' class='more-opts-btn' id='more_btn_$key' onclick=\"moreOptions('$key')\">+ More</button>";
    echo "</div>";
    echo "<div class='param-desc'>$description</div>";
    echo "</div>";
    echo "</div>";
    echo "<input type='hidden' name='$inputId' id='$inputId' value='" . htmlspecialchars($selected) . "'>";
    echo "<div class='selected-val' id='display_$key'>" . htmlspecialchars($selected) . "</div>";
    echo "<div class='options-row' id='opts_$key'>";
    foreach ($options as $opt) {
        $esc_display = htmlspecialchars($opt, ENT_QUOTES);
        $esc_data    = htmlspecialchars($opt, ENT_QUOTES);
        echo "<button type='button' class='opt-chip' data-key='" . htmlspecialchars($key, ENT_QUOTES) . "' data-val='$esc_data' onclick=\"selectOpt(this)\">$esc_display</button>";
    }
    echo "</div>";
    echo "</div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cinematic AI Script Generator</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4ff; color: #1e293b; min-height: 100vh; padding: 24px 16px; }
.container { max-width: 900px; margin: 0 auto; }

/* Header */
.hero {
    background: linear-gradient(135deg, #1d4ed8 0%, #f97316 100%);
    border-radius: 18px; padding: 36px 28px; margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(29,78,216,.2); text-align: center;
}
.hero h1 { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -.3px; }
.hero p  { color: rgba(255,255,255,.82); font-size: 13px; margin-top: 8px; }

/* Cards */
.card { background: #fff; border: 1.5px solid #dde4f5; border-radius: 14px; padding: 22px; margin-bottom: 18px; box-shadow: 0 2px 10px rgba(29,78,216,.06); }
.card-title { font-size: 11px; font-weight: 700; color: #f97316; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 16px; }

/* Business input */
.business-row { display: flex; gap: 10px; align-items: stretch; }
.business-input {
    flex: 1; background: #f8faff; border: 2px solid #dde4f5; border-radius: 10px;
    color: #1e293b; font-size: 14px; padding: 12px 16px; outline: none;
    transition: all .2s; font-family: inherit;
}
.business-input:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.12); }
::placeholder { color: #c4cfe8; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 11px 22px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; transition: all .15s; white-space: nowrap; font-family: inherit; }
.btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.14); }
.btn:active { transform: scale(.97); }
.btn-blue   { background: linear-gradient(135deg,#1d4ed8,#3b82f6); color: #fff; }
.btn-green  { background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff; }
.btn-orange { background: linear-gradient(135deg,#ea580c,#f97316); color: #fff; }
.btn-gray   { background: #f0f4ff; color: #64748b; border: 1.5px solid #dde4f5; }
.btn-sm     { padding: 8px 16px; font-size: 12px; }

.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

/* Parameter grid */
.params-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:620px) { .params-grid { grid-template-columns: 1fr; } }

/* Parameter card */
.param-card {
    background: #f8faff; border: 1.5px solid #dde4f5; border-radius: 12px;
    padding: 14px; transition: border-color .2s;
}
.param-card:hover { border-color: #bfcef5; }
.param-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.param-icon { font-size: 20px; line-height: 1; flex-shrink: 0; margin-top: 1px; }
.param-label { font-size: 12px; font-weight: 700; color: #1d4ed8; text-transform: uppercase; letter-spacing: .4px; }
.param-desc  { font-size: 11px; color: #94a3b8; margin-top: 2px; line-height: 1.4; }

/* Selected value display */
.selected-val {
    background: linear-gradient(135deg,#1d4ed8,#3b82f6); color: #fff;
    border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600;
    margin-bottom: 10px; min-height: 36px; display: flex; align-items: center;
}

/* Option chips */
.options-row { display: flex; gap: 6px; flex-wrap: wrap; }
.opt-chip {
    background: #fff; border: 1.5px solid #dde4f5; color: #475569;
    padding: 5px 11px; border-radius: 99px; font-size: 11px; cursor: pointer;
    transition: all .15s; font-family: inherit; font-weight: 500;
}
.opt-chip:hover { border-color: #f97316; color: #ea580c; background: #fff7ed; }
.opt-chip.picked { border-color: #f97316; color: #ea580c; background: #fff7ed; font-weight: 700; }

/* Steps */
.steps { display: flex; align-items: center; margin-bottom: 22px; }
.step-item { display: flex; align-items: center; gap: 7px; font-size: 12px; }
.step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.step-dot.active { background: linear-gradient(135deg,#f97316,#fb923c); color: #fff; box-shadow: 0 2px 8px rgba(249,115,22,.4); }
.step-dot.done   { background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff; }
.step-dot.idle   { background: #f0f4ff; color: #94a3b8; border: 1.5px solid #dde4f5; }
.step-label { color: #64748b; font-weight: 600; }
.step-line  { flex: 1; height: 2px; background: #dde4f5; margin: 0 8px; border-radius: 99px; }

/* Output */
.output-box {
    background: #f8faff; border: 1.5px solid #dde4f5; border-radius: 10px;
    padding: 18px; white-space: pre-wrap; line-height: 1.85; font-size: 13px;
    color: #334155; max-height: 600px; overflow-y: auto; margin-top: 14px;
    font-family: 'Consolas', 'Courier New', monospace;
}
.error-box { background: #fff5f5; border: 1.5px solid #fecaca; border-radius: 10px; padding: 14px; color: #dc2626; margin-top: 14px; font-size: 13px; }

hr { border: none; border-top: 1.5px solid #dde4f5; margin: 22px 0; }

.loading { text-align:center; padding:30px; color:#64748b; font-size:13px; }
.badge { display:inline-block; background:#fff7ed; color:#ea580c; border:1px solid #fed7aa; border-radius:99px; padding:2px 10px; font-size:10px; font-weight:700; margin-left:8px; vertical-align:middle; }

.more-opts-btn {
    background: #f0f4ff; border: 1.5px solid #dde4f5; color: #1d4ed8;
    padding: 2px 9px; border-radius: 99px; font-size: 10px; font-weight: 700;
    cursor: pointer; transition: all .15s; white-space: nowrap; font-family: inherit;
    flex-shrink: 0; margin-left: auto;
}
.more-opts-btn:hover { background: #dde4f5; border-color: #1d4ed8; }
.more-opts-btn:disabled { opacity: .5; cursor: not-allowed; }
.opt-chip.new-chip { 
    border-color: #22c55e; color: #16a34a; background: #f0fdf4; 
    animation: chipPop .3s ease; 
}
@keyframes chipPop { 
    from { transform: scale(.8); opacity: 0; } 
    to { transform: scale(1); opacity: 1; } 
}

.provider-selector {
    background: #f8faff;
    border-radius: 30px;
    padding: 4px 12px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    font-size: 12px;
}
</style>
</head>
<body>
<div class="container">

<div class="hero">
    <h1>🎬 Cinematic AI Script Generator</h1>
    <p>Type your business — AI suggests everything — you just click to customize</p>
</div>

<!-- Step indicator -->
<div class="steps">
    <div class="step-item">
        <div class="step-dot <?= $step=='0'?'active':($step>='1'?'done':'idle') ?>">1</div>
        <span class="step-label">Your Business</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item">
        <div class="step-dot <?= $step=='1'?'active':($step>='2'?'done':'idle') ?>">2</div>
        <span class="step-label">AI Suggestions</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item">
        <div class="step-dot <?= $step=='2'?'active':($step>='3'?'done':'idle') ?>">3</div>
        <span class="step-label">Story Concept</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item">
        <div class="step-dot <?= $step=='3'?'active':'idle' ?>">4</div>
        <span class="step-label">Full Script</span>
    </div>
</div>

<!-- BUSINESS INPUT -->
<div class="card">
    <div class="card-title">Step 1 — Your Business</div>
    <form method="POST">
        <input type="hidden" name="step" value="1">

        <div style="margin-bottom:12px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">
                🌐 Language
            </label>
            <select name="lang_code" id="langSelect"
                style="width:100%;background:#f8faff;border:1.5px solid #dde4f5;border-radius:10px;color:#1e293b;font-size:13px;padding:10px 14px;outline:none;cursor:pointer;transition:border-color .2s;"
                onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#dde4f5'">
                <option value="en">English</option>
            </select>
        </div>

        <div class="business-row">
            <input class="business-input" type="text" name="business"
                value="<?= htmlspecialchars($business) ?>"
                placeholder="e.g. Marriage bureau for Pakistani community in Toronto · Dental clinic · Immigration law firm · Real estate agent…">
            <button type="submit" class="btn btn-blue">✨ Get AI Suggestions</button>
        </div>
        <div style="margin-top:12px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">
                💡 Additional Instructions <span style="font-weight:400;color:#94a3b8;text-transform:none;">(optional — specific directions for your video)</span>
            </label>
            <textarea name="additional_info" rows="3"
                style="width:100%;background:#f8faff;border:1.5px solid #dde4f5;border-radius:10px;color:#1e293b;font-size:13px;padding:10px 14px;outline:none;resize:vertical;font-family:inherit;transition:border-color .2s;"
                onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#dde4f5'"
                placeholder="e.g. Show faces of South Asian people · Background should be from Karachi Pakistan · It is Eid holidays · Use traditional Pakistani clothing · Show a modern Canadian city skyline…"><?= htmlspecialchars($additional_info) ?></textarea>
        </div>

        <div style="margin-top:12px;">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">
                📣 CTA / Brand Sign-Off <span style="font-weight:400;color:#94a3b8;text-transform:none;">(optional — shown as styled last scene)</span>
            </label>
            <textarea name="cta_text" rows="3"
                style="width:100%;background:#f8faff;border:1.5px solid #dde4f5;border-radius:10px;color:#1e293b;font-size:13px;padding:10px 14px;outline:none;resize:vertical;font-family:inherit;transition:border-color .2s;"
                onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#dde4f5'"
                placeholder="e.g. Follow us for more tips&#10;Ultra Hair Stylist&#10;Milton, Ontario · 📞 (905) 555-0123"><?= htmlspecialchars($cta_text) ?></textarea>
            <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Write each line on a new line. AI will create a cinematic branded end card with visual design.</div>
        </div>
    </form>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="error-box"><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- AI PARAMETER SUGGESTIONS -->
<?php if ($suggestions && $step >= 1): ?>
<form method="POST" id="paramsForm">
    <input type="hidden" name="step" value="2">

    <div class="card">
        <div class="card-title">
            Step 2 — AI Suggested Parameters
            <span class="badge">Click any option to change</span>
        </div>

        <div class="params-grid">
            <?php
            paramCard('title',            'Title',            'The name of your video',                           '🎬', $suggestions['title']             ?? []);
            paramCard('sentiment',        'Sentiment',        'Overall emotional tone',                           '💫', $suggestions['sentiment']          ?? []);
            paramCard('hook',             'Hook',             'Opening line that grabs attention in 2 seconds',   '🎯', $suggestions['hook']               ?? []);
            paramCard('character',        'Character',        'Who the viewer relates to in the story',           '👤', $suggestions['character']          ?? []);
            paramCard('setting',          'Setting',          'Where the story takes place visually',             '🌆', $suggestions['setting']            ?? []);
            paramCard('audio_mood',       'Audio Mood',       'Music style and feel',                             '🎵', $suggestions['audio_mood']         ?? []);
            paramCard('hero_element',     'Hero Element',     'The star of your video — the key service moment',  '⭐', $suggestions['hero_element']       ?? []);
            paramCard('emotional_outcome','Emotional Outcome','How the viewer feels at the very end',             '❤️', $suggestions['emotional_outcome']   ?? []);
            ?>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-green">📖 Generate Story Concept</button>
            <button type="button" class="btn btn-orange" onclick="freshSuggestions()">🔀 More Ideas</button>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</form>

<!-- Hidden form for More Ideas / Fresh AI Suggestions (outside paramsForm) -->
<form method="POST" id="freshForm">
    <input type="hidden" name="step" value="4">
    <input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>">
    <input type="hidden" name="additional_info" value="<?= htmlspecialchars($additional_info) ?>">
    <input type="hidden" name="cta_text" value="<?= htmlspecialchars($cta_text) ?>">
</form>
<?php endif; ?>

<!-- STORY OUTPUT -->
<?php if ($story && $step >= 2): ?>
<hr>
<div class="card">
    <div class="card-title">Step 3 — Story Concept</div>
    <?php
    // Split story into params section and CORE STORY section
    $storyParts = preg_split('/CORE STORY[:\s]*/i', $story, 2);
    $storyParams = trim($storyParts[0] ?? $story);
    $coreStory   = trim($storyParts[1] ?? '');
    ?>
    <?php if ($storyParams): ?>
    <div class="output-box" style="max-height:200px; font-size:12px; color:#64748b; margin-bottom:12px;">
        <?= nl2br(htmlspecialchars($storyParams)) ?>
    </div>
    <?php endif; ?>
    <?php
    // Split core story from Urdu translation
    $urduParts  = preg_split('/URDU\s+TRANSLATION\s*[:\s]*/i', $coreStory, 2);
    $coreOnly   = trim($urduParts[0] ?? $coreStory);
    $urduText   = trim($urduParts[1] ?? '');
    ?>
    <?php if ($coreOnly): ?>
    <div style="background:linear-gradient(135deg,#fff7ed,#fef3c7); border:2px solid #f97316; border-radius:12px; padding:18px; margin-bottom:12px;">
        <div style="font-size:11px; font-weight:700; color:#f97316; text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px;">⭐ Core Story Idea</div>
        <div style="font-size:14px; line-height:1.9; color:#1e293b; font-style:italic;" class="core-story-text"><?= nl2br(htmlspecialchars($coreOnly)) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($urduText): ?>
    <div style="background:linear-gradient(135deg,#f0f4ff,#e8eeff); border:2px solid #1d4ed8; border-radius:12px; padding:18px; margin-bottom:4px;">
        <div style="font-size:11px; font-weight:700; color:#1d4ed8; text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px;">🇵🇰 اردو ترجمہ</div>
        <div style="font-size:15px; line-height:2.2; color:#1e293b; font-style:italic; direction:rtl; text-align:right; font-family:'Noto Nastaliq Urdu','Jameel Noori Nastaleeq',serif;"><?= nl2br(htmlspecialchars($urduText)) ?></div>
    </div>
    <?php elseif (!$coreOnly): ?>
    <div class="output-box"><?= nl2br(htmlspecialchars($story)) ?></div>
    <?php endif; ?>
    <div class="actions">
        <form method="POST">
            <input type="hidden" name="step" value="3">
            <button type="submit" class="btn btn-green">🎥 Generate Full Video Script</button>
        </form>
        <form method="POST">
            <input type="hidden" name="step" value="2">
            <?php if (isset($_SESSION['selected'])): foreach ($_SESSION['selected'] as $k => $v): ?>
                <input type="hidden" name="sel_<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
            <?php endforeach; endif; ?>
            <button type="submit" class="btn btn-orange">🔁 Regenerate Story</button>
        </form>
        <form method="POST">
            <input type="hidden" name="step" value="1">
            <input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>">
            <button type="submit" class="btn btn-gray">← Back to Parameters</button>
        </form>
		<button type="button" class="btn-gray" onclick="warmupModalManually()" style="padding:4px 12px; font-size:11px;">🔥 Warmup Modal</button>
		
		
    </div>
</div>
<?php endif; ?>

<!-- FULL SCRIPT OUTPUT -->
<?php if ($script && $step >= 3): ?>
<hr>
<div class="card">
    <div class="card-title">Step 4 — Full Video Script</div>
    <div class="output-box" id="scriptBox"><?= nl2br(htmlspecialchars($script)) ?></div>
    <div class="actions" style="flex-wrap: wrap; justify-content: space-between;">
        <div class="provider-selector">
            <span style="font-weight:700;">🎨 Image Provider:</span>
            <label style="display:inline-flex; align-items:center; gap:4px;">
                <input type="radio" name="image_provider" value="openai" checked> OpenAI DALL‑E
            </label>
            <label style="display:inline-flex; align-items:center; gap:4px;">
                <input type="radio" name="image_provider" value="modal"> Modal/FLUX
            </label>
            <span style="font-size:10px; color:#f97316;">(Modal first request may take 30‑60s)</span>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-blue" onclick="copyScript()">📋 Copy Script</button>
            <button class="btn btn-green" onclick="generateAllScenes()" id="btnGenScenes">🎬 Generate Scene Images</button>
            <button class="btn btn-orange" onclick="saveMovieToDB()" id="btnSaveDB">💾 Save to VideoVizard</button>
            <button class="btn btn-gray" onclick="debugScript()" style="font-size:11px;">🔍 Debug Script</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="business" value="<?= htmlspecialchars($business) ?>">
                <button type="submit" class="btn btn-orange">🔀 New Variation</button>
            </form>
            <a href="?" class="btn btn-gray">🔄 Start Over</a>
        </div>
    </div>
</div>

<!-- STORYBOARD -->
<div id="storyboardSection" style="display:none; margin-top:20px;">
    <div class="card">
        <div class="card-title" style="margin-bottom:12px;">
            🎞️ Scene Storyboard
            <span style="font-size:10px; color:#94a3b8; font-weight:400; text-transform:none; letter-spacing:0; margin-left:8px;">Each image shows what the viewer sees in motion</span>
        </div>
        <div id="sceneProgress" style="display:none; margin-bottom:14px;">
            <div style="background:#f0f4ff; border-radius:99px; height:8px; overflow:hidden;">
                <div id="sceneProgressBar" style="height:100%; background:linear-gradient(90deg,#1d4ed8,#f97316); border-radius:99px; width:0%; transition:width .4s;"></div>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:5px;" id="sceneProgressLabel">Generating scenes...</div>
        </div>
        <div id="storyboardGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:16px;"></div>
    </div>
</div>

<!-- Hidden: pass script to JS -->
<script>
const FULL_SCRIPT = <?= json_encode($script) ?>;
</script>

<?php endif; ?>

</div>

<style>
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
.btn:disabled{opacity:.6;cursor:not-allowed;transform:none!important}
.provider-selector { background: #f0f4ff; border-radius: 30px; padding: 6px 16px; display: inline-flex; align-items: center; gap: 16px; font-size: 13px; font-weight: 600; }
</style>

<script>
// ── Spinner helpers ────────────────────────────────────────────
function btnLoading(btn, text) {
    btn.disabled = true;
    btn.dataset.orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span>' + text;
}
function btnReset(btn) {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.orig || btn.innerHTML;
}

// Add spinners to all form submit buttons
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form button[type=submit]').forEach(btn => {
        btn.addEventListener('click', function() {
            setTimeout(() => btnLoading(this, 'Processing…'), 10);
        });
    });
});

// ── Select an option chip ──────────────────────────────────────
function selectOpt(btn) {
    const key = btn.dataset.key;
    const val = btn.dataset.val;
    document.getElementById('sel_' + key).value = val;
    document.getElementById('display_' + key).textContent = val;
    document.querySelectorAll('#opts_' + key + ' .opt-chip').forEach(c => {
        c.classList.toggle('picked', c === btn);
    });
}

// ── Fresh suggestions ──────────────────────────────────────────
function freshSuggestions() {
    const btn = document.querySelector('[onclick="freshSuggestions()"]');
    if (btn) btnLoading(btn, 'Getting suggestions…');
    document.getElementById('freshForm').submit();
}

// ── More options for a parameter ──────────────────────────────
async function moreOptions(key) {
    const moreBtn = document.getElementById('more_btn_' + key);
    const optsRow = document.getElementById('opts_' + key);
    if (!moreBtn || !optsRow) return;

    const existing = Array.from(optsRow.querySelectorAll('.opt-chip'))
        .map(c => c.textContent.trim()).join(' | ');

    moreBtn.disabled = true;
    moreBtn.textContent = '…';

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'more_options');
        fd.append('field',       key);
        fd.append('business',    document.querySelector('[name=business]')?.value || '');
        fd.append('existing',    existing);
        fd.append('additional',  document.querySelector('[name=additional_info]')?.value || '');

        const response = await fetch('', { method: 'POST', body: fd });
        const data = await response.json();

        if (data.success && data.options && data.options.length) {
            data.options.forEach(opt => {
                const exists = Array.from(optsRow.querySelectorAll('.opt-chip'))
                    .some(c => c.textContent.trim().toLowerCase() === opt.toLowerCase());
                if (exists) return;

                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'opt-chip new-chip';
                chip.dataset.key = key;
                chip.dataset.val = opt;
                chip.textContent = opt;
                chip.onclick = function() { selectOpt(this); };
                optsRow.appendChild(chip);
                setTimeout(() => chip.classList.remove('new-chip'), 400);
            });
            moreBtn.textContent = '+ More';
        } else {
            moreBtn.textContent = '+ More';
            console.error('Failed to get options:', data.error);
        }
    } catch(e) {
        moreBtn.textContent = '+ More';
        console.error('moreOptions error:', e);
    }
    moreBtn.disabled = false;
}

// ── Debug script ───────────────────────────────────────────────
function debugScript() {
    if (typeof FULL_SCRIPT === 'undefined') { alert('No script found'); return; }
    const scenes = parseScenes(FULL_SCRIPT);
    let msg = 'Found ' + scenes.length + ' scenes:\n\n';
    scenes.forEach(s => {
        msg += 'Scene ' + s.num + ': ' + s.title + '\n';
        msg += 'WAN (' + s.wan.length + ' chars): ' + s.wan.substring(0,100) + '...\n';
        msg += 'Caption: ' + (s.caption || 'none') + '\n\n';
    });
    if (!scenes.length) msg += 'RAW (first 600):\n' + FULL_SCRIPT.substring(0,600);
    alert(msg);
}

// ── Copy script ────────────────────────────────────────────────
function copyScript() {
    const box = document.getElementById('scriptBox');
    if (!box) return;
    navigator.clipboard.writeText(box.innerText).then(() => {
        const btn = event.currentTarget;
        const orig = btn.innerHTML;
        btn.innerHTML = '✅ Copied!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

// ── Parse scenes ───────────────────────────────────────────────
function parseScenes(scriptText) {
    const scenes  = [];
    const markers = [];
    const re = /(?:#{1,3}\s*)?(?:\*{1,2})?\bSCENE\s+(\d+)\b(?:\*{1,2})?[^\n]*/gi;
    let m;
    while ((m = re.exec(scriptText)) !== null) {
        markers.push({ index: m.index, num: parseInt(m[1]), header: m[0] });
    }
    if (!markers.length) return scenes;

    for (let i = 0; i < markers.length; i++) {
        const start = markers[i].index + markers[i].header.length;
        const end   = i + 1 < markers.length ? markers[i+1].index : scriptText.length;
        const block = scriptText.substring(start, end);
        const num   = markers[i].num;

        let wanPrompt = '';
        const wanRe  = /WAN\s*2\.2[\s\S]*?PROMPT\s*[:\n]([\s\S]+?)(?=CAMERA\s*MOVEMENT|LIGHTING\s*STYLE|EMOTIONAL\s*INTENT|ON.?SCREEN|##\s*SCENE|\n{3,}|$)/i;
        const wanRe2 = /WAN\s*2\.2\s*[:\-]?\s*([\s\S]+?)(?=CAMERA\s*MOVEMENT|LIGHTING\s*STYLE|EMOTIONAL\s*INTENT|ON.?SCREEN|##\s*SCENE|\n{3,}|$)/i;
        let wm = block.match(wanRe) || block.match(wanRe2);
        if (wm && wm[1].trim().length > 15) {
            wanPrompt = wm[1].trim().replace(/\n+/g, ' ').substring(0, 1200);
        }

        if (!wanPrompt || wanPrompt.length < 20) {
            const descM   = block.match(/(?:Scene\s*Description|Description)\s*[:\-]?\s*([^\n]+)/i);
            const actionM = block.match(/\*{0,2}Action\*{0,2}\s*[:\-]?\s*([^\n]+)/i);
            const envM    = block.match(/\*{0,2}Environment\*{0,2}\s*[:\-]?\s*([^\n]+)/i);
            const lightM  = block.match(/\*{0,2}Lighting\*{0,2}\s*[:\-]?\s*([^\n]+)/i);
            const moodM   = block.match(/\*{0,2}(?:Mood|Emotion)\*{0,2}\s*[:\-]?\s*([^\n]+)/i);
            const camM    = block.match(/\*{0,2}Camera\*{0,2}\s*[:\-]?\s*([^\n]+)/i);
            const qualM   = block.match(/\*{0,2}Film\s*quality[^\n]*/i);

            const parts = [
                descM   ? descM[1].trim()   : '',
                actionM ? actionM[1].trim() : '',
                envM    ? envM[1].trim()    : '',
                lightM  ? lightM[1].trim()  : '',
                moodM   ? moodM[1].trim()   : '',
                camM    ? camM[1].trim()    : '',
                qualM   ? qualM[0].replace(/\*{1,2}/g,'').trim() : 'cinematic realism, ultra realistic, shallow depth of field, film grain, HDR lighting'
            ].filter(p => p.length > 0);
            wanPrompt = parts.join('. ').substring(0, 1200);
        }

        if (!wanPrompt || wanPrompt.length < 20) {
            wanPrompt = block.replace(/#{1,3}|\*{1,2}/g, '').replace(/\n+/g, ' ').trim().substring(0, 800);
        }

        let caption = '';
        const capRe = /ON[.\s-]*SCREEN\s*(?:TEXT|CAPTION)?\s*[:\-]?\s*([^\n]+)/i;
        const capM  = block.match(capRe);
        if (capM) caption = capM[1].trim().replace(/^(NONE|none|-|N\/A)$/i, '').trim();

        let title = markers[i].header.replace(/#{1,3}|\*{1,2}|\bSCENE\s*\d+\b/gi,'').replace(/[—\-–:]/g,'').trim();
        if (!title) title = block.trim().split('\n')[0].replace(/[—\-–:*#]/g,'').trim().substring(0,60) || ('Scene ' + num);

        scenes.push({ num, title, wan: wanPrompt, caption });
    }
    return scenes;
}

// ── Generate all scene images (supports both OpenAI and Modal) ──
async function generateAllScenes() {
    if (typeof FULL_SCRIPT === 'undefined') return;
    const scenes = parseScenes(FULL_SCRIPT);
    if (!scenes.length) {
        alert('No scenes found. Click Debug Script to diagnose.');
        return;
    }

    // Get selected provider
    const provider = document.querySelector('input[name="image_provider"]:checked')?.value || 'openai';
    const actionName = (provider === 'modal') ? 'generate_scene_image_modal' : 'generate_scene_image';

    const btn = document.getElementById('btnGenScenes');
    btnLoading(btn, 'Starting…');

    // ── Warmup Modal before generating if provider is modal ────
    if (provider === 'modal') {
        document.getElementById('sceneProgressLabel') &&
            (document.getElementById('sceneProgressLabel').style.display = 'block');
        document.getElementById('storyboardSection').style.display = 'block';
        document.getElementById('sceneProgress').style.display     = 'block';
        document.getElementById('sceneProgressBar').style.width    = '0%';
        document.getElementById('sceneProgressLabel').textContent  = '🔥 Warming up Modal/FLUX server…';

        // Send warmup ping
        let modalReady = false;
        try {
            const wfd = new FormData();
            wfd.append('ajax_action', 'warmup_modal');
            const wresp = await fetch('', { method:'POST', body:wfd });
            const wdata = await wresp.json();
            if (wdata.success) {
                modalReady = true;
                document.getElementById('sceneProgressLabel').textContent = '✅ Modal server ready — starting generation…';
            } else {
                document.getElementById('sceneProgressLabel').textContent = '⚠️ Modal warming up (cold start) — waiting 15s before starting…';
            }
        } catch(e) {
            document.getElementById('sceneProgressLabel').textContent = '⚠️ Warmup ping failed — waiting 15s before starting…';
        }

        // If not ready, wait for cold start to complete
        if (!modalReady) {
            // Count down 15 seconds
            for (let s = 15; s > 0; s--) {
                document.getElementById('sceneProgressLabel').textContent =
                    '⏳ Modal cold start — beginning in ' + s + 's…';
                await new Promise(r => setTimeout(r, 1000));
            }
            document.getElementById('sceneProgressLabel').textContent = '🚀 Starting generation…';
        }
    }

    btnLoading(btn, `Generating ${scenes.length} scenes with ${provider.toUpperCase()}…`);

    document.getElementById('storyboardSection').style.display = 'block';
    document.getElementById('sceneProgress').style.display     = 'block';
    document.getElementById('storyboardGrid').innerHTML        = '';

    // Placeholder cards
    scenes.forEach(sc => {
        const card = document.createElement('div');
        card.id = 'scene-card-' + sc.num;
        card.style.cssText = 'background:#f8faff;border:1.5px solid #dde4f5;border-radius:12px;overflow:hidden;';
        card.innerHTML = `<div style="aspect-ratio:9/16;background:linear-gradient(135deg,#f0f4ff,#e8eeff);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:16px;">
            <span style="font-size:28px">🎬</span>
            <div style="font-size:11px;font-weight:700;color:#1d4ed8">Scene ${sc.num}</div>
            <div style="font-size:10px;color:#94a3b8;text-align:center">${sc.title.substring(0,40)}</div>
            <div id="scene-status-${sc.num}" style="font-size:10px;color:#94a3b8;margin-top:4px;padding:3px 10px;background:#e8eeff;border-radius:99px;">⏳ Queued</div>
        </div>`;
        document.getElementById('storyboardGrid').appendChild(card);
    });

    let completed = 0;
    let successCount = 0;
    function onDone(wasSuccess) {
        if (wasSuccess) successCount++;
        completed++;
        const pct = Math.round(completed / scenes.length * 100);
        document.getElementById('sceneProgressBar').style.width = pct + '%';
        document.getElementById('sceneProgressLabel').textContent =
            successCount + ' of ' + scenes.length + ' scenes done…';
        if (completed === scenes.length) {
            document.getElementById('sceneProgressLabel').textContent =
                '✅ ' + successCount + ' of ' + scenes.length + ' scenes generated!';
            btn.disabled = false;
            btn.innerHTML = '🔁 Regenerate Scenes';
        }
    }

    function renderCard(sc, data, wanPrompt) {
        const card = document.getElementById('scene-card-' + sc.num);
        if (!card) return;
        // Store current wan prompt on card for suggestion updates
        const currentWan = wanPrompt || sc.wan || '';
        card.dataset.wan = currentWan;

        if (data && data.success) {
            // No caption overlay — captions added later via ffmpeg
            card.innerHTML = `
            <div style="position:relative;aspect-ratio:9/16;overflow:hidden;border-radius:12px 12px 0 0;">
                <img src="${data.file}" style="width:100%;height:100%;object-fit:cover;display:block"
                     onerror="this.parentElement.innerHTML='<div style=padding:16px;text-align:center;color:#ef4444;font-size:11px>Load failed</div>'">
            </div>
            <div style="padding:8px 10px 6px;background:#fff;">
                <div style="font-size:10px;font-weight:700;color:#1d4ed8;">Scene ${sc.num}</div>
                <div style="font-size:10px;color:#64748b;margin-top:1px;">${sc.title.substring(0,50)}</div>
            </div>
            <div style="padding:6px 10px 10px;background:#f8faff;border-top:1px solid #e8efff;">
                <div style="font-size:10px;font-weight:700;color:#64748b;margin-bottom:5px;">✏️ Suggest a change</div>
                <div style="display:flex;gap:6px;align-items:stretch;">
                    <input id="sug-${sc.num}" type="text" placeholder="e.g. change background to Karachi street, use South Asian actress…"
                        style="flex:1;font-size:11px;padding:6px 9px;border:1.5px solid #dde4f5;border-radius:8px;outline:none;font-family:inherit;min-width:0;"
                        onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#dde4f5'">
                    <button onclick="applySceneSuggestion(${sc.num})"
                        style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;"
                        id="sug-btn-${sc.num}">
                        🔄 Generate
                    </button>
                </div>
            </div>`;
        } else {
            card.innerHTML = `
            <div style="aspect-ratio:9/16;display:flex;align-items:center;justify-content:center;padding:16px;background:#fff5f5;border-radius:12px 12px 0 0;">
                <div style="text-align:center">
                    <div style="font-size:20px;margin-bottom:6px">❌</div>
                    <div style="font-size:10px;color:#dc2626">Scene ${sc.num} failed</div>
                    <div style="font-size:10px;color:#94a3b8;margin-top:4px">${data?.error||'Unknown error'}</div>
                    <button onclick="genScene({num:${sc.num},wan:${JSON.stringify(currentWan)},caption:${JSON.stringify(sc.caption||'')}}).then(()=>{})"
                        style="margin-top:10px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:11px;font-weight:700;cursor:pointer;">
                        🔄 Retry
                    </button>
                </div>
            </div>
            <div style="padding:6px 10px 10px;background:#fff5f5;border-top:1px solid #fecaca;">
                <div style="font-size:10px;font-weight:700;color:#64748b;margin-bottom:5px;">✏️ Suggest a change & retry</div>
                <div style="display:flex;gap:6px;align-items:stretch;">
                    <input id="sug-${sc.num}" type="text" placeholder="e.g. change background, use different actor…"
                        style="flex:1;font-size:11px;padding:6px 9px;border:1.5px solid #fecaca;border-radius:8px;outline:none;font-family:inherit;min-width:0;"
                        onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#fecaca'">
                    <button onclick="applySceneSuggestion(${sc.num})"
                        style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;"
                        id="sug-btn-${sc.num}">
                        🔄 Generate
                    </button>
                </div>
            </div>`;
        }
        onDone(data && data.success);
    }

    async function genScene(sc, overrideWan) {
        const st = document.getElementById('scene-status-' + sc.num);
        if (st) {
            st.innerHTML = '<span class="spinner" style="border-color:rgba(29,78,216,.2);border-top-color:#1d4ed8"></span> Generating…';
            st.style.background = '#fff7ed';
            st.style.color = '#f97316';
            st.style.padding = '3px 10px';
            st.style.borderRadius = '99px';
        }
        const wanToUse = overrideWan || sc.wan;
        try {
            const fd = new FormData();
            fd.append('ajax_action', actionName);
            fd.append('wan_prompt',  wanToUse);
            fd.append('caption',     sc.caption);
            fd.append('scene_num',   sc.num);
            const resp = await fetch('', { method:'POST', body:fd });
            const data = await resp.json();
            renderCard(sc, data, wanToUse);
            return data.success === true;
        } catch(e) {
            renderCard(sc, { success:false, error:e.message }, wanToUse);
            return false;
        }
    }

    document.getElementById('sceneProgressLabel').textContent = `Generating scenes one by one using ${provider.toUpperCase()}…`;
    for (const sc of scenes) {
        if (!document.getElementById('btnGenScenes')) break;
        let attempts = 0;
        let success = false;
        while (attempts < 3 && !success) {
            attempts++;
            if (attempts > 1) {
                document.getElementById('sceneProgressLabel').textContent =
                    `Scene ${sc.num} retry ${attempts}/3…`;
                await new Promise(r => setTimeout(r, 5000)); // 5s before retry
            }
            success = await genScene(sc);
        }
        await new Promise(r => setTimeout(r, 2000)); // 2s gap between scenes
    }
}
async function warmupModalManually() {
    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Warming...';
    btn.disabled = true;
    try {
        const resp = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ajax_action=warmup_modal'
        });
        const data = await resp.json();
        showToast(data.success ? 'Modal ready!' : 'Warmup failed, but you can still try', data.success ? 'ok' : 'err');
    } catch(e) {
        showToast('Warmup error', 'err');
    } finally {
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}
// ── Apply scene suggestion ────────────────────────────────────
async function applySceneSuggestion(sceneNum) {
    const card     = document.getElementById('scene-card-' + sceneNum);
    const input    = document.getElementById('sug-' + sceneNum);
    const btn      = document.getElementById('sug-btn-' + sceneNum);
    if (!card || !input || !btn) return;

    const suggestion = input.value.trim();
    if (!suggestion) { input.focus(); return; }

    const currentWan = card.dataset.wan || '';
    if (!currentWan) { alert('No video prompt found for this scene.'); return; }

    // Show loading state
    const origBtn = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    try {
        // Step 1: Get updated prompts from AI
        const fd1 = new FormData();
        fd1.append('ajax_action', 'suggest_scene');
        fd1.append('wan_prompt',  currentWan);
        fd1.append('suggestion',  suggestion);
        fd1.append('scene_num',   sceneNum);

        const sugResp = await fetch('', { method:'POST', body:fd1 }).then(r => r.json());
        if (!sugResp.success) {
            showToast('❌ ' + (sugResp.error || 'AI update failed'), 'err');
            btn.disabled = false; btn.innerHTML = origBtn;
            return;
        }

        // Step 2: Generate new image with updated prompt
        const provider   = document.querySelector('input[name="image_provider"]:checked')?.value || 'openai';
        const actionName = (provider === 'modal') ? 'generate_scene_image_modal' : 'generate_scene_image';

        // Show regenerating state
        const imgDiv = card.querySelector('[style*="aspect-ratio"]');
        if (imgDiv) imgDiv.style.opacity = '0.35';

        // Use the image_prompt from AI — it already has the suggestion applied
        // Prepend OVERRIDE to make sure AI-generated image respects the change
        const finalPrompt = 'OVERRIDE CHARACTER/SCENE CHANGE: ' + suggestion + '. ' + (sugResp.image_prompt || sugResp.video_prompt);

        const fd2 = new FormData();
        fd2.append('ajax_action', actionName);
        fd2.append('wan_prompt',  finalPrompt);
        fd2.append('caption',     '');
        fd2.append('scene_num',   sceneNum);

        const genResp = await fetch('', { method:'POST', body:fd2 }).then(r => r.json());

        if (genResp.success) {
            // Replace image src
            const existingImg = card.querySelector('img');
            if (existingImg) {
                existingImg.src = genResp.file + '?t=' + Date.now();
                if (imgDiv) imgDiv.style.opacity = '1';
            } else if (imgDiv) {
                imgDiv.innerHTML = '<img src="' + genResp.file + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;display:block">';
                imgDiv.style.opacity = '1';
            }
            // Update stored wan prompt and clear input
            card.dataset.wan = sugResp.video_prompt;
            input.value = '';
            showToast('✅ Scene ' + sceneNum + ' updated!', 'ok');
        } else {
            if (imgDiv) imgDiv.style.opacity = '1';
            showToast('❌ ' + (genResp.error || 'Generation failed'), 'err');
        }
    } catch(e) {
        showToast('❌ ' + e.message, 'err');
    }
    btn.disabled = false;
    btn.innerHTML = origBtn;
}

// ── Toast notification ────────────────────────────────────────
function showToast(msg, type) {
    let t = document.getElementById('toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(80px);opacity:0;transition:all .3s;color:#fff;';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.background = type === 'ok'
        ? 'linear-gradient(135deg,#16a34a,#22c55e)'
        : 'linear-gradient(135deg,#dc2626,#ef4444)';
    t.style.transform = 'translateY(0)';
    t.style.opacity   = '1';
    setTimeout(() => { t.style.transform = 'translateY(80px)'; t.style.opacity = '0'; }, 3500);
}

// ── Load languages from DB ─────────────────────────────────────
async function loadLanguages() {
    const sel = document.getElementById('langSelect');
    if (!sel) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_languages');
        const data = await fetch('movie_gen_save.php', { method:'POST', body:fd }).then(r => r.json());
        if (data.success && data.languages.length) {
            sel.innerHTML = '';
            data.languages.forEach(l => {
                const opt = document.createElement('option');
                opt.value = l.lang_code;
                opt.textContent = l.lang_name;
                if (l.lang_code === 'en') opt.selected = true;
                sel.appendChild(opt);
            });
        }
    } catch(e) {
        console.warn('Could not load languages:', e.message);
    }
}
// Warmup Modal when step 4 is displayed (script exists)
if (typeof FULL_SCRIPT !== 'undefined' && FULL_SCRIPT) {
    // Wait 1 second after page load, then call warmup in background
    setTimeout(() => {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ajax_action=warmup_modal'
        }).then(r => r.json()).then(data => {
            console.log('Modal warmup:', data);
            if (data.success) {
                showToast('✅ Modal server ready (cold start done)', 'ok');
            } else {
                console.warn('Modal warmup may have failed');
            }
        }).catch(e => console.warn('Warmup error:', e));
    }, 1000);
}
// ── Save movie script to DB ────────────────────────────────────
async function saveMovieToDB() {
    if (typeof FULL_SCRIPT === 'undefined' || !FULL_SCRIPT) {
        alert('No script to save. Please generate a full video script first.');
        return;
    }
    const btn = document.getElementById('btnSaveDB');
    btnLoading(btn, 'Saving…');

    const scenes     = parseScenes(FULL_SCRIPT);
    const langSel    = document.getElementById('langSelect');
    const langCode   = langSel ? langSel.value : 'en';
    const scriptBox  = document.getElementById('scriptBox');
    const scriptText = scriptBox ? scriptBox.innerText : FULL_SCRIPT;

    // Collect story text
    const storyBox  = document.querySelector('.core-story-text');
    const storyText = storyBox ? storyBox.innerText : '';

    // Get selected parameter values
    const getVal = (id) => { const el = document.getElementById(id); return el ? el.value : ''; };

    // Collect generated scene image filenames (src path from rendered cards)
    const sceneImages = {};
    scenes.forEach(sc => {
        const card = document.getElementById('scene-card-' + sc.num);
        if (card) {
            const img = card.querySelector('img');
            if (img && img.src && img.src.indexOf('data:') < 0) sceneImages[sc.num] = img.src;
        }
    });

    const fd = new FormData();
    fd.append('ajax_action',  'save_movie');
    fd.append('lang_code',    langCode);
    fd.append('business',     document.querySelector('[name=business]')?.value || '');
    fd.append('niche',        document.querySelector('[name=business]')?.value || '');
    fd.append('video_idea',   getVal('sel_title') || document.querySelector('[name=business]')?.value || 'Cinematic Video');
    fd.append('story',        storyText);
    fd.append('script',       scriptText);
    fd.append('character',    getVal('sel_character'));
    fd.append('audio_mood',   getVal('sel_audio_mood'));
    fd.append('sentiment',    getVal('sel_sentiment'));
    fd.append('setting',      getVal('sel_setting'));
    fd.append('background',   getVal('sel_background'));
    fd.append('hero_element', getVal('sel_hero_element'));
    fd.append('scenes',       JSON.stringify(scenes));
    fd.append('scene_images', JSON.stringify(sceneImages));
    fd.append('additional',   document.querySelector('[name=additional_info]')?.value || '');

    try {
        const data = await fetch('movie_gen_save.php', { method:'POST', body:fd }).then(r => r.json());
        btnReset(btn);
        if (data.success) {
            showToast('✅ Saved! Podcast ID: ' + data.podcast_id, 'ok');
            const res = document.getElementById('saveResult');
            const rc  = document.getElementById('saveResultContent');
            if (res && rc) {
                rc.innerHTML = `
                    <div style="display:flex;gap:20px;flex-wrap:wrap;">
                        <div><span style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;display:block;">Podcast ID</span>
                             <span style="font-size:22px;font-weight:800;color:#1d4ed8;">#${data.podcast_id}</span></div>
                        <div><span style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;display:block;">Scenes Saved</span>
                             <span style="font-size:22px;font-weight:800;color:#22c55e;">${data.scene_count}</span></div>
                        <div><span style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;display:block;">Admin ID</span>
                             <span style="font-size:22px;font-weight:800;color:#f97316;">34</span></div>
                        <div><span style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;display:block;">Company ID</span>
                             <span style="font-size:22px;font-weight:800;color:#f97316;">50</span></div>
                    </div>
                    <div style="margin-top:12px;font-size:12px;color:#64748b;">${data.message}</div>`;
                res.style.display = 'block';
                res.scrollIntoView({ behavior:'smooth', block:'nearest' });
            }
        } else {
            showToast('❌ ' + (data.error || 'Save failed'), 'err');
        }
    } catch(e) {
        btnReset(btn);
        showToast('❌ ' + e.message, 'err');
    }
}

document.addEventListener('DOMContentLoaded', loadLanguages);
</script>
</body>
</html>
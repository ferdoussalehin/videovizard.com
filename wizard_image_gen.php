<?php
// ═══════════════════════════════════════════════════════════════════════════════
// wizard_image_gen.php
// Flow: 1) Enhance prompt via ChatGPT  2) Try Modal/FLUX  3) Fallback to OpenAI
// Returns JSON: { success, filename, file_url, generator, seed }
// ═══════════════════════════════════════════════════════════════════════════════

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | wizard_image_gen.php HIT\n", FILE_APPEND);

// enablng Modal
//$use_modal = false — Modal is now skipped entirely until you top up billing.


set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | session OK | admin_id=".($_SESSION['admin_id']??'NOT SET')."\n", FILE_APPEND);

if (!isset($_SESSION['admin_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}

include 'dbconnect_hdb.php';
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | dbconnect OK\n", FILE_APPEND);

require_once 'config.php';
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | config OK | apiKey=".substr($apiKey??'MISSING',0,10)."\n", FILE_APPEND);

if (!function_exists('callChatGPT_inam')) {
    require_once 'chatgpt_functions.php';
}
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | chatgpt_functions OK\n", FILE_APPEND);

// Capture any stray output from includes
$include_output = ob_get_clean();
if (!empty(trim($include_output))) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | INCLUDE OUTPUT: ".substr($include_output,0,500)."\n", FILE_APPEND);
}

header('Content-Type: application/json');

// ── Read POST params ──────────────────────────────────────────────────────────
$scene_id   = (int)($_POST['scene_id']   ?? 0);
$podcast_id = (int)($_POST['podcast_id'] ?? 0);
$prompt     = trim($_POST['prompt']      ?? '');
$img_field  = trim($_POST['image_field'] ?? 'image_file');

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | POST | scene=$scene_id | field=$img_field | prompt=".substr($prompt,0,80)."\n", FILE_APPEND);

// ── Validate image_field ──────────────────────────────────────────────────────
$field_to_prompt = [
    'image_file'   => 'prompt',
    'image_file_1' => 'prompt_1',
    'image_file_2' => 'prompt_2',
    'image_file_3' => 'prompt_3',
    'image_file_4' => 'prompt_4',
];
$esc_field    = in_array($img_field, array_keys($field_to_prompt)) ? $img_field : 'image_file';
$prompt_field = $field_to_prompt[$esc_field];

if (!$prompt) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | ERROR: empty prompt\n", FILE_APPEND);
    echo json_encode(['success'=>false, 'message'=>'Empty prompt']);
    exit;
}

// ── Modal flag — set false until Modal billing is topped up ───────────────────
$use_modal = false;

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Enhance prompt via ChatGPT
// Falls back to original prompt + quality suffix if ChatGPT fails
// ═══════════════════════════════════════════════════════════════════════════════
$quality_suffix = ' Shot on Sony A7R, natural daylight, color accurate, no warm cast, photorealistic';

$gpt_prompt = "Enhance this image generation prompt for a FLUX photorealistic model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words. The image should look like a real photograph.

IMPORTANT COLOR & LIGHTING RULES — always include these in the enhanced prompt:
- Use cool-to-neutral white lighting (5500K-6500K daylight), never warm yellow/orange tones
- Specify: 'soft natural daylight', 'cool white studio lighting' or 'bright overcast window light'
- Add: 'vibrant true-to-life colors', 'no color cast', 'color accurate', 'crisp clean tones'
- Camera: 35mm lens, shallow depth of field, photojournalistic style
- Always end the prompt with: 'Shot on Sony A7R, natural daylight, color accurate, no warm cast, photorealistic'

PEOPLE & APPEARANCE RULES — if the prompt includes a person:
- Target market is Canada, USA and Europe — people must look North American or Northern European
- Use descriptors like: fair skin, light complexion, Caucasian, Canadian, American or British
- Hair: blonde, brown, auburn, light brown or chestnut — not black hair unless specified
- Features: light eyes (blue, green, grey or hazel) or brown eyes with fair skin
- Style: clean modern Western wardrobe — business casual or smart casual
- NEVER generate faces that appear Southeast Asian, East Asian, South Asian, Middle Eastern or Latin American unless explicitly requested
- Age range 28-55 unless the scene calls for something different

Also generate 2 hashtags that describe the emotion and person type shown (examples: calmwoman, confidentman, happycouple, focusedwoman, relaxedman).

Original prompt:
$prompt

Return ONLY valid JSON, no markdown, no code fences:
{\"enhanced_prompt\": \"...\", \"hashtags\": \"emotionperson1,emotionperson2\"}";

// Default: original prompt + quality suffix in case enhancement fails
$enhanced_prompt = $prompt . $quality_suffix;
$hashtags        = '';

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | calling callChatGPT_inam...\n", FILE_APPEND);

try {
    $result = callChatGPT_inam($gpt_prompt);
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | callChatGPT_inam returned: success=".($result['success']??'null')." | response=".substr($result['response']??'',0,100)."\n", FILE_APPEND);

    if (!empty($result['success']) && !empty($result['response'])) {
        $raw    = preg_replace('/^```(?:json)?\s*/i', '', trim($result['response']));
        $raw    = preg_replace('/\s*```$/i', '', $raw);
        $parsed = json_decode(trim($raw), true);
        if ($parsed && !empty($parsed['enhanced_prompt'])) {
            $enhanced_prompt = $parsed['enhanced_prompt'];
            $hashtags        = $parsed['hashtags'] ?? '';
            // Make sure quality suffix is present even in enhanced prompt
            if (stripos($enhanced_prompt, 'photorealistic') === false) {
                $enhanced_prompt .= $quality_suffix;
            }
            file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | enhancement OK\n", FILE_APPEND);
        } else {
            file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | enhancement parse failed — using original+suffix\n", FILE_APPEND);
        }
    } else {
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | enhancement returned empty — using original+suffix\n", FILE_APPEND);
    }
} catch (Throwable $e) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | enhancement exception: ".$e->getMessage()." — using original+suffix\n", FILE_APPEND);
}

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | enhanced_prompt=".substr($enhanced_prompt,0,120)."\n", FILE_APPEND);

// ── Prepare image folder + unique filename ────────────────────────────────────
$image_folder = __DIR__ . '/podcast_images';
if (!is_dir($image_folder)) mkdir($image_folder, 0777, true);

$image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}
$image_name = $image_name_base . '.png';
$filepath   = $image_folder . '/' . $image_name;

$used_generator = '';
$generation_ok  = false;
$modal_data     = [];

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Try Modal/FLUX (skipped when $use_modal = false)
// ═══════════════════════════════════════════════════════════════════════════════
if ($use_modal) {
    $modal_url     = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';
    $modal_payload = json_encode([
        'prompt' => $enhanced_prompt,
        'style'  => 'cinematic',
        'width'  => 768,
        'height' => 1344,
    ]);

    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | calling Modal/FLUX | filename=$image_name\n", FILE_APPEND);

    $ch = curl_init($modal_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $modal_payload,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_POSTREDIR      => CURL_REDIR_POST_ALL,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($modal_payload),
        ],
    ]);

    $modal_response = curl_exec($ch);
    $httpCode       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr        = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal httpCode=$httpCode | curlErr=$curlErr | response=".substr($modal_response??'',0,200)."\n", FILE_APPEND);

    if (!$curlErr && $httpCode === 200) {
        $modal_data = json_decode($modal_response, true) ?? [];
        if (!empty($modal_data['image'])) {
            $imgData = base64_decode($modal_data['image']);
            if ($imgData && file_put_contents($filepath, $imgData) && filesize($filepath) > 1000) {
                $used_generator = 'Modal/FLUX';
                $generation_ok  = true;
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal/FLUX SUCCESS | ".filesize($filepath)." bytes\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal: file write failed or too small\n", FILE_APPEND);
                @unlink($filepath);
            }
        } else {
            $modal_err = $modal_data['error'] ?? $modal_data['detail'] ?? substr($modal_response, 0, 200);
            file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal: no image in response — $modal_err\n", FILE_APPEND);
        }
    } elseif ($httpCode === 429) {
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal: 429 billing limit — skipping to OpenAI\n", FILE_APPEND);
    } else {
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal: failed httpCode=$httpCode curlErr=$curlErr\n", FILE_APPEND);
    }
} else {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal: skipped (use_modal=false)\n", FILE_APPEND);
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 3 — Fallback to OpenAI if Modal failed or was skipped
// ═══════════════════════════════════════════════════════════════════════════════
if (!$generation_ok) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | trying OpenAI fallback\n", FILE_APPEND);

    if (empty($apiKey)) {
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI: no apiKey in config.php\n", FILE_APPEND);
        echo json_encode(['success'=>false, 'message'=>'No OpenAI API key configured']);
        exit;
    }

    // Fresh filename for OpenAI attempt
    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    $image_name = $image_name_base . '.png';
    $filepath   = $image_folder . '/' . $image_name;

    $openai_payload = json_encode([
        'model'   => 'gpt-image-1',
        'prompt'  => $enhanced_prompt,
        'n'       => 1,
        'size'    => '1024x1536',
        'quality' => 'medium',
    ]);

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $openai_payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $openai_response = curl_exec($ch);
    $httpCode        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr         = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI httpCode=$httpCode | curlErr=$curlErr | response=".substr($openai_response??'',0,200)."\n", FILE_APPEND);

    if (!$curlErr && $httpCode === 200) {
        $openai_data = json_decode($openai_response, true) ?? [];
        $b64         = $openai_data['data'][0]['b64_json'] ?? null;
        $url         = $openai_data['data'][0]['url']      ?? null;

        if ($b64) {
            $imgData = base64_decode($b64);
            if ($imgData && file_put_contents($filepath, $imgData) && filesize($filepath) > 1000) {
                $used_generator = 'ChatGPT/OpenAI';
                $generation_ok  = true;
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI SUCCESS (b64) | ".filesize($filepath)." bytes\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI: b64 file write failed\n", FILE_APPEND);
                @unlink($filepath);
            }
        } elseif ($url) {
            $imgData = @file_get_contents($url);
            if ($imgData && file_put_contents($filepath, $imgData) && filesize($filepath) > 1000) {
                $used_generator = 'ChatGPT/OpenAI';
                $generation_ok  = true;
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI SUCCESS (url) | ".filesize($filepath)." bytes\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI: url download failed\n", FILE_APPEND);
            }
        } else {
            $openai_err = $openai_data['error']['message'] ?? substr($openai_response, 0, 300);
            file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI: no image in response — $openai_err\n", FILE_APPEND);
        }
    } else {
        $err_detail = '';
        if ($httpCode !== 200) {
            $d = json_decode($openai_response, true);
            $err_detail = $d['error']['message'] ?? substr($openai_response, 0, 200);
        }
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | OpenAI: failed httpCode=$httpCode curlErr=$curlErr | $err_detail\n", FILE_APPEND);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Both failed
// ═══════════════════════════════════════════════════════════════════════════════
if (!$generation_ok) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | BOTH generators failed\n", FILE_APPEND);
    echo json_encode(['success'=>false, 'message'=>'Both Modal/FLUX and OpenAI failed — check a_errors.log']);
    exit;
}

// Sanity check
if (!file_exists($filepath) || filesize($filepath) < 1000) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | file missing/too small after generation\n", FILE_APPEND);
    echo json_encode(['success'=>false, 'message'=>'Image file missing or corrupt after generation']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 4 — Update DB
// ═══════════════════════════════════════════════════════════════════════════════
if ($scene_id) {
    $esc_name     = mysqli_real_escape_string($conn, $image_name);
    $esc_enhanced = mysqli_real_escape_string($conn, $enhanced_prompt);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);

    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET `$esc_field`            = '$esc_name',
             `$prompt_field`         = '$esc_enhanced',
             `natural_language_tags` = CONCAT(IFNULL(`natural_language_tags`,''), ' $esc_hashtags')
         WHERE id = $scene_id"
    );

    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | DB updated | scene=$scene_id | $esc_field=$image_name | generator=$used_generator\n", FILE_APPEND);
}

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | SUCCESS | $esc_field=$image_name | generator=$used_generator\n", FILE_APPEND);

echo json_encode([
    'success'   => true,
    'filename'  => $image_name,
    'file_url'  => 'podcast_images/' . $image_name,
    'generator' => $used_generator,
    'seed'      => $modal_data['seed'] ?? null,
]);
exit;
?>
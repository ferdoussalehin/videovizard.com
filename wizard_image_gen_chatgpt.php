<?php
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | wizard_image_gen.php HIT\n", FILE_APPEND);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | session OK | admin_id=".($_SESSION['admin_id']??'NOT SET')."\n", FILE_APPEND);

if (!isset($_SESSION['admin_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Not logged in']); exit;
}

include 'dbconnect_hdb.php';
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | dbconnect OK\n", FILE_APPEND);

require_once 'config.php';
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | config OK | apiKey=".substr($apiKey??'MISSING',0,10)."\n", FILE_APPEND);

if (!function_exists('callChatGPT_inam')) {
    require_once 'chatgpt_functions.php';
}
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | chatgpt_functions OK\n", FILE_APPEND);

if (!function_exists('generateAndSaveImage')) {
    require_once 'generate_image_api.php';
}
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | generate_image_api OK\n", FILE_APPEND);

$include_output = ob_get_clean();
if (!empty(trim($include_output))) {
    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | INCLUDE OUTPUT: ".substr($include_output,0,500)."\n", FILE_APPEND);
}

header('Content-Type: application/json');

$scene_id   = (int)($_POST['scene_id']   ?? 0);
$podcast_id = (int)($_POST['podcast_id'] ?? 0);
$prompt     = trim($_POST['prompt']      ?? '');
$img_field  = trim($_POST['image_field'] ?? 'image_file');

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | POST received | scene=$scene_id | field=$img_field | prompt=".substr($prompt,0,60)."\n", FILE_APPEND);

// ── Map image_field → prompt_field ───────────────────────────────────────────
$field_to_prompt = [
    'image_file'   => 'prompt',
    'image_file_1' => 'prompt_1',
    'image_file_2' => 'prompt_2',
    'image_file_3' => 'prompt_3',
    'image_file_4' => 'prompt_4',
];
$allowed_fields = array_keys($field_to_prompt);
$esc_field      = in_array($img_field, $allowed_fields) ? $img_field : 'image_file';
$prompt_field   = $field_to_prompt[$esc_field];

if (!$prompt) {
    echo json_encode(['success'=>false,'message'=>'Empty prompt']); exit;
}

// ── Step 1: Enhance the prompt ───────────────────────────────────────────────
$gpt_prompt = "Enhance this image generation prompt for OpenAI gpt-image-1 model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words. The image should look like a real photograph.

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

$enhanced_prompt = $prompt;
$hashtags = '';

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | calling callChatGPT_inam...\n", FILE_APPEND);
$result = callChatGPT_inam($gpt_prompt);
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | callChatGPT_inam returned: success=".($result['success']??'null')." | response=".substr($result['response']??'',0,100)."\n", FILE_APPEND);

if ($result && !empty($result['success'])) {
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($result['response']));
    $raw = preg_replace('/\s*```$/i', '', $raw);
    $parsed = json_decode(trim($raw), true);
    if ($parsed && isset($parsed['enhanced_prompt'])) {
        $enhanced_prompt = $parsed['enhanced_prompt'];
        $hashtags        = $parsed['hashtags'] ?? '';
    }
}

// ── Step 2: Generate and save the image ──────────────────────────────────────
$image_folder    = __DIR__ . '/podcast_images';
$image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}
$image_name = $image_name_base . '.png';

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | calling generateAndSaveImage | image=$image_name | enhanced_prompt=".substr($enhanced_prompt,0,80)."\n", FILE_APPEND);
$gen = generateAndSaveImage($enhanced_prompt, $image_name_base, "1024x1536", $image_folder, $apiKey);
file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | generateAndSaveImage returned: ".json_encode($gen)."\n", FILE_APPEND);

if (!$gen['success']) {
    echo json_encode(['success'=>false,'message'=>$gen['message']]); exit;
}

if (!file_exists($gen['filepath']) || filesize($gen['filepath']) < 1000) {
    echo json_encode(['success'=>false,'message'=>'Image file missing or too small: '.$gen['filepath']]); exit;
}

// ── Step 3: Save image filename AND enhanced prompt to correct columns ────────
if ($scene_id) {
    $esc_name     = mysqli_real_escape_string($conn, $image_name);
    $esc_enhanced = mysqli_real_escape_string($conn, $enhanced_prompt);

    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET $esc_field    = '$esc_name',
             $prompt_field = '$esc_enhanced'
         WHERE id = $scene_id"
    );

    file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | DB updated | scene=$scene_id | $esc_field=$image_name\n", FILE_APPEND);
}

file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | SUCCESS | field=$esc_field | filename=$image_name\n", FILE_APPEND);

echo json_encode(['success'=>true, 'filename'=>$image_name]);
exit;

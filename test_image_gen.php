<?php
// ═══════════════════════════════════════════════════════════════════════════════
// test_image_gen.php  —  Standalone image generation test
// Tests: Scene 1, Image 1/1 → Modal/FLUX first, ChatGPT/OpenAI fallback
//
// Usage:  php test_image_gen.php
//    or:  php test_image_gen.php "your custom prompt here"
//
// Place in your web root (same folder as config.php, chatgpt_functions.php)
// Can also run via browser: https://yoursite.com/test_image_gen.php
// ═══════════════════════════════════════════════════════════════════════════════

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

// ── Detect CLI vs browser ─────────────────────────────────────────────────────
$is_cli = (php_sapi_name() === 'cli');

function out(string $msg, string $type = 'info'): void {
    global $is_cli;
    $icons = ['info'=>'ℹ', 'ok'=>'✅', 'warn'=>'⚠', 'error'=>'❌', 'step'=>'▶', 'done'=>'🎉'];
    $icon  = $icons[$type] ?? '·';
    if ($is_cli) {
        echo $icon . ' ' . $msg . PHP_EOL;
    } else {
        $colors = ['info'=>'#7dd3fc','ok'=>'#5fd1ff','warn'=>'#fde68a','error'=>'#fca5a5','step'=>'#c4b5fd','done'=>'#6ee7b7'];
        $color  = $colors[$type] ?? '#e2e8f0';
        echo '<p style="font-family:monospace;font-size:13px;margin:2px 0;color:'.$color.';">'
            . htmlspecialchars($icon . ' ' . $msg) . '</p>' . PHP_EOL;
        ob_flush(); flush();
    }
}

// ── Browser wrapper ───────────────────────────────────────────────────────────
if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Image Gen Test</title>
    <style>
      body { background:#0f2a44; padding:30px; font-family:monospace; }
      .log { background:#0a1e33; border-radius:10px; padding:20px; max-width:860px; margin:0 auto; }
      h2 { color:#5fd1ff; margin:0 0 20px; }
      img.result { max-width:400px; border-radius:8px; border:2px solid #5fd1ff; margin-top:20px; display:block; }
    </style></head><body><div class="log"><h2>🖼 Image Generation Test</h2>';
    ob_flush(); flush();
}

// ─────────────────────────────────────────────────────────────────────────────
// CONFIG — edit these if needed
// ─────────────────────────────────────────────────────────────────────────────
$TEST_PROMPT = isset($argv[1])
    ? $argv[1]
    : 'A confident physiotherapist in a clean modern clinic, guiding a patient through a shoulder exercise. Bright natural window light, professional attire, focused expression.';

$MODAL_API_URL  = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';
$IMAGE_DIR      = __DIR__ . '/podcast_images/';
$IMAGE_WIDTH    = 768;
$IMAGE_HEIGHT   = 1344;

// ─────────────────────────────────────────────────────────────────────────────
// LOAD DEPENDENCIES
// ─────────────────────────────────────────────────────────────────────────────
out('Loading config and functions…', 'step');

if (!file_exists(__DIR__ . '/config.php')) {
    out('config.php not found — make sure this file is in your web root', 'error');
    if (!$is_cli) echo '</div></body></html>';
    exit(1);
}
require_once __DIR__ . '/config.php';
out('config.php loaded | apiKey prefix: ' . substr($apiKey ?? 'MISSING', 0, 8) . '…', 'info');

if (!function_exists('callChatGPT_inam')) {
    if (!file_exists(__DIR__ . '/chatgpt_functions.php')) {
        out('chatgpt_functions.php not found', 'error');
        if (!$is_cli) echo '</div></body></html>';
        exit(1);
    }
    require_once __DIR__ . '/chatgpt_functions.php';
}
out('chatgpt_functions.php loaded', 'info');

// ─────────────────────────────────────────────────────────────────────────────
// STEP 1 — Enhance prompt via ChatGPT
// ─────────────────────────────────────────────────────────────────────────────
out('', 'info');
out('STEP 1: Enhancing prompt via ChatGPT…', 'step');
out('Original prompt: ' . substr($TEST_PROMPT, 0, 100) . '…', 'info');

$gpt_prompt = "Enhance this image generation prompt for a FLUX photorealistic model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words.

STRICT COLOR & LIGHTING RULES:
- Use cool-to-neutral white lighting (5500K-6500K daylight), never warm yellow/orange tones
- Include: soft natural daylight, vibrant true-to-life colors, no warm cast, color accurate
- Camera: 35mm lens, shallow depth of field, photojournalistic style
- Always end with: Shot on Sony A7R, natural daylight, color accurate, no warm cast, photorealistic

PEOPLE & APPEARANCE RULES (if the prompt includes a person):
- North American or Northern European appearance (fair skin, light complexion, Caucasian)
- Hair: blonde, brown, auburn, light brown or chestnut (not black unless specified)
- Features: light eyes (blue, green, grey or hazel) or brown eyes with fair skin
- Style: clean modern Western wardrobe, business casual or smart casual
- Age range 28-55 unless specified otherwise

Original prompt:
{$TEST_PROMPT}

Return ONLY valid JSON, no markdown, no code fences:
{\"enhanced_prompt\": \"...\", \"hashtags\": \"emotionperson1,emotionperson2\"}";

$enhanced_prompt = $TEST_PROMPT; // fallback to original if GPT fails

$gpt_result = callChatGPT_inam($gpt_prompt);

if ($gpt_result && !empty($gpt_result['success'])) {
    $raw    = preg_replace('/^```(?:json)?\s*/i', '', trim($gpt_result['response']));
    $raw    = preg_replace('/\s*```$/i', '', $raw);
    $parsed = json_decode(trim($raw), true);
    if ($parsed && isset($parsed['enhanced_prompt'])) {
        $enhanced_prompt = $parsed['enhanced_prompt'];
        out('Prompt enhanced successfully', 'ok');
        out('Enhanced: ' . substr($enhanced_prompt, 0, 120) . '…', 'info');
    } else {
        out('GPT returned unparseable JSON — using original prompt', 'warn');
        out('Raw response: ' . substr($gpt_result['response'] ?? '', 0, 200), 'warn');
    }
} else {
    out('ChatGPT enhancement failed — using original prompt', 'warn');
    out('Error: ' . ($gpt_result['error'] ?? 'unknown'), 'warn');
}

// ─────────────────────────────────────────────────────────────────────────────
// Prepare output directory + unique filename
// ─────────────────────────────────────────────────────────────────────────────
if (!is_dir($IMAGE_DIR)) {
    if (!mkdir($IMAGE_DIR, 0755, true)) {
        out('Could not create podcast_images/ directory', 'error');
        if (!$is_cli) echo '</div></body></html>';
        exit(1);
    }
}

do {
    $image_name = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT) . '.png';
} while (file_exists($IMAGE_DIR . $image_name));

$image_path = $IMAGE_DIR . $image_name;

// ─────────────────────────────────────────────────────────────────────────────
// STEP 2a — Try Modal/FLUX
// ─────────────────────────────────────────────────────────────────────────────
out('', 'info');
out('STEP 2: Generating image…', 'step');
out('Scene 1, Image 1/1 — trying Modal/FLUX…', 'step');

$used_generator = '';
$generation_ok  = false;

$payload = json_encode([
    'prompt' => $enhanced_prompt,
    'style'  => 'cinematic',
    'width'  => $IMAGE_WIDTH,
    'height' => $IMAGE_HEIGHT,
]);

$ch = curl_init($MODAL_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);

$t_start  = microtime(true);
$response = curl_exec($ch);
$elapsed  = round(microtime(true) - $t_start, 1);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    out("Modal/FLUX cURL error: {$curl_err}", 'warn');
} else {
    $data = json_decode($response, true);
    if (isset($data['image'])) {
        $image_data = base64_decode($data['image']);
        if (file_put_contents($image_path, $image_data) && filesize($image_path) > 1000) {
            $used_generator = 'Modal/FLUX';
            $generation_ok  = true;
            out("Modal/FLUX SUCCESS in {$elapsed}s → {$image_name}", 'ok');
        } else {
            out('Modal/FLUX: file write failed or file too small', 'warn');
            @unlink($image_path);
        }
    } else {
        $modal_error = $data['error'] ?? $data['detail'] ?? substr($response, 0, 200);
        out("Modal/FLUX failed: {$modal_error}", 'warn');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 2b — ChatGPT/OpenAI fallback
// ─────────────────────────────────────────────────────────────────────────────
if (!$generation_ok) {
    out('Falling back to ChatGPT/OpenAI (gpt-image-1)…', 'step');

    // Rebuild a fresh filename in case previous attempt created a partial file
    do {
        $image_name = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT) . '.png';
    } while (file_exists($IMAGE_DIR . $image_name));
    $image_path = $IMAGE_DIR . $image_name;

    // Check config.php has $apiKey
    if (empty($apiKey)) {
        out('$apiKey is empty in config.php — cannot call OpenAI', 'error');
    } else {
        $openai_payload = json_encode([
            'model'   => 'gpt-image-1',
            'prompt'  => $enhanced_prompt,
            'n'       => 1,
            'size'    => '1024x1792',   // closest portrait to 768×1344
            'quality' => 'standard',
        ]);

        $t_start = microtime(true);
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
        $response = curl_exec($ch);
        $elapsed  = round(microtime(true) - $t_start, 1);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            out("OpenAI cURL error: {$curl_err}", 'error');
        } else {
            $data = json_decode($response, true);

            // gpt-image-1 returns b64_json by default
            $b64 = $data['data'][0]['b64_json'] ?? null;
            $url = $data['data'][0]['url']      ?? null;

            if ($b64) {
                $image_data = base64_decode($b64);
                if (file_put_contents($image_path, $image_data) && filesize($image_path) > 1000) {
                    $used_generator = 'ChatGPT/OpenAI';
                    $generation_ok  = true;
                    out("ChatGPT/OpenAI SUCCESS (b64) in {$elapsed}s → {$image_name}", 'ok');
                } else {
                    out('OpenAI: file write failed or file too small', 'error');
                    @unlink($image_path);
                }
            } elseif ($url) {
                // If URL returned, download it
                $img_data = file_get_contents($url);
                if ($img_data && file_put_contents($image_path, $img_data) && filesize($image_path) > 1000) {
                    $used_generator = 'ChatGPT/OpenAI';
                    $generation_ok  = true;
                    out("ChatGPT/OpenAI SUCCESS (url) in {$elapsed}s → {$image_name}", 'ok');
                } else {
                    out('OpenAI: could not download image from URL', 'error');
                }
            } else {
                $openai_error = $data['error']['message'] ?? substr($response, 0, 300);
                out("ChatGPT/OpenAI failed: {$openai_error}", 'error');
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RESULT
// ─────────────────────────────────────────────────────────────────────────────
out('', 'info');
if ($generation_ok) {
    $size_kb = round(filesize($image_path) / 1024);
    out("DONE — {$image_name} ({$size_kb} KB) [generated by {$used_generator}]", 'done');
    out("Saved to: {$image_path}", 'info');
    out("Web URL:  podcast_images/{$image_name}", 'info');

    if (!$is_cli) {
        $web_url = 'podcast_images/' . htmlspecialchars($image_name);
        echo '<img class="result" src="' . $web_url . '" alt="Generated image"><br>';
        echo '<p style="color:#5fd1ff;font-family:monospace;margin-top:10px;">✅ <strong>Generated by ' . htmlspecialchars($used_generator) . '</strong> — <a href="' . $web_url . '" style="color:#5fd1ff;" target="_blank">' . $web_url . '</a></p>';
    }
} else {
    out('FAILED — both Modal/FLUX and ChatGPT/OpenAI failed to generate the image', 'error');
    out('Check a_errors.log and the output above for details', 'warn');
}

if (!$is_cli) {
    echo '</div></body></html>';
}
exit($generation_ok ? 0 : 1);
?>

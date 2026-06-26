<?php
// Buffer ALL output from the very start — nothing must escape before we decide what to send
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0); // silence everything; errors go to log only, never to output

session_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
if (!isset($_SESSION['admin_id'])) {
    ob_end_clean();
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

require_once 'config.php';
require_once __DIR__ . '/videovizard_general_functions.php';


// For every AJAX call: discard anything config.php may have printed, then take over
$_isAjax = !empty($_POST['action']) || !empty($_POST['fashn_action']) || !empty($_POST['crop_action']);
if ($_isAjax) {
    ob_end_clean();  // throw away all buffered output so far
    ob_start();      // fresh buffer — only our json_encode() will be in it
}

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// Support multiple API key variable names from config.php
$apiKey    = $apiKey    ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;
$falApiKey = $falApiKey ?? null;

$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';
$FAL_LTX_VIDEO_URL = 'https://fal.run/fal-ai/ltx-2.3/text-to-video';
$FAL_LTX_IMAGE_VIDEO_URL = 'https://fal.run/fal-ai/ltx-2.3/image-to-video';
$FAL_LTX_RETAKE_VIDEO_URL = 'https://fal.run/fal-ai/ltx-2.3/retake-video';
$FAL_FLUX_I2I_URL = 'https://fal.run/fal-ai/flux/dev/image-to-image';
$FAL_FASHN_TRYON_URL = 'https://fal.run/fal-ai/fashn/tryon/v1.6';
$FAL_NANOBANANA_EDIT_URL = 'https://fal.run/fal-ai/nano-banana-2/edit'; // semantic edit, no manual mask — used for Mannequin → Model
$FAL_BIREFNET_URL = 'https://fal.run/fal-ai/birefnet/v2'; // background removal — used for the optional "remove background" chain step
if (!defined('VV_SITE_BASE_URL')) define('VV_SITE_BASE_URL', 'https://videovizard.com');

// ── Shrink + recompress until the file is safely under a byte-size target ───
// A modest 1800x2200 photo can still be several MB as a high-quality
// JPEG/PNG. That's what was hitting the host's own request-body ceiling
// when base64-encoding a real photo for the fal_proxy.php upload — nothing
// to do with fal.ai or this script's logic, just the request body being too
// big before PHP even ran. This re-encodes through GD regardless of current
// dimensions and iterates size/quality down until the file actually fits.
function vv_shrink_to_target_size($path, $target_bytes = 700000) {
    $info = @getimagesize($path);
    if (!$info) return $path;
    [$w, $h] = $info;

    $src = @imagecreatefromstring(@file_get_contents($path));
    if (!$src) return $path;

    $max_dim   = max($w, $h);
    $quality   = 82;
    $best_path = null;

    for ($i = 0; $i < 6; $i++) {
        $scale = min(1, $max_dim / max($w, $h));
        $new_w = max(1, (int)round($w * $scale));
        $new_h = max(1, (int)round($h * $scale));
        $dst   = imagecreatetruecolor($new_w, $new_h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

        $tmp_path2 = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
        imagejpeg($dst, $tmp_path2, $quality);
        imagedestroy($dst);

        if ($best_path !== null) @unlink($best_path);
        $best_path = $tmp_path2;

        $size = @filesize($tmp_path2);
        if ($size !== false && $size <= $target_bytes) break;

        $max_dim = (int)round($max_dim * 0.8);
        $quality = max(50, $quality - 8);
    }

    imagedestroy($src);
    return $best_path;
}

// ── AJAX: enhance prompt ──────────────────────────────────────────────────────
function enhancePrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert image prompt engineer. Enhance the following prompt for a photorealistic image generation model.

IMPORTANT RULES:
- Use cool-neutral daylight lighting (5500K-6500K), never warm/yellow tones
- Add: 'soft natural daylight', 'cool white studio lighting', 'vibrant true-to-life colors'
- Camera: 35mm lens, shallow depth of field
- End with: 'photorealistic, sharp focus, no warm cast'
- Keep under 150 words

Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this prompt: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 300
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return $prompt;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// ── AJAX: enhance prompt for text-to-video (LTX 2.3, FAL or Modal) ───────────
function enhanceVideoPrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert video prompt engineer for the LTX 2.3 text-to-video model. Enhance the user's prompt to describe a single continuous " . T2V_MAX_DURATION . "-second cinematic shot — be specific about camera movement, lighting, and motion. Keep it under 100 words. Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this prompt: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 250
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return $prompt;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// ── AJAX: enhance prompt for image-to-video — biased toward fidelity ────────
// Unlike text-to-video (which is generating a scene from nothing), image-to-
// video is animating a SPECIFIC source image, so the system prompt explicitly
// tells the model to only describe added motion and to leave the subject's
// likeness, skin tone, lighting, and framing alone — this is the fix for
// image-to-video drifting in brightness/skin tone/crop between source and
// output.
function enhanceImageVideoPrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert prompt engineer for the LTX 2.3 image-to-video model. The model is animating an existing photo, not generating a new scene, so your job is to describe ONLY the motion to add (camera movement, hair/fabric movement, blinking, subtle ambient motion) — never redescribe the subject's face, skin tone, outfit, lighting, or framing, since restating those tends to make the model drift away from the source photo instead of preserving it. Always end the enhanced prompt with this exact instruction: 'Keep the subject's face, skin tone, outfit, lighting, color grading, and framing identical to the source image; do not zoom, crop, or recompose the shot.' Keep the whole thing under 100 words. Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this motion prompt: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 250
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return $prompt;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// Fixed fidelity-preservation clause appended to EVERY image-to-video prompt,
// whether or not GPT enhancement is turned on — guarantees the instruction is
// always present, not just when enhance is checked.
function appendImageFidelityClause($prompt) {
    return rtrim($prompt, ". ") . ". Keep the subject's face, skin tone, outfit, lighting, color grading, and framing identical to the source image; do not zoom, crop, or recompose the shot.";
}

// Fixed fidelity-preservation clause for the Image-to-Image tool specifically.
// This use case is enhancing/restyling existing photos of a model (style,
// lighting, makeup, background, jewelry, fabric texture, realism) — so unlike
// the video-tools clause, it explicitly ALLOWS those edits while still
// pinning down the model's actual identity (face, body, pose) and the shot's
// composition, which is what tends to drift otherwise.
function appendImageToImageFidelityClause($prompt) {
    return rtrim($prompt, ". ") . ". Keep the same model — identical face, facial features, body shape, skin tone, and pose — and keep the same composition and camera framing as the source image. Do not change who the model is.";
}

// ── AJAX: enhance prompt for image-to-image — biased toward fidelity ────────
// Tuned for the actual use case: enhancing/restyling photos of a model —
// style, lighting, makeup, backgrounds, jewelry, fabric textures, realism —
// while keeping the model's identity and composition untouched.
function enhanceImageToImagePrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert prompt engineer for the FLUX.1 [dev] image-to-image model, used here to enhance and restyle photos of a model — changing things like style, lighting, makeup, backgrounds, jewelry, and fabric textures, and improving overall realism. The model is editing an existing photo, not generating a new scene, so describe ONLY the requested change. Never redescribe the model's face, facial features, body shape, skin tone, pose, or the camera framing/composition — restating those tends to make the model drift away from the source photo (a different-looking person, a recomposed shot) instead of preserving it. If the user's instruction doesn't mention a category (style/lighting/makeup/background/jewelry/fabric/realism), don't introduce changes to it. Keep it under 100 words. Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this edit instruction: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 250
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return $prompt;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// Fixed fidelity-preservation clause for Video-to-Video, mirroring the i2i
// one — this tool enhances/restyles existing footage of a model (style,
// lighting, makeup, background, jewelry, fabric texture, realism), so it
// explicitly allows those edits while pinning down the model's identity and
// the shot's motion/composition.
function appendVideoToVideoFidelityClause($prompt) {
    return rtrim($prompt, ". ") . ". Keep the same model — identical face, facial features, body shape, skin tone, and pose — and keep the same composition, camera framing, and motion as the source footage. Do not change who the model is.";
}

// ── AJAX: enhance prompt for video-to-video — biased toward fidelity ────────
// Tuned for the same use case as image-to-image but for footage: restyling
// style, lighting, makeup, backgrounds, jewelry, fabric textures, realism —
// while keeping the model's identity and the shot's motion untouched.
function enhanceVideoToVideoPrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert prompt engineer for the LTX 2.3 retake-video model, used here to enhance and restyle footage of a model — changing things like style, lighting, makeup, backgrounds, jewelry, and fabric textures, and improving overall realism. The model is editing existing footage, not generating a new scene, so describe ONLY the requested change. Never redescribe the model's face, facial features, body shape, skin tone, pose, the camera framing/composition, or the existing motion — restating those tends to make the model drift away from the source footage (a different-looking person, a recomposed shot) instead of preserving it. If the user's instruction doesn't mention a category (style/lighting/makeup/background/jewelry/fabric/realism), don't introduce changes to it. Keep it under 100 words. Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this edit instruction: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 250
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return $prompt;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// ── AJAX: generate image via Modal/FLUX ──────────────────────────────────────
function generateWithModal($prompt, $admin_id, $company_id, $maxRetries = 2) {
    global $MODAL_URL;
    $payload = json_encode([
        'prompt' => $prompt,
        'style'  => 'cinematic',
        'width'  => 768,
        'height' => 1344
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
                'Content-Length: ' . strlen($payload)
            ]
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['image'])) {
                $imageData = base64_decode($data['image']);
                $filename  = 'modal_' . time() . '_' . mt_rand(1000, 9999) . '.png';
                $filePath  = $saveDir . $filename;
                $written   = file_put_contents($filePath, $imageData);
                if ($written !== false) {
                    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host      = $_SERVER['HTTP_HOST'];
                    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                    $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                    return [
                        'success'    => true,
                        'filename'   => $filename,
                        'filepath'   => $publicPath . $filename,
                        'public_url' => $publicUrl,
                        'source'     => 'Modal/FLUX',
                        'seed'       => $data['seed'] ?? null,
                        'duration'   => $duration,
                        'debug'      => ['save_dir' => $saveDir, 'write_result' => $written, 'dir_writable' => is_writable($saveDir)],
                    ];
                }
            }
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    return ['success' => false, 'error' => 'Modal API failed after ' . $maxRetries . ' attempts. The server may be cold-starting — try again in a moment.'];
}


// ── AJAX: generate image via FAL AI (flux/dev) ────────────────────────────────
function generateWithFal($prompt, $imageSize, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    // Map friendly size key to fal.ai image_size values
    $sizeMap = [
        '9:16'  => ['width' => 768,  'height' => 1344],
        '16:9'  => ['width' => 1344, 'height' => 768],
        '1:1'   => ['width' => 1024, 'height' => 1024],
        '4:5'   => ['width' => 896,  'height' => 1120],
        '3:4'   => ['width' => 896,  'height' => 1152],
    ];
    $dims = $sizeMap[$imageSize] ?? $sizeMap['9:16'];

    $payload = json_encode([
        'prompt'               => $prompt,
        'image_size'           => ['width' => $dims['width'], 'height' => $dims['height']],
        'num_images'           => 1,
        'sync_mode'            => true,
        'guidance_scale'       => 3.5,
        'num_inference_steps'  => 28,
        'enable_safety_checker'=> false,
    ]);

    $httpCode = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if ($imageUrl) {
                // ── Get image bytes: FAL may return either a real CDN URL or an inline
                // ── data: URI (this happens with sync_mode => true). A data: URI is not
                // ── something cURL can fetch as an HTTP request, so decode it directly.
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $dataUriMatch);
                if ($isDataUri) {
                    $imageData = base64_decode($dataUriMatch[1]);
                    $dlCode    = ($imageData !== false && strlen($imageData) > 0) ? 200 : 0;
                    $dlErr     = ($imageData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    // ── Download image from FAL CDN ───────────────────────────────
                    $dch = curl_init($imageUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 60,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $imageData = curl_exec($dch);
                    $dlCode    = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr     = curl_error($dch);
                    curl_close($dch);
                }

                // ── Build target directory ────────────────────────────────────
                $folderName  = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
                $saveDir     = __DIR__ . '/user_media/' . $folderName . '/images/';
                $publicPath  = 'user_media/' . $folderName . '/images/';

                // Collect diagnostic info for the response
                $debug = [
                    'fal_cdn_http'    => $dlCode,
                    'fal_cdn_err'     => $dlErr ?: null,
                    'image_bytes'     => $imageData !== false ? strlen($imageData) : 0,
                    'save_dir'        => $saveDir,
                    'dir_exists'      => is_dir($saveDir),
                    'dir_writable'    => is_dir($saveDir) ? is_writable($saveDir) : null,
                    'php_user'        => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : get_current_user(),
                ];

                // Create directory if missing
                if (!is_dir($saveDir)) {
                    $mkOk = mkdir($saveDir, 0755, true);
                    $debug['mkdir_result']  = $mkOk;
                    $debug['mkdir_error']   = $mkOk ? null : error_get_last()['message'] ?? 'unknown';
                    $debug['dir_exists']    = is_dir($saveDir);
                    $debug['dir_writable']  = is_dir($saveDir) ? is_writable($saveDir) : false;
                }

                if ($imageData !== false && $dlCode === 200 && strlen($imageData) > 0) {
                    $filename = 'fal_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $imageData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;
                    $debug['write_error']  = $written === false ? (error_get_last()['message'] ?? 'unknown') : null;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $isDataUri ? null : $imageUrl,
                            'source'     => 'FAL AI / FLUX Dev',
                            'seed'       => $data['seed'] ?? null,
                            'duration'   => $duration,
                            'size'       => $imageSize,
                            'debug'      => $debug,
                        ];
                    }
                    // Write failed — return debug so UI can display the exact reason
                    return [
                        'success' => false,
                        'error'   => '❌ Image downloaded (' . strlen($imageData) . ' bytes) but file_put_contents failed. See debug for details.',
                        'fal_url' => $isDataUri ? null : $imageUrl,
                        'debug'   => $debug,
                    ];
                }

                // CDN download / data-URI decode failed.
                // For a real CDN URL we can still hand the link straight to the browser.
                // For a data: URI there is nothing usable to fall back to (and we must
                // never echo the raw base64 blob back as a "URL"), so report an error.
                if ($isDataUri) {
                    return [
                        'success' => false,
                        'error'   => 'FAL AI returned an inline image but it could not be decoded/saved. See debug for details.',
                        'debug'   => $debug,
                    ];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_' . time() . '.jpg',
                    'filepath'   => null,
                    'public_url' => $imageUrl,
                    'fal_url'    => $imageUrl,
                    'source'     => 'FAL AI / FLUX Dev (CDN — local save skipped)',
                    'seed'       => $data['seed'] ?? null,
                    'duration'   => $duration,
                    'size'       => $imageSize,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no image URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI API failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: generate image via FAL AI (FLUX Dev image-to-image) — instant ─────
// $imageAbsPath is a local file path — base64-encoded into a data: URI, same
// approach used for the video tools' image/video inputs (fal explicitly
// supports this, no separate fal.storage upload round-trip needed).
function generateImageToImageWithFal($imageAbsPath, $prompt, $strength, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_FLUX_I2I_URL;

    $imageBytes = @file_get_contents($imageAbsPath);
    if ($imageBytes === false) {
        return ['success' => false, 'error' => 'Could not read the uploaded image'];
    }
    $mime = function_exists('mime_content_type') ? @mime_content_type($imageAbsPath) : null;
    if (!$mime) {
        $ext  = strtolower(pathinfo($imageAbsPath, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
    }
    $imageDataUri = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);

    $payload = json_encode([
        'image_url'             => $imageDataUri,
        'prompt'                => $prompt,
        'strength'               => $strength, // 0.01–1.0; lower = closer to source, higher = more transformed
        'sync_mode'              => true,
        'num_inference_steps'    => 40,
        'guidance_scale'         => 3.5,
        'enable_safety_checker'  => false,
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_FLUX_I2I_URL);
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
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if ($imageUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $dataUriMatch);
                if ($isDataUri) {
                    $outData = base64_decode($dataUriMatch[1]);
                    $dlCode  = ($outData !== false && strlen($outData) > 0) ? 200 : 0;
                    $dlErr   = ($outData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($imageUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 60,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $outData = curl_exec($dch);
                    $dlCode  = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr   = curl_error($dch);
                    curl_close($dch);
                }

                $debug = ['fal_cdn_http' => $dlCode, 'fal_cdn_err' => $dlErr ?: null, 'image_bytes' => $outData !== false ? strlen($outData) : 0, 'save_dir' => $saveDir];

                if ($outData !== false && $dlCode === 200 && strlen($outData) > 0) {
                    $filename = 'fal_i2i_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $outData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $isDataUri ? null : $imageUrl,
                            'source'     => 'FAL AI / FLUX Dev (image-to-image)',
                            'seed'       => $data['seed'] ?? null,
                            'duration'   => $duration,
                            'strength'   => $strength,
                            'debug'      => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Image downloaded but file_put_contents failed.', 'debug' => $debug];
                }

                if ($isDataUri) {
                    return ['success' => false, 'error' => 'FAL AI returned an inline image but it could not be decoded/saved.', 'debug' => $debug];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_i2i_' . time() . '.jpg',
                    'filepath'   => null,
                    'public_url' => $imageUrl,
                    'fal_url'    => $imageUrl,
                    'source'     => 'FAL AI / FLUX Dev (CDN — local save skipped)',
                    'duration'   => $duration,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no image URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (FLUX image-to-image) failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: fashion try-on via FAL AI (fashn/tryon v1.6) ──────────────────────
// Composites a garment photo onto a model photo. `category` MUST match the
// garment's actual shape ('one-pieces' | 'tops' | 'bottoms') — this is
// exactly the parameter that was causing a full one-piece dress to render
// like a cropped blouse in vizard_scriptgen_3.php when it ended up passed
// through as 'tops'. There's no auto-detection here on purpose: the person
// picks Garment Type explicitly every time (see the panel UI), instead of it
// silently inheriting a wrong default.
//
// IMPORTANT: unlike FLUX (a native FAL model, which happily takes inline
// base64 data URIs), fashn/tryon is a third-party model wrapped by FAL and
// does NOT reliably run the actual try-on against a data URI — it can come
// back HTTP 200 with an image that's just the input model photo, no garment
// applied, no error. The only path that's proven to actually work is the one
// vizard_scriptgen_3.php already uses: upload both images to fal's own
// storage via fal_proxy.php first, then pass the real hosted URLs it returns.
function generateFashionTryonWithFal($modelImageAbsPath, $garmentImageAbsPath, $category, $garmentPhotoType, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_FASHN_TRYON_URL;

    $proxy_url   = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $uploadToFal = function ($path) use ($proxy_url) {
        $ch = curl_init($proxy_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'base64'    => base64_encode(file_get_contents($path)),
                'mime_type' => mime_content_type($path),
                'file_name' => basename($path),
            ]),
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $url = json_decode($res, true)['file_url'] ?? null;
        return [$url, $http, $err, $res];
    };

    $model_upload_path = vv_shrink_to_target_size($modelImageAbsPath);
    [$model_url, $mh, $merr, $mres] = $uploadToFal($model_upload_path);
    if ($model_upload_path !== $modelImageAbsPath) @unlink($model_upload_path);
    if (!$model_url) {
        return ['success' => false, 'error' => "Failed to upload model photo to fal.ai (HTTP $mh" . ($merr ? ", curl: $merr" : '') . ')', 'debug' => ['raw' => substr((string)$mres, 0, 300)]];
    }

    $garment_upload_path = vv_shrink_to_target_size($garmentImageAbsPath);
    [$garment_url, $gh, $gerr, $gres] = $uploadToFal($garment_upload_path);
    if ($garment_upload_path !== $garmentImageAbsPath) @unlink($garment_upload_path);
    if (!$garment_url) {
        return ['success' => false, 'error' => "Failed to upload garment photo to fal.ai (HTTP $gh" . ($gerr ? ", curl: $gerr" : '') . ')', 'debug' => ['raw' => substr((string)$gres, 0, 300)]];
    }

    $payload = json_encode([
        'model_image'        => $model_url,
        'garment_image'      => $garment_url,
        'category'           => $category,            // 'one-pieces' | 'tops' | 'bottoms'
        'mode'               => 'quality',
        'garment_photo_type' => $garmentPhotoType,     // 'flat-lay' | 'model'
        'sync_mode'          => true,
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    $curlError = '';
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_FASHN_TRYON_URL);
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
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if ($imageUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $dataUriMatch);
                if ($isDataUri) {
                    $outData = base64_decode($dataUriMatch[1]);
                    $dlCode  = ($outData !== false && strlen($outData) > 0) ? 200 : 0;
                    $dlErr   = ($outData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($imageUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 60,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $outData = curl_exec($dch);
                    $dlCode  = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr   = curl_error($dch);
                    curl_close($dch);
                }

                $debug = ['fal_cdn_http' => $dlCode, 'fal_cdn_err' => $dlErr ?: null, 'image_bytes' => $outData !== false ? strlen($outData) : 0, 'save_dir' => $saveDir];

                if ($outData !== false && $dlCode === 200 && strlen($outData) > 0) {
                    $filename = 'fal_fashn_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $outData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'            => true,
                            'filename'           => $filename,
                            'filepath'           => $publicPath . $filename,
                            'public_url'         => $publicUrl,
                            'fal_url'            => $isDataUri ? null : $imageUrl,
                            'source'             => 'FAL AI / fashn/tryon v1.6',
                            'category'           => $category,
                            'garment_photo_type' => $garmentPhotoType,
                            'duration'           => $duration,
                            'debug'              => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Image downloaded but file_put_contents failed.', 'debug' => $debug];
                }

                if ($isDataUri) {
                    return ['success' => false, 'error' => 'FAL AI returned an inline image but it could not be decoded/saved.', 'debug' => $debug];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_fashn_' . time() . '.jpg',
                    'filepath'   => null,
                    'public_url' => $imageUrl,
                    'fal_url'    => $imageUrl,
                    'source'     => 'FAL AI / fashn/tryon v1.6 (CDN — local save skipped)',
                    'duration'   => $duration,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no image URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (fashn/tryon) failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: mannequin → model via FAL AI (nano-banana-2/edit) ────────────────
// Unlike generateFashionTryonWithFal (which regenerates the garment onto a
// DIFFERENT photo from scratch — risky for complex/asymmetric/multi-layer
// garments), this keeps the ORIGINAL mannequin/product photo as the base and
// asks a semantic image editor to replace only the mannequin/body with a
// realistic human model. The garment pixels — embroidery, color-blocking,
// drape, hem — are never regenerated, only the masked-by-instruction region
// (head, neck, visible skin) is. No manual mask required: nano-banana-2/edit
// reasons about what to change vs. preserve from the prompt text alone.
// Same upload-via-fal_proxy approach as fashn, for the same reliability reason
// (wrapped third-party-style model, inline data URIs aren't reliable here).
//
// COST NOTE: default resolution is 1K ($0.08/image), not 2K ($0.12/image) —
// 2K is available via $resolution param if a specific generation needs the
// extra detail, but most product shots don't need it as the default.
//
// RETRY NOTE: this is the fix for the double-billing issue — a connect-phase
// failure (DNS, refused connection, timeout before any bytes came back) means
// FAL never got the request, so retrying is safe. But once cURL has actually
// reached FAL (any HTTP status code at all, even an error one), a retry on
// timeout risks re-running — and re-billing — a generation that may have
// already completed server-side while we were still waiting on the response.
// So: only retry when $httpCode === 0 (nothing came back at all). A real
// HTTP error code (4xx/5xx) or empty 200 still fails immediately, no retry.
function generateMannequinToModelWithFal($sourceImageAbsPath, $prompt, $falApiKey, $admin_id, $company_id, $resolution = '1K', $maxRetries = 2) {
    global $FAL_NANOBANANA_EDIT_URL;

    if (!in_array($resolution, ['0.5K', '1K', '2K', '4K'], true)) $resolution = '1K';

    $proxy_url   = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $uploadToFal = function ($path) use ($proxy_url) {
        $ch = curl_init($proxy_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'base64'    => base64_encode(file_get_contents($path)),
                'mime_type' => mime_content_type($path),
                'file_name' => basename($path),
            ]),
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $url = json_decode($res, true)['file_url'] ?? null;
        return [$url, $http, $err, $res];
    };

    $upload_path = vv_shrink_to_target_size($sourceImageAbsPath);
    [$source_url, $uh, $uerr, $ures] = $uploadToFal($upload_path);
    if ($upload_path !== $sourceImageAbsPath) @unlink($upload_path);
    if (!$source_url) {
        return ['success' => false, 'error' => "Failed to upload source photo to fal.ai (HTTP $uh" . ($uerr ? ", curl: $uerr" : '') . ')', 'debug' => ['raw' => substr((string)$ures, 0, 300)]];
    }

    $payload = json_encode([
        'prompt'      => $prompt,
        'image_urls'  => [$source_url],
        'aspect_ratio' => 'auto',
        'resolution'  => $resolution,
        'num_images'  => 1,
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    $curlError = '';
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_NANOBANANA_EDIT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 180, // was 120 — 2K/4K edits can legitimately take longer than that
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if ($imageUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $dataUriMatch);
                if ($isDataUri) {
                    $outData = base64_decode($dataUriMatch[1]);
                    $dlCode  = ($outData !== false && strlen($outData) > 0) ? 200 : 0;
                    $dlErr   = ($outData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($imageUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 60,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $outData = curl_exec($dch);
                    $dlCode  = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr   = curl_error($dch);
                    curl_close($dch);
                }

                $debug = ['fal_cdn_http' => $dlCode, 'fal_cdn_err' => $dlErr ?: null, 'image_bytes' => $outData !== false ? strlen($outData) : 0, 'save_dir' => $saveDir, 'resolution' => $resolution, 'request_duration_s' => $duration, 'attempt' => $attempt];

                if ($outData !== false && $dlCode === 200 && strlen($outData) > 0) {
                    $filename = 'fal_mannequin_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $outData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'      => true,
                            'filename'     => $filename,
                            'filepath'     => $publicPath . $filename,
                            'public_url'   => $publicUrl,
                            'fal_url'      => $isDataUri ? null : $imageUrl,
                            'source'       => 'FAL AI / nano-banana-2/edit (Mannequin → Model)',
                            'duration'     => $duration,
                            'resolution'   => $resolution,
                            'local_path'   => $filePath, // used internally to chain into background removal
                            'debug'        => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Image downloaded but file_put_contents failed.', 'debug' => $debug];
                }

                if ($isDataUri) {
                    return ['success' => false, 'error' => 'FAL AI returned an inline image but it could not be decoded/saved.', 'debug' => $debug];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_mannequin_' . time() . '.jpg',
                    'filepath'   => null,
                    'public_url' => $imageUrl,
                    'fal_url'    => $imageUrl,
                    'source'     => 'FAL AI / nano-banana-2/edit (CDN — local save skipped)',
                    'duration'   => $duration,
                    'resolution' => $resolution,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no image URL. Response: ' . substr($response, 0, 300)];
        }

        // Only retry if literally nothing came back (connect-phase failure) —
        // see the RETRY NOTE above for why an HTTP error or timeout-after-
        // reaching-FAL must NOT trigger a retry (risk of double billing).
        if ($httpCode !== 0) break;
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (nano-banana-2/edit) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: background removal via FAL AI (BiRefNet v2) ──────────────────────
// Takes a local image path (typically the just-generated mannequin→model
// output), uploads it through the same fal_proxy path, and returns a
// transparent PNG with the background removed. Kept as its own small
// function so it can be called standalone OR chained right after
// generateMannequinToModelWithFal in the same request.
function removeBackgroundWithFal($sourceImageAbsPath, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_BIREFNET_URL;

    $proxy_url   = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $uploadToFal = function ($path) use ($proxy_url) {
        $ch = curl_init($proxy_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'base64'    => base64_encode(file_get_contents($path)),
                'mime_type' => mime_content_type($path),
                'file_name' => basename($path),
            ]),
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $url = json_decode($res, true)['file_url'] ?? null;
        return [$url, $http, $err, $res];
    };

    [$source_url, $uh, $uerr, $ures] = (function () use ($sourceImageAbsPath, $uploadToFal) {
        $upload_path = vv_shrink_to_target_size($sourceImageAbsPath);
        $result = $uploadToFal($upload_path);
        if ($upload_path !== $sourceImageAbsPath) @unlink($upload_path);
        return $result;
    })();
    if (!$source_url) {
        return ['success' => false, 'error' => "Failed to upload image to fal.ai for background removal (HTTP $uh" . ($uerr ? ", curl: $uerr" : '') . ')', 'debug' => ['raw' => substr((string)$ures, 0, 300)]];
    }

    $payload = json_encode([
        'image_url' => $source_url,
        'model'     => 'General Use (Light)',
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    $curlError = '';
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_BIREFNET_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            // BiRefNet's output schema is a single `image` object, not an `images` array.
            $imageUrl = $data['image']['url'] ?? ($data['images'][0]['url'] ?? null);
            if ($imageUrl) {
                $dch = curl_init($imageUrl);
                curl_setopt_array($dch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $outData = curl_exec($dch);
                $dlCode  = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                $dlErr   = curl_error($dch);
                curl_close($dch);

                $debug = ['fal_cdn_http' => $dlCode, 'fal_cdn_err' => $dlErr ?: null, 'image_bytes' => $outData !== false ? strlen($outData) : 0];

                if ($outData !== false && $dlCode === 200 && strlen($outData) > 0) {
                    $filename = 'fal_bgremoved_' . time() . '_' . mt_rand(1000, 9999) . '.png'; // PNG — transparency must survive
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $outData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $imageUrl,
                            'source'     => 'FAL AI / BiRefNet v2 (background removed)',
                            'duration'   => $duration,
                            'debug'      => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Background-removed image downloaded but file_put_contents failed.', 'debug' => $debug];
                }
                return ['success' => false, 'error' => 'BiRefNet returned an image URL but it could not be downloaded.', 'debug' => $debug];
            }
            return ['success' => false, 'error' => 'BiRefNet returned HTTP 200 but no image found. Response: ' . substr($response, 0, 300)];
        }

        if ($httpCode !== 0) break; // same no-double-bill rule as the mannequin function
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (BiRefNet background removal) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: generate video via FAL AI (LTX 2.3 Pro, text-to-video) — instant ───
function generateVideoWithFal($prompt, $aspectRatio, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_LTX_VIDEO_URL;

    $payload = json_encode([
        'prompt'         => $prompt,
        'duration'       => T2V_MAX_DURATION, // enum: 6 — must be a number, not a string ("6" caused HTTP 422)
        'resolution'     => '1080p',
        'aspect_ratio'   => $aspectRatio, // "9:16" or "16:9"
        'fps'            => 25,
        'generate_audio' => false,
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_LTX_VIDEO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 240, // text-to-video is slower than images
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $videoUrl = $data['video']['url'] ?? null;
            if ($videoUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $videoUrl, $dataUriMatch);
                if ($isDataUri) {
                    $videoData = base64_decode($dataUriMatch[1]);
                    $dlCode    = ($videoData !== false && strlen($videoData) > 0) ? 200 : 0;
                    $dlErr     = ($videoData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($videoUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $videoData = curl_exec($dch);
                    $dlCode    = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr     = curl_error($dch);
                    curl_close($dch);
                }

                $debug = [
                    'fal_cdn_http' => $dlCode,
                    'fal_cdn_err'  => $dlErr ?: null,
                    'video_bytes'  => $videoData !== false ? strlen($videoData) : 0,
                    'save_dir'     => $saveDir,
                    'dir_exists'   => is_dir($saveDir),
                    'dir_writable' => is_dir($saveDir) ? is_writable($saveDir) : null,
                ];

                if (!is_dir($saveDir)) {
                    $mkOk = mkdir($saveDir, 0755, true);
                    $debug['mkdir_result'] = $mkOk;
                    $debug['mkdir_error']  = $mkOk ? null : (error_get_last()['message'] ?? 'unknown');
                    $debug['dir_exists']   = is_dir($saveDir);
                    $debug['dir_writable'] = is_dir($saveDir) ? is_writable($saveDir) : false;
                }

                if ($videoData !== false && $dlCode === 200 && strlen($videoData) > 0) {
                    $filename = 'fal_ltx_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $videoData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;
                    $debug['write_error']  = $written === false ? (error_get_last()['message'] ?? 'unknown') : null;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $isDataUri ? null : $videoUrl,
                            'source'     => 'FAL AI / LTX 2.3',
                            'duration'   => $duration,
                            'video_secs' => $data['video']['duration'] ?? T2V_MAX_DURATION,
                            'debug'      => $debug,
                        ];
                    }
                    return [
                        'success' => false,
                        'error'   => '❌ Video downloaded (' . strlen($videoData) . ' bytes) but file_put_contents failed. See debug for details.',
                        'fal_url' => $isDataUri ? null : $videoUrl,
                        'debug'   => $debug,
                    ];
                }

                if ($isDataUri) {
                    return [
                        'success' => false,
                        'error'   => 'FAL AI returned an inline video but it could not be decoded/saved. See debug for details.',
                        'debug'   => $debug,
                    ];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_ltx_' . time() . '.mp4',
                    'filepath'   => null,
                    'public_url' => $videoUrl,
                    'fal_url'    => $videoUrl,
                    'source'     => 'FAL AI / LTX 2.3 (CDN — local save skipped)',
                    'duration'   => $duration,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no video URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (LTX 2.3) failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: generate video via FAL AI (LTX 2.3 Pro, image-to-video) — instant ──
// $imageAbsPath is a local file path (e.g. $_FILES['image']['tmp_name']) — it
// gets base64-encoded into a data: URI, which fal explicitly supports as a
// file input, so no separate fal.storage upload round-trip is needed.
function generateImageVideoWithFal($imageAbsPath, $prompt, $aspectRatio, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_LTX_IMAGE_VIDEO_URL;

    $imageBytes = @file_get_contents($imageAbsPath);
    if ($imageBytes === false) {
        return ['success' => false, 'error' => 'Could not read the uploaded image'];
    }
    $mime = function_exists('mime_content_type') ? @mime_content_type($imageAbsPath) : null;
    if (!$mime) {
        $ext  = strtolower(pathinfo($imageAbsPath, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
    }
    $imageDataUri = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);

    $payload = json_encode([
        'image_url'      => $imageDataUri,
        'prompt'         => $prompt,
        'duration'       => T2V_MAX_DURATION, // 6 — must be a number, not a string
        'resolution'     => '1080p',
        'aspect_ratio'   => $aspectRatio, // "auto", "9:16", or "16:9"
        'fps'            => 25,
        'generate_audio' => false,
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_LTX_IMAGE_VIDEO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 240,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $videoUrl = $data['video']['url'] ?? null;
            if ($videoUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $videoUrl, $dataUriMatch);
                if ($isDataUri) {
                    $videoData = base64_decode($dataUriMatch[1]);
                    $dlCode    = ($videoData !== false && strlen($videoData) > 0) ? 200 : 0;
                    $dlErr     = ($videoData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($videoUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $videoData = curl_exec($dch);
                    $dlCode    = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr     = curl_error($dch);
                    curl_close($dch);
                }

                $debug = [
                    'fal_cdn_http' => $dlCode,
                    'fal_cdn_err'  => $dlErr ?: null,
                    'video_bytes'  => $videoData !== false ? strlen($videoData) : 0,
                    'save_dir'     => $saveDir,
                ];

                if ($videoData !== false && $dlCode === 200 && strlen($videoData) > 0) {
                    $filename = 'fal_ltx_i2v_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $videoData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $isDataUri ? null : $videoUrl,
                            'source'     => 'FAL AI / LTX 2.3 (image-to-video)',
                            'duration'   => $duration,
                            'debug'      => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Video downloaded but file_put_contents failed.', 'debug' => $debug];
                }

                if ($isDataUri) {
                    return ['success' => false, 'error' => 'FAL AI returned an inline video but it could not be decoded/saved.', 'debug' => $debug];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_ltx_i2v_' . time() . '.mp4',
                    'filepath'   => null,
                    'public_url' => $videoUrl,
                    'fal_url'    => $videoUrl,
                    'source'     => 'FAL AI / LTX 2.3 (CDN — local save skipped)',
                    'duration'   => $duration,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no video URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (LTX 2.3 image-to-video) failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── AJAX: transform video via FAL AI (LTX 2.3 Pro, retake-video) — instant ──
// LTX 2.3 doesn't have a generic "restyle the whole clip" endpoint — the
// closest fit fal offers is "retake-video", which regenerates a segment of
// the source clip against a new prompt (their own example: "Change flower to
// red rose"). retake_mode is set to 'replace_video' so the source clip's own
// audio survives untouched unless the optional background-audio selector is
// used to override it afterward, same as the t2v/i2v flows.
function generateVideoVideoWithFal($videoAbsPath, $prompt, $falApiKey, $admin_id, $company_id, $maxRetries = 2) {
    global $FAL_LTX_RETAKE_VIDEO_URL;

    $videoBytes = @file_get_contents($videoAbsPath);
    if ($videoBytes === false) {
        return ['success' => false, 'error' => 'Could not read the uploaded video'];
    }
    $mime = function_exists('mime_content_type') ? @mime_content_type($videoAbsPath) : null;
    if (!$mime) {
        $ext  = strtolower(pathinfo($videoAbsPath, PATHINFO_EXTENSION));
        $mime = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/x-m4v'][$ext] ?? 'video/mp4';
    }
    $videoDataUri = 'data:' . $mime . ';base64,' . base64_encode($videoBytes);

    $payload = json_encode([
        'video_url'   => $videoDataUri,
        'prompt'      => $prompt,
        'start_time'  => 0,
        'duration'    => T2V_MAX_DURATION, // 6 — same cap as the rest of the suite
        'retake_mode' => 'replace_video',
    ]);

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $saveDir    = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $httpCode = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($FAL_LTX_RETAKE_VIDEO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 240,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Key ' . $falApiKey,
            ],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $duration  = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $videoUrl = $data['video']['url'] ?? null;
            if ($videoUrl) {
                $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $videoUrl, $dataUriMatch);
                if ($isDataUri) {
                    $outData = base64_decode($dataUriMatch[1]);
                    $dlCode  = ($outData !== false && strlen($outData) > 0) ? 200 : 0;
                    $dlErr   = ($outData === false) ? 'base64_decode of inline data URI failed' : null;
                } else {
                    $dch = curl_init($videoUrl);
                    curl_setopt_array($dch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    $outData = curl_exec($dch);
                    $dlCode  = curl_getinfo($dch, CURLINFO_HTTP_CODE);
                    $dlErr   = curl_error($dch);
                    curl_close($dch);
                }

                $debug = ['fal_cdn_http' => $dlCode, 'fal_cdn_err' => $dlErr ?: null, 'video_bytes' => $outData !== false ? strlen($outData) : 0, 'save_dir' => $saveDir];

                if ($outData !== false && $dlCode === 200 && strlen($outData) > 0) {
                    $filename = 'fal_ltx_v2v_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
                    $filePath = $saveDir . $filename;
                    $written  = file_put_contents($filePath, $outData);
                    $debug['write_path']   = $filePath;
                    $debug['write_result'] = $written;

                    if ($written !== false) {
                        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host      = $_SERVER['HTTP_HOST'];
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $publicPath . $filename;
                        return [
                            'success'    => true,
                            'filename'   => $filename,
                            'filepath'   => $publicPath . $filename,
                            'public_url' => $publicUrl,
                            'fal_url'    => $isDataUri ? null : $videoUrl,
                            'source'     => 'FAL AI / LTX 2.3 (retake / video-to-video)',
                            'duration'   => $duration,
                            'debug'      => $debug,
                        ];
                    }
                    return ['success' => false, 'error' => '❌ Video downloaded but file_put_contents failed.', 'debug' => $debug];
                }

                if ($isDataUri) {
                    return ['success' => false, 'error' => 'FAL AI returned an inline video but it could not be decoded/saved.', 'debug' => $debug];
                }
                return [
                    'success'    => true,
                    'filename'   => 'fal_ltx_v2v_' . time() . '.mp4',
                    'filepath'   => null,
                    'public_url' => $videoUrl,
                    'fal_url'    => $videoUrl,
                    'source'     => 'FAL AI / LTX 2.3 (CDN — local save skipped)',
                    'duration'   => $duration,
                    'debug'      => $debug,
                ];
            }
            return ['success' => false, 'error' => 'FAL AI returned HTTP 200 but no video URL. Response: ' . substr($response, 0, 300)];
        }
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (LTX 2.3 retake-video) failed after ' . $maxRetries . ' attempts (HTTP ' . $httpCode . ').';
    if (!empty($curlError)) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'error' => $errMsg];
}

// ── Credit costs ──────────────────────────────────────────────────────────────
// Text-to-Image: flat 5 credits always
// Text-to-Video: flat per-generation cost, capped at 6s — FAL AI = instant, Modal/LTX 2.3 = queued
// Image-to-Video / Video-to-Video: each has its own separate flat cost (same FAL=instant/Modal=queued split)
// Image-to-Image: fixed 10 credits always
define('CREDIT_T2I_FIXED', 5);
define('CREDIT_I2I_FIXED', 10);
define('CREDIT_T2V_FAL_FLAT', 50);
define('CREDIT_T2V_MODAL_FLAT', 30);
define('CREDIT_I2V_FAL_FLAT', 50);
define('CREDIT_I2V_MODAL_FLAT', 30);
define('CREDIT_V2V_FAL_FLAT', 70);
define('CREDIT_V2V_MODAL_FLAT', 70);
define('T2V_MAX_DURATION', 6);
define('CREDIT_FASHN_FIXED', 15); // two image uploads + a tryon call — priced a bit above plain image-to-image
define('CREDIT_MANNEQUIN_FIXED', 8); // lowered from 12 now that default resolution is 1K, not 2K
define('CREDIT_BGREMOVE_FIXED', 4); // BiRefNet is cheap — small flat add-on when chained

function t2i_cost(): int {
    return CREDIT_T2I_FIXED;
}
function i2i_cost(): int {
    return CREDIT_I2I_FIXED;
}
function t2v_cost(string $gen_mode): int {
    return ($gen_mode === 'fal_ai') ? CREDIT_T2V_FAL_FLAT : CREDIT_T2V_MODAL_FLAT;
}
function i2v_cost(string $gen_mode): int {
    return ($gen_mode === 'fal_ai') ? CREDIT_I2V_FAL_FLAT : CREDIT_I2V_MODAL_FLAT;
}
function v2v_cost(string $gen_mode): int {
    return ($gen_mode === 'fal_ai') ? CREDIT_V2V_FAL_FLAT : CREDIT_V2V_MODAL_FLAT;
}
function fashn_cost(): int {
    return CREDIT_FASHN_FIXED;
}
function mannequin_cost(string $resolution = '1K'): int {
    // 2K/4K cost more at the FAL level too (1.5x / 2x) — scale credits to match
    $base = CREDIT_MANNEQUIN_FIXED;
    if ($resolution === '2K') return (int) round($base * 1.5);
    if ($resolution === '4K') return $base * 2;
    return $base;
}
function bgremove_cost(): int {
    return CREDIT_BGREMOVE_FIXED;
}



// ── AJAX dispatcher ───────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

// ── AJAX: get_credit_balance ──────────────────────────────────────────────────
if ($action === 'get_credit_balance') {
    header('Content-Type: application/json');
    $billing_id = resolve_billing_user($admin_id);
    $balance    = get_credit_balance($billing_id);
    echo json_encode([
        'success'        => true,
        'balance'        => $balance,
        'cost_t2i'       => t2i_cost(),
        'cost_i2i'       => i2i_cost(),
        'cost_t2v_fal'   => t2v_cost('fal_ai'),
        'cost_t2v_modal' => t2v_cost('modal'),
        'cost_i2v_fal'   => i2v_cost('fal_ai'),
        'cost_i2v_modal' => i2v_cost('modal'),
        'cost_v2v_fal'   => v2v_cost('fal_ai'),
        'cost_v2v_modal' => v2v_cost('modal'),
        'cost_fashn'     => fashn_cost(),
        'cost_mannequin' => mannequin_cost('1K'),
        'cost_mannequin_2k' => mannequin_cost('2K'),
        'cost_bgremove'  => bgremove_cost(),
    ]);
    exit;
}

if ($action === 'generate_modal') {
    header('Content-Type: application/json');
    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please enter a prompt']); exit; }

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = t2i_cost();
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This action costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhancePrompt($originalPrompt, $apiKey) : $originalPrompt;

    $result = generateWithModal($promptToUse, $admin_id, $company_id);
    if (!$result['success']) { echo json_encode($result); exit; }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['public_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'source'          => 'Modal / FLUX',
        'seed'            => $result['seed']  ?? null,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,
    ]);
    exit;
}

if ($action === 'generate_fal') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please enter a prompt']); exit; }

    $imageSize = trim($_POST['image_size'] ?? '9:16');

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = t2i_cost();
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This action costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhancePrompt($originalPrompt, $apiKey) : $originalPrompt;

    $result = generateWithFal($promptToUse, $imageSize, $falApiKey, $admin_id, $company_id);
    if (!$result['success']) {
        echo json_encode($result); // includes debug info
        exit;
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['fal_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'size'            => $imageSize,
        'source'          => $result['source'],
        'seed'            => $result['seed']  ?? null,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,  // always included so UI shows save path
    ]);
    exit;
}


// ── AJAX: Image-to-Image via FAL AI (FLUX Dev) — instant ────────────────────
if ($action === 'generate_i2i_fal') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload a source image']); exit;
    }
    $imgExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($imgExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Allowed: jpg, jpeg, png, webp']); exit;
    }
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image too large (max 8MB)']); exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please describe how the image should be edited']); exit; }

    $strength = (float) ($_POST['strength'] ?? 0.75);
    if ($strength < 0.05 || $strength > 1.0) $strength = 0.75;

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = i2i_cost();
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This action costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhanceImageToImagePrompt($originalPrompt, $apiKey) : $originalPrompt;
    $promptToUse = appendImageToImageFidelityClause($promptToUse);

    $result = generateImageToImageWithFal($_FILES['image']['tmp_name'], $promptToUse, $strength, $falApiKey, $admin_id, $company_id);
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['fal_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'strength'        => $strength,
        'source'          => $result['source'],
        'seed'            => $result['seed'] ?? null,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,
    ]);
    exit;
}

// ── AJAX: fashion try-on via FAL AI (fashn/tryon v1.6) — model photo + ──────
// garment photo in, garment-on-model photo out. See generateFashionTryonWithFal
// above for why `category` is a required, explicit field rather than a
// silent default.
if ($action === 'generate_fashn_tryon') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    foreach (['model_image' => 'model photo', 'garment_image' => 'garment photo'] as $field => $label) {
        if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            echo json_encode(['success' => false, 'error' => "Please upload a $label"]); exit;
        }
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            echo json_encode(['success' => false, 'error' => "Invalid $label type. Allowed: jpg, jpeg, png, webp"]); exit;
        }
        if ($_FILES[$field]['size'] > 8 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => ucfirst($label) . ' too large (max 8MB)']); exit;
        }
    }

    $category = trim($_POST['category'] ?? '');
    if (!in_array($category, ['one-pieces', 'tops', 'bottoms'], true)) {
        echo json_encode(['success' => false, 'error' => 'Please select a Garment Type — Full/One-Piece, Top, or Bottom']); exit;
    }
    $garmentPhotoType = trim($_POST['garment_photo_type'] ?? 'flat-lay');
    if (!in_array($garmentPhotoType, ['flat-lay', 'model'], true)) $garmentPhotoType = 'flat-lay';

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = fashn_cost();
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This action costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $result = generateFashionTryonWithFal(
        $_FILES['model_image']['tmp_name'],
        $_FILES['garment_image']['tmp_name'],
        $category,
        $garmentPhotoType,
        $falApiKey,
        $admin_id,
        $company_id
    );
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'            => true,
        'public_url'         => $result['public_url'],
        'fal_url'            => $result['fal_url'],
        'filename'           => $result['filename'],
        'filepath'           => $result['filepath'] ?? null,
        'category'           => $category,
        'garment_photo_type' => $garmentPhotoType,
        'source'             => $result['source'],
        'credits_used'       => $cost,
        'credits_balance'    => $newBalance,
        'debug'              => $result['debug'] ?? null,
    ]);
    exit;
}


// ── AJAX: mannequin → model via FAL AI (nano-banana-2/edit) ────────────────
// Single photo in (mannequin/ghost-mannequin/headless shot), same photo back
// out with a realistic human model swapped in for the mannequin/body —
// garment pixels (embroidery, color-blocking, drape, hem) are preserved
// because nothing about them needs to be regenerated. See
// generateMannequinToModelWithFal for the full reasoning.
if ($action === 'generate_mannequin_to_model') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    if (empty($_FILES['source_image']['tmp_name']) || !is_uploaded_file($_FILES['source_image']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload the mannequin/product photo']); exit;
    }
    $srcExt = strtolower(pathinfo($_FILES['source_image']['name'], PATHINFO_EXTENSION));
    if (!in_array($srcExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Allowed: jpg, jpeg, png, webp']); exit;
    }
    if ($_FILES['source_image']['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image too large (max 8MB)']); exit;
    }

    // ── Model description ────────────────────────────────────────────────────
    // Kept SEPARATE from the full prompt override below on purpose: this is
    // the thing that changes every generation (ethnicity, age, hair, expression,
    // gaze) while the garment-preservation instructions never should. Mixing
    // them into one big textarea means re-typing the preservation rules every
    // time the model needs to change — this way you only ever touch the one
    // line that actually varies.
    $defaultModelDescription = "a Pakistani woman, around 25 years old, golden brown hair, brightly smiling, not looking directly at the camera";
    $modelDescription = trim($_POST['model_description'] ?? '');
    if ($modelDescription === '') $modelDescription = $defaultModelDescription;

    // A solid default prompt the person can override, but most won't need to.
    $defaultPrompt = "Replace the headless mannequin / dress form in this photo with a realistic human model — {$modelDescription} — wearing the exact same garment shown. "
        . "Keep the garment completely unchanged — same embroidery, same colors and color-blocking, same fabric, same drape, same hemline and floor length, same layering. "
        . "Add a natural face, neck, and skin in place of the mannequin form, with a pose matching the mannequin's current stance. "
        . "Keep the background, lighting, and camera angle exactly as in the original photo. Photorealistic, sharp focus.";
    $userPrompt = trim($_POST['prompt'] ?? '');
    // Full prompt override (if provided) takes priority over the templated
    // default+model-description combo — it's the power-user escape hatch.
    $promptToUse = $userPrompt !== '' ? $userPrompt : $defaultPrompt;

    $resolution = trim($_POST['resolution'] ?? '1K');
    if (!in_array($resolution, ['1K', '2K'], true)) $resolution = '1K'; // only exposing 1K/2K in the UI — 4K is overkill here

    $removeBg = isset($_POST['remove_bg']) && $_POST['remove_bg'] === 'true';

    // ── Credit check — covers BOTH steps up front if bg removal is requested ──
    $cost = mannequin_cost($resolution) + ($removeBg ? bgremove_cost() : 0);
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This action costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $result = generateMannequinToModelWithFal(
        $_FILES['source_image']['tmp_name'],
        $promptToUse,
        $falApiKey,
        $admin_id,
        $company_id,
        $resolution
    );
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    $bgStep = null;
    if ($removeBg) {
        // Chain straight into BiRefNet using the local file we just saved —
        // no need to re-download/re-upload from the FAL CDN URL.
        $localPathForBg = $result['local_path'] ?? (__DIR__ . '/' . ($result['filepath'] ?? ''));
        if ($localPathForBg && file_exists($localPathForBg)) {
            $bgStep = removeBackgroundWithFal($localPathForBg, $falApiKey, $admin_id, $company_id);
        } else {
            $bgStep = ['success' => false, 'error' => 'Mannequin→model image saved but local path was unavailable for the background-removal step.'];
        }
    }

    // If bg removal was requested but failed, we still return the (successful)
    // mannequin→model image rather than failing the whole request — and we
    // only charge for the step that actually succeeded.
    $finalResult = ($removeBg && $bgStep && $bgStep['success']) ? $bgStep : $result;
    $actualCost  = mannequin_cost($resolution) + (($removeBg && $bgStep && $bgStep['success']) ? bgremove_cost() : 0);

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $actualCost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'            => true,
        'public_url'         => $finalResult['public_url'],
        'fal_url'            => $finalResult['fal_url'],
        'filename'           => $finalResult['filename'],
        'filepath'           => $finalResult['filepath'] ?? null,
        'source'             => $finalResult['source'],
        'prompt_used'        => $promptToUse,
        'model_description_used' => $modelDescription,
        'resolution'         => $resolution,
        'bg_removed'         => (bool) ($removeBg && $bgStep && $bgStep['success']),
        'bg_removal_error'   => ($removeBg && (!$bgStep || !$bgStep['success'])) ? ($bgStep['error'] ?? 'Unknown background-removal error') : null,
        'mannequin_image'    => $result['public_url'] ?? null, // always included so the UI can show the pre-bg-removal version too if useful
        'credits_used'       => $actualCost,
        'credits_balance'    => $newBalance,
        'debug'              => ['mannequin_step' => $result['debug'] ?? null, 'bg_removal_step' => $bgStep['debug'] ?? null],
    ]);
    exit;
}


// ── AJAX: upload a background-audio file for Text-to-Video ───────────────────
// Mirrors the upload_music pattern already used in vizard_scriptgen_2.php —
// same per-user destination folder, same allowed extensions.
if ($action === 'upload_audio') {
    header('Content-Type: application/json');
    $folder = "user_media/user_id_{$admin_id}_company_id_{$company_id}";
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    $allowed = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];
    if (empty($_FILES['audio_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file received']); exit;
    }
    $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)]); exit;
    }
    $filename = 'audio_' . time() . '_' . preg_replace('/[^a-z0-9_.-]/', '', strtolower($_FILES['audio_file']['name']));
    $dest = $folder . '/' . $filename;
    if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $dest)) {
        echo json_encode(['success' => true, 'file' => $dest, 'name' => $_FILES['audio_file']['name']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
    }
    exit;
}

// ── AJAX: list shared audio library for Text-to-Video ────────────────────────
// Reuses the same shared 'podcast_music' folder the scriptgen_2 music library
// already reads from, so anything already in that library shows up here too.
if ($action === 'list_audio_library') {
    header('Content-Type: application/json');
    $folder  = 'podcast_music';
    $allowed = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];
    $files   = [];
    if (is_dir($folder)) {
        foreach (scandir($folder) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $files[] = ['name' => $f, 'file' => $folder . '/' . $f];
            }
        }
    }
    // Also surface anything this user has already uploaded via upload_audio
    $userFolder = "user_media/user_id_{$admin_id}_company_id_{$company_id}";
    if (is_dir($userFolder)) {
        foreach (scandir($userFolder) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (strpos($f, 'audio_') !== 0) continue; // only our uploaded-audio files
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $files[] = ['name' => $f, 'file' => $userFolder . '/' . $f];
            }
        }
    }
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// ── AJAX: paginated gallery of this user's generated images/videos ───────────
// Scans the same per-user folder every generate action above saves into, so
// it picks up output from text-to-image, text-to-video, image-to-video, and
// video-to-video alike — newest first, 10 per page.
if ($action === 'list_media_gallery') {
    header('Content-Type: application/json');
    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $dir        = __DIR__ . '/user_media/' . $folderName . '/images/';
    $publicPath = 'user_media/' . $folderName . '/images/';
    $page       = max(0, (int)($_POST['page'] ?? 0));
    $perPage    = 10;

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
    $items      = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) continue;
            $full = $dir . $f;
            if (!is_file($full)) continue;
            $items[] = ['name' => $f, 'ext' => $ext, 'mtime' => filemtime($full)];
        }
    }
    usort($items, fn($a, $b) => $b['mtime'] - $a['mtime']);

    $total      = count($items);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages - 1);
    $slice      = array_slice($items, $page * $perPage, $perPage);

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $baseUrl   = $protocol . '://' . $host . $scriptDir . '/' . $publicPath;

    $files = array_map(function ($item) use ($baseUrl) {
        return [
            'name' => $item['name'],
            'url'  => $baseUrl . $item['name'],
            'type' => $item['ext'] === 'mp4' ? 'video' : 'image',
            'date' => date('M j, g:i A', $item['mtime']),
        ];
    }, $slice);

    echo json_encode([
        'success'     => true,
        'files'       => $files,
        'page'        => $page,
        'total_pages' => $totalPages,
        'total'       => $total,
    ]);
    exit;
}

// ── AJAX: delete a generated image/video from this user's gallery ───────────
// basename() strips any directory components from the incoming filename, so
// the lookup is always confined to this user's own folder regardless of what
// the client sends — no path traversal possible.
if ($action === 'delete_media') {
    header('Content-Type: application/json');
    $filename = basename(trim($_POST['filename'] ?? ''));
    if ($filename === '') { echo json_encode(['success' => false, 'error' => 'Missing filename']); exit; }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type']); exit;
    }

    $folderName = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $filePath   = __DIR__ . '/user_media/' . $folderName . '/images/' . $filename;

    if (!is_file($filePath)) {
        echo json_encode(['success' => false, 'error' => 'File not found']); exit;
    }
    if (@unlink($filePath)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not delete file — check folder permissions']);
    }
    exit;
}

// ── Mux a background-audio file into a generated video, if possible ──────────
// This shared-hosting box almost certainly does NOT have ffmpeg (that's why
// vps_ffmpeg_stitch.php exists on the Hostinger VPS for the podcast pipeline).
// This function tries a local ffmpeg binary as a best-effort path and degrades
// gracefully — returning the original silent video plus a note — if it's not
// available. If you want this to actually always mux, point $remoteMuxUrl at
// the VPS stitcher's HTTP endpoint and I'll wire a curl call to it instead.
function muxAudioIntoVideo($videoAbsPath, $audioAbsPath, $outAbsPath) {
    if (!function_exists('exec') || !is_file($videoAbsPath) || !is_file($audioAbsPath)) {
        return ['success' => false, 'reason' => 'exec() unavailable or input file missing'];
    }
    $ffmpegPath = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
    if (!$ffmpegPath) {
        return ['success' => false, 'reason' => 'ffmpeg binary not found on this server'];
    }
    $cmd = escapeshellcmd($ffmpegPath)
        . ' -y -i ' . escapeshellarg($videoAbsPath)
        . ' -i ' . escapeshellarg($audioAbsPath)
        . ' -c:v copy -c:a aac -map 0:v:0 -map 1:a:0 -shortest '
        . escapeshellarg($outAbsPath) . ' 2>&1';
    exec($cmd, $output, $returnCode);
    if ($returnCode === 0 && is_file($outAbsPath) && filesize($outAbsPath) > 0) {
        return ['success' => true];
    }
    return ['success' => false, 'reason' => 'ffmpeg exited with code ' . $returnCode, 'output' => implode("\n", $output)];
}

// ── AJAX: Text-to-Video via FAL AI (LTX 2.3 Pro) — instant ───────────────────
if ($action === 'generate_video_fal') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please enter a prompt']); exit; }

    $aspectRatio = trim($_POST['aspect_ratio'] ?? '9:16');
    if (!in_array($aspectRatio, ['9:16', '16:9'], true)) $aspectRatio = '9:16';

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = t2v_cost('fal_ai');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhanceVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;

    $result = generateVideoWithFal($promptToUse, $aspectRatio, $falApiKey, $admin_id, $company_id);
    if (!$result['success']) {
        echo json_encode($result); // includes debug info
        exit;
    }

    // ── Optional: mux selected background audio into the generated video ────
    $audioRelPath = trim($_POST['audio_file'] ?? '');
    $audioMuxed   = false;
    $audioNote    = null;
    if ($audioRelPath !== '' && !empty($result['filepath'])) {
        $audioAbsPath = __DIR__ . '/' . $audioRelPath;
        $videoAbsPath = __DIR__ . '/' . $result['filepath'];
        $muxedRelPath = preg_replace('/\.mp4$/i', '_audio.mp4', $result['filepath']);
        $muxedAbsPath = __DIR__ . '/' . $muxedRelPath;
        $muxResult    = muxAudioIntoVideo($videoAbsPath, $audioAbsPath, $muxedAbsPath);
        if ($muxResult['success']) {
            @unlink($videoAbsPath); // drop the silent version, keep the muxed one
            $result['filepath'] = $muxedRelPath;
            $result['filename'] = basename($muxedRelPath);
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $result['public_url'] = $protocol . '://' . $host . $scriptDir . '/' . $muxedRelPath;
            $audioMuxed = true;
        } else {
            $audioNote = 'Audio was selected but could not be merged on this server (' . $muxResult['reason'] . '). Saved without audio.';
        }
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['fal_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'aspect_ratio'    => $aspectRatio,
        'duration'        => T2V_MAX_DURATION,
        'source'          => $result['source'],
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_muxed'     => $audioMuxed,
        'audio_note'      => $audioNote,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,
    ]);
    exit;
}

// ── AJAX: Text-to-Video via Modal.com (LTX 2.3 self-hosted) — queued ─────────
// Writes a pending row into hdb_video_gen_que — same table/contract that
// vizard_scriptgen_2.php uses for the podcast-scene pipeline. podcast_id and
// scene_id are set to 0 here since this is a standalone request not tied to
// any podcast scene. ASSUMPTION: the queue-draining cron (not in either file
// I was given) keys off gen_mode + video_folder/video_file to know what to
// generate and where to save it, and does not require podcast_id/scene_id to
// resolve to a real hdb_podcast_stories row. If rows queued from this page
// don't get picked up, that assumption is the first thing to check.
if ($action === 'generate_video_modal_queue') {
    header('Content-Type: application/json');
    if (!isset($conn) || !$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection ($conn) not available — check config.php']);
        exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please enter a prompt']); exit; }

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = t2v_cost('modal');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhanceVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;
    $audioRelPath = trim($_POST['audio_file'] ?? '');

    // Make sure the columns this standalone flow needs exist (no-op once added)
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS admin_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS company_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS audio_file VARCHAR(255) DEFAULT NULL");

    $now         = date('Y-m-d H:i:s');
    $folderName  = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $videoFolder = 'user_media/' . $folderName . '/images'; // same folder images are saved to
    $videoFile   = 'aitools_' . (int)$admin_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
    $mediaType   = 'ai_tools_standalone';
    $genMode     = 'modal.com'; // matches the existing gen_mode value the cron already branches on

    $saveDirCheck = __DIR__ . '/' . $videoFolder . '/';
    if (!is_dir($saveDirCheck)) mkdir($saveDirCheck, 0755, true);

    $pe  = mysqli_real_escape_string($conn, $promptToUse);
    $vfe = mysqli_real_escape_string($conn, $videoFolder);
    $vne = mysqli_real_escape_string($conn, $videoFile);
    $mte = mysqli_real_escape_string($conn, $mediaType);
    $gme = mysqli_real_escape_string($conn, $genMode);
    $afe = mysqli_real_escape_string($conn, $audioRelPath);

    $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
        (podcast_id, scene_id, prompt, video_folder, media_type, video_file, videogen_flag, gen_mode, duration, admin_id, company_id, audio_file, created_at, updated_at)
        VALUES (0, 0, '$pe', '$vfe', '$mte', '$vne', 1, '$gme', " . T2V_MAX_DURATION . ", " . (int)$admin_id . ", " . (int)$company_id . ", " . ($audioRelPath !== '' ? "'$afe'" : "NULL") . ", '$now', '$now')");

    if (!$ins) {
        echo json_encode(['success' => false, 'error' => 'Could not queue video: ' . mysqli_error($conn)]);
        exit;
    }
    $rowId = mysqli_insert_id($conn);

    // ── Deduct credits at queue time (flat cost, no refund path if the job later fails) ──
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    $posRow   = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag IN (1,2) AND id <= $rowId"));
    $position = (int)($posRow['cnt'] ?? 1);

    echo json_encode([
        'success'         => true,
        'queued'          => true,
        'queue_id'        => $rowId,
        'queue_position'  => $position,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_note'      => $audioRelPath !== ''
            ? 'Audio saved alongside this job, but the queue-draining cron needs to be updated to actually mux it in — ask Inam if unsure whether that\'s done yet.'
            : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'message'         => 'Video queued — LTX 2.3 on Modal.com',
    ]);
    exit;
}

// ── AJAX: poll status of a queued Modal/LTX 2.3 video ─────────────────────────
if ($action === 'check_video_queue_status') {
    header('Content-Type: application/json');
    if (!isset($conn) || !$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection ($conn) not available']);
        exit;
    }
    $rowId = (int)($_POST['queue_id'] ?? 0);
    if (!$rowId) { echo json_encode(['success' => false, 'error' => 'Missing queue_id']); exit; }

    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, videogen_flag, video_folder, video_file FROM hdb_video_gen_que WHERE id=$rowId AND admin_id=" . (int)$admin_id . " LIMIT 1"));

    if (!$row) { echo json_encode(['success' => false, 'error' => 'Queue entry not found']); exit; }

    $flag = (int)$row['videogen_flag'];
    if ($flag === 3 && !empty($row['video_file'])) {
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'];
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $publicUrl = $protocol . '://' . $host . $scriptDir . '/' . $row['video_folder'] . '/' . $row['video_file'];
        echo json_encode(['success' => true, 'status' => 'done', 'public_url' => $publicUrl, 'video_file' => $row['video_file']]);
        exit;
    }

    $posRow   = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag IN (1,2) AND id <= $rowId"));
    $position = (int)($posRow['cnt'] ?? 1);

    echo json_encode([
        'success'        => true,
        'status'         => $flag === 2 ? 'processing' : 'queued',
        'queue_position' => $position,
    ]);
    exit;
}

// ── AJAX: Image-to-Video via FAL AI (LTX 2.3 Pro) — instant ─────────────────
if ($action === 'generate_image_video_fal') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload a source image']); exit;
    }
    $imgExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($imgExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Allowed: jpg, jpeg, png, webp']); exit;
    }
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image too large (max 8MB)']); exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please describe how the image should animate']); exit; }

    $aspectRatio = trim($_POST['aspect_ratio'] ?? 'auto');
    if (!in_array($aspectRatio, ['auto', '9:16', '16:9'], true)) $aspectRatio = 'auto';

    // ── Credit check (image-to-video has its own, higher flat cost) ─────────
    $cost = i2v_cost('fal_ai');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhanceImageVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;
    $promptToUse = appendImageFidelityClause($promptToUse);

    $result = generateImageVideoWithFal($_FILES['image']['tmp_name'], $promptToUse, $aspectRatio, $falApiKey, $admin_id, $company_id);
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    // ── Optional: mux selected background audio into the generated video ────
    $audioRelPath = trim($_POST['audio_file'] ?? '');
    $audioMuxed   = false;
    $audioNote    = null;
    if ($audioRelPath !== '' && !empty($result['filepath'])) {
        $audioAbsPath = __DIR__ . '/' . $audioRelPath;
        $videoAbsPath = __DIR__ . '/' . $result['filepath'];
        $muxedRelPath = preg_replace('/\.mp4$/i', '_audio.mp4', $result['filepath']);
        $muxedAbsPath = __DIR__ . '/' . $muxedRelPath;
        $muxResult    = muxAudioIntoVideo($videoAbsPath, $audioAbsPath, $muxedAbsPath);
        if ($muxResult['success']) {
            @unlink($videoAbsPath);
            $result['filepath'] = $muxedRelPath;
            $result['filename'] = basename($muxedRelPath);
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $result['public_url'] = $protocol . '://' . $host . $scriptDir . '/' . $muxedRelPath;
            $audioMuxed = true;
        } else {
            $audioNote = 'Audio was selected but could not be merged on this server (' . $muxResult['reason'] . '). Saved without audio.';
        }
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['fal_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'aspect_ratio'    => $aspectRatio,
        'duration'        => T2V_MAX_DURATION,
        'source'          => $result['source'],
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_muxed'     => $audioMuxed,
        'audio_note'      => $audioNote,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,
    ]);
    exit;
}

// ── AJAX: Image-to-Video via Modal.com (LTX 2.3 self-hosted) — queued ───────
// Same hdb_video_gen_que contract as the text-to-video Modal path, plus a
// source_image_url column so the (not-yet-given-to-me) cron has the source
// image to work with. check_video_queue_status above is reused unchanged.
if ($action === 'generate_image_video_modal_queue') {
    header('Content-Type: application/json');
    if (!isset($conn) || !$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection ($conn) not available — check config.php']);
        exit;
    }
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload a source image']); exit;
    }
    $imgExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($imgExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Allowed: jpg, jpeg, png, webp']); exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please describe how the image should animate']); exit; }

    // ── Credit check (image-to-video has its own, higher flat cost) ─────────
    $cost = i2v_cost('modal');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance      = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse  = ($enhance && $apiKey) ? enhanceImageVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;
    $promptToUse  = appendImageFidelityClause($promptToUse);
    $audioRelPath = trim($_POST['audio_file'] ?? '');

    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS admin_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS company_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS audio_file VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS source_image_url VARCHAR(500) DEFAULT NULL");

    $now         = date('Y-m-d H:i:s');
    $folderName  = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $videoFolder = 'user_media/' . $folderName . '/images'; // same folder images are saved to
    $saveDirAbs  = __DIR__ . '/' . $videoFolder . '/';
    if (!is_dir($saveDirAbs)) mkdir($saveDirAbs, 0755, true);

    // Save the source image so the cron has something to fetch
    $srcImgFilename = 'i2v_src_' . (int)$admin_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $imgExt;
    $srcImgAbsPath  = $saveDirAbs . $srcImgFilename;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $srcImgAbsPath)) {
        echo json_encode(['success' => false, 'error' => 'Could not save uploaded image']); exit;
    }
    $protocol      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host          = $_SERVER['HTTP_HOST'];
    $scriptDir     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $sourceImageUrl = $protocol . '://' . $host . $scriptDir . '/' . $videoFolder . '/' . $srcImgFilename;

    $videoFile = 'aitools_i2v_' . (int)$admin_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
    $mediaType = 'ai_tools_i2v_standalone'; // distinguishes from the t2v standalone rows
    $genMode   = 'modal.com';

    $pe  = mysqli_real_escape_string($conn, $promptToUse);
    $vfe = mysqli_real_escape_string($conn, $videoFolder);
    $vne = mysqli_real_escape_string($conn, $videoFile);
    $mte = mysqli_real_escape_string($conn, $mediaType);
    $gme = mysqli_real_escape_string($conn, $genMode);
    $afe = mysqli_real_escape_string($conn, $audioRelPath);
    $sie = mysqli_real_escape_string($conn, $sourceImageUrl);

    $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
        (podcast_id, scene_id, prompt, video_folder, media_type, video_file, videogen_flag, gen_mode, duration, admin_id, company_id, audio_file, source_image_url, created_at, updated_at)
        VALUES (0, 0, '$pe', '$vfe', '$mte', '$vne', 1, '$gme', " . T2V_MAX_DURATION . ", " . (int)$admin_id . ", " . (int)$company_id . ", " . ($audioRelPath !== '' ? "'$afe'" : "NULL") . ", '$sie', '$now', '$now')");

    if (!$ins) {
        echo json_encode(['success' => false, 'error' => 'Could not queue video: ' . mysqli_error($conn)]);
        exit;
    }
    $rowId = mysqli_insert_id($conn);

    // ── Deduct credits at queue time ─────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    $posRow   = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag IN (1,2) AND id <= $rowId"));
    $position = (int)($posRow['cnt'] ?? 1);

    echo json_encode([
        'success'         => true,
        'queued'          => true,
        'queue_id'        => $rowId,
        'queue_position'  => $position,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_note'      => $audioRelPath !== ''
            ? 'Audio saved alongside this job, but the queue-draining cron needs to be updated to actually mux it in.'
            : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'message'         => 'Video queued — LTX 2.3 on Modal.com',
    ]);
    exit;
}

// ── AJAX: Video-to-Video via FAL AI (LTX 2.3 Pro retake) — instant ──────────
if ($action === 'generate_video_video_fal') {
    header('Content-Type: application/json');
    if (!$falApiKey) { echo json_encode(['success' => false, 'error' => 'FAL AI key (falApiKey) not set in config.php']); exit; }

    if (empty($_FILES['video']['tmp_name']) || !is_uploaded_file($_FILES['video']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload a source video']); exit;
    }
    $vidExt = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
    if (!in_array($vidExt, ['mp4', 'mov', 'webm', 'm4v'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid video type. Allowed: mp4, mov, webm, m4v']); exit;
    }
    if ($_FILES['video']['size'] > 20 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Video too large (max 20MB)']); exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please describe how the footage should be transformed']); exit; }

    // ── Credit check (video-to-video has its own flat cost) ─────────────────
    $cost = v2v_cost('fal_ai');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance     = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse = ($enhance && $apiKey) ? enhanceVideoToVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;
    $promptToUse = appendVideoToVideoFidelityClause($promptToUse);

    $result = generateVideoVideoWithFal($_FILES['video']['tmp_name'], $promptToUse, $falApiKey, $admin_id, $company_id);
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    // ── Optional: mux selected background audio into the generated video ────
    $audioRelPath = trim($_POST['audio_file'] ?? '');
    $audioMuxed   = false;
    $audioNote    = null;
    if ($audioRelPath !== '' && !empty($result['filepath'])) {
        $audioAbsPath = __DIR__ . '/' . $audioRelPath;
        $videoAbsPath = __DIR__ . '/' . $result['filepath'];
        $muxedRelPath = preg_replace('/\.mp4$/i', '_audio.mp4', $result['filepath']);
        $muxedAbsPath = __DIR__ . '/' . $muxedRelPath;
        $muxResult    = muxAudioIntoVideo($videoAbsPath, $audioAbsPath, $muxedAbsPath);
        if ($muxResult['success']) {
            @unlink($videoAbsPath);
            $result['filepath'] = $muxedRelPath;
            $result['filename'] = basename($muxedRelPath);
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $result['public_url'] = $protocol . '://' . $host . $scriptDir . '/' . $muxedRelPath;
            $audioMuxed = true;
        } else {
            $audioNote = 'Audio was selected but could not be merged on this server (' . $muxResult['reason'] . '). Saved without audio.';
        }
    }

    // ── Deduct credits ────────────────────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    echo json_encode([
        'success'         => true,
        'public_url'      => $result['public_url'],
        'fal_url'         => $result['fal_url'],
        'filename'        => $result['filename'],
        'filepath'        => $result['filepath'] ?? null,
        'duration'        => T2V_MAX_DURATION,
        'source'          => $result['source'],
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_muxed'     => $audioMuxed,
        'audio_note'      => $audioNote,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'debug'           => $result['debug'] ?? null,
    ]);
    exit;
}

// ── AJAX: Video-to-Video via Modal.com (LTX 2.3 self-hosted) — queued ───────
// Same hdb_video_gen_que contract as the t2v/i2v Modal paths, plus a
// source_video_url column. check_video_queue_status above is reused as-is.
if ($action === 'generate_video_video_modal_queue') {
    header('Content-Type: application/json');
    if (!isset($conn) || !$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection ($conn) not available — check config.php']);
        exit;
    }
    if (empty($_FILES['video']['tmp_name']) || !is_uploaded_file($_FILES['video']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'Please upload a source video']); exit;
    }
    $vidExt = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
    if (!in_array($vidExt, ['mp4', 'mov', 'webm', 'm4v'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid video type. Allowed: mp4, mov, webm, m4v']); exit;
    }

    $originalPrompt = trim($_POST['prompt'] ?? '');
    if (empty($originalPrompt)) { echo json_encode(['success' => false, 'error' => 'Please describe how the footage should be transformed']); exit; }

    // ── Credit check (video-to-video has its own flat cost) ─────────────────
    $cost = v2v_cost('modal');
    if (check_deduction_allowed(resolve_billing_user($admin_id), $cost) !== 'valid') {
        echo json_encode(['success' => false, 'error' => "Insufficient credits. This video costs {$cost} credits.", 'credits_required' => $cost]);
        exit;
    }

    $enhance      = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    $promptToUse  = ($enhance && $apiKey) ? enhanceVideoToVideoPrompt($originalPrompt, $apiKey) : $originalPrompt;
    $promptToUse  = appendVideoToVideoFidelityClause($promptToUse);
    $audioRelPath = trim($_POST['audio_file'] ?? '');

    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS admin_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS company_id INT(11) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS audio_file VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS source_video_url VARCHAR(500) DEFAULT NULL");

    $now         = date('Y-m-d H:i:s');
    $folderName  = 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
    $videoFolder = 'user_media/' . $folderName . '/images'; // same folder everything else is saved to
    $saveDirAbs  = __DIR__ . '/' . $videoFolder . '/';
    if (!is_dir($saveDirAbs)) mkdir($saveDirAbs, 0755, true);

    // Save the source video so the cron has something to fetch
    $srcVidFilename = 'v2v_src_' . (int)$admin_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $vidExt;
    $srcVidAbsPath  = $saveDirAbs . $srcVidFilename;
    if (!move_uploaded_file($_FILES['video']['tmp_name'], $srcVidAbsPath)) {
        echo json_encode(['success' => false, 'error' => 'Could not save uploaded video']); exit;
    }
    $protocol       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host           = $_SERVER['HTTP_HOST'];
    $scriptDir      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $sourceVideoUrl = $protocol . '://' . $host . $scriptDir . '/' . $videoFolder . '/' . $srcVidFilename;

    $videoFile = 'aitools_v2v_' . (int)$admin_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.mp4';
    $mediaType = 'ai_tools_v2v_standalone';
    $genMode   = 'modal.com';

    $pe  = mysqli_real_escape_string($conn, $promptToUse);
    $vfe = mysqli_real_escape_string($conn, $videoFolder);
    $vne = mysqli_real_escape_string($conn, $videoFile);
    $mte = mysqli_real_escape_string($conn, $mediaType);
    $gme = mysqli_real_escape_string($conn, $genMode);
    $afe = mysqli_real_escape_string($conn, $audioRelPath);
    $sve = mysqli_real_escape_string($conn, $sourceVideoUrl);

    $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
        (podcast_id, scene_id, prompt, video_folder, media_type, video_file, videogen_flag, gen_mode, duration, admin_id, company_id, audio_file, source_video_url, created_at, updated_at)
        VALUES (0, 0, '$pe', '$vfe', '$mte', '$vne', 1, '$gme', " . T2V_MAX_DURATION . ", " . (int)$admin_id . ", " . (int)$company_id . ", " . ($audioRelPath !== '' ? "'$afe'" : "NULL") . ", '$sve', '$now', '$now')");

    if (!$ins) {
        echo json_encode(['success' => false, 'error' => 'Could not queue video: ' . mysqli_error($conn)]);
        exit;
    }
    $rowId = mysqli_insert_id($conn);

    // ── Deduct credits at queue time ─────────────────────────────────────────
    try { $newBalance = deduct_credit_balance($admin_id, $company_id, $cost, 0); }
    catch (Exception $e) { $newBalance = null; }

    $posRow   = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM hdb_video_gen_que WHERE videogen_flag IN (1,2) AND id <= $rowId"));
    $position = (int)($posRow['cnt'] ?? 1);

    echo json_encode([
        'success'         => true,
        'queued'          => true,
        'queue_id'        => $rowId,
        'queue_position'  => $position,
        'enhanced_prompt' => ($enhance && $promptToUse !== $originalPrompt) ? $promptToUse : null,
        'audio_note'      => $audioRelPath !== ''
            ? 'Audio saved alongside this job, but the queue-draining cron needs to be updated to actually mux it in.'
            : null,
        'credits_used'    => $cost,
        'credits_balance' => $newBalance,
        'message'         => 'Video queued — LTX 2.3 on Modal.com',
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — AI Generation Tools</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;  --mid-blue: #143b63;   --accent: #5fd1ff;
  --purple: #8b5cf6;     --purple-lt: #ede9fe;   --green: #10b981;
  --orange: #f59e0b;     --orange-lt: #fef3c7;   --text: #1e293b;
  --muted: #64748b;      --border: #e2e8f0;       --bg: #f8fafc;
  --card: #ffffff;       --shadow: 0 4px 12px rgba(0,0,0,0.08);
  --pink: #ec4899;       --pink-lt: #fdf2f8;
}
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── Header ─────────────────────────────────────────────────── */
.vidora-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: #fff;
  box-shadow: 0 3px 10px rgba(0,0,0,0.15);
  position: sticky;
  top: 0;
  z-index: 1000;
}
.brand-link  { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.brand-icon  { font-size: 24px; }
.brand-name  { font-size: 18px; font-weight: 700; }
.brand-video { color: #fff; }
.brand-vizard{ color: #5fd1ff; }
.back-link { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.75); text-decoration: none; display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid rgba(255,255,255,.25); border-radius: 8px; transition: all .15s; }
.back-link:hover { color: #fff; background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.45); }

/* ── Page wrapper ───────────────────────────────────────────── */
.page-wrap {
  flex: 1;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 36px 16px 60px;
}

/* ── Main card ──────────────────────────────────────────────── */
.wiz-card {
  background: var(--card);
  border-radius: 16px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  width: 100%;
  max-width: 900px;
  overflow: visible;
}
.wiz-card-header {
  padding: 22px 28px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  border-bottom: 1px solid var(--border);
}
.wiz-card-header h1 { font-size: 21px; font-weight: 700; color: #fff; margin: 0 0 4px; }
.wiz-card-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 0; }
.wiz-card-body { padding: 28px 28px 32px; }

/* ── Step label ─────────────────────────────────────────────── */
.step-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.step-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* ── Tool selector tabs ──────────────────────────────────────── */
.tool-tabs {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 28px;
}
@media (max-width: 640px) {
  .tool-tabs { grid-template-columns: repeat(2, 1fr); }
  .wiz-card-body { padding: 20px 16px 28px; }
}
.tool-tab {
  border: 2px solid var(--border);
  border-radius: 14px;
  padding: 16px 12px 14px;
  background: var(--card);
  cursor: pointer;
  transition: all .2s cubic-bezier(.16,1,.3,1);
  text-align: center;
  position: relative;
  overflow: hidden;
}
.tool-tab::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  border-radius: 12px 12px 0 0;
  opacity: 0;
  transition: opacity .2s;
}
.tool-tab:hover::before, .tool-tab.active::before { opacity: 1; }
.tool-tab.tab-t2i::before  { background: linear-gradient(90deg, #8b5cf6, #5fd1ff); }
.tool-tab.tab-i2i::before  { background: linear-gradient(90deg, #14b8a6, #0ea5e9); }
.tool-tab.tab-t2v::before  { background: linear-gradient(90deg, #f59e0b, #ef4444); }
.tool-tab.tab-i2v::before  { background: linear-gradient(90deg, #10b981, #3b82f6); }
.tool-tab.tab-v2v::before  { background: linear-gradient(90deg, #ec4899, #f97316); }
.tool-tab.tab-fashn::before { background: linear-gradient(90deg, #d946ef, #f43f5e); }

.tool-tab:hover  { border-color: var(--purple); box-shadow: 0 4px 16px rgba(139,92,246,0.12); transform: translateY(-2px); }
.tool-tab.active { border-color: var(--purple); box-shadow: 0 4px 16px rgba(139,92,246,0.15); background: var(--purple-lt); }
.tool-tab.tab-i2i.active  { border-color: #0ea5e9;       box-shadow: 0 4px 16px rgba(14,165,233,0.15);  background: #e0f2fe; }
.tool-tab.tab-t2v.active  { border-color: var(--orange); box-shadow: 0 4px 16px rgba(245,158,11,0.15); background: var(--orange-lt); }
.tool-tab.tab-i2v.active  { border-color: var(--green);  box-shadow: 0 4px 16px rgba(16,185,129,0.15);  background: #d1fae5; }
.tool-tab.tab-v2v.active  { border-color: var(--pink);   box-shadow: 0 4px 16px rgba(236,72,153,0.15);  background: var(--pink-lt); }
.tool-tab.tab-fashn.active { border-color: #d946ef;      box-shadow: 0 4px 16px rgba(217,70,239,0.15); background: #fae8ff; }

.tool-tab-icon  { font-size: 28px; display: block; margin-bottom: 8px; line-height: 1; }
.tool-tab-title { font-size: 12px; font-weight: 700; color: var(--dark-blue); line-height: 1.3; }
.tool-tab-sub   { font-size: 10px; color: var(--muted); margin-top: 3px; }

/* ── Tool panel ──────────────────────────────────────────────── */
.tool-panel { display: none; }
.tool-panel.active { display: block; }

/* ── Panel inner layout ──────────────────────────────────────── */
.panel-header {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 20px;
  border-radius: 14px;
  margin-bottom: 22px;
}
.panel-header.ph-t2i { background: linear-gradient(90deg, #4c1d95, #6d28d9); }
.panel-header.ph-t2v { background: linear-gradient(90deg, #92400e, #d97706); }
.panel-header.ph-i2v { background: linear-gradient(90deg, #065f46, #059669); }
.panel-header.ph-v2v { background: linear-gradient(90deg, #831843, #be185d); }
.panel-header.ph-fashn { background: linear-gradient(90deg, #86198f, #be185d); }
.panel-header-icon  { font-size: 32px; line-height: 1; flex-shrink: 0; }
.panel-header-title { font-size: 18px; font-weight: 800; color: #fff; }
.panel-header-sub   { font-size: 12px; color: rgba(255,255,255,.75); margin-top: 3px; }

/* ── Two-col gen layout (for text-to-image) ─────────────────── */
.gen-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }


/* ── Gen box ─────────────────────────────────────────────────── */
.gen-box {
  border: 1.5px solid var(--border);
  border-radius: 14px;
  overflow: visible;
}
.gen-box-head {
  padding: 12px 16px;
  font-size: 13px;
  font-weight: 700;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 8px;
}
.gen-box-head.gh-flux   { background: linear-gradient(90deg, #6d28d9, #4c1d95); }

.gen-box-head.gh-orange { background: linear-gradient(90deg, #d97706, #92400e); }
.gen-box-head.gh-green  { background: linear-gradient(90deg, #059669, #065f46); }
.gen-box-head.gh-pink   { background: linear-gradient(90deg, #be185d, #831843); }
.gen-box-body { padding: 18px 16px; }

/* ── Form elements ───────────────────────────────────────────── */
.field-label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: var(--dark-blue);
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.field-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-size: 13px;
  font-family: inherit;
  color: var(--text);
  resize: vertical;
  outline: none;
  transition: border-color .15s;
  min-height: 90px;
}
.field-textarea:focus { border-color: var(--purple); }
.field-input {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-size: 13px;
  font-family: inherit;
  color: var(--text);
  outline: none;
  transition: border-color .15s;
  background: #fff;
}
.field-input:focus { border-color: var(--purple); }
.field-group { margin-bottom: 14px; }
.check-row {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 14px;
  font-size: 12px;
  color: var(--muted);
  font-weight: 500;
  cursor: pointer;
}
.check-row input { width: 15px; height: 15px; cursor: pointer; accent-color: var(--purple); }

/* ── Buttons ─────────────────────────────────────────────────── */
.gen-btn {
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: all .18s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #fff;
}
.gen-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.18); }
.gen-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.btn-flux   { background: linear-gradient(135deg, #7c3aed, #5b21b6); }

.btn-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
.btn-green  { background: linear-gradient(135deg, #10b981, #059669); }
.btn-pink   { background: linear-gradient(135deg, #ec4899, #be185d); }

/* ── Result area ─────────────────────────────────────────────── */
.result-area { margin-top: 14px; }
.result-loading {
  text-align: center;
  padding: 28px;
  color: var(--muted);
  font-size: 13px;
}
.result-img-wrap { text-align: center; margin-bottom: 10px; }
.result-img-wrap img {
  max-width: 100%;
  border-radius: 12px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.1);
  display: block;
  margin: 0 auto;
}
.result-info {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 10px 12px;
  font-size: 11px;
  color: var(--muted);
  line-height: 1.6;
}
.result-info strong { color: var(--dark-blue); }
.result-error {
  background: #fef2f2;
  color: #dc2626;
  border-radius: 10px;
  padding: 11px 13px;
  font-size: 12px;
  border-left: 3px solid #ef4444;
}
.result-warning {
  background: #fffbeb;
  color: #92400e;
  border-radius: 10px;
  padding: 10px 12px;
  font-size: 12px;
  border-left: 3px solid var(--orange);
  margin-bottom: 10px;
  display: none;
}

/* ── Coming soon panel ───────────────────────────────────────── */
.coming-soon {
  text-align: center;
  padding: 52px 20px;
  color: var(--muted);
}
.coming-soon-icon { font-size: 52px; margin-bottom: 16px; display: block; }
.coming-soon h3   { font-size: 18px; font-weight: 700; color: var(--dark-blue); margin-bottom: 8px; }
.coming-soon p    { font-size: 13px; line-height: 1.6; max-width: 340px; margin: 0 auto; }
.coming-soon-badge {
  display: inline-block;
  margin-top: 16px;
  padding: 5px 14px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  background: var(--purple-lt);
  color: var(--purple);
}

/* ── Divider ─────────────────────────────────────────────────── */
.divider { height: 1px; background: var(--border); margin: 18px 0; }

/* ── Spinner ─────────────────────────────────────────────────── */
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
  display: inline-block;
  width: 14px; height: 14px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin .6s linear infinite;
}

/* ── Animation ───────────────────────────────────────────────── */
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
.tool-panel.active { animation: fadeIn .25s ease both; }

/* ── Full-width single box ───────────────────────────────────── */
.gen-box-full {
  border: 1.5px solid var(--border);
  border-radius: 14px;
  overflow: visible;
}

/* ── Provider selector cards (FAL vs Modal) ───────────────────── */
.provider-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.provider-card {
  border: 2px solid var(--border);
  border-radius: 12px;
  padding: 14px 12px;
  text-align: center;
  cursor: pointer;
  background: #fff;
  transition: all .15s ease;
}
.provider-card:hover { border-color: var(--orange); transform: translateY(-1px); }
.provider-card.selected {
  border-color: var(--orange);
  background: var(--orange-lt);
  box-shadow: 0 4px 14px rgba(245,158,11,.15);
}
.provider-card-icon  { font-size: 22px; display: block; margin-bottom: 4px; }
.provider-card-title { font-size: 13px; font-weight: 700; color: var(--dark-blue); }
.provider-card-sub   { font-size: 10px; color: var(--muted); margin-top: 2px; }
.provider-card-cost  {
  display: inline-block;
  margin-top: 8px;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 10px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid var(--border);
  color: var(--dark-blue);
}

/* ── Background audio selector ────────────────────────────────── */
.audio-btn {
  padding: 8px 14px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--dark-blue);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all .15s;
}
.audio-btn:hover { border-color: var(--purple); background: var(--purple-lt); }
.lib-select-btn {
  padding: 5px 12px;
  border: none;
  border-radius: 7px;
  background: var(--purple);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  flex-shrink: 0;
}
.lib-select-btn:hover { background: #6d28d9; }

/* ── Recent-generations gallery ───────────────────────────────── */
.media-gallery {
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1.5px solid var(--border);
}
.media-gallery-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.media-gallery-title { font-size: 12px; font-weight: 700; color: var(--dark-blue); text-transform: uppercase; letter-spacing: .03em; }
.media-gallery-count { font-size: 11px; color: var(--muted); }
.gallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
  gap: 8px;
}
.gallery-card {
  position: relative;
  aspect-ratio: 1;
  border-radius: 8px;
  overflow: hidden;
  border: 1.5px solid var(--border);
  background: #0f172a;
  display: block;
  text-decoration: none;
  transition: transform .12s, border-color .12s;
}
.gallery-card:hover { transform: translateY(-2px); border-color: var(--purple); }
.gallery-card img, .gallery-card video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.gallery-play-badge {
  position: absolute;
  bottom: 4px;
  right: 4px;
  background: rgba(0,0,0,.65);
  color: #fff;
  font-size: 9px;
  line-height: 1;
  padding: 3px 5px;
  border-radius: 5px;
}
.gallery-card-delete {
  position: absolute;
  top: 3px;
  right: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(0,0,0,.65);
  color: #fff;
  border: none;
  font-size: 11px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2;
}
.gallery-card-delete:hover { background: #dc2626; }
.gallery-pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-top: 12px;
}
.gallery-pager .audio-btn:disabled { opacity: .4; cursor: not-allowed; }
.gallery-pager span { font-size: 12px; color: var(--muted); }


/* ── WAN status banner ────────────────────────────────────────── */
.wan-banner {
  display: none;
  align-items: center;
  gap: 10px;
  padding: 11px 14px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 14px;
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  color: #92400e;
}
.wan-banner.success { background: #f0fdf4; border-color: #bbf7d0; color: #065f46; }
.wan-banner.error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
.wan-banner .spinner { border-top-color: #d97706; border-color: rgba(217,119,6,.3); }

/* ── WAN progress ─────────────────────────────────────────────── */
.wan-prog-wrap { margin-bottom: 18px; display: none; }
.wan-prog-header {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: var(--muted);
  margin-bottom: 6px;
  font-weight: 600;
}
.wan-prog-track {
  width: 100%;
  height: 7px;
  background: #f1f5f9;
  border-radius: 100px;
  overflow: hidden;
  border: 1px solid var(--border);
}
.wan-prog-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #ef4444);
  border-radius: 100px;
  transition: width .6s ease;
  width: 0%;
}

/* ── WAN stories grid ─────────────────────────────────────────── */
.wan-stories { display: none; margin-top: 18px; }
.wan-stories-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 12px;
}
.wan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 12px;
}
.wan-story-card {
  border: 1.5px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  background: #f8fafc;
  transition: transform .2s, border-color .2s;
}
.wan-story-card:hover { transform: translateY(-2px); border-color: var(--orange); }
.wan-video-wrap {
  aspect-ratio: 9/16;
  background: #0f172a;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.wan-video-wrap video { width: 100%; height: 100%; object-fit: cover; }
.wan-pending-overlay, .wan-error-overlay {
  position: absolute; inset: 0;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 6px;
  background: rgba(15,23,42,.8);
}
.wan-pending-overlay .big-spinner {
  width: 28px; height: 28px;
  border: 3px solid rgba(245,158,11,.2);
  border-top-color: #f59e0b;
  border-radius: 50%;
  animation: spin .9s linear infinite;
}
.wan-pending-overlay span, .wan-error-overlay span {
  font-size: 10px; color: rgba(255,255,255,.6);
}
.wan-story-info { padding: 8px 10px; }
.wan-story-id   { font-size: 10px; color: var(--muted); margin-bottom: 4px; }
.wan-story-status {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 700;
  padding: 2px 8px; border-radius: 10px;
}
.wan-story-status.done    { background: #d1fae5; color: #065f46; }
.wan-story-status.pending { background: #fef3c7; color: #92400e; }
.wan-story-status.error   { background: #fee2e2; color: #dc2626; }
.wan-dl-btn {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  margin-top: 6px; padding: 5px 8px;
  border: 1.5px solid var(--border); border-radius: 8px;
  color: var(--orange); font-size: 10px; font-weight: 700;
  text-decoration: none; transition: all .2s;
  background: #fffbeb;
}
.wan-dl-btn:hover { background: #fef3c7; border-color: var(--orange); }

/* ── WAN done banner ──────────────────────────────────────────── */
.wan-done {
  display: none; text-align: center;
  padding: 22px; margin-top: 14px;
  background: #f0fdf4; border: 1.5px solid #bbf7d0;
  border-radius: 12px;
}
.wan-done h3 { font-size: 15px; color: #065f46; margin-bottom: 4px; }
.wan-done p  { font-size: 12px; color: var(--muted); }
</style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────── -->
<header class="vidora-header">
  <a class="brand-link" href="videovizard.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name">
      <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
    </span>
  </a>
  <div id="creditBadge" style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:6px 14px;font-size:13px;font-weight:600;color:#fff;cursor:default;" title="Your credit balance">
    <span style="font-size:16px;">💎</span>
    <span id="creditBalanceVal">—</span>
    <span style="font-size:11px;opacity:.7;font-weight:400;">credits</span>
  </div>
  <a class="back-link" href="vizard_scriptgen.php">← Back</a>
</header>

<!-- ── Page ────────────────────────────────────────────────────── -->
<div class="page-wrap">
  <div class="wiz-card">

    <div class="wiz-card-header">
      <h1>🛠️ AI Generation Tools</h1>
      <p>Text to Image · Text to Video · Image to Video · Video to Video</p>
    </div>

    <div class="wiz-card-body">
      <div class="step-label">Choose a tool below</div>

      <!-- ── Tool selector tabs ──────────────────────────────── -->
      <div class="tool-tabs">

        <div class="tool-tab tab-t2i active" onclick="switchTool('t2i')">
          <span class="tool-tab-icon">🖼️</span>
          <div class="tool-tab-title">Text to Image</div>
          <div class="tool-tab-sub">FAL AI / FLUX Dev</div>
        </div>

        <div class="tool-tab tab-i2i" onclick="switchTool('i2i')">
          <span class="tool-tab-icon">🖼️→🖼️</span>
          <div class="tool-tab-title">Image to Image</div>
          <div class="tool-tab-sub">FAL AI / FLUX Dev</div>
        </div>

        <div class="tool-tab tab-t2v" onclick="switchTool('t2v')">
          <span class="tool-tab-icon">🎞️</span>
          <div class="tool-tab-title">Text to Video</div>
          <div class="tool-tab-sub">FAL AI / LTX 2.3</div>
        </div>

        <div class="tool-tab tab-i2v" onclick="switchTool('i2v')">
          <span class="tool-tab-icon">🖼️→🎬</span>
          <div class="tool-tab-title">Image to Video</div>
          <div class="tool-tab-sub">FAL AI / LTX 2.3</div>
        </div>

        <div class="tool-tab tab-v2v" onclick="switchTool('v2v')">
          <span class="tool-tab-icon">🎬→🎬</span>
          <div class="tool-tab-title">Video to Video</div>
          <div class="tool-tab-sub">FAL AI / LTX 2.3</div>
        </div>

        <div class="tool-tab tab-fashn" onclick="switchTool('fashn')">
          <span class="tool-tab-icon">👗</span>
          <div class="tool-tab-title">Fashion Try-On</div>
          <div class="tool-tab-sub">FAL AI / fashn</div>
        </div>

        <div class="tool-tab tab-mannequin" onclick="switchTool('mannequin')">
          <span class="tool-tab-icon">🪆→🧍</span>
          <div class="tool-tab-title">Mannequin → Model</div>
          <div class="tool-tab-sub">FAL AI / nano-banana-2</div>
        </div>

      </div><!-- /tool-tabs -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL 1: Text to Image                                -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel active" id="panel-t2i">

        <div class="panel-header ph-t2i">
          <span class="panel-header-icon">🖼️</span>
          <div>
            <div class="panel-header-title">Text to Image</div>
            <div class="panel-header-sub">Generate photorealistic images from a text description — Modal / FLUX or FAL AI</div>
          </div>
        </div>

        <div class="gen-grid">

          <div class="gen-box" style="overflow:visible;">
            <div class="gen-box-head gh-flux" style="background:linear-gradient(90deg,#6d28d9,#4c1d95);display:flex;align-items:center;justify-content:space-between;">
              <span>⚡ FAL AI / FLUX Dev — Text to Image</span>
              <span id="t2iCostBadge" style="font-size:11px;background:rgba(255,255,255,.18);padding:3px 10px;border-radius:10px;font-weight:600;">— cr</span>
            </div>
            <div class="gen-box-body">

              <div class="field-group">
                <label class="field-label">Image Prompt</label>
                <textarea class="field-textarea" id="t2iPrompt" rows="4" placeholder="Describe the image you want to generate…"></textarea>
              </div>

              <div class="field-group">
                <label class="field-label">Image Size</label>
                <select class="field-input" id="t2iImageSize" style="cursor:pointer;">
                  <option value="9:16" selected>9:16 — Portrait (768 × 1344) — default</option>
                  <option value="16:9">16:9 — Landscape (1344 × 768)</option>
                  <option value="1:1">1:1 — Square (1024 × 1024)</option>
                  <option value="4:5">4:5 — Portrait (896 × 1120)</option>
                  <option value="3:4">3:4 — Portrait (896 × 1152)</option>
                </select>
              </div>

              <label class="check-row">
                <input type="checkbox" id="t2iEnhance" checked>
                ✨ Enhance prompt with AI (GPT-4o-mini)
              </label>

              <button class="gen-btn btn-flux" id="t2iBtn" onclick="generateT2I()" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);">
                ⚡ Generate Image
              </button>

              <div class="result-area" id="t2iResult"></div>

              <div class="media-gallery">
                <div class="media-gallery-head">
                  <span class="media-gallery-title">📁 Your Recent Generations</span>
                  <span class="media-gallery-count" id="t2iGalleryCount"></span>
                </div>
                <div class="gallery-grid" id="t2iGalleryGrid">
                  <p style="color:#94a3b8;font-size:12px;">Loading…</p>
                </div>
                <div class="gallery-pager">
                  <button type="button" class="audio-btn" id="t2iGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                  <span id="t2iGalleryPageInfo">Page 1</span>
                  <button type="button" class="audio-btn" id="t2iGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
                </div>
              </div>
            </div>
          </div>

        </div><!-- /gen-grid -->

      </div><!-- /panel-t2i -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL 1b: Image to Image (FAL AI / FLUX Dev)           -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-i2i">

        <div class="panel-header ph-t2i">
          <span class="panel-header-icon">🖼️→🖼️</span>
          <div>
            <div class="panel-header-title">Image to Image</div>
            <div class="panel-header-sub">Enhance and transform existing images by changing style, lighting, makeup, backgrounds, jewelry, fabric textures, and overall realism — while keeping the same model and composition.</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-flux" style="display:flex;align-items:center;justify-content:space-between;">
            <span>🖼️→🖼️ FLUX Dev — Image to Image</span>
            <span id="i2iCostBadge" style="font-size:11px;background:rgba(255,255,255,.18);padding:3px 10px;border-radius:10px;font-weight:600;">— cr</span>
          </div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Source Image</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('i2iImageInput').click()">⬆️ Upload Image</button>
                <button type="button" class="audio-btn" id="i2iImageClearBtn" onclick="clearI2IImage()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="i2iImageInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleI2IImageUpload(this)">
              <div id="i2iImagePreviewWrap" style="display:none;margin-top:8px;">
                <img id="i2iImagePreview" src="" alt="Source image preview" style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Edit Instruction</label>
              <textarea class="field-textarea" id="i2iPrompt" rows="3" placeholder="Describe the change… e.g. Turn this into a vibrant watercolor painting, or Change the background to a sunset beach"></textarea>
            </div>

            <div class="field-group">
              <label class="field-label">Transformation Strength</label>
              <select class="field-input" id="i2iStrength" style="cursor:pointer;">
                <option value="0.4">Light — small edit, stays very close to the original</option>
                <option value="0.6">Medium — noticeable change, original still recognizable</option>
                <option value="0.75" selected>Strong (default) — significant transformation</option>
                <option value="0.9">Maximum — almost fully regenerated</option>
              </select>
            </div>

            <label class="check-row">
              <input type="checkbox" id="i2iEnhance" checked>
              ✨ Enhance prompt with AI (GPT-4o-mini)
            </label>

            <button class="gen-btn btn-flux" id="i2iBtn" onclick="generateI2I()" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
              ⚡ Generate Image
            </button>

            <div class="result-area" id="i2iResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="i2iGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="i2iGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="i2iGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="i2iGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="i2iGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-i2i -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL 2: Text to Video (FAL AI / LTX 2.3 + Modal.com)  -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-t2v">

        <div class="panel-header ph-t2v">
          <span class="panel-header-icon">🎞️</span>
          <div>
            <div class="panel-header-title">Text to Video</div>
            <div class="panel-header-sub">Generate a video clip (up to 6s) from a text description — FAL AI or LTX 2.3 on Modal</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-orange">🎬 LTX 2.3 — Text to Video</div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Choose Engine</label>
              <div class="provider-row">
                <div class="provider-card selected" id="t2vCardFal" onclick="selectEngineProvider('t2v','fal')">
                  <span class="provider-card-icon">⚡</span>
                  <div class="provider-card-title">FAL AI</div>
                  <div class="provider-card-sub">Instant · no waiting</div>
                  <span class="provider-card-cost" id="t2vFalCostBadge">— cr</span>
                </div>
                <div class="provider-card" id="t2vCardModal" onclick="selectEngineProvider('t2v','modal')">
                  <span class="provider-card-icon">🕐</span>
                  <div class="provider-card-title">Modal.com</div>
                  <div class="provider-card-sub">Queued · cheaper</div>
                  <span class="provider-card-cost" id="t2vModalCostBadge">— cr</span>
                </div>
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Video Prompt</label>
              <textarea class="field-textarea" id="t2vPrompt" rows="4" placeholder="Describe the video you want to generate… e.g. A cinematic shot of ocean waves crashing at sunset, slow motion"></textarea>
            </div>

            <div class="field-group" id="t2vAspectGroup">
              <label class="field-label">Aspect Ratio</label>
              <select class="field-input" id="t2vAspect" style="cursor:pointer;">
                <option value="9:16" selected>9:16 — Portrait</option>
                <option value="16:9">16:9 — Landscape</option>
              </select>
            </div>

            <div style="font-size:11px;color:var(--muted);margin-bottom:14px;" id="t2vEngineNote">⏱️ Fixed at 6 seconds &nbsp;·&nbsp; Generated instantly, no waiting</div>

            <div class="field-group">
              <label class="field-label">Background Audio <span style="font-weight:400;text-transform:none;color:var(--muted);">(optional)</span></label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('t2vAudioUploadInput').click()">⬆️ Upload</button>
                <button type="button" class="audio-btn" onclick="openAudioLibrary('t2v')">🎵 Library</button>
                <button type="button" class="audio-btn" id="t2vAudioClearBtn" onclick="clearAudioSelection('t2v')" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="t2vAudioUploadInput" accept="audio/*" style="display:none" onchange="handleAudioUpload('t2v', this)">
              <input type="hidden" id="t2vSelectedAudioPath">
              <div id="t2vAudioPreviewWrap" style="display:none;margin-top:8px;">
                <div style="background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;">
                  <span id="t2vAudioName" style="font-size:12px;font-weight:600;color:var(--dark-blue);display:block;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                  <audio id="t2vAudioPlayer" controls style="width:100%;"></audio>
                </div>
              </div>
            </div>

            <label class="check-row">
              <input type="checkbox" id="t2vEnhance" checked>
              ✨ Enhance prompt with AI (GPT-4o-mini)
            </label>

            <!-- Status banner (Modal queue only) -->
            <div class="wan-banner" id="t2vBanner">
              <span class="spinner"></span>
              <span id="t2vBannerText">Queuing video…</span>
            </div>

            <button class="gen-btn btn-orange" id="t2vBtn" onclick="generateT2V()">
              ⚡ Generate Video
            </button>

            <div class="result-area" id="t2vResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="t2vGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="t2vGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="t2vGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="t2vGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="t2vGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-t2v -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL 3: Image to Video (FAL AI / LTX 2.3 + Modal.com) -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-i2v">

        <div class="panel-header ph-i2v">
          <span class="panel-header-icon">🖼️→🎬</span>
          <div>
            <div class="panel-header-title">Image to Video</div>
            <div class="panel-header-sub">Animate a still image into a video clip (up to 6s) — FAL AI or LTX 2.3 on Modal</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-green">🖼️→🎬 LTX 2.3 — Image to Video</div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Choose Engine</label>
              <div class="provider-row">
                <div class="provider-card selected" id="i2vCardFal" onclick="selectEngineProvider('i2v','fal')">
                  <span class="provider-card-icon">⚡</span>
                  <div class="provider-card-title">FAL AI</div>
                  <div class="provider-card-sub">Instant · no waiting</div>
                  <span class="provider-card-cost" id="i2vFalCostBadge">— cr</span>
                </div>
                <div class="provider-card" id="i2vCardModal" onclick="selectEngineProvider('i2v','modal')">
                  <span class="provider-card-icon">🕐</span>
                  <div class="provider-card-title">Modal.com</div>
                  <div class="provider-card-sub">Queued · cheaper</div>
                  <span class="provider-card-cost" id="i2vModalCostBadge">— cr</span>
                </div>
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Source Image</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('i2vImageInput').click()">⬆️ Upload Image</button>
                <button type="button" class="audio-btn" id="i2vImageClearBtn" onclick="clearI2VImage()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="i2vImageInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleI2VImageUpload(this)">
              <div id="i2vImagePreviewWrap" style="display:none;margin-top:8px;">
                <img id="i2vImagePreview" src="" alt="Source image preview" style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Motion Prompt</label>
              <textarea class="field-textarea" id="i2vPrompt" rows="3" placeholder="Describe how the image should animate… e.g. Slow camera dolly in, gentle wind moving the hair and fabric"></textarea>
            </div>

            <div class="field-group" id="i2vAspectGroup">
              <label class="field-label">Aspect Ratio</label>
              <select class="field-input" id="i2vAspect" style="cursor:pointer;">
                <option value="auto" selected>Auto — match source image</option>
                <option value="9:16">9:16 — Portrait</option>
                <option value="16:9">16:9 — Landscape</option>
              </select>
            </div>

            <div style="font-size:11px;color:var(--muted);margin-bottom:14px;" id="i2vEngineNote">⏱️ Fixed at 6 seconds &nbsp;·&nbsp; Generated instantly, no waiting</div>

            <div class="field-group">
              <label class="field-label">Background Audio <span style="font-weight:400;text-transform:none;color:var(--muted);">(optional)</span></label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('i2vAudioUploadInput').click()">⬆️ Upload</button>
                <button type="button" class="audio-btn" onclick="openAudioLibrary('i2v')">🎵 Library</button>
                <button type="button" class="audio-btn" id="i2vAudioClearBtn" onclick="clearAudioSelection('i2v')" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="i2vAudioUploadInput" accept="audio/*" style="display:none" onchange="handleAudioUpload('i2v', this)">
              <input type="hidden" id="i2vSelectedAudioPath">
              <div id="i2vAudioPreviewWrap" style="display:none;margin-top:8px;">
                <div style="background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;">
                  <span id="i2vAudioName" style="font-size:12px;font-weight:600;color:var(--dark-blue);display:block;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                  <audio id="i2vAudioPlayer" controls style="width:100%;"></audio>
                </div>
              </div>
            </div>

            <label class="check-row">
              <input type="checkbox" id="i2vEnhance" checked>
              ✨ Enhance prompt with AI (GPT-4o-mini)
            </label>

            <!-- Status banner (Modal queue only) -->
            <div class="wan-banner" id="i2vBanner">
              <span class="spinner"></span>
              <span id="i2vBannerText">Queuing video…</span>
            </div>

            <button class="gen-btn" id="i2vBtn" onclick="generateI2V()" style="background:linear-gradient(135deg,#10b981,#059669);">
              ⚡ Generate Video
            </button>

            <div class="result-area" id="i2vResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="i2vGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="i2vGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="i2vGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="i2vGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="i2vGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-i2v -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- Image to Video — Audio Library Modal                  -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div id="i2vAudioLibraryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:14px;padding:20px;width:90%;max-width:420px;max-height:70vh;display:flex;flex-direction:column;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <strong style="font-size:15px;color:var(--dark-blue);">🎵 Audio Library</strong>
            <button type="button" onclick="closeAudioLibrary('i2v')" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
          </div>
          <div id="i2vAudioLibraryList" style="overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:8px;">
            <p style="color:#94a3b8;font-size:13px;">Loading…</p>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL 4: Video to Video (FAL AI / LTX 2.3 + Modal.com) -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-v2v">

        <div class="panel-header ph-v2v">
          <span class="panel-header-icon">🎬→🎬</span>
          <div>
            <div class="panel-header-title">Video to Video</div>
            <div class="panel-header-sub">Enhance and transform existing video footage by changing style, lighting, makeup, backgrounds, jewelry, fabric textures, and overall realism — while keeping the same model and motion.</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-pink">🎬→🎬 LTX 2.3 — Video to Video</div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Choose Engine</label>
              <div class="provider-row">
                <div class="provider-card selected" id="v2vCardFal" onclick="selectEngineProvider('v2v','fal')">
                  <span class="provider-card-icon">⚡</span>
                  <div class="provider-card-title">FAL AI</div>
                  <div class="provider-card-sub">Instant · no waiting</div>
                  <span class="provider-card-cost" id="v2vFalCostBadge">— cr</span>
                </div>
                <div class="provider-card" id="v2vCardModal" onclick="selectEngineProvider('v2v','modal')">
                  <span class="provider-card-icon">🕐</span>
                  <div class="provider-card-title">Modal.com</div>
                  <div class="provider-card-sub">Queued · cheaper</div>
                  <span class="provider-card-cost" id="v2vModalCostBadge">— cr</span>
                </div>
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Source Video</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('v2vVideoInput').click()">⬆️ Upload Video</button>
                <button type="button" class="audio-btn" id="v2vVideoClearBtn" onclick="clearV2VVideo()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="v2vVideoInput" accept="video/mp4,video/quicktime,video/webm,video/x-m4v" style="display:none" onchange="handleV2VVideoUpload(this)">
              <div id="v2vVideoPreviewWrap" style="display:none;margin-top:8px;">
                <video id="v2vVideoPreview" src="" controls playsinline style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;"></video>
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Transformation Prompt</label>
              <textarea class="field-textarea" id="v2vPrompt" rows="3" placeholder="Describe how the footage should change… e.g. Change the flowers to red roses, make it nighttime with neon lighting"></textarea>
            </div>

            <div style="font-size:11px;color:var(--muted);margin-bottom:14px;" id="v2vEngineNote">⏱️ Fixed at 6 seconds &nbsp;·&nbsp; Generated instantly, no waiting</div>

            <div class="field-group">
              <label class="field-label">Background Audio <span style="font-weight:400;text-transform:none;color:var(--muted);">(optional — overrides the source clip's own audio)</span></label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('v2vAudioUploadInput').click()">⬆️ Upload</button>
                <button type="button" class="audio-btn" onclick="openAudioLibrary('v2v')">🎵 Library</button>
                <button type="button" class="audio-btn" id="v2vAudioClearBtn" onclick="clearAudioSelection('v2v')" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="v2vAudioUploadInput" accept="audio/*" style="display:none" onchange="handleAudioUpload('v2v', this)">
              <input type="hidden" id="v2vSelectedAudioPath">
              <div id="v2vAudioPreviewWrap" style="display:none;margin-top:8px;">
                <div style="background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;">
                  <span id="v2vAudioName" style="font-size:12px;font-weight:600;color:var(--dark-blue);display:block;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                  <audio id="v2vAudioPlayer" controls style="width:100%;"></audio>
                </div>
              </div>
            </div>

            <label class="check-row">
              <input type="checkbox" id="v2vEnhance" checked>
              ✨ Enhance prompt with AI (GPT-4o-mini)
            </label>

            <!-- Status banner (Modal queue only) -->
            <div class="wan-banner" id="v2vBanner">
              <span class="spinner"></span>
              <span id="v2vBannerText">Queuing video…</span>
            </div>

            <button class="gen-btn" id="v2vBtn" onclick="generateV2V()" style="background:linear-gradient(135deg,#ec4899,#be185d);">
              ⚡ Generate Video
            </button>

            <div class="result-area" id="v2vResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="v2vGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="v2vGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="v2vGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="v2vGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="v2vGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-v2v -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- FASHION TRY-ON ───────────────────────────────────────── -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-fashn">

        <div class="panel-header ph-fashn">
          <span class="panel-header-icon">👗</span>
          <div>
            <div class="panel-header-title">Fashion Try-On</div>
            <div class="panel-header-sub">Upload a model photo and a garment photo — fashn composites the garment onto the model. Garment Type below is the single most important setting: pick it wrong and a full dress renders cropped at the waist like a blouse.</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-flux" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#d946ef,#a21caf);">
            <span>👗 fashn / tryon v1.6 — Fashion Try-On</span>
            <span id="fashnCostBadge" style="font-size:11px;background:rgba(255,255,255,.18);padding:3px 10px;border-radius:10px;font-weight:600;">— cr</span>
          </div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Model Photo</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('fashnModelInput').click()">⬆️ Upload Model Photo</button>
                <button type="button" class="audio-btn" id="fashnModelClearBtn" onclick="clearFashnModelImage()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="fashnModelInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleFashnModelUpload(this)">
              <div id="fashnModelPreviewWrap" style="display:none;margin-top:8px;">
                <img id="fashnModelPreview" src="" alt="Model preview" style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Garment Photo</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('fashnGarmentInput').click()">⬆️ Upload Garment Photo</button>
                <button type="button" class="audio-btn" id="fashnGarmentClearBtn" onclick="clearFashnGarmentImage()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="fashnGarmentInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleFashnGarmentUpload(this)">
              <div id="fashnGarmentPreviewWrap" style="display:none;margin-top:8px;">
                <img id="fashnGarmentPreview" src="" alt="Garment preview" style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Garment Type — required, get this right</label>
              <select class="field-input" id="fashnCategory" style="cursor:pointer;">
                <option value="" selected disabled>— Select the garment's actual shape —</option>
                <option value="one-pieces">👗 Full / One-Piece — dress, gown, jumpsuit, abaya, maxi</option>
                <option value="tops">👕 Top — shirt, blouse, jacket (covers upper body only)</option>
                <option value="bottoms">👖 Bottom — pants, skirt, shorts</option>
              </select>
              <div style="font-size:11px;color:#dc2626;font-weight:600;margin-top:6px;">⚠ A full-length dress picked as "Top" gets rendered cropped at the waist like a blouse. If the garment is one single piece covering top-to-bottom, it's "Full / One-Piece" — not "Top".</div>
            </div>

            <div class="field-group">
              <label class="field-label">Garment Photo Type</label>
              <select class="field-input" id="fashnGarmentPhotoType" style="cursor:pointer;">
                <option value="flat-lay" selected>Flat-lay / isolated — on a hanger, mannequin, or plain background</option>
                <option value="model">Worn on a person in the source photo</option>
              </select>
            </div>

            <button class="gen-btn btn-flux" id="fashnBtn" onclick="generateFashnTryon()" style="background:linear-gradient(135deg,#d946ef,#a21caf);">
              ⚡ Generate Try-On
            </button>

            <div class="result-area" id="fashnResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="fashnGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="fashnGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="fashnGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="fashnGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="fashnGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-fashn -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- PANEL: Mannequin → Model                              -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div class="tool-panel" id="panel-mannequin">

        <div class="panel-header ph-fashn">
          <span class="panel-header-icon">🪆→🧍</span>
          <div>
            <div class="panel-header-title">Mannequin → Model</div>
            <div class="panel-header-sub">Upload your existing mannequin / ghost-mannequin / product photo as-is. The garment is kept untouched — only the mannequin/body is replaced with a realistic human model. Best for complex, embroidered, asymmetric, or multi-layer garments that keep coming out wrong with regular try-on.</div>
          </div>
        </div>

        <div class="gen-box-full">
          <div class="gen-box-head gh-flux" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#d946ef,#a21caf);">
            <span>🪆→🧍 nano-banana-2/edit — Mannequin → Model</span>
            <span id="mannequinCostBadge" style="font-size:11px;background:rgba(255,255,255,.18);padding:3px 10px;border-radius:10px;font-weight:600;">— cr</span>
          </div>
          <div class="gen-box-body">

            <div class="field-group">
              <label class="field-label">Mannequin / Product Photo</label>
              <div style="display:flex;gap:8px;">
                <button type="button" class="audio-btn" onclick="document.getElementById('mannequinInput').click()">⬆️ Upload Photo</button>
                <button type="button" class="audio-btn" id="mannequinClearBtn" onclick="clearMannequinImage()" style="display:none;">✕ Remove</button>
              </div>
              <input type="file" id="mannequinInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleMannequinUpload(this)">
              <div id="mannequinPreviewWrap" style="display:none;margin-top:8px;">
                <img id="mannequinPreview" src="" alt="Source preview" style="max-width:100%;max-height:220px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              </div>
              <div style="font-size:11px;color:#64748b;margin-top:6px;">Upload the full, uncropped photo — the model needs to see the mannequin's pose to know where to place the body.</div>
            </div>

            <div class="field-group">
              <label class="field-label">Model — quick pick</label>
              <select class="field-input" id="mannequinModelPreset" style="cursor:pointer;" onchange="applyMannequinModelPreset()">
                <option value="" selected disabled>— Choose an ethnicity to fill the description below —</option>
                <option value="a Pakistani woman, around 25 years old, golden brown hair, brightly smiling, not looking directly at the camera">🇵🇰 Pakistani</option>
                <option value="an Indian woman, around 25 years old, dark brown hair, brightly smiling, not looking directly at the camera">🇮🇳 Indian</option>
                <option value="an American woman, around 25 years old, light brown hair, brightly smiling, not looking directly at the camera">🇺🇸 American</option>
                <option value="an African woman, around 25 years old, black hair, brightly smiling, not looking directly at the camera">🌍 African</option>
                <option value="a Middle Eastern woman, around 25 years old, dark hair, brightly smiling, not looking directly at the camera">Middle Eastern</option>
                <option value="an East Asian woman, around 25 years old, black hair, brightly smiling, not looking directly at the camera">East Asian</option>
                <option value="a Latina woman, around 25 years old, dark brown hair, brightly smiling, not looking directly at the camera">Latina</option>
              </select>
              <div style="font-size:11px;color:#64748b;margin-top:6px;">Picking one fills the text box below — edit freely after (age, hair color, expression, gaze direction, anything else).</div>
            </div>

            <div class="field-group">
              <label class="field-label">Model Description</label>
              <textarea class="field-input" id="mannequinModelDescription" rows="2" placeholder="e.g. a Pakistani woman, around 25 years old, golden brown hair, brightly smiling, not looking directly at the camera"></textarea>
              <div style="font-size:11px;color:#64748b;margin-top:6px;">Leave blank to use the Pakistani default shown above. This is the ONE thing you'll change between generations — ethnicity, age, hair, expression, gaze — the garment-preservation instructions stay fixed automatically, no extra prompt needed.</div>
            </div>

            <div class="field-group">
              <label class="field-label">Output Resolution</label>
              <select class="field-input" id="mannequinResolution" style="cursor:pointer;">
                <option value="1K" selected>1K — standard, lower cost (recommended default)</option>
                <option value="2K">2K — sharper fine detail (embroidery, fine print) — costs ~1.5×</option>
              </select>
            </div>

            <div class="field-group" style="display:flex;align-items:center;gap:8px;">
              <input type="checkbox" id="mannequinRemoveBg" style="width:16px;height:16px;cursor:pointer;">
              <label for="mannequinRemoveBg" style="font-size:13px;font-weight:600;cursor:pointer;margin:0;">🪄 Also remove background after generating (transparent PNG) — <span id="bgRemoveCostInline">+— cr</span></label>
            </div>

            <button class="gen-btn btn-flux" id="mannequinBtn" onclick="generateMannequinToModel()" style="background:linear-gradient(135deg,#d946ef,#a21caf);">
              ⚡ Generate
            </button>

            <div class="result-area" id="mannequinResult"></div>

            <div class="media-gallery">
              <div class="media-gallery-head">
                <span class="media-gallery-title">📁 Your Recent Generations</span>
                <span class="media-gallery-count" id="mannequinGalleryCount"></span>
              </div>
              <div class="gallery-grid" id="mannequinGalleryGrid">
                <p style="color:#94a3b8;font-size:12px;">Loading…</p>
              </div>
              <div class="gallery-pager">
                <button type="button" class="audio-btn" id="mannequinGalleryPrevBtn" onclick="changeGalleryPage(-1)">← Prev</button>
                <span id="mannequinGalleryPageInfo">Page 1</span>
                <button type="button" class="audio-btn" id="mannequinGalleryNextBtn" onclick="changeGalleryPage(1)">Next →</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-mannequin -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- Video to Video — Audio Library Modal                  -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div id="v2vAudioLibraryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:14px;padding:20px;width:90%;max-width:420px;max-height:70vh;display:flex;flex-direction:column;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <strong style="font-size:15px;color:var(--dark-blue);">🎵 Audio Library</strong>
            <button type="button" onclick="closeAudioLibrary('v2v')" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
          </div>
          <div id="v2vAudioLibraryList" style="overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:8px;">
            <p style="color:#94a3b8;font-size:13px;">Loading…</p>
          </div>
        </div>
      </div>

    </div><!-- /wiz-card-body -->
  </div><!-- /wiz-card -->
</div><!-- /page-wrap -->

<!-- ── Gallery preview modal — Close / Download / Delete ────────── -->
<div id="galleryPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:10000;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:14px;padding:18px;width:100%;max-width:480px;max-height:88vh;display:flex;flex-direction:column;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;">
      <strong id="galleryPreviewName" style="font-size:13px;color:var(--dark-blue);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></strong>
      <button type="button" onclick="closeGalleryModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;flex-shrink:0;">✕</button>
    </div>
    <div id="galleryPreviewMedia" style="flex:1;overflow:auto;display:flex;align-items:center;justify-content:center;background:#0f172a;border-radius:10px;min-height:200px;"></div>
    <div id="galleryPreviewStatus" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;font-size:12px;text-align:center;font-weight:600;"></div>
    <div style="display:flex;gap:8px;margin-top:14px;">
      <button type="button" class="audio-btn" onclick="closeGalleryModal()" style="flex:1;">Close</button>
      <button type="button" class="audio-btn" id="galleryDownloadBtn" onclick="downloadGalleryItem()" style="flex:1;">⬇ Download</button>
      <button type="button" class="audio-btn" id="galleryDeleteBtn" onclick="deleteGalleryItem()" style="flex:1;color:#dc2626;border-color:#fecaca;">🗑 Delete</button>
    </div>
  </div>
</div>

<!-- ── Audio Library Modal (Text to Video — background audio) ──── -->
<div id="t2vAudioLibraryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:20px;width:90%;max-width:420px;max-height:70vh;display:flex;flex-direction:column;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <strong style="font-size:15px;color:var(--dark-blue);">🎵 Audio Library</strong>
      <button type="button" onclick="closeAudioLibrary('t2v')" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">✕</button>
    </div>
    <div id="t2vAudioLibraryList" style="overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:8px;">
      <p style="color:#94a3b8;font-size:13px;">Loading…</p>
    </div>
  </div>
</div>

<script>
// ── Credit state ───────────────────────────────────────────────
let creditBalance   = null;
let costT2I         = null;
let costI2I         = 10;
let costFashn       = 15;
let costMannequin   = 8;
let costMannequin2K = 12;
let costBgRemove    = 4;
let costT2VPerSec   = null; // per-second rate from server
let costs           = {};   // raw costs object from server

// ── Load credits on page load ──────────────────────────────────
async function loadCredits() {
  try {
    const fd = new FormData();
    fd.append('action', 'get_credit_balance');
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      costs         = data;
      costT2I       = data.cost_t2i;
      costI2I       = data.cost_i2i;
      costFashn     = data.cost_fashn;
      costMannequin = data.cost_mannequin;
      costMannequin2K = data.cost_mannequin_2k;
      costBgRemove  = data.cost_bgremove;
      updateBalanceDisplay(data.balance);
      updateCostBadges();
    }
  } catch (e) { /* silent — non-critical */ }
}

function updateBalanceDisplay(balance) {
  creditBalance = balance;
  const el = document.getElementById('creditBalanceVal');
  if (el) el.textContent = balance !== null ? Number(balance).toLocaleString() : '—';
}

function updateCostBadges() {
  const t2i = document.getElementById('t2iCostBadge');
  if (t2i && costT2I !== null) t2i.textContent = costT2I + ' cr';
  const i2i = document.getElementById('i2iCostBadge');
  if (i2i && costI2I !== null) i2i.textContent = costI2I + ' cr';
  const fashn = document.getElementById('fashnCostBadge');
  if (fashn && costFashn !== null) fashn.textContent = costFashn + ' cr';
  const mannequin = document.getElementById('mannequinCostBadge');
  if (mannequin && costMannequin !== null) mannequin.textContent = costMannequin + ' cr';
  const bgInline = document.getElementById('bgRemoveCostInline');
  if (bgInline && costBgRemove !== null) bgInline.textContent = '+' + costBgRemove + ' cr';
  updateT2VCost();
}

function updateT2VCost() {
  const falBadge   = document.getElementById('t2vFalCostBadge');
  const modalBadge = document.getElementById('t2vModalCostBadge');
  if (falBadge   && costs.cost_t2v_fal   !== undefined) falBadge.textContent   = costs.cost_t2v_fal   + ' cr';
  if (modalBadge && costs.cost_t2v_modal !== undefined) modalBadge.textContent = costs.cost_t2v_modal + ' cr';

  const i2vFalBadge   = document.getElementById('i2vFalCostBadge');
  const i2vModalBadge = document.getElementById('i2vModalCostBadge');
  if (i2vFalBadge   && costs.cost_i2v_fal   !== undefined) i2vFalBadge.textContent   = costs.cost_i2v_fal   + ' cr';
  if (i2vModalBadge && costs.cost_i2v_modal !== undefined) i2vModalBadge.textContent = costs.cost_i2v_modal + ' cr';

  const v2vFalBadge   = document.getElementById('v2vFalCostBadge');
  const v2vModalBadge = document.getElementById('v2vModalCostBadge');
  if (v2vFalBadge   && costs.cost_v2v_fal   !== undefined) v2vFalBadge.textContent   = costs.cost_v2v_fal   + ' cr';
  if (v2vModalBadge && costs.cost_v2v_modal !== undefined) v2vModalBadge.textContent = costs.cost_v2v_modal + ' cr';
}

function refreshBalanceAfterGeneration(newBalance) {
  if (newBalance !== null && newBalance !== undefined) {
    updateBalanceDisplay(newBalance);
  } else {
    loadCredits(); // re-fetch if not returned
  }
}

// ── Credit check helper ────────────────────────────────────────
function hasEnoughCredits(cost) {
  if (creditBalance === null) return true; // unknown — let server decide
  return creditBalance >= cost;
}

// ── Tab switching ──────────────────────────────────────────────
function switchTool(tool) {
  document.querySelectorAll('.tool-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tool-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('.tool-tab.tab-' + tool).classList.add('active');
  document.getElementById('panel-' + tool).classList.add('active');
}

// ── Text-to-Image (FAL AI only) ────────────────────────────────
async function generateT2I() {
  const prompt    = document.getElementById('t2iPrompt').value.trim();
  const imageSize = document.getElementById('t2iImageSize').value;
  const enhance   = document.getElementById('t2iEnhance').checked;
  const resultDiv = document.getElementById('t2iResult');
  const btn       = document.getElementById('t2iBtn');

  if (!prompt) { resultDiv.innerHTML = '<div class="result-error">Please enter a prompt first.</div>'; return; }

  if (costT2I !== null && !hasEnoughCredits(costT2I)) {
    resultDiv.innerHTML = `<div class="result-error">❌ Insufficient credits. This action costs <strong>${costT2I} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  btn.disabled = true;
  const origHTML = btn.innerHTML;
  btn.innerHTML  = '<span class="spinner"></span> Generating…';
  resultDiv.innerHTML = '<div class="result-loading">⏳ Generating image with FAL AI, please wait…</div>';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',     'generate_fal');
    fd.append('prompt',     prompt);
    fd.append('enhance',    enhance ? 'true' : 'false');
    fd.append('image_size', imageSize);

    const res         = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const contentType = res.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) throw new Error('Server error (HTTP ' + res.status + ') — check PHP logs.');
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const imgUrl = data.public_url || data.fal_url;
      const dlName = data.filename   || 'fal_image.jpg';

      const enhancedSection = (enhance && data.enhanced_prompt)
        ? `<div style="margin-top:10px;">
            <button onclick="var p=this.nextElementSibling;var o=p.style.display!=='none';p.style.display=o?'none':'block';this.textContent=o?'▶ Show enhanced prompt':'▼ Hide enhanced prompt';"
              style="font-size:11px;font-weight:700;color:var(--purple);background:var(--purple-lt);border:1px solid var(--purple);border-radius:6px;padding:4px 10px;cursor:pointer;font-family:inherit;">▶ Show enhanced prompt</button>
            <div style="display:none;margin-top:6px;padding:10px 12px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;font-size:12px;color:#4c1d95;line-height:1.6;white-space:pre-wrap;">${escHtml(data.enhanced_prompt)}</div>
           </div>` : '';

      // ── Debug panel (save path diagnostics) ──
      const dbg = data.debug || {};
      const savedOk = data.filepath && dbg.write_result > 0;
      const debugHtml = `
        <details style="margin-top:10px;" ${savedOk ? '' : 'open'}>
          <summary style="font-size:11px;font-weight:700;cursor:pointer;color:var(--muted);padding:4px 0;">
            ${savedOk ? '✅ File saved' : '⚠️ File NOT saved — click to see details'}
          </summary>
          <div style="margin-top:6px;background:#f1f5f9;border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:11px;font-family:monospace;line-height:1.9;color:#334155;">
            <b>Save dir:</b> ${escHtml(dbg.save_dir || '—')}<br>
            <b>Dir exists:</b> ${dbg.dir_exists}<br>
            <b>Dir writable:</b> ${dbg.dir_writable}<br>
            <b>mkdir result:</b> ${'mkdir_result' in dbg ? dbg.mkdir_result : 'n/a (dir existed)'}<br>
            <b>mkdir error:</b> ${escHtml(dbg.mkdir_error || '—')}<br>
            <b>CDN download HTTP:</b> ${dbg.fal_cdn_http || '—'}<br>
            <b>CDN download bytes:</b> ${dbg.image_bytes || 0}<br>
            <b>CDN error:</b> ${escHtml(dbg.fal_cdn_err || '—')}<br>
            <b>Write path:</b> ${escHtml(dbg.write_path || '—')}<br>
            <b>Write result:</b> ${dbg.write_result !== undefined ? dbg.write_result : '—'}<br>
            <b>Write error:</b> ${escHtml(dbg.write_error || '—')}<br>
            <b>PHP process user:</b> ${escHtml(dbg.php_user || '—')}<br>
            <b>Saved filepath:</b> ${escHtml(data.filepath || 'not saved locally')}<br>
            <b>Public URL:</b> ${escHtml(data.public_url || data.fal_url || '—')}
          </div>
        </details>`;

      resultDiv.innerHTML = `
        <div style="margin-bottom:10px;">
          <img id="t2iResultImg" src="${escHtml(imgUrl)}" alt="Generated image"
               style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
          <div id="t2iImgErr" style="display:none;padding:10px;background:#fef2f2;border-radius:8px;font-size:12px;color:#dc2626;margin-top:6px;">
            ⚠️ Preview unavailable (CDN link expired). Use Download button below.
          </div>
        </div>
        <div class="result-info">
          <strong>✅ FAL AI / FLUX Dev</strong> &nbsp;·&nbsp; ${secs}s
          ${data.size ? `<br><strong>Size:</strong> ${escHtml(data.size)}` : ''}
          ${data.seed ? `<br><strong>Seed:</strong> ${data.seed}`         : ''}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? costT2I} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        ${debugHtml}
        ${enhancedSection}
        <a href="${escHtml(imgUrl)}" download="${escHtml(dlName)}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#f8f0ff;border:1.5px solid var(--purple);border-radius:10px;color:var(--purple);font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Image
        </a>`;

      // Attach onerror after DOM insert — never show raw URLs
      const imgEl = document.getElementById('t2iResultImg');
      if (imgEl) imgEl.onerror = function() {
        this.style.display = 'none';
        document.getElementById('t2iImgErr').style.display = 'block';
      };

      resultDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      // Show error + debug block if present
      const dbg = data.debug || {};
      const hasDebug = Object.keys(dbg).length > 0;
      const debugHtml = hasDebug ? `
        <details open style="margin-top:8px;">
          <summary style="font-size:11px;font-weight:700;cursor:pointer;color:#dc2626;">🔍 Save diagnostics</summary>
          <div style="margin-top:6px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:11px;font-family:monospace;line-height:1.9;color:#7f1d1d;">
            <b>Save dir:</b> ${escHtml(dbg.save_dir || '—')}<br>
            <b>Dir exists:</b> ${dbg.dir_exists}<br>
            <b>Dir writable:</b> ${dbg.dir_writable}<br>
            <b>mkdir result:</b> ${'mkdir_result' in dbg ? dbg.mkdir_result : 'n/a'}<br>
            <b>mkdir error:</b> ${escHtml(dbg.mkdir_error || '—')}<br>
            <b>CDN HTTP:</b> ${dbg.fal_cdn_http || '—'}<br>
            <b>CDN bytes:</b> ${dbg.image_bytes || 0}<br>
            <b>CDN error:</b> ${escHtml(dbg.fal_cdn_err || '—')}<br>
            <b>Write path:</b> ${escHtml(dbg.write_path || '—')}<br>
            <b>Write error:</b> ${escHtml(dbg.write_error || '—')}<br>
            <b>PHP user:</b> ${escHtml(dbg.php_user || '—')}
            ${data.fal_url ? `<br><br><a href="${escHtml(data.fal_url)}" target="_blank" style="color:#7c3aed;">Open image on FAL CDN ↗</a>` : ''}
          </div>
        </details>` : '';
      resultDiv.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>${debugHtml}`;
    }
  } catch (e) {
    resultDiv.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

// ── Background audio selection (optional) — shared by t2v / i2v panels ──
// `prefix` is 't2v' or 'i2v', matching the DOM id prefixes in each panel.
async function handleAudioUpload(prefix, input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('action', 'upload_audio');
  fd.append('audio_file', file);
  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      setAudioSelection(prefix, data.file, data.name);
    } else {
      alert(data.error || 'Audio upload failed');
    }
  } catch (e) {
    alert(e.message);
  } finally {
    input.value = '';
  }
}

function setAudioSelection(prefix, filePath, fileName) {
  document.getElementById(prefix + 'SelectedAudioPath').value = filePath;
  document.getElementById(prefix + 'AudioName').textContent    = fileName;
  document.getElementById(prefix + 'AudioPlayer').src           = filePath;
  document.getElementById(prefix + 'AudioPreviewWrap').style.display = 'block';
  document.getElementById(prefix + 'AudioClearBtn').style.display    = 'inline-block';
  closeAudioLibrary(prefix);
}

function clearAudioSelection(prefix) {
  document.getElementById(prefix + 'SelectedAudioPath').value = '';
  document.getElementById(prefix + 'AudioPlayer').src = '';
  document.getElementById(prefix + 'AudioPreviewWrap').style.display = 'none';
  document.getElementById(prefix + 'AudioClearBtn').style.display    = 'none';
}

async function openAudioLibrary(prefix) {
  const modal = document.getElementById(prefix + 'AudioLibraryModal');
  modal.style.display = 'flex';
  const list = document.getElementById(prefix + 'AudioLibraryList');
  list.innerHTML = '<p style="color:#94a3b8;font-size:13px;">Loading…</p>';
  try {
    const fd = new FormData();
    fd.append('action', 'list_audio_library');
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.files && data.files.length) {
      list.innerHTML = data.files.map(f => `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;">
          <span style="font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(f.name)}</span>
          <button type="button" class="lib-select-btn" onclick="setAudioSelection('${prefix}','${escHtml(f.file)}','${escHtml(f.name)}')">Select</button>
        </div>`).join('');
    } else {
      list.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No audio files found in the library.</p>';
    }
  } catch (e) {
    list.innerHTML = '<p style="color:#dc2626;font-size:13px;">Failed to load library.</p>';
  }
}

function closeAudioLibrary(prefix) {
  document.getElementById(prefix + 'AudioLibraryModal').style.display = 'none';
}

// ── Engine provider card selector — shared by t2v / i2v panels ──
const providerState = { t2v: 'fal', i2v: 'fal', v2v: 'fal' };

function selectEngineProvider(prefix, p) {
  providerState[prefix] = p;
  document.getElementById(prefix + 'CardFal').classList.toggle('selected', p === 'fal');
  document.getElementById(prefix + 'CardModal').classList.toggle('selected', p === 'modal');
  const aspectGroup = document.getElementById(prefix + 'AspectGroup');
  if (aspectGroup) aspectGroup.style.display = p === 'fal' ? '' : 'none';
  document.getElementById(prefix + 'EngineNote').textContent = p === 'fal'
    ? '⏱️ Fixed at 6 seconds · Generated instantly, no waiting'
    : '⏱️ Fixed at 6 seconds · Self-hosted on Modal — joins a queue, cheaper but not instant';
  const btn = document.getElementById(prefix + 'Btn');
  btn.innerHTML = p === 'fal' ? '⚡ Generate Video' : '🕐 Queue Video';
  btn.style.background = p === 'fal' ? '' : 'linear-gradient(135deg,#10b981,#059669)';
  btn.className = 'gen-btn' + (p === 'fal' ? ' btn-orange' : '');
}

// ── Text-to-Video: single dispatcher based on selected provider ─
async function generateT2V() {
  if (providerState.t2v === 'fal') return generateT2VFalRun();
  return generateT2VModalRun();
}

async function generateT2VFalRun() {
  const prompt  = document.getElementById('t2vPrompt').value.trim();
  const aspect  = document.getElementById('t2vAspect').value;
  const enhance = document.getElementById('t2vEnhance').checked;
  const btn     = document.getElementById('t2vBtn');
  const result  = document.getElementById('t2vResult');

  if (!prompt) { result.innerHTML = '<div class="result-error">Please enter a prompt first.</div>'; return; }

  const cost = costs.cost_t2v_fal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating… (~30–90s)';
  result.innerHTML = '';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',       'generate_video_fal');
    fd.append('prompt',       prompt);
    fd.append('aspect_ratio', aspect);
    fd.append('enhance',      enhance ? 'true' : 'false');
    fd.append('audio_file',   document.getElementById('t2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const enhancedSection = data.enhanced_prompt
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fff7ed;border-radius:8px;font-size:11px;color:#92400e;"><strong>✨ Enhanced prompt:</strong> ${escHtml(data.enhanced_prompt)}</div>` : '';
      const audioSection = data.audio_note
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>` : '';
      result.innerHTML = `
        <div style="margin-bottom:10px;">
          <video src="${escHtml(data.public_url)}" controls playsinline
            style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
          </video>
        </div>
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / LTX 2.3')}</strong> &nbsp;·&nbsp; ${secs}s
          <br><strong>Aspect:</strong> ${escHtml(data.aspect_ratio || aspect)} &nbsp;·&nbsp; <strong>Duration:</strong> ${data.duration || 6}s
          ${data.audio_muxed ? '<br><strong>🎵 Audio:</strong> merged into video' : ''}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? cost ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        ${enhancedSection}
        ${audioSection}
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'video.mp4')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;color:#92400e;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Video
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

async function generateT2VModalRun() {
  const prompt  = document.getElementById('t2vPrompt').value.trim();
  const enhance = document.getElementById('t2vEnhance').checked;
  const btn     = document.getElementById('t2vBtn');
  const banner  = document.getElementById('t2vBanner');
  const result  = document.getElementById('t2vResult');

  if (!prompt) { result.innerHTML = '<div class="result-error">Please enter a prompt first.</div>'; return; }

  const cost = costs.cost_t2v_modal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Queuing…';
  banner.style.display = 'flex';
  banner.className = 'wan-banner';
  document.getElementById('t2vBannerText').textContent = 'Queuing video…';
  result.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action',     'generate_video_modal_queue');
    fd.append('prompt',     prompt);
    fd.append('enhance',    enhance ? 'true' : 'false');
    fd.append('audio_file', document.getElementById('t2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.queue_id) {
      refreshBalanceAfterGeneration(data.credits_balance);
      document.getElementById('t2vBannerText').textContent =
        `Queued (position ${data.queue_position || 1}) — LTX 2.3 is generating your video, this can take a few minutes…`;
      btn.innerHTML = '<span class="spinner"></span> Processing…';
      if (data.audio_note) {
        result.innerHTML = `<div style="padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>`;
      }
      pollModalQueue('t2v', data.queue_id, data.credits_used ?? cost);
    } else {
      banner.style.display = 'none';
      btn.disabled  = false;
      btn.innerHTML = '🕐 Queue Video';
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    banner.style.display = 'none';
    btn.disabled  = false;
    btn.innerHTML = '🕐 Queue Video';
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  }
}

async function pollModalQueue(prefix, queueId, creditsUsed) {
  const btn     = document.getElementById(prefix + 'Btn');
  const banner  = document.getElementById(prefix + 'Banner');
  const result  = document.getElementById(prefix + 'Result');
  const startTime = Date.now();
  const maxWaitMs = 8 * 60 * 1000; // give up updating the banner text past 8 min, but keep polling

  const tick = async () => {
    try {
      const fd = new FormData();
      fd.append('action',   'check_video_queue_status');
      fd.append('queue_id', queueId);
      const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.success) {
        banner.style.display = 'none';
        btn.disabled  = false;
        btn.innerHTML = '🕐 Queue Video';
        result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Lost track of this job')}</div>`;
        return;
      }

      if (data.status === 'done' && data.public_url) {
        banner.style.display = 'none';
        btn.disabled  = false;
        btn.innerHTML = '🕐 Queue Video';
        refreshGalleryAfterGeneration();
        const secs = Math.round((Date.now() - startTime) / 1000);
        result.innerHTML = `
          <div style="margin-bottom:10px;">
            <video src="${escHtml(data.public_url)}" controls playsinline
              style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
            </video>
          </div>
          <div class="result-info">
            <strong>✅ Modal.com / LTX 2.3</strong> &nbsp;·&nbsp; ${secs}s in queue
            <br><strong>💎 Credits used:</strong> ${creditsUsed ?? '—'}
          </div>
          <a href="${escHtml(data.public_url)}" download target="_blank"
            style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#ecfdf5;border:1.5px solid #a7f3d0;border-radius:10px;color:#065f46;font-size:13px;font-weight:700;text-decoration:none;">
            ⬇ Download Video
          </a>`;
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }

      // Still queued/processing — keep polling
      const elapsed = Date.now() - startTime;
      const label   = data.status === 'processing' ? 'Generating with LTX 2.3 on Modal…' : `Queued (position ${data.queue_position || 1})…`;
      document.getElementById(prefix + 'BannerText').textContent =
        elapsed < maxWaitMs ? label : `${label} (taking longer than usual — still working on it)`;
      setTimeout(tick, 6000);
    } catch (e) {
      // Network hiccup — keep trying rather than giving up
      setTimeout(tick, 8000);
    }
  };
  setTimeout(tick, 6000);
}

// ── Image-to-Video: source image upload (client-side preview only — ─────
// the actual file rides along in the FormData when generating, no separate
// upload round-trip needed since fal accepts a base64 data URI directly).
let i2vSelectedImageFile = null;

function handleI2VImageUpload(input) {
  const file = input.files[0];
  if (!file) return;
  i2vSelectedImageFile = file;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('i2vImagePreview').src = e.target.result;
    document.getElementById('i2vImagePreviewWrap').style.display = 'block';
    document.getElementById('i2vImageClearBtn').style.display = 'inline-block';
  };
  reader.readAsDataURL(file);
}

function clearI2VImage() {
  i2vSelectedImageFile = null;
  document.getElementById('i2vImageInput').value = '';
  document.getElementById('i2vImagePreview').src = '';
  document.getElementById('i2vImagePreviewWrap').style.display = 'none';
  document.getElementById('i2vImageClearBtn').style.display = 'none';
}

// ── Image-to-Video: single dispatcher based on selected provider ────────
async function generateI2V() {
  if (providerState.i2v === 'fal') return generateI2VFalRun();
  return generateI2VModalRun();
}

async function generateI2VFalRun() {
  const prompt  = document.getElementById('i2vPrompt').value.trim();
  const aspect  = document.getElementById('i2vAspect').value;
  const enhance = document.getElementById('i2vEnhance').checked;
  const btn     = document.getElementById('i2vBtn');
  const result  = document.getElementById('i2vResult');

  if (!i2vSelectedImageFile) { result.innerHTML = '<div class="result-error">Please upload a source image first.</div>'; return; }
  if (!prompt) { result.innerHTML = '<div class="result-error">Please describe how the image should animate.</div>'; return; }

  const cost = costs.cost_i2v_fal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating… (~30–90s)';
  result.innerHTML = '';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',       'generate_image_video_fal');
    fd.append('image',        i2vSelectedImageFile);
    fd.append('prompt',       prompt);
    fd.append('aspect_ratio', aspect);
    fd.append('enhance',      enhance ? 'true' : 'false');
    fd.append('audio_file',   document.getElementById('i2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const enhancedSection = data.enhanced_prompt
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fff7ed;border-radius:8px;font-size:11px;color:#92400e;"><strong>✨ Enhanced prompt:</strong> ${escHtml(data.enhanced_prompt)}</div>` : '';
      const audioSection = data.audio_note
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>` : '';
      result.innerHTML = `
        <div style="margin-bottom:10px;">
          <video src="${escHtml(data.public_url)}" controls playsinline
            style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
          </video>
        </div>
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / LTX 2.3')}</strong> &nbsp;·&nbsp; ${secs}s
          <br><strong>Aspect:</strong> ${escHtml(data.aspect_ratio || aspect)} &nbsp;·&nbsp; <strong>Duration:</strong> ${data.duration || 6}s
          ${data.audio_muxed ? '<br><strong>🎵 Audio:</strong> merged into video' : ''}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? cost ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        ${enhancedSection}
        ${audioSection}
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'video.mp4')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#ecfdf5;border:1.5px solid #a7f3d0;border-radius:10px;color:#065f46;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Video
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

async function generateI2VModalRun() {
  const prompt  = document.getElementById('i2vPrompt').value.trim();
  const enhance = document.getElementById('i2vEnhance').checked;
  const btn     = document.getElementById('i2vBtn');
  const banner  = document.getElementById('i2vBanner');
  const result  = document.getElementById('i2vResult');

  if (!i2vSelectedImageFile) { result.innerHTML = '<div class="result-error">Please upload a source image first.</div>'; return; }
  if (!prompt) { result.innerHTML = '<div class="result-error">Please describe how the image should animate.</div>'; return; }

  const cost = costs.cost_i2v_modal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Queuing…';
  banner.style.display = 'flex';
  banner.className = 'wan-banner';
  document.getElementById('i2vBannerText').textContent = 'Queuing video…';
  result.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action',     'generate_image_video_modal_queue');
    fd.append('image',      i2vSelectedImageFile);
    fd.append('prompt',     prompt);
    fd.append('enhance',    enhance ? 'true' : 'false');
    fd.append('audio_file', document.getElementById('i2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.queue_id) {
      refreshBalanceAfterGeneration(data.credits_balance);
      document.getElementById('i2vBannerText').textContent =
        `Queued (position ${data.queue_position || 1}) — LTX 2.3 is generating your video, this can take a few minutes…`;
      btn.innerHTML = '<span class="spinner"></span> Processing…';
      if (data.audio_note) {
        result.innerHTML = `<div style="padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>`;
      }
      pollModalQueue('i2v', data.queue_id, data.credits_used ?? cost);
    } else {
      banner.style.display = 'none';
      btn.disabled  = false;
      btn.innerHTML = '🕐 Queue Video';
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    banner.style.display = 'none';
    btn.disabled  = false;
    btn.innerHTML = '🕐 Queue Video';
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  }
}

// ── Video-to-Video: source video upload (client-side preview only) ──────
let v2vSelectedVideoFile = null;

function handleV2VVideoUpload(input) {
  const file = input.files[0];
  if (!file) return;
  v2vSelectedVideoFile = file;
  const url = URL.createObjectURL(file);
  document.getElementById('v2vVideoPreview').src = url;
  document.getElementById('v2vVideoPreviewWrap').style.display = 'block';
  document.getElementById('v2vVideoClearBtn').style.display = 'inline-block';
}

function clearV2VVideo() {
  v2vSelectedVideoFile = null;
  document.getElementById('v2vVideoInput').value = '';
  document.getElementById('v2vVideoPreview').src = '';
  document.getElementById('v2vVideoPreviewWrap').style.display = 'none';
  document.getElementById('v2vVideoClearBtn').style.display = 'none';
}

// ── Video-to-Video: single dispatcher based on selected provider ────────
async function generateV2V() {
  if (providerState.v2v === 'fal') return generateV2VFalRun();
  return generateV2VModalRun();
}

async function generateV2VFalRun() {
  const prompt  = document.getElementById('v2vPrompt').value.trim();
  const enhance = document.getElementById('v2vEnhance').checked;
  const btn     = document.getElementById('v2vBtn');
  const result  = document.getElementById('v2vResult');

  if (!v2vSelectedVideoFile) { result.innerHTML = '<div class="result-error">Please upload a source video first.</div>'; return; }
  if (!prompt) { result.innerHTML = '<div class="result-error">Please describe how the footage should change.</div>'; return; }

  const cost = costs.cost_v2v_fal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating… (~30–90s)';
  result.innerHTML = '';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',     'generate_video_video_fal');
    fd.append('video',      v2vSelectedVideoFile);
    fd.append('prompt',     prompt);
    fd.append('enhance',    enhance ? 'true' : 'false');
    fd.append('audio_file', document.getElementById('v2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const enhancedSection = data.enhanced_prompt
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fff7ed;border-radius:8px;font-size:11px;color:#92400e;"><strong>✨ Enhanced prompt:</strong> ${escHtml(data.enhanced_prompt)}</div>` : '';
      const audioSection = data.audio_note
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>` : '';
      result.innerHTML = `
        <div style="margin-bottom:10px;">
          <video src="${escHtml(data.public_url)}" controls playsinline
            style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
          </video>
        </div>
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / LTX 2.3')}</strong> &nbsp;·&nbsp; ${secs}s
          <br><strong>Duration:</strong> ${data.duration || 6}s
          ${data.audio_muxed ? '<br><strong>🎵 Audio:</strong> merged into video' : ''}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? cost ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        ${enhancedSection}
        ${audioSection}
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'video.mp4')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#fdf2f8;border:1.5px solid #fbcfe8;border-radius:10px;color:#be185d;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Video
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

async function generateV2VModalRun() {
  const prompt  = document.getElementById('v2vPrompt').value.trim();
  const enhance = document.getElementById('v2vEnhance').checked;
  const btn     = document.getElementById('v2vBtn');
  const banner  = document.getElementById('v2vBanner');
  const result  = document.getElementById('v2vResult');

  if (!v2vSelectedVideoFile) { result.innerHTML = '<div class="result-error">Please upload a source video first.</div>'; return; }
  if (!prompt) { result.innerHTML = '<div class="result-error">Please describe how the footage should change.</div>'; return; }

  const cost = costs.cost_v2v_modal ?? null;
  if (cost !== null && !hasEnoughCredits(cost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This video costs <strong>${cost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Queuing…';
  banner.style.display = 'flex';
  banner.className = 'wan-banner';
  document.getElementById('v2vBannerText').textContent = 'Queuing video…';
  result.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action',     'generate_video_video_modal_queue');
    fd.append('video',      v2vSelectedVideoFile);
    fd.append('prompt',     prompt);
    fd.append('enhance',    enhance ? 'true' : 'false');
    fd.append('audio_file', document.getElementById('v2vSelectedAudioPath').value || '');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.queue_id) {
      refreshBalanceAfterGeneration(data.credits_balance);
      document.getElementById('v2vBannerText').textContent =
        `Queued (position ${data.queue_position || 1}) — LTX 2.3 is generating your video, this can take a few minutes…`;
      btn.innerHTML = '<span class="spinner"></span> Processing…';
      if (data.audio_note) {
        result.innerHTML = `<div style="padding:8px 10px;background:#fef2f2;border-radius:8px;font-size:11px;color:#991b1b;">⚠️ ${escHtml(data.audio_note)}</div>`;
      }
      pollModalQueue('v2v', data.queue_id, data.credits_used ?? cost);
    } else {
      banner.style.display = 'none';
      btn.disabled  = false;
      btn.innerHTML = '🕐 Queue Video';
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    banner.style.display = 'none';
    btn.disabled  = false;
    btn.innerHTML = '🕐 Queue Video';
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  }
}

// ── Shared "Recent Generations" gallery — t2i/t2v/i2v/v2v all read the ──
// same per-user folder, so one fetch renders into all four panels at once.
let galleryPage       = 0;
let galleryTotalPages = 1;
let galleryItems      = []; // current page's items, indexed for the preview modal

async function loadMediaGallery() {
  try {
    const fd = new FormData();
    fd.append('action', 'list_media_gallery');
    fd.append('page',   galleryPage);
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) return;

    galleryPage       = data.page ?? galleryPage;
    galleryTotalPages = data.total_pages ?? 1;
    galleryItems       = data.files || [];

    const cardsHtml = galleryItems.length
      ? galleryItems.map((f, i) => `
          <div class="gallery-card" onclick="openGalleryModal(${i})" title="${escHtml(f.name)} — ${escHtml(f.date)}">
            ${f.type === 'video'
              ? `<video src="${escHtml(f.url)}" muted preload="metadata"></video><span class="gallery-play-badge">▶</span>`
              : `<img src="${escHtml(f.url)}" alt="${escHtml(f.name)}" loading="lazy">`}
            <button type="button" class="gallery-card-delete" onclick="event.stopPropagation(); quickDeleteGalleryItem(${i})" title="Delete">✕</button>
          </div>`).join('')
      : '<p style="color:#94a3b8;font-size:12px;grid-column:1/-1;">No generations yet — your media will show up here.</p>';

    ['t2i', 'i2i', 't2v', 'i2v', 'v2v', 'fashn', 'mannequin'].forEach(prefix => {
      const grid     = document.getElementById(prefix + 'GalleryGrid');
      const pageInfo = document.getElementById(prefix + 'GalleryPageInfo');
      const prevBtn  = document.getElementById(prefix + 'GalleryPrevBtn');
      const nextBtn  = document.getElementById(prefix + 'GalleryNextBtn');
      const countEl  = document.getElementById(prefix + 'GalleryCount');
      if (grid)     grid.innerHTML     = cardsHtml;
      if (pageInfo) pageInfo.textContent = `Page ${galleryPage + 1} of ${galleryTotalPages}`;
      if (prevBtn)  prevBtn.disabled  = galleryPage <= 0;
      if (nextBtn)  nextBtn.disabled  = galleryPage >= galleryTotalPages - 1;
      if (countEl)  countEl.textContent = data.total ? `${data.total} total` : '';
    });
  } catch (e) {
    // Gallery is a nice-to-have — fail silently rather than disrupt generation flow.
  }
}

// ── Gallery preview modal: Close / Download / Delete ────────────
let galleryModalIndex = null;

function openGalleryModal(index) {
  const item = galleryItems[index];
  if (!item) return;
  galleryModalIndex = index;

  document.getElementById('galleryPreviewName').textContent = item.name;
  document.getElementById('galleryPreviewMedia').innerHTML = item.type === 'video'
    ? `<video src="${escHtml(item.url)}" controls playsinline style="max-width:100%;max-height:60vh;border-radius:8px;"></video>`
    : `<img src="${escHtml(item.url)}" alt="${escHtml(item.name)}" style="max-width:100%;max-height:60vh;border-radius:8px;">`;

  const status = document.getElementById('galleryPreviewStatus');
  status.style.display = 'none';

  const dlBtn = document.getElementById('galleryDownloadBtn');
  const delBtn = document.getElementById('galleryDeleteBtn');
  dlBtn.disabled  = false; dlBtn.innerHTML  = '⬇ Download';
  delBtn.disabled = false; delBtn.innerHTML = '🗑 Delete';

  document.getElementById('galleryPreviewModal').style.display = 'flex';
}

function closeGalleryModal() {
  document.getElementById('galleryPreviewModal').style.display = 'none';
  galleryModalIndex = null;
}

function showGalleryStatus(message, ok) {
  const status = document.getElementById('galleryPreviewStatus');
  status.style.display = 'block';
  status.style.background = ok ? '#ecfdf5' : '#fef2f2';
  status.style.color      = ok ? '#065f46' : '#991b1b';
  status.textContent = message;
}

async function downloadGalleryItem() {
  const item = galleryItems[galleryModalIndex];
  if (!item) return;
  const btn = document.getElementById('galleryDownloadBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Downloading…';
  try {
    const res  = await fetch(item.url);
    const blob = await res.blob();
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = item.name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(blobUrl);
    showGalleryStatus('✅ Downloaded successfully', true);
    setTimeout(closeGalleryModal, 1200);
  } catch (e) {
    showGalleryStatus('❌ Download failed: ' + e.message, false);
    btn.disabled = false;
    btn.innerHTML = '⬇ Download';
  }
}

async function deleteGalleryItem() {
  const item = galleryItems[galleryModalIndex];
  if (!item) return;
  if (!confirm(`Delete "${item.name}"? This can't be undone.`)) return;

  const btn = document.getElementById('galleryDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Deleting…';
  try {
    const fd = new FormData();
    fd.append('action',   'delete_media');
    fd.append('filename', item.name);
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      showGalleryStatus('✅ Deleted successfully', true);
      loadMediaGallery();
      setTimeout(closeGalleryModal, 1200);
    } else {
      showGalleryStatus('❌ ' + (data.error || 'Delete failed'), false);
      btn.disabled = false;
      btn.innerHTML = '🗑 Delete';
    }
  } catch (e) {
    showGalleryStatus('❌ ' + e.message, false);
    btn.disabled = false;
    btn.innerHTML = '🗑 Delete';
  }
}

// Quick delete straight from the gallery card's "✕" — no need to open the
// preview modal first.
async function quickDeleteGalleryItem(index) {
  const item = galleryItems[index];
  if (!item) return;
  if (!confirm(`Delete "${item.name}"? This can't be undone.`)) return;

  try {
    const fd = new FormData();
    fd.append('action',   'delete_media');
    fd.append('filename', item.name);
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      loadMediaGallery(); // the item disappearing from the grid is the confirmation
    } else {
      alert(data.error || 'Delete failed');
    }
  } catch (e) {
    alert(e.message);
  }
}

function changeGalleryPage(delta) {
  const newPage = galleryPage + delta;
  if (newPage < 0 || newPage >= galleryTotalPages) return;
  galleryPage = newPage;
  loadMediaGallery();
}

function refreshGalleryAfterGeneration() {
  galleryPage = 0; // jump back to page 1 so the newest item is visible
  loadMediaGallery();
}

// ── Image-to-Image: source image upload (client-side preview only) ─────
let i2iSelectedImageFile = null;

function handleI2IImageUpload(input) {
  const file = input.files[0];
  if (!file) return;
  i2iSelectedImageFile = file;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('i2iImagePreview').src = e.target.result;
    document.getElementById('i2iImagePreviewWrap').style.display = 'block';
    document.getElementById('i2iImageClearBtn').style.display = 'inline-block';
  };
  reader.readAsDataURL(file);
}

function clearI2IImage() {
  i2iSelectedImageFile = null;
  document.getElementById('i2iImageInput').value = '';
  document.getElementById('i2iImagePreview').src = '';
  document.getElementById('i2iImagePreviewWrap').style.display = 'none';
  document.getElementById('i2iImageClearBtn').style.display = 'none';
}

async function generateI2I() {
  const prompt   = document.getElementById('i2iPrompt').value.trim();
  const strength = document.getElementById('i2iStrength').value;
  const enhance  = document.getElementById('i2iEnhance').checked;
  const btn      = document.getElementById('i2iBtn');
  const result   = document.getElementById('i2iResult');

  if (!i2iSelectedImageFile) { result.innerHTML = '<div class="result-error">Please upload a source image first.</div>'; return; }
  if (!prompt) { result.innerHTML = '<div class="result-error">Please describe the edit you want.</div>'; return; }

  if (costI2I !== null && !hasEnoughCredits(costI2I)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This action costs <strong>${costI2I} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating…';
  result.innerHTML = '<div class="result-loading">⏳ Editing image with FAL AI, please wait…</div>';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',   'generate_i2i_fal');
    fd.append('image',    i2iSelectedImageFile);
    fd.append('prompt',   prompt);
    fd.append('strength', strength);
    fd.append('enhance',  enhance ? 'true' : 'false');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const enhancedSection = data.enhanced_prompt
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fff7ed;border-radius:8px;font-size:11px;color:#92400e;"><strong>✨ Enhanced prompt:</strong> ${escHtml(data.enhanced_prompt)}</div>` : '';
      result.innerHTML = `
        <div style="margin-bottom:10px;">
          <img src="${escHtml(data.public_url)}" alt="Edited image"
            style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
        </div>
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / FLUX Dev')}</strong> &nbsp;·&nbsp; ${secs}s
          <br><strong>Strength:</strong> ${data.strength ?? strength}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? costI2I ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        ${enhancedSection}
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'image.jpg')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#e0f2fe;border:1.5px solid #bae6fd;border-radius:10px;color:#0369a1;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Image
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Fashion Try-On: model + garment image upload (client-side preview only) ─
let fashnSelectedModelFile   = null;
let fashnSelectedGarmentFile = null;

function handleFashnModelUpload(input) {
  const file = input.files[0];
  if (!file) return;
  fashnSelectedModelFile = file;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('fashnModelPreview').src = e.target.result;
    document.getElementById('fashnModelPreviewWrap').style.display = 'block';
    document.getElementById('fashnModelClearBtn').style.display = 'inline-block';
  };
  reader.readAsDataURL(file);
}

function clearFashnModelImage() {
  fashnSelectedModelFile = null;
  document.getElementById('fashnModelInput').value = '';
  document.getElementById('fashnModelPreview').src = '';
  document.getElementById('fashnModelPreviewWrap').style.display = 'none';
  document.getElementById('fashnModelClearBtn').style.display = 'none';
}

function handleFashnGarmentUpload(input) {
  const file = input.files[0];
  if (!file) return;
  fashnSelectedGarmentFile = file;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('fashnGarmentPreview').src = e.target.result;
    document.getElementById('fashnGarmentPreviewWrap').style.display = 'block';
    document.getElementById('fashnGarmentClearBtn').style.display = 'inline-block';
  };
  reader.readAsDataURL(file);
}

function clearFashnGarmentImage() {
  fashnSelectedGarmentFile = null;
  document.getElementById('fashnGarmentInput').value = '';
  document.getElementById('fashnGarmentPreview').src = '';
  document.getElementById('fashnGarmentPreviewWrap').style.display = 'none';
  document.getElementById('fashnGarmentClearBtn').style.display = 'none';
}

async function generateFashnTryon() {
  const category          = document.getElementById('fashnCategory').value;
  const garmentPhotoType  = document.getElementById('fashnGarmentPhotoType').value;
  const btn               = document.getElementById('fashnBtn');
  const result            = document.getElementById('fashnResult');

  if (!fashnSelectedModelFile)   { result.innerHTML = '<div class="result-error">Please upload a model photo first.</div>'; return; }
  if (!fashnSelectedGarmentFile) { result.innerHTML = '<div class="result-error">Please upload a garment photo first.</div>'; return; }
  if (!category) { result.innerHTML = '<div class="result-error">Please select a Garment Type — Full/One-Piece, Top, or Bottom. This is the setting that decides whether a dress gets treated as a full outfit or cropped like a top.</div>'; return; }

  if (costFashn !== null && !hasEnoughCredits(costFashn)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This action costs <strong>${costFashn} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating…';
  result.innerHTML = '<div class="result-loading">⏳ Running fashn try-on with FAL AI, please wait…</div>';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',              'generate_fashn_tryon');
    fd.append('model_image',         fashnSelectedModelFile);
    fd.append('garment_image',       fashnSelectedGarmentFile);
    fd.append('category',            category);
    fd.append('garment_photo_type',  garmentPhotoType);

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const categoryLabel = { 'one-pieces': 'Full / One-Piece', 'tops': 'Top', 'bottoms': 'Bottom' }[data.category] || data.category;
      result.innerHTML = `
        <div style="margin-bottom:10px;">
          <img src="${escHtml(data.public_url)}" alt="Try-on result"
            style="width:100%;max-width:300px;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.1);display:block;margin:0 auto;">
        </div>
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / fashn/tryon v1.6')}</strong> &nbsp;·&nbsp; ${secs}s
          <br><strong>Garment Type:</strong> ${escHtml(categoryLabel)} &nbsp;·&nbsp; <strong>Garment photo:</strong> ${escHtml(data.garment_photo_type)}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? costFashn ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'tryon.jpg')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#fae8ff;border:1.5px solid #f0abfc;border-radius:10px;color:#86198f;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Image
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

// ── Mannequin → Model: single image upload + generation ────────
let mannequinSelectedFile = null;

function handleMannequinUpload(input) {
  const file = input.files[0];
  if (!file) return;
  mannequinSelectedFile = file;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('mannequinPreview').src = e.target.result;
    document.getElementById('mannequinPreviewWrap').style.display = 'block';
    document.getElementById('mannequinClearBtn').style.display = 'inline-block';
  };
  reader.readAsDataURL(file);
}

function clearMannequinImage() {
  mannequinSelectedFile = null;
  document.getElementById('mannequinInput').value = '';
  document.getElementById('mannequinPreview').src = '';
  document.getElementById('mannequinPreviewWrap').style.display = 'none';
  document.getElementById('mannequinClearBtn').style.display = 'none';
}

function applyMannequinModelPreset() {
  const preset = document.getElementById('mannequinModelPreset').value;
  if (preset) document.getElementById('mannequinModelDescription').value = preset;
}

async function generateMannequinToModel() {
  const prompt           = ''; // advanced full-prompt override removed from UI — backend always builds the default template + Model Description below
  const modelDescription = document.getElementById('mannequinModelDescription').value.trim();
  const resolution = document.getElementById('mannequinResolution').value;
  const removeBg   = document.getElementById('mannequinRemoveBg').checked;
  const btn        = document.getElementById('mannequinBtn');
  const result      = document.getElementById('mannequinResult');

  if (!mannequinSelectedFile) { result.innerHTML = '<div class="result-error">Please upload a mannequin/product photo first.</div>'; return; }

  const baseCost = resolution === '2K' ? (costMannequin2K ?? costMannequin) : costMannequin;
  const totalCost = (baseCost ?? 0) + (removeBg ? (costBgRemove ?? 0) : 0);
  if (totalCost && !hasEnoughCredits(totalCost)) {
    result.innerHTML = `<div class="result-error">❌ Insufficient credits. This action costs <strong>${totalCost} credits</strong>. Your balance: <strong>${Number(creditBalance).toLocaleString()}</strong>.</div>`;
    return;
  }

  const origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating…';
  result.innerHTML = removeBg
    ? '<div class="result-loading">⏳ Replacing mannequin with a model, then removing the background…</div>'
    : '<div class="result-loading">⏳ Replacing mannequin with a model via FAL AI, please wait…</div>';

  const startTime = Date.now();
  try {
    const fd = new FormData();
    fd.append('action',       'generate_mannequin_to_model');
    fd.append('source_image', mannequinSelectedFile);
    fd.append('prompt',       prompt);
    fd.append('model_description', modelDescription);
    fd.append('resolution',   resolution);
    fd.append('remove_bg',    removeBg ? 'true' : 'false');

    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const data = await res.json();
    const secs = Math.round((Date.now() - startTime) / 1000);

    if (data.success && data.public_url) {
      refreshBalanceAfterGeneration(data.credits_balance);
      refreshGalleryAfterGeneration();
      const bgNote = removeBg
        ? (data.bg_removed
            ? `<div style="margin-top:6px;font-size:11px;color:#15803d;">✓ Background removed — transparent PNG</div>`
            : `<div style="margin-top:6px;font-size:11px;color:#b45309;">⚠ Background removal failed (showing the mannequin→model image instead): ${escHtml(data.bg_removal_error || '')}</div>`)
        : '';
      result.innerHTML = `
        <div style="margin-bottom:10px;${data.bg_removed ? 'background:repeating-conic-gradient(#e2e8f0 0% 25%, #fff 0% 50%) 0 0/16px 16px;border-radius:12px;padding:8px;' : ''}">
          <img src="${escHtml(data.public_url)}" alt="Mannequin to model result"
            style="width:100%;max-width:300px;border-radius:12px;${data.bg_removed ? '' : 'box-shadow:0 4px 14px rgba(0,0,0,0.1);'}display:block;margin:0 auto;">
        </div>
        ${bgNote}
        <div class="result-info">
          <strong>✅ ${escHtml(data.source || 'FAL AI / nano-banana-2/edit')}</strong> &nbsp;·&nbsp; ${secs}s &nbsp;·&nbsp; ${escHtml(data.resolution || resolution)}
          ${data.model_description_used ? `<br><strong>Model:</strong> ${escHtml(data.model_description_used)}` : ''}
          <br><strong>💎 Credits used:</strong> ${data.credits_used ?? totalCost ?? '—'} &nbsp;·&nbsp; <strong>Balance:</strong> ${data.credits_balance !== null ? Number(data.credits_balance).toLocaleString() : '—'}
        </div>
        <a href="${escHtml(data.public_url)}" download="${escHtml(data.filename || 'mannequin_to_model.png')}" target="_blank"
          style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:10px;background:#fae8ff;border:1.5px solid #f0abfc;border-radius:10px;color:#86198f;font-size:13px;font-weight:700;text-decoration:none;">
          ⬇ Download Image
        </a>`;
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      result.innerHTML = `<div class="result-error">❌ ${escHtml(data.error || 'Unknown error')}</div>`;
    }
  } catch (e) {
    result.innerHTML = `<div class="result-error">❌ ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled  = false;
    btn.innerHTML = origHTML;
  }
}

// ── Init ───────────────────────────────────────────────────────
loadCredits();
loadMediaGallery();
</script>
</body>
</html>

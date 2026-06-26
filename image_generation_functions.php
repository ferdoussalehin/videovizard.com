<?php
// image_generation_functions.php
// ══════════════════════════════════════════════════════════════════════════════
// Shared image generation functions — safe to include from any file.
// Contains ONLY the generator functions, no action handlers, no constants
// that would conflict if included multiple times.
// ══════════════════════════════════════════════════════════════════════════════

if (!defined('MODAL_IMAGE_URL')) {
    define('MODAL_IMAGE_URL', 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image');
}

// ── Logging helper — uses wk_log if available, falls back to error_log ────────
function img_gen_log($msg) {
    $formatted = '[img_gen] ' . $msg;
    if (function_exists('wk_log'))  { wk_log($msg);  return; }
    if (function_exists('wiz_log')) { wiz_log($msg);  return; }
    error_log($formatted . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCTION: generateAndSaveImageFlux
// PRIMARY provider — Modal-hosted Flux model
// Returns: ['success', 'message', 'filepath', 'resolution', 'provider']
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('generateAndSaveImageFlux')) {
    function generateAndSaveImageFlux($prompt, $imageName, $folder = 'podcast_images', $maxRetries = 3) {
        $payload = json_encode([
            'prompt' => $prompt,
            'style'  => 'cinematic',
            'width'  => 768,
            'height' => 1344,
        ]);

        $b64 = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            img_gen_log("Flux attempt $attempt/$maxRetries");
            $ch = curl_init(MODAL_IMAGE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            img_gen_log("Flux attempt $attempt — HTTP=$httpCode curlErr=$curlErrno" . ($curlError ? " ($curlError)" : ''));

            if ($curlErrno === 0 && $httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (!empty($data['image'])) {
                    img_gen_log("Flux attempt $attempt — image data received OK");
                    $b64 = $data['image'];
                    break;
                } else {
                    img_gen_log("Flux attempt $attempt — HTTP 200 but no image in response: " . mb_substr($response, 0, 200));
                }
            } else {
                img_gen_log("Flux attempt $attempt — failed: " . mb_substr($response ?? '', 0, 200));
            }

            if ($attempt < $maxRetries) {
                img_gen_log("Flux — waiting 5s before retry $attempt");
                sleep(5);
            }
        }

        if (!$b64) {
            return ['success' => false, 'message' => "Flux failed after $maxRetries attempts", 'filepath' => null, 'resolution' => null, 'provider' => 'flux'];
        }

        $imageData = base64_decode($b64);
        if (!$imageData) {
            return ['success' => false, 'message' => 'Flux returned invalid base64', 'filepath' => null, 'resolution' => null, 'provider' => 'flux'];
        }

        if (!is_dir($folder)) mkdir($folder, 0755, true);

        $safeImageName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $imageName);
        $filepath      = $folder . '/' . $safeImageName . '.png';

        if (file_put_contents($filepath, $imageData) === false) {
            return ['success' => false, 'message' => 'Failed to save Flux image to: ' . $filepath, 'filepath' => null, 'resolution' => null, 'provider' => 'flux'];
        }

        if (function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($filepath);
            if ($img) { imagepng($img, $filepath, 1); imagedestroy($img); }
        }

        img_gen_log("Flux saved: $filepath");
        return ['success' => true, 'message' => 'Flux image saved', 'filepath' => $filepath, 'resolution' => '768x1344', 'provider' => 'flux'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCTION: generateAndSaveImage
// FALLBACK provider — OpenAI gpt-image-1 (only called when Flux fails)
// Returns: ['success', 'message', 'filepath', 'resolution', 'provider']
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('generateAndSaveImage')) {
    function generateAndSaveImage($prompt, $imageName, $resolution = '1024x1536', $folder = 'podcast_images', $apiKey = null) {
        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'OpenAI API key missing', 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
        }

        $validResolutions = ['1024x1536', '1536x1024', '1024x1024', 'auto'];
        if (!in_array($resolution, $validResolutions)) $resolution = '1024x1536';

        if ($resolution !== 'auto') {
            list($targetWidth, $targetHeight) = array_map('intval', explode('x', $resolution));
        } else {
            $targetWidth = 1024; $targetHeight = 1536;
        }

        img_gen_log("OpenAI: calling gpt-image-1 size=$resolution");

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'         => 'gpt-image-1',
                'prompt'        => $prompt,
                'size'          => $resolution,
                'quality'       => 'medium',
                'output_format' => 'png',
                'n'             => 1,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        img_gen_log("OpenAI: HTTP=$httpCode curlErr=" . ($curlErr ?: 'none'));

        if ($curlErr) {
            return ['success' => false, 'message' => 'OpenAI cURL error: ' . $curlErr, 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $result['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . mb_substr($response, 0, 300));
            img_gen_log("OpenAI ERROR: $msg");
            return ['success' => false, 'message' => 'OpenAI: ' . $msg, 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
        }

        if (empty($result['data'][0]['b64_json'])) {
            return ['success' => false, 'message' => 'OpenAI: no image data in response', 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
        }

        $imageData   = base64_decode($result['data'][0]['b64_json']);
        $sourceImage = @imagecreatefromstring($imageData);

        if ($sourceImage) {
            $origWidth  = imagesx($sourceImage);
            $origHeight = imagesy($sourceImage);
            img_gen_log("OpenAI: received {$origWidth}x{$origHeight} target {$targetWidth}x{$targetHeight}");

            $needsRotation = ($targetHeight > $targetWidth && $origWidth > $origHeight)
                          || ($targetWidth > $targetHeight && $origHeight > $origWidth);
            if ($needsRotation) {
                img_gen_log("OpenAI: rotating image to fix orientation");
                $rotated = imagerotate($sourceImage, 90, 0);
                imagedestroy($sourceImage);
                $sourceImage = $rotated;
                $origWidth   = imagesx($sourceImage);
                $origHeight  = imagesy($sourceImage);
            }

            $finalImage = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($finalImage, false);
            imagesavealpha($finalImage, true);
            imagecopyresampled($finalImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);
            ob_start();
            imagepng($finalImage, null, 1);
            $imageData = ob_get_clean();
            imagedestroy($sourceImage);
            imagedestroy($finalImage);
        }

        if (!is_dir($folder)) mkdir($folder, 0755, true);

        $safeImageName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $imageName);
        $filepath      = $folder . '/' . $safeImageName . '.png';

        if (file_put_contents($filepath, $imageData) === false) {
            return ['success' => false, 'message' => 'Failed to save OpenAI image to: ' . $filepath, 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
        }

        $clean = @imagecreatefrompng($filepath);
        if ($clean) { imagepng($clean, $filepath, 1); imagedestroy($clean); }

        img_gen_log("OpenAI: saved $filepath");
        return ['success' => true, 'message' => 'OpenAI image saved', 'filepath' => $filepath, 'resolution' => $targetWidth . 'x' . $targetHeight, 'provider' => 'openai'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCTION: generateThumbnail
// Shared helper — creates a resized _thumb copy of any generated image.
// Called by generate_image_api.php, wizard_step2.php, image_worker.php, etc.
//
// $mainFilepath  — full path to the source image on disk
// $imageName     — base name without extension (e.g. "generated_123_001")
// $ext           — file extension without dot (e.g. "png")
// $thumbFolder   — folder to save into (default: "podcast_thumbnails")
// $thumbWidth    — target width in px (default: 320)
//
// Returns: ['filename' => 'generated_123_001_thumb.png',
//           'filepath' => 'podcast_thumbnails/generated_123_001_thumb.png',
//           'generated' => true|false]
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('generateThumbnail')) {
    function generateThumbnail($mainFilepath, $imageName, $ext, $thumbFolder = 'podcast_thumbnails', $thumbWidth = 320) {
        $thumbFilename = $imageName . '_thumb.' . $ext;
        $thumbFilepath = $thumbFolder . '/' . $thumbFilename;

        if (!is_dir($thumbFolder)) mkdir($thumbFolder, 0755, true);

        // Derive height from actual source aspect ratio; fall back to 9:16 portrait default
        $thumbHeight = 568;
        $srcInfo = @getimagesize($mainFilepath);
        if ($srcInfo && $srcInfo[0] > 0 && $srcInfo[1] > 0) {
            $thumbHeight = (int)round($thumbWidth * $srcInfo[1] / $srcInfo[0]);
        }

        $generated = false;
        $src = @imagecreatefrompng($mainFilepath);
        if ($src) {
            $origW = imagesx($src);
            $origH = imagesy($src);
            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            // Transparent background in case source has alpha channel
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $origW, $origH);
            imagepng($thumb, $thumbFilepath, 6); // compression 6 = good size/quality balance
            imagedestroy($src);
            imagedestroy($thumb);
            $generated = true;
            img_gen_log("generateThumbnail: saved $thumbFilepath ({$thumbWidth}x{$thumbHeight})");
        } else {
            // GD could not read source — fallback: copy the original as thumb
            $generated = @copy($mainFilepath, $thumbFilepath);
            img_gen_log("generateThumbnail: GD failed — fallback copy: " . ($generated ? 'OK' : 'FAILED'));
        }

        return [
            'filename'  => $thumbFilename,
            'filepath'  => $thumbFilepath,
            'generated' => $generated,
        ];
    }
}
?>

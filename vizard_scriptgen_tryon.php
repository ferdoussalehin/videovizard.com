<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}
ob_start();

if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

require_once 'config.php';
include 'dbconnect_hdb.php';

// ── Suppress fatal DB exceptions — use warnings instead ──────────────────────
mysqli_report(MYSQLI_REPORT_OFF);

// ── Production domain — used ONLY to resolve relative static-asset paths      ──
// (promo_models/, etc.) that live solely on the main site, not on a VPS or any
// other host this script might happen to be running from. User-uploaded media
// paths elsewhere in this file still resolve from $_SERVER['HTTP_HOST'], since
// those genuinely live wherever the script is currently running.
define('VV_SITE_BASE_URL', 'https://videovizard.com');

// ── fal.ai key (expected to be set in config.php) ────────────────────────────
$falApiKey = $falApiKey ?? null;
// OpenAI key, used only for garment-view classification (analyze which angle a dress photo shows)
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// ── Plan / free-trial status — used to gate the expensive AI-motion-video
// option in Step 6 (Select Video Mode). Static Images stays open to
// everyone; AI Motion Video for a free_trial user still requires enough
// real credit_balance to cover the actual scene cost (see set_video_mode's
// sibling check, get_credit_balance, and step6ContinueToGenerate() in JS). ──
$plan_row      = vv_safe_fetch($conn, "SELECT plan_type FROM hdb_users WHERE id=$admin_id LIMIT 1");
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// ── Core helpers — defined first so they're available everywhere ────────────
function vv_log($msg) {
    error_log('[VPS-VIZ] ' . $msg);
}
function vv_safe_fetch($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r || $r === false) {
        vv_log("vv_safe_fetch FAILED: " . mysqli_error($conn) . " | SQL: " . substr($sql, 0, 200));
        return null;
    }
    return mysqli_fetch_assoc($r) ?: null;
}

// Shrinks an image to a max dimension before it gets base64'd into an upload
// payload — fal_proxy.php / the web server has a request-size ceiling, and
// upscaled images (e.g. clarity-upscaler output) can easily blow past it.
// Returns the ORIGINAL path untouched if it's already small enough, otherwise
// a new temp JPEG path that the caller is responsible for unlinking after use.
function vv_shrink_for_upload($path, $max_dim = 2048, $quality = 85) {
    $info = @getimagesize($path);
    if (!$info) return $path;
    [$w, $h] = $info;
    if (max($w, $h) <= $max_dim) return $path;

    $src = @imagecreatefromstring(@file_get_contents($path));
    if (!$src) return $path;

    $scale = $max_dim / max($w, $h);
    $new_w = max(1, (int)round($w * $scale));
    $new_h = max(1, (int)round($h * $scale));
    $dst   = imagecreatetruecolor($new_w, $new_h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

    $tmp_path = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
    imagejpeg($dst, $tmp_path, $quality);
    imagedestroy($src);
    imagedestroy($dst);
    return $tmp_path;
}

// ── Shrink + recompress until the file is safely under a byte-size target ───
// vv_shrink_for_upload() above only checks pixel dimensions — a modest
// 1800x2200 photo can still be several MB as a high-quality JPEG/PNG, and
// skips shrinking entirely under that check. That's exactly what was hitting
// the host's own request-body ceiling (Apache/LiteSpeed returning a raw 413
// "Request Entity Too Large" HTML page) when base64-encoding a real
// photoshoot photo for the fal_proxy.php upload — nothing to do with fal.ai,
// rembg, or this script's logic, just the request body being too big before
// PHP even ran. This re-encodes through GD regardless of current dimensions
// and iterates size/quality down until the file actually fits.
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

        $tmp_path = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
        imagejpeg($dst, $tmp_path, $quality);
        imagedestroy($dst);

        if ($best_path !== null) @unlink($best_path);
        $best_path = $tmp_path;

        $size = @filesize($tmp_path);
        if ($size !== false && $size <= $target_bytes) break;

        $max_dim = (int)round($max_dim * 0.8);
        $quality = max(50, $quality - 8);
    }

    imagedestroy($src);
    return $best_path;
}

// ── Company / role resolver ───────────────────────────────────────────────
function vv_resolve_user($conn, $admin_id, $session_company_id) {
    $urow         = [];
    $_uq          = mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    if ($_uq)     $urow = mysqli_fetch_assoc($_uq) ?: [];
    $role         = $urow['role']         ?? 'Team Lead';
    $team_lead_id = (int)($urow['team_lead_id'] ?? 0);
    $owner_id     = ($role === 'Team Member' && $team_lead_id > 0) ? $team_lead_id : $admin_id;

    $co_sql = $session_company_id > 0
        ? "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id AND id=$session_company_id LIMIT 1"
        : "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id ORDER BY id ASC LIMIT 1";

    $_cq            = mysqli_query($conn, $co_sql);
    $co_row         = $_cq ? (mysqli_fetch_assoc($_cq) ?: null) : null;
    $company_type   = $co_row['company_type'] ?? '';
    $resolved_co_id = $co_row ? (int)$co_row['id'] : $session_company_id;

    vv_log("vv_resolve_user | admin_id=$admin_id session_co=$session_company_id"
         . " | role=$role team_lead_id=$team_lead_id owner_id=$owner_id"
         . " | co_row=" . json_encode($co_row)
         . " | company_type=[$company_type] resolved_co_id=$resolved_co_id");

    return [$owner_id, $resolved_co_id, $company_type, $role];
}

// ── Hero-shot helper — ONE fashn/tryon call per (model, garment) pair ───────
// Produces the single "this model wearing this exact garment" reference
// image that every later pose reuses via ideogram/character, instead of
// re-running fashn/tryon independently for every pose. That old approach
// is where sleeves/back coverage kept getting lost — each pose was an
// unrelated garment-transfer gamble instead of a simple repose of one
// already-correct image. Cached on disk per (model_id, garment file, category).
// ── Bare pose helper — model's body in a TEMPLATE pose, no garment yet ──────
// fashn/tryon needs a photo it can actually detect a body pose in, since it
// overlays the garment onto a real photographed body rather than generating
// one. The model's source photo is just a head/headshot — no body at all —
// so a full-body image has to be generated first, from a proven pose
// template (mdl_model_pose_templates), exactly the same mechanism the
// legacy get_model_pose_image handler already used. This is reused below to
// seed the hero shot: generate ONE template pose's bare body, run fashn on
// just that one, then repose-with-garment for the rest via ideogram/character.
function vv_get_or_create_bare_pose($conn, $falApiKey, $model_id, $pose_code, $force = false) {
    $pose_dir = __DIR__ . '/promo_models/';
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);
    $filename = "model_id_{$model_id}_pose_{$pose_code}.png";
    $filepath = $pose_dir . $filename;

    if (!$force && file_exists($filepath)) {
        return ['success' => true, 'path' => $filepath, 'cached' => true];
    }

    $model_row = vv_safe_fetch($conn, "SELECT thumbnail FROM mdl_models WHERE model_id=$model_id LIMIT 1");
    if (!$model_row || empty($model_row['thumbnail'])) {
        return ['success' => false, 'error' => 'Model not found or missing a reference photo (thumbnail)'];
    }
    $thumb_path = __DIR__ . '/' . ltrim($model_row['thumbnail'], '/');
    if (!is_file($thumb_path)) {
        return ['success' => false, 'error' => "Reference photo not found on disk at: $thumb_path"];
    }
    $thumb_bytes = @file_get_contents($thumb_path);
    if ($thumb_bytes === false) {
        return ['success' => false, 'error' => "Reference photo exists but could not be read: $thumb_path"];
    }
    $thumb_mime    = @mime_content_type($thumb_path) ?: 'image/jpeg';
    $ref_photo_url = 'data:' . $thumb_mime . ';base64,' . base64_encode($thumb_bytes);

    $safe_pose_code = mysqli_real_escape_string($conn, $pose_code);
    $pose_row = vv_safe_fetch($conn, "SELECT image_prompt FROM mdl_model_pose_templates WHERE code='$safe_pose_code' LIMIT 1");
    if (!$pose_row || empty($pose_row['image_prompt'])) {
        return ['success' => false, 'error' => "Pose template '$pose_code' not found or has no image_prompt"];
    }
    $prompt = trim($pose_row['image_prompt']) . ', plain seamless white studio background, no scenery, no props';

    $gch = curl_init('https://fal.run/fal-ai/ideogram/character');
    curl_setopt_array($gch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt'               => $prompt,
            'reference_image_urls' => [$ref_photo_url],
        ]),
    ]);
    $gres  = curl_exec($gch);
    $ghttp = curl_getinfo($gch, CURLINFO_HTTP_CODE);
    $gerr  = curl_error($gch);
    curl_close($gch);
    $gj = json_decode($gres, true);

    if ($ghttp !== 200 || empty($gj['images'][0]['url'])) {
        $detail = $gerr ? "curl error: $gerr" : ('HTTP ' . $ghttp . ' — ' . substr((string)$gres, 0, 200));
        vv_log("bare_pose: generation failed for model=$model_id pose=$pose_code | $detail");
        return ['success' => false, 'error' => "fal.ai call failed ($detail)"];
    }

    $img_data = @file_get_contents($gj['images'][0]['url']);
    if (!$img_data) {
        return ['success' => false, 'error' => 'Generated pose image could not be downloaded'];
    }

    // ── Clean white background, same approach used elsewhere in this file ──
    $final_data = $img_data;
    $rembg_ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rembg_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode(['image_url'=>'data:image/png;base64,'.base64_encode($img_data),'sync_mode'=>true]),
    ]);
    $rres  = curl_exec($rembg_ch);
    $rhttp = curl_getinfo($rembg_ch, CURLINFO_HTTP_CODE);
    curl_close($rembg_ch);
    $rj = json_decode($rres, true);

    if ($rhttp === 200 && !empty($rj['image']['url'])) {
        $cut_data = @file_get_contents($rj['image']['url']);
        $src = $cut_data ? @imagecreatefromstring($cut_data) : false;
        if ($src) {
            $iw = imagesx($src); $ih = imagesy($src);
            $dst = imagecreatetruecolor($iw, $ih);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagealphablending($src, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
            ob_start(); imagepng($dst); $final_data = ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
        } else {
            vv_log("bare_pose: rembg returned but image decode failed for model=$model_id pose=$pose_code");
        }
    } else {
        vv_log("bare_pose: background cleanup (rembg) failed (HTTP $rhttp) — saving original generation instead");
    }

    file_put_contents($filepath, $final_data);
    vv_log("bare_pose: generated model=$model_id pose=$pose_code -> $filename");
    return ['success' => true, 'path' => $filepath, 'cached' => false];
}

// ── Resolve which garment image (by view) to use for a given pose ──────────
// Reads the company's garment manifest and returns the file path for the
// requested view (front/back/left_side/right_side/upper_half/lower_half),
// falling back to 'front' and then to any image if no exact match exists.
// ── Turns a pose template's video_prompt/image_prompt (usually a full
// descriptive sentence written for an image-gen model, not a viewer) into a
// short, punchy on-screen caption — first clause, max ~7 words, no trailing
// punctuation. Falls back to the garment_view label if the source text is
// empty or only got matched as a leftover/pose_code stand-in.
function vv_make_sleek_caption($source_text, $garment_view) {
    $fallback = ucfirst(str_replace('_', ' ', $garment_view ?: 'front'));
    $text = trim((string)$source_text);
    if ($text === '') return $fallback;

    // First clause only — stop at the first sentence/clause break.
    $text = preg_split('/[.!?\n]|(?<=,)\s/', $text, 2)[0];
    $text = trim($text, " ,.-—");
    if ($text === '') return $fallback;

    // Cap at ~7 words so it reads as a caption, not a prompt.
    $words = preg_split('/\s+/', $text);
    if (count($words) > 7) $words = array_slice($words, 0, 7);
    $caption = implode(' ', $words);

    return $caption !== '' ? ucfirst($caption) : $fallback;
}

function vv_resolve_garment_for_view($owner_id, $co_id, $view) {
    $dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    $manifest_path = $dir . '_manifest.json';
    $manifest = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];

    foreach ($manifest as $m) {
        if (($m['view'] ?? '') === $view && !empty($m['filename']) && is_file($dir . $m['filename'])) {
            return $dir . $m['filename'];
        }
    }
    foreach ($manifest as $m) {
        if (($m['view'] ?? '') === 'front' && !empty($m['filename']) && is_file($dir . $m['filename'])) {
            return $dir . $m['filename'];
        }
    }
    foreach ($manifest as $m) {
        if (!empty($m['filename']) && is_file($dir . $m['filename'])) {
            return $dir . $m['filename'];
        }
    }
    return null;
}

// ── Classify which angle a garment photo shows, via OpenAI vision ──────────
// Mirrors the proven analyze_dress_view logic. Defaults to 'front' if no
// OpenAI key is configured or the call fails — never blocks the upload.
function vv_analyze_garment_view($apiKey, $image_path) {
    $valid = ['front','back','left_side','right_side','upper_half','lower_half'];
    if (!$apiKey) return 'front';

    $bytes = @file_get_contents($image_path);
    if ($bytes === false) return 'front';
    $mime     = @mime_content_type($image_path) ?: 'image/jpeg';
    $data_uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    $prompt = 'You are analyzing a clothing/garment image. Classify which view angle this garment photo shows. '
            . 'Choose EXACTLY ONE from this list: front, back, left_side, right_side, upper_half, lower_half. '
            . '"front" means the front face of the garment is visible. '
            . '"back" means the back/rear of the garment is shown. '
            . '"left_side" means the left side profile. '
            . '"right_side" means the right side profile. '
            . '"upper_half" means the garment is cropped from the neckline down to the stomach/waist — showing collar, chest, sleeves and mid-section only. '
            . '"lower_half" means only the bottom/lower portion (skirt, pants, hem) is shown. '
            . 'Respond with ONLY a JSON object like: {"view":"front"} — no other text.';

    $payload = [
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 30,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type'=>'text','text'=>$prompt],
                ['type'=>'image_url','image_url'=>['url'=>$data_uri,'detail'=>'low']],
            ],
        ]],
    ];
    $och = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($och, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $ores = curl_exec($och); curl_close($och);
    $oj   = json_decode($ores, true);
    $text   = trim($oj['choices'][0]['message']['content'] ?? '');
    $parsed = json_decode($text, true);
    return (isset($parsed['view']) && in_array($parsed['view'], $valid)) ? $parsed['view'] : 'front';
}

// ── Generate a missing garment view from the front photo via flux-pro/kontext ──
// Returns raw image bytes (already background-removed + composited onto white),
// or null on failure. Mirrors the proven generate_dress_view logic.
function vv_generate_garment_view($falApiKey, $front_image_path, $target_view) {
    $view_prompts = [
        'back'       => 'back view of the same garment, rear side, same dress/clothing shown from behind',
        'left_side'  => 'left side profile view of the same garment, 90 degree angle from left',
        'right_side' => 'right side profile view of the same garment, 90 degree angle from right',
        'upper_half' => 'upper portion of the same garment cropped from neckline to stomach/waist — showing collar, neckline, chest, sleeves and mid-section, flat lay or on hanger, white background, no model, clean studio shot',
        'lower_half' => 'lower half of the same garment, close up of skirt/pants/hem only',
    ];
    if (!isset($view_prompts[$target_view])) return null;

    $front_bytes = @file_get_contents($front_image_path);
    if ($front_bytes === false) return null;
    $front_mime    = @mime_content_type($front_image_path) ?: 'image/jpeg';
    $front_data_uri = 'data:' . $front_mime . ';base64,' . base64_encode($front_bytes);

    $prompt = 'Fashion product photography. ' . $view_prompts[$target_view]
            . '. White background. No model. Clean studio shot. Same fabric, colors, and design as the reference image.';

    $fch = curl_init('https://fal.run/fal-ai/flux-pro/kontext');
    curl_setopt_array($fch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt'              => $prompt,
            'image_url'           => $front_data_uri,
            'sync_mode'           => true,
            'num_images'          => 1,
            'guidance_scale'      => 3.5,
            'num_inference_steps' => 28,
        ]),
    ]);
    $fres  = curl_exec($fch); $fhttp = curl_getinfo($fch, CURLINFO_HTTP_CODE); curl_close($fch);
    $fj      = json_decode($fres, true);
    $gen_url = $fj['images'][0]['url'] ?? null;

    if (!$gen_url) {
        vv_log("generate_garment_view: flux-pro/kontext failed for view=$target_view (HTTP $fhttp) | " . substr((string)$fres,0,200));
        return null;
    }

    $img_data = @file_get_contents($gen_url);
    if (!$img_data) return null;

    // No second background-removal pass here — the reference image fed into
    // flux-pro/kontext above already went through birefnet/v2 in
    // garment_upload_image, and the prompt explicitly asks for a white
    // background, so the generated view comes out clean already. Running
    // birefnet again on an already-background-free image would just be an
    // extra cost and an extra point of failure for no benefit.
    return $img_data;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 (new workflow) — Mannequin/Product → Model, via FAL AI
// ═════════════════════════════════════════════════════════════════════════════
// Ports generateMannequinToModelWithFal()/removeBackgroundWithFal() from
// vizard_ai_tools.php into this file so Step 1 doesn't depend on a
// cross-file include. Saves into the SAME poses/ directory the rest of this
// file already uses (model_pose flow / create_podcast_from_poses), with a
// filename keyed to a draft_id rather than time()+rand() — Step 1 only ever
// needs to show "the current 1" per the new spec, so regenerating overwrites
// the same file instead of littering the folder with old attempts.
if (!defined('FAL_NANOBANANA_EDIT_URL')) define('FAL_NANOBANANA_EDIT_URL', 'https://fal.run/fal-ai/nano-banana-2/edit');
if (!defined('FAL_BIREFNET_URL'))        define('FAL_BIREFNET_URL', 'https://fal.run/fal-ai/birefnet/v2');

function vv_fal_upload_for_proxy($path) {
    $proxy_url = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
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
}

// Replaces the mannequin/dress-form in $garmentImageAbsPath with a model
// matching $prompt, via fal-ai/nano-banana-2/edit. Saves as
// draft_{$draft_id}_model.jpg in the poses/ dir for this owner/company —
// fixed filename, overwritten on every regenerate.
function vv_generate_model_from_garment($falApiKey, $garmentImageAbsPath, $prompt, $resolution, $owner_id, $co_id, $draft_id, $maxRetries = 2) {
    $upload_path = vv_shrink_to_target_size($garmentImageAbsPath);
    [$source_url, $uh, $uerr, $ures] = vv_fal_upload_for_proxy($upload_path);
    if ($upload_path !== $garmentImageAbsPath) @unlink($upload_path);
    if (!$source_url) {
        return ['success' => false, 'message' => "Failed to upload garment photo to fal.ai (HTTP $uh" . ($uerr ? ", curl: $uerr" : '') . ')'];
    }

    $payload = json_encode([
        'prompt'       => $prompt,
        'image_urls'   => [$source_url],
        'aspect_ratio' => 'auto',
        'resolution'   => $resolution,
        'num_images'   => 1,
    ]);

    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);
    $filename = "draft_{$draft_id}_model.jpg";
    $filePath = $pose_dir . $filename;

    $httpCode = 0; $curlError = ''; $attempt = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init(FAL_NANOBANANA_EDIT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if (!$imageUrl) {
                return ['success' => false, 'message' => 'FAL AI returned HTTP 200 but no image URL.'];
            }
            $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $m);
            $outData = $isDataUri ? base64_decode($m[1]) : @file_get_contents($imageUrl);
            if ($outData === false || strlen($outData) === 0) {
                return ['success' => false, 'message' => 'Generated image could not be downloaded/decoded.'];
            }
            if (@file_put_contents($filePath, $outData) === false) {
                return ['success' => false, 'message' => 'Image generated but could not be saved to disk.'];
            }
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'];
            $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir, '/')), '/') . '/';
            $public_url = $protocol . '://' . $host . $web_path . $filename . '?v=' . time(); // cache-bust on regenerate
            return [
                'success'    => true,
                'filename'   => $filename,
                'local_path' => $filePath,
                'public_url' => $public_url,
                'source'     => 'FAL AI / nano-banana-2/edit (Mannequin → Model)',
            ];
        }
        if ($httpCode !== 0) break; // already reached FAL — don't risk double billing by retrying
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (nano-banana-2/edit) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if ($curlError) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'message' => $errMsg];
}

// Removes the background of $sourceImageAbsPath via fal-ai/birefnet/v2.
// Saves as draft_{$draft_id}_model_nobg.png (fixed name, overwritten).
function vv_remove_background_fal($falApiKey, $sourceImageAbsPath, $draft_id, $owner_id, $co_id, $maxRetries = 2) {
    $upload_path = vv_shrink_to_target_size($sourceImageAbsPath);
    [$source_url, $uh, $uerr] = vv_fal_upload_for_proxy($upload_path);
    if ($upload_path !== $sourceImageAbsPath) @unlink($upload_path);
    if (!$source_url) {
        return ['success' => false, 'message' => "Failed to upload image for background removal (HTTP $uh)" . ($uerr ? " curl: $uerr" : '')];
    }

    $payload = json_encode(['image_url' => $source_url, 'model' => 'General Use (Light)']);
    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $filename = "draft_{$draft_id}_model_nobg.png";
    $filePath = $pose_dir . $filename;

    $httpCode = 0; $curlError = ''; $attempt = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init(FAL_BIREFNET_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['image']['url'] ?? ($data['images'][0]['url'] ?? null);
            if (!$imageUrl) return ['success' => false, 'message' => 'BiRefNet returned HTTP 200 but no image found.'];
            $outData = @file_get_contents($imageUrl);
            if ($outData === false || strlen($outData) === 0) {
                return ['success' => false, 'message' => 'Background-removed image could not be downloaded.'];
            }
            if (@file_put_contents($filePath, $outData) === false) {
                return ['success' => false, 'message' => 'Background removed but could not be saved to disk.'];
            }
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'];
            $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir, '/')), '/') . '/';
            $public_url = $protocol . '://' . $host . $web_path . $filename . '?v=' . time();
            return ['success' => true, 'filename' => $filename, 'local_path' => $filePath, 'public_url' => $public_url, 'source' => 'FAL AI / BiRefNet v2'];
        }
        if ($httpCode !== 0) break;
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (BiRefNet) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if ($curlError) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'message' => $errMsg];
}

// ═════════════════════════════════════════════════════════════════════════════
// THEMED BACKGROUND — rembg (background removal via fal) → composite the
// cut-out subject over a REAL stock photo picked at random from the theme's
// pool in hdb_theme_types (columns: theme_name, image_name, image_folder),
// instead of generating a fresh AI background plate via flux/dev every time.
// Each theme currently has 8 real photos (more may be added later — this
// just picks randomly among however many rows exist for that theme_name, so
// it scales automatically). Saves the composited result into the SAME
// poses/ dir the rest of Step 3 uses, named by $tag (e.g. "pose{$story_id}_themed").
function vv_apply_theme_background($conn, $falApiKey, $sourceImageAbsPath, $theme_name, $owner_id, $co_id, $tag) {
    $theme_name = trim($theme_name);
    if ($theme_name === '') return ['success' => false, 'message' => 'No theme selected'];

    // ── Step 1: pick a random real photo for this theme from the library ───
    $esc_theme = mysqli_real_escape_string($conn, $theme_name);
    $lib_row = vv_safe_fetch($conn, "SELECT image_name, image_folder FROM hdb_theme_types WHERE theme_name='$esc_theme' ORDER BY RAND() LIMIT 1");
    if (!$lib_row) return ['success' => false, 'message' => "No images found in library for theme '$theme_name'"];

    $bg_folder = trim($lib_row['image_folder'], '/');
    $bg_name   = $lib_row['image_name'];
    $bg_abs    = __DIR__ . '/' . $bg_folder . '/' . $bg_name;

    // Defensive fallback in case the stored filename's extension drifted
    // from what's actually on disk (.jpg vs .jpeg) — try the row's own
    // name first, then swap the extension before giving up.
    if (!is_file($bg_abs)) {
        $base = preg_replace('/\.(jpe?g)$/i', '', $bg_name);
        foreach (['jpg', 'jpeg'] as $ext) {
            $cand = __DIR__ . '/' . $bg_folder . '/' . $base . '.' . $ext;
            if (is_file($cand)) { $bg_abs = $cand; break; }
        }
    }
    if (!is_file($bg_abs)) return ['success' => false, 'message' => "Theme image missing on disk: $bg_folder/$bg_name"];

    // ── Step 2: rembg → transparent PNG of the subject ──────────────────────
    $upload_path = vv_shrink_to_target_size($sourceImageAbsPath);
    [$fal_url, $uh, $uerr] = vv_fal_upload_for_proxy($upload_path);
    if ($upload_path !== $sourceImageAbsPath) @unlink($upload_path);
    if (!$fal_url) {
        return ['success' => false, 'message' => "Failed to upload image for theming (HTTP $uh)" . ($uerr ? " curl: $uerr" : '')];
    }

    $rch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS => json_encode(['image_url' => $fal_url, 'sync_mode' => true]),
    ]);
    $rres = curl_exec($rch); $rhttp = curl_getinfo($rch, CURLINFO_HTTP_CODE); curl_close($rch);
    $rj = json_decode($rres, true);
    if ($rhttp !== 200 || empty($rj['image']['url'])) {
        return ['success' => false, 'message' => 'rembg failed (HTTP ' . $rhttp . '): ' . substr((string)$rres, 0, 200)];
    }

    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);

    $png_data = @file_get_contents($rj['image']['url']);
    if (!$png_data) return ['success' => false, 'message' => 'Could not download rembg output'];
    $png_path = $pose_dir . "tmp_{$tag}_mask.png";
    file_put_contents($png_path, $png_data);
    $model_img = @imagecreatefrompng($png_path);
    if (!$model_img) { @unlink($png_path); return ['success' => false, 'message' => 'GD failed to load rembg PNG']; }
    $iw = imagesx($model_img); $ih = imagesy($model_img);

    // ── Step 3: composite the transparent subject over the chosen theme photo ──
    // Both .jpg and .jpeg are the same JPEG codec, so imagecreatefromjpeg()
    // handles either extension fine.
    $bg_img = @imagecreatefromjpeg($bg_abs);
    if (!$bg_img) { imagedestroy($model_img); @unlink($png_path); return ['success' => false, 'message' => 'GD failed to load theme background photo']; }

    // Output canvas matches the final video's own 1080x1920 (9:16) frame,
    // so nothing gets unexpectedly re-stretched/re-cropped later by
    // vps_ffmpeg_stitch.php. The background is COVER-fit into this canvas
    // (scaled to fully cover it, excess cropped, never distorted out of
    // its own aspect ratio — the same idea as CSS "object-fit: cover"),
    // and the model is scaled DOWN to a believable fraction of frame
    // height and anchored near the bottom with a small floor margin.
    //
    // The old code here stretched the background to match the model
    // cutout's own tight crop, then pasted the model across the ENTIRE
    // canvas at 1:1 with no scaling — which is exactly why the result
    // looked like a giant model standing in front of a squashed postage
    // stamp instead of a person standing inside a real space.
    $CANVAS_W = 1080;
    $CANVAS_H = 1920;

    $bw = imagesx($bg_img); $bh = imagesy($bg_img);
    $canvas = imagecreatetruecolor($CANVAS_W, $CANVAS_H);

    $bg_scale  = max($CANVAS_W / $bw, $CANVAS_H / $bh);
    $scaled_bw = max(1, (int)round($bw * $bg_scale));
    $scaled_bh = max(1, (int)round($bh * $bg_scale));
    $crop_x    = (int)round(($scaled_bw - $CANVAS_W) / 2);
    $crop_y    = (int)round(($scaled_bh - $CANVAS_H) / 2);

    $bg_scaled = imagecreatetruecolor($scaled_bw, $scaled_bh);
    imagecopyresampled($bg_scaled, $bg_img, 0, 0, 0, 0, $scaled_bw, $scaled_bh, $bw, $bh);
    imagecopy($canvas, $bg_scaled, 0, 0, $crop_x, $crop_y, $CANVAS_W, $CANVAS_H);
    imagedestroy($bg_scaled);

    // Full-length fashion shots read as "standing in the scene" at
    // roughly 78-85% of frame height — leaves headroom above and a
    // footing margin below instead of the model touching both edges.
    $MODEL_HEIGHT_FRACTION = 0.70;
    $target_model_h = max(1, (int)round($CANVAS_H * $MODEL_HEIGHT_FRACTION));
    $model_scale    = $target_model_h / $ih;
    $target_model_w = max(1, (int)round($iw * $model_scale));

    $model_scaled = imagecreatetruecolor($target_model_w, $target_model_h);
    // Preserve the rembg transparency through the resize.
    imagealphablending($model_scaled, false);
    imagesavealpha($model_scaled, true);
    $transparent = imagecolorallocatealpha($model_scaled, 0, 0, 0, 127);
    imagefilledrectangle($model_scaled, 0, 0, $target_model_w, $target_model_h, $transparent);
    imagecopyresampled($model_scaled, $model_img, 0, 0, 0, 0, $target_model_w, $target_model_h, $iw, $ih);

    // Center horizontally; anchor near the bottom with a small floor
    // margin (~4% of canvas height) so her feet have a touch of ground
    // showing below them, like a real photo, instead of pinning flush to
    // the very bottom edge.
    $floor_margin = (int)round($CANVAS_H * 0.04);
    $dest_x = (int)round(($CANVAS_W - $target_model_w) / 2);
    $dest_y = $CANVAS_H - $target_model_h - $floor_margin;

    imagealphablending($canvas, true);
    imagealphablending($model_scaled, true);
    imagecopy($canvas, $model_scaled, $dest_x, $dest_y, 0, 0, $target_model_w, $target_model_h);
    imagedestroy($model_scaled);

    $filename = "draft_{$tag}_themed.jpg";
    $filePath = $pose_dir . $filename;
    imagejpeg($canvas, $filePath, 92);
    imagedestroy($model_img); imagedestroy($bg_img); imagedestroy($canvas);
    @unlink($png_path);

    if (!is_file($filePath)) return ['success' => false, 'message' => 'Composite saved but file not found afterward'];

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir, '/')), '/') . '/';
    $public_url = $protocol . '://' . $host . $web_path . $filename . '?v=' . time();
    return ['success' => true, 'filename' => $filename, 'local_path' => $filePath, 'public_url' => $public_url];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 (new workflow) — caption/header/footer settings + hashtag builder
// ═════════════════════════════════════════════════════════════════════════════
// Ports the minimal inline "get user caption settings" block from
// vizard_scriptgen_2.php (generateAllScenes flow) so each scene's captions in
// the new fashion flow match whatever the person has set in their caption
// preferences — same fontfamily/color/position/etc, same enabled flags for
// header/footer. Returns ['caption'=>.., 'header'=>.., 'footer'=>..].
function vv_get_user_caption_settings($conn, $admin_id, $company_id) {
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

    $us_res = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$company_id ORDER BY id DESC LIMIT 1");
    $us_row = ($us_res && mysqli_num_rows($us_res)) ? mysqli_fetch_assoc($us_res) : null;
    if (!$us_row) {
        return ['caption' => $def_cap, 'header' => $def_hdr, 'footer' => $def_ftr];
    }

    $from_db = [
        'fontfamily'        => $us_row['fontfamily']        ?? $def_cap['fontfamily'],
        'fontsize'          => (int)($us_row['fontsize']    ?? $def_cap['fontsize']),
        'fontcolor'         => $us_row['fontcolor']         ?? $def_cap['fontcolor'],
        'fontweight'        => $us_row['fontweight']        ?? $def_cap['fontweight'],
        'font_italic'       => (int)($us_row['font_italic']    ?? 0),
        'font_underline'    => (int)($us_row['font_underline'] ?? 0),
        'caption_alignment' => $us_row['caption_alignment'] ?? 'center',
        'text_align_v'      => $us_row['text_align_v']      ?? 'bottom',
        'fontcolor_bg'      => $us_row['fontcolor_bg']      ?? '#000000',
        'fontbg_enable'     => (int)($us_row['fontbg_enable'] ?? 0),
        'stroke_color'      => $us_row['stroke_color']      ?? '#000000',
        'stroke_width'      => (int)($us_row['stroke_width'] ?? 0),
        'shadow_color'      => $us_row['shadow_color']      ?? '#000000',
        'gradient_color'    => $us_row['gradient_color']    ?? '#ff6600',
        '_anim_style'       => $us_row['text_animation']    ?? $us_row['animation_style'] ?? 'none',
        '_anim_speed'       => is_numeric($us_row['animation_speed'] ?? null) ? (float)$us_row['animation_speed'] : 1.0,
        '_text_fx'          => $us_row['text_effect']       ?? 'none',
        '_text_fx_col'      => $us_row['text_effect_color'] ?? '#ffffff',
        'caption_style'     => $us_row['caption_style']     ?? 'none',
        'caption_position'  => $us_row['caption_position']  ?? 'bottom',
        'display_mode'      => $us_row['display_mode']      ?? 'full',
        'position_x'        => (int)($us_row['position_x'] ?? 50),
        'position_y'        => (int)($us_row['position_y'] ?? 200),
        'width'             => (int)($us_row['width']     ?? 500),
        'is_enabled'        => 1,
        'caption_text'      => $us_row['caption_text']    ?? '',
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
    return ['caption' => $cap_settings, 'header' => $hdr_settings, 'footer' => $ftr_settings];
}

// Inserts up to 3 hdb_captions rows for one story (main always, header/footer
// only if enabled in the person's settings) — ported from the same insert
// blocks in vizard_scriptgen_2.php's generateAllScenes save path.
function vv_insert_scene_captions($conn, $podcast_id, $story_id, $text, $user_settings) {
    $cap = $user_settings['caption'];
    $hdr = $user_settings['header'];
    $ftr = $user_settings['footer'];

    if ((int)($cap['is_enabled'] ?? 1)) {
        $words_arr = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $cap_text  = count($words_arr) > 10 ? implode(' ', array_slice($words_arr, 0, 10)) . '…' : $text;
        $ct  = mysqli_real_escape_string($conn, $cap_text);
        $ff  = mysqli_real_escape_string($conn, $cap['fontfamily'] ?? 'Arial');
        $fc  = mysqli_real_escape_string($conn, $cap['fontcolor'] ?? '#ffffff');
        $fw  = mysqli_real_escape_string($conn, $cap['fontweight'] ?? 'normal');
        $fst = ((int)($cap['font_italic'] ?? 0)) ? 'italic' : 'normal';
        $uline = (int)($cap['font_underline'] ?? 0);
        $ta  = mysqli_real_escape_string($conn, $cap['caption_alignment'] ?? 'center');
        $tav = mysqli_real_escape_string($conn, $cap['text_align_v'] ?? 'bottom');
        $bgc = mysqli_real_escape_string($conn, $cap['fontcolor_bg'] ?? '#000000');
        $bge = (int)($cap['fontbg_enable'] ?? 0);
        $sc  = mysqli_real_escape_string($conn, $cap['stroke_color'] ?? '#000000');
        $sw  = (int)($cap['stroke_width'] ?? 0);
        $se  = $sw > 0 ? 1 : 0;
        $shc = mysqli_real_escape_string($conn, $cap['shadow_color'] ?? '#000000');
        $gc  = mysqli_real_escape_string($conn, $cap['gradient_color'] ?? '#ff6600');
        $ast = mysqli_real_escape_string($conn, $cap['_anim_style'] ?? 'none');
        $asp = is_numeric($cap['_anim_speed'] ?? null) ? (float)$cap['_anim_speed'] : 1.0;
        $tfx = mysqli_real_escape_string($conn, $cap['_text_fx'] ?? 'none');
        $tfc = mysqli_real_escape_string($conn, $cap['_text_fx_col'] ?? '#ffffff');
        $cst = mysqli_real_escape_string($conn, $cap['caption_style'] ?? 'none');
        $cpv = mysqli_real_escape_string($conn, $cap['caption_position'] ?? 'bottom');
        $dm  = mysqli_real_escape_string($conn, $cap['display_mode'] ?? 'full');
        $px  = (int)($cap['position_x'] ?? 50);
        $py  = (int)($cap['position_y'] ?? 200);
        $pw  = min((int)($cap['width'] ?? 500), 350);
        $fs  = (int)($cap['fontsize'] ?? 28);
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

    if ((int)($hdr['is_enabled'] ?? 0) && !empty($hdr['caption_text'])) {
        $ht = mysqli_real_escape_string($conn, $hdr['caption_text']);
        $ff = mysqli_real_escape_string($conn, $hdr['fontfamily'] ?? 'Arial');
        $fc = mysqli_real_escape_string($conn, $hdr['fontcolor'] ?? '#ffffff');
        $fw = mysqli_real_escape_string($conn, $hdr['fontweight'] ?? 'normal');
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

    if ((int)($ftr['is_enabled'] ?? 0) && !empty($ftr['caption_text'])) {
        $ft = mysqli_real_escape_string($conn, $ftr['caption_text']);
        $ff = mysqli_real_escape_string($conn, $ftr['fontfamily'] ?? 'Arial');
        $fc = mysqli_real_escape_string($conn, $ftr['fontcolor'] ?? '#ffffff');
        $fw = mysqli_real_escape_string($conn, $ftr['fontweight'] ?? 'normal');
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

// Ported from vizard_scriptgen_2.php's hashtag/keyword builder — content
// words from the scene captions + business (ai_group/ai_subgroup) +
// Target Location/Audience (from the Video Setting bar).
function vv_build_hashtags_keywords($conn, $garment_category, $brand_tone, $description, $loc_part, $aud_part) {
    // Garment-descriptive tags are anchored to two things: (1) the actual
    // category/brand-tone, and (2) the real product description — what's
    // literally being promoted in THIS video (colour, fabric, embellishment,
    // garment details the user typed in, e.g. "deep purple velvet kurta with
    // gold zardozi embroidery"). That's different from the AI-written
    // marketing captions, which are generic ad-copy regardless of product
    // ("Embrace timeless elegance...") — splitting THOSE only ever yields
    // stray abstract adjectives/verbs ("embrace", "discover", "defines")
    // with zero connection to the actual item. The description, by
    // contrast, is factual and product-specific, so its content words
    // ("purple", "velvet", "zardozi") are genuinely useful tags on their
    // own, and pairing them with the garment noun gives concrete phrases
    // like "velvet party wear" tied to what's actually in the video.
    $stop = ['the','and','for','you','your','with','that','this','are','can','will','have',
             'from','they','their','what','about','there','more','some','would','could',
             'should','been','were','was','one','two','first','then','than','very','just',
             'like','into','over','also','after','other','only','area','near','local',
             'of','a','an','in','is','to','on','at','by','it','as','be','or','if','its',
             'we','our','us','so','not','but','all','any','each','out','up','off','no',
             'do','does','did','has','had','i','my','me','he','she','him','her','them',
             'ideal','perfect','great','good','nice','wear','wearing','look','looks'];

    $garment_noun = strtolower(trim($garment_category)) ?: 'dress';
    $garment_base = trim(preg_replace('/\s*wear$/i', '', $garment_noun));

    $desc_words = array_diff(str_word_count(strtolower(trim($description)), 1), $stop);
    $desc_words = array_filter($desc_words, fn($w) => strlen($w) > 2);
    $desc_words = array_slice(array_values(array_unique($desc_words)), 0, 6);

    $tone_adj_map = [
        'Bridal'       => ['bridal', 'elegant', 'regal'],
        'Premium'      => ['premium', 'elegant', 'chic'],
        'Luxury'       => ['luxury', 'elegant', 'exquisite'],
        'Contemporary' => ['stylish', 'modern', 'chic'],
    ];
    $tone_adj = $tone_adj_map[trim($brand_tone)] ?? ['elegant', 'stylish'];

    $phrases = [$garment_noun];

    // Product-specific phrases first — these are what actually distinguish
    // THIS video's product from any other "party wear" video.
    foreach ($desc_words as $dw) {
        $phrases[] = $dw;                     // e.g. "velvet" on its own
        $phrases[] = "$dw $garment_noun";     // e.g. "velvet party wear"
    }

    // Tone phrases fill out the rest, same as before.
    foreach ($tone_adj as $adj) {
        $phrases[] = "$adj $garment_noun";
        if ($garment_base !== '' && $garment_base !== $garment_noun) {
            $phrases[] = "$adj $garment_base dress";
        }
    }

    $kw_arr = array_values(array_unique(array_map('strtolower', $phrases)));
    if ($loc_part) $kw_arr[] = strtolower(trim($loc_part));
    if ($aud_part) $kw_arr[] = strtolower(trim($aud_part));
    $kw_arr = array_values(array_unique(array_filter($kw_arr, fn($w) => trim($w) !== '')));

    $ht_arr = array_map(function($w){ return '#' . preg_replace('/\s+/', '', $w); }, array_slice($kw_arr, 0, 14));
    $ht_arr = array_values(array_unique($ht_arr));

    return [
        mysqli_real_escape_string($conn, implode(', ', $ht_arr)),
        mysqli_real_escape_string($conn, implode(', ', $kw_arr)),
    ];
}

// Generates $scene_count sequential captions (intro → details → craftsmanship
// → movement → styling → complete look → CTA) via gpt-4o-mini, following the
// exact campaign-caption prompt format. Falls back to $fallback_captions
// (one per scene, e.g. from vv_make_sleek_caption against the style template)
// if there's no API key, the call fails, or the parsed count doesn't match.
function vv_generate_scene_captions_ai($apiKey, $scene_count, $product_description, $brand_tone, $audience, $caption_style, $fallback_captions) {
    if (!$apiKey || $scene_count < 1) return $fallback_captions;

    $has_desc = trim($product_description) !== '';
    $prompt = "Your task is to generate exactly {$scene_count} sequential captions for a {$scene_count}-scene fashion video campaign. Each scene is 6 seconds long.\n\n"
        . "INPUTS:\n"
        . "* Product Description: " . ($has_desc ? $product_description : '(none provided)') . "\n"
        . "* Brand Tone: {$brand_tone}\n"
        . "* Target Audience: " . ($audience ?: 'General fashion audience') . "\n"
        . "* Caption Style: {$caption_style}\n"
        . "* Has Product Description: " . ($has_desc ? 'Yes' : 'No') . "\n\n"
        . "INSTRUCTIONS:\n"
        . "1. Generate exactly {$scene_count} captions, one for each scene.\n"
        . "2. If a product description is provided, incorporate visible garment details naturally across the captions.\n"
        . "3. If no description is provided, create captions based on universal fashion themes such as elegance, craftsmanship, confidence, movement, luxury, and bridal beauty.\n"
        . "4. The captions should tell a progressive story across the scenes — introduction → details → craftsmanship → movement → styling → complete look → final call-to-action (compress or extend this arc to fit exactly {$scene_count} scenes).\n"
        . "5. Keep each caption under 12 words.\n"
        . "6. Avoid repeating the same words.\n"
        . "7. Use premium fashion language suitable for Instagram Reels, TikTok, and Meta ads.\n"
        . "8. Include emojis only if they enhance luxury appeal.\n"
        . "9. Output in the following format, one line per scene, nothing else:\n"
        . "Scene 1: [Caption] Scene 2: [Caption] ... Scene {$scene_count}: [Caption]";

    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 400,
        'temperature' => 0.85,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$res) { vv_log("vv_generate_scene_captions_ai: curl error: $err"); return $fallback_captions; }

    $text = trim(json_decode($res, true)['choices'][0]['message']['content'] ?? '');
    if ($text === '') { vv_log("vv_generate_scene_captions_ai: empty response: $res"); return $fallback_captions; }

    preg_match_all('/Scene\s*(\d+)\s*:\s*(.+?)(?=(?:Scene\s*\d+\s*:)|$)/is', $text, $matches, PREG_SET_ORDER);
    $captions = [];
    foreach ($matches as $m) {
        $idx = (int) $m[1];
        $cap = trim($m[2], " \n\r\t.,-—");
        if ($idx >= 1 && $idx <= $scene_count && $cap !== '') $captions[$idx] = $cap;
    }
    if (count($captions) !== $scene_count) {
        vv_log("vv_generate_scene_captions_ai: parsed " . count($captions) . "/{$scene_count} captions, falling back. raw: $text");
        return $fallback_captions;
    }
    ksort($captions);
    return array_values($captions);
}

// ── Ensure business-settings columns exist on hdb_companies ─────────────────
$_cols = [];
$_cr = mysqli_query($conn, "SHOW COLUMNS FROM hdb_companies");
if ($_cr) while ($_col = mysqli_fetch_assoc($_cr)) $_cols[] = $_col['Field'];
$_needed_cols = [
    'group_name'      => 'VARCHAR(120)',
    'subgroup_name'   => 'VARCHAR(120)',
    'niche'           => 'VARCHAR(120)',
    'ai_group'        => 'VARCHAR(200)',
    'ai_subgroup'     => 'VARCHAR(200)',
    'target_location' => 'VARCHAR(255)',
    'target_audience' => 'VARCHAR(255)',
];
foreach ($_needed_cols as $_colName => $_colType) {
    if (!in_array($_colName, $_cols)) {
        mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN $_colName $_colType DEFAULT NULL");
        vv_log("Auto-added column $_colName to hdb_companies");
    }
}

// ── Target Audience / Location — feeds the "Video Setting" bar (Step 1) ────
$php_target_location = '';
$php_target_audience = '';
{
    [$_t_owner, $_t_co] = vv_resolve_user($conn, $admin_id, $company_id);
    if ($_t_co) {
        $_t_row = vv_safe_fetch($conn, "SELECT target_location, target_audience FROM hdb_companies WHERE id=$_t_co LIMIT 1");
        $php_target_location = trim($_t_row['target_location'] ?? '');
        $php_target_audience = trim($_t_row['target_audience'] ?? '');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS — Business Settings (Group → Sub-group)
// ═════════════════════════════════════════════════════════════════════════════

// ── Master groups: from hdb_promo_categories ─────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_industries') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    mysqli_set_charset($conn, 'utf8mb4');
    $q = mysqli_query($conn,
        "SELECT category_name, category_icon FROM hdb_promo_categories WHERE is_active=1 ORDER BY sort_order ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = ['core_group' => $r['category_name'], 'icon' => $r['category_icon'] ?? ''];
    }
    echo json_encode(['success'=>true,'groups'=>$rows,'total'=>count($rows)]); exit;
}

// ── Sub-groups: from hdb_promo_subcategories ──────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_subgroups') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    mysqli_set_charset($conn, 'utf8mb4');
    $cg = mysqli_real_escape_string($conn, trim($_POST['core_group'] ?? ''));
    if (!$cg) { echo json_encode(['success'=>false,'error'=>'Missing core_group']); exit; }
    $q = mysqli_query($conn,
        "SELECT id, promo_subgroup as industry_desc FROM hdb_promo_subcategories
         WHERE promo_group='$cg' AND is_active=1
         ORDER BY display_order ASC, promo_subgroup ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = ['id'=>(int)$r['id'], 'industry_desc'=>$r['industry_desc']];
    }
    echo json_encode(['success'=>true,'subgroups'=>$rows,'total'=>count($rows)]); exit;
}

// ── Save group/subgroup to hdb_companies ──────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_company_industry') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $field = mysqli_real_escape_string($conn, $_POST['field'] ?? '');
    $val   = mysqli_real_escape_string($conn, trim($_POST['value'] ?? ''));
    $allowed = ['group_name', 'subgroup_name', 'niche', 'ai_group', 'ai_subgroup', 'target_location', 'target_audience'];
    if (!in_array($field, $allowed)) { echo json_encode(['success'=>false,'error'=>'Bad field']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) {
        $fb = vv_safe_fetch($conn, "SELECT id FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
        $co_id = $fb ? (int)$fb['id'] : 0;
    }
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    mysqli_query($conn, "UPDATE hdb_companies SET `$field`='$val' WHERE id=$co_id LIMIT 1");
    $affected = mysqli_affected_rows($conn);
    vv_log("save_company_industry | co_id=$co_id field=$field val=$val affected=$affected");
    echo json_encode(['success'=>true, 'field'=>$field, 'value'=>$val, 'co_id'=>$co_id]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS — Garment Image Upload (Step 1: bg removal + enhance)
// ═════════════════════════════════════════════════════════════════════════════

// ── Upload one image: fal storage → rembg → white composite → enhance ───────
// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 1 (new workflow): Mannequin/Product → Model
// ═════════════════════════════════════════════════════════════════════════════
// One call does it all: uploads the dress photo (first call only — later
// "regenerate" calls reuse the saved garment for this draft_id), runs
// nano-banana-2/edit to swap the mannequin/dress-form for a model matching
// the chosen description, optionally chains straight into BiRefNet for
// background removal, and deducts a flat 5 credits per generate. Nothing is
// written to hdb_podcasts/hdb_podcast_stories yet — that happens in Step 3
// (Approve) once a style is also chosen; until then this is just files on
// disk keyed by draft_id, with the title/description passed straight back
// to the client to carry forward.
// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Themed backgrounds for Step 2's pose sequence. DB-backed via
// hdb_theme_types (columns: theme_name, image_name, image_folder) — one row
// per real stock photo, 8 photos per theme. This returns the 9 DISTINCT
// theme_names, each with ONE representative photo (lowest id) for the
// selection card; the actual per-scene background is picked randomly from
// all of a theme's rows later, in vv_apply_theme_background().
// theme_key here is just the theme_name itself (e.g. "Haveli") — it's what
// gets stored on hdb_podcasts.theme_key and used to look the images back up.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_themes') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $themes = [];
    if (isset($conn)) {
        $q = mysqli_query($conn, "
            SELECT t1.theme_name, t1.image_name, t1.image_folder
            FROM hdb_theme_types t1
            INNER JOIN (
                SELECT theme_name, MIN(id) AS min_id FROM hdb_theme_types GROUP BY theme_name
            ) t2 ON t1.id = t2.min_id
            ORDER BY t1.theme_name ASC
            LIMIT 9
        ");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $folder = trim($r['image_folder'], '/');
                $themes[] = [
                    'theme_key'     => $r['theme_name'],
                    'theme_name'    => $r['theme_name'],
                    'preview_image' => $folder . '/' . $r['image_name'],
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'themes' => $themes]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Theme gallery: returns ALL of a theme's photos (not just the
// one representative preview), so the person can get a real feel for the
// theme before picking it. Used by the "🔍 view all" button on each theme card.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_theme_gallery') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $theme_name = trim($_POST['theme_name'] ?? '');
    $images = [];
    if ($theme_name !== '' && isset($conn)) {
        $esc_theme = mysqli_real_escape_string($conn, $theme_name);
        $q = mysqli_query($conn, "SELECT image_name, image_folder FROM hdb_theme_types WHERE theme_name='$esc_theme' ORDER BY id ASC");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $folder = trim($r['image_folder'], '/');
                $images[] = $folder . '/' . $r['image_name'];
            }
        }
    }

    echo json_encode(['success' => true, 'images' => $images]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_step1_model') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }
    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $title = trim($_POST['title'] ?? '');
    if ($title === '') { echo json_encode(['success'=>false,'message'=>'Video title is required']); exit; }
    $description = trim($_POST['description'] ?? '');

    $draft_id = trim($_POST['draft_id'] ?? '');
    if ($draft_id === '' || !preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) {
        $draft_id = 'd' . time() . mt_rand(1000, 9999);
    }

    $model_description = trim($_POST['model_description'] ?? '');
    if ($model_description === '') {
        echo json_encode(['success'=>false,'message'=>'Model description is required — pick a quick preset or type your own']); exit;
    }

    $resolution = trim($_POST['resolution'] ?? '1K');
    if (!in_array($resolution, ['1K', '2K'], true)) $resolution = '1K';

    // Background removal on the GENERATED model image defaults ON unless the
    // client explicitly sends 'false' — separate from the optional bg-removal
    // toggle on the raw garment upload preview, which this endpoint never touches.
    $removeBg = !(isset($_POST['remove_bg']) && $_POST['remove_bg'] === 'false');

    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);

    // ── Garment image — required on the very first generate, reused (no
    // re-upload needed) on every regenerate for the same draft_id ──────────
    $garment_path = null;
    if (!empty($_FILES['garment_image']['tmp_name']) && is_uploaded_file($_FILES['garment_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['garment_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            echo json_encode(['success'=>false,'message'=>'Invalid garment image type — use jpg, png, or webp']); exit;
        }
        if ($_FILES['garment_image']['size'] > 8 * 1024 * 1024) {
            echo json_encode(['success'=>false,'message'=>'Garment image too large (max 8MB)']); exit;
        }
        // Clear any previous extension's file for this draft before saving the new one
        foreach (['jpg','jpeg','png','webp'] as $oldExt) @unlink($pose_dir . "draft_{$draft_id}_garment.{$oldExt}");
        $garment_path = $pose_dir . "draft_{$draft_id}_garment.{$ext}";
        if (!move_uploaded_file($_FILES['garment_image']['tmp_name'], $garment_path)) {
            echo json_encode(['success'=>false,'message'=>'Could not save uploaded garment photo']); exit;
        }
    } else {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $try = $pose_dir . "draft_{$draft_id}_garment.{$ext}";
            if (is_file($try)) { $garment_path = $try; break; }
        }
    }
    if (!$garment_path || !is_file($garment_path)) {
        echo json_encode(['success'=>false,'message'=>'Please upload the dress/garment photo']); exit;
    }

    // ── Credits — flat 10 per generate (regenerate counts as a new generate).
    // This is the very first step of making a video, so it follows the same
    // plan-aware rule as the rest of the video-creation flow: a free_trial
    // person's allotment lives in credit_balance2, everyone else (including
    // a Team Member, charged against their team lead) uses real credit_balance.
    // If the relevant balance is short, this returns upgrade_needed so the
    // front end can send them to buy credits — scoped to come right back and
    // continue THIS video via return_url — instead of a dead-end error. ───
    $STEP1_COST = 10;
    $cred_user   = vv_safe_fetch($conn, "SELECT plan_type, role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $plan_t      = $cred_user['plan_type'] ?? 'free_trial';
    $balance_col = ($plan_t === 'free_trial') ? 'credit_balance2' : 'credit_balance';
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT $balance_col FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (float)($bal_row[$balance_col] ?? 0);
    if ($balance < $STEP1_COST) {
        echo json_encode([
            'success'        => false,
            'upgrade_needed' => true,
            'plan_type'      => $plan_t,
            'credit_balance' => $balance,
            'required'       => $STEP1_COST,
            'message'        => "This needs $STEP1_COST credits — you have " . (int)$balance . ".",
        ]); exit;
    }

    $defaultPrompt = "Replace the headless mannequin / dress form in this photo with a realistic human model — {$model_description} — wearing the exact same garment shown. "
        . "Keep the garment completely unchanged — same embroidery, same colors and color-blocking, same fabric, same drape, same hemline and floor length, same layering. "
        . "Add a natural face, neck, and skin in place of the mannequin form, with a pose matching the mannequin's current stance. "
        . "Keep the background, lighting, and camera angle exactly as in the original photo. Photorealistic, sharp focus.";

    $result = vv_generate_model_from_garment($falApiKey, $garment_path, $defaultPrompt, $resolution, $owner_id, $co_id, $draft_id);
    if (!$result['success']) { echo json_encode($result); exit; }

    $bgStep = null;
    if ($removeBg) {
        $bgStep = vv_remove_background_fal($falApiKey, $result['local_path'], $draft_id, $owner_id, $co_id);
    }
    $final       = ($removeBg && $bgStep && $bgStep['success']) ? $bgStep : $result;
    $bgRemoved   = (bool) ($removeBg && $bgStep && $bgStep['success']);
    $bgError     = ($removeBg && (!$bgStep || !$bgStep['success'])) ? ($bgStep['message'] ?? 'Background removal failed') : null;

    mysqli_query($conn, "UPDATE hdb_users SET $balance_col = GREATEST(0, $balance_col - $STEP1_COST) WHERE id=$deduct_from");

    vv_log("generate_step1_model: draft=$draft_id owner=$owner_id co=$co_id resolution=$resolution bg_removed=" . ($bgRemoved?'Y':'N') . " cost=$STEP1_COST charged_col=$balance_col user=$deduct_from");

    echo json_encode([
        'success'          => true,
        'draft_id'         => $draft_id,
        'title'            => $title,
        'description'      => $description,
        'public_url'       => $final['public_url'],
        'filename'         => $final['filename'],
        'pre_bg_url'       => $result['public_url'], // the un-bg-removed version, in case it's ever useful to show
        'bg_removed'       => $bgRemoved,
        'bg_removal_error' => $bgError,
        'resolution'       => $resolution,
        'model_description'=> $model_description,
        'credits_charged'  => $STEP1_COST,
        'credits_balance'  => $balance - $STEP1_COST,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 1: optional on-demand background removal for the
// GARMENT preview photo itself (separate from the auto bg-removal on the
// generated model output above). Uploads are kept as-is by default; this
// only runs if the person explicitly clicks "Remove Background" on the
// preview. Overwrites the saved draft garment file in place so the next
// generate (or regenerate) uses the cleaned version.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'step1_remove_garment_bg') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }
    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) { echo json_encode(['success'=>false,'message'=>'Missing draft_id']); exit; }

    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $garment_path = null;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $try = $pose_dir . "draft_{$draft_id}_garment.{$ext}";
        if (is_file($try)) { $garment_path = $try; break; }
    }
    if (!$garment_path) { echo json_encode(['success'=>false,'message'=>'Upload the garment photo first']); exit; }

    $GARMENT_BG_COST = 2;
    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);
    if ($balance < $GARMENT_BG_COST) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $GARMENT_BG_COST credits, you have $balance"]); exit;
    }

    $bgResult = vv_remove_background_fal($falApiKey, $garment_path, $draft_id . '_garment', $owner_id, $co_id);
    if (!$bgResult['success']) { echo json_encode($bgResult); exit; }

    // Replace the garment file used for generation with the bg-removed PNG —
    // delete the old (non-png) original so there's only ever one garment file per draft.
    $newGarmentPath = $pose_dir . "draft_{$draft_id}_garment.png";
    @copy($bgResult['local_path'], $newGarmentPath);
    if ($garment_path !== $newGarmentPath) @unlink($garment_path);
    @unlink($bgResult['local_path']);

    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $GARMENT_BG_COST) WHERE id=$deduct_from");

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir, '/')), '/') . '/';

    echo json_encode([
        'success'         => true,
        'public_url'      => $protocol . '://' . $host . $web_path . "draft_{$draft_id}_garment.png?v=" . time(),
        'credits_charged' => $GARMENT_BG_COST,
        'credits_balance' => $balance - $GARMENT_BG_COST,
    ]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_upload_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured in config.php']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $file = $_FILES['image'] ?? null;
    if (!$file || empty($file['tmp_name'])) { echo json_encode(['success'=>false,'error'=>'No file received']); exit; }

    $allowed = ['image/jpeg','image/png','image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) { echo json_encode(['success'=>false,'error'=>'Invalid file type']); exit; }
    if ($file['size'] > 15*1024*1024) { echo json_encode(['success'=>false,'error'=>'File too large (max 15MB)']); exit; }

    $garment_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    if (!is_dir($garment_dir)) @mkdir($garment_dir, 0777, true);

    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $base      = 'garment_' . time() . '_' . substr(md5($file['name'] . microtime()), 0, 8);
    $orig_path = $garment_dir . $base . '_orig.' . $ext;

    $moved = move_uploaded_file($file['tmp_name'], $orig_path);
    if (!$moved) $moved = @copy($file['tmp_name'], $orig_path);
    if (!$moved || !file_exists($orig_path)) {
        echo json_encode(['success'=>false,'error'=>'Could not save uploaded file.']); exit;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($garment_dir,'/')), '/') . '/';

    // ── Step 1: upload original to fal.ai storage ───────────────────────────
    // NOTE: uses the same VV_SITE_BASE_URL constant as the fashn/tryon handlers
    // below, not $protocol/$host built from request headers — those can drift
    // from the real site URL behind a proxy/CDN, which silently breaks this
    // upload and skips background removal entirely (falls back to the
    // un-removed-background original for the rest of the pipeline).
    //
    // Also shrink first: real photographer-shot dress photos are often
    // several MB, and base64-encoding inflates that further. POSTing that as
    // JSON to fal_proxy.php can exceed the host's own request-body ceiling —
    // confirmed: the host was returning a raw 413 "Request Entity Too Large"
    // page before PHP even ran. vv_shrink_for_upload() only checks pixel
    // dimensions (a modest-resolution but high-quality JPEG/PNG sails through
    // untouched), so use the byte-size-targeting version here instead.
    $upload_src_path  = vv_shrink_to_target_size($orig_path);
    $b64 = base64_encode(file_get_contents($upload_src_path));
    $upload_payload = json_encode(['base64'=>$b64,'mime_type'=>$mime,'file_name'=>$base.'.'.$ext]);
    $uch = curl_init(VV_SITE_BASE_URL . '/fal_proxy.php?action=upload');
    curl_setopt_array($uch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $upload_payload,
    ]);
    $ures  = curl_exec($uch);
    $uhttp = curl_getinfo($uch, CURLINFO_HTTP_CODE); // must come AFTER curl_exec, not before
    $uerr  = curl_error($uch);
    curl_close($uch);
    if ($upload_src_path !== $orig_path) @unlink($upload_src_path);
    $uj   = json_decode($ures, true);

    $white_path = $orig_path;                    // fallback chain — starts at original
    $white_file = $base . '_orig.' . $ext;
    $bg_removed = false;
    $bg_fail_reason = null;

    if (empty($uj['file_url'])) {
        // fal_proxy.php's 'upload' action returns a specific reason on failure
        // (token fetch failed / invalid token / upload failed / no URL in
        // response, each with a 'detail') — surface that instead of a generic
        // message, since which of those it is changes what's actually broken.
        // A request that never even reached fal_proxy.php's PHP code (e.g. the
        // web server itself rejecting an oversized body with a raw 413 page)
        // comes back as HTML, not JSON — call that out explicitly too.
        $looks_like_html = stripos((string)$ures, '<html') !== false || stripos((string)$ures, '<!DOCTYPE') !== false;
        if ($uerr) {
            $bg_fail_reason = "Could not reach fal_proxy.php (curl: $uerr)";
        } elseif ($looks_like_html) {
            $bg_fail_reason = "Web server rejected the upload request before fal_proxy.php ran (HTTP $uhttp) — likely a request-size limit. Raw response: " . substr((string)$ures, 0, 150);
        } elseif (!empty($uj['error'])) {
            $bg_fail_reason = 'fal_proxy.php: ' . $uj['error'] . (!empty($uj['detail']) ? ' — ' . substr((string)$uj['detail'], 0, 200) : '');
        } else {
            $bg_fail_reason = 'fal_proxy.php returned no file_url (HTTP '.$uhttp.'): ' . substr((string)$ures, 0, 200);
        }
        vv_log("garment_upload_image: fal storage upload failed | " . substr((string)$ures,0,300));
    } else {
        $fal_image_url = $uj['file_url'];

        // ── Step 2: remove background — fal-ai/imageutils/rembg (proven approach) ──
        $rembg_ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
        curl_setopt_array($rembg_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
            CURLOPT_POSTFIELDS     => json_encode(['image_url'=>$fal_image_url,'sync_mode'=>true]),
        ]);
        $rres  = curl_exec($rembg_ch);
        $rhttp = curl_getinfo($rembg_ch, CURLINFO_HTTP_CODE);
        curl_close($rembg_ch);
        $rj = json_decode($rres, true);

        if ($rhttp === 200 && !empty($rj['image']['url'])) {
            $png_data = @file_get_contents($rj['image']['url']);
            if ($png_data) {
                $png_path = $garment_dir . $base . '_rembg.png';
                file_put_contents($png_path, $png_data);
                $src = @imagecreatefrompng($png_path);
                if ($src) {
                    $iw = imagesx($src); $ih = imagesy($src);
                    $dst = imagecreatetruecolor($iw, $ih);
                    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                    imagealphablending($src, true);
                    imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
                    $white_file = $base . '_white.jpg';
                    $white_path = $garment_dir . $white_file;
                    imagejpeg($dst, $white_path, 95);
                    imagedestroy($src); imagedestroy($dst);
                    $bg_removed = true;
                } else {
                    $bg_fail_reason = 'rembg succeeded but the result image could not be decoded';
                    vv_log("garment_upload_image: rembg succeeded but GD could not decode result PNG for $base");
                }
                @unlink($png_path);
            } else {
                $bg_fail_reason = 'rembg succeeded but the result image could not be downloaded';
                vv_log("garment_upload_image: rembg succeeded but result image could not be downloaded for $base");
            }
        } else {
            $bg_fail_reason = "rembg failed (HTTP $rhttp)";
            vv_log("garment_upload_image: rembg failed (HTTP $rhttp): " . substr((string)$rres,0,300));
        }
    }

    // ── Step 3: enhance — fal-ai/clarity-upscaler (best-effort, falls back) ─
    $final_path = $white_path;
    $final_file = $white_file;

    $enh_src_data = @file_get_contents($white_path);
    if ($enh_src_data !== false) {
        $enhance_mime = ($white_path === $orig_path) ? $mime : 'image/jpeg';
        $data_uri     = 'data:' . $enhance_mime . ';base64,' . base64_encode($enh_src_data);

        $ech = curl_init('https://fal.run/fal-ai/clarity-upscaler');
        curl_setopt_array($ech, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
            CURLOPT_POSTFIELDS     => json_encode([
                'image_url'       => $data_uri,
                'prompt'          => 'high quality fabric, sharp embroidery detail, crisp pattern, photorealistic, best quality',
                'negative_prompt' => '(worst quality, low quality, normal quality:2), blurry, distorted pattern',
                'creativity'      => 0.3,
            ]),
        ]);
        $eres  = curl_exec($ech);
        $ehttp = curl_getinfo($ech, CURLINFO_HTTP_CODE);
        curl_close($ech);
        $ej = json_decode($eres, true);

        if ($ehttp === 200 && !empty($ej['image']['url'])) {
            $enh_data = @file_get_contents($ej['image']['url']);
            if ($enh_data) {
                $enh_file = $base . '_enhanced.jpg';
                $enh_path = $garment_dir . $enh_file;
                file_put_contents($enh_path, $enh_data);
                $final_path = $enh_path;
                $final_file = $enh_file;
            }
        } else {
            vv_log("garment_upload_image: enhance failed (HTTP $ehttp): " . substr((string)$eres,0,300));
        }
    }

    // ── Record in this folder's manifest so the gallery survives a reload ───
    // Each upload gets classified by view (front/back/left_side/right_side/
    // upper_half/lower_half) via OpenAI vision, so generate_garment_pose can
    // later pick the garment image that actually matches a given pose's angle.
    $view = vv_analyze_garment_view($apiKey, $final_path);

    $manifest_path = $garment_dir . '_manifest.json';
    $manifest = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];
    $manifest[] = ['filename'=>$final_file, 'created_at'=>date('c'), 'view'=>$view, 'generated'=>false, 'bg_removed'=>$bg_removed, 'bg_fail_reason'=>$bg_fail_reason];
    file_put_contents($manifest_path, json_encode($manifest));

    $final_url = $protocol.'://'.$host.$web_path.$final_file;
    $info = @getimagesize($final_path);

    echo json_encode([
        'success'        => true,
        'filename'       => $final_file,
        'url'            => $final_url,
        'view'           => $view,
        'bg_removed'     => $bg_removed,
        'bg_fail_reason' => $bg_fail_reason,
        'dimensions'     => ['w'=>$info[0]??0,'h'=>$info[1]??0],
    ]); exit;
}

// ── Generate ONE missing garment view per call ──────────────────────────────
// Deliberately one-view-per-request (not a batch loop) — flux-pro/kontext +
// rembg together can take up to ~150s per view, and looping 5 of those in a
// single PHP request blows past any execution time limit with no clean
// response, which is exactly what was hanging the UI before. The JS side
// loops this one call at a time so progress ("2 of 5…") can update between
// calls and a single failed view doesn't take the rest down with it.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_generate_one_view') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(170);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $target_view = trim($_POST['target_view'] ?? '');
    $valid_views = ['front','back','left_side','right_side','upper_half','lower_half'];
    if (!in_array($target_view, $valid_views)) {
        echo json_encode(['success'=>false,'error'=>'Invalid target_view']); exit;
    }

    $garment_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    if (!is_dir($garment_dir)) @mkdir($garment_dir, 0777, true);
    $manifest_path = $garment_dir . '_manifest.json';
    $manifest = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];

    // Skip if this view already exists (covers race conditions / re-clicks).
    foreach ($manifest as $m) {
        if (($m['view'] ?? '') === $target_view && !empty($m['filename']) && is_file($garment_dir . $m['filename'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'];
            $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($garment_dir,'/')), '/') . '/';
            echo json_encode(['success'=>true,'skipped'=>true,'filename'=>$m['filename'],'url'=>$protocol.'://'.$host.$web_path.$m['filename'],'view'=>$target_view,'generated'=>true]); exit;
        }
    }

    $front_path = vv_resolve_garment_for_view($owner_id, $co_id, 'front');
    if (!$front_path) {
        echo json_encode(['success'=>false,'error'=>'No garment image uploaded yet — upload at least one photo first']); exit;
    }

    $bytes = vv_generate_garment_view($falApiKey, $front_path, $target_view);
    if (!$bytes) {
        vv_log("garment_generate_one_view: failed to generate view=$target_view for owner=$owner_id co=$co_id");
        echo json_encode(['success'=>false,'error'=>'Generation failed for view: '.$target_view]); exit;
    }

    $fname = 'garment_gen_' . $target_view . '_' . time() . '.jpg';
    file_put_contents($garment_dir . $fname, $bytes);
    $manifest[] = ['filename'=>$fname, 'created_at'=>date('c'), 'view'=>$target_view, 'generated'=>true];
    file_put_contents($manifest_path, json_encode($manifest));

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($garment_dir,'/')), '/') . '/';
    echo json_encode(['success'=>true,'filename'=>$fname,'url'=>$protocol.'://'.$host.$web_path.$fname,'view'=>$target_view,'generated'=>true]); exit;
}

// ── List previously uploaded images (restores the gallery on page reload) ───
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_list_images') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>true,'images'=>[]]); exit; }

    $garment_dir   = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    $manifest_path = $garment_dir . '_manifest.json';
    $manifest      = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($garment_dir,'/')), '/') . '/';

    $rows = [];
    foreach ($manifest as $m) {
        if (empty($m['filename']) || !is_file($garment_dir . $m['filename'])) continue;
        $rows[] = [
            'filename'   => $m['filename'],
            'url'        => $protocol.'://'.$host.$web_path.$m['filename'],
            'view'       => $m['view'] ?? 'front',
            'generated'  => !empty($m['generated']),
            'bg_removed'     => array_key_exists('bg_removed', $m) ? !empty($m['bg_removed']) : true, // generated views are always already isolated
            'bg_fail_reason' => $m['bg_fail_reason'] ?? null,
        ];
    }
    echo json_encode(['success'=>true,'images'=>$rows]); exit;
}

// ── Reset for a fresh dress session — called automatically on page load ─────
// Garment images and finished pose results are keyed only by owner_id/
// company_id (images) or model_id/pose_code (poses), with nothing
// distinguishing "this dress" from a previous one. Without this, a new page
// load would silently show last session's dress photos, and worse, generating
// a pose for the same model would return last session's GARMENT cached under
// 'cached':true — i.e. the wrong dress, with no visible error.
// Deliberately leaves promo_models/ (bare body-per-pose cache) untouched —
// those don't depend on the garment at all, so reusing them is correct and
// saves real generation cost.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_clear_session') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>true]); exit; }

    $base_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/";
    foreach (['product_images/', 'poses/', 'poses/edits/'] as $sub) {
        $dir = $base_dir . $sub;
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '*') as $f) {
            if (is_file($f)) @unlink($f);
        }
    }
    $garment_dir = $base_dir . 'product_images/';
    if (!is_dir($garment_dir)) @mkdir($garment_dir, 0777, true);
    file_put_contents($garment_dir . '_manifest.json', json_encode([]));

    vv_log("garment_clear_session: wiped images+poses for owner=$owner_id co=$co_id");
    echo json_encode(['success'=>true]); exit;
}


if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_delete_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $fname = basename(trim($_POST['filename'] ?? ''));
    if (!$fname) { echo json_encode(['success'=>false,'error'=>'Missing filename']); exit; }

    $garment_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    $fpath = $garment_dir . $fname;
    if (is_file($fpath)) @unlink($fpath);

    $manifest_path = $garment_dir . '_manifest.json';
    if (file_exists($manifest_path)) {
        $manifest = json_decode(file_get_contents($manifest_path), true) ?: [];
        $manifest = array_values(array_filter($manifest, function($m) use ($fname) {
            return ($m['filename'] ?? '') !== $fname;
        }));
        file_put_contents($manifest_path, json_encode($manifest));
    }
    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 2: Select Model
// Scans promo_models/model_poses/ — every model from every garment type now
// lives in one shared folder (the per-type folder/model-type tier was
// removed), and groups files by model code, i.e. everything between the
// folder name and "_pose_p", e.g. female_casual_af_c1_pose_p1_front.jpg ->
// model_code = female_casual_af_c1, OR female_casual_la_pose_p2_right_side.jpg
// -> model_code = female_casual_la (no c1/c2 suffix at all — some models only
// have one variant per ethnicity code). For each distinct model_code, the
// LOWEST-numbered FRONT pose is used as the thumbnail since a model can have
// several front shots (p1_front, p5_front, etc.) — falls back to the
// lowest-numbered pose of any view if that model happens to have no front
// shot at all. Only the thumbnail is read here; every other pose for
// whichever model gets picked is read later.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_model_variants') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $folder = 'model_poses';
    $dir    = __DIR__ . '/promo_models/' . $folder;
    $variants = []; // model_code => state

    if (!is_dir($dir)) {
        vv_log("get_model_variants: directory not found - $dir");
        echo json_encode(['success'=>false,'error'=>'promo_models/model_poses not found on server']); exit;
    }

    // group 1 = model code (non-greedy, can itself contain underscores)
    // group 2 = pose number, group 3 = view name (front/back/left_side/right_side/...)
    $pattern = '/^(.+?)_pose_p(\d+)_([a-z]+)\.(jpe?g|png)$/i';
    foreach (scandir($dir) as $file) {
        if (!preg_match($pattern, $file, $m)) continue;
        $model_code = strtolower($m[1]);
        $pose_num   = (int)$m[2];
        $view       = strtolower($m[3]);

        if (!isset($variants[$model_code])) {
            $variants[$model_code] = [
                'front_file' => null, 'front_pose_num' => null,
                'any_file'   => null, 'any_pose_num'   => null,
            ];
        }
        if ($view === 'front' && ($variants[$model_code]['front_pose_num'] === null || $pose_num < $variants[$model_code]['front_pose_num'])) {
            $variants[$model_code]['front_pose_num'] = $pose_num;
            $variants[$model_code]['front_file']     = $file;
        }
        if ($variants[$model_code]['any_pose_num'] === null || $pose_num < $variants[$model_code]['any_pose_num']) {
            $variants[$model_code]['any_pose_num'] = $pose_num;
            $variants[$model_code]['any_file']     = $file;
        }
    }

    $out = [];
    foreach ($variants as $model_code => $v) {
        $file = $v['front_file'] ?? $v['any_file'];
        if (!$file) continue;
        $out[] = [
            'key'        => $model_code,
            'folder'     => $folder,
            'model_code' => $model_code,
            'file'       => $file,
            'url'        => 'promo_models/' . $folder . '/' . $file,
            'has_front'  => $v['front_file'] !== null,
        ];
    }
    usort($out, function($a, $b) { return strcmp($a['model_code'], $b['model_code']); });
    vv_log("get_model_variants: found " . count($out) . " model(s) in $folder");
    echo json_encode(['success'=>true,'variants'=>$out]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3: Pose Styles (mdl_model_pose_styles)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_pose_styles') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $q = mysqli_query($conn, "SELECT id, stylename, style_type, scene_1, scene_2, scene_3, scene_4, scene_5, scene_6, scene_7, scene_8, scene_9
                               FROM mdl_model_pose_styles ORDER BY id ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $scenes = [];
        for ($i = 1; $i <= 9; $i++) {
            $v = trim($r["scene_$i"] ?? '');
            if ($v !== '') $scenes[] = $v;
        }
        $rows[] = [
            'id'         => (int)$r['id'],
            'stylename'  => $r['stylename'],
            'style_type' => $r['style_type'],
            'scenes'     => $scenes,
        ];
    }
    echo json_encode(['success'=>true,'styles'=>$rows]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Generate one pose WITH garment, fashn applied directly
// ═════════════════════════════════════════════════════════════════════════════
// For each pose: get/generate that pose's bare body (template-driven, since
// the model's source photo is just a head with no body to work from), look
// up which garment VIEW matches this pose's angle (mdl_model_pose_templates.
// garment_view), and run fashn/tryon directly on that (bare body, matched
// garment view) pair. No character-repose step — every pose gets its own
// fashn call, but each one is shown the garment angle it actually needs
// instead of always the front photo, which is what was losing sleeves/back
// coverage before.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_garment_pose') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(120);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $model_id    = (int)($_POST['model_id'] ?? 0);
    $pose_code   = mysqli_real_escape_string($conn, trim($_POST['pose_code'] ?? ''));
    $garment_cat = trim($_POST['garment_category'] ?? 'one-pieces');
    $force       = !empty($_POST['force']);
    if (!$model_id || !$pose_code) {
        echo json_encode(['success'=>false,'error'=>'Missing model_id or pose_code']); exit;
    }

    $final_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($final_dir)) @mkdir($final_dir, 0777, true);
    $final_file = "model_id_{$model_id}_pose_{$pose_code}.jpg";
    $final_path = $final_dir . $final_file;

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($final_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$final_file;

    if (!$force && file_exists($final_path)) {
        echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>true]); exit;
    }

    // ── 1. Bare body for this pose (template-generated, no garment yet) ─────
    $bare = vv_get_or_create_bare_pose($conn, $falApiKey, $model_id, $pose_code, $force);
    if (!$bare['success']) {
        echo json_encode(['success'=>false,'error'=>'Pose body generation failed: '.$bare['error']]); exit;
    }

    // ── 2. Which garment view matches this pose's angle? ────────────────────
    @mysqli_query($conn, "ALTER TABLE mdl_model_pose_templates ADD COLUMN IF NOT EXISTS garment_view VARCHAR(20) DEFAULT 'front'");
    $pose_row = vv_safe_fetch($conn, "SELECT garment_view FROM mdl_model_pose_templates WHERE code='$pose_code' LIMIT 1");
    $garment_view = ($pose_row && !empty($pose_row['garment_view'])) ? $pose_row['garment_view'] : 'front';

    $garment_path = vv_resolve_garment_for_view($owner_id, $co_id, $garment_view);
    if (!$garment_path) {
        echo json_encode(['success'=>false,'error'=>'No garment image available — upload one in Step 1 first']); exit;
    }

    // ── 3. Upload both to fal storage, run fashn/tryon directly ─────────────
    $proxy_url   = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $uploadToFal = function($path) use ($proxy_url) {
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

    $body_upload_path = vv_shrink_for_upload($bare['path']);
    [$body_url, $bh, $berr, $bres] = $uploadToFal($body_upload_path);
    if ($body_upload_path !== $bare['path']) @unlink($body_upload_path);
    if (!$body_url) {
        vv_log("generate_garment_pose: body upload failed for pose=$pose_code (HTTP $bh, curl_err=$berr) | ".substr((string)$bres,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload pose body to fal.ai (HTTP $bh".($berr?", curl: $berr":'').')']); exit;
    }

    $garment_upload_path = vv_shrink_for_upload($garment_path);
    [$garment_url, $gh, $gerr, $gres] = $uploadToFal($garment_upload_path);
    if ($garment_upload_path !== $garment_path) @unlink($garment_upload_path);
    if (!$garment_url) {
        vv_log("generate_garment_pose: garment upload failed for pose=$pose_code view=$garment_view (HTTP $gh, curl_err=$gerr) | ".substr((string)$gres,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload garment image to fal.ai (HTTP $gh".($gerr?", curl: $gerr":'').')']); exit;
    }

    $fch = curl_init('https://fal.run/fal-ai/fashn/tryon/v1.6');
    curl_setopt_array($fch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'model_image'        => $body_url,
            'garment_image'      => $garment_url,
            'category'           => $garment_cat,
            'mode'               => 'quality',
            'garment_photo_type' => 'flat-lay', // garment images are isolated-on-white after Step 1, not on-model
            'sync_mode'          => true,
        ]),
    ]);
    $fres  = curl_exec($fch);
    $fhttp = curl_getinfo($fch, CURLINFO_HTTP_CODE);
    curl_close($fch);
    $fj = json_decode($fres, true);

    if ($fhttp !== 200 || empty($fj['images'][0]['url'])) {
        vv_log("generate_garment_pose: fashn/tryon failed for model=$model_id pose=$pose_code view=$garment_view (HTTP $fhttp) | ".substr((string)$fres,0,300));
        echo json_encode(['success'=>false,'error'=>'fashn/tryon failed (HTTP '.$fhttp.'): '.substr((string)$fres,0,300)]); exit;
    }

    $result_data = @file_get_contents($fj['images'][0]['url']);
    if (!$result_data) {
        echo json_encode(['success'=>false,'error'=>'Try-on result could not be downloaded']); exit;
    }
    file_put_contents($final_path, $result_data);
    vv_log("generate_garment_pose: generated model=$model_id pose=$pose_code garment_view=$garment_view -> $final_file");

    echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>false,'garment_view'=>$garment_view]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3: nano-banana-2/edit using the uploaded garment photo
// (background-removed in Step 1 — typically a mannequin/dress-form shot) as
// the BASE image, with the SELECTED MODEL'S photo passed as a second
// reference image for identity/styling only.
//
// REPLACES the fashn/tryon call that used to live here. Why: fashn/tryon
// regenerated the garment from scratch onto the model's body photo every
// time — exactly the failure mode that kept losing embroidery detail,
// color-blocking, and hem length on complex South Asian garments (asymmetric
// embroidery, multi-layer coats, floor-length hems). The garment photo from
// Step 1 already shows the garment correctly worn (almost always on a
// mannequin) — there's no need to regenerate it. nano-banana-2/edit instead
// treats that photo as the thing to EDIT (replace the mannequin/body with a
// person) rather than a garment spec to redraw from scratch, so embroidery,
// drape, color-blocking, and hemline survive untouched. The model's own
// photo is passed alongside purely so the edit has something to match
// appearance/styling against — nano-banana-2/edit accepts up to 14 reference
// images for exactly this kind of multi-image compositing task.
//
// AJAX contract (model_id, model_photo, garment_category, force →
// {success, url, cached}) is UNCHANGED from the old fashn version, so the
// front-end JS (generateModelTryon in STEP 3 below) needed zero changes.
//
// One result image is generated here and reused for EVERY scene in whichever
// style sequence gets approved — see create_podcast_from_poses below for how
// each scene still gets its own caption/video_prompt from the template table
// even though they all point at this same image.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_model_tryon') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(180); // nano-banana-2/edit can legitimately run longer than fashn/tryon did

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $model_id    = trim($_POST['model_id'] ?? '');
    $model_photo = trim($_POST['model_photo'] ?? ''); // e.g. "promo_models/model_poses/female_casual_af_c1_pose_p1_front.jpg"
    $garment_cat = trim($_POST['garment_category'] ?? 'one-pieces'); // no longer used by nano-banana-2/edit (it's prompt-driven, not category-enum-driven) — kept in the request only so the front-end doesn't need to change
    $force       = !empty($_POST['force']);
    if ($model_id === '' || $model_photo === '') {
        echo json_encode(['success'=>false,'error'=>'Missing model_id or model_photo']); exit;
    }

    // model_photo comes straight from the client (it's just whatever
    // get_model_variants returned), so lock it down to promo_models/model_poses/
    // before touching the filesystem with it.
    $model_photo = str_replace('\\', '/', $model_photo);
    if (!preg_match('#^promo_models/model_poses/[a-zA-Z0-9_.\-]+\.(jpe?g|png)$#i', $model_photo)) {
        echo json_encode(['success'=>false,'error'=>'Invalid model_photo path']); exit;
    }
    $model_photo_path = __DIR__ . '/' . $model_photo;
    if (!is_file($model_photo_path)) {
        echo json_encode(['success'=>false,'error'=>'Model photo not found on server: ' . $model_photo]); exit;
    }

    $safe_model_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $model_id);

    $final_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($final_dir)) @mkdir($final_dir, 0777, true);
    $final_file = "model_{$safe_model_key}_garment_tryon.jpg";
    $final_path = $final_dir . $final_file;

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($final_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$final_file;

    if (!$force && file_exists($final_path)) {
        echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>true]); exit;
    }

    $garment_path = vv_resolve_garment_for_view($owner_id, $co_id, 'front');
    if (!$garment_path) {
        echo json_encode(['success'=>false,'error'=>'No garment image available — upload one in Step 1 first']); exit;
    }

    $proxy_url   = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $uploadToFal = function($path) use ($proxy_url) {
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

    // NOTE: switched from vv_shrink_for_upload (pixel-dimension check only)
    // to vv_shrink_to_target_size (byte-size-targeting) for BOTH uploads here
    // — same fix already applied in garment_upload_image above, and the same
    // reason: a modest-resolution but high-quality JPEG/PNG can still be
    // several MB and trip the host's request-body ceiling (HTTP 413) even
    // when its pixel dimensions look perfectly reasonable.
    $body_upload_path = vv_shrink_to_target_size($model_photo_path);
    [$body_url, $bh, $berr, $bres] = $uploadToFal($body_upload_path);
    if ($body_upload_path !== $model_photo_path) @unlink($body_upload_path);
    if (!$body_url) {
        vv_log("generate_model_tryon: model reference upload failed for model=$model_id (HTTP $bh, curl_err=$berr) | ".substr((string)$bres,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload model reference photo to fal.ai (HTTP $bh".($berr?", curl: $berr":'').')']); exit;
    }

    $garment_upload_path = vv_shrink_to_target_size($garment_path);
    [$garment_url, $gh, $gerr, $gres] = $uploadToFal($garment_upload_path);
    if ($garment_upload_path !== $garment_path) @unlink($garment_upload_path);
    if (!$garment_url) {
        vv_log("generate_model_tryon: garment upload failed for model=$model_id (HTTP $gh, curl_err=$gerr) | ".substr((string)$gres,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload garment image to fal.ai (HTTP $gh".($gerr?", curl: $gerr":'').')']); exit;
    }

    // ── nano-banana-2/edit: image 1 = garment (base, gets edited), image 2 =
    // model reference (identity/styling only, never edited or merged in
    // literally — it's just what the new face/look should resemble).
    $prompt = "The first image shows a garment that is correctly worn — usually on a mannequin or dress form — with the background already removed. "
        . "The second image is a reference photo showing a model's appearance and styling. "
        . "Replace the mannequin or dress form in the FIRST image with a realistic human model whose general appearance, ethnicity, and styling matches the SECOND reference image. "
        . "Keep the garment from the first image completely unchanged — same embroidery, same colors and color-blocking, same fabric, same drape, same hemline and floor length, same layering. "
        . "Add a natural face, neck, and skin in place of the mannequin/dress form, with a pose matching the first image's current stance. "
        . "Keep the lighting and camera angle of the first image. Photorealistic, sharp focus.";

    $nb_payload = json_encode([
        'prompt'       => $prompt,
        'image_urls'   => [$garment_url, $body_url],
        'aspect_ratio' => 'auto',
        'resolution'   => '1K',
        'num_images'   => 1,
    ]);

    // Same no-double-bill retry rule as the FAL functions elsewhere in this
    // suite: only retry on a connect-phase failure (nothing came back at
    // all). A real HTTP response — even an error one — stops immediately,
    // since a timeout AFTER fal.ai actually received the request doesn't
    // mean the generation (and the bill for it) didn't happen.
    $httpCode = 0; $curlError = ''; $nbRes = null; $attempt = 0;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $nch = curl_init('https://fal.run/fal-ai/nano-banana-2/edit');
        curl_setopt_array($nch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
            CURLOPT_POSTFIELDS => $nb_payload,
        ]);
        $nbRes     = curl_exec($nch);
        $httpCode  = curl_getinfo($nch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($nch);
        $curlError = curl_error($nch);
        curl_close($nch);
        if ($curlErrno === 0 && $httpCode === 200 && $nbRes) break;
        if ($httpCode !== 0) break;
        if ($attempt < 2) sleep(3);
    }

    $nj        = json_decode($nbRes, true);
    $resultUrl = $nj['images'][0]['url'] ?? null;

    if ($httpCode !== 200 || !$resultUrl) {
        vv_log("generate_model_tryon: nano-banana-2/edit failed for model=$model_id (HTTP $httpCode, attempt=$attempt) | ".substr((string)$nbRes,0,300));
        echo json_encode(['success'=>false,'error'=>'nano-banana-2/edit failed (HTTP '.$httpCode.'): '.substr((string)$nbRes,0,300)]); exit;
    }

    $result_data = @file_get_contents($resultUrl);
    if (!$result_data) {
        echo json_encode(['success'=>false,'error'=>'Try-on result could not be downloaded']); exit;
    }
    file_put_contents($final_path, $result_data);
    vv_log("generate_model_tryon: generated model=$model_id -> $final_file (nano-banana-2/edit)");

    echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>false]); exit;
}

// ── Image-to-image patch on the single model+garment try-on result (mirrors
// garment_pose_edit below, but against the one-image-per-model file from
// generate_model_tryon instead of the old per-pose file naming). ────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_tryon_edit') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(90);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $model_id    = trim($_POST['model_id'] ?? '');
    $edit_prompt = trim($_POST['edit_prompt'] ?? '');
    if ($model_id === '' || $edit_prompt === '') {
        echo json_encode(['success'=>false,'error'=>'Missing model_id or edit_prompt']); exit;
    }

    $safe_model_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $model_id);
    $pose_dir    = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $source_path = $pose_dir . "model_{$safe_model_key}_garment_tryon.jpg";
    if (!is_file($source_path)) {
        echo json_encode(['success'=>false,'error'=>'Generate the try-on image first']); exit;
    }

    $proxy_url = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $ch = curl_init($proxy_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'base64'    => base64_encode(file_get_contents($source_path)),
            'mime_type' => mime_content_type($source_path),
            'file_name' => basename($source_path),
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $source_url = json_decode($res, true)['file_url'] ?? null;
    if (!$source_url) {
        vv_log("garment_tryon_edit: upload failed for model=$model_id (HTTP $http, curl_err=$err)");
        echo json_encode(['success'=>false,'error'=>"Failed to upload source image (HTTP $http".($err?", curl: $err":'').')']); exit;
    }

    $kch = curl_init('https://fal.run/fal-ai/flux-pro/kontext');
    curl_setopt_array($kch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt'    => $edit_prompt,
            'image_url' => $source_url,
            'sync_mode' => true,
        ]),
    ]);
    $kres  = curl_exec($kch);
    $khttp = curl_getinfo($kch, CURLINFO_HTTP_CODE);
    curl_close($kch);
    $kj = json_decode($kres, true);

    if ($khttp !== 200 || empty($kj['images'][0]['url'])) {
        $detail = substr((string)$kres, 0, 300);
        vv_log("garment_tryon_edit: flux-pro/kontext failed for model=$model_id | $detail");
        echo json_encode(['success'=>false,'error'=>'Image edit failed (HTTP '.$khttp.'): '.$detail]); exit;
    }

    $edit_data = @file_get_contents($kj['images'][0]['url']);
    if (!$edit_data) { echo json_encode(['success'=>false,'error'=>'Edit result could not be downloaded']); exit; }

    $edit_file = "model_{$safe_model_key}_garment_tryon_edit_" . time() . '.jpg';
    $edit_path = $pose_dir . $edit_file;
    file_put_contents($edit_path, $edit_data);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir,'/')), '/') . '/';

    vv_log("garment_tryon_edit: model=$model_id prompt=\"$edit_prompt\" -> $edit_file");
    echo json_encode(['success'=>true,'url'=>$protocol.'://'.$host.$web_path.$edit_file,'prompt'=>$edit_prompt]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Image-to-image patch on an existing fashn/tryon result
// ═════════════════════════════════════════════════════════════════════════════
// Point at a specific defect ("make this full sleeve") and have
// fal-ai/flux-pro/kontext edit just that, instead of re-rolling the whole
// fashn call. Saved as a SEPARATE file under poses/edits/ — the original
// fashn result at poses/model_id_{id}_pose_{code}.jpg is never touched, so
// the fashn output and the image-to-image edit can be compared side by side.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_pose_edit') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(120);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $model_id    = (int)($_POST['model_id'] ?? 0);
    $pose_code   = mysqli_real_escape_string($conn, trim($_POST['pose_code'] ?? ''));
    $edit_prompt = trim($_POST['edit_prompt'] ?? '');
    if (!$model_id || !$pose_code || !$edit_prompt) {
        echo json_encode(['success'=>false,'error'=>'Missing model_id, pose_code, or edit_prompt']); exit;
    }

    $pose_dir    = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $source_path = $pose_dir . "model_id_{$model_id}_pose_{$pose_code}.jpg";
    if (!is_file($source_path)) {
        echo json_encode(['success'=>false,'error'=>'No fashn result yet for this pose — generate it first']); exit;
    }

    $src_bytes = @file_get_contents($source_path);
    if ($src_bytes === false) {
        echo json_encode(['success'=>false,'error'=>'Could not read source pose image']); exit;
    }
    $src_data_uri = 'data:image/jpeg;base64,' . base64_encode($src_bytes);

    $full_prompt = $edit_prompt
        . '. Keep the model\'s pose, face, body proportions, and the background exactly the same — change only what was just described.';

    $kch = curl_init('https://fal.run/fal-ai/flux-pro/kontext');
    curl_setopt_array($kch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt'              => $full_prompt,
            'image_url'           => $src_data_uri,
            'sync_mode'           => true,
            'num_images'          => 1,
            'guidance_scale'      => 3.5,
            'num_inference_steps' => 28,
        ]),
    ]);
    $kres  = curl_exec($kch);
    $khttp = curl_getinfo($kch, CURLINFO_HTTP_CODE);
    $kerr  = curl_error($kch);
    curl_close($kch);
    $kj      = json_decode($kres, true);
    $gen_url = $kj['images'][0]['url'] ?? null;

    if (!$gen_url) {
        $detail = $kerr ? "curl error: $kerr" : ('HTTP '.$khttp.' — '.substr((string)$kres,0,200));
        vv_log("garment_pose_edit: flux-pro/kontext failed for model=$model_id pose=$pose_code | $detail");
        echo json_encode(['success'=>false,'error'=>"Edit failed ($detail)"]); exit;
    }

    $img_data = @file_get_contents($gen_url);
    if (!$img_data) {
        echo json_encode(['success'=>false,'error'=>'Edited image could not be downloaded']); exit;
    }

    $edit_dir = $pose_dir . 'edits/';
    if (!is_dir($edit_dir)) @mkdir($edit_dir, 0777, true);
    $edit_file = "model_id_{$model_id}_pose_{$pose_code}_edit_" . time() . '.jpg';
    file_put_contents($edit_dir . $edit_file, $img_data);

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($edit_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$edit_file;

    vv_log("garment_pose_edit: model=$model_id pose=$pose_code prompt=\"$edit_prompt\" -> $edit_file");
    echo json_encode(['success'=>true,'url'=>$public_url,'prompt'=>$edit_prompt]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3 (new workflow): build hdb_podcasts / hdb_podcast_stories
// / hdb_captions from the Step 1 generated image + Step 2 selected style.
// ═════════════════════════════════════════════════════════════════════════════
// FREE — no fal.ai call happens here, so no credits are charged. Every scene
// reuses the single Step 1 image (already paid for), and each scene's
// video_prompt/prompt comes straight from mdl_model_pose_templates by pose
// code — same lookup create_podcast_from_poses already used (LOWER(TRIM(code))
// match against the style's scene_N column), just reused here rather than
// rebuilt. The 20cr/scene cost is only checked+charged later when Step 5
// actually fires video generation per scene — Step 3 just lays the rows down
// so the person can review/edit captions first.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_podcast_from_step1') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    $style_id = (int)($_POST['style_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $caption_style = trim($_POST['caption_style'] ?? 'Elegant');
    $theme_key      = trim($_POST['theme_key'] ?? '');
    $theme_location = trim($_POST['theme_location_prompt'] ?? '');
    if (!in_array($caption_style, ['Elegant', 'Emotional', 'Premium', 'Sales-Oriented'], true)) $caption_style = 'Elegant';
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id) || !$style_id || $title === '') {
        echo json_encode(['success'=>false,'message'=>'Missing draft_id, style_id, or title']); exit;
    }

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS draft_id VARCHAR(40) DEFAULT NULL");
    $esc_draft_id = mysqli_real_escape_string($conn, $draft_id);
    $existing = vv_safe_fetch($conn, "SELECT id FROM hdb_podcasts WHERE draft_id='$esc_draft_id' AND admin_id=$admin_id LIMIT 1");
    if ($existing) {
        echo json_encode(['success'=>false,'message'=>"Scenes were already built for this draft (podcast #{$existing['id']}) — refresh Step 1 to start a new one."]); exit;
    }

    $style_row = vv_safe_fetch($conn, "SELECT * FROM mdl_model_pose_styles WHERE id=$style_id LIMIT 1");
    if (!$style_row) { echo json_encode(['success'=>false,'message'=>'Style not found']); exit; }

    // ── Locate the Step 1 image — bg-removed version wins if it exists ──────
    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $image_file = null;
    foreach (["draft_{$draft_id}_model_nobg.png", "draft_{$draft_id}_model.jpg"] as $cand) {
        if (is_file($pose_dir . $cand)) { $image_file = $cand; break; }
    }
    if (!$image_file) {
        echo json_encode(['success'=>false,'message'=>'Generate the model image in Step 2 first']); exit;
    }
    $protocol     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host         = $_SERVER['HTTP_HOST'];
    $doc_root     = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $image_folder = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir, '/')), '/') . '/';
    $image_url    = $protocol . '://' . $host . $image_folder . $image_file;

    $scenes_in = [];
    for ($i = 1; $i <= 9; $i++) {
        $code = trim($style_row["scene_$i"] ?? '');
        if ($code !== '') $scenes_in[] = $code;
    }
    if (empty($scenes_in)) { echo json_encode(['success'=>false,'message'=>'Style has no scenes']); exit; }

    // ── Target video length — 30/45/60s, chosen in the UI ───────────────────
    // LTX 2.3 (Step 5's video model) won't generate a clip shorter than 6s
    // per scene, so dividing evenly can push the ACTUAL total over the
    // target (e.g. 6 scenes × 6s floor = 36s even if 30s was requested).
    // That's expected — billing stays 20cr/scene regardless of how the
    // seconds land, never recalculated off the final duration.
    $duration_target = (int)($_POST['duration_target'] ?? 30);
    if (!in_array($duration_target, [30, 45, 60], true)) $duration_target = 30;

    $scene_total = count($scenes_in);
    $usable_durations = []; // plain indexed list — NOT keyed by pose_code, so
                             // repeated codes across scenes (e.g. two scenes
                             // both "F1") don't silently collapse into one.
    foreach ($scenes_in as $pose_code) {
        $duration = $scene_total > 0 ? (int)ceil($duration_target / $scene_total) : 6;
        $usable_durations[] = max(6, min($duration, 12)); // 6s floor (LTX 2.3), 12s ceiling
    }
    $actual_total_secs = array_sum($usable_durations);

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_group VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_subgroup VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS thumbnail VARCHAR(500) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS theme_key VARCHAR(60) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS theme_location_prompt TEXT DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    $_co_row    = vv_safe_fetch($conn, "SELECT group_name, subgroup_name, ai_group, ai_subgroup, target_location, target_audience FROM hdb_companies WHERE id=$co_id LIMIT 1") ?: [];
    $category   = ($_co_row['ai_subgroup'] ?? '') ?: ($_co_row['subgroup_name'] ?? '') ?: 'fashion';
    $ai_group   = ($_co_row['ai_group']    ?? '') ?: ($_co_row['group_name']    ?? '') ?: $category;
    $ai_subgrp  = ($_co_row['ai_subgroup'] ?? '') ?: ($_co_row['subgroup_name'] ?? '') ?: $category;
    $loc_part   = trim($_co_row['target_location'] ?? '');
    $aud_part   = trim($_co_row['target_audience'] ?? '');

    // ── Pre-compute each scene's caption/prompt from the template BEFORE
    // inserting anything, so hashtags/keywords can be built from the actual
    // caption text up front. Iterates scenes_in BY POSITION (scene_1, scene_2,
    // ...) so the seq_no / duration / pose_code triple always lines up,
    // even when two scenes in the sequence share the same code. ───────────
    $scene_data = [];
    $template_debug = []; // returned in the JSON response so the exact
                           // lookup result per scene is visible without
                           // digging through server logs.
    @mysqli_query($conn, "ALTER TABLE mdl_model_pose_templates ADD COLUMN IF NOT EXISTS garment_view VARCHAR(20) DEFAULT 'front'");
    foreach ($scenes_in as $idx => $pose_code) {
        $duration  = $usable_durations[$idx];
        $safe_code = mysqli_real_escape_string($conn, $pose_code);
        $sql_tpl   = "SELECT code, image_prompt, video_prompt, garment_view FROM mdl_model_pose_templates WHERE LOWER(TRIM(code)) = LOWER(TRIM('$safe_code')) LIMIT 1";
        $tpl       = vv_safe_fetch($conn, $sql_tpl);

        $tpl_video_prompt = trim($tpl['video_prompt'] ?? '');
        $tpl_image_prompt = trim($tpl['image_prompt'] ?? '');
        $prompt_text  = $tpl_video_prompt ?: $tpl_image_prompt ?: $pose_code;
        $garment_view = $tpl['garment_view'] ?? 'front';

        vv_log("create_podcast_from_step1: scene " . ($idx+1) . " pose_code='$pose_code' "
            . ($tpl ? "MATCHED code='{$tpl['code']}' video_prompt=\"" . substr($tpl_video_prompt, 0, 80) . "\"" : 'NO MATCH')
            . " | SQL: $sql_tpl");

        $scene_data[] = [
            'pose_code'    => $pose_code,
            'duration'     => $duration,
            'prompt_text'  => $prompt_text,   // → both prompt + video_prompt columns
            'garment_view' => $garment_view,
            'caption'      => vv_make_sleek_caption($prompt_text, $garment_view),
        ];
        $template_debug[] = [
            'seq_no'           => $idx + 1,
            'pose_code'        => $pose_code,
            'matched'          => (bool) $tpl,
            'video_prompt_used'=> $prompt_text,
        ];
    }

    // ── AI-generated sequential captions (intro → details → craftsmanship →
    // movement → styling → complete look → CTA), one per scene, using the
    // product description / brand tone / audience / caption style. Falls
    // back to the template-derived captions above if the API call fails. ──
    $brand_tone_map = [
        'bridal wear'                     => 'Bridal',
        "indian/pakistani bridal wear"    => 'Bridal',
        'party wear'                      => 'Premium',
        'jewellery'                       => 'Luxury',
    ];
    $brand_tone = $brand_tone_map[strtolower($ai_subgrp)] ?? 'Contemporary';

    $ai_captions = vv_generate_scene_captions_ai(
        $apiKey,
        count($scene_data),
        $description,
        $brand_tone,
        $aud_part,
        $caption_style,
        array_column($scene_data, 'caption')
    );
    foreach ($scene_data as $i => &$sd_ref) {
        if (isset($ai_captions[$i]) && trim($ai_captions[$i]) !== '') $sd_ref['caption'] = trim($ai_captions[$i]);
    }
    unset($sd_ref);

    [$hashtags, $keywords] = vv_build_hashtags_keywords(
        $conn,
        $ai_subgrp, $brand_tone, $description, $loc_part, $aud_part
    );

    $esc_title = mysqli_real_escape_string($conn, $title);
    $esc_ai_group    = mysqli_real_escape_string($conn, $ai_group);
    $esc_ai_subgroup = mysqli_real_escape_string($conn, $ai_subgrp);
    $esc_category    = mysqli_real_escape_string($conn, $category);
    $lang_code = 'en';
    $reel_type = 'tryon';
    $topic_key = 'fashion';
    $today     = date('Y-m-d');
    $esc_theme_key      = mysqli_real_escape_string($conn, $theme_key);
    $esc_theme_location = mysqli_real_escape_string($conn, $theme_location);

    $sql_pod = "INSERT INTO hdb_podcasts
        (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
         created_date, updated_at, niche, category, topic_key, hashtags, keywords,
         host_voice, guest_voice, voice_rate, is_campaign,
         logo_flag, facebook_status, tiktok_status, instagram_status,
         youtube_status, twitter_status, linkedin_status,
         schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name,
         videogen_flag, ai_group, ai_subgroup, draft_id, theme_key, theme_location_prompt)
        VALUES
        ($admin_id, $admin_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'scenes_ready',
         '$today', NOW(), 'fashion', '$esc_category', '$topic_key', '$hashtags', '$keywords',
         '', '', 1.0, 0,
         0, 'pending', 'pending', 'pending',
         'pending', 'pending', 'pending',
         '$today', '09:00', '$today', 'vertical', 'video', '', '',
         1, '$esc_ai_group', '$esc_ai_subgroup', '$esc_draft_id', '$esc_theme_key', '$esc_theme_location')";

    if (!mysqli_query($conn, $sql_pod)) {
        echo json_encode(['success'=>false,'message'=>'hdb_podcasts INSERT failed: ' . mysqli_error($conn)]); exit;
    }
    $podcast_id  = mysqli_insert_id($conn);

    // ── Thumbnail — copy the Step 1 model+garment image into a dedicated
    // podcast_thumbnails/ folder, sitting flat alongside this script (NOT
    // nested under user_media/user_id_X_company_id_Y/), and record its path
    // on the podcast row itself. Anchored to __DIR__ (same as $pose_dir
    // above) rather than $_SERVER['DOCUMENT_ROOT'] — DOCUMENT_ROOT proved
    // unreliable on this host and was landing thumbnails one level too deep,
    // inside the per-user poses folder instead of videovizard/podcast_thumbnails/. ──
    $thumb_dir = __DIR__ . '/podcast_thumbnails/';
    vv_log("create_podcast_from_step1: thumb_dir resolved to $thumb_dir (DOCUMENT_ROOT was: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unset') . ")");
    $thumb_ext      = strtolower(pathinfo($image_file, PATHINFO_EXTENSION)) ?: 'jpg';
    $thumb_filename = "podcast_{$podcast_id}_thumb.{$thumb_ext}";
    $thumb_full_path = $thumb_dir . $thumb_filename;
    $thumb_source_path = $pose_dir . $image_file;

    $thumb_saved = false;
    $thumb_error = null;

    if (!is_dir($thumb_dir) && !mkdir($thumb_dir, 0777, true) && !is_dir($thumb_dir)) {
        $thumb_error = 'mkdir failed for ' . $thumb_dir;
    } elseif (!is_file($thumb_source_path)) {
        $thumb_error = "source image missing at $thumb_source_path";
    } elseif (!is_writable($thumb_dir)) {
        $thumb_error = "$thumb_dir is not writable";
    } else {
        $thumb_saved = copy($thumb_source_path, $thumb_full_path);
        if (!$thumb_saved) {
            $e = error_get_last();
            $thumb_error = 'copy() returned false' . ($e ? (' — ' . $e['message']) : '');
        } elseif (!is_file($thumb_full_path)) {
            // copy() reported success but the file isn't actually there — treat as failure
            $thumb_saved = false;
            $thumb_error = 'copy() returned true but file not found after copy';
        }
    }

    $thumb_rel_path = null;
    if ($thumb_saved) {
        $thumb_rel_path = "videovizard/podcast_thumbnails/{$thumb_filename}";
        mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='" . mysqli_real_escape_string($conn, $thumb_rel_path) . "' WHERE id=$podcast_id LIMIT 1");
        vv_log("create_podcast_from_step1: thumbnail saved -> $thumb_full_path");
    } else {
        vv_log("create_podcast_from_step1: THUMBNAIL COPY FAILED for podcast=$podcast_id | source=$thumb_source_path | dest=$thumb_full_path | reason=$thumb_error");
    }

    $cap_settings = vv_get_user_caption_settings($conn, $admin_id, $co_id);

    $scenes_out = [];
    $seq_no = 0;
    foreach ($scene_data as $sd) {
        $seq_no++;
        $text  = ucfirst(str_replace('_', ' ', $sd['garment_view'])) . ' — ' . $sd['caption'];
        $te  = mysqli_real_escape_string($conn, $sd['caption']);
        $de  = mysqli_real_escape_string($conn, substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''));
        $pe  = mysqli_real_escape_string($conn, $sd['prompt_text']); // ← video prompt FROM the style template, both columns
        $ife = mysqli_real_escape_string($conn, $image_file);
        $ifo = mysqli_real_escape_string($conn, $image_folder);
        $tke = $esc_title;

        $ins = "INSERT INTO hdb_podcast_stories
            (podcast_id, lang_code, category, topic_key, title, actor,
             text_contents, text_display, duration, prompt, video_prompt, visual_type,
             status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
             voice_id, voice_rate, image_file, image_folder, videogen_flag)
            VALUES
            ($podcast_id, '$lang_code', '$esc_category', '$topic_key', '$tke', 'host',
             '$te', '$de', {$sd['duration']}, '$pe', '$pe', 'promo',
             'PENDING', NOW(), $seq_no, 0, '', '',
             '', 1.0, '$ife', '$ifo', 1)";

        if (!mysqli_query($conn, $ins)) {
            vv_log("create_podcast_from_step1: story insert failed pose={$sd['pose_code']} | " . mysqli_error($conn));
            continue;
        }
        $story_id = mysqli_insert_id($conn);
        vv_insert_scene_captions($conn, $podcast_id, $story_id, $sd['caption'], $cap_settings);

        $scenes_out[] = [
            'story_id'  => $story_id,
            'seq_no'    => $seq_no,
            'pose_code' => $sd['pose_code'],
            'caption'   => $sd['caption'],
            'image_url' => $image_url,
        ];
    }

    if (empty($scenes_out)) {
        echo json_encode(['success'=>false,'message'=>'All scene inserts failed — check error log']); exit;
    }

    vv_log("create_podcast_from_step1: created podcast=$podcast_id with " . count($scenes_out) . " scenes (draft=$draft_id style=$style_id) target={$duration_target}s actual={$actual_total_secs}s");
    echo json_encode([
        'success'           => true,
        'podcast_id'        => $podcast_id,
        'scenes'            => $scenes_out,
        'scene_count'       => count($scenes_out),
        'estimated_cost'    => count($scenes_out) * 20, // 20cr/scene — charged later at Step 6 firing, not here
        'duration_target'   => $duration_target,
        'actual_total_secs' => $actual_total_secs, // may exceed duration_target — LTX 2.3's 6s/scene floor
        'thumbnail_url'     => $image_url,
        'thumbnail_saved'   => $thumb_saved,
        'thumbnail_error'   => $thumb_saved ? null : $thumb_error,
        'template_debug'    => $template_debug, // per-scene: pose_code, matched?, video_prompt actually used
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3: generate ONE scene's pose image, via fal AI editing
// the Step 1 model+garment image into a new pose. Called once per scene from
// the front-end AFTER create_podcast_from_step1 has already created all the
// rows with the shared Step 1 image — same "one AJAX call per generation"
// pattern this codebase already uses for garment views, to avoid a single
// PHP request blocking for 6-12 sequential fal calls and timing out.
// Charges 5cr per scene, ONLY on success — a failed pose generation leaves
// the scene's existing image untouched (and uncharged) rather than blocking
// the rest of the batch.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_pose') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $story_id = (int)($_POST['story_id'] ?? 0);
    if (!$story_id) { echo json_encode(['success'=>false,'message'=>'Missing story_id']); exit; }

    $story = vv_safe_fetch($conn, "SELECT s.id, s.podcast_id, s.image_file, s.image_folder, s.prompt, p.admin_id AS pod_admin_id, p.theme_key
        FROM hdb_podcast_stories s JOIN hdb_podcasts p ON p.id = s.podcast_id WHERE s.id=$story_id LIMIT 1");
    if (!$story) { echo json_encode(['success'=>false,'message'=>'Scene not found']); exit; }

    // Ownership is encoded right in the stored image_folder path
    // (".../user_id_{N}_company_id_{M}/poses/") — read it back from there
    // rather than re-resolving, so this still works if a team member who
    // isn't the owner triggers it later.
    if (!preg_match('#user_id_(\d+)_company_id_(\d+)#', $story['image_folder'] ?? '', $m)) {
        echo json_encode(['success'=>false,'message'=>'Could not resolve storage path for this scene']); exit;
    }
    [$owner_id, $co_id] = [(int)$m[1], (int)$m[2]];
    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $source_path = $pose_dir . $story['image_file'];
    if (!is_file($source_path)) { echo json_encode(['success'=>false,'message'=>"Source image missing: {$story['image_file']}"]); exit; }

    // ── Credits — flat 5cr, charged from the CURRENT logged-in user/team-lead,
    // only deducted after a successful generation. ALWAYS comes out of
    // credit_balance (never credit_balance2) — credit_balance2 (the free-
    // trial allotment) is only ever checked/deducted at AI-video-generation
    // time, not for these per-scene pose/theme image regenerations. This
    // applies the same whether it's the auto-generated pose or a person's
    // own custom prompt below — same cost, same balance, every time. ──────
    $SCENE_POSE_COST = 5;
    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);
    if ($balance < $SCENE_POSE_COST) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $SCENE_POSE_COST credits, you have $balance"]); exit;
    }

    // ── Pose description — a person's own custom_prompt (when they weren't
    // happy with what the AI produced) overrides the auto-generated pose
    // description from mdl_model_pose_templates, but still goes through the
    // SAME identity-preserving wrapper below (same model/garment, only the
    // pose/scene changes) so a custom prompt can't accidentally drift the
    // model's face or the garment itself. ──────────────────────────────────
    $custom_prompt = trim($_POST['custom_prompt'] ?? '');
    $used_custom   = ($custom_prompt !== '');
    $pose_description = $used_custom ? $custom_prompt : trim($story['prompt'] ?? '');
    $pose_prompt = "Same exact model — same face, same identity, same hair — wearing the exact same garment unchanged "
        . "(same color, embroidery, fabric, drape, hemline). Change ONLY the pose/angle/movement to: {$pose_description}. "
        . "Keep the background, lighting, and overall image quality consistent with the original photo. Photorealistic, sharp focus.";

    $result = vv_generate_model_from_garment($falApiKey, $source_path, $pose_prompt, '1K', $owner_id, $co_id, "pose{$story_id}");
    if (!$result['success']) {
        vv_log("generate_scene_pose: FAILED story=$story_id | " . ($result['message'] ?? 'unknown'));
        echo json_encode($result); exit;
    }

    // ── Themed background (Haveli/Castle/Desert/Studio/etc.) — if this
    // podcast has a theme set, run the freshly-posed image through
    // rembg → composite onto a randomly-picked REAL photo from that
    // theme's pool in hdb_theme_types. A different random photo gets
    // picked per scene, which is what gives variety across a scene
    // sequence without needing a separate AI generation step. Folded into
    // the same 5cr pose charge — not billed separately. A failure here
    // just keeps the plain (non-themed) pose result rather than blocking
    // the scene. ─────────────────────────────────────────────────────────
    $theme_applied = false;
    $theme_error    = null;
    $theme_name = trim($story['theme_key'] ?? '');
    if ($theme_name !== '') {
        $themed = vv_apply_theme_background($conn, $falApiKey, $result['local_path'], $theme_name, $owner_id, $co_id, "pose{$story_id}");
        if ($themed['success']) {
            $result = $themed; // swap in the themed composite as the final result
            $theme_applied = true;
        } else {
            $theme_error = $themed['message'] ?? 'theme background failed';
            vv_log("generate_scene_pose: theme background FAILED story=$story_id | $theme_error — keeping plain pose");
        }
    }

    $esc_file   = mysqli_real_escape_string($conn, $result['filename']);
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$esc_file' WHERE id=$story_id LIMIT 1");
    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $SCENE_POSE_COST) WHERE id=$deduct_from");

    vv_log("generate_scene_pose: OK story=$story_id -> {$result['filename']} cost=$SCENE_POSE_COST theme_applied=" . ($theme_applied?'Y':'N') . " custom_prompt=" . ($used_custom?'Y':'N'));
    echo json_encode([
        'success'         => true,
        'story_id'        => $story_id,
        'public_url'      => $result['public_url'],
        'credits_charged' => $SCENE_POSE_COST,
        'credits_balance' => $balance - $SCENE_POSE_COST,
        'theme_applied'   => $theme_applied,
        'theme_error'     => $theme_error,
        'used_custom_prompt' => $used_custom,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Change a scene's BACKGROUND after the fact (called from the
// videomaker.php editor's "Change Background" icon). Does NOT touch the
// model/pose at all — rembg's whatever is currently in image_file (works
// fine even though that's already a themed composite; rembg segments the
// actual subject regardless of what's behind them) and composites onto a
// freshly-picked photo from the requested (or current) theme. Free — no
// fal.ai cost beyond the one rembg call already folded into
// vv_apply_theme_background(), same as scene generation.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_scene_background') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $story_id   = (int)($_POST['story_id'] ?? 0);
    $theme_name = trim($_POST['theme_name'] ?? '');
    if (!$story_id) { echo json_encode(['success'=>false,'message'=>'Missing story_id']); exit; }

    $story = vv_safe_fetch($conn, "SELECT s.id, s.podcast_id, s.image_file, s.image_folder, p.theme_key
        FROM hdb_podcast_stories s JOIN hdb_podcasts p ON p.id = s.podcast_id WHERE s.id=$story_id LIMIT 1");
    if (!$story) { echo json_encode(['success'=>false,'message'=>'Scene not found']); exit; }

    if (!preg_match('#user_id_(\d+)_company_id_(\d+)#', $story['image_folder'] ?? '', $m)) {
        echo json_encode(['success'=>false,'message'=>'Could not resolve storage path for this scene']); exit;
    }
    [$owner_id, $co_id] = [(int)$m[1], (int)$m[2]];
    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $source_path = $pose_dir . $story['image_file'];
    if (!is_file($source_path)) { echo json_encode(['success'=>false,'message'=>"Source image missing: {$story['image_file']}"]); exit; }

    // No theme_name posted → reuse whatever theme this podcast already has,
    // just re-rolling to a different random photo from that same pool.
    if ($theme_name === '') $theme_name = trim($story['theme_key'] ?? '');
    if ($theme_name === '') { echo json_encode(['success'=>false,'message'=>'No theme selected for this video']); exit; }

    $themed = vv_apply_theme_background($conn, $falApiKey, $source_path, $theme_name, $owner_id, $co_id, "pose{$story_id}");
    if (!$themed['success']) {
        vv_log("change_scene_background: FAILED story=$story_id | " . ($themed['message'] ?? 'unknown'));
        echo json_encode($themed); exit;
    }

    $esc_file       = mysqli_real_escape_string($conn, $themed['filename']);
    $esc_theme_name = mysqli_real_escape_string($conn, $theme_name);
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$esc_file' WHERE id=$story_id LIMIT 1");
    // Keep the podcast's own theme_key in sync if a different theme was
    // explicitly picked, so future scenes (and re-rolls) default to it too.
    mysqli_query($conn, "UPDATE hdb_podcasts SET theme_key='$esc_theme_name' WHERE id={$story['podcast_id']} LIMIT 1");

    vv_log("change_scene_background: OK story=$story_id -> {$themed['filename']} theme=$theme_name");
    echo json_encode([
        'success'    => true,
        'story_id'   => $story_id,
        'filename'   => $themed['filename'],
        'public_url' => $themed['public_url'],
        'theme_name' => $theme_name,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Change the BACKGROUND for an ENTIRE podcast (every scene),
// called from the videomaker.php editor's "Change Background" icon. Each
// scene keeps its own existing pose/angle — only the background changes —
// by rembg-ing each scene's own clean pre-theme pose image (falls back to
// the single Step 1 "dressed model" image via the podcast's draft_id if a
// scene-specific clean pose isn't on disk) and compositing onto a freshly
// picked photo from the chosen theme, same as a single-scene change.
//
// Credits: 5cr PER SCENE, plan-aware — free_trial plans pay out of
// credit_balance2, every other plan out of credit_balance (this is
// DIFFERENT from generate_scene_pose/change_scene_model above, which
// always use credit_balance regardless of plan; this batch action follows
// its own explicit spec). Checked as one up-front sufficiency check for
// the WHOLE batch so a podcast never ends up half-updated from running out
// mid-way, then deducted scene-by-scene as each one actually succeeds.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_podcast_background') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $theme_name = trim($_POST['theme_name'] ?? '');
    if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }

    $pod = vv_safe_fetch($conn, "SELECT id, admin_id, draft_id, theme_key FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1");
    if (!$pod) { echo json_encode(['success'=>false,'message'=>'Video not found']); exit; }
    $pod_admin_id = (int)$pod['admin_id'];
    if ($theme_name === '') $theme_name = trim($pod['theme_key'] ?? '');
    if ($theme_name === '') { echo json_encode(['success'=>false,'message'=>'No theme selected for this video']); exit; }

    $scenes = [];
    $scenes_q = mysqli_query($conn, "SELECT id, image_file, image_folder FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC");
    if ($scenes_q) while ($r = mysqli_fetch_assoc($scenes_q)) $scenes[] = $r;
    if (!$scenes) { echo json_encode(['success'=>false,'message'=>'No scenes found for this video']); exit; }

    // ── Credit sufficiency check — full batch, up front ───────────────────
    $COST_PER_SCENE = 5;
    $total_cost  = $COST_PER_SCENE * count($scenes);
    $cred_user   = vv_safe_fetch($conn, "SELECT plan_type, role, team_lead_id FROM hdb_users WHERE id=$pod_admin_id LIMIT 1");
    $plan_t      = $cred_user['plan_type'] ?? 'free_trial';
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $pod_admin_id;
    $balance_col = ($plan_t === 'free_trial') ? 'credit_balance2' : 'credit_balance';
    $bal_row = vv_safe_fetch($conn, "SELECT $balance_col FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (float)($bal_row[$balance_col] ?? 0);
    if ($balance < $total_cost) {
        echo json_encode([
            'success'        => false,
            'upgrade_needed' => true,
            'plan_type'      => $plan_t,
            'credit_balance' => $balance,
            'required'       => $total_cost,
            'message'        => "Changing the background for all " . count($scenes) . " scenes needs $total_cost credits \u2014 you have " . (int)$balance . ".",
        ]); exit;
    }

    $updated = [];
    $failed  = [];
    $charged_total = 0;
    foreach ($scenes as $sc) {
        $story_id = (int)$sc['id'];
        if (!preg_match('#user_id_(\d+)_company_id_(\d+)#', $sc['image_folder'] ?? '', $m)) {
            $failed[] = ['story_id' => $story_id, 'message' => 'Could not resolve storage path']; continue;
        }
        [$owner_id, $co_id] = [(int)$m[1], (int)$m[2]];
        $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";

        // This scene's own clean (pre-theme) pose first — keeps its
        // specific pose/angle intact. Falls back to the single Step 1
        // dressed-model image (via the podcast's draft_id) if that's
        // missing, e.g. older data from before per-scene clean poses were
        // kept around.
        $source_path = $pose_dir . "draft_pose{$story_id}_model.jpg";
        if (!is_file($source_path)) {
            $draft_id = trim((string)($pod['draft_id'] ?? ''));
            foreach (['jpg','jpeg','png','webp'] as $ext) {
                $try = $pose_dir . "draft_{$draft_id}_model.{$ext}";
                if (is_file($try)) { $source_path = $try; break; }
            }
        }
        if (!is_file($source_path)) {
            $failed[] = ['story_id' => $story_id, 'message' => 'No source image found for this scene']; continue;
        }

        $themed = vv_apply_theme_background($conn, $falApiKey, $source_path, $theme_name, $owner_id, $co_id, "pose{$story_id}");
        if (!$themed['success']) {
            $failed[] = ['story_id' => $story_id, 'message' => $themed['message'] ?? 'theme background failed'];
            continue;
        }

        $esc_file = mysqli_real_escape_string($conn, $themed['filename']);
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$esc_file' WHERE id=$story_id LIMIT 1");
        mysqli_query($conn, "UPDATE hdb_users SET $balance_col = GREATEST(0, $balance_col - $COST_PER_SCENE) WHERE id=$deduct_from");
        $charged_total += $COST_PER_SCENE;
        $updated[] = ['story_id' => $story_id, 'filename' => $themed['filename'], 'public_url' => $themed['public_url']];
    }

    $esc_theme_name = mysqli_real_escape_string($conn, $theme_name);
    mysqli_query($conn, "UPDATE hdb_podcasts SET theme_key='$esc_theme_name' WHERE id=$podcast_id LIMIT 1");

    vv_log("change_podcast_background: podcast=$podcast_id theme=$theme_name updated=" . count($updated) . " failed=" . count($failed) . " charged=$charged_total balance_col=$balance_col");
    echo json_encode([
        'success'         => true,
        'updated'         => $updated,
        'failed'          => $failed,
        'theme_name'      => $theme_name,
        'plan_type'       => $plan_t,
        'credits_charged' => $charged_total,
        'credits_balance' => $balance - $charged_total,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Change a scene's MODEL after the fact (called from the
// videomaker.php editor's "Change Model" icon). Re-runs the same garment
// photo through vv_generate_model_from_garment() with a NEW model
// description — same garment, different person — saved over the same
// stable draft_pose{story_id}_model.jpg filename used during normal scene
// generation, so it doesn't need any separate "clean pose" tracking. Then
// re-applies a theme background exactly like generate_scene_pose does:
// either the SAME theme as before (change_background=false — the front end
// didn't ask, or the person said no) or a newly chosen one
// (change_background=true + theme_name posted).
// Costs the same 5cr as a normal scene-pose generation.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_scene_model') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $story_id          = (int)($_POST['story_id'] ?? 0);
    $model_description = trim($_POST['model_description'] ?? '');
    $resolution         = (($_POST['resolution'] ?? '1K') === '2K') ? '2K' : '1K';
    $change_background  = (($_POST['change_background'] ?? 'false') === 'true');
    $theme_name         = trim($_POST['theme_name'] ?? '');
    if (!$story_id) { echo json_encode(['success'=>false,'message'=>'Missing story_id']); exit; }
    if ($model_description === '') { echo json_encode(['success'=>false,'message'=>'Model description is required']); exit; }

    $story = vv_safe_fetch($conn, "SELECT s.id, s.podcast_id, s.image_folder, p.admin_id AS pod_admin_id, p.draft_id, p.theme_key
        FROM hdb_podcast_stories s JOIN hdb_podcasts p ON p.id = s.podcast_id WHERE s.id=$story_id LIMIT 1");
    if (!$story) { echo json_encode(['success'=>false,'message'=>'Scene not found']); exit; }

    if (!preg_match('#user_id_(\d+)_company_id_(\d+)#', $story['image_folder'] ?? '', $m)) {
        echo json_encode(['success'=>false,'message'=>'Could not resolve storage path for this scene']); exit;
    }
    [$owner_id, $co_id] = [(int)$m[1], (int)$m[2]];
    $pose_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";

    // Find the ORIGINAL garment photo from Step 1 — saved as
    // draft_{draft_id}_garment.{ext} and never touched by anything
    // downstream, so it's still sitting there untouched no matter how many
    // poses/themes have been generated since.
    $draft_id = trim((string)($story['draft_id'] ?? ''));
    if ($draft_id === '') { echo json_encode(['success'=>false,'message'=>'No original garment photo on record for this video']); exit; }
    $garment_path = null;
    foreach (['png','jpg','jpeg','webp'] as $ext) {
        $try = $pose_dir . "draft_{$draft_id}_garment.{$ext}";
        if (is_file($try)) { $garment_path = $try; break; }
    }
    if (!$garment_path) { echo json_encode(['success'=>false,'message'=>'Original garment photo not found on disk']); exit; }

    // ── Credits — same flat 5cr as a normal scene-pose generation. ────────
    $CHANGE_MODEL_COST = 5;
    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);
    if ($balance < $CHANGE_MODEL_COST) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $CHANGE_MODEL_COST credits, you have $balance"]); exit;
    }

    $result = vv_generate_model_from_garment($falApiKey, $garment_path, $model_description, $resolution, $owner_id, $co_id, "pose{$story_id}");
    if (!$result['success']) {
        vv_log("change_scene_model: FAILED story=$story_id | " . ($result['message'] ?? 'unknown'));
        echo json_encode($result); exit;
    }

    // Same theme as before unless the person explicitly opted to change it
    // too (change_background=true + a theme_name was posted).
    $final_theme = ($change_background && $theme_name !== '') ? $theme_name : trim($story['theme_key'] ?? '');
    $theme_applied = false; $theme_error = null;
    if ($final_theme !== '') {
        $themed = vv_apply_theme_background($conn, $falApiKey, $result['local_path'], $final_theme, $owner_id, $co_id, "pose{$story_id}");
        if ($themed['success']) {
            $result = $themed;
            $theme_applied = true;
        } else {
            $theme_error = $themed['message'] ?? 'theme background failed';
            vv_log("change_scene_model: theme background FAILED story=$story_id | $theme_error — keeping plain pose");
        }
    }

    $esc_file = mysqli_real_escape_string($conn, $result['filename']);
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file='$esc_file' WHERE id=$story_id LIMIT 1");
    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $CHANGE_MODEL_COST) WHERE id=$deduct_from");
    if ($change_background && $theme_name !== '' && $theme_applied) {
        $esc_theme_name = mysqli_real_escape_string($conn, $theme_name);
        mysqli_query($conn, "UPDATE hdb_podcasts SET theme_key='$esc_theme_name' WHERE id={$story['podcast_id']} LIMIT 1");
    }

    vv_log("change_scene_model: OK story=$story_id -> {$result['filename']} cost=$CHANGE_MODEL_COST theme_applied=" . ($theme_applied?'Y':'N'));
    echo json_encode([
        'success'         => true,
        'story_id'        => $story_id,
        'filename'        => $result['filename'],
        'public_url'      => $result['public_url'],
        'credits_charged' => $CHANGE_MODEL_COST,
        'credits_balance' => $balance - $CHANGE_MODEL_COST,
        'theme_applied'   => $theme_applied,
        'theme_error'     => $theme_error,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3: edit a scene's caption after creation (the scene
// cards let the person tweak the auto-generated caption before Step 5 fires
// any video generation). Updates both the story row and its 'main' caption row.
// ═════════════════════════════════════════════════════════════════════════════
// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS — Step 4: Background Music
// Ported from videomaker_ajax.php / videomaker.php's music picker — same
// podcast_music/ shared folder, same hdb_podcasts.music_file /
// music_volume / voice_volume columns, same upload/library/volume mechanics.
// ═════════════════════════════════════════════════════════════════════════════

// ── get_music_library — list files already in the shared podcast_music/ dir ─
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_music_library') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $dir = __DIR__ . '/podcast_music/';
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!preg_match('/\.(mp3|wav|ogg|m4a)$/i', $f)) continue;
            $files[] = ['filename' => $f, 'size' => (int) @filesize($dir . $f)];
        }
    }
    usort($files, fn($a, $b) => strcmp($a['filename'], $b['filename']));
    echo json_encode(['success' => true, 'files' => $files]); exit;
}

// ── upload_podcast_music — save a new file into podcast_music/ AND set it as
// this podcast's music_file in one step ─────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_podcast_music') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }

    if (!isset($_FILES['music_file']) || $_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>'Upload error: ' . ($_FILES['music_file']['error'] ?? 'no file')]); exit;
    }
    $file = $_FILES['music_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3','wav','ogg','m4a'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only MP3/WAV/OGG/M4A allowed']); exit;
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        echo json_encode(['success'=>false,'message'=>'Max 20MB']); exit;
    }

    $dir = __DIR__ . '/podcast_music/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $filename = 'music_' . $podcast_id_m . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file']); exit;
    }
    $esc = mysqli_real_escape_string($conn, $filename);
    mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS music_folder VARCHAR(255) NOT NULL DEFAULT 'podcast_music'");
    mysqli_query($conn, "UPDATE hdb_podcasts SET music_file='$esc', music_folder='podcast_music' WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success'=>true,'filename'=>$filename]); exit;
}

// ── update_podcast_music — set/clear music_file from the library picker ─────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_podcast_music') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }
    $file = mysqli_real_escape_string($conn, $_POST['music_file'] ?? '');
    // This flow only ever uploads/picks from the shared podcast_music/
    // folder (no personal-folder option here), so music_folder is always
    // 'podcast_music' — but it still has to be SET, not left untouched,
    // since vps_ffmpeg_stitch.php reads music_folder from the DB at
    // stitch time to know where to look for the file.
    mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS music_folder VARCHAR(255) NOT NULL DEFAULT 'podcast_music'");
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET music_file='$file', music_folder='podcast_music' WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success' => (bool)$ok]); exit;
}

// ── save_podcast_volumes — music_volume/voice_volume sliders ────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_podcast_volumes') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS music_volume DECIMAL(4,2) NOT NULL DEFAULT 0.30");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS voice_volume DECIMAL(4,2) NOT NULL DEFAULT 1.00");

    $mv = (float)($_POST['music_volume'] ?? 0.30);
    $vv = (float)($_POST['voice_volume'] ?? 1.00);
    if ($mv > 2.0) $mv = $mv / 100.0; // tolerate either 0-1 or 0-100 input
    if ($vv > 2.0) $vv = $vv / 100.0;
    $mv = max(0, min(1, $mv));
    $vv = max(0, min(1, $vv));
    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }

    mysqli_query($conn, "UPDATE hdb_podcasts SET music_volume=$mv, voice_volume=$vv WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success' => true, 'music_volume' => $mv, 'voice_volume' => $vv]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene_caption') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $story_id = (int)($_POST['story_id'] ?? 0);
    $caption  = trim($_POST['caption'] ?? '');
    if (!$story_id || $caption === '') { echo json_encode(['success'=>false,'message'=>'Missing story_id or caption']); exit; }

    $esc_caption = mysqli_real_escape_string($conn, $caption);
    $esc_display = mysqli_real_escape_string($conn, substr($caption, 0, 50) . (strlen($caption) > 50 ? '...' : ''));

    mysqli_query($conn, "UPDATE hdb_podcast_stories SET text_contents='$esc_caption', text_display='$esc_display' WHERE id=$story_id LIMIT 1");

    $words_arr = preg_split('/\s+/', $caption, -1, PREG_SPLIT_NO_EMPTY);
    $cap_text  = count($words_arr) > 10 ? implode(' ', array_slice($words_arr, 0, 10)) . '…' : $caption;
    $esc_cap_text = mysqli_real_escape_string($conn, $cap_text);
    mysqli_query($conn, "UPDATE hdb_captions SET text_content='$esc_cap_text' WHERE story_id=$story_id AND caption_type='caption' AND caption_name='main' LIMIT 1");

    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — get_credit_balance: plan_type + the correct current balance
// for the logged-in user (Team Members resolve to their Team Lead's balance
// — same ownership pattern generate_scene_pose already uses). free_trial
// plans track their balance in credit_balance2; every other plan uses the
// regular credit_balance column. Lets Step 6 pre-check before attempting
// set_video_mode, so a person with too little balance for AI Motion Video
// sees the upgrade prompt immediately instead of after a round trip.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_credit_balance') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $cred_user = vv_safe_fetch($conn, "SELECT plan_type, role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $plan_t      = $cred_user['plan_type'] ?? 'free_trial';
    $balance_col = ($plan_t === 'free_trial') ? 'credit_balance2' : 'credit_balance';
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT $balance_col FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (float)($bal_row[$balance_col] ?? 0);

    echo json_encode(['success'=>true, 'plan_type'=>$plan_t, 'credit_balance'=>$balance]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 6: Select Video Mode (static images vs AI motion video).
// Scene rows already exist in hdb_podcast_stories from Step 4's
// create_podcast_from_step1, so this is always an UPDATE of videogen_flag
// across every scene for the podcast — 0 for Static Images (no AI motion
// pass needed at build time), 1 for Video Scenes (AI motion per scene).
// If rows are somehow missing (shouldn't happen given the current flow),
// this reports an error instead of silently inserting placeholder rows.
//
// Static Images stays open to everyone regardless of plan. AI Motion Video
// is real per-scene fal.ai spend (6-7 scenes × 6s each adds up fast), so
// EVERY plan gets checked against scene_count × 2cr before this proceeds —
// free_trial plans check credit_balance2, every other plan checks the
// regular credit_balance. This check is server-side (authoritative) in
// addition to the client-side pre-check via get_credit_balance, so it
// can't be bypassed by skipping the JS flow.
// ═════════════════════════════════════════════════════════════════════════════
// ── Kick cron_video_gen.php in the background (fire-and-forget) ──────
// Same mechanism as vizard_scriptgen_2.php / fal_webhook.php. cron_video_gen.php,
// run from the CLI, submits every pending (flag=1) fal.ai job to queue.fal.run
// with ?fal_webhook=fal_webhook.php. After this the pipeline is webhook-driven
// and browser-independent: fal.ai calls the webhook when each clip is ready and
// the cron downloads + ingests it into user_media/.../podcast_videos and updates
// hdb_podcast_stories.
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
// Fired by startAIVideos() (Step 6 "Video Scenes" path). Ensures every
// AI-generated scene of this podcast has a pending (flag=1) row in
// hdb_video_gen_que, then kicks the cron worker so all of them get submitted
// to fal.ai at once with their image (image_folder + image_file) and video
// prompt. fal.ai returns each finished clip via fal_webhook.php →
// user_media/.../podcast_videos and hdb_podcast_stories is updated. Mirrors the
// handler in vizard_scriptgen_2.php. Closing the browser does NOT stop it.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'start_ai_videos') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json; charset=utf-8');

    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) { echo json_encode(['success' => false, 'error' => 'No podcast_id']); exit; }

    $own = vv_safe_fetch($conn, "SELECT admin_id, company_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1");
    if (!$own) { echo json_encode(['success' => false, 'error' => 'Podcast not found']); exit; }

    @mysqli_query($conn, "ALTER TABLE hdb_video_gen_que ADD COLUMN IF NOT EXISTS duration INT(4) DEFAULT 5");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    $scenes_q = mysqli_query($conn,
        "SELECT id, seq_no, image_file, image_folder, video_prompt, prompt
         FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
    $scene_count    = $scenes_q ? mysqli_num_rows($scenes_q) : 0;
    $scene_duration = max(3, min(10, (int)floor(30 / max(1, $scene_count))));

    $now      = date('Y-m-d H:i:s');
    $queued   = 0;
    $existing = 0;
    $skipped  = 0;

    while ($scenes_q && ($s = mysqli_fetch_assoc($scenes_q))) {
        $sid      = (int)$s['id'];
        $img_file = trim($s['image_file'] ?? '');
        if ($img_file === '') { $skipped++; continue; }                    // no image yet
        // Every scene image in this wizard is an AI render (garment try-on /
        // model / pose), so unlike scriptgen_2 there's no real-photo case to
        // exclude — any scene with an image is eligible. Skip only if the scene
        // already holds a finished video file (re-run safety).
        if (preg_match('/\.(mp4|mov|webm|m4v)$/i', $img_file)) { $existing++; continue; }

        $vp = trim((string)($s['video_prompt'] ?? ''));
        if ($vp === '') $vp = trim((string)($s['prompt'] ?? ''));
        if ($vp === '') { $skipped++; continue; }

        $vpe = mysqli_real_escape_string($conn, $vp);
        $vfe = mysqli_real_escape_string($conn, 'video_ai');
        $vne = mysqli_real_escape_string($conn, 'scene_' . $podcast_id . '_' . $sid . '.mp4');

        $vid_row = vv_safe_fetch($conn,
            "SELECT id, videogen_flag FROM hdb_video_gen_que WHERE podcast_id=$podcast_id AND scene_id=$sid LIMIT 1");
        if ($vid_row) {
            $flag = (int)$vid_row['videogen_flag'];
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
            if (!$ins) {
                $ins = mysqli_query($conn, "INSERT INTO hdb_video_gen_que
                    (podcast_id,scene_id,prompt,video_folder,media_type,video_file,videogen_flag,gen_mode,created_at,updated_at)
                    VALUES ($podcast_id,$sid,'$vpe','$vfe','cinematic','$vne',1,'','$now','$now')");
            }
            if ($ins) $queued++;
        }
    }

    mysqli_query($conn, "UPDATE hdb_podcasts SET internal_status='videos_generating', updated_at='$now' WHERE id=$podcast_id");

    vsg_kick_video_cron();

    vv_log("start_ai_videos: podcast=$podcast_id queued=$queued existing=$existing skipped=$skipped scenes=$scene_count");

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

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'set_video_mode') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $podcast_id_v = (int)($_POST['podcast_id'] ?? 0);
    $mode         = trim($_POST['mode'] ?? '');
    if (!$podcast_id_v || !in_array($mode, ['static', 'video'], true)) {
        echo json_encode(['success'=>false,'message'=>'Missing podcast_id or invalid mode']); exit;
    }

    $count_row = vv_safe_fetch($conn, "SELECT COUNT(*) AS c FROM hdb_podcast_stories WHERE podcast_id=$podcast_id_v");
    if (!$count_row || (int)$count_row['c'] === 0) {
        echo json_encode(['success'=>false,'message'=>'No scenes found for this podcast — build video scenes (Step 5) first']); exit;
    }
    $scene_count = (int)$count_row['c'];

    // ── Idempotency guard — this is a credit-charging action (Static Images
    // charges 50cr flat below), so a podcast can only have its build mode
    // confirmed ONCE. A repeat call (double-click, page refresh replay,
    // direct AJAX replay) just returns the already-confirmed mode instead
    // of re-charging. ────────────────────────────────────────────────────
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS build_mode VARCHAR(20) DEFAULT NULL");
    $pod_row = vv_safe_fetch($conn, "SELECT build_mode FROM hdb_podcasts WHERE id=$podcast_id_v LIMIT 1");
    if (!empty($pod_row['build_mode'])) {
        echo json_encode([
            'success'        => true,
            'podcast_id'     => $podcast_id_v,
            'mode'           => $pod_row['build_mode'],
            'videogen_flag'  => ($pod_row['build_mode'] === 'video') ? 1 : 0,
            'scenes_updated' => 0,
            'already_confirmed' => true,
        ]); exit;
    }

    // ── Credit check + charge. Static Images is NOT an AI-video-generation
    // action, so per the standing rule it ALWAYS comes out of credit_balance
    // — never credit_balance2, regardless of plan_type. credit_balance2
    // (the free-trial allotment) is reserved strictly for actual AI Motion
    // Video generation. ──────────────────────────────────────────────────
    $cred_user   = vv_safe_fetch($conn, "SELECT plan_type, role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $plan_t      = $cred_user['plan_type'] ?? 'free_trial';
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;

    if ($mode === 'video') {
        $balance_col = ($plan_t === 'free_trial') ? 'credit_balance2' : 'credit_balance';
        $bal_row    = vv_safe_fetch($conn, "SELECT $balance_col FROM hdb_users WHERE id=$deduct_from LIMIT 1");
        $balance    = (float)($bal_row[$balance_col] ?? 0);
        $video_cost = $scene_count * 20;
        if ($balance < $video_cost) {
            echo json_encode([
                'success'        => false,
                'upgrade_needed' => true,
                'plan_type'      => $plan_t,
                'credit_balance' => $balance,
                'required'       => $video_cost,
                'message'        => "AI Motion Video needs $video_cost credits — you have " . (int)$balance . ".",
            ]); exit;
        }
        // NOTE: the actual per-scene charge for AI Motion Video happens
        // later when each scene's clip is actually generated (see the video
        // cron worker) — this is a sufficiency check only, same as before.
    } else {
        // Static Images — 50cr flat, charged from credit_balance right now
        // (there's no later per-scene processing step for this mode the
        // way there is for AI Motion Video, so this is the only point
        // where it CAN be charged).
        $STATIC_COST = 50;
        $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
        $balance = (float)($bal_row['credit_balance'] ?? 0);
        if ($balance < $STATIC_COST) {
            echo json_encode([
                'success'        => false,
                'upgrade_needed' => true,
                'plan_type'      => $plan_t,
                'credit_balance' => $balance,
                'required'       => $STATIC_COST,
                'message'        => "Static Images needs $STATIC_COST credits — you have " . (int)$balance . ".",
            ]); exit;
        }
    }

    $flag = ($mode === 'video') ? 1 : 0;
    $ok = mysqli_query($conn, "UPDATE hdb_podcast_stories SET videogen_flag=$flag WHERE podcast_id=$podcast_id_v");
    if (!$ok) { echo json_encode(['success'=>false,'message'=>'UPDATE failed: ' . mysqli_error($conn)]); exit; }
    $affected = mysqli_affected_rows($conn);

    $esc_mode = mysqli_real_escape_string($conn, $mode);
    mysqli_query($conn, "UPDATE hdb_podcasts SET build_mode='$esc_mode' WHERE id=$podcast_id_v LIMIT 1");

    // Static Images charges now (flat 50cr); AI Motion Video charges later,
    // per scene, as each clip actually finishes generating.
    if ($mode === 'static') {
        mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $STATIC_COST) WHERE id=$deduct_from");
    }

    vv_log("set_video_mode: podcast=$podcast_id_v mode=$mode videogen_flag=$flag scenes_updated=$affected" . ($mode==='static' ? " charged={$STATIC_COST}cr from user={$deduct_from}" : ''));
    echo json_encode([
        'success'        => true,
        'podcast_id'     => $podcast_id_v,
        'mode'           => $mode,
        'videogen_flag'  => $flag,
        'scenes_updated' => $affected,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — "Approve" — save the model+garment try-on image as a video
// project (one hdb_podcasts row + one hdb_podcast_stories row per scene in
// the chosen style sequence). All scenes reuse the SAME image from
// generate_model_tryon — there's no per-pose AI repose anymore, just one
// fashn/tryon result reused across the whole sequence — but each scene still
// gets its own caption/video_prompt pulled from mdl_model_pose_templates by
// its own pose code, same as before.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_podcast_from_poses') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $model_id    = trim($_POST['model_id'] ?? '');
    $style_id    = (int)($_POST['style_id'] ?? 0);
    $garment_cat = trim($_POST['garment_category'] ?? 'one-pieces');
    if ($model_id === '' || !$style_id) {
        echo json_encode(['success'=>false,'message'=>'Missing model_id or style_id']); exit;
    }

    $style_row = vv_safe_fetch($conn, "SELECT * FROM mdl_model_pose_styles WHERE id=$style_id LIMIT 1");
    if (!$style_row) { echo json_encode(['success'=>false,'message'=>'Style not found']); exit; }
    $model_name = strtoupper(str_replace('_', ' ', $model_id));

    $scenes_in = [];
    for ($i = 1; $i <= 9; $i++) {
        $code = trim($style_row["scene_$i"] ?? '');
        if ($code !== '') $scenes_in[] = $code;
    }
    if (empty($scenes_in)) { echo json_encode(['success'=>false,'message'=>'Style has no scenes']); exit; }

    $pose_dir   = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $image_folder = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir,'/')), '/') . '/';

    $title      = trim($model_name . ' — ' . ($style_row['stylename'] ?? 'Style'));
    $esc_title  = mysqli_real_escape_string($conn, $title);
    $lang_code  = 'en';
    $reel_type  = 'tryon';
    $category   = mysqli_real_escape_string($conn, $garment_cat);
    $niche      = 'fashion';
    $topic_key  = 'fashion';
    $today      = date('Y-m-d');

    // ── Pre-flight: the single try-on image must exist (Approve only runs
    // after generate_model_tryon has produced it) — every scene in the style
    // sequence reuses this same file, so there's just one file check now
    // instead of one per pose. Credits = 2 per 6 seconds of duration, per
    // scene — checked and reserved BEFORE any row is written.
    $safe_model_key = preg_replace('/[^a-zA-Z0-9_]/', '_', $model_id);
    $tryon_file = "model_{$safe_model_key}_garment_tryon.jpg";
    if (!is_file($pose_dir . $tryon_file)) {
        echo json_encode(['success'=>false,'message'=>'Generate the try-on image first, then Approve']); exit;
    }

    $scene_total = count($scenes_in);
    $usable_scenes = [];      // pose_code => duration
    foreach ($scenes_in as $pose_code) {
        $duration = $scene_total > 0 ? (int)floor(30 / $scene_total) : 5;
        $duration = max(3, min($duration, 10));
        $usable_scenes[$pose_code] = $duration;
    }
    if (empty($usable_scenes)) {
        echo json_encode(['success'=>false,'message'=>'No scenes were saved — none of these poses have been generated yet']); exit;
    }

    $total_credits = 0;
    foreach ($usable_scenes as $pose_code => $duration) {
        $total_credits += ceil($duration / 6) * 2;
    }

    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);

    if ($balance < $total_credits) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this video needs $total_credits credits, you have $balance"]); exit;
    }

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_group VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_subgroup VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    // ai_group/ai_subgroup are NOT NULL on hdb_podcasts — same group/subgroup
    // the Business Settings bar already resolves for this company (ai_group
    // falling back to group_name, ai_subgroup to subgroup_name), with
    // $category as the last-resort fallback so this can never insert NULL.
    $_co_sql = $co_id > 0
        ? "SELECT group_name, subgroup_name, ai_group, ai_subgroup FROM hdb_companies WHERE id=$co_id LIMIT 1"
        : "SELECT group_name, subgroup_name, ai_group, ai_subgroup FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1";
    $_co_row    = vv_safe_fetch($conn, $_co_sql) ?: [];
    $ai_group   = ($_co_row['ai_group']    ?? '') ?: ($_co_row['group_name']    ?? '') ?: $category;
    $ai_subgrp  = ($_co_row['ai_subgroup'] ?? '') ?: ($_co_row['subgroup_name'] ?? '') ?: $category;
    $esc_ai_group    = mysqli_real_escape_string($conn, $ai_group);
    $esc_ai_subgroup = mysqli_real_escape_string($conn, $ai_subgrp);

    $sql_pod = "INSERT INTO hdb_podcasts
        (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
         created_date, updated_at, niche, category, topic_key, hashtags, keywords,
         host_voice, guest_voice, voice_rate, is_campaign,
         logo_flag, facebook_status, tiktok_status, instagram_status,
         youtube_status, twitter_status, linkedin_status,
         schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name,
         videogen_flag, ai_group, ai_subgroup)
        VALUES
        ($admin_id, $admin_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'draft',
         '$today', NOW(), '$niche', '$category', '$topic_key', '', '',
         '', '', 1.0, 0,
         0, 'pending', 'pending', 'pending',
         'pending', 'pending', 'pending',
         '$today', '09:00', '$today', 'vertical', 'video', '', '',
         1, '$esc_ai_group', '$esc_ai_subgroup')";

    if (!mysqli_query($conn, $sql_pod)) {
        echo json_encode(['success'=>false,'message'=>'hdb_podcasts INSERT failed: ' . mysqli_error($conn)]); exit;
    }
    $podcast_id    = mysqli_insert_id($conn);
    $success_count = 0;
    $story_id_map  = [];

    $seq_no = 0;
    @mysqli_query($conn, "ALTER TABLE mdl_model_pose_templates ADD COLUMN IF NOT EXISTS garment_view VARCHAR(20) DEFAULT 'front'");
    foreach ($usable_scenes as $pose_code => $duration) {
        $seq_no++;
        $safe_code = mysqli_real_escape_string($conn, $pose_code);
        $pose_file = $tryon_file; // same approved image for every scene — see pre-flight check above

        // LOWER(TRIM(...)) on both sides — exact '=' match was silently
        // missing rows whenever the style's scene code and the template's
        // code differed by case or stray whitespace (the codes aren't always
        // consistently cased, e.g. "p10" vs "P10").
        $tpl = vv_safe_fetch($conn, "SELECT image_prompt, video_prompt, garment_view FROM mdl_model_pose_templates WHERE LOWER(TRIM(code)) = LOWER(TRIM('$safe_code')) LIMIT 1");
        $tpl_video_prompt = trim($tpl['video_prompt'] ?? '');
        $tpl_image_prompt = trim($tpl['image_prompt'] ?? '');
        $prompt_text  = $tpl_video_prompt ?: $tpl_image_prompt ?: $pose_code;
        $garment_view = $tpl['garment_view'] ?? 'front';

        if (!$tpl) {
            vv_log("create_podcast_from_poses: NO template row matched for pose_code='$pose_code' — check mdl_model_pose_templates.code");
        } elseif ($tpl_video_prompt === '') {
            vv_log("create_podcast_from_poses: template row found for pose_code='$pose_code' but video_prompt is empty (image_prompt=" . ($tpl_image_prompt !== '' ? 'set' : 'also empty') . ')');
        }

        $text  = ucfirst(str_replace('_',' ', $garment_view)) . ' — ' . $title;
        $te    = mysqli_real_escape_string($conn, $text);
        $de    = mysqli_real_escape_string($conn, substr($text, 0, 50).(strlen($text)>50?'...':''));
        $pe    = mysqli_real_escape_string($conn, $prompt_text);
        $ife   = mysqli_real_escape_string($conn, $pose_file);
        $ifo   = mysqli_real_escape_string($conn, $image_folder);
        $tke   = $esc_title;

        $ins = "INSERT INTO hdb_podcast_stories
            (podcast_id, lang_code, category, topic_key, title, actor,
             text_contents, text_display, duration, prompt, video_prompt, visual_type,
             status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
             voice_id, voice_rate, image_file, image_folder, videogen_flag)
            VALUES
            ($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', 'host',
             '$te', '$de', $duration, '$pe', '$pe', 'image',
             'PENDING', NOW(), $seq_no, 0, '', '',
             '', 1.0, '$ife', '$ifo', 1)";

        if (!mysqli_query($conn, $ins)) {
            vv_log("create_podcast_from_poses: story insert failed pose=$pose_code | " . mysqli_error($conn));
            continue;
        }
        $story_id = mysqli_insert_id($conn);
        $story_id_map[$pose_code] = $story_id;
        $success_count++;

        // ── Sleek on-screen caption — a short punchy clause pulled from the
        // same video_prompt/image_prompt text (see vv_make_sleek_caption),
        // instead of the bland "Front"/"Back" garment-view label this used
        // to insert here.
        $cap_text = mysqli_real_escape_string($conn, vv_make_sleek_caption($prompt_text, $garment_view));
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
            ($podcast_id, $story_id, 'caption', 'main', '$cap_text',
             'Arial', 28, '#ffffff', 'normal', 'normal', 0,
             'center', 'bottom', '#000000', 0,
             '#000000', 0, 0,
             '#000000', '#ff6600', 'none', '#ffffff',
             0, 0,
             50, 200, 350, 0,
             'none', 1.0,
             'none', 'bottom', 'full',
             'none', 1, 1)");
    }

    if (empty($story_id_map)) {
        echo json_encode(['success'=>false,'message'=>'All scene inserts failed — check error log']); exit;
    }

    // ── Deduct credits now that the podcast + scenes were actually created ──
    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $total_credits) WHERE id=$deduct_from");

    vv_log("create_podcast_from_poses: created podcast=$podcast_id with $success_count scenes for model=$model_id style=$style_id, deducted $total_credits credits from user=$deduct_from");
    echo json_encode([
        'success'         => true,
        'podcast_id'      => $podcast_id,
        'scene_count'     => $success_count,
        'story_id_map'    => $story_id_map,
        'credits_charged' => $total_credits,
        'credits_balance' => $balance - $total_credits,
    ]); exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — Step 3 (LEGACY/UNUSED): Model + Pose image, no garment
// ═════════════════════════════════════════════════════════════════════════════
// NOTE: kept for reference but no longer called from the JS flow — superseded
// by generate_garment_pose above, which builds the garment in from the start
// instead of generating a bare pose and trying to apply the garment after.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_model_pose_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $model_id  = (int)($_POST['model_id'] ?? 0);
    $pose_code = mysqli_real_escape_string($conn, trim($_POST['pose_code'] ?? ''));
    if (!$model_id || !$pose_code) { echo json_encode(['success'=>false,'error'=>'Missing model_id or pose_code']); exit; }

    $pose_dir = __DIR__ . '/promo_models/';
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);
    $filename = "model_id_{$model_id}_pose_{$pose_code}.png";
    $filepath = $pose_dir . $filename;

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$filename;

    // ── Cache hit — instant return, no fal call ──────────────────────────────
    if (file_exists($filepath)) {
        echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>true]); exit;
    }

    // ── Cache miss — generate via fal-ai/ideogram/character ──────────────────
    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured in config.php']); exit; }

    $model_row = vv_safe_fetch($conn, "SELECT model_name, thumbnail FROM mdl_models WHERE model_id=$model_id LIMIT 1");
    if (!$model_row || empty($model_row['thumbnail'])) {
        echo json_encode(['success'=>false,'error'=>'Model not found or missing a reference photo (thumbnail)']); exit;
    }

    // Read the reference photo straight off disk and send it as a data URI —
    // avoids fal.ai having to fetch our domain over HTTP at all, which sidesteps
    // hotlink/firewall rules and host-resolution ambiguity (VPS IP vs domain).
    $thumb_rel  = ltrim($model_row['thumbnail'], '/');
    $thumb_path = __DIR__ . '/' . $thumb_rel;
    if (!is_file($thumb_path)) {
        echo json_encode(['success'=>false,'error'=>"Reference photo not found on disk at: $thumb_path"]); exit;
    }
    $thumb_bytes = @file_get_contents($thumb_path);
    if ($thumb_bytes === false) {
        echo json_encode(['success'=>false,'error'=>"Reference photo exists but could not be read: $thumb_path"]); exit;
    }
    $thumb_mime    = @mime_content_type($thumb_path) ?: 'image/jpeg';
    $ref_photo_url = 'data:' . $thumb_mime . ';base64,' . base64_encode($thumb_bytes);

    $pose_row = vv_safe_fetch($conn, "SELECT image_prompt FROM mdl_model_pose_templates WHERE code='$pose_code' LIMIT 1");
    if (!$pose_row || empty($pose_row['image_prompt'])) {
        echo json_encode(['success'=>false,'error'=>"Pose template '$pose_code' not found or has no image_prompt"]); exit;
    }
    $prompt = trim($pose_row['image_prompt']) . ', plain seamless white studio background, no scenery, no props';

    $gch = curl_init('https://fal.run/fal-ai/ideogram/character');
    curl_setopt_array($gch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt'               => $prompt,
            'reference_image_urls' => [$ref_photo_url],
        ]),
    ]);
    $gres  = curl_exec($gch);
    $ghttp = curl_getinfo($gch, CURLINFO_HTTP_CODE);
    $gerr  = curl_error($gch);
    curl_close($gch);
    $gj = json_decode($gres, true);

    if ($ghttp !== 200 || empty($gj['images'][0]['url'])) {
        $detail = $gerr ? "curl error: $gerr" : ('HTTP ' . $ghttp . ' — ' . substr((string)$gres, 0, 200));
        vv_log("get_model_pose_image: generation failed for model=$model_id pose=$pose_code | $detail");
        echo json_encode(['success'=>false,'error'=>"fal.ai call failed ($detail)",'ref_photo_path'=>$thumb_path]); exit;
    }

    $img_data = @file_get_contents($gj['images'][0]['url']);
    if (!$img_data) {
        echo json_encode(['success'=>false,'error'=>'Generated image could not be downloaded']); exit;
    }

    // ── Force a guaranteed-plain background — prompts alone aren't reliable ──
    // Same rembg + white-composite approach already proven in Step 1.
    $final_data = $img_data;
    $rembg_ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rembg_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode(['image_url'=>'data:image/png;base64,'.base64_encode($img_data),'sync_mode'=>true]),
    ]);
    $rres  = curl_exec($rembg_ch);
    $rhttp = curl_getinfo($rembg_ch, CURLINFO_HTTP_CODE);
    curl_close($rembg_ch);
    $rj = json_decode($rres, true);

    if ($rhttp === 200 && !empty($rj['image']['url'])) {
        $cut_data = @file_get_contents($rj['image']['url']);
        $src = $cut_data ? @imagecreatefromstring($cut_data) : false;
        if ($src) {
            $iw = imagesx($src); $ih = imagesy($src);
            $dst = imagecreatetruecolor($iw, $ih);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagealphablending($src, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
            ob_start(); imagepng($dst); $final_data = ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
        } else {
            vv_log("get_model_pose_image: rembg returned but image decode failed for model=$model_id pose=$pose_code");
        }
    } else {
        vv_log("get_model_pose_image: background cleanup (rembg) failed for model=$model_id pose=$pose_code (HTTP $rhttp) — saving original generation instead");
    }

    file_put_contents($filepath, $final_data);

    vv_log("get_model_pose_image: generated model=$model_id pose=$pose_code -> $filename");
    echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>false]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — (LEGACY/UNUSED): Apply garment to a generated pose (fashn/tryon)
// ═════════════════════════════════════════════════════════════════════════════
// NOTE: no longer called from the JS flow — this was the old per-pose
// fashn/tryon approach (one independent garment-transfer gamble per pose,
// which is where sleeves/back coverage kept getting lost). Superseded by
// the matched-garment-view architecture above (generate_garment_pose).
// Kept in place in case a manual "force a fresh fashn/tryon on this exact pose"
// fallback is wanted later.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'apply_garment_tryon') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(120);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $model_id      = (int)($_POST['model_id'] ?? 0);
    $pose_code     = mysqli_real_escape_string($conn, trim($_POST['pose_code'] ?? ''));
    $garment_file  = basename(trim($_POST['garment_file'] ?? ''));
    $garment_cat   = trim($_POST['garment_category'] ?? 'one-pieces');
    if (!$model_id || !$pose_code || !$garment_file) {
        echo json_encode(['success'=>false,'error'=>'Missing model_id, pose_code, or garment_file']); exit;
    }

    $pose_path = __DIR__ . "/promo_models/model_id_{$model_id}_pose_{$pose_code}.png";
    if (!file_exists($pose_path)) {
        echo json_encode(['success'=>false,'error'=>'Pose image not generated yet — run Step 3 first']); exit;
    }

    $garment_dir  = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/product_images/";
    $garment_path = $garment_dir . $garment_file;
    if (!file_exists($garment_path)) {
        echo json_encode(['success'=>false,'error'=>'Garment file not found: '.$garment_file]); exit;
    }

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $proxy_url = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';

    // Same upload-to-fal-storage helper as fal_video_generator.php's fashn_tryon
    $uploadToFal = function($path) use ($proxy_url) {
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

    $pose_upload_path = vv_shrink_for_upload($pose_path);
    [$model_url, $mh, $merr, $mres] = $uploadToFal($pose_upload_path);
    if ($pose_upload_path !== $pose_path) @unlink($pose_upload_path);
    if (!$model_url) {
        vv_log("apply_garment_tryon: pose upload failed (HTTP $mh, curl_err=$merr) | " . substr((string)$mres,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload pose image to fal.ai (HTTP $mh".($merr?", curl: $merr":'').')']); exit;
    }
    $garment_upload_path = vv_shrink_for_upload($garment_path);
    [$garment_url, $gh, $gerr2, $gres2] = $uploadToFal($garment_upload_path);
    if ($garment_upload_path !== $garment_path) @unlink($garment_upload_path);
    if (!$garment_url) {
        vv_log("apply_garment_tryon: garment upload failed (HTTP $gh, curl_err=$gerr2) | " . substr((string)$gres2,0,300));
        echo json_encode(['success'=>false,'error'=>"Failed to upload garment image to fal.ai (HTTP $gh".($gerr2?", curl: $gerr2":'').')']); exit;
    }

    $ch = curl_init('https://fal.run/fal-ai/fashn/tryon/v1.6');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'model_image'        => $model_url,
            'garment_image'      => $garment_url,
            'category'           => $garment_cat,
            'sync_mode'          => true,
            'adjust_hands'       => true,
            'restore_clothes'    => true,
            'restore_background' => true,
        ]),
    ]);
    $fres  = curl_exec($ch);
    $fhttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $fj = json_decode($fres, true);

    if ($fhttp !== 200 || empty($fj['images'][0]['url'])) {
        vv_log("apply_garment_tryon: failed for model=$model_id pose=$pose_code (HTTP $fhttp) | " . substr((string)$fres, 0, 300));
        echo json_encode(['success'=>false,'error'=>'fashn/tryon failed (HTTP '.$fhttp.'): '.substr((string)$fres,0,300)]); exit;
    }

    $result_data = @file_get_contents($fj['images'][0]['url']);
    if (!$result_data) { echo json_encode(['success'=>false,'error'=>'Try-on result could not be downloaded']); exit; }

    $final_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/poses/";
    if (!is_dir($final_dir)) @mkdir($final_dir, 0777, true);
    $final_file = "model_id_{$model_id}_pose_{$pose_code}.jpg";
    file_put_contents($final_dir . $final_file, $result_data);

    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($final_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$final_file;

    vv_log("apply_garment_tryon: generated model=$model_id pose=$pose_code garment=$garment_file -> $final_file");
    echo json_encode(['success'=>true,'url'=>$public_url]); exit;
}

// ── Resolve current company's saved business settings for initial page load ──
$php_co_group    = '';
$php_co_subgroup = '';
$_co_sql = $company_id > 0
    ? "SELECT group_name,subgroup_name,ai_group,ai_subgroup FROM hdb_companies WHERE id=$company_id LIMIT 1"
    : "SELECT group_name,subgroup_name,ai_group,ai_subgroup FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1";
$_co_row = vv_safe_fetch($conn, $_co_sql);
if ($_co_row) {
    $php_co_group    = $_co_row['ai_group']    ?: ($_co_row['group_name']    ?? '');
    $php_co_subgroup = $_co_row['ai_subgroup'] ?: ($_co_row['subgroup_name'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Script Wizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ──────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --purple:    #8b5cf6;
  --purple-lt: #ede9fe;
  --green:     #10b981;
  --orange:    #f59e0b;
  --orange-lt: #fef3c7;
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --card:      #ffffff;
  --shadow:    0 4px 12px rgba(0,0,0,0.08);
}
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

/* ── Header ─────────────────────────────────────────────────────────────────── */
.app-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: #fff;
  box-shadow: 0 3px 10px rgba(0,0,0,0.15);
  position: sticky; top: 0; z-index: 1000;
}
.brand { display: flex; align-items: center; gap: 8px; text-decoration: none; }
.brand-name { font-size: 18px; font-weight: 700; }
.brand-name .v { color: #fff; }
.brand-name .z { color: #5fd1ff; }
.header-back { color: rgba(255,255,255,.75); font-size: 13px; font-weight: 600; text-decoration: none; transition: color .2s; }
.header-back:hover { color: #5fd1ff; }

/* ── Page wrap ───────────────────────────────────────────────────────────────── */
.page-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 28px 16px 48px; }

/* ── Core card ───────────────────────────────────────────────────────────────── */
.wiz-card { background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); width: 100%; max-width: 600px; overflow: hidden; }
.wiz-header { padding: 18px 24px 16px; background: linear-gradient(90deg, #0f2a44, #143b63); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.wiz-header h1 { font-size: 20px; font-weight: 700; color: #fff; }
.wiz-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 2px 0 0; }
.wiz-body { padding: 24px; }

/* ── Business settings bar (pills) ─────────────────────────────────────────── */
.settings-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; background: #f7f9fc; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 20px; cursor: pointer; transition: border-color .15s; }
.settings-bar:hover { border-color: var(--purple); }
.settings-bar-label { font-size: 11px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: .06em; margin-right: 2px; white-space: nowrap; }
.settings-bar-edit  { font-size: 11px; color: var(--purple); margin-left: auto; white-space: nowrap; }
.settings-bar-summary { font-size: 12px; font-weight: 600; color: var(--dark-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.s-pill { font-size: 11px; background: var(--purple-lt); color: #6d28d9; border-radius: 4px; padding: 2px 7px; white-space: nowrap; }

/* ── Option chips (used inside settings overlays) ───────────────────────────── */
.setting-group   { margin-bottom: 20px; }
.setting-label   { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
.setting-opts    { display: flex; flex-wrap: wrap; gap: 7px; }
.sopt { padding: 7px 13px; border: 1.5px solid var(--border); border-radius: 7px; background: #fff; color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.sopt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.sopt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }

/* ── Loading dots ────────────────────────────────────────────────────────────── */
.loading { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; padding: 16px 0; }
.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--purple); animation: blink 1.2s ease-in-out infinite; }
.dot:nth-child(2) { animation-delay: .2s; }
.dot:nth-child(3) { animation-delay: .4s; }
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }

/* ── Toast ───────────────────────────────────────────────────────────────────── */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--dark-blue); color: #fff; padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600; z-index: 999; transition: opacity .3s; pointer-events: none; }

/* ── Footer ──────────────────────────────────────────────────────────────────── */
.site-footer { background: linear-gradient(90deg, #0f2a44, #143b63); color: rgba(255,255,255,.5); padding: 14px 20px; font-size: 12px; display: flex; justify-content: center; align-items: center; gap: 24px; flex-wrap: wrap; }
.site-footer a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.site-footer a:hover { color: var(--accent); }
.footer-brand { font-weight: 700; color: var(--accent); }

/* ── Settings overlay (shared shell for Business Settings modal) ────────────── */
.settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.settings-overlay.open { display: flex; }
.settings-panel { background: #fff; border-radius: 16px; padding: 28px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.settings-title  { font-size: 17px; font-weight: 700; color: var(--dark-blue); }
.settings-close  { background: none; border: none; font-size: 22px; color: var(--muted); cursor: pointer; }

/* ── Business settings navigation ────────────────────────────────────────────── */
.biz-back-btn { background: none; border: none; color: var(--purple); font-size: 12px; font-weight: 700; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 3px; }
.biz-back-btn:hover { text-decoration: underline; }

/* Step dots inside business modal */
.biz-step-dots { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 16px; }
.biz-sdot { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.biz-sdot-icon { width: 26px; height: 26px; border-radius: 50%; background: var(--border); border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #aaa; transition: all .2s; }
.biz-sdot-label { font-size: 10px; color: var(--muted); white-space: nowrap; }
.biz-sdot-active .biz-sdot-icon { background: var(--dark-blue); border-color: var(--dark-blue); color: #fff; }
.biz-sdot-active .biz-sdot-label { color: var(--dark-blue); font-weight: 700; }
.biz-sdot-done .biz-sdot-icon { background: var(--green); border-color: var(--green); color: #fff; }
.biz-sdot-done .biz-sdot-label { color: var(--green); }
.biz-sdot-line { flex: 1; height: 2px; background: var(--border); margin: 0 6px 14px; min-width: 28px; }

/* Breadcrumb trail */
.biz-breadcrumb { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; background: #f7f9fc; border-radius: 8px; padding: 7px 12px; margin-bottom: 14px; font-size: 12px; min-height: 34px; }
.biz-bc-item { color: var(--muted); }
.biz-bc-active { color: var(--dark-blue); font-weight: 700; }
.biz-bc-sep { color: #ccc; font-size: 10px; }

/* Done button pinned to bottom of panel content */
.biz-footer { margin-top: 18px; display: flex; justify-content: flex-end; border-top: 1px solid #f0f0f0; padding-top: 14px; }
.biz-done-btn { padding: 11px 28px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s; }
.biz-done-btn:hover:not(:disabled) { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.biz-done-btn:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; }

/* ── Form inputs (Step 1 title/description/model fields, search boxes,
   scene captions, etc) — this class was being used all over the page but
   never actually defined, so every .business-input was falling back to
   the browser's bare default sizing, which is why they looked so short. ── */
.business-input { width: 100%; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; font-family: 'Inter', sans-serif; color: var(--text); background: #fff; transition: border-color .15s, box-shadow .15s; }
.business-input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(139,92,246,.12); }
.business-input::placeholder { color: #a3aab8; }
textarea.business-input { resize: vertical; line-height: 1.5; min-height: 60px; }
select.business-input { cursor: pointer; }

/* ── Garment image upload (Step 1) ───────────────────────────────────────────── */
.garment-heading    { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; }
.field-label        { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; display: block; }
.garment-subheading { font-size: 12px; color: var(--muted); margin-bottom: 14px; line-height: 1.5; }

.garment-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 22px; text-align: center; cursor: pointer; position: relative; transition: border-color .15s, background .15s; margin-bottom: 16px; }
.garment-zone:hover { border-color: var(--purple); background: var(--purple-lt); }
.garment-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.garment-zone-text { font-size: 13px; color: var(--muted); font-weight: 600; }
.garment-zone-sub  { font-size: 11px; color: #aaa; margin-top: 4px; }

.garment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; margin-bottom: 18px; }
.garment-card { position: relative; border: 1.5px solid var(--border); border-radius: 10px; overflow: hidden; background: #f8fafc; aspect-ratio: 3/4; }
.garment-card img { width: 100%; height: 100%; object-fit: cover; display: block; }
.garment-card-del { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; border-radius: 50%; background: rgba(0,0,0,.55); color: #fff; border: none; font-size: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; line-height: 1; }
.garment-card-del:hover { background: #dc2626; }
.garment-card.uploading { display: flex; align-items: center; justify-content: center; }
.garment-card-spin { width: 22px; height: 22px; border: 3px solid var(--border); border-top-color: var(--purple); border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Model selection (Step 2) ────────────────────────────────────────────────── */
.model-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; margin-bottom: 12px; }
.model-card { position: relative; border: 2px solid var(--border); border-radius: 10px; overflow: hidden; cursor: pointer; background: #f8fafc; transition: all .15s; text-align: center; }
.model-card:hover { border-color: var(--purple); box-shadow: 0 2px 8px rgba(139,92,246,.2); }
.model-card.sel { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(139,92,246,.25); }
.model-card img { width: 100%; aspect-ratio: 3/4; object-fit: cover; display: block; background: #e5e7eb; }
.model-card-label { font-size: 11px; font-weight: 600; color: var(--text); padding: 5px 4px 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.model-card-sub   { font-size: 10px; color: var(--muted); padding: 0 4px 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.model-card-check { position: absolute; top: 4px; right: 4px; background: var(--purple); color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 11px; display: flex; align-items: center; justify-content: center; font-weight: 700; }

/* ── Style picker (Step 3) ───────────────────────────────────────────────────── */
.style-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-bottom: 8px; }
.style-card { border: 2px solid var(--border); border-radius: 10px; padding: 12px; cursor: pointer; background: #f8fafc; transition: all .15s; }
.style-card:hover { border-color: var(--purple); }
.style-card.sel { border-color: var(--purple); background: var(--purple-lt); box-shadow: 0 0 0 3px rgba(139,92,246,.15); }
.theme-card { border: 2px solid var(--border); border-radius: 10px; padding: 8px 6px; cursor: pointer; background: #f8fafc; transition: all .15s; text-align: center; font-size: 11px; font-weight: 600; color: var(--text); }
.theme-card.sel { border-color: var(--purple); background: var(--purple-lt); color: #5b21b6; box-shadow: 0 0 0 3px rgba(139,92,246,.15); }
.theme-card-icon { font-size: 20px; display: block; }
.theme-card-thumb-wrap { position: relative; margin-bottom: 6px; }
.theme-card-thumb-empty { aspect-ratio: 9/16; display: flex; align-items: center; justify-content: center; background: #f1f5f9; border-radius: 8px; }
.theme-card-thumb { width: 100%; aspect-ratio: 9/16; object-fit: cover; border-radius: 8px; display: block; background: #e5e7eb; }
.theme-card-viewall { position: absolute; bottom: 5px; right: 5px; background: rgba(15,23,42,.6); border: none; color: #fff; width: 24px; height: 24px; border-radius: 50%; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
.theme-card-viewall:hover { background: rgba(15,23,42,.85); }
.theme-gallery-img { width: 100%; aspect-ratio: 9/16; object-fit: cover; border-radius: 8px; background: #e5e7eb; }
.style-card.disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }
.style-card-name   { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; }
.style-card-scenes { font-size: 11px; color: var(--muted); line-height: 1.5; word-break: break-word; }



</style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<header class="app-header">
  <a class="brand" href="index.php">
    <span style="font-size:24px;">🎬</span>
    <span class="brand-name"><span class="v">Video</span><span class="z">Vizard</span></span>
  </a>
  <a class="header-back" href="vizard_browser.php">← Home</a>
</header>

<div class="page-wrap">

  <div class="wiz-card">
    <div class="wiz-header">
      <div>
        <h1>Script Wizard</h1>
        <p>Core page — header, styles &amp; Business Settings wired up</p>
      </div>
    </div>
    <div class="wiz-body">

      <!-- Business Settings bar -->
      <div class="settings-bar" onclick="openBusinessSettings()">
        <span class="settings-bar-label">Business</span>
        <span id="business-bar-pills"></span>
        <span class="settings-bar-edit">Edit →</span>
      </div>

      <!-- Video Setting bar (target audience/location) -->
      <div class="settings-bar" onclick="openTargetSettings()">
        <span class="settings-bar-label">Video Setting</span>
        <span id="target-bar-pills"></span>
        <span class="settings-bar-edit">Edit →</span>
      </div>

      <div id="garmentNotApplicable" style="padding:30px 0;text-align:center;color:var(--muted);font-size:13px;line-height:1.7;">
        This step applies to garment categories that need a model — Bridal Wear, Party Wear, Indian/Pakistani Bridal Wear, Jewellery.<br>
        Set your Business sub-group above to continue.
      </div>

      <div id="garmentStep1Wrap" style="display:none;">

        <div class="settings-bar" onclick="toggleStep('step1')">
          <span class="settings-bar-label">Step 1 — Upload Dress / Product Photo</span>
          <span id="step1Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step1Chevron">▾</span>
        </div>

        <div id="step1Body">

          <div class="field-group">
            <label class="field-label">Video Title <span style="color:#dc2626;">*</span></label>
            <input type="text" id="step1Title" class="business-input" style="width:100%;" placeholder="e.g. Golden Embroidered Bridal Gown">
          </div>

          <div class="field-group" style="margin-top:12px;">
            <label class="field-label">Brief Description (optional)</label>
            <textarea id="step1Description" class="business-input" style="width:100%;" rows="2" placeholder="A short description of this product/video"></textarea>
          </div>

          <div class="garment-heading" style="margin-top:16px;">Upload Dress / Product Photo</div>
          <div class="garment-subheading">One photo — the mannequin/dress-form/headless shot is replaced with a real model. The garment itself is kept exactly as shown.</div>

          <div id="step1GarmentZone" class="garment-zone">
            <input type="file" accept="image/jpeg,image/png,image/webp" onchange="handleStep1GarmentUpload(this.files[0]); this.value='';">
            <div class="garment-zone-text">📤 Click or drop the dress photo here</div>
            <div class="garment-zone-sub">Shown as-is — background is NOT removed automatically</div>
          </div>

          <div id="step1GarmentPreviewWrap" style="display:none;margin-top:10px;">
            <div style="position:relative;display:inline-block;">
              <img id="step1GarmentPreview" src="" style="max-width:220px;max-height:280px;border-radius:10px;border:1.5px solid var(--border);display:block;">
              <button type="button" class="garment-card-del" onclick="clearStep1Garment()">✕</button>
            </div>
            <div style="margin-top:6px;">
              <button type="button" class="biz-back-btn" id="step1RemoveBgBtn" onclick="step1RemoveGarmentBg()">🪄 Remove Background (2 cr)</button>
            </div>
          </div>

          <button class="biz-done-btn" id="step1ContinueBtn" style="margin-top:16px;width:100%;" onclick="step1ContinueToModel()">
            ➡️ Continue
          </button>

        </div>

        <div id="step1bWrap" style="display:none;margin-top:16px;">

          <div class="settings-bar" onclick="toggleStep('step1b')">
            <span class="settings-bar-label">Step 2 — Choose Your Model</span>
            <span id="step1bSummary" class="settings-bar-summary"></span>
            <span class="settings-bar-edit" id="step1bChevron">▾</span>
          </div>

          <div id="step1bBody">

            <div class="field-group">
              <label class="field-label">Model — quick pick</label>
              <select class="business-input" id="step1ModelPreset" style="width:100%;cursor:pointer;" onchange="applyStep1ModelPreset()">
                <option value="" selected disabled>— Choose an ethnicity to fill the description below —</option>
                <option value="a Pakistani beautiful woman, around 25 years old, dart brown hair, with makeup brightly smiling, looking directly at the camera">🇵🇰 Pakistani</option>
                <option value="an Indian woman, around 25 years old, dark brown hair, brightly smiling,  looking directly at the camera">🇮🇳 Indian</option>
                <option value="an American woman, around 25 years old, light brown hair, brightly smiling,  looking directly at the camera">🇺🇸 American</option>
                <option value="an African woman, around 25 years old, black hair, brightly smiling, looking directly at the camera">🌍 African</option>
                <option value="a Middle Eastern woman, around 25 years old, dark hair, brightly smiling,  looking directly at the camera">Middle Eastern</option>
                <option value="an East Asian woman, around 25 years old, black hair, brightly smiling, looking directly at the camera">East Asian</option>
                <option value="a Latina woman, around 25 years old, dark brown hair, brightly smiling,  looking directly at the camera">Latina</option>
              </select>
            </div>

            <div class="field-group" style="margin-top:12px;">
              <label class="field-label">Model Description <span style="color:#dc2626;">*</span></label>
              <textarea class="business-input" id="step1ModelDescription" style="width:100%;" rows="2" placeholder="e.g. a Pakistani woman, around 25 years old, golden brown hair, brightly smiling, not looking directly at the camera"></textarea>
            </div>

            <div class="field-group" style="margin-top:12px;">
              <label class="field-label">Output Resolution</label>
              <select class="business-input" id="step1Resolution" style="width:100%;cursor:pointer;">
                <option value="1K" selected>1K — standard, lower cost (recommended)</option>
                <option value="2K">2K — sharper fine detail (embroidery, fine print)</option>
              </select>
            </div>

            <div class="field-group" style="margin-top:12px;display:flex;align-items:center;gap:8px;">
              <input type="checkbox" id="step1RemoveBg" checked style="width:16px;height:16px;cursor:pointer;">
              <label for="step1RemoveBg" style="font-size:13px;font-weight:600;cursor:pointer;margin:0;">🪄 Remove background from the generated model image (default on)</label>
            </div>

            <button class="biz-done-btn" id="step1GenerateBtn" style="margin-top:16px;width:100%;" onclick="generateStep1Model()">
              ➡️ Continue (10 cr)
            </button>

            <div id="step1ResultArea" style="margin-top:16px;"></div>

          </div>

        </div>

        <div id="garmentStep23Wrap" style="display:none;margin-top:16px;">

          <!-- ── Step 3 — Select a Style ───────────────────────────────────── -->
          <div id="step2Wrap" style="margin-top:16px;">
            <div class="settings-bar" onclick="toggleStep('step2')">
              <span class="settings-bar-label">Step 3 — Select a Style</span>
              <span id="step2Summary" class="settings-bar-summary"></span>
              <span class="settings-bar-edit" id="step2Chevron">▾</span>
            </div>
            <div id="step2Body">
              <div class="garment-subheading">Pick a pose sequence. The model + garment image generated in Step 2 is reused across every scene in the sequence.</div>
              <div id="styleNeedsModelHint" style="display:none;font-size:12px;color:#dc2626;font-weight:600;margin-bottom:8px;">⚠ Generate the model in Step 2 above before picking a style.</div>
              <div id="styleGrid" class="style-grid"></div>
            </div>
          </div>

          <!-- ── Step 4 — Background Theme (optional) ──────────────────────── -->
          <div id="step3Wrap" style="display:none;margin-top:16px;">
            <div class="settings-bar" onclick="toggleStep('step3')">
              <span class="settings-bar-label">Step 4 — Background Theme <span style="font-size:11px;font-weight:400;color:var(--muted);">(optional)</span></span>
              <span id="step3Summary" class="settings-bar-summary"></span>
              <span class="settings-bar-edit" id="step3Chevron">▾</span>
            </div>
            <div id="step3Body">
              <div class="garment-subheading">Each scene gets a fresh, different shot of the same theme — pick one or leave on White / No Theme.</div>
              <div id="themeGrid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;"></div>
              <button class="biz-done-btn" onclick="step3ContinueToVideoSetting()">Continue →</button>
            </div>
          </div>

          <!-- ── Step 4 — Video Setting (duration, caption style, music) ──── -->
          <div id="step4Wrap" style="display:none;margin-top:16px;">
            <div class="settings-bar" onclick="toggleStep('step4')">
              <span class="settings-bar-label">Step 5 — Video Setting</span>
              <span id="step4Summary" class="settings-bar-summary"></span>
              <span class="settings-bar-edit" id="step4Chevron">▾</span>
            </div>
            <div id="step4Body">
              <div class="field-group" style="margin-bottom:12px;">
                <label class="field-label">Video Duration</label>
                <select class="business-input" id="step2Duration" style="width:100%;cursor:pointer;">
                  <option value="30" selected>~30 seconds</option>
                  <option value="45">~45 seconds</option>
                  <option value="60">~60 seconds</option>
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">Each scene clip is at least 6s, so the final video may run a little over (e.g. 35s instead of 30s) — credits are still charged per scene, not per second.</div>
              </div>
              <div class="field-group" style="margin-bottom:12px;">
                <label class="field-label">Caption Style</label>
                <select class="business-input" id="step2CaptionStyle" style="width:100%;cursor:pointer;">
                  <option value="Elegant" selected>Elegant</option>
                  <option value="Emotional">Emotional</option>
                  <option value="Premium">Premium</option>
                  <option value="Sales-Oriented">Sales-Oriented</option>
                </select>
              </div>

              <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="garment-heading" style="font-size:13px;">🎵 Background Music <span style="font-size:11px;font-weight:400;color:var(--muted);">(optional)</span></div>
                <div id="musicCurrentWrap" style="margin-bottom:10px;font-size:12px;color:var(--muted);">No background music selected</div>

                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
                  <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                      <span class="field-label" style="margin:0;">🎵 Music Volume</span>
                      <span id="musicVolLbl" style="font-size:10px;color:var(--muted);">30%</span>
                    </div>
                    <input type="range" id="musicVolSlider" min="0" max="100" value="30" style="width:100%;" oninput="onMusicVolChange(this.value)">
                  </div>
                  <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                      <span class="field-label" style="margin:0;">🗣️ Voice Volume</span>
                      <span id="voiceVolLbl" style="font-size:10px;color:var(--muted);">100%</span>
                    </div>
                    <input type="range" id="voiceVolSlider" min="0" max="100" value="100" style="width:100%;" oninput="onVoiceVolChange(this.value)">
                  </div>
                </div>

                <input type="file" id="musicFileInput" accept=".mp3,.wav,.ogg,.m4a" style="display:none;" onchange="handleMusicUpload(this)">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px;">
                  <button class="biz-done-btn" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="uploadMusicClick()">📤 Upload</button>
                  <button class="biz-done-btn" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);" onclick="openMusicLibModal()">📚 Library</button>
                  <button class="biz-done-btn" id="musicPlayBtn" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);" onclick="toggleMusicPreview()" disabled>▶ Play</button>
                </div>
                <button class="biz-back-btn" style="width:100%;border:1.5px solid #dc2626;border-radius:8px;padding:7px;color:#dc2626;justify-content:center;" onclick="clearPodcastMusic()">✕ Remove Background Music</button>
                <audio id="musicPreviewPlayer" style="display:none;" onended="onMusicPreviewEnded()"></audio>
              </div>

              <button class="biz-done-btn" id="createVideoBtn" style="width:100%;margin-top:18px;" onclick="createVideoProject()">Continue →</button>
              <div id="createVideoResult" style="margin-top:10px;font-size:12px;"></div>
            </div>
          </div>

          <!-- ── Step 6 — Generate Poses ────────────────────────────────────── -->
          <div id="step5Wrap" style="display:none;margin-top:16px;">
            <div class="settings-bar" onclick="toggleStep('step5')">
              <span class="settings-bar-label">Step 5 — Generate Poses</span>
              <span id="step5Summary" class="settings-bar-summary"></span>
              <span class="settings-bar-edit" id="step5Chevron">▾</span>
            </div>
            <div id="step5Body">
              <div class="garment-subheading">Poses generate automatically below. Failed scenes auto-retry once — use Retry Pose for anything still failed. Captions are editable any time.</div>
              <div id="sceneCardsGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:14px;"></div>
              <button class="biz-done-btn" id="step5ContinueBtn" style="width:100%;" disabled onclick="step5ContinueToVideoMode()">Continue →</button>
              <div id="generatePosesResult" style="margin-top:8px;font-size:12px;"></div>
            </div>
          </div>

          <!-- ── Step 7 — Select Video Mode (shown once poses are generated) ── -->
          <div id="step6Wrap" style="display:none;margin-top:16px;">
            <div class="garment-heading">Step 7 — Select Video Mode</div>
            <div class="garment-subheading">Choose how the final video gets built.</div>

            <div class="style-card" id="buildOptionStatic" style="margin-bottom:10px;" onclick="selectBuildOption('static')">
              <div class="style-card-name">🖼️ Static Images</div>
              <div class="style-card-scenes" id="buildOptionStaticCost">Slideshow of the pose images — 50 cr</div>
            </div>
            <div class="style-card" id="buildOptionVideo" style="margin-bottom:14px;" onclick="selectBuildOption('video')">
              <div class="style-card-name">🎬 Video Scenes</div>
              <div class="style-card-scenes" id="buildOptionVideoCost">AI video motion per scene — scenes × 20 cr each</div>
            </div>

            <button class="biz-done-btn" id="step6ContinueBtn" style="width:100%;" disabled onclick="step6ContinueToGenerate()">Continue →</button>
            <div id="step6Result" style="margin-top:10px;font-size:12px;"></div>
            <a id="step6EditorBtn" class="biz-done-btn" style="width:100%;margin-top:10px;display:none;text-decoration:none;text-align:center;" href="#">✏️ Open in Editor →</a>
          </div>

        </div>

    </div>

</div>

<!-- ── Credit / Upgrade modal (Step 7 — AI Motion Video gate) ─────────────── -->
<div id="creditUpgradeOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(10,20,40,.72);align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);" onclick="if(event.target===this) closeCreditUpgradeModal()">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:380px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.4);">
    <div id="creditUpgradeModalBody"></div>
  </div>
</div>

<!-- ── Music Library modal ──────────────────────────────────────────────── -->
<div id="musicLibModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.7);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;height:70vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.4);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#7c3aed;flex-shrink:0;">
      <span style="color:#fff;font-size:13px;font-weight:700;">🎵 Music Library</span>
      <button onclick="closeMusicLibModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:13px;">✕</button>
    </div>
    <div style="padding:10px 12px;border-bottom:1px solid var(--border);flex-shrink:0;">
      <input id="musicLibSearch" type="text" placeholder="Search by filename…" class="business-input" style="width:100%;" oninput="filterMusicLibGrid()">
    </div>
    <div id="musicLibGrid" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:6px;"></div>
    <div style="padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <span id="musicLibSelInfo" style="font-size:10px;color:var(--muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button onclick="closeMusicLibModal()" style="padding:6px 14px;border-radius:8px;border:1.5px solid var(--border);background:#f9fafb;font-size:11px;font-weight:600;cursor:pointer;">Cancel</button>
        <button id="musicLibUseBtn" onclick="useMusicLibFile()" disabled style="padding:6px 14px;border-radius:8px;border:none;background:var(--dark-blue);color:#fff;font-size:11px;font-weight:700;cursor:pointer;opacity:.4;">✓ Use</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Theme Gallery modal — view ALL of a theme's real photos ───────────── -->
<div id="themeGalleryModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.7);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this) closeThemeGallery()">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:480px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.4);">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#7c3aed;flex-shrink:0;">
      <span id="themeGalleryTitle" style="color:#fff;font-size:13px;font-weight:700;">🖼️ Theme</span>
      <button onclick="closeThemeGallery()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:13px;">✕</button>
    </div>
    <div id="themeGalleryGrid" style="flex:1;overflow-y:auto;padding:12px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;"></div>
    <div style="padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;justify-content:flex-end;gap:8px;">
      <button onclick="closeThemeGallery()" style="padding:6px 14px;border-radius:8px;border:1.5px solid var(--border);background:#f9fafb;font-size:11px;font-weight:600;cursor:pointer;">Close</button>
      <button onclick="useThemeFromGallery()" style="padding:6px 14px;border-radius:8px;border:none;background:var(--dark-blue);color:#fff;font-size:11px;font-weight:700;cursor:pointer;">✓ Use This Theme</button>
    </div>
  </div>
</div>

</div>
</div>

<!-- ── Target Settings overlay ───────────────────────────────────────────── -->
<div id="targetOverlay" class="settings-overlay" onclick="targetOverlayClick(event)">
  <div class="settings-panel" style="max-width:420px;">
    <div class="settings-header">
      <span class="settings-title">🎯 Target Audience &amp; Location</span>
      <button class="settings-close" onclick="closeTargetSettings()">✕</button>
    </div>
    <div class="setting-group">
      <div class="setting-label">📍 Target Location</div>
      <input type="text" id="targetLocationInput" class="business-input" style="width:100%;" placeholder="e.g. Toronto, Canada — or Nationwide">
    </div>
    <div class="setting-group" style="margin-bottom:8px;">
      <div class="setting-label">👥 Target Audience</div>
      <input type="text" id="targetAudienceInput" class="business-input" style="width:100%;" placeholder="e.g. Young professionals aged 25-40">
    </div>
    <div class="biz-footer">
      <button class="biz-done-btn" id="targetSaveBtn" onclick="saveTargetSettings()">Save</button>
    </div>
  </div>
</div>

<!-- ── Business Settings overlay ─────────────────────────────────────────── -->
<div id="businessOverlay" class="settings-overlay" onclick="businessOverlayClick(event)">
  <div class="settings-panel">
    <div class="settings-header">
      <span class="settings-title">🏢 Business Settings</span>
      <button class="settings-close" onclick="closeBusinessSettings()">✕</button>
    </div>
    <div id="business-content"></div>
    <p style="font-size:11px;color:#bbb;margin-top:6px;text-align:center;">Saved to your company profile · Select both to complete</p>
  </div>
</div>

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════════ -->
<script>
const PHP_CO_GROUP    = <?= json_encode($php_co_group) ?>;
const PHP_CO_SUBGROUP = <?= json_encode($php_co_subgroup) ?>;
const PHP_TARGET_LOCATION = <?= json_encode($php_target_location) ?>;
const PHP_TARGET_AUDIENCE = <?= json_encode($php_target_audience) ?>;
const IS_FREE_TRIAL = <?= $is_free_trial ? 'true' : 'false' ?>;

// ═════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ═════════════════════════════════════════════════════════════════════════════
function esc(s)     { return String(s).replace(/"/g,'&quot;'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function showToast(msg) {
    const t = Object.assign(document.createElement('div'), { className:'toast', textContent:msg });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(() => t.remove(), 400); }, 1800);
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP ACCORDION — each step (step1/step2/step3) is a clickable header
// (".settings-bar") + a "{prefix}Body" div. Completing a step collapses its
// body and shows a short summary in the header; clicking the header again
// re-expands it for editing. The summary text is cached on the header span's
// dataset so toggling back and forth doesn't lose it.
// ═════════════════════════════════════════════════════════════════════════════
function collapseStep(prefix, summaryText) {
    const body = document.getElementById(prefix + 'Body');
    if (body) body.style.display = 'none';
    const summaryEl = document.getElementById(prefix + 'Summary');
    if (summaryEl) {
        summaryEl.dataset.summary = summaryText || '';
        summaryEl.textContent = summaryText ? ('— ' + summaryText) : '';
    }
    const chevEl = document.getElementById(prefix + 'Chevron');
    if (chevEl) chevEl.textContent = 'Edit →';
}

function expandStep(prefix) {
    const body = document.getElementById(prefix + 'Body');
    if (body) body.style.display = '';
    const summaryEl = document.getElementById(prefix + 'Summary');
    if (summaryEl) summaryEl.textContent = '';
    const chevEl = document.getElementById(prefix + 'Chevron');
    if (chevEl) chevEl.textContent = '▾';
}

function toggleStep(prefix) {
    const body = document.getElementById(prefix + 'Body');
    if (!body) return;
    if (body.style.display === 'none') {
        expandStep(prefix);
    } else {
        const summaryEl = document.getElementById(prefix + 'Summary');
        collapseStep(prefix, summaryEl ? (summaryEl.dataset.summary || '') : '');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// DB HELPER
// ═════════════════════════════════════════════════════════════════════════════
async function post(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch(location.href, { method:'POST', body:fd });
    const text = await r.text();
    try {
        return JSON.parse(text);
    } catch(e) {
        console.error('[post] JSON parse error for action:', payload.ajax_action, '\nRaw response:', text.substring(0,500));
        throw new Error('Server returned invalid JSON: ' + text.substring(0,100));
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// BUSINESS SETTINGS  (Group → Sub-group)
// Flow: pick chip → Done button advances to next panel
//       Back button returns to previous panel
//       Final Done saves both fields to hdb_companies and closes modal
// ═════════════════════════════════════════════════════════════════════════════

// ── Company industry state (synced to hdb_companies) ─────────────────────────
const coIndustry = {
    group:    PHP_CO_GROUP    || '',
    subgroup: PHP_CO_SUBGROUP || '',
};

// ── Render the Business settings bar pills ────────────────────────────────────
function renderBusinessBar() {
    const pills = [coIndustry.group, coIndustry.subgroup].filter(Boolean);
    const html = pills.length
        ? pills.map(function(v) { return '<span class="s-pill">' + escHtml(v) + '</span>'; }).join('')
        : '<span style="font-size:11px;color:#aaa;font-style:italic;">Not set</span>';
    const el = document.getElementById('business-bar-pills');
    if (el) el.innerHTML = html;
    renderGarmentGate();
}

// Temp selections held while user moves through panels — only committed on final Done
const bizTemp = { group:'', subgroup:'', subgroupId:'' };

// Which panel: 'group' | 'subgroup'
let bizPanel = 'group';

async function openBusinessSettings() {
    // Seed temp from what's already saved
    bizTemp.group      = coIndustry.group    || '';
    bizTemp.subgroup   = coIndustry.subgroup || '';
    bizTemp.subgroupId = '';

    bizPanel = 'group';
    document.getElementById('businessOverlay').classList.add('open');

    if (!window._masterGroups || window._masterGroups.length === 0) {
        _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading…</span></div>`);
        try {
            const d = await post({ ajax_action:'get_master_industries' });
            if (d.success) window._masterGroups = d.groups || [];
        } catch(e) {}
    }
    _renderBizGroupPanel();
}

function _renderBizPanel(html) {
    document.getElementById('business-content').innerHTML = html;
}

// ── Breadcrumb strip ──────────────────────────────────────────────────────────
function _bizBreadcrumb() {
    const parts = [bizTemp.group, bizTemp.subgroup].filter(Boolean);
    if (!parts.length) return '';
    return `<div class="biz-breadcrumb">
      ${parts.map((p,i) => `<span class="biz-bc-item${i===parts.length-1?' biz-bc-active':''}">${escHtml(p)}</span>`).join('<span class="biz-bc-sep">›</span>')}
    </div>`;
}

// ── Step indicator ────────────────────────────────────────────────────────────
function _bizStepDots(active) {
    const steps = ['Category','Subcategory'];
    return `<div class="biz-step-dots">
      ${steps.map((s,i) => `<div class="biz-sdot${i<active?' biz-sdot-done':i===active?' biz-sdot-active':''}">
        <span class="biz-sdot-icon">${i<active?'✓':i+1}</span>
        <span class="biz-sdot-label">${s}</span>
      </div>`).join('<div class="biz-sdot-line"></div>')}
    </div>`;
}

// ── PANEL 1: Group ────────────────────────────────────────────────────────────
function _renderBizGroupPanel() {
    bizPanel = 'group';
    const groups = window._masterGroups || [];

    const chips = groups.map(g =>
        `<div class="sopt${bizTemp.group===g.core_group?' sel':''}"
            onclick="bizTempSelectGroup(this)"
            data-v="${esc(g.core_group)}">${g.icon ? escHtml(g.icon) + ' ' : ''}${escHtml(g.core_group)}</div>`
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

function bizTempSelectGroup(el) {
    document.querySelectorAll('#biz-group-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    const newGroup = el.dataset.v;
    if (bizTemp.group !== newGroup) {
        bizTemp.group      = newGroup;
        bizTemp.subgroup   = '';
        bizTemp.subgroupId = '';
        delete window['_sgCache_' + newGroup];
    }
    document.getElementById('biz-done-group').disabled = false;
}

async function _bizGroupDone() {
    if (!bizTemp.group) return;
    await _renderBizSubgroupPanel();
}

// ── PANEL 2: Sub-group ────────────────────────────────────────────────────────
async function _renderBizSubgroupPanel() {
    bizPanel = 'subgroup';
    const cacheKey = '_sgCache_' + bizTemp.group;

    _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading industries…</span></div>`);

    if (!window[cacheKey]) {
        try {
            const d = await post({ ajax_action:'get_master_subgroups', core_group:bizTemp.group });
            window[cacheKey] = d.success ? (d.subgroups || []) : [];
        } catch(e) { window[cacheKey] = []; }
    }

    // Resolve subgroupId from cache if not set
    if (bizTemp.subgroup && !bizTemp.subgroupId) {
        const match = (window[cacheKey] || []).find(s => s.industry_desc === bizTemp.subgroup);
        if (match) bizTemp.subgroupId = match.id;
    }

    const subs = window[cacheKey];
    const chips = subs.map(sg =>
        `<div class="sopt${bizTemp.subgroup===sg.industry_desc?' sel':''}"
            onclick="bizTempSelectSubgroup(this)"
            data-v="${esc(sg.industry_desc)}"
            data-id="${sg.id}">${escHtml(sg.industry_desc)}</div>`
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

function bizTempSelectSubgroup(el) {
    document.querySelectorAll('#biz-subgroup-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    if (bizTemp.subgroup !== el.dataset.v) {
        bizTemp.subgroup   = el.dataset.v;
        bizTemp.subgroupId = el.dataset.id;
    }
    document.getElementById('biz-done-subgroup').disabled = false;
}

async function _bizSubgroupDone() {
    if (!bizTemp.subgroup) return;
    await _bizSaveAndClose();
}

// ── Final commit — save group + subgroup to hdb_companies ────────────────────
async function _bizSaveAndClose() {
    coIndustry.group    = bizTemp.group;
    coIndustry.subgroup = bizTemp.subgroup;

    await Promise.all([
        post({ ajax_action:'save_company_industry', field:'ai_group',    value:coIndustry.group    }),
        post({ ajax_action:'save_company_industry', field:'ai_subgroup', value:coIndustry.subgroup }),
    ]).catch(() => {});

    renderBusinessBar();
    showToast('Business profile saved ✓');
    closeBusinessSettings();
}

function closeBusinessSettings() {
    document.getElementById('businessOverlay').classList.remove('open');
    renderBusinessBar();
}

function businessOverlayClick(e) {
    if (e.target === document.getElementById('businessOverlay')) closeBusinessSettings();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — MANNEQUIN/PRODUCT → MODEL  — only for sub-groups that need a model
// ═════════════════════════════════════════════════════════════════════════════

// Must match hdb_promo_subcategories.promo_subgroup (group "Fashion & Beauty").
// Canonical DB names first; older descriptive labels kept as aliases. Compared
// case-insensitively below. NB the DB uses "Ind/Pak Bridal wear" — the previous
// list had "Indian/Pakistani Bridal Wear", which never matched, so that category
// always fell through to the "not applicable" message.
const MODEL_REQUIRED_SUBGROUPS = [
    'Bridal wear', 'Party wear', 'Ind/Pak Bridal wear', 'Jewellery',
    'Indian/Pakistani Bridal Wear' // alias for older saved values
];

function subgroupNeedsModel(sg) {
    if (!sg) return false;
    const norm = sg.trim().toLowerCase();
    return MODEL_REQUIRED_SUBGROUPS.some(s => s.trim().toLowerCase() === norm);
}

function renderGarmentGate() {
    const needsModel = subgroupNeedsModel(coIndustry.subgroup);
    const wrap = document.getElementById('garmentStep1Wrap');
    const na   = document.getElementById('garmentNotApplicable');
    if (wrap) wrap.style.display = needsModel ? '' : 'none';
    if (na)   na.style.display   = needsModel ? 'none' : '';
    if (needsModel && !wrap.dataset.loaded) {
        wrap.dataset.loaded = '1';
        step1Reset();
        // Pose styles for Step 2 are loaded lazily once Step 1 has produced
        // a generated model image — see generateStep1Model() below.
    }
}

// ── Draft state — everything here lives only in the browser + on-disk draft
// files until "Build Video Scenes" in Step 2 actually writes the DB rows. ──
let step1Draft = {
    draftId: null,
    garmentUploaded: false,
    generatedUrl: null,
    bgRemoved: false,
};

function step1Reset() {
    step1Draft = { draftId: null, garmentUploaded: false, generatedUrl: null, bgRemoved: false };
    document.getElementById('step1Title').value = '';
    document.getElementById('step1Description').value = '';
    document.getElementById('step1ModelPreset').value = '';
    document.getElementById('step1ModelDescription').value = '';
    document.getElementById('step1Resolution').value = '1K';
    document.getElementById('step1RemoveBg').checked = true;
    document.getElementById('step1GarmentPreviewWrap').style.display = 'none';
    document.getElementById('step1GarmentZone').style.display = '';
    document.getElementById('step1ResultArea').innerHTML = '';
    document.getElementById('step1bWrap').style.display = 'none';
    document.getElementById('garmentStep23Wrap').style.display = 'none';
    document.getElementById('styleGrid').innerHTML = '';
    document.querySelectorAll('#styleGrid .style-card').forEach(c => c.classList.remove('sel'));
    selectedStyle = null;
    expandStep('step1');
    expandStep('step1b');
    expandStep('step2');
    selectedTheme = null;
    const themeGrid = document.getElementById('themeGrid'); if (themeGrid) themeGrid.innerHTML = '';
    step3Podcast = { podcastId: null, scenes: [] };
    scenePoseSuccess = {};
    const step3 = document.getElementById('step3Wrap'); if (step3) step3.style.display = 'none';
    const step4 = document.getElementById('step4Wrap'); if (step4) step4.style.display = 'none';
    const step5 = document.getElementById('step5Wrap'); if (step5) step5.style.display = 'none';
    const step6 = document.getElementById('step6Wrap'); if (step6) step6.style.display = 'none';
    selectedBuildOption = null;
    clearPodcastMusicLocal();
    const step5Btn = document.getElementById('step5ContinueBtn');
    if (step5Btn) step5Btn.disabled = true;
    const gpRes = document.getElementById('generatePosesResult'); if (gpRes) gpRes.textContent = '';
    document.getElementById('buildOptionStatic').classList.remove('sel');
    document.getElementById('buildOptionVideo').classList.remove('sel');
    const step6Btn = document.getElementById('step6ContinueBtn'); if (step6Btn) { step6Btn.disabled = true; step6Btn.style.display = ''; }
    const step6Editor = document.getElementById('step6EditorBtn'); if (step6Editor) step6Editor.style.display = 'none';
    const sceneGrid = document.getElementById('sceneCardsGrid'); if (sceneGrid) sceneGrid.innerHTML = '';
}

// Validates the garment step (title + photo uploaded) and advances from
// Step 1 (garment) to Step 2 (model) — no fal.ai call here, this step is
// free. Mirrors step1ContinueToStyle()'s collapse/expand pattern below.
function step1ContinueToModel() {
    const title = document.getElementById('step1Title').value.trim();
    if (!title) { showToast('Enter a video title first'); return; }
    if (!step1Draft.garmentUploaded && !step1Draft.draftId) { showToast('Upload the dress/product photo first'); return; }

    collapseStep('step1', title);
    document.getElementById('step1bWrap').style.display = '';
    document.getElementById('step1bWrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function applyStep1ModelPreset() {
    const sel = document.getElementById('step1ModelPreset');
    if (sel.value) document.getElementById('step1ModelDescription').value = sel.value;
}

let step1GarmentFile = null;

function handleStep1GarmentUpload(file) {
    if (!file) return;
    step1GarmentFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('step1GarmentPreview').src = e.target.result;
        document.getElementById('step1GarmentPreviewWrap').style.display = 'block';
        document.getElementById('step1GarmentZone').style.display = 'none';
    };
    reader.readAsDataURL(file);
    step1Draft.garmentUploaded = true;
}

function clearStep1Garment() {
    step1GarmentFile = null;
    step1Draft.garmentUploaded = false;
    document.getElementById('step1GarmentPreviewWrap').style.display = 'none';
    document.getElementById('step1GarmentZone').style.display = '';
}

async function step1RemoveGarmentBg() {
    if (!step1Draft.draftId) {
        showToast('Generate at least once first so the draft is saved server-side');
        return;
    }
    const btn = document.getElementById('step1RemoveBgBtn');
    btn.disabled = true; btn.textContent = 'Removing…';
    try {
        const d = await post({ ajax_action: 'step1_remove_garment_bg', draft_id: step1Draft.draftId });
        if (!d.success) throw new Error(d.message || 'Failed');
        document.getElementById('step1GarmentPreview').src = d.public_url;
        showToast('Background removed — ' + d.credits_charged + ' cr charged, ' + d.credits_balance + ' remaining');
    } catch (e) {
        showToast('✕ ' + e.message);
    } finally {
        btn.disabled = false; btn.textContent = '🪄 Remove Background (2 cr)';
    }
}

function step1RenderResult(d) {
    const area = document.getElementById('step1ResultArea');
    area.innerHTML = `
        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;max-width:260px;">
            <img src="${esc(d.public_url)}" style="width:100%;display:block;">
            <div style="padding:8px 10px;font-size:11px;color:var(--muted);display:flex;justify-content:space-between;">
                <span>${d.bg_removed ? '✓ background removed' : 'background kept'}</span>
                <span>${escHtml(d.resolution)}</span>
            </div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <a class="biz-back-btn" href="${esc(d.public_url)}" download="model.jpg" style="text-decoration:none;display:inline-block;">⬇ Download</a>
            <button class="biz-done-btn" onclick="step1ContinueToStyle()">Continue →</button>
        </div>
        ${d.bg_removal_error ? `<div style="margin-top:6px;font-size:11px;color:#dc2626;">⚠ Background removal failed: ${escHtml(d.bg_removal_error)}</div>` : ''}
    `;
}

async function generateStep1Model() {
    const title = document.getElementById('step1Title').value.trim();
    if (!title) { showToast('Enter a video title first'); return; }
    if (!step1Draft.garmentUploaded && !step1Draft.draftId) { showToast('Upload the dress/product photo first'); return; }
    const modelDescription = document.getElementById('step1ModelDescription').value.trim();
    if (!modelDescription) { showToast('Model description is required — pick a quick preset or type your own'); return; }

    const btn = document.getElementById('step1GenerateBtn');
    btn.disabled = true; btn.textContent = '⏳ Generating…';
    document.getElementById('step1ResultArea').innerHTML = '<div class="garment-card-spin" style="margin:18px auto 8px;"></div><div style="text-align:center;font-size:12px;color:var(--muted);">Processing the image…</div>';

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'generate_step1_model');
        fd.append('title', title);
        fd.append('description', document.getElementById('step1Description').value.trim());
        fd.append('model_description', modelDescription);
        fd.append('resolution', document.getElementById('step1Resolution').value);
        fd.append('remove_bg', document.getElementById('step1RemoveBg').checked ? 'true' : 'false');
        if (step1Draft.draftId) fd.append('draft_id', step1Draft.draftId);
        if (step1GarmentFile) fd.append('garment_image', step1GarmentFile);

        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) {
            if (d.upgrade_needed) {
                document.getElementById('step1ResultArea').innerHTML = '';
                showCreditUpgradeModal(d, false); // false = don't show the "Use Static Images instead" fallback here, that's a Step 6 thing
                return;
            }
            throw new Error(d.message || 'Generation failed');
        }

        step1Draft.draftId      = d.draft_id;
        step1Draft.generatedUrl = d.public_url;
        step1Draft.bgRemoved    = d.bg_removed;

        step1RenderResult(d);
        showToast('✓ Generated — ' + d.credits_charged + ' cr charged, ' + d.credits_balance + ' remaining');
    } catch (e) {
        document.getElementById('step1ResultArea').innerHTML = `<div style="font-size:12px;color:#dc2626;">✕ ${escHtml(e.message)}</div>`;
    } finally {
        btn.disabled = false; btn.textContent = '➡️ Continue (10 cr)';
    }
}

// Collapses Step 2 (model) and reveals Step 3 (style picker) — the model
// image generated above is reused as-is, no separate model-selection step
// needed beyond this point.
function step1ContinueToStyle() {
    collapseStep('step1b', document.getElementById('step1Title').value.trim() || 'model generated');
    document.getElementById('garmentStep23Wrap').style.display = '';
    loadPoseStyles();
    document.getElementById('garmentStep23Wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — STYLE SELECTION
// ═════════════════════════════════════════════════════════════════════════════
let poseStyles    = [];
let selectedStyle = null;

async function loadPoseStyles() {
    const grid = document.getElementById('styleGrid');
    grid.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading styles…</span></div>`;
    try {
        const d = await post({ ajax_action: 'get_pose_styles' });
        poseStyles = d.success ? (d.styles || []) : [];
    } catch (e) { poseStyles = []; }
    renderStyleGrid();
}

function renderStyleGrid() {
    const grid = document.getElementById('styleGrid');
    const hint = document.getElementById('styleNeedsModelHint');
    const hasModel = !!step1Draft.generatedUrl;
    if (hint) hint.style.display = hasModel ? 'none' : '';

    if (!poseStyles.length) {
        grid.innerHTML = '<div style="font-size:12px;color:#aaa;">No styles found.</div>';
        return;
    }
    grid.innerHTML = poseStyles.map(s => `
        <div class="style-card${hasModel ? '' : ' disabled'}" data-id="${s.id}" onclick="styleSelect(${s.id})">
            <div class="style-card-name">${escHtml(s.stylename)}</div>
            <div class="style-card-scenes">${s.scenes.map(escHtml).join(' → ')}</div>
        </div>`).join('');
}

// Picking a style no longer triggers a new fal call — Step 1 already
// produced the final "model wearing garment" image, so this just collapses
// Step 2 (no need to show that image again — it's already visible in
// Step 1) and unlocks Step 3 (Background Theme).
function styleSelect(styleId) {
    if (step3Podcast.podcastId) return; // scenes already built for this draft — locked
    const style = poseStyles.find(s => String(s.id) === String(styleId));
    if (!style) return;
    if (!step1Draft.generatedUrl) {
        showToast('Generate the model in Step 2 first');
        return;
    }
    selectedStyle = style;

    document.querySelectorAll('#styleGrid .style-card').forEach(c => {
        c.classList.toggle('sel', String(c.dataset.id) === String(styleId));
    });

    collapseStep('step2', `${style.stylename} — ${style.scenes.join(' → ')}`);

    document.getElementById('step3Wrap').style.display = '';
    document.getElementById('step3Wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!themesLoaded) loadThemes();
}

// Background Theme (Step 3) reviewed — collapse it and reveal Step 4
// (Video Setting: duration, caption style, then music once scenes exist).
function step3ContinueToVideoSetting() {
    collapseStep('step3', selectedTheme ? selectedTheme.theme_name : 'No theme');
    document.getElementById('step4Wrap').style.display = '';
    document.getElementById('createVideoResult').innerHTML = '';
    document.getElementById('step4Wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ═════════════════════════════════════════════════════════════════════════════
// BACKGROUND THEMES (Haveli, Castle, Desert, Studio, etc. — DB-backed via
// hdb_theme_types; cards show a real thumbnail photo per theme instead of
// an emoji icon, since each theme now has actual stock photos)
// ═════════════════════════════════════════════════════════════════════════════
let themesLoaded = false;
let availableThemes = [];
let selectedTheme = null; // null = no theme (plain/white background)

async function loadThemes() {
    themesLoaded = true;
    const grid = document.getElementById('themeGrid');
    grid.innerHTML = '<div style="font-size:11px;color:var(--muted);grid-column:1/-1;">Loading themes…</div>';
    try {
        const d = await post({ ajax_action: 'get_themes' });
        availableThemes = d.success ? (d.themes || []) : [];
    } catch (e) { availableThemes = []; }
    renderThemeGrid();
}

function renderThemeGrid() {
    const grid = document.getElementById('themeGrid');
    const cards = [`<div class="theme-card${!selectedTheme ? ' sel' : ''}" data-key="" onclick="themeSelect(null)">
                <div class="theme-card-thumb-wrap theme-card-thumb-empty"><span class="theme-card-icon">⬜</span></div>
                No Theme
            </div>`]
        .concat(availableThemes.map(t => `
            <div class="theme-card${selectedTheme && selectedTheme.theme_key === t.theme_key ? ' sel' : ''}" data-key="${escHtml(t.theme_key)}" onclick="themeSelectByKey('${escHtml(t.theme_key)}')">
                <div class="theme-card-thumb-wrap">
                    <img class="theme-card-thumb" src="${escHtml(t.preview_image)}" alt="${escHtml(t.theme_name)}" loading="lazy">
                    <button type="button" class="theme-card-viewall" title="View all photos for this theme" onclick="openThemeGallery('${escHtml(t.theme_key)}', event)">🔍</button>
                </div>
                ${escHtml(t.theme_name)}
            </div>`));
    grid.innerHTML = cards.join('');
}

// ── Theme gallery — lets the person see ALL of a theme's real photos (not
// just the one representative thumbnail on the card) before committing to
// it, since a single preview image doesn't give a great sense of the theme.
let _themeGalleryActiveKey = null;

async function openThemeGallery(themeKey, event) {
    if (event) event.stopPropagation(); // don't also trigger the card's own theme-select click
    _themeGalleryActiveKey = themeKey;
    document.getElementById('themeGalleryTitle').textContent = '🖼️ ' + themeKey;
    document.getElementById('themeGalleryGrid').innerHTML = '<div style="grid-column:1/-1;font-size:11px;color:var(--muted);">Loading…</div>';
    document.getElementById('themeGalleryModal').style.display = 'flex';
    try {
        const d = await post({ ajax_action: 'get_theme_gallery', theme_name: themeKey });
        const imgs = d.success ? (d.images || []) : [];
        document.getElementById('themeGalleryGrid').innerHTML = imgs.length
            ? imgs.map(src => `<img src="${escHtml(src)}" loading="lazy" class="theme-gallery-img">`).join('')
            : '<div style="grid-column:1/-1;font-size:11px;color:var(--muted);">No images found for this theme</div>';
    } catch (e) {
        document.getElementById('themeGalleryGrid').innerHTML = '<div style="grid-column:1/-1;font-size:11px;color:#dc2626;">Failed to load images</div>';
    }
}

function closeThemeGallery() {
    document.getElementById('themeGalleryModal').style.display = 'none';
}

function useThemeFromGallery() {
    if (_themeGalleryActiveKey) themeSelectByKey(_themeGalleryActiveKey);
    closeThemeGallery();
}

function themeSelectByKey(key) {
    const t = availableThemes.find(x => x.theme_key === key);
    themeSelect(t || null);
}

function themeSelect(theme) {
    if (step3Podcast.podcastId) return; // locked after scenes are built
    selectedTheme = theme;
    renderThemeGrid();
}

// Called once createVideoProject() succeeds — prevents picking a different
// style or re-clicking "Build Video Scenes" and creating a duplicate
// hdb_podcasts row for the same draft.
function lockStep2Selection() {
    const grid = document.getElementById('styleGrid');
    if (grid) { grid.style.pointerEvents = 'none'; grid.style.opacity = '0.55'; }
    const themeGrid = document.getElementById('themeGrid');
    if (themeGrid) { themeGrid.style.pointerEvents = 'none'; themeGrid.style.opacity = '0.55'; }
    const dur = document.getElementById('step2Duration');       if (dur) dur.disabled = true;
    const cap = document.getElementById('step2CaptionStyle');   if (cap) cap.disabled = true;
}

// ── Step 3 hookup point ─────────────────────────────────────────────────────
// NOT IMPLEMENTED YET — Step 3 (scene captions, hdb_podcasts/_stories/_captions
// creation, 20cr/scene cost confirmation, then Step 5's fal+webhook firing)
// is the next phase. For now this just confirms the selection so Step 1+2
// can be tested end-to-end on their own.
// Calls create_podcast_from_step1 — builds hdb_podcasts/_stories/_captions
// for every scene in the chosen style, each pulling its own video_prompt
// from mdl_model_pose_templates (same lookup the old flow used), all
// pointing at the single Step 1 model image. Free — no fal call here.
let step3Podcast = { podcastId: null, scenes: [] };

async function createVideoProject() {
    if (step3Podcast.podcastId) { showToast('Scenes already built for this draft — refresh to start a new one'); return; }
    if (!step1Draft.generatedUrl || !selectedStyle) { showToast('Select a style first'); return; }
    const btn = document.getElementById('createVideoBtn');
    const out = document.getElementById('createVideoResult');
    btn.disabled = true;
    out.innerHTML = '<span style="color:#9ca3af;">Building scenes…</span>';
    let succeeded = false;
    try {
        const d = await post({
            ajax_action:    'create_podcast_from_step1',
            draft_id:       step1Draft.draftId,
            style_id:       selectedStyle.id,
            title:          document.getElementById('step1Title').value.trim(),
            description:    document.getElementById('step1Description').value.trim(),
            caption_style:  document.getElementById('step2CaptionStyle').value,
            duration_target: document.getElementById('step2Duration').value,
            theme_key:             selectedTheme ? selectedTheme.theme_key : '',
        });
        if (!d.success) throw new Error(d.message || 'Failed');

        step3Podcast = { podcastId: d.podcast_id, scenes: d.scenes, estimatedCost: d.estimated_cost };
        succeeded = true;
        const durNote = d.actual_total_secs > d.duration_target
            ? ` (~${d.actual_total_secs}s actual — ${d.duration_target}s target, 6s/scene floor)`
            : ` (~${d.actual_total_secs}s)`;
        out.innerHTML = `<span style="color:#10b981;font-weight:600;">✓ Created podcast #${d.podcast_id} with ${d.scene_count} scene${d.scene_count===1?'':'s'}${durNote}.</span>`;
        if (!d.thumbnail_saved) {
            out.innerHTML += `<div style="margin-top:4px;color:#dc2626;">⚠ Thumbnail copy failed: ${escHtml(d.thumbnail_error || 'unknown error')}</div>`;
        }
        if (Array.isArray(d.template_debug)) {
            console.log('Step 3 template match per scene:', d.template_debug);
            const missed = d.template_debug.filter(t => !t.matched);
            if (missed.length) {
                out.innerHTML += `<div style="margin-top:4px;color:#dc2626;">⚠ No mdl_model_pose_templates row matched for: ${missed.map(t => escHtml(t.pose_code)).join(', ')} — see console for full per-scene breakdown.</div>`;
            }
        }

        // ── Now that the podcast row exists, push whatever music/volume the
        // person already picked in Step 4 (before the podcast existed) up
        // to the server in one go — upload takes priority over a library
        // pick if somehow both got set. Failures here are non-fatal; the
        // scenes are already built either way. ─────────────────────────────
        try {
            if (pendingMusicFile) {
                const fd = new FormData();
                fd.append('ajax_action', 'upload_podcast_music');
                fd.append('podcast_id', step3Podcast.podcastId);
                fd.append('music_file', pendingMusicFile);
                const r = await fetch(location.href, { method: 'POST', body: fd });
                const md = await r.json();
                if (md.success) currentPodcastMusic = md.filename;
            } else if (currentPodcastMusic) {
                await post({ ajax_action: 'update_podcast_music', podcast_id: step3Podcast.podcastId, music_file: currentPodcastMusic });
            }
            await post({
                ajax_action: 'save_podcast_volumes',
                podcast_id: step3Podcast.podcastId,
                music_volume: bgMusicVolume.toFixed(2),
                voice_volume: voiceVolume.toFixed(2),
            });
        } catch (musicErr) {
            console.warn('Music/volume sync after podcast creation failed:', musicErr);
        }

        lockStep2Selection();
        collapseStep('step4', step3Podcast.scenes.length + ' scene(s)');
        document.getElementById('step4Wrap').style.display = 'none';

        await revealStep5AndGeneratePoses();
    } catch (e) {
        out.innerHTML = `<span style="color:#dc2626;">✕ ${escHtml(e.message)}</span>`;
        btn.disabled = false; // re-enable to retry on failure
    }
}

function renderSceneCards() {
    const grid = document.getElementById('sceneCardsGrid');
    grid.innerHTML = step3Podcast.scenes.map(sc => `
        <div class="model-card" style="text-align:left;cursor:default;" data-story-id="${sc.story_id}">
            <div style="position:relative;">
                <img class="scene-pose-img" data-story-id="${sc.story_id}" src="${esc(sc.image_url)}" style="aspect-ratio:3/4;width:100%;display:block;">
                <div class="scene-pose-spinner" data-story-id="${sc.story_id}" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,.6);align-items:center;justify-content:center;">
                    <div class="garment-card-spin"></div>
                </div>
                <div class="scene-pose-status" data-story-id="${sc.story_id}" style="display:none;position:absolute;top:4px;right:4px;font-size:10px;background:rgba(0,0,0,.6);color:#fff;padding:2px 6px;border-radius:6px;"></div>
            </div>
            <div style="padding:6px 8px;">
                <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Scene ${sc.seq_no} · ${escHtml(sc.pose_code)}</div>
                <textarea class="business-input scene-caption-input" data-story-id="${sc.story_id}" rows="2"
                    style="width:100%;font-size:12px;box-sizing:border-box;">${escHtml(sc.caption)}</textarea>
                <button class="biz-back-btn" style="width:100%;margin-top:4px;font-size:11px;padding:5px;"
                    onclick="saveSceneCaption(${sc.story_id}, this)">💾 Save</button>
                <button class="biz-back-btn scene-pose-retry" data-story-id="${sc.story_id}" style="width:100%;margin-top:4px;font-size:11px;padding:5px;display:none;"
                    onclick="generateOneScenePose(${sc.story_id}, true)">🪄 Retry Pose (5 cr)</button>
                <button class="biz-back-btn scene-custom-toggle" data-story-id="${sc.story_id}" style="width:100%;margin-top:4px;font-size:11px;padding:5px;"
                    onclick="toggleCustomPrompt(${sc.story_id})">✏️ Not happy? Write your own prompt</button>
                <div class="scene-custom-prompt-box" data-story-id="${sc.story_id}" style="display:none;margin-top:4px;">
                    <textarea class="business-input scene-custom-prompt-input" data-story-id="${sc.story_id}" rows="2"
                        placeholder="Describe the pose/scene you want instead…" style="width:100%;font-size:12px;box-sizing:border-box;"></textarea>
                    <button class="biz-back-btn" style="width:100%;margin-top:4px;font-size:11px;padding:5px;background:var(--purple);color:#fff;border-color:var(--purple);"
                        onclick="regenerateWithCustomPrompt(${sc.story_id})">🪄 Regenerate (5 cr)</button>
                </div>
            </div>
        </div>`).join('');
    // Poses fire automatically as soon as Step 5 is revealed — see
    // revealStep5AndGeneratePoses(). Captions stay editable throughout.
}

// Lets the person try their own description when the AI-generated pose/
// theme composite isn't what they wanted — collapsed by default so it
// doesn't clutter the card, opened on demand per scene.
function toggleCustomPrompt(storyId) {
    const box = document.querySelector(`.scene-custom-prompt-box[data-story-id="${storyId}"]`);
    if (box) box.style.display = (box.style.display === 'none' ? '' : 'none');
}

function setScenePoseStatus(storyId, text, color) {
    const el = document.querySelector(`.scene-pose-status[data-story-id="${storyId}"]`);
    if (el) { el.style.display = ''; el.textContent = text; el.style.background = color || 'rgba(0,0,0,.6)'; }
}

function setScenePoseSpinner(storyId, show) {
    const el = document.querySelector(`.scene-pose-spinner[data-story-id="${storyId}"]`);
    if (el) el.style.display = show ? 'flex' : 'none';
}

// story_id -> true (succeeded) | false (failed after retry) | undefined (not yet resolved)
let scenePoseSuccess = {};

// All scenes fire AT ONCE (parallel), same approach already proven for
// OpenAI image/audio generation elsewhere in this app — each scene is its
// own independent fal.ai call/credit charge, so there's no reason to make
// the person wait scene-by-scene. A short delay before the single auto-retry
// gives a transient fal.ai/network hiccup a moment to clear before trying
// again, same pattern used for the audio generation retries.
async function generateAllScenePoses() {
    step3Podcast.scenes.forEach(sc => {
        setScenePoseStatus(sc.story_id, '⏳ queued');
        setScenePoseSpinner(sc.story_id, true);
        delete scenePoseSuccess[sc.story_id];
    });
    await Promise.all(step3Podcast.scenes.map(sc => generateOneScenePose(sc.story_id, false)));
}

const POSE_RETRY_DELAY_MS = 3000;

// isManualRetry=false → this is the automatic Step 5 pass: on failure it
// waits a few seconds then retries once more before giving up (no credit
// risk — the charge in generate_scene_pose only fires on a successful
// generation).
// isManualRetry=true  → person clicked "Retry Pose" themselves; still gets
// the same one-extra-attempt safety net, plus a toast either way.
// customPrompt (optional) → person's own description from the "Not happy?"
// box, sent through as custom_prompt so the backend swaps it in for the
// auto-generated pose description instead. Same 5cr charge either way.
async function generateOneScenePose(storyId, isManualRetry, customPrompt) {
    const retryBtn = document.querySelector(`.scene-pose-retry[data-story-id="${storyId}"]`);
    if (retryBtn) retryBtn.style.display = 'none';
    setScenePoseSpinner(storyId, true);

    const MAX_ATTEMPTS = 2;
    let lastErr = null;
    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        if (attempt > 1) await new Promise(r => setTimeout(r, POSE_RETRY_DELAY_MS));
        setScenePoseStatus(storyId, attempt === 1 ? '⏳ generating…' : '⏳ retrying…', attempt === 1 ? null : 'rgba(217,119,6,.85)');
        try {
            const payload = { ajax_action: 'generate_scene_pose', story_id: storyId };
            if (customPrompt) payload.custom_prompt = customPrompt;
            const d = await post(payload);
            if (!d.success) throw new Error(d.message || 'Failed');
            const img = document.querySelector(`.scene-pose-img[data-story-id="${storyId}"]`);
            if (img) img.src = d.public_url;
            const statusText = selectedTheme ? (d.theme_applied ? '✓ themed' : '✓ pose only (theme failed)') : '✓ done';
            setScenePoseStatus(storyId, statusText, d.theme_applied || !selectedTheme ? 'rgba(16,185,129,.85)' : 'rgba(217,119,6,.85)');
            if (isManualRetry) showToast('✓ Pose regenerated — ' + d.credits_charged + ' cr charged');
            scenePoseSuccess[storyId] = true;
            setScenePoseSpinner(storyId, false);
            checkAllPosesDone();
            return true;
        } catch (e) {
            lastErr = e;
            // first failure on the automatic pass gets one retry (after a
            // short delay); a manual retry click only gets the attempts in
            // this one call
        }
    }
    setScenePoseStatus(storyId, '✕ failed', 'rgba(220,38,38,.85)');
    setScenePoseSpinner(storyId, false);
    if (retryBtn) retryBtn.style.display = '';
    if (isManualRetry) showToast('✕ ' + (lastErr ? lastErr.message : 'Failed'));
    scenePoseSuccess[storyId] = false;
    checkAllPosesDone();
    return false;
}

// Triggered by the "🪄 Regenerate (5 cr)" button inside a scene card's
// custom-prompt box. Reuses generateOneScenePose's spinner/status/retry
// handling — just passes the person's own text through as customPrompt.
async function regenerateWithCustomPrompt(storyId) {
    const input = document.querySelector(`.scene-custom-prompt-input[data-story-id="${storyId}"]`);
    const customPrompt = input ? input.value.trim() : '';
    if (!customPrompt) { showToast('Type a prompt describing what you want first'); return; }
    const ok = await generateOneScenePose(storyId, true, customPrompt);
    if (ok) toggleCustomPrompt(storyId); // collapse the box back after a successful regen
}

// Updates the generatePosesResult message + enables/disables Step 5's
// Continue button based on whether every scene has a successful pose yet.
function checkAllPosesDone() {
    const out = document.getElementById('generatePosesResult');
    const btn = document.getElementById('step5ContinueBtn');
    const total = step3Podcast.scenes.length;
    const resolved = step3Podcast.scenes.filter(sc => scenePoseSuccess[sc.story_id] !== undefined).length;
    const failed   = step3Podcast.scenes.filter(sc => scenePoseSuccess[sc.story_id] === false).length;
    const allOk    = total > 0 && resolved === total && failed === 0;

    if (btn) btn.disabled = !allOk;
    if (!out) return;
    if (allOk) {
        out.innerHTML = '<span style="color:#10b981;font-weight:600;">✓ All poses generated.</span>';
    } else if (resolved < total) {
        out.innerHTML = `<span style="color:#9ca3af;">Generating poses… (${resolved}/${total})</span>`;
    } else if (failed > 0) {
        out.innerHTML = `<span style="color:#dc2626;">${failed} scene${failed===1?'':'s'} still failed after auto-retry — use Retry Pose above, then Continue unlocks.</span>`;
    }
}

async function saveSceneCaption(storyId, btnEl) {
    const ta = document.querySelector(`.scene-caption-input[data-story-id="${storyId}"]`);
    const caption = (ta?.value || '').trim();
    if (!caption) { showToast('Caption cannot be empty'); return; }
    btnEl.disabled = true;
    const orig = btnEl.textContent;
    btnEl.textContent = 'Saving…';
    try {
        const d = await post({ ajax_action: 'update_scene_caption', story_id: storyId, caption });
        if (!d.success) throw new Error(d.message || 'Failed');
        const sc = step3Podcast.scenes.find(s => String(s.story_id) === String(storyId));
        if (sc) sc.caption = caption;
        btnEl.textContent = '✓ Saved';
    } catch (e) {
        btnEl.textContent = '✕ Failed';
        showToast(e.message);
    } finally {
        setTimeout(() => { btnEl.textContent = orig; btnEl.disabled = false; }, 1200);
    }
}

// Step 4 (Video Setting) is done and hidden — reveal Step 5 and immediately
// fire pose generation for every scene. The Continue button stays disabled
// until every scene has a successful pose (auto-retried once on failure).
async function revealStep5AndGeneratePoses() {
    renderSceneCards();
    document.getElementById('step5Wrap').style.display = '';
    document.getElementById('step5Wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('step5ContinueBtn').disabled = true;
    document.getElementById('generatePosesResult').textContent = '';
    await generateAllScenePoses();
    checkAllPosesDone();
}

// Step 5 done (Continue only enables once every pose succeeded) — collapse
// it and reveal Step 6 (Select Video Mode).
function step5ContinueToVideoMode() {
    collapseStep('step5', step3Podcast.scenes.length + ' pose(s) generated');
    revealStep6();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 6 — SELECT VIDEO MODE: static images (50 cr flat) vs video scenes (20 cr/scene)
// ═════════════════════════════════════════════════════════════════════════════
let selectedBuildOption = null; // 'static' | 'video'

function revealStep6() {
    const sceneCount = step3Podcast.scenes.length;
    const staticCost = 50; // flat, regardless of scene count
    const videoCost  = sceneCount * 20;
    step3Podcast.staticCost = staticCost;
    step3Podcast.videoCost  = videoCost;
    document.getElementById('buildOptionStaticCost').textContent = `Slideshow of the pose images — ${staticCost} cr flat`;
    document.getElementById('buildOptionVideoCost').textContent  = `AI video motion per scene — ${sceneCount} scenes × 20 cr = ${videoCost} cr`;
    const wrap = document.getElementById('step6Wrap');
    wrap.style.display = '';
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

let _videoBuildStarted = false; // guard so the Video card can't double-fire

async function selectBuildOption(option) {
    selectedBuildOption = option;
    document.getElementById('buildOptionStatic').classList.toggle('sel', option === 'static');
    document.getElementById('buildOptionVideo').classList.toggle('sel', option === 'video');
    const btn = document.getElementById('step6ContinueBtn');

    // ── Static Images → keep the Continue button (confirm + charge on click) ──
    if (option === 'static') {
        btn.style.display = '';
        btn.disabled = false;
        btn.textContent = `Continue with Static Images (${step3Podcast.staticCost} cr) →`;
        return;
    }

    // ── Video Scenes → fire AI video generation immediately on this click ──
    // No Continue step: credit-check, confirm the mode (charges credits +
    // set_video_mode flips videogen_flag), fire the fal.ai webhook pipeline,
    // then reveal the "Open in Editor" button at the bottom. The whole thing
    // is webhook-driven and browser-independent once started.
    if (_videoBuildStarted) return;
    const out = document.getElementById('step6Result');

    const cancelSelection = () => {
        document.getElementById('buildOptionVideo').classList.remove('sel');
        selectedBuildOption = null;
    };

    // Pre-check balance so the upgrade prompt shows before we charge anything.
    try {
        const d = await post({ ajax_action: 'get_credit_balance' });
        if (d.success && d.credit_balance < step3Podcast.videoCost) {
            showCreditUpgradeModal({
                plan_type: d.plan_type,
                message: `AI Motion Video needs ${step3Podcast.videoCost} credits — you have ${Math.floor(d.credit_balance)}.`
            });
            cancelSelection();
            return;
        }
    } catch (e) { /* non-fatal — set_video_mode still enforces server-side */ }

    _videoBuildStarted = true;
    btn.style.display = 'none';            // no Continue button for the video path
    if (out) out.innerHTML = '<span style="color:#9ca3af;">Confirming…</span>';

    try {
        const sv = await post({ ajax_action: 'set_video_mode', podcast_id: step3Podcast.podcastId, mode: 'video' });
        if (!sv.success) {
            _videoBuildStarted = false;
            if (sv.upgrade_needed) {
                if (out) out.innerHTML = '';
                showCreditUpgradeModal(sv);
                cancelSelection();
                return;
            }
            if (out) out.innerHTML = `<span style="color:#dc2626;">✕ ${escHtml(sv.message || 'Failed')}</span>`;
            return;
        }

        // Reveal the editor button now that the build is confirmed.
        const editorBtn = document.getElementById('step6EditorBtn');
        editorBtn.href = `videomaker.php?podcast_id=${step3Podcast.podcastId}`;
        editorBtn.style.display = '';

        // Fire the fal.ai webhook pipeline (queues all scenes + kicks the cron).
        await startAIVideos();
    } catch (e) {
        _videoBuildStarted = false;
        if (out) out.innerHTML = `<span style="color:#dc2626;">✕ ${escHtml(e.message)}</span>`;
    }
}

// Sets videogen_flag on every hdb_podcast_stories row for this podcast:
// 0 for Static Images (no AI motion needed), 1 for Video Scenes (AI motion
// per scene). Rows already exist from Step 4's create_podcast_from_step1,
// so this is always an UPDATE here — see set_video_mode handler.
// ── Credit / Upgrade modal — shared across any "insufficient credits, go buy
// more" moment (Step 1's model generation, Step 6's AI Motion Video pick,
// etc). return_url is always the current page+query, so buying credits
// drops the person right back into the SAME video they were building.
// showStaticFallback (default true) controls the extra "Use Static Images
// instead" escape hatch — that only makes sense from Step 6, so Step 1's
// call passes false to hide it. ─────────────────────────────────────────────
function showCreditUpgradeModal(d, showStaticFallback) {
    if (showStaticFallback === undefined) showStaticFallback = true;
    const returnUrl   = encodeURIComponent(window.location.pathname + window.location.search);
    const planType    = d.plan_type || 'free_trial';
    const isFreeTrial  = planType === 'free_trial';
    const isAgency     = planType === 'agency';
    const title   = isFreeTrial ? 'Upgrade to Continue' : 'Out of Credits';
    // Agency plan is the top subscription tier — there's nothing to
    // "upgrade" to, so it gets its own Pay-As-You-Go top-up page.
    const ctaHref = isFreeTrial ? '/pricing_free_trial.php?return_url=' + returnUrl
                  : isAgency    ? '/pricing_agency.php?return_url=' + returnUrl
                  : '/pricing.php?return_url=' + returnUrl;
    const ctaText = isFreeTrial ? 'View Plans →' : 'Buy Credits →';
    const ctaBg   = isFreeTrial ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : 'linear-gradient(135deg,#22c55e,#16a34a)';
    const staticFallbackBtn = showStaticFallback
        ? '<button onclick="closeCreditUpgradeModal(); selectBuildOption(\'static\');" style="background:none;border:1.5px solid #e2e8f0;border-radius:10px;color:#64748b;font-size:13px;font-weight:600;padding:12px;cursor:pointer;">Use Static Images instead</button>'
        : '';
    const body = '<div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);padding:28px;text-align:center;">'
        + '<div style="font-size:40px;margin-bottom:10px;">' + (isFreeTrial ? '🚀' : '💳') + '</div>'
        + '<h2 style="font-size:18px;font-weight:800;color:#fff;margin:0 0 8px;">' + escHtml(title) + '</h2>'
        + '<p style="font-size:13px;color:rgba(255,255,255,.78);margin:0;">' + escHtml(d.message || 'This needs more credits than your current balance covers.') + '</p>'
        + '</div>'
        + '<div style="padding:20px;display:flex;flex-direction:column;gap:10px;">'
        + '<a href="' + ctaHref + '" onclick="closeCreditUpgradeModal()" style="display:block;text-align:center;background:' + ctaBg + ';color:#fff;padding:14px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;">' + ctaText + '</a>'
        + staticFallbackBtn
        + '</div>';
    document.getElementById('creditUpgradeModalBody').innerHTML = body;
    document.getElementById('creditUpgradeOverlay').style.display = 'flex';
}
function closeCreditUpgradeModal() {
    document.getElementById('creditUpgradeOverlay').style.display = 'none';
}

async function step6ContinueToGenerate() {
    if (!selectedBuildOption) { showToast('Choose a build option first'); return; }
    const cost = selectedBuildOption === 'static' ? step3Podcast.staticCost : step3Podcast.videoCost;
    const btn = document.getElementById('step6ContinueBtn');
    const out = document.getElementById('step6Result');
    btn.disabled = true;
    out.innerHTML = '<span style="color:#9ca3af;">Saving…</span>';
    try {
        const d = await post({ ajax_action: 'set_video_mode', podcast_id: step3Podcast.podcastId, mode: selectedBuildOption });
        if (!d.success) {
            if (d.upgrade_needed) {
                out.innerHTML = '';
                btn.disabled = false;
                showCreditUpgradeModal(d);
                return;
            }
            throw new Error(d.message || 'Failed');
        }
        out.innerHTML = `<div style="margin-top:4px;padding:10px 12px;background:#f0fdf4;border:1.5px solid #10b981;border-radius:8px;color:#059669;font-weight:600;font-size:12px;">`
            + `✓ Confirmed — podcast #${step3Podcast.podcastId}, `
            + `"${selectedBuildOption === 'static' ? 'Static Images' : 'Video Scenes'}" build, ${cost} credits `
            + `(${d.scenes_updated} scene${d.scenes_updated===1?'':'s'} updated).</div>`;

        // Hide Continue once confirmed — it can't be used again for this
        // podcast, and leaving it visible was squeezing the message above
        // between two full-width buttons, making it easy to miss.
        btn.style.display = 'none';

        // NOTE: editor URL/param guessed as videomaker.php?podcast_id= to
        // match this app's existing convention — adjust here if your actual
        // editor entry point or query param name differs.
        const editorBtn = document.getElementById('step6EditorBtn');
        editorBtn.href = `videomaker.php?podcast_id=${step3Podcast.podcastId}`;
        editorBtn.style.display = '';

        if (selectedBuildOption === 'video') {
            await startAIVideos();
        }
    } catch (e) {
        out.innerHTML = `<span style="color:#dc2626;">✕ ${escHtml(e.message)}</span>`;
        btn.disabled = false;
    }
}

async function startAIVideos() {
    if (!step3Podcast || !step3Podcast.podcastId) return;
    const out = document.getElementById('step6Result');
    if (out) {
        out.innerHTML = '<span style="color:#9ca3af;">Starting AI video generation via fal.ai…</span>';
    }

    try {
        const d = await post({ ajax_action: 'start_ai_videos', podcast_id: step3Podcast.podcastId });
        if (!d.success) {
            if (out) out.innerHTML = `<span style="color:#dc2626;">✕ ${escHtml(d.error || d.message || 'Could not start AI video generation')}</span>`;
            return;
        }
        if (out) {
            out.innerHTML = `<div style="margin-top:4px;padding:10px 12px;background:#f0fdf4;border:1.5px solid #10b981;border-radius:8px;color:#059669;font-weight:600;font-size:12px;">` +
                `${d.in_progress || 0} scene video(s) are being generated in the background via fal.ai. You can safely close this tab and return to the editor later.` +
                `</div>`;
        }
    } catch (e) {
        if (out) out.innerHTML = `<span style="color:#dc2626;">✕ Network error: ${escHtml(e.message)}</span>`;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — BACKGROUND MUSIC (ported from videomaker.php's music picker)
// ═════════════════════════════════════════════════════════════════════════════
let bgMusicVolume        = 0.30;
let voiceVolume          = 1.00;
let currentPodcastMusic  = '';
let _musicLibFiles       = [];
let _musicLibSelected    = '';
let _volSaveTimer        = null;
let pendingMusicFile     = null;   // File object picked via Upload, not yet on the server
let _musicPreviewObjUrl  = null;   // blob: URL for a pending upload's local preview

function clearPodcastMusicLocal() {
    pendingMusicFile = null;
    currentPodcastMusic = '';
    if (_musicPreviewObjUrl) { URL.revokeObjectURL(_musicPreviewObjUrl); _musicPreviewObjUrl = null; }
    const player = document.getElementById('musicPreviewPlayer');
    if (player) { player.pause(); player.removeAttribute('src'); player.load(); }
    const playBtn = document.getElementById('musicPlayBtn');
    if (playBtn) { playBtn.disabled = true; playBtn.textContent = '▶ Play'; }
    _renderCurrentMusic();
}

function _renderCurrentMusic() {
    const wrap = document.getElementById('musicCurrentWrap'); if (!wrap) return;
    wrap.innerHTML = currentPodcastMusic
        ? `<div style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1.5px solid #10b981;border-radius:8px;padding:7px 10px;">
             <span style="font-size:16px;">🎵</span>
             <span style="flex:1;font-size:11px;font-weight:600;color:#059669;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escHtml(currentPodcastMusic)}">${escHtml(currentPodcastMusic)}</span>
           </div>`
        : `<div style="font-size:12px;color:var(--muted);">No background music selected</div>`;
}

function onMusicVolChange(val) {
    bgMusicVolume = parseInt(val) / 100;
    document.getElementById('musicVolLbl').textContent = val + '%';
    const player = document.getElementById('musicPreviewPlayer');
    if (player) player.volume = bgMusicVolume;
    _saveVolumes();
}
function onVoiceVolChange(val) {
    voiceVolume = parseInt(val) / 100;
    document.getElementById('voiceVolLbl').textContent = val + '%';
    _saveVolumes();
}
// Only actually hits the server once the podcast exists (Step 4's Continue
// button creates it and pushes whatever volumes are set at that point) —
// before that this is a no-op so sliding it around pre-podcast doesn't error.
function _saveVolumes() {
    clearTimeout(_volSaveTimer);
    _volSaveTimer = setTimeout(() => {
        if (!step3Podcast.podcastId) return;
        post({
            ajax_action: 'save_podcast_volumes',
            podcast_id: step3Podcast.podcastId,
            music_volume: bgMusicVolume.toFixed(2),
            voice_volume: voiceVolume.toFixed(2),
        }).catch(() => {});
    }, 600);
}

function uploadMusicClick() {
    document.getElementById('musicFileInput').click();
}

// Step 4's music picker now works BEFORE the podcast row exists — the file
// is just held client-side (with a local blob: preview) and only actually
// uploaded to the server when Step 4's "Continue" button fires
// create_podcast_from_step1 and gets a podcast_id back. See createVideoProject().
function handleMusicUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    pendingMusicFile = file;
    currentPodcastMusic = file.name;
    bgMusicVolume = 1.0; voiceVolume = 0.0; // music uploaded → default to music-forward mix
    document.getElementById('musicVolSlider').value = 100; document.getElementById('musicVolLbl').textContent = '100%';
    document.getElementById('voiceVolSlider').value = 0;   document.getElementById('voiceVolLbl').textContent = '0%';

    if (_musicPreviewObjUrl) URL.revokeObjectURL(_musicPreviewObjUrl);
    _musicPreviewObjUrl = URL.createObjectURL(file);
    const player = document.getElementById('musicPreviewPlayer');
    player.src = _musicPreviewObjUrl;
    player.volume = bgMusicVolume;
    document.getElementById('musicPlayBtn').disabled = false;
    document.getElementById('musicPlayBtn').textContent = '▶ Play';

    _renderCurrentMusic();
    _saveVolumes();
    showToast('✓ Music selected — will upload when you hit Continue');
}

function toggleMusicPreview() {
    const player = document.getElementById('musicPreviewPlayer');
    const btn = document.getElementById('musicPlayBtn');
    if (!player || !player.src) return;
    if (player.paused) {
        player.play();
        btn.textContent = '⏸ Pause';
    } else {
        player.pause();
        btn.textContent = '▶ Play';
    }
}
function onMusicPreviewEnded() {
    const btn = document.getElementById('musicPlayBtn');
    if (btn) btn.textContent = '▶ Play';
}

function clearPodcastMusic() {
    clearPodcastMusicLocal();
    if (step3Podcast.podcastId) {
        post({ ajax_action: 'update_podcast_music', podcast_id: step3Podcast.podcastId, music_file: '' }).catch(() => {});
    }
}

// ── Music library modal ──────────────────────────────────────────────────
async function openMusicLibModal() {
    document.getElementById('musicLibModal').style.display = 'flex';
    document.getElementById('musicLibSearch').value = '';
    _musicLibSelected = '';
    document.getElementById('musicLibGrid').innerHTML = '<div style="font-size:12px;color:var(--muted);">Loading…</div>';
    try {
        const d = await post({ ajax_action: 'get_music_library' });
        _musicLibFiles = d.success ? (d.files || []) : [];
    } catch (e) { _musicLibFiles = []; }
    filterMusicLibGrid();
}
function closeMusicLibModal() {
    document.getElementById('musicLibModal').style.display = 'none';
}
function filterMusicLibGrid() {
    const q = (document.getElementById('musicLibSearch').value || '').toLowerCase();
    const grid = document.getElementById('musicLibGrid');
    const filtered = _musicLibFiles.filter(f => f.filename.toLowerCase().includes(q));
    if (!filtered.length) {
        grid.innerHTML = '<div style="font-size:12px;color:var(--muted);padding:10px;">No music files found.</div>';
        return;
    }
    grid.innerHTML = filtered.map(f => `
        <div class="music-lib-row" data-file="${escHtml(f.filename)}" onclick="selectMusicLibFile('${escHtml(f.filename)}')"
            style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid ${f.filename === _musicLibSelected ? 'var(--purple)' : 'var(--border)'};border-radius:8px;cursor:pointer;background:${f.filename === _musicLibSelected ? 'var(--purple-lt)' : '#fff'};">
            <span style="font-size:14px;">🎵</span>
            <span style="flex:1;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(f.filename)}</span>
            <span style="font-size:10px;color:var(--muted);">${Math.round(f.size/1024)} KB</span>
        </div>`).join('');
}
function selectMusicLibFile(filename) {
    _musicLibSelected = filename;
    filterMusicLibGrid();
    const info = document.getElementById('musicLibSelInfo');
    const btn  = document.getElementById('musicLibUseBtn');
    info.textContent = filename;
    btn.disabled = false; btn.style.opacity = '1';
}
// Library tracks already live on the server (podcast_music/ folder), so the
// preview can play immediately via a relative URL — no upload needed. The
// actual hdb_podcasts.music_file assignment is still deferred to Step 4's
// Continue button if the podcast doesn't exist yet (see createVideoProject()).
function useMusicLibFile() {
    if (!_musicLibSelected) return;
    pendingMusicFile = null;
    if (_musicPreviewObjUrl) { URL.revokeObjectURL(_musicPreviewObjUrl); _musicPreviewObjUrl = null; }
    currentPodcastMusic = _musicLibSelected;

    // Music selected → same music-forward default mix as the Upload path:
    // music at 100%, voice at 0%.
    bgMusicVolume = 1.0; voiceVolume = 0.0;
    document.getElementById('musicVolSlider').value = 100; document.getElementById('musicVolLbl').textContent = '100%';
    document.getElementById('voiceVolSlider').value = 0;   document.getElementById('voiceVolLbl').textContent = '0%';

    const player = document.getElementById('musicPreviewPlayer');
    player.src = 'podcast_music/' + _musicLibSelected;
    player.volume = bgMusicVolume;
    document.getElementById('musicPlayBtn').disabled = false;
    document.getElementById('musicPlayBtn').textContent = '▶ Play';

    _renderCurrentMusic();
    closeMusicLibModal();
    _saveVolumes();
    if (step3Podcast.podcastId) {
        post({ ajax_action: 'update_podcast_music', podcast_id: step3Podcast.podcastId, music_file: currentPodcastMusic }).catch(() => {});
    }
    showToast('✓ Background music set' + (step3Podcast.podcastId ? '' : ' — will apply when you hit Continue'));
}

// ═════════════════════════════════════════════════════════════════════════════
// VIDEO SETTING (TARGET) BAR
// ═════════════════════════════════════════════════════════════════════════════
function renderTargetBar() {
    const el = document.getElementById('target-bar-pills');
    if (!el) return;
    const parts = [];
    if (PHP_TARGET_LOCATION) parts.push('<span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">📍 ' + escHtml(PHP_TARGET_LOCATION) + '</span>');
    if (PHP_TARGET_AUDIENCE) parts.push('<span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">👥 ' + escHtml(PHP_TARGET_AUDIENCE) + '</span>');
    el.innerHTML = parts.length ? parts.join('') : '<span style="font-size:12px;color:var(--muted);font-style:italic;">Not set</span>';
}

function openTargetSettings() {
    document.getElementById('targetLocationInput').value = PHP_TARGET_LOCATION;
    document.getElementById('targetAudienceInput').value = PHP_TARGET_AUDIENCE;
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
        await post({ ajax_action: 'save_company_industry', field: 'target_location', value: loc });
        await post({ ajax_action: 'save_company_industry', field: 'target_audience', value: aud });
        window.PHP_TARGET_LOCATION = loc; // not strictly needed since these are consts, but kept for clarity
    } catch (e) {}
    closeTargetSettings();
    renderTargetBarManual(loc, aud);
    btn.disabled = false; btn.textContent = 'Save';
}
function renderTargetBarManual(loc, aud) {
    const el = document.getElementById('target-bar-pills');
    if (!el) return;
    const parts = [];
    if (loc) parts.push('<span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">📍 ' + escHtml(loc) + '</span>');
    if (aud) parts.push('<span class="s-pill" style="background:var(--purple-lt);color:#5b21b6;padding:4px 10px;border-radius:20px;font-size:12px;">👥 ' + escHtml(aud) + '</span>');
    el.innerHTML = parts.length ? parts.join('') : '<span style="font-size:12px;color:var(--muted);font-style:italic;">Not set</span>';
}

// ═════════════════════════════════════════════════════════════════════════════
// INIT
// ═════════════════════════════════════════════════════════════════════════════
renderBusinessBar();
renderTargetBar();
</script>
</body>
</html>

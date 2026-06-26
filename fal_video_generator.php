<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Set session for DB access
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['admin_id']   = 34;
    $_SESSION['company_id'] = 29;
}
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)$_SESSION['company_id'];

require_once 'config.php';

// ── Quick test: ?test_themes=1 ────────────────────────────
if (isset($_GET['test_themes'])) {
    header('Content-Type: application/json');
    $out = ['conn'=>isset($conn), 'session'=>$_SESSION];
    if (isset($conn)) {
        $q = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_promo_themes'");
        $out['table_exists'] = mysqli_num_rows($q) > 0;
        if ($out['table_exists']) {
            $q2 = mysqli_query($conn, "SELECT * FROM hdb_promo_themes LIMIT 3");
            $out['rows'] = [];
            while ($r = mysqli_fetch_assoc($q2)) $out['rows'][] = $r;
        }
        $out['db_error'] = mysqli_error($conn);
    }
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

$apiKey    = $apiKey    ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;
$falApiKey = $falApiKey ?? null;

// ── Session dress prefix ──────────────────────────────────────────────────
$sess_id   = session_id() ?: 'nosess';
$sess_prefix = 'dress_' . substr($sess_id, 0, 12);

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: upload_dress_image
// Saves file as dress_{sess}_{n}_{view}.ext, runs rembg, returns info
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'upload_dress_image') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    set_time_limit(90);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    $file    = $_FILES['dress_image'] ?? null;
    $seq     = (int)($_POST['seq'] ?? 1);  // 1-based upload sequence
    if (!$file || empty($file['tmp_name'])) { echo json_encode(['success'=>false,'error'=>'No file']); exit; }
    $allowed = ['image/jpeg','image/png','image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) { echo json_encode(['success'=>false,'error'=>'Invalid file type']); exit; }
    if ($file['size'] > 15*1024*1024) { echo json_encode(['success'=>false,'error'=>'File too large (max 15MB)']); exit; }

    $tmp_dir = __DIR__ . '/tmp/';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0777, true);

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $base     = $sess_prefix . '_' . $seq;           // dress_{sess}_{seq}
    $orig_path = $tmp_dir . $base . '_raw.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $orig_path)) {
        echo json_encode(['success'=>false,'error'=>'Could not save file']); exit;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $tmp_web  = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp_dir,'/')), '/') . '/';
    $proxy_url = $protocol.'://'.$host . rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') . '/fal_proxy.php?action=upload';

    // Upload to fal.ai storage
    $b64 = base64_encode(file_get_contents($orig_path));
    $uch = curl_init($proxy_url);
    curl_setopt_array($uch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['base64'=>$b64,'mime_type'=>$mime,'file_name'=>basename($orig_path)]),
    ]);
    $ures = curl_exec($uch); curl_close($uch);
    $uj   = json_decode($ures, true);
    if (empty($uj['file_url'])) {
        echo json_encode(['success'=>false,'error'=>'fal upload failed: '.$ures]); exit;
    }
    $fal_url = $uj['file_url'];

    // rembg — remove background
    $rch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS => json_encode(['image_url'=>$fal_url,'sync_mode'=>true]),
    ]);
    $rres = curl_exec($rch); $rhttp = curl_getinfo($rch, CURLINFO_HTTP_CODE); curl_close($rch);
    $rj   = json_decode($rres, true);
    if ($rhttp !== 200 || empty($rj['image']['url'])) {
        echo json_encode(['success'=>false,'error'=>'rembg failed (HTTP '.$rhttp.'): '.substr($rres,0,200)]); exit;
    }

    // Download transparent PNG, composite on white
    $png_data = @file_get_contents($rj['image']['url']);
    $png_path = $tmp_dir . $base . '_mask.png';
    file_put_contents($png_path, $png_data);
    $src = @imagecreatefrompng($png_path);
    $result_file = $base . '_white.jpg';
    $result_path = $tmp_dir . $result_file;
    if ($src) {
        $iw = imagesx($src); $ih = imagesy($src);
        $dst = imagecreatetruecolor($iw, $ih);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($src, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
        imagejpeg($dst, $result_path, 95);
        imagedestroy($src); imagedestroy($dst);
        @unlink($png_path);
    } else {
        // fallback: copy orig
        copy($orig_path, $result_path);
    }
    @unlink($orig_path);

    $result_url = $protocol.'://'.$host.$tmp_web.$result_file;
    echo json_encode([
        'success'      => true,
        'filename'     => $result_file,    // dress_{sess}_{seq}_white.jpg
        'url'          => $result_url,
        'seq'          => $seq,
        'sess_prefix'  => $sess_prefix,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: analyze_dress_view
// Calls OpenAI Vision to classify garment view angle
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'analyze_dress_view') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $image_url = trim($_POST['image_url'] ?? '');
    if (!$image_url) { echo json_encode(['success'=>false,'error'=>'No image_url']); exit; }

    if (!$apiKey) {
        // Fallback: guess front if no OpenAI key
        echo json_encode(['success'=>true,'view'=>'front','confidence'=>'low','fallback'=>true]);
        exit;
    }

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
                ['type'=>'image_url','image_url'=>['url'=>$image_url,'detail'=>'low']],
            ],
        ]],
    ];
    $och = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($och, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $ores = curl_exec($och); $ohttp = curl_getinfo($och, CURLINFO_HTTP_CODE); curl_close($och);
    $oj = json_decode($ores, true);
    $text = trim($oj['choices'][0]['message']['content'] ?? '');
    $parsed = json_decode($text, true);
    $valid  = ['front','back','left_side','right_side','upper_half','lower_half'];
    $view   = (isset($parsed['view']) && in_array($parsed['view'], $valid)) ? $parsed['view'] : 'front';
    echo json_encode(['success'=>true,'view'=>$view,'raw'=>$text]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: generate_dress_view
// Uses fal.ai flux to generate a missing view of the dress from the front image
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'generate_dress_view') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    set_time_limit(120);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    $front_url  = trim($_POST['front_url']  ?? '');
    $target_view = trim($_POST['target_view'] ?? '');
    $seq         = (int)($_POST['seq'] ?? 1);

    $view_prompts = [
        'back'       => 'back view of the same garment, rear side, same dress/clothing shown from behind',
        'left_side'  => 'left side profile view of the same garment, 90 degree angle from left',
        'right_side' => 'right side profile view of the same garment, 90 degree angle from right',
        'upper_half' => 'upper portion of the same garment cropped from neckline to stomach/waist — showing collar, neckline, chest, sleeves and mid-section, flat lay or on hanger, white background, no model, clean studio shot',
        'lower_half' => 'lower half of the same garment, close up of skirt/pants/hem only',
    ];
    if (!$front_url || !isset($view_prompts[$target_view])) {
        echo json_encode(['success'=>false,'error'=>'Invalid view or missing front_url']); exit;
    }

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $doc_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $tmp_dir   = __DIR__ . '/tmp/';
    $tmp_web   = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp_dir,'/')), '/') . '/';
    $proxy_url = $protocol.'://'.$host . rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') . '/fal_proxy.php?action=upload';

    // Use fal.ai flux-kontext (image-to-image) to generate the new view
    $prompt = 'Fashion product photography. ' . $view_prompts[$target_view]
            . '. White background. No model. Clean studio shot. Same fabric, colors, and design as the reference image.';

    // Try flux-kontext (image conditioned)
    $fch = curl_init('https://fal.run/fal-ai/flux-pro/kontext');
    curl_setopt_array($fch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt'         => $prompt,
            'image_url'      => $front_url,
            'sync_mode'      => true,
            'num_images'     => 1,
            'guidance_scale' => 3.5,
            'num_inference_steps' => 28,
        ]),
    ]);
    $fres = curl_exec($fch); $fhttp = curl_getinfo($fch, CURLINFO_HTTP_CODE); curl_close($fch);
    $fj   = json_decode($fres, true);
    $gen_url = $fj['images'][0]['url'] ?? null;

    if (!$gen_url) {
        // Fallback to flux/dev text-to-image
        $fch2 = curl_init('https://fal.run/fal-ai/flux/dev');
        curl_setopt_array($fch2, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
            CURLOPT_POSTFIELDS => json_encode([
                'prompt'      => $prompt,
                'image_size'  => 'portrait_4_3',
                'sync_mode'   => true,
                'num_images'  => 1,
                'guidance_scale' => 3.5,
                'num_inference_steps' => 28,
            ]),
        ]);
        $fres2 = curl_exec($fch2); curl_close($fch2);
        $fj2   = json_decode($fres2, true);
        $gen_url = $fj2['images'][0]['url'] ?? null;
    }

    if (!$gen_url) {
        echo json_encode(['success'=>false,'error'=>'Failed to generate '.$target_view.' view: '.substr($fres,0,200)]); exit;
    }

    // Download and save
    $img_data = @file_get_contents($gen_url);
    if (!$img_data) { echo json_encode(['success'=>false,'error'=>'Could not download generated image']); exit; }
    $filename  = $sess_prefix . '_gen_' . $target_view . '_white.jpg';
    $out_path  = $tmp_dir . $filename;
    file_put_contents($out_path, $img_data);
    $result_url = $protocol.'://'.$host.$tmp_web.$filename;

    echo json_encode(['success'=>true,'filename'=>$filename,'url'=>$result_url,'view'=>$target_view,'generated'=>true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: list_session_dress_images
// Returns all dress images for the current session
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'list_session_dress_images') {
    header('Content-Type: application/json');
    $tmp   = __DIR__ . '/tmp/';
    $files = [];
    if (is_dir($tmp)) {
        foreach (glob($tmp . $sess_prefix . '_*_white.jpg') as $fp) {
            $fn  = basename($fp);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'];
            $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp,'/')), '/') . '/';
            // parse view from filename
            preg_match('/_(?:gen_)?([a-z_]+)_white\.jpg$/', $fn, $m);
            $view = $m[1] ?? 'unknown';
            $files[] = ['filename'=>$fn,'url'=>$protocol.'://'.$host.$web.$fn,'view'=>$view,'time'=>filemtime($fp)];
        }
    }
    usort($files, fn($a,$b) => $a['time'] - $b['time']);
    echo json_encode(['success'=>true,'files'=>$files,'sess_prefix'=>$sess_prefix]);
    exit;
}


// ── Handle dress background removal AJAX ────────────────────────────────
if (!empty($_FILES['dress']['tmp_name']) && !empty($_POST['crop_action'])) {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured in config.php']); exit; }

    $file    = $_FILES['dress'];
    $allowed = ['image/jpeg','image/png','image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) { echo json_encode(['success'=>false,'error'=>'Invalid file type']); exit; }
    if ($file['size'] > 15*1024*1024) { echo json_encode(['success'=>false,'error'=>'File too large (max 15MB)']); exit; }

    $save_dir = __DIR__ . '/tmp/';
    if (!is_dir($save_dir)) @mkdir($save_dir, 0777, true);

    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $base      = 'dress_' . time() . '_' . substr(md5($file['name']),0,6);
    $orig_path = $save_dir . $base . '_orig.' . $ext;
    $moved = move_uploaded_file($file['tmp_name'], $orig_path);
    if (!$moved) $moved = @copy($file['tmp_name'], $orig_path);
    if (!$moved || !file_exists($orig_path)) {
        echo json_encode(['success'=>false,'error'=>'Could not save uploaded file.']); exit;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($save_dir,'/')), '/') . '/';
    $orig_url = $protocol.'://'.$host.$web_path.$base.'_orig.'.$ext;

    // ── Step 1: Upload image to fal.ai storage ────────────────────────────
    $b64      = base64_encode(file_get_contents($orig_path));
    $upload_payload = json_encode(['base64' => $b64, 'mime_type' => $mime, 'file_name' => $base.'.'.$ext]);
    $uch = curl_init($protocol.'://'.$host.rtrim($web_path,'/').'/../fal_proxy.php?action=upload');
    curl_setopt_array($uch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $upload_payload,
    ]);
    $ures = curl_exec($uch); curl_close($uch);
    $uj   = json_decode($ures, true);
    if (empty($uj['file_url'])) {
        echo json_encode(['success'=>false,'error'=>'Failed to upload image to fal.ai storage. '.(isset($uj['error'])?$uj['error']:$ures)]); exit;
    }
    $fal_image_url = $uj['file_url'];

    // ── Step 2: Call fal-ai/imageutils/rembg ─────────────────────────────
    $rembg_ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rembg_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Key ' . $falApiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'image_url' => $fal_image_url,
            'sync_mode' => true,
        ]),
    ]);
    $rres = curl_exec($rembg_ch);
    $rhttp = curl_getinfo($rembg_ch, CURLINFO_HTTP_CODE);
    curl_close($rembg_ch);
    $rj = json_decode($rres, true);

    if ($rhttp !== 200 || empty($rj['image']['url'])) {
        echo json_encode(['success'=>false,'error'=>'fal-ai/imageutils/rembg failed (HTTP '.$rhttp.'): '.substr($rres,0,300)]); exit;
    }
    $rembg_png_url = $rj['image']['url'];

    // ── Step 3: Download the transparent PNG and fill background white ────
    $png_data = @file_get_contents($rembg_png_url);
    if (!$png_data) {
        echo json_encode(['success'=>false,'error'=>'Could not download rembg result PNG.']); exit;
    }
    $png_path = $save_dir . $base . '_rembg.png';
    file_put_contents($png_path, $png_data);

    // Composite onto white background using GD
    $src = @imagecreatefrompng($png_path);
    $result_url = $orig_url; // fallback
    $result_file = $base.'_orig.'.$ext;

    if ($src) {
        $iw = imagesx($src); $ih = imagesy($src);
        $dst = imagecreatetruecolor($iw, $ih);
        // Fill white
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        // Enable alpha blending so transparent PNG composites correctly
        imagealphablending($src, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
        $out_file = $base . '_white.jpg';
        $out_path = $save_dir . $out_file;
        if (imagejpeg($dst, $out_path, 95)) {
            $result_url  = $protocol.'://'.$host.$web_path.$out_file;
            $result_file = $out_file;
        }
        imagedestroy($src); imagedestroy($dst);
        @unlink($png_path); // clean up temp PNG
    }

    $info = @getimagesize($orig_path);
    echo json_encode([
        'success'      => true,
        'original_url' => $orig_url,
        'cropped_url'  => $result_url,
        'crop'         => ['x'=>0,'y'=>0,'w'=>100,'h'=>100],
        'dimensions'   => ['w'=>$info[0]??0,'h'=>$info[1]??0],
        'filename'     => $result_file,
    ]);
    exit;
}
// ── Handle save_model AJAX ────────────────────────────────
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'save_model') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    $file = $_FILES['model_photo'] ?? null;
    if (!$file || empty($file['tmp_name'])) { echo json_encode(['success'=>false,'error'=>'No file received']); exit; }
    $allowed = ['image/jpeg','image/png','image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) { echo json_encode(['success'=>false,'error'=>'Invalid file type']); exit; }
    $tmp_dir = __DIR__ . '/tmp/';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0777, true);
    $ext      = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? 'jpg';
    $filename = 'model_' . time() . '_' . substr(md5($file['name']),0,6) . '.' . $ext;
    $path     = $tmp_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['success'=>false,'error'=>'Could not save model photo']); exit;
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $tmp_web  = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp_dir,'/')), '/') . '/';
    echo json_encode(['success'=>true,'filename'=>$filename,'url'=>$protocol.'://'.$host.$tmp_web.$filename]);
    exit;
}


// ── Handle get_themes AJAX ───────────────────────────────
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'get_themes') {
    header('Content-Type: application/json');

    // Try DB first, fallback to hardcoded themes
    $themes = [];
    $db_error = '';
    if (isset($conn)) {
        $q = mysqli_query($conn, 'SELECT theme_key, theme_name, description, preview_image, location_prompt FROM hdb_promo_themes WHERE status=\'active\' ORDER BY sort_order ASC');
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) $themes[] = $r;
        } else {
            $db_error = mysqli_error($conn);
        }
    } else {
        $db_error = 'No DB connection';
    }

    // Fallback: hardcoded themes
    if (empty($themes)) {
        $themes = [
            ['theme_key'=>'mughal_courtyard', 'theme_name'=>'Mughal Courtyard',  'description'=>'Classic Mughal architecture with stone arches, water channels and ornate gardens',        'preview_image'=>'', 'location_prompt'=>'Grand Mughal courtyard with carved stone arches, flowing water channel, lush manicured gardens with marigold borders, warm golden afternoon light casting long shadows through ornate jali screens'],
            ['theme_key'=>'luxury_hotel',     'theme_name'=>'Luxury Hotel',       'description'=>'5-star hotel interiors — marble lobby, chandeliers, grand staircase',                     'preview_image'=>'', 'location_prompt'=>'Opulent 5-star hotel interior with white marble floors, towering crystal chandeliers, grand curving staircase with gold banister, fresh white floral arrangements, soft warm ambient lighting'],
            ['theme_key'=>'royal_palace',     'theme_name'=>'Royal Palace',       'description'=>'Palatial settings with gold pillars, royal draping and regal interiors',                  'preview_image'=>'', 'location_prompt'=>'Magnificent royal palace hall with soaring gold-leaf pillars, deep red velvet draping, intricate ceiling murals, Persian rugs, warm candlelight ambiance evoking old-world royalty'],
            ['theme_key'=>'garden_wedding',   'theme_name'=>'Garden Wedding',     'description'=>'Lush outdoor garden with florals, fairy lights and natural greenery',                     'preview_image'=>'', 'location_prompt'=>'Romantic outdoor garden setting with manicured hedgerows, overflowing floral arrangements in blush and white, soft fairy lights strung above, dappled natural sunlight through mature trees'],
            ['theme_key'=>'haveli_interior',  'theme_name'=>'Haveli Interior',    'description'=>'Traditional South Asian haveli with carved wood, jharokhas and lanterns',                 'preview_image'=>'', 'location_prompt'=>'Traditional haveli interior with ornately carved wooden jharokha windows, warm lantern light, terracotta floor tiles, embroidered cushions on low seating, peacock motif screens casting patterned shadows'],
            ['theme_key'=>'desert_dunes',     'theme_name'=>'Desert Dunes',       'description'=>'Golden sand dunes at sunset with dramatic sky and ethereal atmosphere',                   'preview_image'=>'', 'location_prompt'=>'Vast golden desert dunes at magic hour, warm amber sun low on the horizon, soft wind lifting fine sand, dramatic cloudless sky transitioning from orange to deep purple, ethereal solitary mood'],
            ['theme_key'=>'modern_studio',    'theme_name'=>'Modern Studio',      'description'=>'Clean editorial studio with dramatic lighting and minimalist backdrop',                   'preview_image'=>'', 'location_prompt'=>'High-end fashion photography studio with seamless white-to-grey gradient backdrop, dramatic split lighting, polished concrete floor with subtle reflections, sleek minimalist editorial atmosphere'],
        ];
    }

    echo json_encode(['success'=>true,'themes'=>$themes,'db_error'=>$db_error,'conn_set'=>isset($conn)]);
    exit;
}


// ── Handle change_background AJAX ────────────────────────
// Step 1: rembg → transparent PNG
// Step 2: flux text-to-image for background scene
// Step 3: composite model PNG over background using GD
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'change_background') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    set_time_limit(180);

    $image_url       = trim($_POST['image_url']       ?? '');
    $location_prompt = trim($_POST['location_prompt'] ?? '');
    $theme_name      = trim($_POST['theme_name']      ?? '');

    if (!$image_url || !$location_prompt) {
        echo json_encode(['success'=>false,'error'=>'Missing image_url or location_prompt']); exit;
    }

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $proxy_url = $protocol.'://'.$host.dirname($_SERVER['SCRIPT_NAME']).'/fal_proxy.php?action=upload';

    // Helper: upload to fal
    $upload = function($path, $mime) use ($proxy_url) {
        $ch = curl_init($proxy_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['base64'=>base64_encode(file_get_contents($path)),'mime_type'=>$mime,'file_name'=>basename($path)]),
        ]);
        $r = curl_exec($ch); curl_close($ch);
        return json_decode($r,true)['file_url'] ?? null;
    };

    // ── Step 1: Download original image ──────────────────
    $tmp_dir = __DIR__ . '/tmp/';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0777, true);
    $orig_data = @file_get_contents($image_url);
    if (!$orig_data) { echo json_encode(['success'=>false,'error'=>'Cannot download image: '.$image_url]); exit; }
    $orig_path = $tmp_dir . 'bg_orig_' . time() . '.jpg';
    file_put_contents($orig_path, $orig_data);

    // ── Step 2: Upload & rembg ────────────────────────────
    $fal_url = $upload($orig_path, 'image/jpeg');
    if (!$fal_url) { echo json_encode(['success'=>false,'error'=>'Upload failed']); exit; }

    $rch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS => json_encode(['image_url'=>$fal_url,'sync_mode'=>true]),
    ]);
    $rres = curl_exec($rch); $rhttp = curl_getinfo($rch, CURLINFO_HTTP_CODE); curl_close($rch);
    $rj = json_decode($rres, true);
    if ($rhttp !== 200 || empty($rj['image']['url'])) {
        echo json_encode(['success'=>false,'error'=>'rembg failed: '.substr($rres,0,200)]); exit;
    }

    // Download transparent PNG
    $png_data = @file_get_contents($rj['image']['url']);
    $png_path = $tmp_dir . 'bg_mask_' . time() . '.png';
    file_put_contents($png_path, $png_data);
    $model_img = @imagecreatefrompng($png_path);
    if (!$model_img) { echo json_encode(['success'=>false,'error'=>'GD failed to load rembg PNG']); exit; }
    $iw = imagesx($model_img); $ih = imagesy($model_img);

    // ── Step 3: Generate background scene with flux ───────
    $bg_prompt = $location_prompt . ', empty scene, no people, fashion photography background, '
               . 'photorealistic, high quality, 9:16 portrait aspect ratio';
    $fch = curl_init('https://fal.run/fal-ai/flux/dev');
    curl_setopt_array($fch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt'      => $bg_prompt,
            'image_size'  => ['width'=>$iw,'height'=>$ih],
            'num_images'  => 1,
            'sync_mode'   => true,
            'guidance_scale' => 3.5,
            'num_inference_steps' => 28,
        ]),
    ]);
    $fres = curl_exec($fch); $fhttp = curl_getinfo($fch, CURLINFO_HTTP_CODE); curl_close($fch);
    $fj = json_decode($fres, true);
    if ($fhttp !== 200 || empty($fj['images'][0]['url'])) {
        echo json_encode(['success'=>false,'error'=>'BG generation failed (HTTP '.$fhttp.'): '.substr($fres,0,300)]); exit;
    }

    // ── Step 4: Composite model over generated background ─
    $bg_data = @file_get_contents($fj['images'][0]['url']);
    $bg_path = $tmp_dir . 'bg_scene_' . time() . '.jpg';
    file_put_contents($bg_path, $bg_data);
    $bg_img = @imagecreatefromjpeg($bg_path);
    if (!$bg_img) { echo json_encode(['success'=>false,'error'=>'GD failed to load background']); exit; }

    // Resize background to match model image size
    $bw = imagesx($bg_img); $bh = imagesy($bg_img);
    $canvas = imagecreatetruecolor($iw, $ih);
    imagecopyresampled($canvas, $bg_img, 0, 0, 0, 0, $iw, $ih, $bw, $bh);

    // Composite transparent model PNG over background
    imagealphablending($model_img, true);
    imagecopy($canvas, $model_img, 0, 0, 0, 0, $iw, $ih);

    // Save result
    $out_file = 'bg_result_' . time() . '.jpg';
    $out_path = $tmp_dir . $out_file;
    imagejpeg($canvas, $out_path, 92);
    imagedestroy($model_img); imagedestroy($bg_img); imagedestroy($canvas);
    @unlink($png_path); @unlink($orig_path); @unlink($bg_path);

    // Build public URL
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $tmp_web  = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp_dir,'/')), '/') . '/';
    $result_url = $protocol.'://'.$host.$tmp_web.$out_file;

    echo json_encode(['success'=>true,'result_url'=>$result_url]);
    exit;
}


// ── Handle fashn try-on AJAX ──────────────────────────────
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'fashn_tryon') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    set_time_limit(120);

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    $dress_file     = basename(trim($_POST['dress_file']       ?? ''));
    $model_cat      = basename(trim($_POST['model_cat']        ?? ''));
    $model_file     = basename(trim($_POST['model_file']       ?? ''));
    $garment_cat    = trim($_POST['garment_category']          ?? 'one-pieces');
    $theme_key      = trim($_POST['theme_key']                 ?? '');
    $theme_name     = trim($_POST['theme_name']                ?? '');
    $theme_location = trim($_POST['theme_location']            ?? '');

    if (!$dress_file || !$model_file) {
        echo json_encode(['success'=>false,'error'=>'dress_file and model_file required']); exit;
    }

    $dress_path = __DIR__ . '/tmp/' . $dress_file;
    if (!file_exists($dress_path)) {
        echo json_encode(['success'=>false,'error'=>'Dress file not found in tmp/: '.$dress_file]); exit;
    }

    // Try multiple model paths (including tmp/ for uploaded models)
    $model_candidates = [
        __DIR__ . '/tmp/' . $model_file,   // directly uploaded model
        __DIR__ . '/promo_models/' . $model_cat . '/' . $model_file,
        __DIR__ . '/promo_models/thumbnails/' . $model_file,
        __DIR__ . '/promo_models/' . $model_file,
    ];
    $model_path = null;
    foreach ($model_candidates as $c) { if (file_exists($c)) { $model_path = $c; break; } }
    if (!$model_path) {
        echo json_encode(['success'=>false,'error'=>'Model image not found: '.$model_cat.'/'.$model_file]); exit;
    }

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $doc_root  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    $proxy_url = $protocol.'://'.$host.rtrim($script_dir,'/')  .'/fal_proxy.php?action=upload';

    // Helper: upload file to fal.ai via proxy
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
        $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true)['file_url'] ?? null;
    };

    $dress_url = $uploadToFal($dress_path);
    if (!$dress_url) { echo json_encode(['success'=>false,'error'=>'Failed to upload dress to fal.ai']); exit; }

    $model_url = $uploadToFal($model_path);
    if (!$model_url) { echo json_encode(['success'=>false,'error'=>'Failed to upload model to fal.ai']); exit; }

    // Call fashn/tryon
    $ch = curl_init('https://fal.run/fal-ai/fashn/tryon/v1.6');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Key '.$falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'model_image'        => $model_url,
            'garment_image'      => $dress_url,
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
        echo json_encode(['success'=>false,'error'=>'fashn/tryon failed (HTTP '.$fhttp.'): '.substr($fres,0,300)]); exit;
    }
    echo json_encode(['success'=>true,'result_url'=>$fj['images'][0]['url']]);
    exit;
}

// ── Handle get_dress_images AJAX ─────────────────────────
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'get_dress_images') {
    header('Content-Type: application/json');
    $tmp   = __DIR__ . '/tmp/';
    $files = [];
    if (is_dir($tmp)) {
        foreach (glob($tmp . '*_white.jpg') as $fp) {
            $fn      = basename($fp);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'];
            $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web      = '/' . ltrim(str_replace($doc_root, '', rtrim($tmp,'/')), '/') . '/';
            $files[]  = ['filename' => $fn, 'url' => $protocol.'://'.$host.$web.$fn, 'time' => filemtime($fp)];
        }
    }
    // Sort newest first
    usort($files, function($a,$b){ return $b['time'] - $a['time']; });
    echo json_encode(['success'=>true,'files'=>$files]);
    exit;
}

// ── Handle get_models AJAX ────────────────────────────────
if (!empty($_POST['fashn_action']) && $_POST['fashn_action'] === 'get_models') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $base = __DIR__ . '/promo_models/';
    if (!is_dir($base)) {
        echo json_encode(['success'=>true,'models'=>[],'categories'=>[]]);
        exit;
    }

    $models = [];
    $series_map = []; // series_key => [files]
    // Scan ALL subdirectories
    foreach (glob($base . '*', GLOB_ONLYDIR) as $dir) {
        $cat = basename($dir);
        foreach (glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $fp) {
            $fn = basename($fp);
            // Parse: {series}_{pose_pN}_{view}.ext  OR  {series}_{pose_pN}.ext
            // Examples:
            //   female_casual_ch_c1_pose_p3_front.jpg  → series=female_casual_ch_c1, pose=p3, view=front
            //   female_casual_ea_pose_p2_back.jpg      → series=female_casual_ea, pose=p2, view=back
            //   female_casual_ea_01_front.jpg          → series=female_casual_ea, seq=01, view=front
            $base_name = '';
            $view      = '';
            $pose_n    = '';
            $name_no_ext = pathinfo($fn, PATHINFO_FILENAME);
            $view_keys = ['front','back','left_side','right_side','upper_half','lower_half'];
            // Try: ...pose_pN_viewkey
            if (preg_match('/^(.+?)_pose_p(\d+)_(' . implode('|',$view_keys) . ')$/', $name_no_ext, $m)) {
                $base_name = $m[1]; $pose_n = $m[2]; $view = $m[3];
            // Try: ...pose_pN (no view)
            } elseif (preg_match('/^(.+?)_pose_p(\d+)$/', $name_no_ext, $m)) {
                $base_name = $m[1]; $pose_n = $m[2]; $view = 'front';
            // Try: ..._NN_viewkey (seq number)
            } elseif (preg_match('/^(.+?)_(\d+)_(' . implode('|',$view_keys) . ')$/', $name_no_ext, $m)) {
                $base_name = $m[1]; $pose_n = $m[2]; $view = $m[3];
            } else {
                $base_name = $name_no_ext; $view = 'front';
            }
            $model = [
                'filename'  => $fn,
                'category'  => $cat,
                'url'       => 'promo_models/' . $cat . '/' . $fn,
                'base_name' => $base_name,
                'pose_n'    => $pose_n,
                'view'      => $view,
            ];
            $models[] = $model;
            $series_map[$cat . '/' . $base_name][] = $model;
        }
    }

    // Build series list (unique base_names per cat, with a cover image)
    $series = [];
    foreach ($series_map as $key => $files) {
        // prefer front view as cover
        $cover = null;
        foreach ($files as $f) { if ($f['view'] === 'front') { $cover = $f; break; } }
        if (!$cover) $cover = $files[0];
        list($scat, $sbase) = explode('/', $key, 2);
        $series[] = [
            'key'       => $key,
            'cat'       => $scat,
            'base_name' => $sbase,
            'label'     => ucwords(str_replace('_',' ', $sbase)),
            'cover_url' => $cover['url'],
            'file_count'=> count($files),
            'files'     => $files,
        ];
    }

    $cats = array_values(array_unique(array_column($models, 'category')));
    echo json_encode(['success'=>true,'models'=>$models,'series'=>$series,'categories'=>$cats]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Video &amp; Image Generator</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  :root {
    --white: #ffffff;
    --off-white: #f8f8f6;
    --surface: #f2f1ef;
    --border: #e4e3e0;
    --border-strong: #cccbc7;
    --text-primary: #1a1a18;
    --text-secondary: #6b6b67;
    --text-muted: #9e9d99;
    --accent: #1a1a18;
    --accent-hover: #3a3a36;
    --danger: #c0392b;
    --success: #1a7a4a;
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
  }

  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;
    background: var(--off-white);
    color: var(--text-primary);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }

  header {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .logo-mark {
    width: 32px; height: 32px;
    background: var(--text-primary);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .logo-mark svg { width: 18px; height: 18px; fill: white; }
  .brand { font-size: 15px; font-weight: 600; letter-spacing: -0.2px; }
  .brand span { color: var(--text-secondary); font-weight: 400; }
  header .model-badge {
    margin-left: auto;
    font-size: 11px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 4px 10px;
    border-radius: 20px;
    letter-spacing: 0.2px;
  }

  main {
    flex: 1;
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 28px;
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 32px 24px;
    align-items: start;
  }

  /* Mode tabs */
  .mode-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: 4px;
    gap: 2px;
  }
  .mode-tab {
    padding: 8px 4px;
    border: none;
    background: transparent;
    border-radius: calc(var(--radius-md) - 2px);
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    color: var(--text-secondary);
    display: flex; align-items: center; justify-content: center; gap: 5px;
    transition: all 0.15s;
    white-space: nowrap;
    font-family: inherit;
  }
  .mode-tab:hover { color: var(--text-primary); background: rgba(255,255,255,0.6); }
  .mode-tab.active {
    background: var(--white);
    color: var(--text-primary);
    font-weight: 600;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
  }
  .mode-tab svg { width: 13px; height: 13px; flex-shrink: 0; }

  .panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
  }
  .panel-header {
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
  }
  .panel-title {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--text-secondary);
  }
  .panel-body { padding: 20px; display: flex; flex-direction: column; gap: 18px; }

  .field { display: flex; flex-direction: column; gap: 7px; }
  label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  label .hint { font-weight: 400; color: var(--text-muted); font-size: 12px; }

  textarea, input[type="text"], input[type="password"], select {
    font-family: inherit;
    font-size: 14px;
    color: var(--text-primary);
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 10px 12px;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: 100%;
    outline: none;
    -webkit-appearance: none;
  }
  textarea:focus, input:focus, select:focus {
    border-color: var(--text-primary);
    box-shadow: 0 0 0 3px rgba(26,26,24,0.06);
  }
  textarea { resize: vertical; min-height: 100px; line-height: 1.6; }
  select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b6b67' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
  }

  .api-key-wrap { position: relative; }
  .api-key-wrap input { padding-right: 80px; font-family: monospace; font-size: 13px; }
  .api-key-toggle {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 12px; color: var(--text-secondary);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 3px 8px;
    cursor: pointer; transition: background 0.15s; font-family: inherit;
  }
  .api-key-toggle:hover { background: var(--border); }

  /* Upload zone */
  .upload-zone {
    border: 1.5px dashed var(--border-strong);
    border-radius: var(--radius-md);
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.15s;
    background: var(--off-white);
    text-align: center;
    position: relative;
  }
  .upload-zone:hover, .upload-zone.drag-over {
    border-color: var(--text-primary);
    background: var(--surface);
  }
  .upload-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .upload-zone svg { width: 26px; height: 26px; stroke: var(--text-muted); }
  .upload-zone .upload-label { font-size: 13px; font-weight: 500; color: var(--text-secondary); }
  .upload-zone .upload-sub { font-size: 11px; color: var(--text-muted); }

  /* Image preview */
  .image-preview-wrap {
    display: none;
    position: relative;
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--border);
    background: #0f0f0f;
  }
  .image-preview-wrap.visible { display: block; }
  .image-preview-wrap img { width: 100%; max-height: 180px; object-fit: contain; display: block; }
  .image-preview-actions {
    position: absolute; top: 8px; right: 8px;
    display: flex; gap: 6px;
  }
  .btn-icon {
    width: 28px; height: 28px;
    background: rgba(0,0,0,0.55);
    border: none; border-radius: var(--radius-sm);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background 0.15s; backdrop-filter: blur(4px);
  }
  .btn-icon:hover { background: rgba(0,0,0,0.8); }
  .btn-icon svg { width: 13px; height: 13px; stroke: white; }
  .image-preview-name {
    padding: 6px 10px; font-size: 11px; color: var(--text-muted);
    background: var(--surface); border-top: 1px solid var(--border);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }

  /* Aspect ratio */
  .ratio-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .ratio-btn {
    border: 1px solid var(--border); border-radius: var(--radius-md);
    background: var(--white); padding: 10px 8px; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    transition: all 0.15s; font-family: inherit;
  }
  .ratio-btn:hover { border-color: var(--border-strong); background: var(--off-white); }
  .ratio-btn.active { border-color: var(--text-primary); background: var(--text-primary); color: white; }
  .ratio-btn.active .ratio-label, .ratio-btn.active .ratio-dim { color: white; }
  .ratio-visual { border: 1.5px solid currentColor; border-radius: 3px; opacity: 0.5; }
  .ratio-btn.active .ratio-visual { opacity: 0.8; }
  .ratio-label { font-size: 12px; font-weight: 600; }
  .ratio-dim { font-size: 10px; color: var(--text-muted); }
  .rv-916 { width: 16px; height: 28px; }
  .rv-169 { width: 28px; height: 16px; }
  .rv-11  { width: 20px; height: 20px; }

  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  /* Strength slider */
  .slider-row { display: flex; align-items: center; gap: 10px; }
  .slider-row input[type="range"] {
    flex: 1; -webkit-appearance: none; height: 4px;
    background: var(--border); border-radius: 2px; outline: none;
    padding: 0; border: none; box-shadow: none;
  }
  .slider-row input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none; width: 16px; height: 16px;
    background: var(--text-primary); border-radius: 50%; cursor: pointer;
  }
  .slider-val { font-size: 13px; font-weight: 600; color: var(--text-primary); min-width: 28px; text-align: right; }

  /* Generate button */
  .btn-generate {
    width: 100%; padding: 13px;
    background: var(--text-primary); color: white;
    border: none; border-radius: var(--radius-md);
    font-size: 15px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.15s, transform 0.1s;
    letter-spacing: -0.2px; font-family: inherit;
  }
  .btn-generate:hover:not(:disabled) { background: var(--accent-hover); }
  .btn-generate:active:not(:disabled) { transform: scale(0.99); }
  .btn-generate:disabled { opacity: 0.45; cursor: not-allowed; }
  .btn-generate svg { width: 18px; height: 18px; }

  /* Output panel */
  .output-panel { display: flex; flex-direction: column; gap: 20px; }

  .status-card {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 20px;
    box-shadow: var(--shadow-sm); display: none;
  }
  .status-card.visible { display: block; }
  .status-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: var(--text-muted); }
  .status-dot.running { background: #f59e0b; animation: pulse 1.2s infinite; }
  .status-dot.success { background: var(--success); }
  .status-dot.error   { background: var(--danger); }
  @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
  .status-text { font-size: 14px; font-weight: 500; }
  .status-sub  { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
  .progress-bar-wrap { height: 3px; background: var(--surface); border-radius: 2px; overflow: hidden; }
  .progress-bar-fill { height: 100%; background: var(--text-primary); border-radius: 2px; transition: width 0.4s ease; width: 0%; }
  .progress-bar-fill.indeterminate { width: 40%; animation: indeterminate 1.4s ease-in-out infinite; }
  @keyframes indeterminate { 0% { transform: translateX(-100%); } 100% { transform: translateX(350%); } }

  .result-wrap {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius-lg); overflow: hidden;
    box-shadow: var(--shadow-sm); display: none;
  }
  .result-wrap.visible { display: block; }
  .result-header {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .result-title { font-size: 13px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
  .result-actions { display: flex; gap: 8px; }
  .btn-small {
    font-size: 12px; font-weight: 500; padding: 5px 12px;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--white); color: var(--text-primary);
    cursor: pointer; transition: background 0.15s; text-decoration: none;
    display: inline-flex; align-items: center; gap: 5px; font-family: inherit;
  }
  .btn-small:hover { background: var(--off-white); }
  .result-stage {
    background: #0f0f0f;
    display: flex; align-items: center; justify-content: center; padding: 24px;
    min-height: 300px;
  }
  .result-stage video, .result-stage img {
    max-width: 100%; max-height: 520px; width: auto; height: auto;
    border-radius: var(--radius-sm); display: block;
  }
  .result-meta {
    padding: 14px 18px; display: flex; gap: 20px; flex-wrap: wrap; border-top: 1px solid var(--border);
  }
  .meta-item { display: flex; flex-direction: column; gap: 2px; }
  .meta-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
  .meta-value { font-size: 13px; font-weight: 500; }

  .empty-state {
    background: var(--white); border: 1px dashed var(--border-strong);
    border-radius: var(--radius-lg); padding: 60px 32px;
    display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center;
  }
  .empty-icon {
    width: 52px; height: 52px; border: 1.5px solid var(--border-strong); border-radius: 50%;
    display: flex; align-items: center; justify-content: center; margin-bottom: 4px;
  }
  .empty-icon svg { width: 22px; height: 22px; stroke: var(--text-muted); }
  .empty-title { font-size: 15px; font-weight: 500; }
  .empty-sub { font-size: 13px; color: var(--text-secondary); max-width: 260px; line-height: 1.5; }

  .history-panel {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius-lg); overflow: hidden;
    box-shadow: var(--shadow-sm); display: none;
  }
  .history-panel.visible { display: block; }
  .history-header {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .history-title { font-size: 13px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
  .history-list { display: flex; flex-direction: column; }
  .history-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 18px; border-bottom: 1px solid var(--border);
    cursor: pointer; transition: background 0.12s;
  }
  .history-item:last-child { border-bottom: none; }
  .history-item:hover { background: var(--off-white); }
  .history-thumb {
    width: 44px; height: 44px; background: #0f0f0f; border-radius: 6px;
    overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  }
  .history-thumb video, .history-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .history-info { flex: 1; min-width: 0; }
  .history-prompt { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .history-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
  .history-badge {
    font-size: 10px; font-weight: 600; padding: 2px 7px;
    border-radius: 20px; border: 1px solid var(--border);
    color: var(--text-secondary); flex-shrink: 0;
    text-transform: uppercase; letter-spacing: 0.3px;
  }

  @media (max-width: 768px) {
    main { grid-template-columns: 1fr; padding: 16px; }
  }
  /* ── Model picker & Fashn ────────────────────────────── */
  .fashn-section-title {
    font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
    color:var(--text-secondary);margin:16px 0 8px;
  }
  .model-filter-chips { display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px; }
  .model-chip {
    padding:5px 12px;border:1.5px solid var(--border);border-radius:20px;
    background:var(--white);font-size:12px;font-weight:600;color:var(--text-secondary);
    cursor:pointer;transition:all .15s;font-family:inherit;
  }
  .model-chip:hover { border-color:var(--accent);color:var(--accent); }
  .model-chip.active { background:var(--accent);border-color:var(--accent);color:#fff; }
  .model-grid {
    display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));
    gap:10px;margin-bottom:14px;
  }
  .model-card {
    position:relative;border:2px solid var(--border);border-radius:var(--radius-md);
    overflow:hidden;cursor:pointer;transition:all .15s;background:var(--off-white);
  }
  .model-card:hover { border-color:var(--accent);transform:translateY(-2px); }
  .model-card.selected { border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15); }
  .model-card img {
    width:100%;aspect-ratio:2/3;object-fit:cover;object-position:center 10%;
    display:block;
  }
  .model-card-label {
    font-size:9px;color:var(--text-muted);padding:4px 5px;font-weight:600;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center;
  }
  .model-check {
    display:none;position:absolute;top:5px;right:5px;
    background:#10b981;color:#fff;border-radius:50%;
    width:18px;height:18px;font-size:10px;
    align-items:center;justify-content:center;font-weight:700;
  }
  .model-card.selected .model-check { display:flex; }
  .fashn-cat-btns { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px; }
  .fashn-cat-btn {
    padding:6px 14px;border:1.5px solid var(--border);border-radius:20px;
    background:var(--white);font-size:12px;font-weight:600;color:var(--text-secondary);
    cursor:pointer;transition:all .15s;font-family:inherit;
  }
  .fashn-cat-btn:hover { border-color:#8b5cf6;color:#8b5cf6; }
  .fashn-cat-btn.active { background:#8b5cf6;border-color:#8b5cf6;color:#fff; }
  .fashn-result-grid {
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:14px;
  }
  .fashn-img-box {
    border:1.5px solid var(--border);border-radius:var(--radius-md);overflow:hidden;
  }
  .fashn-img-box.highlight { border-color:#10b981; }
  .fashn-img-box img { width:100%;display:block;object-fit:contain;background:#f9fafb;max-height:320px; }
  .fashn-img-label {
    padding:6px 10px;font-size:11px;font-weight:600;color:var(--text-secondary);
    border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;
  }
  @keyframes spin { to { transform:rotate(360deg); } }
  .fashn-spinner {
    width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#8b5cf6;
    border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 10px;
  }

</style>
</head>
<body>

<header>
  <div class="logo-mark">
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M8 5v14l11-7z"/></svg>
  </div>
  <span class="brand">MediaGen <span>/ fal.ai</span></span>
  <span class="model-badge" id="headerBadge">text-to-video</span>
</header>

<main>
  <!-- Left: Controls -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Generation settings</div>
    </div>
    <div class="panel-body">

      <!-- Mode Tabs -->
      <div class="field">
        <div class="mode-tabs">
          <button class="mode-tab active" data-mode="t2v" onclick="switchMode('t2v', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Text&nbsp;&rarr;&nbsp;Video
          </button>
          <button class="mode-tab" data-mode="i2v" onclick="switchMode('i2v', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><polygon points="5 15 9 9 13 13 16 10 19 15 5 15"/></svg>
            Image&nbsp;&rarr;&nbsp;Video
          </button>
          <button class="mode-tab" data-mode="i2i" onclick="switchMode('i2i', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            Image&nbsp;&rarr;&nbsp;Image
          </button>
          <button class="mode-tab" data-mode="crop" onclick="switchMode('crop', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/></svg>
            Dress&nbsp;Crop
          </button>
          <button class="mode-tab" data-mode="fashn" onclick="switchMode('fashn', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.57a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.57a2 2 0 0 0-1.34-2.23z"/></svg>
            Fashn&nbsp;Try&#8209;On
          </button>
        </div>
      </div>

      <!-- API Key (hidden — injected from config.php) -->
      <div class="field" style="display:none">
        <label>fal.ai API key <span class="hint">— stored in session only</span></label>
        <div class="api-key-wrap">
          <input type="password" id="apiKey" placeholder="fal-••••••••••••••••••••••••••••••••" autocomplete="off" />
          <button class="api-key-toggle" onclick="toggleKey()">Show</button>
        </div>
      </div>

      <!-- Image upload (I2V + I2I) — multi-image -->
      <div class="field" id="imageUploadField" style="display:none">
        <label id="imageUploadLabel">Source image<span class="hint" id="imgCountBadge" style="margin-left:auto;display:none;"></span></label>

        <!-- Drop zone — shown until at least 1 image is added -->
        <div class="upload-zone" id="uploadZone"
             ondragover="handleDragOver(event)"
             ondragleave="handleDragLeave(event)"
             ondrop="handleDrop(event)">
          <input type="file" id="imageFile" accept="image/*" onchange="handleFileSelect(event)" />
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
          </svg>
          <span class="upload-label">Drop image or click to browse</span>
          <span class="upload-sub">PNG, JPG, WEBP &mdash; max 10 MB</span>
        </div>

        <!-- Uploaded image thumbnails list -->
        <div id="imageListWrap" style="display:none;margin-top:10px;">
          <div id="imageList" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;"></div>
          <!-- Action buttons after first upload -->
          <div id="imageUploadActions" style="display:flex;gap:8px;">
            <button type="button" id="btnUploadMore" onclick="triggerMoreUpload()"
              style="flex:1;padding:9px 14px;border-radius:8px;border:1.5px dashed var(--border-strong);background:transparent;color:var(--text-primary);font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:border-color .2s,background .2s;"
              onmouseover="this.style.borderColor='var(--accent)';this.style.background='rgba(99,102,241,.06)'"
              onmouseout="this.style.borderColor='var(--border)';this.style.background='transparent'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Upload More
            </button>
            <button type="button" id="btnImagesDone" onclick="finishImageUpload()"
              style="flex:1;padding:9px 14px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .2s;"
              onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:15px;height:15px;"><polyline points="20 6 9 17 4 12"/></svg>
              Done
            </button>
          </div>
          <!-- Locked summary shown after Done -->
          <div id="imagesDoneSummary" style="display:none;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
            <span id="imagesDoneSummaryText" style="font-size:13px;color:var(--text-secondary);flex:1;"></span>
            <button type="button" onclick="resetImageUpload()"
              style="font-size:12px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0;font-family:inherit;">✕ Reset</button>
          </div>
        </div>

        <!-- Hidden file input for Upload More -->
        <input type="file" id="imageFileMore" accept="image/*" style="display:none" onchange="handleMoreFileSelect(event)" />
      </div>

      <!-- Prompt -->
      <div class="field">
        <label id="promptLabel">Video prompt <span class="hint" id="promptCount" style="margin-left:auto;font-variant-numeric:tabular-nums;">0 / 2000</span></label>
        <textarea id="prompt" maxlength="2000" placeholder="A serene aerial shot of white sand beaches with turquoise waves, golden hour light…" oninput="updatePromptCount()"></textarea>
      </div>

      <!-- Aspect ratio (T2V + I2I) -->
      <div class="field" id="aspectField">
        <label>Aspect ratio</label>
        <div class="ratio-grid">
          <button class="ratio-btn active" data-ratio="9:16" onclick="selectRatio(this)">
            <div class="ratio-visual rv-916"></div>
            <span class="ratio-label">9:16</span>
            <span class="ratio-dim">1080&times;1920</span>
          </button>
          <button class="ratio-btn" data-ratio="16:9" onclick="selectRatio(this)">
            <div class="ratio-visual rv-169"></div>
            <span class="ratio-label">16:9</span>
            <span class="ratio-dim">1920&times;1080</span>
          </button>
          <button class="ratio-btn" data-ratio="1:1" onclick="selectRatio(this)">
            <div class="ratio-visual rv-11"></div>
            <span class="ratio-label">1:1</span>
            <span class="ratio-dim">1080&times;1080</span>
          </button>
        </div>
      </div>

      <!-- Strength (I2I only) -->
      <div class="field" id="strengthField" style="display:none">
        <label>Strength <span class="hint">— how much to transform the image</span></label>
        <div class="slider-row">
          <input type="range" id="strength" min="0" max="1" step="0.05" value="0.75"
                 oninput="document.getElementById('strengthVal').textContent=parseFloat(this.value).toFixed(2)" />
          <span class="slider-val" id="strengthVal">0.75</span>
        </div>
      </div>

      <!-- Duration + Model -->
      <div class="two-col" id="modelDurationField">
        <div class="field" id="durationField">
          <label>Duration</label>
          <select id="duration">
            <option value="5">5 seconds</option>
            <option value="6" selected>6 seconds</option>
            <option value="8">8 seconds</option>
            <option value="10">10 seconds</option>
          </select>
        </div>
        <div class="field">
          <label>Model</label>
          <select id="model" onchange="updateHeaderBadge(); updatePromptCount()">
            <optgroup label="Text to Video" id="t2vGroup">
              <option value="fal-ai/minimax/video-01">MiniMax v1</option>
              <option value="fal-ai/minimax/hailuo-02/standard/text-to-video">MiniMax Hailuo 02</option>
              <option value="fal-ai/kling-video/v1.6/standard/text-to-video">Kling v1.6</option>
              <option value="fal-ai/wan-t2v">Wan T2V</option>
            </optgroup>
            <optgroup label="Image to Video" id="i2vGroup" style="display:none">
              <option value="fal-ai/minimax/video-01/image-to-video">MiniMax v1 i2v</option>
              <option value="fal-ai/minimax/hailuo-02/standard/image-to-video">MiniMax Hailuo 02 i2v</option>
              <option value="fal-ai/kling-video/v1.6/standard/image-to-video">Kling v1.6 i2v</option>
              <option value="fal-ai/wan-i2v">Wan I2V</option>
            </optgroup>
            <optgroup label="Image to Image" id="i2iGroup" style="display:none">
              <option value="fal-ai/flux/dev/image-to-image">Flux Dev i2i</option>
              <option value="fal-ai/stable-diffusion-v3-medium/image-to-image">SD3 Medium i2i</option>
              <option value="fal-ai/aura-flow">AuraFlow</option>
            </optgroup>
          </select>
        </div>
      </div>

      <!-- Generate -->
      <button class="btn-generate" id="generateBtn" onclick="generate()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <span id="generateLabel">Generate video</span>
      </button>

      <!-- Dress Crop Panel -->
      <div id="cropPanel" style="display:none">
        <div class="field">
          <label>Upload dress photo</label>
          <div class="upload-zone" id="cropZone"
               ondragover="event.preventDefault();this.style.borderColor='#10b981'"
               ondragleave="this.style.borderColor=''"
               ondrop="event.preventDefault();this.style.borderColor='';cropHandleFile(event.dataTransfer.files[0])">
            <input type="file" id="cropFile" accept="image/jpeg,image/png,image/webp" onchange="cropHandleFile(this.files[0])" style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;color:#9ca3af;margin-bottom:8px"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
            <span class="upload-label">Drop dress photo or click to browse</span>
            <span class="upload-sub">JPG, PNG, WEBP — max 15MB</span>
          </div>
        </div>

        <div id="cropProgress" style="display:none;text-align:center;padding:24px">
          <div style="width:36px;height:36px;border:3px solid #e5e7eb;border-top-color:#10b981;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px"></div>
          <div id="cropProgressText" style="font-size:14px;color:#374151;font-weight:600">Analysing dress…</div>
          <div style="font-size:12px;color:#9ca3af;margin-top:4px">fal-ai/imageutils/rembg removing background</div>
        </div>

        <div id="cropResult" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
            <div style="border:1.5px solid #e5e7eb;border-radius:10px;overflow:hidden">
              <img id="cropOrigImg" src="" style="width:100%;display:block;max-height:400px;object-fit:contain;background:#f9fafb">
              <div style="padding:8px 12px;font-size:12px;font-weight:600;color:#6b7280;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center">
                Original
                <a id="cropOrigDl" href="#" download="dress_original.jpg" style="font-size:12px;color:#10b981;font-weight:700;text-decoration:none">⬇ Download</a>
              </div>
            </div>
            <div style="border:1.5px solid #10b981;border-radius:10px;overflow:hidden">
              <img id="cropResultImg" src="" style="width:100%;display:block;max-height:400px;object-fit:contain;background:#f9fafb">
              <div style="padding:8px 12px;font-size:12px;font-weight:600;color:#6b7280;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center">
                Cropped ✓
                <a id="cropResultDl" href="#" download="dress_cropped.jpg" style="font-size:12px;background:#10b981;color:#fff;padding:4px 10px;border-radius:6px;font-weight:700;text-decoration:none">⬇ Download</a>
              </div>
            </div>
          </div>
          <div id="cropInfoText" style="font-size:11px;color:#9ca3af;margin-top:8px"></div>
          <button onclick="cropReset()" style="margin-top:12px;padding:8px 16px;background:#f3f4f6;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">↩ Upload another</button>
        </div>

        <div id="cropError" style="display:none;background:#fef2f2;color:#dc2626;border-radius:8px;padding:12px 16px;font-size:14px;margin-top:12px"></div>
      </div>

      <!-- ══ Fashn Try-On Panel ══════════════════════════════ -->
      <div id="fashnPanel" style="display:none;padding:4px 0;">

        <!-- ① Upload Dress Images -->
        <div style="font-size:13px;font-weight:700;margin-bottom:8px;">① Upload Dress Photos <span style="font-size:11px;font-weight:400;color:#9ca3af;">(up to 6)</span></div>
        <div id="ft-upload-zone" style="border:2px dashed #d1d5db;border-radius:8px;padding:20px;text-align:center;cursor:pointer;position:relative;background:#fafafa;">
          <input type="file" id="ft-file-input" accept="image/*" onchange="ftHandleUpload(this.files[0])"
                 style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%">
          <div style="font-size:13px;color:#6b7280;">Click to upload dress photo</div>
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Background removed automatically &bull; Up to 6 images</div>
        </div>

        <!-- Uploaded dress grid -->
        <div id="ft-dress-upload-list" style="display:none;margin-top:10px;">
          <div id="ft-dress-thumbs" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;"></div>
          <div id="ft-dress-upload-actions" style="display:flex;gap:8px;">
            <button type="button" onclick="document.getElementById('ft-file-input').click()"
              id="ft-upload-more-btn"
              style="flex:1;padding:8px;border:1.5px dashed #d1d5db;border-radius:8px;background:transparent;color:#374151;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;">
              + Upload More
            </button>
            <button type="button" onclick="ftProcessDresses()"
              id="ft-dresses-done-btn"
              style="flex:1;padding:8px;border:none;background:#8b5cf6;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">
              ✓ Done &rarr; Analyze
            </button>
          </div>
        </div>

        <!-- Processing status -->
        <div id="ft-dress-proc-status" style="display:none;margin-top:8px;">
          <div id="ft-dress-proc-bar" style="background:#f3f4f6;border-radius:6px;height:6px;overflow:hidden;margin-bottom:6px;">
            <div id="ft-dress-proc-fill" style="height:100%;background:linear-gradient(90deg,#8b5cf6,#7c3aed);width:0%;transition:width .3s;"></div>
          </div>
          <div id="ft-dress-proc-msg" style="font-size:12px;color:#6b7280;"></div>
        </div>

        <!-- View map — shows after analysis -->
        <div id="ft-view-map" style="display:none;margin-top:10px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
          <div style="padding:8px 10px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:12px;font-weight:700;color:#374151;">Dress Views</div>
          <div id="ft-view-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:8px;"></div>
        </div>

        <!-- ② Select Model -->
        <div style="font-size:13px;font-weight:700;margin:16px 0 8px;">② Select Model</div>

        <!-- Selected model summary -->
        <div id="ft-model-selected" style="display:none;border:2px solid #10b981;border-radius:8px;overflow:hidden;margin-bottom:8px;">
          <div style="display:flex;align-items:center;gap:10px;padding:10px;">
            <img id="ft-model-thumb" src="" style="width:50px;height:68px;object-fit:cover;object-position:top;border-radius:6px;border:1px solid #e5e7eb;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
              <div id="ft-model-name" style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
              <div id="ft-model-cat" style="font-size:11px;color:#6b7280;margin-top:2px;"></div>
              <div style="font-size:11px;color:#10b981;font-weight:600;margin-top:2px;">✓ Selected</div>
            </div>
            <button onclick="ftShowModelPicker(true)" style="padding:6px 12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">Change</button>
          </div>
        </div>

        <!-- Inline model picker -->
        <div id="ft-model-picker" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
          <div style="padding:8px 10px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:8px;">
            <span style="font-size:12px;font-weight:700;color:#374151;flex:1;">Browse Models</span>
            <input id="ft-model-search" type="text" placeholder="Search…" oninput="ftFilterModels()"
              style="border:1px solid #e5e7eb;border-radius:6px;padding:4px 8px;font-size:12px;font-family:inherit;width:120px;">
          </div>
          <!-- Category tabs -->
          <div id="ft-model-cats" style="display:flex;gap:4px;flex-wrap:wrap;padding:6px 8px;border-bottom:1px solid #f3f4f6;background:#fafafa;"></div>
          <!-- Series grid -->
          <div id="ft-model-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:8px;max-height:280px;overflow-y:auto;">
            <div style="grid-column:1/-1;font-size:12px;color:#9ca3af;text-align:center;padding:20px;">Loading models…</div>
          </div>
        </div>

        <!-- Pose selector -->
        <div id="ft-pose-section" style="display:none;margin-top:16px;">
          <div style="font-size:13px;font-weight:700;margin-bottom:8px;">③ Select Poses</div>
          <div id="ft-pose-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:8px;"></div>
          <div style="display:flex;gap:8px;margin-bottom:4px;">
            <button onclick="ftSelectAllPoses(true)"  style="flex:1;padding:5px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">✓ All</button>
            <button onclick="ftSelectAllPoses(false)" style="flex:1;padding:5px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">✕ None</button>
          </div>
        </div>

        <!-- ④ Garment Type -->
        <div style="font-size:13px;font-weight:700;margin:14px 0 8px;">④ Garment Type</div>
        <div style="display:flex;gap:6px;margin-bottom:12px;">
          <button id="ftc-full"   onclick="ftCat('one-pieces',this)" style="flex:1;padding:7px 2px;border:2px solid #8b5cf6;border-radius:20px;background:#8b5cf6;color:#fff;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">👗 Full</button>
          <button id="ftc-top"    onclick="ftCat('tops',this)"       style="flex:1;padding:7px 2px;border:2px solid #e5e7eb;border-radius:20px;background:#fff;color:#374151;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">👕 Top</button>
          <button id="ftc-bottom" onclick="ftCat('bottoms',this)"    style="flex:1;padding:7px 2px;border:2px solid #e5e7eb;border-radius:20px;background:#fff;color:#374151;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">👖 Bottom</button>
        </div>

        <!-- ⑤ Background Theme -->
        <div style="font-size:13px;font-weight:700;margin:14px 0 8px;">⑤ Background Theme <span style="font-size:11px;font-weight:400;color:#9ca3af;">(optional)</span></div>
        <div id="ft-theme-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;max-height:320px;overflow-y:auto;">
          <div style="font-size:12px;color:#9ca3af;padding:8px;grid-column:1/-1;">Loading themes…</div>
        </div>
        <button onclick="ftClearTheme()" id="ft-theme-clear" style="display:none;width:100%;padding:6px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:10px;">✕ Clear selected themes</button>

        <button onclick="ftRun()" style="width:100%;padding:13px;background:linear-gradient(135deg,#8b5cf6,#7c3aed);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;">✨ Apply Try-On to All Views</button>

        <div id="ft-run-prog" style="display:none;font-size:12px;color:#6b7280;padding:10px 0;text-align:center;">⏳ Applying try-on…</div>
        <div id="ft-run-err"  style="display:none;color:#dc2626;font-size:12px;padding:6px 0;"></div>
        <div id="ft-result" style="display:none;margin-top:16px;">
          <div style="font-size:12px;font-weight:700;color:#10b981;margin-bottom:10px;">✅ Try-On Results</div>
          <div id="ft-result-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:12px;"></div>
        </div>

      </div><!-- /fashnPanel -->

    </div>
  </div>

  <!-- Right: Output -->
  <div class="output-panel">

    <div class="status-card" id="statusCard">
      <div class="status-row">
        <div class="status-dot" id="statusDot"></div>
        <div>
          <div class="status-text" id="statusText">Initialising&hellip;</div>
          <div class="status-sub" id="statusSub">Connecting to fal.ai</div>
        </div>
      </div>
      <div class="progress-bar-wrap">
        <div class="progress-bar-fill indeterminate" id="progressBar"></div>
      </div>
    </div>

    <div class="result-wrap" id="resultWrap">
      <div class="result-header">
        <span class="result-title" id="resultTitle">Result</span>
        <div class="result-actions">
          <a class="btn-small" id="downloadBtn" href="#" download="generated-output">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
          </a>
          <button class="btn-small" onclick="copyUrl()">Copy URL</button>
        </div>
      </div>
      <div class="result-stage" id="resultStage"></div>
      <div class="result-meta" id="resultMeta"></div>
    </div>

    <div class="empty-state" id="emptyState">
      <div class="empty-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
        </svg>
      </div>
      <div class="empty-title" id="emptyTitle">No output generated yet</div>
      <div class="empty-sub" id="emptySub">Enter a prompt, choose your settings, and hit Generate.</div>
    </div>

    <div class="history-panel" id="historyPanel">
      <div class="history-header">
        <span class="history-title">History</span>
        <button class="btn-small" onclick="clearHistory()">Clear</button>
      </div>
      <div class="history-list" id="historyList"></div>
    </div>

  </div>
</main>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
let selectedRatio = '9:16';
let currentMode = 't2v';
let uploadedImageBase64 = null;
let uploadedImageType = 'image/jpeg';
let uploadedFileName = '';
let uploadedImages = [];      // multi-image queue [{base64,mime,name,dataUrl}]
let imageUploadDone = false;
let currentResultUrl = '';
let history = JSON.parse(sessionStorage.getItem('vg_history') || '[]');



// ── Mode switching ─────────────────────────────────────────────────────────────
function switchMode(mode, tabEl) {
  currentMode = mode;
  document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
  tabEl.classList.add('active');

  const isCrop  = mode === 'crop';
  const isFashn = mode === 'fashn';
  const isSpecial = isCrop || isFashn;

  // Show or hide the prompt field
  const promptField = document.querySelector('.field:has(#prompt)');
  if (promptField) promptField.style.display = isSpecial ? 'none' : '';

  // Show/hide special panels
  document.getElementById('cropPanel').style.display  = isCrop  ? '' : 'none';
  document.getElementById('fashnPanel').style.display = isFashn ? 'block' : 'none';

  if (isFashn) {
    ['imageUploadField','aspectField','strengthField','durationField','modelDurationField','generateBtn'].forEach(function(id) {
      var el = document.getElementById(id); if (el) el.style.display = 'none';
    });
    ftInit();
  } else if (isCrop) {
    // Hide all generation-specific fields in crop mode
    ['imageUploadField','aspectField','strengthField','durationField','modelDurationField','generateBtn'].forEach(function(id) {
      var el = document.getElementById(id); if (el) el.style.display = 'none';
    });
  } else {
    // Restore generation fields based on the selected mode
    document.getElementById('generateBtn').style.display         = '';
    document.getElementById('modelDurationField').style.display   = '';
    document.getElementById('imageUploadField').style.display = (mode === 'i2v' || mode === 'i2i') ? '' : 'none';
    document.getElementById('strengthField').style.display    = mode === 'i2i' ? '' : 'none';
    document.getElementById('aspectField').style.display      = mode === 'i2i' ? 'none' : '';
    document.getElementById('durationField').style.display    = mode === 'i2i' ? 'none' : '';
    document.getElementById('t2vGroup').style.display  = mode === 't2v' ? '' : 'none';
    document.getElementById('i2vGroup').style.display  = mode === 'i2v' ? '' : 'none';
    document.getElementById('i2iGroup').style.display  = mode === 'i2i' ? '' : 'none';
    document.getElementById('imageUploadLabel').textContent = mode === 'i2i' ? 'Source image' : 'Source image (first frame)';
    const _pl = document.getElementById('promptLabel'); if(_pl) _pl.textContent = mode === 'i2i' ? 'Image prompt' : 'Video prompt';
    document.getElementById('generateLabel').textContent    = mode === 'i2i' ? 'Generate image' : 'Generate video';
    document.getElementById('emptyTitle').textContent       = mode === 'i2i' ? 'No image generated yet' : 'No video generated yet';
    updateHeaderBadge();
    updatePromptCount();
  }
}

// ── Dress Crop Functions ──────────────────────────────────
async function cropHandleFile(file) {
  if (!file) return;
  document.getElementById('cropZone').style.display    = 'none';
  document.getElementById('cropResult').style.display  = 'none';
  document.getElementById('cropError').style.display   = 'none';
  document.getElementById('cropProgress').style.display = '';
  document.getElementById('cropProgressText').textContent = 'Uploading…';

  const fd = new FormData();
  fd.append('dress', file);
  fd.append('crop_action', '1');

  try {
    document.getElementById('cropProgressText').textContent = 'Removing background…';
    const r = await fetch(window.location.pathname, { method:'POST', body:fd });
    const d = await r.json();
    document.getElementById('cropProgress').style.display = 'none';

    if (!d.success) {
      document.getElementById('cropZone').style.display = '';
      document.getElementById('cropError').textContent  = '⚠ ' + (d.error || 'Unknown error');
      document.getElementById('cropError').style.display = '';
      return;
    }

    document.getElementById('cropOrigImg').src    = d.original_url;
    document.getElementById('cropResultImg').src  = d.cropped_url + '?t=' + Date.now();
    document.getElementById('cropOrigDl').href    = d.original_url;
    document.getElementById('cropResultDl').href  = d.cropped_url;
    // Store for Fashn tab reuse
    ft.lastRembgFile = d.filename;
    ft.lastRembgUrl  = d.cropped_url;
    const dim = d.dimensions;
    document.getElementById('cropInfoText').textContent =
      `Background removed · White fill · Original: ${dim.w}×${dim.h}px`;
    document.getElementById('cropResult').style.display = '';
  } catch(e) {
    document.getElementById('cropProgress').style.display = 'none';
    document.getElementById('cropZone').style.display = '';
    document.getElementById('cropError').textContent  = '⚠ Error: ' + e.message;
    document.getElementById('cropError').style.display = '';
  }
}

function cropReset() {
  document.getElementById('cropZone').style.display   = '';
  document.getElementById('cropResult').style.display = 'none';
  document.getElementById('cropError').style.display  = 'none';
  document.getElementById('cropFile').value = '';
}
function updateHeaderBadge() {
  const mode = currentMode;
  const model = document.getElementById('model').value.split('/').pop();
  const modeLabel = { t2v: 'T2V', i2v: 'I2V', i2i: 'I2I' }[mode];
  document.getElementById('headerBadge').textContent = modeLabel + ' \u00B7 ' + model;
}

// ── Aspect ratio ──────────────────────────────────────────────────────────────
function selectRatio(btn) {
  document.querySelectorAll('.ratio-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  selectedRatio = btn.dataset.ratio;
}

// ── API key toggle ─────────────────────────────────────────────────────────────
function toggleKey() {
  const inp = document.getElementById('apiKey');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  event.target.textContent = inp.type === 'password' ? 'Show' : 'Hide';
}

// ── Image upload ───────────────────────────────────────────────────────────────
function handleDragOver(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.add('drag-over');
}
function handleDragLeave() {
  document.getElementById('uploadZone').classList.remove('drag-over');
}
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (file) processImageFile(file);
}
function handleFileSelect(e) {
  const file = e.target.files[0];
  if (file) processImageFile(file);
  e.target.value = '';
}
function handleMoreFileSelect(e) {
  const file = e.target.files[0];
  if (file) processImageFile(file);
  e.target.value = '';
}
function triggerMoreUpload() {
  document.getElementById('imageFileMore').click();
}
function processImageFile(file) {
  if (!file.type.startsWith('image/')) { alert('Please upload an image file.'); return; }
  if (file.size > 10 * 1024 * 1024) { alert('Image too large — max 10 MB.'); return; }
  const reader = new FileReader();
  reader.onload = (ev) => {
    const dataUrl = ev.target.result;
    const entry = { base64: dataUrl.split(',')[1], mime: file.type, name: file.name, dataUrl };
    uploadedImages.push(entry);
    // keep legacy single-image vars pointing at first image
    if (uploadedImages.length === 1) {
      uploadedImageBase64 = entry.base64;
      uploadedImageType   = entry.mime;
      uploadedFileName    = entry.name;
    }
    renderImageList();
    // hide drop zone, show list + buttons
    document.getElementById('uploadZone').style.display = 'none';
    document.getElementById('imageListWrap').style.display = 'block';
    document.getElementById('imageUploadActions').style.display = 'flex';
    document.getElementById('imagesDoneSummary').style.display = 'none';
    imageUploadDone = false;
  };
  reader.readAsDataURL(file);
}
function renderImageList() {
  const list = document.getElementById('imageList');
  list.innerHTML = '';
  uploadedImages.forEach((img, i) => {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;border:1.5px solid var(--border);flex-shrink:0;';
    const thumb = document.createElement('img');
    thumb.src = img.dataUrl;
    thumb.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
    thumb.title = img.name;
    const del = document.createElement('button');
    del.type = 'button';
    del.innerHTML = '×';
    del.title = 'Remove';
    del.style.cssText = 'position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;border:none;font-size:12px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;';
    del.onclick = () => removeImage(i);
    wrap.appendChild(thumb);
    wrap.appendChild(del);
    list.appendChild(wrap);
  });
  // update badge
  const badge = document.getElementById('imgCountBadge');
  badge.textContent = uploadedImages.length + ' image' + (uploadedImages.length !== 1 ? 's' : '');
  badge.style.display = uploadedImages.length ? '' : 'none';
}
function removeImage(i) {
  uploadedImages.splice(i, 1);
  if (uploadedImages.length === 0) {
    resetImageUpload();
  } else {
    // update legacy vars to first remaining
    uploadedImageBase64 = uploadedImages[0].base64;
    uploadedImageType   = uploadedImages[0].mime;
    uploadedFileName    = uploadedImages[0].name;
    renderImageList();
  }
}
function finishImageUpload() {
  if (uploadedImages.length === 0) return;
  imageUploadDone = true;
  document.getElementById('imageUploadActions').style.display = 'none';
  const summary = document.getElementById('imagesDoneSummary');
  summary.style.display = 'flex';
  const n = uploadedImages.length;
  const names = uploadedImages.map(x => x.name).join(', ');
  document.getElementById('imagesDoneSummaryText').textContent =
    n + ' image' + (n !== 1 ? 's' : '') + ' ready — ' + names;
}
function resetImageUpload() {
  uploadedImages = [];
  uploadedImageBase64 = null;
  uploadedImageType = 'image/jpeg';
  uploadedFileName = '';
  imageUploadDone = false;
  document.getElementById('imageListWrap').style.display = 'none';
  document.getElementById('imageList').innerHTML = '';
  document.getElementById('imagesDoneSummary').style.display = 'none';
  document.getElementById('imageUploadActions').style.display = 'flex';
  document.getElementById('uploadZone').style.display = '';
  document.getElementById('imgCountBadge').style.display = 'none';
  document.getElementById('imageFile').value = '';
}
// legacy alias
function clearImage() { resetImageUpload(); }

// ── Status ─────────────────────────────────────────────────────────────────────
function setStatus(state, text, sub) {
  const card = document.getElementById('statusCard');
  const dot  = document.getElementById('statusDot');
  const bar  = document.getElementById('progressBar');
  card.classList.add('visible');
  dot.className = 'status-dot ' + state;
  document.getElementById('statusText').textContent = text;
  document.getElementById('statusSub').textContent = sub || '';
  if (state === 'running') {
    bar.classList.add('indeterminate'); bar.style.width = '';
  } else {
    bar.classList.remove('indeterminate');
    bar.style.width = state === 'success' ? '100%' : '0%';
  }
}

// ── Show result ────────────────────────────────────────────────────────────────
function showResult(url, meta, isImage) {
  currentResultUrl = url;
  const wrap = document.getElementById('resultWrap');
  const stage = document.getElementById('resultStage');
  const dl = document.getElementById('downloadBtn');
  document.getElementById('resultTitle').textContent = isImage ? 'Result \u2014 Image' : 'Result \u2014 Video';
  dl.download = isImage ? 'generated-image.png' : 'generated-video.mp4';
  dl.href = url;
  stage.innerHTML = isImage
    ? `<img src="${escHtml(url)}" alt="Generated image" />`
    : `<video src="${escHtml(url)}" controls playsinline loop></video>`;
  wrap.classList.add('visible');
  document.getElementById('emptyState').style.display = 'none';
  document.getElementById('resultMeta').innerHTML = Object.entries(meta).map(([k, v]) =>
    `<div class="meta-item"><span class="meta-label">${escHtml(k)}</span><span class="meta-value">${escHtml(String(v))}</span></div>`
  ).join('');
}

function copyUrl() {
  if (!currentResultUrl) return;
  navigator.clipboard.writeText(currentResultUrl).then(() => {
    const btn = event.target;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy URL', 1800);
  });
}

// ── History ────────────────────────────────────────────────────────────────────
function addToHistory(prompt, url, label, mode) {
  const item = { prompt, url, label, mode, time: new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) };
  history.unshift(item);
  if (history.length > 10) history.pop();
  sessionStorage.setItem('vg_history', JSON.stringify(history));
  renderHistory();
}
function renderHistory() {
  const panel = document.getElementById('historyPanel');
  const list  = document.getElementById('historyList');
  if (!history.length) { panel.classList.remove('visible'); return; }
  panel.classList.add('visible');
  list.innerHTML = history.map((h, i) => {
    const isImg = h.mode === 'i2i';
    const thumb = isImg ? `<img src="${h.url}" />` : `<video src="${h.url}" muted></video>`;
    const badge = { t2v:'T2V', i2v:'I2V', i2i:'I2I' }[h.mode] || '';
    return `<div class="history-item" onclick="loadFromHistory(${i})">
      <div class="history-thumb">${thumb}</div>
      <div class="history-info">
        <div class="history-prompt">${escHtml(h.prompt)}</div>
        <div class="history-time">${escHtml(h.label)} \u00B7 ${h.time}</div>
      </div>
      <span class="history-badge">${badge}</span>
    </div>`;
  }).join('');
}
function loadFromHistory(i) {
  const h = history[i];
  showResult(h.url, { Label: h.label, Generated: h.time }, h.mode === 'i2i');
}
function clearHistory() {
  history = [];
  sessionStorage.removeItem('vg_history');
  renderHistory();
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function getPromptCap() {
  const model = document.getElementById('model').value;
  if (model.includes('minimax') && currentMode === 'i2v') return 500;
  if (model.includes('minimax')) return 2000;
  if (model.includes('kling'))   return 500;
  if (model.includes('wan'))     return 500;
  return 500;
}
function updatePromptCount() {
  const ta = document.getElementById('prompt');
  const el = document.getElementById('promptCount');
  if (!ta || !el) return;
  const count = ta.value.length;
  const cap = getPromptCap();
  el.textContent = count + ' / ' + cap;
  el.style.color = count > cap ? 'var(--danger)' : count > cap * 0.85 ? '#f59e0b' : 'var(--text-muted)';
}

// ── Upload image via PHP proxy (server-side, avoids DNS issues) ──────────────
async function uploadImageToFal(apiKey) {
  if (!uploadedImageBase64) throw new Error('No image uploaded');
  const res = await fetch('fal_proxy.php?action=upload', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      base64:    uploadedImageBase64,
      mime_type: uploadedImageType,
      file_name: uploadedFileName || 'source.jpg',
    }),
  });
  const data = await res.json();
  if (!res.ok || !data.file_url) {
    throw new Error('Image upload failed: ' + (data.error || JSON.stringify(data)));
  }
  return data.file_url;
}

// ── Core generation ────────────────────────────────────────────────────────────
async function generate() {
  // Guard against null elements when in fashn/crop mode
  if (currentMode === 'fashn' || currentMode === 'crop') return;
  const apiKey = document.getElementById('apiKey').value.trim() || "<?= htmlspecialchars($falApiKey) ?>";
  const rawPrompt = document.getElementById('prompt').value.trim();
  const model  = document.getElementById('model').value;
  // Per-model prompt length caps (fal.ai enforces these server-side)
  // MiniMax i2v is capped at 500; t2v allows 2000
  const promptCap = (model.includes('minimax') && currentMode === 'i2v') ? 500
                  : model.includes('minimax') ? 2000
                  : model.includes('kling')   ? 500
                  : model.includes('wan')     ? 500
                  : 500;
  const prompt = rawPrompt.slice(0, promptCap);
  if (rawPrompt.length > promptCap) {
    console.warn('[fal] prompt trimmed from', rawPrompt.length, 'to', promptCap, 'chars');
  }
  const durEl  = document.getElementById('duration');
  const dur    = durEl ? durEl.value : '5';
  const btn    = document.getElementById('generateBtn');

  if (!apiKey) { alert('Please enter your fal.ai API key.'); return; }
  if (!prompt) { alert('Please enter a prompt.'); return; }
  if (currentMode !== 't2v' && !uploadedImageBase64) { alert('Please upload a source image.'); return; }

  if (btn) btn.disabled = true;
  document.getElementById('resultWrap').classList.remove('visible');
  document.getElementById('emptyState').style.display = 'none';
  setStatus('running', 'Preparing\u2026', 'Getting ready');

  try {
    let imageUrl = null;
    if (currentMode !== 't2v') {
      setStatus('running', 'Uploading image\u2026', 'Sending to fal.ai storage');
      imageUrl = await uploadImageToFal(apiKey);
    }

    // Build payload per mode + model
    let payload = {};
    if (currentMode === 't2v') {
      if (model.includes('minimax'))    payload = { prompt, aspect_ratio: selectedRatio };
      else if (model.includes('kling')) payload = { prompt, aspect_ratio: selectedRatio, duration: parseInt(dur) };
      else                              payload = { prompt, aspect_ratio: selectedRatio };

    } else if (currentMode === 'i2v') {
      // MiniMax i2v: aspect_ratio inferred from source image, no duration param
      if (model.includes('minimax')) {
        payload = { prompt, image_url: imageUrl };
      // Kling i2v: accepts duration 5 or 10 only
      } else if (model.includes('kling')) {
        const klingDur = parseInt(dur) >= 8 ? 10 : 5;
        payload = { prompt, image_url: imageUrl, duration: klingDur };
      // Wan i2v: aspect_ratio inferred from source image
      } else if (model.includes('wan')) {
        payload = { prompt, image_url: imageUrl };
      } else {
        payload = { prompt, image_url: imageUrl, duration: parseInt(dur) };
      }

    } else { // i2i
      const strength = parseFloat(document.getElementById('strength').value);
      const sizeMap = { '16:9': 'landscape_hd', '9:16': 'portrait_hd', '1:1': 'square_hd' };
      payload = { prompt, image_url: imageUrl, strength, image_size: sizeMap[selectedRatio] || 'square_hd' };
    }

    console.log('[fal] mode:', currentMode, '| model:', model, '| payload:', JSON.stringify(payload));

    // Verify image URL is publicly reachable before submitting
    if (imageUrl) {
      console.log('[fal] image_url being sent:', imageUrl);
      try {
        const testRes = await fetch(imageUrl, { method: 'HEAD' });
        console.log('[fal] image URL reachable:', testRes.ok, 'status:', testRes.status);
        if (!testRes.ok) throw new Error('Image URL returned HTTP ' + testRes.status + ' — fal.ai cannot fetch it');
      } catch (headErr) {
        // HEAD may be blocked by CORS — log but don't block
        console.warn('[fal] HEAD check failed (may be CORS, not a problem):', headErr.message);
      }
    }

    setStatus('running', 'Queuing generation\u2026', 'Submitting to fal.ai');

    // Submit via proxy (avoids CORS)
    const submitRes = await fetch(`fal_proxy.php?path=${model}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    console.log('[fal] submitted payload:', JSON.stringify(payload));
    if (!submitRes.ok) {
      const err = await submitRes.json().catch(() => ({}));
      throw new Error(err.detail || err.message || `HTTP ${submitRes.status}`);
    }

    const job = await submitRes.json();
    console.log('[fal] submit response:', JSON.stringify(job));
    const requestId = job.request_id;
    if (!requestId) throw new Error('No request_id from fal.ai: ' + JSON.stringify(job).slice(0,200));
    setStatus('running', 'Job queued', `ID: ${requestId.slice(0, 16)}…`);

    // Poll — use simple clean paths, proxy builds the full URL
    let attempts = 0;
    while (attempts < 180) {
      await sleep(3000);
      attempts++;
      const statusRes = await fetch('fal_proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'status', model, rid: requestId }),
      });
      if (!statusRes.ok) {
        const errText = await statusRes.text().catch(() => '');
        console.warn('[fal] poll failed HTTP', statusRes.status, errText);
        continue;
      }
      const sd = await statusRes.json();
      console.log('[fal] poll status:', sd.status, sd);
      const st = (sd.status || '').toLowerCase();

      if (st === 'in_queue') {
        const pos = sd.queue_position;
        setStatus('running', 'In queue…', pos != null ? `Position: ${pos}` : 'Waiting for worker');
      } else if (st === 'in_progress') {
        const logs = sd.logs;
        const lastLog = logs && logs.length ? logs[logs.length - 1].message : 'Processing…';
        setStatus('running', currentMode === 'i2i' ? 'Generating image…' : 'Generating video…', lastLog);
      } else if (st === 'completed') {
        break;
      } else if (st === 'failed') {
        throw new Error(sd.error || 'Generation failed on fal.ai');
      }
    }
    if (attempts >= 180) throw new Error('Timed out waiting for generation');

    // Fetch result
    setStatus('running', 'Fetching result…', 'Downloading output URL');
    const resultRes = await fetch('fal_proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'result', model, rid: requestId }),
    });
    if (!resultRes.ok) throw new Error(`Result fetch failed: HTTP ${resultRes.status}`);
    const result = await resultRes.json();

    const isImage = currentMode === 'i2i';
    let outputUrl;
    if (isImage) {
      outputUrl = result?.image?.url || result?.images?.[0]?.url
               || result?.output?.image?.url || result?.output?.images?.[0]?.url;
    } else {
      outputUrl = result?.video?.url || result?.video_url
               || result?.output?.video?.url || result?.output?.video_url
               || result?.videos?.[0]?.url;
    }
    if (!outputUrl) throw new Error('No output URL in response: ' + JSON.stringify(result).slice(0, 200));

    const modeLabel = { t2v: 'Text\u2192Video', i2v: 'Image\u2192Video', i2i: 'Image\u2192Image' }[currentMode];
    const label = isImage ? selectedRatio : `${selectedRatio} \u00B7 ${dur}s`;

    setStatus('success', isImage ? 'Image ready' : 'Video ready', 'Generation complete');
    showResult(outputUrl, {
      Mode: modeLabel,
      ...(isImage ? {} : { Duration: `${dur}s` }),
      ...(currentMode !== 'i2v' ? { Ratio: selectedRatio } : {}),
      Model: model.split('/').pop(),
      Generated: new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}),
    }, isImage);
    addToHistory(prompt, outputUrl, label, currentMode);

  } catch (err) {
    setStatus('error', 'Generation failed', err.message);
    document.getElementById('emptyState').style.display = '';
    console.error(err);
  } finally {
    btn.disabled = false;
  }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }



// ══ Fashn Try-On ══════════════════════════════════════════
var ft = {
  // dress views: {front,back,left_side,right_side,upper_half,lower_half} => {filename,url,generated}
  dressViews: {},
  uploadQueue: [],   // raw File objects pending upload
  uploadSeq: 0,      // how many uploaded so far this session
  modelFile: '', modelCat: '', modelUrl: '', modelBaseName: '', modelLabel: '',
  modelFiles: [],   // all pose files for selected series [{filename,view,pose_n,url}]
  garment: 'one-pieces',
  themes: []   // array of selected theme objects (multi-select, toggle)
};

var ALL_VIEWS = ['front','back','left_side','right_side','upper_half','lower_half'];
var VIEW_LABELS = {
  front:'Front', back:'Back', left_side:'Left Side', right_side:'Right Side',
  upper_half:'Neck→Waist', lower_half:'Lower'
};

// (model selection now handled inline via ftSelectModel)

// ── Upload handler ──────────────────────────────────────────────────────
function ftHandleUpload(file) {
  if (!file) return;
  if (!file.type.startsWith('image/')) { alert('Please upload an image file.'); return; }
  if (file.size > 15 * 1024 * 1024) { alert('File too large — max 15 MB.'); return; }
  if (ft.uploadSeq >= 6) { alert('Maximum 6 images already uploaded.'); return; }

  // Show a placeholder thumb immediately
  ft.uploadSeq++;
  var seq = ft.uploadSeq;
  var reader = new FileReader();
  reader.onload = function(ev) {
    ftAddThumbPlaceholder(seq, ev.target.result, file.name);
  };
  reader.readAsDataURL(file);

  // Now upload via FormData
  ftUploadOne(file, seq);
  document.getElementById('ft-upload-zone').style.display    = 'none';
  document.getElementById('ft-dress-upload-list').style.display = 'block';
  // reset file input so same file can be re-selected
  document.getElementById('ft-file-input').value = '';
}

function ftAddThumbPlaceholder(seq, dataUrl, name) {
  var list = document.getElementById('ft-dress-thumbs');
  var wrap = document.createElement('div');
  wrap.id  = 'ft-thumb-' + seq;
  wrap.style.cssText = 'position:relative;width:72px;flex-shrink:0;border-radius:8px;overflow:hidden;border:2px solid #e5e7eb;';
  wrap.innerHTML =
    '<img src="' + dataUrl + '" style="width:100%;height:96px;object-fit:cover;display:block;">'
    + '<button onclick="ftRemoveThumb(' + seq + ')" title="Remove" '
    + 'style="position:absolute;top:3px;right:3px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;border:none;font-size:11px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;font-weight:700;">✕</button>'
    + '<div id="ft-thumb-status-' + seq + '" style="font-size:9px;text-align:center;padding:2px 3px;background:#f3f4f6;color:#6b7280;">Uploading…</div>'
    + '<div id="ft-thumb-view-' + seq + '" style="font-size:9px;text-align:center;padding:2px 3px;background:#8b5cf6;color:#fff;display:none;"></div>';
  list.appendChild(wrap);
}

function ftRemoveThumb(seq) {
  var wrap = document.getElementById('ft-thumb-' + seq);
  if (wrap) wrap.remove();
  // Remove from uploadQueue
  delete ft.uploadQueue[seq];
  // If no thumbs left, show drop zone again
  var thumbs = document.getElementById('ft-dress-thumbs');
  if (thumbs && thumbs.children.length === 0) {
    document.getElementById('ft-dress-upload-list').style.display = 'none';
    document.getElementById('ft-upload-zone').style.display = 'block';
    ft.uploadSeq = 0;
  }
}

async function ftUploadOne(file, seq) {
  var fd = new FormData();
  fd.append('fashn_action', 'upload_dress_image');
  fd.append('dress_image', file);
  fd.append('seq', seq);
  try {
    var r  = await fetch(window.location.pathname, { method:'POST', body:fd });
    var d  = await r.json();
    if (!d.success) throw new Error(d.error || 'Upload failed');

    // Mark thumb as rembg done
    var statusEl = document.getElementById('ft-thumb-status-' + seq);
    if (statusEl) { statusEl.textContent = 'BG removed'; statusEl.style.color = '#10b981'; }
    var thumbWrap = document.getElementById('ft-thumb-' + seq);
    if (thumbWrap) thumbWrap.style.borderColor = '#10b981';

    // Store in queue for analysis when Done is clicked
    ft.uploadQueue[seq] = { filename: d.filename, url: d.url, seq: seq };
  } catch(err) {
    var statusEl2 = document.getElementById('ft-thumb-status-' + seq);
    if (statusEl2) { statusEl2.textContent = '⚠ Error'; statusEl2.style.color = '#dc2626'; }
    console.error('Upload error seq=' + seq, err);
  }
}

// ── Process: analyze views + generate missing ─────────────────────────────
async function ftProcessDresses() {
  // Check at least one upload finished
  var ready = ft.uploadQueue.filter(Boolean);
  if (ready.length === 0) { alert('Please wait for uploads to finish.'); return; }

  // Hide action buttons, show progress
  document.getElementById('ft-dress-upload-actions').style.display = 'none';
  var procStatus = document.getElementById('ft-dress-proc-status');
  var procMsg    = document.getElementById('ft-dress-proc-msg');
  var procFill   = document.getElementById('ft-dress-proc-fill');
  procStatus.style.display = 'block';

  // Step 1: Analyze each uploaded image
  procMsg.textContent = 'Analyzing view angles…';
  procFill.style.width = '10%';

  ft.dressViews = {};
  for (var i = 0; i < ready.length; i++) {
    var item = ready[i];
    procMsg.textContent = 'Analyzing image ' + (i+1) + ' of ' + ready.length + '…';
    try {
      var fd = new FormData();
      fd.append('fashn_action', 'analyze_dress_view');
      fd.append('image_url', item.url);
      var r  = await fetch(window.location.pathname, { method:'POST', body:fd });
      var d  = await r.json();
      var view = d.view || 'front';
      ft.dressViews[view] = { filename: item.filename, url: item.url, generated: false };
      // Update thumb label
      var viewEl = document.getElementById('ft-thumb-view-' + item.seq);
      if (viewEl) { viewEl.textContent = VIEW_LABELS[view] || view; viewEl.style.display = 'block'; }
    } catch(e) {
      console.error('Analyze error:', e);
      // default to front if analysis fails
      if (!ft.dressViews['front']) {
        ft.dressViews['front'] = { filename: item.filename, url: item.url, generated: false };
      }
    }
    procFill.style.width = Math.round(10 + (i+1)/ready.length * 30) + '%';
  }

  // Step 2: Determine missing views (need at least front + back + left + right + upper + lower = 6)
  var missingViews = ALL_VIEWS.filter(v => !ft.dressViews[v]);
  var frontItem = ft.dressViews['front'] || ft.dressViews[ALL_VIEWS.find(v => ft.dressViews[v])];

  if (missingViews.length > 0 && frontItem) {
    procMsg.textContent = 'Generating ' + missingViews.length + ' missing view(s) with AI…';
    for (var mi = 0; mi < missingViews.length; mi++) {
      var mv = missingViews[mi];
      procMsg.textContent = 'Generating ' + VIEW_LABELS[mv] + ' view… (' + (mi+1) + '/' + missingViews.length + ')';
      procFill.style.width = Math.round(40 + (mi+1)/missingViews.length * 50) + '%';
      try {
        var gfd = new FormData();
        gfd.append('fashn_action', 'generate_dress_view');
        gfd.append('front_url', frontItem.url);
        gfd.append('target_view', mv);
        gfd.append('seq', mi+1);
        var gr  = await fetch(window.location.pathname, { method:'POST', body:gfd });
        var gd  = await gr.json();
        if (gd.success) {
          ft.dressViews[mv] = { filename: gd.filename, url: gd.url, generated: true };
        }
      } catch(ge) { console.error('Generate view error:', mv, ge); }
    }
  }

  procFill.style.width = '100%';
  procMsg.textContent = '✅ All ' + Object.keys(ft.dressViews).length + ' views ready!';
  setTimeout(function() { procStatus.style.display = 'none'; }, 1500);

  // Step 3: Render view map
  ftRenderViewMap();
}

function ftRenderViewMap() {
  var grid = document.getElementById('ft-view-grid');
  grid.innerHTML = '';
  ALL_VIEWS.forEach(function(view) {
    var item = ft.dressViews[view];
    var cell = document.createElement('div');
    cell.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;';
    if (item) {
      var badge = item.generated
        ? '<div style="position:absolute;top:4px;left:4px;background:#8b5cf6;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:4px;">AI</div>'
        : '<div style="position:absolute;top:4px;left:4px;background:#10b981;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:4px;">✓</div>';
      cell.innerHTML =
        '<div style="position:relative;">'
        + '<img src="' + item.url + '" style="width:100%;height:110px;object-fit:contain;display:block;background:#f9fafb;">'
        + badge
        + '</div>'
        + '<div style="padding:3px 4px;font-size:10px;font-weight:700;color:' + (item.generated ? '#8b5cf6' : '#10b981') + ';text-align:center;border-top:1px solid #f3f4f6;">'
        + VIEW_LABELS[view] + '</div>';
    } else {
      cell.innerHTML =
        '<div style="height:110px;display:flex;align-items:center;justify-content:center;background:#fafafa;font-size:22px;">?</div>'
        + '<div style="padding:3px 4px;font-size:10px;color:#d1d5db;text-align:center;border-top:1px solid #f3f4f6;">' + VIEW_LABELS[view] + '</div>';
    }
    grid.appendChild(cell);
  });
  document.getElementById('ft-view-map').style.display = 'block';
}


// ── Theme picker ─────────────────────────────────────────────────────────
function ftLoadThemes() {
  var themes = [
    { theme_key:'mughal_courtyard', theme_name:'Mughal Courtyard',  description:'Classic Mughal architecture, stone arches, ornate gardens',      preview_image:'', location_prompt:'Grand Mughal courtyard with carved stone arches, flowing water channel, lush manicured gardens with marigold borders, warm golden afternoon light casting long shadows through ornate jali screens' },
    { theme_key:'luxury_hotel',     theme_name:'Luxury Hotel',       description:'5-star hotel interiors — marble lobby, chandeliers',              preview_image:'', location_prompt:'Opulent 5-star hotel interior with white marble floors, towering crystal chandeliers, grand curving staircase with gold banister, fresh white floral arrangements, soft warm ambient lighting' },
    { theme_key:'royal_palace',     theme_name:'Royal Palace',       description:'Palatial settings with gold pillars, royal draping',              preview_image:'', location_prompt:'Magnificent royal palace hall with soaring gold-leaf pillars, deep red velvet draping, intricate ceiling murals, Persian rugs, warm candlelight ambiance evoking old-world royalty' },
    { theme_key:'garden_wedding',   theme_name:'Garden Wedding',     description:'Lush outdoor garden with florals, fairy lights',                  preview_image:'', location_prompt:'Romantic outdoor garden setting with manicured hedgerows, overflowing floral arrangements in blush and white, soft fairy lights strung above, dappled natural sunlight through mature trees' },
    { theme_key:'haveli_interior',  theme_name:'Haveli Interior',    description:'Traditional South Asian haveli with carved wood, jharokhas',      preview_image:'', location_prompt:'Traditional haveli interior with ornately carved wooden jharokha windows, warm lantern light, terracotta floor tiles, embroidered cushions on low seating, peacock motif screens casting patterned shadows' },
    { theme_key:'desert_dunes',     theme_name:'Desert Dunes',       description:'Golden sand dunes at sunset with dramatic sky',                   preview_image:'', location_prompt:'Vast golden desert dunes at magic hour, warm amber sun low on the horizon, soft wind lifting fine sand, dramatic cloudless sky transitioning from orange to deep purple, ethereal solitary mood' },
    { theme_key:'modern_studio',    theme_name:'Modern Studio',      description:'Clean editorial studio with dramatic lighting',                   preview_image:'', location_prompt:'High-end fashion photography studio with seamless white-to-grey gradient backdrop, dramatic split lighting, polished concrete floor with subtle reflections, sleek minimalist editorial atmosphere' },
  ];
  ftRenderThemes(themes);
}

// _ftThemeList holds the full loaded list so toggle/clear can re-render without refetch
var _ftThemeList = [];

function ftRenderThemes(themes) {
  _ftThemeList = themes;
  var grid = document.getElementById('ft-theme-grid');
  grid.innerHTML = '';
  themes.forEach(function(t) {
    var isSel = ft.themes.some(function(s) { return s.theme_key === t.theme_key; });
    var card  = document.createElement('div');
    card.dataset.key = t.theme_key;
    card.style.cssText = 'border:2px solid ' + (isSel ? '#10b981' : '#e5e7eb') + ';border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .15s,box-shadow .15s;position:relative;user-select:none;';
    if (isSel) {
      var chk = document.createElement('div');
      chk.textContent = '✓';
      chk.style.cssText = 'position:absolute;top:4px;right:5px;background:#10b981;color:#fff;width:16px;height:16px;border-radius:50%;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;z-index:1;';
      card.appendChild(chk);
    }
    var top = document.createElement('div');
    top.style.cssText = 'background:linear-gradient(135deg,#0f2a44,#143b63);height:50px;display:flex;align-items:center;justify-content:center;';
    top.innerHTML = '<span style="font-size:18px;">🏛️</span>';
    var info = document.createElement('div');
    info.style.cssText = 'padding:5px 6px;';
    info.innerHTML = '<div style="font-size:11px;font-weight:700;color:#0f2a44;">' + t.theme_name + '</div>'
      + '<div style="font-size:9px;color:#6b7280;margin-top:1px;line-height:1.3;">' + (t.description || '') + '</div>';
    card.appendChild(top);
    card.appendChild(info);
    grid.appendChild(card);
    card.onclick = function() {
      var key = this.dataset.key;
      var idx = ft.themes.findIndex(function(s) { return s.theme_key === key; });
      if (idx === -1) {
        ft.themes.push(t);   // select
      } else {
        ft.themes.splice(idx, 1);  // deselect
      }
      // Update this card visually without re-rendering the whole grid
      var nowSel = ft.themes.some(function(s) { return s.theme_key === key; });
      this.style.borderColor = nowSel ? '#10b981' : '#e5e7eb';
      // update/remove checkmark
      var existing = this.querySelector('.ft-chk');
      if (nowSel && !existing) {
        var c = document.createElement('div');
        c.className = 'ft-chk';
        c.textContent = '✓';
        c.style.cssText = 'position:absolute;top:4px;right:5px;background:#10b981;color:#fff;width:16px;height:16px;border-radius:50%;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;z-index:1;';
        this.insertBefore(c, this.firstChild);
      } else if (!nowSel && existing) {
        existing.remove();
      }
      // show/hide clear button
      document.getElementById('ft-theme-clear').style.display = ft.themes.length ? '' : 'none';
      // update clear button label
      ftUpdateThemeClearLabel();
    };
  });
}

function ftUpdateThemeClearLabel() {
  var btn = document.getElementById('ft-theme-clear');
  if (!btn) return;
  if (ft.themes.length === 0) {
    btn.style.display = 'none';
  } else {
    btn.style.display = '';
    btn.textContent = '✕ Clear ' + ft.themes.length + ' selected theme' + (ft.themes.length > 1 ? 's' : '');
  }
}

function ftClearTheme() {
  ft.themes = [];
  document.getElementById('ft-theme-clear').style.display = 'none';
  // Deselect all cards visually without full re-render
  document.querySelectorAll('#ft-theme-grid > div').forEach(function(card) {
    card.style.borderColor = '#e5e7eb';
    var chk = card.querySelector('.ft-chk');
    if (chk) chk.remove();
  });
}

function ftInit() {
  ftLoadThemes();
  ftLoadModelPicker();
  // Show picker if no model selected, hide if already selected
  var hasModel = !!ft.modelBaseName;
  document.getElementById('ft-model-picker').style.display   = hasModel ? 'none' : '';
  document.getElementById('ft-model-selected').style.display = hasModel ? '' : 'none';
  // Only reset dress state if nothing uploaded yet
  if (ft.uploadSeq === 0) {
    ft.dressViews  = {};
    ft.uploadQueue = [];
    document.getElementById('ft-dress-upload-list').style.display = 'none';
    document.getElementById('ft-upload-zone').style.display       = 'block';
    document.getElementById('ft-view-map').style.display          = 'none';
    document.getElementById('ft-dress-proc-status').style.display = 'none';
    document.getElementById('ft-dress-thumbs').innerHTML           = '';
    document.getElementById('ft-dress-upload-actions').style.display = 'flex';
  }
}

// ── Inline model picker ───────────────────────────────────────────────────
var _ftAllSeries  = [];
var _ftActiveCat  = '';

async function ftLoadModelPicker() {
  var grid = document.getElementById('ft-model-grid');
  grid.innerHTML = '<div style="grid-column:1/-1;font-size:12px;color:#9ca3af;text-align:center;padding:20px;">Loading models…</div>';
  try {
    var fd = new FormData(); fd.append('fashn_action','get_models');
    var r  = await fetch(window.location.pathname, {method:'POST', body:fd});
    var d  = await r.json();
    if (!d.success || !d.series) { grid.innerHTML = '<div style="grid-column:1/-1;color:#dc2626;font-size:12px;padding:16px;">Could not load models.</div>'; return; }
    _ftAllSeries = d.series;
    // Build category tabs
    var cats = [...new Set(d.series.map(s => s.cat))];
    var catsEl = document.getElementById('ft-model-cats');
    catsEl.innerHTML = '';
    var allBtn = document.createElement('button');
    allBtn.textContent = 'All';
    allBtn.dataset.cat = '';
    allBtn.style.cssText = 'padding:3px 10px;border:1.5px solid #8b5cf6;border-radius:12px;background:#8b5cf6;color:#fff;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;';
    allBtn.onclick = function() { ftSetPickerCat('', this); };
    catsEl.appendChild(allBtn);
    cats.forEach(function(cat) {
      var btn = document.createElement('button');
      btn.textContent = cat.replace(/_/g,' ');
      btn.dataset.cat = cat;
      btn.style.cssText = 'padding:3px 10px;border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;color:#374151;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;';
      btn.onclick = function() { ftSetPickerCat(cat, this); };
      catsEl.appendChild(btn);
    });
    ftRenderPickerGrid();
  } catch(e) { grid.innerHTML = '<div style="grid-column:1/-1;color:#dc2626;font-size:12px;padding:16px;">Error: ' + e.message + '</div>'; }
}

function ftSetPickerCat(cat, btn) {
  _ftActiveCat = cat;
  document.querySelectorAll('#ft-model-cats button').forEach(function(b) {
    var active = b === btn;
    b.style.background  = active ? '#8b5cf6' : '#fff';
    b.style.color       = active ? '#fff' : '#374151';
    b.style.borderColor = active ? '#8b5cf6' : '#e5e7eb';
  });
  ftRenderPickerGrid();
}

function ftFilterModels() {
  ftRenderPickerGrid();
}

function ftRenderPickerGrid() {
  var q    = (document.getElementById('ft-model-search').value || '').toLowerCase();
  var grid = document.getElementById('ft-model-grid');
  grid.innerHTML = '';
  var filtered = _ftAllSeries.filter(function(s) {
    if (_ftActiveCat && s.cat !== _ftActiveCat) return false;
    if (q && s.label.toLowerCase().indexOf(q) === -1 && s.base_name.toLowerCase().indexOf(q) === -1) return false;
    return true;
  });
  if (filtered.length === 0) {
    grid.innerHTML = '<div style="grid-column:1/-1;font-size:12px;color:#9ca3af;text-align:center;padding:20px;">No models found.</div>';
    return;
  }
  filtered.forEach(function(s) {
    var card = document.createElement('div');
    var isSel = ft.modelBaseName === s.base_name && ft.modelCat === s.cat;
    card.style.cssText = 'border:2px solid ' + (isSel ? '#10b981' : '#e5e7eb') + ';border-radius:8px;overflow:hidden;cursor:pointer;position:relative;transition:border-color .15s;';
    card.innerHTML =
      (isSel ? '<div style="position:absolute;top:4px;right:4px;background:#10b981;color:#fff;border-radius:50%;width:16px;height:16px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;">✓</div>' : '')
      + '<img src="' + s.cover_url + '" style="width:100%;aspect-ratio:2/3;object-fit:cover;object-position:top 10%;display:block;" onerror="this.style.background=\'#f3f4f6\';this.style.minHeight=\'80px\';">'
      + '<div style="padding:3px 4px;font-size:9px;font-weight:600;color:#374151;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#f9fafb;border-top:1px solid #f3f4f6;">'
      + s.label + '</div>'
      + '<div style="padding:1px 4px 3px;font-size:9px;color:#9ca3af;text-align:center;background:#f9fafb;">' + s.file_count + ' poses</div>';
    card.onmouseover = function() { if (!isSel) this.style.borderColor='#8b5cf6'; };
    card.onmouseout  = function() { if (!isSel) this.style.borderColor='#e5e7eb'; };
    card.onclick = function() { ftSelectModel(s); };
    grid.appendChild(card);
  });
}

function ftSelectModel(s) {
  // Use the front view file as the "modelFile" reference
  var frontFile = s.files.find(function(f) { return f.view === 'front'; }) || s.files[0];
  ft.modelFile     = frontFile ? frontFile.filename : '';
  ft.modelCat      = s.cat;
  ft.modelUrl      = s.cover_url;
  ft.modelBaseName = s.base_name;
  ft.modelLabel    = s.label;
  // Store all files for this series for view-aware pose selection
  ft.modelFiles    = s.files;  // [{filename, view, pose_n, url}]

  // Show selected summary, hide picker
  document.getElementById('ft-model-none') && (document.getElementById('ft-model-none').style.display = 'none');
  document.getElementById('ft-model-selected').style.display = '';
  document.getElementById('ft-model-thumb').src = s.cover_url;
  document.getElementById('ft-model-name').textContent = s.label;
  document.getElementById('ft-model-cat').textContent  = s.cat.replace(/_/g,' ') + ' · ' + s.file_count + ' poses';
  ftShowModelPicker(false);
  ftShowPoseSelector();
}

function ftShowModelPicker(show) {
  document.getElementById('ft-model-picker').style.display = show ? '' : 'none';
  if (show) ftRenderPickerGrid(); // re-render to reflect current selection
}

function ftCat(cat, btn) {
  ft.garment = cat;
  ['ftc-full','ftc-top','ftc-bottom'].forEach(function(id) {
    var b = document.getElementById(id); if (!b) return;
    var active = b === btn;
    b.style.background  = active ? '#8b5cf6' : '#fff';
    b.style.color       = active ? '#fff'    : '#374151';
    b.style.borderColor = active ? '#8b5cf6' : '#e5e7eb';
  });
}

// ── Pose selector — groups model files by view, shows all poses ──────────
function ftShowPoseSelector() {
  if (!ft.modelBaseName || !ft.modelFiles) return;
  var section = document.getElementById('ft-pose-section');
  section.style.display = 'block';
  var grid = document.getElementById('ft-pose-grid');
  grid.innerHTML = '';

  // Sort by pose_n then view
  var sorted = ft.modelFiles.slice().sort(function(a,b) {
    return (parseInt(a.pose_n)||0) - (parseInt(b.pose_n)||0) || a.view.localeCompare(b.view);
  });

  sorted.forEach(function(f, idx) {
    var card = document.createElement('div');
    card.dataset.file = f.filename;
    card.dataset.view = f.view;
    var defaultOn = idx < 2;
    card.dataset.selected = defaultOn ? 'true' : 'false';
    card.style.cssText = 'border:2px solid ' + (defaultOn ? '#10b981' : '#e5e7eb') + ';border-radius:8px;overflow:hidden;cursor:pointer;position:relative;';

    var chk = document.createElement('div');
    chk.className = 'ft-pose-chk';
    chk.innerHTML = defaultOn ? '&#10003;' : '&#10005;';
    chk.style.cssText = 'position:absolute;top:4px;right:4px;width:18px;height:18px;background:' + (defaultOn ? '#10b981' : '#e5e7eb') + ';color:' + (defaultOn ? '#fff' : '#9ca3af') + ';border-radius:50%;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;';

    var img = document.createElement('img');
    img.src = 'promo_models/' + ft.modelCat + '/' + f.filename;
    img.style.cssText = 'width:100%;aspect-ratio:2/3;object-fit:cover;object-position:top;display:block;';
    img.onerror = function() { this.parentElement.style.opacity='0.4'; };

    var lbl = document.createElement('div');
    // Show view label + pose number
    var viewShort = {'front':'Front','back':'Back','left_side':'L.Side','right_side':'R.Side','upper_half':'Nk→Wst','lower_half':'Lower'}[f.view] || f.view;
    lbl.textContent = (f.pose_n ? 'P'+f.pose_n+' ' : '') + viewShort;
    lbl.style.cssText = 'font-size:9px;font-weight:600;color:#374151;padding:3px 4px;text-align:center;border-top:1px solid #f3f4f6;';

    card.appendChild(chk); card.appendChild(img); card.appendChild(lbl);
    grid.appendChild(card);

    card.onclick = function() {
      var sel = this.dataset.selected === 'true';
      this.dataset.selected = sel ? 'false' : 'true';
      this.style.borderColor = sel ? '#e5e7eb' : '#10b981';
      var c = this.querySelector('.ft-pose-chk');
      if (c) { c.style.background = sel ? '#e5e7eb' : '#10b981'; c.style.color = sel ? '#9ca3af' : '#fff'; }
    };
  });
}

function ftSelectAllPoses(selectAll) {
  document.querySelectorAll('#ft-pose-grid > div').forEach(function(card) {
    card.dataset.selected = selectAll ? 'true' : 'false';
    card.style.borderColor = selectAll ? '#10b981' : '#e5e7eb';
    var chk = card.querySelector('.ft-pose-chk');
    if (chk) { chk.style.background = selectAll ? '#10b981' : '#e5e7eb'; chk.style.color = selectAll ? '#fff' : '#9ca3af'; }
  });
}

// ── Run Try-On across all views × all selected poses ──────────────────────
async function ftRun() {
  var viewsReady = Object.keys(ft.dressViews).filter(v => ft.dressViews[v]);
  if (viewsReady.length === 0) { alert('Please upload and process dress images first.'); return; }
  if (!ft.modelBaseName) { alert('Please select a model first.'); return; }

  var selectedPoses = [];
  document.querySelectorAll('#ft-pose-grid > div').forEach(function(card) {
    if (card.dataset.selected === 'true') selectedPoses.push({ file: card.dataset.file, view: card.dataset.view || 'front' });
  });
  if (selectedPoses.length === 0) { alert('Please select at least one pose.'); return; }

  var progEl    = document.getElementById('ft-run-prog');
  var errEl     = document.getElementById('ft-run-err');
  var resultsEl = document.getElementById('ft-result');
  var gridEl    = document.getElementById('ft-result-grid');

  progEl.style.display    = '';
  errEl.style.display     = 'none';
  resultsEl.style.display = '';
  gridEl.innerHTML        = '';

  // Match each selected model pose to the dress view that best fits.
  // Expand by themes: if themes selected, each pose runs once per theme + once as white bg.
  var themeList = ft.themes.length ? ft.themes : [null];
  var jobs = [];
  selectedPoses.forEach(function(ps) {
    var modelView = ps.view || 'front';
    var dressView = ft.dressViews[modelView]
      ? modelView
      : (ft.dressViews['front'] ? 'front' : viewsReady[0]);
    if (!ft.dressViews[dressView]) return;
    themeList.forEach(function(theme) {
      jobs.push({ view: dressView, viewLabel: VIEW_LABELS[dressView] || dressView,
                  modelView: modelView, poseFile: ps.file,
                  dressItem: ft.dressViews[dressView], theme: theme });
    });
  });

  var extMatch = ft.modelFile.match(/(\.[^.]+)$/);
  var ext = extMatch ? extMatch[1] : '.jpg';

  // Pre-create result cards
  var cards = {};
  jobs.forEach(function(job) {
    var key = job.view + '_' + job.poseFile + '_' + (job.theme ? job.theme.theme_key : 'white');
    var card = document.createElement('div');
    card.style.cssText = 'border:2px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#f9fafb;';
    card.innerHTML = '<div style="min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;">'
      + '<div style="width:22px;height:22px;border:3px solid #e5e7eb;border-top-color:#8b5cf6;border-radius:50%;animation:spin .8s linear infinite;"></div>'
      + '<div style="font-size:10px;color:#9ca3af;">' + job.viewLabel + ' · Pose ' + job.pose + '</div></div>';
    gridEl.appendChild(card);
    cards[key] = card;
  });

  var done = 0;
  for (var ji = 0; ji < jobs.length; ji++) {
    var job = jobs[ji];
    var key = job.view + '_' + job.poseFile + '_' + (job.theme ? job.theme.theme_key : 'white');
    var shortView = {'front':'Front','back':'Back','left_side':'L.Side','right_side':'R.Side','upper_half':'Nk→Wst','lower_half':'Lower'}[job.modelView] || job.modelView;
    progEl.textContent = 'Processing ' + shortView + ' (' + (ji+1) + '/' + jobs.length + ')…';
    try {
      var fd = new FormData();
      fd.append('fashn_action',     'fashn_tryon');
      fd.append('dress_file',       job.dressItem.filename);
      fd.append('model_cat',        ft.modelCat);
      fd.append('model_file',       job.poseFile);
      fd.append('garment_category', ft.garment);
      var jobTheme = job.theme || null;
  if (jobTheme) { fd.append('theme_key', jobTheme.theme_key); fd.append('theme_name', jobTheme.theme_name); fd.append('theme_location', jobTheme.location_prompt); }
      var r = await fetch(window.location.pathname, { method:'POST', body:fd });
      var d = await r.json();
      if (!d.success) throw new Error(d.error || 'Failed');

      var resultUrl = d.result_url;

      // Auto-apply background if theme set
      if (jobTheme && jobTheme.location_prompt) {
        try {
          var bfd = new FormData();
          bfd.append('fashn_action',    'change_background');
          bfd.append('image_url',       resultUrl);
          bfd.append('theme_name',      jobTheme.theme_name);
          bfd.append('location_prompt', jobTheme.location_prompt);
          var br = await fetch(window.location.pathname, { method:'POST', body:bfd });
          var bd = await br.json();
          if (bd.success) resultUrl = bd.result_url;
        } catch(bge) { /* keep original */ }
      }

      cards[key].innerHTML =
        '<img src="' + resultUrl + '" style="width:100%;display:block;object-fit:contain;background:#f9fafb;">'
        + '<div style="padding:5px 8px;font-size:10px;font-weight:600;color:#374151;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">'
        + (({'front':'Front','back':'Back','left_side':'L.Side','right_side':'R.Side','upper_half':'Nk→Wst','lower_half':'Lower'})[job.modelView] || job.modelView)
        + (jobTheme ? ' · ' + jobTheme.theme_name : ' · White BG')
        + '<a href="' + resultUrl + '" download="tryon_' + job.view + '_' + job.poseFile + '.jpg" style="color:#10b981;text-decoration:none;font-weight:700;">&#11015;</a></div>';
      cards[key].style.borderColor = '#10b981';
      done++;
    } catch(err) {
      cards[key].innerHTML = '<div style="aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;padding:10px;text-align:center;">'
        + '<div style="font-size:10px;color:#dc2626;">' + job.poseFile + '<br>' + err.message + '</div></div>';
      cards[key].style.borderColor = '#fca5a5';
    }
  }

  progEl.textContent = '✅ Done! ' + done + ' of ' + jobs.length + ' completed.';
}

// Init
updateHeaderBadge();
renderHistory();
</script>
</body>
</html>

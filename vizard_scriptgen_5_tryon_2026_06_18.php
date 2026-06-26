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

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

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

// ── Ensure business-settings columns exist on hdb_companies ─────────────────
$_cols = [];
$_cr = mysqli_query($conn, "SHOW COLUMNS FROM hdb_companies");
if ($_cr) while ($_col = mysqli_fetch_assoc($_cr)) $_cols[] = $_col['Field'];
$_needed_cols = [
    'group_name'    => 'VARCHAR(120)',
    'subgroup_name' => 'VARCHAR(120)',
    'niche'         => 'VARCHAR(120)',
    'ai_group'      => 'VARCHAR(200)',
    'ai_subgroup'   => 'VARCHAR(200)',
];
foreach ($_needed_cols as $_colName => $_colType) {
    if (!in_array($_colName, $_cols)) {
        mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN $_colName $_colType DEFAULT NULL");
        vv_log("Auto-added column $_colName to hdb_companies");
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
    $allowed = ['group_name', 'subgroup_name', 'niche', 'ai_group', 'ai_subgroup'];
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

    $garment_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/images/";
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
    $b64 = base64_encode(file_get_contents($orig_path));
    $upload_payload = json_encode(['base64'=>$b64,'mime_type'=>$mime,'file_name'=>$base.'.'.$ext]);
    $uch = curl_init($protocol.'://'.$host.'/fal_proxy.php?action=upload');
    curl_setopt_array($uch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $upload_payload,
    ]);
    $ures = curl_exec($uch); curl_close($uch);
    $uj   = json_decode($ures, true);

    $white_path = $orig_path;                    // fallback chain — starts at original
    $white_file = $base . '_orig.' . $ext;

    if (empty($uj['file_url'])) {
        vv_log("garment_upload_image: fal storage upload failed | " . substr((string)$ures,0,300));
    } else {
        $fal_image_url = $uj['file_url'];

        // ── Step 2: remove background — fal-ai/imageutils/rembg ─────────────
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
                }
                @unlink($png_path);
            }
        } else {
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
    $manifest_path = $garment_dir . '_manifest.json';
    $manifest = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];
    $manifest[] = ['filename'=>$final_file, 'created_at'=>date('c')];
    file_put_contents($manifest_path, json_encode($manifest));

    $final_url = $protocol.'://'.$host.$web_path.$final_file;
    $info = @getimagesize($final_path);

    echo json_encode([
        'success'    => true,
        'filename'   => $final_file,
        'url'        => $final_url,
        'dimensions' => ['w'=>$info[0]??0,'h'=>$info[1]??0],
    ]); exit;
}

// ── List previously uploaded images (restores the gallery on page reload) ───
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_list_images') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>true,'images'=>[]]); exit; }

    $garment_dir   = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/images/";
    $manifest_path = $garment_dir . '_manifest.json';
    $manifest      = file_exists($manifest_path) ? (json_decode(file_get_contents($manifest_path), true) ?: []) : [];

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($garment_dir,'/')), '/') . '/';

    $rows = [];
    foreach ($manifest as $m) {
        if (empty($m['filename']) || !is_file($garment_dir . $m['filename'])) continue;
        $rows[] = ['filename'=>$m['filename'], 'url'=>$protocol.'://'.$host.$web_path.$m['filename']];
    }
    echo json_encode(['success'=>true,'images'=>$rows]); exit;
}

// ── Delete a single uploaded image ───────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'garment_delete_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $fname = basename(trim($_POST['filename'] ?? ''));
    if (!$fname) { echo json_encode(['success'=>false,'error'=>'Missing filename']); exit; }

    $garment_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/images/";
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
// AJAX HANDLERS — Step 2: Model Selection (Ethnicity group → Model)
// ═════════════════════════════════════════════════════════════════════════════

// ── Ethnicity groups — mdl_model_groups ──────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_model_groups') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $q = mysqli_query($conn, "SELECT id, ethnicity, gender, title, slug, thumbnail FROM mdl_model_groups ORDER BY ethnicity ASC, gender ASC, title ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'id'        => (int)$r['id'],
            'ethnicity' => $r['ethnicity'],
            'gender'    => $r['gender'],
            'title'     => $r['title'],
            'slug'      => $r['slug'],
            'thumbnail' => $r['thumbnail'],
        ];
    }
    echo json_encode(['success'=>true,'groups'=>$rows]); exit;
}

// ── Models within a group — mdl_models, matched by ethnicity (+ gender) ─────
// NOTE: there's no FK between mdl_model_groups and mdl_models, so this joins
// on the ethnicity/gender string values. Flag if that's not the intended link.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_models_by_group') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $ethnicity = mysqli_real_escape_string($conn, trim($_POST['ethnicity'] ?? ''));
    $gender    = mysqli_real_escape_string($conn, trim($_POST['gender']    ?? ''));
    if (!$ethnicity) { echo json_encode(['success'=>false,'error'=>'Missing ethnicity']); exit; }

    $sql = "SELECT model_id, model_name, age_range, ethnicity, gender, skin_tone, face_type, style_focus, thumbnail, poses_exist
            FROM mdl_models WHERE ethnicity='$ethnicity'";
    if ($gender) $sql .= " AND gender='$gender'";
    $sql .= " ORDER BY model_name ASC";

    $q = mysqli_query($conn, $sql);
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'model_id'    => (int)$r['model_id'],
            'model_name'  => $r['model_name'],
            'age_range'   => $r['age_range'],
            'ethnicity'   => $r['ethnicity'],
            'gender'      => $r['gender'],
            'skin_tone'   => $r['skin_tone'],
            'face_type'   => $r['face_type'],
            'style_focus' => $r['style_focus'],
            'thumbnail'   => $r['thumbnail'],
            'poses_exist' => (int)$r['poses_exist'],
        ];
    }
    echo json_encode(['success'=>true,'models'=>$rows]); exit;
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
// AJAX HANDLER — Step 3: Model + Pose image (cache-or-generate)
// ═════════════════════════════════════════════════════════════════════════════
// Cache key is the file itself: promo_models/model_id_{id}_pose_{code}.png
// First request for a given (model, pose) pair generates it via
// fal-ai/ideogram/character using the model's reference photo + the pose
// template's image_prompt. Every later request for that same pair is an
// instant filesystem hit — no DB table needed, the file IS the cache.
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
// AJAX HANDLER — Step 4: Apply garment to a generated pose (fashn/tryon)
// ═════════════════════════════════════════════════════════════════════════════
// Same fal-ai/fashn/tryon/v1.6 call already proven in fal_video_generator.php —
// one garment image gets reused across every pose, exactly like that file does
// with one dress_file against multiple model/pose images.
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

    $garment_dir  = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/images/";
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

/* ── Garment image upload (Step 1) ───────────────────────────────────────────── */
.garment-heading    { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; }
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

      <div id="garmentNotApplicable" style="padding:30px 0;text-align:center;color:var(--muted);font-size:13px;line-height:1.7;">
        This step applies to garment categories that need a model — Bridal Wear, Party Wear, Indian/Pakistani Bridal Wear, Jewellery.<br>
        Set your Business sub-group above to continue.
      </div>

      <div id="garmentStep1Wrap" style="display:none;">
        <div class="garment-heading">Upload up to 6 images</div>
        <div class="garment-subheading">Front, back, sides, an upper-half pose, and a bottom-half pose work best for the video.</div>

        <div class="garment-zone">
          <input type="file" accept="image/*" multiple onchange="garmentUploadFiles(this.files); this.value='';">
          <div class="garment-zone-text">📤 Click or drop images here</div>
          <div class="garment-zone-sub">Background is removed and the image enhanced automatically</div>
        </div>

        <div class="garment-grid" id="garmentGrid"></div>

        <div class="setting-group">
          <div class="setting-label">Garment Type</div>
          <div class="setting-opts" id="garmentTypeOpts">
            <div class="sopt sel" data-v="one-pieces" onclick="garmentSelectType(this)">👗 Full</div>
            <div class="sopt"     data-v="tops"       onclick="garmentSelectType(this)">👕 Upper</div>
            <div class="sopt"     data-v="bottoms"    onclick="garmentSelectType(this)">👖 Lower</div>
          </div>
        </div>

        <div class="garment-heading" style="margin-top:6px;">Step 2 — Select Model</div>
        <div class="garment-subheading">Pick an ethnicity, then a model.</div>

        <div id="modelGroupPanel">
          <div id="modelGroupGrid" class="model-grid"></div>
        </div>

        <div id="modelPickPanel" style="display:none;">
          <button class="biz-back-btn" onclick="modelBackToGroups()">← Back to ethnicity</button>
          <div id="modelPickGrid" class="model-grid" style="margin-top:8px;"></div>
        </div>

        <div id="modelSelectedInfo" style="display:none;margin-top:4px;margin-bottom:14px;padding:8px 12px;background:#d1fae5;border:1.5px solid #6ee7b7;border-radius:8px;font-size:12px;color:#065f46;font-weight:600;"></div>

        <div class="garment-heading" style="margin-top:6px;">Step 3 — Select a Style</div>
        <div class="garment-subheading">Pick a pose sequence. Any poses not already cached for this model get generated on the spot.</div>

        <div id="styleNeedsModelHint" style="display:none;font-size:12px;color:#dc2626;font-weight:600;margin-bottom:8px;">⚠ Select a model in Step 2 above before picking a style.</div>
        <div id="styleGrid" class="style-grid"></div>

        <div id="poseGenWrap" style="display:none;margin-top:14px;">
          <div class="garment-heading">Generated Poses (preview)</div>
          <div id="poseGenGrid" class="model-grid"></div>

          <div id="tryonStepWrap" style="display:none;margin-top:18px;">
            <div class="garment-heading">Step 4 — Apply Garment</div>
            <div class="garment-subheading">Runs your uploaded garment against all 7 generated poses, one fashn/tryon call per pose.</div>
            <button class="biz-done-btn" id="tryonRunBtn" onclick="runTryonAllPoses()" disabled>Apply Garment to All Poses</button>
            <div id="tryonGrid" class="model-grid" style="margin-top:14px;"></div>
          </div>
        </div>
      </div>

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
// GARMENT IMAGE UPLOAD (Step 1)  — only for sub-groups that need a model
// ═════════════════════════════════════════════════════════════════════════════

// NOTE: confirm these exactly match hdb_promo_subcategories.promo_subgroup —
// compared case-insensitively below as a safety net against minor mismatches.
const MODEL_REQUIRED_SUBGROUPS = ['Bridal Wear', 'Party Wear', 'Indian/Pakistani Bridal Wear', 'Jewellery'];

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
        garmentLoadExisting();
        loadModelGroups();
        loadPoseStyles();
    }
}

let garmentType = 'one-pieces';
function garmentSelectType(el) {
    document.querySelectorAll('#garmentTypeOpts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    garmentType = el.dataset.v;
}

async function garmentLoadExisting() {
    try {
        const d = await post({ ajax_action:'garment_list_images' });
        if (d.success) {
            const grid = document.getElementById('garmentGrid');
            grid.innerHTML = '';
            (d.images || []).forEach(img => garmentAddCard(img.filename, img.url));
        }
    } catch(e) {}
}

function garmentAddCard(filename, url) {
    const grid = document.getElementById('garmentGrid');
    const card = document.createElement('div');
    card.className = 'garment-card';
    card.dataset.filename = filename;
    card.innerHTML = '<img src="' + esc(url) + '">'
        + '<button class="garment-card-del" onclick="garmentDeleteImage(\'' + esc(filename) + '\', this)">✕</button>';
    grid.appendChild(card);
    return card;
}

async function garmentUploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (!files.length) return;
    const grid = document.getElementById('garmentGrid');

    for (const file of files) {
        const placeholder = document.createElement('div');
        placeholder.className = 'garment-card uploading';
        placeholder.innerHTML = '<div class="garment-card-spin"></div>';
        grid.appendChild(placeholder);

        try {
            const fd = new FormData();
            fd.append('ajax_action', 'garment_upload_image');
            fd.append('image', file);
            const r = await fetch(location.href, { method:'POST', body:fd });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'Upload failed');
            placeholder.remove();
            garmentAddCard(d.filename, d.url);
        } catch(e) {
            placeholder.remove();
            showToast('Upload failed: ' + e.message);
        }
    }
}

async function garmentDeleteImage(filename, btnEl) {
    const card = btnEl.closest('.garment-card');
    if (card) card.style.opacity = '.4';
    try {
        await post({ ajax_action:'garment_delete_image', filename: filename });
        if (card) card.remove();
    } catch(e) {
        if (card) card.style.opacity = '1';
        showToast('Delete failed');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — MODEL SELECTION  (Ethnicity group → Model)
// ═════════════════════════════════════════════════════════════════════════════
let modelGroups        = [];
let groupModels         = [];
let selectedModelGroup  = null;
let selectedModel       = null;

async function loadModelGroups() {
    const grid = document.getElementById('modelGroupGrid');
    grid.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading…</span></div>`;
    try {
        const d = await post({ ajax_action:'get_model_groups' });
        modelGroups = d.success ? (d.groups || []) : [];
    } catch(e) { modelGroups = []; }
    renderModelGroupGrid();
}

function renderModelGroupGrid() {
    const grid = document.getElementById('modelGroupGrid');
    if (!modelGroups.length) {
        grid.innerHTML = '<div style="font-size:12px;color:#aaa;grid-column:1/-1;">No model groups found.</div>';
        return;
    }
    grid.innerHTML = modelGroups.map(g => `
        <div class="model-card" data-id="${g.id}" onclick="modelGroupSelect(${g.id})">
            <img src="${esc(g.thumbnail || '')}" onerror="this.style.opacity='.2'">
            <div class="model-card-label">${escHtml(g.title || g.ethnicity || '')}</div>
            <div class="model-card-sub">${escHtml(g.gender || '')}</div>
        </div>`).join('');
}

async function modelGroupSelect(groupId) {
    const group = modelGroups.find(g => String(g.id) === String(groupId));
    if (!group) return;
    selectedModelGroup = group;
    selectedModel = null;
    document.getElementById('modelSelectedInfo').style.display = 'none';

    document.getElementById('modelGroupPanel').style.display = 'none';
    document.getElementById('modelPickPanel').style.display  = '';
    const pickGrid = document.getElementById('modelPickGrid');
    pickGrid.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading models…</span></div>`;

    try {
        const d = await post({ ajax_action:'get_models_by_group', ethnicity: group.ethnicity, gender: group.gender });
        groupModels = d.success ? (d.models || []) : [];
        if (!groupModels.length) {
            pickGrid.innerHTML = '<div style="font-size:12px;color:#aaa;grid-column:1/-1;">No models found for this group.</div>';
            return;
        }
        pickGrid.innerHTML = groupModels.map(m => `
            <div class="model-card" data-id="${m.model_id}" onclick="modelSelect(${m.model_id})">
                <img src="${esc(m.thumbnail || '')}" onerror="this.style.opacity='.2'">
                <div class="model-card-label">${escHtml(m.model_name || ('Model #' + m.model_id))}</div>
                <div class="model-card-sub">${escHtml(m.style_focus || m.age_range || '')}</div>
            </div>`).join('');
    } catch(e) {
        groupModels = [];
        pickGrid.innerHTML = '<div style="font-size:12px;color:#dc2626;grid-column:1/-1;">Could not load models.</div>';
    }
}

function modelBackToGroups() {
    document.getElementById('modelPickPanel').style.display  = 'none';
    document.getElementById('modelGroupPanel').style.display = '';
}

function modelSelect(modelId) {
    selectedModel = groupModels.find(m => String(m.model_id) === String(modelId)) || null;
    document.querySelectorAll('#modelPickGrid .model-card').forEach(c => {
        c.classList.toggle('sel', String(c.dataset.id) === String(modelId));
    });
    const info = document.getElementById('modelSelectedInfo');
    if (selectedModel) {
        info.style.display = '';
        info.textContent = '✓ Selected: ' + (selectedModel.model_name || ('Model #' + modelId))
            + (selectedModel.poses_exist ? ' · ' + selectedModel.poses_exist + ' poses available' : ' · no poses on file yet');
    }
    renderStyleGrid();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 — STYLE SELECTION & POSE GENERATION (preview)
// ═════════════════════════════════════════════════════════════════════════════
let poseStyles    = [];
let selectedStyle = null;

async function loadPoseStyles() {
    const grid = document.getElementById('styleGrid');
    grid.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading styles…</span></div>`;
    try {
        const d = await post({ ajax_action:'get_pose_styles' });
        poseStyles = d.success ? (d.styles || []) : [];
    } catch(e) { poseStyles = []; }
    renderStyleGrid();
}

function renderStyleGrid() {
    const grid = document.getElementById('styleGrid');
    const hint = document.getElementById('styleNeedsModelHint');
    const hasModel = !!(selectedModel && selectedModel.model_id);
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

async function styleSelect(styleId) {
    const style = poseStyles.find(s => String(s.id) === String(styleId));
    if (!style) return;
    if (!selectedModel || !selectedModel.model_id) {
        showToast('Select a model first (Step 2)');
        return;
    }
    selectedStyle = style;

    document.querySelectorAll('#styleGrid .style-card').forEach(c => {
        c.classList.toggle('sel', String(c.dataset.id) === String(styleId));
    });

    const wrap = document.getElementById('poseGenWrap');
    const grid = document.getElementById('poseGenGrid');
    wrap.style.display = '';
    grid.innerHTML = '';

    for (const code of style.scenes) {
        const card = document.createElement('div');
        card.className = 'model-card';
        card.innerHTML = `<div class="garment-card-spin" style="margin:22px auto;"></div><div class="model-card-label">${escHtml(code)}</div>`;
        grid.appendChild(card);

        try {
            const d = await post({ ajax_action:'get_model_pose_image', model_id: selectedModel.model_id, pose_code: code });
            if (!d.success) { const err = new Error(d.error || 'Failed'); err.refPath = d.ref_photo_path || ''; throw err; }
            card.innerHTML = `<img src="${esc(d.url)}">`
                + `<div class="model-card-label">${escHtml(code)}</div>`
                + `<div class="model-card-sub">${d.cached ? 'cached' : 'generated'}</div>`;
        } catch(e) {
            card.innerHTML = `<div style="padding:14px 4px;font-size:11px;color:#dc2626;text-align:center;">✕ ${escHtml(code)} failed<br>${escHtml(e.message)}</div>`
                + (e.refPath ? `<div style="padding:0 4px 10px;font-size:10px;color:#999;text-align:center;word-break:break-all;">${escHtml(e.refPath)}</div>` : '');
        }
    }

    document.getElementById('tryonStepWrap').style.display = '';
    document.getElementById('tryonRunBtn').disabled = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — APPLY GARMENT  (fashn/tryon, one garment reused across all poses)
// ═════════════════════════════════════════════════════════════════════════════
function getActiveGarmentFile() {
    // Uses the first uploaded garment image as "the" garment for this run —
    // multi-garment selection can be added later if more than one is needed.
    const firstCard = document.querySelector('#garmentGrid .garment-card');
    return firstCard ? firstCard.dataset.filename : null;
}

async function runTryonAllPoses() {
    if (!selectedStyle || !selectedModel) { showToast('Pick a model and style first'); return; }
    const garmentFile = getActiveGarmentFile();
    if (!garmentFile) { showToast('Upload a garment image in Step 1 first'); return; }

    const runBtn = document.getElementById('tryonRunBtn');
    runBtn.disabled = true;

    const grid = document.getElementById('tryonGrid');
    grid.innerHTML = '';

    for (const code of selectedStyle.scenes) {
        const card = document.createElement('div');
        card.className = 'model-card';
        card.innerHTML = `<div class="garment-card-spin" style="margin:22px auto;"></div><div class="model-card-label">${escHtml(code)}</div>`;
        grid.appendChild(card);

        try {
            const d = await post({
                ajax_action: 'apply_garment_tryon',
                model_id: selectedModel.model_id,
                pose_code: code,
                garment_file: garmentFile,
                garment_category: garmentType,
            });
            if (!d.success) throw new Error(d.error || 'Failed');
            card.innerHTML = `<img src="${esc(d.url)}">`
                + `<div class="model-card-label">${escHtml(code)}</div>`;
        } catch(e) {
            card.innerHTML = `<div style="padding:14px 4px;font-size:11px;color:#dc2626;text-align:center;">✕ ${escHtml(code)} failed<br>${escHtml(e.message)}</div>`;
        }
    }

    runBtn.disabled = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// INIT
// ═════════════════════════════════════════════════════════════════════════════
renderBusinessBar();
</script>
</body>
</html>

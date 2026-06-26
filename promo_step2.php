<?php
// ============================================================
// promo_step2.php — Promo Video Build Pipeline
// Standalone AJAX handler — does NOT touch vizard_scriptgen_1
// or wizard_step2. Owns its own modal in vizard_promo4.php.
//
// Actions:
//   build_captions         — write hdb_captions using user settings
//   update_podcast_voice   — set voice on podcast record
//   get_scenes             — fetch scene rows by podcast_id
//   update_scene_tags      — push nl_tags / hashtags into a scene
//   generate_scene_audio   — OpenAI TTS per scene → saves MP3
//   search_images_batch    — stock media search via searchAssets()
//   assign_image           — assign a filename to a scene row
// ============================================================

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/promo_step2.log');
error_reporting(E_ALL);

ob_start();

// ── Session ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    // Session may be closed — try POST param as fallback
    if (!empty($_POST['admin_id'])) {
        $_SESSION['admin_id']   = (int)$_POST['admin_id'];
        $_SESSION['company_id'] = (int)($_POST['company_id'] ?? 0);
    } else {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'Not authenticated']);
        exit;
    }
}

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// Release session lock so parallel audio requests don't queue
session_write_close();

require_once __DIR__ . '/config.php';

if (!$conn) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'DB connection failed']);
    exit;
}

// ── Team lead resolver ─────────────────────────────────────
$_u = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$team_lead_id = (!empty($_u) && trim((string)($_u['role']??''))==='Team Member' && (int)($_u['team_lead_id']??0)>0)
    ? (int)$_u['team_lead_id'] : $admin_id;

// ── Logger ────────────────────────────────────────────────
function p2log($msg) {
    $log = __DIR__ . '/promo_step2.log';
    if (!file_exists($log)) @touch($log);
    if (!is_writable($log)) @chmod($log, 0666);
    $ts = date('H:i:s');
    error_log("[promo_step2 $ts] $msg" . PHP_EOL, 3, $log);
}

// ── API keys ───────────────────────────────────────────────
$apiKey    = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;  // OpenAI
// fal.ai key — preserve value from config.php if already set
$falApiKey = $falApiKey ?? null;
foreach (['fal_api_key','fal_key','FAL_API_KEY'] as $_fk) {
    if (!$falApiKey && !empty($$_fk)) { $falApiKey = $$_fk; break; }
}
p2log("keys: openai=" . ($apiKey?'SET':'MISSING') . " fal=" . ($falApiKey?'SET':'MISSING'));
$modalImageUrl = defined('MODAL_IMAGE_URL') ? MODAL_IMAGE_URL : null;

// ── Image search dependency ────────────────────────────────
if (file_exists(__DIR__ . '/image_search_functions.php')) {
    require_once __DIR__ . '/image_search_functions.php';
}

// ── Route — read action FIRST, then flush buffer and set header ──
$action = trim($_POST['action'] ?? $_POST['ajax_action'] ?? '');
p2log("action=$action admin=$admin_id");

ob_end_clean();
header('Content-Type: application/json');

// ════════════════════════════════════════════════════════════
// HELPERS: getUserSettings + buildCaptionRows
// Mirrors wizard_step2.php exactly — no dependency on it.
// ════════════════════════════════════════════════════════════

function p2_getUserSettings($conn, $admin_id, $company_id = 0) {
    if ($company_id > 0) {
        $q = mysqli_query($conn,
            "SELECT * FROM hdb_user_settings
             WHERE admin_id='$admin_id' AND company_id='$company_id'
             ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
        if (!$q || mysqli_num_rows($q) === 0) {
            $q = mysqli_query($conn,
                "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id'
                 ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
        }
    } else {
        $q = mysqli_query($conn,
            "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id'
             ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
    }

    $settings = ['caption'=>null,'header'=>null,'footer'=>null,'logo'=>null];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $t = $r['text_type'] ?? 'caption';
            if (array_key_exists($t, $settings)) $settings[$t] = $r;
        }
    }

    $def_caption = [
        'text_type'=>'caption','is_enabled'=>1,
        'fontfamily'=>'Arial','fontsize'=>28,
        'fontcolor'=>'#ffff00','fontweight'=>'bold',
        'fontcolor_bg'=>'#000000','fontbg_enable'=>0,
        'caption_style'=>'none','caption_position'=>'bottom',
        'caption_alignment'=>'center','caption_speed'=>1.0,
        'text_effect'=>'none','text_animation'=>'none',
        'display_mode'=>'full','animation_speed'=>'normal',
        'position_x'=>50,'position_y'=>250,'width'=>500,
        'logo_name'=>'','logo_size_pct'=>15,
        'logo_pos_h'=>'right','logo_pos_v'=>'top','logo_enabled'=>0,
        'caption_text'=>'',
        'font_italic'=>0,'font_underline'=>0,
        'stroke_color'=>'#000000','stroke_width'=>0,
        'shadow_color'=>'#000000','gradient_color'=>'#ff6600',
        'text_effect_color'=>'#ffffff','text_align_v'=>'bottom',
    ];
    $def_header = array_merge($def_caption, [
        'text_type'=>'header','is_enabled'=>0,
        'fontfamily'=>'Helvetica','fontsize'=>16,
        'fontcolor'=>'#ffffff','fontweight'=>'bold',
        'fontcolor_bg'=>'#1a1a2e','fontbg_enable'=>1,
        'caption_style'=>'box','caption_position'=>'top',
        'text_animation'=>'fade_in','position_x'=>0,'position_y'=>16,'width'=>1080,
        'caption_text'=>'','logo_enabled'=>0,
    ]);
    $def_footer = array_merge($def_caption, [
        'text_type'=>'footer','is_enabled'=>0,
        'fontfamily'=>'Georgia','fontsize'=>12,
        'fontcolor'=>'#aaaaaa','fontweight'=>'normal',
        'fontcolor_bg'=>'#000000','fontbg_enable'=>0,
        'caption_style'=>'none','caption_position'=>'bottom',
        'text_animation'=>'static','animation_speed'=>1.0,
        'position_x'=>0,'position_y'=>555,'width'=>1080,
        'caption_text'=>'','logo_enabled'=>0,
    ]);
    $def_logo = [
        'text_type'=>'logo','position_x'=>960,'position_y'=>20,
        'width'=>120,'logo_name'=>'','logo_size_pct'=>15,
        'logo_pos_h'=>'right','logo_pos_v'=>'top','logo_enabled'=>0,
    ];

    $safe_merge = function(array $defaults, array $db_row): array {
        $result = $defaults;
        foreach ($db_row as $key => $value) {
            if ($value !== null) $result[$key] = $value;
        }
        $result['_anim_style']  = (isset($db_row['text_animation'])    && strlen($db_row['text_animation'])    > 0) ? $db_row['text_animation']    : ($defaults['text_animation']    ?? 'none');
        $_raw_spd               = (isset($db_row['animation_speed'])   && strlen($db_row['animation_speed'])   > 0) ? $db_row['animation_speed']   : ($defaults['animation_speed']   ?? 'normal');
        $result['_anim_speed']  = is_numeric($_raw_spd) ? (float)$_raw_spd : 1.0;
        $result['_text_fx']     = (isset($db_row['text_effect'])       && strlen($db_row['text_effect'])       > 0) ? $db_row['text_effect']       : ($defaults['text_effect']       ?? 'none');
        $result['_text_fx_col'] = (isset($db_row['text_effect_color']) && strlen($db_row['text_effect_color']) > 0) ? $db_row['text_effect_color'] : ($defaults['text_effect_color'] ?? '#ffffff');
        $result['logo_name']    = trim($result['logo_name'] ?? '');
        return $result;
    };

    $settings['caption'] = $settings['caption'] ? $safe_merge($def_caption, $settings['caption']) : $safe_merge($def_caption, []);
    $settings['header']  = $settings['header']  ? $safe_merge($def_header,  $settings['header'])  : $safe_merge($def_header,  []);
    $settings['footer']  = $settings['footer']  ? $safe_merge($def_footer,  $settings['footer'])  : $safe_merge($def_footer,  []);
    $settings['logo']    = $settings['logo']    ? $safe_merge($def_logo,    $settings['logo'])    : $safe_merge($def_logo,    []);

    return $settings;
}

function p2_buildCaptionRows($conn, $podcast_id, $story_id, $scene_text, $user_settings_all) {
    $cap  = $user_settings_all['caption'];
    $hdr  = $user_settings_all['header'];
    $ftr  = $user_settings_all['footer'];
    $rows = 0;

    $doInsert = function(
        string $cap_type, string $cap_name, string $text, array $s, int $z,
        int $px_override = -1, int $py_override = -1, int $fs_override = -1, int $pw_override = -1
    ) use ($conn, $podcast_id, $story_id): bool {
        $ff         = mysqli_real_escape_string($conn, $s['fontfamily']        ?? 'Arial');
        $fs         = $fs_override >= 0 ? $fs_override : (int)($s['fontsize'] ?? 28);
        $fc         = mysqli_real_escape_string($conn, $s['fontcolor']         ?? '#ffffff');
        $fw         = mysqli_real_escape_string($conn, $s['fontweight']        ?? 'normal');
        $fst        = ((int)($s['font_italic']    ?? 0)) ? 'italic' : 'normal';
        $uline      = (int)($s['font_underline']  ?? 0);
        $talign     = mysqli_real_escape_string($conn, $s['caption_alignment'] ?? $s['text_align'] ?? 'center');
        $talign_v   = mysqli_real_escape_string($conn, $s['text_align_v']      ?? 'bottom');
        $bgc        = mysqli_real_escape_string($conn, $s['fontcolor_bg']      ?? '#000000');
        $bge        = (int)($s['fontbg_enable']   ?? 0);
        $stroke_c   = mysqli_real_escape_string($conn, $s['stroke_color']      ?? '#000000');
        $stroke_w   = (int)($s['stroke_width']    ?? 0);
        $stroke_e   = $stroke_w > 0 ? 1 : 0;
        $shadow_c   = mysqli_real_escape_string($conn, $s['shadow_color']      ?? '#000000');
        $grad_c     = mysqli_real_escape_string($conn, $s['gradient_color']    ?? '#ff6600');
        $anim_style = mysqli_real_escape_string($conn, $s['_anim_style']       ?? 'none');
        $anim_spd   = is_numeric($s['_anim_speed'] ?? null) ? (float)$s['_anim_speed'] : 1.0;
        $te_fx      = mysqli_real_escape_string($conn, $s['_text_fx']          ?? 'none');
        $te_col     = mysqli_real_escape_string($conn, $s['_text_fx_col']      ?? '#ffffff');
        $cap_style  = mysqli_real_escape_string($conn, $s['caption_style']     ?? 'none');
        $cap_pos    = mysqli_real_escape_string($conn, $s['caption_position']  ?? 'bottom');
        $disp_mode  = mysqli_real_escape_string($conn, $s['display_mode']      ?? 'full');
        $px         = $px_override >= 0 ? $px_override : (int)($s['position_x'] ?? 50);
        $py         = $py_override >= 0 ? $py_override : (int)($s['position_y'] ?? 200);
        $pw         = $pw_override >= 0 ? $pw_override : min((int)($s['width']  ?? 500), 350);
        $te_safe    = mysqli_real_escape_string($conn, $text);

        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
             text_align, text_align_v, bg_color, bg_enabled,
             stroke_color, stroke_width, stroke_enabled,
             shadow_color, gradient_color,
             text_effects, text_effect_colors,
             panning_zooming_type, panning_zooming_speed,
             position_x, position_y, width, rotation,
             animation_style, animation_speed,
             caption_style, caption_position, display_mode,
             text_decoration, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, '$cap_type', '$cap_name', '$te_safe',
             '$ff', $fs, '$fc', '$fw', '$fst', $uline,
             '$talign', '$talign_v', '$bgc', $bge,
             '$stroke_c', $stroke_w, $stroke_e,
             '$shadow_c', '$grad_c',
             '$te_fx', '$te_col',
             0, 0,
             $px, $py, $pw, 0,
             '$anim_style', $anim_spd,
             '$cap_style', '$cap_pos', '$disp_mode',
             'none', 1, $z)";

        $ok = mysqli_query($conn, $sql);
        if (!$ok) p2log("doInsert FAIL $cap_type/$cap_name: " . mysqli_error($conn));
        return (bool)$ok;
    };

    // ── 1. Main caption — first sentence / max 10 words ───
    if ((int)($cap['is_enabled'] ?? 1)) {
        $clean        = trim(preg_replace('/<break[^>]*>/i', '', $scene_text));
        $caption_text = $clean;
        if (preg_match('/^(.+?[.!?])\s+\S/u', $clean, $m)) {
            $first = trim($m[1]);
            $wds   = preg_split('/\s+/', $first, -1, PREG_SPLIT_NO_EMPTY);
            if (count($wds) > 12) $first = implode(' ', array_slice($wds, 0, 10)) . '…';
            $caption_text = $first;
        } else {
            $wds = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
            if (count($wds) > 12) $caption_text = implode(' ', array_slice($wds, 0, 10)) . '…';
        }
        if ($doInsert('caption', 'main', $caption_text, $cap, 1)) $rows++;
    }

    // ── 2. Header ─────────────────────────────────────────
    if ((int)($hdr['is_enabled'] ?? 0)) {
        $ht = trim($hdr['caption_text'] ?? '');
        if ($doInsert('header', 'header', $ht, $hdr, 2, -1, 16, -1,
                min((int)($hdr['width'] ?? 1080), 350))) $rows++;
    }

    // ── 3. Footer ─────────────────────────────────────────
    if ((int)($ftr['is_enabled'] ?? 0)) {
        $ft = trim($ftr['caption_text'] ?? '');
        if ($doInsert('footer', 'footer', $ft, $ftr, 3, -1, 555, -1,
                min((int)($ftr['width'] ?? 1080), 350))) $rows++;
    }

    // ── 4. Logo ───────────────────────────────────────────
    $logo         = $user_settings_all['logo'] ?? [];
    $logo_enabled = (int)($logo['logo_enabled'] ?? 0);
    $logo_file    = trim($logo['logo_name'] ?? $logo['logo_file'] ?? $cap['logo_name'] ?? '');
    if ($logo_enabled && $logo_file) {
        $lf    = mysqli_real_escape_string($conn, $logo_file);
        $lsize = (int)($logo['logo_size_pct'] ?? 15);
        $lpx   = (int)($logo['position_x']   ?? 960);
        $lpy   = (int)($logo['position_y']   ?? 20);
        $lw    = (int)($logo['width']        ?? min((int)(1080 * $lsize / 100), 350));
        $ok    = mysqli_query($conn,
            "INSERT INTO hdb_captions
             (podcast_id, story_id, caption_type, caption_name, text_content,
              fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
              text_align, text_align_v, bg_color, bg_enabled,
              stroke_color, stroke_width, stroke_enabled,
              shadow_color, gradient_color, text_effects, text_effect_colors,
              panning_zooming_type, panning_zooming_speed,
              position_x, position_y, width, rotation,
              animation_style, animation_speed,
              caption_style, caption_position, display_mode,
              media_type, image_file, is_visible, z_index)
             VALUES
             ($podcast_id, $story_id, 'image', 'logo', '$lf',
              'Arial', 0, '#ffffff', 'normal', 'normal', 0,
              'left', 'top', '#000000', 0,
              '#000000', 0, 0, '#000000', '#ff6600', 'none', '#ffffff',
              0, 0, $lpx, $lpy, $lw, 0,
              'none', 1.0, 'none', 'top', 'full',
              'image', '$lf', 1, 10)");
        if ($ok) $rows++;
    }

    p2log("p2_buildCaptionRows: podcast=$podcast_id story=$story_id rows=$rows");
    return $rows;
}

// ════════════════════════════════════════════════════════════
// ACTION: submit_video_job
// Submits one scene image to fal.ai Kling image-to-video
// Stores fal_request_id in hdb_podcast_stories for polling
// ════════════════════════════════════════════════════════════
if ($action === 'submit_video_job') {
    set_time_limit(60);

    $scene_id    = (int)($_POST['scene_id']    ?? 0);
    $podcast_id  = (int)($_POST['podcast_id']  ?? 0);
    $image_url   = trim($_POST['image_url']    ?? '');
    $video_prompt= trim($_POST['video_prompt'] ?? 'slow gentle push in, cinematic');
    $duration    = max(4, min(10, (int)($_POST['duration'] ?? 4)));
    $scene_index = (int)($_POST['scene_index'] ?? 0);

    if (!$scene_id || !$image_url) {
        echo json_encode(['success'=>false,'error'=>'Missing scene_id or image_url']); exit;
    }
    if (!$falApiKey) {
        echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit;
    }

    // Build absolute URL for the image if it's a relative path
    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $image_abs = (strpos($image_url, 'http') === 0) ? $image_url : $base_url . '/' . ltrim($image_url, '/');

    p2log("submit_video_job: scene=$scene_id image=$image_abs duration={$duration}s");

    // Kling 2.1 image-to-video (best quality/cost)
    $payload = json_encode([
        'image_url'   => $image_abs,
        'prompt'      => $video_prompt,
        'duration'    => (string)$duration,
        'aspect_ratio'=> '9:16',
        'cfg_scale'   => 0.5,
        'negative_prompt' => 'blurry, shaky, distorted, watermark, text, logo',
    ]);

    $ch = curl_init('https://queue.fal.run/fal-ai/kling-video/v2.1/standard/image-to-video');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Key ' . $falApiKey,
            'Content-Type: application/json',
        ],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    p2log("submit_video_job response: http=$http err=$cerr body=" . substr($res, 0, 300));

    if ($cerr || $http !== 200) {
        echo json_encode(['success'=>false,'error'=>$cerr ?: "HTTP $http: " . substr($res,0,200)]); exit;
    }

    $data       = json_decode($res, true);
    $request_id = $data['request_id'] ?? null;

    if (!$request_id) {
        echo json_encode(['success'=>false,'error'=>'No request_id in response: ' . substr($res,0,200)]); exit;
    }

    // Store request_id + mark scene as video_processing
    $resc = mysqli_real_escape_string($conn, $request_id);
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET
        fal_request_id='$resc',
        video_status='processing',
        video_model='kling_v2_standard',
        updated_at=NOW()
        WHERE id=$scene_id");

    // Update podcast status
    mysqli_query($conn, "UPDATE hdb_podcasts SET
        internal_status='video_processing', updated_at=NOW()
        WHERE id=$podcast_id");

    p2log("submit_video_job OK: scene=$scene_id request_id=$request_id");
    echo json_encode(['success'=>true,'request_id'=>$request_id,'scene_id'=>$scene_id]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: build_captions
// Writes hdb_captions rows for every scene using user settings
// ════════════════════════════════════════════════════════════
if ($action === 'build_captions') {
    $podcast_id     = (int)($_POST['podcast_id'] ?? 0);
    $req_company_id = (int)($_POST['company_id'] ?? $company_id);

    if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }

    $user_settings = p2_getUserSettings($conn, $admin_id, $req_company_id);

    $res    = mysqli_query($conn,
        "SELECT id, text_contents FROM hdb_podcast_stories
         WHERE podcast_id=$podcast_id ORDER BY seq_no ASC");
    $scenes = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $scenes[] = $r;

    if (empty($scenes)) { echo json_encode(['success'=>false,'error'=>'No scenes found']); exit; }

    mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id=$podcast_id");

    $total_rows = 0;
    foreach ($scenes as $scene) {
        $total_rows += p2_buildCaptionRows($conn, $podcast_id, (int)$scene['id'],
                            $scene['text_contents'] ?? '', $user_settings);
    }

    p2log("build_captions OK: podcast=$podcast_id scenes=" . count($scenes) . " caption_rows=$total_rows");
    echo json_encode(['success'=>true,'scenes'=>count($scenes),'caption_rows'=>$total_rows]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: generate_preview_image
// Uses fal-ai/flux-pro/v1/redux with dress image reference + pose + theme
// No FASHN — Flux generates full scene directly from dress reference
// ════════════════════════════════════════════════════════════
if ($action === 'generate_preview_image') {
    set_time_limit(180);

    $prompt         = trim($_POST['prompt']         ?? '');
    $scene_index    = (int)($_POST['scene_index']   ?? 0);
    $needs_model    = (int)($_POST['needs_model']   ?? 0);
    $model_file     = trim($_POST['model_file']     ?? '');
    $model_cat      = trim($_POST['model_cat']      ?? '');
    $pose_key       = trim($_POST['pose_key']       ?? '');
    $pose_desc      = trim($_POST['pose_desc']      ?? '');
    $camera_move    = trim($_POST['camera_move']    ?? '');
    $theme_key      = trim($_POST['theme_key']      ?? '');
    $theme_name     = trim($_POST['theme_name']     ?? '');
    $theme_location = trim($_POST['theme_location'] ?? '');
    $dress_images   = json_decode($_POST['dress_images'] ?? '[]', true) ?: [];
    $garment_path   = trim($_POST['garment_path']   ?? '');

    $primary_dress_rel = $dress_images[0] ?? $garment_path ?? '';

    if (!$prompt)    { echo json_encode(['success'=>false,'error'=>'No prompt']); exit; }
    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    $req_company_id = (int)($_POST['company_id'] ?? 0);
    if (!$req_company_id) {
        $br = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT company_id FROM hdb_promo_briefs WHERE id=" . (int)($_POST['brief_id']??0) . " AND admin_id=$admin_id LIMIT 1"));
        if ($br) $req_company_id = (int)$br['company_id'];
    }

    if (!function_exists('ensureUserMediaFolder')) require_once __DIR__ . '/user_media_setup.php';
    $umf         = ensureUserMediaFolder($admin_id, $req_company_id);
    $user_folder = $umf['rel'];
    $save_dir    = ($umf['ok'] ? $umf['path'] : __DIR__ . '/podcast_images') . '/product_previews/';
    if (!is_dir($save_dir)) @mkdir($save_dir, 0777, true);

    // ── Resolve model image ───────────────────────────────
    $model_abs = '';
    if ($model_cat && $model_file) {
        $cat_dir = __DIR__ . '/promo_models/' . $model_cat . '/';
        $m1 = $cat_dir . $model_file;
        if (file_exists($m1)) $model_abs = $m1;
        // Try thumbnails folder
        if (!$model_abs) {
            $m2 = __DIR__ . '/promo_models/thumbnails/' . $model_file;
            if (file_exists($m2)) $model_abs = $m2;
        }
    }

    // ── Resolve dress image ───────────────────────────────
    $dress_abs = '';
    if ($primary_dress_rel) {
        foreach ([
            $primary_dress_rel,
            __DIR__ . '/' . ltrim($primary_dress_rel, '/'),
            $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($primary_dress_rel, '/'),
        ] as $c) {
            if (file_exists($c) && filesize($c) > 100) { $dress_abs = $c; break; }
        }
    }

    // ── Fetch pose from DB if not passed ─────────────────
    if (!$pose_desc && $pose_key) {
        $pk = mysqli_real_escape_string($conn, $pose_key);
        $pr = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT pose_description, camera_movement FROM hdb_promo_poses WHERE pose_key='$pk' LIMIT 1"));
        if ($pr) {
            $pose_desc   = $pr['pose_description'];
            $camera_move = $camera_move ?: $pr['camera_movement'];
        }
    }

    // ── Use pre-computed dress description (analyzed once in generate_scene_plan) ──
    $dress_desc = trim($_POST['dress_desc'] ?? '');

    // Only analyze if not passed (fallback)
    if (!$dress_desc && $dress_abs && $apiKey) {
        $img_data_d = @file_get_contents($dress_abs);
        if ($img_data_d) {
            $b64_d = base64_encode($img_data_d);
            $vch   = curl_init("https://api.openai.com/v1/chat/completions");
            curl_setopt_array($vch, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
                CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer $apiKey"],
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode([
                    "model"=>"gpt-4o-mini", "max_tokens"=>150,
                    "messages"=>[[
                        "role"=>"user",
                        "content"=>[
                            ["type"=>"image_url","image_url"=>["url"=>"data:image/jpeg;base64,{$b64_d}","detail"=>"low"]],
                            ["type"=>"text","text"=>"Describe this dress in 2-3 sentences: color, fabric, embroidery/embellishments, silhouette, cultural style."]
                        ]
                    ]]
                ]),
            ]);
            $vres = curl_exec($vch);
            curl_close($vch);
            $vj = json_decode($vres, true);
            $dress_desc = trim($vj['choices'][0]['message']['content'] ?? '');
        }
    }

    // ── Location context ─────────────────────────────────
    $location_ctx = $theme_location ?: $theme_name;

    // ── Helper: call fal.ai Flux with retry ──────────────
    function p2_flux_call($falApiKey, $endpoint, $payload, $max_retries = 2) {
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            if ($attempt > 0) { sleep(3); p2log("FLUX retry attempt $attempt"); }
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Key ' . $falApiKey,
                    'Content-Type: application/json',
                ],
            ]);
            $res  = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($http === 402) return ['error'=>'insufficient_credits']; // don't retry billing
            if ($cerr || $http !== 200) { p2log("FLUX attempt $attempt failed: http=$http err=$cerr"); continue; }

            $data    = json_decode($res, true);
            $img_url = $data['images'][0]['url'] ?? $data['output']['images'][0]['url'] ?? null;

            // Poll if queued
            if (!$img_url && isset($data['request_id'])) {
                $rid = $data['request_id'];
                $ep_short = str_replace('https://fal.run/', '', $endpoint);
                for ($t = 0; $t < 25; $t++) {
                    sleep(3);
                    $pch = curl_init("https://queue.fal.run/{$ep_short}/requests/{$rid}");
                    curl_setopt_array($pch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Key '.$falApiKey]]);
                    $pres  = curl_exec($pch);
                    $phttp = curl_getinfo($pch, CURLINFO_HTTP_CODE);
                    curl_close($pch);
                    if ($phttp === 200) {
                        $pd = json_decode($pres, true);
                        $img_url = $pd['images'][0]['url'] ?? $pd['output']['images'][0]['url'] ?? null;
                        if ($img_url) break;
                        if (($pd['status']??'') === 'FAILED') break;
                    }
                }
            }

            if ($img_url) return ['url' => $img_url];
        }
        return ['error' => 'failed_after_retries'];
    }

    // ── Helper: download image to local file ─────────────
    function p2_download_image($url, $save_path) {
        if (strpos($url, 'data:') === 0) {
            $parts = explode(',', $url, 2);
            return isset($parts[1]) && file_put_contents($save_path, base64_decode($parts[1])) !== false;
        }
        $data = @file_get_contents($url);
        return $data && file_put_contents($save_path, $data) !== false;
    }

    $final_image_path = '';
    $final_image_url  = '';

    if ($needs_model) {
        // STEP 1 — Reuse pre-generated try-on from generate_scene_plan
        // Kolors was run ONCE — same dressed model reused for all 7 scenes
        $step1_url = trim($_POST['tryon_url'] ?? '');
        $brief_id_ts = (int)($_POST['brief_id'] ?? 0);
        if (!$step1_url && $brief_id_ts) {
            $step1_url = $_SESSION['promo_tryon_url_' . $brief_id_ts] ?? '';
        }
        p2log("generate_preview: scene=$scene_index tryon=" . ($step1_url?'YES':'MISSING'));

        // STEP 2 — Bria Background Replace
        // Removes studio background, replaces with theme location
        // ════════════════════════════════════════════════
        if ($step1_url) {
            $step1_local = $save_dir . 'step1_' . $admin_id . '_s' . $scene_index . '_' . time() . '.jpg';
            p2_download_image($step1_url, $step1_local);

            if (file_exists($step1_local) && filesize($step1_local) > 1000) {
                $step1_b64 = base64_encode(file_get_contents($step1_local));
                $bg_prompt = $location_ctx ?: "Elegant luxury indoor setting with soft golden lighting, bokeh background";
                if ($pose_desc) $bg_prompt .= ". " . $pose_desc;

                $ch = curl_init('https://fal.run/fal-ai/bria/background/replace');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>120, CURLOPT_CONNECTTIMEOUT=>15,
                    CURLOPT_POSTFIELDS=>json_encode(['image_url'=>'data:image/jpeg;base64,'.$step1_b64,'bg_prompt'=>$bg_prompt,'fast'=>false,'sync'=>true]),
                    CURLOPT_HTTPHEADER=>['Authorization: Key '.$falApiKey,'Content-Type: application/json'],
                ]);
                $bres  = curl_exec($ch);
                $bhttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $berr  = curl_error($ch);
                curl_close($ch);
                p2log("Bria bg replace: scene=$scene_index http=$bhttp err=$berr");

                $bg_url = null;
                if (!$berr && $bhttp === 200) {
                    $bdata  = json_decode($bres, true);
                    $bg_url = $bdata['image']['url'] ?? $bdata['images'][0]['url'] ?? $bdata['output']['image']['url'] ?? null;
                    if (!$bg_url && isset($bdata['request_id'])) {
                        $rid = $bdata['request_id'];
                        for ($t = 0; $t < 20; $t++) {
                            sleep(3);
                            $pch = curl_init("https://queue.fal.run/fal-ai/bria/background/replace/requests/{$rid}");
                            curl_setopt_array($pch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Key '.$falApiKey]]);
                            $pres = curl_exec($pch); $phttp = curl_getinfo($pch,CURLINFO_HTTP_CODE); curl_close($pch);
                            if ($phttp===200) {
                                $pd = json_decode($pres, true);
                                $bg_url = $pd['image']['url'] ?? $pd['images'][0]['url'] ?? null;
                                if ($bg_url) break;
                                if (($pd['status']??'')=== 'FAILED') break;
                            }
                        }
                    }
                }

                @unlink($step1_local);
                if ($bg_url) {
                    $final_image_url = $bg_url;
                    p2log("Bria bg replace OK: scene=$scene_index");
                } else {
                    p2log("Bria bg replace FAIL: scene=$scene_index — using step1");
                    $final_image_url = $step1_url;
                }
            } else {
                $final_image_url = $step1_url;
            }
        }

        // Fallback: if both steps failed, use plain text-to-image
        if (!$final_image_url) {
            p2log("Both steps failed scene=$scene_index — falling back to t2i");
            $dress_instruction = $dress_desc
                ? "She is wearing: {$dress_desc}."
                : "She is wearing an ornate South Asian bridal lehenga with heavy gold embroidery.";
            $model_appearance = 'South Asian Pakistani woman, ';

            $t2i_result = p2_flux_call($falApiKey, 'https://fal.run/fal-ai/flux-pro/v1.1', [
                'prompt' => "Ultra-realistic fashion editorial of a beautiful {$model_appearance}late 20s. " .
                    ($pose_desc ? "Pose: {$pose_desc}. " : "") .
                    ($location_ctx ? "Setting: {$location_ctx}. " : "") .
                    $dress_instruction . " Cinematic 9:16 portrait, luxury fashion photography, golden light.",
                'negative_prompt' => 'text, watermark, blurry, deformed, short dress, western clothes',
                'image_size'          => ['width'=>576,'height'=>1024],
                'num_inference_steps' => 35,
                'guidance_scale'      => 4.5,
                'num_images'          => 1,
                'output_format'       => 'jpeg',
                'safety_tolerance'    => '2',
                'sync_mode'           => true,
            ], 2);
            if (isset($t2i_result['url'])) $final_image_url = $t2i_result['url'];
        }

    } else {
        // ── No model — product scene ──────────────────────
        $clean = preg_replace('/\b(call to action|CTA|buy now|shop now|whatsapp|phone|contact)\b/i', '', $prompt);
        $t2i_result = p2_flux_call($falApiKey, 'https://fal.run/fal-ai/flux-pro/v1.1', [
            'prompt'              => trim($clean) . ($location_ctx ? ". Setting: {$location_ctx}" : ''),
            'negative_prompt'     => 'text, words, watermark, logo, blurry, low quality, deformed',
            'image_size'          => ['width'=>576,'height'=>1024],
            'num_inference_steps' => 28,
            'guidance_scale'      => 3.5,
            'num_images'          => 1,
            'output_format'       => 'jpeg',
            'safety_tolerance'    => '2',
            'sync_mode'           => true,
        ], 2);
        if (isset($t2i_result['url'])) $final_image_url = $t2i_result['url'];
        if (isset($t2i_result['error']) && $t2i_result['error'] === 'insufficient_credits') {
            echo json_encode(['success'=>false,'error'=>'Insufficient fal.ai credits']); exit;
        }
    }

    if (!$final_image_url) {
        p2log("ALL generation failed: scene=$scene_index");
        echo json_encode(['success'=>false,'error'=>'Image generation failed after retries — check fal.ai key and balance']); exit;
    }

    // ── Save final image locally ──────────────────────────
    $filename   = 'preview_' . $admin_id . '_s' . $scene_index . '_' . time() . '.jpg';
    $local_path = $save_dir . $filename;
    if (p2_download_image($final_image_url, $local_path) && file_exists($local_path) && filesize($local_path) > 1000) {
        p2log("preview saved: scene=$scene_index file=$filename size=" . filesize($local_path));
        echo json_encode([
            'success'    => true,
            'image_url'  => $user_folder . '/product_previews/' . $filename,
            'image_path' => $user_folder . '/product_previews/' . $filename,
            'type'       => $needs_model ? 'flux_redux_2step' : 'flux_t2i',
        ]);
    } else {
        // Return CDN URL as fallback
        echo json_encode(['success'=>true,'image_url'=>$final_image_url,'image_path'=>$final_image_url,'type'=>'flux_cdn']);
    }
    exit;
}


// ════════════════════════════════════════════════════════════
// ACTION: save_ai_scene_image
// 1. Downloads preview image → saves to podcast_images/
// 2. Updates hdb_podcast_stories: image_file, image_folder, videogen_flag=1
// 3. Inserts into hdb_image_gen_queue: gen_mode=fal_ai, videogen_flag=1
// Cron job picks up queue and fires Kling video generation
// ════════════════════════════════════════════════════════════
if ($action === 'save_ai_scene_image') {
    $scene_id    = (int)($_POST['scene_id']    ?? 0);
    $podcast_id  = (int)($_POST['podcast_id']  ?? 0);
    $seq_no      = (int)($_POST['seq_no']      ?? 1);
    $image_url   = trim($_POST['image_url']    ?? '');
    $video_prompt= trim($_POST['video_prompt'] ?? 'slow gentle push in, cinematic');
    $req_company = (int)($_POST['company_id']  ?? $company_id);

    if (!$scene_id || !$image_url) {
        echo json_encode(['success'=>false,'error'=>'Missing scene_id or image_url']); exit;
    }

    p2log("save_ai_scene_image: scene=$scene_id url=" . substr($image_url,0,100) . " podcast=$podcast_id");

    // ── Use image directly — no copy needed ───────────────
    $saved = false;
    $filename   = '';
    $img_folder = '';

    if (strpos($image_url, 'data:') === 0) {
        // base64 — save to podcast_images
        $save_dir = __DIR__ . '/podcast_images/';
        if (!is_dir($save_dir)) @mkdir($save_dir, 0777, true);
        $filename   = 'ai_promo_' . $podcast_id . '_' . $scene_id . '_' . time() . '.jpg';
        $save_path  = $save_dir . $filename;
        $img_folder = 'podcast_images';
        $parts = explode(',', $image_url, 2);
        if (isset($parts[1])) {
            $saved = (file_put_contents($save_path, base64_decode($parts[1])) !== false);
        }
    } elseif (strpos($image_url, 'http') === 0) {
        // Remote URL — download to podcast_images
        $save_dir = __DIR__ . '/podcast_images/';
        if (!is_dir($save_dir)) @mkdir($save_dir, 0777, true);
        $ext        = 'jpg';
        $url_ext    = strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($url_ext, ['jpg','jpeg','png','webp'])) $ext = $url_ext === 'jpeg' ? 'jpg' : $url_ext;
        $filename   = 'ai_promo_' . $podcast_id . '_' . $scene_id . '_' . time() . '.' . $ext;
        $save_path  = $save_dir . $filename;
        $img_folder = 'podcast_images';
        $img_data   = @file_get_contents($image_url);
        if ($img_data) $saved = (file_put_contents($save_path, $img_data) !== false);
    } else {
        // Local relative path — use as-is, file already exists
        $filename   = basename($image_url);
        $img_folder = dirname($image_url);
        $saved      = true;
        p2log("save_ai_scene_image: using existing file folder=$img_folder file=$filename");
    }

    if (!$saved) {
        p2log("save_ai_scene_image: FAIL scene=$scene_id url=" . substr($image_url,0,80));
        echo json_encode(['success'=>false,'error'=>'Could not save image']); exit;
    }
    p2log("save_ai_scene_image: saved scene=$scene_id file=$filename");

    // If scene 1, set as podcast thumbnail
    if ($seq_no === 1) {
        $fn_thumb_e = mysqli_real_escape_string($conn, $filename);
        mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='$fn_thumb_e' WHERE id=$podcast_id");
        p2log("save_ai_scene_image: set podcast thumbnail=$fn_thumb_e for podcast=$podcast_id");
    }

    // ── Update hdb_podcast_stories ─────────────────────────
    $fn_e     = mysqli_real_escape_string($conn, $filename);
    $folder_e = mysqli_real_escape_string($conn, $img_folder);
    $vp_e     = mysqli_real_escape_string($conn, $video_prompt);
    mysqli_query($conn, "UPDATE hdb_podcast_stories SET
        image_file='$fn_e', image_folder='$folder_e',
        video_prompt='$vp_e', videogen_flag=1
        WHERE id=$scene_id");

    // ── Insert into hdb_image_gen_que (existing table) ────
    $sp = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT image_prompt, video_prompt, hashtags FROM hdb_podcast_stories WHERE id=$scene_id LIMIT 1"));
    // Build rich cinematic image-to-video prompt for Kling
    $scene_bg    = trim($sp['image_prompt'] ?? '');
    $scene_video = trim($sp['video_prompt']  ?? '') ?: $video_prompt;

    $fal_prompt = "A cinematic fashion video featuring a model wearing the exact outfit shown in the reference image. " .
        ($scene_bg ? "Scene: {$scene_bg}. " : "") .
        ($scene_video ? "{$scene_video}. " : "The model moves gracefully and confidently. ") .
        "The fabric flows naturally with the model's movement, catching light beautifully. " .
        "Camera is smooth cinematic tracking shot with shallow depth of field, background softly blurred. " .
        "Soft golden studio lighting with subtle glow highlighting fabric textures and embroidery details. " .
        "The model's outfit must remain EXACTLY as shown in the reference image — same colors, same design, same embroidery. " .
        "Ultra realistic, high fashion editorial style, 4K cinematic quality, smooth slow motion, elegant luxurious mood.";

    $fal_prompt_e = mysqli_real_escape_string($conn, $fal_prompt);
    $vp2_e        = mysqli_real_escape_string($conn, $video_prompt);

    $gen_mode   = in_array(trim($_POST['gen_mode'] ?? 'fast'), ['fast','standard']) ? trim($_POST['gen_mode']) : 'fast';
    $queue_gen  = $gen_mode === 'fast' ? 'fal_ai' : 'modal';
    $pod_duration = max(6, min(60, (int)($_POST['duration'] ?? 6)));

    mysqli_query($conn, "INSERT INTO hdb_video_gen_que
        (podcast_id, scene_id, prompt, video_folder, media_type, video_file,
         videogen_flag, gen_mode, duration, created_at, updated_at)
        VALUES
        ($podcast_id, $scene_id, '$fal_prompt_e', '$folder_e', 'video', '$fn_e',
         1, '$queue_gen', $pod_duration, NOW(), NOW())");

    $queue_id = mysqli_insert_id($conn);
    p2log("save_ai_scene_image: queued scene=$scene_id queue_id=$queue_id gen_mode=fal_ai");

    echo json_encode([
        'success'   => true,
        'filename'  => $filename,
        'folder'    => $img_folder,
        'queue_id'  => $queue_id,
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: update_podcast_voice
// ════════════════════════════════════════════════════════════
if ($action === 'update_podcast_voice') {
    $podcast_id  = (int)($_POST['podcast_id']  ?? 0);
    $host_voice  = mysqli_real_escape_string($conn, trim($_POST['host_voice']  ?? 'openai:alloy'));
    $guest_voice = mysqli_real_escape_string($conn, trim($_POST['guest_voice'] ?? $host_voice));
    $rate        = (float)($_POST['rate'] ?? 1.0);

    if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }

    $ok = mysqli_query($conn,
        "UPDATE hdb_podcasts
         SET host_voice='$host_voice', guest_voice='$guest_voice',
             voice_rate=$rate, team_lead_id=$team_lead_id, updated_at=NOW()
         WHERE id=$podcast_id AND admin_id=$admin_id");

    p2log("update_podcast_voice: podcast=$podcast_id voice=$host_voice rate=$rate ok=" . ($ok?'1':'0'));
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: get_scenes
// ════════════════════════════════════════════════════════════
if ($action === 'get_scenes') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) { echo json_encode([]); exit; }

    $res    = mysqli_query($conn,
        "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC");
    $scenes = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $scenes[] = $row;
    p2log("get_scenes: podcast=$podcast_id rows=" . count($scenes));
    echo json_encode($scenes);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: update_scene_tags
// ════════════════════════════════════════════════════════════
if ($action === 'update_scene_tags') {
    $scene_id     = (int)($_POST['scene_id']    ?? 0);
    $nl_tags      = mysqli_real_escape_string($conn, $_POST['nl_tags']      ?? '');
    $hashtags     = mysqli_real_escape_string($conn, $_POST['hashtags']     ?? '');
    $prompt       = mysqli_real_escape_string($conn, $_POST['prompt']       ?? '');
    $video_prompt = mysqli_real_escape_string($conn, $_POST['video_prompt'] ?? '');

    if (!$scene_id) { echo json_encode(['success'=>false,'error'=>'Missing scene_id']); exit; }

    $extra = '';
    if ($prompt)       $extra .= ", prompt='$prompt'";
    if ($video_prompt) $extra .= ", video_prompt='$video_prompt'";

    $ok = mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET
             natural_language_tags='$nl_tags', hashtags='$hashtags' $extra
         WHERE id=$scene_id");

    p2log("update_scene_tags: scene=$scene_id ok=" . ($ok?'1':'0'));
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: generate_scene_audio
// Uses same approach as wizard_step2 — proven on thousands of videos
// ════════════════════════════════════════════════════════════
if ($action === 'generate_scene_audio') {
    $_t_audio = microtime(true);

    $scene_id   = (int)($_POST['scene_id']   ?? 0);
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $seq_no     = (int)($_POST['seq_no']     ?? 1);
    $voice_id   = trim($_POST['voice_id']    ?? 'openai:alloy');
    $rate       = $_POST['rate']             ?? '1.0';
    $text       = trim($_POST['text']        ?? '');
    $lang_code  = trim($_POST['lang_code']   ?? 'en');

    if (!$text)     { if (ob_get_level()) ob_clean(); echo json_encode(['success'=>false,'error'=>'No text']);     exit; }
    if (!$scene_id) { if (ob_get_level()) ob_clean(); echo json_encode(['success'=>false,'error'=>'No scene_id']); exit; }
    if (!$apiKey)   { if (ob_get_level()) ob_clean(); echo json_encode(['success'=>false,'error'=>'No API key']);  exit; }

    $audio_dir = __DIR__ . '/podcast_audios/';
    if (!is_dir($audio_dir))    @mkdir($audio_dir, 0777, true);
    if (!is_writable($audio_dir)) @chmod($audio_dir, 0777);

    $filename = 'voice_' . $podcast_id . '_' . $scene_id . '_' . $lang_code . '.mp3';
    $filepath = $audio_dir . $filename;

    p2log("audio START: scene=$scene_id voice=$voice_id chars=" . strlen($text));

    // Same logic as wizard_step2 — gpt-4o-mini-tts at 1.0, tts-1 otherwise
    $voice    = strpos($voice_id, ':') !== false ? substr($voice_id, strpos($voice_id,':')+1) : 'alloy';
    if (empty(trim($voice))) $voice = 'alloy';
    $speed    = max(0.25, min(4.0, (float)$rate));
    $use_speed= (abs($speed - 1.0) > 0.01);
    $model    = $use_speed ? 'tts-1' : 'gpt-4o-mini-tts';
    $params   = ['model'=>$model, 'voice'=>$voice, 'input'=>$text];
    if ($use_speed) $params['speed'] = $speed;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.openai.com/v1/audio/speech',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response  = curl_exec($ch);
    $http      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    p2log("audio API: scene=$scene_id http=$http model=$model elapsed=" . round((microtime(true)-$_t_audio),2) . "s");

    if ($curl_err || $http !== 200) {
        $err = $curl_err ?: 'HTTP '.$http.': '.substr($response,0,200);
        p2log("audio FAIL: scene=$scene_id err=$err");
        if (ob_get_level()) ob_clean();
        echo json_encode(['success'=>false,'error'=>$err]); exit;
    }

    $bytes = @file_put_contents($filepath, $response);
    if ($bytes === false || $bytes === 0) {
        $why = !is_writable($audio_dir) ? "dir not writable ($audio_dir)" : 'file_put_contents failed';
        p2log("audio WRITE FAIL: scene=$scene_id $why");
        if (ob_get_level()) ob_clean();
        echo json_encode(['success'=>false,'error'=>'Could not save audio: '.$why]); exit;
    }

    $fsize = (int)filesize($filepath);
    if ($fsize === 0) {
        p2log("audio EMPTY: scene=$scene_id file=$filename");
        if (ob_get_level()) ob_clean();
        echo json_encode(['success'=>false,'error'=>'Audio file empty after write']); exit;
    }

    // Estimate duration
    $duration = round(($fsize * 8) / (128 * 1000), 1);
    $ve = mysqli_real_escape_string($conn, $voice_id);
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET audio_file='$filename', voice_id='$ve', voice_rate=$speed, duration=$duration
         WHERE id=$scene_id");

    p2log("audio OK: scene=$scene_id file=$filename size={$fsize}B dur={$duration}s total=" . round((microtime(true)-$_t_audio),2) . "s");
    if (ob_get_level()) ob_clean();
    echo json_encode(['success'=>true,'filename'=>$filename,'file_url'=>'podcast_audios/'.$filename,'duration'=>$duration]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: search_images_batch
// ════════════════════════════════════════════════════════════
if ($action === 'search_images_batch') {
    ini_set('memory_limit', '512M');

    $scenes_raw      = $_POST['scenes']       ?? '[]';
    $podcast_id      = (int)($_POST['podcast_id']   ?? 0);
    $slots_needed    = max(1, min(10, (int)($_POST['slots'] ?? 1)));
    $scenes_input    = json_decode($scenes_raw, true);
    $req_company_id  = (int)($_POST['company_id'] ?? $company_id);
    $raw_media       = trim($_POST['media_type'] ?? 'stock_videos');

    $media_type_filter = '';
    if (strpos($raw_media, 'video') !== false)     $media_type_filter = 'video';
    elseif (strpos($raw_media, 'image') !== false) $media_type_filter = 'image';

    if (!is_array($scenes_input) || empty($scenes_input)) {
        echo json_encode(['podcast_id'=>$podcast_id,'results'=>[]]); exit;
    }

    if (!function_exists('searchAssets')) {
        p2log("searchAssets() not available — image_search_functions.php missing");
        echo json_encode(['success'=>false,'error'=>'searchAssets not available','results'=>[]]); exit;
    }

    // Dedup: files used in last 10 videos
    $recently_used = [];
    $scope_id      = $team_lead_id > 0 ? $team_lead_id : $admin_id;
    $rr = mysqli_query($conn,
        "SELECT id FROM hdb_podcasts
         WHERE (admin_id=$scope_id OR team_lead_id=$scope_id) AND id!=$podcast_id
           AND video_status NOT IN ('ARCHIVED') ORDER BY id DESC LIMIT 10");
    $rids = [];
    if ($rr) while ($r = mysqli_fetch_assoc($rr)) $rids[] = (int)$r['id'];
    if (!empty($rids)) {
        $id_csv  = implode(',', $rids);
        $ur = mysqli_query($conn,
            "SELECT DISTINCT image_file FROM hdb_podcast_stories
             WHERE podcast_id IN ($id_csv) AND image_file IS NOT NULL AND image_file!=''");
        if ($ur) while ($r = mysqli_fetch_assoc($ur)) $recently_used[] = $r['image_file'];
        $recently_used = array_unique($recently_used);
    }

    // In-build dedup table
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_build_used_files (
        podcast_id INT NOT NULL, filename VARCHAR(500) NOT NULL,
        PRIMARY KEY (podcast_id, filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $used_filenames = [];
    if ($podcast_id) {
        $uf = mysqli_query($conn, "SELECT filename FROM hdb_build_used_files WHERE podcast_id=$podcast_id");
        if ($uf) while ($r = mysqli_fetch_assoc($uf)) $used_filenames[] = $r['filename'];
    }

    $results         = [];
    $embedding_cache = []; // cache embedding vectors per unique query

    $t_start = microtime(true);

    // ── Get company's promo_group from hdb_companies ──────
    $promo_group = '';
    if ($req_company_id > 0) {
        $cg = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ai_group FROM hdb_companies WHERE id=$req_company_id LIMIT 1"));
        if ($cg) $promo_group = trim($cg['ai_group'] ?? '');
    }
    $promo_group_esc = mysqli_real_escape_string($conn, $promo_group);
    $promo_group_lc  = mysqli_real_escape_string($conn, strtolower($promo_group));
    p2log("search_images_batch: promo_group='$promo_group' company=$req_company_id scenes=" . count($scenes_input));

    // ── Pre-load asset vectors — two tiers ────────────────
    // Tier 1: company-owned assets matching promo_group
    // Tier 2: shared stock (company_id=0) matching promo_group
    // Load both images AND videos — videos get score boost in ranking
    $media_clause = ''; // no type filter — include both

    // Use IN() instead of LOWER() to allow index use
    $pg_clause = $promo_group ? "AND promo_group IN ('$promo_group_esc','$promo_group_lc')" : '';

    function load_promo_assets($conn, $company_id, $pg_clause, $media_clause, $podcast_id) {
        $excl = $podcast_id > 0
            ? "AND image_name NOT IN (SELECT image_file FROM hdb_podcast_stories WHERE podcast_id=$podcast_id AND image_file!='' AND image_file IS NOT NULL)"
            : '';
        // Note: embedding is longtext — reading it is the bottleneck
        // Use straight SELECT with LIMIT to avoid full table scan
        $sql = "SELECT id, image_name, natural_language_tags, media_type, embedding, thumbnail, image_folder
                FROM hdb_image_data
                WHERE company_id=$company_id
                  AND embedding IS NOT NULL AND embedding != ''
                  AND skip_embedding=0
                  $pg_clause $media_clause $excl
                ORDER BY id ASC LIMIT 500";
        $t0  = microtime(true);
        $res = mysqli_query($conn, $sql);
        $assets = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) {
            $vec = json_decode($r['embedding'], true);
            if (!is_array($vec) || !count($vec)) continue;
            $assets[] = [
                'id'          => $r['id'],
                'filename'    => $r['image_name'],
                'media_type'  => $r['media_type'],
                'nl_tags'     => $r['natural_language_tags'],
                'thumbnail'   => $r['thumbnail'],
                'image_folder'=> $r['image_folder'] ?? '',
                'vec'         => $vec,
                'dims'        => count($vec),
            ];
        }
        $ms = round((microtime(true)-$t0)*1000);
        p2log("load_promo_assets: company=$company_id rows=" . count($assets) . " query_ms={$ms}ms");
        return $assets;
    }

    $tier1_assets = $req_company_id > 0
        ? load_promo_assets($conn, $req_company_id, $pg_clause, $media_clause, $podcast_id)
        : [];
    $tier2_assets = load_promo_assets($conn, 0, $pg_clause, $media_clause, $podcast_id);

    $t_loaded = microtime(true);
    p2log("assets loaded: tier1=" . count($tier1_assets) . " tier2=" . count($tier2_assets) . " load_time=" . round(($t_loaded-$t_start)*1000) . "ms");

    foreach ($scenes_input as $idx => $scene) {
        $query        = trim($scene['query']   ?? '');
        $scene_id     = (int)($scene['scene_id'] ?? 0);
        $nl_tags      = trim($scene['nl_tags'] ?? $query);
        $search_query = $nl_tags ?: $query;

        if (empty($search_query)) {
            $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
        }

        // Get embedding — reuse cached if same query
        $cache_key = md5(strtolower($search_query));
        if (!isset($embedding_cache[$cache_key])) {
            if (!function_exists('getEmbeddingForSearch')) {
                p2log("ERROR: getEmbeddingForSearch not available");
                $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
            }
            $vec = getEmbeddingForSearch($search_query, $apiKey);
            $embedding_cache[$cache_key] = $vec;
        } else {
            $vec = $embedding_cache[$cache_key];
        }

        if (!$vec) {
            p2log("embedding failed for scene $idx");
            $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
        }

        $dims = count($vec);

        // Score assets — cosine similarity
        $scored = [];
        $all_assets = array_merge(
            array_map(fn($a) => array_merge($a, ['tier'=>1]), $tier1_assets),
            array_map(fn($a) => array_merge($a, ['tier'=>2]), $tier2_assets)
        );
        foreach ($all_assets as $asset) {
            if ($asset['dims'] !== $dims) continue;
            // Cosine similarity
            $dot=0.0; $na=0.0; $nb=0.0;
            foreach ($asset['vec'] as $k => $v) {
                $dot += $v * $vec[$k];
                $na  += $v * $v;
                $nb  += $vec[$k] * $vec[$k];
            }
            $score = ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
            if ($score < 0.25) continue;

            // Boost video score by 15% so videos rank above equally-scored images
            $is_vid = strpos(strtolower($asset['media_type'] ?? ''), 'video') !== false;
            if ($is_vid) $score = min(1.0, $score * 1.15);

            $scored[] = [
                'filename'     => $asset['filename'],
                'media_type'   => $asset['media_type'],
                'type'         => $asset['media_type'],
                'thumbnail'    => $asset['thumbnail'],
                'image_folder' => $asset['image_folder'] ?? '',
                'score'        => round($score, 4),
                'tier'         => $asset['tier'],
                'matched_terms'=> '[]',
            ];
        }

        // Sort by score desc, prefer tier1, prefer video
        usort($scored, function($a, $b) {
            if ($a['tier'] !== $b['tier']) return $a['tier'] <=> $b['tier'];
            if ($b['score'] !== $a['score']) return $b['score'] <=> $a['score'];
            $av = strpos($a['media_type']??'','video')!==false ? 0 : 1;
            $bv = strpos($b['media_type']??'','video')!==false ? 0 : 1;
            return $av <=> $bv;
        });

        $found = array_slice($scored, 0, $slots_needed * 3);
        p2log("search scene $idx: query='" . substr($search_query,0,40) . "' found=" . count($found) . " top_score=" . ($found[0]['score']??0));
        $results[] = ['scene_idx'=>$idx,'query'=>$search_query,'found'=>$found];
    }

    $t_end = microtime(true);
    $stats = [
        'tier1_rows'    => count($tier1_assets),
        'tier2_rows'    => count($tier2_assets),
        'total_rows'    => count($tier1_assets) + count($tier2_assets),
        'promo_group'   => $promo_group,
        'load_ms'       => round(($t_loaded - $t_start) * 1000),
        'search_ms'     => round(($t_end - $t_loaded) * 1000),
        'total_ms'      => round(($t_end - $t_start) * 1000),
        'embedding_calls'=> count($embedding_cache),
    ];
    p2log("search DONE: total_ms={$stats['total_ms']} embedding_calls={$stats['embedding_calls']} tier1={$stats['tier1_rows']} tier2={$stats['tier2_rows']}");

    echo json_encode(['podcast_id'=>$podcast_id,'results'=>$results,'_stats'=>$stats]);
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: assign_image
// ════════════════════════════════════════════════════════════
if ($action === 'assign_image') {
    $scene_id    = (int)($_POST['scene_id']    ?? 0);
    $podcast_id  = (int)($_POST['podcast_id']  ?? 0);
    $filename    = mysqli_real_escape_string($conn, $_POST['filename']    ?? '');
    $image_field = mysqli_real_escape_string($conn, $_POST['image_field'] ?? 'image_file');

    $allowed_fields = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    if (!in_array($image_field, $allowed_fields)) $image_field = 'image_file';

    if (!$scene_id || !$filename) {
        echo json_encode(['success'=>false,'error'=>'Missing scene_id or filename']); exit;
    }

    $video_exts = ['mp4','webm','mov','avi','mkv','m4v'];
    $ext        = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $is_video   = in_array($ext, $video_exts);
    $basename     = basename($filename);
    $basename_esc = mysqli_real_escape_string($conn, $basename);

    // Use image_folder from POST if provided (passed directly from search results)
    $image_folder = trim($_POST['image_folder'] ?? '');

    // If not passed, look up from hdb_image_data
    if (!$image_folder) {
        $tq = mysqli_query($conn,
            "SELECT thumbnail, image_folder, company_id, admin_id FROM hdb_image_data
             WHERE image_name='$filename' OR image_name='$basename_esc' LIMIT 1");
        if ($tq && $tr = mysqli_fetch_assoc($tq)) {
            $image_folder = trim($tr['image_folder'] ?? '');
            if (!$image_folder && (int)($tr['company_id'] ?? 0) > 0) {
                $img_admin   = (int)$tr['admin_id'];
                $img_company = (int)$tr['company_id'];
                $img_subdir  = $is_video ? 'podcast_videos' : 'podcast_images';
                $image_folder = 'user_media/user_id_' . $img_admin . '_company_id_' . $img_company . '/' . $img_subdir;
            }
        }
    }

    // Final fallback
    if (!$image_folder) {
        $image_folder = $is_video ? 'podcast_videos' : 'podcast_images';
    }

    // Get thumbnail
    $thumb = '';
    $tq2 = mysqli_query($conn,
        "SELECT thumbnail FROM hdb_image_data WHERE image_name='$filename' OR image_name='$basename_esc' LIMIT 1");
    if ($tq2 && $tr2 = mysqli_fetch_assoc($tq2)) $thumb = mysqli_real_escape_string($conn, $tr2['thumbnail'] ?? '');

    $folder_field_map = ['image_file'=>'image_folder','image_file_1'=>'image_folder_1','image_file_2'=>'image_folder_2','image_file_3'=>'image_folder_3','image_file_4'=>'image_folder_4'];
    $folder_field     = $folder_field_map[$image_field] ?? 'image_folder';
    $folder_esc       = mysqli_real_escape_string($conn, $image_folder);

    if ($image_field === 'image_file' && $thumb) {
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET `$image_field`='$basename_esc', thumbnail='$thumb',
                 image_folder='$folder_esc', `$folder_field`='$folder_esc'
             WHERE id=$scene_id");
    } else {
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET `$image_field`='$basename_esc',
                 image_folder='$folder_esc', `$folder_field`='$folder_esc'
             WHERE id=$scene_id");
    }

    p2log("assign_image: scene=$scene_id file=$basename_esc folder=$image_folder is_video=" . ($is_video?'yes':'no') . " ok=" . ($ok?'1':'0'));

    // If this is scene 1 (seq_no=1), save as podcast thumbnail
    $seq_no = (int)($_POST['seq_no'] ?? 0);
    if ($seq_no === 1 && $ok && !$is_video) {
        $thumb_e = mysqli_real_escape_string($conn, $basename);
        mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='$thumb_e' WHERE id=$podcast_id");
        p2log("assign_image: set podcast thumbnail=$thumb_e for podcast=$podcast_id");
    }

    echo json_encode(['success'=>(bool)$ok,'thumbnail'=>$thumb,'image_folder'=>$image_folder]);
    exit;
}

// ── Unknown action ─────────────────────────────────────────
p2log("Unknown action: '$action'");
echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
exit;

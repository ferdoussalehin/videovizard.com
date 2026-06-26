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
// For AI track storyboard — generates a preview image per scene
// Sub-track A (no model): FLUX schnell from image_prompt
// Sub-track B (needs_model): FASHN try-on — model + garment
// ════════════════════════════════════════════════════════════
if ($action === 'generate_preview_image') {
    set_time_limit(120);

    $prompt       = trim($_POST['prompt']       ?? '');
    $scene_index  = (int)($_POST['scene_index'] ?? 0);
    $needs_model  = (int)($_POST['needs_model'] ?? 0);
    $model_file   = trim($_POST['model_file']   ?? '');
    $model_cat    = trim($_POST['model_cat']    ?? '');
    $model_index  = (int)($_POST['model_index'] ?? 0);
    $garment_path = trim($_POST['garment_path'] ?? '');

    if (!$prompt) { echo json_encode(['success'=>false,'error'=>'No prompt']); exit; }

    // Get company_id from POST (more reliable than session after session_write_close)
    $req_company_id = (int)($_POST['company_id'] ?? $_POST['brief_id'] ?? 0);
    if (!$req_company_id) {
        // Try to get from brief
        $brief_cid = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT company_id FROM hdb_promo_briefs WHERE id=" . (int)($_POST['brief_id']??0) . " AND admin_id=$admin_id LIMIT 1"));
        if ($brief_cid) $req_company_id = (int)$brief_cid['company_id'];
    }
    if (!$req_company_id) {
        // Fallback: get first company for this admin
        $co_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1"));
        if ($co_row) $req_company_id = (int)$co_row['id'];
    }

    if (!function_exists('ensureUserMediaFolder')) {
        require_once __DIR__ . '/user_media_setup.php';
    }
    $umf         = ensureUserMediaFolder($admin_id, $req_company_id);
    $user_folder = $umf['rel'];
    $save_dir    = ($umf['ok'] ? $umf['path'] : __DIR__ . '/podcast_images') . '/product_previews/';
    if (!is_dir($save_dir)) @mkdir($save_dir, 0777, true);

    // ── Sub-track B: FASHN try-on ─────────────────────────
    // Garment path — try multiple resolutions
    $garment_abs = '';
    if ($garment_path) {
        $candidates = [
            $garment_path,                                    // already absolute
            __DIR__ . '/' . ltrim($garment_path, '/'),        // relative to script
            $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($garment_path, '/'), // relative to web root
        ];
        foreach ($candidates as $c) {
            if (file_exists($c) && filesize($c) > 100) {
                $garment_abs = $c;
                break;
            }
        }
    }

    // Build ordered pose list for this model
    // Order: user-selected (_01.jpg) first, then pose variants p2-p6
    $model_abs = '';
    if ($model_cat) {
        $cat_dir = __DIR__ . '/promo_models/' . $model_cat . '/';

        // Extract ethnicity code from selected filename
        // Handles: female_casual_af_c1_pose_p1.jpg → af
        //          female_formal_me_01.jpg → me
        $eth_code = '';
        if ($model_file && preg_match('/_([a-z]{2})(?:_c\d)?_(?:0\d|pose)/', $model_file, $m_eth)) {
            $eth_code = $m_eth[1];
        }

        // Build ordered pose sequence for this ethnicity
        $pose_files = [];
        if ($eth_code) {
            // Try both naming conventions: _01.jpg and _pose_p1.jpg
            $p1 = $cat_dir . $model_cat . '_' . $eth_code . '_01.jpg';
            if (file_exists($p1)) $pose_files[] = $p1;

            // Pose variants — _pose_p1 through _pose_p6
            foreach (['p1','p2','p3','p4','p5','p6'] as $pc) {
                $pf = $cat_dir . $model_cat . '_' . $eth_code . '_pose_' . $pc . '.jpg';
                if (file_exists($pf)) $pose_files[] = $pf;
                // Also try with _c1_ naming: female_casual_af_c1_pose_p1.jpg
                $pf2 = $cat_dir . $model_cat . '_' . $eth_code . '_c1_pose_' . $pc . '.jpg';
                if (file_exists($pf2)) $pose_files[] = $pf2;
            }
        }

        // If we have pose files, use them in order; otherwise fall back to all files in dir
        if (!empty($pose_files)) {
            $model_abs = $pose_files[$model_index % count($pose_files)];
        } else {
            $all_imgs = glob($cat_dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
            // Pull from adjacent category if needed
            if (count($all_imgs) < 3 && strpos($model_cat, 'formal') !== false) {
                $alt_dir  = __DIR__ . '/promo_models/' . str_replace('formal','casual',$model_cat) . '/';
                $all_imgs = array_merge($all_imgs, glob($alt_dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []);
            }
            if (!empty($all_imgs)) {
                $selected_idx = 0;
                foreach ($all_imgs as $k => $f) {
                    if (basename($f) === $model_file) { $selected_idx = $k; break; }
                }
                $model_abs = $all_imgs[($selected_idx + $model_index) % count($all_imgs)];
            }
        }

        // Final fallback: user's selected model for all scenes
        if (!$model_abs && $model_file) {
            $m1 = $cat_dir . $model_file;
            if (file_exists($m1)) $model_abs = $m1;
        }
    }

    p2log("preview: needs_model=$needs_model garment_abs=$garment_abs garment_exists=" . (file_exists($garment_abs)?'YES':'NO') . " model_abs=$model_abs model_exists=" . ($model_abs&&file_exists($model_abs)?'YES':'NO') . " falKey=" . ($falApiKey?'SET':'MISSING'));

    $debug_info = [
        'needs_model'    => $needs_model,
        'garment_path'   => $garment_path,
        'garment_abs'    => $garment_abs,
        'garment_exists' => file_exists($garment_abs),
        'model_abs'      => $model_abs,
        'model_exists'   => $model_abs && file_exists($model_abs),
        'fal_key_set'    => !empty($falApiKey),
    ];

    if ($needs_model && $model_abs && $garment_abs && file_exists($garment_abs) && file_exists($model_abs) && $falApiKey) {

        $model_b64      = base64_encode(file_get_contents($model_abs));
        $garment_b64    = base64_encode(file_get_contents($garment_abs));
        $garment_mime   = mime_content_type($garment_abs) ?: 'image/jpeg';
        $model_data_url   = 'data:image/jpeg;base64,' . $model_b64;
        $garment_data_url = 'data:' . $garment_mime . ';base64,' . $garment_b64;

        $payload = json_encode([
            'model_image'            => $model_data_url,
            'garment_image'          => $garment_data_url,
            'category'               => 'one-pieces',   // full dress — never split
            'garment_photo_type'     => 'model',        // garment shown on person/mannequin
            'nsfw_filter'            => true,
            'cover_feet'             => true,
            'adjust_hands'           => false,           // preserve sleeves exactly
            'restore_background'     => false,
            'restore_clothes'        => true,            // preserve ALL garment details
            'long_top'               => true,
            'guidance_scale'         => 1.5,             // lowest = most faithful to garment
            'timestep_to_start_cfg'  => 7,              // highest adherence
            'num_inference_steps'    => 50,
            'output_format'          => 'jpeg',
            'sync_mode'              => true,
        ]);

        p2log("FASHN submit: scene=$scene_index model=" . basename($model_abs) . " garment=" . basename($garment_abs));

        $ch = curl_init('https://fal.run/fal-ai/fashn/tryon/v1.6');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
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

        p2log("FASHN response: http=$http err=$cerr body=" . substr($res, 0, 500));

        if ($http === 402) {
            p2log("FASHN FAIL: insufficient fal.ai balance — top up at fal.ai/dashboard");
            echo json_encode(['success'=>false,'error'=>'Insufficient fal.ai credits — please top up your balance at fal.ai/dashboard']); exit;
        }

        if ($http === 200) {
            $data    = json_decode($res, true);
            $img_url = $data['output']['image']['url']
                    ?? $data['image']['url']
                    ?? $data['images'][0]['url']
                    ?? null;

            if ($img_url) {
                $filename   = 'tryon_' . $admin_id . '_s' . $scene_index . '_' . time() . '.jpg';
                $local_path = $save_dir . $filename;
                $img_data   = @file_get_contents($img_url);
                if ($img_data && file_put_contents($local_path, $img_data)) {
                    p2log("FASHN OK: scene=$scene_index file=$filename");
                    echo json_encode([
                        'success'    => true,
                        'image_url'  => $user_folder . '/product_previews/' . $filename,
                        'image_path' => $user_folder . '/product_previews/' . $filename,
                        'type'       => 'tryon',
                    ]);
                    exit;
                }
            }
        }
        // Log why FASHN failed and fall through to FLUX
        p2log("FASHN FAIL scene=$scene_index http=$http cerr=$cerr — falling back to FLUX");
    } else {
        p2log("FASHN SKIP scene=$scene_index: needs_model=$needs_model model=" . ($model_abs?'ok':'missing') . " garment=" . ($garment_abs&&file_exists($garment_abs)?'ok':'missing') . " falKey=" . ($falApiKey?'ok':'MISSING'));
    }
    // Not returning here — fall through to FLUX with debug info attached

    // ── Load cultural style context from DB ───────────────
    $brief_id_for_style = (int)($_POST['brief_id'] ?? 0);
    $style_context   = '';
    $style_neg_extra = '';
    if ($brief_id_for_style) {
        $br = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT category_key, sub_category FROM hdb_promo_briefs
             WHERE id=$brief_id_for_style AND admin_id=$admin_id LIMIT 1"));
        if ($br) {
            $cat_k = mysqli_real_escape_string($conn, $br['category_key'] ?? '');
            $sub_k = mysqli_real_escape_string($conn, $br['sub_category']  ?? '');
            $ps = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT style_prefix, negative_extra FROM hdb_promo_prompt_styles
                 WHERE category_key='$cat_k'
                   AND (sub_category='$sub_k' OR sub_category IS NULL)
                   AND is_active=1
                 ORDER BY (sub_category IS NOT NULL) DESC, sort_order ASC
                 LIMIT 1"));
            if ($ps) {
                $style_context   = trim($ps['style_prefix']   ?? '');
                $style_neg_extra = trim($ps['negative_extra'] ?? '');
            }
        }
    }

    // ── Build final FLUX prompt ───────────────────────────
    // The scene prompt already has specific background — just add cultural context as suffix
    // Don't prepend the full style prefix (makes all scenes look same)
    // Instead extract just the cultural key terms
    $cultural_hint = '';
    if ($style_context) {
        // Extract first 8 words as cultural hint (e.g. "Pakistani bridal fashion photography, South Asian aesthetic")
        $words = explode(' ', $style_context);
        $cultural_hint = implode(' ', array_slice($words, 0, 10));
    }

    $clean_prompt = preg_replace('/\b(call to action|CTA|buy now|shop now|order now|whatsapp|phone|contact|text overlay|caption)\b/i', '', $prompt);
    $flux_prompt  = trim($clean_prompt) . ($cultural_hint ? ', ' . $cultural_hint : '');
    $flux_prompt  = trim($flux_prompt) ?: $prompt;

    // Negative prompt — build once, cleanly
    $flux_neg = 'text, words, letters, typography, watermark, logo, blurry, low quality, cropped, deformed, ugly, white dress, generic model, stock photo';
    if ($style_neg_extra) $flux_neg .= ', ' . $style_neg_extra;

    p2log("FLUX prompt (scene=$scene_index): " . substr($flux_prompt, 0, 120));

    if ($falApiKey) {
        // Use flux-pro/v1.1 with sync_mode for simplicity
        $ch = curl_init('https://fal.run/fal-ai/flux-pro/v1.1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_POSTFIELDS     => json_encode([
                'prompt'              => $flux_prompt,
                'image_size'          => ['width' => 576, 'height' => 1024],
                'num_inference_steps' => 28,
                'guidance_scale'      => 3.5,
                'num_images'          => 1,
                'output_format'       => 'jpeg',
                'safety_tolerance'    => '2',
                'sync_mode'           => true,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Key ' . $falApiKey,
                'Content-Type: application/json',
            ],
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        p2log("FLUX submit: scene=$scene_index http=$http err=$err body=" . substr($res,0,200));

        if ($http === 402) {
            p2log("FLUX FAIL: insufficient fal.ai balance");
            echo json_encode(['success'=>false,'error'=>'Insufficient fal.ai credits — please top up at fal.ai/dashboard']); exit;
        }

        $img_url = null;
        if (!$err && $http === 200) {
            $data = json_decode($res, true);
            // Response can be direct result or queued
            $img_url = $data['images'][0]['url']
                    ?? $data['output']['images'][0]['url']
                    ?? null;

            // If queued, poll for result
            if (!$img_url && isset($data['request_id'])) {
                $request_id = $data['request_id'];
                p2log("FLUX queued: scene=$scene_index request_id=$request_id");
                for ($t = 0; $t < 20; $t++) {
                    sleep(3);
                    $pch = curl_init("https://queue.fal.run/fal-ai/flux-pro/v1.1/requests/$request_id");
                    curl_setopt_array($pch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 20,
                        CURLOPT_HTTPHEADER     => ['Authorization: Key ' . $falApiKey],
                    ]);
                    $pres  = curl_exec($pch);
                    $phttp = curl_getinfo($pch, CURLINFO_HTTP_CODE);
                    curl_close($pch);
                    p2log("FLUX poll $t: scene=$scene_index http=$phttp body=" . substr($pres,0,100));
                    if ($phttp === 200) {
                        $pd = json_decode($pres, true);
                        $img_url = $pd['images'][0]['url'] ?? $pd['output']['images'][0]['url'] ?? null;
                        if ($img_url) break;
                        if (($pd['status'] ?? '') === 'FAILED') break;
                    }
                }
            }
        }

        if ($img_url) {
            $filename   = 'preview_ai_' . $admin_id . '_' . $scene_index . '_' . time() . '.jpg';
            $local_path = $save_dir . $filename;
            $img_data   = @file_get_contents($img_url);
            if ($img_data) {
                file_put_contents($local_path, $img_data);
                p2log("preview FLUX pro OK: scene=$scene_index file=$filename");
                echo json_encode(['success'=>true,'image_url'=>$user_folder.'/product_previews/'.$filename,'image_path'=>$user_folder.'/product_previews/'.$filename,'type'=>'flux_pro','_debug'=>$debug_info]);
            } else {
                echo json_encode(['success'=>true,'image_url'=>$img_url,'image_path'=>$img_url,'type'=>'flux_cdn','_debug'=>$debug_info]);
            }
            exit;
        }
        p2log("preview fal.ai FLUX pro FAIL: scene=$scene_index http=$http err=$err");
    }

    // Fallback: Modal/Flux (same endpoint as wizard_step2)
    if ($modalImageUrl) {
        $ch = curl_init($modalImageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_POSTFIELDS     => json_encode([
                'prompt' => $prompt,
                'style'  => 'cinematic',
                'width'  => 576,
                'height' => 1024,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!$err && $http === 200) {
            $data = json_decode($res, true);
            $b64  = $data['image'] ?? null;
            if ($b64) {
                $img_data   = base64_decode($b64);
                $filename   = 'preview_ai_' . $admin_id . '_' . $scene_index . '_' . time() . '.jpg';
                $local_path = $save_dir . $filename;
                file_put_contents($local_path, $img_data);
                p2log("preview Modal OK: scene=$scene_index file=$filename");
                echo json_encode(['success'=>true,'image_url'=>$user_folder . '/product_previews/' . $filename,'image_path'=>$user_folder . '/product_previews/' . $filename,'type'=>'modal']);
                exit;
            }
        }
        p2log("preview Modal FAIL: scene=$scene_index http=$http err=$err");
    }

    echo json_encode(['success'=>false,'error'=>'No image generator available — check fal_api_key or MODAL_IMAGE_URL in config.php']);
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
    $embedding_cache = [];
    $t_start = microtime(true);

    // ── Get company's promo_group + promo_subgroup from hdb_companies ──────
    $promo_group    = '';
    $promo_subgroup = '';
    if ($req_company_id > 0) {
        $cg = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ai_group, ai_subgroup FROM hdb_companies WHERE id=$req_company_id LIMIT 1"));
        if ($cg) {
            $promo_group    = trim($cg['ai_group']    ?? '');
            $promo_subgroup = trim($cg['ai_subgroup'] ?? '');
        }
    }
    $pg_esc = mysqli_real_escape_string($conn, $promo_group);
    $ps_esc = mysqli_real_escape_string($conn, $promo_subgroup);
    $pg_lc  = mysqli_real_escape_string($conn, strtolower($promo_group));
    $ps_lc  = mysqli_real_escape_string($conn, strtolower($promo_subgroup));

    $pg_clause = $promo_group    ? "AND promo_group    IN ('$pg_esc','$pg_lc')" : '';
    $ps_clause = $promo_subgroup ? "AND promo_subgroup IN ('$ps_esc','$ps_lc')" : '';

    p2log("search_images_batch: promo_group='$promo_group' promo_subgroup='$promo_subgroup' company=$req_company_id scenes=" . count($scenes_input));

    // ── Asset loader ─────────────────────────────────────────────────────────
    function load_promo_assets($conn, $company_id, $pg_clause, $ps_clause, $media_clause, $podcast_id) {
        $excl = $podcast_id > 0
            ? "AND image_name NOT IN (SELECT image_file FROM hdb_podcast_stories WHERE podcast_id=$podcast_id AND image_file!='' AND image_file IS NOT NULL)"
            : '';
        $sql = "SELECT id, image_name, natural_language_tags, media_type, embedding, thumbnail, image_folder
                FROM hdb_image_data
                WHERE company_id=$company_id
                  AND embedding IS NOT NULL AND embedding != ''
                  AND skip_embedding=0
                  $pg_clause $ps_clause $media_clause $excl
                ORDER BY id ASC LIMIT 500";
        $t0  = microtime(true);
        $res = mysqli_query($conn, $sql);
        $assets = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) {
            $vec = json_decode($r['embedding'], true);
            if (!is_array($vec) || !count($vec)) continue;
            $assets[] = [
                'id'           => $r['id'],
                'filename'     => $r['image_name'],
                'media_type'   => $r['media_type'],
                'nl_tags'      => $r['natural_language_tags'],
                'thumbnail'    => $r['thumbnail'],
                'image_folder' => $r['image_folder'] ?? '',
                'vec'          => $vec,
                'dims'         => count($vec),
            ];
        }
        p2log("load_promo_assets: company=$company_id rows=" . count($assets) . " ms=" . round((microtime(true)-$t0)*1000));
        return $assets;
    }

    // ── 4-Pool loading ───────────────────────────────────────────────────────
    // Pool 1: company videos + group + subgroup
    $pool1_assets = $req_company_id > 0
        ? load_promo_assets($conn, $req_company_id, $pg_clause, $ps_clause, "AND media_type='video'", $podcast_id)
        : [];
    // Pool 2: company images + group + subgroup
    $pool2_assets = $req_company_id > 0
        ? load_promo_assets($conn, $req_company_id, $pg_clause, $ps_clause, "AND media_type='image'", $podcast_id)
        : [];
    // Pool 3: shared stock videos + group + subgroup
    $pool3_assets = load_promo_assets($conn, 0, $pg_clause, $ps_clause, "AND media_type='video'", $podcast_id);
    // Pool 4: shared stock images + group + subgroup
    $pool4_assets = load_promo_assets($conn, 0, $pg_clause, $ps_clause, "AND media_type='image'", $podcast_id);

    $t_loaded = microtime(true);
    p2log("assets: pool1(co_vid)=" . count($pool1_assets) . " pool2(co_img)=" . count($pool2_assets) . " pool3(sh_vid)=" . count($pool3_assets) . " pool4(sh_img)=" . count($pool4_assets) . " load_ms=" . round(($t_loaded-$t_start)*1000));

    if (!count($pool1_assets) && !count($pool2_assets) && !count($pool3_assets) && !count($pool4_assets)) {
        p2log("WARNING: No assets found for promo_group='$promo_group' promo_subgroup='$promo_subgroup' — will AI generate");
    }

    // ── Score helper ─────────────────────────────────────────────────────────
    function p2_score_pool($pool, $vec, $dims, $pool_num) {
        $scored = [];
        foreach ($pool as $asset) {
            if ($asset['dims'] !== $dims) continue;
            $dot=0.0; $na=0.0; $nb=0.0;
            foreach ($asset['vec'] as $k => $v) {
                $dot += $v * $vec[$k];
                $na  += $v * $v;
                $nb  += $vec[$k] * $vec[$k];
            }
            $score = ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
            if ($score < 0.25) continue;
            $scored[] = [
                'filename'     => $asset['filename'],
                'media_type'   => $asset['media_type'],
                'type'         => $asset['media_type'],
                'thumbnail'    => $asset['thumbnail'],
                'image_folder' => $asset['image_folder'] ?? '',
                'score'        => round($score, 4),
                'tier'         => $pool_num,
                'matched_terms'=> '[]',
            ];
        }
        usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    foreach ($scenes_input as $idx => $scene) {
        $query        = trim($scene['query']    ?? '');
        $scene_id     = (int)($scene['scene_id'] ?? 0);
        $nl_tags      = trim($scene['nl_tags']  ?? $query);
        $search_query = $nl_tags ?: $query;

        if (empty($search_query)) {
            $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
        }

        // Get embedding — reuse cached
        $cache_key = md5(strtolower($search_query));
        if (!isset($embedding_cache[$cache_key])) {
            if (!function_exists('getEmbeddingForSearch')) {
                p2log("ERROR: getEmbeddingForSearch not available");
                $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
            }
            $embedding_cache[$cache_key] = getEmbeddingForSearch($search_query, $apiKey);
        }
        $vec = $embedding_cache[$cache_key];

        if (!$vec) {
            p2log("embedding failed scene $idx — skipping");
            $results[] = ['scene_idx'=>$idx,'found'=>[]]; continue;
        }

        $dims = count($vec);

        // Score all 4 pools
        $p1 = p2_score_pool($pool1_assets, $vec, $dims, 1);
        $p2 = p2_score_pool($pool2_assets, $vec, $dims, 2);
        $p3 = p2_score_pool($pool3_assets, $vec, $dims, 3);
        $p4 = p2_score_pool($pool4_assets, $vec, $dims, 4);

        // Pick from pools in order — first non-empty pool wins
        $found       = [];
        $chosen_pool = 'none';
        foreach ([[$p1,1],[$p2,2],[$p3,3],[$p4,4]] as [$pool, $pn]) {
            if (empty($pool)) continue;
            $found       = array_slice($pool, 0, $slots_needed * 3);
            $chosen_pool = 'pool' . $pn;
            break;
        }

        // AI generate fallback
        if (empty($found)) {
            p2log("scene $idx: no assets in any pool — flagging AI generate");
            $found = [[
                'filename'     => '',
                'media_type'   => 'ai_generate',
                'type'         => 'ai_generate',
                'thumbnail'    => '',
                'image_folder' => '',
                'score'        => 0,
                'tier'         => 0,
                'ai_prompt'    => $search_query,
            ]];
            $chosen_pool = 'ai_generate';
        }

        p2log("scene $idx: p1=" . count($p1) . " p2=" . count($p2) . " p3=" . count($p3) . " p4=" . count($p4) . " chosen=$chosen_pool found=" . count($found));
        $results[] = ['scene_idx'=>$idx,'query'=>$search_query,'found'=>$found];
    }

    $t_end = microtime(true);
    $stats = [
        'pool1_rows'      => count($pool1_assets),
        'pool2_rows'      => count($pool2_assets),
        'pool3_rows'      => count($pool3_assets),
        'pool4_rows'      => count($pool4_assets),
        'promo_group'     => $promo_group,
        'promo_subgroup'  => $promo_subgroup,
        'load_ms'         => round(($t_loaded - $t_start) * 1000),
        'search_ms'       => round(($t_end - $t_loaded) * 1000),
        'total_ms'        => round(($t_end - $t_start) * 1000),
        'embedding_calls' => count($embedding_cache),
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

<?php
// ============================================================================
// wizard_step2_podcast.php
// Handles ALL Step 2 actions exclusively for reel_type = 'podcast'
// Actions: create_scenes_from_content, get_scenes, generate_scene_audio,
//          check_audio_file, assign_image, update_podcast_voice
//
// Scene processing order (one scene at a time):
//   1. Strip "host:" / "guest:" prefix from text
//   2. Generate audio (host or guest voice)
//   3. Look up voice gender from hdb_voices
//   4. Pick host image by gender + cycling pose
//   5. Update scene row in DB
// ============================================================================

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

// ── Logging ──────────────────────────────────────────────────────────────────
function pod_log(string $msg): void {
    error_log('[wizard_step2_avatar] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ── Safe JSON output — flushes any stray buffered output first ────────────────
function pod_json(array $data): void {
    // Discard any stray buffered output (warnings, notices from includes)
    if (ob_get_level() > 0) {
        $leaked = ob_get_clean();
        if (!empty(trim($leaked))) {
            pod_log('LEAKED OUTPUT before JSON: ' . substr(trim($leaked), 0, 500));
        }
    }
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Global exception/error handler so nothing dies silently ──────────────────
set_exception_handler(function(Throwable $e) {
    pod_log('UNCAUGHT EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    pod_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
});
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR) {
        pod_log("PHP ERROR [{$errno}]: {$errstr} in {$errfile}:{$errline}");
        pod_json(['success' => false, 'error' => "PHP error: {$errstr}"]);
    }
    pod_log("PHP WARNING [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    return true; // don't execute PHP internal error handler
});

// ── Session ───────────────────────────────────────────────────────────────────
$timeout = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $timeout,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Output buffering and JSON headers are handled by pod_json() — see above

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id'])) {
    if (!empty($_POST['admin_id'])) {
        $_SESSION['admin_id'] = (int) $_POST['admin_id'];
    } else {
        pod_json(['success' => false, 'error' => 'Not authenticated']);
    }
}

$admin_id  = (int) $_SESSION['admin_id'];
$client_id = (int) ($_SESSION['client_id'] ?? $admin_id);
session_write_close(); // Release session lock so parallel requests don't queue

// ── DB + config ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
// dbconnect_hdb.php MUST come last: config.php opens its own $conn to the
// legacy `hypnotherapy_db`, which lacks the hdb_* schema. Loading it last
// ensures $conn/$pdo point at the main `user_hypnotherapy_db2` database.
include 'dbconnect_hdb.php';

if (!$conn) {
    pod_json(['success' => false, 'error' => 'DB connection failed']);
}

// ── Resolve team_lead_id for this podcast row ────────────────────────────────
// Team Member  → use hdb_users.team_lead_id (never 0)
// Team Leader / anyone else → use their own admin_id
$_u = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, team_lead_id FROM hdb_users WHERE id = $admin_id LIMIT 1"));
if (!empty($_u) && $_u['role'] === 'Team Member' && (int)$_u['team_lead_id'] > 0) {
    $team_lead_id = (int)$_u['team_lead_id'];
} else {
    $team_lead_id = $admin_id;
}

// ── Credit deduction helper ───────────────────────────────────────────────────
// podcast / talking head = 2 credits, standard / b-roll = 1 credit
// Team Members deduct from their Team Lead's balance
function deductCredit($conn, $admin_id, $reel_type) {
    $rt   = strtolower($reel_type ?? '');
    $cost = (strpos($rt, 'podcast') !== false || strpos($rt, 'talking head') !== false) ? 2 : 1;
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $deduct_from = ($urow['role'] === 'Team Member' && (int)$urow['team_lead_id'] > 0)
        ? (int)$urow['team_lead_id']
        : $admin_id;
    mysqli_query($conn,
        "UPDATE hdb_users
         SET credit_balance = GREATEST(0, credit_balance - $cost)
         WHERE id = $deduct_from");
}

$action = $_POST['action'] ?? $_POST['ajax_action'] ?? '';
pod_log("Action: {$action} | admin={$admin_id}");


// ============================================================================
// HELPERS
// ============================================================================

// ── Clean scene text — strip actor prefix AND <break> tags ───────────────────
// Used everywhere text is saved to DB: stories, captions, audio input.
// Handles: "host: text", "guest: text", <break time="200ms"/>, <break/>
function strip_actor_prefix(string $text): string {
    // 1. Strip leading "host:" / "guest:" label
    $text = preg_replace('/^(host|guest)\s*:\s*/i', '', trim($text));
    // 2. Strip all <break> tags (Azure TTS SSML markers)
    $text = preg_replace('/<break[^>]*\/?>/i', '', $text);
    // 3. Tidy up any double spaces left behind
    $text = preg_replace('/  +/', ' ', $text);
    return trim($text);
}

// ── OpenAI TTS ────────────────────────────────────────────────────────────────
function generate_audio_openai(string $text, string $voice_id, string $filepath, string $rate = '1.0'): array {
    global $apiKey;

    $voice = strpos($voice_id, ':') !== false
        ? substr($voice_id, strpos($voice_id, ':') + 1)
        : 'alloy';
    if (empty(trim($voice))) $voice = 'alloy';

    $dir = dirname($filepath);
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    // gpt-4o-mini-tts does NOT support the speed parameter — only tts-1/tts-1-hd do.
    // Use tts-1 when speed is non-default so the param is honoured; keep gpt-4o-mini-tts at 1.0.
    $speed = max(0.25, min(4.0, (float)$rate));
    $use_speed = (abs($speed - 1.0) > 0.01);
    $model = $use_speed ? 'tts-1' : 'gpt-4o-mini-tts';
    $params = ['model' => $model, 'voice' => $voice, 'input' => $text];
    if ($use_speed) $params['speed'] = $speed;
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.openai.com/v1/audio/speech',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['success' => false, 'error' => 'cURL: ' . $curl_err];
    }
    if ($http_code !== 200) {
        $decoded = json_decode($response, true);
        return [
            'success' => false,
            'error'   => 'OpenAI TTS HTTP ' . $http_code . ': '
                       . ($decoded['error']['message'] ?? $response),
        ];
    }

    $bytes = @file_put_contents($filepath, $response);
    if ($bytes === false || $bytes === 0) {
        $dir = dirname($filepath);
        $why = !is_writable($dir) ? "directory not writable ($dir)" : 'file_put_contents failed';
        return ['success' => false, 'error' => 'Could not save audio file: ' . $why];
    }
    return ['success' => true];
}

// ── Azure TTS (delegates to chatgpt_functions.php) ────────────────────────────
function generate_audio_azure(string $text, string $voice_id, string $rate, string $filepath): array {
    if (!file_exists(__DIR__ . '/chatgpt_functions.php')) {
        return ['success' => false, 'error' => 'chatgpt_functions.php not found'];
    }
    require_once __DIR__ . '/chatgpt_functions.php';
    return generateVoice($text, $voice_id, $rate, $filepath);
}

// ── Route audio generation by voice prefix ────────────────────────────────────
function generate_audio(string $text, string $voice_id, string $rate, string $filepath): array {
    if (strpos($voice_id, 'openai:') === 0) {
        return generate_audio_openai($text, $voice_id, $filepath, $rate);
    }
    return generate_audio_azure($text, $voice_id, $rate, $filepath);
}

// ── Look up voice gender from hdb_voices ──────────────────────────────────────
function get_voice_gender(mysqli $conn, string $voice_id): string {

    // Strip 'openai:' prefix if present — DB stores just 'alloy', 'nova' etc.
    $lookup_key = strpos($voice_id, 'openai:') === 0
        ? substr($voice_id, 7)  // 'openai:alloy' → 'alloy'
        : $voice_id;            // Azure keys unchanged

    $esc    = mysqli_real_escape_string($conn, $lookup_key);
    $result = mysqli_query($conn,
        "SELECT gender FROM hdb_voices WHERE voice_key = '$esc' LIMIT 1"
    );

    if ($result && $row = mysqli_fetch_assoc($result)) {
        $g = strtolower(trim($row['gender'] ?? ''));
        if ($g === 'male' || $g === 'female') {
            pod_log("get_voice_gender: found — voice_id={$voice_id} lookup_key={$lookup_key} gender={$g}");
            return $g;
        }
    }

    pod_log("get_voice_gender: not found — voice_id={$voice_id} lookup_key={$lookup_key} defaulting to male");
    return 'male';
}

// ── Fetch all host/guest images for a gender from hdb_hostguest_images ────────
function get_hostguest_images(mysqli $conn, string $gender): array {
    $esc    = mysqli_real_escape_string($conn, $gender);
    $result = mysqli_query($conn,
        "SELECT * FROM hdb_hostguest_images
         WHERE gender = '{$esc}'
         ORDER BY id ASC"
    );
    $images = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $images[] = $row;
        }
    }
    if (empty($images)) {
        pod_log("get_hostguest_images: no images found for gender={$gender}");
    }
    return $images;
}

// ── Randomly pick a host image name, different from the previous one ──────────
// Returns the image_name (e.g. 'host_male_2') or null if not possible.
function find_host_imagename(string $prev_name, array $images_array, int $max_attempts = 20): ?string {
    if (empty($images_array)) {
        pod_log("find_host_imagename: images array is empty");
        return null;
    }
    // Only one option and it's the same as previous — can't pick different
    if (count($images_array) === 1 && $images_array[0]['image_name'] === $prev_name) {
        pod_log("find_host_imagename: only one image and matches prev_name={$prev_name}");
        return $images_array[0]['image_name']; // return it anyway, no choice
    }
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $idx      = array_rand($images_array);
        $selected = $images_array[$idx];
        if ($selected['image_name'] !== $prev_name) {
            pod_log("find_host_imagename: selected={$selected['image_name']} prev={$prev_name}");
            return $selected['image_name'];
        }
    }
    // Fallback: return first that isn't prev_name
    foreach ($images_array as $img) {
        if ($img['image_name'] !== $prev_name) return $img['image_name'];
    }
    return $images_array[0]['image_name'];
}

// ── Build full image filename: base name + pose + .png ────────────────────────
// e.g. 'host_male_2' + pose 3 → 'host_male_2_p3.png'
// Pose cycles 1→2→3→4→1
function build_pose_filename(string $base_name, int $pose): string {
    $pose_clamped = (($pose - 1) % 4) + 1;
    return "{$base_name}_p{$pose_clamped}.png";
}

// ── Verify the final filename exists in hdb_image_data ───────────────────────
function image_file_exists(mysqli $conn, string $filename): bool {
    $esc    = mysqli_real_escape_string($conn, $filename);
    $result = mysqli_query($conn,
        "SELECT id FROM hdb_image_data WHERE image_name = '{$esc}' LIMIT 1"
    );
    return ($result && mysqli_num_rows($result) > 0);
}



// ── getUserSettingsWiz ────────────────────────────────────────────────────────
function getUserSettingsWiz(mysqli $conn, int $admin_id): array {
    $default_caption = [
        'is_enabled'=>1,'fontfamily'=>'Arial','fontsize'=>28,
        'fontcolor'=>'#ffff00','fontweight'=>'bold',
        'font_italic'=>0,'font_underline'=>0,
        'fontcolor_bg'=>'#000000','fontbg_enable'=>0,
        'caption_alignment'=>'center','position_x'=>50,'position_y'=>250,
        'width'=>500,'caption_speed'=>1.0,'text_animation'=>'static',
        'text_effect'=>'none','stroke_width'=>0,'stroke_color'=>'#000000',
        'shadow_color'=>'#000000','gradient_color'=>'#ff6600',
        'display_mode'=>'full','caption_style'=>'none',
        'caption_position'=>'bottom','text_align_v'=>'bottom','caption_text'=>'',
    ];
    $default_header = [
        'is_enabled'=>0,'fontfamily'=>'Helvetica','fontsize'=>16,
        'fontcolor'=>'#ffffff','fontweight'=>'bold',
        'font_italic'=>0,'font_underline'=>0,
        'fontcolor_bg'=>'#1a1a2e','fontbg_enable'=>1,
        'caption_alignment'=>'center','position_x'=>0,'position_y'=>0,
        'width'=>1080,'caption_speed'=>1.0,'text_animation'=>'fade_in',
        'text_effect'=>'none','stroke_width'=>0,'stroke_color'=>'#000000',
        'shadow_color'=>'#000000','gradient_color'=>'#ff6600',
        'display_mode'=>'full','caption_style'=>'box',
        'caption_position'=>'top','text_align_v'=>'top','caption_text'=>'',
    ];
    $default_footer = [
        'is_enabled'=>0,'fontfamily'=>'Georgia','fontsize'=>12,
        'fontcolor'=>'#aaaaaa','fontweight'=>'normal',
        'font_italic'=>0,'font_underline'=>0,
        'fontcolor_bg'=>'#000000','fontbg_enable'=>0,
        'caption_alignment'=>'center','position_x'=>0,'position_y'=>0,
        'width'=>1080,'caption_speed'=>1.0,'text_animation'=>'static',
        'text_effect'=>'none','stroke_width'=>0,'stroke_color'=>'#000000',
        'shadow_color'=>'#000000','gradient_color'=>'#ff6600',
        'display_mode'=>'full','caption_style'=>'none',
        'caption_position'=>'bottom','text_align_v'=>'bottom','caption_text'=>'',
    ];
    $default_logo = [
        'logo_enabled'=>0,'logo_file'=>'','logo_name'=>'',
        'logo_pos_h'=>'right','logo_pos_v'=>'top',
        'logo_size_pct'=>15,'position_x'=>960,'position_y'=>20,'width'=>120,
    ];
    $result = @mysqli_query($conn,
        "SELECT * FROM hdb_user_settings WHERE admin_id = $admin_id LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        return ['caption'=>$default_caption,'header'=>$default_header,'footer'=>$default_footer,'logo'=>$default_logo];
    }
    $row = mysqli_fetch_assoc($result);
    if (!empty($row['caption_settings'])) {
        return [
            'caption' => array_merge($default_caption, json_decode($row['caption_settings'], true) ?: []),
            'header'  => array_merge($default_header,  json_decode($row['header_settings']  ?? '{}', true) ?: []),
            'footer'  => array_merge($default_footer,  json_decode($row['footer_settings']  ?? '{}', true) ?: []),
            'logo'    => array_merge($default_logo,    json_decode($row['logo_settings']    ?? '{}', true) ?: []),
        ];
    }
    $g = fn($k,$d) => $row[$k] ?? $d;
    return [
        'caption' => array_merge($default_caption, ['is_enabled'=>(int)$g('cap_is_enabled',1),'fontfamily'=>$g('cap_fontfamily','Arial'),'fontsize'=>(int)$g('cap_fontsize',28),'fontcolor'=>$g('cap_fontcolor','#ffff00'),'fontweight'=>$g('cap_fontweight','bold'),'caption_alignment'=>$g('cap_alignment','center'),'position_x'=>(int)$g('cap_position_x',50),'position_y'=>(int)$g('cap_position_y',250),'width'=>(int)$g('cap_width',500),'caption_speed'=>(float)$g('cap_speed',1.0),'text_animation'=>$g('cap_text_animation','static'),'text_effect'=>$g('cap_text_effect','none'),'stroke_width'=>(int)$g('cap_stroke_width',0),'stroke_color'=>$g('cap_stroke_color','#000000'),'shadow_color'=>$g('cap_shadow_color','#000000'),'gradient_color'=>$g('cap_gradient_color','#ff6600'),'display_mode'=>$g('cap_display_mode','full'),'caption_style'=>$g('cap_caption_style','none'),'caption_position'=>$g('cap_position','bottom'),'text_align_v'=>$g('cap_text_align_v','bottom'),'caption_text'=>$g('cap_caption_text','')]),
        'header'  => array_merge($default_header,  ['is_enabled'=>(int)$g('hdr_is_enabled',0),'fontfamily'=>$g('hdr_fontfamily','Helvetica'),'fontsize'=>(int)$g('hdr_fontsize',16),'fontcolor'=>$g('hdr_fontcolor','#ffffff'),'caption_text'=>$g('hdr_caption_text','')]),
        'footer'  => array_merge($default_footer,  ['is_enabled'=>(int)$g('ftr_is_enabled',0),'fontfamily'=>$g('ftr_fontfamily','Georgia'),'fontsize'=>(int)$g('ftr_fontsize',12),'fontcolor'=>$g('ftr_fontcolor','#aaaaaa'),'caption_text'=>$g('ftr_caption_text','')]),
        'logo'    => array_merge($default_logo,    ['logo_enabled'=>(int)$g('logo_enabled',0),'logo_file'=>$g('logo_file',''),'logo_name'=>$g('logo_name','')]),
    ];
}

$def_caption = [
    'text_type'=>'caption','is_enabled'=>1,
    'fontfamily'=>'Arial','fontsize'=>28,
    'fontcolor'=>'#ffff00','fontweight'=>'bold',
    'fontcolor_bg'=>'#000000','fontbg_enable'=>0,
    'font_italic'=>0,
    'font_underline'=>0,
    'caption_style'=>'none','caption_position'=>'bottom',
    'caption_alignment'=>'center','caption_speed'=>1.0,
    'text_effect'=>'none','text_animation'=>'static',
    'display_mode'=>'full','animation_speed'=>'medium',
    'position_x'=>50,'position_y'=>250,'width'=>500,
    'stroke_color'=>'#000000',
    'stroke_width'=>0,
    'shadow_color'=>'#000000',
    'gradient_color'=>'#ff6600',
    'text_align_v'=>'bottom',
    'logo_name'=>'','logo_file'=>'','logo_size'=>'60',
    'logo_position'=>'top-right','logo_pos_h'=>'right','logo_pos_v'=>'top',
    'logo_size_pct'=>15,'logo_enabled'=>0,
    'header_text'=>'','footer_text'=>'',
];
// ── buildCaptionRows ──────────────────────────────────────────────────────────
// Complete version with ALL text effects, 4 caption types, and full styling
function buildCaptionRows(mysqli $conn, int $podcast_id, int $story_id, string $scene_text, array $user_settings_all, bool $is_broll = false): int {
    $cap = $user_settings_all['caption'];
    $hdr = $user_settings_all['header'];
    $ftr = $user_settings_all['footer'];
    $logo_settings = $user_settings_all['logo'] ?? [];
    $rows = 0;

    $esc = function($val) use ($conn) { return mysqli_real_escape_string($conn, $val); };
    
    // Helper to build effect colors string (shadow, gradient, stroke)
    $buildEffectColors = function($settings) use ($esc) {
        $shadow  = $settings['shadow_color']   ?? '#000000';
        $gradient = $settings['gradient_color'] ?? '#ff6600';
        $stroke  = $settings['stroke_color']   ?? '#000000';
        return $esc("$shadow,$gradient,$stroke");
    };

    // ═══════════════════════════════════════════════════════════════════════
    // 1. MAIN CAPTION — text is the scene text
    // ═══════════════════════════════════════════════════════════════════════
    if ((int)($cap['is_enabled'] ?? 1)) {
        $ff      = $esc($cap['fontfamily']      ?? 'Arial');
        $fs      = (int)($cap['fontsize']       ?? 28);
        $fc      = $esc($cap['fontcolor']       ?? '#ffff00');
        $fw      = $esc($cap['fontweight']      ?? 'bold');
        $fstyle  = (int)($cap['font_italic']    ?? 0) ? 'italic' : 'normal';
        $funderline = (int)($cap['font_underline'] ?? 0);
        $bgc     = $esc($cap['fontcolor_bg']    ?? '#000000');
        $bge     = (int)($cap['fontbg_enable']  ?? 0);
        $align   = $esc($cap['caption_alignment'] ?? 'center');
        $px      = (int)($cap['position_x']     ?? 50);
        $py      = (int)($cap['position_y']     ?? 250);
        
        // B-Roll override: caption near top, smaller font
        if ($is_broll) {
            $py = 25;
            $fs = 20;
        }
        
        $pw      = min((int)($cap['width'] ?? 500), 350);
        $cspd    = (float)($cap['caption_speed'] ?? 1.0);
        $anim    = $esc($cap['text_animation']   ?? 'static');
        $text_effect = $esc($cap['text_effect'] ?? 'none');
        $effect_colors = $buildEffectColors($cap);
        $stroke_width = (int)($cap['stroke_width'] ?? 0);
        $display_mode = $esc($cap['display_mode'] ?? 'full');
        $caption_style = $esc($cap['caption_style'] ?? 'none');
        $caption_pos   = $esc($cap['caption_position'] ?? 'bottom');
        $text_align_v  = $esc($cap['text_align_v'] ?? 'bottom');
        $te = $esc($scene_text);
        $cap_stroke   = $esc($cap['stroke_color']   ?? '#000000');
        $cap_shadow   = $esc($cap['shadow_color']   ?? '#000000');
        $cap_gradient = $esc($cap['gradient_color'] ?? '#ff6600');
        $text_decoration = $funderline ? 'underline' : 'none';
        $fontstyle_val = $fw . ($fstyle === 'italic' ? ' italic' : '');
        
        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index,
             text_effects, text_effect_colors, stroke_width, stroke_color,
             shadow_color, gradient_color, display_mode, caption_style,
             caption_position, text_align_v, underline, text_decoration)
            VALUES
            ({$podcast_id}, {$story_id}, 'caption', 'main', '{$te}',
             '{$ff}', {$fs}, '{$fc}', '{$fw}', '{$fontstyle_val}', '{$align}',
             '{$bgc}', {$bge}, {$px}, {$py}, {$pw}, 0,
             '{$anim}', {$cspd}, 1, 1,
             '{$text_effect}', '{$effect_colors}', {$stroke_width}, '{$cap_stroke}',
             '{$cap_shadow}', '{$cap_gradient}',
             '{$display_mode}', '{$caption_style}', '{$caption_pos}', '{$text_align_v}',
             {$funderline}, '{$text_decoration}')";
        
        if (mysqli_query($conn, $sql)) {
            $rows++;
            pod_log("Caption inserted: story={$story_id}");
        } else {
            pod_log("Caption INSERT failed: " . mysqli_error($conn));
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. HEADER — text is caption_text from user settings
    // ═══════════════════════════════════════════════════════════════════════
    if ((int)($hdr['is_enabled'] ?? 0)) {
        $ff      = $esc($hdr['fontfamily']      ?? 'Helvetica');
        $fs      = (int)($hdr['fontsize']       ?? 16);
        $fc      = $esc($hdr['fontcolor']       ?? '#ffffff');
        $fw      = $esc($hdr['fontweight']      ?? 'bold');
        $fstyle  = (int)($hdr['font_italic']    ?? 0) ? 'italic' : 'normal';
        $funderline = (int)($hdr['font_underline'] ?? 0);
        $bgc     = $esc($hdr['fontcolor_bg']    ?? '#1a1a2e');
        $bge     = (int)($hdr['fontbg_enable']  ?? 1);
        $align   = $esc($hdr['caption_alignment'] ?? 'center');
        $px      = (int)($hdr['position_x']     ?? 0);
        $py      = (int)($hdr['position_y']     ?? 0);
        $pw      = min((int)($hdr['width'] ?? 1080), 350);
        $cspd    = (float)($hdr['caption_speed'] ?? 1.0);
        $anim    = $esc($hdr['text_animation']   ?? 'fade_in');
        $text_effect = $esc($hdr['text_effect'] ?? 'none');
        $effect_colors = $buildEffectColors($hdr);
        $stroke_width = (int)($hdr['stroke_width'] ?? 0);
        $display_mode = $esc($hdr['display_mode'] ?? 'full');
        $caption_style = $esc($hdr['caption_style'] ?? 'box');
        $caption_pos   = $esc($hdr['caption_position'] ?? 'top');
        $text_align_v  = $esc($hdr['text_align_v'] ?? 'top');
        $ht = $esc($hdr['caption_text'] ?? '');
        $hdr_stroke   = $esc($hdr['stroke_color']   ?? '#000000');
        $hdr_shadow   = $esc($hdr['shadow_color']   ?? '#000000');
        $hdr_gradient = $esc($hdr['gradient_color'] ?? '#ff6600');
        $text_decoration = $funderline ? 'underline' : 'none';
        $fontstyle_val = $fw . ($fstyle === 'italic' ? ' italic' : '');
        
        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index,
             text_effects, text_effect_colors, stroke_width, stroke_color,
             shadow_color, gradient_color, display_mode, caption_style,
             caption_position, text_align_v, underline, text_decoration)
            VALUES
            ({$podcast_id}, {$story_id}, 'header', 'header', '{$ht}',
             '{$ff}', {$fs}, '{$fc}', '{$fw}', '{$fontstyle_val}', '{$align}',
             '{$bgc}', {$bge}, {$px}, {$py}, {$pw}, 0,
             '{$anim}', {$cspd}, 1, 2,
             '{$text_effect}', '{$effect_colors}', {$stroke_width}, '{$hdr_stroke}',
             '{$hdr_shadow}', '{$hdr_gradient}',
             '{$display_mode}', '{$caption_style}', '{$caption_pos}', '{$text_align_v}',
             {$funderline}, '{$text_decoration}')";
        
        if (mysqli_query($conn, $sql)) {
            $rows++;
            pod_log("Header inserted: story={$story_id}");
        } else {
            pod_log("Header INSERT failed: " . mysqli_error($conn));
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. FOOTER — text is caption_text from user settings
    // ═══════════════════════════════════════════════════════════════════════
    if ((int)($ftr['is_enabled'] ?? 0)) {
        $ff      = $esc($ftr['fontfamily']      ?? 'Georgia');
        $fs      = (int)($ftr['fontsize']       ?? 12);
        $fc      = $esc($ftr['fontcolor']       ?? '#aaaaaa');
        $fw      = $esc($ftr['fontweight']      ?? 'normal');
        $fstyle  = (int)($ftr['font_italic']    ?? 0) ? 'italic' : 'normal';
        $funderline = (int)($ftr['font_underline'] ?? 0);
        $bgc     = $esc($ftr['fontcolor_bg']    ?? '#000000');
        $bge     = (int)($ftr['fontbg_enable']  ?? 0);
        $align   = $esc($ftr['caption_alignment'] ?? 'center');
        $px      = (int)($ftr['position_x']     ?? 0);
        $py      = (int)($ftr['position_y']     ?? 0);
        $pw      = min((int)($ftr['width'] ?? 1080), 350);
        $cspd    = (float)($ftr['caption_speed'] ?? 1.0);
        $anim    = $esc($ftr['text_animation']   ?? 'static');
        $text_effect = $esc($ftr['text_effect'] ?? 'none');
        $effect_colors = $buildEffectColors($ftr);
        $stroke_width = (int)($ftr['stroke_width'] ?? 0);
        $display_mode = $esc($ftr['display_mode'] ?? 'full');
        $caption_style = $esc($ftr['caption_style'] ?? 'none');
        $caption_pos   = $esc($ftr['caption_position'] ?? 'bottom');
        $text_align_v  = $esc($ftr['text_align_v'] ?? 'bottom');
        $ft_text = $esc($ftr['caption_text'] ?? '');
        $ftr_stroke   = $esc($ftr['stroke_color']   ?? '#000000');
        $ftr_shadow   = $esc($ftr['shadow_color']   ?? '#000000');
        $ftr_gradient = $esc($ftr['gradient_color'] ?? '#ff6600');
        $text_decoration = $funderline ? 'underline' : 'none';
        $fontstyle_val = $fw . ($fstyle === 'italic' ? ' italic' : '');
        
        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index,
             text_effects, text_effect_colors, stroke_width, stroke_color,
             shadow_color, gradient_color, display_mode, caption_style,
             caption_position, text_align_v, underline, text_decoration)
            VALUES
            ({$podcast_id}, {$story_id}, 'footer', 'footer', '{$ft_text}',
             '{$ff}', {$fs}, '{$fc}', '{$fw}', '{$fontstyle_val}', '{$align}',
             '{$bgc}', {$bge}, {$px}, {$py}, {$pw}, 0,
             '{$anim}', {$cspd}, 1, 3,
             '{$text_effect}', '{$effect_colors}', {$stroke_width}, '{$ftr_stroke}',
             '{$ftr_shadow}', '{$ftr_gradient}',
             '{$display_mode}', '{$caption_style}', '{$caption_pos}', '{$text_align_v}',
             {$funderline}, '{$text_decoration}')";
        
        if (mysqli_query($conn, $sql)) {
            $rows++;
            pod_log("Footer inserted: story={$story_id}");
        } else {
            pod_log("Footer INSERT failed: " . mysqli_error($conn));
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. LOGO — image/video overlay
    // ═══════════════════════════════════════════════════════════════════════
    $logo_enabled = (int)($logo_settings['logo_enabled'] ?? 0);
    $logo_file = trim($logo_settings['logo_file'] ?? $logo_settings['logo_name'] ?? $cap['logo_file'] ?? $cap['logo_name'] ?? '');
    
    if ($logo_enabled && !empty($logo_file)) {
        $lf = $esc($logo_file);
        $lpx = (int)($logo_settings['position_x'] ?? 960);
        $lpy = (int)($logo_settings['position_y'] ?? 20);
        $lw = (int)($logo_settings['width'] ?? 120);
        
        $logo_pos_h = $logo_settings['logo_pos_h'] ?? 'right';
        $logo_pos_v = $logo_settings['logo_pos_v'] ?? 'top';
        $logo_size_pct = (int)($logo_settings['logo_size_pct'] ?? 15);
        
        if ($lpx == 960 && $logo_pos_h == 'left') $lpx = 20;
        if ($lpx == 960 && $logo_pos_h == 'center') $lpx = 540;
        if ($lpy == 20 && $logo_pos_v == 'middle') $lpy = 540;
        if ($lpy == 20 && $logo_pos_v == 'bottom') $lpy = 1000;
        
        $lw = (int)(1080 * $logo_size_pct / 100);
        
        $video_exts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
        $file_ext = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));
        $is_video = in_array($file_ext, $video_exts);
        $media_type = $is_video ? 'video' : 'image';
        
        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index, media_type)
            VALUES
            ({$podcast_id}, {$story_id}, 'logo', 'logo', '{$lf}',
             'Arial', 0, '#ffffff', 'normal', 'normal', 'left',
             '#000000', 0, {$lpx}, {$lpy}, {$lw}, 0,
             'none', 1.0, 1, 10, '{$media_type}')";
        
        if (mysqli_query($conn, $sql)) {
            pod_log("Logo inserted: {$logo_file} (type: {$media_type})");
            $rows++;
        } else {
            pod_log("Logo INSERT failed: " . mysqli_error($conn));
        }
    }

    pod_log("buildCaptionRows: podcast={$podcast_id} story={$story_id} rows={$rows}");
    return $rows;
}
// ============================================================================
// ACTION: create_scenes_from_content
// Identical surface API to wizard_step2.php so the JS doesn't need changes
// ============================================================================
if ($action === 'create_scenes_from_content') {

    try {
        $title       = trim($_POST['title']       ?? '');
        $lang_code   = mysqli_real_escape_string($conn, $_POST['target_lang']  ?? 'en');
        $reel_type   = mysqli_real_escape_string($conn, $_POST['reel_type']    ?? 'podcast');
        $topic_key   = mysqli_real_escape_string($conn, $_POST['topic']        ?? 'general');
        $category    = mysqli_real_escape_string($conn, $_POST['category']     ?? 'free-format');
        $niche       = mysqli_real_escape_string($conn, $_POST['niche']        ?? '');
        $host_voice  = mysqli_real_escape_string($conn, $_POST['host_voice']   ?? '');
        $guest_voice = mysqli_real_escape_string($conn, $_POST['guest_voice']  ?? $host_voice);
        $voice_rate  = (float) ($_POST['rate'] ?? 1.0);
        $company_id  = (int) ($_SESSION['company_id'] ?? $admin_id);

        $scenes_json = $_POST['content'] ?? '[]';
        $scenes      = json_decode($scenes_json, true);

        // Fallback: read from hdb_podcasts.script_text
        if (!is_array($scenes) || empty($scenes)) {
            $podcast_id_for_script = (int) ($_POST['podcast_id'] ?? 0);
            if ($podcast_id_for_script) {
                $script_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT script_text FROM hdb_podcasts
                     WHERE id={$podcast_id_for_script} AND admin_id={$admin_id} LIMIT 1"
                ));
                $script_text = trim($script_row['script_text'] ?? '');
                if (!empty($script_text)) {
                    $lines  = array_values(array_filter(array_map('trim', explode("\n", $script_text))));
                    $scenes = array_map(fn($line) => [
                        'text'     => $line,
                        'prompt'   => '',
                        'hashtags' => '',
                        'nl_tags'  => '',
                        'actor'    => stripos($line, 'guest:') === 0 ? 'guest' : 'host',
                    ], $lines);
                    pod_log("create_scenes_from_content: loaded " . count($scenes) . " scenes from script_text");
                }
            }
        }

        if (!is_array($scenes) || empty($scenes)) {
            pod_json(['success' => false, 'message' => 'No valid scenes found']);
        }

        pod_log("create_scenes_from_content: " . count($scenes) . " scenes | title={$title}");

        $user_settings = getUserSettingsWiz($conn, $admin_id);
        $esc_title  = mysqli_real_escape_string($conn, $title);
        $today      = date('Y-m-d');

        // Insert podcast row
        $sql = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
             created_date, updated_at, niche, category, topic_key,
             host_voice, guest_voice, voice_rate, is_campaign,
             logo_flag, facebook_status, tiktok_status, instagram_status,
             youtube_status, twitter_status, linkedin_status,
             schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name)
            VALUES
            ({$admin_id}, {$team_lead_id}, {$company_id}, '{$esc_title}', '{$lang_code}', '{$reel_type}', 'draft', 'draft',
             '{$today}', NOW(), '{$niche}', '{$category}', '{$topic_key}',
             '{$host_voice}', '{$guest_voice}', {$voice_rate}, 0,
             0, 'pending', 'pending', 'pending',
             'pending', 'pending', 'pending',
             '{$today}', '09:00', '{$today}', 'vertical', 'video', '', '')"; 

        if (!mysqli_query($conn, $sql)) {
            pod_log("podcast insert FAIL: " . mysqli_error($conn));
            pod_json(['success' => false, 'message' => 'Failed to create podcast: ' . mysqli_error($conn)]);
        }

        $podcast_id    = mysqli_insert_id($conn);
        $success_count = 0;
		// Log podcast creation
		mysqli_query($conn,
			"INSERT INTO hdb_user_activity_log
			 (podcast_id, admin_id, action_type, action_detail)
			 VALUES ($podcast_id, $admin_id, 'podcast_created', 'type:$reel_type scenes:$success_count')"
		);
        foreach ($scenes as $i => $scene) {
            $raw_text = trim($scene['text'] ?? '');
            if (empty($raw_text)) continue;

            // Detect actor from prefix before stripping
            $actor = 'host';
            if (preg_match('/^guest\s*:/i', $raw_text)) $actor = 'guest';

            // Strip prefix for storage
            $clean_text = strip_actor_prefix($raw_text);
            $seq_no     = $i + 1;
            $word_count = count(array_filter(explode(' ', $clean_text)));
            $duration   = max(3, (int) round(($word_count / 130) * 60));

            $te = mysqli_real_escape_string($conn, $clean_text);
            $de = mysqli_real_escape_string($conn, substr($clean_text, 0, 50) . (strlen($clean_text) > 50 ? '...' : ''));
            $ae = mysqli_real_escape_string($conn, $actor);
            $ve = mysqli_real_escape_string($conn, $actor === 'guest' ? $guest_voice : $host_voice);
            $tk = mysqli_real_escape_string($conn, $title);

             $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt,
                 status, created_date, scene_order, voice_id, voice_rate)
                VALUES
                ({$podcast_id}, '{$lang_code}', '{$category}', '{$topic_key}', '{$tk}', '{$ae}',
                 '{$te}', '{$de}', {$duration}, '',
                 'PENDING', NOW(), {$seq_no}, '{$ve}', {$voice_rate})";

            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                buildCaptionRows($conn, $podcast_id, $story_id, $clean_text, $user_settings, false);
                $success_count++;
            } else {
                pod_log("scene {$seq_no} INSERT FAIL: " . mysqli_error($conn));
            }
        }

        pod_log("create_scenes_from_content done: {$success_count} scenes for podcast={$podcast_id}");
        deductCredit($conn, $admin_id, $reel_type);

        // Reset video_type to standard after scenes are generated
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET video_type='standard', updated_at=NOW() WHERE id={$podcast_id}");
        pod_log("create_scenes_from_content: reset video_type=standard for podcast={$podcast_id}");

        // ── Check if any scenes were queued for talking head generation ───────
       // ── Queue for talking head generation if image + audio both assigned ────────
                // ── Estimate queue wait time ───────────────────────────────────────────
        $queue_ahead = (int)(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM hdb_video_gen
             WHERE status IN ('pending','processing') AND id < (SELECT MIN(id) FROM hdb_video_gen WHERE podcast_id={$podcast_id})"))['c'] ?? 0);
        $est_minutes = ($queue_ahead + $success_count) * 2; // ~2 mins per scene

        pod_json([
            'success'          => true,
            'podcast_id'       => $podcast_id,
            'scene_count'      => $success_count,
            'video_gen_queued' => $is_talking_head_reel,
            'est_minutes'      => $est_minutes,
        ]);

    } catch (Throwable $e) {
        pod_log("create_scenes_from_content EXCEPTION: " . $e->getMessage());
        pod_json(['success' => false, 'message' => $e->getMessage()]);
    }
}


// ============================================================================
// ACTION: get_scenes
// ============================================================================
if ($action === 'get_scenes') {

    $podcast_id = (int) ($_POST['podcast_id'] ?? 0);
    $result     = mysqli_query($conn,
        "SELECT * FROM hdb_podcast_stories
         WHERE podcast_id = {$podcast_id}
         ORDER BY seq_no ASC"
    );
    $scenes = [];
    while ($row = mysqli_fetch_assoc($result)) $scenes[] = $row;
    pod_json($scenes);
}


// ============================================================================
// ACTION: process_podcast_scene
// Core action — handles ONE scene at a time:
//   1. Strip actor prefix from text, update DB
//   2. Generate audio with correct voice
//   3. Look up voice gender
//   4. Pick host image (gender + cycling pose)
//   5. Update scene with audio + image
// ============================================================================
if ($action === 'process_podcast_scene') {

    $scene_id    = (int)   ($_POST['scene_id']    ?? 0);
    $podcast_id  = (int)   ($_POST['podcast_id']  ?? 0);
    $seq_no      = (int)   ($_POST['seq_no']       ?? 1);
    $lang_code   = mysqli_real_escape_string($conn, $_POST['lang_code']   ?? 'en');
    $reel_type   = trim($_POST['reel_type'] ?? 'podcast');
    $host_voice  = trim($_POST['host_voice']  ?? '');
    $guest_voice = trim($_POST['guest_voice'] ?? $host_voice);
    $rate        = trim($_POST['rate']        ?? '1.0');

    // Pose counters passed from JS (incremented per gender across scenes)
    $male_pose_counter   = (int) ($_POST['male_pose_counter']   ?? 1);
    $female_pose_counter = (int) ($_POST['female_pose_counter'] ?? 1);

    // Host and guest base image names selected by user in the UI picker
    $host_imagename  = trim($_POST['host_imagename']  ?? '');
    $guest_imagename = trim($_POST['guest_imagename'] ?? '');

    // Image folder: 'avatars' for talking head, 'podcast_images' for podcast
    $is_talking_head = (stripos($reel_type, 'talking head') !== false);
    $image_folder    = $is_talking_head ? 'podcast_avatars' : 'podcast_images';

    if (!$scene_id || !$podcast_id) {
        pod_json(['success' => false, 'error' => 'Missing scene_id or podcast_id']);
    }

    // ── Load scene from DB ────────────────────────────────────────────────────
    $scene_res = mysqli_query($conn,
        "SELECT * FROM hdb_podcast_stories WHERE id = {$scene_id} LIMIT 1"
    );
    if (!$scene_res || mysqli_num_rows($scene_res) === 0) {
        pod_json(['success' => false, 'error' => 'Scene not found']);
    }
    $scene = mysqli_fetch_assoc($scene_res);
    $actor = strtolower(trim($scene['actor'] ?? 'host'));   // 'host' or 'guest'

    pod_log("process_podcast_scene: scene={$scene_id} seq={$seq_no} actor={$actor}");

    $result_data = [
        'success'            => true,
        'scene_id'           => $scene_id,
        'seq_no'             => $seq_no,
        'actor'              => $actor,
        'male_pose_counter'  => $male_pose_counter,
        'female_pose_counter'=> $female_pose_counter,
        'audio_file'         => null,
        'image_file'         => null,
        'voice_gender'       => null,
        'errors'             => [],
    ];

    // ── STEP 1: Strip actor prefix, update DB ─────────────────────────────────
    $raw_text   = $scene['text_contents'] ?? '';
    $clean_text = strip_actor_prefix($raw_text);
    $clean_disp = substr($clean_text, 0, 50) . (strlen($clean_text) > 50 ? '...' : '');

    $te = mysqli_real_escape_string($conn, $clean_text);
    $de = mysqli_real_escape_string($conn, $clean_disp);
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET text_contents = '{$te}', text_display = '{$de}'
         WHERE id = {$scene_id}"
    );
    pod_log("scene {$scene_id}: text stripped and updated");

    // ── STEP 2: Generate audio ────────────────────────────────────────────────
    $tts_text = $clean_text;
    if (empty(trim($tts_text))) {
        pod_log("scene {$scene_id}: empty text, skipping audio");
        $result_data['errors'][] = 'Empty text — audio skipped';
    } else {
        $voice_to_use = ($actor === 'guest' && !empty($guest_voice)) ? $guest_voice : $host_voice;

        $audio_dir  = __DIR__ . '/podcast_audios/';
        if (!is_dir($audio_dir)) @mkdir($audio_dir, 0777, true);
        if (is_dir($audio_dir) && !is_writable($audio_dir)) @chmod($audio_dir, 0777); // self-heal perms
        $audio_file = "voice_{$podcast_id}_{$scene_id}_{$lang_code}.mp3";
        $filepath   = $audio_dir . $audio_file;

        $audio_result = generate_audio($tts_text, $voice_to_use, $rate, $filepath);

        if ($audio_result['success']) {
            $af = mysqli_real_escape_string($conn, $audio_file);
            $ve = mysqli_real_escape_string($conn, $voice_to_use);
            $re = mysqli_real_escape_string($conn, $rate);
            mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                 SET audio_file = '{$af}', voice_id = '{$ve}', voice_rate = '{$re}'
                 WHERE id = {$scene_id}"
            );
            $result_data['audio_file'] = $audio_file;
            pod_log("scene {$scene_id}: audio OK — {$audio_file}");
        } else {
            $err = $audio_result['error'] ?? 'Audio generation failed';
            $result_data['errors'][] = 'Audio: ' . $err;
            pod_log("scene {$scene_id}: audio FAIL — {$err}");
        }

        // ── STEP 3: Voice gender lookup ───────────────────────────────────────
        $voice_gender = get_voice_gender($conn, $voice_to_use);
        $result_data['voice_gender'] = $voice_gender;
        pod_log("scene {$scene_id}: voice_gender={$voice_gender}");

        // ── STEP 4: Pick host image using base name + cycling pose ────────────
        // base name (e.g. 'host_male_2') was resolved once before the loop
        // and passed in from JS. We append _p1/_p2/_p3/_p4 cycling per gender.
        $base_name = ($actor === 'guest') ? $guest_imagename : $host_imagename;

        if (empty($base_name)) {
            $result_data['errors'][] = 'No image base name provided for actor: ' . $actor;
            pod_log("scene {$scene_id}: missing base_name for actor={$actor}");
        } else {
            if ($voice_gender === 'male') {
                $pose = $male_pose_counter;
                $result_data['male_pose_counter'] = $male_pose_counter + 1;
            } else {
                $pose = $female_pose_counter;
                $result_data['female_pose_counter'] = $female_pose_counter + 1;
            }

            $filename = build_pose_filename($base_name, $pose);
            pod_log("scene inam {$scene_id}: image candidate={$filename} (base={$base_name} pose={$pose})");

            if (image_file_exists($conn, $filename)) {
                $img = mysqli_real_escape_string($conn, $filename);
                $ifolder = mysqli_real_escape_string($conn, $image_folder);
                mysqli_query($conn,
                    "UPDATE hdb_podcast_stories
                     SET image_file = '{$img}', image_folder = '{$ifolder}'
                     WHERE id = {$scene_id}"
                );
                $result_data['image_file']   = $filename;
                $result_data['image_folder'] = $image_folder;
                pod_log("scene {$scene_id}: image assigned — {$filename} folder={$image_folder}");
            } else {
                $result_data['errors'][] = "Image not found in hdb_image_data: {$filename}";
                pod_log("scene {$scene_id}: image NOT found — {$filename}");
            }
        }
    }

    // ── Queue for talking head generation if image + audio both assigned ────────
	$rt_lower = strtolower($reel_type ?? '');
	$is_talking_head_reel = (strpos($rt_lower, 'podcast') !== false || strpos($rt_lower, 'talking head') !== false);
	$has_image = !empty($result_data['image_file']);
	$has_audio = !empty($result_data['audio_file']);

	if ($is_talking_head_reel && $has_image && $has_audio) {
		$q_img    = mysqli_real_escape_string($conn, $result_data['image_file']);
		$q_audio  = mysqli_real_escape_string($conn, $result_data['audio_file']);
		$q_folder = mysqli_real_escape_string($conn, $image_folder);
		
		// Get the clean script text for this scene
		$script_text = mysqli_real_escape_string($conn, $clean_text);
		
		// First, check if hdb_video_gen has script_text column
		$check_column = mysqli_query($conn, "SHOW COLUMNS FROM hdb_video_gen LIKE 'script_text'");
		$has_script_column = mysqli_num_rows($check_column) > 0;
		
		if ($has_script_column) {
			// Insert with script_text column
			mysqli_query($conn,
				"INSERT INTO hdb_video_gen
				 (podcast_id, story_id, admin_id, team_lead_id,
				  image_file, image_folder, audio_file, script_text,
				  status, phase, created_at)
				 VALUES
				 ({$podcast_id}, {$scene_id}, {$admin_id}, {$team_lead_id},
				  '{$q_img}', '{$q_folder}', '{$q_audio}', '{$script_text}',
				  'pending', 'sadtalker', NOW())"
			);
		} else {
			// Insert without script_text column (original way)
			mysqli_query($conn,
				"INSERT INTO hdb_video_gen
				 (podcast_id, story_id, admin_id, team_lead_id,
				  image_file, image_folder, audio_file,
				  status, phase, created_at)
				 VALUES
				 ({$podcast_id}, {$scene_id}, {$admin_id}, {$team_lead_id},
				  '{$q_img}', '{$q_folder}', '{$q_audio}',
				  'pending', 'sadtalker', NOW())"
			);
		}
		pod_log("scene {$scene_id}: queued for video gen with script_text length=" . strlen($clean_text));

		// Mark scene: videomaker must use the VIDEO's embedded audio (not the separate mp3)
		// because SadTalker generates lip-sync baked to the audio — playing separate audio would be out of sync.
		// ALTER TABLE hdb_podcast_stories ADD COLUMN use_video_audio TINYINT(1) DEFAULT 0;
		$check_col = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories LIKE 'use_video_audio'");
		if (mysqli_num_rows($check_col) > 0) {
			mysqli_query($conn, "UPDATE hdb_podcast_stories SET use_video_audio=1 WHERE id={$scene_id}");
		}
		$result_data['use_video_audio'] = 1;
	}

    pod_json($result_data);
}


// ============================================================================
// ACTION: process_avatar_scene
// Handles ONE talking-head scene:
//   1. Strip host: prefix from text, update DB
//   2. Generate audio with host voice
//   3. Assign avatar image (avatar_base + cycling pose _p1-_p4)
//   4. Update scene row with audio + image
// ============================================================================
if ($action === 'process_avatar_scene') {
  try {

    $scene_id   = (int)   ($_POST['scene_id']   ?? 0);
    $podcast_id = (int)   ($_POST['podcast_id'] ?? 0);
    $seq_no     = (int)   ($_POST['seq_no']      ?? 1);
    $lang_code  = mysqli_real_escape_string($conn, trim($_POST['lang_code']  ?? 'en'));
    $host_voice = trim($_POST['host_voice'] ?? '');
    $rate       = trim($_POST['rate']       ?? '1.0');
    $avatar_base = trim($_POST['avatar_base'] ?? '');   // e.g. "avatar_005"
    $image_folder = 'podcast_avatars';

    pod_log("process_avatar_scene INPUT: scene={$scene_id} podcast={$podcast_id} seq={$seq_no} voice={$host_voice} rate={$rate} avatar={$avatar_base}");

    if (!$scene_id || !$podcast_id) {
        pod_json(['success' => false, 'error' => 'Missing scene_id or podcast_id']);
    }

    // Load scene
    $scene_res = mysqli_query($conn,
        "SELECT * FROM hdb_podcast_stories WHERE id = {$scene_id} LIMIT 1"
    );
    if (!$scene_res || mysqli_num_rows($scene_res) === 0) {
        pod_log("process_avatar_scene: scene {$scene_id} NOT FOUND in DB");
        pod_json(['success' => false, 'error' => 'Scene not found']);
    }
    $scene = mysqli_fetch_assoc($scene_res);

    pod_log("process_avatar_scene: scene={$scene_id} seq={$seq_no} avatar={$avatar_base}");

    $result_data = [
        'success'    => true,
        'scene_id'   => $scene_id,
        'seq_no'     => $seq_no,
        'audio_file' => null,
        'image_file' => null,
        'errors'     => [],
    ];

    // ── STEP 1: Strip prefix, update DB ───────────────────────────────────────
    $raw_text   = $scene['text_contents'] ?? '';
    $clean_text = strip_actor_prefix($raw_text);
    $clean_disp = substr($clean_text, 0, 50) . (strlen($clean_text) > 50 ? '...' : '');

    $te = mysqli_real_escape_string($conn, $clean_text);
    $de = mysqli_real_escape_string($conn, $clean_disp);
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET text_contents = '{$te}', text_display = '{$de}'
         WHERE id = {$scene_id}"
    );

    // ── STEP 2: Generate audio ─────────────────────────────────────────────────
    if (empty(trim($clean_text))) {
        $result_data['errors'][] = 'Empty text — audio skipped';
        pod_log("scene {$scene_id}: empty text");
    } else {
        $audio_dir  = __DIR__ . '/podcast_audios/';
        if (!is_dir($audio_dir)) @mkdir($audio_dir, 0777, true);
        if (is_dir($audio_dir) && !is_writable($audio_dir)) @chmod($audio_dir, 0777); // self-heal perms
        $audio_file = "voice_{$podcast_id}_{$scene_id}_{$lang_code}.mp3";
        $filepath   = $audio_dir . $audio_file;

        $audio_result = generate_audio($clean_text, $host_voice, $rate, $filepath);

        if ($audio_result['success']) {
            $af = mysqli_real_escape_string($conn, $audio_file);
            $ve = mysqli_real_escape_string($conn, $host_voice);
            $re = mysqli_real_escape_string($conn, $rate);
            mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                 SET audio_file = '{$af}', voice_id = '{$ve}', voice_rate = '{$re}'
                 WHERE id = {$scene_id}"
            );
            $result_data['audio_file'] = $audio_file;
            pod_log("scene {$scene_id}: audio OK — {$audio_file}");
        } else {
            $err = $audio_result['error'] ?? 'Audio generation failed';
            $result_data['errors'][] = 'Audio: ' . $err;
            pod_log("scene {$scene_id}: audio FAIL — {$err}");
        }
    }

    // ── STEP 3: Assign avatar image — cycle poses _p1 to _p4 ──────────────────
    // Checks disk directly (podcast_avatars/ folder) — does NOT require hdb_image_data
    if (!empty($avatar_base)) {
        $pose     = (($seq_no - 1) % 4) + 1;
        $filename = "{$avatar_base}_p{$pose}.png";
        $avatar_disk_dir = __DIR__ . '/podcast_avatars/';

        // Log exact path and directory contents for diagnosis
        $dir_files = is_dir($avatar_disk_dir)
            ? implode(', ', array_slice(array_map('basename', glob($avatar_disk_dir . '*') ?: []), 0, 20))
            : 'DIRECTORY DOES NOT EXIST';
        pod_log("scene {$scene_id}: avatar candidate={$filename} | dir={$avatar_disk_dir} | dir_exists=" . (is_dir($avatar_disk_dir)?'YES':'NO') . " | files=[{$dir_files}]");

        // Helper: assign image to scene
        $assign_avatar = function(string $fname) use ($conn, $scene_id, $image_folder, &$result_data) {
            $img     = mysqli_real_escape_string($conn, $fname);
            $ifolder = mysqli_real_escape_string($conn, $image_folder);
            mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                 SET image_file = '{$img}', image_folder = '{$ifolder}'
                 WHERE id = {$scene_id}"
            );
            $result_data['image_file']   = $fname;
            $result_data['image_folder'] = $image_folder;
            pod_log("scene {$scene_id}: image assigned — {$fname}");
        };

        if (file_exists($avatar_disk_dir . $filename)) {
            $assign_avatar($filename);
        } else {
            // Try fallback poses p1→p4
            $assigned = false;
            for ($p = 1; $p <= 4; $p++) {
                $try = "{$avatar_base}_p{$p}.png";
                if (file_exists($avatar_disk_dir . $try)) {
                    $assign_avatar($try);
                    $assigned = true;
                    break;
                }
            }
            if (!$assigned) {
                // Last resort: any file starting with avatar_base
                $candidates = glob($avatar_disk_dir . $avatar_base . '*.png') ?: [];
                if (!empty($candidates)) {
                    $assign_avatar(basename($candidates[0]));
                } else {
                    $result_data['errors'][] = "Avatar image not found on disk: {$avatar_disk_dir}{$filename}";
                    pod_log("scene {$scene_id}: avatar NOT found on disk — checked {$avatar_disk_dir}{$filename}");
                }
            }
        }
    } else {
        $result_data['errors'][] = 'No avatar_base provided';
        pod_log("scene {$scene_id}: no avatar_base");
    }

    pod_log('process_avatar_scene RESULT: ' . json_encode($result_data));
    pod_json($result_data);

  } catch (Throwable $e) {
    pod_log('process_avatar_scene EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    pod_json(['success' => false, 'error' => 'process_avatar_scene: ' . $e->getMessage()]);
  }
}

// ============================================================================
// ACTION: get_host_guest_names
// Called ONCE before the scene loop. Resolves the base image name for host
// and guest using find_host_imagename() from hdb_hostguest_images.
// Returns: { host_imagename: 'host_male_2', guest_imagename: 'host_female_1' }
// ============================================================================
if ($action === 'get_host_guest_names') {

    $host_voice  = trim($_POST['host_voice']  ?? '');
    $guest_voice = trim($_POST['guest_voice'] ?? $host_voice);

    // Look up genders
    $host_gender  = get_voice_gender($conn, $host_voice);
    $guest_gender = get_voice_gender($conn, $guest_voice);

    pod_log("get_host_guest_names: host_voice={$host_voice} gender={$host_gender} | guest_voice={$guest_voice} gender={$guest_gender}");

    // Load image pools
    $images_male   = get_hostguest_images($conn, 'male');
    $images_female = get_hostguest_images($conn, 'female');

    // Pick host image name
    $host_pool      = ($host_gender === 'female') ? $images_female : $images_male;
    $host_imagename = find_host_imagename('', $host_pool);

    // Pick guest image name — must differ from host if same gender pool
    $guest_pool      = ($guest_gender === 'female') ? $images_female : $images_male;
    $prev_for_guest  = ($guest_gender === $host_gender) ? $host_imagename : '';
    $guest_imagename = find_host_imagename($prev_for_guest, $guest_pool);

    pod_log("get_host_guest_names: host_imagename={$host_imagename} | guest_imagename={$guest_imagename}");

    pod_json([
        'success'         => true,
        'host_imagename'  => $host_imagename  ?? '',
        'guest_imagename' => $guest_imagename ?? '',
        'host_gender'     => $host_gender,
        'guest_gender'    => $guest_gender,
    ]);
}


// ============================================================================
// ACTION: update_podcast_voice
// Saves host/guest voice and rate to hdb_podcasts
// ============================================================================
if ($action === 'update_podcast_voice') {

    $podcast_id  = (int)   ($_POST['podcast_id']  ?? 0);
    $host_voice  = mysqli_real_escape_string($conn, trim($_POST['host_voice']  ?? ''));
    $guest_voice = mysqli_real_escape_string($conn, trim($_POST['guest_voice'] ?? $host_voice));
    $rate        = (float) ($_POST['rate'] ?? 1.0);

    if (!$podcast_id) {
        pod_json(['success' => false, 'error' => 'Missing podcast_id']);
    }

    $ok = mysqli_query($conn,
        "UPDATE hdb_podcasts
         SET host_voice  = '{$host_voice}',
             guest_voice = '{$guest_voice}',
             voice_rate  = {$rate},
             updated_at  = NOW()
         WHERE id = {$podcast_id} AND admin_id = {$admin_id}"
    );

    pod_log("update_podcast_voice: podcast={$podcast_id} host={$host_voice} guest={$guest_voice} rate={$rate} ok=" . ($ok ? '1' : '0'));
    pod_json(['success' => (bool) $ok, 'affected' => mysqli_affected_rows($conn)]);
}


// ============================================================================
// ACTION: check_audio_file
// Deletes stale audio file before regenerating (prevents cached playback)
// ============================================================================
if ($action === 'check_audio_file') {

    $filename = basename($_POST['filename'] ?? '');
    $filepath = __DIR__ . '/podcast_audios/' . $filename;
    $exists   = file_exists($filepath);
    $deleted  = $exists ? @unlink($filepath) : false;
    pod_json(['success' => true, 'exists' => $exists, 'deleted' => $deleted]);
}


// ============================================================================
// ACTION: get_podcaster_images
// Returns thumbnails for the host and guest pickers.
// Host thumbnails → podcast_hosts/ folder
// Guest thumbnails → podcast_guests/ folder
// Actual pose images (p1–p4) remain in podcast_images/ — unchanged.
// ============================================================================
if ($action === 'get_podcaster_images') {

    function scan_thumb_folder(string $dir): array {
        $items = [];
        if (!is_dir($dir)) return $items;
        $files = array_merge(
            glob($dir . '*.png')  ?: [],
            glob($dir . '*.jpg')  ?: [],
            glob($dir . '*.jpeg') ?: [],
            glob($dir . '*.PNG')  ?: [],
            glob($dir . '*.JPG')  ?: []
        );
        foreach ($files as $filepath) {
            $fname = basename($filepath);
            // Strip _p1.ext or just .ext to get the base name used for pose cycling
            $base = preg_replace('/(_p[0-9]+)?\.(png|jpg|jpeg)$/i', '', $fname);
            if (!empty($base)) {
                $items[] = ['image_name' => $base, 'thumb' => $fname, 'gender' => ''];
            }
        }
        usort($items, fn($a, $b) => strcmp($a['image_name'], $b['image_name']));
        return $items;
    }

    $host_images  = scan_thumb_folder(__DIR__ . '/podcast_hosts/');
    $guest_images = scan_thumb_folder(__DIR__ . '/podcast_guests/');

    // Fallback to hdb_hostguest_images if folders are empty
    if (empty($host_images) && empty($guest_images)) {
        $result = mysqli_query($conn,
            "SELECT image_name, gender FROM hdb_hostguest_images ORDER BY gender ASC, id ASC");
        $all = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $all[] = [
                    'image_name' => $row['image_name'],
                    'gender'     => $row['gender'],
                    'thumb'      => $row['image_name'] . '_p1.png',
                ];
            }
        }
        // Split by gender into host/guest buckets for consistent JS handling
        $host_images  = array_values(array_filter($all, fn($r) => $r['gender'] !== 'female'));
        $guest_images = array_values(array_filter($all, fn($r) => $r['gender'] === 'female'));
        if (empty($host_images))  $host_images  = $all;
        if (empty($guest_images)) $guest_images = $all;
    }

    pod_log("get_podcaster_images: hosts=" . count($host_images) . " guests=" . count($guest_images));

    pod_json([
        'success'       => true,
        'host_images'   => $host_images,
        'guest_images'  => $guest_images,
        'images'        => array_merge($host_images, $guest_images),
    ]);
}



// ============================================================================
// ACTION: get_avatar_images
// Returns avatar thumbnails for the talking-head image picker.
// Scans the avatars/ folder; falls back to hdb_image_data if empty.
// Response: { success, avatars: [ { avatar_base, thumb } ] }
// ============================================================================
if ($action === 'get_avatar_images') {

    if (!function_exists('scan_thumb_folder')) {
        function scan_thumb_folder(string $dir): array {
            $items = [];
            if (!is_dir($dir)) return $items;
            $files = array_merge(
                glob($dir . '*.png')  ?: [],
                glob($dir . '*.jpg')  ?: [],
                glob($dir . '*.jpeg') ?: [],
                glob($dir . '*.PNG')  ?: [],
                glob($dir . '*.JPG')  ?: []
            );
            foreach ($files as $filepath) {
                $fname = basename($filepath);
                $base  = preg_replace('/(_p[0-9]+)?\.(png|jpg|jpeg)$/i', '', $fname);
                if (!empty($base)) {
                    $items[] = ['image_name' => $base, 'thumb' => $fname];
                }
            }
            usort($items, fn($a, $b) => strcmp($a['image_name'], $b['image_name']));
            return $items;
        }
    }

    $avatar_dir = __DIR__ . '/podcast_avatars_thumbnails/';
    $raw_list   = scan_thumb_folder($avatar_dir);

    // Deduplicate by base name — keep first (p1) thumb per avatar
    $seen    = [];
    $avatars = [];
    foreach ($raw_list as $item) {
        $base = $item['image_name'];
        if (!isset($seen[$base])) {
            $seen[$base] = true;
            $avatars[] = [
                'avatar_base' => $base,
                'thumb'       => $item['thumb'],
            ];
        }
    }

    // Fallback: query hdb_image_data for avatar images if folder is empty
    if (empty($avatars)) {
        $res = mysqli_query($conn,
            "SELECT image_name FROM hdb_image_data
             WHERE image_folder = 'podcast_avatars' OR image_name LIKE 'avatar%'
             ORDER BY image_name ASC LIMIT 100");
        if ($res) {
            $seen = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $base = preg_replace('/(_p[0-9]+)?$/', '', $row['image_name']);
                if (!empty($base) && !isset($seen[$base])) {
                    $seen[$base] = true;
                    $avatars[] = [
                        'avatar_base' => $base,
                        'thumb'       => $base . '_p1.png',
                    ];
                }
            }
        }
    }

    pod_log("get_avatar_images: found " . count($avatars) . " avatars in {$avatar_dir}");

    if (empty($avatars)) {
        pod_json(['success' => false, 'error' => 'No avatar images found. Please upload avatars to the podcast_avatars_thumbnails/ folder.', 'avatars' => []]);
    } else {
        pod_json(['success' => true, 'avatars' => $avatars]);
    }
}

// ============================================================================
// ACTION: get_host_numbers
// Returns available host numbers per gender so JS can randomly pick one
// ============================================================================
if ($action === 'get_host_numbers') {

    $gender  = strtolower(trim($_POST['gender'] ?? 'male'));
    if (!in_array($gender, ['male', 'female'])) $gender = 'male';

    $numbers = get_available_host_numbers($conn, $gender);

    pod_json([
        'success' => true,
        'gender'  => $gender,
        'numbers' => $numbers,
    ]);
}


// ============================================================================
// ACTION: create_scenes_from_podcast
// Called after the podcast record already exists in hdb_podcasts.
// Reads script_text, splits into lines, detects host/guest from prefix,
// strips the prefix, then inserts clean rows into hdb_podcast_stories.
// ============================================================================
if ($action === 'create_scenes_from_podcast') {

    try {
        $podcast_id  = (int)   ($_POST['podcast_id']  ?? 0);
        $host_voice  = mysqli_real_escape_string($conn, trim($_POST['host_voice']  ?? ''));
        $guest_voice = mysqli_real_escape_string($conn, trim($_POST['guest_voice'] ?? $_POST['host_voice'] ?? ''));
        $voice_rate  = (float) ($_POST['rate']         ?? 1.0);
        $lang_code   = mysqli_real_escape_string($conn, trim($_POST['lang_code']   ?? 'en'));

        if (!$podcast_id) {
            pod_json(['success' => false, 'error' => 'Missing podcast_id']);
        }

        // Load podcast row
        $pod_res = mysqli_query($conn,
            "SELECT * FROM hdb_podcasts WHERE id = {$podcast_id} AND admin_id = {$admin_id} LIMIT 1"
        );
        if (!$pod_res || mysqli_num_rows($pod_res) === 0) {
            pod_json(['success' => false, 'error' => 'Podcast not found']);
        }
        $pod = mysqli_fetch_assoc($pod_res);

        $script_text = trim($pod['script_text'] ?? '');
        $title       = $pod['title']     ?? '';
        $category    = $pod['category']  ?? 'free-format';
        $topic_key   = $pod['topic_key'] ?? 'general';

        if (empty($script_text)) {
            pod_json(['success' => false, 'error' => 'No script_text found in podcast record']);
        }

        pod_log("create_scenes_from_podcast: podcast={$podcast_id} | title={$title}");

        // Clear existing scenes for a clean rebuild
        mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id = {$podcast_id}");
        mysqli_query($conn, "DELETE FROM hdb_captions       WHERE podcast_id = {$podcast_id}");

        // Update podcast with voice settings
        mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET host_voice  = '{$host_voice}',
                 guest_voice = '{$guest_voice}',
                 voice_rate  = {$voice_rate},
                 lang_code   = '{$lang_code}',
                 internal_status = 'processing',
                 updated_at  = NOW()
             WHERE id = {$podcast_id}"
        );

        // Split script into lines — one line = one scene
        $raw_lines = explode("\n", $script_text);
        $lines     = array_values(array_filter(array_map('trim', $raw_lines)));

        if (empty($lines)) {
            pod_json(['success' => false, 'error' => 'Script produced no lines after splitting']);
        }

        $esc_title    = mysqli_real_escape_string($conn, $title);
        $esc_category = mysqli_real_escape_string($conn, $category);
        $esc_topic    = mysqli_real_escape_string($conn, $topic_key);
        $user_settings = getUserSettingsWiz($conn, $admin_id);
        $success_count = 0;

        foreach ($lines as $i => $raw_line) {
            if (empty($raw_line)) continue;

            // Detect actor from prefix BEFORE stripping
            $actor = 'host';
            if (preg_match('/^guest\s*:/i', $raw_line)) $actor = 'guest';

            // Strip "host:" / "guest:" prefix for clean storage
            $clean_text = strip_actor_prefix($raw_line);
            if (empty($clean_text)) continue;

            $seq_no     = $i + 1;
            $word_count = count(array_filter(explode(' ', $clean_text)));
            $duration   = max(3, (int) round(($word_count / 130) * 60));
            $voice_id   = ($actor === 'guest' && !empty($guest_voice)) ? $guest_voice : $host_voice;
            $disp       = substr($clean_text, 0, 50) . (strlen($clean_text) > 50 ? '...' : '');

            $te = mysqli_real_escape_string($conn, $clean_text);
            $de = mysqli_real_escape_string($conn, $disp);
            $ae = mysqli_real_escape_string($conn, $actor);
            $ve = mysqli_real_escape_string($conn, $voice_id);
			$tk = mysqli_real_escape_string($conn, $title);
             $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt,
                 status, created_date, scene_order, voice_id, voice_rate)
                VALUES
                ({$podcast_id}, '{$lang_code}', '{$category}', '{$topic_key}', '{$tk}', '{$ae}',
                 '{$te}', '{$de}', {$duration}, '',
                 'PENDING', NOW(), {$seq_no}, '{$ve}', {$voice_rate})";

            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                buildCaptionRows($conn, $podcast_id, $story_id, $clean_text, $user_settings, false);
                $success_count++;
            } else {
                pod_log("scene {$seq_no} INSERT FAIL: " . mysqli_error($conn));
            }
        }

        mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET internal_status = 'scenes_ready', video_type='standard', updated_at = NOW()
             WHERE id = {$podcast_id}"
        );

        pod_log("create_scenes_from_podcast done: {$success_count} scenes for podcast={$podcast_id} | reset video_type=standard");
       // deductCredit($conn, $admin_id, $reel_type);
        pod_json([
            'success'     => true,
            'podcast_id'  => $podcast_id,
            'scene_count' => $success_count,
        ]);

    } catch (Throwable $e) {
        pod_log("create_scenes_from_podcast EXCEPTION: " . $e->getMessage());
        pod_json(['success' => false, 'error' => $e->getMessage()]);
    }
}


// ============================================================================
// ACTION: create_scenes_from_avatar
// Same as create_scenes_from_podcast but for Talking Head mode:
// single voice (host only), actor always 'host', image_folder = 'podcast_avatars'
// ============================================================================
if ($action === 'create_scenes_from_avatar') {

    try {
        $podcast_id  = (int)   ($_POST['podcast_id']  ?? 0);
        $host_voice  = mysqli_real_escape_string($conn, trim($_POST['host_voice'] ?? ''));
        $voice_rate  = (float) ($_POST['rate']        ?? 1.0);
        $lang_code   = mysqli_real_escape_string($conn, trim($_POST['lang_code'] ?? 'en'));

        if (!$podcast_id) {
            pod_json(['success' => false, 'error' => 'Missing podcast_id']);
        }

        // Load podcast row
        $pod_res = mysqli_query($conn,
            "SELECT * FROM hdb_podcasts WHERE id = {$podcast_id} AND admin_id = {$admin_id} LIMIT 1"
        );
        if (!$pod_res || mysqli_num_rows($pod_res) === 0) {
            pod_json(['success' => false, 'error' => 'Podcast not found']);
        }
        $pod = mysqli_fetch_assoc($pod_res);

        $script_text = trim($pod['script_text'] ?? '');
        $title       = $pod['title']     ?? '';
        $category    = $pod['category']  ?? 'free-format';
        $topic_key   = $pod['topic_key'] ?? 'general';

        if (empty($script_text)) {
            pod_json(['success' => false, 'error' => 'No script_text found in podcast record']);
        }

        pod_log("create_scenes_from_avatar: podcast={$podcast_id} | title={$title}");

        // Clear existing scenes for a clean rebuild
        mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id = {$podcast_id}");
        mysqli_query($conn, "DELETE FROM hdb_captions       WHERE podcast_id = {$podcast_id}");

        // Update podcast with voice settings
        mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET host_voice      = '{$host_voice}',
                 guest_voice     = '{$host_voice}',
                 voice_rate      = {$voice_rate},
                 lang_code       = '{$lang_code}',
                 internal_status = 'processing',
                 updated_at      = NOW()
             WHERE id = {$podcast_id}"
        );

        // Split script into lines — one line = one scene
        $raw_lines = explode("\n", $script_text);
        $lines     = array_values(array_filter(array_map('trim', $raw_lines)));

        if (empty($lines)) {
            pod_json(['success' => false, 'error' => 'Script produced no lines after splitting']);
        }

        $esc_title     = mysqli_real_escape_string($conn, $title);
        $esc_category  = mysqli_real_escape_string($conn, $category);
        $esc_topic     = mysqli_real_escape_string($conn, $topic_key);
        $user_settings = getUserSettingsWiz($conn, $admin_id);
        $success_count = 0;

        foreach ($lines as $i => $raw_line) {
            if (empty($raw_line)) continue;

            // Talking head — always host, strip any host:/guest: prefix
            $clean_text = strip_actor_prefix($raw_line);
            if (empty($clean_text)) continue;

            $seq_no     = $i + 1;
            $word_count = count(array_filter(explode(' ', $clean_text)));
            $duration   = max(3, (int) round(($word_count / 130) * 60));
            $disp       = substr($clean_text, 0, 50) . (strlen($clean_text) > 50 ? '...' : '');

            $te = mysqli_real_escape_string($conn, $clean_text);
            $de = mysqli_real_escape_string($conn, $disp);
            $ve = $host_voice;
            $tk = $esc_title;

            $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt,
                 status, created_date, scene_order, voice_id, voice_rate)
                VALUES
                ({$podcast_id}, '{$lang_code}', '{$esc_category}', '{$esc_topic}', '{$tk}', 'host',
                 '{$te}', '{$de}', {$duration}, '',
                 'PENDING', NOW(), {$seq_no}, '{$ve}', {$voice_rate})";

            if (mysqli_query($conn, $ins)) {
                $story_id = mysqli_insert_id($conn);
                buildCaptionRows($conn, $podcast_id, $story_id, $clean_text, $user_settings, false);
                $success_count++;
            } else {
                pod_log("scene {$seq_no} INSERT FAIL: " . mysqli_error($conn));
            }
        }

        mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET internal_status = 'scenes_ready', video_type = 'talking_head', updated_at = NOW()
             WHERE id = {$podcast_id}"
        );

        pod_log("create_scenes_from_avatar done: {$success_count} scenes for podcast={$podcast_id}");

        pod_json([
            'success'     => true,
            'podcast_id'  => $podcast_id,
            'scene_count' => $success_count,
        ]);

    } catch (Throwable $e) {
        pod_log('create_scenes_from_avatar EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        pod_json(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================================
// Unknown action
// ============================================================================
pod_log("Unknown action: {$action}");
pod_json(['success' => false, 'error' => 'Unknown action: ' . $action]);

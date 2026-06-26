<?php
// ============================================================================
// wizard_step2_common.php
// Shared functions for all wizard_step2 variants
// ============================================================================

// ── Logging ──────────────────────────────────────────────────────────────────
function wiz2_log(string $msg, string $context = 'common'): void {
    error_log("[wizard_step2_{$context}] " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ── Clean scene text — strip actor prefix AND <break> tags ───────────────────
function wiz2_strip_actor_prefix(string $text): string {
    $text = preg_replace('/^(host|guest)\s*:\s*/i', '', trim($text));
    $text = preg_replace('/<break[^>]*\/?>/i', '', $text);
    $text = preg_replace('/  +/', ' ', $text);
    return trim($text);
}

// ── Get User Settings with defaults ──────────────────────────────────────────
function wiz2_getUserSettings(mysqli $conn, int $admin_id): array {
    $q = mysqli_query($conn,
        "SELECT * FROM hdb_user_settings
         WHERE admin_id='{$admin_id}'
         ORDER BY FIELD(text_type,'caption','header','footer','logo')
         LIMIT 10");
    
    $settings = ['caption' => null, 'header' => null, 'footer' => null, 'logo' => null];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $t = $r['text_type'] ?? 'caption';
            if (array_key_exists($t, $settings)) $settings[$t] = $r;
        }
    }
    
    // Default caption settings
    $def_caption = [
        'text_type' => 'caption', 'is_enabled' => 1,
        'fontfamily' => 'Arial', 'fontsize' => 28,
        'fontcolor' => '#ffff00', 'fontweight' => 'bold',
        'fontcolor_bg' => '#000000', 'fontbg_enable' => 0,
        'font_italic' => 0, 'font_underline' => 0,
        'caption_style' => 'none', 'caption_position' => 'bottom',
        'caption_alignment' => 'center', 'caption_speed' => 1.0,
        'text_effect' => 'none', 'text_animation' => 'static',
        'display_mode' => 'full', 'animation_speed' => 'medium',
        'position_x' => 50, 'position_y' => 250, 'width' => 500,
        'stroke_color' => '#000000', 'stroke_width' => 0,
        'shadow_color' => '#000000', 'gradient_color' => '#ff6600',
        'text_align_v' => 'bottom',
        'logo_name' => '', 'logo_file' => '', 'logo_size' => '60',
        'logo_position' => 'top-right', 'logo_pos_h' => 'right', 'logo_pos_v' => 'top',
        'logo_size_pct' => 15, 'logo_enabled' => 0,
        'caption_text' => '',
    ];
    
    $def_header = array_merge($def_caption, [
        'text_type' => 'header', 'is_enabled' => 0,
        'fontfamily' => 'Helvetica', 'fontsize' => 16,
        'fontcolor' => '#ffffff', 'fontweight' => 'bold',
        'fontcolor_bg' => '#1a1a2e', 'fontbg_enable' => 1,
        'caption_style' => 'box', 'caption_position' => 'top',
        'text_animation' => 'fade_in', 'position_x' => 0, 'position_y' => 0, 'width' => 1080,
        'text_align_v' => 'top',
        'caption_text' => '',
    ]);
    
    $def_footer = array_merge($def_caption, [
        'text_type' => 'footer', 'is_enabled' => 0,
        'fontfamily' => 'Georgia', 'fontsize' => 12,
        'fontcolor' => '#aaaaaa', 'fontweight' => 'normal',
        'fontcolor_bg' => '#000000', 'fontbg_enable' => 0,
        'caption_style' => 'none', 'caption_position' => 'bottom',
        'text_animation' => 'static', 'animation_speed' => 'slow',
        'position_x' => 0, 'position_y' => 0, 'width' => 1080,
        'text_align_v' => 'bottom',
        'caption_text' => '',
    ]);
    
    $def_logo = [
        'text_type' => 'logo', 'is_enabled' => 0,
        'logo_name' => '', 'logo_file' => '',
        'logo_pos_h' => 'right', 'logo_pos_v' => 'top',
        'logo_size_pct' => 15, 'position_x' => 960, 'position_y' => 20, 'width' => 120,
    ];
    
    $settings['caption'] = $settings['caption'] ? array_merge($def_caption, $settings['caption']) : $def_caption;
    $settings['header']  = $settings['header']  ? array_merge($def_header, $settings['header'])   : $def_header;
    $settings['footer']  = $settings['footer']  ? array_merge($def_footer, $settings['footer'])   : $def_footer;
    $settings['logo']    = $settings['logo']    ? array_merge($def_logo, $settings['logo'])       : $def_logo;
    
    return $settings;
}

// ── Build effect colors string ───────────────────────────────────────────────
function wiz2_buildEffectColors(array $settings): string {
    $shadow  = $settings['shadow_color']   ?? '#000000';
    $gradient = $settings['gradient_color'] ?? '#ff6600';
    $stroke  = $settings['stroke_color']   ?? '#000000';
    return "$shadow,$gradient,$stroke";
}

// ── Build Caption Rows (ALL 4 types: caption, header, footer, logo) ─────────
function wiz2_buildCaptionRows(mysqli $conn, int $podcast_id, int $story_id, string $scene_text, array $user_settings, bool $is_broll = false): int {
    $cap = $user_settings['caption'];
    $hdr = $user_settings['header'];
    $ftr = $user_settings['footer'];
    $logo_settings = $user_settings['logo'];
    $rows = 0;
    
    $esc = function($val) use ($conn) { return mysqli_real_escape_string($conn, $val); };
    
    // Helper to insert caption row
    $insertCaption = function($type, $name, $text, $settings, $z_index, $override_y = null) use ($conn, $podcast_id, $story_id, $esc, $is_broll) {
        $ff           = $esc($settings['fontfamily']       ?? 'Arial');
        $fs           = (int)($settings['fontsize']        ?? 28);
        $fc           = $esc($settings['fontcolor']        ?? '#ffffff');
        $fw           = $esc($settings['fontweight']       ?? 'normal');
        $fitalic      = (int)($settings['font_italic']     ?? 0);
        $funderline   = (int)($settings['font_underline']  ?? 0);
        $fontstyle    = $fw . ($fitalic ? ' italic' : '');
        $text_decoration = $funderline ? 'underline' : 'none';
        $bgc          = $esc($settings['fontcolor_bg']     ?? '#000000');
        $bge          = (int)($settings['fontbg_enable']   ?? 0);
        $align        = $esc($settings['caption_alignment'] ?? 'center');
        $px           = (int)($settings['position_x']      ?? 50);
        $py           = $override_y !== null ? $override_y : (int)($settings['position_y'] ?? 250);
        $pw           = min((int)($settings['width'] ?? 500), 1080);
        $cspd         = (float)($settings['caption_speed'] ?? 1.0);
        $anim         = $esc($settings['text_animation']   ?? 'static');
        $text_effect  = $esc($settings['text_effect']      ?? 'none');
        $effect_colors = wiz2_buildEffectColors($settings);
        $stroke_width = (int)($settings['stroke_width']    ?? 0);
        $stroke_color = $esc($settings['stroke_color']     ?? '#000000');
        $shadow_color = $esc($settings['shadow_color']     ?? '#000000');
        $gradient_color = $esc($settings['gradient_color'] ?? '#ff6600');
        $display_mode = $esc($settings['display_mode']     ?? 'full');
        $caption_style = $esc($settings['caption_style']   ?? 'none');
        $caption_pos   = $esc($settings['caption_position'] ?? 'bottom');
        $text_align_v  = $esc($settings['text_align_v']    ?? 'bottom');
        $caption_text  = $esc($type === 'caption' ? $scene_text : ($settings['caption_text'] ?? ''));
        
        // B-Roll override
        if ($is_broll && $type === 'caption') {
            $py = 25;
            $fs = 20;
        }
        
        $sql = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index,
             text_effects, text_effect_colors, stroke_width, stroke_color,
             shadow_color, gradient_color, display_mode, caption_style,
             caption_position, text_align_v, underline, text_decoration)
            VALUES
            ({$podcast_id}, {$story_id}, '{$type}', '{$name}', '{$caption_text}',
             '{$ff}', {$fs}, '{$fc}', '{$fw}', '{$fontstyle}', '{$align}',
             '{$bgc}', {$bge}, {$px}, {$py}, {$pw}, 0,
             '{$anim}', {$cspd}, 1, {$z_index},
             '{$text_effect}', '{$effect_colors}', {$stroke_width}, '{$stroke_color}',
             '{$shadow_color}', '{$gradient_color}', '{$display_mode}', '{$caption_style}',
             '{$caption_pos}', '{$text_align_v}', {$funderline}, '{$text_decoration}')";
        
        if (mysqli_query($conn, $sql)) {
            wiz2_log("{$type} inserted: story={$story_id}", 'captions');
            return 1;
        } else {
            wiz2_log("{$type} INSERT failed: " . mysqli_error($conn), 'captions');
            return 0;
        }
    };
    
    // 1. MAIN CAPTION
    if ((int)($cap['is_enabled'] ?? 1)) {
        $rows += $insertCaption('caption', 'main', $scene_text, $cap, 1);
    }
    
    // 2. HEADER
    if ((int)($hdr['is_enabled'] ?? 0)) {
        $rows += $insertCaption('header', 'header', $scene_text, $hdr, 2);
    }
    
    // 3. FOOTER
    if ((int)($ftr['is_enabled'] ?? 0)) {
        $rows += $insertCaption('footer', 'footer', $scene_text, $ftr, 3);
    }
    
    // 4. LOGO
    $logo_enabled = (int)($logo_settings['logo_enabled'] ?? 0);
    $logo_file = trim($logo_settings['logo_file'] ?? $logo_settings['logo_name'] ?? '');
    
    if ($logo_enabled && !empty($logo_file)) {
        $lf = $esc($logo_file);
        $lpx = (int)($logo_settings['position_x'] ?? 960);
        $lpy = (int)($logo_settings['position_y'] ?? 20);
        $lw = (int)($logo_settings['width'] ?? 120);
        
        // Alternative positioning using logo_pos_h/v
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
            wiz2_log("Logo inserted: {$logo_file} (type: {$media_type})", 'captions');
            $rows++;
        } else {
            wiz2_log("Logo INSERT failed: " . mysqli_error($conn), 'captions');
        }
    }
    
    wiz2_log("buildCaptionRows: podcast={$podcast_id} story={$story_id} rows={$rows}", 'captions');
    return $rows;
}

// ── OpenAI TTS ────────────────────────────────────────────────────────────────
function wiz2_generate_audio_openai(string $text, string $voice_id, string $filepath): array {
    global $apiKey;
    $voice = strpos($voice_id, ':') !== false ? substr($voice_id, strpos($voice_id, ':') + 1) : 'alloy';
    if (empty(trim($voice))) $voice = 'alloy';
    
    $dir = dirname($filepath);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    $payload = json_encode(['model' => 'gpt-4o-mini-tts', 'voice' => $voice, 'input' => $text], JSON_UNESCAPED_UNICODE);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.openai.com/v1/audio/speech',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    
    if ($curl_err) return ['success' => false, 'error' => 'cURL: ' . $curl_err];
    if ($http_code !== 200) return ['success' => false, 'error' => 'OpenAI TTS HTTP ' . $http_code];
    
    file_put_contents($filepath, $response);
    return ['success' => true];
}

// ── Azure TTS ────────────────────────────────────────────────────────────────
function wiz2_generate_audio_azure(string $text, string $voice_id, string $rate, string $filepath): array {
    if (!file_exists(__DIR__ . '/chatgpt_functions.php')) {
        return ['success' => false, 'error' => 'chatgpt_functions.php not found'];
    }
    require_once __DIR__ . '/chatgpt_functions.php';
    return generateVoice($text, $voice_id, $rate, $filepath);
}

// ── Route audio generation ───────────────────────────────────────────────────
function wiz2_generate_audio(string $text, string $voice_id, string $rate, string $filepath): array {
    if (strpos($voice_id, 'openai:') === 0) {
        return wiz2_generate_audio_openai($text, $voice_id, $filepath);
    }
    return wiz2_generate_audio_azure($text, $voice_id, $rate, $filepath);
}

// ── MP3 duration reader ──────────────────────────────────────────────────────
function wiz2_getMp3DurationSeconds($filepath) {
    $fh = @fopen($filepath, 'rb');
    if (!$fh) return null;
    
    $bitrate_table = ['1_3' => [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0]];
    $samplerate_table = ['1' => [44100, 48000, 32000, 0]];
    
    $total_duration = 0.0;
    $frames_found = 0;
    $scan_limit = 65536;
    $scanned = 0;
    $filesize = filesize($filepath);
    
    $header = fread($fh, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $sz = ((ord($header[6]) & 0x7f) << 21) | ((ord($header[7]) & 0x7f) << 14) | ((ord($header[8]) & 0x7f) << 7) | (ord($header[9]) & 0x7f);
        fseek($fh, 10 + $sz);
    } else {
        fseek($fh, 0);
    }
    
    while (!feof($fh) && $scanned < $scan_limit) {
        $byte = fread($fh, 1);
        if ($byte === false || $byte === '') break;
        $scanned++;
        if (ord($byte) !== 0xFF) continue;
        
        $next = fread($fh, 1);
        if ($next === false) break;
        $scanned++;
        $b2 = ord($next);
        if (($b2 & 0xE0) !== 0xE0) continue;
        
        $mpeg_ver = ($b2 >> 3) & 0x03;
        $layer = ($b2 >> 1) & 0x03;
        
        $header4 = fread($fh, 2);
        if (strlen($header4) < 2) break;
        $scanned += 2;
        $b3 = ord($header4[0]);
        $b4 = ord($header4[1]);
        
        $bitrate_idx = ($b3 >> 4) & 0x0F;
        $samplerate_idx = ($b3 >> 2) & 0x03;
        $padding = ($b3 >> 1) & 0x01;
        
        if ($mpeg_ver !== 3 || $layer !== 1) {
            fseek($fh, -2, SEEK_CUR);
            $scanned -= 2;
            continue;
        }
        
        $bitrate = $bitrate_table['1_3'][$bitrate_idx] ?? 0;
        $samplerate = $samplerate_table['1'][$samplerate_idx] ?? 0;
        if ($bitrate === 0 || $samplerate === 0) continue;
        
        $frame_size = (int)(144 * $bitrate * 1000 / $samplerate) + $padding;
        $frame_dur = 1152 / $samplerate;
        
        $frames_found++;
        if ($frames_found === 1) {
            $data_bytes = $filesize - ftell($fh) + 4;
            $total_duration = ($data_bytes / $frame_size) * $frame_dur;
            break;
        }
    }
    
    fclose($fh);
    if ($total_duration > 0) return (int)ceil($total_duration);
    if ($filesize > 0) return (int)ceil(($filesize * 8) / (128 * 1000));
    return null;
}

// ── Credit deduction helper ───────────────────────────────────────────────────
function wiz2_deductCredit(mysqli $conn, int $admin_id, string $reel_type): void {
    $rt = strtolower($reel_type ?? '');
    $cost = (strpos($rt, 'podcast') !== false || strpos($rt, 'talking head') !== false) ? 2 : 1;
    
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT role, team_lead_id FROM hdb_users WHERE id={$admin_id} LIMIT 1"));
    $deduct_from = ($urow['role'] === 'Team Member' && (int)$urow['team_lead_id'] > 0)
        ? (int)$urow['team_lead_id']
        : $admin_id;
    
    mysqli_query($conn,
        "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - {$cost}) WHERE id = {$deduct_from}");
}

// ── Get voice gender from hdb_voices ─────────────────────────────────────────
function wiz2_getVoiceGender(mysqli $conn, string $voice_id): string {
    $lookup_key = strpos($voice_id, 'openai:') === 0 ? substr($voice_id, 7) : $voice_id;
    $esc = mysqli_real_escape_string($conn, $lookup_key);
    $result = mysqli_query($conn, "SELECT gender FROM hdb_voices WHERE voice_key = '{$esc}' LIMIT 1");
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $g = strtolower(trim($row['gender'] ?? ''));
        if ($g === 'male' || $g === 'female') return $g;
    }
    return 'male';
}
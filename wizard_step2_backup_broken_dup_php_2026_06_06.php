<?php
	// wizard_step2.php
	// Handles all Step 2 AJAX actions for the VideoVizard wizard
	// Actions: create_scenes_from_content, create_scenes_from_podcast,
	//          get_scenes, generate_scene_audio, check_audio_file,
	//          check_podcast, assign_image, log_media_search,
	//          search_images, update_scene_tags, update_podcast_voice,
	//          rebuild_scenes, get_thumbnail_from_scene, save_edited_script
	<?php
    // wizard_step2.php
    // ── CRITICAL: suppress display_errors BEFORE any includes ──
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/a_errors.log');
    error_reporting(E_ALL);
    ob_start();
    // ── NOW safe to load dependencies ──────────────────────────
    require_once 'image_search_functions.php';
	
	error_reporting(E_ALL);

	function wiz_log($msg) {
		error_log('[wizard_step2] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
	}

	// ── Timing helpers ────────────────────────────────────────────────────────────
	$_wiz_req_start = microtime(true);

	function wiz_ms($start) {
		return round((microtime(true) - $start) * 1000) . 'ms';
	}
	function wiz_sec($start) {
		return round(microtime(true) - $start, 2) . 's';
	}
	function wiz_elapsed() {
		global $_wiz_req_start;
		return round((microtime(true) - $_wiz_req_start) * 1000) . 'ms';
	}

	// Session
	$timeout = 30 * 24 * 60 * 60;
	if (session_status() === PHP_SESSION_NONE)
		session_set_cookie_params(['lifetime'=>$timeout,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
	if (session_status() === PHP_SESSION_NONE) session_start();

	if (ob_get_level()) ob_clean();
	if (!headers_sent()) header('Content-Type: application/json');

	if (!isset($_SESSION['admin_id'])) {
		if (!empty($_POST['admin_id'])) {
			$_SESSION['admin_id'] = (int)$_POST['admin_id'];
		} else {
			echo json_encode(['success'=>false,'error'=>'Not authenticated']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}
	}

	$admin_id  = (int)$_SESSION['admin_id'];
	$client_id = (int)($_SESSION['client_id'] ?? $admin_id);
	session_write_close(); // Release session lock so parallel AJAX requests don't queue

	include 'dbconnect_hdb.php';
	require_once __DIR__ . '/config.php';

	if (!$conn) {
		echo json_encode(['success'=>false,'error'=>'DB connection failed']);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ── Resolve team_lead_id for this podcast row ────────────────────────────────
	// Team Member  → use hdb_users.team_lead_id (never 0)
	// Team Leader / anyone else → use their own admin_id
	$_u = mysqli_fetch_assoc(mysqli_query($conn,
		"SELECT role, team_lead_id FROM hdb_users WHERE id = $admin_id LIMIT 1"));
	wiz_log("DEBUG tl: admin=$admin_id role='" . (string)($_u['role'] ?? 'NULL') . "' hdb_tl=" . (string)($_u['team_lead_id'] ?? 'NULL'));
	if (!empty($_u) && trim((string)$_u['role']) === 'Team Member' && (int)$_u['team_lead_id'] > 0) {
		$team_lead_id = (int)$_u['team_lead_id'];
	} else {
		$team_lead_id = $admin_id;
	}
	wiz_log("DEBUG tl: final team_lead_id=$team_lead_id");

	// ── Credit deduction helper ───────────────────────────────────────────────────
	// Deducts credits from the correct user:
	// - Team Member: deducts from their Team Lead's credit_balance
	// - Team Lead / regular user: deducts from their own credit_balance
	// Cost: podcast / talking head = 2 credits, standard / b-roll = 1 credit
	function deductCredit($conn, $admin_id, $reel_type) {
		$rt          = strtolower($reel_type ?? '');
		$media_type  = strtolower($_POST['media_type'] ?? '');
		$is_ai_media = strpos($media_type, 'unique_image') !== false || strpos($media_type, 'ai_image') !== false || strpos($media_type, 'ai image') !== false;
		$cost = (strpos($rt, 'podcast') !== false || strpos($rt, 'talking head') !== false || $is_ai_media) ? 3 : 1;
		// Check if this user is a Team Member
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

	// ── OpenAI TTS ────────────────────────────────────────────────────────────────
	function generateVoiceOpenAI_wiz($text, $voice_id, $filepath, $rate = '1.0') {
		global $apiKey;
		$voice = strpos($voice_id, ':') !== false ? substr($voice_id, strpos($voice_id, ':')+1) : 'alloy';
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
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],
			CURLOPT_TIMEOUT        => 60,
		]);
		$_t = microtime(true);
		$response = curl_exec($ch);
		$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_err = curl_error($ch);
		$tts_ms   = wiz_sec($_t);
		curl_close($ch);
		wiz_log("⏱ OpenAI TTS API: {$tts_ms} | voice=$voice | chars=" . strlen($text) . " | http=$status");
		if ($curl_err) return ['success'=>false,'error'=>'cURL: '.$curl_err];
		if ($status !== 200) {
			$d = json_decode($response, true);
			return ['success'=>false,'error'=>'OpenAI TTS HTTP '.$status.': '.($d['error']['message']??$response)];
		}
		file_put_contents($filepath, $response);
		return ['success'=>true];
	}

	// ── MP3 duration reader (pure PHP, no dependencies) ──────────────────────────
	// Walks MPEG frame headers to sum actual playback duration in seconds.
	// Falls back to file-size estimate if no valid frame is found within 64 KB.
	function getMp3DurationSeconds($filepath) {
		$fh = @fopen($filepath, 'rb');
		if (!$fh) return null;

		$bitrate_table = [
			// MPEG1, Layer3
			'1_3' => [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0],
		];
		$samplerate_table = [
			'1' => [44100, 48000, 32000, 0],
		];

		$total_duration = 0.0;
		$frames_found   = 0;
		$scan_limit     = 65536; // scan first 64 KB for first frame, then trust CBR
		$scanned        = 0;
		$filesize        = filesize($filepath);

		// Skip ID3v2 tag if present
		$header = fread($fh, 10);
		if (substr($header, 0, 3) === 'ID3') {
			$sz = ((ord($header[6]) & 0x7f) << 21)
				| ((ord($header[7]) & 0x7f) << 14)
				| ((ord($header[8]) & 0x7f) << 7)
				|  (ord($header[9]) & 0x7f);
			fseek($fh, 10 + $sz);
		} else {
			fseek($fh, 0);
		}

		$tag_offset = ftell($fh);

		while (!feof($fh) && $scanned < $scan_limit) {
			$byte = fread($fh, 1);
			if ($byte === false || $byte === '') break;
			$scanned++;

			if (ord($byte) !== 0xFF) continue;

			$next = fread($fh, 1);
			if ($next === false) break;
			$scanned++;

			$b2 = ord($next);
			// Check sync: 0xFB = MPEG1 Layer3 no CRC, 0xFA = with CRC, 0xF3/0xF2 = MPEG2
			if (($b2 & 0xE0) !== 0xE0) continue;

			$mpeg_ver   = ($b2 >> 3) & 0x03; // 3=MPEG1, 2=MPEG2, 0=MPEG2.5
			$layer      = ($b2 >> 1) & 0x03; // 3=L1, 2=L2, 1=L3

			$header4 = fread($fh, 2);
			if (strlen($header4) < 2) break;
			$scanned += 2;

			$b3 = ord($header4[0]);
			$b4 = ord($header4[1]);

			$bitrate_idx  = ($b3 >> 4) & 0x0F;
			$samplerate_idx = ($b3 >> 2) & 0x03;
			$padding      = ($b3 >> 1) & 0x01;

			// Only handle MPEG1 Layer3 (most common TTS output)
			if ($mpeg_ver !== 3 || $layer !== 1) {
				fseek($fh, -2, SEEK_CUR);
				$scanned -= 2;
				continue;
			}

			$bitrates    = $bitrate_table['1_3'];
			$samplerates = $samplerate_table['1'];
			$bitrate     = $bitrates[$bitrate_idx]    ?? 0;
			$samplerate  = $samplerates[$samplerate_idx] ?? 0;

			if ($bitrate === 0 || $samplerate === 0) continue;

			$frame_size = (int)(144 * $bitrate * 1000 / $samplerate) + $padding;
			$frame_dur  = 1152 / $samplerate; // seconds per MPEG1-L3 frame

			// CBR shortcut: estimate total frames from file size after first valid frame
			$frames_found++;
			if ($frames_found === 1) {
				$data_bytes = $filesize - ftell($fh) + 4;
				$total_duration = ($data_bytes / $frame_size) * $frame_dur;
				break;
			}
		}

		fclose($fh);

		if ($total_duration > 0) return (int)ceil($total_duration);

		// Last-resort: assume 128 kbps CBR
		if ($filesize > 0) return (int)ceil(($filesize * 8) / (128 * 1000));

		return null;
	}

	// ── getUserSettingsWiz ────────────────────────────────────────────────────────


	function getUserSettingsWiz($conn, $admin_id, $company_id = 0) {
		// Prefer company-scoped row; fall back to any row for this admin
		if ($company_id > 0) {
			$q = mysqli_query($conn,
				"SELECT * FROM hdb_user_settings
				 WHERE admin_id='$admin_id' AND company_id='$company_id'
				 ORDER BY FIELD(text_type,'caption','header','footer','logo')
				 LIMIT 10");
			// If no company-specific rows, fall back to admin-level rows
			if (!$q || mysqli_num_rows($q) === 0) {
				$q = mysqli_query($conn,
					"SELECT * FROM hdb_user_settings
					 WHERE admin_id='$admin_id'
					 ORDER BY FIELD(text_type,'caption','header','footer','logo')
					 LIMIT 10");
			}
		} else {
			$q = mysqli_query($conn,
				"SELECT * FROM hdb_user_settings
				 WHERE admin_id='$admin_id'
				 ORDER BY FIELD(text_type,'caption','header','footer','logo')
				 LIMIT 10");
		}

		$settings = ['caption' => null, 'header' => null, 'footer' => null, 'logo' => null];
		if ($q) {
			while ($r = mysqli_fetch_assoc($q)) {
				$t = $r['text_type'] ?? 'caption';
				wiz_log("getUserSettingsWiz RAW DB ROW text_type=$t | text_animation=" . var_export($r['text_animation'] ?? 'KEY_MISSING', true) . " | animation_style=" . var_export($r['animation_style'] ?? 'KEY_MISSING', true) . " | animation_speed=" . var_export($r['animation_speed'] ?? 'KEY_MISSING', true) . " | caption_speed=" . var_export($r['caption_speed'] ?? 'KEY_MISSING', true));
				wiz_log("getUserSettingsWiz FULL ROW: " . json_encode($r));
				if (array_key_exists($t, $settings)) $settings[$t] = $r;
			}
		}
		if (!$settings['caption']) {
			wiz_log("getUserSettingsWiz: NO caption row found for admin_id=$admin_id company_id=$company_id — using defaults");
		}

		// ── Direct column check query ─────────────────────────────────────────────
		// Log the exact column values we care about directly from DB, bypassing PHP
		$chk = mysqli_query($conn,
			"SELECT id, text_type, text_animation, animation_style, animation_speed, caption_speed
			 FROM hdb_user_settings WHERE admin_id='$admin_id' LIMIT 5");
		if ($chk) {
			while ($cr = mysqli_fetch_assoc($chk)) {
				wiz_log("DIRECT_CHECK row id=" . $cr['id'] . " text_type=" . $cr['text_type'] . " | text_animation=" . var_export($cr['text_animation'], true) . " | animation_style=" . var_export($cr['animation_style'], true) . " | animation_speed=" . var_export($cr['animation_speed'], true) . " | caption_speed=" . var_export($cr['caption_speed'], true));
			}
		} else {
			wiz_log("DIRECT_CHECK query failed: " . mysqli_error($conn));
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
			'logo_name'=>'','logo_size'=>'60',
			'logo_position'=>'top-right','logo_pos_h'=>'right','logo_pos_v'=>'top',
			'logo_size_pct'=>15,'logo_enabled'=>0,
			'caption_text'=>'',
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

		// Safe merge: DB row wins for every key. Falls back to default only when DB value is null.
		$safe_merge = function(array $defaults, array $db_row): array {
			$result = $defaults;
			foreach ($db_row as $key => $value) {
				if ($value !== null) $result[$key] = $value;
			}
			// Explicit mappings — user_settings column → canonical key used by $doInsert
			// user_settings.text_animation  → captions.animation_style
			// user_settings.animation_speed → captions.animation_speed  (slow/normal/fast/very fast)
			// user_settings.text_effect     → captions.text_effects
			// user_settings.text_effect_color → captions.text_effect_colors
			// Use isset+strlen check — !empty() fails on '0' and non-null empty strings from DB
			$result['_anim_style']  = (isset($db_row['text_animation'])    && strlen($db_row['text_animation'])    > 0)
									? $db_row['text_animation']    : ($defaults['text_animation']    ?? 'none');
			$_raw_spd = (isset($db_row['animation_speed']) && strlen($db_row['animation_speed']) > 0)
									? $db_row['animation_speed'] : ($defaults['animation_speed'] ?? 'normal');
			// Sanitize: only accept known speed values — bad DB data (e.g. 'bottom') gets reset to 'normal'
			// animation_speed is now numeric (e.g. 0.5=slow, 1.0=normal, 2.0=fast)
			// Accept numeric values in range 0.1–10; reject text garbage like 'bottom'
			$result['_anim_speed'] = is_numeric($_raw_spd) ? (float)$_raw_spd : 1.0;
			$result['_text_fx']     = (isset($db_row['text_effect'])       && strlen($db_row['text_effect'])       > 0)
									? $db_row['text_effect']       : ($defaults['text_effect']       ?? 'none');
			$result['_text_fx_col'] = (isset($db_row['text_effect_color']) && strlen($db_row['text_effect_color']) > 0)
									? $db_row['text_effect_color'] : ($defaults['text_effect_color'] ?? '#ffffff');
			// Logo name
			$result['logo_name'] = trim($result['logo_name'] ?? '');
			wiz_log("safe_merge: db[text_animation]=" . var_export($db_row['text_animation'] ?? 'KEY_MISSING', true) . " db[animation_speed]=" . var_export($db_row['animation_speed'] ?? 'KEY_MISSING', true) . " => _anim_style=" . $result['_anim_style'] . " _anim_speed=" . $result['_anim_speed']);
			return $result;
		};

		$settings['caption'] = $settings['caption']
			? $safe_merge($def_caption, $settings['caption'])
			: $safe_merge($def_caption, []);

		$settings['header'] = $settings['header']
			? $safe_merge($def_header, $settings['header'])
			: $safe_merge($def_header, []);

		$settings['footer'] = $settings['footer']
			? $safe_merge($def_footer, $settings['footer'])
			: $safe_merge($def_footer, []);

		$def_logo = [
			'text_type'    => 'logo',
			'position_x'   => 960,
			'position_y'   => 20,
			'width'        => 120,
			'logo_name'    => '',
			'logo_size_pct'=> 15,
			'logo_pos_h'   => 'right',
			'logo_pos_v'   => 'top',
			'logo_enabled' => 0,
		];
		$settings['logo'] = $settings['logo']
			? $safe_merge($def_logo, $settings['logo'])
			: $safe_merge($def_logo, []);

		return $settings;
	}


	function buildCaptionRows($conn, $podcast_id, $story_id, $scene_text, $user_settings_all, $is_broll = false) 
	{
		wiz_log("buildCaptionRows VERSION=2025-04-22-DOINSERT podcast=$podcast_id story=$story_id");
		$cap  = $user_settings_all['caption'];
		$hdr  = $user_settings_all['header'];
		$ftr  = $user_settings_all['footer'];
		$rows = 0;

		// ── Helper: build a full INSERT from a settings row ───────────────────────
		// Maps every user_settings column → the corresponding hdb_captions column.
		$doInsert = function(
			string $cap_type,
			string $cap_name,
			string $text,
			array  $s,          // settings row (already safe_merged)
			int    $z,          // z_index
			int    $px_override = -1,
			int    $py_override = -1,
			int    $fs_override = -1,
			int    $pw_override = -1
		) use ($conn, $podcast_id, $story_id): bool {

			// ── Font ──────────────────────────────────────────────────────────────
			$ff  = mysqli_real_escape_string($conn, $s['fontfamily']    ?? 'Arial');
			$fs  = $fs_override >= 0 ? $fs_override : (int)($s['fontsize']  ?? 28);
			$fc  = mysqli_real_escape_string($conn, $s['fontcolor']     ?? '#ffffff');
			$fw  = mysqli_real_escape_string($conn, $s['fontweight']    ?? 'normal');
			// font_italic → fontstyle
			$fst = ((int)($s['font_italic'] ?? 0)) ? 'italic' : 'normal';
			// font_underline → underline column
			$uline = (int)($s['font_underline'] ?? 0);

			// ── Alignment ─────────────────────────────────────────────────────────
			$talign  = mysqli_real_escape_string($conn, $s['caption_alignment'] ?? $s['text_align'] ?? 'center');
			$talign_v = mysqli_real_escape_string($conn, $s['text_align_v']      ?? 'bottom');

			// ── Background ───────────────────────────────────────────────────────
			$bgc = mysqli_real_escape_string($conn, $s['fontcolor_bg'] ?? '#000000');
			$bge = (int)($s['fontbg_enable'] ?? 0);

			// ── Stroke ───────────────────────────────────────────────────────────
			$stroke_c = mysqli_real_escape_string($conn, $s['stroke_color'] ?? '#000000');
			$stroke_w = (int)($s['stroke_width'] ?? 0);
			$stroke_e = $stroke_w > 0 ? 1 : 0;

			// ── Shadow ───────────────────────────────────────────────────────────
			$shadow_c = mysqli_real_escape_string($conn, $s['shadow_color'] ?? '#000000');

			// ── Gradient ─────────────────────────────────────────────────────────
			$grad_c = mysqli_real_escape_string($conn, $s['gradient_color'] ?? '#ff6600');

			// ── Text effects & Animation ─────────────────────────────────────────
			// All four fields read from canonical keys set by safe_merge:
			// user_settings.text_animation   → _anim_style  → captions.animation_style
			// user_settings.animation_speed  → _anim_speed  → captions.animation_speed
			// user_settings.text_effect      → _text_fx     → captions.text_effects
			// user_settings.text_effect_color→ _text_fx_col → captions.text_effect_colors
			$anim_style = mysqli_real_escape_string($conn, $s['_anim_style']  ?? 'none');
			$anim_spd   = is_numeric($s['_anim_speed'] ?? null) ? (float)$s['_anim_speed'] : 1.0;
			$te_fx      = mysqli_real_escape_string($conn, $s['_text_fx']     ?? 'none');
			$te_col     = mysqli_real_escape_string($conn, $s['_text_fx_col'] ?? '#ffffff');

			wiz_log("doInsert $cap_type/$cap_name: anim_style=$anim_style anim_spd=$anim_spd te_fx=$te_fx te_col=$te_col");
			$cap_style = mysqli_real_escape_string($conn, $s['caption_style']    ?? 'none');
			$cap_pos   = mysqli_real_escape_string($conn, $s['caption_position'] ?? 'bottom');
			$disp_mode = mysqli_real_escape_string($conn, $s['display_mode']     ?? 'full');

			// ── Position / size ───────────────────────────────────────────────────
			$px = $px_override >= 0 ? $px_override : (int)($s['position_x'] ?? 50);
			$py = $py_override >= 0 ? $py_override : (int)($s['position_y'] ?? 200);
			$pw = $pw_override >= 0 ? $pw_override : min((int)($s['width']   ?? 500), 350);

			$te_safe = mysqli_real_escape_string($conn, $text);

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
				 text_decoration,
				 is_visible, z_index)
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
				 'none',
				 1, $z)";

			wiz_log("doInsert PRE-QUERY ($cap_type/$cap_name): anim_style_var=$anim_style | anim_spd_var=$anim_spd | cap_style=$cap_style | cap_pos=$cap_pos | py=$py");
			wiz_log("doInsert FULL SQL: $sql");
			$ok = mysqli_query($conn, $sql);
			if (!$ok) {
				wiz_log("doInsert INSERT FAILED ($cap_type/$cap_name): " . mysqli_error($conn));
			} else {
				wiz_log("doInsert INSERT OK ($cap_type/$cap_name): id=" . mysqli_insert_id($conn));
			}
			return (bool)$ok;
		};

		// ── 1. MAIN CAPTION — text is the scene text ──────────────────────────────
		if ((int)($cap['is_enabled'] ?? 1)) {
			// B-Roll override: caption near bottom-center, smaller font + line-by-line animation
			$py_cap = $is_broll ? 400 : -1;
			$fs_cap = $is_broll ? 20  : -1;
			if ($is_broll) {
				$cap['_anim_style'] = 'line-by-line';
				$cap['_anim_speed'] = 0.5;
				wiz_log("buildCaptionRows B-ROLL OVERRIDE APPLIED: _anim_style=line-by-line _anim_speed=0.5 position_y=$py_cap");
			}

			// Caption text — strip <break> tags, show clean spoken text only
			$clean_for_cap = trim(preg_replace('/<break[^>]*>/i', '', $scene_text));
			$caption_text  = $clean_for_cap;
			if (preg_match('/^(.+?[.!?])\s+\S/u', $clean_for_cap, $m)) {
				// Take first sentence only
				$first_sentence = trim($m[1]);
				$words_arr = preg_split('/\s+/', $first_sentence, -1, PREG_SPLIT_NO_EMPTY);
				if (count($words_arr) > 12) {
					$first_sentence = implode(' ', array_slice($words_arr, 0, 10)) . '…';
				}
				$caption_text = $first_sentence;
			} else {
				// Single sentence — trim to 12 words if needed
				$words_arr = preg_split('/\s+/', $clean_for_cap, -1, PREG_SPLIT_NO_EMPTY);
				if (count($words_arr) > 12) {
					$caption_text = implode(' ', array_slice($words_arr, 0, 10)) . '…';
				}
			}

			if ($doInsert('caption', 'main', $caption_text, $cap, 1, -1, $py_cap, $fs_cap)) {
				$rows++;
			}
		}

		// ── 2. HEADER — fixed text from user settings ─────────────────────────────
		if ((int)($hdr['is_enabled'] ?? 0)) {
			$ht = trim($hdr['caption_text'] ?? '');
			// position_y=16 is always forced — never pulled from user_settings (which may have stale/wrong values)
			if ($doInsert('header', 'header', $ht, $hdr, 2, -1, 16, -1, min((int)($hdr['width'] ?? 1080), 350))) {
				$rows++;
			}
		}

		// ── 3. FOOTER — fixed text from user settings ─────────────────────────────
		if ((int)($ftr['is_enabled'] ?? 0)) {
			$ft = trim($ftr['caption_text'] ?? '');
			// position_y=555 is always forced — never pulled from user_settings
			if ($doInsert('footer', 'footer', $ft, $ftr, 3, -1, 555, -1, min((int)($ftr['width'] ?? 1080), 350))) {
				$rows++;
			}
		}

		// ── 4. LOGO ───────────────────────────────────────────────────────────────
		// logo_enabled must come from the logo settings row, NOT the caption row.
		$logo_settings = $user_settings_all['logo'] ?? [];
		$logo_enabled  = (int)($logo_settings['logo_enabled'] ?? 0);
		$logo_file     = trim($logo_settings['logo_name'] ?? $logo_settings['logo_file'] ?? $cap['logo_name'] ?? '');

		wiz_log("buildCaptionRows LOGO CHECK: podcast=$podcast_id story=$story_id");
		wiz_log("  logo_settings[logo_enabled]=" . ($logo_settings['logo_enabled'] ?? 'KEY_MISSING'));
		wiz_log("  logo_settings[logo_name]="    . ($logo_settings['logo_name']    ?? 'KEY_MISSING'));
		wiz_log("  resolved logo_enabled=$logo_enabled");
		wiz_log("  resolved logo_file=$logo_file");

		if (!$logo_enabled) {
			wiz_log("  SKIP: logo_enabled is 0 in logo settings — logo OFF");
		} elseif (empty($logo_file)) {
			wiz_log("  SKIP: logo_name is empty");
		} else {
			wiz_log("  PROCEEDING to insert logo caption row");
			$lf    = mysqli_real_escape_string($conn, $logo_file);
			$lsize = (int)($logo_settings['logo_size_pct'] ?? $cap['logo_size_pct'] ?? 15);
			$lpx   = (int)($logo_settings['position_x']   ?? 960);
			$lpy   = (int)($logo_settings['position_y']   ?? 20);
			$lw    = (int)($logo_settings['width']        ?? min((int)(1080 * $lsize / 100), 350));
			wiz_log("  logo pos from user_settings[logo]: lpx=$lpx lpy=$lpy lw=$lw lsize=$lsize");

			$sql = "INSERT INTO hdb_captions
				(podcast_id, story_id, caption_type, caption_name, text_content,
				 fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
				 text_align, text_align_v, bg_color, bg_enabled,
				 stroke_color, stroke_width, stroke_enabled,
				 shadow_color, gradient_color, text_effects, text_effect_colors,
				 panning_zooming_type, panning_zooming_speed,
				 position_x, position_y, width, rotation,
				 animation_style, animation_speed,
				 caption_style, caption_position, display_mode,
				 media_type, image_file,
				 is_visible, z_index)
				VALUES
				($podcast_id, $story_id, 'image', 'logo', '$lf',
				 'Arial', 0, '#ffffff', 'normal', 'normal', 0,
				 'left', 'top', '#000000', 0,
				 '#000000', 0, 0,
				 '#000000', '#ff6600', 'none', '#ffffff',
				 0, 0,
				 $lpx, $lpy, $lw, 0,
				 'none', 1.0,
				 'none', 'top', 'full',
				 'image', '$lf',
				 1, 10)";
			wiz_log("  SQL: $sql");
			$ok = mysqli_query($conn, $sql);
			if ($ok) {
				wiz_log("  SUCCESS: logo caption inserted | caption_id=" . mysqli_insert_id($conn));
				$rows++;
			} else {
				wiz_log("  FAILED: mysqli_error=" . mysqli_error($conn));
			}
		}

		if (function_exists('wiz_log')) wiz_log("buildCaptionRows: podcast=$podcast_id story=$story_id rows=$rows");
		if (function_exists('pod_log')) pod_log("buildCaptionRows: podcast=$podcast_id story=$story_id rows=$rows");

		return $rows;
	}

	// ── Image generation functions (Flux primary, OpenAI fallback) ──────────
	require_once __DIR__ . '/image_generation_functions.php';

	// ══════════════════════════════════════════════════════════════════════════
	// FUNCTION: generateAndSaveImageFlux
	// Primary image provider — Modal-hosted Flux model
	// Returns: ['success', 'message', 'filepath', 'resolution', 'provider']
	// ══════════════════════════════════════════════════════════════════════════
	function generateAndSaveImageFlux($prompt, $imageName, $folder = 'podcast_images', $maxRetries = 3) {
		$payload = json_encode([
			'prompt' => $prompt,
			'style'  => 'cinematic',
			'width'  => 768,
			'height' => 1344,
		]);

		$b64 = null;
		for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
			curl_close($ch);

			wiz_log("Modal/Flux attempt=$attempt HTTP=$httpCode curlErr=$curlErrno");

			if ($curlErrno === 0 && $httpCode === 200 && $response) {
				$data = json_decode($response, true);
				if (!empty($data['image'])) { $b64 = $data['image']; break; }
			}
			if ($attempt < $maxRetries) sleep(5);
		}

		if (!$b64) {
			return ['success' => false, 'message' => 'Modal/Flux failed after ' . $maxRetries . ' attempts', 'filepath' => null, 'resolution' => null, 'provider' => 'flux'];
		}

		$imageData = base64_decode($b64);
		if (!$imageData) {
			return ['success' => false, 'message' => 'Invalid base64 from Modal/Flux', 'filepath' => null, 'resolution' => null, 'provider' => 'flux'];
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

		wiz_log("Modal/Flux saved: $filepath");

		return [
			'success'    => true,
			'message'    => 'Modal/Flux image saved successfully',
			'filepath'   => $filepath,
			'resolution' => '768x1344',
			'provider'   => 'flux',
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FUNCTION: generateAndSaveImage
	// Fallback image provider — OpenAI gpt-image-1
	// Returns: ['success', 'message', 'filepath', 'resolution', 'provider']
	// ══════════════════════════════════════════════════════════════════════════
	function generateAndSaveImage($prompt, $imageName, $resolution = '1024x1536', $folder = 'podcast_images', $apiKey = null) {
		if (empty($apiKey)) {
			return ['success' => false, 'message' => 'OpenAI API key is required', 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
		}

		$validResolutions = ['1024x1536', '1536x1024', '1024x1024', 'auto'];
		if (!in_array($resolution, $validResolutions)) $resolution = '1024x1536';

		if ($resolution !== 'auto') {
			list($targetWidth, $targetHeight) = array_map('intval', explode('x', $resolution));
		} else {
			$targetWidth = 1024; $targetHeight = 1536;
		}

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

		wiz_log("OpenAI fallback HTTP=$httpCode size=$resolution");

		if ($curlErr) {
			return ['success' => false, 'message' => 'OpenAI cURL error: ' . $curlErr, 'filepath' => null, 'resolution' => null, 'provider' => 'openai'];
		}

		$result = json_decode($response, true);
		if ($httpCode !== 200) {
			$msg = $result['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . mb_substr($response, 0, 300));
			wiz_log("OpenAI ERROR: $msg");
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
			$needsRotation = ($targetHeight > $targetWidth && $origWidth > $origHeight)
						  || ($targetWidth > $targetHeight && $origHeight > $origWidth);
			if ($needsRotation) {
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

		wiz_log("OpenAI saved: $filepath");

		return [
			'success'    => true,
			'message'    => 'OpenAI image saved successfully',
			'filepath'   => $filepath,
			'resolution' => $targetWidth . 'x' . $targetHeight,
			'provider'   => 'openai',
		];
	}

	$action = $_POST['action'] ?? $_POST['ajax_action'] ?? '';
	wiz_log("Action: $action | admin=$admin_id | req_start=" . date('H:i:s'));

	// ══════════════════════════════════════════════════════════════════════════
	// HELPER: buildVideoPrompt($niche, $scene_text, $seq_no, $total_scenes)
	//
	// Generates a unique, human-centred, scene-specific 5-second video prompt.
	// Structure: [who] + [action] + [environment/zone] + [lighting] + [camera]
	//
	// Environment is zone-aware: the same niche gets a DIFFERENT background
	// depending on the action in the script — so a cooking video cycles through
	// prep station → stovetop → plating surface rather than the same kitchen
	// for every scene. Same logic applied to fitness, beauty, clinic, education,
	// real estate, and retail.
	// ══════════════════════════════════════════════════════════════════════════
	function buildVideoPrompt($niche, $scene_text, $seq_no, $total_scenes) {

		$nl = strtolower(trim($niche));
		$snippet = strtolower(mb_substr(trim(preg_replace('/<[^>]+>/', '', $scene_text)), 0, 220));

		// ── Niche detection flags ──────────────────────────────────────────────
		$is_food    = (stripos($nl,'restaurant')!==false || stripos($nl,'food')!==false
		             || stripos($nl,'cafe')!==false      || stripos($nl,'chef')!==false
		             || stripos($nl,'catering')!==false  || stripos($nl,'bakery')!==false
		             || stripos($nl,'kitchen')!==false   || stripos($nl,'cooking')!==false);

		$is_fitness = (stripos($nl,'fitness')!==false || stripos($nl,'gym')!==false
		             || stripos($nl,'yoga')!==false    || stripos($nl,'sport')!==false
		             || stripos($nl,'pilates')!==false || stripos($nl,'crossfit')!==false
		             || stripos($nl,'personal trainer')!==false);

		$is_beauty  = (stripos($nl,'salon')!==false  || stripos($nl,'beauty')!==false
		             || stripos($nl,'nail')!==false   || stripos($nl,'hair')!==false
		             || stripos($nl,'spa')!==false    || stripos($nl,'barber')!==false
		             || stripos($nl,'makeup')!==false || stripos($nl,'skincare')!==false);

		$is_clinic  = (stripos($nl,'clinic')!==false  || stripos($nl,'dental')!==false
		             || stripos($nl,'medical')!==false || stripos($nl,'health')!==false
		             || stripos($nl,'pharmacy')!==false|| stripos($nl,'therapy')!==false
		             || stripos($nl,'physio')!==false  || stripos($nl,'hospital')!==false);

		$is_realestate = (stripos($nl,'real estate')!==false || stripos($nl,'property')!==false
		                || stripos($nl,'realtor')!==false     || stripos($nl,'mortgage')!==false);

		$is_retail  = (stripos($nl,'retail')!==false || stripos($nl,'shop')!==false
		             || stripos($nl,'store')!==false  || stripos($nl,'boutique')!==false
		             || stripos($nl,'market')!==false);

		$is_edu     = (stripos($nl,'education')!==false || stripos($nl,'school')!==false
		             || stripos($nl,'tutor')!==false     || stripos($nl,'coach')!==false
		             || stripos($nl,'training')!==false  || stripos($nl,'academy')!==false);

		$is_law     = (stripos($nl,'law')!==false      || stripos($nl,'legal')!==false
		             || stripos($nl,'attorney')!==false || stripos($nl,'solicitor')!==false
		             || stripos($nl,'notary')!==false);

		$is_finance = (stripos($nl,'finance')!==false    || stripos($nl,'accounting')!==false
		             || stripos($nl,'insurance')!==false  || stripos($nl,'investment')!==false
		             || stripos($nl,'mortgage')!==false   || stripos($nl,'tax')!==false);

		$is_tech    = (stripos($nl,'tech')!==false     || stripos($nl,'software')!==false
		             || stripos($nl,'digital')!==false  || stripos($nl,'it service')!==false
		             || stripos($nl,'app')!==false      || stripos($nl,'web')!==false
		             || stripos($nl,'startup')!==false  || stripos($nl,'saas')!==false);

		// ── Determine subject ──────────────────────────────────────────────────
		if ($is_food)       $subject = 'A chef';
		elseif ($is_fitness) $subject = 'A fitness coach';
		elseif ($is_beauty)  $subject = 'A beauty professional';
		elseif ($is_clinic)  $subject = 'A healthcare professional';
		elseif ($is_realestate) $subject = 'A real estate agent';
		elseif ($is_retail)  $subject = 'A shopkeeper';
		elseif ($is_edu)     $subject = 'A teacher';
		elseif ($is_law)     $subject = 'A lawyer';
		elseif ($is_finance) $subject = 'A financial advisor';
		elseif ($is_tech)    $subject = 'A tech professional';
		else                 $subject = 'A professional';

		// ── Action keyword → action phrase ────────────────────────────────────
		$action = 'working confidently';
		$action_map = [
			// cooking-specific
			'chop'    => 'carefully chopping fresh ingredients',
			'cut'     => 'precisely cutting with a sharp knife',
			'slice'   => 'cleanly slicing ingredients on a board',
			'dice'    => 'dicing vegetables with focused precision',
			'mince'   => 'finely mincing herbs with a rocking knife',
			'fry'     => 'frying ingredients in a sizzling hot pan',
			'sauté'   => 'sautéing vegetables over a high flame',
			'saute'   => 'sautéing vegetables over a high flame',
			'stir'    => 'stirring a rich sauce over gentle heat',
			'toss'    => 'tossing fresh ingredients in a wide pan',
			'sizzle'  => 'placing protein into a sizzling hot skillet',
			'bake'    => 'sliding a tray into a preheated oven',
			'roast'   => 'arranging ingredients on a roasting tray',
			'boil'    => 'stirring a large pot of boiling water',
			'simmer'  => 'ladling simmering broth with steady hands',
			'steam'   => 'lifting a steamer lid to check progress',
			'blanch'  => 'blanching vegetables in rapidly boiling water',
			'plate'   => 'plating a finished dish with artistic care',
			'garnish' => 'adding a precise garnish to a plated dish',
			'drizzle' => 'drizzling sauce over a beautifully plated dish',
			'serve'   => 'serving a finished dish to a waiting guest',
			'mix'     => 'mixing ingredients in a large bowl',
			'whisk'   => 'whisking eggs or batter in a metal bowl',
			'blend'   => 'blending ingredients into a smooth consistency',
			'knead'   => 'kneading dough with strong rhythmic hands',
			'marinate'=> 'coating ingredients in a fresh marinade',
			'season'  => 'seasoning a dish with confident precision',
			'taste'   => 'tasting and adjusting a dish with a spoon',
			// fitness-specific
			'stretch' => 'leading a client through a deep stretch',
			'lift'    => 'coaching a client through a weighted lift',
			'squat'   => 'demonstrating a perfect squat with form cues',
			'run'     => 'pacing on a treadmill with focused intensity',
			'warm'    => 'leading a dynamic warm-up routine',
			'cool'    => 'guiding a calming cool-down stretch sequence',
			'cardio'  => 'motivating a client through a cardio circuit',
			'plank'   => 'holding a strong plank position with focus',
			'push'    => 'demonstrating perfect push-up form',
			'breathe' => 'guiding a client through a breathing exercise',
			'meditat' => 'sitting in focused meditation with calm presence',
			'pose'    => 'holding a yoga pose with graceful balance',
			// beauty-specific
			'consult' => 'consulting with a client about their treatment',
			'apply'   => 'applying treatment product with precise hands',
			'colour'  => 'applying hair colour with a professional brush',
			'color'   => 'applying hair colour with a professional brush',
			'trim'    => 'trimming hair with focused scissor work',
			'style'   => 'styling and finishing a client look',
			'wash'    => 'washing a client\'s hair at a backwash basin',
			'massage' => 'giving a relaxing scalp or face massage',
			'wax'     => 'applying wax with a professional spatula',
			'manicur' => 'painting a client\'s nails with steady precision',
			'nail'    => 'applying nail art with a fine detail brush',
			'facial'  => 'performing a facial treatment with gentle hands',
			// clinic-specific
			'examin'  => 'examining a patient with calm professional attention',
			'diagnos' => 'reviewing a diagnosis with a patient at a desk',
			'prescri' => 'writing a prescription at a clinical desk',
			'treat'   => 'treating a patient with gentle clinical care',
			'inject'  => 'preparing an injection with careful sterile technique',
			'check'   => 'checking a patient\'s vitals with focus',
			'monitor' => 'monitoring patient data on a screen',
			// universal
			'explain' => 'explaining clearly to a client',
			'show'    => 'demonstrating a technique step by step',
			'help'    => 'helping a client with focused attention',
			'build'   => 'building something with careful precision',
			'create'  => 'creating something with skilled hands',
			'design'  => 'designing attentively at a workstation',
			'review'  => 'reviewing documents or results carefully',
			'present' => 'presenting ideas with confidence',
			'train'   => 'training a client through a technique',
			'prepare' => 'preparing materials with careful attention',
			'clean'   => 'cleaning and organising a professional space',
			'write'   => 'writing notes or reports at a desk',
			'sign'    => 'signing important documents at a desk',
			'meet'    => 'meeting with a client in a bright office',
			'analys'  => 'analysing data on a screen intently',
			'inspect' => 'inspecting work with close professional attention',
			'deliver' => 'delivering results to a satisfied client',
			'call'    => 'speaking on a call with a warm confident tone',
			'listen'  => 'listening attentively with empathy',
			'smile'   => 'smiling and welcoming a new client',
			'walk'    => 'walking purposefully through a professional space',
			'greet'   => 'greeting a client warmly at the entrance',
			'open'    => 'opening a consultation session with a client',
			'close'   => 'closing a successful deal with a handshake',
			'ask'     => 'asking a client thoughtful questions',
			'answer'  => 'answering questions with confident expertise',
			'record'  => 'recording data or results on a clipboard',
			'paint'   => 'painting with careful deliberate brushstrokes',
			'repair'  => 'repairing equipment with focused hands',
			'install' => 'installing components with professional precision',
			'measure' => 'measuring carefully with a professional tool',
			'package' => 'packaging a product with neat careful hands',
			'hand'    => 'handing over a finished product to a client',
			'sign'    => 'signing an agreement across a professional desk',
			'welcome' => 'welcoming a new client with a warm handshake',
			'introduc'=> 'introducing a service to an interested client',
		];
		foreach ($action_map as $kw => $phrase) {
			if (strpos($snippet, $kw) !== false) { $action = $phrase; break; }
		}

		// ── Zone-aware environment ────────────────────────────────────────────
		// Each niche has a default environment, but specific action keywords
		// override it with a more contextually accurate location/zone.

		if ($is_food) {
			$env = 'a bright professional kitchen with stainless steel surfaces and cool overhead lighting';
			// Zone overrides — different part of kitchen based on action
			if (strpos($snippet,'chop')!==false || strpos($snippet,'cut')!==false ||
			    strpos($snippet,'slice')!==false || strpos($snippet,'dice')!==false ||
			    strpos($snippet,'mince')!==false || strpos($snippet,'peel')!==false ||
			    strpos($snippet,'marinate')!==false || strpos($snippet,'season')!==false ||
			    strpos($snippet,'mix')!==false || strpos($snippet,'whisk')!==false ||
			    strpos($snippet,'knead')!==false || strpos($snippet,'measure')!==false) {
				$env = 'a bright kitchen prep station with a worn wooden chopping board, fresh vegetables and herbs scattered nearby, cool natural light from a side window';
			} elseif (strpos($snippet,'fry')!==false || strpos($snippet,'saute')!==false ||
			          strpos($snippet,'sauté')!==false || strpos($snippet,'stir')!==false ||
			          strpos($snippet,'toss')!==false  || strpos($snippet,'sizzle')!==false ||
			          strpos($snippet,'simmer')!==false|| strpos($snippet,'boil')!==false ||
			          strpos($snippet,'steam')!==false || strpos($snippet,'blanch')!==false) {
				$env = 'a professional stovetop with a heavy cast-iron pan, gentle steam rising, warm overhead task light with cool ambient fill from behind';
			} elseif (strpos($snippet,'bake')!==false || strpos($snippet,'roast')!==false ||
			          strpos($snippet,'oven')!==false  || strpos($snippet,'preheat')!==false) {
				$env = 'a clean oven station with baking trays and parchment paper, a warm amber glow from the open oven door balanced by cool daylight overhead';
			} elseif (strpos($snippet,'plate')!==false || strpos($snippet,'garnish')!==false ||
			          strpos($snippet,'drizzle')!==false|| strpos($snippet,'finish')!==false ||
			          strpos($snippet,'present')!==false) {
				$env = 'a clean white marble plating surface under bright soft directional lighting, a finished dish in the foreground with space for garnish';
			} elseif (strpos($snippet,'serve')!==false || strpos($snippet,'bring')!==false ||
			          strpos($snippet,'deliver')!==false|| strpos($snippet,'table')!==false) {
				$env = 'an elegant restaurant dining area, warm ambient lighting, tables set with white linen, a sense of refined service';
			} elseif (strpos($snippet,'taste')!==false || strpos($snippet,'flavour')!==false ||
			          strpos($snippet,'flavor')!==false || strpos($snippet,'sample')!==false) {
				$env = 'a tasting station with small sample dishes arranged neatly, clean neutral background, cool daylight';
			}

		} elseif ($is_fitness) {
			$env = 'a modern gym with natural light flooding through floor-to-ceiling windows';
			if (strpos($snippet,'stretch')!==false || strpos($snippet,'warm')!==false ||
			    strpos($snippet,'breathe')!==false || strpos($snippet,'cool')!==false ||
			    strpos($snippet,'meditat')!==false || strpos($snippet,'relax')!==false) {
				$env = 'a calm open yoga studio with wooden floors, large windows, soft natural daylight and minimal equipment';
			} elseif (strpos($snippet,'lift')!==false || strpos($snippet,'squat')!==false ||
			          strpos($snippet,'deadlift')!==false || strpos($snippet,'barbell')!==false ||
			          strpos($snippet,'dumbbell')!==false || strpos($snippet,'weight')!==false ||
			          strpos($snippet,'push')!==false     || strpos($snippet,'bench')!==false) {
				$env = 'a well-equipped weights area with racks of dumbbells, rubber flooring, industrial ceiling with cool-toned LED lighting';
			} elseif (strpos($snippet,'run')!==false || strpos($snippet,'cardio')!==false ||
			          strpos($snippet,'treadmill')!==false || strpos($snippet,'sprint')!==false ||
			          strpos($snippet,'jump')!==false      || strpos($snippet,'cycle')!==false) {
				$env = 'a cardio zone with treadmills and bikes, large mirror walls, bright overhead lighting and a high-energy atmosphere';
			} elseif (strpos($snippet,'pose')!==false || strpos($snippet,'yoga')!==false ||
			          strpos($snippet,'balance')!==false  || strpos($snippet,'mat')!==false) {
				$env = 'a serene yoga studio with bamboo floors, soft diffused natural light, and plants along the window ledge';
			}

		} elseif ($is_beauty) {
			$env = 'a bright modern beauty salon with white surfaces and soft overhead lighting';
			if (strpos($snippet,'consult')!==false || strpos($snippet,'discuss')!==false ||
			    strpos($snippet,'ask')!==false     || strpos($snippet,'welcome')!==false ||
			    strpos($snippet,'greet')!==false) {
				$env = 'a welcoming beauty salon reception area with a marble desk, fresh flowers, and warm-cool balanced lighting';
			} elseif (strpos($snippet,'wash')!==false || strpos($snippet,'shampoo')!==false ||
			          strpos($snippet,'rinse')!==false || strpos($snippet,'backwash')!==false) {
				$env = 'a salon backwash area with sleek basins, white towels, and soft overhead lighting reflected in glossy surfaces';
			} elseif (strpos($snippet,'colour')!==false || strpos($snippet,'color')!==false ||
			          strpos($snippet,'dye')!==false    || strpos($snippet,'highlight')!==false ||
			          strpos($snippet,'trim')!==false   || strpos($snippet,'cut')!==false ||
			          strpos($snippet,'blow')!==false   || strpos($snippet,'style')!==false) {
				$env = 'a styling station with large mirror framed by round bulb lights, a clean white countertop with professional tools arranged neatly';
			} elseif (strpos($snippet,'nail')!==false   || strpos($snippet,'manicur')!==false ||
			          strpos($snippet,'pedicur')!==false || strpos($snippet,'polish')!==false) {
				$env = 'a nail station with a clean white table, gel lamps, and an array of coloured polish bottles under bright cool lighting';
			} elseif (strpos($snippet,'facial')!==false || strpos($snippet,'mask')!==false ||
			          strpos($snippet,'skin')!==false   || strpos($snippet,'massage')!==false ||
			          strpos($snippet,'wax')!==false    || strpos($snippet,'thread')!==false) {
				$env = 'a serene treatment room with a padded table, dim warm lighting, white linens, and shelves of skincare products';
			} elseif (strpos($snippet,'finish')!==false || strpos($snippet,'reveal')!==false ||
			          strpos($snippet,'result')!==false || strpos($snippet,'mirror')!==false) {
				$env = 'a styling station mirror reveal moment, client smiling at their reflection, bright salon lighting, clean professional space';
			}

		} elseif ($is_clinic) {
			$env = 'a clean modern clinic with crisp white walls and cool overhead lighting';
			if (strpos($snippet,'consult')!==false || strpos($snippet,'discuss')!==false ||
			    strpos($snippet,'explain')!==false || strpos($snippet,'diagnos')!==false ||
			    strpos($snippet,'prescri')!==false || strpos($snippet,'record')!==false) {
				$env = 'a private consultation room with a clean desk, medical certificates on the wall, and calm cool-toned overhead lighting';
			} elseif (strpos($snippet,'examin')!==false || strpos($snippet,'check')!==false ||
			          strpos($snippet,'assess')!==false  || strpos($snippet,'vital')!==false ||
			          strpos($snippet,'test')!==false    || strpos($snippet,'scan')!==false) {
				$env = 'a clinical examination room with an adjustable table, medical equipment on the walls, bright cool clinical lighting';
			} elseif (strpos($snippet,'treat')!==false || strpos($snippet,'inject')!==false ||
			          strpos($snippet,'procedure')!==false|| strpos($snippet,'operat')!==false ||
			          strpos($snippet,'therap')!==false) {
				$env = 'a treatment bay with sterile surfaces, medical trays arranged neatly, bright procedure lighting with clean white walls';
			} elseif (strpos($snippet,'recov')!==false || strpos($snippet,'rest')!==false ||
			          strpos($snippet,'discharge')!==false|| strpos($snippet,'follow')!==false) {
				$env = 'a calm recovery area with comfortable seating, natural light from a window, warm yet clinical atmosphere';
			}

		} elseif ($is_realestate) {
			$env = 'a spacious well-lit modern property interior';
			if (strpos($snippet,'exterior')!==false || strpos($snippet,'outside')!==false ||
			    strpos($snippet,'arrive')!==false   || strpos($snippet,'approach')!==false ||
			    strpos($snippet,'curb')!==false     || strpos($snippet,'front')!==false) {
				$env = 'a well-maintained property exterior on a bright sunny day, green lawn, and a welcoming front entrance';
			} elseif (strpos($snippet,'kitchen')!==false || strpos($snippet,'living')!==false ||
			          strpos($snippet,'bedroom')!==false  || strpos($snippet,'bathroom')!==false ||
			          strpos($snippet,'tour')!==false     || strpos($snippet,'walk')!==false ||
			          strpos($snippet,'show')!==false     || strpos($snippet,'room')!==false) {
				$env = 'a bright open-plan living area with large windows, modern finishes, and natural light flooding across hardwood floors';
			} elseif (strpos($snippet,'sign')!==false || strpos($snippet,'offer')!==false ||
			          strpos($snippet,'close')!==false || strpos($snippet,'deal')!==false ||
			          strpos($snippet,'contract')!==false|| strpos($snippet,'paper')!==false) {
				$env = 'a modern real estate office with a large desk, signed documents, and floor-to-ceiling windows overlooking the city';
			} elseif (strpos($snippet,'key')!==false || strpos($snippet,'handover')!==false ||
			          strpos($snippet,'move')!==false || strpos($snippet,'congratulat')!==false) {
				$env = 'a front doorstep of a new home on a bright day, client and agent smiling, keys being handed over';
			}

		} elseif ($is_retail) {
			$env = 'a bright modern retail store with clean displays and warm-cool balanced lighting';
			if (strpos($snippet,'stock')!==false || strpos($snippet,'arrange')!==false ||
			    strpos($snippet,'display')!==false|| strpos($snippet,'shelf')!==false ||
			    strpos($snippet,'organis')!==false|| strpos($snippet,'organiz')!==false) {
				$env = 'a retail stockroom or shop floor with shelves being arranged neatly, clean lighting, product packaging visible';
			} elseif (strpos($snippet,'help')!==false   || strpos($snippet,'assist')!==false ||
			          strpos($snippet,'show')!==false    || strpos($snippet,'recommend')!==false ||
			          strpos($snippet,'customer')!==false|| strpos($snippet,'client')!==false) {
				$env = 'a bright shop floor with a customer browsing, a staff member guiding them through options with a warm smile';
			} elseif (strpos($snippet,'checkout')!==false || strpos($snippet,'pay')!==false ||
			          strpos($snippet,'till')!==false     || strpos($snippet,'receipt')!==false ||
			          strpos($snippet,'purchase')!==false || strpos($snippet,'transaction')!==false) {
				$env = 'a clean checkout counter with a card terminal, bright lighting, and a warm interaction between staff and customer';
			} elseif (strpos($snippet,'wrap')!==false || strpos($snippet,'package')!==false ||
			          strpos($snippet,'bag')!==false   || strpos($snippet,'gift')!==false) {
				$env = 'a packaging station with tissue paper, branded bags, and neatly wrapped products under bright clean lighting';
			}

		} elseif ($is_edu) {
			$env = 'a bright modern classroom or training space with natural daylight';
			if (strpos($snippet,'lecture')!==false || strpos($snippet,'present')!==false ||
			    strpos($snippet,'teach')!==false   || strpos($snippet,'explain')!==false ||
			    strpos($snippet,'board')!==false   || strpos($snippet,'slide')!==false) {
				$env = 'a modern classroom with a whiteboard or large screen behind, students visible in soft focus, bright overhead lighting';
			} elseif (strpos($snippet,'one-on-one')!==false || strpos($snippet,'tutor')!==false ||
			          strpos($snippet,'individual')!==false  || strpos($snippet,'student')!==false ||
			          strpos($snippet,'mentor')!==false      || strpos($snippet,'coach')!==false) {
				$env = 'a quiet one-on-one tutoring space with a shared desk, open notebooks, and soft natural light from a window';
			} elseif (strpos($snippet,'group')!==false || strpos($snippet,'workshop')!==false ||
			          strpos($snippet,'team')!==false  || strpos($snippet,'collaborat')!==false ||
			          strpos($snippet,'discuss')!==false) {
				$env = 'an open workshop space with round tables, students collaborating, large windows and energetic natural light';
			} elseif (strpos($snippet,'review')!==false || strpos($snippet,'grade')!==false ||
			          strpos($snippet,'mark')!==false   || strpos($snippet,'assess')!==false ||
			          strpos($snippet,'feedback')!==false) {
				$env = 'a teacher\'s desk with papers and a laptop, a calm quiet classroom in the background, cool soft lighting';
			}

		} elseif ($is_law) {
			$env = 'a professional law office with bookshelves and natural daylight';
			if (strpos($snippet,'consult')!==false || strpos($snippet,'meet')!==false ||
			    strpos($snippet,'advise')!==false  || strpos($snippet,'discuss')!==false ||
			    strpos($snippet,'explain')!==false) {
				$env = 'a private law consultation room with a polished wooden desk, leather chairs, and bookshelves in the background';
			} elseif (strpos($snippet,'sign')!==false || strpos($snippet,'document')!==false ||
			          strpos($snippet,'contract')!==false|| strpos($snippet,'review')!==false ||
			          strpos($snippet,'draft')!==false   || strpos($snippet,'write')!==false) {
				$env = 'a law office desk covered in legal documents, a pen being handed across the table, cool daylight from a tall window';
			} elseif (strpos($snippet,'court')!==false || strpos($snippet,'hearing')!==false ||
			          strpos($snippet,'trial')!==false  || strpos($snippet,'judge')!==false ||
			          strpos($snippet,'argue')!==false) {
				$env = 'a formal courtroom with dark wood panelling, overhead lighting, a sense of gravitas and professionalism';
			}

		} elseif ($is_finance) {
			$env = 'a modern glass-walled office with city views and cool-toned lighting';
			if (strpos($snippet,'analys')!==false || strpos($snippet,'data')!==false ||
			    strpos($snippet,'chart')!==false  || strpos($snippet,'report')!==false ||
			    strpos($snippet,'screen')!==false || strpos($snippet,'number')!==false) {
				$env = 'a financial analyst\'s desk with multiple monitors showing charts and data, cool blue-toned office lighting';
			} elseif (strpos($snippet,'consult')!==false || strpos($snippet,'client')!==false ||
			          strpos($snippet,'advise')!==false  || strpos($snippet,'meet')!==false ||
			          strpos($snippet,'plan')!==false) {
				$env = 'a professional meeting room with a glass table, financial documents spread out, bright overhead lighting';
			} elseif (strpos($snippet,'sign')!==false || strpos($snippet,'contract')!==false ||
			          strpos($snippet,'agree')!==false  || strpos($snippet,'close')!==false ||
			          strpos($snippet,'deal')!==false) {
				$env = 'a clean executive desk with a signed agreement, a handshake across the table, soft cool light from tall windows';
			}

		} elseif ($is_tech) {
			$env = 'a sleek modern office with multiple screens and cool blue-toned lighting';
			if (strpos($snippet,'code')!==false   || strpos($snippet,'program')!==false ||
			    strpos($snippet,'develop')!==false || strpos($snippet,'build')!==false ||
			    strpos($snippet,'debug')!==false   || strpos($snippet,'deploy')!==false) {
				$env = 'a developer\'s workstation with two monitors showing code, a mechanical keyboard, cool ambient lighting in a dark modern office';
			} elseif (strpos($snippet,'meet')!==false   || strpos($snippet,'call')!==false ||
			          strpos($snippet,'present')!==false || strpos($snippet,'demo')!==false ||
			          strpos($snippet,'pitch')!==false) {
				$env = 'a bright modern conference room with a large screen displaying a product demo, team members in soft focus behind';
			} elseif (strpos($snippet,'design')!==false || strpos($snippet,'ui')!==false ||
			          strpos($snippet,'ux')!==false     || strpos($snippet,'prototype')!==false ||
			          strpos($snippet,'sketch')!==false || strpos($snippet,'wireframe')!==false) {
				$env = 'a design studio desk with an open laptop, tablet and stylus, mood boards pinned on the wall, soft cool daylight';
			}

		} else {
			$env = 'a clean modern professional space with large windows and natural cool daylight';
		}

		// ── Assemble final prompt ─────────────────────────────────────────────
		// [who] + [action] + [environment/zone] + [lighting] + [camera feel]
		$prompt = "{$subject} {$action} in {$env}, "
			. "soft cool-toned natural daylight with a subtle bluish atmosphere, no yellow cast, "
			. "shallow depth of field, handheld cinematic feel, 4K quality, "
			. "bright airy scene with natural shadows — scene {$seq_no} of {$total_scenes}.";

		return ['prompt' => $prompt, 'subject' => $subject, 'action' => $action, 'env' => $env];
	}



	// ── search_images_batch ───────────────────────────────────────────────────
	// ── search_images_batch ───────────────────────────────────────────────────
	// ── search_images_batch ───────────────────────────────────────────────────
	if ($action === 'init_media_session') {
		// Reset DB-based shared exclusion list for this podcast build
		$pid = (int)($_POST['podcast_id'] ?? 0);
		if ($pid) mysqli_query($conn, "DELETE FROM hdb_build_used_files WHERE podcast_id=$pid");
		echo json_encode(['success' => true]); exit;
	}
	if ($action === 'search_images_batch') {
		ini_set('memory_limit', '512M');
		while (ob_get_level()) ob_end_clean();
		header('Content-Type: application/json');

		$_t_batch     = microtime(true);
		wiz_log("=== SEARCH_IMAGES_BATCH START ===");

		$scenes_raw    = $_POST['scenes']      ?? '[]';
		$podcast_id    = (int)($_POST['podcast_id']    ?? 0);
		$slots_needed  = max(1, min(10, (int)($_POST['slots'] ?? 5)));
		$scenes_input  = json_decode($scenes_raw, true);
		$admin_id_bat  = (int)($_POST['admin_id']      ?? $admin_id ?? 0);
		$team_lead_bat = (int)($_POST['team_lead_id']  ?? 0);

		// media_type_filter: stock_videos→video, stock_images→image, ai_images→generate, else '' (both)
		$raw_media         = trim($_POST['media_type'] ?? '');
		$media_type_filter = '';
		$is_ai_images      = false;
		if (strpos($raw_media, 'video') !== false)                                       $media_type_filter = 'video';
		elseif (strpos($raw_media, 'ai_image') !== false || strpos($raw_media, 'ai image') !== false) { $media_type_filter = 'image'; $is_ai_images = true; }
		elseif (strpos($raw_media, 'image') !== false)                                   $media_type_filter = 'image';

		wiz_log("podcast=$podcast_id slots=$slots_needed scenes=" . count($scenes_input) . " media=$raw_media filter=$media_type_filter ai_images=" . ($is_ai_images ? 'YES' : 'NO') . " admin=$admin_id_bat");

		// ── AI image generation notice ────────────────────────────────────────────
		if ($is_ai_images) {
			$scene_count = count($scenes_input);
			wiz_log("╔══════════════════════════════════════════════════════════════╗");
			wiz_log("║  AI IMAGE GENERATION REQUESTED                               ║");
			wiz_log("║  Scenes: $scene_count | Estimated: " . ($scene_count * 30) . "–" . ($scene_count * 60) . "s total");
			wiz_log("║  Provider: Flux (Modal) PRIMARY — OpenAI fallback if needed  ║");
			wiz_log("║  Each image takes 30–60 seconds — please be patient          ║");
			wiz_log("╚══════════════════════════════════════════════════════════════╝");
		}

		if (!is_array($scenes_input) || empty($scenes_input)) {
			wiz_log("No scenes input, returning empty");
			echo json_encode([]); exit;
		}

		// ── Deduplication: collect filenames used in last 10 completed videos ────
		// Prevents the same asset appearing across multiple consecutive videos.
		// We query hdb_podcast_stories directly so deleted videos are naturally excluded.
		$recently_used = [];
		$scope_id = ($team_lead_bat > 0) ? $team_lead_bat : $admin_id_bat;
		if ($scope_id > 0) {
			$recent_res = mysqli_query($conn,
				"SELECT id FROM hdb_podcasts
				 WHERE (admin_id = $scope_id OR team_lead_id = $scope_id)
				   AND id != $podcast_id
				   AND video_status NOT IN ('ARCHIVED')
				   AND (archived_flag IS NULL OR archived_flag = 0)
				 ORDER BY id DESC LIMIT 10");
			$recent_ids = [];
			if ($recent_res) {
				while ($r = mysqli_fetch_assoc($recent_res)) $recent_ids[] = (int)$r['id'];
			}
			if (!empty($recent_ids)) {
				$id_csv = implode(',', $recent_ids);
				$used_res = mysqli_query($conn,
					"SELECT DISTINCT image_file FROM hdb_podcast_stories
					 WHERE podcast_id IN ($id_csv)
					   AND image_file IS NOT NULL AND image_file != ''");
				if ($used_res) {
					while ($r = mysqli_fetch_assoc($used_res)) {
						$recently_used[] = $r['image_file'];
					}
				}
				$recently_used = array_unique($recently_used);
				wiz_log("dedup: " . count($recently_used) . " files used in last " . count($recent_ids) . " videos");
			}
		}

		// Check if hdb_media_match_log table exists (once, outside loop)
		$log_table_exists = false;
		$tc = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_media_match_log'");
		if ($tc && mysqli_num_rows($tc) > 0) $log_table_exists = true;

		$results        = [];
		// DB-based shared exclusion list — reads files already claimed by other parallel scene requests
		$used_filenames = [];
		if ($podcast_id > 0) {
			// Auto-create table if missing
			mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_build_used_files (
				podcast_id INT NOT NULL,
				filename VARCHAR(500) NOT NULL,
				PRIMARY KEY (podcast_id, filename)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			$uf_res = mysqli_query($conn, "SELECT filename FROM hdb_build_used_files WHERE podcast_id=$podcast_id");
			if ($uf_res) while ($uf_row = mysqli_fetch_assoc($uf_res)) $used_filenames[] = $uf_row['filename'];
		}

		foreach ($scenes_input as $idx => $scene) {
			$_t_scene      = microtime(true);
			$query         = trim($scene['query']    ?? '');
			$scene_id      = (int)($scene['scene_id'] ?? 0);
			$scene_nl_tags = trim($scene['nl_tags']  ?? '');

			if (empty($query)) {
				$results[] = ['scene_idx' => $idx, 'found' => []];
				continue;
			}

			// Use nl_tags as search query if available — it's richer than plain text
			$search_query = !empty($scene_nl_tags) ? $scene_nl_tags : $query;

			// Fetch more candidates than needed so dedup doesn't leave slots empty
			$_t_search = microtime(true);
			$matches = searchAssets($conn, $search_query, $apiKey, $podcast_id, $media_type_filter, false, 0, $slots_needed * 6, 0.25);
			wiz_log("⏱ scene $idx search: " . wiz_ms($_t_search) . " → " . count($matches) . " raw matches | media=$media_type_filter");

			// ── Video preference: if any of top 5 results is a video, promote it ─
			// Move first video in top-5 to position 0 so it fills slot 1
			if ($media_type_filter !== 'image') {
				$top5 = array_slice($matches, 0, 5);
				foreach ($top5 as $ti => $tm) {
					if (strtolower($tm['media_type'] ?? '') === 'video' && $ti > 0) {
						$vid = array_splice($matches, $ti, 1);
						array_unshift($matches, $vid[0]);
						wiz_log("  video promoted from pos $ti to pos 0: " . $vid[0]['filename']);
						break;
					}
				}
			}

			$assigned = [];
			foreach ($matches as $rank => $match) {
				if (count($assigned) >= $slots_needed) break;

				$fn = $match['filename'];

				// Skip if used in THIS video already
				if (in_array($fn, $used_filenames)) continue;

				// Skip if used in one of the last 10 videos (soft dedup)
				// Allow fallback: if we've checked all matches and still need slots, relax this rule
				if (in_array($fn, $recently_used) && count($matches) > ($rank + 1)) continue;

				$matchedTermsJson = !empty($match['matched_terms']) ? json_encode($match['matched_terms']) : '[]';
				$slot_number      = count($assigned) + 1;

				// ── Log to hdb_media_match_log ────────────────────────────────
				if ($log_table_exists && $podcast_id > 0 && $scene_id > 0) {
					$esc_fn  = mysqli_real_escape_string($conn, $fn);
					$esc_sq  = mysqli_real_escape_string($conn, $search_query);
					$esc_snl = mysqli_real_escape_string($conn, $scene_nl_tags);
					$esc_anl = mysqli_real_escape_string($conn, $match['nl_tags'] ?? '');
					$esc_mt  = mysqli_real_escape_string($conn, $matchedTermsJson);
					$score   = (float)$match['score'];

					mysqli_query($conn,
						"INSERT INTO hdb_media_match_log
						 (podcast_id, scene_id, slot_number, assigned_filename,
						  search_query, scene_nl_tags, matched_terms,
						  similarity_score, match_rank, asset_nl_tags)
						 VALUES
						 ($podcast_id, $scene_id, $slot_number, '$esc_fn',
						  '$esc_sq', '$esc_snl', '$esc_mt',
						  $score, " . ($rank + 1) . ", '$esc_anl')"
					);
				}

				$assigned[]       = [
					'filename'            => $fn,
					'type'                => $match['media_type'],
					'score'               => $match['score'],
					'rank'                => $rank + 1,
					'matched_terms'       => $matchedTermsJson,
					'matched_terms_array' => $match['matched_terms'] ?? [],
					'asset_nl_tags'       => $match['nl_tags'] ?? '',
					'search_query'        => $search_query,
				];
				$used_filenames[] = $fn;
			}

			// Save newly claimed files to DB so next parallel request excludes them
			if ($podcast_id > 0 && !empty($assigned)) {
				$insert_vals = implode(',', array_map(function($a) use ($conn, $podcast_id) {
					$ef = mysqli_real_escape_string($conn, $a['filename']);
					return "($podcast_id,'$ef')";
				}, $assigned));
				if ($insert_vals) mysqli_query($conn, "INSERT IGNORE INTO hdb_build_used_files (podcast_id,filename) VALUES $insert_vals");
			}

			$results[] = [
				'scene_idx' => $idx,
				'query'     => $search_query,
				'found'     => $assigned,
			];
			wiz_log("⏱ scene $idx total: " . wiz_ms($_t_scene) . " → " . count($assigned) . " assigned");
		}

		wiz_log("=== SEARCH_IMAGES_BATCH DONE: " . count($results) . " scenes | total=" . wiz_sec($_t_batch) . " ===");
		echo json_encode(['podcast_id' => $podcast_id, 'results' => $results]);
		exit;
	}
	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: create_scenes_from_content
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'create_scenes_from_content') {
		try {
			$company_id    = (int)($_POST['company_id'] ?? $_SESSION['company_id'] ?? 0);
			$user_settings = getUserSettingsWiz($conn, $admin_id, $company_id);

			$title       = trim($_POST['title']       ?? '');
			$lang_code   = mysqli_real_escape_string($conn, $_POST['target_lang'] ?? 'en');
			$reel_type   = mysqli_real_escape_string($conn, $_POST['reel_type']   ?? 'standard');
			$topic_key   = mysqli_real_escape_string($conn, $_POST['topic']       ?? $_POST['topic_key'] ?? 'general');
			$category    = mysqli_real_escape_string($conn, $_POST['category']    ?? 'free-format');
			$cta         = mysqli_real_escape_string($conn, $_POST['cta']         ?? '');
			$niche       = mysqli_real_escape_string($conn, $_POST['niche']       ?? '');
			$host_voice  = mysqli_real_escape_string($conn, $_POST['host_voice']  ?? '');
			$guest_voice = mysqli_real_escape_string($conn, $_POST['guest_voice'] ?? '');
			$voice_rate  = (float)($_POST['rate'] ?? 1.0);

			$scenes_json = $_POST['content'] ?? '[]';
			$scenes      = json_decode($scenes_json, true);

			// If no scenes passed via POST, read from hdb_podcasts.script_text
			if (!is_array($scenes) || empty($scenes)) {
				$podcast_id_for_script = (int)($_POST['podcast_id'] ?? 0);
				if ($podcast_id_for_script) {
					$script_row     = mysqli_fetch_assoc(mysqli_query($conn,
						"SELECT script_text FROM hdb_podcasts
						 WHERE id=$podcast_id_for_script AND admin_id=$admin_id LIMIT 1"));
					$script_from_db = trim($script_row['script_text'] ?? '');
					if (!empty($script_from_db)) {

						$is_broll   = stripos($reel_type, 'broll') !== false || stripos($reel_type, 'b-roll') !== false;
						$is_podcast = stripos($reel_type, 'podcast') !== false;

						if ($is_broll) {
							// B-Roll = entire script is ONE scene (continuous voiceover)
							$clean = preg_replace('/<break[^>]*>/i', '', $script_from_db);
							$clean = preg_replace('/[ \t]+/', ' ', $clean);
							$clean = preg_replace('/\n{3,}/', "\n\n", $clean);
							$clean = trim($clean);
							$scenes = [['text'=>$clean,'prompt'=>'','hashtags'=>'','nl_tags'=>'','actor'=>'host']];
							wiz_log("create_scenes_from_content: B-Roll — 1 combined scene");

						} elseif ($is_podcast) {
							// Podcast = split by newline, detect HOST:/GUEST: prefix per line
							$raw_lines = explode("\n", $script_from_db);
							$lines     = array_values(array_filter(array_map('trim', $raw_lines)));
							$scenes    = array_map(function($line) {
								$actor = 'host';
								if (preg_match('/^guest\s*:/i', $line)) $actor = 'guest';
								// Strip host:/guest: prefix and <break> tags
								$text = preg_replace('/^(host|guest)\s*:\s*/i', '', $line);
								$text = preg_replace('/<break[^>]*>/i', '', $text);
								$text = trim($text);
								return ['text'=>$text,'prompt'=>'','hashtags'=>'','nl_tags'=>'','actor'=>$actor];
							}, $lines);
							wiz_log("create_scenes_from_content: Podcast — ".count($scenes)." scenes (host/guest split)");

						} else {
							// Standard / Talking Head = split by [SCENE BREAK] or newline
							if (strpos($script_from_db, '[SCENE BREAK]') !== false) {
								$raw_lines = preg_split('/\[SCENE BREAK\]/i', $script_from_db);
							} else {
								$raw_lines = explode("\n", $script_from_db);
							}
							$lines  = array_values(array_filter(array_map('trim', $raw_lines)));
							$scenes = array_map(function($line) {
								$text = preg_replace('/<break[^>]*>/i', '', $line);
								return ['text'=>trim($text),'prompt'=>'','hashtags'=>'','nl_tags'=>'','actor'=>'host'];
							}, $lines);
							wiz_log("create_scenes_from_content: Standard — ".count($scenes)." scenes");
						}
					}
				}
			}

			if (!is_array($scenes) || empty($scenes)) {
				echo json_encode(['success'=>false,'message'=>'No valid scenes found']);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}

			wiz_log("create_scenes_from_content: ".count($scenes)." scenes | title=$title");

			// Build podcast-level hashtags/keywords
			$all_text = implode(' ', array_column($scenes, 'text'));
			$stop     = ['the','and','for','you','your','with','that','this','are','can','will','have',
						 'from','they','their','what','about','there','more','some','would','could',
						 'should','been','were','was','one','two','first','then','than','very','just',
						 'like','into','over','also','after','other','only'];
			$words    = array_diff(str_word_count(strtolower($all_text), 1), $stop);
			$kw_arr   = array_slice(array_unique(array_values($words)), 0, 10);
			$ht_arr   = array_map(fn($w) => '#'.$w, array_slice($kw_arr, 0, 7));
			if (!empty($niche)) {
				$kw_arr[] = strtolower($niche);
				$ht_arr[] = '#'.strtolower(preg_replace('/\s+/', '', $niche));
			}
			$hashtags  = mysqli_real_escape_string($conn, implode(', ', array_unique($ht_arr)));
			$keywords  = mysqli_real_escape_string($conn, implode(', ', array_unique($kw_arr)));
			$esc_title = mysqli_real_escape_string($conn, $title);
			$today     = date('Y-m-d');
			$company_id = (int)($_SESSION['company_id'] ?? $admin_id);

			// Insert podcast row
			$sql = "INSERT INTO hdb_podcasts
				(admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
				 created_date, updated_at, niche, category, topic_key, hashtags, keywords,
				 host_voice, guest_voice, voice_rate, is_campaign,
				 logo_flag, facebook_status, tiktok_status, instagram_status,
				 youtube_status, twitter_status, linkedin_status,
				 schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name)
				VALUES
				($admin_id, $team_lead_id, $company_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'draft',
				 '$today', NOW(), '$niche', '$category', '$topic_key', '$hashtags', '$keywords',
				 '$host_voice', '$guest_voice', $voice_rate, 0,
				 0, 'pending', 'pending', 'pending',
				 'pending', 'pending', 'pending',
				 '$today', '09:00', '$today', 'vertical', 'video', '', '')";  

			if (!mysqli_query($conn, $sql)) {
				wiz_log("podcast insert FAIL: ".mysqli_error($conn));
				echo json_encode(['success'=>false,'message'=>'Failed to create podcast: '.mysqli_error($conn)]);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}
			$podcast_id    = mysqli_insert_id($conn);
			$success_count = 0;
			wiz_log("podcast_id=$podcast_id");
			// Log podcast creation
			mysqli_query($conn,
				"INSERT INTO hdb_user_activity_log
				 (podcast_id, admin_id, action_type, action_detail)
				 VALUES ($podcast_id, $admin_id, 'podcast_created', 'type:$reel_type scenes:$success_count')"
			);
			foreach ($scenes as $i => $scene) {
				$text = preg_replace('/<break[^>]*>/', '', trim($scene['text'] ?? ''));
				if (empty($text)) continue;

				$prompt  = trim($scene['prompt']   ?? '');
				$sc_hash = str_replace('#', '', trim($scene['hashtags'] ?? ''));
				$nl_tags = trim($scene['nl_tags']  ?? '');
				$actor   = in_array($scene['actor'] ?? 'host', ['host','guest']) ? $scene['actor'] : 'host';
				$seq_no  = $i + 1;
				$is_broll_dur = stripos($reel_type, 'broll') !== false || stripos($reel_type, 'b-roll') !== false;
				$word_count   = count(array_filter(explode(' ', strip_tags($text))));
				$duration     = $is_broll_dur
					? max(30, (int)round(($word_count / 130) * 60))
					: max(3,  (int)round(($word_count / 130) * 60));
				$v_id    = ($actor === 'guest' && !empty($guest_voice)) ? $guest_voice : $host_voice;
				$disp    = substr($text, 0, 50).(strlen($text) > 50 ? '...' : '');

				// ── Per-scene video prompt — unique, zone-aware, human-centred ──────────
				$_vp = buildVideoPrompt($niche, $text, $seq_no, count($scenes));
				$video_prompt = $_vp['prompt'];
				wiz_log("[VideoPrompt] scene=$seq_no subject='" . $_vp['subject'] . "' action='" . $_vp['action'] . "'");
				wiz_log("[VideoPrompt] env='" . mb_substr($_vp['env'], 0, 120) . "'");
				wiz_log("[VideoPrompt] final=" . mb_substr($video_prompt, 0, 200));

				$te  = mysqli_real_escape_string($conn, $text);
				$de  = mysqli_real_escape_string($conn, $disp);
				$pe  = mysqli_real_escape_string($conn, $prompt);
				$vpe = mysqli_real_escape_string($conn, $video_prompt);
				$he  = mysqli_real_escape_string($conn, $sc_hash);
				$ne  = mysqli_real_escape_string($conn, $nl_tags);
				$ae  = mysqli_real_escape_string($conn, $actor);
				$ve  = mysqli_real_escape_string($conn, $v_id);
				$tke = mysqli_real_escape_string($conn, $title);

				// Clean INSERT — no font/caption columns (those live in hdb_captions only)
				$ins = "INSERT INTO hdb_podcast_stories
					(podcast_id, lang_code, category, topic_key, title, actor,
					 text_contents, text_display, duration, prompt, video_prompt, visual_type,
					 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
					 voice_id, voice_rate)
					VALUES
					($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', '$ae',
					 '$te', '$de', $duration, '$pe', '$vpe', 'image',
					 'PENDING', NOW(), $seq_no, 0, '$he', '$ne', '$ve', $voice_rate)";

				if (mysqli_query($conn, $ins)) {
					$story_id = mysqli_insert_id($conn);
					buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, $is_broll);
					$success_count++;
				} else {
					// Fallback without natural_language_tags column (older schema)
					if (mysqli_errno($conn) == 1054 && strpos(mysqli_error($conn), 'natural_language_tags') !== false) {
						$ins2 = "INSERT INTO hdb_podcast_stories
							(podcast_id, lang_code, category, topic_key, title, actor,
							 text_contents, text_display, duration, prompt, video_prompt, visual_type,
							 status, created_date, seq_no, logo_flag, hashtags, voice_id, voice_rate)
							VALUES
							($podcast_id, '$lang_code', '$category', '$topic_key', '$tke', '$ae',
							 '$te', '$de', $duration, '$pe', '$vpe', 'image',
							 'PENDING', NOW(), $seq_no, 0, '$he', '$ve', $voice_rate)";
						if (mysqli_query($conn, $ins2)) {
							$story_id = mysqli_insert_id($conn);
							buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, $is_broll);
							$success_count++;
						} else {
							wiz_log("scene $seq_no fallback FAIL: ".mysqli_error($conn));
						}
					} else {
						wiz_log("scene $seq_no INSERT FAIL: ".mysqli_error($conn));
					}
				}
			}

			wiz_log("create_scenes_from_content done: $success_count scenes");
			deductCredit($conn, $admin_id, $reel_type);

			// Reset video_type to standard after scenes are generated
			mysqli_query($conn,
				"UPDATE hdb_podcasts SET video_type='standard', updated_at=NOW() WHERE id=$podcast_id");
			wiz_log("create_scenes_from_content: reset video_type=standard for podcast=$podcast_id");
			echo json_encode([
				'success'     => true,
				'podcast_id'  => $podcast_id,
				'scene_count' => $success_count,
				'topic_key'   => $topic_key,
				'host_voice'  => $host_voice,
			]);

		} catch (Throwable $e) {
			wiz_log("create_scenes_from_content EXCEPTION: ".$e->getMessage());
			echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
		}
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: create_scenes_from_podcast
	// ══════════════════════════════════════════════════════════════════════════════
	// ══════════════════════════════════════════════════════════════════════════════
// ACTION: create_scenes_from_podcast
// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'create_scenes_from_podcast') {
		$_t_csp = microtime(true);
		try {
			$podcast_id  = (int)($_POST['podcast_id']  ?? 0);
			$host_voice  = trim($_POST['host_voice']   ?? '');
			$guest_voice = trim($_POST['guest_voice']  ?? $host_voice);
			$voice_rate  = (float)($_POST['rate']       ?? 1.0);
			$lang_code   = mysqli_real_escape_string($conn, trim($_POST['lang_code'] ?? 'en'));

			if (!$podcast_id) {
				echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}

			$pod_res = mysqli_query($conn,
				"SELECT * FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1");
			if (!$pod_res || mysqli_num_rows($pod_res) === 0) {
				echo json_encode(['success'=>false,'error'=>'Podcast not found or access denied']);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}
			$pod = mysqli_fetch_assoc($pod_res);

			$script_text = trim($pod['script_text'] ?? '');
			$title       = $pod['title']      ?? '';
			$niche       = $pod['niche']      ?? '';
			$category    = $pod['category']   ?? 'free-format';
			$topic_key   = $pod['topic_key']  ?? 'general';
			$reel_type   = $pod['video_type'] ?? 'standard';
			$ai_group    = mysqli_real_escape_string($conn, trim($_POST['ai_group']    ?? $pod['ai_group']    ?? $niche    ?? ''));
			$ai_subgroup = mysqli_real_escape_string($conn, trim($_POST['ai_subgroup'] ?? $pod['ai_subgroup'] ?? $category ?? ''));

			if (empty($script_text)) {
				echo json_encode(['success'=>false,'error'=>'No script_text found in this podcast record']);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}

			wiz_log("⏱ create_scenes_from_podcast START podcast=$podcast_id | title=$title | lang=$lang_code | script_len=" . strlen($script_text));

			// Clean slate
			$_t_db = microtime(true);
			mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id=$podcast_id");
			mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id=$podcast_id");
			wiz_log("⏱ DB clean slate: " . wiz_ms($_t_db));

			// Update podcast with voice/rate/lang
			$hv = mysqli_real_escape_string($conn, $host_voice);
			$gv = mysqli_real_escape_string($conn, $guest_voice);
			mysqli_query($conn,
				"UPDATE hdb_podcasts
				 SET host_voice='$hv', guest_voice='$gv', voice_rate=$voice_rate,
					 lang_code='$lang_code', internal_status='processing', updated_at=NOW(),
					 team_lead_id = $team_lead_id
				 WHERE id=$podcast_id");

			$user_settings = getUserSettingsWiz($conn, $admin_id, (int)($pod['company_id'] ?? $_SESSION['company_id'] ?? 0));

			// Split script
			$is_broll = ($_POST['is_broll'] ?? '0') === '1'
					 || stripos($reel_type, 'broll') !== false
					 || stripos($reel_type, 'b-roll') !== false;

			// ============================================================
			// KEY FIX: Use ENGLISH fields for search prompts, NOT the script text
			// The script text stays in the target language
			// ============================================================
			
			// Stop words for English keyword extraction (only for search terms)
			$stopWords = ['the','and','for','you','your','with','that','this','are','can','will',
						  'have','from','they','what','about','more','just','into','over','after',
						  'then','than','very','some','would','could','should','been','were','was',
						  'one','two','first','also','after','other','only'];
			
			// Extract keywords from ENGLISH-ONLY sources: niche, category, topic_key
			$eng_source = implode(' ', array_filter([$niche, $category, $topic_key]));
			$words = preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $eng_source)));
			$kws = array_values(array_filter($words, function($w) use ($stopWords) {
				return strlen($w) > 2 && !in_array($w, $stopWords);
			}));
			
			// Build hashtags from English fields only
			$tags = array_unique(array_merge(
				[$niche ? strtolower(preg_replace('/\s+/', '', $niche)) : 'general'],
				array_slice($kws, 0, 4)
			));
			$hashtags_base = implode(' ', $tags);
			
			// Build NL tags from English fields only (for better search results)
			$nl_tags_base = implode('|', array_unique(array_filter([
				($niche ? $niche . ' professional' : 'professional'),
				(!empty($kws[0]) ? $kws[0] . ' lifestyle' : 'lifestyle'),
				(!empty($kws[1]) ? ($niche ? $niche . ' ' : '') . $kws[1] : ($niche ? $niche . ' concept' : 'concept')),
				'real life ' . ($niche ?: 'business'),
				($topic_key ? $topic_key : ''),
				($category ? $category : ''),
			])));
			
			// Image prompt - ALWAYS in English (for stock image search)
			$image_prompt_base = "Photorealistic documentary-style photograph. Niche: {$niche}. Category: {$category}. "
								. "Natural lighting, candid composition, 35mm lens, shallow depth of field, authentic environment. Shot on Sony A7R, no yellow cast, crisp clean tones.";
			
			// video_prompt is built per-scene below using buildVideoPrompt().
			// $video_prompt_base is not used — kept as a variable name placeholder only.
			$video_prompt_base = '';
			
			wiz_log("create_scenes_from_podcast: Language=$lang_code | English prompts kept for search");
			wiz_log("  niche=$niche | category=$category | topic_key=$topic_key");
			wiz_log("  hashtags_base=$hashtags_base");
			wiz_log("  nl_tags_base=$nl_tags_base");
			
			// Now process the script (which may be in any language)
			if ($is_broll) {
				// B-Roll: Single combined scene
				$clean = preg_replace('/<break[^>]*>/i', '', $script_text);
				$clean = preg_replace('/[ \t]+/', ' ', $clean);
				$clean = preg_replace('/\n{3,}/', "\n\n", $clean);
				$clean = trim($clean);
				$lines = [$clean];
				wiz_log("create_scenes_from_podcast: B-Roll — 1 combined scene in $lang_code");
				
			} elseif (strpos($script_text, '[SCENE BREAK]') !== false) {
				$raw_lines = preg_split('/\[SCENE BREAK\]/i', $script_text);
				$lines = array_values(array_filter(array_map('trim', $raw_lines)));
			} else {
				$raw_lines = explode("\n", $script_text);
				$lines = array_values(array_filter(array_map('trim', $raw_lines)));
			}

			if (empty($lines)) {
				echo json_encode(['success'=>false,'error'=>'Script text produced no lines after splitting']);
				if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
			}

			$esc_title    = mysqli_real_escape_string($conn, $title);
			$esc_category = mysqli_real_escape_string($conn, $category);
			$esc_topic    = mysqli_real_escape_string($conn, $topic_key);
			$success_count = 0;
			$_t_loop = microtime(true);

			foreach ($lines as $i => $line) {
				$_t_scene = microtime(true);
				// Keep the text in its original language (target language)
				$text = trim(preg_replace('/<break[^>]*>/i', '', $line));
				if (empty($text)) continue;

				$seq_no     = $i + 1;
				$word_count = count(array_filter(explode(' ', trim(preg_replace('/<[^>]*>/', '', $text)))));
				$duration   = $is_broll ? max(30, (int)round(($word_count / 130) * 60))
										: max(3,  (int)round(($word_count / 130) * 60));

				// Use the ENGLISH base values for search (these are the same for all scenes)
				$hashtags_str = $hashtags_base;
				$nl_tags      = $nl_tags_base;
				$image_prompt = $image_prompt_base;

				// ── Per-scene video prompt — unique, zone-aware, human-centred ──────────
				$_vp = buildVideoPrompt($niche, $text, $seq_no, count($lines));
				$video_prompt = $_vp['prompt'];
				wiz_log("[VideoPrompt] scene=$seq_no subject='" . $_vp['subject'] . "' action='" . $_vp['action'] . "'");
				wiz_log("[VideoPrompt] env='" . mb_substr($_vp['env'], 0, 120) . "'");
				wiz_log("[VideoPrompt] final=" . mb_substr($video_prompt, 0, 200));
				
				// For variety across scenes, append scene number to NL tags (still in English)
				if (!$is_broll && count($lines) > 1) {
					$nl_tags .= "|scene_{$seq_no}_" . ($seq_no % 2 == 0 ? "variation" : "alternate");
				}

				$disp = substr($text, 0, 50) . (strlen($text) > 50 ? '...' : '');
				$te   = mysqli_real_escape_string($conn, $text);
				$de   = mysqli_real_escape_string($conn, $disp);
				$pe   = mysqli_real_escape_string($conn, $image_prompt);
				$vpe  = mysqli_real_escape_string($conn, $video_prompt);
				$he   = mysqli_real_escape_string($conn, $hashtags_str);
				$ne   = mysqli_real_escape_string($conn, $nl_tags);
				$ve   = mysqli_real_escape_string($conn, $host_voice);

				wiz_log("scene $seq_no: text_len=" . strlen($text) . " | hashtags=$hashtags_str | nl_tags=" . substr($nl_tags, 0, 50) . "...");

				// Insert scene with script text in target language, but search fields in English
				$ins = "INSERT INTO hdb_podcast_stories
					(podcast_id, lang_code, category, topic_key, title, actor,
					 text_contents, text_display, duration, prompt, video_prompt, visual_type,
					 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
					 voice_id, voice_rate)
					VALUES
					($podcast_id, '$lang_code', '$esc_category', '$esc_topic', '$esc_title', 'host',
					 '$te', '$de', $duration, '$pe', '$vpe', 'image',
					 'PENDING', NOW(), $seq_no, 0, '$he', '$ne',
					 '$ve', $voice_rate)";

				if (mysqli_query($conn, $ins)) {
					$story_id = mysqli_insert_id($conn);
					$_t_cap = microtime(true);
					buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, $is_broll);
					wiz_log("⏱ scene $seq_no INSERT+captions: " . wiz_ms($_t_scene) . " (captions: " . wiz_ms($_t_cap) . ")");
					$success_count++;
				} else {
					// Fallback without natural_language_tags (older schema)
					if (mysqli_errno($conn) == 1054 && strpos(mysqli_error($conn), 'natural_language_tags') !== false) {
						$ins2 = "INSERT INTO hdb_podcast_stories
							(podcast_id, lang_code, category, topic_key, title, actor,
							 text_contents, text_display, duration, prompt, video_prompt, visual_type,
							 status, created_date, seq_no, logo_flag, hashtags,
							 voice_id, voice_rate)
							VALUES
							($podcast_id, '$lang_code', '$esc_category', '$esc_topic', '$esc_title', 'host',
							 '$te', '$de', $duration, '$pe', '$vpe', 'image',
							 'PENDING', NOW(), $seq_no, 0, '$he',
							 '$ve', $voice_rate)";
						if (mysqli_query($conn, $ins2)) {
							$story_id = mysqli_insert_id($conn);
							$_t_cap = microtime(true);
							buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, $is_broll);
							wiz_log("⏱ scene $seq_no fallback INSERT+captions: " . wiz_ms($_t_scene) . " (captions: " . wiz_ms($_t_cap) . ")");
							$success_count++;
						} else {
							wiz_log("scene $seq_no fallback FAIL: ".mysqli_error($conn));
						}
					} else {
						wiz_log("scene $seq_no INSERT FAIL: ".mysqli_error($conn));
					}
				}
			}

			mysqli_query($conn,
				"UPDATE hdb_podcasts SET internal_status='scenes_ready', video_type='standard', updated_at=NOW(),
				 ai_group='$ai_group', ai_subgroup='$ai_subgroup'
				 WHERE id=$podcast_id");

			wiz_log("⏱ create_scenes_from_podcast DONE: $success_count scenes | loop=" . wiz_ms($_t_loop) . " | total=" . wiz_sec($_t_csp));
			deductCredit($conn, $admin_id, $reel_type);
			
			echo json_encode([
				'success'     => true,
				'podcast_id'  => $podcast_id,
				'scene_count' => $success_count,
				'language'    => $lang_code,
				'message'     => "Created $success_count scenes with script in $lang_code, search prompts in English"
			]);

		} catch (Throwable $e) {
			wiz_log("create_scenes_from_podcast EXCEPTION: ".$e->getMessage());
			echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
		}
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}
	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: get_scenes
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'get_scenes') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		$result     = mysqli_query($conn,
			"SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC");
		$scenes = [];
		while ($row = mysqli_fetch_assoc($result)) $scenes[] = $row;
		echo json_encode($scenes);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: check_podcast
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'check_podcast') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		$q = mysqli_query($conn,
			"SELECT id FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1");
		echo json_encode(['success'=>true,'exists'=>($q && mysqli_num_rows($q)>0),'podcast_id'=>$podcast_id]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: generate_scene_audio
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'generate_scene_audio') {
		$_t_audio = microtime(true);
		$scene_id   = (int)($_POST['scene_id']   ?? 0);
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		$seq_no     = (int)($_POST['seq_no']     ?? 1);
		$lang_code  = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? 'en');
		$voice_id   = trim($_POST['voice_id'] ?? '');
		$rate       = $_POST['rate'] ?? '1.0';
		$text       = trim($_POST['text'] ?? '');

		if (empty($text))     { echo json_encode(['success'=>false,'error'=>'No text']);  if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit; }
		if (empty($voice_id)) { echo json_encode(['success'=>false,'error'=>'No voice']); if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit; }

		$audio_dir = __DIR__ . '/podcast_audios/';
		if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);
		$filename = 'voice_'.$podcast_id.'_'.$scene_id.'_'.$lang_code.'.mp3';
		$filepath = $audio_dir.$filename;

		wiz_log("⏱ audio START scene=$scene_id voice=$voice_id chars=" . strlen($text));

		if (strpos($voice_id, 'openai:') === 0) {
			$result = generateVoiceOpenAI_wiz($text, $voice_id, $filepath, $rate);
		} else {
			if (file_exists(__DIR__.'/chatgpt_functions.php')) {
				require_once __DIR__.'/chatgpt_functions.php';
				$_t_azure = microtime(true);
				$result = generateVoice($text, $voice_id, $rate, $filepath);
				wiz_log("⏱ Azure TTS API: " . wiz_sec($_t_azure) . " | voice=$voice_id | chars=" . strlen($text));
			} else {
				$result = ['success'=>false,'error'=>'Azure TTS: chatgpt_functions.php not found'];
			}
		}

		if ($result['success']) {
			$ve = mysqli_real_escape_string($conn, $voice_id);
			$re = mysqli_real_escape_string($conn, $rate);

			// Measure actual audio duration from the written MP3 file
			$real_duration = getMp3DurationSeconds($filepath);
			$dur_sql       = ($real_duration !== null) ? ", duration=$real_duration" : '';
			$file_kb       = round(filesize($filepath) / 1024);
			wiz_log("⏱ audio DONE scene=$scene_id file=$filename duration=" . ($real_duration ?? 'n/a') . "s size={$file_kb}KB total=" . wiz_sec($_t_audio));

			mysqli_query($conn,
				"UPDATE hdb_podcast_stories
				 SET audio_file='$filename', voice_id='$ve', voice_rate='$re'$dur_sql
				 WHERE id=$scene_id");

			echo json_encode([
				'success'   => true,
				'filename'  => $filename,
				'file_url'  => 'podcast_audios/'.$filename,
				'duration'  => $real_duration,
			]);
		} else {
			wiz_log("⏱ audio FAIL scene=$scene_id elapsed=" . wiz_sec($_t_audio) . " err=" . ($result['error'] ?? '?'));
			echo json_encode(['success'=>false,'error'=>$result['error']??'Audio generation failed']);
		}
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: check_audio_file
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'check_audio_file') {
		$filename = basename($_POST['filename'] ?? '');
		$filepath = __DIR__.'/podcast_audios/'.$filename;
		$exists   = file_exists($filepath);
		$deleted  = $exists ? @unlink($filepath) : false;
		echo json_encode(['success'=>true,'exists'=>$exists,'deleted'=>$deleted,'filename'=>$filename]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: assign_image
	// ══════════════════════════════════════════════════════════════════════════════
	// ══════════════════════════════════════════════════════════════════════════════
// ACTION: assign_image


	// ══════════════════════════════════════════════════════════════════════════════
// ACTION: assign_image
// ══════════════════════════════════════════════════════════════════════════════
	// ══════════════════════════════════════════════════════════════════════════════
// ACTION: assign_image
// ══════════════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════════════
// ACTION: assign_image
// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'assign_image') {
		$_t_assign = microtime(true);

		$scene_id    = (int)($_POST['scene_id']    ?? 0);
		$filename    = mysqli_real_escape_string($conn, $_POST['filename']    ?? '');
		$image_field = mysqli_real_escape_string($conn, $_POST['image_field'] ?? 'image_file');

		// Get match metadata from frontend
		$search_query     = mysqli_real_escape_string($conn, $_POST['search_query'] ?? '');
		$similarity_score = (float)($_POST['similarity_score'] ?? 0);
		$match_rank       = (int)($_POST['match_rank'] ?? 0);
		$matched_terms    = mysqli_real_escape_string($conn, $_POST['matched_terms'] ?? '');
		$podcast_id       = (int)($_POST['podcast_id'] ?? 0);
		
		// Get the reel type to determine if this is B-roll
		$reel_type = '';
		$rt_query = mysqli_query($conn, "SELECT video_type FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1");
		if ($rt_query && $rt_row = mysqli_fetch_assoc($rt_query)) {
			$reel_type = strtolower($rt_row['video_type'] ?? '');
		}
		$is_broll = (strpos($reel_type, 'broll') !== false || strpos($reel_type, 'b-roll') !== false);
		
		// LOG FOR DEBUGGING
		wiz_log("=== ASSIGN_IMAGE DEBUG ===");
		wiz_log("podcast_id: $podcast_id");
		wiz_log("reel_type from DB: '$reel_type'");
		wiz_log("is_broll: " . ($is_broll ? 'TRUE' : 'FALSE'));
		
		// Determine slot number (1-5) and field name for consistent tracking
		$slot_map = [
			'image_file'   => 1,
			'image_file_1' => 2,
			'image_file_2' => 3,
			'image_file_3' => 4,
			'image_file_4' => 5
		];
		$slot_number = $slot_map[$image_field] ?? 1;

		$allowed_fields = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
		if (!in_array($image_field, $allowed_fields)) $image_field = 'image_file';

		if (!$scene_id || !$filename) {
			wiz_log("assign_image ERROR: Missing params scene_id=$scene_id filename=$filename");
			echo json_encode(['success'=>false,'error'=>'Missing params']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// Look up thumbnail AND natural_language_tags from hdb_image_data
		$thumb = '';
		$asset_nl_tags = '';
		$_t_lookup = microtime(true);
		$tq = mysqli_query($conn,
			"SELECT thumbnail, natural_language_tags, media_type as db_media_type FROM hdb_image_data WHERE image_name='$filename' LIMIT 1");
		if ($tq && $tr = mysqli_fetch_assoc($tq)) {
			$thumb         = mysqli_real_escape_string($conn, trim($tr['thumbnail']             ?? ''));
			$asset_nl_tags = mysqli_real_escape_string($conn, trim($tr['natural_language_tags'] ?? ''));
		}
		wiz_log("⏱ assign_image lookup: " . wiz_ms($_t_lookup) . " | scene=$scene_id slot=$slot_number file=$filename");

		// Determine media type from file extension (reliable regardless of DB value)
		$video_exts   = ['mp4','webm','mov','avi','mkv','m4v'];
		$file_ext     = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$is_video     = in_array($file_ext, $video_exts);
		$image_folder = $is_video ? 'podcast_videos' : 'podcast_images';
		wiz_log("assign_image media detect: file=$filename ext=$file_ext is_video=" . ($is_video?'YES':'NO') . " image_folder=$image_folder");

		// Map each image slot to its corresponding image_folder column
		$folder_field_map = [
			'image_file'   => 'image_folder',
			'image_file_1' => 'image_folder_1',
			'image_file_2' => 'image_folder_2',
			'image_file_3' => 'image_folder_3',
			'image_file_4' => 'image_folder_4',
		];
		$folder_field = $folder_field_map[$image_field] ?? 'image_folder';

		// Assign image field + save thumbnail + update image_folder columns
		$_t_upd = microtime(true);
		if ($image_field === 'image_file' && !empty($thumb)) {
			$ok = mysqli_query($conn,
				"UPDATE hdb_podcast_stories
				 SET `$image_field`='$filename', thumbnail='$thumb',
				     image_folder='$image_folder', `$folder_field`='$image_folder'
				 WHERE id=$scene_id");
		} else {
			$ok = mysqli_query($conn,
				"UPDATE hdb_podcast_stories
				 SET `$image_field`='$filename',
				     image_folder='$image_folder', `$folder_field`='$image_folder'
				 WHERE id=$scene_id");
		}
		wiz_log("⏱ assign_image DB update: " . wiz_ms($_t_upd) . " | ok=" . ($ok?'YES':'NO') . " | image_folder=$image_folder | folder_field=$folder_field");

		// LOG THE MATCH to hdb_media_match_log table
		if ($ok && $podcast_id > 0 && $scene_id > 0 && !empty($search_query)) {
			$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_media_match_log'");
			if ($table_check && mysqli_num_rows($table_check) > 0) {
				$esc_filename = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');
				$esc_query    = $search_query;
				$esc_terms    = $matched_terms;
				$log_sql = "INSERT INTO hdb_media_match_log
					(podcast_id, scene_id, slot_number, assigned_filename,
					 search_query, matched_terms, similarity_score, match_rank, asset_nl_tags)
					VALUES
					($podcast_id, $scene_id, $slot_number, '$esc_filename',
					 '$esc_query', '$esc_terms',
					 $similarity_score, $match_rank, '$asset_nl_tags')";
				mysqli_query($conn, $log_sql);
			}
		}

		// For B-roll, get ALL assigned slots to return complete slot status
		$assigned_slots = [];
		$any_slot_filled = false;
		$all_slots_filled = false;
		
		if ($is_broll) {
			// Query to get all image slots for this scene
			$slot_query = mysqli_query($conn,
				"SELECT 
					image_file, image_file_1, image_file_2, image_file_3, image_file_4
				 FROM hdb_podcast_stories 
				 WHERE id = $scene_id");
			
			if ($slot_query && $slots = mysqli_fetch_assoc($slot_query)) {
				$assigned_slots = [
					1 => !empty(trim($slots['image_file'] ?? '')),
					2 => !empty(trim($slots['image_file_1'] ?? '')),
					3 => !empty(trim($slots['image_file_2'] ?? '')),
					4 => !empty(trim($slots['image_file_3'] ?? '')),
					5 => !empty(trim($slots['image_file_4'] ?? ''))
				];
				$any_slot_filled = in_array(true, $assigned_slots);
				$all_slots_filled = $assigned_slots[1] && $assigned_slots[2] && $assigned_slots[3] && 
									$assigned_slots[4] && $assigned_slots[5];
				
				wiz_log("B-roll slot status: " . json_encode($assigned_slots));
				wiz_log("any_slot_filled: " . ($any_slot_filled ? 'TRUE' : 'FALSE'));
				wiz_log("all_slots_filled: " . ($all_slots_filled ? 'TRUE' : 'FALSE'));
			}
		}

		wiz_log("⏱ assign_image TOTAL: " . wiz_ms($_t_assign) . " | scene=$scene_id");
		wiz_log("=== END ASSIGN_IMAGE DEBUG ===");
		
		// Return response with explicit flags for B-roll
		$response = [
			'success' => (bool)$ok,
			'thumbnail' => $thumb,
			'is_broll' => $is_broll,
			'reel_type' => $reel_type,  // Added for debugging
			'assigned_slots' => $assigned_slots,
			'any_slot_filled' => $any_slot_filled,
			'all_slots_filled' => $all_slots_filled,
			'slot_number' => $slot_number,
			'slot_filled' => true
		];
		
		echo json_encode($response);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}
	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: log_media_search
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'log_media_search') {
		$podcast_id    = (int)($_POST['podcast_id']    ?? 0);
		$scene_id      = (int)($_POST['scene_id']      ?? 0);
		$scene_no      = (int)($_POST['scene_no']      ?? 0);
		$hashtags      = mysqli_real_escape_string($conn, $_POST['hashtags']       ?? '');
		$found_images  = (int)($_POST['found_images']  ?? 0);
		$found_videos  = (int)($_POST['found_videos']  ?? 0);
		$selected_file = mysqli_real_escape_string($conn, $_POST['selected_file']  ?? '');
		$selected_type = mysqli_real_escape_string($conn, $_POST['selected_type']  ?? '');
		$was_duplicate = (int)($_POST['was_duplicate'] ?? 0);
		$ai_generated  = (int)($_POST['ai_generated']  ?? 0);
		$ai_prompt     = mysqli_real_escape_string($conn, $_POST['ai_prompt']      ?? '');

		$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_media_log'");
		if ($tbl && mysqli_num_rows($tbl) > 0) {
			mysqli_query($conn, "INSERT INTO hdb_media_log
				(admin_id,podcast_id,scene_id,scene_no,hashtags,
				 search_found_images,search_found_videos,
				 selected_file,selected_type,was_duplicate,ai_generated,ai_prompt)
				VALUES
				($admin_id,$podcast_id,$scene_id,$scene_no,'$hashtags',
				 $found_images,$found_videos,
				 '$selected_file','$selected_type',$was_duplicate,$ai_generated,'$ai_prompt')");
		}
		echo json_encode(['success'=>true]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: update_scene_tags
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'update_scene_tags') {
		$_t_tags      = microtime(true);
		$scene_id     = (int)($_POST['scene_id'] ?? 0);
		$hashtags     = mysqli_real_escape_string($conn, $_POST['hashtags']     ?? '');
		$nl_tags      = mysqli_real_escape_string($conn, $_POST['nl_tags']      ?? '');
		$prompt       = mysqli_real_escape_string($conn, $_POST['prompt']       ?? '');
		$video_prompt = mysqli_real_escape_string($conn, $_POST['video_prompt'] ?? '');
		$prompt_1     = mysqli_real_escape_string($conn, $_POST['prompt_1']     ?? '');
		$prompt_2     = mysqli_real_escape_string($conn, $_POST['prompt_2']     ?? '');
		$prompt_3     = mysqli_real_escape_string($conn, $_POST['prompt_3']     ?? '');
		$prompt_4     = mysqli_real_escape_string($conn, $_POST['prompt_4']     ?? '');

		if (!$scene_id) {
			echo json_encode(['success'=>false,'error'=>'Missing scene_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// Only overwrite video_prompt if a non-empty value is provided — preserve the
		// value inserted during create_scenes_from_podcast if nothing new is sent
		$video_prompt_sql = !empty($video_prompt) ? "video_prompt='$video_prompt'," : '';

		$ok = mysqli_query($conn,
			"UPDATE hdb_podcast_stories SET
				hashtags='$hashtags',
				natural_language_tags='$nl_tags',
				prompt='$prompt',
				$video_prompt_sql
				prompt_1='$prompt_1',
				prompt_2='$prompt_2',
				prompt_3='$prompt_3',
				prompt_4='$prompt_4'
			 WHERE id=$scene_id");
		wiz_log("⏱ update_scene_tags scene=$scene_id vp=" . (!empty($video_prompt) ? 'updated' : 'preserved') . ": " . wiz_ms($_t_tags));
		echo json_encode(['success'=>(bool)$ok]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: rebuild_scenes
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'rebuild_scenes') {
		$podcast_id  = (int)($_POST['podcast_id'] ?? 0);
		$scenes_json = $_POST['scenes'] ?? '[]';
		$host_voice  = trim($_POST['host_voice'] ?? '');
		$rate        = (float)($_POST['rate'] ?? 1.0);
		$lang_code   = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? 'en');

		if (!$podcast_id) {
			echo json_encode(['success'=>false,'error'=>'No podcast_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id=$podcast_id");
		mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id=$podcast_id");

		// Fetch company_id AND reel type from the podcast row
		$_pod_co = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT company_id, video_type FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
		$_rebuild_company_id = (int)($_pod_co['company_id'] ?? $_SESSION['company_id'] ?? 0);
		$_rebuild_reel_type  = strtolower($_pod_co['video_type'] ?? '');
		$_rebuild_is_broll   = strpos($_rebuild_reel_type, 'broll') !== false || strpos($_rebuild_reel_type, 'b-roll') !== false;
		wiz_log("rebuild_scenes: podcast=$podcast_id reel_type=$_rebuild_reel_type is_broll=" . ($_rebuild_is_broll ? '1' : '0'));

		$user_settings = getUserSettingsWiz($conn, $admin_id, $_rebuild_company_id);
		$scenes        = json_decode($scenes_json, true) ?: [];
		$count         = 0;

		foreach ($scenes as $i => $scene) {
			$text = trim(preg_replace('/<break[^>]*>/i', '', $scene['text'] ?? ''));
			if (empty($text)) continue;
			$seq_no     = $i + 1;
			$word_count = count(array_filter(explode(' ', $text)));
			$duration   = max(3, (int)round(($word_count / 130) * 60));
			$te = mysqli_real_escape_string($conn, $text);
			$ve = mysqli_real_escape_string($conn, $host_voice);

			$ins = "INSERT INTO hdb_podcast_stories
				(podcast_id, lang_code, text_contents, text_display, duration,
				 status, created_date, seq_no, voice_id, voice_rate, visual_type, actor)
				VALUES
				($podcast_id, '$lang_code', '$te', '$te', $duration,
				 'PENDING', NOW(), $seq_no, '$ve', $rate, 'image', 'host')";

			if (mysqli_query($conn, $ins)) {
				$story_id = mysqli_insert_id($conn);
				buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, $_rebuild_is_broll);
				$count++;
			}
		}

		echo json_encode(['success'=>true,'scene_count'=>$count]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: save_edited_script
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'save_edited_script') {
		if (ob_get_length()) ob_clean();
		$podcast_id  = (int)($_POST['podcast_id'] ?? 0);
		$script_text = trim($_POST['script'] ?? '');

		if (!$podcast_id) {
			echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		$esc_script = mysqli_real_escape_string($conn, $script_text);
		$ok = mysqli_query($conn,
			"UPDATE hdb_podcasts
			 SET script_text='$esc_script', updated_at=NOW(), team_lead_id=$team_lead_id
			 WHERE id=$podcast_id AND admin_id=$admin_id");

		wiz_log("save_edited_script: podcast=$podcast_id ok=".($ok?'1':'0'));
		echo json_encode(['success'=>(bool)$ok]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: get_thumbnail_from_scene
	// ══════════════════════════════════════════════════════════════════════════════
	// ── Paste this block into wizard_step2.php ──────────────────────────────────
// Replace your existing 'get_thumbnail_from_scene' action handler with this:

	if ($action === 'get_thumbnail_from_scene') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'no podcast_id']); exit; }

		// Get the first scene that has any media assigned
		$q = mysqli_query($conn,
			"SELECT image_file, image_file_1, video_file
			 FROM hdb_podcast_stories
			 WHERE podcast_id = $podcast_id
			 ORDER BY seq_no ASC, id ASC
			 LIMIT 1");

		if (!$q || mysqli_num_rows($q) === 0) {
			echo json_encode(['success'=>false,'error'=>'no scenes']); exit;
		}

		$scene    = mysqli_fetch_assoc($q);
		$filename = '';
		$is_video = false;

		// Prefer video_file first, fall back to image_file slots
		if (!empty(trim($scene['video_file'] ?? ''))) {
			$filename = trim($scene['video_file']);
			$is_video = true;
		} elseif (!empty(trim($scene['image_file'] ?? ''))) {
			$filename = trim($scene['image_file']);
		} elseif (!empty(trim($scene['image_file_1'] ?? ''))) {
			$filename = trim($scene['image_file_1']);
		}

		if (!$filename) {
			echo json_encode(['success'=>false,'error'=>'no media file on first scene']); exit;
		}

		// Look up thumbnail from hdb_image_data — column is image_name (not filename)
		// For videos this returns the pre-generated thumbnail image stored in the DB.
		// For images it may return a resized/thumb version or be empty.
		$esc = mysqli_real_escape_string($conn, $filename);
		$tq  = mysqli_query($conn,
			"SELECT thumbnail FROM hdb_image_data
			 WHERE image_name = '$esc'
			 LIMIT 1");

		$thumbnail = '';
		if ($tq && $row = mysqli_fetch_assoc($tq)) {
			$thumbnail = trim($row['thumbnail'] ?? '');
		}

		// For images: if no explicit thumbnail row, the image itself is its own thumbnail
		if (empty($thumbnail) && !$is_video && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
			$thumbnail = $filename;
		}

		// For videos: thumbnail MUST come from image_data — we never use the raw video as thumbnail
		if (empty($thumbnail) && $is_video) {
			wiz_log("get_thumbnail_from_scene: video $filename has no thumbnail in hdb_image_data");
			echo json_encode(['success'=>false,'error'=>'no thumbnail for video: '.$filename]); exit;
		}

		if ($thumbnail) {
			$esc_thumb = mysqli_real_escape_string($conn, $thumbnail);
			mysqli_query($conn,
				"UPDATE hdb_podcasts SET thumbnail = '$esc_thumb' WHERE id = $podcast_id");
			wiz_log("get_thumbnail_from_scene: podcast=$podcast_id thumb=$thumbnail (from ".($is_video?'video':'image').")");
			echo json_encode(['success'=>true,'thumbnail'=>$thumbnail,'source'=>$is_video?'video':'image']);
		} else {
			echo json_encode(['success'=>false,'error'=>'no thumbnail found for: '.$filename]);
		}
		exit;
	}


	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: search_images — semantic embedding match with duplicate exclusion
	// FIX: UNION wrapped in derived table alias (MySQL requires this for NOT IN)
	if ($action === 'search_images') {
		require_once __DIR__ . '/image_search_functions.php';
		
		$query           = trim($_POST['hashtags'] ?? $_POST['query'] ?? '');
		$limit           = max(1, min(10, (int)($_POST['limit'] ?? 5)));
		$podcast_id      = (int)($_POST['podcast_id'] ?? 0);
		$media_type      = trim($_POST['media_type_filter'] ?? '');
		$include_mine    = isset($_POST['include_mine']) ? (bool)$_POST['include_mine'] : false;
		
		if (empty($query)) {
			echo json_encode([]);
			exit;
		}
		
		global $apiKey;
		
		// Use the shared search function
		$results = searchAssets($conn, $query, $apiKey, $podcast_id, $media_type, $include_mine, $admin_id, $limit, 0.25);
		
		// Format for wizard frontend with match details
		$formatted = [];
		foreach ($results as $rank => $r) {
			$formatted[] = [
				'filename'      => $r['filename'],
				'type'          => $r['media_type'] ?? 'image',
				'score'         => $r['score'],
				'score_pct'     => $r['score_pct'] ?? round($r['score'] * 100, 1),
				'thumbnail'     => $r['thumbnail'] ?? '',
				'rank'          => $rank + 1,
				'matched_terms' => $r['matched_terms'] ?? [],
				'asset_nl_tags' => $r['nl_tags'] ?? ''
			];
		}
		
		echo json_encode($formatted);
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: update_podcast_voice
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'update_podcast_voice') {
		$podcast_id  = (int)($_POST['podcast_id']  ?? 0);
		$host_voice  = mysqli_real_escape_string($conn, trim($_POST['host_voice']  ?? ''));
		$guest_voice = mysqli_real_escape_string($conn, trim($_POST['guest_voice'] ?? $host_voice));
		$rate        = (float)($_POST['rate'] ?? 1.0);

		if (!$podcast_id) {
			echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		$ok = mysqli_query($conn, 
			"UPDATE hdb_podcasts
			 SET host_voice  = '$host_voice',
				 guest_voice = '$guest_voice',
				 voice_rate  = $rate,
				 team_lead_id = $team_lead_id,
				 updated_at  = NOW()
			 WHERE id = $podcast_id AND admin_id = $admin_id");

		wiz_log("update_podcast_voice: podcast=$podcast_id host=$host_voice guest=$guest_voice rate=$rate ok=".($ok?'1':'0'));
		echo json_encode(['success'=>(bool)$ok,'affected'=>mysqli_affected_rows($conn)]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: generate_image_api
	// Primary: Flux (Modal). Fallback: OpenAI gpt-image-1.
	// Assigns generated image to the correct slot + folder columns.
	// Also generates a resized thumbnail saved to podcast_thumbnails/.
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'generate_image_api') {
		$_t_gen = microtime(true);

		$scene_id        = (int)($_POST['scene_id']        ?? 0);
		$podcast_id      = (int)($_POST['podcast_id']      ?? 0);
		$enhanced_prompt = trim($_POST['enhanced_prompt']  ?? '');
		$hashtags        = trim($_POST['hashtags']         ?? '');
		$seq_no          = (int)($_POST['seq_no']          ?? 1);
		$image_field     = trim($_POST['image_field']      ?? 'image_file');

		// Validate image_field
		$allowed_fields = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
		if (!in_array($image_field, $allowed_fields)) $image_field = 'image_file';

		// Map slot → paired folder column
		$folder_field_map = [
			'image_file'   => 'image_folder',
			'image_file_1' => 'image_folder_1',
			'image_file_2' => 'image_folder_2',
			'image_file_3' => 'image_folder_3',
			'image_file_4' => 'image_folder_4',
		];
		$folder_field = $folder_field_map[$image_field] ?? 'image_folder';

		if (empty($enhanced_prompt)) {
			echo json_encode(['success' => false, 'error' => 'No prompt provided']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		$seq_padded = str_pad($seq_no, 3, '0', STR_PAD_LEFT);
		$imageName  = "generated_{$podcast_id}_{$seq_padded}";
		$folder     = 'podcast_images';

		// ── 1. Try Flux (Modal) first ─────────────────────────────────────────
		wiz_log("generate_image_api: scene=$scene_id slot=$image_field | trying Flux first");
		$result = generateAndSaveImageFlux($enhanced_prompt, $imageName, $folder);

		// ── 2. Fallback to OpenAI if Flux failed ──────────────────────────────
		if (!$result['success']) {
			wiz_log("generate_image_api: Flux failed ({$result['message']}) — falling back to OpenAI");
			$result = generateAndSaveImage($enhanced_prompt, $imageName, '1024x1536', $folder, $apiKey);
		}

		wiz_log("⏱ generate_image_api: provider=" . ($result['provider'] ?? '?') . " success=" . ($result['success'] ? '1' : '0') . " | " . wiz_sec($_t_gen));

		if (!$result['success']) {
			echo json_encode(['success' => false, 'error' => $result['message']]);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// ── 3. Persist metadata to hdb_image_data ────────────────────────────
		// Derive extension from the actual saved file — never assume .png
		$ext          = pathinfo($result['filepath'], PATHINFO_EXTENSION) ?: 'png';
		$filename     = $imageName . '.' . $ext;
		$esc_filename = mysqli_real_escape_string($conn, $filename);
		$esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
		$esc_prompt   = mysqli_real_escape_string($conn, $enhanced_prompt);
		$image_folder = 'podcast_images';

		mysqli_query($conn,
			"INSERT IGNORE INTO hdb_image_data
			     (image_name, image_hashtags, description, media_type, add_by, created_at)
			 VALUES
			     ('$esc_filename', '$esc_hashtags', '$esc_prompt', 'image', $admin_id, NOW())"
		);

		// ── 3b. Generate resized thumbnail via shared helper ──────────────────
		// generateThumbnail() lives in image_generation_functions.php —
		// one implementation used by wizard_step2, generate_image_api, and image_worker.
		$thumb         = generateThumbnail($result['filepath'], $imageName, $ext);
		$thumbFilename = $thumb['generated'] ? $thumb['filename'] : $filename; // fallback to main if failed
		$thumbFilepath = $thumb['filepath'];
		$esc_thumbname = mysqli_real_escape_string($conn, $thumbFilename);
		wiz_log("generate_image_api: thumbnail=" . $thumbFilepath . " generated=" . ($thumb['generated'] ? 'YES' : 'NO'));

		// ── 4. Assign to the correct scene slot + folder columns ──────────────
		// Reconnect to MySQL — Flux takes 200+ seconds and the connection may
		// have timed out (MySQL wait_timeout on shared hosting is often 120-300s)
		if ($scene_id > 0) {
			// Ping to check connection, reconnect if dead
			if (!mysqli_ping($conn)) {
				wiz_log("generate_image_api: DB connection lost — reconnecting");
				mysqli_close($conn);
				require __DIR__ . '/dbconnect_hdb.php';
				if (!$conn) {
					wiz_log("generate_image_api: DB reconnect FAILED — image saved but story not updated: $filename");
					echo json_encode(['success'=>true,'filename'=>$filename,'thumbnail'=>$thumbFilename,'provider'=>$result['provider'],'error'=>'DB reconnect failed']);
					if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
				}
				wiz_log("generate_image_api: DB reconnected OK");
				// Re-escape after reconnect
				$esc_filename  = mysqli_real_escape_string($conn, $filename);
				$esc_thumbname = mysqli_real_escape_string($conn, $thumbFilename);
				$esc_hashtags  = mysqli_real_escape_string($conn, $hashtags);
				$esc_prompt    = mysqli_real_escape_string($conn, $enhanced_prompt);
			}

			// Only update thumbnail column when writing to the primary image slot
			$thumb_sql = ($image_field === 'image_file') ? ", thumbnail='$esc_thumbname'" : '';
			$ok = mysqli_query($conn,
				"UPDATE hdb_podcast_stories
				 SET `$image_field`  = '$esc_filename',
				     image_folder    = '$image_folder',
				     `$folder_field` = '$image_folder'
				     $thumb_sql
				 WHERE id = $scene_id"
			);
			$rows = mysqli_affected_rows($conn);
			if (!$ok) {
				wiz_log("generate_image_api: UPDATE FAILED: " . mysqli_error($conn) . " | scene=$scene_id");
			} elseif ($rows === 0) {
				wiz_log("generate_image_api: UPDATE 0 rows — scene_id=$scene_id not found");
			} else {
				wiz_log("generate_image_api: scene updated OK | scene=$scene_id slot=$image_field folder=$folder_field file=$filename thumbnail=$thumbFilename rows=$rows");
			}
		}

		echo json_encode([
			'success'        => true,
			'filename'       => $filename,
			'thumbnail'      => $thumbFilename,
			'thumb_folder'   => $thumbFolder,
			'filepath'       => $result['filepath'],
			'thumb_filepath' => $thumbFilepath,
			'resolution'     => $result['resolution'],
			'image_field'    => $image_field,
			'folder_field'   => $folder_field,
			'provider'       => $result['provider'],
		]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: queue_image_generation
	// Queues AI image generation jobs for all scenes and returns immediately.
	// The actual generation is done by image_worker.php (cron every minute).
	// Frontend polls check_image_jobs to get progress.
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'queue_image_generation') {
		$podcast_id  = (int)($_POST['podcast_id'] ?? 0);
		$scenes_json = $_POST['scenes'] ?? '[]';
		$scenes      = json_decode($scenes_json, true) ?: [];

		if (!$podcast_id || empty($scenes)) {
			echo json_encode(['success' => false, 'error' => 'Missing podcast_id or scenes']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// Auto-create queue table if not exists
		mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_image_gen_queue (
			id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			podcast_id      INT UNSIGNED NOT NULL,
			scene_id        INT UNSIGNED NOT NULL,
			admin_id        INT UNSIGNED NOT NULL,
			seq_no          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			image_field     VARCHAR(20) NOT NULL DEFAULT 'image_file',
			prompt          TEXT NOT NULL,
			hashtags        VARCHAR(500) NOT NULL DEFAULT '',
			status          ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
			provider        VARCHAR(20) NULL COMMENT 'flux or openai — set after completion',
			filename        VARCHAR(255) NULL COMMENT 'set after completion',
			error_msg       VARCHAR(500) NULL,
			attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at      DATETIME NULL,
			completed_at    DATETIME NULL,
			INDEX idx_podcast  (podcast_id),
			INDEX idx_status   (status),
			INDEX idx_pending  (status, created_at)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// Clear any old jobs for this podcast
		mysqli_query($conn, "DELETE FROM hdb_image_gen_queue WHERE podcast_id=$podcast_id");

		// Insert one job per scene
		$inserted = 0;
		foreach ($scenes as $scene) {
			$scene_id   = (int)($scene['scene_id']    ?? 0);
			$seq_no     = (int)($scene['seq_no']      ?? 1);
			$img_field  = in_array($scene['image_field'] ?? '', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
						  ? $scene['image_field'] : 'image_file';
			$prompt     = mysqli_real_escape_string($conn, trim($scene['prompt']   ?? ''));
			$hashtags   = mysqli_real_escape_string($conn, trim($scene['hashtags'] ?? ''));

			if (!$scene_id || empty($prompt)) continue;

			mysqli_query($conn,
				"INSERT INTO hdb_image_gen_queue
				     (podcast_id, scene_id, admin_id, seq_no, image_field, prompt, hashtags)
				 VALUES
				     ($podcast_id, $scene_id, $admin_id, $seq_no, '$img_field', '$prompt', '$hashtags')"
			);
			$inserted++;
		}

		wiz_log("queue_image_generation: podcast=$podcast_id queued=$inserted scenes");

		echo json_encode([
			'success'    => true,
			'podcast_id' => $podcast_id,
			'queued'     => $inserted,
			'message'    => "$inserted image jobs queued — worker will process shortly",
		]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: check_image_jobs
	// Returns current status of all image generation jobs for a podcast.
	// Frontend polls this every 5 seconds to show progress.
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'check_image_jobs') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		if (!$podcast_id) {
			echo json_encode(['success' => false, 'error' => 'Missing podcast_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		$q = mysqli_query($conn,
			"SELECT id, scene_id, seq_no, image_field, status, provider, filename, error_msg, attempts
			 FROM hdb_image_gen_queue
			 WHERE podcast_id = $podcast_id
			 ORDER BY seq_no ASC"
		);

		$jobs     = [];
		$pending  = 0; $processing = 0; $done = 0; $failed = 0;
		while ($row = mysqli_fetch_assoc($q)) {
			$jobs[] = $row;
			switch ($row['status']) {
				case 'pending':    $pending++;    break;
				case 'processing': $processing++; break;
				case 'done':       $done++;       break;
				case 'failed':     $failed++;     break;
			}
		}

		$total    = count($jobs);
		$complete = ($total > 0 && ($pending + $processing) === 0);

		echo json_encode([
			'success'    => true,
			'podcast_id' => $podcast_id,
			'total'      => $total,
			'pending'    => $pending,
			'processing' => $processing,
			'done'       => $done,
			'failed'     => $failed,
			'complete'   => $complete,
			'jobs'       => $jobs,
		]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	// ══════════════════════════════════════════════════════════════════════════════
	// ACTION: update_video_duration
	// Sums real audio durations from hdb_podcast_stories and saves total to
	// hdb_podcasts.video_duration. Called once after all scene audio is generated.
	// ══════════════════════════════════════════════════════════════════════════════
	if ($action === 'update_video_duration') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		if (!$podcast_id) {
			echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// Ensure video_duration column exists (runs silently if already present)
		mysqli_query($conn,
			"ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS video_duration DECIMAL(8,2) DEFAULT NULL COMMENT 'Total video duration in seconds, summed from scene audio durations'");

		// Verify podcast belongs to this admin
		$chk = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT id FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1"));
		if (!$chk) {
			echo json_encode(['success'=>false,'error'=>'Podcast not found']);
			if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
		}

		// Sum all scene durations — only count scenes that have a real audio duration (> 0)
		$dur_q = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT COUNT(*) AS scene_count,
			        ROUND(SUM(CASE WHEN duration > 0 THEN duration ELSE 0 END), 2) AS total_duration
			 FROM hdb_podcast_stories
			 WHERE podcast_id = $podcast_id"));

		$total_duration = (float)($dur_q['total_duration'] ?? 0);
		$scene_count    = (int)($dur_q['scene_count']    ?? 0);

		if ($total_duration > 0) {
			mysqli_query($conn,
				"UPDATE hdb_podcasts
				 SET video_duration = $total_duration,
				     updated_at     = NOW()
				 WHERE id = $podcast_id");
			wiz_log("update_video_duration: podcast=$podcast_id total={$total_duration}s scenes=$scene_count");
		} else {
			wiz_log("update_video_duration: podcast=$podcast_id — no scene durations found yet");
		}

		echo json_encode([
			'success'        => true,
			'podcast_id'     => $podcast_id,
			'total_duration' => $total_duration,
			'scene_count'    => $scene_count,
		]);
		if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	}

	wiz_log("Unknown action: $action");
	echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
	if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
	?>
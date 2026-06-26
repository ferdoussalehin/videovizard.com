<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function logError($msg) {
    error_log(date("Y-m-d H:i:s") . " - [save_script_to_db] " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

if (!isset($_SESSION['admin_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
if ($company_id === 0) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Session expired or company not set.']);
    exit;
}

$admin_id  = (int)$_SESSION['admin_id'];
$client_id = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : $admin_id;

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$script = trim($body['script'] ?? '');
$data   = $body['data']   ?? [];

if (empty($script)) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'No script provided']);
    exit;
}

$niche     = '';   // deprecated column — no longer populated (was incorrectly sourced from $data['niche'], the video-idea niche picker, unrelated to company industry classification)
$title     = $data['title']     ?? '';
$lang_code = $data['language']  ?? 'en';
$reel_type = $data['reel_type'] ?? 'standard';
$category  = $data['topic']     ?? '';
$topic_key = '';   // deprecated column — no longer populated, see $niche above

// Real company taxonomy — Category/Subcategory (ai_group/ai_subgroup)
$ai_group    = trim($data['industry_group'] ?? $data['ai_group']    ?? '');
$ai_subgroup = trim($data['industry_desc']  ?? $data['ai_subgroup'] ?? '');

// Used only for scene-level image-search context (prompts/hashtags/NL tags below) —
// NOT written to the deprecated niche/topic_key columns.
$niche_ctx = $ai_subgroup ?: $ai_group;

$is_podcast      = stripos($reel_type, 'podcast')      !== false;
$is_broll        = stripos($reel_type, 'b-roll')       !== false || stripos($reel_type, 'broll') !== false;
$is_talking_head = stripos($reel_type, 'talking head') !== false;

$credit_cost = ($is_podcast || $is_talking_head) ? 2 : 1;

logError("Saving script — title=$title ai_group=$ai_group ai_subgroup=$ai_subgroup reel_type=$reel_type");

try {
    $conn = mysqli_connect("localhost", "user_inaamalvi1403", "AllahuAkbar786", "user_hypnotherapy_db2");
    if (!$conn) throw new Exception('DB connection failed: ' . mysqli_connect_error());
    mysqli_report(MYSQLI_REPORT_OFF);

    // Deduct credits
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $deduct_from = ($urow && $urow['role'] === 'Team Member' && (int)($urow['team_lead_id'] ?? 0) > 0)
        ? (int)$urow['team_lead_id'] : $admin_id;
    mysqli_query($conn,
        "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $credit_cost) WHERE id=$deduct_from");

    $now  = date('Y-m-d H:i:s');
    $n_e  = mysqli_real_escape_string($conn, $niche);
    $ti_e = mysqli_real_escape_string($conn, $title);
    $lc_e = mysqli_real_escape_string($conn, $lang_code);
    $rt_e = mysqli_real_escape_string($conn, $reel_type);
    $sc_e = mysqli_real_escape_string($conn, $script);
    $ca_e = mysqli_real_escape_string($conn, $category);
    $tk_e = mysqli_real_escape_string($conn, $topic_key);
    $ag_e = mysqli_real_escape_string($conn, $ai_group);
    $asg_e = mysqli_real_escape_string($conn, $ai_subgroup);

    // 1. Insert podcast row
    $sql1 = "INSERT INTO hdb_podcasts
                (company_id, client_id, admin_id, niche, title, lang_code,
                 video_type, script_text, created_date, updated_at,
                 category, topic_key, ai_group, ai_subgroup,
                 video_status, internal_status,
                 scene_seq_no, hook_id, hook_name,
                 schedule_date, schedule_time, publish_date, video_filename,
                 hashtags, keywords, caption_text,
                 facebook_status, tiktok_status, instagram_status,
                 youtube_status, twitter_status, linkedin_status,
                 logo_flag, thumbnail, video_format, video_media)
             VALUES
                ($company_id, $client_id, $admin_id, '$n_e', '$ti_e', '$lc_e',
                 '$rt_e', '$sc_e', '$now', '$now',
                 '$ca_e', '$tk_e', '$ag_e', '$asg_e',
                 'ready', 'new',
                 0, '', '',
                 '', '', '', '',
                 '', '', '',
                 'none','none','none',
                 'none','none','none',
                 0, '', 'vertical', 'stock')";

    if (!mysqli_query($conn, $sql1)) {
        throw new Exception('Insert failed (hdb_podcasts): ' . mysqli_error($conn));
    }
    $podcast_id = mysqli_insert_id($conn);
    if (!$podcast_id) throw new Exception('No podcast_id after insert');

    // 2. Load user caption settings
    $ff = 'Arial'; $fs = 28; $fc = '#ffffff'; $fw = 'bold';
    $bgc = '#000000'; $bge = 0; $cs = 'none'; $cspd = 1.0;
    $px = 20; $py = 300; $pw = 380;

    $us_q = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' LIMIT 1");
    if ($us_q && mysqli_num_rows($us_q) > 0) {
        $us   = mysqli_fetch_assoc($us_q);
        $ff   = $us['fontfamily']    ?? 'Arial';
        $fs   = intval($us['fontsize']      ?? 28);
        $fc   = $us['fontcolor']     ?? '#ffffff';
        $fw   = $us['fontweight']    ?? 'bold';
        $bgc  = $us['fontcolor_bg']  ?? '#000000';
        $bge  = intval($us['fontbg_enable'] ?? 0);
        $cs   = $us['caption_style'] ?? 'none';
        $cspd = floatval($us['caption_speed'] ?? 1.0);
        $px   = intval($us['position_x'] ?? 20);
        $py   = intval($us['position_y'] ?? 300);
        $pw   = intval($us['width']      ?? 380);
    }

    // 3. Insert scene rows
    $stop_words = ['the','and','for','you','your','with','that','this','are','can',
                   'will','have','from','they','what','about','more','just','into',
                   'over','after','were','been','has','its','not','but','all'];

    $scene_order = 1;
    $scenes = $is_broll
        ? [$script]
        : array_values(array_filter(array_map('trim', explode("\n", $script))));

    foreach ($scenes as $scene_text) {
        if (empty($scene_text)) continue;

        $clean_text = trim(preg_replace('/<break[^>]*>/i', '', $scene_text));

        $actor = 'host';
        if ($is_podcast) {
            if (stripos($clean_text, 'HOST:') === 0)  { $clean_text = trim(substr($clean_text, 5)); $actor = 'host'; }
            elseif (stripos($clean_text, 'GUEST:') === 0) { $clean_text = trim(substr($clean_text, 6)); $actor = 'guest'; }
        }
        if (empty($clean_text)) continue;

        $word_count = count(array_filter(explode(' ', $clean_text)));
        $scene_dur  = max(3, (int)round(($word_count / 130) * 60));

        $words    = preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $clean_text)));
        $keywords = array_values(array_filter($words, function($w) use ($stop_words) {
            return strlen($w) > 3 && !in_array($w, $stop_words);
        }));
        $tags = array_unique(array_merge(
            [$niche_ctx ? strtolower(preg_replace('/\s+/', '', $niche_ctx)) : 'general'],
            array_slice($keywords, 0, 4)
        ));
        $hashtags_str = implode(' ', $tags);
        $kw0     = $keywords[0] ?? $niche_ctx;
        $kw1     = $keywords[1] ?? 'concept';
        $nl_tags = implode('|', [
            $clean_text,
            ($niche_ctx ? $niche_ctx . ' professional' : 'professional'),
            $kw0 . ' lifestyle',
            $niche_ctx . ' ' . $kw1,
            'real life ' . ($niche_ctx ?: 'business'),
        ]);

        if ($is_podcast) {
            $actor_label = ucfirst($actor);
            $prompt_text = "Podcast studio. {$actor_label} speaking. Context: {$clean_text}. Professional lighting, microphone visible.";
        } elseif ($is_broll) {
            $prompt_text = "Cinematic B-Roll. Scene: {$clean_text}. Niche: {$niche_ctx}. Wide shot, documentary style.";
        } elseif ($is_talking_head) {
            $prompt_text = "Talking head. Speaker on camera. Context: {$clean_text}. Niche: {$niche_ctx}. Clean background.";
        } else {
            $prompt_text = "Photorealistic photo. Scene: {$clean_text}. Niche: {$niche_ctx}. Natural lighting, 35mm lens.";
        }

        $acte = mysqli_real_escape_string($conn, $actor);
        $te   = mysqli_real_escape_string($conn, $clean_text);  // clean — no break tags
        $ce   = mysqli_real_escape_string($conn, $clean_text);
        $pe   = mysqli_real_escape_string($conn, $prompt_text);
        $he   = mysqli_real_escape_string($conn, $hashtags_str);
        $ne   = mysqli_real_escape_string($conn, $nl_tags);
        $lce  = mysqli_real_escape_string($conn, $lang_code);
        $cate = mysqli_real_escape_string($conn, $category);
        $tke  = mysqli_real_escape_string($conn, $topic_key);
        $tite = mysqli_real_escape_string($conn, $title);

        // INSERT — uses image_file and image_folder, NO image_video
        $ins = "INSERT INTO hdb_podcast_stories
                    (company_id, podcast_id, lang_code, scene_order, category,
                     topic_key, title, actor, text_contents, text_display,
                     duration, prompt, status, audio_file,
                     image_file, image_folder, created_date,
                     seq_no, logo_flag, hashtags, natural_language_tags)
                 VALUES
                    ($company_id, $podcast_id, '$lce', $scene_order, '$cate',
                     '$tke', '$tite', '$acte', '$te', '$ce',
                     $scene_dur, '$pe', 'pending', '',
                     '', '', '$now',
                     $scene_order, 0, '$he', '$ne')";

        if (!mysqli_query($conn, $ins)) {
            $err = mysqli_error($conn);
            logError("Scene $scene_order FAIL: $err");
            // Fallback without natural_language_tags if column missing
            if (strpos($err, 'natural_language_tags') !== false) {
                $ins2 = "INSERT INTO hdb_podcast_stories
                            (company_id, podcast_id, lang_code, scene_order, category,
                             topic_key, title, actor, text_contents, text_display,
                             duration, prompt, status, audio_file,
                             image_file, image_folder, created_date,
                             seq_no, logo_flag, hashtags)
                         VALUES
                            ($company_id, $podcast_id, '$lce', $scene_order, '$cate',
                             '$tke', '$tite', '$acte', '$te', '$ce',
                             $scene_dur, '$pe', 'pending', '',
                             '', '', '$now',
                             $scene_order, 0, '$he')";
                if (!mysqli_query($conn, $ins2)) {
                    logError("Scene $scene_order fallback FAIL: " . mysqli_error($conn));
                    $scene_order++; continue;
                }
            } else {
                $scene_order++; continue;
            }
        }
        $story_id = mysqli_insert_id($conn);

        // Caption row
        $ff_esc  = mysqli_real_escape_string($conn, $ff);
        $fc_esc  = mysqli_real_escape_string($conn, $fc);
        $fw_esc  = mysqli_real_escape_string($conn, $fw);
        $bgc_esc = mysqli_real_escape_string($conn, $bgc);
        $cs_esc  = mysqli_real_escape_string($conn, $cs);

        $cap_ins = "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, 'text', 'main', '$ce',
             '$ff_esc', $fs, '$fc_esc', '$fw_esc', 'normal', 'center',
             '$bgc_esc', $bge, $px, $py, $pw, 0,
             '$cs_esc', $cspd, 1, 1)";
        if (!mysqli_query($conn, $cap_ins)) {
            logError("Caption $scene_order FAIL: " . mysqli_error($conn));
        }

        logError("Scene $scene_order OK story_id=$story_id");
        $scene_order++;
    }

    mysqli_close($conn);
    ob_clean();
    echo json_encode(['success' => true, 'podcast_id' => $podcast_id, 'scenes' => $scene_order - 1]);

} catch (Throwable $e) {
    logError('ERROR: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

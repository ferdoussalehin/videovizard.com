<?php
// ============================================================
// movie_gen_save.php  v2.0
// Saves cinematic AI script to hdb_podcasts, hdb_podcast_stories, hdb_captions
// Fixed: admin_id=34, company_id=50
// ============================================================
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

include __DIR__ . '/dbconnect_hdb.php';
include __DIR__ . '/config.php';

$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? null;

define('FIXED_ADMIN_ID',   34);
define('FIXED_COMPANY_ID', 50);
define('IMAGE_FOLDER',     'podcast_images');

function mg_log(string $msg): void {
    file_put_contents(__DIR__ . '/a_errors.log',
        '[' . date('Y-m-d H:i:s') . '] [movie_gen] ' . $msg . "\n", FILE_APPEND);
}

function json_out(array $d): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d);
    exit;
}

// ── Get user caption settings ─────────────────────────────────
function getMovieSettings($conn, $admin_id, $company_id) {
    $q = mysqli_query($conn,
        "SELECT * FROM hdb_user_settings
          WHERE admin_id='$admin_id' AND company_id='$company_id'
          ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
    if (!$q || mysqli_num_rows($q) === 0) {
        $q = mysqli_query($conn,
            "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id'
              ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
    }
    $settings = ['caption' => null, 'header' => null, 'footer' => null, 'logo' => null];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $t = $r['text_type'] ?? 'caption';
            if (array_key_exists($t, $settings)) $settings[$t] = $r;
        }
    }
    $def = [
        'fontfamily' => 'Arial', 'fontsize' => 28,
        'fontcolor' => '#ffff00', 'fontweight' => 'bold',
        'fontcolor_bg' => '#000000', 'fontbg_enable' => 0,
        'caption_position' => 'bottom', 'caption_alignment' => 'center',
        'position_x' => 50, 'position_y' => 250, 'width' => 500,
        '_anim_style' => 'none', '_anim_speed' => 1.0,
        '_text_fx' => 'none', '_text_fx_col' => '#ffffff',
    ];
    foreach (['caption', 'header', 'footer'] as $type) {
        if ($settings[$type]) {
            $merged = $def;
            foreach ($settings[$type] as $k => $v) { if ($v !== null) $merged[$k] = $v; }
            $merged['_anim_style'] = $settings[$type]['text_animation']    ?? 'none';
            $spd = $settings[$type]['animation_speed'] ?? 1.0;
            $merged['_anim_speed'] = is_numeric($spd) ? (float)$spd : 1.0;
            $merged['_text_fx']    = $settings[$type]['text_effect']       ?? 'none';
            $merged['_text_fx_col']= $settings[$type]['text_effect_color'] ?? '#ffffff';
            $settings[$type]       = $merged;
        } else {
            $settings[$type] = $def;
        }
    }
    return $settings;
}

// ── Insert caption row for a story ────────────────────────────
function buildMovieCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings) {
    $cap = $user_settings['caption'];
    $ff  = mysqli_real_escape_string($conn, $cap['fontfamily']      ?? 'Arial');
    $fs  = (int)($cap['fontsize']            ?? 28);
    $fc  = mysqli_real_escape_string($conn, $cap['fontcolor']       ?? '#ffff00');
    $fw  = mysqli_real_escape_string($conn, $cap['fontweight']      ?? 'bold');
    $bgc = mysqli_real_escape_string($conn, $cap['fontcolor_bg']    ?? '#000000');
    $bge = (int)($cap['fontbg_enable']       ?? 0);
    $pos = mysqli_real_escape_string($conn, $cap['caption_position']?? 'bottom');
    $aln = mysqli_real_escape_string($conn, $cap['caption_alignment']?? 'center');
    $px  = (int)($cap['position_x']  ?? 50);
    $py  = (int)($cap['position_y']  ?? 250);
    $pw  = (int)($cap['width']       ?? 500);
    $ast = mysqli_real_escape_string($conn, $cap['_anim_style']     ?? 'none');
    $asp = (float)($cap['_anim_speed']       ?? 1.0);
    $tfx = mysqli_real_escape_string($conn, $cap['_text_fx']        ?? 'none');
    $te  = mysqli_real_escape_string($conn, $text);

    $sql = "INSERT INTO hdb_captions
        (podcast_id, story_id, cap_type, cap_name, text_contents,
         fontfamily, fontsize, fontcolor, fontweight, fontstyle,
         bg_color, bg_enabled, position, text_align, position_x, position_y, width,
         animation_style, animation_speed, text_effects,
         z_index, status, created_at)
        VALUES
        ($podcast_id, $story_id, 'caption', 'Main Caption', '$te',
         '$ff', $fs, '$fc', '$fw', 'normal',
         '$bgc', $bge, '$pos', '$aln', $px, $py, $pw,
         '$ast', $asp, '$tfx',
         10, 'active', NOW())";

    if (!mysqli_query($conn, $sql)) {
        mg_log("buildMovieCaptionRows FAIL story=$story_id: " . mysqli_error($conn));
    }
}

// ── AJAX: fetch languages ──────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_languages') {
    $rows = [];
    $r = mysqli_query($conn, "SELECT lang_code, lang_name FROM hdb_languages ORDER BY lang_name ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    json_out(['success' => true, 'languages' => $rows]);
}

// ── AJAX: save movie script to DB ─────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_movie') {

    $admin_id   = FIXED_ADMIN_ID;
    $company_id = FIXED_COMPANY_ID;

    // ── Collect all inputs ─────────────────────────────────────
    $niche        = trim($_POST['niche']        ?? $_POST['business'] ?? '');
    $video_idea   = trim($_POST['video_idea']   ?? $niche);           // = title
    $lang_code    = trim($_POST['lang_code']    ?? 'en');
    $story        = trim($_POST['story']        ?? '');               // core story paragraph
    $script       = trim($_POST['script']       ?? '');               // full script text
    $character    = trim($_POST['character']    ?? '');               // actor
    $audio_mood   = trim($_POST['audio_mood']   ?? '');
    $sentiment    = trim($_POST['sentiment']    ?? '');
    $setting      = trim($_POST['setting']      ?? '');
    $background   = trim($_POST['background']   ?? '');
    $hero_element = trim($_POST['hero_element'] ?? '');
    $additional   = trim($_POST['additional']   ?? '');
    $scenes_json  = $_POST['scenes']            ?? '[]';
    $scenes       = json_decode($scenes_json, true) ?: [];
    $scene_images = json_decode($_POST['scene_images'] ?? '{}', true) ?: [];

    if (empty($scenes)) {
        json_out(['success' => false, 'error' => 'No scenes provided — please parse the script first']);
    }

    $lang_code = mysqli_real_escape_string($conn, $lang_code);

    $user_settings = getMovieSettings($conn, $admin_id, $company_id);

    // Build hashtags from niche words
    $ht_arr = [];
    $words  = explode(' ', strtolower(preg_replace('/[^a-zA-Z\s]/', '', $niche)));
    foreach (array_slice(array_unique(array_filter($words)), 0, 5) as $w) {
        if (strlen($w) > 3) $ht_arr[] = '#' . $w;
    }
    $hashtags = mysqli_real_escape_string($conn, implode(', ', $ht_arr));
    $keywords = mysqli_real_escape_string($conn, implode(', ', array_unique(array_filter($words))));
    $today    = date('Y-m-d');

    $e_video_idea  = mysqli_real_escape_string($conn, $video_idea);
    $e_niche       = mysqli_real_escape_string($conn, $niche);
    $e_story       = mysqli_real_escape_string($conn, $story);        // caption_text = story idea
    $e_script      = mysqli_real_escape_string($conn, $script);
    $e_mood        = mysqli_real_escape_string($conn, $audio_mood);
    $e_sentiment   = mysqli_real_escape_string($conn, $sentiment);
    $e_setting     = mysqli_real_escape_string($conn, $setting);
    $e_background  = mysqli_real_escape_string($conn, $background);
    $e_character   = mysqli_real_escape_string($conn, $character);
    $e_hero        = mysqli_real_escape_string($conn, $hero_element);

    // ── INSERT hdb_podcasts ────────────────────────────────────
    // Rules:
    // internal_status = 'scenes_ready'
    // video_type      = 'cinematic'
    // category        = niche
    // title           = video_idea
    // schedule_date/time = 0000-00-00 / 00:00
    // publish_date/time  = 0000-00-00 / 00:00
    // caption_text    = story idea
    // all platform statuses = 'none'
    // niche           = niche

    $sql_pod = "INSERT INTO hdb_podcasts
        (admin_id, team_lead_id, company_id,
         title, lang_code,
         video_type, video_status, internal_status,
         created_date, updated_at,
         niche, category, topic_key,
         hashtags, keywords,
         host_voice, guest_voice, voice_rate,
         is_campaign, logo_flag,
         facebook_status, tiktok_status, instagram_status,
         youtube_status, twitter_status, linkedin_status,
         schedule_date, schedule_time,
         publish_date, publish_time,
         video_format, video_media, music_file, hook_name,
         caption_text, script_text)
        VALUES
        ($admin_id, $admin_id, $company_id,
         '$e_video_idea', '$lang_code',
         'cinematic', 'draft', 'scenes_ready',
         '$today', NOW(),
         '$e_niche', '$e_niche', 'movie',
         '$hashtags', '$keywords',
         '', '', 1.0,
         0, 0,
         'none', 'none', 'none',
         'none', 'none', 'none',
         '0000-00-00', '00:00:00',
         '0000-00-00', '00:00:00',
         'vertical', 'video', '$e_mood', '$e_sentiment',
         '$e_story', '$e_script')";

    if (!mysqli_query($conn, $sql_pod)) {
        $err = mysqli_error($conn);
        mg_log("hdb_podcasts INSERT FAIL: $err");
        // Try fallback without publish_time if column doesn't exist
        if (strpos($err, 'publish_time') !== false) {
            $sql_pod2 = "INSERT INTO hdb_podcasts
                (admin_id, team_lead_id, company_id,
                 title, lang_code,
                 video_type, video_status, internal_status,
                 created_date, updated_at,
                 niche, category, topic_key,
                 hashtags, keywords,
                 host_voice, guest_voice, voice_rate,
                 is_campaign, logo_flag,
                 facebook_status, tiktok_status, instagram_status,
                 youtube_status, twitter_status, linkedin_status,
                 schedule_date, schedule_time, publish_date,
                 video_format, video_media, music_file, hook_name,
                 caption_text, script_text)
                VALUES
                ($admin_id, $admin_id, $company_id,
                 '$e_video_idea', '$lang_code',
                 'cinematic', 'draft', 'scenes_ready',
                 '$today', NOW(),
                 '$e_niche', '$e_niche', 'movie',
                 '$hashtags', '$keywords',
                 '', '', 1.0,
                 0, 0,
                 'none', 'none', 'none',
                 'none', 'none', 'none',
                 '0000-00-00', '00:00:00', '0000-00-00',
                 'vertical', 'video', '$e_mood', '$e_sentiment',
                 '$e_story', '$e_script')";
            if (!mysqli_query($conn, $sql_pod2)) {
                json_out(['success' => false, 'error' => 'Failed to create podcast: ' . mysqli_error($conn)]);
            }
        } else {
            json_out(['success' => false, 'error' => 'Failed to create podcast: ' . $err]);
        }
    }
    $podcast_id = (int)mysqli_insert_id($conn);
    mg_log("podcast inserted id=$podcast_id video_idea='$video_idea' niche='$niche' lang=$lang_code");

    // ── INSERT each scene into hdb_podcast_stories ─────────────
    // Rules:
    // text_contents   = caption (on-screen text)
    // text_display    = caption
    // duration        = scene duration max 5 sec
    // actor           = character from input
    // category        = niche
    // prompt          = image prompt (enhanced prompt)
    // video_prompt    = WAN 2.2 prompt
    // image_file      = image_{podcast_id}_{story_id}.png
    // image_folder    = 'podcast_images'

    $scene_count  = 0;
    $story_id_map = []; // seq_no => story_id

    foreach ($scenes as $i => $scene) {
        $seq_no      = (int)($scene['num']     ?? ($i + 1));
        $wan_prompt  = trim($scene['wan']      ?? '');               // WAN 2.2 video_prompt
        $caption     = trim($scene['caption']  ?? '');               // on-screen text
        $scene_title = trim($scene['title']    ?? ('Scene ' . $seq_no));

        // image prompt = enhanced prompt if available, else wan_prompt
        $img_prompt  = trim($scene['enhanced'] ?? $wan_prompt);

        if (empty($wan_prompt))  $wan_prompt  = $scene_title;
        if (empty($caption))     $caption     = $scene_title;
        if (empty($img_prompt))  $img_prompt  = $wan_prompt;

        // Duration max 5 sec
        $duration = 5;

        $e_wan      = mysqli_real_escape_string($conn, $wan_prompt);
        $e_caption  = mysqli_real_escape_string($conn, $caption);
        $e_stitle   = mysqli_real_escape_string($conn, $scene_title);
        $e_char     = mysqli_real_escape_string($conn, $character);
        $e_niche_s  = mysqli_real_escape_string($conn, $niche);
        $e_img_p    = mysqli_real_escape_string($conn, $img_prompt);
        $e_folder   = 'podcast_images';

        // Step 1: Insert row (image_file filled after we get story_id)
        $sql_story = "INSERT INTO hdb_podcast_stories
            (podcast_id, lang_code, category, topic_key, title, actor,
             text_contents, text_display, duration,
             prompt, video_prompt, visual_type,
             status, created_date, seq_no, logo_flag,
             hashtags, natural_language_tags,
             image_folder,
             voice_id, voice_rate)
            VALUES
            ($podcast_id, '$lang_code', '$e_niche_s', 'movie', '$e_stitle', '$e_char',
             '$e_caption', '$e_caption', $duration,
             '$e_img_p', '$e_wan', 'image',
             'PENDING', NOW(), $seq_no, 0,
             '$hashtags', '$e_wan',
             '$e_folder',
             '', 1.0)";

        $ok = mysqli_query($conn, $sql_story);
        $db_err = mysqli_error($conn);

        // Fallback: try without optional columns that may not exist
        if (!$ok) {
            $fallback_cols   = 'natural_language_tags,image_folder';
            $try_without_nlp = strpos($db_err, 'natural_language_tags') !== false;
            $try_without_fol = strpos($db_err, 'image_folder') !== false;

            if ($try_without_nlp || $try_without_fol) {
                $sql_story = "INSERT INTO hdb_podcast_stories
                    (podcast_id, lang_code, category, topic_key, title, actor,
                     text_contents, text_display, duration,
                     prompt, video_prompt, visual_type,
                     status, created_date, seq_no, logo_flag,
                     hashtags, voice_id, voice_rate)
                    VALUES
                    ($podcast_id, '$lang_code', '$e_niche_s', 'movie', '$e_stitle', '$e_char',
                     '$e_caption', '$e_caption', $duration,
                     '$e_img_p', '$e_wan', 'image',
                     'PENDING', NOW(), $seq_no, 0,
                     '$hashtags', '', 1.0)";
                $ok = mysqli_query($conn, $sql_story);
                $db_err = mysqli_error($conn);
            }
        }

        if ($ok) {
            $story_id = (int)mysqli_insert_id($conn);

            // Step 2: Build image_file = image_{podcast_id}_{story_id}.png
            $image_name = "image_{$podcast_id}_{$story_id}.png";
            $e_img_name = mysqli_real_escape_string($conn, $image_name);

            // Rename temp image to image_{podcast_id}_{story_id}.png in podcast_images/
            $temp_path = $scene_images[$seq_no] ?? ($scene_images[(string)$seq_no] ?? '');
            $dest_dir  = __DIR__ . '/' . IMAGE_FOLDER . '/';
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
            $dest_abs  = $dest_dir . $image_name;

            if ($temp_path) {
                // Could be a relative path like podcast_images/scenes/temp_scene_xxx.png
                // or an absolute server path
                $src_abs = $temp_path;
                if (!file_exists($src_abs)) {
                    // Try as relative to __DIR__
                    $src_abs = __DIR__ . '/' . ltrim($temp_path, '/');
                }
                if (!file_exists($src_abs)) {
                    // Try stripping URL host if present
                    $src_path = parse_url($temp_path, PHP_URL_PATH);
                    $src_abs  = __DIR__ . '/' . ltrim($src_path ?? '', '/');
                }
                if (file_exists($src_abs)) {
                    rename($src_abs, $dest_abs);
                    mg_log("scene $seq_no: renamed $src_abs -> $dest_abs");
                } else {
                    mg_log("scene $seq_no: temp image not found at $src_abs");
                }
            } else {
                mg_log("scene $seq_no: no temp image path provided");
            }

            // Update image_file and image_folder on story row
            mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                    SET image_file='$e_img_name', image_folder='podcast_images'
                  WHERE id=$story_id");

            // Insert caption row
            buildMovieCaptionRows($conn, $podcast_id, $story_id, $caption, $user_settings);

            $story_id_map[$seq_no] = $story_id;
            $scene_count++;
            mg_log("scene $seq_no ok story_id=$story_id image=$image_name");
        } else {
            mg_log("scene $seq_no INSERT FAIL: $db_err");
        }
    }

    mg_log("save_movie done podcast=$podcast_id scenes=$scene_count/" . count($scenes));

    json_out([
        'success'     => true,
        'podcast_id'  => $podcast_id,
        'scene_count' => $scene_count,
        'story_ids'   => $story_id_map,   // scene_num => story_id for image naming
        'message'     => "$scene_count of " . count($scenes) . " scenes saved",
    ]);
}

json_out(['success' => false, 'error' => 'Unknown action']);

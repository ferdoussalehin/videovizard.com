<?php
/**
 * wizard_ai_clone_step2.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Backend for the "Let AI Create Content For Me" (AI Clone) wizard.
 *
 * Strategy: define WIZARD_INCLUDED before including wizard_step2.php.
 * wizard_step2.php checks this flag at the end of every action block:
 *     if (defined('WIZARD_INCLUDED') && WIZARD_INCLUDED) return; else exit;
 * So it runs its setup code (session, DB, buildCaptionRows, getUserSettingsWiz,
 * generateVoiceOpenAI_wiz, getMp3DurationSeconds) but returns instead of
 * exiting, giving control back here for clone_ actions.
 */

// ── Tell wizard_step2.php not to exit after its own actions ──────────────────
define('WIZARD_INCLUDED', true);

// ── Capture everything wizard_step2.php outputs so we can discard it ─────────
// wizard_step2.php outputs {"success":false,"error":"Unknown action: clone_xxx"}
// for any clone_ action — we throw that away and output our own clean response.
ob_start();
require_once __DIR__ . '/wizard_step2.php';
ob_end_clean(); // discard wizard_step2 output — we send our own response below

// Ensure clean JSON header for our response
if (!headers_sent()) header('Content-Type: application/json');

function clone_log($msg) {
    error_log('[clone_step2] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_create_scenes
// Split script → hdb_podcast_stories + real buildCaptionRows()
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_create_scenes') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $host_voice = trim($_POST['host_voice'] ?? '');
    $voice_rate = (float)($_POST['rate'] ?? 1.0);
    $lang_code  = mysqli_real_escape_string($conn, trim($_POST['lang_code'] ?? 'en'));

    if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }

    $pod = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1"));
    if (!$pod) { echo json_encode(['success'=>false,'error'=>'Podcast not found']); exit; }

    $script_text = trim($pod['script_text'] ?? '');
    $title       = $pod['title']    ?? '';
    $niche       = $pod['niche']    ?? '';
    $category    = $pod['category'] ?? 'free-format';

    if (!$script_text) { echo json_encode(['success'=>false,'error'=>'No script_text in podcast']); exit; }

    // Clean slate
    mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id=$podcast_id");
    mysqli_query($conn, "DELETE FROM hdb_captions         WHERE podcast_id=$podcast_id");

    $hv = mysqli_real_escape_string($conn, $host_voice);
    mysqli_query($conn,
        "UPDATE hdb_podcasts SET host_voice='$hv', voice_rate=$voice_rate,
         lang_code='$lang_code', internal_status='processing', updated_at=NOW()
         WHERE id=$podcast_id");

    // Real getUserSettingsWiz() from wizard_step2.php
    $user_settings = getUserSettingsWiz($conn, $admin_id);

    // Split script
    if (strpos($script_text, '[SCENE BREAK]') !== false) {
        $lines = array_values(array_filter(array_map('trim',
            preg_split('/\[SCENE BREAK\]/i', $script_text))));
    } else {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $script_text))));
    }

    if (empty($lines)) { echo json_encode(['success'=>false,'error'=>'No lines after split']); exit; }

    $stopWords = ['the','and','for','you','your','with','that','this','are','can','will',
                  'have','from','they','what','about','more','just','into','over','after'];
    $et = mysqli_real_escape_string($conn, $title);
    $ec = mysqli_real_escape_string($conn, $category);
    $success = 0;

    foreach ($lines as $i => $line) {
        $text = trim(preg_replace('/<break[^>]*>/i', '', $line));
        if (!$text) continue;

        $seq_no     = $i + 1;
        $word_count = count(array_filter(explode(' ', preg_replace('/<[^>]*>/', '', $text))));
        $duration   = max(3, (int)round(($word_count / 130) * 60));

        $words    = preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $text)));
        $kws      = array_values(array_filter($words, function($w) use ($stopWords) {
            return strlen($w) > 3 && !in_array($w, $stopWords);
        }));
        $tags     = array_unique(array_merge(
            [$niche ? strtolower(preg_replace('/\s+/', '', $niche)) : 'general'],
            array_slice($kws, 0, 4)
        ));
        $hashtags = implode(' ', $tags);
        $nl_tags  = implode('|', [
            $text,
            ($niche ? $niche.' professional' : 'professional'),
            (!empty($kws[0]) ? $kws[0].' lifestyle'    : 'lifestyle'),
            (!empty($kws[1]) ? $niche.' '.$kws[1]      : $niche.' concept'),
            'real life '.($niche ?: 'business'),
        ]);
        $prompt = "Photorealistic documentary-style photograph. Scene: {$text} Niche: {$niche}. "
                . "Natural lighting, candid composition, 35mm lens, shallow depth of field.";

        $disp = substr($text, 0, 50).(strlen($text) > 50 ? '...' : '');
        $te2  = mysqli_real_escape_string($conn, $text);
        $de   = mysqli_real_escape_string($conn, $disp);
        $pe   = mysqli_real_escape_string($conn, $prompt);
        $he   = mysqli_real_escape_string($conn, $hashtags);
        $ne   = mysqli_real_escape_string($conn, $nl_tags);
        $ve   = mysqli_real_escape_string($conn, $host_voice);

        $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt, visual_type,
                 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
                 voice_id, voice_rate)
                VALUES
                ($podcast_id, '$lang_code', '$ec', 'general', '$et', 'host',
                 '$te2', '$de', $duration, '$pe', 'image',
                 'PENDING', NOW(), $seq_no, 0, '$he', '$ne', '$ve', $voice_rate)";

        if (mysqli_query($conn, $ins)) {
            $story_id = mysqli_insert_id($conn);
            // Real buildCaptionRows() from wizard_step2.php — handles caption/header/footer/logo
            buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, false);
            $success++;
        } else {
            // Fallback without natural_language_tags (older schema)
            if (mysqli_errno($conn) == 1054 && strpos(mysqli_error($conn), 'natural_language_tags') !== false) {
                $ins2 = "INSERT INTO hdb_podcast_stories
                        (podcast_id, lang_code, category, topic_key, title, actor,
                         text_contents, text_display, duration, prompt, visual_type,
                         status, created_date, seq_no, logo_flag, hashtags, voice_id, voice_rate)
                        VALUES
                        ($podcast_id, '$lang_code', '$ec', 'general', '$et', 'host',
                         '$te2', '$de', $duration, '$pe', 'image',
                         'PENDING', NOW(), $seq_no, 0, '$he', '$ve', $voice_rate)";
                if (mysqli_query($conn, $ins2)) {
                    $story_id = mysqli_insert_id($conn);
                    buildCaptionRows($conn, $podcast_id, $story_id, $text, $user_settings, false);
                    $success++;
                } else {
                    clone_log("scene $seq_no fallback FAIL: ".mysqli_error($conn));
                }
            } else {
                clone_log("scene $seq_no INSERT FAIL: ".mysqli_error($conn));
            }
        }
    }

    mysqli_query($conn,
        "UPDATE hdb_podcasts SET internal_status='scenes_ready', updated_at=NOW()
         WHERE id=$podcast_id");

    clone_log("clone_create_scenes: $success scenes for podcast=$podcast_id");
    echo json_encode(['success'=>true,'scene_count'=>$success,'podcast_id'=>$podcast_id]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_get_scenes
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_get_scenes') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) { echo json_encode([]); exit; }
    $q = mysqli_query($conn,
        "SELECT s.* FROM hdb_podcast_stories s
         JOIN hdb_podcasts p ON p.id = s.podcast_id
         WHERE s.podcast_id=$podcast_id AND p.admin_id=$admin_id
         ORDER BY s.seq_no ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode($rows);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_update_voice
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_update_voice') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $host_voice = mysqli_real_escape_string($conn, trim($_POST['host_voice'] ?? ''));
    $rate       = (float)($_POST['rate'] ?? 1.0);
    if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }
    mysqli_query($conn,
        "UPDATE hdb_podcasts SET host_voice='$host_voice', voice_rate=$rate, updated_at=NOW()
         WHERE id=$podcast_id AND admin_id=$admin_id");
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET voice_id='$host_voice', voice_rate=$rate
         WHERE podcast_id=$podcast_id");
    echo json_encode(['success'=>true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_generate_audio
// Uses real generateVoiceOpenAI_wiz() + getMp3DurationSeconds() from wizard_step2.php
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_generate_audio') {
    $scene_id   = (int)($_POST['scene_id']   ?? 0);
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $lang_code  = preg_replace('/[^a-z]/', '', strtolower(trim($_POST['lang_code'] ?? 'en')));
    $voice_id   = trim($_POST['voice_id']    ?? '');
    $rate       = trim($_POST['rate']        ?? '1.0');
    $text       = trim($_POST['text']        ?? '');

    if (!$scene_id || !$podcast_id || !$text || !$voice_id) {
        echo json_encode(['success'=>false,'error'=>'Missing params']); exit;
    }

    $audio_dir = __DIR__ . '/podcast_audios/';
    if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);
    $filename = "voice_{$podcast_id}_{$scene_id}_{$lang_code}.mp3";
    $filepath = $audio_dir . $filename;

    // Real TTS — same logic as wizard_step2 generate_scene_audio
    if (strpos($voice_id, 'openai:') === 0) {
        $result = generateVoiceOpenAI_wiz($text, $voice_id, $filepath);
    } else {
        if (file_exists(__DIR__.'/chatgpt_functions.php')) {
            require_once __DIR__.'/chatgpt_functions.php';
            $result = generateVoice($text, $voice_id, $rate, $filepath);
        } else {
            $result = ['success'=>false,'error'=>'Azure TTS: chatgpt_functions.php not found'];
        }
    }

    if (!$result['success']) { echo json_encode(['success'=>false,'error'=>$result['error']]); exit; }

    // Real MP3 duration from wizard_step2.php
    $real_duration = getMp3DurationSeconds($filepath);
    $dur_sql       = ($real_duration !== null) ? ", duration=$real_duration" : '';
    $fn = mysqli_real_escape_string($conn, $filename);
    $ve = mysqli_real_escape_string($conn, $voice_id);
    $re = mysqli_real_escape_string($conn, $rate);
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories SET audio_file='$fn', voice_id='$ve', voice_rate='$re'$dur_sql
         WHERE id=$scene_id AND podcast_id=$podcast_id");

    clone_log("clone_generate_audio: scene=$scene_id file=$filename dur=".($real_duration ?? 'n/a')."s");
    echo json_encode(['success'=>true,'audio_file'=>$filename,'duration'=>$real_duration]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_copy_media_from_source
// Positionally copies image slots from source podcast to new podcast.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_copy_media_from_source') {
    $new_podcast_id    = (int)($_POST['new_podcast_id']    ?? 0);
    $source_podcast_id = (int)($_POST['source_podcast_id'] ?? 0);

    if (!$new_podcast_id || !$source_podcast_id) {
        echo json_encode(['success'=>false,'error'=>'Missing podcast IDs']); exit;
    }

    $check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_podcasts WHERE id=$new_podcast_id AND admin_id=$admin_id LIMIT 1"));
    if (!$check) { echo json_encode(['success'=>false,'error'=>'Access denied']); exit; }

    $qNew = mysqli_query($conn,
        "SELECT id, seq_no FROM hdb_podcast_stories
         WHERE podcast_id=$new_podcast_id ORDER BY seq_no ASC");
    $newScenes = [];
    while ($r = mysqli_fetch_assoc($qNew)) $newScenes[] = $r;

    $qSrc = mysqli_query($conn,
        "SELECT seq_no, image_file, image_file_1, image_file_2, image_file_3, image_file_4
         FROM hdb_podcast_stories
         WHERE podcast_id=$source_podcast_id ORDER BY seq_no ASC");
    $srcScenes = [];
    while ($r = mysqli_fetch_assoc($qSrc)) $srcScenes[] = $r;

    $IMAGE_FIELDS = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    $results = [];

    foreach ($newScenes as $idx => $ns) {
        $scene_id = (int)$ns['id'];
        if (isset($srcScenes[$idx])) {
            $src = $srcScenes[$idx];
            $setClauses = [];
            $copied = 0;
            foreach ($IMAGE_FIELDS as $f) {
                if (!empty($src[$f])) {
                    $val = mysqli_real_escape_string($conn, $src[$f]);
                    $setClauses[] = "`$f`='$val'";
                    $copied++;
                }
            }
            if ($setClauses) {
                mysqli_query($conn,
                    "UPDATE hdb_podcast_stories SET ".implode(', ', $setClauses).
                    " WHERE id=$scene_id AND podcast_id=$new_podcast_id");
            }
            $results[] = ['scene_id'=>$scene_id,'seq_no'=>(int)$ns['seq_no'],'copied'=>$copied,'has_source'=>true];
        } else {
            $results[] = ['scene_id'=>$scene_id,'seq_no'=>(int)$ns['seq_no'],'copied'=>0,'has_source'=>false];
        }
    }

    clone_log("clone_copy_media: new=$new_podcast_id src=$source_podcast_id scenes=".count($newScenes));
    echo json_encode(['success'=>true,'results'=>$results]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_assign_image
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_assign_image') {
    $scene_id    = (int)($_POST['scene_id']   ?? 0);
    $podcast_id  = (int)($_POST['podcast_id'] ?? 0);
    $filename    = mysqli_real_escape_string($conn, trim($_POST['filename']    ?? ''));
    $image_field = mysqli_real_escape_string($conn, trim($_POST['image_field'] ?? 'image_file'));

    $allowed = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    if (!in_array($image_field, $allowed)) $image_field = 'image_file';
    if (!$scene_id || !$filename) { echo json_encode(['success'=>false,'error'=>'Missing params']); exit; }

    $thumb = '';
    $tq = mysqli_query($conn, "SELECT thumbnail FROM hdb_image_data WHERE image_name='$filename' LIMIT 1");
    if ($tq && $tr = mysqli_fetch_assoc($tq)) $thumb = mysqli_real_escape_string($conn, trim($tr['thumbnail'] ?? ''));

    if ($image_field === 'image_file' && !empty($thumb)) {
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$image_field`='$filename', thumbnail='$thumb'
             WHERE id=$scene_id AND podcast_id=$podcast_id");
    } else {
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$image_field`='$filename'
             WHERE id=$scene_id AND podcast_id=$podcast_id");
    }
    echo json_encode(['success'=>(bool)$ok,'thumbnail'=>$thumb]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_search_images_batch  (stock fallback for extra scenes)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_search_images_batch') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $slots      = max(1, min(10, (int)($_POST['slots'] ?? 5)));
    $scenes     = json_decode(trim($_POST['scenes'] ?? '[]'), true) ?: [];

    if (!$podcast_id || empty($scenes)) {
        echo json_encode(['success'=>false,'error'=>'Missing params']); exit;
    }

    $results = [];
    foreach ($scenes as $scene) {
        $scene_idx = (int)($scene['scene_idx'] ?? 0);
        $scene_id  = (int)($scene['scene_id']  ?? 0);
        $query     = trim($scene['query']       ?? '');
        if (!$query) { $results[] = ['scene_idx'=>$scene_idx,'scene_id'=>$scene_id,'found'=>[]]; continue; }

        $found = [];
        if (function_exists('searchAssets')) {
            $matches = searchAssets($conn, $query, $apiKey, $podcast_id, '', false, 0, $slots * 2, 0.28);
            foreach ($matches as $rank => $m) {
                if (count($found) >= $slots) break;
                $found[] = ['filename'=>$m['filename'],'score'=>$m['score'],'rank'=>$rank+1,
                            'matched_terms'=>!empty($m['matched_terms'])?json_encode($m['matched_terms']):''];
            }
        } else {
            $eq    = mysqli_real_escape_string($conn, $query);
            $terms = array_filter(array_map('trim', explode('|', $query)));
            $like  = [];
            foreach (array_slice($terms, 0, 6) as $t) {
                $et = mysqli_real_escape_string($conn, $t);
                $like[] = "(natural_language_tags LIKE '%$et%' OR hashtags LIKE '%$et%')";
            }
            $where = $like ? '('.implode(' OR ', $like).')' : "hashtags LIKE '%$eq%'";
            $q = mysqli_query($conn, "SELECT image_file FROM hdb_images WHERE $where ORDER BY RAND() LIMIT $slots");
            if ($q) while ($r = mysqli_fetch_assoc($q))
                $found[] = ['filename'=>$r['image_file'],'score'=>0.8,'rank'=>count($found)+1,'matched_terms'=>''];
        }
        $results[] = ['scene_idx'=>$scene_idx,'scene_id'=>$scene_id,'found'=>$found];
    }
    echo json_encode(['success'=>true,'results'=>$results]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: clone_get_thumbnail
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'clone_get_thumbnail') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) { echo json_encode(['success'=>false]); exit; }

    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT image_file FROM hdb_podcast_stories
         WHERE podcast_id=$podcast_id AND image_file != '' AND image_file IS NOT NULL
         ORDER BY seq_no ASC LIMIT 1"));

    if ($row && $row['image_file']) {
        $fn  = mysqli_real_escape_string($conn, $row['image_file']);
        $tq  = mysqli_query($conn, "SELECT thumbnail FROM hdb_image_data WHERE image_name='$fn' LIMIT 1");
        $thumb = $row['image_file'];
        if ($tq && $tr = mysqli_fetch_assoc($tq) && !empty($tr['thumbnail'])) $thumb = $tr['thumbnail'];
        $et = mysqli_real_escape_string($conn, $thumb);
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET thumbnail='$et', updated_at=NOW()
             WHERE id=$podcast_id AND admin_id=$admin_id");
        echo json_encode(['success'=>true,'thumbnail'=>$thumb]);
    } else {
        echo json_encode(['success'=>false,'error'=>'No image found']);
    }
    exit;
}

clone_log("Unknown clone action: $action");
echo json_encode(['success'=>false,'error'=>'Unknown clone action: '.$action]);
exit;

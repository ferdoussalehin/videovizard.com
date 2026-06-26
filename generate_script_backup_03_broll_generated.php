<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

function logError($msg) {
    error_log(date("Y-m-d H:i:s") . " - " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

logError('=== generate_script.php START ===');
logError('SESSION DUMP: ' . json_encode($_SESSION));
logError('COOKIES: '      . json_encode($_COOKIE));

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL) {
        ob_clean();
        echo json_encode(["success" => false, "error" => "Fatal: " . $error['message'], "script" => ""]);
    }
});

define('AZURE_BREAK', '<break time="200ms"/>');

$company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
if ($company_id === 0) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Session expired or company not set.', 'script' => '']);
    exit;
}

function splitAndTag($raw, $reel_type = '') {
    $raw = trim($raw);
    if (empty($raw)) return '';
    $raw = preg_replace('/<break[^\/]*\/>/i', '', $raw);
    $raw = trim($raw);
    $raw = str_replace("\xc2\xa0", ' ', $raw);
    $raw = str_replace("\t", ' ', $raw);

    // ── B-Roll: keep as ONE single block, no splitting ────────────────────────
    $is_broll = stripos($reel_type, 'b-roll') !== false
             || stripos($reel_type, 'broll')  !== false;
    if ($is_broll) {
        // Preserve paragraph breaks as newlines for readability but keep as one scene
        $raw = preg_replace('/\r?\n{2,}/', "\n\n", $raw); // normalize double newlines
        return $raw . ' ' . AZURE_BREAK;
    }

    // ── Standard / Podcast: split into scenes ─────────────────────────────────
    $scenes = preg_split('/\[SCENE BREAK\]/i', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        return implode("\n", array_map(function($s){ return rtrim($s) . ' ' . AZURE_BREAK; }, $scenes));
    }
    $scenes = preg_split('/\r?\n/', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        return implode("\n", array_map(function($s){ return rtrim($s) . ' ' . AZURE_BREAK; }, $scenes));
    }
    $modified = preg_replace('/([.!?])\s+/', "$1\n", $raw);
    $scenes = preg_split('/\n/', $modified);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        return implode("\n", array_map(function($s){ return rtrim($s) . ' ' . AZURE_BREAK; }, $scenes));
    }
    return $raw . ' ' . AZURE_BREAK;
}

function callChatGPT($prompt, $model = 'gpt-4o-mini', $systemPrompt = '') {
    require_once __DIR__ . '/config.php';
    $messages = [];
    if (!empty($systemPrompt)) {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];
    $data = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.75,
        'max_tokens'  => 2000,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error)        return ['success' => false, 'error' => $curl_error,       'response' => ''];
    if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code", 'response' => ''];
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return ['success' => true, 'response' => $result['choices'][0]['message']['content']];
    }
    return ['success' => false, 'error' => 'Unexpected response', 'response' => ''];
}

function buildPrompt($d) {
    if (!empty($d['_custom_prompt'])) {
        return $d['_custom_prompt'];
    }
    $niche     = $d['niche']     ?? '';
    $category  = $d['topic']     ?? '';
    $idea      = $d['title']     ?? '';
    $objective = $d['objective'] ?? '';
    $audience  = $d['audience']  ?? '';
    $angle     = $d['angle']     ?? '';
    $duration  = $d['duration']  ?? '60 seconds';
    $cta       = $d['cta']       ?? '';
    $language  = $d['language']  ?? 'English';
    $reelType  = $d['reel_type'] ?? 'Standard (Talking Head)';
    $durationMap = ['15 seconds' => 35, '30 seconds' => 70, '60 seconds' => 140, '90 seconds' => 210];
    $words = $durationMap[$duration] ?? 140;
    $brk = AZURE_BREAK;

    // ── B-Roll ────────────────────────────────────────────────────────────────
    if (stripos($reelType, 'B-Roll') !== false) {
        return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a voiceover script for: $idea
Niche: $niche | Category: $category | Audience: $audience | Objective: $objective
Angle: $angle | Duration: $duration (~$words words) | Language: $language

FORMAT: B-Roll voiceover — this is ONE continuous narration broken into paragraphs.
Background footage will play over the voiceover. Write in a calm, authoritative narrator voice.

OUTPUT RULES:
- Write 3-5 paragraphs separated by [SCENE BREAK]
- Each paragraph = 2-4 sentences, flowing naturally into the next
- Every paragraph ends with: $brk
- First paragraph: powerful opening statement or question
- Last paragraph: $cta $brk
- NO labels, NO headings, NO scene numbers
- Write as a continuous flowing narration — NOT short punchy sentences

EXAMPLE:
Every single day, millions of people wake up and go through the same routine without ever questioning it. They work hard, pay their bills, and wonder why nothing ever changes. $brk
[SCENE BREAK]
The truth is, most people are one decision away from a completely different life. The problem is they are waiting for the perfect moment that never comes. $brk
[SCENE BREAK]
$cta $brk
PROMPT;
    }

    // ── Podcast ───────────────────────────────────────────────────────────────
    if (stripos($reelType, 'Podcast') !== false) {
        return <<<PROMPT
You are an expert podcast scriptwriter for the '$niche' industry.

Write a podcast conversation script for: $idea
Niche: $niche | Category: $category | Audience: $audience | Objective: $objective
Angle: $angle | Duration: $duration (~$words words) | Language: $language

FORMAT: Natural back-and-forth podcast conversation between HOST and GUEST.

OUTPUT RULES:
- Every line must start with HOST: or GUEST:
- Alternate between HOST and GUEST naturally
- Each line = ONE sentence only, max 15 words
- HOST asks questions or makes statements, GUEST responds with insight and detail
- Always start with HOST: and end with HOST: delivering the CTA
- Separate each exchange with [SCENE BREAK]
- Every line ends with: $brk
- NO stage directions, NO headings, NO scene numbers

EXAMPLE:
HOST: Did you know most people never reach their true potential? $brk
[SCENE BREAK]
GUEST: Absolutely, and the main reason is usually fear of failure. $brk
[SCENE BREAK]
HOST: So how do we actually break through that fear? $brk
[SCENE BREAK]
GUEST: Start small and take one meaningful step every single day. $brk
[SCENE BREAK]
HOST: $cta $brk
PROMPT;
    }

    // ── Standard (Talking Head) ───────────────────────────────────────────────
    return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a script for: $idea
Niche: $niche | Category: $category | Audience: $audience | Objective: $objective
Angle: $angle | Duration: $duration (~$words words) | Language: $language

FORMAT: Standard talking head — direct-to-camera monologue, first person, punchy and engaging.

OUTPUT RULES:
- Exactly 6-8 scenes separated by [SCENE BREAK]
- Each scene = ONE sentence, max 12 words
- Every sentence ends with: $brk
- First scene: attention-grabbing hook, do NOT start with Hi or Welcome
- Last scene: $cta $brk
- NO labels, NO headings, NO scene numbers

EXAMPLE:
This mistake is costing you thousands every year. $brk
[SCENE BREAK]
Most people never realise they are doing it. $brk
[SCENE BREAK]
Here is exactly what to do instead. $brk
[SCENE BREAK]
$cta $brk
PROMPT;
}

// ── Save to DB ────────────────────────────────────────────────────────────────
function saveToDatabase($data, $script, $company_id) {
    $conn = mysqli_connect("localhost", "inaamalvi1403", "AllahuAkbar786", "hypnotherapy_db");
    if (!$conn) {
        throw new Exception('DB connection failed: ' . mysqli_connect_error());
    }

    $now       = date('Y-m-d H:i:s');
    $niche     = $data['niche']    ?? '';
    $title     = $data['title']    ?? '';
    $lang_code = $data['language'] ?? 'en';
    $reel_type = $data['reel_type'] ?? 'standard';
    $category  = $data['topic']    ?? '';
    $topic_key = $data['niche']    ?? '';
    $client_id = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : 0;
    $admin_id  = isset($_SESSION['admin_id'])  ? (int)$_SESSION['admin_id']  : 0;

    // ── 1. Insert podcast row ─────────────────────────────────────────────────
    $n_e  = mysqli_real_escape_string($conn, $niche);
    $ti_e = mysqli_real_escape_string($conn, $title);
    $lc_e = mysqli_real_escape_string($conn, $lang_code);
    $rt_e = mysqli_real_escape_string($conn, $reel_type);
    $sc_e = mysqli_real_escape_string($conn, $script);
    $ca_e = mysqli_real_escape_string($conn, $category);
    $tk_e = mysqli_real_escape_string($conn, $topic_key);

    $sql1 = "INSERT INTO hdb_podcasts
                (company_id, client_id, admin_id, niche, title, lang_code,
                 video_type, script_text, created_date, updated_at,
                 category, topic_key,
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
                 '$ca_e', '$tk_e',
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
    if (!$podcast_id) {
        throw new Exception('No podcast_id after insert');
    }
    logError("hdb_podcasts OK — podcast_id=$podcast_id");

    // ── Load user caption settings ────────────────────────────────────────────
    $ff   = 'Arial';
    $fs   = 28;
    $fc   = '#ffffff';
    $fw   = 'bold';
    $bgc  = '#000000';
    $bge  = 0;
    $cs   = 'none';
    $cspd = 1.0;
    $px   = 20;
    $py   = 300;
    $pw   = 380;

    if ($admin_id) {
        $us_q = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' LIMIT 1");
        if ($us_q && mysqli_num_rows($us_q) > 0) {
            $us   = mysqli_fetch_assoc($us_q);
            $ff   = $us['fontfamily']    ?? 'Arial';
            $fs   = intval($us['fontsize']     ?? 28);
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
    }

    // ── 2. Insert one scene row per line ──────────────────────────────────────
    $stop_words = ['the','and','for','you','your','with','that','this','are','can',
                   'will','have','from','they','what','about','more','just','into',
                   'over','after','were','been','has','its','not','but','all'];

    $scenes      = array_values(array_filter(array_map('trim', explode("\n", $script))));
    $scene_order = 1;

    foreach ($scenes as $scene_text) {
        if (empty($scene_text)) continue;

        $clean_text = trim(preg_replace('/<break[^>]*>/i', '', $scene_text));

        // ── Duration from word count at 130wpm ────────────────────────────────
        $word_count = count(array_filter(explode(' ', $clean_text)));
        $scene_dur  = max(3, (int)round(($word_count / 130) * 60));

        // ── Tags & prompt ─────────────────────────────────────────────────────
        $words    = preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $clean_text)));
        $keywords = array_values(array_filter($words, function($w) use ($stop_words) {
            return strlen($w) > 3 && !in_array($w, $stop_words);
        }));
        $tags = array_unique(array_merge(
            [$niche ? strtolower(preg_replace('/\s+/', '', $niche)) : 'general'],
            array_slice($keywords, 0, 4)
        ));
        $hashtags_str = implode(' ', $tags);
        $kw0     = $keywords[0] ?? $niche;
        $kw1     = $keywords[1] ?? 'concept';
        $nl_tags = implode('|', [
            $clean_text,
            ($niche ? $niche . ' professional' : 'professional'),
            $kw0 . ' lifestyle',
            $niche . ' ' . $kw1,
            'real life ' . ($niche ?: 'business'),
        ]);
        $prompt_text = "Photorealistic documentary-style photograph. Scene: {$clean_text} "
                     . "Niche: {$niche}. Natural lighting, candid composition, 35mm lens, "
                     . "shallow depth of field, authentic environment.";

        // ── Escape all values ─────────────────────────────────────────────────
        $te   = mysqli_real_escape_string($conn, $scene_text);
        $ce   = mysqli_real_escape_string($conn, $clean_text);
        $pe   = mysqli_real_escape_string($conn, $prompt_text);
        $he   = mysqli_real_escape_string($conn, $hashtags_str);
        $ne   = mysqli_real_escape_string($conn, $nl_tags);
        $lce  = mysqli_real_escape_string($conn, $lang_code);
        $cate = mysqli_real_escape_string($conn, $category);
        $tke  = mysqli_real_escape_string($conn, $topic_key);
        $tite = mysqli_real_escape_string($conn, $title);

        // ── Insert scene ──────────────────────────────────────────────────────
        $ins = "INSERT INTO hdb_podcast_stories
                    (company_id, podcast_id, lang_code, scene_order, category,
                     topic_key, title, actor, text_contents, text_display,
                     duration, image_video, prompt, status, audio_file,
                     image_file, video_file, created_date, schudule_date,
                     publish_date, publish_status, seq_no, logo_flag,
                     visual_type, voice_id,
                     hashtags, natural_language_tags)
                 VALUES
                    ($company_id, $podcast_id, '$lce', $scene_order, '$cate',
                     '$tke', '$tite', 'host', '$te', '$ce',
                     $scene_dur, 'image', '$pe', 'pending', '',
                     '', '', '$now', '',
                     0, 'pending', $scene_order, 0,
                     'stock', '',
                     '$he', '$ne')";

        if (!mysqli_query($conn, $ins)) {
            logError("Scene $scene_order INSERT FAIL: " . mysqli_error($conn));
            $scene_order++;
            continue;
        }
        $story_id = mysqli_insert_id($conn);

        // ── Insert caption row ────────────────────────────────────────────────
        $ff_esc  = mysqli_real_escape_string($conn, $ff);
        $fc_esc  = mysqli_real_escape_string($conn, $fc);
        $fw_esc  = mysqli_real_escape_string($conn, $fw);
        $bgc_esc = mysqli_real_escape_string($conn, $bgc);
        $cs_esc  = mysqli_real_escape_string($conn, $cs);

        $cap = mysqli_query($conn, "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, 'text', 'main', '$ce',
             '$ff_esc', $fs, '$fc_esc', '$fw_esc', 'normal', 'center',
             '$bgc_esc', $bge, $px, $py, $pw, 0,
             '$cs_esc', $cspd, 1, 1)");

        if (!$cap) {
            logError("Caption INSERT FAIL scene $scene_order: " . mysqli_error($conn));
        }

        logError("Scene $scene_order OK — story_id=$story_id dur={$scene_dur}s words=$word_count");
        $scene_order++;
    }

    mysqli_close($conn);
    return $podcast_id;
}

// ── Main ──────────────────────────────────────────────────────────────────────
try {
    $raw = file_get_contents('php://input');
    logError('RAW INPUT: ' . $raw);

    $data = json_decode($raw, true);
    if (!$data) throw new Exception('Invalid JSON input');

    $niche = trim($data['niche'] ?? '');
    $title = trim($data['title'] ?? '');
    if (!$niche) throw new Exception('Niche is missing');
    if (!$title && empty($data['_custom_prompt'])) throw new Exception('Video idea is missing');

    logError("Generating script: $niche | $title | company_id=$company_id");

    $prompt       = buildPrompt($data);
    $systemPrompt = '';

    if (!empty($data['_mode']) && $data['_mode'] === 'content') {
        $cta      = $data['cta']      ?? 'Follow for more tips';
        $language = $data['language'] ?? 'English';
        $brk      = AZURE_BREAK;
        $systemPrompt =
            'You are a video script formatter. Reformat the user content into exactly 6-8 short scenes.'
            . "\n\nRULES:"
            . "\n- Output one sentence per line"
            . "\n- Each sentence max 12 words"
            . "\n- Every sentence must end with: " . $brk
            . "\n- Last sentence must be: " . $cta . ' ' . $brk
            . "\n- Language: " . $language
            . "\n- NO blank lines between sentences"
            . "\n- NO labels, NO headings, NO extra text"
            . "\n\nEXAMPLE OUTPUT:"
            . "\nYour mind has the power to create real change. " . $brk
            . "\nHypnotherapy guides you into a deeply relaxed state. " . $brk
            . "\nYour subconscious opens to positive suggestions. " . $brk
            . "\n" . $cta . ' ' . $brk;
    }

    $response = callChatGPT($prompt, 'gpt-4o-mini', $systemPrompt);
    if (!$response['success']) throw new Exception($response['error']);

    $raw_script = trim($response['response']);
    logError('RAW RESPONSE: ' . $raw_script);

    $script = splitAndTag($raw_script, $data['reel_type'] ?? '');
    logError('AFTER splitAndTag: ' . $script);

   

    ob_clean();
    echo json_encode([
        'success'    => true,
        'script'     => $script,
        'podcast_id' => null,
		'data'       => $data, // pass data back to JS for use at build time
    ]);
    exit;

} catch (Throwable $e) {
    logError('ERROR: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'script' => '']);
    exit;
}
?>

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

    $is_broll = stripos($reel_type, 'b-roll') !== false
             || stripos($reel_type, 'broll')  !== false;
    if ($is_broll) {
        $raw = preg_replace('/\r?\n{2,}/', "\n\n", $raw);
        return $raw . ' ' . AZURE_BREAK;
    }

    $is_podcast = stripos($reel_type, 'podcast') !== false;

    $scenes = preg_split('/\[SCENE BREAK\]/i', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        return implode("\n", array_map(function($s) {
            return rtrim($s) . ' ' . AZURE_BREAK;
        }, $scenes));
    }

    $scenes = preg_split('/\r?\n/', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        return implode("\n", array_map(function($s) {
            return rtrim($s) . ' ' . AZURE_BREAK;
        }, $scenes));
    }

    if (!$is_podcast) {
        $modified = preg_replace('/([.!?])\s+/', "$1\n", $raw);
        $scenes   = preg_split('/\n/', $modified);
        $scenes   = array_values(array_filter(array_map('trim', $scenes)));
        if (count($scenes) > 1) {
            return implode("\n", array_map(function($s) {
                return rtrim($s) . ' ' . AZURE_BREAK;
            }, $scenes));
        }
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
    $durationMap = ['15 seconds'=>35,'30 seconds'=>70,'60 seconds'=>140,'90 seconds'=>210];
    $words = $durationMap[$duration] ?? 140;
    $brk = AZURE_BREAK;

    $meta_instruction = <<<META

After the script, on a new line write exactly: ---META---
Then on the next line return ONLY this JSON (no markdown, no extra text):
{"hashtags":"10-12 hashtags with # separated by spaces","keywords":"8-10 comma-separated SEO keywords without #","caption_text":"2-3 sentence engaging caption for Instagram/Facebook ending with the CTA, no hashtags"}
META;

    if (stripos($reelType, 'B-Roll') !== false) {
        return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a voiceover script for: $idea
Niche: $niche | Category: $category | Audience: $audience | Objective: $objective
Angle: $angle | Duration: $duration (~$words words) | Language: $language

FORMAT: B-Roll voiceover — ONE continuous narration broken into paragraphs.

OUTPUT RULES:
- Write 3-5 paragraphs separated by [SCENE BREAK]
- Each paragraph = 2-4 sentences, flowing naturally into the next
- Every paragraph ends with: $brk
- First paragraph: powerful opening statement or question
- Last paragraph: $cta $brk
- NO labels, NO headings, NO scene numbers
$meta_instruction
PROMPT;
    }

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
- HOST asks questions, makes observations, transitions topics
- GUEST gives detailed answers, shares insights, tells stories
- Always start with HOST: welcoming the guest
- Always end with HOST: delivering the CTA
- Each line on its own line, every line ends with: $brk
- NO stage directions, NO headings, NO scene numbers, NO blank lines
$meta_instruction
PROMPT;
    }

    return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a script for: $idea
Niche: $niche | Category: $category | Audience: $audience | Objective: $objective
Angle: $angle | Duration: $duration (~$words words) | Language: $language

FORMAT: Standard talking head — direct-to-camera monologue, punchy and engaging.

OUTPUT RULES:
- Exactly 6-8 scenes separated by [SCENE BREAK]
- Each scene = ONE sentence, max 12 words
- Every sentence ends with: $brk
- First scene: attention-grabbing hook, do NOT start with Hi or Welcome
- Last scene: $cta $brk
- NO labels, NO headings, NO scene numbers
$meta_instruction
PROMPT;
}
function parseScriptAndMeta($raw_response) {
    $script = $raw_response;
    $meta   = ['hashtags' => '', 'keywords' => '', 'caption_text' => ''];

    if (strpos($raw_response, '---META---') !== false) {
        [$script_part, $meta_part] = explode('---META---', $raw_response, 2);
        $script = trim($script_part);

        // Clean any markdown fences GPT might add around the JSON
        $meta_part = trim($meta_part);
        $meta_part = preg_replace('/^```(?:json)?\s*/i', '', $meta_part);
        $meta_part = preg_replace('/\s*```$/i', '', $meta_part);

        $decoded = json_decode(trim($meta_part), true);
        if (is_array($decoded)) {
            $meta['hashtags']     = $decoded['hashtags']     ?? '';
            $meta['keywords']     = $decoded['keywords']     ?? '';
            $meta['caption_text'] = $decoded['caption_text'] ?? '';
        } else {
            logError("parseScriptAndMeta: JSON decode failed | raw=" . substr($meta_part, 0, 300));
        }
    }

    return [$script, $meta];
}
// ── Save to DB ────────────────────────────────────────────────────────────────
function saveToDatabase($data, $script, $company_id, $meta = []) {
    $conn = mysqli_connect("localhost", "inaamalvi1403", "AllahuAkbar786", "hypnotherapy_db");
    if (!$conn) {
        throw new Exception('DB connection failed: ' . mysqli_connect_error());
    }

    $now       = date('Y-m-d H:i:s');
    $niche     = $data['niche']     ?? '';
    $title     = $data['title']     ?? '';
    $lang_code = $data['language']  ?? 'en';
    $reel_type = $data['reel_type'] ?? 'standard';
    $category  = $data['topic']     ?? '';
    $topic_key = $data['niche']     ?? '';
    $client_id = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : 0;
    $admin_id  = isset($_SESSION['admin_id'])  ? (int)$_SESSION['admin_id']  : 0;

    $n_e  = mysqli_real_escape_string($conn, $niche);
    $ti_e = mysqli_real_escape_string($conn, $title);
    $lc_e = mysqli_real_escape_string($conn, $lang_code);
    $rt_e = mysqli_real_escape_string($conn, $reel_type);
    $sc_e = mysqli_real_escape_string($conn, $script);
    $ca_e = mysqli_real_escape_string($conn, $category);
    $tk_e = mysqli_real_escape_string($conn, $topic_key);

    $hv_e = mysqli_real_escape_string($conn, $data['host_voice_id'] ?? $data['host_voice'] ?? $data['voice_id'] ?? '');
    $gv_e = mysqli_real_escape_string($conn, $data['guest_voice_id'] ?? $data['guest_voice'] ?? $data['voice_id_guest'] ?? '');

    $hashtags_e     = mysqli_real_escape_string($conn, $meta['hashtags']     ?? '');
    $keywords_e     = mysqli_real_escape_string($conn, $meta['keywords']     ?? '');
    $caption_text_e = mysqli_real_escape_string($conn, $meta['caption_text'] ?? '');

    logError("saveToDatabase: niche=$niche | title=$title | hashtags=" . ($meta['hashtags'] ?? 'EMPTY'));

    $sql1 = "INSERT INTO hdb_podcasts
                (company_id, client_id, admin_id, niche, title, lang_code,
                 video_type, script_text, host_voice, guest_voice,
                 created_date, updated_at,
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
                 '$rt_e', '$sc_e', '$hv_e', '$gv_e',
                 '$now', '$now',
                 '$ca_e', '$tk_e',
                 'ready', 'new',
                 0, '', '',
                 '', '', '', '',
                 '$hashtags_e', '$keywords_e', '$caption_text_e',
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

    logError("hdb_podcasts OK — podcast_id=$podcast_id | host_voice=$hv_e | hashtags=$hashtags_e");

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

    $raw_full = trim($response['response']);
    logError('RAW RESPONSE: ' . $raw_full);

    // Parse script and social meta from single GPT response
    [$raw_script, $meta] = parseScriptAndMeta($raw_full);
    logError('PARSED SCRIPT: ' . $raw_script);
    logError('PARSED META: ' . json_encode($meta));

    $script = splitAndTag($raw_script, $data['reel_type'] ?? '');
    logError('AFTER splitAndTag: ' . $script);

    // ── Save to DB ────────────────────────────────────────────────────────────
    $podcast_id = saveToDatabase($data, $script, $company_id, $meta);
	
	
	
    logError("podcast_id after save: $podcast_id");

    ob_clean();
    echo json_encode([
        'success'    => true,
        'script'     => $script,
        'podcast_id' => $podcast_id,
        'data'       => $data,
    ]);
    exit;

} catch (Throwable $e) {
    logError('ERROR: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'script' => '']);
    exit;
}
?>

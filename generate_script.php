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

// ── FIX 1: Merge scenes that are too short (safety net) ──────────────────────
// Combines consecutive lines under $minWords into one scene so no scene is
// shorter than ~3 seconds of audio. Stops merging once a scene hits $maxWords.
function mergeShortScenes(string $script, int $minWords = 15, int $maxWords = 35): string {
    $lines  = array_values(array_filter(array_map('trim', explode("\n", $script))));
    $merged = [];
    $buffer = '';

    foreach ($lines as $line) {
        $clean     = trim(preg_replace('/<break[^\/]*\/>/i', '', $line));
        $wordCount = str_word_count($clean);

        if ($buffer === '') {
            $buffer = $line;
        } else {
            $bufClean = trim(preg_replace('/<break[^\/]*\/>/i', '', $buffer));
            $bufWords = str_word_count($bufClean);

            // Merge only if buffer is under min AND combined total won't exceed max
            if ($bufWords < $minWords && ($bufWords + $wordCount) <= $maxWords) {
                $bufNoBreak = rtrim(preg_replace('/<break[^\/]*\/>/i', '', $buffer));
                $buffer     = $bufNoBreak . ' ' . $clean . ' ' . AZURE_BREAK;
            } else {
                $merged[] = $buffer;
                $buffer   = $line;
            }
        }
    }

    if ($buffer !== '') $merged[] = $buffer;

    return implode("\n", $merged);
}

// ── FIX 2: splitAndTag — removed the .!? sentence splitter ───────────────────
// The original code split on every period/exclamation/question mark, creating
// 2-3 second scenes. Now only [SCENE BREAK] tags or newlines split scenes.
function splitAndTag($raw, $reel_type = '') {
    $raw = trim($raw);
    if (empty($raw)) return '';

    $is_broll        = stripos($reel_type, 'b-roll') !== false || stripos($reel_type, 'broll') !== false;
    $is_podcast      = stripos($reel_type, 'podcast') !== false;
    $is_talking_head = stripos($reel_type, 'talking head') !== false;

    if (!$is_podcast && !$is_talking_head) {
        $raw = preg_replace('/<break[^\/]*\/>/i', '', $raw);
    }
    $raw = trim($raw);
    $raw = str_replace("\xc2\xa0", ' ', $raw);
    $raw = str_replace("\t", ' ', $raw);

    if ($is_broll) {
        $raw = preg_replace('/\r?\n{2,}/', "\n\n", $raw);
        return $raw . ' ' . AZURE_BREAK;
    }

    if ($is_podcast || $is_talking_head) {
        $lines = preg_split('/\r?\n/', $raw);
        $lines = array_values(array_filter(array_map('trim', $lines)));
        $lines = array_map(function($line) {
            if (!preg_match('/<break[^\/]*\/>/i', $line)) {
                $line = rtrim($line) . ' ' . AZURE_BREAK;
            }
            return $line;
        }, $lines);
        return implode("\n", $lines);
    }

    // Split on [SCENE BREAK] markers
    $scenes = preg_split('/\[SCENE BREAK\]/i', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        $tagged = implode("\n", array_map(fn($s) => rtrim($s) . ' ' . AZURE_BREAK, $scenes));
        return mergeShortScenes($tagged, 15, 35);
    }

    // Split on newlines
    $scenes = preg_split('/\r?\n/', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        $tagged = implode("\n", array_map(fn($s) => rtrim($s) . ' ' . AZURE_BREAK, $scenes));
        return mergeShortScenes($tagged, 15, 35);
    }

    // REMOVED: the .!? sentence splitter that was creating 2-3 second scenes.
    // If no [SCENE BREAK] or newlines, return as one scene.
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

    $niche           = trim($d['niche']           ?? '');
    $category        = trim($d['topic']           ?? $d['category'] ?? '');
    $idea            = trim($d['title']           ?? $d['video_idea'] ?? '');
    $angle           = trim($d['angle']           ?? '');
    $duration        = trim($d['duration']        ?? '60 seconds');
    $cta             = trim($d['cta']             ?? 'Follow for more tips');
    $language        = trim($d['language']        ?? 'English');

    $script_enforcement_map = [
        'Urdu'             => 'CRITICAL: Write ONLY in Urdu using Nastaliq/Perso-Arabic script (اردو). Do NOT use Roman Urdu under any circumstances. Every single word must use proper Urdu characters.',
        'Arabic'           => 'CRITICAL: Write ONLY in Arabic script (العربية). Do NOT use romanized Arabic.',
        'Hindi'            => 'CRITICAL: Write ONLY in Hindi using Devanagari script (हिन्दी). Do NOT use Roman/transliterated Hindi.',
        'Punjabi'          => 'CRITICAL: Write ONLY in Punjabi using Gurmukhi script (ਪੰਜਾਬੀ) or Shahmukhi (پنجابی). Do NOT romanize.',
        'Gujarati'         => 'CRITICAL: Write ONLY in Gujarati script (ગુજરાતી). Do NOT use romanized text.',
        'Tamil'            => 'CRITICAL: Write ONLY in Tamil script (தமிழ்). Do NOT use romanized Tamil.',
        'Bengali'          => 'CRITICAL: Write ONLY in Bengali script (বাংলা). Do NOT use romanized Bengali.',
        'Mandarin Chinese' => 'CRITICAL: Write ONLY in Simplified Chinese characters (中文). Do NOT use Pinyin.',
        'Japanese'         => 'CRITICAL: Write ONLY in Japanese using Kanji/Hiragana/Katakana (日本語). Do NOT romanize.',
        'Korean'           => 'CRITICAL: Write ONLY in Korean Hangul (한국어). Do NOT use romanized Korean.',
        'Farsi'            => 'CRITICAL: Write ONLY in Farsi/Persian using Arabic script (فارسی). Do NOT use romanized Farsi.',
        'Russian'          => 'CRITICAL: Write ONLY in Russian using Cyrillic script (Русский). Do NOT romanize.',
        'Bulgarian'        => 'CRITICAL: Write ONLY in Bulgarian using Cyrillic script (Български). Do NOT romanize.',
        'Serbian'          => 'CRITICAL: Write ONLY in Serbian using Cyrillic script (Српски). Do NOT romanize.',
        'Ukrainian'        => 'CRITICAL: Write ONLY in Ukrainian using Cyrillic script (Українська). Do NOT romanize.',
        'Greek'            => 'CRITICAL: Write ONLY in Greek using Greek alphabet (Ελληνικά). Do NOT romanize.',
        'Turkish'          => 'CRITICAL: Write in Turkish (Türkçe) using correct Turkish characters (ş, ğ, ı, ç, ö, ü).',
        'Portuguese'       => 'CRITICAL: Write in Portuguese (Português) with correct accented characters.',
        'Spanish'          => 'CRITICAL: Write in Spanish (Español) with correct accented characters.',
        'French'           => 'CRITICAL: Write in French (Français) with correct accented characters.',
        'German'           => 'CRITICAL: Write in German (Deutsch) with correct characters (ä, ö, ü, ß).',
        'Dutch'            => 'CRITICAL: Write in Dutch (Nederlands) with correct accented characters.',
        'Swedish'          => 'CRITICAL: Write in Swedish (Svenska) with correct characters (å, ä, ö).',
        'Norwegian'        => 'CRITICAL: Write in Norwegian (Norsk) with correct characters (æ, ø, å).',
        'Danish'           => 'CRITICAL: Write in Danish (Dansk) with correct characters (æ, ø, å).',
        'Finnish'          => 'CRITICAL: Write in Finnish (Suomi) with correct characters (ä, ö).',
        'Polish'           => 'CRITICAL: Write in Polish (Polski) with correct characters (ą, ć, ę, ł, ń, ó, ś, ź, ż).',
        'Czech'            => 'CRITICAL: Write in Czech (Čeština) with correct diacritical characters (á, č, ď, é, ě, í, ň, ř, š, ť, ů, ž).',
        'Slovak'           => 'CRITICAL: Write in Slovak (Slovenčina) with correct diacritical characters.',
        'Hungarian'        => 'CRITICAL: Write in Hungarian (Magyar) with correct characters (á, é, í, ó, ö, ő, ú, ü, ű).',
        'Romanian'         => 'CRITICAL: Write in Romanian (Română) with correct characters (ă, â, î, ș, ț).',
        'Croatian'         => 'CRITICAL: Write in Croatian (Hrvatski) with correct characters (č, ć, đ, š, ž).',
        'Slovenian'        => 'CRITICAL: Write in Slovenian (Slovenščina) with correct characters (č, š, ž).',
        'Albanian'         => 'CRITICAL: Write in Albanian (Shqip) with correct characters (ë, ç).',
    ];
    $lang_enforce = $script_enforcement_map[$language] ?? '';
    $language_instruction = "Language: {$language}" . ($lang_enforce ? "
{$lang_enforce}" : '');

    $reelType        = trim($d['reel_type']       ?? 'Standard');
    $tone            = trim($d['tone']            ?? 'Friendly');
    $brand_name      = trim($d['brand_name']      ?? '');
    $content_goals   = trim($d['content_goals']   ?? $d['objective'] ?? 'Promote');
    $growth_goals    = trim($d['growth_goals']    ?? 'Grow Followers');
    $target_location = trim($d['target_location'] ?? 'Global');
    $target_audience = trim($d['target_audience'] ?? $d['audience'] ?? 'General Public');

    // ── Duration-aware targets calibrated to TTS at ~150 wpm ─────────────────
    // Azure/OpenAI TTS reads at ~150 wpm. We target 90% of that to leave
    // breathing room for pauses and the <break> tags between scenes.
    // total_words = duration_seconds × (150/60) × 0.90
    $durationConfig = [
        '15 seconds' => ['total_words'=>32,  'scene_count'=>'3-4', 'min_scene'=>8,  'max_scene'=>12],
        '30 seconds' => ['total_words'=>60,  'scene_count'=>'4-5', 'min_scene'=>12, 'max_scene'=>18],
        '60 seconds' => ['total_words'=>120, 'scene_count'=>'6-7', 'min_scene'=>16, 'max_scene'=>24],
        '90 seconds' => ['total_words'=>180, 'scene_count'=>'7-8', 'min_scene'=>20, 'max_scene'=>28],
    ];
    $dc = $durationConfig[$duration] ?? $durationConfig['60 seconds'];
    $words           = $dc['total_words'];
    $scene_count     = $dc['scene_count'];
    $min_scene_words = $dc['min_scene'];
    $max_scene_words = $dc['max_scene'];
    $brk   = AZURE_BREAK;

    $location_ctx = ($target_location && $target_location !== 'Global')
        ? "Target Location: $target_location"
        : "Target Location: Global (worldwide audience)";

    $brand_ctx = $brand_name ? "Brand/Business Name: $brand_name" : '';

    $location_hashtag_note = '';
    if ($target_location && $target_location !== 'Global') {
        $loc_tag = '#' . preg_replace('/[^a-zA-Z0-9]/', '', $target_location);
        $location_hashtag_note = "- MUST include location hashtags for: $target_location (e.g. $loc_tag and related region tags)\n";
    }

    $brand_hashtag_note = $brand_name
        ? '- MUST include brand hashtag: #' . preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', $brand_name)) . "\n"
        : '';

    // ── Goal-driven writing style ─────────────────────────────────────────────
    $goal_styles = [
        'Promote' => [
            'writing_style' => 'Promotional and desire-building. Write like an excited business owner showing off something they\'re proud of. Make the viewer want to visit, buy, or order.',
            'hook_style'    => 'Lead with the product, result, or experience — create immediate desire or FOMO.',
            'structure'     => 'Hook → showcase the product/service → highlight what makes it special → social proof or urgency → CTA to visit/order/book.',
            'cta_tone'      => 'Direct and action-driving: visit us, order now, book today, come try it.',
            'avoid'         => 'Generic tips, educational content, anything that doesn\'t tie back to the business or product.',
        ],
        'Educate' => [
            'writing_style' => 'Clear, authoritative, and genuinely useful. Teach one specific thing the viewer didn\'t know or couldn\'t do before watching.',
            'hook_style'    => 'Lead with a surprising fact, a common mistake, or a question that makes the viewer feel they\'re missing something important.',
            'structure'     => 'Hook with the problem or knowledge gap → explain the concept step by step → give a concrete example → actionable takeaway → CTA to learn more.',
            'cta_tone'      => 'Value-driven: follow for more tips, save this for later, share with someone who needs this.',
            'avoid'         => 'Vague generalisations, promotional language, anything that feels like an ad.',
        ],
        'Build Trust' => [
            'writing_style' => 'Honest, transparent, and human. Show the real side of the business — process, values, people, and quality. Let authenticity do the selling.',
            'hook_style'    => 'Open with an honest admission, a behind-the-scenes reveal, or a "here\'s what most people don\'t see" moment.',
            'structure'     => 'Hook with authenticity → show real process, real people, or real standards → explain the why behind the what → close with a genuine statement of values → soft CTA.',
            'cta_tone'      => 'Soft and relationship-building: come visit us, we\'d love to meet you, follow along for more.',
            'avoid'         => 'Hard sell, exaggerated claims, polished marketing language, anything that feels scripted or fake.',
        ],
        'Entertain' => [
            'writing_style' => 'Fun, energetic, and shareable. Prioritise the laugh, the surprise, the satisfying moment, or the relatable scenario over information delivery.',
            'hook_style'    => 'Open with something unexpected, funny, or visually irresistible — make them laugh or gasp in the first 2 seconds.',
            'structure'     => 'Hook with the entertaining premise → build the moment or story → deliver the payoff (laugh, reveal, reaction, satisfying visual) → light CTA.',
            'cta_tone'      => 'Light and social: follow for more, tag a friend who needs to see this, share if this made you smile.',
            'avoid'         => 'Dry information, corporate tone, anything that feels like a lecture or a sales pitch.',
        ],
        'Inform' => [
            'writing_style' => 'Newsy, clear, and timely. Share something relevant happening now — a new product, update, event, or trend. Be concise and factual.',
            'hook_style'    => 'Lead with the news itself — what\'s new, what\'s changed, or what\'s coming. Treat it like a headline.',
            'structure'     => 'Announce the news → give the key details (what, when, where, why it matters) → any action required → CTA.',
            'cta_tone'      => 'Informative and clear: find out more, come see us, check the link, mark your calendar.',
            'avoid'         => 'Storytelling for its own sake, entertainment content, deep tutorials — keep it factual and relevant.',
        ],
        'Inspire' => [
            'writing_style' => 'Emotional, warm, and story-driven. Connect on a human level — share a journey, a struggle, a milestone, or a moment of meaning. Make them feel something.',
            'hook_style'    => 'Open with an emotional truth, a personal moment, or a line that makes them stop and think.',
            'structure'     => 'Hook with the emotional premise → tell the story or share the journey → reveal the transformation or lesson → connect it back to the viewer\'s own life → CTA.',
            'cta_tone'      => 'Warm and inviting: follow our journey, share your story, join our community, this one\'s for you.',
            'avoid'         => 'Cold facts, promotional language, anything transactional — this is about feeling, not selling.',
        ],
    ];

    $gs = $goal_styles[$content_goals] ?? $goal_styles['Promote'];
    $goal_style_block = "CONTENT GOAL: {$content_goals}
Writing Style: {$gs['writing_style']}
Hook Style: {$gs['hook_style']}
Script Structure: {$gs['structure']}
CTA Tone: {$gs['cta_tone']}
Avoid: {$gs['avoid']}";

    $meta_instruction = <<<META

After the script, on a new line write exactly: ---META---
Then on the next line return ONLY this JSON (no markdown, no extra text):
{"hashtags":"<value>","keywords":"<value>","caption_text":"<value>"}

HASHTAG RULES (25-30 hashtags, always in English):
- Niche-specific tags for: $niche / $category
- Audience tags for: $target_audience
- Content goal tags for: $content_goals
- Growth goal tags for: $growth_goals
$location_hashtag_note$brand_hashtag_note- Mix popular and niche-specific hashtags
- No spaces inside hashtags, separated by spaces, include # prefix

KEYWORD RULES (12-18 keywords, comma-separated, no #, always in English):
- Include: niche terms, category terms, audience type ($target_audience)
- Include: location terms if not Global ($target_location)
- Include: content goal ($content_goals), growth goal ($growth_goals)
- Include: brand name if provided ($brand_name)
- These are used for platform search/discovery SEO

CAPTION RULES (2-3 sentences, write in $language):
- Tone: $tone
- Open with an engaging hook related to the video content
- Naturally weave in location if not Global: $target_location
- Naturally mention brand if provided: $brand_name
- End with CTA driving: $growth_goals — use this CTA: $cta
- No hashtags in the caption
META;

    // ── B-Roll Voiceover ─────────────────────────────────────────────────────
    if (stripos($reelType, 'B-Roll') !== false) {
        return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a voiceover script for: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

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

    // ── Talking Head ─────────────────────────────────────────────────────────
    if (stripos($reelType, 'Talking Head') !== false) {
        return <<<PROMPT
You are a confident, engaging on-camera speaker.
Create a natural spoken script for a talking avatar.

Topic: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

STYLE:
- Sound like a real person talking to camera (not reading a script)
- Conversational, slightly informal, human
- Tone must be: $tone
- Use natural phrasing, not perfect grammar
- Include subtle fillers where appropriate: "so", "honestly", "you know", "here's the thing"

DELIVERY RULES:
- Mix short and medium-length sentences (5-20 words)
- Occasionally use sentence fragments (like real speech)
- Add light emotional cues when relevant: (smiles), (pauses), (leans in), (excited)
- Avoid overly polished or corporate language

PACING:
- Use natural pauses with variation:
  <break time="100ms"/> (quick)
  <break time="300ms"/> (normal)
  <break time="600ms"/> (emphasis)

STRUCTURE:
- Start with a strong, natural hook (not generic, NOT "Hi" or "Welcome")
- Flow smoothly between ideas (no abrupt jumps)
- Keep it engaging throughout
- End with this CTA naturally: $cta

FORMAT:
- No headings, no bullet points, no labels
- Output as continuous spoken lines
- Each line ends with a break tag
- 1-2 sentences per line

GOAL:
Make it feel like a real human speaking directly to the viewer on camera.
$meta_instruction
PROMPT;
    }

    // ── Podcast ───────────────────────────────────────────────────────────────
    if (stripos($reelType, 'Podcast') !== false) {
        $pod_words = (int)round($words * 0.8);
        return <<<PROMPT
You are an expert podcast scriptwriter for the '$niche' industry.

Write a highly natural, engaging podcast conversation for: $idea

Context:
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$pod_words words) | $language_instruction

$goal_style_block

STYLE: Real human conversation (not scripted, not robotic).

CRITICAL RULES:
- Use HOST and GUEST labels
- DO NOT strictly alternate speakers (allow interruptions and follow-ups)
- Mix short and long lines (3-25 words)
- Occasionally use sentence fragments (like real speech)
- Allow natural fillers: "yeah", "honestly", "you know", "I mean"
- Add light conversational cues: (laughs), (pauses), (sighs), (chuckles)
- HOST can react, interrupt, or add opinions (not just ask questions)
- GUEST should sound human: imperfect, reflective, sometimes hesitant
- Include occasional follow-up lines from the same speaker

PACING:
- Vary pauses naturally using:
  <break time="100ms"/> for quick replies
  <break time="300ms"/> for normal pauses
  <break time="600ms"/> for emphasis or emotional moments

FORMAT:
- Each line starts with HOST: or GUEST:
- 1-2 sentences per line (not forced to one)
- Each line ends with a break tag
- No headings or extra formatting

FLOW:
- Start casually, not overly formal
- Build into deeper discussion
- Include at least 1 moment of interruption or overlap feeling
- End naturally with HOST delivering this CTA: $cta

GOAL:
Make it sound like a real recorded podcast, not an AI script.
$meta_instruction
PROMPT;
    }

    // ── Standard (default) ────────────────────────────────────────────────────
    // FIX 3: scenes now 20-40 words (~4-7 seconds) instead of max 12 words (2-3 seconds)
    return <<<PROMPT
You are an expert short-form video scriptwriter for the '$niche' industry.

Write a script for: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Angle: $angle | Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

FORMAT: Standard short-form video — direct, engaging, scene-by-scene.

OUTPUT RULES:
- Exactly $scene_count scenes separated by [SCENE BREAK]
- Each scene = 1-2 complete sentences, {$min_scene_words}–{$max_scene_words} words
- Every scene ends with: $brk
- First scene: attention-grabbing hook — do NOT start with "Hi" or "Welcome"
- Last scene: $cta $brk
- NO labels, NO headings, NO scene numbers
- Each scene must be a COMPLETE thought a viewer can read and absorb in 4-7 seconds
- Do NOT write single short punchy lines — write full, meaningful sentences

GOOD EXAMPLE SCENE:
"Most people wear the same outfit the same way every time, but with one simple swap you can make it work for three completely different occasions. $brk"

BAD EXAMPLE (too short — do not do this):
"Stop wearing the same outfit! $brk"
$meta_instruction
PROMPT;
}

function parseScriptAndMeta($raw_response) {
    $script = $raw_response;
    $meta   = ['hashtags' => '', 'keywords' => '', 'caption_text' => ''];

    if (strpos($raw_response, '---META---') !== false) {
        [$script_part, $meta_part] = explode('---META---', $raw_response, 2);
        $script = trim($script_part);

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

function saveToDatabase($data, $script, $company_id, $meta = []) {
    $conn = mysqli_connect("localhost", "user_inaamalvi1403", "AllahuAkbar786", "user_hypnotherapy_db2");
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
	$vc_sql = "UPDATE hdb_users SET video_count = COALESCE(video_count, 0) + 1 WHERE id = " . (int)$_SESSION['admin_id'];
	mysqli_query($conn, $vc_sql);
	logError("video_count UPDATE — affected=" . mysqli_affected_rows($conn) . " admin_id=" . (int)$_SESSION['admin_id']);
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

        $script_enforcement_map = [
            'Urdu'             => 'CRITICAL: Write ONLY in Urdu using Nastaliq/Perso-Arabic script (اردو). Do NOT use Roman Urdu under any circumstances.',
            'Arabic'           => 'CRITICAL: Write ONLY in Arabic script (العربية). Do NOT use romanized Arabic.',
            'Hindi'            => 'CRITICAL: Write ONLY in Hindi using Devanagari script (हिन्दी). Do NOT use Roman/transliterated Hindi.',
            'Punjabi'          => 'CRITICAL: Write ONLY in Punjabi using Gurmukhi script (ਪੰਜਾਬੀ) or Shahmukhi (پنجابی). Do NOT romanize.',
            'Gujarati'         => 'CRITICAL: Write ONLY in Gujarati script (ગુજરાતી). Do NOT use romanized text.',
            'Tamil'            => 'CRITICAL: Write ONLY in Tamil script (தமிழ்). Do NOT use romanized Tamil.',
            'Bengali'          => 'CRITICAL: Write ONLY in Bengali script (বাংলা). Do NOT use romanized Bengali.',
            'Mandarin Chinese' => 'CRITICAL: Write ONLY in Simplified Chinese characters (中文). Do NOT use Pinyin.',
            'Japanese'         => 'CRITICAL: Write ONLY in Japanese using Kanji/Hiragana/Katakana (日本語). Do NOT romanize.',
            'Korean'           => 'CRITICAL: Write ONLY in Korean Hangul (한국어). Do NOT use romanized Korean.',
            'Farsi'            => 'CRITICAL: Write ONLY in Farsi/Persian using Arabic script (فارسی). Do NOT use romanized Farsi.',
            'Russian'          => 'CRITICAL: Write ONLY in Russian using Cyrillic script (Русский). Do NOT romanize.',
            'Bulgarian'        => 'CRITICAL: Write ONLY in Bulgarian using Cyrillic script (Български). Do NOT romanize.',
            'Serbian'          => 'CRITICAL: Write ONLY in Serbian using Cyrillic script (Српски). Do NOT romanize.',
            'Ukrainian'        => 'CRITICAL: Write ONLY in Ukrainian using Cyrillic script (Українська). Do NOT romanize.',
            'Greek'            => 'CRITICAL: Write ONLY in Greek using Greek alphabet (Ελληνικά). Do NOT romanize.',
            'Turkish'          => 'CRITICAL: Write in Turkish (Türkçe) using correct Turkish characters (ş, ğ, ı, ç, ö, ü).',
            'Portuguese'       => 'CRITICAL: Write in Portuguese (Português) with correct accented characters.',
            'Spanish'          => 'CRITICAL: Write in Spanish (Español) with correct accented characters.',
            'French'           => 'CRITICAL: Write in French (Français) with correct accented characters.',
            'German'           => 'CRITICAL: Write in German (Deutsch) with correct characters (ä, ö, ü, ß).',
            'Dutch'            => 'CRITICAL: Write in Dutch (Nederlands) with correct accented characters.',
            'Swedish'          => 'CRITICAL: Write in Swedish (Svenska) with correct characters (å, ä, ö).',
            'Norwegian'        => 'CRITICAL: Write in Norwegian (Norsk) with correct characters (æ, ø, å).',
            'Danish'           => 'CRITICAL: Write in Danish (Dansk) with correct characters (æ, ø, å).',
            'Finnish'          => 'CRITICAL: Write in Finnish (Suomi) with correct characters (ä, ö).',
            'Polish'           => 'CRITICAL: Write in Polish (Polski) with correct characters (ą, ć, ę, ł, ń, ó, ś, ź, ż).',
            'Czech'            => 'CRITICAL: Write in Czech (Čeština) with correct diacritical characters.',
            'Slovak'           => 'CRITICAL: Write in Slovak (Slovenčina) with correct diacritical characters.',
            'Hungarian'        => 'CRITICAL: Write in Hungarian (Magyar) with correct characters (á, é, í, ó, ö, ő, ú, ü, ű).',
            'Romanian'         => 'CRITICAL: Write in Romanian (Română) with correct characters (ă, â, î, ș, ț).',
            'Croatian'         => 'CRITICAL: Write in Croatian (Hrvatski) with correct characters (č, ć, đ, š, ž).',
            'Slovenian'        => 'CRITICAL: Write in Slovenian (Slovenščina) with correct characters (č, š, ž).',
            'Albanian'         => 'CRITICAL: Write in Albanian (Shqip) with correct characters (ë, ç).',
        ];
        $lang_enforce         = $script_enforcement_map[$language] ?? '';
        $language_instruction = "Language: {$language}" . ($lang_enforce ? "\n{$lang_enforce}" : '');

        // FIX 4: content mode system prompt updated to 20-40 words per scene
        $systemPrompt =
            'You are a video script formatter. Reformat the user content into exactly 6-8 scenes.'
            . "\n\nRULES:"
            . "\n- Output one scene per line"
            . "\n- Each scene = 1-2 complete sentences, 20-40 words"
            . "\n- Each scene must be a complete thought a viewer can read and absorb in 4-7 seconds"
            . "\n- Every scene must end with: " . $brk
            . "\n- Last scene must be: " . $cta . ' ' . $brk
            . "\n- Language: " . $language_instruction
            . "\n- NO blank lines between scenes"
            . "\n- NO labels, NO headings, NO extra text"
            . "\n- Do NOT write single short punchy lines — write full meaningful sentences"
            . "\n\nEXAMPLE OUTPUT:"
            . "\nYour mind has the power to create real change, and hypnotherapy is one of the most effective tools available to unlock it. " . $brk
            . "\nIn a deeply relaxed state, your subconscious becomes open to positive suggestions that can reshape long-held patterns. " . $brk
            . "\n" . $cta . ' ' . $brk;
    }

    $response = callChatGPT($prompt, 'gpt-4o-mini', $systemPrompt);
    if (!$response['success']) throw new Exception($response['error']);

    $raw_full = trim($response['response']);
    logError('RAW RESPONSE: ' . $raw_full);

    [$raw_script, $meta] = parseScriptAndMeta($raw_full);
    logError('PARSED SCRIPT: ' . $raw_script);
    logError('PARSED META: ' . json_encode($meta));

    $script = splitAndTag($raw_script, $data['reel_type'] ?? '');

    // Safety net: merge any scenes still under 15 words, but never exceed 35 words (Standard mode only)
    if (stripos($data['reel_type'] ?? '', 'b-roll')        === false
     && stripos($data['reel_type'] ?? '', 'podcast')       === false
     && stripos($data['reel_type'] ?? '', 'talking head')  === false) {
        $script = mergeShortScenes($script, 15, 35);
    }

    logError('AFTER splitAndTag + merge: ' . $script);

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

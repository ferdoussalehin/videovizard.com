<?php
// get_voices.php
// Returns a JSON array of available voices for the given lang_code.
// Called by vizard_scriptgen.php via POST: lang_code=en
// Response: { "voices": [ { voice_id, voice_name, gender, sample_voice, lang_code }, ... ] }

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$timeout = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>$timeout,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'error'=>'Not authenticated','voices'=>[]]);
    exit;
}

include __DIR__ . '/dbconnect_hdb.php';

$lang_code = trim($_POST['lang_code'] ?? $_GET['lang_code'] ?? 'en');
if (empty($lang_code)) $lang_code = 'en';
$lang_esc  = mysqli_real_escape_string($conn, $lang_code);

// ── Try to load from hdb_voices ──────────────────────────────────────────────
$voices = [];

if ($conn) {
    // Try lang_code match first, then fallback to all active voices
    $result = mysqli_query($conn,
        "SELECT voice_key AS voice_id, voice_name, gender,
                COALESCE(sample_voice, '') AS sample_voice,
                COALESCE(lang_code, 'en') AS lang_code
         FROM hdb_voices
         WHERE (lang_code = '$lang_esc' OR lang_code = '' OR lang_code IS NULL)
           
         ORDER BY gender ASC, voice_name ASC
         LIMIT 200"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Prefix openai voices if not already prefixed
            $vid = $row['voice_id'];
            if (!str_contains($vid, ':')) {
                // Heuristic: known OpenAI voice names
                $openai_voices = ['alloy','echo','fable','onyx','nova','shimmer','ash','coral','sage','verse'];
                if (in_array(strtolower($vid), $openai_voices)) {
                    $vid = 'openai:' . strtolower($vid);
                }
            }
            $voices[] = [
                'voice_id'    => $vid,
                'voice_name'  => $row['voice_name'],
                'gender'      => strtolower($row['gender'] ?? 'male'),
                'sample_voice'=> $row['sample_voice'] ?? '',
                'lang_code'   => $row['lang_code'],
            ];
        }
    }
}

// ── Fallback: return built-in OpenAI voices if DB empty or unavailable ───────
if (empty($voices)) {
    $voices = [
        ['voice_id'=>'openai:alloy',   'voice_name'=>'Alloy (Neutral)',  'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:echo',    'voice_name'=>'Echo',             'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:onyx',    'voice_name'=>'Onyx',             'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:fable',   'voice_name'=>'Fable',            'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:ash',     'voice_name'=>'Ash',              'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:verse',   'voice_name'=>'Verse',            'gender'=>'male',   'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:nova',    'voice_name'=>'Nova',             'gender'=>'female', 'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:shimmer', 'voice_name'=>'Shimmer',          'gender'=>'female', 'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:coral',   'voice_name'=>'Coral',            'gender'=>'female', 'sample_voice'=>'', 'lang_code'=>'en'],
        ['voice_id'=>'openai:sage',    'voice_name'=>'Sage',             'gender'=>'female', 'sample_voice'=>'', 'lang_code'=>'en'],
    ];
}

echo json_encode([
    'success'   => true,
    'lang_code' => $lang_code,
    'count'     => count($voices),
    'voices'    => $voices,
], JSON_UNESCAPED_UNICODE);

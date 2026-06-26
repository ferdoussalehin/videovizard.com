<?php
// generate_campaign.php
ob_start();

$error_log_path = __DIR__ . '/a_errors.log';
if (file_exists($error_log_path)) @unlink($error_log_path);
ini_set('error_log', $error_log_path);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function camp_log($msg) {
    global $error_log_path;
    file_put_contents($error_log_path, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

camp_log('generate_campaign.php START');
camp_log('PHP version: ' . PHP_VERSION);

if (!defined('AZURE_BREAK')) define('AZURE_BREAK', '<break time="200ms"/>');
if (!defined('SCENE_SEP'))   define('SCENE_SEP',   '[SCENE BREAK]');
camp_log('Constants defined OK');

function addBreakTag($scene) {
    $scene = trim($scene);
    if ($scene === '') return '';
    $scene = preg_replace('/<break[^\/]*\/>/i', '', $scene);
    return rtrim($scene) . ' ' . AZURE_BREAK;
}
camp_log('Functions defined OK');

$timeout = 30 * 24 * 60 * 60;
session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Strict'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

camp_log('Session started. admin_id=' . ($_SESSION['admin_id'] ?? 'NOT SET') . ' company_id=' . ($_SESSION['company_id'] ?? 'NOT SET'));

if (!isset($_SESSION['admin_id'])) {
    camp_log('ERROR: Not authenticated');
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated — please log in']);
    exit;
}

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
$client_id  = $_SESSION['client_id'] ?? $admin_id;

if ($company_id === 0) {
    camp_log('ERROR: company_id not set in session');
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Company not found in session — please log in again']);
    exit;
}

camp_log('Session OK: admin_id=' . $admin_id . ' company_id=' . $company_id);

include 'dbconnect_hdb.php';
if (!isset($apiKey) && file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
camp_log('DB included. conn=' . ($conn ? 'OK' : 'FAILED') . ' | apiKey=' . (empty($apiKey) ? 'MISSING' : 'SET (len=' . strlen($apiKey) . ')'));

ob_clean();
header('Content-Type: application/json');

if (!$conn) {
    camp_log('ERROR: DB connection failed');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$raw   = file_get_contents('php://input');
camp_log('Raw input length: ' . strlen($raw));
$input = json_decode($raw, true);
if (!$input) {
    camp_log('ERROR: Invalid JSON input. Raw: ' . substr($raw, 0, 200));
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}
camp_log('Input parsed OK. titles=' . count($input['titles'] ?? []) . ' niche=' . ($input['niche'] ?? ''));

$goal          = $input['goal']          ?? 'Brand Awareness';
$niche         = $input['niche']         ?? '';
$category      = $input['category']      ?? '';
$languages     = $input['languages']     ?? ['English'];
$duration      = $input['duration']      ?? '1 Month (30 days)';
$posts_per_day = $input['posts_per_day'] ?? '1 post per day';
$video_length  = $input['video_length']  ?? '60 seconds';
$titles        = $input['titles']        ?? [];
$reel_type     = $input['reel_type']     ?? 'Standard (Talking Head)';
$format        = $input['format']        ?? '9:16 Vertical (Reels / TikTok / Shorts)';
$objective     = $input['objective']     ?? 'Educate';
$audience      = $input['audience']      ?? 'General Public';

if (empty($titles) || empty($niche)) {
    echo json_encode(['success' => false, 'error' => 'Missing titles or niche']);
    exit;
}

$reel_lower = strtolower($reel_type);
if (strpos($reel_lower, 'b-roll') !== false)      $script_format = 'broll';
elseif (strpos($reel_lower, 'podcast') !== false) $script_format = 'podcast';
else                                               $script_format = 'standard';

// ── Auto-generate campaign name ───────────────────────────────
// Format: "Niche – Goal – Mon YYYY"  e.g. "Fitness – Brand Awareness – Mar 2026"
$campaign_name = trim($niche) . ' – ' . trim($goal) . ' – ' . date('M Y');

// ── Insert campaign row ONCE before the loop ──────────────────
$esc_campaign_name = mysqli_real_escape_string($conn, $campaign_name);
$esc_niche_c       = mysqli_real_escape_string($conn, $niche);
$esc_category_c    = mysqli_real_escape_string($conn, $category);
$esc_goal_c        = mysqli_real_escape_string($conn, $goal);
$esc_reel_c        = mysqli_real_escape_string($conn, $reel_type);
$esc_format_c      = mysqli_real_escape_string($conn, $format);
$esc_objective_c   = mysqli_real_escape_string($conn, $objective);
$esc_audience_c    = mysqli_real_escape_string($conn, $audience);
$esc_duration_c    = mysqli_real_escape_string($conn, $duration);
$esc_ppd_c         = mysqli_real_escape_string($conn, $posts_per_day);
$esc_vlen_c        = mysqli_real_escape_string($conn, $video_length);
$esc_languages_c   = mysqli_real_escape_string($conn, json_encode($languages));
$total_videos      = count($titles) * count($languages);

$sql_campaign = "INSERT INTO hdb_campaigns
                    (admin_id, company_id, campaign_name, niche, category, goal,
                     reel_type, format, objective, audience,
                     duration, posts_per_day, video_length,
                     languages, total_videos, status, created_at)
                 VALUES
                    ($admin_id, $company_id, '$esc_campaign_name', '$esc_niche_c', '$esc_category_c', '$esc_goal_c',
                     '$esc_reel_c', '$esc_format_c', '$esc_objective_c', '$esc_audience_c',
                     '$esc_duration_c', '$esc_ppd_c', '$esc_vlen_c',
                     '$esc_languages_c', $total_videos, 'active', NOW())";

camp_log("Inserting campaign: $campaign_name | total_videos=$total_videos");
$campaign_result = mysqli_query($conn, $sql_campaign);

if (!$campaign_result) {
    camp_log('ERROR: Campaign insert failed: ' . mysqli_error($conn));
    echo json_encode(['success' => false, 'error' => 'Failed to create campaign: ' . mysqli_error($conn)]);
    exit;
}

$campaign_id = mysqli_insert_id($conn);
camp_log("Campaign created: id=$campaign_id name='$campaign_name'");

// ── Hook angles ───────────────────────────────────────────────
$hook_angles = [
    'Quick Hacks', 'Step-by-Step', 'Common Mistakes', 'Surprising Secrets',
    'Did You Know?', 'Storytime', 'Myth Busting', 'Top 5 List',
    'FAQs Answered', 'Client Transformation', 'Warning / What to Avoid', 'Industry Trends'
];

$results = [];
$errors  = [];

foreach ($titles as $title_idx => $title) {
    $angle_used = $hook_angles[$title_idx % count($hook_angles)];

    foreach ($languages as $lang) {

        // ── Build prompt ───────────────────────────────────────
        $prompt = "You are an expert short-form video script writer.

Write a video script for: {$title}
Niche: {$niche} | Category: {$category} | Goal: {$goal}
Angle: {$angle_used} | Length: {$video_length} | Audience: {$audience} | Language: {$lang}

OUTPUT RULES — read carefully:
- Write exactly 6-8 scenes
- Each scene = ONE sentence, maximum 12 words
- Every sentence MUST end with: <break time=\"200ms\"/>
- Separate scenes with exactly: [SCENE BREAK]
- First scene: strong attention-grabbing hook, do NOT start with Hi or Welcome
- Last scene: a call to action sentence <break time=\"200ms\"/>
- NO labels, NO headings, NO scene numbers, NO commentary — pure sentences only
- Write in {$lang}

EXAMPLE OUTPUT:
This one mistake is costing you thousands every year. <break time=\"200ms\"/>
[SCENE BREAK]
Most people never even realise they are doing it. <break time=\"200ms\"/>
[SCENE BREAK]
Here is exactly what to do instead. <break time=\"200ms\"/>
[SCENE BREAK]
Follow for more tips like this every week. <break time=\"200ms\"/>";

        // ── Call OpenAI ────────────────────────────────────────
        $script_text = '';
        camp_log("Calling OpenAI for title='{$title}' lang={$lang}");
        try {
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model'       => 'gpt-4o-mini',
                    'messages'    => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens'  => 800,
                    'temperature' => 0.8
                ])
            ]);
            $resp      = curl_exec($ch);
            $curl_err  = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            camp_log("OpenAI response: HTTP {$http_code} | curl_err=" . ($curl_err ?: 'none') . " | resp_len=" . strlen($resp));

            if ($curl_err) throw new Exception('cURL error: ' . $curl_err);

            $resp_data = json_decode($resp, true);
            if (isset($resp_data['error'])) {
                camp_log('OpenAI API error: ' . json_encode($resp_data['error']));
                throw new Exception($resp_data['error']['message'] ?? 'OpenAI error');
            }
            $script_text = trim($resp_data['choices'][0]['message']['content'] ?? '');
            camp_log("Script generated OK. length=" . strlen($script_text));

        } catch (Exception $e) {
            camp_log('EXCEPTION: ' . $e->getMessage());
            $errors[] = "Script failed for '{$title}' ({$lang}): " . $e->getMessage();
            continue;
        }

        if (empty($script_text)) {
            $errors[] = "Empty script for '{$title}' ({$lang})";
            continue;
        }

        // ── Parse scenes ──────────────────────────────────────
        $scenes_raw = preg_split('/\[SCENE BREAK\]/i', $script_text);
        $scenes_raw = array_values(array_filter(array_map('trim', $scenes_raw)));

        if (count($scenes_raw) <= 1) {
            $scenes_raw = preg_split('/\n\n+/', $script_text);
            $scenes_raw = array_values(array_filter(array_map('trim', $scenes_raw)));
        }
        if (count($scenes_raw) <= 1) {
            $scenes_raw = preg_split('/\n/', $script_text);
            $scenes_raw = array_values(array_filter(array_map('trim', $scenes_raw)));
        }

        $scenes_raw  = array_values(array_filter(array_map('addBreakTag', $scenes_raw)));
        $script_text = implode("\n", $scenes_raw);
        camp_log("Scenes after tagging: " . count($scenes_raw));

        // ── Save to hdb_podcasts (now includes campaign_id) ───
        $esc_title    = mysqli_real_escape_string($conn, $title);
        $esc_lang     = mysqli_real_escape_string($conn, strtolower(substr($lang, 0, 2)));
        $esc_niche    = mysqli_real_escape_string($conn, $niche);
        $esc_goal     = mysqli_real_escape_string($conn, $goal);
        $esc_category = mysqli_real_escape_string($conn, $category);
        $esc_script   = mysqli_real_escape_string($conn, $script_text);

        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        $sql1 = "INSERT INTO hdb_podcasts
             (admin_id, client_id, company_id, campaign_id, title, lang_code, video_type, video_status,
              internal_status, created_date, updated_at,
              niche, category, campaign_goal, is_campaign,
              logo_flag, facebook_status, tiktok_status, instagram_status,
              youtube_status, twitter_status, linkedin_status,
              schedule_date, schedule_time, publish_date, video_format,
              video_media, music_file, hook_name, topic_key, script_text)
         VALUES
             ({$admin_id}, {$client_id}, {$company_id}, {$campaign_id}, '{$esc_title}', '{$esc_lang}',
              'standard', 'draft', 'draft', '{$today}', '{$now}',
              '{$esc_niche}', '{$esc_category}', '{$esc_goal}', 1,
              0, 'none', 'none', 'none',
              'none', 'none', 'none',
              '{$today}', '09:00', '{$today}', 'vertical',
              'video', '', '', '', '{$esc_script}')";

        $podcast_result = mysqli_query($conn, $sql1);
        camp_log("Insert hdb_podcasts: " . ($podcast_result ? 'OK' : 'FAIL: ' . mysqli_error($conn)));

        $podcast_id = mysqli_insert_id($conn);
        camp_log("podcast_id=$podcast_id | campaign_id=$campaign_id");

        if (!$podcast_id) {
            $errors[] = "Failed to save project for '{$title}' ({$lang}): " . mysqli_error($conn);
            continue;
        }

        $results[] = [
            'title'       => $title,
            'language'    => $lang,
            'podcast_id'  => $podcast_id,
            'campaign_id' => $campaign_id,
            'scenes'      => count($scenes_raw),
            'script'      => $script_text,
        ];

        usleep(200000);
    }
}

camp_log('DONE. results=' . count($results) . ' errors=' . count($errors));

echo json_encode([
    'success'       => true,
    'campaign_id'   => $campaign_id,
    'campaign_name' => $campaign_name,
    'results'       => $results,
    'errors'        => $errors,
    'total'         => count($results),
    'message'       => count($results) . ' scripts generated and saved to campaign: ' . $campaign_name,
]);
?>

<?php
/**
 * tag_ai_image.php
 *
 * Called after wizard_image_gen.php saves an AI-generated image.
 * Reads the image, runs GPT-4o Vision to generate tags, matches/creates
 * industry + niche rows, generates embedding, then INSERTs a new row
 * into hdb_image_data.
 *
 * POST params:
 *   filename    — basename only, e.g. "ai_abc123.jpg"  (looked up in podcast_images/)
 *   niche       — wizard niche string, used as hint for ai_group
 *   industry    — wizard industry string (optional hint)


 *
 * Returns JSON: { success, image_id, industry_name, niche_name, embedded }
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e['message']]); }
});

include __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';
$openai_key = $apiKey;

define('TAG_IMG_DIR', __DIR__ . '/podcast_images/');

// ── Auth ──────────────────────────────────────────────────────────────────────
$filename   = basename(trim($_POST['filename']  ?? ''));
$hint_niche = trim($_POST['niche']    ?? '');
$hint_indus = trim($_POST['industry'] ?? '');

if (!$filename) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'No filename']); exit;
}

$filepath = TAG_IMG_DIR . $filename;
if (!file_exists($filepath)) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'File not found: ' . $filename]); exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function tag_now_str()  { return date('Y-m-d H:i:s'); }
function tag_title($s)  {
    $low   = ['a','an','the','and','but','or','for','nor','on','at','to','by','in','of','up','as'];
    $words = explode(' ', strtolower(trim($s)));
    $out   = [];
    foreach ($words as $i => $w)
        $out[] = ($i === 0 || !in_array($w, $low)) ? ucfirst($w) : $w;
    return implode(' ', $out);
}

function tag_resize($path, $mime, $maxPx = 800) {
    if (!function_exists('imagecreatefromjpeg')) return false;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
        case 'image/png':  $src = @imagecreatefrompng($path);  break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        default:           $src = false;
    }
    if (!$src) return false;
    $ow = imagesx($src); $oh = imagesy($src);
    if ($ow <= $maxPx && $oh <= $maxPx) {
        ob_start(); imagejpeg($src, null, 82); $d = ob_get_clean(); imagedestroy($src); return $d;
    }
    $ratio = $ow / $oh;
    $nw = ($ow >= $oh) ? $maxPx : (int)round($maxPx * $ratio);
    $nh = ($ow >= $oh) ? (int)round($maxPx / $ratio) : $maxPx;
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);
    ob_start(); imagejpeg($dst, null, 82); $d = ob_get_clean(); imagedestroy($dst); return $d;
}

function tag_ensure_industry($conn, $name) {
    $esc = mysqli_real_escape_string($conn, $name);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_master_industries WHERE LOWER(industry_desc)=LOWER('$esc') LIMIT 1"));
    if ($row) return (int)$row['id'];
    $now = tag_now_str();
    mysqli_query($conn, "INSERT INTO hdb_master_industries (industry_desc,created_at,updated_at) VALUES ('$esc','$now','$now')");
    return (int)mysqli_insert_id($conn);
}

function tag_ensure_niche($conn, $industry_id, $name) {
    $esc = mysqli_real_escape_string($conn, $name);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_master_niches WHERE master_industry_id=$industry_id AND LOWER(niche_desc)=LOWER('$esc') LIMIT 1"));
    if ($row) return (int)$row['id'];
    $now = tag_now_str();
    mysqli_query($conn,
        "INSERT INTO hdb_master_niches (master_industry_id,niche_desc,created_at,updated_at)
         VALUES ($industry_id,'$esc','$now','$now')");
    return (int)mysqli_insert_id($conn);
}

// ── Read & encode image ───────────────────────────────────────────────────────
$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimeMap  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$origMime = $mimeMap[$ext] ?? 'image/jpeg';

$imgData = tag_resize($filepath, $origMime, 800);
if (!$imgData) $imgData = file_get_contents($filepath);
$b64  = base64_encode($imgData);
$mime = 'image/jpeg';

// ── Load master industries ────────────────────────────────────────────────────
$industry_list = [];
$r = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
while ($row = mysqli_fetch_assoc($r))
    $industry_list[(int)$row['id']] = trim($row['industry_desc']);

// ── GPT-4o Vision ─────────────────────────────────────────────────────────────
$hint_note = '';
if ($hint_niche || $hint_indus) {
    $hint_note = "\n\nCONTEXT HINT (from the video wizard): "
               . ($hint_indus ? "Industry = \"$hint_indus\". " : '')
               . ($hint_niche  ? "Niche = \"$hint_niche\". " : '')
               . "Use this as a guide but still pick the best matching group from the list.";
}

$vision_system =
    "Analyze the provided image and generate structured metadata.\n"
  . "You MUST pick the industry (group) from this exact list — do not invent new ones:\n"
  . "[" . implode(', ', $industry_list) . "]\n"
  . "If nothing fits reasonably well, use exactly: Others\n"
  . $hint_note . "\n\n"
  . "Return ONLY valid JSON, no markdown, no explanation:\n"
  . "{\n"
  . "  \"group\": \"<pick EXACTLY from the list above>\",\n"
  . "  \"subgroup\": \"<specific subcategory e.g. Outdoor Music, Luxury Homes, Mental Health, Street Food>\",\n"
  . "  \"description\": \"<one sentence describing what the image shows — subject matter only, no aesthetics>\",\n"
  . "  \"tags\": [\"natural language phrase 1\",\"natural language phrase 2\",...],\n"
  . "  \"mood\": \"<single mood word or phrase e.g. Inspirational, Professional, Energetic, Calm>\",\n"
  . "  \"use_cases\": [\"use case 1\",\"use case 2\",...]\n"
  . "}\n\n"
  . "For TAGS — strict rules:\n"
  . "GOAL: Tags must be concrete and searchable. A user typing this phrase in a search box must find EXACTLY this image.\n"
  . "FORMAT: 3-8 word phrases. Every tag MUST contain a specific noun + specific action OR specific noun + specific location.\n"
  . "Generate 10-15 tags across these categories:\n"
  . "  • WHO + WHAT: who/what is in the image and what they are doing (e.g. 'woman sitting on metal guardrail')\n"
  . "  • WHO + WHERE: subject placed in a specific location (e.g. 'sheep grazing on hillside pasture')\n"
  . "  • OBJECT + ACTION: specific objects doing or being used for something\n"
  . "  • COMBINATIONS: two specific subjects together\n"
  . "  • CONTEXT: only if highly specific\n"
  . "HARD BANNED:\n"
  . "  ✗ Atmosphere/setting with no subject: 'outdoor rural setting', 'natural landscape view'\n"
  . "  ✗ Lighting/aesthetics: 'sunset lighting', 'golden hour', 'soft natural light'\n"
  . "  ✗ Abstract concepts: 'connection with nature', 'enjoying nature'\n"
  . "  ✗ Vague lifestyle: 'relaxed outdoor lifestyle', 'casual clothing in nature'\n"
  . "  ✗ Single adjective + noun with no action: 'natural landscape', 'rural scene'\n"
  . "TEST every tag: 'does this phrase uniquely describe something VISIBLE and SPECIFIC?' If no — cut it.";

$payload = json_encode([
    'model'       => 'gpt-4o-mini',
    'messages'    => [
        ['role' => 'system', 'content' => $vision_system],
        ['role' => 'user',   'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64,$b64", 'detail' => 'low']],
            ['type' => 'text',      'text'      => 'Analyze this image and return the JSON as instructed.'],
        ]],
    ],
    'max_tokens'  => 700,
    'temperature' => 0.1,
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
    CURLOPT_TIMEOUT        => 60,
]);
$resp = curl_exec($ch); $curl_err = curl_error($ch); curl_close($ch);

if ($curl_err) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Vision cURL: ' . $curl_err]); exit;
}
$api_data = json_decode($resp, true);
if (!isset($api_data['choices'][0]['message']['content'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Vision API bad response']); exit;
}
$raw = trim($api_data['choices'][0]['message']['content']);
$raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
$raw = preg_replace('/\s*```$/', '', $raw);
$vision = json_decode($raw, true);

if (!$vision || !isset($vision['group'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Vision JSON parse failed']); exit;
}

$ai_group    = tag_title(trim($vision['group']));
$ai_subgroup = tag_title(trim($vision['subgroup'] ?? ''));
$ai_desc     = trim($vision['description'] ?? '');
$ai_mood     = trim($vision['mood']        ?? '');
$ai_usecases = (array)($vision['use_cases'] ?? []);
$ai_tags     = array_map('trim', (array)($vision['tags'] ?? []));

// ── Match Industry ────────────────────────────────────────────────────────────
$matched_industry_id   = null;
$matched_industry_name = '';

foreach ($industry_list as $id => $desc) {
    if (strtolower(trim($desc)) === strtolower($ai_group)) {
        $matched_industry_id = $id; $matched_industry_name = $desc; break;
    }
}
// Fallback → Lifestyle
if (!$matched_industry_id) {
    foreach ($industry_list as $id => $desc) {
        if (strtolower(trim($desc)) === 'lifestyle') {
            $matched_industry_id = $id; $matched_industry_name = $desc; break;
        }
    }
}
// Fallback → General
if (!$matched_industry_id) {
    $matched_industry_id   = tag_ensure_industry($conn, 'General');
    $matched_industry_name = 'General';
}

// ── Match / Create Niche ──────────────────────────────────────────────────────
$existing_niches = [];
$r = mysqli_query($conn,
    "SELECT id, niche_desc FROM hdb_master_niches WHERE master_industry_id=$matched_industry_id ORDER BY niche_desc ASC");
while ($row = mysqli_fetch_assoc($r))
    $existing_niches[(int)$row['id']] = trim($row['niche_desc']);

$ai_niche_name    = $ai_subgroup ?: $ai_group;
$matched_niche_id = null; $matched_niche_name = '';

foreach ($existing_niches as $id => $desc) {
    if (strtolower(trim($desc)) === strtolower($ai_niche_name)) {
        $matched_niche_id = $id; $matched_niche_name = $desc; break;
    }
}
if (!$matched_niche_id && $ai_niche_name && strtolower($ai_niche_name) !== 'others') {
    $matched_niche_id   = tag_ensure_niche($conn, $matched_industry_id, $ai_niche_name);
    $matched_niche_name = $ai_niche_name;
}
if (!$matched_niche_id) {
    $matched_niche_id   = tag_ensure_niche($conn, $matched_industry_id, 'General');
    $matched_niche_name = 'General';
}

// ── Build natural_language_tags ───────────────────────────────────────────────
$nl_parts = [];
if ($ai_desc)     $nl_parts[] = $ai_desc;
if ($ai_group)    $nl_parts[] = $ai_group;
if ($ai_subgroup) $nl_parts[] = $ai_subgroup;
if ($ai_mood)     $nl_parts[] = $ai_mood;
foreach ($ai_tags     as $t) if (trim($t)) $nl_parts[] = trim($t);
foreach ($ai_usecases as $u) if (trim($u)) $nl_parts[] = trim($u);
$nl_tags_str = implode(' | ', $nl_parts);

// ── Generate embedding ────────────────────────────────────────────────────────
$embedding_sql = '';
if (!empty($nl_tags_str)) {
    $ep = json_encode(['model' => 'text-embedding-3-large', 'input' => $nl_tags_str, 'dimensions' => 3072]);
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $ep,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
        CURLOPT_TIMEOUT => 30,
    ]);
    $er = curl_exec($ch); curl_close($ch);
    $ed = json_decode($er, true);
    if (isset($ed['data'][0]['embedding'])) {
        $embedding_sql = ", embedding = '" . mysqli_real_escape_string($conn, json_encode($ed['data'][0]['embedding'])) . "'";
    }
}

// ── INSERT new row into hdb_image_data ────────────────────────────────────────
$esc_fn       = mysqli_real_escape_string($conn, $filename);
$esc_group    = mysqli_real_escape_string($conn, $ai_group);
$esc_subgroup = mysqli_real_escape_string($conn, $ai_subgroup);
$esc_mood     = mysqli_real_escape_string($conn, $ai_mood);
$esc_desc     = mysqli_real_escape_string($conn, $ai_desc);
$esc_nl       = mysqli_real_escape_string($conn, $nl_tags_str);
$tags_json    = mysqli_real_escape_string($conn, json_encode($ai_tags));
$use_json     = mysqli_real_escape_string($conn, json_encode($ai_usecases));
$now          = tag_now_str();

// ── INSERT base row (no embedding yet) ───────────────────────────────────────
// Columns per actual hdb_image_data schema — all NOT NULL cols supplied
$_admin_id = (int)($_SESSION['admin_id'] ?? 0);
mysqli_query($conn,
    "INSERT INTO hdb_image_data
        (image_name, media_type, media_format, media_type_format,
         image_hashtags, niches, niche, master_industry,
         image_description, description,
         add_by, admin_id,
         created_at, updated_at,
         status, thumbnail, resize_flag, file_size,
         industry_id, niche_id,
         ai_group, ai_subgroup, ai_mood, ai_usecases,
         ai_description, ai_tags, natural_language_tags,
         tag_flag, tagged_at)
     VALUES
        ('$esc_fn', 'image', 'image', 'image',
         '', '', '$esc_subgroup', '$esc_group',
         '$esc_desc', '$esc_desc',
         $_admin_id, $_admin_id,
         '$now', '$now',
         'active', '', 0, '0',
         $matched_industry_id, $matched_niche_id,
         '$esc_group', '$esc_subgroup', '$esc_mood', '$use_json',
         '$esc_desc', '$tags_json', '$esc_nl',
         1, '$now')");

$new_id = (int)mysqli_insert_id($conn);

if (!$new_id) {
    $db_err = mysqli_error($conn);
    error_log("[tag_ai_image] INSERT failed: $db_err — filename=$filename");
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'DB insert failed: ' . $db_err]);
    exit;
}

// ── Apply embedding via UPDATE (embedding_sql = ", embedding = '...'" ) ──────
if (!empty($embedding_sql)) {
    $emb_col = ltrim($embedding_sql, ', '); // strip leading ", "
    mysqli_query($conn, "UPDATE hdb_image_data SET $emb_col WHERE id=$new_id");
}

error_log("[tag_ai_image] Saved id=$new_id file=$filename industry=$matched_industry_name niche=$matched_niche_name embedded=" . (!empty($embedding_sql) ? 'yes' : 'no'));

ob_clean();
echo json_encode([
    'success'       => true,
    'image_id'      => $new_id,
    'industry_name' => $matched_industry_name,
    'niche_name'    => $matched_niche_name,
    'group'         => $ai_group,
    'subgroup'      => $ai_subgroup,
    'mood'          => $ai_mood,
    'tags'          => $ai_tags,
    'embedded'      => !empty($embedding_sql),
]);

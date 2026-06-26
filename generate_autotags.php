<?php
/**
 * auto_tag.php — AI Image Tagger v3
 * OpenAI GPT-4o Vision · Industry/Niche dropdowns with add/delete
 * Tables: hdb_master_industries, hdb_master_niches, hdb_image_data
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/auto_tag.log');
error_reporting(E_ALL);
set_time_limit(0);
ob_start();

include __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';
$openai_key = $apiKey;

define('IMG_DIR', __DIR__ . '/podcast_images/');

// ── Ensure columns ────────────────────────────────────────────────────────────
foreach (array(
    "tag_flag    TINYINT(1) NOT NULL DEFAULT 0",
    "industry_id INT        DEFAULT NULL",
    "niche_id    INT        DEFAULT NULL",
    "ai_tags     TEXT       DEFAULT NULL",
    "ai_group    VARCHAR(255) DEFAULT NULL",
    "ai_subgroup VARCHAR(255) DEFAULT NULL",
    "ai_mood     VARCHAR(255) DEFAULT NULL",
    "ai_usecases    TEXT        DEFAULT NULL",
    "ai_description TEXT        DEFAULT NULL",
    "tagged_at      DATETIME    DEFAULT NULL",
) as $def) {
    $col = explode(' ', trim($def))[0];
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0)
        mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN $def");
}

// ── send_json ─────────────────────────────────────────────────────────────────
function send_json($data) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── resize_image_for_api — resize to max $maxPx, return JPEG binary ───────────
function resize_image_for_api($path, $mime, $maxPx = 800) {
    if (!function_exists('imagecreatefromjpeg')) return false;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
        case 'image/png':  $src = @imagecreatefrompng($path);  break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        case 'image/gif':  $src = @imagecreatefromgif($path);  break;
        default:           $src = false;
    }
    if (!$src) return false;
    $ow = imagesx($src); $oh = imagesy($src);
    if ($ow <= $maxPx && $oh <= $maxPx) {
        ob_start(); imagejpeg($src, null, 82); $data = ob_get_clean();
        imagedestroy($src); return $data;
    }
    $ratio = $ow / $oh;
    if ($ow >= $oh) { $nw = $maxPx; $nh = (int)round($maxPx / $ratio); }
    else            { $nh = $maxPx; $nw = (int)round($maxPx * $ratio); }
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);
    ob_start(); imagejpeg($dst, null, 82); $data = ob_get_clean();
    imagedestroy($dst); return $data;
}

// ── OpenAI text ───────────────────────────────────────────────────────────────
function callOpenAI($apiKey, $system, $user, $max_tokens = 300) {
    $payload = json_encode(array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array('role'=>'system','content'=>$system),
            array('role'=>'user',  'content'=>$user),
        ),
        'max_tokens' => $max_tokens, 'temperature' => 0.1,
    ));
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$apiKey),
        CURLOPT_TIMEOUT=>30,
    ));
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) { error_log("[auto_tag] cURL: $err"); return null; }
    $d = json_decode($resp, true);
    if (!isset($d['choices'][0]['message']['content'])) { error_log("[auto_tag] OpenAI: $resp"); return null; }
    $raw = trim($d['choices'][0]['message']['content']);
    $raw = preg_replace('/^```(?:json)?\s*/i','',$raw);
    $raw = preg_replace('/\s*```$/','',$raw);
    return json_decode($raw, true);
}

// ── OpenAI Vision ─────────────────────────────────────────────────────────────
function callOpenAIVision($apiKey, $system, $b64, $mime, $max_tokens = 600) {
    $payload = json_encode(array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array('role'=>'system','content'=>$system),
            array('role'=>'user','content'=>array(
                array('type'=>'image_url','image_url'=>array('url'=>"data:$mime;base64,$b64",'detail'=>'low')),
                array('type'=>'text','text'=>'Analyze this image and return the JSON as instructed.'),
            )),
        ),
        'max_tokens' => $max_tokens, 'temperature' => 0.1,
    ));
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$apiKey),
        CURLOPT_TIMEOUT=>45,
    ));
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) { error_log("[auto_tag] Vision cURL: $err"); return null; }
    $d = json_decode($resp, true);
    if (!isset($d['choices'][0]['message']['content'])) { error_log("[auto_tag] Vision: $resp"); return null; }
    $raw = trim($d['choices'][0]['message']['content']);
    $raw = preg_replace('/^```(?:json)?\s*/i','',$raw);
    $raw = preg_replace('/\s*```$/','',$raw);
    return json_decode($raw, true);
}

function toTitleCase($s) {
    $low=array('a','an','the','and','but','or','for','nor','on','at','to','by','in','of','up','as');
    $words=explode(' ',strtolower(trim($s))); $out=array();
    foreach($words as $i=>$w) $out[]=($i===0||!in_array($w,$low))?ucfirst($w):$w;
    return implode(' ',$out);
}

function now_str(){ return date('Y-m-d H:i:s'); }

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ═════════════════════════════════════════════════════════════════════════════
$action      = isset($_GET['action'])  ? $_GET['action']  : '';
$post_action = isset($_POST['action']) ? $_POST['action'] : '';

// ── get_stats ─────────────────────────────────────────────────────────────────
if ($action === 'get_stats') {
    send_json(array(
        'success' => true,
        'total'   => (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image'"))['c'],
        'tagged'  => (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image' AND tag_flag=1"))['c'],
        'pending' => (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image' AND tag_flag=0"))['c'],
    ));
}

// ── get_industries ────────────────────────────────────────────────────────────
if ($action === 'get_industries') {
    $rows=array();
    $r=mysqli_query($conn,"SELECT id,industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    while($row=mysqli_fetch_assoc($r)) $rows[]=array('id'=>(int)$row['id'],'name'=>trim($row['industry_desc']));
    send_json(array('success'=>true,'industries'=>$rows));
}

// ── get_niches (?industry_id=N) ───────────────────────────────────────────────
if ($action === 'get_niches') {
    $iid=(int)(isset($_GET['industry_id'])?$_GET['industry_id']:0);
    $rows=array();
    $r=mysqli_query($conn,"SELECT id,niche_desc FROM hdb_master_niches WHERE master_industry_id=$iid ORDER BY niche_desc ASC");
    while($row=mysqli_fetch_assoc($r)) $rows[]=array('id'=>(int)$row['id'],'name'=>trim($row['niche_desc']));
    send_json(array('success'=>true,'niches'=>$rows));
}

// ── add_industry ──────────────────────────────────────────────────────────────
if ($post_action === 'add_industry') {
    $name = toTitleCase(trim(isset($_POST['name'])?$_POST['name']:''));
    if (!$name) send_json(array('success'=>false,'message'=>'Name required'));
    $esc = mysqli_real_escape_string($conn,$name);
    $chk = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM hdb_master_industries WHERE LOWER(industry_desc)=LOWER('$esc') LIMIT 1"));
    if ($chk) send_json(array('success'=>false,'message'=>'Already exists','id'=>(int)$chk['id']));
    $now=now_str();
    mysqli_query($conn,"INSERT INTO hdb_master_industries (industry_desc,created_at,updated_at) VALUES ('$esc','$now','$now')");
    send_json(array('success'=>true,'id'=>(int)mysqli_insert_id($conn),'name'=>$name));
}

// ── delete_industry (cascade niches) ─────────────────────────────────────────
if ($post_action === 'delete_industry') {
    $iid=(int)(isset($_POST['id'])?$_POST['id']:0);
    if (!$iid) send_json(array('success'=>false,'message'=>'No id'));
    mysqli_query($conn,"DELETE FROM hdb_master_niches WHERE master_industry_id=$iid");
    mysqli_query($conn,"DELETE FROM hdb_master_industries WHERE id=$iid");
    send_json(array('success'=>true));
}

// ── add_niche ─────────────────────────────────────────────────────────────────
if ($post_action === 'add_niche') {
    $iid  = (int)(isset($_POST['industry_id'])?$_POST['industry_id']:0);
    $name = toTitleCase(trim(isset($_POST['name'])?$_POST['name']:''));
    if (!$iid||!$name) send_json(array('success'=>false,'message'=>'industry_id and name required'));
    $esc=mysqli_real_escape_string($conn,$name);
    $chk=mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM hdb_master_niches WHERE master_industry_id=$iid AND LOWER(niche_desc)=LOWER('$esc') LIMIT 1"));
    if ($chk) send_json(array('success'=>false,'message'=>'Already exists','id'=>(int)$chk['id']));
    $now=now_str();
    mysqli_query($conn,"INSERT INTO hdb_master_niches (master_industry_id,niche_desc,created_at,updated_at) VALUES ($iid,'$esc','$now','$now')");
    send_json(array('success'=>true,'id'=>(int)mysqli_insert_id($conn),'name'=>$name));
}

// ── delete_niche ──────────────────────────────────────────────────────────────
if ($post_action === 'delete_niche') {
    $nid=(int)(isset($_POST['id'])?$_POST['id']:0);
    if (!$nid) send_json(array('success'=>false,'message'=>'No id'));
    mysqli_query($conn,"DELETE FROM hdb_master_niches WHERE id=$nid");
    send_json(array('success'=>true));
}

// ── save_classification ───────────────────────────────────────────────────────
if ($post_action === 'save_classification') {
    $imgId       = (int)(isset($_POST['image_id'])     ? $_POST['image_id']     : 0);
    $iid         = (int)(isset($_POST['industry_id'])  ? $_POST['industry_id']  : 0);
    $nid         = (int)(isset($_POST['niche_id'])     ? $_POST['niche_id']     : 0);
    $tagsRaw     = isset($_POST['tags'])           ? $_POST['tags']           : '[]';
    $ai_group    = isset($_POST['ai_group'])       ? trim($_POST['ai_group'])    : '';
    $ai_subgroup = isset($_POST['ai_subgroup'])    ? trim($_POST['ai_subgroup']) : '';
    $ai_mood     = isset($_POST['ai_mood'])        ? trim($_POST['ai_mood'])     : '';
    $usecasesRaw = isset($_POST['ai_usecases'])    ? $_POST['ai_usecases']       : '[]';
    $ai_desc     = isset($_POST['ai_description']) ? trim($_POST['ai_description']) : '';

    if (!$imgId||!$iid||!$nid) send_json(array('success'=>false,'message'=>'Missing image_id, industry_id or niche_id'));

    $tags      = json_decode($tagsRaw, true);     if (!is_array($tags))      $tags      = array();
    $use_cases = json_decode($usecasesRaw, true); if (!is_array($use_cases)) $use_cases = array();

    $tags_json     = mysqli_real_escape_string($conn, json_encode($tags));
    $usecases_json = mysqli_real_escape_string($conn, json_encode($use_cases));
    $esc_group     = mysqli_real_escape_string($conn, $ai_group);
    $esc_subgroup  = mysqli_real_escape_string($conn, $ai_subgroup);
    $esc_mood      = mysqli_real_escape_string($conn, $ai_mood);
    $esc_desc      = mysqli_real_escape_string($conn, $ai_desc);
    $now           = now_str();

    // ── Build natural_language_tags ───────────────────────────────────────────
    $nl_parts = array();
    if ($ai_desc)     $nl_parts[] = $ai_desc;
    if ($ai_group)    $nl_parts[] = $ai_group;
    if ($ai_subgroup) $nl_parts[] = $ai_subgroup;
    if ($ai_mood)     $nl_parts[] = $ai_mood;
    foreach ($tags      as $t) { if (trim($t)) $nl_parts[] = trim($t); }
    foreach ($use_cases as $u) { if (trim($u)) $nl_parts[] = trim($u); }
    $nl_tags_str = implode(' | ', $nl_parts);
    $esc_nl      = mysqli_real_escape_string($conn, $nl_tags_str);

    // ── Generate embedding text-embedding-3-large (3072 dims) ─────────────────
    $embedding_sql = '';
    if (!empty($nl_tags_str)) {
        $embed_payload = json_encode(array(
            'model'      => 'text-embedding-3-large',
            'input'      => $nl_tags_str,
            'dimensions' => 3072,
        ));
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $embed_payload,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openai_key,
            ),
            CURLOPT_TIMEOUT => 30,
        ));
        $embed_resp = curl_exec($ch);
        $embed_err  = curl_error($ch);
        curl_close($ch);

        if (!$embed_err) {
            $embed_data = json_decode($embed_resp, true);
            if (isset($embed_data['data'][0]['embedding'])) {
                $vec = $embed_data['data'][0]['embedding'];
                $embedding_sql = ", embedding = '" . mysqli_real_escape_string($conn, json_encode($vec)) . "'";
                error_log("[auto_tag save] embedding OK id=$imgId dims=".count($vec));
            } else {
                error_log("[auto_tag save] embedding API error: $embed_resp");
            }
        } else {
            error_log("[auto_tag save] embedding cURL error: $embed_err");
        }
    }

    // ── UPDATE hdb_image_data ─────────────────────────────────────────────────
    mysqli_query($conn,
        "UPDATE hdb_image_data SET
            industry_id          = $iid,
            niche_id             = $nid,
            ai_group             = '$esc_group',
            ai_subgroup          = '$esc_subgroup',
            ai_mood              = '$esc_mood',
            ai_usecases          = '$usecases_json',
            ai_description       = '$esc_desc',
            ai_tags              = '$tags_json',
            natural_language_tags= '$esc_nl',
            tag_flag             = 1,
            tagged_at            = '$now'
            $embedding_sql
         WHERE id = $imgId"
    );

    if (mysqli_error($conn)) send_json(array('success'=>false,'message'=>mysqli_error($conn)));

    $embedded = !empty($embedding_sql);
    error_log("[auto_tag save] id=$imgId industry=$iid niche=$nid nl_tags=".strlen($nl_tags_str)."chars embedded=".($embedded?'yes':'no'));
    send_json(array(
        'success'  => true,
        'embedded' => $embedded,
        'nl_tags'  => $nl_tags_str,
    ));
}

// ── get_pending_image ─────────────────────────────────────────────────────────
if ($action === 'get_pending_image') {
    $mid=(int)(isset($_GET['id'])?$_GET['id']:0);
    $sql = $mid
        ? "SELECT id,image_name,media_type FROM hdb_image_data WHERE id=$mid AND media_type='image' LIMIT 1"
        : "SELECT id,image_name,media_type FROM hdb_image_data WHERE tag_flag=0 AND media_type='image' ORDER BY id ASC LIMIT 1";
    $res=mysqli_query($conn,$sql);
    if (!$res||mysqli_num_rows($res)===0) send_json(array('success'=>false,'message'=>'No untagged images found (media_type=image, tag_flag=0).'));
    $row=mysqli_fetch_assoc($res);
    $path=IMG_DIR.$row['image_name'];
    if (!file_exists($path)) send_json(array('success'=>false,'message'=>'File not on disk: '.$row['image_name']));
    $ext=strtolower(pathinfo($row['image_name'],PATHINFO_EXTENSION));
    $mimeMap=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif');
    $origMime=isset($mimeMap[$ext])?$mimeMap[$ext]:'image/jpeg';
    $imgData = resize_image_for_api($path, $origMime, 800);
    if (!$imgData) $imgData = file_get_contents($path);
    $b64 = base64_encode($imgData);
    $mime = 'image/jpeg';
    send_json(array('success'=>true,'id'=>(int)$row['id'],'image_name'=>$row['image_name'],
        'dataUrl'=>"data:$mime;base64,$b64",'base64'=>$b64,'mime'=>$mime));
}

// ── skip ──────────────────────────────────────────────────────────────────────
if ($action === 'skip') {
    $id=(int)(isset($_GET['id'])?$_GET['id']:0);
    if ($id) mysqli_query($conn,"UPDATE hdb_image_data SET tag_flag=2 WHERE id=$id");
    send_json(array('success'=>true));
}

// ── classify (main AI call) ───────────────────────────────────────────────────
if ($post_action === 'classify') {
    $imageId=(int)(isset($_POST['id'])?$_POST['id']:0);
    $base64 =isset($_POST['base64'])?$_POST['base64']:'';
    $mime   =isset($_POST['mime'])?$_POST['mime']:'image/jpeg';
    if (!$imageId||empty($base64)) send_json(array('success'=>false,'message'=>'Missing id or base64'));

    // ── STEP 1: Load industries from DB first, pass list to GPT ──────────────
    $industry_list = array();
    $r = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    while ($row = mysqli_fetch_assoc($r)) {
        $industry_list[(int)$row['id']] = trim($row['industry_desc']);
    }
    $industry_names_str = implode(', ', $industry_list);

    $vision_system =
        "Analyze the provided image and generate structured metadata.\n"
      . "You MUST pick the industry (group) from this exact list — do not invent new ones:\n"
      . "[" . $industry_names_str . "]\n"
      . "If nothing fits reasonably well, use exactly: Others\n\n"
      . "Return ONLY valid JSON, no markdown, no explanation:\n"
      . "{\n"
      . "  \"group\": \"<pick EXACTLY from the list above>\",\n"
      . "  \"subgroup\": \"<specific subcategory — e.g. Outdoor Music, Luxury Homes, Mental Health, Street Food>\",\n"
      . "  \"description\": \"<one sentence describing what the image shows — subject matter only, no aesthetics>\",\n"
      . "  \"tags\": [\"natural language phrase 1\",\"natural language phrase 2\",...],\n"
      . "  \"mood\": \"<single mood word or phrase — e.g. Inspirational, Professional, Energetic, Calm>\",\n"
      . "  \"use_cases\": [\"use case 1\",\"use case 2\",...]\n"
      . "}\n\n"
      . "For TAGS — write NATURAL LANGUAGE PHRASES (3-8 words each), not single keywords.\n"
      . "Think of how someone would search for this image in plain English.\n"
      . "Generate 10-15 tags covering combinations like:\n"
      . "- Who is in the image: \"young woman with guitar\", \"girl in jungle\"\n"
      . "- What they are doing: \"girl playing guitar\", \"woman performing outdoors\"\n"
      . "- Where it is: \"guitar in the jungle\", \"musician in forest setting\"\n"
      . "- Combinations: \"girl playing guitar in the jungle\", \"solo musician in nature\"\n"
      . "- Emotion/mood phrases: \"creative expression in nature\", \"peaceful solo performance\"\n"
      . "- Object + context: \"acoustic guitar outdoors\", \"guitar surrounded by trees\"\n"
      . "FORBIDDEN: single-word tags, lighting quality, brightness, aesthetics, 'well-lit', 'bright room'\n"
      . "Be concise but descriptive. Avoid assumptions that cannot be visually confirmed.";

    $vision_result = callOpenAIVision($openai_key, $vision_system, $base64, $mime, 700);
    if (!$vision_result || !isset($vision_result['group'])) {
        send_json(array('success'=>false,'message'=>'Vision API call failed — check OpenAI key or image size'));
    }

    $ai_group          = toTitleCase(trim($vision_result['group']));
    $ai_subgroup       = toTitleCase(trim(isset($vision_result['subgroup']) ? $vision_result['subgroup'] : ''));
    $image_description = isset($vision_result['description']) ? trim($vision_result['description']) : '';
    $ai_mood           = isset($vision_result['mood'])        ? trim($vision_result['mood'])        : '';
    $ai_use_cases      = isset($vision_result['use_cases'])   ? (array)$vision_result['use_cases'] : array();
    $tags              = isset($vision_result['tags'])        ? array_map('trim',(array)$vision_result['tags']) : array();

    // ── STEP 2: Exact match GPT's chosen group → hdb_master_industries ────────
    // No fuzzy logic — GPT already picked from the list we gave it
    $matched_industry_id   = null;
    $matched_industry_name = $ai_group;
    $industry_status       = '';

    foreach ($industry_list as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($ai_group)) {
            $matched_industry_id   = $id;
            $matched_industry_name = $desc;
            $industry_status       = 'existed';
            break;
        }
    }

    // GPT returned something not in the list — fall back to Others
    if (!$matched_industry_id) {
        $others_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, industry_desc FROM hdb_master_industries WHERE LOWER(industry_desc)='others' LIMIT 1"
        ));
        if ($others_row) {
            $matched_industry_id   = (int)$others_row['id'];
            $matched_industry_name = $others_row['industry_desc'];
        } else {
            $now = now_str();
            mysqli_query($conn, "INSERT INTO hdb_master_industries (industry_desc,created_at,updated_at) VALUES ('Others','$now','$now')");
            $matched_industry_id   = (int)mysqli_insert_id($conn);
            $matched_industry_name = 'Others';
        }
        $industry_status = 'others';
        error_log("[auto_tag classify] GPT returned '$ai_group' — not found in list, fell back to Others");
    }

    // ── STEP 3: Match subgroup → hdb_master_niches for this industry ─────────
    $existing_niches = array();
    $r = mysqli_query($conn, "SELECT id,niche_desc FROM hdb_master_niches WHERE master_industry_id=$matched_industry_id ORDER BY niche_desc ASC");
    while ($row = mysqli_fetch_assoc($r)) $existing_niches[(int)$row['id']] = trim($row['niche_desc']);

    $ai_niche_name   = $ai_subgroup ?: $ai_group;
    $matched_niche_id   = null;
    $matched_niche_name = $ai_niche_name;
    $niche_status       = '';

    // Exact match first
    foreach ($existing_niches as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($ai_niche_name)) {
            $matched_niche_id   = $id;
            $matched_niche_name = $desc;
            $niche_status       = 'existed';
            break;
        }
    }

    // Niches are free-form subgroups — still auto-create if new (this is fine)
    if (!$matched_niche_id) {
        $esc  = mysqli_real_escape_string($conn, $ai_niche_name);
        $now  = now_str();
        mysqli_query($conn, "INSERT INTO hdb_master_niches (master_industry_id,niche_desc,created_at,updated_at) VALUES ($matched_industry_id,'$esc','$now','$now')");
        $matched_niche_id   = (int)mysqli_insert_id($conn);
        $matched_niche_name = $ai_niche_name;
        $niche_status       = 'added';
    }

    error_log("[auto_tag classify] id=$imageId group=$matched_industry_name subgroup=$matched_niche_name mood=$ai_mood");

    send_json(array(
        'success'         => true,
        'industry_id'     => $matched_industry_id,
        'industry_name'   => $matched_industry_name,
        'industry_status' => $industry_status,
        'niche_id'        => $matched_niche_id,
        'niche_name'      => $matched_niche_name,
        'niche_status'    => $niche_status,
        'group'           => $ai_group,
        'subgroup'        => $ai_subgroup,
        'description'     => $image_description,
        'tags'            => $tags,
        'mood'            => $ai_mood,
        'use_cases'       => $ai_use_cases,
    ));
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Image Tagger</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:       #e8f0fb;
  --bg2:      #d4e4f7;
  --card:     #ffffff;
  --card2:    #f0f6ff;
  --bdr:      #b8d0ee;
  --bdr2:     #93b8e8;
  --dk:       #0a1f4e;
  --dk2:      #0d2d6b;
  --dk3:      #1a3f8a;
  --acc:      #1565c0;
  --acc2:     #1976d2;
  --acc-lt:   #e3eeff;
  --acc-glow: rgba(21,101,192,0.18);
  --grn:      #1b8a4a;
  --grn-lt:   #e6f4ed;
  --amber:    #c07000;
  --amber-lt: #fff3e0;
  --red:      #c62828;
  --red-lt:   #fdecea;
  --txt:      #0a1f4e;
  --txt2:     #2d5a8e;
  --mut:      #6b89b4;
  --tag-bg:   #deeaff;
  --tag-txt:  #1565c0;
  --shadow:   0 4px 24px rgba(10,31,78,0.10);
  --shadow-lg:0 8px 40px rgba(10,31,78,0.14);
  font-family:'Segoe UI',system-ui,sans-serif;
}
body{background:var(--bg);color:var(--txt);min-height:100vh;padding:24px 20px;
  background-image:
    radial-gradient(ellipse 70% 50% at 5% 0%,  rgba(21,101,192,0.07) 0%,transparent 60%),
    radial-gradient(ellipse 60% 50% at 95% 100%,rgba(21,101,192,0.05) 0%,transparent 60%);}

/* ── Header ── */
.page-header{background:linear-gradient(135deg,var(--dk) 0%,var(--dk3) 100%);
  border-radius:14px;padding:20px 24px;margin-bottom:22px;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
  box-shadow:var(--shadow-lg);}
.page-header h1{font-size:20px;font-weight:700;color:#fff;letter-spacing:.3px;display:flex;align-items:center;gap:10px;}
.header-sub{font-size:12px;color:rgba(255,255,255,0.55);margin-top:3px;}
.badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.12);
  border:1px solid rgba(255,255,255,0.2);border-radius:100px;padding:4px 13px;
  font-size:11px;font-weight:700;color:#fff;letter-spacing:.08em;text-transform:uppercase;}
.badge-dot{width:6px;height:6px;background:#4fc3f7;border-radius:50%;animation:pulse 2s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.3;transform:scale(.5);}}

/* ── Stats ── */
.stats-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.stat{background:var(--card);border:1px solid var(--bdr);border-radius:12px;
  padding:13px 20px;min-width:115px;text-align:center;box-shadow:var(--shadow);}
.stat strong{display:block;font-size:28px;font-weight:700;line-height:1.1;}
.stat span{font-size:11px;color:var(--mut);margin-top:2px;display:block;}
.s-total  strong{color:var(--acc);}
.s-tagged strong{color:var(--grn);}
.s-pending strong{color:var(--amber);}

/* ── Row ID bar ── */
.row-id-bar{display:none;align-items:center;gap:14px;
  background:linear-gradient(90deg,var(--dk) 0%,var(--dk3) 100%);
  border-radius:10px;padding:10px 20px;margin-bottom:16px;color:#fff;}
.row-id-bar.show{display:flex;}
.row-id-label{font-size:10px;font-weight:700;color:rgba(255,255,255,0.55);letter-spacing:.1em;text-transform:uppercase;}
.row-id-num{font-size:22px;font-weight:700;color:#fff;}
.row-id-file{font-size:12px;color:rgba(255,255,255,0.65);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:400px;}

/* ── Controls ── */
.controls{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:20px;}
.btn{padding:9px 20px;border-radius:8px;border:none;font-size:13px;font-weight:700;
  cursor:pointer;font-family:inherit;transition:all .15s;letter-spacing:.2px;}
.btn:hover:not(:disabled){filter:brightness(1.08);transform:translateY(-1px);}
.btn:disabled{opacity:.35;cursor:not-allowed;transform:none;}
.btn-primary{background:linear-gradient(135deg,var(--acc),var(--dk3));color:#fff;box-shadow:0 3px 12px var(--acc-glow);}
.btn-secondary{background:var(--card);color:var(--acc);border:1.5px solid var(--bdr2);}
.btn-amber{background:linear-gradient(135deg,#f57c00,#e65100);color:#fff;}
.btn-danger{background:linear-gradient(135deg,var(--red),#8b0000);color:#fff;}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:6px;}
.btn-ghost{background:transparent;color:var(--red);border:1px solid rgba(198,40,40,0.3);font-size:11px;padding:4px 10px;border-radius:5px;cursor:pointer;}
.btn-ghost:hover{background:var(--red-lt);}
.id-input{padding:8px 13px;border:1.5px solid var(--bdr);border-radius:8px;
  background:var(--card);color:var(--txt);font-family:inherit;font-size:13px;width:155px;}
.id-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-lt);}
.id-input::placeholder{color:var(--mut);}

/* ── Main grid ── */
.main-grid{display:grid;grid-template-columns:1fr 430px;gap:14px;}
@media(max-width:960px){.main-grid{grid-template-columns:1fr;}}

/* ── Image card ── */
.img-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.card-header{padding:11px 16px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;background:var(--card2);}
.card-title{font-size:13px;font-weight:700;color:var(--dk);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:75%;}
.card-meta{font-size:11px;color:var(--mut);}
.img-body{background:var(--bg2);min-height:340px;display:flex;align-items:center;justify-content:center;}
.img-body img{max-width:100%;max-height:420px;object-fit:contain;display:block;}
.img-placeholder{color:var(--mut);font-size:13px;text-align:center;padding:40px;line-height:2.2;}
.img-placeholder strong{color:var(--acc);}

/* ── Result card ── */
.result-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);display:flex;flex-direction:column;}
.result-body{flex:1;padding:16px;display:flex;flex-direction:column;gap:13px;}

/* ── Form rows ── */
.res-block{display:flex;flex-direction:column;gap:5px;}
.res-label{font-size:10px;font-weight:800;color:var(--mut);letter-spacing:1.1px;text-transform:uppercase;}
.res-value{font-size:14px;font-weight:600;color:var(--txt);}
.empty-val{color:var(--mut);font-style:italic;font-weight:400;font-size:13px;}
.res-desc{font-size:12px;color:var(--txt2);line-height:1.65;font-style:italic;}

/* ── Dropdown row ── */
.dd-row{display:flex;gap:6px;align-items:center;}
.dd-select{flex:1;padding:8px 10px;border:1.5px solid var(--bdr);border-radius:8px;
  background:var(--card2);color:var(--txt);font-family:inherit;font-size:13px;cursor:pointer;}
.dd-select:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-lt);}
.dd-select:disabled{opacity:.45;cursor:not-allowed;}

/* ── Add-new row ── */
.add-row{display:none;gap:6px;align-items:center;margin-top:5px;}
.add-row.show{display:flex;}
.add-input{flex:1;padding:7px 10px;border:1.5px solid var(--acc);border-radius:7px;
  background:#fff;color:var(--txt);font-family:inherit;font-size:13px;}
.add-input:focus{outline:none;box-shadow:0 0 0 3px var(--acc-lt);}

/* ── Status pills ── */
.pill{display:inline-block;font-size:10px;font-weight:700;padding:2px 9px;border-radius:100px;vertical-align:middle;margin-left:5px;}
.pill-db   {background:var(--acc-lt);  color:var(--acc);}
.pill-new  {background:var(--grn-lt);  color:var(--grn);}
.pill-amber{background:var(--amber-lt);color:var(--amber);}

/* ── Tags ── */
.tags-wrap{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px;min-height:28px;}
.tag{display:inline-flex;align-items:center;gap:5px;background:var(--tag-bg);color:var(--tag-txt);
  border-radius:20px;padding:4px 10px 4px 13px;font-size:12px;font-weight:500;
  border:1px solid rgba(21,101,192,0.15);}
.tag-del{background:none;border:none;color:rgba(21,101,192,0.45);cursor:pointer;
  font-size:13px;line-height:1;padding:0;margin:0;display:flex;align-items:center;}
.tag-del:hover{color:var(--red);}

/* ── Use cases ── */
.use-cases{display:flex;flex-direction:column;gap:4px;}
.use-case-item{font-size:12px;color:var(--txt2);padding:3px 0;}
.use-case-item::before{content:'→ ';color:var(--acc);}

/* ── Mood badge ── */
.mood-badge{display:inline-block;background:linear-gradient(135deg,var(--dk),var(--dk3));
  color:#fff;border-radius:8px;padding:4px 14px;font-size:12px;font-weight:600;}

/* ── Divider ── */
.divider{border:none;border-top:1px solid var(--bdr);}

/* ── Save bar ── */
.save-bar{background:var(--card2);border-top:1px solid var(--bdr);padding:12px 16px;display:flex;gap:8px;align-items:center;}

/* ── Log ── */
.log-wrap{flex:1;display:flex;flex-direction:column;gap:4px;}
.log-box{background:var(--dk);border:1px solid var(--bdr2);border-radius:8px;
  padding:11px;font-size:11px;line-height:1.9;font-family:'Cascadia Code','Fira Code',monospace;
  color:rgba(255,255,255,0.5);min-height:70px;max-height:180px;overflow-y:auto;}
.log-ok  {color:#66bb6a;}
.log-err {color:#ef9a9a;}
.log-warn{color:#ffd54f;}
.log-info{color:#90caf9;}

/* ── Spinner ── */
.spinner{display:inline-block;width:11px;height:11px;
  border:2px solid rgba(21,101,192,0.2);border-top-color:var(--acc);
  border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<!-- Header -->
<div class="page-header">
  <div>
    <h1>🏷 AI Image Tagger <span class="badge"><span class="badge-dot"></span>GPT-4o Vision</span></h1>
    <div class="header-sub">media_type = 'image' · tag_flag = 0 · OpenAI structured metadata</div>
  </div>
</div>

<!-- Stats -->
<div class="stats-bar">
  <div class="stat s-total" ><strong id="statTotal">—</strong> <span>Total Images</span></div>
  <div class="stat s-tagged"><strong id="statTagged">—</strong><span>Tagged</span></div>
  <div class="stat s-pending"><strong id="statPending">—</strong><span>Pending</span></div>
</div>

<!-- Row ID bar -->
<div class="row-id-bar" id="rowIdBar">
  <div>
    <div class="row-id-label">Reading Row ID</div>
    <div class="row-id-num" id="badgeId">—</div>
  </div>
  <div class="row-id-file" id="badgeFile"></div>
</div>

<!-- Controls -->
<div class="controls">
  <button class="btn btn-primary"   id="btnRun"  onclick="runNext()">▶ Tag Next Image</button>
  <button class="btn btn-secondary" id="btnNext" onclick="loadImage(0)" disabled>⏭ Load Only</button>
  <button class="btn btn-secondary" id="btnSkip" onclick="skipCurrent()" disabled>⤵ Skip</button>
  <input  class="id-input" id="manualId" type="number" min="1" placeholder="Enter row ID…">
  <button class="btn btn-amber" onclick="runManual()">▶ Tag by ID</button>
</div>

<!-- Main grid -->
<div class="main-grid">

  <!-- Image viewer -->
  <div class="img-card">
    <div class="card-header">
      <span class="card-title" id="imgTitle">No image loaded</span>
      <span class="card-meta"  id="imgMeta"></span>
    </div>
    <div class="img-body" id="imgBody">
      <div class="img-placeholder">Click <strong>"Tag Next Image"</strong> to begin<br>or enter a row ID above.</div>
    </div>
  </div>

  <!-- Result panel -->
  <div class="result-card">
    <div class="card-header">
      <span class="card-title">📊 Classification Result</span>
    </div>
    <div class="result-body">

      <!-- Industry dropdown -->
      <div class="res-block">
        <div class="res-label">Industry / Group <span id="indStatus"></span></div>
        <div class="dd-row">
          <select class="dd-select" id="selIndustry" onchange="onIndustryChange()" disabled>
            <option value="">— select industry —</option>
          </select>
          <button class="btn btn-sm btn-secondary" onclick="toggleAddIndustry()" title="Add new industry">＋</button>
          <button class="btn btn-sm btn-ghost"     onclick="deleteIndustry()"    title="Delete industry">🗑</button>
        </div>
        <div class="add-row" id="addIndRow">
          <input class="add-input" id="addIndInput" type="text" placeholder="New industry name…">
          <button class="btn btn-sm btn-primary"   onclick="submitAddIndustry()">Add</button>
          <button class="btn btn-sm btn-secondary" onclick="toggleAddIndustry()">✕</button>
        </div>
      </div>

      <!-- Niche dropdown -->
      <div class="res-block">
        <div class="res-label">Niche / Subgroup <span id="nicheStatus"></span></div>
        <div class="dd-row">
          <select class="dd-select" id="selNiche" disabled>
            <option value="">— select industry first —</option>
          </select>
          <button class="btn btn-sm btn-secondary" onclick="toggleAddNiche()" title="Add new niche">＋</button>
          <button class="btn btn-sm btn-ghost"     onclick="deleteNiche()"    title="Delete niche">🗑</button>
        </div>
        <div class="add-row" id="addNicheRow">
          <input class="add-input" id="addNicheInput" type="text" placeholder="New niche name…">
          <button class="btn btn-sm btn-primary"   onclick="submitAddNiche()">Add</button>
          <button class="btn btn-sm btn-secondary" onclick="toggleAddNiche()">✕</button>
        </div>
      </div>

      <hr class="divider">

      <!-- Group / Subgroup display -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="res-block">
          <div class="res-label">AI Group → Industry</div>
          <div class="res-value" id="resGroup"><span class="empty-val">—</span></div>
        </div>
        <div class="res-block">
          <div class="res-label">AI Subgroup → Niche</div>
          <div class="res-value" id="resSubgroup"><span class="empty-val">—</span></div>
        </div>
      </div>

      <!-- Description -->
      <div class="res-block">
        <div class="res-label">What GPT Sees</div>
        <div class="res-desc" id="resDesc">—</div>
      </div>

      <!-- Mood -->
      <div class="res-block">
        <div class="res-label">Mood</div>
        <div id="resMood"><span class="empty-val">—</span></div>
      </div>

      <!-- Tags -->
      <div class="res-block">
        <div class="res-label" style="display:flex;align-items:center;justify-content:space-between;">
          <span>Natural Language Tags</span>
          <span style="font-size:10px;color:var(--mut);font-weight:400;" id="tagCount"></span>
        </div>
        <div class="tags-wrap" id="resTags"><span class="empty-val">—</span></div>
        <div class="add-row show" id="addTagRow" style="margin-top:8px;">
          <input class="add-input" id="addTagInput" type="text" placeholder="Add a tag phrase…"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();addTag();}">
          <button class="btn btn-sm btn-primary" onclick="addTag()">＋ Add</button>
        </div>
      </div>

      <!-- Use cases -->
      <div class="res-block">
        <div class="res-label">Possible Use Cases</div>
        <div class="use-cases" id="resUseCases"><span class="empty-val">—</span></div>
      </div>

      <hr class="divider">

      <!-- Log -->
      <div class="log-wrap">
        <div class="res-label">Log</div>
        <div class="log-box" id="logBox">Ready.</div>
      </div>

    </div>

    <!-- Save bar -->
    <div class="save-bar">
      <button class="btn btn-primary" id="btnSave" onclick="saveOverride()" disabled style="flex:1;">
        💾 Save Classification
      </button>
      <small style="color:var(--mut);font-size:11px;">Override AI result &amp; save</small>
    </div>
  </div>

</div>

<script>
// ── State ──────────────────────────────────────────────────────────────────
var currentId   = null;
var currentB64  = null;
var currentMime = null;
var allIndustries = [];
var aiState = { group:'', subgroup:'', mood:'', use_cases:[], tags:[], description:'' };

// ── Init ───────────────────────────────────────────────────────────────────
loadStats();
loadAllIndustries();

// ── Stats ──────────────────────────────────────────────────────────────────
function loadStats() {
  fetch('?action=get_stats').then(r=>r.text()).then(raw=>{
    try { var d=JSON.parse(raw);
      if(!d.success)return;
      document.getElementById('statTotal').textContent   = d.total;
      document.getElementById('statTagged').textContent  = d.tagged;
      document.getElementById('statPending').textContent = d.pending;
    } catch(e){}
  });
}

// ── Log ────────────────────────────────────────────────────────────────────
function log(cls, msg) {
  var box=document.getElementById('logBox');
  var line=document.createElement('div'); line.className='log-'+cls;
  var ts=new Date().toLocaleTimeString('en-GB',{hour12:false});
  line.textContent='['+ts+'] '+msg;
  box.appendChild(line); box.scrollTop=box.scrollHeight;
}

// ── Industry dropdown ──────────────────────────────────────────────────────
function loadAllIndustries() {
  return fetch('?action=get_industries').then(r=>r.text()).then(raw=>{
    try { var d=JSON.parse(raw); if(!d.success)return; allIndustries=d.industries; populateIndustrySelect(null); } catch(e){}
  });
}

function populateIndustrySelect(selectedId) {
  var sel=document.getElementById('selIndustry');
  var prev=selectedId||parseInt(sel.value)||0;
  sel.innerHTML='<option value="">— select industry —</option>';
  allIndustries.forEach(function(ind){
    var opt=document.createElement('option'); opt.value=ind.id; opt.textContent=ind.name;
    if(ind.id===prev) opt.selected=true;
    sel.appendChild(opt);
  });
  sel.disabled=false;
  if(prev) loadNichesFor(prev, null);
}

function onIndustryChange() {
  var iid=parseInt(document.getElementById('selIndustry').value)||0;
  document.getElementById('nicheStatus').textContent='';
  if(iid) loadNichesFor(iid,null);
  else {
    var s=document.getElementById('selNiche');
    s.innerHTML='<option value="">— select industry first —</option>';
    s.disabled=true;
  }
}

// ── Niche dropdown ─────────────────────────────────────────────────────────
function loadNichesFor(iid, selectedId) {
  return fetch('?action=get_niches&industry_id='+iid).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw); if(!d.success)return;
      var sel=document.getElementById('selNiche');
      sel.innerHTML='<option value="">— select niche —</option>';
      d.niches.forEach(function(n){
        var opt=document.createElement('option'); opt.value=n.id; opt.textContent=n.name;
        if(selectedId&&n.id===selectedId) opt.selected=true;
        sel.appendChild(opt);
      });
      sel.disabled=false;
    } catch(e){}
  });
}

// ── Add industry ───────────────────────────────────────────────────────────
function toggleAddIndustry() {
  var row=document.getElementById('addIndRow');
  row.classList.toggle('show');
  if(row.classList.contains('show')) document.getElementById('addIndInput').focus();
}
function submitAddIndustry() {
  var name=document.getElementById('addIndInput').value.trim();
  if(!name){log('warn','⚠ Enter an industry name');return;}
  var fd=new FormData(); fd.append('action','add_industry'); fd.append('name',name);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw);
      if(!d.success){log('err','❌ '+d.message);return;}
      log('ok','✅ Industry added: '+d.name+' (ID #'+d.id+')');
      document.getElementById('addIndInput').value='';
      document.getElementById('addIndRow').classList.remove('show');
      allIndustries.push({id:d.id,name:d.name});
      allIndustries.sort(function(a,b){return a.name.localeCompare(b.name);});
      populateIndustrySelect(d.id);
      loadNichesFor(d.id, null);
    } catch(e){log('err','Parse error: '+e.message);}
  });
}

// ── Delete industry ────────────────────────────────────────────────────────
function deleteIndustry() {
  var iid=parseInt(document.getElementById('selIndustry').value)||0;
  if(!iid){log('warn','⚠ Select an industry to delete');return;}
  var name=document.getElementById('selIndustry').options[document.getElementById('selIndustry').selectedIndex].text;
  if(!confirm('Delete industry "'+name+'" AND all its niches? This cannot be undone.'))return;
  var fd=new FormData(); fd.append('action','delete_industry'); fd.append('id',iid);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw);
      if(!d.success){log('err','❌ '+d.message);return;}
      log('ok','🗑 Industry deleted: '+name);
      loadAllIndustries();
      var s=document.getElementById('selNiche');
      s.innerHTML='<option value="">— select industry first —</option>'; s.disabled=true;
    } catch(e){}
  });
}

// ── Add niche ──────────────────────────────────────────────────────────────
function toggleAddNiche() {
  var iid=parseInt(document.getElementById('selIndustry').value)||0;
  if(!iid){log('warn','⚠ Select an industry first');return;}
  var row=document.getElementById('addNicheRow');
  row.classList.toggle('show');
  if(row.classList.contains('show')) document.getElementById('addNicheInput').focus();
}
function submitAddNiche() {
  var iid=parseInt(document.getElementById('selIndustry').value)||0;
  var name=document.getElementById('addNicheInput').value.trim();
  if(!iid||!name){log('warn','⚠ Select industry and enter niche name');return;}
  var fd=new FormData(); fd.append('action','add_niche'); fd.append('industry_id',iid); fd.append('name',name);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw);
      if(!d.success){log('err','❌ '+d.message);return;}
      log('ok','✅ Niche added: '+d.name+' (ID #'+d.id+')');
      document.getElementById('addNicheInput').value='';
      document.getElementById('addNicheRow').classList.remove('show');
      var sel=document.getElementById('selNiche');
      var opt=document.createElement('option'); opt.value=d.id; opt.textContent=d.name;
      var inserted=false;
      for(var i=1;i<sel.options.length;i++){
        if(sel.options[i].text.localeCompare(d.name)>0){sel.insertBefore(opt,sel.options[i]);inserted=true;break;}
      }
      if(!inserted) sel.appendChild(opt);
      sel.value=d.id;
      sel.disabled=false;
    } catch(e){log('err','Parse error: '+e.message);}
  });
}

// ── Delete niche ───────────────────────────────────────────────────────────
function deleteNiche() {
  var nid=parseInt(document.getElementById('selNiche').value)||0;
  if(!nid){log('warn','⚠ Select a niche to delete');return;}
  var name=document.getElementById('selNiche').options[document.getElementById('selNiche').selectedIndex].text;
  if(!confirm('Delete niche "'+name+'"?'))return;
  var fd=new FormData(); fd.append('action','delete_niche'); fd.append('id',nid);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw);
      if(!d.success){log('err','❌ '+d.message);return;}
      log('ok','🗑 Niche deleted: '+name);
      var iid=parseInt(document.getElementById('selIndustry').value)||0;
      if(iid) loadNichesFor(iid,null);
    } catch(e){}
  });
}

// ── Save classification ────────────────────────────────────────────────────
function saveOverride() {
  if(!currentId){log('warn','⚠ No image loaded');return;}
  var iid=parseInt(document.getElementById('selIndustry').value)||0;
  var nid=parseInt(document.getElementById('selNiche').value)||0;
  if(!iid||!nid){log('warn','⚠ Select both industry and niche before saving');return;}
  var fd=new FormData();
  fd.append('action','save_classification');
  fd.append('image_id',      currentId);
  fd.append('industry_id',   iid);
  fd.append('niche_id',      nid);
  fd.append('ai_group',      aiState.group);
  fd.append('ai_subgroup',   aiState.subgroup);
  fd.append('ai_mood',       aiState.mood);
  fd.append('ai_usecases',   JSON.stringify(aiState.use_cases));
  fd.append('ai_description',aiState.description);
  fd.append('tags',          JSON.stringify(aiState.tags));
  document.getElementById('btnSave').disabled=true;
  document.getElementById('btnSave').textContent='Saving…';
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    try {
      var d=JSON.parse(raw);
      document.getElementById('btnSave').disabled=false;
      document.getElementById('btnSave').textContent='💾 Save Classification';
      if(!d.success){log('err','❌ '+d.message);return;}
      var indName=document.getElementById('selIndustry').options[document.getElementById('selIndustry').selectedIndex].text;
      var nichName=document.getElementById('selNiche').options[document.getElementById('selNiche').selectedIndex].text;
      log('ok','💾 Saved row '+currentId+' → '+indName+' / '+nichName);
      if(d.embedded) log('ok','   ✅ Embedding generated (3072 dims)');
      else           log('warn','   ⚠ Embedding skipped or failed — check log');
      log('info','   NL Tags: '+d.nl_tags);
      loadStats();
      log('info','⏭ Loading next image…');
      setTimeout(function(){ runNext(); }, 800);
    } catch(e){log('err','Parse error: '+e.message);}
  });
}

// ── Badge ──────────────────────────────────────────────────────────────────
function showBadge(id,fn){
  var bar=document.getElementById('rowIdBar'); bar.classList.add('show');
  document.getElementById('badgeId').textContent  =id;
  document.getElementById('badgeFile').textContent=fn;
}
function hideBadge(){ document.getElementById('rowIdBar').classList.remove('show'); }

// ── Clear results ──────────────────────────────────────────────────────────
function clearResults() {
  aiState = { group:'', subgroup:'', mood:'', use_cases:[], tags:[], description:'' };
  document.getElementById('indStatus').textContent  ='';
  document.getElementById('nicheStatus').textContent='';
  document.getElementById('resGroup').innerHTML    ='<span class="empty-val">—</span>';
  document.getElementById('resSubgroup').innerHTML ='<span class="empty-val">—</span>';
  document.getElementById('resDesc').textContent   ='—';
  document.getElementById('resMood').innerHTML     ='<span class="empty-val">—</span>';
  document.getElementById('resTags').innerHTML     ='<span class="empty-val">—</span>';
  document.getElementById('resUseCases').innerHTML ='<span class="empty-val">—</span>';
  document.getElementById('btnSave').disabled=true;
}

// ── Load image ─────────────────────────────────────────────────────────────
function loadImage(manualId) {
  currentId=null; currentB64=null; currentMime=null;
  clearResults(); hideBadge();
  document.getElementById('imgBody').innerHTML    ='<div class="img-placeholder"><span class="spinner"></span>Loading…</div>';
  document.getElementById('imgTitle').textContent ='Loading…';
  document.getElementById('imgMeta').textContent  ='';
  document.getElementById('btnNext').disabled=true;
  document.getElementById('btnSkip').disabled=true;
  document.getElementById('btnSave').disabled=true;

  var url='?action=get_pending_image'+(manualId?'&id='+manualId:'');
  return fetch(url).then(r=>r.text()).then(raw=>{
    var d; try{d=JSON.parse(raw);}catch(e){
      log('err','❌ Server error: '+raw.substring(0,200));
      document.getElementById('imgBody').innerHTML='<div class="img-placeholder">❌ Server error — check auto_tag.log</div>';
      return false;
    }
    if(!d.success){
      log('warn','⚠ '+d.message);
      document.getElementById('imgBody').innerHTML='<div class="img-placeholder">'+escHtml(d.message)+'</div>';
      return false;
    }
    currentId=d.id; currentB64=d.base64; currentMime=d.mime;
    showBadge(d.id,d.image_name);
    document.getElementById('imgBody').innerHTML='<img src="'+d.dataUrl+'" alt="">';
    document.getElementById('imgTitle').textContent=d.image_name;
    document.getElementById('imgMeta').textContent='Row ID: '+d.id;
    document.getElementById('btnNext').disabled=false;
    document.getElementById('btnSkip').disabled=false;
    document.getElementById('btnSave').disabled=false;
    log('info','🖼 Loaded row ID '+d.id+' — '+d.image_name);
    return true;
  }).catch(e=>{ log('err','❌ Fetch error: '+e.message); return false; });
}

// ── Classify ───────────────────────────────────────────────────────────────
function classify() {
  if(!currentId||!currentB64){log('warn','⚠ No image loaded');return Promise.resolve();}
  document.getElementById('resGroup').innerHTML   ='<span class="spinner"></span>';
  document.getElementById('resSubgroup').innerHTML='<span class="spinner"></span>';
  document.getElementById('resTags').innerHTML    ='<span class="spinner"></span>';
  log('info','🤖 Row '+currentId+' → GPT-4o Vision…');

  var fd=new FormData();
  fd.append('action','classify');
  fd.append('id',currentId);
  fd.append('base64',currentB64);
  fd.append('mime',currentMime);

  return fetch(location.href,{method:'POST',body:fd}).then(r=>r.text()).then(raw=>{
    var d; try{d=JSON.parse(raw);}catch(e){
      log('err','❌ Server non-JSON: '+raw.substring(0,200)); clearResults(); return;
    }
    if(!d.success){ log('err','❌ '+d.message); clearResults(); return; }

    populateIndustrySelect(d.industry_id);
    loadNichesFor(d.industry_id, d.niche_id);

    // Status pills
    var indPillClass = d.industry_status==='others' ? 'pill-amber' : (d.industry_status==='added' ? 'pill-new' : 'pill-db');
    var indPillText  = d.industry_status==='others' ? 'Others' : (d.industry_status==='added' ? 'New' : 'DB');
    document.getElementById('indStatus').innerHTML   = '<span class="pill '+indPillClass+'">'+indPillText+'</span>';
    document.getElementById('nicheStatus').innerHTML = '<span class="pill '+(d.niche_status==='added'?'pill-new':'pill-db')+'">'+(d.niche_status==='added'?'New':'DB')+'</span>';

    document.getElementById('resGroup').textContent   = d.group    || '—';
    document.getElementById('resSubgroup').textContent= d.subgroup || '—';
    document.getElementById('resDesc').textContent    = d.description || '—';

    document.getElementById('resMood').innerHTML = d.mood
      ? '<span class="mood-badge">'+escHtml(d.mood)+'</span>' : '<span class="empty-val">—</span>';

    var tw=document.getElementById('resTags'); tw.innerHTML='';
    if(d.tags&&d.tags.length){ d.tags.forEach(function(t){ tw.appendChild(makeTagChip(t)); }); }
    else tw.innerHTML='<span class="empty-val">No tags returned</span>';

    var uw=document.getElementById('resUseCases'); uw.innerHTML='';
    if(d.use_cases&&d.use_cases.length){ d.use_cases.forEach(u=>{ var div=document.createElement('div');div.className='use-case-item';div.textContent=u;uw.appendChild(div); }); }
    else uw.innerHTML='<span class="empty-val">—</span>';

    aiState.group       = d.group       || '';
    aiState.subgroup    = d.subgroup    || '';
    aiState.mood        = d.mood        || '';
    aiState.use_cases   = d.use_cases   || [];
    aiState.tags        = d.tags        || [];
    aiState.description = d.description || '';

    log('ok','✅ Industry: '+d.industry_name+' (ID #'+d.industry_id+') ['+d.industry_status+']');
    log('ok','   Niche:   '+d.niche_name+' (ID #'+d.niche_id+') ['+d.niche_status+']');
    if(d.industry_status==='others') log('warn','   ⚠ GPT picked "'+d.group+'" — not in list, defaulted to Others');
    log('ok','   Mood:    '+d.mood);
    log('ok','   Tags:    '+(d.tags||[]).join(', '));
    log('info','   Use cases: '+(d.use_cases||[]).join(' | '));
    log('info','👆 Review above, adjust dropdowns if needed, then click Save.');

    loadStats();
  }).catch(e=>{ log('err','❌ Classify error: '+e.message); clearResults(); });
}

// ── Run helpers ────────────────────────────────────────────────────────────
function runNext()   { loadImage(0).then(ok=>{ if(ok) classify(); }); }
function runManual() {
  var id=parseInt(document.getElementById('manualId').value,10);
  if(!id){log('warn','⚠ Enter a valid row ID');return;}
  loadImage(id).then(ok=>{ if(ok) classify(); });
}

// ── Skip ───────────────────────────────────────────────────────────────────
function skipCurrent(){
  if(!currentId)return;
  log('warn','⤵ Skipping row ID '+currentId);
  fetch('?action=skip&id='+currentId);
  currentId=null; currentB64=null; currentMime=null;
  clearResults(); hideBadge();
  document.getElementById('imgBody').innerHTML='<div class="img-placeholder">Skipped. Click Tag Next Image to continue.</div>';
  document.getElementById('imgTitle').textContent='Skipped';
  document.getElementById('btnNext').disabled=true;
  document.getElementById('btnSkip').disabled=true;
  document.getElementById('btnSave').disabled=true;
}

// ── Tag chip ───────────────────────────────────────────────────────────────
function makeTagChip(text) {
  var span=document.createElement('span');
  span.className='tag';
  var txt=document.createElement('span'); txt.textContent=text;
  var del=document.createElement('button'); del.type='button'; del.textContent='×';
  del.title='Remove tag';
  del.style.cssText='background:#c62828;border:none;color:#fff;cursor:pointer;font-size:13px;font-weight:700;line-height:1;padding:0 4px;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;';
  del.onmouseover=function(){this.style.background='#8b0000';};
  del.onmouseout =function(){this.style.background='#c62828';};
  del.onclick=function(){ span.remove(); aiState.tags=aiState.tags.filter(function(t){return t!==text;}); };
  span.appendChild(txt); span.appendChild(del);
  return span;
}

// ── Add tag ────────────────────────────────────────────────────────────────
function addTag() {
  var input=document.getElementById('addTagInput');
  var val=input.value.trim(); if(!val)return;
  if(aiState.tags.indexOf(val)!==-1){input.value='';return;}
  aiState.tags.push(val);
  var tw=document.getElementById('resTags');
  var empty=tw.querySelector('.empty-val'); if(empty)empty.remove();
  tw.appendChild(makeTagChip(val));
  input.value=''; input.focus();
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>

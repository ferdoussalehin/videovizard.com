<?php
/**
 * generate_autotags_batch.php — Automated Batch Image Tagger
 * Processes all images where tag_flag IS NULL or = 0
 * Industry fallback: exact match → Lifestyle → General
 * Niche    fallback: exact match → auto-create subgroup → General
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/autotag_batch.log');
error_reporting(E_ALL);
set_time_limit(0);
ob_start();

include __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';
$openai_key = $apiKey;

define('IMG_DIR',   __DIR__ . '/podcast_images/');
define('VID_DIR',   __DIR__ . '/podcast_videos/');
define('THUMB_DIR', __DIR__ . '/podcast_videos/');  // thumbnails stored alongside videos

// ── Ensure columns ────────────────────────────────────────────────────────────
foreach ([
    "tag_flag              TINYINT(1)   NOT NULL DEFAULT 0",
    "industry_id           INT          DEFAULT NULL",
    "niche_id              INT          DEFAULT NULL",
    "ai_tags               TEXT         DEFAULT NULL",
    "ai_group              VARCHAR(255) DEFAULT NULL",
    "ai_subgroup           VARCHAR(255) DEFAULT NULL",
    "ai_mood               VARCHAR(255) DEFAULT NULL",
    "ai_usecases           TEXT         DEFAULT NULL",
    "ai_description        TEXT         DEFAULT NULL",
    "natural_language_tags TEXT         DEFAULT NULL",
    "tagged_at             DATETIME     DEFAULT NULL",
] as $def) {
    $col = explode(' ', trim($def))[0];
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0)
        mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN $def");
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function send_json($data) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function toTitleCase($s) {
    $low   = ['a','an','the','and','but','or','for','nor','on','at','to','by','in','of','up','as'];
    $words = explode(' ', strtolower(trim($s)));
    $out   = [];
    foreach ($words as $i => $w)
        $out[] = ($i === 0 || !in_array($w, $low)) ? ucfirst($w) : $w;
    return implode(' ', $out);
}

function now_str() { return date('Y-m-d H:i:s'); }

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

function callOpenAIVision($apiKey, $system, $b64, $mime, $max_tokens = 700) {
    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64,$b64", 'detail' => 'low']],
                ['type' => 'text',      'text'      => 'Analyze this image and return the JSON as instructed.'],
            ]],
        ],
        'max_tokens'  => $max_tokens,
        'temperature' => 0.1,
    ]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) { error_log("[batch] Vision cURL: $err"); return null; }
    $d = json_decode($resp, true);
    if (!isset($d['choices'][0]['message']['content'])) { error_log("[batch] Vision: $resp"); return null; }
    $raw = trim($d['choices'][0]['message']['content']);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);
    return json_decode($raw, true);
}

function ensureIndustry($conn, $name) {
    $esc = mysqli_real_escape_string($conn, $name);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_master_industries WHERE LOWER(industry_desc)=LOWER('$esc') LIMIT 1"));
    if ($row) return (int)$row['id'];
    $now = now_str();
    mysqli_query($conn, "INSERT INTO hdb_master_industries (industry_desc,created_at,updated_at) VALUES ('$esc','$now','$now')");
    return (int)mysqli_insert_id($conn);
}

function ensureNiche($conn, $industry_id, $name) {
    $esc = mysqli_real_escape_string($conn, $name);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_master_niches WHERE master_industry_id=$industry_id AND LOWER(niche_desc)=LOWER('$esc') LIMIT 1"));
    if ($row) return (int)$row['id'];
    $now = now_str();
    mysqli_query($conn,
        "INSERT INTO hdb_master_niches (master_industry_id,niche_desc,created_at,updated_at)
         VALUES ($industry_id,'$esc','$now','$now')");
    return (int)mysqli_insert_id($conn);
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── test_ffmpeg: run FFmpeg on one video and capture full stderr ──────────────
if ($action === 'test_ffmpeg') {
    $res = mysqli_query($conn, "SELECT id, image_name FROM hdb_image_data WHERE media_type='video' AND (tag_flag IS NULL OR tag_flag=0 OR tag_flag='') ORDER BY id ASC LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) send_json(['success'=>false,'error'=>'No untagged video rows']);
    $row       = mysqli_fetch_assoc($res);
    $videoFile = $row['image_name'];
    $videoPath = VID_DIR . $videoFile;
    $outThumb  = VID_DIR . pathinfo($videoFile, PATHINFO_FILENAME) . '_thumbtest.jpg';
    @unlink($outThumb);

    $ffmpegBin    = trim(shell_exec('which ffmpeg 2>&1') ?: '/usr/bin/ffmpeg');
    $shell_test   = shell_exec('echo shell_exec_works 2>&1');
    $video_exists = file_exists($videoPath);
    $dir_writable = is_writable(VID_DIR);

    // Run WITH stderr captured
    $cmd = $ffmpegBin
         . ' -y -ss 00:00:00'
         . ' -i '    . escapeshellarg($videoPath)
         . ' -vframes 1 -q:v 2 -vf "scale=800:-1"'
         . ' '       . escapeshellarg($outThumb)
         . ' 2>&1';
    $ffmpeg_output = shell_exec($cmd);
    $thumb_created = file_exists($outThumb);
    $thumb_size    = $thumb_created ? filesize($outThumb) : 0;
    if ($thumb_created) @unlink($outThumb);

    send_json([
        'success'       => true,
        'video_id'      => (int)$row['id'],
        'video_file'    => $videoFile,
        'video_path'    => $videoPath,
        'video_exists'  => $video_exists,
        'vid_dir'       => VID_DIR,
        'dir_writable'  => $dir_writable,
        'ffmpeg_bin'    => $ffmpegBin,
        'shell_works'   => trim($shell_test ?? ''),
        'ffmpeg_output' => $ffmpeg_output,
        'thumb_created' => $thumb_created,
        'thumb_size'    => $thumb_size,
        'cmd'           => $cmd,
    ]);
}

// ── debug_videos: show raw DB rows for untagged videos ───────────────────────
if ($action === 'debug_videos') {
    $limit = (int)($_GET['limit'] ?? 10);
    $res = mysqli_query($conn, "SELECT id, image_name, media_type, thumbnail, tag_flag
                                FROM hdb_image_data
                                WHERE media_type='video'
                                ORDER BY id ASC LIMIT $limit");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $vPath = VID_DIR . $r['image_name'];
        $tFile = trim($r['thumbnail'] ?? '');
        // Check all candidate thumb paths
        $candidates = $tFile ? [
            VID_DIR . $tFile,
            VID_DIR . basename($tFile),
            IMG_DIR . $tFile,
            IMG_DIR . basename($tFile),
            __DIR__ . '/' . ltrim($tFile, '/'),
        ] : [];
        $foundThumb = '';
        foreach ($candidates as $c) { if (file_exists($c)) { $foundThumb = $c; break; } }
        $rows[] = [
            'id'           => (int)$r['id'],
            'image_name'   => $r['image_name'],
            'media_type'   => $r['media_type'],
            'thumbnail_col'=> $tFile,
            'tag_flag'     => $r['tag_flag'],
            'video_exists' => file_exists($vPath),
            'video_path'   => $vPath,
            'thumb_found'  => $foundThumb ?: '(not found)',
            'thumb_tried'  => $candidates,
        ];
    }
    send_json(['success' => true, 'rows' => $rows,
               'vid_dir' => VID_DIR, 'img_dir' => IMG_DIR]);
}

// ── get_stats ─────────────────────────────────────────────────────────────────
if ($action === 'get_stats') {
    $total_img   = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image'"))['c'];
    $tagged_img  = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image' AND tag_flag=1"))['c'];
    $pending_img = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='image' AND (tag_flag IS NULL OR tag_flag=0 OR tag_flag='')"))['c'];

    $total_vid   = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='video'"))['c'];
    $tagged_vid  = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='video' AND tag_flag=1"))['c'];
    $pending_vid = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='video' AND (tag_flag IS NULL OR tag_flag=0 OR tag_flag='')"))['c'];

    send_json([
        'success'     => true,
        'total'       => $total_img + $total_vid,
        'tagged'      => $tagged_img + $tagged_vid,
        'pending'     => $pending_img + $pending_vid,
        'total_img'   => $total_img,   'tagged_img'   => $tagged_img,   'pending_img'   => $pending_img,
        'total_vid'   => $total_vid,   'tagged_vid'   => $tagged_vid,   'pending_vid'   => $pending_vid,
    ]);
}

// ── save_video_thumb: receive base64 frame from browser, save as thumbnail ─────
if ($action === 'save_video_thumb') {
    $id        = (int)($_POST['id']         ?? 0);
    $videoFile =       $_POST['video_file'] ?? '';
    $base64    =       $_POST['base64']     ?? '';
    if (!$id || empty($videoFile) || empty($base64)) {
        send_json(['success' => false, 'message' => 'Missing parameters']);
    }
    // Strip data URL prefix if present
    if (strpos($base64, ',') !== false) $base64 = explode(',', $base64, 2)[1];

    $decoded = base64_decode($base64);
    if (!$decoded || strlen($decoded) < 500) {
        send_json(['success' => false, 'message' => 'Invalid or empty frame data']);
    }

    $thumbDir  = __DIR__ . '/podcast_thumbnails/';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

    $thumbName = pathinfo($videoFile, PATHINFO_FILENAME) . '_thumb.jpg';
    $thumbPath = $thumbDir . $thumbName;

    // Resize to max 800px wide (for AI vision) using GD
    $src = @imagecreatefromstring($decoded);
    if ($src) {
        $origW = imagesx($src);
        $origH = imagesy($src);
        $ratio = min(800 / $origW, 800 / $origH, 1);
        $newW  = (int)round($origW * $ratio);
        $newH  = (int)round($origH * $ratio);
        $dst   = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagejpeg($dst, $thumbPath, 85);
        imagedestroy($src);
        imagedestroy($dst);
    } else {
        file_put_contents($thumbPath, $decoded);
    }

    if (!file_exists($thumbPath) || filesize($thumbPath) < 100) {
        send_json(['success' => false, 'message' => 'Failed to write thumbnail to disk: ' . $thumbPath]);
    }

    // Update thumbnail column in DB
    $safeThumb = mysqli_real_escape_string($conn, $thumbName);
    mysqli_query($conn, "UPDATE hdb_image_data SET thumbnail='$safeThumb' WHERE id=$id");
    error_log("[batch-video] Browser-captured thumbnail saved: $thumbName for id=$id");

    send_json(['success' => true, 'thumbnail' => $thumbName, 'path' => $thumbPath]);
}

// ── get_next ──────────────────────────────────────────────────────────────────
if ($action === 'get_next') {
    $afterId    = (int)($_GET['after_id'] ?? 0);
    $modeFilter = $_GET['mode'] ?? 'all'; // 'all' | 'images' | 'videos'

    // Build WHERE clause based on mode filter
    if ($modeFilter === 'images') {
        $mediaWhere = "media_type='image'";
    } elseif ($modeFilter === 'videos') {
        $mediaWhere = "media_type='video'";
    } else {
        $mediaWhere = "(media_type='image' OR media_type='video')";
    }

    $sql = "SELECT id, image_name, media_type, thumbnail FROM hdb_image_data
            WHERE $mediaWhere AND (tag_flag IS NULL OR tag_flag=0 OR tag_flag='')
            " . ($afterId ? "AND id > $afterId" : "") . "
            ORDER BY id ASC LIMIT 1";

    $res = mysqli_query($conn, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        send_json(['success' => false, 'message' => 'No more untagged items.']);
    }
    $row       = mysqli_fetch_assoc($res);
    $mediaType = $row['media_type'] ?? 'image';

    // ── IMAGE branch ──────────────────────────────────────────────────────────
    if ($mediaType === 'image') {
        $path = IMG_DIR . $row['image_name'];
        if (!file_exists($path)) {
            mysqli_query($conn, "UPDATE hdb_image_data SET tag_flag=2 WHERE id=" . (int)$row['id']);
            send_json(['success' => false, 'skip' => true, 'id' => (int)$row['id'],
                       'message' => 'Image file not on disk: ' . $row['image_name']]);
        }
        $ext      = strtolower(pathinfo($row['image_name'], PATHINFO_EXTENSION));
        $mimeMap  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                     'webp' => 'image/webp', 'gif' => 'image/gif'];
        $origMime = $mimeMap[$ext] ?? 'image/jpeg';
        $imgData  = resize_image_for_api($path, $origMime, 800);
        if (!$imgData) $imgData = file_get_contents($path);
        send_json([
            'success'    => true,
            'id'         => (int)$row['id'],
            'image_name' => $row['image_name'],
            'media_type' => 'image',
            'base64'     => base64_encode($imgData),
            'mime'       => 'image/jpeg',
        ]);
    }

    // ── VIDEO branch — return video URL for browser-side frame capture ─────────
    // The browser loads the video, seeks to 10%, draws to canvas, sends base64 back.
    // No FFmpeg needed. PHP just needs to confirm the file exists.
    $videoFile = $row['image_name'];
    $videoPath = VID_DIR . $videoFile;
    $thumbFile = trim($row['thumbnail'] ?? '');

    if (!file_exists($videoPath)) {
        send_json(['success' => false, 'skip' => true, 'id' => (int)$row['id'],
                   'message' => "Video file not found on disk: $videoFile"]);
    }

    // Check if thumbnail already exists on disk — if yes, use it directly
    $existingThumb = '';
    if ($thumbFile) {
        $thumbDir = __DIR__ . '/podcast_thumbnails/';
        $candidates = [
            $thumbDir . $thumbFile,
            $thumbDir . basename($thumbFile),
            VID_DIR   . $thumbFile,
            VID_DIR   . basename($thumbFile),
            IMG_DIR   . $thumbFile,
            IMG_DIR   . basename($thumbFile),
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $existingThumb = $c; break; }
        }
    }

    if ($existingThumb) {
        // Thumbnail already on disk — load it and send for AI tagging
        $ext      = strtolower(pathinfo($existingThumb, PATHINFO_EXTENSION));
        $mimeMap  = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        $origMime = $mimeMap[$ext] ?? 'image/jpeg';
        $imgData  = resize_image_for_api($existingThumb, $origMime, 800);
        if (!$imgData) $imgData = @file_get_contents($existingThumb);
        if ($imgData) {
            send_json([
                'success'          => true,
                'id'               => (int)$row['id'],
                'image_name'       => $videoFile,
                'thumbnail'        => $thumbFile,
                'media_type'       => 'video',
                'needs_capture'    => false,   // thumb already exists
                'base64'           => base64_encode($imgData),
                'mime'             => 'image/jpeg',
            ]);
        }
    }

    // No existing thumbnail — tell JS to capture a frame from the video
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . rtrim(dirname($_SERVER['REQUEST_URI']), '/');

    send_json([
        'success'       => true,
        'id'            => (int)$row['id'],
        'image_name'    => $videoFile,
        'media_type'    => 'video',
        'needs_capture' => true,    // JS must capture frame and call save_video_thumb
        'video_url'     => 'podcast_videos/' . rawurlencode($videoFile),
        'mime'          => 'image/jpeg',
    ]);
}

// ── process_one ───────────────────────────────────────────────────────────────
if ($action === 'process_one') {
    $imageId   = (int)($_POST['id']         ?? 0);
    $base64    =       $_POST['base64']     ?? '';
    $mime      =       $_POST['mime']       ?? 'image/jpeg';
    $mediaType =       $_POST['media_type'] ?? 'image';  // 'image' or 'video'
    if (!$imageId || empty($base64)) send_json(['success' => false, 'message' => 'Missing id or base64']);

    // Load industries
    $industry_list = [];
    $r = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    while ($row = mysqli_fetch_assoc($r))
        $industry_list[(int)$row['id']] = trim($row['industry_desc']);

    $vision_system =
        "Analyze the provided image and generate structured metadata.\n"
      . "You MUST pick the industry (group) from this exact list — do not invent new ones:\n"
      . "[" . implode(', ', $industry_list) . "]\n"
      . "If nothing fits reasonably well, use exactly: Others\n\n"
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
      . "  • WHO + WHAT: name exactly who/what is in the image and what they are doing (e.g. 'woman sitting on metal guardrail', 'goat standing beside seated woman')\n"
      . "  • WHO + WHERE: subject placed in a specific location (e.g. 'woman resting on roadside barrier', 'sheep grazing on hillside pasture')\n"
      . "  • OBJECT + ACTION: specific objects doing or being used for something (e.g. 'guardrail used as seating', 'farm animals near road')\n"
      . "  • COMBINATIONS: two specific subjects together (e.g. 'woman and goat in rural field', 'livestock beside resting traveller')\n"
      . "  • CONTEXT: only if highly specific (e.g. 'woman resting during countryside hike', 'tourist stopped at rural roadside')\n"
      . "HARD BANNED — any tag matching these patterns will be rejected:\n"
      . "  ✗ Atmosphere/setting descriptors with no subject: 'outdoor rural setting', 'natural landscape view', 'peaceful countryside scene', 'scenic background'\n"
      . "  ✗ Lighting/aesthetics: 'sunset lighting', 'golden hour', 'soft natural light', 'bright background', 'well-lit scene'\n"
      . "  ✗ Abstract concepts: 'connection with nature', 'enjoying nature', 'harmony with environment', 'freedom in nature'\n"
      . "  ✗ Vague lifestyle: 'relaxed outdoor lifestyle', 'casual clothing in nature', 'comfortable footwear', 'casual summer outfit'\n"
      . "  ✗ Single adjective + noun with no action: 'natural landscape', 'rural scene', 'farm setting'\n"
      . "TEST every tag before including it: ask 'does this phrase uniquely describe something VISIBLE and SPECIFIC in this image?' If no — cut it.";

    $vision_result = callOpenAIVision($openai_key, $vision_system, $base64, $mime, 700);
    if (!$vision_result || !isset($vision_result['group'])) {
        send_json(['success' => false, 'message' => 'Vision API failed — check API key or image.']);
    }

    $ai_group    = toTitleCase(trim($vision_result['group']));
    $ai_subgroup = toTitleCase(trim($vision_result['subgroup'] ?? ''));
    $ai_desc     = trim($vision_result['description'] ?? '');
    $ai_mood     = trim($vision_result['mood']        ?? '');
    $ai_usecases = (array)($vision_result['use_cases'] ?? []);
    $ai_tags     = array_map('trim', (array)($vision_result['tags'] ?? []));

    // ── Match Industry ────────────────────────────────────────────────────────
    // 1. Exact match
    $matched_industry_id   = null;
    $matched_industry_name = '';
    $industry_status       = '';

    foreach ($industry_list as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($ai_group)) {
            $matched_industry_id   = $id;
            $matched_industry_name = $desc;
            $industry_status       = 'matched';
            break;
        }
    }

    // 2. Fallback → Lifestyle (if it exists in DB)
    if (!$matched_industry_id) {
        foreach ($industry_list as $id => $desc) {
            if (strtolower(trim($desc)) === 'lifestyle') {
                $matched_industry_id   = $id;
                $matched_industry_name = $desc;
                $industry_status       = 'lifestyle_fallback';
                break;
            }
        }
    }

    // 3. Final fallback → General (create if needed)
    if (!$matched_industry_id) {
        $matched_industry_id   = ensureIndustry($conn, 'General');
        $matched_industry_name = 'General';
        $industry_status       = 'general_fallback';
        $industry_list[$matched_industry_id] = 'General';
    }

    // ── Match / Create Niche ──────────────────────────────────────────────────
    $existing_niches = [];
    $r = mysqli_query($conn,
        "SELECT id, niche_desc FROM hdb_master_niches WHERE master_industry_id=$matched_industry_id ORDER BY niche_desc ASC");
    while ($row = mysqli_fetch_assoc($r))
        $existing_niches[(int)$row['id']] = trim($row['niche_desc']);

    $ai_niche_name      = $ai_subgroup ?: $ai_group;
    $matched_niche_id   = null;
    $matched_niche_name = '';
    $niche_status       = '';

    // Exact match
    foreach ($existing_niches as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($ai_niche_name)) {
            $matched_niche_id   = $id;
            $matched_niche_name = $desc;
            $niche_status       = 'matched';
            break;
        }
    }

    // Auto-create if subgroup looks valid
    if (!$matched_niche_id && $ai_niche_name && strtolower($ai_niche_name) !== 'others') {
        $matched_niche_id   = ensureNiche($conn, $matched_industry_id, $ai_niche_name);
        $matched_niche_name = $ai_niche_name;
        $niche_status       = 'created';
    }

    // Fallback → General niche
    if (!$matched_niche_id) {
        $matched_niche_id   = ensureNiche($conn, $matched_industry_id, 'General');
        $matched_niche_name = 'General';
        $niche_status       = 'general_fallback';
    }

    // ── Build natural_language_tags ───────────────────────────────────────────
    $nl_parts = [];
    if ($ai_desc)     $nl_parts[] = $ai_desc;
    if ($ai_group)    $nl_parts[] = $ai_group;
    if ($ai_subgroup) $nl_parts[] = $ai_subgroup;
    if ($ai_mood)     $nl_parts[] = $ai_mood;
    foreach ($ai_tags     as $t) if (trim($t)) $nl_parts[] = trim($t);
    foreach ($ai_usecases as $u) if (trim($u)) $nl_parts[] = trim($u);
    $nl_tags_str = implode(' | ', $nl_parts);

    // ── Generate embedding ────────────────────────────────────────────────────
    $embedding_sql = '';
    if (!empty($nl_tags_str)) {
        $embed_payload = json_encode([
            'model'      => 'text-embedding-3-large',
            'input'      => $nl_tags_str,
            'dimensions' => 3072,
        ]);
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS     => $embed_payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $embed_resp = curl_exec($ch); $embed_err = curl_error($ch); curl_close($ch);
        if (!$embed_err) {
            $embed_data = json_decode($embed_resp, true);
            if (isset($embed_data['data'][0]['embedding'])) {
                $vec           = $embed_data['data'][0]['embedding'];
                $embedding_sql = ", embedding = '" . mysqli_real_escape_string($conn, json_encode($vec)) . "'";
            }
        }
    }

    // ── Save to DB ────────────────────────────────────────────────────────────
    $esc_group    = mysqli_real_escape_string($conn, $ai_group);
    $esc_subgroup = mysqli_real_escape_string($conn, $ai_subgroup);
    $esc_mood     = mysqli_real_escape_string($conn, $ai_mood);
    $esc_desc     = mysqli_real_escape_string($conn, $ai_desc);
    $esc_nl       = mysqli_real_escape_string($conn, $nl_tags_str);
    $tags_json    = mysqli_real_escape_string($conn, json_encode($ai_tags));
    $use_json     = mysqli_real_escape_string($conn, json_encode($ai_usecases));
    $now          = now_str();

    mysqli_query($conn,
        "UPDATE hdb_image_data SET
            industry_id           = $matched_industry_id,
            niche_id              = $matched_niche_id,
            ai_group              = '$esc_group',
            ai_subgroup           = '$esc_subgroup',
            ai_mood               = '$esc_mood',
            ai_usecases           = '$use_json',
            ai_description        = '$esc_desc',
            ai_tags               = '$tags_json',
            natural_language_tags = '$esc_nl',
            tag_flag              = 1,
            tagged_at             = '$now'
            $embedding_sql
         WHERE id = $imageId"
    );

    if (mysqli_error($conn)) send_json(['success' => false, 'message' => mysqli_error($conn)]);

    error_log("[batch] Saved id=$imageId industry=$matched_industry_name niche=$matched_niche_name embedded=" . (!empty($embedding_sql) ? 'yes' : 'no'));

    send_json([
        'success'         => true,
        'id'              => $imageId,
        'media_type'      => $mediaType,
        'industry_name'   => $matched_industry_name,
        'industry_status' => $industry_status,
        'niche_name'      => $matched_niche_name,
        'niche_status'    => $niche_status,
        'group'           => $ai_group,
        'subgroup'        => $ai_subgroup,
        'description'     => $ai_desc,
        'mood'            => $ai_mood,
        'tags'            => $ai_tags,
        'embedded'        => !empty($embedding_sql),
    ]);
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Batch Auto-Tagger</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#e8f0fb; --bg2:#d4e4f7; --card:#ffffff; --card2:#f0f6ff;
  --bdr:#b8d0ee; --bdr2:#93b8e8;
  --dk:#0a1f4e; --dk2:#0d2d6b; --dk3:#1a3f8a;
  --acc:#1565c0; --acc2:#1976d2; --acc-lt:#e3eeff; --acc-glow:rgba(21,101,192,0.18);
  --grn:#1b8a4a; --grn-lt:#e6f4ed;
  --amber:#c07000; --amber-lt:#fff3e0;
  --red:#c62828; --red-lt:#fdecea;
  --txt:#0a1f4e; --txt2:#2d5a8e; --mut:#6b89b4;
  --shadow:0 4px 24px rgba(10,31,78,0.10);
  --shadow-lg:0 8px 40px rgba(10,31,78,0.14);
  font-family:'Segoe UI',system-ui,sans-serif;
}
body{background:var(--bg);color:var(--txt);min-height:100vh;padding:24px 20px;
  background-image:
    radial-gradient(ellipse 70% 50% at 5% 0%,rgba(21,101,192,0.07) 0%,transparent 60%),
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
.badge-dot{width:6px;height:6px;background:#4fc3f7;border-radius:50%;animation:blink 2s ease-in-out infinite;}
@keyframes blink{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.3;transform:scale(.5);}}

/* ── Stats ── */
.stats-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.stat{background:var(--card);border:1px solid var(--bdr);border-radius:12px;
  padding:13px 20px;min-width:110px;text-align:center;box-shadow:var(--shadow);}
.stat strong{display:block;font-size:28px;font-weight:700;line-height:1.1;}
.stat span{font-size:11px;color:var(--mut);margin-top:2px;display:block;}
.s-total   strong{color:var(--acc);}
.s-tagged  strong{color:var(--grn);}
.s-pending strong{color:var(--amber);}
.s-done    strong{color:var(--grn);}
.s-errors  strong{color:var(--red);}
.s-skipped strong{color:var(--mut);}

/* ── Controls card ── */
.controls-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;
  padding:18px 20px;margin-bottom:18px;box-shadow:var(--shadow);}
.controls-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.controls-label{font-size:10px;font-weight:800;color:var(--mut);letter-spacing:1px;
  text-transform:uppercase;margin-bottom:12px;}
.btn{padding:10px 22px;border-radius:9px;border:none;font-size:13px;font-weight:700;
  cursor:pointer;font-family:inherit;transition:all .15s;letter-spacing:.2px;
  display:inline-flex;align-items:center;gap:7px;}
.btn:hover:not(:disabled){filter:brightness(1.08);transform:translateY(-1px);}
.btn:disabled{opacity:.32;cursor:not-allowed;transform:none;}
.btn-start{background:linear-gradient(135deg,#1b8a4a,#0d5c2e);color:#fff;box-shadow:0 3px 14px rgba(27,138,74,0.28);}
.btn-pause{background:linear-gradient(135deg,var(--amber),#8a5000);color:#fff;box-shadow:0 3px 14px rgba(192,112,0,0.28);}
.btn-stop {background:linear-gradient(135deg,var(--red),#8b0000);color:#fff;box-shadow:0 3px 14px rgba(198,40,40,0.28);}
.delay-wrap{margin-left:auto;display:flex;align-items:center;gap:8px;}
.delay-label{font-size:12px;color:var(--txt2);font-weight:600;white-space:nowrap;}
.delay-select{padding:8px 10px;border:1.5px solid var(--bdr);border-radius:8px;
  background:var(--card2);color:var(--txt);font-family:inherit;font-size:13px;cursor:pointer;}
.delay-select:focus{outline:none;border-color:var(--acc);}

/* ── Progress ── */
.progress-section{margin-top:16px;display:none;}
.progress-section.show{display:block;}
.progress-outer{background:var(--bg2);border-radius:100px;height:13px;overflow:hidden;
  border:1px solid var(--bdr);box-shadow:inset 0 1px 3px rgba(0,0,0,0.08);}
.progress-inner{height:100%;background:linear-gradient(90deg,var(--grn),#52d88a);
  border-radius:100px;transition:width .5s ease;width:0%;}
.progress-text{font-size:12px;color:var(--txt2);margin-top:6px;font-weight:600;text-align:center;}

/* ── Status bar ── */
.status-bar{border-radius:10px;padding:11px 18px;margin-bottom:18px;
  display:none;align-items:center;gap:12px;box-shadow:var(--shadow);}
.status-bar.show{display:flex;}
.status-bar.s-running{background:linear-gradient(90deg,#0a3d1e,#145c35);}
.status-bar.s-paused {background:linear-gradient(90deg,#4a3000,#7a5000);}
.status-bar.s-stopped{background:linear-gradient(90deg,#3d0a0a,#6b1414);}
.status-bar.s-done   {background:linear-gradient(90deg,#0a3d1e,#145c35);}
.status-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.dot-running{background:#52d88a;animation:blink 1.2s ease-in-out infinite;}
.dot-paused {background:#ffd54f;}
.dot-stopped{background:#ef9a9a;}
.dot-done   {background:#52d88a;}
.status-label{font-size:13px;font-weight:700;color:#fff;white-space:nowrap;}
.status-img{font-size:11px;color:rgba(255,255,255,0.55);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* ── Main grid ── */
.main-grid{display:grid;grid-template-columns:1fr 430px;gap:16px;}
@media(max-width:940px){.main-grid{grid-template-columns:1fr;}}

/* ── Image card ── */
.img-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.card-header{padding:11px 16px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;background:var(--card2);}
.card-title{font-size:13px;font-weight:700;color:var(--dk);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%;}
.card-meta{font-size:11px;color:var(--mut);white-space:nowrap;}
.img-body{background:var(--bg2);min-height:330px;display:flex;align-items:center;justify-content:center;}
.img-body img{max-width:100%;max-height:400px;object-fit:contain;display:block;}
.img-placeholder{color:var(--mut);font-size:13px;text-align:center;padding:40px;line-height:2.8;}
.img-placeholder strong{color:var(--acc);}

/* ── Result card ── */
.result-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
.result-body{padding:16px;display:flex;flex-direction:column;gap:13px;}
.res-block{display:flex;flex-direction:column;gap:4px;}
.res-label{font-size:10px;font-weight:800;color:var(--mut);letter-spacing:1.1px;text-transform:uppercase;}
.res-value{font-size:14px;font-weight:600;color:var(--txt);}
.res-desc{font-size:12px;color:var(--txt2);line-height:1.65;font-style:italic;}
.empty-val{color:var(--mut);font-style:italic;font-weight:400;font-size:13px;}
.pill{display:inline-block;font-size:10px;font-weight:700;padding:2px 9px;border-radius:100px;vertical-align:middle;margin-left:5px;}
.pill-matched  {background:var(--acc-lt);color:var(--acc);}
.pill-created  {background:var(--grn-lt);color:var(--grn);}
.pill-lifestyle{background:var(--amber-lt);color:var(--amber);}
.pill-general  {background:var(--red-lt);color:var(--red);}
.mood-badge{display:inline-block;background:linear-gradient(135deg,var(--dk),var(--dk3));
  color:#fff;border-radius:8px;padding:4px 14px;font-size:12px;font-weight:600;}
.tags-wrap{display:flex;flex-wrap:wrap;gap:5px;margin-top:2px;}
.tag{background:#deeaff;color:var(--acc);border-radius:20px;padding:3px 11px;
  font-size:11px;font-weight:500;border:1px solid rgba(21,101,192,0.15);}
.divider{border:none;border-top:1px solid var(--bdr);}

/* ── Log card ── */
.log-card{background:var(--card);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-top:16px;}
.log-inner{padding:12px 16px;}
.log-box{background:var(--dk);border:1px solid var(--bdr2);border-radius:9px;
  padding:12px 14px;font-size:11.5px;line-height:2;font-family:'Cascadia Code','Fira Code',monospace;
  color:rgba(255,255,255,0.4);height:220px;overflow-y:auto;}
.log-ok  {color:#66bb6a;}
.log-err {color:#ef9a9a;}
.log-warn{color:#ffd54f;}
.log-info{color:#90caf9;}
.log-head{color:#ce93d8;font-weight:700;}
.log-actions{display:flex;justify-content:flex-end;padding:8px 16px 12px;border-top:1px solid var(--bdr);}
.btn-sm{padding:5px 13px;font-size:11px;border-radius:6px;border:1.5px solid var(--bdr2);
  background:var(--card2);color:var(--acc);cursor:pointer;font-family:inherit;font-weight:700;}
.btn-sm:hover{background:var(--acc-lt);}

/* ── Spinner ── */
.spinner{display:inline-block;width:10px;height:10px;
  border:2px solid rgba(255,255,255,0.2);border-top-color:#fff;
  border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<!-- Header -->
<div class="page-header">
  <div>
    <h1>⚡ Batch Auto-Tagger <span class="badge"><span class="badge-dot"></span>GPT-4o Vision</span></h1>
    <div class="header-sub">Fully automated · Images + Videos · Auto-generates thumbnails via FFmpeg · Industry · Niche · Embeddings</div>
  </div>
</div>

<!-- Stats -->
<div class="stats-bar">
  <div class="stat s-total"  ><strong id="statTotal"  >—</strong><span>Total</span></div>
  <div class="stat s-tagged" ><strong id="statTagged" >—</strong><span>Tagged</span></div>
  <div class="stat s-pending"><strong id="statPending">—</strong><span>Pending</span></div>
  <div class="stat s-done"   ><strong id="statDone"   >0</strong><span>This Session</span></div>
  <div class="stat s-errors" ><strong id="statErrors" >0</strong><span>Errors</span></div>
  <div class="stat s-skipped"><strong id="statSkipped">0</strong><span>Skipped</span></div>
</div>
<!-- Sub-stats: images vs videos -->
<div class="stats-bar" style="margin-top:-8px;">
  <div class="stat" style="flex:1;background:#f0f6ff;border-color:#b8d0ee;">
    <strong style="font-size:14px;color:var(--acc);">🖼 Images</strong>
    <span id="subStatImg" style="font-size:11px;color:var(--mut);">— total · — tagged · — pending</span>
  </div>
  <div class="stat" style="flex:1;background:#f0fff4;border-color:#86efac;">
    <strong style="font-size:14px;color:var(--grn);">🎬 Videos</strong>
    <span id="subStatVid" style="font-size:11px;color:var(--mut);">— total · — tagged · — pending</span>
  </div>
</div>

<!-- Controls -->
<div class="controls-card">
  <div class="controls-label">Batch Controls</div>
  <div class="controls-top">
    <button class="btn btn-start" id="btnStart" onclick="startBatch()">▶ Start</button>
    <button class="btn btn-pause" id="btnPause" onclick="pauseBatch()" disabled>⏸ Pause</button>
    <button class="btn btn-stop"  id="btnStop"  onclick="stopBatch()"  disabled>⏹ Stop</button>
    <div class="delay-wrap">
      <span class="delay-label">Process:</span>
      <select class="delay-select" id="modeSelect" title="What to process">
        <option value="all"    selected>🖼+🎬 All</option>
        <option value="images">🖼 Images only</option>
        <option value="videos">🎬 Videos only</option>
      </select>
    </div>
    <div class="delay-wrap">
      <span class="delay-label">Delay:</span>
      <select class="delay-select" id="delaySelect">
        <option value="300">0.3 s</option>
        <option value="500">0.5 s</option>
        <option value="1000" selected>1 s</option>
        <option value="2000">2 s</option>
        <option value="3000">3 s</option>
        <option value="5000">5 s</option>
      </select>
    </div>
  </div>
  <!-- Debug row -->
  <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--bdr);display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <button class="btn" style="background:#374151;color:#fff;padding:8px 16px;font-size:12px;"
            onclick="runDebug()">🔍 Inspect Video Rows</button>
    <button class="btn" style="background:#7c3aed;color:#fff;padding:8px 16px;font-size:12px;"
            onclick="runFfmpegTest()">🎬 Test FFmpeg</button>
    <span style="font-size:11px;color:var(--mut);">Diagnose why thumbnails fail</span>
  </div>
  <div id="debugPanel" style="display:none;margin-top:12px;background:#0f172a;border-radius:8px;padding:12px;font-family:monospace;font-size:11px;color:#94a3b8;max-height:300px;overflow-y:auto;"></div>

  <!-- Progress (hidden until started) -->
  <div class="progress-section" id="progressSection">
    <div class="progress-outer"><div class="progress-inner" id="progressBar"></div></div>
    <div class="progress-text"  id="progressText">0 / 0</div>
  </div>
</div>

<!-- Status bar -->
<div class="status-bar" id="statusBar">
  <span class="status-dot"  id="statusDot"></span>
  <span class="status-label" id="statusLabel">Idle</span>
  <span class="status-img"  id="statusImg"></span>
</div>

<!-- Main grid -->
<div class="main-grid">

  <!-- Current item (image or video thumbnail) -->
  <div class="img-card">
    <div class="card-header">
      <span class="card-title" id="imgTitle">No item loaded</span>
      <span class="card-meta"  id="imgMeta"></span>
    </div>
    <div class="img-body" id="imgBody">
      <div class="img-placeholder">Click <strong>Start</strong> to begin batch tagging.<br><span style="font-size:11px;opacity:.7;">Images are analysed directly · Videos: thumbnail used if exists, otherwise auto-extracted via FFmpeg</span></div>
    </div>
  </div>

  <!-- Last result -->
  <div class="result-card">
    <div class="card-header">
      <span class="card-title">📊 Last Result</span>
      <span class="card-meta" id="resultMeta"></span>
    </div>
    <div class="result-body">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="res-block">
          <div class="res-label">Industry <span id="indStatus"></span></div>
          <div class="res-value" id="resIndustry"><span class="empty-val">—</span></div>
        </div>
        <div class="res-block">
          <div class="res-label">Niche <span id="nicheStatus"></span></div>
          <div class="res-value" id="resNiche"><span class="empty-val">—</span></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="res-block">
          <div class="res-label">AI Group</div>
          <div class="res-value" id="resGroup"><span class="empty-val">—</span></div>
        </div>
        <div class="res-block">
          <div class="res-label">AI Subgroup</div>
          <div class="res-value" id="resSubgroup"><span class="empty-val">—</span></div>
        </div>
      </div>

      <div class="res-block">
        <div class="res-label">Description</div>
        <div class="res-desc" id="resDesc">—</div>
      </div>

      <div class="res-block">
        <div class="res-label">Mood</div>
        <div id="resMood"><span class="empty-val">—</span></div>
      </div>

      <div class="res-block">
        <div class="res-label">Tags</div>
        <div class="tags-wrap" id="resTags"><span class="empty-val">—</span></div>
      </div>

      <hr class="divider">

      <div class="res-block">
        <div class="res-label">Embedding</div>
        <div id="resEmbedding"><span class="empty-val">—</span></div>
      </div>

    </div>
  </div>
</div>

<!-- Log -->
<div class="log-card">
  <div class="card-header">
    <span class="card-title">📋 Activity Log</span>
  </div>
  <div class="log-inner">
    <div class="log-box" id="logBox">Ready. Select mode (All / Images / Videos) then press <strong style="color:#90caf9">Start</strong>.</div>
  </div>
  <div class="log-actions">
    <button class="btn-sm" onclick="clearLog()">Clear Log</button>
  </div>
</div>

<script>
// ── State ──────────────────────────────────────────────────────────────────
var batchState   = 'idle';   // idle | running | paused | stopped | done
var lastId       = 0;
var sessionDone  = 0;
var sessionErr   = 0;
var sessionSkip  = 0;
var pendingTotal = 0;
var loopTimer    = null;

// ── Init ───────────────────────────────────────────────────────────────────
loadStats();

// ── Stats ──────────────────────────────────────────────────────────────────
function loadStats() {
  fetch('?action=get_stats').then(r => r.json()).then(d => {
    if (!d.success) return;
    document.getElementById('statTotal').textContent   = d.total;
    document.getElementById('statTagged').textContent  = d.tagged;
    document.getElementById('statPending').textContent = d.pending;
    // Sub-stats for images vs videos
    var si = document.getElementById('subStatImg');
    var sv = document.getElementById('subStatVid');
    if (si) si.textContent = (d.total_img||0) + ' total · ' + (d.tagged_img||0) + ' tagged · ' + (d.pending_img||0) + ' pending';
    if (sv) sv.textContent = (d.total_vid||0) + ' total · ' + (d.tagged_vid||0) + ' tagged · ' + (d.pending_vid||0) + ' pending';
    pendingTotal = d.pending;
    updateProgress();
  }).catch(() => {});
}

// ── Button states ──────────────────────────────────────────────────────────
function syncButtons() {
  var running = batchState === 'running';
  var active  = running || batchState === 'paused';
  document.getElementById('btnStart').disabled = running;
  document.getElementById('btnPause').disabled = !running;
  document.getElementById('btnStop').disabled  = !active;
  document.getElementById('btnStart').innerHTML =
    batchState === 'paused' ? '▶ Resume' : '▶ Start';
}

// ── Start / Resume ──────────────────────────────────────────────────────────
function startBatch() {
  if (batchState === 'running') return;

  if (batchState !== 'paused') {
    // Fresh start
    lastId = sessionDone = sessionErr = sessionSkip = 0;
    document.getElementById('statDone').textContent    = 0;
    document.getElementById('statErrors').textContent  = 0;
    document.getElementById('statSkipped').textContent = 0;
    log('head', '════════════ Batch started ════════════');
    loadStats();
  } else {
    log('info', '▶ Resumed from ID #' + lastId);
  }

  batchState = 'running';
  syncButtons();
  document.getElementById('progressSection').classList.add('show');
  showStatus('running', 'Running…', '');
  runNext();
}

// ── Pause ───────────────────────────────────────────────────────────────────
function pauseBatch() {
  if (batchState !== 'running') return;
  batchState = 'paused';
  clearTimeout(loopTimer);
  syncButtons();
  showStatus('paused', 'Paused', 'Click Start to resume from ID #' + lastId);
  log('warn', '⏸ Paused. Will resume after current image completes.');
}

// ── Stop ────────────────────────────────────────────────────────────────────
function stopBatch() {
  if (batchState === 'idle' || batchState === 'done') return;
  batchState = 'stopped';
  clearTimeout(loopTimer);
  syncButtons();
  showStatus('stopped', 'Stopped',
    'Done=' + sessionDone + '  Errors=' + sessionErr + '  Skipped=' + sessionSkip);
  log('warn', '⏹ Stopped manually. Done=' + sessionDone + '  Errors=' + sessionErr + '  Skipped=' + sessionSkip);
  loadStats();
}

// ── Hidden video element for frame capture ───────────────────────────────────
(function() {
  var vid = document.createElement('video');
  vid.id = 'hiddenCaptureVideo';
  vid.muted = true;
  vid.crossOrigin = 'anonymous';
  vid.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;';
  document.body.appendChild(vid);
  var canvas = document.createElement('canvas');
  canvas.id = 'hiddenCaptureCanvas';
  canvas.style.cssText = 'display:none;';
  document.body.appendChild(canvas);
})();

// ── Capture a frame from video URL, return base64 JPEG ───────────────────────
function captureVideoFrame(videoUrl) {
  return new Promise(function(resolve, reject) {
    var vid    = document.getElementById('hiddenCaptureVideo');
    var canvas = document.getElementById('hiddenCaptureCanvas');
    var timer  = null;

    function cleanup(err) {
      clearTimeout(timer);
      vid.onloadedmetadata = null;
      vid.onseeked = null;
      vid.onerror  = null;
      vid.pause();
      vid.removeAttribute('src');
      vid.load();
      if (err) reject(err); 
    }

    timer = setTimeout(function() { cleanup(new Error('Timeout loading video for capture')); }, 30000);

    vid.onloadedmetadata = function() {
      vid.currentTime = Math.max(0.1, (vid.duration || 10) * 0.1);
    };

    vid.onseeked = function() {
      try {
        var w = vid.videoWidth  || 800;
        var h = vid.videoHeight || 450;
        // Scale down to max 800px
        var ratio = Math.min(800 / w, 800 / h, 1);
        canvas.width  = Math.round(w * ratio);
        canvas.height = Math.round(h * ratio);
        var ctx = canvas.getContext('2d');
        ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
        var dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        cleanup();
        resolve(dataUrl);
      } catch(e) { cleanup(e); }
    };

    vid.onerror = function() { cleanup(new Error('Video load/decode error')); };
    vid.src = videoUrl;
    vid.load();
  });
}

// ── Core loop ───────────────────────────────────────────────────────────────
function runNext() {
  if (batchState !== 'running') return;

  var mode = document.getElementById('modeSelect')?.value || 'all';

  fetch('?action=get_next&after_id=' + lastId + '&mode=' + encodeURIComponent(mode))
    .then(r => r.json())
    .then(async function(d) {
      if (!d.success) {
        if (d.skip) {
          sessionSkip++;
          document.getElementById('statSkipped').textContent = sessionSkip;
          lastId = d.id;
          log('warn', '⤵ SKIPPED id=' + d.id + ' — ' + d.message);
          scheduleNext();
          return;
        }
        // All done
        batchState = 'done';
        syncButtons();
        showStatus('done', '✅ All Done!',
          'Processed ' + sessionDone + ' item(s)  •  Errors: ' + sessionErr + '  Skipped: ' + sessionSkip);
        log('ok', '🎉 Batch complete!  Done=' + sessionDone + '  Errors=' + sessionErr + '  Skipped=' + sessionSkip);
        loadStats();
        return;
      }

      lastId = d.id;
      var isVideo   = d.media_type === 'video';
      var typeIcon  = isVideo ? '🎬' : '🖼';
      var typeLabel = isVideo ? 'VIDEO' : 'IMAGE';
      showStatus('running', 'Processing ' + typeLabel + '…', 'ID #' + d.id + '  ' + d.image_name);

      var base64ToSend = d.base64 || '';
      var mimeToSend   = d.mime   || 'image/jpeg';

      // ── VIDEO: browser captures the frame ──────────────────────────────────
      if (isVideo && d.needs_capture) {
        log('info', typeIcon + ' [' + d.id + '] ' + d.image_name + '  capturing frame…');
        showStatus('running', '🎬 Capturing frame from video…', d.image_name);

        try {
          var dataUrl = await captureVideoFrame(d.video_url);
          base64ToSend = dataUrl.split(',')[1];
          mimeToSend   = 'image/jpeg';

          // Show preview
          document.getElementById('imgBody').innerHTML =
            '<div style="position:relative;display:inline-block;">'
            + '<img src="' + dataUrl + '" style="max-width:100%;max-height:400px;object-fit:contain;">'
            + '<div style="position:absolute;top:10px;left:10px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">🎬 Captured frame</div>'
            + '</div>';
          document.getElementById('imgTitle').textContent = d.image_name;
          document.getElementById('imgMeta').textContent  = 'Row ID: ' + d.id + '  ·  VIDEO (frame captured)';

          // Save thumbnail to disk via PHP
          var tfd = new FormData();
          tfd.append('id',         d.id);
          tfd.append('video_file', d.image_name);
          tfd.append('base64',     dataUrl);
          var ts = await fetch(location.href, { method: 'POST', body: tfd }).then(r => r.json()).catch(() => ({}));
          if (ts.success) {
            log('info', '   💾 Thumbnail saved: ' + ts.thumbnail);
          } else {
            log('warn', '   ⚠ Thumbnail save failed: ' + (ts.message || 'unknown'));
          }

        } catch(captureErr) {
          sessionSkip++;
          document.getElementById('statSkipped').textContent = sessionSkip;
          log('warn', '⤵ SKIPPED id=' + d.id + ' — frame capture failed: ' + captureErr.message);
          scheduleNext();
          return;
        }

      } else {
        // Image or video with existing thumbnail — show preview directly
        var previewHtml = '<img src="data:' + mimeToSend + ';base64,' + base64ToSend + '" style="max-width:100%;max-height:400px;object-fit:contain;">';
        if (isVideo) previewHtml = '<div style="position:relative;display:inline-block;">' + previewHtml
          + '<div style="position:absolute;top:10px;left:10px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">🎬 Saved thumbnail</div></div>';
        document.getElementById('imgBody').innerHTML    = previewHtml;
        document.getElementById('imgTitle').textContent = d.image_name;
        document.getElementById('imgMeta').textContent  = 'Row ID: ' + d.id + '  ·  ' + typeLabel;
        log('info', typeIcon + ' [' + d.id + '] ' + d.image_name);
      }

      // ── Send to AI for tagging ─────────────────────────────────────────────
      var fd = new FormData();
      fd.append('id',         d.id);
      fd.append('base64',     base64ToSend);
      fd.append('mime',       mimeToSend);
      fd.append('media_type', d.media_type || 'image');

      fetch('?action=process_one', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) {
            sessionErr++;
            document.getElementById('statErrors').textContent = sessionErr;
            log('err', '❌ [' + d.id + '] ' + res.message);
          } else {
            sessionDone++;
            document.getElementById('statDone').textContent = sessionDone;
            renderResult(res);
            var resIcon = (res.media_type === 'video') ? '🎬' : '🖼';
            log('ok', resIcon + ' ✅ [' + res.id + '] ' + res.industry_name + pillText(res.industry_status)
                     + ' / ' + res.niche_name + pillText(res.niche_status));
            if (res.embedded) log('ok',  '   ✅ Embedding saved (3072 dims)');
            else              log('warn', '   ⚠ Embedding skipped');
            log('info', '   Mood: ' + res.mood + '   Tags: ' + (res.tags || []).slice(0, 4).join(', ')
                       + ((res.tags || []).length > 4 ? '…' : ''));
            loadStats();
          }
          scheduleNext();
        })
        .catch(e => {
          sessionErr++;
          document.getElementById('statErrors').textContent = sessionErr;
          log('err', '❌ [' + d.id + '] process error: ' + e.message);
          scheduleNext();
        });
    })
    .catch(e => {
      sessionErr++;
      document.getElementById('statErrors').textContent = sessionErr;
      log('err', '❌ get_next error: ' + e.message);
      scheduleNext();
    });
}

function scheduleNext() {
  if (batchState !== 'running') return;
  var delay = parseInt(document.getElementById('delaySelect').value) || 1000;
  loopTimer = setTimeout(runNext, delay);
}

// ── Progress bar ────────────────────────────────────────────────────────────
function updateProgress() {
  if (pendingTotal <= 0) return;
  var pct = Math.min(100, Math.round((sessionDone / pendingTotal) * 100));
  document.getElementById('progressBar').style.width = pct + '%';
  document.getElementById('progressText').textContent =
    sessionDone + ' / ' + pendingTotal + '  (' + pct + '%)';
}

// ── Status bar ──────────────────────────────────────────────────────────────
function showStatus(type, label, img) {
  var bar = document.getElementById('statusBar');
  bar.className = 'status-bar show s-' + type;
  document.getElementById('statusDot').className   = 'status-dot dot-' + type;
  document.getElementById('statusLabel').textContent = label;
  document.getElementById('statusImg').textContent   = img;
}

// ── Render last result ───────────────────────────────────────────────────────
function renderResult(r) {
  var typeLabel = r.media_type === 'video' ? ' 🎬 video' : ' 🖼 image';
  document.getElementById('resultMeta').textContent    = 'ID #' + r.id + typeLabel;
  document.getElementById('indStatus').innerHTML       = pillHtml(r.industry_status);
  document.getElementById('nicheStatus').innerHTML     = pillHtml(r.niche_status);
  document.getElementById('resIndustry').textContent   = r.industry_name || '—';
  document.getElementById('resNiche').textContent      = r.niche_name    || '—';
  document.getElementById('resGroup').textContent      = r.group         || '—';
  document.getElementById('resSubgroup').textContent   = r.subgroup      || '—';
  document.getElementById('resDesc').textContent       = r.description   || '—';
  document.getElementById('resMood').innerHTML = r.mood
    ? '<span class="mood-badge">' + esc(r.mood) + '</span>'
    : '<span class="empty-val">—</span>';
  var tw = document.getElementById('resTags');
  tw.innerHTML = '';
  if (r.tags && r.tags.length) {
    r.tags.forEach(function(t) {
      var s = document.createElement('span');
      s.className = 'tag'; s.textContent = t; tw.appendChild(s);
    });
  } else { tw.innerHTML = '<span class="empty-val">—</span>'; }
  document.getElementById('resEmbedding').innerHTML = r.embedded
    ? '<span style="color:var(--grn);font-weight:700;">✅ Generated (3072 dims)</span>'
    : '<span style="color:var(--amber);">⚠ Skipped</span>';
  updateProgress();
}

// ── Pill helpers ─────────────────────────────────────────────────────────────
function pillClass(s) {
  return s === 'matched' ? 'pill-matched'
       : s === 'created' ? 'pill-created'
       : s === 'lifestyle_fallback' ? 'pill-lifestyle'
       : 'pill-general';
}
function pillLabel(s) {
  return s === 'matched'            ? 'DB'
       : s === 'created'            ? 'New'
       : s === 'lifestyle_fallback' ? 'Lifestyle ↩'
       : 'General ↩';
}
function pillHtml(s) {
  return '<span class="pill ' + pillClass(s) + '">' + pillLabel(s) + '</span>';
}
function pillText(s) { return ' [' + pillLabel(s) + ']'; }

// ── Log ──────────────────────────────────────────────────────────────────────
function log(cls, msg) {
  var box  = document.getElementById('logBox');
  var line = document.createElement('div');
  line.className = 'log-' + cls;
  var ts = new Date().toLocaleTimeString('en-GB', { hour12: false });
  line.textContent = '[' + ts + '] ' + msg;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}
function clearLog() { document.getElementById('logBox').innerHTML = ''; }

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Debug: inspect raw video rows from DB ─────────────────────────────────────
function runDebug() {
  var panel = document.getElementById('debugPanel');
  panel.style.display = 'block';
  panel.innerHTML = '<span style="color:#60a5fa;">Loading video rows from DB…</span>';
  fetch('?action=debug_videos&limit=20')
    .then(r => r.json())
    .then(d => {
      if (!d.success) { panel.innerHTML = '<span style="color:#f87171;">Error: ' + esc(JSON.stringify(d)) + '</span>'; return; }
      var html = '<span style="color:#a78bfa;font-weight:700;">VID_DIR: ' + esc(d.vid_dir) + '</span>\n';
      html     += '<span style="color:#a78bfa;font-weight:700;">IMG_DIR: ' + esc(d.img_dir) + '</span>\n\n';
      if (!d.rows.length) { html += '<span style="color:#fbbf24;">No video rows found in hdb_image_data.</span>'; }
      d.rows.forEach(function(r) {
        var vidOk   = r.video_exists ? '✅' : '❌';
        var thumbOk = r.thumb_found !== '(not found)' ? '✅' : '❌';
        html += '<span style="color:#34d399;font-weight:700;">── ID ' + r.id + ' tag_flag=' + r.tag_flag + ' ──</span>\n';
        html += '   image_name  : ' + esc(r.image_name) + '\n';
        html += '   video_path  : ' + vidOk + ' ' + esc(r.video_path) + '\n';
        html += '   thumbnail   : ' + esc(r.thumbnail_col || '(empty)') + '\n';
        html += '   thumb_found : ' + thumbOk + ' ' + esc(r.thumb_found) + '\n';
        if (r.thumb_tried && r.thumb_tried.length) {
          html += '   tried paths :\n';
          r.thumb_tried.forEach(function(p) { html += '     - ' + esc(p) + '\n'; });
        }
        html += '\n';
      });
      panel.innerHTML = '<pre style="margin:0;white-space:pre-wrap;">' + html + '</pre>';
    })
    .catch(e => { panel.innerHTML = '<span style="color:#f87171;">Fetch error: ' + esc(e.message) + '</span>'; });
}

function runFfmpegTest() {
  var panel = document.getElementById('debugPanel');
  panel.style.display = 'block';
  panel.innerHTML = '<span style="color:#60a5fa;">Running FFmpeg test on first untagged video…</span>';
  fetch('?action=test_ffmpeg')
    .then(r => r.json())
    .then(d => {
      if (!d.success) { panel.innerHTML = '<span style="color:#f87171;">Error: ' + esc(JSON.stringify(d)) + '</span>'; return; }
      var html = '';
      html += '<span style="color:#a78bfa;font-weight:700;">═══ FFmpeg Diagnostic ═══</span>\n\n';
      html += 'shell_exec works : ' + (d.shell_works === 'shell_exec_works' ? '✅ yes' : '❌ BLOCKED — ' + esc(d.shell_works||'(empty)')) + '\n';
      html += 'ffmpeg binary    : ' + esc(d.ffmpeg_bin) + '\n';
      html += 'video file       : ' + esc(d.video_file) + '\n';
      html += 'video exists     : ' + (d.video_exists  ? '✅ yes' : '❌ NOT FOUND') + '\n';
      html += 'dir writable     : ' + (d.dir_writable  ? '✅ yes' : '❌ NOT WRITABLE — ' + esc(d.vid_dir)) + '\n';
      html += 'thumb created    : ' + (d.thumb_created ? '✅ yes (' + d.thumb_size + ' bytes)' : '❌ no') + '\n\n';
      html += '<span style="color:#fbbf24;">CMD:</span>\n' + esc(d.cmd) + '\n\n';
      html += '<span style="color:#fbbf24;">FFmpeg output:</span>\n' + esc(d.ffmpeg_output || '(empty — shell_exec returned nothing)') + '\n';
      panel.innerHTML = '<pre style="margin:0;white-space:pre-wrap;">' + html + '</pre>';
    })
    .catch(e => { panel.innerHTML = '<span style="color:#f87171;">Error: ' + esc(e.message) + '</span>'; });
}
</script>
</body>
</html>

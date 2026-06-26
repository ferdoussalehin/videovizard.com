<?php
// ═════════════════════════════════════════════════════════════════════════════
// SCRIPTGEN_STAGING.PHP — Real Estate Virtual Staging + Video
// ═════════════════════════════════════════════════════════════════════════════
// STATUS: Step 1 (Upload Room Photo → AI Staging) + Step 2 (Select Video
// Sequence — choose which staged rooms go in the video and in what order).
// Steps 3-6 (Lighting Mood, Video Setting, Generate Room Clips, Select
// Video Mode) follow the exact same architecture and attach next.
//
// KEY DIFFERENCE FROM THE DRESS-TRYON FLOW: that flow has ONE hero image
// reused across every scene, and "Select a Style" in Step 2 there picks an
// abstract pose SEQUENCE from a DB table (mdl_model_pose_styles) because
// poses are interchangeable templates. Staging has no interchangeable
// templates — each room is a specific, unique uploaded photo. So "Select a
// Style" here becomes "Select Video Sequence": pick which already-staged
// rooms are actually included in the final video, and the order they play
// in. That selection *is* the scene list Step 4 will build rows from.
// ═════════════════════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}
ob_start();

if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

require_once 'config.php';
include 'dbconnect_hdb.php';

mysqli_report(MYSQLI_REPORT_OFF);

define('VV_SITE_BASE_URL', 'https://videovizard.com');

$falApiKey = $falApiKey ?? null;
// OpenAI key, used only for room-type classification (which room is this photo?)
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// ── Core helpers — ported as-is from vizard_scriptgen_3.php ─────────────────
function vv_log($msg) {
    error_log('[VPS-STAGING] ' . $msg);
}
function vv_safe_fetch($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r || $r === false) {
        vv_log("vv_safe_fetch FAILED: " . mysqli_error($conn) . " | SQL: " . substr($sql, 0, 200));
        return null;
    }
    return mysqli_fetch_assoc($r) ?: null;
}

function vv_shrink_for_upload($path, $max_dim = 2048, $quality = 85) {
    $info = @getimagesize($path);
    if (!$info) return $path;
    [$w, $h] = $info;
    if (max($w, $h) <= $max_dim) return $path;

    $src = @imagecreatefromstring(@file_get_contents($path));
    if (!$src) return $path;

    $scale = $max_dim / max($w, $h);
    $new_w = max(1, (int)round($w * $scale));
    $new_h = max(1, (int)round($h * $scale));
    $dst   = imagecreatetruecolor($new_w, $new_h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

    $tmp_path = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
    imagejpeg($dst, $tmp_path, $quality);
    imagedestroy($src);
    imagedestroy($dst);
    return $tmp_path;
}

function vv_shrink_to_target_size($path, $target_bytes = 700000) {
    $info = @getimagesize($path);
    if (!$info) return $path;
    [$w, $h] = $info;

    $src = @imagecreatefromstring(@file_get_contents($path));
    if (!$src) return $path;

    $max_dim   = max($w, $h);
    $quality   = 82;
    $best_path = null;

    for ($i = 0; $i < 6; $i++) {
        $scale = min(1, $max_dim / max($w, $h));
        $new_w = max(1, (int)round($w * $scale));
        $new_h = max(1, (int)round($h * $scale));
        $dst   = imagecreatetruecolor($new_w, $new_h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

        $tmp_path = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
        imagejpeg($dst, $tmp_path, $quality);
        imagedestroy($dst);

        if ($best_path !== null) @unlink($best_path);
        $best_path = $tmp_path;

        $size = @filesize($tmp_path);
        if ($size !== false && $size <= $target_bytes) break;

        $max_dim = (int)round($max_dim * 0.8);
        $quality = max(50, $quality - 8);
    }

    imagedestroy($src);
    return $best_path;
}

function vv_resolve_user($conn, $admin_id, $session_company_id) {
    $urow         = [];
    $_uq          = mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    if ($_uq)     $urow = mysqli_fetch_assoc($_uq) ?: [];
    $role         = $urow['role']         ?? 'Team Lead';
    $team_lead_id = (int)($urow['team_lead_id'] ?? 0);
    $owner_id     = ($role === 'Team Member' && $team_lead_id > 0) ? $team_lead_id : $admin_id;

    $co_sql = $session_company_id > 0
        ? "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id AND id=$session_company_id LIMIT 1"
        : "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id ORDER BY id ASC LIMIT 1";

    $_cq            = mysqli_query($conn, $co_sql);
    $co_row         = $_cq ? (mysqli_fetch_assoc($_cq) ?: null) : null;
    $company_type   = $co_row['company_type'] ?? '';
    $resolved_co_id = $co_row ? (int)$co_row['id'] : $session_company_id;

    return [$owner_id, $resolved_co_id, $company_type, $role];
}

function vv_fal_upload_for_proxy($path) {
    $proxy_url = VV_SITE_BASE_URL . '/fal_proxy.php?action=upload';
    $ch = curl_init($proxy_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'base64'    => base64_encode(file_get_contents($path)),
            'mime_type' => mime_content_type($path),
            'file_name' => basename($path),
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $url = json_decode($res, true)['file_url'] ?? null;
    return [$url, $http, $err, $res];
}

// ═════════════════════════════════════════════════════════════════════════════
// ROOM TYPES + STAGING PROMPTS
// ═════════════════════════════════════════════════════════════════════════════
function vv_room_types() {
    return [
        'front_exterior' => 'Front Exterior',
        'backyard'       => 'Backyard',
        'living_room'    => 'Living Room',
        'family_room'    => 'Family Room',
        'dining_room'    => 'Dining Room',
        'kitchen'        => 'Kitchen',
        'bedroom'        => 'Bedroom',
        'basement'       => 'Basement',
        'washroom'       => 'Washroom',
        'office'         => 'Home Office',
        'other'          => 'Other Room',
    ];
}

// ── Default room ORDER for "Select Video Sequence" (Step 2) ────────────────
// Mirrors typical listing-tour logic: curb appeal first, then primary
// living spaces, then private spaces, ending on bathrooms/basement. Used
// to auto-sort newly staged rooms before the person drags to fine-tune.
function vv_room_type_default_priority() {
    return [
        'front_exterior' => 1,
        'living_room'    => 2,
        'family_room'    => 3,
        'dining_room'    => 4,
        'kitchen'        => 5,
        'bedroom'        => 6,
        'office'         => 7,
        'basement'       => 8,
        'washroom'       => 9,
        'backyard'       => 10,
        'other'          => 11,
    ];
}

function vv_room_staging_base_prompt($room_type) {
    $prompts = [
        'front_exterior' => 'Improve the curb appeal of this home\'s front exterior: add tidy, well-trimmed landscaping, fresh mulch beds, a clean walkway, and a welcoming entrance — potted plants near the door, tidy lawn. Do not alter the house structure, roofline, windows, door position, siding, or driveway layout.',
        'backyard'       => 'Stage this backyard as an inviting outdoor living space: add a patio seating set or outdoor sectional, a small dining area or fire pit, potted plants, and tasteful outdoor lighting. Keep the fence, deck/patio structure, landscaping bones, and house exterior exactly as shown.',
        'living_room'    => 'Stage this empty or cluttered living room with a tasteful sofa, coffee table, area rug, accent chairs, wall art, and ambient lamp lighting suited to the room\'s actual proportions.',
        'family_room'    => 'Stage this family room with a comfortable sectional or sofa, a coffee table, area rug, TV console, soft accent lighting, and tasteful wall decor sized correctly for the room.',
        'dining_room'    => 'Stage this dining room with a dining table and matching chairs sized correctly for the room, a simple centerpiece, and a pendant or chandelier light fixture if one is not already present.',
        'kitchen'        => 'Lightly stage this kitchen: tidy countertops with a few tasteful decor items (fruit bowl, cutting board, small plants), bar stools at the island/counter if present. Do NOT alter cabinets, countertops, appliances, backsplash, or layout.',
        'bedroom'        => 'Stage this bedroom with a made bed (headboard, pillows, neatly folded throw), nightstands with simple lamps, and light wall decor sized correctly for the room.',
        'basement'       => 'Stage this basement as a finished, livable space: add a cozy sectional or sofa, area rug, and simple decor appropriate to the room\'s actual finish level. Do not alter exposed structural elements, ceiling height, or unfinished areas if visible.',
        'washroom'       => 'Lightly stage this washroom/bathroom: fresh towels neatly hung or folded, a simple bath mat, a small plant or tasteful decor item on the counter. Do NOT alter the vanity, tub, shower, tile, fixtures, or layout.',
        'office'         => 'Stage this room as a home office: a desk, ergonomic chair, bookshelf or simple storage, and tasteful wall decor sized correctly for the room.',
        'other'          => 'Tastefully stage this room with furniture and decor appropriate to its apparent purpose, sized correctly for the room\'s actual proportions.',
    ];
    return $prompts[$room_type] ?? $prompts['other'];
}

function vv_staging_style_modifier($style) {
    $styles = [
        'modern'      => 'Modern style: clean lines, neutral palette (whites, greys, warm wood tones), minimal but warm decor.',
        'luxury'      => 'Luxury style: high-end furniture, rich textures, statement lighting, elevated finishes, sophisticated neutral-and-gold palette.',
        'farmhouse'   => 'Modern farmhouse style: warm woods, shiplap-friendly neutral tones, cozy textiles, vintage-inspired but clean accents.',
        'coastal'     => 'Coastal style: light airy palette, natural textures (linen, rattan, light wood), soft blues and sandy neutrals.',
        'minimalist'  => 'Minimalist style: very few pieces, clean lines, neutral monochrome palette, uncluttered and calm.',
    ];
    return $styles[$style] ?? $styles['modern'];
}

function vv_analyze_room_type($apiKey, $image_path) {
    $valid = array_keys(vv_room_types());
    if (!$apiKey) return 'other';

    $bytes = @file_get_contents($image_path);
    if ($bytes === false) return 'other';
    $mime     = @mime_content_type($image_path) ?: 'image/jpeg';
    $data_uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    $prompt = 'You are analyzing a real estate listing photo. Classify which room/area this photo shows. '
            . 'Choose EXACTLY ONE from this list: ' . implode(', ', $valid) . '. '
            . 'Respond with ONLY a JSON object like: {"room_type":"kitchen"} — no other text.';

    $payload = [
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 30,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type'=>'text','text'=>$prompt],
                ['type'=>'image_url','image_url'=>['url'=>$data_uri,'detail'=>'low']],
            ],
        ]],
    ];
    $och = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($och, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $ores = curl_exec($och); curl_close($och);
    $oj   = json_decode($ores, true);
    $text   = trim($oj['choices'][0]['message']['content'] ?? '');
    $parsed = json_decode($text, true);
    return (isset($parsed['room_type']) && in_array($parsed['room_type'], $valid)) ? $parsed['room_type'] : 'other';
}

if (!defined('FAL_NANOBANANA_EDIT_URL')) define('FAL_NANOBANANA_EDIT_URL', 'https://fal.run/fal-ai/nano-banana-2/edit');

function vv_generate_staged_room($falApiKey, $roomImageAbsPath, $room_type, $style, $owner_id, $co_id, $draft_id, $maxRetries = 2) {
    $upload_path = vv_shrink_to_target_size($roomImageAbsPath);
    [$source_url, $uh, $uerr] = vv_fal_upload_for_proxy($upload_path);
    if ($upload_path !== $roomImageAbsPath) @unlink($upload_path);
    if (!$source_url) {
        return ['success' => false, 'message' => "Failed to upload room photo to fal.ai (HTTP $uh" . ($uerr ? ", curl: $uerr" : '') . ')'];
    }

    $prompt = vv_room_staging_base_prompt($room_type) . ' ' . vv_staging_style_modifier($style)
            . ' Keep the room\'s walls, windows, doors, floor, ceiling, and overall structure and perspective exactly unchanged — only add furniture and decor. Photorealistic, natural lighting matching the original photo, sharp focus, real estate listing quality.';

    $payload = json_encode([
        'prompt'       => $prompt,
        'image_urls'   => [$source_url],
        'aspect_ratio' => 'auto',
        'resolution'   => '2K',
        'num_images'   => 1,
    ]);

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    if (!is_dir($room_dir)) @mkdir($room_dir, 0777, true);
    $filename = "draft_{$draft_id}_room_{$room_type}.jpg";
    $filePath = $room_dir . $filename;

    $httpCode = 0; $curlError = ''; $attempt = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init(FAL_NANOBANANA_EDIT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if (!$imageUrl) {
                return ['success' => false, 'message' => 'FAL AI returned HTTP 200 but no image URL.'];
            }
            $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $m);
            $outData = $isDataUri ? base64_decode($m[1]) : @file_get_contents($imageUrl);
            if ($outData === false || strlen($outData) === 0) {
                return ['success' => false, 'message' => 'Generated image could not be downloaded/decoded.'];
            }
            if (@file_put_contents($filePath, $outData) === false) {
                return ['success' => false, 'message' => 'Image generated but could not be saved to disk.'];
            }
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'];
            $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($room_dir, '/')), '/') . '/';
            $public_url = $protocol . '://' . $host . $web_path . $filename . '?v=' . time();
            return [
                'success'    => true,
                'filename'   => $filename,
                'local_path' => $filePath,
                'public_url' => $public_url,
                'room_type'  => $room_type,
                'style'      => $style,
                'source'     => 'FAL AI / nano-banana-2/edit (Room Staging)',
            ];
        }
        if ($httpCode !== 0) break;
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (nano-banana-2/edit) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if ($curlError) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'message' => $errMsg];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 — LIGHTING MOOD (optional)
// ═════════════════════════════════════════════════════════════════════════════
// Re-edits the ALREADY-STAGED room (not the original upload) to change the
// time-of-day/lighting feel — same fal-ai/nano-banana-2/edit mechanism as
// the staging call itself, just a different, narrower prompt. Optional —
// "Skip" (mood = 'none') is the default, same as Step 3 Background Theme
// is optional in the dress-tryon flow. Unlike Background Theme there's no
// rembg/composite step here: relighting a whole room is a single edit
// pass, not a subject-vs-background swap.
// ═════════════════════════════════════════════════════════════════════════════
function vv_lighting_moods() {
    return [
        'none'          => 'No Change',
        'day_bright'    => 'Bright Daylight',
        'golden_hour'   => 'Golden Hour',
        'dusk_twilight' => 'Dusk / Twilight',
        'overcast_soft' => 'Soft Overcast',
    ];
}

function vv_lighting_mood_prompt($mood) {
    $prompts = [
        'day_bright'    => 'Relight this photo as bright, clear midday daylight — crisp natural light through the windows, clean white balance, airy and bright atmosphere.',
        'golden_hour'   => 'Relight this photo as warm golden-hour sunset light — soft warm amber tones, gentle long shadows, cozy inviting atmosphere.',
        'dusk_twilight' => 'Relight this photo as dusk/twilight — windows showing a deep blue evening sky outside, warm interior lamps and lights turned on, cozy contrast between warm interior and cool exterior light.',
        'overcast_soft' => 'Relight this photo as soft, even overcast daylight — diffused natural light, no harsh shadows, calm neutral atmosphere.',
    ];
    return $prompts[$mood] ?? null;
}

// Re-edits draft_{tag}_room_{room_type}.jpg (the staged image) into a new
// draft_{tag}_room_{room_type}_lit_{mood}.jpg. Returns the staged image's
// own result shape (success/public_url/filename) so callers can treat it
// identically to vv_generate_staged_room's return value.
function vv_apply_lighting_mood($falApiKey, $stagedImageAbsPath, $mood, $owner_id, $co_id, $tag, $maxRetries = 2) {
    $prompt_detail = vv_lighting_mood_prompt($mood);
    if (!$prompt_detail) {
        return ['success' => false, 'message' => "Unknown lighting mood: $mood"];
    }

    $upload_path = vv_shrink_to_target_size($stagedImageAbsPath);
    [$source_url, $uh, $uerr] = vv_fal_upload_for_proxy($upload_path);
    if ($upload_path !== $stagedImageAbsPath) @unlink($upload_path);
    if (!$source_url) {
        return ['success' => false, 'message' => "Failed to upload staged room photo for relighting (HTTP $uh" . ($uerr ? ", curl: $uerr" : '') . ')'];
    }

    $prompt = $prompt_detail . ' Keep every piece of furniture, decor, staging, and the room\'s architecture (walls, windows, doors, floor, ceiling) exactly unchanged — only change the lighting and time-of-day feel. Photorealistic, real estate listing quality.';

    $payload = json_encode([
        'prompt'       => $prompt,
        'image_urls'   => [$source_url],
        'aspect_ratio' => 'auto',
        'resolution'   => '2K',
        'num_images'   => 1,
    ]);

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    if (!is_dir($room_dir)) @mkdir($room_dir, 0777, true);
    $filename = "draft_{$tag}_lit_{$mood}.jpg";
    $filePath = $room_dir . $filename;

    $httpCode = 0; $curlError = ''; $attempt = 0;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init(FAL_NANOBANANA_EDIT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data     = json_decode($response, true);
            $imageUrl = $data['images'][0]['url'] ?? null;
            if (!$imageUrl) {
                return ['success' => false, 'message' => 'FAL AI returned HTTP 200 but no image URL.'];
            }
            $isDataUri = (bool) preg_match('/^data:[^;]+;base64,(.+)$/s', $imageUrl, $m);
            $outData = $isDataUri ? base64_decode($m[1]) : @file_get_contents($imageUrl);
            if ($outData === false || strlen($outData) === 0) {
                return ['success' => false, 'message' => 'Generated image could not be downloaded/decoded.'];
            }
            if (@file_put_contents($filePath, $outData) === false) {
                return ['success' => false, 'message' => 'Image generated but could not be saved to disk.'];
            }
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host       = $_SERVER['HTTP_HOST'];
            $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($room_dir, '/')), '/') . '/';
            $public_url = $protocol . '://' . $host . $web_path . $filename . '?v=' . time();
            return ['success' => true, 'filename' => $filename, 'local_path' => $filePath, 'public_url' => $public_url, 'mood' => $mood];
        }
        if ($httpCode !== 0) break;
        if ($attempt < $maxRetries) sleep(3);
    }
    $errMsg = 'FAL AI (nano-banana-2/edit relight) failed after ' . $attempt . ' attempt(s) (HTTP ' . $httpCode . ').';
    if ($curlError) $errMsg .= ' cURL: ' . $curlError;
    return ['success' => false, 'message' => $errMsg];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — VIDEO SETTING (builds hdb_podcasts / hdb_podcast_stories)
// ═════════════════════════════════════════════════════════════════════════════

// ── Per-room-type camera move, stored as each scene's video_prompt ─────────
// This is what Step 5 (Generate Room Clips) will hand to the image-to-video
// model — decided now, at scene-creation time, same as the dress-tryon flow
// resolves each scene's video_prompt from its pose template at Step 4, not
// later at Step 5.
function vv_room_camera_prompt($room_type) {
    $prompts = [
        'front_exterior' => 'Slow cinematic push-in toward the front entrance, real estate showcase style, warm natural lighting, smooth steady motion',
        'backyard'       => 'Smooth wide pan across the backyard, real estate showcase style, warm natural lighting, smooth steady motion',
        'living_room'    => 'Slow cinematic push-in across the living room toward the seating area, real estate showcase style, warm natural lighting, smooth steady motion',
        'family_room'    => 'Slow cinematic push-in across the family room toward the seating area, real estate showcase style, warm natural lighting, smooth steady motion',
        'dining_room'    => 'Smooth lateral pan across the dining room, real estate showcase style, warm natural lighting, smooth steady motion',
        'kitchen'        => 'Slow push-in toward the kitchen island/counter, real estate showcase style, warm natural lighting, smooth steady motion',
        'bedroom'        => 'Slow cinematic pan across the bedroom, lingering toward the bed, real estate showcase style, warm natural lighting, smooth steady motion',
        'basement'       => 'Smooth wide pan across the basement living space, real estate showcase style, warm natural lighting, smooth steady motion',
        'washroom'       => 'Slow push-in across the washroom vanity, real estate showcase style, warm natural lighting, smooth steady motion',
        'office'         => 'Slow pan across the home office desk area, real estate showcase style, warm natural lighting, smooth steady motion',
        'other'          => 'Slow cinematic pan across the room, real estate showcase style, warm natural lighting, smooth steady motion',
    ];
    return $prompts[$room_type] ?? $prompts['other'];
}

// ── AI-generated sequential per-room captions, in the given room order —
// mirrors vv_generate_scene_captions_ai's structure/parsing, but the prompt
// is written for a real-estate listing walkthrough (room-by-room) instead
// of a fashion sequence. Falls back to plain room labels if the API call
// fails or doesn't parse cleanly.
function vv_generate_listing_captions_ai($apiKey, $room_labels_in_order, $property_description, $caption_style, $fallback_captions) {
    $scene_count = count($room_labels_in_order);
    if (!$apiKey || $scene_count < 1) return $fallback_captions;

    $has_desc = trim($property_description) !== '';
    $rooms_list = implode(' → ', $room_labels_in_order);

    $prompt = "Your task is to generate exactly {$scene_count} sequential captions for a real estate listing walkthrough video. Each scene shows ONE specific room, in this exact order: {$rooms_list}.\n\n"
        . "INPUTS:\n"
        . "* Property Description: " . ($has_desc ? $property_description : '(none provided)') . "\n"
        . "* Caption Style: {$caption_style}\n\n"
        . "INSTRUCTIONS:\n"
        . "1. Generate exactly {$scene_count} captions, one per scene, in the same order as the room list above.\n"
        . "2. Each caption must clearly describe THAT SPECIFIC room (e.g. the kitchen caption should be about the kitchen, not generic).\n"
        . "3. If a property description is provided, incorporate relevant details naturally where they fit the room.\n"
        . "4. Keep each caption under 12 words.\n"
        . "5. Avoid repeating the same words across captions.\n"
        . "6. Use warm, inviting real estate listing language suitable for Instagram Reels, TikTok, and MLS video.\n"
        . "7. The final caption (last room) may include a soft call-to-action (e.g. 'Schedule your private tour today').\n"
        . "8. Output in the following format, one line per scene, nothing else:\n"
        . "Scene 1: [Caption] Scene 2: [Caption] ... Scene {$scene_count}: [Caption]";

    $payload = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 400,
        'temperature' => 0.85,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$res) { vv_log("vv_generate_listing_captions_ai: curl error: $err"); return $fallback_captions; }

    $text = trim(json_decode($res, true)['choices'][0]['message']['content'] ?? '');
    if ($text === '') { vv_log("vv_generate_listing_captions_ai: empty response: $res"); return $fallback_captions; }

    preg_match_all('/Scene\s*(\d+)\s*:\s*(.+?)(?=(?:Scene\s*\d+\s*:)|$)/is', $text, $matches, PREG_SET_ORDER);
    $captions = [];
    foreach ($matches as $m) {
        $idx = (int) $m[1];
        $cap = trim($m[2], " \n\r\t.,-—");
        if ($idx >= 1 && $idx <= $scene_count && $cap !== '') $captions[$idx] = $cap;
    }
    if (count($captions) !== $scene_count) {
        vv_log("vv_generate_listing_captions_ai: parsed " . count($captions) . "/{$scene_count} captions, falling back. raw: $text");
        return $fallback_captions;
    }
    ksort($captions);
    return array_values($captions);
}

// ── Caption/header/footer style settings — ported as-is from
// vizard_scriptgen_3.php (hdb_user_settings is generic, not fashion-specific). ──
function vv_get_user_caption_settings($conn, $admin_id, $company_id) {
    $def_cap = [
        'fontfamily'=>'Arial','fontsize'=>28,'fontcolor'=>'#ffffff','fontweight'=>'normal',
        'font_italic'=>0,'font_underline'=>0,'caption_alignment'=>'center','text_align_v'=>'bottom',
        'fontcolor_bg'=>'#000000','fontbg_enable'=>0,'stroke_color'=>'#000000','stroke_width'=>0,
        'shadow_color'=>'#000000','gradient_color'=>'#ff6600','_anim_style'=>'none','_anim_speed'=>1.0,
        '_text_fx'=>'none','_text_fx_col'=>'#ffffff','caption_style'=>'none','caption_position'=>'bottom',
        'display_mode'=>'full','position_x'=>50,'position_y'=>200,'width'=>500,'is_enabled'=>1,
    ];
    $def_hdr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>16,'text_align_v'=>'top']);
    $def_ftr = array_merge($def_cap, ['is_enabled'=>0,'caption_text'=>'','position_y'=>555,'text_align_v'=>'bottom']);

    $us_res = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$company_id ORDER BY id DESC LIMIT 1");
    $us_row = ($us_res && mysqli_num_rows($us_res)) ? mysqli_fetch_assoc($us_res) : null;
    if (!$us_row) {
        return ['caption' => $def_cap, 'header' => $def_hdr, 'footer' => $def_ftr];
    }

    $from_db = [
        'fontfamily'        => $us_row['fontfamily']        ?? $def_cap['fontfamily'],
        'fontsize'          => (int)($us_row['fontsize']    ?? $def_cap['fontsize']),
        'fontcolor'         => $us_row['fontcolor']         ?? $def_cap['fontcolor'],
        'fontweight'        => $us_row['fontweight']        ?? $def_cap['fontweight'],
        'font_italic'       => (int)($us_row['font_italic']    ?? 0),
        'font_underline'    => (int)($us_row['font_underline'] ?? 0),
        'caption_alignment' => $us_row['caption_alignment'] ?? 'center',
        'text_align_v'      => $us_row['text_align_v']      ?? 'bottom',
        'fontcolor_bg'      => $us_row['fontcolor_bg']      ?? '#000000',
        'fontbg_enable'     => (int)($us_row['fontbg_enable'] ?? 0),
        'stroke_color'      => $us_row['stroke_color']      ?? '#000000',
        'stroke_width'      => (int)($us_row['stroke_width'] ?? 0),
        'shadow_color'      => $us_row['shadow_color']      ?? '#000000',
        'gradient_color'    => $us_row['gradient_color']    ?? '#ff6600',
        '_anim_style'       => $us_row['text_animation']    ?? $us_row['animation_style'] ?? 'none',
        '_anim_speed'       => is_numeric($us_row['animation_speed'] ?? null) ? (float)$us_row['animation_speed'] : 1.0,
        '_text_fx'          => $us_row['text_effect']       ?? 'none',
        '_text_fx_col'      => $us_row['text_effect_color'] ?? '#ffffff',
        'caption_style'     => $us_row['caption_style']     ?? 'none',
        'caption_position'  => $us_row['caption_position']  ?? 'bottom',
        'display_mode'      => $us_row['display_mode']      ?? 'full',
        'position_x'        => (int)($us_row['position_x'] ?? 50),
        'position_y'        => (int)($us_row['position_y'] ?? 200),
        'width'             => (int)($us_row['width']     ?? 500),
        'is_enabled'        => 1,
        'caption_text'      => $us_row['caption_text']    ?? '',
    ];
    $cap_settings = array_merge($def_cap, $from_db);
    $hdr_settings = array_merge($def_hdr, [
        'is_enabled'   => (int)($us_row['header_enabled'] ?? 0),
        'caption_text' => $us_row['header_text'] ?? '',
        'fontfamily'   => $us_row['header_fontfamily'] ?? $def_cap['fontfamily'],
        'fontsize'     => (int)($us_row['header_fontsize'] ?? 24),
        'fontcolor'    => $us_row['header_fontcolor'] ?? '#ffffff',
    ]);
    $ftr_settings = array_merge($def_ftr, [
        'is_enabled'   => (int)($us_row['footer_enabled'] ?? 0),
        'caption_text' => $us_row['footer_text'] ?? '',
        'fontfamily'   => $us_row['footer_fontfamily'] ?? $def_cap['fontfamily'],
        'fontsize'     => (int)($us_row['footer_fontsize'] ?? 20),
        'fontcolor'    => $us_row['footer_fontcolor'] ?? '#ffffff',
    ]);
    return ['caption' => $cap_settings, 'header' => $hdr_settings, 'footer' => $ftr_settings];
}

// ── Inserts up to 3 hdb_captions rows for one story — ported as-is. ────────
function vv_insert_scene_captions($conn, $podcast_id, $story_id, $text, $user_settings) {
    $cap = $user_settings['caption'];
    $hdr = $user_settings['header'];
    $ftr = $user_settings['footer'];

    if ((int)($cap['is_enabled'] ?? 1)) {
        $words_arr = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $cap_text  = count($words_arr) > 10 ? implode(' ', array_slice($words_arr, 0, 10)) . '…' : $text;
        $ct  = mysqli_real_escape_string($conn, $cap_text);
        $ff  = mysqli_real_escape_string($conn, $cap['fontfamily'] ?? 'Arial');
        $fc  = mysqli_real_escape_string($conn, $cap['fontcolor'] ?? '#ffffff');
        $fw  = mysqli_real_escape_string($conn, $cap['fontweight'] ?? 'normal');
        $fst = ((int)($cap['font_italic'] ?? 0)) ? 'italic' : 'normal';
        $uline = (int)($cap['font_underline'] ?? 0);
        $ta  = mysqli_real_escape_string($conn, $cap['caption_alignment'] ?? 'center');
        $tav = mysqli_real_escape_string($conn, $cap['text_align_v'] ?? 'bottom');
        $bgc = mysqli_real_escape_string($conn, $cap['fontcolor_bg'] ?? '#000000');
        $bge = (int)($cap['fontbg_enable'] ?? 0);
        $sc  = mysqli_real_escape_string($conn, $cap['stroke_color'] ?? '#000000');
        $sw  = (int)($cap['stroke_width'] ?? 0);
        $se  = $sw > 0 ? 1 : 0;
        $shc = mysqli_real_escape_string($conn, $cap['shadow_color'] ?? '#000000');
        $gc  = mysqli_real_escape_string($conn, $cap['gradient_color'] ?? '#ff6600');
        $ast = mysqli_real_escape_string($conn, $cap['_anim_style'] ?? 'none');
        $asp = is_numeric($cap['_anim_speed'] ?? null) ? (float)$cap['_anim_speed'] : 1.0;
        $tfx = mysqli_real_escape_string($conn, $cap['_text_fx'] ?? 'none');
        $tfc = mysqli_real_escape_string($conn, $cap['_text_fx_col'] ?? '#ffffff');
        $cst = mysqli_real_escape_string($conn, $cap['caption_style'] ?? 'none');
        $cpv = mysqli_real_escape_string($conn, $cap['caption_position'] ?? 'bottom');
        $dm  = mysqli_real_escape_string($conn, $cap['display_mode'] ?? 'full');
        $px  = (int)($cap['position_x'] ?? 50);
        $py  = (int)($cap['position_y'] ?? 200);
        $pw  = min((int)($cap['width'] ?? 500), 350);
        $fs  = (int)($cap['fontsize'] ?? 28);
        mysqli_query($conn, "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
             text_align, text_align_v, bg_color, bg_enabled,
             stroke_color, stroke_width, stroke_enabled,
             shadow_color, gradient_color, text_effects, text_effect_colors,
             panning_zooming_type, panning_zooming_speed,
             position_x, position_y, width, rotation,
             animation_style, animation_speed,
             caption_style, caption_position, display_mode,
             text_decoration, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, 'caption', 'main', '$ct',
             '$ff', $fs, '$fc', '$fw', '$fst', $uline,
             '$ta', '$tav', '$bgc', $bge,
             '$sc', $sw, $se,
             '$shc', '$gc', '$tfx', '$tfc',
             0, 0,
             $px, $py, $pw, 0,
             '$ast', $asp,
             '$cst', '$cpv', '$dm',
             'none', 1, 1)");
    }

    if ((int)($hdr['is_enabled'] ?? 0) && !empty($hdr['caption_text'])) {
        $ht = mysqli_real_escape_string($conn, $hdr['caption_text']);
        $ff = mysqli_real_escape_string($conn, $hdr['fontfamily'] ?? 'Arial');
        $fc = mysqli_real_escape_string($conn, $hdr['fontcolor'] ?? '#ffffff');
        $fw = mysqli_real_escape_string($conn, $hdr['fontweight'] ?? 'normal');
        $fs = (int)($hdr['fontsize'] ?? 24);
        $pw = min((int)($hdr['width'] ?? 1080), 350);
        mysqli_query($conn, "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
             text_align, text_align_v, bg_color, bg_enabled,
             stroke_color, stroke_width, stroke_enabled,
             shadow_color, gradient_color, text_effects, text_effect_colors,
             panning_zooming_type, panning_zooming_speed,
             position_x, position_y, width, rotation,
             animation_style, animation_speed,
             caption_style, caption_position, display_mode,
             text_decoration, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, 'header', 'header', '$ht',
             '$ff', $fs, '$fc', '$fw', 'normal', 0,
             'center', 'top', '#000000', 0,
             '#000000', 0, 0,
             '#000000', '#ff6600', 'none', '#ffffff',
             0, 0,
             50, 16, $pw, 0,
             'none', 1.0,
             'none', 'top', 'full',
             'none', 1, 2)");
    }

    if ((int)($ftr['is_enabled'] ?? 0) && !empty($ftr['caption_text'])) {
        $ft = mysqli_real_escape_string($conn, $ftr['caption_text']);
        $ff = mysqli_real_escape_string($conn, $ftr['fontfamily'] ?? 'Arial');
        $fc = mysqli_real_escape_string($conn, $ftr['fontcolor'] ?? '#ffffff');
        $fw = mysqli_real_escape_string($conn, $ftr['fontweight'] ?? 'normal');
        $fs = (int)($ftr['fontsize'] ?? 20);
        $pw = min((int)($ftr['width'] ?? 1080), 350);
        mysqli_query($conn, "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline,
             text_align, text_align_v, bg_color, bg_enabled,
             stroke_color, stroke_width, stroke_enabled,
             shadow_color, gradient_color, text_effects, text_effect_colors,
             panning_zooming_type, panning_zooming_speed,
             position_x, position_y, width, rotation,
             animation_style, animation_speed,
             caption_style, caption_position, display_mode,
             text_decoration, is_visible, z_index)
            VALUES
            ($podcast_id, $story_id, 'footer', 'footer', '$ft',
             '$ff', $fs, '$fc', '$fw', 'normal', 0,
             'center', 'bottom', '#000000', 0,
             '#000000', 0, 0,
             '#000000', '#ff6600', 'none', '#ffffff',
             0, 0,
             50, 555, $pw, 0,
             'none', 1.0,
             'none', 'bottom', 'full',
             'none', 1, 3)");
    }
}

// ── Hashtag/keyword builder — ported as-is, generic (not fashion-specific). ──
function vv_build_hashtags_keywords($conn, $all_caption_text, $biz_part, $loc_part, $aud_part) {
    $stop = ['the','and','for','you','your','with','that','this','are','can','will','have',
             'from','they','their','what','about','there','more','some','would','could',
             'should','been','were','was','one','two','first','then','than','very','just',
             'like','into','over','also','after','other','only','area','near','local',
             'of','a','an','in','is','to','on','at','by','it','as','be','or','if','its',
             'we','our','us','so','not','but','all','any','each','out','up','off','no',
             'do','does','did','has','had','i','my','me','he','she','him','her','them'];

    $words  = array_diff(str_word_count(strtolower($all_caption_text), 1), $stop);
    $words  = array_filter($words, fn($w) => strlen($w) > 2);
    $kw_arr = array_slice(array_values(array_unique($words)), 0, 10);

    if ($biz_part) $kw_arr[] = strtolower(trim($biz_part));
    if ($loc_part) $kw_arr[] = strtolower(trim($loc_part));
    if ($aud_part) $kw_arr[] = strtolower(trim($aud_part));

    $kw_arr = array_values(array_unique(array_filter($kw_arr, fn($w) => trim($w) !== '')));

    $ht_arr = array_map(function($w){ return '#' . preg_replace('/\s+/', '', $w); }, array_slice($kw_arr, 0, 12));
    $ht_arr = array_values(array_unique($ht_arr));

    return [
        mysqli_real_escape_string($conn, implode(', ', $ht_arr)),
        mysqli_real_escape_string($conn, implode(', ', $kw_arr)),
    ];
}

// ── Manifest path helper — every Step 1/2 handler below reads/writes the
// same per-draft manifest file, so centralize the path + load/save. ───────
function vv_manifest_path($room_dir, $draft_id) {
    return $room_dir . "draft_{$draft_id}_manifest.json";
}
function vv_load_manifest($room_dir, $draft_id) {
    $p = vv_manifest_path($room_dir, $draft_id);
    return file_exists($p) ? (json_decode(file_get_contents($p), true) ?: []) : [];
}
function vv_save_manifest($room_dir, $draft_id, $manifest) {
    file_put_contents(vv_manifest_path($room_dir, $draft_id), json_encode($manifest));
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — room_upload_image  (Step 1)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'room_upload_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    if ($draft_id === '' || !preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) {
        $draft_id = 'd' . time() . mt_rand(1000, 9999);
    }

    $file = $_FILES['room_image'] ?? null;
    if (!$file || empty($file['tmp_name'])) { echo json_encode(['success'=>false,'message'=>'No file received']); exit; }

    $allowed = ['image/jpeg','image/png','image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid file type — use jpg, png, or webp']); exit; }
    if ($file['size'] > 15*1024*1024) { echo json_encode(['success'=>false,'message'=>'File too large (max 15MB)']); exit; }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    if (!is_dir($room_dir)) @mkdir($room_dir, 0777, true);

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $room_key = uniqid('room_');
    $orig_file = "draft_{$draft_id}_{$room_key}_orig.{$ext}";
    $orig_path = $room_dir . $orig_file;

    $moved = move_uploaded_file($file['tmp_name'], $orig_path);
    if (!$moved) $moved = @copy($file['tmp_name'], $orig_path);
    if (!$moved || !file_exists($orig_path)) {
        echo json_encode(['success'=>false,'message'=>'Could not save uploaded file.']); exit;
    }

    $room_type = trim($_POST['room_type'] ?? '');
    $room_types_valid = array_keys(vv_room_types());
    if (!in_array($room_type, $room_types_valid, true)) {
        $room_type = vv_analyze_room_type($apiKey, $orig_path);
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($room_dir, '/')), '/') . '/';

    $manifest = vv_load_manifest($room_dir, $draft_id);
    $manifest[] = [
        'room_key'   => $room_key,
        'room_type'  => $room_type,
        'orig_file'  => $orig_file,
        'staged_file'=> null,
        'style'      => null,
        'included'   => true,   // whether this room is part of the final video — Step 2 toggles this
        'order'      => vv_room_type_default_priority()[$room_type] ?? 99,
        'created_at' => date('c'),
    ];
    vv_save_manifest($room_dir, $draft_id, $manifest);

    vv_log("room_upload_image: draft=$draft_id room_key=$room_key room_type=$room_type owner=$owner_id co=$co_id");

    echo json_encode([
        'success'    => true,
        'draft_id'   => $draft_id,
        'room_key'   => $room_key,
        'room_type'  => $room_type,
        'room_label' => vv_room_types()[$room_type] ?? 'Other Room',
        'orig_url'   => $protocol . '://' . $host . $web_path . $orig_file,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — generate_staged_room  (Step 1)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_staged_room') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }
    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    $room_key = trim($_POST['room_key'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id) || $room_key === '') {
        echo json_encode(['success'=>false,'message'=>'Missing draft_id or room_key']); exit;
    }

    $style = trim($_POST['style'] ?? 'modern');
    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);

    $idx = null;
    foreach ($manifest as $i => $m) {
        if (($m['room_key'] ?? '') === $room_key) { $idx = $i; break; }
    }
    if ($idx === null) { echo json_encode(['success'=>false,'message'=>'Room not found — upload it first']); exit; }

    $room_type = trim($_POST['room_type'] ?? '') ?: $manifest[$idx]['room_type'];
    $room_types_valid = array_keys(vv_room_types());
    if (!in_array($room_type, $room_types_valid, true)) $room_type = 'other';

    $orig_path = $room_dir . $manifest[$idx]['orig_file'];
    if (!is_file($orig_path)) { echo json_encode(['success'=>false,'message'=>'Original room photo missing on disk']); exit; }

    $STAGING_COST = 5;
    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);
    if ($balance < $STAGING_COST) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $STAGING_COST credits, you have $balance"]); exit;
    }

    $result = vv_generate_staged_room($falApiKey, $orig_path, $room_type, $style, $owner_id, $co_id, $draft_id . '_' . $room_key);
    if (!$result['success']) { echo json_encode($result); exit; }

    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $STAGING_COST) WHERE id=$deduct_from");

    $manifest[$idx]['room_type']   = $room_type;
    $manifest[$idx]['staged_file'] = $result['filename'];
    $manifest[$idx]['style']       = $style;
    $manifest[$idx]['order']       = $manifest[$idx]['order'] ?? (vv_room_type_default_priority()[$room_type] ?? 99);
    vv_save_manifest($room_dir, $draft_id, $manifest);

    vv_log("generate_staged_room: draft=$draft_id room_key=$room_key room_type=$room_type style=$style cost=$STAGING_COST");

    echo json_encode([
        'success'         => true,
        'draft_id'        => $draft_id,
        'room_key'        => $room_key,
        'room_type'       => $room_type,
        'room_label'      => vv_room_types()[$room_type] ?? 'Other Room',
        'style'           => $style,
        'public_url'      => $result['public_url'],
        'filename'        => $result['filename'],
        'credits_charged' => $STAGING_COST,
        'credits_balance' => $balance - $STAGING_COST,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — room_list_images  (Step 1 + Step 2 both read this)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'room_list_images') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) { echo json_encode(['success'=>true,'rooms'=>[]]); exit; }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path = '/' . ltrim(str_replace($doc_root, '', rtrim($room_dir, '/')), '/') . '/';

    // Sort by saved 'order' so Step 2's sequence (once set) survives reloads.
    usort($manifest, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

    $rooms = array_map(function($m) use ($protocol, $host, $web_path) {
        return [
            'room_key'    => $m['room_key'],
            'room_type'   => $m['room_type'],
            'room_label'  => vv_room_types()[$m['room_type']] ?? 'Other Room',
            'orig_url'    => $protocol.'://'.$host.$web_path.$m['orig_file'],
            'staged_url'  => $m['staged_file'] ? ($protocol.'://'.$host.$web_path.$m['staged_file']) : null,
            'lighting_url'=> !empty($m['lighting_file']) ? ($protocol.'://'.$host.$web_path.$m['lighting_file']) : null,
            'lighting_mood' => $m['lighting_mood'] ?? 'none',
            'style'       => $m['style'] ?? null,
            'included'    => (bool)($m['included'] ?? true),
            'order'       => (int)($m['order'] ?? 99),
        ];
    }, $manifest);

    echo json_encode(['success'=>true, 'rooms'=>$rooms]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — room_delete_image  (Step 1)
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'room_delete_image') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    $room_key = trim($_POST['room_key'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id) || $room_key === '') {
        echo json_encode(['success'=>false,'message'=>'Missing draft_id or room_key']); exit;
    }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);

    $new_manifest = [];
    foreach ($manifest as $m) {
        if (($m['room_key'] ?? '') === $room_key) {
            if (!empty($m['orig_file']))   @unlink($room_dir . $m['orig_file']);
            if (!empty($m['staged_file'])) @unlink($room_dir . $m['staged_file']);
            continue;
        }
        $new_manifest[] = $m;
    }
    vv_save_manifest($room_dir, $draft_id, $new_manifest);

    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — room_clear_session  (Step 1 — "Start Over")
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'room_clear_session') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) { echo json_encode(['success'=>true]); exit; }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);
    foreach ($manifest as $m) {
        if (!empty($m['orig_file']))   @unlink($room_dir . $m['orig_file']);
        if (!empty($m['staged_file'])) @unlink($room_dir . $m['staged_file']);
    }
    @unlink(vv_manifest_path($room_dir, $draft_id));

    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — save_room_sequence  (Step 2: Select Video Sequence)
// ═════════════════════════════════════════════════════════════════════════════
// Receives the FULL ordered list of room_keys the person wants in the
// final video (drag-reordered client-side) plus which ones are toggled
// off. Writes 'order' (position in the array) and 'included' back onto
// each manifest row — this is exactly the data Step 4 will read to build
// hdb_podcast_stories rows later, one per included room, in this order.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_room_sequence') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id)) { echo json_encode(['success'=>false,'message'=>'Missing draft_id']); exit; }

    // sequence: JSON array like [{"room_key":"room_abc","included":true}, ...]
    // already in the desired play order — position in this array = order.
    $sequence = json_decode($_POST['sequence'] ?? '[]', true);
    if (!is_array($sequence)) { echo json_encode(['success'=>false,'message'=>'Invalid sequence payload']); exit; }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);

    $order_map = [];
    foreach ($sequence as $pos => $entry) {
        $order_map[$entry['room_key']] = ['order' => $pos, 'included' => (bool)($entry['included'] ?? true)];
    }
    foreach ($manifest as $i => $m) {
        if (isset($order_map[$m['room_key']])) {
            $manifest[$i]['order']    = $order_map[$m['room_key']]['order'];
            $manifest[$i]['included'] = $order_map[$m['room_key']]['included'];
        }
    }
    vv_save_manifest($room_dir, $draft_id, $manifest);

    $included_count = count(array_filter($manifest, fn($m) => ($m['included'] ?? true) && !empty($m['staged_file'])));
    vv_log("save_room_sequence: draft=$draft_id included_count=$included_count");

    echo json_encode(['success'=>true, 'included_count'=>$included_count]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — apply_lighting_mood  (Step 3 — optional)
// ═════════════════════════════════════════════════════════════════════════════
// Runs vv_apply_lighting_mood() on ONE room's STAGED image (not the
// original). mood='none' clears any previously-applied mood and reverts
// that room to its plain staged image — free, no fal call. Any other mood
// charges a flat 3cr (cheaper than the 5cr staging pass, since this is a
// lighter single-purpose edit) and overwrites that room's lighting file on
// every call (regenerate-by-default, same pattern as Step 1).
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'apply_lighting_mood') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id = trim($_POST['draft_id'] ?? '');
    $room_key = trim($_POST['room_key'] ?? '');
    $mood     = trim($_POST['mood'] ?? 'none');
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id) || $room_key === '') {
        echo json_encode(['success'=>false,'message'=>'Missing draft_id or room_key']); exit;
    }
    if (!array_key_exists($mood, vv_lighting_moods())) {
        echo json_encode(['success'=>false,'message'=>'Unknown lighting mood']); exit;
    }

    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);

    $idx = null;
    foreach ($manifest as $i => $m) {
        if (($m['room_key'] ?? '') === $room_key) { $idx = $i; break; }
    }
    if ($idx === null) { echo json_encode(['success'=>false,'message'=>'Room not found']); exit; }
    if (empty($manifest[$idx]['staged_file'])) { echo json_encode(['success'=>false,'message'=>'Stage this room first (Step 1) before changing its lighting']); exit; }

    // ── 'none' = revert to plain staged image, no fal call, no charge ──────
    if ($mood === 'none') {
        $manifest[$idx]['lighting_file'] = null;
        $manifest[$idx]['lighting_mood'] = 'none';
        vv_save_manifest($room_dir, $draft_id, $manifest);
        echo json_encode(['success'=>true, 'mood'=>'none', 'public_url'=>null]); exit;
    }

    if (!$falApiKey) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }

    $LIGHTING_COST = 3;
    $cred_user = vv_safe_fetch($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $bal_row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$deduct_from LIMIT 1");
    $balance = (int)($bal_row['credit_balance'] ?? 0);
    if ($balance < $LIGHTING_COST) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $LIGHTING_COST credits, you have $balance"]); exit;
    }

    $staged_path = $room_dir . $manifest[$idx]['staged_file'];
    if (!is_file($staged_path)) { echo json_encode(['success'=>false,'message'=>'Staged room photo missing on disk']); exit; }

    $result = vv_apply_lighting_mood($falApiKey, $staged_path, $mood, $owner_id, $co_id, $draft_id . '_' . $room_key);
    if (!$result['success']) { echo json_encode($result); exit; }

    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = GREATEST(0, credit_balance - $LIGHTING_COST) WHERE id=$deduct_from");

    $manifest[$idx]['lighting_file'] = $result['filename'];
    $manifest[$idx]['lighting_mood'] = $mood;
    vv_save_manifest($room_dir, $draft_id, $manifest);

    vv_log("apply_lighting_mood: draft=$draft_id room_key=$room_key mood=$mood cost=$LIGHTING_COST");

    echo json_encode([
        'success'         => true,
        'room_key'        => $room_key,
        'mood'             => $mood,
        'mood_label'       => vv_lighting_moods()[$mood],
        'public_url'       => $result['public_url'],
        'credits_charged'  => $LIGHTING_COST,
        'credits_balance'  => $balance - $LIGHTING_COST,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER — create_podcast_from_rooms  (Step 4: Video Setting)
// ═════════════════════════════════════════════════════════════════════════════
// Builds hdb_podcasts + one hdb_podcast_stories row PER INCLUDED ROOM, in
// the order saved by Step 2 (save_room_sequence). This is the real-estate
// equivalent of create_podcast_from_step1 — but where that flow reuses ONE
// shared hero image across every scene, here each scene gets its OWN
// image (that room's lit-if-set, else plain staged, file) and its OWN
// video_prompt (that room type's camera move, from vv_room_camera_prompt).
// Like the dress flow, this only LAYS DOWN the rows — actually animating
// each room into a clip happens later, scene-by-scene, in Step 5.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_podcast_from_rooms') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);

    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) { echo json_encode(['success'=>false,'message'=>'No company found']); exit; }

    $draft_id      = trim($_POST['draft_id'] ?? '');
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? ''); // property highlights — fed to the caption AI
    $caption_style = trim($_POST['caption_style'] ?? 'Elegant');
    if (!in_array($caption_style, ['Elegant', 'Emotional', 'Premium', 'Sales-Oriented'], true)) $caption_style = 'Elegant';
    if (!preg_match('/^[a-zA-Z0-9]{6,40}$/', $draft_id) || $title === '') {
        echo json_encode(['success'=>false,'message'=>'Missing draft_id or title']); exit;
    }

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS draft_id VARCHAR(40) DEFAULT NULL");
    $esc_draft_id = mysqli_real_escape_string($conn, $draft_id);
    $existing = vv_safe_fetch($conn, "SELECT id FROM hdb_podcasts WHERE draft_id='$esc_draft_id' AND admin_id=$admin_id LIMIT 1");
    if ($existing) {
        echo json_encode(['success'=>false,'message'=>"Scenes were already built for this draft (podcast #{$existing['id']}) — start a new draft to build again."]); exit;
    }

    // ── Pull the included, ordered rooms straight from the manifest — this
    // IS the scene list, no separate "style" lookup needed (unlike the
    // dress flow's mdl_model_pose_styles). ─────────────────────────────────
    $room_dir = __DIR__ . "/user_media/user_id_{$owner_id}_company_id_{$co_id}/rooms/";
    $manifest = vv_load_manifest($room_dir, $draft_id);
    $included = array_values(array_filter($manifest, fn($m) => ($m['included'] ?? true) && !empty($m['staged_file'])));
    usort($included, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

    if (empty($included)) {
        echo json_encode(['success'=>false,'message'=>'No staged, included rooms found — finish Steps 1 and 2 first']); exit;
    }

    // ── Video mode + cost — decided here, at build time, not deferred to a
    // later step. Static is a flat fee since it's just ffmpeg zoompan on
    // existing images; AI video scales per room since each one is a real
    // image-to-video generation fired later by the webhook pipeline. ──────
    $mode = trim($_POST['mode'] ?? 'static');
    if (!in_array($mode, ['static', 'ai_video'], true)) $mode = 'static';
    $scene_total_for_cost = count($included);
    $cost = ($mode === 'ai_video') ? ($scene_total_for_cost * 20) : 50;
    $videogen_flag = ($mode === 'ai_video') ? 1 : 0;

    // ── Credit check — same role/team_lead_id billing-entity resolution
    // used elsewhere in this file, but the balance column itself depends on
    // plan_type (free-trial accounts draw from credit_balance2). ──────────
    $cred_user   = vv_safe_fetch($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $deduct_from = (!empty($cred_user) && trim((string)($cred_user['role'] ?? '')) === 'Team Member' && (int)($cred_user['team_lead_id'] ?? 0) > 0)
        ? (int)$cred_user['team_lead_id'] : $admin_id;
    $credit_info = vv_resolve_credit_balance($conn, $deduct_from);
    if ($credit_info['balance'] < $cost) {
        echo json_encode(['success'=>false,'message'=>"Insufficient credits — this needs $cost credits, you have {$credit_info['balance']}"]); exit;
    }

    $protocol     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host         = $_SERVER['HTTP_HOST'];
    $doc_root     = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $image_folder = '/' . ltrim(str_replace($doc_root, '', rtrim($room_dir, '/')), '/') . '/';

    // ── Target video length — same 30/45/60s + 6s-floor/12s-ceiling clamp
    // logic as the dress-tryon flow's Step 4. ───────────────────────────────
    $duration_target = (int)($_POST['duration_target'] ?? 30);
    if (!in_array($duration_target, [30, 45, 60], true)) $duration_target = 30;

    $scene_total = count($included);
    $usable_durations = [];
    foreach ($included as $r) {
        $duration = $scene_total > 0 ? (int)ceil($duration_target / $scene_total) : 6;
        $usable_durations[] = max(6, min($duration, 12));
    }
    $actual_total_secs = array_sum($usable_durations);

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_group VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS ai_subgroup VARCHAR(255) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS thumbnail VARCHAR(500) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

    $_co_row   = vv_safe_fetch($conn, "SELECT group_name, subgroup_name, ai_group, ai_subgroup, target_location, target_audience FROM hdb_companies WHERE id=$co_id LIMIT 1") ?: [];
    $category  = ($_co_row['ai_subgroup'] ?? '') ?: ($_co_row['subgroup_name'] ?? '') ?: 'real_estate';
    $ai_group  = ($_co_row['ai_group']    ?? '') ?: ($_co_row['group_name']    ?? '') ?: 'real_estate';
    $ai_subgrp = ($_co_row['ai_subgroup'] ?? '') ?: ($_co_row['subgroup_name'] ?? '') ?: 'real_estate';
    $loc_part  = trim($_co_row['target_location'] ?? '');
    $aud_part  = trim($_co_row['target_audience'] ?? '');

    // ── Build per-room scene data: image, camera-move prompt, fallback
    // caption (room label) BEFORE calling the caption AI, so the AI gets
    // the full room order as context. ──────────────────────────────────────
    $scene_data = [];
    foreach ($included as $i => $r) {
        $image_file = !empty($r['lighting_file']) && ($r['lighting_mood'] ?? 'none') !== 'none' ? $r['lighting_file'] : $r['staged_file'];
        $room_label = vv_room_types()[$r['room_type']] ?? 'Other Room';
        $scene_data[] = [
            'room_key'    => $r['room_key'],
            'room_type'   => $r['room_type'],
            'room_label'  => $room_label,
            'image_file'  => $image_file,
            'duration'    => $usable_durations[$i],
            'video_prompt'=> vv_room_camera_prompt($r['room_type']),
            'caption'     => $room_label, // fallback if AI captioning fails/unavailable
        ];
    }

    $ai_captions = vv_generate_listing_captions_ai(
        $apiKey,
        array_column($scene_data, 'room_label'),
        $description,
        $caption_style,
        array_column($scene_data, 'caption')
    );
    foreach ($scene_data as $i => &$sd_ref) {
        if (isset($ai_captions[$i]) && trim($ai_captions[$i]) !== '') $sd_ref['caption'] = trim($ai_captions[$i]);
    }
    unset($sd_ref);

    [$hashtags, $keywords] = vv_build_hashtags_keywords(
        $conn,
        implode(' ', array_column($scene_data, 'caption')),
        $ai_subgrp, $loc_part, $aud_part
    );

    $esc_title       = mysqli_real_escape_string($conn, $title);
    $esc_ai_group    = mysqli_real_escape_string($conn, $ai_group);
    $esc_ai_subgroup = mysqli_real_escape_string($conn, $ai_subgrp);
    $esc_category    = mysqli_real_escape_string($conn, $category);
    $lang_code = 'en';
    $reel_type = 'promo';
    $topic_key = 'real_estate';
    $today     = date('Y-m-d');

    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS video_mode VARCHAR(20) DEFAULT 'static'");
    $esc_mode = mysqli_real_escape_string($conn, $mode);

    // hashtags/keywords are ALREADY escaped by vv_build_hashtags_keywords — do not re-escape.
    $sql_pod = "INSERT INTO hdb_podcasts
        (admin_id, team_lead_id, company_id, title, lang_code, video_type, video_status, internal_status,
         created_date, updated_at, niche, category, topic_key, hashtags, keywords,
         host_voice, guest_voice, voice_rate, is_campaign,
         logo_flag, facebook_status, tiktok_status, instagram_status,
         youtube_status, twitter_status, linkedin_status,
         schedule_date, schedule_time, publish_date, video_format, video_media, music_file, hook_name,
         videogen_flag, ai_group, ai_subgroup, draft_id, video_mode)
        VALUES
        ($admin_id, $admin_id, $co_id, '$esc_title', '$lang_code', '$reel_type', 'draft', 'scenes_ready',
         '$today', NOW(), 'real_estate', '$esc_category', '$topic_key', '$hashtags', '$keywords',
         '', '', 1.0, 0,
         0, 'pending', 'pending', 'pending',
         'pending', 'pending', 'pending',
         '$today', '09:00', '$today', 'vertical', 'video', '', '',
         $videogen_flag, '$esc_ai_group', '$esc_ai_subgroup', '$esc_draft_id', '$esc_mode')";

    if (!mysqli_query($conn, $sql_pod)) {
        echo json_encode(['success'=>false,'message'=>'hdb_podcasts INSERT failed: ' . mysqli_error($conn)]); exit;
    }
    $podcast_id = mysqli_insert_id($conn);

    // ── Thumbnail — copy the FIRST sequenced room's image into
    // podcast_thumbnails/, same flat folder/anchoring approach as the
    // dress-tryon flow. ──────────────────────────────────────────────────
    $thumb_dir = __DIR__ . '/podcast_thumbnails/';
    $first_image_file   = $scene_data[0]['image_file'];
    $thumb_ext          = strtolower(pathinfo($first_image_file, PATHINFO_EXTENSION)) ?: 'jpg';
    $thumb_filename     = "podcast_{$podcast_id}_thumb.{$thumb_ext}";
    $thumb_full_path    = $thumb_dir . $thumb_filename;
    $thumb_source_path  = $room_dir . $first_image_file;

    $thumb_saved = false; $thumb_error = null;
    if (!is_dir($thumb_dir) && !mkdir($thumb_dir, 0777, true) && !is_dir($thumb_dir)) {
        $thumb_error = 'mkdir failed for ' . $thumb_dir;
    } elseif (!is_file($thumb_source_path)) {
        $thumb_error = "source image missing at $thumb_source_path";
    } else {
        $thumb_saved = @copy($thumb_source_path, $thumb_full_path);
        if (!$thumb_saved) { $e = error_get_last(); $thumb_error = 'copy() returned false' . ($e ? (' — ' . $e['message']) : ''); }
    }
    if ($thumb_saved) {
        $thumb_rel_path = "videovizard/podcast_thumbnails/{$thumb_filename}";
        mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='" . mysqli_real_escape_string($conn, $thumb_rel_path) . "' WHERE id=$podcast_id LIMIT 1");
    } else {
        vv_log("create_podcast_from_rooms: THUMBNAIL COPY FAILED for podcast=$podcast_id | source=$thumb_source_path | reason=$thumb_error");
    }

    $cap_settings = vv_get_user_caption_settings($conn, $admin_id, $co_id);

    $scenes_out = [];
    $seq_no = 0;
    foreach ($scene_data as $sd) {
        $seq_no++;
        $text = $sd['caption'];
        $te  = mysqli_real_escape_string($conn, $sd['caption']);
        $de  = mysqli_real_escape_string($conn, substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''));
        $pe  = mysqli_real_escape_string($conn, $sd['video_prompt']);
        $ife = mysqli_real_escape_string($conn, $sd['image_file']);
        $ifo = mysqli_real_escape_string($conn, $image_folder);
        $tke = $esc_title;

        $ins = "INSERT INTO hdb_podcast_stories
            (podcast_id, lang_code, category, topic_key, title, actor,
             text_contents, text_display, duration, prompt, video_prompt, visual_type,
             status, created_date, seq_no, logo_flag, hashtags, natural_language_tags,
             voice_id, voice_rate, image_file, image_folder, videogen_flag)
            VALUES
            ($podcast_id, '$lang_code', '$esc_category', '$topic_key', '$tke', 'host',
             '$te', '$de', {$sd['duration']}, '$pe', '$pe', 'promo',
             'PENDING', NOW(), $seq_no, 0, '', '',
             '', 1.0, '$ife', '$ifo', $videogen_flag)";

        if (!mysqli_query($conn, $ins)) {
            vv_log("create_podcast_from_rooms: story insert failed room={$sd['room_type']} | " . mysqli_error($conn));
            continue;
        }
        $story_id = mysqli_insert_id($conn);
        vv_insert_scene_captions($conn, $podcast_id, $story_id, $sd['caption'], $cap_settings);

        $scenes_out[] = [
            'story_id'   => $story_id,
            'seq_no'     => $seq_no,
            'room_type'  => $sd['room_type'],
            'room_label' => $sd['room_label'],
            'caption'    => $sd['caption'],
            'image_url'  => $protocol . '://' . $host . $image_folder . $sd['image_file'],
        ];
    }

    if (empty($scenes_out)) {
        echo json_encode(['success'=>false,'message'=>'All scene inserts failed — check error log']); exit;
    }

    // ── Charge now that the build actually succeeded — not before, so a
    // failed build never costs the user credits. ──────────────────────────
    $bal_col = $credit_info['column'];
    mysqli_query($conn, "UPDATE hdb_users SET {$bal_col} = GREATEST(0, {$bal_col} - $cost) WHERE id=$deduct_from");
    $credits_balance_after = $credit_info['balance'] - $cost;

    vv_log("create_podcast_from_rooms: created podcast=$podcast_id with " . count($scenes_out) . " scenes (draft=$draft_id) mode=$mode cost=$cost charged_to=$deduct_from col=$bal_col target={$duration_target}s actual={$actual_total_secs}s");
    echo json_encode([
        'success'           => true,
        'podcast_id'        => $podcast_id,
        'scenes'            => $scenes_out,
        'scene_count'       => count($scenes_out),
        'video_mode'        => $mode,
        'credits_charged'   => $cost,
        'credits_balance'   => $credits_balance_after,
        'duration_target'   => $duration_target,
        'actual_total_secs' => $actual_total_secs,
        'thumbnail_url'     => $protocol . '://' . $host . $image_folder . $first_image_file,
        'thumbnail_saved'   => $thumb_saved,
        'thumbnail_error'   => $thumb_saved ? null : $thumb_error,
        'redirect_url'      => 'videomaker.php?podcast_id=' . $podcast_id,
    ]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS — Step 4: Background Music (generic — ported as-is, these
// don't reference fashion/staging at all, they just operate on
// hdb_podcasts.music_file / music_volume / voice_volume). ──────────────────
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_music_library') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $dir = __DIR__ . '/podcast_music/';
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!preg_match('/\.(mp3|wav|ogg|m4a)$/i', $f)) continue;
            $files[] = ['filename' => $f, 'size' => (int) @filesize($dir . $f)];
        }
    }
    usort($files, fn($a, $b) => strcmp($a['filename'], $b['filename']));
    echo json_encode(['success' => true, 'files' => $files]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_podcast_music') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }

    if (!isset($_FILES['music_file']) || $_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>'Upload error: ' . ($_FILES['music_file']['error'] ?? 'no file')]); exit;
    }
    $file = $_FILES['music_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3','wav','ogg','m4a'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only MP3/WAV/OGG/M4A allowed']); exit;
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        echo json_encode(['success'=>false,'message'=>'Max 20MB']); exit;
    }

    $dir = __DIR__ . '/podcast_music/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $filename = 'music_' . $podcast_id_m . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file']); exit;
    }
    $esc = mysqli_real_escape_string($conn, $filename);
    mysqli_query($conn, "UPDATE hdb_podcasts SET music_file='$esc' WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success'=>true,'filename'=>$filename]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_podcast_music') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }
    $file = mysqli_real_escape_string($conn, $_POST['music_file'] ?? '');
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET music_file='$file' WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success' => (bool)$ok]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_podcast_volumes') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS music_volume DECIMAL(4,2) NOT NULL DEFAULT 0.30");
    @mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS voice_volume DECIMAL(4,2) NOT NULL DEFAULT 1.00");

    $mv = (float)($_POST['music_volume'] ?? 0.30);
    $vv = (float)($_POST['voice_volume'] ?? 1.00);
    if ($mv > 2.0) $mv = $mv / 100.0;
    if ($vv > 2.0) $vv = $vv / 100.0;
    $mv = max(0, min(1, $mv));
    $vv = max(0, min(1, $vv));
    $podcast_id_m = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id_m) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); exit; }

    mysqli_query($conn, "UPDATE hdb_podcasts SET music_volume=$mv, voice_volume=$vv WHERE id=$podcast_id_m LIMIT 1");
    echo json_encode(['success' => true, 'music_volume' => $mv, 'voice_volume' => $vv]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene_caption') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $story_id = (int)($_POST['story_id'] ?? 0);
    $caption  = trim($_POST['caption'] ?? '');
    if (!$story_id || $caption === '') { echo json_encode(['success'=>false,'message'=>'Missing story_id or caption']); exit; }

    $esc_caption = mysqli_real_escape_string($conn, $caption);
    $esc_display = mysqli_real_escape_string($conn, substr($caption, 0, 50) . (strlen($caption) > 50 ? '...' : ''));

    mysqli_query($conn, "UPDATE hdb_podcast_stories SET text_contents='$esc_caption', text_display='$esc_display' WHERE id=$story_id LIMIT 1");

    $words_arr = preg_split('/\s+/', $caption, -1, PREG_SPLIT_NO_EMPTY);
    $cap_text  = count($words_arr) > 10 ? implode(' ', array_slice($words_arr, 0, 10)) . '…' : $caption;
    $esc_cap_text = mysqli_real_escape_string($conn, $cap_text);
    mysqli_query($conn, "UPDATE hdb_captions SET text_content='$esc_cap_text' WHERE story_id=$story_id AND caption_type='caption' AND caption_name='main' LIMIT 1");

    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// CREDIT RESOLUTION — free-trial accounts spend from a separate
// credit_balance2 pool instead of the normal credit_balance. Checked on
// whichever user id is actually being billed (self, or the team lead for
// team members), same as the existing role/team_lead_id pattern elsewhere
// in this file. Falls back to plain credit_balance if plan_type /
// credit_balance2 don't exist yet on this install.
// ═════════════════════════════════════════════════════════════════════════════
function vv_resolve_credit_balance($conn, $billing_user_id) {
    $res = @mysqli_query($conn, "SELECT plan_type, credit_balance, credit_balance2 FROM hdb_users WHERE id=$billing_user_id LIMIT 1");
    if ($res === false) {
        $row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$billing_user_id LIMIT 1") ?: [];
        return ['column' => 'credit_balance', 'balance' => (float) ($row['credit_balance'] ?? 0)];
    }
    $row = mysqli_fetch_assoc($res) ?: [];
    $is_trial = stripos((string) ($row['plan_type'] ?? ''), 'trial') !== false;
    return $is_trial
        ? ['column' => 'credit_balance2', 'balance' => (float) ($row['credit_balance2'] ?? 0)]
        : ['column' => 'credit_balance',  'balance' => (float) ($row['credit_balance']  ?? 0)];
}


[$owner_id_page, $co_id_page] = vv_resolve_user($conn, $admin_id, $company_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Real Estate Staging Video — VideoVizard</title>
<style>
/* ── Reset & Root — identical theme to vizard_scriptgen_3.php ─────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --purple:    #8b5cf6;
  --purple-lt: #ede9fe;
  --green:     #10b981;
  --orange:    #f59e0b;
  --orange-lt: #fef3c7;
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --card:      #ffffff;
  --shadow:    0 4px 12px rgba(0,0,0,0.08);
}
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

/* ── Header ─────────────────────────────────────────────────────────────────── */
.app-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; background: linear-gradient(90deg, #0f2a44, #143b63); color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 1000; }
.brand { display: flex; align-items: center; gap: 8px; text-decoration: none; }
.brand-name { font-size: 18px; font-weight: 700; }
.brand-name .v { color: #fff; }
.brand-name .z { color: #5fd1ff; }
.header-back { color: rgba(255,255,255,.75); font-size: 13px; font-weight: 600; text-decoration: none; transition: color .2s; }
.header-back:hover { color: #5fd1ff; }

.page-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 28px 16px 48px; }

.wiz-card { background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); width: 100%; max-width: 700px; overflow: hidden; }
.wiz-header { padding: 18px 24px 16px; background: linear-gradient(90deg, #0f2a44, #143b63); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.wiz-header h1 { font-size: 20px; font-weight: 700; color: #fff; }
.wiz-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 2px 0 0; }
.reset-btn { flex-shrink: 0; padding: 8px 14px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.25); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background .15s, border-color .15s; white-space: nowrap; }
.reset-btn:hover { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.4); }
.reset-btn:disabled { opacity: .5; cursor: not-allowed; }
.wiz-body { padding: 24px; }

/* ── Accordion step bars ─────────────────────────────────────────────────── */
.settings-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; background: #f7f9fc; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 0; cursor: pointer; transition: border-color .15s; }
.settings-bar:hover { border-color: var(--purple); }
.settings-bar-label { font-size: 11px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: .06em; margin-right: 2px; white-space: nowrap; }
.settings-bar-edit  { font-size: 11px; color: var(--purple); margin-left: auto; white-space: nowrap; }
.settings-bar-summary { font-size: 12px; font-weight: 600; color: var(--dark-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.step-section { margin-bottom: 18px; }
.step-body { padding: 16px 4px 4px; }

.garment-heading    { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; }
.garment-subheading { font-size: 12px; color: var(--muted); margin-bottom: 14px; line-height: 1.5; }

/* ── Step 1 — room upload grid ───────────────────────────────────────────── */
.room-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap: 12px; margin-bottom: 8px; }
.room-card { position: relative; border: 1.5px solid var(--border); border-radius: 10px; overflow: hidden; background: #f8fafc; padding: 8px; }
.room-card img { width: 100%; height: 110px; object-fit: cover; border-radius: 6px; background: #e5e7eb; display: block; }
.room-card select { width: 100%; margin-top: 6px; padding: 5px; font-size: 11px; border-radius: 6px; border: 1.5px solid var(--border); color: var(--text); }
.room-card button { width: 100%; margin-top: 6px; padding: 7px; font-size: 12px; font-weight: 600; border-radius: 7px; border: none; cursor: pointer; }
.room-card button.primary { background: var(--purple); color: #fff; }
.room-card button.primary:hover { box-shadow: 0 2px 8px rgba(139,92,246,.3); }
.room-card button.primary:disabled { opacity: .5; cursor: default; }
.room-card-del { position: absolute; top: 12px; right: 12px; width: 20px; height: 20px; border-radius: 50%; background: rgba(0,0,0,.55); color: #fff; border: none; font-size: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; line-height: 1; padding: 0; }
.room-card-del:hover { background: #dc2626; }
.room-status { font-size: 10px; color: var(--muted); margin-top: 4px; line-height: 1.4; }
.add-room { border: 2px dashed var(--border); border-radius: 10px; display: flex; align-items: center; justify-content: center; min-height: 160px; cursor: pointer; color: var(--purple); font-size: 13px; font-weight: 600; background: #fff; transition: border-color .15s, background .15s; }
.add-room:hover { border-color: var(--purple); background: var(--purple-lt); }
.paste-room { border: 2px dashed var(--border); border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; min-height: 160px; cursor: text; color: var(--muted); font-size: 12px; font-weight: 600; background: #fff; text-align: center; padding: 8px; transition: border-color .15s, background .15s; outline: none; }
.paste-room .paste-icon { font-size: 22px; line-height: 1; }
.paste-room:hover, .paste-room:focus { border-color: var(--purple); background: var(--purple-lt); color: var(--purple); }
.paste-room.drag-over { border-color: var(--purple); background: var(--purple-lt); color: var(--purple); border-style: solid; }

.style-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.style-row label { font-size: 12px; font-weight: 700; color: var(--dark-blue); }
.style-row select { padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 7px; font-size: 13px; color: var(--text); }
.field-label { font-size: 13px; font-weight: 700; color: var(--dark-blue); margin-bottom: 4px; display: block; }
.vv-input { width: 100%; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; font-family: inherit; color: var(--text); resize: vertical; }
.vv-input:focus { outline: none; border-color: var(--purple); }

/* ── Step 2 — sequence list ──────────────────────────────────────────────── */
.seq-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.seq-row { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1.5px solid var(--border); border-radius: 10px; padding: 8px 10px; cursor: grab; transition: border-color .15s; }
.seq-row:hover { border-color: var(--purple); }
.seq-row.dragging { opacity: .4; }
.seq-row.excluded { opacity: .45; }
.seq-handle { font-size: 14px; color: #c9c9d6; cursor: grab; }
.seq-thumb { width: 56px; height: 42px; object-fit: cover; border-radius: 6px; background: #e5e7eb; flex-shrink: 0; }
.seq-info { flex: 1; min-width: 0; }
.seq-label { font-size: 13px; font-weight: 700; color: var(--dark-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.seq-sub { font-size: 11px; color: var(--muted); }
.seq-num { width: 22px; height: 22px; border-radius: 50%; background: var(--purple-lt); color: #5b21b6; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.seq-toggle { flex-shrink: 0; }
.seq-toggle input { width: 16px; height: 16px; accent-color: var(--purple); cursor: pointer; }
.seq-summary { font-size: 12px; color: var(--muted); background: #f7f9fc; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 14px; }
.seq-summary b { color: var(--dark-blue); }
.continue-btn { padding: 11px 28px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s; }
.continue-btn:hover:not(:disabled) { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.continue-btn:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; }

/* ── Step 5 — Build Your Video (static vs AI motion cards) ──────────────── */
.mode-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 14px; }
.mode-card { border: 1.5px solid var(--border); border-radius: 12px; padding: 18px 16px; text-align: center; background: #f8fafc; transition: border-color .15s, box-shadow .15s; }
.mode-card:hover { border-color: var(--purple); box-shadow: 0 4px 14px rgba(139,92,246,.12); }
.mode-card-icon { font-size: 28px; margin-bottom: 6px; }
.mode-card-title { font-size: 14px; font-weight: 700; color: var(--dark-blue); margin-bottom: 6px; }
.mode-card-desc { font-size: 12px; color: var(--muted); line-height: 1.5; margin-bottom: 10px; min-height: 36px; }
.mode-card-cost { font-size: 13px; font-weight: 700; color: var(--purple); margin-bottom: 12px; }
.mode-card button { width: 100%; }

.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--dark-blue); color: #fff; padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600; z-index: 999; transition: opacity .3s; pointer-events: none; }

/* ── Lightbox (click any room/staged/lit photo to enlarge) ──────────────── */
.room-card img, .seq-thumb, #moodGrid img { cursor: zoom-in; }
.lightbox-overlay { display: none; position: fixed; inset: 0; background: rgba(15,42,68,0.85); z-index: 2000; align-items: center; justify-content: center; padding: 30px; cursor: zoom-out; }
.lightbox-overlay.open { display: flex; }
.lightbox-overlay img { max-width: 92vw; max-height: 88vh; border-radius: 10px; box-shadow: 0 12px 50px rgba(0,0,0,.5); }
.lightbox-close { position: absolute; top: 18px; right: 24px; color: #fff; font-size: 30px; font-weight: 300; cursor: pointer; line-height: 1; }
.lightbox-close:hover { color: var(--accent); }

.site-footer { background: linear-gradient(90deg, #0f2a44, #143b63); color: rgba(255,255,255,.5); padding: 14px 20px; font-size: 12px; display: flex; justify-content: center; align-items: center; gap: 24px; flex-wrap: wrap; }
.site-footer a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.site-footer a:hover { color: var(--accent); }
.footer-brand { font-weight: 700; color: var(--accent); }
</style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<header class="app-header">
  <a class="brand" href="index.php">
    <span style="font-size:24px;">🏠</span>
    <span class="brand-name"><span class="v">Video</span><span class="z">Vizard</span></span>
  </a>
  <a class="header-back" href="vizard_browser.php">← Home</a>
</header>

<div class="page-wrap">
  <div class="wiz-card">
    <div class="wiz-header">
      <div>
        <h1>Listing Video Wizard</h1>
        <p>Stage every room, pick the sequence, get a finished walkthrough video</p>
      </div>
      <button class="reset-btn" id="resetDraftBtn" onclick="resetDraft()" title="Clear this draft and start a new listing">↺ Start Over</button>
    </div>
    <div class="wiz-body">

      <!-- ── Step 1 — Upload Room Photos & Stage ─────────────────────────── -->
      <div class="step-section">
        <div class="settings-bar" onclick="toggleStep('step1')">
          <span class="settings-bar-label">Step 1 — Upload Rooms &amp; Stage</span>
          <span id="step1Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step1Chevron">▾</span>
        </div>
        <div id="step1Body" class="step-body">
          <div class="garment-subheading">Upload each room — front exterior, backyard, family room, bedroom, kitchen, basement, washroom, etc. — then stage it with one click.</div>

          <div class="style-row">
            <label>Staging Style</label>
            <select id="globalStyle">
              <option value="modern">Modern</option>
              <option value="luxury">Luxury</option>
              <option value="farmhouse">Farmhouse</option>
              <option value="coastal">Coastal</option>
              <option value="minimalist">Minimalist</option>
            </select>
          </div>

          <div class="room-grid" id="roomGrid">
            <div class="add-room" id="addRoomBtn">+ Add Room Photo</div>
            <div class="paste-room" id="pasteRoomBox" tabindex="0">
              <span class="paste-icon">📋</span>
              <span>Click here, then paste an image<br>(Ctrl+V / Cmd+V)<br>or drag &amp; drop</span>
            </div>
          </div>
          <input type="file" id="fileInput" accept="image/*" style="display:none" multiple>
          <button class="continue-btn" id="step1ContinueBtn" disabled style="margin-top:14px;" onclick="continueToStep2()">Continue to Select Video Sequence →</button>
        </div>
      </div>

      <!-- ── Step 2 — Select Video Sequence ──────────────────────────────── -->
      <div class="step-section">
        <div class="settings-bar" onclick="toggleStep('step2')">
          <span class="settings-bar-label">Step 2 — Select Video Sequence</span>
          <span id="step2Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step2Chevron">▾</span>
        </div>
        <div id="step2Body" class="step-body" style="display:none;">
          <div class="garment-subheading">Drag to reorder, uncheck to leave a room out of the final video. Rooms are pre-sorted exterior → living spaces → bedrooms → basement/bathrooms — drag to fine-tune.</div>

          <div class="seq-summary" id="seqSummary">No staged rooms yet — finish Step 1 first.</div>
          <div class="seq-list" id="seqList"></div>

          <button class="continue-btn" id="seqContinueBtn" disabled onclick="continueToStep3()">Continue to Lighting Mood →</button>
        </div>
      </div>

      <!-- ── Step 3 — Lighting Mood (optional) ───────────────────────────── -->
      <div class="step-section">
        <div class="settings-bar" onclick="toggleStep('step3')">
          <span class="settings-bar-label">Step 3 — Lighting Mood <span style="font-size:11px;font-weight:400;color:var(--muted);">(optional)</span></span>
          <span id="step3Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step3Chevron">▾</span>
        </div>
        <div id="step3Body" class="step-body" style="display:none;">
          <div class="garment-subheading">Optionally shift the time-of-day feel of each staged room — bright daylight, golden hour, dusk with the lights on, or soft overcast. Skip this to keep each room's original lighting.</div>

          <div class="style-row">
            <label>Apply to all sequenced rooms</label>
            <select id="globalMood">
              <option value="none">No Change (skip)</option>
              <option value="day_bright">Bright Daylight</option>
              <option value="golden_hour">Golden Hour</option>
              <option value="dusk_twilight">Dusk / Twilight</option>
              <option value="overcast_soft">Soft Overcast</option>
            </select>
            <button class="continue-btn" id="applyAllMoodBtn" onclick="applyMoodToAll()" style="padding:8px 16px;font-size:12px;">Apply to All</button>
          </div>

          <div class="room-grid" id="moodGrid"></div>

          <button class="continue-btn" id="moodContinueBtn" onclick="continueToStep4()" style="margin-top:14px;">Continue to Video Setting →</button>
        </div>
      </div>

      <!-- ── Step 4 — Video Setting ──────────────────────────────────────── -->
      <div class="step-section">
        <div class="settings-bar" onclick="toggleStep('step4')">
          <span class="settings-bar-label">Step 4 — Video Setting</span>
          <span id="step4Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step4Chevron">▾</span>
        </div>
        <div id="step4Body" class="step-body" style="display:none;">

          <label class="field-label">Listing Title</label>
          <input type="text" id="listingTitle" class="vv-input" placeholder="e.g. 123 Maple Street — Charming 4-Bed Family Home" style="margin-bottom:14px;">

          <label class="field-label">Property Highlights <span style="font-weight:400;color:var(--muted);">(optional — feeds the AI captions)</span></label>
          <textarea id="listingDescription" class="vv-input" rows="3" placeholder="e.g. Fully renovated kitchen, finished basement, walk to schools, quiet cul-de-sac..." style="margin-bottom:14px;"></textarea>

          <div class="style-row">
            <label>Target Length</label>
            <select id="durationTarget">
              <option value="30">~30 seconds</option>
              <option value="45">~45 seconds</option>
              <option value="60">~60 seconds</option>
            </select>
            <label style="margin-left:14px;">Caption Style</label>
            <select id="captionStyle">
              <option value="Elegant">Elegant</option>
              <option value="Emotional">Emotional</option>
              <option value="Premium">Premium</option>
              <option value="Sales-Oriented">Sales-Oriented</option>
            </select>
          </div>

          <div style="margin-top:6px;padding-top:14px;border-top:1px solid var(--border);">
            <div class="garment-heading">🎵 Background Music <span style="font-size:11px;font-weight:400;color:var(--muted);">(optional)</span></div>
            <div id="musicCurrentWrap" style="margin-bottom:10px;font-size:12px;color:var(--muted);">No background music selected</div>

            <div class="style-row">
              <input type="file" id="musicFileInput" accept="audio/*" style="font-size:12px;">
              <select id="musicLibSelect" style="flex:1;">
                <option value="">— Choose from library —</option>
              </select>
            </div>

            <div class="style-row" style="margin-top:10px;">
              <label style="width:90px;">Music Vol</label>
              <input type="range" id="musicVolSlider" min="0" max="100" value="30" style="flex:1;">
              <span id="musicVolLbl" style="font-size:11px;color:var(--muted);width:36px;">30%</span>
            </div>
            <div class="style-row">
              <label style="width:90px;">Voice Vol</label>
              <input type="range" id="voiceVolSlider" min="0" max="100" value="100" style="flex:1;">
              <span id="voiceVolLbl" style="font-size:11px;color:var(--muted);width:36px;">100%</span>
            </div>
          </div>

          <div id="step4Status" class="room-status" style="margin-top:8px;"></div>
          <button class="continue-btn" id="toStep5Btn" style="margin-top:14px;" onclick="continueToStep5()">Continue to Build Video →</button>
        </div>
      </div>

      <!-- ── Step 5 — Build Your Video (choose static vs. AI motion) ─────── -->
      <div class="step-section">
        <div class="settings-bar" onclick="toggleStep('step5')">
          <span class="settings-bar-label">Step 5 — Build Your Video</span>
          <span id="step5Summary" class="settings-bar-summary"></span>
          <span class="settings-bar-edit" id="step5Chevron">▾</span>
        </div>
        <div id="step5Body" class="step-body" style="display:none;">
          <div class="garment-subheading">Choose how this listing's final video gets built — both open the video editor when ready.</div>

          <div class="mode-card-grid">
            <div class="mode-card">
              <div class="mode-card-icon">🖼</div>
              <div class="mode-card-title">Static Slideshow</div>
              <div class="mode-card-desc">Ken Burns pan/zoom on each staged photo. Ready right away.</div>
              <div class="mode-card-cost">50 credits</div>
              <button class="continue-btn" onclick="buildVideo('static')">Generate Static Video</button>
            </div>
            <div class="mode-card">
              <div class="mode-card-icon">🎬</div>
              <div class="mode-card-title">AI Motion Video</div>
              <div class="mode-card-desc">Real AI-generated camera movement through every room.</div>
              <div class="mode-card-cost" id="aiCostLabel">20 credits / room</div>
              <button class="continue-btn" onclick="buildVideo('ai_video')">Generate AI Video</button>
            </div>
          </div>

          <div id="step5Status" class="room-status" style="margin-top:12px;"></div>
        </div>
      </div>

    </div>

  </div>
</div>

<!-- ── Lightbox overlay ─────────────────────────────────────────────────── -->
<div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
  <span class="lightbox-close" onclick="closeLightbox()">×</span>
  <img id="lightboxImg" src="" onclick="event.stopPropagation()">
</div>

<footer class="site-footer">
  <span class="footer-brand">🏠 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<script>
const ROOM_TYPES = <?php echo json_encode(vv_room_types()); ?>;
let DRAFT_ID = localStorage.getItem('staging_draft_id') || '';

function post(payload, isForm) {
  const fd = isForm ? payload : new URLSearchParams(payload);
  return fetch(location.href, { method:'POST', body:fd }).then(r=>r.json());
}
function showToast(msg) {
  const t = document.createElement('div');
  t.className = 'toast'; t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(()=>t.remove(), 300); }, 2200);
}

// ── Lightbox — click any room/staged/lit photo anywhere on the page to
// enlarge it. Delegated on document.body (not bound per-card) so it keeps
// working on cards rendered later by renderRoomCard/renderSeqList/
// renderMoodGrid without needing separate wiring in each of those. ────────
function openLightbox(src) {
  if (!src) return;
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxOverlay').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightboxOverlay').classList.remove('open');
  document.getElementById('lightboxImg').src = '';
}
document.body.addEventListener('click', (e) => {
  if (e.target.tagName !== 'IMG') return;
  if (e.target.closest('.room-card, .seq-row, #moodGrid')) openLightbox(e.target.src);
});

// ── Accordion (same toggleStep pattern as vizard_scriptgen_3.php) ──────────
function toggleStep(prefix) {
  const body = document.getElementById(prefix + 'Body');
  if (!body) return;
  body.style.display = (body.style.display === 'none') ? 'block' : 'none';
  const chev = document.getElementById(prefix + 'Chevron');
  if (chev) chev.textContent = (body.style.display === 'none') ? '▾' : '▴';
}

// ── Start Over — this is why the same images/title/music kept reappearing
// on reload: DRAFT_ID persists in localStorage on purpose (so a refresh
// resumes your in-progress draft instead of losing it). This button is the
// explicit opt-out: deletes the uploaded + staged photos and the manifest
// for the current draft server-side (room_clear_session, already existed
// but wasn't wired to anything), clears the saved draft id, then reloads —
// a full reload is the simplest way to guarantee every field/grid/accordion
// goes back to blank rather than manually resetting each piece of state. ──
async function resetDraft() {
  if (!confirm('Start a new listing? This permanently deletes the uploaded and staged photos for the current draft.')) return;
  const btn = document.getElementById('resetDraftBtn');
  btn.disabled = true;
  btn.textContent = 'Clearing…';
  if (DRAFT_ID) {
    await post({ ajax_action: 'room_clear_session', draft_id: DRAFT_ID });
  }
  localStorage.removeItem('staging_draft_id');
  location.reload();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — Upload + Stage
// ═════════════════════════════════════════════════════════════════════════════
document.getElementById('addRoomBtn').onclick = () => document.getElementById('fileInput').click();
document.getElementById('fileInput').onchange = async (e) => {
  for (const file of e.target.files) await uploadRoom(file);
  e.target.value = '';
};

// ── Paste-here box: paste (Ctrl+V) or drag & drop an image directly ────────
(function setupPasteBox() {
  const box = document.getElementById('pasteRoomBox');

  box.addEventListener('paste', async (e) => {
    const items = [...(e.clipboardData?.items || [])];
    const imageItems = items.filter(i => i.type.startsWith('image/'));
    if (!imageItems.length) {
      showToast('No image found on clipboard');
      return;
    }
    e.preventDefault();
    for (const item of imageItems) {
      const file = item.getAsFile();
      if (file) await uploadRoom(file);
    }
  });

  // Drag & drop also lands here (in addition to anywhere on the grid)
  ['dragenter', 'dragover'].forEach(evt =>
    box.addEventListener(evt, (e) => { e.preventDefault(); box.classList.add('drag-over'); })
  );
  ['dragleave', 'drop'].forEach(evt =>
    box.addEventListener(evt, (e) => { e.preventDefault(); box.classList.remove('drag-over'); })
  );
  box.addEventListener('drop', async (e) => {
    const files = [...(e.dataTransfer?.files || [])].filter(f => f.type.startsWith('image/'));
    for (const file of files) await uploadRoom(file);
  });
})();

async function uploadRoom(file) {
  const fd = new FormData();
  fd.append('ajax_action', 'room_upload_image');
  fd.append('draft_id', DRAFT_ID);
  fd.append('room_image', file);
  const d = await post(fd, true);
  if (!d.success) { showToast(d.message || 'Upload failed'); return; }
  DRAFT_ID = d.draft_id;
  localStorage.setItem('staging_draft_id', DRAFT_ID);
  renderRoomCard(d.room_key, d.room_type, d.orig_url, null);
  updateStep1Summary();
}

function renderRoomCard(room_key, room_type, orig_url, staged_url) {
  const grid = document.getElementById('roomGrid');
  const card = document.createElement('div');
  card.className = 'room-card';
  card.id = 'room_' + room_key;
  const opts = Object.entries(ROOM_TYPES).map(([k,v]) =>
    `<option value="${k}" ${k===room_type?'selected':''}>${v}</option>`).join('');
  card.innerHTML = `
    <button class="room-card-del" title="Remove">×</button>
    <img src="${staged_url || orig_url}" data-orig="${orig_url}" data-staged="${staged_url||''}">
    <select class="roomTypeSel">${opts}</select>
    <button class="primary genBtn">${staged_url ? 'Regenerate Staging' : 'Generate Staging'}</button>
    <div class="room-status"></div>
  `;
  card.querySelector('.genBtn').onclick = () => generateStaging(room_key, card);
  card.querySelector('.room-card-del').onclick = () => deleteRoom(room_key, card);
  grid.insertBefore(card, document.getElementById('addRoomBtn'));
}

async function generateStaging(room_key, card) {
  const btn = card.querySelector('.genBtn');
  const status = card.querySelector('.room-status');
  const room_type = card.querySelector('.roomTypeSel').value;
  const style = document.getElementById('globalStyle').value;
  btn.disabled = true; status.textContent = 'Staging… (~30-60s)';
  const d = await post({
    ajax_action: 'generate_staged_room',
    draft_id: DRAFT_ID,
    room_key: room_key,
    room_type: room_type,
    style: style,
  });
  btn.disabled = false;
  if (!d.success) { status.textContent = '✗ ' + (d.message || 'Failed'); return; }
  const img = card.querySelector('img');
  img.src = d.public_url;
  img.dataset.staged = d.public_url;
  status.textContent = `✓ Staged (${d.style}) — ${d.credits_charged}cr used, ${d.credits_balance} left`;
  btn.textContent = 'Regenerate Staging';
  updateStep1Summary();
  loadSequence(); // keep Step 2's list in sync as rooms get staged
}

async function deleteRoom(room_key, card) {
  if (!confirm('Remove this room?')) return;
  await post({ ajax_action: 'room_delete_image', draft_id: DRAFT_ID, room_key: room_key });
  card.remove();
  updateStep1Summary();
  loadSequence();
}

function updateStep1Summary() {
  const count = document.querySelectorAll('#roomGrid .room-card').length;
  document.getElementById('step1Summary').textContent = count ? `${count} room(s) added` : '';

  // Require at least one STAGED room (not just uploaded) before letting the
  // user move on — Step 2's sequence list only shows staged rooms anyway.
  const stagedCount = [...document.querySelectorAll('#roomGrid .room-card img')]
    .filter(img => img.dataset.staged).length;
  document.getElementById('step1ContinueBtn').disabled = stagedCount === 0;
}

function continueToStep2() {
  toggleStep('step1'); // collapse Step 1
  toggleStep('step2'); // expand Step 2
  loadSequence();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — Select Video Sequence
// ═════════════════════════════════════════════════════════════════════════════
let _seqRooms = []; // current working list, in display order

async function loadSequence() {
  if (!DRAFT_ID) return;
  const d = await post({ ajax_action: 'room_list_images', draft_id: DRAFT_ID });
  if (!d.success) return;
  // Only staged rooms can be sequenced — un-staged ones aren't ready yet.
  _seqRooms = d.rooms.filter(r => r.staged_url);
  renderSeqList();
}

function renderSeqList() {
  const list = document.getElementById('seqList');
  const summary = document.getElementById('seqSummary');
  const continueBtn = document.getElementById('seqContinueBtn');

  if (!_seqRooms.length) {
    list.innerHTML = '';
    summary.textContent = 'No staged rooms yet — finish Step 1 first.';
    continueBtn.disabled = true;
    return;
  }

  list.innerHTML = _seqRooms.map((r, i) => `
    <div class="seq-row ${r.included ? '' : 'excluded'}" draggable="true" data-key="${r.room_key}" data-idx="${i}">
      <span class="seq-handle">⠿</span>
      <span class="seq-num">${i+1}</span>
      <img class="seq-thumb" src="${r.staged_url}">
      <div class="seq-info">
        <div class="seq-label">${r.room_label}</div>
        <div class="seq-sub">${r.style || ''}</div>
      </div>
      <label class="seq-toggle">
        <input type="checkbox" ${r.included ? 'checked' : ''} data-key="${r.room_key}">
      </label>
    </div>`).join('');

  list.querySelectorAll('.seq-toggle input').forEach(cb => {
    cb.onchange = () => {
      const r = _seqRooms.find(x => x.room_key === cb.dataset.key);
      r.included = cb.checked;
      renderSeqList();
      saveSequence();
    };
  });

  attachDragHandlers(list);

  const includedCount = _seqRooms.filter(r => r.included).length;
  const estSeconds = includedCount * 6; // each scene clip is ~6s minimum, same as the dress-tryon video model
  summary.innerHTML = `<b>${includedCount}</b> room(s) selected — estimated video length <b>~${estSeconds}s</b>` +
    (estSeconds < 30 ? ' (add more rooms to reach 30-40s)' : estSeconds > 45 ? ' (consider trimming to keep it under ~40s)' : ' — good length');
  continueBtn.disabled = includedCount === 0;
}

function attachDragHandlers(list) {
  let dragEl = null;
  list.querySelectorAll('.seq-row').forEach(row => {
    row.addEventListener('dragstart', () => { dragEl = row; row.classList.add('dragging'); });
    row.addEventListener('dragend', () => { row.classList.remove('dragging'); dragEl = null; reorderFromDOM(list); });
    row.addEventListener('dragover', (e) => {
      e.preventDefault();
      const over = e.target.closest('.seq-row');
      if (!over || over === dragEl) return;
      const rect = over.getBoundingClientRect();
      const before = (e.clientY - rect.top) < rect.height / 2;
      list.insertBefore(dragEl, before ? over : over.nextSibling);
    });
  });
}

function reorderFromDOM(list) {
  const keysInOrder = [...list.querySelectorAll('.seq-row')].map(r => r.dataset.key);
  _seqRooms.sort((a, b) => keysInOrder.indexOf(a.room_key) - keysInOrder.indexOf(b.room_key));
  renderSeqList();
  saveSequence();
}

async function saveSequence() {
  const sequence = _seqRooms.map(r => ({ room_key: r.room_key, included: r.included }));
  const d = await post({ ajax_action: 'save_room_sequence', draft_id: DRAFT_ID, sequence: JSON.stringify(sequence) });
  if (d.success) document.getElementById('step2Summary').textContent = `${d.included_count} room(s) in sequence`;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 — Lighting Mood (optional)
// ═════════════════════════════════════════════════════════════════════════════
const MOOD_LABELS = {none:'No Change', day_bright:'Bright Daylight', golden_hour:'Golden Hour', dusk_twilight:'Dusk / Twilight', overcast_soft:'Soft Overcast'};

function continueToStep3() {
  toggleStep('step2'); // collapse Step 2
  toggleStep('step3'); // expand Step 3
  renderMoodGrid();
}

function renderMoodGrid() {
  const grid = document.getElementById('moodGrid');
  // Only rooms actually included in the sequence get a lighting option —
  // matches what Step 4 will eventually build scenes from.
  const included = _seqRooms.filter(r => r.included);
  if (!included.length) {
    grid.innerHTML = '<div style="font-size:12px;color:var(--muted);">No rooms in the sequence yet — finish Step 2 first.</div>';
    return;
  }
  grid.innerHTML = included.map(r => `
    <div class="room-card" id="mood_${r.room_key}">
      <img src="${r.lighting_url || r.staged_url}">
      <select class="moodSel">
        ${Object.entries(MOOD_LABELS).map(([k,v]) => `<option value="${k}" ${k===(r.lighting_mood||'none')?'selected':''}>${v}</option>`).join('')}
      </select>
      <button class="primary applyMoodBtn">Apply</button>
      <div class="room-status"></div>
    </div>`).join('');

  grid.querySelectorAll('.applyMoodBtn').forEach((btn, i) => {
    const room_key = included[i].room_key;
    btn.onclick = () => applyMood(room_key, document.getElementById('mood_'+room_key));
  });

  document.getElementById('step3Summary').textContent = `${included.length} room(s) ready`;
}

async function applyMood(room_key, card) {
  const btn = card.querySelector('.applyMoodBtn');
  const status = card.querySelector('.room-status');
  const mood = card.querySelector('.moodSel').value;
  btn.disabled = true;
  status.textContent = mood === 'none' ? 'Reverting…' : 'Relighting… (~30-60s)';
  const d = await post({ ajax_action: 'apply_lighting_mood', draft_id: DRAFT_ID, room_key, mood });
  btn.disabled = false;
  if (!d.success) { status.textContent = '✗ ' + (d.message || 'Failed'); return; }
  if (mood === 'none') {
    const r = _seqRooms.find(x => x.room_key === room_key);
    card.querySelector('img').src = r ? r.staged_url : card.querySelector('img').src;
    status.textContent = '✓ Reverted to original staging';
  } else {
    card.querySelector('img').src = d.public_url;
    status.textContent = `✓ ${d.mood_label} — ${d.credits_charged}cr used, ${d.credits_balance} left`;
  }
  const r = _seqRooms.find(x => x.room_key === room_key);
  if (r) { r.lighting_mood = mood; r.lighting_url = mood === 'none' ? null : d.public_url; }
}

async function applyMoodToAll() {
  const mood = document.getElementById('globalMood').value;
  const cards = [...document.querySelectorAll('#moodGrid .room-card')];
  const allBtn = document.getElementById('applyAllMoodBtn');
  allBtn.disabled = true;
  for (const card of cards) {
    const room_key = card.id.replace('mood_', '');
    card.querySelector('.moodSel').value = mood;
    await applyMood(room_key, card); // one at a time, same pattern as Step 1's per-room generation
  }
  allBtn.disabled = false;
  showToast('Lighting mood applied to all sequenced rooms');
}

function continueToStep4() {
  toggleStep('step3'); // collapse Step 3
  toggleStep('step4'); // expand Step 4
  loadMusicLibrary();
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — Video Setting (title/description/duration/captions + music)
// ═════════════════════════════════════════════════════════════════════════════
let _currentPodcastId = null;
let pendingMusicFile = null;
let currentPodcastMusic = '';

async function loadMusicLibrary() {
  const d = await post({ ajax_action: 'get_music_library' });
  const sel = document.getElementById('musicLibSelect');
  sel.innerHTML = '<option value="">— Choose from library —</option>' +
    (d.success ? d.files.map(f => `<option value="${f.filename}">${f.filename}</option>`).join('') : '');
}

document.getElementById('musicFileInput').onchange = (e) => {
  const file = e.target.files[0];
  if (!file) return;
  pendingMusicFile = file;
  currentPodcastMusic = '';
  document.getElementById('musicLibSelect').value = '';
  document.getElementById('musicCurrentWrap').textContent = '🎵 ' + file.name + ' (will upload when you build scenes)';
};
document.getElementById('musicLibSelect').onchange = (e) => {
  if (!e.target.value) return;
  pendingMusicFile = null;
  currentPodcastMusic = e.target.value;
  document.getElementById('musicCurrentWrap').textContent = '🎵 ' + currentPodcastMusic + ' (from library)';
};
document.getElementById('musicVolSlider').oninput = (e) => {
  document.getElementById('musicVolLbl').textContent = e.target.value + '%';
};
document.getElementById('voiceVolSlider').oninput = (e) => {
  document.getElementById('voiceVolLbl').textContent = e.target.value + '%';
};

async function continueToStep5() {
  // Build buttons need a podcast/scene count context — make sure the
  // sequence is loaded so we can show an accurate AI-video cost before the
  // user commits to either card.
  toggleStep('step4');
  toggleStep('step5');
  const includedCount = _seqRooms.filter(r => r.included).length || 1;
  document.getElementById('aiCostLabel').textContent = (includedCount * 20) + ' credits (' + includedCount + ' room' + (includedCount === 1 ? '' : 's') + ' × 20cr)';
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5 — Build Your Video (static slideshow vs. AI motion video)
// ═════════════════════════════════════════════════════════════════════════════
// Either card calls the SAME create_podcast_from_rooms handler used to lay
// down scenes, just with a different mode — the backend resolves cost,
// credit balance (incl. free-trial credit_balance2), and videogen_flag from
// that one parameter. AI-mode rows are written with videogen_flag=1 and
// left for the separate webhook pipeline to actually fire — this page only
// writes the rows and hands off to the video editor.
// ═════════════════════════════════════════════════════════════════════════════
async function buildVideo(mode) {
  const title = document.getElementById('listingTitle').value.trim();
  if (!title) { showToast('Give the listing a title first'); return; }
  if (!DRAFT_ID) { showToast('Finish Step 1 first'); return; }

  const status = document.getElementById('step5Status');
  document.querySelectorAll('.mode-card button').forEach(b => b.disabled = true);
  status.textContent = mode === 'ai_video' ? 'Generating AI video request…' : 'Building static video…';

  const d = await post({
    ajax_action: 'create_podcast_from_rooms',
    draft_id: DRAFT_ID,
    title: title,
    description: document.getElementById('listingDescription').value.trim(),
    caption_style: document.getElementById('captionStyle').value,
    duration_target: document.getElementById('durationTarget').value,
    mode: mode,
  });

  if (!d.success) {
    document.querySelectorAll('.mode-card button').forEach(b => b.disabled = false);
    status.textContent = '✗ ' + (d.message || 'Failed to build video');
    return;
  }
  _currentPodcastId = d.podcast_id;

  // ── Music + volumes now that the podcast row exists ───────────────
  if (pendingMusicFile) {
    const fd = new FormData();
    fd.append('ajax_action', 'upload_podcast_music');
    fd.append('podcast_id', _currentPodcastId);
    fd.append('music_file', pendingMusicFile);
    await post(fd, true);
  } else if (currentPodcastMusic) {
    await post({ ajax_action: 'update_podcast_music', podcast_id: _currentPodcastId, music_file: currentPodcastMusic });
  }
  await post({
    ajax_action: 'save_podcast_volumes',
    podcast_id: _currentPodcastId,
    music_volume: document.getElementById('musicVolSlider').value,
    voice_volume: document.getElementById('voiceVolSlider').value,
  });

  document.getElementById('step5Summary').textContent = (d.video_mode === 'ai_video' ? 'AI Motion' : 'Static') + ' — ' + d.credits_charged + 'cr used';
  status.textContent = '✓ Queued podcast #' + d.podcast_id + ' — ' + d.credits_charged + 'cr used, ' + d.credits_balance + ' left — opening editor…';
  showToast('Video queued — opening editor→');
  window.location.href = d.redirect_url;
}



(async function init() {
  if (!DRAFT_ID) return;
  const d = await post({ ajax_action: 'room_list_images', draft_id: DRAFT_ID });
  if (d.success) {
    d.rooms.forEach(r => renderRoomCard(r.room_key, r.room_type, r.orig_url, r.staged_url));
    updateStep1Summary();
  }
  loadSequence();
})();
</script>
</body>
</html>

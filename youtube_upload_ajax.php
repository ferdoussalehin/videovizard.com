<?php
// youtube_upload_ajax.php
// Called by youtube_upload.php — does the actual resumable upload to YouTube
// No session needed — reads admin_id from hdb_podcasts

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '256M');

// Streaming output — send lines of JSON as we go
header('Content-Type: text/plain');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

require_once 'youtube_config.php';
include 'dbconnect_hdb.php';

function out($data) {
    echo json_encode($data) . "\n";
    flush();
}

function outLog($msg, $cls = 'inf') {
    out(['log' => $msg, 'cls' => $cls]);
}

function outProgress($pct, $label = '') {
    out(['progress' => $pct, 'label' => $label]);
}

function outDone($success, $data = []) {
    out(array_merge(['done' => true, 'success' => $success], $data));
}

// ── Validate request ──────────────────────────────────────────────
$podcast_id = (int)($_POST['podcast_id'] ?? 0);
if (!$podcast_id) {
    outDone(false, ['error' => 'Missing podcast_id']);
    exit;
}

// ── Load podcast ──────────────────────────────────────────────────
$podcast = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, admin_id, company_id, title, caption_text, hashtags, keywords, published_video
     FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));

if (!$podcast) {
    outDone(false, ['error' => "Podcast #$podcast_id not found"]);
    exit;
}

$admin_id        = (int)$podcast['admin_id'];
// Token is scoped to the podcast's owning company (no session here)
$company_id      = (int)($podcast['company_id'] ?? 0);
$title           = $podcast['title']          ?: 'VideoVizard Video #' . $podcast_id;
$description     = $podcast['caption_text']   ?: '';
$hashtags        = $podcast['hashtags']        ?: '';
$keywords        = $podcast['keywords']        ?: '';
$published_video = trim($podcast['published_video'] ?: '');

if ($hashtags)  $description .= "\n\n" . $hashtags;
if ($keywords)  $description .= "\n\nKeywords: " . $keywords;

// ── Find video file ───────────────────────────────────────────────
$video_path = __DIR__ . '/published_videos/' . $published_video;
if (!$published_video || !file_exists($video_path)) {
    outDone(false, ['error' => "Video file not found: published_videos/$published_video"]);
    exit;
}

$file_size = filesize($video_path);
$mime_type = preg_match('/\.mp4$/i', $published_video) ? 'video/mp4' : 'video/webm';

outLog("📹 Video: $published_video (" . round($file_size/1048576, 1) . " MB)");
outLog("📺 Title: $title");
outProgress(5, 'Loaded video file…');

// ── Load and refresh OAuth token (scoped to the podcast's company) ──
$token_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, access_token, refresh_token, token_expiry
     FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND company_id=$company_id AND platform='youtube' LIMIT 1"));

if (!$token_row) {
    outDone(false, ['error' => 'YouTube not connected. Go to youtube_connect.php first.']);
    exit;
}
$yt_tok_id = (int)$token_row['id'];

$access_token = $token_row['access_token'];
$expiry       = strtotime($token_row['token_expiry'] ?? '');

if ($expiry && $expiry < time() + 60) {
    outLog('🔄 Refreshing access token…');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => YT_CLIENT_ID,
            'client_secret' => YT_CLIENT_SECRET,
            'refresh_token' => $token_row['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);

    if (empty($data['access_token'])) {
        outDone(false, ['error' => 'Token refresh failed. Reconnect YouTube.']);
        exit;
    }

    $access_token   = $data['access_token'];
    $new_expiry     = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
    $now            = date('Y-m-d H:i:s');
    $esc_tok        = mysqli_real_escape_string($conn, $access_token);
    $esc_exp        = mysqli_real_escape_string($conn, $new_expiry);
    mysqli_query($conn,
        "UPDATE hdb_oauth_tokens SET access_token='$esc_tok', token_expiry='$esc_exp', updated_at='$now'
         WHERE id=$yt_tok_id");
    outLog('✅ Token refreshed');
}

outProgress(10, 'Got access token…');

// ── Step 1: Start resumable upload session ────────────────────────
outLog('🚀 Starting resumable upload session…');

$metadata = json_encode([
    'snippet' => [
        'title'       => $title,
        'description' => $description,
        'tags'        => array_filter(array_map('trim', explode(',', $keywords))),
        'categoryId'  => '22', // People & Blogs
    ],
    'status' => [
        'privacyStatus'          => 'private',
        'selfDeclaredMadeForKids'=> false,
    ],
]);

$ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $metadata,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'X-Upload-Content-Type: ' . $mime_type,
        'X-Upload-Content-Length: ' . $file_size,
    ],
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response    = curl_exec($ch);
$http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$curl_err    = curl_error($ch);
curl_close($ch);

error_log("YT Upload session: HTTP=$http_code curl_err=$curl_err");

if ($http_code !== 200) {
    $body = substr($response, $header_size);
    error_log("YT Upload session body: $body");
    outDone(false, ['error' => "Failed to start upload session (HTTP $http_code): $body"]);
    exit;
}

// Extract upload URL from Location header
$headers_raw = substr($response, 0, $header_size);
$upload_url  = '';
foreach (explode("\r\n", $headers_raw) as $header) {
    if (stripos($header, 'Location:') === 0) {
        $upload_url = trim(substr($header, 9));
        break;
    }
}

if (!$upload_url) {
    outDone(false, ['error' => 'No upload URL in response headers']);
    exit;
}

outLog('✅ Upload session started');
outProgress(15, 'Upload session ready…');

// ── Step 2: Upload file in chunks ─────────────────────────────────
$chunk_size  = 5 * 1024 * 1024; // 5MB chunks
$uploaded    = 0;
$video_id    = null;
$fh          = fopen($video_path, 'rb');

if (!$fh) {
    outDone(false, ['error' => 'Cannot open video file for reading']);
    exit;
}

outLog("📤 Uploading " . round($file_size/1048576, 1) . " MB in " . ceil($file_size/$chunk_size) . " chunks…");

while (!feof($fh)) {
    $chunk      = fread($fh, $chunk_size);
    $chunk_len  = strlen($chunk);
    $range_end  = $uploaded + $chunk_len - 1;

    $ch = curl_init($upload_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $chunk,
        CURLOPT_HTTPHEADER     => [
            'Content-Length: ' . $chunk_len,
            'Content-Range: bytes ' . $uploaded . '-' . $range_end . '/' . $file_size,
            'Content-Type: ' . $mime_type,
        ],
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $result    = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    $uploaded += $chunk_len;
    $pct       = min(95, 15 + round(($uploaded / $file_size) * 80));
    $mb_done   = round($uploaded / 1048576, 1);
    $mb_total  = round($file_size  / 1048576, 1);

    error_log("YT Upload chunk: HTTP=$http_code uploaded=$uploaded/$file_size curl_err=$curl_err");

    if ($http_code === 308) {
        // Resume Incomplete — chunk accepted, continue
        outLog("📦 Chunk uploaded: {$mb_done} / {$mb_total} MB");
        outProgress($pct, "Uploading… {$mb_done} / {$mb_total} MB");
        continue;
    }

    if ($http_code === 200 || $http_code === 201) {
        // Upload complete!
        $video_data = json_decode($result, true);
        $video_id   = $video_data['id'] ?? null;
        outLog("✅ Upload complete! Video ID: $video_id", 'ok');
        outProgress(98, 'Upload complete, saving…');
        break;
    }

    // Error
    fclose($fh);
    error_log("YT Upload chunk error: HTTP=$http_code result=$result");
    outDone(false, ['error' => "Upload failed at chunk (HTTP $http_code): $result"]);
    exit;
}

fclose($fh);

if (!$video_id) {
    outDone(false, ['error' => 'Upload finished but no video ID returned']);
    exit;
}

// ── Step 3: Save to DB ────────────────────────────────────────────
$youtube_url   = 'https://www.youtube.com/watch?v=' . $video_id;
$now           = date('Y-m-d H:i:s');
$esc_vid_id    = mysqli_real_escape_string($conn, $video_id);
$esc_yt_url    = mysqli_real_escape_string($conn, $youtube_url);

mysqli_query($conn,
    "UPDATE hdb_podcasts SET
        youtube_video_id = '$esc_vid_id',
        youtube_url      = '$esc_yt_url',
        youtube_status   = 'posted',
        updated_at       = '$now'
     WHERE id = $podcast_id");

outLog("💾 Saved to DB: youtube_video_id=$video_id");
outProgress(100, 'Done!');
outDone(true, [
    'video_id' => $video_id,
    'url'      => $youtube_url,
]);
exit;
?>

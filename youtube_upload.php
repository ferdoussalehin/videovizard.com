<?php
// youtube_upload.php — uploads podcast video to YouTube
// Usage: youtube_upload.php?podcast_id=58
// Standalone — no session required, reads admin_id from hdb_podcasts

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
set_time_limit(0);

require_once 'youtube_config.php';
include 'dbconnect_hdb.php';

$podcast_id = (int)($_GET['podcast_id'] ?? 0);
if (!$podcast_id) die('Missing podcast_id. Use: youtube_upload.php?podcast_id=58');

// ── Load podcast from DB ──────────────────────────────────────────
$podcast = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, admin_id, company_id, title, caption_text, hashtags, keywords,
            published_video, youtube_status, youtube_video_id
     FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));

if (!$podcast) die("Podcast #$podcast_id not found.");

$admin_id       = (int)$podcast['admin_id'];
// Token is scoped to the podcast's owning company (no session here)
$company_id     = (int)($podcast['company_id'] ?? 0);
$title          = $podcast['title']         ?: 'VideoVizard Video #' . $podcast_id;
$description    = $podcast['caption_text']  ?: '';
$hashtags       = $podcast['hashtags']      ?: '';
$keywords       = $podcast['keywords']      ?: '';
$published_video= trim($podcast['published_video'] ?: '');

// Build full description with hashtags
if ($hashtags) $description .= "\n\n" . $hashtags;
if ($keywords) $description .= "\n\nKeywords: " . $keywords;

// ── Find video file ───────────────────────────────────────────────
$video_path = __DIR__ . '/published_videos/' . $published_video;

if (!$published_video || !file_exists($video_path)) {
    die("Video file not found: published_videos/$published_video<br>
         Make sure the video has been recorded and saved first.");
}

$file_size = filesize($video_path);
$mime_type = preg_match('/\.mp4$/i', $published_video) ? 'video/mp4' : 'video/webm';

// ── Load OAuth token (scoped to the podcast's company) ─────────────
$token_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, access_token, refresh_token, token_expiry
     FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND company_id=$company_id AND platform='youtube' LIMIT 1"));

if (!$token_row) {
    die("YouTube not connected for this account.
         <a href='youtube_connect.php'>Connect YouTube first</a>");
}
$yt_tok_id = (int)$token_row['id'];

// ── Refresh token if expired ──────────────────────────────────────
function refreshAccessToken($conn, $tok_id, $refresh_token) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => YT_CLIENT_ID,
            'client_secret' => YT_CLIENT_SECRET,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (empty($data['access_token'])) return null;

    $new_token  = $data['access_token'];
    $expiry     = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
    $now        = date('Y-m-d H:i:s');
    $esc_token  = mysqli_real_escape_string($conn, $new_token);
    $esc_expiry = mysqli_real_escape_string($conn, $expiry);

    mysqli_query($conn,
        "UPDATE hdb_oauth_tokens SET
            access_token = '$esc_token',
            token_expiry = '$esc_expiry',
            updated_at   = '$now'
         WHERE id=$tok_id");

    return $new_token;
}

// Check if token is expired
$access_token = $token_row['access_token'];
$expiry       = strtotime($token_row['token_expiry'] ?? '');
if ($expiry && $expiry < time() + 60) {
    error_log("YT Upload: Token expired, refreshing...");
    $access_token = refreshAccessToken($conn, $yt_tok_id, $token_row['refresh_token']);
    if (!$access_token) die("Failed to refresh YouTube token. <a href='youtube_connect.php'>Reconnect YouTube</a>");
}

// ── Helper: format bytes ──────────────────────────────────────────
function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes/1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024, 1)    . ' KB';
    return $bytes . ' B';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YouTube Upload — Podcast #<?= $podcast_id ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family:'Inter',system-ui,sans-serif;
    background:#f1f5f9;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
}
.card {
    background:#fff;
    border-radius:16px;
    padding:28px 32px;
    width:100%;
    max-width:500px;
    box-shadow:0 4px 24px rgba(0,0,0,0.09);
}
h1 { font-size:18px; font-weight:700; color:#0f2a44; margin-bottom:4px; }
.sub { font-size:12px; color:#64748b; margin-bottom:20px; }

.info-box {
    background:#f8fafc;
    border:1.5px solid #e2e8f0;
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:16px;
}
.info-row { display:flex; gap:8px; margin-bottom:6px; font-size:12px; }
.info-row:last-child { margin-bottom:0; }
.info-lbl { color:#94a3b8; font-weight:600; min-width:80px; }
.info-val { color:#1e293b; font-weight:600; flex:1; }

.progress-wrap {
    margin:16px 0;
    display:none;
}
.progress-bar-bg {
    background:#e2e8f0;
    border-radius:8px;
    height:12px;
    overflow:hidden;
    margin-bottom:6px;
}
.progress-bar {
    height:100%;
    background:linear-gradient(90deg,#ff0000,#ff4444);
    border-radius:8px;
    width:0%;
    transition:width .3s ease;
}
.progress-lbl {
    font-size:11px;
    color:#64748b;
    text-align:center;
}

.log-box {
    background:#0f172a;
    border-radius:10px;
    padding:12px 16px;
    font-family:monospace;
    font-size:11px;
    color:#94a3b8;
    line-height:1.8;
    max-height:200px;
    overflow-y:auto;
    margin:16px 0;
    display:none;
}
.log-box .ok  { color:#4ade80; }
.log-box .err { color:#f87171; }
.log-box .inf { color:#60a5fa; }

.btn-upload {
    width:100%;
    padding:13px;
    background:linear-gradient(135deg,#ff0000,#cc0000);
    color:#fff;
    border:none;
    border-radius:10px;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    transition:box-shadow .15s;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}
.btn-upload:hover { box-shadow:0 4px 14px rgba(255,0,0,0.35); }
.btn-upload:disabled { opacity:.5; cursor:not-allowed; }

.result-box {
    display:none;
    border-radius:10px;
    padding:16px;
    margin-top:16px;
    text-align:center;
}
.result-box.ok  { background:#f0fdf4; border:1.5px solid #86efac; }
.result-box.err { background:#fef2f2; border:1.5px solid #fca5a5; }
.result-box h3  { font-size:16px; font-weight:700; margin-bottom:8px; }
.result-box.ok  h3 { color:#166534; }
.result-box.err h3 { color:#991b1b; }
.result-box a {
    display:inline-block;
    margin-top:10px;
    padding:8px 20px;
    background:#ff0000;
    color:#fff;
    border-radius:8px;
    text-decoration:none;
    font-size:12px;
    font-weight:700;
}
.back { display:block; text-align:center; margin-top:14px; font-size:12px; color:#94a3b8; text-decoration:none; }
.back:hover { color:#0f2a44; }
</style>
</head>
<body>
<div class="card">
    <h1>▶️ Upload to YouTube</h1>
    <p class="sub">Podcast #<?= $podcast_id ?> — <?= htmlspecialchars($title) ?></p>

    <div class="info-box">
        <div class="info-row">
            <span class="info-lbl">Video file</span>
            <span class="info-val">📹 <?= htmlspecialchars($published_video) ?></span>
        </div>
        <div class="info-row">
            <span class="info-lbl">File size</span>
            <span class="info-val"><?= formatBytes($file_size) ?></span>
        </div>
        <div class="info-row">
            <span class="info-lbl">Format</span>
            <span class="info-val"><?= strtoupper(pathinfo($published_video, PATHINFO_EXTENSION)) ?></span>
        </div>
        <div class="info-row">
            <span class="info-lbl">Privacy</span>
            <span class="info-val">🔒 Private</span>
        </div>
        <?php if ($podcast['youtube_video_id']): ?>
        <div class="info-row">
            <span class="info-lbl">Already uploaded</span>
            <span class="info-val">
                <a href="https://youtube.com/watch?v=<?= htmlspecialchars($podcast['youtube_video_id']) ?>"
                   target="_blank" style="color:#ff0000;">
                    ▶ <?= htmlspecialchars($podcast['youtube_video_id']) ?>
                </a>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <div class="progress-wrap" id="progressWrap">
        <div class="progress-bar-bg">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        <div class="progress-lbl" id="progressLbl">Preparing upload…</div>
    </div>

    <div class="log-box" id="logBox"></div>

    <button class="btn-upload" id="btnUpload" onclick="startUpload()">
        ▶️ Upload to YouTube
    </button>

    <div class="result-box" id="resultBox"></div>

    <a href="youtube_upload.php?podcast_id=<?= $podcast_id ?>" class="back">↺ Reset</a>
</div>

<script>
const PODCAST_ID = <?= $podcast_id ?>;

function log(msg, cls) {
    const box = document.getElementById('logBox');
    box.style.display = 'block';
    const p = document.createElement('p');
    if (cls) p.className = cls;
    p.textContent = msg;
    box.appendChild(p);
    box.scrollTop = box.scrollHeight;
}

function setProgress(pct, label) {
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('progressBar').style.width    = pct + '%';
    document.getElementById('progressLbl').textContent    = label;
}

function showResult(ok, html) {
    const box = document.getElementById('resultBox');
    box.className   = 'result-box ' + (ok ? 'ok' : 'err');
    box.style.display = 'block';
    box.innerHTML   = html;
}

async function startUpload() {
    const btn = document.getElementById('btnUpload');
    btn.disabled    = true;
    btn.textContent = '⏳ Uploading…';

    log('Starting YouTube upload…', 'inf');
    setProgress(5, 'Initializing upload…');

    try {
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('podcast_id', PODCAST_ID);

        const r    = await fetch('youtube_upload_ajax.php', {
            method: 'POST',
            body:   fd,
        });
        const text = await r.text();

        // Parse streaming log lines
        const lines = text.split('\n').filter(l => l.trim());
        lines.forEach(line => {
            try {
                const obj = JSON.parse(line);
                if (obj.log)      log(obj.log, obj.cls || 'inf');
                if (obj.progress) setProgress(obj.progress, obj.label || '');
                if (obj.done) {
                    if (obj.success) {
                        setProgress(100, 'Upload complete!');
                        log('✅ Upload successful!', 'ok');
                        log('YouTube URL: ' + obj.url, 'ok');
                        showResult(true, `
                            <h3>✅ Uploaded to YouTube!</h3>
                            <p style="font-size:12px;color:#166534;margin-bottom:8px;">
                                Video ID: <strong>${obj.video_id}</strong>
                            </p>
                            <a href="${obj.url}" target="_blank">▶ View on YouTube</a>
                        `);
                        btn.textContent = '✅ Uploaded';
                    } else {
                        log('❌ Error: ' + obj.error, 'err');
                        showResult(false, `<h3>❌ Upload Failed</h3><p style="font-size:12px;color:#991b1b;">${obj.error}</p>`);
                        btn.disabled    = false;
                        btn.textContent = '▶️ Retry Upload';
                    }
                }
            } catch(e) {
                if (line.trim()) log(line, 'inf');
            }
        });

    } catch(e) {
        log('❌ Request failed: ' + e.message, 'err');
        showResult(false, `<h3>❌ Failed</h3><p style="font-size:12px;color:#991b1b;">${e.message}</p>`);
        btn.disabled    = false;
        btn.textContent = '▶️ Retry Upload';
    }
}
</script>
</body>
</html>

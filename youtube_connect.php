<?php
// youtube_connect.php
// Opens Google OAuth login to connect YouTube channel
require_once 'session_config.php';
session_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }

require_once 'youtube_config.php';

$admin_id = (int)$_SESSION['admin_id'];

// Build OAuth URL — state carries admin_id and company_id
$company_id = (int)($_SESSION['company_id'] ?? 0);
$state = bin2hex(random_bytes(16)) . '|' . $admin_id . '|' . $company_id;
$_SESSION['yt_oauth_state'] = $state;

$params = http_build_query([
    'client_id'             => YT_CLIENT_ID,
    'redirect_uri'          => YT_REDIRECT_URI,
    'response_type'         => 'code',
    'scope'                 => YT_SCOPE,
    'access_type'           => 'offline',   // gets refresh token
    'prompt'                => 'consent',   // always show consent to get refresh token
    'state'                 => $state,
]);

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;

// Check if already connected
include 'dbconnect_hdb.php';
$token = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT channel_name, channel_id, updated_at
     FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND company_id=$company_id AND platform='youtube' LIMIT 1"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connect YouTube — VideoVizard</title>
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
    padding:32px;
    width:100%;
    max-width:440px;
    box-shadow:0 4px 24px rgba(0,0,0,0.09);
    text-align:center;
}
.yt-icon { font-size:48px; margin-bottom:16px; }
h1 { font-size:20px; font-weight:700; color:#0f2a44; margin-bottom:8px; }
.sub { font-size:13px; color:#64748b; margin-bottom:24px; line-height:1.6; }

.status-box {
    background:#f0fdf4;
    border:1.5px solid #86efac;
    border-radius:10px;
    padding:14px 18px;
    margin-bottom:20px;
    text-align:left;
}
.status-box .lbl { font-size:10px; font-weight:700; color:#16a34a; text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.status-box .val { font-size:14px; font-weight:700; color:#0f2a44; }
.status-box .meta { font-size:11px; color:#64748b; margin-top:2px; }

.btn-yt {
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:13px 28px;
    background:#ff0000;
    color:#fff;
    border:none;
    border-radius:10px;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    transition:box-shadow .15s;
    margin-bottom:12px;
}
.btn-yt:hover { box-shadow:0 4px 14px rgba(255,0,0,0.35); }
.btn-yt .ico { font-size:20px; }

.btn-reconnect {
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 20px;
    background:transparent;
    color:#64748b;
    border:1.5px solid #e2e8f0;
    border-radius:10px;
    font-size:12px;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    transition:all .15s;
}
.btn-reconnect:hover { border-color:#0f2a44; color:#0f2a44; }

.back { display:block; margin-top:16px; font-size:12px; color:#94a3b8; text-decoration:none; }
.back:hover { color:#0f2a44; }

.perms {
    background:#f8fafc;
    border-radius:10px;
    padding:12px 16px;
    margin:20px 0;
    text-align:left;
}
.perms .lbl { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-bottom:8px; }
.perm-item { display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; margin-bottom:5px; }
.perm-item:last-child { margin-bottom:0; }
.perm-item .tick { color:#10b981; font-weight:700; }
</style>
</head>
<body>
<div class="card">
    <div class="yt-icon">▶️</div>
    <h1>Connect YouTube</h1>
    <p class="sub">Connect your YouTube channel to automatically upload and schedule videos from VideoVizard.</p>

    <?php if ($token): ?>
    <!-- Already connected -->
    <div class="status-box">
        <div class="lbl">✅ Connected Channel</div>
        <div class="val">📺 <?= htmlspecialchars($token['channel_name'] ?: 'YouTube Channel') ?></div>
        <div class="meta">ID: <?= htmlspecialchars($token['channel_id'] ?: '—') ?> &nbsp;·&nbsp; Connected: <?= htmlspecialchars($token['updated_at'] ?: '—') ?></div>
    </div>
    <a href="<?= $auth_url ?>" class="btn-reconnect">🔄 Reconnect / Switch Channel</a>
    <?php else: ?>
    <!-- Not connected -->
    <div class="perms">
        <div class="lbl">This will allow VideoVizard to:</div>
        <div class="perm-item"><span class="tick">✓</span> Upload videos to your YouTube channel</div>
        <div class="perm-item"><span class="tick">✓</span> Set video title, description and tags</div>
        <div class="perm-item"><span class="tick">✓</span> Read your channel info</div>
    </div>
    <a href="<?= $auth_url ?>" class="btn-yt">
        <span class="ico">▶️</span> Connect YouTube Channel
    </a>
    <?php endif; ?>

    <a href="vizard_browser.php" class="back">← Back to VideoVizard</a>
</div>
</body>
</html>

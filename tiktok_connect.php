<?php
// tiktok_connect.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
session_start();

if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }

require_once 'tiktok_config.php';
include 'dbconnect_hdb.php';

$admin_id = (int)$_SESSION['admin_id'];

// Build OAuth URL — state carries admin_id and company_id
$company_id = (int)($_SESSION['company_id'] ?? 0);
$csrf_state = bin2hex(random_bytes(16)) . '|' . $admin_id . '|' . $company_id;
$_SESSION['tt_oauth_state'] = $csrf_state;

$params = http_build_query([
    'client_key'    => TT_CLIENT_KEY,
    'redirect_uri'  => TT_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => TT_SCOPE,
    'state'         => $csrf_state,
]);

$auth_url = 'https://www.tiktok.com/v2/auth/authorize/?' . $params;

// Check if already connected
$token = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT channel_name, channel_id, updated_at, access_token
     FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND company_id=$company_id AND platform='tiktok' LIMIT 1"));

// Detect if connected with old scopes (no upload permission)
// We check by seeing if they connected before the approval date
$needs_reconnect = false;
if ($token) {
    $connected_at = strtotime($token['updated_at'] ?? '2000-01-01');
    // If connected before today, prompt reconnect to get new scopes
    $needs_reconnect = $connected_at < strtotime('2025-01-01');
}

$just_connected = isset($_GET['connected']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connect TikTok — VideoVizard</title>
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
    box-shadow:0 4px 24px rgba(0,0,0,.09);
    text-align:center;
}
.tt-icon { font-size:48px; margin-bottom:16px; }
h1 { font-size:20px; font-weight:700; color:#0f2a44; margin-bottom:8px; }
.sub { font-size:13px; color:#64748b; margin-bottom:24px; line-height:1.6; }

.status-box {
    background:#f0fdf4;
    border:1.5px solid #86efac;
    border-radius:10px;
    padding:14px 18px;
    margin-bottom:16px;
    text-align:left;
}
.status-box .lbl { font-size:10px; font-weight:700; color:#16a34a; text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.status-box .val { font-size:14px; font-weight:700; color:#0f2a44; }
.status-box .meta { font-size:11px; color:#64748b; margin-top:2px; }

.notice {
    border-radius:10px;
    padding:12px 16px;
    margin:12px 0 16px;
    font-size:12px;
    text-align:left;
    line-height:1.6;
}
.notice.blue  { background:#eff6ff; border:1.5px solid #93c5fd; color:#1e40af; }
.notice.green { background:#f0fdf4; border:1.5px solid #86efac; color:#166534; }
.notice.amber { background:#fffbeb; border:1.5px solid #fcd34d; color:#92400e; }

.btn-tt {
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:13px 28px;
    background:#000;
    color:#fff;
    border:none;
    border-radius:10px;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    transition:box-shadow .15s, background .15s;
    margin-bottom:10px;
    width:100%;
    justify-content:center;
}
.btn-tt:hover { box-shadow:0 4px 14px rgba(0,0,0,.35); background:#1a1a1a; }

.btn-secondary {
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
    width:100%;
    justify-content:center;
    margin-bottom:10px;
}
.btn-secondary:hover { border-color:#0f2a44; color:#0f2a44; }

.perms {
    background:#f8fafc;
    border-radius:10px;
    padding:12px 16px;
    margin:16px 0;
    text-align:left;
}
.perms .lbl { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-bottom:8px; }
.perm-item { display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; margin-bottom:5px; }
.perm-item:last-child { margin-bottom:0; }
.perm-item .tick { color:#10b981; font-weight:700; }

.back { display:block; margin-top:16px; font-size:12px; color:#94a3b8; text-decoration:none; }
.back:hover { color:#0f2a44; }
</style>
</head>
<body>
<div class="card">
    <div class="tt-icon">🎵</div>
    <h1>Connect TikTok</h1>
    <p class="sub">Connect your TikTok account to automatically upload and publish videos from VideoVizard.</p>

    <?php if ($just_connected): ?>
    <!-- Just reconnected successfully -->
    <div class="notice green">
        🎉 <strong>TikTok connected successfully!</strong> You can now upload and publish videos directly to TikTok from VideoVizard.
    </div>
    <?php endif; ?>

    <?php if ($token): ?>
    <!-- Already connected -->
    <div class="status-box">
        <div class="lbl">✅ Connected Account</div>
        <div class="val">🎵 <?= htmlspecialchars($token['channel_name'] ?: 'TikTok Account') ?></div>
        <div class="meta">
            ID: <?= htmlspecialchars($token['channel_id'] ?: '—') ?>
            &nbsp;·&nbsp;
            Updated: <?= htmlspecialchars($token['updated_at'] ?: '—') ?>
        </div>
    </div>

    <?php if ($needs_reconnect): ?>
    <!-- Old connection — prompt for new scopes -->
    <div class="notice blue">
        🎉 <strong>Video upload is now approved!</strong> Please reconnect to grant the new upload and publish permissions — this only takes a few seconds.
    </div>
    <a href="<?= $auth_url ?>" class="btn-tt">🔄 Reconnect to Enable Upload</a>
    <?php else: ?>
    <!-- Fully connected with new scopes -->
    <div class="notice green">
        ✅ <strong>Video upload enabled.</strong> Your account has full upload and publish permissions.
    </div>
    <a href="<?= $auth_url ?>" class="btn-secondary">🔄 Reconnect / Switch Account</a>
    <?php endif; ?>

    <?php else: ?>
    <!-- Not connected -->
    <div class="perms">
        <div class="lbl">This will allow VideoVizard to:</div>
        <div class="perm-item"><span class="tick">✓</span> Read your TikTok profile info</div>
        <div class="perm-item"><span class="tick">✓</span> View your video statistics</div>
        <div class="perm-item"><span class="tick">✓</span> Upload videos to your account</div>
        <div class="perm-item"><span class="tick">✓</span> Publish videos at scheduled times</div>
    </div>
    <a href="<?= $auth_url ?>" class="btn-tt">🎵 Connect TikTok Account</a>
    <?php endif; ?>

    <a href="vizard_browser.php" class="back">← Back to VideoVizard</a>
</div>
</body>
</html>

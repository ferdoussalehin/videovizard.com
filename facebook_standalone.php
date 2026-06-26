<?php
/**
 * fb_standalone.php
 * ─────────────────────────────────────────────────────────────────
 * STANDALONE Facebook OAuth connect + video post tester.
 * NO dependencies on VideoVizard files (no config.php, no dbconnect).
 * Upload this ONE file anywhere and visit it in a browser.
 *
 * Usage:
 *   Step 1 — fill in YOUR APP credentials in the CONFIG block below
 *   Step 2 — upload to your server (e.g. videovizard.com/fb_standalone.php)
 *   Step 3 — visit the URL, click "Connect Facebook"
 *   Step 4 — after connect, use the Post Test form to upload a video
 *
 * Redirect URI to register in Facebook App dashboard:
 *   https://videovizard.com/fb_standalone.php?action=callback
 * ─────────────────────────────────────────────────────────────────
 */

// ══════════════════════════════════════════════════════════════════
// ► FILL THESE IN BEFORE UPLOADING
// ══════════════════════════════════════════════════════════════════
define('FB_APP_ID', '952268383945804');
define('FB_APP_SECRET', '06510d978bdc1fbd3d11733839c94442');
define('FB_SELF_URL',    'https://videovizard.com/facebook_standalone.php'); // no trailing slash

// Absolute server path to your published_videos folder
define('VIDEO_DIR', '/home/videovizard/public_html/published_videos/');
// ══════════════════════════════════════════════════════════════════

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/fb_standalone_errors.log');
error_reporting(E_ALL);

session_set_cookie_params(86400);
session_start();

define('FB_REDIRECT_URI', FB_SELF_URL . '?action=callback');
define('FB_SCOPES', 'public_profile,pages_manage_posts,pages_read_engagement,pages_show_list,pages_manage_engagement');

$action = $_GET['action'] ?? '';

// ──────────────────────────────────────────────────────────────────
// ACTION: start OAuth
// ──────────────────────────────────────────────────────────────────
if ($action === 'connect') {
    // State = hex only (no session needed — survives redirect on shared hosting)
    $state = bin2hex(random_bytes(16));

    $url = "https://www.facebook.com/v21.0/dialog/oauth"
         . "?client_id="    . FB_APP_ID
         . "&redirect_uri=" . urlencode(FB_REDIRECT_URI)
         . "&state="        . urlencode($state)
         . "&scope="        . FB_SCOPES;

    header('Location: ' . $url);
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACTION: OAuth callback
// ──────────────────────────────────────────────────────────────────
if ($action === 'callback') {

    // ── DEBUG: dump everything Facebook sent back ─────────────────
    echo "<pre style='font-family:monospace;font-size:13px;padding:20px;background:#f8f8f8'>";
    echo "<strong>Full URL:</strong>\n";
    echo htmlspecialchars("https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}") . "\n\n";
    echo "<strong>GET params:</strong>\n";
    foreach ($_GET as $k => $v) {
        echo "  " . htmlspecialchars($k) . " = " . htmlspecialchars($v) . "\n";
    }
    echo "\n<strong>FB_REDIRECT_URI:</strong> " . htmlspecialchars(FB_REDIRECT_URI) . "\n";
    echo "<strong>FB_APP_ID:</strong> " . htmlspecialchars(FB_APP_ID) . "\n";
    echo "</pre><p style='font-family:sans-serif;padding:0 20px'><a href='" . FB_SELF_URL . "'>← Back</a></p>";
    exit;
    // ── END DEBUG ─────────────────────────────────────────────────

    // Error from Facebook?
    if (isset($_GET['error'])) {
        $msg = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
        fb_die("Facebook returned an error: $msg");
    }

    // State just needs to be present and non-empty
    $got_state = $_GET['state'] ?? '';
    if (empty($got_state)) {
        fb_die("No state parameter returned from Facebook. Please try again.");
    }

    $code = $_GET['code'] ?? '';
    if (!$code) fb_die("No authorization code received from Facebook.");

    // ── Exchange code for short-lived token ──────────────────────
    $resp = fb_curl("https://graph.facebook.com/v21.0/oauth/access_token?" . http_build_query([
        'client_id'     => FB_APP_ID,
        'redirect_uri'  => FB_REDIRECT_URI,
        'client_secret' => FB_APP_SECRET,
        'code'          => $code,
    ]));

    if (empty($resp['access_token'])) {
        fb_die("Short-lived token exchange failed: " . ($resp['error']['message'] ?? json_encode($resp)));
    }
    $short_token = $resp['access_token'];

    // ── Exchange for long-lived token (60 days) ──────────────────
    $resp2 = fb_curl("https://graph.facebook.com/v21.0/oauth/access_token?" . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => FB_APP_ID,
        'client_secret'     => FB_APP_SECRET,
        'fb_exchange_token' => $short_token,
    ]));

    if (empty($resp2['access_token'])) {
        fb_die("Long-lived token exchange failed: " . ($resp2['error']['message'] ?? json_encode($resp2)));
    }

    $long_token  = $resp2['access_token'];
    $expires_in  = (int)($resp2['expires_in'] ?? 5184000);
    $token_expiry = date('Y-m-d H:i:s', time() + $expires_in);

    // ── Get user info ────────────────────────────────────────────
    $me = fb_curl("https://graph.facebook.com/v21.0/me?fields=id,name&access_token=" . urlencode($long_token));

    // ── Get Pages this user manages ──────────────────────────────
    $pages_resp = fb_curl("https://graph.facebook.com/v21.0/me/accounts?access_token=" . urlencode($long_token));
    $pages = $pages_resp['data'] ?? [];

    if (empty($pages)) {
        fb_die("No Facebook Pages found for this account. Make sure you have at least one Page (not just a personal profile).");
    }

    // Store all pages + tokens in session
    $_SESSION['fb_user']        = ['id' => $me['id'] ?? '', 'name' => $me['name'] ?? ''];
    $_SESSION['fb_long_token']  = $long_token;
    $_SESSION['fb_token_expiry']= $token_expiry;
    $_SESSION['fb_pages']       = $pages; // full list
    $_SESSION['fb_page_index']  = 0;      // default to first page

    header('Location: ' . FB_SELF_URL . '?connected=1');
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACTION: select page
// ──────────────────────────────────────────────────────────────────
if ($action === 'select_page' && isset($_POST['page_index'])) {
    $idx = (int)$_POST['page_index'];
    $pages = $_SESSION['fb_pages'] ?? [];
    if (isset($pages[$idx])) {
        $_SESSION['fb_page_index'] = $idx;
    }
    header('Location: ' . FB_SELF_URL . '?connected=1');
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACTION: disconnect
// ──────────────────────────────────────────────────────────────────
if ($action === 'disconnect') {
    unset($_SESSION['fb_user'], $_SESSION['fb_long_token'], $_SESSION['fb_token_expiry'],
          $_SESSION['fb_pages'], $_SESSION['fb_page_index']);
    header('Location: ' . FB_SELF_URL);
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACTION: post video
// ──────────────────────────────────────────────────────────────────
if ($action === 'post_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');

    $pages      = $_SESSION['fb_pages']      ?? [];
    $page_index = $_SESSION['fb_page_index'] ?? 0;
    $page       = $pages[$page_index]        ?? null;

    if (!$page) { echo "ERROR: Not connected to a Facebook Page.\n"; exit; }

    $page_id    = $page['id'];
    $page_token = $page['access_token'];
    $video_file = trim($_POST['video_file'] ?? '');
    $title      = trim($_POST['title']      ?? 'Test video');
    $description= trim($_POST['description']?? '');

    if (!$video_file) { echo "ERROR: No video filename provided.\n"; exit; }

    $video_path = rtrim(VIDEO_DIR, '/') . '/' . basename($video_file);
    if (!file_exists($video_path)) {
        echo "ERROR: File not found: $video_path\n";
        exit;
    }

    $file_size = filesize($video_path);
    echo "Video: $video_file (" . round($file_size/1024/1024, 2) . " MB)\n";
    echo "Page:  {$page['name']} ($page_id)\n\n";

    // Step 1 — init
    echo "Step 1/3: Initialising upload session...\n";
    $init = fb_curl_post("https://graph-video.facebook.com/v21.0/{$page_id}/videos", [
        'upload_phase' => 'start',
        'file_size'    => $file_size,
        'access_token' => $page_token,
    ]);

    if (empty($init['upload_session_id'])) {
        echo "ERROR: " . ($init['error']['message'] ?? json_encode($init)) . "\n";
        exit;
    }

    $session_id = $init['upload_session_id'];
    $video_id   = $init['video_id'];
    echo "Session: $session_id  Video ID: $video_id\n\n";

    // Step 2 — transfer
    echo "Step 2/3: Uploading file (may take a minute)...\n";
    $transfer = fb_curl_post("https://graph-video.facebook.com/v21.0/{$page_id}/videos", [
        'upload_phase'      => 'transfer',
        'upload_session_id' => $session_id,
        'start_offset'      => 0,
        'video_file_chunk'  => new CURLFile($video_path, 'video/mp4', basename($video_path)),
        'access_token'      => $page_token,
    ], 300);

    if (!empty($transfer['error'])) {
        echo "ERROR: " . $transfer['error']['message'] . "\n";
        exit;
    }
    echo "Transfer OK.\n\n";

    // Step 3 — finish
    echo "Step 3/3: Finishing and publishing...\n";
    $finish = fb_curl_post("https://graph-video.facebook.com/v21.0/{$page_id}/videos", [
        'upload_phase'      => 'finish',
        'upload_session_id' => $session_id,
        'title'             => substr($title, 0, 255),
        'description'       => substr($description, 0, 2000),
        'published'         => 'true',
        'access_token'      => $page_token,
    ]);

    if (!empty($finish['error'])) {
        echo "ERROR: " . $finish['error']['message'] . "\n";
        exit;
    }

    echo "SUCCESS!\n";
    echo "Post ID: " . ($finish['id'] ?? $video_id) . "\n";
    exit;
}

// ──────────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ──────────────────────────────────────────────────────────────────
function fb_curl(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function fb_curl_post(string $url, array $fields, int $timeout = 60): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function fb_die(string $msg): void {
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;'>";
    echo "<h2 style='color:#c0392b'>Error</h2><p>" . htmlspecialchars($msg) . "</p>";
    echo "<p><a href='" . FB_SELF_URL . "'>← Start over</a></p></body></html>";
    exit;
}

// ──────────────────────────────────────────────────────────────────
// BUILD PAGE DATA
// ──────────────────────────────────────────────────────────────────
$connected   = isset($_SESSION['fb_long_token']);
$fb_user     = $_SESSION['fb_user']      ?? null;
$fb_pages    = $_SESSION['fb_pages']     ?? [];
$page_index  = $_SESSION['fb_page_index']?? 0;
$active_page = $fb_pages[$page_index]    ?? null;
$token_expiry= $_SESSION['fb_token_expiry'] ?? '';
$token_ok    = $token_expiry && strtotime($token_expiry) > time();

// List video files if VIDEO_DIR is accessible
$video_files = [];
if (is_dir(VIDEO_DIR)) {
    foreach (glob(VIDEO_DIR . '*.mp4') as $f) {
        $video_files[] = basename($f);
    }
    rsort($video_files);
}

// Show ?connected=1 banner
$just_connected = isset($_GET['connected']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facebook Standalone Tester — VideoVizard</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
         background: #f4f5f7; color: #1a1a2e; min-height: 100vh; }
  .wrap { max-width: 740px; margin: 0 auto; padding: 32px 20px 60px; }

  h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; }
  .sub { font-size: 14px; color: #666; margin-bottom: 32px; }

  .card { background: #fff; border-radius: 12px; border: 1px solid #e0e0e8;
          padding: 28px 28px 24px; margin-bottom: 20px; }
  .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; color: #333; }

  .badge { display: inline-flex; align-items: center; gap: 6px;
           padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; }
  .badge.ok    { background: #e6f9f0; color: #1a7a4a; }
  .badge.bad   { background: #fdecea; color: #b71c1c; }
  .badge.warn  { background: #fff8e1; color: #e65100; }

  .btn { display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
         padding: 10px 22px; border-radius: 8px; font-size: 14px; font-weight: 500;
         border: none; text-decoration: none; transition: opacity .15s; }
  .btn:hover { opacity: .85; }
  .btn-fb  { background: #1877f2; color: #fff; }
  .btn-red { background: #e53935; color: #fff; }
  .btn-green { background: #2e7d32; color: #fff; }
  .btn-gray { background: #e0e0e8; color: #333; }

  label { display: block; font-size: 13px; font-weight: 500; color: #555; margin-bottom: 5px; margin-top: 14px; }
  label:first-child { margin-top: 0; }
  input[type=text], textarea, select {
    width: 100%; padding: 9px 12px; border: 1px solid #d0d0dc; border-radius: 7px;
    font-size: 14px; font-family: inherit; background: #fafafa;
  }
  input[type=text]:focus, textarea:focus, select:focus {
    outline: none; border-color: #1877f2; background: #fff;
  }
  textarea { resize: vertical; min-height: 80px; }

  .page-list { list-style: none; }
  .page-list li { display: flex; align-items: center; gap: 10px;
                  padding: 10px 0; border-bottom: 1px solid #f0f0f4; }
  .page-list li:last-child { border-bottom: none; }
  .page-dot { width: 10px; height: 10px; border-radius: 50%; background: #ccc; flex-shrink: 0; }
  .page-dot.active { background: #1877f2; }
  .page-name { font-size: 14px; flex: 1; }
  .page-id   { font-size: 12px; color: #888; }

  .info-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }

  .banner { background: #e6f9f0; border: 1px solid #a8e6c6; border-radius: 8px;
            padding: 12px 16px; margin-bottom: 20px; color: #1a7a4a; font-size: 14px; }
  .warn-box { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px;
              padding: 12px 16px; margin-bottom: 20px; color: #6d4c00; font-size: 13px; }
  .warn-box code { background: #f5e6b0; padding: 1px 5px; border-radius: 3px; font-size: 12px; }

  #post-output { background: #1a1a2e; color: #a8ffbc; font-family: monospace;
                 font-size: 13px; padding: 16px; border-radius: 8px; margin-top: 14px;
                 white-space: pre-wrap; min-height: 60px; display: none; }
  .divider { height: 1px; background: #eee; margin: 20px 0; }
  .row { display: flex; gap: 10px; align-items: flex-end; }
  .row > * { flex: 1; }
  .row .btn { flex: 0 0 auto; }
</style>
</head>
<body>
<div class="wrap">

  <h1>📘 Facebook Standalone Tester</h1>
  <p class="sub">VideoVizard · Connect your Facebook Page and test video posting</p>

  <?php if ($just_connected): ?>
  <div class="banner">✓ Successfully connected to Facebook!</div>
  <?php endif; ?>

  <?php if (!defined('FB_APP_ID') || FB_APP_ID === 'YOUR_APP_ID_HERE'): ?>
  <div class="warn-box">
    ⚠️ You haven't set your credentials yet. Open <code>fb_standalone.php</code> and fill in
    <code>FB_APP_ID</code> and <code>FB_APP_SECRET</code> at the top of the file.
  </div>
  <?php endif; ?>

  <!-- ── Connection status ─────────────────────────────── -->
  <div class="card">
    <h2>Connection Status</h2>
    <div class="info-row">
      <?php if ($connected && $token_ok): ?>
        <span class="badge ok">● Connected</span>
        <span style="font-size:13px;color:#666">as <strong><?= htmlspecialchars($fb_user['name'] ?? 'Unknown') ?></strong></span>
        <span style="font-size:12px;color:#aaa">Token expires <?= date('M j, Y', strtotime($token_expiry)) ?></span>
      <?php elseif ($connected && !$token_ok): ?>
        <span class="badge bad">● Token Expired</span>
      <?php else: ?>
        <span class="badge bad">● Not Connected</span>
      <?php endif; ?>
    </div>

    <?php if (!$connected || !$token_ok): ?>
      <a href="?action=connect" class="btn btn-fb">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M24 12.073C24 5.404 18.627 0 12 0S0 5.404 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.007 1.792-4.669 4.532-4.669 1.313 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
        Connect Facebook
      </a>
    <?php else: ?>
      <a href="?action=disconnect" class="btn btn-red" style="margin-top:10px"
         onclick="return confirm('Disconnect Facebook?')">Disconnect</a>
    <?php endif; ?>
  </div>

  <?php if ($connected && $token_ok && !empty($fb_pages)): ?>

  <!-- ── Page selector ─────────────────────────────────── -->
  <div class="card">
    <h2>Facebook Pages (<?= count($fb_pages) ?>)</h2>
    <ul class="page-list">
      <?php foreach ($fb_pages as $i => $pg): ?>
      <li>
        <div class="page-dot <?= $i === $page_index ? 'active' : '' ?>"></div>
        <span class="page-name"><?= htmlspecialchars($pg['name']) ?></span>
        <span class="page-id">ID: <?= htmlspecialchars($pg['id']) ?></span>
        <?php if ($i !== $page_index): ?>
        <form method="post" action="?action=select_page" style="margin:0">
          <input type="hidden" name="page_index" value="<?= $i ?>">
          <button type="submit" class="btn btn-gray" style="padding:5px 12px;font-size:12px">Use this page</button>
        </form>
        <?php else: ?>
        <span class="badge ok" style="font-size:11px">Active</span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($active_page): ?>
    <p style="font-size:12px;color:#888;margin-top:10px">
      Posting to: <strong><?= htmlspecialchars($active_page['name']) ?></strong>
    </p>
    <?php endif; ?>
  </div>

  <!-- ── Post video ─────────────────────────────────────── -->
  <div class="card">
    <h2>Post Video to Facebook</h2>

    <form id="post-form">
      <label>Video file</label>
      <?php if (!empty($video_files)): ?>
      <select name="video_file" id="video_file">
        <option value="">— select a video —</option>
        <?php foreach ($video_files as $vf): ?>
        <option value="<?= htmlspecialchars($vf) ?>"><?= htmlspecialchars($vf) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="text" name="video_file" id="video_file"
             placeholder="e.g. podcast_167_final.mp4  (filename only, no path)">
      <p style="font-size:12px;color:#888;margin-top:4px">
        Video directory: <code><?= htmlspecialchars(VIDEO_DIR) ?></code>
        <?php if (!is_dir(VIDEO_DIR)): ?>
        <span style="color:#c0392b"> — directory not found, check VIDEO_DIR in the file</span>
        <?php endif; ?>
      </p>
      <?php endif; ?>

      <label>Title</label>
      <input type="text" name="title" value="Test Video" maxlength="255">

      <label>Description</label>
      <textarea name="description" rows="3" placeholder="Caption / hashtags…"></textarea>

      <div class="divider"></div>
      <button type="button" class="btn btn-green" onclick="postVideo()">
        ▶ Post to Facebook
      </button>
    </form>

    <div id="post-output"></div>
  </div>

  <?php endif; ?>

  <!-- ── Setup instructions ─────────────────────────────── -->
  <div class="card">
    <h2>Setup checklist</h2>
    <ol style="font-size:13px;line-height:2;padding-left:18px;color:#444">
      <li>In <strong>Facebook Developer Portal → Your App → Facebook Login → Settings</strong>,
          add this exact URI to <em>Valid OAuth Redirect URIs</em>:<br>
          <code style="background:#f0f0f8;padding:2px 8px;border-radius:4px;font-size:12px">
            <?= htmlspecialchars(FB_REDIRECT_URI) ?>
          </code></li>
      <li>App must be in <strong>Live mode</strong> (or your test account must be an App Tester).</li>
      <li>Required permissions: <code>pages_manage_posts</code>, <code>pages_read_engagement</code>,
          <code>pages_show_list</code>, <code>pages_manage_engagement</code></li>
      <li>For video posting, the Page must allow video uploads
          (most Business Pages do by default).</li>
    </ol>
  </div>

</div><!-- /wrap -->

<script>
function postVideo() {
  const form   = document.getElementById('post-form');
  const out    = document.getElementById('post-output');
  const file   = document.getElementById('video_file').value.trim();
  if (!file) { alert('Please select or enter a video filename.'); return; }

  const data   = new FormData(form);
  out.style.display = 'block';
  out.textContent   = 'Starting upload...\n';

  fetch('?action=post_video', { method: 'POST', body: data })
    .then(r => r.text())
    .then(t => { out.textContent = t; })
    .catch(e => { out.textContent = 'Request error: ' + e; });
}
</script>
</body>
</html>

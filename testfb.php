<?php
/**
 * fb_debug.php  — VideoVizard Facebook Connection Debugger
 * PHP 7.2+ compatible
 *
 * Upload to your server root, visit it, fix what it shows, delete it when done.
 */

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

// ── PHP 7 polyfills ──────────────────────────────────────────────
if (!function_exists('str_starts_with')) { 
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n !== '' && strpos($h, $n) !== false; }
}

// ── Safe DB helpers ──────────────────────────────────────────────
function sq($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if ($r === false) error_log("[fb_debug] SQL failed: " . mysqli_error($conn) . " | $sql");
    return $r;
}
function sqa($conn, $sql) {
    $r = sq($conn, $sql);
    return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
}
function tbl_exists($conn, $t) {
    $r = sq($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $r && mysqli_num_rows($r) > 0;
}
function get_cols($conn, $t) {
    $c = [];
    if (!tbl_exists($conn, $t)) return $c;
    $r = sq($conn, "SHOW COLUMNS FROM `$t`");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $c[] = $row['Field'];
    return $c;
}

// ── Bootstrap ────────────────────────────────────────────────────
require_once 'config.php';
include 'dbconnect_hdb.php';
if (!isset($conn) && isset($con)) $conn = $con;
if (!isset($conn)) die('<pre>ERROR: No $conn or $con after dbconnect_hdb.php</pre>');

session_set_cookie_params(15552000);
session_start();

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$admin_id) {
    foreach (['hdb_admins','admins','hdb_users','users','tbl_admin','tbl_admins'] as $t) {
        if (tbl_exists($conn, $t)) {
            $tmp = sqa($conn, "SELECT id FROM `$t` ORDER BY id LIMIT 1");
            if ($tmp) { $admin_id = (int)$tmp['id']; break; }
        }
    }
}

$step = $_GET['step'] ?? 'check';

// ═══════════════════════════════════════════════════════════════
// STEP: connect
// ═══════════════════════════════════════════════════════════════
if ($step === 'connect') {
    if (!$admin_id) die('No admin_id. Log into VideoVizard first.');
    if (!defined('FB_APP_ID') || !defined('FB_REDIRECT_URI')) die('FB_APP_ID or FB_REDIRECT_URI missing from config.php');

    $state = bin2hex(random_bytes(16)) . '|' . $admin_id;
    $_SESSION['fb_state']    = $state;
    $_SESSION['fb_admin_id'] = $admin_id;

    $perms = 'email,public_profile,pages_manage_posts,pages_read_engagement,pages_show_list,instagram_content_publish,instagram_basic';
    $url   = "https://www.facebook.com/v18.0/dialog/oauth"
           . "?client_id="     . urlencode(FB_APP_ID)
           . "&redirect_uri="  . urlencode(FB_REDIRECT_URI)
           . "&state="         . urlencode($state)
           . "&scope="         . $perms
           . "&response_type=code";

    error_log("[fb_debug] OAuth redirect admin_id=$admin_id");
    header('Location: ' . $url);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// STEP: callback
// ═══════════════════════════════════════════════════════════════
if ($step === 'callback') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Facebook Callback Debug ===\nTime: " . date('Y-m-d H:i:s') . "\n\nGET:\n";
    foreach ($_GET as $k => $v) echo "  $k = $v\n";
    echo "\n";

    if (isset($_GET['error'])) {
        echo "FACEBOOK ERROR: " . $_GET['error'] . "\n";
        echo "Description:    " . ($_GET['error_description'] ?? '') . "\n\n";
        echo "FIX: App may be in Development mode.\n";
        echo "Go to Meta Dashboard > App Roles > Testers and add your account.\n";
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (!$code) { echo "ERROR: No code received.\n"; exit; }

    $parts    = explode('|', $_GET['state'] ?? '');
    $aid      = (int)($parts[1] ?? 0);
    if (!$aid) $aid = (int)($_SESSION['fb_admin_id'] ?? $_SESSION['admin_id'] ?? $admin_id);
    if (!$aid) { echo "ERROR: Cannot find admin_id. Log into VideoVizard and try again.\n"; exit; }
    echo "admin_id: $aid\n\n";

    // Step 1: short-lived token
    echo "Step 1: Short-lived token...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://graph.facebook.com/v18.0/oauth/access_token"
                                . "?client_id="     . FB_APP_ID
                                . "&redirect_uri="  . urlencode(FB_REDIRECT_URI)
                                . "&client_secret=" . FB_APP_SECRET
                                . "&code="          . urlencode($code),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $r1 = curl_exec($ch); $h1 = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ce = curl_error($ch); curl_close($ch);
    echo "HTTP $h1 | cURL: " . ($ce ?: 'ok') . "\n$r1\n\n";
    $d1 = json_decode($r1, true);
    if (empty($d1['access_token'])) {
        echo "FAILED.\nCAUSES:\n";
        echo "  1. FB_REDIRECT_URI mismatch. Yours: " . FB_REDIRECT_URI . "\n";
        echo "     Must EXACTLY match: Meta Dashboard > Facebook Login > Valid OAuth Redirect URIs\n";
        echo "  2. Wrong FB_APP_SECRET\n  3. Code already used (one-time only)\n"; exit;
    }
    echo "Short-lived token: OK\n";

    // Step 2: long-lived token
    echo "\nStep 2: Long-lived token...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://graph.facebook.com/v18.0/oauth/access_token"
                                . "?grant_type=fb_exchange_token"
                                . "&client_id="         . FB_APP_ID
                                . "&client_secret="     . FB_APP_SECRET
                                . "&fb_exchange_token=" . urlencode($d1['access_token']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $r2 = curl_exec($ch); $h2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "HTTP $h2\n$r2\n\n";
    $d2 = json_decode($r2, true);
    if (empty($d2['access_token'])) { echo "FAILED getting long-lived token.\n"; exit; }
    $long_token   = $d2['access_token'];
    $token_expiry = date('Y-m-d H:i:s', time() + (int)($d2['expires_in'] ?? 5184000));
    echo "Long-lived token: OK — expires $token_expiry\n";

    // Step 3: get pages
    echo "\nStep 3: Getting Pages...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://graph.facebook.com/v18.0/me/accounts?access_token=" . urlencode($long_token),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $r3 = curl_exec($ch); $h3 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "HTTP $h3\n$r3\n\n";
    $d3    = json_decode($r3, true);
    $pages = $d3['data'] ?? [];
    if (empty($pages)) {
        echo "ERROR: No Facebook Pages found.\n";
        echo "FIX: This account must ADMIN a Facebook Page (not a personal profile).\n";
        echo "Create one: https://www.facebook.com/pages/create\n"; exit;
    }
    echo "Pages found: " . count($pages) . "\n";
    foreach ($pages as $i => $pg) echo "  [$i] {$pg['name']} — {$pg['id']}\n";

    $page_id    = $pages[0]['id'];
    $page_name  = $pages[0]['name'];
    $page_token = $pages[0]['access_token'] ?? $long_token;

    // Step 4: ensure unique index
    echo "\nStep 4: Unique index...\n";
    // Per-company tokens: the unique key must include company_id so the same
    // user can connect the same platform under different companies. Any older
    // unique key that constrains (admin_id, platform) WITHOUT company_id is
    // dropped — covers admin_platform and uq_admin_platform_channel.
    $has_composite = false; $drop_keys = [];
    $idx = []; // key_name => [seq => column]
    $ir = sq($conn, "SHOW INDEX FROM hdb_oauth_tokens");
    if ($ir) while ($ix = mysqli_fetch_assoc($ir)) {
        if (!$ix['Non_unique'] && $ix['Key_name'] !== 'PRIMARY')
            $idx[$ix['Key_name']][(int)$ix['Seq_in_index']] = $ix['Column_name'];
    }
    foreach ($idx as $kname => $cols) {
        ksort($cols); $set = array_values($cols);
        if ($set === ['admin_id','company_id','platform']) { $has_composite = true; continue; }
        // Old blocking key: unique, mentions admin_id+platform, lacks company_id
        if (in_array('admin_id',$set,true) && in_array('platform',$set,true)
            && !in_array('company_id',$set,true)) {
            $drop_keys[] = $kname;
        }
    }
    if (!$has_composite) {
        foreach ($drop_keys as $dk) sq($conn, "ALTER TABLE hdb_oauth_tokens DROP INDEX `$dk`");
        // Collapse duplicates WITHIN the same company before adding the key
        sq($conn, "DELETE t1 FROM hdb_oauth_tokens t1 INNER JOIN hdb_oauth_tokens t2
                   WHERE t1.id < t2.id AND t1.admin_id=t2.admin_id
                     AND t1.company_id=t2.company_id AND t1.platform=t2.platform");
        $fx = sq($conn, "ALTER TABLE hdb_oauth_tokens ADD UNIQUE KEY uniq_admin_company_platform (admin_id, company_id, platform)");
        echo $fx
            ? "Composite unique index added" . ($drop_keys ? " (dropped: " . implode(', ', $drop_keys) . ")" : "") . ".\n"
            : "Could not add index: " . mysqli_error($conn) . "\n";
    } else { echo "Composite unique index already exists.\n"; }

    // Step 5: save
    echo "\nStep 5: Saving to DB...\n";
    $now = date('Y-m-d H:i:s');
    $ok  = sq($conn,
        "INSERT INTO hdb_oauth_tokens
            (admin_id, platform, access_token, refresh_token, token_expiry, channel_id, channel_name, created_at, updated_at)
         VALUES ($aid, 'facebook',
             '" . mysqli_real_escape_string($conn, $long_token)   . "',
             '" . mysqli_real_escape_string($conn, $page_token)   . "',
             '" . mysqli_real_escape_string($conn, $token_expiry) . "',
             '" . mysqli_real_escape_string($conn, $page_id)      . "',
             '" . mysqli_real_escape_string($conn, $page_name)    . "',
             '$now','$now')
         ON DUPLICATE KEY UPDATE
             access_token  = '" . mysqli_real_escape_string($conn, $long_token)   . "',
             refresh_token = '" . mysqli_real_escape_string($conn, $page_token)   . "',
             token_expiry  = '" . mysqli_real_escape_string($conn, $token_expiry) . "',
             channel_id    = '" . mysqli_real_escape_string($conn, $page_id)      . "',
             channel_name  = '" . mysqli_real_escape_string($conn, $page_name)    . "',
             updated_at    = '$now'"
    );
    if (!$ok) { echo "DB SAVE FAILED: " . mysqli_error($conn) . "\n"; exit; }

    $v = sqa($conn, "SELECT * FROM hdb_oauth_tokens WHERE admin_id=$aid AND platform='facebook' LIMIT 1");
    if ($v) {
        echo "\n=== FACEBOOK CONNECTED! ===\n";
        echo "Page:    {$v['channel_name']}\nID:      {$v['channel_id']}\nExpires: {$v['token_expiry']}\n\n";
        echo "Delete fb_debug.php then test: facebook_test.php?podcast_id=YOUR_ID\n";
        $_SESSION['admin_id'] = $aid;
    } else {
        echo "Saved but could not verify — check DB permissions.\n";
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// DEFAULT: check page
// ═══════════════════════════════════════════════════════════════
$fb_app_id   = defined('FB_APP_ID')       ? FB_APP_ID       : null;
$fb_secret   = defined('FB_APP_SECRET')   ? FB_APP_SECRET   : null;
$fb_redirect = defined('FB_REDIRECT_URI') ? FB_REDIRECT_URI : null;

$tok = null; $dup = 0;
if ($admin_id && tbl_exists($conn, 'hdb_oauth_tokens')) {
    $tok = sqa($conn, "SELECT * FROM hdb_oauth_tokens WHERE admin_id=$admin_id AND platform='facebook' LIMIT 1");
    // Duplicates are now counted per company — multiple companies legitimately
    // each hold their own row, so only >1 within a single company is a problem.
    $dr  = sqa($conn, "SELECT COUNT(*) as c FROM hdb_oauth_tokens
                       WHERE admin_id=$admin_id AND platform='facebook'
                       GROUP BY company_id ORDER BY c DESC LIMIT 1");
    $dup = (int)($dr['c'] ?? 0);
}

$oauth_cols   = get_cols($conn, 'hdb_oauth_tokens');
$pod_cols     = get_cols($conn, 'hdb_podcasts');
$miss_oauth   = array_diff(['id','company_id','admin_id','platform','access_token','refresh_token','token_expiry','channel_id','channel_name'], $oauth_cols);
$miss_pod     = array_diff(['facebook_status','facebook_post_id','facebook_posted_at','facebook_error','facebook_scheduled_at'], $pod_cols);

$has_unique = false;
if (tbl_exists($conn, 'hdb_oauth_tokens')) {
    $u_idx = [];
    $ir = sq($conn, "SHOW INDEX FROM hdb_oauth_tokens");
    if ($ir) while ($ix = mysqli_fetch_assoc($ir))
        if (!$ix['Non_unique']) $u_idx[$ix['Key_name']][(int)$ix['Seq_in_index']] = $ix['Column_name'];
    foreach ($u_idx as $cols) {
        ksort($cols);
        if (array_values($cols) === ['admin_id','company_id','platform']) $has_unique = true;
    }
}

$cb_name   = $fb_redirect ? basename(parse_url($fb_redirect, PHP_URL_PATH)) : 'facebook_callback.php';
$cb_exists = file_exists(__DIR__ . '/' . $cb_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Facebook Debugger — VideoVizard</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Segoe UI,sans-serif;background:#EBF4FD;padding:28px 20px;color:#0D3560;font-size:14px;line-height:1.5}
.wrap{max-width:820px;margin:0 auto}
h1{font-size:20px;font-weight:700;margin-bottom:3px}
.sub{color:#5A7FA8;font-size:13px;margin-bottom:22px}
.card{background:#fff;border:1px solid #D1E4F5;border-radius:12px;padding:18px 22px;margin-bottom:14px}
.card h2{font-size:14px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #EBF4FD}
.row{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid #F4F9FE;gap:12px}
.row:last-child{border:none}
.lbl{color:#5A7FA8;min-width:200px;flex-shrink:0;font-size:13px}
.val{font-weight:500;word-break:break-all;font-size:13px}
.ok{color:#12B76A;font-weight:600}
.warn{color:#F79009;font-weight:600}
.bad{color:#F04438;font-weight:600}
.fix{background:#FFF8E7;border:1px solid #FCD34D;border-radius:8px;padding:12px 14px;margin-top:8px;font-size:12.5px;line-height:1.6}
.fix strong{color:#92400E}
pre{background:#F4F9FE;border:1px solid #D1E4F5;border-radius:6px;padding:10px 12px;font-size:12px;overflow-x:auto;margin-top:6px;white-space:pre-wrap}
.btn{display:inline-block;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;color:#fff;background:#2E8FE8}
.btn:hover{background:#185FA5}
.cta{text-align:center;padding:24px 20px}
.cta p{color:#5A7FA8;font-size:13px;margin-top:12px}
.phpv{font-size:12px;color:#5A7FA8;float:right;font-weight:400}
</style>
</head>
<body>
<div class="wrap">

<h1>Facebook Connection Debugger <span class="phpv">PHP <?= PHP_VERSION ?></span></h1>
<div class="sub">VideoVizard &middot; admin_id = <?= $admin_id ?: '<span class="bad">0 (not logged in)</span>' ?></div>

<?php if (!$admin_id): ?>
<div class="card" style="border-color:#F04438">
    <span class="bad">Not logged into VideoVizard.</span>
    Checks will still run, but Connect button needs your session.
    <a href="login.php" style="color:#2E8FE8;margin-left:8px">Log in first &rarr;</a>
</div>
<?php endif; ?>

<!-- 1. config.php -->
<div class="card">
<h2>1. config.php constants</h2>
<div class="row">
    <span class="lbl">FB_APP_ID</span>
    <div class="val">
        <?php if ($fb_app_id): ?>
            <?= htmlspecialchars($fb_app_id) ?><br>
            <span class="<?= strlen($fb_app_id) > 10 ? 'ok' : 'warn' ?>">
                <?= strlen($fb_app_id) > 10 ? '&#10003; Looks good' : '&#9888; Seems short — verify in Meta Dashboard' ?>
            </span>
        <?php else: ?>
            <span class="bad">&#10007; NOT DEFINED</span>
            <div class="fix"><strong>Add to config.php:</strong>
<pre>define('FB_APP_ID', 'your_app_id_here');</pre></div>
        <?php endif; ?>
    </div>
</div>
<div class="row">
    <span class="lbl">FB_APP_SECRET</span>
    <div class="val">
        <?php if ($fb_secret): ?>
            <span class="ok">&#10003; Set</span> (<?= strlen($fb_secret) ?> chars)
            <?php if (strlen($fb_secret) < 25): ?>
                <br><span class="warn">&#9888; App secrets are usually 32 chars — double check</span>
            <?php endif; ?>
        <?php else: ?>
            <span class="bad">&#10007; NOT DEFINED</span>
            <div class="fix"><strong>Add to config.php:</strong>
<pre>define('FB_APP_SECRET', 'your_app_secret_here');</pre></div>
        <?php endif; ?>
    </div>
</div>
<div class="row">
    <span class="lbl">FB_REDIRECT_URI</span>
    <div class="val">
        <?php if ($fb_redirect): ?>
            <code><?= htmlspecialchars($fb_redirect) ?></code><br>
            <?php $is_https = (strpos($fb_redirect, 'https://') === 0); ?>
            <span class="<?= $is_https ? 'ok' : 'bad' ?>">
                <?= $is_https ? '&#10003; Uses HTTPS' : '&#10007; Must use https:// — Facebook rejects http' ?>
            </span>
            <div class="fix">
                <strong>Critical:</strong> This EXACT URL must be in Meta Dashboard:<br>
                <strong>Your App &rarr; Facebook Login &rarr; Settings &rarr; Valid OAuth Redirect URIs</strong><br>
                Copy exactly: <code><?= htmlspecialchars($fb_redirect) ?></code>
            </div>
        <?php else: ?>
            <span class="bad">&#10007; NOT DEFINED</span>
            <div class="fix"><strong>Add to config.php:</strong>
<pre>define('FB_REDIRECT_URI', 'https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/facebook_callback.php');</pre></div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- 2. Token -->
<div class="card">
<h2>2. Facebook token in database (admin_id=<?= $admin_id ?>)</h2>
<?php if (!$admin_id): ?>
    <span class="warn">Log in to VideoVizard to check your token.</span>
<?php elseif (!tbl_exists($conn, 'hdb_oauth_tokens')): ?>
    <span class="bad">&#10007; Table hdb_oauth_tokens does not exist yet.</span>
    <div class="fix"><strong>Create it in phpMyAdmin:</strong>
<pre>CREATE TABLE hdb_oauth_tokens (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  company_id    INT NOT NULL DEFAULT 0,
  admin_id      INT NOT NULL,
  platform      VARCHAR(30) NOT NULL,
  access_token  TEXT NOT NULL,
  refresh_token TEXT NULL,
  token_expiry  DATETIME NULL,
  channel_id    VARCHAR(100) NULL,
  channel_name  VARCHAR(255) NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_company_platform (admin_id, company_id, platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre></div>
<?php elseif (!$tok): ?>
    <div class="row"><span class="lbl">Token</span>
        <span class="bad">&#10007; No token found — not yet connected. Use button below.</span></div>
<?php else:
    $exp = $tok['token_expiry'] ? strtotime($tok['token_expiry']) : 0;
    $days = $exp ? round(($exp - time()) / 86400) : 0;
    $expired = $exp && $exp < time();
?>
    <div class="row"><span class="lbl">Facebook Page</span>
        <span class="val ok">&#10003; <?= htmlspecialchars($tok['channel_name']) ?> (ID: <?= htmlspecialchars($tok['channel_id']) ?>)</span></div>
    <div class="row"><span class="lbl">Expiry</span>
        <span class="val <?= $expired ? 'bad' : ($days < 7 ? 'warn' : 'ok') ?>">
            <?= $expired ? '&#10007; EXPIRED — reconnect below' : "&#10003; $days days left" ?>
            &mdash; <?= htmlspecialchars($tok['token_expiry'] ?? '') ?>
        </span></div>
    <div class="row"><span class="lbl">User token (access_token)</span>
        <span class="<?= !empty($tok['access_token']) ? 'ok' : 'bad' ?>">
            <?= !empty($tok['access_token']) ? '&#10003; Stored' : '&#10007; EMPTY' ?></span></div>
    <div class="row"><span class="lbl">Page token (refresh_token col)</span>
        <span class="<?= !empty($tok['refresh_token']) ? 'ok' : 'bad' ?>">
            <?= !empty($tok['refresh_token']) ? '&#10003; Stored' : '&#10007; EMPTY — this is the posting token!' ?></span></div>
    <?php if ($dup > 1): ?>
    <div class="row"><span class="lbl">Duplicate rows</span>
        <span class="bad">&#10007; <?= $dup ?> rows — only 1 expected. Breaks token updates.</span></div>
    <div class="fix"><strong>Fix in phpMyAdmin:</strong>
<pre>DELETE t1 FROM hdb_oauth_tokens t1
INNER JOIN hdb_oauth_tokens t2
  WHERE t1.id &lt; t2.id AND t1.admin_id=t2.admin_id AND t1.company_id=t2.company_id AND t1.platform=t2.platform;

ALTER TABLE hdb_oauth_tokens ADD UNIQUE KEY uniq_admin_company_platform (admin_id, company_id, platform);</pre></div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- 3. Table structure -->
<div class="card">
<h2>3. hdb_oauth_tokens structure</h2>
<?php if (!tbl_exists($conn, 'hdb_oauth_tokens')): ?>
    <span class="bad">&#10007; Table does not exist — see fix above</span>
<?php else: ?>
    <div class="row"><span class="lbl">Columns present</span><span class="val"><?= implode(', ', $oauth_cols) ?></span></div>
    <?php if ($miss_oauth): ?>
    <div class="row"><span class="lbl">Missing columns</span><span class="bad">&#10007; <?= implode(', ', $miss_oauth) ?></span></div>
    <div class="fix"><strong>Fix:</strong><pre><?php foreach ($miss_oauth as $m) echo "ALTER TABLE hdb_oauth_tokens ADD COLUMN `$m` VARCHAR(500) NULL;\n"; ?></pre></div>
    <?php else: ?>
    <div class="row"><span class="lbl">Required columns</span><span class="ok">&#10003; All present</span></div>
    <?php endif; ?>
    <div class="row"><span class="lbl">Unique key (admin_id, platform)</span>
        <?php if ($has_unique): ?>
            <span class="ok">&#10003; Present</span>
        <?php else: ?>
            <div><span class="bad">&#10007; Missing — token updates will create duplicates</span>
            <div class="fix"><strong>Fix:</strong>
<pre>DELETE t1 FROM hdb_oauth_tokens t1
INNER JOIN hdb_oauth_tokens t2
  WHERE t1.id &lt; t2.id AND t1.admin_id=t2.admin_id AND t1.company_id=t2.company_id AND t1.platform=t2.platform;

ALTER TABLE hdb_oauth_tokens ADD UNIQUE KEY uniq_admin_company_platform (admin_id, company_id, platform);</pre></div></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<!-- 4. Podcasts columns -->
<div class="card">
<h2>4. hdb_podcasts — Facebook columns</h2>
<?php if (!tbl_exists($conn, 'hdb_podcasts')): ?>
    <span class="warn">&#9888; hdb_podcasts table not found</span>
<?php elseif ($miss_pod): ?>
    <div class="row"><span class="lbl">Missing</span><span class="bad">&#10007; <?= implode(', ', $miss_pod) ?></span></div>
    <div class="fix"><strong>Fix:</strong>
<pre>ALTER TABLE hdb_podcasts
<?php
$lines = [];
foreach ($miss_pod as $m) {
    if ($m === 'facebook_status')       $lines[] = "  ADD COLUMN facebook_status       ENUM('pending','posting','posted','failed','cancelled') DEFAULT 'pending'";
    elseif ($m === 'facebook_scheduled_at') $lines[] = "  ADD COLUMN facebook_scheduled_at DATETIME NULL";
    elseif ($m === 'facebook_posted_at')    $lines[] = "  ADD COLUMN facebook_posted_at    DATETIME NULL";
    elseif ($m === 'facebook_post_id')      $lines[] = "  ADD COLUMN facebook_post_id      VARCHAR(100) NULL";
    elseif ($m === 'facebook_error')        $lines[] = "  ADD COLUMN facebook_error        TEXT NULL";
}
echo implode(",\n", $lines) . ";";
?></pre></div>
<?php else: ?>
    <div class="row"><span class="lbl">All columns</span><span class="ok">&#10003; Present</span></div>
<?php endif; ?>
</div>

<!-- 5. Files -->
<div class="card">
<h2>5. Files on server</h2>
<?php foreach (['facebook_callback.php','facebook_post.php','config.php'] as $f): ?>
<div class="row">
    <span class="lbl"><?= $f ?></span>
    <span class="<?= file_exists(__DIR__.'/'.$f) ? 'ok' : 'bad' ?>">
        <?= file_exists(__DIR__.'/'.$f) ? '&#10003; Found' : '&#10007; Not found' ?></span>
</div>
<?php endforeach; ?>
<?php if ($cb_name && $cb_name !== 'facebook_callback.php'): ?>
<div class="row">
    <span class="lbl">URI target: <?= htmlspecialchars($cb_name) ?></span>
    <span class="<?= $cb_exists ? 'ok' : 'bad' ?>">
        <?= $cb_exists ? '&#10003; Found' : '&#10007; Not found — filename mismatch' ?></span>
</div>
<?php if (!$cb_exists): ?>
<div class="fix">
    <strong>Your FB_REDIRECT_URI points to <code><?= htmlspecialchars($cb_name) ?></code></strong>
    but your file is <code>facebook_callback.php</code>.<br>
    Fix: Change FB_REDIRECT_URI in config.php to end in <code>/facebook_callback.php</code>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<!-- Connect -->
<div class="card cta">
    <a href="fb_debug.php?step=connect" class="btn">Connect Facebook &rarr;</a>
    <p>Fix all &#10007; items above first, then click Connect.<br>
    After success, <strong>delete fb_debug.php</strong> from your server.</p>
</div>

</div>
</body>
</html>

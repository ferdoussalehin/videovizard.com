<?php
/**
 * facebook_connected.php
 * Post-OAuth landing page — shows connected status, page selector,
 * and a manual post button. Replaces the old facebook_connect.php UI role.
 *
 * Links in from:  vizard_browser.php modal, settings sidebar, etc.
 * Redirects to:   facebook_connect.php  (to start fresh OAuth)
 *                 facebook_post.php     (to post a specific video)
 */

session_set_cookie_params(15552000);
session_start();

require_once 'config.php';
include   'dbconnect_hdb.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$admin_id) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
// Per-company tokens: scope all reads/writes to the active workspace
$company_id = (int)($_SESSION['company_id'] ?? $_SESSION['client_company_id'] ?? 0);

// ── Load token from DB ───────────────────────────────────────────────
$tok = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT access_token, refresh_token, channel_id, channel_name, token_expiry, updated_at
     FROM hdb_oauth_tokens
     WHERE admin_id = $admin_id AND company_id = $company_id AND platform = 'facebook'
     LIMIT 1"
));

$connected    = !empty($tok);
$token_ok     = $connected && strtotime($tok['token_expiry']) > time();
$token_expiry = $tok['token_expiry'] ?? '';
$page_name    = $tok['channel_name'] ?? '';
$page_id      = $tok['channel_id']   ?? '';

// Pages stored in session (all pages from OAuth)
$all_pages    = $_SESSION['fb_pages'] ?? [];

// ── Handle page switch ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_page'])) {
    $new_page_id    = mysqli_real_escape_string($conn, $_POST['page_id']    ?? '');
    $new_page_name  = mysqli_real_escape_string($conn, $_POST['page_name']  ?? '');
    $new_page_token = mysqli_real_escape_string($conn, $_POST['page_token'] ?? '');
    $now            = date('Y-m-d H:i:s');

    if ($new_page_id && $new_page_token) {
        mysqli_query($conn,
            "UPDATE hdb_oauth_tokens
             SET channel_id = '$new_page_id', channel_name = '$new_page_name',
                 refresh_token = '$new_page_token', updated_at = '$now'
             WHERE admin_id = $admin_id AND company_id = $company_id AND platform = 'facebook'"
        );
        header('Location: facebook_connected.php?switched=1');
        exit;
    }
}

// ── Handle disconnect ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
    mysqli_query($conn,
        "DELETE FROM hdb_oauth_tokens WHERE admin_id=$admin_id AND company_id=$company_id AND platform='facebook'"
    );
    unset($_SESSION['fb_pages'], $_SESSION['fb_token_expiry']);
    header('Location: facebook_connected.php?disconnected=1');
    exit;
}

$just_connected   = isset($_GET['connected']);
$just_switched    = isset($_GET['switched']);
$just_disconnected= isset($_GET['disconnected']);

// ── Recent posts ─────────────────────────────────────────────────────
$recent = [];
$rq = mysqli_query($conn,
    "SELECT id, title, facebook_status, facebook_post_id, schedule_date, schedule_time
     FROM hdb_podcasts
     WHERE admin_id = $admin_id AND facebook_status IS NOT NULL AND facebook_status != ''
     ORDER BY id DESC LIMIT 8"
);
while ($r = mysqli_fetch_assoc($rq)) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facebook — VideoVizard</title>
<link rel="stylesheet" href="assets/css/main.css"> <!-- your existing CSS -->
<style>
  .fb-wrap { max-width: 700px; margin: 32px auto; padding: 0 20px 60px; }
  .fb-card { background: var(--card-bg, #fff); border: 1px solid var(--border, #e0e0e8);
             border-radius: 12px; padding: 24px 28px; margin-bottom: 20px; }
  .fb-card h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; }
  .badge { display:inline-flex;align-items:center;gap:5px;padding:3px 11px;
           border-radius:20px;font-size:12px;font-weight:500; }
  .badge.ok   { background:#e6f9f0;color:#1a7a4a; }
  .badge.bad  { background:#fdecea;color:#b71c1c; }
  .badge.warn { background:#fff8e1;color:#7a5000; }
  .btn { display:inline-flex;align-items:center;gap:6px;padding:9px 18px;
         border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;
         text-decoration:none;transition:opacity .15s; }
  .btn:hover{opacity:.85}
  .btn-fb   { background:#1877f2;color:#fff; }
  .btn-red  { background:#e53935;color:#fff; }
  .btn-gray { background:#e8e8f0;color:#333; }
  .info-row { display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px; }
  table.posts { width:100%;border-collapse:collapse;font-size:13px; }
  table.posts th { text-align:left;font-weight:500;color:#666;padding:6px 8px;
                   border-bottom:1px solid #eee; }
  table.posts td { padding:7px 8px;border-bottom:1px solid #f4f4f8;vertical-align:middle; }
  .status-pill { padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600; }
  .status-posted  { background:#e6f9f0;color:#1a7a4a; }
  .status-failed  { background:#fdecea;color:#b71c1c; }
  .status-posting { background:#fff8e1;color:#7a5000; }
  .status-pending { background:#f0f0f8;color:#555; }
  .page-row { display:flex;align-items:center;gap:10px;padding:10px 0;
              border-bottom:1px solid #f0f0f4; }
  .page-row:last-child{border-bottom:none}
  .page-dot{width:9px;height:9px;border-radius:50%;background:#ccc;flex-shrink:0}
  .page-dot.active{background:#1877f2}
  .banner{background:#e6f9f0;border:1px solid #a8e6c6;border-radius:8px;
          padding:11px 16px;margin-bottom:18px;font-size:13px;color:#1a7a4a;}
</style>
</head>
<body>
<?php
// Include your standard nav/header here if you have one
// include 'header.php';
?>
<div class="fb-wrap">

  <h1 style="font-size:20px;font-weight:600;margin-bottom:6px">Facebook</h1>
  <p style="font-size:13px;color:#666;margin-bottom:24px">
    Connect your Facebook Page to post videos automatically.
  </p>

  <?php if ($just_connected):?>
  <div class="banner">✓ Facebook connected successfully!</div>
  <?php elseif($just_switched):?>
  <div class="banner">✓ Active page updated.</div>
  <?php elseif($just_disconnected):?>
  <div class="banner" style="background:#fdecea;border-color:#f8baba;color:#b71c1c">
    Facebook disconnected.
  </div>
  <?php endif;?>

  <!-- ── Status card ──────────────────────────────────────── -->
  <div class="fb-card">
    <h2>Connection</h2>
    <div class="info-row">
      <?php if ($connected && $token_ok):?>
        <span class="badge ok">● Connected</span>
        <span style="font-size:13px"><strong><?= htmlspecialchars($page_name) ?></strong></span>
        <span style="font-size:12px;color:#aaa">Token expires <?= date('M j, Y', strtotime($token_expiry)) ?></span>
      <?php elseif ($connected && !$token_ok):?>
        <span class="badge bad">● Token Expired</span>
        <span style="font-size:13px;color:#c0392b">Reconnect to resume posting</span>
      <?php else:?>
        <span class="badge bad">● Not Connected</span>
      <?php endif;?>
    </div>

    <?php if (!$connected || !$token_ok):?>
      <a href="facebook_connect.php" class="btn btn-fb">
        Connect Facebook Page
      </a>
    <?php else:?>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="facebook_connect.php" class="btn btn-gray">Reconnect / Switch Account</a>
        <form method="post" style="margin:0" onsubmit="return confirm('Disconnect Facebook?')">
          <button name="disconnect" value="1" class="btn btn-red">Disconnect</button>
        </form>
      </div>
    <?php endif;?>
  </div>

  <?php if ($connected && $token_ok && !empty($all_pages)):?>
  <!-- ── Page selector ────────────────────────────────────── -->
  <div class="fb-card">
    <h2>Your Pages (<?= count($all_pages) ?>)</h2>
    <?php foreach ($all_pages as $pg):
      $is_active = ($pg['id'] === $page_id); ?>
    <div class="page-row">
      <div class="page-dot <?= $is_active ? 'active' : '' ?>"></div>
      <span style="flex:1;font-size:14px"><?= htmlspecialchars($pg['name']) ?></span>
      <span style="font-size:12px;color:#aaa">ID: <?= htmlspecialchars($pg['id']) ?></span>
      <?php if (!$is_active):?>
      <form method="post" style="margin:0">
        <input type="hidden" name="switch_page"  value="1">
        <input type="hidden" name="page_id"      value="<?= htmlspecialchars($pg['id']) ?>">
        <input type="hidden" name="page_name"    value="<?= htmlspecialchars($pg['name']) ?>">
        <input type="hidden" name="page_token"   value="<?= htmlspecialchars($pg['access_token']) ?>">
        <button type="submit" class="btn btn-gray" style="padding:5px 12px;font-size:12px">Use this</button>
      </form>
      <?php else:?>
        <span class="badge ok" style="font-size:11px">Active</span>
      <?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <!-- ── Recent posts ─────────────────────────────────────── -->
  <?php if (!empty($recent)):?>
  <div class="fb-card">
    <h2>Recent Facebook Posts</h2>
    <table class="posts">
      <tr>
        <th>Video</th>
        <th>Status</th>
        <th>Post ID</th>
      </tr>
      <?php foreach ($recent as $p):
        $status = $p['facebook_status'] ?? 'pending';
        $cls = match($status) {
          'posted'  => 'status-posted',
          'failed'  => 'status-failed',
          'posting' => 'status-posting',
          default   => 'status-pending',
        };
        ?>
      <tr>
        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= htmlspecialchars($p['title'] ?? "#" . $p['id']) ?>
        </td>
        <td><span class="status-pill <?= $cls ?>"><?= htmlspecialchars($status) ?></span></td>
        <td style="font-size:11px;color:#aaa"><?= htmlspecialchars($p['facebook_post_id'] ?? '—') ?></td>
      </tr>
      <?php endforeach;?>
    </table>
  </div>
  <?php endif;?>

</div>
</body>
</html>

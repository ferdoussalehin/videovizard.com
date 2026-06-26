<?php
/**
 * fb_select_pages.php
 * Page picker shown after Facebook OAuth when the account manages more than one
 * Page. The user ticks the pages they want to connect (or "Select all"); the
 * chosen pages are written to hdb_oauth_tokens via fb_save_pages.php and the
 * user is sent back to the scheduler.
 *
 * Reached from meta/fb_callback_vizard.php, which leaves the available pages in
 * $_SESSION['fb_pending'].
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$pending  = $_SESSION['fb_pending'] ?? null;

if (!$admin_id || !$pending || empty($pending['pages'])) {
    header('Location: ../vizard_scheduler.php?fb_error=' . urlencode('session_expired_reconnect'));
    exit;
}

$pages      = $pending['pages'];
$company_id = (int)$pending['company_id'];
$expiry     = (string)$pending['expiry'];
$returnUrl  = (string)$pending['return'];

include __DIR__ . '/../dbconnect_hdb.php';   // provides $conn

// Pages already connected for this workspace — used to pre-tick the boxes.
$connectedIds = [];
$cq = mysqli_query($conn,
    "SELECT channel_id FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND company_id=$company_id AND platform LIKE 'facebook_page_%'");
if ($cq) while ($cr = mysqli_fetch_assoc($cq)) $connectedIds[] = (string)$cr['channel_id'];
$firstTime = empty($connectedIds);   // first connect → default to all pages ticked

// ── Save selection ───────────────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIds = $_POST['page_ids'] ?? [];
    if (!is_array($selectedIds)) $selectedIds = [];
    $selectedIds = array_map('strval', $selectedIds);

    $selected = array_values(array_filter($pages, function ($p) use ($selectedIds) {
        return in_array((string)($p['id'] ?? ''), $selectedIds, true);
    }));

    if (empty($selected)) {
        $error = 'Please select at least one page.';
    } else {
        require_once __DIR__ . '/fb_save_pages.php';
        fb_save_selected_pages($conn, $admin_id, $company_id, $selected, $expiry);
        unset($_SESSION['fb_pending'], $_SESSION['fb_oauth_return'], $_SESSION['oauth_company_id']);
        header('Location: ' . $returnUrl);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose Facebook Pages — VideoVizard</title>
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; background:#f4f5f9; margin:0; color:#1a1a2e; }
  .wrap { max-width:560px; margin:48px auto; padding:0 18px; }
  .card { background:#fff; border:1px solid #e4e4ee; border-radius:14px; padding:26px 28px; box-shadow:0 6px 24px rgba(0,0,0,.05); }
  h1 { font-size:19px; margin:0 0 6px; }
  p.sub { font-size:13px; color:#666; margin:0 0 20px; }
  .err { background:#fdecea; color:#b71c1c; border:1px solid #f8baba; border-radius:8px; padding:9px 13px; font-size:13px; margin-bottom:16px; }
  .row { display:flex; align-items:center; gap:11px; padding:12px 4px; border-bottom:1px solid #f1f1f6; }
  .row:last-of-type { border-bottom:none; }
  .row input { width:18px; height:18px; accent-color:#1877f2; cursor:pointer; }
  .row label { flex:1; font-size:14px; cursor:pointer; }
  .row .pid { font-size:11px; color:#aaa; }
  .selall { display:flex; align-items:center; gap:9px; padding:8px 4px 14px; font-size:13px; font-weight:600; color:#1877f2; }
  .selall input { width:17px; height:17px; accent-color:#1877f2; cursor:pointer; }
  .btn { display:inline-block; width:100%; margin-top:20px; padding:13px; border:none; border-radius:9px;
         background:#1877f2; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
  .btn:hover { opacity:.9; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Choose your Facebook Pages</h1>
    <p class="sub">Select the page(s) you want to publish to. You can post to any of them later.</p>

    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
      <div class="selall">
        <input type="checkbox" id="selAll" onclick="document.querySelectorAll('.pg-chk').forEach(c=>c.checked=this.checked)">
        <label for="selAll" style="cursor:pointer">Select all pages</label>
      </div>

      <?php foreach ($pages as $pg):
        $pid     = (string)($pg['id'] ?? '');
        $pname   = (string)($pg['name'] ?? $pid);
        $checked = $firstTime || in_array($pid, $connectedIds, true);
      ?>
      <div class="row">
        <input type="checkbox" class="pg-chk" id="pg-<?= htmlspecialchars($pid) ?>"
               name="page_ids[]" value="<?= htmlspecialchars($pid) ?>" <?= $checked ? 'checked' : '' ?>>
        <label for="pg-<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($pname) ?></label>
        <span class="pid">ID: <?= htmlspecialchars($pid) ?></span>
      </div>
      <?php endforeach; ?>

      <button type="submit" class="btn">Connect Selected Pages</button>
    </form>
  </div>
</div>
</body>
</html>

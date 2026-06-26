<?php
// ── Auth guard ────────────────────────────────────────────────
session_start();
if (!isset($_SESSION['cts_user_id'])) {
    header('Location: ../login.php'); exit;
}
if (!in_array($_SESSION['cts_role'] ?? '', ['super_admin','admin'])) {
    header('Location: ../login.php?error=no_permission'); exit;
}

require_once __DIR__ . '/../dbconnect.php';

$admin_id   = (int)$_SESSION['cts_user_id'];
$admin_name = $_SESSION['cts_firstname'] . ' ' . $_SESSION['cts_lastname'];
$admin_init = strtoupper(substr($_SESSION['cts_firstname'], 0, 1));

$errors  = [];
$success = '';
$saved   = null; // holds saved data on error so form re-populates

// ── Plan → minutes map ────────────────────────────────────────
$plan_minutes = ['starter' => 200, 'growth' => 800, 'agency' => 9999];
$plan_prices  = ['starter' => 297, 'growth' => 597, 'agency' => 997];

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & sanitise
    $fn       = trim($_POST['firstname']    ?? '');
    $ln       = trim($_POST['lastname']     ?? '');
    $co       = trim($_POST['company_name'] ?? '');
    $em       = trim($_POST['email']        ?? '');
    $ph       = trim($_POST['phone']        ?? '');
    $country  = trim($_POST['country']      ?? '');
    $tz       = trim($_POST['timezone']     ?? 'UTC');
    $plan     = in_array($_POST['plan_type'] ?? '', ['starter','growth','agency'])
                ? $_POST['plan_type'] : 'starter';
    $pw       = trim($_POST['password']     ?? '');
    $pw2      = trim($_POST['password2']    ?? '');
    $niche    = in_array($_POST['niche'] ?? '', ['real_estate','financial','general'])
                ? $_POST['niche'] : 'general';
    $notes    = trim($_POST['notes']        ?? '');
    $overage  = floatval($_POST['overage_rate'] ?? 0.10);
    $credits  = floatval($_POST['credit_balance'] ?? 0);

    // Validation
    if (empty($fn))  $errors[] = 'First name is required.';
    if (empty($ln))  $errors[] = 'Last name is required.';
    if (empty($co))  $errors[] = 'Company name is required.';
    if (empty($em))  $errors[] = 'Email address is required.';
    elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if (empty($pw))  $errors[] = 'Password is required.';
    elseif ($pw !== $pw2) $errors[] = 'Passwords do not match.';
    elseif (strlen($pw) < 4) $errors[] = 'Password must be at least 4 characters.';

    // Email uniqueness
    if (empty($errors)) {
        $safe_em = mysqli_real_escape_string($conn, $em);
        $exists  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM cts_users WHERE email_id='$safe_em' LIMIT 1"));
        if ($exists) $errors[] = 'An account with this email already exists.';
    }

    // Save
    if (empty($errors)) {
        $fn_e  = mysqli_real_escape_string($conn, $fn);
        $ln_e  = mysqli_real_escape_string($conn, $ln);
        $co_e  = mysqli_real_escape_string($conn, $co);
        $em_e  = mysqli_real_escape_string($conn, $em);
        $ph_e  = mysqli_real_escape_string($conn, $ph);
        $ct_e  = mysqli_real_escape_string($conn, $country);
        $tz_e  = mysqli_real_escape_string($conn, $tz);
        $pw_e  = mysqli_real_escape_string($conn, $pw);
        $ni_e  = mysqli_real_escape_string($conn, $niche);
        $no_e  = mysqli_real_escape_string($conn, $notes);
        $mins  = $plan_minutes[$plan];
        $fwd   = 'dashboard.php';

        // Auto-generate username: firstname + lastname + random 2 digits
        $uname_base = strtolower(preg_replace('/[^a-z0-9]/i', '', $fn . $ln));
        $uname      = $uname_base . rand(10, 99);
        // Ensure unique
        $uc = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM cts_users WHERE user_name='$uname' LIMIT 1"));
        if ($uc) $uname = $uname_base . rand(100, 999);
        $uname_e = mysqli_real_escape_string($conn, $uname);

        mysqli_query($conn, "START TRANSACTION");
        try {
            // Insert cts_users
            mysqli_query($conn,
                "INSERT INTO cts_users
                    (user_name, firstname, lastname, password, level_name,
                     email_id, phone_number, country, company_name, plan_type,
                     created_at, updated_at, schedule_flag, max_videos_allowed,
                     trial_period_expiry_dt, team_lead_id, role, credit_balance, forward_to)
                 VALUES
                    ('$uname_e','$fn_e','$ln_e','$pw_e','client',
                     '$em_e','$ph_e','$ct_e','$co_e','$plan',
                     NOW(),NOW(),0,100,'2099-12-31',0,'client',0,'$fwd')");
            $uid = mysqli_insert_id($conn);

            // Insert cts_clients
            mysqli_query($conn,
                "INSERT INTO cts_clients
                    (user_id, company_name, contact_firstname, contact_lastname,
                     email, phone, country, timezone, plan_type, status,
                     monthly_minutes, overage_rate, credit_balance, notes, added_by, created_at)
                 VALUES
                    ($uid,'$co_e','$fn_e','$ln_e',
                     '$em_e','$ph_e','$ct_e','$tz_e','$plan','active',
                     $mins,$overage,$credits,'$no_e',$admin_id,NOW())");
            $cid = mysqli_insert_id($conn);

            // Link client_id back to user
            mysqli_query($conn,"UPDATE cts_users SET client_id=$cid WHERE id=$uid");

            // Audit log
            mysqli_query($conn,
                "INSERT INTO cts_audit_log
                    (actor_id, actor_role, client_id, action, entity_type, entity_id, ip_address)
                 VALUES
                    ($admin_id,'{$_SESSION['cts_role']}',$cid,'client_added','client',$cid,
                     '" . mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']) . "')");

            mysqli_query($conn, "COMMIT");

            $success = "Client <strong>" . htmlspecialchars($co) . "</strong> added successfully! "
                     . "Login username: <strong>" . htmlspecialchars($uname) . "</strong>";

            // Clear form
            $fn=$ln=$co=$em=$ph=$country=$pw=$pw2=$notes='';
            $plan='starter'; $tz='UTC'; $niche='general';
            $overage=0.10; $credits=0;

        } catch (Exception $e) {
            mysqli_query($conn, "ROLLBACK");
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // Re-populate on error
    if (!empty($errors)) {
        $saved = $_POST;
    }
}

// ── Helper: field value ───────────────────────────────────────
function fv($key, $default = '') {
    global $saved;
    return htmlspecialchars($saved[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Client — CallMind AI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --ink:       #0a0e1a;
  --ink-soft:  #3d4460;
  --ink-mute:  #7a8099;
  --bg:        #f0f2f7;
  --sidebar-bg:#0a0e1a;
  --card:      #ffffff;
  --teal:      #1a7a6e;
  --teal-lt:   #22a090;
  --teal-pale: #d4efec;
  --gold:      #c8973a;
  --gold-pale: rgba(200,151,58,.1);
  --red:       #dc2626;
  --red-pale:  #fef2f2;
  --green:     #16a34a;
  --green-pale:#f0fdf4;
  --border:    rgba(10,14,26,.08);
  --shadow:    0 1px 4px rgba(10,14,26,.07);
  --shadow-md: 0 4px 20px rgba(10,14,26,.1);
  --radius:    14px;
  --ff-display:'Fraunces', Georgia, serif;
  --ff-body:   'DM Sans', sans-serif;
  --sidebar-w: 240px;
}
html { height: 100%; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; }

/* ── Sidebar (same as dashboard) ─────────────────────────── */
.sidebar { width: var(--sidebar-w); background: var(--sidebar-bg); min-height: 100vh; position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; z-index: 500; }
.sb-logo { padding: 24px 20px 20px; display: flex; align-items: center; gap: 10px; text-decoration: none; border-bottom: 1px solid rgba(255,255,255,.06); }
.sb-logo-mark { width: 34px; height: 34px; border-radius: 9px; background: var(--teal); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sb-logo-mark svg { width: 18px; height: 18px; }
.sb-logo-name { font-family: var(--ff-display); font-size: 17px; font-weight: 700; color: #fff; letter-spacing: -.02em; }
.sb-logo-name em { color: #22a090; font-style: normal; }
.sb-section { padding: 20px 12px 8px; }
.sb-section-label { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.25); padding: 0 8px; margin-bottom: 6px; }
.sb-nav { list-style: none; display: flex; flex-direction: column; gap: 2px; }
.sb-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: rgba(255,255,255,.5); text-decoration: none; transition: all .15s; }
.sb-nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
.sb-nav a.active { background: var(--teal); color: #fff; font-weight: 600; }
.sb-nav a .ico { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.sb-bottom { margin-top: auto; padding: 16px 12px; border-top: 1px solid rgba(255,255,255,.06); }
.sb-user { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; }
.sb-avatar { width: 32px; height: 32px; border-radius: 8px; background: var(--teal); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
.sb-user-name { font-size: 13px; font-weight: 600; color: #fff; }
.sb-user-role { font-size: 11px; color: rgba(255,255,255,.35); }
.sb-logout { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 10px; font-size: 13px; color: rgba(255,255,255,.35); text-decoration: none; margin-top: 4px; transition: all .15s; }
.sb-logout:hover { background: rgba(220,38,38,.15); color: #f87171; }

/* ── Main ─────────────────────────────────────────────────── */
.main { margin-left: var(--sidebar-w); flex: 1; min-width: 0; display: flex; flex-direction: column; }

/* ── Topbar ───────────────────────────────────────────────── */
.topbar { background: var(--card); border-bottom: 1px solid var(--border); padding: 0 28px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 400; }
.topbar-left { display: flex; align-items: center; gap: 10px; }
.topbar-back { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-mute); text-decoration: none; padding: 6px 12px; border-radius: 8px; border: 1.5px solid var(--border); transition: all .15s; }
.topbar-back:hover { border-color: var(--ink); color: var(--ink); }
.topbar-title { font-family: var(--ff-display); font-size: 18px; font-weight: 700; color: var(--ink); letter-spacing: -.02em; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.btn-save { display: flex; align-items: center; gap: 6px; padding: 9px 20px; background: var(--teal); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: var(--ff-body); transition: all .15s; }
.btn-save:hover { background: var(--teal-lt); transform: translateY(-1px); }
.btn-save:disabled { opacity: .55; cursor: not-allowed; transform: none; }
.btn-cancel-top { padding: 9px 18px; border-radius: 10px; border: 1.5px solid var(--border); background: #fff; color: var(--ink-soft); font-size: 13px; font-weight: 600; cursor: pointer; font-family: var(--ff-body); text-decoration: none; display: inline-flex; align-items: center; transition: all .15s; }
.btn-cancel-top:hover { border-color: var(--ink); color: var(--ink); }

/* ── Page ─────────────────────────────────────────────────── */
.page { padding: 28px; flex: 1; }

/* ── Alerts ───────────────────────────────────────────────── */
.alert { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-radius: 12px; font-size: 14px; line-height: 1.6; margin-bottom: 24px; animation: alertIn .3s ease; }
@keyframes alertIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.alert-err { background: var(--red-pale); color: var(--red); border: 1px solid #fecaca; }
.alert-ok  { background: var(--green-pale); color: var(--green); border: 1px solid #86efac; }
.alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.alert ul { margin: 6px 0 0 18px; }
.alert ul li { margin-bottom: 3px; font-size: 13px; }

/* ── Two-column layout ────────────────────────────────────── */
.form-layout { display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }

/* ── Form panels ──────────────────────────────────────────── */
.form-panel {
  background: var(--card);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  overflow: hidden;
  margin-bottom: 20px;
}
.fp-head {
  padding: 18px 24px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.fp-head-icon { font-size: 18px; }
.fp-head-title { font-size: 14px; font-weight: 700; color: var(--ink); }
.fp-head-sub { font-size: 12px; color: var(--ink-mute); margin-top: 2px; }
.fp-body { padding: 24px; }

/* ── Form elements ────────────────────────────────────────── */
.fg { margin-bottom: 18px; }
.fg:last-child { margin-bottom: 0; }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.fg-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

.fg label {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; font-weight: 600; color: var(--ink);
  letter-spacing: .03em; margin-bottom: 6px;
}
.req { color: var(--red); }
.opt-tag { font-size: 10px; font-weight: 500; color: var(--ink-mute); background: #f1f5f9; padding: 1px 6px; border-radius: 4px; }

.fg input[type="text"],
.fg input[type="email"],
.fg input[type="tel"],
.fg input[type="password"],
.fg input[type="number"],
.fg select,
.fg textarea {
  width: 100%;
  padding: 10px 13px;
  background: var(--bg);
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-size: 13px;
  font-family: var(--ff-body);
  color: var(--ink);
  outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.fg input:focus,
.fg select:focus,
.fg textarea:focus {
  border-color: var(--teal);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(26,122,110,.08);
}
.fg textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
.fg select { cursor: pointer; }
.fg-hint { font-size: 11px; color: var(--ink-mute); margin-top: 5px; line-height: 1.4; }

/* pw wrap */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 40px; }
.pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 14px; color: var(--ink-mute); padding: 4px; line-height: 1; transition: color .15s; }
.pw-eye:hover { color: var(--ink); }

/* ── Plan cards ───────────────────────────────────────────── */
.plan-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; }
.plan-card {
  border: 2px solid var(--border);
  border-radius: 12px; padding: 16px;
  cursor: pointer; transition: all .2s;
  position: relative;
}
.plan-card:hover { border-color: var(--teal); background: rgba(26,122,110,.03); }
.plan-card.selected { border-color: var(--teal); background: var(--teal-pale); }
.plan-card input[type="radio"] { position: absolute; opacity: 0; }
.plan-name { font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
.plan-price { font-family: var(--ff-display); font-size: 22px; font-weight: 700; color: var(--teal); line-height: 1; }
.plan-price span { font-size: 12px; font-weight: 400; color: var(--ink-mute); }
.plan-mins { font-size: 11px; color: var(--ink-mute); margin-top: 6px; }
.plan-check { position: absolute; top: 10px; right: 10px; width: 18px; height: 18px; border-radius: 50%; background: var(--teal); color: #fff; display: none; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; }
.plan-card.selected .plan-check { display: flex; }

/* ── Niche selector ───────────────────────────────────────── */
.niche-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.niche-card {
  border: 2px solid var(--border);
  border-radius: 12px; padding: 14px 12px;
  cursor: pointer; text-align: center; transition: all .2s;
  position: relative;
}
.niche-card input[type="radio"] { position: absolute; opacity: 0; }
.niche-card:hover { border-color: var(--teal); }
.niche-card.selected { border-color: var(--teal); background: var(--teal-pale); }
.niche-icon { font-size: 24px; margin-bottom: 6px; }
.niche-label { font-size: 12px; font-weight: 600; color: var(--ink); }

/* ── Right sidebar panels ─────────────────────────────────── */
.side-panel {
  background: var(--card);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  overflow: hidden;
  margin-bottom: 16px;
}
.sp-head { padding: 14px 18px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px; }
.sp-body { padding: 16px 18px; }

/* summary rows */
.sum-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.sum-row:last-child { border-bottom: none; }
.sum-label { color: var(--ink-mute); }
.sum-value { font-weight: 600; color: var(--ink); }
.sum-value.teal { color: var(--teal); }
.sum-value.gold { color: var(--gold); }

/* checklist */
.checklist { display: flex; flex-direction: column; gap: 8px; }
.checklist-item { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: var(--ink-soft); }
.checklist-item .ci-icon { width: 18px; height: 18px; border-radius: 50%; background: var(--teal-pale); color: var(--teal); display: flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }

/* ── Divider ──────────────────────────────────────────────── */
.section-divider { display: flex; align-items: center; gap: 12px; margin: 4px 0 20px; }
.section-divider span { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-mute); white-space: nowrap; }
.section-divider::before, .section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ── Toast ────────────────────────────────────────────────── */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 11px 22px; border-radius: 12px; font-size: 13px; font-weight: 600; z-index: 9999; opacity: 0; transition: opacity .3s; pointer-events: none; white-space: nowrap; }

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 1100px) { .form-layout { grid-template-columns: 1fr; } }
@media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } .fg-row,.fg-row-3 { grid-template-columns: 1fr; } .plan-grid,.niche-grid { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar">
  <a href="../index.php" class="sb-logo">
    <div class="sb-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
        <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
      </svg>
    </div>
    <span class="sb-logo-name">Call<em>Mind</em> AI</span>
  </a>
  <div class="sb-section">
    <div class="sb-section-label">Main</div>
    <ul class="sb-nav">
      <li><a href="dashboard.php"><span class="ico">📊</span> Dashboard</a></li>
      <li><a href="clients.php" class="active"><span class="ico">👥</span> Clients</a></li>
      <li><a href="campaigns.php"><span class="ico">📣</span> Campaigns</a></li>
      <li><a href="call_logs.php"><span class="ico">📞</span> Call Logs</a></li>
      <li><a href="appointments.php"><span class="ico">📅</span> Appointments</a></li>
    </ul>
  </div>
  <div class="sb-section">
    <div class="sb-section-label">Configure</div>
    <ul class="sb-nav">
      <li><a href="agents.php"><span class="ico">🤖</span> AI Agents</a></li>
      <li><a href="scripts.php"><span class="ico">📝</span> Scripts</a></li>
      <li><a href="billing.php"><span class="ico">💳</span> Billing</a></li>
    </ul>
  </div>
  <div class="sb-section">
    <div class="sb-section-label">System</div>
    <ul class="sb-nav">
      <li><a href="audit_log.php"><span class="ico">🔍</span> Audit Log</a></li>
      <li><a href="settings.php"><span class="ico">⚙️</span> Settings</a></li>
    </ul>
  </div>
  <div class="sb-bottom">
    <div class="sb-user">
      <div class="sb-avatar"><?= htmlspecialchars($admin_init) ?></div>
      <div>
        <div class="sb-user-name"><?= htmlspecialchars($admin_name) ?></div>
        <div class="sb-user-role">Super Admin</div>
      </div>
    </div>
    <a href="../logout.php" class="sb-logout"><span>🚪</span> Log Out</a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <a href="clients.php" class="topbar-back">← Clients</a>
      <span class="topbar-title">Add New Client</span>
    </div>
    <div class="topbar-right">
      <a href="clients.php" class="btn-cancel-top">Cancel</a>
      <button class="btn-save" onclick="submitForm()" id="saveBtn">
        ✓ Save Client
      </button>
    </div>
  </div>

  <div class="page">

    <!-- Alerts -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-err">
      <span class="alert-icon">⚠</span>
      <div>
        <strong>Please fix the following:</strong>
        <ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-ok">
      <span class="alert-icon">✓</span>
      <div><?= $success ?> &nbsp;—&nbsp; <a href="clients.php" style="color:var(--green);font-weight:700;">View all clients</a> or add another below.</div>
    </div>
    <?php endif; ?>

    <form method="POST" action="add_client.php" id="clientForm">
    <div class="form-layout">

      <!-- ── LEFT: Main form ────────────────────────────── -->
      <div>

        <!-- Contact info -->
        <div class="form-panel">
          <div class="fp-head">
            <span class="fp-head-icon">👤</span>
            <div>
              <div class="fp-head-title">Contact Information</div>
              <div class="fp-head-sub">Primary contact person for this client account</div>
            </div>
          </div>
          <div class="fp-body">
            <div class="fg-row">
              <div class="fg">
                <label>First Name <span class="req">*</span></label>
                <input type="text" name="firstname" placeholder="James" value="<?= fv('firstname') ?>" required>
              </div>
              <div class="fg">
                <label>Last Name <span class="req">*</span></label>
                <input type="text" name="lastname" placeholder="Wilson" value="<?= fv('lastname') ?>" required>
              </div>
            </div>
            <div class="fg">
              <label>Company / Agency Name <span class="req">*</span></label>
              <input type="text" name="company_name" placeholder="e.g. Keller Williams Dallas" value="<?= fv('company_name') ?>" required>
            </div>
            <div class="fg-row">
              <div class="fg">
                <label>Email Address <span class="req">*</span></label>
                <input type="email" name="email" placeholder="james@kwdallas.com" value="<?= fv('email') ?>" required>
              </div>
              <div class="fg">
                <label>Phone Number <span class="opt-tag">optional</span></label>
                <input type="tel" name="phone" placeholder="+1 214 555 0101" value="<?= fv('phone') ?>">
              </div>
            </div>
            <div class="fg-row">
              <div class="fg">
                <label>Country <span class="opt-tag">optional</span></label>
                <select name="country">
                  <option value="">— Select country —</option>
                  <?php
                  $countries = ['US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom',
                                'AU'=>'Australia','NZ'=>'New Zealand','ZA'=>'South Africa',
                                'IN'=>'India','AE'=>'UAE','SG'=>'Singapore'];
                  foreach ($countries as $code => $name):
                    $sel = (fv('country') === $code) ? 'selected' : '';
                  ?>
                  <option value="<?= $code ?>" <?= $sel ?>><?= $name ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="fg">
                <label>Timezone</label>
                <select name="timezone">
                  <?php
                  $tzones = [
                    'America/New_York'    => 'Eastern (ET)',
                    'America/Chicago'     => 'Central (CT)',
                    'America/Denver'      => 'Mountain (MT)',
                    'America/Los_Angeles' => 'Pacific (PT)',
                    'America/Toronto'     => 'Toronto',
                    'America/Vancouver'   => 'Vancouver',
                    'Europe/London'       => 'London (GMT)',
                    'Australia/Sydney'    => 'Sydney (AEST)',
                    'Asia/Dubai'          => 'Dubai (GST)',
                    'UTC'                 => 'UTC',
                  ];
                  foreach ($tzones as $val => $lbl):
                    $sel = ((fv('timezone','America/Chicago')) === $val) ? 'selected' : '';
                  ?>
                  <option value="<?= $val ?>" <?= $sel ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Login credentials -->
        <div class="form-panel">
          <div class="fp-head">
            <span class="fp-head-icon">🔑</span>
            <div>
              <div class="fp-head-title">Login Credentials</div>
              <div class="fp-head-sub">Client uses these to log into their dashboard</div>
            </div>
          </div>
          <div class="fp-body">
            <div class="alert" style="background:#fffbeb;border:1px solid #fbbf24;color:#92400e;padding:10px 14px;border-radius:10px;font-size:12px;margin-bottom:16px;display:flex;gap:8px;align-items:flex-start;">
              <span>💡</span>
              <span>Username is auto-generated from the client's name. Share the password with your client — they can change it after logging in.</span>
            </div>
            <div class="fg-row">
              <div class="fg">
                <label>Password <span class="req">*</span></label>
                <div class="pw-wrap">
                  <input type="password" name="password" id="pw1" placeholder="Set a password" value="<?= fv('password') ?>" required>
                  <button type="button" class="pw-eye" onclick="togglePw('pw1',this)">👁</button>
                </div>
              </div>
              <div class="fg">
                <label>Confirm Password <span class="req">*</span></label>
                <div class="pw-wrap">
                  <input type="password" name="password2" id="pw2" placeholder="Repeat password" value="<?= fv('password2') ?>" required>
                  <button type="button" class="pw-eye" onclick="togglePw('pw2',this)">👁</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Plan selection -->
        <div class="form-panel">
          <div class="fp-head">
            <span class="fp-head-icon">📦</span>
            <div>
              <div class="fp-head-title">Subscription Plan</div>
              <div class="fp-head-sub">Determines included minutes and feature limits</div>
            </div>
          </div>
          <div class="fp-body">
            <div class="plan-grid" id="planGrid">
              <?php
              $plans = [
                'starter' => ['label'=>'Starter','price'=>297,'mins'=>200,'desc'=>'Perfect for solo agents'],
                'growth'  => ['label'=>'Growth', 'price'=>597,'mins'=>800,'desc'=>'For active teams'],
                'agency'  => ['label'=>'Agency', 'price'=>997,'mins'=>9999,'desc'=>'Unlimited everything'],
              ];
              $sel_plan = fv('plan_type','growth');
              foreach ($plans as $key => $p):
                $sel = ($sel_plan === $key) ? 'selected' : '';
              ?>
              <div class="plan-card <?= $sel ?>" onclick="selectPlan('<?= $key ?>')" id="plan-<?= $key ?>">
                <input type="radio" name="plan_type" value="<?= $key ?>" <?= $sel ? 'checked' : '' ?>>
                <div class="plan-name"><?= $p['label'] ?></div>
                <div class="plan-price">$<?= $p['price'] ?><span>/mo</span></div>
                <div class="plan-mins"><?= $p['mins'] == 9999 ? 'Unlimited' : number_format($p['mins']) ?> mins included</div>
                <div class="plan-mins" style="margin-top:3px;color:var(--ink-soft);"><?= $p['desc'] ?></div>
                <div class="plan-check">✓</div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="section-divider" style="margin-top:20px;"><span>Billing details</span></div>

            <div class="fg-row">
              <div class="fg">
                <label>Overage Rate (per minute) <span class="opt-tag">optional</span></label>
                <input type="number" name="overage_rate" step="0.01" min="0" max="1"
                       placeholder="0.10" value="<?= fv('overage_rate','0.10') ?>">
                <div class="fg-hint">Charged per minute when plan limit is exceeded.</div>
              </div>
              <div class="fg">
                <label>Opening Credit Balance <span class="opt-tag">optional</span></label>
                <input type="number" name="credit_balance" step="0.01" min="0"
                       placeholder="0.00" value="<?= fv('credit_balance','0') ?>">
                <div class="fg-hint">Pre-loaded credit in dollars for this account.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Niche & notes -->
        <div class="form-panel">
          <div class="fp-head">
            <span class="fp-head-icon">🎯</span>
            <div>
              <div class="fp-head-title">Industry & Notes</div>
              <div class="fp-head-sub">Helps pre-select relevant script templates</div>
            </div>
          </div>
          <div class="fp-body">
            <div class="fg" style="margin-bottom:20px;">
              <label>Industry / Niche</label>
              <div class="niche-grid" id="nicheGrid">
                <?php
                $niches = [
                  'real_estate' => ['icon'=>'🏠','label'=>'Real Estate'],
                  'financial'   => ['icon'=>'📊','label'=>'Financial'],
                  'general'     => ['icon'=>'🌐','label'=>'General'],
                ];
                $sel_niche = fv('niche','real_estate');
                foreach ($niches as $key => $n):
                  $sel = ($sel_niche === $key) ? 'selected' : '';
                ?>
                <div class="niche-card <?= $sel ?>" onclick="selectNiche('<?= $key ?>')" id="niche-<?= $key ?>">
                  <input type="radio" name="niche" value="<?= $key ?>" <?= $sel ? 'checked' : '' ?>>
                  <div class="niche-icon"><?= $n['icon'] ?></div>
                  <div class="niche-label"><?= $n['label'] ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="fg">
              <label>Internal Notes <span class="opt-tag">optional</span></label>
              <textarea name="notes" placeholder="e.g. Referred by John Smith. Focus on expired listings in DFW area."><?= fv('notes') ?></textarea>
              <div class="fg-hint">Visible to admins only — not shown to the client.</div>
            </div>
          </div>
        </div>

      </div>
      <!-- end left -->

      <!-- ── RIGHT: Summary sidebar ─────────────────────── -->
      <div>

        <!-- Account summary -->
        <div class="side-panel">
          <div class="sp-head">📋 Account Summary</div>
          <div class="sp-body">
            <div class="sum-row">
              <span class="sum-label">Plan</span>
              <span class="sum-value teal" id="sum-plan">Growth</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Monthly fee</span>
              <span class="sum-value" id="sum-price">$597 / mo</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Included minutes</span>
              <span class="sum-value" id="sum-mins">800 mins</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Status</span>
              <span class="sum-value" style="color:var(--green);">Active</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Added by</span>
              <span class="sum-value"><?= htmlspecialchars($admin_name) ?></span>
            </div>
          </div>
        </div>

        <!-- What happens next -->
        <div class="side-panel">
          <div class="sp-head">🚀 What Happens Next</div>
          <div class="sp-body">
            <div class="checklist">
              <div class="checklist-item"><div class="ci-icon">✓</div><span>Client account & login created instantly</span></div>
              <div class="checklist-item"><div class="ci-icon">✓</div><span>Login credentials ready to share</span></div>
              <div class="checklist-item"><div class="ci-icon">✓</div><span>Client can upload leads & create campaigns</span></div>
              <div class="checklist-item"><div class="ci-icon">✓</div><span>Admin can impersonate their account anytime</span></div>
              <div class="checklist-item"><div class="ci-icon">✓</div><span>All activity logged to audit trail</span></div>
            </div>
          </div>
        </div>

        <!-- Quick tips -->
        <div class="side-panel">
          <div class="sp-head">💡 Quick Tips</div>
          <div class="sp-body" style="font-size:13px;color:var(--ink-soft);line-height:1.6;">
            <p style="margin-bottom:10px;">Set the password to something simple like their phone number or postcode — they can change it after first login.</p>
            <p style="margin-bottom:10px;">Choose the correct niche so script templates are pre-filtered for their industry.</p>
            <p>Opening credit balance is useful for promotional free trials — add $25–50 to get them started.</p>
          </div>
        </div>

        <!-- Save button (duplicate for convenience) -->
        <button type="submit" class="btn-save" style="width:100%;justify-content:center;padding:13px;font-size:15px;border-radius:12px;" id="saveBtnBottom">
          ✓ Save Client
        </button>
        <p style="text-align:center;font-size:11px;color:var(--ink-mute);margin-top:8px;">
          Client can log in immediately after saving
        </p>

      </div>
      <!-- end right -->

    </div>
    </form>

  </div><!-- /page -->
</div><!-- /main -->

<div class="toast" id="toast"></div>

<script>
// ── Plan selection ────────────────────────────────────────────
const planData = {
  starter: { label:'Starter', price:'$297 / mo', mins:'200 mins' },
  growth:  { label:'Growth',  price:'$597 / mo', mins:'800 mins' },
  agency:  { label:'Agency',  price:'$997 / mo', mins:'Unlimited' },
};

function selectPlan(key) {
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('plan-' + key).classList.add('selected');
  document.querySelector(`input[name="plan_type"][value="${key}"]`).checked = true;
  // Update summary
  const d = planData[key];
  document.getElementById('sum-plan').textContent  = d.label;
  document.getElementById('sum-price').textContent = d.price;
  document.getElementById('sum-mins').textContent  = d.mins;
}

// ── Niche selection ───────────────────────────────────────────
function selectNiche(key) {
  document.querySelectorAll('.niche-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('niche-' + key).classList.add('selected');
  document.querySelector(`input[name="niche"][value="${key}"]`).checked = true;
}

// ── Password visibility ───────────────────────────────────────
function togglePw(id, btn) {
  const f = document.getElementById(id);
  f.type  = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}

// ── Topbar save button submits the form ───────────────────────
function submitForm() {
  document.getElementById('clientForm').submit();
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(() => t.style.opacity = '0', 2500);
}

// ── Show toast on success ─────────────────────────────────────
<?php if ($success): ?>
setTimeout(() => showToast('✅ Client saved successfully!'), 300);
<?php endif; ?>

// ── Live password match indicator ─────────────────────────────
document.getElementById('pw2').addEventListener('input', function() {
  const match = this.value === document.getElementById('pw1').value;
  this.style.borderColor = this.value ? (match ? 'var(--green)' : 'var(--red)') : '';
});
</script>
</body>
</html>

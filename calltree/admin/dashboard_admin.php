<?php
// ── Auth guard ────────────────────────────────────────────────
session_start();
/*
if (!isset($_SESSION['cts_user_id'])) {
    header('Location: ../login.php'); exit;
}
if (!in_array($_SESSION['cts_role'] ?? '', ['super_admin','admin'])) {
    header('Location: ../login.php?error=no_permission'); exit;
}
*/
require_once __DIR__ . '/../dbconnect.php';

$admin_id    = (int)$_SESSION['cts_user_id'];
$admin_name  = $_SESSION['cts_firstname'] . ' ' . $_SESSION['cts_lastname'];
$admin_init  = strtoupper(substr($_SESSION['cts_firstname'], 0, 1));

// ── AJAX handlers ────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // Suspend / activate client
    if ($_POST['ajax_action'] === 'toggle_status') {
        $cid    = (int)($_POST['client_id'] ?? 0);
        $status = $_POST['status'] === 'active' ? 'suspended' : 'active';
        $safe   = mysqli_real_escape_string($conn, $status);
        $ok     = mysqli_query($conn,
            "UPDATE cts_clients SET status='$safe', updated_at=NOW() WHERE id=$cid");
        if ($ok) {
            mysqli_query($conn,
                "INSERT INTO cts_audit_log (actor_id,actor_role,client_id,action,entity_type,entity_id,new_values,ip_address)
                 VALUES ($admin_id,'{$_SESSION['cts_role']}',$cid,'client_status_changed','client',$cid,
                         '{\"status\":\"$safe\"}','" . mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']) . "')");
        }
        echo json_encode(['success' => (bool)$ok, 'new_status' => $status]);
        exit;
    }

    // Add new client
    if ($_POST['ajax_action'] === 'add_client') {
        $fn    = mysqli_real_escape_string($conn, trim($_POST['firstname']    ?? ''));
        $ln    = mysqli_real_escape_string($conn, trim($_POST['lastname']     ?? ''));
        $co    = mysqli_real_escape_string($conn, trim($_POST['company_name'] ?? ''));
        $em    = mysqli_real_escape_string($conn, trim($_POST['email']        ?? ''));
        $ph    = mysqli_real_escape_string($conn, trim($_POST['phone']        ?? ''));
        $tz    = mysqli_real_escape_string($conn, trim($_POST['timezone']     ?? 'UTC'));
        $plan  = in_array($_POST['plan_type'] ?? '', ['starter','growth','agency'])
                 ? $_POST['plan_type'] : 'starter';
        $mins  = ['starter'=>200,'growth'=>800,'agency'=>9999][$plan];
        // Use provided username or auto-generate from name
        $raw_uname = trim($_POST['username'] ?? '');
        if (!empty($raw_uname)) {
            $base_uname = strtolower(preg_replace('/[^a-z0-9_]/', '', $raw_uname));
        } else {
            $base_uname = strtolower(preg_replace('/[^a-z0-9]/i', '', $fn.$ln)) . rand(10,99);
        }
        // Ensure uniqueness
        $uname_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM cts_users WHERE user_name='" . mysqli_real_escape_string($conn,$base_uname) . "' LIMIT 1"));
        $uname = $uname_check ? $base_uname . rand(10,99) : $base_uname;
        $uname = mysqli_real_escape_string($conn, $uname);
        $pw    = password_hash($_POST['password'] ?? 'Welcome@123', PASSWORD_DEFAULT);
        $fwd   = 'dashboard.php';

        if (empty($fn) || empty($em)) {
            echo json_encode(['success'=>false,'error'=>'First name and email are required']); exit;
        }

        // Check email unique
        $chk = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM cts_users WHERE email_id='$em' LIMIT 1"));
        if ($chk) { echo json_encode(['success'=>false,'error'=>'Email already exists']); exit; }

        mysqli_query($conn, "START TRANSACTION");
        try {
            // Create user login
            mysqli_query($conn,
                "INSERT INTO cts_users (user_name,firstname,lastname,password,level_name,email_id,phone_number,
                 company_name,plan_type,created_at,updated_at,schedule_flag,max_videos_allowed,
                 trial_period_expiry_dt,team_lead_id,role,credit_balance,forward_to)
                 VALUES ('$uname','$fn','$ln','$pw','client','$em','$ph','$co','$plan',
                 NOW(),NOW(),0,100,'2099-12-31',0,'client',0,'$fwd')");
            $uid = mysqli_insert_id($conn);

            // Create client record
            mysqli_query($conn,
                "INSERT INTO cts_clients (user_id,company_name,contact_firstname,contact_lastname,
                 email,phone,timezone,plan_type,status,monthly_minutes,added_by,created_at)
                 VALUES ($uid,'$co','$fn','$ln','$em','$ph','$tz','$plan','active',$mins,$admin_id,NOW())");
            $cid = mysqli_insert_id($conn);

            // Update client_id back on user
            mysqli_query($conn,"UPDATE cts_users SET client_id=$cid WHERE id=$uid");

            // Audit
            mysqli_query($conn,
                "INSERT INTO cts_audit_log (actor_id,actor_role,client_id,action,entity_type,entity_id,ip_address)
                 VALUES ($admin_id,'{$_SESSION['cts_role']}',$cid,'client_added','client',$cid,
                 '" . mysqli_real_escape_string($conn,$_SERVER['REMOTE_ADDR']) . "')");

            mysqli_query($conn, "COMMIT");
            echo json_encode(['success'=>true,'client_id'=>$cid,'user_name'=>$uname]);
        } catch (Exception $e) {
            mysqli_query($conn, "ROLLBACK");
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // Impersonate client
    if ($_POST['ajax_action'] === 'impersonate') {
        $cid   = (int)($_POST['client_id'] ?? 0);
        $token = bin2hex(random_bytes(24));
        $exp   = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        mysqli_query($conn,
            "UPDATE cts_clients SET impersonate_token='$token', impersonate_expires='$exp'
             WHERE id=$cid");
        mysqli_query($conn,
            "INSERT INTO cts_audit_log (actor_id,actor_role,client_id,action,entity_type,entity_id,ip_address)
             VALUES ($admin_id,'{$_SESSION['cts_role']}',$cid,'impersonate_start','client',$cid,
             '" . mysqli_real_escape_string($conn,$_SERVER['REMOTE_ADDR']) . "')");
        echo json_encode(['success'=>true,'token'=>$token,'url'=>'../impersonate.php?token='.$token]);
        exit;
    }

    // Export CSV
    if ($_POST['ajax_action'] === 'export_clients') {
        $rows = [];
        $q = mysqli_query($conn,
            "SELECT c.id, c.company_name, c.contact_firstname, c.contact_lastname,
                    c.email, c.phone, c.plan_type, c.status,
                    c.total_calls, c.total_minutes, c.total_appointments,
                    c.credit_balance, c.created_at
             FROM cts_clients c ORDER BY c.id DESC");
        while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
}

// ── Stats cards ───────────────────────────────────────────────
$stat = [];

$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_clients"));
$stat['total_clients'] = (int)$r['c'];

$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_clients WHERE status='active'"));
$stat['active_clients'] = (int)$r['c'];

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM cts_call_log WHERE DATE(initiated_at)=CURDATE()"));
$stat['calls_today'] = (int)$r['c'];

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(billed_minutes),0) m FROM cts_call_log WHERE DATE(initiated_at)=CURDATE()"));
$stat['minutes_today'] = round((float)$r['m'], 1);

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(total),0) v FROM cts_billing
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND status='paid'"));
$stat['revenue_month'] = number_format((float)$r['v'], 2);

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM cts_campaigns WHERE status='running'"));
$stat['active_campaigns'] = (int)$r['c'];

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM cts_appointments
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"));
$stat['appointments_month'] = (int)$r['c'];

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM cts_clients
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"));
$stat['new_clients_month'] = (int)$r['c'];

// ── Client list ───────────────────────────────────────────────
$clients = [];
$q = mysqli_query($conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM cts_campaigns cp WHERE cp.client_id=c.id) AS campaign_count,
            (SELECT COUNT(*) FROM cts_call_log cl WHERE cl.client_id=c.id AND DATE(cl.initiated_at)=CURDATE()) AS calls_today,
            (SELECT COALESCE(SUM(cl2.talk_seconds),0) FROM cts_call_log cl2 WHERE cl2.client_id=c.id AND DATE(cl2.initiated_at)=CURDATE()) AS seconds_today
     FROM cts_clients c
     ORDER BY c.id DESC");
while ($r = mysqli_fetch_assoc($q)) $clients[] = $r;

// ── Recent call log ───────────────────────────────────────────
$recent_calls = [];
$q = mysqli_query($conn,
    "SELECT cl.*, c.company_name
     FROM cts_call_log cl
     LEFT JOIN cts_clients c ON c.id = cl.client_id
     ORDER BY cl.initiated_at DESC LIMIT 12");
while ($r = mysqli_fetch_assoc($q)) $recent_calls[] = $r;

// ── Helpers ───────────────────────────────────────────────────
function fmtSecs($s) {
    $s = (int)$s;
    if ($s < 60) return $s . 's';
    return floor($s/60) . 'm ' . ($s%60) . 's';
}
function timeAgo($dt) {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff/60)  . 'm ago';
    if ($diff < 86400)return floor($diff/3600) . 'h ago';
    return date('M j', strtotime($dt));
}
$av_colors = ['#1a7a6e','#c8973a','#3b82f6','#8b5cf6','#ef4444','#06b6d4'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — CallMind AI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700;1,9..144,300&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --ink:        #0a0e1a;
  --ink-soft:   #3d4460;
  --ink-mute:   #7a8099;
  --bg:         #f0f2f7;
  --sidebar-bg: #0a0e1a;
  --card:       #ffffff;
  --teal:       #1a7a6e;
  --teal-lt:    #22a090;
  --teal-pale:  #d4efec;
  --gold:       #c8973a;
  --gold-pale:  rgba(200,151,58,.12);
  --red:        #dc2626;
  --red-pale:   #fef2f2;
  --green:      #16a34a;
  --green-pale: #f0fdf4;
  --blue:       #2563eb;
  --border:     rgba(10,14,26,.08);
  --shadow:     0 1px 4px rgba(10,14,26,.07);
  --shadow-md:  0 4px 20px rgba(10,14,26,.09);
  --radius:     14px;
  --ff-display: 'Fraunces', Georgia, serif;
  --ff-body:    'DM Sans', sans-serif;
  --sidebar-w:  240px;
}
html { height: 100%; }
body {
  font-family: var(--ff-body);
  background: var(--bg);
  color: var(--ink);
  min-height: 100vh;
  display: flex;
}

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w);
  background: var(--sidebar-bg);
  min-height: 100vh;
  position: fixed; top: 0; left: 0; bottom: 0;
  display: flex; flex-direction: column;
  z-index: 500;
  transition: transform .3s;
}
.sb-logo {
  padding: 24px 20px 20px;
  display: flex; align-items: center; gap: 10px;
  text-decoration: none;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.sb-logo-mark {
  width: 34px; height: 34px; border-radius: 9px;
  background: var(--teal);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.sb-logo-mark svg { width: 18px; height: 18px; }
.sb-logo-name {
  font-family: var(--ff-display);
  font-size: 17px; font-weight: 700;
  color: #fff; letter-spacing: -.02em;
}
.sb-logo-name em { color: #22a090; font-style: normal; }

.sb-section { padding: 20px 12px 8px; }
.sb-section-label {
  font-size: 10px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.25);
  padding: 0 8px; margin-bottom: 6px;
}
.sb-nav { list-style: none; display: flex; flex-direction: column; gap: 2px; }
.sb-nav a {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 10px;
  font-size: 13px; font-weight: 500;
  color: rgba(255,255,255,.5);
  text-decoration: none;
  transition: all .15s;
}
.sb-nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
.sb-nav a.active {
  background: var(--teal); color: #fff; font-weight: 600;
}
.sb-nav a .ico { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.sb-badge {
  margin-left: auto;
  background: var(--teal); color: #fff;
  font-size: 10px; font-weight: 700;
  padding: 2px 7px; border-radius: 100px;
}

.sb-bottom {
  margin-top: auto;
  padding: 16px 12px;
  border-top: 1px solid rgba(255,255,255,.06);
}
.sb-user {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 10px;
  transition: background .15s; cursor: pointer;
}
.sb-user:hover { background: rgba(255,255,255,.06); }
.sb-avatar {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--teal);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.sb-user-name { font-size: 13px; font-weight: 600; color: #fff; }
.sb-user-role { font-size: 11px; color: rgba(255,255,255,.35); }
.sb-logout {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 12px; border-radius: 10px;
  font-size: 13px; color: rgba(255,255,255,.35);
  text-decoration: none; margin-top: 4px;
  transition: all .15s;
}
.sb-logout:hover { background: rgba(220,38,38,.15); color: #f87171; }

/* ── Main content ─────────────────────────────────────────── */
.main {
  margin-left: var(--sidebar-w);
  flex: 1; min-width: 0;
  display: flex; flex-direction: column;
}

/* ── Topbar ───────────────────────────────────────────────── */
.topbar {
  background: var(--card);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: 60px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 400;
}
.topbar-left { display: flex; align-items: center; gap: 12px; }
.topbar-title {
  font-family: var(--ff-display);
  font-size: 18px; font-weight: 700;
  color: var(--ink); letter-spacing: -.02em;
}
.topbar-date { font-size: 12px; color: var(--ink-mute); }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.topbar-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: 10px;
  font-size: 13px; font-weight: 600;
  cursor: pointer; border: none;
  font-family: var(--ff-body);
  transition: all .15s;
}
.topbar-btn.primary { background: var(--teal); color: #fff; }
.topbar-btn.primary:hover { background: var(--teal-lt); }
.topbar-btn.ghost {
  background: transparent; color: var(--ink-soft);
  border: 1.5px solid var(--border);
}
.topbar-btn.ghost:hover { border-color: var(--ink); color: var(--ink); }

/* ── Page content ─────────────────────────────────────────── */
.page { padding: 28px; flex: 1; }

/* ── Stat cards ───────────────────────────────────────────── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px; margin-bottom: 24px;
}
.stat-card {
  background: var(--card);
  border-radius: var(--radius);
  padding: 20px 22px;
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  position: relative; overflow: hidden;
  transition: box-shadow .2s;
}
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-card::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
}
.stat-card.teal::before   { background: linear-gradient(90deg, var(--teal), var(--teal-lt)); }
.stat-card.gold::before   { background: linear-gradient(90deg, var(--gold), #e8b95a); }
.stat-card.blue::before   { background: linear-gradient(90deg, var(--blue), #60a5fa); }
.stat-card.green::before  { background: linear-gradient(90deg, var(--green), #4ade80); }

.sc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.sc-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-mute); }
.sc-icon { font-size: 20px; }
.sc-value {
  font-family: var(--ff-display);
  font-size: 32px; font-weight: 700;
  color: var(--ink); line-height: 1;
  margin-bottom: 4px;
}
.sc-sub { font-size: 12px; color: var(--ink-mute); }
.sc-trend {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 700; padding: 2px 8px;
  border-radius: 100px; margin-top: 6px;
}
.sc-trend.up   { background: var(--green-pale); color: var(--green); }
.sc-trend.down { background: var(--red-pale);   color: var(--red); }

/* ── Two-column layout ────────────────────────────────────── */
.content-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 20px;
}

/* ── Panel / card wrapper ─────────────────────────────────── */
.panel {
  background: var(--card);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  overflow: hidden;
}
.panel-head {
  padding: 18px 22px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.panel-title {
  font-size: 14px; font-weight: 700;
  color: var(--ink);
  display: flex; align-items: center; gap: 8px;
}
.panel-title .pt-icon { font-size: 16px; }
.panel-action {
  font-size: 12px; font-weight: 600;
  color: var(--teal); cursor: pointer;
  background: none; border: none;
  font-family: var(--ff-body);
  transition: color .15s;
}
.panel-action:hover { color: var(--teal-lt); }

/* ── Clients table ────────────────────────────────────────── */
.clients-table { width: 100%; border-collapse: collapse; }
.clients-table th {
  text-align: left; padding: 10px 16px;
  font-size: 11px; font-weight: 700;
  letter-spacing: .06em; text-transform: uppercase;
  color: var(--ink-mute);
  background: #fafbfc;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.clients-table td {
  padding: 13px 16px;
  font-size: 13px; color: var(--ink-soft);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.clients-table tr:last-child td { border-bottom: none; }
.clients-table tr:hover td { background: #fafbfd; }

.client-cell { display: flex; align-items: center; gap: 10px; }
.client-av {
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.client-name { font-weight: 600; color: var(--ink); font-size: 13px; }
.client-email { font-size: 11px; color: var(--ink-mute); margin-top: 1px; }

/* badges */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 100px;
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .04em; white-space: nowrap;
}
.badge-active    { background: var(--green-pale); color: var(--green); }
.badge-suspended { background: var(--red-pale);   color: var(--red); }
.badge-trial     { background: var(--gold-pale);   color: var(--gold); }
.badge-teal      { background: var(--teal-pale);  color: var(--teal); }
.badge-blue      { background: #eff6ff; color: var(--blue); }
.badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

/* plan tags */
.plan-tag {
  display: inline-block;
  padding: 3px 9px; border-radius: 8px;
  font-size: 11px; font-weight: 700;
}
.plan-starter { background: #f1f5f9; color: #64748b; }
.plan-growth  { background: var(--teal-pale); color: var(--teal); }
.plan-agency  { background: var(--gold-pale); color: var(--gold); }

/* action buttons in table */
.tbl-actions { display: flex; align-items: center; gap: 6px; }
.tbl-btn {
  padding: 5px 12px; border-radius: 8px;
  font-size: 11px; font-weight: 600; cursor: pointer;
  border: 1.5px solid; font-family: var(--ff-body);
  transition: all .15s; white-space: nowrap;
}
.tbl-btn-view   { color: var(--teal); border-color: var(--teal-pale); background: var(--teal-pale); }
.tbl-btn-view:hover   { background: var(--teal); color: #fff; }
.tbl-btn-imp    { color: var(--blue); border-color: #dbeafe; background: #eff6ff; }
.tbl-btn-imp:hover    { background: var(--blue); color: #fff; }
.tbl-btn-susp   { color: var(--red); border-color: #fecaca; background: var(--red-pale); }
.tbl-btn-susp:hover   { background: var(--red); color: #fff; }
.tbl-btn-act    { color: var(--green); border-color: #bbf7d0; background: var(--green-pale); }
.tbl-btn-act:hover    { background: var(--green); color: #fff; }

/* ── Recent calls feed ────────────────────────────────────── */
.call-feed { display: flex; flex-direction: column; }
.call-item {
  display: flex; align-items: center; gap: 12px;
  padding: 13px 20px;
  border-bottom: 1px solid var(--border);
  transition: background .15s;
}
.call-item:last-child { border-bottom: none; }
.call-item:hover { background: #fafbfd; }
.call-dot {
  width: 8px; height: 8px; border-radius: 50%;
  flex-shrink: 0;
}
.call-dot.answered    { background: var(--green); }
.call-dot.no_answer   { background: var(--ink-mute); }
.call-dot.voicemail   { background: var(--gold); }
.call-dot.busy        { background: var(--red); }
.call-dot.failed      { background: var(--red); }

.call-info { flex: 1; min-width: 0; }
.call-name {
  font-size: 13px; font-weight: 600; color: var(--ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.call-meta { font-size: 11px; color: var(--ink-mute); margin-top: 2px; }
.call-dur {
  font-size: 12px; font-weight: 600;
  color: var(--ink-soft); white-space: nowrap;
}
.call-outcome-pill {
  font-size: 10px; font-weight: 700; padding: 2px 8px;
  border-radius: 100px; white-space: nowrap;
  text-transform: uppercase; letter-spacing: .04em;
}
.pill-appt { background: var(--teal-pale); color: var(--teal); }
.pill-int  { background: var(--green-pale); color: var(--green); }
.pill-vm   { background: var(--gold-pale); color: var(--gold); }
.pill-na   { background: #f1f5f9; color: var(--ink-mute); }

/* ── Modal ────────────────────────────────────────────────── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(10,14,26,.55);
  backdrop-filter: blur(4px);
  z-index: 9000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal-box {
  background: var(--card); border-radius: 20px;
  padding: 36px; width: 100%; max-width: 480px;
  box-shadow: 0 32px 80px rgba(10,14,26,.2);
  animation: modalUp .25s ease; position: relative;
}
@keyframes modalUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
.modal-close {
  position: absolute; top: 16px; right: 16px;
  width: 30px; height: 30px; border-radius: 8px;
  background: var(--bg); border: none; cursor: pointer;
  font-size: 14px; color: var(--ink-mute);
  display: flex; align-items: center; justify-content: center;
}
.modal-close:hover { background: var(--border); }
.modal-title {
  font-family: var(--ff-display);
  font-size: 22px; font-weight: 700; margin-bottom: 4px;
}
.modal-sub { font-size: 13px; color: var(--ink-mute); margin-bottom: 24px; }

/* form inside modal */
.fg { margin-bottom: 14px; }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fg label {
  display: block; font-size: 12px; font-weight: 600;
  color: var(--ink); margin-bottom: 5px;
}
.fg input, .fg select {
  width: 100%; padding: 10px 13px;
  background: var(--bg); border: 1.5px solid var(--border);
  border-radius: 10px; font-size: 13px;
  font-family: var(--ff-body); color: var(--ink);
  outline: none; transition: border-color .15s;
}
.fg input:focus, .fg select:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(26,122,110,.08);
  background: #fff;
}
.modal-alert {
  padding: 10px 14px; border-radius: 10px;
  font-size: 13px; margin-bottom: 14px; display: none;
}
.modal-alert.err { background: var(--red-pale); color: var(--red); border: 1px solid #fecaca; }
.modal-alert.ok  { background: var(--green-pale); color: var(--green); border: 1px solid #bbf7d0; }
.modal-footer { display: flex; gap: 10px; margin-top: 8px; }
.btn-cancel {
  flex: 1; padding: 11px; border-radius: 10px;
  border: 1.5px solid var(--border); background: #fff;
  color: var(--ink-mute); font-size: 14px; font-weight: 600;
  cursor: pointer; font-family: var(--ff-body);
}
.btn-save {
  flex: 2; padding: 11px; border-radius: 10px;
  border: none; background: var(--teal); color: #fff;
  font-size: 14px; font-weight: 700; cursor: pointer;
  font-family: var(--ff-body); transition: background .15s;
}
.btn-save:hover { background: var(--teal-lt); }
.btn-save:disabled { opacity: .6; cursor: not-allowed; }

/* ── Toast ────────────────────────────────────────────────── */
.toast {
  position: fixed; bottom: 24px; left: 50%;
  transform: translateX(-50%);
  background: var(--ink); color: #fff;
  padding: 11px 22px; border-radius: 12px;
  font-size: 13px; font-weight: 600;
  z-index: 9999; opacity: 0;
  transition: opacity .3s; pointer-events: none;
  white-space: nowrap;
}

/* ── Empty state ──────────────────────────────────────────── */
.empty-row td {
  text-align: center; padding: 40px;
  color: var(--ink-mute); font-size: 14px;
}

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 1100px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .content-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
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
      <li><a href="dashboard.php" class="active"><span class="ico">📊</span> Dashboard</a></li>
      <li><a href="clients.php"><span class="ico">👥</span> Clients <span class="sb-badge"><?= $stat['total_clients'] ?></span></a></li>
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
    <a href="../logout.php" class="sb-logout">
      <span>🚪</span> Log Out
    </a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <span class="topbar-title">Dashboard</span>
      <span class="topbar-date"><?= date('l, F j, Y') ?></span>
    </div>
    <div class="topbar-right">
      <button class="topbar-btn ghost" onclick="exportCSV()">⬇ Export CSV</button>
      <button class="topbar-btn primary" onclick="openAddClient()">➕ Add Client</button>
    </div>
  </div>

  <!-- Page content -->
  <div class="page">

    <!-- ── Stats grid ───────────────────────────────────────── -->
    <div class="stats-grid">

      <div class="stat-card teal">
        <div class="sc-header">
          <span class="sc-label">Total Clients</span>
          <span class="sc-icon">👥</span>
        </div>
        <div class="sc-value"><?= $stat['total_clients'] ?></div>
        <div class="sc-sub"><?= $stat['active_clients'] ?> active accounts</div>
        <div class="sc-trend up">↑ <?= $stat['new_clients_month'] ?> this month</div>
      </div>

      <div class="stat-card gold">
        <div class="sc-header">
          <span class="sc-label">Calls Today</span>
          <span class="sc-icon">📞</span>
        </div>
        <div class="sc-value"><?= $stat['calls_today'] ?></div>
        <div class="sc-sub"><?= $stat['minutes_today'] ?> minutes used today</div>
        <div class="sc-trend up">↑ Active campaigns: <?= $stat['active_campaigns'] ?></div>
      </div>

      <div class="stat-card blue">
        <div class="sc-header">
          <span class="sc-label">Revenue / Month</span>
          <span class="sc-icon">💰</span>
        </div>
        <div class="sc-value">$<?= $stat['revenue_month'] ?></div>
        <div class="sc-sub">Paid invoices this month</div>
        <div class="sc-trend up">↑ <?= $stat['new_clients_month'] ?> new clients</div>
      </div>

      <div class="stat-card green">
        <div class="sc-header">
          <span class="sc-label">Appointments</span>
          <span class="sc-icon">📅</span>
        </div>
        <div class="sc-value"><?= $stat['appointments_month'] ?></div>
        <div class="sc-sub">Booked this month</div>
        <div class="sc-trend up">↑ All campaigns combined</div>
      </div>

    </div>
    <!-- end stats -->

    <!-- ── Content grid ─────────────────────────────────────── -->
    <div class="content-grid">

      <!-- Clients table -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">
            <span class="pt-icon">👥</span> All Clients
          </div>
          <button class="panel-action" onclick="openAddClient()">+ Add New</button>
        </div>

        <div style="overflow-x:auto;">
          <table class="clients-table">
            <thead>
              <tr>
                <th>Client</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Calls Today</th>
                <th>Talk Time</th>
                <th>Appts</th>
                <th>Campaigns</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="clientsBody">
            <?php if (empty($clients)): ?>
              <tr class="empty-row"><td colspan="8">No clients yet — add your first one above</td></tr>
            <?php else: ?>
            <?php foreach ($clients as $i => $cl):
              $init  = strtoupper(substr($cl['company_name'], 0, 1));
              $color = $av_colors[$i % count($av_colors)];
              $mins  = $cl['seconds_today'] > 0 ? fmtSecs($cl['seconds_today']) : '—';
            ?>
              <tr id="row-<?= $cl['id'] ?>">
                <td>
                  <div class="client-cell">
                    <div class="client-av" style="background:<?= $color ?>;"><?= htmlspecialchars($init) ?></div>
                    <div>
                      <div class="client-name"><?= htmlspecialchars($cl['company_name']) ?></div>
                      <div class="client-email"><?= htmlspecialchars($cl['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="plan-tag plan-<?= $cl['plan_type'] ?>">
                    <?= ucfirst($cl['plan_type']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge badge-<?= $cl['status'] === 'active' ? 'active' : 'suspended' ?>"
                        id="badge-<?= $cl['id'] ?>">
                    <span class="badge-dot"></span>
                    <?= ucfirst($cl['status']) ?>
                  </span>
                </td>
                <td style="font-weight:600;"><?= (int)$cl['calls_today'] ?></td>
                <td><?= $mins ?></td>
                <td style="font-weight:600;"><?= (int)$cl['total_appointments'] ?></td>
                <td><?= (int)$cl['campaign_count'] ?></td>
                <td>
                  <div class="tbl-actions">
                    <button class="tbl-btn tbl-btn-view"
                            onclick="window.location='client_detail.php?id=<?= $cl['id'] ?>'">
                      View
                    </button>
                    <button class="tbl-btn tbl-btn-imp"
                            onclick="impersonate(<?= $cl['id'] ?>)">
                      Login As
                    </button>
                    <?php if ($cl['status'] === 'active'): ?>
                    <button class="tbl-btn tbl-btn-susp"
                            onclick="toggleStatus(<?= $cl['id'] ?>, 'active', this)">
                      Suspend
                    </button>
                    <?php else: ?>
                    <button class="tbl-btn tbl-btn-act"
                            onclick="toggleStatus(<?= $cl['id'] ?>, 'suspended', this)">
                      Activate
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- end clients table -->

      <!-- Recent calls feed -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title"><span class="pt-icon">📞</span> Recent Calls</div>
          <a href="call_logs.php" class="panel-action">View all</a>
        </div>
        <div class="call-feed">
          <?php if (empty($recent_calls)): ?>
            <div style="padding:30px;text-align:center;color:var(--ink-mute);font-size:13px;">
              No calls recorded yet
            </div>
          <?php else: ?>
          <?php foreach ($recent_calls as $cl): ?>
            <div class="call-item">
              <div class="call-dot <?= htmlspecialchars($cl['disposition'] ?? 'no_answer') ?>"></div>
              <div class="call-info">
                <div class="call-name"><?= htmlspecialchars($cl['lead_name']) ?></div>
                <div class="call-meta">
                  <?= htmlspecialchars($cl['company_name'] ?? '—') ?> ·
                  <?= timeAgo($cl['initiated_at']) ?>
                </div>
              </div>
              <?php if ($cl['talk_seconds'] > 0): ?>
                <div class="call-dur"><?= fmtSecs($cl['talk_seconds']) ?></div>
              <?php endif; ?>
              <?php if ($cl['outcome'] === 'appointment_set'): ?>
                <span class="call-outcome-pill pill-appt">Appt</span>
              <?php elseif ($cl['outcome'] === 'interested'): ?>
                <span class="call-outcome-pill pill-int">Hot</span>
              <?php elseif ($cl['disposition'] === 'voicemail'): ?>
                <span class="call-outcome-pill pill-vm">VM</span>
              <?php elseif ($cl['disposition'] === 'no_answer'): ?>
                <span class="call-outcome-pill pill-na">N/A</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <!-- end call feed -->

    </div>
    <!-- end content grid -->

  </div><!-- /page -->
</div><!-- /main -->

<!-- ══ ADD CLIENT MODAL ══════════════════════════════════════ -->
<div class="modal-overlay" id="addModal" onclick="handleOverlay(event,'addModal')">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('addModal')">✕</button>
    <div class="modal-title">Add New Client</div>
    <div class="modal-sub">Creates a login account and client workspace.</div>

    <div class="modal-alert err" id="addErr"></div>
    <div class="modal-alert ok"  id="addOk"></div>

    <div class="fg-row">
      <div class="fg">
        <label>First Name *</label>
        <input type="text" id="ac_fn" placeholder="James">
      </div>
      <div class="fg">
        <label>Last Name *</label>
        <input type="text" id="ac_ln" placeholder="Wilson">
      </div>
    </div>
    <div class="fg">
      <label>Company Name *</label>
      <input type="text" id="ac_co" placeholder="Keller Williams Dallas">
    </div>
    <div class="fg">
      <label>Email Address *</label>
      <input type="email" id="ac_em" placeholder="james@kwdallas.com">
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Phone</label>
        <input type="tel" id="ac_ph" placeholder="+1 214 555 0101">
      </div>
      <div class="fg">
        <label>Timezone</label>
        <select id="ac_tz">
          <option value="UTC">UTC</option>
          <option value="America/New_York">Eastern (ET)</option>
          <option value="America/Chicago" selected>Central (CT)</option>
          <option value="America/Denver">Mountain (MT)</option>
          <option value="America/Los_Angeles">Pacific (PT)</option>
          <option value="America/Toronto">Toronto</option>
          <option value="Europe/London">London</option>
          <option value="Australia/Sydney">Sydney</option>
        </select>
      </div>
    </div>
    <div class="fg-row">
      <div class="fg">
        <label>Plan</label>
        <select id="ac_plan">
          <option value="starter">Starter — $297/mo</option>
          <option value="growth" selected>Growth — $597/mo</option>
          <option value="agency">Agency — $997/mo</option>
        </select>
      <div class="fg">
        <label>Password *</label>
        <div style="position:relative;">
          <input type="password" id="ac_pw" placeholder="Set a password" autocomplete="new-password" style="width:100%;padding-right:36px;">
          <button type="button" onclick="togglePwAdmin()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:13px;color:var(--ink-mute);">&#128065;</button>
        </div>
      </div>
    </div>
    <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);padding:8px 0 6px;border-top:1px solid var(--border);margin:4px 0 10px;">Login Credentials</div>
    <div class="fg">
      <label>Username *</label>
      <input type="text" id="ac_uname" placeholder="e.g. jameswilson" autocomplete="off">
      <div style="font-size:11px;color:var(--ink-mute);margin-top:4px;">Auto-filled from name if left blank</div>
    </div>


    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
      <button class="btn-save" id="addSaveBtn" onclick="submitAddClient()">
        ➕ Create Client
      </button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Helpers ───────────────────────────────────────────────────
function showToast(msg, dur=2500) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(() => t.style.opacity = '0', dur);
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function handleOverlay(e, id) { if (e.target === document.getElementById(id)) closeModal(id); }
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open')); });

// ── Add client ────────────────────────────────────────────────
function openAddClient() { openModal('addModal'); }

async function submitAddClient() {
  const errEl = document.getElementById('addErr');
  const okEl  = document.getElementById('addOk');
  const btn   = document.getElementById('addSaveBtn');
  errEl.style.display = 'none'; okEl.style.display = 'none';

  const fd = new FormData();
  fd.append('ajax_action',  'add_client');
  fd.append('firstname',    document.getElementById('ac_fn').value.trim());
  fd.append('lastname',     document.getElementById('ac_ln').value.trim());
  fd.append('company_name', document.getElementById('ac_co').value.trim());
  fd.append('email',        document.getElementById('ac_em').value.trim());
  fd.append('phone',        document.getElementById('ac_ph').value.trim());
  fd.append('timezone',     document.getElementById('ac_tz').value);
  fd.append('plan_type',    document.getElementById('ac_plan').value);
  fd.append('username',     document.getElementById('ac_uname').value.trim());
  fd.append('password',     document.getElementById('ac_pw').value);

  btn.disabled = true; btn.textContent = '⏳ Creating…';
  try {
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      okEl.textContent  = `✓ Client created — username: ${d.user_name}`;
      okEl.style.display = 'block';
      showToast('✅ Client added successfully');
      setTimeout(() => { closeModal('addModal'); location.reload(); }, 1600);
    } else {
      errEl.textContent  = d.error || 'Failed to create client.';
      errEl.style.display = 'block';
    }
  } catch(e) {
    errEl.textContent = 'Network error — please try again.';
    errEl.style.display = 'block';
  }
  btn.disabled = false; btn.textContent = '➕ Create Client';
}

// ── Toggle status ─────────────────────────────────────────────
async function toggleStatus(cid, currentStatus, btn) {
  const label  = currentStatus === 'active' ? 'Suspend' : 'Activate';
  if (!confirm(`${label} this client?`)) return;

  const fd = new FormData();
  fd.append('ajax_action', 'toggle_status');
  fd.append('client_id',   cid);
  fd.append('status',      currentStatus);
  try {
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      const badge = document.getElementById('badge-' + cid);
      const ns    = d.new_status;
      if (badge) {
        badge.className = 'badge badge-' + (ns==='active' ? 'active' : 'suspended');
        badge.innerHTML = `<span class="badge-dot"></span>${ns.charAt(0).toUpperCase()+ns.slice(1)}`;
      }
      // Swap button
      if (ns === 'active') {
        btn.className   = 'tbl-btn tbl-btn-susp';
        btn.textContent = 'Suspend';
        btn.onclick     = () => toggleStatus(cid, 'active', btn);
      } else {
        btn.className   = 'tbl-btn tbl-btn-act';
        btn.textContent = 'Activate';
        btn.onclick     = () => toggleStatus(cid, 'suspended', btn);
      }
      showToast(`Client ${ns === 'active' ? 'activated' : 'suspended'}`);
    }
  } catch(e) { showToast('⚠ Error — please try again'); }
}

// ── Impersonate ───────────────────────────────────────────────
async function impersonate(cid) {
  if (!confirm('Log in as this client? A temporary 15-min token will be created.')) return;
  const fd = new FormData();
  fd.append('ajax_action', 'impersonate');
  fd.append('client_id',   cid);
  try {
    const r = await fetch(location.href, { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
      showToast('🔑 Opening client dashboard…');
      window.open(d.url, '_blank');
    } else { showToast('⚠ Impersonate failed'); }
  } catch(e) { showToast('⚠ Network error'); }
}

// ── Export CSV ────────────────────────────────────────────────
async function exportCSV() {
  const fd = new FormData();
  fd.append('ajax_action', 'export_clients');
  const r = await fetch(location.href, { method:'POST', body:fd });
  const d = await r.json();
  if (!d.success) { showToast('⚠ Export failed'); return; }

  const rows = d.data;
  const cols = ['id','company_name','contact_firstname','contact_lastname','email',
                'phone','plan_type','status','total_calls','total_minutes',
                'total_appointments','credit_balance','created_at'];
  const csv  = [cols.join(','),
    ...rows.map(r => cols.map(c => `"${(r[c]||'').toString().replace(/"/g,'""')}"`).join(','))
  ].join('\n');

  const a   = document.createElement('a');
  a.href    = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download= `callmind_clients_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  showToast('⬇ CSV exported');
}

function togglePwAdmin() {
  const f = document.getElementById('ac_pw');
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>

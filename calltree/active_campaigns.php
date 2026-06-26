<?php
// ── Auth guard ─────────────────────────────────────────────────
session_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['cts_user_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit;
}
//echo "client_id in session: " . (int)$_SESSION['cts_client_id'];
//exit;
require_once __DIR__ . '/dbconnect.php';

$uid       = (int)$_SESSION['cts_user_id'];
$client_id = (int)($_SESSION['cts_client_id'] ?? 0);
$firstname = $_SESSION['cts_firstname'] ?? 'Client';
$lastname  = $_SESSION['cts_lastname']  ?? '';
$fullname  = trim($firstname . ' ' . $lastname);
$initials  = strtoupper(substr($firstname,0,1) . substr($lastname,0,1));
$plan      = $_SESSION['cts_plan'] ?? 'starter';
$is_imp    = !empty($_SESSION['is_impersonating']);
$imp_co    = $_SESSION['impersonated_company'] ?? '';

$cl = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM cts_clients WHERE id=$client_id LIMIT 1"));
if (!$cl) { $cl = ['company_name'=>'My Account','monthly_minutes'=>200,'credit_balance'=>0,'plan_type'=>$plan]; }

// ── AJAX handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── Fetch next lead for a slot ────────────────────────────
    if ($act === 'get_next_lead') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        $slot_index  = (int)($_POST['slot_index']  ?? 0);

        // Try 'new' first, then 'retry'
        $lead = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM cts_leads
             WHERE client_id=$client_id
               AND campaign_id=$campaign_id
               AND status='new'
               AND do_not_call=0
               AND (agent_lock IS NULL OR agent_lock < NOW() - INTERVAL 10 MINUTE)
             ORDER BY id ASC LIMIT 1"));

        if (!$lead) {
            $lead = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM cts_leads
                 WHERE client_id=$client_id
                   AND campaign_id=$campaign_id
                   AND status='retry'
                   AND do_not_call=0
                   AND (next_call_at IS NULL OR next_call_at <= NOW())
                   AND (agent_lock IS NULL OR agent_lock < NOW() - INTERVAL 10 MINUTE)
                 ORDER BY next_call_at ASC LIMIT 1"));
        }

        if (!$lead) {
            echo json_encode(['success'=>false,'error'=>'no_leads']);
            exit;
        }

        // Lock to this slot so other concurrent slots skip it
        $lock_key = mysqli_real_escape_string($conn, "slot_{$campaign_id}_{$slot_index}");
        mysqli_query($conn,
            "UPDATE cts_leads SET agent_lock='$lock_key', updated_at=NOW()
             WHERE id={$lead['id']} AND client_id=$client_id");

        echo json_encode(['success'=>true,'lead'=>$lead]);
        exit;
    }

    // ── Record call result ─────────────────────────────────────
    if ($act === 'record_call_result') {
        $lead_id      = (int)($_POST['lead_id']          ?? 0);
        $campaign_id  = (int)($_POST['campaign_id']      ?? 0);
        $outcome      = mysqli_real_escape_string($conn, $_POST['outcome']           ?? '');
        $disposition  = mysqli_real_escape_string($conn, $_POST['disposition']       ?? '');
        $duration_min = round((float)($_POST['duration_minutes'] ?? 0), 4);
        $talk_secs    = (int)round($duration_min * 60);
        $notes_esc    = mysqli_real_escape_string($conn, $_POST['notes']             ?? '');
        $lead_name    = mysqli_real_escape_string($conn, $_POST['lead_name']         ?? '');
        $lead_phone   = mysqli_real_escape_string($conn, $_POST['lead_phone']        ?? '');
        $cost_per_min = 0.05;
        $total_cost   = round($duration_min * $cost_per_min, 4);

        $status_map = [
            'appointment'    => 'appointment',
            'callback'       => 'callback',
            'not_interested' => 'not_interested',
            'voicemail'      => 'voicemail',
            'invalid'        => 'invalid',
            'retry'          => 'retry',
            'do_not_call'    => 'do_not_call',
        ];
        $new_status = $status_map[$outcome] ?? 'retry';
        $next_call  = ($outcome === 'callback')
                      ? "next_call_at=DATE_ADD(NOW(), INTERVAL 2 HOUR),"
                      : "next_call_at=NULL,";
        $dnc_sql    = ($outcome === 'do_not_call') ? "do_not_call=1," : "";

        mysqli_query($conn,
            "UPDATE cts_leads
             SET status='$new_status', disposition='$disposition', outcome='$outcome',
                 $dnc_sql $next_call
                 attempts=attempts+1, last_called_at=NOW(), agent_lock=NULL, updated_at=NOW()
             WHERE id=$lead_id AND client_id=$client_id");

        mysqli_query($conn,
            "INSERT INTO cts_call_log
             (client_id,campaign_id,agent_id,lead_id,lead_name,lead_phone,
              direction,talk_seconds,disposition,outcome,billed_minutes,total_cost,notes,initiated_at)
             VALUES
             ($client_id,$campaign_id,0,$lead_id,'$lead_name','$lead_phone',
              'outbound',$talk_secs,'$disposition','$outcome',$duration_min,$total_cost,'$notes_esc',NOW())");

        $appt_inc = ($outcome === 'appointment') ? 1 : 0;
        $ans_inc  = in_array($outcome, ['appointment','callback','not_interested']) ? 1 : 0;
        mysqli_query($conn,
            "UPDATE cts_campaigns
             SET leads_called=leads_called+1, calls_answered=calls_answered+$ans_inc,
                 appointments_set=appointments_set+$appt_inc, updated_at=NOW()
             WHERE id=$campaign_id AND client_id=$client_id");

        if ($total_cost > 0)
            mysqli_query($conn,
                "UPDATE cts_clients SET credit_balance=credit_balance-$total_cost
                 WHERE id=$client_id AND credit_balance>=$total_cost");

        $credit = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM cts_clients WHERE id=$client_id LIMIT 1"));
        $stats  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT leads_called,calls_answered,appointments_set,total_leads
             FROM cts_campaigns WHERE id=$campaign_id LIMIT 1"));

        echo json_encode([
            'success'        => true,
            'leads_called'   => (int)($stats['leads_called']     ?? 0),
            'calls_answered' => (int)($stats['calls_answered']   ?? 0),
            'appointments'   => (int)($stats['appointments_set'] ?? 0),
            'total_leads'    => (int)($stats['total_leads']      ?? 0),
            'credit_balance' => number_format((float)($credit['credit_balance'] ?? 0), 2),
            'cost_this_call' => $total_cost,
        ]);
        exit;
    }

    // ── Toggle campaign status ─────────────────────────────────
    if ($act === 'toggle_campaign') {
        $cid_c = (int)($_POST['campaign_id']  ?? 0);
        $cur   = $_POST['current_status']     ?? 'draft';
        $new   = ($cur === 'running') ? 'paused' : 'running';
        $ok    = mysqli_query($conn,
            "UPDATE cts_campaigns SET status='$new', updated_at=NOW()
             WHERE id=$cid_c AND client_id=$client_id");
        echo json_encode(['success'=>(bool)$ok,'new_status'=>$new]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

// ── Page data ──────────────────────────────────────────────────
$campaigns = [];
$q = mysqli_query($conn,
    "SELECT * FROM cts_campaigns
     WHERE client_id=$client_id AND status IN ('running','paused','draft')
     ORDER BY status='running' DESC, id DESC");
while ($r = mysqli_fetch_assoc($q)) $campaigns[] = $r;

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(billed_minutes),0) m FROM cts_call_log
     WHERE client_id=$client_id AND MONTH(initiated_at)=MONTH(NOW())"));
$mins_used  = round((float)$r['m'], 1);
$mins_total = (int)($cl['monthly_minutes'] ?? 200);
$mins_pct   = $mins_total > 0 ? min(100, round($mins_used / $mins_total * 100)) : 0;

$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c, COALESCE(SUM(billed_minutes),0) m
     FROM cts_call_log WHERE client_id=$client_id AND DATE(initiated_at)=CURDATE()"));
$calls_today = (int)$r['c'];
$mins_today  = round((float)$r['m'], 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Active Campaigns — <?= htmlspecialchars($cl['company_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --ink:#0a0e1a;--ink-soft:#3d4460;--ink-mute:#7a8099;
  --bg:#f0f2f7;--card:#fff;
  --teal:#1a7a6e;--teal-lt:#22a090;--teal-pale:#d4efec;
  --gold:#c8973a;--gold-pale:rgba(200,151,58,.1);
  --red:#dc2626;--red-pale:#fef2f2;
  --green:#16a34a;--green-pale:#f0fdf4;
  --blue:#2563eb;--blue-pale:#eff6ff;
  --orange:#ea580c;--orange-pale:#fff7ed;
  --border:rgba(10,14,26,.08);
  --shadow:0 1px 4px rgba(10,14,26,.07);
  --radius:14px;
  --ff-display:'Fraunces',Georgia,serif;
  --ff-body:'DM Sans',sans-serif;
  --sidebar-w:240px;
}
html{height:100%;}
body{font-family:var(--ff-body);background:var(--bg);color:var(--ink);min-height:100vh;display:flex;}
.sidebar{width:var(--sidebar-w);background:var(--ink);min-height:100vh;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:500;}
.sb-logo{padding:22px 20px 18px;display:flex;align-items:center;gap:10px;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.06);}
.sb-logo-mark{width:34px;height:34px;border-radius:9px;background:var(--teal);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo-mark svg{width:18px;height:18px;}
.sb-logo-name{font-family:var(--ff-display);font-size:17px;font-weight:700;color:#fff;letter-spacing:-.02em;}
.sb-logo-name em{color:#22a090;font-style:normal;}
.sb-co{padding:12px 20px;font-size:11px;font-weight:600;color:rgba(255,255,255,.35);letter-spacing:.04em;text-transform:uppercase;border-bottom:1px solid rgba(255,255,255,.06);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-section{padding:16px 12px 6px;}
.sb-lbl{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.22);padding:0 8px;margin-bottom:5px;}
.sb-nav{list-style:none;display:flex;flex-direction:column;gap:2px;}
.sb-nav a{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:10px;font-size:13px;font-weight:500;color:rgba(255,255,255,.5);text-decoration:none;transition:all .15s;}
.sb-nav a:hover{background:rgba(255,255,255,.06);color:#fff;}
.sb-nav a.active{background:var(--teal);color:#fff;font-weight:600;}
.sb-nav a .ico{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.sb-bottom{margin-top:auto;padding:16px 12px;border-top:1px solid rgba(255,255,255,.06);}
.sb-credit{background:rgba(255,255,255,.05);border-radius:10px;padding:12px;margin-bottom:12px;}
.sb-credit-label{font-size:11px;color:rgba(255,255,255,.4);margin-bottom:6px;}
.sb-credit-bar{height:5px;background:rgba(255,255,255,.1);border-radius:99px;margin-bottom:5px;overflow:hidden;}
.sb-credit-fill{height:100%;background:var(--teal-lt);border-radius:99px;}
.sb-credit-nums{display:flex;justify-content:space-between;font-size:10px;color:rgba(255,255,255,.3);}
.sb-user{display:flex;align-items:center;gap:9px;padding:8px 4px;margin-bottom:4px;}
.sb-av{width:32px;height:32px;border-radius:9px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-un{font-size:12px;font-weight:600;color:#fff;}
.sb-ur{font-size:10px;color:rgba(255,255,255,.35);}
.sb-logout{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:9px;font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;transition:all .15s;}
.sb-logout:hover{background:rgba(255,255,255,.06);color:#fff;}
.main{margin-left:var(--sidebar-w);flex:1;min-width:0;}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 28px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;}
.tb-title{font-size:18px;font-weight:700;color:var(--ink);}
.btn-ghost{padding:8px 16px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--ink-soft);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);text-decoration:none;display:inline-flex;align-items:center;}
.btn-ghost:hover{border-color:var(--teal);color:var(--teal);}
.page{padding:24px 28px;}
.stats-bar{display:flex;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:24px;}
.sbi{flex:1;padding:14px 20px;border-right:1px solid var(--border);}
.sbi:last-child{border-right:none;}
.sbi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);}
.sbi-val{font-size:20px;font-weight:700;color:var(--ink);margin-top:3px;}
.sbi-val.green{color:var(--green);}
.sbi-val.teal{color:var(--teal);}
.camp-card{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;margin-bottom:24px;box-shadow:var(--shadow);}
.camp-head{padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid var(--border);}
.camp-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.camp-meta{font-size:12px;color:var(--ink-mute);}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.sp-running{background:#dcfce7;color:#15803d;}
.sp-paused{background:#fef9c3;color:#854d0e;}
.sp-draft{background:#f1f5f9;color:#64748b;}
.sp-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}
.btn-go{padding:8px 20px;border:none;border-radius:9px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;}
.btn-go:hover{background:var(--teal-lt);}
.btn-stop{padding:8px 20px;border:1.5px solid #fecaca;border-radius:9px;background:var(--red-pale);color:var(--red);font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);}
.btn-stop:hover{background:#fecaca;}
.camp-stats{display:grid;grid-template-columns:repeat(5,1fr);border-bottom:1px solid var(--border);}
.cs-box{padding:13px 18px;text-align:center;border-right:1px solid var(--border);}
.cs-box:last-child{border-right:none;}
.cs-val{font-size:22px;font-weight:700;color:var(--ink);}
.cs-lbl{font-size:11px;color:var(--ink-mute);margin-top:3px;}
.prog-wrap{padding:10px 22px 14px;}
.prog-track{height:5px;background:var(--border);border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--teal),var(--teal-lt));border-radius:99px;transition:width .6s;}
.slots-area{padding:16px 22px 20px;}
.slots-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.slots-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);}
.slots-grid{display:grid;gap:14px;}
.slot{border:1.5px solid var(--border);border-radius:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.slot.s-dialing{border-color:var(--teal);box-shadow:0 0 0 3px rgba(26,122,110,.09);}
.slot.s-done{border-color:#86efac;}
.slot.s-error{border-color:#fca5a5;}
.slot-top{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:#fafbfc;border-bottom:1px solid var(--border);}
.slot-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);}
.slot-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;text-transform:uppercase;letter-spacing:.04em;}
.sb-idle{background:#f1f5f9;color:#64748b;}
.sb-dialing{background:var(--teal-pale);color:var(--teal);}
.sb-done{background:var(--green-pale);color:var(--green);}
.sb-error{background:var(--red-pale);color:var(--red);}
.slot-body{padding:14px;}
.slot-idle-msg{text-align:center;color:var(--ink-mute);font-size:12px;padding:18px 0;}
.slot-idle-icon{font-size:28px;display:block;margin-bottom:6px;}
.lead-card{background:linear-gradient(135deg,rgba(26,122,110,.06),rgba(34,160,144,.02));border:1px solid rgba(26,122,110,.18);border-radius:9px;padding:12px 14px;}
.lead-dial-row{display:flex;align-items:center;gap:7px;margin-bottom:9px;}
.dot-pulse{width:9px;height:9px;border-radius:50%;background:var(--teal);animation:blink 1s ease-in-out infinite;flex-shrink:0;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.dial-label{font-size:10px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.06em;}
.dial-timer{margin-left:auto;font-size:12px;font-weight:700;color:var(--teal);font-variant-numeric:tabular-nums;}
.lead-name{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:8px;}
.lead-fields{display:grid;grid-template-columns:1fr 1fr;gap:5px 12px;}
.lf{display:flex;flex-direction:column;gap:1px;}
.lf-k{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);}
.lf-v{font-size:12px;font-weight:600;color:var(--ink-soft);}
.outcome-box{border-radius:9px;padding:10px 13px;margin-top:10px;margin-bottom:10px;}
.outcome-title{font-size:13px;font-weight:700;}
.outcome-sub{font-size:11px;margin-top:2px;opacity:.78;}
.oc-appointment{background:#dcfce7;color:#15803d;}
.oc-callback{background:var(--blue-pale);color:var(--blue);}
.oc-not_interested{background:#f1f5f9;color:#475569;}
.oc-voicemail{background:var(--gold-pale);color:var(--gold);}
.oc-retry{background:var(--orange-pale);color:var(--orange);}
.oc-invalid{background:var(--red-pale);color:var(--red);}
.oc-do_not_call{background:var(--red-pale);color:var(--red);}
.btn-next{width:100%;padding:9px;border:none;border-radius:9px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;}
.btn-next:hover{background:var(--teal-lt);}
.mini-log{border-top:1px solid var(--border);padding:10px 22px;}
.ml-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);margin-bottom:8px;}
.ml-item{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;border-bottom:1px solid rgba(10,14,26,.04);}
.ml-item:last-child{border-bottom:none;}
.ml-name{flex:1;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ml-dur{color:var(--ink-mute);font-size:11px;}
.ml-badge{padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;}
.imp-bar{background:#fef3c7;border-bottom:2px solid #f59e0b;padding:8px 24px;display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:600;color:#92400e;}
.imp-bar a{color:#92400e;text-decoration:none;font-weight:700;}
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 22px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap;}
@media(max-width:900px){.camp-stats{grid-template-columns:repeat(3,1fr);}.slots-grid{grid-template-columns:1fr!important;}}
@media(max-width:768px){.sidebar{display:none;}.main{margin-left:0;}}
</style>
</head>
<body>

<aside class="sidebar">
  <a href="dashboard.php" class="sb-logo">
    <div class="sb-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
        <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
      </svg>
    </div>
    <span class="sb-logo-name">Call<em>Mind</em> AI</span>
  </a>
  <div class="sb-co"><?= htmlspecialchars($cl['company_name']) ?></div>
  <div class="sb-section">
    <div class="sb-lbl">My Account</div>
    <ul class="sb-nav">
      <li><a href="dashboard.php"><span class="ico">📊</span> Dashboard</a></li>
      <li><a href="campaigns.php"><span class="ico">📣</span> Campaigns</a></li>
      <li><a href="active_campaigns.php" class="active"><span class="ico">🟢</span> Active Campaigns</a></li>
      <li><a href="call_logs.php"><span class="ico">📞</span> Call Log</a></li>
    </ul>
  </div>
  <div class="sb-section">
    <div class="sb-lbl">Settings</div>
    <ul class="sb-nav">
      <li><a href="profile.php"><span class="ico">👤</span> Profile</a></li>
      <li><a href="billing.php"><span class="ico">💳</span> Billing</a></li>
    </ul>
  </div>
  <div class="sb-bottom">
    <div class="sb-credit">
      <div class="sb-credit-label">Minutes Used This Month</div>
      <div class="sb-credit-bar"><div class="sb-credit-fill" style="width:<?= $mins_pct ?>%"></div></div>
      <div class="sb-credit-nums"><span><?= $mins_used ?> used</span><span><?= $mins_total == 9999 ? '∞' : $mins_total ?> included</span></div>
    </div>
    <div class="sb-user">
      <div class="sb-av"><?= htmlspecialchars($initials) ?></div>
      <div><div class="sb-un"><?= htmlspecialchars($fullname) ?></div><div class="sb-ur"><?= ucfirst($plan) ?> plan</div></div>
    </div>
    <a href="logout.php" class="sb-logout"><span>🚪</span> Log Out</a>
  </div>
</aside>

<div class="main">
  <?php if ($is_imp): ?>
  <div class="imp-bar">
    <span>🔑 Viewing as <strong><?= htmlspecialchars($imp_co) ?></strong></span>
    <a href="admin/dashboard.php">← Back to Admin</a>
  </div>
  <?php endif; ?>

  <div class="topbar">
    <div class="tb-title">🟢 Active Campaigns</div>
    <a href="dashboard.php" class="btn-ghost">← Dashboard</a>
  </div>

  <div class="page">

    <div class="stats-bar">
      <div class="sbi"><div class="sbi-label">Credit Balance</div><div class="sbi-val green" id="global-credit">$<?= number_format((float)$cl['credit_balance'],2) ?></div></div>
      <div class="sbi"><div class="sbi-label">Calls Today</div><div class="sbi-val teal" id="global-calls-today"><?= $calls_today ?></div></div>
      <div class="sbi"><div class="sbi-label">Minutes Today</div><div class="sbi-val" id="global-mins-today"><?= $mins_today ?></div></div>
      <div class="sbi"><div class="sbi-label">Active Slots</div><div class="sbi-val" id="global-active-slots">0</div></div>
    </div>

    <?php if (empty($campaigns)): ?>
    <div style="text-align:center;padding:60px 24px;background:var(--card);border-radius:var(--radius);border:1px solid var(--border);">
      <div style="font-size:44px;margin-bottom:12px;">📣</div>
      <div style="font-size:18px;font-weight:700;margin-bottom:6px;">No campaigns yet</div>
      <div style="font-size:13px;color:var(--ink-mute);margin-bottom:20px;">Create a campaign and upload leads to get started.</div>
      <a href="dashboard.php" style="display:inline-block;padding:10px 22px;background:var(--teal);color:#fff;border-radius:10px;text-decoration:none;font-weight:700;">← Dashboard</a>
    </div>
    <?php else: ?>

    <?php foreach ($campaigns as $camp):
      $cid      = (int)$camp['id'];
      $max_conc = max(1, (int)($camp['max_concurrent'] ?? 1));
      $total_l  = (int)$camp['total_leads'];
      $called_l = (int)$camp['leads_called'];
      $pct      = $total_l > 0 ? min(100, round($called_l / $total_l * 100)) : 0;
      $cols     = min($max_conc, 3);
    ?>
    <div class="camp-card" id="camp-<?= $cid ?>">
      <div class="camp-head">
        <div>
          <div class="camp-title"><?= htmlspecialchars($camp['name']) ?></div>
          <div class="camp-meta">
            <?= ucfirst($camp['direction'] ?? 'outbound') ?> ·
            <?= $max_conc ?> concurrent slot<?= $max_conc > 1 ? 's' : '' ?>
            <?php if (!empty($camp['calling_time_from'])): ?>
              · <?= $camp['calling_time_from'] ?>–<?= $camp['calling_time_to'] ?> <?= $camp['timezone'] ?? '' ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="status-pill sp-<?= $camp['status'] ?>">
            <span class="sp-dot"></span><?= ucfirst($camp['status']) ?>
          </span>
          <button class="btn-go" id="btn-go-<?= $cid ?>"
            onclick="startCampaign(<?= $cid ?>, <?= $max_conc ?>)">
            ▶ Start Calling
          </button>
          <button class="btn-stop" id="btn-stop-<?= $cid ?>"
            onclick="stopCampaign(<?= $cid ?>)" style="display:none;">
            ⏹ Stop All
          </button>
        </div>
      </div>

      <div class="camp-stats">
        <div class="cs-box"><div class="cs-val"><?= $total_l ?></div><div class="cs-lbl">Total Leads</div></div>
        <div class="cs-box"><div class="cs-val" id="c-called-<?= $cid ?>"><?= $called_l ?></div><div class="cs-lbl">Called</div></div>
        <div class="cs-box"><div class="cs-val" id="c-ans-<?= $cid ?>"><?= (int)$camp['calls_answered'] ?></div><div class="cs-lbl">Answered</div></div>
        <div class="cs-box"><div class="cs-val" id="c-appt-<?= $cid ?>"><?= (int)$camp['appointments_set'] ?></div><div class="cs-lbl">Booked</div></div>
        <div class="cs-box"><div class="cs-val" id="c-pct-<?= $cid ?>"><?= $pct ?>%</div><div class="cs-lbl">Complete</div></div>
      </div>
      <div class="prog-wrap">
        <div class="prog-track"><div class="prog-fill" id="prog-<?= $cid ?>" style="width:<?= $pct ?>%"></div></div>
      </div>

      <div class="slots-area">
        <div class="slots-head">
          <div class="slots-title">Dialing Slots — <?= $max_conc ?> concurrent</div>
        </div>
        <div class="slots-grid" id="slots-<?= $cid ?>"
             style="grid-template-columns:repeat(<?= $cols ?>,1fr);">
          <?php for ($s = 0; $s < $max_conc; $s++): ?>
          <div class="slot s-idle" id="slot-<?= $cid ?>-<?= $s ?>">
            <div class="slot-top">
              <span class="slot-lbl">Slot <?= $s+1 ?></span>
              <span class="slot-badge sb-idle">Idle</span>
            </div>
            <div class="slot-body">
              <div class="slot-idle-msg">
                <span class="slot-idle-icon">📞</span>
                Click Start Calling to begin
              </div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="mini-log" id="minilog-<?= $cid ?>" style="display:none;">
        <div class="ml-title">Recent Calls This Session</div>
        <div id="minilog-items-<?= $cid ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>
<div class="toast" id="toast"></div>

<script>
const VAPI_URL    = 'vapi_call.php';
const VAPI_SECRET = 'CM_Vapi_2026!';
const SELF_URL    = location.href.split('?')[0];

const OUTCOMES = {
  appointment:    { label:'✅ Appointment Booked', sub:'Lead agreed to a meeting',     css:'oc-appointment'    },
  callback:       { label:'📅 Callback Requested', sub:'Call back later',              css:'oc-callback'       },
  not_interested: { label:'👎 Not Interested',     sub:'Lead declined',                css:'oc-not_interested' },
  voicemail:      { label:'📬 Voicemail',          sub:'Reached answering machine',    css:'oc-voicemail'      },
  retry:          { label:'🔄 No Answer / Busy',   sub:'Will retry on next run',       css:'oc-retry'          },
  invalid:        { label:'❌ Invalid Number',     sub:'Wrong or disconnected number', css:'oc-invalid'        },
  do_not_call:    { label:'🚫 Do Not Call',        sub:'Added to DNC list',            css:'oc-do_not_call'    },
};

const campRunning = {};   // cid → bool
const nextResolvers = {}; // `${cid}_${s}` → resolve fn
const slotTimers = {};    // `${cid}_${s}` → intervalId

// ── Start / Stop ──────────────────────────────────────────────
function startCampaign(cid, maxConc) {
  campRunning[cid] = true;
  g(`btn-go-${cid}`).style.display   = 'none';
  g(`btn-stop-${cid}`).style.display = '';
  updateSlotCount();
  for (let s = 0; s < maxConc; s++) runSlot(cid, s);
}

function stopCampaign(cid) {
  campRunning[cid] = false;
  g(`btn-go-${cid}`).style.display   = '';
  g(`btn-stop-${cid}`).style.display = 'none';
  // Unblock any waiting slots
  document.querySelectorAll(`[id^="slot-${cid}-"]`).forEach(el => {
    const s = parseInt(el.id.split('-').pop());
    resolveNext(cid, s);
    killTimer(cid, s);
    setIdle(cid, s, 'Stopped');
  });
  updateSlotCount();
  toast('⏹ Calling stopped');
}

// ── Slot loop ─────────────────────────────────────────────────
async function runSlot(cid, s) {
  while (campRunning[cid]) {

    // 1. Fetch next lead
    setBadge(cid, s, 'dialing', 'Fetching…');
    let lr;
    try {
      lr = await post(SELF_URL, { ajax_action:'get_next_lead', campaign_id:cid, slot_index:s });
    } catch(e) {
      setBadge(cid, s, 'error', '⚠ Network');
      await sleep(5000); continue;
    }

    if (!lr.success) {
      setIdle(cid, s, '✅ All leads called');
      campRunning[cid] = false;
      setTimeout(() => {
        g(`btn-go-${cid}`).style.display   = '';
        g(`btn-stop-${cid}`).style.display = 'none';
        updateSlotCount();
        toast('✅ All leads called!');
      }, 400);
      return;
    }

    const lead = lr.lead;

    // 2. Show dialing card — lead info visible to user
    showDialing(cid, s, lead);
    startTimer(cid, s);

    // 3. AJAX to vapi_call.php (dummy — waits 3-7s, returns outcome)
    let result;
    try {
      result = await post(VAPI_URL, {
        secret:      VAPI_SECRET,
        lead_id:     lead.id,
        firstname:   lead.firstname,
        lastname:    lead.lastname,
        phone:       lead.phone_e164 || lead.phone,
        email:       lead.email  || '',
        city:        lead.city   || '',
        state:       lead.state  || '',
        zip:         lead.zip    || '',
        campaign_id: cid,
      });
    } catch(e) {
      result = { success:true, outcome:'retry', disposition:'failed', duration_minutes:0, notes:'Error' };
    }
    killTimer(cid, s);
    if (!result?.success) result = { outcome:'retry', disposition:'failed', duration_minutes:0, notes:'' };

    // 4. Show outcome + Next button
    showOutcome(cid, s, lead, result);

    // 5. Save to DB
    try {
      const sr = await post(SELF_URL, {
        ajax_action:      'record_call_result',
        lead_id:          lead.id,
        campaign_id:      cid,
        outcome:          result.outcome,
        disposition:      result.disposition || 'no_answer',
        duration_minutes: result.duration_minutes || 0,
        notes:            result.notes || '',
        lead_name:        lead.firstname + ' ' + lead.lastname,
        lead_phone:       lead.phone,
      });
      if (sr?.success) {
        updateStats(cid, sr);
        setTxt('global-credit', '$' + sr.credit_balance);
        setTxt('global-calls-today', parseInt(g('global-calls-today').textContent||'0') + 1);
      }
    } catch(e) {}

    // 6. Add to session log
    addLog(cid, lead, result);

    // 7. Wait for Next click
    await waitNext(cid, s);
    if (!campRunning[cid]) { setIdle(cid, s, 'Stopped'); return; }
  }
  setIdle(cid, s, 'Stopped');
  updateSlotCount();
}

// ── Next button ───────────────────────────────────────────────
function waitNext(cid, s)   { return new Promise(r => { nextResolvers[`${cid}_${s}`] = r; }); }
function clickNext(cid, s)  { resolveNext(cid, s); }
function resolveNext(cid, s){ const k=`${cid}_${s}`; if(nextResolvers[k]){ nextResolvers[k](); delete nextResolvers[k]; } }

// ── Renderers ─────────────────────────────────────────────────
function showDialing(cid, s, lead) {
  const card = g(`slot-${cid}-${s}`);
  card.className = 'slot s-dialing';
  const fields = [
    ['Phone',    lead.phone     || ''],
    ['City',     lead.city      || ''],
    ['Province', lead.state     || ''],
    ['Email',    lead.email     || ''],
    ['Zip',      lead.zip       || ''],
    ['Type',     lead.lead_type || ''],
  ].filter(([,v]) => v).slice(0,6);
  const fhtml = fields.map(([k,v])=>`<div class="lf"><span class="lf-k">${k}</span><span class="lf-v">${x(v)}</span></div>`).join('');
  card.innerHTML = `
    <div class="slot-top">
      <span class="slot-lbl">Slot ${s+1}</span>
      <span class="slot-badge sb-dialing">⬤ Dialing</span>
    </div>
    <div class="slot-body">
      <div class="lead-card">
        <div class="lead-dial-row">
          <span class="dot-pulse"></span>
          <span class="dial-label">Dialing</span>
          <span class="dial-timer" id="timer-${cid}-${s}">0:00</span>
        </div>
        <div class="lead-name">${x(lead.firstname)} ${x(lead.lastname)}</div>
        <div class="lead-fields">${fhtml}</div>
      </div>
    </div>`;
}

function showOutcome(cid, s, lead, result) {
  const card = g(`slot-${cid}-${s}`);
  card.className = 'slot s-done';
  const cfg   = OUTCOMES[result.outcome] || OUTCOMES.retry;
  const secs  = Math.round((result.duration_minutes||0)*60);
  const dur   = secs >= 60 ? `${Math.floor(secs/60)}m ${secs%60}s` : `${secs}s`;
  const fields = [
    ['Phone',    lead.phone  || ''],
    ['City',     lead.city   || ''],
    ['Province', lead.state  || ''],
  ].filter(([,v]) => v);
  const fhtml = fields.map(([k,v])=>`<div class="lf"><span class="lf-k">${k}</span><span class="lf-v">${x(v)}</span></div>`).join('');
  card.innerHTML = `
    <div class="slot-top">
      <span class="slot-lbl">Slot ${s+1}</span>
      <span class="slot-badge sb-done">Done</span>
    </div>
    <div class="slot-body">
      <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:6px;">${x(lead.firstname)} ${x(lead.lastname)}</div>
      <div class="lead-fields" style="margin-bottom:6px;">${fhtml}</div>
      <div style="font-size:10px;color:var(--ink-mute);margin-bottom:2px;">Duration: ${dur}</div>
      <div class="outcome-box ${cfg.css}">
        <div class="outcome-title">${cfg.label}</div>
        <div class="outcome-sub">${cfg.sub}</div>
      </div>
      <button class="btn-next" onclick="clickNext(${cid},${s})">▶ Next Lead</button>
    </div>`;
}

function setIdle(cid, s, msg) {
  const card = g(`slot-${cid}-${s}`);
  if (!card) return;
  card.className = 'slot s-idle';
  card.innerHTML = `
    <div class="slot-top">
      <span class="slot-lbl">Slot ${s+1}</span>
      <span class="slot-badge sb-idle">Idle</span>
    </div>
    <div class="slot-body">
      <div class="slot-idle-msg"><span class="slot-idle-icon">📞</span>${x(msg)}</div>
    </div>`;
}

function setBadge(cid, s, mode, msg) {
  const card = g(`slot-${cid}-${s}`);
  if (!card) return;
  card.className = `slot s-${mode==='error'?'error':'dialing'}`;
  card.innerHTML = `
    <div class="slot-top">
      <span class="slot-lbl">Slot ${s+1}</span>
      <span class="slot-badge ${mode==='error'?'sb-error':'sb-dialing'}">${x(msg)}</span>
    </div>
    <div class="slot-body"><div class="slot-idle-msg">${x(msg)}</div></div>`;
}

// ── Timer ─────────────────────────────────────────────────────
function startTimer(cid, s) {
  const k = `${cid}_${s}`, t0 = Date.now();
  slotTimers[k] = setInterval(() => {
    const el = g(`timer-${cid}-${s}`); if (!el) return;
    const sec = Math.floor((Date.now()-t0)/1000);
    el.textContent = `${Math.floor(sec/60)}:${String(sec%60).padStart(2,'0')}`;
  }, 500);
}
function killTimer(cid, s) {
  const k = `${cid}_${s}`;
  if (slotTimers[k]) { clearInterval(slotTimers[k]); delete slotTimers[k]; }
}

// ── Mini log ──────────────────────────────────────────────────
function addLog(cid, lead, result) {
  const wrap  = g(`minilog-${cid}`);
  const items = g(`minilog-items-${cid}`);
  if (!wrap || !items) return;
  wrap.style.display = '';
  const cfg  = OUTCOMES[result.outcome] || OUTCOMES.retry;
  const secs = Math.round((result.duration_minutes||0)*60);
  const dur  = secs >= 60 ? `${Math.floor(secs/60)}m ${secs%60}s` : `${secs}s`;
  const row  = document.createElement('div');
  row.className = 'ml-item';
  row.innerHTML = `
    <span class="ml-name">${x(lead.firstname)} ${x(lead.lastname)}</span>
    <span class="ml-dur">${dur}</span>
    <span class="ml-badge ${cfg.css}">${result.outcome.replace(/_/g,' ')}</span>`;
  items.prepend(row);
  while (items.children.length > 10) items.removeChild(items.lastChild);
}

// ── Stats update ──────────────────────────────────────────────
function updateStats(cid, s) {
  setTxt(`c-called-${cid}`, s.leads_called);
  setTxt(`c-ans-${cid}`,    s.calls_answered);
  setTxt(`c-appt-${cid}`,   s.appointments);
  const total = parseInt(g(`camp-${cid}`)?.querySelector('.cs-val')?.textContent||'0');
  const pct   = total>0 ? Math.min(100,Math.round(s.leads_called/total*100)) : 0;
  setTxt(`c-pct-${cid}`, pct+'%');
  const p = g(`prog-${cid}`); if(p) p.style.width=pct+'%';
}

function updateSlotCount() {
  setTxt('global-active-slots', Object.values(campRunning).filter(Boolean).length);
}

// ── Utilities ─────────────────────────────────────────────────
function g(id)        { return document.getElementById(id); }
function setTxt(id,v) { const e=g(id); if(e) e.textContent=v; }
function sleep(ms)    { return new Promise(r=>setTimeout(r,ms)); }
function x(s)         { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function toast(msg,d=2800){ const t=g('toast'); t.textContent=msg; t.style.opacity='1'; setTimeout(()=>t.style.opacity='0',d); }

async function post(url, data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k, String(v)));
  const r = await fetch(url, { method:'POST', body:fd });
  return r.json();
}
</script>
</body>
</html>

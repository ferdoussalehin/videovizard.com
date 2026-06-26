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

require_once __DIR__ . '/dbconnect.php';

$uid        = (int)$_SESSION['cts_user_id'];
$client_id  = (int)($_SESSION['cts_client_id'] ?? 0);
$firstname  = $_SESSION['cts_firstname'] ?? 'Client';
$lastname   = $_SESSION['cts_lastname']  ?? '';
$fullname   = trim($firstname . ' ' . $lastname);
$initials   = strtoupper(substr($firstname,0,1) . substr($lastname,0,1));
$plan       = $_SESSION['cts_plan'] ?? 'starter';
$is_imp     = !empty($_SESSION['is_impersonating']);
$imp_co     = $_SESSION['impersonated_company'] ?? '';

// Load client record
$cl = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM cts_clients WHERE id=$client_id LIMIT 1"));
if (!$cl) { $cl = ['company_name'=>'My Account','monthly_minutes'=>200,'credit_balance'=>0,'plan_type'=>$plan]; }

// ── AJAX handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── Get next lead for a campaign slot ─────────────────────
    if ($act === 'get_next_lead') {
        $campaign_id = (int)($_POST['campaign_id'] ?? 0);
        $agent_id    = (int)($_POST['agent_id']    ?? 0);
        $slot_index  = (int)($_POST['slot_index']  ?? 0);

        // Lock key: prevent two slots grabbing the same lead
        $lock_key = "slot_{$campaign_id}_{$agent_id}_{$slot_index}";
        $lock_esc = mysqli_real_escape_string($conn, $lock_key);

        $lead = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM cts_leads
             WHERE client_id=$client_id
               AND campaign_id=$campaign_id
               AND status='new'
               AND do_not_call=0
               AND (agent_lock IS NULL OR agent_lock < NOW() - INTERVAL 10 MINUTE)
             ORDER BY id ASC LIMIT 1"));

        if (!$lead) {
            // Also try retry leads
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

        // Lock this lead
        $lead_id = (int)$lead['id'];
        mysqli_query($conn,
            "UPDATE cts_leads SET agent_lock='$lock_esc', updated_at=NOW()
             WHERE id=$lead_id AND client_id=$client_id");

        echo json_encode(['success'=>true,'lead'=>$lead]);
        exit;
    }

    // ── Record call result ─────────────────────────────────────
    if ($act === 'record_call_result') {
        $lead_id      = (int)($_POST['lead_id']          ?? 0);
        $campaign_id  = (int)($_POST['campaign_id']      ?? 0);
        $agent_id     = (int)($_POST['agent_id']         ?? 0);
        $outcome      = mysqli_real_escape_string($conn, $_POST['outcome']      ?? '');
        $disposition  = mysqli_real_escape_string($conn, $_POST['disposition']  ?? '');
        $duration_min = round((float)($_POST['duration_minutes'] ?? 0), 4);
        $talk_secs    = (int)round($duration_min * 60);
        $lead_name    = mysqli_real_escape_string($conn, $_POST['lead_name']    ?? '');
        $lead_phone   = mysqli_real_escape_string($conn, $_POST['lead_phone']   ?? '');
        $notes_esc    = mysqli_real_escape_string($conn, $_POST['notes']        ?? '');
        $cost_per_min = 0.05;
        $total_cost   = round($duration_min * $cost_per_min, 4);

        // Map outcome → lead status
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

        // next_call_at for callbacks (2 hours out by default)
        $next_call_sql = ($outcome === 'callback')
            ? "next_call_at=DATE_ADD(NOW(), INTERVAL 2 HOUR),"
            : "next_call_at=NULL,";

        // do_not_call flag
        $dnc_sql = ($outcome === 'do_not_call') ? "do_not_call=1," : "";

        mysqli_query($conn,
            "UPDATE cts_leads
             SET status='$new_status',
                 disposition='$disposition',
                 outcome='$outcome',
                 $dnc_sql
                 $next_call_sql
                 attempts=attempts+1,
                 last_called_at=NOW(),
                 agent_lock=NULL,
                 updated_at=NOW()
             WHERE id=$lead_id AND client_id=$client_id");

        // Insert call log
        mysqli_query($conn,
            "INSERT INTO cts_call_log
             (client_id, campaign_id, agent_id, lead_id, lead_name, lead_phone,
              direction, talk_seconds, disposition, outcome, billed_minutes, total_cost, notes, initiated_at)
             VALUES
             ($client_id, $campaign_id, $agent_id, $lead_id,
              '$lead_name', '$lead_phone', 'outbound', $talk_secs,
              '$disposition', '$outcome', $duration_min, $total_cost, '$notes_esc', NOW())");

        // Campaign counters
        $appt_inc = ($outcome === 'appointment') ? 1 : 0;
        $ans_inc  = in_array($outcome, ['appointment','callback','not_interested']) ? 1 : 0;
        mysqli_query($conn,
            "UPDATE cts_campaigns
             SET leads_called=leads_called+1,
                 calls_answered=calls_answered+$ans_inc,
                 appointments_set=appointments_set+$appt_inc,
                 updated_at=NOW()
             WHERE id=$campaign_id AND client_id=$client_id");

        // Deduct credit
        if ($total_cost > 0) {
            mysqli_query($conn,
                "UPDATE cts_clients
                 SET credit_balance=credit_balance-$total_cost
                 WHERE id=$client_id AND credit_balance>=$total_cost");
        }

        $credit = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM cts_clients WHERE id=$client_id LIMIT 1"));
        $stats = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT leads_called, calls_answered, appointments_set, total_leads
             FROM cts_campaigns WHERE id=$campaign_id LIMIT 1"));

        echo json_encode([
            'success'        => true,
            'leads_called'   => (int)($stats['leads_called']    ?? 0),
            'calls_answered' => (int)($stats['calls_answered']  ?? 0),
            'appointments'   => (int)($stats['appointments_set']?? 0),
            'total_leads'    => (int)($stats['total_leads']     ?? 0),
            'credit_balance' => number_format((float)($credit['credit_balance'] ?? 0), 2),
            'cost_this_call' => $total_cost,
        ]);
        exit;
    }

    // ── Toggle campaign status ─────────────────────────────────
    if ($act === 'toggle_campaign') {
        $cid_camp  = (int)($_POST['campaign_id']    ?? 0);
        $cur       = $_POST['current_status']       ?? 'draft';
        $new       = ($cur === 'running') ? 'paused' : 'running';
        $ok = mysqli_query($conn,
            "UPDATE cts_campaigns SET status='$new', updated_at=NOW()
             WHERE id=$cid_camp AND client_id=$client_id");
        echo json_encode(['success'=>(bool)$ok,'new_status'=>$new]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

// ── Load campaigns ─────────────────────────────────────────────
$campaigns = [];
$q = mysqli_query($conn,
    "SELECT * FROM cts_campaigns
     WHERE client_id=$client_id AND status IN ('running','paused','draft')
     ORDER BY status='running' DESC, id DESC");
while ($r = mysqli_fetch_assoc($q)) $campaigns[] = $r;

// Load agents per campaign
$campaign_agents = [];
if (!empty($campaigns)) {
    $cids = implode(',', array_column($campaigns,'id'));
    $q = mysqli_query($conn,
        "SELECT ca.*, a.name AS agent_name, a.provider_agent_id AS vapi_agent_id
         FROM cts_campaign_agents ca
         JOIN cts_agents a ON a.id=ca.agent_id
         WHERE ca.campaign_id IN ($cids) AND ca.client_id=$client_id
           AND a.status='active'");
    while ($r = mysqli_fetch_assoc($q)) {
        $campaign_agents[(int)$r['campaign_id']][] = $r;
    }
}

// Minutes usage
$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(billed_minutes),0) m FROM cts_call_log
     WHERE client_id=$client_id AND MONTH(initiated_at)=MONTH(NOW())"));
$mins_used  = round((float)$r['m'], 1);
$mins_total = (int)($cl['monthly_minutes'] ?? 200);
$mins_pct   = $mins_total > 0 ? min(100, round($mins_used / $mins_total * 100)) : 0;

// Today's stats
$r = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c, COALESCE(SUM(billed_minutes),0) m
     FROM cts_call_log WHERE client_id=$client_id AND DATE(initiated_at)=CURDATE()"));
$calls_today = (int)$r['c'];
$mins_today  = round((float)$r['m'], 1);

function timeAgo($dt) {
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 60)    return 'Just now';
    if ($d < 3600)  return floor($d/60)  . 'm ago';
    if ($d < 86400) return floor($d/3600). 'h ago';
    return date('M j', strtotime($dt));
}
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
  --purple:#7c3aed;--purple-pale:#f5f3ff;
  --border:rgba(10,14,26,.08);
  --shadow:0 1px 4px rgba(10,14,26,.07);
  --shadow-md:0 4px 20px rgba(10,14,26,.10);
  --radius:14px;
  --ff-display:'Fraunces',Georgia,serif;
  --ff-body:'DM Sans',sans-serif;
  --sidebar-w:240px;
}
html{height:100%;}
body{font-family:var(--ff-body);background:var(--bg);color:var(--ink);min-height:100vh;display:flex;}

/* ── Sidebar ──────────────────────────────────────────────── */
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

/* ── Main ─────────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;min-width:0;}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 28px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;}
.tb-title{font-size:18px;font-weight:700;color:var(--ink);}
.tb-right{display:flex;gap:10px;align-items:center;}
.btn-primary{padding:9px 18px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary:hover{background:var(--teal-lt);}
.btn-ghost{padding:8px 16px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--ink-soft);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);text-decoration:none;display:inline-flex;align-items:center;}
.btn-ghost:hover{border-color:var(--teal);color:var(--teal);}
.page{padding:24px 28px;}

/* ── Credit topbar ────────────────────────────────────────── */
.credit-topbar{display:flex;align-items:center;gap:24px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 22px;margin-bottom:24px;flex-wrap:wrap;}
.credit-item{display:flex;flex-direction:column;gap:2px;}
.credit-label{font-size:10px;font-weight:700;color:var(--ink-mute);text-transform:uppercase;letter-spacing:.06em;}
.credit-val{font-size:20px;font-weight:700;color:var(--ink);}
.credit-val.green{color:var(--green);}
.credit-val.teal{color:var(--teal);}
.credit-div{width:1px;height:32px;background:var(--border);}

/* ── Campaign card ────────────────────────────────────────── */
.camp-card{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;margin-bottom:24px;box-shadow:var(--shadow);}
.camp-header{padding:18px 22px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;border-bottom:1px solid var(--border);}
.camp-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:3px;}
.camp-meta{font-size:12px;color:var(--ink-mute);}
.camp-header-right{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.sp-running{background:#dcfce7;color:#15803d;}
.sp-paused{background:#fef9c3;color:#854d0e;}
.sp-draft{background:#f1f5f9;color:#64748b;}
.sp-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}

/* ── Stats row ────────────────────────────────────────────── */
.camp-stats-row{display:grid;grid-template-columns:repeat(5,1fr);border-bottom:1px solid var(--border);}
.camp-stat-box{padding:14px 18px;text-align:center;border-right:1px solid var(--border);}
.camp-stat-box:last-child{border-right:none;}
.csb-val{font-size:22px;font-weight:700;color:var(--ink);line-height:1;}
.csb-lbl{font-size:11px;color:var(--ink-mute);margin-top:3px;}
.prog-bar{height:5px;background:var(--border);margin:0 22px 16px;border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--teal),var(--teal-lt));border-radius:99px;transition:width .6s ease;}

/* ── Agents section ───────────────────────────────────────── */
.agents-section{padding:20px 22px;}
.agents-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.agents-section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);}
.agents-bulk-btns{display:flex;gap:8px;}

/* ── Agent card ───────────────────────────────────────────── */
.agent-card{border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px;transition:border-color .2s,box-shadow .2s;}
.agent-card.is-running{border-color:var(--teal);box-shadow:0 0 0 3px rgba(26,122,110,.10);}
.agent-card-header{display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fafbfc;border-bottom:1px solid var(--border);}
.agent-av{width:38px;height:38px;border-radius:10px;background:var(--teal-pale);color:var(--teal);font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;}
.agent-av.pulse::after{content:'';position:absolute;inset:-3px;border-radius:13px;border:2px solid var(--teal);animation:pulse-ring 1.4s ease-out infinite;}
@keyframes pulse-ring{0%{opacity:1;transform:scale(1)}100%{opacity:0;transform:scale(1.3)}}
.agent-name{font-size:14px;font-weight:700;color:var(--ink);}
.agent-subtext{font-size:11px;color:var(--ink-mute);margin-top:1px;}
.agent-header-right{margin-left:auto;display:flex;gap:8px;align-items:center;}

/* ── Dialing slots grid ───────────────────────────────────── */
.slots-grid{display:grid;gap:12px;padding:16px;}
/* columns set dynamically via JS based on max_concurrent */

/* ── Single slot card ─────────────────────────────────────── */
.slot-card{border:1.5px solid var(--border);border-radius:10px;overflow:hidden;transition:border-color .25s,box-shadow .25s;}
.slot-card.slot-idle{border-color:var(--border);}
.slot-card.slot-dialing{border-color:var(--teal);box-shadow:0 0 0 3px rgba(26,122,110,.10);}
.slot-card.slot-done{border-color:#86efac;}
.slot-card.slot-error{border-color:#fca5a5;}

.slot-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fafbfc;border-bottom:1px solid var(--border);}
.slot-label{font-size:11px;font-weight:700;color:var(--ink-mute);text-transform:uppercase;letter-spacing:.06em;}
.slot-status-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;text-transform:uppercase;letter-spacing:.04em;}
.ssb-idle{background:#f1f5f9;color:#64748b;}
.ssb-dialing{background:var(--teal-pale);color:var(--teal);}
.ssb-done{background:var(--green-pale);color:var(--green);}
.ssb-error{background:var(--red-pale);color:var(--red);}

.slot-body{padding:14px;}

/* ── Lead info inside slot ────────────────────────────────── */
.slot-idle-msg{text-align:center;color:var(--ink-mute);font-size:12px;padding:18px 0;}
.slot-idle-msg .idle-icon{font-size:28px;display:block;margin-bottom:6px;}

.lead-info-card{background:linear-gradient(135deg,rgba(26,122,110,.05) 0%,rgba(34,160,144,.03) 100%);border:1px solid rgba(26,122,110,.15);border-radius:9px;padding:12px 14px;}
.lead-calling-bar{display:flex;align-items:center;gap:7px;margin-bottom:10px;}
.dot-blink{width:9px;height:9px;border-radius:50%;background:var(--teal);animation:blink 1s ease-in-out infinite;flex-shrink:0;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.calling-label{font-size:11px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.06em;}
.call-timer-badge{margin-left:auto;font-size:11px;font-weight:700;color:var(--teal);font-variant-numeric:tabular-nums;}

.lead-full-name{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:8px;}
.lead-fields{display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;}
.lead-field{display:flex;flex-direction:column;gap:1px;}
.lf-key{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);}
.lf-val{font-size:12px;font-weight:600;color:var(--ink-soft);}

/* ── Outcome display ──────────────────────────────────────── */
.outcome-card{margin-top:10px;border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:10px;}
.outcome-icon{font-size:20px;flex-shrink:0;}
.outcome-label{font-size:13px;font-weight:700;}
.outcome-sub{font-size:11px;opacity:.75;margin-top:1px;}
.oc-appointment{background:#dcfce7;color:#15803d;}
.oc-callback{background:var(--blue-pale);color:var(--blue);}
.oc-not_interested{background:#f1f5f9;color:#475569;}
.oc-voicemail{background:var(--gold-pale);color:var(--gold);}
.oc-invalid{background:var(--red-pale);color:var(--red);}
.oc-retry{background:var(--orange-pale);color:var(--orange);}
.oc-do_not_call{background:var(--red-pale);color:var(--red);}

/* ── Slot action buttons ──────────────────────────────────── */
.slot-actions{display:flex;gap:8px;margin-top:12px;}
.btn-next{flex:1;padding:9px;border:none;border-radius:9px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;display:flex;align-items:center;justify-content:center;gap:6px;}
.btn-next:hover{background:var(--teal-lt);}
.btn-next:disabled{opacity:.5;cursor:not-allowed;}
.btn-skip{padding:9px 14px;border:1.5px solid var(--border);border-radius:9px;background:#fff;color:var(--ink-mute);font-size:12px;font-weight:600;cursor:pointer;font-family:var(--ff-body);}
.btn-skip:hover{border-color:var(--ink-mute);color:var(--ink);}

/* ── Mini log ─────────────────────────────────────────────── */
.mini-log{border-top:1px solid var(--border);padding:10px 16px;}
.mini-log-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);margin-bottom:7px;}
.mini-log-item{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:11px;border-bottom:1px solid rgba(10,14,26,.04);}
.mini-log-item:last-child{border-bottom:none;}
.mli-name{flex:1;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mli-dur{color:var(--ink-mute);white-space:nowrap;font-size:10px;}
.mli-badge{padding:2px 7px;border-radius:5px;font-size:9px;font-weight:700;text-transform:uppercase;white-space:nowrap;}

/* ── Campaign/agent control buttons ───────────────────────── */
.btn-run{padding:8px 16px;border:none;border-radius:9px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;display:inline-flex;align-items:center;gap:5px;}
.btn-run:hover{background:var(--teal-lt);}
.btn-pause{padding:8px 16px;border:1.5px solid #fecaca;border-radius:9px;background:var(--red-pale);color:var(--red);font-size:12px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:all .15s;display:inline-flex;align-items:center;gap:5px;}
.btn-pause:hover{background:#fecaca;}
.btn-sm{padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--ff-body);border:1.5px solid var(--border);background:#fff;color:var(--ink-soft);transition:all .15s;}
.btn-sm:hover{border-color:var(--teal);color:var(--teal);}
.btn-sm.danger:hover{border-color:#fca5a5;color:var(--red);background:var(--red-pale);}

/* ── Empty / impersonation ────────────────────────────────── */
.empty-state{text-align:center;padding:60px 24px;background:var(--card);border-radius:var(--radius);border:1px solid var(--border);}
.empty-icon{font-size:44px;margin-bottom:12px;}
.empty-title{font-size:18px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--ink-mute);margin-bottom:20px;}
.imp-bar{background:#fef3c7;border-bottom:2px solid #f59e0b;padding:8px 24px;display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:600;color:#92400e;}
.imp-bar a{color:#92400e;text-decoration:none;font-weight:700;}

/* ── Toast ────────────────────────────────────────────────── */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 22px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap;}

@media(max-width:900px){.camp-stats-row{grid-template-columns:repeat(3,1fr);}.slots-grid{grid-template-columns:1fr!important;}}
@media(max-width:768px){.sidebar{display:none;}.main{margin-left:0;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<aside class="sidebar">
  <a href="dashboard.php" class="sb-logo">
    <div class="sb-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
        <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
      </svg>
    </div>
    <span class="sb-logo-name">Call<em>Mind</em> AI</span>
  </a>
  <div class="sb-co" title="<?= htmlspecialchars($cl['company_name']) ?>"><?= htmlspecialchars($cl['company_name']) ?></div>
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

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">

  <?php if ($is_imp): ?>
  <div class="imp-bar">
    <span>🔑 Viewing as <strong><?= htmlspecialchars($imp_co) ?></strong></span>
    <a href="admin/dashboard.php">← Back to Admin</a>
  </div>
  <?php endif; ?>

  <div class="topbar">
    <div class="tb-title">🟢 Active Campaigns</div>
    <div class="tb-right">
      <a href="dashboard.php" class="btn-ghost">← Dashboard</a>
    </div>
  </div>

  <div class="page">

    <!-- Global stats bar -->
    <div class="credit-topbar">
      <div class="credit-item">
        <div class="credit-label">Credit Balance</div>
        <div class="credit-val green" id="global-credit">$<?= number_format((float)$cl['credit_balance'],2) ?></div>
      </div>
      <div class="credit-div"></div>
      <div class="credit-item">
        <div class="credit-label">Calls Today</div>
        <div class="credit-val teal" id="global-calls-today"><?= $calls_today ?></div>
      </div>
      <div class="credit-div"></div>
      <div class="credit-item">
        <div class="credit-label">Minutes Today</div>
        <div class="credit-val" id="global-mins-today"><?= $mins_today ?></div>
      </div>
      <div class="credit-div"></div>
      <div class="credit-item">
        <div class="credit-label">Active Slots</div>
        <div class="credit-val" id="global-active-slots">0</div>
      </div>
    </div>

    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
      <div class="empty-icon">📣</div>
      <div class="empty-title">No campaigns yet</div>
      <div class="empty-sub">Create a campaign from the dashboard and upload leads to get started.</div>
      <a href="dashboard.php" class="btn-primary" style="text-decoration:none;">← Go to Dashboard</a>
    </div>

    <?php else: ?>

    <?php foreach ($campaigns as $camp):
      $cid         = (int)$camp['id'];
      $max_conc    = max(1, (int)($camp['max_concurrent'] ?? 1));
      $pct         = $camp['total_leads'] > 0
                     ? min(100, round($camp['leads_called'] / $camp['total_leads'] * 100))
                     : 0;
      $camp_agents = $campaign_agents[$cid] ?? [];
    ?>
    <div class="camp-card" id="camp-<?= $cid ?>">

      <!-- Campaign header -->
      <div class="camp-header">
        <div>
          <div class="camp-title"><?= htmlspecialchars($camp['name']) ?></div>
          <div class="camp-meta">
            <?= ucfirst($camp['direction'] ?? 'outbound') ?> ·
            Max <?= $max_conc ?> concurrent slot<?= $max_conc > 1 ? 's' : '' ?> per agent
            <?php if ($camp['calling_time_from'] ?? ''): ?>
              · <?= $camp['calling_time_from'] ?> – <?= $camp['calling_time_to'] ?> <?= $camp['timezone'] ?? '' ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="camp-header-right">
          <span class="status-pill sp-<?= $camp['status'] ?>" id="camp-pill-<?= $cid ?>">
            <span class="sp-dot"></span><?= ucfirst($camp['status']) ?>
          </span>
          <?php if ($camp['status'] === 'running'): ?>
            <button class="btn-pause" onclick="toggleCampaign(<?= $cid ?>,'running')">⏸ Pause</button>
          <?php else: ?>
            <button class="btn-run" onclick="toggleCampaign(<?= $cid ?>,'<?= $camp['status'] ?>')">▶ Resume</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats -->
      <div class="camp-stats-row">
        <div class="camp-stat-box">
          <div class="csb-val"><?= (int)$camp['total_leads'] ?></div>
          <div class="csb-lbl">Total Leads</div>
        </div>
        <div class="camp-stat-box">
          <div class="csb-val" id="stat-called-<?= $cid ?>"><?= (int)$camp['leads_called'] ?></div>
          <div class="csb-lbl">Called</div>
        </div>
        <div class="camp-stat-box">
          <div class="csb-val" id="stat-answered-<?= $cid ?>"><?= (int)$camp['calls_answered'] ?></div>
          <div class="csb-lbl">Answered</div>
        </div>
        <div class="camp-stat-box">
          <div class="csb-val" id="stat-appts-<?= $cid ?>"><?= (int)$camp['appointments_set'] ?></div>
          <div class="csb-lbl">Booked</div>
        </div>
        <div class="camp-stat-box">
          <div class="csb-val" id="stat-pct-<?= $cid ?>"><?= $pct ?>%</div>
          <div class="csb-lbl">Complete</div>
        </div>
      </div>
      <div class="prog-bar">
        <div class="prog-fill" id="prog-<?= $cid ?>" style="width:<?= $pct ?>%"></div>
      </div>

      <!-- Agents -->
      <div class="agents-section">
        <?php if (empty($camp_agents)): ?>
          <div style="color:var(--ink-mute);font-size:13px;padding:8px 0;">
            No active agents assigned to this campaign.
            <a href="campaigns.php" style="color:var(--teal);">Edit campaign</a> to assign agents.
          </div>
        <?php else: ?>
        <?php foreach ($camp_agents as $ag):
          $ag_id   = (int)$ag['agent_id'];
          $ag_init = strtoupper(substr($ag['agent_name'], 0, 2));
        ?>
        <div class="agent-card" id="agent-<?= $cid ?>-<?= $ag_id ?>">

          <!-- Agent header -->
          <div class="agent-card-header">
            <div class="agent-av" id="agent-av-<?= $cid ?>-<?= $ag_id ?>"><?= $ag_init ?></div>
            <div>
              <div class="agent-name"><?= htmlspecialchars($ag['agent_name']) ?></div>
              <div class="agent-subtext" id="agent-subtext-<?= $cid ?>-<?= $ag_id ?>">
                Ready · <?= $max_conc ?> slot<?= $max_conc > 1 ? 's' : '' ?>
              </div>
            </div>
            <div class="agent-header-right">
              <button class="btn-sm danger" id="btn-stop-<?= $cid ?>-<?= $ag_id ?>"
                onclick="stopAgent(<?= $cid ?>,<?= $ag_id ?>)" style="display:none;">
                ⏹ Stop Agent
              </button>
              <button class="btn-run" id="btn-start-<?= $cid ?>-<?= $ag_id ?>"
                onclick="startAgent(<?= $cid ?>,<?= $ag_id ?>,'<?= addslashes($ag['agent_name']) ?>','<?= addslashes($ag['vapi_agent_id'] ?? '') ?>', <?= $max_conc ?>)">
                ▶ Start Agent
              </button>
            </div>
          </div>

          <!-- Dialing slots grid (built by JS) -->
          <div class="slots-grid" id="slots-<?= $cid ?>-<?= $ag_id ?>"
               style="grid-template-columns: repeat(<?= min($max_conc,4) ?>, 1fr);">
            <!-- JS will render slot cards here -->
          </div>

          <!-- Mini log -->
          <div class="mini-log" id="minilog-<?= $cid ?>-<?= $ag_id ?>" style="display:none;">
            <div class="mini-log-title">Recent Calls</div>
            <div id="minilog-items-<?= $cid ?>-<?= $ag_id ?>"></div>
          </div>

        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div><!-- /page -->
</div><!-- /main -->
<div class="toast" id="toast"></div>

<script>
// ══════════════════════════════════════════════════════════════
// OUTCOME CONFIG
// ══════════════════════════════════════════════════════════════
const OUTCOMES = {
  appointment:    { label:'✅ Appointment Booked', sub:'Lead booked a meeting',       css:'oc-appointment',    mli:'🟢', disposition:'answered' },
  callback:       { label:'📅 Callback Requested', sub:'Call back later',             css:'oc-callback',       mli:'🔵', disposition:'answered' },
  not_interested: { label:'👎 Not Interested',     sub:'Lead declined',               css:'oc-not_interested', mli:'⚫', disposition:'answered' },
  voicemail:      { label:'📬 Answering Machine',  sub:'Left voicemail',              css:'oc-voicemail',      mli:'🟡', disposition:'no_answer' },
  invalid:        { label:'❌ Invalid Number',     sub:'Wrong/disconnected number',   css:'oc-invalid',        mli:'❌', disposition:'failed'    },
  retry:          { label:'🔄 No Answer / Busy',   sub:'Will retry automatically',    css:'oc-retry',          mli:'🔁', disposition:'no_answer' },
  do_not_call:    { label:'🚫 Do Not Call',        sub:'Added to DNC list',           css:'oc-do_not_call',    mli:'🚫', disposition:'failed'    },
};

// ══════════════════════════════════════════════════════════════
// DUMMY VAPI FUNCTION
// Replace this with your real VAPI endpoint when ready.
// Receives: vapiAgentId (string), lead (object)
// Returns:  { outcome, disposition, duration_minutes, notes }
// ══════════════════════════════════════════════════════════════
async function callVapi(vapiAgentId, lead) {
  // Simulate network delay (3–6 seconds like a real call attempt)
  const delay = 3000 + Math.random() * 3000;
  await sleep(delay);

  // Weighted random outcome distribution
  const outcomes = [
    { o:'appointment',    weight: 8  },
    { o:'callback',       weight: 15 },
    { o:'not_interested', weight: 20 },
    { o:'voicemail',      weight: 25 },
    { o:'retry',          weight: 22 },
    { o:'invalid',        weight: 7  },
    { o:'do_not_call',    weight: 3  },
  ];
  const total  = outcomes.reduce((s,x) => s + x.weight, 0);
  let rand     = Math.random() * total;
  let picked   = outcomes[outcomes.length - 1].o;
  for (const {o, weight} of outcomes) {
    rand -= weight;
    if (rand <= 0) { picked = o; break; }
  }

  const duration = parseFloat((0.5 + Math.random() * 4).toFixed(2));
  const cfg      = OUTCOMES[picked];

  /* ── REAL VAPI INTEGRATION (replace above with this when ready) ──
  const response = await fetch('/api/vapi_call.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      vapi_agent_id: vapiAgentId,
      lead: {
        id:        lead.id,
        firstname: lead.firstname,
        lastname:  lead.lastname,
        phone:     lead.phone_e164 || lead.phone,
        email:     lead.email   || '',
        city:      lead.city    || '',
        state:     lead.state   || '',
        zip:       lead.zip     || '',
      }
    })
  });
  if (!response.ok) throw new Error('VAPI error ' + response.status);
  return await response.json();
  // Expected response shape: { outcome, disposition, duration_minutes, notes }
  ────────────────────────────────────────────────────────────── */

  return {
    outcome:          picked,
    disposition:      cfg.disposition,
    duration_minutes: duration,
    notes:            `[DUMMY] ${cfg.label} — ${duration}m`,
  };
}

// ══════════════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════════════
// agentRunning[`${cid}_${aid}`] = true/false
const agentRunning = {};
// slotState[`${cid}_${aid}_${slotIdx}`] = 'idle'|'dialing'|'done'|'stopped'
const slotState = {};

// ══════════════════════════════════════════════════════════════
// START / STOP AGENT
// ══════════════════════════════════════════════════════════════
function startAgent(cid, aid, agentName, vapiAgentId, maxConc) {
  const key = `${cid}_${aid}`;
  if (agentRunning[key]) return;
  agentRunning[key] = true;

  // UI
  document.getElementById(`btn-start-${cid}-${aid}`).style.display = 'none';
  document.getElementById(`btn-stop-${cid}-${aid}`).style.display  = '';
  const agCard = document.getElementById(`agent-${cid}-${aid}`);
  agCard.classList.add('is-running');
  const av = document.getElementById(`agent-av-${cid}-${aid}`);
  av.classList.add('pulse');
  setSubtext(cid, aid, `Running · ${maxConc} slot${maxConc>1?'s':''} active`);

  // Build slot cards
  buildSlotCards(cid, aid, maxConc);

  // Launch each slot independently
  for (let s = 0; s < maxConc; s++) {
    runSlot(cid, aid, agentName, vapiAgentId, s, maxConc);
  }

  updateActiveSlotCount();
}

function stopAgent(cid, aid) {
  const key = `${cid}_${aid}`;
  agentRunning[key] = false;

  document.getElementById(`btn-start-${cid}-${aid}`).style.display = '';
  document.getElementById(`btn-stop-${cid}-${aid}`).style.display  = 'none';
  const agCard = document.getElementById(`agent-${cid}-${aid}`);
  agCard.classList.remove('is-running');
  document.getElementById(`agent-av-${cid}-${aid}`).classList.remove('pulse');
  setSubtext(cid, aid, 'Stopped — click Start to resume');
  updateActiveSlotCount();
  showToast('⏹ Agent stopped');
}

// ══════════════════════════════════════════════════════════════
// SLOT CARD BUILDER
// ══════════════════════════════════════════════════════════════
function buildSlotCards(cid, aid, maxConc) {
  const grid = document.getElementById(`slots-${cid}-${aid}`);
  grid.innerHTML = '';
  for (let s = 0; s < maxConc; s++) {
    const card = document.createElement('div');
    card.className = 'slot-card slot-idle';
    card.id = `slot-${cid}-${aid}-${s}`;
    card.innerHTML = slotIdleHTML(s + 1);
    grid.appendChild(card);
  }
}

function slotIdleHTML(num) {
  return `
    <div class="slot-header">
      <span class="slot-label">Slot ${num}</span>
      <span class="slot-status-badge ssb-idle">Idle</span>
    </div>
    <div class="slot-body">
      <div class="slot-idle-msg">
        <span class="idle-icon">📞</span>
        Waiting to dial…
      </div>
    </div>`;
}

// ══════════════════════════════════════════════════════════════
// SLOT LOOP
// ══════════════════════════════════════════════════════════════
async function runSlot(cid, aid, agentName, vapiAgentId, slotIdx, maxConc) {
  const agKey    = `${cid}_${aid}`;
  const slotNum  = slotIdx + 1;

  while (agentRunning[agKey]) {

    // 1. Fetch next lead
    setSlotStatus(cid, aid, slotIdx, slotNum, 'dialing', null, 'Fetching next lead…');
    let leadResp;
    try {
      leadResp = await apiPost({
        ajax_action: 'get_next_lead',
        campaign_id: cid,
        agent_id:    aid,
        slot_index:  slotIdx,
      });
    } catch(e) {
      setSlotStatus(cid, aid, slotIdx, slotNum, 'error', null, '⚠ Network error');
      await sleep(5000);
      continue;
    }

    if (!leadResp.success) {
      // No more leads
      setSlotStatus(cid, aid, slotIdx, slotNum, 'done', null, '✅ All leads called');
      setSlotBodyHTML(cid, aid, slotIdx, `
        <div class="slot-idle-msg">
          <span class="idle-icon">✅</span>
          All leads called for this slot
        </div>`);
      // Stop this slot — if all slots done, stop agent
      agentRunning[agKey] = false;
      checkAllSlotsDone(cid, aid, maxConc, agentName);
      return;
    }

    const lead = leadResp.lead;

    // 2. Show dialing UI
    renderDialingUI(cid, aid, slotIdx, slotNum, lead);
    startSlotTimer(cid, aid, slotIdx);

    // 3. Call VAPI (dummy)
    let result;
    try {
      result = await callVapi(vapiAgentId, lead);
    } catch(e) {
      result = { outcome:'retry', disposition:'failed', duration_minutes:0, notes:'Call error: '+e.message };
    }
    stopSlotTimer(cid, aid, slotIdx);

    // 4. Show outcome in slot
    renderOutcomeUI(cid, aid, slotIdx, slotNum, lead, result);
    addToMiniLog(cid, aid, lead, result);

    // 5. Save to DB
    let statsResp;
    try {
      statsResp = await apiPost({
        ajax_action:      'record_call_result',
        lead_id:          lead.id,
        campaign_id:      cid,
        agent_id:         aid,
        outcome:          result.outcome,
        disposition:      result.disposition,
        duration_minutes: result.duration_minutes,
        notes:            result.notes || '',
        lead_name:        lead.firstname + ' ' + lead.lastname,
        lead_phone:       lead.phone,
      });
      if (statsResp?.success) {
        updateCampaignStats(cid, statsResp);
        document.getElementById('global-credit').textContent = '$' + statsResp.credit_balance;
        incrementGlobalCalls();
      }
    } catch(e) { /* non-fatal */ }

    // 6. Wait for manual "Next" click — loop pauses here
    await waitForNext(cid, aid, slotIdx);

    // If agent was stopped while waiting
    if (!agentRunning[agKey]) return;
  }

  // Agent stopped — reset slot to idle
  setSlotToIdle(cid, aid, slotIdx, slotNum);
  updateActiveSlotCount();
}

// ══════════════════════════════════════════════════════════════
// MANUAL "NEXT" MECHANISM
// ══════════════════════════════════════════════════════════════
const nextResolvers = {}; // slotKey → resolve fn

function waitForNext(cid, aid, slotIdx) {
  return new Promise(resolve => {
    nextResolvers[`${cid}_${aid}_${slotIdx}`] = resolve;
  });
}

function clickNext(cid, aid, slotIdx) {
  const key = `${cid}_${aid}_${slotIdx}`;
  if (nextResolvers[key]) {
    nextResolvers[key]();
    delete nextResolvers[key];
  }
}

// ══════════════════════════════════════════════════════════════
// SLOT UI RENDERERS
// ══════════════════════════════════════════════════════════════
function renderDialingUI(cid, aid, slotIdx, slotNum, lead) {
  const card = document.getElementById(`slot-${cid}-${aid}-${slotIdx}`);
  if (!card) return;
  card.className = 'slot-card slot-dialing';

  const fields = [
    ['Phone',    lead.phone     || '—'],
    ['City',     lead.city      || '—'],
    ['Province', lead.state     || '—'],
    ['Email',    lead.email     || '—'],
    ['Zip',      lead.zip       || '—'],
    ['Type',     lead.lead_type || '—'],
  ].filter(([,v]) => v && v !== '—').slice(0, 6);

  const fieldsHTML = fields.map(([k,v]) =>
    `<div class="lead-field">
       <span class="lf-key">${k}</span>
       <span class="lf-val">${escHtml(v)}</span>
     </div>`).join('');

  card.innerHTML = `
    <div class="slot-header">
      <span class="slot-label">Slot ${slotNum}</span>
      <span class="slot-status-badge ssb-dialing">
        <span class="dot-blink" style="width:7px;height:7px;display:inline-block;border-radius:50%;background:currentColor;animation:blink 1s ease-in-out infinite;"></span>
        Dialing
      </span>
    </div>
    <div class="slot-body">
      <div class="lead-info-card">
        <div class="lead-calling-bar">
          <span class="dot-blink"></span>
          <span class="calling-label">Dialing</span>
          <span class="call-timer-badge" id="timer-${cid}-${aid}-${slotIdx}">0:00</span>
        </div>
        <div class="lead-full-name">${escHtml(lead.firstname)} ${escHtml(lead.lastname)}</div>
        <div class="lead-fields">${fieldsHTML}</div>
      </div>
    </div>`;
}

function renderOutcomeUI(cid, aid, slotIdx, slotNum, lead, result) {
  const card = document.getElementById(`slot-${cid}-${aid}-${slotIdx}`);
  if (!card) return;
  card.className = 'slot-card slot-done';
  const cfg = OUTCOMES[result.outcome] || OUTCOMES['retry'];

  const fields = [
    ['Phone',    lead.phone     || '—'],
    ['City',     lead.city      || '—'],
    ['Province', lead.state     || '—'],
    ['Email',    lead.email     || '—'],
  ].filter(([,v]) => v && v !== '—').slice(0, 4);

  const fieldsHTML = fields.map(([k,v]) =>
    `<div class="lead-field">
       <span class="lf-key">${k}</span>
       <span class="lf-val">${escHtml(v)}</span>
     </div>`).join('');

  card.innerHTML = `
    <div class="slot-header">
      <span class="slot-label">Slot ${slotNum}</span>
      <span class="slot-status-badge ssb-done">Done</span>
    </div>
    <div class="slot-body">
      <div class="lead-info-card" style="background:transparent;border-color:var(--border);">
        <div class="lead-full-name" style="font-size:14px;">${escHtml(lead.firstname)} ${escHtml(lead.lastname)}</div>
        <div class="lead-fields" style="margin-bottom:4px;">${fieldsHTML}</div>
        <div style="font-size:10px;color:var(--ink-mute);">Duration: ${result.duration_minutes}m</div>
      </div>
      <div class="outcome-card ${cfg.css}">
        <div>
          <div class="outcome-label">${cfg.label}</div>
          <div class="outcome-sub">${cfg.sub}</div>
        </div>
      </div>
      <div class="slot-actions">
        <button class="btn-next" onclick="clickNext(${cid},${aid},${slotIdx})">
          ▶ Next Lead
        </button>
      </div>
    </div>`;
}

function setSlotToIdle(cid, aid, slotIdx, slotNum) {
  const card = document.getElementById(`slot-${cid}-${aid}-${slotIdx}`);
  if (!card) return;
  card.className = 'slot-card slot-idle';
  card.innerHTML = slotIdleHTML(slotNum);
}

function setSlotStatus(cid, aid, slotIdx, slotNum, mode, lead, msg) {
  // Simple status setter for transient states (fetching, error)
  const card = document.getElementById(`slot-${cid}-${aid}-${slotIdx}`);
  if (!card) return;
  const badgeCss = mode === 'error' ? 'ssb-error' : 'ssb-dialing';
  card.className = `slot-card slot-${mode === 'error' ? 'error' : 'dialing'}`;
  card.innerHTML = `
    <div class="slot-header">
      <span class="slot-label">Slot ${slotNum}</span>
      <span class="slot-status-badge ${badgeCss}">${msg}</span>
    </div>
    <div class="slot-body">
      <div class="slot-idle-msg">${msg}</div>
    </div>`;
}

function setSlotBodyHTML(cid, aid, slotIdx, html) {
  const card = document.getElementById(`slot-${cid}-${aid}-${slotIdx}`);
  if (!card) return;
  const body = card.querySelector('.slot-body');
  if (body) body.innerHTML = html;
}

// ══════════════════════════════════════════════════════════════
// CALL TIMER
// ══════════════════════════════════════════════════════════════
const timers = {};
function startSlotTimer(cid, aid, slotIdx) {
  const key   = `${cid}_${aid}_${slotIdx}`;
  const start = Date.now();
  timers[key] = setInterval(() => {
    const el = document.getElementById(`timer-${cid}-${aid}-${slotIdx}`);
    if (!el) { clearInterval(timers[key]); return; }
    const s   = Math.floor((Date.now() - start) / 1000);
    const mm  = String(Math.floor(s / 60)).padStart(1,'0');
    const ss  = String(s % 60).padStart(2,'0');
    el.textContent = `${mm}:${ss}`;
  }, 500);
}
function stopSlotTimer(cid, aid, slotIdx) {
  const key = `${cid}_${aid}_${slotIdx}`;
  if (timers[key]) { clearInterval(timers[key]); delete timers[key]; }
}

// ══════════════════════════════════════════════════════════════
// MINI LOG
// ══════════════════════════════════════════════════════════════
function addToMiniLog(cid, aid, lead, result) {
  const logEl   = document.getElementById(`minilog-${cid}-${aid}`);
  const itemsEl = document.getElementById(`minilog-items-${cid}-${aid}`);
  if (!logEl || !itemsEl) return;
  logEl.style.display = '';
  const cfg  = OUTCOMES[result.outcome] || OUTCOMES['retry'];
  const item = document.createElement('div');
  item.className = 'mini-log-item';
  item.innerHTML = `
    <span class="mli-name">${escHtml(lead.firstname)} ${escHtml(lead.lastname)}</span>
    <span class="mli-dur">${result.duration_minutes}m</span>
    <span class="mli-badge ${cfg.css}">${cfg.mli} ${result.outcome.replace('_',' ')}</span>`;
  itemsEl.prepend(item);
  while (itemsEl.children.length > 8) itemsEl.removeChild(itemsEl.lastChild);
}

// ══════════════════════════════════════════════════════════════
// CAMPAIGN STATS UPDATE
// ══════════════════════════════════════════════════════════════
function updateCampaignStats(cid, s) {
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  set(`stat-called-${cid}`,   s.leads_called);
  set(`stat-answered-${cid}`, s.calls_answered);
  set(`stat-appts-${cid}`,    s.appointments);
  const total = parseInt(document.querySelector(`#camp-${cid} .csb-val`)?.textContent || '0');
  const pct   = total > 0 ? Math.min(100, Math.round(s.leads_called / total * 100)) : 0;
  set(`stat-pct-${cid}`, pct + '%');
  const prog = document.getElementById(`prog-${cid}`);
  if (prog) prog.style.width = pct + '%';
}

function incrementGlobalCalls() {
  const el = document.getElementById('global-calls-today');
  if (el) el.textContent = parseInt(el.textContent || '0') + 1;
}

function updateActiveSlotCount() {
  const active = Object.values(agentRunning).filter(Boolean).length;
  const el = document.getElementById('global-active-slots');
  if (el) el.textContent = active;
}

function checkAllSlotsDone(cid, aid, maxConc, agentName) {
  // Stop agent cleanly
  setTimeout(() => stopAgent(cid, aid), 800);
  showToast(`✅ ${agentName}: All leads called!`);
}

// ══════════════════════════════════════════════════════════════
// CAMPAIGN TOGGLE
// ══════════════════════════════════════════════════════════════
async function toggleCampaign(cid, curStatus) {
  const d = await apiPost({ ajax_action:'toggle_campaign', campaign_id:cid, current_status:curStatus });
  if (d.success) {
    showToast(d.new_status === 'running' ? '▶ Campaign resumed' : '⏸ Campaign paused');
    setTimeout(() => location.reload(), 700);
  } else { showToast('⚠ Error toggling campaign'); }
}

// ══════════════════════════════════════════════════════════════
// UTILITIES
// ══════════════════════════════════════════════════════════════
function setSubtext(cid, aid, txt) {
  const el = document.getElementById(`agent-subtext-${cid}-${aid}`);
  if (el) el.textContent = txt;
}

async function apiPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k, String(v)));
  const r = await fetch(location.href, { method:'POST', body:fd });
  return r.json();
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function escHtml(s) {
  if (!s) return '';
  return String(s)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}

function showToast(msg, dur=2800) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(() => t.style.opacity = '0', dur);
}
</script>
</body>
</html>

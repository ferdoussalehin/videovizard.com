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

require_once __DIR__ . '/../dbconnect.php';

$uid       = (int)$_SESSION['cts_user_id'];
$client_id = (int)($_SESSION['cts_client_id'] ?? 0);
$firstname = $_SESSION['cts_firstname'] ?? 'Admin';
$lastname  = $_SESSION['cts_lastname']  ?? '';
$fullname  = trim($firstname . ' ' . $lastname);
$initials  = strtoupper(substr($firstname,0,1) . substr($lastname,0,1));
$plan      = $_SESSION['cts_plan'] ?? 'admin';
$is_imp    = !empty($_SESSION['is_impersonating']);
$imp_co    = $_SESSION['impersonated_company'] ?? '';

// ── AJAX handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── Save (insert or update) agent ─────────────────────────
    if ($act === 'save_agent') {
        $agent_id         = (int)($_POST['agent_id'] ?? 0);
        $name             = mysqli_real_escape_string($conn, trim($_POST['name']               ?? ''));
        $provider         = mysqli_real_escape_string($conn, trim($_POST['provider']           ?? 'vapi'));
        $provider_agent_id= mysqli_real_escape_string($conn, trim($_POST['provider_agent_id'] ?? ''));
        $voice            = mysqli_real_escape_string($conn, trim($_POST['voice']              ?? ''));
        $language         = mysqli_real_escape_string($conn, trim($_POST['language']           ?? 'en-US'));
        $phone_number     = mysqli_real_escape_string($conn, trim($_POST['phone_number']       ?? ''));
        $status           = in_array($_POST['status'] ?? 'active', ['active','inactive','maintenance']) ? $_POST['status'] : 'active';
        $script_id        = (int)($_POST['script_id'] ?? 0);
        // client_id: admin can assign to a client, or leave 0 for pool
        $assign_client    = (int)($_POST['assign_client_id'] ?? 0);

        if (empty($name)) {
            echo json_encode(['success'=>false,'error'=>'Agent name is required']); exit;
        }

        if ($agent_id) {
            // Update
            $ok = mysqli_query($conn,
                "UPDATE cts_agents SET
                    name='$name', provider='$provider', provider_agent_id=" . ($provider_agent_id ? "'$provider_agent_id'" : "NULL") . ",
                    voice='$voice', language='$language', phone_number=" . ($phone_number ? "'$phone_number'" : "NULL") . ",
                    status='$status', script_id=$script_id, client_id=$assign_client,
                    updated_at=NOW()
                 WHERE id=$agent_id");
        } else {
            // Insert
            $ok = mysqli_query($conn,
                "INSERT INTO cts_agents
                    (client_id, script_id, name, provider, provider_agent_id, voice, language, phone_number, status, total_calls, total_minutes, created_at, updated_at)
                 VALUES
                    ($assign_client, $script_id, '$name', '$provider',
                     " . ($provider_agent_id ? "'$provider_agent_id'" : "NULL") . ",
                     '$voice', '$language',
                     " . ($phone_number ? "'$phone_number'" : "NULL") . ",
                     '$status', 0, 0.00, NOW(), NOW())");
            $agent_id = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok, 'agent_id'=>$agent_id,
            'error'=> $ok ? null : mysqli_error($conn)]); exit;
    }

    // ── Get single agent for editing ──────────────────────────
    if ($act === 'get_agent') {
        $agent_id = (int)($_POST['agent_id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM cts_agents WHERE id=$agent_id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row, 'agent'=>$row]); exit;
    }

    // ── Toggle agent status ───────────────────────────────────
    if ($act === 'toggle_status') {
        $agent_id = (int)($_POST['agent_id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        $ok = mysqli_query($conn,
            "UPDATE cts_agents SET status='$new_status', updated_at=NOW() WHERE id=$agent_id");
        echo json_encode(['success'=>(bool)$ok, 'new_status'=>$new_status]); exit;
    }

    // ── Delete agent ──────────────────────────────────────────
    if ($act === 'delete_agent') {
        $agent_id = (int)($_POST['agent_id'] ?? 0);
        $ok = mysqli_query($conn, "DELETE FROM cts_agents WHERE id=$agent_id");
        echo json_encode(['success'=>(bool)$ok]); exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
}

// ── Load data ──────────────────────────────────────────────────
$agents = [];
$q = mysqli_query($conn,
    "SELECT a.*, c.company_name
     FROM cts_agents a
     LEFT JOIN cts_clients c ON c.id = a.client_id
     ORDER BY a.id DESC");
while ($r = mysqli_fetch_assoc($q)) $agents[] = $r;

// All clients for the assign dropdown
$clients = [];
$q = mysqli_query($conn, "SELECT id, company_name FROM cts_clients ORDER BY company_name");
while ($r = mysqli_fetch_assoc($q)) $clients[] = $r;

// Stats
$total_agents    = count($agents);
$active_agents   = count(array_filter($agents, fn($a) => $a['status'] === 'active'));
$total_calls_all = array_sum(array_column($agents, 'total_calls'));
$total_mins_all  = array_sum(array_column($agents, 'total_minutes'));

function timeAgo($dt){
    if(!$dt) return '—';
    $d = time()-strtotime($dt);
    if($d<60)  return 'Just now';
    if($d<3600)return floor($d/60).'m ago';
    if($d<86400)return floor($d/3600).'h ago';
    return date('M j, Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agents — CallMind AI Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700;1,9..144,300&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --ink:#0a0e1a;--ink-soft:#3d4460;--ink-mute:#7a8099;
  --bg:#f0f2f7;--card:#fff;
  --teal:#1a7a6e;--teal-lt:#22a090;--teal-pale:#d4efec;
  --gold:#c8973a;--gold-pale:rgba(200,151,58,.12);
  --red:#dc2626;--red-pale:#fef2f2;
  --green:#16a34a;--green-pale:#f0fdf4;
  --blue:#2563eb;--blue-pale:#eff6ff;
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

/* ── Sidebar ─────────────────────────────────────────────── */
.sidebar{width:var(--sidebar-w);background:var(--ink);min-height:100vh;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:500;}
.sb-logo{padding:22px 20px 18px;display:flex;align-items:center;gap:10px;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.06);}
.sb-logo-mark{width:34px;height:34px;border-radius:9px;background:var(--teal);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo-mark svg{width:18px;height:18px;}
.sb-logo-name{font-family:var(--ff-display);font-size:17px;font-weight:700;color:#fff;letter-spacing:-.02em;}
.sb-logo-name em{color:#22a090;font-style:normal;}
.sb-co{padding:12px 20px;font-size:11px;font-weight:600;color:rgba(255,255,255,.35);letter-spacing:.04em;text-transform:uppercase;border-bottom:1px solid rgba(255,255,255,.06);}
.sb-section{padding:16px 12px 6px;}
.sb-lbl{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.22);padding:0 8px;margin-bottom:5px;}
.sb-nav{list-style:none;display:flex;flex-direction:column;gap:2px;}
.sb-nav a{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:10px;font-size:13px;font-weight:500;color:rgba(255,255,255,.5);text-decoration:none;transition:all .15s;}
.sb-nav a:hover{background:rgba(255,255,255,.06);color:#fff;}
.sb-nav a.active{background:var(--teal);color:#fff;font-weight:600;}
.sb-nav a .ico{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.sb-bottom{margin-top:auto;padding:16px 12px;border-top:1px solid rgba(255,255,255,.06);}
.sb-user{display:flex;align-items:center;gap:9px;padding:10px 4px;margin-bottom:4px;}
.sb-av{width:32px;height:32px;border-radius:9px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-un{font-size:12px;font-weight:600;color:#fff;}
.sb-ur{font-size:10px;color:rgba(255,255,255,.35);}
.sb-logout{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:9px;font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;transition:all .15s;}
.sb-logout:hover{background:rgba(255,255,255,.06);color:#fff;}

/* ── Main ───────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;min-width:0;}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 28px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;}
.tb-title{font-size:18px;font-weight:700;color:var(--ink);}
.tb-right{display:flex;gap:10px;align-items:center;}
.btn-primary{padding:9px 18px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary:hover{background:var(--teal-lt);}
.btn-ghost{padding:8px 16px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--ink-soft);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);text-decoration:none;display:inline-flex;align-items:center;}
.btn-ghost:hover{border-color:var(--teal);color:var(--teal);}
.page{padding:24px 28px;}

/* ── Stat cards ─────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
.sc{background:var(--card);border-radius:var(--radius);padding:18px 20px;border:1px solid var(--border);display:flex;align-items:center;gap:14px;}
.sc-ico{font-size:26px;line-height:1;}
.sc-val{font-size:24px;font-weight:700;color:var(--ink);line-height:1;margin-bottom:2px;}
.sc-lbl{font-size:11px;color:var(--ink-mute);font-weight:600;text-transform:uppercase;letter-spacing:.04em;}

/* ── Search / filter bar ─────────────────────────────────── */
.filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:18px;}
.filter-bar input,.filter-bar select{padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;font-family:var(--ff-body);color:var(--ink);background:#fff;outline:none;transition:border-color .15s;}
.filter-bar input{flex:1;}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--teal);}
.filter-bar select{min-width:140px;}

/* ── Agents table ────────────────────────────────────────── */
.table-wrap{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;box-shadow:var(--shadow);}
.agents-table{width:100%;border-collapse:collapse;}
.agents-table thead tr{background:#fafbfc;border-bottom:2px solid var(--border);}
.agents-table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);white-space:nowrap;}
.agents-table td{padding:13px 16px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle;}
.agents-table tbody tr:last-child td{border-bottom:none;}
.agents-table tbody tr:hover{background:#fafbfc;}

/* agent identity cell */
.agent-identity{display:flex;align-items:center;gap:11px;}
.agent-av{width:36px;height:36px;border-radius:10px;background:var(--teal-pale);color:var(--teal);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.agent-av.prov-elevenlabs{background:var(--purple-pale);color:var(--purple);}
.agent-av.prov-openai{background:var(--blue-pale);color:var(--blue);}
.agent-name-cell{font-weight:700;color:var(--ink);font-size:13px;margin-bottom:1px;}
.agent-id-cell{font-size:10px;color:var(--ink-mute);font-family:monospace;}

/* status pill */
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.sp-active{background:#dcfce7;color:#15803d;}
.sp-inactive{background:#f1f5f9;color:#64748b;}
.sp-maintenance{background:var(--gold-pale);color:var(--gold);}
.sp-dot{width:5px;height:5px;border-radius:50%;background:currentColor;}

/* provider badge */
.prov-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;letter-spacing:.03em;}
.pb-vapi{background:#ede9fe;color:#5b21b6;}
.pb-elevenlabs{background:#fff7ed;color:#c2410c;}
.pb-openai{background:var(--blue-pale);color:var(--blue);}
.pb-twilio{background:#fce7f3;color:#be185d;}
.pb-other{background:#f1f5f9;color:#475569;}

/* action buttons */
.act-btn{padding:5px 11px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--ff-body);border:1.5px solid var(--border);background:#fff;color:var(--ink-soft);transition:all .15s;}
.act-btn:hover{border-color:var(--teal);color:var(--teal);background:var(--teal-pale);}
.act-btn.danger:hover{border-color:#fecaca;color:var(--red);background:var(--red-pale);}
.act-btns{display:flex;gap:6px;align-items:center;}

/* empty state */
.empty-state{text-align:center;padding:60px 24px;}
.empty-icon{font-size:44px;margin-bottom:12px;}
.empty-title{font-size:18px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--ink-mute);margin-bottom:20px;}

/* ── Modal ───────────────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(10,14,26,.45);display:flex;align-items:center;justify-content:center;z-index:800;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal{background:#fff;border-radius:18px;padding:28px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 20px 60px rgba(10,14,26,.18);}
.modal-close{position:absolute;top:16px;right:16px;width:30px;height:30px;border-radius:8px;border:none;background:var(--bg);color:var(--ink-mute);font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{background:var(--red-pale);color:var(--red);}
.modal-title{font-family:var(--ff-display);font-size:20px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.modal-sub{font-size:13px;color:var(--ink-mute);margin-bottom:22px;}
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;letter-spacing:.02em;}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 12px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:var(--ff-body);color:var(--ink);outline:none;transition:border-color .15s,box-shadow .15s;}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(26,122,110,.08);background:#fff;}
.fg-hint{font-size:11px;color:var(--ink-mute);margin-top:4px;}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fg-section{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);padding-bottom:8px;border-bottom:1px solid var(--border);margin:16px 0 14px;}
.modal-alert{padding:10px 14px;border-radius:9px;font-size:13px;margin-bottom:14px;display:none;}
.modal-alert.err{background:var(--red-pale);color:var(--red);border:1px solid #fecaca;}
.modal-alert.ok{background:var(--green-pale);color:var(--green);border:1px solid #86efac;}
.modal-footer{display:flex;gap:10px;margin-top:18px;}
.btn-cancel{flex:1;padding:10px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--ink-mute);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);}
.btn-save{flex:2;padding:10px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;}
.btn-save:hover{background:var(--teal-lt);}
.btn-save:disabled{opacity:.55;cursor:not-allowed;}

/* voice preview tag */
.voice-tag{display:inline-block;padding:2px 8px;border-radius:5px;background:var(--blue-pale);color:var(--blue);font-size:10px;font-weight:700;margin-top:2px;}
.lang-tag{display:inline-block;padding:2px 8px;border-radius:5px;background:#f1f5f9;color:#475569;font-size:10px;font-weight:700;margin-top:2px;}

/* toast */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap;}

.imp-bar{background:#fef3c7;border-bottom:2px solid #f59e0b;padding:8px 24px;display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:600;color:#92400e;}
.imp-bar a{color:#92400e;text-decoration:none;font-weight:700;}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.sidebar{display:none;}.main{margin-left:0;}.stats-row{grid-template-columns:1fr 1fr;}.fg-row{grid-template-columns:1fr;}}
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
  <div class="sb-co">Admin Panel</div>

  <div class="sb-section">
    <div class="sb-lbl">Management</div>
    <ul class="sb-nav">
      <li><a href="admin/dashboard.php"><span class="ico">📊</span> Dashboard</a></li>
      <li><a href="admin/clients.php"><span class="ico">🏢</span> Clients</a></li>
      <li><a href="agents.php" class="active"><span class="ico">🤖</span> Agents</a></li>
      <li><a href="admin/campaigns.php"><span class="ico">📣</span> Campaigns</a></li>
      <li><a href="admin/call_log.php"><span class="ico">📞</span> Call Log</a></li>
      <li><a href="admin/billing.php"><span class="ico">💳</span> Billing</a></li>
    </ul>
  </div>
  <div class="sb-section">
    <div class="sb-lbl">System</div>
    <ul class="sb-nav">
      <li><a href="admin/settings.php"><span class="ico">⚙️</span> Settings</a></li>
    </ul>
  </div>
  <div class="sb-bottom">
    <div class="sb-user">
      <div class="sb-av"><?= htmlspecialchars($initials) ?></div>
      <div><div class="sb-un"><?= htmlspecialchars($fullname) ?></div><div class="sb-ur">Administrator</div></div>
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
    <div class="tb-title">🤖 AI Agents</div>
    <div class="tb-right">
      <a href="admin/dashboard.php" class="btn-ghost">← Admin</a>
      <button class="btn-primary" onclick="openNewAgent()">➕ Add Agent</button>
    </div>
  </div>

  <div class="page">

    <!-- Stats -->
    <div class="stats-row">
      <div class="sc">
        <div class="sc-ico">🤖</div>
        <div><div class="sc-val"><?= $total_agents ?></div><div class="sc-lbl">Total Agents</div></div>
      </div>
      <div class="sc">
        <div class="sc-ico">🟢</div>
        <div><div class="sc-val"><?= $active_agents ?></div><div class="sc-lbl">Active</div></div>
      </div>
      <div class="sc">
        <div class="sc-ico">📞</div>
        <div><div class="sc-val"><?= number_format($total_calls_all) ?></div><div class="sc-lbl">Total Calls</div></div>
      </div>
      <div class="sc">
        <div class="sc-ico">⏱</div>
        <div><div class="sc-val"><?= number_format($total_mins_all, 1) ?></div><div class="sc-lbl">Total Minutes</div></div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <input type="text" id="searchInput" placeholder="🔍  Search agents by name, voice, phone…" oninput="filterTable()">
      <select id="filterStatus" onchange="filterTable()">
        <option value="">All Statuses</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="maintenance">Maintenance</option>
      </select>
      <select id="filterProvider" onchange="filterTable()">
        <option value="">All Providers</option>
        <option value="vapi">VAPI</option>
        <option value="elevenlabs">ElevenLabs</option>
        <option value="openai">OpenAI</option>
        <option value="twilio">Twilio</option>
        <option value="other">Other</option>
      </select>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <?php if (empty($agents)): ?>
      <div class="empty-state">
        <div class="empty-icon">🤖</div>
        <div class="empty-title">No agents yet</div>
        <div class="empty-sub">Add your first AI calling agent to get started.</div>
        <button class="btn-primary" onclick="openNewAgent()">➕ Add First Agent</button>
      </div>
      <?php else: ?>
      <table class="agents-table" id="agentsTable">
        <thead>
          <tr>
            <th>Agent</th>
            <th>Provider</th>
            <th>Voice</th>
            <th>Language</th>
            <th>Phone Number</th>
            <th>Assigned Client</th>
            <th>Status</th>
            <th>Calls</th>
            <th>Minutes</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($agents as $a):
          $av_class = 'prov-' . strtolower($a['provider'] ?? 'vapi');
          $pb_class  = 'pb-' . strtolower($a['provider'] ?? 'other');
          $initials_a = strtoupper(substr($a['name'],0,2));
        ?>
        <tr data-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
            data-status="<?= htmlspecialchars($a['status']) ?>"
            data-provider="<?= htmlspecialchars(strtolower($a['provider'])) ?>">
          <td>
            <div class="agent-identity">
              <div class="agent-av <?= $av_class ?>"><?= $initials_a ?></div>
              <div>
                <div class="agent-name-cell"><?= htmlspecialchars($a['name']) ?></div>
                <div class="agent-id-cell"><?= $a['provider_agent_id'] ? htmlspecialchars(substr($a['provider_agent_id'],0,22)).'…' : '— no provider ID' ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="prov-badge <?= $pb_class ?>">
              <?= htmlspecialchars(strtoupper($a['provider'])) ?>
            </span>
          </td>
          <td>
            <?php if($a['voice']): ?>
              <span class="voice-tag"><?= htmlspecialchars($a['voice']) ?></span>
            <?php else: ?><span style="color:var(--ink-mute);">—</span><?php endif; ?>
          </td>
          <td><span class="lang-tag"><?= htmlspecialchars($a['language']) ?></span></td>
          <td style="font-family:monospace;font-size:12px;color:var(--ink-soft);">
            <?= $a['phone_number'] ? htmlspecialchars($a['phone_number']) : '<span style="color:var(--ink-mute);">—</span>' ?>
          </td>
          <td style="font-size:12px;color:var(--ink-soft);">
            <?= $a['company_name'] ? htmlspecialchars($a['company_name']) : '<span style="color:var(--ink-mute);">Unassigned</span>' ?>
          </td>
          <td>
            <span class="status-pill sp-<?= $a['status'] ?>" id="sp-<?= $a['id'] ?>">
              <span class="sp-dot"></span><?= ucfirst($a['status']) ?>
            </span>
          </td>
          <td style="font-weight:700;color:var(--ink);"><?= number_format($a['total_calls']) ?></td>
          <td style="font-weight:700;color:var(--ink);"><?= number_format($a['total_minutes'],1) ?></td>
          <td style="color:var(--ink-mute);font-size:12px;"><?= timeAgo($a['created_at']) ?></td>
          <td>
            <div class="act-btns">
              <button class="act-btn" onclick="editAgent(<?= $a['id'] ?>)">✏ Edit</button>
              <?php if($a['status'] === 'active'): ?>
                <button class="act-btn" onclick="toggleStatus(<?= $a['id'] ?>,'inactive',this)">⏸ Disable</button>
              <?php else: ?>
                <button class="act-btn" onclick="toggleStatus(<?= $a['id'] ?>,'active',this)">▶ Enable</button>
              <?php endif; ?>
              <button class="act-btn danger" onclick="deleteAgent(<?= $a['id'] ?>,this)">🗑</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div><!-- /page -->
</div><!-- /main -->

<!-- ══ AGENT MODAL ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="agentModal" onclick="overlayClick(event)">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <div class="modal-title" id="modalTitle">➕ Add Agent</div>
    <div class="modal-sub">Configure a new AI calling agent</div>
    <div class="modal-alert err" id="agentErr"></div>
    <input type="hidden" id="agent_id" value="">

    <div class="fg-section">Identity</div>
    <div class="fg-row">
      <div class="fg">
        <label>Agent Name *</label>
        <input type="text" id="f_name" placeholder="e.g. John — Sales Agent">
      </div>
      <div class="fg">
        <label>Status</label>
        <select id="f_status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="maintenance">Maintenance</option>
        </select>
      </div>
    </div>

    <div class="fg-section">Provider</div>
    <div class="fg-row">
      <div class="fg">
        <label>Provider</label>
        <select id="f_provider">
          <option value="vapi">VAPI</option>
          <option value="elevenlabs">ElevenLabs</option>
          <option value="openai">OpenAI</option>
          <option value="twilio">Twilio</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="fg">
        <label>Provider Agent ID</label>
        <input type="text" id="f_provider_agent_id" placeholder="e.g. vapi-agent-abc123">
        <div class="fg-hint">The ID from your provider's dashboard</div>
      </div>
    </div>

    <div class="fg-section">Voice &amp; Language</div>
    <div class="fg-row">
      <div class="fg">
        <label>Voice</label>
        <input type="text" id="f_voice" placeholder="e.g. en-US-Neural2-J, alloy, shimmer">
        <div class="fg-hint">Voice name or ID from your provider</div>
      </div>
      <div class="fg">
        <label>Language</label>
        <select id="f_language">
          <option value="en-US">English (US)</option>
          <option value="en-GB">English (UK)</option>
          <option value="en-AU">English (AU)</option>
          <option value="en-CA">English (CA)</option>
          <option value="fr-FR">French</option>
          <option value="fr-CA">French (CA)</option>
          <option value="es-US">Spanish (US)</option>
          <option value="es-ES">Spanish (ES)</option>
          <option value="de-DE">German</option>
          <option value="it-IT">Italian</option>
          <option value="pt-BR">Portuguese (BR)</option>
          <option value="nl-NL">Dutch</option>
          <option value="ja-JP">Japanese</option>
          <option value="zh-CN">Chinese (Mandarin)</option>
          <option value="ar-SA">Arabic</option>
          <option value="hi-IN">Hindi</option>
        </select>
      </div>
    </div>

    <div class="fg-section">Phone &amp; Assignment</div>
    <div class="fg-row">
      <div class="fg">
        <label>Phone Number</label>
        <input type="text" id="f_phone" placeholder="e.g. +12135550100">
        <div class="fg-hint">DID number assigned to this agent</div>
      </div>
      <div class="fg">
        <label>Assign to Client</label>
        <select id="f_client">
          <option value="0">— Unassigned (pool) —</option>
          <?php foreach ($clients as $cli): ?>
          <option value="<?= $cli['id'] ?>"><?= htmlspecialchars($cli['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-save" id="saveBtnLabel" onclick="saveAgent()">💾 Save Agent</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Utilities ──────────────────────────────────────────────────
function showToast(msg, dur=2800){
  const t=document.getElementById('toast');
  t.textContent=msg; t.style.opacity='1';
  setTimeout(()=>t.style.opacity='0',dur);
}
async function post(data){
  const fd=new FormData();
  Object.entries(data).forEach(([k,v])=>fd.append(k,v));
  const r=await fetch(location.href,{method:'POST',body:fd});
  return r.json();
}
function openModal(){ document.getElementById('agentModal').classList.add('open'); }
function closeModal(){ document.getElementById('agentModal').classList.remove('open'); clearErr(); }
function overlayClick(e){ if(e.target===document.getElementById('agentModal')) closeModal(); }
function clearErr(){ const e=document.getElementById('agentErr'); e.style.display='none'; e.textContent=''; }
function showErr(msg){ const e=document.getElementById('agentErr'); e.textContent=msg; e.style.display='block'; }

// ── Open new agent form ────────────────────────────────────────
function openNewAgent(){
  document.getElementById('modalTitle').textContent = '➕ Add Agent';
  document.getElementById('saveBtnLabel').textContent = '💾 Save Agent';
  document.getElementById('agent_id').value = '';
  document.getElementById('f_name').value = '';
  document.getElementById('f_provider').value = 'vapi';
  document.getElementById('f_provider_agent_id').value = '';
  document.getElementById('f_voice').value = '';
  document.getElementById('f_language').value = 'en-US';
  document.getElementById('f_phone').value = '';
  document.getElementById('f_status').value = 'active';
  document.getElementById('f_client').value = '0';
  clearErr();
  openModal();
}

// ── Edit existing agent ────────────────────────────────────────
async function editAgent(id){
  const d = await post({ajax_action:'get_agent', agent_id:id});
  if(!d.success){ showToast('⚠ Could not load agent'); return; }
  const a = d.agent;
  document.getElementById('modalTitle').textContent = '✏ Edit Agent';
  document.getElementById('saveBtnLabel').textContent = '💾 Update Agent';
  document.getElementById('agent_id').value            = a.id;
  document.getElementById('f_name').value              = a.name;
  document.getElementById('f_provider').value          = a.provider;
  document.getElementById('f_provider_agent_id').value = a.provider_agent_id || '';
  document.getElementById('f_voice').value             = a.voice || '';
  document.getElementById('f_language').value          = a.language || 'en-US';
  document.getElementById('f_phone').value             = a.phone_number || '';
  document.getElementById('f_status').value            = a.status;
  document.getElementById('f_client').value            = a.client_id || '0';
  clearErr();
  openModal();
}

// ── Save agent ─────────────────────────────────────────────────
async function saveAgent(){
  const name = document.getElementById('f_name').value.trim();
  if(!name){ showErr('Agent name is required.'); return; }
  const btn = document.getElementById('saveBtnLabel');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const d = await post({
      ajax_action:        'save_agent',
      agent_id:           document.getElementById('agent_id').value,
      name,
      provider:           document.getElementById('f_provider').value,
      provider_agent_id:  document.getElementById('f_provider_agent_id').value,
      voice:              document.getElementById('f_voice').value,
      language:           document.getElementById('f_language').value,
      phone_number:       document.getElementById('f_phone').value,
      status:             document.getElementById('f_status').value,
      assign_client_id:   document.getElementById('f_client').value,
    });
    if(d.success){
      showToast('✅ Agent saved!');
      closeModal();
      setTimeout(()=>location.reload(), 700);
    } else { showErr(d.error || 'Save failed'); }
  } catch(e){ showErr('Network error'); }
  btn.disabled = false; btn.textContent = '💾 Save Agent';
}

// ── Toggle status inline ───────────────────────────────────────
async function toggleStatus(id, newStatus, btn){
  const d = await post({ajax_action:'toggle_status', agent_id:id, new_status:newStatus});
  if(d.success){
    showToast(newStatus==='active' ? '✅ Agent enabled' : '⏸ Agent disabled');
    setTimeout(()=>location.reload(), 600);
  } else { showToast('⚠ Error'); }
}

// ── Delete agent ───────────────────────────────────────────────
async function deleteAgent(id, btn){
  if(!confirm('Delete this agent? This cannot be undone and will remove it from all campaigns.')) return;
  const d = await post({ajax_action:'delete_agent', agent_id:id});
  if(d.success){ btn.closest('tr').remove(); showToast('🗑 Agent deleted'); }
  else showToast('⚠ Delete failed');
}

// ── Client-side filter ─────────────────────────────────────────
function filterTable(){
  const search   = document.getElementById('searchInput').value.toLowerCase();
  const status   = document.getElementById('filterStatus').value;
  const provider = document.getElementById('filterProvider').value;
  document.querySelectorAll('#agentsTable tbody tr').forEach(row => {
    const name     = row.dataset.name     || '';
    const rowSt    = row.dataset.status   || '';
    const rowProv  = row.dataset.provider || '';
    const text     = row.textContent.toLowerCase();
    const matchSearch   = !search   || text.includes(search);
    const matchStatus   = !status   || rowSt === status;
    const matchProvider = !provider || rowProv === provider;
    row.style.display = (matchSearch && matchStatus && matchProvider) ? '' : 'none';
  });
}

// Close modal on Escape
document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });
</script>
</body>
</html>

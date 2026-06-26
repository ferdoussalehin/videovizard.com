<?php
session_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);

$admin_id    = $_SESSION['admin_id']   ?? null;
$admin_level = $_SESSION['level']      ?? '';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';

// ── AJAX: upload_external_video (reused from vizard_browser) ─────────────
// Handled by ajax_upload_external.php — no duplication needed here

// ── Load user data ───────────────────────────────────────────────────────
$user_result = mysqli_query($conn, "SELECT * FROM hdb_users WHERE id='$admin_id' LIMIT 1");
$user_data   = $user_result ? mysqli_fetch_assoc($user_result) : [];
$firstname   = $user_data['firstname']  ?? 'User';
$lastname    = $user_data['lastname']   ?? '';
$email       = $user_data['email']      ?? '';
$admin_initial = strtoupper(substr($firstname, 0, 1));

// Team member support
$team_lead_id    = (int)($user_data['team_lead_id'] ?? 0);
$is_team_member  = (($user_data['role'] ?? '') === 'Team Member' && $team_lead_id > 0);
$effective_admin = $is_team_member ? $team_lead_id : $admin_id;

// ── Companies ────────────────────────────────────────────────────────────
$companies_result = mysqli_query($conn,
    "SELECT id, companyname, company_type FROM hdb_companies WHERE admin_id=$effective_admin ORDER BY id ASC");
$companies = [];
while ($cr = mysqli_fetch_assoc($companies_result)) $companies[] = $cr;

// Company switching via GET — use it directly without redirecting
// This allows linking directly to a specific company workspace
if (isset($_GET['company_id'])) {
    $switched = (int)$_GET['company_id'];
    $chk      = mysqli_query($conn,
        "SELECT id FROM hdb_companies WHERE id=$switched AND admin_id=$effective_admin LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $_SESSION['company_id'] = $switched;
        mysqli_query($conn, "UPDATE hdb_users SET last_company_id=$switched WHERE id=$admin_id");
    }
}

// Determine active company
$company_id          = 0;
$active_company_name = 'My Workspace';
$active_company_type = '';

// GET takes priority over session, then DB last_company_id
if (!empty($_GET['company_id']))
    $company_id = (int)$_GET['company_id'];
elseif (!empty($_SESSION['company_id']))
    $company_id = (int)$_SESSION['company_id'];

if ($company_id == 0 && !empty($user_data['last_company_id'])) {
    $lid = (int)$user_data['last_company_id'];
    foreach ($companies as $c) {
        if ($c['id'] == $lid) { $company_id = $lid; $_SESSION['company_id'] = $lid; break; }
    }
}
if ($company_id == 0 && !empty($companies)) {
    $company_id = (int)$companies[0]['id'];
    $_SESSION['company_id'] = $company_id;
}

foreach ($companies as $c) {
    if ((int)$c['id'] === $company_id) {
        $active_company_name = $c['companyname'];
        $active_company_type = $c['company_type'] ?? '';
        break;
    }
}

// ── Workspace title logic ─────────────────────────────────────────────────
// Show company name if a company is selected, else fall back to Personal Workspace
$workspace_label = ($company_id > 0 && $active_company_name !== 'My Workspace')
    ? htmlspecialchars($active_company_name)
    : 'Personal Workspace';

// ── Scope SQL ─────────────────────────────────────────────────────────────
if (($user_data['role'] ?? '') === 'Team Leader') {
    $scope_sql = "(admin_id=$admin_id OR team_lead_id=$admin_id)";
} elseif ($is_team_member) {
    $scope_sql = "team_lead_id=$team_lead_id";
} else {
    $scope_sql = "(admin_id=$admin_id OR team_lead_id=$admin_id)";
}

// ── Per-status counts ─────────────────────────────────────────────────────
$cnt_res = mysqli_query($conn, "SELECT
    SUM(CASE WHEN video_status NOT IN ('RECORDED','SCHEDULED','POSTED','PUBLISHED','ARCHIVED') AND approval_status NOT IN ('pending_approval','approved','review_required') AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS wip_count,
    SUM(CASE WHEN video_status='RECORDED'          AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS completed_count,
    SUM(CASE WHEN approval_status='pending_approval' AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS approval_count,
    SUM(CASE WHEN approval_status='approved'         AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN approval_status='review_required'  AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS review_count,
    SUM(CASE WHEN video_status='SCHEDULED'           AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS scheduled_count,
    SUM(CASE WHEN video_status IN ('POSTED','PUBLISHED') AND (archived_flag IS NULL OR archived_flag=0) THEN 1 ELSE 0 END) AS posted_count
    FROM hdb_podcasts WHERE $scope_sql AND company_id=$company_id");
$counts = $cnt_res ? mysqli_fetch_assoc($cnt_res) : [];
$counts = array_map('intval', $counts + [
    'wip_count'=>0,'completed_count'=>0,'approval_count'=>0,
    'approved_count'=>0,'review_count'=>0,'scheduled_count'=>0,'posted_count'=>0
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>VideoVizard — Workspace</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
:root {
    --navy:   #0f2a44; --navy2:  #143b63; --accent: #5fd1ff;
    --green:  #10b981; --purple: #8b5cf6;
    --text:   #1e293b; --muted:  #64748b; --border: #e2e8f0;
    --bg:     #f0f4f8; --card:   #ffffff;
    --shadow: 0 4px 20px rgba(0,0,0,.08);
    --del:    #ef4444; --amber:  #f59e0b;
}
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
       background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column; }

/* ── Header ── */
.ws-header { display:flex;justify-content:space-between;align-items:center;padding:12px 16px;
    background:linear-gradient(90deg,var(--navy),var(--navy2));color:#fff;
    box-shadow:0 3px 10px rgba(0,0,0,.15);position:sticky;top:0;z-index:1000;gap:12px; }
.ws-brand a { text-decoration:none;display:flex;align-items:center;gap:8px; }
.ws-logo { font-size:20px;font-weight:700;line-height:1.2; }
.ws-logo .vv  { color:#fff; }
.ws-logo .viz { color:var(--accent); }
.ws-right { display:flex;align-items:center;gap:10px;flex-shrink:0; }

/* Company switcher */
.co-sw { position:relative; }
.co-btn { background:rgba(95,209,255,.15);border:1px solid rgba(95,209,255,.35);color:#fff;
    padding:7px 12px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;
    display:flex;align-items:center;gap:7px;min-height:40px;max-width:180px;transition:.2s; }
.co-btn .co-name { white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px; }
.co-btn .chev { font-size:10px;transition:transform .2s; }
.co-btn.open .chev { transform:rotate(180deg); }
.co-dd { display:none;position:absolute;top:calc(100% + 8px);right:0;background:#fff;
    border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.18);min-width:230px;overflow:hidden;
    z-index:9999;border:1px solid var(--border); }
.co-dd.open { display:block;animation:slideDown .2s ease; }
.co-dd-hdr { padding:12px 16px 8px;font-size:11px;font-weight:700;color:var(--muted);
    text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border); }
.co-item { padding:12px 16px;font-size:14px;color:var(--text);display:flex;align-items:center;
    gap:10px;cursor:pointer;transition:background .15s;text-decoration:none; }
.co-item:hover { background:#f0f9ff; }
.co-item.active { font-weight:700;color:var(--navy); }
.co-check { color:var(--green);font-size:16px;margin-left:auto; }

/* Profile */
.prof-wrap { position:relative; }
.prof-btn { background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;
    padding:8px 12px;border-radius:10px;cursor:pointer;font-size:14px;font-weight:600;
    display:flex;align-items:center;gap:8px;min-height:40px;transition:.2s; }
.prof-btn .av { width:26px;height:26px;background:var(--accent);color:var(--navy);
    border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-weight:800;font-size:13px;flex-shrink:0; }
.prof-dd { display:none;position:absolute;top:calc(100%+8px);right:0;background:#fff;
    border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);min-width:200px;
    overflow:hidden;z-index:9999;border:1px solid var(--border); }
.prof-dd.open { display:block;animation:slideDown .2s ease; }
.prof-dd-user { padding:14px 16px;border-bottom:1px solid var(--border); }
.prof-dd-name { font-weight:700;font-size:14px;color:var(--navy); }
.prof-dd-email { font-size:11px;color:var(--muted);margin-top:1px; }
.dd-item { padding:12px 16px;font-size:14px;color:var(--text);display:flex;align-items:center;
    gap:10px;text-decoration:none;transition:background .15s;min-height:44px; }
.dd-item:hover { background:#f8fafc; }
.dd-item.logout { color:var(--del); }
.dd-div { height:1px;background:var(--border); }
@keyframes slideDown { from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none} }

/* ── Main ── */
.ws-main { flex:1;padding:16px;width:100%; }

/* ── Workspace banner ── */
.ws-banner {
    background:linear-gradient(135deg,var(--navy),var(--navy2));
    border-radius:18px;padding:22px 24px;margin-bottom:20px;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
    box-shadow:0 8px 30px rgba(15,42,68,.18);
}
.ws-banner-left { display:flex;flex-direction:column;gap:4px; }
.ws-banner-eyebrow { font-size:11px;font-weight:700;color:rgba(95,209,255,.8);
    text-transform:uppercase;letter-spacing:.06em; }
.ws-banner-title { font-size:22px;font-weight:800;color:#fff;letter-spacing:-.5px;line-height:1.2; }
.ws-banner-sub { font-size:13px;color:rgba(255,255,255,.6); }
.ws-banner-actions { display:flex;gap:10px;flex-wrap:wrap; }
.ws-btn { display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:40px;
    font-size:13px;font-weight:700;cursor:pointer;border:none;transition:.2s;text-decoration:none; }
.ws-btn-upload { background:rgba(255,255,255,.12);color:#fff;border:1.5px solid rgba(255,255,255,.25); }
.ws-btn-upload:hover { background:rgba(255,255,255,.2); }
.ws-btn-analytics { background:var(--accent);color:var(--navy); }
.ws-btn-analytics:hover { opacity:.9; }
.ws-btn-grid { background:rgba(255,255,255,.12);color:#fff;border:1.5px solid rgba(255,255,255,.25); }
.ws-btn-grid:hover { background:rgba(255,255,255,.2); }

/* ── Status tabs ── */
.ws-tabs { display:flex;gap:4px;margin-bottom:0;background:var(--card);
    border-radius:14px 14px 0 0;border:1px solid var(--border);border-bottom:none;
    padding:6px 6px 0;overflow-x:auto;-webkit-overflow-scrolling:touch;
    scrollbar-width:none; }
.ws-tabs::-webkit-scrollbar { display:none; }
.ws-tab { padding:10px 18px;border-radius:10px 10px 0 0;font-size:14px;font-weight:600;
    color:var(--muted);background:transparent;border:none;cursor:pointer;transition:.2s;
    white-space:nowrap;min-height:44px;display:flex;align-items:center;gap:7px;flex-shrink:0; }
.ws-tab.active { background:var(--navy);color:#fff; }
.ws-tab .tb { padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;
    background:rgba(255,255,255,.2); }
.ws-tab:not(.active) .tb { background:var(--border);color:var(--muted); }

/* ── Panel ── */
.ws-panel { display:none;background:var(--card);border:1px solid var(--border);
    border-radius:0 14px 14px 14px;padding:20px 16px; }
.ws-panel.active { display:block; }

/* panel top bar */
.panel-topbar { display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:8px;margin-bottom:16px; }
.panel-topbar-actions { display:flex;gap:8px;flex-wrap:wrap; }
.panel-btn { display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
    border-radius:30px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:.2s; }
.panel-btn-approval { background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d; }
.panel-btn-approval:hover { background:#fde68a; }
.panel-btn-upload { background:#dbeafe;color:#1d4ed8;border:1.5px solid #bfdbfe; }
.panel-btn-upload:hover { background:#bfdbfe; }
.panel-btn-grid { background:#f0fdf4;color:#065f46;border:1.5px solid #86efac; }
.panel-btn-grid:hover { background:#dcfce7; }

/* ── Video cards ── */
.cards-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px; }
.project-card { background:var(--card);border-radius:14px;overflow:hidden;
    box-shadow:var(--shadow);border:1px solid var(--border);cursor:pointer;
    transition:.25s;text-decoration:none;color:inherit;display:flex;flex-direction:column;
    position:relative;animation:fadeIn .3s ease;aspect-ratio:9/16; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none} }
.project-card.fade-out { animation:fadeOut .3s ease forwards; }
@keyframes fadeOut { to{opacity:0;transform:scale(.9)} }
.status-badge { position:absolute;top:10px;left:10px;padding:5px 10px;border-radius:30px;
    font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
    z-index:5;box-shadow:0 2px 6px rgba(0,0,0,.12); }
.s-wip       { background:#fef3c7;color:#d97706; }
.s-completed { background:#d1fae5;color:#059669; }
.s-approval  { background:#fef3c7;color:#b45309; }
.s-approved  { background:#d1fae5;color:#065f46; }
.s-review    { background:#fee2e2;color:#991b1b; }
.s-scheduled { background:#dbeafe;color:#2563eb; }
.s-posted    { background:#ede9fe;color:#7c3aed; }
.card-thumb { width:100%;height:57%;object-fit:cover;display:block; }
.card-thumb-default { width:100%;height:57%;background:linear-gradient(135deg,var(--navy),#1e4a7a);
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:8px;color:rgba(255,255,255,.9); }
.card-thumb-default .pi { font-size:42px;opacity:.9; }
.card-thumb-default .nt { font-size:11px;text-transform:uppercase;letter-spacing:.5px;
    background:rgba(0,0,0,.4);padding:5px 10px;border-radius:30px; }
.card-body { padding:10px 12px;height:30%;display:flex;flex-direction:column;justify-content:space-between; }
.card-title { font-size:13px;font-weight:600;color:var(--text);line-height:1.4;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
    word-break:break-word;margin-bottom:4px; }
.card-meta { display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-top:auto; }
.card-date { color:var(--muted); }
.card-id { color:var(--muted);background:var(--bg);padding:3px 7px;border-radius:10px;font-weight:600; }
.card-actions { position:absolute;top:10px;right:10px;display:flex;gap:6px;z-index:10;
    opacity:0;transition:opacity .2s; }
.project-card:hover .card-actions { opacity:1; }
.act-btn { width:38px;height:38px;border-radius:10px;border:none;background:white;color:var(--muted);
    display:flex;align-items:center;justify-content:center;cursor:pointer;transition:.2s;
    box-shadow:0 3px 10px rgba(0,0,0,.18);font-size:16px; }
.act-btn.del  { color:var(--del); }
.act-btn.post { background:#f0fdf4!important;color:#059669!important;border:1.5px solid #86efac!important; }
.act-btn.sched{ background:#eff6ff!important;color:#2563eb!important;border:1.5px solid #bfdbfe!important; }

/* approval strip at bottom of card */
.appr-strip { display:flex;align-items:center;justify-content:center;padding:6px;
    width:100%;border-top:2px solid;font-size:12px;font-weight:700;gap:6px; }

/* ── Misc ── */
.spinner { display:inline-block;width:44px;height:44px;border:3px solid var(--border);
    border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite; }
@keyframes spin { to{transform:rotate(360deg)} }
.loading-spinner { text-align:center;padding:50px 16px;color:var(--muted);grid-column:1/-1; }
.empty-state { text-align:center;padding:60px 20px;color:var(--muted);border-radius:16px;
    border:2px dashed var(--border);grid-column:1/-1; }
.empty-state .ei { font-size:56px;margin-bottom:16px; }
.empty-state p { font-size:15px;margin-bottom:10px; }
.load-more-cont { text-align:center;margin:20px 0;grid-column:1/-1; }
.load-more-btn { background:var(--card);border:1px solid var(--border);color:var(--navy);
    padding:14px 30px;border-radius:40px;font-size:15px;font-weight:600;cursor:pointer;
    transition:.2s;box-shadow:var(--shadow);min-height:52px;min-width:180px; }

/* ── Modals shared ── */
.modal { display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;
    align-items:center;justify-content:center;padding:20px; }
.modal.show { display:flex;animation:fadeIn .2s ease; }
.modal-box { background:#fff;border-radius:20px;padding:28px;max-width:380px;width:100%;
    box-shadow:0 30px 60px rgba(0,0,0,.3); }
.modal-box h3 { font-size:20px;margin-bottom:10px;color:var(--navy); }
.modal-box p  { font-size:14px;color:var(--muted);margin-bottom:20px;line-height:1.6; }
.modal-actions { display:flex;gap:10px; }
.m-btn { flex:1;padding:14px;border:none;border-radius:12px;font-size:15px;font-weight:600;
    cursor:pointer;transition:.2s;min-height:50px; }
.m-btn.cancel { background:var(--border);color:var(--text); }
.m-btn.danger  { background:var(--del);color:#fff; }
.m-btn.primary { background:linear-gradient(135deg,var(--navy),#0284c7);color:#fff; }

/* ── Upload modal ── */
.upload-label { font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;
    letter-spacing:.04em;display:block;margin-bottom:5px; }
.upload-input { width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;
    font-size:14px;outline:none;font-family:inherit;background:#fff;color:var(--text); }
.upload-input:focus { border-color:var(--navy); }

/* ── Footer ── */
.ws-footer { background:linear-gradient(90deg,var(--navy),var(--navy2));
    color:rgba(255,255,255,.55);padding:16px 20px;font-size:13px;
    display:flex;flex-direction:column;gap:10px;text-align:center;margin-top:auto; }
.ws-footer-brand { font-weight:700;color:var(--accent); }
.ws-footer-links { display:flex;gap:20px;justify-content:center;flex-wrap:wrap; }
.ws-footer-links a { color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;padding:4px 0; }
.ws-footer-links a:hover { color:var(--accent); }

/* ── Analytics panel ── */
.analytics-panel { background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-radius:16px;
    padding:28px 24px;text-align:center; }
.analytics-panel h3 { font-size:20px;font-weight:800;color:var(--navy);margin-bottom:8px; }
.analytics-panel p  { font-size:14px;color:var(--muted);margin-bottom:24px;line-height:1.6; }
.analytics-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px; }
.analytics-card { background:#fff;border-radius:14px;padding:18px 14px;text-align:center;
    box-shadow:0 4px 16px rgba(2,132,199,.08);border:1px solid #bae6fd; }
.analytics-card .ac-icon { font-size:28px;margin-bottom:8px;display:block; }
.analytics-card .ac-label { font-size:11px;font-weight:700;color:var(--muted);
    text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px; }
.analytics-card .ac-val { font-size:24px;font-weight:800;color:var(--navy); }
.analytics-card .ac-sub { font-size:11px;color:var(--muted);margin-top:2px; }
.plat-analytics { display:flex;flex-direction:column;gap:8px;margin-top:8px; }
.plat-row { display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;
    border-radius:10px;border:1px solid var(--border); }
.plat-row .plat-icon { font-size:20px;width:28px;text-align:center; }
.plat-row .plat-name { flex:1;font-size:13px;font-weight:700;color:var(--text); }
.plat-row .plat-stat { font-size:12px;color:var(--muted); }
.plat-row .plat-connect { font-size:12px;font-weight:700;color:#0284c7;
    background:#e0f2fe;padding:4px 10px;border-radius:20px; }
.connect-btn { display:inline-flex;align-items:center;gap:8px;padding:12px 24px;
    background:linear-gradient(135deg,var(--navy),#0284c7);color:#fff;border:none;
    border-radius:40px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;
    text-decoration:none;margin-top:16px; }
.connect-btn:hover { opacity:.9; }

@media(max-width:380px) { .cards-grid{grid-template-columns:1fr;} }
@media(min-width:768px) {
    .ws-header { padding:14px 24px; }
    .ws-main   { padding:28px 24px; }
    .analytics-grid { grid-template-columns:repeat(4,1fr); }
}
</style>
</head>
<body>

<!-- ── HEADER ── -->
<header class="ws-header">
    <div class="ws-brand">
        <a href="vizard_browser.php">
            <div class="ws-logo"><span class="vv">Video</span><span class="viz">Vizard</span></div>
        </a>
    </div>
    <div class="ws-right">
        <!-- Company switcher -->
        <?php if (count($companies) > 1): ?>
        <div class="co-sw">
            <button class="co-btn" id="coBtnMain" onclick="toggleCoMenu()">
                🏢 <span class="co-name"><?= htmlspecialchars($active_company_name) ?></span>
                <span class="chev">▼</span>
            </button>
            <div class="co-dd" id="coDdMain">
                <div class="co-dd-hdr">Switch Workspace</div>
                <?php foreach ($companies as $c): ?>
                <div class="co-item <?= $c['id']==$company_id?'active':'' ?>"
                     onclick="switchCompany(<?= $c['id'] ?>)">
                    <?= $c['company_type']==='internal' ? '🏢' : '👤' ?>
                    <?= htmlspecialchars($c['companyname']) ?>
                    <?php if ($c['id']==$company_id): ?>
                        <span class="co-check">✓</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <!-- Profile -->
        <div class="prof-wrap">
            <button class="prof-btn" id="profBtnMain" onclick="toggleProfMenu()">
                <div class="av"><?= $admin_initial ?></div>
                <span class="chev">▼</span>
            </button>
            <div class="prof-dd" id="profDdMain">
                <div class="prof-dd-user">
                    <div class="prof-dd-name"><?= htmlspecialchars("$firstname $lastname") ?></div>
                    <div class="prof-dd-email"><?= htmlspecialchars($email) ?></div>
                </div>
                <a href="vizard_browser.php" class="dd-item">🎬 Full Dashboard</a>
                <a href="profile.php"        class="dd-item">👤 My Profile</a>
                <a href="user_settings.php"  class="dd-item">⚙️ Settings</a>
                <div class="dd-div"></div>
                <a href="logout.php" class="dd-item logout">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- ── MAIN ── -->
<div class="ws-main">

    <!-- Workspace banner -->
    <div class="ws-banner">
        <div class="ws-banner-left">
            <div class="ws-banner-eyebrow">
                <?= ($company_id > 0 && $active_company_name !== 'My Workspace') ? '🏢 ' . htmlspecialchars($active_company_name) : '👤 Personal Workspace' ?>
            </div>
            <div class="ws-banner-title"><?= $workspace_label ?></div>
            <div class="ws-banner-sub">
              <?php if ($company_id > 0 && $active_company_name !== 'My Workspace'): ?>
                <?= htmlspecialchars($active_company_name) ?> — upload, review, schedule &amp; post.
              <?php else: ?>
                <?= htmlspecialchars($firstname) ?>'s content hub — upload, review, schedule &amp; post.
              <?php endif; ?>
            </div>
        </div>
        <div class="ws-banner-actions">
            <button class="ws-btn ws-btn-upload" onclick="openUploadModal()">📤 Upload Video</button>
            <button class="ws-btn ws-btn-grid"   onclick="openIgGrid()">📸 Instagram Grid</button>
            <button class="ws-btn ws-btn-analytics" onclick="switchWsTab('analytics')">📊 Analytics</button>
        </div>
    </div>

    <!-- Status tabs -->
    <div class="ws-tabs" id="wsTabs">
        <button class="ws-tab active" data-tab="wip"       onclick="switchWsTab('wip')">
            🔨 In Progress <span class="tb" id="tc-wip"><?= $counts['wip_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="completed"        onclick="switchWsTab('completed')">
            ✅ Completed <span class="tb" id="tc-completed"><?= $counts['completed_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="approval"         onclick="switchWsTab('approval')">
            ⏳ Sent for Approval <span class="tb" id="tc-approval"><?= $counts['approval_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="approved"         onclick="switchWsTab('approved')">
            ✅ Approved <span class="tb" id="tc-approved"><?= $counts['approved_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="review"           onclick="switchWsTab('review')">
            🔁 Review Required <span class="tb" id="tc-review"><?= $counts['review_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="scheduled"        onclick="switchWsTab('scheduled')">
            🗓 Scheduled <span class="tb" id="tc-scheduled"><?= $counts['scheduled_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="posted"           onclick="switchWsTab('posted')">
            🚀 Posted <span class="tb" id="tc-posted"><?= $counts['posted_count'] ?></span>
        </button>
        <button class="ws-tab" data-tab="analytics"        onclick="switchWsTab('analytics')">
            📊 Analytics
        </button>
    </div>

    <!-- Video panels (wip / completed / approval / approved / review / scheduled / posted) -->
    <?php
    $panels = [
        'wip'       => ['label'=>'In Progress',        'status'=>'active',           'empty'=>'No videos in progress yet.'],
        'completed' => ['label'=>'Completed',           'status'=>'completed',         'empty'=>'No completed videos yet. Upload one above!'],
        'approval'  => ['label'=>'Sent for Approval',  'status'=>'pending_approval',  'empty'=>'No videos awaiting approval.'],
        'approved'  => ['label'=>'Approved',            'status'=>'approved',          'empty'=>'No approved videos yet.'],
        'review'    => ['label'=>'Review Required',     'status'=>'review_required',   'empty'=>'No videos flagged for review.'],
        'scheduled' => ['label'=>'Scheduled',           'status'=>'scheduled',         'empty'=>'No scheduled videos.'],
        'posted'    => ['label'=>'Posted',              'status'=>'posted',            'empty'=>'No posted videos yet.'],
    ];
    foreach ($panels as $key => $cfg): ?>
    <div class="ws-panel <?= $key==='wip'?'active':'' ?>" id="panel-<?= $key ?>">
        <?php if ($key === 'completed'): ?>
        <div class="panel-topbar">
            <div style="font-size:13px;color:var(--muted);">Videos ready to post or send for approval</div>
            <div class="panel-topbar-actions">
                <button class="panel-btn panel-btn-approval" onclick="openApprovalModal()">☑️ Send for Approval</button>
                <button class="panel-btn panel-btn-upload"   onclick="openUploadModal()">📤 Upload Video</button>
                <button class="panel-btn panel-btn-grid"     onclick="openIgGrid()">📸 IG Grid</button>
            </div>
        </div>
        <?php endif; ?>
        <div class="cards-grid" id="grid-<?= $key ?>">
            <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading…</p></div>
        </div>
        <div class="load-more-cont" id="lm-<?= $key ?>" style="display:none;">
            <button class="load-more-btn" onclick="loadMore('<?= $key ?>')">Load More</button>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Analytics panel -->
    <div class="ws-panel" id="panel-analytics">
        <div class="analytics-panel">
            <h3>📊 Your Content Analytics</h3>
            <p>Track your growth, engagement, and top-performing videos across all connected platforms.</p>
            <div class="analytics-grid">
                <div class="analytics-card">
                    <span class="ac-icon">🎬</span>
                    <div class="ac-label">Total Videos</div>
                    <div class="ac-val"><?= array_sum($counts) ?></div>
                    <div class="ac-sub">across all statuses</div>
                </div>
                <div class="analytics-card">
                    <span class="ac-icon">🚀</span>
                    <div class="ac-label">Posted</div>
                    <div class="ac-val"><?= $counts['posted_count'] ?></div>
                    <div class="ac-sub">published to platforms</div>
                </div>
                <div class="analytics-card">
                    <span class="ac-icon">🗓</span>
                    <div class="ac-label">Scheduled</div>
                    <div class="ac-val"><?= $counts['scheduled_count'] ?></div>
                    <div class="ac-sub">queued to post</div>
                </div>
                <div class="analytics-card">
                    <span class="ac-icon">✅</span>
                    <div class="ac-label">Approved</div>
                    <div class="ac-val"><?= $counts['approved_count'] ?></div>
                    <div class="ac-sub">ready to publish</div>
                </div>
            </div>
            <div style="font-size:13px;font-weight:700;color:var(--navy);text-align:left;margin-bottom:10px;">
                Connected Platforms
            </div>
            <div class="plat-analytics">
                <div class="plat-row">
                    <span class="plat-icon">📸</span>
                    <span class="plat-name">Instagram</span>
                    <span class="plat-connect">Connect →</span>
                </div>
                <div class="plat-row">
                    <span class="plat-icon">🎵</span>
                    <span class="plat-name">TikTok</span>
                    <span class="plat-connect">Connect →</span>
                </div>
                <div class="plat-row">
                    <span class="plat-icon">▶️</span>
                    <span class="plat-name">YouTube</span>
                    <span class="plat-connect">Connect →</span>
                </div>
                <div class="plat-row">
                    <span class="plat-icon">📘</span>
                    <span class="plat-name">Facebook</span>
                    <span class="plat-connect">Connect →</span>
                </div>
            </div>
            <a href="vizard_browser.php?tab=videos" class="connect-btn">
                🔗 Connect Platforms & See Full Analytics
            </a>
        </div>
    </div>

</div><!-- /ws-main -->

<!-- ── DELETE MODAL ── -->
<div class="modal" id="deleteModal">
    <div class="modal-box">
        <h3>🗑️ Delete Video?</h3>
        <p>This will permanently delete this video and all its files. This cannot be undone.</p>
        <div class="modal-actions">
            <button class="m-btn cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="m-btn danger"  id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ── APPROVAL MODAL ── -->
<div class="modal" id="approvalModal">
    <div class="modal-box">
        <h3>📨 Send for Approval</h3>
        <p>Enter the client's email. They'll receive a link to review and approve the selected videos.</p>
        <div style="margin-bottom:16px;">
            <label class="upload-label">Client Email</label>
            <input type="email" id="approvalEmail" class="upload-input" placeholder="client@example.com">
        </div>
        <div class="modal-actions">
            <button class="m-btn cancel" onclick="closeApprovalModal()">Cancel</button>
            <button class="m-btn primary" id="confirmApprovalBtn" onclick="sendForApproval()">Send Notification</button>
        </div>
    </div>
</div>

<!-- ── UPLOAD MODAL ── -->
<div class="modal" id="uploadModal" onclick="if(event.target===this)closeUploadModal()">
    <div class="modal-box" style="max-width:500px;">
        <h3>📤 Upload Video</h3>
        <p style="font-size:13px;">Upload a video from Canva, CapCut or any tool. It'll appear in your Completed videos.</p>
        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px;">
            <div>
                <label class="upload-label">Video Title</label>
                <input type="text" id="upTitle" class="upload-input" placeholder="Enter video title">
            </div>
            <div>
                <label class="upload-label">Language</label>
                <select id="upLang" class="upload-input">
                    <option value="en">🇬🇧 English</option>
                    <option value="ar">🇸🇦 Arabic</option>
                    <option value="ur">🇵🇰 Urdu</option>
                    <option value="hi">🇮🇳 Hindi</option>
                    <option value="es">🇪🇸 Spanish</option>
                    <option value="fr">🇫🇷 French</option>
                    <option value="pt">🇵🇹 Portuguese</option>
                    <option value="pa">🇮🇳 Punjabi</option>
                    <option value="gu">🇮🇳 Gujarati</option>
                    <option value="ta">🇮🇳 Tamil</option>
                    <option value="zh">🇨🇳 Chinese</option>
                    <option value="fa">🇮🇷 Farsi</option>
                    <option value="bn">🇧🇩 Bengali</option>
                    <option value="ru">🇷🇺 Russian</option>
                    <option value="ja">🇯🇵 Japanese</option>
                    <option value="ko">🇰🇷 Korean</option>
                    <option value="tr">🇹🇷 Turkish</option>
                </select>
            </div>
            <div>
                <label class="upload-label">Video File</label>
                <input type="file" id="upFile" accept="video/*" class="upload-input" style="padding:6px;" onchange="captureUpThumb(this)">
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">MP4, MOV, WebM — max 500MB</div>
            </div>
            <div id="upThumbPrev" style="display:none;text-align:center;">
                <img id="upThumbImg" style="max-width:100px;border-radius:8px;border:2px solid var(--green);">
                <div style="font-size:11px;color:#059669;margin-top:4px;">✅ Thumbnail captured</div>
            </div>
            <video id="upThumbVid"    style="display:none;" muted playsinline></video>
            <canvas id="upThumbCanvas" style="display:none;"></canvas>
            <div id="upProgress" style="display:none;">
                <div style="background:var(--border);border-radius:20px;height:8px;overflow:hidden;">
                    <div id="upProgressBar" style="height:100%;background:linear-gradient(90deg,var(--navy),#0284c7);width:0%;transition:width .3s;border-radius:20px;"></div>
                </div>
                <div id="upProgressTxt" style="font-size:12px;color:var(--muted);margin-top:4px;text-align:center;">Uploading…</div>
            </div>
            <div id="upError" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;font-size:13px;color:#991b1b;"></div>
        </div>
        <div class="modal-actions">
            <button class="m-btn cancel" onclick="closeUploadModal()">Cancel</button>
            <button class="m-btn primary" id="upSubmitBtn" onclick="submitUpload()">Upload Video</button>
        </div>
    </div>
</div>

<!-- ── IG GRID MODAL ── -->
<div class="modal" id="igModal" onclick="if(event.target===this)closeIgGrid()" style="padding:0;">
    <div style="background:#fff;border-radius:20px;width:96vw;max-width:900px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 60px rgba(0,0,0,.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">
            <h3 style="font-size:17px;font-weight:700;color:var(--navy);">📸 Instagram Grid View</h3>
            <button onclick="closeIgGrid()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);">✕</button>
        </div>
        <div style="padding:10px 16px 6px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:13px;color:#0369a1;">
            📱 How your completed videos look on Instagram — tap any to preview.
        </div>
        <div id="igGridBody" style="flex:1;overflow-y:auto;padding:16px;">
            <!-- filled by JS -->
        </div>
    </div>
</div>

<!-- ── BPM MODAL (Post / Schedule / Download) — reuse same structure ── -->
<div class="bpm-overlay" id="bpmOverlay">
  <div class="bpm-modal">
    <div id="bpmMain">
      <div class="bpm-head">
        <div class="bpm-head-left">
          <span class="bpm-head-icon">📤</span>
          <div>
            <div class="bpm-head-title">Post / Schedule</div>
            <div class="bpm-head-sub" id="bpmSubTitle"></div>
          </div>
        </div>
        <button class="bpm-close" onclick="closeBpmModal()">✕</button>
      </div>
      <div class="bpm-saved" style="display:none;"><div class="bpm-saved-dot"></div><span id="bpmSavedLabel"></span></div>
      <div id="bpmSpinner" style="display:flex;justify-content:center;padding:40px;"><div class="spinner"></div></div>
      <div id="bpmBody" style="display:none;">
        <div class="bpm-inner">
          <div class="bpm-lbl">Choose Platforms</div>
          <div class="bpm-platforms">
            <div class="bpm-plat" data-p="instagram" onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">📸</span> Instagram</div>
            <div class="bpm-plat" data-p="tiktok"    onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">🎵</span> TikTok</div>
            <div class="bpm-plat" data-p="youtube"   onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">▶️</span> YouTube</div>
            <div class="bpm-plat" data-p="facebook"  onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">📘</span> Facebook</div>
          </div>
          <div class="bpm-warn" id="bpmWarn" style="display:none;">Please select at least one platform.</div>
          <div class="bpm-ctabs">
            <button class="bpm-ctab active" data-tab="caption"  onclick="bpmSwitchTab('caption',this)">Caption</button>
            <button class="bpm-ctab"        data-tab="keywords" onclick="bpmSwitchTab('keywords',this)">Keywords</button>
            <button class="bpm-ctab"        data-tab="schedule" onclick="bpmSwitchTab('schedule',this)">Schedule</button>
          </div>
          <div class="bpm-ctab-panel active" id="bpm-tab-caption">
            <div class="bpm-lbl">Caption</div>
            <textarea id="bpmCaption" class="bpm-textarea" placeholder="Write your caption…"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="bpm-tab-keywords">
            <div class="bpm-lbl">Keywords</div>
            <textarea id="bpmKeywords" class="bpm-textarea" placeholder="Keywords…" rows="2"></textarea>
            <div class="bpm-lbl" style="margin-top:10px;">Hashtags</div>
            <textarea id="bpmHashtags" class="bpm-textarea" placeholder="#yourhashtag" rows="2"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="bpm-tab-schedule">
            <div class="bpm-lbl">Quick Pick</div>
            <div class="bpm-quick">
              <button class="bpm-qpill" onclick="bpmQuick(this,1)">In 1h</button>
              <button class="bpm-qpill" onclick="bpmQuick(this,6)">In 6h</button>
              <button class="bpm-qpill active" onclick="bpmQuick(this,24)">Tomorrow</button>
              <button class="bpm-qpill" onclick="bpmQuick(this,168)">Next week</button>
            </div>
            <div class="bpm-date-row">
              <div><div class="bpm-lbl">Date</div><input type="date" id="bpmDate" class="bpm-input"></div>
              <div><div class="bpm-lbl">Time</div><input type="time" id="bpmTime" class="bpm-input"></div>
            </div>
          </div>
          <div class="bpm-footer">
            <button class="bpm-dl-btn"    onclick="bpmDownload()">⬇️ Download MP4</button>
            <button class="bpm-btn-now"   onclick="bpmPostNow()">📤 Post Now</button>
            <button class="bpm-btn-sched" onclick="bpmSchedule()">🗓 Schedule</button>
            <button class="bpm-btn-skip"  onclick="closeBpmModal()">Skip</button>
          </div>
        </div>
      </div>
    </div>
    <div id="bpmConfirm" style="display:none;padding-bottom:24px;">
      <div style="text-align:center;font-size:52px;padding:28px 0 0;" id="bpmConfirmIcon">🗓</div>
      <div style="text-align:center;font-size:22px;font-weight:800;color:var(--navy);margin-top:10px;" id="bpmConfirmTitle">Scheduled!</div>
      <div style="text-align:center;font-size:14px;color:var(--muted);margin-top:6px;padding-bottom:6px;" id="bpmConfirmSub"></div>
      <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;padding:14px 20px;" id="bpmConfirmPills"></div>
      <button onclick="closeBpmModal()" style="display:block;margin:0 20px;padding:13px;background:linear-gradient(135deg,var(--green),#059669);border:none;border-radius:12px;font-size:15px;font-weight:700;color:#fff;cursor:pointer;width:calc(100% - 40px);">Done ✓</button>
    </div>
  </div>
</div>

<footer class="ws-footer">
    <div class="ws-footer-brand">🎬 VideoVizard</div>
    <div class="ws-footer-links">
        <a href="vizard_browser.php">Full Dashboard</a>
        <a href="profile.php">Profile</a>
        <a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> VideoVizard</div>
</footer>

<style>
/* BPM styles (minimal reuse) */
.bpm-overlay { display:none;position:fixed;inset:0;background:rgba(15,42,68,.72);backdrop-filter:blur(4px);z-index:99990;align-items:flex-end;justify-content:center;padding:0; }
.bpm-overlay.open { display:flex; }
@media(min-width:600px) { .bpm-overlay{align-items:center;padding:16px;} }
.bpm-modal { background:#fff;border-radius:22px 22px 0 0;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:0 -8px 40px rgba(0,0,0,.25);animation:bpmUp .28s cubic-bezier(.34,1.56,.64,1) both;-webkit-overflow-scrolling:touch; }
@keyframes bpmUp { from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none} }
@media(min-width:600px) { .bpm-modal{border-radius:22px;box-shadow:0 24px 80px rgba(0,0,0,.35);} }
.bpm-head { display:flex;align-items:center;justify-content:space-between;padding:18px 20px 12px;border-bottom:1px solid var(--border); }
.bpm-head-left { display:flex;align-items:center;gap:12px; }
.bpm-head-icon { font-size:26px; }
.bpm-head-title { font-size:16px;font-weight:800;color:var(--navy); }
.bpm-head-sub   { font-size:12px;color:var(--muted);margin-top:2px; }
.bpm-close { background:#f1f5f9;border:none;border-radius:50%;width:32px;height:32px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--muted); }
.bpm-saved { display:flex;align-items:center;gap:10px;padding:10px 20px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;font-size:13px;color:#065f46;font-weight:600; }
.bpm-saved-dot { width:9px;height:9px;border-radius:50%;background:var(--green);flex-shrink:0; }
.bpm-inner { padding:16px 20px 20px; }
.bpm-lbl { font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px; }
.bpm-platforms { display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin-bottom:6px; }
.bpm-plat { display:flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1.5px solid var(--border);font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;transition:.15s;background:#f8fafc; }
.bpm-plat.sel { background:#f0fdf4;border-color:#86efac;color:#065f46; }
.bpm-plat-icon { font-size:15px; }
.bpm-warn { font-size:12px;color:#dc2626;font-weight:600;margin-bottom:8px;padding:6px 10px;background:#fef2f2;border-radius:8px; }
.bpm-ctabs { display:flex;gap:6px;margin:12px 0 6px; }
.bpm-ctab { flex:1;padding:7px 0;border-radius:8px;border:1.5px solid var(--border);font-size:12px;font-weight:700;color:var(--muted);background:#f8fafc;cursor:pointer;transition:.15s; }
.bpm-ctab.active { background:var(--navy);border-color:var(--navy);color:#fff; }
.bpm-ctab-panel { display:none; }
.bpm-ctab-panel.active { display:block; }
.bpm-textarea { width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;color:var(--text);font-family:inherit;resize:vertical;outline:none;min-height:72px;transition:border-color .15s; }
.bpm-textarea:focus { border-color:var(--navy); }
.bpm-quick { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px; }
.bpm-qpill { padding:6px 12px;border-radius:20px;border:1.5px solid var(--border);font-size:12px;font-weight:600;color:var(--muted);background:#f8fafc;cursor:pointer;transition:.15s; }
.bpm-qpill.active { background:var(--navy);border-color:var(--navy);color:#fff; }
.bpm-date-row { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px; }
.bpm-input { width:100%;padding:8px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;color:var(--text);font-family:inherit;outline:none;background:#f8fafc;transition:border-color .15s; }
.bpm-input:focus { border-color:var(--navy); }
.bpm-footer { display:grid;grid-template-columns:1fr 1fr;gap:8px; }
.bpm-dl-btn { grid-column:span 2;padding:10px;background:#f8fafc;border:1.5px solid var(--green);border-radius:10px;font-size:13px;font-weight:700;color:#059669;cursor:pointer;transition:.15s; }
.bpm-btn-now   { padding:10px;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer; }
.bpm-btn-sched { padding:10px;background:linear-gradient(135deg,var(--navy),#0284c7);border:none;border-radius:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer; }
.bpm-btn-skip  { grid-column:span 2;padding:8px;background:none;border:none;font-size:12px;color:#94a3b8;cursor:pointer;text-decoration:underline; }
.bpm-confirm-pill { padding:6px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:20px;font-size:13px;font-weight:700;color:#065f46; }
/* IG grid */
.ig-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:3px; }
.ig-cell { aspect-ratio:1;background:var(--navy);position:relative;cursor:pointer;overflow:hidden; }
.ig-cell img { width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s; }
.ig-cell:hover img { transform:scale(1.06); }
.ig-cell-ov { position:absolute;inset:0;background:rgba(0,0,0,0);transition:background .2s;display:flex;align-items:flex-end;padding:8px; }
.ig-cell:hover .ig-cell-ov { background:rgba(0,0,0,.45); }
.ig-cell-title { font-size:11px;font-weight:700;color:#fff;opacity:0;transition:opacity .2s;line-height:1.3;text-shadow:0 1px 4px rgba(0,0,0,.8); }
.ig-cell:hover .ig-cell-title { opacity:1; }
</style>

<script>
const ADMIN_ID    = <?= (int)$effective_admin ?>;
const COMPANY_ID  = <?= (int)$company_id ?>;
const COMP_TYPE   = <?= json_encode($active_company_type) ?>;

// ── State ─────────────────────────────────────────────────────
let _tab     = 'wip';
let _page    = {};     // per-tab page number
let _hasMore = {};     // per-tab has-more flag
let _loading = {};     // per-tab loading flag
let _loaded  = {};     // per-tab already-loaded flag

// Tab → ajax_load_videos.php status mapping
// For approval/approved/review we use approval_status filter via custom param
const TAB_STATUS = {
    wip:       'active',
    completed: 'completed',
    approval:  'pending_approval',
    approved:  'approved',
    review:    'review_required',
    scheduled: 'scheduled',
    posted:    'posted',
};

document.addEventListener('DOMContentLoaded', () => {
    switchWsTab('wip');
    // Init BPM date
    const d = new Date(); d.setHours(d.getHours() + 24);
    const datEl = document.getElementById('bpmDate');
    const timEl = document.getElementById('bpmTime');
    if (datEl) datEl.value = d.toISOString().split('T')[0];
    if (timEl) timEl.value = d.toTimeString().slice(0, 5);
});

// ── Tab switching ──────────────────────────────────────────────
function switchWsTab(tab) {
    _tab = tab;
    document.querySelectorAll('.ws-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.ws-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tab));
    if (tab === 'analytics') return;
    if (!_loaded[tab]) loadVideos(tab, 1);
}

// ── Load videos ────────────────────────────────────────────────
function loadVideos(tab, page, append = false) {
    if (_loading[tab]) return;
    _loading[tab] = true;
    const status = TAB_STATUS[tab] || tab;

    // Build URL — approval/approved/review use approval_status param
    const approvalStatuses = ['pending_approval','approved','review_required'];
    let url;
    if (approvalStatuses.includes(status)) {
        url = `ajax_load_videos.php?approval_status=${status}&page=${page}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`;
    } else {
        url = `ajax_load_videos.php?status=${status}&page=${page}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`;
    }

    fetch(url).then(r => r.json()).then(data => {
        _loading[tab] = false;
        _loaded[tab]  = true;
        if (data.counts) updateCounts(data.counts);
        const grid = document.getElementById('grid-' + tab);
        if (data.success) {
            grid.innerHTML = append ? grid.innerHTML + data.html : data.html;
            _hasMore[tab]  = data.has_more;
            document.getElementById('lm-' + tab).style.display = data.has_more ? 'block' : 'none';
            patchCardClicks(tab);
            if (tab === 'completed') injectApprovalStrips();
        } else {
            if (!append) {
                const msgs = {
                    wip:       'No videos in progress.',
                    completed: 'No completed videos yet — upload one!',
                    approval:  'No videos sent for approval.',
                    approved:  'No approved videos yet.',
                    review:    'No videos flagged for review.',
                    scheduled: 'Nothing scheduled yet.',
                    posted:    'No posted videos yet.',
                };
                grid.innerHTML = `<div class="empty-state"><div class="ei">${tab==='completed'?'📤':'📭'}</div><p>${msgs[tab]||'Nothing here yet.'}</p></div>`;
            }
        }
    }).catch(() => {
        _loading[tab] = false;
        document.getElementById('grid-' + tab).innerHTML =
            `<div class="empty-state"><div class="ei">⚠️</div><p>Error loading videos. Please try again.</p></div>`;
    });
}

function loadMore(tab) {
    if (!_hasMore[tab] || _loading[tab]) return;
    _page[tab] = (_page[tab] || 1) + 1;
    loadVideos(tab, _page[tab], true);
}

// ── Card clicks → videomaker ───────────────────────────────────
function patchCardClicks(tab) {
    const grid = document.getElementById('grid-' + tab);
    if (!grid) return;
    grid.querySelectorAll('.project-card').forEach(card => {
        if (card.dataset.clickPatched) return;
        card.dataset.clickPatched = '1';
        const pid = card.dataset.id;
        if (!pid) return;
        card.onclick = e => {
            if (e.target.closest('.act-btn') || e.target.closest('.appr-strip')) return;
            window.location.href = 'videomaker.php?podcast_id=' + pid;
        };
    });
}

// ── Approval strips (completed tab) ────────────────────────────
function injectApprovalStrips() {
    document.querySelectorAll('#grid-completed .project-card').forEach(card => {
        if (card.dataset.apprInjected) return;
        card.dataset.apprInjected = '1';
        const pid    = parseInt(card.dataset.id);
        const status = card.dataset.approvalStatus || '';
        const strip  = document.createElement('div');
        strip.className = 'appr-strip';
        _renderApprStrip(strip, pid, status);
        card.appendChild(strip);
    });
}
function _renderApprStrip(strip, pid, status) {
    const styles = {
        '':                    ['2px solid #0284c7','#e0f2fe',''],
        'approval_required':   ['2px solid #f59e0b','#fef3c7',''],
        'approved':            ['2px solid #059669','#d1fae5',''],
        'review_required':     ['2px solid #dc2626','#fee2e2',''],
    };
    const s = styles[status] || styles[''];
    strip.style.borderTop   = s[0];
    strip.style.background  = s[1];
    if (status === '' || status === undefined) {
        strip.innerHTML = `<input type="checkbox" style="width:16px;height:16px;cursor:pointer;" onchange="onApprCb(this,${pid})">
                           <span style="font-size:12px;color:#0284c7;font-weight:700;">Send for Approval</span>`;
    } else if (status === 'approval_required') {
        strip.innerHTML = `<span style="font-size:12px;color:#92400e;font-weight:700;">⏳ Pending Approval</span>`;
    } else if (status === 'approved') {
        strip.innerHTML = `<span style="font-size:12px;color:#065f46;font-weight:700;">✅ Approved</span>`;
    } else if (status === 'review_required') {
        strip.innerHTML = `<span style="font-size:12px;color:#991b1b;font-weight:700;">🔁 Review Required</span>`;
    }
}
async function onApprCb(cb, pid) {
    cb.disabled = true;
    const fd = new FormData();
    fd.append('action',     'set_approval_status');
    fd.append('podcast_id', pid);
    fd.append('status',     cb.checked ? 'approval_required' : '');
    try {
        const r = await fetch('ajax_approval.php', {method:'POST',credentials:'same-origin',body:fd});
        const d = await r.json();
        if (!d.success) { cb.checked = !cb.checked; alert('Failed: ' + (d.message||'Error')); }
        else { loadVideos('completed', 1); loadVideos('approval', 1); }
    } catch(e) { cb.checked = !cb.checked; }
    finally { cb.disabled = false; }
}

// ── Count badges ───────────────────────────────────────────────
function updateCounts(c) {
    const map = {
        active_count:    'tc-wip',
        completed_count: 'tc-completed',
        scheduled_count: 'tc-scheduled',
        posted_count:    'tc-posted',
    };
    for (const [k,id] of Object.entries(map)) {
        const el = document.getElementById(id);
        if (el && c[k] !== undefined) el.textContent = c[k];
    }
}

// ── Approval modal ─────────────────────────────────────────────
function openApprovalModal()  { document.getElementById('approvalModal').classList.add('show'); }
function closeApprovalModal() { document.getElementById('approvalModal').classList.remove('show'); }
async function sendForApproval() {
    const email = document.getElementById('approvalEmail').value.trim();
    if (!email || !email.includes('@')) { alert('Please enter a valid email.'); return; }
    const btn = document.getElementById('confirmApprovalBtn');
    btn.textContent = 'Sending…'; btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action',       'send_for_approval_bulk');
        fd.append('podcast_ids',  JSON.stringify([]));
        fd.append('client_email', email);
        fd.append('company_id',   COMPANY_ID);
        const r = await fetch('ajax_approval.php',{method:'POST',credentials:'same-origin',body:fd});
        await r.json();
        closeApprovalModal();
        alert('✅ Notification sent to ' + email);
    } catch(e) { alert('Error: ' + e.message); }
    finally { btn.textContent='Send Notification'; btn.disabled=false; }
}

// ── Upload modal ───────────────────────────────────────────────
let _upThumb = '';
function openUploadModal() {
    document.getElementById('uploadModal').classList.add('show');
    document.getElementById('upTitle').value = '';
    document.getElementById('upFile').value  = '';
    document.getElementById('upThumbPrev').style.display  = 'none';
    document.getElementById('upProgress').style.display   = 'none';
    document.getElementById('upError').style.display      = 'none';
    document.getElementById('upSubmitBtn').disabled       = false;
    document.getElementById('upSubmitBtn').textContent    = 'Upload Video';
    _upThumb = '';
}
function closeUploadModal() { document.getElementById('uploadModal').classList.remove('show'); }

function captureUpThumb(input) {
    const file = input.files[0];
    if (!file) return;
    _upThumb = '';
    const url = URL.createObjectURL(file);
    const vid = document.getElementById('upThumbVid');
    vid.src = url; vid.load();
    vid.onloadedmetadata = () => { vid.currentTime = Math.min(1, vid.duration * 0.1); };
    vid.onseeked = () => {
        const cv = document.getElementById('upThumbCanvas');
        const r  = Math.min(320/vid.videoWidth, 320/vid.videoHeight, 1);
        cv.width  = Math.round(vid.videoWidth  * r);
        cv.height = Math.round(vid.videoHeight * r);
        cv.getContext('2d').drawImage(vid, 0, 0, cv.width, cv.height);
        _upThumb = cv.toDataURL('image/jpeg', 0.82);
        vid.src = ''; URL.revokeObjectURL(url);
        document.getElementById('upThumbImg').src = _upThumb;
        document.getElementById('upThumbPrev').style.display = 'block';
    };
}

async function submitUpload() {
    const title = document.getElementById('upTitle').value.trim();
    const lang  = document.getElementById('upLang').value;
    const file  = document.getElementById('upFile').files[0];
    const errEl = document.getElementById('upError');
    errEl.style.display = 'none';
    if (!title) { errEl.textContent='Please enter a video title.'; errEl.style.display='block'; return; }
    if (!file)  { errEl.textContent='Please select a video file.'; errEl.style.display='block'; return; }
    if (file.size > 500*1024*1024) { errEl.textContent='File too large. Max 500MB.'; errEl.style.display='block'; return; }

    const btn = document.getElementById('upSubmitBtn');
    btn.disabled = true; btn.textContent = 'Uploading…';
    document.getElementById('upProgress').style.display = 'block';

    const fd = new FormData();
    fd.append('action',     'upload_external_video');
    fd.append('title',      title);
    fd.append('lang_code',  lang);
    fd.append('company_id', COMPANY_ID);
    fd.append('video_file', file);
    if (_upThumb) fd.append('thumbnail_base64', _upThumb);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'ajax_upload_external.php', true);
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded/e.total*100);
            document.getElementById('upProgressBar').style.width = pct + '%';
            document.getElementById('upProgressTxt').textContent = 'Uploading… ' + pct + '%';
        }
    };
    xhr.onload = () => {
        btn.disabled = false; btn.textContent = 'Upload Video';
        try {
            const d = JSON.parse(xhr.responseText);
            if (d.success) {
                closeUploadModal();
                _loaded['completed'] = false;
                switchWsTab('completed');
            } else {
                errEl.textContent = d.message || 'Upload failed.';
                errEl.style.display = 'block';
            }
        } catch(e) { errEl.textContent='Server error.'; errEl.style.display='block'; }
    };
    xhr.onerror = () => { btn.disabled=false; btn.textContent='Upload Video'; errEl.textContent='Network error.'; errEl.style.display='block'; };
    xhr.send(fd);
}

// ── Delete video ───────────────────────────────────────────────
let _delId = null, _delCard = null;
function deleteVideo(id, card) { _delId=id; _delCard=card; document.getElementById('deleteModal').classList.add('show'); }
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('show'); _delId=null; _delCard=null; }
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!_delId) return;
    const id = _delId; const card = _delCard;
    closeDeleteModal();
    card.classList.add('fade-out');
    const fd = new FormData(); fd.append('video_id',id); fd.append('action','delete');
    fetch('ajax_update_video.php',{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json()).then(d=>{
            setTimeout(()=>{ card.remove(); if(d.counts) updateCounts(d.counts); }, 300);
        }).catch(()=>{ card.classList.remove('fade-out'); alert('Delete failed.'); });
});

// ── Instagram Grid ─────────────────────────────────────────────
function openIgGrid() {
    document.getElementById('igModal').classList.add('show');
    const body = document.getElementById('igGridBody');
    body.innerHTML = '<div class="loading-spinner" style="padding:40px 0;"><div class="spinner"></div><p style="margin-top:14px;">Loading…</p></div>';
    fetch(`ajax_load_videos.php?status=completed&page=1&per_page=500&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`)
        .then(r=>r.json()).then(data=>{
            if (!data.success || !data.html) {
                body.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted);">No completed videos found.</div>'; return;
            }
            const parser = new DOMParser();
            const doc    = parser.parseFromString('<div>'+data.html+'</div>','text/html');
            const cards  = doc.querySelectorAll('.project-card');
            if (!cards.length) { body.innerHTML='<div style="padding:40px;text-align:center;">No completed videos.</div>'; return; }
            const cells = Array.from(cards).map(c => {
                const thumb = c.querySelector('img.card-thumb')?.getAttribute('src') || '';
                const title = c.querySelector('.card-title')?.textContent?.trim() || 'Untitled';
                const pid   = c.dataset.id || '';
                return `<div class="ig-cell" onclick="${pid?'window.location.href=\'videomaker.php?podcast_id='+pid+'\'':''}">
                    ${thumb ? `<img src="${esc(thumb)}" loading="lazy" onerror="this.style.display='none'">` : '<span style="font-size:36px;color:rgba(255,255,255,.4);">🎬</span>'}
                    <div class="ig-cell-ov"><div class="ig-cell-title">${esc(title)}</div></div>
                </div>`;
            }).join('');
            body.innerHTML = `<div class="ig-grid">${cells}</div>`;
        }).catch(()=>{ body.innerHTML='<div style="padding:40px;text-align:center;color:var(--muted);">Error loading videos.</div>'; });
}
function closeIgGrid() { document.getElementById('igModal').classList.remove('show'); }

// ── BPM (Post/Schedule/Download modal) ────────────────────────
let _bpm = { podcastId:null, mp4Url:null, filename:null };

async function openBrowserPostModal(podcastId, title, mode) {
    _bpm.podcastId = podcastId; _bpm.mp4Url = null; _bpm.filename = null;
    const ov = document.getElementById('bpmOverlay');
    document.getElementById('bpmMain').style.display    = 'block';
    document.getElementById('bpmConfirm').style.display = 'none';
    document.getElementById('bpmSpinner').style.display = 'flex';
    document.getElementById('bpmBody').style.display    = 'none';
    document.getElementById('bpmSubTitle').textContent  = title || '';
    document.getElementById('bpmWarn').style.display    = 'none';
    if (mode === 'schedule') setTimeout(()=>{ const t=document.querySelector('.bpm-ctab[data-tab="schedule"]'); if(t) t.click(); }, 100);
    ov.classList.add('open');
    await Promise.all([_bpmCheckMp4(podcastId), _bpmLoadCaption(podcastId)]);
    document.getElementById('bpmSpinner').style.display = 'none';
    document.getElementById('bpmBody').style.display    = 'block';
    const savedEl = document.getElementById('bpmSavedLabel');
    if (savedEl && savedEl.innerHTML) { document.querySelector('.bpm-saved').style.display='flex'; }
}
async function _bpmCheckMp4(pid) {
    const url = 'published_videos/podcast_' + pid + '.mp4';
    try {
        const r = await fetch(url,{method:'HEAD'});
        if (r.ok) {
            _bpm.mp4Url = url; _bpm.filename = 'podcast_'+pid+'.mp4';
            const cl = r.headers.get('content-length');
            const mb = cl ? (parseInt(cl)/1024/1024).toFixed(1) : '?';
            const el = document.getElementById('bpmSavedLabel');
            if (el) el.innerHTML = `<span>Video ready — <strong>${_bpm.filename}</strong> · ${mb} MB ✅</span>`;
            return;
        }
    } catch(e) {}
    // Try webm
    const url2 = 'published_videos/podcast_' + pid + '.webm';
    try {
        const r2 = await fetch(url2,{method:'HEAD'});
        if (r2.ok) { _bpm.mp4Url=url2; _bpm.filename='podcast_'+pid+'.webm'; return; }
    } catch(e) {}
}
async function _bpmLoadCaption(pid) {
    try {
        const fd = new FormData(); fd.append('ajax_action','get_podcast_caption_data'); fd.append('podcast_id',pid);
        const r = await fetch('videomaker.php?podcast_id='+pid,{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) {
            const f = n => document.getElementById(n);
            if (f('bpmCaption'))  f('bpmCaption').value  = d.caption_text || '';
            if (f('bpmKeywords')) f('bpmKeywords').value = d.keywords     || '';
            if (f('bpmHashtags')) f('bpmHashtags').value = d.hashtags     || '';
        }
    } catch(e) {}
}
function closeBpmModal() { document.getElementById('bpmOverlay').classList.remove('open'); }
function bpmSwitchTab(tab, btn) {
    document.querySelectorAll('#bpmOverlay .bpm-ctab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#bpmOverlay .bpm-ctab-panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('bpm-tab-'+tab).classList.add('active');
}
function bpmTogglePlat(el) { el.classList.toggle('sel'); document.getElementById('bpmWarn').style.display='none'; }
function bpmGetPlats() { return [...document.querySelectorAll('#bpmOverlay .bpm-plat.sel')].map(e=>e.dataset.p); }
function bpmQuick(btn, hrs) {
    document.querySelectorAll('#bpmOverlay .bpm-qpill').forEach(p=>p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const d = new Date(); d.setHours(d.getHours()+hrs);
    document.getElementById('bpmDate').value = d.toISOString().split('T')[0];
    document.getElementById('bpmTime').value = d.toTimeString().slice(0,5);
}
function bpmDownload() {
    if (!_bpm.mp4Url) { alert('No video file found. Please record the video first.'); return; }
    const a = document.createElement('a'); a.href=_bpm.mp4Url; a.download=_bpm.filename||'video.mp4';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); closeBpmModal();
}
function bpmPostNow() {
    const p = bpmGetPlats(); if (!p.length) { document.getElementById('bpmWarn').style.display='block'; return; }
    _bpmSave('now', p, null);
}
function bpmSchedule() {
    const p = bpmGetPlats(); if (!p.length) { document.getElementById('bpmWarn').style.display='block'; return; }
    const date=document.getElementById('bpmDate').value, time=document.getElementById('bpmTime').value;
    if (!date||!time) { alert('Please select a date and time'); return; }
    _bpmSave('scheduled', p, new Date(date+'T'+time));
}
async function _bpmSave(type, plats, dt) {
    const payload = { podcast_id:_bpm.podcastId, platforms:plats,
        caption:document.getElementById('bpmCaption').value,
        keywords:document.getElementById('bpmKeywords').value,
        hashtags:document.getElementById('bpmHashtags').value,
        sched_date: dt ? dt.toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
        sched_time: dt ? dt.toTimeString().slice(0,5) : new Date().toTimeString().slice(0,5),
        post_type:type, video_filename:_bpm.filename };
    try {
        const r = await fetch('social_schedule.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        await r.json();
    } catch(e) {}
    _bpmShowConfirm(type, plats, dt);
}
function _bpmShowConfirm(type, plats, dt) {
    document.getElementById('bpmMain').style.display    = 'none';
    document.getElementById('bpmConfirm').style.display = 'block';
    const labels = {instagram:'📸 Instagram',tiktok:'🎵 TikTok',youtube:'▶️ YouTube',facebook:'📘 Facebook'};
    if (type==='now') {
        document.getElementById('bpmConfirmIcon').textContent  = '🎉';
        document.getElementById('bpmConfirmTitle').textContent = 'Posted!';
        document.getElementById('bpmConfirmSub').textContent   = 'Going live now';
    } else {
        const ds = dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
        const ts = dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
        document.getElementById('bpmConfirmIcon').textContent  = '🗓';
        document.getElementById('bpmConfirmTitle').textContent = 'Scheduled!';
        document.getElementById('bpmConfirmSub').textContent   = `Posts ${ds} at ${ts}`;
    }
    document.getElementById('bpmConfirmPills').innerHTML =
        plats.map(p=>`<span class="bpm-confirm-pill">${labels[p]||p}</span>`).join('');
    const fd = new FormData(); fd.append('video_id',_bpm.podcastId);
    fd.append('action',type==='now'?'mark_posted':'mark_scheduled');
    fd.append('sched_date',document.getElementById('bpmDate').value);
    fd.append('sched_time',document.getElementById('bpmTime').value);
    fetch('ajax_update_video.php',{method:'POST',body:fd}).catch(()=>{});
    setTimeout(()=>{ _loaded[_tab]=false; loadVideos(_tab,1); }, 1800);
}
document.addEventListener('click',e=>{
    if (e.target===document.getElementById('bpmOverlay')) closeBpmModal();
});

// ── Dropdowns ──────────────────────────────────────────────────
function toggleCoMenu() {
    document.getElementById('coDdMain')?.classList.toggle('open');
    document.getElementById('coBtnMain')?.classList.toggle('open');
}
function toggleProfMenu() {
    document.getElementById('profDdMain')?.classList.toggle('open');
    document.getElementById('profBtnMain')?.classList.toggle('open');
}
document.addEventListener('click', e => {
    const co = document.querySelector('.co-sw');
    if (co && !co.contains(e.target)) { document.getElementById('coDdMain')?.classList.remove('open'); document.getElementById('coBtnMain')?.classList.remove('open'); }
    const pr = document.querySelector('.prof-wrap');
    if (pr && !pr.contains(e.target)) { document.getElementById('profDdMain')?.classList.remove('open'); document.getElementById('profBtnMain')?.classList.remove('open'); }
});
function switchCompany(id) { 
    const url = new URL(window.location.href);
    url.searchParams.set('company_id', id);
    window.location.href = url.toString();
}

// ── Utils ──────────────────────────────────────────────────────
function esc(s) { return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
</script>
</body>
</html>

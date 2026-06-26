<?php
session_start();
$admin_id    = $_SESSION['admin_id']   ?? null;
$admin_level = $_SESSION['level']      ?? '';
$client_id   = $_SESSION['client_id'] ?? '';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';

$user_result = mysqli_query($conn, "SELECT * FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
if ($user_result && mysqli_num_rows($user_result) > 0) {
    $user_data  = mysqli_fetch_assoc($user_result);
    $firstname  = $user_data['firstname']  ?? 'User';
    $lastname   = $user_data['lastname']   ?? '';
    $email      = $user_data['email']      ?? '';
    $level_name = $user_data['level_name'] ?? $admin_level;
    $client_id  = $user_data['client_id']  ?? $client_id;
    $_SESSION['firstname']  = $firstname;
    $_SESSION['lastname']   = $lastname;
    $_SESSION['user_email'] = $email;
    $_SESSION['level_name'] = $level_name;
    $_SESSION['client_id']  = $client_id;
} else {
    $firstname = 'User'; $lastname = '';
}

$companies_result = mysqli_query($conn,
    "SELECT id, companyname FROM hdb_companies WHERE admin_id = $admin_id ORDER BY id ASC");
$companies = [];
while ($cr = mysqli_fetch_assoc($companies_result)) $companies[] = $cr;

if (isset($_GET['company_id'])) {
    $switched = (int)$_GET['company_id'];
    foreach ($companies as $c) {
        if ($c['id'] === $switched) { $_SESSION['company_id'] = $switched; break; }
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if (empty($_SESSION['company_id']) && !empty($companies))
    $_SESSION['company_id'] = (int)$companies[0]['id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$active_company_name = 'My Company';
foreach ($companies as $c) {
    if ((int)$c['id'] === $company_id) { $active_company_name = $c['companyname']; break; }
}
$admin_initial = strtoupper(substr($firstname, 0, 1));

$counts_result = mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN (video_status IS NULL OR video_status = '') AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN video_status = 'RECORDED'  AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN video_status = 'SCHEDULED' AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
        SUM(CASE WHEN video_status = 'POSTED'    AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN archived_flag = 1 THEN 1 ELSE 0 END) as archived_count
     FROM hdb_podcasts WHERE admin_id = $admin_id AND company_id = $company_id");
if (!$counts_result) {
    $counts_result = mysqli_query($conn,
        "SELECT
            SUM(CASE WHEN (video_status IS NULL OR video_status = '') AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN video_status = 'RECORDED'  AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN video_status = 'SCHEDULED' AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
            SUM(CASE WHEN video_status = 'POSTED'    AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
            SUM(CASE WHEN archived_flag = 1 THEN 1 ELSE 0 END) as archived_count
         FROM hdb_podcasts WHERE admin_id = $admin_id");
}
$counts = $counts_result ? mysqli_fetch_assoc($counts_result) : [];
$counts['active_count']    = $counts['active_count']    ?? 0;
$counts['completed_count'] = $counts['completed_count'] ?? 0;
$counts['scheduled_count'] = $counts['scheduled_count'] ?? 0;
$counts['posted_count']    = $counts['posted_count']    ?? 0;
$counts['archived_count']  = $counts['archived_count']  ?? 0;

$camp_count = 0;
$camp_count_res = mysqli_query($conn,
    "SELECT COUNT(*) as total FROM hdb_campaigns WHERE admin_id = $admin_id AND company_id = $company_id");
if ($camp_count_res) $camp_count = mysqli_fetch_assoc($camp_count_res)['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>VideoVizard - Dashboard</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --dark-blue: #0f2a44; --mid-blue: #143b63; --accent: #5fd1ff;
    --green: #10b981; --purple: #8b5cf6; --purple-lt: #ede9fe;
    --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
    --bg: #f0f4f8; --card-bg: #ffffff;
    --shadow: 0 4px 20px rgba(0,0,0,0.08);
    --delete-color: #ef4444; --archive-color: #f59e0b; --restore-color: #10b981;
}
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; -webkit-font-smoothing: antialiased; }

/* ── Header ── */
.vidora-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: linear-gradient(90deg, #0f2a44, #143b63); color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 1000; gap: 12px; }
.brand-container a { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.main-icon { font-size: 24px; }
.logo { font-size: 20px; font-weight: 700; line-height: 1.2; }
.brand-video { color: white; } .brand-vizard { color: var(--accent); }
.tagline { font-size: 10px; color: rgba(255,255,255,0.6); display: none; }
.header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.company-switcher { position: relative; }
.company-btn { background: rgba(95,209,255,0.15); border: 1px solid rgba(95,209,255,0.35); color: #fff; padding: 7px 12px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 7px; transition: all 0.2s; min-height: 40px; max-width: 160px; }
.company-btn .co-icon { font-size: 16px; flex-shrink: 0; }
.company-btn .co-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; }
.company-btn .co-chevron { font-size: 10px; flex-shrink: 0; transition: transform 0.2s; }
.company-btn.open .co-chevron { transform: rotate(180deg); }
.company-dropdown { display: none; position: absolute; top: calc(100% + 8px); right: 0; background: #fff; border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,0.18); min-width: 220px; overflow: hidden; z-index: 9999; border: 1px solid var(--border); }
.company-dropdown.open { display: block; animation: slideDown 0.2s ease; }
.co-dropdown-header { padding: 12px 16px 8px; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
.co-item { padding: 12px 16px; font-size: 14px; color: var(--text); display: flex; align-items: center; gap: 10px; cursor: pointer; transition: background 0.15s; text-decoration: none; }
.co-item:hover { background: #f0f9ff; }
.co-item.active { color: var(--dark-blue); font-weight: 700; }
.co-item .co-check { color: var(--green); font-size: 16px; margin-left: auto; }

.profile-wrap { position: relative; }
.profile-btn { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 8px 12px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: all 0.2s; min-height: 40px; }
.profile-btn .avatar { width: 26px; height: 26px; background: #5fd1ff; color: #0f2a44; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
.profile-btn .username { display: none; }
.profile-btn .chevron { font-size: 11px; transition: transform 0.2s; }
.profile-btn.open .chevron { transform: rotate(180deg); }
.dropdown-menu { display: none; position: absolute; top: calc(100% + 8px); right: 0; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.18); min-width: 200px; overflow: hidden; z-index: 9999; border: 1px solid var(--border); }
.dropdown-menu.open { display: block; animation: slideDown 0.2s ease; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.dropdown-user { padding: 14px 16px; border-bottom: 1px solid var(--border); }
.d-name { font-weight: 700; font-size: 14px; color: var(--dark-blue); }
.d-role { font-size: 12px; color: var(--muted); margin-top: 2px; }
.dropdown-item { padding: 12px 16px; font-size: 14px; color: var(--text); display: flex; align-items: center; gap: 10px; text-decoration: none; transition: background 0.15s; min-height: 44px; }
.dropdown-item:hover { background: #f8fafc; }
.dropdown-item.logout { color: var(--delete-color); }
.dropdown-divider { height: 1px; background: var(--border); }

/* ── Main ── */
.main { width: 100%; padding: 16px; flex: 1; }
.action-bar { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.page-title { font-size: 24px; font-weight: 700; color: var(--dark-blue); }
.page-title span { color: var(--green); }
.btn-create { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 13px 22px; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; box-shadow: 0 4px 15px rgba(16,185,129,0.35); transition: all 0.2s; width: 100%; min-height: 48px; }

/* ── Section Tabs ── */
.section-tabs { display: flex; gap: 4px; margin-bottom: 0; background: var(--card-bg); border-radius: 14px 14px 0 0; border: 1px solid var(--border); border-bottom: none; padding: 6px 6px 0; width: fit-content; }
.section-tab { padding: 10px 22px; border-radius: 10px 10px 0 0; font-size: 15px; font-weight: 600; color: var(--muted); background: transparent; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; min-height: 44px; display: flex; align-items: center; gap: 8px; }
.section-tab.active { background: var(--dark-blue); color: white; }
.section-tab .s-badge { padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; background: rgba(255,255,255,0.2); }
.section-tab:not(.active) .s-badge { background: var(--border); color: var(--muted); }

/* ── Section Panels ── */
.section-panel { display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 0 14px 14px 14px; padding: 20px 16px; }
.section-panel.active { display: block; }

/* ── Video Tab Bar ── */
.tab-bar { display: flex; gap: 6px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 6px; -webkit-overflow-scrolling: touch; scrollbar-width: thin; border-bottom: 2px solid var(--border); }
.tab-bar::-webkit-scrollbar { height: 3px; }
.tab-item { padding: 9px 18px; border-radius: 40px; font-size: 14px; font-weight: 600; color: var(--muted); background: transparent; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; flex-shrink: 0; min-height: 44px; }
.tab-item.active { background: var(--dark-blue); color: white; box-shadow: 0 4px 12px rgba(15,42,68,0.2); }
.tab-count { display: inline-block; margin-left: 6px; padding: 2px 7px; border-radius: 20px; background: rgba(255,255,255,0.2); font-size: 11px; }
.tab-item:not(.active) .tab-count { background: var(--border); color: var(--muted); }

/* ── Video Cards ── */
.cards-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 20px; }
.project-card { background: var(--card-bg); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); cursor: pointer; transition: all 0.25s; text-decoration: none; color: inherit; display: flex; flex-direction: column; position: relative; animation: fadeIn 0.3s ease; aspect-ratio: 9/16; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.project-card.archived { opacity: 0.7; filter: grayscale(0.8); background: #f1f5f9; }
.project-card.fade-out { animation: fadeOut 0.3s ease forwards; }
@keyframes fadeOut { to { opacity: 0; transform: scale(0.9); } }
.status-badge { position: absolute; top: 10px; left: 10px; padding: 5px 10px; border-radius: 30px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 5; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
.status-in-progress { background: #fef3c7; color: #d97706; }
.status-completed   { background: #d1fae5; color: #059669; }
.status-scheduled   { background: #dbeafe; color: #2563eb; }
.status-posted      { background: #ede9fe; color: #7c3aed; }
.status-archived    { background: #e2e8f0; color: #475569; }
.draft-badge { position: absolute; bottom: 10px; left: 10px; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: #7c3aed; color: #fff; z-index: 6; box-shadow: 0 2px 6px rgba(124,58,237,0.4); }
.card-thumb { width: 100%; height: 65%; object-fit: cover; display: block; }
.card-thumb-default { width: 100%; height: 65%; background: linear-gradient(135deg, #0f2a44, #1e4a7a); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: rgba(255,255,255,0.9); }
.card-thumb-default .play-icon { font-size: 42px; opacity: 0.9; }
.card-thumb-default .no-thumb-text { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(0,0,0,0.4); padding: 5px 10px; border-radius: 30px; }
.card-body { padding: 12px; height: 35%; display: flex; flex-direction: column; justify-content: space-between; }
.card-title { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; margin-bottom: 4px; }
.card-meta { display: flex; justify-content: space-between; align-items: center; font-size: 11px; margin-top: auto; }
.card-date { color: var(--muted); }
.card-id { color: var(--muted); background: var(--bg); padding: 3px 7px; border-radius: 10px; font-weight: 600; }
.card-actions { position: absolute; top: 10px; right: 10px; display: flex; gap: 6px; z-index: 10; opacity: 0; transition: opacity 0.2s; }
.project-card:hover .card-actions { opacity: 1; }
.action-btn { width: 40px; height: 40px; border-radius: 10px; border: none; background: white; color: var(--muted); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; box-shadow: 0 3px 10px rgba(0,0,0,0.18); font-size: 18px; }
.action-btn.archive { color: var(--archive-color); }
.action-btn.delete  { color: var(--delete-color); }
.action-btn.restore { color: var(--restore-color); }

/* ── Campaign Table ── */
.campaign-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
.campaign-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.campaign-table thead th { background: var(--dark-blue); color: rgba(255,255,255,0.85); padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
.campaign-table thead th:first-child { border-radius: 12px 0 0 0; }
.campaign-table thead th:last-child  { border-radius: 0 12px 0 0; }
.campaign-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
.campaign-table tbody tr:hover { background: #f0f9ff; }
.campaign-table tbody tr:last-child { border-bottom: none; }
.campaign-table td { padding: 14px 16px; vertical-align: middle; color: var(--text); }
.camp-name { font-weight: 600; color: var(--dark-blue); display: flex; align-items: center; gap: 8px; }
.camp-name .camp-icon { font-size: 18px; }
.camp-niche { font-size: 12px; color: var(--muted); margin-top: 2px; }
.lang-pills { display: flex; gap: 4px; flex-wrap: wrap; }
.lang-pill { padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #e0f2fe; color: #0369a1; white-space: nowrap; }
.camp-status { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
.camp-status.active    { background: #d1fae5; color: #059669; }
.camp-status.paused    { background: #fef3c7; color: #d97706; }
.camp-status.completed { background: #ede9fe; color: #7c3aed; }
.camp-status.draft     { background: #e2e8f0; color: #475569; }
.camp-videos-count { font-weight: 700; color: var(--dark-blue); font-size: 16px; }
.camp-date { color: var(--muted); font-size: 12px; white-space: nowrap; }
.view-camp-btn { padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); background: white; color: var(--dark-blue); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.view-camp-btn:hover { background: var(--dark-blue); color: white; border-color: var(--dark-blue); }

/* ── Campaign Podcast List ── */
.camp-podcast-header {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
}
.camp-podcast-header h3 { font-size: 17px; font-weight: 700; color: var(--dark-blue); margin: 0; }
.camp-back-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--border); background: white; color: var(--muted); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; flex-shrink: 0; }
.camp-back-btn:hover { border-color: var(--dark-blue); color: var(--dark-blue); }
.camp-podcast-meta { font-size: 13px; color: var(--muted); margin-left: auto; }

.podcast-list { display: flex; flex-direction: column; gap: 10px; }
.podcast-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; background: #fff; transition: all 0.2s; cursor: pointer; }
.podcast-row:hover { border-color: var(--purple); background: #faf9ff; box-shadow: 0 4px 12px rgba(139,92,246,0.1); }
.podcast-row.is-draft { border-left: 4px solid #7c3aed; }
.podcast-row-thumb { width: 52px; height: 52px; border-radius: 10px; background: linear-gradient(135deg, #0f2a44, #1e4a7a); display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; overflow: hidden; }
.podcast-row-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.podcast-row-info { flex: 1; min-width: 0; }
.podcast-row-title { font-size: 14px; font-weight: 600; color: var(--dark-blue); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 5px; }
.podcast-row-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.podcast-row-lang { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #e0f2fe; color: #0369a1; }
.podcast-row-date { font-size: 11px; color: var(--muted); }
.podcast-row-status { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
.prs-draft     { background: #ede9fe; color: #7c3aed; }
.prs-active    { background: #fef3c7; color: #d97706; }
.prs-completed { background: #d1fae5; color: #059669; }
.prs-posted    { background: #dbeafe; color: #2563eb; }
.podcast-row-action { flex-shrink: 0; padding: 8px 18px; border-radius: 20px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.podcast-row-action.build { background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #fff; }
.podcast-row-action.build:hover { box-shadow: 0 4px 12px rgba(124,58,237,0.4); }
.podcast-row-action.open  { background: linear-gradient(135deg, #0f2a44, #143b63); color: #fff; }
.podcast-row-action.open:hover { box-shadow: 0 4px 12px rgba(15,42,68,0.3); }

/* ── Misc ── */
.camp-filter-banner { display: none; align-items: center; gap: 10px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 10px; padding: 10px 16px; margin-bottom: 16px; font-size: 13px; color: #0369a1; font-weight: 600; }
.camp-filter-banner.show { display: flex; }
.camp-filter-close { margin-left: auto; cursor: pointer; font-size: 18px; opacity: 0.7; background: none; border: none; color: #0369a1; line-height: 1; }
.camp-filter-close:hover { opacity: 1; }
.loading-spinner { text-align: center; padding: 50px 16px; color: var(--muted); grid-column: 1 / -1; }
.spinner { display: inline-block; width: 44px; height: 44px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.load-more-container { text-align: center; margin: 20px 0; grid-column: 1 / -1; }
.load-more-btn { background: var(--card-bg); border: 1px solid var(--border); color: var(--dark-blue); padding: 14px 30px; border-radius: 40px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: var(--shadow); min-height: 52px; min-width: 180px; }
.load-more-btn.loading { opacity: 0.7; pointer-events: none; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--muted); border-radius: 16px; border: 2px dashed var(--border); grid-column: 1 / -1; }
.empty-state .empty-icon { font-size: 56px; margin-bottom: 16px; }
.empty-state p { font-size: 15px; margin-bottom: 10px; }
.empty-state .empty-hint { font-size: 13px; }

/* ── Delete Modal ── */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
.modal.show { display: flex; animation: fadeIn 0.2s ease; }
.modal-content { background: white; border-radius: 20px; padding: 28px; max-width: 340px; width: 100%; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
.modal-content h3 { font-size: 20px; margin-bottom: 10px; color: var(--dark-blue); }
.modal-content p  { font-size: 15px; color: var(--muted); margin-bottom: 24px; line-height: 1.5; }
.modal-actions { display: flex; gap: 10px; }
.modal-btn { flex: 1; padding: 14px; border: none; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; min-height: 50px; }
.modal-btn.cancel { background: var(--border); color: var(--text); }
.modal-btn.delete { background: var(--delete-color); color: white; }

/* ── Draft Pipeline Modal ── */
.draft-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 3000; align-items: center; justify-content: center; padding: 20px; }
.draft-overlay.open { display: flex; }
.draft-panel { background: #fff; border-radius: 16px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.3); }
.draft-header { padding: 18px 20px 14px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.draft-header h2 { font-size: 17px; font-weight: 700; color: var(--dark-blue); margin: 0; }
.draft-body { padding: 20px; }
.draft-section { margin-bottom: 18px; }
.draft-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; display: block; }
.draft-select { width: 100%; padding: 9px 12px; font-size: 13px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); outline: none; transition: border-color .15s; }
.draft-select:focus { border-color: var(--purple); }
.draft-media-opts { display: flex; gap: 8px; flex-wrap: wrap; }
.draft-media-opt { padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; background: #fff; }
.draft-media-opt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.draft-media-opt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }
.draft-start-btn { width: 100%; padding: 13px; background: linear-gradient(135deg, #0f2a44, #143b63); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s; margin-top: 4px; }
.draft-start-btn:hover { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.draft-close { background: none; border: none; font-size: 22px; color: var(--muted); cursor: pointer; }
.draft-steps { display: flex; flex-direction: column; gap: 10px; margin: 16px 0; }
.draft-step { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
.draft-step.active { border-color: var(--purple); background: var(--purple-lt); }
.draft-step.done   { border-color: var(--green); background: #f0fdf4; }
.draft-step.error  { border-color: #fca5a5; background: #fef2f2; }
.draft-step-icon  { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.draft-step-title { font-size: 13px; font-weight: 600; color: var(--dark-blue); }
.draft-step-sub   { font-size: 12px; color: var(--muted); margin-top: 2px; }
.draft-log { background: #0f2a44; border-radius: 8px; padding: 12px; max-height: 160px; overflow-y: auto; font-family: monospace; font-size: 11px; line-height: 1.6; margin-top: 12px; }
.draft-log-line { margin: 0; }
.draft-log-line.info    { color: #7dd3fc; }
.draft-log-line.success { color: #86efac; }
.draft-log-line.warning { color: #fde68a; }
.draft-log-line.error   { color: #fca5a5; }
.draft-done-bar { background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 14px 18px; margin-top: 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.draft-done-bar a { padding: 10px 22px; background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #fff; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 700; }

/* ── Footer ── */
.site-footer { background: linear-gradient(90deg, #0f2a44, #143b63); color: rgba(255,255,255,0.55); padding: 16px 20px; font-size: 13px; display: flex; flex-direction: column; gap: 10px; text-align: center; margin-top: auto; }
.footer-brand { font-weight: 700; color: #5fd1ff; font-size: 13px; }
.footer-links { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
.footer-links a { color: rgba(255,255,255,0.55); text-decoration: none; transition: color 0.2s; padding: 4px 0; }
.footer-links a:hover { color: #5fd1ff; }

@media (max-width: 380px) { .cards-grid { grid-template-columns: 1fr; } .company-btn .co-name { max-width: 70px; } }
@media (min-width: 768px) {
    .vidora-header { padding: 14px 24px; } .main { padding: 28px 24px; }
    .action-bar { flex-direction: row; justify-content: space-between; align-items: center; }
    .btn-create { width: auto; min-width: 200px; }
    .profile-btn .username { display: inline; } .tagline { display: block; }
    .cards-grid { grid-template-columns: repeat(4, 1fr); gap: 18px; }
    .card-actions { opacity: 0; }
    .site-footer { flex-direction: row; justify-content: space-between; align-items: center; padding: 14px 28px; }
    .section-panel { padding: 24px 20px; }
}
@media (min-width: 1024px) { .cards-grid { grid-template-columns: repeat(6, 1fr); } .main { max-width: 1400px; margin: 0 auto; } }
@media (min-width: 1440px) { .cards-grid { grid-template-columns: repeat(8, 1fr); } }
</style>
</head>
<body>

<header class="vidora-header">
    <div class="brand-container">
        <a href="index.php">
            <span class="main-icon">🎬</span>
            <div>
                <div class="logo"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></div>
                <div class="tagline">Social Media Automation</div>
            </div>
        </a>
    </div>
    <div class="header-right">
        <?php if (count($companies) > 0): ?>
        <div class="company-switcher">
            <button class="company-btn" id="companyBtn" onclick="toggleCompany()">
                <span class="co-icon">🏢</span>
                <span class="co-name"><?= htmlspecialchars($active_company_name) ?></span>
                <?php if (count($companies) > 1): ?><span class="co-chevron">▼</span><?php endif; ?>
            </button>
            <?php if (count($companies) > 1): ?>
            <div class="company-dropdown" id="companyDropdown">
                <div class="co-dropdown-header">Switch Company</div>
                <?php foreach ($companies as $c): ?>
                <a href="?company_id=<?= $c['id'] ?>" class="co-item <?= ((int)$c['id'] === $company_id) ? 'active' : '' ?>">
                    🏢 <?= htmlspecialchars($c['companyname']) ?>
                    <?php if ((int)$c['id'] === $company_id): ?><span class="co-check">✓</span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="profile-wrap">
            <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()">
                <div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
                <span class="username"><?= htmlspecialchars($firstname) ?></span>
                <span class="chevron">▼</span>
            </button>
            <div class="dropdown-menu" id="dropdownMenu">
                <div class="dropdown-user">
                    <div class="d-name"><?= htmlspecialchars($firstname . ' ' . $lastname) ?></div>
                    <div class="d-role"><?= htmlspecialchars($active_company_name) ?></div>
                </div>
                <a href="profile.php"  class="dropdown-item">👤 My Profile</a>
                <a href="settings.php" class="dropdown-item">⚙️ Settings</a>
                <div class="dropdown-divider"></div>
                <a href="logout.php"   class="dropdown-item logout">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="main">
    <div class="action-bar">
        <h1 class="page-title">My <span>Projects</span></h1>
        <a href="vizard_scriptgen.php" class="btn-create">＋ Create New Project</a>
    </div>

    <div class="section-tabs">
        <button class="section-tab active" id="sectionTabVideos" onclick="switchSection('videos')">
            🎬 Videos <span class="s-badge" id="videosTotalBadge"><?= array_sum($counts) ?></span>
        </button>
        <button class="section-tab" id="sectionTabCampaigns" onclick="switchSection('campaigns')">
            🚀 Campaigns <span class="s-badge" id="campTotalBadge"><?= $camp_count ?></span>
        </button>
    </div>

    <!-- ══ VIDEOS PANEL ══ -->
    <div class="section-panel active" id="panelVideos">
        <div class="camp-filter-banner" id="campFilterBanner">
            <span>🚀</span>
            <span id="campFilterLabel">Showing videos from campaign</span>
            <button class="camp-filter-close" onclick="clearCampaignFilter()">✕</button>
        </div>
        <div class="tab-bar">
            <button class="tab-item active" data-tab="active"     onclick="switchTab('active')">Active <span class="tab-count" id="activeTabCount"><?= $counts['active_count'] ?></span></button>
            <button class="tab-item" data-tab="completed"         onclick="switchTab('completed')">Completed <span class="tab-count" id="completedTabCount"><?= $counts['completed_count'] ?></span></button>
            <button class="tab-item" data-tab="scheduled"         onclick="switchTab('scheduled')">Scheduled <span class="tab-count" id="scheduledTabCount"><?= $counts['scheduled_count'] ?></span></button>
            <button class="tab-item" data-tab="posted"            onclick="switchTab('posted')">Posted <span class="tab-count" id="postedTabCount"><?= $counts['posted_count'] ?></span></button>
            <button class="tab-item" data-tab="archived"          onclick="switchTab('archived')">Archived <span class="tab-count" id="archivedTabCount"><?= $counts['archived_count'] ?></span></button>
        </div>
        <div id="videosGrid" class="cards-grid">
            <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading your videos…</p></div>
        </div>
        <div id="loadMoreContainer" class="load-more-container" style="display:none;">
            <button class="load-more-btn" onclick="loadMoreVideos()" id="loadMoreBtn">Load More Videos</button>
        </div>
    </div>

    <!-- ══ CAMPAIGNS PANEL ══ -->
    <div class="section-panel" id="panelCampaigns">
        <!-- View 1: Campaign table -->
        <div id="campaignTableView">
            <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading campaigns…</p></div>
        </div>
        <!-- View 2: Podcast list for a single campaign -->
        <div id="campaignPodcastView" style="display:none;"></div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <h3>Delete Video?</h3>
        <p>This will permanently delete the video and all its files. This cannot be undone.</p>
        <div class="modal-actions">
            <button class="modal-btn cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="modal-btn delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ══ DRAFT PIPELINE MODAL ══ -->
<div class="draft-overlay" id="draftOverlay">
  <div class="draft-panel">
    <div class="draft-header">
      <h2>🎬 Build Video</h2>
      <button class="draft-close" id="draftCloseBtn" onclick="closeDraftModal()">✕</button>
    </div>
    <div class="draft-body">
      <div id="draftSetup">
        <div class="draft-section">
          <label class="draft-label">Host Voice</label>
          <select class="draft-select" id="draftHostVoice"><option value="">Loading voices…</option></select>
        </div>
        <div class="draft-section" id="draftGuestSection" style="display:none;">
          <label class="draft-label">Guest Voice (Podcast)</label>
          <select class="draft-select" id="draftGuestVoice"><option value="">— Same as host —</option></select>
        </div>
        <div class="draft-section">
          <label class="draft-label">Speech Rate</label>
          <select class="draft-select" id="draftRate">
            <option value="0.9">0.9× — Slightly slow</option>
            <option value="1.0" selected>1.0× — Normal</option>
            <option value="1.1">1.1× — Slightly fast</option>
            <option value="1.2">1.2× — Fast</option>
          </select>
        </div>
        <div class="draft-section">
          <label class="draft-label">Media Type</label>
          <div class="draft-media-opts">
            <div class="draft-media-opt sel" data-val="stock_images" onclick="selDraftMedia(this)">📷 Stock Images</div>
            <div class="draft-media-opt" data-val="stock_videos"  onclick="selDraftMedia(this)">🎥 Stock Videos</div>
            <div class="draft-media-opt" data-val="unique_images" onclick="selDraftMedia(this)">🤖 AI Images</div>
          </div>
        </div>
        <button class="draft-start-btn" onclick="startDraftPipeline()">🚀 Build Video Now</button>
      </div>
      <div id="draftProgress" style="display:none;">
        <div class="draft-steps">
          <div class="draft-step" id="draftStep0"><span class="draft-step-icon">📝</span><div><div class="draft-step-title">Create Scenes</div><div class="draft-step-sub">Waiting…</div></div></div>
          <div class="draft-step" id="draftStep1"><span class="draft-step-icon">🎤</span><div><div class="draft-step-title">Generate Audio</div><div class="draft-step-sub">Waiting…</div></div></div>
          <div class="draft-step" id="draftStep2"><span class="draft-step-icon">🖼️</span><div><div class="draft-step-title">Assign Media</div><div class="draft-step-sub">Waiting…</div></div></div>
        </div>
        <div class="draft-log" id="draftLog"></div>
        <div id="draftDoneBar" style="display:none;" class="draft-done-bar">
          <span style="font-size:14px;font-weight:600;color:#166534;">✅ Video ready!</span>
          <a id="draftVideoLink" href="#">Open in VideoMaker →</a>
        </div>
      </div>
    </div>
  </div>
</div>

<footer class="site-footer">
    <div class="footer-brand">🎬 VideoVizard</div>
    <div class="footer-links">
        <a href="vidora_home.php">Home</a><a href="profile.php">Profile</a>
        <a href="settings.php">Settings</a><a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> VideoVizard</div>
</footer>

<script>
const ADMIN_ID    = <?= (int)$admin_id ?>;
const COMPANY_ID  = <?= (int)$company_id ?>;
const S2_ENDPOINT = 'wizard_step2.php';

let currentTab         = 'active';
let currentPage        = 1;
let isLoading          = false;
let hasMore            = true;
let deleteVideoId      = null;
let deleteCardElement  = null;
let activeCampaignId   = null;
let campaignsLoaded    = false;
let currentSection     = 'videos';
let draftPodcastId     = null;
let draftLangCode      = 'en';
let draftMediaType     = 'stock_images';
let draftCancelled     = false;

document.addEventListener('DOMContentLoaded', () => { loadVideos('active', 1); });

// ── Section switcher ──────────────────────────────────────────
function switchSection(section) {
    currentSection = section;
    document.getElementById('sectionTabVideos').classList.toggle('active',    section === 'videos');
    document.getElementById('sectionTabCampaigns').classList.toggle('active', section === 'campaigns');
    document.getElementById('panelVideos').classList.toggle('active',    section === 'videos');
    document.getElementById('panelCampaigns').classList.toggle('active', section === 'campaigns');
    if (section === 'campaigns' && !campaignsLoaded) loadCampaigns();
}

// ── Video tab ─────────────────────────────────────────────────
function switchTab(tab) {
    if (tab === currentTab) return;
    document.querySelectorAll('.tab-item').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    currentTab = tab; currentPage = 1; hasMore = true;
    document.getElementById('videosGrid').innerHTML =
        `<div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading ${tab} videos…</p></div>`;
    document.getElementById('loadMoreContainer').style.display = 'none';
    loadVideos(tab, 1);
}

// ── Load videos ───────────────────────────────────────────────
function loadVideos(status, page, append = false) {
    if (isLoading) return;
    isLoading = true;
    let url = `ajax_load_videos.php?status=${status}&page=${page}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`;
    if (activeCampaignId) url += `&campaign_id=${activeCampaignId}`;
    fetch(url).then(r => r.json()).then(data => {
        isLoading = false;
        if (data.success) {
            document.getElementById('videosGrid').innerHTML = append
                ? document.getElementById('videosGrid').innerHTML + data.html : data.html;
            hasMore = data.has_more;
            document.getElementById('loadMoreContainer').style.display = hasMore ? 'block' : 'none';
            if (data.counts) updateCounts(data.counts);
        } else {
            if (!append) document.getElementById('videosGrid').innerHTML =
                `<div class="empty-state"><div class="empty-icon">📭</div><p>No ${status} videos found</p><div class="empty-hint">Create a new project to get started</div></div>`;
        }
    }).catch(() => {
        isLoading = false;
        document.getElementById('videosGrid').innerHTML =
            `<div class="empty-state"><div class="empty-icon">⚠️</div><p>Error loading videos. Please try again.</p></div>`;
    });
}

function loadMoreVideos() {
    if (!hasMore || isLoading) return;
    currentPage++;
    loadVideos(currentTab, currentPage, true);
    const btn = document.getElementById('loadMoreBtn');
    btn.classList.add('loading'); btn.textContent = 'Loading…';
    setTimeout(() => { btn.classList.remove('loading'); btn.textContent = 'Load More Videos'; }, 1200);
}

function openVideoOrDraft(podcastId, internalStatus, langCode) {
    if (internalStatus === 'draft') openDraftModal(podcastId, langCode || 'en');
    else window.location.href = 'videomaker_9.php?podcast_id=' + podcastId;
}

// ── Load campaigns ────────────────────────────────────────────
function loadCampaigns() {
    fetch(`ajax_load_campaigns.php?admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`)
        .then(r => r.json())
        .then(data => { campaignsLoaded = true; renderCampaignTable(data.campaigns || []); })
        .catch(() => {
            document.getElementById('campaignTableView').innerHTML =
                `<div class="empty-state" style="border:none;"><div class="empty-icon">⚠️</div><p>Error loading campaigns.</p></div>`;
        });
}

function renderCampaignTable(campaigns) {
    document.getElementById('campaignPodcastView').style.display = 'none';
    document.getElementById('campaignTableView').style.display   = 'block';

    if (!campaigns.length) {
        document.getElementById('campaignTableView').innerHTML =
            `<div class="empty-state" style="border:none;"><div class="empty-icon">🚀</div>
            <p>No campaigns yet</p><div class="empty-hint">Create your first campaign to get started</div></div>`;
        return;
    }

    const rows = campaigns.map(c => {
        const langs = JSON.parse(c.languages || '[]');
        const langPills = langs.map(l => `<span class="lang-pill">${l}</span>`).join('');
        const date = c.created_at ? c.created_at.substring(0, 10) : '';
        const sc = c.status || 'active';
        return `<tr>
            <td>
                <div class="camp-name"><span class="camp-icon">🚀</span>${escHtml(c.campaign_name)}</div>
                <div class="camp-niche">${escHtml(c.niche)}${c.category ? ' · ' + escHtml(c.category) : ''}</div>
            </td>
            <td><div class="lang-pills">${langPills || '—'}</div></td>
            <td><span class="camp-videos-count">${c.total_videos}</span></td>
            <td><span class="camp-status ${sc}">${sc}</span></td>
            <td class="camp-date">${date}</td>
            <td>
                <button class="view-camp-btn"
                    onclick="viewCampaignPodcasts(${c.id}, '${escAttr(c.campaign_name)}')">
                    View Videos →
                </button>
            </td>
        </tr>`;
    }).join('');

    document.getElementById('campaignTableView').innerHTML =
        `<div class="campaign-table-wrap"><table class="campaign-table">
            <thead><tr>
                <th>Campaign</th><th>Languages</th><th>Videos</th>
                <th>Status</th><th>Created</th><th></th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table></div>`;
}

// ── View podcasts for a campaign inline ───────────────────────
function viewCampaignPodcasts(campaignId, campaignName) {
    const tableView = document.getElementById('campaignTableView');
    const listView  = document.getElementById('campaignPodcastView');

    tableView.style.display = 'none';
    listView.style.display  = 'block';
    listView.innerHTML = `
        <div class="camp-podcast-header">
            <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
            <h3>🚀 ${escHtml(campaignName)}</h3>
        </div>
        <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading videos…</p></div>`;

    fetch(`ajax_load_campaign_podcasts.php?campaign_id=${campaignId}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`)
        .then(r => r.json())
        .then(data => {
            const podcasts = data.podcasts || [];
            if (!podcasts.length) {
                listView.innerHTML = `
                    <div class="camp-podcast-header">
                        <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                        <h3>🚀 ${escHtml(campaignName)}</h3>
                    </div>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <p>No videos in this campaign yet</p>
                        <div class="empty-hint">Generate scripts from the Wizard to populate this campaign</div>
                    </div>`;
                return;
            }

            const rows = podcasts.map(p => {
                const isDraft  = (p.internal_status === 'draft');
                const langCode = p.lang_code || 'en';
                const date     = p.created_date || '';

                let sc = 'prs-active', st = 'In Progress';
                if (isDraft)                            { sc = 'prs-draft';     st = '⚡ Ready to Build'; }
                else if (p.video_status === 'RECORDED') { sc = 'prs-completed'; st = 'Completed'; }
                else if (p.video_status === 'POSTED')   { sc = 'prs-posted';    st = 'Posted'; }

                const thumbHtml = p.thumbnail
                    ? `<div class="podcast-row-thumb"><img src="${escAttr(p.thumbnail)}" onerror="this.parentNode.innerHTML='🎬'"></div>`
                    : `<div class="podcast-row-thumb">${isDraft ? '⚡' : '🎬'}</div>`;

                const actionBtn = isDraft
                    ? `<button class="podcast-row-action build"
                            onclick="event.stopPropagation(); openDraftModal(${p.id}, '${langCode}')">
                            ⚡ Build</button>`
                    : `<button class="podcast-row-action open"
                            onclick="event.stopPropagation(); window.location.href='videomaker_9.php?podcast_id=${p.id}'">
                            ▶ Open</button>`;

                const rowClick = isDraft
                    ? `openDraftModal(${p.id}, '${langCode}')`
                    : `window.location.href='videomaker_9.php?podcast_id=${p.id}'`;

                return `<div class="podcast-row${isDraft ? ' is-draft' : ''}" onclick="${rowClick}">
                    ${thumbHtml}
                    <div class="podcast-row-info">
                        <div class="podcast-row-title">${escHtml(p.title || 'Untitled')}</div>
                        <div class="podcast-row-meta">
                            <span class="podcast-row-lang">🌐 ${langCode.toUpperCase()}</span>
                            <span class="podcast-row-status ${sc}">${st}</span>
                            <span class="podcast-row-date">📅 ${date}</span>
                        </div>
                    </div>
                    ${actionBtn}
                </div>`;
            }).join('');

            listView.innerHTML = `
                <div class="camp-podcast-header">
                    <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                    <h3>🚀 ${escHtml(campaignName)}</h3>
                    <span class="camp-podcast-meta">${podcasts.length} video${podcasts.length !== 1 ? 's' : ''}</span>
                </div>
                <div class="podcast-list">${rows}</div>`;
        })
        .catch(() => {
            listView.innerHTML = `
                <div class="camp-podcast-header">
                    <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                    <h3>🚀 ${escHtml(campaignName)}</h3>
                </div>
                <div class="empty-state" style="border:none;"><div class="empty-icon">⚠️</div><p>Error loading videos.</p></div>`;
        });
}

function backToCampaignTable() {
    document.getElementById('campaignPodcastView').style.display = 'none';
    document.getElementById('campaignTableView').style.display   = 'block';
}

// ── Counts ────────────────────────────────────────────────────
function updateCounts(counts) {
    const map = { active:'active', completed:'completed', scheduled:'scheduled', posted:'posted', archived:'archived' };
    for (const [key, slug] of Object.entries(map)) {
        if (counts[key] !== undefined)
            document.getElementById(slug + 'TabCount').textContent = counts[key];
    }
}

function clearCampaignFilter() {
    activeCampaignId = null;
    document.getElementById('campFilterBanner').classList.remove('show');
    currentPage = 1; hasMore = true;
    loadVideos(currentTab, 1);
}

// ── Archive / Restore / Delete ────────────────────────────────
function archiveVideo(videoId, cardEl) {
    if (!confirm('Move this video to archive?')) return;
    cardEl.classList.add('fade-out'); postAction({ video_id: videoId, action: 'archive' }, cardEl);
}
function restoreVideo(videoId, cardEl) {
    cardEl.classList.add('fade-out'); postAction({ video_id: videoId, action: 'restore' }, cardEl);
}
function deleteVideo(videoId, cardEl) {
    deleteVideoId = videoId; deleteCardElement = cardEl;
    document.getElementById('deleteModal').classList.add('show');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    deleteVideoId = null; deleteCardElement = null;
}
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!deleteVideoId || !deleteCardElement) { closeDeleteModal(); return; }
    const el = deleteCardElement; el.classList.add('fade-out'); closeDeleteModal();
    postAction({ video_id: deleteVideoId, action: 'delete' }, el);
});
function postAction(payload, cardEl) {
    fetch('ajax_update_video.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
    .then(r => r.json()).then(data => {
        if (data.success) {
            setTimeout(() => {
                cardEl.remove();
                if (data.counts) updateCounts(data.counts);
                if (!document.querySelectorAll('.project-card').length)
                    document.getElementById('videosGrid').innerHTML =
                        `<div class="empty-state"><div class="empty-icon">📭</div><p>No ${currentTab} videos found</p></div>`;
            }, 300);
        } else { cardEl.classList.remove('fade-out'); alert('Action failed'); }
    }).catch(() => { cardEl.classList.remove('fade-out'); alert('Network error'); });
}

// ═══════════════════════════════════════════
//  DRAFT PIPELINE MODAL
// ═══════════════════════════════════════════
function openDraftModal(podcastId, langCode) {
    draftPodcastId = podcastId; draftLangCode = langCode || 'en'; draftCancelled = false;
    document.getElementById('draftSetup').style.display    = 'block';
    document.getElementById('draftProgress').style.display = 'none';
    document.getElementById('draftDoneBar').style.display  = 'none';
    document.getElementById('draftLog').innerHTML = '';
    ['draftStep0','draftStep1','draftStep2'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.className = 'draft-step'; el.querySelector('.draft-step-sub').textContent = 'Waiting…'; }
    });
    draftLoadVoices(langCode);
    document.getElementById('draftOverlay').classList.add('open');
    document.getElementById('draftCloseBtn').style.display = 'inline';
}
function closeDraftModal() { draftCancelled = true; document.getElementById('draftOverlay').classList.remove('open'); }
function selDraftMedia(el) { document.querySelectorAll('.draft-media-opt').forEach(x=>x.classList.remove('sel')); el.classList.add('sel'); draftMediaType = el.dataset.val; }

async function draftLoadVoices(langCode) {
    const host = document.getElementById('draftHostVoice'), guest = document.getElementById('draftGuestVoice');
    try {
        const r = await fetch('get_voices.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'lang_code='+encodeURIComponent(langCode||'en')});
        const d = await r.json(); const voices = d.voices||[]; if (!voices.length) throw new Error('No voices');
        const opts = voices.map(v=>`<option value="${escAttr(v.voice_id)}">${escHtml(v.voice_name)}</option>`).join('');
        host.innerHTML = opts; guest.innerHTML = '<option value="">— Same as host —</option>'+opts;
    } catch(e) {
        const fb=[{id:'openai:alloy',name:'Alloy'},{id:'openai:echo',name:'Echo'},{id:'openai:nova',name:'Nova'},{id:'openai:shimmer',name:'Shimmer'}];
        const opts=fb.map(v=>`<option value="${v.id}">${v.name} (OpenAI)</option>`).join('');
        host.innerHTML=opts; guest.innerHTML='<option value="">— Same as host —</option>'+opts;
    }
}
function draftLog(msg,type='info'){const log=document.getElementById('draftLog');const p=document.createElement('p');p.className='draft-log-line '+type;p.textContent=msg;log.appendChild(p);log.scrollTop=log.scrollHeight;}
function draftStepStatus(n,status,sub){const el=document.getElementById('draftStep'+n);if(!el)return;el.className='draft-step '+status;el.querySelector('.draft-step-sub').textContent=sub;}

async function startDraftPipeline(){
    const hostVoice=document.getElementById('draftHostVoice').value;
    const guestVoice=document.getElementById('draftGuestVoice').value||hostVoice;
    const rate=document.getElementById('draftRate').value;
    if(!hostVoice){alert('Please select a host voice');return;}
    document.getElementById('draftSetup').style.display='none';
    document.getElementById('draftProgress').style.display='block';
    document.getElementById('draftCloseBtn').style.display='none';
    draftCancelled=false;
    const podcastId=draftPodcastId, langCode=draftLangCode;

    // Step 0: create scenes
    draftStepStatus(0,'active','Reading script from database…');
    draftLog('📝 Creating scenes from saved script…','info');
    const fd=new FormData();
    fd.append('action','create_scenes_from_podcast');fd.append('podcast_id',podcastId);
    fd.append('host_voice',hostVoice);fd.append('guest_voice',guestVoice);fd.append('rate',rate);fd.append('lang_code',langCode);
    try{
        const r=await fetch(S2_ENDPOINT,{method:'POST',body:fd});const txt=await r.text();let d;
        try{d=JSON.parse(txt);}catch(e){throw new Error('Server error: '+txt.substring(0,200));}
        if(!d.success)throw new Error(d.message||d.error||'Failed to create scenes');
        draftStepStatus(0,'done',`✓ ${d.scene_count} scenes created`);
        draftLog(`✅ ${d.scene_count} scenes saved`,'success');
    }catch(err){draftStepStatus(0,'error',err.message);draftLog('❌ '+err.message,'error');document.getElementById('draftCloseBtn').style.display='inline';return;}
    if(draftCancelled){draftLog('⏹ Cancelled','warning');return;}

    // Step 1: audio
    draftStepStatus(1,'active','Fetching scenes…');draftLog('🎤 Starting audio generation…','info');
    const scFd=new FormData();scFd.append('action','get_scenes');scFd.append('podcast_id',podcastId);
    let dbScenes=[];
    try{const r=await fetch(S2_ENDPOINT,{method:'POST',body:scFd});dbScenes=await r.json();}
    catch(e){draftLog('⚠ Could not fetch scenes: '+e.message,'warning');}
    let audioDone=0,audioFail=0;
    for(let i=0;i<dbScenes.length;i++){
        if(draftCancelled)break;
        const scene=dbScenes[i],seqNo=i+1;
        draftStepStatus(1,'active',`Audio ${seqNo}/${dbScenes.length}…`);
        const ttsText=(scene.text_contents||'').replace(/<break[^>]*>/gi,'').trim();
        if(!ttsText){audioDone++;continue;}
        const voiceToUse=(scene.actor==='guest'&&guestVoice)?guestVoice:hostVoice;
        const aFd=new FormData();
        aFd.append('action','generate_scene_audio');aFd.append('scene_id',scene.id);
        aFd.append('podcast_id',podcastId);aFd.append('seq_no',seqNo);aFd.append('lang_code',langCode);
        aFd.append('voice_id',voiceToUse);aFd.append('rate',rate);aFd.append('text',ttsText);
        try{const r=await fetch(S2_ENDPOINT,{method:'POST',body:aFd});const d=await r.json();
            if(d.success){audioDone++;draftLog(`✓ Scene ${seqNo} audio OK`,'success');}
            else{audioFail++;draftLog(`✗ Scene ${seqNo}: ${d.error}`,'error');}
        }catch(e){audioFail++;draftLog(`✗ Scene ${seqNo}: ${e.message}`,'error');}
    }
    draftStepStatus(1,audioFail>0?'error':'done',`✓ ${audioDone} audio files${audioFail>0?' ('+audioFail+' failed)':''}`);
    draftLog(`Audio: ${audioDone} OK, ${audioFail} failed`,audioDone>0?'success':'error');
    if(draftCancelled){draftLog('⏹ Cancelled','warning');return;}

    // Step 2: media
    draftStepStatus(2,'active','Searching media library…');draftLog('🖼 Assigning media to scenes…','info');
    const usedFiles=new Set();let mediaDone=0,mediaFail=0;
    for(let i=0;i<dbScenes.length;i++){
        if(draftCancelled)break;
        const scene=dbScenes[i],seqNo=i+1;
        draftStepStatus(2,'active',`Media ${seqNo}/${dbScenes.length}…`);
        if(draftMediaType==='unique_images'){
            try{
                const imgFd=new FormData();imgFd.append('prompt',scene.prompt||scene.text_contents||'');
                imgFd.append('scene_id',scene.id);imgFd.append('podcast_id',podcastId);
                const r=await fetch('generate_image_api.php',{method:'POST',body:imgFd});const d=await r.json();
                if(d.success&&d.filename){await draftAssignImage(scene.id,d.filename,podcastId,seqNo,scene.hashtags||'',0,0,1,scene.prompt||'');mediaDone++;draftLog(`✓ Scene ${seqNo} AI image`,'success');}
                else{mediaFail++;draftLog(`✗ Scene ${seqNo}: ${d.error||'failed'}`,'error');}
            }catch(e){mediaFail++;draftLog(`✗ Scene ${seqNo}: ${e.message}`,'error');}
        }else{
            const nlPhrases=(scene.natural_language_tags||'').split('|').map(p=>p.trim()).filter(Boolean);
            const queries=nlPhrases.length>0?nlPhrases:[(scene.hashtags||'').split(',')[0]||''];
            let found=[];
            for(const q of queries){if(!q)continue;const sFd=new FormData();sFd.append('action','search_images');sFd.append('hashtags',q);try{const r=await fetch(S2_ENDPOINT,{method:'POST',body:sFd});found=await r.json();if(found&&found.length>0)break;}catch(e){}}
            const unique=(found||[]).filter(f=>!usedFiles.has(f.filename));
            if(unique.length>0){const pick=unique[0];usedFiles.add(pick.filename);await draftAssignImage(scene.id,pick.filename,podcastId,seqNo,scene.hashtags||'',found.filter(f=>f.type==='image').length,found.filter(f=>f.type==='video').length,0,'');mediaDone++;draftLog(`✓ Scene ${seqNo}: ${pick.filename}`,'success');}
            else{mediaFail++;draftLog(`⚠ Scene ${seqNo}: no media found`,'warning');}
        }
    }
    draftStepStatus(2,mediaFail===dbScenes.length?'error':'done',`✓ ${mediaDone} scenes assigned`);
    draftLog(`Media: ${mediaDone} assigned, ${mediaFail} not found`,'success');
    document.getElementById('draftCloseBtn').style.display='inline';
    document.getElementById('draftVideoLink').href='videomaker_9.php?podcast_id='+podcastId;
    document.getElementById('draftDoneBar').style.display='flex';
    draftLog('🎉 All done! Click "Open in VideoMaker" to continue.','success');
}

async function draftAssignImage(sceneId,filename,podcastId,sceneNo,hashtags,fi,fv,aiGen,aiPrompt){
    const fd=new FormData();fd.append('action','assign_image');fd.append('scene_id',sceneId);fd.append('filename',filename);
    await fetch(S2_ENDPOINT,{method:'POST',body:fd}).catch(()=>{});
    const lFd=new FormData();lFd.append('action','log_media_search');lFd.append('podcast_id',podcastId);
    lFd.append('scene_id',sceneId);lFd.append('scene_no',sceneNo);lFd.append('hashtags',hashtags);
    lFd.append('found_images',fi);lFd.append('found_videos',fv);lFd.append('selected_file',filename);
    lFd.append('selected_type',filename.match(/\.(mp4|webm|mov)$/i)?'video':'image');
    lFd.append('was_duplicate','0');lFd.append('ai_generated',aiGen);lFd.append('ai_prompt',aiPrompt);
    await fetch(S2_ENDPOINT,{method:'POST',body:lFd}).catch(()=>{});
}

// ── Dropdowns ─────────────────────────────────────────────────
function toggleDropdown(){document.getElementById('dropdownMenu').classList.toggle('open');document.getElementById('profileBtn').classList.toggle('open');}
function toggleCompany(){const dd=document.getElementById('companyDropdown');const btn=document.getElementById('companyBtn');if(dd){dd.classList.toggle('open');btn.classList.toggle('open');}}
document.addEventListener('click',e=>{
    const pw=document.querySelector('.profile-wrap');
    if(pw&&!pw.contains(e.target)){document.getElementById('dropdownMenu')?.classList.remove('open');document.getElementById('profileBtn')?.classList.remove('open');}
    const cs=document.querySelector('.company-switcher');
    if(cs&&!cs.contains(e.target)){document.getElementById('companyDropdown')?.classList.remove('open');document.getElementById('companyBtn')?.classList.remove('open');}
});

function escHtml(str){return String(str).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function escAttr(str){return String(str).replace(/"/g,'&quot;');}
</script>
</body>
</html>

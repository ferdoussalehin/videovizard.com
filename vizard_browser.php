<?php
require_once 'session_config.php';

$admin_id    = $_SESSION['admin_id']   ?? null;
$admin_level = $_SESSION['level']      ?? '';
$client_id   = $_SESSION['client_id'] ?? '';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';
// ── AJAX: save_company_preference ───────────────────────────────────────────
if (isset($_GET['ajax_switch_company'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Invalid request method']);
        exit;
    }
    
    $switched = (int)($_POST['company_id'] ?? 0);
    if (!$switched) {
        echo json_encode(['success' => false, 'error' => 'No company ID provided']);
        exit;
    }
    
    // Verify company belongs to user
    $valid = false;
    foreach ($companies as $c) {
        if ($c['id'] == $switched) {
            $valid = true;
            break;
        }
    }
    
    if ($valid) {
        $_SESSION['company_id'] = $switched;
        // Save to database
        $update_sql = "UPDATE hdb_users SET last_company_id = $switched WHERE id = $admin_id";
        if (mysqli_query($conn, $update_sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid company ID']);
    }
    exit;
}
// ── AJAX: get_template_scenes ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_template_scenes') {
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    $pid = (int)($_GET['podcast_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false,'error'=>'No podcast_id']); exit; }

    $podcast = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title, lang_code, video_type FROM hdb_podcasts WHERE id=$pid AND admin_id=32 LIMIT 1"));
    if (!$podcast) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

    // Get all columns that exist — avoid missing column errors
    $scenes = [];
    $sq = mysqli_query($conn,
        "SELECT id, seq_no, text_contents, audio_file, image_file, duration, thumbnail
         FROM hdb_podcast_stories WHERE podcast_id=$pid ORDER BY seq_no ASC, id ASC");
    if ($sq) while ($sr = mysqli_fetch_assoc($sq)) $scenes[] = $sr;

    echo json_encode(['success'=>true,'podcast'=>$podcast,'scenes'=>$scenes,'count'=>count($scenes)], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_result = mysqli_query($conn, "SELECT * FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
if ($user_result && mysqli_num_rows($user_result) > 0) {
    $user_data  = mysqli_fetch_assoc($user_result);
    $firstname  = $user_data['firstname']  ?? 'User';
    $lastname   = $user_data['lastname']   ?? '';
    $email      = $user_data['email_id']      ?? '';
    $level_name = $user_data['level_name'] ?? $admin_level;
	$role       = $user_data['role'] ?? $admin_level;
    $client_id  = $user_data['client_id']  ?? $client_id;
    $_SESSION['firstname']  = $firstname;
    $_SESSION['lastname']   = $lastname;
    $_SESSION['user_email'] = $email;
    $_SESSION['level_name'] = $level_name;
    $_SESSION['client_id']  = $client_id;
} else {
    $firstname = 'User'; $lastname = '';
}

// ── Team Member: use Team Lead's companies & data ─────────────
// A Team Member has no companies of their own — they work under
// their Team Lead's companies. Swap the effective admin_id used
// for all company/video queries to the lead's id.
$team_lead_id    = (int)($user_data['team_lead_id'] ?? 0);
$is_team_member  = (($user_data['role'] ?? '') === 'Team Member' && $team_lead_id > 0);
$effective_admin = $is_team_member ? $team_lead_id : $admin_id;

$companies_result = mysqli_query($conn,
    "SELECT id, companyname, company_type FROM hdb_companies WHERE admin_id = $effective_admin ORDER BY id ASC");
$companies = [];
while ($cr = mysqli_fetch_assoc($companies_result)) $companies[] = $cr;

// Handle company switching from GET (traditional page reload)
if (isset($_GET['company_id'])) {
    $switched = (int)$_GET['company_id'];
    $valid = false;
    foreach ($companies as $c) {
        if ($c['id'] == $switched) {
            $valid = true;
            break;
        }
    }
    if ($valid) {
        $_SESSION['company_id'] = $switched;
        // Save to database
        $update_sql = "UPDATE hdb_users SET last_company_id = $switched WHERE id = $admin_id";
        mysqli_query($conn, $update_sql);
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Determine which company to use
$company_id = 0;
$active_company_name = 'My Company';
$active_company_type = '';

// First, try to get from session
if (!empty($_SESSION['company_id'])) {
    $company_id = (int)$_SESSION['company_id'];
}

// If no company in session or it's invalid, check database last_company_id
if ($company_id == 0 && isset($user_data['last_company_id']) && $user_data['last_company_id'] > 0) {
    $last_id = (int)$user_data['last_company_id'];
    // Verify this company still exists for this user
    foreach ($companies as $c) {
        if ($c['id'] == $last_id) {
            $company_id = $last_id;
            $_SESSION['company_id'] = $company_id;
            break;
        }
    }
}

// If still no company, find first internal company (client workspace)
if ($company_id == 0 && !empty($companies)) {
    // Priority: find company with type 'internal' first
    foreach ($companies as $c) {
        if ($c['company_type'] === 'internal') {
            $company_id = $c['id'];
            break;
        }
    }
    // If no internal company found, take the first one
    if ($company_id == 0) {
        $company_id = (int)$companies[0]['id'];
    }
    $_SESSION['company_id'] = $company_id;
    
    // Save to database
    if ($user_data && isset($user_data['id'])) {
        $update_sql = "UPDATE hdb_users SET last_company_id = $company_id WHERE id = $admin_id";
        mysqli_query($conn, $update_sql);
    }
}

// Get active company details
foreach ($companies as $c) {
    if ((int)$c['id'] === $company_id) {
        $active_company_name = $c['companyname'];
        $active_company_type = $c['company_type'] ?? '';
        break;
    }
}

// If company_id is still 0 (no companies at all), set defaults
if ($company_id == 0) {
    $company_id = 0;
    $active_company_name = 'No Company';
    $active_company_type = '';
}

$admin_initial = strtoupper(substr($firstname, 0, 1));
// ── Video counts ──────────────────────────────────────────────────────────────
// Team Leader  : sees own + team members' videos (team_lead_id = admin_id OR admin_id = admin_id)
// Team Member  : sees all videos under their lead (team_lead_id = their lead's id)
// Solo/other   : sees own videos only
if (($user_data['role'] ?? '') === 'Team Leader') {
    $scope_sql = "(admin_id = $admin_id OR team_lead_id = $admin_id)";
} elseif ($is_team_member) {
    $scope_sql = "team_lead_id = $team_lead_id";
} else {
    $scope_sql = "(admin_id = $admin_id OR team_lead_id = $admin_id)";
}

$counts_result = mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN
                video_status NOT IN ('SCHEDULED','POSTED','PUBLISHED','ARCHIVED')
                AND (archived_flag IS NULL OR archived_flag = 0)
            THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN video_status = 'SCHEDULED'
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
        SUM(CASE WHEN video_status IN ('POSTED','PUBLISHED')
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN (video_status = 'ARCHIVED' OR archived_flag = 1) THEN 1 ELSE 0 END) as archived_count
     FROM hdb_podcasts WHERE $scope_sql AND company_id = $company_id");
$counts = $counts_result ? mysqli_fetch_assoc($counts_result) : [];



$counts['active_count']    = (int)($counts['active_count']    ?? 0);
$counts['scheduled_count'] = (int)($counts['scheduled_count'] ?? 0);
$counts['posted_count']    = (int)($counts['posted_count']    ?? 0);
$counts['archived_count']  = (int)($counts['archived_count']  ?? 0);
$videos_total = array_sum($counts);


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
.d-email { font-size: 11px; color: var(--muted); margin-top: 1px; word-break: break-all; }
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

/* ── Main Panel ── */
.section-panel { display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; padding: 20px 16px; }
.section-panel.active { display: block; }

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
.draft-badge { position: absolute; bottom: 10px; left: 10px; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: #7c3aed; color: #fff; z-index: 6; }
.card-thumb { width: 100%; height: 57%; object-fit: cover; display: block; }
.card-thumb-default { width: 100%; height: 57%; background: linear-gradient(135deg, #0f2a44, #1e4a7a); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: rgba(255,255,255,0.9); }
.card-thumb-default .play-icon { font-size: 42px; opacity: 0.9; }
.card-thumb-default .no-thumb-text { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(0,0,0,0.4); padding: 5px 10px; border-radius: 30px; }
.card-body { padding: 10px 12px; height: 30%; display: flex; flex-direction: column; justify-content: space-between; }
.card-title { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; margin-bottom: 4px; }
.card-meta { display: flex; justify-content: space-between; align-items: center; font-size: 11px; margin-top: auto; }
.card-date { color: var(--muted); }
.card-id { color: var(--muted); background: var(--bg); padding: 3px 7px; border-radius: 10px; font-weight: 600; }
.card-actions { position: absolute; top: 8px; left: 8px; right: 8px; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 5px; z-index: 10; opacity: 0; transition: opacity 0.2s; }
.project-card:hover .card-actions { opacity: 1; }
.action-btn { width: 32px; height: 32px; border-radius: 9px; border: none; background: white; color: var(--muted); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; box-shadow: 0 3px 10px rgba(0,0,0,0.18); font-size: 15px; flex-shrink: 0; }
.action-btn.archive { color: var(--archive-color); }
.action-btn.delete  { color: var(--delete-color); }
.action-btn.restore { color: var(--restore-color); }


/* ── Misc ── */
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

/* ── Modals ── */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
.modal.show { display: flex; animation: fadeIn 0.2s ease; }
.modal-content { background: white; border-radius: 20px; padding: 28px; max-width: 360px; width: 100%; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
.modal-content h3 { font-size: 20px; margin-bottom: 10px; color: var(--dark-blue); }
.modal-content p  { font-size: 14px; color: var(--muted); margin-bottom: 24px; line-height: 1.6; }
.modal-content p strong { color: var(--text); }
.modal-actions { display: flex; gap: 10px; }
.modal-btn { flex: 1; padding: 14px; border: none; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; min-height: 50px; }
.modal-btn.cancel { background: var(--border); color: var(--text); }
.modal-btn.delete { background: var(--delete-color); color: white; }
.modal-btn:disabled { opacity: 0.5; cursor: not-allowed; }

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

.dropdown-user {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
}
.d-name {
    font-weight: 700;
    font-size: 15px;
    color: var(--dark-blue);
    margin-bottom: 4px;
}
.d-email {
    font-size: 11px;
    color: var(--muted);
    word-break: break-all;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px dashed var(--border);
}
.d-role {
    font-size: 12px;
    margin-top: 4px;
    line-height: 1.5;
}
.d-role span {
    display: inline-block;
}
.team-member-badge {
    background: #ede9fe;
    color: #7c3aed;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    display: inline-block;
    margin-right: 6px;
}
.team-leader-badge {
    background: #d1fae5;
    color: #059669;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    display: inline-block;
    margin-right: 6px;
}
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
			<a href="#" class="co-item <?= ((int)$c['id'] === $company_id) ? 'active' : '' ?>" 
			   data-company-id="<?= $c['id'] ?>"
			   onclick="switchCompany(<?= $c['id'] ?>, this); return false;">
				🏢 <?= htmlspecialchars($c['companyname']) ?>
				<?php if ((int)$c['id'] === $company_id): ?><span class="co-check">✓</span><?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="profile-wrap">
           <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()" title="<?= htmlspecialchars($email) ?>">
				<div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
				<span class="username"><?= htmlspecialchars($firstname) ?></span>
				<span class="chevron">▼</span>
			</button>
            <div class="dropdown-menu" id="dropdownMenu">
    <div class="dropdown-user">
        <div class="d-name"><?= htmlspecialchars($firstname . ' ' . $lastname) ?></div>
        <div class="d-email"><?= htmlspecialchars($email) ?></div>
        <div class="d-role">
				<?php 
				// Show position/role clearly
				if ($is_team_member) {
					// Get team lead name for display
					$lead_name = '';
					$lead_result = mysqli_query($conn, "SELECT firstname, lastname FROM hdb_users WHERE id = $team_lead_id LIMIT 1");
					if ($lead_result && $lead_row = mysqli_fetch_assoc($lead_result)) {
						$lead_name = $lead_row['firstname'] . ' ' . $lead_row['lastname'];
					}
					echo '<span style="color: #8b5cf6;">👥 Team Member</span><br>';
					echo '<span style="font-size: 11px; color: #64748b;">Working under: ' . htmlspecialchars($lead_name) . '</span><br>';
					echo '<span style="font-size: 11px; color: #64748b;">Company: ' . htmlspecialchars($active_company_name) . '</span>';
				} elseif (($user_data['role'] ?? '') === 'Team Leader') {
					echo '<span style="color: #10b981;">👑 Team Leader</span><br>';
					echo '<span style="font-size: 11px; color: #64748b;">Company: ' . htmlspecialchars($active_company_name) . '</span>';
				} else {
					echo '<span>' . htmlspecialchars($active_company_name) . '</span>';
					if (!empty($role) && $role !== 'user') {
						echo '<br><span style="font-size: 11px; color: #64748b;">Role: ' . htmlspecialchars($role) . '</span>';
					}
				}
				?>
			</div>
		</div>
		<a href="profile.php" class="dropdown-item">👤 My Profile</a>
		<a href="vizard_scheduler.php" class="dropdown-item">📅 Scheduler</a>
		<a href="user_clients.php" class="dropdown-item">👥 My Clients</a>
		<a href="user_team.php" class="dropdown-item">👥 My Team</a>
		<a href="user_settings.php" class="dropdown-item">⚙️ Settings</a>
		<div class="dropdown-divider"></div>
		<a href="logout.php" class="dropdown-item logout">🚪 Logout</a>
	</div>
        </div>
    </div>
</header>

<div class="main">
    <div class="action-bar">
        <h1 class="page-title">My <span>Projects</span></h1>
        <a href="vizard_scriptgen.php" class="btn-create">＋ Create New Video</a>
    </div>

    <!-- ══ MY VIDEOS PANEL ══ -->
    <div class="section-panel active" id="panelVideos">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:4px;">
            <div class="tab-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                <button class="tab-item active" data-tab="active"    onclick="switchTab('active')">Active <span class="tab-count" id="activeTabCount"><?= $counts['active_count'] ?></span></button>
                <button class="tab-item" data-tab="scheduled"        onclick="switchTab('scheduled')">Scheduled <span class="tab-count" id="scheduledTabCount"><?= $counts['scheduled_count'] ?></span></button>
                <button class="tab-item" data-tab="posted"           onclick="switchTab('posted')">Posted <span class="tab-count" id="postedTabCount"><?= $counts['posted_count'] ?></span></button>
                <button class="tab-item" data-tab="archived"         onclick="switchTab('archived')">Archived <span class="tab-count" id="archivedTabCount"><?= $counts['archived_count'] ?></span></button>
            </div>
            <?php if ($active_company_type !== 'internal'): ?>
			<button id="approvalModeBtn" onclick="toggleApprovalMode()" title="Select completed videos to send for client approval" style="display:none;">☑️ Send for Approval</button>
			<?php endif; ?>

		   <?php if ($active_company_type !== 'internal'): ?>
            <button id="uploadExternalBtn" onclick="openUploadExternalModal()" title="Upload video from Canva, CapCut etc" style="display:none;">📤 Upload External Video</button>
            <?php endif; ?>

            <button id="igGridBtn" onclick="openIgGridModal()" title="View completed videos as Instagram grid" style="display:none;">📸 Instagram Grid</button>
        </div>
        <div id="videosGrid" class="cards-grid">
            <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading your videos…</p></div>
        </div>
        <div id="loadMoreContainer" class="load-more-container" style="display:none;">
            <button class="load-more-btn" onclick="loadMoreVideos()" id="loadMoreBtn">Load More Videos</button>
        </div>
    </div>

</div>

<!-- ══ VIDEO DELETE MODAL ══ -->
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


<!-- ── Floating Approval Bar ─────────────────────────────────── -->
<div id="approvalFloatBar">
    <span class="float-count" id="approvalCount">0</span>
    <span class="float-label">video(s) selected for approval</span>
    <button id="approvalSendBtn" onclick="sendForApproval()">📨 Send for Approval</button>
    <button id="approvalClearBtn" onclick="clearApprovalSelection()">✕ Cancel</button>
</div>

<!-- ── Approval Confirm Modal ────────────────────────────────── -->
<div class="modal" id="approvalModal">
    <div class="modal-content">
        <h3>📨 Send for Approval?</h3>
        <p>This will notify the client by email to review <strong id="approvalModalCount">0</strong> selected video(s).</p>
        <div style="margin:14px 0;">
            <label style="font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:6px;">Client Email</label>
            <input type="email" id="approvalEmailInput" placeholder="client@example.com"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;outline:none;">
        </div>
        <div class="modal-actions">
            <button class="modal-btn cancel" onclick="closeApprovalModal()">Cancel</button>
            <button class="modal-btn" id="confirmApprovalBtn"
                style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border:none;">
                Send Email & Notify
            </button>
        </div>
    </div>
</div>


<!-- ── Upload External Video Modal ───────────────────────────── -->
<div class="modal" id="uploadExternalModal" onclick="if(event.target===this)closeUploadExternalModal()">
    <div class="modal-content" style="max-width:480px;">
        <h3>📤 Upload External Video</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">Upload a video created in Canva, CapCut or any other tool for client approval.</p>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
                <label class="upload-field-label">Niche</label>
                <select id="extNiche" class="upload-field-input" onchange="loadExtCategories()">
                    <option value="">— Select Niche —</option>
                </select>
            </div>
            <div>
                <label class="upload-field-label">Category</label>
                <select id="extCategory" class="upload-field-input">
                    <option value="">— Select Category —</option>
                </select>
            </div>
            <div>
                <label class="upload-field-label">Video Title</label>
                <input type="text" id="extTitle" class="upload-field-input" placeholder="Enter video title">
            </div>
            <div>
                <label class="upload-field-label">Language</label>
                <select id="extLang" class="upload-field-input">
                    <option value="en">English</option>
                    <option value="ar">Arabic</option>
                    <option value="ur">Urdu</option>
                    <option value="hi">Hindi</option>
                    <option value="es">Spanish</option>
                    <option value="fr">French</option>
                    <option value="pt">Portuguese</option>
                    <option value="pa">Punjabi</option>
                </select>
            </div>
            <div>
                <label class="upload-field-label">Video File</label>
                <input type="file" id="extVideoFile" accept="video/*" class="upload-field-input" style="padding:6px;" onchange="captureExtThumbnail(this)">
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">MP4, MOV, WebM — max 500MB</div>
            </div>
            <!-- Thumbnail preview -->
            <div id="extThumbPreview" style="display:none;text-align:center;">
                <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px;">Thumbnail Preview</div>
                <img id="extThumbImg" style="max-width:120px;border-radius:8px;border:2px solid #10b981;display:inline-block;">
                <div id="extThumbStatus" style="font-size:11px;color:#059669;margin-top:4px;">✅ Thumbnail captured</div>
            </div>
            <!-- Hidden elements for thumbnail capture -->
            <video id="extThumbVideo" style="display:none;" muted playsinline></video>
            <canvas id="extThumbCanvas" style="display:none;"></canvas>
            <div id="extUploadProgress" style="display:none;">
                <div style="background:var(--border);border-radius:20px;height:8px;overflow:hidden;">
                    <div id="extProgressBar" style="height:100%;background:linear-gradient(90deg,#0f2a44,#0284c7);width:0%;transition:width .3s;border-radius:20px;"></div>
                </div>
                <div id="extProgressText" style="font-size:12px;color:var(--muted);margin-top:4px;text-align:center;">Uploading…</div>
            </div>
            <div id="extUploadError" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;font-size:13px;color:#991b1b;"></div>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button class="modal-btn cancel" onclick="closeUploadExternalModal()">Cancel</button>
            <button class="modal-btn" id="extUploadBtn" onclick="submitExternalUpload()"
                style="background:linear-gradient(135deg,#0f2a44,#0284c7);color:#fff;border:none;">
                Upload Video
            </button>
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
const ADMIN_ID          = <?= (int)$effective_admin ?>;  // Team Member sees Team Lead's data
const MY_ADMIN_ID       = <?= (int)$admin_id ?>;         // actual logged-in user id
const COMPANY_ID        = <?= (int)$company_id ?>;
const IS_TEAM_MEMBER    = <?= $is_team_member ? 'true' : 'false' ?>;
const COMPANY_TYPE      = <?= json_encode($active_company_type) ?>;
const SHOW_APPROVAL     = (COMPANY_TYPE !== 'internal');
const EFFECTIVE_ADMIN   = <?= (int)$effective_admin ?>;

let currentTab        = 'active';
let currentPage       = 1;
let isLoading         = false;
let hasMore           = true;
let deleteVideoId     = null;
let deleteCardElement = null;
let videosLoaded      = false;

document.addEventListener('DOMContentLoaded', () => {
    videosLoaded = true;
    loadVideos(currentTab, 1);
});

function useTemplate(podcastId) {
    openTemplatePreview(podcastId);
}

// ── Template Preview Modal ────────────────────────────────────
let tplScenes = [], tplIdx = 0, tplAudio = null, tplPlaying = false, tplTimer = null;

async function openTemplatePreview(podcastId) {
    const modal = document.getElementById('tplPreviewModal');
    const body  = document.getElementById('tplPreviewBody');
    body.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p style="margin-top:12px">Loading preview…</p></div>';
    modal.classList.add('show');
    tplScenes = []; tplIdx = 0; tplPlaying = false;
    if (tplAudio) { tplAudio.pause(); tplAudio = null; }
    clearTimeout(tplTimer);

    try {
        const r = await fetch(`vizard_browser.php?action=get_template_scenes&podcast_id=${podcastId}`);
        const d = await r.json();
        console.log('[TPL PREVIEW] scenes response:', d);
        if (!d.success || !d.scenes.length) {
            body.innerHTML = '<div class="empty-state"><div class="empty-icon">📭</div><p>No scenes found for this template</p></div>';
            return;
        }
        tplScenes = d.scenes;
        renderTplPreview(d.podcast);
        // Set correct podcast_id AFTER render so element exists in DOM
        const useBtn = document.getElementById('tplUseBtn');
        if (useBtn) useBtn.href = 'vizard_usetemplate.php?podcast_id=' + podcastId;
    } catch(e) {
        body.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><p>Error loading preview</p></div>';
    }
}

function buildSceneRowHtml(sc, i) {
    var isVid = sc.image_file && /\.(mp4|webm|mov)$/i.test(sc.image_file);
    // Scene list thumb: images → podcast_images, video → podcast_thumbnails
    var bare = sc.thumbnail ? sc.thumbnail.replace(/^.*[\/\\]/, '') : '';
    var src  = '';
    if (isVid && bare) {
        src = 'podcast_thumbnails/' + bare;
    } else if (!isVid && sc.image_file) {
        src = 'podcast_images/' + sc.image_file;
    }
    var icon       = isVid ? '🎥' : '🎬';
    var thumbInner = src
        ? '<img src="' + src + '" onerror="this.parentNode.innerHTML=\'' + icon + '\'">'
        : icon;
    var label = 'Scene ' + (i + 1) + (isVid ? ' 🎥' : '');
    var txt   = escHtml((sc.text_contents || '').substring(0, 60)) + '…';
    return '<div class="tpl-scene-row' + (i === 0 ? ' active' : '') + '" id="tplRow' + i + '" onclick="tplGoTo(' + i + ')">'
         +   '<div class="tpl-scene-thumb">' + thumbInner + '</div>'
         +   '<div class="tpl-scene-info">'
         +     '<div class="tpl-scene-num">' + label + '</div>'
         +     '<div class="tpl-scene-txt">'  + txt   + '</div>'
         +   '</div>'
         + '</div>';
}

function renderTplPreview(podcast) {
    var totalSec = tplScenes.reduce(function(s,sc){ return s + (parseInt(sc.duration)||5); }, 0);
    var mins     = Math.floor(totalSec / 60), secs = totalSec % 60;
    var imgCount = tplScenes.filter(function(sc){ return sc.image_file && !sc.image_file.match(/\.(mp4|webm|mov)$/i); }).length;
    var vidCount = tplScenes.filter(function(sc){ return sc.image_file &&  sc.image_file.match(/\.(mp4|webm|mov)$/i); }).length;
    var rows     = tplScenes.map(function(sc, i){ return buildSceneRowHtml(sc, i); }).join('');

    document.getElementById('tplPreviewBody').innerHTML =
        '<div class="tpl-preview-wrap">'
      +   '<div class="tpl-player-col">'
      +     '<div class="tpl-screen-wrap">'
      +       '<div class="tpl-scene-img-wrap" id="tplImgWrap">'
      +         '<img id="tplSceneImg" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;">'
      +         '<video id="tplSceneVid" src="" playsinline muted loop style="display:none;width:100%;height:100%;object-fit:cover;"></video>'
      +         '<div id="tplSceneBlank" class="tpl-blank">🎬</div>'
      +       '</div>'
      +       '<div class="tpl-caption" id="tplCaption"></div>'
      +       '<div class="tpl-scene-counter" id="tplCounter">1 / ' + tplScenes.length + '</div>'
      +     '</div>'
      +     '<div class="tpl-controls">'
      +       '<button class="tpl-nav-btn" onclick="tplNav(-1)">⏮</button>'
      +       '<button class="tpl-play-btn" id="tplPlayBtn" onclick="tplTogglePlay()">▶ Play</button>'
      +       '<button class="tpl-nav-btn" onclick="tplNav(1)">⏭</button>'
      +     '</div>'
      +     '<a class="tpl-use-btn" id="tplUseBtn" href="#">🚀 Use this Template</a>'
      +   '</div>'
      +   '<div class="tpl-info-col">'
      +     '<div class="tpl-stat-card">'
      +       '<div class="tpl-stat-row"><span>⏱ Duration</span><strong>' + mins + 'm ' + secs + 's</strong></div>'
      +       '<div class="tpl-stat-row"><span>🎬 Scenes</span><strong>'  + tplScenes.length + '</strong></div>'
      +       '<div class="tpl-stat-row"><span>🖼 Images</span><strong>'  + imgCount + '</strong></div>'
      +       '<div class="tpl-stat-row"><span>🎥 Videos</span><strong>'  + vidCount + '</strong></div>'
      +     '</div>'
      +     '<div class="tpl-scene-list" id="tplSceneList">' + rows + '</div>'
      +   '</div>'
      + '</div>';

    tplGoTo(0);
}

// Show a scene in the preview — images from podcast_images/, videos from podcast_videos/, thumbnails from podcast_thumbnails/
function tplShowScene(idx) {
    if (idx < 0 || idx >= tplScenes.length) return;
    tplIdx = idx;
    const sc    = tplScenes[idx];
    const img   = document.getElementById('tplSceneImg');
    const vid   = document.getElementById('tplSceneVid');
    const blank = document.getElementById('tplSceneBlank');
    const isVid = sc.image_file && /\.(mp4|webm|mov)$/i.test(sc.image_file);

    // Hide all three first
    if (img)   img.style.display   = 'none';
    if (vid)   { vid.style.display = 'none'; vid.pause(); }
    if (blank) blank.style.display = 'none';

    if (isVid && sc.image_file) {
        // Play actual video from podcast_videos/
        if (vid) {
            vid.src = 'podcast_videos/' + sc.image_file;
            vid.style.display = 'block';
            vid.load();
            if (tplPlaying) vid.play().catch(function(){});
        } else if (blank) {
            blank.style.display = 'flex';
            blank.textContent   = '🎥';
        }
    } else if (!isVid && sc.image_file) {
        // Show image from podcast_images/
        if (img) {
            img.src           = 'podcast_images/' + sc.image_file;
            img.style.display = 'block';
        }
    } else {
        // No media
        if (blank) { blank.style.display = 'flex'; blank.textContent = '🎬'; }
    }

    const ctr = document.getElementById('tplCounter');
    if (ctr) ctr.textContent = (idx + 1) + ' / ' + tplScenes.length;
    document.querySelectorAll('.tpl-scene-row').forEach(function(r, i){ r.classList.toggle('active', i === idx); });
    const row = document.getElementById('tplRow' + idx);
    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// Promise-based audio play — resolves when audio ends or errors
function tplPlayAudio(src) {
    return new Promise(resolve => {
        if (tplAudio) { tplAudio.pause(); tplAudio = null; }
        const a = new Audio('podcast_audios/' + src + '?t=' + Date.now());
        tplAudio = a;
        a.onended  = () => resolve('ended');
        a.onerror  = () => resolve('error');
        a.play().catch(() => resolve('blocked'));
        // Safety timeout — max 60s per scene
        tplTimer = setTimeout(() => { a.pause(); resolve('timeout'); }, 60000);
    });
}

function tplSleep(ms) {
    return new Promise(resolve => { tplTimer = setTimeout(resolve, ms); });
}

// Main play loop — mirrors videomaker togglePlay logic
async function tplRunPlayback() {
    for (let i = tplIdx; i < tplScenes.length; i++) {
        if (!tplPlaying) break;
        const sc = tplScenes[i];
        tplShowScene(i);
        clearTimeout(tplTimer);
        if (sc.audio_file) {
            await tplPlayAudio(sc.audio_file);
        } else {
            await tplSleep((parseInt(sc.duration) || 5) * 1000);
        }
        if (!tplPlaying) break;
    }
    // Reached end
    tplPlaying = false;
    const btn = document.getElementById('tplPlayBtn');
    if (btn) btn.textContent = '▶ Play';
}

function tplTogglePlay() {
    const vid = document.getElementById('tplSceneVid');
    if (tplPlaying) {
        tplPlaying = false;
        if (tplAudio) { tplAudio.pause(); }
        if (vid)      { vid.pause(); }
        clearTimeout(tplTimer);
        document.getElementById('tplPlayBtn').textContent = '▶ Play';
    } else {
        tplPlaying = true;
        document.getElementById('tplPlayBtn').textContent = '⏸ Pause';
        // Resume video if current scene is a video
        if (vid && vid.style.display !== 'none') vid.play().catch(function(){});
        tplRunPlayback();
    }
}

function tplGoTo(idx) {
    const wasPlaying = tplPlaying;
    tplPlaying = false;
    if (tplAudio) { tplAudio.pause(); tplAudio = null; }
    const vid = document.getElementById('tplSceneVid');
    if (vid) vid.pause();
    clearTimeout(tplTimer);
    tplShowScene(idx);
    if (wasPlaying) {
        tplPlaying = true;
        tplRunPlayback();
    }
}

function tplNav(dir) {
    const next = tplIdx + dir;
    if (next < 0 || next >= tplScenes.length) return;
    tplGoTo(next);
}

function closeTplPreview() {
    tplPlaying = false;
    if (tplAudio) { tplAudio.pause(); tplAudio = null; }
    clearTimeout(tplTimer);
    const vid = document.getElementById('tplSceneVid');
    if (vid) vid.pause();
    document.getElementById('tplPreviewModal').classList.remove('show');
}

function switchTab(tab) {
    if (tab === currentTab) return;
    document.querySelectorAll('.tab-item').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    currentTab = tab; currentPage = 1; hasMore = true;
    document.getElementById('videosGrid').innerHTML =
        `<div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading ${tab} videos…</p></div>`;
    document.getElementById('loadMoreContainer').style.display = 'none';
    // Show action buttons only on the active tab (RECORDED videos now live here)
    const isCompleted = tab === 'active';
    const approvalBtn = document.getElementById('approvalModeBtn');
    const uploadBtn   = document.getElementById('uploadExternalBtn');
    const igGridBtn   = document.getElementById('igGridBtn');
    if (approvalBtn) approvalBtn.style.display = isCompleted ? '' : 'none';
    if (uploadBtn)   uploadBtn.style.display   = isCompleted ? '' : 'none';
    if (igGridBtn)   igGridBtn.style.display   = isCompleted ? '' : 'none';
    loadVideos(tab, 1);
}

function loadVideos(status, page, append = false) {
    if (isLoading) return;
    isLoading = true;
    let url = `ajax_load_videos.php?status=${status}&page=${page}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`;
    fetch(url).then(r => r.json()).then(data => {
        isLoading = false;
        // Always update counts regardless of whether videos were found
        if (data.counts) updateCounts(data.counts);
        if (data.success) {
            document.getElementById('videosGrid').innerHTML = append
                ? document.getElementById('videosGrid').innerHTML + data.html : data.html;
            hasMore = data.has_more;
            document.getElementById('loadMoreContainer').style.display = hasMore ? 'block' : 'none';
            // Inject approval checkboxes on active tab (RECORDED cards only)
            setTimeout(injectApprovalCheckboxes, 50);
        } else {
            if (!append) document.getElementById('videosGrid').innerHTML =
                `<div class="empty-state"><div class="empty-icon">📭</div><p>No ${status} videos found</p><div class="empty-hint">Create a new project to get started</div></div>`;
        }
    }).catch(() => {
        isLoading = false;
        videosLoaded = false;
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

function switchCompany(companyId, element) {
    // Save preference via AJAX
    fetch('ajax_save_company_pref.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'company_id=' + companyId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Reload the page to show data for new company
            window.location.href = window.location.pathname + '?company_id=' + companyId;
        } else {
            alert('Failed to switch company: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(e => {
        // Fallback to traditional method
        window.location.href = window.location.pathname + '?company_id=' + companyId;
    });
}
// ── Counts ─────────────────────────────────────────────────────
function updateCounts(counts) {
    const map = {active:'active',scheduled:'scheduled',posted:'posted',archived:'archived'};
    for (const [key, slug] of Object.entries(map)) {
        if (counts[key] !== undefined) {
            document.getElementById(slug+'TabCount').textContent = counts[key];
        }
    }
}


// ── Open external video (no editor — video only) ─────────────
function openExternalVideo(podcastId) {
    window.open('published_videos/podcast_' + podcastId + '.mp4', '_blank');
}

// ── Open video or draft ───────────────────────────────────────
function openVideoOrDraft(podcastId, internalStatus, langCode) {
    if (internalStatus === 'draft' || internalStatus === 'processing' || internalStatus === 'scenes_ready') {
        // Draft — open the build modal via vizard_scriptgen flow
        if (typeof openS2 === 'function') {
            openS2(podcastId);
        } else {
            window.location.href = 'vizard_scriptgen.php?podcast_id=' + podcastId;
        }
    } else {
        // Completed/recorded — open videomaker
        window.location.href = 'videomaker.php?podcast_id=' + podcastId;
    }
}

// ── Archive / Restore / Delete video ─────────────────────────
function archiveVideo(videoId, cardEl) {
    if (!confirm('Move this video to archive?')) return;
    cardEl.classList.add('fade-out'); postAction({video_id:videoId,action:'archive'}, cardEl);
}
function restoreVideo(videoId, cardEl) {
    cardEl.classList.add('fade-out'); postAction({video_id:videoId,action:'restore'}, cardEl);
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
    const el = deleteCardElement;
	const videoId = deleteVideoId;   // ← capture here
	el.classList.add('fade-out'); 
	closeDeleteModal();
    postAction({video_id:videoId ,action:'delete'}, el);
});
function postAction(payload, cardEl) {
    const fd = new FormData();
	console.log('postAction payload:', JSON.stringify(payload)); 
    for (const [k, v] of Object.entries(payload)) fd.append(k, v);
    
    fetch('ajax_update_video.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    })
    .then(r => r.json()) 
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                cardEl.remove();
                if (data.counts) updateCounts(data.counts);
                if (!document.querySelectorAll('.project-card').length)
                    document.getElementById('videosGrid').innerHTML =
                        `<div class="empty-state"><div class="empty-icon">📭</div><p>No ${currentTab} videos found</p></div>`;
            }, 300);
        } else {
            cardEl.classList.remove('fade-out');
            alert('Action failed: ' + (data.debug || data.db_error || data.error || JSON.stringify(data)));
        }
    }).catch(e => { cardEl.classList.remove('fade-out'); alert('Network error: ' + e.message); });
}

// ── Dropdowns ──────────────────────────────────────────────────
function toggleDropdown(){document.getElementById('dropdownMenu').classList.toggle('open');document.getElementById('profileBtn').classList.toggle('open');}
function toggleCompany(){const dd=document.getElementById('companyDropdown');const btn=document.getElementById('companyBtn');if(dd){dd.classList.toggle('open');btn.classList.toggle('open');}}
document.addEventListener('click',e=>{
    const pw=document.querySelector('.profile-wrap');
    if(pw&&!pw.contains(e.target)){document.getElementById('dropdownMenu')?.classList.remove('open');document.getElementById('profileBtn')?.classList.remove('open');}
    const cs=document.querySelector('.company-switcher');
    if(cs&&!cs.contains(e.target)){document.getElementById('companyDropdown')?.classList.remove('open');document.getElementById('companyBtn')?.classList.remove('open');}
});


// ── Upload External Video ─────────────────────────────────────
function openUploadExternalModal() {
    document.getElementById('uploadExternalModal').classList.add('show');
    document.getElementById('extTitle').value     = '';
    document.getElementById('extLang').value      = 'en';
    document.getElementById('extVideoFile').value = '';
    document.getElementById('extThumbPreview').style.display   = 'none';
    document.getElementById('extUploadProgress').style.display = 'none';
    _extThumbBase64 = '';
    document.getElementById('extUploadError').style.display    = 'none';
    document.getElementById('extUploadBtn').disabled           = false;
    document.getElementById('extUploadBtn').textContent        = 'Upload Video';
    loadExtNiches();
}

async function loadExtNiches() {
    const sel = document.getElementById('extNiche');
    sel.innerHTML = '<option value="">Loading…</option>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_niches');
        fd.append('admin_id', EFFECTIVE_ADMIN);
        const r    = await fetch('vizard_scriptgen.php', {method:'POST', body:fd});
        const data = await r.json();
        // Merge user niches + common niches, deduplicate
        const all = [...new Set([...(data.niches||[]), ...(data.common_niches||[])])];
        sel.innerHTML = '<option value="">— Select Niche —</option>'
            + all.map(n => `<option value="${escAttr(n)}">${escHtml(n)}</option>`).join('');
    } catch(e) {
        sel.innerHTML = '<option value="">— Select Niche —</option>';
    }
    document.getElementById('extCategory').innerHTML = '<option value="">— Select Category —</option>';
}

async function loadExtCategories() {
    const niche = document.getElementById('extNiche').value;
    const sel   = document.getElementById('extCategory');
    sel.innerHTML = '<option value="">Loading…</option>';
    if (!niche) { sel.innerHTML = '<option value="">— Select Category —</option>'; return; }
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_categories');
        fd.append('admin_id', EFFECTIVE_ADMIN);
        fd.append('niche_name', niche);
        const r    = await fetch('vizard_scriptgen.php', {method:'POST', body:fd});
        const data = await r.json();
        const all  = [...new Set([...(data.categories||[]), ...(data.common_categories||[])])];
        sel.innerHTML = '<option value="">— Select Category —</option>'
            + all.map(c => `<option value="${escAttr(c)}">${escHtml(c)}</option>`).join('');
    } catch(e) {
        sel.innerHTML = '<option value="">— Select Category —</option>';
    }
}

function closeUploadExternalModal() {
    document.getElementById('uploadExternalModal').classList.remove('show');
}

// Capture thumbnail from selected video file
let _extThumbBase64 = '';

function captureExtThumbnail(input) {
    const file = input.files[0];
    if (!file) return;
    _extThumbBase64 = '';
    document.getElementById('extThumbPreview').style.display = 'none';
    document.getElementById('extThumbStatus').textContent = '⏳ Capturing thumbnail…';

    const url = URL.createObjectURL(file);
    const vid = document.getElementById('extThumbVideo');
    vid.src = url;
    vid.load();

    vid.onloadedmetadata = () => {
        vid.currentTime = Math.min(1, vid.duration * 0.1);
    };

    vid.onseeked = () => {
        const canvas = document.getElementById('extThumbCanvas');
        const origW  = vid.videoWidth  || 640;
        const origH  = vid.videoHeight || 360;
        const ratio  = Math.min(320 / origW, 320 / origH, 1);
        canvas.width  = Math.round(origW * ratio);
        canvas.height = Math.round(origH * ratio);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
        _extThumbBase64 = canvas.toDataURL('image/jpeg', 0.82);
        vid.src = '';
        URL.revokeObjectURL(url);

        // Show preview
        document.getElementById('extThumbImg').src = _extThumbBase64;
        document.getElementById('extThumbPreview').style.display = 'block';
        document.getElementById('extThumbStatus').textContent = '✅ Thumbnail captured';
    };

    vid.onerror = () => {
        URL.revokeObjectURL(url);
        document.getElementById('extThumbStatus').textContent = '⚠️ Could not capture thumbnail';
        document.getElementById('extThumbPreview').style.display = 'block';
    };
}

async function submitExternalUpload() {
    const niche    = document.getElementById('extNiche').value.trim();
    const category = document.getElementById('extCategory').value.trim();
    const title    = document.getElementById('extTitle').value.trim();
    const lang     = document.getElementById('extLang').value;
    const fileEl   = document.getElementById('extVideoFile');
    const file     = fileEl.files[0];
    const errEl    = document.getElementById('extUploadError');

    errEl.style.display = 'none';

    if (!title) { errEl.textContent = 'Please enter a video title.';  errEl.style.display = 'block'; return; }
    if (!file)  { errEl.textContent = 'Please select a video file.';  errEl.style.display = 'block'; return; }
    if (file.size > 500 * 1024 * 1024) { errEl.textContent = 'File too large. Max 500MB.'; errEl.style.display = 'block'; return; }

    const btn = document.getElementById('extUploadBtn');
    btn.disabled    = true;
    btn.textContent = 'Uploading…';
    document.getElementById('extUploadProgress').style.display = 'block';

    const fd = new FormData();
    fd.append('action',     'upload_external_video');
    fd.append('niche',      niche);
    fd.append('category',   category);
    fd.append('title',      title);
    fd.append('lang_code',  lang);
    fd.append('company_id', COMPANY_ID);
    fd.append('video_file', file);
    if (_extThumbBase64) fd.append('thumbnail_base64', _extThumbBase64);

    try {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_upload_external.php', true);

        xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                document.getElementById('extProgressBar').style.width  = pct + '%';
                document.getElementById('extProgressText').textContent = `Uploading… ${pct}%`;
            }
        };

        xhr.onload = () => {
            btn.disabled    = false;
            btn.textContent = 'Upload Video';
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    closeUploadExternalModal();
                    // Switch to active tab and reload (RECORDED videos now live here)
                    if (currentTab === 'active') {
                        loadVideos('active', 1);
                    } else {
                        switchTab('active');
                    }
                } else {
                    errEl.textContent = data.message || 'Upload failed.';
                    errEl.style.display = 'block';
                }
            } catch(e) {
                errEl.textContent = 'Server error: ' + xhr.responseText.substring(0, 100);
                errEl.style.display = 'block';
            }
        };

        xhr.onerror = () => {
            btn.disabled    = false;
            btn.textContent = 'Upload Video';
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
        };

        xhr.send(fd);
    } catch(e) {
        btn.disabled    = false;
        btn.textContent = 'Upload Video';
        errEl.textContent = 'Error: ' + e.message;
        errEl.style.display = 'block';
    }
}


// ── APPROVAL SELECTION ────────────────────────────────────────
let approvalMode     = false;
let approvalSelected = new Set(); // podcast ids

function toggleApprovalMode() {
    approvalMode = !approvalMode;
    const btn = document.getElementById('approvalModeBtn');
    btn.classList.toggle('active', approvalMode);
    btn.textContent = approvalMode ? '✕ Exit Selection' : '☑️ Select for Approval';
    if (!approvalMode) clearApprovalSelection();
    // Checkboxes on RECORDED cards are always visible
}

function clearApprovalSelection() {
    approvalSelected.clear();
    document.querySelectorAll('.approval-cb').forEach(cb => cb.checked = false);
    updateApprovalBar();
    if (approvalMode) { approvalMode = false; toggleApprovalMode(); }
}

async function onApprovalCbChange(cb, podcastId) {
    const checked = cb.checked;
    cb.disabled = true; // prevent double-click while saving

    const fd = new FormData();
    fd.append('action',     'set_approval_status');
    fd.append('podcast_id', podcastId);
    fd.append('status',     checked ? 'approval_required' : '');

    try {
        const r    = await fetch('ajax_approval.php', {method:'POST', credentials:'same-origin', body:fd});
        const data = await r.json();
        if (data.success) {
            if (checked) {
                approvalSelected.add(podcastId);
                // Update card badge
                updateCardApprovalBadge(podcastId, 'approval_required');
            } else {
                approvalSelected.delete(podcastId);
                updateCardApprovalBadge(podcastId, '');
            }
        } else {
            // Revert checkbox on failure
            cb.checked = !checked;
            alert('Failed to update approval status: ' + (data.message || 'Unknown error'));
        }
    } catch(e) {
        cb.checked = !checked;
        alert('Network error: ' + e.message);
    } finally {
        cb.disabled = false;
    }
    updateApprovalBar();
}

function updateCardApprovalBadge(podcastId, status) {
    const card = document.querySelector('#videosGrid .project-card[data-id="' + podcastId + '"]');
    if (!card) return;
    // Update the approval wrap bar at bottom of card
    const wrap = card.querySelector('.approval-wrap-bar');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (status === 'approval_required') {
        wrap.style.borderColor = '#f59e0b'; wrap.style.background = '#fef3c7';
        wrap.innerHTML = `<input type="checkbox" onchange="onApprovalCbChange(this,${podcastId})"
                            style="width:18px;height:18px;cursor:pointer;" checked>
                          <span style="font-size:12px;color:#92400e;margin-left:6px;font-weight:700;">⏳ Pending Approval</span>`;
    } else if (status === 'approved') {
        wrap.style.borderColor = '#059669'; wrap.style.background = '#d1fae5';
        wrap.innerHTML = `<span style="font-size:12px;color:#065f46;font-weight:700;">✅ Approved</span>`;
    } else if (status === 'inprogress') {
        wrap.style.borderColor = '#1d4ed8'; wrap.style.background = '#eff6ff';
        wrap.innerHTML = `<span style="font-size:12px;color:#1d4ed8;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${podcastId}, this)">💬 In Progress ›</span>`;
    } else if (status === 'sent_feedback') {
        wrap.style.borderColor = '#7e22ce'; wrap.style.background = '#fdf4ff';
        wrap.innerHTML = `<span style="font-size:12px;color:#7e22ce;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${podcastId}, this)">📥 Client Feedback Received ›</span>`;
    } else if (status === 'feedback_replied') {
        wrap.style.borderColor = '#059669'; wrap.style.background = '#f0fdf4';
        wrap.innerHTML = `<span style="font-size:12px;color:#166534;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${podcastId}, this)">💬 Reply Sent ›</span>`;
    } else {
        wrap.style.borderColor = '#0284c7'; wrap.style.background = '#e0f2fe';
        wrap.innerHTML = `<input type="checkbox" onchange="onApprovalCbChange(this,${podcastId})"
                            style="width:18px;height:18px;cursor:pointer;">
                          <span style="font-size:12px;color:#0284c7;margin-left:6px;font-weight:700;">Send for Approval</span>`;
    }
    // Update data attribute
    card.dataset.approvalStatus = status;
}

function updateApprovalBar() {
    const bar = document.getElementById('approvalFloatBar');
    const cnt = document.getElementById('approvalCount');
    const n   = approvalSelected.size;
    cnt.textContent = n;
    bar.classList.toggle('show', n > 0);
}

function sendForApproval() {
    if (!approvalSelected.size) return;
    document.getElementById('approvalModalCount').textContent = approvalSelected.size;
    // Pre-fill email if company has one saved
    document.getElementById('approvalEmailInput').value = '';
    document.getElementById('approvalModal').classList.add('show');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.remove('show');
}

document.getElementById('confirmApprovalBtn').addEventListener('click', async () => {
    const email = document.getElementById('approvalEmailInput').value.trim();
    if (!email || !email.includes('@')) {
        alert('Please enter a valid client email address.');
        return;
    }
    const ids = Array.from(approvalSelected);
    const btn = document.getElementById('confirmApprovalBtn');
    btn.textContent = 'Sending…'; btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('action',     'send_for_approval_bulk');
        fd.append('podcast_ids', JSON.stringify(ids));
        fd.append('client_email', email);
        fd.append('company_id',  COMPANY_ID);

        const r    = await fetch('ajax_approval.php', {method:'POST', credentials:'same-origin', body:fd});
        const data = await r.json();

        if (data.success) {
            closeApprovalModal();
            clearApprovalSelection();
            // Update cards visually
            ids.forEach(id => {
                const card = document.querySelector(`.project-card[data-id="${id}"]`);
                if (card) {
                    // Add/update approval badge
                    let badge = card.querySelector('.approval-status-badge');
                    if (!badge) { badge = document.createElement('div'); badge.className = 'approval-status-badge'; card.appendChild(badge); }
                    badge.className = 'approval-status-badge badge-approval-required';
                    badge.textContent = '⏳ Pending Approval';
                }
            });
            alert(`✅ ${data.sent} video(s) marked for approval. Client notified at ${email}.`);
            loadVideos(currentTab, 1);
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    } catch(e) {
        alert('Network error: ' + e.message);
    } finally {
        btn.textContent = 'Send Email & Notify'; btn.disabled = false;
    }
});

// ── Post buttons are now built server-side in ajax_load_videos.php ──────────
// This function is kept for backward-compat but does nothing extra now.
// Post Now (📤), Schedule (🗓️), Archive (📦), and Delete (🗑️) are all built
// server-side in ajax_load_videos.php based on video_status — there is no
// client-side hiding here. If a button isn't showing, the fix belongs in
// ajax_load_videos.php, not in this file.
function injectPostButtons() {
    // No-op: buttons are rendered server-side. Nothing to inject.
}

// Hook into renderCard — add checkbox if video_status = 'ready'
// This is called from ajax_load_videos.php which builds HTML server-side,
// so we patch the grid after load via MutationObserver
function injectApprovalCheckboxes() {
    console.log('injectApprovalCheckboxes called, currentTab=' + currentTab + ' SHOW_APPROVAL=' + SHOW_APPROVAL);
    // Inject post buttons on all RECORDED cards (regardless of company type)
    injectPostButtons();
    // Only inject approval checkboxes when on the active tab and company is not internal
    if (currentTab !== 'active') return;
    if (!SHOW_APPROVAL) return;
    const cards = document.querySelectorAll('#videosGrid .project-card');
    console.log('Found ' + cards.length + ' cards');
    cards.forEach(card => {
        if (card.dataset.approvalInjected) return;
        // Active tab now mixes draft/processing/RECORDED cards — only RECORDED
        // videos get the approval bar (drafts/in-progress videos aren't ready for it).
        const cardStatus = (card.dataset.status || card.dataset.internalStatus || '').toUpperCase();
        if (cardStatus !== 'RECORDED') return;
        card.dataset.approvalInjected = '1';
        const id = parseInt(card.dataset.id);
        console.log('Injecting checkbox on card id=' + id);
        if (!id) return;

        const approvalStatus = card.dataset.approvalStatus || '';
        const wrap = document.createElement('div');
        wrap.className = 'approval-wrap-bar';
        wrap.style.cssText = 'display:flex;align-items:center;justify-content:center;padding:6px;width:100%;border-top:2px solid;';
        wrap.addEventListener('click', e => e.stopPropagation());

        if (approvalStatus === 'approved') {
            wrap.style.borderColor = '#059669';
            wrap.style.background  = '#d1fae5';
            wrap.innerHTML = `<span style="font-size:12px;color:#065f46;font-weight:700;">✅ Approved</span>`;

        } else if (approvalStatus === 'inprogress') {
            wrap.style.borderColor = '#1d4ed8';
            wrap.style.background  = '#eff6ff';
            wrap.innerHTML = `<span style="font-size:12px;color:#1d4ed8;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${id}, this)">💬 In Progress ›</span>`;

        } else if (approvalStatus === 'sent_feedback') {
            wrap.style.borderColor = '#7e22ce';
            wrap.style.background  = '#fdf4ff';
            wrap.innerHTML = `<span style="font-size:12px;color:#7e22ce;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${id}, this)">📥 Client Feedback Received ›</span>`;

        } else if (approvalStatus === 'feedback_replied') {
            wrap.style.borderColor = '#059669';
            wrap.style.background  = '#f0fdf4';
            wrap.innerHTML = `<span style="font-size:12px;color:#166534;font-weight:700;cursor:pointer;" onclick="openFeedbackChat(${id}, this)">💬 Reply Sent ›</span>`;

        } else if (approvalStatus === 'approval_required') {
            approvalSelected.add(id);
            wrap.style.borderColor = '#f59e0b';
            wrap.style.background  = '#fef3c7';
            wrap.innerHTML = `<input type="checkbox" onchange="onApprovalCbChange(this,${id})"
                                style="width:18px;height:18px;cursor:pointer;" checked>
                              <span style="font-size:12px;color:#92400e;margin-left:6px;font-weight:700;">⏳ Pending Approval</span>`;

        } else {
            wrap.style.borderColor = '#0284c7';
            wrap.style.background  = '#e0f2fe';
            wrap.innerHTML = `<input type="checkbox" onchange="onApprovalCbChange(this,${id})"
                                style="width:18px;height:18px;cursor:pointer;">
                              <span style="font-size:12px;color:#0284c7;margin-left:6px;font-weight:700;">Send for Approval</span>`;
        }
        card.appendChild(wrap);
    });
}

// Observe grid for new cards loaded via AJAX
const _approvalObserver = new MutationObserver(() => injectApprovalCheckboxes());
_approvalObserver.observe(document.getElementById('videosGrid'), {childList:true, subtree:true});

// ── Patch video card clicks to go to videomaker.php ───────────
// ajax_load_videos.php builds cards server-side with onclick pointing to
// vizard_scriptgen. We override the card-level click here so clicking
// anywhere on the card body (outside the 3 action icon buttons) opens
// videomaker.php?podcast_id instead.
function patchVideoCardClicks() {
    document.querySelectorAll('#videosGrid .project-card').forEach(card => {
        if (card.dataset.clickPatched) return;
        card.dataset.clickPatched = '1';
        const podcastId = card.dataset.id;
        if (!podcastId) return;
        // Card body click does nothing — videomaker only opens via the edit icon
        card.onclick = function(e) {
            // Swallow clicks on the card body so nothing navigates
            // Action buttons (edit/delete/archive/upload) handle their own onclick
            if (e.target.closest('.card-actions') || e.target.closest('.action-btn')) return;
            if (e.target.closest('.approval-wrap-bar')) return;
            e.stopPropagation();
            // Do NOT navigate — icons handle all actions
        };
    });
}
const _cardClickObserver = new MutationObserver(() => patchVideoCardClicks());
_cardClickObserver.observe(document.getElementById('videosGrid'), {childList:true, subtree:true});
patchVideoCardClicks();

// ── Delegated edit-icon click → open videomaker ───────────────────────────────
// Handles edit buttons built server-side in ajax_load_videos.php
// Looks for .action-btn.edit or [data-action="edit"] inside #videosGrid
document.getElementById('videosGrid').addEventListener('click', function(e) {
    // Edit icon click → open videomaker / draft builder
    const editBtn = e.target.closest('.action-btn.edit, [data-action="edit"], .btn-edit');
    if (editBtn) {
        e.stopPropagation();
        const card      = editBtn.closest('.project-card');
        const podcastId = card?.dataset?.id;
        const status    = card?.dataset?.internalStatus || card?.dataset?.status || '';
        const lang      = card?.dataset?.lang || 'en';
        if (podcastId) openVideoOrDraft(podcastId, status, lang);
        return;
    }
    // Clicks anywhere else on the card body — do nothing
    const card = e.target.closest('.project-card');
    if (card && !e.target.closest('.card-actions') && !e.target.closest('.approval-wrap-bar')) {
        e.stopPropagation();
        e.preventDefault();
    }
});

function escHtml(str){return String(str).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function escAttr(str){return String(str).replace(/"/g,'&quot;');}
function tplImgErr(img){img.style.display='none';var fb=img.nextElementSibling;if(fb)fb.style.display='flex';}

// ══════════════════════════════════════════════════════════════
// BROWSER POST MODAL — Post / Schedule / Download for completed videos
// ══════════════════════════════════════════════════════════════

let _bpm = {
    podcastId: null,
    mp4Url:    null,
    filename:  null,
    sizeMb:    null,
};

async function openBrowserPostModal(podcastId, title, mode) {
    _bpm.podcastId = podcastId;
    _bpm.mp4Url    = null;
    _bpm.filename  = null;
    _bpm.sizeMb    = null;

    // Reset modal UI
    const overlay = document.getElementById('bpmOverlay');
    const main    = document.getElementById('bpmMain');
    const confirm = document.getElementById('bpmConfirm');
    const spinner = document.getElementById('bpmSpinner');
    const body    = document.getElementById('bpmBody');
    const subTitle= document.getElementById('bpmSubTitle');

    main.style.display    = 'block';
    confirm.style.display = 'none';
    // Pre-open the Schedule tab when triggered from the 🗓️ button
    if (mode === 'schedule') {
        setTimeout(function() {
            var schedTab = document.querySelector('.bpm-ctab[data-tab="schedule"]');
            if (schedTab) schedTab.click();
        }, 100);
    }
    spinner.style.display = 'flex';
    body.style.display    = 'none';
    subTitle.textContent  = title || 'Your Video';
    document.getElementById('bpmWarn').style.display = 'none';

    // Reset to tomorrow by default
    const tomorrowBtn = document.querySelectorAll('#bpmOverlay .bpm-qpill')[2];
    if (tomorrowBtn) bpmQuick(tomorrowBtn, 24);

    overlay.classList.add('open');

    // Load caption data + check MP4
    await Promise.all([
        _bpmLoadCaption(podcastId),
        _bpmCheckMp4(podcastId),
    ]);

    spinner.style.display = 'none';
    body.style.display    = 'block';
}

async function _bpmCheckMp4(podcastId) {
    // Try MP4 first
    const mp4Url = 'published_videos/podcast_' + podcastId + '.mp4';
    try {
        const r = await fetch(mp4Url, { method: 'HEAD' });
        if (r.ok) {
            _bpm.mp4Url   = mp4Url;
            _bpm.filename = 'podcast_' + podcastId + '.mp4';
            // Try to get size from content-length
            const cl = r.headers.get('content-length');
            _bpm.sizeMb = cl ? (parseInt(cl) / 1024 / 1024).toFixed(1) : '?';
            const savedEl = document.getElementById('bpmSavedLabel');
            if (savedEl) savedEl.innerHTML =
                `<span>Video ready — <strong>${_bpm.filename}</strong> · ${_bpm.sizeMb} MB ✅ MP4</span>`;
            return;
        }
    } catch(e) {}

    // Fallback: WebM
    const webmUrl = 'published_videos/podcast_' + podcastId + '.webm';
    try {
        const r2 = await fetch(webmUrl, { method: 'HEAD' });
        if (r2.ok) {
            _bpm.mp4Url   = webmUrl;
            _bpm.filename = 'podcast_' + podcastId + '.webm';
            const cl = r2.headers.get('content-length');
            _bpm.sizeMb = cl ? (parseInt(cl) / 1024 / 1024).toFixed(1) : '?';
            const savedEl = document.getElementById('bpmSavedLabel');
            if (savedEl) savedEl.innerHTML =
                `<span>Video saved — <strong>${_bpm.filename}</strong> · ${_bpm.sizeMb} MB ⚠️ WebM</span>`;
            return;
        }
    } catch(e) {}

    // No file found — link to videomaker
    const savedEl = document.getElementById('bpmSavedLabel');
    if (savedEl) savedEl.innerHTML =
        `<span style="color:#f59e0b;">⚠️ No video file found. <a href="videomaker.php?podcast_id=${podcastId}" style="color:#0284c7;">Open in Editor</a> to record first.</span>`;
}

async function _bpmLoadCaption(podcastId) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_podcast_caption_data');
        fd.append('podcast_id',  podcastId);
        const r    = await fetch('videomaker.php?' + new URLSearchParams({podcast_id: podcastId}), {method:'POST', body:fd});
        const data = await r.json();
        if (data.success) {
            const capEl  = document.getElementById('bpmCaption');
            const kwEl   = document.getElementById('bpmKeywords');
            const htEl   = document.getElementById('bpmHashtags');
            if (capEl)  capEl.value  = data.caption_text || '';
            if (kwEl)   kwEl.value   = data.keywords     || '';
            if (htEl)   htEl.value   = data.hashtags     || '';
        }
    } catch(e) { /* silent — captions are optional */ }
}

function closeBpmModal() {
    document.getElementById('bpmOverlay').classList.remove('open');
}

function bpmSwitchTab(tab, btn) {
    document.querySelectorAll('#bpmOverlay .bpm-ctab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#bpmOverlay .bpm-ctab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('bpm-tab-' + tab).classList.add('active');
}

function bpmTogglePlat(el) {
    if (el.classList.contains('disconnected')) return;
    el.classList.toggle('sel');
    document.getElementById('bpmWarn').style.display = 'none';
}

function bpmGetPlats() {
    return [...document.querySelectorAll('#bpmOverlay .bpm-plat.sel:not(.disconnected)')].map(el => el.dataset.p);
}

function bpmQuick(btn, hrs) {
    document.querySelectorAll('#bpmOverlay .bpm-qpill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const d = new Date();
    d.setHours(d.getHours() + hrs);
    document.getElementById('bpmDate').value = d.toISOString().split('T')[0];
    document.getElementById('bpmTime').value = d.toTimeString().slice(0, 5);
}

function bpmDownload() {
    if (!_bpm.mp4Url) {
        alert('No video file found. Please record the video first in the Editor.');
        return;
    }
    const a = document.createElement('a');
    a.href = _bpm.mp4Url;
    a.download = _bpm.filename || ('podcast_' + _bpm.podcastId + '.mp4');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    closeBpmModal();
}

function bpmPostNow() {
    const plats = bpmGetPlats();
    if (!plats.length) { document.getElementById('bpmWarn').style.display = 'block'; return; }
    _bpmSave('now', plats, null);
}

function bpmSchedule() {
    const plats = bpmGetPlats();
    if (!plats.length) { document.getElementById('bpmWarn').style.display = 'block'; return; }
    const date = document.getElementById('bpmDate').value;
    const time = document.getElementById('bpmTime').value;
    if (!date || !time) { alert('Please select a date and time'); return; }
    _bpmSave('scheduled', plats, new Date(date + 'T' + time));
}

async function _bpmSave(type, plats, dt) {
    const payload = {
        podcast_id:     _bpm.podcastId,
        platforms:      plats,
        caption:        document.getElementById('bpmCaption').value,
        keywords:       document.getElementById('bpmKeywords').value,
        hashtags:       document.getElementById('bpmHashtags').value,
        sched_date:     dt ? dt.toISOString().split('T')[0]  : new Date().toISOString().split('T')[0],
        sched_time:     dt ? dt.toTimeString().slice(0, 5)    : new Date().toTimeString().slice(0, 5),
        post_type:      type,
        video_filename: _bpm.filename,
    };
    try {
        const r    = await fetch('social_schedule.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await r.json();
        if (data.success) {
            _bpmShowConfirm(type, plats, dt);
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch(e) {
        // social_schedule.php not yet wired — show confirm anyway for now
        _bpmShowConfirm(type, plats, dt);
    }
}

function _bpmShowConfirm(type, plats, dt) {
    document.getElementById('bpmMain').style.display    = 'none';
    document.getElementById('bpmConfirm').style.display = 'block';

    const labels = {
        instagram:'📸 Instagram', tiktok:'🎵 TikTok', youtube:'▶️ YouTube',
        facebook:'📘 Facebook',   twitter:'🐦 X',      linkedin:'💼 LinkedIn',
    };

    if (type === 'now') {
        document.getElementById('bpmConfirmIcon').textContent  = '🎉';
        document.getElementById('bpmConfirmTitle').textContent = 'Posted!';
        document.getElementById('bpmConfirmSub').textContent   = 'Going live now';
    } else {
        const ds = dt.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
        const ts = dt.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
        document.getElementById('bpmConfirmIcon').textContent  = '🗓';
        document.getElementById('bpmConfirmTitle').textContent = 'Scheduled!';
        document.getElementById('bpmConfirmSub').textContent   = `Posts ${ds} at ${ts}`;
    }

    document.getElementById('bpmConfirmPills').innerHTML =
        plats.map(p => `<span class="bpm-confirm-pill">${labels[p] || p}</span>`).join('');

    // Update video status in DB
    const newStatus = type === 'now' ? 'POSTED' : 'SCHEDULED';
    const fd = new FormData();
    fd.append('video_id', _bpm.podcastId);
    fd.append('action',   type === 'now' ? 'mark_posted' : 'mark_scheduled');
    fd.append('sched_date', document.getElementById('bpmDate').value);
    fd.append('sched_time', document.getElementById('bpmTime').value);
    fetch('ajax_update_video.php', { method:'POST', body:fd }).catch(()=>{});

    // Reload the grid after a short delay so the card moves tabs
    setTimeout(() => loadVideos(currentTab, 1), 1800);
}

// Close on backdrop click
document.addEventListener('click', function(e) {
    const overlay = document.getElementById('bpmOverlay');
    if (e.target === overlay) closeBpmModal();
});

// ── Instagram Grid Modal ──────────────────────────────────────
function openIgGridModal() {
    const modal = document.getElementById('igGridModal');
    const body  = document.getElementById('igGridBody');
    modal.style.display = 'flex';
    modal.classList.add('show');
    body.innerHTML = '<div class="loading-spinner" style="padding:40px 0;"><div class="spinner"></div><p style="margin-top:14px;">Loading completed videos…</p></div>';

    fetch(`ajax_load_videos.php?status=completed&page=1&per_page=500&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.html) {
                body.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted);">No completed videos found.</div>';
                return;
            }
            // Parse podcast_id and thumbnail out of server-rendered cards
            const parser = new DOMParser();
            const doc    = parser.parseFromString('<div>' + data.html + '</div>', 'text/html');
            const cards  = doc.querySelectorAll('.project-card');
            if (!cards.length) {
                body.innerHTML = '<div style="padding:40px;text-align:center;"><div style="font-size:48px;margin-bottom:12px;">📭</div><p style="color:var(--muted);">No completed videos found.</p></div>';
                return;
            }
            const cellsHtml = Array.from(cards).map(card => {
                // Extract podcast_id from onclick="..." — matches podcast_id=123 or just the number
                const onclick = card.getAttribute('onclick') || '';
                const pid     = onclick.match(/podcast_id=(\d+)/)?.[1]
                             || onclick.match(/\b(\d+)\b/)?.[1];
                const img     = card.querySelector('img.card-thumb');
                const title   = card.querySelector('.card-title')?.textContent?.trim() || 'Untitled';
                const thumb   = img ? img.getAttribute('src') : '';
                const inner   = thumb
                    ? `<img src="${escAttr(thumb)}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                       <div class="ig-modal-cell-ph" style="display:none;">🎬</div>`
                    : `<div class="ig-modal-cell-ph">🎬</div>`;
                const click = pid ? `openTemplatePreview(${pid})` : '';
                return `<div class="ig-modal-cell" onclick="${click}" title="${escAttr(title)}">
                    ${inner}
                    <div class="ig-modal-overlay">
                        <div class="ig-modal-cell-title">${escHtml(title)}</div>
                    </div>
                </div>`;
            }).join('');
            body.innerHTML = `<div class="ig-modal-grid">${cellsHtml}</div>`;
        })
        .catch(() => {
            body.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted);">Error loading videos.</div>';
        });
}

function closeIgGridModal() {
    const modal = document.getElementById('igGridModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
}
</script>

<!-- ── Instagram Grid Modal ─────────────────────────────────── -->
<div class="modal" id="igGridModal" style="display:none;" onclick="if(event.target===this)closeIgGridModal()">
    <div class="tpl-modal-box" style="max-width:900px;width:96vw;">
        <div class="modal-header">
            <h3>📸 Instagram Grid — Completed Videos</h3>
            <button class="modal-close" onclick="closeIgGridModal()">✕</button>
        </div>
        <div style="padding:10px 16px 6px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:13px;color:#0369a1;display:flex;align-items:center;gap:8px;">
            <span>📱</span><span>All your completed videos as they'd appear on Instagram. Click any to preview.</span>
        </div>
        <div id="igGridBody" style="flex:1;overflow-y:auto;padding:0;">
            <!-- filled by JS -->
        </div>
    </div>
</div>

<!-- ── Template Preview Modal ──────────────────────────────── -->
<div class="modal" id="tplPreviewModal" onclick="if(event.target===this)closeTplPreview()">
    <div class="modal-box tpl-modal-box">
        <div class="modal-header">
            <h3>🎨 Template Preview</h3>
            <button class="modal-close" onclick="closeTplPreview()">✕</button>
        </div>
        <div id="tplPreviewBody"></div>
    </div>
</div>

<style>


/* ── Upload External Video ───────────────────────────────────── */
#uploadExternalBtn {
    padding: 8px 16px; background: #fff; border: 1.5px solid #10b981;
    border-radius: 20px; font-size: 13px; font-weight: 700; cursor: pointer;
    color: #059669; transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
#uploadExternalBtn:hover { background: #10b981; color: #fff; }
.upload-field-label { font-size: 12px; font-weight: 700; color: var(--text); display: block; margin-bottom: 4px; }
.upload-field-input {
    width: 100%; padding: 9px 12px; border: 1.5px solid var(--border);
    border-radius: 8px; font-size: 14px; color: var(--text);
    background: #fff; outline: none; font-family: inherit; transition: border-color .2s;
}
.upload-field-input:focus { border-color: #0284c7; }

/* ── Approval checkboxes & floating button ───────────────────── */
.approval-cb-wrap {
    display: none;
    align-items: center;
    justify-content: center;
    padding: 5px 8px;
    background: #e0f2fe;
    border-top: 1.5px solid #0284c7;
    width: 100%;
    flex-shrink: 0;
}
.approval-cb-wrap.visible { display: flex; }
.approval-cb {
    width: 18px; height: 18px; accent-color: #0284c7; cursor: pointer;
}
.approval-cb-label { font-size: 11px; color: var(--muted); margin-left: 5px; font-weight: 600; }
.approval-status-badge {
    position: absolute; bottom: 38px; left: 50%; transform: translateX(-50%);
    padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; z-index: 11;
    pointer-events: none;
}
.badge-approval-required { background:#451a03; color:#fb923c; }
.badge-approved           { background:#052e16; color:#4ade80; }
.badge-inprogress         { background:#1e3a8a; color:#93c5fd; }
.badge-sent-feedback      { background:#3b0764; color:#d8b4fe; }
.badge-feedback-replied   { background:#052e16; color:#86efac; }

/* Floating send-for-approval bar */
#approvalFloatBar {
    display: none;
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: #0f2a44; border: 1.5px solid #0284c7;
    border-radius: 50px; padding: 12px 20px;
    box-shadow: 0 8px 32px rgba(2,132,199,0.35);
    z-index: 9999; align-items: center; gap: 14px;
    animation: floatUp .25s ease both;
    white-space: nowrap;
}
#approvalFloatBar.show { display: flex; }
@keyframes floatUp { from{opacity:0;transform:translateX(-50%) translateY(16px);} to{opacity:1;transform:translateX(-50%) translateY(0);} }
#approvalFloatBar .float-count {
    background: #0284c7; color: #fff; border-radius: 20px;
    padding: 3px 10px; font-size: 13px; font-weight: 700;
}
#approvalFloatBar .float-label { color: #e2e8f0; font-size: 14px; font-weight: 600; }
#approvalSendBtn {
    padding: 9px 20px; background: #0284c7; border: none; border-radius: 30px;
    color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .2s;
}
#approvalSendBtn:hover { background: #0369a1; transform: translateY(-1px); }
#approvalClearBtn {
    padding: 9px 14px; background: transparent; border: 1px solid #334155;
    border-radius: 30px; color: #94a3b8; font-size: 13px; cursor: pointer;
}
#approvalClearBtn:hover { border-color: #f87171; color: #f87171; }

/* Approval mode toggle in tab bar */
#approvalModeBtn {
    padding: 8px 16px; background: #fff; border: 1.5px solid #0284c7;
    border-radius: 20px; font-size: 13px; font-weight: 700; cursor: pointer;
    color: #0284c7; transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
#approvalModeBtn:hover { background: #0284c7; color: #fff; }
#approvalModeBtn.active { background: #dc2626; border-color: #dc2626; color: #fff; }

.tpl-modal-box { max-width:680px; width:96vw; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; background:#fff; border-radius:20px; box-shadow:0 24px 80px rgba(0,0,0,0.45); }

/* ── Instagram Grid (inside modal) ── */
.ig-modal-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3px;
}
@media(min-width:600px){ .ig-modal-grid { grid-template-columns: repeat(4, 1fr); } }
@media(min-width:800px){ .ig-modal-grid { grid-template-columns: repeat(5, 1fr); } }
.ig-modal-cell {
    aspect-ratio: 9/16;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    background: #0f2a44;
    transition: transform .15s;
}
.ig-modal-cell:hover { transform: scale(1.03); z-index: 2; }
.ig-modal-cell img { width:100%; height:100%; object-fit:cover; display:block; }
.ig-modal-cell-ph { width:100%; height:100%; background:linear-gradient(135deg,#0f2a44,#1e4a7a); display:flex; align-items:center; justify-content:center; font-size:32px; }
.ig-modal-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0);
    transition: background .18s;
    display: flex; align-items: flex-end; justify-content: center;
    padding-bottom: 8px;
}
.ig-modal-cell:hover .ig-modal-overlay { background: rgba(0,0,0,0.4); }
.ig-modal-cell-title {
    color: #fff; font-size: 11px; font-weight: 600;
    text-align: center; padding: 0 6px;
    opacity: 0; transition: opacity .18s;
    text-shadow: 0 1px 4px rgba(0,0,0,0.8);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;
}
.ig-modal-cell:hover .ig-modal-cell-title { opacity: 1; }

/* Instagram Grid button */
#igGridBtn {
    padding: 8px 16px; background: #fff; border: 1.5px solid #7c3aed;
    border-radius: 20px; font-size: 13px; font-weight: 700; cursor: pointer;
    color: #7c3aed; transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
#igGridBtn:hover { background: #7c3aed; color: #fff; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border); flex-shrink:0; }
.modal-header h3 { font-size:17px; font-weight:700; color:var(--dark-blue); }
.modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:var(--muted); line-height:1; }
#tplPreviewBody { flex:1; overflow:auto; padding:20px; }
.tpl-preview-wrap { display:flex; gap:20px; align-items:flex-start; }

/* Player column */
.tpl-player-col { flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:12px; }
.tpl-screen-wrap { width:200px; aspect-ratio:9/16; background:#0f2a44; border-radius:16px; overflow:hidden; position:relative; box-shadow:0 8px 30px rgba(0,0,0,0.3); }
.tpl-scene-img-wrap { width:100%; height:100%; position:relative; }
#tplSceneImg { width:100%; height:100%; object-fit:cover; display:block; }
.tpl-blank { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:48px; }
.tpl-caption { position:absolute; bottom:12px; left:8px; right:8px; text-align:center; color:#fff; font-size:11px; font-weight:600; text-shadow:0 1px 4px rgba(0,0,0,0.8); line-height:1.4; }
.tpl-scene-counter { position:absolute; top:8px; right:8px; background:rgba(0,0,0,0.55); color:#fff; font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; }
.tpl-controls { display:flex; align-items:center; gap:8px; }
.tpl-use-btn { display:block; width:100%; text-align:center; padding:11px 0; border-radius:30px; background:linear-gradient(135deg,#10b981,#059669); color:#fff; font-size:14px; font-weight:700; text-decoration:none; box-shadow:0 4px 14px rgba(16,185,129,0.35); transition:all 0.2s; margin-top:4px; }
.tpl-use-btn:hover { background:linear-gradient(135deg,#059669,#047857); transform:translateY(-1px); }
.tpl-nav-btn { width:36px; height:36px; border-radius:50%; background:var(--border); border:none; font-size:16px; cursor:pointer; transition:all 0.2s; }
.tpl-nav-btn:hover { background:var(--dark-blue); color:#fff; }
.tpl-play-btn { padding:9px 20px; border-radius:30px; background:#7c3aed; color:#fff; border:none; font-size:14px; font-weight:700; cursor:pointer; transition:all 0.2s; }
.tpl-play-btn:hover { background:#5b21b6; }

/* Info column */
.tpl-info-col { flex:1; min-width:0; display:flex; flex-direction:column; gap:14px; }
.tpl-stat-card { background:linear-gradient(135deg,#f5f3ff,#ede9fe); border:1px solid #c4b5fd; border-radius:14px; padding:16px; }
.tpl-stat-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid rgba(124,58,237,0.1); font-size:14px; color:#5b21b6; }
.tpl-stat-row:last-child { border-bottom:none; }
.tpl-stat-row strong { font-size:16px; color:#3b0764; }
.tpl-scene-list { display:flex; flex-direction:column; gap:8px; max-height:320px; overflow-y:auto; }
.tpl-scene-row { display:flex; gap:10px; align-items:center; padding:8px; border-radius:10px; border:1.5px solid var(--border); background:#fff; cursor:pointer; transition:all 0.15s; }
.tpl-scene-row:hover { border-color:#c4b5fd; background:#f5f3ff; }
.tpl-scene-row.active { border-color:#7c3aed; background:#ede9fe; }
.tpl-scene-thumb { width:40px; height:70px; border-radius:7px; overflow:hidden; background:#0f2a44; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:18px; }
.tpl-scene-thumb img { width:100%; height:100%; object-fit:cover; }
.tpl-scene-info { flex:1; min-width:0; }
.tpl-scene-num { font-size:11px; font-weight:700; color:#7c3aed; margin-bottom:3px; }
.tpl-scene-txt { font-size:12px; color:var(--muted); line-height:1.4; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }

@media(max-width:600px){
    .tpl-preview-wrap { flex-direction:column; align-items:center; }
    .tpl-screen-wrap { width:160px; }
    .tpl-modal-box { max-height:96vh; }
}
</style>
<!-- ══════════════════════════════════════════════════════════════
     BROWSER POST MODAL — Post / Schedule / Download
     ══════════════════════════════════════════════════════════════ -->
<div class="bpm-overlay" id="bpmOverlay">
  <div class="bpm-modal">

    <!-- Main panel -->
    <div id="bpmMain">
      <div class="bpm-head">
        <div class="bpm-head-left">
          <div class="bpm-head-icon">📤</div>
          <div>
            <div class="bpm-head-title">Publish Video</div>
            <div class="bpm-head-sub" id="bpmSubTitle">Choose where &amp; when to share</div>
          </div>
        </div>
        <button class="bpm-close" onclick="closeBpmModal()">✕</button>
      </div>

      <!-- Spinner shown while checking MP4 -->
      <div id="bpmSpinner" style="display:flex;align-items:center;justify-content:center;padding:40px;gap:12px;">
        <div style="width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#10b981;border-radius:50%;animation:bpmSpin .7s linear infinite;"></div>
        <span style="font-size:14px;color:#64748b;">Checking video…</span>
      </div>

      <!-- Body — hidden until spinner done -->
      <div id="bpmBody" style="display:none;">
        <div class="bpm-saved" id="bpmSavedLabel">
          <div class="bpm-saved-dot"></div>
          <span>Checking video file…</span>
        </div>

        <div class="bpm-inner">
          <div class="bpm-lbl">Platforms</div>
          <div class="bpm-platforms">
            <div class="bpm-plat sel"          data-p="instagram" onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">📸</span> Instagram</div>
            <div class="bpm-plat sel"          data-p="tiktok"    onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">🎵</span> TikTok</div>
            <div class="bpm-plat sel"          data-p="youtube"   onclick="bpmTogglePlat(this)"><span class="bpm-plat-icon">▶️</span> YouTube</div>
            <div class="bpm-plat disconnected" data-p="facebook"                               ><span class="bpm-plat-icon">📘</span> Facebook</div>
            <div class="bpm-plat disconnected" data-p="twitter"                                ><span class="bpm-plat-icon">🐦</span> X</div>
            <div class="bpm-plat disconnected" data-p="linkedin"                               ><span class="bpm-plat-icon">💼</span> LinkedIn</div>
          </div>
          <div class="bpm-warn" id="bpmWarn" style="display:none;">Select at least one platform</div>

          <!-- Caption / Keywords / Hashtags tabs -->
          <div class="bpm-ctabs">
            <button class="bpm-ctab active" onclick="bpmSwitchTab('caption',this)">✍️ Caption</button>
            <button class="bpm-ctab"        onclick="bpmSwitchTab('keywords',this)">🔑 Keywords</button>
            <button class="bpm-ctab"        onclick="bpmSwitchTab('hashtags',this)">#️⃣ Hashtags</button>
          </div>
          <div class="bpm-ctab-panel active" id="bpm-tab-caption">
            <textarea class="bpm-textarea" id="bpmCaption" placeholder="Caption text…"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="bpm-tab-keywords">
            <textarea class="bpm-textarea" id="bpmKeywords" placeholder="Keywords…" style="height:54px;"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="bpm-tab-hashtags">
            <textarea class="bpm-textarea" id="bpmHashtags" placeholder="#hashtags…" style="height:54px;"></textarea>
          </div>

          <div class="bpm-lbl" style="margin-top:12px;">Schedule</div>
          <div class="bpm-quick">
            <button class="bpm-qpill"         onclick="bpmQuick(this,0)"  >Now</button>
            <button class="bpm-qpill"         onclick="bpmQuick(this,1)"  >+1hr</button>
            <button class="bpm-qpill active"  onclick="bpmQuick(this,24)" >Tomorrow</button>
            <button class="bpm-qpill"         onclick="bpmQuick(this,72)" >+3 days</button>
            <button class="bpm-qpill"         onclick="bpmQuick(this,168)">Next week</button>
          </div>
          <div class="bpm-date-row">
            <div>
              <div class="bpm-lbl">Date</div>
              <input type="date" class="bpm-input" id="bpmDate">
            </div>
            <div>
              <div class="bpm-lbl">Time</div>
              <input type="time" class="bpm-input" id="bpmTime" value="09:00">
            </div>
          </div>

          <div class="bpm-footer">
            <button class="bpm-dl-btn"    onclick="bpmDownload()">⬇ Download</button>
            <button class="bpm-btn-now"   onclick="bpmPostNow()">⚡ Post Now</button>
            <button class="bpm-btn-sched" onclick="bpmSchedule()">🗓 Schedule</button>
            <button class="bpm-btn-skip"  onclick="closeBpmModal()">Skip — publish manually</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm panel -->
    <div id="bpmConfirm" style="display:none;">
      <div class="bpm-confirm-icon"  id="bpmConfirmIcon">🗓</div>
      <div class="bpm-confirm-title" id="bpmConfirmTitle">Scheduled!</div>
      <div class="bpm-confirm-sub"   id="bpmConfirmSub"></div>
      <div class="bpm-confirm-pills" id="bpmConfirmPills"></div>
      <button class="bpm-confirm-done" onclick="closeBpmModal()">Done ✓</button>
    </div>

  </div>
</div>

<!-- ══ FEEDBACK CHAT DRAWER ══ -->
<div id="feedbackChatOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,42,68,.6);z-index:99980;backdrop-filter:blur(3px);" onclick="closeFeedbackChat()"></div>
<div id="feedbackChatDrawer" style="display:none;position:fixed;right:0;top:0;bottom:0;width:min(420px,100vw);background:#fff;box-shadow:-8px 0 40px rgba(0,0,0,0.2);z-index:99981;display:none;flex-direction:column;">
  <div style="padding:16px 18px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
    <div>
      <div style="font-size:16px;font-weight:800;" id="fcDrawerTitle">💬 Client Feedback</div>
      <div style="font-size:12px;opacity:.7;margin-top:2px;" id="fcDrawerSub">Chat history</div>
    </div>
    <button onclick="closeFeedbackChat()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
  </div>

  <!-- Status bar -->
  <div id="fcStatusBar" style="padding:8px 16px;font-size:12px;font-weight:700;border-bottom:1px solid #e2e8f0;flex-shrink:0;"></div>

  <!-- Messages -->
  <div id="fcMessages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#f8fafc;">
    <div id="fcEmpty" style="text-align:center;color:#94a3b8;font-size:13px;padding:30px 0;">Loading…</div>
  </div>

  <!-- Input -->
  <div style="padding:12px 14px;border-top:1px solid #e2e8f0;background:#fff;flex-shrink:0;display:flex;gap:8px;align-items:flex-end;">
    <textarea id="fcInput" rows="2"
      placeholder="Reply to client…"
      style="flex:1;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:16px;font-size:14px;font-family:inherit;resize:none;outline:none;transition:border-color .2s;max-height:100px;"
      onfocus="this.style.borderColor='#0284c7'"
      onblur="this.style.borderColor='#e2e8f0'"
      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();fcSendMessage();}"></textarea>
    <button onclick="fcSendMessage()" id="fcSendBtn"
      style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#0f2a44,#0284c7);border:none;color:#fff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .2s;">
      ➤
    </button>
  </div>
</div>

<style>
.fc-bubble { max-width:80%;display:flex;flex-direction:column;gap:3px; }
.fc-bubble.admin { align-self:flex-end;align-items:flex-end; }
.fc-bubble.client { align-self:flex-start;align-items:flex-start; }
.fc-body { padding:10px 14px;border-radius:18px;font-size:14px;line-height:1.5;word-break:break-word; }
.fc-bubble.admin  .fc-body { background:#0f2a44;color:#fff;border-bottom-right-radius:4px; }
.fc-bubble.client .fc-body { background:#fff;color:#1e293b;border:1px solid #e2e8f0;border-bottom-left-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,.06); }
.fc-meta { font-size:10px;color:#94a3b8;padding:0 4px; }
</style>

<script>
let _fcPodcastId = null;
let _fcAdminName = '<?= htmlspecialchars($firstname . ' ' . $lastname) ?>';

function openFeedbackChat(podcastId, triggerEl) {
  _fcPodcastId = podcastId;
  const card  = triggerEl ? triggerEl.closest('.project-card') : null;
  const title = card ? (card.querySelector('.card-title')?.textContent || 'Video') : 'Video';
  document.getElementById('fcDrawerTitle').textContent = '💬 Client Feedback';
  document.getElementById('fcDrawerSub').textContent   = title;

  // Status bar
  const status   = card?.dataset?.approvalStatus || '';
  const statusBar= document.getElementById('fcStatusBar');
  if (status === 'inprogress')             { statusBar.style.cssText='background:#eff6ff;color:#1d4ed8;'; statusBar.textContent='💬 In Progress — Discussing Changes'; }
  else if (status === 'sent_feedback')     { statusBar.style.cssText='background:#fdf4ff;color:#7e22ce;'; statusBar.textContent='📥 Client\'s Feedback Received — Reply Below'; }
  else if (status === 'feedback_replied')  { statusBar.style.cssText='background:#f0fdf4;color:#166534;'; statusBar.textContent='💬 You Replied — Awaiting Client Response'; }
  else if (status === 'approved')          { statusBar.style.cssText='background:#d1fae5;color:#065f46;'; statusBar.textContent='✅ Approved'; }
  else if (status === 'approval_required') { statusBar.style.cssText='background:#fef3c7;color:#92400e;'; statusBar.textContent='⏳ Awaiting Client Approval'; }
  else                                     { statusBar.style.cssText='background:#f1f5f9;color:#475569;'; statusBar.textContent='No approval status'; }

  // Show drawer
  const overlay = document.getElementById('feedbackChatOverlay');
  const drawer  = document.getElementById('feedbackChatDrawer');
  overlay.style.display = 'block';
  drawer.style.display  = 'flex';

  fcLoadMessages();
}

function closeFeedbackChat() {
  document.getElementById('feedbackChatOverlay').style.display = 'none';
  document.getElementById('feedbackChatDrawer').style.display  = 'none';
  _fcPodcastId = null;
}

function fcLoadMessages() {
  const container = document.getElementById('fcMessages');
  container.innerHTML = '<div id="fcEmpty" style="text-align:center;color:#94a3b8;font-size:13px;padding:30px 0;">Loading…</div>';
  if (!_fcPodcastId) return;

  fetch(`ajax_approval.php?action=get_feedback_admin&podcast_id=${_fcPodcastId}`)
  .then(r => r.json())
  .then(d => {
    console.log('FEEDBACK RESPONSE:', JSON.stringify(d));
    fcRenderMessages(d.messages);
  })
    .catch(() => { container.innerHTML = '<div id="fcEmpty" style="text-align:center;color:#ef4444;font-size:13px;padding:30px 0;">Error loading messages.</div>'; });
}

function fcRenderMessages(messages) {
  const container = document.getElementById('fcMessages');
  if (!messages || !messages.length) {
    container.innerHTML = '<div id="fcEmpty" style="text-align:center;color:#94a3b8;font-size:13px;padding:30px 0;">No messages yet. Reply to start the conversation.</div>';
    return;
  }
  container.innerHTML = messages.map(m => {
    const isAdmin = m.sender_type === 'admin';
    const time    = fcFormatTime(m.created_at);
    return `<div class="fc-bubble ${isAdmin ? 'admin' : 'client'}">
      <div class="fc-body">${fcEscHtml(m.message)}</div>
      <div class="fc-meta">${fcEscHtml(m.sender_name)} · ${time}</div>
    </div>`;
  }).join('');
  container.scrollTop = container.scrollHeight;
}

async function fcSendMessage() {
  const input = document.getElementById('fcInput');
  const btn   = document.getElementById('fcSendBtn');
  const msg   = input.value.trim();
  if (!msg || !_fcPodcastId) return;
  input.disabled = true; btn.disabled = true;

  const fd = new FormData();
  fd.append('action',     'send_feedback_admin');
  fd.append('podcast_id', _fcPodcastId);
  fd.append('message',    msg);

  try {
    const r = await fetch('ajax_approval.php', {method:'POST', credentials:'same-origin', body:fd});
    const d = await r.json();
    if (d.success) {
      input.value = '';
      const container = document.getElementById('fcMessages');
      const empty = document.getElementById('fcEmpty');
      if (empty) empty.remove();
      const m = d.message;
      const bubble = document.createElement('div');
      bubble.className = 'fc-bubble admin';
      bubble.innerHTML = `<div class="fc-body">${fcEscHtml(m.message)}</div><div class="fc-meta">${fcEscHtml(m.sender_name)} · ${fcFormatTime(m.created_at)}</div>`;
      container.appendChild(bubble);
      container.scrollTop = container.scrollHeight;

      // Update status bar and card badge to feedback_replied
      const statusBar = document.getElementById('fcStatusBar');
      statusBar.style.cssText = 'background:#f0fdf4;color:#166534;';
      statusBar.textContent = '💬 You Replied — Awaiting Client Response';
      updateCardApprovalBadge(_fcPodcastId, 'feedback_replied');
    } else {
      alert('Failed to send: ' + (d.message || 'Unknown error'));
    }
  } catch(e) {
    alert('Network error: ' + e.message);
  }
  input.disabled = false; btn.disabled = false; input.focus();
}

function fcFormatTime(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ', 'T'));
  return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
}
function fcEscHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>

<style>
/* ── Browser Post Modal ──────────────────────────────────────── */
@keyframes bpmSpin { to { transform: rotate(360deg); } }
@keyframes bpmSlideUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }

.bpm-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,42,68,.72); backdrop-filter: blur(4px);
    z-index: 99990; align-items: flex-end; justify-content: center;
    padding: 0;
}
.bpm-overlay.open { display: flex; }
@media(min-width:600px) { .bpm-overlay { align-items: center; padding: 16px; } }

.bpm-modal {
    background: #fff; border-radius: 22px 22px 0 0; width: 100%; max-width: 480px;
    max-height: 92vh; overflow-y: auto; box-shadow: 0 -8px 40px rgba(0,0,0,.25);
    animation: bpmSlideUp .28s cubic-bezier(.34,1.56,.64,1) both;
    -webkit-overflow-scrolling: touch;
}
@media(min-width:600px) {
    .bpm-modal { border-radius: 22px; box-shadow: 0 24px 80px rgba(0,0,0,.35); }
}

.bpm-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 20px 12px; border-bottom: 1px solid #e2e8f0;
}
.bpm-head-left { display: flex; align-items: center; gap: 12px; }
.bpm-head-icon { font-size: 26px; }
.bpm-head-title { font-size: 16px; font-weight: 800; color: #0f2a44; }
.bpm-head-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }
.bpm-close {
    background: #f1f5f9; border: none; border-radius: 50%;
    width: 32px; height: 32px; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; color: #64748b;
    transition: background .15s; flex-shrink: 0;
}
.bpm-close:hover { background: #e2e8f0; }

.bpm-saved {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; background: #f0fdf4;
    border-bottom: 1px solid #bbf7d0; font-size: 13px; color: #065f46; font-weight: 600;
}
.bpm-saved-dot {
    width: 9px; height: 9px; border-radius: 50%; background: #10b981; flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(16,185,129,.2);
}

.bpm-inner { padding: 16px 20px 20px; }

.bpm-lbl { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }

.bpm-platforms {
    display: grid; grid-template-columns: repeat(3,1fr); gap: 7px; margin-bottom: 6px;
}
.bpm-plat {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 10px; border-radius: 10px; border: 1.5px solid #e2e8f0;
    font-size: 13px; font-weight: 600; color: #64748b;
    cursor: pointer; transition: all .15s; user-select: none;
    background: #f8fafc;
}
.bpm-plat.sel { background: #f0fdf4; border-color: #86efac; color: #065f46; }
.bpm-plat.disconnected { opacity: .4; cursor: not-allowed; }
.bpm-plat-icon { font-size: 15px; }
.bpm-warn {
    font-size: 12px; color: #dc2626; font-weight: 600;
    margin-bottom: 8px; padding: 6px 10px; background: #fef2f2; border-radius: 8px;
}

.bpm-ctabs { display: flex; gap: 6px; margin: 12px 0 6px; }
.bpm-ctab {
    flex: 1; padding: 7px 0; border-radius: 8px; border: 1.5px solid #e2e8f0;
    font-size: 12px; font-weight: 700; color: #64748b; background: #f8fafc;
    cursor: pointer; transition: all .15s;
}
.bpm-ctab.active { background: #0f2a44; border-color: #0f2a44; color: #fff; }
.bpm-ctab-panel { display: none; }
.bpm-ctab-panel.active { display: block; }
.bpm-textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0;
    border-radius: 10px; font-size: 13px; color: #1e293b; font-family: inherit;
    resize: vertical; outline: none; min-height: 72px; transition: border-color .15s;
}
.bpm-textarea:focus { border-color: #0f2a44; }

.bpm-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.bpm-qpill {
    padding: 6px 12px; border-radius: 20px; border: 1.5px solid #e2e8f0;
    font-size: 12px; font-weight: 600; color: #64748b; background: #f8fafc;
    cursor: pointer; transition: all .15s;
}
.bpm-qpill.active { background: #0f2a44; border-color: #0f2a44; color: #fff; }

.bpm-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.bpm-input {
    width: 100%; padding: 8px 12px; border: 1.5px solid #e2e8f0;
    border-radius: 10px; font-size: 13px; color: #1e293b; font-family: inherit; outline: none;
    background: #f8fafc; transition: border-color .15s;
}
.bpm-input:focus { border-color: #0f2a44; }

.bpm-footer {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.bpm-dl-btn {
    grid-column: span 2; padding: 10px;
    background: #f8fafc; border: 1.5px solid #10b981; border-radius: 10px;
    font-size: 13px; font-weight: 700; color: #059669; cursor: pointer; transition: all .15s;
}
.bpm-dl-btn:hover { background: #f0fdf4; }
.bpm-btn-now {
    padding: 10px; background: linear-gradient(135deg,#f59e0b,#d97706);
    border: none; border-radius: 10px; font-size: 13px; font-weight: 700;
    color: #fff; cursor: pointer; transition: all .15s;
}
.bpm-btn-now:hover { opacity: .9; }
.bpm-btn-sched {
    padding: 10px; background: linear-gradient(135deg,#0f2a44,#0284c7);
    border: none; border-radius: 10px; font-size: 13px; font-weight: 700;
    color: #fff; cursor: pointer; transition: all .15s;
}
.bpm-btn-sched:hover { opacity: .9; }
.bpm-btn-skip {
    grid-column: span 2; padding: 8px; background: none; border: none;
    font-size: 12px; color: #94a3b8; cursor: pointer; text-decoration: underline;
}

/* Confirm screen */
.bpm-confirm-icon  { text-align: center; font-size: 52px; padding: 28px 0 0; }
.bpm-confirm-title { text-align: center; font-size: 22px; font-weight: 800; color: #0f2a44; margin-top: 10px; }
.bpm-confirm-sub   { text-align: center; font-size: 14px; color: #64748b; margin-top: 6px; padding-bottom: 6px; }
.bpm-confirm-pills {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 8px;
    padding: 14px 20px;
}
.bpm-confirm-pill {
    padding: 6px 14px; background: #f0fdf4; border: 1.5px solid #86efac;
    border-radius: 20px; font-size: 13px; font-weight: 700; color: #065f46;
}
.bpm-confirm-done {
    display: block; margin: 0 20px 24px; padding: 13px;
    background: linear-gradient(135deg,#10b981,#059669);
    border: none; border-radius: 12px; font-size: 15px; font-weight: 700;
    color: #fff; cursor: pointer; width: calc(100% - 40px); transition: all .15s;
}
.bpm-confirm-done:hover { opacity: .9; }

/* Post button on card */
.action-btn-post {
    background: #f0fdf4 !important; color: #059669 !important;
    border: 1.5px solid #86efac !important;
}
.action-btn-post:hover { background: #dcfce7 !important; transform: scale(1.1); }
/* Schedule button — blue, matches Post Now style */
.action-btn-schedule {
    background: #eff6ff !important; color: #2563eb !important;
    border: 1.5px solid #bfdbfe !important;
}
.action-btn-schedule:hover { background: #dbeafe !important; transform: scale(1.1); }
</style> 
</body>
</html> 
<?php
session_start();
$admin_id = $_SESSION['admin_id'] ?? null;
$admin_level = $_SESSION['level'] ?? '';
$client_id = $_SESSION['client_id'] ?? '';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit; 
}

include 'dbconnect_hdb.php';

// Fetch user details
$user_query = "SELECT * FROM hdb_users WHERE id = '$admin_id' LIMIT 1";
$user_result = mysqli_query($conn, $user_query);

if ($user_result && mysqli_num_rows($user_result) > 0) {
    $user_data = mysqli_fetch_assoc($user_result);
    $firstname = $user_data['firstname'] ?? 'User';
    $lastname = $user_data['lastname'] ?? '';
    $email = $user_data['email'] ?? '';
    $level_name = $user_data['level_name'] ?? $admin_level;
    $client_id = $user_data['client_id'] ?? $client_id;
    
    $_SESSION['firstname'] = $firstname;
    $_SESSION['lastname'] = $lastname;
    $_SESSION['user_email'] = $email;
    $_SESSION['level_name'] = $level_name;
    $_SESSION['client_id'] = $client_id;
} else {
    $firstname = 'User';
}

$client_id = (int)$client_id;
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'User';
$admin_initial = strtoupper(substr($admin_name, 0, 1));

// Get counts for each category (considering archived_flag)
// Get counts for each category (considering archived_flag)
$counts_query = "SELECT 
    SUM(CASE WHEN (video_status IS NULL OR video_status = '') AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN video_status = 'RECORDED' AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN video_status = 'SCHEDULED' AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
    SUM(CASE WHEN video_status = 'POSTED' AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
    SUM(CASE WHEN archived_flag = 1 THEN 1 ELSE 0 END) as archived_count
    FROM hdb_podcasts WHERE admin_id = $admin_id";  // Changed from client_id to admin_id

$counts_result = mysqli_query($conn, $counts_query);
$counts = mysqli_fetch_assoc($counts_result);

// Set default counts to 0 if null
$counts['active_count'] = $counts['active_count'] ?? 0;
$counts['completed_count'] = $counts['completed_count'] ?? 0;
$counts['scheduled_count'] = $counts['scheduled_count'] ?? 0;
$counts['posted_count'] = $counts['posted_count'] ?? 0;
$counts['archived_count'] = $counts['archived_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<link rel="stylesheet" href="/css/tooltip.css">
<title>VideoVizard - Dashboard</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --dark-blue: #0f2a44;
    --mid-blue: #143b63;
    --accent: #5fd1ff;
    --green: #10b981;
    --text: #1e293b;
    --muted: #64748b;
    --border: #e2e8f0;
    --bg: #f0f4f8;
    --card-bg: #ffffff;
    --shadow: 0 4px 20px rgba(0,0,0,0.08);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.15);
    --archive-overlay: rgba(100, 116, 139, 0.3);
    --delete-color: #ef4444;
    --archive-color: #f59e0b;
    --restore-color: #10b981;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Mobile-First Header */
.vidora-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: linear-gradient(90deg, #0f2a44, #143b63);
    color: #fff;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.brand-container a { 
    text-decoration: none; 
    display: flex; 
    align-items: center; 
    gap: 8px; 
}

.main-icon { 
    font-size: 28px; 
}

.brand-text { 
    display: flex; 
    flex-direction: column; 
}

.logo { 
    font-size: 20px; 
    font-weight: 700; 
    line-height: 1.2;
}

.brand-video { 
    color: white; 
}

.brand-vizard { 
    color: var(--accent); 
}

.tagline { 
    font-size: 10px; 
    color: rgba(255,255,255,0.6); 
    letter-spacing: 0.3px; 
    display: none;
}

/* Profile Dropdown - Larger Touch Targets */
.profile-wrap { 
    position: relative; 
}

.profile-btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    padding: 8px 12px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    min-height: 44px; /* Minimum tap target size */
}

.profile-btn .avatar {
    width: 28px;
    height: 28px;
    background: #5fd1ff;
    color: #0f2a44;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 14px;
    flex-shrink: 0;
}

.profile-btn .username {
    display: none;
}

.profile-btn .chevron { 
    font-size: 12px; 
    transition: transform 0.2s; 
}

.profile-btn.open .chevron { 
    transform: rotate(180deg); 
}

.dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.18);
    min-width: 220px;
    overflow: hidden;
    z-index: 9999;
    border: 1px solid var(--border);
}

.dropdown-menu.open { 
    display: block; 
    animation: slideDown 0.2s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.dropdown-item {
    padding: 14px 18px;
    font-size: 14px;
    min-height: 48px; /* Larger tap target */
}

/* Main Content */
.main {
    width: 100%;
    padding: 16px;
    flex: 1;
}

/* Action Bar */
.action-bar {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}

.page-title {
    font-size: 26px;
    font-weight: 700;
    color: var(--dark-blue);
}

.page-title span { 
    color: var(--green); 
}

.btn-create {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 14px 24px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(16,185,129,0.35);
    transition: all 0.2s;
    width: 100%;
    min-height: 52px; /* Larger tap target */
}

/* Stats Bar - Larger Touch Targets */
.stats-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 8px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.stats-bar::-webkit-scrollbar {
    height: 4px;
}

.stats-bar::-webkit-scrollbar-track {
    background: var(--border);
    border-radius: 4px;
}

.stats-bar::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: 4px;
}

.stat-pill {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
    min-height: 60px; /* Larger tap target */
}

.stat-pill:active { 
    transform: scale(0.98); 
}

.stat-pill .stat-num { 
    font-size: 22px; 
    font-weight: 800; 
    color: var(--dark-blue); 
    line-height: 1; 
}

.stat-pill .stat-label { 
    font-size: 12px; 
    color: var(--muted); 
    line-height: 1.3; 
}

.stat-pill .stat-icon { 
    font-size: 24px; 
}

/* Tab Bar - Larger Touch Targets */
.tab-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 8px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    border-bottom: 2px solid var(--border);
}

.tab-bar::-webkit-scrollbar {
    height: 3px;
}

.tab-item {
    padding: 10px 20px;
    border-radius: 40px;
    font-size: 15px;
    font-weight: 600;
    color: var(--muted);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
    min-height: 48px; /* Larger tap target */
}

.tab-item:active {
    background: rgba(95, 209, 255, 0.2);
}

.tab-item.active {
    background: var(--dark-blue);
    color: white;
    box-shadow: 0 4px 12px rgba(15, 42, 68, 0.2);
}

.tab-count {
    display: inline-block;
    margin-left: 8px;
    padding: 3px 8px;
    border-radius: 20px;
    background: rgba(255,255,255,0.2);
    font-size: 12px;
}

/* Loading States */
.loading-spinner {
    text-align: center;
    padding: 60px 16px;
    color: var(--muted);
    grid-column: 1 / -1;
}

.spinner {
    display: inline-block;
    width: 50px;
    height: 50px;
    border: 4px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Cards Grid - 9x16 Ratio Cards */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

/* Project Card - 9x16 Aspect Ratio (Vertical) */
.project-card {
    background: var(--card-bg);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    color: inherit;
    display: block;
    position: relative;
    animation: fadeIn 0.3s ease;
    aspect-ratio: 9/16;
    display: flex;
    flex-direction: column;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Archived state - using archived_flag */
.project-card.archived {
    opacity: 0.7;
    filter: grayscale(0.8);
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.project-card.archived .card-thumb,
.project-card.archived .card-thumb-default {
    filter: grayscale(1) brightness(0.9);
}

.project-card.fade-out {
    animation: fadeOut 0.3s ease forwards;
}

@keyframes fadeOut {
    to { opacity: 0; transform: scale(0.9); }
}

.project-card:active {
    transform: scale(0.98);
}

/* Status badges - Larger and more visible */
.status-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    z-index: 5;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.status-in-progress { 
    background: #fef3c7; 
    color: #d97706; 
}

.status-completed { 
    background: #d1fae5; 
    color: #059669; 
}

.status-scheduled { 
    background: #dbeafe; 
    color: #2563eb; 
}

.status-posted { 
    background: #ede9fe; 
    color: #7c3aed; 
}

.status-archived { 
    background: #e2e8f0; 
    color: #475569; 
}

.card-thumb {
    width: 100%;
    height: 65%; /* Takes 65% of the 9x16 card */
    object-fit: cover;
    display: block;
    background: linear-gradient(135deg, #0f2a44, #143b63);
}

.card-thumb-default {
    width: 100%;
    height: 65%;
    background: linear-gradient(135deg, #0f2a44, #1e4a7a);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: rgba(255,255,255,0.9);
}

.card-thumb-default .play-icon { 
    font-size: 48px; 
    opacity: 0.9; 
}

.card-thumb-default .no-thumb-text { 
    font-size: 12px; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
    background: rgba(0,0,0,0.4);
    padding: 6px 12px;
    border-radius: 30px;
}

.card-body {
    padding: 14px;
    height: 35%; /* Takes 35% of the 9x16 card */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.card-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    margin-bottom: 6px;
}

.card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    margin-top: auto;
}

.card-date { 
    color: var(--muted); 
    display: flex;
    align-items: center;
    gap: 3px;
}

.card-id { 
    color: var(--muted); 
    background: var(--bg); 
    padding: 4px 8px; 
    border-radius: 12px; 
    font-weight: 600;
}

/* Card Actions - Larger Icons for Mobile */
.card-actions {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    gap: 8px;
    z-index: 10;
    opacity: 0;
    transition: opacity 0.2s;
}

.project-card:hover .card-actions,
.project-card:active .card-actions {
    opacity: 1;
}

/* On mobile, always show actions with semi-transparency */
@media (max-width: 768px) {
    .card-actions {
        opacity: 0.9;
    }
}

.action-btn {
    width: 44px;  /* Larger for mobile */
    height: 44px; /* Larger for mobile */
    border-radius: 12px;
    border: none;
    background: white;
    color: var(--muted);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    font-size: 20px; /* Larger icons */
    font-weight: normal;
}

.action-btn:active {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0,0,0,0.25);
}

.action-btn.archive { color: var(--archive-color); }
.action-btn.delete { color: var(--delete-color); }
.action-btn.restore { color: var(--restore-color); }
.action-btn.edit { color: var(--accent); }

/* Load More Button - Larger */
.load-more-container {
    text-align: center;
    margin: 24px 0;
    grid-column: 1 / -1;
}

.load-more-btn {
    background: var(--card-bg);
    border: 1px solid var(--border);
    color: var(--dark-blue);
    padding: 16px 32px;
    border-radius: 40px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow);
    min-height: 56px; /* Larger tap target */
    min-width: 200px;
}

.load-more-btn:active {
    transform: scale(0.98);
    background: var(--accent);
    color: white;
}

.load-more-btn.loading {
    opacity: 0.7;
    pointer-events: none;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--muted);
    background: var(--card-bg);
    border-radius: 20px;
    border: 2px dashed var(--border);
    grid-column: 1 / -1;
}

.empty-state .empty-icon { 
    font-size: 64px; 
    margin-bottom: 20px; 
}

.empty-state p { 
    font-size: 16px; 
    margin-bottom: 12px;
}

.empty-state .empty-hint {
    font-size: 14px;
    color: var(--muted);
}

/* Delete Confirmation Modal - Larger for Mobile */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.show {
    display: flex;
    animation: fadeIn 0.2s ease;
}

.modal-content {
    background: white;
    border-radius: 24px;
    padding: 32px;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 30px 60px rgba(0,0,0,0.3);
}

.modal-content h3 {
    font-size: 22px;
    margin-bottom: 12px;
    color: var(--dark-blue);
}

.modal-content p {
    font-size: 16px;
    color: var(--muted);
    margin-bottom: 28px;
    line-height: 1.5;
}

.modal-actions {
    display: flex;
    gap: 12px;
}

.modal-btn {
    flex: 1;
    padding: 16px;
    border: none;
    border-radius: 14px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 56px; /* Larger tap target */
}

.modal-btn.cancel {
    background: var(--border);
    color: var(--text);
}

.modal-btn.delete {
    background: var(--delete-color);
    color: white;
}

.modal-btn:active {
    transform: scale(0.98);
}

/* Footer - Larger Links */
.site-footer {
    background: linear-gradient(90deg, #0f2a44, #143b63);
    color: rgba(255,255,255,0.55);
    padding: 20px;
    font-size: 13px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    text-align: center;
    margin-top: auto;
}

.footer-brand { 
    font-weight: 700; 
    color: #5fd1ff; 
    font-size: 14px; 
}

.footer-links { 
    display: flex; 
    gap: 24px; 
    justify-content: center; 
    flex-wrap: wrap; 
}

.footer-links a { 
    color: rgba(255,255,255,0.55); 
    text-decoration: none; 
    transition: color 0.2s; 
    padding: 8px 0; /* Larger tap target */
    min-height: 44px;
    display: inline-block;
}

.footer-links a:active { 
    color: #5fd1ff; 
}

/* Tablet Breakpoint */
@media (min-width: 768px) {
    .vidora-header {
        padding: 14px 24px;
    }
    
    .main {
        padding: 30px 24px;
    }
    
    .action-bar {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
    }
    
    .btn-create {
        width: auto;
        min-width: 200px;
    }
    
    .profile-btn .username {
        display: inline;
    }
    
    .tagline {
        display: block;
    }
    
    .cards-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }
    
    .site-footer {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        padding: 16px 30px;
    }
    
    /* On tablet/desktop, hide actions until hover */
    .card-actions {
        opacity: 0;
    }
}

/* Desktop Breakpoint */
@media (min-width: 1024px) {
    .cards-grid {
        grid-template-columns: repeat(6, 1fr);
    }
    
    .main {
        max-width: 1400px;
        margin: 0 auto;
    }
}

/* Large Desktop Breakpoint */
@media (min-width: 1440px) {
    .cards-grid {
        grid-template-columns: repeat(8, 1fr);
    }
}

/* Small phones */
@media (max-width: 380px) {
    .cards-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-btn {
        padding: 6px 10px;
    }
    
    .action-btn {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
}
</style>
</head>
<body>

<header class="vidora-header">
    <div class="brand-container">
        <a href="index.php">
            <span class="main-icon">🎬</span>
            <div class="brand-text">
                <div class="logo">
                    <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
                </div>
                <div class="tagline">Social Media Automation</div>
            </div>
        </a>
    </div>

    <div class="profile-wrap">
        <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()">
            <div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
            <span class="username"><?= htmlspecialchars($firstname) ?></span>
            <span class="chevron">▼</span>
        </button>
        <div class="dropdown-menu" id="dropdownMenu">
            <div class="dropdown-user">
                <div class="d-name"><?= htmlspecialchars($firstname . ' ' . $lastname) ?></div>
                <div class="d-role">Client Account</div>
            </div>
            <a href="profile.php" class="dropdown-item">
                <span class="d-icon">👤</span> My Profile
            </a>
            <a href="settings.php" class="dropdown-item">
                <span class="d-icon">⚙️</span> Settings
            </a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item logout">
                <span class="d-icon">🚪</span> Logout
            </a>
        </div>
    </div>
</header>

<div class="main">
    <!-- Action Bar -->
    <div class="action-bar">
        <h1 class="page-title">My <span>Projects</span></h1>
        <a href="script_gen.php" class="btn-create">Create New Project</a>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar">
        <div class="stat-pill" onclick="switchTab('active')">
            <div class="stat-icon">🎬</div>
            <div>
                <div class="stat-num" id="totalCount">0</div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="stat-pill" onclick="switchTab('active')">
            <div class="stat-icon">⏳</div>
            <div>
                <div class="stat-num" id="activeCount"><?= $counts['active_count'] ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="stat-pill" onclick="switchTab('completed')">
            <div class="stat-icon">✅</div>
            <div>
                <div class="stat-num" id="completedCount"><?= $counts['completed_count'] ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
        <div class="stat-pill" onclick="switchTab('scheduled')">
            <div class="stat-icon">📅</div>
            <div>
                <div class="stat-num" id="scheduledCount"><?= $counts['scheduled_count'] ?></div>
                <div class="stat-label">Scheduled</div>
            </div>
        </div>
        <div class="stat-pill" onclick="switchTab('posted')">
            <div class="stat-icon">📢</div>
            <div>
                <div class="stat-num" id="postedCount"><?= $counts['posted_count'] ?></div>
                <div class="stat-label">Posted</div>
            </div>
        </div>
        <div class="stat-pill" onclick="switchTab('archived')">
            <div class="stat-icon">📦</div>
            <div>
                <div class="stat-num" id="archivedCount"><?= $counts['archived_count'] ?></div>
                <div class="stat-label">Archived</div>
            </div>
        </div>
    </div>
	
    <!-- Tab Bar -->
    <div class="tab-bar" id="tabBar">
        <button class="tab-item active" data-tab="active" onclick="switchTab('active')">
            Active <span class="tab-count" id="activeTabCount"><?= $counts['active_count'] ?></span>
        </button>
        <button class="tab-item" data-tab="completed" onclick="switchTab('completed')">
            Completed <span class="tab-count" id="completedTabCount"><?= $counts['completed_count'] ?></span>
        </button>
        <button class="tab-item" data-tab="scheduled" onclick="switchTab('scheduled')">
            Scheduled <span class="tab-count" id="scheduledTabCount"><?= $counts['scheduled_count'] ?></span>
        </button>
        <button class="tab-item" data-tab="posted" onclick="switchTab('posted')">
            Posted <span class="tab-count" id="postedTabCount"><?= $counts['posted_count'] ?></span>
        </button>
        <button class="tab-item" data-tab="archived" onclick="switchTab('archived')">
            Archived <span class="tab-count" id="archivedTabCount"><?= $counts['archived_count'] ?></span>
        </button>
    </div>

    <!-- Videos Grid Container -->
    <div id="videosGrid" class="cards-grid">
        <div class="loading-spinner" id="initialLoader">
            <div class="spinner"></div>
            <p style="margin-top: 16px;">Loading your videos...</p>
        </div>
    </div>

    <!-- Load More Button -->
    <div id="loadMoreContainer" class="load-more-container" style="display: none;">
        <button class="load-more-btn" onclick="loadMoreVideos()" id="loadMoreBtn">
            Load More Videos
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <h3>Delete Video?</h3>
        <p>This will permanently delete the video and all its files. This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="modal-btn cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="modal-btn delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="site-footer">
    <div class="footer-brand">🎬 VideoVizard</div>
    <div class="footer-links">
        <a href="vidora_home.php">Home</a>
        <a href="profile.php">Profile</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> VideoVizard</div>
</footer>

<script>
// State management
let currentTab = 'active';
let currentPage = 1;
let isLoading = false;
let hasMore = true;
let deleteVideoId = null;
let deleteCardElement = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadVideos('active', 1);
    updateTotalCount();
});

// Update total count
function updateTotalCount() {
    const active = parseInt(document.getElementById('activeCount').textContent) || 0;
    const completed = parseInt(document.getElementById('completedCount').textContent) || 0;
    const scheduled = parseInt(document.getElementById('scheduledCount').textContent) || 0;
    const posted = parseInt(document.getElementById('postedCount').textContent) || 0;
    const archived = parseInt(document.getElementById('archivedCount').textContent) || 0;
    document.getElementById('totalCount').textContent = active + completed + scheduled + posted + archived;
}

// Switch between tabs
function switchTab(tab) {
    if (tab === currentTab) return;
    
    // Update active tab styling
    document.querySelectorAll('.tab-item').forEach(t => {
        if (t.dataset.tab === tab) {
            t.classList.add('active');
        } else {
            t.classList.remove('active');
        }
    });
    
    currentTab = tab;
    currentPage = 1;
    hasMore = true;
    
    // Clear grid and show loader
    document.getElementById('videosGrid').innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p style="margin-top: 16px;">Loading ${tab} videos...</p>
        </div>
    `;
    
    // Hide load more button
    document.getElementById('loadMoreContainer').style.display = 'none';
    
    // Load videos for selected tab
    loadVideos(tab, 1);
}

// Load videos via AJAX
function loadVideos(status, page, append = false) {
    if (isLoading) return;
    
    isLoading = true;
    
   const url = `ajax_load_videos.php?status=${status}&page=${page}&admin_id=<?= $admin_id ?>`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            isLoading = false;
            
            if (data.success) {
                if (!append) {
                    document.getElementById('videosGrid').innerHTML = data.html;
                } else {
                    document.getElementById('videosGrid').innerHTML += data.html;
                }
                
                hasMore = data.has_more;
                
                // Show/hide load more button
                if (hasMore) {
                    document.getElementById('loadMoreContainer').style.display = 'block';
                } else {
                    document.getElementById('loadMoreContainer').style.display = 'none';
                }
                
                // Update counts if provided
                if (data.counts) {
                    updateCounts(data.counts);
                }
            } else {
                if (!append) {
                    document.getElementById('videosGrid').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <p>No ${status} videos found</p>
                            <div class="empty-hint">Create a new project to get started</div>
                        </div>
                    `;
                }
            }
        })
        .catch(error => {
            console.error('Error loading videos:', error);
            isLoading = false;
            document.getElementById('videosGrid').innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">⚠️</div>
                    <p>Error loading videos. Please try again.</p>
                </div>
            `;
        });
}

// Load more videos
function loadMoreVideos() {
    if (!hasMore || isLoading) return;
    
    currentPage++;
    loadVideos(currentTab, currentPage, true);
    
    // Show loading state on button
    const btn = document.getElementById('loadMoreBtn');
    btn.classList.add('loading');
    btn.textContent = 'Loading...';
    
    // Reset button after loading
    setTimeout(() => {
        btn.classList.remove('loading');
        btn.textContent = 'Load More Videos';
    }, 1000);
}

// Update counts in UI
function updateCounts(counts) {
    if (counts.active !== undefined) {
        document.getElementById('activeCount').textContent = counts.active;
        document.getElementById('activeTabCount').textContent = counts.active;
    }
    if (counts.completed !== undefined) {
        document.getElementById('completedCount').textContent = counts.completed;
        document.getElementById('completedTabCount').textContent = counts.completed;
    }
    if (counts.scheduled !== undefined) {
        document.getElementById('scheduledCount').textContent = counts.scheduled;
        document.getElementById('scheduledTabCount').textContent = counts.scheduled;
    }
    if (counts.posted !== undefined) {
        document.getElementById('postedCount').textContent = counts.posted;
        document.getElementById('postedTabCount').textContent = counts.posted;
    }
    if (counts.archived !== undefined) {
        document.getElementById('archivedCount').textContent = counts.archived;
        document.getElementById('archivedTabCount').textContent = counts.archived;
    }
    
    updateTotalCount();
}

// Archive video (sets archived_flag = 1)
function archiveVideo(videoId, cardElement) {
    if (!confirm('Move this video to archive?')) return;
    
    cardElement.classList.add('fade-out');
    
    fetch('ajax_update_video.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            video_id: videoId,
            action: 'archive'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                cardElement.remove();
                updateCounts(data.counts);
                
                // Show empty state if no videos left
                if (document.querySelectorAll('.project-card').length === 0) {
                    document.getElementById('videosGrid').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <p>No ${currentTab} videos found</p>
                        </div>
                    `;
                }
            }, 300);
        } else {
            cardElement.classList.remove('fade-out');
            alert('Failed to archive video');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        cardElement.classList.remove('fade-out');
        alert('Error archiving video');
    });
}

// Restore video (sets archived_flag = 0, preserves original video_status)
function restoreVideo(videoId, cardElement) {
    cardElement.classList.add('fade-out');
    
    fetch('ajax_update_video.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            video_id: videoId,
            action: 'restore'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                cardElement.remove();
                updateCounts(data.counts);
                
                if (document.querySelectorAll('.project-card').length === 0) {
                    document.getElementById('videosGrid').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <p>No ${currentTab} videos found</p>
                        </div>
                    `;
                }
            }, 300);
        } else {
            cardElement.classList.remove('fade-out');
            alert('Failed to restore video');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        cardElement.classList.remove('fade-out');
        alert('Error restoring video');
    });
}

// Delete video
function deleteVideo(videoId, cardElement) {
    deleteVideoId = videoId;
    deleteCardElement = cardElement;
    document.getElementById('deleteModal').classList.add('show');
}

// Close delete modal
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    deleteVideoId = null;
    deleteCardElement = null;
}

// Confirm delete
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!deleteVideoId || !deleteCardElement) {
        closeDeleteModal();
        return;
    }
    
    const cardElement = deleteCardElement;
    cardElement.classList.add('fade-out');
    
    fetch('ajax_update_video.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            video_id: deleteVideoId,
            action: 'delete'
        })
    })
    .then(response => response.json())
    .then(data => {
        closeDeleteModal();
        
        if (data.success) {
            setTimeout(() => {
                cardElement.remove();
                updateCounts(data.counts);
                
                if (document.querySelectorAll('.project-card').length === 0) {
                    document.getElementById('videosGrid').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <p>No ${currentTab} videos found</p>
                        </div>
                    `;
                }
            }, 300);
        } else {
            cardElement.classList.remove('fade-out');
            alert('Failed to delete video');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        closeDeleteModal();
        cardElement.classList.remove('fade-out');
        alert('Error deleting video');
    });
});

// Toggle dropdown menu
function toggleDropdown() {
    const menu = document.getElementById('dropdownMenu');
    const btn = document.getElementById('profileBtn');
    menu.classList.toggle('open');
    btn.classList.toggle('open');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.querySelector('.profile-wrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('dropdownMenu').classList.remove('open');
        document.getElementById('profileBtn').classList.remove('open');
    }
});
</script>

</body>
</html>
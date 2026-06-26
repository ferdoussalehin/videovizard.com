<?php

session_start();
if (!isset($_SESSION['client_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
include 'dbconnect_hdb.php';

$client_id = (int)$_SESSION['client_id'];
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'User';
$admin_initial = strtoupper(substr($admin_name, 0, 1));

// Fetch incomplete projects
$incomplete = [];
$r = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE client_id=$client_id AND (video_status = '' OR video_status IS NULL) ORDER BY updated_at DESC");
if ($r) while ($row = mysqli_fetch_assoc($r)) $incomplete[] = $row;

// Fetch completed projects
$completed = [];
$r2 = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE client_id=$client_id AND video_status = 'recorded' ORDER BY updated_at DESC");
if ($r2) while ($row = mysqli_fetch_assoc($r2)) $completed[] = $row;

// Fetch posted projects
$posted = [];
$r3 = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE client_id=$client_id AND video_status = 'POSTED' ORDER BY updated_at DESC");
if ($r3) while ($row = mysqli_fetch_assoc($r3)) $posted[] = $row;

function getThumb($row) {
    $thumb = $row['thumbnail'] ?? '';
    if (!empty($thumb) && file_exists($thumb)) return $thumb;
    $auto = 'podcast_images/' . ($row['id'] ?? '') . '.jpg';
    if (file_exists($auto)) return $auto;
    return '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vidora — My Projects</title>
<style>
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
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ---- HEADER (matching other pages) ---- */
.vidora-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    background: linear-gradient(90deg, #0f2a44, #143b63);
    color: #fff;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
    font-family: "Segoe UI", sans-serif;
}
.brand {
    font-size: 22px;
    font-weight: 600;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.brand span { color: #5fd1ff; }
.brand small { font-size: 12px; color: #cde9ff; font-weight: 400; }
.vidora-nav { display: flex; gap: 18px; }
.vidora-nav a {
    text-decoration: none;
    color: #fff;
    font-size: 14px;
    padding: 7px 14px;
    border-radius: 6px;
    transition: all 0.25s ease;
}
.vidora-nav a:hover { background: rgba(255,255,255,0.15); }
.vidora-nav a.active { background: #5fd1ff; color: #0f2a44; font-weight: 600; }

/* ---- PROFILE DROPDOWN (new) ---- */
.profile-wrap { position: relative; }
.profile-btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    padding: 7px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}
.profile-btn:hover { background: rgba(255,255,255,0.25); }
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
    font-size: 13px;
    flex-shrink: 0;
}
.profile-btn .chevron { font-size: 10px; transition: transform 0.2s; }
.profile-btn.open .chevron { transform: rotate(180deg); }
.dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.18);
    min-width: 190px;
    overflow: hidden;
    z-index: 9999;
    border: 1px solid var(--border);
}
.dropdown-menu.open { display: block; }
.dropdown-user {
    padding: 14px 16px;
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
}
.dropdown-user .d-name { font-size: 13px; font-weight: 700; color: var(--dark-blue); }
.dropdown-user .d-role { font-size: 11px; color: var(--muted); margin-top: 2px; }
.dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    font-size: 13px;
    color: var(--text);
    text-decoration: none;
    transition: background 0.15s;
    width: 100%;
}
.dropdown-item:hover { background: #f0f9ff; color: var(--dark-blue); }
.dropdown-item .d-icon { font-size: 15px; width: 20px; text-align: center; }
.dropdown-divider { height: 1px; background: var(--border); }
.dropdown-item.logout { color: #dc2626; }
.dropdown-item.logout:hover { background: #fef2f2; }

/* ---- MAIN CONTENT ---- */
.main {
    max-width: 1300px;
    margin: 0 auto;
    padding: 30px 20px;
    flex: 1;
}

/* ---- TOP ACTION BAR ---- */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 15px;
}
.page-title {
    font-size: 26px;
    font-weight: 700;
    color: var(--dark-blue);
}
.page-title span { color: var(--green); }
.btn-create {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 12px 28px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(16,185,129,0.35);
    transition: all 0.2s;
}
.btn-create:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16,185,129,0.45);
}

/* ---- SECTION ---- */
.section { margin-bottom: 40px; }
.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
}
.section-title { font-size: 18px; font-weight: 700; color: var(--dark-blue); }
.section-badge {
    background: var(--dark-blue);
    color: white;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.section-badge.green { background: var(--green); }
.section-badge.orange { background: #f59e0b; }

/* ---- CARDS GRID ---- */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 12px;
}

/* ---- PROJECT CARD ---- */
.project-card {
    background: var(--card-bg);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    color: inherit;
    display: block;
    position: relative;
}
.project-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
    border-color: #5fd1ff;
}
.card-thumb {
    width: 100%;
    height: 160px;
    object-fit: cover;
    display: block;
    background: linear-gradient(135deg, #0f2a44, #143b63);
}
.card-thumb-default {
    width: 100%;
    height: 160px;
    background: linear-gradient(135deg, #0f2a44, #1e4a7a);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: rgba(255,255,255,0.6);
}
.card-thumb-default .play-icon { font-size: 42px; opacity: 0.7; }
.card-thumb-default .no-thumb-text { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
.card-status {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.status-pending { background: #fef3c7; color: #d97706; }
.status-done { background: #d1fae5; color: #059669; }
.card-body { padding: 14px 16px 16px; }
.card-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.4;
    margin-bottom: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}
.card-date { font-size: 11px; color: var(--muted); }
.card-id { font-size: 10px; color: var(--muted); background: var(--bg); padding: 2px 8px; border-radius: 10px; }
.card-arrow {
    position: absolute;
    bottom: 14px;
    right: 16px;
    color: var(--accent);
    font-size: 18px;
    opacity: 0;
    transition: opacity 0.2s, transform 0.2s;
}
.project-card:hover .card-arrow { opacity: 1; transform: translateX(4px); }

/* ---- EMPTY STATE ---- */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    color: var(--muted);
    background: var(--card-bg);
    border-radius: 14px;
    border: 2px dashed var(--border);
}
.empty-state .empty-icon { font-size: 48px; margin-bottom: 12px; }
.empty-state p { font-size: 14px; }

/* ---- STATS BAR ---- */
.stats-bar {
    display: flex;
    gap: 16px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}
.stat-pill {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.stat-pill:hover { border-color: #5fd1ff; transform: translateY(-2px); transition: all 0.2s; }
.stat-pill .stat-num { font-size: 24px; font-weight: 800; color: var(--dark-blue); line-height: 1; }
.stat-pill .stat-label { font-size: 12px; color: var(--muted); line-height: 1.3; }
.stat-pill .stat-icon { font-size: 24px; }

/* ---- FOOTER (new) ---- */
.site-footer {
    background: linear-gradient(90deg, #0f2a44, #143b63);
    color: rgba(255,255,255,0.55);
    padding: 14px 30px;
    font-size: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.footer-brand { font-weight: 700; color: #5fd1ff; font-size: 13px; }
.footer-links { display: flex; gap: 16px; }
.footer-links a { color: rgba(255,255,255,0.55); text-decoration: none; transition: color 0.2s; }
.footer-links a:hover { color: #5fd1ff; }
</style>
</head>
<body>

<header class="vidora-header">
    <div class="brand">
        🎬 <span>Vidora</span>
        <small>Social Media Automation</small>
    </div>
    <nav class="vidora-nav">
        <a href="vidora_home.php" class="active">🏠 Home</a>
        <a href="vidora.php">1. Contents</a>
        <a href="image_gen.php">2. Images</a>
        <a href="audio_gen.php">3. Audios</a>
        <a href="videomaker.php">4. Video</a>
        <a href="podcast_translator.php">5. Translate</a>
        <a href="publisher/dashboard.php">6. Schedule</a>
    </nav>
    <!-- Profile Button (new) -->
    <div class="profile-wrap">
        <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()">
            <div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
            <?= htmlspecialchars($admin_name) ?>
            <span class="chevron">▼</span>
        </button>
        <div class="dropdown-menu" id="dropdownMenu">
            <div class="dropdown-user">
                <div class="d-name"><?= htmlspecialchars($admin_name) ?></div>
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
        <a href="vidora.php" class="btn-create">➕ Create New Project</a>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar">
    <div class="stat-pill" onclick="scrollToSection('in-progress')" style="cursor:pointer;">
        <div class="stat-icon">🎬</div>
        <div>
            <div class="stat-num"><?= count($incomplete) + count($completed) + count($posted) ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
    </div>
    <div class="stat-pill" onclick="scrollToSection('in-progress')" style="cursor:pointer;">
        <div class="stat-icon">⏳</div>
        <div>
            <div class="stat-num"><?= count($incomplete) ?></div>
            <div class="stat-label">In Progress</div>
        </div>
    </div>
    <div class="stat-pill" onclick="scrollToSection('completed')" style="cursor:pointer;">
        <div class="stat-icon">✅</div>
        <div>
            <div class="stat-num"><?= count($completed) ?></div>
            <div class="stat-label">Completed</div>
        </div>
    </div>
    <div class="stat-pill" onclick="scrollToSection('posted')" style="cursor:pointer;">
        <div class="stat-icon">📢</div>
        <div>
            <div class="stat-num"><?= count($posted) ?></div>
            <div class="stat-label">Posted</div>
        </div>
    </div>
</div>

    <!-- In Progress Section -->
    <div class="section" id="in-progress">
        <div class="section-header" >
            <div class="section-title" >⏳ In Progress</div>
            <span class="section-badge orange"><?= count($incomplete) ?></span>
        </div>

        <?php if (empty($incomplete)): ?>
            <div class="empty-state">
                <div class="empty-icon">🎯</div>
                <p>No projects in progress. <a href="vidora.php" style="color:var(--green);">Create your first project!</a></p>
            </div>
        <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($incomplete as $p):
                    $thumb = getThumb($p);
                    $date = date('M j, Y', strtotime($p['updated_at'] ?? $p['created_at'] ?? 'now'));
                ?>
                <a href="vidora.php?podcast_id=<?= $p['id'] ?>" class="project-card">
                    <span class="card-status status-pending">⏳ In Progress</span>
                    <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>" class="card-thumb" alt="Thumbnail" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="card-thumb-default" style="display:none;">
                            <div class="play-icon">🎬</div>
                            <div class="no-thumb-text">No Thumbnail</div>
                        </div>
                    <?php else: ?>
                        <div class="card-thumb-default">
                            <div class="play-icon">🎬</div>
                            <div class="no-thumb-text">No Thumbnail</div>
                        </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="card-title"><?= htmlspecialchars($p['title'] ?? 'Untitled Project') ?></div>
                        <div class="card-meta">
                            <span class="card-date">📅 <?= $date ?></span>
                           <span class="card-id">🌐 <?= htmlspecialchars($p['lang_code'] ?? 'en') ?></span>
                        </div>
                    </div>
                    <div class="card-arrow">→</div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Completed Section -->
    <div class="section" id="completed">
        <div class="section-header" >
            <div class="section-title" >✅ Completed</div>
            <span class="section-badge green"><?= count($completed) ?></span>
        </div>

        <?php if (empty($completed)): ?>
            <div class="empty-state">
                <div class="empty-icon">🏆</div>
                <p>No completed projects yet. Finish a project to see it here.</p>
            </div>
        <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($completed as $p):
                    $thumb = getThumb($p);
                    $date = date('M j, Y', strtotime($p['updated_at'] ?? $p['created_at'] ?? 'now'));
                ?>
                <a href="videomaker.php?podcast_id=<?= $p['id'] ?>" class="project-card">
                    <span class="card-status status-done">✅ Completed</span>
                    <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>" class="card-thumb" alt="Thumbnail" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="card-thumb-default" style="display:none;">
                            <div class="play-icon">🎬</div>
                            <div class="no-thumb-text">No Thumbnail</div>
                        </div>
                    <?php else: ?>
                        <div class="card-thumb-default">
                            <div class="play-icon">🎬</div>
                            <div class="no-thumb-text">No Thumbnail</div>
                        </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="card-title"><?= htmlspecialchars($p['title'] ?? 'Untitled Project') ?></div>
                        <div class="card-meta">
                            <span class="card-date">📅 <?= $date ?></span>
                           <span class="card-id">🌐 <?= htmlspecialchars($p['lang_code'] ?? 'en') ?></span>
                        </div>
                    </div>
                    <div class="card-arrow">→</div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
	
	<!-- Posted Section -->
<div class="section" id="posted">
    <div class="section-header">
        <div class="section-title">📢 Posted</div>
        <span class="section-badge" style="background:#7c3aed;"><?= count($posted) ?></span>
    </div>
    <?php if (empty($posted)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p>No posted projects yet.</p>
        </div>
    <?php else: ?>
        <div class="cards-grid">
            <?php foreach ($posted as $p):
                $thumb = getThumb($p);
                $date = date('M j, Y', strtotime($p['updated_at'] ?? $p['created_at'] ?? 'now'));
            ?>
            <a href="videomaker.php?podcast_id=<?= $p['id'] ?>" class="project-card">
                <span class="card-status" style="background:#ede9fe; color:#7c3aed;">📢 Posted</span>
                <?php if ($thumb): ?>
                    <img src="<?= htmlspecialchars($thumb) ?>" class="card-thumb" alt="Thumbnail" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="card-thumb-default" style="display:none;">
                        <div class="play-icon">🎬</div>
                        <div class="no-thumb-text">No Thumbnail</div>
                    </div>
                <?php else: ?>
                    <div class="card-thumb-default">
                        <div class="play-icon">🎬</div>
                        <div class="no-thumb-text">No Thumbnail</div>
                    </div>
                <?php endif; ?>
                <div class="card-body">
                    <div class="card-title"><?= htmlspecialchars($p['title'] ?? 'Untitled Project') ?></div>
                    <div class="card-meta">
                        <span class="card-date">📅 <?= $date ?></span>
                        <span class="card-id">🌐 <?= htmlspecialchars($p['lang_code'] ?? 'en') ?></span>
                    </div>
                </div>
                <div class="card-arrow">→</div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<!-- Footer (new) -->
<footer class="site-footer">
    <div class="footer-brand">🎬 Vidora</div>
    <div class="footer-links">
        <a href="vidora_home.php">Home</a>
        <a href="profile.php">Profile</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> Vidora — Social Media Automation</div>
</footer>

<script>
function toggleDropdown() {
    const menu = document.getElementById('dropdownMenu');
    const btn = document.getElementById('profileBtn');
    menu.classList.toggle('open');
    btn.classList.toggle('open');
}

function scrollToSection(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
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

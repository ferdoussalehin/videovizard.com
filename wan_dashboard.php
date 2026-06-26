<?php
/**
 * dashboard_wan.php
 * Beautiful frontend dashboard for WAN video generation.
 * Shows live progress, previews generated videos, and allows downloading.
 */

include 'config.php';

// Fetch all podcasts with videogen_flag = 1 or 2 for display
$podcasts_res = mysqli_query($conn,
    "SELECT id, title, videogen_flag FROM hdb_podcasts WHERE videogen_flag IN (1,2) ORDER BY id DESC LIMIT 20"
);
$podcasts = [];
while ($row = mysqli_fetch_assoc($podcasts_res)) {
    $podcasts[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WAN Video Studio — Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:        #030712;
            --surface:   rgba(255,255,255,0.04);
            --border:    rgba(255,255,255,0.08);
            --accent:    #7c3aed;
            --accent2:   #06b6d4;
            --success:   #10b981;
            --error:     #ef4444;
            --warn:      #f59e0b;
            --text:      #f1f5f9;
            --muted:     rgba(255,255,255,0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            background-image:
                radial-gradient(ellipse at 15% 10%, rgba(124,58,237,0.18) 0%, transparent 55%),
                radial-gradient(ellipse at 85% 85%, rgba(6,182,212,0.12) 0%, transparent 55%);
            color: var(--text);
            min-height: 100vh;
            padding: 32px 20px;
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        .badge {
            display: inline-block;
            padding: 4px 14px;
            border: 1px solid rgba(124,58,237,0.5);
            border-radius: 100px;
            font-size: 0.75rem;
            color: #a78bfa;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .header h1 {
            font-size: clamp(1.8rem, 4vw, 2.8rem);
            font-weight: 800;
            background: linear-gradient(135deg, #a78bfa 0%, #06b6d4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .header p {
            color: var(--muted);
            margin-top: 8px;
            font-size: 0.95rem;
        }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            backdrop-filter: blur(12px);
            max-width: 860px;
            margin: 0 auto 24px;
        }

        /* ── Podcast Selector ── */
        .select-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .select-row label {
            font-size: 0.85rem;
            color: var(--muted);
            white-space: nowrap;
        }
        select#podcast-select {
            flex: 1;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            padding: 10px 14px;
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
            cursor: pointer;
            min-width: 200px;
        }
        select#podcast-select option { background: #111827; }

        /* ── Generate Button ── */
        .btn-start {
            padding: 12px 28px;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff;
            transition: all 0.3s;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }
        .btn-start::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--accent2), var(--accent));
            opacity: 0;
            transition: opacity 0.3s;
        }
        .btn-start:hover::before { opacity: 1; }
        .btn-start:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(124,58,237,0.45); }
        .btn-start:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-start span { position: relative; z-index: 1; }

        /* ── Status Banner ── */
        #status-banner {
            display: none;
            margin-top: 22px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(124,58,237,0.12);
            border: 1px solid rgba(124,58,237,0.3);
            color: #a78bfa;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #status-banner.success { background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }
        #status-banner.error   { background: rgba(239,68,68,0.1);  border-color: rgba(239,68,68,0.3);  color: #fca5a5; }
        .spinner {
            width: 16px; height: 16px;
            border: 2px solid rgba(167,139,250,0.3);
            border-top-color: #a78bfa;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Overall Progress ── */
        #overall-progress { display: none; margin-top: 24px; }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .progress-label { font-size: 0.85rem; color: var(--muted); }
        .progress-count { font-size: 0.85rem; font-weight: 600; color: #a78bfa; }
        .progress-track {
            width: 100%;
            height: 8px;
            background: rgba(255,255,255,0.06);
            border-radius: 100px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(to right, var(--accent), var(--accent2));
            border-radius: 100px;
            transition: width 0.6s ease;
            width: 0%;
        }

        /* ── Stories Grid ── */
        #stories-section { display: none; margin-top: 32px; }
        #stories-section h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 16px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-size: 0.8rem;
        }
        .stories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        /* ── Story Card ── */
        .story-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.3s, border-color 0.3s;
        }
        .story-card:hover { transform: translateY(-3px); border-color: rgba(124,58,237,0.3); }

        .story-video-wrap {
            aspect-ratio: 9/16;
            background: #0f172a;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .story-video-wrap video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Pending overlay */
        .pending-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: rgba(3,7,18,0.8);
        }
        .pending-overlay .big-spinner {
            width: 36px; height: 36px;
            border: 3px solid rgba(124,58,237,0.2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        .pending-overlay span {
            font-size: 0.75rem;
            color: var(--muted);
        }

        /* Error overlay */
        .error-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(3,7,18,0.85);
        }
        .error-overlay .error-icon { font-size: 2rem; }
        .error-overlay span { font-size: 0.75rem; color: #fca5a5; }

        .story-info {
            padding: 12px;
        }
        .story-id {
            font-size: 0.75rem;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .story-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 100px;
        }
        .story-status.done    { background: rgba(16,185,129,0.15); color: #6ee7b7; }
        .story-status.pending { background: rgba(124,58,237,0.15); color: #a78bfa; }
        .story-status.error   { background: rgba(239,68,68,0.15);  color: #fca5a5; }

        .btn-download {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
            padding: 8px;
            border: 1px solid rgba(124,58,237,0.3);
            border-radius: 10px;
            color: #a78bfa;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s;
        }
        .btn-download:hover { background: rgba(124,58,237,0.15); border-color: var(--accent); }

        /* ── Done Banner ── */
        #done-banner {
            display: none;
            text-align: center;
            padding: 28px;
            background: rgba(16,185,129,0.08);
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 16px;
            margin-top: 24px;
        }
        #done-banner .done-icon { font-size: 2.5rem; margin-bottom: 10px; }
        #done-banner h3 { font-size: 1.2rem; color: #6ee7b7; margin-bottom: 6px; }
        #done-banner p  { font-size: 0.85rem; color: var(--muted); }

        @media (max-width: 480px) {
            .select-row { flex-direction: column; align-items: stretch; }
            .btn-start { width: 100%; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="badge">⚡ WAN 2.1 AI Engine</div>
    <h1>Video Generation Studio</h1>
    <p>Select a podcast and generate AI videos for all its stories</p>
</div>

<div class="card">
    <div class="select-row">
        <label for="podcast-select">Select Podcast:</label>
        <select id="podcast-select">
            <option value="">-- Choose a Podcast --</option>
            <?php foreach ($podcasts as $p): ?>
                <option value="<?= $p['id'] ?>" data-flag="<?= $p['videogen_flag'] ?>">
                    #<?= $p['id'] ?> — <?= htmlspecialchars($p['title'] ?? 'Untitled') ?>
                    <?= $p['videogen_flag'] == 2 ? '✅ Done' : '⏳ Pending' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn-start" id="btn-start" disabled>
            <span id="btn-text">🎬 Start Generating</span>
        </button>
    </div>

    <!-- Status Banner -->
    <div id="status-banner">
        <div class="spinner" id="status-spinner"></div>
        <span id="status-text">Initializing...</span>
    </div>

    <!-- Overall Progress Bar -->
    <div id="overall-progress">
        <div class="progress-header">
            <span class="progress-label">Overall Progress</span>
            <span class="progress-count" id="progress-count">0 / 0</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="progress-fill"></div>
        </div>
    </div>

    <!-- Done Banner -->
    <div id="done-banner">
        <div class="done-icon">🎉</div>
        <h3>All Videos Generated!</h3>
        <p>All stories have been processed and videos are saved to the server.</p>
    </div>
</div>

<!-- Stories Grid -->
<div class="card" id="stories-section">
    <h2>📽 Stories</h2>
    <div class="stories-grid" id="stories-grid"></div>
</div>

<script>
let podcastId  = null;
let pollTimer  = null;
let isRunning  = false;

const btnStart   = document.getElementById('btn-start');
const btnText    = document.getElementById('btn-text');
const selectEl   = document.getElementById('podcast-select');
const banner     = document.getElementById('status-banner');
const bannerSpinner = document.getElementById('status-spinner');
const bannerText = document.getElementById('status-text');
const progWrap   = document.getElementById('overall-progress');
const progFill   = document.getElementById('progress-fill');
const progCount  = document.getElementById('progress-count');
const storySec   = document.getElementById('stories-section');
const storyGrid  = document.getElementById('stories-grid');
const doneBanner = document.getElementById('done-banner');

// Enable button when podcast selected
selectEl.addEventListener('change', () => {
    podcastId = selectEl.value || null;
    btnStart.disabled = !podcastId || isRunning;
});

// ── Start Generation ──────────────────────────────────────────────────────────
btnStart.addEventListener('click', async () => {
    if (!podcastId) return;

    isRunning = true;
    btnStart.disabled = true;
    btnText.textContent = '⏳ Processing...';
    doneBanner.style.display = 'none';

    showBanner('Starting video generation...', 'default');
    progWrap.style.display = 'block';

    try {
        const res  = await fetch(`irfan.php?action=start`);
        const data = await res.json();

        if (!data.success) {
            showBanner('Error: ' + data.message, 'error');
            resetBtn();
            return;
        }

        showBanner(`Generating ${data.total_stories} videos for Podcast #${podcastId}...`, 'default');
        startPolling();

    } catch(e) {
        showBanner('Failed to connect to server: ' + e.message, 'error');
        resetBtn();
    }
});

// ── Polling ──────────────────────────────────────────────────────────────────
function startPolling() {
    clearInterval(pollTimer);
    pollTimer = setInterval(pollStatus, 8000);
    pollStatus(); // Immediate first poll
}

async function pollStatus() {
    if (!podcastId) return;

    try {
        const res  = await fetch(`irfan.php?action=status&podcast_id=${podcastId}`);
        const data = await res.json();

        if (!data.success) return;

        const total   = data.total_stories  || 0;
        const done    = data.done_stories   || 0;
        const pct     = total > 0 ? Math.round((done / total) * 100) : 0;

        // Progress bar
        progFill.style.width  = pct + '%';
        progCount.textContent = `${done} / ${total}`;

        // Status message
        if (data.status === 'all_done') {
            clearInterval(pollTimer);
            showBanner('All videos generated successfully! 🎉', 'success');
            doneBanner.style.display = 'block';
            progFill.style.width = '100%';
            progCount.textContent = `${total} / ${total}`;
            resetBtn();
            isRunning = false;
        } else {
            showBanner(data.message || 'Processing...', 'default');
        }

        // Render story cards
        if (data.stories && data.stories.length > 0) {
            renderStories(data.stories);
        }

    } catch(e) {
        console.warn('Poll error:', e);
    }
}

// ── Render Story Cards ────────────────────────────────────────────────────────
function renderStories(stories) {
    storySec.style.display = 'block';

    stories.forEach(s => {
        let card = document.getElementById('story-' + s.story_id);

        if (!card) {
            card = document.createElement('div');
            card.className = 'story-card';
            card.id = 'story-' + s.story_id;
            storyGrid.appendChild(card);
        }

        if (s.state === 'done' && s.video_file) {
            card.innerHTML = `
                <div class="story-video-wrap">
                    <video src="${s.video_file}" controls playsinline muted></video>
                </div>
                <div class="story-info">
                    <div class="story-id">Story #${s.story_id}</div>
                    <span class="story-status done">✅ Done</span>
                    <a class="btn-download" href="${s.video_file}" download>
                        ⬇ Download Video
                    </a>
                </div>`;
        } else if (s.state === 'error') {
            card.innerHTML = `
                <div class="story-video-wrap">
                    <div class="error-overlay">
                        <div class="error-icon">⚠️</div>
                        <span>Generation Failed</span>
                    </div>
                </div>
                <div class="story-info">
                    <div class="story-id">Story #${s.story_id}</div>
                    <span class="story-status error">❌ Error</span>
                </div>`;
        } else {
            card.innerHTML = `
                <div class="story-video-wrap">
                    <div class="pending-overlay">
                        <div class="big-spinner"></div>
                        <span>Generating...</span>
                    </div>
                </div>
                <div class="story-info">
                    <div class="story-id">Story #${s.story_id}</div>
                    <span class="story-status pending">⏳ Pending</span> 
                </div>`;
        }
    });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function showBanner(msg, type) {
    banner.style.display = 'flex';
    bannerText.textContent = msg;
    banner.className = type === 'success' ? 'success' : type === 'error' ? 'error' : '';
    bannerSpinner.style.display = (type === 'default') ? 'block' : 'none';
}

function resetBtn() {
    btnStart.disabled = !podcastId;
    btnText.textContent = '🎬 Start Generating';
}
</script>

</body>
</html>
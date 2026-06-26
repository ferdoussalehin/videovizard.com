<?php
/**
 * video_dashboard.php — Video Generation UI
 * Works with wan_text2_video_api.php
 */
include 'config.php';

$podcasts = [];
if (isset($conn)) {
    $res = mysqli_query($conn, "SELECT id, title FROM hdb_podcasts ORDER BY id DESC LIMIT 50");
    if ($res) while ($row = mysqli_fetch_assoc($res)) $podcasts[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>WAN Video Generator</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#0a0a0f;--surface:#12121a;--border:#1e1e2e;
    --accent:#00e5a0;--accent2:#ff6b35;--muted:#3a3a52;
    --text:#e8e8f0;--text-dim:#6b6b88;
    --done:#00e5a0;--pending:#ff6b35;--processing:#ffd166;
    --radius:12px;
    --font-head:'Syne',sans-serif;
    --font-mono:'DM Mono',monospace;
  }
  html,body{background:var(--bg);color:var(--text);font-family:var(--font-mono);min-height:100vh;overflow-x:hidden}
  body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");pointer-events:none;z-index:0}
  .wrap{position:relative;z-index:1;max-width:960px;margin:0 auto;padding:48px 24px 80px}

  /* HEADER */
  .badge{display:inline-flex;align-items:center;gap:6px;background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);color:var(--accent);font-size:11px;letter-spacing:.12em;text-transform:uppercase;padding:4px 10px;border-radius:99px;margin-bottom:14px}
  .badge span{width:6px;height:6px;border-radius:50%;background:var(--accent);animation:pulse 2s infinite}
  h1{font-family:var(--font-head);font-size:clamp(28px,5vw,48px);font-weight:800;line-height:1.05;letter-spacing:-.02em;margin-bottom:8px}
  h1 em{color:var(--accent);font-style:normal}
  .subtitle{color:var(--text-dim);font-size:13px;margin-bottom:40px;line-height:1.6}

  /* PANEL */
  .panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:28px;margin-bottom:24px}
  .panel-title{font-family:var(--font-head);font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--text-dim);margin-bottom:20px}
  .controls-row{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}
  .field{display:flex;flex-direction:column;gap:6px;flex:1;min-width:200px}
  .field label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim)}
  .field select,.field input{background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:13px;padding:10px 14px;border-radius:8px;outline:none;transition:border-color .2s;-webkit-appearance:none}
  .field select:focus,.field input:focus{border-color:var(--accent)}

  /* BUTTONS */
  .btn{position:relative;overflow:hidden;font-family:var(--font-head);font-size:14px;font-weight:800;letter-spacing:.04em;border:none;border-radius:8px;padding:12px 24px;cursor:pointer;white-space:nowrap;transition:transform .15s,box-shadow .15s,opacity .2s}
  .btn:disabled{opacity:.4;cursor:not-allowed;transform:none!important}
  .btn-primary{background:var(--accent);color:#000;box-shadow:0 0 24px rgba(0,229,160,.25)}
  .btn-primary:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 0 36px rgba(0,229,160,.45)}
  .btn-danger{background:transparent;color:var(--accent2);border:1px solid rgba(255,107,53,.3);font-size:12px;padding:10px 18px}
  .btn-danger:hover:not(:disabled){background:rgba(255,107,53,.08);border-color:var(--accent2)}

  /* STATUS BAR */
  #status-bar{display:none;align-items:center;gap:12px;background:rgba(0,229,160,.05);border:1px solid rgba(0,229,160,.15);border-radius:8px;padding:14px 18px;margin-top:20px;font-size:13px}
  #status-bar.error{background:rgba(255,107,53,.05);border-color:rgba(255,107,53,.2);color:var(--accent2)}
  #status-bar.done{background:rgba(0,229,160,.08);border-color:rgba(0,229,160,.35)}
  .spinner{width:16px;height:16px;border-radius:50%;border:2px solid rgba(0,229,160,.2);border-top-color:var(--accent);animation:spin .7s linear infinite;flex-shrink:0}
  #status-msg{flex:1}
  #status-timer{color:var(--text-dim);font-size:11px;font-variant-numeric:tabular-nums}

  /* PROGRESS */
  #progress-wrap{display:none;margin-bottom:28px}
  .progress-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
  .progress-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-dim)}
  .progress-count{font-family:var(--font-head);font-size:24px;font-weight:800;color:var(--accent)}
  .progress-track{width:100%;height:6px;background:var(--border);border-radius:99px;overflow:hidden}
  .progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),#00b8ff);transition:width .6s cubic-bezier(.4,0,.2,1);width:0%}

  /* DONE BANNER */
  #done-banner{display:none;background:linear-gradient(135deg,rgba(0,229,160,.1),rgba(0,184,255,.05));border:1px solid rgba(0,229,160,.3);border-radius:var(--radius);padding:28px;margin-bottom:28px;text-align:center;animation:fadeIn .5s ease}
  .done-icon{font-size:40px;margin-bottom:8px}
  .done-title{font-family:var(--font-head);font-size:26px;font-weight:800;color:var(--accent);margin-bottom:6px}
  .done-sub{font-size:13px;color:var(--text-dim)}

  /* STORIES */
  #stories-section{display:none}
  .section-title{font-family:var(--font-head);font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--text-dim);margin-bottom:16px}
  .stories-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:16px}

  .story-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s,transform .2s;animation:cardIn .4s ease both}
  .story-card:hover{transform:translateY(-2px)}
  .story-card.state-done{border-color:rgba(0,229,160,.3)}

  .card-video-wrap{position:relative;aspect-ratio:9/16;background:var(--bg);overflow:hidden}
  .card-video-wrap video{width:100%;height:100%;object-fit:cover;display:block}
  .card-placeholder{width:100%;height:100%;background:linear-gradient(90deg,var(--border) 25%,var(--muted) 50%,var(--border) 75%);background-size:200% 100%;animation:shimmer 1.6s infinite}

  .card-badge{position:absolute;top:8px;right:8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 8px;border-radius:99px}
  .card-badge.done{background:rgba(0,229,160,.15);color:var(--done);border:1px solid rgba(0,229,160,.3)}
  .card-badge.pending{background:rgba(255,107,53,.12);color:var(--pending);border:1px solid rgba(255,107,53,.25)}

  .card-body{padding:10px 12px}
  .card-id{font-size:10px;color:var(--text-dim);margin-bottom:4px}
  .card-filename{font-size:11px;color:var(--text);word-break:break-all}
  .card-download{display:block;width:100%;background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);color:var(--accent);font-family:var(--font-mono);font-size:11px;text-align:center;padding:7px;text-decoration:none;transition:background .2s;margin-top:8px;border-radius:6px}
  .card-download:hover{background:rgba(0,229,160,.18)}

  /* ANIMATIONS */
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
  @keyframes spin{to{transform:rotate(360deg)}}
  @keyframes shimmer{to{background-position:-200% 0}}
  @keyframes cardIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  @keyframes fadeIn{from{opacity:0}to{opacity:1}}
</style>
</head>
<body>
<div class="wrap">

  <div class="badge"><span></span> WAN Video Studio</div>
  <h1>Generate<br><em>Podcast Videos</em></h1>
  <p class="subtitle">Podcast chunno → Start dabao → Videos background mein generate hongi.<br>Status har 10 sec mein auto-update hoga. Page band karne ki zaroorat nahi.</p>

  <!-- CONTROL PANEL -->
  <div class="panel">
    <div class="panel-title">— Configuration</div>
    <div class="controls-row">

      <?php if (!empty($podcasts)): ?>
      <div class="field">
        <label>Podcast Select karo</label>
        <select id="podcast-select">
          <?php foreach ($podcasts as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title'] ?? 'Podcast') ?> (#<?= (int)$p['id'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <div class="field">
        <label>Podcast ID</label>
        <input type="number" id="podcast-id-manual" placeholder="Podcast ID daalo" min="1"/>
      </div>
      <?php endif; ?>

      <button class="btn btn-primary" id="btn-generate" onclick="startGeneration()">
        ▶ Start Generation
      </button>

      <button class="btn btn-danger" id="btn-reset" onclick="resetAndRegenerate()" style="display:none">
        ↺ Reset &amp; Regenerate
      </button>
    </div>

    <!-- Status bar -->
    <div id="status-bar">
      <div class="spinner" id="status-spinner"></div>
      <span id="status-msg">Initializing…</span>
      <span id="status-timer"></span>
    </div>
  </div>

  <!-- PROGRESS BAR -->
  <div id="progress-wrap">
    <div class="progress-header">
      <span class="progress-label">Videos Generated</span>
      <span class="progress-count" id="progress-text">0 / 0</span>
    </div>
    <div class="progress-track">
      <div class="progress-fill" id="progress-fill"></div>
    </div>
  </div>

  <!-- ALL DONE -->
  <div id="done-banner">
    <div class="done-icon">🎬</div>
    <div class="done-title">Sab Videos Tayyar!</div>
    <div class="done-sub" id="done-sub-text"></div>
  </div>

  <!-- STORIES GRID -->
  <div id="stories-section">
    <div class="section-title">— Story Videos</div>
    <div class="stories-grid" id="stories-grid"></div>
  </div>

</div>

<script>
const API_URL       = 'wan_text2_video_api.php';
const POLL_INTERVAL = 10000; // 10 seconds

let pollTimer    = null;
let podcastId    = null;
let startTime    = null;
let elapsedTimer = null;
let knownDone    = new Set(); // story_ids already rendered as done
let totalStories = 0;

// ── GET SELECTED PODCAST ID ───────────────────────────────────────────────────
function getSelectedPodcastId() {
  const sel = document.getElementById('podcast-select');
  const man = document.getElementById('podcast-id-manual');
  return sel ? parseInt(sel.value) || 0 : parseInt(man?.value) || 0;
}

// ── START ─────────────────────────────────────────────────────────────────────
async function startGeneration() {
  const btn = document.getElementById('btn-generate');
  btn.disabled = true;
  btn.textContent = '⏳ Starting…';
  document.getElementById('btn-reset').style.display = 'none';
  document.getElementById('done-banner').style.display = 'none';
  knownDone.clear();

  const pid = getSelectedPodcastId();
  if (!pid) {
    showStatus('❌ Pehle podcast select karo.', 'error');
    btn.disabled = false;
    btn.textContent = '▶ Start Generation';
    return;
  }

  showStatus('Modal API ko request bhej raha hoon…', 'loading');

  try {
    // ?action=start resets flag=1, clears old image_file, then begins generating
    const res  = await fetch(`${API_URL}?action=start&podcast_id=${pid}`);
    const data = await res.json();

    if (!data.success) {
      showStatus('❌ ' + (data.message || 'Error'), 'error');
      btn.disabled = false;
      btn.textContent = '▶ Start Generation';
      return;
    }

    if (data.status === 'all_done') {
      // No stories found
      showStatus('⚠️ ' + data.message, 'done');
      btn.textContent = '✓ Done';
      return;
    }

    // Started successfully
    podcastId    = data.podcast_id;
    totalStories = data.total_stories;
    startTime    = Date.now();

    startElapsedTimer();
    showProgress(0, totalStories);
    renderPlaceholders(totalStories);

    btn.textContent = '⏳ Generating…';
    showStatus(`Podcast #${podcastId} — ${totalStories} stories generate ho rahi hain…`, 'loading');

    // Begin polling every 10s
    clearInterval(pollTimer);
    pollStatus(); // immediate first poll after a short delay
    pollTimer = setInterval(pollStatus, POLL_INTERVAL);

  } catch (err) {
    showStatus('❌ Network error: ' + err.message, 'error');
    btn.disabled = false;
    btn.textContent = '▶ Start Generation';
  }
}

// ── RESET & REGENERATE ────────────────────────────────────────────────────────
async function resetAndRegenerate() {
  if (!confirm('Purani videos ki DB entries clear ho jayegi aur phir se generate hogi. Sure?')) return;
  document.getElementById('btn-reset').style.display = 'none';
  startGeneration();
}

// ── POLL STATUS ───────────────────────────────────────────────────────────────
async function pollStatus() {
  if (!podcastId) return;
  try {
    const res  = await fetch(`${API_URL}?action=status&podcast_id=${podcastId}`);
    const data = await res.json();

    updateProgress(data.done_stories || 0, data.total_stories || totalStories);
    renderStoryCards(data.stories || []);

    if (data.status === 'all_done') {
      clearInterval(pollTimer);
      clearInterval(elapsedTimer);
      showStatus('✅ ' + data.message, 'done');
      showDoneBanner(data.done_stories, data.total_stories);
      document.getElementById('btn-generate').textContent = '✓ Complete';
      document.getElementById('btn-generate').disabled = false;
      document.getElementById('btn-reset').style.display = 'inline-flex';
    } else if (data.status === 'processing') {
      showStatus('⏳ ' + data.message, 'loading');
    } else {
      showStatus('ℹ️ ' + data.message, 'loading');
    }

  } catch (err) {
    showStatus('⚠️ Poll error (retry in 10s): ' + err.message, 'error');
  }
}

// ── UI: STATUS BAR ────────────────────────────────────────────────────────────
function showStatus(msg, type) {
  const bar     = document.getElementById('status-bar');
  const msgEl   = document.getElementById('status-msg');
  const spinner = document.getElementById('status-spinner');
  bar.style.display = 'flex';
  bar.className = type === 'error' ? 'error' : (type === 'done' ? 'done' : '');
  msgEl.textContent = msg;
  spinner.style.display = (type === 'loading') ? 'block' : 'none';
}

// ── UI: PROGRESS ──────────────────────────────────────────────────────────────
function showProgress(done, total) {
  document.getElementById('progress-wrap').style.display = 'block';
  updateProgress(done, total);
}
function updateProgress(done, total) {
  if (!total) return;
  document.getElementById('progress-text').textContent = `${done} / ${total}`;
  document.getElementById('progress-fill').style.width  = `${Math.round((done/total)*100)}%`;
}

// ── UI: DONE BANNER ───────────────────────────────────────────────────────────
function showDoneBanner(done, total) {
  const b = document.getElementById('done-banner');
  b.style.display = 'block';
  document.getElementById('done-sub-text').textContent = `${done} out of ${total} videos successfully generated.`;
  b.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── UI: PLACEHOLDER CARDS ─────────────────────────────────────────────────────
function renderPlaceholders(count) {
  const grid = document.getElementById('stories-grid');
  const sec  = document.getElementById('stories-section');
  grid.innerHTML = '';
  knownDone.clear();
  sec.style.display = 'block';

  for (let i = 0; i < count; i++) {
    const card = document.createElement('div');
    card.className = 'story-card state-pending';
    card.id = 'story-card-placeholder-' + (i + 1);
    card.style.animationDelay = (i * 0.04) + 's';
    card.innerHTML = `
      <div class="card-video-wrap">
        <div class="card-placeholder"></div>
        <span class="card-badge pending">Pending</span>
      </div>
      <div class="card-body">
        <div class="card-id">Story #${i + 1}</div>
        <div class="card-filename" style="color:var(--text-dim)">Waiting for Modal API…</div>
      </div>`;
    grid.appendChild(card);
  }
}

// ── UI: UPDATE STORY CARDS FROM POLL DATA ─────────────────────────────────────
function renderStoryCards(stories) {
  if (!stories.length) return;
  document.getElementById('stories-section').style.display = 'block';
  const grid = document.getElementById('stories-grid');

  stories.forEach((s, idx) => {
    // Skip if we already rendered this as done
    if (knownDone.has(s.story_id)) return;

    // Find existing card by story id or by placeholder index
    let card = document.getElementById('story-card-' + s.story_id);
    if (!card) {
      // Reuse placeholder slot
      const ph = document.getElementById('story-card-placeholder-' + (idx + 1));
      if (ph) {
        ph.id = 'story-card-' + s.story_id;
        card = ph;
      } else {
        card = document.createElement('div');
        card.id = 'story-card-' + s.story_id;
        grid.appendChild(card);
      }
    }

    if (s.state === 'done' && s.video_file) {
      renderDoneCard(card, s);
      knownDone.add(s.story_id);
    } else {
      renderPendingCard(card, s, idx);
    }
  });
}

function renderDoneCard(card, s) {
  card.className = 'story-card state-done';
  // Ensure leading slash
  const src = s.video_file.startsWith('/') ? s.video_file : '/' + s.video_file;
  card.innerHTML = `
    <div class="card-video-wrap">
      <video autoplay muted loop playsinline preload="metadata">
        <source src="${src}" type="video/mp4"/>
      </video>
      <span class="card-badge done">✓ Done</span>
    </div>
    <div class="card-body">
      <div class="card-id">Story #${s.story_id}</div>
      <div class="card-filename">${src.split('/').pop()}</div>
      <a class="card-download" href="${src}" download>⬇ Download</a>
    </div>`;
}

function renderPendingCard(card, s, idx) {
  // Only re-render pending if not already showing pending (avoid flicker)
  if (card.querySelector('.card-placeholder')) return;
  card.className = 'story-card state-pending';
  card.innerHTML = `
    <div class="card-video-wrap">
      <div class="card-placeholder"></div>
      <span class="card-badge pending">Pending</span>
    </div>
    <div class="card-body">
      <div class="card-id">Story #${s.story_id}</div>
      <div class="card-filename" style="color:var(--text-dim)">Generating…</div>
    </div>`;
}

// ── ELAPSED TIMER ─────────────────────────────────────────────────────────────
function startElapsedTimer() {
  clearInterval(elapsedTimer);
  const el = document.getElementById('status-timer');
  elapsedTimer = setInterval(() => {
    if (!startTime) return;
    const sec = Math.floor((Date.now() - startTime) / 1000);
    const mm  = String(Math.floor(sec / 60)).padStart(2, '0');
    const ss  = String(sec % 60).padStart(2, '0');
    el.textContent = mm + ':' + ss;
  }, 1000);
}
</script>
</body>
</html>

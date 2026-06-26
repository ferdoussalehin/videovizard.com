<?php
/**
 * video_generator.php — Video Generator Frontend
 */
include 'config.php';

global $conn;
if (!defined('DB_HOST')) {
    $db_host = $db_host ?? 'localhost'; $db_user = $db_user ?? '';
    $db_pass = $db_pass ?? '';          $db_name = $db_name ?? '';
} else {
    $db_host = DB_HOST; $db_user = DB_USER;
    $db_pass = DB_PASS; $db_name = DB_NAME;
}
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if ($conn) mysqli_set_charset($conn, 'utf8mb4');

$podcasts = [];
if ($conn) {
    $res = mysqli_query($conn,
        "SELECT p.id, p.title, p.videogen_flag,
                COUNT(s.id) as total_stories,
                SUM(CASE WHEN s.image_file IS NOT NULL AND s.image_file != ''
                         AND s.image_file != 'skipped_no_prompt'
                         AND s.image_file LIKE 'wan_%' THEN 1 ELSE 0 END) as done_stories
         FROM hdb_podcasts p
         LEFT JOIN hdb_podcast_stories s ON s.podcast_id = p.id
         GROUP BY p.id ORDER BY p.id DESC LIMIT 30"
    );
    if ($res) while ($r = mysqli_fetch_assoc($res)) $podcasts[] = $r;
}

$selected_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : ($podcasts[0]['id'] ?? 0);
$stories = [];
if ($conn && $selected_id > 0) {
    $res = mysqli_query($conn,
        "SELECT id, scene_number, video_prompt, image_file, image_folder
         FROM hdb_podcast_stories
         WHERE podcast_id = $selected_id ORDER BY id ASC"
    );
    if ($res) while ($r = mysqli_fetch_assoc($res)) $stories[] = $r;
}

$selected_podcast = null;
foreach ($podcasts as $p) {
    if ((int)$p['id'] === $selected_id) { $selected_podcast = $p; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VideoVizard — Scene Generator</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --y:   #FFE500;
  --o:   #FF6B00;
  --b:   #00C2FF;
  --g:   #00E676;
  --r:   #FF1744;
  --ink: #090910;
  --w:   #FFFFFF;
  --card:#F7F7FF;
  --mid: #E8E8F5;
  --muted:#9090AA;
  --rad: 10px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--w);color:var(--ink);min-height:100vh}

/* TOPBAR */
.topbar{
  background:var(--ink);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 2rem;height:58px;
  border-bottom:3px solid var(--y);
  position:sticky;top:0;z-index:200;
}
.logo{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--y);letter-spacing:1px}
.logo em{color:var(--o);font-style:normal}
.topbar-right{display:flex;align-items:center;gap:.8rem}
.badge{background:var(--y);color:var(--ink);font-size:.65rem;font-weight:700;
       padding:3px 10px;border-radius:20px;letter-spacing:1px;text-transform:uppercase}

/* LAYOUT */
.wrap{display:grid;grid-template-columns:270px 1fr;min-height:calc(100vh - 58px)}

/* SIDEBAR */
.sidebar{background:#F0F0FA;border-right:2px solid var(--mid);padding:1.2rem .8rem;overflow-y:auto}
.side-head{font-family:'Syne',sans-serif;font-size:.75rem;font-weight:800;
           letter-spacing:3px;color:var(--muted);text-transform:uppercase;
           margin-bottom:1rem;padding-bottom:.6rem;border-bottom:2px solid var(--mid)}
.pod-link{display:block;padding:.7rem .9rem;border-radius:var(--rad);margin-bottom:.35rem;
          text-decoration:none;color:var(--ink);border:2px solid transparent;transition:all .15s}
.pod-link:hover{background:var(--w);border-color:var(--y)}
.pod-link.active{background:var(--y);border-color:var(--o)}
.pod-name{font-size:.82rem;font-weight:600;margin-bottom:.25rem;line-height:1.3}
.pod-info{font-size:.7rem;color:var(--muted);display:flex;align-items:center;gap:.4rem}
.pod-link.active .pod-info{color:var(--ink);opacity:.65}
.fdot{width:7px;height:7px;border-radius:50%;background:var(--mid);flex-shrink:0}
.fdot.f0{background:var(--muted)}.fdot.f1{background:var(--b)}.fdot.f2{background:var(--g)}

/* MAIN */
.main{padding:2rem;overflow-y:auto;max-width:1200px}

/* PAGE HEADER */
.phead{display:flex;align-items:flex-start;justify-content:space-between;
       margin-bottom:1.8rem;flex-wrap:wrap;gap:1rem}
.ptitle{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;line-height:1.1}
.ptitle small{display:block;font-family:'Plus Jakarta Sans',sans-serif;
              font-size:.78rem;font-weight:500;color:var(--muted);margin-top:.4rem}

/* GENERATE BUTTON */
.btn-gen{
  background:var(--y);color:var(--ink);border:none;cursor:pointer;
  font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;letter-spacing:1px;
  padding:.75rem 1.8rem;border-radius:var(--rad);
  display:flex;align-items:center;gap:.6rem;
  box-shadow:4px 4px 0 var(--o);
  transition:all .12s;text-transform:uppercase;
}
.btn-gen:hover{transform:translate(-2px,-2px);box-shadow:6px 6px 0 var(--o)}
.btn-gen:active{transform:translate(2px,2px);box-shadow:2px 2px 0 var(--o)}
.btn-gen:disabled{background:var(--mid);color:var(--muted);box-shadow:none;cursor:not-allowed;transform:none}

/* STATUS BANNER */
.status-banner{
  border-radius:var(--rad);padding:1rem 1.5rem;
  margin-bottom:1.5rem;display:none;
  align-items:center;gap:.9rem;font-size:.88rem;font-weight:600;
}
.status-banner.show{display:flex}
.status-banner.processing{background:var(--ink);color:var(--y)}
.status-banner.success{background:#E8FFF0;color:#007A30;border:2px solid var(--g)}
.status-banner.error{background:#FFF0F0;color:#B00020;border:2px solid var(--r)}
.status-banner.info{background:#F0F8FF;color:#0050AA;border:2px solid var(--b)}
.spin{width:22px;height:22px;border:3px solid rgba(255,229,0,.25);
      border-top-color:var(--y);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}

/* DB UPDATE ROW */
.db-update-bar{
  background:linear-gradient(90deg,#001a40,#003080);
  border:2px solid var(--b);border-radius:var(--rad);
  padding:.8rem 1.2rem;margin-bottom:1.5rem;
  display:none;align-items:center;gap:.8rem;
  font-size:.8rem;font-weight:600;color:var(--b);
}
.db-update-bar.show{display:flex}
.db-dot{width:8px;height:8px;border-radius:50%;background:var(--b);
        animation:blink 1s infinite;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

/* SCENES SECTION */
.sec-title{font-family:'Syne',sans-serif;font-size:.7rem;font-weight:800;
           letter-spacing:3px;text-transform:uppercase;color:var(--muted);
           margin-bottom:1rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1.2rem}

/* SCENE CARD */
.card{background:var(--card);border-radius:var(--rad);border:2px solid var(--mid);
      overflow:hidden;transition:all .18s;position:relative}
.card:hover{border-color:var(--y);transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.1)}
.card.is-generating{border-color:var(--b);box-shadow:0 0 0 3px rgba(0,194,255,.15);animation:card-pulse 2s infinite}
.card.is-done{border-color:var(--g)}
@keyframes card-pulse{0%,100%{box-shadow:0 0 0 3px rgba(0,194,255,.15)}50%{box-shadow:0 0 0 6px rgba(0,194,255,.3)}}

/* VIDEO AREA */
.vid-wrap{width:100%;aspect-ratio:9/16;background:var(--ink);position:relative;overflow:hidden}
.vid-wrap video{width:100%;height:100%;object-fit:cover;display:block}
.placeholder{width:100%;height:100%;display:flex;flex-direction:column;
             align-items:center;justify-content:center;gap:.5rem;
             font-size:.72rem;color:#555}
.placeholder .ico{font-size:2.2rem;opacity:.25}
.placeholder.gen-state{background:linear-gradient(135deg,#080818,#0d1a35);color:var(--b)}
.placeholder.gen-state .ico{opacity:1;animation:ico-pulse 1.4s infinite}
@keyframes ico-pulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.1);opacity:1}}

/* VIDEO OVERLAY PLAY */
.vid-wrap .play-overlay{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  opacity:0;transition:opacity .2s;background:rgba(0,0,0,.3);cursor:pointer;
}
.vid-wrap:hover .play-overlay{opacity:1}
.play-btn{width:48px;height:48px;background:var(--y);border-radius:50%;
          display:flex;align-items:center;justify-content:center;font-size:1.2rem}

/* CARD BODY */
.cbody{padding:.85rem}
.scene-num{font-size:.68rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
           color:var(--muted);margin-bottom:.3rem}
.prompt-text{font-size:.77rem;color:#444;line-height:1.5;
             display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.cstatus{display:flex;align-items:center;gap:.4rem;margin-top:.6rem;
         font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.sdot{width:7px;height:7px;border-radius:50%;background:var(--muted);flex-shrink:0}
.sdot.done{background:var(--g)}.sdot.gen{background:var(--b);animation:blink 1s infinite}
.sdot.skip{background:var(--r)}

/* CARD ACTIONS */
.cactions{padding:.5rem .85rem .85rem;display:flex;gap:.5rem;flex-wrap:wrap}
.btn-sm{font-size:.68rem;font-weight:700;padding:.32rem .75rem;border-radius:5px;
        border:none;cursor:pointer;letter-spacing:.5px;text-transform:uppercase;transition:all .12s}
.btn-view{background:var(--y);color:var(--ink)}
.btn-view:hover{background:var(--o);color:var(--w)}
.btn-regen{background:var(--ink);color:var(--y)}
.btn-regen:hover{background:#222}
.btn-this{background:var(--b);color:var(--ink)}
.btn-this:hover{background:#00a0d6}

/* FULL VIDEO MODAL */
.voverlay{position:fixed;inset:0;background:rgba(0,0,0,.9);
          z-index:500;display:none;align-items:center;justify-content:center}
.voverlay.open{display:flex}
.vbox{background:var(--ink);border:3px solid var(--y);border-radius:14px;
      padding:1.5rem;max-width:380px;width:92%;position:relative}
.vbox-close{position:absolute;top:.7rem;right:.9rem;background:none;border:none;
            color:var(--y);font-size:1.5rem;cursor:pointer;line-height:1}
.vbox-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;
            color:var(--y);margin-bottom:.8rem;letter-spacing:.5px}
.vbox video{width:100%;border-radius:8px;display:block;max-height:70vh}

/* EMPTY */
.empty{text-align:center;padding:5rem 2rem;color:var(--muted);grid-column:1/-1}
.empty .eico{font-size:3rem;margin-bottom:1rem;opacity:.3}
.empty p{font-size:.88rem}

@media(max-width:680px){
  .wrap{grid-template-columns:1fr}
  .sidebar{display:none}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="logo">Video<em>Vizard</em></div>
  <div class="topbar-right">
    <span class="badge">Scene Generator</span>
  </div>
</div>

<div class="wrap">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="side-head">📁 Podcasts</div>
    <?php if (empty($podcasts)): ?>
      <p style="font-size:.8rem;color:var(--muted)">No podcasts found.</p>
    <?php else: foreach ($podcasts as $p):
      $active = ((int)$p['id'] === $selected_id);
      $fc = $p['videogen_flag'] == 1 ? 'f1' : ($p['videogen_flag'] == 2 ? 'f2' : 'f0');
    ?>
      <a href="?podcast_id=<?= $p['id'] ?>" class="pod-link <?= $active ? 'active' : '' ?>">
        <div class="pod-name"><?= htmlspecialchars($p['title'] ?? 'Podcast #'.$p['id']) ?></div>
        <div class="pod-info">
          <span class="fdot <?= $fc ?>"></span>
          <?= (int)$p['done_stories'] ?>/<?= (int)$p['total_stories'] ?> scenes &nbsp;·&nbsp; #<?= $p['id'] ?>
        </div>
      </a>
    <?php endforeach; endif; ?>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <?php if ($selected_id > 0): ?>

    <div class="phead">
      <div class="ptitle">
        Generate Scene
        <small>Podcast #<?= $selected_id ?> &nbsp;·&nbsp; <?= count($stories) ?> total scenes &nbsp;·&nbsp;
          Sends 1 scene to Modal.com &nbsp;·&nbsp; flag=<?= $selected_podcast['videogen_flag'] ?? '?' ?>
        </small>
      </div>
      <button class="btn-gen" id="btnGen" onclick="startGen()">
        ▶&nbsp; Generate Scene
      </button>
    </div>

    <!-- Status banner -->
    <div class="status-banner" id="statusBanner">
      <div class="spin" id="statusSpin"></div>
      <span id="statusMsg">Starting...</span>
    </div>

    <!-- DB update indicator -->
    <div class="db-update-bar" id="dbBar">
      <span class="db-dot"></span>
      <span id="dbMsg">Checking database...</span>
    </div>

    <!-- Scenes -->
    <div class="sec-title">All Scenes</div>
    <div class="grid" id="grid">
      <?php foreach ($stories as $i => $s):
        $img    = trim($s['image_file'] ?? '');
        $hasvid = !empty($img) && $img !== 'skipped_no_prompt' && strpos($img, 'wan_') === 0;
        $skip   = ($img === 'skipped_no_prompt');
        $folder = rtrim($s['image_folder'] ?? 'user_videos', '/') . '/';
        $vpath  = $hasvid ? $folder . $img : '';
        $snum   = $s['scene_number'] ?? ($i + 1);
      ?>
      <div class="card <?= $hasvid ? 'is-done' : '' ?>" id="card-<?= $s['id'] ?>">

        <div class="vid-wrap">
          <?php if ($hasvid): ?>
            <video src="<?= htmlspecialchars($vpath) ?>" muted playsinline loop
                   id="vid-<?= $s['id'] ?>"></video>
            <div class="play-overlay" onclick="openModal('<?= htmlspecialchars($vpath) ?>','Scene <?= $snum ?>')">
              <div class="play-btn">▶</div>
            </div>
          <?php else: ?>
            <div class="placeholder <?= $skip ? '' : '' ?>" id="ph-<?= $s['id'] ?>">
              <div class="ico"><?= $skip ? '⚠️' : '🎬' ?></div>
              <div><?= $skip ? 'No Prompt' : 'Not Generated' ?></div>
            </div>
          <?php endif; ?>
        </div>

        <div class="cbody">
          <div class="scene-num">Scene <?= $snum ?> &nbsp;·&nbsp; ID #<?= $s['id'] ?></div>
          <div class="prompt-text"><?= htmlspecialchars($s['video_prompt'] ?? '—') ?></div>
          <div class="cstatus" id="cstatus-<?= $s['id'] ?>">
            <span class="sdot <?= $hasvid ? 'done' : ($skip ? 'skip' : '') ?>"></span>
            <?= $hasvid ? 'Done ✓' : ($skip ? 'Skipped' : 'Pending') ?>
          </div>
        </div>

        <div class="cactions" id="cactions-<?= $s['id'] ?>">
          <?php if ($hasvid): ?>
            <button class="btn-sm btn-view" onclick="openModal('<?= htmlspecialchars($vpath) ?>','Scene <?= $snum ?>')">▶ Play</button>
            <a href="<?= htmlspecialchars($vpath) ?>" download class="btn-sm btn-regen" style="text-decoration:none">⬇ Save</a>
            <button class="btn-sm btn-regen" onclick="regenScene(<?= $s['id'] ?>)">↻ Regen</button>
          <?php else: ?>
            <button class="btn-sm btn-this" onclick="regenScene(<?= $s['id'] ?>)">▶ Generate This</button>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>

      <?php if (empty($stories)): ?>
        <div class="empty">
          <div class="eico">🎬</div>
          <p>No scenes found for this podcast.</p>
        </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="empty" style="padding:6rem 2rem">
      <div class="eico">📁</div>
      <p>Select a podcast from the sidebar.</p>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- VIDEO MODAL -->
<div class="voverlay" id="voverlay">
  <div class="vbox">
    <button class="vbox-close" onclick="closeModal()">✕</button>
    <div class="vbox-title" id="vboxTitle">Scene Video</div>
    <video id="vboxVideo" controls autoplay playsinline></video>
  </div>
</div>

<script>
const PID    = <?= $selected_id ?>;
let polling  = null;
let busy     = false;
let activeStoryId = null;

// ── Start generation (first pending scene) ────────────────────────────────────
function startGen() {
  if (busy) return;
  setBusy(true);
  showStatus('processing', '⚡ Contacting Modal.com — please wait...');
  hideDbBar();

  fetch(`wan_text2_video_api.php?action=start&podcast_id=${PID}`)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showStatus('processing', '🎬 Scene sent to Modal.com — generating video...');
        startPolling();
      } else {
        showStatus('error', '❌ ' + (d.message || 'Failed to start'));
        setBusy(false);
      }
    })
    .catch(e => { showStatus('error', '❌ Network error: ' + e.message); setBusy(false); });
}

// ── Regen a specific scene ────────────────────────────────────────────────────
function regenScene(storyId) {
  if (busy) { alert('Already generating. Please wait.'); return; }
  setBusy(true);
  activeStoryId = storyId;
  setCardGen(storyId);
  showStatus('processing', `⚡ Sending Scene #${storyId} to Modal.com...`);

  fetch(`wan_text2_video_api.php?action=start&podcast_id=${PID}`)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showStatus('processing', `🎬 Scene #${storyId} generating on Modal.com...`);
        startPolling();
      } else {
        showStatus('error', '❌ ' + (d.message || 'Failed'));
        setBusy(false);
      }
    })
    .catch(e => { showStatus('error', '❌ ' + e.message); setBusy(false); });
}

// ── Poll every 4s ────────────────────────────────────────────────────────────
function startPolling() {
  if (polling) clearInterval(polling);
  polling = setInterval(doPoll, 4000);
}

function doPoll() {
  fetch(`wan_text2_video_api.php?action=status&podcast_id=${PID}`)
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;

      // Update all cards from DB state
      (d.stories || []).forEach(updateCard);

      if (d.status === 'all_done') {
        clearInterval(polling);
        polling = null;
        showStatus('success', `✅ Scene generated and saved! videogen_flag=2.`);
        showDbBar(`hdb_podcasts.videogen_flag = 2 &nbsp;·&nbsp; hdb_podcast_stories.image_file updated ✅`);
        setBusy(false);
      } else if (d.status === 'processing') {
        showStatus('processing',
          `🎬 ${d.done_stories}/${d.total_stories} done — Waiting for Modal.com response...`);
      } else {
        clearInterval(polling);
        polling = null;
        showStatus('info', `ℹ️ ${d.message}`);
        setBusy(false);
      }
    })
    .catch(() => {}); // silent retry
}

// ── Update a single card from status data ─────────────────────────────────────
function updateCard(s) {
  const card    = document.getElementById('card-' + s.story_id);
  const ph      = document.getElementById('ph-' + s.story_id);
  const cstatus = document.getElementById('cstatus-' + s.story_id);
  const cacts   = document.getElementById('cactions-' + s.story_id);
  if (!card) return;

  if (s.state === 'done' && s.video_file) {
    card.classList.remove('is-generating');
    card.classList.add('is-done');

    const wrap = card.querySelector('.vid-wrap');
    if (!wrap.querySelector('video')) {
      wrap.innerHTML = `
        <video src="${s.video_file}" muted playsinline loop id="vid-${s.story_id}"></video>
        <div class="play-overlay" onclick="openModal('${s.video_file}','Scene')">
          <div class="play-btn">▶</div>
        </div>`;
    }

    if (cstatus) cstatus.innerHTML = '<span class="sdot done"></span> Done ✓';
    if (cacts) cacts.innerHTML = `
      <button class="btn-sm btn-view" onclick="openModal('${s.video_file}','Scene ${s.story_id}')">▶ Play</button>
      <a href="${s.video_file}" download class="btn-sm btn-regen" style="text-decoration:none">⬇ Save</a>
      <button class="btn-sm btn-regen" onclick="regenScene(${s.story_id})">↻ Regen</button>`;

  } else if (s.state === 'pending' && busy) {
    if (cstatus) cstatus.innerHTML = '<span class="sdot gen"></span> Generating...';
    if (ph) { ph.className = 'placeholder gen-state'; ph.innerHTML = '<div class="ico">⚡</div><div>Generating...</div>'; }
    card.classList.add('is-generating');
  }
}

// ── Card generating state ─────────────────────────────────────────────────────
function setCardGen(storyId) {
  const card    = document.getElementById('card-' + storyId);
  const ph      = document.getElementById('ph-' + storyId);
  const cstatus = document.getElementById('cstatus-' + storyId);
  if (card)    card.classList.add('is-generating');
  if (ph)    { ph.className = 'placeholder gen-state'; ph.innerHTML = '<div class="ico">⚡</div><div>Generating...</div>'; }
  if (cstatus) cstatus.innerHTML = '<span class="sdot gen"></span> Generating...';
}

// ── UI helpers ────────────────────────────────────────────────────────────────
function setBusy(b) {
  busy = b;
  document.getElementById('btnGen').disabled = b;
}

function showStatus(type, msg) {
  const el = document.getElementById('statusBanner');
  const sp = document.getElementById('statusSpin');
  const tx = document.getElementById('statusMsg');
  el.className = 'status-banner show ' + type;
  sp.style.display = type === 'processing' ? 'block' : 'none';
  tx.textContent = msg;
}

function showDbBar(msg) {
  const el = document.getElementById('dbBar');
  document.getElementById('dbMsg').innerHTML = msg;
  el.classList.add('show');
}
function hideDbBar() {
  document.getElementById('dbBar').classList.remove('show');
}

// ── Video modal ───────────────────────────────────────────────────────────────
function openModal(src, title) {
  document.getElementById('vboxVideo').src = src;
  document.getElementById('vboxTitle').textContent = title;
  document.getElementById('voverlay').classList.add('open');
}
function closeModal() {
  document.getElementById('voverlay').classList.remove('open');
  document.getElementById('vboxVideo').pause();
  document.getElementById('vboxVideo').src = '';
}
document.getElementById('voverlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Hover play/pause on cards ─────────────────────────────────────────────────
document.addEventListener('mouseover', e => {
  const wrap = e.target.closest('.vid-wrap');
  if (wrap) { const v = wrap.querySelector('video'); if (v) v.play(); }
});
document.addEventListener('mouseout', e => {
  const wrap = e.target.closest('.vid-wrap');
  if (wrap) { const v = wrap.querySelector('video'); if (v) { v.pause(); v.currentTime = 0; } }
});
</script>
</body>
</html>

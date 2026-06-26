<?php
// scheduler_test.php — fully self-contained, no session needed
// Place in same folder as dbconnect_hdb.php
// Test: scheduler_test.php?podcast_id=19

// ── AJAX handler (built-in, no separate social_schedule.php needed) ──

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (isset($data['ajax_action']) && $data['ajax_action'] === 'schedule_post') {
    header('Content-Type: application/json');

    include 'dbconnect_hdb.php';


    // If JSON body empty, try POST fields (fallback)
    if (!$data) $data = $_POST;

    $podcast_id     = (int)($data['podcast_id']    ?? 0);
    $platforms      = $data['platforms']           ?? [];
    $caption        = trim($data['caption']        ?? '');
    $keywords       = trim($data['keywords']       ?? '');
    $hashtags       = trim($data['hashtags']       ?? '');
    $sched_date     = trim($data['sched_date']     ?? date('Y-m-d'));
    $sched_time     = trim($data['sched_time']     ?? '09:00');
    $post_type      = trim($data['post_type']      ?? 'scheduled');
    $video_filename = trim($data['video_filename'] ?? '');

    if (!$podcast_id) {
        echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
        exit;
    }

    // Check podcast exists (no admin_id check in test mode)
    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, admin_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if (!$chk) {
        echo json_encode(['success'=>false,'error'=>"Podcast #$podcast_id not found in DB"]);
        exit;
    }

    $admin_id = (int)$chk['admin_id'];

    // Per-platform status
    $all_platforms = ['instagram','tiktok','youtube','facebook','twitter','linkedin'];
    $pstatus = [];
    foreach ($all_platforms as $p) {
        $pstatus[$p] = in_array($p, $platforms) ? 'pending' : 'skip';
    }

    $video_status = ($post_type === 'now') ? 'posting' : 'scheduled';
    $now          = date('Y-m-d H:i:s');

    $esc_caption  = mysqli_real_escape_string($conn, $caption);
    $esc_keywords = mysqli_real_escape_string($conn, $keywords);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
    $esc_date     = mysqli_real_escape_string($conn, $sched_date);
    $esc_time     = mysqli_real_escape_string($conn, $sched_time);
    $esc_vstatus  = mysqli_real_escape_string($conn, $video_status);
    $esc_vid_file = mysqli_real_escape_string($conn, $video_filename);

    $ok = mysqli_query($conn,
        "UPDATE hdb_podcasts SET
            caption_text     = '$esc_caption',
            keywords         = '$esc_keywords',
            hashtags         = '$esc_hashtags',
            schedule_date    = '$esc_date',
            schedule_time    = '$esc_time',
            video_status     = '$esc_vstatus',
            video_filename   = '$esc_vid_file',
            instagram_status = '{$pstatus['instagram']}',
            tiktok_status    = '{$pstatus['tiktok']}',
            youtube_status   = '{$pstatus['youtube']}',
            facebook_status  = '{$pstatus['facebook']}',
            twitter_status   = '{$pstatus['twitter']}',
            linkedin_status  = '{$pstatus['linkedin']}',
            updated_at       = '$now'
         WHERE id = $podcast_id");

    if (!$ok) {
        echo json_encode(['success'=>false,'error'=>'DB error: '.mysqli_error($conn)]);
        exit;
    }

    $affected = mysqli_affected_rows($conn);

    // Save to hdb_schedule if exists
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_schedule'");
    $schedule_saved = false;
    if ($tbl && mysqli_num_rows($tbl) > 0) {
        mysqli_query($conn, "DELETE FROM hdb_schedule WHERE podcast_id=$podcast_id");
        foreach ($platforms as $platform) {
            $esc_plat = mysqli_real_escape_string($conn, $platform);
            mysqli_query($conn,
                "INSERT INTO hdb_schedule
                    (admin_id, podcast_id, platform, caption, sched_date, sched_time,
                     post_type, status, created_at)
                 VALUES
                    ($admin_id, $podcast_id, '$esc_plat', '$esc_caption',
                     '$esc_date', '$esc_time', '$post_type', 'pending', '$now')");
        }
        $schedule_saved = true;
    }

    echo json_encode([
        'success'        => true,
        'podcast_id'     => $podcast_id,
        'admin_id'       => $admin_id,
        'video_status'   => $video_status,
        'platforms'      => $platforms,
        'schedule_date'  => $sched_date,
        'schedule_time'  => $sched_time,
        'rows_affected'  => $affected,
        'schedule_saved' => $schedule_saved,
        'test_mode'      => true,
    ]);
    exit;
}

$podcast_id = intval($_GET['podcast_id'] ?? 19);

// Load podcast title from DB if possible
$podcast_title = 'Test Video #' . $podcast_id;
if (file_exists('dbconnect_hdb.php')) {
    include 'dbconnect_hdb.php';
    $pr = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT title FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if ($pr) $podcast_title = $pr['title'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scheduler Test — Podcast #<?= $podcast_id ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    flex-direction: column;
    gap: 20px;
    padding: 20px;
}

.trigger-card {
    background: #fff;
    border-radius: 14px;
    padding: 24px 30px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    text-align: center;
    width: 100%;
    max-width: 420px;
}
.trigger-card h2 {
    font-size: 15px;
    font-weight: 700;
    color: #0f2a44;
    margin-bottom: 4px;
}
.trigger-card p {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 18px;
}
.trigger-btn {
    padding: 13px 32px;
    background: linear-gradient(135deg, #0f2a44, #143b63);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: box-shadow .15s;
}
.trigger-btn:hover { box-shadow: 0 4px 14px rgba(15,42,68,0.35); }

/* ── RESULT BOX ── */
#resultBox {
    display: none;
    width: 100%;
    max-width: 420px;
    border-radius: 12px;
    padding: 14px 18px;
    font-size: 12px;
    font-family: monospace;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-all;
}
#resultBox.ok  { background: #f0fdf4; border: 1.5px solid #86efac; color: #166534; }
#resultBox.err { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; }

/* ── OVERLAY ── */
.vs-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 9999;
    align-items: flex-start;
    justify-content: center;
    padding: 12px;
    overflow-y: auto;
}
.vs-overlay.open { display: flex; }

/* ── MODAL ── */
.vs-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    animation: vsSlide 0.28s cubic-bezier(0.16,1,0.3,1);
    margin: auto;
    max-height: calc(100vh - 24px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
@keyframes vsSlide {
    from { opacity:0; transform:translateY(20px) scale(0.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}

/* Header */
.vs-head {
    background: linear-gradient(90deg, #0f2a44, #143b63);
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.vs-head-left { display:flex; align-items:center; gap:8px; }
.vs-head-icon {
    width:28px; height:28px;
    background: rgba(95,209,255,0.15);
    border-radius: 7px;
    display:flex; align-items:center; justify-content:center;
    font-size:14px; flex-shrink:0;
}
.vs-head-title { font-size:13px; font-weight:700; color:#fff; margin:0; }
.vs-head-sub {
    font-size:10px; color:rgba(255,255,255,0.5); margin:1px 0 0;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px;
}
.vs-close {
    width:26px; height:26px; border-radius:50%;
    border:1px solid rgba(255,255,255,0.15);
    background:rgba(255,255,255,0.08);
    color:rgba(255,255,255,0.6);
    font-size:13px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .15s; flex-shrink:0;
}
.vs-close:hover { background:rgba(255,255,255,0.18); color:#fff; }

/* Saved bar */
.vs-saved {
    background:#f0fdf4; border-bottom:1px solid #bbf7d0;
    padding:6px 14px;
    display:flex; align-items:center; gap:8px;
    font-size:11px; color:#166534; font-weight:500;
    flex-shrink:0;
}
.vs-saved-dot {
    width:7px; height:7px; background:#22c55e; border-radius:50%;
    flex-shrink:0; animation:vsPulse 1.5s ease-in-out infinite;
}
@keyframes vsPulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

/* Body — scrollable */
.vs-body {
    padding:10px 14px 14px;
    overflow-y:auto;
    flex:1;
    min-height:0;
}

.vs-lbl {
    font-size:10px; font-weight:700; color:#94a3b8;
    text-transform:uppercase; letter-spacing:.08em; margin-bottom:5px;
}

/* Platforms */
.vs-platforms { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:10px; }
.vs-plat {
    display:flex; align-items:center; gap:4px;
    padding:5px 10px;
    border:1.5px solid #e2e8f0; border-radius:20px;
    background:#f8fafc; font-size:11px; font-weight:600; color:#64748b;
    cursor:pointer; transition:all .15s; white-space:nowrap;
}
.vs-plat:hover  { border-color:#0f2a44; color:#0f2a44; background:#f0f4f8; }
.vs-plat.sel    { background:#0f2a44; border-color:#0f2a44; color:#fff; }
.vs-plat.disconnected { opacity:0.4; cursor:not-allowed; pointer-events:none; }
.vs-plat-icon { font-size:12px; }

.vs-warn { font-size:11px; color:#ef4444; margin-top:-8px; margin-bottom:8px; display:none; }

/* Textareas */
.vs-textarea {
    width:100%; padding:7px 10px;
    border:1.5px solid #e2e8f0; border-radius:8px;
    font-size:12px; font-family:inherit;
    resize:none; outline:none;
    color:#1e293b; line-height:1.5;
    transition:border-color .15s; margin-bottom:10px;
}
.vs-textarea:focus { border-color:#0f2a44; }

/* Date row */
.vs-date-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px; }
.vs-input {
    width:100%; padding:7px 9px;
    border:1.5px solid #e2e8f0; border-radius:8px;
    font-size:12px; font-family:inherit; color:#1e293b;
    outline:none; transition:border-color .15s;
}
.vs-input:focus { border-color:#0f2a44; }

/* Quick pills */
.vs-quick { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:8px; }
.vs-qpill {
    padding:3px 9px;
    border:1.5px solid #e2e8f0; border-radius:12px;
    font-size:10px; font-weight:500; color:#64748b;
    cursor:pointer; background:#fff; transition:all .15s;
}
.vs-qpill:hover, .vs-qpill.active { border-color:#0f2a44; color:#0f2a44; background:#f0f4f8; }

/* Footer buttons */
.vs-footer { display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-top:4px; }
.vs-dl-btn {
    grid-column:span 2; padding:9px;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff; border:none; border-radius:9px;
    font-size:12px; font-weight:700; cursor:pointer; transition:all .15s;
    margin-bottom:3px;
}
.vs-dl-btn:hover { box-shadow:0 4px 12px rgba(124,58,237,0.3); }
.vs-btn-now {
    padding:9px; background:linear-gradient(135deg,#10b981,#059669);
    color:#fff; border:none; border-radius:9px;
    font-size:12px; font-weight:700; cursor:pointer; transition:all .15s;
}
.vs-btn-now:hover { box-shadow:0 4px 12px rgba(16,185,129,0.3); }
.vs-btn-sched {
    padding:9px; background:linear-gradient(135deg,#0f2a44,#143b63);
    color:#fff; border:none; border-radius:9px;
    font-size:12px; font-weight:700; cursor:pointer; transition:all .15s;
}
.vs-btn-sched:hover { box-shadow:0 4px 12px rgba(15,42,68,0.3); }
.vs-btn-skip {
    grid-column:span 2; padding:7px;
    background:none; border:none; color:#94a3b8;
    font-size:11px; cursor:pointer; transition:color .15s;
}
.vs-btn-skip:hover { color:#64748b; }

/* Confirm */
.vs-confirm { display:none; padding:24px 18px; text-align:center; }
.vs-confirm-icon  { font-size:40px; margin-bottom:10px; }
.vs-confirm-title { font-size:17px; font-weight:700; color:#0f2a44; margin-bottom:4px; }
.vs-confirm-sub   { font-size:12px; color:#64748b; margin-bottom:14px; }
.vs-confirm-pills { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin-bottom:16px; }
.vs-confirm-pill  {
    padding:4px 12px; background:#f0f4f8;
    border:1px solid #e2e8f0; border-radius:12px;
    font-size:12px; font-weight:600; color:#0f2a44;
}
.vs-confirm-done {
    width:100%; padding:12px;
    background:linear-gradient(135deg,#0f2a44,#143b63);
    color:#fff; border:none; border-radius:9px;
    font-size:14px; font-weight:700; cursor:pointer;
}

/* Spinner */
.vs-spinner {
    display:none; width:18px; height:18px;
    border:2px solid rgba(255,255,255,0.3);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .6s linear infinite;
    margin:0 auto;
}
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>
<body>

<div class="trigger-card">
    <h2>📤 Scheduler Test</h2>
    <p>Podcast #<strong><?= $podcast_id ?></strong> — <?= htmlspecialchars($podcast_title) ?></p>
    <button class="trigger-btn" onclick="openSchedModal()">Open Publish Scheduler</button>
</div>

<div id="resultBox"></div>

<!-- ══ MODAL ══ -->
<div class="vs-overlay" id="vsOverlay">
  <div class="vs-modal">

    <!-- Main panel -->
    <div id="vsMain">
      <div class="vs-head">
        <div class="vs-head-left">
          <div class="vs-head-icon">📤</div>
          <div>
            <div class="vs-head-title">Publish Video</div>
            <div class="vs-head-sub" id="vsSubTitle"><?= htmlspecialchars($podcast_title) ?></div>
          </div>
        </div>
        <button class="vs-close" onclick="closeSchedModal()">✕</button>
      </div>

      <div class="vs-saved">
        <div class="vs-saved-dot"></div>
        <span>Video saved — Podcast <strong>#<?= $podcast_id ?></strong></span>
      </div>

      <div class="vs-body">

        <div class="vs-lbl">Platforms</div>
        <div class="vs-platforms">
          <div class="vs-plat sel"          data-p="instagram" onclick="vsTogglePlat(this)"><span class="vs-plat-icon">📸</span> Instagram</div>
          <div class="vs-plat sel"          data-p="tiktok"    onclick="vsTogglePlat(this)"><span class="vs-plat-icon">🎵</span> TikTok</div>
          <div class="vs-plat sel"          data-p="youtube"   onclick="vsTogglePlat(this)"><span class="vs-plat-icon">▶️</span> YouTube</div>
          <div class="vs-plat disconnected" data-p="facebook"                              ><span class="vs-plat-icon">📘</span> Facebook</div>
          <div class="vs-plat disconnected" data-p="twitter"                               ><span class="vs-plat-icon">🐦</span> X</div>
          <div class="vs-plat disconnected" data-p="linkedin"                              ><span class="vs-plat-icon">💼</span> LinkedIn</div>
        </div>
        <div class="vs-warn" id="vsWarn">⚠ Select at least one platform</div>

        <div class="vs-lbl">Caption</div>
        <textarea class="vs-textarea" id="vsCaption" rows="3" placeholder="Write a caption for this post…"></textarea>

        <div class="vs-lbl">Keywords</div>
        <textarea class="vs-textarea" id="vsKeywords" rows="2" placeholder="keyword1, keyword2…"></textarea>

        <div class="vs-lbl">Hashtags</div>
        <textarea class="vs-textarea" id="vsHashtags" rows="2" placeholder="#hashtag1 #hashtag2…"></textarea>

        <div class="vs-lbl">Schedule</div>
        <div class="vs-quick">
          <button class="vs-qpill"        onclick="vsQuick(this,0)"  >Now</button>
          <button class="vs-qpill"        onclick="vsQuick(this,1)"  >+1hr</button>
          <button class="vs-qpill active" onclick="vsQuick(this,24)" >Tomorrow</button>
          <button class="vs-qpill"        onclick="vsQuick(this,72)" >+3 days</button>
          <button class="vs-qpill"        onclick="vsQuick(this,168)">Next week</button>
        </div>
        <div class="vs-date-row">
          <div>
            <div class="vs-lbl">Date</div>
            <input type="date" class="vs-input" id="vsDate">
          </div>
          <div>
            <div class="vs-lbl">Time</div>
            <input type="time" class="vs-input" id="vsTime" value="09:00">
          </div>
        </div>

        <div class="vs-footer">
          <button class="vs-dl-btn" onclick="vsDownload()">⬇ Download Video</button>
          <button class="vs-btn-now"   id="btnNow"   onclick="vsPostNow()">⚡ Post Now</button>
          <button class="vs-btn-sched" id="btnSched" onclick="vsSchedule()">🗓 Schedule</button>
          <button class="vs-btn-skip"  onclick="closeSchedModal()">Skip — publish manually</button>
        </div>

      </div>
    </div>

    <!-- Confirm panel -->
    <div class="vs-confirm" id="vsConfirm">
      <div class="vs-confirm-icon"  id="vsConfirmIcon">🗓</div>
      <div class="vs-confirm-title" id="vsConfirmTitle">Scheduled!</div>
      <div class="vs-confirm-sub"   id="vsConfirmSub"></div>
      <div class="vs-confirm-pills" id="vsConfirmPills"></div>
      <button class="vs-confirm-done" onclick="closeSchedModal()">Done ✓</button>
    </div>

  </div>
</div>

<script>
const PODCAST_ID = <?= $podcast_id ?>;

function openSchedModal() {
    document.getElementById('vsMain').style.display    = 'block';
    document.getElementById('vsConfirm').style.display = 'none';
    document.getElementById('vsWarn').style.display    = 'none';
    vsQuick(document.querySelectorAll('.vs-qpill')[2], 24);
    document.getElementById('vsOverlay').classList.add('open');
}

function closeSchedModal() {
    document.getElementById('vsOverlay').classList.remove('open');
}

// Close on backdrop click
document.getElementById('vsOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeSchedModal();
});

function vsTogglePlat(el) {
    el.classList.toggle('sel');
    document.getElementById('vsWarn').style.display = 'none';
}

function vsGetPlats() {
    return [...document.querySelectorAll('.vs-plat.sel:not(.disconnected)')].map(el => el.dataset.p);
}

function vsQuick(btn, hrs) {
    document.querySelectorAll('.vs-qpill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const d = new Date();
    d.setHours(d.getHours() + hrs);
    document.getElementById('vsDate').value = d.toISOString().split('T')[0];
    document.getElementById('vsTime').value = d.toTimeString().slice(0,5);
}

function vsDownload() {
    // In production this will trigger the actual video download
    alert('Download: In production this downloads podcast_' + PODCAST_ID + '.mp4');
    closeSchedModal();
}

function vsPostNow() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display='block'; return; }
    vsSave('now', plats, null);
}

function vsSchedule() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display='block'; return; }
    const date = document.getElementById('vsDate').value;
    const time = document.getElementById('vsTime').value;
    if (!date || !time) { alert('Please select a date and time'); return; }
    vsSave('scheduled', plats, new Date(date + 'T' + time));
}

function _setBtnLoading(loading) {
    const n = document.getElementById('btnNow');
    const s = document.getElementById('btnSched');
    if (n) { n.disabled = loading; n.textContent = loading ? '…' : '⚡ Post Now'; }
    if (s) { s.disabled = loading; s.textContent = loading ? '…' : '🗓 Schedule'; }
}

async function vsSave(type, plats, dt) {
    _setBtnLoading(true);

    const payload = {
        podcast_id:     PODCAST_ID,
        platforms:      plats,
        caption:        document.getElementById('vsCaption').value,
        keywords:       document.getElementById('vsKeywords').value,
        hashtags:       document.getElementById('vsHashtags').value,
        sched_date:     dt ? dt.toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
        sched_time:     dt ? dt.toTimeString().slice(0,5)   : new Date().toTimeString().slice(0,5),
        post_type:      type,
        video_filename: 'podcast_' + PODCAST_ID + '.mp4',
    };

    console.log('📤 Sending:', JSON.stringify(payload, null, 2));

    try {
        // POST to THIS same file with ajax_action=schedule_post
        const fd = new FormData();
        fd.append('ajax_action', 'schedule_post');

        const r    = await fetch(location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ ...payload, ajax_action: 'schedule_post' }),
        });
        const data = await r.json();

        console.log('✅ Response:', data);
        _setBtnLoading(false);

        if (data.success) {
            _showResult(true, data);
            vsShowConfirm(type, plats, dt);
        } else {
            _showResult(false, data);
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch(e) {
        console.error('❌ Fetch error:', e);
        _setBtnLoading(false);
        _showResult(false, { error: e.message });
        alert('Request failed: ' + e.message);
    }
}

function _showResult(ok, data) {
    const box = document.getElementById('resultBox');
    box.className = 'resultBox ' + (ok ? 'ok' : 'err');
    box.style.display = 'block';
    box.textContent = (ok ? '✅ DB SAVED\n' : '❌ ERROR\n') + JSON.stringify(data, null, 2);
}

function vsShowConfirm(type, plats, dt) {
    document.getElementById('vsMain').style.display    = 'none';
    document.getElementById('vsConfirm').style.display = 'block';
    const labels = {
        instagram:'📸 Instagram', tiktok:'🎵 TikTok', youtube:'▶️ YouTube',
        facebook:'📘 Facebook',   twitter:'🐦 X',      linkedin:'💼 LinkedIn',
    };
    if (type === 'now') {
        document.getElementById('vsConfirmIcon').textContent  = '🎉';
        document.getElementById('vsConfirmTitle').textContent = 'Posted!';
        document.getElementById('vsConfirmSub').textContent   = 'Going live now';
    } else {
        const ds = dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
        const ts = dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
        document.getElementById('vsConfirmIcon').textContent  = '🗓';
        document.getElementById('vsConfirmTitle').textContent = 'Scheduled!';
        document.getElementById('vsConfirmSub').textContent   = `Posts ${ds} at ${ts}`;
    }
    document.getElementById('vsConfirmPills').innerHTML =
        plats.map(p => `<span class="vs-confirm-pill">${labels[p]||p}</span>`).join('');
}
</script>

</body>
</html>

<?php
// Test with: scheduler_test.php?podcast_id=19
$podcast_id = intval($_GET['podcast_id'] ?? 19);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scheduler Test — Podcast #<?= $podcast_id ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; flex-direction: column; gap: 20px; }

/* ── TRIGGER BUTTON ── */
.trigger-btn {
  padding: 14px 32px;
  background: linear-gradient(135deg, #0f2a44, #143b63);
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
}
.trigger-label { font-size: 13px; color: #64748b; }

/* ── OVERLAY ── */
.vs-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 9999;
  align-items: center;
  justify-content: center;
  padding: 12px;
}
.vs-overlay.open { display: flex; }
.vs-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.35);
  overflow: hidden;
  animation: vsSlide 0.28s cubic-bezier(0.16,1,0.3,1);
}
@keyframes vsSlide {
  from { opacity:0; transform:translateY(20px) scale(0.97); }
  to   { opacity:1; transform:translateY(0) scale(1); }
}

/* Header */
.vs-head {
  background: linear-gradient(90deg, #0f2a44, #143b63);
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.vs-head-left { display:flex; align-items:center; gap:10px; }
.vs-head-icon {
  width: 34px; height: 34px;
  background: rgba(95,209,255,0.15);
  border-radius: 8px;
  display: flex; align-items:center; justify-content:center;
  font-size: 16px;
}
.vs-head-title { font-size:15px; font-weight:700; color:#fff; margin:0; }
.vs-head-sub { font-size:11px; color:rgba(255,255,255,0.5); margin:1px 0 0; }
.vs-close {
  width:28px; height:28px; border-radius:50%;
  border: 1px solid rgba(255,255,255,0.15);
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6);
  font-size:14px; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition: all .15s;
}
.vs-close:hover { background:rgba(255,255,255,0.18); color:#fff; }

/* Success bar */
.vs-saved {
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  padding: 9px 18px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #166534;
  font-weight: 500;
}
.vs-saved-dot {
  width: 8px; height: 8px;
  background: #22c55e;
  border-radius: 50%;
  flex-shrink: 0;
  animation: vsPulse 1.5s ease-in-out infinite;
}
@keyframes vsPulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

/* Body */
.vs-body { padding: 14px 18px 16px; }
.vs-lbl {
  font-size: 10px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 8px;
}

/* Platform buttons */
.vs-platforms { display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px; }
.vs-plat {
  display: flex; align-items:center; gap:5px;
  padding: 6px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 20px;
  background: #f8fafc;
  font-size: 12px; font-weight:600; color:#64748b;
  cursor: pointer; transition: all .15s; white-space:nowrap;
}
.vs-plat:hover { border-color:#0f2a44; color:#0f2a44; background:#f0f4f8; }
.vs-plat.sel { background:#0f2a44; border-color:#0f2a44; color:#fff; }
.vs-plat.disconnected { opacity:0.4; cursor:not-allowed; pointer-events:none; }
.vs-plat-icon { font-size:13px; }

/* Caption */
.vs-textarea {
  width:100%; padding:9px 12px;
  border:1.5px solid #e2e8f0; border-radius:8px;
  font-size:13px; font-family:inherit;
  resize:none; height:60px; outline:none;
  color:#1e293b; line-height:1.5;
  transition:border-color .15s; margin-bottom:14px;
}
.vs-textarea:focus { border-color:#0f2a44; }

/* Date row */
.vs-date-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px; }
.vs-input {
  width:100%; padding:8px 10px;
  border:1.5px solid #e2e8f0; border-radius:8px;
  font-size:13px; font-family:inherit; color:#1e293b;
  outline:none; transition:border-color .15s;
}
.vs-input:focus { border-color:#0f2a44; }

/* Quick pills */
.vs-quick { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.vs-qpill {
  padding:4px 10px;
  border:1.5px solid #e2e8f0; border-radius:12px;
  font-size:11px; font-weight:500; color:#64748b;
  cursor:pointer; background:#fff; transition:all .15s;
}
.vs-qpill:hover, .vs-qpill.active { border-color:#0f2a44; color:#0f2a44; background:#f0f4f8; }

/* Footer */
.vs-footer { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.vs-btn-now {
  padding:11px; background:linear-gradient(135deg,#10b981,#059669);
  color:#fff; border:none; border-radius:9px;
  font-size:13px; font-weight:700; cursor:pointer; transition:all .15s;
}
.vs-btn-now:hover { box-shadow:0 4px 12px rgba(16,185,129,0.3); }
.vs-btn-sched {
  padding:11px; background:linear-gradient(135deg,#0f2a44,#143b63);
  color:#fff; border:none; border-radius:9px;
  font-size:13px; font-weight:700; cursor:pointer; transition:all .15s;
}
.vs-btn-sched:hover { box-shadow:0 4px 12px rgba(15,42,68,0.3); }
.vs-btn-skip {
  grid-column:span 2; padding:8px;
  background:none; border:none; color:#94a3b8;
  font-size:12px; cursor:pointer; transition:color .15s;
}
.vs-btn-skip:hover { color:#64748b; }
.vs-warn { font-size:11px; color:#ef4444; margin-top:-10px; margin-bottom:10px; display:none; }

/* Confirm */
.vs-confirm { display:none; padding:24px 18px; text-align:center; }
.vs-confirm-icon { font-size:40px; margin-bottom:10px; }
.vs-confirm-title { font-size:17px; font-weight:700; color:#0f2a44; margin-bottom:4px; }
.vs-confirm-sub { font-size:12px; color:#64748b; margin-bottom:14px; }
.vs-confirm-pills { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin-bottom:16px; }
.vs-confirm-pill {
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

/* ── API DOC PANEL ── */
.doc-panel {
  width: 100%;
  max-width: 640px;
  background: #1e293b;
  border-radius: 12px;
  padding: 20px 24px;
  color: #e2e8f0;
  font-size: 13px;
  line-height: 1.7;
}
.doc-panel h2 { font-size: 14px; font-weight: 700; color: #5fd1ff; margin-bottom: 14px; letter-spacing: .05em; text-transform: uppercase; }
.doc-panel h3 { font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; margin: 14px 0 6px; }
.doc-panel code {
  display: block;
  background: #0f172a;
  border-radius: 6px;
  padding: 10px 14px;
  font-family: monospace;
  font-size: 12px;
  color: #86efac;
  white-space: pre;
  overflow-x: auto;
  margin: 6px 0;
}
.doc-panel .key { color: #7dd3fc; }
.doc-panel .val { color: #86efac; }
.doc-panel .comment { color: #475569; }
.field-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
.field-table th { text-align:left; color:#5fd1ff; padding:4px 8px; border-bottom:1px solid #334155; font-weight:600; }
.field-table td { padding:5px 8px; border-bottom:1px solid #1e293b; color:#cbd5e1; vertical-align:top; }
.field-table td:first-child { color:#7dd3fc; font-family:monospace; white-space:nowrap; }
.badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
.badge.req { background:#7f1d1d; color:#fca5a5; }
.badge.opt { background:#1e3a5f; color:#93c5fd; }
</style>
</head>
<body>

<div class="trigger-label">Scheduler Modal Test — Podcast ID: <strong><?= $podcast_id ?></strong></div>
<button class="trigger-btn" onclick="openSchedModal({ podcast_id: <?= $podcast_id ?>, title: 'Test Video #<?= $podcast_id ?>' })">
  📤 Open Publish Scheduler
</button>

<!-- ══ MODAL ══ -->
<div class="vs-overlay" id="vsOverlay">
  <div class="vs-modal">

    <div id="vsMain">
      <div class="vs-head">
        <div class="vs-head-left">
          <div class="vs-head-icon">📤</div>
          <div>
            <div class="vs-head-title">Publish Video</div>
            <div class="vs-head-sub" id="vsSubTitle">Choose where &amp; when to share</div>
          </div>
        </div>
        <button class="vs-close" onclick="closeSchedModal()">✕</button>
      </div>

      <div class="vs-saved">
        <div class="vs-saved-dot"></div>
        <span>Video saved — Podcast ID: <strong id="vsPodcastIdDisplay"><?= $podcast_id ?></strong></span>
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
        <div class="vs-warn" id="vsWarn">Select at least one platform</div>

        <div class="vs-lbl">Caption</div>
        <textarea class="vs-textarea" id="vsCaption" placeholder="Write a caption for this post…"></textarea>

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
          <button class="vs-btn-now"  onclick="vsPostNow()">⚡ Post Now</button>
          <button class="vs-btn-sched" onclick="vsSchedule()">🗓 Schedule</button>
          <button class="vs-btn-skip" onclick="closeSchedModal()">Skip — publish manually</button>
        </div>

      </div>
    </div>

    <div class="vs-confirm" id="vsConfirm">
      <div class="vs-confirm-icon"  id="vsConfirmIcon">🗓</div>
      <div class="vs-confirm-title" id="vsConfirmTitle">Scheduled!</div>
      <div class="vs-confirm-sub"   id="vsConfirmSub"></div>
      <div class="vs-confirm-pills" id="vsConfirmPills"></div>
      <button class="vs-confirm-done" onclick="closeSchedModal()">Done ✓</button>
    </div>

  </div>
</div>

<!-- ══ API DOCUMENTATION PANEL ══ -->
<div class="doc-panel">
  <h2>📋 Backend API Specification — social_schedule.php</h2>

  <h3>Endpoint</h3>
  <code>POST /social_schedule.php
Content-Type: application/json</code>

  <h3>Request Payload</h3>
  <code>{
  <span class="key">"podcast_id"</span>:  <span class="val">19</span>,               <span class="comment">// INT  — hdb_podcasts.id</span>
  <span class="key">"platforms"</span>:   <span class="val">["instagram","tiktok"]</span>, <span class="comment">// ARRAY — selected platforms</span>
  <span class="key">"caption"</span>:     <span class="val">"Your caption text"</span>,  <span class="comment">// STRING — post caption</span>
  <span class="key">"sched_date"</span>:  <span class="val">"2026-03-20"</span>,         <span class="comment">// STRING — Y-m-d</span>
  <span class="key">"sched_time"</span>:  <span class="val">"09:00"</span>,             <span class="comment">// STRING — H:i</span>
  <span class="key">"post_type"</span>:   <span class="val">"scheduled"</span>           <span class="comment">// STRING — "now" or "scheduled"</span>
}</code>

  <h3>Fields</h3>
  <table class="field-table">
    <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
    <tr><td>podcast_id</td><td>int</td><td><span class="badge req">required</span></td><td>Links to hdb_podcasts.id</td></tr>
    <tr><td>platforms</td><td>array</td><td><span class="badge req">required</span></td><td>instagram, tiktok, youtube, facebook, twitter, linkedin</td></tr>
    <tr><td>caption</td><td>string</td><td><span class="badge opt">optional</span></td><td>Saved to hdb_podcasts.caption_text</td></tr>
    <tr><td>sched_date</td><td>string</td><td><span class="badge req">required</span></td><td>Y-m-d format. Saved to hdb_podcasts.schedule_date</td></tr>
    <tr><td>sched_time</td><td>string</td><td><span class="badge req">required</span></td><td>H:i format. Saved to hdb_podcasts.schedule_time</td></tr>
    <tr><td>post_type</td><td>string</td><td><span class="badge req">required</span></td><td>"now" = post immediately, "scheduled" = post at date/time</td></tr>
  </table>

  <h3>Database — UPDATE hdb_podcasts</h3>
  <code>UPDATE hdb_podcasts SET
  caption_text      = ?,          <span class="comment">-- caption field</span>
  schedule_date     = ?,          <span class="comment">-- sched_date</span>
  schedule_time     = ?,          <span class="comment">-- sched_time</span>
  video_status      = ?,          <span class="comment">-- "posting" if now, "scheduled" if future</span>
  instagram_status  = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  tiktok_status     = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  youtube_status    = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  facebook_status   = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  twitter_status    = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  linkedin_status   = ?,          <span class="comment">-- "pending" if selected, "skip" if not</span>
  updated_at        = NOW()
WHERE id = ?                      <span class="comment">-- podcast_id</span></code>

  <h3>Success Response</h3>
  <code>{
  <span class="key">"success"</span>:      <span class="val">true</span>,
  <span class="key">"podcast_id"</span>:   <span class="val">19</span>,
  <span class="key">"video_status"</span>: <span class="val">"scheduled"</span>,
  <span class="key">"platforms"</span>:    <span class="val">["instagram","tiktok"]</span>,
  <span class="key">"schedule_date"</span>:<span class="val">"2026-03-20"</span>,
  <span class="key">"schedule_time"</span>:<span class="val">"09:00"</span>
}</code>

  <h3>Error Response</h3>
  <code>{
  <span class="key">"success"</span>: <span class="val">false</span>,
  <span class="key">"error"</span>:   <span class="val">"Missing podcast_id"</span>
}</code>

  <h3>Cron Job — picks up scheduled posts</h3>
  <code>SELECT * FROM hdb_podcasts
WHERE video_status = 'scheduled'
AND CONCAT(schedule_date, ' ', schedule_time) &lt;= NOW()
AND (instagram_status = 'pending'
  OR tiktok_status    = 'pending'
  OR youtube_status   = 'pending')</code>
</div>

<script>
function openSchedModal(opts) {
  opts = opts || {};
  window._vsPodcastId = opts.podcast_id || null;
  document.getElementById('vsSubTitle').textContent      = opts.title || 'Your Video';
  document.getElementById('vsPodcastIdDisplay').textContent = opts.podcast_id || '—';
  document.getElementById('vsMain').style.display    = 'block';
  document.getElementById('vsConfirm').style.display = 'none';
  vsQuick(document.querySelectorAll('.vs-qpill')[2], 24);
  document.getElementById('vsOverlay').classList.add('open');
}

function closeSchedModal() {
  document.getElementById('vsOverlay').classList.remove('open');
}

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

function vsPostNow() {
  const plats = vsGetPlats();
  if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
  vsSave('now', plats, null);
}

function vsSchedule() {
  const plats = vsGetPlats();
  if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
  const date = document.getElementById('vsDate').value;
  const time = document.getElementById('vsTime').value;
  if (!date || !time) { alert('Please select a date and time'); return; }
  vsSave('scheduled', plats, new Date(date + 'T' + time));
}

function vsSave(type, plats, dt) {
  const payload = {
    podcast_id: window._vsPodcastId,
    platforms:  plats,
    caption:    document.getElementById('vsCaption').value,
    sched_date: dt ? dt.toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
    sched_time: dt ? dt.toTimeString().slice(0,5)   : new Date().toTimeString().slice(0,5),
    post_type:  type
  };

  console.log('📤 Payload to social_schedule.php:', JSON.stringify(payload, null, 2));

  fetch('social_schedule.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(d => {
    console.log('✅ Response:', d);
    if (d.success) vsShowConfirm(type, plats, dt);
    else alert('Backend error: ' + (d.error || 'Unknown'));
  })
  .catch(err => {
    console.warn('⚠️ social_schedule.php not yet wired — showing confirm anyway');
    vsShowConfirm(type, plats, dt);
  });
}

function vsShowConfirm(type, plats, dt) {
  document.getElementById('vsMain').style.display    = 'none';
  document.getElementById('vsConfirm').style.display = 'block';
  const labels = { instagram:'📸 Instagram', tiktok:'🎵 TikTok', youtube:'▶️ YouTube', facebook:'📘 Facebook', twitter:'🐦 X', linkedin:'💼 LinkedIn' };
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
  document.getElementById('vsConfirmPills').innerHTML = plats.map(p=>`<span class="vs-confirm-pill">${labels[p]||p}</span>`).join('');
}
</script>

</body>
</html>

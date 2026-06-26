<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Script Wizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Reset & Base ── */
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --purple:    #8b5cf6;
  --purple-lt: #ede9fe;
  --green:     #10b981;
  --orange:    #f59e0b;
  --orange-lt: #fef3c7;
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --card:      #ffffff;
  --shadow:    0 4px 12px rgba(0,0,0,0.08);
}
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; }

/* ── Header ── */
.vidora-header { display:flex; justify-content:space-between; align-items:center; padding:12px 20px; background:linear-gradient(90deg,#0f2a44,#143b63); color:#fff; box-shadow:0 3px 10px rgba(0,0,0,0.15); position:sticky; top:0; z-index:1000; }
.brand-link { text-decoration:none; display:flex; align-items:center; gap:8px; }
.brand-icon { font-size:24px; }
.brand-name { font-size:18px; font-weight:700; }
.brand-video { color:#fff; }
.brand-vizard { color:#5fd1ff; }

/* ── Page ── */
.page-wrap { flex:1; display:flex; align-items:flex-start; justify-content:center; padding:28px 16px 48px; }

/* ── Card ── */
.wiz-card { background:var(--card); border-radius:16px; border:1px solid var(--border); box-shadow:var(--shadow); width:100%; max-width:600px; overflow:hidden; }
.wiz-card-header { padding:18px 24px 16px; background:linear-gradient(135deg,#f8fafc,#f1f5f9); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:12px; }
.wiz-card-header h1 { font-size:20px; font-weight:700; color:var(--dark-blue); margin:0; }
.wiz-card-header p { font-size:13px; color:var(--muted); margin:2px 0 0; }
.gear-btn { width:36px; height:36px; border-radius:50%; border:1px solid var(--border); background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:17px; color:var(--muted); transition:all .15s; flex-shrink:0; }
.gear-btn:hover { background:var(--purple-lt); border-color:var(--purple); color:var(--purple); }
.wiz-card-body { padding:24px; }

/* ── Settings bar ── */
.settings-bar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#f7f9fc; border:1px solid var(--border); border-radius:8px; padding:8px 12px; margin-bottom:20px; cursor:pointer; transition:border-color .15s; }
.settings-bar:hover { border-color:var(--purple); }
.settings-bar-label { font-size:11px; font-weight:700; color:#aaa; text-transform:uppercase; letter-spacing:.06em; margin-right:2px; white-space:nowrap; }
.settings-bar-edit { font-size:11px; color:var(--purple); margin-left:auto; white-space:nowrap; }
.s-pill { font-size:11px; background:var(--purple-lt); color:#6d28d9; border-radius:4px; padding:2px 7px; white-space:nowrap; }

/* ── Progress ── */
.prog-track { height:4px; background:var(--border); border-radius:2px; margin-bottom:24px; overflow:hidden; }
.prog-fill { height:100%; background:linear-gradient(90deg,var(--dark-blue),var(--purple)); border-radius:2px; transition:width .4s ease; }

/* ── Step meta ── */
.step-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:6px; }
.step-q { font-size:20px; font-weight:700; color:var(--dark-blue); margin-bottom:18px; line-height:1.35; }

/* ── Pills ── */
.opts { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.opt { padding:9px 16px; border:1.5px solid var(--border); border-radius:8px; background:#fff; color:var(--text); font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; line-height:1; }
.opt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.opt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.opt.multi-sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }

/* ── More button ── */
.more-btn { display:inline-flex; align-items:center; gap:5px; padding:10px 16px; background:#fff; border:1.5px dashed var(--purple); border-radius:10px; color:var(--purple); font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.more-btn:hover { background:var(--purple-lt); border-style:solid; }
.more-btn:disabled { opacity:.5; cursor:not-allowed; }
.more-btn .spin { display:inline-block; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── Custom add ── */
.custom-row { display:flex; gap:8px; margin-bottom:6px; }
.custom-in { flex:1; padding:9px 12px; font-size:13px; border:1.5px solid var(--border); border-radius:8px; color:var(--text); outline:none; transition:border-color .15s; background:#fff; }
.custom-in:focus { border-color:var(--purple); }
.custom-add { padding:9px 14px; font-size:13px; background:#f5f5f5; border:1.5px solid var(--border); border-radius:8px; color:var(--muted); cursor:pointer; white-space:nowrap; transition:all .15s; }
.custom-add:hover { background:var(--purple-lt); color:var(--purple); border-color:var(--purple); }

/* ── Loading ── */
.loading { display:flex; align-items:center; gap:10px; color:var(--muted); font-size:14px; padding:16px 0; }
.dot { width:6px; height:6px; border-radius:50%; background:var(--purple); animation:blink 1.2s ease-in-out infinite; }
.dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }

/* ── Nav ── */
.nav { display:flex; align-items:center; justify-content:space-between; margin-top:24px; padding-top:20px; border-top:1px solid #f0f0f0; }
.nav-back { font-size:13px; color:var(--muted); cursor:pointer; padding:8px 0; background:none; border:none; transition:color .15s; }
.nav-back:hover { color:var(--text); }
.nav-next { padding:11px 28px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; transition:all .15s; }
.nav-next:hover { background:linear-gradient(135deg,var(--mid-blue),#1e4a7a); box-shadow:0 4px 12px rgba(15,42,68,.3); }
.nav-next:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }

/* ── Summary ── */
.summary { background:#f7f9fc; border:1px solid var(--border); border-radius:12px; padding:16px 20px; margin-top:4px; }
.sum-section { font-size:10px; font-weight:700; color:#bbb; text-transform:uppercase; letter-spacing:.07em; margin:14px 0 6px; }
.sum-section:first-child { margin-top:0; }
.sum-row { display:flex; justify-content:space-between; align-items:flex-start; padding:7px 0; border-bottom:1px solid #eef0f3; font-size:13px; gap:16px; }
.sum-row:last-child { border-bottom:none; }
.sum-key { color:var(--muted); white-space:nowrap; }
.sum-val { color:var(--dark-blue); font-weight:600; text-align:right; }
.done-title { font-size:22px; font-weight:700; color:var(--dark-blue); margin-bottom:6px; }
.done-sub { font-size:13px; color:var(--muted); margin-bottom:18px; }
.gen-btn { margin-top:16px; width:100%; padding:14px; background:linear-gradient(135deg,var(--green),#059669); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; transition:all .15s; }
.gen-btn:hover { background:linear-gradient(135deg,#059669,#047857); box-shadow:0 4px 12px rgba(16,185,129,.3); }
.gen-btn:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }
.restart-btn { margin-top:10px; width:100%; padding:11px; background:#fff; color:var(--muted); border:1.5px solid var(--border); border-radius:10px; font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; }
.restart-btn:hover { border-color:var(--purple); color:var(--purple); }
.script-box { background:#f7f9fc; border:1px solid var(--border); border-radius:10px; padding:16px 20px; font-size:14px; line-height:1.8; color:var(--text); white-space:pre-wrap; margin-top:4px; }
.copy-btn { margin-top:8px; width:100%; padding:10px; background:#fff; color:var(--purple); border:1.5px solid var(--purple); border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; }
.copy-btn:hover { background:var(--purple-lt); }

/* ── Settings overlay ── */
.settings-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:200; align-items:center; justify-content:center; padding:20px; }
.settings-overlay.open { display:flex; }
.settings-panel { background:#fff; border-radius:16px; padding:28px; width:100%; max-width:500px; max-height:90vh; overflow-y:auto; box-shadow:0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.settings-title { font-size:17px; font-weight:700; color:var(--dark-blue); }
.settings-close { background:none; border:none; font-size:22px; color:var(--muted); cursor:pointer; padding:0 4px; }
.settings-close:hover { color:var(--text); }
.setting-group { margin-bottom:20px; }
.setting-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:8px; }
.setting-opts { display:flex; flex-wrap:wrap; gap:7px; }
.sopt { padding:7px 13px; border:1.5px solid var(--border); border-radius:7px; background:#fff; color:var(--text); font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.sopt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.sopt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.settings-save { margin-top:8px; width:100%; padding:13px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
.settings-hint { font-size:12px; color:#bbb; margin-top:10px; text-align:center; }

/* ── Mode cards ── */
.mode-card { flex:1; min-width:180px; border:1.5px solid var(--border); border-radius:16px; padding:22px 18px; background:var(--card); cursor:pointer; transition:all .2s; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
.mode-card:hover { border-color:var(--purple); box-shadow:0 6px 20px rgba(139,92,246,0.12); transform:translateY(-2px); }
.mode-card-icon { font-size:32px; margin-bottom:10px; }
.mode-card-title { font-size:15px; font-weight:700; color:var(--dark-blue); margin-bottom:8px; }
.mode-card-desc { font-size:13px; color:var(--muted); line-height:1.5; margin-bottom:12px; }
.mode-card-badge { display:inline-block; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; }
.back-to-menu { background:none; border:none; color:var(--muted); font-size:13px; font-weight:600; cursor:pointer; padding:0; display:inline-flex; align-items:center; gap:4px; transition:color .15s; }
.back-to-menu:hover { color:var(--dark-blue); }
.field-label { display:block; font-size:13px; font-weight:600; color:var(--dark-blue); margin-bottom:6px; }

/* ── Campaign-specific ── */
.camp-progress-bar {
  background: linear-gradient(135deg, #fef3c7, #fffbeb);
  border: 1px solid #fde68a;
  border-radius: 10px;
  padding: 12px 16px;
  margin-bottom: 20px;
  font-size: 13px;
  color: #92400e;
}
.camp-progress-bar strong { color: #78350f; }

/* Title grid for campaign title selection */
.title-grid { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; max-height:320px; overflow-y:auto; padding-right:4px; }
.title-item {
  display:flex; align-items:flex-start; gap:10px;
  padding:10px 14px;
  border:1.5px solid var(--border);
  border-radius:10px;
  background:#fff;
  cursor:pointer;
  transition:all .15s;
  font-size:13px;
  line-height:1.4;
}
.title-item:hover { border-color:var(--purple); background:var(--purple-lt); }
.title-item.sel { border-color:var(--purple); background:var(--purple-lt); color:#5b21b6; font-weight:600; }
.title-item .chk { width:18px; height:18px; border:2px solid var(--border); border-radius:4px; flex-shrink:0; margin-top:1px; display:flex; align-items:center; justify-content:center; font-size:11px; transition:all .15s; }
.title-item.sel .chk { background:var(--purple); border-color:var(--purple); color:#fff; }
.title-count-badge { display:inline-flex; align-items:center; gap:6px; background:var(--orange-lt); color:#92400e; border:1px solid #fde68a; border-radius:20px; padding:4px 12px; font-size:12px; font-weight:600; margin-bottom:12px; }

/* ── Campaign results dashboard ── */
.campaign-result-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 12px;
}
.campaign-result-header {
  padding: 12px 16px;
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  cursor: pointer;
}
.campaign-result-header:hover { background: var(--purple-lt); }
.campaign-result-body { padding: 14px 16px; display: none; }
.campaign-result-body.open { display: block; }
.lang-tab-bar { display:flex; gap:6px; margin-bottom:12px; }
.lang-tab { padding:5px 14px; border:1.5px solid var(--border); border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; background:#fff; color:var(--muted); transition:all .15s; }
.lang-tab.active { background:var(--dark-blue); color:#fff; border-color:var(--dark-blue); }
.script-scene { background:#f7f9fc; border-radius:8px; padding:10px 14px; margin-bottom:8px; font-size:13px; line-height:1.6; color:var(--text); white-space:pre-wrap; }

/* ── Toast ── */
.toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--dark-blue); color:#fff; padding:10px 22px; border-radius:10px; font-size:13px; font-weight:600; z-index:999; transition:opacity .3s; pointer-events:none; }

/* ── Footer ── */
.site-footer { background:linear-gradient(90deg,#0f2a44,#143b63); color:rgba(255,255,255,.5); padding:14px 20px; font-size:12px; display:flex; justify-content:center; align-items:center; gap:24px; flex-wrap:wrap; }
.site-footer a { color:rgba(255,255,255,.55); text-decoration:none; transition:color .2s; }
.site-footer a:hover { color:var(--accent); }
.footer-brand { font-weight:700; color:var(--accent); }

/* number input style */
.num-input { width:100%; padding:10px 12px; font-size:14px; border:1.5px solid var(--border); border-radius:8px; outline:none; transition:border-color .15s; }
.num-input:focus { border-color:var(--purple); }

/* ── Step 2 Build Video ── */
.build-btn { margin-top:10px; width:100%; padding:14px; background:linear-gradient(135deg,#7c3aed,#5b21b6); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; transition:all .15s; }
.build-btn:hover { background:linear-gradient(135deg,#6d28d9,#4c1d95); box-shadow:0 4px 12px rgba(109,40,217,.35); }
.build-btn:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }

/* Step 2 Modal */
.s2-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:500; align-items:center; justify-content:center; padding:20px; }
.s2-overlay.open { display:flex; }
.s2-panel { background:#fff; border-radius:16px; width:100%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 12px 40px rgba(0,0,0,0.25); }
.s2-header { padding:18px 20px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.s2-header h2 { font-size:17px; font-weight:700; color:var(--dark-blue); margin:0; }
.s2-body { padding:20px; }
.s2-section { margin-bottom:18px; }
.s2-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:8px; }
.s2-select { width:100%; padding:9px 12px; font-size:13px; border:1.5px solid var(--border); border-radius:8px; background:#fff; color:var(--text); outline:none; transition:border-color .15s; }
.s2-select:focus { border-color:var(--purple); }
.s2-media-opts { display:flex; gap:8px; flex-wrap:wrap; }
.s2-media-opt { padding:8px 14px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.s2-media-opt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.s2-media-opt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.s2-start-btn { width:100%; padding:13px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; transition:all .15s; margin-top:4px; }
.s2-start-btn:hover { box-shadow:0 4px 12px rgba(15,42,68,.3); }
.s2-close { background:none; border:none; font-size:22px; color:var(--muted); cursor:pointer; }

/* Progress steps */
.s2-steps { display:flex; flex-direction:column; gap:10px; margin:16px 0; }
.s2-step { display:flex; align-items:flex-start; gap:12px; padding:12px 14px; border:1px solid var(--border); border-radius:10px; background:#f8fafc; }
.s2-step.active { border-color:var(--purple); background:var(--purple-lt); }
.s2-step.done { border-color:var(--green); background:#f0fdf4; }
.s2-step.error { border-color:#fca5a5; background:#fef2f2; }
.s2-step-icon { font-size:20px; flex-shrink:0; margin-top:1px; }
.s2-step-title { font-size:13px; font-weight:600; color:var(--dark-blue); }
.s2-step-sub { font-size:12px; color:var(--muted); margin-top:2px; }
.s2-log { background:#0f2a44; border-radius:8px; padding:12px; max-height:180px; overflow-y:auto; font-family:monospace; font-size:11px; line-height:1.6; margin-top:12px; }
.s2-log-line { margin:0; }
.s2-log-line.info  { color:#7dd3fc; }
.s2-log-line.success { color:#86efac; }
.s2-log-line.warning { color:#fde68a; }
.s2-log-line.error   { color:#fca5a5; }
.s2-done-bar { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:14px 18px; margin-top:12px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.s2-done-bar a { padding:9px 20px; background:linear-gradient(135deg,var(--green),#059669); color:#fff; border-radius:8px; text-decoration:none; font-size:13px; font-weight:700; }
</style>
</head>
<body>

<!-- Header -->
<header class="vidora-header">
  <a class="brand-link" href="index.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
</header>

<!-- Settings overlay (shared) -->
<div class="settings-overlay" id="settingsOverlay" onclick="overlayClick(event)">
  <div class="settings-panel" id="settingsPanel">
    <div class="settings-header">
      <span class="settings-title">⚙️ Video Settings</span>
      <button class="settings-close" onclick="closeSettings()">✕</button>
    </div>
    <div class="setting-group">
      <div class="setting-label">Language</div>
      <div class="setting-opts" id="s-language"></div>
    </div>
    <div class="setting-group">
      <div class="setting-label">Reel Type</div>
      <div class="setting-opts" id="s-reel_type"></div>
    </div>
    <div class="setting-group">
      <div class="setting-label">Video Format</div>
      <div class="setting-opts" id="s-format"></div>
    </div>
    <div class="setting-group">
      <div class="setting-label">Objective</div>
      <div class="setting-opts" id="s-objective"></div>
    </div>
    <div class="setting-group">
      <div class="setting-label">Target Audience</div>
      <div class="setting-opts" id="s-audience"></div>
    </div>
    <button class="settings-save" onclick="saveSettings()">Save Settings</button>
    <div class="settings-hint">Saved for future sessions</div>
  </div>
</div>

<!-- Main -->
<div class="page-wrap">

  <!-- ══ MODE SELECT ══ -->
  <div id="modeSelect" style="width:100%; max-width:700px;">
    <div style="text-align:center; margin-bottom:24px;">
      <h1 style="font-size:26px; font-weight:800; color:var(--dark-blue); margin-bottom:6px;">🎬 VideoVizard</h1>
      <p style="font-size:14px; color:var(--muted);">How would you like to create your video script?</p>
    </div>
    <div style="display:flex; gap:16px; flex-wrap:wrap;">

      <div class="mode-card" onclick="selectMode('wizard')">
        <div class="mode-card-icon">✨</div>
        <div class="mode-card-title">Generate Video Script</div>
        <div class="mode-card-desc">Answer a few questions — AI writes a complete, ready-to-use video script for you.</div>
        <div class="mode-card-badge" style="background:#ede9fe; color:#6d28d9;">Best for new ideas</div>
      </div>

      <div class="mode-card" onclick="selectMode('campaign')">
        <div class="mode-card-icon">📅</div>
        <div class="mode-card-title">Generate Campaign</div>
        <div class="mode-card-desc">Plan a week, month or custom period. AI generates multiple scripts at once — one step, full content calendar.</div>
        <div class="mode-card-badge" style="background:#fef3c7; color:#92400e;">Best for content planning</div>
      </div>

      <div class="mode-card" onclick="selectMode('content')">
        <div class="mode-card-icon">📄</div>
        <div class="mode-card-title">I Have Content</div>
        <div class="mode-card-desc">Paste your own text or script — it gets formatted and split into scenes automatically.</div>
        <div class="mode-card-badge" style="background:#dbeafe; color:#1e40af;">Best for existing content</div>
      </div>

    </div>
  </div>

  <!-- ══ MODE: SINGLE SCRIPT WIZARD ══ -->
  <div id="modeWizard" style="display:none; width:100%; max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div>
          <h1 id="cardTitle">✨ Generate Video Script</h1>
          <p id="cardSubtitle">Answer a few questions to generate your video script</p>
        </div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:16px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
        </div>
        <div class="settings-bar" id="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="settings-bar-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div class="prog-track"><div class="prog-fill" id="prog"></div></div>
        <div id="step-label" class="step-label"></div>
        <div id="step-q" class="step-q"></div>
        <div id="step-body"></div>
        <div class="nav" id="nav-bar">
          <button class="nav-back" id="backBtn" onclick="goBack()">← Back</button>
          <button class="nav-next" id="nextBtn" disabled onclick="goNext()">Continue →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ MODE: CAMPAIGN WIZARD ══ -->
  <div id="modeCampaign" style="display:none; width:100%; max-width:640px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div>
          <h1 id="campCardTitle">📅 Generate Campaign</h1>
          <p id="campCardSubtitle">Build your full content calendar in one go</p>
        </div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:16px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
        </div>
        <div class="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="camp-settings-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <div class="prog-track"><div class="prog-fill" id="camp-prog"></div></div>
        <div id="camp-step-label" class="step-label"></div>
        <div id="camp-step-q" class="step-q"></div>
        <div id="camp-step-body"></div>

        <div class="nav" id="camp-nav-bar">
          <button class="nav-back" id="campBackBtn" onclick="campGoBack()">← Back</button>
          <button class="nav-next" id="campNextBtn" disabled onclick="campGoNext()">Continue →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ MODE: I HAVE CONTENT ══ -->
  <div id="modeContent" style="display:none; width:100%; max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div>
          <h1>📄 I Have Content</h1>
          <p>Paste your script — AI formats it into scenes</p>
        </div>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:20px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
        </div>
        <div class="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="content-settings-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Video Title</label>
          <input type="text" id="content-title" placeholder="e.g. 5 Ways to Reduce Stress" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; outline:none;">
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Your Script / Story</label>
          <textarea id="content-script" rows="8" placeholder="Paste your script, blog post, or story here…&#10;&#10;For podcast format, start lines with Host: or Guest:" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; resize:vertical; outline:none; line-height:1.6;"></textarea>
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Call to Action</label>
          <input type="text" id="content-cta" placeholder="e.g. Follow for more tips" value="Follow for more tips" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; outline:none;">
        </div>
        <div id="content-script-output"></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:4px;">
          <button class="nav-next" id="content-process-btn" onclick="processMyContent()" style="flex:1; min-width:160px;">📝 Process Content</button>
        </div>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

<!-- Footer -->
<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="wizard.php">Script Generator</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<script>
/* ═══════════════════════════════════════════
   CONSTANTS
═══════════════════════════════════════════ */
const AZURE_BREAK = '<break time="200ms"/>';
const SCENE_SEPARATOR = '[SCENE BREAK]';

/* ═══════════════════════════════════════════
   SCENE BREAK ENFORCER
   Guarantees every scene line ends with the
   Azure TTS pause tag — runs on every script
   returned from the server regardless of mode.
═══════════════════════════════════════════ */
// Split any script format into one-sentence-per-line before enforcing break tags.
// Handles: [SCENE BREAK] delimited, newline-delimited, or one-paragraph with spaces.
function splitIntoScenes(script) {
  if (!script) return '';

  // Normalise: replace all whitespace variants (nbsp, tab, multiple spaces) with single space
  let s = script.replace(/[\u00a0\t]+/g, ' ');

  // If already has [SCENE BREAK] markers — split on those
  if (s.includes(SCENE_SEPARATOR)) {
    return s.split(SCENE_SEPARATOR).map(x => x.trim()).filter(Boolean).join('\n');
  }

  // If already has newlines — split on those
  if (s.includes('\n')) {
    return s.split('\n').map(x => x.trim()).filter(Boolean).join('\n');
  }

  // One paragraph — split after every sentence-ending punctuation mark
  // Works regardless of how many spaces follow the punctuation
  const delim = '\x00';
  s = s
    .replace(/\.\s+/g,  '.' + delim)
    .replace(/!\s+/g,    '!' + delim)
    .replace(/\?\s+/g,   '?' + delim);

  return s.split(delim).map(x => x.trim()).filter(Boolean).join('\n');
}

function enforceSceneBreaks(script) {
  if (!script) return '';
  // By this point splitIntoScenes() has already normalised to \n-separated lines
  return script
    .split('\n')
    .map(scene => {
      const trimmed = scene.trim();
      if (!trimmed) return '';
      const cleaned = trimmed.replace(/<break[^/]*\/>/gi, '').trimEnd();
      return cleaned + ' ' + AZURE_BREAK;
    })
    .filter(Boolean)
    .join('\n');
}

/* ═══════════════════════════════════════════
   SINGLE SCRIPT WIZARD STEPS
═══════════════════════════════════════════ */
const STEPS = [
  {
    key:'niche', label:'Step 1 of 6', q:'Select your niche or profession',
    type:'opts+more',
    opts:['Hypnotherapy','Real Estate','Hair Dressing','Nail Parlour','Financial Adviser',
          'Physiotherapist','Life Coaching','Personal Training','Dentistry','Mortgage Broker'],
    morePrompt:(existing) =>
      `You are a content strategy expert. List 10 MORE professional niche ideas for short social media videos.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of short niche name strings.
       Example: ["Nutritionist","Yoga Instructor","Chiropractor"]`
  },
  {
    key:'topic', label:'Step 2 of 6', q:'Select a category',
    type:'ai', aiUrl:'generate_categories.php', aiPayload:['niche'], resKey:'categories',
    morePrompt:(existing,ans) =>
      `You are a content strategy expert for the niche: "${ans.niche}".
       List 8 MORE broad content categories for short social media videos in this niche.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of category name strings.`
  },
  {
    key:'title', label:'Step 3 of 6', q:'Choose a video idea',
    type:'ai', aiUrl:'generate_titles.php', aiPayload:['niche','topic'], resKey:'titles',
    morePrompt:(existing,ans) =>
      `You are an expert short-form video content creator for the niche "${ans.niche}", category "${ans.topic}".
       Generate 8 MORE specific, engaging video topic ideas for Reels/TikTok/Shorts.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of title strings.`
  },
  {
    key:'angle', label:'Step 4 of 6', q:'What hook or angle will you use?',
    type:'opts+more',
    opts:['Quick Hacks','Step-by-Step','Common Mistakes','Surprising Secrets','Before & After',
          'Myth Busting','Did You Know?','Storytime','Controversial Opinion','Top 5 List',
          'FAQs Answered','Behind the Scenes','Client Transformation',
          'Warning / What to Avoid','Industry Trends'],
    morePrompt:(existing) =>
      `You are an expert short-form video content strategist.
       List 10 MORE creative hook or angle ideas for short social media videos.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of short hook/angle name strings.`
  },
  { key:'duration', label:'Step 5 of 6', q:'How long is this video?', type:'opts', opts:['15 seconds','30 seconds','60 seconds','90 seconds'] },
  { key:'cta', label:'Step 6 of 6', q:'What should viewers do next?', type:'opts', opts:['Follow for More','Subscribe','Book a Free Call','Visit Website','Download Guide'] },
];
const WIZARD_LABELS = { niche:'Niche', topic:'Category', title:'Video Idea', angle:'Angle / Hook', duration:'Duration', cta:'Call to Action' };

/* ═══════════════════════════════════════════
   CAMPAIGN WIZARD STEPS
═══════════════════════════════════════════ */
const CAMP_STEPS = [
  {
    key:'camp_goal', label:'Campaign Step 1 of 8', q:'What is your campaign goal?',
    type:'opts',
    opts:['Brand Awareness','Lead Generation','Product Launch','Education & Tips',
          'Community Building','Sales & Promotions','Event Promotion','Trust Building']
  },
  {
    key:'camp_niche', label:'Campaign Step 2 of 8', q:'Select your niche or profession',
    type:'opts+more',
    opts:['Hypnotherapy','Real Estate','Hair Dressing','Nail Parlour','Financial Adviser',
          'Physiotherapist','Life Coaching','Personal Training','Dentistry','Mortgage Broker'],
    morePrompt:(existing) =>
      `List 10 MORE professional niche ideas for social media video campaigns.
       Do NOT repeat: ${existing.join(', ')}.
       Return ONLY a valid JSON array of strings.`
  },
  {
    key:'camp_category', label:'Campaign Step 3 of 8', q:'Select a content category',
    type:'ai', aiUrl:'generate_categories.php', aiPayload:['camp_niche'], resKey:'categories',
    payloadMap:{ camp_niche:'niche' },
    morePrompt:(existing,ans) =>
      `Content categories for niche "${ans.camp_niche}".
       List 8 MORE. Do NOT repeat: ${existing.join(', ')}.
       Return ONLY a valid JSON array.`
  },
  {
    key:'camp_languages', label:'Campaign Step 4 of 8', q:'Which languages will you post in?',
    type:'multi',
    opts:['English','Urdu','Arabic','Hindi','Spanish','French','Punjabi','Portuguese']
  },
  {
    key:'camp_duration', label:'Campaign Step 5 of 8', q:'How long is your campaign?',
    type:'opts',
    opts:['1 Week (7 days)','2 Weeks (14 days)','1 Month (30 days)','3 Months (90 days)','Custom']
  },
  {
    key:'camp_posts_per_day', label:'Campaign Step 6 of 8', q:'How often will you post?',
    type:'opts',
    opts:['1 post every 3 days','1 post every 2 days','1 post per day','2 posts per day','3 posts per day']
  },
  {
    key:'camp_video_length', label:'Campaign Step 7 of 8', q:'How long is each video?',
    type:'opts',
    opts:['30 seconds','60 seconds','90 seconds','2 minutes']
  },
  {
    key:'camp_titles', label:'Campaign Step 8 of 8', q:'Select the video titles for your campaign',
    type:'title-select',
    hint:'AI will generate title suggestions based on your niche and category. Select as many as you need.'
  }
];

/* ═══════════════════════════════════════════
   SETTINGS
═══════════════════════════════════════════ */
const SETTING_DEFS = {
  language:  { opts:['English','Spanish','French','Arabic','Hindi','Urdu','Punjabi','Portuguese','Mandarin','German'], def:'English' },
  reel_type: { opts:['Standard (Talking Head)','B-Roll (Voiceover)','Podcast Style'], def:'Standard (Talking Head)' },
  format:    { opts:['9:16 Vertical (Reels / TikTok / Shorts)','1:1 Square (Feed)','16:9 Landscape (YouTube)'], def:'9:16 Vertical (Reels / TikTok / Shorts)' },
  objective: { opts:['Educate','Inspire','Entertain','Inform','Build Trust'], def:'Educate' },
  audience:  { opts:['Complete Beginners','Intermediate Learners','Professionals','General Public','Business Owners'], def:'General Public' },
};
const SETTING_LABELS = { language:'Language', reel_type:'Reel Type', format:'Format', objective:'Objective', audience:'Audience' };

let settings = {}, cur = 0, ans = {}, stepOpts = {};
let campCur = 0, campAns = {}, campStepOpts = {}, campSelectedTitles = [];

/* ── Settings helpers ── */
function loadSettings() {
  try { settings = JSON.parse(localStorage.getItem('vw_settings')||'{}'); } catch(e){ settings={}; }
  Object.keys(SETTING_DEFS).forEach(k => { if (!settings[k]) settings[k] = SETTING_DEFS[k].def; });
  renderSettingsBar(); renderCampSettingsPills(); renderContentSettingsPills();
}
function renderSettingsBar() {
  const short = { language:settings.language, reel_type:settings.reel_type.split(' (')[0], format:settings.format.split(' ')[0], objective:settings.objective, audience:settings.audience.replace(' Learners','').replace('Complete ','').replace('Business ','Biz ') };
  const html = Object.values(short).map(v=>`<span class="s-pill">${v}</span>`).join('');
  const el = document.getElementById('settings-bar-pills'); if(el) el.innerHTML = html;
  renderCampSettingsPills(); renderContentSettingsPills();
}
function renderCampSettingsPills() {
  const el = document.getElementById('camp-settings-pills'); if(!el) return;
  const short = { language:settings.language, reel_type:settings.reel_type.split(' (')[0], format:settings.format.split(' ')[0] };
  el.innerHTML = Object.values(short).map(v=>`<span class="s-pill">${v}</span>`).join('');
}
function openSettings() {
  Object.entries(SETTING_DEFS).forEach(([k,def]) => {
    document.getElementById('s-'+k).innerHTML = def.opts.map(o=>
      `<div class="sopt${settings[k]===o?' sel':''}" data-v="${o}" onclick="selectSopt(this,'${k}')">${o}</div>`
    ).join('');
  });
  document.getElementById('settingsOverlay').classList.add('open');
}
function selectSopt(el,key) { document.querySelectorAll('#s-'+key+' .sopt').forEach(x=>x.classList.remove('sel')); el.classList.add('sel'); }
function saveSettings() {
  Object.keys(SETTING_DEFS).forEach(k=>{ const s=document.querySelector('#s-'+k+' .sopt.sel'); if(s) settings[k]=s.dataset.v; });
  localStorage.setItem('vw_settings',JSON.stringify(settings));
  closeSettings(); renderSettingsBar(); showToast('Settings saved ✓');
}
function closeSettings() { document.getElementById('settingsOverlay').classList.remove('open'); }
function overlayClick(e) { if(e.target===document.getElementById('settingsOverlay')) closeSettings(); }
function showToast(msg) {
  const t=Object.assign(document.createElement('div'),{className:'toast',textContent:msg});
  document.body.appendChild(t);
  setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),400); },1800);
}

/* ═══════════════════════════════════════════
   SINGLE WIZARD ENGINE
═══════════════════════════════════════════ */
function setNext(v){ document.getElementById('nextBtn').disabled=!v; }
function setBack(){ document.getElementById('backBtn').style.visibility=cur===0?'hidden':'visible'; }

async function render(){
  document.getElementById('prog').style.width=Math.round((cur/STEPS.length)*100)+'%';
  setBack(); setNext(false);
  const s=STEPS[cur];
  document.getElementById('step-label').textContent=s.label;
  document.getElementById('step-q').textContent=s.q;
  updateCardSubtitle();
  if(s.type==='opts'||s.type==='opts+more'){
    if(!stepOpts[s.key]) stepOpts[s.key]=[...s.opts];
    renderOpts(stepOpts[s.key],s.key,s.type==='opts+more');
  } else if(s.type==='ai') { await renderAI(s); }
}
function updateCardSubtitle(){
  const parts=[]; if(ans.niche)parts.push(ans.niche); if(ans.topic)parts.push(ans.topic); if(ans.title)parts.push(ans.title);
  const sub=document.getElementById('cardSubtitle');
  sub.textContent=parts.length?parts.join(' › '):'Answer a few questions to generate your video script';
}
function renderOpts(opts,key,showMore=false){
  const body=document.getElementById('step-body');
  let html=`<div class="opts" id="opts-wrap">`+opts.map(o=>`<div class="opt${ans[key]===o?' sel':''}" data-v="${esc(o)}">${o}</div>`).join('')+`</div>`;
  html+=`<div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Or type your own…"><button class="custom-add" id="cust-btn">Add</button></div>`;
  body.innerHTML=html;
  document.querySelectorAll('.opt').forEach(b=>{
    b.onclick=()=>{
      const prev=ans[key];
      document.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); ans[key]=b.dataset.v;
      if(key==='niche'&&prev&&prev!==ans[key]){delete ans.topic;delete ans.title;delete stepOpts.topic;delete stepOpts.title;}
      if(key==='topic'&&prev&&prev!==ans[key]){delete ans.title;delete stepOpts.title;}
      setNext(true);
    };
  });
  document.getElementById('cust-btn').onclick=()=>addCustom(key);
  document.getElementById('cust-in').onkeydown=e=>{if(e.key==='Enter')addCustom(key);};
  if(ans[key]) setNext(true);
  const navBar=document.getElementById('nav-bar');
  const oldMore=document.getElementById('more-btn'); if(oldMore)oldMore.remove();
  if(showMore){
    const moreBtn=document.createElement('button');
    moreBtn.id='more-btn'; moreBtn.className='more-btn';
    moreBtn.innerHTML='<span>+</span> More'; moreBtn.onclick=loadMore;
    navBar.insertBefore(moreBtn,document.getElementById('nextBtn'));
  }
}
function addCustom(key){
  const inp=document.getElementById('cust-in'); const v=inp.value.trim(); if(!v)return; inp.value='';
  if(!stepOpts[key])stepOpts[key]=[]; if(!stepOpts[key].includes(v))stepOpts[key].push(v);
  document.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
  const wrap=document.getElementById('opts-wrap');
  const b=document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
  b.onclick=()=>{ document.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); ans[key]=v; setNext(true); };
  wrap.appendChild(b); ans[key]=v; setNext(true);
}
async function renderAI(s){
  if(stepOpts[s.key]&&stepOpts[s.key].length>0){ renderOpts(stepOpts[s.key],s.key,true); return; }
  document.getElementById('step-body').innerHTML=`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Asking AI…</span></div>`;
  const oldMore=document.getElementById('more-btn'); if(oldMore)oldMore.remove();
  const payload={}; (s.aiPayload||[]).forEach(k=>{payload[k]=ans[k];});
  try {
    const r=await fetch(s.aiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json(); const items=d[s.resKey]||[]; if(!items.length)throw new Error('empty');
    stepOpts[s.key]=[...items]; renderOpts(stepOpts[s.key],s.key,true);
  } catch(e) {
    document.getElementById('step-body').innerHTML=`<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load suggestions — add your own below</div><div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Type here…"><button class="custom-add" id="cust-btn">Add</button></div>`;
    document.getElementById('cust-btn').onclick=()=>addCustom(s.key);
    document.getElementById('cust-in').onkeydown=e=>{if(e.key==='Enter')addCustom(s.key);};
  }
}
async function loadMore(){
  const s=STEPS[cur]; const btn=document.getElementById('more-btn'); if(!btn||!s.morePrompt)return;
  btn.disabled=true; btn.innerHTML='<span class="spin">⟳</span> Loading…';
  const existing=[...stepOpts[s.key]]; const prompt=s.morePrompt(existing,ans);
  try {
    const r=await fetch('generate_more_opts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
    if(!r.ok)throw new Error('HTTP '+r.status);
    const d=await r.json(); if(!d.success)throw new Error(d.error||'Server error');
    let added=0;
    (d.items||[]).forEach(item=>{ const c=String(item).trim(); if(c&&!stepOpts[s.key].includes(c)){stepOpts[s.key].push(c);added++;} });
    renderOpts(stepOpts[s.key],s.key,true);
    if(added===0){const b=document.getElementById('more-btn');if(b){b.textContent='No more';b.disabled=true;}}
  } catch(e) {
    const b=document.getElementById('more-btn'); if(b){b.disabled=false;b.innerHTML='<span>+</span> More';} showToast('Could not load more: '+e.message);
  }
}
function goNext(){ if(cur<STEPS.length-1){cur++;clearMoreBtn();render();}else showSummary(); }
function goBack(){ if(cur>0){cur--;clearMoreBtn();render();} }
function clearMoreBtn(){ const b=document.getElementById('more-btn'); if(b)b.remove(); }

function showSummary(){
  document.getElementById('prog').style.width='100%';
  document.getElementById('step-label').textContent='Ready';
  document.getElementById('step-q').textContent='';
  document.getElementById('nav-bar').style.display='none';
  document.getElementById('cardTitle').textContent='📋 Your Video Brief';
  document.getElementById('cardSubtitle').textContent='Review then generate your script';
  const wizRows=Object.entries(ans).map(([k,v])=>`<div class="sum-row"><span class="sum-key">${WIZARD_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`).join('');
  const setRows=Object.entries(settings).map(([k,v])=>`<div class="sum-row"><span class="sum-key">${SETTING_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`).join('');
  document.getElementById('step-body').innerHTML=`
    <div class="done-title">Your brief is complete</div>
    <div class="done-sub">Tap ⚙ to change language, format or duration anytime</div>
    <div class="summary">
      <div class="sum-section">Content</div>${wizRows}
      <div class="sum-section">Settings</div>${setRows}
    </div>
    <div id="script-output"></div>
    <button class="gen-btn" id="gen-btn" onclick="generateScript()">🚀 Generate Video Script</button>
    <button class="restart-btn" onclick="restart()">Start over</button>`;
}

async function generateScript(){
  const btn=document.getElementById('gen-btn'); const out=document.getElementById('script-output');
  btn.disabled=true; btn.textContent='⏳ Generating…';
  out.innerHTML=`<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Writing your script…</span></div>`;
  try {
    const payload=Object.assign({},ans,settings,{
      short_sentences: true,
      scene_format: true,
      max_words_per_scene: 12,
      scene_count: '6-8',
      scene_break_tag: AZURE_BREAK
    });
    const r=await fetch('generate_script.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const text=await r.text();
    let d;
    try { d=JSON.parse(text); } catch(e) { throw new Error('Server error: '+text.substring(0,200)); }
    if(!d.success) throw new Error(d.error||'Script generation failed');
    // Enforce scene break tags client-side as safety net
    const script = enforceSceneBreaks(splitIntoScenes(d.script));
    // Store raw scenes for Step 2
    window._wizScriptRaw = d.script;
    window._wizAns = Object.assign({}, ans, settings);
    out.innerHTML=`<div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Generated Script</div>
      <div class="script-box" id="script-text">${escHtml(script)}</div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button class="build-btn" onclick="openS2('wizard')" style="flex:2;min-width:160px;">🎬 Build Video</button>
        <button class="copy-btn" onclick="copyScript()" style="flex:1;min-width:100px;margin-top:0;">📋 Copy</button>
      </div>`;
    btn.textContent='🔄 Regenerate'; btn.disabled=false;
  } catch(e) {
    out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0">Error: ${e.message}</div>`;
    btn.textContent='Try Again →'; btn.disabled=false;
  }
}
function copyScript(){ const el=document.getElementById('script-text'); if(!el)return; navigator.clipboard.writeText(el.innerText).then(()=>{const b=document.querySelector('.copy-btn');if(b){b.textContent='✓ Copied!';setTimeout(()=>b.textContent='📋 Copy Script',2000);}}); }
function restart(){ cur=0;ans={};stepOpts={};clearMoreBtn();goToMenu(); }

/* ═══════════════════════════════════════════
   CAMPAIGN WIZARD ENGINE
═══════════════════════════════════════════ */
function campSetNext(v){ document.getElementById('campNextBtn').disabled=!v; }
function campSetBack(){ document.getElementById('campBackBtn').style.visibility=campCur===0?'hidden':'visible'; }

async function campRender(){
  const totalSteps=CAMP_STEPS.length;
  document.getElementById('camp-prog').style.width=Math.round((campCur/totalSteps)*100)+'%';
  campSetBack(); campSetNext(false);
  const s=CAMP_STEPS[campCur];
  document.getElementById('camp-step-label').textContent=s.label;
  document.getElementById('camp-step-q').textContent=s.q;
  campUpdateSubtitle();

  if(s.type==='opts'){
    campRenderOpts(s.opts, s.key, false);
  } else if(s.type==='opts+more'){
    if(!campStepOpts[s.key]) campStepOpts[s.key]=[...s.opts];
    campRenderOpts(campStepOpts[s.key], s.key, true);
  } else if(s.type==='multi'){
    campRenderMulti(s.opts, s.key);
  } else if(s.type==='ai'){
    await campRenderAI(s);
  } else if(s.type==='title-select'){
    await campRenderTitleSelect();
  }
}

function campUpdateSubtitle(){
  const parts=[];
  if(campAns.camp_goal) parts.push(campAns.camp_goal);
  if(campAns.camp_niche) parts.push(campAns.camp_niche);
  if(campAns.camp_category) parts.push(campAns.camp_category);
  document.getElementById('campCardSubtitle').textContent=parts.length?parts.join(' › '):'Build your full content calendar in one go';
}

function campRenderOpts(opts, key, showMore=false){
  const body=document.getElementById('camp-step-body');
  let html=`<div class="opts" id="camp-opts-wrap">`+opts.map(o=>`<div class="opt${campAns[key]===o?' sel':''}" data-v="${esc(o)}">${o}</div>`).join('')+`</div>`;
  html+=`<div class="custom-row"><input class="custom-in" id="camp-cust-in" placeholder="Or type your own…"><button class="custom-add" id="camp-cust-btn">Add</button></div>`;
  body.innerHTML=html;
  document.querySelectorAll('#camp-opts-wrap .opt').forEach(b=>{
    b.onclick=()=>{
      document.querySelectorAll('#camp-opts-wrap .opt').forEach(x=>x.classList.remove('sel'));
      b.classList.add('sel'); campAns[key]=b.dataset.v; campSetNext(true);
    };
  });
  document.getElementById('camp-cust-btn').onclick=()=>campAddCustom(key);
  document.getElementById('camp-cust-in').onkeydown=e=>{if(e.key==='Enter')campAddCustom(key);};
  if(campAns[key]) campSetNext(true);

  const navBar=document.getElementById('camp-nav-bar');
  const oldMore=document.getElementById('camp-more-btn'); if(oldMore)oldMore.remove();
  if(showMore&&CAMP_STEPS[campCur].morePrompt){
    const btn=document.createElement('button');
    btn.id='camp-more-btn'; btn.className='more-btn';
    btn.innerHTML='<span>+</span> More'; btn.onclick=campLoadMore;
    navBar.insertBefore(btn,document.getElementById('campNextBtn'));
  }
}

function campAddCustom(key){
  const inp=document.getElementById('camp-cust-in'); const v=inp.value.trim(); if(!v)return; inp.value='';
  if(!campStepOpts[key])campStepOpts[key]=[]; if(!campStepOpts[key].includes(v))campStepOpts[key].push(v);
  document.querySelectorAll('#camp-opts-wrap .opt').forEach(x=>x.classList.remove('sel'));
  const wrap=document.getElementById('camp-opts-wrap');
  const b=document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
  b.onclick=()=>{ document.querySelectorAll('#camp-opts-wrap .opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); campAns[key]=v; campSetNext(true); };
  wrap.appendChild(b); campAns[key]=v; campSetNext(true);
}

function campRenderMulti(opts, key){
  if(!campAns[key]) campAns[key]=[];
  const body=document.getElementById('camp-step-body');
  body.innerHTML=`
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">Select one or more languages</div>
    <div class="opts" id="camp-multi-wrap">`+
    opts.map(o=>{
      const sel=campAns[key].includes(o);
      return `<div class="opt${sel?' multi-sel':''}" data-v="${esc(o)}">${o}</div>`;
    }).join('')+
    `</div>`;
  document.querySelectorAll('#camp-multi-wrap .opt').forEach(b=>{
    b.onclick=()=>{
      const v=b.dataset.v;
      if(!campAns[key]) campAns[key]=[];
      if(campAns[key].includes(v)){
        campAns[key]=campAns[key].filter(x=>x!==v);
        b.classList.remove('multi-sel');
      } else {
        campAns[key].push(v);
        b.classList.add('multi-sel');
      }
      campSetNext(campAns[key].length>0);
    };
  });
  if(campAns[key].length>0) campSetNext(true);
}

async function campRenderAI(s){
  if(campStepOpts[s.key]&&campStepOpts[s.key].length>0){ campRenderOpts(campStepOpts[s.key],s.key,true); return; }
  document.getElementById('camp-step-body').innerHTML=`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Asking AI…</span></div>`;
  const oldMore=document.getElementById('camp-more-btn'); if(oldMore)oldMore.remove();
  const payload={};
  (s.aiPayload||[]).forEach(k=>{
    const mappedKey=(s.payloadMap&&s.payloadMap[k])?s.payloadMap[k]:k;
    payload[mappedKey]=campAns[k]||campAns[mappedKey];
  });
  try {
    const r=await fetch(s.aiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json(); const items=d[s.resKey]||[]; if(!items.length)throw new Error('empty');
    campStepOpts[s.key]=[...items]; campRenderOpts(campStepOpts[s.key],s.key,true);
  } catch(e){
    document.getElementById('camp-step-body').innerHTML=`<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load suggestions — add your own below</div><div class="custom-row"><input class="custom-in" id="camp-cust-in" placeholder="Type here…"><button class="custom-add" id="camp-cust-btn">Add</button></div>`;
    document.getElementById('camp-cust-btn').onclick=()=>campAddCustom(s.key);
    document.getElementById('camp-cust-in').onkeydown=e=>{if(e.key==='Enter')campAddCustom(s.key);};
  }
}

async function campLoadMore(){
  const s=CAMP_STEPS[campCur]; const btn=document.getElementById('camp-more-btn');
  if(!btn||!s.morePrompt)return;
  btn.disabled=true; btn.innerHTML='<span class="spin">⟳</span> Loading…';
  const existing=[...campStepOpts[s.key]]; const prompt=s.morePrompt(existing,campAns);
  try {
    const r=await fetch('generate_more_opts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
    if(!r.ok)throw new Error('HTTP '+r.status);
    const d=await r.json(); if(!d.success)throw new Error(d.error||'Server error');
    let added=0;
    (d.items||[]).forEach(item=>{const c=String(item).trim();if(c&&!campStepOpts[s.key].includes(c)){campStepOpts[s.key].push(c);added++;}});
    campRenderOpts(campStepOpts[s.key],s.key,true);
    if(added===0){const b=document.getElementById('camp-more-btn');if(b){b.textContent='No more';b.disabled=true;}}
  } catch(e){
    const b=document.getElementById('camp-more-btn');if(b){b.disabled=false;b.innerHTML='<span>+</span> More';}
    showToast('Could not load more: '+e.message);
  }
}

async function campRenderTitleSelect(){
  const daysMap={'1 Week (7 days)':7,'2 Weeks (14 days)':14,'1 Month (30 days)':30,'3 Months (90 days)':90,'Custom':14};
  const days=daysMap[campAns.camp_duration]||14;
  const rateMap={
    '1 post every 3 days': 1/3,
    '1 post every 2 days': 1/2,
    '1 post per day':      1,
    '2 posts per day':     2,
    '3 posts per day':     3
  };
  const postsPerDay=rateMap[campAns.camp_posts_per_day]||1;
  const totalVideos=Math.ceil(days*postsPerDay);
  const langs=campAns.camp_languages||['English'];
  const postLabel=campAns.camp_posts_per_day||'1 post per day';

  const body=document.getElementById('camp-step-body');

  body.innerHTML=`
    <div class="camp-progress-bar">
      📊 <strong>${days} days</strong> · <strong>${postLabel}</strong> · <strong>${langs.length} language${langs.length>1?'s':''}</strong>
      = <strong>${totalVideos * langs.length} total videos</strong>
      (${totalVideos} unique scripts × ${langs.length} language${langs.length>1?'s':''})
    </div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:12px;">
      Select exactly <strong style="color:var(--dark-blue);">${totalVideos} video title${totalVideos>1?'s':''}</strong> for your campaign. Need more? Hit "+ More Titles".
    </div>
    <div id="camp-title-count" class="title-count-badge">
      <span>🎯</span> <span id="camp-sel-count">0</span> of <strong>${totalVideos}</strong> selected
    </div>
    <div id="camp-title-list" class="title-grid">
      <div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating title ideas…</span></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap;">
      <input class="custom-in" id="camp-title-cust-in" placeholder="Or add your own title…" style="flex:1;min-width:160px;">
      <button class="custom-add" id="camp-title-cust-btn">Add</button>
      <button class="more-btn" id="camp-titles-more-btn" onclick="campLoadMoreTitles(${totalVideos})" disabled>
        <span>+</span> More Titles
      </button>
    </div>`;

  document.getElementById('camp-title-cust-btn').onclick=()=>campAddCustomTitle(totalVideos);
  document.getElementById('camp-title-cust-in').onkeydown=e=>{if(e.key==='Enter')campAddCustomTitle(totalVideos);};

  campSetNext(false);

  try {
    const numToGenerate=Math.max(totalVideos+8, 20);
    const r=await fetch('generate_titles.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({niche:campAns.camp_niche, topic:campAns.camp_category, count:numToGenerate, goal:campAns.camp_goal})
    });
    const d=await r.json();
    const titles=d.titles||[];
    campStepOpts['camp_titles']=[...titles];
    campRenderTitleList(titles, totalVideos);
    const moreBtn=document.getElementById('camp-titles-more-btn');
    if(moreBtn) moreBtn.disabled=false;
  } catch(e){
    document.getElementById('camp-title-list').innerHTML=`<div style="color:#c00;font-size:13px;padding:8px;">Could not load suggestions. Add your own above.</div>`;
    const moreBtn=document.getElementById('camp-titles-more-btn');
    if(moreBtn) moreBtn.disabled=false;
  }
}

async function campLoadMoreTitles(needed){
  const btn=document.getElementById('camp-titles-more-btn');
  if(btn){ btn.disabled=true; btn.innerHTML='<span class="spin">⟳</span> Loading…'; }

  const existing=campStepOpts['camp_titles']||[];
  const prompt=`You are an expert short-form video content creator for the niche "${campAns.camp_niche}", category "${campAns.camp_category}", campaign goal "${campAns.camp_goal}".
Generate 10 MORE specific, engaging video title ideas for Reels/TikTok/Shorts.
Do NOT repeat any of these: ${existing.join(', ')}.
Return ONLY a valid JSON array of title strings.`;

  try {
    const r=await fetch('generate_more_opts.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({prompt})
    });
    if(!r.ok) throw new Error('HTTP '+r.status);
    const d=await r.json();
    if(!d.success) throw new Error(d.error||'Server error');

    const newItems=[];
    (d.items||[]).forEach(item=>{
      const c=String(item).trim();
      if(c && !campStepOpts['camp_titles'].includes(c)){
        campStepOpts['camp_titles'].push(c);
        newItems.push(c);
      }
    });

    if(newItems.length===0){
      if(btn){ btn.textContent='No more'; btn.disabled=true; }
      showToast('No new titles found — try adding your own');
      return;
    }

    const list=document.getElementById('camp-title-list');
    if(list){
      newItems.forEach(t=>{
        const el=document.createElement('div');
        el.className='title-item';
        el.dataset.title=t;
        el.innerHTML=`<div class="chk"></div><span>${t}</span>`;
        el.onclick=()=>campToggleTitle(el, t, needed);
        list.appendChild(el);
        el.scrollIntoView({behavior:'smooth', block:'nearest'});
      });
    }

    campUpdateTitleCount(needed);
    if(btn){ btn.disabled=false; btn.innerHTML='<span>+</span> More Titles'; }
    showToast(`✅ ${newItems.length} new titles added`);

  } catch(e){
    showToast('Could not load more titles: '+e.message);
    if(btn){ btn.disabled=false; btn.innerHTML='<span>+</span> More Titles'; }
  }
}

function campRenderTitleList(titles, needed){
  const list=document.getElementById('camp-title-list');
  if(!list)return;
  if(!campSelectedTitles) campSelectedTitles=[];
  list.innerHTML=titles.map((t,i)=>{
    const sel=campSelectedTitles.includes(t);
    return `<div class="title-item${sel?' sel':''}" data-title="${esc(t)}" onclick="campToggleTitle(this,'${esc(t)}',${needed})">
      <div class="chk">${sel?'✓':''}</div>
      <span>${t}</span>
    </div>`;
  }).join('');
  campUpdateTitleCount(needed);
}

function campToggleTitle(el, title, needed){
  if(!campSelectedTitles) campSelectedTitles=[];
  if(campSelectedTitles.includes(title)){
    campSelectedTitles=campSelectedTitles.filter(x=>x!==title);
    el.classList.remove('sel');
    el.querySelector('.chk').textContent='';
  } else {
    campSelectedTitles.push(title);
    el.classList.add('sel');
    el.querySelector('.chk').textContent='✓';
  }
  campUpdateTitleCount(needed);
}

function campAddCustomTitle(needed){
  const inp=document.getElementById('camp-title-cust-in'); const v=inp.value.trim(); if(!v)return; inp.value='';
  if(!campSelectedTitles) campSelectedTitles=[];
  if(!campStepOpts['camp_titles']) campStepOpts['camp_titles']=[];
  campStepOpts['camp_titles'].push(v);
  campSelectedTitles.push(v);
  const list=document.getElementById('camp-title-list');
  const el=document.createElement('div');
  el.className='title-item sel'; el.dataset.title=v;
  el.innerHTML=`<div class="chk">✓</div><span>${v}</span>`;
  el.onclick=()=>campToggleTitle(el,v,needed);
  list.appendChild(el);
  campUpdateTitleCount(needed);
}

function campUpdateTitleCount(needed){
  const count=campSelectedTitles.length;
  const el=document.getElementById('camp-sel-count'); if(el) el.textContent=count;
  const badge=document.getElementById('camp-title-count');
  if(badge){
    if(count>=needed){
      badge.style.background='#dcfce7'; badge.style.borderColor='#86efac'; badge.style.color='#166534';
      badge.querySelector('span').textContent='✅';
    } else {
      badge.style.background='#fef3c7'; badge.style.borderColor='#fde68a'; badge.style.color='#92400e';
      badge.querySelector('span').textContent='🎯';
    }
  }
  campAns['camp_titles']=campSelectedTitles;
  campSetNext(count >= needed);
  const nextBtn=document.getElementById('campNextBtn');
  if(nextBtn){
    if(count>=needed) nextBtn.textContent='Continue →';
    else nextBtn.textContent=`Select ${needed-count} more →`;
  }
}

function campGoNext(){
  if(campCur<CAMP_STEPS.length-1){
    campCur++;
    const oldMore=document.getElementById('camp-more-btn'); if(oldMore)oldMore.remove();
    campRender();
  } else {
    campShowSummary();
  }
}
function campGoBack(){
  if(campCur>0){
    campCur--;
    const oldMore=document.getElementById('camp-more-btn'); if(oldMore)oldMore.remove();
    campRender();
  }
}

function campShowSummary(){
  document.getElementById('camp-prog').style.width='100%';
  document.getElementById('camp-step-label').textContent='Ready';
  document.getElementById('camp-step-q').textContent='';
  document.getElementById('camp-nav-bar').style.display='none';
  document.getElementById('campCardTitle').textContent='📋 Campaign Brief';
  document.getElementById('campCardSubtitle').textContent='Review and generate your campaign scripts';

  const daysMap={'1 Week (7 days)':7,'2 Weeks (14 days)':14,'1 Month (30 days)':30,'3 Months (90 days)':90,'Custom':14};
  const days=daysMap[campAns.camp_duration]||14;
  const rateMap={'1 post every 3 days':1/3,'1 post every 2 days':1/2,'1 post per day':1,'2 posts per day':2,'3 posts per day':3};
  const postsPerDay=rateMap[campAns.camp_posts_per_day]||1;
  const langs=campAns.camp_languages||['English'];
  const totalScripts=campSelectedTitles.length;
  const totalVideos=totalScripts*langs.length;

  document.getElementById('camp-step-body').innerHTML=`
    <div class="done-title">Your campaign is ready</div>
    <div class="done-sub">${totalScripts} scripts × ${langs.length} language${langs.length>1?'s':''} = ${totalVideos} total videos</div>
    <div class="summary">
      <div class="sum-section">Campaign Setup</div>
      <div class="sum-row"><span class="sum-key">Goal</span><span class="sum-val">${campAns.camp_goal||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Niche</span><span class="sum-val">${campAns.camp_niche||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Category</span><span class="sum-val">${campAns.camp_category||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Duration</span><span class="sum-val">${campAns.camp_duration||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Posts / Day</span><span class="sum-val">${campAns.camp_posts_per_day||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Video Length</span><span class="sum-val">${campAns.camp_video_length||'-'}</span></div>
      <div class="sum-row"><span class="sum-key">Languages</span><span class="sum-val">${langs.join(', ')}</span></div>
      <div class="sum-section">Videos to Generate</div>
      ${campSelectedTitles.map((t,i)=>`<div class="sum-row"><span class="sum-key" style="color:var(--purple);font-weight:600;">#${i+1}</span><span class="sum-val" style="text-align:left;">${t}</span></div>`).join('')}
    </div>
    <div id="camp-gen-output"></div>
    <button class="gen-btn" id="camp-gen-btn" onclick="generateCampaign()">🚀 Generate ${totalVideos} Campaign Scripts</button>
    <button class="restart-btn" onclick="campRestart()">Start over</button>`;
}

async function generateCampaign(){
  const btn=document.getElementById('camp-gen-btn');
  const out=document.getElementById('camp-gen-output');
  const langs=campAns.camp_languages||['English'];
  const totalScripts=campSelectedTitles.length;
  const totalVideos=totalScripts*langs.length;

  btn.disabled=true; btn.textContent=`⏳ Generating ${totalVideos} scripts…`;
  out.innerHTML=`<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating campaign scripts (${totalVideos} total)…</span></div>`;

  try {
    const r=await fetch('generate_campaign.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        goal:campAns.camp_goal, niche:campAns.camp_niche, category:campAns.camp_category,
        languages:langs, duration:campAns.camp_duration, posts_per_day:campAns.camp_posts_per_day,
        video_length:campAns.camp_video_length, titles:campSelectedTitles,
        reel_type:settings.reel_type, format:settings.format,
        objective:settings.objective, audience:settings.audience,
        short_sentences: true,
        max_words_per_scene: 12,
        scene_count: '6-8',
        scene_break_tag: AZURE_BREAK
      })
    });
    const rawText=await r.text();
    let d;
    try { d=JSON.parse(rawText); } catch(e) { throw new Error('Server error: '+rawText.substring(0,300)); }
    if(!d.success) throw new Error(d.error||'Campaign generation failed');

    // Enforce scene break tags on every script client-side
    const results=(d.results||[]).map(item=>({
      ...item,
      script: enforceSceneBreaks(splitIntoScenes(item.script||''))
    }));

    let html=`<div style="margin:20px 0 12px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">
      ✅ ${results.length} scripts generated — saved to your projects</div>`;

    results.forEach((item,i)=>{
      html+=`<div class="campaign-result-card">
        <div class="campaign-result-header" onclick="toggleCampResult(this)">
          <div>
            <div style="font-size:13px;font-weight:700;color:var(--dark-blue);">#${i+1} ${item.title}</div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">${item.language} · ${item.podcast_id?'Saved ✓':'Pending'}</div>
          </div>
          <span style="color:var(--muted);font-size:18px;">▾</span>
        </div>
        <div class="campaign-result-body">
          <div class="script-box">${escHtml(item.script||'')}</div>
          ${item.podcast_id?`<a href="videomaker_9.php?podcast_id=${item.podcast_id}" class="copy-btn" style="display:block;text-align:center;text-decoration:none;margin-top:8px;">🎬 Open in VideoMaker</a>`:''}
        </div>
      </div>`;
    });

    out.innerHTML=html;
    btn.textContent='🔄 Regenerate Campaign'; btn.disabled=false;
    if(results.length===0){
      const errList=(d.errors||[]).join('<br>');
      out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;">
        <strong>⚠️ No scripts were generated.</strong><br>${errList||'Check a_errors.log for details.'}</div>`;
    } else {
      showToast('✅ ' + results.length + ' scripts saved to your projects!');
    }

  } catch(e){
    out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${e.message}</div>`;
    btn.textContent='Try Again →'; btn.disabled=false;
  }
}

function toggleCampResult(header){
  const body=header.nextElementSibling;
  body.classList.toggle('open');
  header.querySelector('span:last-child').textContent=body.classList.contains('open')?'▴':'▾';
}

function campRestart(){
  campCur=0; campAns={}; campStepOpts={}; campSelectedTitles=[];
  document.getElementById('camp-nav-bar').style.display='flex';
  goToMenu();
}

/* ═══════════════════════════════════════════
   I HAVE CONTENT
═══════════════════════════════════════════ */
function renderContentSettingsPills(){
  const el=document.getElementById('content-settings-pills'); if(!el)return;
  const short={language:settings.language,reel_type:settings.reel_type.split(' (')[0],format:settings.format.split(' ')[0]};
  el.innerHTML=Object.values(short).map(v=>`<span class="s-pill">${v}</span>`).join('');
}

async function processMyContent(){
  const btn=document.getElementById('content-process-btn'); const out=document.getElementById('content-script-output');
  const title=document.getElementById('content-title').value.trim();
  const script=document.getElementById('content-script').value.trim();
  const cta=document.getElementById('content-cta').value.trim();
  if(!title){alert('Please enter a video title');return;}
  if(!script){alert('Please paste your script or content');return;}
  btn.disabled=true; btn.textContent='⏳ Processing…';
  out.innerHTML=`<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Formatting into scenes…</span></div>`;
  const reelType=settings.reel_type.toLowerCase().includes('b-roll')?'broll':settings.reel_type.toLowerCase().includes('podcast')?'podcast':'standard';

  // Send content + instructions directly — PHP will use system+user message format
  const userContent = `ORIGINAL CONTENT:\n${script}\n\nLANGUAGE: ${settings.language}\nCALL TO ACTION: ${cta}`;

  try {
    const r=await fetch('generate_script.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({niche:'custom',title,objective:'Inform',audience:'General Public',angle:'Storytelling',duration:'60 seconds',cta,language:settings.language,reel_type:settings.reel_type,format:settings.format,_custom_prompt:userContent,_mode:'content'})});
    const rawText=await r.text();
    let d;
    try { d=JSON.parse(rawText); } catch(e) { throw new Error('Server error: '+rawText.substring(0,200)); }
    if(!d.success) throw new Error(d.error||'Processing failed');
    console.log('RAW d.script:', JSON.stringify(d.script));
    console.log('After splitIntoScenes:', JSON.stringify(splitIntoScenes(d.script)));
    window._contentScriptRaw = d.script;
    // Split into scenes then enforce break tag on every line
    const processedScript = enforceSceneBreaks(splitIntoScenes(d.script));
    console.log('After enforceSceneBreaks:', JSON.stringify(processedScript));
    out.innerHTML=`<div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Formatted Script</div>
      <div class="script-box" id="content-script-text">${escHtml(processedScript)}</div>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
        <button class="build-btn" onclick="openS2('content')" style="flex:2;min-width:160px;">🎬 Build Video</button>
        <button class="copy-btn" onclick="copyContentScript()" style="flex:1;min-width:100px;margin-top:0;">📋 Copy</button>
      </div>`;
    btn.textContent='🔄 Reprocess'; btn.disabled=false;
  } catch(e){
    out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${e.message}</div>`;
    btn.textContent='📝 Process Content'; btn.disabled=false;
  }
}

function copyContentScript(){ const el=document.getElementById('content-script-text'); if(!el)return; navigator.clipboard.writeText(el.innerText).then(()=>{const b=document.querySelector('#content-script-output .copy-btn');if(b){b.textContent='✓ Copied!';setTimeout(()=>b.textContent='📋 Copy Script',2000);}}); }

/* ═══════════════════════════════════════════
   MODE SWITCHING
═══════════════════════════════════════════ */
function selectMode(mode){
  ['modeSelect','modeWizard','modeCampaign','modeContent'].forEach(id=>{
    document.getElementById(id).style.display='none';
  });
  if(mode==='wizard'){
    document.getElementById('modeWizard').style.display='block';
    cur=0; ans={}; stepOpts={}; clearMoreBtn();
    document.getElementById('nav-bar').style.display='flex';
    document.getElementById('cardTitle').textContent='✨ Generate Video Script';
    document.getElementById('cardSubtitle').textContent='Answer a few questions to generate your video script';
    render();
  } else if(mode==='campaign'){
    document.getElementById('modeCampaign').style.display='block';
    campCur=0; campAns={}; campStepOpts={}; campSelectedTitles=[];
    document.getElementById('camp-nav-bar').style.display='flex';
    document.getElementById('campCardTitle').textContent='📅 Generate Campaign';
    document.getElementById('campCardSubtitle').textContent='Build your full content calendar in one go';
    renderCampSettingsPills();
    campRender();
  } else if(mode==='content'){
    document.getElementById('modeContent').style.display='block';
    renderContentSettingsPills();
  }
  window.scrollTo({top:0,behavior:'smooth'});
}

function goToMenu(){
  ['modeWizard','modeCampaign','modeContent'].forEach(id=>{document.getElementById(id).style.display='none';});
  document.getElementById('modeSelect').style.display='block';
  window.scrollTo({top:0,behavior:'smooth'});
}

function esc(s){ return String(s).replace(/"/g,'&quot;'); }
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ── Init ── */
loadSettings();
document.getElementById('modeWizard').style.display='none';
document.getElementById('modeCampaign').style.display='none';
document.getElementById('modeContent').style.display='none';
document.getElementById('modeSelect').style.display='block';

/* ═══════════════════════════════════════════
   STEP 2 — BUILD VIDEO
═══════════════════════════════════════════ */

const S2_ENDPOINT = 'wizard_step2.php';
let s2PodcastId = null;
let s2Source = 'wizard'; // 'wizard' | 'content'
let s2MediaType = 'stock_images';
let s2Cancelled = false;

/* ── Media type selector ── */
function selMedia(el){
  document.querySelectorAll('.s2-media-opt').forEach(x=>x.classList.remove('sel'));
  el.classList.add('sel');
  s2MediaType = el.dataset.val;
}

/* ── Open modal ── */
function openS2(source){
  s2Source = source || 'wizard';
  s2Cancelled = false;
  s2PodcastId = null;
  // Reset UI
  document.getElementById('s2Setup').style.display='block';
  document.getElementById('s2Progress').style.display='none';
  document.getElementById('s2DoneBar').style.display='none';
  document.getElementById('s2Log').innerHTML='';
  ['s2Step0','s2Step1','s2Step2','s2Step3'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){ el.className='s2-step'; el.querySelector('.s2-step-sub').textContent='Waiting…'; }
  });
  // Show/hide guest voice based on reel type
  const isPodcast = settings.reel_type && settings.reel_type.toLowerCase().includes('podcast');
  document.getElementById('s2GuestVoiceSection').style.display = isPodcast ? 'block' : 'none';
  loadS2Voices();
  document.getElementById('s2Overlay').classList.add('open');
  document.getElementById('s2CloseBtn').style.display='inline';
}

function closeS2(){
  s2Cancelled = true;
  document.getElementById('s2Overlay').classList.remove('open');
}

/* ── Load voices from server ── */
async function loadS2Voices(){
  const host = document.getElementById('s2HostVoice');
  const guest = document.getElementById('s2GuestVoice');
  try {
    const r = await fetch('get_voices.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'lang_code='+encodeURIComponent(settings.language||'en')});
    if(!r.ok) throw new Error('HTTP '+r.status);
    const d = await r.json();
    const voices = d.voices || [];
    if(voices.length === 0) throw new Error('No voices');
    const opts = voices.map(v=>`<option value="${esc(v.voice_id)}">${v.voice_name}</option>`).join('');
    host.innerHTML = opts;
    guest.innerHTML = '<option value="">— Same as host —</option>' + opts;
  } catch(e){
    // Fallback to OpenAI voices
    const openaiVoices = [
      {id:'openai:alloy',name:'Alloy (OpenAI)'},
      {id:'openai:echo',name:'Echo (OpenAI)'},
      {id:'openai:fable',name:'Fable (OpenAI)'},
      {id:'openai:onyx',name:'Onyx (OpenAI)'},
      {id:'openai:nova',name:'Nova (OpenAI)'},
      {id:'openai:shimmer',name:'Shimmer (OpenAI)'}
    ];
    const opts = openaiVoices.map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
    host.innerHTML = opts;
    guest.innerHTML = '<option value="">— Same as host —</option>' + opts;
  }
}

/* ── Log to modal ── */
function s2Log(msg, type='info'){
  const log = document.getElementById('s2Log');
  const p = document.createElement('p');
  p.className = 's2-log-line ' + type;
  p.textContent = msg;
  log.appendChild(p);
  log.scrollTop = log.scrollHeight;
}

/* ── Step status ── */
function s2StepStatus(stepNum, status, sub){
  const el = document.getElementById('s2Step'+stepNum);
  if(!el) return;
  el.className = 's2-step ' + status;
  el.querySelector('.s2-step-sub').textContent = sub;
}

/* ── Parse wizard scenes into Step 2 format ── */
// ── Quick fallback: basic scene data without AI ──────────────────────────
function parseWizardScenesBasic(rawScript, wizAns){
  const lines = rawScript.split('\n').map(l=>l.trim()).filter(Boolean);
  const niche = wizAns.niche || 'professional';
  const stopWords = new Set(['the','and','for','you','your','with','that','this','are','can',
                             'will','have','from','they','what','about','more','just','into']);
  return lines.map(line => {
    const text = line.replace(/<break[^>]*>/gi,'').trim();
    const words = text.toLowerCase().replace(/[^a-z0-9 ]/g,'').split(/\s+/)
      .filter(w=>w.length>3 && !stopWords.has(w));
    const hashtags = [...new Set([niche.toLowerCase().replace(/\s+/g,''), ...words.slice(0,4)])].join(' ');
    const nlTags = [text, `${niche} professional`, `${words[0]||niche} lifestyle`, `${niche} ${words[1]||'concept'}`, `real life ${niche}`].join('|');
    const prompt = `Photorealistic documentary-style photograph. Scene: ${text} Niche: ${niche}. Natural lighting, candid composition, 35mm lens, shallow depth of field, authentic environment.`;
    return { text: line, prompt, hashtags, nl_tags: nlTags, actor: 'host' };
  });
}

// ── AI-powered scene enhancement ─────────────────────────────────────────
// Calls Claude via Anthropic API to generate realistic prompts, hashtags, NL tags
async function enhanceSceneWithAI(sceneText, niche, title, sceneIndex, total){
  const cleanText = sceneText.replace(/<break[^>]*>/gi,'').trim();

  const systemPrompt = `You are a professional video art director specialising in photorealistic image prompts for social media videos.
For each scene sentence you receive, output ONLY valid JSON with exactly these fields:
{
  "prompt": "...",
  "hashtags": "...",
  "nl_tags": "..."
}

PROMPT rules:
- Write a highly detailed photorealistic image generation prompt (60-100 words)
- Use documentary-style, cinematic realism approach
- Specify: real location/environment, natural lighting (window light / practical indoor / outdoor daylight), camera settings (35mm or 50mm lens, shallow depth of field, slight bokeh, subtle film grain)
- Include real-world materials: wood grain, fabric textures, glass reflections, metal surfaces
- Add lived-in details: slight clutter, uneven textures, natural imperfections, background context
- Avoid: CGI look, plastic textures, perfect symmetry, overly polished or staged scenes
- Match the mood and content of the scene sentence exactly

HASHTAGS rules:
- 3-5 single words, space-separated, no # symbol
- Keywords for internal image library search

NL_TAGS rules:
- 5-6 natural English phrases pipe-separated (|)
- Each phrase describes who/what/where for Pexels or stock video search
- Be specific and realistic (e.g. "woman reviewing documents at kitchen table" not just "woman")

Return ONLY the JSON object. No markdown, no explanation.`;

  const userMsg = `Scene ${sceneIndex+1} of ${total}
Niche: ${niche}
Video title: ${title}
Scene text: "${cleanText}"`;

  try {
    const resp = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: 'claude-sonnet-4-20250514',
        max_tokens: 600,
        system: systemPrompt,
        messages: [{ role: 'user', content: userMsg }]
      })
    });
    const data = await resp.json();
    const raw = data.content?.[0]?.text || '';
    const cleaned = raw.replace(/^```(?:json)?\s*/i,'').replace(/\s*```$/i,'').trim();
    const parsed = JSON.parse(cleaned);
    if(parsed.prompt && parsed.hashtags && parsed.nl_tags){
      return {
        text: sceneText,
        prompt: parsed.prompt,
        hashtags: parsed.hashtags,
        nl_tags: parsed.nl_tags,
        actor: 'host'
      };
    }
    throw new Error('Incomplete AI response');
  } catch(e){
    // Fallback to basic generation for this scene
    const basic = parseWizardScenesBasic(sceneText, {niche})[0];
    return basic || { text: sceneText, prompt: cleanText, hashtags: niche, nl_tags: cleanText, actor: 'host' };
  }
}

// ── Main scene parser: AI-enhanced with fallback ──────────────────────────
async function parseWizardScenes(rawScript, wizAns, logFn){
  const lines = rawScript.split('\n').map(l=>l.trim()).filter(Boolean);
  const niche = wizAns.niche || 'professional';
  const title = wizAns.title || 'Video';
  const total = lines.length;
  const scenes = [];

  for(let i=0; i<lines.length; i++){
    if(logFn) logFn(`✨ Enhancing scene ${i+1}/${total}…`, 'info');
    const scene = await enhanceSceneWithAI(lines[i], niche, title, i, total);
    scenes.push(scene);
  }
  return scenes;
}

/* ── Main: start building video ── */
async function startBuildVideo(){
  const hostVoice  = document.getElementById('s2HostVoice').value;
  const guestVoice = document.getElementById('s2GuestVoice').value || hostVoice;
  const rate       = document.getElementById('s2Rate').value;

  if(!hostVoice){ alert('Please select a host voice'); return; }

  // Switch UI to progress view
  document.getElementById('s2Setup').style.display='none';
  document.getElementById('s2Progress').style.display='block';
  document.getElementById('s2CloseBtn').style.display='none';
  s2Cancelled = false;

  // Get script data
  let scenes = [];
  let title   = '';
  let langCode = (settings.language||'English').substring(0,2).toLowerCase();

  let rawForParse = '';
  let wizAnsForParse = {};
  if(s2Source === 'wizard'){
    rawForParse = window._wizScriptRaw || '';
    wizAnsForParse = Object.assign({}, window._wizAns || ans);
    title = wizAnsForParse.title || 'VideoVizard Script';
    scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse); // temp basic until AI runs
  } else if(s2Source === 'content'){
    title = document.getElementById('content-title')?.value.trim() || 'My Video';
    rawForParse = window._contentScriptRaw || '';
    wizAnsForParse = { niche: 'general', title, topic: title };
    scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse); // temp basic until AI runs
  }

  // ── STEP 0: AI prompt enhancement ──────────────────────
  s2StepStatus(0, 'active', `Enhancing ${scenes.length} scenes with AI…`);
  s2Log(`🤖 Generating realistic image prompts for ${scenes.length} scenes…`, 'info');
  try {
    scenes = await parseWizardScenes(rawForParse, wizAnsForParse, s2Log);
    s2StepStatus(0, 'done', `✓ ${scenes.length} scene prompts enhanced`);
    s2Log(`✅ AI prompts generated for all scenes`, 'success');
  } catch(e){
    s2Log(`⚠ AI enhancement failed, using basic prompts: ${e.message}`, 'warning');
    scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse);
    s2StepStatus(0, 'done', `✓ Basic prompts used (${scenes.length} scenes)`);
  }

  s2Log(`Starting pipeline: ${scenes.length} scenes | voice: ${hostVoice} | media: ${s2MediaType}`, 'info');

  // ── STEP 1: Create scenes in DB ──────────────────────────
  s2StepStatus(1, 'active', `Creating ${scenes.length} scenes…`);
  s2Log('📝 Creating scenes in database…', 'info');

  const fd = new FormData();
  fd.append('action',       'create_scenes_from_content');
  fd.append('title',        title);
  fd.append('target_lang',  langCode);
  fd.append('reel_type',    settings.reel_type?.toLowerCase().includes('broll')?'broll':settings.reel_type?.toLowerCase().includes('podcast')?'podcast':'standard');
  fd.append('topic',        (window._wizAns||ans).topic||title);
  fd.append('category',     (window._wizAns||ans).topic||'free-format');
  fd.append('niche',        (window._wizAns||ans).niche||'');
  fd.append('cta',          (window._wizAns||ans).cta||'Follow for more');
  fd.append('host_voice',   hostVoice);
  fd.append('guest_voice',  guestVoice);
  fd.append('rate',         rate);
  fd.append('content',      JSON.stringify(scenes));

  let podcastId = null;
  try {
    const r   = await fetch(S2_ENDPOINT, {method:'POST', body:fd});
    const txt = await r.text();
    let d;
    try { d = JSON.parse(txt); } catch(e){ throw new Error('Server error: '+txt.substring(0,200)); }
    if(!d.success) throw new Error(d.message || d.error || 'Failed to create scenes');
    podcastId = d.podcast_id;
    s2PodcastId = podcastId;
    s2StepStatus(1, 'done', `✓ ${d.scene_count} scenes saved (ID: ${podcastId})`);
    s2Log(`✅ Podcast #${podcastId} created with ${d.scene_count} scenes`, 'success');
  } catch(err){
    s2StepStatus(1, 'error', err.message);
    s2Log('❌ '+err.message, 'error');
    document.getElementById('s2CloseBtn').style.display='inline';
    return;
  }

  if(s2Cancelled){ s2Log('⏹ Cancelled','warning'); return; }

  // ── STEP 2: Generate audio ───────────────────────────────
  s2StepStatus(2, 'active', 'Fetching scenes…');
  s2Log('🎤 Starting audio generation…', 'info');

  // Get scenes from DB
  const scFd = new FormData();
  scFd.append('action', 'get_scenes');
  scFd.append('podcast_id', podcastId);
  let dbScenes = [];
  try {
    const r = await fetch(S2_ENDPOINT, {method:'POST', body:scFd});
    dbScenes = await r.json();
  } catch(e){ s2Log('⚠ Could not fetch scenes: '+e.message,'warning'); }

  let audioDone=0, audioFail=0;
  for(let i=0; i<dbScenes.length; i++){
    if(s2Cancelled) break;
    const scene = dbScenes[i];
    const seqNo = i+1;
    s2StepStatus(2, 'active', `Audio ${seqNo}/${dbScenes.length}…`);

    // Strip break tag from text before sending to TTS
    const ttsText = (scene.text_contents||'').replace(/<break[^>]*>/gi,'').trim();
    if(!ttsText){ audioDone++; continue; }

    // Delete existing file
    const chkFd = new FormData();
    chkFd.append('action','check_audio_file');
    chkFd.append('filename','voice_'+podcastId+'_'+scene.id+'_'+langCode+'.mp3');
    await fetch(S2_ENDPOINT,{method:'POST',body:chkFd}).catch(()=>{});

    const voiceToUse = (scene.actor==='guest' && guestVoice) ? guestVoice : hostVoice;
    const aFd = new FormData();
    aFd.append('action','generate_scene_audio');
    aFd.append('scene_id', scene.id);
    aFd.append('podcast_id', podcastId);
    aFd.append('seq_no', seqNo);
    aFd.append('lang_code', langCode);
    aFd.append('voice_id', voiceToUse);
    aFd.append('rate', rate);
    aFd.append('text', ttsText);

    try {
      const r   = await fetch(S2_ENDPOINT,{method:'POST',body:aFd});
      const d   = await r.json();
      if(d.success){ audioDone++; s2Log(`✓ Scene ${seqNo} audio OK`,'success'); }
      else { audioFail++; s2Log(`✗ Scene ${seqNo}: ${d.error}`,'error'); }
    } catch(e){ audioFail++; s2Log(`✗ Scene ${seqNo}: ${e.message}`,'error'); }
  }
  s2StepStatus(2, audioFail>0?'error':'done', `✓ ${audioDone} audio files${audioFail>0?' ('+audioFail+' failed)':''}`);
  s2Log(`Audio complete: ${audioDone} OK, ${audioFail} failed`, audioDone>0?'success':'error');

  if(s2Cancelled){ s2Log('⏹ Cancelled','warning'); return; }

  // ── STEP 3: Assign media ─────────────────────────────────
  s2StepStatus(3, 'active', 'Searching media library…');
  s2Log('🖼 Assigning media to scenes…', 'info');

  const usedFiles = new Set();
  let mediaDone=0, mediaFail=0;

  for(let i=0; i<dbScenes.length; i++){
    if(s2Cancelled) break;
    const scene = dbScenes[i];
    const seqNo = i+1;
    s2StepStatus(3,'active',`Media ${seqNo}/${dbScenes.length}…`);

    if(s2MediaType === 'unique_images'){
      // AI image generation — delegate to generate_image_api.php
      try {
        const imgFd = new FormData();
        imgFd.append('prompt', scene.prompt||scene.text_contents||'');
        imgFd.append('scene_id', scene.id);
        imgFd.append('podcast_id', podcastId);
        const r   = await fetch('generate_image_api.php',{method:'POST',body:imgFd});
        const d   = await r.json();
        if(d.success && d.filename){
          await assignS2Image(scene.id, d.filename, podcastId, seqNo, scene.hashtags||'', 0, 0, 1, scene.prompt||'');
          mediaDone++;
          s2Log(`✓ Scene ${seqNo} AI image generated`,'success');
        } else {
          mediaFail++;
          s2Log(`✗ Scene ${seqNo} AI image: ${d.error||'failed'}`,'error');
        }
      } catch(e){ mediaFail++; s2Log(`✗ Scene ${seqNo}: ${e.message}`,'error'); }
    } else {
      // Stock images/videos — search internal library using nl_tags then hashtags
      const nlPhrases = (scene.natural_language_tags||'').split('|').map(p=>p.trim()).filter(Boolean);
      const queries   = nlPhrases.length>0 ? nlPhrases : [(scene.hashtags||'').split(',')[0]||''];
      let found = [];

      for(const q of queries){
        if(!q) continue;
        const sFd = new FormData();
        sFd.append('action','search_images');
        sFd.append('hashtags', q);
        try {
          const r = await fetch(S2_ENDPOINT,{method:'POST',body:sFd});
          found = await r.json();
          if(found && found.length>0) break;
        } catch(e){}
      }

      // Filter out already used files
      const unique = (found||[]).filter(f=>!usedFiles.has(f.filename));
      if(unique.length>0){
        const pick = unique[0];
        usedFiles.add(pick.filename);
        await assignS2Image(scene.id, pick.filename, podcastId, seqNo, scene.hashtags||'', found.filter(f=>f.type==='image').length, found.filter(f=>f.type==='video').length, 0, '');
        mediaDone++;
        s2Log(`✓ Scene ${seqNo}: ${pick.filename}`,'success');
      } else {
        mediaFail++;
        s2Log(`⚠ Scene ${seqNo}: no media found`,'warning');
      }
    }
  }

  s2StepStatus(3, mediaFail===dbScenes.length?'error':'done', `✓ ${mediaDone} scenes assigned`);
  s2Log(`Media complete: ${mediaDone} assigned, ${mediaFail} not found`, 'success');

  // ── DONE ─────────────────────────────────────────────────
  document.getElementById('s2CloseBtn').style.display='inline';
  const doneBar = document.getElementById('s2DoneBar');
  const vidLink = document.getElementById('s2VideoLink');
  vidLink.href = 'videomaker.php?podcast_id='+podcastId;
  doneBar.style.display='flex';
  s2Log('🎉 All done! Podcast #'+podcastId, 'success');
  showToast('✅ Video ready — Podcast #'+podcastId);
}

async function assignS2Image(sceneId, filename, podcastId, sceneNo, hashtags, foundImages, foundVideos, aiGenerated, aiPrompt){
  const fd = new FormData();
  fd.append('action','assign_image');
  fd.append('scene_id', sceneId);
  fd.append('filename', filename);
  await fetch(S2_ENDPOINT,{method:'POST',body:fd}).catch(()=>{});

  // Log media search
  const lFd = new FormData();
  lFd.append('action','log_media_search');
  lFd.append('podcast_id',   podcastId);
  lFd.append('scene_id',     sceneId);
  lFd.append('scene_no',     sceneNo);
  lFd.append('hashtags',     hashtags);
  lFd.append('found_images', foundImages);
  lFd.append('found_videos', foundVideos);
  lFd.append('selected_file',filename);
  lFd.append('selected_type',filename.match(/\.(mp4|webm|mov)$/i)?'video':'image');
  lFd.append('was_duplicate','0');
  lFd.append('ai_generated', aiGenerated);
  lFd.append('ai_prompt',    aiPrompt);
  await fetch(S2_ENDPOINT,{method:'POST',body:lFd}).catch(()=>{});
}

// Also hook up "I Have Content" Build Video
// Store content script raw for step 2 use
const _origProcessContent = typeof processMyContent !== 'undefined' ? processMyContent : null;

</script>

<!-- ══ STEP 2: BUILD VIDEO MODAL ══ -->
<div class="s2-overlay" id="s2Overlay">
  <div class="s2-panel" id="s2Panel">
    <div class="s2-header">
      <h2>🎬 Build Video</h2>
      <button class="s2-close" id="s2CloseBtn" onclick="closeS2()">✕</button>
    </div>
    <div class="s2-body">

      <!-- Setup form (shown before start) -->
      <div id="s2Setup">
        <div class="s2-section">
          <div class="s2-label">Host Voice</div>
          <select class="s2-select" id="s2HostVoice">
            <option value="">Loading voices…</option>
          </select>
        </div>
        <div class="s2-section" id="s2GuestVoiceSection" style="display:none;">
          <div class="s2-label">Guest Voice (Podcast)</div>
          <select class="s2-select" id="s2GuestVoice">
            <option value="">— Same as host —</option>
          </select>
        </div>
        <div class="s2-section">
          <div class="s2-label">Speech Rate</div>
          <select class="s2-select" id="s2Rate">
            <option value="0.9">0.9× — Slightly slow</option>
            <option value="1.0" selected>1.0× — Normal</option>
            <option value="1.1">1.1× — Slightly fast</option>
            <option value="1.2">1.2× — Fast</option>
          </select>
        </div>
        <div class="s2-section">
          <div class="s2-label">Media Type</div>
          <div class="s2-media-opts">
            <div class="s2-media-opt sel" data-val="stock_images" onclick="selMedia(this)">📷 Stock Images</div>
            <div class="s2-media-opt" data-val="stock_videos" onclick="selMedia(this)">🎥 Stock Videos</div>
            <div class="s2-media-opt" data-val="unique_images" onclick="selMedia(this)">🤖 AI Images</div>
          </div>
        </div>
        <button class="s2-start-btn" onclick="startBuildVideo()">🚀 Build Video Now</button>
      </div>

      <!-- Progress (shown during processing) -->
      <div id="s2Progress" style="display:none;">
        <div class="s2-steps">
          <div class="s2-step" id="s2Step0">
            <span class="s2-step-icon">✨</span>
            <div>
              <div class="s2-step-title">Enhance Prompts (AI)</div>
              <div class="s2-step-sub" id="s2Step0Sub">Waiting…</div>
            </div>
          </div>
          <div class="s2-step" id="s2Step1">
            <span class="s2-step-icon">📝</span>
            <div>
              <div class="s2-step-title">Create Scenes</div>
              <div class="s2-step-sub" id="s2Step1Sub">Waiting…</div>
            </div>
          </div>
          <div class="s2-step" id="s2Step2">
            <span class="s2-step-icon">🎤</span>
            <div>
              <div class="s2-step-title">Generate Audio</div>
              <div class="s2-step-sub" id="s2Step2Sub">Waiting…</div>
            </div>
          </div>
          <div class="s2-step" id="s2Step3">
            <span class="s2-step-icon">🖼️</span>
            <div>
              <div class="s2-step-title">Assign Media</div>
              <div class="s2-step-sub" id="s2Step3Sub">Waiting…</div>
            </div>
          </div>
        </div>
        <div class="s2-log" id="s2Log"></div>
        <div id="s2DoneBar" style="display:none;" class="s2-done-bar">
          <span style="font-size:14px;font-weight:600;color:#166534;">✅ Video ready!</span>
          <a id="s2VideoLink" href="#" target="_blank">Open in VideoMaker →</a>
        </div>
      </div>

    </div>
  </div>
</div>

</body>
</html>

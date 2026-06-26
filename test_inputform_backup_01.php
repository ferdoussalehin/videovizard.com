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
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --card:      #ffffff;
  --shadow:    0 4px 12px rgba(0,0,0,0.08);
}
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── Header (matches script_gen) ── */
.vidora-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: #fff;
  box-shadow: 0 3px 10px rgba(0,0,0,0.15);
  position: sticky;
  top: 0;
  z-index: 1000;
}
.brand-link { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.brand-icon { font-size: 24px; }
.brand-name { font-size: 18px; font-weight: 700; }
.brand-video { color: #fff; }
.brand-vizard { color: #5fd1ff; }

/* ── Page container ── */
.page-wrap {
  flex: 1;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 28px 16px 48px;
}

/* ── Card ── */
.wiz-card {
  background: var(--card);
  border-radius: 16px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  width: 100%;
  max-width: 600px;
  overflow: hidden;
}
.wiz-card-header {
  padding: 18px 24px 16px;
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.wiz-card-header h1 {
  font-size: 20px;
  font-weight: 700;
  color: var(--dark-blue);
  margin: 0;
}
.wiz-card-header p {
  font-size: 13px;
  color: var(--muted);
  margin: 2px 0 0;
}
.gear-btn {
  width: 36px; height: 36px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: #fff;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; color: var(--muted);
  transition: all .15s;
  flex-shrink: 0;
}
.gear-btn:hover { background: var(--purple-lt); border-color: var(--purple); color: var(--purple); }

.wiz-card-body { padding: 24px; }

/* ── Settings bar ── */
.settings-bar {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  background: #f7f9fc; border: 1px solid var(--border);
  border-radius: 8px; padding: 8px 12px; margin-bottom: 20px;
  cursor: pointer; transition: border-color .15s;
}
.settings-bar:hover { border-color: var(--purple); }
.settings-bar-label { font-size: 11px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: .06em; margin-right: 2px; white-space: nowrap; }
.settings-bar-edit { font-size: 11px; color: var(--purple); margin-left: auto; white-space: nowrap; }
.s-pill { font-size: 11px; background: var(--purple-lt); color: #6d28d9; border-radius: 4px; padding: 2px 7px; white-space: nowrap; }

/* ── Progress bar ── */
.prog-track { height: 4px; background: var(--border); border-radius: 2px; margin-bottom: 24px; overflow: hidden; }
.prog-fill { height: 100%; background: linear-gradient(90deg, var(--dark-blue), var(--purple)); border-radius: 2px; transition: width .4s ease; }

/* ── Step meta ── */
.step-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
.step-q { font-size: 20px; font-weight: 700; color: var(--dark-blue); margin-bottom: 18px; line-height: 1.35; }

/* ── Pills ── */
.opts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.opt {
  padding: 9px 16px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--text);
  font-size: 14px; font-weight: 500;
  cursor: pointer;
  transition: all .15s;
  line-height: 1;
}
.opt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.opt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }

/* ── More button ── */
.more-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 10px 16px;
  background: #fff;
  border: 1.5px dashed var(--purple);
  border-radius: 10px;
  color: var(--purple);
  font-size: 13px; font-weight: 600;
  cursor: pointer;
  transition: all .15s;
  white-space: nowrap;
}
.more-btn:hover { background: var(--purple-lt); border-style: solid; }
.more-btn:disabled { opacity: .5; cursor: not-allowed; }
.more-btn .spin { display: inline-block; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Custom add row ── */
.custom-row { display: flex; gap: 8px; margin-bottom: 6px; }
.custom-in {
  flex: 1; padding: 9px 12px; font-size: 13px;
  border: 1.5px solid var(--border); border-radius: 8px;
  color: var(--text); outline: none; transition: border-color .15s;
  background: #fff;
}
.custom-in:focus { border-color: var(--purple); }
.custom-add {
  padding: 9px 14px; font-size: 13px;
  background: #f5f5f5; border: 1.5px solid var(--border);
  border-radius: 8px; color: var(--muted); cursor: pointer;
  white-space: nowrap; transition: all .15s;
}
.custom-add:hover { background: var(--purple-lt); color: var(--purple); border-color: var(--purple); }

/* ── Loading dots ── */
.loading { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; padding: 16px 0; }
.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--purple); animation: blink 1.2s ease-in-out infinite; }
.dot:nth-child(2) { animation-delay: .2s; }
.dot:nth-child(3) { animation-delay: .4s; }
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }

/* ── Nav ── */
.nav { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f0f0f0; }
.nav-back { font-size: 13px; color: var(--muted); cursor: pointer; padding: 8px 0; background: none; border: none; transition: color .15s; }
.nav-back:hover { color: var(--text); }
.nav-next {
  padding: 11px 28px;
  background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue));
  color: #fff; border: none; border-radius: 10px;
  font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s;
}
.nav-next:hover { background: linear-gradient(135deg, var(--mid-blue), #1e4a7a); box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.nav-next:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; box-shadow: none; }

/* ── Summary ── */
.summary { background: #f7f9fc; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; margin-top: 4px; }
.sum-section { font-size: 10px; font-weight: 700; color: #bbb; text-transform: uppercase; letter-spacing: .07em; margin: 14px 0 6px; }
.sum-section:first-child { margin-top: 0; }
.sum-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 7px 0; border-bottom: 1px solid #eef0f3; font-size: 13px; gap: 16px; }
.sum-row:last-child { border-bottom: none; }
.sum-key { color: var(--muted); white-space: nowrap; }
.sum-val { color: var(--dark-blue); font-weight: 600; text-align: right; }

.done-title { font-size: 22px; font-weight: 700; color: var(--dark-blue); margin-bottom: 6px; }
.done-sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; }

.gen-btn {
  margin-top: 16px; width: 100%; padding: 14px;
  background: linear-gradient(135deg, var(--green), #059669);
  color: #fff; border: none; border-radius: 10px;
  font-size: 15px; font-weight: 700; cursor: pointer; transition: all .15s;
}
.gen-btn:hover { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 4px 12px rgba(16,185,129,.3); }
.gen-btn:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; box-shadow: none; }

.restart-btn {
  margin-top: 10px; width: 100%; padding: 11px;
  background: #fff; color: var(--muted);
  border: 1.5px solid var(--border); border-radius: 10px;
  font-size: 14px; font-weight: 500; cursor: pointer; transition: all .15s;
}
.restart-btn:hover { border-color: var(--purple); color: var(--purple); }

.script-box {
  background: #f7f9fc; border: 1px solid var(--border); border-radius: 10px;
  padding: 16px 20px; font-size: 14px; line-height: 1.8;
  color: var(--text); white-space: pre-wrap; margin-top: 4px;
}
.copy-btn {
  margin-top: 8px; width: 100%; padding: 10px;
  background: #fff; color: var(--purple);
  border: 1.5px solid var(--purple); border-radius: 10px;
  font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;
}
.copy-btn:hover { background: var(--purple-lt); }

/* ── Settings overlay ── */
.settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.settings-overlay.open { display: flex; }
.settings-panel { background: #fff; border-radius: 16px; padding: 28px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.settings-title { font-size: 17px; font-weight: 700; color: var(--dark-blue); }
.settings-close { background: none; border: none; font-size: 22px; color: var(--muted); cursor: pointer; padding: 0 4px; }
.settings-close:hover { color: var(--text); }
.setting-group { margin-bottom: 20px; }
.setting-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
.setting-opts { display: flex; flex-wrap: wrap; gap: 7px; }
.sopt { padding: 7px 13px; border: 1.5px solid var(--border); border-radius: 7px; background: #fff; color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.sopt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.sopt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }
.settings-save { margin-top: 8px; width: 100%; padding: 13px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
.settings-save:hover { opacity: .9; }
.settings-hint { font-size: 12px; color: #bbb; margin-top: 10px; text-align: center; }

/* ── Mode selection cards ── */
.mode-card {
  flex: 1; min-width: 180px;
  border: 1.5px solid var(--border); border-radius: 16px;
  padding: 22px 18px; background: var(--card);
  cursor: pointer; transition: all .2s;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.mode-card:hover { border-color: var(--purple); box-shadow: 0 6px 20px rgba(139,92,246,0.12); transform: translateY(-2px); }
.mode-card-icon  { font-size: 32px; margin-bottom: 10px; }
.mode-card-title { font-size: 15px; font-weight: 700; color: var(--dark-blue); margin-bottom: 8px; }
.mode-card-desc  { font-size: 13px; color: var(--muted); line-height: 1.5; margin-bottom: 12px; }
.mode-card-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
.back-to-menu { background: none; border: none; color: var(--muted); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; transition: color .15s; }
.back-to-menu:hover { color: var(--dark-blue); }
.field-label { display: block; font-size: 13px; font-weight: 600; color: var(--dark-blue); margin-bottom: 6px; }

/* ── Toast ── */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--dark-blue); color: #fff; padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600; z-index: 999; transition: opacity .3s; pointer-events: none; }

/* ── Footer ── */
.site-footer {
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: rgba(255,255,255,.5);
  padding: 14px 20px;
  font-size: 12px;
  display: flex; justify-content: center; align-items: center; gap: 24px;
  flex-wrap: wrap;
}
.site-footer a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.site-footer a:hover { color: var(--accent); }
.footer-brand { font-weight: 700; color: var(--accent); }
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

<!-- Settings overlay -->
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

  <!-- ══ THREE-CARD SELECTION (shown first) ══ -->
  <div id="modeSelect" style="width:100%; max-width:700px;">
    <div style="text-align:center; margin-bottom:24px;">
      <h1 style="font-size:26px; font-weight:800; color:var(--dark-blue); margin-bottom:6px;">🎬 VideoVizard</h1>
      <p style="font-size:14px; color:var(--muted);">How would you like to create your video script?</p>
    </div>
    <div style="display:flex; gap:16px; flex-wrap:wrap;">

      <!-- Card 1: Generate Video Script -->
      <div class="mode-card" onclick="selectMode('wizard')">
        <div class="mode-card-icon">✨</div>
        <div class="mode-card-title">Generate Video Script</div>
        <div class="mode-card-desc">Answer a few questions — AI writes a complete, ready-to-use video script for you.</div>
        <div class="mode-card-badge" style="background:#ede9fe; color:#6d28d9;">Best for new ideas</div>
      </div>

      <!-- Card 2: Generate Content Bank -->
      <div class="mode-card" onclick="selectMode('bank')">
        <div class="mode-card-icon">📚</div>
        <div class="mode-card-title">Generate Content Bank</div>
        <div class="mode-card-desc">Build a library of niches, topics and titles. AI generates content ideas you can reuse anytime.</div>
        <div class="mode-card-badge" style="background:#dcfce7; color:#166534;">Best for planned content</div>
      </div>

      <!-- Card 3: I Have Content -->
      <div class="mode-card" onclick="selectMode('content')">
        <div class="mode-card-icon">📄</div>
        <div class="mode-card-title">I Have Content</div>
        <div class="mode-card-desc">Paste your own text or script — it gets formatted and split into scenes automatically.</div>
        <div class="mode-card-badge" style="background:#dbeafe; color:#1e40af;">Best for existing content</div>
      </div>

    </div>
  </div>

  <!-- ══ MODE: GENERATE VIDEO SCRIPT (wizard) ══ -->
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
        <!-- Settings bar -->
        <div class="settings-bar" id="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="settings-bar-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <!-- Progress -->
        <div class="prog-track"><div class="prog-fill" id="prog"></div></div>
        <!-- Step meta -->
        <div id="step-label" class="step-label"></div>
        <div id="step-q" class="step-q"></div>
        <!-- Step body -->
        <div id="step-body"></div>
        <!-- Nav -->
        <div class="nav" id="nav-bar">
          <button class="nav-back" id="backBtn" onclick="goBack()">← Back</button>
          <button class="nav-next" id="nextBtn" disabled onclick="goNext()">Continue →</button>
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

        <!-- Settings bar -->
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

  <!-- ══ MODE: CONTENT BANK (placeholder) ══ -->
  <div id="modeBank" style="display:none; width:100%; max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div>
          <h1>📚 Generate Content Bank</h1>
          <p>Build your niche, topic and title library</p>
        </div>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:20px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
        </div>
        <div style="text-align:center; padding:40px 20px; color:var(--muted);">
          <div style="font-size:48px; margin-bottom:16px;">🚧</div>
          <div style="font-size:16px; font-weight:600; color:var(--dark-blue); margin-bottom:8px;">Coming Soon</div>
          <div style="font-size:14px;">Content Bank builder is under development.<br>Check back soon!</div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Footer -->
<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="script_gen.php">Script Generator</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<script>
/* ═══════════════════════════════════════════════
   WIZARD STEPS  (6 steps)
═══════════════════════════════════════════════ */
const STEPS = [
  {
    key: 'niche', label: 'Step 1 of 6', q: 'Select your niche or profession',
    type: 'opts+more',
    opts: ['Hypnotherapy','Real Estate','Hair Dressing','Nail Parlour','Financial Adviser',
           'Physiotherapist','Life Coaching','Personal Training','Dentistry','Mortgage Broker'],
    morePrompt: (existing) =>
      `You are a content strategy expert. List 10 MORE professional niche ideas for short social media videos.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of short niche name strings.
       Example: ["Nutritionist","Yoga Instructor","Chiropractor"]`
  },
  {
    key: 'topic', label: 'Step 2 of 6', q: 'Select a category',
    type: 'ai',
    aiUrl: 'generate_categories.php', aiPayload: ['niche'], resKey: 'categories',
    morePrompt: (existing, ans) =>
      `You are a content strategy expert for the niche: "${ans.niche}".
       List 8 MORE broad content categories for short social media videos in this niche.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of category name strings.`
  },
  {
    key: 'title', label: 'Step 3 of 6', q: 'Choose a video idea',
    type: 'ai',
    aiUrl: 'generate_titles.php', aiPayload: ['niche','topic'], resKey: 'titles',
    morePrompt: (existing, ans) =>
      `You are an expert short-form video content creator for the niche "${ans.niche}", category "${ans.topic}".
       Generate 8 MORE specific, engaging video topic ideas for Reels/TikTok/Shorts.
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of title strings.`
  },
  {
    key: 'angle', label: 'Step 4 of 6', q: 'What hook or angle will you use?',
    type: 'opts+more',
    opts: ['Quick Hacks','Step-by-Step','Common Mistakes','Surprising Secrets','Before & After',
           'Myth Busting','Did You Know?','Storytime','Controversial Opinion','Top 5 List',
           'FAQs Answered','Behind the Scenes','Client Transformation',
           'Warning / What to Avoid','Industry Trends'],
    morePrompt: (existing) =>
      `You are an expert short-form video content strategist.
       List 10 MORE creative hook or angle ideas for short social media videos (Reels, TikTok, Shorts).
       Do NOT repeat any of these: ${existing.join(', ')}.
       Return ONLY a valid JSON array of short hook/angle name strings.
       Example: ["Hot Take","Reaction Video","Day in the Life","Listicle","Challenge Format"]`
  },
  {
    key: 'duration', label: 'Step 5 of 6', q: 'How long is this video?',
    type: 'opts',
    opts: ['15 seconds','30 seconds','60 seconds','90 seconds']
  },
  {
    key: 'cta', label: 'Step 6 of 6', q: 'What should viewers do next?',
    type: 'opts',
    opts: ['Follow for More','Subscribe','Book a Free Call','Visit Website','Download Guide']
  },
];

const WIZARD_LABELS = {
  niche:'Niche', topic:'Category', title:'Video Idea',
  angle:'Angle / Hook', duration:'Duration', cta:'Call to Action'
};

/* ═══════════════════════════════════════════════
   SETTINGS
═══════════════════════════════════════════════ */
const SETTING_DEFS = {
  language:  { opts:['English','Spanish','French','Arabic','Hindi','Urdu','Punjabi','Portuguese','Mandarin','German'], def:'English' },
  reel_type: { opts:['Standard (Talking Head)','B-Roll (Voiceover)','Podcast Style'],                                def:'Standard (Talking Head)' },
  format:    { opts:['9:16 Vertical (Reels / TikTok / Shorts)','1:1 Square (Feed)','16:9 Landscape (YouTube)'],      def:'9:16 Vertical (Reels / TikTok / Shorts)' },
  objective: { opts:['Educate','Inspire','Entertain','Inform','Build Trust'],                                         def:'Educate' },
  audience:  { opts:['Complete Beginners','Intermediate Learners','Professionals','General Public','Business Owners'],def:'General Public' },
};
const SETTING_LABELS = { language:'Language', reel_type:'Reel Type', format:'Format', objective:'Objective', audience:'Audience' };

let settings = {}, cur = 0, ans = {};
// Per-step option lists (grow as user loads more)
let stepOpts = {};

/* ── Settings helpers ── */
function loadSettings() {
  try { settings = JSON.parse(localStorage.getItem('vw_settings') || '{}'); } catch(e) { settings = {}; }
  Object.keys(SETTING_DEFS).forEach(k => { if (!settings[k]) settings[k] = SETTING_DEFS[k].def; });
  renderSettingsBar();
}

function renderSettingsBar() {
  const short = {
    language:  settings.language,
    reel_type: settings.reel_type.split(' (')[0],
    format:    settings.format.split(' ')[0],
    objective: settings.objective,
    audience:  settings.audience.replace(' Learners','').replace('Complete ','').replace('Business ','Biz ')
  };
  const pillsHtml = Object.values(short).map(v => `<span class="s-pill">${v}</span>`).join('');
  const el = document.getElementById('settings-bar-pills');
  if (el) el.innerHTML = pillsHtml;
  renderContentSettingsPills();
}

function openSettings() {
  Object.entries(SETTING_DEFS).forEach(([k, def]) => {
    document.getElementById('s-' + k).innerHTML = def.opts.map(o =>
      `<div class="sopt${settings[k]===o?' sel':''}" data-v="${o}" onclick="selectSopt(this,'${k}')">${o}</div>`
    ).join('');
  });
  document.getElementById('settingsOverlay').classList.add('open');
}

function selectSopt(el, key) {
  document.querySelectorAll('#s-' + key + ' .sopt').forEach(x => x.classList.remove('sel'));
  el.classList.add('sel');
}

function saveSettings() {
  Object.keys(SETTING_DEFS).forEach(k => {
    const sel = document.querySelector('#s-' + k + ' .sopt.sel');
    if (sel) settings[k] = sel.dataset.v;
  });
  localStorage.setItem('vw_settings', JSON.stringify(settings));
  closeSettings();
  renderSettingsBar();
  showToast('Settings saved ✓');
}

function closeSettings() { document.getElementById('settingsOverlay').classList.remove('open'); }
function overlayClick(e) { if (e.target === document.getElementById('settingsOverlay')) closeSettings(); }

function showToast(msg) {
  const t = Object.assign(document.createElement('div'), { className: 'toast', textContent: msg });
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 1800);
}

/* ═══════════════════════════════════════════════
   RENDER ENGINE
═══════════════════════════════════════════════ */
function setNext(v) { document.getElementById('nextBtn').disabled = !v; }
function setBack()  { document.getElementById('backBtn').style.visibility = cur === 0 ? 'hidden' : 'visible'; }

async function render() {
  document.getElementById('prog').style.width = Math.round((cur / STEPS.length) * 100) + '%';
  setBack(); setNext(false);
  const s = STEPS[cur];
  document.getElementById('step-label').textContent = s.label;
  document.getElementById('step-q').textContent     = s.q;
  // Update card header subtitle with context
  updateCardSubtitle();

  if (s.type === 'opts' || s.type === 'opts+more') {
    if (!stepOpts[s.key]) stepOpts[s.key] = [...s.opts];
    renderOpts(stepOpts[s.key], s.key, s.type === 'opts+more');
  } else if (s.type === 'ai') {
    await renderAI(s);
  }
}

function updateCardSubtitle() {
  const parts = [];
  if (ans.niche)  parts.push(ans.niche);
  if (ans.topic)  parts.push(ans.topic);
  if (ans.title)  parts.push(ans.title);
  const sub = document.getElementById('cardSubtitle');
  sub.textContent = parts.length ? parts.join(' › ') : 'Answer a few questions to generate your video script';
}

/* ── Render options pills ── */
function renderOpts(opts, key, showMoreBtn = false) {
  const body = document.getElementById('step-body');

  let html = `<div class="opts" id="opts-wrap">` +
    opts.map(o => `<div class="opt${ans[key]===o?' sel':''}" data-v="${esc(o)}">${o}</div>`).join('') +
  `</div>`;

  // Custom add row
  html += `<div class="custom-row">
    <input class="custom-in" id="cust-in" placeholder="Or type your own…">
    <button class="custom-add" id="cust-btn">Add</button>
  </div>`;

  body.innerHTML = html;

  document.querySelectorAll('.opt').forEach(b => {
    b.onclick = () => {
      const prevVal = ans[key];
      document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
      b.classList.add('sel');
      ans[key] = b.dataset.v;
      // FIX 3: Reset downstream steps when niche or category changes
      if (key === 'niche' && prevVal && prevVal !== ans[key]) {
        delete ans.topic; delete ans.title;
        delete stepOpts.topic; delete stepOpts.title;
      }
      if (key === 'topic' && prevVal && prevVal !== ans[key]) {
        delete ans.title;
        delete stepOpts.title;
      }
      setNext(true);
    };
  });
  document.getElementById('cust-btn').onclick = () => addCustom(key);
  document.getElementById('cust-in').onkeydown = e => { if (e.key === 'Enter') addCustom(key); };
  if (ans[key]) setNext(true);

  // FIX 2: Move more-btn into the nav bar (right side, before Continue)
  const navBar = document.getElementById('nav-bar');
  // Remove any old more-btn that was previously injected into nav
  const oldMore = document.getElementById('more-btn');
  if (oldMore) oldMore.remove();
  if (showMoreBtn) {
    const moreBtn = document.createElement('button');
    moreBtn.id = 'more-btn';
    moreBtn.className = 'more-btn';
    moreBtn.innerHTML = '<span>+</span> More';
    moreBtn.onclick = loadMore;
    // Insert before the Continue button
    const nextBtn = document.getElementById('nextBtn');
    navBar.insertBefore(moreBtn, nextBtn);
  }
}

function addCustom(key) {
  const inp = document.getElementById('cust-in');
  const v   = inp.value.trim();
  if (!v) return;
  inp.value = '';
  if (!stepOpts[key]) stepOpts[key] = [];
  if (!stepOpts[key].includes(v)) stepOpts[key].push(v);
  document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
  const wrap = document.getElementById('opts-wrap');
  const b = document.createElement('div');
  b.className = 'opt sel'; b.dataset.v = v; b.textContent = v;
  b.onclick = () => {
    document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
    b.classList.add('sel'); ans[key] = v; setNext(true);
  };
  wrap.appendChild(b);
  ans[key] = v; setNext(true);
}

/* ── AI fetch ── */
async function renderAI(s) {
  // FIX 1: Clear existing options for this step so fresh AI results load (not stale cache)
  // Only clear if we have no options yet (first load) OR if parent changed (handled by reset above)
  const alreadyHasOpts = stepOpts[s.key] && stepOpts[s.key].length > 0;

  if (alreadyHasOpts) {
    // Show cached options immediately (user navigated back then forward)
    renderOpts(stepOpts[s.key], s.key, true);
    return;
  }

  document.getElementById('step-body').innerHTML =
    `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Asking AI…</span></div>`;
  // Also clear the more-btn from nav during loading
  const oldMore = document.getElementById('more-btn');
  if (oldMore) oldMore.remove();

  const payload = {};
  (s.aiPayload || []).forEach(k => { payload[k] = ans[k]; });
  try {
    const r = await fetch(s.aiUrl, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const d = await r.json();
    const items = d[s.resKey] || [];
    if (!items.length) throw new Error('empty');
    stepOpts[s.key] = [...items];
    renderOpts(stepOpts[s.key], s.key, true);
  } catch(e) {
    document.getElementById('step-body').innerHTML =
      `<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load suggestions — add your own below</div>` +
      `<div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Type here…"><button class="custom-add" id="cust-btn">Add</button></div>`;
    document.getElementById('cust-btn').onclick = () => addCustom(s.key);
    document.getElementById('cust-in').onkeydown = e => { if (e.key==='Enter') addCustom(s.key); };
  }
}

/* ── Load More ── */
async function loadMore() {
  const s   = STEPS[cur];
  const btn = document.getElementById('more-btn');
  if (!btn) return;

  // If step has no morePrompt, nothing to do
  if (!s.morePrompt) {
    btn.textContent = 'No more'; btn.disabled = true; return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spin">⟳</span> Loading…';

  if (!stepOpts[s.key]) stepOpts[s.key] = [];
  const existing = [...stepOpts[s.key]]; // snapshot before mutation
  const prompt   = s.morePrompt(existing, ans);

  try {
    const r = await fetch('/generate_more_opts.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ prompt })
    });

    if (!r.ok) throw new Error('HTTP ' + r.status);

    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e) { throw new Error('Bad JSON: ' + text.substring(0,100)); }

    if (!d.success) throw new Error(d.error || 'Server error');

    const newItems = Array.isArray(d.items) ? d.items : [];
    let added = 0;
    newItems.forEach(item => {
      const clean = String(item).trim();
      if (clean && !stepOpts[s.key].includes(clean)) {
        stepOpts[s.key].push(clean);
        added++;
      }
    });

    // Re-render pills — this removes and re-adds the More button
    renderOpts(stepOpts[s.key], s.key, true);

    // If nothing new was found, mark the button
    if (added === 0) {
      const b = document.getElementById('more-btn');
      if (b) { b.textContent = 'No more'; b.disabled = true; }
    }

  } catch(e) {
    console.error('loadMore error:', e);
    // Restore button state
    const b = document.getElementById('more-btn');
    if (b) { b.disabled = false; b.innerHTML = '<span>+</span> More'; }
    showToast('Could not load more: ' + e.message);
  }
}

/* ═══════════════════════════════════════════════
   NAVIGATION
═══════════════════════════════════════════════ */
function goNext() { if (cur < STEPS.length - 1) { cur++; clearMoreBtn(); render(); } else showSummary(); }
function goBack()  { if (cur > 0) { cur--; clearMoreBtn(); render(); } }
function clearMoreBtn() { const b = document.getElementById('more-btn'); if (b) b.remove(); }

/* ═══════════════════════════════════════════════
   SUMMARY + SCRIPT GENERATION
═══════════════════════════════════════════════ */
function showSummary() {
  document.getElementById('prog').style.width = '100%';
  document.getElementById('step-label').textContent = 'Ready';
  document.getElementById('step-q').textContent = '';
  document.getElementById('nav-bar').style.display = 'none';
  document.getElementById('cardTitle').textContent = '📋 Your Video Brief';
  document.getElementById('cardSubtitle').textContent = 'Review then generate your script';

  const wizRows = Object.entries(ans).map(([k,v]) =>
    `<div class="sum-row"><span class="sum-key">${WIZARD_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`
  ).join('');
  const setRows = Object.entries(settings).map(([k,v]) =>
    `<div class="sum-row"><span class="sum-key">${SETTING_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`
  ).join('');

  document.getElementById('step-body').innerHTML = `
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

async function generateScript() {
  const btn = document.getElementById('gen-btn');
  const out  = document.getElementById('script-output');
  btn.disabled = true; btn.textContent = '⏳ Generating…';
  out.innerHTML = `<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Writing your script…</span></div>`;

  try {
    const r = await fetch('generate_script.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(Object.assign({}, ans, settings))
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Script generation failed');
    out.innerHTML = `
      <div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Generated Script</div>
      <div class="script-box" id="script-text">${escHtml(d.script)}</div>
      <button class="copy-btn" onclick="copyScript()">📋 Copy Script</button>`;
    btn.textContent = '🔄 Regenerate'; btn.disabled = false;
  } catch(e) {
    out.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0">Error: ${e.message}</div>`;
    btn.textContent = 'Try Again →'; btn.disabled = false;
  }
}

function copyScript() {
  const el = document.getElementById('script-text'); if (!el) return;
  navigator.clipboard.writeText(el.innerText).then(() => {
    const b = document.querySelector('.copy-btn');
    if (b) { b.textContent = '✓ Copied!'; setTimeout(() => b.textContent = '📋 Copy Script', 2000); }
  });
}

function restart() {
  cur = 0; ans = {}; stepOpts = {};
  clearMoreBtn();
  goToMenu();
}

/* ═══════════════════════════════════════════════
   MODE SWITCHING
═══════════════════════════════════════════════ */
function selectMode(mode) {
  document.getElementById('modeSelect').style.display  = 'none';
  document.getElementById('modeWizard').style.display  = 'none';
  document.getElementById('modeContent').style.display = 'none';
  document.getElementById('modeBank').style.display    = 'none';

  if (mode === 'wizard') {
    document.getElementById('modeWizard').style.display = 'block';
    // Reset wizard state fresh each time
    cur = 0; ans = {}; stepOpts = {};
    clearMoreBtn();
    document.getElementById('nav-bar').style.display = 'flex';
    document.getElementById('cardTitle').textContent    = '✨ Generate Video Script';
    document.getElementById('cardSubtitle').textContent = 'Answer a few questions to generate your video script';
    render();
  } else if (mode === 'content') {
    document.getElementById('modeContent').style.display = 'block';
    renderContentSettingsPills();
  } else if (mode === 'bank') {
    document.getElementById('modeBank').style.display = 'block';
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goToMenu() {
  document.getElementById('modeWizard').style.display  = 'none';
  document.getElementById('modeContent').style.display = 'none';
  document.getElementById('modeBank').style.display    = 'none';
  document.getElementById('modeSelect').style.display  = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ═══════════════════════════════════════════════
   I HAVE CONTENT — settings pills sync
═══════════════════════════════════════════════ */
function renderContentSettingsPills() {
  const el = document.getElementById('content-settings-pills');
  if (!el) return;
  const short = {
    language:  settings.language,
    reel_type: settings.reel_type.split(' (')[0],
    format:    settings.format.split(' ')[0],
  };
  el.innerHTML = Object.values(short).map(v => `<span class="s-pill">${v}</span>`).join('');
}

/* ═══════════════════════════════════════════════
   I HAVE CONTENT — process
═══════════════════════════════════════════════ */
async function processMyContent() {
  const btn    = document.getElementById('content-process-btn');
  const out    = document.getElementById('content-script-output');
  const title  = document.getElementById('content-title').value.trim();
  const script = document.getElementById('content-script').value.trim();
  const cta    = document.getElementById('content-cta').value.trim();

  if (!title)  { alert('Please enter a video title'); return; }
  if (!script) { alert('Please paste your script or content'); return; }

  btn.disabled = true; btn.textContent = '⏳ Processing…';
  out.innerHTML = `<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Formatting your script into scenes…</span></div>`;

  const reelType = settings.reel_type.toLowerCase().includes('b-roll') ? 'broll'
                 : settings.reel_type.toLowerCase().includes('podcast') ? 'podcast'
                 : 'standard';
  const langName = settings.language;

  const prompt = `You are an expert video script formatter.

Take the following content and reformat it as a short-form ${reelType} video script.

TITLE: ${title}
LANGUAGE: ${langName}
REEL TYPE: ${reelType}
CALL TO ACTION: ${cta}

ORIGINAL CONTENT:
${script}

FORMAT RULES:
${reelType === 'broll' ? `- Write as voiceover narration
- After each sentence add a scene note in [square brackets] describing B-roll footage` :
reelType === 'podcast' ? `- Format as HOST: / GUEST: dialogue
- Keep it conversational and natural` :
`- Format as direct-to-camera monologue
- Short punchy sentences
- Add <break time="250ms"/> after each sentence`}

- Write in ${langName}
- End with a natural CTA: ${cta}
- Do NOT include any labels like "Hook:" or "CTA:" — just the clean script

OUTPUT the formatted script only, nothing else.`;

  try {
    const r = await fetch('generate_script.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        niche: 'custom', title, objective: 'Inform',
        audience: 'General Public', angle: 'Storytelling',
        duration: '60 seconds', cta, language: langName,
        reel_type: settings.reel_type, format: settings.format,
        _custom_prompt: prompt
      })
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Processing failed');
    out.innerHTML = `
      <div style="margin:20px 0 8px; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em;">Formatted Script</div>
      <div class="script-box" id="content-script-text">${escHtml(d.script)}</div>
      <button class="copy-btn" onclick="copyContentScript()" style="margin-top:8px;">📋 Copy Script</button>`;
    btn.textContent = '🔄 Reprocess'; btn.disabled = false;
  } catch(e) {
    out.innerHTML = `<div style="color:#c00; font-size:13px; margin:12px 0;">Error: ${e.message}</div>`;
    btn.textContent = '📝 Process Content'; btn.disabled = false;
  }
}

function copyContentScript() {
  const el = document.getElementById('content-script-text'); if (!el) return;
  navigator.clipboard.writeText(el.innerText).then(() => {
    const b = document.querySelector('#content-script-output .copy-btn');
    if (b) { b.textContent = '✓ Copied!'; setTimeout(() => b.textContent = '📋 Copy Script', 2000); }
  });
}
function esc(s)    { return String(s).replace(/"/g,'&quot;'); }
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ── Init ── */
loadSettings();
// Show the mode selection menu on load (not the wizard directly)
document.getElementById('modeWizard').style.display  = 'none';
document.getElementById('modeContent').style.display = 'none';
document.getElementById('modeBank').style.display    = 'none';
document.getElementById('modeSelect').style.display  = 'block';
</script>
</body>
</html>

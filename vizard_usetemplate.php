<?php
/**
 * vizard_ai_clone.php
 * ─────────────────────────────────────────────────────────────────────────────
 * "Let AI Create Content For Me" wizard.
 *
 * User flow:
 *   1. Enter  Niche → Category → Video Idea → What should viewers do next? (CTA)
 *      (angle/hook and duration are taken from the source podcast automatically)
 *   2. AI generates script  →  user approves / edits
 *   3. "Build Video" opens a modal that:
 *      a. Generates audio for each scene (TTS)
 *      b. Copies media from the source podcast scene-by-scene
 *      c. For any extra scenes (new video longer than original) → stock fallback
 *
 * COMPLETELY SEPARATE from vizard_scriptgen.php — zero shared state.
 * Backend: wizard_ai_clone_step2.php
 */

session_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }

include 'dbconnect_hdb.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// ── AJAX: Video quota ─────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clone_get_quota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT video_count, max_videos_allowed, plan_type FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    echo json_encode([
        'success'            => true,
        'video_count'        => (int)($row['video_count']        ?? 0),
        'max_videos_allowed' => (int)($row['max_videos_allowed'] ?? 3),
        'plan_type'          => $row['plan_type'] ?? 'free_trial',
    ]);
    exit;
}

// ── AJAX: Get voices ──────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clone_get_voices') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $lang_code = preg_replace('/[^a-z]/', '', strtolower(trim($_POST['lang_code'] ?? 'en')));
    // Delegate to existing get_voices endpoint format
    $q  = mysqli_query($conn,
        "SELECT voice_id, voice_name, sample_voice, voice_source
         FROM hdb_voices
         WHERE lang_code='$lang_code' OR lang_code LIKE '{$lang_code}%'
         ORDER BY is_featured DESC, voice_name ASC LIMIT 30");
    $voices = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) $voices[] = $r;
    echo json_encode(['success'=>true,'voices'=>$voices]);
    exit;
}

$plan_row      = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT plan_type FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');
$js_free_trial = $is_free_trial ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — AI Content Creator</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Shared tokens (same palette as vizard_scriptgen.php) ── */
*{box-sizing:border-box;margin:0;padding:0;}
:root{
  --dark-blue:#0f2a44; --mid-blue:#143b63; --accent:#5fd1ff;
  --purple:#8b5cf6;    --purple-lt:#ede9fe; --green:#10b981;
  --orange:#f59e0b;    --orange-lt:#fef3c7; --text:#1e293b;
  --muted:#64748b;     --border:#e2e8f0;    --bg:#f8fafc;
  --card:#ffffff;      --shadow:0 4px 12px rgba(0,0,0,0.08);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
/* ── Header ── */
.vv-header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;background:linear-gradient(90deg,#0f2a44,#143b63);color:#fff;box-shadow:0 3px 10px rgba(0,0,0,.15);position:sticky;top:0;z-index:1000;}
.brand-link{text-decoration:none;display:flex;align-items:center;gap:8px;}
.brand-icon{font-size:24px;}
.brand-name{font-size:18px;font-weight:700;}
.brand-video{color:#fff;} .brand-vizard{color:#5fd1ff;}
/* ── Page wrapper ── */
.page-wrap{flex:1;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px 48px;}
/* ── Wizard card ── */
.wiz-card{background:var(--card);border-radius:16px;border:1px solid var(--border);box-shadow:var(--shadow);width:100%;max-width:600px;overflow:hidden;}
.wiz-card-header{padding:18px 24px 16px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid var(--border);}
.wiz-card-header h1{font-size:20px;font-weight:700;color:var(--dark-blue);margin:0;}
.wiz-card-header p{font-size:13px;color:var(--muted);margin:2px 0 0;}
.wiz-card-body{padding:24px;}
/* ── Progress bar ── */
.prog-track{height:4px;background:var(--border);border-radius:2px;margin-bottom:24px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--dark-blue),var(--purple));border-radius:2px;transition:width .4s ease;}
/* ── Step label / question ── */
.step-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
.step-q{font-size:20px;font-weight:700;color:var(--dark-blue);margin-bottom:18px;line-height:1.35;}
/* ── Option chips ── */
.opts{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
.opt{padding:9px 16px;border:1.5px solid var(--border);border-radius:8px;background:#fff;color:var(--text);font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;line-height:1;}
.opt:hover{border-color:var(--purple);color:var(--purple);background:var(--purple-lt);}
.opt.sel{background:var(--purple-lt);border-color:var(--purple);color:#5b21b6;font-weight:600;}
/* ── Custom input ── */
.custom-row{display:flex;gap:8px;margin-bottom:6px;}
.custom-in{flex:1;padding:9px 12px;font-size:13px;border:1.5px solid var(--border);border-radius:8px;color:var(--text);outline:none;transition:border-color .15s;background:#fff;}
.custom-in:focus{border-color:var(--purple);}
.custom-add{padding:9px 14px;font-size:13px;background:#f5f5f5;border:1.5px solid var(--border);border-radius:8px;color:var(--muted);cursor:pointer;white-space:nowrap;transition:all .15s;}
.custom-add:hover{background:var(--purple-lt);color:var(--purple);border-color:var(--purple);}
/* ── Nav bar ── */
.nav{display:flex;align-items:center;justify-content:space-between;margin-top:24px;padding-top:20px;border-top:1px solid #f0f0f0;}
.nav-back{font-size:13px;color:var(--muted);cursor:pointer;padding:8px 0;background:none;border:none;transition:color .15s;}
.nav-back:hover{color:var(--text);}
.nav-next{padding:11px 28px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s;}
.nav-next:hover{box-shadow:0 4px 12px rgba(15,42,68,.3);}
.nav-next:disabled{background:var(--border);color:var(--muted);cursor:not-allowed;box-shadow:none;}
/* ── Loading dots ── */
.loading{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:14px;padding:16px 0;}
.dot{width:6px;height:6px;border-radius:50%;background:var(--purple);animation:blink 1.2s ease-in-out infinite;}
.dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
@keyframes blink{0%,80%,100%{opacity:.2}40%{opacity:1}}
/* ── Summary card ── */
.summary{background:#f7f9fc;border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-top:4px;}
.sum-row{display:flex;justify-content:space-between;align-items:flex-start;padding:7px 0;border-bottom:1px solid #eef0f3;font-size:13px;gap:16px;}
.sum-row:last-child{border-bottom:none;}
.sum-key{color:var(--muted);white-space:nowrap;}
.sum-val{color:var(--dark-blue);font-weight:600;text-align:right;}
/* ── Buttons ── */
.gen-btn,.build-btn{margin-top:10px;width:100%;padding:14px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;}
.gen-btn:hover,.build-btn:hover{box-shadow:0 4px 12px rgba(15,42,68,.3);}
.gen-btn:disabled,.build-btn:disabled{background:var(--border);color:var(--muted);cursor:not-allowed;box-shadow:none;}
.restart-btn{margin-top:8px;width:100%;padding:11px;background:#fff;color:var(--muted);border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;}
.restart-btn:hover{border-color:var(--purple);color:var(--purple);}
.back-to-browser{background:none;border:none;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:4px;transition:color .15s;margin-bottom:16px;}
.back-to-browser:hover{color:var(--dark-blue);}
/* ── Script textarea ── */
.script-box{width:100%;min-height:200px;padding:14px;border:1.5px solid var(--border);border-radius:10px;font-family:monospace;font-size:13px;line-height:1.8;resize:vertical;outline:none;background:#f8fafc;color:var(--text);}
.script-box:focus{border-color:var(--purple);}
/* ── Info banner ── */
.info-banner{display:flex;align-items:flex-start;gap:10px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#1e3a5f;line-height:1.6;}
.info-banner .icon{font-size:16px;flex-shrink:0;margin-top:1px;}
/* ── Toast ── */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--dark-blue);color:#fff;padding:10px 22px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;pointer-events:none;}
/* ── Build modal ── */
.s2-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;}
.s2-overlay.open{display:flex;}
.s2-panel{background:#fff;border-radius:16px;width:100%;max-width:540px;display:flex;flex-direction:column;overflow:hidden;margin:20px 0;box-shadow:0 12px 40px rgba(0,0,0,.25);}
.s2-header{padding:14px 20px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.s2-header h2{font-size:17px;font-weight:700;color:var(--dark-blue);margin:0;}
.s2-body{padding:16px 20px;display:flex;flex-direction:column;gap:0;}
.s2-section{margin-bottom:14px;}
.s2-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;}
.s2-select{width:100%;padding:9px 12px;font-size:13px;border:1.5px solid var(--border);border-radius:8px;background:#fff;color:var(--text);outline:none;transition:border-color .15s;}
.s2-select:focus{border-color:var(--purple);}
.s2-media-opts{display:flex;gap:8px;flex-wrap:wrap;}
.s2-media-opt{padding:8px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;}
.s2-media-opt:hover{border-color:var(--purple);color:var(--purple);background:var(--purple-lt);}
.s2-media-opt.sel{background:var(--purple-lt);border-color:var(--purple);color:#5b21b6;font-weight:600;}
.s2-start-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;margin-top:4px;}
.s2-start-btn:hover{box-shadow:0 4px 12px rgba(15,42,68,.3);}
.s2-close{background:none;border:none;font-size:22px;color:var(--muted);cursor:pointer;}
.s2-steps{display:flex;flex-direction:column;gap:4px;margin:0 0 8px;flex-shrink:0;}
.s2-step{display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:#f8fafc;min-height:34px;overflow:hidden;}
.s2-step.active{border-color:var(--purple);background:var(--purple-lt);}
.s2-step.done{border-color:var(--mid-blue);background:#e8f0fe;}
.s2-step.error{border-color:#fca5a5;background:#fef2f2;}
.s2-step-icon{font-size:15px;flex-shrink:0;}
.s2-step-title{font-size:12px;font-weight:700;color:var(--dark-blue);white-space:nowrap;flex-shrink:0;}
.s2-step-sub{font-size:11px;color:var(--muted);margin-left:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.s2-log{background:#0f2a44;border-radius:8px;padding:10px 12px;max-height:110px;overflow-y:auto;font-family:monospace;font-size:10px;line-height:1.5;margin-top:6px;}
.s2-log-line{margin:0;}
.s2-log-line.info{color:#7dd3fc;}
.s2-log-line.success{color:#5fd1ff;}
.s2-log-line.warning{color:#fde68a;}
.s2-log-line.error{color:#fca5a5;}
.s2-done-bar{background:#e8f0fe;border:1px solid var(--mid-blue);border-radius:10px;padding:10px 14px;margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;flex-shrink:0;}
.s2-done-bar a{padding:9px 20px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;}
.s2-spinner-bar{display:none;flex-direction:row;align-items:center;gap:12px;background:linear-gradient(135deg,#0f2a44,#143b63);border-radius:8px;padding:10px 14px;margin-bottom:8px;flex-shrink:0;}
.s2-spinner-bar.active{display:flex;}
.s2-spinner{width:24px;height:24px;border:3px solid rgba(255,255,255,.2);border-top-color:#5fd1ff;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg)}}
.s2-spin-msg{color:#fff;font-size:13px;font-weight:600;}
/* ── Footer ── */
.site-footer{background:linear-gradient(90deg,#0f2a44,#143b63);color:rgba(255,255,255,.5);padding:14px 20px;font-size:12px;display:flex;justify-content:center;align-items:center;gap:24px;flex-wrap:wrap;}
.site-footer a{color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;}
.site-footer a:hover{color:var(--accent);}
.footer-brand{font-weight:700;color:var(--accent);}
</style>
</head>
<body>

<header class="vv-header">
  <a class="brand-link" href="index.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
  <a href="vizard_browser.php" style="color:rgba(255,255,255,.75);font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px;transition:color .2s;" onmouseover="this.style.color='#5fd1ff'" onmouseout="this.style.color='rgba(255,255,255,.75)'">
    ← Browser
  </a>
</header>

<div class="page-wrap">
  <div class="wiz-card">
    <div class="wiz-card-header">
      <h1 id="cardTitle">🎬 Use This Video as Template</h1>
      <p id="cardSubtitle">Choose how you want to create your new video</p>
    </div>
    <div class="wiz-card-body">
      <button class="back-to-browser" onclick="window.history.back()">← Back</button>

      <!-- Info banner explaining the feature -->
      <div class="info-banner" id="cloneInfoBanner">
        <span class="icon">💡</span>
        <span>
          You're creating a <strong>new video</strong> with your own text, but reusing the media
          (images&nbsp;/&nbsp;videos) from the original video — scene&nbsp;by&nbsp;scene.
          Just tell us your niche, pick a category, and describe your video idea.
        </span>
      </div>

      <div class="prog-track"><div class="prog-fill" id="prog" style="width:0%"></div></div>
      <div id="step-label" class="step-label"></div>
      <div id="step-q" class="step-q"></div>
      <div id="step-body"></div>
      <div class="nav" id="nav-bar">
        <button class="nav-back" id="backBtn" onclick="goBack()" style="visibility:hidden">← Back</button>
        <button class="nav-next" id="nextBtn" disabled onclick="goNext()">Continue →</button>
      </div>
    </div>
  </div>
</div>

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="vizard_scriptgen.php">Full Wizard</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<!-- ── Build Video Modal ─────────────────────────────────────────────────── -->
<div class="s2-overlay" id="s2Overlay">
  <div class="s2-panel">
    <div class="s2-header">
      <h2>🎬 Build Video</h2>
      <button class="s2-close" id="s2CloseBtn" onclick="closeS2()">✕</button>
    </div>
    <div class="s2-body">

      <!-- Setup panel -->
      <div id="s2Setup">
        <div class="info-banner" style="margin-bottom:14px;">
          <span class="icon">🎭</span>
          <span>Media will be <strong>copied scene-by-scene</strong> from the original video.
          Extra scenes (if your script is longer) get stock images automatically.</span>
        </div>
        <div class="s2-section">
          <div class="s2-label">Host Voice</div>
          <select class="s2-select" id="s2HostVoice">
            <option value="">Loading voices…</option>
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
        <div class="s2-section" id="s2MediaSection">
          <div class="s2-label">Fallback Media (for extra scenes)</div>
          <div class="s2-media-opts">
            <div class="s2-media-opt"     data-val="stock_images" onclick="selMedia(this)">📷 Stock Images</div>
            <div class="s2-media-opt sel" data-val="stock_videos" onclick="selMedia(this)">🎥 Stock Videos</div>
          </div>
          <p style="font-size:11px;color:var(--muted);margin-top:6px;">Only used if your new video has more scenes than the original.</p>
        </div>
        <button class="s2-start-btn" onclick="startBuild()">🚀 Build Video Now</button>
      </div>

      <!-- Progress panel -->
      <div id="s2Progress" style="display:none;">
        <div class="s2-spinner-bar" id="s2SpinnerBar">
          <div class="s2-spinner"></div>
          <div class="s2-spin-msg" id="s2SpinMsg">Starting…</div>
        </div>
        <div class="s2-steps">
          <div class="s2-step" id="step0">
            <span class="s2-step-icon">📝</span>
            <span class="s2-step-title">Create Scenes</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="step1">
            <span class="s2-step-icon">🎤</span>
            <span class="s2-step-title">Generate Audio</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="step2">
            <span class="s2-step-icon">🖼️</span>
            <span class="s2-step-title">Copy Media</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="step3">
            <span class="s2-step-icon">✅</span>
            <span class="s2-step-title">Done</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
        </div>
        <div class="s2-log" id="s2Log"></div>
        <div id="s2DoneBar" style="display:none;" class="s2-done-bar">
          <span style="font-size:14px;font-weight:600;color:#166534;">✅ Video ready!</span>
          <a id="s2VideoLink" href="#">Review / Record / Schedule →</a>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════════
const IS_FREE_TRIAL  = <?= $js_free_trial ?>;
const CLONE_BACKEND  = 'wizard_ai_clone_step2.php';
const SCENE_SEP      = '[SCENE BREAK]';
const AZURE_BREAK    = '<break time="200ms"/>';

// Source podcast_id — accepts ?podcast_id=123 or ?source_podcast_id=123
const SOURCE_PODCAST_ID = (function() {
    const p = new URLSearchParams(location.search);
    return parseInt(p.get('podcast_id') || p.get('source_podcast_id') || '0', 10);
})();

// ═══════════════════════════════════════════════════════════════════════════════
// STEP DEFINITIONS  (fewer than full wizard — no angle/hook, no duration)
// ═══════════════════════════════════════════════════════════════════════════════
const DEFAULT_NICHES = [
    'Hypnotherapy','Real Estate','Hair Dressing','Nail Parlour','Financial Adviser',
    'Physiotherapist','Life Coaching','Personal Training','Dentistry','Mortgage Broker'
];

const STEPS = [
    {
        key: 'niche',
        label: 'Step 1 of 4',
        q: 'Select your niche or profession',
        type: 'niche-select',
        opts: DEFAULT_NICHES,
    },
    {
        key: 'category',
        label: 'Step 2 of 4',
        q: 'Select a category',
        type: 'ai-categories',
    },
    {
        key: 'video_idea',
        label: 'Step 3 of 4',
        q: 'What is your video idea?',
        type: 'ai-ideas',
    },
    {
        key: 'cta',
        label: 'Step 4 of 4',
        q: 'What should viewers do next?',
        type: 'cta-select',
        opts: ['Follow for More','Subscribe','Book a Free Call','Visit Website','Download Guide','Comment Below'],
    },
];

// ═══════════════════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════════════════
let cur = 0;
let ans = {};              // wizard answers
let stepOpts = {};         // cached option lists
let generatedScript = '';  // approved script text
let newPodcastId = null;   // podcast created for the new video
let s2MediaType  = 'stock_videos';
let s2Cancelled  = false;

// ═══════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════════════════════
function esc(s) { return String(s).replace(/"/g,'&quot;'); }
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function showToast(msg) {
    const t = Object.assign(document.createElement('div'), { className:'toast', textContent:msg });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(()=>t.remove(), 400); }, 2200);
}

function splitIntoScenes(raw) {
    if (!raw) return '';
    if (raw.includes(SCENE_SEP)) return raw.split(SCENE_SEP).map(x=>x.trim()).filter(Boolean).join('\n');
    if (raw.includes('\n'))       return raw.split('\n').map(x=>x.trim()).filter(Boolean).join('\n');
    return raw.split(/(?<=[.!?])\s+/).map(x=>x.trim()).filter(Boolean).join('\n');
}

function enforceSceneBreaks(script) {
    if (!script) return '';
    return script.split('\n').map(s => {
        const t = s.trim();
        if (!t) return '';
        return t.replace(/<break[^/]*\/>/gi,'').trimEnd() + ' ' + AZURE_BREAK;
    }).filter(Boolean).join('\n');
}

// ═══════════════════════════════════════════════════════════════════════════════
// NAV
// ═══════════════════════════════════════════════════════════════════════════════
function setNext(v)  { document.getElementById('nextBtn').disabled = !v; }
function setBack()   {
    document.getElementById('backBtn').style.visibility = cur === 0 ? 'hidden' : 'visible';
}

function goNext() {
    if (cur < STEPS.length - 1) { cur++; render(); } else { showSummary(); }
}
function goBack() { if (cur > 0) { cur--; render(); } }

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER STEPS
// ═══════════════════════════════════════════════════════════════════════════════
async function render() {
    const s = STEPS[cur];
    const total = STEPS.length;
    document.getElementById('prog').style.width = Math.round((cur / total) * 100) + '%';
    document.getElementById('step-label').textContent = s.label;
    document.getElementById('step-q').textContent     = s.q;
    setBack();
    setNext(false);

    // Hide info banner after step 0
    document.getElementById('cloneInfoBanner').style.display = cur === 0 ? '' : 'none';

    if      (s.type === 'niche-select')  renderNicheSelect(s);
    else if (s.type === 'ai-categories') await renderAICategories(s);
    else if (s.type === 'ai-ideas')      await renderAIIdeas(s);
    else if (s.type === 'cta-select')    renderCTASelect(s);
}

// ── Niche select ──────────────────────────────────────────────────────────────
function renderNicheSelect(s) {
    if (!stepOpts[s.key]) stepOpts[s.key] = [...s.opts];
    const body = document.getElementById('step-body');
    let html = `<div class="opts" id="opts-wrap">`;
    stepOpts[s.key].forEach(n => {
        html += `<div class="opt${ans[s.key]===n?' sel':''}" data-v="${esc(n)}">${n}</div>`;
    });
    html += `</div>`;
    html += `<div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Or type your own…"><button class="custom-add" id="cust-btn">Add</button></div>`;
    body.innerHTML = html;

    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            ans[s.key] = b.dataset.v;
            // Clear downstream on niche change
            delete ans.category; delete ans.video_idea;
            delete stepOpts.category; delete stepOpts.video_idea;
            setNext(true);
        };
    });
    const custBtn = document.getElementById('cust-btn');
    const custIn  = document.getElementById('cust-in');
    if (custBtn) custBtn.onclick  = () => addCustom(s.key, 'opts-wrap');
    if (custIn)  custIn.onkeydown = e => { if (e.key==='Enter') addCustom(s.key, 'opts-wrap'); };
    if (ans[s.key]) setNext(true);
}

function addCustom(key, wrapId) {
    const inp = document.getElementById('cust-in');
    const v   = inp.value.trim(); if (!v) return; inp.value = '';
    if (!stepOpts[key]) stepOpts[key] = [];
    if (!stepOpts[key].includes(v)) stepOpts[key].push(v);
    document.querySelectorAll(`#${wrapId} .opt`).forEach(x => x.classList.remove('sel'));
    const wrap = document.getElementById(wrapId);
    const b = document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
    b.onclick = () => {
        document.querySelectorAll(`#${wrapId} .opt`).forEach(x=>x.classList.remove('sel'));
        b.classList.add('sel'); ans[key]=v; setNext(true);
    };
    wrap.appendChild(b); ans[key]=v; setNext(true);
}

// ── AI categories ─────────────────────────────────────────────────────────────
async function renderAICategories(s) {
    if (stepOpts[s.key]) { renderOptsFromCache(s, stepOpts[s.key]); return; }
    document.getElementById('step-body').innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading categories…</span></div>`;
    try {
        const r = await fetch('generate_categories.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ niche: ans.niche })
        });
        const d = await r.json();
        const items = d.categories || [];
        if (!items.length) throw new Error('empty');
        stepOpts[s.key] = items;
        renderOptsFromCache(s, items);
    } catch(e) {
        document.getElementById('step-body').innerHTML =
            `<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load — add your own below</div>
             <div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Type here…"><button class="custom-add" id="cust-btn">Add</button></div>`;
        document.getElementById('cust-btn').onclick  = () => addCustom(s.key, 'opts-wrap-fb');
        document.getElementById('cust-in').onkeydown = e => { if (e.key==='Enter') addCustom(s.key,'opts-wrap-fb'); };
    }
}

// ── AI video ideas ─────────────────────────────────────────────────────────────
async function renderAIIdeas(s) {
    if (stepOpts[s.key]) { renderOptsFromCache(s, stepOpts[s.key]); return; }
    document.getElementById('step-body').innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating ideas…</span></div>`;
    try {
        const r = await fetch('generate_titles.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ niche: ans.niche, topic: ans.category })
        });
        const d = await r.json();
        const items = d.titles || [];
        if (!items.length) throw new Error('empty');
        stepOpts[s.key] = items;
        renderOptsFromCache(s, items);
    } catch(e) {
        document.getElementById('step-body').innerHTML =
            `<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load — add your own below</div>
             <div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Type your video idea…"><button class="custom-add" id="cust-btn">Add</button></div>`;
        document.getElementById('cust-btn').onclick  = () => addCustom(s.key, 'opts-wrap-fb');
        document.getElementById('cust-in').onkeydown = e => { if (e.key==='Enter') addCustom(s.key,'opts-wrap-fb'); };
    }
}

// ── Generic opts renderer (used by categories & ideas) ───────────────────────
function renderOptsFromCache(s, items) {
    const body = document.getElementById('step-body');
    let html = `<div class="opts" id="opts-wrap">`;
    items.forEach(o => {
        html += `<div class="opt${ans[s.key]===o?' sel':''}" data-v="${esc(o)}">${o}</div>`;
    });
    html += `</div>`;
    html += `<div class="custom-row"><input class="custom-in" id="cust-in" placeholder="Or type your own…"><button class="custom-add" id="cust-btn">Add</button></div>`;
    body.innerHTML = html;

    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
            b.classList.add('sel'); ans[s.key]=b.dataset.v; setNext(true);
        };
    });
    const custBtn = document.getElementById('cust-btn');
    const custIn  = document.getElementById('cust-in');
    if (custBtn) custBtn.onclick  = () => addCustom(s.key,'opts-wrap');
    if (custIn)  custIn.onkeydown = e => { if (e.key==='Enter') addCustom(s.key,'opts-wrap'); };
    if (ans[s.key]) setNext(true);
}

// ── CTA select ────────────────────────────────────────────────────────────────
function renderCTASelect(s) {
    if (!stepOpts[s.key]) stepOpts[s.key] = [...s.opts];
    const body = document.getElementById('step-body');
    let html = `<div class="opts" id="opts-wrap">`;
    stepOpts[s.key].forEach(o => {
        html += `<div class="opt${ans[s.key]===o?' sel':''}" data-v="${esc(o)}">${o}</div>`;
    });
    html += `</div>`;
    html += `<div class="custom-row">
        <input class="custom-in" id="cust-in" placeholder="Or type your own CTA…">
        <button class="custom-add" id="cust-btn">Add</button>
    </div>`;
    body.innerHTML = html;

    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
            b.classList.add('sel'); ans[s.key]=b.dataset.v; setNext(true);
        };
    });
    const custBtn = document.getElementById('cust-btn');
    const custIn  = document.getElementById('cust-in');
    if (custBtn) custBtn.onclick  = () => addCustom(s.key,'opts-wrap');
    if (custIn)  custIn.onkeydown = e => { if (e.key==='Enter') addCustom(s.key,'opts-wrap'); };
    if (ans[s.key]) setNext(true);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUMMARY + SCRIPT GENERATION
// ═══════════════════════════════════════════════════════════════════════════════
function showSummary() {
    document.getElementById('prog').style.width = '100%';
    document.getElementById('step-label').textContent = 'Ready';
    document.getElementById('step-q').textContent     = '';
    document.getElementById('nav-bar').style.display  = 'none';
    document.getElementById('cardTitle').textContent   = '📋 Your Brief';
    document.getElementById('cardSubtitle').textContent = 'Review then generate your script';
    document.getElementById('cloneInfoBanner').style.display = 'none';

    const LABELS = { niche:'Niche', category:'Category', video_idea:'Video Idea', cta:'Call to Action' };
    const rows = Object.entries(ans).map(([k,v]) =>
        `<div class="sum-row"><span class="sum-key">${LABELS[k]||k}</span><span class="sum-val">${escHtml(v)}</span></div>`
    ).join('');

    document.getElementById('step-body').innerHTML = `
        <div style="margin-bottom:12px;">
            <div style="font-size:14px;font-weight:700;color:var(--dark-blue);margin-bottom:4px;">Your brief is complete</div>
            <div style="font-size:12px;color:var(--muted);">Media will be cloned from the original video (podcast #${SOURCE_PODCAST_ID || '?'})</div>
        </div>
        <div class="summary">${rows}</div>
        <div id="script-output"></div>
        <button class="gen-btn" id="gen-btn" onclick="generateScript()">🚀 Generate Script</button>
        <button class="restart-btn" onclick="restart()">Start over</button>
    `;
}

async function generateScript() {
    const btn = document.getElementById('gen-btn');
    const out = document.getElementById('script-output');
    btn.disabled = true; btn.textContent = '⏳ Generating…';
    out.innerHTML = `<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Writing your script…</span></div>`;

    try {
        // We reuse the same generate_script.php endpoint as the main wizard
        const payload = {
            niche:          ans.niche,
            topic:          ans.category,
            title:          ans.video_idea,
            angle:          'engaging',          // angle taken from source — minimal
            duration:       '60 seconds',        // duration taken from source — minimal
            cta:            ans.cta,
            language:       'English',
            reel_type:      'Standard (Talking Head)',
            objective:      'Educate',
            audience:       'General Public',
            short_sentences: true,
            scene_format:   true,
            max_words_per_scene: 12,
            scene_count:    '6-8',
            scene_break_tag: AZURE_BREAK,
            source_podcast_id: SOURCE_PODCAST_ID,
        };
        const r    = await fetch('generate_script.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            credentials:'include', body:JSON.stringify(payload)
        });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { throw new Error('Server error: '+text.substring(0,200)); }
        if (!d.success) throw new Error(d.error || 'Script generation failed');

        const script   = enforceSceneBreaks(splitIntoScenes(d.script));
        generatedScript = script;
        newPodcastId    = d.podcast_id || null;

        out.innerHTML = `
            <div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Generated Script</div>
            <textarea id="script-text" class="script-box" oninput="generatedScript=this.value">${escHtml(script)}</textarea>
            <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
              <button class="build-btn" onclick="openS2()">🎬 Build Video</button>
            </div>`;
        btn.textContent = '🔄 Regenerate'; btn.disabled = false;

    } catch(e) {
        out.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${escHtml(e.message)}</div>`;
        btn.textContent = 'Try Again'; btn.disabled = false;
    }
}

function restart() {
    cur=0; ans={}; stepOpts={}; generatedScript=""; newPodcastId=null;
    document.getElementById("cardTitle").textContent    = "🎬 Use This Video as Template";
    document.getElementById("cardSubtitle").textContent = "Choose how you want to create your new video";
    showModeSelect();
}

// ═══════════════════════════════════════════════════════════════════════════════
// BUILD VIDEO MODAL
// ═══════════════════════════════════════════════════════════════════════════════
function selMedia(el) {
    document.querySelectorAll('.s2-media-opt').forEach(x=>x.classList.remove('sel'));
    el.classList.add('sel'); s2MediaType=el.dataset.val;
}

function openS2() {
    s2Cancelled = false;
    document.getElementById('s2Setup').style.display    = 'block';
    document.getElementById('s2Progress').style.display = 'none';
    document.getElementById('s2DoneBar').style.display  = 'none';
    document.getElementById('s2Log').innerHTML = '';
    document.getElementById('s2CloseBtn').style.display = 'inline';
    ['step0','step1','step2','step3'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.className='s2-step'; el.querySelector('.s2-step-sub').textContent='Waiting…'; }
    });
    loadVoices();
    document.getElementById('s2Overlay').classList.add('open');
}

function closeS2() {
    s2Cancelled = true;
    document.getElementById('s2Overlay').classList.remove('open');
}

async function loadVoices() {
    const fd = new FormData();
    fd.append('ajax_action', 'clone_get_voices');
    fd.append('lang_code', 'en');
    try {
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        const voices = d.voices || [];
        const sel = document.getElementById('s2HostVoice');
        if (voices.length) {
            sel.innerHTML = voices.map(v =>
                `<option value="${esc(v.voice_id)}">${escHtml(v.voice_name)}</option>`
            ).join('');
        } else {
            // Fallback OpenAI voices
            sel.innerHTML = ['alloy','echo','fable','onyx','nova','shimmer'].map(v =>
                `<option value="openai:${v}">${v.charAt(0).toUpperCase()+v.slice(1)}</option>`
            ).join('');
        }
    } catch(e) {
        document.getElementById('s2HostVoice').innerHTML =
            ['alloy','echo','fable','onyx','nova','shimmer'].map(v =>
                `<option value="openai:${v}">${v.charAt(0).toUpperCase()+v.slice(1)}</option>`
            ).join('');
    }
}

function s2Log(msg, type='info') {
    const log = document.getElementById('s2Log');
    const p = document.createElement('p'); p.className='s2-log-line '+type; p.textContent=msg;
    log.appendChild(p); log.scrollTop = log.scrollHeight;
}

function stepStatus(num, status, sub) {
    const el = document.getElementById('step'+num); if (!el) return;
    el.className = 's2-step '+status; el.querySelector('.s2-step-sub').textContent = sub;
}

function setSpinMsg(msg) {
    const el = document.getElementById('s2SpinMsg'); if (el) el.textContent = msg;
}

// ─────────────────────────────────────────────────────────────────────────────
// Main build function
// ─────────────────────────────────────────────────────────────────────────────
// ── Safe fetch: always returns parsed JSON or throws with the raw server text ──
async function cloneFetch(fd) {
    const r    = await fetch(CLONE_BACKEND, { method:'POST', body:fd, credentials:'include' });
    const text = await r.text();
    try {
        return JSON.parse(text);
    } catch(e) {
        // Server returned HTML (PHP error / 404) — surface first 300 chars
        const preview = text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,300);
        throw new Error('Server error: ' + preview);
    }
}

async function startBuild() {
    const hostVoice = document.getElementById('s2HostVoice').value;
    const rate      = document.getElementById('s2Rate').value;
    if (!hostVoice) { alert('Please select a host voice'); return; }
    if (!SOURCE_PODCAST_ID) { alert('No source podcast ID — please open this page from the Browser.'); return; }

    // Use latest script from textarea if user edited it
    const scriptEl = document.getElementById('script-text');
    if (scriptEl) generatedScript = scriptEl.value.trim();
    if (!generatedScript) { alert('No script — please generate one first'); return; }

    document.getElementById('s2Setup').style.display    = 'none';
    document.getElementById('s2Progress').style.display = 'block';
    document.getElementById('s2CloseBtn').style.display = 'none';
    document.getElementById('s2SpinnerBar').classList.add('active');
    s2Cancelled = false;

    const langCode = 'en';

    const abortBuild = (stepNum, msg) => {
        stepStatus(stepNum,'error', msg);
        s2Log('❌ '+msg,'error');
        document.getElementById('s2CloseBtn').style.display='inline';
        document.getElementById('s2SpinnerBar').classList.remove('active');
    };

    // ── STEP 0: Create / ensure podcast record ─────────────────────────────
    stepStatus(0,'active','Creating podcast record…');
    setSpinMsg('Saving script…');
    s2Log('💾 Creating podcast record…','info');

    let podcastId = newPodcastId;

    if (!podcastId) {
        try {
            const r    = await fetch('save_script_to_db.php', {
                method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
                body: JSON.stringify({
                    script: generatedScript,
                    data: {
                        niche:     ans.niche     || '',
                        topic:     ans.category  || '',
                        title:     ans.video_idea || ans.title || 'AI Clone Video',
                        cta:       ans.cta        || '',
                        language:  'English',
                        reel_type: 'Standard (Talking Head)',
                    }
                })
            });
            const text = await r.text();
            let d;
            try { d = JSON.parse(text); } catch(e) {
                throw new Error('save_script_to_db.php returned non-JSON: ' + text.replace(/<[^>]*>/g,' ').trim().substring(0,200));
            }
            if (!d.success) throw new Error(d.error || 'Save failed');
            podcastId    = d.podcast_id;
            newPodcastId = podcastId;
            s2Log(`✅ Podcast #${podcastId} created`,'success');
        } catch(e) {
            abortBuild(0, e.message); return;
        }
    } else {
        s2Log(`✅ Reusing podcast #${podcastId}`,'success');
    }

    // ── Update voice on podcast ────────────────────────────────────────────
    try {
        const fd = new FormData();
        fd.append('action','clone_update_voice');
        fd.append('podcast_id', podcastId);
        fd.append('host_voice', hostVoice);
        fd.append('rate',       rate);
        const d = await cloneFetch(fd);
        if (d.success) s2Log(`✅ Voice saved — ${hostVoice}`,'success');
        else           s2Log(`⚠ Voice save: ${d.error||'unknown'}`,'warning');
    } catch(e) { s2Log('⚠ Voice save: '+e.message,'warning'); }

    // ── Create scenes ──────────────────────────────────────────────────────
    setSpinMsg('Creating scenes…');
    s2Log('📝 Splitting script into scenes…','info');
    try {
        const fd = new FormData();
        fd.append('action',     'clone_create_scenes');
        fd.append('podcast_id', podcastId);
        fd.append('host_voice', hostVoice);
        fd.append('rate',       rate);
        fd.append('lang_code',  langCode);
        const d = await cloneFetch(fd);
        if (!d.success) throw new Error(d.error||'Scene creation failed');
        s2Log(`✅ ${d.scene_count} scenes created`,'success');
    } catch(e) {
        abortBuild(0, e.message); return;
    }
    stepStatus(0,'done','✓ Scenes created');

    // ── Fetch scenes ───────────────────────────────────────────────────────
    let dbScenes = [];
    try {
        const fd = new FormData();
        fd.append('action',     'clone_get_scenes');
        fd.append('podcast_id', podcastId);
        const d = await cloneFetch(fd);
        dbScenes = Array.isArray(d) ? d : (d.scenes || []);
        s2Log(`📋 Fetched ${dbScenes.length} scenes`,'info');
    } catch(e) { s2Log('⚠ Could not fetch scenes: '+e.message,'warning'); }

    if (!dbScenes.length) {
        abortBuild(0, 'No scenes found — check that the script saved correctly'); return;
    }

    // ── STEP 1: Generate audio (parallel) ─────────────────────────────────
    stepStatus(1,'active',`Generating ${dbScenes.length} audio files…`);
    setSpinMsg(`Generating audio for ${dbScenes.length} scenes…`);
    s2Log(`🎤 Generating audio in parallel…`,'info');

    const audioResults = await Promise.all(dbScenes.map(async (scene, i) => {
        if (s2Cancelled) return { success:false };
        const ttsText = (scene.text_contents||'').replace(/<break[^>]*>/gi,'').trim();
        if (!ttsText) { s2Log(`⏭ Scene ${i+1}: empty, skipping`,'info'); return {success:true}; }
        try {
            const fd = new FormData();
            fd.append('action',     'clone_generate_audio');
            fd.append('scene_id',   scene.id);
            fd.append('podcast_id', podcastId);
            fd.append('seq_no',     i+1);
            fd.append('lang_code',  langCode);
            fd.append('voice_id',   hostVoice);
            fd.append('rate',       rate);
            fd.append('text',       ttsText);
            const d = await cloneFetch(fd);
            if (d.success) s2Log(`✓ Scene ${i+1} audio OK`,'success');
            else           s2Log(`✗ Scene ${i+1}: ${d.error}`,'error');
            return d;
        } catch(e) { s2Log(`✗ Scene ${i+1}: ${e.message}`,'error'); return {success:false}; }
    }));

    const audioDone = audioResults.filter(r=>r.success).length;
    const audioFail = audioResults.filter(r=>!r.success).length;
    stepStatus(1, audioFail>0 ? 'error':'done',
        `✓ ${audioDone} audio files${audioFail>0?' ('+audioFail+' failed)':''}`);

    if (s2Cancelled) {
        s2Log('⏹ Cancelled','warning');
        document.getElementById('s2SpinnerBar').classList.remove('active'); return;
    }

    // ── STEP 2: Copy media from source podcast ─────────────────────────────
    stepStatus(2,'active','Copying media from source…');
    setSpinMsg('Copying images from original video…');
    s2Log(`🖼 Copying media from source podcast #${SOURCE_PODCAST_ID}…`,'info');

    let copyResults = [];
    try {
        const fd = new FormData();
        fd.append('action',            'clone_copy_media_from_source');
        fd.append('new_podcast_id',    podcastId);
        fd.append('source_podcast_id', SOURCE_PODCAST_ID);
        const d = await cloneFetch(fd);
        if (!d.success) throw new Error(d.error||'Copy failed');
        copyResults = d.results || [];
        const copied = copyResults.filter(x=>x.has_source).length;
        const extra  = copyResults.filter(x=>!x.has_source).length;
        s2Log(`✅ ${copied} scenes cloned from source | ${extra} extra scenes need fallback`,'success');
    } catch(e) {
        s2Log('⚠ Media copy: '+e.message,'warning');
        // Mark all scenes as needing fallback
        copyResults = dbScenes.map(sc=>({scene_id:sc.id,seq_no:sc.seq_no,copied:0,has_source:false}));
    }

    // ── Fallback stock search for scenes beyond source length ──────────────
    const extraScenes = copyResults.filter(x => !x.has_source);
    if (extraScenes.length > 0) {
        s2Log(`🔍 Stock search for ${extraScenes.length} extra scene(s)…`,'info');
        setSpinMsg(`Stock fallback for ${extraScenes.length} extra scenes…`);
        const IMAGE_FIELDS = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
        const sceneQueries = extraScenes.map((r,idx) => {
            const scene = dbScenes.find(s => s.id == r.scene_id) || {};
            const nl    = (scene.natural_language_tags||'').trim();
            const ht    = (scene.hashtags||'').trim();
            const text  = (scene.text_contents||'').replace(/<[^>]*>/g,'').trim();
            return { scene_idx:idx, scene_id:r.scene_id, query: nl||ht||text };
        });
        try {
            const fd = new FormData();
            fd.append('action',     'clone_search_images_batch');
            fd.append('podcast_id', podcastId);
            fd.append('slots',      5);
            fd.append('scenes',     JSON.stringify(sceneQueries));
            const data = await cloneFetch(fd);
            for (const br of (data.results||[])) {
                const found  = br.found || [];
                const seen   = new Set(); const unique = [];
                for (const item of found) {
                    if (!seen.has(item.filename)) { seen.add(item.filename); unique.push(item); }
                }
                for (let slot=0; slot<Math.min(5,unique.length); slot++) {
                    const afd = new FormData();
                    afd.append('action',      'clone_assign_image');
                    afd.append('scene_id',    br.scene_id);
                    afd.append('podcast_id',  podcastId);
                    afd.append('filename',    unique[slot].filename);
                    afd.append('image_field', IMAGE_FIELDS[slot]);
                    await cloneFetch(afd).catch(()=>{});
                }
                if (unique.length) s2Log(`✓ Extra scene #${br.scene_id}: ${unique.length} images`,'success');
                else               s2Log(`⚠ Extra scene #${br.scene_id}: no stock found`,'warning');
            }
        } catch(e) { s2Log('⚠ Fallback search: '+e.message,'warning'); }
    }

    stepStatus(2,'done',`✓ Media assigned`);

    // ── STEP 3: Thumbnail & done ───────────────────────────────────────────
    stepStatus(3,'active','Finalising…');
    setSpinMsg('Updating thumbnail…');
    try {
        const fd = new FormData();
        fd.append('action',     'clone_get_thumbnail');
        fd.append('podcast_id', podcastId);
        await cloneFetch(fd);
        s2Log('🖼 Thumbnail updated','success');
    } catch(e) { s2Log('⚠ Thumbnail: '+e.message,'warning'); }

    stepStatus(3,'done','✓ All done!');
    document.getElementById('s2SpinnerBar').classList.remove('active');
    document.getElementById('s2CloseBtn').style.display='inline';
    document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id='+podcastId;
    document.getElementById('s2DoneBar').style.display='flex';
    s2Log(`🎉 Video ready! Podcast #${podcastId}`,'success');
    showToast('✅ Video ready — Podcast #'+podcastId);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODE SELECTION — shown first before any wizard steps
// ═══════════════════════════════════════════════════════════════════════════════
function showModeSelect() {
    document.getElementById('prog').style.width = '0%';
    document.getElementById('step-label').textContent = '';
    document.getElementById('step-q').textContent = '';
    document.getElementById('nav-bar').style.display = 'none';
    document.getElementById('cloneInfoBanner').style.display = 'none';

    document.getElementById('step-body').innerHTML = `
        <div style="margin-bottom:20px;">
            <div style="font-size:15px;font-weight:700;color:var(--dark-blue);margin-bottom:6px;">
                How would you like to create this video?
            </div>
            <div style="font-size:13px;color:var(--muted);">
                Using the same media (images &amp; videos) as the original — Podcast #${SOURCE_PODCAST_ID}
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:12px;">

            <div class="mode-choice" onclick="selectMode('ai')" style="
                border:1.5px solid var(--border);border-radius:14px;padding:20px 18px;
                background:#fff;cursor:pointer;transition:all .2s;
            ">
                <div style="font-size:28px;margin-bottom:8px;">🤖</div>
                <div style="font-size:15px;font-weight:700;color:var(--dark-blue);margin-bottom:6px;">Let AI Create Content For Me</div>
                <div style="font-size:13px;color:var(--muted);line-height:1.6;">
                    Answer a few quick questions (niche, category, video idea, CTA) and AI writes
                    a brand-new script — then builds the video using the original media.
                </div>
                <div style="margin-top:10px;display:inline-block;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;background:#ede9fe;color:#6d28d9;">Best for new ideas</div>
            </div>

            <div class="mode-choice" onclick="selectMode('own')" style="
                border:1.5px solid var(--border);border-radius:14px;padding:20px 18px;
                background:#fff;cursor:pointer;transition:all .2s;
            ">
                <div style="font-size:28px;margin-bottom:8px;">✍️</div>
                <div style="font-size:15px;font-weight:700;color:var(--dark-blue);margin-bottom:6px;">I Have My Own Content</div>
                <div style="font-size:13px;color:var(--muted);line-height:1.6;">
                    Paste your own script or text — it gets split into scenes and built using
                    the original video's media, scene by scene.
                </div>
                <div style="margin-top:10px;display:inline-block;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;background:#dbeafe;color:#1e40af;">Best for existing content</div>
            </div>

        </div>
    `;

    // Hover effect
    document.querySelectorAll('.mode-choice').forEach(el => {
        el.addEventListener('mouseenter', () => {
            el.style.borderColor = 'var(--purple)';
            el.style.boxShadow   = '0 6px 20px rgba(139,92,246,0.12)';
            el.style.transform   = 'translateY(-2px)';
        });
        el.addEventListener('mouseleave', () => {
            el.style.borderColor = 'var(--border)';
            el.style.boxShadow   = 'none';
            el.style.transform   = 'none';
        });
    });
}

function selectMode(mode) {
    if (mode === 'ai') {
        document.getElementById("cardTitle").textContent    = "🤖 AI Creates Content For Me";
        document.getElementById("cardSubtitle").textContent = "Answer a few questions — AI writes your script";
        document.getElementById("cloneInfoBanner").style.display = "";
        document.getElementById("nav-bar").style.display = "flex";
        cur = 0; ans = {}; stepOpts = {};
        render();
    } else {
        showOwnContentPanel();
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// "I HAVE CONTENT" PANEL
// ═══════════════════════════════════════════════════════════════════════════════
function showOwnContentPanel() {
    document.getElementById('prog').style.width = '0%';
    document.getElementById('step-label').textContent = '';
    document.getElementById('step-q').textContent = '';
    document.getElementById('nav-bar').style.display = 'none';
    document.getElementById('cloneInfoBanner').style.display = 'none';
    document.getElementById('cardTitle').textContent    = '✍️ Use My Own Content';
    document.getElementById('cardSubtitle').textContent = 'Paste your script — we\'ll build the video with the original media';

    document.getElementById('step-body').innerHTML = `
        <button onclick="showModeSelect()" style="background:none;border:none;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;padding:0;margin-bottom:16px;display:inline-flex;align-items:center;gap:4px;">← Back</button>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:13px;font-weight:600;color:var(--dark-blue);margin-bottom:6px;">Video Title</label>
            <input type="text" id="own-title" placeholder="e.g. 5 Ways to Reduce Stress"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;outline:none;"
                onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:13px;font-weight:600;color:var(--dark-blue);margin-bottom:6px;">Your Script</label>
            <textarea id="own-script" rows="8" placeholder="Paste your script here. Each line will become one scene."
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;outline:none;line-height:1.6;"
                onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'"></textarea>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:13px;font-weight:600;color:var(--dark-blue);margin-bottom:6px;">Call to Action</label>
            <input type="text" id="own-cta" value="Follow for more tips"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;outline:none;"
                onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
        </div>

        <div id="own-output"></div>

        <button class="gen-btn" onclick="processOwnContent()">📝 Process &amp; Preview Script</button>
    `;
}

async function processOwnContent() {
    const title  = document.getElementById('own-title').value.trim();
    const script = document.getElementById('own-script').value.trim();
    const cta    = document.getElementById('own-cta').value.trim();
    const out    = document.getElementById('own-output');

    if (!title)  { alert('Please enter a video title'); return; }
    if (!script) { alert('Please paste your script'); return; }

    const btn = document.querySelector('#step-body .gen-btn');
    btn.disabled = true; btn.textContent = '⏳ Processing…';
    out.innerHTML = `<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Formatting into scenes…</span></div>`;

    try {
        const r = await fetch('generate_script.php', {
            method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
            body: JSON.stringify({
                niche:'custom', title, objective:'Inform', audience:'General Public',
                angle:'Storytelling', duration:'60 seconds', cta,
                language:'English', reel_type:'Standard (Talking Head)',
                _custom_prompt: `ORIGINAL CONTENT:\n${script}\n\nCALL TO ACTION: ${cta}`,
                _mode:'content',
                source_podcast_id: SOURCE_PODCAST_ID,
            })
        });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Processing failed');

        generatedScript = enforceSceneBreaks(splitIntoScenes(d.script));
        newPodcastId    = d.podcast_id || null;

        // Override ans for the build step
        ans = { niche:'custom', video_idea: title, cta };

        out.innerHTML = `
            <div style="margin:16px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Formatted Script</div>
            <textarea id="script-text" class="script-box" oninput="generatedScript=this.value">${escHtml(generatedScript)}</textarea>
            <button class="build-btn" style="margin-top:10px;" onclick="openS2()">🎬 Build Video</button>
        `;
        btn.textContent = '🔄 Reprocess'; btn.disabled = false;

    } catch(e) {
        out.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${escHtml(e.message)}</div>`;
        btn.textContent = '📝 Process & Preview Script'; btn.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════════════════
(function init() {
    if (!SOURCE_PODCAST_ID) {
        document.getElementById('step-body').innerHTML =
            `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:16px 18px;color:#991b1b;font-size:14px;">
               ⚠️ <strong>No source podcast specified.</strong><br>
               Add <code>?podcast_id=123</code> to the URL, or open this page from the VideoVizard Browser.
             </div>`;
        document.getElementById('nav-bar').style.display = 'none';
        document.getElementById('prog').style.width = '0%';
        return;
    }
    showModeSelect();
})();
</script>
</body>
</html>

<?php
/**
 * industry_selector.php
 * Two-step: Industry → Niche
 * Master:  hdb_master_industries, hdb_master_niches
 * User:    hdb_user_industries,   hdb_user_niches
 * PHP 7.2+ compatible
 */
include __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';

// ── Get current user from session (adjust to your session var names) ──────────
session_start();
$admin_id   = (int)($_SESSION['admin_id']   ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

// For testing without session — remove these two lines in production
if (!$admin_id)   $admin_id   = 1;
if (!$company_id) $company_id = 1;

function now_str() { return date('Y-m-d H:i:s'); }

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_user_industries
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_user_industries') {
    header('Content-Type: application/json');
    $rows = array();
    $res  = mysqli_query($conn,
        "SELECT id, industry_id, niche_name as industry_desc
         FROM hdb_user_industries
         WHERE admin_id = $admin_id AND company_id = $company_id
         ORDER BY created_date DESC"
    );
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array(
            'id'          => (int)$r['id'],
            'industry_id' => (int)$r['industry_id'],
            'name'        => trim($r['industry_desc']),
        );
    }
    echo json_encode(array('success' => true, 'industries' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_master_industries
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_master_industries') {
    header('Content-Type: application/json');
    $rows = array();
    $res  = mysqli_query($conn,
        "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC"
    );
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array(
            'id'   => (int)$r['id'],
            'name' => trim($r['industry_desc']),
        );
    }
    echo json_encode(array('success' => true, 'industries' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: save_industry
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'save_industry') {
    header('Content-Type: application/json');
    $industry_id   = (int)(isset($_POST['industry_id'])   ? $_POST['industry_id']   : 0);
    $industry_desc = trim(isset($_POST['industry_desc'])  ? $_POST['industry_desc'] : '');
    if (!$industry_id || !$industry_desc) {
        echo json_encode(array('success' => false, 'error' => 'Missing data')); exit;
    }
    // Check duplicate
    $esc  = mysqli_real_escape_string($conn, $industry_desc);
    $chk  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_user_industries
         WHERE admin_id = $admin_id AND company_id = $company_id AND industry_id = $industry_id
         LIMIT 1"
    ));
    if ($chk) {
        echo json_encode(array('success' => true, 'status' => 'existed', 'id' => (int)$chk['id']));
        exit;
    }
    $now = now_str();
    mysqli_query($conn,
        "INSERT INTO hdb_user_industries (admin_id, company_id, niche_name, industry_id, is_ai_generated, created_date)
         VALUES ($admin_id, $company_id, '$esc', $industry_id, 0, '$now')"
    );
    echo json_encode(array('success' => true, 'status' => 'added', 'id' => (int)mysqli_insert_id($conn)));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_master_niches
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_master_niches') {
    header('Content-Type: application/json');
    $industry_id = (int)(isset($_GET['industry_id']) ? $_GET['industry_id'] : 0);
    if (!$industry_id) {
        echo json_encode(array('success' => false, 'error' => 'No industry_id')); exit;
    }
    $rows = array();
    $res  = mysqli_query($conn,
        "SELECT id, niche_desc FROM hdb_master_niches
         WHERE master_industry_id = $industry_id ORDER BY niche_desc ASC"
    );
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array('id' => (int)$r['id'], 'name' => trim($r['niche_desc']));
    }
    echo json_encode(array('success' => true, 'niches' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_user_niches
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_user_niches') {
    header('Content-Type: application/json');
    $industry_id = (int)(isset($_GET['industry_id']) ? $_GET['industry_id'] : 0);
    if (!$industry_id) {
        echo json_encode(array('success' => false, 'error' => 'No industry_id')); exit;
    }
    $rows = array();
    $res  = mysqli_query($conn,
        "SELECT id, niche_id, niche_name
         FROM hdb_user_niches
         WHERE admin_id = $admin_id AND company_id = $company_id AND industry_id = $industry_id
         ORDER BY created_date DESC"
    );
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array(
            'id'       => (int)$r['id'],
            'niche_id' => (int)$r['niche_id'],
            'name'     => trim($r['niche_name']),
        );
    }
    echo json_encode(array('success' => true, 'niches' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: save_niche
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'save_niche') {
    header('Content-Type: application/json');
    $niche_id    = (int)(isset($_POST['niche_id'])    ? $_POST['niche_id']    : 0);
    $industry_id = (int)(isset($_POST['industry_id']) ? $_POST['industry_id'] : 0);
    $niche_name  = trim(isset($_POST['niche_name'])   ? $_POST['niche_name']  : '');
    if (!$niche_id || !$industry_id || !$niche_name) {
        echo json_encode(array('success' => false, 'error' => 'Missing data')); exit;
    }
    $esc = mysqli_real_escape_string($conn, $niche_name);
    // Check duplicate
    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_user_niches
         WHERE admin_id = $admin_id AND company_id = $company_id
         AND industry_id = $industry_id AND niche_id = $niche_id
         LIMIT 1"
    ));
    if ($chk) {
        echo json_encode(array('success' => true, 'status' => 'existed', 'id' => (int)$chk['id']));
        exit;
    }
    $now = now_str();
    mysqli_query($conn,
        "INSERT INTO hdb_user_niches (admin_id, company_id, niche_name, industry_id, niche_id, is_ai_generated, created_date)
         VALUES ($admin_id, $company_id, '$esc', $industry_id, $niche_id, 0, '$now')"
    );
    echo json_encode(array('success' => true, 'status' => 'added', 'id' => (int)mysqli_insert_id($conn)));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Industry & Niche Selector — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:        #eef1f7;
  --card:      #ffffff;
  --card2:     #f5f7fb;
  --border:    #dce3ee;
  --accent:    #3a7bd5;
  --accent2:   #5b9cf6;
  --accent-lt: rgba(58,123,213,0.10);
  --gold:      #d97b2a;
  --gold-lt:   rgba(217,123,42,0.12);
  --text:      #1c2a3e;
  --muted:     #6b7c99;
  --success:   #1a9e6e;
  --suc-lt:    rgba(26,158,110,0.10);
  --error:     #cc3f3f;
  --radius:    16px;
  --shadow:    0 10px 44px rgba(58,100,180,0.13);
}
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'DM Sans', sans-serif;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 20px;
  background-image:
    radial-gradient(ellipse 70% 50% at 5% 0%, rgba(58,123,213,0.07) 0%, transparent 65%),
    radial-gradient(ellipse 55% 45% at 95% 100%, rgba(91,156,246,0.06) 0%, transparent 65%);
}
.container { width: 100%; max-width: 680px; }

/* Header */
.header { text-align: center; margin-bottom: 36px; }
.badge {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--accent-lt); border: 1px solid rgba(58,123,213,0.20);
  border-radius: 100px; padding: 5px 15px; font-size: 11px; font-weight: 700;
  color: var(--accent); letter-spacing: 0.11em; text-transform: uppercase; margin-bottom: 14px;
}
.badge-dot { width:7px; height:7px; background:var(--accent); border-radius:50%; animation:pulse 2s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.6);} }
h1 { font-family:'Syne',sans-serif; font-size:clamp(24px,5vw,38px); font-weight:800; color:var(--text); letter-spacing:-0.02em; margin-bottom:8px; }
h1 span { color:var(--accent); }
.subtitle { color:var(--muted); font-size:14px; line-height:1.65; }

/* Steps */
.steps { display:flex; margin-bottom:32px; }
.step-item { flex:1; display:flex; align-items:center; }
.step-num {
  width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-size:13px; font-weight:800;
  background:var(--card2); border:2px solid var(--border); color:var(--muted); transition:all 0.3s; flex-shrink:0;
}
.step-label { font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; margin-left:9px; transition:color 0.3s; }
.step-line  { flex:1; height:2px; background:var(--border); margin:0 12px; transition:background 0.3s; }
.step-item.active .step-num   { background:var(--accent); border-color:var(--accent); color:#fff; }
.step-item.active .step-label { color:var(--accent); }
.step-item.done   .step-num   { background:var(--success); border-color:var(--success); color:#fff; }
.step-item.done   .step-label { color:var(--success); }

/* Card */
.card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow); }

/* Section */
.section { margin-bottom:24px; }
.section-label {
  font-size:11px; font-weight:700; color:var(--muted); letter-spacing:0.10em;
  text-transform:uppercase; margin-bottom:12px; display:flex; align-items:center; gap:8px;
}
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }

/* Chips */
.chips { display:flex; flex-wrap:wrap; gap:8px; min-height:36px; }
.chip {
  background:var(--card2); border:1.5px solid var(--border); border-radius:100px;
  padding:8px 18px; font-size:13px; font-weight:500; color:var(--muted);
  cursor:pointer; transition:all 0.17s; user-select:none;
}
.chip:hover  { border-color:var(--accent2); color:var(--accent); background:var(--accent-lt); }
.chip.active { border-color:var(--accent); color:var(--accent); background:var(--accent-lt); font-weight:700; }
.chip.active-gold { border-color:var(--gold); color:var(--gold); background:var(--gold-lt); font-weight:700; }
.chip.user-chip { border-color:rgba(26,158,110,0.4); color:var(--success); background:var(--suc-lt); }
.chip.user-chip:hover  { border-color:var(--success); background:rgba(26,158,110,0.18); }
.chip.user-chip.active { border-color:var(--success); background:rgba(26,158,110,0.22); font-weight:700; }
.chip-empty { font-size:13px; color:var(--muted); font-style:italic; padding:6px 0; }

/* Show more btn */
.show-more-btn {
  margin-top:10px; padding:7px 18px; background:transparent;
  border:1.5px dashed var(--border); border-radius:100px;
  font-size:12px; font-weight:600; color:var(--muted); cursor:pointer; transition:all 0.17s;
}
.show-more-btn:hover { border-color:var(--accent); color:var(--accent); }

/* Selected pill */
.selected-pill {
  display:none; align-items:center; gap:10px; margin-bottom:20px;
  background:var(--accent-lt); border:1.5px solid rgba(58,123,213,0.25);
  border-radius:12px; padding:12px 16px;
}
.selected-pill.show { display:flex; }
.selected-pill.gold { background:var(--gold-lt); border-color:rgba(217,123,42,0.30); }
.pill-icon { font-size:20px; }
.pill-info { flex:1; }
.pill-name { font-family:'Syne',sans-serif; font-size:16px; font-weight:700; color:var(--text); }
.pill-sub  { font-size:11px; margin-top:2px; }
.pill-sub.added   { color:var(--success); }
.pill-sub.existed { color:var(--accent); }
.pill-change { font-size:12px; color:var(--muted); cursor:pointer; text-decoration:underline; white-space:nowrap; }
.pill-change:hover { color:var(--accent); }

/* Niche step */
.niche-step { display:none; animation:slideUp 0.4s ease both; }
.niche-step.show { display:block; }
@keyframes slideUp { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

/* Result */
.result-area { display:none; margin-top:24px; animation:slideUp 0.4s ease both; }
.result-area.show { display:block; }
.result-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.result-card {
  background:var(--card2); border:1.5px solid var(--border); border-radius:13px;
  padding:18px; position:relative; overflow:hidden;
}
.result-card::before { content:''; position:absolute; top:0;left:0;right:0; height:3px; border-radius:13px 13px 0 0; }
.result-card.rc-ind::before { background:linear-gradient(90deg,var(--accent),var(--accent2)); }
.result-card.rc-niche::before { background:linear-gradient(90deg,var(--gold),#f09050); }
.rc-icon  { font-size:20px; margin-bottom:8px; display:block; }
.rc-type  { font-size:10px; text-transform:uppercase; letter-spacing:0.10em; font-weight:700; margin-bottom:4px; }
.result-card.rc-ind   .rc-type { color:var(--accent); }
.result-card.rc-niche .rc-type { color:var(--gold); }
.rc-value { font-family:'Syne',sans-serif; font-size:15px; font-weight:700; color:var(--text); line-height:1.3; }
.rc-id    { font-size:11px; color:var(--muted); margin-top:5px; }
.rc-status { display:inline-flex; font-size:11px; font-weight:600; padding:2px 9px; border-radius:100px; margin-top:7px; }
.rc-status.added   { background:var(--suc-lt); color:var(--success); }
.rc-status.existed { background:var(--accent-lt); color:var(--accent); }

/* Action buttons */
.action-row { display:flex; gap:10px; }
.btn-reset {
  flex:1; padding:13px; background:var(--card2); border:1.5px solid var(--border);
  border-radius:10px; font-family:'Syne',sans-serif; font-size:14px; font-weight:700;
  color:var(--muted); cursor:pointer; transition:all 0.2s;
}
.btn-reset:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-lt); }
.btn-confirm {
  flex:2; padding:13px; border:none; border-radius:10px;
  font-family:'Syne',sans-serif; font-size:14px; font-weight:700;
  color:#fff; cursor:pointer; transition:all 0.2s;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  box-shadow:0 4px 16px rgba(58,123,213,0.25);
}
.btn-confirm:hover { opacity:0.9; transform:translateY(-1px); }
.btn-confirm:disabled { opacity:0.4; cursor:not-allowed; transform:none; }

/* Loading spinner */
.spinner-wrap { padding:16px 0; color:var(--muted); font-size:13px; display:flex; align-items:center; gap:8px; }
.spinner { display:inline-block; width:14px; height:14px; border:2px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin 0.7s linear infinite; }
@keyframes spin { to{transform:rotate(360deg);} }

.error-msg { font-size:13px; color:var(--error); margin-top:8px; display:none; }

@media(max-width:480px) {
  .card { padding:22px 16px; }
  .result-grid { grid-template-columns:1fr; }
  .step-label { display:none; }
}
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="badge"><span class="badge-dot"></span>VideoVizard</div>
    <h1>Select Your <span>Industry & Niche</span></h1>
    <p class="subtitle">Choose your industry then pick your specific niche.<br>No typing needed — just click.</p>
  </div>

  <div class="card">

    <!-- Step indicators -->
    <div class="steps">
      <div class="step-item active" id="step1-item">
        <div class="step-num">1</div>
        <div class="step-label">Industry</div>
      </div>
      <div class="step-line" id="step-line"></div>
      <div class="step-item" id="step2-item">
        <div class="step-num">2</div>
        <div class="step-label">Niche</div>
      </div>
    </div>

    <!-- Selected industry pill -->
    <div class="selected-pill" id="ind-pill">
      <span class="pill-icon">&#127981;</span>
      <div class="pill-info">
        <div class="pill-name" id="ind-pill-name"></div>
        <div class="pill-sub"  id="ind-pill-sub"></div>
      </div>
      <span class="pill-change" onclick="resetIndustry()">Change</span>
    </div>

    <!-- STEP 1: Industry selection -->
    <div id="step1">

      <!-- Your industries -->
      <div class="section" id="user-ind-section">
        <div class="section-label">Your Industries</div>
        <div class="chips" id="user-ind-chips">
          <div class="spinner-wrap"><span class="spinner"></span> Loading...</div>
        </div>
      </div>

      <!-- Master industries -->
      <div class="section">
        <div class="section-label">All Industries</div>
        <div class="chips" id="master-ind-chips">
          <div class="spinner-wrap"><span class="spinner"></span> Loading...</div>
        </div>
        <button class="show-more-btn" id="show-more-btn" style="display:none;" onclick="showMoreIndustries()">
          &#43; Show More Industries
        </button>
      </div>

      <div class="error-msg" id="ind-error"></div>
    </div>

    <!-- STEP 2: Niche selection -->
    <div class="niche-step" id="step2">

      <!-- Selected niche pill -->
      <div class="selected-pill gold" id="niche-pill">
        <span class="pill-icon">&#127919;</span>
        <div class="pill-info">
          <div class="pill-name" id="niche-pill-name"></div>
          <div class="pill-sub"  id="niche-pill-sub"></div>
        </div>
        <span class="pill-change" onclick="resetNiche()">Change</span>
      </div>

      <!-- Your niches -->
      <div class="section" id="user-niche-section">
        <div class="section-label">Your Niches</div>
        <div class="chips" id="user-niche-chips">
          <div class="spinner-wrap"><span class="spinner"></span> Loading...</div>
        </div>
      </div>

      <!-- Master niches -->
      <div class="section">
        <div class="section-label">All Niches</div>
        <div class="chips" id="master-niche-chips">
          <div class="spinner-wrap"><span class="spinner"></span> Loading...</div>
        </div>
        <button class="show-more-btn" id="show-more-niche-btn" style="display:none;" onclick="showMoreNiches()">
          &#43; Show More Niches
        </button>
      </div>

      <div class="error-msg" id="niche-error"></div>
    </div>

    <!-- Result -->
    <div class="result-area" id="result-area">
      <div class="result-grid">
        <div class="result-card rc-ind">
          <span class="rc-icon">&#127981;</span>
          <div class="rc-type">Industry</div>
          <div class="rc-value"  id="res-ind-name"></div>
          <div class="rc-id"     id="res-ind-id"></div>
          <div class="rc-status" id="res-ind-status"></div>
        </div>
        <div class="result-card rc-niche">
          <span class="rc-icon">&#127919;</span>
          <div class="rc-type">Niche</div>
          <div class="rc-value"  id="res-niche-name"></div>
          <div class="rc-id"     id="res-niche-id"></div>
          <div class="rc-status" id="res-niche-status"></div>
        </div>
      </div>
      <div class="action-row">
        <button class="btn-reset" onclick="resetAll()">Start Over</button>
        <button class="btn-confirm" id="confirm-btn" onclick="confirmSelection()">&#10003; Confirm &amp; Continue</button>
      </div>
    </div>

  </div>
</div>

<script>
var PAGE_URL = window.location.href.split('?')[0];
var SHOW_INITIAL = 5; // industries shown before "show more"

// ── State ─────────────────────────────────────────────────────────────────────
var state = {
  industryId    : null,
  industryName  : '',
  industryStatus: '',
  nicheId       : null,
  nicheName     : '',
  nicheStatus   : '',
};

var allMasterIndustries = [];
var allMasterNiches     = [];
var userIndustryIds     = []; // track which master IDs user already has

// ── Init ──────────────────────────────────────────────────────────────────────
(function init() {
  loadUserIndustries();
  loadMasterIndustries();
})();

// ── Load user industries ──────────────────────────────────────────────────────
function loadUserIndustries() {
  fetch(PAGE_URL + '?action=get_user_industries')
    .then(function(r){ return r.json(); })
    .then(function(d) {
      var wrap = document.getElementById('user-ind-chips');
      wrap.innerHTML = '';
      if (!d.success || !d.industries.length) {
        document.getElementById('user-ind-section').style.display = 'none';
        return;
      }
      d.industries.forEach(function(ind) {
        userIndustryIds.push(ind.industry_id);
        wrap.appendChild(makeIndChip(ind, true));
      });
    });
}

// ── Load master industries ────────────────────────────────────────────────────
function loadMasterIndustries() {
  fetch(PAGE_URL + '?action=get_master_industries')
    .then(function(r){ return r.json(); })
    .then(function(d) {
      allMasterIndustries = d.industries || [];
      renderMasterIndustries();
    });
}

function renderMasterIndustries() {
  var wrap = document.getElementById('master-ind-chips');
  wrap.innerHTML = '';
  var shown = allMasterIndustries.slice(0, SHOW_INITIAL);
  shown.forEach(function(ind) {
    wrap.appendChild(makeIndChip(ind, false));
  });
  var moreBtn = document.getElementById('show-more-btn');
  moreBtn.style.display = allMasterIndustries.length > SHOW_INITIAL ? 'inline-block' : 'none';
  moreBtn.dataset.showing = SHOW_INITIAL;
}

function showMoreIndustries() {
  var wrap    = document.getElementById('master-ind-chips');
  var showing = parseInt(document.getElementById('show-more-btn').dataset.showing) || SHOW_INITIAL;
  var next    = showing + 10;
  var batch   = allMasterIndustries.slice(showing, next);
  batch.forEach(function(ind) {
    wrap.appendChild(makeIndChip(ind, false));
  });
  document.getElementById('show-more-btn').dataset.showing = next;
  if (next >= allMasterIndustries.length) {
    document.getElementById('show-more-btn').style.display = 'none';
  }
}

function makeIndChip(ind, isUser) {
  var chip = document.createElement('span');
  chip.className   = 'chip' + (isUser ? ' user-chip' : '');
  chip.textContent = ind.name;
  chip.onclick     = function() { selectIndustry(ind, isUser); };
  return chip;
}

// ── Select industry ───────────────────────────────────────────────────────────
function selectIndustry(ind, isUser) {
  // Mark chip active
  document.querySelectorAll('#step1 .chip').forEach(function(c){ c.classList.remove('active'); });
  event.target.classList.add('active');

  if (isUser) {
    // Already in user table — just use it
    state.industryId     = ind.industry_id;
    state.industryName   = ind.name;
    state.industryStatus = 'existed';
    afterIndustrySelected();
  } else {
    // Save to hdb_user_industries
    var fd = new FormData();
    fd.append('action',        'save_industry');
    fd.append('industry_id',   ind.id);
    fd.append('industry_desc', ind.name);
    fetch(PAGE_URL, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (!d.success) { showErr('ind-error', d.error || 'Save failed'); return; }
        state.industryId     = ind.id;
        state.industryName   = ind.name;
        state.industryStatus = d.status;
        afterIndustrySelected();
      })
      .catch(function(){ showErr('ind-error', 'Network error'); });
  }
}

function afterIndustrySelected() {
  // Show pill
  document.getElementById('ind-pill-name').textContent = state.industryName;
  var sub = document.getElementById('ind-pill-sub');
  sub.textContent = state.industryStatus === 'added' ? 'Added to your industries' : 'From your industries';
  sub.className   = 'pill-sub ' + state.industryStatus;
  document.getElementById('ind-pill').classList.add('show');

  // Update steps
  document.getElementById('step1-item').className = 'step-item done';
  document.getElementById('step2-item').className = 'step-item active';
  document.getElementById('step-line').style.background = 'var(--success)';

  // Hide step1, show step2
  document.getElementById('step1').style.display = 'none';
  document.getElementById('step2').classList.add('show');

  // Load niches
  loadNiches(state.industryId);
}

// ── Load niches ───────────────────────────────────────────────────────────────
function loadNiches(industryId) {
  // Load user niches and master niches in parallel
  var userWrap   = document.getElementById('user-niche-chips');
  var masterWrap = document.getElementById('master-niche-chips');
  userWrap.innerHTML   = '<div class="spinner-wrap"><span class="spinner"></span> Loading...</div>';
  masterWrap.innerHTML = '<div class="spinner-wrap"><span class="spinner"></span> Loading...</div>';

  // User niches
  fetch(PAGE_URL + '?action=get_user_niches&industry_id=' + industryId)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      userWrap.innerHTML = '';
      if (!d.success || !d.niches.length) {
        document.getElementById('user-niche-section').style.display = 'none';
        return;
      }
      d.niches.forEach(function(n) {
        userWrap.appendChild(makeNicheChip(n, true));
      });
    });

  // Master niches
  fetch(PAGE_URL + '?action=get_master_niches&industry_id=' + industryId)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      allMasterNiches = d.niches || [];
      renderMasterNiches();
    });
}

function renderMasterNiches() {
  var wrap = document.getElementById('master-niche-chips');
  wrap.innerHTML = '';
  if (!allMasterNiches.length) {
    wrap.innerHTML = '<span class="chip-empty">No niches available for this industry yet.</span>';
    return;
  }
  var shown = allMasterNiches.slice(0, 8);
  shown.forEach(function(n) {
    wrap.appendChild(makeNicheChip(n, false));
  });
  var moreBtn = document.getElementById('show-more-niche-btn');
  moreBtn.style.display   = allMasterNiches.length > 8 ? 'inline-block' : 'none';
  moreBtn.dataset.showing = 8;
}

function showMoreNiches() {
  var wrap    = document.getElementById('master-niche-chips');
  var showing = parseInt(document.getElementById('show-more-niche-btn').dataset.showing) || 8;
  var next    = showing + 10;
  allMasterNiches.slice(showing, next).forEach(function(n) {
    wrap.appendChild(makeNicheChip(n, false));
  });
  document.getElementById('show-more-niche-btn').dataset.showing = next;
  if (next >= allMasterNiches.length) {
    document.getElementById('show-more-niche-btn').style.display = 'none';
  }
}

function makeNicheChip(n, isUser) {
  var chip = document.createElement('span');
  chip.className   = 'chip' + (isUser ? ' user-chip' : '');
  chip.textContent = n.name;
  chip.onclick     = function() { selectNiche(n, isUser, chip); };
  return chip;
}

// ── Select niche ──────────────────────────────────────────────────────────────
function selectNiche(n, isUser, el) {
  document.querySelectorAll('#step2 .chip').forEach(function(c){ c.classList.remove('active'); });
  el.classList.add('active');

  if (isUser) {
    state.nicheId     = n.niche_id;
    state.nicheName   = n.name;
    state.nicheStatus = 'existed';
    afterNicheSelected();
  } else {
    var fd = new FormData();
    fd.append('action',      'save_niche');
    fd.append('niche_id',    n.id);
    fd.append('industry_id', state.industryId);
    fd.append('niche_name',  n.name);
    fetch(PAGE_URL, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (!d.success) { showErr('niche-error', d.error || 'Save failed'); return; }
        state.nicheId     = n.id;
        state.nicheName   = n.name;
        state.nicheStatus = d.status;
        afterNicheSelected();
      })
      .catch(function(){ showErr('niche-error', 'Network error'); });
  }
}

function afterNicheSelected() {
  // Show niche pill
  document.getElementById('niche-pill-name').textContent = state.nicheName;
  var sub = document.getElementById('niche-pill-sub');
  sub.textContent = state.nicheStatus === 'added' ? 'Added to your niches' : 'From your niches';
  sub.className   = 'pill-sub ' + state.nicheStatus;
  document.getElementById('niche-pill').classList.add('show');

  // Show result
  document.getElementById('res-ind-name').textContent    = state.industryName;
  document.getElementById('res-ind-id').textContent      = 'ID #' + state.industryId;
  document.getElementById('res-niche-name').textContent  = state.nicheName;
  document.getElementById('res-niche-id').textContent    = 'ID #' + state.nicheId;
  setStatus('res-ind-status',   state.industryStatus);
  setStatus('res-niche-status', state.nicheStatus);
  document.getElementById('result-area').classList.add('show');
}

// ── Confirm ───────────────────────────────────────────────────────────────────
function confirmSelection() {
  var btn = document.getElementById('confirm-btn');
  var bothExisted = state.industryStatus === 'existed' && state.nicheStatus === 'existed';
  btn.textContent = bothExisted ? '✓ Already in DB — Next' : '✓ Saved!';
  btn.style.background = bothExisted
    ? 'linear-gradient(135deg,var(--muted),#8fa3c0)'
    : 'var(--success)';
  btn.disabled = true;
  setTimeout(function() {
    resetAll();
    btn.textContent      = '✓ Confirm & Continue';
    btn.style.background = '';
    btn.disabled         = false;
  }, 1200);
}

// ── Resets ────────────────────────────────────────────────────────────────────
function resetAll() {
  state = { industryId:null, industryName:'', industryStatus:'', nicheId:null, nicheName:'', nicheStatus:'' };
  allMasterNiches = [];
  document.getElementById('ind-pill').classList.remove('show');
  document.getElementById('niche-pill').classList.remove('show');
  document.getElementById('result-area').classList.remove('show');
  document.getElementById('step2').classList.remove('show');
  document.getElementById('step1').style.display = '';
  document.getElementById('user-niche-section').style.display = '';
  document.getElementById('step1-item').className = 'step-item active';
  document.getElementById('step2-item').className = 'step-item';
  document.getElementById('step-line').style.background = '';
  document.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('active'); });
}

function resetIndustry() { resetAll(); }

function resetNiche() {
  state.nicheId = null; state.nicheName = ''; state.nicheStatus = '';
  document.getElementById('niche-pill').classList.remove('show');
  document.getElementById('result-area').classList.remove('show');
  document.querySelectorAll('#step2 .chip').forEach(function(c){ c.classList.remove('active'); });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setStatus(elId, status) {
  var el = document.getElementById(elId);
  if (status === 'added') { el.textContent = 'Newly added'; el.className = 'rc-status added'; }
  else { el.textContent = 'From your list'; el.className = 'rc-status existed'; }
}
function showErr(id, msg) {
  var el = document.getElementById(id);
  el.textContent = msg; el.style.display = 'block';
  setTimeout(function(){ el.style.display = 'none'; }, 4000);
}
</script>
</body>
</html>

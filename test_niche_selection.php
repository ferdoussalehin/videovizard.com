<?php
/**
 * niche_classifier.php — Two-step Industry + Category selector
 * Tables: hdb_master_industries, hdb_master_niches
 * PHP 7.2+ compatible
 */
include __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';
$openai_key = $apiKey;

// ── Title Case helper ─────────────────────────────────────────────────────────
function toTitleCase($str) {
    // Keep common abbreviations uppercase, title-case everything else
    $lower_words = array('a','an','the','and','but','or','for','nor','on','at','to','by','in','of','up','as');
    $words = explode(' ', strtolower(trim($str)));
    $result = array();
    foreach ($words as $i => $word) {
        if ($i === 0 || !in_array($word, $lower_words)) {
            $result[] = ucfirst($word);
        } else {
            $result[] = $word;
        }
    }
    return implode(' ', $result);
}

// ── OpenAI helper ─────────────────────────────────────────────────────────────
function callOpenAI($apiKey, $system, $user) {
    $payload = json_encode(array(
        'model'       => 'gpt-4o-mini',
        'messages'    => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => $user),
        ),
        'max_tokens'  => 80,
        'temperature' => 0.1,
    ));
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ),
        CURLOPT_TIMEOUT => 20,
    ));
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) return null;
    $raw = trim($data['choices'][0]['message']['content']);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);
    return json_decode($raw, true);
}

// ── Fuzzy match helper ────────────────────────────────────────────────────────
function fuzzyMatch($input, $list, $threshold) {
    $best_score = 0;
    $best_id    = null;
    $best_val   = null;
    foreach ($list as $id => $val) {
        similar_text(strtolower(trim($input)), strtolower(trim($val)), $pct);
        if ($pct > $best_score) {
            $best_score = $pct;
            $best_id    = $id;
            $best_val   = $val;
        }
    }
    if ($best_score >= $threshold) {
        return array('id' => $best_id, 'val' => $best_val, 'score' => $best_score);
    }
    return null;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_industries
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_industries') {
    header('Content-Type: application/json');
    $rows = array();
    $res  = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array('id' => (int)$r['id'], 'name' => trim($r['industry_desc']));
    }
    echo json_encode(array('success' => true, 'industries' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: get_categories
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_categories') {
    header('Content-Type: application/json');
    $industry_id = (int)(isset($_POST['industry_id']) ? $_POST['industry_id'] : 0);
    if (!$industry_id) { echo json_encode(array('success' => false, 'error' => 'No industry_id')); exit; }
    $rows = array();
    $res  = mysqli_query($conn, "SELECT id, niche_desc FROM hdb_master_niches WHERE master_industry_id = $industry_id ORDER BY niche_desc ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array('id' => (int)$r['id'], 'name' => trim($r['niche_desc']));
    }
    echo json_encode(array('success' => true, 'categories' => $rows));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: match_industry
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'match_industry') {
    header('Content-Type: application/json');
    $raw_input = trim(isset($_POST['input']) ? $_POST['input'] : '');
    if (!$raw_input) { echo json_encode(array('success' => false, 'error' => 'Empty input')); exit; }
    $now = date('Y-m-d H:i:s');

    // Load existing industries
    $existing = array();
    $res = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $existing[(int)$r['id']] = trim($r['industry_desc']);
    }

    $lines = array();
    foreach ($existing as $v) { $lines[] = '- ' . $v; }
    $list_str = !empty($lines) ? implode("\n", $lines) : '(none yet)';

    $system =
        "You are a business analyst. The user typed their industry or profession.\n\n"
      . "EXISTING INDUSTRIES IN DATABASE:\n" . $list_str . "\n\n"
      . "TASK: Return the best matching master industry.\n"
      . "- If an existing industry fits, return its EXACT name character-for-character\n"
      . "- Only create a new broad industry name if nothing fits\n"
      . "- Must be a broad category (e.g. 'Hospitality & Dining', 'Pet Services', 'Finance')\n"
      . "- NOT a specific niche (e.g. do not return 'Chinese Cuisine' — return 'Hospitality & Dining')\n"
      . "Return JSON: {\"industry\": \"...\"}. No markdown, no extra text.";

    $ai = callOpenAI($openai_key, $system, 'User typed: ' . $raw_input);
    if (!$ai || !isset($ai['industry'])) {
        echo json_encode(array('success' => false, 'error' => 'AI call failed')); exit;
    }
    $ai_name = toTitleCase(trim($ai['industry']));

    // Exact match
    $matched_id   = null;
    $matched_name = $ai_name;
    $status       = '';
    foreach ($existing as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($ai_name)) {
            $matched_id   = $id;
            $matched_name = $desc; // use exact DB casing
            break;
        }
    }
    // Fuzzy match
    if (!$matched_id && !empty($existing)) {
        $m = fuzzyMatch($ai_name, $existing, 60);
        if ($m) { $matched_id = $m['id']; $matched_name = $m['val']; }
    }
    // Insert if new
    if ($matched_id) {
        $status = 'existed';
    } else {
        $esc = mysqli_real_escape_string($conn, $ai_name);
        mysqli_query($conn, "INSERT INTO hdb_master_industries (industry_desc, created_at, updated_at) VALUES ('$esc', '$now', '$now')");
        $matched_id   = (int)mysqli_insert_id($conn);
        $matched_name = $ai_name;
        $status       = 'added';
    }

    echo json_encode(array(
        'success'     => true,
        'industry_id' => $matched_id,
        'name'        => $matched_name,
        'status'      => $status,
    ));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: match_category
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'match_category') {
    header('Content-Type: application/json');
    $raw_input     = trim(isset($_POST['input'])          ? $_POST['input']          : '');
    $industry_id   = (int)(isset($_POST['industry_id'])   ? $_POST['industry_id']   : 0);
    $industry_name = trim(isset($_POST['industry_name'])  ? $_POST['industry_name'] : '');
    $exact_mode    = (isset($_POST['exact']) && $_POST['exact'] === '1');

    if (!$raw_input || !$industry_id) {
        echo json_encode(array('success' => false, 'error' => 'Missing input')); exit;
    }
    $now = date('Y-m-d H:i:s');

    // Load existing niches for this industry
    $existing = array();
    $res = mysqli_query($conn, "SELECT id, niche_desc FROM hdb_master_niches WHERE master_industry_id = $industry_id ORDER BY niche_desc ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $existing[(int)$r['id']] = trim($r['niche_desc']);
    }

    // Determine save name
    if ($exact_mode) {
        // User's typed value — apply Title Case, skip AI
        $save_name = toTitleCase($raw_input);
    } else {
        $lines = array();
        foreach ($existing as $v) { $lines[] = '- ' . $v; }
        $list_str = !empty($lines) ? implode("\n", $lines) : '(none yet)';

        $system =
            "You are a business analyst. The master industry is: \"" . $industry_name . "\".\n"
          . "The user typed their specific category/niche within this industry.\n\n"
          . "EXISTING CATEGORIES:\n" . $list_str . "\n\n"
          . "TASK: Return the best matching category.\n"
          . "- If an existing category fits, return its EXACT name character-for-character\n"
          . "- Only create a new name if nothing fits\n"
          . "- Must be specific sub-category of \"" . $industry_name . "\", NOT the industry name itself\n"
          . "Return JSON: {\"category\": \"...\"}. No markdown, no extra text.";

        $ai = callOpenAI($openai_key, $system, 'User typed: ' . $raw_input);
        if (!$ai || !isset($ai['category'])) {
            echo json_encode(array('success' => false, 'error' => 'AI call failed')); exit;
        }
        $save_name = toTitleCase(trim($ai['category']));
    }

    // Exact DB match
    $matched_id   = null;
    $matched_name = $save_name;
    $status       = '';
    foreach ($existing as $id => $desc) {
        if (strtolower(trim($desc)) === strtolower($save_name)) {
            $matched_id   = $id;
            $matched_name = $desc;
            break;
        }
    }
    // Fuzzy only if not exact mode
    if (!$matched_id && !$exact_mode && !empty($existing)) {
        $m = fuzzyMatch($save_name, $existing, 60);
        if ($m) { $matched_id = $m['id']; $matched_name = $m['val']; }
    }
    // Insert if new
    if ($matched_id) {
        $status = 'existed';
    } else {
        $esc = mysqli_real_escape_string($conn, $save_name);
        mysqli_query($conn, "INSERT INTO hdb_master_niches (master_industry_id, niche_desc, created_at, updated_at) VALUES ($industry_id, '$esc', '$now', '$now')");
        $matched_id   = (int)mysqli_insert_id($conn);
        $matched_name = $save_name;
        $status       = 'added';
    }

    echo json_encode(array(
        'success'     => true,
        'category_id' => $matched_id,
        'name'        => $matched_name,
        'status'      => $status,
    ));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Industry & Category Selector — VideoVizard</title>
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
.container { width: 100%; max-width: 620px; }

/* ── Header ── */
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
h1 span { color: var(--accent); }
.subtitle { color:var(--muted); font-size:14px; line-height:1.65; }

/* ── Card ── */
.card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:36px; box-shadow:var(--shadow); }

/* ── Steps ── */
.steps { display:flex; margin-bottom:32px; }
.step-item { flex:1; display:flex; align-items:center; }
.step-num {
  width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-size:13px; font-weight:800;
  background:var(--card2); border:2px solid var(--border); color:var(--muted);
  transition:all 0.3s; flex-shrink:0;
}
.step-label { font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; margin-left:9px; transition:color 0.3s; white-space:nowrap; }
.step-line  { flex:1; height:2px; background:var(--border); margin:0 12px; transition:background 0.3s; }
.step-item.active .step-num   { background:var(--accent); border-color:var(--accent); color:#fff; }
.step-item.active .step-label { color:var(--accent); }
.step-item.done   .step-num   { background:var(--success); border-color:var(--success); color:#fff; }
.step-item.done   .step-label { color:var(--success); }

/* ── Field ── */
.field-label { display:block; font-size:11.5px; font-weight:700; color:var(--muted); letter-spacing:0.09em; text-transform:uppercase; margin-bottom:8px; }

/* ── Autocomplete ── */
.autocomplete-wrap { position:relative; }
.ac-input {
  width:100%; background:var(--card2); border:1.5px solid var(--border); border-radius:10px;
  padding:14px 44px 14px 16px; font-size:15px; font-family:'DM Sans',sans-serif;
  color:var(--text); outline:none; transition:border-color 0.2s, box-shadow 0.2s;
}
.ac-input::placeholder { color:#b0bdd4; }
.ac-input:focus { border-color:var(--accent2); background:#fff; box-shadow:0 0 0 3px rgba(58,123,213,0.11); }
.ac-input:disabled { opacity:0.5; cursor:not-allowed; }
.ac-spinner {
  position:absolute; right:14px; top:50%; transform:translateY(-50%);
  width:16px; height:16px; border:2px solid var(--border); border-top-color:var(--accent);
  border-radius:50%; animation:spin 0.7s linear infinite; display:none;
}
@keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }

/* ── Dropdown ── */
.ac-dropdown {
  position:absolute; top:calc(100% + 6px); left:0; right:0;
  background:var(--card); border:1.5px solid var(--border); border-radius:12px;
  box-shadow:0 8px 32px rgba(58,100,180,0.15); z-index:100;
  max-height:220px; overflow-y:auto; display:none;
}
.ac-item {
  padding:11px 16px; font-size:14px; color:var(--text); cursor:pointer;
  border-bottom:1px solid var(--border); transition:background 0.15s;
  display:flex; align-items:center; justify-content:space-between;
}
.ac-item:last-child { border-bottom:none; }
.ac-item:hover, .ac-item.highlighted { background:var(--accent-lt); }
.ac-badge { font-size:10px; font-weight:700; color:var(--accent); background:var(--accent-lt); padding:2px 8px; border-radius:100px; }
.ac-item.new-item { color:var(--accent); font-style:italic; }
.ac-item.new-item .ac-badge { background:var(--suc-lt); color:var(--success); }

/* ── Selected pill ── */
.selected-pill {
  display:none; align-items:center; gap:10px; margin-top:10px;
  background:var(--accent-lt); border:1.5px solid rgba(58,123,213,0.22);
  border-radius:10px; padding:10px 14px;
}
.selected-pill.show { display:flex; }
.selected-pill.gold-pill { background:var(--gold-lt); border-color:rgba(217,123,42,0.30); }
.pill-icon { font-size:18px; }
.pill-text { flex:1; }
.pill-name { font-family:'Syne',sans-serif; font-size:15px; font-weight:700; color:var(--text); }
.pill-sub  { font-size:11px; color:var(--muted); margin-top:1px; }
.pill-sub.added   { color:var(--success); }
.pill-sub.existed { color:var(--accent); }
.pill-change { font-size:12px; color:var(--muted); cursor:pointer; text-decoration:underline; white-space:nowrap; }
.pill-change:hover { color:var(--accent); }

/* ── Category section ── */
.cat-section { display:none; margin-top:28px; animation:slideUp 0.4s ease both; }
.cat-section.show { display:block; }
@keyframes slideUp { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

.cat-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; min-height:32px; }
.cat-chip {
  background:var(--card2); border:1.5px solid var(--border); border-radius:100px;
  padding:7px 16px; font-size:13px; font-weight:500; color:var(--muted);
  cursor:pointer; transition:all 0.17s; user-select:none;
}
.cat-chip:hover  { border-color:var(--accent2); color:var(--accent); background:var(--accent-lt); }
.cat-chip.active { border-color:var(--gold); color:var(--gold); background:var(--gold-lt); font-weight:700; }
.cat-empty { font-size:13px; color:var(--muted); font-style:italic; padding:4px 0; }

.cat-divider {
  display:flex; align-items:center; gap:10px; margin:14px 0;
  font-size:10.5px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--muted);
}
.cat-divider::before,.cat-divider::after { content:''; flex:1; height:1px; background:var(--border); }

/* ── Result ── */
.result-area { display:none; margin-top:28px; animation:slideUp 0.4s ease both; }
.result-area.show { display:block; }
.result-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.result-card {
  background:var(--card2); border:1.5px solid var(--border); border-radius:13px;
  padding:18px 18px 15px; position:relative; overflow:hidden;
}
.result-card::before { content:''; position:absolute; top:0;left:0;right:0; height:3px; border-radius:13px 13px 0 0; }
.result-card.rc-ind::before { background:linear-gradient(90deg, var(--accent), var(--accent2)); }
.result-card.rc-cat::before { background:linear-gradient(90deg, var(--gold), #f09050); }
.rc-icon  { font-size:22px; margin-bottom:8px; display:block; }
.rc-type  { font-size:10px; text-transform:uppercase; letter-spacing:0.10em; font-weight:700; margin-bottom:4px; }
.result-card.rc-ind .rc-type { color:var(--accent); }
.result-card.rc-cat .rc-type { color:var(--gold); }
.rc-value  { font-family:'Syne',sans-serif; font-size:16px; font-weight:700; color:var(--text); line-height:1.3; }
.rc-id     { font-size:11px; color:var(--muted); margin-top:6px; }
.rc-status { display:inline-flex; align-items:center; font-size:11px; font-weight:600; padding:2px 9px; border-radius:100px; margin-top:8px; }
.rc-status.added   { background:var(--suc-lt); color:var(--success); }
.rc-status.existed { background:var(--accent-lt); color:var(--accent); }

.result-actions { display:flex; gap:10px; }
.btn-reset {
  flex:1; padding:13px; background:var(--card2); border:1.5px solid var(--border);
  border-radius:10px; font-family:'Syne',sans-serif; font-size:14px; font-weight:700;
  color:var(--muted); cursor:pointer; transition:all 0.2s;
}
.btn-reset:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-lt); }
.btn-confirm {
  flex:2; padding:13px; background:linear-gradient(135deg, var(--accent), var(--accent2));
  border:none; border-radius:10px; font-family:'Syne',sans-serif; font-size:14px; font-weight:700;
  color:#fff; cursor:pointer; box-shadow:0 4px 16px rgba(58,123,213,0.25); transition:all 0.2s;
}
.btn-confirm:hover { opacity:0.9; transform:translateY(-1px); }

.error-msg { font-size:13px; color:var(--error); margin-top:8px; display:none; }

@media(max-width:480px){
  .card { padding:24px 18px; }
  .result-grid { grid-template-columns:1fr; }
  .step-label { display:none; }
}
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="badge"><span class="badge-dot"></span>VideoVizard</div>
    <h1>Industry &amp; <span>Category</span></h1>
    <p class="subtitle">Type your industry — AI finds the best match.<br>Then pick or add your specific category.</p>
  </div>

  <div class="card">

    <!-- Step indicators -->
    <div class="steps">
      <div class="step-item active" id="step1-ind">
        <div class="step-num">1</div>
        <div class="step-label">Industry</div>
      </div>
      <div class="step-line" id="step-line"></div>
      <div class="step-item" id="step2-ind">
        <div class="step-num">2</div>
        <div class="step-label">Category</div>
      </div>
    </div>

    <!-- Step 1: Industry -->
    <div id="industry-field">
      <label class="field-label">What is your industry or profession?</label>
      <div class="autocomplete-wrap">
        <input type="text" class="ac-input" id="industry-input"
               placeholder="e.g. Beauty, Pet Services, Finance, Restaurant..."
               autocomplete="off">
        <div class="ac-spinner" id="industry-spinner"></div>
        <div class="ac-dropdown" id="industry-dropdown"></div>
      </div>
      <div class="error-msg" id="industry-error"></div>
      <div class="selected-pill" id="industry-pill">
        <span class="pill-icon">&#127981;</span>
        <div class="pill-text">
          <div class="pill-name" id="pill-industry-name"></div>
          <div class="pill-sub"  id="pill-industry-sub"></div>
        </div>
        <span class="pill-change" onclick="resetIndustry()">Change</span>
      </div>
    </div>

    <!-- Step 2: Category -->
    <div class="cat-section" id="cat-section">
      <label class="field-label">What is your specific category?</label>
      <div class="cat-chips" id="cat-chips"></div>
      <div class="cat-divider">or type your own</div>
      <div class="autocomplete-wrap">
        <input type="text" class="ac-input" id="cat-input"
               placeholder="Type a category..."
               autocomplete="off">
        <div class="ac-spinner" id="cat-spinner"></div>
      </div>
      <div class="error-msg" id="cat-error"></div>
      <div class="selected-pill gold-pill" id="cat-pill">
        <span class="pill-icon">&#127919;</span>
        <div class="pill-text">
          <div class="pill-name" id="pill-cat-name"></div>
          <div class="pill-sub"  id="pill-cat-sub"></div>
        </div>
        <span class="pill-change" onclick="resetCategory()">Change</span>
      </div>
    </div>

    <!-- Result -->
    <div class="result-area" id="result-area">
      <div class="result-grid">
        <div class="result-card rc-ind">
          <span class="rc-icon">&#127981;</span>
          <div class="rc-type">Master Industry</div>
          <div class="rc-value"  id="res-industry"></div>
          <div class="rc-id"     id="res-industry-id"></div>
          <div class="rc-status" id="res-industry-status"></div>
        </div>
        <div class="result-card rc-cat">
          <span class="rc-icon">&#127919;</span>
          <div class="rc-type">Category</div>
          <div class="rc-value"  id="res-cat"></div>
          <div class="rc-id"     id="res-cat-id"></div>
          <div class="rc-status" id="res-cat-status"></div>
        </div>
      </div>
      <div class="result-actions">
        <button class="btn-reset"   onclick="resetAll()">Start Over</button>
        <button class="btn-confirm" onclick="confirmSelection()">&#10003; Confirm &amp; Use This</button>
      </div>
    </div>

  </div>
</div>

<script>
var PAGE_URL = window.location.href;

var state = {
  allIndustries : [],
  industryId    : null,
  industryName  : '',
  industryStatus: '',
  catId         : null,
  catName       : '',
  catStatus     : '',
};

// Title Case in JS
function toTitleCase(str) {
  var lowerWords = ['a','an','the','and','but','or','for','nor','on','at','to','by','in','of','up','as'];
  return str.toLowerCase().replace(/\w\S*/g, function(word, idx) {
    if (idx === 0 || lowerWords.indexOf(word) === -1) {
      return word.charAt(0).toUpperCase() + word.slice(1);
    }
    return word;
  });
}

// ── Init ──────────────────────────────────────────────────────────────────────
(function init() {
  var fd = new FormData();
  fd.append('action', 'get_industries');
  fetch(PAGE_URL, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.success) state.allIndustries = d.industries; });

  document.getElementById('industry-input').addEventListener('input', onIndustryType);
  document.getElementById('industry-input').addEventListener('keydown', onIndustryKey);
  document.getElementById('cat-input').addEventListener('keydown', onCatKey);
  document.getElementById('cat-input').addEventListener('blur', function(){
    var val = this.value.trim();
    if (val) { setTimeout(function(){ matchCatWithAI(val); }, 150); }
  });
})();

// ── Industry autocomplete ─────────────────────────────────────────────────────
var indDebounce  = null;
var indHighlight = -1;

function onIndustryType() {
  var val = document.getElementById('industry-input').value.trim();
  clearTimeout(indDebounce);
  if (!val) { closeDropdown('industry-dropdown'); return; }
  indDebounce = setTimeout(function(){ showIndustryDropdown(val); }, 200);
}

function showIndustryDropdown(val) {
  var dd  = document.getElementById('industry-dropdown');
  var low = val.toLowerCase();
  var matches = state.allIndustries.filter(function(i){
    return i.name.toLowerCase().indexOf(low) !== -1;
  });
  dd.innerHTML = '';
  indHighlight = -1;
  matches.forEach(function(i) {
    var div = document.createElement('div');
    div.className = 'ac-item';
    div.innerHTML = '<span>' + escHtml(i.name) + '</span><span class="ac-badge">existing</span>';
    div.onclick   = function(){ selectIndustryExisting(i); };
    dd.appendChild(div);
  });
  var exactMatch = state.allIndustries.some(function(i){ return i.name.toLowerCase() === low; });
  if (!exactMatch) {
    var div = document.createElement('div');
    div.className = 'ac-item new-item';
    div.innerHTML = '<span>Use "' + escHtml(toTitleCase(val)) + '" (AI will match/create)</span><span class="ac-badge">new</span>';
    div.onclick   = function(){ matchIndustryWithAI(val); };
    dd.appendChild(div);
  }
  dd.style.display = dd.children.length > 0 ? 'block' : 'none';
}

function onIndustryKey(e) {
  var dd    = document.getElementById('industry-dropdown');
  var items = dd.querySelectorAll('.ac-item');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    indHighlight = Math.min(indHighlight + 1, items.length - 1);
    items.forEach(function(it, i){ it.classList.toggle('highlighted', i === indHighlight); });
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    indHighlight = Math.max(indHighlight - 1, 0);
    items.forEach(function(it, i){ it.classList.toggle('highlighted', i === indHighlight); });
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (indHighlight >= 0 && items[indHighlight]) {
      items[indHighlight].click();
    } else {
      var val = document.getElementById('industry-input').value.trim();
      if (val) matchIndustryWithAI(val);
    }
  } else if (e.key === 'Escape') {
    closeDropdown('industry-dropdown');
  }
}

function selectIndustryExisting(ind) {
  closeDropdown('industry-dropdown');
  state.industryId     = ind.id;
  state.industryName   = ind.name;
  state.industryStatus = 'existed';
  var typed = document.getElementById('industry-input').value.trim();
  showIndustryPill();
  loadCategories(ind.id, typed);
}

function matchIndustryWithAI(val) {
  closeDropdown('industry-dropdown');
  setSpinner('industry-spinner', true);
  document.getElementById('industry-input').disabled = true;
  var fd = new FormData();
  fd.append('action', 'match_industry');
  fd.append('input',  val);
  fetch(PAGE_URL, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      setSpinner('industry-spinner', false);
      document.getElementById('industry-input').disabled = false;
      if (!d.success) { showErr('industry-error', d.error || 'AI failed'); return; }
      state.industryId     = d.industry_id;
      state.industryName   = d.name;
      state.industryStatus = d.status;
      if (d.status === 'added') {
        state.allIndustries.push({ id: d.industry_id, name: d.name });
      }
      showIndustryPill();
      loadCategories(d.industry_id, val);
    })
    .catch(function(){ setSpinner('industry-spinner', false); showErr('industry-error', 'Network error'); });
}

function showIndustryPill() {
  document.getElementById('industry-input').style.display = 'none';
  var pill = document.getElementById('industry-pill');
  pill.classList.add('show');
  document.getElementById('pill-industry-name').textContent = toTitleCase(state.industryName);
  var sub = document.getElementById('pill-industry-sub');
  sub.textContent = state.industryStatus === 'added' ? 'Newly added to database' : 'Matched from database';
  sub.className   = 'pill-sub ' + state.industryStatus;
  document.getElementById('step1-ind').className = 'step-item done';
  document.getElementById('step2-ind').className = 'step-item active';
  document.getElementById('step-line').style.background = 'var(--success)';
}

// ── Categories ────────────────────────────────────────────────────────────────
function loadCategories(industryId, userTyped) {
  var chips = document.getElementById('cat-chips');
  chips.innerHTML = '<span class="cat-empty">Loading...</span>';
  document.getElementById('cat-section').classList.add('show');
  document.getElementById('cat-input').value = '';

  var fd = new FormData();
  fd.append('action',      'get_categories');
  fd.append('industry_id', industryId);
  fetch(PAGE_URL, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      chips.innerHTML = '';
      var categories = (d.success && d.categories) ? d.categories : [];

      // Check fuzzy match with userTyped
      var matchedCat = null;
      if (userTyped) {
        var low = userTyped.toLowerCase();
        categories.forEach(function(c){
          if (!matchedCat && (
            c.name.toLowerCase().indexOf(low) !== -1 ||
            low.indexOf(c.name.toLowerCase()) !== -1
          )) { matchedCat = c; }
        });
      }

      // Render existing chips
      if (!categories.length && !userTyped) {
        chips.innerHTML = '<span class="cat-empty">No categories yet — type yours below</span>';
      } else {
        categories.forEach(function(c){
          var span = document.createElement('span');
          span.className   = 'cat-chip';
          span.textContent = c.name;
          span.onclick     = function(){ selectCatChip(c, span); };
          chips.appendChild(span);
        });
      }

      // Auto-select matched existing chip
      if (matchedCat) {
        document.querySelectorAll('.cat-chip').forEach(function(el){
          if (el.textContent === matchedCat.name) selectCatChip(matchedCat, el);
        });
        return;
      }

      // userTyped not found — add as pre-selected chip at start, save directly
      if (userTyped) {
        var displayName = toTitleCase(userTyped);
        var span = document.createElement('span');
        span.className   = 'cat-chip active';
        span.textContent = displayName;
        chips.firstChild
          ? chips.insertBefore(span, chips.firstChild)
          : chips.appendChild(span);
        span.onclick = function() {
          document.querySelectorAll('.cat-chip').forEach(function(c){ c.classList.remove('active'); });
          span.classList.add('active');
          state.catName = displayName;
          showResult();
        };
        saveCategoryDirect(userTyped);
      } else {
        document.getElementById('cat-input').focus();
      }
    });
}

function selectCatChip(cat, el) {
  document.querySelectorAll('.cat-chip').forEach(function(c){ c.classList.remove('active'); });
  el.classList.add('active');
  state.catId     = cat.id;
  state.catName   = cat.name;
  state.catStatus = 'existed';
  document.getElementById('cat-input').value = '';
  showCatPill();
}

function onCatKey(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    var val = document.getElementById('cat-input').value.trim();
    if (val) matchCatWithAI(val);
  }
}

function matchCatWithAI(val) {
  if (!state.industryId) return;
  setSpinner('cat-spinner', true);
  document.getElementById('cat-input').disabled = true;
  var fd = new FormData();
  fd.append('action',        'match_category');
  fd.append('input',         val);
  fd.append('industry_id',   state.industryId);
  fd.append('industry_name', state.industryName);
  fetch(PAGE_URL, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      setSpinner('cat-spinner', false);
      document.getElementById('cat-input').disabled = false;
      if (!d.success) { showErr('cat-error', d.error || 'AI failed'); return; }
      state.catId     = d.category_id;
      state.catName   = d.name;
      state.catStatus = d.status;
      showCatPill();
    })
    .catch(function(){ setSpinner('cat-spinner', false); showErr('cat-error', 'Network error'); });
}

function saveCategoryDirect(val) {
  if (!state.industryId) return;
  var fd = new FormData();
  fd.append('action',        'match_category');
  fd.append('input',         val);
  fd.append('industry_id',   state.industryId);
  fd.append('industry_name', state.industryName);
  fd.append('exact',         '1');
  fetch(PAGE_URL, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.success) { showErr('cat-error', d.error || 'Save failed'); return; }
      state.catId     = d.category_id;
      state.catName   = toTitleCase(val);
      state.catStatus = d.status;
      showCatPill();
    })
    .catch(function(){ showErr('cat-error', 'Network error'); });
}

function showCatPill() {
  document.getElementById('cat-input').style.display = 'none';
  document.getElementById('cat-input').disabled      = false;
  var pill = document.getElementById('cat-pill');
  pill.classList.add('show');
  document.getElementById('pill-cat-name').textContent = toTitleCase(state.catName);
  var sub = document.getElementById('pill-cat-sub');
  sub.textContent = state.catStatus === 'added' ? 'Newly added to database' : 'Matched from database';
  sub.className   = 'pill-sub ' + state.catStatus;
  showResult();
}

// ── Result ────────────────────────────────────────────────────────────────────
function showResult() {
  // Restore result-actions in case it was replaced in a previous run
  var actions = document.querySelector('.result-actions');
  actions.innerHTML =
    '<button class="btn-reset" onclick="resetAll()">Start Over</button>' +
    '<button class="btn-confirm" onclick="confirmSelection()">&#10003; Confirm &amp; Use This</button>';

  document.getElementById('result-area').classList.add('show');
  document.getElementById('res-industry').textContent    = toTitleCase(state.industryName);
  document.getElementById('res-industry-id').textContent = 'ID #' + state.industryId;
  document.getElementById('res-cat').textContent         = toTitleCase(state.catName);
  document.getElementById('res-cat-id').textContent      = 'ID #' + state.catId;
  setRcStatus('res-industry-status', state.industryStatus);
  setRcStatus('res-cat-status',      state.catStatus);

  // If both already exist, skip confirm and auto-reset
  checkAndAutoConfirm();
}

function setRcStatus(elId, status) {
  var el = document.getElementById(elId);
  if (status === 'added') { el.textContent = 'Newly added'; el.className = 'rc-status added'; }
  else { el.textContent = 'From database'; el.className = 'rc-status existed'; }
}

// ── Confirm — save only if new, otherwise just acknowledge and reset ──────────
function confirmSelection() {
  var btn = document.querySelector('.btn-confirm');
  btn.textContent      = '✓ Saved!';
  btn.style.background = 'var(--success)';
  btn.disabled         = true;
  setTimeout(function() {
    resetAll();
    btn.textContent      = '✓ Confirm & Use This';
    btn.style.background = '';
    btn.disabled         = false;
  }, 1200);
}

// ── Update confirm button label based on status ───────────────────────────────
function checkAndAutoConfirm() {
  var btn = document.querySelector('.btn-confirm');
  if (!btn) return;
  if (state.industryStatus === 'existed' && state.catStatus === 'existed') {
    btn.textContent      = '&#10003; Already in DB — Next';
    btn.innerHTML        = '&#10003; Already in DB &mdash; Next';
    btn.style.background = 'linear-gradient(135deg, var(--muted), #8fa3c0)';
  } else {
    btn.innerHTML        = '&#10003; Confirm &amp; Save';
    btn.style.background = '';
  }
}

// ── Resets ────────────────────────────────────────────────────────────────────
function resetAll() {
  state.industryId = null; state.industryName = ''; state.industryStatus = '';
  state.catId      = null; state.catName      = ''; state.catStatus      = '';

  document.getElementById('industry-input').style.display = '';
  document.getElementById('industry-input').value         = '';
  document.getElementById('industry-input').disabled      = false;
  document.getElementById('industry-input').focus();
  document.getElementById('industry-pill').classList.remove('show');
  document.getElementById('cat-section').classList.remove('show');
  document.getElementById('cat-pill').classList.remove('show');
  document.getElementById('result-area').classList.remove('show');
  document.getElementById('cat-input').style.display = '';
  document.getElementById('cat-input').value         = '';
  document.getElementById('step1-ind').className = 'step-item active';
  document.getElementById('step2-ind').className = 'step-item';
  document.getElementById('step-line').style.background = '';
}

function resetIndustry() { resetAll(); }

function resetCategory() {
  state.catId = null; state.catName = ''; state.catStatus = '';
  document.getElementById('cat-input').style.display = '';
  document.getElementById('cat-input').value         = '';
  document.getElementById('cat-input').disabled      = false;
  document.getElementById('cat-pill').classList.remove('show');
  document.getElementById('result-area').classList.remove('show');
  document.querySelectorAll('.cat-chip').forEach(function(c){ c.classList.remove('active'); });
  document.getElementById('cat-input').focus();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function closeDropdown(id) { document.getElementById(id).style.display = 'none'; }
function setSpinner(id, on) { document.getElementById(id).style.display = on ? 'block' : 'none'; }
function showErr(id, msg) {
  var el = document.getElementById(id);
  el.textContent = msg; el.style.display = 'block';
  setTimeout(function(){ el.style.display = 'none'; }, 4000);
}
function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.autocomplete-wrap')) closeDropdown('industry-dropdown');
});
</script> 
</body>
</html>

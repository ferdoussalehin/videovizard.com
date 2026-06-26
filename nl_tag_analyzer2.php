<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
ini_set('memory_limit', '512M');
set_time_limit(120);

require 'config.php';
require 'dbconnect_hdb.php';

// ── Cosine similarity ─────────────────────────────────────────
function cosineSimilarity($a, $b) {
    $dot = 0; $na = 0; $nb = 0;
    for ($i = 0, $len = count($a); $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0;
}

// ── OpenAI embedding ──────────────────────────────────────────
function getEmbedding($text, $apiKey) {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'text-embedding-3-large', 'input' => $text]),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $d = json_decode($resp, true);
    return $d['data'][0]['embedding'] ?? null;
}

// ═══════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ═══════════════════════════════════════════════════════════════

// ── Industries ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'industries') {
    ob_end_clean(); header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn, "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = ['id' => (int)$row['id'], 'name' => $row['industry_desc']];
    echo json_encode($rows); exit;
}

// ── Niches for industry ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'niches') {
    ob_end_clean(); header('Content-Type: application/json');
    $indId = (int)($_GET['industry_id'] ?? 0);
    $rows = [];
    if ($indId) {
        $r = mysqli_query($conn, "SELECT id, niche_desc, aliases FROM hdb_master_niches WHERE master_industry_id=$indId ORDER BY niche_desc ASC");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = ['id' => (int)$row['id'], 'name' => $row['niche_desc'], 'aliases' => $row['aliases'] ?? ''];
    }
    echo json_encode($rows); exit;
}

// ── Podcast ai_group / ai_subgroup ───────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'podcast_groups') {
    ob_end_clean(); header('Content-Type: application/json');
    $vid = (int)($_GET['video_id'] ?? 0);
    if (!$vid) { echo json_encode(['ai_group'=>'','ai_subgroup'=>'']); exit; }
    $pg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ai_group, ai_subgroup FROM hdb_podcasts WHERE id=$vid LIMIT 1"));
    $aiGroup    = $pg['ai_group']    ?? '';
    $aiSubgroup = $pg['ai_subgroup'] ?? '';
    $aliases = '';
    if ($aiSubgroup) {
        $aEsc = mysqli_real_escape_string($conn, $aiSubgroup);
        $ar = mysqli_fetch_assoc(mysqli_query($conn, "SELECT aliases FROM hdb_master_niches WHERE niche_desc='$aEsc' LIMIT 1"));
        $aliases = $ar['aliases'] ?? '';
    }
    echo json_encode(['ai_group' => $aiGroup, 'ai_subgroup' => $aiSubgroup, 'aliases' => $aliases]);
    exit;
}

// ── Videos ───────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'videos') {
    ob_end_clean(); header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn, "SELECT id, title FROM hdb_podcasts ORDER BY id DESC LIMIT 200");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = ['id' => (int)$row['id'], 'title' => $row['title'] ?? 'Untitled #'.$row['id']];
    echo json_encode($rows); exit;
}

// ── Scenes for video ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'scenes') {
    ob_end_clean(); header('Content-Type: application/json');
    $vid = (int)($_GET['video_id'] ?? 0);
    $rows = [];
    if ($vid) {
        $r = mysqli_query($conn, "SELECT id, scene_order, natural_language_tags, prompt FROM hdb_podcast_stories WHERE podcast_id=$vid ORDER BY scene_order ASC");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    }
    echo json_encode($rows); exit;
}

// ── Niche asset count ────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'niche_count') {
    ob_end_clean(); header('Content-Type: application/json');
    $nn = mysqli_real_escape_string($conn, trim($_GET['niche_name'] ?? ''));
    $t  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_data WHERE ai_subgroup='$nn'"));
    $e  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_data WHERE ai_subgroup='$nn' AND embedding IS NOT NULL AND embedding != ''"));
    echo json_encode(['total' => (int)$t['cnt'], 'with_embedding' => (int)$e['cnt']]);
    exit;
}

// ── Industry name lookup (for ai_group) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'industry_name') {
    ob_end_clean(); header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $row = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT industry_desc FROM hdb_master_industries WHERE id=$id")) : null;
    echo json_encode(['name' => $row ? $row['industry_desc'] : '']);
    exit;
}

// ── Embedding search ──────────────────────────────────────────
if (isset($_POST['ajax_search'])) {
    ob_end_clean(); header('Content-Type: application/json');

    $nlTags     = trim($_POST['nl_tags']      ?? '');
    $nicheName  = trim($_POST['niche_name']   ?? '');
    $aiGroup    = trim($_POST['ai_group']     ?? '');
    $aiSubgroup = trim($_POST['ai_subgroup']  ?? '');
    $topN       = max(1, min(100, (int)($_POST['top_n'] ?? 20)));

    $filterSubgroup = $aiSubgroup ?: $nicheName;

    if (!$nlTags)         { echo json_encode(['error' => 'NL tags required']);             exit; }
    if (!$filterSubgroup) { echo json_encode(['error' => 'Niche / ai_subgroup required']); exit; }

    // Build list of ai_subgroups to search: main niche + aliases from hdb_master_niches
    $sgEsc = mysqli_real_escape_string($conn, $filterSubgroup);
    $subgroups = [$filterSubgroup]; // start with main niche

    $nr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT aliases FROM hdb_master_niches WHERE niche_desc='$sgEsc' LIMIT 1"));
    if ($nr && !empty($nr['aliases'])) {
        $aliasList = array_map('trim', explode(',', $nr['aliases']));
        foreach ($aliasList as $alias) {
            if ($alias !== '') $subgroups[] = $alias;
        }
    }

    // Build IN() clause
    $inClause = implode(',', array_map(fn($s) => "'" . mysqli_real_escape_string($conn, $s) . "'", $subgroups));

    $emb = getEmbedding($nlTags, $apiKey);
    if (!$emb) { echo json_encode(['error' => 'OpenAI embedding failed']); exit; }

    $r = mysqli_query($conn, "SELECT id, image_name, media_type, ai_description, ai_tags, natural_language_tags, embedding
        FROM hdb_image_data
        WHERE ai_subgroup IN ($inClause)
        AND embedding IS NOT NULL AND embedding != ''");

    if (!$r) { echo json_encode(['error' => 'DB error: ' . mysqli_error($conn)]); exit; }

    $scored = []; $total = 0;
    while ($row = mysqli_fetch_assoc($r)) {
        $total++;
        $vec = json_decode($row['embedding'], true);
        if (!is_array($vec) || count($vec) < 10) continue;
        $row['score'] = round(cosineSimilarity($emb, $vec), 4);
        unset($row['embedding']);
        $scored[] = $row;
    }
    usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);

    echo json_encode([
        'results'        => array_slice($scored, 0, $topN),
        'total'          => $total,
        'count'          => min(count($scored), $topN),
        'niche'          => $filterSubgroup,
        'ai_group'       => $aiGroup,
        'ai_subgroup'    => $filterSubgroup,
        'nl_tags'        => $nlTags,
        'subgroups_used' => $subgroups,
    ]);
    exit;
}

// ── Load videos for page (PHP) ────────────────────────────────
$videos = [];
$vr = mysqli_query($conn, "SELECT id, title FROM hdb_podcasts ORDER BY id DESC LIMIT 200");
if ($vr) while ($row = mysqli_fetch_assoc($vr)) $videos[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NL Tag Analyzer</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 24px 20px; }
h2 { color: #1e1b4b; margin-bottom: 4px; }
.subtitle { color: #666; font-size: 13px; margin-bottom: 20px; }

.card { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 18px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.card h3 { font-size: 14px; color: #444; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #ede9fe; }

.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

label { font-size: 12px; font-weight: 600; color: #555; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.4px; }
select { width: 100%; padding: 8px 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #333; }
select:disabled { background: #f9fafb; color: #aaa; }
textarea { width: 100%; padding: 9px 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical; min-height: 70px; line-height: 1.5; }
input[type=number] { padding: 8px 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 6px; width: 80px; }

/* Prompt box */
.prompt-box { background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 10px 13px; font-size: 13px; color: #78350f; line-height: 1.6; min-height: 70px; }
.prompt-label { font-size: 10px; text-transform: uppercase; color: #92400e; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 700; }

/* ai_group / ai_subgroup read-only display boxes */
.ai-field-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 6px; padding: 9px 13px; font-size: 13px; font-weight: 600; color: #14532d; min-height: 38px; line-height: 1.5; }
.ai-subgroup-box { background: #eff6ff; border-color: #bfdbfe; border-left-color: #3b82f6; color: #1e3a5f; }

/* Niche badge */
.niche-badge { display: inline-block; background: #ede9fe; color: #5b21b6; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-top: 6px; }
.niche-info { font-size: 12px; color: #666; margin-top: 6px; }
.niche-info strong { color: #16a34a; }

/* Button */
.btn { background: #5c35d4; color: #fff; border: none; padding: 11px 28px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
.btn:hover { background: #4a28b8; }
.btn:disabled { background: #9ca3af; cursor: not-allowed; }
.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #ddd; border-top-color: #5c35d4; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Stats */
.stats-bar { display: flex; gap: 16px; flex-wrap: wrap; background: #1e1e2e; border-radius: 8px; padding: 14px 18px; margin-bottom: 14px; }
.stat { text-align: center; }
.stat-label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-value { font-size: 20px; font-weight: 700; color: #a78bfa; }
.stat-value.green { color: #4ade80; }
.stat-value.orange { color: #fb923c; }
.stat-value.white { color: #f9fafb; font-size: 14px; }

/* Table */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: #5c35d4; color: #fff; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
tr:hover td { background: #fafafa; }

/* Score */
.score-wrap { display: flex; align-items: center; gap: 6px; }
.score-track { flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; min-width: 60px; }
.score-fill { height: 100%; border-radius: 3px; }
.fill-high { background: #16a34a; }
.fill-mid  { background: #d97706; }
.fill-low  { background: #dc2626; }
.score-num { font-size: 12px; font-weight: 700; min-width: 36px; }
.num-high { color: #166534; }
.num-mid  { color: #92400e; }
.num-low  { color: #991b1b; }

/* Preview */
.preview { width: 80px; height: 142px; background: #f0f0f0; border-radius: 6px; overflow: hidden; border: 1px solid #e0e0e0; display: flex; align-items: center; justify-content: center; }
.preview img, .preview video { width: 100%; height: 100%; object-fit: cover; cursor: pointer; }
.no-media { color: #bbb; font-size: 10px; text-align: center; padding: 6px; }

/* Tags */
.tag-pills { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 6px; }
.tag-pill { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; font-size: 10px; padding: 1px 7px; border-radius: 8px; line-height: 1.8; }

/* Media badge */
.mbadge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
.mbadge-img { background: #dbeafe; color: #1d4ed8; }
.mbadge-vid { background: #ede9fe; color: #6d28d9; }

/* Error / empty */
.err { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #b91c1c; margin-top: 10px; }
.empty { text-align: center; padding: 30px; color: #999; font-size: 13px; }
.loading-msg { color: #5c35d4; font-size: 13px; margin-left: 12px; }

/* Lightbox */
.lb { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.9); z-index: 999; align-items: center; justify-content: center; cursor: pointer; }
.lb.on { display: flex; }
.lb-inner { position: relative; max-width: 92vw; max-height: 92vh; }
.lb-close { position: absolute; top: -38px; right: 0; color: #fff; font-size: 28px; cursor: pointer; line-height: 1; }
.lb-media { max-width: 92vw; max-height: 92vh; object-fit: contain; border-radius: 6px; }
</style>
</head>
<body>

<h2>🎬 NL Tag Analyzer</h2>
<p class="subtitle">Pick industry &amp; niche → ai_group/ai_subgroup auto-set. Enter NL tags, then run embedding search against that niche's assets.</p>

<!-- ── ROW 1: Video + Scene ──────────────────────────────────── -->
<div class="card">
    <h3>📽️ Step 1 — Select Video &amp; Scene</h3>
    <div class="grid2">
        <div>
            <label>Video</label>
            <select id="videoSel" onchange="loadScenes()">
                <option value="">— select a video —</option>
                <?php foreach ($videos as $v): ?>
                <option value="<?= $v['id'] ?>">[<?= $v['id'] ?>] <?= htmlspecialchars(substr($v['title'] ?? 'Untitled', 0, 80)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Scene</label>
            <select id="sceneSel" onchange="onSceneSelect()" disabled>
                <option value="">— select video first —</option>
            </select>
        </div>
    </div>

    <!-- Debug output for scenes -->
    <div id="sceneDebug" style="display:none; margin-top:10px; background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:10px 13px; font-size:12px; color:#7f1d1d; word-break:break-all; white-space:pre-wrap;"></div>

    <!-- Prompt display (shown when scene selected) -->
    <div id="sceneDetail" style="margin-top:14px; display:none;">
        <div class="prompt-label">🎨 Image Prompt</div>
        <div class="prompt-box" id="promptBox">—</div>
    </div>
</div>

<!-- ── ROW 2: Industry + Niche ───────────────────────────────── -->
<div class="card">
    <h3>🏭 Step 2 — Select Industry &amp; Niche</h3>
    <div class="grid2">
        <div>
            <label>Industry</label>
            <select id="industrySel" onchange="loadNiches()">
                <option value="" data-name="">— loading… —</option>
            </select>
        </div>
        <div>
            <label>Niche <span style="font-weight:400; text-transform:none; font-size:11px;">(auto-matches ai_subgroup)</span></label>
            <select id="nicheSel" onchange="onNicheSelect()" disabled>
                <option value="" data-name="">— select industry first —</option>
            </select>
            <div id="nicheInfo" style="display:none;">
                <span class="niche-badge" id="nicheBadge"></span>
                <div class="niche-info">Matching assets: <strong id="nicheCount">0</strong> &nbsp;|&nbsp; With embeddings: <strong id="embCount">0</strong></div>
            </div>
        </div>
    </div>

    <!-- Auto-populated ai_group / ai_subgroup -->
    <div class="grid2" style="margin-top:14px;">
        <div>
            <label>ai_group <span style="font-weight:400;text-transform:none;font-size:11px;">(auto from Industry)</span></label>
            <div class="ai-field-box" id="aiGroupDisplay">—</div>
            <input type="hidden" id="aiGroupVal" value="">
        </div>
        <div>
            <label>ai_subgroup <span style="font-weight:400;text-transform:none;font-size:11px;">(auto from Niche — used to filter assets)</span></label>
            <div class="ai-field-box ai-subgroup-box" id="aiSubgroupDisplay">—</div>
            <input type="hidden" id="aiSubgroupVal" value="">
            <input type="hidden" id="aliasesVal" value="">
            <div id="aliasesDisplay" style="display:none;margin-top:5px;font-size:11px;color:#6b7280;font-style:italic;"></div>
        </div>
    </div>
</div>

<!-- ── ROW 3: NL Tags Input ──────────────────────────────────── -->
<div class="card">
    <h3>🏷️ Step 3 — NL Tags for Embedding Search</h3>
    <p style="font-size:12px;color:#777;margin-bottom:10px;">Auto-filled when a scene is selected above, or type/paste tags manually.</p>
    <textarea id="nlTagsInput" rows="4" placeholder="e.g. professional woman working at desk, modern office, natural light, business casual…" style="width:100%;"></textarea>
</div>

<!-- ── ROW 4: Run Search ─────────────────────────────────────── -->
<div class="card">
    <h3>🔍 Step 4 — Run Embedding Search</h3>
    <div id="searchDebug" style="display:none; background:#1e1e2e; color:#a5f3fc; font-size:12px; border-radius:6px; padding:12px 14px; margin-bottom:12px; line-height:1.8; font-family:monospace;"></div>
    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <button class="btn" id="searchBtn" onclick="runSearch()">▶ Run Embedding Search</button>
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="margin:0; white-space:nowrap;">Top results</label>
            <input type="number" id="topN" value="20" min="1" max="100">
        </div>
        <span id="loadMsg" class="loading-msg" style="display:none;"><span class="spinner"></span>Getting embedding &amp; scoring…</span>
    </div>
    <div id="searchErr" class="err" style="display:none;"></div>
</div>

<!-- ── RESULTS ───────────────────────────────────────────────── -->
<div id="resultsArea"></div>

<!-- ── LIGHTBOX ─────────────────────────────────────────────── -->
<div class="lb" id="lb" onclick="closeLb()">
    <div class="lb-inner" onclick="event.stopPropagation()">
        <div class="lb-close" onclick="closeLb()">×</div>
        <div id="lbMedia"></div>
    </div>
</div>

<script>
var SELF = location.href.split('?')[0];
var sceneMap = {};

// Attach video change listener on DOM ready (belt + suspenders)
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('videoSel').addEventListener('change', loadScenes);
});

// ── Load scenes + auto-select industry/niche from podcast ─────
function loadScenes() {
    var vid = document.getElementById('videoSel').value;
    var sel = document.getElementById('sceneSel');
    sceneMap = {};
    document.getElementById('sceneDetail').style.display = 'none';
    document.getElementById('sceneDebug').style.display = 'none';

    // Reset industry/niche
    resetIndustryNiche();

    if (!vid) {
        sel.innerHTML = '<option value="">— select video first —</option>';
        sel.disabled = true;
        return;
    }

    sel.innerHTML = '<option value="">— loading… —</option>';
    sel.disabled = true;

    // Run both fetches in parallel
    Promise.all([
        fetch(SELF + '?ajax=scenes&video_id=' + vid).then(function(r){ return r.json(); }),
        fetch(SELF + '?ajax=podcast_groups&video_id=' + vid).then(function(r){ return r.json(); })
    ]).then(function(results) {
        var scenes = results[0];
        var groups = results[1];

        // Load scenes
        if (!Array.isArray(scenes) || !scenes.length) {
            sel.innerHTML = '<option value="">— no scenes found —</option>';
        } else {
            sel.innerHTML = '<option value="">— select a scene —</option>';
            scenes.forEach(function(s) {
                var key = 'scene_' + s.id;
                sceneMap[key] = s;
                var opt = document.createElement('option');
                opt.value = key;
                opt.textContent = 'Scene ' + s.scene_order + (s.natural_language_tags ? ' — ' + s.natural_language_tags.substring(0, 50) + '…' : '');
                sel.appendChild(opt);
            });
        }
        sel.disabled = false;

        // Auto-select industry + niche from podcast ai_group / ai_subgroup
        if (groups.ai_group || groups.ai_subgroup) {
            autoSelectIndustryNiche(groups.ai_group, groups.ai_subgroup, groups.aliases || '');
        }
    }).catch(function() {
        sel.innerHTML = '<option value="">— failed to load —</option>';
        sel.disabled = false;
    });
}

// ── Reset industry/niche dropdowns ────────────────────────────
function resetIndustryNiche() {
    var indSel   = document.getElementById('industrySel');
    var nicheSel = document.getElementById('nicheSel');
    indSel.value = '';
    nicheSel.innerHTML = '<option value="">— select industry first —</option>';
    nicheSel.disabled  = true;
    nicheMap = {};
    document.getElementById('nicheInfo').style.display  = 'none';
    document.getElementById('aiGroupVal').value          = '';
    document.getElementById('aiGroupDisplay').textContent = '—';
    document.getElementById('aiSubgroupVal').value           = '';
    document.getElementById('aiSubgroupDisplay').textContent = '—';
}

// ── Auto-select industry + niche by name ──────────────────────
function autoSelectIndustryNiche(aiGroup, aiSubgroup, aliases) {
    aliases = aliases || '';
    // Set ai_group display immediately
    document.getElementById('aiGroupVal').value           = aiGroup;
    document.getElementById('aiGroupDisplay').textContent = aiGroup || '—';
    document.getElementById('aiSubgroupVal').value           = aiSubgroup;
    document.getElementById('aiSubgroupDisplay').textContent = aiSubgroup || '—';

    // Find matching industry option by name
    var indSel = document.getElementById('industrySel');
    var indId  = null;
    for (var i = 0; i < indSel.options.length; i++) {
        if (industryMap[indSel.options[i].value] === aiGroup) {
            indId = indSel.options[i].value;
            indSel.value = indId;
            break;
        }
    }

    if (!indId) return; // industry not found in dropdown

    // Load niches for this industry, then select matching niche
    fetch(SELF + '?ajax=niches&industry_id=' + indId)
        .then(function(r) { return r.json(); })
        .then(function(niches) {
            var nicheSel = document.getElementById('nicheSel');
            nicheSel.innerHTML = '<option value="">— select a niche —</option>';
            nicheMap = {};
            niches.forEach(function(n) {
                nicheMap[n.id] = { name: n.name, aliases: n.aliases || '' };
                var opt = document.createElement('option');
                opt.value       = n.id;
                opt.textContent = n.name;
                nicheSel.appendChild(opt);
            });
            nicheSel.disabled = false;

            // Now select the matching niche
            for (var id in nicheMap) {
                if (nicheMap[id].name === aiSubgroup) {
                    nicheSel.value = id;
                    onNicheSelect();
                    break;
                }
            }
        });
}

// ── Scene selected → populate prompt + nl tags ───────────────
function onSceneSelect() {
    var key    = document.getElementById('sceneSel').value;
    var detail = document.getElementById('sceneDetail');
    if (!key || !sceneMap[key]) { detail.style.display = 'none'; return; }
    var s = sceneMap[key];
    document.getElementById('promptBox').textContent = s.prompt || '— no prompt —';
    document.getElementById('nlTagsInput').value     = s.natural_language_tags || '';
    detail.style.display = 'block';
}

// ── Industry / Niche name maps (id → name) ───────────────────
var industryMap = {};   // id → name
var nicheMap    = {};   // id → name

// ── Load industries ───────────────────────────────────────────
fetch(SELF + '?ajax=industries')
    .then(r => r.json())
    .then(function(data) {
        var sel = document.getElementById('industrySel');
        sel.innerHTML = '<option value="">— select an industry —</option>';
        data.forEach(function(ind) {
            industryMap[ind.id] = ind.name;
            var opt = document.createElement('option');
            opt.value = ind.id;
            opt.textContent = ind.name;
            sel.appendChild(opt);
        });
    })
    .catch(function() {
        document.getElementById('industrySel').innerHTML = '<option value="">— error loading —</option>';
    });

// ── Industry changed → set ai_group + load niches ────────────
function loadNiches() {
    var indSel  = document.getElementById('industrySel');
    var indId   = indSel.value;
    var indName = industryMap[indId] || '';

    // Always update ai_group display immediately
    document.getElementById('aiGroupVal').value           = indName;
    document.getElementById('aiGroupDisplay').textContent = indName || '—';

    // Reset niche side
    nicheMap = {};
    var nicheSel = document.getElementById('nicheSel');
    nicheSel.innerHTML = '<option value="">— loading… —</option>';
    nicheSel.disabled  = true;
    document.getElementById('nicheInfo').style.display = 'none';
    document.getElementById('aiSubgroupVal').value           = '';
    document.getElementById('aiSubgroupDisplay').textContent = '—';

    if (!indId) {
        nicheSel.innerHTML = '<option value="">— select industry first —</option>';
        return;
    }

    fetch(SELF + '?ajax=niches&industry_id=' + indId)
        .then(r => r.json())
        .then(function(niches) {
            nicheSel.innerHTML = '<option value="">— select a niche —</option>';
            niches.forEach(function(n) {
                nicheMap[n.id] = { name: n.name, aliases: n.aliases || '' };
                var opt = document.createElement('option');
                opt.value       = n.id;
                opt.textContent = n.name;
                nicheSel.appendChild(opt);
            });
            nicheSel.disabled = false;
        })
        .catch(function() {
            nicheSel.innerHTML = '<option value="">— error loading niches —</option>';
            nicheSel.disabled  = false;
        });
}

// ── Niche changed → set ai_subgroup + show asset count ───────
function onNicheSelect() {
    var nicheSel  = document.getElementById('nicheSel');
    var nicheId   = nicheSel.value;
    var nicheData = nicheMap[nicheId] || {};
    var nicheName = nicheData.name || '';
    var aliases   = nicheData.aliases || '';
    var info      = document.getElementById('nicheInfo');

    document.getElementById('aiSubgroupVal').value           = nicheName;
    document.getElementById('aiSubgroupDisplay').textContent = nicheName || '—';
    document.getElementById('aliasesVal').value              = aliases;

    if (!nicheName) { info.style.display = 'none'; return; }

    document.getElementById('nicheBadge').textContent = nicheName;
    // Show aliases if any
    var aliasDiv = document.getElementById('aliasesDisplay');
    if (aliases) {
        aliasDiv.textContent = '+ aliases: ' + aliases;
        aliasDiv.style.display = 'block';
    } else {
        aliasDiv.style.display = 'none';
    }

    fetch(SELF + '?ajax=niche_count&niche_name=' + encodeURIComponent(nicheName))
        .then(r => r.json())
        .then(function(d) {
            document.getElementById('nicheCount').textContent = d.total || 0;
            document.getElementById('embCount').textContent   = d.with_embedding || 0;
            info.style.display = 'block';
        });
}

// ── Run embedding search — loops per | tag ───────────────────
function runSearch() {
    var raw        = document.getElementById('nlTagsInput').value.trim();
    var aiGroup    = document.getElementById('aiGroupVal').value.trim();
    var aiSubgroup = document.getElementById('aiSubgroupVal').value.trim();
    var topN       = parseInt(document.getElementById('topN').value) || 5;

    if (!raw)        { alert('Please enter NL tags in Step 3.'); return; }
    if (!aiSubgroup) { alert('Please select an Industry and Niche in Step 2.'); return; }

    // Split by | and clean up
    var tags = raw.split('|').map(function(t){ return t.trim(); }).filter(function(t){ return t.length > 0; });

    document.getElementById('searchBtn').disabled = true;
    document.getElementById('loadMsg').style.display = 'inline';
    document.getElementById('searchErr').style.display = 'none';
    document.getElementById('searchDebug').style.display = 'none';
    document.getElementById('resultsArea').innerHTML =
        '<div style="background:#1e1e2e;color:#a5f3fc;font-size:12px;border-radius:8px;padding:12px 16px;margin-bottom:12px;font-family:monospace;">' +
        '🔍 Running ' + tags.length + ' tag search' + (tags.length>1?'es':'') + ' against <strong>' + esc(aiSubgroup) + '</strong>…' +
        '</div>';

    // Run searches sequentially so results appear one by one
    var idx = 0;
    function runNext() {
        if (idx >= tags.length) {
            document.getElementById('searchBtn').disabled = false;
            document.getElementById('loadMsg').style.display = 'none';
            return;
        }
        var tag = tags[idx];
        idx++;

        // Add a pending row for this tag
        var rowId = 'tagrow_' + idx;
        var pendingHtml =
            '<div id="' + rowId + '" style="background:#fff;border-radius:8px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);">' +
            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">' +
            '<span style="background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;">TAG ' + idx + ' / ' + tags.length + '</span>' +
            '<span style="font-size:13px;font-weight:600;color:#1e1b4b;">' + esc(tag) + '</span>' +
            '<span style="font-size:12px;color:#888;margin-left:auto;"><span class="spinner"></span> embedding…</span>' +
            '</div></div>';
        document.getElementById('resultsArea').insertAdjacentHTML('beforeend', pendingHtml);

        var fd = new FormData();
        fd.append('ajax_search', '1');
        fd.append('nl_tags',     tag);
        fd.append('ai_group',    aiGroup);
        fd.append('ai_subgroup', aiSubgroup);
        fd.append('niche_name',  aiSubgroup);
        fd.append('top_n',       topN);
        // aliases not needed - PHP reads them from hdb_master_niches directly

        fetch(SELF, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                var el = document.getElementById(rowId);
                if (!el) { runNext(); return; }

                if (data.error) {
                    el.innerHTML = el.innerHTML.replace(/<span[^>]*spinner[^>]*>.*?<\/span>.*?<\/span>/, '') +
                        '<div style="color:#b91c1c;font-size:12px;">❌ ' + esc(data.error) + '</div></div>';
                    runNext(); return;
                }

                var results      = data.results || [];
                var total        = data.total || 0;
                var topScore     = results.length ? results[0].score : 0;
                var subgroups    = data.subgroups_used || [aiSubgroup];

                // Score colour
                var scoreColor = topScore >= 0.5 ? '#16a34a' : topScore >= 0.35 ? '#d97706' : '#dc2626';
                var scoreBg    = topScore >= 0.5 ? '#f0fdf4' : topScore >= 0.35 ? '#fffbeb' : '#fef2f2';

                var html =
                    '<div id="' + rowId + '" style="background:#fff;border-radius:8px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);">' +
                    '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">' +
                    '<span style="background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;">TAG ' + idx + ' / ' + tags.length + '</span>' +
                    '<span style="font-size:13px;font-weight:700;color:#1e1b4b;">' + esc(tag) + '</span>' +
                    '<span style="margin-left:auto;display:flex;gap:8px;align-items:center;">' +
                    '<span style="font-size:11px;color:#666;">rows: <strong>' + total + '</strong></span>' +
                    '<span style="background:' + scoreBg + ';color:' + scoreColor + ';font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">top: ' + topScore + '</span>' +
                    '</span></div>' +
                    '<div style="font-size:11px;color:#666;margin-bottom:8px;font-family:monospace;background:#f8fafc;padding:5px 10px;border-radius:4px;">' +
                    '🗂 ' + subgroups.map(function(s){ return esc(s); }).join(' &nbsp;+&nbsp; ') +
                    '</div>';

                if (!results.length) {
                    html += '<div style="color:#999;font-size:12px;padding:6px 0;">🚫 No results with embeddings found</div>';
                } else {
                    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                    results.forEach(function(row) {
                        var sc  = parseFloat(row.score);
                        var cls = sc >= 0.5 ? '#16a34a' : sc >= 0.35 ? '#d97706' : '#dc2626';
                        var mt  = (row.media_type||'').toLowerCase();
                        var base = mt === 'video' ? 'https://videovizard.com/podcast_videos/' : 'https://videovizard.com/podcast_images/';
                        var url = base + (row.image_name||'');

                        html += '<div style="width:90px;flex-shrink:0;">';
                        var safeUrl = esc(url);
                        if (mt === 'video') {
                            html += '<video src="' + safeUrl + '" muted preload="metadata" playsinline style="width:90px;height:160px;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid ' + cls + ';" onmouseover="try{this.play()}catch(e){}" onmouseout="this.pause();this.currentTime=0" onclick="openLb(this.src,&quot;video&quot;)" onerror="this.style.display=&quot;none&quot;"></video>';
                        } else {
                            html += '<img src="' + safeUrl + '" loading="lazy" style="width:90px;height:160px;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid ' + cls + ';" onclick="openLb(this.src,&quot;image&quot;)" onerror="this.style.display=&quot;none&quot;">';
                        }
                        html += '<div style="font-size:10px;font-weight:700;color:' + cls + ';text-align:center;margin-top:3px;">' + sc + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                }

                html += '</div>';
                el.outerHTML = html;
                runNext();
            })
            .catch(function(err) {
                var el = document.getElementById(rowId);
                if (el) el.innerHTML += '<div style="color:#b91c1c;font-size:12px;">❌ Fetch error: ' + err + '</div>';
                runNext();
            });
    }

    runNext();
}

// ── Render results ────────────────────────────────────────────
function renderResults(data) {
    var area = document.getElementById('resultsArea');
    if (!data.results || data.results.length === 0) {
        area.innerHTML = '<div class="card"><div class="empty">🚫 No results found for niche <strong>' + esc(data.niche) + '</strong><br><small>Check that ai_subgroup matches niche name exactly.</small></div></div>';
        return;
    }

    var topScore = data.results[0].score;
    var botScore = data.results[data.results.length-1].score;

    var html = '<div class="card">';
    var groupLabel = data.ai_group ? esc(data.ai_group) + ' › ' + esc(data.ai_subgroup) : esc(data.niche);
    html += '<h3>Results — <em>' + esc(data.nl_tags.substring(0,60)) + '</em> &nbsp;<span style="font-size:12px;font-weight:400;color:#888;">(' + groupLabel + ')</span></h3>';
    html += '<div class="stats-bar">';
    html += stat('Total in Niche', data.total, '');
    html += stat('Shown', data.count, 'green');
    html += stat('Top Score', topScore, 'green');
    html += stat('Bottom Score', botScore, 'orange');
    html += stat('Niche', data.niche, 'white');
    html += '</div>';

    html += '<table><thead><tr>';
    html += '<th>#</th><th>Score</th><th>Preview</th><th>Description &amp; Tags</th><th>Type</th><th>File</th>';
    html += '</tr></thead><tbody>';

    data.results.forEach(function(row, i) {
        var sc = parseFloat(row.score);
        var cls = sc >= 0.5 ? 'high' : sc >= 0.35 ? 'mid' : 'low';
        var pct = Math.round(sc * 100);
        var mt = (row.media_type || '').toLowerCase();
        var base = mt === 'video' ? 'https://videovizard.com/podcast_videos/' : 'https://videovizard.com/podcast_images/';
        var url = base + row.image_name;
        var su = esc(url), sn = esc(row.image_name || '');

        var prev = mt === 'video'
            ? '<div class="preview"><video src="' + su + '" muted preload="metadata" playsinline onmouseenter="this.play().catch(()=>{})" onmouseleave="this.pause();this.currentTime=0" onclick="openLb(\'' + su + '\',\'video\')" onerror="this.parentElement.innerHTML=\'<div class=no-media>🎬</div>\'"></video></div>'
            : '<div class="preview"><img src="' + su + '" loading="lazy" onclick="openLb(\'' + su + '\',\'image\')" onerror="this.parentElement.innerHTML=\'<div class=no-media>🖼️</div>\'"></div>';

        var tags = '';
        if (row.ai_tags) { try { var ta = JSON.parse(row.ai_tags); if (Array.isArray(ta)) tags = '<div class="tag-pills">' + ta.map(t => '<span class="tag-pill">' + esc(t) + '</span>').join('') + '</div>'; } catch(e){} }

        html += '<tr>';
        html += '<td style="color:#999;font-size:12px;">' + (i+1) + '</td>';
        html += '<td><div class="score-wrap"><div class="score-track"><div class="score-fill fill-' + cls + '" style="width:' + pct + '%"></div></div><span class="score-num num-' + cls + '">' + sc + '</span></div></td>';
        html += '<td>' + prev + '</td>';
        html += '<td><div style="font-size:12px;line-height:1.6;color:#333;">' + esc(row.ai_description||'—') + '</div>' + tags + '</td>';
        html += '<td><span class="mbadge ' + (mt==='video'?'mbadge-vid':'mbadge-img') + '">' + esc(row.media_type||'—') + '</span></td>';
        html += '<td style="font-size:11px;color:#999;word-break:break-all;">' + sn + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    area.innerHTML = html;
}

function stat(label, val, cls) {
    return '<div class="stat"><div class="stat-label">' + label + '</div><div class="stat-value ' + cls + '">' + val + '</div></div>';
}

// ── Lightbox ──────────────────────────────────────────────────
function openLb(url, type) {
    var mc = document.getElementById('lbMedia');
    mc.innerHTML = '';
    if (type === 'video') {
        var v = document.createElement('video');
        v.src = url; v.controls = true; v.autoplay = true; v.className = 'lb-media';
        mc.appendChild(v);
    } else {
        var img = document.createElement('img');
        img.src = url; img.className = 'lb-media';
        mc.appendChild(img);
    }
    document.getElementById('lb').classList.add('on');
}
function closeLb() {
    var v = document.querySelector('#lbMedia video');
    if (v) v.pause();
    document.getElementById('lbMedia').innerHTML = '';
    document.getElementById('lb').classList.remove('on');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(); });

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>

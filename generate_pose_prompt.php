<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}
ob_start();

if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

require_once 'config.php';
include 'dbconnect_hdb.php';
mysqli_report(MYSQLI_REPORT_OFF);

$falApiKey = $falApiKey ?? null;

function vv_log($msg) { error_log('[VPS-VIZ][pose_lab] ' . $msg); }
function vv_safe_fetch($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r || $r === false) { vv_log("vv_safe_fetch FAILED: " . mysqli_error($conn) . " | SQL: " . substr($sql,0,200)); return null; }
    return mysqli_fetch_assoc($r) ?: null;
}

// Same shrink helper as vizard_scriptgen_tryon.php — kept local so this stays
// a genuinely standalone script.
function vv_shrink_for_upload($path, $max_dim = 2048, $quality = 85, $size_threshold = 1500000) {
    $size = @filesize($path);
    $info = @getimagesize($path);
    if (!$info) return $path;
    [$w, $h] = $info;
    $needs_resize   = max($w, $h) > $max_dim;
    $needs_reencode = ($size !== false && $size > $size_threshold);
    if (!$needs_resize && !$needs_reencode) return $path;
    $src = @imagecreatefromstring(@file_get_contents($path));
    if (!$src) return $path;
    if ($needs_resize) {
        $scale = $max_dim / max($w, $h);
        $new_w = max(1, (int)round($w * $scale));
        $new_h = max(1, (int)round($h * $scale));
    } else { $new_w = $w; $new_h = $h; }
    $dst = imagecreatetruecolor($new_w, $new_h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($src, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
    $tmp_path = sys_get_temp_dir() . '/' . uniqid('vvshrink_') . '.jpg';
    imagejpeg($dst, $tmp_path, $quality);
    imagedestroy($src); imagedestroy($dst);
    return $tmp_path;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX — list models for the picker
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'lab_get_models') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $q = mysqli_query($conn, "SELECT model_id, model_name, ethnicity, gender, thumbnail FROM mdl_models ORDER BY model_name ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success'=>true,'models'=>$rows]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX — list all pose templates
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'lab_get_templates') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $q = mysqli_query($conn, "SELECT id, code, title, category, motion_type, image_prompt, video_prompt, sort_order
                               FROM mdl_model_pose_templates WHERE active=1 ORDER BY sort_order ASC, id ASC");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success'=>true,'templates'=>$rows]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX — save an edited prompt back to the template
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'lab_save_prompt') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $code = mysqli_real_escape_string($conn, trim($_POST['code'] ?? ''));
    $prompt = mysqli_real_escape_string($conn, trim($_POST['image_prompt'] ?? ''));
    if (!$code) { echo json_encode(['success'=>false,'error'=>'Missing code']); exit; }
    mysqli_query($conn, "UPDATE mdl_model_pose_templates SET image_prompt='$prompt' WHERE code='$code' LIMIT 1");
    echo json_encode(['success'=>true]); exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX — generate (or force-regenerate) one model+pose image
// Pure text-to-image now (no reference photo): ideogram/v3 -> rembg ->
// white composite. Identity consistency across poses for the same model
// comes from a detailed appearance_prompt per model plus a fixed seed
// derived from model_id. Cache file is identical
// (promo_models/model_id_{id}_pose_{code}.png) so this tool and the live
// app share the same cache.
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'lab_generate_pose') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    set_time_limit(120);

    $model_id  = (int)($_POST['model_id'] ?? 0);
    $pose_code = mysqli_real_escape_string($conn, trim($_POST['pose_code'] ?? ''));
    $force     = !empty($_POST['force']);
    if (!$model_id || !$pose_code) { echo json_encode(['success'=>false,'error'=>'Missing model_id or pose_code']); exit; }

    $pose_dir = __DIR__ . '/promo_models/';
    if (!is_dir($pose_dir)) @mkdir($pose_dir, 0777, true);
    $filename = "model_id_{$model_id}_pose_{$pose_code}.png";
    $filepath = $pose_dir . $filename;

    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $web_path   = '/' . ltrim(str_replace($doc_root, '', rtrim($pose_dir,'/')), '/') . '/';
    $public_url = $protocol.'://'.$host.$web_path.$filename;

    if ($force && file_exists($filepath)) @unlink($filepath);

    if (file_exists($filepath)) {
        echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>true]); exit;
    }

    if (!$falApiKey) { echo json_encode(['success'=>false,'error'=>'fal.ai API key not configured']); exit; }

    $model_row = vv_safe_fetch($conn, "SELECT model_name FROM mdl_models WHERE model_id=$model_id LIMIT 1");
    if (!$model_row) {
        echo json_encode(['success'=>false,'error'=>'Model not found']); exit;
    }

    $pose_row = vv_safe_fetch($conn, "SELECT image_prompt FROM mdl_model_pose_templates WHERE code='$pose_code' LIMIT 1");
    if (!$pose_row || empty($pose_row['image_prompt'])) {
        echo json_encode(['success'=>false,'error'=>"Pose '$pose_code' has no image_prompt"]); exit;
    }

    // No reference photo, no per-model description column — the pose
    // template's image_prompt is already self-contained. A seed derived
    // from model_id is the only per-model touch, just so different
    // model_ids don't all render as the exact same output.
    $prompt = trim($pose_row['image_prompt'])
            . ', wearing a simple plain white fitted t-shirt and plain black fitted pants, no dress, no gown, no embellishment, no jewelry, plain seamless white studio background, no scenery, no props';
    $seed = crc32('model_' . $model_id) % 1000000;

    $gch = curl_init('https://fal.run/fal-ai/ideogram/v3');
    curl_setopt_array($gch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt'         => $prompt,
            'aspect_ratio'   => '9:16',
            'style'          => 'REALISTIC',
            'expand_prompt'  => false,
            'negative_prompt'=> 'man, male, masculine features, facial hair, beard, mustache',
            'seed'           => $seed,
        ]),
    ]);
    $gres  = curl_exec($gch);
    $ghttp = curl_getinfo($gch, CURLINFO_HTTP_CODE);
    $gerr  = curl_error($gch);
    curl_close($gch);
    $gj = json_decode($gres, true);

    if ($ghttp !== 200 || empty($gj['images'][0]['url'])) {
        $detail = $gerr ? "curl error: $gerr" : ('HTTP ' . $ghttp . ' — ' . substr((string)$gres, 0, 200));
        vv_log("lab_generate_pose: generation failed model=$model_id pose=$pose_code | $detail");
        echo json_encode(['success'=>false,'error'=>"fal.ai call failed ($detail)"]); exit;
    }

    $img_data = @file_get_contents($gj['images'][0]['url']);
    if (!$img_data) { echo json_encode(['success'=>false,'error'=>'Generated image could not be downloaded']); exit; }

    // Force a guaranteed-plain background — same as the live app.
    $final_data = $img_data;
    $rembg_ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
    curl_setopt_array($rembg_ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key ' . $falApiKey],
        CURLOPT_POSTFIELDS     => json_encode(['image_url'=>'data:image/png;base64,'.base64_encode($img_data),'sync_mode'=>true]),
    ]);
    $rres  = curl_exec($rembg_ch);
    $rhttp = curl_getinfo($rembg_ch, CURLINFO_HTTP_CODE);
    curl_close($rembg_ch);
    $rj = json_decode($rres, true);

    if ($rhttp === 200 && !empty($rj['image']['url'])) {
        $cut_data = @file_get_contents($rj['image']['url']);
        $src = $cut_data ? @imagecreatefromstring($cut_data) : false;
        if ($src) {
            $iw = imagesx($src); $ih = imagesy($src);
            $dst = imagecreatetruecolor($iw, $ih);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagealphablending($src, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
            ob_start(); imagepng($dst); $final_data = ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
        }
    } else {
        vv_log("lab_generate_pose: rembg cleanup failed model=$model_id pose=$pose_code (HTTP $rhttp)");
    }

    file_put_contents($filepath, $final_data);
    vv_log("lab_generate_pose: generated model=$model_id pose=$pose_code -> $filename");
    echo json_encode(['success'=>true,'url'=>$public_url,'cached'=>false]); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pose Prompt Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root { --dark-blue:#0f2a44; --purple:#8b5cf6; --purple-lt:#ede9fe; --green:#10b981; --text:#1e293b; --muted:#64748b; --border:#e2e8f0; --bg:#f8fafc; --card:#fff; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); }
.topbar { background:linear-gradient(90deg,#0f2a44,#143b63); color:#fff; padding:14px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; position:sticky; top:0; z-index:10; }
.topbar h1 { font-size:16px; font-weight:700; }
.topbar select, .topbar button { font-family:inherit; font-size:13px; padding:7px 12px; border-radius:7px; border:1px solid rgba(255,255,255,.3); background:rgba(255,255,255,.1); color:#fff; }
.topbar button { background:var(--purple); border:none; font-weight:700; cursor:pointer; }
.topbar button:hover { background:#7c3aed; }
.wrap { max-width:1400px; margin:0 auto; padding:20px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.pcard { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
.pcard-head { padding:10px 12px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:baseline; }
.pcard-code { font-weight:800; color:var(--purple); font-size:13px; }
.pcard-cat { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
.pcard-title { font-size:12px; color:var(--text); padding:6px 12px 0; font-weight:600; }
.pcard-img { aspect-ratio:3/4; background:#f1f5f9; display:flex; align-items:center; justify-content:center; }
.pcard-img img { width:100%; height:100%; object-fit:cover; display:block; }
.pcard-body { padding:10px 12px; display:flex; flex-direction:column; gap:6px; flex:1; }
.pcard-body textarea { width:100%; font-size:11px; font-family:inherit; border:1px solid var(--border); border-radius:6px; padding:6px; resize:vertical; min-height:60px; color:var(--text); }
.pcard-actions { display:flex; gap:6px; }
.pcard-actions button { flex:1; font-size:11px; font-weight:700; padding:6px 8px; border-radius:6px; border:1px solid var(--border); background:#fff; cursor:pointer; }
.pcard-actions button.primary { background:var(--purple); color:#fff; border-color:var(--purple); }
.pcard-actions button:hover { border-color:var(--purple); }
.pcard-status { font-size:10px; color:var(--muted); text-align:center; }
.loading-dot { width:6px; height:6px; border-radius:50%; background:var(--purple); display:inline-block; animation:blink 1.2s ease-in-out infinite; margin:0 1px; }
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }
</style>
</head>
<body>

<div class="topbar">
  <h1>🧪 Pose Prompt Lab</h1>
  <select id="modelSelect" onchange="onModelChange()"></select>
  <button onclick="generateAll(false)">Generate Missing</button>
  <button onclick="generateAll(true)">Force Regenerate All</button>
  <span id="topStatus" style="font-size:12px;opacity:.8;"></span>
</div>

<div class="wrap">
  <div id="templateGrid" class="grid"></div>
</div>

<script>
function esc(s) { return String(s).replace(/"/g,'&quot;'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function post(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch(location.href, { method:'POST', body:fd });
    return r.json();
}

let models = [];
let templates = [];
let currentModelId = null;

async function init() {
    const [md, td] = await Promise.all([
        post({ ajax_action:'lab_get_models' }),
        post({ ajax_action:'lab_get_templates' }),
    ]);
    models    = md.success ? md.models : [];
    templates = td.success ? td.templates : [];

    const sel = document.getElementById('modelSelect');
    sel.innerHTML = models.map(m => `<option value="${m.model_id}">${escHtml(m.model_name || ('Model #'+m.model_id))} (${escHtml(m.ethnicity||'')} ${escHtml(m.gender||'')})</option>`).join('');
    if (models.length) currentModelId = models[0].model_id;

    renderGrid();
}

function renderGrid() {
    const grid = document.getElementById('templateGrid');
    grid.innerHTML = templates.map(t => `
        <div class="pcard" data-code="${t.code}">
            <div class="pcard-head">
                <span class="pcard-code">${escHtml(t.code)}</span>
                <span class="pcard-cat">${escHtml(t.category)}</span>
            </div>
            <div class="pcard-title">${escHtml(t.title)}</div>
            <div class="pcard-img" id="img_${t.code}"><span style="font-size:11px;color:#aaa;">not generated</span></div>
            <div class="pcard-body">
                <textarea id="prompt_${t.code}">${escHtml(t.image_prompt || '')}</textarea>
                <div class="pcard-actions">
                    <button onclick="savePrompt('${t.code}')">💾 Save</button>
                    <button onclick="generateOne('${t.code}', false)">Generate</button>
                    <button class="primary" onclick="generateOne('${t.code}', true)">Regenerate</button>
                </div>
                <div class="pcard-status" id="status_${t.code}"></div>
            </div>
        </div>`).join('');

    if (currentModelId) loadExistingThumbs();
}

function onModelChange() {
    currentModelId = document.getElementById('modelSelect').value;
    templates.forEach(t => {
        document.getElementById(`img_${t.code}`).innerHTML = '<span style="font-size:11px;color:#aaa;">not generated</span>';
        document.getElementById(`status_${t.code}`).textContent = '';
    });
    loadExistingThumbs();
}

async function loadExistingThumbs() {
    // Cache hits are instant — just ask each one, cheap and shows what's already there.
    for (const t of templates) {
        const d = await post({ ajax_action:'lab_generate_pose', model_id: currentModelId, pose_code: t.code, force: 0 });
        if (d.success) {
            document.getElementById(`img_${t.code}`).innerHTML = `<img src="${esc(d.url)}">`;
            document.getElementById(`status_${t.code}`).textContent = d.cached ? 'cached' : 'generated';
        }
    }
}

async function savePrompt(code) {
    const val = document.getElementById(`prompt_${code}`).value;
    await post({ ajax_action:'lab_save_prompt', code, image_prompt: val });
    document.getElementById(`status_${code}`).textContent = 'prompt saved';
}

async function generateOne(code, force) {
    const imgBox = document.getElementById(`img_${code}`);
    const status = document.getElementById(`status_${code}`);
    imgBox.innerHTML = '<span class="loading-dot"></span><span class="loading-dot"></span><span class="loading-dot"></span>';
    status.textContent = 'working…';
    try {
        const d = await post({ ajax_action:'lab_generate_pose', model_id: currentModelId, pose_code: code, force: force ? 1 : 0 });
        if (!d.success) throw new Error(d.error || 'Failed');
        imgBox.innerHTML = `<img src="${esc(d.url)}">`;
        status.textContent = d.cached ? 'cached' : 'generated';
    } catch(e) {
        imgBox.innerHTML = '<span style="font-size:11px;color:#dc2626;padding:8px;text-align:center;">' + escHtml(e.message) + '</span>';
        status.textContent = 'failed';
    }
}

async function generateAll(force) {
    document.getElementById('topStatus').textContent = 'Running…';
    for (const t of templates) {
        await generateOne(t.code, force);
    }
    document.getElementById('topStatus').textContent = 'Done';
}

init();
</script>
</body>
</html>

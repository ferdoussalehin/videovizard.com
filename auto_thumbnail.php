<?php
// auto_thumbnail.php
ob_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/auto_thumb.log'); 
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);

require_once 'dbconnect_hdb.php';

$imgDir   = __DIR__ . '/podcast_images/';
$vidDir   = __DIR__ . '/podcast_videos/';
$thumbDir = __DIR__ . '/podcast_thumbnails/';
$imgExts  = ['jpg','jpeg','png','webp','gif'];
$vidExts  = ['mp4','webm','mov'];

// ── Ensure thumbnail column exists ────────────────────────────────────────────
$chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data LIKE 'thumbnail'");
if ($chk && mysqli_num_rows($chk) === 0) {
    mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN thumbnail VARCHAR(100) DEFAULT ''");
}

// ── Ensure thumbnail folder exists ────────────────────────────────────────────
if (!is_dir($thumbDir)) mkdir($thumbDir, 0777, true);

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Save thumbnail
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_thumb') {
    ob_clean();
    header('Content-Type: application/json');

    $id        = (int)($_POST['id']         ?? 0);
    $imgData   =       $_POST['image_data'] ?? '';
    $imageName = mysqli_real_escape_string($conn, $_POST['image_name'] ?? '');

    if (!$id || empty($imgData) || empty($imageName)) {
        echo json_encode(['success'=>false,'message'=>'Missing data']); exit;
    }

    if (strpos($imgData, ',') !== false) {
        $imgData = explode(',', $imgData, 2)[1];
    }
    $decoded = base64_decode($imgData);
    if (!$decoded) {
        echo json_encode(['success'=>false,'message'=>'Invalid base64']); exit;
    }

    $thumbName = pathinfo($imageName, PATHINFO_FILENAME) . '_thumb.jpg';
    $thumbPath = $thumbDir . $thumbName;
    $newW = 0; $newH = 0;

    $src = @imagecreatefromstring($decoded);
    if (!$src) {
        file_put_contents($thumbPath, $decoded);
    } else {
        $origW = imagesx($src);
        $origH = imagesy($src);
        $ratio = min(320 / $origW, 320 / $origH, 1);
        $newW  = (int)round($origW * $ratio);
        $newH  = (int)round($origH * $ratio);
        $dst   = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $trans);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagejpeg($dst, $thumbPath, 82);
        imagedestroy($src);
        imagedestroy($dst);
    }

    $safeThumb = mysqli_real_escape_string($conn, $thumbName);
    $ok = mysqli_query($conn, "UPDATE hdb_image_data SET thumbnail='$safeThumb', updated_at=NOW() WHERE id=$id");
    if ($ok) {
        error_log("[auto_thumb] Saved: $thumbName for id=$id ($imageName)\n", 3, __DIR__ . '/auto_thumb.log');
        echo json_encode(['success'=>true,'thumbnail'=>$thumbName,'size'=>$newW.'×'.$newH]);
    } else {
        echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Serve file — images as base64, videos as URL
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_image') {
    ob_clean();
    header('Content-Type: application/json');

    $name = basename($_GET['name'] ?? '');
    if (empty($name)) {
        echo json_encode(['success'=>false,'message'=>'No name']); exit;
    }

    $ext     = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, $vidExts);
    $isImage = in_array($ext, $imgExts);

    // Pick the right folder
    if ($isVideo) {
        $path = $vidDir . $name;
    } else {
        $path = $imgDir . $name;
    }

    // Check file exists
    if (!file_exists($path)) {
        error_log("[auto_thumb] Not found: $name — tried: $path\n", 3, __DIR__ . '/auto_thumb.log');
        
        // DELETE the row from database since file doesn't exist on disk
        $deleteQuery = "DELETE FROM hdb_image_data WHERE image_name = '" . mysqli_real_escape_string($conn, $name) . "'";
        $deleteResult = mysqli_query($conn, $deleteQuery);
        
        if ($deleteResult && mysqli_affected_rows($conn) > 0) {
            error_log("[auto_thumb] Deleted stale DB row for: $name\n", 3, __DIR__ . '/auto_thumb.log');
            echo json_encode(['success'=>false, 'message'=>'File not found: ' . $name . ' — Row deleted from database', 'deleted'=>true]);
        } else {
            echo json_encode(['success'=>false, 'message'=>'File not found: ' . $name . ' — No matching row found to delete', 'deleted'=>false]);
        }
        exit;
    }

    // Return video as direct URL (too large for base64)
    if ($isVideo) {
        echo json_encode([
            'success'  => true,
            'is_video' => true,
            'url'      => 'podcast_videos/' . rawurlencode($name),
        ]);
        exit;
    }

    // Return image as base64 data URL
    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
    $mime    = $mimeMap[$ext] ?? 'image/jpeg';
    $b64     = base64_encode(file_get_contents($path));
    echo json_encode([
        'success'  => true,
        'is_video' => false,
        'dataUrl'  => "data:$mime;base64,$b64",
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Clean DB rows where file no longer exists on disk
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'clean_missing') {
    ob_clean();
    header('Content-Type: application/json');

    $deleted = []; $kept = 0; $errors = []; $skipped = 0;

    $res = mysqli_query($conn, "SELECT id, image_name FROM hdb_image_data ORDER BY id ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $name = $row['image_name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $isImg = in_array($ext, $imgExts);
        $isVid = in_array($ext, $vidExts);

        // Not a known media file — skip
        if (!$isImg && !$isVid) { $skipped++; continue; }

        // Check correct folder
        $path = $isVid ? ($vidDir . $name) : ($imgDir . $name);

        // File exists — keep
        if (file_exists($path)) { $kept++; continue; }

        // File missing — delete from DB
        if (mysqli_query($conn, "DELETE FROM hdb_image_data WHERE id=" . (int)$row['id'])) {
            $deleted[] = $name;
            error_log("[auto_thumb] Cleaned: $name (id={$row['id']})\n", 3, __DIR__ . '/auto_thumb.log');
        } else {
            $errors[] = $name . ': ' . mysqli_error($conn);
        }
    }

    echo json_encode([
        'success'       => true,
        'deleted_count' => count($deleted),
        'kept_count'    => $kept,
        'skipped_count' => $skipped,
        'error_count'   => count($errors),
        'deleted'       => $deleted,
        'errors'        => $errors,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Get pending records (no thumbnail yet)
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_pending') {
    ob_clean();
    header('Content-Type: application/json');

    $redo  = isset($_GET['redo']) && $_GET['redo'] == '1';
    $where = $redo ? "1=1" : "(thumbnail IS NULL OR thumbnail = '')";

    $res  = mysqli_query($conn, "SELECT id, image_name, media_type FROM hdb_image_data WHERE $where ORDER BY id ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

    $tc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE $where"))['c'];
    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>(int)$tc]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Stats
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_stats') {
    ob_clean();
    header('Content-Type: application/json');

    $total   = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data"))['c'];
    $done    = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data WHERE thumbnail != '' AND thumbnail IS NOT NULL"))['c'];
    $pending = $total - $done;

    $imgCount = is_dir($imgDir) ? count(array_filter(scandir($imgDir), fn($f) => !in_array($f,['.','..']))) : 0;
    $vidCount = is_dir($vidDir) ? count(array_filter(scandir($vidDir), fn($f) => !in_array($f,['.','..']))) : 0;

    echo json_encode([
        'success'   => true,
        'total'     => $total,
        'done'      => $done,
        'pending'   => $pending,
        'img_disk'  => $imgCount,
        'vid_disk'  => $vidCount,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Diagnostic check
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'check_files') {
    ob_clean();
    header('Content-Type: application/json');
    $res    = mysqli_query($conn, "SELECT id, image_name FROM hdb_image_data WHERE thumbnail='' OR thumbnail IS NULL ORDER BY id ASC LIMIT 10");
    $dbRows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $ext  = strtolower(pathinfo($r['image_name'], PATHINFO_EXTENSION));
        $isV  = in_array($ext, $vidExts);
        $path = $isV ? ($vidDir . $r['image_name']) : ($imgDir . $r['image_name']);
        $dbRows[] = ['id'=>$r['id'],'name'=>$r['image_name'],'on_disk'=>file_exists($path),'folder'=>$isV?'podcast_videos':'podcast_images'];
    }
    echo json_encode([
        'img_dir'    => $imgDir,
        'vid_dir'    => $vidDir,
        'img_exists' => is_dir($imgDir),
        'vid_exists' => is_dir($vidDir),
        'db_rows'    => $dbRows,
    ], JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Auto Thumbnail Generator</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f4f8;--card:#fff;--bdr:#d1dde9;
  --acc:#2563eb;--grn:#16a34a;--red:#dc2626;--amber:#d97706;--purple:#7c3aed;
  --txt:#1e3a5f;--mut:#64869e;
  font-family:'Segoe UI',system-ui,sans-serif;
}
body{background:var(--bg);color:var(--txt);min-height:100vh;padding:24px;}
h1{font-size:20px;font-weight:700;color:var(--acc);margin-bottom:4px;}
.sub{font-size:13px;color:var(--mut);margin-bottom:20px;}

.stats-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.stat{background:var(--card);border:1px solid var(--bdr);border-radius:10px;padding:12px 18px;min-width:110px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.stat strong{display:block;font-size:26px;font-weight:700;line-height:1;}
.stat span{font-size:11px;color:var(--mut);margin-top:3px;display:block;}
.stat.s-total   strong{color:var(--acc);}
.stat.s-done    strong{color:var(--grn);}
.stat.s-pending strong{color:var(--amber);}
.stat.s-img     strong{color:#0891b2;}
.stat.s-vid     strong{color:var(--purple);}

.clean-bar{background:#fff8f0;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.clean-bar p{font-size:13px;color:#92400e;flex:1;margin:0;}
.clean-result{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;font-size:13px;color:#166534;margin-bottom:18px;display:none;}

.controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px;}
.btn{padding:9px 20px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit;}
.btn:hover{opacity:.88;transform:translateY(-1px);}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none;}
.btn-start{background:var(--grn);color:#fff;}
.btn-pause{background:var(--amber);color:#fff;}
.btn-skip {background:#fff;color:var(--txt);border:1px solid var(--bdr);}
.btn-clean{background:var(--red);color:#fff;}
.speed-sel{padding:8px 10px;border:1px solid var(--bdr);border-radius:8px;background:#fff;font-family:inherit;font-size:13px;color:var(--txt);cursor:pointer;}

.progress-wrap{background:var(--card);border:1px solid var(--bdr);border-radius:10px;padding:14px 18px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.progress-label{display:flex;justify-content:space-between;font-size:13px;font-weight:600;color:var(--mut);margin-bottom:7px;}
.progress-bar-bg{background:#e2ecf7;border-radius:20px;height:12px;overflow:hidden;}
.progress-bar{height:100%;width:0%;background:linear-gradient(90deg,var(--acc),var(--grn));border-radius:20px;transition:width .4s;}
.current-file{margin-top:8px;font-size:12px;color:var(--mut);min-height:16px;}

.main-grid{display:grid;grid-template-columns:1fr 340px;gap:14px;}
@media(max-width:800px){.main-grid{grid-template-columns:1fr;}}

.viewer-card{background:var(--card);border:1px solid var(--bdr);border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.viewer-header{padding:11px 15px;background:var(--bg);border-bottom:1px solid var(--bdr);font-size:13px;font-weight:700;color:var(--txt);display:flex;align-items:center;justify-content:space-between;}
.viewer-body{display:flex;min-height:400px;}
.source-pane{flex:1;background:#111;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
.source-pane img,.source-pane video{max-width:100%;max-height:400px;object-fit:contain;display:block;}
.source-label{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.6);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;}
.thumb-pane{width:155px;flex-shrink:0;background:#1a1a2e;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px;gap:8px;border-left:1px solid var(--bdr);}
.thumb-pane img{max-width:135px;max-height:200px;object-fit:contain;border-radius:6px;border:2px solid var(--grn);}
.thumb-label{font-size:10px;color:rgba(255,255,255,.5);text-align:center;}
.thumb-dims{font-size:10px;color:rgba(255,255,255,.4);font-family:monospace;}
.no-thumb-msg{font-size:11px;color:rgba(255,255,255,.3);text-align:center;font-style:italic;}
canvas#workCanvas{display:none;}

.log-card{background:var(--card);border:1px solid var(--bdr);border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;}
.log-header{padding:10px 14px;background:var(--bg);border-bottom:1px solid var(--bdr);font-size:13px;font-weight:700;color:var(--txt);display:flex;justify-content:space-between;align-items:center;}
.log-header button{background:none;border:none;color:var(--mut);font-size:11px;cursor:pointer;font-family:inherit;padding:2px 6px;}
.log-header button:hover{color:var(--red);}
#logArea{flex:1;min-height:380px;max-height:520px;overflow-y:auto;padding:10px 12px;font-size:11px;line-height:1.85;font-family:'Courier New',monospace;color:var(--txt);}
.log-ok   {color:var(--grn);}
.log-err  {color:var(--red);}
.log-skip {color:var(--amber);}
.log-info {color:var(--mut);}
.log-clean{color:var(--purple);}

.spinner{width:13px;height:13px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;display:inline-block;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<h1>🖼 Auto Thumbnail Generator</h1>
<p class="sub">Processes images from <code>podcast_images/</code> and videos from <code>podcast_videos/</code> — saves 320px thumbnails to <code>podcast_thumbnails/</code>.</p>

<!-- Stats -->
<div class="stats-bar">
  <div class="stat s-total"  ><strong id="statTotal">—</strong><span>Total in DB</span></div>
  <div class="stat s-done"   ><strong id="statDone">—</strong><span>Thumbnailed</span></div>
  <div class="stat s-pending"><strong id="statPending">—</strong><span>Pending</span></div>
  <div class="stat s-img"    ><strong id="statImgDisk">—</strong><span>Images on Disk</span></div>
  <div class="stat s-vid"    ><strong id="statVidDisk">—</strong><span>Videos on Disk</span></div>
</div>

<!-- Clean -->
<div class="clean-bar">
  <p>⚠️ Run <strong>Clean</strong> first to remove DB records for files that no longer exist on disk. This prevents "Not on disk" errors.</p>
  <button class="btn btn-clean" id="btnClean" onclick="cleanMissing()">🧹 Clean Missing Files from DB</button>
</div>
<div class="clean-result" id="cleanResult"></div>

<!-- Controls -->
<div class="controls">
  <button class="btn btn-start" id="btnStart" onclick="startProcess()">▶ Start</button>
  <button class="btn btn-pause" id="btnPause" onclick="togglePause()" disabled>⏸ Pause</button>
  <button class="btn btn-skip"  id="btnSkip"  onclick="skipCurrent()" disabled>⏭ Skip</button>
  <select class="speed-sel" id="speedSel">
    <option value="200">Fast (0.2s)</option>
    <option value="500" selected>Normal (0.5s)</option>
    <option value="1000">Slow (1s)</option>
    <option value="2000">Very Slow (2s)</option>
  </select>
  <label style="font-size:13px;color:var(--mut);display:flex;align-items:center;gap:6px;cursor:pointer;">
    <input type="checkbox" id="chkRedo" style="accent-color:var(--purple);cursor:pointer;">
    Re-do existing thumbnails
  </label>
</div>

<!-- Progress -->
<div class="progress-wrap">
  <div class="progress-label">
    <span id="progressLabel">Idle — click Start to begin</span>
    <span id="progressPct">0%</span>
  </div>
  <div class="progress-bar-bg"><div class="progress-bar" id="progressBar"></div></div>
  <div class="current-file" id="currentFile"></div>
</div>

<!-- Main -->
<div class="main-grid">
  <div class="viewer-card">
    <div class="viewer-header">
      <span id="viewerTitle">Waiting…</span>
      <span id="viewerStatus" style="font-size:12px;color:var(--mut);font-weight:400;"></span>
    </div>
    <div class="viewer-body">
      <div class="source-pane">
        <div class="source-label" id="sourceLabel">Original</div>
        <img   id="sourceImg"   src="" alt="" style="display:none;">
        <video id="sourceVideo" muted  style="display:none;max-width:100%;max-height:400px;object-fit:contain;"></video>
        <div   id="sourcePlaceholder" style="color:rgba(255,255,255,.3);font-size:13px;">No file loaded</div>
      </div>
      <div class="thumb-pane">
        <div class="thumb-label">Thumbnail (320px)</div>
        <img id="thumbPreviewImg" src="" style="display:none;">
        <div class="no-thumb-msg" id="thumbPlaceholder">Preview here</div>
        <div class="thumb-dims"   id="thumbDims"></div>
      </div>
    </div>
    <canvas id="workCanvas"></canvas>
  </div>

  <div class="log-card">
    <div class="log-header">
      <span>📋 Log</span>
      <button onclick="clearLog()">Clear</button>
    </div>
    <div id="logArea"></div>
  </div>
</div>

<script>
var queue      = [];
var queueIndex = 0;
var totalCount = 0;
var doneCount  = 0;
var running    = false;
var paused     = false;
var skipFlag   = false;

// ── Stats ─────────────────────────────────────────────────────────────────────
function loadStats() {
  fetch('?action=get_stats').then(r=>r.json()).then(d=>{
    if (!d.success) return;
    document.getElementById('statTotal').textContent   = d.total;
    document.getElementById('statDone').textContent    = d.done;
    document.getElementById('statPending').textContent = d.pending;
    document.getElementById('statImgDisk').textContent = d.img_disk;
    document.getElementById('statVidDisk').textContent = d.vid_disk;
  }).catch(()=>{});
}
loadStats();

// ── Clean missing ─────────────────────────────────────────────────────────────
async function cleanMissing() {
  var btn = document.getElementById('btnClean');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Scanning…';
  log('clean', '🧹 Scanning for missing files…');
  try {
    var d = await fetch('?action=clean_missing').then(r=>r.json());
    btn.disabled  = false;
    btn.innerHTML = '🧹 Clean Missing Files from DB';
    if (d.success) {
      var msg = '✅ Removed ' + d.deleted_count + ' stale records. '
              + d.kept_count + ' files still on disk. '
              + d.skipped_count + ' non-media rows skipped.';
      if (d.error_count) msg += ' ⚠️ ' + d.error_count + ' errors.';
      var el = document.getElementById('cleanResult');
      el.textContent  = msg;
      el.style.display = 'block';
      log('clean', msg);
      d.deleted.slice(0,20).forEach(function(n){ log('err','  🗑 '+n); });
      if (d.deleted.length > 20) log('info','  …and '+(d.deleted.length-20)+' more');
      loadStats();
    }
  } catch(e) {
    btn.disabled  = false;
    btn.innerHTML = '🧹 Clean Missing Files from DB';
    log('err', '❌ Clean error: ' + e.message);
  }
}

// ── Start ─────────────────────────────────────────────────────────────────────
async function startProcess() {
  if (running) return;
  running    = true;
  paused     = false;
  queueIndex = 0;
  doneCount  = 0;
  document.getElementById('btnStart').disabled = true;
  document.getElementById('btnPause').disabled = false;
  document.getElementById('btnSkip').disabled  = false;
  document.getElementById('progressBar').style.width = '0%';
  document.getElementById('progressPct').textContent = '0%';
  log('info', '▶ Starting…');

  var redo = document.getElementById('chkRedo').checked;
  try {
    var resp = await fetch('?action=get_pending' + (redo?'&redo=1':'')).then(r=>r.json());
    if (!resp.success || !resp.rows.length) {
      log('ok', '✅ Nothing to do — all files already have thumbnails!');
      finish(); return;
    }
    queue      = resp.rows;
    totalCount = resp.rows.length;
    log('info', '📋 ' + totalCount + ' files queued (' + queue.filter(r=>isVideoName(r.image_name)).length + ' videos, ' + queue.filter(r=>!isVideoName(r.image_name)).length + ' images)');
    updateProgress(0, totalCount);
    processNext();
  } catch(e) {
    log('err', '❌ ' + e.message);
    finish();
  }
}

function isVideoName(name) {
  return ['mp4','webm','mov'].indexOf(name.split('.').pop().toLowerCase()) !== -1;
}

// ── Process loop ──────────────────────────────────────────────────────────────
async function processNext() {
  if (!running) return;
  if (paused)   { setTimeout(processNext, 200); return; }
  if (queueIndex >= queue.length) { finish(); return; }

  var row  = queue[queueIndex++];
  skipFlag = false;

  document.getElementById('currentFile').textContent = '⏳ ' + row.image_name;
  document.getElementById('viewerTitle').textContent = row.image_name;
  document.getElementById('viewerStatus').textContent = 'Loading…';
  // Reset viewer
  document.getElementById('sourceImg').style.display         = 'none';
  document.getElementById('sourceVideo').style.display       = 'none';
  document.getElementById('sourcePlaceholder').style.display = 'block';
  document.getElementById('sourcePlaceholder').textContent   = 'Loading…';
  document.getElementById('thumbPreviewImg').style.display   = 'none';
  document.getElementById('thumbPlaceholder').style.display  = 'block';
  document.getElementById('thumbDims').textContent           = '';

  try {
    var dataUrl = await loadAndCapture(row.image_name);
    if (skipFlag) {
      log('skip', '⏭ Skipped: ' + row.image_name);
    } else if (!dataUrl) {
      // error already logged in loadAndCapture
    } else {
      var result = await saveThumbnail(row.id, row.image_name, dataUrl);
      if (result.success) {
        doneCount++;
        log('ok', '✅ ' + row.image_name + ' → ' + result.thumbnail + ' (' + (result.size||'') + ')');
      } else {
        log('err', '❌ ' + row.image_name + ' — ' + (result.message||'save failed'));
      }
    }
  } catch(e) {
    log('err', '❌ ' + row.image_name + ' — ' + e.message);
  }

  updateProgress(queueIndex, totalCount);
  if (queueIndex % 10 === 0) loadStats();
  setTimeout(processNext, parseInt(document.getElementById('speedSel').value)||500);
}

// ── Load file via PHP, capture frame to canvas ────────────────────────────────
async function loadAndCapture(name) {
  // Ask PHP to serve the file
  var d;
  try {
    d = await fetch('?action=get_image&name=' + encodeURIComponent(name)).then(r=>r.json());
  } catch(e) {
    log('err', '❌ Fetch error: ' + name + ' — ' + e.message);
    return null;
  }

  if (!d.success) {
    log('err', '❌ Not on disk: ' + name + (d.deleted ? ' — Row automatically deleted from DB' : ''));
    document.getElementById('viewerStatus').textContent = 'Missing from disk' + (d.deleted ? ' (DB cleaned)' : '');
    return null;
  }

  if (skipFlag) return null;

  // ── VIDEO ────────────────────────────────────────────────────────────────
  if (d.is_video) {
    return new Promise(function(resolve) {
      var vid = document.getElementById('sourceVideo');
      vid.src = d.url;
      vid.style.display = 'block';
      document.getElementById('sourceImg').style.display         = 'none';
      document.getElementById('sourcePlaceholder').style.display = 'none';
      document.getElementById('sourceLabel').textContent         = '🎬 Video';

      var timer = setTimeout(function() {
        log('err', '⏱ Timeout loading video: ' + name);
        vid.src = ''; resolve(null);
      }, 25000);

      vid.onloadedmetadata = function() {
        // Seek to 10% of duration
        vid.currentTime = Math.max(0.1, (vid.duration || 10) * 0.1);
      };

      vid.onseeked = function() {
        clearTimeout(timer);
        if (skipFlag) { vid.src=''; resolve(null); return; }

        document.getElementById('viewerStatus').textContent = vid.videoWidth + '×' + vid.videoHeight + ' (video)';

        var origW = vid.videoWidth  || 640;
        var origH = vid.videoHeight || 360;
        var ratio = Math.min(320/origW, 320/origH, 1);
        var newW  = Math.round(origW * ratio);
        var newH  = Math.round(origH * ratio);

        var canvas = document.getElementById('workCanvas');
        canvas.width = newW; canvas.height = newH;
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0,0,newW,newH);
        ctx.drawImage(vid, 0,0,newW,newH);

        var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
        vid.pause();

        document.getElementById('thumbPreviewImg').src           = dataUrl;
        document.getElementById('thumbPreviewImg').style.display = 'block';
        document.getElementById('thumbPlaceholder').style.display= 'none';
        document.getElementById('thumbDims').textContent         = newW+'×'+newH+'px';

        resolve(dataUrl);
      };

      vid.onerror = function() {
        clearTimeout(timer);
        log('err', '❌ Video load error: ' + name);
        vid.src = ''; resolve(null);
      };

      vid.load();
    });
  }

  // ── IMAGE ─────────────────────────────────────────────────────────────────
  return new Promise(function(resolve) {
    var img = new Image();
    var timer = setTimeout(function() {
      log('err', '⏱ Timeout loading image: ' + name);
      resolve(null);
    }, 12000);

    img.onload = function() {
      clearTimeout(timer);
      if (skipFlag) { resolve(null); return; }

      document.getElementById('sourceImg').src               = d.dataUrl;
      document.getElementById('sourceImg').style.display     = 'block';
      document.getElementById('sourceVideo').style.display   = 'none';
      document.getElementById('sourcePlaceholder').style.display = 'none';
      document.getElementById('sourceLabel').textContent     = '🖼 Image';
      document.getElementById('viewerStatus').textContent    = img.naturalWidth + '×' + img.naturalHeight;

      var origW = img.naturalWidth  || 400;
      var origH = img.naturalHeight || 600;
      var ratio = Math.min(320/origW, 320/origH, 1);
      var newW  = Math.round(origW * ratio);
      var newH  = Math.round(origH * ratio);

      var canvas = document.getElementById('workCanvas');
      canvas.width = newW; canvas.height = newH;
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0,0,newW,newH);
      ctx.drawImage(img, 0,0,newW,newH);

      var dataUrl = canvas.toDataURL('image/jpeg', 0.82);

      document.getElementById('thumbPreviewImg').src           = dataUrl;
      document.getElementById('thumbPreviewImg').style.display = 'block';
      document.getElementById('thumbPlaceholder').style.display= 'none';
      document.getElementById('thumbDims').textContent         = newW+'×'+newH+'px';

      resolve(dataUrl);
    };

    img.onerror = function() {
      clearTimeout(timer);
      log('err', '❌ Image render failed: ' + name);
      resolve(null);
    };

    img.src = d.dataUrl;
  });
}

// ── Save thumbnail ─────────────────────────────────────────────────────────────
async function saveThumbnail(id, imageName, dataUrl) {
  var fd = new FormData();
  fd.append('ajax_action', 'save_thumb');
  fd.append('id',          id);
  fd.append('image_name',  imageName);
  fd.append('image_data',  dataUrl);
  return await fetch(location.href, {method:'POST',body:fd}).then(r=>r.json());
}

// ── UI helpers ────────────────────────────────────────────────────────────────
function togglePause() {
  paused = !paused;
  var btn = document.getElementById('btnPause');
  btn.textContent      = paused ? '▶ Resume' : '⏸ Pause';
  btn.style.background = paused ? 'var(--grn)' : 'var(--amber)';
  log('info', paused ? '⏸ Paused' : '▶ Resumed');
}

function skipCurrent() { skipFlag = true; }

function finish() {
  running = false;
  document.getElementById('btnStart').disabled         = false;
  document.getElementById('btnPause').disabled         = true;
  document.getElementById('btnSkip').disabled          = true;
  document.getElementById('btnPause').textContent      = '⏸ Pause';
  document.getElementById('btnPause').style.background = 'var(--amber)';
  document.getElementById('progressLabel').textContent = '✅ Done — ' + doneCount + ' thumbnails generated';
  document.getElementById('currentFile').textContent   = '';
  document.getElementById('viewerTitle').textContent   = 'Complete';
  document.getElementById('viewerStatus').textContent  = '';
  log('ok', '🎉 Finished! ' + doneCount + ' thumbnails saved.');
  loadStats();
}

function updateProgress(done, total) {
  if (!total) return;
  var pct = Math.round((done/total)*100);
  document.getElementById('progressBar').style.width   = pct + '%';
  document.getElementById('progressPct').textContent   = pct + '%';
  document.getElementById('progressLabel').textContent = done + ' / ' + total + ' processed';
}

function log(type, msg) {
  var area = document.getElementById('logArea');
  var line = document.createElement('div');
  line.className   = 'log-' + type;
  var ts = new Date().toLocaleTimeString('en-GB',{hour12:false});
  line.textContent = '[' + ts + '] ' + msg;
  area.appendChild(line);
  area.scrollTop = area.scrollHeight;
}

function clearLog() { document.getElementById('logArea').innerHTML = ''; }
</script>
</body>
</html>
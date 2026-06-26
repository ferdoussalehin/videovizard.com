<?php
// video_recorder.php — Standalone recorder (Phase 1: Play + Record buttons)
// Phase 2: remove buttons, auto-record on load
ob_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
include 'dbconnect_hdb.php';

// ── VPS Config ────────────────────────────────────────────────────────────────
$VPS_URL    = 'http://187.124.249.46/videovizard.com/vps_convert.php';
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';

$podcast_id = (int)($_GET['podcast_id'] ?? 0);
if (!$podcast_id) die('Missing podcast_id');

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
if (!$row) die('Podcast #'.$podcast_id.' not found');

$podcast_title = $row['title']      ?? '';
$podcast_music = $row['music_file'] ?? '';
$lang_code     = $row['lang_code']  ?? 'en';
$video_type    = $row['video_type'] ?? 'standard';
$img_folder    = trim(trim($row['image_folder'] ?? ''), '/') ?: 'podcast_images';
$vid_folder    = $img_folder;
$audio_speed   = isset($row['audio_speed']) && $row['audio_speed'] > 0
                 ? (float)$row['audio_speed'] : 1.0;
$host_voice_id  = $row['host_voice_id']  ?? $row['host_voice']   ?? $row['voice_id']      ?? '';
$guest_voice_id = $row['guest_voice_id'] ?? $row['guest_voice']  ?? $row['voice_id_guest'] ?? '';

// Auto-add slot columns if missing
$slot_cols = [
    'slot_main' => "TINYINT(1) NOT NULL DEFAULT 1",
    'slot_1'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_2'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_3'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_4'    => "TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($slot_cols as $col => $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0)
        mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN $col $def");
}

$scenes = [];
$q = mysqli_query($conn,
    "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
while ($r = mysqli_fetch_assoc($q)) $scenes[] = $r;
if (!$scenes && !isset($_POST['ajax_action'])) die('No scenes for podcast #'.$podcast_id);

$scenes_json = json_encode($scenes);

$all_captions = [];
if ($scenes) {
    $scene_ids = implode(',', array_map(fn($s)=>(int)$s['id'], $scenes));
    $cq = mysqli_query($conn,
        "SELECT * FROM hdb_captions WHERE podcast_id=$podcast_id AND story_id IN ($scene_ids) ORDER BY z_index ASC, id ASC");
    while ($cr = mysqli_fetch_assoc($cq)) $all_captions[] = $cr;
}
$all_captions_json = json_encode($all_captions);

// Collect video files for pre-pool
$vid_files = [];
$img_slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
foreach ($scenes as $sc) {
    foreach ($img_slots as $k) {
        $fn = trim($sc[$k] ?? '');
        if ($fn && preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $fn) && !in_array($fn, $vid_files))
            $vid_files[] = $fn;
    }
}

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // ── queue_generate ─────────────────────────────────────────────────────
    if ($action === 'queue_generate') {
        $pid     = (int)($_POST['podcast_id'] ?? 0);
        $created = date('Y-m-d H:i:s');
        $res     = $conn->query("SELECT COUNT(*) as cnt FROM hdb_general_que WHERE status = 0");
        $pending = (int)($res->fetch_assoc()['cnt'] ?? 0);
        $stmt    = $conn->prepare(
            "INSERT INTO hdb_general_que (podcast_id, que_type, created_at, status, next_action)
             VALUES (?, 'video', ?, 0, 'RECORD')");
        $stmt->bind_param('is', $pid, $created);
        $stmt->execute();
        $insert_id = $conn->insert_id;
        $stmt->close();
        $total = $pending + 1;
        echo json_encode(['success'=>true,'que_id'=>$insert_id,'position'=>$total,'minutes'=>$total * 3]);
        ob_end_flush(); exit;
    }

    // ── save_published_video ───────────────────────────────────────────────
    if ($action === 'save_published_video') {
        $pid = (int)($_POST['podcast_id'] ?? 0);
        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'No file received']); exit;
        }
        $dir = __DIR__ . '/published_videos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach (glob($dir . 'podcast_' . $pid . '.*') as $old) @unlink($old);
        $ext      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
        $ext      = in_array($ext, ['mp4','webm']) ? $ext : 'webm';
        $filename = 'podcast_' . $pid . '.' . $ext;
        if (!move_uploaded_file($_FILES['video']['tmp_name'], $dir . $filename)) {
            echo json_encode(['success'=>false,'message'=>'Failed to save file']); exit;
        }
        $esc = mysqli_real_escape_string($conn, $filename);
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET video_filename='$esc', published_video='$esc',
             video_status='RECORDED', updated_at=NOW() WHERE id=$pid AND admin_id=$admin_id");
        echo json_encode(['success'=>true,'filename'=>$filename]); exit;
    }

    // ── update_video_status ────────────────────────────────────────────────
    if ($action === 'update_video_status') {
        $pid    = (int)($_POST['podcast_id'] ?? 0);
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
        if ($pid && $status)
            mysqli_query($conn, "UPDATE hdb_podcasts SET video_status='$status', updated_at=NOW() WHERE id=$pid");
        echo json_encode(['success'=>true]); exit;
    }

    // ── start_mp4_convert ─────────────────────────────────────────────────
    if ($action === 'start_mp4_convert') {
        $pid      = (int)($_POST['podcast_id'] ?? 0);
        $webm_path = __DIR__ . '/published_videos/podcast_' . $pid . '.webm';
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $webm_url  = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/published_videos/podcast_' . $pid . '.webm';
        if (!file_exists($webm_path)) {
            echo json_encode(['success'=>false,'message'=>'WebM not found']); exit;
        }
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $VPS_URL,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => [
                'secret_key' => $SECRET_KEY,
                'action'     => 'convert',
                'podcast_id' => $pid,
                'webm_url'   => $webm_url,
            ],
        ]);
        $resp     = curl_exec($curl);
        $curl_err = curl_error($curl);
        curl_close($curl);
        if ($curl_err) { echo json_encode(['success'=>false,'message'=>$curl_err,'fallback'=>true]); exit; }
        $data = json_decode($resp, true);
        if (!$data || !$data['success']) {
            echo json_encode(['success'=>false,'message'=>'VPS unavailable','webm_url'=>$webm_url,'fallback'=>true]); exit;
        }
        echo json_encode($data); exit;
    }

    // ── poll_mp4_convert ──────────────────────────────────────────────────
    if ($action === 'poll_mp4_convert') {
        $pid    = (int)($_POST['podcast_id'] ?? 0);
        $job_id = trim($_POST['job_id'] ?? '');
        $log    = function($m){ file_put_contents(__DIR__.'/a_errors.log', date('[Y-m-d H:i:s] ').$m."\n", FILE_APPEND); };
        $curl   = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $VPS_URL . '?action=status&job_id=' . urlencode($job_id) . '&secret_key=' . urlencode($SECRET_KEY),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp     = curl_exec($curl);
        $curl_err = curl_error($curl);
        curl_close($curl);
        if ($curl_err) { echo json_encode(['status'=>'error','message'=>$curl_err]); exit; }
        $data = json_decode($resp, true);
        if (!$data)    { echo json_encode(['status'=>'error','message'=>'Bad VPS response']); exit; }

        if (($data['status'] ?? '') === 'done') {
            $mp4_url  = $data['mp4_url'] ?? '';
            $mp4_path = __DIR__ . '/published_videos/podcast_' . $pid . '.mp4';
            $webm_path = __DIR__ . '/published_videos/podcast_' . $pid . '.webm';
            if ($mp4_url) {
                $ch = curl_init($mp4_url);
                $fp = fopen($mp4_path, 'wb');
                curl_setopt_array($ch, [CURLOPT_FILE=>$fp, CURLOPT_TIMEOUT=>300, CURLOPT_FOLLOWLOCATION=>true]);
                $ok = curl_exec($ch); curl_close($ch); fclose($fp);
                if ($ok && file_exists($mp4_path) && filesize($mp4_path) > 0) {
                    @unlink($webm_path);
                    @file_get_contents($VPS_URL.'?action=cleanup&secret_key='.urlencode($SECRET_KEY).'&job_id='.urlencode($job_id).'&podcast_id='.$pid);
                    $esc = mysqli_real_escape_string($conn, 'podcast_'.$pid.'.mp4');
                    mysqli_query($conn, "UPDATE hdb_podcasts SET video_filename='$esc', video_status='RECORDED', updated_at=NOW() WHERE id=$pid AND admin_id=$admin_id");
                    $data['mp4_size_mb'] = round(filesize($mp4_path)/1024/1024, 2);
                    $data['filename']    = 'podcast_'.$pid.'.mp4';
                }
            }
        }
        echo json_encode($data); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

define('CW', 360);
define('CH', 640);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recorder — <?= htmlspecialchars($podcast_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<!-- Same custom fonts as videomaker -->
<style>
@font-face { font-family:'NotoNastaliqUrdu'; src:url('./fonts/NotoNastaliqUrdu-Regular.woff2') format('woff2'); font-display:swap; }
@font-face { font-family:'AttariQuraanWord';  src:url('./fonts/Attari_Quraan_Word.woff2')      format('woff2'); font-display:swap; }
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
:root {
    --primary:#0f2a44; --info:#3b82f6; --success:#10b981;
    --danger:#ef4444;  --warning:#f59e0b;
    --bg:#f0f4f8;      --surface:#fff;  --surface2:#f8fafc;
    --border:#e2e8f0;  --text:#0f172a;  --muted:#64748b;
    --radius:14px;
    --shadow:0 4px 20px rgba(15,42,68,.10);
    --shadow-lg:0 8px 40px rgba(15,42,68,.16);
}
body {
    font-family:'Inter',system-ui,sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; display:flex; flex-direction:column; align-items:center;
}

/* ── Header ─────────────────────────────────────────────────────── */
.vv-header {
    width:100%; background:var(--primary);
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 20px; box-shadow:0 2px 12px rgba(0,0,0,.25);
    position:sticky; top:0; z-index:100;
}
.vv-brand { display:flex; align-items:center; gap:8px; text-decoration:none; }
.vv-brand .name { font-size:16px; font-weight:800; color:#fff; }
.vv-brand .name span { color:#5fc3ff; }
.vv-title { font-size:12px; color:rgba(255,255,255,.65); font-weight:500;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }
.vv-back { background:rgba(255,255,255,.12); border:none; color:#fff;
           padding:6px 14px; border-radius:30px; font-size:11px; font-weight:600; cursor:pointer; }
.vv-back:hover { background:rgba(255,255,255,.22); }

/* ── Main layout ─────────────────────────────────────────────────── */
.main { display:flex; flex-direction:column; align-items:center; padding:20px 12px 40px; gap:14px; width:100%; max-width:440px; }

/* ── Canvas / player ─────────────────────────────────────────────── */
#playerWrap {
    position:relative;
    width:<?= CW ?>px; height:<?= CH ?>px;
    border-radius:18px; overflow:hidden;
    background:#000;
    box-shadow:0 0 0 1.5px var(--border), var(--shadow-lg);
    flex-shrink:0;
    /* Scale down to fit nicely on screen */
    transform:scale(0.72); transform-origin:top center;
    margin-bottom:calc(<?= CH ?>px * -0.295);
}
#screen   { display:block; }
#screenHD { display:none; }

/* recording red outline */
#screen.recording { outline:3px solid var(--danger); outline-offset:3px; }

/* Preload overlay */
#preloadOverlay {
    position:absolute; inset:0; background:rgba(15,42,68,.88);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:12px; border-radius:18px; z-index:10;
}
#preloadOverlay.gone { display:none; }
#preloadOverlay .spinner {
    width:36px; height:36px;
    border:4px solid rgba(255,255,255,.15); border-top-color:#5fc3ff;
    border-radius:50%; animation:spin .7s linear infinite;
}
#preloadMsg { font-size:12px; color:#fff; font-weight:600; text-align:center; padding:0 20px; }
#preloadBar {
    width:70%; height:4px; background:rgba(255,255,255,.15); border-radius:2px; overflow:hidden;
}
#preloadBar::after { content:''; display:block; height:100%; background:#5fc3ff; width:var(--pct,0%); transition:width .2s; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── Buttons ─────────────────────────────────────────────────────── */
.btn-row { display:flex; gap:10px; width:<?= CW ?>px; }
.vbtn {
    flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;
    padding:12px 8px 10px;
    border:none; border-radius:14px; font-family:inherit;
    font-size:11px; font-weight:700; cursor:pointer; transition:all .15s;
    user-select:none;
}
.vbtn .ico { font-size:22px; line-height:1; }
.vbtn-play { background:var(--primary); color:#fff; }
.vbtn-play:hover:not(:disabled) { background:#143b63; }
.vbtn-rec  { background:var(--danger); color:#fff; }
.vbtn-rec:hover:not(:disabled) { background:#dc2626; }
.vbtn:disabled { opacity:.5; cursor:not-allowed; }

/* ── Rec indicator bar ────────────────────────────────────────────── */
#recBar {
    display:none; align-items:center; gap:8px;
    background:#fff0f0; border:1.5px solid var(--danger); border-radius:10px;
    padding:7px 14px; font-size:11px; font-weight:700; color:var(--danger);
    width:<?= CW ?>px;
}
#recBar.on { display:flex; }
#recDot {
    width:10px; height:10px; border-radius:50%; background:var(--danger);
    animation:blink 1s step-start infinite; flex-shrink:0;
}
@keyframes blink { 50% { opacity:0; } }

/* ── Scene nav ────────────────────────────────────────────────────── */
#sceneNav {
    display:flex; gap:10px; align-items:center; justify-content:center;
    width:<?= CW ?>px; background:var(--surface);
    border:1.5px solid var(--border); border-radius:10px; padding:6px 14px;
}
.nav-btn {
    width:30px; height:30px; border-radius:50%; background:var(--primary);
    border:none; color:#fff; font-size:15px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
}
.nav-btn:disabled { opacity:.35; cursor:not-allowed; }
#dotRow { display:flex; gap:5px; flex-wrap:wrap; justify-content:center; max-width:<?= CW ?>px; }
.dot { width:7px; height:7px; border-radius:50%; background:var(--border); transition:background .2s; }
.dot.active { background:var(--info); }

/* ── Notification ─────────────────────────────────────────────────── */
#notification {
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
    min-width:280px; max-width:360px; z-index:9999;
    background:#0f2a44; color:#fff; border-radius:14px;
    padding:14px 18px; box-shadow:0 8px 30px rgba(0,0,0,.35);
    font-size:13px; font-weight:600; display:none;
    flex-direction:column; gap:6px;
    animation:slideUp .3s ease;
}
#notification.show { display:flex; }
#notification .notif-title { font-size:14px; font-weight:800; }
#notification .notif-sub   { font-size:11px; opacity:.8; font-weight:400; }
#notification .notif-close {
    position:absolute; top:8px; right:10px; background:none; border:none;
    color:#fff; opacity:.6; font-size:16px; cursor:pointer; padding:0;
}
#notification.success { background:#064e3b; }
#notification.error   { background:#7f1d1d; }
#notification.warning { background:#78350f; }
@keyframes slideUp {
    from { transform:translateX(-50%) translateY(20px); opacity:0; }
    to   { transform:translateX(-50%) translateY(0);    opacity:1; }
}

/* ── Upload overlay ────────────────────────────────────────────────── */
#uploadOverlay {
    display:none; position:fixed; inset:0;
    background:rgba(15,42,68,.82); z-index:99998;
    align-items:center; justify-content:center;
}
#uploadOverlay > div {
    background:#fff; border-radius:16px; padding:32px 40px;
    text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.4); max-width:360px;
}
.uo-spinner {
    width:44px; height:44px; border:4px solid rgba(59,130,246,.2);
    border-top-color:#3b82f6; border-radius:50%;
    animation:spin .7s linear infinite; margin:0 auto 16px;
}

/* ── Log ─────────────────────────────────────────────────────────── */
#log {
    display:none; width:<?= CW ?>px; max-height:120px; overflow-y:auto;
    background:var(--surface); border:1.5px solid var(--border);
    border-radius:10px; padding:8px 10px; font-size:10px; font-family:monospace;
}
#log p { margin:1px 0; color:var(--muted); }
#log p.ok  { color:var(--success); }
#log p.err { color:var(--danger); }
#log p.inf { color:var(--info); }
#log p.wrn { color:var(--warning); }

/* ── Download panel ───────────────────────────────────────────────── */
#dlPanel {
    display:none; width:<?= CW ?>px; background:var(--surface);
    border:1.5px solid var(--border); border-radius:14px; padding:16px;
    box-shadow:var(--shadow);
}
#dlPanel.on { display:block; }
#dlPanel h3 { font-size:14px; font-weight:800; color:var(--primary); margin-bottom:6px; }
#dlPanel p  { font-size:11px; color:var(--muted); margin-bottom:10px; }
.dl-btn {
    display:inline-block; padding:9px 18px; border-radius:10px;
    border:none; font-family:inherit; font-size:12px; font-weight:700;
    cursor:pointer; text-decoration:none; margin-right:8px; margin-bottom:6px;
}
.dl-btn-mp4  { background:var(--success); color:#fff; }
.dl-btn-webm { background:var(--info); color:#fff; }
.dl-btn-close { background:var(--surface2); color:var(--text); border:1.5px solid var(--border); }
</style>
</head>
<body>

<!-- Header -->
<div class="vv-header">
    <a class="vv-brand" href="vizard_browser.php">
        <span style="font-size:20px;">🎬</span>
        <span class="name">Video<span>Vizard</span></span>
    </a>
    <span class="vv-title"><?= htmlspecialchars($podcast_title ?: 'Recorder') ?></span>
    <button class="vv-back" onclick="history.back()">← Back</button>
</div>

<!-- Hidden video pool -->
<div id="vidPool" style="display:none;"></div>

<!-- Hidden slot checkboxes needed by preloadAll / sceneMedia / getEnabledSlots -->
<div style="display:none;">
    <input type="checkbox" id="slotChk_image_file"   checked>
    <input type="checkbox" id="slotChk_image_file_1">
    <input type="checkbox" id="slotChk_image_file_2">
    <input type="checkbox" id="slotChk_image_file_3">
    <input type="checkbox" id="slotChk_image_file_4">
</div>

<div class="main">

    <!-- ── Buttons ─────────────────────────────────────────────────── -->
    <div class="btn-row">
        <button class="vbtn vbtn-play" id="btnPlay" onclick="togglePlay()">
            <span class="ico" id="playIco">▶</span>
            <span id="playLbl">Preview</span>
        </button>
        <button class="vbtn vbtn-rec" id="btnRec"
                onclick="isRecording ? stopRecording() : startRecording()">
            <span class="ico">⏺</span>
            <span id="recLbl">Record & Save</span>
        </button>
    </div>

    <!-- ── Canvas ──────────────────────────────────────────────────── -->
    <div id="playerWrap">
        <canvas id="screen"   width="<?= CW ?>" height="<?= CH ?>"></canvas>
        <canvas id="screenHD" width="1080" height="1920"></canvas>
        <div id="preloadOverlay">
            <div class="spinner"></div>
            <p id="preloadMsg">Loading first scene…</p>
            <div id="preloadBar"></div>
        </div>
    </div>

    <!-- ── Title chip ───────────────────────────────────────────────── -->
    <div style="width:<?= CW ?>px; background:var(--surface); border:1.5px solid var(--border);
                border-radius:10px; padding:7px 12px; display:flex; align-items:center; gap:6px;">
        <span style="font-size:13px;">🎬</span>
        <span style="font-size:11px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= htmlspecialchars($podcast_title ?: 'Untitled') ?>
        </span>
        <span style="margin-left:auto; font-size:10px; color:var(--muted);">
            <?= count($scenes) ?> scenes
        </span>
    </div>

    <!-- ── Scene nav ────────────────────────────────────────────────── -->
    <div id="sceneNav">
        <button class="nav-btn" id="navPrev" onclick="navigate(-1)">←</button>
        <span id="sceneNum" style="font-size:12px; font-weight:700; min-width:50px; text-align:center;">
            1 / <?= count($scenes) ?>
        </span>
        <button class="nav-btn" id="navNext" onclick="navigate(1)">→</button>
    </div>

    <!-- Dots -->
    <div id="dotRow">
        <?php foreach ($scenes as $i => $s): ?>
        <div class="dot<?= $i === 0 ? ' active' : '' ?>" id="dot<?= $i ?>"></div>
        <?php endforeach; ?>
    </div>

    <!-- ── Rec indicator ─────────────────────────────────────────────── -->
    <div id="recBar">
        <div id="recDot"></div>
        <span>Recording…</span>
        <span id="recSize" style="margin-left:auto;">0.0 MB</span>
    </div>

    <!-- ── Download panel ──────────────────────────────────────────── -->
    <div id="dlPanel"></div>

    <!-- ── Log ─────────────────────────────────────────────────────── -->
    <div id="log"></div>

</div><!-- .main -->

<!-- ── Upload overlay ───────────────────────────────────────────── -->
<div id="uploadOverlay">
    <div>
        <div class="uo-spinner"></div>
        <div id="overlayTitle" style="font-size:15px; font-weight:700; color:#0f2a44; margin-bottom:8px;">
            Uploading your video…
        </div>
        <div id="overlayDesc" style="font-size:12px; color:#64748b; line-height:1.6;">
            Sending to server. Please wait…
        </div>
        <div id="overlayTimer" style="font-size:11px; color:#94a3b8; margin-top:10px;"></div>
    </div>
</div>

<!-- ── Toast notification ────────────────────────────────────────── -->
<div id="notification">
    <button class="notif-close" onclick="closeNotif()">✕</button>
    <div class="notif-title" id="notifTitle"></div>
    <div class="notif-sub"   id="notifSub"></div>
</div>

<script>
// ── Data from PHP ──────────────────────────────────────────────────
const SCENES        = <?= $scenes_json ?>;
const ALL_CAPTIONS  = <?= $all_captions_json ?>;
const VID_FILES     = <?= json_encode($vid_files) ?>;
const IMG_BASE      = '<?= htmlspecialchars($img_folder, ENT_QUOTES) ?>/';
const VID_BASE      = '<?= htmlspecialchars($vid_folder, ENT_QUOTES) ?>/';
const USER_MEDIA_FOLDER = 'user_media/user_id_<?= (int)$admin_id ?>_company_id_<?= (int)$company_id ?>/';
const AUD_BASE      = 'podcast_audios/';
const MUS_BASE      = 'podcast_music/';
const PODCAST_ID    = <?= $podcast_id ?>;
const AUDIO_SPEED   = <?= json_encode($audio_speed) ?>;
const VIDEO_TYPE    = '<?= addslashes($video_type) ?>';
const CW = <?= CW ?>, CH = <?= CH ?>;
const T_DUR = 380, KB_DUR = 8000;
const KB_EFFECTS = ['zoom-in','zoom-out','pan-left','pan-right'];
const SLOTS = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
const SLOT_FOLDER_COL = {
    image_file:   'image_folder',
    image_file_1: 'image_folder_1',
    image_file_2: 'image_folder_2',
    image_file_3: 'image_folder_3',
    image_file_4: 'image_folder_4',
};
const SCENE_SAVED_SLOTS = <?php
    $slotMap = [];
    foreach ($scenes as $sc) {
        $slotMap[(int)$sc['id']] = [
            'slot_main' => (int)($sc['slot_main'] ?? 1),
            'slot_1'    => (int)($sc['slot_1']    ?? 0),
            'slot_2'    => (int)($sc['slot_2']    ?? 0),
            'slot_3'    => (int)($sc['slot_3']    ?? 0),
            'slot_4'    => (int)($sc['slot_4']    ?? 0),
        ];
    }
    echo json_encode($slotMap);
?>;
const SLOT_COL_MAP = {
    image_file:   'slot_main',
    image_file_1: 'slot_1',
    image_file_2: 'slot_2',
    image_file_3: 'slot_3',
    image_file_4: 'slot_4',
};

// Filename → folder map
const FILE_FOLDER = {};
(function(){
    SCENES.forEach(sc => {
        Object.entries(SLOT_FOLDER_COL).forEach(([slot, folderCol]) => {
            const fn = (sc[slot]||'').trim();
            if (!fn) return;
            const f = (sc[folderCol]||sc.image_folder||'').trim().replace(/^\/|\/$/g,'');
            FILE_FOLDER[fn] = (f||'podcast_images') + '/';
        });
    });
})();

// ── Canvas ────────────────────────────────────────────────────────
const canvas   = document.getElementById('screen');
const ctx      = canvas.getContext('2d');
const canvasHD = document.getElementById('screenHD');
const ctxHD    = canvasHD.getContext('2d');

const vidEls = {};
const imgCache = {};
const sceneKB  = SCENES.map(() => KB_EFFECTS[Math.floor(Math.random()*KB_EFFECTS.length)]);

const S = {
    type:'blank', img:null, imgOut:null, vidEl:null,
    alpha:1, alphaOut:0, kbEffect:'zoom-in', kbStart:0,
    txOffset:null, nextImg:null, nextVid:null, nextType:null, isTransitioning:false
};

let renderRaf=null, framesDrawn=0;
let currentIndex=0, isPlaying=false, isRecording=false;
let currentAudio=null, transitioning=false;
let activeSlot='image_file';
let bgAudio=null, bgMusicVolume=0.3, voiceVolume=1.0;
let currentPlaybackSpeed = AUDIO_SPEED;
let _vsRecURL=null, _vsRecFname=null;
<?php if ($podcast_music): ?>
bgAudio = new Audio('<?= addslashes(MUS_BASE.$podcast_music) ?>');
bgAudio.loop = true; bgAudio.volume = bgMusicVolume;
<?php endif; ?>

// ── Caption state ─────────────────────────────────────────────────
const captionStates = {};
let sceneCaptions   = [];
let selectedCapId   = null;

// ── Notification ──────────────────────────────────────────────────
let _notifTimer = null;
function notify(title, sub='', type='', duration=5000) {
    const el   = document.getElementById('notification');
    const tEl  = document.getElementById('notifTitle');
    const sEl  = document.getElementById('notifSub');
    el.className = 'show' + (type ? ' ' + type : '');
    tEl.textContent = title;
    sEl.textContent = sub;
    clearTimeout(_notifTimer);
    if (duration > 0) _notifTimer = setTimeout(closeNotif, duration);
}
function closeNotif() {
    document.getElementById('notification').className = '';
}

// ── Log ───────────────────────────────────────────────────────────
function L(m, c='') {
    const el = document.getElementById('log');
    el.style.display = 'block';
    const p = document.createElement('p');
    if (c) p.className = c;
    p.textContent = m;
    el.appendChild(p);
    el.scrollTop = el.scrollHeight;
}

// ── Helpers ───────────────────────────────────────────────────────
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function getSceneFolder(sc, slot) {
    const folderCol = SLOT_FOLDER_COL[slot] || 'image_folder';
    const raw = (sc[folderCol] || sc.image_folder || '').trim().replace(/^\/|\/$/g,'');
    return (raw || 'podcast_images') + '/';
}
function getFileFolder(fn) { return FILE_FOLDER[fn] || IMG_BASE; }
function getImageSrc(fn, sc, slot) {
    if (!fn) return '';
    if (fn.startsWith('logo_')) return 'podcast_logos/' + fn;
    return getSceneFolder(sc, slot) + fn;
}
function isVideoReady(v) { return v && v.readyState >= 2 && v.videoWidth > 0 && v.videoHeight > 0; }

function getEnabledSlots() {
    // Use saved slot preferences from DB for the current scene
    const sc = SCENES[currentIndex];
    if (!sc) return ['image_file'];
    const saved = SCENE_SAVED_SLOTS[sc.id];
    if (!saved) return ['image_file'];
    const colToSlot = { slot_main:'image_file', slot_1:'image_file_1', slot_2:'image_file_2', slot_3:'image_file_3', slot_4:'image_file_4' };
    const enabled = [];
    for (const [col, slot] of Object.entries(colToSlot)) {
        if (saved[col]) enabled.push(slot);
    }
    // Also check the hidden checkboxes (for preloadAll compatibility)
    const fromChk = SLOTS.filter(k => {
        const chk = document.getElementById('slotChk_' + k);
        return chk && chk.checked;
    });
    // Merge: prefer DB slots, fall back to checkbox state
    const merged = [...new Set([...enabled, ...fromChk])];
    return merged.length ? merged : ['image_file'];
}

function applySceneSlots(sc) {
    if (!sc) return;
    const saved = SCENE_SAVED_SLOTS[sc.id];
    if (!saved) return;
    const colToSlot = { slot_main:'image_file', slot_1:'image_file_1', slot_2:'image_file_2', slot_3:'image_file_3', slot_4:'image_file_4' };
    for (const [col, slot] of Object.entries(colToSlot)) {
        const chk = document.getElementById('slotChk_' + slot);
        if (chk) chk.checked = !!saved[col];
    }
}

function sceneMedia(sc, slotOverride) {
    if (slotOverride) {
        const v = (sc[slotOverride]||'').trim();
        return { fn:v||null, isVideo:/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(v), slot:slotOverride };
    }
    const enabledSlots = getEnabledSlots();
    for (const slot of enabledSlots) {
        const fn = (sc[slot]||'').trim();
        if (fn) return { fn, isVideo:/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn), slot };
    }
    return { fn:null, isVideo:false, slot:'image_file' };
}

function isTalkingHeadScene(sc) {
    const folder = (sc.image_folder||'').trim().replace(/\/+$/,'');
    return folder === 'user_videos' && /\.(mp4|webm|mov|m4v)$/i.test((sc.image_file||'').trim());
}

// ── Caption system (rendering only — no editor UI) ────────────────
function loadSceneCaptions(sceneId) {
    sceneCaptions = ALL_CAPTIONS.filter(c => +c.story_id === +sceneId);
    sceneCaptions.forEach(c => {
        if (!captionStates[c.id])
            captionStates[c.id] = { show:'', full:'', words:[], karIdx:0, timer:null };
        if (c.is_visible) startCaptionAnim(c);
    });
}
function stopCaptionAnim(capId) {
    const st = captionStates[capId];
    if (!st) return;
    if (st.timer) { clearInterval(st.timer); clearTimeout(st.timer); st.timer = null; }
}
function startCaptionAnim(cap) {
    stopCaptionAnim(cap.id);
    const st = captionStates[cap.id] || (captionStates[cap.id] = { show:'', full:'', words:[], karIdx:0, timer:null });
    if (cap._extraVPad === undefined) cap._extraVPad = parseInt(cap.rotation) || 0;
    const text = cap.text_content || '';
    st.full = text; st.words = text.split(' '); st.karIdx = 0; st.show = '';
    const style = cap.animation_style || 'none';
    const spd   = parseFloat(cap.animation_speed) || 1;
    if (['static','none','fade-in','zoom-in','pop','bounce'].includes(style)) { st.show = text; return; }
    if (style === 'typewriter' || style === 'char-by-char') {
        let i=0; const ms=Math.round((style==='char-by-char'?60:36)/spd);
        st.timer=setInterval(()=>{ st.show=text.substring(0,++i); if(i>=text.length){clearInterval(st.timer);st.timer=null;} },ms); return;
    }
    if (style==='word-reveal') {
        let wi=0; const ms=Math.round(140/spd);
        st.timer=setInterval(()=>{ st.show=st.words.slice(0,++wi).join(' '); if(wi>=st.words.length){clearInterval(st.timer);st.timer=null;} },ms); return;
    }
    if (style==='line-by-line') {
        const chunk=6,chunks=[];
        for(let i=0;i<st.words.length;i+=chunk) chunks.push(st.words.slice(i,i+chunk).join(' '));
        let ci=0; st.show=chunks[ci++]||'';
        const ms=Math.round(900/spd);
        st.timer=setInterval(()=>{ if(ci>=chunks.length){clearInterval(st.timer);st.timer=null;return;} st.show=chunks[ci++]; },ms); return;
    }
    if (style==='karaoke') {
        st.show=text; st.karIdx=0;
        const ms=Math.round(320/spd);
        st.timer=setInterval(()=>{ st.karIdx++; if(st.karIdx>=st.words.length){clearInterval(st.timer);st.timer=null;} },ms); return;
    }
    st.show = text;
}

function _toHex(color) {
    if (!color) return '#ffffff';
    color = color.trim();
    if (/^#[0-9a-f]{6}$/i.test(color)) return color;
    try {
        const c=document.createElement('canvas'); c.width=c.height=1;
        const x=c.getContext('2d'); x.fillStyle=color; x.fillRect(0,0,1,1);
        const d=x.getImageData(0,0,1,1).data;
        return '#'+[d[0],d[1],d[2]].map(v=>v.toString(16).padStart(2,'0')).join('');
    } catch(e) { return '#ffffff'; }
}

function _capBoxH(cap) {
    const fs=parseInt(cap.fontsize)||22, lh=fs+7, pad=10+(cap._extraVPad||0);
    return lh + pad*2;
}

function drawAllCaptions() { sceneCaptions.forEach(cap => drawOneCaption(cap)); }

function drawOneCaption(cap) {
    if (!cap.is_visible) return;
    if (cap.caption_type === 'image') {
        const fn=cap.text_content||'', px=parseInt(cap.position_x)||20, py=parseInt(cap.position_y)||20;
        const pw=parseInt(cap.width)||120, ph=parseInt(cap.rotation)||120;
        cap._bbox={x:px,y:py,w:pw,h:ph};
        const img=imgCache[fn];
        if (img===null) { ctx.save(); ctx.fillStyle='rgba(100,100,100,0.25)'; ctx.fillRect(px,py,pw,ph); ctx.restore(); }
        else if (img) { ctx.save(); ctx.drawImage(img,px,py,pw,ph); ctx.restore(); }
        else {
            ctx.save(); ctx.fillStyle='rgba(100,100,100,0.4)'; ctx.fillRect(px,py,pw,ph); ctx.restore();
            if (!imgCache['_loading_'+fn]) {
                imgCache['_loading_'+fn]=true;
                const i=new Image(); i.crossOrigin='anonymous';
                i.onload=()=>{ imgCache[fn]=i; delete imgCache['_loading_'+fn]; };
                i.onerror=()=>{ imgCache[fn]=null; delete imgCache['_loading_'+fn]; };
                i.src=getFileFolder(fn)+fn;
            }
        }
        return;
    }
    const st=captionStates[cap.id]; if(!st) return;
    const text=st.show||''; if(!text.trim()) { cap._bbox=null; return; }
    const fs=parseInt(cap.fontsize)||22, extraVPad=cap._extraVPad??parseInt(cap.rotation)??0;
    const pad=10+Math.round(extraVPad/2), lh=fs+7;
    const maxW=parseInt(cap.width)||320, posX=parseInt(cap.position_x)||50, posY=parseInt(cap.position_y)||400;
    const tAlign=cap.text_align||'center';
    const bold=(cap.fontweight==='bold'||cap.fontweight==='700')?'bold ':'';
    const italic=cap.fontstyle==='italic'?'italic ':'';
    ctx.save();
    ctx.font=`${italic}${bold}${fs}px '${cap.fontfamily||'Inter'}',sans-serif`;
    ctx.textBaseline='top';
    // Word wrap
    const words=text.split(' '); const lines=[]; let line='';
    for (const w of words) {
        const test=line?line+' '+w:w;
        if (ctx.measureText(test).width>maxW&&line) { lines.push(line); line=w; }
        else line=test;
    }
    if (line) lines.push(line);
    const totalH=lines.length*lh+pad*2;
    cap._bbox={x:posX,y:posY,w:maxW,h:totalH};
    // Background
    const bgEnabled=(cap.bg_enabled===1||cap.bg_enabled==='1'||cap.bg_enabled===true);
    if (bgEnabled) {
        ctx.globalAlpha=parseFloat(cap.bg_opacity)||0.7;
        ctx.fillStyle=cap.bg_color||'#000';
        const borderThick=parseInt(cap.caption_box_border_thickness)||0;
        if (borderThick>0) {
            ctx.strokeStyle=cap.caption_box_border_color||'#fff';
            ctx.lineWidth=borderThick;
        }
        ctx.beginPath();
        ctx.roundRect?ctx.roundRect(posX,posY,maxW,totalH,8):ctx.rect(posX,posY,maxW,totalH);
        ctx.fill();
        if (borderThick>0) ctx.stroke();
        ctx.globalAlpha=1;
    }
    // Outline
    const outEnabled=(cap.outline_enabled===1||cap.outline_enabled==='1'||cap.outline_enabled===true);
    if (outEnabled) {
        ctx.strokeStyle=cap.outline_color||'#000';
        ctx.lineWidth=parseFloat(cap.outline_width)||2;
        ctx.lineJoin='round';
    }
    ctx.fillStyle=cap.fontcolor||'#fff';
    let ax=posX+(tAlign==='right'?maxW:tAlign==='center'?maxW/2:0);
    ctx.textAlign=tAlign==='justify'?'left':tAlign;
    lines.forEach((ln,i)=>{
        const y=posY+pad+i*lh;
        if (outEnabled) ctx.strokeText(ln,ax,y);
        // Karaoke highlight
        if ((cap.animation_style||'')===('karaoke')) {
            const ws=ln.split(' '); let cx=ax;
            ws.forEach((wd,wi)=>{
                const gi=lines.slice(0,i).reduce((a,l)=>a+l.split(' ').length,0)+wi;
                ctx.fillStyle=gi===st.karIdx?'#fbbf24':(cap.fontcolor||'#fff');
                ctx.fillText(wd,cx,y); cx+=ctx.measureText(wd+' ').width;
            });
        } else {
            ctx.fillText(ln,ax,y);
        }
        if (cap.underline) {
            const tw=ctx.measureText(ln).width;
            const lx=tAlign==='center'?ax-tw/2:tAlign==='right'?ax-tw:ax;
            ctx.fillRect(lx,y+fs+1,tw,1.5);
        }
    });
    ctx.restore();
}

// ── Render loop ───────────────────────────────────────────────────
function startRender() {
    if (renderRaf) return;
    (function frame(){ drawFrame(); framesDrawn++; renderRaf=requestAnimationFrame(frame); })();
}
function drawFrame() {
    ctx.fillStyle='#000'; ctx.fillRect(0,0,CW,CH);
    if (S.imgOut && S.alphaOut>0.01) {
        ctx.save(); ctx.globalAlpha=S.alphaOut; drawCover(S.imgOut,null); ctx.restore();
    }
    if (S.alpha>0.01) {
        ctx.save(); ctx.globalAlpha=S.alpha;
        if (S.txOffset) {
            if (S.txOffset.x!=null) ctx.translate(S.txOffset.x,0);
            if (S.txOffset.scale) { ctx.translate(CW/2,CH/2); ctx.scale(S.txOffset.scale,S.txOffset.scale); ctx.translate(-CW/2,-CH/2); }
        }
        if (S.type==='image'&&S.img) {
            drawCover(S.img, S.kbEffect==='none'?null:kbXform(S.kbEffect,S.kbStart,S.img));
        } else if (S.type==='video'&&S.vidEl) {
            try { if(S.vidEl.videoWidth&&S.vidEl.videoHeight&&S.vidEl.readyState>=2) ctx.drawImage(S.vidEl,0,0,CW,CH); } catch(_){}
        }
        ctx.restore();
    }
    drawAllCaptions();
    ctxHD.drawImage(canvas,0,0,1080,1920);
}
function kbXform(ef,t0,img) {
    if(ef==='none'||!img)return null;
    const p=Math.min((performance.now()-t0)/KB_DUR,1);
    const e=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const zoom=base*1.18,off=Math.max(CW,CH)*0.055;
    const M={'zoom-in':{ss:base,es:zoom,sox:0,eox:0},'zoom-out':{ss:zoom,es:base,sox:0,eox:0},'pan-left':{ss:zoom,es:zoom,sox:off,eox:-off},'pan-right':{ss:zoom,es:zoom,sox:-off,eox:off}}[ef]||{ss:base,es:zoom,sox:0,eox:0};
    const s=M.ss+(M.es-M.ss)*e,ox=M.sox+(M.eox-M.sox)*e;
    return{s,ox,oy:0};
}
function drawCover(img,kb) {
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const s=kb?kb.s:base,w=img.naturalWidth*s,h=img.naturalHeight*s;
    ctx.drawImage(img,(CW-w)/2+(kb?kb.ox:0),(CH-h)/2+(kb?kb.oy:0),w,h);
}

// ── Transition ────────────────────────────────────────────────────
function doTransition(type, dur) {
    return new Promise(res => {
        if (type==='none') { S.alpha=1;S.alphaOut=0;S.imgOut=null;S.txOffset=null;res();return; }
        const startTime=performance.now(), duration=dur||T_DUR;
        function step(now) {
            const progress=Math.min((now-startTime)/duration,1);
            S.alpha=progress; S.alphaOut=1-progress;
            if(progress<1) requestAnimationFrame(step);
            else { S.alpha=1;S.alphaOut=0;S.imgOut=null;res(); }
        }
        requestAnimationFrame(step);
    });
}

// ── prepareNextScene ──────────────────────────────────────────────
async function prepareNextScene(nextIndex) {
    if (nextIndex<0||nextIndex>=SCENES.length) return;
    const sc=SCENES[nextIndex];
    const {fn,isVideo,slot}=sceneMedia(sc);
    if (isVideo&&fn) {
        if (!vidEls[fn]) {
            const v=document.createElement('video');
            v.muted=true; v.loop=!isTalkingHeadScene(sc);
            v.playsInline=true; v.crossOrigin='anonymous'; v.preload='auto';
            v.src=getSceneFolder(sc,slot)+fn;
            document.getElementById('vidPool').appendChild(v);
            vidEls[fn]=v;
        }
        const video=vidEls[fn];
        if (!isVideoReady(video)) {
            await new Promise(resolve=>{
                const chk=()=>isVideoReady(video)?resolve():setTimeout(chk,50);
                const t=setTimeout(resolve,2000);
                video.addEventListener('loadeddata',()=>{ clearTimeout(t);resolve(); },{once:true});
                chk(); video.load();
            });
        }
        S.nextType='video'; S.nextVid=video; S.nextImg=null;
        video.currentTime=0;
        if(video.paused&&isVideoReady(video)) video.play().catch(()=>{});
    } else if (fn&&imgCache[fn]) {
        S.nextType='image'; S.nextImg=imgCache[fn]; S.nextVid=null;
    } else if (fn) {
        const img=new Image(); img.crossOrigin='anonymous';
        await new Promise(resolve=>{
            img.onload=()=>{ imgCache[fn]=img; S.nextType='image'; S.nextImg=img; S.nextVid=null; resolve(); };
            img.onerror=resolve; setTimeout(resolve,2000);
            img.src=getImageSrc(fn,sc,slot);
        });
    }
}

// ── showScene ─────────────────────────────────────────────────────
async function showScene(index, instant) {
    if (index<0||index>=SCENES.length||S.isTransitioning) return;
    S.isTransitioning=true;
    const sc=SCENES[index];
    const {fn,isVideo,slot}=sceneMedia(sc);
    if (instant) {
        if (isVideo&&fn&&vidEls[fn]) {
            if(S.vidEl&&S.vidEl!==vidEls[fn]) S.vidEl.pause();
            S.type='video'; S.vidEl=vidEls[fn]; S.img=null; S.alpha=1; S.alphaOut=0; S.imgOut=null; S.txOffset=null;
            try{ vidEls[fn].currentTime=0; vidEls[fn].play(); }catch(e){}
        } else if (fn&&imgCache[fn]) {
            if(S.vidEl) S.vidEl.pause();
            S.type='image'; S.img=imgCache[fn]; S.vidEl=null; S.alpha=1; S.alphaOut=0; S.imgOut=null; S.txOffset=null;
            S.kbEffect=sceneKB[index]; S.kbStart=performance.now();
        } else { S.type='blank'; S.img=null; S.vidEl=null; S.alpha=1; }
        currentIndex=index;
        loadSceneCaptions(sc.id);
        applySceneSlots(sc);
        document.getElementById('sceneNum').textContent=(index+1)+' / '+SCENES.length;
        updateDots(index); updateNavButtons();
        S.isTransitioning=false;
        setTimeout(()=>prepareNextScene(index+1),100);
        return;
    }
    // Smooth transition
    let oldFrameImg=null;
    try {
        oldFrameImg=new Image(); const dataUrl=canvas.toDataURL('image/png');
        await new Promise(resolve=>{ oldFrameImg.onload=resolve; oldFrameImg.src=dataUrl; });
    } catch(e) { oldFrameImg=S.img; }
    if (isVideo&&fn&&vidEls[fn]) {
        const video=vidEls[fn];
        if(video.readyState<2) await new Promise(resolve=>{ const t=setTimeout(resolve,500); video.addEventListener('canplay',resolve,{once:true}); video.load(); });
        if(S.vidEl&&S.vidEl!==video) S.vidEl.pause();
        S.vidEl=video; S.type='video'; S.img=null; S.alpha=0;
        video.currentTime=0; try{ video.play(); }catch(e){}
    } else if (fn&&imgCache[fn]) {
        if(S.vidEl) S.vidEl.pause();
        S.type='image'; S.img=imgCache[fn]; S.vidEl=null; S.alpha=0;
        S.kbEffect=sceneKB[index]; S.kbStart=performance.now();
    } else { S.type='blank'; S.img=null; S.vidEl=null; S.alpha=0; }
    S.imgOut=oldFrameImg||S.imgOut; S.alphaOut=1; S.txOffset=null;
    drawFrame();
    await doTransition('fade',T_DUR);
    S.alpha=1; S.alphaOut=0; S.imgOut=null; S.txOffset=null;
    currentIndex=index;
    loadSceneCaptions(sc.id);
    applySceneSlots(sc);
    document.getElementById('sceneNum').textContent=(index+1)+' / '+SCENES.length;
    updateDots(index); updateNavButtons();
    S.isTransitioning=false;
    setTimeout(()=>prepareNextScene(index+1),100);
}

function updateDots(i) { document.querySelectorAll('.dot').forEach((d,j)=>d.className='dot'+(j===i?' active':'')); }
function navigate(dir) { if(!isPlaying&&!isRecording) showScene(currentIndex+dir,false); }
function updateNavButtons() {
    const busy=isPlaying||isRecording;
    const prev=document.getElementById('navPrev'), next=document.getElementById('navNext');
    if(prev) prev.disabled=busy||currentIndex===0;
    if(next) next.disabled=busy||currentIndex===SCENES.length-1;
}

// ── Preload all scenes ────────────────────────────────────────────
async function preloadAll() {
    // Enable slot checkboxes based on saved DB values for each scene
    // For preload, enable all slots that have any data across any scene
    const allSlots = new Set(['image_file']);
    SCENES.forEach(sc => {
        const saved = SCENE_SAVED_SLOTS[sc.id] || {};
        const colToSlot = { slot_main:'image_file', slot_1:'image_file_1', slot_2:'image_file_2', slot_3:'image_file_3', slot_4:'image_file_4' };
        for (const [col, slot] of Object.entries(colToSlot)) {
            if (saved[col] && (sc[slot]||'').trim()) allSlots.add(slot);
        }
    });
    const enabledSlots = [...allSlots];
    // Sync checkboxes
    SLOTS.forEach(k => {
        const chk=document.getElementById('slotChk_'+k);
        if(chk) chk.checked=enabledSlots.includes(k);
    });

    const allImgFiles=[], allVidFiles=[];
    SCENES.forEach(sc => {
        enabledSlots.forEach(slot => {
            const fn=(sc[slot]||'').trim(); if(!fn) return;
            if (/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) { if(!allVidFiles.includes(fn)) allVidFiles.push(fn); }
            else { if(!allImgFiles.includes(fn)) allImgFiles.push(fn); }
        });
    });

    const total=allImgFiles.length+allVidFiles.length;
    const bar=document.getElementById('preloadBar');
    const msg=document.getElementById('preloadMsg');
    let done=0;
    const tick=()=>{ done++; const pct=Math.round(done/total*100); if(bar)bar.style.setProperty('--pct',pct+'%'); if(msg)msg.textContent=`Loading ${pct}% (${done}/${total})`; };

    // Images
    await Promise.all(allImgFiles.map(fn=>new Promise(resolve=>{
        if(imgCache[fn]){tick();resolve();return;}
        const img=new Image(); img.crossOrigin='anonymous';
        const t=setTimeout(()=>{tick();resolve();},8000);
        img.onload=()=>{ clearTimeout(t); imgCache[fn]=img; tick(); resolve(); };
        img.onerror=()=>{ clearTimeout(t); tick(); resolve(); };
        img.src=getFileFolder(fn)+fn;
    })));

    // Videos
    await Promise.all(allVidFiles.map(fn=>new Promise(resolve=>{
        let video=vidEls[fn];
        if(!video){
            video=document.createElement('video');
            video.muted=true; video.loop=true; video.playsInline=true;
            video.crossOrigin='anonymous'; video.preload='auto';
            const sc=SCENES.find(s=>Object.values({image_file:s.image_file,image_file_1:s.image_file_1,image_file_2:s.image_file_2,image_file_3:s.image_file_3,image_file_4:s.image_file_4}).includes(fn));
            const slot=sc?Object.keys({image_file:sc.image_file,image_file_1:sc.image_file_1,image_file_2:sc.image_file_2,image_file_3:sc.image_file_3,image_file_4:sc.image_file_4}).find(k=>sc[k]===fn):'image_file';
            video.src=sc?getSceneFolder(sc,slot)+fn:IMG_BASE+fn;
            document.getElementById('vidPool').appendChild(video);
            vidEls[fn]=video;
        }
        if(video.readyState>=3){tick();resolve();return;}
        const t=setTimeout(()=>{ tick();resolve(); },10000);
        video.addEventListener('canplaythrough',()=>{ clearTimeout(t);tick();resolve(); },{once:true});
        video.addEventListener('error',()=>{ clearTimeout(t);tick();resolve(); },{once:true});
        video.load();
    })));

    L(`Preload done: ${done}/${total}`, done===total?'ok':'wrn');
}

// ── Audio ─────────────────────────────────────────────────────────
function playAudio(src) {
    return new Promise(res=>{
        if(currentAudio){currentAudio.pause();currentAudio=null;}
        const a=new Audio();
        currentAudio=a; a.volume=voiceVolume; a.playbackRate=currentPlaybackSpeed;
        a.src=src+'?t='+Date.now();
        a.onloadedmetadata=()=>{ a.playbackRate=currentPlaybackSpeed; };
        if(window._recDest) {
            try {
                const s=window._recActx.createMediaElementSource(a);
                s.connect(window._recDest); s.connect(window._recActx.destination);
                a.addEventListener('ended',()=>{ try{s.disconnect();}catch(_){} },{once:true});
            } catch(_){}
        }
        if(window._recActx&&window._recActx.state==='suspended') window._recActx.resume().catch(()=>{});
        let resolved=false;
        const done=()=>{ if(!resolved){resolved=true;res();} };
        a.onended=done; a.onerror=()=>sleep(200).then(done);
        a.play().catch(()=>sleep(200).then(done));
        setTimeout(done,600000);
    });
}

function playSceneAudio(sc, vidEl) {
    if(isTalkingHeadScene(sc)&&vidEl) {
        return new Promise(res=>{
            if(currentAudio){currentAudio.pause();currentAudio.volume=0;currentAudio=null;}
            vidEl.muted=false; vidEl.volume=1.0;
            vidEl.currentTime=0; vidEl.play().catch(()=>{});
            const cleanup=()=>{ vidEl.removeEventListener('ended',onEnded);vidEl.removeEventListener('error',onError);clearTimeout(guard);vidEl.muted=true;res(); };
            const onEnded=()=>cleanup(), onError=()=>cleanup();
            const guard=setTimeout(cleanup,300000);
            vidEl.addEventListener('ended',onEnded,{once:true});
            vidEl.addEventListener('error',onError,{once:true});
        });
    }
    const af=sc.audio_file;
    return af?playAudio(AUD_BASE+af):sleep((parseInt(sc.duration)||5)*1000);
}

// ── Video / Image sequence ────────────────────────────────────────
async function ensureVideoReady(fn) {
    if (!fn) return false;
    let video=vidEls[fn];
    if (!video) {
        video=document.createElement('video'); video.muted=true; video.loop=true;
        video.playsInline=true; video.crossOrigin='anonymous'; video.preload='auto';
        video.src=getFileFolder(fn)+fn;
        document.getElementById('vidPool').appendChild(video); vidEls[fn]=video;
    }
    if(video.readyState>=3) return true;
    return new Promise(resolve=>{
        const t=setTimeout(()=>resolve(false),5000);
        video.addEventListener('canplaythrough',()=>{clearTimeout(t);resolve(true);},{once:true});
        video.load();
    });
}

async function playVideoSequence(scene, videoSlots, audioPromise) {
    for (const vs of videoSlots) {
        if(!vidEls[vs]||vidEls[vs].readyState<2) await ensureVideoReady(vs);
    }
    let audioDone=false; audioPromise.then(()=>{audioDone=true;});
    let idx=0;
    while (!audioDone) {
        const fn=videoSlots[idx%videoSlots.length];
        const v=vidEls[fn];
        if (!v) {idx++;continue;}
        if(S.vidEl&&S.vidEl!==v) S.vidEl.pause();
        S.type='video'; S.vidEl=v; S.img=null; S.alpha=1; S.alphaOut=0; S.imgOut=null;
        v.currentTime=0; try{await v.play();}catch(e){}
        await new Promise(resolve=>{ let d=false; const finish=()=>{if(!d){d=true;resolve();}}; v.addEventListener('ended',finish,{once:true}); audioPromise.then(finish); });
        idx++;
    }
}

async function playImageSequence(scene, imageSlots, audioDurationMs, audioPromise) {
    const perImageMs=Math.max(1000,Math.floor(audioDurationMs/imageSlots.length));
    let audioDone=false; audioPromise.then(()=>{audioDone=true;});
    let idx=0;
    while (!audioDone) {
        const fn=imageSlots[idx%imageSlots.length];
        if(imgCache[fn]){
            if(S.vidEl)S.vidEl.pause();
            S.type='image'; S.img=imgCache[fn]; S.vidEl=null;
            S.alpha=1; S.alphaOut=0; S.imgOut=null;
            S.kbEffect=sceneKB[SCENES.indexOf(scene)]; S.kbStart=performance.now();
        }
        await new Promise(resolve=>{ let d=false; const finish=()=>{if(!d){d=true;resolve();}}; const t=setTimeout(finish,perImageMs); audioPromise.then(()=>{clearTimeout(t);finish();}); });
        idx++;
    }
}

async function playSceneWithDynamicSlots(scene, index) {
    const saved=SCENE_SAVED_SLOTS[scene.id]||{};
    const colToSlot={slot_main:'image_file',slot_1:'image_file_1',slot_2:'image_file_2',slot_3:'image_file_3',slot_4:'image_file_4'};
    const enabledSlots=[];
    for(const [col,slot] of Object.entries(colToSlot)) if(saved[col]&&(scene[slot]||'').trim()) enabledSlots.push(slot);
    if(!enabledSlots.length&&(scene.image_file||'').trim()) enabledSlots.push('image_file');

    const audioPromise=playSceneAudio(scene, isTalkingHeadScene(scene)?vidEls[(scene.image_file||'').trim()]:null);
    await showScene(index, false);
    loadSceneCaptions(scene.id);

    const videoSlots=enabledSlots.filter(s=>/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((scene[s]||'').trim())).map(s=>(scene[s]||'').trim());
    const imageSlots=enabledSlots.filter(s=>!(/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((scene[s]||'').trim()))&&(scene[s]||'').trim()).map(s=>(scene[s]||'').trim());

    if (videoSlots.length>0) {
        await Promise.all([playVideoSequence(scene,videoSlots,audioPromise), audioPromise]);
    } else if (imageSlots.length>0) {
        const audioDurationMs=await new Promise(resolve=>{
            const a=currentAudio;
            if(a&&isFinite(a.duration)&&a.duration>0){resolve(Math.ceil(a.duration*1000));return;}
            const fallback=(parseInt(scene.duration)||5)*1000;
            if(!a){resolve(fallback);return;}
            let done=false; const finish=ms=>{if(!done){done=true;resolve(ms);}};
            a.addEventListener('loadedmetadata',()=>finish(Math.ceil(a.duration*1000)),{once:true});
            setTimeout(()=>finish(fallback),3000);
        });
        await Promise.all([playImageSequence(scene,imageSlots,audioDurationMs,audioPromise), audioPromise]);
    } else {
        await audioPromise;
    }
}

// ── Play preview ──────────────────────────────────────────────────
async function togglePlay() {
    if (isPlaying) { stopPlay(); return; }
    const ov=document.getElementById('preloadOverlay');
    if(ov){ov.style.opacity='1';ov.classList.remove('gone');}
    const msg=document.getElementById('preloadMsg');
    if(msg) msg.textContent='Loading all media…';
    await preloadAll();
    await prepareNextScene(0);
    if(ov){ov.style.transition='opacity .4s';ov.style.opacity='0';setTimeout(()=>ov.classList.add('gone'),450);}
    await sleep(100);
    isPlaying=true; currentIndex=0;
    document.getElementById('playIco').textContent='⏹';
    document.getElementById('playLbl').textContent='Stop';
    document.getElementById('btnRec').disabled=true;
    if(bgAudio){bgAudio.currentTime=0;bgAudio.play().catch(()=>{});}
    for(let i=0;i<SCENES.length;i++){
        if(!isPlaying) break;
        await playSceneWithDynamicSlots(SCENES[i],i);
    }
    stopPlay();
}

function stopPlay() {
    isPlaying=false;
    if(currentAudio){currentAudio.pause();currentAudio=null;}
    if(S.vidEl) S.vidEl.pause();
    if(bgAudio) bgAudio.pause();
    document.getElementById('playIco').textContent='▶';
    document.getElementById('playLbl').textContent='Preview';
    document.getElementById('btnRec').disabled=false;
    updateNavButtons();
}

// ── Recording ─────────────────────────────────────────────────────
let mr=null, recChunks=[], recBlob=null;

async function startRecording() {
    // Queue the job
    try {
        const fd=new FormData();
        fd.append('ajax_action','queue_generate');
        fd.append('podcast_id',PODCAST_ID);
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(data.success) {
            notify(`✅ Queued at position #${data.position}`,`~${data.minutes} min wait`,'',4000);
        }
    } catch(e){ console.warn('Queue failed:',e); }

    // Preload
    const ov=document.getElementById('preloadOverlay');
    if(ov){ov.style.opacity='1';ov.classList.remove('gone');}
    const msg=document.getElementById('preloadMsg');
    if(msg) msg.textContent='Loading all media…';
    await preloadAll();
    if(ov){ov.style.transition='opacity .4s';ov.style.opacity='0';setTimeout(()=>ov.classList.add('gone'),450);}

    // Wait for render frames
    await new Promise(res=>{ const chk=()=>framesDrawn>=5?res():requestAnimationFrame(chk); chk(); });

    // Capture stream from HD canvas
    let stream;
    try { stream=canvasHD.captureStream(30); }
    catch(e){ L('captureStream: '+e.message,'err'); return; }

    // Audio context
    try {
        const actx=new (window.AudioContext||window.webkitAudioContext)();
        const dest=actx.createMediaStreamDestination();
        window._recActx=actx; window._recDest=dest;
        const osc=actx.createOscillator(), gain=actx.createGain();
        gain.gain.value=0; osc.connect(gain); gain.connect(dest); osc.start();
        Object.values(vidEls).forEach(v=>{ try{const s=actx.createMediaElementSource(v);s.connect(dest);s.connect(actx.destination);}catch(_){} });
        if(bgAudio){ try{const s=actx.createMediaElementSource(bgAudio);s.connect(dest);}catch(_){} }
        dest.stream.getAudioTracks().forEach(t=>stream.addTrack(t));
    } catch(e){ L('Audio ctx: '+e.message,'wrn'); }

    // MIME
    let MIME='video/webm;codecs=vp9,opus';
    if(MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) MIME='video/webm;codecs=vp8,opus';
    else if(MediaRecorder.isTypeSupported('video/webm')) MIME='video/webm';
    else if(MediaRecorder.isTypeSupported('video/mp4')) MIME='video/mp4';

    recChunks=[];
    try { mr=new MediaRecorder(stream,{mimeType:MIME,videoBitsPerSecond:4000000}); }
    catch(e){ L('MediaRecorder: '+e.message,'err'); return; }

    mr.ondataavailable=e=>{ if(e.data&&e.data.size>0){recChunks.push(e.data); document.getElementById('recSize').textContent=(recChunks.reduce((s,c)=>s+c.size,0)/1024/1024).toFixed(1)+' MB';} };
    mr.onstop=handleRecordingDone;

    mr.start(1000);
    isRecording=true;
    window._processingComplete=false;
    canvas.classList.add('recording');
    document.getElementById('recBar').classList.add('on');
    document.getElementById('btnPlay').disabled=true;
    document.getElementById('recLbl').textContent='Stop';
    updateNavButtons();
    L('● Recording started…','ok');

    if(bgAudio){bgAudio.currentTime=0;bgAudio.play().catch(()=>{});}

    currentIndex=0;
    for(let i=0;i<SCENES.length;i++){
        if(!isRecording) break;
        await playSceneWithDynamicSlots(SCENES[i],i);
    }
    stopRecording();
}

function stopRecording() {
    isRecording=false;
    if(bgAudio) bgAudio.pause();
    if(currentAudio){currentAudio.pause();currentAudio=null;}
    if(mr&&mr.state!=='inactive') mr.stop();
}

async function handleRecordingDone() {
    if(window._processingComplete) return;
    window._processingComplete=true;

    recBlob=new Blob(recChunks,{type:'video/webm'});
    const mb=(recBlob.size/1024/1024).toFixed(2);
    document.getElementById('recBar').classList.remove('on');
    canvas.classList.remove('recording');
    document.getElementById('btnPlay').disabled=false;
    document.getElementById('recLbl').textContent='Record & Save';
    window._recActx=null; window._recDest=null;
    updateNavButtons();
    L(`✅ Recording done — ${mb} MB`,'ok');

    const fname=`podcast_${PODCAST_ID}.webm`;
    _vsRecFname=fname;
    _vsRecURL=URL.createObjectURL(recBlob);

    // ── Upload to server ──────────────────────────────────────────
    const overlay=document.getElementById('uploadOverlay');
    const oTitle=document.getElementById('overlayTitle');
    const oDesc=document.getElementById('overlayDesc');
    const oTimer=document.getElementById('overlayTimer');
    if(overlay){oTitle.textContent='⬆ Uploading video…';oDesc.innerHTML='Sending to server. Please wait…';oTimer.textContent='';overlay.style.display='flex';}
    L('⬆ Uploading WebM…','inf');

    let uploadOk=false;
    try {
        const fd=new FormData();
        fd.append('ajax_action','save_published_video');
        fd.append('podcast_id',PODCAST_ID);
        fd.append('video',recBlob,fname);
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        uploadOk=data.success;
        if(!uploadOk) throw new Error(data.message||'Upload failed');
        L('✅ Uploaded to server','ok');
    } catch(e) {
        if(overlay) overlay.style.display='none';
        L('⚠ Upload failed: '+e.message,'err');
        notify('⚠ Upload failed',e.message,'error',0);
        showDownloadPanel({webm:true});
        return;
    }

    // ── Start MP4 conversion ──────────────────────────────────────
    if(oTitle) oTitle.textContent='🎬 Converting to MP4…';
    if(oDesc) oDesc.innerHTML='Your video is being processed.<br>This takes 1–3 minutes.';
    L('🎬 Starting MP4 conversion…','ok');

    let jobId=null;
    try {
        const fd=new FormData();
        fd.append('ajax_action','start_mp4_convert');
        fd.append('podcast_id',PODCAST_ID);
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(data.fallback||!data.success) throw new Error('fallback');
        jobId=data.job_id; if(!jobId) throw new Error('No job ID');
    } catch(e) {
        if(overlay) overlay.style.display='none';
        L('⚠ MP4 conversion unavailable — WebM ready','wrn');
        notify('⚠ MP4 conversion unavailable','WebM file is ready for download','warning',0);
        showDownloadPanel({webm:true});
        return;
    }

    // ── Poll for MP4 ──────────────────────────────────────────────
    let attempts=0; const maxAttempts=72; let elapsed=0;
    const timerTick=setInterval(()=>{ elapsed++; if(oTimer) oTimer.textContent=`⏱ ${elapsed}s elapsed`; },1000);

    const poll=setInterval(async()=>{
        attempts++;
        try {
            const fd=new FormData();
            fd.append('ajax_action','poll_mp4_convert');
            fd.append('job_id',jobId);
            fd.append('podcast_id',PODCAST_ID);
            const r=await fetch(location.href,{method:'POST',body:fd});
            const data=await r.json();
            if(data.status==='done'){
                clearInterval(poll); clearInterval(timerTick);
                if(overlay) overlay.style.display='none';
                L('✅ MP4 ready!','ok');
                // ── NOTIFICATION ──
                notify('🎉 Video ready!',`MP4 converted (${data.mp4_size_mb||'?'} MB)`,'success',0);
                showDownloadPanel({mp4:true, filename:data.filename||('podcast_'+PODCAST_ID+'.mp4'), mb:data.mp4_size_mb});
            }
            if(data.status==='failed'||data.status==='error'){
                clearInterval(poll); clearInterval(timerTick);
                if(overlay) overlay.style.display='none';
                L('⚠ MP4 conversion failed','wrn');
                notify('⚠ Conversion failed','WebM is available for download','warning',0);
                showDownloadPanel({webm:true});
            }
            if(attempts>=maxAttempts){
                clearInterval(poll); clearInterval(timerTick);
                if(overlay) overlay.style.display='none';
                notify('⚠ Conversion timeout','WebM is available for download','warning',0);
                showDownloadPanel({webm:true});
            }
        } catch(e){ console.warn('Poll error:',e); }
    },5000);
}

// ── Download panel ────────────────────────────────────────────────
function showDownloadPanel({mp4=false, webm=false, filename='', mb=''}) {
    const dlPanel=document.getElementById('dlPanel');
    if (mp4) {
        const mp4Url='published_videos/'+(filename||('podcast_'+PODCAST_ID+'.mp4'));
        dlPanel.innerHTML=`
            <h3>✅ MP4 Ready!</h3>
            <p>Your video has been converted and saved${mb?' ('+mb+' MB)':''}.</p>
            <a class="dl-btn dl-btn-mp4" href="${mp4Url}" download="${filename||('podcast_'+PODCAST_ID+'.mp4')}">⬇ Download MP4</a>
            <button class="dl-btn dl-btn-close" onclick="document.getElementById('dlPanel').classList.remove('on')">✕ Close</button>`;
    } else {
        const webmUrl='published_videos/podcast_'+PODCAST_ID+'.webm';
        dlPanel.innerHTML=`
            <h3>⚠️ WebM Only</h3>
            <p>MP4 conversion unavailable. Use VLC or Chrome to play WebM.</p>
            <a class="dl-btn dl-btn-webm" href="${webmUrl}" download="podcast_${PODCAST_ID}.webm">⬇ Download WebM</a>
            <button class="dl-btn dl-btn-close" onclick="document.getElementById('dlPanel').classList.remove('on')">✕ Close</button>`;
    }
    dlPanel.classList.add('on');
}

// ── Boot ──────────────────────────────────────────────────────────
(async function boot() {
    startRender();
    applySceneSlots(SCENES[0]);

    const ov=document.getElementById('preloadOverlay');
    if(ov){ov.classList.remove('gone');ov.style.opacity='1';}
    const msg=document.getElementById('preloadMsg');
    if(msg) msg.textContent='Loading first scene…';

    await prepareNextScene(0);
    await showScene(0,true);

    if(ov){ov.style.transition='opacity .5s ease';ov.style.opacity='0';setTimeout(()=>ov.classList.add('gone'),550);}
    updateNavButtons();
})();
</script>
</body>
</html>

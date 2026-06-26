<?php
// videomaker2.php — v1.0 (new base — canvas + scene navigator)
ob_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
include 'dbconnect_hdb.php';

// ── VPS / API config (preserved from original) ───────────────────────────────
// $VPS_URL    = 'http://187.124.249.46/videovizard.com/vps_stitch.php';
$VPS_URL    = 'vps_stitch.php';
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
require_once 'generate_image_api.php';
require_once 'chatgpt_functions.php';

$podcast_id = (int)($_GET['podcast_id'] ?? $_POST['podcast_id'] ?? 0);
if (!$podcast_id) die('No podcast_id');

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
if (!$row) die('Podcast #'.$podcast_id.' not found');

$podcast_title = $row['title']      ?? '';
$podcast_music = $row['music_file'] ?? '';
$lang_code     = $row['lang_code']  ?? 'en';
$video_type    = $row['video_type'] ?? 'standard';

$img_folder  = trim(trim($row['image_folder'] ?? ''), '/') ?: 'podcast_images';
$audio_speed = isset($row['audio_speed']) && $row['audio_speed'] > 0
               ? (float)$row['audio_speed'] : 1.0;

$host_voice_id  = $row['host_voice_id']  ?? $row['host_voice']   ?? $row['voice_id']      ?? '';
$guest_voice_id = $row['guest_voice_id'] ?? $row['guest_voice']  ?? $row['voice_id_guest'] ?? '';

// ── Auto-create music_volume / voice_volume columns if missing ────────────────
foreach (['music_volume' => 'DECIMAL(4,2) NOT NULL DEFAULT 0.30',
          'voice_volume' => 'DECIMAL(4,2) NOT NULL DEFAULT 1.00'] as $col => $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN $col $def");
        // Re-fetch row so we get the new default value
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    }
}
$music_volume = isset($row['music_volume']) ? (float)$row['music_volume'] : 0.30;
$voice_volume = isset($row['voice_volume']) ? (float)$row['voice_volume'] : 1.00;
// Normalize: if stored as percentage (>2.0), convert to decimal
if ($music_volume > 2.0) $music_volume = $music_volume / 100.0;
if ($voice_volume > 2.0) $voice_volume = $voice_volume / 100.0;

// ── User role + credit balance ────────────────────────────────────────────────
$user_row     = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, credit_balance, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$user_role         = $user_row['role']           ?? 'team_lead';
$credit_balance    = (float)($user_row['credit_balance'] ?? 0);
$team_lead_id      = (int)($user_row['team_lead_id']     ?? 0);
// If this user is a team_member, load team lead's balance for deduction
$billing_user_id   = ($user_role === 'team_member' && $team_lead_id) ? $team_lead_id : $admin_id;
if ($billing_user_id !== $admin_id) {
    $lead_row      = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT credit_balance FROM hdb_users WHERE id=$billing_user_id LIMIT 1"));
    $credit_balance = (float)($lead_row['credit_balance'] ?? 0);
}

// ── fal.ai video generation AJAX handlers ────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fal_generate_video') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_OFF);
    $scene_id       = (int)($_POST['scene_id']   ?? 0);
    $podcast_id_fal = (int)($_POST['podcast_id'] ?? 0);
    $prompt         = trim($_POST['prompt']      ?? '');
    if (!$prompt) { echo json_encode(['success'=>false,'message'=>'No prompt']); exit; }
    ob_start(); if (!isset($falApiKey)) { @require_once 'config.php'; } ob_end_clean();
    while (ob_get_level()) ob_end_clean();
    if (empty($falApiKey)) { echo json_encode(['success'=>false,'message'=>'fal.ai API key not configured']); exit; }
    $payload = json_encode(['prompt'=>$prompt,'duration'=>6,'resolution'=>'1080p','aspect_ratio'=>'9:16','fps'=>25,'generate_audio'=>false]);
    $ch = curl_init('https://queue.fal.run/fal-ai/ltx-2.3/text-to-video');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json',"Authorization: Key {$falApiKey}"],
        CURLOPT_POSTFIELDS=>$payload]);
    $resp=curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); $curlErr=curl_error($ch); curl_close($ch);
    if ($curlErr||$httpCode!==200) {
        $body=json_decode($resp,true); $msg=$body['detail']??$body['error']??"HTTP $httpCode $curlErr";
        echo json_encode(['success'=>false,'message'=>"fal.ai submit failed: $msg"]); exit;
    }
    $result=json_decode($resp,true); $request_id=$result['request_id']??'';
    if (!$request_id) { echo json_encode(['success'=>false,'message'=>'No request_id: '.substr($resp,0,200)]); exit; }
    echo json_encode(['success'=>true,'request_id'=>$request_id]);
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fal_poll_video') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_OFF); // prevent fatal exceptions from bubbling up
    $request_id      = trim($_POST['request_id'] ?? '');
    $scene_id        = (int)($_POST['scene_id']   ?? 0);
    $podcast_id_poll = (int)($_POST['podcast_id'] ?? 0);
    if (!$request_id) { echo json_encode(['status'=>'error','message'=>'No request_id']); exit; }
    ob_start(); if (!isset($falApiKey)) { @require_once 'config.php'; } ob_end_clean();
    while (ob_get_level()) ob_end_clean();
    if (empty($falApiKey)) { echo json_encode(['status'=>'error','message'=>'fal.ai API key not configured']); exit; }
    $ch=curl_init("https://queue.fal.run/fal-ai/ltx-2.3/requests/{$request_id}/status");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>["Authorization: Key {$falApiKey}"]]);
    $resp=curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($httpCode===202) {
        $s=json_decode($resp,true);
        echo json_encode(['status'=>'pending','fal_status'=>strtoupper($s['status']??'IN_PROGRESS')]); exit;
    }
    if ($httpCode!==200) { echo json_encode(['status'=>'error','message'=>"Status check failed HTTP $httpCode: ".substr((string)$resp,0,200)]); exit; }
    $status=json_decode($resp,true); $state=strtoupper($status['status']??'');
    if ($state==='FAILED'||$state==='ERROR') { echo json_encode(['status'=>'error','message'=>$status['error']??$status['detail']??'Generation failed']); exit; }
    if ($state!=='COMPLETED') { echo json_encode(['status'=>'pending','fal_status'=>$state]); exit; }
    // Fetch result
    $ch2=curl_init("https://queue.fal.run/fal-ai/ltx-2.3/requests/{$request_id}");
    curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>["Authorization: Key {$falApiKey}"]]);
    $resp2=curl_exec($ch2); curl_close($ch2);
    $result2=json_decode($resp2,true);
    $video_url=$result2['video']['url']??$result2['output']['video']['url']??$result2['output']['video_url']??$result2['video_url']??'';
    if (!$video_url) { echo json_encode(['status'=>'error','message'=>'No video URL: '.substr($resp2,0,400)]); exit; }
    // Download and save with absolute path
    $vid_folder_abs=__DIR__.'/podcast_videos';
    if (!is_dir($vid_folder_abs)) mkdir($vid_folder_abs,0755,true);
    $filename='scene_'.$podcast_id_poll.'_'.$scene_id.'_'.time().'.mp4';
    $save_path=$vid_folder_abs.'/'.$filename;
    $dl=curl_init($video_url);
    curl_setopt_array($dl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>true]);
    $video_data=curl_exec($dl); $dl_http=curl_getinfo($dl,CURLINFO_HTTP_CODE); curl_close($dl);
    if ($dl_http!==200||!$video_data||strlen($video_data)<1000) {
        echo json_encode(['status'=>'error','message'=>"Video download failed HTTP $dl_http size=".strlen((string)$video_data)]); exit;
    }
    if (file_put_contents($save_path,$video_data)===false) {
        echo json_encode(['status'=>'error','message'=>'Could not save video — check permissions on '.$vid_folder_abs]); exit;
    }
    // Update DB — wrap everything in try/catch so exceptions never crash the response
    $esc_file = mysqli_real_escape_string($conn, $filename);
    try {
        $cols_q    = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories LIKE 'image_folder'");
        $col_exists= $cols_q && mysqli_num_rows($cols_q) > 0;
        $upd_sql   = $col_exists
            ? "UPDATE hdb_podcast_stories SET image_file='$esc_file',image_folder='podcast_videos' WHERE id=$scene_id"
            : "UPDATE hdb_podcast_stories SET image_file='$esc_file' WHERE id=$scene_id";
        mysqli_query($conn, $upd_sql);

        $pod_q  = mysqli_query($conn, "SELECT ai_group,ai_subgroup,niche FROM hdb_podcasts WHERE id=$podcast_id_poll LIMIT 1");
        $pod    = ($pod_q ? mysqli_fetch_assoc($pod_q) : null) ?: [];
        $ag     = mysqli_real_escape_string($conn, $pod['ai_group']    ?? '');
        $asg    = mysqli_real_escape_string($conn, $pod['ai_subgroup'] ?? '');
        $nv     = mysqli_real_escape_string($conn, $pod['niche']       ?? '');
        $nl     = mysqli_real_escape_string($conn, trim(implode('|', array_filter([$pod['ai_group']??'',$pod['ai_subgroup']??'',$pod['niche']??'']))));
        $now_dt = date('Y-m-d H:i:s');
        mysqli_query($conn, "INSERT IGNORE INTO hdb_image_data
            (image_name,image_hashtags,niches,media_type,media_format,natural_language_tags,ai_group,ai_subgroup,niche,
             skip_embedding,tag_flag,status,created_at,updated_at,image_description,description,file_size,resize_flag,thumbnail,master_industry)
            VALUES ('$esc_file','$ag','$nv','video','mp4','$nl','$ag','$asg','$nv',1,0,'active','$now_dt','$now_dt','','',0,0,'','$ag')");
    } catch (Throwable $e) {
        error_log("fal_poll_video DB error: " . $e->getMessage() . " | scene_id=$scene_id");
        // Continue — video is saved on disk, return done to JS regardless
    }
    echo json_encode(['status'=>'done','filename'=>$filename,'folder'=>'podcast_videos','url'=>$video_url]);
    exit;
}

// ── Save video thumbnail (frame grab from client canvas) ──────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_video_thumbnail') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $scene_id_t   = (int)($_POST['scene_id']   ?? 0);
    $podcast_id_t = (int)($_POST['podcast_id'] ?? $podcast_id ?? 0);
    $is_scene1    = (int)($_POST['is_scene1']  ?? 0);
    $filename_t   = trim($_POST['filename']    ?? '');
    if (empty($_POST['image_data'])) { echo json_encode(['success'=>false,'message'=>'No image_data']); exit; }
    $data_url=$_POST['image_data'];
    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/',$data_url)) {
        echo json_encode(['success'=>false,'message'=>'Invalid image_data']); exit;
    }
    $base64=preg_replace('/^data:image\/[a-z]+;base64,/','',$data_url);
    $img_bin=base64_decode($base64);
    if (!$img_bin||strlen($img_bin)<100) { echo json_encode(['success'=>false,'message'=>'Empty image']); exit; }
    $thumb_dir=__DIR__.'/podcast_thumbnails/';
    if (!is_dir($thumb_dir)) mkdir($thumb_dir,0755,true);
    $thumb_name=preg_replace('/\.[^.]+$/','_thumb.jpg',basename($filename_t));
    if (!$thumb_name||$thumb_name===$filename_t) $thumb_name='scene_'.$podcast_id_t.'_'.$scene_id_t.'_thumb.jpg';
    if (file_put_contents($thumb_dir.$thumb_name,$img_bin)===false) {
        echo json_encode(['success'=>false,'message'=>'Could not save thumbnail']); exit;
    }
    $esc_thumb=mysqli_real_escape_string($conn,$thumb_name);
    if ($is_scene1&&$podcast_id_t) {
        mysqli_query($conn,"UPDATE hdb_podcasts SET thumbnail='$esc_thumb',updated_at=NOW() WHERE id=$podcast_id_t");
    }
    echo json_encode(['success'=>true,'thumbnail'=>$thumb_name,'folder'=>'podcast_thumbnails']);
    exit;
}

// ── Handle save_podcast_volumes before delegating to videomaker_ajax.php ─────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_podcast_volumes') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $mv  = (float)($_POST['music_volume'] ?? 0.30);
    $vv  = (float)($_POST['voice_volume'] ?? 1.00);
    // Normalize: if sent as percentage convert to decimal
    if ($mv > 2.0) $mv = $mv / 100.0;
    if ($vv > 2.0) $vv = $vv / 100.0;
    $mv  = max(0, min(1, $mv));
    $vv  = max(0, min(1, $vv));
    $pid = (int)($_POST['podcast_id'] ?? $podcast_id);
    mysqli_query($conn, "UPDATE hdb_podcasts SET music_volume=$mv, voice_volume=$vv WHERE id=$pid LIMIT 1");
    echo json_encode(['success' => true, 'music_volume' => $mv, 'voice_volume' => $vv]);
    exit;
}

// ── Credit deduction (must come before other AJAX handlers) ─────────────────
require_once 'deduct_credit.php';

// ── AJAX handler (must come before page render) ───────────────────────────────
require_once 'videomaker_ajax.php';

// ── Load scenes (only for full page render) ───────────────────────────────────
if (isset($_POST['ajax_action'])) exit; // videomaker_ajax.php already handled it

$scenes = [];
$q = mysqli_query($conn,
    "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
while ($r = mysqli_fetch_assoc($q)) $scenes[] = $r;
if (!$scenes) die('No scenes found for podcast #'.$podcast_id);
$scenes_json = json_encode($scenes);

// ── Load captions ─────────────────────────────────────────────────────────────
$all_captions = [];
$scene_ids    = implode(',', array_map(fn($s)=>(int)$s['id'], $scenes));
$cq = mysqli_query($conn,
    "SELECT * FROM hdb_captions WHERE podcast_id=$podcast_id AND story_id IN ($scene_ids) ORDER BY z_index ASC, id ASC");
while ($cr = mysqli_fetch_assoc($cq)) $all_captions[] = $cr;
$all_captions_json = json_encode($all_captions);

define('CW', 360);
define('CH', 640);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — <?= htmlspecialchars($podcast_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Reset & base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #e8edf2;
    --surface:  #ffffff;
    --surface2: #f1f5f9;
    --border:   #cbd5e1;
    --primary:  #1d4ed8;
    --primary2: #3b82f6;
    --text:     #0f172a;
    --muted:    #64748b;
    --success:  #10b981;
    --danger:   #ef4444;
    --info:     #0284c7;
    --shadow:   0 4px 24px rgba(0,0,0,.12);
}

html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.5;
}

/* ── Header ── */
.vv-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    background: #ffffff;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

.vv-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    font-weight: 800;
    font-size: 16px;
    color: var(--text);
}
.vv-brand span.name span { color: var(--primary2); }
.vv-back {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--muted);
    border-radius: 8px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
}
.vv-back:hover { background: var(--surface2); color: var(--text); }

/* ── Top action bar (Review / Generate) ── */
.action-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 10px 16px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.action-bar .podcast-title {
    flex: 1;
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 260px;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 20px;
    border-radius: 10px;
    border: none;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    letter-spacing: .02em;
    white-space: nowrap;
}
.btn-review {
    background: var(--surface2);
    border: 1.5px solid var(--border);
    color: var(--text);
}
.btn-review:hover { background: var(--border); }

.btn-generate {
    background: linear-gradient(135deg, #1d4ed8, #7c3aed);
    color: #fff;
    box-shadow: 0 2px 12px rgba(29,78,216,.4);
}
.btn-generate:hover { opacity: .9; transform: translateY(-1px); }

/* ── Main workspace ── */
.workspace {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 24px 16px;
    min-height: calc(100vh - 97px);
}

/* ── Canvas column ── */
.canvas-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

/* ── Player wrap (canvas + overlay) ── */
#playerWrap {
    position: relative;
    width: <?= CW ?>px;
    height: <?= CH ?>px;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 0 0 1.5px var(--border), 0 8px 40px rgba(15,42,68,.16);
    background: #000;
    flex-shrink: 0;
    transform: scale(0.7);
    transform-origin: top center;
    margin-bottom: calc(<?= CH ?>px * -0.305);
}

#screen {
    display: block;
    width: <?= CW ?>px;
    height: <?= CH ?>px;
}

/* hidden video used to render scene into canvas */
#sceneVideo {
    display: none;
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

/* overlay shown while loading */
#loadOverlay {
    position: absolute;
    inset: 0;
    background: rgba(15,23,42,.85);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    border-radius: 16px;
}
#loadOverlay .spinner {
    width: 36px; height: 36px;
    border: 3px solid rgba(255,255,255,.15);
    border-top-color: var(--primary2);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
#loadOverlay p { font-size: 12px; color: var(--muted); }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Navigation bar (arrows + counter) ── */
.scene-nav {
    width: <?= CW ?>px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 8px 14px;
}

.nav-arrow {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: var(--primary);
    border: none;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    flex-shrink: 0;
}
.nav-arrow:hover { background: var(--primary2); transform: scale(1.08); }
.nav-arrow:disabled { background: var(--border); opacity: .5; cursor: default; transform: none; }

.nav-counter {
    flex: 1;
    text-align: center;
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.nav-play-btn {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1d4ed8, #7c3aed);
    border: none;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    box-shadow: 0 2px 8px rgba(29,78,216,.4);
    flex-shrink: 0;
}
.nav-play-btn:hover { opacity: .88; transform: scale(1.1); }
.nav-play-btn.playing { background: linear-gradient(135deg, #7c3aed, #db2777); }

/* ── Scene action icons ── */
.scene-icons {
    width: <?= CW ?>px;
    display: flex;
    gap: 8px;
}
.scene-icon-btn {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px 4px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    background: var(--surface);
    color: var(--text);
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    letter-spacing: .03em;
}
.scene-icon-btn:hover { background: var(--surface2); border-color: var(--primary2); color: var(--primary); }
.scene-icon-btn .icon-ico { font-size: 20px; line-height: 1; }

/* ── Dot row ── */
#dotRow {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    justify-content: center;
    max-width: <?= CW ?>px;
}
.dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--border);
    transition: all .2s;
    cursor: pointer;
}
.dot.active { background: var(--primary2); transform: scale(1.3); }
.dot.has-media { background: var(--success); }
.dot.has-media.active { background: var(--primary2); }
/* ── Caption sub-icons ── */
.cap-sub-icons { margin-top: -4px; }
.scene-icon-btn.sub { background: var(--surface2); font-size: 9px; padding: 7px 4px; }
.scene-icon-btn.sub .icon-ico { font-size: 16px; }
.scene-icon-btn.sub.active { background: #dbeafe; border-color: var(--primary); color: var(--primary); }

/* ── Caption overlays ── */
.cap-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9000;
    align-items: flex-end;
    justify-content: center;
    padding-bottom: 0;
}
.cap-overlay.open { display: flex; }

.cap-ov-panel {
    background: var(--surface);
    border-radius: 16px 16px 0 0;
    width: 100%;
    max-width: 480px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 -8px 40px rgba(0,0,0,.2);
    animation: slideUp .22s cubic-bezier(.16,1,.3,1);
}
@keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }

.cap-ov-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 12px;
    background: var(--primary);
    border-radius: 16px 16px 0 0;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.cap-ov-close {
    width: 28px; height: 28px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,.2);
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.cap-ov-body {
    overflow-y: auto;
    flex: 1;
    padding: 0;
}
.cap-ov-section {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}
.cap-ov-section:last-child { border-bottom: none; }
.cap-section-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--primary);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.cap-field-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    flex-wrap: wrap;
    gap: 6px;
}
.cap-field-label {
    font-size: 9px;
    color: var(--muted);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 3px;
}
.cap-textarea {
    width: 100%;
    padding: 9px 11px;
    border-radius: 9px;
    border: 1.5px solid var(--border);
    font-size: 12px;
    font-family: inherit;
    resize: vertical;
    outline: none;
    background: var(--surface2);
    color: var(--text);
    line-height: 1.5;
}
.cap-tabs-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 12px 8px;
    background: #2d1b4e;
    flex-wrap: wrap;
}
.cap-tabs {
    display: flex;
    gap: 4px;
    flex: 1;
    flex-wrap: wrap;
    min-width: 0;
}
.cap-tab {
    padding: 4px 10px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,255,255,.25);
    background: rgba(255,255,255,.08);
    color: rgba(255,255,255,.8);
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
}
.cap-tab.active { background: var(--primary2); border-color: var(--primary2); color: #fff; }
.cap-add-btn {
    padding: 4px 10px;
    border-radius: 20px;
    border: none;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
.cap-add-btn.green  { background: #10b981; color: #fff; }
.cap-add-btn.purple { background: #7c3aed; color: #fff; }
.cap-nosel {
    padding: 28px 20px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}
.cap-vis-btn {
    padding: 4px 12px;
    border-radius: 20px;
    border: none;
    cursor: pointer;
    font-size: 10px;
    font-weight: 700;
    background: var(--success);
    color: #fff;
}
.cap-delete-btn {
    width: 100%;
    background: transparent;
    color: var(--danger);
    border: 1.5px solid var(--danger);
    border-radius: 9px;
    padding: 8px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 700;
}
.pos-cell {
    width: 32px; height: 32px;
    border-radius: 7px;
    border: 1.5px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .12s;
}
.pos-cell:hover { background: var(--border); }
.tog {
    padding: 5px 8px;
    border-radius: 7px;
    border: 1.5px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    font-size: 11px;
    cursor: pointer;
    transition: all .12s;
}
.tog.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.cap-num-input {
    width: 100%;
    padding: 5px 6px;
    border-radius: 7px;
    border: 1.5px solid var(--border);
    background: var(--surface2);
    font-size: 11px;
    font-family: inherit;
    outline: none;
    color: var(--text);
}
.fp-opt { padding:9px 14px;font-size:15px;cursor:pointer;color:var(--text);transition:background .1s;border-bottom:1px solid var(--border); }
.fp-opt:last-child { border-bottom:none; }
.fp-opt:hover { background:#eff6ff;color:var(--info); }
.fp-opt.selected { background:#dbeafe;color:var(--info);font-weight:700; }
.fp-hdr { padding:6px 10px;background:var(--surface2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em; }
</style>
</head>
<body>

<!-- ══ HEADER ══ -->
<div class="vv-header">
    <a class="vv-brand" href="vizard_browser.php">
        <span>🎬</span>
        <span class="name">Video<span>Vizard</span></span>
    </a>
    <button class="vv-back" onclick="window.location.href='vizard_browser.php'">← Back</button>
</div>

<!-- ══ ACTION BAR ══ -->
<div class="action-bar">
    <span class="podcast-title">🎙 <?= htmlspecialchars($podcast_title) ?></span>
    <button class="btn-action btn-review"   id="btnReview"   onclick="onReview()">👁 Review</button>
    <button class="btn-action btn-generate" id="btnGenerate" onclick="onGenerate()">⚡ Generate</button>
</div>

<!-- ══ WORKSPACE ══ -->
<div class="workspace">
    <div class="canvas-col">

        <!-- Canvas player -->
        <div id="playerWrap">
            <canvas id="screen" width="<?= CW ?>" height="<?= CH ?>"></canvas>
            <!-- Hidden video element for playing scene videos into canvas -->
            <video id="sceneVideo" muted playsinline webkit-playsinline></video>
            <!-- Loading overlay -->
            <div id="loadOverlay">
                <div class="spinner"></div>
                <p id="loadMsg">Loading scene…</p>
            </div>
        </div>

        <!-- Navigation arrows -->
        <div class="scene-nav">
            <button class="nav-arrow" id="navPrev" onclick="navigate(-1)">←</button>
            <span class="nav-counter" id="navCounter">
                <span id="navCounterText">1 / <?= count($scenes) ?></span>
                <button class="nav-play-btn" id="navPlayBtn" onclick="toggleScenePlay()" title="Play / Pause">▶</button>
            </span>
            <button class="nav-arrow" id="navNext" onclick="navigate(1)">→</button>
        </div>

        <!-- Scene action icons -->
        <div class="scene-icons">
            <button class="scene-icon-btn" id="ibCaption" onclick="onCaption()" title="Caption">
                <span class="icon-ico">🅰️</span>
                <span>Caption</span>
            </button>
            <button class="scene-icon-btn" id="ibFont" onclick="onFont()" title="Font">
                <span class="icon-ico">🔤</span>
                <span>Font</span>
            </button>
            <button class="scene-icon-btn" id="ibMedia" onclick="onMedia()" title="Media">
                <span class="icon-ico">🌄</span>
                <span>Media</span>
            </button>
            <button class="scene-icon-btn" id="ibAudio" onclick="onAudio()" title="Audio">
                <span class="icon-ico">🔊</span>
                <span>Audio</span>
            </button>
        </div>

        <!-- Caption sub-icons (shown when Caption is active) -->
        <div class="scene-icons cap-sub-icons" id="capSubIcons" style="display:none;">
            <button class="scene-icon-btn sub active" onclick="openCapOverlay('text')" id="capSubText">
                <span class="icon-ico">✏️</span><span>Text &amp; Delete</span>
            </button>
            <button class="scene-icon-btn sub" onclick="openCapOverlay('bg')" id="capSubBg">
                <span class="icon-ico">🎨</span><span>Box &amp; BG</span>
            </button>
            <button class="scene-icon-btn sub" onclick="openCapOverlay('pos')" id="capSubPos">
                <span class="icon-ico">📐</span><span>Position</span>
            </button>
        </div>

        <!-- Font sub-icons (shown when Font is active) -->
        <div class="scene-icons cap-sub-icons" id="fontSubIcons" style="display:none;">
            <button class="scene-icon-btn sub active" onclick="openFontOverlay('font')" id="fontSubFont">
                <span class="icon-ico">🔤</span><span>Font</span>
            </button>
            <button class="scene-icon-btn sub" onclick="openFontOverlay('style')" id="fontSubStyle">
                <span class="icon-ico">✨</span><span>Style &amp; FX</span>
            </button>
            <button class="scene-icon-btn sub" onclick="openFontOverlay('align')" id="fontSubAlign">
                <span class="icon-ico">↔️</span><span>Alignment</span>
            </button>
        </div>

        <!-- Audio sub-icons (shown when Audio is active) -->
        <div class="scene-icons cap-sub-icons" id="audioSubIcons" style="display:none;">
            <button class="scene-icon-btn sub active" onclick="openAudioOverlay('voice')" id="audioSubVoice">
                <span class="icon-ico">🎙️</span><span>Voice</span>
            </button>
            <button class="scene-icon-btn sub" onclick="openAudioOverlay('music')" id="audioSubMusic">
                <span class="icon-ico">🎵</span><span>Music</span>
            </button>
        </div>

    </div><!-- .canvas-col -->
</div><!-- .workspace -->

<!-- ══ OVERLAY 1: Text & Add/Delete ══ -->
<div class="cap-overlay" id="capOvText">
    <div class="cap-ov-panel">
        <div class="cap-ov-head">
            <span>✏️ Caption Text &amp; Add/Delete</span>
            <button class="cap-ov-close" onclick="closeCapOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <!-- Caption tabs -->
            <div class="cap-tabs-row">
                <div id="captionTabs" class="cap-tabs"></div>
                <div style="display:flex;gap:5px;flex-shrink:0;">
                    <button class="cap-add-btn green" onclick="addCaption()">+ Text</button>
                    <button class="cap-add-btn purple" onclick="addImageCaption()">🖼 Logo</button>
                </div>
            </div>
            <!-- No selection notice -->
            <div id="captionNoSel" class="cap-nosel">👆 Select a caption tab above</div>
            <!-- Editor -->
            <div id="captionEditor" style="display:none;">
                <div class="cap-ov-section">
                    <div class="cap-field-row">
                        <span class="cap-section-label">Caption Text</span>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <label id="capGlobalWrap" style="display:none;align-items:center;gap:4px;cursor:pointer;background:#eff6ff;border:1.5px solid var(--info);border-radius:20px;padding:3px 8px;font-size:10px;font-weight:700;color:var(--info);">
                                <input type="checkbox" id="capGlobalChk" checked style="width:12px;height:12px;accent-color:var(--info);" onchange="_applyToAllScenes=this.checked;_updateGlobalLabel();">
                                🌐 All Scenes
                            </label>
                            <button id="capVisBtn" onclick="toggleCapVisible()" class="cap-vis-btn">👁 Visible</button>
                        </div>
                    </div>
                    <textarea id="capText" class="cap-textarea" rows="3" placeholder="Type caption text…"
                        oninput="_capTextLiveUpdate(this.value)"></textarea>
                    <div id="capAudioStatus" style="display:none;margin-top:6px;font-size:11px;font-weight:600;color:var(--info);padding:5px 8px;background:#eff6ff;border-radius:7px;"></div>
                    <button onclick="saveMainCaptionText()" id="capSaveBtn"
                        style="width:100%;margin-top:8px;padding:10px;border-radius:9px;border:none;
                               background:var(--success);color:#fff;font-size:12px;font-weight:700;
                               cursor:pointer;font-family:inherit;letter-spacing:.02em;">
                        💾 Save &amp; Generate Audio
                    </button>
                </div>
                <div class="cap-ov-section" id="capDeleteWrap" style="display:none;">
                    <button onclick="deleteCaption()" class="cap-delete-btn">🗑 Delete this Caption</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ OVERLAY 2: Box Background & Border ══ -->
<div class="cap-overlay" id="capOvBg">
    <div class="cap-ov-panel">
        <div class="cap-ov-head">
            <span>🎨 Box Background &amp; Border</span>
            <button class="cap-ov-close" onclick="closeCapOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div id="capBgNoSel" class="cap-nosel">👆 Select a caption first</div>
            <div id="capBgEditor" style="display:none;">
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:10px;">Background</div>
                    <div style="display:flex;gap:10px;align-items:flex-end;">
                        <div>
                            <div class="cap-field-label">
                                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="checkbox" id="capBgEnabled" style="width:13px;height:13px;accent-color:var(--info);" onchange="toggleBgEnabled(this.checked)">
                                    <span id="capBgEnableLabel" style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;">Box BG</span>
                                </label>
                            </div>
                            <input type="color" id="capBgColor" value="#000000"
                                oninput="capFieldChanged('bg_color',this.value)"
                                onchange="capFieldChanged('bg_color',this.value)"
                                style="padding:2px 3px;height:32px;width:52px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;">
                        </div>
                        <div style="flex:1;">
                            <div class="cap-field-label">BG Opacity <span id="capBgAlphaVal" style="color:var(--info);">70%</span></div>
                            <input type="range" id="capBgAlpha" min="0" max="100" value="70"
                                style="width:100%;accent-color:var(--info);cursor:pointer;margin-top:6px;"
                                oninput="document.getElementById('capBgAlphaVal').textContent=this.value+'%';capFieldChanged('bg_opacity',this.value/100)">
                        </div>
                    </div>
                </div>
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:10px;">Border</div>
                    <div style="display:flex;gap:10px;align-items:flex-end;">
                        <div>
                            <div class="cap-field-label">Color</div>
                            <input type="color" id="capBorderColor" value="#ffffff"
                                oninput="capBorderChanged()" onchange="capBorderChanged()"
                                style="padding:2px 3px;height:32px;width:52px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;">
                        </div>
                        <div style="flex:1;">
                            <div class="cap-field-label">Thickness <span id="capBorderThickVal" style="color:var(--info);font-weight:700;">0px</span></div>
                            <input type="range" id="capBorderThick" min="0" max="16" value="0" step="1"
                                style="width:100%;accent-color:var(--info);cursor:pointer;margin-top:6px;"
                                oninput="document.getElementById('capBorderThickVal').textContent=this.value+'px';capBorderChanged()">
                        </div>
                        <div>
                            <div class="cap-field-label">Preview</div>
                            <div id="capBorderPreview" style="width:44px;height:26px;border-radius:6px;border:0px solid #ffffff;background:rgba(0,0,0,0.45);transition:all .2s;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ OVERLAY 3: Position & Alignment ══ -->
<div class="cap-overlay" id="capOvPos">
    <div class="cap-ov-panel">
        <div class="cap-ov-head">
            <span>📐 Position &amp; Alignment</span>
            <button class="cap-ov-close" onclick="closeCapOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div id="capPosNoSel" class="cap-nosel">👆 Select a caption first</div>
            <div id="capPosEditor" style="display:none;">
                <div class="cap-ov-section">
                    <div style="display:flex;gap:14px;align-items:flex-start;">
                        <!-- D-pad -->
                        <div>
                            <div class="cap-field-label" style="margin-bottom:6px;">Move Box</div>
                            <div style="display:grid;grid-template-columns:repeat(3,32px);grid-template-rows:repeat(3,32px);gap:3px;">
                                <div></div>
                                <button class="pos-cell" onclick="moveCapArrow(0,-20)">↑</button>
                                <div></div>
                                <button class="pos-cell" onclick="moveCapArrow(-20,0)">←</button>
                                <button class="pos-cell" onclick="centreCaption()" style="background:var(--primary);color:#fff;border-color:var(--primary);font-size:10px;">●</button>
                                <button class="pos-cell" onclick="moveCapArrow(20,0)">→</button>
                                <div></div>
                                <button class="pos-cell" onclick="moveCapArrow(0,20)">↓</button>
                                <div></div>
                            </div>
                            <div style="font-size:9px;color:var(--muted);text-align:center;margin-top:5px;">
                                X <span id="capPosXLbl" style="color:var(--info);font-weight:700;">0</span>
                                &nbsp;Y <span id="capPosYLbl" style="color:var(--info);font-weight:700;">0</span>
                            </div>
                        </div>
                        <!-- Snap + exact -->
                        <div style="flex:1;display:flex;flex-direction:column;gap:10px;">
                            <div>
                                <div class="cap-field-label" style="margin-bottom:5px;">Snap To</div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;">
                                    <button class="tog" style="font-size:10px;padding:6px 4px;" onclick="snapCaption('top')">⬆ Top</button>
                                    <button class="tog" style="font-size:10px;padding:6px 4px;" onclick="snapCaption('middle')">↕ Middle</button>
                                    <button class="tog" style="font-size:10px;padding:6px 4px;" onclick="snapCaption('bottom')">⬇ Bottom</button>
                                    <button class="tog" style="font-size:10px;padding:6px 4px;" onclick="snapCaption('centre-h')">⬌ Centre</button>
                                </div>
                            </div>
                            <div>
                                <div class="cap-field-label" style="margin-bottom:5px;">Exact Position</div>
                                <div style="display:flex;gap:4px;">
                                    <div style="flex:1;">
                                        <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">X</div>
                                        <input type="number" id="capPosX" min="0" max="360" value="50" class="cap-num-input"
                                            oninput="capPosInput('position_x',this.value)">
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">Y</div>
                                        <input type="number" id="capPosY" min="0" max="640" value="400" class="cap-num-input"
                                            oninput="capPosInput('position_y',this.value)">
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">W</div>
                                        <input type="number" id="capWidth" min="80" max="360" value="320" class="cap-num-input"
                                            oninput="capPosInput('width',this.value)">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
        <div id="dotRow">
            <?php foreach ($scenes as $i => $s): ?>
            <?php
                $hasMed = !empty(trim($s['image_file'] ?? ''));
            ?>
            <div class="dot<?= $i===0?' active':'' ?><?= $hasMed?' has-media':'' ?>"
                 id="dot<?= $i ?>"
                 onclick="goToScene(<?= $i ?>)"
                 title="Scene <?= $i+1 ?>"></div>
            <?php endforeach; ?>
        </div>

    </div><!-- .canvas-col -->
</div><!-- .workspace -->

<!-- ══ FONT OVERLAY 1: Font Family & Size & Color ══ -->
<div class="cap-overlay" id="fontOvFont">
    <div class="cap-ov-panel">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>🔤 Font Family, Size &amp; Color</span>
            <button class="cap-ov-close" onclick="closeFontOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div id="fontOvNoSel1" class="cap-nosel">👆 Select a caption on the canvas first</div>
            <div id="fontOvEditor1" style="display:none;">
                <div class="cap-ov-section">
                    <div class="cap-field-label" style="margin-bottom:6px;">Font Family</div>
                    <input type="hidden" id="capFont" value="Arial,sans-serif">
                    <div id="fontPickerWrap" style="position:relative;">
                        <div id="fontPickerBtn" onclick="toggleFontPicker()"
                            style="width:100%;padding:8px 12px;border-radius:8px;border:1.5px solid var(--border);
                                   background:var(--surface2);color:var(--text);font-size:14px;cursor:pointer;
                                   display:flex;align-items:center;justify-content:space-between;gap:6px;user-select:none;">
                            <span id="fontPickerLabel" style="font-family:Arial,sans-serif;">Arial</span>
                            <span style="font-size:10px;color:var(--muted);">▼</span>
                        </div>
                        <div id="fontPickerDropdown"
                            style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
                                   background:var(--surface);border:1.5px solid var(--border);border-radius:10px;
                                   box-shadow:0 8px 30px rgba(0,0,0,.15);z-index:99999;max-height:280px;overflow-y:auto;">
                            <div class="fp-hdr">Sans Serif</div>
                            <div class="fp-opt" data-val="Arial,sans-serif"                    style="font-family:Arial,sans-serif;">Arial</div>
                            <div class="fp-opt" data-val="'Segoe UI',sans-serif"               style="font-family:'Segoe UI',sans-serif;">Segoe UI</div>
                            <div class="fp-opt" data-val="Verdana,sans-serif"                  style="font-family:Verdana,sans-serif;">Verdana</div>
                            <div class="fp-opt" data-val="'Poppins',sans-serif"                style="font-family:'Poppins',sans-serif;">Poppins</div>
                            <div class="fp-opt" data-val="'Montserrat',sans-serif"             style="font-family:'Montserrat',sans-serif;">Montserrat</div>
                            <div class="fp-opt" data-val="'Raleway',sans-serif"                style="font-family:'Raleway',sans-serif;">Raleway</div>
                            <div class="fp-opt" data-val="'Oswald',sans-serif"                 style="font-family:'Oswald',sans-serif;">Oswald</div>
                            <div class="fp-opt" data-val="'Josefin Sans',sans-serif"           style="font-family:'Josefin Sans',sans-serif;">Josefin Sans</div>
                            <div class="fp-opt" data-val="'Barlow Condensed',sans-serif"       style="font-family:'Barlow Condensed',sans-serif;">Barlow Condensed</div>
                            <div class="fp-opt" data-val="'DM Sans',sans-serif"                style="font-family:'DM Sans',sans-serif;">DM Sans</div>
                            <div class="fp-opt" data-val="'Jost',sans-serif"                   style="font-family:'Jost',sans-serif;">Jost</div>
                            <div class="fp-opt" data-val="'Space Grotesk',sans-serif"          style="font-family:'Space Grotesk',sans-serif;">Space Grotesk</div>
                            <div class="fp-opt" data-val="'Righteous',sans-serif"              style="font-family:'Righteous',sans-serif;">Righteous</div>
                            <div class="fp-opt" data-val="'Black Han Sans',sans-serif"         style="font-family:'Black Han Sans',sans-serif;">Black Han Sans</div>
                            <div class="fp-hdr">Serif</div>
                            <div class="fp-opt" data-val="Georgia,serif"                       style="font-family:Georgia,serif;">Georgia</div>
                            <div class="fp-opt" data-val="'Times New Roman',serif"             style="font-family:'Times New Roman',serif;">Times New Roman</div>
                            <div class="fp-opt" data-val="'Playfair Display',serif"            style="font-family:'Playfair Display',serif;">Playfair Display</div>
                            <div class="fp-opt" data-val="'Lora',serif"                        style="font-family:'Lora',serif;">Lora</div>
                            <div class="fp-opt" data-val="'Libre Baskerville',serif"           style="font-family:'Libre Baskerville',serif;">Libre Baskerville</div>
                            <div class="fp-opt" data-val="'Cinzel',serif"                      style="font-family:'Cinzel',serif;">Cinzel</div>
                            <div class="fp-opt" data-val="'Roboto Slab',serif"                 style="font-family:'Roboto Slab',serif;">Roboto Slab</div>
                            <div class="fp-hdr" style="background:#fff7ed;color:#c2410c;">📣 Display &amp; Promotional</div>
                            <div class="fp-opt" data-val="Impact,fantasy"                      style="font-family:Impact,fantasy;">Impact</div>
                            <div class="fp-opt" data-val="'Anton',sans-serif"                  style="font-family:'Anton',sans-serif;">Anton</div>
                            <div class="fp-opt" data-val="'Bebas Neue',sans-serif"             style="font-family:'Bebas Neue',sans-serif;">Bebas Neue</div>
                            <div class="fp-opt" data-val="'Bangers',cursive"                   style="font-family:'Bangers',cursive;">Bangers</div>
                            <div class="fp-opt" data-val="'Luckiest Guy',cursive"              style="font-family:'Luckiest Guy',cursive;">Luckiest Guy</div>
                            <div class="fp-opt" data-val="'Black Ops One',cursive"             style="font-family:'Black Ops One',cursive;">Black Ops One</div>
                            <div class="fp-opt" data-val="'Russo One',sans-serif"              style="font-family:'Russo One',sans-serif;">Russo One</div>
                            <div class="fp-opt" data-val="'Teko',sans-serif"                   style="font-family:'Teko',sans-serif;">Teko</div>
                            <div class="fp-hdr" style="background:#fdf4ff;color:#7c3aed;">🖋️ Handwriting</div>
                            <div class="fp-opt" data-val="'Dancing Script',cursive"            style="font-family:'Dancing Script',cursive;">Dancing Script</div>
                            <div class="fp-opt" data-val="'Pacifico',cursive"                  style="font-family:'Pacifico',cursive;">Pacifico</div>
                            <div class="fp-opt" data-val="'Lobster',cursive"                   style="font-family:'Lobster',cursive;">Lobster</div>
                            <div class="fp-opt" data-val="'Permanent Marker',cursive"          style="font-family:'Permanent Marker',cursive;">Permanent Marker</div>
                            <div class="fp-opt" data-val="'Caveat',cursive"                    style="font-family:'Caveat',cursive;">Caveat</div>
                            <div class="fp-opt" data-val="'Great Vibes',cursive"               style="font-family:'Great Vibes',cursive;font-size:18px;">Great Vibes</div>
                            <div class="fp-opt" data-val="'Sacramento',cursive"                style="font-family:'Sacramento',cursive;font-size:18px;">Sacramento</div>
                            <div class="fp-opt" data-val="'Satisfy',cursive"                   style="font-family:'Satisfy',cursive;font-size:18px;">Satisfy</div>
                            <div class="fp-hdr" style="background:#fdf4ff;color:#7c3aed;">🌙 Arabic / Urdu</div>
                            <div class="fp-opt" data-val="'NotoNastaliqUrdu',serif"            style="font-family:'NotoNastaliqUrdu',serif;direction:rtl;font-size:20px;line-height:2;">نوٹو نستعلیق <span style="font-size:10px;direction:ltr;color:var(--muted);font-family:Arial,sans-serif;">Noto Nastaliq Urdu</span></div>
                            <div class="fp-opt" data-val="'AttariQuraanWord',serif"            style="font-family:'AttariQuraanWord',serif;direction:rtl;font-size:20px;line-height:2;">عطاری قرآن <span style="font-size:10px;direction:ltr;color:var(--muted);font-family:Arial,sans-serif;">Attari Quraan Word</span></div>
                        </div>
                    </div>
                </div>
                <div class="cap-ov-section">
                    <div style="display:flex;gap:12px;align-items:flex-end;">
                        <div style="width:90px;flex-shrink:0;">
                            <div class="cap-field-label" style="margin-bottom:4px;">Size</div>
                            <select id="capSize" onchange="capFieldChanged('fontsize',this.value)"
                                style="width:100%;padding:7px 8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:12px;font-family:inherit;cursor:pointer;outline:none;">
                                <option value="10">10</option><option value="12">12</option>
                                <option value="14">14</option><option value="16">16</option>
                                <option value="18">18</option><option value="20">20</option>
                                <option value="22">22</option><option value="24">24</option>
                                <option value="26">26</option><option value="28">28</option>
                                <option value="30">30</option><option value="32">32</option>
                                <option value="36">36</option><option value="40">40</option>
                                <option value="48">48</option><option value="56">56</option>
                                <option value="64">64</option><option value="72">72</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <div class="cap-field-label" style="margin-bottom:4px;">Text Color</div>
                            <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                                <input type="color" id="capColor" value="#ffffff"
                                    oninput="capFieldChanged('fontcolor',this.value)"
                                    onchange="capFieldChanged('fontcolor',this.value)"
                                    style="padding:2px 3px;height:32px;width:44px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;flex-shrink:0;">
                                <?php foreach(['#ffffff','#ffff00','#ff3b30','#00ff00','#00ffff','#5fc3ff','#ff9500','#ff69b4','#000000'] as $c): ?>
                                <div class="swatch" style="background:<?=$c?>;<?=$c==='#ffffff'?'border:1px solid #ccc;':''?>width:22px;height:22px;border-radius:4px;cursor:pointer;flex-shrink:0;"
                                    onclick="setCapTextColor('<?=$c?>')"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ FONT OVERLAY 2: Style & Effects & Animation ══ -->
<div class="cap-overlay" id="fontOvStyle">
    <div class="cap-ov-panel">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>✨ Style, Effects &amp; Animation</span>
            <button class="cap-ov-close" onclick="closeFontOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div id="fontOvNoSel2" class="cap-nosel">👆 Select a caption on the canvas first</div>
            <div id="fontOvEditor2" style="display:none;">
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:10px;">Style</div>
                    <div style="display:flex;gap:6px;">
                        <button class="tog" id="capBold"      onclick="toggleCapStyle('bold')"
                            style="flex:1;height:38px;font-size:15px;"><b>B</b></button>
                        <button class="tog" id="capItalic"    onclick="toggleCapStyle('italic')"
                            style="flex:1;height:38px;font-size:15px;"><i>I</i></button>
                        <button class="tog" id="capUnderline" onclick="toggleCapStyle('underline')"
                            style="flex:1;height:38px;font-size:15px;"><u>U</u></button>
                    </div>
                </div>
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:10px;">Effect</div>
                    <div style="display:flex;gap:8px;align-items:flex-end;">
                        <div style="flex:1;">
                            <select id="capEffect" onchange="capEffectChanged(this.value)"
                                style="width:100%;padding:8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:12px;font-family:inherit;cursor:pointer;outline:none;">
                                <option value="none">None</option>
                                <option value="shadow">Shadow</option>
                                <option value="glow">Glow</option>
                                <option value="outline">Outline</option>
                                <option value="stroke">Stroke</option>
                                <option value="gradient">Gradient</option>
                                <option value="3d">3D</option>
                            </select>
                        </div>
                        <div id="capStrokeColorField" style="display:none;flex-shrink:0;">
                            <div class="cap-field-label" style="margin-bottom:4px;">Color</div>
                            <input type="color" id="capStrokeColor" value="#000000"
                                oninput="capFieldChanged('stroke_color',this.value)"
                                onchange="capFieldChanged('stroke_color',this.value)"
                                style="padding:2px 3px;height:32px;width:44px;border-radius:8px;border:1.5px solid var(--border);cursor:pointer;outline:none;">
                        </div>
                    </div>
                </div>
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:10px;">Animation</div>
                    <div style="display:flex;gap:8px;align-items:flex-start;">
                        <div style="flex:1;">
                            <div class="cap-field-label" style="margin-bottom:4px;">Style</div>
                            <select id="capAnim" onchange="capFieldChanged('animation_style',this.value)"
                                style="width:100%;padding:8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:12px;font-family:inherit;cursor:pointer;outline:none;">
                                <option value="none">None</option>
                                <option value="typewriter">Typewriter</option>
                                <option value="char-by-char">Char by Char</option>
                                <option value="word-reveal">Word by Word</option>
                                <option value="line-by-line">Line by Line</option>
                                <option value="zoom-in">Zoom In</option>
                                <option value="pop">Pop</option>
                                <option value="bounce">Bounce</option>
                                <option value="karaoke">Karaoke</option>
                                <option value="fade-in">Fade In</option>
                                <option value="static">Static</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <div class="cap-field-label">Speed</div>
                                <div id="capAnimSpeedVal" style="font-size:10px;font-weight:700;color:var(--primary);">1.0x</div>
                            </div>
                            <input type="range" id="capAnimSpeed" min="0.2" max="4" step="0.1" value="1"
                                style="width:100%;accent-color:var(--primary);cursor:pointer;"
                                oninput="document.getElementById('capAnimSpeedVal').textContent=parseFloat(this.value).toFixed(1)+'x';capFieldChanged('animation_speed',parseFloat(this.value));">
                            <div style="display:flex;justify-content:space-between;font-size:8px;color:var(--muted);margin-top:2px;">
                                <span>Slow</span><span>Normal</span><span>Fast</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ FONT OVERLAY 3: Alignment ══ -->
<div class="cap-overlay" id="fontOvAlign">
    <div class="cap-ov-panel">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>↔️ Text Alignment</span>
            <button class="cap-ov-close" onclick="closeFontOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div id="fontOvNoSel3" class="cap-nosel">👆 Select a caption on the canvas first</div>
            <div id="fontOvEditor3" style="display:none;">
                <div class="cap-ov-section">
                    <div class="cap-section-label" style="margin-bottom:12px;">Text Align</div>
                    <div style="display:flex;gap:6px;">
                        <button class="tog" id="capTaLeft"    onclick="setCapTA('left')"
                            style="flex:1;height:44px;font-size:18px;" title="Left">
                            <svg width="18" height="14" viewBox="0 0 18 14" fill="currentColor"><rect x="0" y="0" width="18" height="2"/><rect x="0" y="4" width="12" height="2"/><rect x="0" y="8" width="16" height="2"/><rect x="0" y="12" width="10" height="2"/></svg>
                        </button>
                        <button class="tog" id="capTaCenter"  onclick="setCapTA('center')"
                            style="flex:1;height:44px;font-size:18px;" title="Center">
                            <svg width="18" height="14" viewBox="0 0 18 14" fill="currentColor"><rect x="0" y="0" width="18" height="2"/><rect x="3" y="4" width="12" height="2"/><rect x="1" y="8" width="16" height="2"/><rect x="4" y="12" width="10" height="2"/></svg>
                        </button>
                        <button class="tog" id="capTaRight"   onclick="setCapTA('right')"
                            style="flex:1;height:44px;font-size:18px;" title="Right">
                            <svg width="18" height="14" viewBox="0 0 18 14" fill="currentColor"><rect x="0" y="0" width="18" height="2"/><rect x="6" y="4" width="12" height="2"/><rect x="2" y="8" width="16" height="2"/><rect x="8" y="12" width="10" height="2"/></svg>
                        </button>
                        <button class="tog" id="capTaJustify" onclick="setCapTA('justify')"
                            style="flex:1;height:44px;font-size:18px;" title="Justify">
                            <svg width="18" height="14" viewBox="0 0 18 14" fill="currentColor"><rect x="0" y="0" width="18" height="2"/><rect x="0" y="4" width="18" height="2"/><rect x="0" y="8" width="18" height="2"/><rect x="0" y="12" width="12" height="2"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ MEDIA OVERLAY ══ -->
<div class="cap-overlay" id="mediaOverlay">
    <div class="cap-ov-panel" style="max-height:90vh;">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>🌄 Scene Media — Scene <span id="mediaSceneNum">1</span></span>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:11px;font-weight:700;background:rgba(255,255,255,.2);padding:3px 10px;border-radius:20px;">
                    💰 <span id="creditBalanceDisplay"><?= number_format($credit_balance, 2) ?></span> cr
                </span>
                <button class="cap-ov-close" onclick="closeMediaOverlay()">✕</button>
            </div>
        </div>
        <div class="cap-ov-body">
            <div class="cap-ov-section">
                <div style="display:flex;gap:14px;align-items:flex-start;">
                    <div style="flex-shrink:0;">
                        <div class="cap-field-label" style="margin-bottom:6px;">Current Media</div>
                        <div id="mediaCurrThumb"
                             style="width:90px;height:160px;border-radius:10px;background:#1e293b;border:2px solid var(--border);
                                    overflow:hidden;display:flex;align-items:center;justify-content:center;position:relative;">
                            <span id="mediaCurrPh" style="font-size:28px;color:rgba(255,255,255,.4);">🎬</span>
                        </div>
                        <div id="mediaCurrName" style="font-size:9px;color:var(--muted);margin-top:4px;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;"></div>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="cap-field-label" style="margin-bottom:6px;">Video Prompt</div>
                        <textarea id="mediaVideoPrompt" rows="7" class="cap-textarea"
                            placeholder="Describe the video scene — used for AI video generation…"
                            oninput="_mediaPromptChanged()"
                            style="font-size:11px;font-family:monospace;resize:none;"></textarea>
                        <div id="mediaPromptSaveStatus" style="font-size:10px;color:var(--muted);margin-top:3px;height:14px;"></div>
                    </div>
                </div>
            </div>
            <div class="cap-ov-section">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                    <button onclick="mediaUpload()" id="mediaUploadBtn"
                        style="background:var(--success);color:#fff;border:none;border-radius:12px;padding:14px 6px;
                               cursor:pointer;font-size:12px;font-weight:700;display:flex;flex-direction:column;
                               align-items:center;justify-content:center;gap:5px;min-height:80px;font-family:inherit;">
                        <span style="font-size:24px;">📤</span>Upload
                    </button>
                    <button onclick="mediaLibrary()" id="mediaLibBtn"
                        style="background:#7c3aed;color:#fff;border:none;border-radius:12px;padding:14px 6px;
                               cursor:pointer;font-size:12px;font-weight:700;display:flex;flex-direction:column;
                               align-items:center;justify-content:center;gap:5px;min-height:80px;font-family:inherit;">
                        <span style="font-size:24px;">📚</span>Library
                    </button>
                    <button onclick="mediaGenerate()" id="mediaGenBtn"
                        style="background:#e65100;color:#fff;border:none;border-radius:12px;padding:14px 6px;
                               cursor:pointer;font-size:12px;font-weight:700;display:flex;flex-direction:column;
                               align-items:center;justify-content:center;gap:5px;min-height:80px;font-family:inherit;">
                        <span style="font-size:24px;">🎬</span>Generate<span style="font-size:10px;opacity:.85;">1 cr</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ GENERATE CONFIRM MODAL ══ -->
<div id="mediaGenConfirm" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:26px 24px;width:300px;max-width:90vw;
                box-shadow:0 20px 60px rgba(0,0,0,.4);text-align:center;">
        <div style="font-size:36px;margin-bottom:10px;">🎬</div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px;">Generate AI Video?</div>
        <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Cost: <strong>1 credit</strong></div>
        <div style="font-size:12px;color:#64748b;margin-bottom:16px;">
            Your balance: <strong id="confirmBalance">0.00</strong> credits
            <span id="confirmInsufficientMsg" style="display:none;color:#ef4444;font-weight:700;font-size:11px;"><br>⚠️ Insufficient credits</span>
        </div>
        <div style="display:flex;gap:10px;">
            <button onclick="mediaGenConfirmed()" id="confirmYesBtn"
                style="flex:1;padding:11px;border-radius:9px;border:none;background:#e65100;color:#fff;
                       font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">✅ Yes, Generate</button>
            <button onclick="document.getElementById('mediaGenConfirm').style.display='none'"
                style="flex:1;padding:11px;border-radius:9px;border:1.5px solid #cbd5e1;background:#fff;
                       color:#64748b;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
        </div>
    </div>
</div>

<!-- ══ LIBRARY MODAL ══ -->
<div id="libModal" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(15,23,42,.82);
     backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:16px;">
    <div style="background:var(--surface);border-radius:14px;width:100%;max-width:480px;height:82vh;
                display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.5);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;
                    background:var(--primary);flex-shrink:0;border-radius:14px 14px 0 0;">
            <div style="color:#fff;font-size:13px;font-weight:700;">📚 Stock Media Library</div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="libSearchStatus" style="font-size:10px;color:rgba(255,255,255,.8);font-weight:600;display:none;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:20px;"></span>
                <button onclick="closeLibraryModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                        width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">✕</button>
            </div>
        </div>
        <div style="padding:10px 12px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <div style="display:flex;gap:6px;margin-bottom:8px;">
                <input id="libSearch" type="text" placeholder="Search media…"
                    style="flex:1;padding:7px 12px;border-radius:20px;border:1.5px solid var(--border);
                           font-size:12px;outline:none;font-family:inherit;background:var(--surface2);color:var(--text);"
                    onkeydown="if(event.key==='Enter')performLibSearch()">
                <button onclick="performLibSearch()"
                    style="padding:7px 14px;border-radius:20px;border:none;background:var(--info);
                           color:#fff;font-size:11px;font-weight:700;cursor:pointer;">🔍</button>
            </div>
            <div style="display:flex;gap:4px;">
                <button id="libTabAll"  onclick="setLibTab('all')"   class="lib-tab active">All <span id="libCountAll"></span></button>
                <button id="libTabImg"  onclick="setLibTab('image')" class="lib-tab">🖼️ <span id="libCountImg"></span></button>
                <button id="libTabVid"  onclick="setLibTab('video')" class="lib-tab">🎬 <span id="libCountVid"></span></button>
                <button id="libTabMine" onclick="setLibTab('mine')"  class="lib-tab">👤 Mine <span id="libCountMine"></span></button>
            </div>
        </div>
        <div id="libGrid" style="flex:1;overflow-y:auto;padding:10px;">
            <div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:var(--muted);font-size:13px;">Loading…</div>
        </div>
        <div style="padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;
             display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <span id="libSelInfo" style="font-size:10px;color:var(--muted);flex:1;overflow:hidden;
                  text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <button onclick="closeLibraryModal()"
                    style="padding:6px 14px;border-radius:8px;border:1.5px solid var(--border);
                           background:var(--surface2);font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
                <button id="libUseBtn" onclick="useLibraryFile()" disabled
                    style="padding:6px 14px;border-radius:8px;border:none;background:var(--primary);
                           color:#fff;font-size:11px;font-weight:700;cursor:pointer;opacity:.4;font-family:inherit;">✓ Use</button>
            </div>
        </div>
    </div>
</div>
<style>
.lib-tab { flex:1;padding:5px 4px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;font-family:inherit; }
.lib-tab.active { background:var(--primary);border-color:var(--primary);color:#fff; }

/* Library grid card */
.lib-card {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    overflow: hidden;
    background: #0f172a;
    transition: border-color .15s, box-shadow .15s;
    display: flex;
    flex-direction: column;
}
.lib-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.2); }
.lib-card.selected { border-color: var(--info) !important; box-shadow: 0 0 0 3px rgba(56,189,248,.3); }
.lib-card-media {
    position: relative;
    width: 100%;
    height: 190px;          /* fixed — looks good at 3-col in 480px modal */
    overflow: hidden;
    flex-shrink: 0;
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
}
.lib-card-media img,
.lib-card-media video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    opacity: 0;
    transition: opacity .3s;
}
.lib-card-media img.lib-lazy { opacity: 0; }
.lib-card-tag {
    padding: 4px 6px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    font-size: 9px;
    color: #64748b;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    flex-shrink: 0;
    line-height: 1.4;
}
.lib-score {
    position: absolute;
    top: 5px; right: 5px;
    background: rgba(0,0,0,.72);
    color: #fff;
    padding: 2px 5px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 700;
    z-index: 10;
    pointer-events: none;
    line-height: 1.4;
}
.lib-vid-badge {
    position: absolute;
    top: 5px; left: 5px;
    background: rgba(0,0,0,.72);
    color: #fff;
    padding: 2px 5px;
    border-radius: 6px;
    font-size: 8px;
    font-weight: 600;
    z-index: 10;
    pointer-events: none;
}
.lib-check {
    position: absolute;
    bottom: 6px; right: 6px;
    background: #10b981;
    color: #fff;
    width: 22px; height: 22px;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    z-index: 20;
    pointer-events: none;
}
.lib-no-thumb {
    font-size: 32px;
    color: rgba(255,255,255,.25);
    pointer-events: none;
}
</style>
<input type="file" id="mediaFileInput" accept="image/*,video/*" style="display:none" onchange="handleMediaUpload(this)">
<input type="file" id="musicFileInput" accept="audio/mp3,audio/mpeg,audio/wav,audio/ogg,audio/m4a,.mp3,.wav,.ogg,.m4a" style="display:none" onchange="handleMusicUpload(this)">

<!-- ══ AUDIO OVERLAY 1: Voice ══ -->
<div class="cap-overlay" id="audioOvVoice">
    <div class="cap-ov-panel" style="max-height:92vh;">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>🎙️ Voice — Scene <span id="audioSceneNum">1</span></span>
            <button class="cap-ov-close" onclick="closeAudioOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div class="cap-ov-section">

                <?php if (($row['video_type']??'') === 'podcast'): ?>
                <div style="display:flex;gap:6px;margin-bottom:10px;">
                    <button onclick="setVoiceTarget('host')" id="vtHost"
                        style="flex:1;padding:6px 8px;border-radius:20px;border:1.5px solid var(--info);background:var(--info);color:#fff;font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;">
                        🎙 Host Voice
                    </button>
                    <button onclick="setVoiceTarget('guest')" id="vtGuest"
                        style="flex:1;padding:6px 8px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;">
                        🎙 Guest Voice
                    </button>
                </div>
                <?php endif; ?>

                <div style="background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:11px;color:var(--muted);">
                    <span style="font-weight:600;color:var(--text);">Current: </span>
                    <span id="vcCurrentName">—</span>
                </div>

                <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
                    <select id="vcVoiceSelect" onchange="onVoiceSelectChange()"
                        style="flex:1;padding:7px 10px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;outline:none;cursor:pointer;">
                        <option value="">Loading voices…</option>
                    </select>
                    <button id="vcPlayBtn" onclick="previewSelectedVoice()"
                        style="width:34px;height:34px;border-radius:50%;border:none;background:var(--info);color:#fff;font-size:13px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
                </div>
                <div id="vcVoiceDesc" style="font-size:10px;color:var(--muted);margin-bottom:10px;padding-left:4px;min-height:14px;"></div>

                <textarea id="vcSampleText" rows="2"
                    placeholder="Type a sentence to preview this voice…"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;resize:none;outline:none;margin-bottom:8px;"></textarea>

                <button onclick="saveSelectedVoice()" id="vcApplyBtn"
                    style="width:100%;background:var(--success);color:#fff;border:none;border-radius:10px;padding:10px;cursor:pointer;font-size:12px;font-weight:700;letter-spacing:.02em;font-family:inherit;margin-bottom:14px;">
                    ✓ Apply Voice to All Scenes
                </button>

                <!-- Regen progress -->
                <div id="audioRegenWrap" style="display:none;">
                    <div style="background:var(--border);border-radius:20px;height:8px;overflow:hidden;margin-bottom:8px;">
                        <div id="regenBar" style="height:100%;background:var(--info);width:0%;transition:width .4s;border-radius:20px;"></div>
                    </div>
                    <div style="text-align:center;font-size:12px;font-weight:700;color:var(--text);margin-bottom:4px;">
                        <span id="regenDone">0</span> / <span id="regenTotal">0</span> scenes
                    </div>
                    <div id="regenStatus" style="text-align:center;font-size:11px;color:var(--muted);margin-bottom:8px;"></div>
                    <div id="regenLog" style="max-height:120px;overflow-y:auto;font-size:10px;background:var(--surface2);border-radius:8px;padding:8px;border:1px solid var(--border);font-family:monospace;color:var(--muted);line-height:1.7;"></div>
                </div>

                <div class="cap-field-label" style="margin-bottom:6px;">
                    🎙️ Voiceover Speed &nbsp;<span id="speedValue" style="color:var(--info);font-size:12px;font-weight:700;"><?= $audio_speed ?>x</span>
                </div>
                <input type="range" id="playbackSpeedSlider" min="0.5" max="2.0" step="0.05" value="<?= $audio_speed ?>"
                    style="width:100%;accent-color:var(--info);margin-bottom:8px;"
                    oninput="updatePlaybackSpeed(this.value)">
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <button class="speed-preset" onclick="setPlaybackSpeed(0.5)">0.5x</button>
                    <button class="speed-preset" onclick="setPlaybackSpeed(0.75)">0.75x</button>
                    <button class="speed-preset<?= $audio_speed == 1.0 ? ' active':'' ?>" onclick="setPlaybackSpeed(1.0)">1.0x</button>
                    <button class="speed-preset" onclick="setPlaybackSpeed(1.25)">1.25x</button>
                    <button class="speed-preset" onclick="setPlaybackSpeed(1.5)">1.5x</button>
                    <button class="speed-preset" onclick="setPlaybackSpeed(2.0)">2.0x</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ AUDIO OVERLAY 2: Music ══ -->
<div class="cap-overlay" id="audioOvMusic">
    <div class="cap-ov-panel" style="max-height:92vh;">
        <div class="cap-ov-head" style="background:#7c3aed;">
            <span>🎵 Background Music</span>
            <button class="cap-ov-close" onclick="closeAudioOverlay()">✕</button>
        </div>
        <div class="cap-ov-body">
            <div class="cap-ov-section">
                <div id="musicCurrentWrap" style="margin-bottom:10px;"></div>

                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span class="cap-field-label">🎵 Music Volume</span>
                            <span id="musicVolLbl" style="font-size:10px;color:var(--muted);"><?= round($music_volume * 100) ?>%</span>
                        </div>
                        <input type="range" id="musicVolSlider" min="0" max="100" value="<?= round($music_volume * 100) ?>"
                            style="width:100%;accent-color:var(--info);" oninput="onMusicVolChange(this.value)">
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span class="cap-field-label">🗣️ Voice Volume</span>
                            <span id="voiceVolLbl" style="font-size:10px;color:var(--muted);"><?= round($voice_volume * 100) ?>%</span>
                        </div>
                        <input type="range" id="voiceVolSlider" min="0" max="100" value="<?= round($voice_volume * 100) ?>"
                            style="width:100%;accent-color:var(--info);" oninput="onVoiceVolChange(this.value)">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                    <button onclick="uploadMusicClick()"
                        style="background:var(--success);color:#fff;border:none;border-radius:10px;padding:10px;cursor:pointer;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:5px;font-family:inherit;">
                        📤 Upload
                    </button>
                    <button onclick="openMusicLibModal()"
                        style="background:#7c3aed;color:#fff;border:none;border-radius:10px;padding:10px;cursor:pointer;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:5px;font-family:inherit;">
                        📚 Library
                    </button>
                </div>
                <button onclick="clearPodcastMusic()"
                    style="width:100%;background:transparent;color:var(--danger);border:1.5px solid var(--danger);border-radius:8px;padding:7px;cursor:pointer;font-size:10px;font-weight:600;font-family:inherit;">
                    ✕ Remove Background Music
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MUSIC LIBRARY MODAL ══ -->
<div id="musicLibModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.82);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:16px;">
    <div style="background:var(--surface);border-radius:14px;width:100%;max-width:480px;height:75vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.5);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--primary);flex-shrink:0;border-radius:14px 14px 0 0;">
            <span style="color:#fff;font-size:13px;font-weight:700;">🎵 Music Library</span>
            <button onclick="closeMusicLibModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div style="padding:10px 12px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <input id="musicLibSearch" type="text" placeholder="Search by filename…"
                style="width:100%;padding:7px 12px;border-radius:20px;border:1.5px solid var(--border);font-size:12px;outline:none;font-family:inherit;background:var(--surface2);color:var(--text);box-sizing:border-box;"
                oninput="filterMusicLibGrid()">
        </div>
        <div id="musicLibGrid" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:6px;"></div>
        <div style="padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <span id="musicLibSelInfo" style="font-size:10px;color:var(--muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <button onclick="closeMusicLibModal()" style="padding:6px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
                <button id="musicLibUseBtn" onclick="useMusicLibFile()" disabled style="padding:6px 14px;border-radius:8px;border:none;background:var(--primary);color:#fff;font-size:11px;font-weight:700;cursor:pointer;opacity:.4;font-family:inherit;">✓ Use</button>
            </div>
        </div>
    </div>
</div>
<style>
.speed-preset {
    padding:4px 10px;border-radius:20px;border:1.5px solid var(--border);
    background:var(--surface2);color:var(--text);font-size:10px;font-weight:600;
    cursor:pointer;font-family:inherit;transition:all .13s;
}
.speed-preset.active { background:var(--primary);border-color:var(--primary);color:#fff; }
</style>

<script>
const PODCAST_ID   = <?= $podcast_id ?>;
const SCENES       = <?= $scenes_json ?>;
const ALL_CAPTIONS = <?= $all_captions_json ?>;
const IMG_FOLDER   = <?= json_encode($img_folder) ?>;
const CW = <?= CW ?>;
const CH = <?= CH ?>;
const AUDIO_SPEED  = <?= json_encode($audio_speed) ?>;
const LANG_CODE    = <?= json_encode($lang_code) ?>;
let _hostVoiceId   = '<?= addslashes($host_voice_id) ?>';
let _guestVoiceId  = '<?= addslashes($guest_voice_id) ?>';
let USER_CREDIT    = <?= json_encode($credit_balance) ?>;
const USER_ROLE    = <?= json_encode($user_role) ?>;
const BILLING_UID  = <?= $billing_user_id ?>;
const USER_MEDIA_FOLDER = 'user_media/user_id_<?= (int)$admin_id ?>_company_id_<?= (int)$company_id ?>/';
const IMG_BASE     = <?= json_encode($img_folder.'/') ?>;
const FILE_FOLDER  = {};

// Pre-populate FILE_FOLDER from scene data
(function() {
    SCENES.forEach(sc => {
        const fn = (sc.image_file||'').trim();
        if (!fn) return;
        const f  = (sc.image_folder||'').trim().replace(/^\/|\/$/g,'');
        FILE_FOLDER[fn] = (f||'podcast_images') + '/';
    });
})();

function getFileFolder(fn) { return FILE_FOLDER[fn] || IMG_BASE; }
function getSceneMediaFolder(sc) {
    const raw = (sc.image_folder||'').trim().replace(/^\/|\/$/g,'');
    return (raw || 'podcast_images') + '/';
}
</script>

<script>
// ── State ────────────────────────────────────────────────────────────────────
let currentIndex = 0;
const totalScenes = SCENES.length;

// ── DOM refs ─────────────────────────────────────────────────────────────────
const canvas      = document.getElementById('screen');
const ctx         = canvas.getContext('2d');
const sceneVideo  = document.getElementById('sceneVideo');
const loadOverlay = document.getElementById('loadOverlay');
const loadMsg     = document.getElementById('loadMsg');

// ── Helpers ──────────────────────────────────────────────────────────────────
function isVideoFile(fn) {
    return /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
}

function mediaPath(scene) {
    const fn     = (scene.image_file || '').trim();
    const folder = (scene.image_folder || IMG_FOLDER || 'podcast_images').replace(/\/+$/, '');
    return fn ? folder + '/' + fn : null;
}

function showOverlay(msg) {
    loadMsg.textContent = msg || 'Loading…';
    loadOverlay.style.display = 'flex';
}

function hideOverlay() {
    loadOverlay.style.display = 'none';
}

// ── Canvas fill helpers ───────────────────────────────────────────────────────
function fillBlack(message) {
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, CW, CH);
    if (message) {
        ctx.fillStyle = 'rgba(148,163,184,.55)';
        ctx.font = '600 13px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(message, CW / 2, CH / 2);
    }
}

function loadImageCached(src) {
    const key = src;
    if (imgCache[key] && imgCache[key] !== null) return Promise.resolve(imgCache[key]);
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => { imgCache[key] = img; resolve(img); };
        img.onerror = () => { imgCache[key] = null; reject(new Error('Image load failed: ' + src)); };
        img.src = src;
    });
}

// ── Video loop (with captions) ────────────────────────────────────────────────
let _rafId   = null;
let _playing = false;
let _staticRaf = null; // separate RAF for static-image caption redraw

function _stopStaticRaf() {
    if (_staticRaf) { cancelAnimationFrame(_staticRaf); _staticRaf = null; }
}

function _startStaticRaf() {
    _stopStaticRaf();
    // Only needed if any caption has an animation style
    const needsAnim = sceneCaptions.some(c => {
        const style = c.animation_style || 'none';
        return +c.is_visible && !['static','none'].includes(style);
    });
    if (!needsAnim) return; // static captions don't need continuous redraw
    function loop() {
        // Redraw: background image then captions
        if (_lastStaticSrc) {
            const im = _lastStaticImg;
            if (im) {
                ctx.clearRect(0, 0, CW, CH);
                const scale = Math.max(CW / im.naturalWidth, CH / im.naturalHeight);
                const sw = im.naturalWidth * scale, sh = im.naturalHeight * scale;
                ctx.drawImage(im, (CW-sw)/2, (CH-sh)/2, sw, sh);
            }
        }
        drawAllCaptions();
        _staticRaf = requestAnimationFrame(loop);
    }
    loop();
}

let _lastStaticSrc = null;
let _lastStaticImg = null;

function stopVideo() {
    _playing = false;
    cancelAnimationFrame(_rafId);
    _rafId = null;
    _stopStaticRaf();
    sceneVideo.pause();
    sceneVideo.src = '';
}

function startVideoLoop() {
    _playing = true;
    function loop() {
        if (!_playing) return;
        ctx.drawImage(sceneVideo, 0, 0, CW, CH);
        drawAllCaptions();
        _rafId = requestAnimationFrame(loop);
    }
    loop();
}

function playVideoScene(src) {
    stopVideo();
    sceneVideo.src = src;
    sceneVideo.loop = true;
    sceneVideo.muted = true;
    sceneVideo.oncanplay = () => {
        hideOverlay();
        sceneVideo.play().then(() => {
            startVideoLoop();
            _syncPlayBtn();
        }).catch(err => {
            console.warn('Video play error:', err);
            hideOverlay();
        });
    };
    sceneVideo.onerror = () => {
        hideOverlay();
        fillBlack('⚠ Video load failed');
    };
    sceneVideo.load();
}

// ── Load a scene into canvas ──────────────────────────────────────────────────
async function loadScene(index) {
    stopVideo();
    _lastStaticSrc = null;
    _lastStaticImg = null;
    const pb = document.getElementById('navPlayBtn');
    if (pb) { pb.textContent = '▶'; pb.classList.remove('playing'); }
    const scene = SCENES[index];
    const path  = mediaPath(scene);

    // Load captions for this scene
    loadSceneCaptions(scene.id);

    updateUI(index);

    if (!path) {
        hideOverlay();
        fillBlack('No media — Scene ' + (index + 1));
        drawAllCaptions();
        return;
    }

    showOverlay('Loading scene ' + (index + 1) + '…');

    if (isVideoFile(scene.image_file || '')) {
        playVideoScene(path);
    } else {
        // Static image
        try {
            fillBlack();
            const img = await loadImageCached(path);
            _lastStaticSrc = path;
            _lastStaticImg = img;
            const scale = Math.max(CW / img.naturalWidth, CH / img.naturalHeight);
            const sw = img.naturalWidth * scale, sh = img.naturalHeight * scale;
            ctx.drawImage(img, (CW-sw)/2, (CH-sh)/2, sw, sh);
            drawAllCaptions();
            hideOverlay();
            _startStaticRaf(); // keep repainting if any caption animates
        } catch (e) {
            hideOverlay();
            fillBlack('⚠ Image not found');
            drawAllCaptions();
            console.warn(e);
        }
    }
}

// ── Update all UI elements for the current scene ──────────────────────────────
function updateUI(index) {
    // Counter
    document.getElementById('navCounterText').textContent = (index + 1) + ' / ' + totalScenes;

    // Arrows
    document.getElementById('navPrev').disabled = (index === 0);
    document.getElementById('navNext').disabled = (index === totalScenes - 1);

    // Dots
    document.querySelectorAll('.dot').forEach((d, i) => {
        d.classList.toggle('active', i === index);
    });

    // Refresh media overlay if open
    if (document.getElementById('mediaOverlay')?.classList.contains('open')) {
        _populateMediaOverlay();
    }
}

// ── Navigation ────────────────────────────────────────────────────────────────
function navigate(delta) {
    const next = currentIndex + delta;
    if (next < 0 || next >= totalScenes) return;
    currentIndex = next;
    loadScene(currentIndex);
}

function goToScene(index) {
    if (index < 0 || index >= totalScenes) return;
    currentIndex = index;
    loadScene(currentIndex);
}

// ── Caption system ────────────────────────────────────────────────────────────
const captionStates  = {}; // { capId: { show, full, words, karIdx, timer } }
let   sceneCaptions  = []; // captions for the current scene
const imgCache       = {}; // filename → Image | null (null = failed)

const FONT_NORM = {
    'Arial':'Arial,sans-serif','Helvetica':'Helvetica,sans-serif',
    'Verdana':'Verdana,sans-serif','Georgia':'Georgia,serif',
    'Impact':'Impact,fantasy','Courier New':"'Courier New',monospace",
    'Times New Roman':"'Times New Roman',serif",'Inter':"'Inter',sans-serif",
    'Poppins':"'Poppins',sans-serif",'Montserrat':"'Montserrat',sans-serif",
    'Raleway':"'Raleway',sans-serif",'Oswald':"'Oswald',sans-serif",
    'Anton':"'Anton',sans-serif",'Righteous':"'Righteous',sans-serif",
    'Black Han Sans':"'Black Han Sans',sans-serif",
    'Josefin Sans':"'Josefin Sans',sans-serif",
    'Barlow Condensed':"'Barlow Condensed',sans-serif",
    'DM Sans':"'DM Sans',sans-serif",'Jost':"'Jost',sans-serif",
    'Space Grotesk':"'Space Grotesk',sans-serif",'Syne':"'Syne',sans-serif",
    'Playfair Display':"'Playfair Display',serif",'Lora':"'Lora',serif",
    'Libre Baskerville':"'Libre Baskerville',serif",
    'Cinzel':"'Cinzel',serif",'Bebas Neue':"'Bebas Neue',sans-serif",
    'Bangers':"'Bangers',cursive",'Luckiest Guy':"'Luckiest Guy',cursive",
    'Black Ops One':"'Black Ops One',cursive",'Russo One':"'Russo One',sans-serif",
    'Dancing Script':"'Dancing Script',cursive",'Pacifico':"'Pacifico',cursive",
    'Lobster':"'Lobster',cursive",'Permanent Marker':"'Permanent Marker',cursive",
    'Caveat':"'Caveat',cursive",'Great Vibes':"'Great Vibes',cursive",
    'Sacramento':"'Sacramento',cursive",'Satisfy':"'Satisfy',cursive",
    'NotoNastaliqUrdu':"'NotoNastaliqUrdu',serif",
    'AttariQuraanWord':"'AttariQuraanWord',serif",
};

function rrect(c, x, y, w, h, r) {
    c.beginPath();
    c.moveTo(x+r,y); c.lineTo(x+w-r,y); c.quadraticCurveTo(x+w,y,x+w,y+r);
    c.lineTo(x+w,y+h-r); c.quadraticCurveTo(x+w,y+h,x+w-r,y+h);
    c.lineTo(x+r,y+h); c.quadraticCurveTo(x,y+h,x,y+h-r);
    c.lineTo(x,y+r); c.quadraticCurveTo(x,y,x+r,y);
    c.closePath();
}

function _capEffect(cap) {
    if (cap.outline_enabled && +cap.outline_width > 0) return 'outline';
    if (cap.stroke_enabled  && +cap.stroke_width  > 0) return 'stroke';
    return cap._uiEffect || 'none';
}

function stopCaptionAnim(capId) {
    const st = captionStates[capId];
    if (!st) return;
    if (st.timer) { clearInterval(st.timer); clearTimeout(st.timer); st.timer = null; }
}

function startCaptionAnim(cap) {
    if (cap._extraVPad === undefined) cap._extraVPad = parseInt(cap.rotation) || 0;
    stopCaptionAnim(cap.id);
    const st = captionStates[cap.id] || (captionStates[cap.id] = { show:'', full:'', words:[], karIdx:0, timer:null });
    const text  = cap.text_content || '';
    st.full = text; st.words = text.split(' '); st.karIdx = 0; st.show = '';
    const style = cap.animation_style || 'none';
    const spd   = parseFloat(cap.animation_speed) || 1;
    if (['static','none','fade-in','zoom-in','pop','bounce'].includes(style)) { st.show = text; return; }
    if (style === 'typewriter' || style === 'char-by-char') {
        let i = 0; const ms = Math.round((style==='char-by-char'?60:36)/spd);
        st.timer = setInterval(() => { st.show = text.substring(0,++i); if(i>=text.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'word-reveal') {
        let wi = 0; const ms = Math.round(140/spd);
        st.timer = setInterval(() => { st.show = st.words.slice(0,++wi).join(' '); if(wi>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'line-by-line') {
        const chunk=6, chunks=[];
        for(let i=0;i<st.words.length;i+=chunk) chunks.push(st.words.slice(i,i+chunk).join(' '));
        let ci=0; st.show = chunks[ci++]||'';
        const ms = Math.round(900/spd);
        st.timer = setInterval(() => { if(ci>=chunks.length){clearInterval(st.timer);st.timer=null;return;} st.show=chunks[ci++]; }, ms); return;
    }
    if (style === 'karaoke') {
        st.show = text; st.karIdx = 0;
        const ms = Math.round(320/spd);
        st.timer = setInterval(() => { st.karIdx++; if(st.karIdx>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    st.show = text;
}

function loadSceneCaptions(sceneId) {
    // Stop all previous caption animations
    sceneCaptions.forEach(c => stopCaptionAnim(c.id));
    sceneCaptions = ALL_CAPTIONS.filter(c => +c.story_id === +sceneId);
    sceneCaptions.forEach(c => {
        if (!captionStates[c.id])
            captionStates[c.id] = { show:'', full:'', words:[], karIdx:0, timer:null };
        if (+c.is_visible) startCaptionAnim(c);
    });
}

function drawAllCaptions() {
    sceneCaptions.forEach(cap => drawOneCaption(cap));
    if (selectedCapId) drawSelectionHandles(selectedCapId);
}

function drawSelectionHandles(capId) {
    const cap = sceneCaptions.find(c => +c.id === +capId);
    if (!cap || !cap._bbox) return;
    const {x, y, w, h} = cap._bbox;
    ctx.save();
    ctx.strokeStyle = '#3b82f6'; ctx.lineWidth = 2; ctx.setLineDash([4,3]);
    ctx.strokeRect(x-2, y-2, w+4, h+4);
    ctx.setLineDash([]);
    const hs = 12, off = 2;
    const handles = [
        { cx: x - off - hs/2,     cy: y - off - hs/2,     dir:'nw', arrow:'↖' },
        { cx: x + w + off + hs/2, cy: y - off - hs/2,     dir:'ne', arrow:'↗' },
        { cx: x - off - hs/2,     cy: y + h + off + hs/2, dir:'sw', arrow:'↙' },
        { cx: x + w + off + hs/2, cy: y + h + off + hs/2, dir:'se', arrow:'↘' },
        { cx: x + w + off + hs/2, cy: y + h/2,             dir:'e',  arrow:'↔' },
        { cx: x + w/2,            cy: y + h + off + hs/2, dir:'s',  arrow:'↕' },
    ];
    handles.forEach(({cx, cy, arrow, dir}) => {
        const hx = cx - hs/2, hy = cy - hs/2;
        ctx.fillStyle   = (dir === 's') ? '#10b981' : '#3b82f6';
        ctx.fillRect(hx, hy, hs, hs);
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5;
        ctx.strokeRect(hx+1, hy+1, hs-2, hs-2);
        ctx.fillStyle   = '#fff'; ctx.font = 'bold 8px Inter';
        ctx.textAlign   = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(arrow, cx, cy);
    });
    ctx.restore();
}

function drawOneCaption(cap) {
    if (!+cap.is_visible) return;

    // ── IMAGE CAPTION (logo) ──────────────────────────────────────
    if (cap.caption_type === 'image') {
        const fn = (cap.text_content || '').trim();
        const px = parseInt(cap.position_x) || 20;
        const py = parseInt(cap.position_y) || 20;
        const pw = parseInt(cap.width)      || 120;
        const ph = parseInt(cap.rotation)   || 120; // height stored in rotation column for image caps
        cap._bbox = { x:px, y:py, w:pw, h:ph };

        const cached = imgCache[fn];
        if (cached === null) {
            // Failed — draw grey placeholder
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.25)';
            ctx.fillRect(px, py, pw, ph);
            ctx.restore();
        } else if (cached) {
            ctx.save();
            ctx.drawImage(cached, px, py, pw, ph);
            ctx.restore();
        } else {
            // Not yet loaded — draw placeholder and trigger async load
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.4)';
            ctx.fillRect(px, py, pw, ph);
            ctx.fillStyle = '#fff';
            ctx.font = '11px Inter';
            ctx.textAlign = 'center';
            ctx.fillText('🖼️', px + pw/2, py + ph/2);
            ctx.restore();
            if (!imgCache['_loading_'+fn]) {
                imgCache['_loading_'+fn] = true;
                const imgSrc = fn.startsWith('logo_') ? 'podcast_logos/' + fn : IMG_FOLDER + '/' + fn;
                const im = new Image();
                im.crossOrigin = 'anonymous';
                im.onload  = () => { imgCache[fn] = im; delete imgCache['_loading_'+fn]; };
                im.onerror = () => { imgCache[fn] = null; delete imgCache['_loading_'+fn]; };
                im.src = imgSrc;
            }
        }
        return;
    }

    // ── TEXT CAPTION (main / header / footer) ────────────────────
    const st = captionStates[cap.id];
    if (!st) return;
    const text = st.show || '';
    if (!text.trim()) { cap._bbox = null; return; }

    const fs        = parseInt(cap.fontsize) || 22;
    const extraVPad = cap._extraVPad ?? parseInt(cap.rotation) ?? 0;
    const pad       = 10 + Math.round(extraVPad / 2);
    const lh        = fs + 7;
    const maxW      = parseInt(cap.width)      || 320;
    const posX      = parseInt(cap.position_x) || 20;
    const posY      = parseInt(cap.position_y) || 400;
    const tAlign    = cap.text_align   || 'center';
    const bold      = (cap.fontweight === 'bold' || cap.fontweight === '700') ? 'bold ' : '';
    const italic    = cap.fontstyle === 'italic' ? 'italic ' : '';
    const rawFamily = cap.fontfamily || '';
    const family    = FONT_NORM[rawFamily] || rawFamily || 'Arial,sans-serif';

    ctx.save();
    ctx.font = italic + bold + fs + 'px ' + family;

    // Word-wrap
    const paragraphs = text.split('\n');
    const lines = [];
    paragraphs.forEach(para => {
        const trimmed = para.trim();
        if (!trimmed) { lines.push(''); return; }
        const words = trimmed.split(' ');
        let ln = '';
        words.forEach(w => {
            const t = ln ? ln + ' ' + w : w;
            if (ctx.measureText(t).width > maxW && ln) { lines.push(ln); ln = w; } else ln = t;
        });
        if (ln) lines.push(ln);
    });

    const bh = lines.length * lh + pad * 2;
    const bw = maxW;
    cap._bbox = { x: posX, y: posY, w: bw, h: bh };

    const bgOn     = (cap.bg_enabled === 1 || cap.bg_enabled === '1' || cap.bg_enabled === true);
    const bdrThick = parseInt(cap.caption_box_border_thickness) || 0;
    const bdrColor = cap.caption_box_border_color || '#ffffff';

    if (bgOn || bdrThick > 0) {
        ctx.save();
        rrect(ctx, posX, posY, bw, bh, 10);
        if (bgOn) {
            const br = parseInt((cap.bg_color || '#000000').slice(1,3), 16);
            const bg = parseInt((cap.bg_color || '#000000').slice(3,5), 16);
            const bb = parseInt((cap.bg_color || '#000000').slice(5,7), 16);
            ctx.fillStyle = `rgba(${br},${bg},${bb},${parseFloat(cap.bg_opacity) || 0.7})`;
            ctx.fill();
        }
        if (bdrThick > 0) {
            ctx.strokeStyle = bdrColor;
            ctx.lineWidth   = bdrThick;
            ctx.lineJoin    = 'round';
            ctx.stroke();
        }
        ctx.restore();
    }

    let tx, ta;
    if      (tAlign === 'left')  { tx = posX + pad;      ta = 'left';   }
    else if (tAlign === 'right') { tx = posX + bw - pad; ta = 'right';  }
    else                         { tx = posX + bw / 2;   ta = 'center'; }
    ctx.textAlign = ta;

    const fx = _capEffect(cap);
    let gradFill = null;
    if (fx === 'gradient') {
        const gr = ctx.createLinearGradient(posX, 0, posX + bw, 0);
        gr.addColorStop(0, '#ff6b6b'); gr.addColorStop(.33, '#ffd93d');
        gr.addColorStop(.66, '#6bcb77'); gr.addColorStop(1, '#4d96ff');
        gradFill = gr;
    }

    lines.forEach((line, i) => {
        const ty = posY + pad + fs + i * lh;
        ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
        if      (fx === 'shadow') { ctx.shadowColor='rgba(0,0,0,.95)'; ctx.shadowBlur=8; ctx.shadowOffsetX=2; ctx.shadowOffsetY=2; }
        else if (fx === 'glow')   { ctx.shadowColor=cap.fontcolor||'#fff'; ctx.shadowBlur=22; }
        else if (fx === '3d')     { ctx.shadowColor='rgba(0,0,0,.65)'; ctx.shadowOffsetX=3; ctx.shadowOffsetY=3; }
        if (fx === 'outline' || fx === 'stroke') {
            ctx.shadowBlur=0; ctx.shadowOffsetX=0; ctx.shadowOffsetY=0;
            ctx.strokeStyle = cap.stroke_color || '#000';
            ctx.lineWidth   = (parseInt(cap.stroke_width) || 2) * 2;
            ctx.lineJoin    = 'round';
            ctx.strokeText(line, tx, ty);
        }
        ctx.fillStyle = gradFill || (cap.fontcolor || '#ffffff');
        ctx.fillText(line, tx, ty);
        if (cap.underline) {
            const tw = ctx.measureText(line).width;
            const ux = ta==='center' ? tx-tw/2 : ta==='right' ? tx-tw : tx;
            ctx.beginPath(); ctx.moveTo(ux,ty+2); ctx.lineTo(ux+tw,ty+2);
            ctx.strokeStyle=cap.fontcolor||'#fff'; ctx.lineWidth=1; ctx.stroke();
        }
    });
    ctx.restore();
}



function onReview() { console.log('Review — coming soon'); }

// ── Generate (calls VPS stitch) ───────────────────────────────────────────────
let _vsRecURL    = null;
let _vsRecFname  = null;
let _vsPodcastId = PODCAST_ID;
const VPS_STITCH_URL = 'http://187.124.249.46/videovizard.com/vps_ffmpeg_stitch.php';

async function onGenerate() {
    const btn = document.getElementById('btnGenerate');
    if (btn) btn.disabled = true;

    // Show spinner overlay
    const ov    = document.getElementById('generateOverlay');
    const title = document.getElementById('generateSpinnerTitle');
    const msg   = document.getElementById('generateSpinnerMsg');
    const timer = document.getElementById('generateSpinnerTimer');
    if (ov) ov.style.display = 'flex';
    if (title) title.textContent = '⚡ Generating your video…';
    if (msg) msg.innerHTML = 'Encoding scenes · Burning captions · Mixing audio<br>This may take 30–60 seconds';

    const t0 = Date.now();
    const tick = setInterval(() => {
        const s = Math.floor((Date.now() - t0) / 1000);
        const m = Math.floor(s / 60);
        if (timer) timer.textContent = '⏱ ' + (m > 0 ? m + 'm ' : '') + (s % 60) + 's elapsed';
        // Update message after a while
        if (s === 15 && msg) msg.innerHTML = 'Downloading scene videos · Processing audio…<br>Almost there, please wait';
        if (s === 35 && msg) msg.innerHTML = 'Mixing music · Finalising MP4…<br>Nearly done!';
    }, 1000);

    try {
        const fd = new FormData();
        fd.append('action',     'start_stitch');
        fd.append('podcast_id', PODCAST_ID);
        fd.append('base_url',   window.location.origin + '/');
        fd.append('secret_key', 'VS_FFmpeg_2026_Secret!');
        fd.append('admin_id',   '<?= $admin_id ?>');

        const res  = await fetch(VPS_STITCH_URL, { method:'POST', body:fd });
        const data = await res.json();
        clearInterval(tick);
        if (ov) ov.style.display = 'none';

        if (!data.success) {
            alert('❌ Generation failed: ' + (data.error || 'Unknown error'));
            if (btn) btn.disabled = false;
            return;
        }

        const filename = data.mp4_url ? data.mp4_url.split('/').pop() : ('podcast_' + PODCAST_ID + '.mp4');
        openSchedModalWithMp4(data.mp4_url || ('published_videos/' + filename), filename, data.mp4_size_mb || '?');

    } catch(e) {
        clearInterval(tick);
        if (ov) ov.style.display = 'none';
        alert('❌ Network error: ' + e.message);
    }
    if (btn) btn.disabled = false;
}

// ── Schedule modal ────────────────────────────────────────────────────────────
function openSchedModalWithMp4(mp4Url, filename, sizeMb) {
    closeSchedModal();
    _vsRecURL = mp4Url; _vsRecFname = filename;
    window._mp4Ready = true; window._mp4Url = mp4Url; window._mp4Filename = filename;

    const savedEl = document.getElementById('vsFilenameDisplay');
    if (savedEl) savedEl.innerHTML =
        `<div class="bpm-saved-dot"></div><span>Video ready — <strong>${filename}</strong> · ${sizeMb} MB ✅ MP4</span>`;

    const subEl = document.getElementById('vsSubTitle');
    if (subEl) subEl.textContent = '<?= addslashes($row['title'] ?? 'Your Video') ?>';

    document.getElementById('vsMain').style.display    = 'block';
    document.getElementById('vsConfirm').style.display = 'none';
    document.getElementById('vsWarn').style.display    = 'none';
    _vsSetDefaultDate(); _vsPopulateCaption();
    document.getElementById('vsOverlay').classList.add('open');
}

function closeSchedModal() {
    document.getElementById('vsOverlay')?.classList.remove('open');
}

function _vsPopulateCaption() {
    const fd = new FormData();
    fd.append('ajax_action','get_podcast_caption_data'); fd.append('podcast_id', PODCAST_ID);
    fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(!d.success)return;
        document.getElementById('vsCaption').value  = d.caption_text||'';
        document.getElementById('vsKeywords').value = d.keywords||'';
        document.getElementById('vsHashtags').value = d.hashtags||'';
    }).catch(()=>{});
}

function _vsSetDefaultDate() {
    const tomorrowBtn = document.querySelectorAll('#vsOverlay .bpm-qpill')[2];
    if (tomorrowBtn) vsQuick(tomorrowBtn, 24);
}

function vsSwitchTab(tab, btn) {
    document.querySelectorAll('#vsOverlay .bpm-ctab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#vsOverlay .bpm-ctab-panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('vs-tab-'+tab).classList.add('active');
}

function vsTogglePlat(el) {
    if(el.classList.contains('disconnected'))return;
    el.classList.toggle('sel');
    document.getElementById('vsWarn').style.display='none';
}

function vsGetPlats() {
    return [...document.querySelectorAll('#vsOverlay .bpm-plat.sel:not(.disconnected)')].map(el=>el.dataset.p);
}

function vsQuick(btn, hrs) {
    document.querySelectorAll('#vsOverlay .bpm-qpill').forEach(p=>p.classList.remove('active'));
    if(btn)btn.classList.add('active');
    const d=new Date(); d.setHours(d.getHours()+hrs);
    document.getElementById('vsDate').value=d.toISOString().split('T')[0];
    document.getElementById('vsTime').value=d.toTimeString().slice(0,5);
}

function vsDownload() {
    const a=document.createElement('a');
    a.href     = window._mp4Url||('published_videos/podcast_'+PODCAST_ID+'.mp4');
    a.download = window._mp4Filename||('podcast_'+PODCAST_ID+'.mp4');
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    closeSchedModal();
}

function vsPostNow() {
    const plats=vsGetPlats();
    if(!plats.length){document.getElementById('vsWarn').style.display='block';return;}
    _vsSave('now',plats,null);
}

function vsSchedule() {
    const plats=vsGetPlats();
    if(!plats.length){document.getElementById('vsWarn').style.display='block';return;}
    const date=document.getElementById('vsDate').value;
    const time=document.getElementById('vsTime').value;
    if(!date||!time){alert('Please select a date and time');return;}
    _vsSave('scheduled',plats,new Date(date+'T'+time));
}

async function _vsSave(type, plats, dt) {
    const payload={
        podcast_id:_vsPodcastId, platforms:plats,
        caption:document.getElementById('vsCaption').value,
        keywords:document.getElementById('vsKeywords').value,
        hashtags:document.getElementById('vsHashtags').value,
        sched_date:dt?dt.toISOString().split('T')[0]:new Date().toISOString().split('T')[0],
        sched_time:dt?dt.toTimeString().slice(0,5):new Date().toTimeString().slice(0,5),
        post_type:type, video_filename:_vsRecFname,
    };
    try {
        const r=await fetch('social_schedule.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data=await r.json();
        if(data.success)_vsShowConfirm(type,plats,dt); else alert('Error: '+(data.error||'Unknown'));
    } catch(e){ _vsShowConfirm(type,plats,dt); }
}

function _vsShowConfirm(type, plats, dt) {
    document.getElementById('vsMain').style.display='none';
    document.getElementById('vsConfirm').style.display='block';
    const labels={instagram:'📸 Instagram',tiktok:'🎵 TikTok',youtube:'▶️ YouTube',facebook:'📘 Facebook',twitter:'🐦 X',linkedin:'💼 LinkedIn'};
    if(type==='now'){
        document.getElementById('vsConfirmIcon').textContent='🎉';
        document.getElementById('vsConfirmTitle').textContent='Posted!';
        document.getElementById('vsConfirmSub').textContent='Going live now';
    } else {
        const ds=dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
        const ts=dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
        document.getElementById('vsConfirmIcon').textContent='🗓';
        document.getElementById('vsConfirmTitle').textContent='Scheduled!';
        document.getElementById('vsConfirmSub').textContent=`Posts ${ds} at ${ts}`;
    }
    document.getElementById('vsConfirmPills').innerHTML=
        plats.map(p=>`<span class="bpm-confirm-pill">${labels[p]||p}</span>`).join('');
}

document.addEventListener('DOMContentLoaded',()=>{
    document.getElementById('vsOverlay')?.addEventListener('click',function(e){
        if(e.target===this)closeSchedModal();
    });
});
function onFont() {
    const sub  = document.getElementById('fontSubIcons');
    const btn  = document.getElementById('ibFont');
    const open = sub.style.display !== 'none';
    // Close caption + audio sub-icons
    document.getElementById('capSubIcons').style.display  = 'none';
    document.getElementById('audioSubIcons').style.display = 'none';
    document.getElementById('ibCaption')?.classList.remove('active');
    document.getElementById('ibAudio')?.classList.remove('active');
    closeCapOverlay(); closeAudioOverlay();
    if (open) {
        sub.style.display = 'none';
        btn.classList.remove('active');
        closeFontOverlay();
    } else {
        sub.style.display = 'flex';
        btn.classList.add('active');
    }
}

let _activeFontOv = null;

function openFontOverlay(which) {
    ['fontSubFont','fontSubStyle','fontSubAlign'].forEach(id => {
        document.getElementById(id)?.classList.toggle('active',
            (which==='font'&&id==='fontSubFont')||(which==='style'&&id==='fontSubStyle')||(which==='align'&&id==='fontSubAlign'));
    });
    ['fontOvFont','fontOvStyle','fontOvAlign'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    const map = { font:'fontOvFont', style:'fontOvStyle', align:'fontOvAlign' };
    document.getElementById(map[which])?.classList.add('open');
    _activeFontOv = which;
    const hasCaption = !!selectedCapId;
    // Show/hide no-sel vs editor
    document.getElementById('fontOvNoSel1').style.display  = hasCaption ? 'none'  : 'block';
    document.getElementById('fontOvEditor1').style.display = hasCaption ? 'block' : 'none';
    document.getElementById('fontOvNoSel2').style.display  = hasCaption ? 'none'  : 'block';
    document.getElementById('fontOvEditor2').style.display = hasCaption ? 'block' : 'none';
    document.getElementById('fontOvNoSel3').style.display  = hasCaption ? 'none'  : 'block';
    document.getElementById('fontOvEditor3').style.display = hasCaption ? 'block' : 'none';
    if (hasCaption) {
        const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
        if (cap) _populateFontEditor(cap);
    }
    const ov = document.getElementById(map[which]);
    if (ov) ov.onclick = e => { if (e.target === ov) closeFontOverlay(); };
}

function closeFontOverlay() {
    ['fontOvFont','fontOvStyle','fontOvAlign'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    _activeFontOv = null;
}

function _populateFontEditor(cap) {
    // Font family picker
    const ff  = document.getElementById('capFont');
    const lbl = document.getElementById('fontPickerLabel');
    if (ff) ff.value = cap.fontfamily || 'Arial,sans-serif';
    if (lbl) {
        const opt = document.querySelector(`.fp-opt[data-val="${CSS.escape(cap.fontfamily || 'Arial,sans-serif')}"]`);
        lbl.textContent      = opt ? opt.cloneNode(true).textContent.trim().split('\n')[0].trim() : (cap.fontfamily || 'Arial').split(',')[0].replace(/'/g,'');
        lbl.style.fontFamily = cap.fontfamily || 'Arial,sans-serif';
    }
    document.querySelectorAll('.fp-opt').forEach(o =>
        o.classList.toggle('selected', o.dataset.val === (cap.fontfamily || 'Arial,sans-serif')));
    // Size
    const fs = document.getElementById('capSize');
    if (fs) {
        let matched = false;
        Array.from(fs.options).forEach(o => { o.selected = o.value == cap.fontsize; if(o.selected) matched=true; });
        if (!matched) {
            const target = parseInt(cap.fontsize)||22;
            let best=null, bestDiff=Infinity;
            Array.from(fs.options).forEach(o=>{ const d=Math.abs(parseInt(o.value)-target); if(d<bestDiff){bestDiff=d;best=o;} });
            if (best) best.selected=true;
        }
    }
    // Color
    const cc = document.getElementById('capColor');
    if (cc) cc.value = _toHex(cap.fontcolor || '#ffffff');
    // Style toggles
    document.getElementById('capBold')     ?.classList.toggle('on', cap.fontweight==='bold'||cap.fontweight==='700');
    document.getElementById('capItalic')   ?.classList.toggle('on', cap.fontstyle==='italic');
    document.getElementById('capUnderline')?.classList.toggle('on', !!+cap.underline);
    // Effect
    const ef = document.getElementById('capEffect');
    if (ef) { const cur=_capEffect(cap); Array.from(ef.options).forEach(o=>o.selected=o.value===cur); }
    const scf = document.getElementById('capStrokeColorField');
    if (scf) scf.style.display = (['outline','stroke'].includes(_capEffect(cap))) ? 'flex' : 'none';
    const sc = document.getElementById('capStrokeColor');
    if (sc) sc.value = _toHex(cap.stroke_color||'#000000');
    // Animation
    const ca = document.getElementById('capAnim');
    if (ca) Array.from(ca.options).forEach(o=>o.selected=o.value===(cap.animation_style||'none'));
    const cas = document.getElementById('capAnimSpeed');
    const csv = document.getElementById('capAnimSpeedVal');
    if (cas) { cas.value=Math.min(4,Math.max(0.2,parseFloat(cap.animation_speed)||1)); if(csv) csv.textContent=parseFloat(cas.value).toFixed(1)+'x'; }
    // Text align
    ['left','center','right','justify'].forEach(a =>
        document.getElementById('capTa'+a.charAt(0).toUpperCase()+a.slice(1))?.classList.toggle('on', a===(cap.text_align||'center')));
}

// ── Font picker ───────────────────────────────────────────────────────────────
function toggleFontPicker() {
    const dd = document.getElementById('fontPickerDropdown');
    if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
function selectFont(val, label, el) {
    const ff  = document.getElementById('capFont');
    const lbl = document.getElementById('fontPickerLabel');
    if (ff)  ff.value = val;
    if (lbl) { lbl.textContent = label; lbl.style.fontFamily = val; }
    document.querySelectorAll('.fp-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('fontPickerDropdown').style.display = 'none';
    capFieldChanged('fontfamily', val);
}
// Delegated click for fp-opt
document.addEventListener('click', function(e) {
    const opt = e.target.closest('.fp-opt');
    if (opt) {
        const val = opt.dataset.val; if (!val) return;
        const clone = opt.cloneNode(true);
        clone.querySelectorAll('span').forEach(s => s.remove());
        selectFont(val, clone.textContent.trim() || val.split(',')[0].replace(/'/g,''), opt);
        return;
    }
    // Close picker when clicking outside
    const wrap = document.getElementById('fontPickerWrap');
    if (wrap && !wrap.contains(e.target)) {
        const dd = document.getElementById('fontPickerDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ── Style / align / effect functions ─────────────────────────────────────────
function setCapTextColor(hex) {
    const inp = document.getElementById('capColor'); if(inp) inp.value=hex;
    capFieldChanged('fontcolor', hex);
}
function toggleCapStyle(s) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c=>+c.id===+selectedCapId); if(!cap) return;
    if (s==='bold') {
        const now=(cap.fontweight==='bold'||cap.fontweight==='700');
        cap.fontweight=now?'normal':'bold';
        document.getElementById('capBold')?.classList.toggle('on',!now);
        capFieldChanged('fontweight',cap.fontweight);
    } else if (s==='italic') {
        const now=cap.fontstyle==='italic';
        cap.fontstyle=now?'normal':'italic';
        document.getElementById('capItalic')?.classList.toggle('on',!now);
        capFieldChanged('fontstyle',cap.fontstyle);
    } else if (s==='underline') {
        cap.underline=cap.underline?0:1;
        document.getElementById('capUnderline')?.classList.toggle('on',!!cap.underline);
        capFieldChanged('underline',cap.underline);
    }
}
function setCapTA(a) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c=>+c.id===+selectedCapId); if(!cap) return;
    cap.text_align=a;
    ['left','center','right','justify'].forEach(n =>
        document.getElementById('capTa'+n.charAt(0).toUpperCase()+n.slice(1))?.classList.toggle('on',n===a));
    capFieldChanged('text_align',a);
}
function capEffectChanged(val) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c=>+c.id===+selectedCapId); if(!cap) return;
    cap._uiEffect=val;
    cap.stroke_enabled=0; cap.outline_enabled=0;
    if (val==='stroke') { cap.stroke_enabled=1; cap.stroke_width=cap.stroke_width||2; capFieldChanged('stroke_enabled',1); capFieldChanged('stroke_width',cap.stroke_width); }
    else if (val==='outline') { cap.outline_enabled=1; cap.outline_width=cap.outline_width||2; capFieldChanged('outline_enabled',1); capFieldChanged('outline_width',cap.outline_width); }
    else { capFieldChanged('stroke_enabled',0); capFieldChanged('outline_enabled',0); }
    const scf = document.getElementById('capStrokeColorField');
    if (scf) scf.style.display = (val==='outline'||val==='stroke') ? 'flex' : 'none';
}

// Repopulate font overlays when caption changes
function _syncFontOverlay(cap) {
    if (_activeFontOv && cap) _populateFontEditor(cap);
}
// ── Media overlay ─────────────────────────────────────────────────────────────
let _mediaPromptTimer = null;
let _libImgs=[], _libVids=[], _libMine=[], _libSelectedFile=null, _libTab='all';

function onMedia() {
    const btn = document.getElementById('ibMedia');
    const ov  = document.getElementById('mediaOverlay');
    if (ov.classList.contains('open')) {
        closeMediaOverlay(); return;
    }
    // Close other sub-icons/overlays
    document.getElementById('capSubIcons').style.display='none';
    document.getElementById('ibCaption')?.classList.remove('active');
    document.getElementById('fontSubIcons').style.display='none';
    document.getElementById('ibFont')?.classList.remove('active');
    closeCapOverlay(); closeFontOverlay();
    btn?.classList.add('active');
    ov.classList.add('open');
    ov.onclick = e => { if (e.target===ov) closeMediaOverlay(); };
    _populateMediaOverlay();
}

function closeMediaOverlay() {
    document.getElementById('mediaOverlay')?.classList.remove('open');
    document.getElementById('ibMedia')?.classList.remove('active');
}

function _populateMediaOverlay() {
    const sc  = SCENES[currentIndex];
    const num = document.getElementById('mediaSceneNum');
    if (num) num.textContent = currentIndex + 1;
    // Prompt
    const ta = document.getElementById('mediaVideoPrompt');
    if (ta) ta.value = sc.prompt || '';
    // Thumbnail
    const fn = (sc.image_file || '').trim();
    const folder = (sc.image_folder || IMG_FOLDER).replace(/\/?$/,'/');
    const thumb  = document.getElementById('mediaCurrThumb');
    const ph     = document.getElementById('mediaCurrPh');
    const nm     = document.getElementById('mediaCurrName');
    if (thumb) {
        thumb.innerHTML = '';
        if (fn) {
            const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
            if (isVid) {
                const v = document.createElement('video');
                v.src=folder+fn; v.muted=true; v.playsInline=true; v.preload='metadata';
                v.style.cssText='width:100%;height:100%;object-fit:cover;display:block;';
                thumb.appendChild(v);
            } else {
                const i = document.createElement('img');
                i.src=folder+fn+'?t='+Date.now(); i.alt='';
                i.style.cssText='width:100%;height:100%;object-fit:cover;display:block;';
                thumb.appendChild(i);
            }
            if (nm) nm.textContent = fn;
        } else {
            const s = document.createElement('span');
            s.textContent='🎬'; s.style.cssText='font-size:28px;color:rgba(255,255,255,.4);';
            thumb.appendChild(s);
            if (nm) nm.textContent = '';
        }
    }
    // Credit display
    document.getElementById('creditBalanceDisplay').textContent = USER_CREDIT.toFixed(2);
}

function _mediaPromptChanged() {
    clearTimeout(_mediaPromptTimer);
    _mediaPromptTimer = setTimeout(async () => {
        const sc  = SCENES[currentIndex];
        const ta  = document.getElementById('mediaVideoPrompt');
        const val = ta ? ta.value.trim() : '';
        sc.prompt = val;
        const st = document.getElementById('mediaPromptSaveStatus');
        if (st) st.textContent = '⏳ Saving…';
        const fd = new FormData();
        fd.append('ajax_action','save_prompt');
        fd.append('scene_id', sc.id);
        fd.append('prompt_field','prompt');
        fd.append('prompt', val);
        await fetch(location.href,{method:'POST',body:fd});
        if (st) { st.textContent='✅ Saved'; setTimeout(()=>{ st.textContent=''; },1500); }
    }, 800);
}

// ── Upload ────────────────────────────────────────────────────────────────────
function mediaUpload() {
    const inp = document.getElementById('mediaFileInput');
    if (inp) { inp.value=''; inp.click(); }
}

async function handleMediaUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file  = input.files[0];
    const sc    = SCENES[currentIndex];
    const isVid = file.type.startsWith('video/');
    const btn   = document.getElementById('mediaUploadBtn');
    if (btn) { btn.disabled=true; btn.innerHTML='<span style="font-size:20px;">⏳</span>Uploading…'; }
    const fd = new FormData();
    fd.append('ajax_action',  'upload_scene_image');
    fd.append('scene_id',     sc.id);
    fd.append('image_field',  'image_file');
    fd.append('media_type',   isVid ? 'video' : 'image');
    fd.append('scene_image',  file);
    // Tell server to store in user's personal folder
    fd.append('target_folder', USER_MEDIA_FOLDER.replace(/\/?$/,''));
    try {
        const r    = await fetch(location.href,{method:'POST',body:fd});
        const data = await r.json();
        if (!data.success) throw new Error(data.message||'Upload failed');
        // Update scene: file + folder (image_folder in DB now points to user folder)
        sc.image_file   = data.filename;
        sc.image_folder = data.image_folder || USER_MEDIA_FOLDER.replace(/\/?$/,'').replace(/^user_media\//,'');
        FILE_FOLDER[data.filename] = sc.image_folder.replace(/\/?$/,'/');
        await loadScene(currentIndex);
        _populateMediaOverlay();
    } catch(e) { alert('Upload failed: '+e.message); }
    finally {
        if (btn) { btn.disabled=false; btn.innerHTML='<span style="font-size:24px;">📤</span>Upload'; }
    }
}


// ── Library ───────────────────────────────────────────────────────────────────
function mediaLibrary() {
    const sc = SCENES[currentIndex];
    let q = '';
    if (sc.natural_language_tags) q = sc.natural_language_tags.trim().split('|')[0].trim();
    else if (sc.hashtags)         q = sc.hashtags.trim().split(/\s+/)[0].replace(/^#/,'');
    const inp = document.getElementById('libSearch');
    if (inp) inp.value = q;
    const modal = document.getElementById('libModal');
    if (modal) modal.style.display = 'flex';
    const st = document.getElementById('libSearchStatus');
    if (st) { st.style.display='block'; st.textContent = q ? `🔍 "${q.substring(0,40)}"…` : '📂 Loading…'; }
    _libSelectedFile = null;
    _resetLibSel();
    if (q) performLibSearch(); else _loadRecentLibFiles();
}

function closeLibraryModal() {
    const modal = document.getElementById('libModal');
    if (modal) modal.style.display = 'none';
    _libSelectedFile = null;
}

function _resetLibSel() {
    const btn  = document.getElementById('libUseBtn');
    const info = document.getElementById('libSelInfo');
    if (btn)  { btn.disabled=true; btn.style.opacity='.4'; }
    if (info) info.textContent = 'No file selected';
}

function _updateLibCounts() {
    const a=document.getElementById('libCountAll'), im=document.getElementById('libCountImg');
    const v=document.getElementById('libCountVid'), m=document.getElementById('libCountMine');
    const total = _libImgs.length + _libVids.length;
    if(a)  a.textContent  = total;
    if(im) im.textContent = _libImgs.length;
    if(v)  v.textContent  = _libVids.length;
    if(m)  m.textContent  = _libMine.length;
    const st = document.getElementById('libSearchStatus');
    if (st && total > 0) {
        st.textContent    = `✅ ${_libImgs.length} images · ${_libVids.length} videos`;
        st.style.display  = 'inline';
    }
}

async function _loadRecentLibFiles() {
    const grid = document.getElementById('libGrid');
    if(grid) grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">📂 Loading recent media…</div>';
    try {
        const fd=new FormData();
        fd.append('ajax_action','get_library_files');
        fd.append('limit','60'); // cap at 60 — no need to load 300 at once
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        const files=(data.files||data.results||data.items||[]).slice(0,60);
        // Normalise: some responses use type, some media_type
        files.forEach(f=>{ if(!f.media_type && f.type) f.media_type=f.type; });
        _libImgs=files.filter(f=>f.media_type!=='video').map(f=>({...f, score:0, matched_segment:'', matched_line:''}));
        _libVids=files.filter(f=>f.media_type==='video').map(f=>({...f,  score:0, matched_segment:'', matched_line:''}));
        _updateLibCounts(); _renderLibGrid();
    } catch(e) {
        if(grid) grid.innerHTML='<div style="grid-column:1/-1;color:var(--danger);text-align:center;padding:20px;">Failed to load</div>';
    }
}

async function _loadMyUploads() {
    const grid=document.getElementById('libGrid');
    if(grid) grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading your media…</div>';
    try {
        const fd=new FormData(); fd.append('ajax_action','get_user_media');
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(!data.has_folder||!data.files||!data.files.length){
            _libMine=[];
            if(grid) grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);">
                <div style="font-size:36px;margin-bottom:10px;">🗂️</div>
                <div style="font-weight:600;margin-bottom:6px;">No personal media yet</div>
                <div style="font-size:11px;line-height:1.6;">Use <strong>📤 Upload</strong> to add files to your folder.<br>
                <span style="opacity:.55;font-size:10px;">${USER_MEDIA_FOLDER}</span></div></div>`;
            return;
        }
        window._userMediaFolder=(data.folder||USER_MEDIA_FOLDER).replace(/\/?$/,'/');
        _libMine=data.files.map(f=>({filename:f.filename,media_type:f.media_type||'image',nl_tags:'',score:0,thumbnail:f.thumbnail||'',is_user_media:true,matched_segment:'',matched_line:''}));
        _updateLibCounts(); _renderLibGrid();
    } catch(e) {
        if(grid) grid.innerHTML='<div style="grid-column:1/-1;color:var(--danger);text-align:center;padding:20px;">Failed to load your media</div>';
    }
}

async function _searchWithQuery(query) {
    const grid=document.getElementById('libGrid');
    if(grid) grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Searching…</div>';
    const st=document.getElementById('libSearchStatus');
    if(st){ st.style.display='block'; st.textContent=`Searching: "${query.substring(0,50)}"…`; }
    try {
        const fd=new FormData();
        fd.append('ajax_action','search_media_nl');
        fd.append('query',query);
        fd.append('media_type_filter',_libTab==='image'?'image':_libTab==='video'?'video':'');
        fd.append('tab_type',_libTab==='mine'?'mine':'all');
        const r=await fetch(location.href,{method:'POST',body:fd});
        const results=await r.json();
        const list=Array.isArray(results)?results:(results.results||results.items||[]);
        // API may return type OR media_type — normalise
        list.forEach(f=>{ if(!f.media_type && f.type) f.media_type=f.type; });
        _libImgs=list.filter(f=>f.media_type!=='video');
        _libVids=list.filter(f=>f.media_type==='video');
        _updateLibCounts(); _renderLibGrid();
        if(st) st.textContent=list.length?`✅ ${_libImgs.length} images · ${_libVids.length} videos`:'❌ No results found';
    } catch(e) {
        console.error('Search error:',e);
        if(grid) grid.innerHTML='<div style="grid-column:1/-1;color:var(--danger);text-align:center;padding:20px;">Search failed — try again</div>';
    }
}

async function performLibSearch() {
    const q=(document.getElementById('libSearch')?.value||'').trim();
    if(!q){ _loadRecentLibFiles(); return; }
    await _searchWithQuery(q);
}

function setLibTab(type) {
    _libTab=type;
    ['all','image','video','mine'].forEach(t=>{
        const ids={all:'libTabAll',image:'libTabImg',video:'libTabVid',mine:'libTabMine'};
        document.getElementById(ids[t])?.classList.toggle('active',t===type);
    });
    if(type==='mine'){ _loadMyUploads(); return; }
    const q=(document.getElementById('libSearch')?.value||'').trim();
    if(q) _searchWithQuery(q); else _loadRecentLibFiles();
}

function _renderLibGrid() {
    const grid = document.getElementById('libGrid');
    if (!grid) return;

    const files = _libTab==='video' ? _libVids
                : _libTab==='image' ? _libImgs
                : _libTab==='mine'  ? _libMine
                : [..._libImgs, ..._libVids];

    if (!files.length) {
        const icon = _libTab==='mine' ? '🗂️' : _libTab==='video' ? '🎬' : '🖼️';
        const msg  = _libTab==='mine' ? 'No personal media — use 📤 Upload'
                   : 'No ' + (_libTab==='all' ? 'media' : _libTab+'s') + ' found';
        grid.innerHTML = `<div style="text-align:center;padding:60px 20px;color:#94a3b8;">
            <div style="font-size:36px;margin-bottom:10px;">${icon}</div><div>${msg}</div></div>`;
        return;
    }

    // Flex-wrap layout — 3 cards per row, explicit pixel sizes, zero ambiguity
    const CARD_W = 130;  // px — 3 cols fit in ~420px inner width
    const CARD_H = 230;  // px — 9:16 of 130 = 231

    const cards = files.map((f, i) => {
        const isVid  = f.media_type === 'video';
        const score  = f.score || 0;
        const folder = f.is_user_media ? (window._userMediaFolder || USER_MEDIA_FOLDER) : getFileFolder(f.filename);
        const safeFolder = folder.replace(/'/g, "\\'");
        const safeName   = f.filename.replace(/'/g, "\\'");

        let borderC = '#334155';
        if      (score >= 0.5)  borderC = '#10b981';
        else if (score >= 0.35) borderC = '#f59e0b';
        else if (score > 0)     borderC = '#ef4444';

        const thumb      = (f.thumbnail || '').trim();
        const mediaSrc   = isVid ? (thumb ? `podcast_thumbnails/${thumb}` : '')
                                 : (thumb ? `podcast_thumbnails/${thumb}` : folder + f.filename);
        const fallbackSrc = !isVid ? folder + f.filename : '';

        const scoreHtml = score > 0
            ? `<div style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.8);color:#fff;padding:1px 5px;border-radius:5px;font-size:9px;font-weight:700;">${score>=0.5?'🟢':score>=0.35?'🟡':'🔴'} ${Math.round(score*100)}%</div>` : '';
        const vidHtml = isVid
            ? `<div style="position:absolute;top:4px;left:4px;background:rgba(0,0,0,.8);color:#fff;padding:1px 5px;border-radius:5px;font-size:8px;font-weight:600;">🎬</div>` : '';

        const imgHtml = mediaSrc
            ? `<img data-src="${mediaSrc}" ${fallbackSrc && fallbackSrc!==mediaSrc ? `data-fallback="${fallbackSrc}"` : ''} class="lib-lazy" alt=""
                style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .3s;">`
            : `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:32px;color:rgba(255,255,255,.2);">${isVid?'🎬':'🖼️'}</div>`;

        const seg = (f.matched_segment || '').trim();
        const tag = seg ? seg.substring(0,22) : (f.is_user_media ? '👤 ' : '') + f.filename.substring(0,18);

        return `<div onclick="pickLibFile(this,'${safeName}','${f.media_type||'image'}','${safeFolder}')"
            data-folder="${folder}" id="libItem_${i}"
            style="width:${CARD_W}px;flex-shrink:0;border-radius:8px;overflow:hidden;
                   border:2px solid ${borderC};cursor:pointer;background:#0f172a;
                   margin:4px;display:inline-block;vertical-align:top;">
            <div style="position:relative;width:${CARD_W}px;height:${CARD_H}px;overflow:hidden;background:#0f172a;">
                ${imgHtml}${scoreHtml}${vidHtml}
                <div class="lib-check" style="position:absolute;bottom:5px;right:5px;background:#10b981;color:#fff;width:20px;height:20px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:11px;font-weight:700;z-index:20;">✓</div>
            </div>
            <div style="padding:3px 5px;background:#f1f5f9;font-size:8px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${tag}</div>
        </div>`;
    }).join('');

    grid.innerHTML = `<div style="display:flex;flex-wrap:wrap;margin:-4px;">${cards}</div>`;

    // Lazy load
    _initLibLazyLoad(grid);
}

let _libLazyObserver = null;
function _initLibLazyLoad(grid) {
    if (_libLazyObserver) { _libLazyObserver.disconnect(); _libLazyObserver = null; }
    const scrollEl = grid.parentElement; // the scrollable container
    _libLazyObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const img = entry.target;
            const src = img.dataset.src;
            if (!src) { _libLazyObserver.unobserve(img); return; }
            img.src = src;
            img.onload  = () => { img.style.opacity = '1'; };
            img.onerror = () => {
                const fb = img.dataset.fallback;
                if (fb && img.src !== fb) {
                    img.src = fb;
                    img.onload  = () => { img.style.opacity = '1'; };
                    img.onerror = () => { img.style.display = 'none'; };
                } else {
                    img.style.display = 'none';
                }
            };
            _libLazyObserver.unobserve(img);
        });
    }, { root: scrollEl, rootMargin: '200px 0px', threshold: 0 });

    grid.querySelectorAll('img.lib-lazy').forEach(img => _libLazyObserver.observe(img));
}

function pickLibFile(el, filename, type, folder) {
    // Deselect all
    document.querySelectorAll('#libGrid .lib-card').forEach(d => {
        d.classList.remove('selected');
        d.style.borderColor = d.dataset.origBorder || '#e2e8f0';
        const chk = d.querySelector('.lib-check');
        if (chk) chk.style.display = 'none';
    });
    // Select this one
    el.classList.add('selected');
    el.style.borderColor = 'var(--info)';
    const chk = el.querySelector('.lib-check');
    if (chk) chk.style.display = 'flex';
    _libSelectedFile = { filename, type, folder };
    const info = document.getElementById('libSelInfo');
    if (info) info.textContent = filename;
    const btn  = document.getElementById('libUseBtn');
    if (btn)  { btn.disabled = false; btn.style.opacity = '1'; }
}

async function useLibraryFile(){
    if(!_libSelectedFile)return;
    const{filename,type,folder}=_libSelectedFile;
    const sc=SCENES[currentIndex];
    const isVidFile=type==='video'||/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
    // Determine folder to save — Mine tab uses user_media subfolder (strip 'user_media/' prefix for DB)
    let folderToSave;
    if(_libTab==='mine'||(folder&&folder.indexOf('user_media')!==-1)){
        folderToSave=(folder||USER_MEDIA_FOLDER).replace(/\/?$/,'').replace(/^user_media\//,'');
    } else {
        folderToSave=isVidFile?'podcast_videos':'podcast_images';
    }
    const fd=new FormData();
    fd.append('ajax_action','assign_image');
    fd.append('scene_id',sc.id);
    fd.append('filename',filename);
    fd.append('image_field','image_file');
    fd.append('media_type',type||'image');
    fd.append('folder_override',folderToSave);
    try {
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(!data.success)throw new Error(data.message||'Failed');
        sc.image_file=filename;
        sc.image_folder=data.image_folder||folderToSave;
        FILE_FOLDER[filename]=sc.image_folder.replace(/\/?$/,'/');
        closeLibraryModal();
        await loadScene(currentIndex);
        _populateMediaOverlay();
    } catch(e){ alert('Error: '+e.message); }
}


// ── Generate (video) ──────────────────────────────────────────────────────────
function mediaGenerate() {
    const bal = USER_CREDIT;
    const confirmDiv  = document.getElementById('mediaGenConfirm');
    const balEl       = document.getElementById('confirmBalance');
    const insuffEl    = document.getElementById('confirmInsufficientMsg');
    const yesBtn      = document.getElementById('confirmYesBtn');
    if (balEl) balEl.textContent = bal.toFixed(2);
    const canAfford   = bal >= 1.00;
    if (insuffEl) insuffEl.style.display = canAfford ? 'none' : 'inline';
    if (yesBtn)  { yesBtn.disabled = !canAfford; yesBtn.style.opacity = canAfford ? '1' : '.4'; }
    if (confirmDiv) confirmDiv.style.display = 'flex';
}

// ── Grab first frame from video and upload as thumbnail ──────────────────────
async function _grabAndSaveVideoThumbnail(videoSrc, videoFilename, sceneId, isScene1) {
    return new Promise((resolve, reject) => {
        const vid = document.createElement('video');
        vid.src         = videoSrc + '?t=' + Date.now();
        vid.muted       = true;
        vid.playsInline = true;
        vid.preload     = 'metadata';
        vid.crossOrigin = 'anonymous';
        const cleanup = () => { vid.src = ''; };
        vid.addEventListener('error', () => { cleanup(); reject(new Error('Video load error')); });
        vid.addEventListener('loadedmetadata', () => { vid.currentTime = 0.5; });
        vid.addEventListener('seeked', async () => {
            try {
                const cvs = document.createElement('canvas');
                cvs.width  = vid.videoWidth  || 360;
                cvs.height = vid.videoHeight || 640;
                const ctx2 = cvs.getContext('2d');
                ctx2.drawImage(vid, 0, 0, cvs.width, cvs.height);
                const dataUrl = cvs.toDataURL('image/jpeg', 0.85);
                cleanup();
                const fd = new FormData();
                fd.append('ajax_action', 'save_video_thumbnail');
                fd.append('podcast_id',  PODCAST_ID);
                fd.append('scene_id',    sceneId);
                fd.append('is_scene1',   isScene1 ? '1' : '0');
                fd.append('filename',    videoFilename);
                fd.append('image_data',  dataUrl);
                const r    = await fetch(location.href, { method: 'POST', body: fd });
                const data = await r.json();
                if (data.success) { console.log('Thumbnail saved:', data.thumbnail); resolve(data); }
                else { reject(new Error(data.message || 'Thumbnail save failed')); }
            } catch(e) { cleanup(); reject(e); }
        });
        document.body.appendChild(vid);
        vid.load();
        setTimeout(() => { if (vid.parentNode) vid.parentNode.removeChild(vid); }, 5000);
    });
}

async function mediaGenConfirmed() {
    document.getElementById('mediaGenConfirm').style.display = 'none';
    const sc     = SCENES[currentIndex];
    const prompt = (document.getElementById('mediaVideoPrompt')?.value || sc.prompt || '').trim();
    if (!prompt) { alert('Please enter a video prompt first.'); return; }

    const btn = document.getElementById('mediaGenBtn');
    const resetBtn = () => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<span style="font-size:24px;">\uD83C\uDFAC</span>Generate<span style="font-size:10px;opacity:.85;">1 cr</span>'; }
    };

    let progressEl = document.getElementById('mediaGenProgress');
    if (!progressEl) {
        progressEl = document.createElement('div');
        progressEl.id = 'mediaGenProgress';
        progressEl.style.cssText = 'margin:12px 16px;padding:14px 16px;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:12px;font-size:12px;color:#0369a1;display:none;';
        document.querySelector('#mediaOverlay .cap-ov-body')?.appendChild(progressEl);
    }
    const setProgress = (msg, pct) => {
        progressEl.style.display = 'block';
        progressEl.innerHTML = '<div style="font-weight:700;margin-bottom:6px;">\uD83C\uDFAC ' + msg + '</div>'
            + '<div style="background:#e0f2fe;border-radius:20px;height:6px;overflow:hidden;">'
            + '<div style="background:#0284c7;height:100%;width:' + pct + '%;transition:width .4s;border-radius:20px;"></div></div>';
    };
    const hideProgress = () => { progressEl.style.display = 'none'; };

    if (btn) { btn.disabled = true; btn.innerHTML = '<span style="font-size:20px;">\u23F3</span>Generating\u2026<span style="font-size:10px;opacity:.85;">wait</span>'; }

    try {
        // Step 1: Deduct 1 credit
        setProgress('Checking credits\u2026', 5);
        const deductFd = new FormData();
        deductFd.append('ajax_action', 'deduct_credit');
        deductFd.append('user_id',     BILLING_UID);
        deductFd.append('amount',      '1.00');
        deductFd.append('description', 'AI video generation scene ' + sc.id);
        const dr     = await fetch(location.href, { method: 'POST', body: deductFd });
        const drText = await dr.text();
        let ddat;
        try { ddat = JSON.parse(drText); } catch(e) { throw new Error('Server error on deduct_credit: ' + drText.substring(0,300)); }
        if (!ddat.success) throw new Error(ddat.message || 'Credit deduction failed');
        USER_CREDIT = parseFloat(ddat.new_balance || 0);
        document.getElementById('creditBalanceDisplay').textContent = USER_CREDIT.toFixed(2);

        // Step 2: Submit to fal.ai
        setProgress('Sending to fal.ai\u2026', 15);
        const genFd = new FormData();
        genFd.append('ajax_action', 'fal_generate_video');
        genFd.append('podcast_id',  PODCAST_ID);
        genFd.append('scene_id',    sc.id);
        genFd.append('prompt',      prompt);
        const gr     = await fetch(location.href, { method: 'POST', body: genFd });
        const grText = await gr.text();
        let gdat;
        try { gdat = JSON.parse(grText); } catch(e) { throw new Error('Server error on fal_generate_video: ' + grText.substring(0,300)); }
        if (!gdat.success) throw new Error(gdat.message || 'fal.ai submission failed');
        const requestId = gdat.request_id;
        if (!requestId) throw new Error('No request_id from fal.ai');

        // Step 3: Poll every 3s up to 3 min
        let elapsed = 0;
        let videoResult = null;
        while (elapsed < 180000) {
            await new Promise(r => setTimeout(r, 3000));
            elapsed += 3000;
            const pct = Math.min(15 + Math.round((elapsed / 180000) * 75), 90);
            setProgress('Generating video\u2026 ' + Math.round(elapsed/1000) + 's elapsed', pct);
            const pollFd = new FormData();
            pollFd.append('ajax_action', 'fal_poll_video');
            pollFd.append('request_id',  requestId);
            pollFd.append('scene_id',    sc.id);
            pollFd.append('podcast_id',  PODCAST_ID);
            const pr     = await fetch(location.href, { method: 'POST', body: pollFd });
            const prText = await pr.text();
            let pdat;
            try { pdat = JSON.parse(prText); } catch(e) { throw new Error('Server error on fal_poll_video: ' + prText.substring(0,300)); }
            if (pdat.status === 'done')  { videoResult = pdat; break; }
            if (pdat.status === 'error') { throw new Error(pdat.message || 'fal.ai generation error'); }
        }
        if (!videoResult) throw new Error('Video generation timed out after 3 minutes.');

        // Step 4: Update scene + reload canvas
        setProgress('Video saved!', 100);
        sc.image_file   = videoResult.filename;
        sc.image_folder = 'podcast_videos';
        sc.media_type   = 'video';
        FILE_FOLDER[videoResult.filename] = 'podcast_videos/';
        await loadScene(currentIndex);
        _populateMediaOverlay();

        // Step 5: Grab thumbnail
        setProgress('Saving thumbnail\u2026', 100);
        try {
            await _grabAndSaveVideoThumbnail(
                'podcast_videos/' + videoResult.filename,
                videoResult.filename, sc.id, currentIndex === 0
            );
        } catch(thumbErr) { console.warn('Thumbnail grab failed:', thumbErr); }

        setTimeout(hideProgress, 3000);

    } catch(e) {
        hideProgress();
        alert('\u274C Failed: ' + e.message);
    } finally {
        resetBtn();
    }
}

// ── Audio overlay ─────────────────────────────────────────────────────────────
let _activeAudioOv = null;

function onAudio() {
    const sub  = document.getElementById('audioSubIcons');
    const btn  = document.getElementById('ibAudio');
    const open = sub.style.display !== 'none';
    // Close other sub-icons/overlays
    document.getElementById('capSubIcons').style.display  = 'none';
    document.getElementById('fontSubIcons').style.display = 'none';
    document.getElementById('ibCaption')?.classList.remove('active');
    document.getElementById('ibFont')?.classList.remove('active');
    closeCapOverlay(); closeFontOverlay(); closeMediaOverlay();
    if (open) {
        sub.style.display = 'none';
        btn.classList.remove('active');
        closeAudioOverlay();
    } else {
        sub.style.display = 'flex';
        btn.classList.add('active');
    }
}

function openAudioOverlay(which) {
    // Highlight active sub-icon
    document.getElementById('audioSubVoice')?.classList.toggle('active', which === 'voice');
    document.getElementById('audioSubMusic')?.classList.toggle('active', which === 'music');
    // Close both, open the chosen one
    ['audioOvVoice','audioOvMusic'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    const id = which === 'voice' ? 'audioOvVoice' : 'audioOvMusic';
    document.getElementById(id)?.classList.add('open');
    _activeAudioOv = which;
    // Backdrop close
    const ov = document.getElementById(id);
    if (ov) ov.onclick = e => { if (e.target === ov) closeAudioOverlay(); };
    // Load data
    document.getElementById('audioSceneNum').textContent = currentIndex + 1;
    if (which === 'voice') { _loadVoicePanel(); }
    if (which === 'music') { _renderCurrentMusic(); _syncVolumeSliders(); }
}

function closeAudioOverlay() {
    ['audioOvVoice','audioOvMusic'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    _activeAudioOv = null;
    _stopVoicePreview();
}
const AUD_BASE = 'podcast_audios/';
const MUS_BASE = 'podcast_music/';
let bgAudio          = null;
let bgMusicVolume    = <?= json_encode($music_volume) ?>;
let voiceVolume      = <?= json_encode($voice_volume) ?>;
let currentPlaybackSpeed = AUDIO_SPEED;
let _allVoices       = [];
let _voiceTarget     = 'host';
let _selectedVoiceKey= '';
let _voicePreviewAudio = null;
let _musicPreviewAudio = null;
let _musicLibFiles   = [];
let _selectedMusicFile = null;
let _isFreeTrialUser = <?= json_encode($is_free_trial ?? false) ?>;
let _currentPodcastMusic = '<?= addslashes($podcast_music) ?>';

// ── Voice panel ───────────────────────────────────────────────────────────────
async function _loadVoicePanel() {
    _updateVcCurrentDisplay();
    if (_allVoices.length) { _populateVoiceDropdowns(); return; }
    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel) vSel.innerHTML = '<option>Loading voices…</option>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_voices_by_language');
        fd.append('lang_code',   LANG_CODE);
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        _allVoices = data.voices || [];
        _populateVoiceDropdowns();
    } catch(e) { if (vSel) vSel.innerHTML = '<option>Failed to load voices</option>'; }
}

function _populateVoiceDropdowns() {
    const sel = document.getElementById('vcVoiceSelect'); if (!sel) return;
    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey || '';
    sel.innerHTML = '<option value="">— Select a voice —</option>' +
        _allVoices.map(v => {
            const gender  = (v.gender||'').toLowerCase() === 'female' ? 'female' : 'male';
            const icon    = gender === 'female' ? '👩' : '👨';
            const label   = `${icon} ${v.voice_name||v.voice_key} (${gender})${v.voice_description?' — '+v.voice_description:''}`;
            const blocked = _isFreeTrialUser && (v.voice_source||'').toLowerCase() === 'azure';
            return `<option value="${v.voice_key}"
                data-gender="${gender}"
                data-sample="${v.sample_voice||''}"
                data-lang="${v.lang_code||''}"
                data-voice-text="${(v.voice_text||'').replace(/"/g,'&quot;')}"
                ${blocked?'disabled style="color:#ccc;"':''}
                ${v.voice_key===currentKey?'selected':''}>
                ${label}${blocked?' 🔒':''}
            </option>`;
        }).join('');
    _updateVcCurrentDisplay();
    _updateVoiceDescSingle();
}

function onVoiceSelectChange() {
    const sel = document.getElementById('vcVoiceSelect'); if (!sel||!sel.value) return;
    _selectedVoiceKey = sel.value;
    const opt = sel.options[sel.selectedIndex];
    // Seed sample text from voice DB text if field is empty
    const ta = document.getElementById('vcSampleText');
    if (ta && !ta.value.trim() && opt?.dataset.voiceText) ta.value = opt.dataset.voiceText;
    _updateVoiceDescSingle();
    _stopVoicePreview();
}

function _updateVoiceDescSingle() {
    const sel  = document.getElementById('vcVoiceSelect');
    const desc = document.getElementById('vcVoiceDesc'); if (!sel||!desc) return;
    const opt  = sel.options[sel.selectedIndex];
    desc.textContent = (opt&&opt.value) ? (opt.dataset.description||'') : '';
}

function _updateVcCurrentDisplay() {
    const span = document.getElementById('vcCurrentName'); if (!span) return;
    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    const voice = _allVoices.find(v => v.voice_key === currentKey);
    const ta    = document.getElementById('vcSampleText');
    if (ta && !ta.value.trim() && voice?.voice_text) ta.value = voice.voice_text;
    const label = voice
        ? (voice.voice_name||voice.voice_key) + (voice.voice_description?' ('+voice.voice_description+')':'')
        : (currentKey||'— none —');
    span.textContent = (_voiceTarget==='guest'?'🎙 Guest: ':'🎙 Host: ') + label;
}

function setVoiceTarget(target) {
    _voiceTarget = target;
    _stopVoicePreview();
    ['host','guest'].forEach(t => {
        const btn = document.getElementById('vt'+t.charAt(0).toUpperCase()+t.slice(1)); if(!btn)return;
        const on  = t === target;
        btn.style.background  = on ? 'var(--info)' : 'var(--surface2)';
        btn.style.borderColor = on ? 'var(--info)' : 'var(--border)';
        btn.style.color       = on ? '#fff' : 'var(--muted)';
    });
    const currentKey = target==='guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey||'';
    const sel = document.getElementById('vcVoiceSelect');
    if (sel) sel.value = currentKey||'';
    _updateVoiceDescSingle();
    _updateVcCurrentDisplay();
}

function previewSelectedVoice() {
    const sel = document.getElementById('vcVoiceSelect');
    const btn = document.getElementById('vcPlayBtn');
    if (!sel||!sel.value) return;
    if (_voicePreviewAudio && !_voicePreviewAudio.paused) { _stopVoicePreview(); return; }
    _stopVoicePreview();
    const opt      = sel.options[sel.selectedIndex];
    const langCode = opt?.dataset.lang || 'en';
    const ta       = document.getElementById('vcSampleText');
    const text     = (ta&&ta.value.trim()) ? ta.value.trim() : (opt?.dataset.voiceText||'Hello, this is a sample of my voice.');
    if (!text) return;
    if (btn) { btn.textContent='…'; btn.disabled=true; }
    const fd = new FormData();
    fd.append('text',     text);
    fd.append('voice_id', sel.value);
    fd.append('lang_code',langCode);
    fd.append('row_id',   '0');
    fd.append('rate',     currentPlaybackSpeed||'1.0');
    fd.append('filename', 'preview_'+sel.value.replace(/[^a-zA-Z0-9_]/g,'_')+'.mp3');
    fetch('generate_voice.php',{method:'POST',body:fd,credentials:'include'})
        .then(r=>r.json())
        .then(d => {
            if(btn){btn.textContent='▶';btn.disabled=false;}
            if(!d.success) return;
            _voicePreviewAudio = new Audio('podcast_audios/'+d.filename+'?t='+Date.now());
            _voicePreviewAudio.playbackRate = currentPlaybackSpeed;
            _voicePreviewAudio.onended = () => { if(btn)btn.textContent='▶'; _voicePreviewAudio=null; };
            _voicePreviewAudio.play().catch(()=>{});
            if(btn) btn.textContent='⏹';
        })
        .catch(() => { if(btn){btn.textContent='▶';btn.disabled=false;} });
}

function _stopVoicePreview() {
    if (_voicePreviewAudio) { _voicePreviewAudio.pause(); _voicePreviewAudio=null; }
    const pb = document.getElementById('vcPlayBtn'); if(pb) pb.textContent='▶';
}

function _ensureVoicePrefix(voiceId) {
    if (!voiceId||voiceId.includes(':')) return voiceId;
    const found = _allVoices.find(v=>v.voice_key===voiceId);
    if (found && (found.voice_source||'').toLowerCase()==='openai') return 'openai:'+voiceId;
    return 'openai:'+voiceId;
}

async function saveSelectedVoice() {
    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel&&vSel.value) _selectedVoiceKey = vSel.value;
    if (!_selectedVoiceKey) { alert('Please select a voice first.'); return; }
    const prefixedKey = _ensureVoicePrefix(_selectedVoiceKey);
    if (_voiceTarget==='guest') _guestVoiceId=prefixedKey; else _hostVoiceId=prefixedKey;
    const btn = document.getElementById('vcApplyBtn');
    if (btn) { btn.disabled=true; btn.textContent='⏳ Saving…'; }
    const fd = new FormData();
    fd.append('ajax_action',    'save_podcast_voices');
    fd.append('host_voice_id',  _hostVoiceId);
    fd.append('guest_voice_id', _guestVoiceId);
    try {
        const r    = await fetch(location.href,{method:'POST',body:fd});
        const data = await r.json();
        if (!data.success) { alert('Failed to save voice'); return; }
        _updateVcCurrentDisplay();
        _stopVoicePreview();
        await regenAllScenesAudio();
    } catch(e) { alert('Error: '+e.message); }
    finally { if(btn){btn.disabled=false;btn.textContent='✓ Apply Voice to All Scenes';} }
}

async function regenAllScenesAudio() {
    const total  = SCENES.length;
    const wrap   = document.getElementById('audioRegenWrap');
    const barEl  = document.getElementById('regenBar');
    const doneEl = document.getElementById('regenDone');
    const totEl  = document.getElementById('regenTotal');
    const statEl = document.getElementById('regenStatus');
    const logEl  = document.getElementById('regenLog');
    if (wrap)   wrap.style.display  = 'block';
    if (totEl)  totEl.textContent   = total;
    if (logEl)  logEl.innerHTML     = '';
    let done = 0;
    for (const sc of SCENES) {
        const scCaps  = ALL_CAPTIONS.filter(c=>+c.story_id===+sc.id);
        const mainCap = scCaps.find(c=>(c.caption_name||'').toLowerCase()==='main')||scCaps[0]||null;
        const text    = (mainCap?mainCap.text_content:'').replace(/<break[^>]*>/gi,'').trim();
        const rawV    = ((sc.actor||'').toLowerCase()==='guest'&&_guestVoiceId)?_guestVoiceId:_hostVoiceId;
        const voiceId = _ensureVoicePrefix(rawV);
        if (statEl) statEl.textContent = `Scene ${done+1} of ${total}…`;
        if (!text||!voiceId) { done++; _updateRegenProgress(done,total); continue; }
        try {
            const fd=new FormData();
            fd.append('ajax_action','generate_scene_audio');
            fd.append('text',text); fd.append('voice_id',voiceId);
            fd.append('lang_code',LANG_CODE); fd.append('rate',currentPlaybackSpeed||1.0);
            fd.append('scene_id',sc.id); fd.append('podcast_id',PODCAST_ID);
            const r    = await fetch('wizard_step2.php',{method:'POST',body:fd});
            const data = await r.json();
            if (data.success) { sc.audio_file=data.filename; if(logEl)logEl.innerHTML+=`✅ Scene ${done+1}<br>`; }
            else { if(logEl)logEl.innerHTML+=`⚠️ Scene ${done+1}: ${data.message||'failed'}<br>`; }
        } catch(e) { if(logEl)logEl.innerHTML+=`❌ Scene ${done+1}: ${e.message}<br>`; }
        done++; _updateRegenProgress(done,total);
        if(logEl) logEl.scrollTop=logEl.scrollHeight;
    }
    if (statEl) statEl.textContent='✅ All done!';
    setTimeout(()=>{ if(wrap)wrap.style.display='none'; },2000);
}

function _updateRegenProgress(done, total) {
    const pct    = Math.round((done/total)*100);
    const barEl  = document.getElementById('regenBar');
    const doneEl = document.getElementById('regenDone');
    if (barEl)  barEl.style.width  = pct+'%';
    if (doneEl) doneEl.textContent = done;
}

// ── Speed ─────────────────────────────────────────────────────────────────────
function updatePlaybackSpeed(speed) {
    currentPlaybackSpeed = parseFloat(speed);
    const lbl = document.getElementById('speedValue');
    if (lbl) lbl.textContent = parseFloat(speed).toFixed(2)+'x';
    document.querySelectorAll('.speed-preset').forEach(b =>
        b.classList.toggle('active', parseFloat(b.textContent)===currentPlaybackSpeed));
    // Debounce save to DB
    clearTimeout(_speedSaveTimer);
    _speedSaveTimer = setTimeout(async () => {
        const fd=new FormData(); fd.append('ajax_action','save_audio_speed');
        fd.append('speed',currentPlaybackSpeed); fd.append('podcast_id',PODCAST_ID);
        await fetch(location.href,{method:'POST',body:fd});
    }, 800);
}
let _speedSaveTimer = null;

function setPlaybackSpeed(speed) {
    const slider = document.getElementById('playbackSpeedSlider');
    if (slider) slider.value = speed;
    updatePlaybackSpeed(speed);
}

// ── Music ─────────────────────────────────────────────────────────────────────
let _volSaveTimer = null;
function _syncVolumeSliders() {
    const mvs = document.getElementById('musicVolSlider');
    const vvs = document.getElementById('voiceVolSlider');
    const mvl = document.getElementById('musicVolLbl');
    const vvl = document.getElementById('voiceVolLbl');
    if (mvs) mvs.value = Math.round(bgMusicVolume * 100);
    if (vvs) vvs.value = Math.round(voiceVolume   * 100);
    if (mvl) mvl.textContent = Math.round(bgMusicVolume * 100) + '%';
    if (vvl) vvl.textContent = Math.round(voiceVolume   * 100) + '%';
    if (bgAudio) bgAudio.volume = bgMusicVolume;
}
function _saveVolumes() {
    clearTimeout(_volSaveTimer);
    _volSaveTimer = setTimeout(async () => {
        const fd = new FormData();
        fd.append('ajax_action',  'save_podcast_volumes');
        fd.append('podcast_id',   PODCAST_ID);
        fd.append('music_volume', bgMusicVolume.toFixed(2));
        fd.append('voice_volume', voiceVolume.toFixed(2));
        await fetch(location.href, { method:'POST', body:fd });
    }, 600);
}

function onMusicVolChange(val) {
    bgMusicVolume = parseInt(val) / 100;
    const lbl = document.getElementById('musicVolLbl');
    if (lbl) lbl.textContent = val + '%';
    if (bgAudio) bgAudio.volume = bgMusicVolume;
    if (typeof _musicPreviewAudio !== 'undefined' && _musicPreviewAudio) _musicPreviewAudio.volume = bgMusicVolume;
    _saveVolumes();
}

function onVoiceVolChange(val) {
    voiceVolume = parseInt(val) / 100;
    const lbl = document.getElementById('voiceVolLbl');
    if (lbl) lbl.textContent = val + '%';
    _saveVolumes();
}

function _renderCurrentMusic() {
    const wrap = document.getElementById('musicCurrentWrap'); if(!wrap)return;
    if (_currentPodcastMusic) {
        wrap.innerHTML=`<div style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1.5px solid var(--success);border-radius:8px;padding:7px 10px;">
            <span style="font-size:16px;">🎵</span>
            <span style="flex:1;font-size:11px;font-weight:600;color:var(--success);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${_currentPodcastMusic}">${_currentPodcastMusic}</span>
            <button onclick="_previewCurrentMusic(this)" style="width:24px;height:24px;border-radius:50%;border:none;background:var(--success);color:#fff;font-size:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
        </div>`;
    } else {
        wrap.innerHTML=`<div style="font-size:11px;color:var(--muted);padding:4px 0;">No background music selected</div>`;
    }
}


function _applyBgAudio() {
    if (bgAudio) bgAudio.pause();
    if (_currentPodcastMusic) {
        const src = window._currentMusicSrc || (MUS_BASE + _currentPodcastMusic);
        bgAudio = new Audio(src + '?t=' + Date.now());
        bgAudio.loop = true; bgAudio.volume = bgMusicVolume;
    } else { bgAudio = null; }
}

function _previewCurrentMusic(btn) {
    if (_musicPreviewAudio && !_musicPreviewAudio.paused) { _musicPreviewAudio.pause(); btn.textContent='▶'; return; }
    const src = window._currentMusicSrc || (MUS_BASE + _currentPodcastMusic);
    _musicPreviewAudio = new Audio(src + '?t=' + Date.now());
    _musicPreviewAudio.volume = bgMusicVolume;
    _musicPreviewAudio.onended = () => { btn.textContent = '▶'; };
    _musicPreviewAudio.play().catch(()=>{});
    btn.textContent = '⏹';
}

function uploadMusicClick() {
    const inp=document.getElementById('musicFileInput'); if(inp){inp.value='';inp.click();}
}

async function handleMusicUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const btn  = document.getElementById('musicUploadBtnRef'); // optional ref
    const fd   = new FormData();
    fd.append('ajax_action',   'upload_user_media');        // upload to user folder
    fd.append('media_file',    file);
    fd.append('media_type',    'audio');
    fd.append('target_folder', USER_MEDIA_FOLDER.replace(/\/?$/,''));
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (!data.success) throw new Error(data.message || 'Upload failed');
        const userFolder = (window._userMusicFolder || USER_MEDIA_FOLDER).replace(/\/?$/,'/');
        const src  = userFolder + data.filename;
        // Set as current music
        _currentPodcastMusic     = data.filename;
        window._currentMusicSrc  = src;
        // Set music volume to 100%, voice to 0% when music is uploaded
        bgMusicVolume = 1.0;
        voiceVolume   = 0.0;
        _syncVolumeSliders();
        // Save to podcast
        const fd2 = new FormData();
        fd2.append('ajax_action',  'update_podcast_music');
        fd2.append('music_file',   data.filename);
        fd2.append('music_folder', (window._userMusicFolder||'').replace(/\/?$/,''));
        await fetch(location.href, { method:'POST', body:fd2 });
        // Save volumes
        const fd3 = new FormData();
        fd3.append('ajax_action',  'save_podcast_volumes');
        fd3.append('podcast_id',   PODCAST_ID);
        fd3.append('music_volume', '1.00');
        fd3.append('voice_volume', '0.00');
        await fetch(location.href, { method:'POST', body:fd3 });
        _applyBgAudio(); _renderCurrentMusic();
        // Refresh library
        await _loadMusicLibGrid();
    } catch(e) { alert('Upload failed: ' + e.message); }
}

async function clearPodcastMusic() {
    const fd=new FormData(); fd.append('ajax_action','update_podcast_music'); fd.append('music_file','');
    await fetch(location.href,{method:'POST',body:fd});
    _currentPodcastMusic=''; _applyBgAudio(); _renderCurrentMusic();
}

// ── Music library ─────────────────────────────────────────────────────────────
async function openMusicLibModal() {
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}
    _selectedMusicFile=null;
    const useBtn=document.getElementById('musicLibUseBtn'); if(useBtn){useBtn.disabled=true;useBtn.style.opacity='.4';}
    const info=document.getElementById('musicLibSelInfo'); if(info)info.textContent='No file selected';
    const modal=document.getElementById('musicLibModal'); if(modal)modal.style.display='flex';
    await _loadMusicLibGrid();
}

function closeMusicLibModal() {
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}
    document.querySelectorAll('#musicLibGrid button').forEach(b=>{if(b.textContent==='⏹')b.textContent='▶';});
    const modal=document.getElementById('musicLibModal'); if(modal)modal.style.display='none';
    _selectedMusicFile=null;
}

async function _loadMusicLibGrid() {
    const grid = document.getElementById('musicLibGrid');
    if (grid) grid.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted);">Loading…</div>';
    try {
        // Load shared music library
        const fd = new FormData();
        fd.append('ajax_action', 'get_music_library');
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        // Normalise — each file gets a src for preview
        const shared = (data.files||[]).map(f => ({
            filename: f.filename,
            size:     f.size || 0,
            src:      'podcast_music/' + f.filename,
            label:    f.filename
        }));

        // Also load user's personal audio files from their folder
        const fd2 = new FormData();
        fd2.append('ajax_action', 'get_user_media');
        fd2.append('media_type_filter', 'audio');
        const r2    = await fetch(location.href, { method:'POST', body:fd2 });
        const data2 = await r2.json();
        const userFolder = (data2.folder || USER_MEDIA_FOLDER).replace(/\/?$/, '/');
        window._userMusicFolder = userFolder;
        const personal = (data2.files || [])
            .filter(f => /\.(mp3|wav|ogg|m4a|aac|flac)$/i.test(f.filename))
            .map(f => ({
                filename: f.filename,
                size:     f.size || 0,
                src:      userFolder + f.filename,
                label:    '👤 ' + f.filename,
                is_personal: true
            }));

        _musicLibFiles = [...shared, ...personal];
        _renderMusicLibGrid(_musicLibFiles);
    } catch(e) {
        if (grid) grid.innerHTML = '<div style="color:var(--danger);text-align:center;padding:20px;">Error loading files</div>';
        console.error('_loadMusicLibGrid error:', e);
    }
}

function filterMusicLibGrid() {
    const q=(document.getElementById('musicLibSearch')?.value||'').toLowerCase();
    _renderMusicLibGrid(q?_musicLibFiles.filter(f=>f.filename.toLowerCase().includes(q)):_musicLibFiles);
}

function _renderMusicLibGrid(files) {
    const grid = document.getElementById('musicLibGrid'); if (!grid) return;
    if (!files.length) {
        grid.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted);">No music files found</div>';
        return;
    }
    grid.innerHTML = files.map(f => {
        const isCur = f.filename === _currentPodcastMusic;
        const src   = f.src || ('podcast_music/' + f.filename);
        const safeSrc = src.replace(/'/g, "\\'");
        return `<div onclick="_pickMusicFile(this,'${f.filename}','${safeSrc}')"
            style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;
                   border:1.5px solid ${isCur?'var(--success)':'var(--border)'};
                   background:${isCur?'#f0fdf4':'var(--surface)'};cursor:pointer;transition:border-color .13s;">
            <span style="font-size:20px;flex-shrink:0;">${f.is_personal?'👤':'🎵'}</span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:11px;font-weight:600;color:${isCur?'var(--success)':'var(--text)'};
                     white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${f.filename}">${f.label||f.filename}</div>
                <div style="font-size:9px;color:var(--muted);">${f.is_personal?'Personal folder · ':''}${((f.size||0)/1024).toFixed(0)} KB</div>
            </div>
            <button onclick="event.stopPropagation();_prevMusicLib('${safeSrc}',this)"
                style="width:26px;height:26px;border-radius:50%;border:none;background:var(--info);color:#fff;font-size:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
            ${isCur?'<span style="color:var(--success);font-size:14px;">✓</span>':''}
        </div>`;
    }).join('');
}

function _pickMusicFile(el, filename, src) {
    document.querySelectorAll('#musicLibGrid > div').forEach(d => {
        d.style.borderColor = 'var(--border)'; d.style.background = 'var(--surface)';
    });
    el.style.borderColor = 'var(--info)'; el.style.background = '#eff6ff';
    _selectedMusicFile = { filename, src: src || ('podcast_music/' + filename) };
    const info = document.getElementById('musicLibSelInfo'); if (info) info.textContent = filename;
    const btn  = document.getElementById('musicLibUseBtn'); if (btn) { btn.disabled=false; btn.style.opacity='1'; }
}

function _prevMusicLib(src, btn) {
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;
        document.querySelectorAll('#musicLibGrid button').forEach(b=>{if(b.textContent==='⏹')b.textContent='▶';});}
    if(btn.textContent==='⏹'){btn.textContent='▶';return;}
    _musicPreviewAudio=new Audio(src+'?t='+Date.now());
    _musicPreviewAudio.onended=()=>{btn.textContent='▶';};
    _musicPreviewAudio.play().catch(()=>{});
    btn.textContent='⏹';
}

async function useMusicLibFile() {
    if (!_selectedMusicFile) return;
    const { filename, src } = _selectedMusicFile;
    const fd = new FormData();
    fd.append('ajax_action', 'update_podcast_music');
    fd.append('music_file',  filename);
    if (src && src.indexOf('user_media') !== -1) {
        fd.append('music_folder', (window._userMusicFolder||'').replace(/\/?$/,''));
    }
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success) {
            _currentPodcastMusic    = filename;
            window._currentMusicSrc = src || (MUS_BASE + filename);
            // Set music=100%, voice=0% and save
            bgMusicVolume = 1.0;
            voiceVolume   = 0.0;
            _syncVolumeSliders();
            const fv = new FormData();
            fv.append('ajax_action',  'save_podcast_volumes');
            fv.append('podcast_id',   PODCAST_ID);
            fv.append('music_volume', '1.00');
            fv.append('voice_volume', '0.00');
            await fetch(location.href, { method:'POST', body:fv });
            _applyBgAudio(); _renderCurrentMusic(); closeMusicLibModal();
        } else { alert('Failed to set music'); }
    } catch(e) { alert('Error: '+e.message); }
}

function _staticRepaint() {
    // Only needed for static image scenes; video loop repaints itself every frame
    if (_playing) return;
    if (_lastStaticImg) {
        const im = _lastStaticImg;
        ctx.clearRect(0, 0, CW, CH);
        const scale = Math.max(CW / im.naturalWidth, CH / im.naturalHeight);
        const sw = im.naturalWidth * scale, sh = im.naturalHeight * scale;
        ctx.drawImage(im, (CW-sw)/2, (CH-sh)/2, sw, sh);
    } else {
        fillBlack();
    }
    drawAllCaptions();
}

// ── Canvas caption drag & resize ─────────────────────────────────────────────
let _drag   = { active:false, capId:null, startX:0, startY:0, origX:0, origY:0 };
let _resize = { active:false, capId:null, dir:'', startX:0, startY:0, origW:0, origH:0, origBaseH:0, origPX:0, origPY:0, origExtraVPad:0 };

function _canvasPos(e) {
    const rect = canvas.getBoundingClientRect();
    return { x: (e.clientX - rect.left) * (CW / rect.width),
             y: (e.clientY - rect.top)  * (CH / rect.height) };
}

function _hitCap(x, y) {
    for (let i = sceneCaptions.length - 1; i >= 0; i--) {
        const c = sceneCaptions[i];
        if (!+c.is_visible) continue;
        const bbox = c._bbox || (c.caption_type === 'image' ? {
            x: parseInt(c.position_x)||20, y: parseInt(c.position_y)||20,
            w: parseInt(c.width)||120,     h: parseInt(c.rotation)||120
        } : null);
        if (!bbox) continue;
        if (x >= bbox.x && x <= bbox.x + bbox.w && y >= bbox.y && y <= bbox.y + bbox.h) return c.id;
    }
    return null;
}

function _isResizeHandle(capId, x, y) {
    const cap = sceneCaptions.find(c => +c.id === +capId);
    if (!cap || !cap._bbox) return false;
    const {x:bx, y:by, w, h} = cap._bbox;
    const hs = 12, off = 2, tol = 10;
    const handles = [
        { cx: bx - off - hs/2,     cy: by - off - hs/2,     dir:'nw' },
        { cx: bx + w + off + hs/2, cy: by - off - hs/2,     dir:'ne' },
        { cx: bx - off - hs/2,     cy: by + h + off + hs/2, dir:'sw' },
        { cx: bx + w + off + hs/2, cy: by + h + off + hs/2, dir:'se' },
        { cx: bx + w + off + hs/2, cy: by + h/2,             dir:'e'  },
        { cx: bx + w/2,            cy: by + h + off + hs/2, dir:'s'  },
    ];
    for (const {cx, cy, dir} of handles) {
        if (Math.hypot(x - cx, y - cy) < tol + hs/2) return dir;
    }
    return false;
}

canvas.addEventListener('mousedown', e => {
    const {x, y} = _canvasPos(e);
    // Resize handle first
    const rh = selectedCapId && _isResizeHandle(selectedCapId, x, y);
    if (rh) {
        const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
        const bboxH       = cap._bbox ? cap._bbox.h : _capBoxH(cap);
        const storedH     = parseInt(cap.rotation) || 0;
        const extraVPad   = cap._extraVPad ?? (cap.caption_type === 'image' ? 0 : storedH);
        const baseH       = cap.caption_type === 'image' ? storedH : Math.max(20, bboxH - extraVPad);
        _resize = { active:true, dir:rh, capId:selectedCapId,
                    startX:x, startY:y,
                    origW:parseInt(cap.width)||120, origH:bboxH, origBaseH:baseH,
                    origPX:parseInt(cap.position_x)||0, origPY:parseInt(cap.position_y)||0,
                    origExtraVPad:extraVPad };
        e.preventDefault(); return;
    }
    // Hit test caption body
    const hit = _hitCap(x, y);
    if (hit) {
        selectCaption(hit);
        const cap = sceneCaptions.find(c => +c.id === +hit);
        _drag = { active:true, capId:hit, startX:x, startY:y,
                  origX:parseInt(cap.position_x)||50, origY:parseInt(cap.position_y)||400 };
    } else {
        selectedCapId = null;
        showCaptionEditor(false);
        renderCaptionTabs();
        _staticRepaint();
    }
    e.preventDefault();
});

canvas.addEventListener('mousemove', e => {
    const {x, y} = _canvasPos(e);
    if (_resize.active) {
        const cap = sceneCaptions.find(c => +c.id === +_resize.capId);
        if (cap) {
            const dx = x - _resize.startX, dy = y - _resize.startY;
            const dir = _resize.dir;
            if (dir==='e'||dir==='ne'||dir==='se') {
                const maxW = CW - CAP_MARGIN - (parseFloat(cap.position_x)||0);
                cap.width = Math.max(40, Math.min(maxW, _resize.origW + dx));
            }
            if (dir==='nw'||dir==='sw') {
                const nw = Math.max(40, _resize.origW - dx);
                const nx = Math.max(CAP_MARGIN, _resize.origPX + (_resize.origW - nw));
                cap.position_x = nx;
                cap.width = Math.min(nw, CW - CAP_MARGIN - nx);
            }
            if (dir==='s'||dir==='se'||dir==='sw') {
                const nh = Math.max(20, Math.min(CH - (parseFloat(cap.position_y)||0), _resize.origH + dy));
                cap.rotation = nh; cap._extraVPad = Math.max(0, nh - _resize.origBaseH);
            }
            if (dir==='ne'||dir==='nw') {
                const nh = Math.max(20, _resize.origH - dy);
                const ny = Math.max(0, _resize.origPY + (_resize.origH - nh));
                cap.position_y = Math.min(ny, CH - 20);
                cap.rotation = nh; cap._extraVPad = Math.max(0, nh - _resize.origBaseH);
            }
            ['width','rotation','position_x','position_y'].forEach(f => {
                if (!_capDirty[cap.id]) _capDirty[cap.id] = {};
                _capDirty[cap.id][f] = Math.round(parseFloat(cap[f])||0);
            });
            syncPosInputs(cap);
            _staticRepaint();
        }
        return;
    }
    if (_drag.active) {
        const cap = sceneCaptions.find(c => +c.id === +_drag.capId);
        if (cap) {
            const w  = parseFloat(cap.width) || 120;
            const bh = _capBoxH(cap);
            cap.position_x = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w,  _drag.origX + (x - _drag.startX)));
            cap.position_y = Math.max(0,           Math.min(CH - Math.max(20,bh), _drag.origY + (y - _drag.startY)));
            syncPosInputs(cap);
            _staticRepaint();
        }
        return;
    }
    // Cursor hints
    const rdir = selectedCapId && _isResizeHandle(selectedCapId, x, y);
    if (rdir === 'e')                  { canvas.style.cursor = 'ew-resize';   return; }
    if (rdir === 's')                  { canvas.style.cursor = 'ns-resize';   return; }
    if (rdir === 'nw' || rdir === 'se'){ canvas.style.cursor = 'nwse-resize'; return; }
    if (rdir === 'ne' || rdir === 'sw'){ canvas.style.cursor = 'nesw-resize'; return; }
    canvas.style.cursor = _hitCap(x, y) ? 'grab' : 'default';
});

canvas.addEventListener('mouseup', () => {
    // Persist position/size after drag or resize
    if (_drag.active && _drag.capId) {
        const cap = sceneCaptions.find(c => +c.id === +_drag.capId);
        if (cap) {
            if (!_capDirty[cap.id]) _capDirty[cap.id] = {};
            _capDirty[cap.id]['position_x'] = Math.round(cap.position_x);
            _capDirty[cap.id]['position_y'] = Math.round(cap.position_y);
            _saveCaption(cap.id);
        }
    }
    if (_resize.active && _resize.capId) {
        const cap = sceneCaptions.find(c => +c.id === +_resize.capId);
        if (cap) {
            if (!_capDirty[cap.id]) _capDirty[cap.id] = {};
            ['width','rotation','position_x','position_y'].forEach(f => {
                _capDirty[cap.id][f] = Math.round(parseFloat(cap[f])||0);
            });
            _saveCaption(cap.id);
        }
    }
    _drag.active = false;
    _resize.active = false;
});
canvas.addEventListener('mouseleave', () => { _drag.active = false; _resize.active = false; });

// Touch forwarding
canvas.addEventListener('touchstart', e => {
    if (e.touches[0]) canvas.dispatchEvent(new MouseEvent('mousedown', { clientX:e.touches[0].clientX, clientY:e.touches[0].clientY, bubbles:true }));
}, { passive:false });
canvas.addEventListener('touchmove', e => {
    if (e.touches[0]) canvas.dispatchEvent(new MouseEvent('mousemove', { clientX:e.touches[0].clientX, clientY:e.touches[0].clientY, bubbles:true }));
    e.preventDefault();
}, { passive:false });
canvas.addEventListener('touchend', () => canvas.dispatchEvent(new MouseEvent('mouseup', {})));

// ── Caption constants ─────────────────────────────────────────────────────────
const CAP_MARGIN = 5;
const CAP_MAX_W  = CW - CAP_MARGIN * 2;
const GLOBAL_CAP_NAMES = ['main','header','footer','logo'];
let selectedCapId     = null;
let _applyToAllScenes = true;
let _capSaveTimers    = {};
let _capDirty         = {};
let _activeCapOv      = null;

// ── Caption icon: toggle sub-icons ───────────────────────────────────────────
function onCaption() {
    const sub  = document.getElementById('capSubIcons');
    const btn  = document.getElementById('ibCaption');
    const open = sub.style.display !== 'none';
    // Close font + audio sub-icons
    document.getElementById('fontSubIcons').style.display  = 'none';
    document.getElementById('audioSubIcons').style.display = 'none';
    document.getElementById('ibFont')?.classList.remove('active');
    document.getElementById('ibAudio')?.classList.remove('active');
    closeFontOverlay(); closeAudioOverlay();
    if (open) {
        sub.style.display = 'none';
        btn.classList.remove('active');
        closeCapOverlay();
    } else {
        sub.style.display = 'flex';
        btn.classList.add('active');
    }
}

// ── Open / close overlays ────────────────────────────────────────────────────
function openCapOverlay(which) {
    ['capSubText','capSubBg','capSubPos'].forEach(id => {
        document.getElementById(id)?.classList.toggle('active',
            (which==='text'&&id==='capSubText')||(which==='bg'&&id==='capSubBg')||(which==='pos'&&id==='capSubPos'));
    });
    ['capOvText','capOvBg','capOvPos'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    const map = { text:'capOvText', bg:'capOvBg', pos:'capOvPos' };
    document.getElementById(map[which])?.classList.add('open');
    _activeCapOv = which;
    if (selectedCapId) {
        const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
        if (cap) {
            if (which==='text') { showCaptionEditor(true); populateCaptionEditor(cap); }
            if (which==='bg')   { _showBgEditor(true);  _populateBgEditor(cap); }
            if (which==='pos')  { _showPosEditor(true); syncPosInputs(cap); }
        }
    } else {
        if (which==='text') showCaptionEditor(false);
        if (which==='bg')   _showBgEditor(false);
        if (which==='pos')  _showPosEditor(false);
    }
    const ov = document.getElementById(map[which]);
    if (ov) ov.onclick = e => { if (e.target===ov) closeCapOverlay(); };
}

function closeCapOverlay() {
    ['capOvText','capOvBg','capOvPos'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    _activeCapOv = null;
}

function _showBgEditor(show) {
    const ns=document.getElementById('capBgNoSel'), ed=document.getElementById('capBgEditor');
    if(ns) ns.style.display=show?'none':'block';
    if(ed) ed.style.display=show?'block':'none';
}
function _showPosEditor(show) {
    const ns=document.getElementById('capPosNoSel'), ed=document.getElementById('capPosEditor');
    if(ns) ns.style.display=show?'none':'block';
    if(ed) ed.style.display=show?'block':'none';
}

// ── renderCaptionTabs ─────────────────────────────────────────────────────────
function renderCaptionTabs() {
    const tabs = document.getElementById('captionTabs');
    if (!tabs) return;
    if (!sceneCaptions.length) {
        tabs.innerHTML='<span style="font-size:10px;color:rgba(255,255,255,.5);">No captions</span>'; return;
    }
    tabs.innerHTML = sceneCaptions.map(c => {
        const isMain=(c.caption_name||'').toLowerCase()==='main';
        const isSel=+c.id===+selectedCapId;
        return `<button class="cap-tab${isSel?' active':''}" onclick="selectCaption(${c.id})">
            ${isMain?'🔒 ':''}${c.caption_name||'cap'}
            ${!+c.is_visible?'<span style="opacity:.5;font-size:9px;">🚫</span>':''}
        </button>`;
    }).join('');
}

// ── selectCaption ─────────────────────────────────────────────────────────────
function selectCaption(capId) {
    selectedCapId = capId;
    renderCaptionTabs();
    const cap = sceneCaptions.find(c => +c.id === +capId);
    if (!cap) return;
    showCaptionEditor(true);
    populateCaptionEditor(cap);
    if (_activeCapOv==='bg')  { _showBgEditor(true);  _populateBgEditor(cap); }
    if (_activeCapOv==='pos') { _showPosEditor(true); syncPosInputs(cap); }
    if (typeof _syncFontOverlay === 'function') _syncFontOverlay(cap);
    // Never auto-open overlay here — it breaks canvas dragging
}

// ── showCaptionEditor ─────────────────────────────────────────────────────────
function showCaptionEditor(show) {
    const ed=document.getElementById('captionEditor'), ns=document.getElementById('captionNoSel');
    if(ed) ed.style.display=show?'block':'none';
    if(ns) ns.style.display=show?'none':'block';
}

// ── populateCaptionEditor (text overlay) ─────────────────────────────────────
function populateCaptionEditor(cap) {
    const isMain=(cap.caption_name||'').toLowerCase()==='main';
    _updateVisBtn(cap);
    const dw=document.getElementById('capDeleteWrap');
    if(dw) dw.style.display=isMain?'none':'block';
    const ta=document.getElementById('capText');
    if(ta) ta.value=cap.text_content||'';
    _applyToAllScenes=GLOBAL_CAP_NAMES.includes((cap.caption_name||'').toLowerCase().trim());
    _updateGlobalLabel();
}

// ── _populateBgEditor ────────────────────────────────────────────────────────
function _populateBgEditor(cap) {
    const bgEnabled=(cap.bg_enabled===1||cap.bg_enabled==='1'||cap.bg_enabled===true);
    const bgChk=document.getElementById('capBgEnabled'), bgLbl=document.getElementById('capBgEnableLabel');
    if(bgChk) bgChk.checked=bgEnabled;
    if(bgLbl) bgLbl.style.color=bgEnabled?'var(--info)':'var(--muted)';
    const bc=document.getElementById('capBgColor');
    if(bc) bc.value=_toHex(cap.bg_color||'#000000');
    const ba=document.getElementById('capBgAlpha'), bv=document.getElementById('capBgAlphaVal');
    if(ba){ ba.value=Math.round((parseFloat(cap.bg_opacity)||0.7)*100); if(bv) bv.textContent=ba.value+'%'; }
    const bcol=document.getElementById('capBorderColor'), bthk=document.getElementById('capBorderThick');
    const bthkv=document.getElementById('capBorderThickVal'), bprev=document.getElementById('capBorderPreview');
    const bColor=_toHex(cap.caption_box_border_color||'#ffffff');
    const bThick=parseInt(cap.caption_box_border_thickness)||0;
    if(bcol) bcol.value=bColor; if(bthk) bthk.value=bThick;
    if(bthkv) bthkv.textContent=bThick+'px';
    if(bprev){ bprev.style.borderWidth=bThick+'px'; bprev.style.borderColor=bColor; bprev.style.borderStyle=bThick>0?'solid':'none'; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function _updateVisBtn(cap) {
    const vb=document.getElementById('capVisBtn'); if(!vb) return;
    vb.textContent=+cap.is_visible?'👁 Visible':'🚫 Hidden';
    vb.style.background=+cap.is_visible?'var(--success)':'var(--muted)';
}
function _updateGlobalLabel() {
    const wrap=document.getElementById('capGlobalWrap'); if(!wrap) return;
    if(!selectedCapId){ wrap.style.display='none'; return; }
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);
    if(!cap){ wrap.style.display='none'; return; }
    const name=(cap.caption_name||'').toLowerCase().trim();
    if(GLOBAL_CAP_NAMES.includes(name)){ wrap.style.display='flex'; const chk=document.getElementById('capGlobalChk'); if(chk) chk.checked=_applyToAllScenes; }
    else wrap.style.display='none';
}
function _toHex(color) {
    if(!color) return '#ffffff'; color=color.trim();
    if(/^#[0-9a-f]{6}$/i.test(color)) return color;
    if(/^#[0-9a-f]{3}$/i.test(color)) return '#'+color[1]+color[1]+color[2]+color[2]+color[3]+color[3];
    try{ const c=document.createElement('canvas');c.width=c.height=1;const x=c.getContext('2d');x.fillStyle=color;x.fillRect(0,0,1,1);const d=x.getImageData(0,0,1,1).data;return'#'+[d[0],d[1],d[2]].map(v=>v.toString(16).padStart(2,'0')).join(''); }catch(e){return'#ffffff';}
}
function syncPosInputs(cap) {
    const px=document.getElementById('capPosX'),py=document.getElementById('capPosY'),pw=document.getElementById('capWidth');
    const xl=document.getElementById('capPosXLbl'),yl=document.getElementById('capPosYLbl');
    const rx=Math.round(cap.position_x||0),ry=Math.round(cap.position_y||0);
    if(px)px.value=rx;if(py)py.value=ry;if(pw)pw.value=Math.round(cap.width||320);
    if(xl)xl.textContent=rx;if(yl)yl.textContent=ry;
}
function _capBoxH(cap) {
    const bw=parseInt(cap.width)||320,fs=parseInt(cap.fontsize)||22,lh=fs+7;
    const extraVPad=cap._extraVPad??parseInt(cap.rotation)??0,pad=10+Math.round(extraVPad/2);
    const words=(cap.text_content||'').split(' ');
    const family=FONT_NORM[cap.fontfamily||'']||(cap.fontfamily||'')||'Arial,sans-serif';
    const bold=(cap.fontweight==='bold'||cap.fontweight==='700')?'bold ':'';
    const italic=cap.fontstyle==='italic'?'italic ':'';
    ctx.font=italic+bold+fs+'px '+family;
    let lines=1,ln='';
    words.forEach(w=>{const t=ln?ln+' '+w:w;if(ctx.measureText(t).width>bw&&ln){lines++;ln=w;}else ln=t;});
    return lines*lh+pad*2;
}

// ── Caption field interactions ────────────────────────────────────────────────
function toggleCapVisible() {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.is_visible=+cap.is_visible?0:1;
    const fd=new FormData();fd.append('ajax_action','toggle_caption_visible');fd.append('caption_id',cap.id);fd.append('is_visible',cap.is_visible);
    fetch(location.href,{method:'POST',body:fd});_updateVisBtn(cap);renderCaptionTabs();
}
function toggleBgEnabled(checked) {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.bg_enabled=checked?1:0;
    const lbl=document.getElementById('capBgEnableLabel');if(lbl)lbl.style.color=checked?'var(--info)':'var(--muted)';
    capFieldChanged('bg_enabled',cap.bg_enabled);
}
function capBorderChanged() {
    const colorEl=document.getElementById('capBorderColor'),thickEl=document.getElementById('capBorderThick'),preview=document.getElementById('capBorderPreview');
    const color=colorEl?colorEl.value:'#ffffff',thick=thickEl?parseInt(thickEl.value):0;
    if(preview){preview.style.borderWidth=thick+'px';preview.style.borderColor=color;preview.style.borderStyle=thick>0?'solid':'none';}
    capFieldChanged('caption_box_border_color',color);capFieldChanged('caption_box_border_thickness',thick);
}
function capPosInput(field,val) {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap[field]=parseFloat(val)||0;syncPosInputs(cap);capFieldChanged(field,cap[field]);
}
function moveCapArrow(dx,dy) {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const w=parseFloat(cap.width)||120,bh=_capBoxH(cap);
    cap.position_x=Math.max(CAP_MARGIN,Math.min(CW-CAP_MARGIN-w,(parseFloat(cap.position_x)||0)+dx));
    cap.position_y=Math.max(0,Math.min(CH-Math.max(20,bh),(parseFloat(cap.position_y)||0)+dy));
    syncPosInputs(cap);capFieldChanged('position_x',Math.round(cap.position_x));capFieldChanged('position_y',Math.round(cap.position_y));
}
function centreCaption() {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320;
    cap.position_x=Math.round((CW-bw)/2);cap.position_y=Math.round((CH-_capBoxH(cap))/2);
    syncPosInputs(cap);capFieldChanged('position_x',cap.position_x);capFieldChanged('position_y',cap.position_y);
}
function snapCaption(preset) {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320,bh=_capBoxH(cap);
    if(preset==='top')cap.position_y=10;
    else if(preset==='middle')cap.position_y=Math.round((CH-bh)/2);
    else if(preset==='bottom')cap.position_y=Math.round(CH-bh-14);
    else if(preset==='centre-h')cap.position_x=Math.round((CW-bw)/2);
    syncPosInputs(cap);capFieldChanged('position_x',Math.round(cap.position_x));capFieldChanged('position_y',Math.round(cap.position_y));
}

// ── capFieldChanged (debounce + global propagation) ───────────────────────────
function capFieldChanged(field,value) {
    if(!selectedCapId)return; const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if(field==='width'){const x=parseFloat(cap.position_x)||0;value=Math.max(40,Math.min(CW-CAP_MARGIN-x,parseFloat(value)||40));}
    else if(field==='position_x'){const w=parseFloat(cap.width)||120;value=Math.max(CAP_MARGIN,Math.min(CW-CAP_MARGIN-w,parseFloat(value)||0));}
    else if(field==='position_y'){const bh=_capBoxH(cap);value=Math.max(0,Math.min(CH-Math.max(20,bh),parseFloat(value)||0));}
    cap[field]=value;
    // text_content is saved exclusively via the Save button — skip debounce here
    if(field==='text_content'){ startCaptionAnim(cap); return; }
    if(!_capDirty[cap.id])_capDirty[cap.id]={};_capDirty[cap.id][field]=value;
    clearTimeout(_capSaveTimers[cap.id]);_capSaveTimers[cap.id]=setTimeout(()=>_saveCaption(cap.id),600);
    if(field==='animation_style'||field==='animation_speed')startCaptionAnim(cap);
    if(!_applyToAllScenes)return;
    const capName=(cap.caption_name||'').toLowerCase().trim();
    if(!GLOBAL_CAP_NAMES.includes(capName))return;
    ALL_CAPTIONS.forEach(other=>{
        if(+other.id===+cap.id)return;
        if((other.caption_name||'').toLowerCase().trim()!==capName)return;
        other[field]=value;
        if(!_capDirty[other.id])_capDirty[other.id]={};_capDirty[other.id][field]=value;
        clearTimeout(_capSaveTimers[other.id]);_capSaveTimers[other.id]=setTimeout(()=>_saveCaption(other.id),600);
    });
}

// ── Save / Add / Delete ───────────────────────────────────────────────────────
async function _saveCaption(capId) {
    const dirty = _capDirty[capId];
    if (!dirty || !Object.keys(dirty).length) return;
    const fd = new FormData();
    fd.append('ajax_action','save_caption'); fd.append('caption_id', capId);
    Object.entries(dirty).forEach(([k,v]) => fd.append(k, v));
    _capDirty[capId] = {};
    await fetch(location.href, { method:'POST', body:fd });
}

// ── Save caption text + generate audio (called by Save button) ────────────────
// ── Live-update caption text on canvas while typing (no DB write) ─────────────
function _capTextLiveUpdate(val) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    cap.text_content = val;
    const globalCap = ALL_CAPTIONS.find(c => +c.id === +cap.id);
    if (globalCap) globalCap.text_content = val;
    startCaptionAnim(cap);
    _staticRepaint();
}

async function saveMainCaptionText() {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    const ta  = document.getElementById('capText');
    const txt = (ta ? ta.value : '') ;
    const btn = document.getElementById('capSaveBtn');

    // Update in memory
    cap.text_content = txt;
    startCaptionAnim(cap);

    // Update ALL_CAPTIONS too
    const globalCap = ALL_CAPTIONS.find(c => +c.id === +cap.id);
    if (globalCap) globalCap.text_content = txt;

    // Save to hdb_captions via save_caption_text action (direct text save)
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }
    try {
        const fd = new FormData();
        fd.append('ajax_action',  'save_caption_text');
        fd.append('caption_id',   cap.id);
        fd.append('text_content', txt);
        await fetch(location.href, { method:'POST', body:fd });

        // Only auto-generate audio for the main caption
        const isMain = (cap.caption_name||'').toLowerCase() === 'main';
        if (isMain) {
            const sc = SCENES.find(s => +s.id === +cap.story_id);
            if (sc) {
                if (btn) { btn.disabled = true; btn.textContent = '🎙️ Generating…'; }
                await generateSceneAudio(txt, sc);
            }
        }
        if (btn) { btn.textContent = '✅ Saved!'; setTimeout(() => { btn.disabled=false; btn.textContent='💾 Save & Generate Audio'; }, 2000); }
    } catch(e) {
        console.warn('saveMainCaptionText error:', e);
        if (btn) { btn.disabled=false; btn.textContent='💾 Save & Generate Audio'; }
    }
}

// ── Auto-generate scene audio ─────────────────────────────────────────────────
let _audioGenInProgress = false;
async function generateSceneAudio(text, sc) {
    if (!text || !text.trim()) return;
    if (_audioGenInProgress) return;
    const cleanText = text.replace(/<break[^>]*>/gi,'').trim();
    if (!cleanText) return;
    const isGuest  = /^GUEST\s*:/i.test(cleanText);
    const voiceId  = (isGuest && _guestVoiceId) ? _guestVoiceId : _hostVoiceId;
    if (!voiceId) { console.warn('No voice set — skipping auto audio gen'); return; }
    _audioGenInProgress = true;
    // Show subtle status
    const statusEl = document.getElementById('capAudioStatus');
    if (statusEl) { statusEl.textContent = '🎙️ Generating audio…'; statusEl.style.display = 'block'; }
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'generate_scene_audio');
        fd.append('text',        cleanText);
        fd.append('voice_id',    voiceId);
        fd.append('lang_code',   LANG_CODE);
        fd.append('rate',        AUDIO_SPEED);
        fd.append('scene_id',    sc.id);
        fd.append('podcast_id',  PODCAST_ID);
        const r    = await fetch('wizard_step2.php', { method:'POST', body:fd });
        const data = await r.json();
        if (data.success && data.filename) {
            sc.audio_file = data.filename;
            if (statusEl) { statusEl.textContent = '✅ Audio ready'; setTimeout(() => { statusEl.style.display='none'; }, 2500); }
        } else {
            if (statusEl) { statusEl.textContent = '⚠️ Audio gen failed'; setTimeout(() => { statusEl.style.display='none'; }, 3000); }
        }
    } catch(e) {
        console.warn('generateSceneAudio error:', e);
        if (statusEl) { statusEl.style.display = 'none'; }
    } finally {
        _audioGenInProgress = false;
    }
}
async function addCaption() {
    const sc=SCENES[currentIndex];const name='cap'+(sceneCaptions.length+1);
    const fd=new FormData();fd.append('ajax_action','add_caption');fd.append('story_id',sc.id);fd.append('caption_name',name);fd.append('text_content','New caption');
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
        if(data.success&&data.caption){const newCap=data.caption;ALL_CAPTIONS.push(newCap);
            captionStates[newCap.id]={show:'New caption',full:'New caption',words:['New','caption'],karIdx:0,timer:null};
            selectedCapId=parseInt(newCap.id);loadSceneCaptions(sc.id);selectCaption(newCap.id);}
    }catch(e){console.warn('addCaption',e);}
}
async function addImageCaption() {
    const sc=SCENES[currentIndex];
    const fd=new FormData();fd.append('ajax_action','add_caption');fd.append('story_id',sc.id);fd.append('caption_name','logo');fd.append('caption_type','image');fd.append('text_content','');
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
        if(data.success&&data.caption){const newCap=data.caption;ALL_CAPTIONS.push(newCap);
            captionStates[newCap.id]={show:'',full:'',words:[],karIdx:0,timer:null};
            selectedCapId=parseInt(newCap.id);loadSceneCaptions(sc.id);selectCaption(newCap.id);}
    }catch(e){console.warn('addImageCaption',e);}
}
async function deleteCaption() {
    if(!selectedCapId)return;const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if((cap.caption_name||'').toLowerCase()==='main'){alert('Cannot delete the main caption.');return;}
    if(!confirm('Delete caption "'+cap.caption_name+'"?'))return;
    const fd=new FormData();fd.append('ajax_action','delete_caption');fd.append('caption_id',cap.id);
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
        if(data.success){const idx=ALL_CAPTIONS.findIndex(c=>+c.id===+cap.id);if(idx>=0)ALL_CAPTIONS.splice(idx,1);
            selectedCapId=null;showCaptionEditor(false);loadSceneCaptions(SCENES[currentIndex].id);}
    }catch(e){console.warn('deleteCaption',e);}
}

// ── Play / Pause toggle ───────────────────────────────────────────────────────
function toggleScenePlay() {
    const btn   = document.getElementById('navPlayBtn');
    const scene = SCENES[currentIndex];
    const path  = mediaPath(scene);
    const isVid = path && isVideoFile(scene.image_file || '');

    if (!isVid) return; // nothing to play for static images

    if (sceneVideo.paused) {
        sceneVideo.play().then(() => {
            if (!_playing) startVideoLoop();
            btn.textContent = '⏸';
            btn.classList.add('playing');
        }).catch(console.warn);
    } else {
        sceneVideo.pause();
        _playing = false;
        cancelAnimationFrame(_rafId);
        btn.textContent = '▶';
        btn.classList.remove('playing');
    }
}

function _syncPlayBtn() {
    const btn = document.getElementById('navPlayBtn');
    if (!btn) return;
    if (!sceneVideo.paused) {
        btn.textContent = '⏸';
        btn.classList.add('playing');
    } else {
        btn.textContent = '▶';
        btn.classList.remove('playing');
    }
}

// ── Init ─────────────────────────────────────────────────────────────────────
(function init() {
    showOverlay('Loading podcast…');
    loadScene(0);
    // Init background music if already set in DB
    if (_currentPodcastMusic) _applyBgAudio();
    // Sync volume sliders to DB values
    _syncVolumeSliders();
    // Sync speed slider to DB value
    const spd = document.getElementById('playbackSpeedSlider');
    if (spd) {
        spd.value = currentPlaybackSpeed;
        document.querySelectorAll('.speed-preset').forEach(b =>
            b.classList.toggle('active', parseFloat(b.textContent) === currentPlaybackSpeed));
    }
})();
</script>

<!-- ══ GENERATE SPINNER OVERLAY ══ -->
<div id="generateOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,42,68,.85);
     z-index:99995;align-items:center;justify-content:center;flex-direction:column;gap:20px;">
    <div style="width:52px;height:52px;border:5px solid rgba(255,255,255,.2);border-top-color:#10b981;
                border-radius:50%;animation:spin .8s linear infinite;"></div>
    <div style="color:#fff;font-size:16px;font-weight:700;" id="generateSpinnerTitle">⚡ Generating your video…</div>
    <div style="color:rgba(255,255,255,.65);font-size:13px;text-align:center;max-width:280px;line-height:1.6;" id="generateSpinnerMsg">
        Encoding scenes · Burning captions · Mixing audio<br>This may take 30–60 seconds
    </div>
    <div style="color:rgba(255,255,255,.45);font-size:11px;" id="generateSpinnerTimer">⏱ 0s elapsed</div>
</div>

<!-- ══ SCHEDULE / PUBLISH MODAL ══ -->
<style>
@keyframes bpmSlideUp { from { transform: translateY(40px); opacity:0; } to { transform: translateY(0); opacity:1; } }
@keyframes bpmSpin    { to { transform: rotate(360deg); } }
.bpm-overlay { display:none;position:fixed;inset:0;background:rgba(15,42,68,.72);backdrop-filter:blur(4px);z-index:99990;align-items:flex-end;justify-content:center;padding:0; }
.bpm-overlay.open { display:flex; }
@media(min-width:600px){ .bpm-overlay { align-items:center;padding:16px; } }
.bpm-modal { background:#fff;border-radius:22px 22px 0 0;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:0 -8px 40px rgba(0,0,0,.25);animation:bpmSlideUp .28s cubic-bezier(.34,1.56,.64,1) both;-webkit-overflow-scrolling:touch; }
@media(min-width:600px){ .bpm-modal { border-radius:22px;box-shadow:0 24px 80px rgba(0,0,0,.35); } }
.bpm-head { display:flex;align-items:center;justify-content:space-between;padding:18px 20px 12px;border-bottom:1px solid #e2e8f0; }
.bpm-head-left { display:flex;align-items:center;gap:12px; }
.bpm-head-icon  { font-size:26px; }
.bpm-head-title { font-size:16px;font-weight:800;color:#0f2a44; }
.bpm-head-sub   { font-size:12px;color:#64748b;margin-top:2px; }
.bpm-close { background:#f1f5f9;border:none;border-radius:50%;width:32px;height:32px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;transition:background .15s;flex-shrink:0; }
.bpm-close:hover { background:#e2e8f0; }
.bpm-saved { display:flex;align-items:center;gap:10px;padding:10px 20px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;font-size:13px;color:#065f46;font-weight:600; }
.bpm-saved-dot { width:9px;height:9px;border-radius:50%;background:#10b981;flex-shrink:0;box-shadow:0 0 0 3px rgba(16,185,129,.2); }
.bpm-inner { padding:16px 20px 20px; }
.bpm-lbl { font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px; }
.bpm-platforms { display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-bottom:6px; }
.bpm-plat { display:flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1.5px solid #e2e8f0;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;user-select:none;background:#f8fafc; }
.bpm-plat.sel { background:#f0fdf4;border-color:#86efac;color:#065f46; }
.bpm-plat.disconnected { opacity:.4;cursor:not-allowed; }
.bpm-plat-icon { font-size:15px; }
.bpm-warn { font-size:12px;color:#dc2626;font-weight:600;margin-bottom:8px;padding:6px 10px;background:#fef2f2;border-radius:8px; }
.bpm-ctabs { display:flex;gap:6px;margin:12px 0 6px; }
.bpm-ctab { flex:1;padding:7px 0;border-radius:8px;border:1.5px solid #e2e8f0;font-size:12px;font-weight:700;color:#64748b;background:#f8fafc;cursor:pointer;transition:all .15s;font-family:inherit; }
.bpm-ctab.active { background:#0f2a44;border-color:#0f2a44;color:#fff; }
.bpm-ctab-panel { display:none; }
.bpm-ctab-panel.active { display:block; }
.bpm-textarea { width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;color:#1e293b;font-family:inherit;resize:vertical;outline:none;min-height:72px;transition:border-color .15s;box-sizing:border-box; }
.bpm-textarea:focus { border-color:#0f2a44; }
.bpm-quick { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px; }
.bpm-qpill { padding:6px 12px;border-radius:20px;border:1.5px solid #e2e8f0;font-size:12px;font-weight:600;color:#64748b;background:#f8fafc;cursor:pointer;transition:all .15s;font-family:inherit; }
.bpm-qpill.active { background:#0f2a44;border-color:#0f2a44;color:#fff; }
.bpm-date-row { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px; }
.bpm-input { width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;color:#1e293b;font-family:inherit;outline:none;background:#f8fafc;transition:border-color .15s;box-sizing:border-box; }
.bpm-input:focus { border-color:#0f2a44; }
.bpm-footer { display:grid;grid-template-columns:1fr 1fr;gap:8px; }
.bpm-dl-btn { grid-column:span 2;padding:10px;background:#f8fafc;border:1.5px solid #10b981;border-radius:10px;font-size:13px;font-weight:700;color:#059669;cursor:pointer;transition:all .15s;font-family:inherit; }
.bpm-dl-btn:hover { background:#f0fdf4; }
.bpm-btn-now  { padding:10px;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;transition:all .15s;font-family:inherit; }
.bpm-btn-sched { padding:10px;background:linear-gradient(135deg,#0f2a44,#0284c7);border:none;border-radius:10px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;transition:all .15s;font-family:inherit; }
.bpm-btn-skip { grid-column:span 2;padding:8px;background:none;border:none;font-size:12px;color:#94a3b8;cursor:pointer;text-decoration:underline;font-family:inherit; }
.bpm-confirm-icon  { text-align:center;font-size:52px;padding:28px 0 0; }
.bpm-confirm-title { text-align:center;font-size:22px;font-weight:800;color:#0f2a44;margin-top:10px; }
.bpm-confirm-sub   { text-align:center;font-size:14px;color:#64748b;margin-top:6px;padding-bottom:6px; }
.bpm-confirm-pills { display:flex;flex-wrap:wrap;justify-content:center;gap:8px;padding:14px 20px; }
.bpm-confirm-pill  { padding:6px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:20px;font-size:13px;font-weight:700;color:#065f46; }
.bpm-confirm-done  { display:block;margin:0 20px 24px;padding:13px;background:linear-gradient(135deg,#10b981,#059669);border:none;border-radius:12px;font-size:15px;font-weight:700;color:#fff;cursor:pointer;width:calc(100% - 40px);transition:all .15s;font-family:inherit; }
</style>

<div class="bpm-overlay" id="vsOverlay">
  <div class="bpm-modal">
    <div id="vsMain">
      <div class="bpm-head">
        <div class="bpm-head-left">
          <div class="bpm-head-icon">📤</div>
          <div>
            <div class="bpm-head-title">Publish Video</div>
            <div class="bpm-head-sub" id="vsSubTitle">Choose where &amp; when to share</div>
          </div>
        </div>
        <button class="bpm-close" onclick="closeSchedModal()">✕</button>
      </div>
      <div id="bpmVmBody">
        <div class="bpm-saved" id="vsFilenameDisplay">
          <div class="bpm-saved-dot"></div>
          <span>Video ready</span>
        </div>
        <div class="bpm-inner">
          <div class="bpm-lbl">Platforms</div>
          <div class="bpm-platforms">
            <div class="bpm-plat sel"          data-p="instagram" onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">📸</span> Instagram</div>
            <div class="bpm-plat sel"          data-p="tiktok"    onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">🎵</span> TikTok</div>
            <div class="bpm-plat sel"          data-p="youtube"   onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">▶️</span> YouTube</div>
            <div class="bpm-plat disconnected" data-p="facebook"                              ><span class="bpm-plat-icon">📘</span> Facebook</div>
            <div class="bpm-plat disconnected" data-p="twitter"                               ><span class="bpm-plat-icon">🐦</span> X</div>
            <div class="bpm-plat disconnected" data-p="linkedin"                              ><span class="bpm-plat-icon">💼</span> LinkedIn</div>
          </div>
          <div class="bpm-warn" id="vsWarn" style="display:none;">Select at least one platform</div>
          <div class="bpm-ctabs">
            <button class="bpm-ctab active" onclick="vsSwitchTab('caption',this)">✍️ Caption</button>
            <button class="bpm-ctab"        onclick="vsSwitchTab('keywords',this)">🔑 Keywords</button>
            <button class="bpm-ctab"        onclick="vsSwitchTab('hashtags',this)">#️⃣ Hashtags</button>
          </div>
          <div class="bpm-ctab-panel active" id="vs-tab-caption">
            <textarea class="bpm-textarea" id="vsCaption" placeholder="Caption text…"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="vs-tab-keywords">
            <textarea class="bpm-textarea" id="vsKeywords" placeholder="Keywords…" style="height:54px;"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="vs-tab-hashtags">
            <textarea class="bpm-textarea" id="vsHashtags" placeholder="#hashtags…" style="height:54px;"></textarea>
          </div>
          <div class="bpm-lbl" style="margin-top:12px;">Schedule</div>
          <div class="bpm-quick">
            <button class="bpm-qpill"        onclick="vsQuick(this,0)"  >Now</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,1)"  >+1hr</button>
            <button class="bpm-qpill active" onclick="vsQuick(this,24)" >Tomorrow</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,72)" >+3 days</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,168)">Next week</button>
          </div>
          <div class="bpm-date-row">
            <div><div class="bpm-lbl">Date</div><input type="date" class="bpm-input" id="vsDate"></div>
            <div><div class="bpm-lbl">Time</div><input type="time" class="bpm-input" id="vsTime" value="09:00"></div>
          </div>
          <div class="bpm-footer">
            <button class="bpm-dl-btn"    onclick="vsDownload()">⬇ Download MP4</button>
            <button class="bpm-btn-now"   onclick="vsPostNow()">⚡ Post Now</button>
            <button class="bpm-btn-sched" onclick="vsSchedule()">🗓 Schedule</button>
            <button class="bpm-btn-skip"  onclick="closeSchedModal()">Skip — publish manually</button>
          </div>
        </div>
      </div>
    </div>
    <div id="vsConfirm" style="display:none;">
      <div class="bpm-confirm-icon"  id="vsConfirmIcon">🗓</div>
      <div class="bpm-confirm-title" id="vsConfirmTitle">Scheduled!</div>
      <div class="bpm-confirm-sub"   id="vsConfirmSub"></div>
      <div class="bpm-confirm-pills" id="vsConfirmPills"></div>
      <button class="bpm-confirm-done" onclick="closeSchedModal()">Done ✓</button>
    </div>
  </div>
</div>

</body>
</html>

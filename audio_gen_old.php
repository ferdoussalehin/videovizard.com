<?php
ob_start();  // ← ADD THIS AS VERY FIRST LINE
session_start();
error_reporting(0);
ini_set('display_errors', 0);

$admin_id    = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id   = $_SESSION['client_id'];

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';
require_once 'generate_image_api.php';

// Get podcast_id from URL
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';
// Update podcast thumbnail based on available media
// Update podcast thumbnail based on available media
// Update podcast thumbnail based on available media
if ($url_podcast_id > 0) {
    // First update the updated_at timestamp
    $update_sql = "UPDATE hdb_podcasts SET updated_at = NOW() WHERE id = $url_podcast_id";
    mysqli_query($conn, $update_sql);
    
    // Check for image files in podcast stories (priority)
    $image_check_sql = "SELECT image_file FROM hdb_podcast_stories 
                        WHERE podcast_id = $url_podcast_id 
                        AND image_file IS NOT NULL 
                        AND image_file != '' 
                        LIMIT 1";
    $image_result = mysqli_query($conn, $image_check_sql);
    
    if ($image_result && mysqli_num_rows($image_result) > 0) {
        // Found an image - use the first one as thumbnail
        $image_row = mysqli_fetch_assoc($image_result);
        $thumbnail = $image_row['image_file'];
        
        $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";
        mysqli_query($conn, $thumbnail_sql);
    } else {
        // No image found, check for video files
        $video_check_sql = "SELECT video_file FROM hdb_podcast_stories 
                            WHERE podcast_id = $url_podcast_id 
                            AND video_file IS NOT NULL 
                            AND video_file != '' 
                            LIMIT 1";
        $video_result = mysqli_query($conn, $video_check_sql);
        
        if ($video_result && mysqli_num_rows($video_result) > 0) {
            // Found a video - use video filename as thumbnail reference
            $video_row = mysqli_fetch_assoc($video_result);
            $thumbnail = $video_row['video_file'];
            
            $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";
            mysqli_query($conn, $thumbnail_sql);
        }
        // If no media found, leave thumbnail as is (don't overwrite)
    }
}
// Get scenes for the podcast_id from URL
$scenes_result = null;
$podcast_title = '';
if ($url_podcast_id > 0) {
    // Get podcast title
    $title_query = mysqli_query($conn, "SELECT title FROM hdb_podcasts WHERE id = $url_podcast_id AND client_id = '$client_id'");
    if ($title_query && mysqli_num_rows($title_query) > 0) {
        $title_row = mysqli_fetch_assoc($title_query);
        $podcast_title = $title_row['title'];
    }
    
    // Get scenes
    $scenes_result = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id ORDER BY id");
}

// Define available voices
// Full Expanded Voice List
$voices = [
    '🇺🇸 English (US)' => [
        'en-US-GuyNeural' => 'Guy - Calm, Steady Male ⭐ (TOP CHOICE)',
        'en-US-DavisNeural' => 'Davis - Deep, Soothing Male ⭐',
        'en-US-SaraNeural' => 'Sara - Empathetic, Warm Female ⭐',
        'en-US-JennyNeural' => 'Jenny - Soft, Gentle Female',
    ],
    '🇮🇳 Hindi' => [
        'hi-IN-SwaraNeural' => 'Swara - Female, Warm & Calming ⭐',
        'hi-IN-MadhurNeural' => 'Madhur - Male, Soothing & Confident ⭐',
    ],
    '🇵🇰 Urdu' => [
        'ur-PK-UzmaNeural' => 'Uzma - Female, Gentle & Empathetic ⭐',
        'ur-PK-AsadNeural' => 'Asad - Male, Calm & Steady ⭐',
    ],
    '🇸🇦 / 🇪🇬 Arabic' => [
        'ar-SA-ZariyahNeural' => 'Zariyah - Female, Clear & Relaxing ⭐',
        'ar-SA-HamedNeural' => 'Hamed - Male, Calm & Authoritative ⭐',
        'ar-EG-SalmaNeural' => 'Salma - Female, Soothing',
    ],
    '🇪🇸 Spanish' => [
        'es-ES-ElviraNeural' => 'Elvira - Female, Spain ⭐',
        'es-ES-AlvaroNeural' => 'Alvaro - Male, Spain ⭐',
        'es-MX-DaliaNeural' => 'Dalia - Female, Mexico ⭐',
        'es-MX-JorgeNeural' => 'Jorge - Male, Mexico ⭐',
    ],
    '🇫🇷 French' => [
        'fr-FR-DeniseNeural' => 'Denise - Female, France ⭐',
        'fr-FR-HenriNeural' => 'Henri - Male, France ⭐',
        'fr-CA-SylvieNeural' => 'Sylvie - Female, Canada ⭐',
        'fr-CA-JeanNeural' => 'Jean - Male, Canada ⭐',
    ],
];

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_library') {
    ob_clean();
    header('Content-Type: application/json');
    
    $log = __DIR__ . '/a_debug.log';
    $video_dir = '/home/syjy0p3q5yjb/public_html/stressreleasor.com/podcast_videos/';
    
    $videos = [];
    
    if (is_dir($video_dir)) {
        $files = scandir($video_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4','webm','mov','avi','mkv','m4v'])) {
                $fsize = @filesize($video_dir . $file);
                $videos[] = [
                    'video_name' => utf8_encode($file),
                    'file_size' => $fsize ? $fsize : 0
                ];
            }
        }
    }
    
    $json = json_encode($videos, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    
    error_log(date('Y-m-d H:i:s') . " | json_error=" . json_last_error() . " | json_msg=" . json_last_error_msg() . " | count=" . count($videos) . " | json_len=" . strlen($json) . "\n", 3, $log);
    
    echo $json;
    exit;
}

// ---- NOW the login check ----
if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ---------- AJAX: Get Scenes for Podcast ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scenes') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $pid = (int)$_POST['podcast_id'];
    $scenes = [];
    $r = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$pid ORDER BY id");
    while($row = mysqli_fetch_assoc($r)) $scenes[] = $row;
    echo json_encode($scenes);
    exit;
}

// ---------- AJAX: Update Scene Text ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_text') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? '');
    
    $sql = "UPDATE hdb_podcast_stories SET text_contents='$text_contents' WHERE id=$scene_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Update Scene Row ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? '');
    $video_file = mysqli_real_escape_string($conn, $_POST['video_file'] ?? '');
    $audio_file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');
    $media_type = $_POST['media_type'] ?? 'image';

    if ($media_type === 'video' && !empty($video_file)) {
        $sql = "UPDATE hdb_podcast_stories SET video_file='$video_file' WHERE id=$scene_id";
    } elseif ($media_type === 'audio' && !empty($audio_file)) {
        $sql = "UPDATE hdb_podcast_stories SET audio_file='$audio_file' WHERE id=$scene_id";
    } else {
        $sql = "UPDATE hdb_podcast_stories SET image_file='$image_file' WHERE id=$scene_id";
    }
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Get Media Library ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_media_library') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $image_dir = __DIR__ . '/podcast_images/';
    $images = [];
    
    $db_images = [];
    $r = mysqli_query($conn, "SELECT * FROM hdb_image_data ORDER BY id DESC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $db_images[$row['image_name']] = $row;
        }
    }
    
    if (is_dir($image_dir)) {
        $files = scandir($image_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) continue;
            
            $filepath = $image_dir . $file;
            $file_size = file_exists($filepath) ? filesize($filepath) : 0;
            
            $img_data = [
                'image_name' => $file,
                'hashtags' => '',
                'file_size' => $file_size
            ];
            
            if (isset($db_images[$file])) {
                $img_data['hashtags'] = $db_images[$file]['image_hashtags'] ?? $db_images[$file]['hashtags'] ?? '';
            }
            
            $images[] = $img_data;
        }
    }
    
    echo json_encode($images);
    exit;
}

// ---------- AJAX: Upload Audio File ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_audio') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $response = ['success' => false, 'message' => ''];
    
    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = isset($_FILES['audio_file']) ? $_FILES['audio_file']['error'] : 'No file uploaded';
        $response['message'] = 'Upload error: ' . $error_msg;
        echo json_encode($response);
        exit;
    }
    
    $file = $_FILES['audio_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext !== 'mp3') {
        $response['message'] = 'Only MP3 files are allowed';
        echo json_encode($response);
        exit;
    }
    
    // Use podcast_audios folder (plural)
    $audio_dir = __DIR__ . '/podcast_audios/';
    if (!is_dir($audio_dir)) {
        mkdir($audio_dir, 0777, true);
    }
    
    $filename = 'audio_' . $scene_id . '_' . time() . '.mp3';
    $destination = $audio_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Update the scene with the audio file
        $update_sql = "UPDATE hdb_podcast_stories SET audio_file='$filename' WHERE id=$scene_id";
        if (mysqli_query($conn, $update_sql)) {
            $response['success'] = true;
            $response['message'] = 'Audio uploaded successfully';
            $response['filename'] = $filename;
            $response['file_url'] = 'podcast_audios/' . $filename;
        } else {
            $response['message'] = 'Database update failed: ' . mysqli_error($conn);
        }
    } else {
        $response['message'] = 'Failed to move uploaded file';
    }
    
    echo json_encode($response);
    exit;
}

// ---------- AJAX: Save Voice Settings ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_voice_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $host_voice = mysqli_real_escape_string($conn, $_POST['host_voice'] ?? '');
    $guest_voice = mysqli_real_escape_string($conn, $_POST['guest_voice'] ?? '');
    $rate_picker = mysqli_real_escape_string($conn, $_POST['rate_picker'] ?? '');
    $admin_id = (int)$_SESSION['admin_id'];
    
    // Save to session
    $_SESSION['host_voice'] = $host_voice;
    $_SESSION['guest_voice'] = $guest_voice;
    $_SESSION['rate_picker'] = $rate_picker;
    
    echo json_encode(['success' => true]);
    exit;
}

// ---------- AJAX: Load Voice Settings ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_voice_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $host_voice   = $_SESSION['host_voice'] ?? '';
    $guest_voice  = $_SESSION['guest_voice'] ?? '';
    $rate_picker  = $_SESSION['rate_picker'] ?? '';
    
    echo json_encode([
        'success' => true,
        'host_voice' => $host_voice,
        'rate_picker' => $rate_picker,
        'guest_voice' => $guest_voice
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>VideoVizard-From Idea to Video in Minutes</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;font-size:13px;padding:30px;background:#f0f2f5;color:#333}
.card{background:#fff;padding:25px;border-radius:12px;border:1px solid #e0e0e0;margin:0 auto 20px;max-width:1200px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
h1{margin:0 0 5px;color:#1e293b;font-size:22px}
.sub{color:#64748b;font-size:12px;margin-bottom:20px}
select{padding:8px 12px;border-radius:6px;border:1px solid #ddd;font-size:13px;width:100%;background:#fff}
.btn{border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;color:#fff;display:inline-flex;align-items:center;gap:6px}
.btn:disabled{background:#cbd5e1!important;cursor:not-allowed}
.btn-go{background:#7c3aed;padding:10px 24px;border-radius:8px;font-size:13px}.btn-go:hover:not(:disabled){background:#6d28d9}
.btn-gen{background:#2563eb}.btn-gen:hover:not(:disabled){background:#1d4ed8}
.btn-upload{background:#10b981}.btn-upload:hover{background:#059669}
.btn-save {
    background: #64748b;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 600;
    margin-left: 5px;
}
.btn-save:hover {
    background: #475569;
}
.btn-generate-all {
    background: #8b5cf6;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-generate-all:hover:not(:disabled) {
    background: #7c3aed;
}
table{width:100%;border-collapse:collapse;margin-top:15px}
th{background:#f8fafc;padding:10px;text-align:left;font-size:11px;color:#64748b;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.5px}
td{padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:middle}
tr:hover{background:#f8fafc}
tr.row-active {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
}
tr.row-completed {
    background: #ecfdf5;
    border-left: 4px solid #10b981;
}
tr.row-completed td {
    color: #065f46;
}
.img-thumb{width:45px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;transition:opacity .2s}.img-thumb:hover{opacity:.8}
.status-badge{padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;display:inline-block}
.st-done{background:#ecfdf5;color:#059669}
.st-pending{background:#fef3c7;color:#d97706}
.st-working{background:#eff6ff;color:#2563eb}
.st-error{background:#fef2f2;color:#dc2626}
.st-audio{background:#ede9fe;color:#6d28d9}
.text-editor {
    width: 250px;
    min-height: 60px;
    padding: 8px;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    line-height: 1.5;
    resize: vertical;
    background: #fff;
}
.text-editor:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.text-container {
    position: relative;
}
.text-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}
.text-header label {
    font-weight: 600;
    color: #334155;
    font-size: 11px;
}
.scene-count{font-size:11px;color:#64748b;margin:10px 0}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px}
/* Image Preview Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:9999;justify-content:center;align-items:center;cursor:pointer}
.modal-overlay.open{display:flex}
.modal-box{position:relative;max-height:90vh;max-width:90vw;animation:modalIn .2s ease}
.modal-box img{max-height:85vh;max-width:85vw;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.5)}
.modal-close{position:absolute;top:-12px;right:-12px;width:32px;height:32px;background:#fff;border:none;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);color:#333;font-weight:700}
.modal-close:hover{background:#f1f1f1}
.modal-info{text-align:center;color:#94a3b8;font-size:11px;margin-top:10px}
@keyframes modalIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}

.vidora-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    background: linear-gradient(90deg, #0f2a44, #143b63);
    color: #fff;
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
    margin-bottom: 25px;
    font-family: "Segoe UI", sans-serif;
}

.brand {
    font-size: 22px;
    font-weight: 600;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.brand span { color: #5fd1ff; }
.brand small { font-size: 12px; color: #cde9ff; font-weight: 400; }

.vidora-nav { display: flex; gap: 18px; }
.vidora-nav a {
    text-decoration: none;
    color: #fff;
    font-size: 15px;
    padding: 7px 14px;
    border-radius: 6px;
    transition: all 0.25s ease;
}
.vidora-nav a:hover { background: rgba(255,255,255,0.15); }
.vidora-nav a.active {
    background: #5fd1ff;
    color: #0f2a44;
    font-weight: 600;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
    margin-bottom: 20px;
}

h1 { font-size: 28px; color: #0f2a44; margin-bottom: 10px; }
h2 { font-size: 18px; color: #64748b; margin-bottom: 25px; font-weight: 400; }

/* Language badge */
.lang-badge {
    display: inline-block;
    background: #8b5cf6;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    margin-left: 5px;
    font-weight: normal;
}

/* Voice selector row */
.voice-row {
    display: flex;
    gap: 20px;
    margin-bottom: 25px;
    flex-wrap: wrap;
    background: #f8fafc;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

.voice-item {
    flex: 1;
    min-width: 250px;
}

.voice-item label {
    display: block;
    margin-bottom: 8px;
    color: #1e293b;
    font-weight: 600;
    font-size: 13px;
}

.voice-item select {
    width: 100%;
    padding: 10px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 12px;
    background: white;
}

.voice-item select:focus {
    border-color: #8b5cf6;
    outline: none;
}

/* Hidden file input */
#audioUploadInput {
    display: none;
}

/* Audio player container */
.audio-player-container {
    width: 220px;
    background: #f8fafc;
    border-radius: 20px;
    padding: 5px 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #e2e8f0;
}

.audio-play-btn {
    background: #8b5cf6;
    color: white;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}

.audio-play-btn.playing {
    background: #dc2626;
}

.audio-time {
    font-size: 10px;
    color: #64748b;
    min-width: 65px;
    text-align: center;
}

.audio-progress {
    flex-grow: 1;
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    cursor: pointer;
    position: relative;
}

.audio-progress-fill {
    height: 100%;
    background: #8b5cf6;
    border-radius: 2px;
    width: 0%;
}

/* Progress bar for batch generation */
.batch-progress {
    margin: 15px 0;
    padding: 10px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.batch-progress-bar {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.batch-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #8b5cf6, #2563eb);
    width: 0%;
    transition: width 0.3s ease;
}

.batch-progress-text {
    font-size: 12px;
    color: #64748b;
    text-align: center;
}
</style>
</head>
<body>

<div class="container">
    <header class="vidora-header">
        <div class="brand">
            🎬 <span>Vidora</span>
            <small>Social Media Automation</small>
        </div>
         <nav class="vidora-nav">
                <a href="vidora_home.php" class="active">Home</a>
                <a href="image_gen.php?podcast_id=<?=$url_podcast_id;?>">Visuals</a>
                <a href="trans_gen.php?podcast_id=<?=$url_podcast_id;?>">Translate</a>
                <a href="videomaker.php?podcast_id=<?=$url_podcast_id;?>">Render</a>
                <a href="publisher/dashboard.php?podcast_id=<?=$url_podcast_id;?>">Schedule</a>
            </nav>
    </header>

    <div class="card">
        <h1>🖼️Add Voice & Sound</h1>
        <p class="sub">Turn your script into natural-sounding voiceover and enhance it with background music in just a few clicks.</p>
        

        
        <!-- Voice Selection Row -->
        <div class="voice-row">
            <div class="voice-item">
                <label>🎤 Host Voice:</label>
                <select id="hostVoicePicker" onchange="saveVoiceSettings()">
                    <option value="" disabled selected>-- Select Host Voice --</option>
                    <?php foreach ($voices as $group => $list): ?>
                        <optgroup label="<?= htmlspecialchars($group) ?>">
                            <?php foreach ($list as $code => $name): ?>
                                <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="voice-item">
                <label>👤 Guest Voice:</label>
                <select id="guestVoicePicker" onchange="saveVoiceSettings()">
                    <option value="" disabled selected>-- Select Guest Voice --</option>
                    <?php foreach ($voices as $group => $list): ?>
                        <optgroup label="<?= htmlspecialchars($group) ?>">
                            <?php foreach ($list as $code => $name): ?>
                                <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><strong>Speed/Rate:</strong></label><br>
                <select id="ratePicker" onchange="saveVoiceSettings()">
                    <option value="0.75">Very Slow</option>
                    <option value="0.85" selected>Calm (0.85)</option>
                    <option value="1.0">Normal</option>
                    <option value="1.15" >Podcast (1.15)</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Hidden file input for audio upload -->
    <input type="file" id="audioUploadInput" accept=".mp3,audio/mpeg">

    <!-- Hidden rate picker and lang filter -->
    <input type="hidden" name="lang_filter" value="en-US">

    <div class="card" id="scenesCard" style="<?= $url_podcast_id > 0 ? 'display:block' : 'display:none' ?>">
        <div class="top-bar">
            <div>
                <h3 style="margin:0" id="scenesTitle"><?= $podcast_title ? htmlspecialchars($podcast_title) : 'Scenes' ?></h3>
                <div class="scene-count" id="sceneCount"></div>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <button class="btn-generate-all" id="generateAllBtn" onclick="startGeneration()">🎤 Generate All Audio</button>
                <button class="btn" style="background:#64748b" onclick="document.getElementById('logBox').value=''">🗑️ Clear Log</button>
            </div>
        </div>

        <!-- Batch Progress Bar -->
        <div id="batchProgress" class="batch-progress" style="display:none;">
            <div class="batch-progress-bar">
                <div id="batchProgressFill" class="batch-progress-fill" style="width:0%;"></div>
            </div>
            <div id="batchProgressText" class="batch-progress-text">0/0 scenes completed</div>
        </div>

        <div style="margin-bottom:15px">
            <label style="font-weight:700;font-size:12px;color:#334155;display:block;margin-bottom:5px">📋 Log:</label>
            <textarea id="logBox" readonly style="width:100%;height:200px;background:#0f172a;color:#a5f3fc;font-family:'Courier New',monospace;font-size:11.5px;padding:12px;border-radius:8px;border:2px solid #334155;resize:vertical;line-height:1.7;white-space:pre-wrap;outline:none;" placeholder="Select a podcast to load scenes..."></textarea>
        </div>

        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Audio</th>
                        <th>Text (Editable)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="scenesBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div class="modal-overlay" id="imgModal" onclick="closePreview(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closePreview()">&times;</button>
            <img id="modalImg" src="" alt="Preview">
            <div class="modal-info" id="modalInfo"></div>
        </div>
    </div>
</div>

<script>
let scenes = [];
let currentAudioSceneId = null;
let currentPlayingId = null;
let audioPlayers = {};
let currentPodcastId = <?= $url_podcast_id ?>;

// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function L(m) {
    const b = document.getElementById('logBox');
    const ts = new Date().toLocaleTimeString();
    b.value += '[' + ts + '] ' + m + '\n';
    b.scrollTop = b.scrollHeight;
}

async function safeFetch(fd) {
    const r = await fetch(location.href, {method:'POST', body:fd});
    const raw = await r.text();
    try {
        return { data: JSON.parse(raw), raw: raw };
    } catch(e) {
        throw new Error('Server returned non-JSON. Raw response:\n' + raw.substring(0, 800));
    }
}

// Auto-load scenes on page load
document.addEventListener('DOMContentLoaded', function() {
    if (currentPodcastId > 0) {
        loadScenes(currentPodcastId);
    }
    loadVoiceSettings();
});

async function loadScenes(podcastId) {
    const card = document.getElementById('scenesCard');
    card.style.display = 'block';
    document.getElementById('scenesBody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">⏳ Loading scenes...</td></tr>';
    
    try {
        L('📥 Loading scenes for podcast ID: ' + podcastId + '...');
        const fd = new FormData();
        fd.append('ajax_action', 'get_scenes');
        fd.append('podcast_id', podcastId);
        const {data, raw} = await safeFetch(fd);
        scenes = data;
        L('✅ Loaded ' + scenes.length + ' scenes');
        renderTable();
    } catch(e) {
        L('❌ LOAD FAILED: ' + e.message);
        document.getElementById('scenesBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#dc2626;padding:20px">❌ Error loading scenes.</td></tr>';
    }
}

// Save voice settings
async function saveVoiceSettings() {
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const ratePicker = document.getElementById('ratePicker').value;
    
    const fd = new FormData();
    fd.append('ajax_action', 'save_voice_settings');
    fd.append('host_voice', hostVoice);
    fd.append('guest_voice', guestVoice);
    fd.append('rate_picker', ratePicker);
    
    try {
        await safeFetch(fd);
        L('✅ Voice settings saved (Rate: ' + ratePicker + ')');
    } catch(e) {
        L('❌ Failed to save voice settings: ' + e.message);
    }
}

// Load voice settings
async function loadVoiceSettings() {
    const fd = new FormData();
    fd.append('ajax_action', 'load_voice_settings');
    
    try {
        const {data} = await safeFetch(fd);
        if (data.success) {
            if (data.host_voice) {
                document.getElementById('hostVoicePicker').value = data.host_voice;
            }
            if (data.guest_voice) {
                document.getElementById('guestVoicePicker').value = data.guest_voice;
            }
            if (data.rate_picker) {
                document.getElementById('ratePicker').value = data.rate_picker;
            }
        }
    } catch(e) {
        console.error('Failed to load voice settings:', e);
    }
}

// Save text content
async function saveText(sceneId) {
    const textarea = document.getElementById('text_' + sceneId);
    if (!textarea) return;
    
    const newText = textarea.value.trim();
    
    L('💾 Saving text for scene #' + sceneId + '...');
    
    const fd = new FormData();
    fd.append('ajax_action', 'update_text');
    fd.append('scene_id', sceneId);
    fd.append('text_contents', newText);
    
    try {
        const {data} = await safeFetch(fd);
        if (data.success) {
            L('✅ Text saved for scene #' + sceneId);
            
            // Update the scene in memory
            const scene = scenes.find(s => s.id == sceneId);
            if (scene) {
                scene.text_contents = newText;
            }
            
            // Show saved indicator
            const savedEl = document.getElementById('saved_' + sceneId);
            if (savedEl) {
                savedEl.style.display = 'inline';
                savedEl.innerText = '✓ Saved';
                setTimeout(() => {
                    savedEl.style.display = 'none';
                }, 2000);
            }
        } else {
            L('❌ Failed to save text: ' + data.message);
        }
    } catch(e) {
        L('❌ Error saving text: ' + e.message);
    }
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function createAudioPlayer(sceneId, filename, autoPlay = false) {
    const playerId = 'audio_' + sceneId;
    const audioUrl = 'podcast_audios/' + filename + '?t=' + Date.now();
    
    // Create container
    const container = document.createElement('div');
    container.className = 'audio-player-container';
    container.id = 'player_container_' + sceneId;
    
    // Play button
    const playBtn = document.createElement('button');
    playBtn.className = 'audio-play-btn';
    playBtn.id = 'play_btn_' + sceneId;
    playBtn.innerHTML = '▶';
    playBtn.onclick = () => togglePlay(sceneId);
    
    // Time display
    const timeDisplay = document.createElement('span');
    timeDisplay.className = 'audio-time';
    timeDisplay.id = 'time_' + sceneId;
    timeDisplay.innerText = '0:00 / 0:00';
    
    // Progress bar
    const progressContainer = document.createElement('div');
    progressContainer.className = 'audio-progress';
    progressContainer.id = 'progress_container_' + sceneId;
    progressContainer.onclick = (e) => seekAudio(sceneId, e);
    
    const progressFill = document.createElement('div');
    progressFill.className = 'audio-progress-fill';
    progressFill.id = 'progress_fill_' + sceneId;
    progressContainer.appendChild(progressFill);
    
    // Audio element
    const audio = document.createElement('audio');
    audio.id = playerId;
    audio.src = audioUrl;
    audio.preload = 'metadata';
    
    // Audio events
    audio.onloadedmetadata = () => {
        const duration = audio.duration;
        timeDisplay.innerText = '0:00 / ' + formatTime(duration);
        if (autoPlay) {
            audio.play().catch(e => console.log('Auto-play prevented'));
        }
    };
    
    audio.ontimeupdate = () => {
        const duration = audio.duration;
        const current = audio.currentTime;
        const percent = (current / duration) * 100 || 0;
        progressFill.style.width = percent + '%';
        timeDisplay.innerText = formatTime(current) + ' / ' + formatTime(duration);
    };
    
    audio.onended = () => {
        playBtn.innerHTML = '▶';
        playBtn.classList.remove('playing');
        if (currentPlayingId === sceneId) currentPlayingId = null;
        progressFill.style.width = '0%';
        timeDisplay.innerText = '0:00 / ' + formatTime(audio.duration);
    };
    
    audio.onplay = () => {
        playBtn.innerHTML = '⏸';
        playBtn.classList.add('playing');
    };
    
    audio.onpause = () => {
        playBtn.innerHTML = '▶';
        playBtn.classList.remove('playing');
    };
    
    // Assemble
    container.appendChild(playBtn);
    container.appendChild(timeDisplay);
    container.appendChild(progressContainer);
    container.appendChild(audio);
    
    return { container, audio };
}

function togglePlay(sceneId) {
    const audio = document.getElementById('audio_' + sceneId);
    const playBtn = document.getElementById('play_btn_' + sceneId);
    
    if (!audio) return;
    
    if (currentPlayingId && currentPlayingId !== sceneId) {
        // Stop the currently playing audio
        const currentAudio = document.getElementById('audio_' + currentPlayingId);
        if (currentAudio) {
            currentAudio.pause();
            const currentBtn = document.getElementById('play_btn_' + currentPlayingId);
            if (currentBtn) {
                currentBtn.innerHTML = '▶';
                currentBtn.classList.remove('playing');
            }
        }
    }
    
    if (audio.paused) {
        audio.play();
        currentPlayingId = sceneId;
    } else {
        audio.pause();
        currentPlayingId = null;
    }
}

function seekAudio(sceneId, event) {
    const audio = document.getElementById('audio_' + sceneId);
    const container = document.getElementById('progress_container_' + sceneId);
    if (!audio || !container) return;
    
    const rect = container.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const width = rect.width;
    const percent = clickX / width;
    audio.currentTime = percent * audio.duration;
}

// Update audio player for a scene
function updateAudioPlayer(sceneId, filename, autoPlay = false) {
    const cell = document.getElementById('audio_cell_' + sceneId);
    if (!cell) return;
    
    // Clear existing content
    cell.innerHTML = '';
    
    // Create new player
    const { container, audio } = createAudioPlayer(sceneId, filename, autoPlay);
    cell.appendChild(container);
    audioPlayers['audio_' + sceneId] = audio;
}

// ---- Audio Generation Functions ----
async function generateSingle(id) {
    L('🎨 Generating audio for scene #' + id + '...');
    
    const row = document.getElementById('row-' + id);
    
    // Get actor from data attribute, default to 'host'
    let actor = 'host';
    if (row && row.getAttribute('data-actor')) {
        actor = row.getAttribute('data-actor');
    }
    
    // Update row to show it's processing
    if (row) {
        row.classList.remove('row-completed');
        row.classList.add('row-active');
    }
    
    // Get voice picker based on actor
    let voicePicker;
    if (actor === 'guest') {
        voicePicker = document.getElementById('guestVoicePicker');
        L('👤 Using GUEST voice');
    } else {
        voicePicker = document.getElementById('hostVoicePicker');
        L('🎤 Using HOST voice');
    }

    const ratePicker = document.getElementById('ratePicker');
    const textElement = document.getElementById('text_' + id);
    const langFilter = document.querySelector('[name=lang_filter]');

    // Check if voice is selected
    if (!voicePicker || !voicePicker.value) {
        L('❌ No voice selected! Please select a ' + actor + ' voice first.');
        if (row) row.classList.remove('row-active');
        return false;
    }

    if (!ratePicker) {
        L('❌ Rate picker missing');
        if (row) row.classList.remove('row-active');
        return false;
    }
    
    if (!textElement || !textElement.value.trim()) {
        L('❌ No text content for scene #' + id);
        if (row) row.classList.remove('row-active');
        return false;
    }

    const formData = new FormData();
    formData.append('row_id', id);
    formData.append('text', textElement.value);
    formData.append('lang_code', langFilter ? langFilter.value : 'en-US');
    formData.append('voice_id', voicePicker.value);
    formData.append('rate', ratePicker.value);
    
    L('🎚️ Using rate: ' + ratePicker.value + ' for scene #' + id);
    L('🎤 Using voice: ' + voicePicker.value + ' for scene #' + id);
    
    try {
        L('📡 Sending request to generate_voice.php...');
        const response = await fetch('generate_voice.php', { 
            method: 'POST', 
            body: formData 
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Extract filename from response
            let filename = data.filename;
            if (!filename && data.file) {
                const parts = data.file.split('/');
                filename = parts[parts.length - 1].split('?')[0];
            }
            
            if (!filename) {
                L('❌ No filename in response');
                if (row) row.classList.remove('row-active');
                return false;
            }
            
            // Update the scene in memory
            const scene = scenes.find(s => s.id == id);
            if (scene) {
                scene.audio_file = filename;
            }
            
            // Update the audio player immediately with autoPlay=true
            L('🎵 Updating audio player with: ' + filename);
            updateAudioPlayer(id, filename, true);
            
            // Update row to show completed
            if (row) {
                row.classList.remove('row-active');
                row.classList.add('row-completed');
            }
            
            L('✅ Audio generated for scene #' + id);
            return true;
        } else {
            L('❌ API Error for scene #' + id + ': ' + (data.message || 'Unknown error'));
            if (row) row.classList.remove('row-active');
            return false;
        }
    } catch (err) {
        L('❌ Network Error for scene #' + id + ': ' + err.message);
        console.error('Full error:', err);
        if (row) row.classList.remove('row-active');
        return false;
    }
}

async function startGeneration() {
    if (!scenes.length) {
        alert('No scenes loaded');
        return;
    }

    if (!confirm("Generate audio for all scenes?")) return;

    const btn = document.getElementById('generateAllBtn');
    const progressDiv = document.getElementById('batchProgress');
    const progressFill = document.getElementById('batchProgressFill');
    const progressText = document.getElementById('batchProgressText');
    
    btn.disabled = true;
    btn.innerText = "⏳ Processing Audio...";
    progressDiv.style.display = 'block';
    
    let completed = 0;
    const total = scenes.length;
    
    progressFill.style.width = '0%';
    progressText.innerText = `0/${total} scenes completed`;

    for (let i = 0; i < scenes.length; i++) {
        const scene = scenes[i];
        
        const success = await generateSingle(scene.id);
        
        if (success) {
            completed++;
            const percent = Math.round((completed / total) * 100);
            progressFill.style.width = percent + '%';
            progressText.innerText = `${completed}/${total} scenes completed`;
        }
        
        // Small delay between generations
        await new Promise(r => setTimeout(r, 500));
    }

    btn.disabled = false;
    btn.innerText = "🎤 Generate All Audio";
    progressDiv.style.display = 'none';
    alert(`Audio generation complete! ${completed}/${total} scenes completed.`);
}

function renderTable() {
    const body = document.getElementById('scenesBody');
    document.getElementById('sceneCount').innerText = scenes.length + ' scenes found';
    
    if (!scenes.length) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">No scenes found</td></tr>';
        return;
    }
    
    let html = '';
    scenes.forEach((s, i) => {
        const hasImage = s.image_file && s.image_file.trim() !== '';
        const hasAudio = s.audio_file && s.audio_file.trim() !== '';
        
        const imgHtml = hasImage 
            ? '<img src="podcast_images/' + s.image_file + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + s.image_file + '\', ' + s.id + ')" onerror="this.src=\'\';this.alt=\'Missing\'" id="img-' + s.id + '">' 
            : '<span style="color:#94a3b8;font-size:11px" id="img-' + s.id + '">No image</span>';
        
        // ALWAYS create the audio cell div, even if no audio yet
        // This ensures the container exists for updateAudioPlayer to use
        const audioHtml = `<div id="audio_cell_${s.id}" style="min-width:220px;">${hasAudio ? '' : '<span style="color:#94a3b8;font-size:11px">No audio</span>'}</div>`;
        
        // Text editor with pause commands
        const textValue = s.text_contents ? escapeHtml(s.text_contents) : '';
        const textHtml = `
            <div class="text-container">
                <div class="text-header">
                    <label>Scene Text:</label>
                    <span class="status-badge st-audio" id="saved_${s.id}" style="display:none;">✓ Saved</span>
                </div>
                <textarea class="text-editor" id="text_${s.id}" rows="3" placeholder="Enter text with pause commands...">${textValue}</textarea>
                <div style="display:flex; justify-content:flex-end; margin-top:5px;">
                    <button class="btn-save" onclick="saveText(${s.id})">💾 Save Text</button>
                </div>
            </div>
        `;
        
        const statusHtml = hasImage 
            ? '<span class="status-badge st-done">✅ Has Image</span>'
            : '<span class="status-badge st-pending">⏳ No Image</span>';
        
        // Determine row class based on audio status
        const rowClass = hasAudio ? 'row-completed' : '';
        
        // Make sure data-actor is set - default to 'host' if not set
        const actor = s.actor || 'host';
        
        html += '<tr id="row-' + s.id + '" data-actor="' + actor + '" class="' + rowClass + '">' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + s.id + '</td>' +
            '<td>' + imgHtml + '</td>' +
            '<td>' + audioHtml + '</td>' +
            '<td>' + textHtml + '</td>' +
            '<td>' + statusHtml + '</td>' +
            '<td style="white-space:nowrap">' +
                '<button class="btn btn-gen" onclick="generateSingle(' + s.id + ')">🎨 Gen</button> ' +
                '<button class="btn btn-upload" onclick="uploadAudio(' + s.id + ')">📤 Upload</button>' +
            '</td>' +
        '</tr>';
    });
    
    body.innerHTML = html;
    
    // Create audio players for rows that already have audio
    scenes.forEach(s => {
        if (s.audio_file && s.audio_file.trim() !== '') {
            const cell = document.getElementById('audio_cell_' + s.id);
            if (cell) {
                // Clear the "No audio" text
                cell.innerHTML = '';
                const { container, audio } = createAudioPlayer(s.id, s.audio_file);
                cell.appendChild(container);
                audioPlayers['audio_' + s.id] = audio;
            }
        }
    });
}

// ---- Image Preview Modal ----
function openPreview(src, sceneId) {
    document.getElementById('modalImg').src = src + '?t=' + Date.now();
    document.getElementById('modalInfo').innerText = 'Scene ID: ' + sceneId + ' | ' + src;
    document.getElementById('imgModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closePreview(e) {
    if (e && e.target !== document.getElementById('imgModal')) return;
    document.getElementById('imgModal').classList.remove('open');
    document.getElementById('modalImg').src = '';
    document.body.style.overflow = '';
}

// ---- Audio Upload Function ----
function uploadAudio(sceneId) {
    currentAudioSceneId = sceneId;
    const fileInput = document.getElementById('audioUploadInput');
    fileInput.click();
}

// Handle file selection
document.getElementById('audioUploadInput').addEventListener('change', async function(e) {
    if (!this.files || this.files.length === 0) return;
    
    const file = this.files[0];
    const sceneId = currentAudioSceneId;
    
    if (!sceneId) {
        alert('No scene selected');
        return;
    }
    
    L('📤 Uploading audio for scene #' + sceneId + ': ' + file.name);
    
    const formData = new FormData();
    formData.append('ajax_action', 'upload_audio');
    formData.append('scene_id', sceneId);
    formData.append('audio_file', file);
    
    try {
        const response = await fetch(location.href, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            L('✅ Audio uploaded successfully: ' + data.filename);
            
            // Update the scene in memory
            const scene = scenes.find(s => s.id == sceneId);
            if (scene) {
                scene.audio_file = data.filename;
            }
            
            // Update the audio player immediately and auto-play
            updateAudioPlayer(sceneId, data.filename, true);
            
            // Update row to show completed
            const row = document.getElementById('row-' + sceneId);
            if (row) {
                row.classList.add('row-completed');
            }
            
            alert('Audio uploaded successfully!');
        } else {
            L('❌ Upload failed: ' + data.message);
            alert('Upload failed: ' + data.message);
        }
    } catch (err) {
        L('❌ Upload error: ' + err.message);
        alert('Upload error: ' + err.message);
    }
    
    // Clear the file input
    this.value = '';
    currentAudioSceneId = null;
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePreview();
    }
});

// Debug on page load
window.addEventListener('load', function() {
    console.log('Page loaded');
    console.log('Rate picker element:', document.getElementById('ratePicker'));
    console.log('Rate picker value:', document.getElementById('ratePicker')?.value);
    console.log('Host voice picker:', document.getElementById('hostVoicePicker')?.value);
    console.log('Guest voice picker:', document.getElementById('guestVoicePicker')?.value);
});
</script>
</body>
</html>
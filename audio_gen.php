<?php
require_once 'check_session.php';
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

// Get user info for header
$firstname = '';
$admin_initial = 'U';
if (isset($_SESSION['admin_id'])) {
    $user_query = mysqli_query($conn, "SELECT firstname, email FROM hdb_users WHERE id = '".$_SESSION['admin_id']."'");
    if ($user_query && mysqli_num_rows($user_query) > 0) {
        $user_data = mysqli_fetch_assoc($user_query);
        $firstname = $user_data['firstname'] ?? 'User';
        $admin_initial = strtoupper(substr($firstname, 0, 1));
    }
}

// Get podcast_id from URL
$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

// Get user plan for free trial check
$user_query = mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
$user_row = mysqli_fetch_assoc($user_query);
$plan_type = $user_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// Get podcast video_type to determine voice options
$podcast_video_type = 'host'; // Default
$podcast_lang_code = 'en';
if ($url_podcast_id > 0) {
    $type_query = mysqli_query($conn, "SELECT video_type, lang_code FROM hdb_podcasts WHERE id = $url_podcast_id");
    if ($type_query && mysqli_num_rows($type_query) > 0) {
        $type_row = mysqli_fetch_assoc($type_query);
        $podcast_video_type = $type_row['video_type'] ?? 'host';
        $podcast_lang_code  = $type_row['lang_code'] ?? 'en';
    }
}

// Get internal_status for the podcast
$internal_status = '';
if ($url_podcast_id > 0) {
    $status_query = mysqli_query($conn, "SELECT internal_status FROM hdb_podcasts WHERE id = $url_podcast_id");
    if ($status_query && mysqli_num_rows($status_query) > 0) {
        $status_row = mysqli_fetch_assoc($status_query);
        $internal_status = $status_row['internal_status'] ?? '';
    }
}

// Get all scenes for this podcast
$scenes = [];
$podcast_title = '';
if ($url_podcast_id > 0) {
    $title_query = mysqli_query($conn, "SELECT title FROM hdb_podcasts WHERE id = $url_podcast_id AND client_id = '$client_id'");
    if ($title_query && mysqli_num_rows($title_query) > 0) {
        $title_row = mysqli_fetch_assoc($title_query);
        $podcast_title = $title_row['title'];
    }
    
    $scenes_query = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id ORDER BY id");
    if ($scenes_query) {
        while ($row = mysqli_fetch_assoc($scenes_query)) {
            $scenes[] = $row;
        }
    }
}

// Get voices from hdb_voices table based on lang_code
$voices = [];
$lang_code = $_GET['lang_filter'] ?? 'en';

// Query hdb_voices table for the selected language
$voices_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$lang_code' ORDER BY voice_name");
if ($voices_query && mysqli_num_rows($voices_query) > 0) {
    $language_group = "Voices for " . strtoupper($lang_code);
    $voices[$language_group] = [];
    while ($voice = mysqli_fetch_assoc($voices_query)) {
        $display_name = $voice['voice_name'];
        if (!empty($voice['voice_description'])) {
            $display_name .= " - " . $voice['voice_description'];
        }
        $voices[$language_group][$voice['voice_key']] = $display_name;
    }
} else {
    // Fallback voices if no database entries
    $voices = [
        '🇺🇸 English (US)' => [
            'en-US-GuyNeural' => 'Guy - Calm, Steady Male ⭐',
            'en-US-DavisNeural' => 'Davis - Deep, Soothing Male ⭐',
            'en-US-SaraNeural' => 'Sara - Empathetic, Warm Female ⭐',
            'en-US-JennyNeural' => 'Jenny - Soft, Gentle Female',
        ],
        '🇮🇳 Hindi' => [
            'hi-IN-SwaraNeural' => 'Swara - Female, Warm & Calming ⭐',
            'hi-IN-MadhurNeural' => 'Madhur - Male, Soothing & Confident ⭐',
        ],
    ];
}

// Get sample voice endpoint
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_voice_sample') 
{
    ob_clean();
    header('Content-Type: application/json');
    
    $voice_code = mysqli_real_escape_string($conn, $_POST['voice_code'] ?? '');
    
    error_log("get_voice_sample called for voice_code: " . $voice_code);
    
    // Query hdb_voices for sample URL
    $sample_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE voice_key = '$voice_code' LIMIT 1");
    
    if ($sample_query && mysqli_num_rows($sample_query) > 0) {
        $sample_row = mysqli_fetch_assoc($sample_query);
        
        error_log("Found voice: " . print_r($sample_row, true));
        
        echo json_encode([
            'success' => true,
            'sample_url' => $sample_row['sample_voice'],
            'debug_voice_name' => $sample_row['voice_name']
        ]);
    } else {
        error_log("No voice found with voice_code: " . $voice_code);
        
        echo json_encode([
            'success' => false,
            'message' => 'No sample found for this voice'
        ]);
    }
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
    
    // Use podcast_audios folder
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>VideoVizard - Voice Your Story</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --dark-blue: #0f2a44;
            --mid-blue: #143b63;
            --accent: #5fd1ff;
            --green: #10b981;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-hover: 0 8px 24px rgba(0,0,0,0.12);
            --error: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --info: #3b82f6;
            --purple: #8b5cf6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.5;
        }

        /* Header */
        .vidora-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: linear-gradient(90deg, #0f2a44, #143b63);
            color: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .brand-container a { 
            text-decoration: none; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }

        .main-icon { 
            font-size: 24px; 
        }

        .brand-text { 
            display: flex; 
            flex-direction: column; 
        }

        .logo { 
            font-size: 18px; 
            font-weight: 700; 
            line-height: 1.2;
        }

        .brand-video { 
            color: white; 
        }

        .brand-vizard { 
            color: var(--accent); 
        }

        .tagline { 
            font-size: 9px; 
            color: rgba(255,255,255,0.6); 
            letter-spacing: 0.3px; 
            display: none;
        }

        /* Navigation Buttons */
        .top-nav {
            display: flex;
            gap: 10px;
            padding: 16px 16px 0;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .nav-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .nav-btn.home {
            background: var(--dark-blue);
            color: white;
        }

        .nav-btn.visuals {
            background: var(--info);
            color: white;
        }

        .nav-btn.translate {
            background: var(--success);
            color: white;
        }

        .nav-btn.video {
            background: var(--purple);
            color: white;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        /* Main Container */
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 16px;
            width: 100%;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }

        .card-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark-blue);
            margin-bottom: 8px;
        }

        .card-header p {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        .card-body {
            padding: 20px;
        }

        /* Read More Link */
        .read-more-link {
            color: var(--info);
            font-weight: 600;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
        }

        .read-more-content {
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--text);
            line-height: 1.7;
            border-left: 4px solid var(--accent);
        }

        /* Voice Row */
        .voice-row {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (min-width: 768px) {
            .voice-row {
                flex-direction: row;
                gap: 20px;
            }
        }

        .voice-item {
            flex: 1;
            min-width: 0;
        }

        .voice-item label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-blue);
            margin-bottom: 6px;
        }

        .voice-select {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: #fff;
            margin-bottom: 8px;
        }

        .voice-select:focus {
            outline: none;
            border-color: var(--purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .btn-sample {
            background: var(--purple);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-sample:hover {
            background: #7c3aed;
            transform: translateY(-1px);
        }

        /* Project Info */
        .project-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border);
        }

        .project-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark-blue);
        }

        .scene-counter {
            font-size: 13px;
            color: var(--muted);
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 30px;
        }

        /* Main Canvas Card */
        .canvas-card {
            background: var(--card-bg);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-hover);
            overflow: hidden;
            margin-bottom: 20px;
        }

        /* Action Buttons */
        .action-bar {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }

        .action-btn {
            flex: 1;
            padding: 14px 8px;
            border: none;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .action-btn.generate {
            background: var(--purple);
            color: white;
            box-shadow: 0 4px 10px rgba(139,92,246,0.3);
        }

        .action-btn.generate:hover {
            background: #7c3aed;
            transform: translateY(-2px);
        }

        .action-btn.clear {
            background: #f1f5f9;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .action-btn.clear:hover {
            background: #e2e8f0;
        }

        /* Canvas Container */
        .canvas-container {
            position: relative;
            width: 100%;
            aspect-ratio: 9/16;
            background: #000;
            overflow: hidden;
        }

        #sceneImage {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .no-image-overlay {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #94a3b8;
            font-size: 14px;
            flex-direction: column;
            gap: 12px;
        }

        .no-image-overlay span {
            font-size: 48px;
            opacity: 0.5;
        }

        /* Scene Info Overlay */
        .scene-info-overlay {
            position: absolute;
            top: 16px;
            left: 16px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 5;
        }

        .audio-status {
            position: absolute;
            top: 16px;
            right: 16px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 5;
        }

        .audio-status.ready {
            background: rgba(16, 185, 129, 0.9);
        }

        /* Text Overlay */
        .text-overlay {
            position: absolute;
            bottom: 80px;
            left: 5%;
            width: 90%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            color: white;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 5;
            max-height: 100px;
            overflow-y: auto;
        }

        /* Navigation Arrows - BOLD */
        .nav-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 16px;
            background: #fff;
            border-top: 1px solid var(--border);
        }

        .nav-arrow {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--purple), #7c3aed);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 28px;
            font-weight: 800;
            box-shadow: 0 8px 16px rgba(139,92,246,0.3);
        }

        .nav-arrow:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 24px rgba(139,92,246,0.4);
        }

        .nav-arrow:active {
            transform: scale(0.95);
        }

        .nav-indicator {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-blue);
            background: #f1f5f9;
            padding: 10px 24px;
            border-radius: 40px;
            border: 1px solid var(--border);
        }

        /* Scene Controls */
        .scene-controls {
            padding: 20px;
            background: #fff;
            border-top: 1px solid var(--border);
        }

        .text-section {
            margin-bottom: 20px;
        }

        .text-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .text-label span {
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-blue);
        }

        .saved-badge {
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .scene-textarea {
            width: 100%;
            min-height: 80px;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 16px;
            font-family: 'Inter', monospace;
            font-size: 13px;
            line-height: 1.6;
            resize: vertical;
            background: #fff;
            transition: all 0.2s;
            margin-bottom: 8px;
        }

        .scene-textarea:focus {
            outline: none;
            border-color: var(--purple);
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .text-actions {
            display: flex;
            justify-content: flex-end;
        }

        .btn-save {
            background: var(--muted);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-save:hover {
            background: #475569;
        }

        /* Audio Player */
        .audio-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid var(--border);
        }

        .audio-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .audio-player-container {
            background: white;
            border-radius: 60px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .audio-play-btn {
            width: 44px;
            height: 44px;
            background: var(--purple);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            transition: all 0.2s;
            box-shadow: 0 4px 10px rgba(139,92,246,0.3);
        }

        .audio-play-btn:hover {
            background: #7c3aed;
            transform: scale(1.05);
        }

        .audio-play-btn.playing {
            background: var(--error);
        }

        .audio-time {
            font-size: 12px;
            color: var(--muted);
            min-width: 80px;
            text-align: center;
            font-weight: 500;
        }

        .audio-progress {
            flex-grow: 1;
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }

        .audio-progress-fill {
            height: 100%;
            background: var(--purple);
            border-radius: 3px;
            width: 0%;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
        }

        .btn-gen {
            flex: 1;
            background: var(--info);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-gen:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59,130,246,0.3);
        }

        .btn-upload {
            flex: 1;
            background: var(--success);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-upload:hover {
            background: #047857;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5,150,105,0.3);
        }

        /* Batch Progress */
        .batch-progress {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 16px;
            margin: 20px 0;
        }

        .batch-progress-bar {
            background: var(--border);
            border-radius: 20px;
            height: 10px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .batch-progress-fill {
            background: linear-gradient(90deg, var(--purple), var(--info));
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: 20px;
        }

        .batch-progress-text {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        /* Log Box */
        .log-box {
            width: 100%;
            height: 120px;
            background: #0f172a;
            color: #a5f3fc;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            padding: 12px;
            border-radius: 12px;
            border: 2px solid #334155;
            resize: vertical;
            line-height: 1.6;
            white-space: pre-wrap;
            outline: none;
            margin-top: 20px;
        }

        /* Hidden file input */
        #audioUploadInput {
            display: none;
        }

        /* Footer */
        .site-footer {
            background: linear-gradient(90deg, #0f2a44, #143b63);
            color: rgba(255,255,255,0.55);
            padding: 16px;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            text-align: center;
            margin-top: 40px;
        }

        .footer-brand { 
            font-weight: 700; 
            color: var(--accent); 
            font-size: 13px; 
        }

        .footer-links { 
            display: flex; 
            gap: 20px; 
            justify-content: center; 
            flex-wrap: wrap; 
        }

        .footer-links a { 
            color: rgba(255,255,255,0.55); 
            text-decoration: none; 
            transition: color 0.2s; 
            padding: 8px 0;
        }

        .footer-links a:active { 
            color: var(--accent); 
        }

        /* Responsive */
        @media (min-width: 768px) {
            .container {
                padding: 24px;
            }
            
            .tagline {
                display: block;
            }

            .top-nav {
                padding: 20px 24px 0;
            }
        }
    </style>
</head>
<body>

<div class="vidora-header">
    <div class="brand-container">
        <a href="index.php">
            <span class="main-icon">🎬</span>
            <div class="brand-text">
                <div class="logo">
                    <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
                </div>
                <div class="tagline">Social Media Automation</div>
            </div>
        </a>
    </div>
</div>

<!-- Top Navigation Buttons -->
<div class="top-nav">
    <a href="vidora_home.php" class="nav-btn home">
        <span>🏠</span> Home
    </a>
    <a href="image_gen.php?podcast_id=<?=$podcast_id;?>" class="nav-btn visuals">
        <span>🖼️</span> Visuals
    </a>
    <?php if ($podcast_lang_code == "en") { ?>
        <a href="trans_gen.php?podcast_id=<?=$podcast_id;?>" class="nav-btn translate">
            <span>🌐</span> Translate
        </a>
    <?php } ?>
    <a href="videomaker.php?podcast_id=<?=$podcast_id;?>" class="nav-btn video">
        <span>🎬</span> Video
    </a>
</div>

<div class="container">
    <!-- Info Card -->
    <div class="card">
        <div class="card-header">
            <h1>🎙️ Voice Your Story</h1>
            <p>Generate professional AI audio for every scene. Select voices, adjust speed, and click Generate to create your soundtrack.</p>
            <a href="javascript:void(0);" class="read-more-link" onclick="toggleReadMore()">
                <span id="readMoreText">Read more</span> <span id="readMoreIcon">▼</span>
            </a>
            <div id="readMoreContent" class="read-more-content" style="display: none;">
                <p>For <strong>Podcast</strong> video types, select distinct voices for Host and Guest. Use <strong>Speed/Rate</strong> to match your content's energy.</p>
            </div>
        </div>
    </div>

    <!-- Voice Selection Card -->
    <div class="card">
        <div class="card-body">
            <div class="voice-row">
                <div class="voice-item">
                    <label>🎤 Host Voice</label>
                    <select id="hostVoicePicker" class="voice-select" onchange="saveVoiceSettings(); playVoiceSample(this.value, 'host')">
                        <option value="" disabled selected>-- Select Host Voice --</option>
                        <?php foreach ($voices as $group => $list): ?>
                            <optgroup label="<?= htmlspecialchars($group) ?>">
                                <?php foreach ($list as $code => $name): ?>
                                    <option value="<?= $code ?>" data-voice-code="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-sample" onclick="playVoiceSample(document.getElementById('hostVoicePicker').value, 'host')">
                        <span>▶️</span> Sample
                    </button>
                </div>

                <?php if ($podcast_video_type === 'podcast'): ?>
                <div class="voice-item">
                    <label>👤 Guest Voice</label>
                    <select id="guestVoicePicker" class="voice-select" onchange="saveVoiceSettings(); playVoiceSample(this.value, 'guest')">
                        <option value="" disabled selected>-- Select Guest Voice --</option>
                        <?php foreach ($voices as $group => $list): ?>
                            <optgroup label="<?= htmlspecialchars($group) ?>">
                                <?php foreach ($list as $code => $name): ?>
                                    <option value="<?= $code ?>" data-voice-code="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-sample" onclick="playVoiceSample(document.getElementById('guestVoicePicker').value, 'guest')">
                        <span>▶️</span> Sample
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="voice-item">
                    <label>⚡ Speed/Rate</label>
                    <select id="ratePicker" class="voice-select" onchange="saveVoiceSettings()">
                        <option value="0.75">Very Slow (0.75x)</option>
                        <option value="0.85" selected>Calm (0.85x)</option>
                        <option value="1.0">Normal (1.0x)</option>
                        <option value="1.15">Podcast (1.15x)</option>
                        <option value="1.25">Fast (1.25x)</option>
                        <option value="1.5">Very Fast (1.5x)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden file input for audio upload -->
    <input type="file" id="audioUploadInput" accept=".mp3,audio/mpeg">

    <!-- Project Info -->
    <div class="project-info">
        <div class="project-title">📋 <?= htmlspecialchars($podcast_title ?: 'Untitled Project') ?></div>
        <div class="scene-counter" id="sceneCounter"><?= count($scenes) ?> scenes</div>
    </div>

    <!-- Main Canvas Card -->
    <div class="canvas-card">
        <!-- Action Buttons -->
        <div class="action-bar">
            <button class="action-btn generate" id="generateAllBtn" onclick="startGeneration()">
                <span>🎤</span> Generate All
            </button>
            <button class="action-btn clear" onclick="document.getElementById('logBox').value=''">
                <span>🗑️</span> Clear Log
            </button>
        </div>

        <!-- Canvas with Image -->
        <div class="canvas-container" id="canvasContainer">
            <img id="sceneImage" src="" alt="Scene">
            <div id="noImageTemplate" class="no-image-overlay" style="display: none;">
                <span>🖼️</span>
                <div>No image for this scene</div>
            </div>
            
            <!-- Scene Info Overlay -->
            <div class="scene-info-overlay" id="sceneInfo">
                Scene <span id="currentSceneNum">1</span>/<span id="totalScenes"><?= count($scenes) ?></span>
            </div>
            
            <!-- Audio Status -->
            <div class="audio-status" id="audioStatus">
                ⏳ No Audio
            </div>
            
            <!-- Text Overlay -->
            <div class="text-overlay" id="textOverlay">
                Loading...
            </div>
        </div>

        <!-- Navigation Arrows - BOLD -->
        <div class="nav-section">
            <button class="nav-arrow" onclick="navigateScene('prev')">←</button>
            <div class="nav-indicator" id="navIndicator">1 / <?= count($scenes) ?></div>
            <button class="nav-arrow" onclick="navigateScene('next')">→</button>
        </div>

        <!-- Scene Controls -->
        <div class="scene-controls">
            <!-- Text Editor -->
            <div class="text-section">
                <div class="text-label">
                    <span>📝 Scene Text</span>
                    <span class="saved-badge" id="savedIndicator" style="display:none;">✓ Saved</span>
                </div>
                <textarea class="scene-textarea" id="sceneText" placeholder="Enter scene text..."></textarea>
                <div class="text-actions">
                    <button class="btn-save" onclick="saveCurrentText()">💾 Save Text</button>
                </div>
            </div>

            <!-- Audio Player & Controls -->
            <div class="audio-section">
                <div class="audio-label">🎵 Audio</div>
                <div id="audioPlayerContainer" style="min-height: 60px;">
                    <!-- Audio player will be inserted here -->
                </div>
                
                <div class="action-buttons">
                    <button class="btn-gen" id="generateBtn" onclick="generateCurrentAudio()">
                        <span>🎨</span> Generate
                    </button>
                    <button class="btn-upload" onclick="uploadAudio()">
                        <span>📤</span> Upload MP3
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Batch Progress Bar (hidden by default) -->
    <div id="batchProgress" class="batch-progress" style="display:none;">
        <div class="batch-progress-bar">
            <div id="batchProgressFill" class="batch-progress-fill" style="width:0%;"></div>
        </div>
        <div id="batchProgressText" class="batch-progress-text">0/0 scenes completed</div>
    </div>

    <!-- Log Box -->
    <textarea id="logBox" class="log-box" readonly placeholder="Progress log..."></textarea>
</div>

<!-- Hidden audio element for voice samples -->
<audio id="sampleAudioPlayer" style="display:none;"></audio>

<!-- Footer -->
<footer class="site-footer">
    <div class="footer-brand">🎬 VideoVizard</div>
    <div class="footer-links">
        <a href="vidora_home.php">Home</a>
        <a href="profile.php">Profile</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> VideoVizard</div>
</footer>

<script>
// ========== GLOBAL VARIABLES ==========
let scenes = <?= json_encode($scenes) ?>;
let currentSceneIndex = 0;
let currentSceneId = scenes.length > 0 ? scenes[0].id : null;
let currentAudioSceneId = null;
let currentPlayingId = null;
let audioPlayers = {};
let currentPodcastId = <?= $url_podcast_id ?>;
let podcastVideoType = '<?= $podcast_video_type ?>';
let internalStatus = '<?= $internal_status ?>';
let isFreeTrial = <?= $is_free_trial ? 'true' : 'false' ?>;

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    if (scenes.length > 0) {
        updateCanvas(currentSceneId);
    }
    loadVoiceSettings();
});

function L(m) {
    const b = document.getElementById('logBox');
    const ts = new Date().toLocaleTimeString();
    b.value += '[' + ts + '] ' + m + '\n';
    b.scrollTop = b.scrollHeight;
}

function toggleReadMore() {
    const content = document.getElementById('readMoreContent');
    const text = document.getElementById('readMoreText');
    const icon = document.getElementById('readMoreIcon');
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        text.innerText = 'Read less';
        icon.innerText = '▲';
    } else {
        content.style.display = 'none';
        text.innerText = 'Read more';
        icon.innerText = '▼';
    }
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

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

// ========== VOICE SETTINGS ==========
async function playVoiceSample(voiceCode, type) {
    if (!voiceCode) {
        alert('Please select a voice first');
        return;
    }
    
    L(`🔊 Loading sample for ${type} voice: ${voiceCode}`);
    
    const fd = new FormData();
    fd.append('ajax_action', 'get_voice_sample');
    fd.append('voice_code', voiceCode);
    
    try {
        const {data} = await safeFetch(fd);
        
        if (data.success && data.sample_url) {
            L(`✅ Sample URL received: ${data.sample_url}`);
            
            let fullUrl = data.sample_url;
            if (fullUrl.startsWith('/')) {
                fullUrl = window.location.origin + fullUrl;
            }
            
            const audio = document.getElementById('sampleAudioPlayer');
            audio.src = fullUrl;
            audio.play()
                .then(() => L(`✅ Playing sample for ${voiceCode}`))
                .catch(err => L(`❌ Playback error: ${err.message}`));
        } else {
            L(`❌ No sample available for this voice`);
            alert('No sample available for this voice');
        }
    } catch(e) {
        L(`❌ Error loading sample: ${e.message}`);
        console.error(e);
    }
}

async function saveVoiceSettings() {
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker')?.value || '';
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

async function loadVoiceSettings() {
    const fd = new FormData();
    fd.append('ajax_action', 'load_voice_settings');
    
    try {
        const {data} = await safeFetch(fd);
        if (data.success) {
            if (data.host_voice) {
                document.getElementById('hostVoicePicker').value = data.host_voice;
            }
            if (data.guest_voice && document.getElementById('guestVoicePicker')) {
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

// ========== CANVAS UPDATE ==========
function updateCanvas(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    const img = document.getElementById('sceneImage');
    const noImage = document.getElementById('noImageTemplate');
    const textOverlay = document.getElementById('textOverlay');
    const audioStatus = document.getElementById('audioStatus');
    
    // Update image
    if (scene.image_file) {
        img.src = 'podcast_images/' + scene.image_file + '?t=' + Date.now();
        img.style.display = 'block';
        noImage.style.display = 'none';
    } else {
        img.style.display = 'none';
        noImage.style.display = 'flex';
    }
    
    // Update text
    textOverlay.innerText = scene.text_contents || 'No text for this scene';
    
    // Update audio status
    if (scene.audio_file) {
        audioStatus.innerText = '✅ Audio Ready';
        audioStatus.classList.add('ready');
    } else {
        audioStatus.innerText = '⏳ No Audio';
        audioStatus.classList.remove('ready');
    }
    
    // Update textarea
    document.getElementById('sceneText').value = scene.text_contents || '';
    
    // Update navigation indicators
    document.getElementById('currentSceneNum').innerText = currentSceneIndex + 1;
    document.getElementById('navIndicator').innerText = (currentSceneIndex + 1) + ' / ' + scenes.length;
    
    // Update audio player if exists
    if (scene.audio_file) {
        createAudioPlayer(scene.id, scene.audio_file);
    } else {
        document.getElementById('audioPlayerContainer').innerHTML = '<div style="color:var(--muted); text-align:center; padding:20px;">No audio for this scene</div>';
    }
}

function createAudioPlayer(sceneId, filename) {
    const container = document.getElementById('audioPlayerContainer');
    container.innerHTML = '';
    
    const playerContainer = document.createElement('div');
    playerContainer.className = 'audio-player-container';
    playerContainer.id = 'player_container_' + sceneId;
    
    const playBtn = document.createElement('button');
    playBtn.className = 'audio-play-btn';
    playBtn.id = 'play_btn_' + sceneId;
    playBtn.innerHTML = '▶';
    playBtn.onclick = () => togglePlay(sceneId);
    
    const timeDisplay = document.createElement('span');
    timeDisplay.className = 'audio-time';
    timeDisplay.id = 'time_' + sceneId;
    timeDisplay.innerText = '0:00 / 0:00';
    
    const progressContainer = document.createElement('div');
    progressContainer.className = 'audio-progress';
    progressContainer.id = 'progress_container_' + sceneId;
    progressContainer.onclick = (e) => seekAudio(sceneId, e);
    
    const progressFill = document.createElement('div');
    progressFill.className = 'audio-progress-fill';
    progressFill.id = 'progress_fill_' + sceneId;
    progressContainer.appendChild(progressFill);
    
    const audio = document.createElement('audio');
    audio.id = 'audio_' + sceneId;
    audio.src = 'podcast_audios/' + filename + '?t=' + Date.now();
    audio.preload = 'metadata';
    
    audio.onloadedmetadata = () => {
        const duration = audio.duration;
        timeDisplay.innerText = '0:00 / ' + formatTime(duration);
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
    
    playerContainer.appendChild(playBtn);
    playerContainer.appendChild(timeDisplay);
    playerContainer.appendChild(progressContainer);
    playerContainer.appendChild(audio);
    
    container.appendChild(playerContainer);
    audioPlayers['audio_' + sceneId] = audio;
}

// ========== SCENE NAVIGATION ==========
function navigateScene(direction) {
    if (scenes.length === 0) return;
    
    if (direction === 'prev') {
        currentSceneIndex = (currentSceneIndex - 1 + scenes.length) % scenes.length;
    } else {
        currentSceneIndex = (currentSceneIndex + 1) % scenes.length;
    }
    
    currentSceneId = scenes[currentSceneIndex].id;
    updateCanvas(currentSceneId);
    L('📽️ Scene ' + (currentSceneIndex + 1) + ' of ' + scenes.length);
}

// ========== TEXT MANAGEMENT ==========
async function saveCurrentText() {
    if (!currentSceneId) return;
    
    const textarea = document.getElementById('sceneText');
    const newText = textarea.value.trim();
    
    L('💾 Saving text for scene #' + currentSceneId + '...');
    
    const fd = new FormData();
    fd.append('ajax_action', 'update_text');
    fd.append('scene_id', currentSceneId);
    fd.append('text_contents', newText);
    
    try {
        const {data} = await safeFetch(fd);
        if (data.success) {
            L('✅ Text saved for scene #' + currentSceneId);
            
            // Update scene in memory
            const scene = scenes.find(s => s.id == currentSceneId);
            if (scene) {
                scene.text_contents = newText;
            }
            
            // Update overlay
            document.getElementById('textOverlay').innerText = newText || 'No text for this scene';
            
            // Show saved indicator
            const savedEl = document.getElementById('savedIndicator');
            savedEl.style.display = 'inline';
            setTimeout(() => {
                savedEl.style.display = 'none';
            }, 2000);
        } else {
            L('❌ Failed to save text: ' + data.message);
        }
    } catch(e) {
        L('❌ Error saving text: ' + e.message);
    }
}

// ========== AUDIO PLAYBACK ==========
function togglePlay(sceneId) {
    const audio = document.getElementById('audio_' + sceneId);
    const playBtn = document.getElementById('play_btn_' + sceneId);
    
    if (!audio) return;
    
    if (currentPlayingId && currentPlayingId !== sceneId) {
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

// ========== AUDIO GENERATION ==========
async function generateCurrentAudio() {
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    await generateSingle(currentSceneId);
}

async function generateSingle(id) {
    L('🎨 Generating audio for scene #' + id + '...');
    
    const scene = scenes.find(s => s.id == id);
    if (!scene) return false;
    
    // Get actor from scene
    let actor = scene.actor || 'host';
    
    // Update UI
    document.getElementById('audioStatus').innerText = '🔄 Generating...';
    
    // Get voice picker based on actor
    let voicePicker;
    if (actor === 'guest' && podcastVideoType === 'podcast') {
        voicePicker = document.getElementById('guestVoicePicker');
        L('👤 Using GUEST voice');
    } else {
        voicePicker = document.getElementById('hostVoicePicker');
        L('🎤 Using HOST voice');
    }

    const ratePicker = document.getElementById('ratePicker');
    const textElement = document.getElementById('sceneText');

    // Check if voice is selected
    if (!voicePicker || !voicePicker.value) {
        L('❌ No voice selected! Please select a ' + actor + ' voice first.');
        document.getElementById('audioStatus').innerText = '❌ No Voice Selected';
        return false;
    }

    if (!ratePicker) {
        L('❌ Rate picker missing');
        return false;
    }
    
    if (!textElement || !textElement.value.trim()) {
        L('❌ No text content for scene #' + id);
        return false;
    }

    const formData = new FormData();
    formData.append('row_id', id);
    formData.append('text', textElement.value);
    formData.append('lang_code', 'en-US');
    formData.append('voice_id', voicePicker.value);
    formData.append('rate', ratePicker.value);
    
    L('🎚️ Using rate: ' + ratePicker.value + ' for scene #' + id);
    
    try {
        L('📡 Sending request to generate_voice.php...');
        const response = await fetch('generate_voice.php', { 
            method: 'POST', 
            body: formData 
        });
        
        const data = await response.json();
        
        if (data.success) {
            let filename = data.filename;
            if (!filename && data.file) {
                const parts = data.file.split('/');
                filename = parts[parts.length - 1].split('?')[0];
            }
            
            if (!filename) {
                L('❌ No filename in response');
                document.getElementById('audioStatus').innerText = '❌ Generation Failed';
                return false;
            }
            
            // Update the scene in memory
            scene.audio_file = filename;
            
            // Update audio player
            createAudioPlayer(id, filename);
            
            // Update status
            document.getElementById('audioStatus').innerText = '✅ Audio Ready';
            document.getElementById('audioStatus').classList.add('ready');
            
            L('✅ Audio generated for scene #' + id);
            return true;
        } else {
            L('❌ API Error for scene #' + id + ': ' + (data.message || 'Unknown error'));
            document.getElementById('audioStatus').innerText = '❌ Generation Failed';
            return false;
        }
    } catch (err) {
        L('❌ Network Error for scene #' + id + ': ' + err.message);
        document.getElementById('audioStatus').innerText = '❌ Network Error';
        return false;
    }
}

// ========== GENERATE ALL ==========
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
    btn.innerHTML = '<span>⏳</span> Processing...';
    progressDiv.style.display = 'block';
    
    let completed = 0;
    const total = scenes.length;
    
    progressFill.style.width = '0%';
    progressText.innerText = `0/${total} scenes completed`;

    for (let i = 0; i < scenes.length; i++) {
        const scene = scenes[i];
        
        // Select this scene
        currentSceneIndex = i;
        currentSceneId = scene.id;
        updateCanvas(scene.id);
        
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
    btn.innerHTML = '<span>🎤</span> Generate All';
    progressDiv.style.display = 'none';
    alert(`Audio generation complete! ${completed}/${total} scenes completed.`);
}

// ========== AUDIO UPLOAD ==========
function uploadAudio() {
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    currentAudioSceneId = currentSceneId;
    const fileInput = document.getElementById('audioUploadInput');
    fileInput.click();
}

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
            
            const scene = scenes.find(s => s.id == sceneId);
            if (scene) {
                scene.audio_file = data.filename;
            }
            
            createAudioPlayer(sceneId, data.filename);
            
            document.getElementById('audioStatus').innerText = '✅ Audio Ready';
            document.getElementById('audioStatus').classList.add('ready');
            
            alert('Audio uploaded successfully!');
        } else {
            L('❌ Upload failed: ' + data.message);
            alert('Upload failed: ' + data.message);
        }
    } catch (err) {
        L('❌ Upload error: ' + err.message);
        alert('Upload error: ' + err.message);
    }
    
    this.value = '';
    currentAudioSceneId = null;
});
</script>
</body>
</html>
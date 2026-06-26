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

$podcast_id    = $_GET['podcast_id'] ?? 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

// Get user plan for free trial check
$user_query = mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
$user_row = mysqli_fetch_assoc($user_query);
$plan_type = $user_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// Get podcast_id from URL if present
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$url_lang_filter = isset($_GET['lang_filter']) ? $_GET['lang_filter'] : 'en';

$sql = "SELECT * FROM hdb_podcasts WHERE id ='$podcast_id' ";
$title_query = mysqli_query($conn, $sql);
$row = mysqli_fetch_assoc($title_query);
$lang_code =  $row['lang_code'];
$podcast_lang_code  = $type_row['lang_code'] ?? 'en';

if ($url_podcast_id > 0) {
    $type_query = mysqli_query($conn, "SELECT video_type FROM hdb_podcasts WHERE id = $url_podcast_id");
    if ($type_query && mysqli_num_rows($type_query) > 0) {
        $type_row = mysqli_fetch_assoc($type_query);
       
		$podcast_lang_code  = $type_row['lang_code'] ?? 'en';
    }
}


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
                    'video_name' => utf8_encode($file),  // Fix encoding issues
                    'file_size' => $fsize ? $fsize : 0
                ];
            }
        }
    }
    
    $json = json_encode($videos, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    
    // Log json_last_error
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

// ---------- AJAX: Enhance Prompt & Get Hashtags ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'enhance_prompt') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $original_prompt = trim($_POST['prompt'] ?? '');

    if (empty($original_prompt)) {
        echo json_encode(['success' => false, 'message' => "Scene $scene_id has empty prompt"]);
        exit;
    }

    $gpt_prompt = "Enhance this image generation prompt for OpenAI gpt-image-1 model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words. The image should look like a real photograph.

Also generate 2 hashtags that describe the emotion and person type shown (examples: sadwoman, worriedman, happycouple, calmwoman, stressedman, peacefulwoman, anxiousman, hopefulwoman).

Original prompt:
" . $original_prompt . "

Return ONLY valid JSON, no markdown, no code fences:
{\"enhanced_prompt\": \"...\", \"hashtags\": \"emotionperson1,emotionperson2\"}";

    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | scene=$scene_id | calling callChatGPT_inam\n", 3, __DIR__ . "/a_debug.log");
    
    $result = callChatGPT_inam($gpt_prompt);
    
    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | result=" . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");
    
    if (!$result || !is_array($result)) {
        echo json_encode(['success' => false, 'message' => 'callChatGPT_inam returned invalid result: ' . print_r($result, true)]);
        exit;
    }
    
    if (!$result['success']) {
        $err = isset($result['error']) ? $result['error'] : (isset($result['message']) ? $result['message'] : 'Unknown error');
        echo json_encode(['success' => false, 'message' => 'AI Error: ' . $err]);
        exit;
    }

    $raw = $result['response'];
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```$/i', '', $raw);
    $parsed = json_decode(trim($raw), true);

    if (!$parsed || !isset($parsed['enhanced_prompt']) || !isset($parsed['hashtags'])) {
        error_log("Enhance parse fail scene=$scene_id raw=$raw\n", 3, __DIR__ . "/a_debug.log");
        echo json_encode(['success' => false, 'message' => 'JSON parse fail. Raw: ' . mb_substr($raw, 0, 300)]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'enhanced_prompt' => $parsed['enhanced_prompt'],
        'hashtags' => $parsed['hashtags']
    ]);
    exit;
}

// ---------- AJAX: Check Image Data by Hashtags ----------
// ---------- AJAX: Check Image Data by Hashtags ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_image_data') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $hashtags = trim($_POST['hashtags'] ?? '');
    $used_images = $_POST['used_images'] ?? '[]';
    
    // Decode and clean the used images array
    $used_images_array = json_decode($used_images, true);
    if (!is_array($used_images_array)) {
        $used_images_array = [];
    }
    
    // Remove any empty values and duplicates
    $used_images_array = array_filter(array_unique($used_images_array));
    
    // Log what we're doing
    error_log("=== CHECK_IMAGE_DATA ===");
    error_log("Hashtags: " . $hashtags);
    error_log("Used images count: " . count($used_images_array));
    error_log("Used images: " . implode(', ', $used_images_array));

    if (empty($hashtags)) {
        echo json_encode(['found' => false]);
        exit;
    }

    // Build hashtag conditions
    $tags = array_map('trim', explode(',', $hashtags));
    $tag_conditions = [];
    foreach ($tags as $tag) {
        if (!empty($tag)) {
            $escaped = mysqli_real_escape_string($conn, $tag);
            $tag_conditions[] = "image_hashtags LIKE '%$escaped%'";
        }
    }

    if (empty($tag_conditions)) {
        echo json_encode(['found' => false]);
        exit;
    }

    $where = "(" . implode(' OR ', $tag_conditions) . ")";
    
    // Add exclusion of used images
    if (!empty($used_images_array)) {
        $escaped_used = array_map(function($img) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $img) . "'";
        }, $used_images_array);
        $where .= " AND image_name NOT IN (" . implode(',', $escaped_used) . ")";
    }
    
    // IMPORTANT: Also exclude any images that are already in the podcast_stories table for THIS podcast
    // This is a safety measure in case the used_images array is incomplete
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if ($podcast_id > 0) {
        $where .= " AND image_name NOT IN (
            SELECT DISTINCT image_file 
            FROM hdb_podcast_stories 
            WHERE podcast_id = $podcast_id 
            AND image_file IS NOT NULL 
            AND image_file != ''
        )";
    }
    
    $sql = "SELECT * FROM hdb_image_data WHERE $where LIMIT 1";
    error_log("SQL: " . $sql);
    
    $r = mysqli_query($conn, $sql);

    if ($r && mysqli_num_rows($r) > 0) {
        $row = mysqli_fetch_assoc($r);
        error_log("FOUND: " . $row['image_name']);
        echo json_encode([
            'found' => true,
            'image_name' => $row['image_name'],
            'image_hashtags' => $row['image_hashtags']
        ]);
    } else {
        error_log("NOT FOUND");
        echo json_encode(['found' => false]);
    }
    exit;
}

// ---------- AJAX: Generate Image via gpt-image-1 ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_image') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $enhanced_prompt = trim($_POST['enhanced_prompt'] ?? '');
    $hashtags = trim($_POST['hashtags'] ?? '');

    if (empty($enhanced_prompt)) {
        echo json_encode(['success' => false, 'message' => 'Empty prompt', 'step' => 'validate']);
        exit;
    }

    $api_key ="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";
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'message' => '$api_key not set. Check chatgpt_functions.php', 'step' => 'api_key']);
        exit;
    }

    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    $image_folder = __DIR__ . '/podcast_images';
    while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    $image_name = $image_name_base . '.png';

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step A: image_name=$image_name | folder=$image_folder\n", 3, __DIR__ . "/a_debug.log");
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B: Calling gpt-image-1 API...\n", 3, __DIR__ . "/a_debug.log");
    
    $result = generateAndSaveImage($enhanced_prompt, $image_name_base, "1024x1536", $image_folder, $api_key);

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B result: " . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");

    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => $result['message'], 'step' => 'generate_image']);
        exit;
    }

    $full_path = $result['filepath'];
    $file_exists = file_exists($full_path);
    $file_size = $file_exists ? filesize($full_path) : 0;
    
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step C: file_exists=" . ($file_exists ? 'YES' : 'NO') . " | size={$file_size} bytes | path=$full_path\n", 3, __DIR__ . "/a_debug.log");

    if (!$file_exists || $file_size < 1000) {
        echo json_encode(['success' => false, 'message' => "Image file missing or too small ({$file_size} bytes). Path: $full_path", 'step' => 'verify_file']);
        exit;
    }

    $esc_name = mysqli_real_escape_string($conn, $image_name);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
    $esc_prompt = mysqli_real_escape_string($conn, $enhanced_prompt);
    
    $table_cols = [];
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
    if (!$col_check) {
        $db_warning = 'hdb_image_data table not found: ' . mysqli_error($conn);
        echo json_encode(['success' => true, 'image_name' => $image_name, 'file_size' => $file_size, 'db_warning' => $db_warning, 'step' => 'db_table_missing']);
        exit;
    }
    while ($c = mysqli_fetch_assoc($col_check)) $table_cols[] = $c['Field'];
    
    $insert_map = [];
    if (in_array('image_name', $table_cols))     $insert_map['image_name'] = "'$esc_name'";
    if (in_array('image_hashtags', $table_cols))  $insert_map['image_hashtags'] = "'$esc_hashtags'";
    if (in_array('image_prompt', $table_cols))    $insert_map['image_prompt'] = "'$esc_prompt'";
    if (in_array('created_at', $table_cols))      $insert_map['created_at'] = "NOW()";
    if (in_array('name', $table_cols) && !isset($insert_map['image_name']))           $insert_map['name'] = "'$esc_name'";
    if (in_array('hashtags', $table_cols) && !isset($insert_map['image_hashtags']))    $insert_map['hashtags'] = "'$esc_hashtags'";
    if (in_array('prompt', $table_cols) && !isset($insert_map['image_prompt']))        $insert_map['prompt'] = "'$esc_prompt'";
    
    $db_warning = '';
    $db_inserted = false;
    
    if (empty($insert_map)) {
        $db_warning = 'No matching columns in hdb_image_data. Found columns: ' . implode(', ', $table_cols);
    } else {
        $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";
        if (!mysqli_query($conn, $ins_sql)) {
            $db_warning = 'INSERT failed: ' . mysqli_error($conn) . ' | Columns: ' . implode(', ', $table_cols);
        } else {
            $db_inserted = true;
        }
    }

    echo json_encode([
        'success' => true,
        'image_name' => $image_name,
        'file_size' => $file_size,
        'file_path' => $full_path,
        'db_inserted' => $db_inserted,
        'db_warning' => $db_warning,
        'table_columns' => $table_cols
    ]);
    exit;
}

// ---------- AJAX: Update Scene Row ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? '');
    $video_file = mysqli_real_escape_string($conn, $_POST['video_file'] ?? '');
    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');
    $media_type = $_POST['media_type'] ?? 'image';

    if ($media_type === 'video' && !empty($video_file)) {
        $sql = "UPDATE hdb_podcast_stories SET video_file='$video_file' WHERE id=$scene_id";
    } else {
        $sql = "UPDATE hdb_podcast_stories SET image_file='$image_file'" . ($prompt ? ", prompt='$prompt'" : "") . " WHERE id=$scene_id";
    }
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Save Prompt Only ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_prompt') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');
    
    $sql = "UPDATE hdb_podcast_stories SET prompt='$prompt' WHERE id=$scene_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Get Media Library with Prompts ----------
// ---------- AJAX: Get Media Library ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_media_library') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $image_dir = __DIR__ . '/podcast_images/';
    $images = [];
    
    // Get image data from database
    $db_images = [];
    $r = mysqli_query($conn, "SELECT image_name, image_hashtags FROM hdb_image_data ORDER BY id DESC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $db_images[$row['image_name']] = $row['image_hashtags'] ?? '';
        }
    }
    
    error_log("=== GET_MEDIA_LIBRARY DEBUG ===");
    error_log("DB images found: " . count($db_images));
    
    if (is_dir($image_dir)) {
        $files = scandir($image_dir);
        error_log("Files in directory: " . count($files));
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) continue;
            
            $filepath = $image_dir . $file;
            $file_size = file_exists($filepath) ? filesize($filepath) : 0;
            
            $img_data = [
                'image_name' => $file,
                'hashtags' => $db_images[$file] ?? '',
                'file_size' => $file_size
            ];
            
            $images[] = $img_data;
            
            // Log first few images to see what's being returned
            if (count($images) <= 5) {
                error_log("Sample image: " . $file . " - hashtags: " . ($db_images[$file] ?? 'NONE'));
            }
        }
    }
    
    error_log("Total images returned: " . count($images));
    
    echo json_encode($images);
    exit;
}
// ---------- AJAX: Get Video Library ----------
// ---------- AJAX: Get Video Library ----------

// ---------- PAGE: Get ALL Podcasts (no language or admin restrictions) ----------
// ---------- PAGE: Get ALL Podcasts ----------
if ($url_podcast_id > 0) {
    // If podcast_id is in URL, show only that podcast
    $podcasts_result = mysqli_query($conn,
        "SELECT * FROM hdb_podcasts WHERE id = $url_podcast_id AND client_id = '$client_id' AND (video_status = '' OR video_status IS NULL) ORDER BY id DESC"
    );
} else {
    // Otherwise show all
    $podcasts_result = mysqli_query($conn,
        "SELECT * FROM hdb_podcasts WHERE client_id = '$client_id' AND (video_status = '' OR video_status IS NULL) ORDER BY id DESC"
    );
}
//echo "podcst id ".$podcast_id;die;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>VideoVizard - Design Your Visuals</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.5;
        }

        /* Header - Simplified */
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

        /* Navigation Buttons - Now at top */
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

        .nav-btn.audio {
            background: var(--info);
            color: white;
        }

        .nav-btn.translate {
            background: var(--success);
            color: white;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        /* Main Container */
        .container {
            max-width: 1200px;
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

        /* Top Bar */
        .top-bar {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (min-width: 768px) {
            .top-bar {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .project-info h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark-blue);
            margin-bottom: 4px;
        }

        .scene-count {
            font-size: 13px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* Skip Checkbox */
        .skip-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
        }

        .skip-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .skip-checkbox input[type="checkbox"]:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .free-trial-badge {
            background: var(--warning);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* Buttons */
        .btn {
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }

        /* Progress Bar */
        .progress-container {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .progress-bar-bg {
            background: var(--border);
            border-radius: 20px;
            height: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            background: linear-gradient(90deg, var(--accent), var(--success));
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: 20px;
        }

        .progress-text {
            font-size: 13px;
            color: var(--muted);
            margin-top: 8px;
            text-align: center;
        }

        /* Scene Grid - Mobile First */
        .scene-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-top: 20px;
        }

        @media (min-width: 640px) {
            .scene-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .scene-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        
		/* Scene Card - Like CapCut */
		.scene-card {
			background: var(--card-bg);
			border-radius: 16px;
			border: 1px solid var(--border);
			overflow: hidden;
			transition: all 0.2s;
			box-shadow: var(--shadow);
			scroll-margin-top: 80px;
			width: 100%;
			max-width: 280px; /* Limit maximum width */
			margin: 0 auto; /* Center if needed */
		}

        .scene-card.highlight {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(95, 209, 255, 0.3);
        }

        .scene-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            border-color: var(--accent);
        }

        .scene-preview {
			position: relative;
			aspect-ratio: 9/16;
			background: #1e293b;
			display: flex;
			align-items: center;
			justify-content: center;
			overflow: hidden;
			width: 100%;
		}

        .scene-preview img,
			.scene-preview video {
				width: 100%;
				height: 100%;
				object-fit: cover;
			}


        .scene-number {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0,0,0,0.6);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 2;
        }

        .scene-status {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(0,0,0,0.6);
            color: white;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 2;
        }

        .scene-status.has-image {
            background: rgba(16, 185, 129, 0.9);
        }

        .scene-status.pending {
            background: rgba(245, 158, 11, 0.9);
        }

        .scene-info {
            padding: 16px;
        }

        .scene-prompt {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 12px;
            line-height: 1.6;
            min-height: 60px;
            padding: 8px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border);
            width: 100%;
            font-family: 'Inter', monospace;
            resize: vertical;
        }

        .scene-prompt:focus {
            outline: none;
            border-color: var(--info);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .scene-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .scene-action-btn {
            flex: 1;
            padding: 10px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            min-height: 44px;
        }

        .scene-action-btn.regen {
            background: var(--info);
            color: white;
        }

        .scene-action-btn.regen:hover {
            background: #2563eb;
        }

        .scene-action-btn.upload {
            background: var(--warning);
            color: white;
        }

        .scene-action-btn.upload:hover {
            background: #d97706;
        }
		
/* Make the cards smaller overall */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

@media (min-width: 640px) {
    .cards-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 1024px) {
    .cards-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (min-width: 1440px) {
    .cards-grid { 
        grid-template-columns: repeat(5, 1fr);
    }
}
        /* Log Box */
        .log-box {
            width: 100%;
            height: 150px;
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

        /* Media Library Modal - Mobile Optimized */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            z-index: 9999;
        }

        .modal-overlay.active {
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        @media (min-width: 768px) {
            .modal-overlay.active {
                align-items: center;
            }
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 24px 24px 0 0;
            width: 100%;
            max-width: 1000px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.5);
        }

        @media (min-width: 768px) {
            .modal-content {
                border-radius: 24px;
                max-width: 1000px;
                margin: 20px;
            }
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-blue);
        }

        .modal-close {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #e2e8f0;
        }

        .modal-tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--border);
        }

        .modal-tab {
            flex: 1;
            padding: 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            background: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
        }

        .modal-tab.active {
            color: var(--info);
            border-bottom-color: var(--info);
        }

        .modal-search {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }

        .modal-search input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 30px;
            font-size: 14px;
            outline: none;
        }

        .modal-search input:focus {
            border-color: var(--info);
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            padding: 16px;
            overflow-y: auto;
            max-height: 400px;
        }

        @media (min-width: 640px) {
            .media-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .media-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        .media-item {
            background: #f8fafc;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
            position: relative;
        }

        .media-item:hover {
            transform: translateY(-2px);
            border-color: var(--info);
        }

        .media-item.selected {
            border-color: var(--success);
        }

        .media-preview {
            aspect-ratio: 9/16;
            width: 100%;
            object-fit: cover;
            background: #1e293b;
        }

        .media-info {
            padding: 6px 8px;
        }

        .media-name {
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .media-tags {
            font-size: 8px;
            color: var(--info);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .media-check {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 24px;
            height: 24px;
            background: var(--success);
            color: white;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            z-index: 2;
        }

        .media-item.selected .media-check {
            display: flex;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #f8fafc;
        }

        @media (min-width: 768px) {
            .modal-footer {
                flex-direction: row;
                justify-content: flex-end;
                align-items: center;
            }
        }

        .modal-footer .btn {
            width: 100%;
        }

        @media (min-width: 768px) {
            .modal-footer .btn {
                width: auto;
            }
        }

        #mediaSelInfo {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        @media (min-width: 768px) {
            #mediaSelInfo {
                text-align: left;
            }
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

        .hidden {
            display: none;
        }
		
		/* Media Library Modal - 9x16 Ratio Items */
.media-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    padding: 16px;
    overflow-y: auto;
    max-height: 400px;
}

@media (min-width: 640px) {
    .media-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (min-width: 1024px) {
    .media-grid {
        grid-template-columns: repeat(6, 1fr);
    }
}

.media-item {
    background: #f8fafc;
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
    position: relative;
    aspect-ratio: 9/16;  /* THIS MAKES IT 9x16 RATIO */
    display: flex;
    flex-direction: column;
}

.media-item:hover {
    transform: translateY(-2px);
    border-color: var(--info);
}

.media-item.selected {
    border-color: var(--success);
}

.media-preview {
    width: 100%;
    height: 70%; /* Takes 70% of the 9x16 card */
    object-fit: cover;
    background: #1e293b;
}

.media-info {
    padding: 6px 8px;
    height: 30%; /* Takes 30% of the 9x16 card */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.media-name {
    font-size: 10px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
}

.media-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 2px;
    max-height: 40px;
    overflow-y: auto;
}

.media-tags span {
    background: #e2e8f0;
    color: #64748b;
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 8px;
    font-weight: 600;
    display: inline-block;
}

.media-check {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 24px;
    height: 24px;
    background: var(--success);
    color: white;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    z-index: 2;
}

.media-item.selected .media-check {
    display: flex;
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
    <a href="audio_gen.php?podcast_id=<?=$podcast_id;?>" class="nav-btn audio">
        <span>🎵</span> Audio
    </a>
    <?php if ($podcast_lang_code == "en") { ?>
        <a href="trans_gen.php?podcast_id=<?=$podcast_id;?>" class="nav-btn translate">
            <span>🌐</span> Translate
        </a>
    <?php } ?>
</div>

<div class="container">
    <!-- Info Card -->
    <div class="card">
        <div class="card-header">
            <h1>🎥 Design Your Visuals</h1>
            <p>Assign visuals to each scene of your video. Click <strong>Generate All</strong> to automatically match images, or customize each scene manually.</p>
            <a href="javascript:void(0);" class="read-more-link" onclick="toggleReadMore()">
                <span id="readMoreText">Read more</span> <span id="readMoreIcon">▼</span>
            </a>
            <div id="readMoreContent" class="read-more-content" style="display: none;">
                <p>VideoVizard intelligently assigns visuals for every scene based on your script context. When you click <strong>Generate All</strong>, the system first checks your available image and video library. If no suitable visuals are found, it automatically generates new AI visuals tailored to each scene.</p>
                <p>You can customize any scene at any time. Use <strong>Regen</strong> to create a new visual based on the current prompt, or <strong>Upload</strong> to select from your media library.</p>
            </div>
        </div>
    </div>

    <!-- Scenes Card -->
    <div class="card" id="scenesCard" style="<?= $url_podcast_id > 0 ? 'display:block' : 'display:none' ?>">
        <div class="card-body">
            <div class="top-bar">
                <div class="project-info">
                    <h2 id="scenesTitle"><?= $podcast_title ? htmlspecialchars($podcast_title) : 'Scenes' ?></h2>
                    <div class="scene-count" id="sceneCount"></div>
                </div>
                <div class="action-buttons">
                    <!-- Skip Existing Checkbox -->
                    <div class="skip-checkbox">
                        <input type="checkbox" id="skipExistingCheckbox" <?= $is_free_trial ? 'disabled' : '' ?>>
                        <label for="skipExistingCheckbox">Skip existing</label>
                        <?php if ($is_free_trial): ?>
                            <span class="free-trial-badge">Free Trial</span>
                        <?php endif; ?>
                    </div>
                    
                    <button class="btn btn-primary" id="genAllBtn" onclick="generateAll()" disabled>
                        <span>🚀</span> Generate All
                    </button>
                    
                    <button class="btn btn-warning" style="display:none" id="stopBtn" onclick="STOP=true;">
                        <span>🛑</span> Stop
                    </button>
                </div>
            </div>

            <!-- Progress Bar -->
            <div id="progressWrap" style="display:none" class="progress-container">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="pf"></div>
                </div>
                <div class="progress-text" id="pt">0/0</div>
            </div>

            <!-- Scene Grid -->
            <div id="sceneGrid" class="scene-grid"></div>
        </div>
    </div>

    <!-- Log Box -->
    <textarea id="logBox" class="log-box" readonly placeholder="Progress log..."></textarea>
</div>

<!-- Media Library Modal - Mobile Optimized -->
<div id="mediaLibModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📁 Media Library — Scene <span id="editSceneId"></span></h3>
            <button class="modal-close" onclick="closeMediaLib()">✕</button>
        </div>
        
        <div class="modal-tabs">
            <button class="modal-tab active" id="tabImages" onclick="switchMediaTab('images')">🖼️ Images <span class="tab-count" id="tabImagesCount">0</span></button>
            <button class="modal-tab" id="tabVideos" onclick="switchMediaTab('videos')">🎬 Videos <span class="tab-count" id="tabVideosCount">0</span></button>
        </div>
        
        <div class="modal-search">
            <input type="text" id="mediaSearchInput" placeholder="Search by hashtag or filename..." onkeyup="filterMediaItems()">
        </div>
        
        <div id="mediaGrid" class="media-grid">
            <div style="text-align:center;padding:40px;color:#94a3b8;grid-column:1/-1;">Select a scene to browse media</div>
        </div>
        
        <div class="modal-footer">
            <div id="mediaSelInfo">No file selected</div>
            <div style="display:flex; gap:10px; width:100%;">
                <button class="btn btn-outline" style="flex:1;" onclick="closeMediaLib()">Cancel</button>
                <button class="btn btn-primary" style="flex:1;" id="mediaSelectBtn" onclick="confirmMediaSelect()" disabled>Save</button>
            </div>
        </div>
    </div>
</div>

<script>
// ========== GLOBAL VARIABLES ==========
let scenes = [], totalGen = 0, doneGen = 0, STOP = false;
let currentPodcastId = <?= $url_podcast_id ?>;
let editingSceneId = null, selectedMediaFile = null, selectedMediaType = null;
let activeMediaTab = 'images';
let cachedImages = [], cachedVideos = [];
let isFreeTrial = <?= $is_free_trial ? 'true' : 'false' ?>;

// ========== HELPER FUNCTIONS ==========
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

// ========== SCENE MANAGEMENT ==========
document.addEventListener('DOMContentLoaded', function() {
    if (currentPodcastId > 0) {
        loadScenes(currentPodcastId);
    }
    
    // Set skip checkbox state for free trial
    if (isFreeTrial) {
        document.getElementById('skipExistingCheckbox').checked = false;
        document.getElementById('skipExistingCheckbox').disabled = true;
    }
});

async function loadScenes(podcastId) {
    const card = document.getElementById('scenesCard');
    card.style.display = 'block';
    document.getElementById('sceneGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">⏳ Loading scenes...</div>';
    
    try {
        L('📥 Loading scenes for podcast ID: ' + podcastId + '...');
        const fd = new FormData();
        fd.append('ajax_action', 'get_scenes');
        fd.append('podcast_id', podcastId);
        const {data} = await safeFetch(fd);
        scenes = data;
        L('✅ Loaded ' + scenes.length + ' scenes');
        renderSceneGrid();
    } catch(e) {
        L('❌ LOAD FAILED: ' + e.message);
        document.getElementById('sceneGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#dc2626; padding:20px">❌ Error loading scenes.</div>';
    }
}

function renderSceneGrid() {
    const grid = document.getElementById('sceneGrid');
    const count = document.getElementById('sceneCount');
    const genAllBtn = document.getElementById('genAllBtn');
    
    count.innerText = scenes.length + ' scenes found';
    genAllBtn.disabled = scenes.length === 0;
    
    if (!scenes.length) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No scenes found</div>';
        return;
    }
    
    let html = '';
    scenes.forEach((s, i) => {
        const hasImage = s.image_file && s.image_file.trim() !== '';
        const hasVideo = s.video_file && s.video_file.trim() !== '';
        const mediaType = hasVideo ? 'video' : (hasImage ? 'image' : 'none');
        
        const statusClass = hasImage || hasVideo ? 'has-image' : 'pending';
        const statusText = hasImage ? '✅ Image' : (hasVideo ? '🎬 Video' : '⏳ Pending');
        
        const promptText = s.prompt ? s.prompt : (s.text_contents ? s.text_contents : '');
        
        html += `
        <div class="scene-card" id="scene-${s.id}" data-index="${i}">
            <div class="scene-preview">
                <div class="scene-number">${i+1}</div>
                <div class="scene-status ${statusClass}">${statusText}</div>
                ${hasImage ? 
                    `<img src="podcast_images/${s.image_file}" onclick="openPreview('podcast_images/${s.image_file}', ${s.id})">` : 
                    (hasVideo ? 
                        `<video src="podcast_videos/${s.video_file}" onclick="openVideoPreview('podcast_videos/${s.video_file}', ${s.id})"></video>` : 
                        `<div style="color:#94a3b8; font-size:12px;">No media</div>`)
                }
            </div>
            <div class="scene-info">
                <textarea class="scene-prompt" id="prompt-${s.id}" rows="2" placeholder="Enter prompt..." oninput="checkPromptChange(${s.id})" onchange="savePromptToDB(${s.id})">${escapeHtml(promptText)}</textarea>
                <div class="scene-actions">
                    <button class="scene-action-btn regen" id="regen-${s.id}" onclick="genOne(${s.id}, ${i}, true)">
                        <span>🔄</span> Regen
                    </button>
                    <button class="scene-action-btn upload" id="upload-${s.id}" onclick="openMediaLib(${s.id})">
                        <span>📁</span> Upload
                    </button>
                </div>
            </div>
        </div>`;
    });
    
    grid.innerHTML = html;
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

function checkPromptChange(sceneId) {
    // This function can be used to track changes if needed
}

async function savePromptToDB(sceneId) {
    const textarea = document.getElementById('prompt-' + sceneId);
    if (!textarea) return;
    
    const newPrompt = textarea.value.trim();
    const scene = scenes.find(s => parseInt(s.id) === sceneId);
    if (scene && scene.prompt === newPrompt) return;
    
    L('💾 Saving prompt for scene #' + sceneId + '...');
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', newPrompt);
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message || 'Unknown error');
        
        if (scene) scene.prompt = newPrompt;
        L('✅ Prompt saved for scene #' + sceneId);
    } catch(e) {
        L('❌ Failed to save prompt: ' + e.message);
    }
}

// ========== GENERATION FUNCTIONS ==========
async function genOne(sceneId, index, forceNew = false) {
    const scene = scenes.find(s => parseInt(s.id) === sceneId);
    if (!scene) { L('❌ Scene ' + sceneId + ' not found'); return; }
    
    const card = document.getElementById('scene-' + sceneId);
    const statusEl = card.querySelector('.scene-status');
    
    statusEl.className = 'scene-status st-working';
    statusEl.innerText = '🔄 Working...';
    
    // Scroll to this card
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('highlight');
    setTimeout(() => card.classList.remove('highlight'), 2000);
    
    // Get prompt from textarea
    const textarea = document.getElementById('prompt-' + sceneId);
    let originalPrompt = textarea ? textarea.value.trim() : (scene.prompt || scene.text_contents);
    
    if (!originalPrompt || originalPrompt.trim() === '') {
        L('❌ Scene #' + sceneId + ': No prompt');
        statusEl.className = 'scene-status st-error';
        statusEl.innerText = '❌ No prompt';
        return;
    }

    L('\n━━━ SCENE #' + sceneId + ' ━━━');
    L('📝 Prompt: ' + originalPrompt.substring(0, 150) + '...');
    
    // Step 1: Enhance prompt
    var enhanced, hashtags;
    statusEl.innerText = '🔄 Enhancing...';
    
    try {
        L('🔄 Step 1: Enhancing prompt...');
        var fd = new FormData();
        fd.append('ajax_action', 'enhance_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', originalPrompt);
        var res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message || 'Unknown error');
        enhanced = res.data.enhanced_prompt;
        hashtags = res.data.hashtags;
        L('✅ Enhanced prompt ready');
        L('🏷️ Hashtags: ' + hashtags);
    } catch(e) {
        L('❌ ENHANCE FAILED: ' + e.message);
        statusEl.className = 'scene-status st-error';
        statusEl.innerText = '❌ Error';
        return;
    }
    
    // Check if we should skip existing (checkbox enabled and not forceNew)
    const skipExisting = document.getElementById('skipExistingCheckbox').checked;
    let imageName = null, reused = false;
    
    if (!forceNew && skipExisting) {
        statusEl.innerText = '🔍 Checking library...';
        try {
            const usedImages = scenes
                .filter(s => s.image_file && s.image_file.trim() !== '' && parseInt(s.id) !== sceneId)
                .map(s => s.image_file);
            
            L('🔍 Checking for existing image...');
            
            var fd2 = new FormData();
            fd2.append('ajax_action', 'check_image_data');
            fd2.append('hashtags', hashtags);
            fd2.append('used_images', JSON.stringify(usedImages));
            fd2.append('podcast_id', currentPodcastId);
            
            var res2 = await safeFetch(fd2);
                        
            if (res2.data.found) {
                imageName = res2.data.image_name;
                reused = true;
                L('♻️ Found existing image: ' + imageName);
            } else {
                L('🆕 No existing image found');
            }
        } catch(e) {
            L('⚠️ Image check error: ' + e.message);
        }
    } else {
        L('🔄 Force new image generation');
    }
    
    // Generate new image if not found
    if (!imageName) {
        statusEl.innerText = '🎨 Generating...';
        try {
            L('🎨 Generating new image...');
            var fd3 = new FormData();
            fd3.append('ajax_action', 'generate_image');
            fd3.append('scene_id', sceneId);
            fd3.append('enhanced_prompt', enhanced);
            fd3.append('hashtags', hashtags);
            var res3 = await safeFetch(fd3);
            if (!res3.data.success) throw new Error(res3.data.message || 'Generation failed');
            imageName = res3.data.image_name;
            L('✅ Image generated: ' + imageName);
        } catch(e) {
            L('❌ GENERATION FAILED: ' + e.message);
            statusEl.className = 'scene-status st-error';
            statusEl.innerText = '❌ Gen Failed';
            return;
        }
    }
    
    // Update scene in DB
    statusEl.innerText = '💾 Saving...';
    try {
        L('💾 Updating database...');
        var fd4 = new FormData();
        fd4.append('ajax_action', 'update_scene');
        fd4.append('scene_id', sceneId);
        fd4.append('image_file', imageName);
        fd4.append('prompt', enhanced);
        var res4 = await safeFetch(fd4);
        if (!res4.data.success) throw new Error(res4.data.message);
        
        // Update the scene in memory
        scene.image_file = imageName;
        scene.prompt = enhanced;
        
        // Update the preview
        const previewEl = card.querySelector('.scene-preview');
        const oldImg = previewEl.querySelector('img');
        if (oldImg) oldImg.remove();
        
        const img = document.createElement('img');
        img.src = 'podcast_images/' + imageName + '?t=' + Date.now();
        img.onclick = function() { openPreview('podcast_images/' + imageName, sceneId); };
        previewEl.appendChild(img);
        
        statusEl.className = 'scene-status has-image';
        statusEl.innerText = reused ? '♻️ Reused' : '✅ Generated';
        
        L('✅ Scene #' + sceneId + ' complete!');
        
    } catch(e) {
        L('❌ DB UPDATE FAILED: ' + e.message);
        statusEl.className = 'scene-status st-error';
        statusEl.innerText = '❌ Update Failed';
    }
}

// ========== GENERATE ALL ==========
async function generateAll() {
    const btn = document.getElementById('genAllBtn');
    const progressWrap = document.getElementById('progressWrap');
    const stopBtn = document.getElementById('stopBtn');
    const skipExisting = document.getElementById('skipExistingCheckbox').checked;
    
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Generating All...';
    progressWrap.style.display = 'block';
    stopBtn.style.display = 'inline-flex';
    
    L('\n🚀 BATCH START - ' + (skipExisting ? 'Skip existing ON' : 'Skip existing OFF'));
    
    // Get pending scenes
    let pending = [];
    if (skipExisting) {
        pending = scenes.filter(s => !s.image_file || s.image_file.trim() === '');
        L('⏭️ Skipping ' + (scenes.length - pending.length) + ' scenes with existing images');
    } else {
        pending = scenes; // Generate for all scenes
        L('🔄 Generating for all ' + scenes.length + ' scenes');
    }
    
    if (pending.length === 0) {
        L('✅ No scenes need generation!');
        btn.disabled = false;
        btn.innerHTML = '<span>🚀</span> Generate All';
        return;
    }
    
    totalGen = pending.length;
    doneGen = 0;
    STOP = false;
    updateProgress();
    
    for (var i = 0; i < pending.length; i++) {
        if (STOP) { L('🛑 STOPPED BY USER'); break; }
        var s = pending[i];
        var idx = scenes.findIndex(sc => sc.id === s.id);
        await genOne(parseInt(s.id), idx, !skipExisting); // Force new if not skipping
        doneGen++;
        updateProgress();
        if (i < pending.length - 1 && !STOP) {
            L('⏳ Waiting 2s...');
            await new Promise(r => setTimeout(r, 2000));
        }
    }
    
    L('\n🎉 BATCH COMPLETE: ' + doneGen + '/' + totalGen + (STOP ? ' (stopped early)' : ''));
    btn.disabled = false;
    btn.innerHTML = '<span>🚀</span> Generate All';
    stopBtn.style.display = 'none';
}

function updateProgress() {
    const p = totalGen > 0 ? (doneGen / totalGen * 100) : 0;
    document.getElementById('pf').style.width = p + '%';
    document.getElementById('pt').innerText = doneGen + '/' + totalGen + ' (' + Math.round(p) + '%)';
}

// ========== MEDIA LIBRARY MODAL ==========
// ========== MEDIA LIBRARY MODAL WITH HASHTAG MATCHING ==========
async function openMediaLib(sceneId) {
    editingSceneId = sceneId;
    selectedMediaFile = null;
    selectedMediaType = null;
    activeMediaTab = 'images';
    cachedImages = [];
    cachedVideos = [];
    
    document.getElementById('editSceneId').innerText = '#' + sceneId;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No file selected';
    document.getElementById('mediaSearchInput').value = '';
    document.getElementById('mediaGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">⏳ Loading images...</div>';
    document.getElementById('mediaLibModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Set active tab
    document.getElementById('tabImages').className = 'modal-tab active';
    document.getElementById('tabVideos').className = 'modal-tab';
    
    // Get scene hashtags first
    const scene = scenes.find(s => parseInt(s.id) === sceneId);
    const sceneHashtags = scene?.hashtags || '';
    
    // Display the hashtags we're searching for
    const searchTagsDisplay = document.createElement('div');
    searchTagsDisplay.id = 'searchTagsDisplay';
    searchTagsDisplay.style.cssText = 'padding: 10px 16px; background: #e0f2fe; border-bottom: 1px solid var(--border); font-size: 12px; color: #0369a1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;';
    
    if (sceneHashtags && sceneHashtags.trim() !== '') {
        const tags = sceneHashtags.split(/\s+/).filter(t => t.trim() !== '');
        searchTagsDisplay.innerHTML = `
            <span style="font-weight: 600;">🔍 Searching for hashtags:</span>
            ${tags.map(tag => `<span style="background: #0f2a44; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-family: monospace;">${tag}</span>`).join(' ')}
            <span style="margin-left: auto; color: #64748b;">${tags.length} tag${tags.length !== 1 ? 's' : ''}</span>
        `;
    } else {
        searchTagsDisplay.innerHTML = `
            <span style="font-weight: 600; color: #f59e0b;">⚠️ No hashtags found for this scene</span>
            <span style="margin-left: auto; color: #64748b;">Showing all images</span>
        `;
    }
    
    // Insert after modal-tabs
    const modalTabs = document.querySelector('.modal-tabs');
    const existingDisplay = document.getElementById('searchTagsDisplay');
    if (existingDisplay) existingDisplay.remove();
    modalTabs.insertAdjacentElement('afterend', searchTagsDisplay);
    
    console.log('🔍 Scene hashtags:', sceneHashtags);
    L(`🔍 Scene #${sceneId} searching for hashtags: ${sceneHashtags || '(none)'}`);
    
    // Load images with hashtag matching
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_media_library');
        fd.append('scene_id', sceneId);
        const {data} = await safeFetch(fd);
        
        console.log('📸 Total images loaded:', data.length);
        L(`📸 Total images in library: ${data.length}`);
        
        // Store all images
        cachedImages = data;
        
        // Filter images based on hashtag match with scene hashtags
        let filteredImages = data;
        const matchingStats = {};
        
        if (sceneHashtags && sceneHashtags.trim() !== '') {
            // Split scene hashtags into array
            const sceneTags = sceneHashtags.toLowerCase().split(/\s+/).filter(tag => tag.trim() !== '');
            console.log('🏷️ Scene tags array:', sceneTags);
            L(`🏷️ Scene tags: ${sceneTags.join(', ')}`);
            
            if (sceneTags.length > 0) {
                // Filter images that have at least one matching hashtag
                filteredImages = data.filter(img => {
                    const imgTags = (img.hashtags || '').toLowerCase();
                    console.log(`   Checking image ${img.image_name} with tags: "${imgTags}"`);
                    
                    const matches = sceneTags.filter(tag => imgTags.includes(tag));
                    
                    // Store match count for sorting
                    img.matchCount = matches.length;
                    
                    // Track which tags are matching
                    matches.forEach(tag => {
                        matchingStats[tag] = (matchingStats[tag] || 0) + 1;
                    });
                    
                    if (matches.length > 0) {
                        console.log(`   ✅ MATCHED! Found ${matches.length} matches: ${matches.join(', ')}`);
                    }
                    
                    return matches.length > 0;
                });
                
                // Log matching stats to console and log box
                console.log('📊 Hashtag matching stats:', matchingStats);
                L('📊 Hashtag matching stats:');
                Object.entries(matchingStats).forEach(([tag, count]) => {
                    L(`   • ${tag}: found in ${count} image${count !== 1 ? 's' : ''}`);
                });
                
                console.log(`🎯 Found ${filteredImages.length} images matching scene hashtags out of ${data.length} total`);
                L(`🎯 Found ${filteredImages.length} images matching scene hashtags out of ${data.length} total`);
            }
        } else {
            // No hashtags, show all images
            filteredImages = data.map(img => {
                img.matchCount = 0;
                return img;
            });
            console.log(`📋 No scene hashtags - showing all ${data.length} images`);
            L(`📋 No scene hashtags - showing all ${data.length} images`);
        }
        
        document.getElementById('tabImagesCount').innerText = filteredImages.length;
        renderMediaGrid(filteredImages, sceneHashtags);
    } catch(e) {
        console.error('❌ Error loading images:', e);
        document.getElementById('mediaGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#dc2626">❌ ' + e.message + '</div>';
    }
    
    // Pre-load videos (no hashtag matching for videos)
    try {
        const fd2 = new FormData();
        fd2.append('ajax_action', 'get_video_library');
        const response = await fetch('image_gen.php', { method: 'POST', body: fd2 });
        const vdata = await response.json();
        cachedVideos = vdata;
        document.getElementById('tabVideosCount').innerText = vdata.length;
        console.log('🎬 Videos loaded:', vdata.length);
    } catch(e) {
        console.error("Background video load error:", e);
    }
}


function renderMediaGrid(images, sceneHashtags = '') {
    const grid = document.getElementById('mediaGrid');
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No matching images found</div>';
        return;
    }
    
    // Split scene hashtags for highlighting
    const sceneTags = sceneHashtags.toLowerCase().split(/\s+/).filter(tag => tag.trim() !== '');
    
    let html = '';
    images.forEach(img => {
        const tags = img.hashtags || '';
        const name = img.image_name;
        
        // Check which scene tags match this image
        const imgTagsLower = tags.toLowerCase();
        const matchingTags = sceneTags.filter(tag => imgTagsLower.includes(tag));
        const matchCount = matchingTags.length;
        
        // Split all tags for display
        const allTags = tags.split(',').map(t => t.trim()).filter(t => t);
        
        // Create tooltip with all tags
        const tooltipText = allTags.length > 0 ? allTags.join(', ') : 'No tags';
        
        // Add match indicator if there are matches
        const matchIndicator = matchCount > 0 ? 
            `<span style="background: #10b981; color: white; padding: 2px 6px; border-radius: 12px; font-size: 8px; margin-left: 4px;" title="Matches: ${matchingTags.join(', ')}">${matchCount} match</span>` : 
            '';
        
        // Format tags for display with colored highlighting
        let tagsDisplay = '';
        if (allTags.length > 0) {
            tagsDisplay = '<div class="media-tags" style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">';
            allTags.forEach(tag => {
                const isMatch = matchingTags.includes(tag.toLowerCase());
                tagsDisplay += `<span style="background: ${isMatch ? '#10b981' : '#e2e8f0'}; color: ${isMatch ? 'white' : '#64748b'}; padding: 2px 6px; border-radius: 12px; font-size: 8px; font-weight: 600; display: inline-block;">${tag}</span>`;
            });
            tagsDisplay += '</div>';
        }
        
        html += `
        <div class="media-item" data-file="${name}" data-tags="${tags}" data-type="image" data-match="${matchCount}" title="${tooltipText}" onclick="selectMediaItem(this, '${name}', 'image')">
            <img src="podcast_images/${name}" class="media-preview" onerror="this.src='';this.alt='Missing'" loading="lazy">
            <div class="media-info">
                <div class="media-name" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px;">
                    <span title="${name}">${name.substring(0, 12)}${name.length > 12 ? '...' : ''}</span>
                    ${matchIndicator}
                </div>
                ${tagsDisplay}
            </div>
            <div class="media-check">✓</div>
        </div>`;
    });
    grid.innerHTML = html;
    
    // Sort by match count (highest first)
    const mediaItems = document.querySelectorAll('#mediaGrid .media-item');
    const itemsArray = Array.from(mediaItems);
    itemsArray.sort((a, b) => {
        const matchA = parseInt(a.dataset.match || '0');
        const matchB = parseInt(b.dataset.match || '0');
        return matchB - matchA;
    });
    
    // Reorder in DOM
    itemsArray.forEach(item => grid.appendChild(item));
}



function filterMediaItems() {
    const term = document.getElementById('mediaSearchInput').value.toLowerCase();
    let visible = 0;
    let matchingTags = new Set();
    
    document.querySelectorAll('#mediaGrid .media-item').forEach(item => {
        const name = (item.dataset.file || '').toLowerCase();
        const tags = (item.dataset.tags || '').toLowerCase();
        
        // Search in both filename and hashtags
        if (name.includes(term) || tags.includes(term)) {
            item.style.display = '';
            visible++;
            
            // Track which tags are matching the search term
            if (term && tags.includes(term)) {
                // Handle both comma-separated and space-separated tags
                const tagList = tags.split(/[,\s]+/).map(t => t.trim()).filter(t => t);
                tagList.forEach(tag => {
                    if (tag.includes(term)) {
                        matchingTags.add(tag);
                    }
                });
            }
        } else {
            item.style.display = 'none';
        }
    });
    
    var label = activeMediaTab === 'videos' ? 'videos' : 'images';
    const resultCount = document.getElementById('mediaResultCount');
    
    if (term && matchingTags.size > 0) {
        resultCount.innerHTML = `${visible} ${label} (matching tags: ${Array.from(matchingTags).join(', ')})`;
    } else {
        resultCount.innerText = visible + ' ' + label;
    }
}

function renderMediaGrid(images) {
    const grid = document.getElementById('mediaGrid');
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No images found</div>';
        return;
    }
    
    let html = '';
    images.forEach(img => {
        const tags = img.hashtags || '';
        const name = img.image_name;
        
        html += `
        <div class="media-item" data-file="${name}" data-tags="${tags}" data-type="image" onclick="selectMediaItem(this, '${name}', 'image')">
            <img src="podcast_images/${name}" class="media-preview" onerror="this.src='';this.alt='Missing'" loading="lazy">
            <div class="media-info">
                <div class="media-name" title="${name}">${name.substring(0, 15)}${name.length > 15 ? '...' : ''}</div>
                ${tags ? '<div class="media-tags">🏷️ ' + tags.substring(0, 20) + (tags.length > 20 ? '...' : '') + '</div>' : ''}
            </div>
            <div class="media-check">✓</div>
        </div>`;
    });
    grid.innerHTML = html;
}

function renderVideoGrid(videos) {
    const grid = document.getElementById('mediaGrid');
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No videos found</div>';
        return;
    }
    
    let html = '';
    videos.forEach(vid => {
        const name = vid.video_name;
        const videoPath = 'podcast_videos/' + name;
        
        html += `
        <div class="media-item" data-file="${name}" data-tags="" data-type="video" onclick="selectMediaItem(this, '${name}', 'video')">
            <video class="media-preview" preload="metadata">
                <source src="${videoPath}" type="video/mp4">
            </video>
            <div class="media-info">
                <div class="media-name" title="${name}">${name.substring(0, 15)}${name.length > 15 ? '...' : ''}</div>
            </div>
            <div class="media-check">✓</div>
        </div>`;
    });
    grid.innerHTML = html;
}

function switchMediaTab(tab) {
    activeMediaTab = tab;
    selectedMediaFile = null;
    selectedMediaType = null;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No file selected';
    document.getElementById('mediaSearchInput').value = '';
    
    if (tab === 'images') {
        document.getElementById('tabImages').className = 'modal-tab active';
        document.getElementById('tabVideos').className = 'modal-tab';
        renderMediaGrid(cachedImages);
    } else {
        document.getElementById('tabImages').className = 'modal-tab';
        document.getElementById('tabVideos').className = 'modal-tab active';
        renderVideoGrid(cachedVideos);
    }
}

function selectMediaItem(el, fileName, mediaType) {
    document.querySelectorAll('#mediaGrid .media-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    selectedMediaFile = fileName;
    selectedMediaType = mediaType;
    document.getElementById('mediaSelInfo').innerHTML = '✅ Selected: ' + fileName.substring(0, 20) + (fileName.length > 20 ? '...' : '');
    document.getElementById('mediaSelectBtn').disabled = false;
}



async function confirmMediaSelect() {
    if (!selectedMediaFile || !editingSceneId || !selectedMediaType) return;
    
    L('📁 Assigning ' + selectedMediaType + ' ' + selectedMediaFile + ' to scene #' + editingSceneId);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_scene');
        fd.append('scene_id', editingSceneId);
        fd.append('media_type', selectedMediaType);
        
        if (selectedMediaType === 'video') {
            fd.append('video_file', selectedMediaFile);
            fd.append('image_file', '');
        } else {
            fd.append('image_file', selectedMediaFile);
            fd.append('video_file', '');
        }
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message);
        
        // Update the scene card
        const card = document.getElementById('scene-' + editingSceneId);
        const previewEl = card.querySelector('.scene-preview');
        const statusEl = card.querySelector('.scene-status');
        
        // Remove old media
        const oldImg = previewEl.querySelector('img');
        const oldVideo = previewEl.querySelector('video');
        if (oldImg) oldImg.remove();
        if (oldVideo) oldVideo.remove();
        
        if (selectedMediaType === 'image') {
            const img = document.createElement('img');
            img.src = 'podcast_images/' + selectedMediaFile + '?t=' + Date.now();
            img.onclick = function() { openPreview('podcast_images/' + selectedMediaFile, editingSceneId); };
            previewEl.appendChild(img);
            statusEl.className = 'scene-status has-image';
            statusEl.innerText = '🖼️ Image Set';
        } else {
            const video = document.createElement('video');
            video.src = 'podcast_videos/' + selectedMediaFile;
            video.onclick = function() { openVideoPreview('podcast_videos/' + selectedMediaFile, editingSceneId); };
            previewEl.appendChild(video);
            statusEl.className = 'scene-status has-image';
            statusEl.innerText = '🎬 Video Set';
        }
        
        // Update scene in memory
        const scene = scenes.find(s => parseInt(s.id) === editingSceneId);
        if (scene) {
            if (selectedMediaType === 'image') scene.image_file = selectedMediaFile;
            if (selectedMediaType === 'video') scene.video_file = selectedMediaFile;
        }
        
        L('✅ Scene #' + editingSceneId + ' updated');
    } catch(e) {
        L('❌ Update failed: ' + e.message);
        alert('Failed to update: ' + e.message);
    }
    
    closeMediaLib();
}

function closeMediaLib() {
    document.getElementById('mediaLibModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ========== PREVIEW FUNCTIONS ==========
function openPreview(src, sceneId) {
    window.open(src, '_blank');
}

function openVideoPreview(src, sceneId) {
    window.open(src, '_blank');
}

// ========== EVENT LISTENERS ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMediaLib();
    }
});

document.getElementById('mediaLibModal').addEventListener('click', function(e) {
    if (e.target === this) closeMediaLib();
});
</script>
</body>
</html>
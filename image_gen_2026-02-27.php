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

$podcast_id    = $_GET['podcast_id'] ?? 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

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
<meta charset="UTF-8"><title>VideoVizard-From Idea to Video in Minutes.</title>
<link rel="stylesheet" href="/css/header.css">
<link rel="stylesheet" href="/css/tooltip.css">																																											
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
.btn-edit{background:#f59e0b}.btn-edit:hover{background:#d97706}
.btn-regen{background:#dc2626;font-size:11px;padding:6px 10px}.btn-regen:hover{background:#b91c1c}
.btn-all{background:#059669;padding:10px 24px;border-radius:8px;font-size:13px}.btn-all:hover:not(:disabled){background:#047857}
table{width:100%;border-collapse:collapse;margin-top:15px}
th{background:#f8fafc;padding:10px;text-align:left;font-size:11px;color:#64748b;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.5px}
td{padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:top}
tr:hover{background:#f8fafc}
.img-thumb{width:45px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;transition:opacity .2s}.img-thumb:hover{opacity:.8}
.status-badge{padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;display:inline-block}
.st-done{background:#ecfdf5;color:#059669}
.st-pending{background:#fef3c7;color:#d97706}
.st-working{background:#eff6ff;color:#2563eb}
.st-error{background:#fef2f2;color:#dc2626}
.st-reused{background:#f0fdf4;color:#15803d}
.prompt-text{max-width:250px;max-height:60px;overflow:hidden;text-overflow:ellipsis;font-size:11px;color:#475569;line-height:1.4}
.editable-prompt {
    width: 100%;
    min-height: 60px;
    padding: 8px;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    line-height: 1.5;
    resize: vertical;
    background: #fff;
    transition: border 0.2s;
}
.editable-prompt:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.prompt-container {
    position: relative;
    min-width: 280px;
}
.prompt-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-top: 5px;
    gap: 8px;
}
.btn-tiny {
    padding: 4px 8px;
    font-size: 11px;
    border-radius: 4px;
}
.btn-save-prompt {
    background: #059669;
    color: white;
    border: none;
    cursor: pointer;
}
.btn-save-prompt:hover {
    background: #047857;
}
.prompt-saved {
    color: #059669;
    font-size: 11px;
    font-weight: 600;
    animation: fadeOut 2s forwards;
}
@keyframes fadeOut {
    0% { opacity: 1; }
    70% { opacity: 1; }
    100% { opacity: 0; }
}
.pb{width:100%;height:8px;background:#e2e8f0;border-radius:10px;overflow:hidden;margin:15px 0}
.pf{height:100%;background:linear-gradient(90deg,#7c3aed,#10b981);border-radius:10px;transition:width .3s;width:0}
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
/* Media Library Modal */
.media-modal{display:none;position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.7);backdrop-filter:blur(5px)}
.media-modal-content{background:#fff;margin:3% auto;width:90%;max-width:1100px;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:modalIn .2s ease}
.media-modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 25px;border-bottom:2px solid #e2e8f0;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:12px 12px 0 0}
.media-modal-header h2{color:#1e293b;font-size:18px;margin:0}
.media-close-btn{font-size:28px;font-weight:700;color:#64748b;cursor:pointer;line-height:1}.media-close-btn:hover{color:#dc2626}
.media-search-bar{padding:12px 25px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center}
.media-search-box{flex:1;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none}.media-search-box:focus{border-color:#2563eb}
.media-grid-container{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:15px;padding:20px 25px;max-height:450px;overflow-y:auto;background:#f8fafc}
.media-item{background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,.05);border:2px solid transparent;cursor:pointer;position:relative;transition:all .2s}
.media-item:hover{transform:translateY(-2px);box-shadow:0 6px 12px rgba(0,0,0,.1);border-color:#2563eb}
.media-item.selected{border-color:#059669;background:#f0fdf4}
.media-preview-img{width:100%;height:130px;object-fit:cover;display:block;background:#f1f5f9}
.media-item-info{padding:8px 10px}
.media-item-name{font-size:10px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-item-tags{font-size:9px;color:#7c3aed;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-item-size{font-size:9px;color:#94a3b8;margin-top:2px}
.media-check{position:absolute;top:8px;right:8px;width:22px;height:22px;background:#059669;color:#fff;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.media-item.selected .media-check{display:flex}
.media-modal-footer{display:flex;justify-content:flex-end;gap:12px;padding:15px 25px;border-top:2px solid #e2e8f0;background:#f8fafc;border-radius:0 0 12px 12px;align-items:center}
.media-selection-info{flex:1;font-size:12px;color:#475569}
.media-footer-btn{padding:10px 24px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.media-cancel-btn{background:#e2e8f0;color:#475569}.media-cancel-btn:hover{background:#cbd5e1}
.media-select-btn{background:#059669;color:#fff}.media-select-btn:hover{background:#047857}
.media-select-btn:disabled{opacity:.5;cursor:not-allowed}
/* Media Tabs */
.media-tabs{display:flex;gap:0;padding:0 25px;background:#fff;border-bottom:2px solid #e2e8f0}
.media-tab{padding:12px 24px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;border:none;background:none;border-bottom:3px solid transparent;transition:all .2s}
.media-tab:hover{color:#1e293b;background:#f8fafc}
.media-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.media-tab .tab-count{font-size:10px;background:#e2e8f0;color:#64748b;padding:2px 6px;border-radius:10px;margin-left:6px}
.media-tab.active .tab-count{background:#dbeafe;color:#2563eb}
.media-save-bar{display:flex;justify-content:space-between;align-items:center;padding:12px 25px;background:#fffbeb;border-bottom:1px solid #fde68a}
.media-save-btn{padding:10px 28px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:#059669;color:#fff;transition:all .2s}.media-save-btn:hover{background:#047857}
.media-save-btn:disabled{opacity:.5;cursor:not-allowed}
.video-preview-thumb{width:100%;height:130px;background:#1e293b;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:28px}
.media-item-type{font-size:9px;color:#f59e0b;margin-top:2px;font-weight:600}

.canvas-container {
    width: 360px;
    height: 640px;
    overflow: hidden;
    position: relative;
    background: #000;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

.ken-burns-active {
    width: 100%;
    height: 100%;
    object-fit: cover;
    animation: kenburns-effect 12s infinite alternate ease-in-out;
}

@keyframes kenburns-effect {
    0% {
        transform: scale(1.0) translate(0, 0);
    }
    100% {
        transform: scale(1.2) translate(-2%, -3%);
    }
}

/* For video preview area */
#videomaker-preview-area {
    width: 360px;
    height: 640px;
    overflow: hidden;
    position: relative;
    background: #000;
    margin: 0 auto;
}

.ken-burns-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
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
</style>
</head>
<body>

<div class="container">
    <he<header class="vidora-header">
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

    <nav class="vidora-nav">
       
	    <a href="vidora_home.php"  class="active">Home</a>
        <a href="audio_gen.php?podcast_id=<?=$podcast_id;?>" >Audio</a>
		 <?if ($podcast_lang_code == "en")
				{?>
					 <a href="trans_gen.php?podcast_id=<?=$podcast_id;?>">Translation</a>
					
			<?	}?>
           
		<a href="videomaker.php?podcast_id=<?=$podcast_id;?>">Video</a>
       
		 
       

    </div>
    </nav>





<div class="card">
  <h1>🎥 Design Your Visuals</h1>

  <!-- Short Description (Always Visible) -->
  <p>
    Assign visuals to each scene of your video in seconds. 
    Click <strong>Generate All</strong> to automatically match images or videos to your script, 
    or customize each scene manually.
  </p>

  <!-- Read More Toggle -->
  <a href="javascript:void(0);" onclick="toggleReadMore()" id="readMoreBtn" style="font-weight:600; color:#6366f1; text-decoration:none;">
    Read more
  </a>

  <!-- Hidden Detailed Description -->
  <div id="readMoreContent" style="display:none; margin-top:10px; color:#475569; line-height:1.6;">
    <p>
      VideoVizard intelligently assigns visuals for every scene based on your script context. 
      When you click <strong>Generate All</strong>, the system first checks your available image 
      and video library. If no suitable visuals are found, it automatically generates new AI visuals 
      tailored to each scene.
    </p>

    <p>
      You can customize any scene at any time. Use <strong>AI Generate</strong> to create a new visual, 
      upload your own image or video, or edit the prompt to refine results. You can also copy the prompt, 
      enhance it, and regenerate visuals until they match your vision perfectly.
    </p>

    <p>
      This gives you the perfect balance of speed and control — automatic visuals when you want automation, 
      and full customization when you want precision.
    </p>
  </div>
</div>

<script>
function toggleReadMore() {
  const content = document.getElementById("readMoreContent");
  const btn = document.getElementById("readMoreBtn");

  if (content.style.display === "none") {
    content.style.display = "block";
    btn.textContent = "Read less";
  } else {
    content.style.display = "none";
    btn.textContent = "Read more";
  }
}
</script>












<div class="card" id="scenesCard" style="<?= $url_podcast_id > 0 ? 'display:block' : 'display:none' ?>">
<div class="top-bar">
    <div>
        <h2 style="margin:0" id="scenesTitle"><?= $podcast_title ? htmlspecialchars($podcast_title) : 'Scenes' ?></h2>
        <div class="scene-count" id="sceneCount"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-all" id="genAllBtn" onclick="generateAll()" disabled>🚀 Generate All Images</button>
        <button class="btn" style="background:#dc2626;display:none" id="stopBtn" onclick="STOP=true;this.innerText='🛑 Stopping...'">🛑 Stop</button>
        <button class="btn" style="background:#64748b" onclick="document.getElementById('logBox').value=''">🗑️ Clear Log</button>
    </div>
</div>

<div id="progressWrap" style="display:none">
    <div class="pb"><div class="pf" id="pf"></div></div>
    <div style="text-align:center;font-size:12px;color:#64748b" id="pt">0/0</div>
</div>



<div style="overflow-x:auto">
<table>
<thead>
<tr><th>#</th><th>ID</th><th>Image</th><th>Prompt (Editable)</th><th>Text (Preview)</th><th>Status</th><th>Action</th></tr>
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

<div style="margin-bottom:15px">
    <label style="font-weight:700;font-size:12px;color:#334155;display:block;margin-bottom:5px">📋 Progress & Error Log:</label>
    <textarea id="logBox" readonly style="width:100%;height:200px;background:#0f172a;color:#a5f3fc;font-family:'Courier New',monospace;font-size:11.5px;padding:12px;border-radius:8px;border:2px solid #334155;resize:vertical;line-height:1.7;white-space:pre-wrap;outline:none;" placeholder="Waiting for actions..."></textarea>
</div>
<!-- Media Library Modal -->
<div id="mediaLibModal" class="media-modal">
    <div class="media-modal-content">
        <div class="media-modal-header">
            <h2>📁 Media Library — Scene <span id="editSceneId"></span></h2>
            <span class="media-close-btn" onclick="closeMediaLib()">&times;</span>
        </div>
        <div class="media-tabs">
            <button class="media-tab active" id="tabImages" onclick="switchMediaTab('images')">🖼️ Images <span class="tab-count" id="tabImagesCount">0</span></button>
            <button class="media-tab" id="tabVideos" onclick="switchMediaTab('videos')">🎬 Videos <span class="tab-count" id="tabVideosCount">0</span></button>
        </div>
        <div class="media-save-bar">
            <div class="media-selection-info" id="mediaSelInfo">No file selected</div>
            <button class="media-save-btn" id="mediaSelectBtn" onclick="confirmMediaSelect()" disabled>💾 Save to Scene</button>
        </div>
        <div class="media-search-bar">
            <input type="text" id="mediaSearchInput" class="media-search-box" placeholder="Search by hashtag or filename..." onkeyup="filterMediaItems()">
            <span id="mediaResultCount" style="font-size:11px;color:#64748b;margin-left:10px"></span>
        </div>
        <div id="mediaGrid" class="media-grid-container">
            <div style="text-align:center;padding:40px;color:#94a3b8">Select a scene to browse media</div>
        </div>
        <div class="media-modal-footer">
            <button class="media-footer-btn media-cancel-btn" onclick="closeMediaLib()">Cancel</button>
        </div>
    </div>
</div>
<!-- Add this temporary debug button -->

<script>
let scenes = [], totalGen = 0, doneGen = 0, STOP = false;
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

// Update prompt in memory when edited
function updatePromptInMemory(sceneId, newPrompt) {
    const scene = scenes.find(s => parseInt(s.id) === sceneId);
    if (scene) {
        scene.prompt = newPrompt;
    }
    // Hide any previous saved indicator
    const savedEl = document.getElementById('saved-' + sceneId);
    if (savedEl) savedEl.style.display = 'none';
}

// Save prompt to database
async function savePromptToDB(sceneId) {
    const textarea = document.getElementById('prompt-' + sceneId);
    if (!textarea) return;
    
    const newPrompt = textarea.value.trim();
    const savedEl = document.getElementById('saved-' + sceneId);
    
    L('💾 Saving prompt for scene #' + sceneId + '...');
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', newPrompt);
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message || 'Unknown error');
        
        // Update the scene in memory
        const scene = scenes.find(s => parseInt(s.id) === sceneId);
        if (scene) scene.prompt = newPrompt;
        
        // Show saved indicator
        if (savedEl) {
            savedEl.style.display = 'inline';
            savedEl.innerText = '✓ Saved';
            setTimeout(() => {
                savedEl.style.display = 'none';
            }, 2000);
        }
        
        L('✅ Prompt saved for scene #' + sceneId);
    } catch(e) {
        L('❌ Failed to save prompt: ' + e.message);
        alert('Failed to save prompt: ' + e.message);
    }
}

// Auto-load scenes on page load
document.addEventListener('DOMContentLoaded', function() {
    if (currentPodcastId > 0) {
        loadScenes(currentPodcastId);
    }
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

function renderTable() {
    const body = document.getElementById('scenesBody');
    document.getElementById('sceneCount').innerText = scenes.length + ' scenes found';
    document.getElementById('genAllBtn').disabled = scenes.length === 0;
    
    if (!scenes.length) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">No scenes found</td></tr>';
        return;
    }
    
    let html = '';
    scenes.forEach((s, i) => {
        const hasImage = s.image_file && s.image_file.trim() !== '';
        const imgHtml = hasImage 
            ? '<img src="podcast_images/' + s.image_file + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + s.image_file + '\', ' + s.id + ')" onerror="this.src=\'\';this.alt=\'Missing\'" id="img-' + s.id + '">' 
            : '<span style="color:#94a3b8;font-size:11px" id="img-' + s.id + '">No image</span>';
        
        const statusHtml = hasImage 
            ? '<span class="status-badge st-done" id="st-' + s.id + '">✅ Has Image</span>'
            : '<span class="status-badge st-pending" id="st-' + s.id + '">⏳ Pending</span>';
        
        const textPreview = s.text_contents ? s.text_contents.substring(0, 120) + (s.text_contents.length > 120 ? '...' : '') : '';
        const promptValue = s.prompt ? escapeHtml(s.prompt) : '';
        
        html += '<tr id="row-' + s.id + '">' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + s.id + '</td>' +
            '<td>' + imgHtml + '</td>' +
            '<td>' +
                '<div class="prompt-container">' +
                    '<textarea class="editable-prompt" id="prompt-' + s.id + '" data-scene-id="' + s.id + '" rows="3" placeholder="Enter prompt..." onchange="updatePromptInMemory(' + s.id + ', this.value)">' + promptValue + '</textarea>' +
                    '<div class="prompt-actions">' +
                        '<button class="btn btn-tiny btn-save-prompt" onclick="savePromptToDB(' + s.id + ')" title="Save to Database">💾 Save</button>' +
                        '<span class="prompt-saved" id="saved-' + s.id + '" style="display:none;">✓ Saved</span>' +
                    '</div>' +
                '</div>' +
            '</td>' +
            '<td><div class="prompt-text">' + textPreview + '</div></td>' +
            '<td>' + statusHtml + '</td>' +
            '<td style="white-space:nowrap">' +
                '<button class="btn btn-gen" id="btn-' + s.id + '" onclick="genOne(' + s.id + ', ' + i + ')">🎨 Gen</button> ' +
                '<button class="btn btn-regen" onclick="genOne(' + s.id + ', ' + i + ', true)">🔄</button> ' +
                '<button class="btn btn-edit" onclick="openMediaLib(' + s.id + ')">📁 Edit</button>' +
            '</td>' +
        '</tr>';
    });
    body.innerHTML = html;
}

function L(m) {
    const b = document.getElementById('logBox');
    const ts = new Date().toLocaleTimeString();
    b.value += '[' + ts + '] ' + m + '\n';
    b.scrollTop = b.scrollHeight;
}

function updateProgress() {
    const p = totalGen > 0 ? (doneGen / totalGen * 100) : 0;
    document.getElementById('pf').style.width = p + '%';
    document.getElementById('pt').innerText = doneGen + '/' + totalGen + ' (' + Math.round(p) + '%)';
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

// ---- Media Library Modal ----
let editingSceneId = null, selectedMediaFile = null, selectedMediaType = null;
let activeMediaTab = 'images';
let cachedImages = [], cachedVideos = [];


async function openMediaLib(sceneId) {
    editingSceneId = sceneId;
    selectedMediaFile = null;
    selectedMediaType = null;
    activeMediaTab = 'images';
    cachedImages = [];
    cachedVideos = [];
    window.cachedVideos = []; // Clear global cache too
    
    document.getElementById('editSceneId').innerText = '#' + sceneId;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No file selected';
    document.getElementById('mediaSearchInput').value = '';
    document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#64748b">⏳ Loading images...</div>';
    document.getElementById('mediaLibModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Set active tab
    document.getElementById('tabImages').className = 'media-tab active';
    document.getElementById('tabVideos').className = 'media-tab';
    
    // Load images
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_media_library');
        fd.append('scene_id', sceneId);
        const {data} = await safeFetch(fd);
        cachedImages = data;
        document.getElementById('tabImagesCount').innerText = data.length;
        renderMediaGrid(data);
    } catch(e) {
        document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626">❌ ' + e.message + '</div>';
    }
    
    // Pre-load videos in background
    try {
        const fd2 = new FormData();
        fd2.append('ajax_action', 'get_video_library');
        const response = await fetch('image_gen.php', { method: 'POST', body: fd2 });
        const vdata = await response.json();
        
        cachedVideos = vdata;
        window.cachedVideos = vdata;
        document.getElementById('tabVideosCount').innerText = vdata.length;
        
        console.log("Videos loaded in background:", vdata.length);
    } catch(e) {
        console.error("Background video load error:", e);
        cachedVideos = [];
        window.cachedVideos = [];
        document.getElementById('tabVideosCount').innerText = '0';
    }
}



async function switchMediaTab(tab) {
    activeMediaTab = tab;
    selectedMediaFile = null;
    selectedMediaType = null;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No file selected';
    document.getElementById('mediaSearchInput').value = '';
    
    if (tab === 'images') {
        document.getElementById('tabImages').className = 'media-tab active';
        document.getElementById('tabVideos').className = 'media-tab';
        renderMediaGrid(cachedImages);
    } else {
        document.getElementById('tabImages').className = 'media-tab';
        document.getElementById('tabVideos').className = 'media-tab active';
        
        // Show loading message
        document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#64748b">⏳ Loading videos...</div>';
        
        try {
            // Fetch videos from server
            const fd = new FormData();
            fd.append('ajax_action', 'get_video_library');
            
            const response = await fetch('image_gen.php', { method: 'POST', body: fd });
            const data = await response.json();
            
            // Store in cache
            window.cachedVideos = data;
            cachedVideos = data; // Also store in local variable
            
            // Update the tab count
            document.getElementById('tabVideosCount').innerText = data.length;
            
            // Render the videos
            renderVideoGrid(data);
        } catch (e) {
            console.error("Video load error:", e);
            document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626">❌ Error loading videos</div>';
        }
    }
}


function renderMediaGrid(images) {
    const grid = document.getElementById('mediaGrid');
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">No images found</div>';
        document.getElementById('mediaResultCount').innerText = '0 images';
        return;
    }
    document.getElementById('mediaResultCount').innerText = images.length + ' images';
    
    let html = '';
    images.forEach(function(img) {
        const tags = img.hashtags || '';
        const name = img.image_name;
        const size = img.file_size ? (img.file_size / 1024).toFixed(0) + ' KB' : '';
        html += '<div class="media-item" data-file="' + name + '" data-tags="' + tags + '" data-type="image" onclick="selectMediaItem(this, \'' + name + '\', \'image\')">' +
            '<img src="podcast_images/' + name + '" class="media-preview-img" onerror="this.alt=\'Missing\'" loading="lazy">' +
            '<div class="media-item-info">' +
                '<div class="media-item-name" title="' + name + '">' + name + '</div>' +
                (tags ? '<div class="media-item-tags">🏷️ ' + tags + '</div>' : '') +
                '<div class="media-item-size">' + size + '</div>' +
            '</div>' +
            '<div class="media-check">✓</div>' +
        '</div>';
    });
    grid.innerHTML = html;
}

function renderVideoGrid(videos) {
    const grid = document.getElementById('mediaGrid');
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">No videos found in podcast_videos/ folder</div>';
        document.getElementById('mediaResultCount').innerText = '0 videos';
        return;
    }
    document.getElementById('mediaResultCount').innerText = videos.length + ' videos';
    
    let html = '';
    videos.forEach(function(vid) {
        const name = vid.video_name;
        const size = vid.file_size ? (vid.file_size / (1024 * 1024)).toFixed(1) + ' MB' : '';
        const ext = name.split('.').pop().toUpperCase();
        
        // Add video preview with actual video element
        const videoPath = 'podcast_videos/' + name;
        
        html += '<div class="media-item" data-file="' + name + '" data-tags="" data-type="video" onclick="selectMediaItem(this, \'' + name + '\', \'video\')">' +
            '<div class="video-preview-thumb" style="height:130px; background:#1e293b; display:flex; align-items:center; justify-content:center; overflow:hidden;">' +
                '<video width="100%" height="100%" style="object-fit:cover;" preload="metadata">' +
                    '<source src="' + videoPath + '" type="video/mp4">' +
                    '🎬' +
                '</video>' +
            '</div>' +
            '<div class="media-item-info">' +
                '<div class="media-item-name" title="' + name + '">' + name + '</div>' +
                '<div class="media-item-type">📹 ' + ext + '</div>' +
                '<div class="media-item-size">' + size + '</div>' +
            '</div>' +
            '<div class="media-check">✓</div>' +
        '</div>';
    });
    grid.innerHTML = html;
}
function selectMediaItem(el, fileName, mediaType) {
    document.querySelectorAll('#mediaGrid .media-item').forEach(function(i) { i.classList.remove('selected'); });
    el.classList.add('selected');
    selectedMediaFile = fileName;
    selectedMediaType = mediaType;
    var icon = mediaType === 'video' ? '🎬' : '🖼️';
    document.getElementById('mediaSelInfo').innerHTML = '✅ Selected ' + icon + ': <b>' + fileName + '</b>';
    document.getElementById('mediaSelectBtn').disabled = false;
}

function filterMediaItems() {
    const term = document.getElementById('mediaSearchInput').value.toLowerCase();
    let visible = 0;
    document.querySelectorAll('#mediaGrid .media-item').forEach(function(item) {
        const name = (item.dataset.file || '').toLowerCase();
        const tags = (item.dataset.tags || '').toLowerCase();
        if (name.includes(term) || tags.includes(term)) {
            item.style.display = '';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });
    var label = activeMediaTab === 'videos' ? 'videos' : 'images';
    document.getElementById('mediaResultCount').innerText = visible + ' ' + label;
}

async function confirmMediaSelect() {
    if (!selectedMediaFile || !editingSceneId || !selectedMediaType) return;
    
    var typeLabel = selectedMediaType === 'video' ? 'video' : 'image';
    L('📁 Assigning ' + typeLabel + ' ' + selectedMediaFile + ' to scene #' + editingSceneId);
    
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
        fd.append('prompt', '');
        const {data: d} = await safeFetch(fd);
        if (!d.success) throw new Error(d.message);
        
        if (selectedMediaType === 'image') {
            document.getElementById('img-' + editingSceneId).outerHTML = '<img src="podcast_images/' + selectedMediaFile + '?t=' + Date.now() + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + selectedMediaFile + '\', ' + editingSceneId + ')" id="img-' + editingSceneId + '">';
            document.getElementById('st-' + editingSceneId).className = 'status-badge st-reused';
            document.getElementById('st-' + editingSceneId).innerText = '🖼️ Image Set';
        } else {
            document.getElementById('st-' + editingSceneId).className = 'status-badge st-reused';
            document.getElementById('st-' + editingSceneId).innerText = '🎬 Video Set';
        }
        
        var scene = scenes.find(function(s) { return parseInt(s.id) === editingSceneId; });
        if (scene) {
            if (selectedMediaType === 'image') scene.image_file = selectedMediaFile;
            if (selectedMediaType === 'video') scene.video_file = selectedMediaFile;
        }
        
        L('✅ Scene #' + editingSceneId + ' ' + typeLabel + ' updated with ' + selectedMediaFile);
    } catch(e) {
        L('❌ Update failed: ' + e.message);
        alert('Failed to update: ' + e.message);
    }
    
    closeMediaLib();
}

function closeMediaLib() {
    document.getElementById('mediaLibModal').style.display = 'none';
    document.body.style.overflow = '';
}

async function genOne(sceneId, index, forceNew) {
    const scene = scenes.find(function(s) { return parseInt(s.id) === sceneId; });
    if (!scene) { L('❌ Scene ' + sceneId + ' not found'); return; }
    
    const btn = document.getElementById('btn-' + sceneId);
    const st = document.getElementById('st-' + sceneId);
    btn.disabled = true;
    btn.innerHTML = '⏳ Working...';
    st.className = 'status-badge st-working';
    st.innerText = '🔄 Enhancing...';
    
    // Get the latest prompt from the textarea if it exists
    const textarea = document.getElementById('prompt-' + sceneId);
    let originalPrompt;
    if (textarea) {
        originalPrompt = textarea.value.trim();
        scene.prompt = originalPrompt;
    } else {
        originalPrompt = (scene.prompt && scene.prompt.trim() !== '') ? scene.prompt : scene.text_contents;
    }
    
    if (!originalPrompt || originalPrompt.trim() === '') {
        L('❌ Scene #' + sceneId + ': No prompt or text_contents');
        st.className = 'status-badge st-error';
        st.innerText = '❌ No prompt';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen';
        return;
    }

    L('\n━━━ SCENE #' + sceneId + ' ━━━');
    L('📝 Original prompt: ' + originalPrompt.substring(0, 150) + '...');
    
    // DEBUG: Show current state of all scenes
    console.log('🔍 DEBUG - Current scenes array:', scenes);
    L('🔍 DEBUG - Current scenes:');
    scenes.forEach((s, idx) => {
        L(`   Scene ${idx+1} (ID:${s.id}): image_file="${s.image_file || 'null'}"`);
    });
    
    // Step 1: Enhance prompt
    var enhanced, hashtags;
    try {
        L('🔄 Step 1: Sending to ChatGPT for prompt enhancement...');
        var fd = new FormData();
        fd.append('ajax_action', 'enhance_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', originalPrompt);
        var res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message || 'Unknown error. Raw: ' + res.raw.substring(0, 300));
        enhanced = res.data.enhanced_prompt;
        hashtags = res.data.hashtags;
        L('✅ Enhanced prompt ready (' + enhanced.length + ' chars)');
        L('🏷️ Hashtags: ' + hashtags);
    } catch(e) {
        L('❌ ENHANCE FAILED: ' + e.message);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Error';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen';
        return;
    }
    
    // Step 2: Check for existing image (skip if forceNew)
    st.innerText = '🔍 Checking library...';
    var imageName = null, reused = false;
    
    if (forceNew) {
        L('🔄 Step 2: SKIPPED — Force new image generation');
    } else {
        try {
            // Get list of already used images in this podcast (excluding current scene)
            const usedImages = scenes
                .filter(s => s.image_file && s.image_file.trim() !== '' && parseInt(s.id) !== sceneId)
                .map(s => s.image_file);

            // Remove duplicates from usedImages
            const uniqueUsedImages = [...new Set(usedImages)];

            if (usedImages.length !== uniqueUsedImages.length) {
                L('⚠️ Warning: Duplicate images found in used list!');
                L(`   Original (${usedImages.length}): ${usedImages.join(', ')}`);
                L(`   Unique (${uniqueUsedImages.length}): ${uniqueUsedImages.join(', ')}`);
            }
            
            L('🔍 Step 2: usedImages array = [' + usedImages.join(', ') + ']');
            L('🔍 Step 2: Excluding ' + usedImages.length + ' already used images');
            
            // DEBUG: Log what we're sending to server
            console.log('🔍 Sending to check_image_data:', {
                hashtags: hashtags,
                used_images: usedImages
            });
            const podcastId = currentPodcastId;
            var fd2 = new FormData();
            fd2.append('ajax_action', 'check_image_data');
            fd2.append('hashtags', hashtags);
            fd2.append('used_images', JSON.stringify(uniqueUsedImages));
            fd2.append('podcast_id', podcastId); // Add this line
            
            
            
            console.log('🔍 Sending to check_image_data:', {
                hashtags: hashtags,
                used_images: usedImages,
                used_images_count: usedImages.length,
                used_images_list: usedImages.join(', ')
            });

            var res2 = await safeFetch(fd2);
            console.log('🔍 Response from check_image_data:', res2.data);
                        
            if (res2.data.found) {
                imageName = res2.data.image_name;
                reused = true;
                L('♻️ MATCH FOUND! Reusing: ' + imageName);
                L('🔍 Hashtags of found image: ' + res2.data.image_hashtags);
            } else {
                L('🆕 No match found — will generate new image');
            }
        } catch(e) {
            L('⚠️ Image check error: ' + e.message);
            console.error('Image check error:', e);
        }
    } // end if !forceNew
    
    // Step 3: Generate new image if not found
    if (!imageName) {
        st.innerText = '🎨 Generating image...';
        try {
            L('🎨 Step 3: Calling gpt-image-1 API...');
            L('   📝 Prompt: ' + enhanced.substring(0, 200) + '...');
            L('   🏷️ Hashtags: ' + hashtags);
            var fd3 = new FormData();
            fd3.append('ajax_action', 'generate_image');
            fd3.append('scene_id', sceneId);
            fd3.append('enhanced_prompt', enhanced);
            fd3.append('hashtags', hashtags);
            var res3 = await safeFetch(fd3);
            if (!res3.data.success) throw new Error((res3.data.step ? '[' + res3.data.step + '] ' : '') + (res3.data.message || 'Raw: ' + res3.raw.substring(0, 500)));
            imageName = res3.data.image_name;
            L('   ✅ Image generated: ' + imageName);
            L('   📁 File saved: ' + (res3.data.file_path || 'N/A'));
            L('   📏 File size: ' + (res3.data.file_size ? (res3.data.file_size / 1024).toFixed(1) + ' KB' : 'N/A'));
            if (res3.data.db_inserted) L('   💾 hdb_image_data: INSERT OK ✅');
            else if (res3.data.db_warning) L('   ⚠️ DB WARNING: ' + res3.data.db_warning);
            if (res3.data.table_columns) L('   📋 Columns: ' + res3.data.table_columns.join(', '));
        } catch(e) {
            L('❌ IMAGE GENERATION FAILED: ' + e.message);
            st.className = 'status-badge st-error';
            st.innerText = '❌ Gen Failed';
            btn.disabled = false;
            btn.innerHTML = '🎨 Gen';
            return;
        }
    }
    
    // Step 4: Update scene in DB
    st.innerText = '💾 Saving...';
    try {
        L('💾 Step 4: Updating hdb_podcast_stories with image: ' + imageName);
        var fd4 = new FormData();
        fd4.append('ajax_action', 'update_scene');
        fd4.append('scene_id', sceneId);
        fd4.append('image_file', imageName);
        fd4.append('prompt', enhanced);
        var res4 = await safeFetch(fd4);
        if (!res4.data.success) throw new Error(res4.data.message || 'Raw: ' + res4.raw.substring(0, 300));
        
        document.getElementById('img-' + sceneId).outerHTML = '<img src="podcast_images/' + imageName + '?t=' + Date.now() + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + imageName + '\', ' + sceneId + ')" id="img-' + sceneId + '">';
        
        // Update the prompt in the textarea with the enhanced version
        const promptTextarea = document.getElementById('prompt-' + sceneId);
        if (promptTextarea) {
            promptTextarea.value = enhanced;
        }
        
        st.className = reused ? 'status-badge st-reused' : 'status-badge st-done';
        st.innerText = reused ? '♻️ Reused' : '✅ Generated';
        L('✅ Scene #' + sceneId + ' COMPLETE! ' + (reused ? '(reused)' : '(new image)'));
        
        // Update the scene in the local array
        const sceneIndex = scenes.findIndex(s => parseInt(s.id) === sceneId);
        if (sceneIndex !== -1) {
            scenes[sceneIndex].image_file = imageName;
            scenes[sceneIndex].prompt = enhanced;
        }
        
        // DEBUG: Show updated scenes array
        console.log('🔍 DEBUG - Updated scenes array after save:', scenes);
        L('🔍 DEBUG - After update, scenes now have:');
        scenes.forEach((s, idx) => {
            L(`   Scene ${idx+1} (ID:${s.id}): image_file="${s.image_file || 'null'}"`);
        });
        
        // IMPORTANT: Refresh from server to be absolutely sure
        L('🔄 Refreshing all scene data from server...');
        const refreshFd = new FormData();
        refreshFd.append('ajax_action', 'get_scenes');
        refreshFd.append('podcast_id', currentPodcastId);
        const refreshRes = await safeFetch(refreshFd);
        if (refreshRes.data) {
            scenes = refreshRes.data;
            L('✅ Scene data refreshed, found ' + scenes.length + ' scenes');
            
            // DEBUG: Show refreshed scenes
            console.log('🔍 DEBUG - Refreshed scenes from server:', scenes);
            L('🔍 DEBUG - After refresh, scenes from server:');
            scenes.forEach((s, idx) => {
                L(`   Scene ${idx+1} (ID:${s.id}): image_file="${s.image_file || 'null'}"`);
            });
        }
        
    } catch(e) {
        L('❌ DB UPDATE FAILED: ' + e.message);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Update Failed';
    }
    
    btn.disabled = false;
    btn.innerHTML = '🎨 Gen';
}
// ---- Generate All ----
async function generateAll() {
    const btn = document.getElementById('genAllBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Generating All...';
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('stopBtn').style.display = 'inline-flex';
    
    // ADD THIS: Update internal_status to "visuals"
    try {
        const podcastId = getCurrentPodcastId(); // You'll need this helper function
        const response = await fetch('update_podcast_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                podcast_id: podcastId,
                internal_status: 'visuals'
            })
        });
        const result = await response.json();
        if (result.success) {
            L('📝 Podcast status updated to: visuals');
        }
    } catch (error) {
        console.error('Error updating podcast status:', error);
        L(`⚠️ Status update warning: ${error.message}`);
    }
    // END OF ADDED CODE
    
    const pending = scenes.filter(function(s) { return !s.image_file || s.image_file.trim() === ''; });
    
    if (pending.length === 0) {
        L('✅ All scenes already have images!');
        btn.disabled = false;
        btn.innerHTML = '🚀 Generate All Images';
        return;
    }
    
    totalGen = pending.length;
    doneGen = 0;
    STOP = false;
    updateProgress();
    
    L('\n🚀 BATCH START: ' + pending.length + ' scenes without images');
    
    for (var i = 0; i < pending.length; i++) {
        if (STOP) { L('🛑 STOPPED BY USER'); break; }
        var s = pending[i];
        var idx = scenes.findIndex(function(sc) { return sc.id === s.id; });
        await genOne(parseInt(s.id), idx);
        doneGen++;
        updateProgress();
        if (i < pending.length - 1 && !STOP) {
            L('⏳ Waiting 2s...');
            await new Promise(function(r) { setTimeout(r, 2000); });
        }
    }
    
    L('\n🎉 BATCH COMPLETE: ' + doneGen + '/' + totalGen + (STOP ? ' (stopped early)' : ''));
    btn.disabled = false;
    btn.innerHTML = '🚀 Generate All Images';
    document.getElementById('stopBtn').style.display = 'none';
}

// ADD THIS HELPER FUNCTION (add at the end of your script)
function getCurrentPodcastId() {
    // Try to get podcast ID from URL
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id') || urlParams.get('podcast_id') || urlParams.get('project_id');
    if (id) return id;
    
    // Try from global variable
    if (window.currentPodcastId) return window.currentPodcastId;
    
    // Try from data attribute
    const element = document.getElementById('podcastData');
    if (element && element.dataset.podcastId) return element.dataset.podcastId;
    
    // Default fallback - you might want to handle this differently
    console.warn('Could not determine podcast ID');
    return null;
}
async function fetchLibrary(action) {
    const fd = new FormData();
    fd.append('ajax_action', action);
    
    try {
        const response = await fetch('image_gen.php', { method: 'POST', body: fd });
        const data = await response.json();
        
        const grid = document.getElementById('mediaGrid');
        grid.innerHTML = ''; // Clear current view
        
        if (data.length === 0) {
            grid.innerHTML = '<div style="padding:40px; color:#94a3b8;">No files found in folder.</div>';
            return;
        }

        data.forEach(item => {
            const isVideo = action === 'get_video_library';
            const fileName = isVideo ? item.video_name : item.image_name;
            const displayName = fileName;
            
            // Create the HTML for the item
            const div = document.createElement('div');
            div.className = 'media-item';
            div.onclick = () => selectMediaItem(div, fileName, isVideo ? 'video' : 'image');
            
            if (isVideo) {
                // Video Placeholder with Icon
                div.innerHTML = `
                    <div class="video-preview-thumb">🎬</div>
                    <div class="media-item-info">
                        <div class="media-item-name">${displayName}</div>
                        <div class="media-item-type">VIDEO</div>
                    </div>
                    <div class="media-check">✓</div>
                `;
            } else {
                // Image Preview
                div.innerHTML = `
                    <img src="podcast_images/${fileName}" class="media-preview-img">
                    <div class="media-item-info">
                        <div class="media-item-name">${displayName}</div>
                        <div class="media-item-tags">${item.hashtags || ''}</div>
                    </div>
                    <div class="media-check">✓</div>
                `;
            }
            grid.appendChild(div);
        });

        // Update counts
        if (isVideo) {
            document.getElementById('tabVideosCount').innerText = data.length;
        } else {
            document.getElementById('tabImagesCount').innerText = data.length;
        }

    } catch (e) {
        console.error("Library Load Error:", e);
    }
}
// ---- Keyboard shortcuts ----
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePreview();
        closeMediaLib();
    }
});


// Debug function to check video folder
async function debugVideoFolder() {
    console.log("🔍 DEBUG: Checking video folder...");
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_video_library');
        
        const response = await fetch('image_gen.php', { method: 'POST', body: fd });
        const data = await response.json();
        
        console.log("📊 Response data:", data);
        console.log("📊 Number of videos found:", data.length);
        
        if (data.length > 0) {
            console.log("📊 First video:", data[0]);
        }
        
        // Show alert with info
        alert(`Found ${data.length} videos in podcast_videos/ folder\n\nCheck console for details (F12)`);
        
        // Update the tab count
        document.getElementById('tabVideosCount').innerText = data.length;
        
        // Force refresh the video grid if on videos tab
        if (activeMediaTab === 'videos') {
            renderVideoGrid(data);
        }
        
    } catch(e) {
        console.error("❌ Debug error:", e);
        alert("Error: " + e.message);
    }
}

// Close media modal on backdrop click
document.getElementById('mediaLibModal').addEventListener('click', function(e) {
    if (e.target === this) closeMediaLib();
});
</script>
</body>
</html>
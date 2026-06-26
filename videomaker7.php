<?php
// 1. Database & Config and making
require_once 'check_session.php';
session_start();

$admin_id    = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id   = $_SESSION['client_id'];

if(!isset($_SESSION['admin_id']))
{
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit; 
} 
  
include 'dbconnect_hdb.php'; 
require_once 'chatgpt_functions.php';
require_once 'generate_image_api.php';

ini_set('error_log', __DIR__ . '/a_errors.log'); // Server errors
$podcast_id    = $_GET['podcast_id'] ?? 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

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


// Get user plan for free trial check
$user_query = mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
$user_row = mysqli_fetch_assoc($user_query);
$plan_type = $user_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// Get podcast_id from URL if present
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$url_lang_filter = isset($_GET['lang_filter']) ? $_GET['lang_filter'] : 'en';
$video_type = 'standard'; // default
if ($url_podcast_id > 0) {
    $type_query = mysqli_query($conn, "SELECT video_type, lang_code FROM hdb_podcasts WHERE id = $url_podcast_id");
    if ($type_query && mysqli_num_rows($type_query) > 0) {
        $type_row = mysqli_fetch_assoc($type_query);
        $podcast_lang_code  = $type_row['lang_code'] ?? 'en';
        $lang_code =  $podcast_lang_code;
		$video_type = $type_row['video_type'] ?? 'standard';
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

// Fetch podcast title from DB
$podcast_title = '';
$podcast_music = '';
if ($podcast_id > 0) {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM hdb_podcasts WHERE id = " . (int)$podcast_id));
    $podcast_title = $row['title'] ?? '';
	$podcast_music = $row['music_file'] ?? '';
} else {
    $podcast_title = '';
}

// Fetch all scenes for this podcast
// Fetch all scenes for this podcast - INCLUDING HASHTAGS
$scenes = [];
if ($url_podcast_id > 0) {
    $scenes_query = mysqli_query($conn, "SELECT *, hashtags FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id ORDER BY id");
    if ($scenes_query) {
        while ($row = mysqli_fetch_assoc($scenes_query)) {
            $scenes[] = $row;
        }
    }
}
// ---------- AJAX: Upload Image ----------


// Get all audio files for this podcast
$audio_files = [];
if ($url_podcast_id > 0) {
    $audio_query = mysqli_query($conn, "SELECT id, audio_file FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id AND audio_file IS NOT NULL AND audio_file != ''");
    if ($audio_query) {
        while ($row = mysqli_fetch_assoc($audio_query)) {
            $audio_files[$row['id']] = $row['audio_file'];
        }
    } 
}

// ---------- AJAX: Upload Podcast Music ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_podcast_music') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $podcast_id = (int)$_POST['podcast_id'];
    $response = ['success' => false, 'message' => ''];
    
    // Check if file was uploaded
    if (!isset($_FILES['music_file']) || $_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = isset($_FILES['music_file']) ? $_FILES['music_file']['error'] : 'No file uploaded';
        $response['message'] = 'Upload error: ' . $error_msg;
        echo json_encode($response);
        exit;
    }
    
    $file = $_FILES['music_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate file type (only MP3 for music)
    if ($ext !== 'mp3') {
        $response['message'] = 'Only MP3 files are allowed for background music';
        echo json_encode($response);
        exit;
    }
    
    // Validate file size (max 20MB for music)
    if ($file['size'] > 20 * 1024 * 1024) {
        $response['message'] = 'File too large. Max 20MB';
        echo json_encode($response);
        exit;
    }
    
    // Use podcast_music folder
    $music_dir = __DIR__ . '/podcast_music/';
    if (!is_dir($music_dir)) {
        mkdir($music_dir, 0777, true);
    }
    
    // Generate unique filename
    $filename = 'music_' . $podcast_id . '_' . time() . '.mp3';
    $destination = $music_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Update the podcast with the music file
        $update_sql = "UPDATE hdb_podcasts SET music_file='$filename' WHERE id=$podcast_id";
        if (mysqli_query($conn, $update_sql)) {
            $response['success'] = true;
            $response['message'] = 'Music uploaded successfully';
            $response['filename'] = $filename;
            $response['file_url'] = 'podcast_music/' . $filename;
        } else {
            $response['message'] = 'Database update failed: ' . mysqli_error($conn);
        }
    } else {
        $response['message'] = 'Failed to move uploaded file';
    }
    
    echo json_encode($response);
    exit;
}
// Create new caption
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_caption') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    // Log the request for debugging
    error_log("create_caption called with: " . print_r($_POST, true));
    
    $story_id = (int)$_POST['story_id'];
    $podcast_id = (int)$_POST['podcast_id']; // ADD THIS LINE - get podcast_id from POST
    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']); // 'text' or 'image'
    $caption_name = mysqli_real_escape_string($conn, $_POST['caption_name'] ?? '');
    
    // If caption_name is empty, generate one with datetime
    if (empty($caption_name)) {
        $caption_name = $caption_type . '_' . date('Ymd_His');
    }
    
    // Validate caption_type - only allow 'text' or 'image'
    if (!in_array($caption_type, ['text', 'image'])) {
        $caption_type = 'text'; // Default to text if invalid
    }
    $media_type = ($caption_type === 'image') ? 'image' : $caption_type;
    
    // Default settings
    $default_fontfamily = 'Arial';
    $default_fontsize = 30;
    $default_fontcolor = '#ffff00';
    $default_fontcolor_bg = '#000000';
    $default_fontweight = 'bold';
    $default_fontbg_enable = 0;
    
    // Set default text based on caption type
    $default_text = ($caption_type === 'text') ? 'Enter new text here' : '';
    
    // UPDATED SQL to include podcast_id
    $sql = "INSERT INTO hdb_captions 
            (story_id, podcast_id, caption_type, caption_name, text_content, media_type, fontfamily, fontsize, fontcolor, 
             fontweight, fontstyle, text_align, bg_color, bg_enabled, position_x, position_y, width, 
             animation_style, animation_speed, is_visible, z_index)
            VALUES 
            ($story_id, $podcast_id, '$caption_type', '$caption_name', '$default_text', '$caption_type',
             '$default_fontfamily', $default_fontsize, '$default_fontcolor',
             '$default_fontweight', 'normal', 'center', '$default_fontcolor_bg', $default_fontbg_enable,
             50, 200, 500, 'none', 1.0, 1, 1)";
    
    error_log("Create caption SQL: " . $sql);
    
    if (mysqli_query($conn, $sql)) {
        $new_id = mysqli_insert_id($conn);
        error_log("Caption created with ID: " . $new_id);
        
        // Get the newly created caption to return
        $new_caption_query = mysqli_query($conn, "SELECT * FROM hdb_captions WHERE id = $new_id");
        $new_caption = mysqli_fetch_assoc($new_caption_query);
        
        echo json_encode([
            'success' => true, 
            'caption_id' => $new_id,
            'caption_type' => $caption_type,
            'caption_name' => $caption_name,
            'caption_data' => $new_caption,
            'message' => 'Caption created successfully'
        ]);
    } else {
        error_log("Caption creation failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// ---------- AJAX: Save Caption Global Status ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_caption_global') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $caption_id = (int)$_POST['caption_id'];
    $is_global = (int)$_POST['is_global'];
    
    $sql = "UPDATE hdb_captions SET is_global = $is_global WHERE id = $caption_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Delete Caption By Type ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_caption_by_type') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $story_id = (int)$_POST['story_id'];
    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']);
    
    $sql = "DELETE FROM hdb_captions WHERE story_id = $story_id AND caption_type = '$caption_type'";
    
    if (mysqli_query($conn, $sql)) {
        $deleted = mysqli_affected_rows($conn);
        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// ---------- AJAX: Save All Scene Settings ----------
// In your save_scene_settings AJAX handler, add text_contents to the fields
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_scene_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    
    // Sanitize and prepare all fields
    $fontfamily = mysqli_real_escape_string($conn, $_POST['fontfamily'] ?? 'Arial');
    $fontsize = (int)($_POST['fontsize'] ?? 16);
    $fontcolor = mysqli_real_escape_string($conn, $_POST['fontcolor'] ?? '#ffffff');
    $fontweight = mysqli_real_escape_string($conn, $_POST['fontweight'] ?? 'normal');
    $fontcolor_bg = mysqli_real_escape_string($conn, $_POST['fontcolor_bg'] ?? '#000000');
    $fontbg_enable = (int)($_POST['fontbg_enable'] ?? 1);
    $caption_style = mysqli_real_escape_string($conn, $_POST['caption_style'] ?? 'typewriter');
    $caption_position = mysqli_real_escape_string($conn, $_POST['caption_position'] ?? 'bottom');
    $caption_alignment = mysqli_real_escape_string($conn, $_POST['caption_alignment'] ?? 'center');
    $caption_speed = (float)($_POST['caption_speed'] ?? 0.85);
    $logo_name = mysqli_real_escape_string($conn, $_POST['logo_name'] ?? '');
    $logo_size = mysqli_real_escape_string($conn, $_POST['logo_size'] ?? '60');
    $logo_position = mysqli_real_escape_string($conn, $_POST['logo_position'] ?? 'top-right');
    $logo_enabled = (int)($_POST['logo_enabled'] ?? 0);
    $audio_file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');
    $audio_volume = (int)($_POST['audio_volume'] ?? 100);
    $voice_id = mysqli_real_escape_string($conn, $_POST['voice_id'] ?? '');
    $text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? ''); // ADD THIS LINE
    
    // Build the SQL query
    $sql = "UPDATE hdb_podcast_stories SET 
            fontfamily = '$fontfamily',
            fontsize = $fontsize,
            fontcolor = '$fontcolor',
            fontweight = '$fontweight',
            fontcolor_bg = '$fontcolor_bg',
            fontbg_enable = $fontbg_enable,
            caption_style = '$caption_style',
            caption_position = '$caption_position',
            caption_alignment = '$caption_alignment',
            caption_speed = $caption_speed,
            logo_name = '$logo_name',
            logo_size = '$logo_size',
            logo_position = '$logo_position',
            logo_enabled = $logo_enabled,
            audio_file = '$audio_file',
            audio_volume = $audio_volume";
            
    
    // Add text_contents to update if provided
    if (!empty($text_contents)) {
        $sql .= ", text_contents = '$text_contents'";
    }
    
    $sql .= " WHERE id = $scene_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}							

// Get all captions for a scene
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scene_captions') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $story_id = (int)$_POST['scene_id'];
    
    $sql = "SELECT * FROM hdb_captions WHERE story_id = $story_id";
    $result = mysqli_query($conn, $sql);
    
    $captions = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $captions[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'captions' => $captions
    ]);
    exit;
}
// ---------- AJAX: Update Main Caption Styling Only (NO TEXT) ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_main_caption_styling') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $caption_id = (int)$_POST['caption_id'];
    
    // ALLOWED FIELDS FOR MAIN CAPTION - NO text_content!
    $allowed_fields = [
        'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 'fontstyle',
        'underline', 'linethrough', 'text_align', 'bg_color', 'bg_enabled',
        'outline_color', 'outline_width', 'outline_enabled',
        'stroke_color', 'stroke_width', 'stroke_enabled',
        'position_x', 'position_y', 'width', 'rotation',
        'animation_style', 'animation_speed', 'is_visible', 'z_index'
    ];
    
    $updates = [];
    foreach ($allowed_fields as $field) {
        if (isset($_POST[$field])) {
            $value = $_POST[$field];
            if ($value === '') {
                $value = 'NULL';
            } else {
                $value = "'" . mysqli_real_escape_string($conn, $value) . "'";
            }
            $updates[] = "$field = $value";
        }
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit;
    }
    
    $sql = "UPDATE hdb_captions SET " . implode(', ', $updates) . " WHERE id = $caption_id";
    
    error_log("Main caption styling update: $sql");
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// Save a caption
// Update caption by ID
// Save a caption
// Update caption by ID
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_caption') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $caption_id = (int)$_POST['caption_id'];
    
    $allowed_fields = [
        'text_content', 'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 'fontstyle',
        'underline', 'linethrough', 'text_align', 'bg_color', 'bg_enabled',
        'outline_color', 'outline_width', 'outline_enabled',
        'stroke_color', 'stroke_width', 'stroke_enabled',
        'position_x', 'position_y', 'width', 'rotation',
        'animation_style', 'animation_speed', 'is_visible', 'z_index',
        'image_file', 'media_type'  // MAKE SURE THESE ARE INCLUDED
    ];
    
    $updates = [];
    foreach ($allowed_fields as $field) {
        if (isset($_POST[$field])) {
            $value = $_POST[$field];
            if ($value === '') {
                $value = 'NULL';
            } else {
                $value = "'" . mysqli_real_escape_string($conn, $value) . "'";
            }
            $updates[] = "$field = $value";
        }
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit;
    }
    
    $sql = "UPDATE hdb_captions SET " . implode(', ', $updates) . " WHERE id = $caption_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_image') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Check if file was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error');
        }
        
        $file = $_FILES['image'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WEBP');
        }
        
        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File too large. Max 10MB');
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        $image_name = $image_name_base . '.' . $extension;
        
        $upload_dir = __DIR__ . '/podcast_images/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $destination = $upload_dir . $image_name;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Failed to save uploaded file');
        }
        
        // Insert into database
        $esc_name = mysqli_real_escape_string($conn, $image_name);
        $esc_prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');
        
        // Check table structure
        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
        $table_cols = [];
        while ($c = mysqli_fetch_assoc($col_check)) {
            $table_cols[] = $c['Field'];
        }
        
        $insert_map = [];
        if (in_array('image_name', $table_cols)) {
            $insert_map['image_name'] = "'$esc_name'";
        }
        if (in_array('created_at', $table_cols)) {
            $insert_map['created_at'] = "NOW()";
        }
        if (in_array('image_prompt', $table_cols) && !empty($esc_prompt)) {
            $insert_map['image_prompt'] = "'$esc_prompt'";
        }
        
        if (!empty($insert_map)) {
            $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";
            mysqli_query($conn, $ins_sql);
        }
        
        $response['success'] = true;
        $response['image_name'] = $image_name;
        $response['message'] = 'File uploaded successfully';
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}
// ---------- AJAX: Save Scene Text ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_scene_text') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? '');
    
    $sql = "UPDATE hdb_podcast_stories SET text_contents = '$text_contents' WHERE id = $scene_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// ---------- AJAX: Get Translate Languages ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_translate_languages') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $exclude = mysqli_real_escape_string($conn, $_POST['exclude_lang'] ?? '');
    
    // Check if hdb_languages table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_languages'");
    if (mysqli_num_rows($table_check) == 0) {
        // Table doesn't exist - return default languages
        $default_languages = [
            ['lang_code' => 'ur', 'lang_name' => 'Urdu'],
            ['lang_code' => 'ar', 'lang_name' => 'Arabic'],
            ['lang_code' => 'hi', 'lang_name' => 'Hindi'],
            ['lang_code' => 'fr', 'lang_name' => 'French'],
            ['lang_code' => 'es', 'lang_name' => 'Spanish'],
            ['lang_code' => 'de', 'lang_name' => 'German'],
            ['lang_code' => 'zh', 'lang_name' => 'Chinese'],
            ['lang_code' => 'ja', 'lang_name' => 'Japanese'],
            ['lang_code' => 'ko', 'lang_name' => 'Korean'],
            ['lang_code' => 'ru', 'lang_name' => 'Russian'],
            ['lang_code' => 'pt', 'lang_name' => 'Portuguese'],
            ['lang_code' => 'it', 'lang_name' => 'Italian'],
            ['lang_code' => 'nl', 'lang_name' => 'Dutch'],
            ['lang_code' => 'tr', 'lang_name' => 'Turkish'],
            ['lang_code' => 'pl', 'lang_name' => 'Polish']
        ];
        
        // Filter out excluded language
        if ($exclude) {
            $default_languages = array_filter($default_languages, function($lang) use ($exclude) {
                return $lang['lang_code'] !== $exclude;
            });
            $default_languages = array_values($default_languages);
        }
        
        echo json_encode([
            'success' => true,
            'languages' => $default_languages
        ]);
        exit;
    }
    
    $sql = "SELECT lang_code, lang_name FROM hdb_languages WHERE is_active = 1";
    if ($exclude) {
        $sql .= " AND lang_code != '$exclude'";
    }
    $sql .= " ORDER BY lang_name";
    
    $result = mysqli_query($conn, $sql);
    $languages = [];
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $languages[] = $row;
        }
    } else {
        // Fallback languages if table is empty
        $languages = [
            ['lang_code' => 'ur', 'lang_name' => 'Urdu'],
            ['lang_code' => 'ar', 'lang_name' => 'Arabic'],
            ['lang_code' => 'hi', 'lang_name' => 'Hindi'],
            ['lang_code' => 'fr', 'lang_name' => 'French'],
            ['lang_code' => 'es', 'lang_name' => 'Spanish'],
            ['lang_code' => 'de', 'lang_name' => 'German'],
            ['lang_code' => 'zh', 'lang_name' => 'Chinese']
        ];
        
        // Filter out excluded language
        if ($exclude) {
            $languages = array_filter($languages, function($lang) use ($exclude) {
                return $lang['lang_code'] !== $exclude;
            });
            $languages = array_values($languages);
        }
    }
    
    echo json_encode([
        'success' => true,
        'languages' => $languages
    ]);
    exit;
}

// ---------- AJAX: Save Rendered Video ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_rendered_video') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Get the video data
        $video_data = $_POST['video_data'] ?? '';
        $filename = $_POST['filename'] ?? '';
        
        if (empty($video_data) || empty($filename)) {
            throw new Exception('Missing video data or filename');
        }
        
        // Remove the data URL prefix (e.g., "data:video/mp4;base64,")
        $video_data = preg_replace('/^data:video\/\w+;base64,/', '', $video_data);
        $video_data = base64_decode($video_data);
        
        if ($video_data === false) {
            throw new Exception('Failed to decode video data');
        }
        
        // Create published_videos directory if it doesn't exist
        $video_dir = __DIR__ . '/published_videos/';
        if (!is_dir($video_dir)) {
            mkdir($video_dir, 0777, true);
        }
        
        // Delete any existing file with the same name
        $filepath = $video_dir . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
            error_log("Deleted previous render: $filename");
        }
        
        // Save the new video
        if (file_put_contents($filepath, $video_data) !== false) {
            $response['success'] = true;
            $response['message'] = 'Video saved successfully';
            $response['filename'] = $filename;
            $response['filepath'] = 'published_videos/' . $filename;
            $response['filesize'] = filesize($filepath);
            
            error_log("Video saved: $filename (" . filesize($filepath) . " bytes)");
        } else {
            throw new Exception('Failed to save video file');
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Video save error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// ---------- AJAX: Delete Previous Render ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_previous_render') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $filename = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');
    $response = ['success' => false, 'deleted' => false];
    
    if ($filename) {
        $video_dir = __DIR__ . '/published_videos/';
        $filepath = $video_dir . $filename;
        
        if (file_exists($filepath)) {
            if (unlink($filepath)) {
                $response['success'] = true;
                $response['deleted'] = true;
                error_log("Deleted previous render: $filename");
            } else {
                error_log("Failed to delete: $filename");
            }
        } else {
            $response['success'] = true; // File doesn't exist, consider it success
            $response['deleted'] = false;
        }
    }
    
    echo json_encode($response);
    exit;
}




// ---------- AJAX: Delete Video File ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_video_file') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $filename = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');
    $response = ['success' => false];
    
    if ($filename) {
        $video_dir = __DIR__ . '/podcast_videos/';
        $filepath = $video_dir . $filename;
        
        if (file_exists($filepath)) {
            if (unlink($filepath)) {
                $response['success'] = true;
                error_log("Deleted previous render file: $filename");
            } else {
                error_log("Failed to delete: $filename");
            }
        } else {
            $response['success'] = true; // File doesn't exist, consider it success
        }
    }
    
    echo json_encode($response);
    exit;
}

// ---------- AJAX: Delete Caption ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_caption') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $caption_id = (int)$_POST['caption_id'];
    
    $sql = "DELETE FROM hdb_captions WHERE id = $caption_id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// ---------- AJAX: Get Voices by Language ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_voices_by_language') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $lang_code = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? '');
    
    if (!$lang_code) {
        echo json_encode(['success' => false, 'message' => 'No language code provided']);
        exit;
    }
    
    // Check if hdb_voices table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_voices'");
    if (mysqli_num_rows($table_check) == 0) {
        // Return default voices based on language
        $default_voices = getDefaultVoices($lang_code);
        echo json_encode([
            'success' => true,
            'voices' => $default_voices
        ]);
        exit;
    }
    
    $sql = "SELECT voice_key, voice_name, voice_description, sample_voice 
            FROM hdb_voices 
            WHERE lang_code = '$lang_code' 
            ORDER BY voice_name";
    
    $result = mysqli_query($conn, $sql);
    $voices = [];
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $voices[] = $row;
        }
    } else {
        // Return default voices if none found
        $voices = getDefaultVoices($lang_code);
    }
    
    echo json_encode([
        'success' => true,
        'voices' => $voices
    ]);
    exit;
}

// ---------- AJAX: Check if Language Exists ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_language_exists') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $lang_code = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? '');
    
    $sql = "SELECT id FROM hdb_podcasts 
            WHERE title = '$title' 
            AND lang_code = '$lang_code' 
            AND client_id = '$client_id'";
    
    $result = mysqli_query($conn, $sql);
    
    echo json_encode([
        'success' => true,
        'exists' => ($result && mysqli_num_rows($result) > 0)
    ]);
    exit;
}

// ---------- AJAX: Get Podcast Scenes ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_podcast_scenes') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $podcast_id = (int)$_POST['podcast_id'];
    
    $sql = "SELECT id, text_contents FROM hdb_podcast_stories WHERE podcast_id = $podcast_id ORDER BY id";
    $result = mysqli_query($conn, $sql);
    $scenes = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $scenes[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'scenes' => $scenes
    ]);
    exit;
}

// Helper function to get default voices
function getDefaultVoices($lang_code) {
    $default_voices = [
        'ur' => [
            ['voice_key' => 'ur-PK-AsadNeural', 'voice_name' => 'Asad', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'ur-PK-UzmaNeural', 'voice_name' => 'Uzma', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'ar' => [
            ['voice_key' => 'ar-SA-HamedNeural', 'voice_name' => 'Hamed', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'ar-SA-ZariyahNeural', 'voice_name' => 'Zariyah', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'hi' => [
            ['voice_key' => 'hi-IN-MadhurNeural', 'voice_name' => 'Madhur', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'hi-IN-SwaraNeural', 'voice_name' => 'Swara', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'fr' => [
            ['voice_key' => 'fr-FR-HenriNeural', 'voice_name' => 'Henri', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'fr-FR-DeniseNeural', 'voice_name' => 'Denise', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'es' => [
            ['voice_key' => 'es-ES-AlvaroNeural', 'voice_name' => 'Alvaro', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'es-ES-ElviraNeural', 'voice_name' => 'Elvira', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'de' => [
            ['voice_key' => 'de-DE-ConradNeural', 'voice_name' => 'Conrad', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'de-DE-KatjaNeural', 'voice_name' => 'Katja', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'zh' => [
            ['voice_key' => 'zh-CN-YunxiNeural', 'voice_name' => 'Yunxi', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'zh-CN-XiaoxiaoNeural', 'voice_name' => 'Xiaoxiao', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'ja' => [
            ['voice_key' => 'ja-JP-KeitaNeural', 'voice_name' => 'Keita', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'ja-JP-NanamiNeural', 'voice_name' => 'Nanami', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'ko' => [
            ['voice_key' => 'ko-KR-InJoonNeural', 'voice_name' => 'InJoon', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'ko-KR-SunHiNeural', 'voice_name' => 'SunHi', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ],
        'ru' => [
            ['voice_key' => 'ru-RU-DmitryNeural', 'voice_name' => 'Dmitry', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
            ['voice_key' => 'ru-RU-DariyaNeural', 'voice_name' => 'Dariya', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
        ]
    ];
    
    return $default_voices[$lang_code] ?? [
        ['voice_key' => 'en-US-GuyNeural', 'voice_name' => 'Guy', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],
        ['voice_key' => 'en-US-JennyNeural', 'voice_name' => 'Jenny', 'voice_description' => 'Female, Natural', 'sample_voice' => '']
    ];
}


// ---------- AJAX: Get Music Library ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_music_library') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $music_dir = __DIR__ . '/podcast_music/';
    $music_files = [];
    
    if (is_dir($music_dir)) {
        $files = scandir($music_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'mp3') {
                $filepath = $music_dir . $file;
                $fsize = filesize($filepath);
                
                // Try to extract ID3 tags
                $title = pathinfo($file, PATHINFO_FILENAME);
                $artist = 'Unknown';
                $duration = 0;
                
                // Use getID3 if available (recommended)
                if (file_exists(__DIR__ . '/getID3/getid3.php')) {
                    require_once __DIR__ . '/getID3/getid3.php';
                    $getID3 = new getID3;
                    $fileInfo = $getID3->analyze($filepath);
                    
                    if (isset($fileInfo['tags']['id3v2']['title'][0])) {
                        $title = $fileInfo['tags']['id3v2']['title'][0];
                    }
                    if (isset($fileInfo['tags']['id3v2']['artist'][0])) {
                        $artist = $fileInfo['tags']['id3v2']['artist'][0];
                    }
                    if (isset($fileInfo['playtime_seconds'])) {
                        $duration = $fileInfo['playtime_seconds'];
                    }
                } else {
                    // Fallback: try to get duration using ffprobe if available
                    $ffprobe_path = 'ffprobe'; // or full path to ffprobe
                    $cmd = "$ffprobe_path -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filepath) . " 2>&1";
                    $output = shell_exec($cmd);
                    if ($output && is_numeric(trim($output))) {
                        $duration = floatval(trim($output));
                    }
                }
                
                $music_files[] = [
                    'filename' => $file,
                    'title' => $title,
                    'artist' => $artist,
                    'duration' => $duration,
                    'filesize' => $fsize,
                    'file_url' => 'podcast_music/' . $file
                ];
            }
        }
        
        // Sort by filename
        usort($music_files, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });
    }
    
    echo json_encode([
        'success' => true,
        'music_files' => $music_files
    ]);
    exit;
}
// ---------- AJAX: Check Caption Type Exists ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_caption_type_exists') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $story_id = (int)$_POST['story_id'];
    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']);
    
    $check = mysqli_query($conn, "SELECT id FROM hdb_captions WHERE story_id = $story_id AND caption_type = '$caption_type'");
    
    echo json_encode([
        'success' => true,
        'exists' => (mysqli_num_rows($check) > 0)
    ]);
    exit;
}
// ---------- AJAX: Update Podcast Music ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_podcast_music') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $podcast_id = (int)$_POST['podcast_id'];
    $music_file = mysqli_real_escape_string($conn, $_POST['music_file'] ?? '');
    
    $sql = "UPDATE hdb_podcasts SET music_file='$music_file' WHERE id=$podcast_id";
    
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
    
    $log_file = __DIR__ . '/a_inam_debug.log';
    error_log(date('Y-m-d H:i:s') . " - GET_MEDIA_LIBRARY STARTED\n", 3, $log_file);
    
    $image_dir = __DIR__ . '/podcast_images/';
    $images = [];
    
    // Check if directory exists
    if (!is_dir($image_dir)) {
        error_log(date('Y-m-d H:i:s') . " - ERROR: Directory not found: $image_dir\n", 3, $log_file);
        echo json_encode(['error' => 'Image directory not found']);
        exit;
    }
    
    // Get image data from database
    $db_images = [];
    $r = mysqli_query($conn, "SELECT image_name, image_hashtags FROM hdb_image_data ORDER BY id DESC");
    
    if (!$r) {
        error_log(date('Y-m-d H:i:s') . " - DB Query failed: " . mysqli_error($conn) . "\n", 3, $log_file);
    } else {
        $db_count = mysqli_num_rows($r);
        error_log(date('Y-m-d H:i:s') . " - DB query returned $db_count rows\n", 3, $log_file);
        
        while ($row = mysqli_fetch_assoc($r)) {
            $db_images[$row['image_name']] = $row['image_hashtags'] ?? '';
        }
        
        error_log(date('Y-m-d H:i:s') . " - DB images loaded: " . count($db_images) . "\n", 3, $log_file);
        
        // Log first 5 DB entries as sample
        $sample = array_slice($db_images, 0, 5, true);
        foreach ($sample as $name => $tags) {
            error_log(date('Y-m-d H:i:s') . " - DB Sample: $name -> '$tags'\n", 3, $log_file);
        }
    }
    
    // Scan directory
    $files = scandir($image_dir);
    error_log(date('Y-m-d H:i:s') . " - Directory scanned, found " . count($files) . " files\n", 3, $log_file);
    
    $image_count = 0;
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        // Check if it's an image file
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            continue;
        }
        
        $image_count++;
        $filepath = $image_dir . $file;
        $file_size = file_exists($filepath) ? filesize($filepath) : 0;
        
        $hashtags = $db_images[$file] ?? '';
        
        // Log first 5 images with their hashtags
        if ($image_count <= 5) {
            error_log(date('Y-m-d H:i:s') . " - Image $image_count: $file - hashtags: '$hashtags'\n", 3, $log_file);
        }
        
        $img_data = [
            'image_name' => $file,
            'hashtags' => $hashtags,
            'file_size' => $file_size
        ];
        
        $images[] = $img_data;
    }
    
    error_log(date('Y-m-d H:i:s') . " - Total images found: $image_count\n", 3, $log_file);
    error_log(date('Y-m-d H:i:s') . " - Returning " . count($images) . " images in JSON\n", 3, $log_file);
    
    // Log the first few images being returned
    if (count($images) > 0) {
        $json_sample = array_slice($images, 0, 3);
        error_log(date('Y-m-d H:i:s') . " - Sample JSON data: " . json_encode($json_sample) . "\n", 3, $log_file);
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

// ---------- AJAX: Get Video Library ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_library') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $video_dir = __DIR__ . '/podcast_videos/';
    $videos = [];
    
    if (is_dir($video_dir)) {
        $files = scandir($video_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4','webm','mov','avi','mkv','m4v'])) {
                $fsize = @filesize($video_dir . $file);
                $videos[] = [
                    'video_name' => $file,
                    'file_size' => $fsize ? $fsize : 0
                ];
            }
        }
    }
    
    echo json_encode($videos);
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
// ---------- AJAX: Upload Image for Stickers (Visual Boxes) ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_sticker_image') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => '', 'debug' => []];
    $response['debug']['time'] = date('Y-m-d H:i:s');
    
    try {
        // Get podcast_id and scene_id
        $podcast_id = $url_podcast_id; // From your existing PHP variable
        $scene_id = isset($_POST['scene_id']) ? (int)$_POST['scene_id'] : 0;
        
        if (!$scene_id) {
            throw new Exception('Scene ID is required');
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = isset($_FILES['image']) ? $_FILES['image']['error'] : 'No file uploaded';
            $response['debug']['error'] = $error_msg;
            throw new Exception('Upload error: ' . $error_msg);
        }
        
        $file = $_FILES['image'];
        $response['debug']['original_name'] = $file['name'];
        $response['debug']['file_size'] = $file['size'];
        $response['debug']['file_type'] = $file['type'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WEBP');
        }
        
        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File too large. Max 10MB');
        }
        
        // Get file extension
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        // Generate filename in format: image_podcast_id_scene_id_timestamp.extension
        $timestamp = time();
        $image_name = "image_{$podcast_id}_{$scene_id}_{$timestamp}.{$extension}";
        
        // Use podcast_stickers folder
        $upload_dir = __DIR__ . '/podcast_stickers/';
        $response['debug']['upload_dir'] = $upload_dir;
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('Failed to create podcast_stickers directory');
            }
        }
        
        // Check if directory is writable
        if (!is_writable($upload_dir)) {
            throw new Exception('podcast_stickers directory is not writable');
        }
        
        $destination = $upload_dir . $image_name;
        $response['debug']['destination'] = $destination;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        $response['debug']['file_saved'] = true;
        $response['debug']['file_exists'] = file_exists($destination);
        $response['debug']['file_size_saved'] = filesize($destination);
        
        // Insert into database (using hdb_image_data table)
        $esc_name = mysqli_real_escape_string($conn, $image_name);
        
        // Check table structure
        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
        if (!$col_check) {
            $response['debug']['db_error'] = 'Could not check table structure';
        } else {
            $table_cols = [];
            while ($c = mysqli_fetch_assoc($col_check)) {
                $table_cols[] = $c['Field'];
            }
            $response['debug']['table_columns'] = $table_cols;
            
            $insert_map = [];
            if (in_array('image_name', $table_cols)) {
                $insert_map['image_name'] = "'$esc_name'";
            }
            if (in_array('created_at', $table_cols)) {
                $insert_map['created_at'] = "NOW()";
            }
            if (in_array('podcast_id', $table_cols)) {
                $insert_map['podcast_id'] = $podcast_id;
            }
            if (in_array('scene_id', $table_cols)) {
                $insert_map['scene_id'] = $scene_id;
            }
            
            if (!empty($insert_map)) {
                $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";
                $response['debug']['insert_sql'] = $ins_sql;
                
                if (mysqli_query($conn, $ins_sql)) {
                    $response['debug']['db_inserted'] = true;
                    $response['debug']['insert_id'] = mysqli_insert_id($conn);
                } else {
                    $response['debug']['db_insert_error'] = mysqli_error($conn);
                }
            }
        }
        
        $response['success'] = true;
        $response['image_name'] = $image_name;
        $response['message'] = 'Sticker uploaded successfully to podcast_stickers/';
        
        // Log success
        error_log(date('Y-m-d H:i:s') . " - Sticker uploaded: $image_name to podcast_stickers/\n", 3, __DIR__ . '/sticker_upload.log');
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log(date('Y-m-d H:i:s') . " - Sticker upload error: " . $e->getMessage() . "\n", 3, __DIR__ . '/sticker_upload.log');
    }
    
    echo json_encode($response);
    exit;
}

// ---------- AJAX: Get Stickers from podcast_stickers folder ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_stickers') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $sticker_dir = __DIR__ . '/podcast_stickers/';
    $stickers = [];
    
    // Check if directory exists
    if (!is_dir($sticker_dir)) {
        echo json_encode([]);
        exit;
    }
    
    // Get image data from database
    $db_images = [];
    $r = mysqli_query($conn, "SELECT image_name, image_hashtags FROM hdb_image_data ORDER BY id DESC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $db_images[$row['image_name']] = $row['image_hashtags'] ?? '';
        }
    }
    
    // Scan directory
    $files = scandir($sticker_dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        // Check if it's an image file
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            continue;
        }
        
        $filepath = $sticker_dir . $file;
        $file_size = file_exists($filepath) ? filesize($filepath) : 0;
        
        // Try to extract podcast_id and scene_id from filename if it matches pattern
        $podcast_id = null;
        $scene_id = null;
        if (preg_match('/image_(\d+)_(\d+)_/', $file, $matches)) {
            $podcast_id = $matches[1];
            $scene_id = $matches[2];
        }
        
        $hashtags = $db_images[$file] ?? '';
        
        $img_data = [
            'image_name' => $file,
            'hashtags' => $hashtags,
            'file_size' => $file_size,
            'podcast_id' => $podcast_id,
            'scene_id' => $scene_id,
            'folder' => 'podcast_stickers'
        ];
        
        $stickers[] = $img_data;
    }
    
    // Sort by newest first (assuming timestamp at end of filename)
    usort($stickers, function($a, $b) {
        return strcmp($b['image_name'], $a['image_name']);
    });
    
    echo json_encode($stickers);
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
// ---------- AJAX: Update Scene Row ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');  
    $scene_id = (int)$_POST['scene_id'];
    $image_field = $_POST['image_field'] ?? 'image_file'; // Get which field to update
    $image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? '');
    $video_file = mysqli_real_escape_string($conn, $_POST['video_file'] ?? '');
    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');
    $media_type = $_POST['media_type'] ?? 'image';
    
    // Map image field to corresponding prompt field
    $prompt_field = 'prompt';
    if ($image_field === 'image_file_1') $prompt_field = 'prompt_1';
    else if ($image_field === 'image_file_2') $prompt_field = 'prompt_2';
    else if ($image_field === 'image_file_3') $prompt_field = 'prompt_3';
    else if ($image_field === 'image_file_4') $prompt_field = 'prompt_4';
    else if ($image_field === 'image_file_5') $prompt_field = 'prompt_5';
    
    if ($media_type === 'video' && !empty($video_file)) {
        $sql = "UPDATE hdb_podcast_stories SET video_file='$video_file' WHERE id=$scene_id";
    } else {
        // Update the specific image field and its corresponding prompt
        $sql = "UPDATE hdb_podcast_stories SET 
                $image_field = '$image_file'";
        
        // Add prompt update if provided
        if (!empty($prompt)) {
            $sql .= ", $prompt_field = '$prompt'";
        }
        
        $sql .= " WHERE id = $scene_id";
    }
    
    error_log("Update Scene SQL: $sql");
    
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
    $prompt_field = $_POST['prompt_field'] ?? 'prompt'; // Get which prompt field to update
    
    // Validate prompt field to prevent SQL injection
    $allowed_prompt_fields = ['prompt', 'prompt_1', 'prompt_2', 'prompt_3', 'prompt_4', 'prompt_5'];
    if (!in_array($prompt_field, $allowed_prompt_fields)) {
        $prompt_field = 'prompt';
    }
    
    $sql = "UPDATE hdb_podcast_stories SET $prompt_field='$prompt' WHERE id=$scene_id";
    
    error_log("Save Prompt SQL: $sql");
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}



?>
<!DOCTYPE html>
<html lang="en"> 
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>VideoVizard - Render Your Masterpiece</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
	<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="videomaker-styles.css">
	
	<style>
    /* Fabric upper canvas must receive mouse events */
    .canvas-container canvas.upper-canvas {
        pointer-events: auto !important;
    }
    /* Icon bar containers transparent to mouse — individual icons stay clickable */
    .primary-icons,
    .secondary-icons {
        pointer-events: none !important;
    }
    .primary-icons .overlay-icon,
    .secondary-icons .overlay-icon {
        pointer-events: auto !important;
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

<!-- Top Navigation Buttons 
<div class="top-nav">
    <a href="vidora_home.php" class="nav-btn home">
        <span>🏠</span> Home
    </a>
    <a href="image_gen.php?podcast_id=<?=$podcast_id;?>" class="nav-btn visuals">
        <span>🖼️</span> Visuals
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
-->
<div class="container">
    <!-- Info Card -->
    <div class="info-card">
        <h1>✨ Render Your Masterpiece 1</h1>
        <p>Fine-tune your captions, typography, and branding. Click the icons on the canvas for scene-specific or global settings.</p>
    </div>


	
   
    <div class="info-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
     
			
		
		<!-- Floating Action Menu - Above Canvas -->
		<div class="floating-action-menu">
		<h4 style="margin: 0;">Project: <?= htmlspecialchars($podcast_title ?: 'Untitled Project') ?></h4>
    <!-- Left Section 
			<div class="menu-left">
			<button class="menu-btn" onclick="toggleSceneSettings('typography')" title="Typography Settings">
				<span>🅰️</span>
				<span class="btn-label">Text</span>
			</button>
			<button class="menu-btn" onclick="toggleSceneSettings('images')" title="Image Settings">
				<span>🌄</span>
				<span class="btn-label">Images</span>
			</button>
			<button class="menu-btn" onclick="toggleSceneSettings('audio')" title="Audio Settings">
				<span>🔊</span>
				<span class="btn-label">Audio</span>
			</button>
			</div>
			-->
		</div>

</div>
 <!-- Main Canvas Card -->
		<div class="info-card canvas-wrapper">
    
       

        <!-- Scene-specific Settings Panel (appears when overlay icon clicked) -->
				<!-- Scene-specific Settings Panel (appears when overlay icon clicked) -->
		<div id="sceneSettingsPanel" class="settings-panel"> 
			
			
			<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REPLACES BOTH INDIVIDUAL PANELS -->
		<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REDESIGNED -->
			<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REDESIGNED -->
			
			
			
			
			
			
			
			

			<!-- Hidden audio element for voice samples -->
			<audio id="sampleAudioPlayer" style="display:none;"></audio>

			<!-- Hidden file input for music upload -->
			<input type="file" id="musicUploadInput" accept=".mp3,audio/mpeg" style="display:none;">

			<!-- Save/Cancel Buttons - ALWAYS VISIBLE 
			<div class="panel-actions">
				<button class="panel-btn save" onclick="saveSceneSettings()">💾 Save to This Scene</button>
				<button class="panel-btn save" onclick="saveToAllScenes()" style="background: var(--info);">💾 Save to All Scenes</button>
			</div>
			-->
		</div>
		
		<div class="canvas-container" id="canvasContainer">
		 
			<!-- Three-dot Menu at Top-Right -->
			
			
			<!-- Action Menu Dropdown -->
			<div class="action-menu" id="actionMenu">
				
				<div class="action-menu-item" onclick="playPreview()">
					<span>▶️</span> Preview
				</div>
				<div class="action-menu-item" onclick="startRecording()">
					<span>⏺️</span> Record
				</div>
				<div class="action-menu-item" onclick="scheduleVideo()">
					<span>📅</span> Schedule
				</div>
				<?php if ($podcast_lang_code === 'en'): ?>
				<div class="action-menu-item" onclick="TranslateVideo()">
					<span>🌐</span> Translate
				</div>
				   <div class="action-menu-item" onclick="archiveProject()">
					<span>📦</span> Archive
					</div>
					<div class="action-menu-item" onclick="deleteProject()" style="color: #dc2626;">
						<span>🗑️</span> Delete
					</div>
				<?php endif; ?>
				<div class="action-menu-item" onclick="gobackProjects()">
					<span>🏠</span> Go Back to Projects
				</div>
			</div>
			
			<!-- Fabric.js Canvas -->
			<canvas id="fabricCanvas" style="display: block;"></canvas>

<!-- Floating Stop Button — shown only during preview or recording -->
<div id="floatingStopBtn" onclick="handleStopBtn()" style="
    display: none;
    position: absolute;
    top: 12px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99999;
    background: #dc2626;
    color: white;
    border-radius: 30px;
    padding: 10px 24px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(0,0,0,0.35);
    user-select: none;
">⏹ Stop</div>

<!-- Hidden image for background -->
<img id="hiddenBackgroundImage" style="display: none;">

<!-- Video element for background video -->
<video id="hiddenBackgroundVideo" style="display: none;" muted loop playsinline></video>
    <div class="nav-arrows">
        <div class="nav-arrow" onclick="navigateScene('prev')">←</div>
        <div class="scene-indicator" id="sceneIndicator">1aa/<?= count($scenes) ?: 0 ?></div>
        <div class="nav-arrow" onclick="navigateScene('next')">→</div>
    </div>
<!-- Icon Container - Single container for all icons -->
<div class="icon-container">
    <!-- Primary Icons (visible by default) -->
    <div class="primary-icons" id="primaryIcons">
        <div class="overlay-icon" onclick="showTypographyIcons()" title="Typography & Caption Settings">🅰️</div>
        <div class="overlay-icon" onclick="showImageSelectorOverlay(event)" title="Image Selection">🌄</div>
          <div class="overlay-icon" onclick="showAudioIcons()" title="Audio Settings">🔊</div>
        <div class="overlay-icon" onclick="playPreview()" title="Preview">▶️</div>
		<div class="overlay-icon" onclick="startRecording()" title="Record">⏺️</div>
		  <div class="overlay-icon" onclick="toggleActionMenu(event)" title="More Options">⋮</div>
    </div> 
    
    <!-- Secondary Icons - Text Settings (hidden by default) inam-->
   <!-- Secondary Icons - Text Settings (hidden by default) -->
<div class="secondary-icons" id="textIcons" style="display: none;">
    <!-- Back button -->
    <div class="overlay-icon back-icon" onclick="hideTextIcons()" title="Back">←</div>
    
    <!-- Style tools -->
    <div class="overlay-icon" onclick="showFontFamilyPanel()" title="Font Family">🔤</div>
	<div class="overlay-icon" onclick="showFontSizeOptions()" title="Font Size">📏</div>
    <div class="overlay-icon" onclick="showTextColorOptions()" title="Text Color">🎨</div>
    <div class="overlay-icon" onclick="showBgColorOptions(event)" title="Background Color">🖌️</div>
    <div class="overlay-icon" onclick="showStyleOptions()" title="Text Style">✒️</div>
    <div class="overlay-icon" onclick="showAlignment()" title="Alignment">⬅️</div>
    <div class="overlay-icon" onclick="showEffects()" title="Text Effects">✨</div>
    <div class="overlay-icon" onclick="showPosition()" title="Position">📍</div>
    <div class="overlay-icon" onclick="showAnimation()" title="Animation">⚡</div>
    
    <!-- NEW IMAGE CAPTION ICON - Add this line -->
    <div class="overlay-icon" onclick="showImageSourceOverlay(event)" title="Add Image as Caption" style="background: var(--success); color: white;">➕🖼️</div>
    
    
    <!-- Add Caption and Delete Caption -->
    <div class="overlay-icon" onclick="addNewCaption()" title="Add New Text Caption" style="background: var(--success); color: white;">➕</div>
    <div class="overlay-icon" id="deleteIconSecondary" onclick="deleteSelectedCaption()" title="Delete Selected Caption" style="background: #dc2626; color: white;">🗑️</div>
</div>
</div>
			
<!-- Secondary Icons - Typography (Hidden by default) -->


<!-- Overlay Panels - Appear when secondary icons are clicked -->
<!-- Font Family Panel - Updated to match other overlays -->
<div class="overlay-panel" id="fontFamilyPanel" style="display: none; width: 280px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('fontFamilyPanel')">←</button>
        <span>🔤 Font Family</span>
    </div>
    <div class="overlay-content" style="padding: 12px;">
        <div class="font-option" onclick="setFontFamily('Inter')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; margin-bottom: 2px;">Inter</div>
        <div class="font-option" onclick="setFontFamily('Arial')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: Arial, sans-serif; margin-bottom: 2px;">Arial</div>
        <div class="font-option" onclick="setFontFamily('Helvetica')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: Helvetica, sans-serif; margin-bottom: 2px;">Helvetica</div>
        <div class="font-option" onclick="setFontFamily('Times New Roman')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Times New Roman', serif; margin-bottom: 2px;">Times New Roman</div>
        <div class="font-option" onclick="setFontFamily('Roboto')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Roboto', sans-serif; margin-bottom: 2px;">Roboto</div>
        <div class="font-option" onclick="setFontFamily('Montserrat')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Montserrat', sans-serif; margin-bottom: 2px;">Montserrat</div>
        <div class="font-option" onclick="setFontFamily('Poppins')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Poppins', sans-serif; margin-bottom: 2px;">Poppins</div>
        <div class="font-option" onclick="setFontFamily('Open Sans')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Open Sans', sans-serif; margin-bottom: 2px;">Open Sans</div>
    </div>
</div>

<!-- Font Size Panel - Matching font family style -->
<div class="overlay-panel" id="fontSizePanel" style="display: none; width: 280px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('fontSizePanel')">←</button>
        <span>📏 Font Size</span>
    </div>
    <div class="overlay-content" style="padding: 20px;">
        <!-- Size display and stepper -->
        <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 60px; padding: 8px; border: 2px solid #e2e8f0; margin-bottom: 20px;">
            <button class="step-btn" onclick="decreaseFontSize()" style="width: 48px; height: 48px; border-radius: 50%; border: none; background: white; color: #0f2a44; font-size: 24px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">−</button>
            <div style="display: flex; align-items: baseline; gap: 4px; background: white; padding: 8px 24px; border-radius: 40px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">
                <span id="fontSizeNumber" style="font-size: 36px; font-weight: 700; color: #0f2a44; min-width: 50px; text-align: center;">36</span>
                <span style="font-size: 16px; color: #64748b;">px</span>
            </div>
            <button class="step-btn" onclick="increaseFontSize()" style="width: 48px; height: 48px; border-radius: 50%; border: none; background: white; color: #0f2a44; font-size: 24px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">+</button>
        </div>
        
        <!-- Slider -->
        <input type="range" id="fontSizeSlider" min="8" max="120" value="36" style="width: 100%; height: 6px; -webkit-appearance: none; background: linear-gradient(90deg, #8b5cf6, #ec4899); border-radius: 3px; margin-bottom: 20px;" oninput="handleFontSizeSlider(this.value)">
        
        <!-- Size presets -->
        <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px;">
            <button class="size-preset" onclick="setFontSizeValue(8)">8</button>
            <button class="size-preset" onclick="setFontSizeValue(10)">10</button>
            <button class="size-preset" onclick="setFontSizeValue(12)">12</button>
            <button class="size-preset" onclick="setFontSizeValue(14)">14</button>
            <button class="size-preset" onclick="setFontSizeValue(16)">16</button>
            <button class="size-preset" onclick="setFontSizeValue(18)">18</button>
            <button class="size-preset" onclick="setFontSizeValue(20)">20</button>
            <button class="size-preset" onclick="setFontSizeValue(24)">24</button>
            <button class="size-preset" onclick="setFontSizeValue(28)">28</button>
            <button class="size-preset" onclick="setFontSizeValue(32)">32</button>
            <button class="size-preset" onclick="setFontSizeValue(36)">36</button>
            <button class="size-preset" onclick="setFontSizeValue(42)">42</button>
            <button class="size-preset" onclick="setFontSizeValue(48)">48</button>
            <button class="size-preset" onclick="setFontSizeValue(56)">56</button>
            <button class="size-preset" onclick="setFontSizeValue(64)">64</button>
            <button class="size-preset" onclick="setFontSizeValue(72)">72</button>
            <button class="size-preset" onclick="setFontSizeValue(96)">96</button>
            <button class="size-preset" onclick="setFontSizeValue(120)">120</button>
        </div>
    </div>
</div>

<div class="overlay-panel" id="textColorPanel" style="display: none;">
 
    <div class="overlay-content">
        <input type="color" id="textColorPicker" value="#ffffff" class="color-picker" oninput="previewTextColor(this.value)">
        <div class="color-presets">
            <div class="color-swatch" style="background: #ffffff;" onclick="setTextColor('#ffffff')"></div>
            <div class="color-swatch" style="background: #ffff00;" onclick="setTextColor('#ffff00')"></div>
            <div class="color-swatch" style="background: #ff0000;" onclick="setTextColor('#ff0000')"></div>
            <div class="color-swatch" style="background: #00ff00;" onclick="setTextColor('#00ff00')"></div>
            <div class="color-swatch" style="background: #0000ff;" onclick="setTextColor('#0000ff')"></div>
            <div class="color-swatch" style="background: #ff00ff;" onclick="setTextColor('#ff00ff')"></div>
            <div class="color-swatch" style="background: #00ffff;" onclick="setTextColor('#00ffff')"></div>
            <div class="color-swatch" style="background: #000000;" onclick="setTextColor('#000000')"></div>
            <div class="color-swatch" style="background: #ffa500;" onclick="setTextColor('#ffa500')"></div>
            <div class="color-swatch" style="background: #800080;" onclick="setTextColor('#800080')"></div>
        </div>
    </div>
</div>






<!-- Animation Panel - Enhanced with more options -->
<div class="overlay-panel" id="animationPanel" style="display: none; width: 320px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('animationPanel')">←</button>
        <span>⚡ Animation & Display</span>
    </div>
    <div class="overlay-content" style="padding: 15px; max-height: 500px; overflow-y: auto;">
        
        <!-- DISPLAY MODE SECTION -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="font-size: 20px;">📄</span>
                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Text Display Mode</span>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                <div class="animation-option" onclick="setDisplayMode('full')" id="displayFull" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 20px; margin-bottom: 4px;">📃</div>
                    <div style="font-size: 11px; font-weight: 600;">Full Text</div>
                    <div style="font-size: 9px; color: #64748b;">All at once</div>
                </div>
                
                <div class="animation-option" onclick="setDisplayMode('word')" id="displayWord" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 20px; margin-bottom: 4px;">🔤</div>
                    <div style="font-size: 11px; font-weight: 600;">Word by Word</div>
                    <div style="font-size: 9px; color: #64748b;">Reveal words</div>
                </div>
                
                <div class="animation-option" onclick="setDisplayMode('line')" id="displayLine" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 20px; margin-bottom: 4px;">📏</div>
                    <div style="font-size: 11px; font-weight: 600;">Line by Line</div>
                    <div style="font-size: 9px; color: #64748b;">Split at \n</div>
                </div>
                
                <div class="animation-option" onclick="setDisplayMode('character')" id="displayChar" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 20px; margin-bottom: 4px;">🔡</div>
                    <div style="font-size: 11px; font-weight: 600;">Character</div>
                    <div style="font-size: 9px; color: #64748b;">Letter by letter</div>
                </div>
            </div>
            
            <div style="margin-top: 10px; padding: 8px; background: #f1f5f9; border-radius: 12px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 6px;">
                <span style="background: #0f2a44; color: white; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px;">i</span>
                <span>Use \n in text to create line breaks</span>
            </div>
        </div>
        
        <!-- ANIMATION STYLE SECTION -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="font-size: 20px;">🎬</span>
                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Animation Style</span>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                <!-- Row 1 -->
                <div class="anim-btn" onclick="setAnimationStyle('typewriter')" id="animTypewriter" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">📝</div>
                    <div style="font-size: 10px; font-weight: 600;">Typewriter</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('wordReveal')" id="animWordReveal" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">🔤</div>
                    <div style="font-size: 10px; font-weight: 600;">Word Reveal</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('fade')" id="animFade" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">🌫️</div>
                    <div style="font-size: 10px; font-weight: 600;">Fade In</div>
                </div>
                
                <!-- Row 2 -->
                <div class="anim-btn" onclick="setAnimationStyle('slideUp')" id="animSlideUp" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">⬆️</div>
                    <div style="font-size: 10px; font-weight: 600;">Slide Up</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('zoom')" id="animZoom" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">🔍</div>
                    <div style="font-size: 10px; font-weight: 600;">Zoom In</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('pop')" id="animPop" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">💥</div>
                    <div style="font-size: 10px; font-weight: 600;">Pop</div>
                </div>
                
                <!-- Row 3 -->
                <div class="anim-btn" onclick="setAnimationStyle('bounce')" id="animBounce" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">🏀</div>
                    <div style="font-size: 10px; font-weight: 600;">Bounce</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('karaoke')" id="animKaraoke" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">🎤</div>
                    <div style="font-size: 10px; font-weight: 600;">Karaoke</div>
                </div>
                
                <div class="anim-btn" onclick="setAnimationStyle('static')" id="animStatic" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">
                    <div style="font-size: 22px;">⏸️</div>
                    <div style="font-size: 10px; font-weight: 600;">Static</div>
                </div>
            </div>
        </div>
        
        <!-- ANIMATION SPEED CONTROL -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="font-size: 20px;">⏱️</span>
                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Animation Speed</span>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
                <span style="color: #4CAF50; font-size: 11px;">Slow</span>
                <input type="range" id="animSpeedSlider" min="0.3" max="3" step="0.1" value="1" style="flex: 1; height: 6px; -webkit-appearance: none; background: linear-gradient(90deg, #4CAF50, #f44336); border-radius: 3px;" oninput="previewAnimSpeed(this.value)">
                <span style="color: #f44336; font-size: 11px;">Fast</span>
            </div>
            
            <div style="display: flex; justify-content: center; margin-top: 10px;">
                <span id="animSpeedDisplay" style="background: #0f2a44; color: white; padding: 5px 20px; border-radius: 30px; font-size: 14px; font-weight: 700;">1.0x</span>
            </div>
        </div>
        
        <!-- PREVIEW NOTE -->
        <div style="padding: 8px; background: #f1f5f9; border-radius: 30px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">
            <span style="background: var(--info); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>
            <span>Animation plays when previewing/rendering video</span>
        </div>
        
    </div>
</div>


<!-- Font Family Selector Overlay (hidden by default) -->

<div class="font-selector-overlay" id="fontSelectorOverlay" style="display: none;">
    <div class="font-selector-panel">
        <!-- Just the picker card, no preview -->
        <div class="picker-card">
            <div class="search-wrap">
                <div class="search-inner">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="2.5" stroke-linecap="round">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input class="search-input" id="fontSearchInput" placeholder="Search fonts…" autocomplete="off">
                    <button class="search-clear" id="fontSearchClear">×</button>
                </div>
            </div>

            <div class="font-list" id="fontListContainer"></div>
            <div class="no-results" id="noResults">No fonts found</div>

            <div class="picker-footer">
                <span class="footer-count" id="footerCount">20 fonts</span>
                <span class="footer-selected" id="footerSelected">Bebas Neue</span>
            </div>
        </div>
    </div>
</div>

<!-- Font Size Picker Overlay -->
<div class="font-size-overlay" id="fontSizeOverlay" style="display: none;">
    <div class="picker-card">
        <div class="card-header">
            <span class="back-icon-small" onclick="closeFontSizePicker()">←</span>
            Font Size
        </div>
        <div class="card-body">
            <div class="size-stepper">
                <button class="step-btn" id="sizeDown">−</button>
                <div class="size-display">
                    <span class="size-number" id="sizeNumber">36</span>
                    <span class="size-unit">px</span>
                </div>
                <button class="step-btn" id="sizeUp">+</button>
            </div>
            <input class="size-slider" type="range" id="sizeSlider" min="8" max="120" value="36">
            <div class="size-presets" id="sizePresets"></div>
        </div>
    </div>
</div>

<!-- Font Color Picker Overlay -->
<div class="font-color-overlay" id="fontColorOverlay" style="display: none;">
    <div class="picker-card">
        <div class="card-header">
            <span class="back-icon-small" onclick="closeFontColorPicker()">←</span>
            Font Color
        </div>
        <div class="card-body">
            <!-- Swatches -->
            <div class="color-swatches" id="colorSwatches"></div>
            
            <div class="divider"></div>
            
            <!-- Hex + preview -->
            <div class="hex-row">
                <div class="hex-preview" id="hexPreview"></div>
                <input class="hex-input" id="hexInput" type="text" placeholder="#000000" maxlength="7">
            </div>
            
            <!-- Opacity -->
            <div class="opacity-row">
                <span class="opacity-label">Opacity</span>
                <input class="opacity-slider" type="range" id="opacitySlider" min="0" max="100" value="100">
                <span class="opacity-value" id="opacityValue">100%</span>
            </div>
        </div>
    </div>
</div>



<!-- Background Color Panel - Matching font family style -->
<div class="overlay-panel" id="bgColorPanel" style="display: none; width: 280px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('bgColorPanel')">←</button>
        <span>🖌️ Background Color</span>
    </div>
    <div class="overlay-content" style="padding: 20px;">
        <!-- Color picker -->
        <input type="color" id="bgColorPicker" value="#000000" style="width: 100%; height: 50px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; margin-bottom: 15px;" oninput="previewBgColor(this.value)">
        
        <!-- Color presets grid -->
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px;">
            <div style="background-color: #FF3B30; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF3B30')" title="Red"></div>
            <div style="background-color: #FF9500; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF9500')" title="Orange"></div>
            <div style="background-color: #FFCC00; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FFCC00')" title="Yellow"></div>
            <div style="background-color: #34C759; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#34C759')" title="Green"></div>
            <div style="background-color: #00C7BE; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#00C7BE')" title="Teal"></div>
            
            <div style="background-color: #007AFF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#007AFF')" title="Blue"></div>
            <div style="background-color: #5856D6; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#5856D6')" title="Purple"></div>
            <div style="background-color: #AF52DE; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#AF52DE')" title="Lavender"></div>
            <div style="background-color: #FF2D55; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF2D55')" title="Pink"></div>
            <div style="background-color: #FF6B6B; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF6B6B')" title="Coral"></div>
            
            <div style="background-color: #A8E6CF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#A8E6CF')" title="Mint"></div>
            <div style="background-color: #3D5A80; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#3D5A80')" title="Navy"></div>
            <div style="background-color: #E07A5F; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#E07A5F')" title="Terracotta"></div>
            <div style="background-color: #000000; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#000000')" title="Black"></div>
            <div style="background-color: #FFFFFF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FFFFFF')" title="White"></div>
        </div>
        
        <!-- Background Enable Checkbox -->
        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 8px; background: #f8fafc; border-radius: 30px;">
                <input type="checkbox" id="enableBgCheckbox" onchange="toggleBgEnable(this.checked)" style="width: 18px; height: 18px; cursor: pointer; margin-left: 5px;"> 
                <span style="font-size: 14px; font-weight: 500;">Enable Background Color</span>
                <span style="font-size: 16px; margin-left: auto; margin-right: 5px;">🟦</span>
            </label>
        </div>
    </div>
</div>

<!-- Style Overlay - For Bold, Italic, Underline -->
<!-- Style Overlay - For Bold, Italic, Underline - IMPROVED DESIGN -->
<!-- Style Overlay - Compact with icons only -->
<div class="overlay-panel" id="stylePanel" style="display: none;">
   <div class="overlay-header">
		<button class="overlay-close" onclick="closeOverlay('stylePanel')">←</button>
		<span>✒️ Text Style</span> 
	</div>
    <div class="overlay-content" style="padding: 15px;">
        
        <!-- Style options in a row - icons only -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
            
            <!-- Bold -->
            <div class="style-button" onclick="toggleTextStyle('bold')" id="styleBoldBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝐁</div>
                <div id="styleBoldIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
            <!-- Italic -->
            <div class="style-button" onclick="toggleTextStyle('italic')" id="styleItalicBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; font-style: italic; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝐼</div>
                <div id="styleItalicIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
            <!-- Underline -->
            <div class="style-button" onclick="toggleTextStyle('underline')" id="styleUnderlineBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; text-decoration: underline; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝑈</div>
                <div id="styleUnderlineIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
        </div>
        
        <!-- Compact indicator -->
        <div style="margin-top: 12px; padding: 6px; background: #f1f5f9; border-radius: 20px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">
            <span style="background: var(--success); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>
            <span>Active style</span>
        </div>
        
    </div>
</div>

<!-- Alignment Overlay - Compact with 4 icons in one row -->
<!-- Alignment Overlay - Matching the style overlay design -->
<!-- Alignment Overlay - Compact with icons only -->
<div class="overlay-panel" id="alignmentPanel" style="display: none;">
    <div class="overlay-header">
		<button class="overlay-close" onclick="closeOverlay('alignmentPanel')">←</button>
		<span>⬅️ Text Alignment</span>
	</div>
    <div class="overlay-content" style="padding: 15px;">
        
        <!-- Alignment options in a 2x2 grid - icons only, more compact -->
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
            
            <!-- Left Align -->
            <div class="align-button" onclick="setTextAlignment('left')" id="alignLeft" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="width: 30px; display: flex; flex-direction: column; align-items: flex-start; gap: 5px;">
                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>
                    </div>
                </div>
                <div class="align-indicator" id="alignLeftIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
            <!-- Center Align -->
            <div class="align-button" onclick="setTextAlignment('center')" id="alignCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="width: 30px; display: flex; flex-direction: column; align-items: center; gap: 5px;">
                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>
                    </div>
                </div>
                <div class="align-indicator" id="alignCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
            <!-- Right Align -->
            <div class="align-button" onclick="setTextAlignment('right')" id="alignRight" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="width: 30px; display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>
                    </div>
                </div>
                <div class="align-indicator" id="alignRightIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
            <!-- Justify -->
            <div class="align-button" onclick="setTextAlignment('justify')" id="alignJustify" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="width: 30px; display: flex; flex-direction: column; align-items: stretch; gap: 5px;">
                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>
                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>
                    </div>
                </div>
                <div class="align-indicator" id="alignJustifyIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
            </div>
            
        </div>
        
        <!-- Compact indicator -->
        <div style="margin-top: 12px; padding: 6px; background: #f1f5f9; border-radius: 20px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">
            <span style="background: var(--success); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>
            <span>Active alignment</span>
        </div>
        
    </div>
</div>

<!-- Effects Overlay - Enhanced with Shadow, Glow, Gradient, Outline, Stroke, and 3D -->
<div class="overlay-panel" id="effectsPanel" style="display: none; width: 320px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('effectsPanel')">←</button>
        <span>✨ Text Effects</span>
    </div>
    <div class="overlay-content" style="padding: 15px; max-height: 500px; overflow-y: auto;">
        
        <!-- SHADOW EFFECT -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">🌑</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Shadow</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="shadowEnable" onchange="toggleEffect('shadow')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- Shadow Controls (hidden by default) -->
            <div id="shadowControls" style="display: none;">
                <!-- Shadow Color -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>
                    <input type="color" id="shadowColor" value="#000000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateShadow()">
                </div>
                
                <!-- Shadow Blur -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">BLUR</div>
                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                        <input type="range" id="shadowBlur" min="0" max="50" value="10" style="flex: 1;" oninput="updateShadow()">
                        <span id="shadowBlurValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">10</span>
                    </div>
                </div>
                
                <!-- Shadow Offset X -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">OFFSET X</div>
                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                        <input type="range" id="shadowOffsetX" min="-20" max="20" value="5" style="flex: 1;" oninput="updateShadow()">
                        <span id="shadowOffsetXValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">5</span>
                    </div>
                </div>
                
                <!-- Shadow Offset Y -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">OFFSET Y</div>
                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                        <input type="range" id="shadowOffsetY" min="-20" max="20" value="5" style="flex: 1;" oninput="updateShadow()">
                        <span id="shadowOffsetYValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">5</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GLOW EFFECT (Outer Glow) -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">✨</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Glow</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="glowEnable" onchange="toggleEffect('glow')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- Glow Controls (hidden by default) -->
            <div id="glowControls" style="display: none;">
                <!-- Glow Color -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>
                    <input type="color" id="glowColor" value="#ffff00" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGlow()">
                </div>
                
                <!-- Glow Blur -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">INTENSITY</div>
                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                        <input type="range" id="glowBlur" min="1" max="30" value="15" style="flex: 1;" oninput="updateGlow()">
                        <span id="glowBlurValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">15</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- OUTLINE EFFECT -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">🔲</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Outline</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="outlineEnable" onchange="toggleEffect('outline')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- Outline Controls -->
            <div id="outlineControls" style="display: none;">
                <div style="display: flex; gap: 15px; align-items: center; margin-top: 10px;">
                    <!-- Color Picker -->
                    <div style="flex: 1;">
                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>
                        <input type="color" id="outlineColor" value="#000000" style="width: 100%; height: 45px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateOutline()">
                    </div>
                    
                    <!-- Width Slider -->
                    <div style="flex: 1;">
                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">WIDTH</div>
                        <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                            <input type="range" id="outlineWidth" min="1" max="10" value="2" style="flex: 1;" oninput="updateOutline()">
                            <span id="outlineWidthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">2</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- STROKE EFFECT (Inner/Outer with different paint order) -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">✏️</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Stroke</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="strokeEnable" onchange="toggleEffect('stroke')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- Stroke Controls -->
            <div id="strokeControls" style="display: none;">
                <!-- Stroke Position -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">POSITION</div>
                    <select id="strokePosition" onchange="updateStroke()" style="width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 30px; background: white;">
                        <option value="fill">Outside (paint fill first)</option>
                        <option value="stroke">Inside (paint stroke first)</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 15px; align-items: center;">
                    <!-- Color Picker -->
                    <div style="flex: 1;">
                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>
                        <input type="color" id="strokeColor" value="#ffffff" style="width: 100%; height: 45px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateStroke()">
                    </div>
                    
                    <!-- Width Slider -->
                    <div style="flex: 1;">
                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">WIDTH</div>
                        <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                            <input type="range" id="strokeWidth" min="1" max="10" value="2" style="flex: 1;" oninput="updateStroke()">
                            <span id="strokeWidthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">2</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GRADIENT EFFECT -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">🌈</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Gradient</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="gradientEnable" onchange="toggleEffect('gradient')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- Gradient Controls -->
            <div id="gradientControls" style="display: none;">
                <!-- Color 1 -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR 1</div>
                    <input type="color" id="gradientColor1" value="#ff0000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGradient()">
                </div>
                
                <!-- Color 2 -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR 2</div>
                    <input type="color" id="gradientColor2" value="#0000ff" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGradient()">
                </div>
                
                <!-- Gradient Direction -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DIRECTION</div>
                    <select id="gradientDirection" onchange="updateGradient()" style="width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 30px; background: white;">
                        <option value="left-to-right">→ Left to Right</option>
                        <option value="right-to-left">← Right to Left</option>
                        <option value="top-to-bottom">↓ Top to Bottom</option>
                        <option value="bottom-to-top">↑ Bottom to Top</option>
                        <option value="diagonal">↘ Diagonal</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- 3D EFFECT (using multiple shadows) -->
        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <!-- Header with icon and checkbox -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">🎲</span>
                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">3D Effect</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" id="effect3DEnable" onchange="toggleEffect('3d')" style="width: 16px; height: 16px; cursor: pointer;">
                    <span style="font-size: 12px; font-weight: 500;">Enable</span>
                </label>
            </div>
            
            <!-- 3D Controls -->
            <div id="effect3DControls" style="display: none;">
                <!-- 3D Color -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DEPTH COLOR</div>
                    <input type="color" id="effect3DColor" value="#000000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateEffect3D()">
                </div>
                
                <!-- 3D Depth -->
                <div style="margin-bottom: 12px;">
                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DEPTH</div>
                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">
                        <input type="range" id="effect3DDepth" min="1" max="20" value="8" style="flex: 1;" oninput="updateEffect3D()">
                        <span id="effect3DDepthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">8</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Active Effects Status -->
        <div id="activeEffects" style="margin-top: 10px; padding: 10px; background: #f1f5f9; border-radius: 30px; font-size: 12px; color: #475569; display: flex; align-items: center; gap: 8px; justify-content: center; min-height: 40px;">
            <span>✨ No effects active</span>
        </div>
        
    </div>
</div>

<!-- Position Overlay - For vertical and horizontal positioning -->
<div class="overlay-panel" id="positionPanel" style="display: none;">
    <div class="overlay-header">
		<button class="overlay-close" onclick="closeOverlay('positionPanel')">←</button>
		<span>📍 Position</span>
	</div>
    <div class="overlay-content" style="padding: 15px;">
        
        <!-- Vertical Positioning (Top, Center, Bottom) -->
        <div style="margin-bottom: 20px;">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">
                <span>⬆️⬇️</span> VERTICAL POSITION
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                
                <!-- Top -->
                <div class="position-button" onclick="setVerticalPosition('top')" id="posTop" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">⬆️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Top</span>
                    <div class="pos-indicator" id="posTopIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
                <!-- Center (Vertical) -->
                <div class="position-button" onclick="setVerticalPosition('center')" id="posVCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">⬆️⬇️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Center</span>
                    <div class="pos-indicator" id="posVCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
                <!-- Bottom -->
                <div class="position-button" onclick="setVerticalPosition('bottom')" id="posBottom" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">⬇️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Bottom</span>
                    <div class="pos-indicator" id="posBottomIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
            </div>
        </div>
        
        <!-- Horizontal Positioning (Left, Center, Right) -->
        <div style="margin-bottom: 20px;">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">
                <span>⬅️➡️</span> HORIZONTAL POSITION
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                
                <!-- Left -->
                <div class="position-button" onclick="setHorizontalPosition('left')" id="posLeft" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">⬅️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Left</span>
                    <div class="pos-indicator" id="posLeftIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
                <!-- Center (Horizontal) -->
                <div class="position-button" onclick="setHorizontalPosition('center')" id="posHCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">⬅️➡️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Center</span>
                    <div class="pos-indicator" id="posHCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
                <!-- Right -->
                <div class="position-button" onclick="setHorizontalPosition('right')" id="posRight" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">➡️</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Right</span>
                    <div class="pos-indicator" id="posRightIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>
                </div>
                
            </div>
        </div>
        
        <!-- Combined Center (both vertical and horizontal) -->
        <div>
            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">
                <span>🎯</span> CENTER ON CANVAS
            </div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 8px;">
                
                <!-- Center Both -->
                <div class="position-button" onclick="setCenterPosition()" id="posCenter" style="display: flex; align-items: center; gap: 15px; padding: 12px 20px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">
                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <span style="font-size: 24px;">🎯</span>
                    </div>
                    <span style="flex: 1; font-size: 14px; font-weight: 600; color: #0f2a44;">Center on Canvas</span>
                    <span style="font-size: 11px; color: #64748b;">(both axes)</span>
                </div>
                
            </div>
        </div>
        
        <!-- Current Position Status -->
        <div id="currentPosition" style="margin-top: 15px; padding: 8px 12px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 8px; justify-content: center;">
            <span>📍 Current: </span>
            <span id="positionCoordinates">--</span>
        </div>
        
    </div>
</div>
<!-- Image Source Overlay - For adding image captions -->
<div class="overlay-panel" id="imageSourcePanel" style="display: none; width: 280px;">
    <div class="overlay-header">
        <span>🖼️ Add Image Caption</span>
        <button class="overlay-close" onclick="closeOverlay('imageSourcePanel')">✕</button>
    </div>
    <div class="overlay-content" style="padding: 20px;">
        
        <!-- Library Button -->
        <button onclick="openStickerLibraryForNewBox(); closeOverlay('imageSourcePanel');" 
                style="background: var(--purple); color: white; border: none; padding: 18px 16px; border-radius: 50px; cursor: pointer; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); transition: all 0.2s;">
            <span style="font-size: 24px;">📚</span>
            <span>Choose from Library</span>
        </button>
        
        <!-- Upload Button -->
        <button onclick="uploadImageForNewBox(); closeOverlay('imageSourcePanel');" 
                style="background: var(--success); color: white; border: none; padding: 18px 16px; border-radius: 50px; cursor: pointer; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); transition: all 0.2s;">
            <span style="font-size: 24px;">📤</span>
            <span>Upload from Computer</span>
        </button>
        
        <div style="text-align: center; margin-top: 10px; color: var(--muted); font-size: 11px;">
            Supported: JPG, PNG, GIF, WEBP (max 10MB)
        </div>
        
    </div>
</div>



<!-- Image Selector Overlay - Shows 5 image slots from the scene - FITS INSIDE CANVAS -->
<div class="overlay-panel" id="imageSelectorPanel" style="display: none; width: 360px; z-index: 10000;">
    <div class="overlay-header">
        <span>🌄 Scene Images</span>
        <button class="overlay-close" onclick="closeOverlay('imageSelectorPanel')">✕</button>
    </div>
    <div class="overlay-content" style="padding: 16px;">
        
        <!-- 5 Image Slots in a row -->
        <div style="display: flex; gap: 8px; justify-content: space-between; margin-bottom: 16px;">
            
            <!-- Image Slot 1 (Main) -->
            <div class="image-slot" data-slot="image_file" onclick="selectImageSlot('image_file')" style="flex: 1; text-align: center; cursor: pointer;">
                <div id="slot_image_file" style="width: 58px; height: 58px; border-radius: 8px; border: 2px solid var(--info); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">
                    <img id="slot_img_image_file" src="" style="width:100%; height:100%; object-fit: cover; display: none;">
                    <span id="slot_placeholder_image_file" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>
                </div>
                <div style="font-size: 10px; color: var(--info); font-weight: 600;">Main</div>
            </div>
            
            <!-- Image Slot 2 -->
            <div class="image-slot" data-slot="image_file_1" onclick="selectImageSlot('image_file_1')" style="flex: 1; text-align: center; cursor: pointer;">
                <div id="slot_image_file_1" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">
                    <img id="slot_img_image_file_1" src="" style="width:100%; height:100%; object-fit: cover; display: none;">
                    <span id="slot_placeholder_image_file_1" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>
                </div>
                <div style="font-size: 10px; color: var(--muted);">V1</div>
            </div>
            
            <!-- Image Slot 3 -->
            <div class="image-slot" data-slot="image_file_2" onclick="selectImageSlot('image_file_2')" style="flex: 1; text-align: center; cursor: pointer;">
                <div id="slot_image_file_2" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">
                    <img id="slot_img_image_file_2" src="" style="width:100%; height:100%; object-fit: cover; display: none;">
                    <span id="slot_placeholder_image_file_2" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>
                </div>
                <div style="font-size: 10px; color: var(--muted);">V2</div>
            </div>
            
            <!-- Image Slot 4 -->
            <div class="image-slot" data-slot="image_file_3" onclick="selectImageSlot('image_file_3')" style="flex: 1; text-align: center; cursor: pointer;">
                <div id="slot_image_file_3" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">
                    <img id="slot_img_image_file_3" src="" style="width:100%; height:100%; object-fit: cover; display: none;">
                    <span id="slot_placeholder_image_file_3" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>
                </div>
                <div style="font-size: 10px; color: var(--muted);">V3</div>
            </div>
            
            <!-- Image Slot 5 -->
            <div class="image-slot" data-slot="image_file_4" onclick="selectImageSlot('image_file_4')" style="flex: 1; text-align: center; cursor: pointer;">
                <div id="slot_image_file_4" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">
                    <img id="slot_img_image_file_4" src="" style="width:100%; height:100%; object-fit: cover; display: none;">
                    <span id="slot_placeholder_image_file_4" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>
                </div>
                <div style="font-size: 10px; color: var(--muted);">V4</div>
            </div>
            
        </div>
        
        <!-- Prompt Textarea - Moved up -->
        <div style="margin-bottom: 16px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Prompt for selected slot</div>
            <textarea id="slotPrompt" rows="3" class="panel-input" placeholder="Enter prompt for image generation..." style="resize: vertical; width: 100%; font-family: monospace; font-size: 12px; padding: 10px; border: 2px solid var(--border); border-radius: 12px;"></textarea>
        </div>
        
        <!-- Action Buttons -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px;">
            <button class="panel-btn" onclick="uploadImageForSelectedSlot()" style="background: var(--success); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                <span style="font-size: 18px;">📤</span>
                <span>Upload</span>
            </button>
            <button class="panel-btn" onclick="openLibraryForSelectedSlot()" style="background: var(--purple); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                <span style="font-size: 18px;">📚</span>
                <span>Library</span>
            </button>
            <button class="panel-btn" onclick="generateImageForSelectedSlot()" style="background: var(--info); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                <span style="font-size: 18px;">🔄</span>
                <span>Generate</span>
            </button>
        </div>
        
        <!-- Hidden file input for uploads -->
        <input type="file" id="slotImageUpload" accept="image/*" style="display: none;">
        
        <!-- Current Slot Indicator - Compact -->
        <div style="padding: 8px 12px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; justify-content: space-between;">
            <span>Selected:</span>
            <span id="selectedSlotName" style="font-weight: 600; color: var(--info);">Main</span>
        </div>
        
    </div>
</div>

<!-- Audio Overlay 1: Current Scene Audio - REDUCED WIDTH -->
<div class="overlay-panel" id="currentSceneAudioOverlay" style="display: none; width: 330px; max-height: 500px; overflow-y: auto; z-index: 10000;">
    <div class="overlay-header" style="position: sticky; top: 0; background: white; z-index: 10; border-bottom: 1px solid var(--border);">
        <span><span style="background: var(--purple); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 8px;">1</span> Current Scene Audio</span>
        <button class="overlay-close" onclick="closeOverlay('currentSceneAudioOverlay')">✕</button>
    </div>
    <div class="overlay-content" style="padding: 14px;"> <!-- Reduced padding from 16px to 14px -->
        
        <!-- Scene Text -->
        <div style="margin-bottom: 12px;"> <!-- Reduced margin -->
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Scene Text</div> <!-- Reduced margin-bottom -->
            <div id="currentSceneAudioTextDisplay" style="background: #f1f5f9; padding: 10px; border-radius: 8px; font-size: 13px; border: 1px solid var(--border); max-height: 80px; overflow-y: auto;">
                Loading...
            </div>
        </div>
        
        <!-- Audio Player -->
        <div style="margin-bottom: 12px;"> <!-- Reduced margin -->
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Audio Player</div> <!-- Reduced margin-bottom -->
            <div id="currentSceneAudioPlayerContainer">
                <div style="color:var(--muted); text-align:center; padding:15px;">No audio for this scene</div> <!-- Reduced padding -->
            </div>
        </div>
        
        <!-- Generate Button -->
        <div>
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Action</div>
            <button class="panel-btn" onclick="generateCurrentAudioFromOverlay()" style="background: var(--purple); color: white; border: none; padding: 10px; border-radius: 30px; cursor: pointer; font-size: 14px; font-weight:600; width:100%;">
                <span>🔄</span> Generate Audio
            </button>
        </div>
    </div>
</div>

<!-- Audio Overlay 2: Podcast-Wide Audio - Also reduce width for consistency -->
<div class="overlay-panel" id="podcastAudioOverlay" style="display: none; width: 330px; max-height: 500px; overflow-y: auto; z-index: 10000;">
    <div class="overlay-header" style="position: sticky; top: 0; background: white; z-index: 10; border-bottom: 1px solid var(--border);">
        <span><span style="background: var(--info); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 8px;">2</span> Podcast-Wide Audio</span>
        <button class="overlay-close" onclick="closeOverlay('podcastAudioOverlay')">✕</button>
    </div>
    <div class="overlay-content" style="padding: 14px;">
        
        <!-- Voice Dropdown -->
        <div style="margin-bottom: 12px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Voice for All Scenes</div>
            <select id="podcastAudioOverlayVoiceSelect" class="panel-select" style="width:100%; margin-bottom:6px; padding: 10px;" onchange="previewSelectedVoiceOverlay()">
                <option value="" disabled selected>-- Select Voice --</option>
                <?php 
                $voice_lang = $podcast_lang_code ?? 'en';
                $voice_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$voice_lang' ORDER BY voice_name");
                if ($voice_query && mysqli_num_rows($voice_query) > 0) {
                    while ($voice = mysqli_fetch_assoc($voice_query)) {
                        $display = $voice['voice_name'];
                        if (!empty($voice['voice_description'])) {
                            $display .= " - " . $voice['voice_description'];
                        }
                        echo '<option value="' . htmlspecialchars($voice['voice_key']) . '" data-sample="' . htmlspecialchars($voice['sample_voice'] ?? '') . '">' . htmlspecialchars($display) . '</option>';
                    }
                } else {
                    echo '<option value="en-US-GuyNeural">Guy - Male</option>';
                    echo '<option value="en-US-DavisNeural">Davis - Male</option>';
                    echo '<option value="en-US-SaraNeural">Sara - Female</option>';
                }
                ?>
            </select>
            <button class="panel-btn" onclick="playPodcastVoiceSampleOverlay()" style="background: var(--purple); color: white; border: none; padding: 8px 12px; border-radius: 30px; cursor: pointer; font-size: 12px; width:100%;">
                <span>🔊</span> Play Sample
            </button>
        </div>
        
        <!-- Background Music Section -->
        <div style="margin-bottom: 12px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Background Music</div>
            
            <div id="podcastAudioMusicInfo" style="background: #f1f5f9; padding: 8px; border-radius: 8px; font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                <span id="podcastAudioMusicFileName" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis;">No music selected</span>
                <button class="panel-btn" onclick="clearBackgroundMusicOverlay()" style="background: #f1f5f9; color: var(--error); border: 1px solid var(--border); padding: 4px 8px; border-radius: 20px; font-size: 11px;">✕</button>
            </div>
            
            <div id="podcastAudioMusicPlayerContainer" style="margin-bottom: 8px; display: none;">
                <audio id="podcastAudioMusicPlayer" controls style="width:100%; height:36px;">
                    <source src="" type="audio/mpeg">
                </audio>
            </div>
            
            <div style="display: flex; gap: 6px;">
                <button class="panel-btn" onclick="openMusicLibraryFromOverlay()" style="background: var(--info); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">
                    📚 Library
                </button>
                <button class="panel-btn" onclick="uploadBackgroundMusicFromOverlay()" style="background: var(--success); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">
                    📤 Upload
                </button>
            </div>
        </div>
        
        <!-- Generate All Button -->
        <div style="margin-top: 15px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Batch Action</div>
            <button class="panel-btn" onclick="generateAllAudioWithSelectedVoiceOverlay()" style="background: var(--dark-blue); color: white; border: none; padding: 12px; border-radius: 30px; cursor: pointer; font-size: 13px; font-weight:600; width:100%;">
                <span>🎤</span> Generate All Scenes
            </button>
        </div>
    </div>
</div>

<!-- Audio Overlay 2: Podcast-Wide Audio -->
<div class="overlay-panel" id="podcastAudioOverlay" style="display: none; width: 340px; max-height: 500px; overflow-y: auto; z-index: 10000;">
    <div class="overlay-header" style="position: sticky; top: 0; background: white; z-index: 10; border-bottom: 1px solid var(--border);">
        <span><span style="background: var(--info); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 8px;">2</span> Podcast-Wide Audio</span>
        <button class="overlay-close" onclick="closeOverlay('podcastAudioOverlay')">✕</button>
    </div>
    <div class="overlay-content" style="padding: 16px;">
        
        <!-- Voice Dropdown -->
        <div style="margin-bottom: 15px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Voice for All Scenes</div>
            <select id="podcastAudioOverlayVoiceSelect" class="panel-select" style="width:100%; margin-bottom:8px;" onchange="previewSelectedVoiceOverlay()">
                <option value="" disabled selected>-- Select Voice for All Scenes --</option>
                <?php 
                $voice_lang = $podcast_lang_code ?? 'en';
                $voice_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$voice_lang' ORDER BY voice_name");
                if ($voice_query && mysqli_num_rows($voice_query) > 0) {
                    while ($voice = mysqli_fetch_assoc($voice_query)) {
                        $display = $voice['voice_name'];
                        if (!empty($voice['voice_description'])) {
                            $display .= " - " . $voice['voice_description'];
                        }
                        echo '<option value="' . htmlspecialchars($voice['voice_key']) . '" data-sample="' . htmlspecialchars($voice['sample_voice'] ?? '') . '">' . htmlspecialchars($display) . '</option>';
                    }
                } else {
                    echo '<option value="en-US-GuyNeural">Guy - Calm, Steady Male ⭐</option>';
                    echo '<option value="en-US-DavisNeural">Davis - Deep, Soothing Male ⭐</option>';
                    echo '<option value="en-US-SaraNeural">Sara - Empathetic, Warm Female ⭐</option>';
                }
                ?>
            </select>
            <button class="panel-btn" onclick="playPodcastVoiceSampleOverlay()" style="background: var(--purple); color: white; border: none; padding: 8px 14px; border-radius: 30px; cursor: pointer; font-size: 12px; width:100%;">
                <span>🔊</span> Play Voice Sample
            </button>
        </div>
        
        <!-- Background Music Section -->
        <div style="margin-bottom: 15px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Background Music</div>
            
            <div id="podcastAudioMusicInfo" style="background: #f1f5f9; padding: 10px; border-radius: 8px; font-size: 12px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                <span id="podcastAudioMusicFileName">No music selected</span>
                <button class="panel-btn" onclick="clearBackgroundMusicOverlay()" style="background: #f1f5f9; color: var(--error); border: 1px solid var(--border); padding: 4px 8px; border-radius: 20px; font-size: 11px;">✕ Clear</button>
            </div>
            
            <div id="podcastAudioMusicPlayerContainer" style="margin-bottom: 10px; display: none;">
                <audio id="podcastAudioMusicPlayer" controls style="width:100%; height:40px;">
                    <source src="" type="audio/mpeg">
                </audio>
            </div>
            
            <div style="display: flex; gap: 8px;">
                <button class="panel-btn" onclick="openMusicLibraryFromOverlay()" style="background: var(--info); color: white; border: none; padding: 10px; border-radius: 30px; cursor: pointer; font-size: 13px; flex:1; display: flex; align-items: center; justify-content: center; gap: 4px;">
                    <span>📚</span> Library
                </button>
                <button class="panel-btn" onclick="uploadBackgroundMusicFromOverlay()" style="background: var(--success); color: white; border: none; padding: 10px; border-radius: 30px; cursor: pointer; font-size: 13px; flex:1; display: flex; align-items: center; justify-content: center; gap: 4px;">
                    <span>📤</span> Upload
                </button>
            </div>
        </div>
        
        <!-- Generate All Button -->
        <div style="margin-top: 20px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Batch Action</div>
            <button class="panel-btn" onclick="generateAllAudioWithSelectedVoiceOverlay()" style="background: var(--dark-blue); color: white; border: none; padding: 14px; border-radius: 30px; cursor: pointer; font-size: 14px; font-weight:600; width:100%;">
                <span>🎤</span> Generate All Scenes with Selected Voice
            </button>
        </div>
    </div>
</div>

<!-- Secondary Icons - Audio Settings (hidden by default) -->
<div class="secondary-icons" id="audioIcons" style="display: none; position: absolute; top: 20px; left: 20px; z-index: 1000; pointer-events: auto;">
    <!-- Back button -->
    <div class="overlay-icon back-icon" onclick="hideAudioIcons()" title="Back to main">←</div>
    
    <!-- Audio tools -->
    <div class="overlay-icon" onclick="showCurrentSceneAudioOverlay(event)" title="Current Scene Audio" style="background: var(--purple);">🔊</div>
    <div class="overlay-icon" onclick="showPodcastAudioOverlay(event)" title="Podcast-Wide Audio" style="background: var(--info);">🎤</div>
    
   
</div>

<!-- Music Library Modal -->
<div id="musicLibraryModal" class="modal-overlay" style="z-index: 10002; display: none;">
    <div class="modal-content" style="max-width: 800px; width: 90%;">
        <div class="modal-header">
            <h3><span style="margin-right: 8px;">🎵</span> Music Library</h3>
            <button class="modal-close" onclick="closeMusicLibrary()">✕</button>
        </div>
        
        <div class="modal-search">
            <div style="display: flex; gap: 8px;">
                <input type="text" id="musicSearchInput" placeholder="Search by filename..." onkeyup="filterMusicFiles()" style="flex: 1; padding: 12px; border: 2px solid var(--border); border-radius: 30px; font-size: 14px;">
                <span id="musicCount" style="padding: 12px; color: var(--muted);">0 files</span>
            </div>
        </div>
        
        <div class="modal-body" style="padding: 16px; max-height: 60vh; overflow-y: auto;">
            <div id="musicGrid" class="music-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">Loading music library...</div>
            </div>
        </div>
        
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px;">
            <div id="musicSelInfo" style="font-size: 13px; color: var(--muted);">No file selected</div>
            <div style="display: flex; gap: 12px;">
                <button class="panel-btn" onclick="closeMusicLibrary()" style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px;">Cancel</button>
                <button id="selectMusicBtn" class="panel-btn" onclick="selectMusicForPodcast()" style="background: var(--success); color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;" disabled>Use Selected Music</button>
            </div>
        </div>
    </div>
</div>	
<!-- Add this to your image settings panel or a new overlay -->
<div class="overlay-panel" id="kenBurnsPanel" style="display: none; width: 280px;">
    <div class="overlay-header">
        <button class="overlay-close" onclick="closeOverlay('kenBurnsPanel')">←</button>
        <span>🎬 Ken Burns Effect</span>
    </div>
    <div class="overlay-content" style="padding: 15px;">
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-in')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">🔍</div>
                <div style="font-size: 12px; font-weight: 600;">Zoom In</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-out')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">🔎</div>
                <div style="font-size: 12px; font-weight: 600;">Zoom Out</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('pan-left')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">⬅️</div>
                <div style="font-size: 12px; font-weight: 600;">Pan Left</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('pan-right')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">➡️</div>
                <div style="font-size: 12px; font-weight: 600;">Pan Right</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('pan-up')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">⬆️</div>
                <div style="font-size: 12px; font-weight: 600;">Pan Up</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('pan-down')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">
                <div style="font-size: 24px;">⬇️</div>
                <div style="font-size: 12px; font-weight: 600;">Pan Down</div>
            </div>
            
            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-pan')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent; grid-column: span 2;">
                <div style="font-size: 24px;">🎯</div>
                <div style="font-size: 12px; font-weight: 600;">Zoom & Pan</div>
            </div>
        </div>
        
        <div style="margin-top: 15px; padding: 10px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">
            <span style="background: var(--info); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>
            <span>Effect plays during preview/recording</span>
        </div>
    </div>
</div>
			<!-- Video Play Button (for video backgrounds) -->
			<!-- inam - -->
			
			<!-- Overlay Icons -->
			<!-- Overlay Icons -->
			<!-- Overlay Icons -->
			
		</div>
		
		
		<!-- Zoom Controls -->
<!-- Zoom Controls - REPLACE THIS -->
		<!-- Pan/Scroll Controls for Mobile -->
		<div class="canvas-pan-controls">
			<button class="pan-btn" id="panModeBtn" onclick="togglePanMode()" title="Toggle Pan/Scroll Mode (Hold to drag canvas)">
				<span class="pan-icon">🖐️</span>
			</button>
			
		</div>
		
		</div>
		 <!-- Navigation Arrows -->
     
        <!-- Logo Status -->
       
    </div>

    <!-- Log Box -->
    <textarea id="logBox" class="log-box" readonly placeholder="Activity log..."></textarea>
</div>

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

<!-- Media Library Modal -->
<!-- Media Library Modal -->




<script>
// ========== GLOBAL VARIABLES ==========
let scenes = <?= json_encode($scenes) ?>;
let currentSceneIndex = 0;
let currentSceneId = scenes.length > 0 ? scenes[0].id : null;
let currentSettingType = 'typography';
let currentImageField = 'image_file'; // Track which image field is currently selected
let isPlaying = false;
let isRecording = false;
let totalGen = 0, doneGen = 0, STOP = false;
let audio_files = <?= json_encode($audio_files) ?>;
// ========== FABRIC.JS INTEGRATION ==========
let fabricCanvas = null;
let currentBackgroundImage = null;
let currentBackgroundVideo = null;
let captionText = null;
let headerText = null;
let footerText = null;
let logoImage = null;
let isSelectionMode = false;
let autoSaveTimeout = null;

let isReloading = false;
let lastReloadTime = 0;
let isBackgroundLoading = false;
let lastBackgroundLoad = 0;
// DEBUG: Track what's causing updates
let updateCounter = 0;
let lastUpdateSource = '';

// Animation state for playback
let isPlayingAnimation = false;
let animationFrame = null;
let animationInterval = null;
let currentAnimationStep = 0;
let originalText = '';
let originalCaptionSettings = {};

function trackUpdate(source) {
    updateCounter++;
    const now = new Date().toLocaleTimeString();
    console.log(`🔴 UPDATE #${updateCounter} at ${now} from: ${source}`);
    console.trace('Update trace:');
    
    // Store in a visible element for easy checking
    let debugDiv = document.getElementById('updateDebug');
    if (!debugDiv) {
        debugDiv = document.createElement('div');
        debugDiv.id = 'updateDebug';
        debugDiv.style.cssText = 'position:fixed; bottom:10px; left:10px; background:red; color:white; padding:5px; z-index:9999; font-size:12px;';
        document.body.appendChild(debugDiv);
    }
    debugDiv.innerHTML = `Updates: ${updateCounter}<br>Last: ${source}<br>${now}`;
}
// Initialize Fabric canvas - RESPONSIVE VERSION
function initFabricCanvas() {
    if (fabricCanvas) return;
    
    console.log('🚀 Initializing Fabric canvas...');
    
    // Get the container
    const container = document.getElementById('canvasContainer');
    const containerWidth = container.clientWidth;
    
    // Calculate dimensions based on actual container width
    // so Fabric's internal size always matches the displayed size (ratio = 1)
    let canvasHeight, canvasWidth;

    // Always use actual container width to avoid CSS scaling mismatch
    canvasWidth  = Math.floor(containerWidth);
    canvasHeight = Math.round(canvasWidth * 16 / 9);

    // Clamp to reasonable limits
    if (canvasWidth < 280)  canvasWidth  = 280;
    if (canvasWidth > 500)  canvasWidth  = 500;
    canvasHeight = Math.round(canvasWidth * 16 / 9);

console.log('📐 Setting canvas size to:', canvasWidth, 'x', canvasHeight);

console.log('📐 Setting canvas size to:', canvasWidth, 'x', canvasHeight);
	
	
    
    fabricCanvas = new fabric.Canvas('fabricCanvas', {
        width: canvasWidth,
        height: canvasHeight,
        backgroundColor: '#000000',
        preserveObjectStacking: true,
        allowTouchScrolling: false,
        selection: true
    });
    
    console.log('✅ Fabric canvas created');
    
    // CRITICAL: Recalculate offset before every interaction
    // This fixes handle positions when canvas is inside a CSS-scaled container
    fabricCanvas.on('mouse:down', function() {
        fabricCanvas.calcOffset();
    });
    fabricCanvas.on('touch:start', function() {
        fabricCanvas.calcOffset();
    });
    
    // Disable selection by default
    fabricCanvas.selection = false;
    fabricCanvas.forEachObject(obj => {
        obj.selectable = false;
        obj.hasControls = false;
        obj.hasBorders = false;
    });
    
	
	  // Setup caption selection handlers
    setupCaptionSelection();
    // Load the current scene
    console.log('📂 Loading current scene to Fabric...');
    loadCurrentSceneToFabric();
    
    console.log('Fabric.js canvas initialized');
}
// Prevent canvas reload on scroll for mobile
function preventScrollReload() {
    if ('ontouchstart' in window) {
        let lastScrollY = window.scrollY;
        let scrollTimeout;
        
        window.addEventListener('scroll', function() {
            // Clear any pending reload
            if (scrollTimeout) {
                clearTimeout(scrollTimeout);
            }
            
            // Don't trigger resize on scroll
            const currentScrollY = window.scrollY;
            if (Math.abs(currentScrollY - lastScrollY) > 10) {
                console.log('📱 Scroll detected, suppressing resize');
                lastScrollY = currentScrollY;
            }
        }, { passive: true });
    }
}
// Add this near the top of your JavaScript
let renderCount = 0;
// Call it after initialization
setTimeout(preventScrollReload, 2000);
// Handle window resize
// Handle window resize
// Handle window resize - UPDATED WITH RELOAD PREVENTION
// Handle window resize - SIMPLIFIED VERSION (NO RELOAD)
window.addEventListener('resize', debounce(function() {
	  trackUpdate('resize handler'); // ADD THIS
    if (fabricCanvas) {
        console.log('🔄 Window resized, adjusting canvas...');
        
        const container = document.getElementById('canvasContainer');
        const containerWidth = container.clientWidth;
        
        // Always match container width so internal size = display size
        let canvasWidth  = Math.floor(containerWidth);
        if (canvasWidth < 280) canvasWidth = 280;
        if (canvasWidth > 500) canvasWidth = 500;
        let canvasHeight = Math.round(canvasWidth * 16 / 9);
        
        // Only resize if dimensions actually changed
        if (Math.abs(fabricCanvas.width - canvasWidth) > 5) {
            console.log('📐 Resizing canvas to:', canvasWidth, 'x', canvasHeight);
            
            // Store current zoom/pan
            const currentZoom = fabricCanvas.getZoom();
            const currentViewport = fabricCanvas.viewportTransform;
            
            // Resize canvas
            fabricCanvas.setDimensions({
                width: canvasWidth,
                height: canvasHeight
            });
            
            // Restore zoom/pan
            if (currentViewport) {
                fabricCanvas.setViewportTransform(currentViewport);
            }
            
            // Just re-render, don't reload
            fabricCanvas.renderAll();
        }
    }
}, 500));

// Handle orientation change specifically for mobile
if ('ontouchstart' in window) {
    window.addEventListener('orientationchange', function() {
        console.log('📱 Orientation change detected');
        
        // Delay the resize to allow the browser to update
        setTimeout(function() {
            if (fabricCanvas) {
                const container = document.getElementById('canvasContainer');
                const containerWidth = container.clientWidth;
                
                let canvasHeight, canvasWidth;
                
                if (window.innerWidth <= 768) {
                    canvasWidth = Math.min(containerWidth, 400);
                    canvasHeight = Math.round(canvasWidth * 16/9);
                } else {
                    canvasHeight = Math.min(700, window.innerHeight * 0.7);
                    canvasWidth = Math.round(canvasHeight * 9/16);
                }
                
                fabricCanvas.setDimensions({
                    width: canvasWidth,
                    height: canvasHeight
                });
                
                loadCurrentSceneToFabric();
            }
        }, 300);
    });
}

// Debounce helper function
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
// ========== TOGGLE SCENE SETTINGS PANEL ==========
let currentOpenPanel = null;

// Update toggleSceneSettings function
// ========== TOGGLE SCENE SETTINGS PANEL - FIXED ==========
function toggleSceneSettings(type) {
    console.log('toggleSceneSettings called with type:', type);
    
    if (!currentSceneId) {
        console.log('No current scene ID');
        return;
    }
    
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    // FIX: Get icon safely - it might not exist
    const icon = document.getElementById('overlay-' + type);
    const selectIcon = document.getElementById('overlay-select');
    const deleteIcon = document.getElementById('overlay-delete');
    
    // If clicking the same panel that's already open, close it
    if (currentOpenPanel === type && settingsPanel && settingsPanel.classList.contains('active')) {
        closeScenePanel();
        // FIX: Check if icon exists before accessing classList
        if (icon) {
            icon.classList.remove('active');
        }
        currentOpenPanel = null;
        
        // Hide select and delete icons when panel closes
        if (selectIcon) {
            selectIcon.style.display = 'none';
        }
        if (deleteIcon) {
            deleteIcon.style.display = 'none';
        }
        return;
    }
    
    // Update current setting type
    currentSettingType = type;
    
    // First hide all panels
    const allPanels = document.querySelectorAll('.scene-setting');
    allPanels.forEach(p => p.classList.add('hidden'));
    
    // Handle different panel types
    if (type === 'typography' || type === 'captions') {
        const combinedPanel = document.getElementById('scene-typography-captions');
        if (combinedPanel) {
            combinedPanel.classList.remove('hidden');
            
            // SHOW the select and delete icons for typography tab
            if (selectIcon) {
                selectIcon.style.display = 'flex';
            }
            if (deleteIcon) {
                deleteIcon.style.display = 'flex';
            }
            
            // Enable selection mode
            setSelectionMode(true);
            
            // CRITICAL: Force all objects to be selectable and update coordinates
            if (fabricCanvas) {
                fabricCanvas.getObjects().forEach(obj => {
                    obj.set({
                        selectable: true,
                        evented: true,
                        hasControls: true,
                        hasBorders: true
                    });
                    obj.setCoords(); // Important for hit detection
                });
                fabricCanvas.renderAll();
                console.log('✅ All objects set to selectable');
            }
            
            // Auto-select the first caption
            autoSelectFirstCaption();
            
            const seqNoSpan = document.getElementById('currentSceneSeqNoCombined');
            if (seqNoSpan) {
                const scene = scenes.find(s => s.id == currentSceneId);
                seqNoSpan.innerText = scene?.seq_no || (currentSceneIndex + 1);
            }
        }
    } else {
        // Handle other panels (images, audio)
        const panelId = 'scene-' + type;
        const selectedPanel = document.getElementById(panelId);
        
        if (selectedPanel) {
            selectedPanel.classList.remove('hidden');
            
            // HIDE the select and delete icons for other tabs
            if (selectIcon) {
                selectIcon.style.display = 'none';
            }
            if (deleteIcon) {
                deleteIcon.style.display = 'none';
            }
            
            // Disable selection mode for non-typography tabs
            setSelectionMode(false);
            
            if (type === 'audio') {
                loadAudioForScene(currentSceneId);
            } else if (type === 'images') {
                loadImageThumbnails();
                loadImagePrompt('image_file');
            }
            
            // Clear any active selection
            if (fabricCanvas) {
                fabricCanvas.discardActiveObject();
                fabricCanvas.renderAll();
            }
            clearCaptionSelection();
        }
    }
    
    // Show the settings panel
    if (settingsPanel) {
        settingsPanel.classList.add('active');
    }
    
    // Update icon states - FIX: Check if icon exists
    document.querySelectorAll('.overlay-icon').forEach(ic => {
        ic.classList.remove('active');
    });
    
    // FIX: Only try to add class if icon exists
    if (icon) {
        icon.classList.add('active');
    } else {
        console.log('⚠️ Icon not found: overlay-' + type);
    }
    
    currentOpenPanel = type;
    
    // Scroll to show the panel smoothly
    setTimeout(() => {
        if (settingsPanel) {
            settingsPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, 100);
}

// ========== IMAGE CAPTION SETTINGS FUNCTIONS ==========

// Load image caption settings
function loadImageCaptionSettings(caption) {
    console.log('🖼️ Loading image caption settings:', caption);
    
    // Restore panel content first
    restorePanelContent();
    
    // Disable text-specific controls
    const textControls = [
        'sceneFontFamily', 
        'sceneFontSize', 
        'sceneFontColor', 
        'sceneFontBgColor', 
        'sceneFontBgEnable'
    ];
    
    textControls.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = true;
            el.style.opacity = '0.5';
            el.style.pointerEvents = 'none';
        }
    });
    
    // Disable dropdown buttons
    const dropdowns = document.querySelectorAll('.emoji-main-btn');
    dropdowns.forEach(btn => {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
    });
    
    // Hide effect panels
    const effectPanels = ['outlineSettings', 'strokeSettings', 'speedSettings'];
    effectPanels.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    
    // Show image preview
    if (caption.image_file) {
        showImageBoxPreview(caption.image_file);
    } else {
        // Hide preview if no image
        const previewDiv = document.getElementById('selectedImageBoxPreview');
        if (previewDiv) previewDiv.style.display = 'none';
    }
    
    // Update panel title
    const seqNoSpan = document.getElementById('currentSceneSeqNoCombined');
    if (seqNoSpan) {
        seqNoSpan.innerText = '🖼️ Image Box';
    }
}




// Function to enable/disable selection mode - FIXED
function setSelectionMode(enabled) {
    console.log(`🔧 Setting selection mode: ${enabled}`);
    
    if (!fabricCanvas) return;
    
    // Update the selection mode flag
    isSelectionMode = enabled;
    
    // CRITICAL: Control HOW selection works
    if (enabled) {
        // In typography mode:
        // - Allow selecting individual objects
        // - Disable selecting empty area (no selection rectangle)
        fabricCanvas.selection = false; // Don't allow drawing selection box
        fabricCanvas.skipTargetFind = false; // Allow finding objects
        fabricCanvas.targetFindTolerance = 5; // Make it easier to hit objects
    } else {
        // In other modes:
        // - Disable all selection
        fabricCanvas.selection = false;
        fabricCanvas.skipTargetFind = true; // Skip finding objects entirely
    }
    
    // Update ALL objects on canvas
    fabricCanvas.getObjects().forEach(obj => {
        // Check if this is a background object (no captionId)
        if (!obj.captionId) {
            // Background images/videos should NEVER be selectable
            obj.set({
                selectable: false,
                hasControls: false,
                hasBorders: false,
                evented: false,
                lockMovementX: true,
                lockMovementY: true,
                lockScalingX: true,
                lockScalingY: true,
                lockRotation: true,
                hoverCursor: 'default' // No special cursor on hover
            });
        } else {
            // This is a caption (has captionId) - make selectable based on mode
            obj.set({
                selectable: enabled,
                hasControls: enabled,
                hasBorders: enabled,
                evented: enabled
            });
            
            // Set cursor based on mode
            if (enabled) {
                obj.set('hoverCursor', 'move');
            } else {
                obj.set('hoverCursor', 'default');
            }
            
            // Log for debugging
            if (enabled && obj.captionId) {
                console.log(`Setting caption ${obj.captionId} (${obj.type}) selectable: true`);
            }
        }
        
        // CRITICAL: Update coordinates for hit detection
        obj.setCoords();
    });
    
    // If disabling selection, clear any active selection
    if (!enabled && fabricCanvas.getActiveObject()) {
        fabricCanvas.discardActiveObject();
        clearCaptionSelection();
    }
    
    fabricCanvas.renderAll();
}



// Auto-select the first caption when typography tab is opened
function autoSelectFirstCaption() {
    if (!fabricCanvas) return;
    
    // Find the first caption object on canvas
    const objects = fabricCanvas.getObjects();
    const firstCaption = objects.find(obj => obj.captionId && 
                                           (obj.type === 'textbox' || obj.type === 'text'));
    
    if (firstCaption) {
        // Select it
        fabricCanvas.setActiveObject(firstCaption);
        
        // CRITICAL: Set the global captionText variable
        captionText = firstCaption;
        
        fabricCanvas.renderAll();
        
        // Set selected IDs
        selectedCaptionId = firstCaption.captionId;
        selectedCaptionType = firstCaption.captionType;
        
        // Load its settings into panel
        const caption = findCaptionById(selectedCaptionId);
        if (caption) {
            loadCaptionSettingsToPanel(caption);
            updateCaptionPanelHeader(caption);
        }
        
        console.log('✅ Auto-selected first caption:', selectedCaptionId);
        console.log('✅ captionText set to:', captionText);
    } else {
        console.log('⚠️ No captions found to select');
        showCaptionSelectionMessage();
        captionText = null;
    }
}
// Update closeScenePanel function
// Update closeScenePanel function
// Update closeScenePanel function
function closeScenePanel() {
    console.log('closeScenePanel called');
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    const selectIcon = document.getElementById('overlay-select');
    const deleteIcon = document.getElementById('overlay-delete');
    
    if (settingsPanel) {
        settingsPanel.classList.remove('active');
        
        // Remove active state from all icons
        document.querySelectorAll('.overlay-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        
        // Hide select and delete icons when panel closes
        if (selectIcon) {
            selectIcon.style.display = 'none';
        }
        if (deleteIcon) {
            deleteIcon.style.display = 'none';
        }
        
        // Disable selection mode when closing panel
        setSelectionMode(false);
        
        currentOpenPanel = null;
    }
}
// Update the enableObjectSelection function
function enableObjectSelection() {
    // If typography tab is not open, open it first
    if (currentOpenPanel !== 'typography') {
        toggleSceneSettings('typography');
    } else {
        // If typography tab is already open, toggle selection mode
        setSelectionMode(!isSelectionMode);
    }
    
    // Keep the visual feedback
    const selectIcon = document.getElementById('overlay-select');
    selectIcon.style.background = 'var(--success)';
    setTimeout(() => {
        if (isSelectionMode) {
            selectIcon.style.background = 'var(--info)';
        } else {
            selectIcon.style.background = 'rgba(0,0,0,0.6)';
        }
    }, 200);
}


// Save text position to database
async function saveTextPositionToDB(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene || !captionText) return;
    
    const fd = new FormData();
    fd.append('ajax_action', 'save_scene_settings');
    fd.append('scene_id', sceneId);
    fd.append('text_x', Math.round(captionText.left));
    fd.append('text_y', Math.round(captionText.top));
    fd.append('text_width', Math.round(captionText.width));
    fd.append('text_rotation', captionText.angle || 0);
    
    try {
        const {data} = await safeFetch(fd);
        if (data.success) {
            L(`📍 Text position saved for scene #${sceneId}`);
        }
    } catch(e) {
        console.error('Failed to save position:', e);
    }
}


// Global variable to store captions for current scene
let sceneCaptions = {};

// Load all captions for a scene
// Load all captions for a scene
async function loadSceneCaptions(sceneId) {
    console.log(`📡 Fetching captions for scene ${sceneId}...`);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_scene_captions');
        fd.append('scene_id', sceneId);
        
        const {data} = await safeFetch(fd);
        console.log('📥 Raw captions response:', data);
        
        if (data.success && data.captions && data.captions.length > 0) {
            // Clear existing captions first
            sceneCaptions = {};
            
            // Store captions by type
            data.captions.forEach(caption => {
                sceneCaptions[caption.caption_type] = caption;
            });
            
            console.log(`✅ Loaded ${data.captions.length} captions for scene:`, sceneId);
            console.log('📊 Captions data:', sceneCaptions);
        } else {
            console.log('⚠️ No captions found for scene:', sceneId);
            sceneCaptions = {};
        }
        
        return sceneCaptions;
    } catch(e) {
        console.error('❌ Error loading captions:', e);
        return {};
    }
}

// Save or update a caption
async function saveCaption(sceneId, captionType, captionData) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_caption');
        
        // You need to have captionId available
        if (!captionData.id) {
            console.error('❌ No caption ID provided in captionData:', captionData);
            return false;
        }
        
        fd.append('caption_id', captionData.id);
        console.log(`📤 Saving caption ${captionData.id} with data:`, captionData);
        
        // Add all caption data
        Object.keys(captionData).forEach(key => {
            let value = captionData[key];
            if (value === null || value === undefined) {
                value = '';
            }
            // Don't send the id again as a field
            if (key !== 'id' && key !== 'caption_type') {
                fd.append(key, value);
                console.log(`  - ${key}: ${value}`);
            }
        });
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            console.log(`✅ Saved ${captionType} caption (ID: ${captionData.id})`);
            
            // Update local cache
            if (!sceneCaptions[captionType]) {
                sceneCaptions[captionType] = {};
            }
            Object.assign(sceneCaptions[captionType], captionData);
            
            return true;
        } else {
            console.error('Save failed:', data.message);
            return false;
        }
    } catch(e) {
        console.error('Save error:', e);
        return false;
    }
}
// Add main caption to canvas - WITH FIXED COORDINATES
// ========== ADD MAIN CAPTION TO FABRIC - FIXED BACKGROUND ==========
async function addMainCaptionToFabric(scene, captionData) {
    console.log('📝 addMainCaptionToFabric called');
    
    if (!captionData || !captionData.text_content || !fabricCanvas) return;

    // Use saved positions or defaults
    const startLeft = captionData.position_x || 50;
    const startTop = captionData.position_y || 200;
    const startWidth = captionData.width || 500;
    const startFontSize = captionData.fontsize || 48;
    
    console.log('Using position:', startLeft, startTop);
    console.log('Background settings:', {
        bg_enabled: captionData.bg_enabled,
        bg_color: captionData.bg_color,
        original_bg: captionData.backgroundColor
    });
    
    // CRITICAL FIX: Determine background color with proper handling
    let backgroundColor = 'transparent';
    if (captionData.bg_enabled == 1 || captionData.bg_enabled === true) {
        backgroundColor = captionData.bg_color || captionData.fontcolor_bg || '#000000';
        console.log('✅ Background ENABLED - using color:', backgroundColor);
    } else {
        console.log('❌ Background DISABLED - using transparent');
    }
    
    // Check if this is a global caption
    const isGlobal = captionData.is_global == 1;
    if (isGlobal) {
        console.log('🌍 This is a GLOBAL caption');
    }
    
    // Parse font weight correctly
    let fontWeight = 'normal';
    if (captionData.fontweight) {
        if (captionData.fontweight === 'bold' || captionData.fontweight === '700') {
            fontWeight = 'bold';
        } else if (typeof captionData.fontweight === 'number') {
            fontWeight = String(captionData.fontweight);
        } else {
            fontWeight = captionData.fontweight;
        }
    }
    
    // Create text object with ALL styling properties
    const textObj = new fabric.Textbox(captionData.text_content, {
        left: parseInt(startLeft),
        top: parseInt(startTop),
        width: parseInt(startWidth),
        fontSize: parseInt(startFontSize),
        fontFamily: captionData.fontfamily || 'Arial',
        fill: captionData.fontcolor || '#ffff00',
        
        // Font styling
        fontWeight: fontWeight,
        fontStyle: captionData.fontstyle || 'normal',
        underline: captionData.underline == 1,
        linethrough: captionData.linethrough == 1,
        textAlign: captionData.text_align || 'center',
        
        // CRITICAL FIX: Background color with proper padding
        backgroundColor: backgroundColor,
        
        // Outline/Stroke
        stroke: captionData.outline_enabled ? captionData.outline_color : 
                (captionData.stroke_enabled ? captionData.stroke_color : null),
        strokeWidth: captionData.outline_enabled ? parseInt(captionData.outline_width || 2) : 
                    (captionData.stroke_enabled ? parseInt(captionData.stroke_width || 2) : 0),
        paintFirst: captionData.outline_enabled ? 'stroke' : 'fill',
        
        // CRITICAL FIX: Set explicit line height and padding
        lineHeight: 1.2,
        padding: 15, // INCREASED padding to make background more visible
        charSpacing: 0,
        
        // Selection properties
        borderColor: '#00ff00',
        cornerColor: '#ff0000',
        cornerSize: 15,
        transparentCorners: false,
        
        // Controls — respect current selection mode
        hasControls: isSelectionMode,
        hasBorders:  isSelectionMode,
        selectable:  isSelectionMode,
        editable:    true,
        evented:     true,
        
        // Store metadata
        captionId:      captionData.id,
        captionType:    captionData.caption_type || 'main',
        isGlobal:       isGlobal,
        animationStyle: captionData.animation_style || 'none',
        animationSpeed: parseFloat(captionData.animation_speed) || 1.0,
        originalText:   captionData.text_content
    });
    
    // Remove existing and add new
    const existing = fabricCanvas.getObjects().find(obj => obj.captionId == captionData.id);
    if (existing) fabricCanvas.remove(existing);
    
    fabricCanvas.add(textObj);
    
    // CRITICAL: Ensure text is above background
    textObj.bringToFront();
    
    fabricCanvas.renderAll();

    // Trigger animation immediately for 'main' captions
    if ((captionData.caption_name === 'main') && captionData.animation_style && captionData.animation_style !== 'none') {
        startCaptionAnimation(textObj);
    }
    
    // Simple move handler
    textObj.on('modified', function() {
        console.log('Moved to:', this.left, this.top);
        autoSaveCaptionProperty(this, 'position_x', Math.round(this.left));
        autoSaveCaptionProperty(this, 'position_y', Math.round(this.top));
        autoSaveCaptionProperty(this, 'width', Math.round(this.width));
    });
    
    // Simple edit handler
    textObj.on('editing:exited', function() {
        if (this.text !== captionData.text_content) {
            autoSaveCaptionProperty(this, 'text_content', this.text);
        }
    });
    
    return textObj;
}
// Debug function to check background colors
// Debug function to check background colors
function debugBackgroundColors() {
    if (!fabricCanvas) return;
    
    console.log('🎨 ===== BACKGROUND COLOR DEBUG =====');
    
    const objects = fabricCanvas.getObjects();
    objects.forEach((obj, index) => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            console.log(`📝 Text ${index}:`, {
                id: obj.captionId,
                text: obj.text?.substring(0, 30),
                backgroundColor: obj.backgroundColor,
                fill: obj.fill,
                padding: obj.padding,
                left: obj.left,
                top: obj.top,
                width: obj.width,
                height: obj.height
            });
            
            // Force background to be visible for testing
            if (obj.backgroundColor && obj.backgroundColor !== 'transparent') {
                console.log('✅ Background should be visible:', obj.backgroundColor);
                
                // Double-check the object is rendered
                obj.set({
                    dirty: true,
                    padding: 15
                });
            } else {
                console.log('❌ No background or transparent');
            }
        }
    });
    
    fabricCanvas.renderAll();
    console.log('🎨 ===== END DEBUG =====');
}

// Add a keyboard shortcut to run debug (Ctrl+Shift+B)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.shiftKey && e.key === 'B') {
        debugBackgroundColors();
        console.log('Debug function triggered via keyboard');
    }
});

// Call it to check
setTimeout(debugBackgroundColors, 2000);
function debugLayering() {
    if (!fabricCanvas) return;
    
    console.log('📊 ===== LAYERING DEBUG =====');
    const objects = fabricCanvas.getObjects();
    
    objects.forEach((obj, index) => {
        console.log(`Layer ${index}:`, {
            type: obj.type,
            isBackground: obj.isBackground || false,
            selectable: obj.selectable,
            hasCaptionId: !!obj.captionId,
            captionType: obj.captionType,
            position: { left: obj.left, top: obj.top }
        });
    });
    
    console.log('📊 ===== END DEBUG =====');
    
    // Check video element
    const video = document.getElementById('hiddenBackgroundVideo');
    if (video) {
        const style = window.getComputedStyle(video);
        console.log('Video element styles:', {
            zIndex: style.zIndex,
            position: style.position,
            display: style.display,
            opacity: style.opacity
        });
    }
}

// Call it after loading a scene
setTimeout(debugLayering, 2000);
// Visualize where each line should be clickable
function debugLinePositions() {
    if (!fabricCanvas) return;
    
    const activeObj = fabricCanvas.getActiveObject();
    if (!activeObj || activeObj.type !== 'textbox') {
        console.log('Please select a text box first');
        return;
    }
    
    const bounds = activeObj.getBoundingRect();
    const fontSize = activeObj.fontSize;
    const lineHeight = activeObj.lineHeight || 1.2;
    const lines = activeObj.text.split('\n').length;
    
    console.log('📊 LINE POSITIONS:');
    
    // Remove old line markers
    const oldMarkers = fabricCanvas.getObjects().filter(obj => obj.isLineMarker);
    oldMarkers.forEach(obj => fabricCanvas.remove(obj));
    
    // Draw markers for each line
    for (let i = 0; i < lines; i++) {
        const lineY = bounds.top + (i * fontSize * lineHeight) + (fontSize * 0.2);
        
        // Horizontal line across the text box at line position
        const lineMarker = new fabric.Line([bounds.left, lineY, bounds.left + bounds.width, lineY], {
            stroke: i === 0 ? '#ff0000' : i === lines-1 ? '#00ff00' : '#ffff00',
            strokeWidth: 2,
            strokeDashArray: [5, 3],
            selectable: false,
            evented: false,
            isLineMarker: true
        });
        
        // Text label
        const label = new fabric.Text(`Line ${i+1}`, {
            left: bounds.left - 60,
            top: lineY - 10,
            fontSize: 12,
            fill: i === 0 ? '#ff0000' : i === lines-1 ? '#00ff00' : '#ffff00',
            selectable: false,
            evented: false,
            isLineMarker: true
        });
        
        fabricCanvas.add(lineMarker);
        fabricCanvas.add(label);
        
        console.log(`Line ${i+1}: Y = ${Math.round(lineY)}`);
    }
    
    fabricCanvas.renderAll();
}

// Run it: debugLinePositions()
// Simple debug function to log text box info
function debugTextBox() {
    if (!fabricCanvas) return;
    
    const activeObj = fabricCanvas.getActiveObject();
    if (!activeObj || !activeObj.captionId) {
        console.log('No active text box');
        return;
    }
    
    console.log(
'=== TEXT BOX DEBUG ===');
    console.log('ID:', activeObj.captionId);
    console.log('Position:', { left: activeObj.left, top: activeObj.top });
    console.log('Size:', { width: activeObj.width, height: activeObj.height });
    console.log('Scale:', { x: activeObj.scaleX, y: activeObj.scaleY });
    console.log('Padding:', activeObj.padding);
    console.log('Font size:', activeObj.fontSize);
    
    // Get bounding rect
    const bounds = activeObj.getBoundingRect();
    console.log('Bounding rect:', {
        left: bounds.left,
        top: bounds.top,
        right: bounds.left + bounds.width,
        bottom: bounds.top + bounds.height,
        width: bounds.width,
        height: bounds.height
    });
    
    // Get canvas dimensions
    console.log('Canvas:', {
        width: fabricCanvas.width,
        height: fabricCanvas.height
    });
}

// Call it when needed
// debugTextBox();
// Debug function to check if text editing is possible
function debugTextEditing() {
    if (!fabricCanvas) return;
    
    const activeObject = fabricCanvas.getActiveObject();
    if (activeObject && activeObject.captionId) {
        console.log('🔍 Text editing debug:', {
            id: activeObject.captionId,
            type: activeObject.type,
            editable: activeObject.editable,
            isEditing: activeObject.isEditing,
            hasHiddenTextarea: !!activeObject.hiddenTextarea
        });
        
        // Force enable editing if needed
        if (activeObject.editable && !activeObject.isEditing) {
            console.log('🔄 Attempting to enter edit mode');
            activeObject.enterEditing();
            activeObject.set({
                backgroundColor: 'rgba(255,255,255,0.95)',
                fill: '#000000'
            });
            fabricCanvas.renderAll();
        }
    }
}

// Call it when needed (you can trigger this from console)
// debugTextEditing();


// Add touch event handlers
function setupTouchHandling() {
    if (!fabricCanvas) return;
    
    // Make handles larger on touch devices
    if ('ontouchstart' in window) {
        fabricCanvas.selection = true;
        fabricCanvas.selectionColor = 'rgba(0, 255, 0, 0.3)';
        fabricCanvas.selectionBorderColor = '#00ff00';
        fabricCanvas.selectionLineWidth = 2;
        
        // Increase handle size for all objects
        fabric.Object.prototype.set({
            cornerSize: 20,
            transparentCorners: false,
            borderColor: '#00ff00',
            cornerColor: '#ff0000'
        });
    }
}

// Call it after canvas initialization
setTimeout(setupTouchHandling, 2000);
// Force all text to be visible with high contrast colors
// Force all text to be visible
function forceTextVisibility() {
    console.log('🎨 Forcing all text to be visible...');
    
    if (!fabricCanvas) return;
    
    const objects = fabricCanvas.getObjects();
    let textFixed = 0;
    
    objects.forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            // Check if text is visible
            const needsFix = !obj.visible || 
                            obj.opacity === 0 || 
                            obj.fill === '#000000' || 
                            obj.fill === 'black' ||
                            (obj.backgroundColor === 'transparent' && obj.fill === '#000000');
            
            if (needsFix) {
                console.log(`🔧 Fixing text object:`, {
                    id: obj.captionId,
                    oldFill: obj.fill,
                    oldBg: obj.backgroundColor
                });
                
                obj.set({
                    fill: '#ffff00', // Bright yellow
                    backgroundColor: 'rgba(0,0,0,0.7)', // Semi-transparent black
                    stroke: '#000000',
                    strokeWidth: 1,
                    opacity: 1,
                    visible: true
                });
                textFixed++;
            }
            obj.bringToFront();
        }
    });
    
    fabricCanvas.renderAll();
    console.log(`✅ Fixed ${textFixed} text objects`);
}

// Call it after scene loads
setTimeout(() => {
    forceTextVisibility();
}, 1000);

// Force all captions to visible positions
function ensureCaptionsVisible() {
    if (!fabricCanvas) return;
    
    console.log('🔍 Ensuring all captions are visible...');
    
    const objects = fabricCanvas.getObjects();
    let updated = 0;
    
    objects.forEach(obj => {
        if (obj.captionId) {
            // Check if object is outside visible area
            if (obj.left < 0 || obj.left > fabricCanvas.width - 100 ||
                obj.top < 0 || obj.top > fabricCanvas.height - 100) {
                
                console.log(`⚠️ Caption ${obj.captionId} at (${obj.left}, ${obj.top}) is outside visible area`);
                
                // Move to visible area
                obj.set({
                    left: 50,
                    top: 200 + (updated * 50), // Stack them if multiple
                    visible: true,
                    opacity: 1
                });
                updated++;
            }
            
            // Ensure text has visible colors
            if (obj.fill === '#ffffff' || obj.fill === 'white') {
                obj.set('fill', '#ffff00'); // Change to yellow if it was white
            }
            
            if (!obj.backgroundColor || obj.backgroundColor === 'transparent') {
                obj.set('backgroundColor', 'rgba(0,0,0,0.5)');
            }
            
            obj.bringToFront();
        }
    });
    
    if (updated > 0) {
        console.log(`✅ Moved ${updated} captions to visible area`);
        fabricCanvas.renderAll();
    }
    
    // Auto-select the first caption
    const firstCaption = objects.find(obj => obj.captionId);
    if (firstCaption) {
        fabricCanvas.setActiveObject(firstCaption);
        selectedCaptionId = firstCaption.captionId;
        selectedCaptionType = firstCaption.captionType;
        console.log('✅ Selected first caption:', selectedCaptionId);
    }
}

// Call this after scene loads
setTimeout(ensureCaptionsVisible, 1000);
// Add header caption to canvas
async function addHeaderCaptionToFabric(scene, captionData) {
    if (!captionData || !captionData.text_content || !fabricCanvas) return;
    
    // Remove existing header if any
    if (headerText) {
        fabricCanvas.remove(headerText);
    }
    
    headerText = new fabric.Textbox(captionData.text_content, {
        left: captionData.position_x || 50,
        top: captionData.position_y || 50,
        width: captionData.width || 400,
        fontSize: parseInt(captionData.fontsize) || 20,
        fontFamily: captionData.fontfamily || 'Inter',
        fill: captionData.fontcolor || '#ffffff',
        fontWeight: captionData.fontweight || 'bold',
        textAlign: captionData.text_align || 'center',
        backgroundColor: captionData.bg_enabled ? (captionData.bg_color || '#000000') : 'transparent',
        selectable: isSelectionMode,
        hasControls: isSelectionMode,
        hasBorders: isSelectionMode,
        editable: true,
        captionType: 'header'
    });
    
    fabricCanvas.add(headerText);
    fabricCanvas.renderAll();
}

// Add footer caption to canvas
async function addFooterCaptionToFabric(scene, captionData) {
    if (!captionData || !captionData.text_content || !fabricCanvas) return;
    
    // Remove existing footer if any
    if (footerText) {
        fabricCanvas.remove(footerText);
    }
    
    footerText = new fabric.Textbox(captionData.text_content, {
        left: captionData.position_x || 50,
        top: captionData.position_y || (fabricCanvas.height - 100),
        width: captionData.width || 400,
        fontSize: parseInt(captionData.fontsize) || 16,
        fontFamily: captionData.fontfamily || 'Inter',
        fill: captionData.fontcolor || '#ffffff',
        fontWeight: captionData.fontweight || 'normal',
        textAlign: captionData.text_align || 'center',
        backgroundColor: captionData.bg_enabled ? (captionData.bg_color || '#000000') : 'transparent',
        selectable: isSelectionMode,
        hasControls: isSelectionMode,
        hasBorders: isSelectionMode,
        editable: true,
        captionType: 'footer'
    });
    
    fabricCanvas.add(footerText);
    fabricCanvas.renderAll();
}

// Make sure this function is async

// ========== LOAD CURRENT SCENE TO FABRIC - WITH PRELOAD CACHE ==========
async function loadCurrentSceneToFabric() {
    trackUpdate('loadCurrentSceneToFabric');
    console.log('🔍 ===== LOAD SCENE START =====');
    console.log('Current scene ID:', currentSceneId);
    console.log('fabricCanvas exists:', !!fabricCanvas);
    
    if (!fabricCanvas) {
        console.error('❌ fabricCanvas is not initialized');
        return;
    }
    
    if (!currentSceneId) {
        console.error('❌ currentSceneId is null');
        return;
    }
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) {
        console.error('❌ Scene not found:', currentSceneId);
        return;
    }
    
    console.log('📝 Scene found:', scene.id);
    
    // IMPORTANT: Reset sceneCaptions before loading
    sceneCaptions = {};
    
    // Load all captions for this scene
    console.log('📚 Loading captions for scene...');
    await loadSceneCaptions(currentSceneId);
    console.log('✅ Captions loaded:', sceneCaptions);
    
    // Log each caption's data to verify
    for (let type in sceneCaptions) {
        const caption = sceneCaptions[type];
        console.log(`📊 Caption ${type}:`, {
            id: caption.id,
            text: caption.text_content?.substring(0, 30),
            image: caption.image_file,
            media_type: caption.media_type,
            position_x: caption.position_x,
            position_y: caption.position_y,
            fontsize: caption.fontsize,
            fontcolor: caption.fontcolor
        });
    }
    
    // Clear ALL objects from canvas
    console.log('🧹 Clearing canvas...');

    // Stop Ken Burns animation from previous scene
    if (window.kenBurnsFrame) {
        cancelAnimationFrame(window.kenBurnsFrame);
        window.kenBurnsFrame = null;
    }

    fabricCanvas.clear();
    currentBackgroundVideo = null;
    currentBackgroundImage = null;
    captionText = null;
    headerText = null;
    footerText = null;
    
    // Hide video play button
    const playButton = document.getElementById('videoPlayButton');
    if (playButton) playButton.style.display = 'none';
    
    // Clean up any existing background video element
    const oldVideo = document.getElementById('backgroundVideo');
    if (oldVideo) {
        oldVideo.pause();
        oldVideo.remove();
    }
    
    // Get the filename for background
    const imageField = currentImageField || 'image_file';
    const filename = scene[imageField];
    
    console.log('📸 Loading media:', {
        field: imageField,
        filename: filename
    });
    
    // CHECK PRELOAD CACHE FIRST
    let mediaLoaded = false;
    
    if (filename && filename.trim() !== '' && window.preloadCache && window.preloadCache[filename]) {
        console.log('📦 Using preloaded media from cache:', filename);
        const cached = window.preloadCache[filename];
        
        try {
            if (cached.tagName === 'VIDEO') {
                // Use cached video
                console.log('🎬 Using cached video');
                await setVideoBackgroundFromCache(cached);
                mediaLoaded = true;
            } else if (cached.tagName === 'IMG') {
                // Use cached image
                console.log('🖼️ Using cached image');
                await setImageBackgroundFromCache(cached);
                mediaLoaded = true;
            }
            
            // Keep in cache for duration of playback — stopPlayback will clear it
            // delete window.preloadCache[filename];
            
        } catch (cacheError) {
            console.error('Failed to use cached media:', cacheError);
            // Fall through to normal loading
        }
    }
    
    // STEP 1: Load background normally if not loaded from cache
    if (!mediaLoaded && filename && filename.trim() !== '') {
        const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
        
        if (isVideo) {
            console.log('🎬 Loading VIDEO from: podcast_videos/' + filename);
            try {
                await setVideoBackground('podcast_videos/' + filename);
            } catch (videoError) {
                console.error('Failed to load video:', videoError);
                fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas));
            }
        } else {
            console.log('🖼️ Loading IMAGE from: podcast_images/' + filename);
            await setImageBackground('podcast_images/' + filename);
        }
    } else if (!mediaLoaded) {
        // No background media, set black background
        fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas));
    }
    
    // Ensure canvas is on top of video
    const canvasEl = document.getElementById('fabricCanvas');
    if (canvasEl) {
        canvasEl.style.position = 'relative';
        
        canvasEl.style.backgroundColor = 'transparent';
    }
    
    // STEP 2: Add captions to canvas (THESE WILL BE ON TOP)
    console.log('📝 Adding captions to canvas...');
    let captionsAdded = 0;
    
    for (let type in sceneCaptions) {
        const caption = sceneCaptions[type];
        
        // Check if this is an image caption
        if (caption.image_file && caption.image_file.trim() !== '') {
            console.log(`🖼️ Adding image caption: ${type}`, {
                id: caption.id,
                image: caption.image_file,
                position: caption.position_x + ',' + caption.position_y
            });
            
            // Add image to canvas
            await addImageBoxToCanvas(caption.id, caption.image_file, false);
            captionsAdded++;
            
        } else if (caption.text_content) {
            // Text caption
            console.log(`📝 Adding text caption: ${type}`, {
                id: caption.id,
                text: caption.text_content.substring(0, 30),
                position: caption.position_x + ',' + caption.position_y,
                fontweight: caption.fontweight,
                fontstyle: caption.fontstyle,
                underline: caption.underline,
                linethrough: caption.linethrough,
                stroke: caption.stroke_color
            });
            
            // Use saved positions from database with ALL styling
            await addMainCaptionToFabric(scene, caption);
            captionsAdded++;
        } else {
            console.log(`⚠️ Skipping caption ${type} - no image or text content`);
        }
    }
    
    console.log(`✅ Added ${captionsAdded} captions to canvas`);
    
    // STEP 3: Add logo if enabled
    if (scene.logo_enabled && scene.logo_name) {
        console.log('➕ Adding logo:', scene.logo_name);
        await addLogoToFabric(scene);
    }
    
    // Force render
    fabricCanvas.renderAll();
    
    // Make non-caption objects non-selectable
    // BUT respect current isSelectionMode for caption objects
    fabricCanvas.discardActiveObject();
    fabricCanvas.forEachObject(obj => {
        if (!obj.captionId) {
            // Background/logo — never selectable
            obj.selectable = false;
            obj.hasControls = false;
            obj.hasBorders = false;
            obj.evented = false;
        } else {
            // Caption — selectable only if we're in selection/typography mode
            obj.selectable = isSelectionMode;
            obj.hasControls = isSelectionMode;
            obj.hasBorders = isSelectionMode;
            obj.evented = true; // always evented so clicks register
        }
    });

    // Restore canvas interactivity based on current mode
    fabricCanvas.selection = false; // never allow drag-selection box
    fabricCanvas.skipTargetFind = !isSelectionMode;
    fabricCanvas.renderAll();
    
    // Clear any selected caption
    selectedCaptionId = null;
    selectedCaptionType = null;
    
    // Show/hide select+delete icons based on whether we're in typography mode
    const selectIcon = document.getElementById('overlay-select');
    const deleteIcon = document.getElementById('overlay-delete');
    if (selectIcon) selectIcon.style.display = isSelectionMode ? 'flex' : 'none';
    if (deleteIcon) deleteIcon.style.display = isSelectionMode ? 'flex' : 'none';
    
    console.log('✅ ===== LOAD SCENE COMPLETE ===== (selectionMode:', isSelectionMode, ')');
}

// ========== SET VIDEO FROM CACHE ==========
function setVideoBackgroundFromCache(cachedVideo) {
    return new Promise((resolve) => {
        console.log('📦 Setting video from cache');
        
        const container = document.getElementById('canvasContainer');
        if (!container) {
            resolve();
            return;
        }
        
        // Remove any existing video
        const oldVideo = document.getElementById('backgroundVideo');
        if (oldVideo) {
            oldVideo.remove();
        }
        
        // Create new video element with cached source
        const video = document.createElement('video');
        video.id = 'backgroundVideo';
        video.muted = true;
        video.loop = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1;
            pointer-events: none;
        `;
        
        // Use the cached source
        video.src = cachedVideo.src;
        container.appendChild(video);
        
        // Ensure canvas is on top
        const canvasEl = document.getElementById('fabricCanvas');
        if (canvasEl) {
            canvasEl.style.position = 'relative';
            
            canvasEl.style.backgroundColor = 'transparent';
        }
        
        // Play immediately (should be instant since preloaded)
        video.play()
            .then(() => {
                console.log('▶️ Cached video playing');
                currentBackgroundVideo = video;
                
                // Clear any existing background image
                if (currentBackgroundImage) {
                    fabricCanvas.remove(currentBackgroundImage);
                    currentBackgroundImage = null;
                }
                
                resolve();
            })
            .catch(err => {
                console.error('❌ Cached video play failed:', err);
                resolve();
            });
    });
}

// ========== SET IMAGE FROM CACHE ==========
function setImageBackgroundFromCache(cachedImage) {
    return new Promise((resolve) => {
        console.log('📦 Setting image from cache');
        
        if (!fabricCanvas) {
            resolve();
            return;
        }
        
        // Stop and hide any video element
        const videoElement = document.getElementById('backgroundVideo');
        if (videoElement) {
            videoElement.pause();
            videoElement.remove();
        }
        
        // Hide video play button
        const playButton = document.getElementById('videoPlayButton');
        if (playButton) {
            playButton.style.display = 'none';
        }
        
        // Force remove any existing video from fabric canvas
        if (currentBackgroundVideo) {
            fabricCanvas.remove(currentBackgroundVideo);
            currentBackgroundVideo = null;
        }
        
        // Remove any existing image
        if (currentBackgroundImage) {
            fabricCanvas.remove(currentBackgroundImage);
            currentBackgroundImage = null;
        }
        
        // Clone the preloaded <img> directly into Fabric — no re-fetch, no flicker
        const clonedImg = cachedImage.cloneNode();
        const fabricImg = new fabric.Image(clonedImg);

        const canvasWidth  = fabricCanvas.width;
        const canvasHeight = fabricCanvas.height;

        const scale = Math.max(
            canvasWidth  / fabricImg.width,
            canvasHeight / fabricImg.height
        );

        const left = (canvasWidth  - fabricImg.width  * scale) / 2;
        const top  = (canvasHeight - fabricImg.height * scale) / 2;

        fabricImg.set({
            left, top, scaleX: scale, scaleY: scale,
            selectable: false, evented: false,
            hasControls: false, hasBorders: false,
            originX: 'left', originY: 'top'
        });

        fabricCanvas.add(fabricImg);
        fabricCanvas.sendToBack(fabricImg);
        currentBackgroundImage = fabricImg;
        fabricCanvas.renderAll();

        // Apply Ken Burns effect (default: zoom-in)
        const scene = scenes.find(s => s.id == currentSceneId);
        const kenEffect = scene?.ken_burns_effect || 'zoom-in';
        applyKenBurnsToCanvas(fabricImg, kenEffect);

        resolve();
    });
}
// Ultra simple video background function
// ========== SET VIDEO BACKGROUND ==========
function setVideoBackground(fullPath) {
    return new Promise((resolve) => {
        console.log('🎬 Setting video background with path:', fullPath);
        
        // Remove any existing video background
        if (currentBackgroundVideo) {
            // Remove old video element if it exists
            const oldVideo = document.getElementById('backgroundVideo');
            if (oldVideo) oldVideo.remove();
            currentBackgroundVideo = null;
        }
        
        // Get the container
        const container = document.getElementById('canvasContainer');
        if (!container) {
            console.error('Canvas container not found');
            resolve();
            return;
        }
        
        // Create or get video element
        let video = document.getElementById('backgroundVideo');
        if (!video) {
            video = document.createElement('video');
            video.id = 'backgroundVideo';
            container.appendChild(video);
        }
        
        // Style the video
        video.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1;
            pointer-events: none;
        `;
        
        // Set video source
        video.src = fullPath + '?t=' + Date.now();
        video.muted = true;
        video.loop = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.load();
        
        // Ensure canvas is on top
        const canvasEl = document.getElementById('fabricCanvas');
        if (canvasEl) {
            canvasEl.style.position = 'relative';
            
            canvasEl.style.backgroundColor = 'transparent';
        }
        
        video.onloadedmetadata = () => {
            console.log('✅ Video metadata loaded');
            
            video.play()
                .then(() => {
                    console.log('▶️ Video playing');
                    currentBackgroundVideo = video; // Store reference
                    
                    // Clear any existing background from fabric
                    if (currentBackgroundImage) {
                        fabricCanvas.remove(currentBackgroundImage);
                        currentBackgroundImage = null;
                    }
                    
                    resolve();
                })
                .catch(err => {
                    console.error('❌ Video play failed:', err);
                    resolve();
                });
        };
        
        video.onerror = (e) => {
            console.error('❌ Video error:', e);
            resolve();
        };
    });
}

// Add this function to clean up video when changing scenes
function cleanupVideo() {
    const oldVideo = document.getElementById('backgroundVideo');
    if (oldVideo) {
        oldVideo.pause();
        oldVideo.remove();
    }
    currentBackgroundVideo = null;
}


// Add this debug function

// ========== SIMPLE NAVIGATION WITH PRELOAD ==========
async function navigateScene(direction) {
    console.log('navigateScene called with:', direction);
    if (scenes.length === 0) return;
    
    // Save current scene
    await saveCurrentImageField();
    
    // Calculate new index
    let newIndex;
    if (direction === 'prev') {
        newIndex = (currentSceneIndex - 1 + scenes.length) % scenes.length;
    } else {
        newIndex = (currentSceneIndex + 1) % scenes.length;
    }
    
    const newScene = scenes[newIndex];
    const filename = newScene.image_file;
    
    console.log('Navigating to scene:', newIndex + 1);
    
    // STEP 1: If it's a video, preload it first (while current video still plays)
    if (filename && filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i)) {
        console.log('Preloading video:', filename);
        
        // Create a hidden video element to preload
        const preloadVideo = document.createElement('video');
        preloadVideo.preload = 'auto';
        preloadVideo.src = 'podcast_videos/' + filename + '?t=' + Date.now();
        preloadVideo.load();
        
        // Wait a tiny bit for it to start loading
        await new Promise(r => setTimeout(r, 100));
    }
    
    // STEP 2: Now do the actual navigation
    // Remove old video
    const oldVideo = document.getElementById('backgroundVideo');
    if (oldVideo) {
        oldVideo.remove();
    }
    
    if (currentBackgroundVideo) {
        fabricCanvas.remove(currentBackgroundVideo);
        currentBackgroundVideo = null;
    }
    
    // Update indices
    currentSceneIndex = newIndex;
    currentSceneId = newScene.id;
    currentImageField = 'image_file';
    
    // Load new scene
    if (fabricCanvas) {
        await loadCurrentSceneToFabric();
    }
    
    updateSceneIndicator();
    console.log('✅ Navigation complete');
}




// Add button

// Auto-select the main caption when scene loads
function autoSelectMainCaption() {
    console.log('🔍 ===== AUTO-SELECT MAIN CAPTION =====');
    
    if (!fabricCanvas) {
        console.log('❌ fabricCanvas not initialized');
        return;
    }
    
    // Find all caption objects on canvas
    const objects = fabricCanvas.getObjects();
    console.log(`📊 Total objects on canvas: ${objects.length}`);
    
    // Log all objects for debugging
    objects.forEach((obj, index) => {
        if (obj.captionType) {
            console.log(`📝 Caption object ${index}:`, {
                captionId: obj.captionId,
                captionType: obj.captionType,
                text: obj.text ? obj.text.substring(0, 50) : 'NO TEXT',
                left: Math.round(obj.left),
                top: Math.round(obj.top),
                visible: obj.visible,
                selectable: obj.selectable
            });
        }
    });
    
    // Find the main caption object
    const mainCaptionObj = objects.find(obj => obj.captionType === 'main');
    
    if (mainCaptionObj) {
        console.log('✅ Found main caption object:');
        console.log('   - Caption ID:', mainCaptionObj.captionId);
        console.log('   - Caption Type:', mainCaptionObj.captionType);
        console.log('   - Text:', mainCaptionObj.text ? mainCaptionObj.text.substring(0, 100) : 'NO TEXT');
        console.log('   - Position:', mainCaptionObj.left, mainCaptionObj.top);
        console.log('   - Font Size:', mainCaptionObj.fontSize);
        console.log('   - Font Family:', mainCaptionObj.fontFamily);
        console.log('   - Text Color:', mainCaptionObj.fill);
        
        // Select the object on canvas
        fabricCanvas.setActiveObject(mainCaptionObj);
        fabricCanvas.renderAll();
        
        // Set selectedCaptionId
        selectedCaptionId = mainCaptionObj.captionId;
        selectedCaptionType = 'main';
        
        console.log('✅ Auto-selected main caption, ID:', selectedCaptionId);
        
        // If typography panel is open, update it
        const typographyPanel = document.getElementById('scene-typography-captions');
        const settingsPanel = document.getElementById('sceneSettingsPanel');
        
        if (typographyPanel && !typographyPanel.classList.contains('hidden') && 
            settingsPanel && settingsPanel.classList.contains('active')) {
            
            const caption = findCaptionById(selectedCaptionId);
            if (caption) {
                console.log('📋 Loading caption settings for panel:', caption);
                loadCaptionSettingsToPanel(caption);
                updateCaptionPanelHeader(caption);
            }
        }
    } else {
        console.log('⚠️ No main caption object found on canvas');
        
        // Log all caption types present
        const captionTypes = objects
            .filter(obj => obj.captionType)
            .map(obj => obj.captionType);
        
        if (captionTypes.length > 0) {
            console.log('📋 Available caption types:', captionTypes.join(', '));
            
            // Try to select the first available caption
            const firstCaption = objects.find(obj => obj.captionType);
            if (firstCaption) {
                console.log('✅ Selecting first available caption:', firstCaption.captionType);
                fabricCanvas.setActiveObject(firstCaption);
                fabricCanvas.renderAll();
                
                selectedCaptionId = firstCaption.captionId;
                selectedCaptionType = firstCaption.captionType;
            }
        } else {
            console.log('❌ No caption objects found on canvas at all');
        }
    }
    
    console.log('🔍 ===== AUTO-SELECT COMPLETE =====');
}


// Toggle global caption status
// ========== TOGGLE GLOBAL CAPTION ==========
async function toggleGlobalCaption(isGlobal) {
    if (!selectedCaptionId) {
        alert('Please select a caption first');
        document.getElementById('makeCaptionGlobal').checked = false;
        return;
    }
    
    const caption = findCaptionById(selectedCaptionId);
    
    // Check if this is the main caption
    if (caption && caption.caption_type === 'main') {
        // Main caption has special behavior
        if (isGlobal) {
            if (!confirm('⚠️ This will apply the current styling of the main caption to ALL scenes. Each scene will keep its own text but use your font/style settings. Continue?')) {
                document.getElementById('makeCaptionGlobal').checked = false;
                return;
            }
            
            // Show different warning for main caption
            const warningDiv = document.getElementById('globalCaptionWarning');
            warningDiv.innerHTML = '⚠️ Main caption styling will be applied to all scenes. Text content remains scene-specific.';
            warningDiv.style.display = 'block';
            
            // Save the global status (even though main caption isn't truly global)
            await saveCaptionGlobalStatus(selectedCaptionId, 1);
            
            // Apply styling to all scenes
            await duplicateCaptionToAllScenes(selectedCaptionId);
            
        } else {
            const warningDiv = document.getElementById('globalCaptionWarning');
            warningDiv.style.display = 'none';
            
            await saveCaptionGlobalStatus(selectedCaptionId, 0);
            // No need to remove from other scenes for main caption
        }
        return;
    }
    
    // Handle user-generated captions (text/image)
    const warningDiv = document.getElementById('globalCaptionWarning');
    
    if (isGlobal) {
        // Show warning and ask for confirmation
        if (!confirm('⚠️ This will place this caption in ALL scenes with the same settings. Continue?')) {
            document.getElementById('makeCaptionGlobal').checked = false;
            return;
        }
        warningDiv.style.display = 'block';
        
        // Save the global status
        await saveCaptionGlobalStatus(selectedCaptionId, 1);
        
        // Duplicate to all scenes
        await duplicateCaptionToAllScenes(selectedCaptionId);
    } else {
        warningDiv.style.display = 'none';
        
        // Ask if they want to remove from other scenes
        if (confirm('Remove this caption from other scenes and keep only in current scene?')) {
            await saveCaptionGlobalStatus(selectedCaptionId, 0);
            await removeCaptionFromOtherScenes(selectedCaptionId);
        } else {
            // User canceled, keep checkbox checked
            document.getElementById('makeCaptionGlobal').checked = true;
        }
    }
}
// Save global status to database
async function saveCaptionGlobalStatus(captionId, isGlobal) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_caption_global');
        fd.append('caption_id', captionId);
        fd.append('is_global', isGlobal);
        
        const {data} = await safeFetch(fd);
        return data.success;
    } catch (e) {
        console.error('Error saving global status:', e);
        return false;
    }
}

// Duplicate caption to all scenes
// ========== DUPLICATE CAPTION TO ALL SCENES ==========
async function duplicateCaptionToAllScenes(sourceCaptionId) {
    if (!scenes.length) return;
    
    // Get the source caption data
    const sourceCaption = findCaptionById(sourceCaptionId);
    if (!sourceCaption) {
        console.error('Source caption not found');
        return;
    }
    
    // Check if this is the main caption
    const isMainCaption = (sourceCaption.caption_type === 'main');
    
    if (isMainCaption) {
        L('🔄 Applying main caption STYLING to ALL scenes (text will NOT be copied)...');
    } else {
        L('🔄 Duplicating user caption (including text) to all scenes...');
    }
    
    let successCount = 0;
    let errorCount = 0;
    
    for (const scene of scenes) {
        // Skip the current scene (already has it)
        if (scene.id == currentSceneId) continue;
        
        try {
            if (isMainCaption) {
                // MAIN CAPTION: Update existing main caption in each scene (styling only)
                await updateMainCaptionInScene(scene.id, sourceCaption);
                successCount++;
            } else {
                // USER CAPTION: Create new caption in each scene (full copy including text)
                await createUserCaptionInScene(scene.id, sourceCaption);
                successCount++;
            }
        } catch (e) {
            console.error('Error processing scene', scene.id, e);
            errorCount++;
        }
        
        // Small delay to avoid overwhelming the server
        await new Promise(r => setTimeout(r, 100));
    }
    
    L(`✅ Processed ${successCount} scenes (${errorCount} failed)`);
    
    if (isMainCaption) {
        alert(`✅ Main caption STYLING applied to ${successCount} other scenes successfully!\n\n(Each scene keeps its own text content)`);
    } else {
        if (successCount > 0) {
            alert(`✅ User caption duplicated to ${successCount} other scenes successfully!`);
        } else {
            alert('No new captions were created. They may already exist in other scenes.');
        }
    }
}
// ========== UPDATE MAIN CAPTION IN SCENE ==========
// ========== UPDATE MAIN CAPTION IN SCENE ==========
// ========== UPDATE MAIN CAPTION IN SCENE ==========
async function updateMainCaptionInScene(targetSceneId, sourceCaption) {
    console.log('=========================================');
    console.log(`🔄 DEBUG: updateMainCaptionInScene START for scene ${targetSceneId}`);
    console.log('SOURCE CAPTION DATA:', JSON.stringify(sourceCaption, null, 2));
    
    try {
        // First, find the main caption in the target scene
        console.log(`📡 DEBUG: Fetching captions for scene ${targetSceneId}...`);
        const checkFd = new FormData();
        checkFd.append('ajax_action', 'get_scene_captions');
        checkFd.append('scene_id', targetSceneId);
        
        const {data} = await safeFetch(checkFd);
        console.log(`📥 DEBUG: Captions for scene ${targetSceneId}:`, data);
        
        if (data.success && data.captions) {
            // Find the main caption in the target scene
            const targetMainCaption = data.captions.find(c => c.caption_type === 'main');
            
            if (targetMainCaption) {
                console.log(`✅ DEBUG: Found target main caption:`, {
                    id: targetMainCaption.id,
                    current_text: targetMainCaption.text_content?.substring(0, 50),
                    current_font: targetMainCaption.fontfamily
                });
                
                // Update the existing main caption with source styling
                const updateFd = new FormData();
                updateFd.append('ajax_action', 'update_main_caption_styling');
                updateFd.append('caption_id', targetMainCaption.id);
                
                // Copy ONLY styling properties (EXCLUDE text_content)
                const styleProperties = [
                    'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 'fontstyle',
                    'underline', 'linethrough', 'text_align', 'bg_color', 'bg_enabled',
                    'outline_color', 'outline_width', 'outline_enabled',
                    'stroke_color', 'stroke_width', 'stroke_enabled',
                    'position_x', 'position_y', 'width', 'rotation',
                    'animation_style', 'animation_speed', 'is_visible', 'z_index'
                ];
                
                console.log('📤 DEBUG: Sending styling updates:');
                styleProperties.forEach(key => {
                    if (sourceCaption[key] !== null && sourceCaption[key] !== undefined) {
                        updateFd.append(key, sourceCaption[key]);
                        console.log(`  - ${key}: ${sourceCaption[key]}`);
                    }
                });
                
                // CRITICAL: Verify text_content is NOT being sent
                console.log('🔍 DEBUG: Checking if text_content is in FormData:');
                console.log('  - text_content present?', updateFd.has('text_content') ? 'YES ❌' : 'NO ✅');
                
                // Log all FormData entries before sending
                console.log('📤 DEBUG: Complete FormData being sent:');
                for (let pair of updateFd.entries()) {
                    console.log(`  ${pair[0]}: ${pair[1]}`);
                }
                
                const updateResult = await safeFetch(updateFd);
                console.log('📥 DEBUG: Update response:', updateResult);
                
                if (!updateResult.data.success) {
                    throw new Error(updateResult.data.message || 'Failed to update main caption');
                }
                
                console.log(`✅ DEBUG: Successfully updated main caption styling in scene ${targetSceneId}`);
                
                // Verify the update by fetching again
                console.log(`📡 DEBUG: Verifying update for scene ${targetSceneId}...`);
                const verifyFd = new FormData();
                verifyFd.append('ajax_action', 'get_scene_captions');
                verifyFd.append('scene_id', targetSceneId);
                
                const verifyResult = await safeFetch(verifyFd);
                if (verifyResult.data.success && verifyResult.data.captions) {
                    const updatedCaption = verifyResult.data.captions.find(c => c.caption_type === 'main');
                    if (updatedCaption) {
                        console.log(`🔍 DEBUG: After update - Caption text: "${updatedCaption.text_content?.substring(0, 50)}"`);
                        console.log(`🔍 DEBUG: After update - Font family: ${updatedCaption.fontfamily}`);
                        console.log(`🔍 DEBUG: Text changed?`, {
                            before: targetMainCaption.text_content?.substring(0, 50),
                            after: updatedCaption.text_content?.substring(0, 50),
                            changed: targetMainCaption.text_content !== updatedCaption.text_content ? 'YES ❌' : 'NO ✅'
                        });
                    }
                }
                
            } else {
                console.log(`⚠️ DEBUG: No main caption found in scene ${targetSceneId}`);
                throw new Error('Main caption not found in target scene');
            }
        }
    } catch (e) {
        console.error('❌ DEBUG: Error in updateMainCaptionInScene:', e);
        throw e;
    }
    
    console.log('=========================================');
}
// ========== CREATE USER CAPTION IN SCENE ==========
// ========== CREATE USER CAPTION IN SCENE ==========
async function createUserCaptionInScene(targetSceneId, sourceCaption) {
    console.log(`🔄 Creating user caption in scene ${targetSceneId}`);
    
    try {
        // First check if caption of same type already exists
        const checkFd = new FormData();
        checkFd.append('ajax_action', 'check_caption_type_exists');
        checkFd.append('story_id', targetSceneId);
        checkFd.append('caption_type', sourceCaption.caption_type);
        
        const checkResult = await safeFetch(checkFd);
        
        // If exists, we might want to skip or update? For now, skip
        if (checkResult.data.exists) {
            console.log(`Caption type ${sourceCaption.caption_type} already exists in scene ${targetSceneId}, skipping`);
            return;
        }
        
        // Get the podcast_id from the scene
        const targetScene = scenes.find(s => s.id == targetSceneId);
        const podcastId = targetScene ? targetScene.podcast_id : currentPodcastId;
        
        // Create a new caption in the target scene
        const fd = new FormData();
        fd.append('ajax_action', 'create_caption');
        fd.append('story_id', targetSceneId);
        fd.append('podcast_id', podcastId); // ADD THIS LINE
        fd.append('caption_type', sourceCaption.caption_type);
        fd.append('caption_name', sourceCaption.caption_name + '_copy');
        fd.append('media_type', sourceCaption.media_type || 'text');
        
        const {data} = await safeFetch(fd);
        
        if (data.success) {
            // Now update the new caption with all the source settings
            const updateFd = new FormData();
            updateFd.append('ajax_action', 'save_caption');
            updateFd.append('caption_id', data.caption_id);
            
            // Copy ALL properties including text_content (for user captions)
            const propertiesToCopy = [
                'text_content', 'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 
                'fontstyle', 'underline', 'linethrough', 'text_align', 'bg_color', 
                'bg_enabled', 'outline_color', 'outline_width', 'outline_enabled',
                'stroke_color', 'stroke_width', 'stroke_enabled', 'position_x', 
                'position_y', 'width', 'rotation', 'animation_style', 'animation_speed',
                'image_file', 'media_type'
            ];
            
            propertiesToCopy.forEach(key => {
                if (sourceCaption[key] !== null && sourceCaption[key] !== undefined) {
                    updateFd.append(key, sourceCaption[key]);
                }
            });
            
            const updateResult = await safeFetch(updateFd);
            if (!updateResult.data.success) {
                throw new Error(updateResult.data.message || 'Failed to update new caption');
            }
            
            console.log(`✅ Created user caption in scene ${targetSceneId} with all attributes (including text)`);
        } else {
            throw new Error(data.message || 'Failed to create caption');
        }
    } catch (e) {
        console.error('Error creating user caption in scene', targetSceneId, e);
        throw e;
    }
}
// Remove caption from other scenes
async function removeCaptionFromOtherScenes(captionId) {
    if (!scenes.length) return;
    
    L('🗑️ Removing caption from other scenes...');
    
    // Get the source caption to identify its type
    const sourceCaption = findCaptionById(captionId);
    if (!sourceCaption) return;
    
    let removedCount = 0;
    
    for (const scene of scenes) {
        // Skip the current scene
        if (scene.id == currentSceneId) continue;
        
        try {
            // Find and delete captions of the same type in other scenes
            const fd = new FormData();
            fd.append('ajax_action', 'delete_caption_by_type');
            fd.append('story_id', scene.id);
            fd.append('caption_type', sourceCaption.caption_type);
            
            const {data} = await safeFetch(fd);
            if (data.success && data.deleted > 0) {
                removedCount += data.deleted;
            }
        } catch (e) {
            console.error('Error removing from scene', scene.id, e);
        }
    }
    
    L(`✅ Removed from ${removedCount} other scenes`);
}
// Add this right after your addMainCaptionToFabric function to debug text creation
function debugTextCreation() {
    console.log('🎨 ===== TEXT CREATION DEBUG =====');
    
    // Check if sceneCaptions has data
    console.log('sceneCaptions:', sceneCaptions);
    
    if (sceneCaptions['main']) {
        console.log('Main caption data:', {
            text_content: sceneCaptions['main'].text_content,
            fontsize: sceneCaptions['main'].fontsize,
            fontcolor: sceneCaptions['main'].fontcolor,
            position_x: sceneCaptions['main'].position_x,
            position_y: sceneCaptions['main'].position_y
        });
    } else {
        console.log('❌ No main caption found in sceneCaptions');
    }
    
    // Check fabric canvas state
    if (fabricCanvas) {
        console.log('Canvas dimensions:', fabricCanvas.width, 'x', fabricCanvas.height);
        console.log('Canvas objects:', fabricCanvas.getObjects().length);
        
        fabricCanvas.getObjects().forEach((obj, i) => {
            if (obj.type === 'textbox' || obj.type === 'text') {
                console.log(`Text object ${i}:`, {
                    text: obj.text ? obj.text.substring(0, 30) : 'NO TEXT',
                    left: obj.left,
                    top: obj.top,
                    fontSize: obj.fontSize,
                    fill: obj.fill,
                    visible: obj.visible,
                    opacity: obj.opacity
                });
            }
        });
    }
    
    console.log('🎨 ===== END DEBUG =====');
}

// Call this after scene loads
setTimeout(debugTextCreation, 3000);





// Set image background - WITH PROPER SIZING
// ========== KEN BURNS EFFECT ==========
// effect values stored in hdb_podcast_stories.ken_burns_effect:
//   zoom-in   — slowly zoom in to centre (default)
//   zoom-out  — start zoomed in, slowly pull back
//   pan-left  — pan from right to left
//   pan-right — pan from left to right
//   pan-up    — pan from bottom to top
//   pan-down  — pan from top to bottom
//   zoom-pan  — zoom in while panning diagonally
function applyKenBurnsToCanvas(img, effect) {
    // Cancel any running Ken Burns animation
    if (window.kenBurnsFrame) {
        cancelAnimationFrame(window.kenBurnsFrame);
        window.kenBurnsFrame = null;
    }

    if (!img || !fabricCanvas) return;

    const cW = fabricCanvas.width;
    const cH = fabricCanvas.height;

    // Base scale = exactly covers canvas
    const baseScale = Math.max(cW / img.width, cH / img.height);
    // Zoomed scale = 20% larger to allow movement
    const zoomScale = baseScale * 1.2;

    const duration = 6000; // ms — one full Ken Burns cycle
    const startTime = performance.now();

    // Define start/end state for each effect
    const states = {
        'zoom-in':   { ss: baseScale, es: zoomScale, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },
        'zoom-out':  { ss: zoomScale, es: baseScale, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },
        'pan-left':  { ss: zoomScale, es: zoomScale, sl: cW/2 + (img.width*zoomScale - cW)/2, el: cW/2 - (img.width*zoomScale - cW)/2, st: cH/2, et: cH/2 },
        'pan-right': { ss: zoomScale, es: zoomScale, sl: cW/2 - (img.width*zoomScale - cW)/2, el: cW/2 + (img.width*zoomScale - cW)/2, st: cH/2, et: cH/2 },
        'pan-up':    { ss: zoomScale, es: zoomScale, sl: cW/2, el: cW/2, st: cH/2 + (img.height*zoomScale - cH)/2, et: cH/2 - (img.height*zoomScale - cH)/2 },
        'pan-down':  { ss: zoomScale, es: zoomScale, sl: cW/2, el: cW/2, st: cH/2 - (img.height*zoomScale - cH)/2, et: cH/2 + (img.height*zoomScale - cH)/2 },
        'zoom-pan':  { ss: baseScale, es: zoomScale, sl: cW/2 - 40, el: cW/2 + 40, st: cH/2 + 30, et: cH/2 - 30 }
    };

    const s = states[effect] || states['zoom-in'];

    // Set initial position
    img.set({ scaleX: s.ss, scaleY: s.ss, left: s.sl, top: s.st, originX: 'center', originY: 'center' });
    img.setCoords();
    fabricCanvas.renderAll();

    function step(now) {
        // Stop if image was removed from canvas (scene changed)
        if (!currentBackgroundImage || currentBackgroundImage !== img) return;

        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Ease in-out
        const eased = progress < 0.5
            ? 2 * progress * progress
            : 1 - Math.pow(-2 * progress + 2, 2) / 2;

        img.set({
            scaleX: s.ss + (s.es - s.ss) * eased,
            scaleY: s.ss + (s.es - s.ss) * eased,
            left:   s.sl + (s.el - s.sl) * eased,
            top:    s.st + (s.et - s.st) * eased
        });
        img.setCoords();
        fabricCanvas.renderAll();

        if (progress < 1) {
            window.kenBurnsFrame = requestAnimationFrame(step);
        }
    }

    window.kenBurnsFrame = requestAnimationFrame(step);
}

function setImageBackground(fullPath) {
	
	 trackUpdate('setImageBackground'); // ADD THIS
    return new Promise((resolve) => {
        console.log('🖼️ setImageBackground START - path:', fullPath);
        
        // CRITICAL: Stop and hide the actual video element
        const videoElement = document.getElementById('hiddenBackgroundVideo');
        if (videoElement) {
            console.log('🎬 Stopping and clearing video element');
            videoElement.pause();
            videoElement.removeAttribute('src');
            videoElement.load();
            videoElement.style.display = 'none';
        }
        
        // Hide video play button
        const playButton = document.getElementById('videoPlayButton');
        if (playButton) {
            playButton.style.display = 'none';
        }
        
        // Force remove any existing video from fabric canvas
        if (currentBackgroundVideo) {
            fabricCanvas.remove(currentBackgroundVideo);
            currentBackgroundVideo = null;
        }
        
        // Remove any existing image
        if (currentBackgroundImage) {
            fabricCanvas.remove(currentBackgroundImage);
            currentBackgroundImage = null;
        }
        
        console.log('Loading image from:', fullPath);
        
        fabric.Image.fromURL(fullPath, (img) => {
            if (!img) {
                console.error('❌ Failed to load image');
                fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas));
                resolve();
                return;
            }
            
            console.log('✅ Image loaded:', {
                width: img.width,
                height: img.height
            });
            
            const canvasWidth = fabricCanvas.width;
            const canvasHeight = fabricCanvas.height;
            
            // Calculate scale to COVER the entire canvas (like object-fit: cover)
            const scale = Math.max(
				canvasWidth / img.width,
				canvasHeight / img.height
			);
            
            // Calculate position to center the image
            const left = (canvasWidth - img.width * scale) / 2;
            const top = (canvasHeight - img.height * scale) / 2;
            
            console.log('Image positioning:', {
                scale: scale,
                left: left,
                top: top,
                finalWidth: img.width * scale,
                finalHeight: img.height * scale,
                canvasWidth: canvasWidth,
                canvasHeight: canvasHeight
            });
            
            img.set({
                left: left,
                top: top,
                scaleX: scale,
                scaleY: scale,
                selectable: false,
                evented: false,
                hasControls: false,
                hasBorders: false,
                originX: 'left',
                originY: 'top'
            });
            
            fabricCanvas.add(img);
            fabricCanvas.sendToBack(img);
            currentBackgroundImage = img;
            
            // Force render
            fabricCanvas.renderAll();

            // Apply Ken Burns effect based on scene setting (default: zoom-in)
            const scene = scenes.find(s => s.id == currentSceneId);
            const kenEffect = scene?.ken_burns_effect || 'zoom-in';
            applyKenBurnsToCanvas(img, kenEffect);
            
            resolve();
            
        }, { 
            crossOrigin: 'anonymous',
            maxWidth: fabricCanvas.width * 2, // Limit max size for performance
            maxHeight: fabricCanvas.height * 2
        });
    });
}


// Update outline settings




// ========== EFFECT SETTINGS UPDATE LISTENERS ==========


// Helper function to update style button appearance
function updateStyleButtonAppearance() {
    if (!captionText) return;
    
    // We don't have separate style buttons anymore, but we can update the dropdown
    // This is handled by toggleTextStyle function
}

// Helper function to update effect button appearance
function updateEffectButtonAppearance() {
    if (!captionText) return;
    
    // Update effect settings visibility based on current text state
    const shadowSettings = document.getElementById('shadowSettings');
    const gradientSettings = document.getElementById('gradientSettings');
    const outlineSettings = document.getElementById('outlineSettings');
    const strokeSettings = document.getElementById('strokeSettings');
    
    if (shadowSettings) {
        shadowSettings.style.display = (captionText.shadow && captionText.shadow !== null) ? 'block' : 'none';
    }
    
    if (gradientSettings) {
        gradientSettings.style.display = (captionText.fill && captionText.fill.type === 'linear') ? 'block' : 'none';
    }
    
    if (outlineSettings || strokeSettings) {
        const hasOutline = captionText.stroke && captionText.strokeWidth > 0;
        if (outlineSettings) outlineSettings.style.display = hasOutline ? 'block' : 'none';
        if (strokeSettings) strokeSettings.style.display = hasOutline ? 'block' : 'none';
    }
}

// Helper function to highlight active alignment in dropdown
function highlightAlignmentButton(alignment) {
    // Remove active class from all alignment dropdown items
    document.querySelectorAll('#alignDropdown .dropdown-item').forEach(item => {
        item.style.background = 'transparent';
        item.style.fontWeight = 'normal';
    });
    
    // Highlight the active one based on the emoji in the text
    const alignmentMap = {
        'left': '⬅️',
        'center': '⬆️',
        'right': '➡️',
        'justify': '⬌️'
    };
    
    const emoji = alignmentMap[alignment];
    if (emoji) {
        document.querySelectorAll('#alignDropdown .dropdown-item').forEach(item => {
            if (item.innerHTML.includes(emoji)) {
                item.style.background = '#e0f2fe';
                item.style.fontWeight = '600';
            }
        });
    }
}

// Add this function to update the main button emoji based on selection
function updateMainButtonEmoji(buttonId, emoji, text) {
    const button = document.querySelector(`[onclick="toggleDropdown('${buttonId}')"]`);
    if (button) {
        const emojiSpan = button.querySelector('span:first-child');
        if (emojiSpan) {
            emojiSpan.innerText = emoji;
        }
    }
}




// Force selection on mobile when tapping
function enableMobileSelection() {
    if (!fabricCanvas) return;
    
    // Make all text objects easier to select on mobile
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            // Increase hit area on mobile
            obj.set({
                touchCornerSize: 20,
                cornerSize: 20
            });
        }
    });
    
    fabricCanvas.renderAll();
}

// Call it after canvas loads
setTimeout(enableMobileSelection, 2000);






// Then define toggleEffect


// Then define saveCombinedSettings
async function saveCombinedSettings() {
    if (!currentSceneId) return;
    
    // Save typography settings
    const typographySettings = {
        fontfamily: document.getElementById('sceneFontFamily')?.value || 'Inter',
        fontsize: parseInt(document.getElementById('sceneFontSize')?.value) || 28,
        fontcolor: document.getElementById('sceneFontColor')?.value || '#ffffff',
        fontcolor_bg: document.getElementById('sceneFontBgColor')?.value || '#000000',
        fontweight: document.getElementById('sceneFontWeight')?.value || '700',
        fontbg_enable: document.getElementById('sceneFontBgEnable')?.checked ? 1 : 0
    };
    
    // Save caption settings
    const captionSettings = {
        caption_style: document.getElementById('sceneCaptionStyle')?.value || 'typewriter',
        caption_position: document.getElementById('sceneCaptionPosition')?.value || 'bottom',
        caption_speed: parseFloat(document.getElementById('sceneCaptionSpeed')?.value) || 0.85,
        caption_alignment: document.getElementById('sceneCaptionAlignment')?.value || 'center'
    };
    
    // Save effect settings if they exist on the text object
    if (captionText) {
        // Save text position
        typographySettings.text_x = Math.round(captionText.left);
        typographySettings.text_y = Math.round(captionText.top);
        typographySettings.text_width = Math.round(captionText.width);
        typographySettings.text_rotation = captionText.angle || 0;
        
        // Save shadow
        if (captionText.shadow) {
            typographySettings.shadow = JSON.stringify(captionText.shadow);
        }
        
        // Save gradient
        if (captionText.fill && captionText.fill.type === 'linear') {
            typographySettings.gradient = JSON.stringify(captionText.fill);
        }
        
        // Save stroke/outline
        if (captionText.stroke) {
            typographySettings.stroke = captionText.stroke;
            typographySettings.strokeWidth = captionText.strokeWidth;
        }
    }
    
    // Combine all settings
    const allSettings = { ...typographySettings, ...captionSettings };
    
    // Update local sceneSettings
    if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
    sceneSettings[currentSceneId].typography = typographySettings;
    sceneSettings[currentSceneId].captions = captionSettings;
    
    // Save to database
    await saveAllSettingsToDB(currentSceneId, allSettings);
    
    // Update the scene in memory
    const scene = scenes.find(s => s.id == currentSceneId);
    if (scene) {
        Object.assign(scene, allSettings);
    }
    
    L(`✅ Text & Caption settings saved for scene #${currentSceneId}`);
    updateOverlayBadges(currentSceneId);
    closeScenePanel();
}



function addEffectListeners() {
    // Shadow listeners
    ['shadowOffsetX', 'shadowOffsetY', 'shadowBlur', 'shadowColor'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', function() {
            if (captionText && captionText.shadow) {
                captionText.set('shadow', {
                    color: document.getElementById('shadowColor').value,
                    blur: parseInt(document.getElementById('shadowBlur').value),
                    offsetX: parseInt(document.getElementById('shadowOffsetX').value),
                    offsetY: parseInt(document.getElementById('shadowOffsetY').value)
                });
                fabricCanvas.renderAll();
            }
        });
    });
    
    // Gradient listeners
    ['gradientColor1', 'gradientColor2', 'gradientDirection'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', function() {
            if (captionText && captionText.fill && captionText.fill.type === 'linear') {
                const color1 = document.getElementById('gradientColor1').value;
                const color2 = document.getElementById('gradientColor2').value;
                const direction = document.getElementById('gradientDirection').value;
                
                let coords;
                if (direction === 'left-to-right') {
                    coords = { x1: 0, y1: 0, x2: captionText.width, y2: 0 };
                } else if (direction === 'top-to-bottom') {
                    coords = { x1: 0, y1: 0, x2: 0, y2: captionText.height };
                } else {
                    coords = { x1: 0, y1: 0, x2: captionText.width, y2: captionText.height };
                }
                
                captionText.set('fill', new fabric.Gradient({
                    type: 'linear',
                    coords: coords,
                    colorStops: [
                        { offset: 0, color: color1 },
                        { offset: 1, color: color2 }
                    ]
                }));
                fabricCanvas.renderAll();
            }
        });
    });
    
    // Outline listeners
    document.getElementById('outlineColor')?.addEventListener('input', function() {
        if (captionText && captionText.stroke) {
            captionText.set('stroke', this.value);
            fabricCanvas.renderAll();
        }
    });
    
    document.getElementById('outlineWidth')?.addEventListener('input', function() {
        if (captionText && captionText.stroke) {
            captionText.set('strokeWidth', parseInt(this.value));
            fabricCanvas.renderAll();
        }
    });
    
    // Stroke listeners
    document.getElementById('strokeColor')?.addEventListener('input', function() {
        if (captionText && captionText.stroke) {
            captionText.set('stroke', this.value);
            fabricCanvas.renderAll();
        }
    });
    
    document.getElementById('strokeWidth')?.addEventListener('input', function() {
        if (captionText && captionText.stroke) {
            captionText.set('strokeWidth', parseInt(this.value));
            fabricCanvas.renderAll();
        }
    });
}
// Toggle video playback
function toggleVideoPlayback() {
    if (!currentBackgroundVideo) return;
    
    const video = document.getElementById('hiddenBackgroundVideo');
    const playButton = document.getElementById('videoPlayButton');
    
    if (video.paused) {
        video.play();
        playButton.innerHTML = '<div class="play-icon">⏸</div>';
    } else {
        video.pause();
        playButton.innerHTML = '<div class="play-icon">▶</div>';
    }
}




// Add caption to canvas
async function addCaptionToFabric(scene) {
    if (!scene.text_display || !fabricCanvas) return;
    
    console.log('Adding caption with styles:', {
        bold: scene.fontweight,
        italic: scene.fontstyle,
        underline: scene.underline,
        strike: scene.linethrough,
        stroke: scene.stroke_color
    });
    
    // Remove existing caption
    if (captionText) {
        fabricCanvas.remove(captionText);
    }
    
    // Calculate position
    let top = scene.text_y;
    if (!top || top === 0) {
        const canvasHeight = fabricCanvas.height;
        const estimatedHeight = 100;
        
        if (scene.caption_position === 'top') {
            top = 50;
        } else if (scene.caption_position === 'center') {
            top = (canvasHeight / 2) - (estimatedHeight / 2);
        } else if (scene.caption_position === 'bottom') {
            top = canvasHeight - estimatedHeight - 50;
        } else {
            top = 200;
        }
    }
    
    // Create text with ALL attributes from the row
    captionText = new fabric.Textbox(scene.text_display, {
        left: scene.text_x || 50,
        top: top,
        width: scene.text_width || 500,
        fontSize: parseInt(scene.fontsize) || 48,
        fontFamily: scene.fontfamily || 'Arial',
        fill: scene.fontcolor || '#ffffff',
        fontWeight: scene.fontweight || 'normal',
        fontStyle: scene.fontstyle || 'normal',
        underline: scene.underline == 1,
        linethrough: scene.linethrough == 1,
        textAlign: scene.caption_alignment || 'center',
        backgroundColor: scene.fontbg_enable ? (scene.fontcolor_bg || '#000000') : 'transparent',
        stroke: scene.stroke_color || null,
        strokeWidth: parseInt(scene.stroke_width) || 0,
        angle: parseFloat(scene.text_rotation) || 0,
        selectable: isSelectionMode,
        hasControls: isSelectionMode,
        hasBorders: isSelectionMode,
        editable: true
    });
    
    fabricCanvas.add(captionText);
    captionText.bringToFront();
    fabricCanvas.renderAll();
    
    // Save position when moved
    captionText.off('modified');
    captionText.on('modified', function() {
        // Determine position based on actual top coordinate
        let position = 'center';
        const canvasHeight = fabricCanvas.height;
        const textTop = this.top;
        
        if (textTop < canvasHeight * 0.3) {
            position = 'top';
        } else if (textTop > canvasHeight * 0.7) {
            position = 'bottom';
        } else {
            position = 'center';
        }
        
        const updates = {
            text_x: Math.round(this.left),
            text_y: Math.round(this.top),
            text_width: Math.round(this.width),
            text_rotation: this.angle || 0,
            caption_position: position
        };
        saveSceneUpdates(scene.id, updates);
    });
    
    // Save text when edited
    captionText.off('editing:exited');
    captionText.on('editing:exited', function() {
        if (this.text !== scene.text_display) {
            saveSceneUpdates(scene.id, { text_display: this.text });
            scene.text_display = this.text;
        }
    });
}
// Sync panel values from canvas object
function syncPanelFromCanvas() {
    if (!captionText || !currentSceneId) return;
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    // Update scene object with current text properties
    scene.fontfamily = captionText.fontFamily;
    scene.fontsize = captionText.fontSize;
    scene.fontcolor = captionText.fill;
    scene.fontcolor_bg = captionText.backgroundColor !== 'transparent' ? captionText.backgroundColor : '#000000';
    scene.fontweight = captionText.fontWeight;
    scene.fontbg_enable = captionText.backgroundColor !== 'transparent' ? 1 : 0;
    scene.caption_alignment = captionText.textAlign;
    
    // Update panel UI
    document.getElementById('sceneFontFamily').value = scene.fontfamily;
    document.getElementById('sceneFontSize').value = scene.fontsize;
    document.getElementById('sceneFontColor').value = scene.fontcolor;
    document.getElementById('sceneFontBgColor').value = scene.fontcolor_bg;
    document.getElementById('sceneFontWeight').value = scene.fontweight;
    document.getElementById('sceneFontBgEnable').checked = scene.fontbg_enable;
    document.getElementById('sceneCaptionAlignment').value = scene.caption_alignment;
    
    L('🔄 Panel synced from canvas');
}

// Add header text to canvas
async function addHeaderToFabric(scene) {
    if (!scene.header_text) return;
    
    const headerOptions = {
        left: fabricCanvas.width / 2,
        top: 50,
        fontSize: parseInt(scene.header_fontsize) || 20,
        fontFamily: scene.header_fontfamily || scene.fontfamily || 'Inter',
        fill: scene.header_fontcolor || '#ffffff',
        fontWeight: '600',
        backgroundColor: scene.header_bg_enable ? (scene.header_fontcolor_bg || '#000000') : 'transparent',
        padding: 8,
        selectable: isSelectionMode,
        hasControls: isSelectionMode,
        hasBorders: isSelectionMode,
        originX: 'center',
        originY: 'top'
    };
    
    headerText = new fabric.Text(scene.header_text, headerOptions);
    fabricCanvas.add(headerText);
}

// Add footer text to canvas
async function addFooterToFabric(scene) {
    if (!scene.footer_text) return;
    
    const footerOptions = {
        left: fabricCanvas.width / 2,
        top: fabricCanvas.height - 100,
        fontSize: parseInt(scene.footer_fontsize) || 14,
        fontFamily: scene.footer_fontfamily || scene.fontfamily || 'Inter',
        fill: scene.footer_fontcolor || '#ffffff',
        fontWeight: '500',
        backgroundColor: scene.footer_bg_enable ? (scene.footer_fontcolor_bg || '#000000') : 'transparent',
        padding: 6,
        selectable: isSelectionMode,
        hasControls: isSelectionMode,
        hasBorders: isSelectionMode,
        originX: 'center',
        originY: 'bottom'
    };
    
    footerText = new fabric.Text(scene.footer_text, footerOptions);
    fabricCanvas.add(footerText);
}

// Add logo to canvas
async function addLogoToFabric(scene) {
    if (!scene.logo_name) return;
    
    return new Promise((resolve) => {
        fabric.Image.fromURL('podcast_logos/' + scene.logo_name, (img) => {
            // Set logo size
            const logoSize = parseInt(scene.logo_size) || 60;
            const scale = logoSize / img.width;
            
            // Set position based on logo_position
            let left = 20, top = 20;
            switch(scene.logo_position) {
                case 'top-left':
                    left = 20; top = 20; break;
                case 'top-right':
                    left = fabricCanvas.width - logoSize - 20; top = 20; break;
                case 'bottom-left':
                    left = 20; top = fabricCanvas.height - logoSize - 20; break;
                case 'bottom-right':
                    left = fabricCanvas.width - logoSize - 20; top = fabricCanvas.height - logoSize - 20; break;
                case 'center':
                    left = (fabricCanvas.width - logoSize) / 2; top = (fabricCanvas.height - logoSize) / 2; break;
            }
            
            img.set({
                left: left,
                top: top,
                scaleX: scale,
                scaleY: scale,
                selectable: isSelectionMode,
                hasControls: isSelectionMode,
                hasBorders: isSelectionMode,
                originX: 'left',
                originY: 'top'
            });
            
            logoImage = img;
            fabricCanvas.add(img);
            resolve();
        }, { crossOrigin: 'anonymous' });
    });
}

// Update canvas when scene changes
function updateFabricForScene(sceneId) {
    if (!fabricCanvas) {
        initFabricCanvas();
    } else {
        loadCurrentSceneToFabric();
    }
}

// Save current canvas state to scene settings
async function saveCanvasStateToScene() {
    if (!fabricCanvas || !currentSceneId) return;
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    // Get positions of all objects
    if (captionText) {
        scene.caption_position = captionText.top < fabricCanvas.height / 3 ? 'top' :
                                 captionText.top < fabricCanvas.height * 2 / 3 ? 'center' : 'bottom';
        scene.caption_alignment = captionText.textAlign;
        scene.fontsize = captionText.fontSize;
        scene.fontfamily = captionText.fontFamily;
        scene.fontcolor = captionText.fill;
        scene.fontcolor_bg = captionText.backgroundColor;
    }
    
    if (logoImage) {
        // Determine logo position based on coordinates
        if (logoImage.left < 100 && logoImage.top < 100) scene.logo_position = 'top-left';
        else if (logoImage.left > fabricCanvas.width - 100 && logoImage.top < 100) scene.logo_position = 'top-right';
        else if (logoImage.left < 100 && logoImage.top > fabricCanvas.height - 100) scene.logo_position = 'bottom-left';
        else if (logoImage.left > fabricCanvas.width - 100 && logoImage.top > fabricCanvas.height - 100) scene.logo_position = 'bottom-right';
        else scene.logo_position = 'center';
        
        scene.logo_size = Math.round(logoImage.width * logoImage.scaleX);
    }
    
    // Save to database
    await saveAllSettingsToDB(currentSceneId, scene);
}

// Override navigateScene to use Fabric
const originalNavigateScene = window.navigateScene;
window.navigateScene = async function(direction) {
    if (typeof originalNavigateScene === 'function') {
        await originalNavigateScene(direction);
    }
    
    if (fabricCanvas) {
        await loadCurrentSceneToFabric();
    }
};

// Override openSceneSettings to initialize Fabric if needed
const originalOpenSceneSettings = window.openSceneSettings;

window.openSceneSettings = function(type) {
    if (!fabricCanvas) {
        initFabricCanvas();
    }
    
    if (typeof originalOpenSceneSettings === 'function') {
        originalOpenSceneSettings(type);
    }
};


// Global settings (saved to hdb_podcast)
let globalSettings = {
    captions: {
        style: 'typewriter',
        position: 'bottom',
        speed: '0.85'
    },
    typography: {
        font: 'Inter',
        size: '28',
        color: '#ffffff',
        bgColor: '#000000',
        weight: '700'
    },
    branding: {
        logoSize: '60',
        logoPosition: 'top-right',
        enabled: true,
        companyName: 'VideoVizard'
    }
};

// Scene-specific settings (saved to hdb_podcast_stories)
let sceneSettings = {};

// ========== CANVAS SCALING FOR DEVELOPMENT ==========
const CANVAS_SCALE = 1;

// ========== HELPER FUNCTIONS ==========
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
// Zoom control
function zoomCanvas(factor) {
    if (!fabricCanvas) return;
    
    const container = document.getElementById('canvasContainer');
    const currentZoom = parseFloat(container.dataset.zoom || '1');
    const newZoom = factor === 1 ? 1 : currentZoom * factor;
    
    // Limit zoom range
    if (newZoom < 0.5 || newZoom > 2) return;
    
    container.dataset.zoom = newZoom;
    container.style.transform = `scale(${newZoom})`;
    container.style.transformOrigin = 'top center';
    
    // Adjust margins to compensate
    const marginOffset = (1 - newZoom) * 100;
    container.style.margin = `-${marginOffset/2}px 0`;
    
    console.log('🔍 Zoom level:', newZoom);
}

// Reset zoom
function resetZoom() {
    const container = document.getElementById('canvasContainer');
    container.dataset.zoom = '1';
    container.style.transform = 'scale(1)';
    container.style.margin = '0';
}
// ========== GET AVAILABLE IMAGE FIELD ==========
function getAvailableImageField(scene) {
    if (!scene.image_file || scene.image_file.trim() === '') {
        return 'image_file';
    } else if (!scene.image_file_1 || scene.image_file_1.trim() === '') {
        return 'image_file_1';
    } else if (!scene.image_file_2 || scene.image_file_2.trim() === '') {
        return 'image_file_2';
    } else if (!scene.image_file_3 || scene.image_file_3.trim() === '') {
        return 'image_file_3';
    } else if (!scene.image_file_4 || scene.image_file_4.trim() === '') {
        return 'image_file_4';
    } else if (!scene.image_file_5 || scene.image_file_5.trim() === '') {
        return 'image_file_5';
    } else {
        // All slots are full, default to overwriting image_file
        return 'image_file';
    }
}


// Auto-save prompt with debouncing
let promptSaveTimeout;
function autoSavePrompt() {
    clearTimeout(promptSaveTimeout);
    promptSaveTimeout = setTimeout(() => {
        if (currentSceneId && currentImageField) {
            savePromptToDB(currentSceneId, currentImageField);
        }
    }, 1000);
}
// ========== TAB SWITCHING ==========
function switchTab(tab) {
    console.log('switchTab called with:', tab);
    // Update tab active states
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const tabElement = document.getElementById('tab-' + tab);
    if (tabElement) tabElement.classList.add('active');
    
    // Show corresponding global settings panel
    document.querySelectorAll('.global-settings').forEach(p => p.classList.add('hidden'));
    const globalPanel = document.getElementById('global-' + tab);
    if (globalPanel) globalPanel.classList.remove('hidden');
}

// ========== CANVAS UPDATE ==========


function updateOverlayBadges(sceneId) {
    const settings = sceneSettings[sceneId] || {};
    
    // Typography badge
    const typoIcon = document.getElementById('overlay-typography');
    if (typoIcon) {
        if (settings.typography) {
            typoIcon.classList.add('has-setting');
        } else {
            typoIcon.classList.remove('has-setting');
        }
    }
    
    // Captions badge
    const capIcon = document.getElementById('overlay-captions');
    if (capIcon) {
        if (settings.captions) {
            capIcon.classList.add('has-setting');
        } else {
            capIcon.classList.remove('has-setting');
        }
    }
    
    // Branding badge
    const brandIcon = document.getElementById('overlay-branding');
    if (brandIcon) {
        if (settings.branding) {
            brandIcon.classList.add('has-setting');
        } else {
            brandIcon.classList.remove('has-setting');
        }
    }
    
    // Images badge
    const imgIcon = document.getElementById('overlay-images');
    if (imgIcon) {
        if (settings.images) {
            imgIcon.classList.add('has-setting');
        } else {
            imgIcon.classList.remove('has-setting');
        }
    }
}

// ========== SCENE NAVIGATION ==========
// ========== SCENE NAVIGATION ==========
async function navigateScene(direction) {
    console.log('navigateScene called with:', direction);
    if (scenes.length === 0) return;
    
    // Save current image field before changing scene
    L('💾 Saving current image data...');
    const saved = await saveCurrentImageField();
    
    if (!saved) {
        L('⚠️ Failed to save current image data, but continuing...');
    }
    
    if (direction === 'prev') {
        currentSceneIndex = (currentSceneIndex - 1 + scenes.length) % scenes.length;
    } else {
        currentSceneIndex = (currentSceneIndex + 1) % scenes.length;
    }
    
    currentSceneId = scenes[currentSceneIndex].id;
    currentImageField = 'image_file'; // Reset to main image when changing scenes
    
    console.log('Navigated to scene:', currentSceneIndex + 1, 'ID:', currentSceneId);
    
    // Update the canvas
    if (typeof fabricCanvas !== 'undefined' && fabricCanvas) {
        await loadCurrentSceneToFabric();
    } else {
        updateCanvas(currentSceneId);
    }
    
    // Update scene indicator
    updateSceneIndicator();
    
    // If a panel is open, reload its content for the new scene
    if (currentOpenPanel) {
        const settingsPanel = document.getElementById('sceneSettingsPanel');
        if (settingsPanel && settingsPanel.classList.contains('active')) {
            // Re-toggle to reload content
            toggleSceneSettings(currentOpenPanel);
        }
    }
    
    // Load all thumbnails for the new scene
    setTimeout(() => {
        loadImageThumbnails();
        loadImagePrompt('image_file');
        
        // Highlight main thumbnail
        const mainThumb = document.querySelector('[onclick="selectImageFile(\'image_file\')"] div:first-child');
        if (mainThumb) {
            document.querySelectorAll('.image-thumb div:first-child').forEach(div => {
                div.style.border = '1px solid var(--border)';
            });
            mainThumb.style.border = '2px solid var(--info)';
        }
    }, 100);
    
    L('📽️ Scene ' + (currentSceneIndex + 1) + ' of ' + scenes.length);
}

function updateSceneIndicator() {
    const indicator = document.getElementById('sceneIndicator');
    if (indicator) {
        indicator.innerText = (currentSceneIndex + 1) + '/' + scenes.length;
    }
}


function openSceneSettings(type) {
    console.log('openSceneSettings called with type:', type);
    
    if (!currentSceneId) {
        console.log('No current scene ID');
        return;
    }
    
    currentSettingType = type;
    console.log('Current scene ID:', currentSceneId);
    
    // Update panel title with current scene
    updatePanelTitle();
    
    // First hide all panels
    const allPanels = document.querySelectorAll('.scene-setting');
    console.log('Found', allPanels.length, 'panels with class scene-setting');
    
    allPanels.forEach(p => {
        console.log('Hiding panel:', p.id);
        p.classList.add('hidden');
    });
    
    // Handle different panel types
    if (type === 'typography' || type === 'captions') {
        // For typography/captions, show the combined panel
        const combinedPanel = document.getElementById('scene-typography-captions');
        if (combinedPanel) {
            console.log('Showing combined panel');
            combinedPanel.classList.remove('hidden');
            loadCombinedSettingsIntoPanel(currentSceneId);
            
            // Update sequence number
            const seqNoSpan = document.getElementById('currentSceneSeqNoCombined');
            if (seqNoSpan) {
                const scene = scenes.find(s => s.id == currentSceneId);
                seqNoSpan.innerText = scene?.seq_no || (currentSceneIndex + 1);
            }
        } else {
            console.error('Combined panel not found!');
        }
    } else {
        // For other panels, show the specific panel
        const panelId = 'scene-' + type;
        const selectedPanel = document.getElementById(panelId);
        
        if (selectedPanel) {
            console.log('Showing panel:', panelId);
            selectedPanel.classList.remove('hidden');
            
            // Load appropriate settings based on panel type
            if (type === 'branding') {
                loadBrandingSettings(currentSceneId);
            } else if (type === 'audio') {
                loadAudioForScene(currentSceneId);
            } else if (type === 'images') {
                loadImageThumbnails();
                loadImagePrompt('image_file');
            } else {
                loadSceneSettingsIntoPanel(currentSceneId, type);
            }
        } else {
            console.error('Panel not found:', panelId);
        }
    }
    
    // Show the settings panel
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    if (settingsPanel) {
        settingsPanel.classList.add('active');
    } else {
        console.error('Settings panel not found!');
    }
}

let originalOverlayStates = {};

// Also update the hideAllOverlays function to ensure we store the current scene
// Function to hide all UI overlays


// ========== HIDE ALL OVERLAYS (including navigation arrows) ==========
function hideAllOverlays() {
    console.log('🎬 Hiding all overlays and navigation');
    
    // Store current scene index before hiding
    originalOverlayStates.beforePlaySceneIndex = currentSceneIndex;
    
    // Store original states for ALL overlay elements
    originalOverlayStates.overlayIcons = [];
    
    // 1. Hide all overlay icons (primary and secondary)
    const overlayIcons = document.querySelectorAll('.overlay-icon');
    overlayIcons.forEach(icon => {
        originalOverlayStates.overlayIcons.push({
            element: icon,
            display: icon.style.display
        });
        icon.style.display = 'none';
    });
    
    // 2. Hide primary icons container
    const primaryIcons = document.getElementById('primaryIcons');
    if (primaryIcons) {
        originalOverlayStates.primaryIcons = primaryIcons.style.display;
        primaryIcons.style.display = 'none';
    }
    
    // 3. Hide text icons container
    const textIcons = document.getElementById('textIcons');
    if (textIcons) {
        originalOverlayStates.textIcons = textIcons.style.display;
        textIcons.style.display = 'none';
    }
    
    // 4. Hide audio icons container
    const audioIcons = document.getElementById('audioIcons');
    if (audioIcons) {
        originalOverlayStates.audioIcons = audioIcons.style.display;
        audioIcons.style.display = 'none';
    }
    
    // 5. Hide navigation arrows
    const navArrows = document.querySelector('.nav-arrows');
    if (navArrows) {
        originalOverlayStates.navArrows = navArrows.style.display;
        navArrows.style.display = 'none';
    }
    
    // 6. Hide three-dot menu
    const threeDotMenu = document.querySelector('.three-dot-menu');
    if (threeDotMenu) {
        originalOverlayStates.threeDotMenu = threeDotMenu.style.display;
        threeDotMenu.style.display = 'none';
    }
    
    // 7. Hide action menu if open
    const actionMenu = document.getElementById('actionMenu');
    if (actionMenu) {
        originalOverlayStates.actionMenu = actionMenu.style.display;
        actionMenu.style.display = 'none';
        actionMenu.classList.remove('active');
    }
    
    // 8. Hide video play button
    const videoPlayButton = document.getElementById('videoPlayButton');
    if (videoPlayButton) {
        originalOverlayStates.videoPlayButton = videoPlayButton.style.display;
        videoPlayButton.style.display = 'none';
        videoPlayButton.classList.add('hidden-during-playback');
    }
    
    // 9. Hide any native video controls
    const videoElement = document.getElementById('hiddenBackgroundVideo');
    if (videoElement) {
        videoElement.removeAttribute('controls');
        videoElement.muted = true;
    }
    
    // 10. Hide pan controls
    const panControls = document.querySelector('.canvas-pan-controls');
    if (panControls) {
        originalOverlayStates.panControls = panControls.style.display;
        panControls.style.display = 'none';
    }
    
    // 11. Hide logo status
    const logoStatus = document.querySelector('.logo-status');
    if (logoStatus) {
        originalOverlayStates.logoStatus = logoStatus.style.display;
        logoStatus.style.display = 'none';
    }
    
    // 12. Hide settings panel if open
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    if (settingsPanel && settingsPanel.classList.contains('active')) {
        originalOverlayStates.settingsPanel = true;
        closeScenePanel();
    }
    
    // 13. Hide any open modals
    const mediaModal = document.getElementById('mediaLibModal');
    if (mediaModal && mediaModal.classList.contains('active')) {
        originalOverlayStates.mediaModal = true;
        closeMediaLib();
    }
    
    const musicModal = document.getElementById('musicLibraryModal');
    if (musicModal && musicModal.style.display === 'flex') {
        originalOverlayStates.musicModal = true;
        closeMusicLibrary();
    }
    
    const translateModal = document.getElementById('translateModal');
    if (translateModal && translateModal.style.display === 'flex') {
        originalOverlayStates.translateModal = true;
        closeTranslateModal();
    }
}

// ========== RESTORE ALL OVERLAYS ==========
function restoreAllOverlays() {
    console.log('🎬 Restoring all overlays and navigation');
    
    // 1. Restore overlay icons
    if (originalOverlayStates.overlayIcons) {
        originalOverlayStates.overlayIcons.forEach(item => {
            if (item.element) {
                item.element.style.display = item.display || 'flex';
            }
        });
    }
    
    // 2. Restore primary icons container
    if (originalOverlayStates.primaryIcons !== undefined) {
        const primaryIcons = document.getElementById('primaryIcons');
        if (primaryIcons) {
            primaryIcons.style.display = originalOverlayStates.primaryIcons || 'flex';
        }
    }
    
    // 3. Restore text icons container
    if (originalOverlayStates.textIcons !== undefined) {
        const textIcons = document.getElementById('textIcons');
        if (textIcons) {
            textIcons.style.display = originalOverlayStates.textIcons || 'none';
        }
    }
    
    // 4. Restore audio icons container
    if (originalOverlayStates.audioIcons !== undefined) {
        const audioIcons = document.getElementById('audioIcons');
        if (audioIcons) {
            audioIcons.style.display = originalOverlayStates.audioIcons || 'none';
        }
    }
    
    // 5. Restore navigation arrows
    if (originalOverlayStates.navArrows !== undefined) {
        const navArrows = document.querySelector('.nav-arrows');
        if (navArrows) {
            navArrows.style.display = originalOverlayStates.navArrows || 'flex';
        }
    } else {
        // If not stored, default to showing them
        const navArrows = document.querySelector('.nav-arrows');
        if (navArrows) {
            navArrows.style.display = 'flex';
        }
    }
    
    // 6. Restore three-dot menu
    if (originalOverlayStates.threeDotMenu !== undefined) {
        const threeDotMenu = document.querySelector('.three-dot-menu');
        if (threeDotMenu) {
            threeDotMenu.style.display = originalOverlayStates.threeDotMenu || 'block';
        }
    }
    
    // 7. Restore action menu if it was open
    if (originalOverlayStates.actionMenu !== undefined) {
        const actionMenu = document.getElementById('actionMenu');
        if (actionMenu) {
            actionMenu.style.display = originalOverlayStates.actionMenu || 'none';
            if (originalOverlayStates.actionMenu === 'block') {
                actionMenu.classList.add('active');
            } else {
                actionMenu.classList.remove('active');
            }
        }
    }
    
    // 8. Restore video play button
    if (originalOverlayStates.videoPlayButton !== undefined) {
        const videoPlayButton = document.getElementById('videoPlayButton');
        if (videoPlayButton) {
            videoPlayButton.style.display = originalOverlayStates.videoPlayButton || 'flex';
            videoPlayButton.classList.remove('hidden-during-playback');
        }
    }
    
    // 9. Restore pan controls
    if (originalOverlayStates.panControls !== undefined) {
        const panControls = document.querySelector('.canvas-pan-controls');
        if (panControls) {
            panControls.style.display = originalOverlayStates.panControls || 'flex';
        }
    }
    
    // 10. Restore logo status
    if (originalOverlayStates.logoStatus !== undefined) {
        const logoStatus = document.querySelector('.logo-status');
        if (logoStatus) {
            logoStatus.style.display = originalOverlayStates.logoStatus || 'block';
        }
    }
    
    // Clear original states
    originalOverlayStates = {};
}
// Function to restore all UI overlays
// ========== RESTORE ALL OVERLAYS ==========


// ========== MUSIC LIBRARY MODAL ==========
let musicLibraryModal = null;
let currentMusicFiles = [];

// Initialize music library modal
// Replace the initMusicLibraryModal function (around line 7590) with this safer version:



// Also fix the closeMusicLibrary function to check if modal exists
function closeMusicLibrary() {
    if (musicLibraryModal) {
        musicLibraryModal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Stop any playing audio with null check
        const audio = document.getElementById('musicPreviewAudio');
        if (audio) {
            audio.pause();
            audio.remove();
        }
    }
}

// ========== OPEN MUSIC LIBRARY ==========
function openMusicLibrary() {
    L('📚 Opening music library...');
    
    if (!musicLibraryModal) {
        initMusicLibraryModal();
    }
    
    musicLibraryModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Reset selection
    selectedMusicFile = null;
    document.getElementById('selectMusicBtn').disabled = true;
    document.getElementById('musicSelInfo').innerText = 'No file selected';
    document.getElementById('musicSearchInput').value = '';
    
    // Load music files
    loadMusicLibrary();
}

// ========== CLOSE MUSIC LIBRARY ==========
function closeMusicLibrary() {
    if (musicLibraryModal) {
        musicLibraryModal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Stop any playing audio
        const audio = document.getElementById('musicPreviewAudio');
        if (audio) {
            audio.pause();
            audio.remove();
        }
    }
}

// ========== LOAD MUSIC LIBRARY ==========
let selectedMusicFile = null;

async function loadMusicLibrary() {
    const grid = document.getElementById('musicGrid');
    const countSpan = document.getElementById('musicCount');
    
    grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">⏳ Loading music files...</div>';
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_music_library');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const text = await response.text();
        console.log('Music library response:', text.substring(0, 200));
        
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            console.error('Failed to parse music library:', text.substring(0, 500));
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#dc2626">❌ Failed to load music library</div>';
            return;
        }
        
        if (data.success && data.music_files) {
            currentMusicFiles = data.music_files;
            countSpan.innerText = currentMusicFiles.length + ' file' + (currentMusicFiles.length !== 1 ? 's' : '');
            renderMusicGrid(currentMusicFiles);
        } else {
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No music files found</div>';
        }
        
    } catch(e) {
        console.error('Error loading music library:', e);
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#dc2626">❌ Error: ' + e.message + '</div>';
    }
}
let isZoomed = false;
const container = document.getElementById('canvasContainer');
if (container) {
    container.style.transform = 'scale(1)';
    container.style.margin = '0';
}
function toggleZoom() {
    if (!fabricCanvas) return;
    
    const container = document.getElementById('canvasContainer');
    
    if (!isZoomed) {
        // Zoom to actual size
        container.style.transform = 'scale(1)';
        container.style.margin = '0';
    } else {
        // Zoom to fit
        container.style.transform = 'scale(0.7)';
        container.style.margin = '-40px 0';
    }
    
    isZoomed = !isZoomed;
}
// Render music grid with improved layout
function renderMusicGrid(files) {
    const grid = document.getElementById('musicGrid');
    if (!grid) return;
    
    if (!files || files.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:60px; color:var(--muted); font-size:16px;">🎵 No music files found in podcast_music folder</div>';
        return;
    }
    
    let html = '';
    
    files.forEach(file => {
        const duration = file.duration ? formatDuration(file.duration) : '--:--';
        const filesize = formatFileSize(file.filesize);
        
        html += `
        <div class="music-item" data-filename="${file.filename}" onclick="selectMusicItem(this, '${file.filename}')">
            <div class="music-preview">
                <div class="music-icon">🎵</div>
                <div class="music-details">
                    <div class="music-title" title="${file.title}">${file.title}</div>
                    <div class="music-artist" title="${file.artist}">${file.artist}</div>
                </div>
                <div class="play-music-btn" onclick="event.stopPropagation(); previewMusic('${file.filename}', this)">
                    <span>▶</span>
                </div>
            </div>
            <div class="music-info">
                <div class="music-details-row">
                    <span>⏱️ ${duration}</span>
                    <span>💾 ${filesize}</span>
                </div>
                <div class="music-filename" title="${file.filename}">📁 ${file.filename}</div>
            </div>
            <div class="media-check" style="display: none;">✓</div>
        </div>`;
    });
    
    grid.innerHTML = html;
}

// ========== FILTER MUSIC FILES ==========
function filterMusicFiles() {
    const searchTerm = document.getElementById('musicSearchInput').value.toLowerCase();
    
    if (!searchTerm) {
        renderMusicGrid(currentMusicFiles);
        return;
    }
    
    const filtered = currentMusicFiles.filter(file => 
        file.title.toLowerCase().includes(searchTerm) ||
        file.artist.toLowerCase().includes(searchTerm) ||
        file.filename.toLowerCase().includes(searchTerm)
    );
    
    renderMusicGrid(filtered);
    document.getElementById('musicCount').innerText = filtered.length + ' file' + (filtered.length !== 1 ? 's' : '');
}

// ========== SELECT MUSIC ITEM ==========
function selectMusicItem(element, filename) {
    // Remove selected class from all items
    document.querySelectorAll('#musicGrid .music-item').forEach(item => {
        item.classList.remove('selected');
        item.querySelector('.media-check').style.display = 'none';
    });
    
    // Add selected class to clicked item
    element.classList.add('selected');
    element.querySelector('.media-check').style.display = 'flex';
    
    selectedMusicFile = filename;
    document.getElementById('musicSelInfo').innerHTML = '✅ Selected: ' + filename;
    document.getElementById('selectMusicBtn').disabled = false;
    document.getElementById('selectMusicBtn').style.opacity = '1';
}

// ========== PREVIEW MUSIC ==========
// ========== PREVIEW MUSIC ==========
let currentPlayingPreview = null;
let currentPlayingButton = null;

function previewMusic(filename, btnElement) {
    // If this button is already playing, pause it
    if (currentPlayingButton === btnElement && currentPlayingPreview) {
        currentPlayingPreview.pause();
        return;
    }
    
    // Stop any currently playing preview
    if (currentPlayingPreview) {
        currentPlayingPreview.pause();
        if (currentPlayingButton) {
            currentPlayingButton.classList.remove('playing');
            currentPlayingButton.innerHTML = '<span style="font-size: 14px;">▶</span>';
        }
    }
    
    // Remove any existing audio element
    const oldAudio = document.getElementById('musicPreviewAudio');
    if (oldAudio) oldAudio.remove();
    
    // Create new audio element
    const audio = document.createElement('audio');
    audio.id = 'musicPreviewAudio';
    audio.src = 'podcast_music/' + filename;
    audio.preload = 'metadata';
    
    audio.onplay = () => {
        btnElement.classList.add('playing');
        btnElement.innerHTML = '<span style="font-size: 14px;">⏸</span>';
        currentPlayingPreview = audio;
        currentPlayingButton = btnElement;
    };
    
    audio.onpause = () => {
        btnElement.classList.remove('playing');
        btnElement.innerHTML = '<span style="font-size: 14px;">▶</span>';
        if (currentPlayingButton === btnElement) {
            currentPlayingPreview = null;
            currentPlayingButton = null;
        }
    };
    
    audio.onended = () => {
        btnElement.classList.remove('playing');
        btnElement.innerHTML = '<span style="font-size: 14px;">▶</span>';
        if (currentPlayingButton === btnElement) {
            currentPlayingPreview = null;
            currentPlayingButton = null;
        }
    };
    
    audio.play().catch(err => {
        console.error('Playback error:', err);
        alert('Could not play audio. The file may be corrupted or unsupported.');
    });
    
    document.body.appendChild(audio);
}
// ========== SELECT MUSIC FOR PODCAST ==========
async function selectMusicForPodcast() {
    if (!selectedMusicFile) {
        alert('Please select a music file');
        return;
    }
    
    L(`🎵 Selecting music: ${selectedMusicFile}`);
    
    // Save to database
    await saveMusicToDatabase(selectedMusicFile);
    
    // Update global variable
    podcastMusicFile = selectedMusicFile;
    
    // Update display
    updateMusicDisplay();
    
    // Show success message
    alert(`✅ Background music set to: ${selectedMusicFile}`);
    
    // Close modal
    closeMusicLibrary();
}

// ========== HELPER FUNCTIONS ==========
function formatDuration(seconds) {
    if (!seconds || seconds === 0) return '--:--';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}






// ========== CREATE NEW IMAGE BOX FUNCTION ==========
async function createNewImageBox(imageFile) {
    console.log('🎨 createNewImageBox started with imageFile:', imageFile);
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Generate a unique caption name with timestamp
    const now = new Date();
    const timestamp = now.getFullYear() + 
                      String(now.getMonth() + 1).padStart(2, '0') + 
                      String(now.getDate()).padStart(2, '0') + '_' +
                      String(now.getHours()).padStart(2, '0') + 
                      String(now.getMinutes()).padStart(2, '0') + 
                      String(now.getSeconds()).padStart(2, '0');
    
    const captionName = 'caption_' + timestamp;
    
    // Get podcast_id from current scene
    const currentScene = scenes.find(s => s.id == currentSceneId);
    const podcastId = currentScene ? currentScene.podcast_id : currentPodcastId;
    
    try {
        // Step 1: Create caption with type='image'
        console.log('📤 Step 1: Creating caption with type="image"');
        const fd = new FormData();
        fd.append('ajax_action', 'create_caption');
        fd.append('story_id', currentSceneId);
        fd.append('podcast_id', podcastId);
        fd.append('caption_type', 'image');
        fd.append('caption_name', captionName);
        // Don't append media_type here - let the server set it based on caption_type
        
        const response = await fetch(location.href, {
            method: 'POST',
            body: fd
        });
        
        const text = await response.text();
        console.log('📥 Create caption response:', text);
        
        let result;
        try {
            result = JSON.parse(text);
        } catch(e) {
            console.error('JSON parse error:', text.substring(0, 200));
            throw new Error('Server returned invalid JSON for create_caption');
        }
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to create caption');
        }
        
        const captionId = result.caption_id;
        console.log('✅ Image caption created with ID:', captionId);
        
        // Step 2: Update with image file - THIS IS CRITICAL
        console.log('📤 Step 2: Updating caption with image file:', imageFile);
        const updateFd = new FormData();
        updateFd.append('ajax_action', 'save_caption');
        updateFd.append('caption_id', captionId);
        updateFd.append('image_file', imageFile);
        updateFd.append('media_type', 'image');
        updateFd.append('text_content', '');
        
        // Calculate center position
        const centerX = fabricCanvas ? Math.round(fabricCanvas.width / 2 - 100) : 200;
        const centerY = fabricCanvas ? Math.round(fabricCanvas.height / 2 - 100) : 300;
        
        updateFd.append('position_x', centerX);
        updateFd.append('position_y', centerY);
        updateFd.append('width', 200);
        
        console.log('Update data:', Object.fromEntries(updateFd));
        
        const updateResponse = await fetch(location.href, {
            method: 'POST',
            body: updateFd
        });
        
        const updateText = await updateResponse.text();
        console.log('📥 Update caption response:', updateText);
        
        let updateResult;
        try {
            updateResult = JSON.parse(updateText);
        } catch(e) {
            console.error('Update JSON parse error:', updateText.substring(0, 200));
            throw new Error('Invalid update response');
        }
        
        if (!updateResult.success) {
            throw new Error(updateResult.message || 'Failed to update caption with image');
        }
        
        console.log('✅ Step 2 complete: Caption updated with image file');
        
        // Step 3: Reload captions to get the new data
        console.log('📤 Step 3: Reloading scene captions...');
        await loadSceneCaptions(currentSceneId);
        console.log('✅ Scene captions reloaded');
        console.log('Available captions:', Object.keys(sceneCaptions));
        
        // Step 4: Find the new caption
        const newCaption = Object.values(sceneCaptions).find(c => c.id == captionId);
        console.log('Looking for caption with ID:', captionId);
        console.log('Found caption:', newCaption);
        
        if (!newCaption) {
            throw new Error('New caption not found after creation');
        }
        
        // Verify the image_file was saved
        console.log('Caption image_file:', newCaption.image_file);
        
        // Step 5: Add to canvas
        console.log('📤 Step 4: Adding image to canvas...');
        await addImageBoxToCanvas(captionId, imageFile, true);
        console.log('✅ Image added to canvas');
        
        // Step 6: Select the new caption
        setTimeout(() => {
            selectCaption(captionId, 'image');
            console.log('✅ New caption selected');
        }, 100);
        
        alert('✅ New image box added successfully!');
        
    } catch(error) {
        console.error('❌ Error in createNewImageBox:', error);
        console.error('Error stack:', error.stack);
        alert('Failed to create image box: ' + error.message);
    }
}

// ========== AUTO-SAVE IMAGE BOX PROPERTIES ==========
function autoSaveImageBoxProperties(imgObj) {
    if (!imgObj.captionId) return;
    
    const updates = {
        position_x: Math.round(imgObj.left),
        position_y: Math.round(imgObj.top),
        width: Math.round(imgObj.width * imgObj.scaleX),
        scale_x: imgObj.scaleX,
        scale_y: imgObj.scaleY,
        rotation: imgObj.angle || 0
    };
    
    if (imgObj._saveTimeout) clearTimeout(imgObj._saveTimeout);
    imgObj._saveTimeout = setTimeout(async () => {
        const fd = new FormData();
        fd.append('ajax_action', 'save_caption');
        fd.append('caption_id', imgObj.captionId);
        
        Object.keys(updates).forEach(key => {
            fd.append(key, updates[key]);
        });
        
        await safeFetch(fd);
        console.log('✅ Image box position saved for', imgObj.captionId);
    }, 500);
}

// ========== SHOW IMAGE BOX PREVIEW ==========
function showImageBoxPreview(imageFile) {
    const previewDiv = document.getElementById('selectedImageBoxPreview');
    const previewImg = document.getElementById('imageBoxPreview');
    
    if (previewDiv && previewImg) {
        previewImg.src = 'podcast_stickers/' + imageFile + '?t=' + Date.now();
        previewDiv.style.display = 'block';
        console.log('🖼️ Showing image preview:', imageFile);
    }
}

// ========== UPDATE SELECT CAPTION TO SHOW IMAGE PREVIEW ==========
// Store original selectCaption function
const originalSelectCaption = window.selectCaption;



// ========== CAPTION SELECTION AND MANAGEMENT ==========
let selectedCaptionId = null;
let selectedCaptionType = null;

// Function to handle caption selection on canvas - UPDATED to filter background
function setupCaptionSelection() {
    if (!fabricCanvas) return;
    
    let isTouchDevice = 'ontouchstart' in window;
    let touchStartTime = 0;
    let touchStartTarget = null;
    let touchMoved = false;
    let selectionTimer = null;
    
    console.log('📱 Touch device:', isTouchDevice);
    
    // Handle selection created
    fabricCanvas.on('selection:created', function(e) {
        console.log('🔵 selection:created event FIRED', e);
        
        const selected = e.selected[0];
        console.log('Selected object:', {
            type: selected?.type,
            hasCaptionId: !!selected?.captionId,
            captionId: selected?.captionId
        });
        
        // If no selection or selection doesn't have captionId, discard it
        if (!selected || !selected.captionId) {
            console.log('⚠️ Selected object is not a caption - discarding selection');
            fabricCanvas.discardActiveObject();
            fabricCanvas.renderAll();
            return;
        }
        
        if (!isSelectionMode) {
            console.log('Selection mode is off, discarding selection');
            fabricCanvas.discardActiveObject();
            fabricCanvas.renderAll();
            return;
        }
        
        console.log('✅ Calling selectCaption with:', selected.captionId, selected.captionType);
        selectCaption(selected.captionId, selected.captionType);
    });
    
    // Handle selection updated
    fabricCanvas.on('selection:updated', function(e) {
        console.log('🟢 selection:updated event FIRED', e);
        
        const selected = e.selected[0];
        
        // If no selection or selection doesn't have captionId, discard it
        if (!selected || !selected.captionId) {
            console.log('⚠️ Updated selection is not a caption - discarding');
            fabricCanvas.discardActiveObject();
            fabricCanvas.renderAll();
            return;
        }
        
        if (!isSelectionMode) {
            fabricCanvas.discardActiveObject();
            fabricCanvas.renderAll();
            return;
        }
        
        selectCaption(selected.captionId, selected.captionType);
    });
    
    // Handle selection cleared
    fabricCanvas.on('selection:cleared', function(e) {
        console.log('🔴 selection:cleared', e);
        
        // Clear any pending timer
        if (selectionTimer) {
            clearTimeout(selectionTimer);
        }
        
        // On mobile, don't immediately clear the caption selection
        if (isTouchDevice) {
            console.log('📱 Mobile: delaying selection clear');
            
            selectionTimer = setTimeout(() => {
                // Check if we still have an active object
                const activeObj = fabricCanvas.getActiveObject();
                
                if (activeObj && activeObj.captionId) {
                    console.log('📱 Mobile: object still active, keeping selection');
                    return;
                }
                
                // Check if we're in the middle of a touch
                if (touchStartTarget) {
                    console.log('📱 Mobile: touch in progress, keeping selection');
                    
                    // Try to restore the selection
                    if (touchStartTarget.captionId) {
                        fabricCanvas.setActiveObject(touchStartTarget);
                        fabricCanvas.renderAll();
                        
                        // Keep the caption selected in the tab
                        if (selectedCaptionId !== touchStartTarget.captionId) {
                            selectCaption(touchStartTarget.captionId, touchStartTarget.captionType);
                        }
                    }
                    return;
                }
                
                console.log('📱 Mobile: really cleared');
                clearCaptionSelection();
                touchStartTarget = null;
            }, 300);
        } else {
            // Desktop: clear immediately
            clearCaptionSelection();
        }
    });
    
    // Touch start tracking
    fabricCanvas.on('touch:start', function(e) {
        console.log('📱 touch:start', e);
        touchStartTime = Date.now();
        touchMoved = false;
        
        if (e.target && e.target.captionId) {
            touchStartTarget = e.target;
            console.log('📱 Touch started on caption:', e.target.captionId);
        } else {
            touchStartTarget = null;
        }
    });
    
    // Touch move tracking
    fabricCanvas.on('touch:move', function(e) {
        touchMoved = true;
    });
    
    // Touch end handling
    fabricCanvas.on('touch:end', function(e) {
        const touchEndTime = Date.now();
        const touchDuration = touchEndTime - touchStartTime;
        
        console.log('📱 touch:end', { duration: touchDuration, moved: touchMoved });
        
        // Check if this was a tap (short duration, no movement)
        if (!touchMoved && touchDuration < 300 && e.target && e.target.captionId) {
            console.log('📱 Tap detected on caption:', e.target.captionId);
            
            // Ensure the object is selected
            if (fabricCanvas.getActiveObject() !== e.target) {
                fabricCanvas.setActiveObject(e.target);
                fabricCanvas.renderAll();
            }
            
            // Update caption selection in tab
            selectCaption(e.target.captionId, e.target.captionType);
        }
        
        // Check for double tap
        if (!touchMoved && touchDuration < 200 && e.target && e.target.captionId) {
            console.log('📱 Possible double tap');
        }
        
        touchStartTarget = null;
    });
    
    // Double tap for editing
    fabricCanvas.on('touch:dbltap', function(e) {
        console.log('📱 Double tap detected');
        
        if (e.target && e.target.captionId) {
            console.log('📱 Entering edit mode on double tap');
            
            // Make sure it's selected first
            fabricCanvas.setActiveObject(e.target);
            fabricCanvas.renderAll();
            
            // Enter edit mode
            setTimeout(() => {
                e.target.enterEditing();
                e.target.set({
                    backgroundColor: 'rgba(255,255,255,0.95)',
                    fill: '#000000'
                });
                fabricCanvas.renderAll();
                
                // Focus the input
                if (e.target.hiddenTextarea) {
                    e.target.hiddenTextarea.focus();
                }
            }, 50);
        }
    });
    
    // Also handle mouse events for desktop
    fabricCanvas.on('mouse:down', function(e) {
        if (!isTouchDevice) {
            if (e.target && e.target.captionId) {
                console.log('🖱️ Mouse down on caption:', e.target.captionId);
            } else {
                console.log('🖱️ Mouse down on background or non-caption object');
            }
        }
    });
}

// Make text objects touch-friendly
function makeTextObjectsTouchFriendly() {
    if (!fabricCanvas) return;
    
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            // Increase hit area for touch
            obj.set({
                cornerSize: 20,
                touchCornerSize: 25,
                borderColor: '#00ff00',
                cornerColor: '#ff0000',
                transparentCorners: false
            });
        }
    });
    
    fabricCanvas.renderAll();
    console.log('📱 Text objects optimized for touch');
}

// Call it after canvas loads and when new objects are added
setTimeout(makeTextObjectsTouchFriendly, 2000);
// Add this to monitor selection state
function monitorSelection() {
    if (!fabricCanvas) return;
    
    setInterval(() => {
        const activeObject = fabricCanvas.getActiveObject();
        if (activeObject && activeObject.captionId) {
            console.log('👁️ Active object:', {
                id: activeObject.captionId,
                type: activeObject.type,
                text: activeObject.text ? activeObject.text.substring(0, 20) : 'no text',
                editing: activeObject.isEditing ? 'yes' : 'no'
            });
        }
    }, 2000);
}

// Call it after canvas initialization
//setTimeout(monitorSelection, 3000);

// ========== SINGLE SELECT CAPTION FUNCTION ==========

// Make sure it's available globally
window.selectCaption = selectCaption;

// Clear caption selection
function clearCaptionSelection() {
    selectedCaptionId = null;
    selectedCaptionType = null;
    
    // Update UI to show "select text" message
    showCaptionSelectionMessage();
}
// ========== SINGLE SELECT CAPTION FUNCTION ==========
function selectCaption(captionId, captionType) {
    console.log('📝 Caption selected:', captionId, captionType);
    
    // Store selection
    selectedCaptionId = captionId;
    selectedCaptionType = captionType;
    
    // Find the caption data
    const caption = findCaptionById(captionId);
    if (!caption) {
        console.error('Caption not found:', captionId);
        return;
    }
    
    // Update captionText based on the active object type
    if (fabricCanvas) {
        const activeObj = fabricCanvas.getActiveObject();
        if (activeObj && activeObj.captionId == captionId) {
            if (activeObj.type === 'textbox' || activeObj.type === 'text') {
                captionText = activeObj;
                console.log('✅ Set captionText to text object');
            } else {
                captionText = null; // Images don't use captionText
                console.log('🖼️ Image object selected, captionText set to null');
            }
        }
    }
    
    // Load settings based on caption type
    if (caption.media_type === 'image' || caption.image_file) {
        // It's an image caption
        loadImageCaptionSettings(caption);
        showImageBoxPreview(caption.image_file);
    } else {
        // It's a text caption
        loadCaptionSettingsToPanel(caption);
        // Hide image preview
        const previewDiv = document.getElementById('selectedImageBoxPreview');
        if (previewDiv) previewDiv.style.display = 'none';
    }
    
    // Show the typography tab if needed
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    //const typographyPanel = document.getElementById('scene-typography-captions');
    
    if (!settingsPanel.classList.contains('active') || typographyPanel.classList.contains('hidden')) {
        toggleSceneSettings('typography');
    }
    
    // Update the panel header
    updateCaptionPanelHeader(caption);
    
    L(`✅ Caption "${caption.caption_type || caption.caption_name}" selected`);
}
// Show message when no caption is selected
function showCaptionSelectionMessage() {
    const panel = document.getElementById('scene-typography-captions');
    if (!panel) return;
    
    // Hide global caption options when no caption selected
    const globalCheckbox = document.getElementById('makeCaptionGlobal');
    const warningDiv = document.getElementById('globalCaptionWarning');
    if (globalCheckbox) globalCheckbox.checked = false;
    if (warningDiv) warningDiv.style.display = 'none';
    
    // Store original content if not already stored
    if (!panel.dataset.originalContent) {
        panel.dataset.originalContent = panel.innerHTML;
    }
    
    // Show simple selection message - just one line in red
    panel.innerHTML = `
        <div class="panel-header">
            <div class="panel-title">🎨 Text & Caption Settings</div>
            <button class="panel-close" onclick="closeScenePanel()">✕</button>
        </div>
        <div style="text-align: center; padding: 30px 20px; background: #fff1f0; border-radius: 8px; margin: 15px 0; border-left: 4px solid #dc2626;">
            <p style="color: #dc2626; font-weight: 500; margin: 0; font-size: 14px;">
                ⚠️ Select a caption on the canvas to change its attributes
            </p>
        </div>
    `;
}


// Main delete function - called from UI
async function deleteSelectedCaption() {
    console.log('🗑️ deleteSelectedCaption called');
    
    if (!selectedCaptionId) {
        alert('Please select a caption to delete');
        return;
    }
    
    const caption = findCaptionById(selectedCaptionId);
    console.log('Caption to delete:', caption);
    
    // Check if this is the main caption - MUST return immediately
    if (caption && caption.caption_type === 'main') {
        alert('⚠️ The main caption cannot be deleted. It is essential for the scene.');
        return; // Exit immediately, no further code execution
    }
	
	    // Check if this is the main caption by caption_name
    if (caption && caption.caption_name === 'main') {
        alert('⚠️ The main caption cannot be deleted. It is essential for the scene.');
        return; // Exit immediately, no further code execution
    }

    
    // For non-main captions, check if it's global
    if (caption && caption.is_global == 1) {
        if (!confirm('⚠️ This caption appears in ALL scenes. Do you want to delete it from ALL scenes?')) {
            return;
        }
    } else {
        // Regular caption
        if (!confirm('Are you sure you want to delete this visual box? This cannot 11 be undone.')) {
            return;
        }
    }
    
    // Only reaches here if user confirmed deletion
    try {
        if (caption && caption.is_global == 1) {
            await deleteGlobalCaption(selectedCaptionId, caption.caption_type);
        } else {
            await deleteSingleCaption(selectedCaptionId);
        }
        
        // Reload captions
        await loadSceneCaptions(currentSceneId);
        
        // Select first remaining caption if any
        const remainingCaptions = Object.keys(sceneCaptions);
        if (remainingCaptions.length > 0) {
            const firstCaptionType = remainingCaptions[0];
            const firstCaption = sceneCaptions[firstCaptionType];
            if (firstCaption) {
                selectCaption(firstCaption.id, firstCaptionType);
            }
        } else {
            showCaptionSelectionMessage();
            closeScenePanel();
        }
        
    } catch (err) {
        console.error('❌ Error deleting caption:', err);
        alert('Error deleting caption: ' + err.message);
    }
}

// Delete single caption (local to current scene)
async function deleteSingleCaption(captionId) {
    console.log('🗑️ deleteSingleCaption called for:', captionId);
    
    const fd = new FormData();
    fd.append('ajax_action', 'delete_caption');
    fd.append('caption_id', captionId);
    
    const {data} = await safeFetch(fd);
    
    if (data.success) {
        L(`✅ Caption deleted from current scene`);
        
        // Remove from canvas
        if (fabricCanvas) {
            const objects = fabricCanvas.getObjects();
            objects.forEach(obj => {
                if (obj.captionId == captionId) {
                    fabricCanvas.remove(obj);
                }
            });
            fabricCanvas.renderAll();
        }
    } else {
        throw new Error(data.message || 'Delete failed');
    }
}

// Delete global caption from all scenes
async function deleteGlobalCaption(captionId, captionType) {
    console.log('🗑️ deleteGlobalCaption called for:', captionId, captionType);
    
    L('🗑️ Deleting global caption from ALL scenes...');
    
    let deletedCount = 0;
    
    for (const scene of scenes) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'delete_caption_by_type');
            fd.append('story_id', scene.id);
            fd.append('caption_type', captionType);
            
            const {data} = await safeFetch(fd);
            if (data.success && data.deleted > 0) {
                deletedCount += data.deleted;
            }
        } catch (e) {
            console.error('Error deleting from scene', scene.id, e);
        }
    }
    
    L(`✅ Deleted from ${deletedCount} scenes`);
    
    // Remove from current canvas
    if (fabricCanvas) {
        const objects = fabricCanvas.getObjects();
        objects.forEach(obj => {
            if (obj.captionId == captionId) {
                fabricCanvas.remove(obj);
            }
        });
        fabricCanvas.renderAll();
    }
}

// ----------------------- play functionality -------------------------------
// ========== SEQUENCE PLAYER ==========
let isPlayingSequence = false;
let currentScenePlayIndex = 0;
let currentAudioPlayer = null;

// Play the full sequence
async function playFullSequence(isRecording = false) {
    console.log('🎬 playFullSequence called, isRecording:', isRecording);
    
    // Check if we have scenes
    if (!scenes || scenes.length === 0) {
        alert('No scenes to play');
        return;
    }
    
    // If already playing, stop it
    if (isPlayingSequence) {
        stopPlayback();
        return;
    }
    
    // Hide all overlays
    hideAllOverlays();
    
    // Save current scene before playing
    if (currentSceneId) {
        await saveCurrentImageField();
    }

    // ── Preload all scene media before starting ──────────────
    await preloadAllScenes();
    // ────────────────────────────────────────────────────────
    
    isPlayingSequence = true;
    currentScenePlayIndex = 0;
    showStopBtn();
    
    // Update the preview button in the 3-dot menu
    updatePreviewButtonState(true);
    
    L(`▶️ Playing sequence from scene 1/${scenes.length}`);
    
    // Start with first scene
    playSceneInSequence(0);
}

// ========== PRELOAD ALL SCENES BEFORE PLAYBACK ==========
async function preloadAllScenes() {
    if (!scenes || scenes.length === 0) return;

    console.log(`⏳ Preloading all ${scenes.length} scenes...`);

    // Show loading state on stop button
    const stopBtn = document.getElementById('floatingStopBtn');
    if (stopBtn) {
        stopBtn.style.display = 'block';
        stopBtn.style.background = '#f59e0b';
        stopBtn.style.cursor = 'default';
        stopBtn.innerText = '⏳ Loading...';
    }

    window.preloadCache = window.preloadCache || {};

    const loadMedia = (scene) => new Promise(resolve => {
        const filename = scene.image_file || scene.video_file;
        if (!filename) { resolve(); return; }

        // Already cached — skip
        if (window.preloadCache[filename]) { resolve(); return; }

        const isVideo = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);

        if (isVideo) {
            const video = document.createElement('video');
            video.preload = 'auto';
            video.muted = true;
            video.src = 'podcast_videos/' + filename;
            video.oncanplaythrough = () => {
                window.preloadCache[filename] = video;
                console.log(`✅ Video preloaded: ${filename}`);
                resolve();
            };
            video.onerror = () => {
                console.warn(`⚠️ Video preload failed: ${filename}`);
                resolve(); // don't block — continue anyway
            };
            // Resolve after 8s max so a slow file doesn't block forever
            setTimeout(resolve, 8000);
            video.load();
        } else {
            const img = new Image();
            img.onload = () => {
                window.preloadCache[filename] = img;
                console.log(`✅ Image preloaded: ${filename}`);
                resolve();
            };
            img.onerror = () => {
                console.warn(`⚠️ Image preload failed: ${filename}`);
                resolve();
            };
            setTimeout(resolve, 5000);
            img.src = 'podcast_images/' + filename;
        }
    });

    // Load all scenes in parallel
    await Promise.all(scenes.map(loadMedia));

    console.log(`✅ All scenes preloaded`);

    // Restore stop button
    if (stopBtn) {
        stopBtn.style.background = '#dc2626';
        stopBtn.style.cursor = 'pointer';
        stopBtn.innerText = '⏹ Stop';
        stopBtn.style.display = 'none'; // will be shown again by showStopBtn()
    }
}

// ========== PLAY SCENE IN SEQUENCE ==========

// Update the stopPlayback function
function showStopBtn() {
    const btn = document.getElementById('floatingStopBtn');
    if (btn) btn.style.display = 'block';
}
function hideStopBtn() {
    const btn = document.getElementById('floatingStopBtn');
    if (btn) btn.style.display = 'none';
}
function handleStopBtn() {
    if (isRecording) {
        stopRecording();
    } else {
        stopPlayback();
    }
    hideStopBtn();
}

function stopPlayback() {
    console.log('⏹️ Stopping playback');
    
    isPlayingSequence = false;
    hideStopBtn();
    
    // Stop animations
    stopCaptionAnimation();
    
    // Stop any playing audio
    if (currentAudioPlayer) {
        currentAudioPlayer.pause();
        currentAudioPlayer.currentTime = 0;
        currentAudioPlayer = null;
    }
    
    // Remove all temporary audio elements
    document.querySelectorAll('[id^="sequence-audio-"]').forEach(el => el.remove());

    // Clear preload cache to free memory
    if (window.preloadCache) {
        Object.values(window.preloadCache).forEach(item => {
            if (item.tagName === 'VIDEO') { item.pause(); item.src = ''; }
        });
        window.preloadCache = {};
    }

    // Stop background music if playing
    const bgMusic = document.getElementById('preview-bg-music');
    if (bgMusic) {
        bgMusic.pause();
        bgMusic.src = '';
        bgMusic.remove();
    }
    
    // Update button state
    updatePreviewButtonState(false);
    
    // Restore all overlays
    restoreAllOverlays();
    
    // Reset to scene 1
    if (scenes.length > 0 && currentSceneIndex !== 0) {
        currentSceneIndex = 0;
        currentSceneId = scenes[0].id;
        updateSceneIndicator();
        
        if (fabricCanvas) {
            setTimeout(async () => {
                await loadCurrentSceneToFabric();
                console.log('✅ Reset to scene 1');
            }, 100);
        }
    }
    
    L('⏹️ Playback stopped - reset to scene 1');
}


// Update preview button state
function updatePreviewButtonState(isPlaying) {
    const actionMenu = document.getElementById('actionMenu');
    if (actionMenu) {
        const previewItem = Array.from(actionMenu.children).find(item => 
            item.textContent.includes('Preview')
        );
        if (previewItem) {
            if (isPlaying) {
                previewItem.innerHTML = '<span>⏹️</span> Stop';
            } else {
                previewItem.innerHTML = '<span>▶️</span> Preview';
            }
        }
    }
}

// Override the existing playPreview function
function playPreview() {
    console.log('🎬 playPreview called - checking conditions...');
    
    if (!scenes || scenes.length === 0) {
        console.log('❌ No scenes available');
        alert('No scenes to play');
        return;
    }
    
    console.log('✅ Scenes available, calling playFullSequence');
    playFullSequence(false);
}

//------------------------------------------------------------------------------
// ========== FIND CAPTION BY ID ==========
function findCaptionById(captionId) {
    if (!captionId) {
        console.warn('❌ findCaptionById called with no captionId');
        return null;
    }
    
    console.log('🔍 Looking for caption with ID:', captionId);
    
    // Search in current scene's captions
    if (sceneCaptions && typeof sceneCaptions === 'object') {
        // First try direct access by ID if sceneCaptions is indexed by ID
        for (let key in sceneCaptions) {
            const caption = sceneCaptions[key];
            if (caption && caption.id == captionId) {
                console.log('✅ Found caption:', {
                    id: caption.id,
                    type: caption.caption_type,
                    name: caption.caption_name,
                    media_type: caption.media_type
                });
                return caption;
            }
        }
    }
    
    // If not found in sceneCaptions, try searching in scenes array
    if (scenes && Array.isArray(scenes)) {
        for (let scene of scenes) {
            if (scene.captions && typeof scene.captions === 'object') {
                for (let key in scene.captions) {
                    const caption = scene.captions[key];
                    if (caption && caption.id == captionId) {
                        console.log('✅ Found caption in scenes array:', {
                            id: caption.id,
                            type: caption.caption_type,
                            name: caption.caption_name
                        });
                        return caption;
                    }
                }
            }
        }
    }
    
    console.log('❌ Caption not found with ID:', captionId);
    return null;
}

// Load caption settings into the typography panel
// ========== LOAD CAPTION SETTINGS TO PANEL ==========
function loadCaptionSettingsToPanel(caption) {
    console.log('Loading caption settings:', caption);
    
    // Restore original panel content first
    restorePanelContent();
    
    // Check if elements exist before setting values
    const fontFamilyEl = document.getElementById('sceneFontFamily');
    if (fontFamilyEl) fontFamilyEl.value = caption.fontfamily || 'Inter';
    
    const fontSizeEl = document.getElementById('sceneFontSize');
    if (fontSizeEl) fontSizeEl.value = caption.fontsize || '28';
    
    const fontColorEl = document.getElementById('sceneFontColor');
    if (fontColorEl) fontColorEl.value = caption.fontcolor || '#ffff00';
    
    const fontBgColorEl = document.getElementById('sceneFontBgColor');
    if (fontBgColorEl) fontBgColorEl.value = caption.bg_color || '#000000';
    
    const fontBgEnableEl = document.getElementById('sceneFontBgEnable');
    if (fontBgEnableEl) fontBgEnableEl.checked = caption.bg_enabled == 1;
    
    // Caption style settings
    const captionStyleEl = document.getElementById('sceneCaptionStyle');
    if (captionStyleEl) captionStyleEl.value = caption.animation_style || 'none';
    
    const captionPositionEl = document.getElementById('sceneCaptionPosition');
    if (captionPositionEl) captionPositionEl.value = caption.caption_position || 'bottom';
    
    const captionAlignmentEl = document.getElementById('sceneCaptionAlignment');
    if (captionAlignmentEl) captionAlignmentEl.value = caption.text_align || 'center';
    
    const captionSpeedEl = document.getElementById('sceneCaptionSpeed');
    if (captionSpeedEl) {
        captionSpeedEl.value = caption.animation_speed || '1.0';
        const speedValueEl = document.getElementById('sceneSpeedValue');
        if (speedValueEl) speedValueEl.innerText = (caption.animation_speed || '1.0') + 'x';
    }
    
    // Outline settings
    const outlineColorEl = document.getElementById('outlineColor');
    const outlineWidthEl = document.getElementById('outlineWidth');
    const outlineSettingsEl = document.getElementById('outlineSettings');
    
    if (caption.outline_color) {
        if (outlineColorEl) outlineColorEl.value = caption.outline_color;
        if (outlineWidthEl) outlineWidthEl.value = caption.outline_width || 2;
        if (outlineSettingsEl) {
            outlineSettingsEl.style.display = caption.outline_enabled == 1 ? 'block' : 'none';
        }
    } else {
        if (outlineSettingsEl) outlineSettingsEl.style.display = 'none';
    }
    
    // Stroke settings
    const strokeColorEl = document.getElementById('strokeColor');
    const strokeWidthEl = document.getElementById('strokeWidth');
    const strokeSettingsEl = document.getElementById('strokeSettings');
    
    if (caption.stroke_color) {
        if (strokeColorEl) strokeColorEl.value = caption.stroke_color;
        if (strokeWidthEl) strokeWidthEl.value = caption.stroke_width || 2;
        if (strokeSettingsEl) {
            strokeSettingsEl.style.display = caption.stroke_enabled == 1 ? 'block' : 'none';
        }
    } else {
        if (strokeSettingsEl) strokeSettingsEl.style.display = 'none';
    }
    
    // Update speed settings visibility
    const speedSettingsEl = document.getElementById('speedSettings');
    if (speedSettingsEl) {
        speedSettingsEl.style.display = (caption.animation_style === 'typewriter' || caption.animation_style === 'scroll') ? 'block' : 'none';
    }
    
    // Update global caption checkbox
    const globalCheckbox = document.getElementById('makeCaptionGlobal');
    const warningDiv = document.getElementById('globalCaptionWarning');

    if (globalCheckbox) {
        // Set checkbox state based on caption's global status
        globalCheckbox.checked = caption.is_global == 1;
        
        // Show warning if global
        if (caption.is_global == 1) {
            warningDiv.style.display = 'block';
        } else {
            warningDiv.style.display = 'none';
        }
    }
}

// Force text to be visible with contrasting colors
function fixTextVisibility() {
    if (!fabricCanvas) return;
    
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            console.log('🎨 Fixing text visibility');
            
            // Set bright contrasting colors
            obj.set({
                fill: '#ffff00', // Bright yellow
                stroke: '#000000', // Black stroke
                strokeWidth: 2,
                backgroundColor: 'rgba(0,0,0,0.5)', // Semi-transparent black background
                fontSize: 48,
                opacity: 1,
                visible: true
            });
            
            // Log the colors for debugging
            console.log('Text fill color set to:', obj.fill);
            console.log('Text background:', obj.backgroundColor);
        }
    });
    
    fabricCanvas.renderAll();
    console.log('✅ Text visibility fixed - should now be visible');
}

// Run it
fixTextVisibility();


// Find and reposition the text
function findAndRepositionText() {
    if (!fabricCanvas) return;
    
    console.log('🔍 Searching for text object...');
    
    const objects = fabricCanvas.getObjects();
    let textFound = false;
    
    objects.forEach((obj, index) => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            textFound = true;
            console.log(`📝 Text object ${index}:`, {
                text: obj.text ? obj.text.substring(0, 30) : 'NO TEXT',
                left: obj.left,
                top: obj.top,
                width: obj.width,
                height: obj.height,
                scaleX: obj.scaleX,
                scaleY: obj.scaleY,
                fontSize: obj.fontSize,
                visible: obj.visible,
                opacity: obj.opacity
            });
            
            // Force it to a visible position
            console.log('🔄 Moving text to center of canvas');
            obj.set({
                left: 100,
                top: 300,
                width: 500,
                fontSize: 48,
                scaleX: 1,
                scaleY: 1,
                visible: true,
                opacity: 1
            });
        }
    });
    
    if (!textFound) {
        console.log('❌ No text objects found on canvas');
        
        // Check if sceneCaptions has text
        console.log('sceneCaptions:', sceneCaptions);
        if (sceneCaptions['main'] && sceneCaptions['main'].text_content) {
            console.log('✅ Found text in sceneCaptions, but not on canvas');
            console.log('Text content:', sceneCaptions['main'].text_content);
        }
    }
    
    fabricCanvas.renderAll();
    console.log('✅ Canvas re-rendered');
    
    return textFound;
}

// Run it
findAndRepositionText();


// Update panel header with caption info
// ========== UPDATE CAPTION PANEL HEADER ==========
function updateCaptionPanelHeader(caption) {
    const seqNoSpan = document.getElementById('currentSceneSeqNoCombined');
    if (seqNoSpan && caption) {
        const captionType = caption.caption_type === 'main' ? 'Main Caption' : 
                           caption.caption_type === 'header' ? 'Header' :
                           caption.caption_type === 'footer' ? 'Footer' : 
                           caption.caption_name || caption.caption_type;
        seqNoSpan.innerText = captionType;
        console.log('📋 Updated panel header to:', captionType);
    }
}

// Add a new caption to the current scene
// Add a new caption to the current scene - AUTOMATIC VERSION
// Add a new text caption
// Add a new text caption
// Add a new text caption
async function addNewCaption() {
    console.log('➕ ===== ADD NEW TEXT CAPTION STARTED =====');
    
    if (!currentSceneId) {
        console.error('❌ No current scene ID');
        alert('No scene selected');
        return;
    }
    
    // Generate a unique caption name with timestamp
    const now = new Date();
    const timestamp = now.getFullYear() + 
                      String(now.getMonth() + 1).padStart(2, '0') + 
                      String(now.getDate()).padStart(2, '0') + '_' +
                      String(now.getHours()).padStart(2, '0') + 
                      String(now.getMinutes()).padStart(2, '0') + 
                      String(now.getSeconds()).padStart(2, '0');
    
    const captionName = 'caption_' + timestamp;
    const captionType = 'text'; // All text captions have type 'text'
    
    // Get podcast_id from current scene
    const currentScene = scenes.find(s => s.id == currentSceneId);
    const podcastId = currentScene ? currentScene.podcast_id : currentPodcastId;
    
    try {
        // Step 1: Create caption via AJAX
        console.log('📤 Sending create_caption request...');
        const fd = new FormData();
        fd.append('ajax_action', 'create_caption');
        fd.append('story_id', currentSceneId);
        fd.append('podcast_id', podcastId);
        fd.append('caption_type', captionType);
        fd.append('caption_name', captionName);
        
        const response = await fetch(location.href, {
            method: 'POST',
            body: fd
        });
        
        const text = await response.text();
        console.log('📥 Create caption raw response:', text);
        
        let result;
        try {
            result = JSON.parse(text);
        } catch(e) {
            console.error('❌ JSON parse error:', text.substring(0, 200));
            throw new Error('Server returned invalid JSON for create_caption');
        }
        
        console.log('📥 Parsed response:', result);
        
        if (!result.success) {
            console.error('❌ Create caption failed:', result.message);
            throw new Error(result.message || 'Failed to create caption');
        }
        
        const captionId = result.caption_id;
        console.log('✅ Caption created with ID:', captionId);
        console.log('Caption data:', result.caption_data);
        
        // Step 2: Reload captions to get the new data
        console.log('📤 Reloading scene captions...');
        await loadSceneCaptions(currentSceneId);
        console.log('✅ Scene captions reloaded');
        console.log('Current scene captions after:', Object.keys(sceneCaptions));
        
        // Step 3: Find the new caption
        const newCaption = sceneCaptions[captionType];
        console.log('Looking for new caption with type:', captionType);
        console.log('Found caption:', newCaption);
        
        if (!newCaption) {
            // Try to find by ID
            const foundById = Object.values(sceneCaptions).find(c => c.id == captionId);
            if (foundById) {
                console.log('✅ Found caption by ID:', foundById);
                // Add to canvas using found caption
                const scene = scenes.find(s => s.id == currentSceneId);
                foundById.text_content = 'Enter new text here';
                await addMainCaptionToFabric(scene, foundById);
                
                // Select the new caption
                setTimeout(() => {
                    selectCaption(captionId, foundById.caption_type);
                }, 100);
                
                // Show the typography panel
                toggleSceneSettings('typography');
                
                alert('✅ New text caption added successfully!');
                return;
            } else {
                throw new Error('New caption not found after creation');
            }
        }
        
        // Step 4: Add to canvas with default text
        const scene = scenes.find(s => s.id == currentSceneId);
        newCaption.text_content = 'Enter new text here';
        await addMainCaptionToFabric(scene, newCaption);
        console.log('✅ Text caption added to canvas');
        
        // Step 5: Force text visibility
        setTimeout(() => {
            forceTextVisibility();
        }, 100);
        
        // Step 6: Select the new caption
        setTimeout(() => {
            selectCaption(captionId, captionType);
            console.log('✅ New caption selected');
        }, 100);
        
        // Step 7: Show the typography panel
        toggleSceneSettings('typography');
        
        console.log('🎉 ===== TEXT CAPTION CREATION COMPLETE =====');
        alert('✅ New text caption added successfully!');
        
    } catch(error) {
        console.error('❌ Error in addNewCaption:', error);
        console.error('Error stack:', error.stack);
        alert('Failed to create text caption: ' + error.message);
    }
}        
        // ... rest of the function remains the same
// TEMPORARY DEBUG: Hide the background image/video
function debugHideBackground() {
    if (!fabricCanvas) return;
    
    console.log('🎨 Hiding background to check text...');
    
    // Remove or hide background image
    if (currentBackgroundImage) {
        console.log('Hiding background image');
        currentBackgroundImage.set('visible', false);
    }
    
    // Remove or hide background video
    if (currentBackgroundVideo) {
        console.log('Hiding background video');
        currentBackgroundVideo.set('visible', false);
    }
    
    // Make text BRIGHT and visible
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            console.log('Making text BRIGHT RED');
            obj.set({
                fill: '#ff0000',
                stroke: '#00ff00',
                strokeWidth: 3,
                backgroundColor: 'rgba(0,0,0,0.7)',
                fontSize: 60, // Make it larger
                opacity: 1,
                visible: true
            });
            obj.bringToFront();
        }
    });
    
    fabricCanvas.renderAll();
    console.log('✅ Background hidden, text should now be visible (bright red)');
}

// Also try removing the image completely
function debugRemoveBackground() {
    if (!fabricCanvas) return;
    
    console.log('🗑️ Removing background completely');
    
    if (currentBackgroundImage) {
        fabricCanvas.remove(currentBackgroundImage);
        currentBackgroundImage = null;
    }
    
    if (currentBackgroundVideo) {
        fabricCanvas.remove(currentBackgroundVideo);
        currentBackgroundVideo = null;
    }
    
    // Set a contrasting background color
    fabricCanvas.setBackgroundColor('#333333', fabricCanvas.renderAll.bind(fabricCanvas));
    
    // Make text stand out
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            obj.set({
                fill: '#ffff00', // Bright yellow
                stroke: '#ff0000',
                strokeWidth: 2,
                fontSize: 72,
                opacity: 1
            });
            obj.bringToFront();
        }
    });
    
    fabricCanvas.renderAll();
    console.log('✅ Background removed, text should be visible on gray');
}

// Run one of these
debugHideBackground();

// ULTIMATE DEBUG - Check everything about the text
function ultimateTextDebug() {
    console.log('🔍 ===== ULTIMATE TEXT DEBUG =====');
    
    if (!fabricCanvas) {
        console.log('❌ fabricCanvas is null');
        return;
    }
    
    // List ALL objects on canvas
    const objects = fabricCanvas.getObjects();
    console.log(`Total objects on canvas: ${objects.length}`);
    
    objects.forEach((obj, index) => {
        console.log(`\n--- Object ${index} ---`);
        console.log('Type:', obj.type);
        console.log('Class:', obj.constructor.name);
        
        if (obj.type === 'textbox' || obj.type === 'text') {
            console.log('📝 TEXT OBJECT FOUND!');
            console.log('  Text content:', obj.text);
            console.log('  Left:', obj.left);
            console.log('  Top:', obj.top);
            console.log('  Width:', obj.width);
            console.log('  Height:', obj.height);
            console.log('  Font size:', obj.fontSize);
            console.log('  Font family:', obj.fontFamily);
            console.log('  Fill color:', obj.fill);
            console.log('  Stroke:', obj.stroke);
            console.log('  Stroke width:', obj.strokeWidth);
            console.log('  Background color:', obj.backgroundColor);
            console.log('  Opacity:', obj.opacity);
            console.log('  Visible:', obj.visible);
            console.log('  Selectable:', obj.selectable);
            console.log('  Evented:', obj.evented);
            console.log('  Has controls:', obj.hasControls);
            console.log('  Canvas contains object:', fabricCanvas.contains(obj));
            
            // Force it to be visible with extreme measures
            console.log('🛠️ FORCING TEXT TO BE VISIBLE');
            obj.set({
                left: 100,
                top: 300,
                fontSize: 72,
                fill: '#FF0000',
                stroke: '#00FF00',
                strokeWidth: 4,
                backgroundColor: '#000000',
                opacity: 1,
                visible: true,
                selectable: true,
                evented: true,
                hasControls: true
            });
            obj.bringToFront();
        } else if (obj.type === 'image') {
            console.log('🖼️ Image object:');
            console.log('  Width:', obj.width);
            console.log('  Height:', obj.height);
            console.log('  Scale:', obj.scaleX, obj.scaleY);
            console.log('  Position:', obj.left, obj.top);
        }
    });
    
    // Force render
    fabricCanvas.renderAll();
    
    // Check canvas dimensions
    console.log('\n📐 Canvas dimensions:');
    console.log('  Width:', fabricCanvas.width);
    console.log('  Height:', fabricCanvas.height);
    console.log('  Viewport transform:', fabricCanvas.viewportTransform);
    
    // Check if canvas element exists in DOM
    const canvasEl = document.getElementById('fabricCanvas');
    console.log('\n🖥️ Canvas DOM element:');
    console.log('  Exists:', !!canvasEl);
    if (canvasEl) {
        const rect = canvasEl.getBoundingClientRect();
        console.log('  Width:', rect.width);
        console.log('  Height:', rect.height);
        console.log('  Position:', rect.left, rect.top);
        console.log('  Visible:', rect.width > 0 && rect.height > 0);
    }
    
    console.log('🔍 ===== END DEBUG =====');
    
    return objects.length;
}

// Run it
ultimateTextDebug();
// or
// debugRemoveBackground();




// ========== SAVE CURRENT IMAGE FIELD BEFORE NAVIGATION ==========
// ========== SAVE CURRENT IMAGE FIELD BEFORE NAVIGATION ==========
async function saveCurrentImageField() {
    if (!currentSceneId || !currentImageField) return true;
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return true;
    
    const filename = scene[currentImageField];
    const promptTextarea = document.getElementById('imagePrompt');
    const currentPrompt = promptTextarea ? promptTextarea.value.trim() : '';
    
    // Map field name to prompt field
    let promptField = 'prompt';
    if (currentImageField === 'image_file_1') promptField = 'prompt_1';
    else if (currentImageField === 'image_file_2') promptField = 'prompt_2';
    else if (currentImageField === 'image_file_3') promptField = 'prompt_3';
    else if (currentImageField === 'image_file_4') promptField = 'prompt_4';
    else if (currentImageField === 'image_file_5') promptField = 'prompt_5';
    
    console.log(`Saving ${currentImageField} = ${filename}, ${promptField} = ${currentPrompt}`);
    
    // Only save if there's a filename or prompt
    if (filename || currentPrompt) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'update_scene');
            fd.append('scene_id', currentSceneId);
            fd.append('image_field', currentImageField);
            fd.append('image_file', filename || '');
            fd.append('prompt', currentPrompt);
            
            const {data} = await safeFetch(fd);
            if (!data.success) {
                console.error('Failed to save image field:', data.message);
                return false;
            }
            
            // Update the scene in memory
            scene[currentImageField] = filename;
            scene[promptField] = currentPrompt;
            
            console.log(`✅ Saved ${currentImageField} for scene ${currentSceneId}`);
            return true;
            
        } catch(e) {
            console.error('Error saving image field:', e);
            return false;
        }
    }
    return true;
}


function saveSceneSettings() {
    console.log('saveSceneSettings called');
    if (!currentSceneId) return;
    
    // Build settings object based on current type
    let settings = {};
    
    if (currentSettingType === 'typography') {
        settings = {
            fontfamily: document.getElementById('sceneFontFamily')?.value || 'Inter',
            fontsize: parseInt(document.getElementById('sceneFontSize')?.value) || 28,
            fontcolor: document.getElementById('sceneFontColor')?.value || '#ffffff',
            fontcolor_bg: document.getElementById('sceneFontBgColor')?.value || '#000000',
            fontweight: document.getElementById('sceneFontWeight')?.value || '700',
            fontbg_enable: 1
        };
        
        // Update local sceneSettings
        if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
        sceneSettings[currentSceneId].typography = settings;
        
    } else if (currentSettingType === 'captions') {
        settings = {
            caption_style: document.getElementById('sceneCaptionStyle')?.value || 'typewriter',
            caption_position: document.getElementById('sceneCaptionPosition')?.value || 'bottom',
            caption_speed: parseFloat(document.getElementById('sceneCaptionSpeed')?.value) || 0.85,
            caption_alignment: document.getElementById('sceneCaptionAlignment')?.value || 'center'
        };
        
        if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
        sceneSettings[currentSceneId].captions = settings;
        
    } else if (currentSettingType === 'branding') {
        settings = {
            logo_name: document.getElementById('logoFileName')?.innerText || '',
            logo_size: document.getElementById('sceneLogoSize')?.value || '60',
            logo_position: document.getElementById('sceneLogoPosition')?.value || 'top-right',
            logo_enabled: document.getElementById('sceneLogoEnabled')?.checked ? 1 : 0
        };
        
        if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
        sceneSettings[currentSceneId].branding = settings;
        
    } else if (currentSettingType === 'audio') {
        settings = {
            audio_file: document.getElementById('currentAudioName')?.innerText || '',
            audio_volume: parseInt(document.getElementById('sceneAudioVolume')?.value) || 100
        };
        
        if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
        sceneSettings[currentSceneId].audio = settings;
    }
    
    // Save to database
    saveAllSettingsToDB(currentSceneId, settings);
    
    L(`✅ ${currentSettingType} settings saved for scene #${currentSceneId}`);
    updateOverlayBadges(currentSceneId);
    closeScenePanel();
}
async function saveAllSettingsToDB(sceneId, settings) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_settings');
        fd.append('scene_id', sceneId);
        
        // Add all settings to FormData
        Object.keys(settings).forEach(key => {
            fd.append(key, settings[key]);
        });
        
        const {data} = await safeFetch(fd);
        if (!data.success) {
            throw new Error(data.message || 'Failed to save settings');
        }
        
        console.log('Settings saved to database for scene:', sceneId);
        
    } catch(e) {
        console.error('Error saving settings:', e);
        L(`❌ Failed to save settings: ${e.message}`);
    }
}

async function saveToAllScenes() {
    console.log('saveToAllScenes called');
    if (!currentSceneId) return;
    
    let settings = {};
    
    // Get current settings based on type
    if (currentSettingType === 'typography') {
        settings = {
            fontfamily: document.getElementById('sceneFontFamily')?.value || 'Inter',
            fontsize: parseInt(document.getElementById('sceneFontSize')?.value) || 28,
            fontcolor: document.getElementById('sceneFontColor')?.value || '#ffffff',
            fontcolor_bg: document.getElementById('sceneFontBgColor')?.value || '#000000',
            fontweight: document.getElementById('sceneFontWeight')?.value || '700',
            fontbg_enable: 1
        };
    } else if (currentSettingType === 'captions') {
        settings = {
            caption_style: document.getElementById('sceneCaptionStyle')?.value || 'typewriter',
            caption_position: document.getElementById('sceneCaptionPosition')?.value || 'bottom',
            caption_speed: parseFloat(document.getElementById('sceneCaptionSpeed')?.value) || 0.85,
            caption_alignment: document.getElementById('sceneCaptionAlignment')?.value || 'center'
        };
    } else if (currentSettingType === 'branding') {
        settings = {
            logo_name: document.getElementById('logoFileName')?.innerText || '',
            logo_size: document.getElementById('sceneLogoSize')?.value || '60',
            logo_position: document.getElementById('sceneLogoPosition')?.value || 'top-right',
            logo_enabled: document.getElementById('sceneLogoEnabled')?.checked ? 1 : 0
        };
    } else if (currentSettingType === 'audio') {
        settings = {
            audio_file: document.getElementById('currentAudioName')?.innerText || '',
            audio_volume: parseInt(document.getElementById('sceneAudioVolume')?.value) || 100
        };
    }
    
    // Save to all scenes
    let successCount = 0;
    let errorCount = 0;
    
    for (const scene of scenes) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'save_scene_settings');
            fd.append('scene_id', scene.id);
            
            Object.keys(settings).forEach(key => {
                fd.append(key, settings[key]);
            });
            
            const {data} = await safeFetch(fd);
            if (data.success) {
                successCount++;
                // Update local sceneSettings
                if (!sceneSettings[scene.id]) sceneSettings[scene.id] = {};
                sceneSettings[scene.id][currentSettingType] = settings;
            } else {
                errorCount++;
            }
        } catch(e) {
            errorCount++;
            console.error('Error saving to scene', scene.id, e);
        }
    }
    
    L(`✅ ${currentSettingType} settings saved to ${successCount} scenes (${errorCount} failed)`);
    updateOverlayBadges(currentSceneId);
    closeScenePanel();
}

// ========== LOAD SCENE SETTINGS INTO PANEL ==========
function loadSceneSettingsIntoPanel(sceneId, type) {
    console.log('loadSceneSettingsIntoPanel called with:', sceneId, type);
    
    // First check if we have the scene in our data
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    if (type === 'typography') {
        const fontFamily = document.getElementById('sceneFontFamily');
        const fontSize = document.getElementById('sceneFontSize');
        const fontColor = document.getElementById('sceneFontColor');
        const fontBgColor = document.getElementById('sceneFontBgColor');
        const fontWeight = document.getElementById('sceneFontWeight');
        
        if (fontFamily) fontFamily.value = scene.fontfamily || 'Inter';
        if (fontSize) fontSize.value = scene.fontsize || '28';
        if (fontColor) fontColor.value = scene.fontcolor || '#ffffff';
        if (fontBgColor) fontBgColor.value = scene.fontcolor_bg || '#000000';
        if (fontWeight) fontWeight.value = scene.fontweight || '700';
        
    } else if (type === 'captions') {
        const captionStyle = document.getElementById('sceneCaptionStyle');
        const captionPosition = document.getElementById('sceneCaptionPosition');
        const captionSpeed = document.getElementById('sceneCaptionSpeed');
        const speedValue = document.getElementById('sceneSpeedValue');
        const captionAlignment = document.getElementById('sceneCaptionAlignment');
        
        if (captionStyle) captionStyle.value = scene.caption_style || 'typewriter';
        if (captionPosition) captionPosition.value = scene.caption_position || 'bottom';
        const speed = scene.caption_speed || '0.85';
        if (captionSpeed) captionSpeed.value = speed;
        if (speedValue) speedValue.innerText = speed + 'x';
        if (captionAlignment) captionAlignment.value = scene.caption_alignment || 'center';
        
    } else if (type === 'branding') {
        const companyName = document.getElementById('sceneCompanyName');
        const logoSize = document.getElementById('sceneLogoSize');
        const logoPosition = document.getElementById('sceneLogoPosition');
        const logoEnabled = document.getElementById('sceneLogoEnabled');
        const logoFileName = document.getElementById('logoFileName');
        
        if (companyName) companyName.value = 'VideoVizard'; // You might want to store this
        if (logoSize) logoSize.value = scene.logo_size || '60';
        if (logoPosition) logoPosition.value = scene.logo_position || 'top-right';
        if (logoEnabled) logoEnabled.checked = scene.logo_enabled == 1;
        if (logoFileName) logoFileName.innerText = scene.logo_name || 'No file chosen';
        
    } else if (type === 'audio') {
        const audioVolume = document.getElementById('sceneAudioVolume');
        const audioValue = document.getElementById('audioVolumeValue');
        const currentAudioName = document.getElementById('currentAudioName');
        
        if (audioVolume) audioVolume.value = scene.audio_volume || '100';
        if (audioValue) audioValue.innerText = (scene.audio_volume || '100') + '%';
        if (currentAudioName) currentAudioName.innerText = scene.audio_file || 'No audio selected';
    }
}


function useGlobalSettings() {
    console.log('useGlobalSettings called');
    // Remove scene-specific settings for this type
    if (sceneSettings[currentSceneId]) {
        delete sceneSettings[currentSceneId][currentSettingType];
    }
    
    // Update overlay badge
    updateOverlayBadges(currentSceneId);
    
    L('🔄 Using global ' + currentSettingType + ' settings for scene #' + currentSceneId);
    closeScenePanel();
}
// ========== THREE-DOT MENU FUNCTIONS ==========


// Prevent menu from closing when clicking inside it
document.getElementById('actionMenu')?.addEventListener('click', function(event) {
    event.stopPropagation();
});



// DEBUG FUNCTION - Call this to check text on canvas
function debugCanvasText() {
    console.log('🔍 ===== CANVAS TEXT DEBUG =====');
    console.log('Current scene ID:', currentSceneId);
    console.log('Total objects on canvas:', fabricCanvas.getObjects().length);
    
    const objects = fabricCanvas.getObjects();
    let textObjects = 0;
    
    objects.forEach((obj, index) => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            textObjects++;
            console.log(`📝 Text object ${index}:`, {
                type: obj.type,
                text: obj.text ? obj.text.substring(0, 30) : 'NO TEXT',
                captionId: obj.captionId,
                captionType: obj.captionType,
                visible: obj.visible,
                opacity: obj.opacity,
                left: obj.left,
                top: obj.top,
                width: obj.width,
                height: obj.height,
                selectable: obj.selectable,
                evented: obj.evented
            });
        }
    });
    
    console.log(`Total text objects: ${textObjects}`);
    console.log('sceneCaptions:', sceneCaptions);
    console.log('selectedCaptionId:', selectedCaptionId);
    console.log('🔍 ===== END DEBUG =====');
    
    return textObjects;
}

// Call this after scene loads
setTimeout(debugCanvasText, 2000);


// ========== IMAGE SETTINGS FUNCTIONS ==========
function selectImageFile(fieldName) {
    console.log('selectImageFile called with:', fieldName);
    
    // Update current image field
    currentImageField = fieldName;
    
    const scene = scenes[currentSceneIndex];
    if (!scene) return;
    
    // Get the filename from the scene
    const filename = scene[fieldName];
    
    // Update canvas with selected media
    const imagePreview = document.getElementById('imagePreview');
    const videoPreview = document.getElementById('videoPreview');
    const videoPlayButton = document.getElementById('videoPlayButton');
    
    if (filename && filename.trim() !== '') {
        const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
        
        if (isVideo) {
            // It's a video
            videoPreview.src = 'podcast_videos/' + filename + '?t=' + Date.now();
            videoPreview.style.display = 'block';
            imagePreview.style.display = 'none';
            if (videoPlayButton) videoPlayButton.style.display = 'flex';
            if (videoPlayButton) videoPlayButton.classList.remove('playing');
            videoPreview.pause();
            L(`🎬 Loaded video from ${fieldName} for scene ${currentSceneIndex + 1}`);
        } else {
            // It's an image
            imagePreview.src = 'podcast_images/' + filename + '?t=' + Date.now();
            imagePreview.style.display = 'block';
            videoPreview.style.display = 'none';
            if (videoPlayButton) videoPlayButton.style.display = 'none';
            L(`🖼️ Loaded image from ${fieldName} for scene ${currentSceneIndex + 1}`);
        }
    } else {
        // No file
        imagePreview.style.display = 'none';
        videoPreview.style.display = 'none';
        if (videoPlayButton) videoPlayButton.style.display = 'none';
        L(`⚠️ No media in ${fieldName} for scene ${currentSceneIndex + 1}`);
    }
    
    // Update border styles to show selection
    document.querySelectorAll('.image-thumb').forEach(thumb => {
        const thumbDiv = thumb.querySelector('div:first-child');
        if (thumbDiv) {
            thumbDiv.style.border = '1px solid var(--border)';
        }
    });
    
    // Highlight selected thumbnail
    const selectedThumb = event?.currentTarget?.querySelector('div:first-child');
    if (selectedThumb) {
        selectedThumb.style.border = '2px solid var(--info)';
    }
    
    // Load the corresponding prompt
    loadImagePrompt(fieldName);
}

function loadImagePrompt(fieldName) {
    console.log('loadImagePrompt called with:', fieldName);
    const scene = scenes[currentSceneIndex];
    if (!scene) return;
    
    const promptTextarea = document.getElementById('imagePrompt');
    if (!promptTextarea) return;
    
    // Map field name to prompt field
    let promptField = 'prompt'; // Default for image_file
    
    if (fieldName === 'image_file_1') promptField = 'prompt_1';
    else if (fieldName === 'image_file_2') promptField = 'prompt_2';
    else if (fieldName === 'image_file_3') promptField = 'prompt_3';
    else if (fieldName === 'image_file_4') promptField = 'prompt_4';
    else if (fieldName === 'image_file_5') promptField = 'prompt_5';
    
    // Get the prompt from scene
    const prompt = scene[promptField] || '';
    promptTextarea.value = prompt;
    
    console.log(`Loaded prompt for ${fieldName}:`, prompt.substring(0, 50));
    L(`📝 Loaded prompt for ${fieldName}`);
}

// ========== SAVE PROMPT TO DATABASE ==========
async function savePromptToDB(sceneId, fieldName) {
    const textarea = document.getElementById('imagePrompt');
    if (!textarea) return;
    
    const newPrompt = textarea.value.trim();
    const scene = scenes.find(s => s.id == sceneId);
    
    // Map field name to prompt field
    let promptField = 'prompt';
    if (fieldName === 'image_file_1') promptField = 'prompt_1';
    else if (fieldName === 'image_file_2') promptField = 'prompt_2';
    else if (fieldName === 'image_file_3') promptField = 'prompt_3';
    else if (fieldName === 'image_file_4') promptField = 'prompt_4';
    else if (fieldName === 'image_file_5') promptField = 'prompt_5';
    
    // Check if prompt actually changed
    if (scene && scene[promptField] === newPrompt) return;
    
    L('💾 Saving prompt for scene #' + sceneId + ' (' + fieldName + ')...');
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', newPrompt);
        fd.append('prompt_field', promptField);
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message || 'Unknown error');
        
        if (scene) scene[promptField] = newPrompt;
        L('✅ Prompt saved for scene #' + sceneId);
    } catch(e) {
        L('❌ Failed to save prompt: ' + e.message);
    }
}


// ========== GENERATE IMAGE FUNCTION (from imagegen.php) ==========
async function genOne(sceneId, fieldName, forceNew = true) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) { L('❌ Scene ' + sceneId + ' not found'); return; }
    
    // Get prompt from textarea
    const textarea = document.getElementById('imagePrompt');
    let originalPrompt = textarea ? textarea.value.trim() : '';
    
    if (!originalPrompt || originalPrompt.trim() === '') {
        L('❌ Scene #' + sceneId + ': No prompt');
        alert('Please enter a prompt first');
        return;
    }

    L('\n━━━ GENERATING IMAGE FOR SCENE #' + sceneId + ' ━━━');
    L('📝 Prompt: ' + originalPrompt.substring(0, 150) + '...');
    L('🎯 Target slot: ' + fieldName);
    
    // Step 1: Enhance prompt
    let enhanced, hashtags;
    
    try {
        L('🔄 Step 1: Enhancing prompt...');
        let fd = new FormData();
        fd.append('ajax_action', 'enhance_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', originalPrompt);
        let res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message || 'Unknown error');
        enhanced = res.data.enhanced_prompt;
        hashtags = res.data.hashtags;
        L('✅ Enhanced prompt ready');
        L('🏷️ Hashtags: ' + hashtags);
    } catch(e) {
        L('❌ ENHANCE FAILED: ' + e.message);
        alert('Failed to enhance prompt: ' + e.message);
        return;
    }
    
    // Generate new image
    let imageName;
    
    try {
        L('🎨 Generating new image...');
        let fd = new FormData();
        fd.append('ajax_action', 'generate_image');
        fd.append('scene_id', sceneId);
        fd.append('enhanced_prompt', enhanced);
        fd.append('hashtags', hashtags);
        let res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message || 'Generation failed');
        imageName = res.data.image_name;
        L('✅ Image generated: ' + imageName);
    } catch(e) {
        L('❌ GENERATION FAILED: ' + e.message);
        alert('Failed to generate image: ' + e.message);
        return;
    }
    
    // Update scene in DB with the specific image slot
    try {
        L('💾 Updating database with ' + fieldName + ' = ' + imageName);
        
        let fd = new FormData();
        fd.append('ajax_action', 'update_scene');
		fd.append('scene_id', sceneId);
		fd.append('image_field', fieldName); // Make sure this is using fieldName parameter
		fd.append('image_file', imageName);
		fd.append('prompt', enhanced);
        
        let res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message);
        
        // Update the scene in memory
        scene[fieldName] = imageName;
        
        // Map field name to prompt field and save enhanced prompt
        let promptField = 'prompt';
        if (fieldName === 'image_file_1') promptField = 'prompt_1';
        else if (fieldName === 'image_file_2') promptField = 'prompt_2';
        else if (fieldName === 'image_file_3') promptField = 'prompt_3';
        else if (fieldName === 'image_file_4') promptField = 'prompt_4';
        else if (fieldName === 'image_file_5') promptField = 'prompt_5';
        
        scene[promptField] = enhanced;
        
        // Update the textarea with enhanced prompt
        if (textarea) textarea.value = enhanced;
        
        // Update the thumbnail
        if (currentImageField === fieldName) {
            const imagePreview = document.getElementById('imagePreview');
            imagePreview.src = 'podcast_images/' + imageName + '?t=' + Date.now();
            imagePreview.style.display = 'block';
        }
        
        // Reload thumbnails
        loadImageThumbnails();
        
        L(`✅ Scene #${sceneId} complete! Image saved to ${fieldName}`);
        
    } catch(e) {
        L('❌ DB UPDATE FAILED: ' + e.message);
        alert('Failed to update database: ' + e.message);
    }
}
// ========== UPDATE PANEL TITLE WITH CURRENT SCENE ==========
function updatePanelTitle() {
    const panelTitle = document.getElementById('scenePanelTitle');
    if (!panelTitle) return;
    
    // Get the current scene to find its sequence number
    const currentScene = scenes[currentSceneIndex];
    const sceneSeqNo = currentScene ? (currentScene.seq_no || (currentSceneIndex + 1)) : (currentSceneIndex + 1);
    
    // Update the sequence number in the title
    const seqNoSpan = document.getElementById('currentSceneSeqNo');
    if (seqNoSpan) {
        seqNoSpan.innerText = sceneSeqNo;
    } else {
        // If span doesn't exist, update the whole title
        const titles = {
            'typography': '🅰️ Typography Settings',
            'captions': '✍️ Caption Settings',
            'branding': '🏷️ Branding Settings',
            'images': '🌄 Image Settings',
            'audio': '🔊 Audio Settings'
        };
        panelTitle.innerHTML = titles[currentSettingType] + ' - Scene <span id="currentSceneSeqNo">' + sceneSeqNo + '</span>';
    }
}
// ========== REGENERATE IMAGE ==========
async function regenerateImage() {
    console.log('regenerateImage called for:', currentImageField);
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    if (!currentImageField) {
        currentImageField = 'image_file';
    }
    
    // Save current prompt before regenerating
    const promptTextarea = document.getElementById('imagePrompt');
    const currentPrompt = promptTextarea ? promptTextarea.value.trim() : '';
    
    if (!currentPrompt) {
        alert('Please enter a prompt first');
        return;
    }
    
    await genOne(currentSceneId, currentImageField, true);
}

function loadImageThumbnails() {
    console.log('loadImageThumbnails called for scene index:', currentSceneIndex);
    const scene = scenes[currentSceneIndex];
    if (!scene) {
        console.log('No scene found at index:', currentSceneIndex);
        return;
    }
    
    console.log('Loading thumbnails for scene:', scene);
    
    const imageFields = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4', 'image_file_5'];
    
    imageFields.forEach(field => {
        const thumb = document.getElementById('thumb_' + field);
        const placeholder = document.getElementById('placeholder_' + field);
        const videoIndicator = document.getElementById('video_indicator_' + field);
        
        if (!thumb || !placeholder) {
            console.log('Missing elements for field:', field);
            return;
        }
        
        const filename = scene[field];
        console.log(`Field ${field}:`, filename);
        
        if (filename && filename.trim() !== '') {
            // Check if it's a video file
            const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
            
            if (isVideo) {
                // It's a video - show video thumbnail with play icon
                thumb.style.display = 'none';
                placeholder.style.display = 'flex';
                placeholder.innerHTML = '🎬'; // Film icon for videos
                if (videoIndicator) {
                    videoIndicator.style.display = 'flex';
                }
                console.log(`Field ${field} is a video:`, filename);
            } else {
                // It's an image
                thumb.src = 'podcast_images/' + filename + '?t=' + Date.now();
                thumb.style.display = 'block';
                placeholder.style.display = 'none';
                if (videoIndicator) {
                    videoIndicator.style.display = 'none';
                }
                console.log(`Field ${field} is an image:`, filename);
            }
        } else {
            // No file
            thumb.style.display = 'none';
            placeholder.style.display = 'flex';
            placeholder.innerHTML = '🖼️';
            if (videoIndicator) {
                videoIndicator.style.display = 'none';
            }
            console.log(`Field ${field} is empty`);
        }
    });
}






// ========== DROPDOWN FUNCTIONS ==========
function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        // Close all other dropdowns first
        document.querySelectorAll('.emoji-dropdown').forEach(d => d.style.display = 'none');
        dropdown.style.display = 'block';
    }
}

function hideDropdown(dropdownId) {
    document.getElementById(dropdownId).style.display = 'none';
}
// Restore panel content when caption is selected
// ========== RESTORE PANEL CONTENT ==========
function restorePanelContent() {
    console.log('🔄 Restoring panel content');
    
    const panel = document.getElementById('scene-typography-captions');
    if (panel && panel.dataset.originalContent) {
        panel.innerHTML = panel.dataset.originalContent;
        console.log('✅ Panel content restored from original');
    } else {
        console.log('⚠️ No original content stored to restore');
    }
    
    // Re-enable text controls
    const textControls = [
        'sceneFontFamily', 
        'sceneFontSize', 
        'sceneFontColor', 
        'sceneFontBgColor', 
        'sceneFontBgEnable'
    ];
    
    textControls.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = false;
            el.style.opacity = '1';
            el.style.pointerEvents = 'auto';
        }
    });
    
    // Re-enable dropdown buttons
    const dropdowns = document.querySelectorAll('.emoji-main-btn');
    dropdowns.forEach(btn => {
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        }
    });
    
    // Show effect panels (they will be hidden by default)
    const effectPanels = ['outlineSettings', 'strokeSettings', 'speedSettings'];
    effectPanels.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none'; // Keep hidden by default
    });
    
    // Update panel title back to default
    const seqNoSpan = document.getElementById('currentSceneSeqNoCombined');
    if (seqNoSpan) {
        const scene = scenes.find(s => s.id == currentSceneId);
        seqNoSpan.innerText = scene?.seq_no || (currentSceneIndex + 1);
    }
}


// ========== REAL-TIME UPDATE FUNCTIONS ==========






// Set text position (TOP, CENTER, BOTTOM)
// Set text position (TOP, CENTER, BOTTOM)


// Toggle text style (BOLD, ITALIC, UNDERLINE, STRIKE)


// ========== REAL-TIME UPDATE FUNCTIONS WITH NULL CHECKS ==========

// ========== UPDATE FONT FAMILY ==========
function updateFontFamily(value) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    captionText.set('fontFamily', value);
    fabricCanvas.renderAll();
    
    // Debounce save
    if (captionText._fontFamilyTimeout) clearTimeout(captionText._fontFamilyTimeout);
    captionText._fontFamilyTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, { fontfamily: value });
    }, 500);
}

// ========== UPDATE FONT SIZE ==========
function updateFontSize(value) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    captionText.set('fontSize', parseInt(value));
    fabricCanvas.renderAll();
    
    if (captionText._fontSizeTimeout) clearTimeout(captionText._fontSizeTimeout);
    captionText._fontSizeTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, { fontsize: parseInt(value) });
    }, 500);
}

// ========== UPDATE TEXT COLOR ==========
function updateTextColor(value) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    captionText.set('fill', value);
    fabricCanvas.renderAll();
    
    if (captionText._colorTimeout) clearTimeout(captionText._colorTimeout);
    captionText._colorTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, { fontcolor: value });
    }, 500);
}

// ========== UPDATE BACKGROUND COLOR - FIXED ==========
async function updateBgColor(value) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Updating background color to:', value);
    
    // Update on canvas
    if (document.getElementById('sceneFontBgEnable').checked) {
        captionText.set('backgroundColor', value);
        fabricCanvas.renderAll();
    }
    
    // Save to database
    await saveCaptionToDatabase(captionText.captionId, { 
        bg_color: value
    });
}
// ========== TOGGLE BACKGROUND ENABLE - FIXED ==========
// ========== TOGGLE BACKGROUND ENABLE - UPDATED ==========
async function toggleBgEnable(checked) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        
        // Uncheck if no caption selected
        if (!captionText) {
            document.getElementById('enableBgCheckbox').checked = false;
        }
        return;
    }
    
    console.log('Toggling background enable:', checked);
    
    // Get current background color from picker
    const bgColor = document.getElementById('bgColorPicker').value;
    
    // Update canvas
    if (checked) {
        captionText.set('backgroundColor', bgColor);
    } else {
        captionText.set('backgroundColor', 'transparent');
    }
    
    fabricCanvas.renderAll();
    
    // Save to database
    await saveCaptionToDatabase(captionText.captionId, { 
        bg_enabled: checked ? 1 : 0,
        bg_color: bgColor // Always save the color too
    });
    
    console.log('Background enabled saved as:', checked);
}





async function setTextPosition(position) {
    if (!captionText || !fabricCanvas) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting text position to:', position);
    
    let top;
    const canvasHeight = fabricCanvas.height;
    const textHeight = captionText.height || 100;
    
    if (position === 'top') {
        top = 50;
    } else if (position === 'center') {
        top = (canvasHeight / 2) - (textHeight / 2);
    } else if (position === 'bottom') {
        top = canvasHeight - textHeight - 50;
    }
    
    // Update canvas
    captionText.set('top', Math.round(top));
    fabricCanvas.renderAll();
    
    // Update hidden field
    const posField = document.getElementById('sceneCaptionPosition');
    if (posField) posField.value = position;
    
    // Auto-save both position and coordinate
    autoSaveCaptionProperty(captionText, 'caption_position', position);
    autoSaveCaptionProperty(captionText, 'position_y', Math.round(top));
    
    hideDropdown('positionDropdown');
}

// ========== SET ANIMATION STYLE ==========
async function setAnimationStyle(style) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting animation style:', style);
    
    // Update hidden field
    const styleField = document.getElementById('sceneCaptionStyle');
    if (styleField) styleField.value = style;
    
    // Show/hide speed settings
    const speedSettings = document.getElementById('speedSettings');
    if (speedSettings) {
        speedSettings.style.display = (style === 'typewriter' || style === 'scroll') ? 'block' : 'none';
    }

    // Update animationStyle on the fabric object and preview immediately
    captionText.animationStyle = style;
    captionText.originalText = captionText.originalText || captionText.text;
    startCaptionAnimation(captionText);
    
    // Save to database
    await saveCaptionToDatabase(captionText.captionId, { animation_style: style });
    
    hideDropdown('animationDropdown');
}
// ========== UPDATE SPEED ==========
async function updateSpeed(value) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    const speedValue = document.getElementById('sceneSpeedValue');
    if (speedValue) speedValue.innerText = value + 'x';
    
    const speedField = document.getElementById('sceneCaptionSpeed');
    if (speedField) speedField.value = value;
    
    await saveCaptionToDatabase(captionText.captionId, { animation_speed: parseFloat(value) });
}

// Called by animSpeedSlider oninput — updates display and replays animation
function previewAnimSpeed(value) {
    // Update display label
    const display = document.getElementById('animSpeedDisplay');
    if (display) display.innerText = parseFloat(value).toFixed(1) + 'x';

    // Update speed on fabric object and replay animation
    if (captionText && captionText.captionId) {
        captionText.animationSpeed = parseFloat(value);
        if (captionText.animationStyle && captionText.animationStyle !== 'none' && captionText.animationStyle !== 'static') {
            startCaptionAnimation(captionText);
        }
        // Save to DB (debounced)
        clearTimeout(window._speedSaveTimer);
        window._speedSaveTimer = setTimeout(() => {
            saveCaptionToDatabase(captionText.captionId, { animation_speed: parseFloat(value) });
        }, 600);
    }
}



// ========== UPDATE STROKE ==========
function updateStroke() {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    const color = document.getElementById('strokeColor').value;
    const width = parseInt(document.getElementById('strokeWidth').value);
    
    captionText.set({
        stroke: color,
        strokeWidth: width
    });
    
    fabricCanvas.renderAll();
    
    // Save to database with debounce
    if (captionText._strokeTimeout) clearTimeout(captionText._strokeTimeout);
    captionText._strokeTimeout = setTimeout(async () => {
        await saveCaptionToDatabase(captionText.captionId, {
            stroke_color: color,
            stroke_width: width,
            stroke_enabled: 1
        });
        console.log('✅ Stroke settings saved');
    }, 500);
}

// ========== TOGGLE TEXT STYLE ==========
async function toggleTextStyle(style) {
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Toggling text style:', style);
    
    const updates = {};
    const captionId = captionText.captionId;
    
    if (style === 'bold') {
        const newValue = captionText.fontWeight === 'bold' ? 'normal' : 'bold';
        captionText.set('fontWeight', newValue);
        updates.fontweight = newValue;
        console.log('Bold set to:', newValue);
    } 
    else if (style === 'italic') {
        const newValue = captionText.fontStyle === 'italic' ? 'normal' : 'italic';
        captionText.set('fontStyle', newValue);
        updates.fontstyle = newValue;
        console.log('Italic set to:', newValue);
    } 
    else if (style === 'underline') {
        const newValue = !captionText.underline;
        captionText.set('underline', newValue);
        updates.underline = newValue ? 1 : 0;
        console.log('Underline set to:', newValue);
    } 
    else if (style === 'linethrough') {
        const newValue = !captionText.linethrough;
        captionText.set('linethrough', newValue);
        updates.linethrough = newValue ? 1 : 0;
        console.log('Strike set to:', newValue);
    }
    
    fabricCanvas.renderAll();
    
    // Save to database if we have a caption ID
    if (captionId && Object.keys(updates).length > 0) {
        await saveCaptionToDatabase(captionId, updates);
        console.log(`✅ Text style saved:`, updates);
    }
    
    hideDropdown('styleDropdown');
}

// Toggle effect (outline or stroke)
function toggleEffect(effect) {
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    const isEnabled = document.getElementById(effect + 'Enable').checked;
    const controls = document.getElementById(effect + 'Controls');
    
    if (isEnabled) {
        controls.style.display = 'block';
        
        if (effect === 'outline') {
            const color = document.getElementById('outlineColor').value;
            const width = parseInt(document.getElementById('outlineWidth').value);
            
            captionText.set({
                stroke: color,
                strokeWidth: width,
                paintFirst: 'stroke'
            });
            
            saveCaptionToDatabase(captionText.captionId, {
                outline_enabled: 1,
                outline_color: color,
                outline_width: width
            });
        } else {
            const color = document.getElementById('strokeColor').value;
            const width = parseInt(document.getElementById('strokeWidth').value);
            
            captionText.set({
                stroke: color,
                strokeWidth: width
            });
            
            saveCaptionToDatabase(captionText.captionId, {
                stroke_enabled: 1,
                stroke_color: color,
                stroke_width: width
            });
        }
    } else {
        controls.style.display = 'none';
        
        if (effect === 'outline') {
            // If stroke is also disabled, remove stroke completely
            if (!document.getElementById('strokeEnable').checked) {
                captionText.set({
                    stroke: null,
                    strokeWidth: 0
                });
            }
            saveCaptionToDatabase(captionText.captionId, { outline_enabled: 0 });
        } else {
            // If outline is also disabled, remove stroke completely
            if (!document.getElementById('outlineEnable').checked) {
                captionText.set({
                    stroke: null,
                    strokeWidth: 0
                });
            }
            saveCaptionToDatabase(captionText.captionId, { stroke_enabled: 0 });
        }
    }
    
    fabricCanvas.renderAll();
    updateActiveEffectsDisplay();
}
// Update outline
function updateOutline() {
    if (!captionText || !document.getElementById('outlineEnable').checked) return;
    
    const color = document.getElementById('outlineColor').value;
    const width = parseInt(document.getElementById('outlineWidth').value);
    
    document.getElementById('outlineWidthValue').textContent = width;
    
    captionText.set({
        stroke: color,
        strokeWidth: width,
        paintFirst: 'stroke'
    });
    
    fabricCanvas.renderAll();
    
    // Debounce save
    clearTimeout(window.outlineTimeout);
    window.outlineTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            outline_color: color,
            outline_width: width
        });
    }, 500);
}

// Update stroke
function updateStroke() {
    if (!captionText || !document.getElementById('strokeEnable').checked) return;
    
    const color = document.getElementById('strokeColor').value;
    const width = parseInt(document.getElementById('strokeWidth').value);
    
    document.getElementById('strokeWidthValue').textContent = width;
    
    captionText.set({
        stroke: color,
        strokeWidth: width
    });
    
    fabricCanvas.renderAll();
    
    clearTimeout(window.strokeTimeout);
    window.strokeTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            stroke_color: color,
            stroke_width: width
        });
    }, 500);
}

// Update active effects display
function updateActiveEffectsDisplay() {
    const display = document.getElementById('activeEffects');
    const outlineEnabled = document.getElementById('outlineEnable')?.checked;
    const strokeEnabled = document.getElementById('strokeEnable')?.checked;
    
    let activeEffects = [];
    if (outlineEnabled) activeEffects.push('🔲 Outline');
    if (strokeEnabled) activeEffects.push('✏️ Stroke');
    
    if (activeEffects.length > 0) {
        display.innerHTML = activeEffects.join(' · ');
        display.style.background = '#e0f2fe';
        display.style.color = '#0369a1';
    } else {
        display.innerHTML = 'No effects active';
        display.style.background = '#f1f5f9';
        display.style.color = '#475569';
    }
}

// Load effects from caption
function updateEffectsFromCaption() {
    if (!captionText) return;
    
    // Check if outline/stroke is enabled
    const hasStroke = captionText.stroke && captionText.strokeWidth > 0;
    
    // Try to determine if it's outline or stroke based on paintFirst
    const isOutline = captionText.paintFirst === 'stroke';
    
    if (hasStroke) {
        if (isOutline) {
            document.getElementById('outlineEnable').checked = true;
            document.getElementById('outlineControls').style.display = 'block';
            document.getElementById('outlineColor').value = captionText.stroke;
            document.getElementById('outlineWidth').value = captionText.strokeWidth;
            document.getElementById('outlineWidthValue').textContent = captionText.strokeWidth;
        } else {
            document.getElementById('strokeEnable').checked = true;
            document.getElementById('strokeControls').style.display = 'block';
            document.getElementById('strokeColor').value = captionText.stroke;
            document.getElementById('strokeWidth').value = captionText.strokeWidth;
            document.getElementById('strokeWidthValue').textContent = captionText.strokeWidth;
        }
    }
    
    updateActiveEffectsDisplay();
}

// ========== SAVE SCENE UPDATES TO DATABASE ==========
async function saveSceneUpdates(sceneId, updates) {
    if (!sceneId) return false;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_settings');
        fd.append('scene_id', sceneId);
        
        // Add all updates
        Object.keys(updates).forEach(key => {
            let value = updates[key];
            // Handle null values
            if (value === null || value === undefined) {
                value = '';
            }
            fd.append(key, value);
        });
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            console.log('✅ Saved to database:', updates);
            
            // Update local scene data
            const scene = scenes.find(s => s.id == sceneId);
            if (scene) {
                Object.assign(scene, updates);
            }
            return true;
        } else {
            console.error('Save failed:', data.message);
            return false;
        }
    } catch(e) {
        console.error('Save error:', e);
        return false;
    }
}
// Load settings from row into panel
// ========== LOAD COMBINED SETTINGS INTO PANEL ==========
function loadCombinedSettingsIntoPanel(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    console.log('Loading combined settings for scene:', sceneId);
    
    // Load typography settings for the new emoji-based UI
    const fontFamily = document.getElementById('sceneFontFamily');
    const fontSize = document.getElementById('sceneFontSize');
    const fontColor = document.getElementById('sceneFontColor');
    const fontBgColor = document.getElementById('sceneFontBgColor');
    const fontBgEnable = document.getElementById('sceneFontBgEnable');
    
    if (fontFamily) fontFamily.value = scene.fontfamily || 'Inter';
    if (fontSize) fontSize.value = scene.fontsize || '28';
    if (fontColor) fontColor.value = scene.fontcolor || '#ffffff';
    if (fontBgColor) fontBgColor.value = scene.fontcolor_bg || '#000000';
    if (fontBgEnable) fontBgEnable.checked = scene.fontbg_enable != 0;
    
    // Load caption settings (these are now hidden inputs but we need them for saving)
    const captionStyle = document.getElementById('sceneCaptionStyle');
    const captionPosition = document.getElementById('sceneCaptionPosition');
    const captionSpeed = document.getElementById('sceneCaptionSpeed');
    const speedValue = document.getElementById('sceneSpeedValue');
    const captionAlignment = document.getElementById('sceneCaptionAlignment');
    
    if (captionStyle) captionStyle.value = scene.caption_style || 'typewriter';
    if (captionPosition) captionPosition.value = scene.caption_position || 'bottom';
    const speed = scene.caption_speed || '0.85';
    if (captionSpeed) captionSpeed.value = speed;
    if (speedValue) speedValue.innerText = speed + 'x';
    if (captionAlignment) captionAlignment.value = scene.caption_alignment || 'center';
    
    // Update UI based on loaded values
    setTimeout(() => {
        if (captionText) {
            // Update alignment button appearance based on current text alignment
            const alignment = captionText.textAlign || 'center';
            highlightAlignmentButton(alignment);
            
            // Update style buttons appearance
            updateStyleButtonAppearance();
            
            // Update effect buttons appearance
            updateEffectButtonAppearance();
        }
        
        // Set animation style dropdown and show/hide speed settings
        const currentStyle = document.getElementById('sceneCaptionStyle').value;
        const speedSettings = document.getElementById('speedSettings');
        if (speedSettings) {
            if (currentStyle === 'typewriter' || currentStyle === 'scroll') {
                speedSettings.style.display = 'block';
            } else {
                speedSettings.style.display = 'none';
            }
        }
        
        // Highlight the active alignment in the dropdown
        const currentAlignment = document.getElementById('sceneCaptionAlignment').value;
        highlightAlignmentButton(currentAlignment);
        
    }, 100);
}


// ========== EFFECT UPDATE FUNCTIONS ==========
function updateShadow() {
    if (captionText && captionText.shadow) {
        captionText.set('shadow', {
            color: document.getElementById('shadowColor').value,
            blur: parseInt(document.getElementById('shadowBlur').value),
            offsetX: parseInt(document.getElementById('shadowOffsetX').value),
            offsetY: parseInt(document.getElementById('shadowOffsetY').value)
        });
        fabricCanvas.renderAll();
    }
}

function updateGradient() {
    if (captionText && captionText.fill && captionText.fill.type === 'linear') {
        const color1 = document.getElementById('gradientColor1').value;
        const color2 = document.getElementById('gradientColor2').value;
        const direction = document.getElementById('gradientDirection').value;
        
        let coords;
        if (direction === 'left-to-right') {
            coords = { x1: 0, y1: 0, x2: captionText.width, y2: 0 };
        } else if (direction === 'top-to-bottom') {
            coords = { x1: 0, y1: 0, x2: 0, y2: captionText.height };
        } else {
            coords = { x1: 0, y1: 0, x2: captionText.width, y2: captionText.height };
        }
        
        captionText.set('fill', new fabric.Gradient({
            type: 'linear',
            coords: coords,
            colorStops: [
                { offset: 0, color: color1 },
                { offset: 1, color: color2 }
            ]
        }));
        fabricCanvas.renderAll();
    }
}


//********************************************  image/sticker *************************
// ========== IMAGE CAPTION FUNCTIONS ==========

// Load image captions for current scene
async function loadImageCaptions() {
    if (!currentSceneId) return;
    
    console.log('🖼️ Loading image captions for scene:', currentSceneId);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_scene_captions');
        fd.append('scene_id', currentSceneId);
        
        const {data} = await safeFetch(fd);
        
        if (data.success && data.captions) {
            // Filter image captions
            const imageCaptions = data.captions.filter(c => c.media_type === 'image');
            
            // Update the grid
            renderImageCaptionsGrid(imageCaptions);
            
            // Update existing list
            renderExistingImageCaptions(imageCaptions);
        }
    } catch(e) {
        console.error('Error loading image captions:', e);
    }
}

// Render image captions grid
function renderImageCaptionsGrid(captions) {
    const grid = document.getElementById('imageCaptionsGrid');
    if (!grid) return;
    
    if (!captions || captions.length === 0) {
        grid.innerHTML = '<div style="color: var(--muted); font-size: 12px; padding: 10px;">No image captions yet</div>';
        return;
    }
    
    let html = '';
    captions.forEach(cap => {
        html += `
        <div class="image-caption-thumb" onclick="selectImageCaption(${cap.id})" data-id="${cap.id}" style="width: 80px; text-align: center; cursor: pointer; position: relative;">
            <div style="width: 80px; height: 80px; border: 2px solid transparent; border-radius: 8px; overflow: hidden; margin-bottom: 4px; background: #f1f5f9; position: relative;">
                <img src="podcast_images/${cap.image_file}" style="width:100%; height:100%; object-fit: cover;" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%231e293b\'/%3E%3Ctext x=\'50\' y=\'50\' font-family=\'Arial\' font-size=\'12\' fill=\'%2394a3b8\' text-anchor=\'middle\' dy=\'.3em\'%3E🖼️%3C/text%3E%3C/svg%3E'">
                <div class="global-badge" style="position: absolute; top: 2px; right: 2px; background: ${cap.is_global ? '#10b981' : '#94a3b8'}; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center;">${cap.is_global ? '🌍' : ''}</div>
            </div>
            <div style="font-size: 9px; color: var(--muted); word-break: break-word;">${cap.caption_name || 'Image'}</div>
        </div>`;
    });
    
    grid.innerHTML = html;
}

// Render existing image captions list
function renderExistingImageCaptions(captions) {
    const list = document.getElementById('existingImageCaptions');
    if (!list) return;
    
    if (!captions || captions.length === 0) {
        list.innerHTML = '<div style="color: var(--muted); font-size: 12px; padding: 10px; text-align: center;">No saved image captions</div>';
        return;
    }
    
    let html = '';
    captions.forEach(cap => {
        html += `
        <div style="display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid var(--border);">
            <div style="width: 40px; height: 40px; border-radius: 4px; overflow: hidden; background: #f1f5f9;">
                <img src="podcast_images/${cap.image_file}" style="width:100%; height:100%; object-fit: cover;">
            </div>
            <div style="flex: 1;">
                <div style="font-size: 12px; font-weight: 600;">${cap.caption_name || 'Image Caption'}</div>
                <div style="font-size: 10px; color: var(--muted);">${cap.image_file}</div>
            </div>
            <div style="display: flex; gap: 5px;">
                <button class="panel-btn" onclick="addImageCaptionToScene(${cap.id})" style="background: var(--info); color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer;">➕</button>
                <button class="panel-btn" onclick="deleteImageCaption(${cap.id})" style="background: #dc2626; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer;">🗑️</button>
            </div>
        </div>`;
    });
    
    list.innerHTML = html;
}

// Upload image caption
async function uploadImageCaption() {
    const fileInput = document.getElementById('imageCaptionUpload');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select an image file');
        return;
    }
    
    const file = fileInput.files[0];
    
    // Validate file type
    if (!file.type.startsWith('image/')) {
        alert('Please select an image file');
        return;
    }
    
    // Validate size (max 5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('Image must be less than 5MB');
        return;
    }
    
    L(`📤 Uploading image caption: ${file.name}`);
    
    const formData = new FormData();
    formData.append('ajax_action', 'upload_image');
    formData.append('image', file);
    formData.append('prompt', 'Image Caption');
    
    try {
        const response = await fetch(location.href, {
            method: 'POST',
            body: formData
        });
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            throw new Error('Invalid server response');
        }
        
        if (!data.success) {
            throw new Error(data.message || 'Upload failed');
        }
        
        L(`✅ Image uploaded: ${data.image_name}`);
        
        // Create caption with this image
        await createImageCaption(data.image_name, file.name);
        
    } catch(error) {
        console.error('Upload error:', error);
        alert('Upload failed: ' + error.message);
    }
    
    fileInput.value = '';
}

// Create image caption
async function createImageCaption(imageFile, originalName) {
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Generate caption name
    const captionName = 'Image ' + new Date().toLocaleTimeString().replace(/:/g, '-');
    const captionType = 'image_' + Date.now();
    
    const applyToAll = document.getElementById('applyImageCaptionToAll')?.checked || false;
    
    L(`➕ Creating image caption: ${captionName}`);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'create_caption');
        fd.append('story_id', currentSceneId);
        fd.append('caption_type', captionType);
        fd.append('caption_name', captionName);
        fd.append('media_type', 'image');
        
        const {data} = await safeFetch(fd);
        
        if (data.success) {
            // Now update with image file
            const updateFd = new FormData();
            updateFd.append('ajax_action', 'save_caption');
            updateFd.append('caption_id', data.caption_id);
            updateFd.append('image_file', imageFile);
            updateFd.append('media_type', 'image');
            updateFd.append('width', 200);
            updateFd.append('position_x', 100);
            updateFd.append('position_y', 100);
            
            const updateRes = await safeFetch(updateFd);
            
            if (updateRes.data.success) {
                L(`✅ Image caption created`);
                
                if (applyToAll) {
                    await duplicateImageCaptionToAllScenes(data.caption_id);
                } else {
                    // Add to current scene
                    await addImageCaptionToCanvas(data.caption_id, imageFile);
                }
                
                // Reload captions
                await loadSceneCaptions(currentSceneId);
                await loadImageCaptions();
            }
        }
    } catch(e) {
        console.error('Error creating image caption:', e);
        alert('Error: ' + e.message);
    }
}

// Add image caption to canvas
async function addImageCaptionToCanvas(captionId, imageFile) {
    if (!fabricCanvas) return;
    
    console.log('🖼️ Adding image caption to canvas:', imageFile);
    
    return new Promise((resolve) => {
        fabric.Image.fromURL('podcast_images/' + imageFile, (img) => {
            // Set initial size and position
            const maxWidth = 200;
            const scale = maxWidth / img.width;
            
            img.set({
                left: 100,
                top: 200,
                scaleX: scale,
                scaleY: scale,
                selectable: true,
                hasControls: true,
                hasBorders: true,
                cornerSize: 10,
                transparentCorners: false,
                captionId: captionId,
                captionType: 'image'
            });
            
            fabricCanvas.add(img);
            fabricCanvas.setActiveObject(img);
            fabricCanvas.renderAll();
            
            // Save position when modified
            img.on('modified', function() {
                autoSaveImageCaptionProperties(this);
            });
            
            resolve(img);
        });
    });
}
// ========== WRAPPER FUNCTIONS FOR THE BUTTONS ==========

// Upload image for a NEW box (creates new caption)
function uploadImageForNewBox() {
    console.log('📤 Upload button clicked - creating new image box');
    document.getElementById('newImageBoxUpload').click();
}

// Open sticker library for a NEW box
function openStickerLibraryForNewBox() {
    console.log('📚 Sticker library button clicked - creating new image box');
    openStickerLibrary();
}

// Remove image from selected box
async function removeImageFromSelectedBox() {
    if (!selectedCaptionId) {
        showNoBoxWarning();
        return;
    }
    
    if (!confirm('Remove the image from this box? It will become a text box again.')) return;
    
    const fd = new FormData();
    fd.append('ajax_action', 'save_caption');
    fd.append('caption_id', selectedCaptionId);
    fd.append('image_file', '');
    fd.append('media_type', 'text');
    
    try {
        const {data} = await safeFetch(fd);
        
        if (data.success) {
            // Update local data
            const caption = findCaptionById(selectedCaptionId);
            if (caption) {
                caption.image_file = '';
                caption.media_type = 'text';
            }
            
            // Remove image from canvas
            if (fabricCanvas) {
                const objects = fabricCanvas.getObjects();
                objects.forEach(obj => {
                    if (obj.captionId == selectedCaptionId && obj.mediaType === 'image') {
                        fabricCanvas.remove(obj);
                    }
                });
                
                // Re-add text version if there's text content
                if (caption && caption.text_content) {
                    const scene = scenes.find(s => s.id == currentSceneId);
                    if (scene) {
                        await addMainCaptionToFabric(scene, caption);
                    }
                }
                
                fabricCanvas.renderAll();
            }
            
            // Hide preview
            document.getElementById('selectedImageBoxPreview').style.display = 'none';
            
            L(`✅ Image removed from box #${selectedCaptionId}`);
        }
    } catch(e) {
        console.error('Error removing image:', e);
        alert('Failed to remove image: ' + e.message);
    }
}

// Show warning when no box selected
function showNoBoxWarning() {
    const warning = document.getElementById('noBoxSelectedWarning');
    if (warning) {
        warning.style.display = 'block';
        setTimeout(() => {
            warning.style.display = 'none';
        }, 3000);
    }
    alert('Please select a visual box on the canvas first');
}
// Auto-save image caption properties
function autoSaveImageCaptionProperties(imgObj) {
    if (!imgObj.captionId) return;
    
    const updates = {
        position_x: Math.round(imgObj.left),
        position_y: Math.round(imgObj.top),
        width: Math.round(imgObj.width * imgObj.scaleX),
        scale_x: imgObj.scaleX,
        scale_y: imgObj.scaleY,
        rotation: imgObj.angle || 0
    };
    
    // Debounce save
    if (imgObj._saveTimeout) clearTimeout(imgObj._saveTimeout);
    imgObj._saveTimeout = setTimeout(async () => {
        const fd = new FormData();
        fd.append('ajax_action', 'save_caption');
        fd.append('caption_id', imgObj.captionId);
        
        Object.keys(updates).forEach(key => {
            fd.append(key, updates[key]);
        });
        
        await safeFetch(fd);
        console.log('✅ Image caption position saved');
    }, 500);
}

// Duplicate image caption to all scenes
// Duplicate image caption to all scenes
async function duplicateImageCaptionToAllScenes(sourceCaptionId) {
    if (!scenes.length) return;
    
    L('🔄 Duplicating image caption to all scenes...');
    
    // Get source caption
    const sourceCaption = findCaptionById(sourceCaptionId);
    if (!sourceCaption) return;
    
    let successCount = 0;
    
    for (const scene of scenes) {
        if (scene.id == currentSceneId) continue;
        
        try {
            // Get podcast_id from the scene
            const podcastId = scene.podcast_id || currentPodcastId;
            
            // Create new caption
            const fd = new FormData();
            fd.append('ajax_action', 'create_caption');
            fd.append('story_id', scene.id);
            fd.append('podcast_id', podcastId); // ADD THIS LINE
            fd.append('caption_type', sourceCaption.caption_type + '_' + scene.id);
            fd.append('caption_name', sourceCaption.caption_name);
            fd.append('media_type', 'image');
            
            const {data} = await safeFetch(fd);
            
            if (data.success) {
                // Copy image file
                const updateFd = new FormData();
                updateFd.append('ajax_action', 'save_caption');
                updateFd.append('caption_id', data.caption_id);
                updateFd.append('image_file', sourceCaption.image_file);
                updateFd.append('media_type', 'image');
                updateFd.append('width', sourceCaption.width || 200);
                updateFd.append('position_x', sourceCaption.position_x || 100);
                updateFd.append('position_y', sourceCaption.position_y || 100);
                
                await safeFetch(updateFd);
                successCount++;
            }
        } catch(e) {
            console.error('Error duplicating to scene', scene.id, e);
        }
    }
    
    L(`✅ Image caption duplicated to ${successCount} scenes`);
}

// Select image caption
function selectImageCaption(captionId) {
    console.log('🖼️ Selected image caption:', captionId);
    
    // Highlight in grid
    document.querySelectorAll('.image-caption-thumb').forEach(el => {
        const div = el.querySelector('div:first-child');
        if (div) div.style.borderColor = 'transparent';
    });
    
    const selected = document.querySelector(`.image-caption-thumb[data-id="${captionId}"] div:first-child`);
    if (selected) {
        selected.style.borderColor = 'var(--info)';
    }
    
    // Select on canvas
    if (fabricCanvas) {
        const objects = fabricCanvas.getObjects();
        const imgObj = objects.find(obj => obj.captionId == captionId);
        if (imgObj) {
            fabricCanvas.setActiveObject(imgObj);
            fabricCanvas.renderAll();
        }
    }
}

// Delete image caption
async function deleteImageCaption(captionId) {
    if (!confirm('Delete this image caption?')) return;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_caption');
        fd.append('caption_id', captionId);
        
        const {data} = await safeFetch(fd);
        
        if (data.success) {
            L('✅ Image caption deleted');
            
            // Remove from canvas
            if (fabricCanvas) {
                const objects = fabricCanvas.getObjects();
                objects.forEach(obj => {
                    if (obj.captionId == captionId) {
                        fabricCanvas.remove(obj);
                    }
                });
                fabricCanvas.renderAll();
            }
            
            // Reload
            await loadImageCaptions();
        }
    } catch(e) {
        console.error('Error deleting:', e);
        alert('Error: ' + e.message);
    }
}

// Add existing image caption to current scene
async function addImageCaptionToScene(captionId) {
    const caption = findCaptionById(captionId);
    if (!caption || !caption.image_file) return;
    
    await addImageCaptionToCanvas(captionId, caption.image_file);
}

// Update loadSceneCaptions to handle image captions
const originalLoadSceneCaptions = loadSceneCaptions;
loadSceneCaptions = async function(sceneId) {
    await originalLoadSceneCaptions(sceneId);
    
    // Also load image captions for the UI
    await loadImageCaptions();
    
    // Add image captions to canvas
    if (fabricCanvas) {
        for (let type in sceneCaptions) {
            const caption = sceneCaptions[type];
            if (caption.media_type === 'image' && caption.image_file) {
                await addImageCaptionToCanvas(caption.id, caption.image_file);
            }
        }
    }
};



//***********************************end of image sticker ***************************

// Initialize effect buttons based on current text state
if (captionText) {
    // Bold
    document.getElementById('btnBold').style.background = captionText.fontWeight === 'bold' ? 'var(--info)' : 'white';
    document.getElementById('btnBold').style.color = captionText.fontWeight === 'bold' ? 'white' : 'var(--text)';
    
    // Italic
    document.getElementById('btnItalic').style.background = captionText.fontStyle === 'italic' ? 'var(--info)' : 'white';
    document.getElementById('btnItalic').style.color = captionText.fontStyle === 'italic' ? 'white' : 'var(--text)';
    
    // Underline
    document.getElementById('btnUnderline').style.background = captionText.underline ? 'var(--info)' : 'white';
    document.getElementById('btnUnderline').style.color = captionText.underline ? 'white' : 'var(--text)';
    
    // Strikethrough
    document.getElementById('btnStrikethrough').style.background = captionText.linethrough ? 'var(--info)' : 'white';
    document.getElementById('btnStrikethrough').style.color = captionText.linethrough ? 'white' : 'var(--text)';
    
    // Shadow
    const hasShadow = captionText.shadow !== null && captionText.shadow !== undefined;
    document.getElementById('btnShadow').style.background = hasShadow ? 'var(--info)' : 'white';
    document.getElementById('btnShadow').style.color = hasShadow ? 'white' : 'var(--text)';
    document.getElementById('shadowSettings').style.display = hasShadow ? 'block' : 'none';
    
    // Gradient
    const hasGradient = captionText.fill && captionText.fill.type === 'linear';
    document.getElementById('btnGradient').style.background = hasGradient ? 'var(--info)' : 'white';
    document.getElementById('btnGradient').style.color = hasGradient ? 'white' : 'var(--text)';
    document.getElementById('gradientSettings').style.display = hasGradient ? 'block' : 'none';
    
    // Outline/Stroke
    const hasOutline = captionText.stroke && captionText.strokeWidth > 0;
    document.getElementById('btnOutline').style.background = hasOutline ? 'var(--info)' : 'white';
    document.getElementById('btnOutline').style.color = hasOutline ? 'white' : 'var(--text)';
    document.getElementById('outlineSettings').style.display = hasOutline ? 'block' : 'none';
    
    document.getElementById('btnStroke').style.background = hasOutline ? 'var(--info)' : 'white';
    document.getElementById('btnStroke').style.color = hasOutline ? 'white' : 'var(--text)';
    document.getElementById('strokeSettings').style.display = hasOutline ? 'block' : 'none';
    
    // Alignment
    setTextAlignment(captionText.textAlign || 'center');
}
// Save settings with apply to all confirmation
// ========== SAVE SETTINGS WITH APPLY TO ALL ==========
async function saveSettingsWithApply(type) {
    console.log('saveSettingsWithApply called for type:', type);
    if (!currentSceneId) return;
    
    // Check if apply to all is checked
    let applyToAll = false;
    let settings = {};
    
    if (type === 'typography') {
        applyToAll = document.getElementById('applyTypographyToAll')?.checked || false;
        settings = {
            fontfamily: document.getElementById('sceneFontFamily')?.value || 'Inter',
            fontsize: parseInt(document.getElementById('sceneFontSize')?.value) || 28,
            fontcolor: document.getElementById('sceneFontColor')?.value || '#ffffff',
            fontcolor_bg: document.getElementById('sceneFontBgColor')?.value || '#000000',
            fontweight: document.getElementById('sceneFontWeight')?.value || '700',
            fontbg_enable: document.getElementById('sceneFontBgEnable')?.checked ? 1 : 0
        };
    } else if (type === 'captions') {
        applyToAll = document.getElementById('applyCaptionsToAll')?.checked || false;
        settings = {
            caption_style: document.getElementById('sceneCaptionStyle')?.value || 'typewriter',
            caption_position: document.getElementById('sceneCaptionPosition')?.value || 'bottom',
            caption_speed: parseFloat(document.getElementById('sceneCaptionSpeed')?.value) || 0.85,
            caption_alignment: document.getElementById('sceneCaptionAlignment')?.value || 'center'
        };
    } else if (type === 'branding') {
        applyToAll = document.getElementById('applyBrandingToAll')?.checked || false;
        settings = {
            logo_name: document.getElementById('logoFileName')?.innerText || '',
            logo_size: document.getElementById('sceneLogoSize')?.value || '60',
            logo_position: document.getElementById('sceneLogoPosition')?.value || 'top-right',
            logo_enabled: document.getElementById('sceneLogoEnabled')?.checked ? 1 : 0
        };
    } else if (type === 'audio') {
        applyToAll = document.getElementById('applyAudioToAll')?.checked || false;
        settings = {
            audio_file: document.getElementById('currentAudioName')?.innerText || '',
            audio_volume: parseInt(document.getElementById('sceneAudioVolume')?.value) || 100
        };
    }
    
    if (applyToAll) {
        // Save to all scenes
        L(`🔄 Applying ${type} settings to ALL scenes...`);
        let successCount = 0;
        let errorCount = 0;
        
        for (const scene of scenes) {
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'save_scene_settings');
                fd.append('scene_id', scene.id);
                
                Object.keys(settings).forEach(key => {
                    fd.append(key, settings[key]);
                });
                
                const {data} = await safeFetch(fd);
                if (data.success) {
                    successCount++;
                    // Update local sceneSettings
                    if (!sceneSettings[scene.id]) sceneSettings[scene.id] = {};
                    sceneSettings[scene.id][type] = settings;
                } else {
                    errorCount++;
                }
            } catch(e) {
                errorCount++;
                console.error('Error saving to scene', scene.id, e);
            }
        }
        
        L(`✅ ${type} settings saved to ${successCount} scenes (${errorCount} failed)`);
        
    } else {
        // Save only to current scene
        L(`💾 Saving ${type} settings to current scene only...`);
        
        // Update local sceneSettings
        if (!sceneSettings[currentSceneId]) sceneSettings[currentSceneId] = {};
        sceneSettings[currentSceneId][type] = settings;
        
        // Save to database
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'save_scene_settings');
            fd.append('scene_id', currentSceneId);
            
            Object.keys(settings).forEach(key => {
                fd.append(key, settings[key]);
            });
            
            const {data} = await safeFetch(fd);
            if (!data.success) {
                throw new Error(data.message || 'Failed to save settings');
            }
            
            L(`✅ ${type} settings saved for scene #${currentSceneId}`);
            
        } catch(e) {
            console.error('Error saving settings:', e);
            L(`❌ Failed to save settings: ${e.message}`);
        }
    }
    
    updateOverlayBadges(currentSceneId);
    closeScenePanel();
}

// Helper function to save caption to database
// ========== SAVE CAPTION TO DATABASE ==========
async function saveCaptionToDatabase(captionId, updates) {
    if (!captionId) return false;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_caption');
        fd.append('caption_id', captionId);
        
        // Add all updates
        Object.keys(updates).forEach(key => {
            let value = updates[key];
            if (value === null || value === undefined) {
                value = '';
            }
            fd.append(key, value);
        });
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            console.log(`✅ Caption ${captionId} updated:`, updates);
            
            // Update local sceneCaptions if available
            for (let type in sceneCaptions) {
                if (sceneCaptions[type] && sceneCaptions[type].id == captionId) {
                    Object.assign(sceneCaptions[type], updates);
                    break;
                }
            }
            
            return true;
        } else {
            console.error('Save failed:', data.message);
            return false;
        }
    } catch(e) {
        console.error('Save error:', e);
        return false;
    }
}

// Helper function to update caption object on canvas
function updateCaptionObjectOnCanvas(captionId, settings) {
    if (!fabricCanvas) return;
    
    const objects = fabricCanvas.getObjects();
    objects.forEach(obj => {
        if (obj.captionId == captionId) {
            console.log('🔄 Updating canvas object for caption', captionId);
            
            // Update text properties
            if (settings.fontfamily) obj.set('fontFamily', settings.fontfamily);
            if (settings.fontsize) obj.set('fontSize', settings.fontsize);
            if (settings.fontcolor) obj.set('fill', settings.fontcolor);
            if (settings.bg_enabled) {
                obj.set('backgroundColor', settings.bg_color || '#000000');
            } else {
                obj.set('backgroundColor', 'transparent');
            }
            if (settings.text_align) obj.set('textAlign', settings.text_align);
            
            // Update stroke/outline
            let strokeColor = null;
            let strokeWidth = 0;
            
            if (settings.outline_enabled && settings.outline_color) {
                strokeColor = settings.outline_color;
                strokeWidth = settings.outline_width || 2;
            } else if (settings.stroke_enabled && settings.stroke_color) {
                strokeColor = settings.stroke_color;
                strokeWidth = settings.stroke_width || 2;
            }
            
            obj.set({
                stroke: strokeColor,
                strokeWidth: strokeWidth
            });
        }
    });
    
    fabricCanvas.renderAll();
}
// Add live preview listeners
function addLivePreviewListeners(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    // Font Family
    document.getElementById('sceneFontFamily').addEventListener('change', function(e) {
        if (captionText) {
            captionText.set('fontFamily', e.target.value);
            fabricCanvas.renderAll();
        }
    });
    
    // Font Size
    document.getElementById('sceneFontSize').addEventListener('change', function(e) {
        if (captionText) {
            captionText.set('fontSize', parseInt(e.target.value));
            fabricCanvas.renderAll();
        }
    });
    
    // Font Color
    document.getElementById('sceneFontColor').addEventListener('input', function(e) {
        if (captionText) {
            captionText.set('fill', e.target.value);
            fabricCanvas.renderAll();
        }
    });
    
    // Background Color
    document.getElementById('sceneFontBgColor').addEventListener('input', function(e) {
        if (captionText && document.getElementById('sceneFontBgEnable').checked) {
            captionText.set('backgroundColor', e.target.value);
            fabricCanvas.renderAll();
        }
    });
    
    // Background Enable
    document.getElementById('sceneFontBgEnable').addEventListener('change', function(e) {
        if (captionText) {
            if (e.target.checked) {
                captionText.set('backgroundColor', document.getElementById('sceneFontBgColor').value);
            } else {
                captionText.set('backgroundColor', 'transparent');
            }
            fabricCanvas.renderAll();
        }
    });
    
    // Font Weight
    document.getElementById('sceneFontWeight').addEventListener('change', function(e) {
        if (captionText) {
            captionText.set('fontWeight', e.target.value);
            fabricCanvas.renderAll();
        }
    });
    
    // Caption Position
    document.getElementById('sceneCaptionPosition').addEventListener('change', function(e) {
        if (captionText) {
            const position = e.target.value;
            let top;
            if (position === 'top') {
                top = 100;
            } else if (position === 'center') {
                top = fabricCanvas.height / 2 - (captionText.height / 2);
            } else {
                top = fabricCanvas.height - 200;
            }
            captionText.set('top', top);
            fabricCanvas.renderAll();
        }
    });
    
    // Caption Alignment
    document.getElementById('sceneCaptionAlignment').addEventListener('change', function(e) {
        if (captionText) {
            captionText.set('textAlign', e.target.value);
            fabricCanvas.renderAll();
        }
    });
}



// ========== SAVE COMBINED SETTINGS TO ALL SCENES ==========
async function saveCombinedSettingsToAll() {
    if (!currentSceneId) return;
    
    const applyTypography = document.getElementById('applyTypographyToAllCombined')?.checked || false;
    const applyCaptions = document.getElementById('applyCaptionsToAllCombined')?.checked || false;
    
    if (!applyTypography && !applyCaptions) {
        alert('Please select at least one setting to apply to all scenes');
        return;
    }
    
    let settings = {};
    
    if (applyTypography) {
        settings = {
            ...settings,
            fontfamily: document.getElementById('sceneFontFamily')?.value || 'Inter',
            fontsize: parseInt(document.getElementById('sceneFontSize')?.value) || 28,
            fontcolor: document.getElementById('sceneFontColor')?.value || '#ffffff',
            fontcolor_bg: document.getElementById('sceneFontBgColor')?.value || '#000000',
            fontweight: document.getElementById('sceneFontWeight')?.value || '700',
            fontbg_enable: document.getElementById('sceneFontBgEnable')?.checked ? 1 : 0
        };
    }
    
    if (applyCaptions) {
        settings = {
            ...settings,
            caption_style: document.getElementById('sceneCaptionStyle')?.value || 'typewriter',
            caption_position: document.getElementById('sceneCaptionPosition')?.value || 'bottom',
            caption_speed: parseFloat(document.getElementById('sceneCaptionSpeed')?.value) || 0.85,
            caption_alignment: document.getElementById('sceneCaptionAlignment')?.value || 'center'
        };
    }
    
    L(`🔄 Applying settings to ALL scenes...`);
    let successCount = 0;
    let errorCount = 0;
    
    for (const scene of scenes) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'save_scene_settings');
            fd.append('scene_id', scene.id);
            
            Object.keys(settings).forEach(key => {
                fd.append(key, settings[key]);
            });
            
            const {data} = await safeFetch(fd);
            if (data.success) {
                successCount++;
                if (!sceneSettings[scene.id]) sceneSettings[scene.id] = {};
                if (applyTypography) sceneSettings[scene.id].typography = settings;
                if (applyCaptions) sceneSettings[scene.id].captions = settings;
            } else {
                errorCount++;
            }
        } catch(e) {
            errorCount++;
            console.error('Error saving to scene', scene.id, e);
        }
    }
    
    L(`✅ Settings saved to ${successCount} scenes (${errorCount} failed)`);
    updateOverlayBadges(currentSceneId);
    closeScenePanel();
}


// Add image box to canvas - FIXED VERSION
async function addImageBoxToCanvas(captionId, imageFile, center = false) {
    if (!fabricCanvas) return;
    
    console.log('🖼️ addImageBoxToCanvas called with:', { captionId, imageFile, center });
    
    return new Promise((resolve) => {
        const imagePath = 'podcast_stickers/' + imageFile;
        console.log('🖼️ Loading sticker from:', imagePath);
        
        fabric.Image.fromURL(imagePath + '?t=' + Date.now(), (img) => {
            if (!img) {
                console.error('Failed to load image:', imageFile);
                // Try podcast_images as fallback
                const fallbackPath = 'podcast_images/' + imageFile;
                fabric.Image.fromURL(fallbackPath + '?t=' + Date.now(), (fallbackImg) => {
                    if (!fallbackImg) {
                        console.error('Failed to load image from both paths');
                        resolve(null);
                        return;
                    }
                    processImage(fallbackImg);
                });
                return;
            }
            processImage(img);
        });
        
        function processImage(img) {
            console.log('✅ Image loaded, processing...');
            
            // Get saved position or use defaults
            const caption = findCaptionById(captionId);
            
            // Calculate center position if requested
            let startLeft = caption?.position_x || 150;
            let startTop = caption?.position_y || 250;
            
            if (center) {
                startLeft = fabricCanvas.width / 2 - 100;
                startTop = fabricCanvas.height / 2 - 100;
            }
            
            const startWidth = caption?.width || 200;
            
            // Calculate scale
            const scale = startWidth / img.width;
            
            // Set all properties BEFORE adding to canvas
            img.set({
                left: parseInt(startLeft),
                top: parseInt(startTop),
                scaleX: scale,
                scaleY: scale,
                
                // CRITICAL: Selection properties
                selectable: true,
                evented: true,
                hasControls: true,
                hasBorders: true,
                
                // Make handles visible and touch-friendly
                cornerSize: 15,
                transparentCorners: false,
                borderColor: '#00ff00',
                cornerColor: '#ff0000',
                
                // Ensure object can be found by target detection
                perPixelTargetFind: false, // Set to false for better performance
                targetFindTolerance: 5, // Pixels tolerance for finding object
                
                // Custom properties
                captionId: captionId,
                captionType: 'image',
                mediaType: 'image'
            });
            
            // CRITICAL: Update coordinates before adding to canvas
            img.setCoords();
            
            console.log('📊 Image object properties:', {
                captionId: img.captionId,
                selectable: img.selectable,
                evented: img.evented,
                left: img.left,
                top: img.top,
                scaleX: img.scaleX,
                scaleY: img.scaleY
            });
            
            // Remove existing object with same captionId
            const existing = fabricCanvas.getObjects().find(obj => obj.captionId == captionId);
            if (existing) {
                console.log('Removing existing object with captionId:', captionId);
                fabricCanvas.remove(existing);
            }
            
            // Add to canvas
            fabricCanvas.add(img);
            
            // Update coordinates again after adding
            img.setCoords();
            
            // Force a render
            fabricCanvas.renderAll();
            
            // Save position when modified
            img.on('modified', function() {
                console.log('Image modified:', this.captionId);
                autoSaveImageBoxProperties(this);
                this.setCoords(); // Update coordinates after modification
            });
            
            resolve(img);
        }
    });
}
// Add this function to inspect a specific object by captionId
function inspectImageObject(captionId) {
    if (!fabricCanvas) return;
    
    const objects = fabricCanvas.getObjects();
    const imageObj = objects.find(obj => obj.captionId == captionId);
    
    if (!imageObj) {
        console.log('❌ No object found with captionId:', captionId);
        return;
    }
    
    console.log('🔍 ===== IMAGE OBJECT INSPECTION =====');
    console.log('Type:', imageObj.type);
    console.log('Class:', imageObj.constructor.name);
    console.log('Properties:', {
        captionId: imageObj.captionId,
        selectable: imageObj.selectable,
        evented: imageObj.evented,
        hasControls: imageObj.hasControls,
        hasBorders: imageObj.hasBorders,
        lockMovementX: imageObj.lockMovementX,
        lockMovementY: imageObj.lockMovementY,
        lockScalingX: imageObj.lockScalingX,
        lockScalingY: imageObj.lockScalingY,
        perPixelTargetFind: imageObj.perPixelTargetFind,
        targetFindTolerance: imageObj.targetFindTolerance
    });
    console.log('Position:', { left: imageObj.left, top: imageObj.top });
    console.log('Size:', { width: imageObj.width, height: imageObj.height });
    console.log('Scale:', { x: imageObj.scaleX, y: imageObj.scaleY });
    
    // Get bounding rect for selection testing
    const bounds = imageObj.getBoundingRect();
    console.log('Bounding rect:', bounds);
    
    console.log('🔍 ===== END INSPECTION =====');
    
    return imageObj;
}
// Auto-save function for caption properties
// Auto-save function for caption properties
function autoSaveCaptionProperty(captionObj, property, value) {
    if (!captionObj || !captionObj.captionId) {
        console.error('❌ Cannot auto-save: missing captionObj or captionId');
        return;
    }
    
    console.log(`⏱️ Queueing auto-save for ${property} = ${value} for caption ${captionObj.captionId}`);
    
    // Clear any pending save
    if (autoSaveTimeout) {
        clearTimeout(autoSaveTimeout);
    }
    
    // Store the pending update on the caption object
    if (!captionObj._pendingUpdates) {
        captionObj._pendingUpdates = {};
    }
    captionObj._pendingUpdates[property] = value;
    
    // Set a new timeout to save after 500ms of no changes
    autoSaveTimeout = setTimeout(async () => {
        const updates = { ...captionObj._pendingUpdates };
        captionObj._pendingUpdates = {};
        
        console.log(`💾 Auto-saving ${Object.keys(updates).length} properties for caption ${captionObj.captionId}:`, updates);
        
        // Make sure we have the correct caption ID
        if (!captionObj.captionId) {
            console.error('❌ Caption ID is missing!');
            return;
        }
        
        // Get the caption type from the object
        const captionType = captionObj.captionType || 'main';
        
        const captionToSave = {
            id: captionObj.captionId,
            caption_type: captionType,
            ...updates
        };
        
        try {
            const success = await saveCaption(currentSceneId, captionType, captionToSave);
            if (success) {
                console.log(`✅ Auto-save complete for caption ${captionObj.captionId}`);
            } else {
                console.error(`❌ Auto-save failed for caption ${captionObj.captionId}`);
                // Re-queue the failed updates
                captionObj._pendingUpdates = { ...captionObj._pendingUpdates, ...updates };
            }
        } catch (error) {
            console.error(`❌ Auto-save error for caption ${captionObj.captionId}:`, error);
        }
    }, 500);
}

// Debug function to check text object properties
function debugTextSelection() {
    if (!fabricCanvas) return;
    
    console.log('🔍 ===== TEXT SELECTION DEBUG =====');
    
    const objects = fabricCanvas.getObjects();
    objects.forEach((obj, index) => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            console.log(`📝 Text object ${index}:`, {
                captionId: obj.captionId,
                left: obj.left,
                top: obj.top,
                width: obj.width,
                height: obj.height,
                selectable: obj.selectable,
                evented: obj.evented,
                hasControls: obj.hasControls,
                padding: obj.padding,
                backgroundColor: obj.backgroundColor,
                fill: obj.fill
            });
            
            // Log the bounding box
            const boundingRect = obj.getBoundingRect();
            console.log(`   Bounding box:`, {
                left: boundingRect.left,
                top: boundingRect.top,
                width: boundingRect.width,
                height: boundingRect.height
            });
        }
    });
    
    console.log('🔍 ===== END DEBUG =====');
}

// Call it after moving a text
setTimeout(debugTextSelection, 3000);


// Set text alignment
// ========== SET TEXT ALIGNMENT ==========
// ========== SET TEXT ALIGNMENT ==========
async function setTextAlignment(alignment) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting text alignment:', alignment);
    
    // Update the text object
    captionText.set('textAlign', alignment);
    fabricCanvas.renderAll();
    
    // Update hidden field
    const alignField = document.getElementById('sceneCaptionAlignment');
    if (alignField) alignField.value = alignment;
    
    // Save to database
    await saveCaptionToDatabase(captionText.captionId, { text_align: alignment });
    
    hideDropdown('alignDropdown');
}







async function openImageLibrary() {
	
	
	  lastSearchTerm = '';
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    editingSceneId = currentSceneId;
    editingImageSlot = currentImageField || 'image_file';
    selectedMediaFile = null;
    selectedMediaType = null;
    activeMediaTab = 'images';
    cachedImages = [];
    cachedVideos = [];
    
    document.getElementById('editSceneId').innerText = 'Scene #' + editingSceneId;
    document.getElementById('editSlotName').innerText = editingImageSlot;
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
    const scene = scenes.find(s => s.id == editingSceneId);
    const sceneHashtags = scene?.hashtags || '';
    
    // Display the hashtags we're searching for
    const searchTagsDisplay = document.createElement('div');
    searchTagsDisplay.id = 'searchTagsDisplay';
    searchTagsDisplay.style.cssText = 'padding: 10px 16px; background: #e0f2fe; border-bottom: 1px solid var(--border); font-size: 12px; color: #0369a1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;';
    
    if (sceneHashtags && sceneHashtags.trim() !== '') {
        // FIXED: Split by comma for display
        const tags = sceneHashtags.split(',').map(t => t.trim()).filter(t => t !== '');
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
    L(`🔍 Scene #${editingSceneId} searching for hashtags: ${sceneHashtags || '(none)'}`);
    
    // Load images with hashtag matching
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_media_library');
        const {data} = await safeFetch(fd);
        
        console.log('📸 Total images loaded:', data.length);
        L(`📸 Total images in library: ${data.length}`);
        
        // Store all images
        cachedImages = data;
        
        // Filter images based on hashtag match with scene hashtags
        let filteredImages = data;
        const matchingStats = {};
        
        if (sceneHashtags && sceneHashtags.trim() !== '') {
            // FIXED: Split by comma, not whitespace
            let sceneTags = [];
            if (sceneHashtags.includes(',')) {
                sceneTags = sceneHashtags.toLowerCase().split(',').map(tag => tag.trim()).filter(tag => tag !== '');
            } else {
                // Fallback to whitespace split if no commas
                sceneTags = sceneHashtags.toLowerCase().split(/\s+/).filter(tag => tag.trim() !== '');
            }
            
            console.log('🏷️ Scene tags array:', sceneTags);
            L(`🏷️ Scene tags: ${sceneTags.join(', ')}`);
            
            if (sceneTags.length > 0) {
                // Filter images that have at least one matching hashtag
                filteredImages = data.filter(img => {
                    const imgTags = (img.hashtags || '').toLowerCase();
                    
                    const matches = sceneTags.filter(tag => imgTags.includes(tag));
                    
                    // Store match count for sorting
                    img.matchCount = matches.length;
                    
                    // Track which tags are matching
                    matches.forEach(tag => {
                        matchingStats[tag] = (matchingStats[tag] || 0) + 1;
                    });
                    
                    return matches.length > 0;
                });
                
                // Log matching stats
                console.log('📊 Hashtag matching stats:', matchingStats);
                L('📊 Hashtag matching stats:');
                Object.entries(matchingStats).forEach(([tag, count]) => {
                    L(`   • ${tag}: found in ${count} image${count !== 1 ? 's' : ''}`);
                });
                
                console.log(`🎯 Found ${filteredImages.length} images matching scene hashtags out of ${data.length} total`);
                L(`🎯 Found ${filteredImages.length} images matching scene hashtags out of ${data.length} total`);
                
                // If no matches found, show a warning but still show some images
                if (filteredImages.length === 0) {
                    console.log('⚠️ No matches found, showing recent images as suggestions');
                    L('⚠️ No matches found, showing recent images');
                    filteredImages = data.slice(0, 30).map(img => {
                        img.matchCount = 0;
                        return img;
                    });
                }
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
    // Pre-load videos (no hashtag matching for videos)
		try {
			const fd2 = new FormData();
			fd2.append('ajax_action', 'get_video_library');
			const response = await fetch(location.href, { method: 'POST', body: fd2 });
			const text = await response.text(); // Get as text first
			console.log('Video response:', text); // Debug log
			
			if (text && text.trim() !== '') {
				try {
					const vdata = JSON.parse(text);
					cachedVideos = Array.isArray(vdata) ? vdata : [];
				} catch(e) {
					console.error('Invalid video JSON:', text.substring(0, 100));
					cachedVideos = [];
				}
			} else {
				cachedVideos = [];
			}
			
			document.getElementById('tabVideosCount').innerText = cachedVideos.length;
			console.log('🎬 Videos loaded:', cachedVideos.length);
		} catch(e) {
			console.error("Background video load error:", e);
			cachedVideos = []; // Set to empty array on error
		}
}


// ========== UPLOAD IMAGE FROM SETTINGS PANEL ==========
function uploadImage() {
    console.log('uploadImage called for:', currentImageField);
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Create a file input dynamically
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.style.display = 'none';
    
    fileInput.onchange = async function(e) {
        if (!e.target.files || e.target.files.length === 0) return;
        
        const file = e.target.files[0];
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file');
            return;
        }
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            return;
        }
        
        // Show uploading message
        L(`📤 Uploading ${file.name} to ${currentImageField}...`);
        
        // Create form data
        const formData = new FormData();
        formData.append('ajax_action', 'upload_image');
        formData.append('image', file);
        formData.append('scene_id', currentSceneId);
        formData.append('image_field', currentImageField);
        
        // Get current prompt
        const promptTextarea = document.getElementById('imagePrompt');
        if (promptTextarea) {
            formData.append('prompt', promptTextarea.value.trim());
        }
        
        try {
            const response = await fetch(location.href, {
                method: 'POST',
                body: formData
            });
            
            const text = await response.text();
            console.log('Upload response:', text);
            
            let result;
            try {
                result = JSON.parse(text);
            } catch(e) {
                throw new Error('Invalid server response');
            }
            
            if (!result.success) {
                throw new Error(result.message || 'Upload failed');
            }
            
            // Success! Update the UI
            L(`✅ Image uploaded successfully: ${result.image_name}`);
            
            // Update the scene in memory
            const scene = scenes.find(s => s.id == currentSceneId);
            if (scene) {
                scene[currentImageField] = result.image_name;
            }
            
            // Update canvas
            const imagePreview = document.getElementById('imagePreview');
            imagePreview.src = 'podcast_images/' + result.image_name + '?t=' + Date.now();
            imagePreview.style.display = 'block';
            
            // Reload thumbnails
            loadImageThumbnails();
            
            // Refresh media library in background
            refreshMediaLibrary();
            
            alert('Image uploaded successfully!');
            
        } catch(error) {
            console.error('Upload error:', error);
            alert('Upload failed: ' + error.message);
            L(`❌ Upload failed: ${error.message}`);
        }
        
        document.body.removeChild(fileInput);
    };
    
    document.body.appendChild(fileInput);
    fileInput.click();
}

async function refreshMediaLibrary() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_media_library');
        const {data} = await safeFetch(fd);
        cachedImages = data;
        console.log('📸 Media library refreshed:', data.length);
    } catch(e) {
        console.error('Error refreshing media library:', e);
    }
}

// ========== AUDIO SETTINGS FUNCTIONS ==========
function playAudioPreview() {
    console.log('playAudioPreview called');
    L('▶️ Playing audio preview');
    alert('Audio preview will be implemented in the next update');
}

function selectAudioFile() {
    console.log('selectAudioFile called');
    L('🎵 Selecting audio file');
    alert('Audio selection will be implemented in the next update');
}

function regenerateAudio() {
    console.log('regenerateAudio called');
    L('🔄 Regenerating audio');
    alert('Audio regeneration will be implemented in the next update');
}

// ========== MORE SETTINGS FUNCTIONS ==========
function openMoreSettings() {
    console.log('openMoreSettings called');
    L('⋯ Opening more options');
    alert('More options will be implemented in a future update');
}
// ========== NAVIGATION FUNCTIONS ==========
async function gobackProjects() {
    // Save current scene before leaving
    if (currentSceneId) {
        await saveCurrentImageField();
    }
    window.location.href = 'vidora_home.php';
}

// ========== RENDER FUNCTION ==========
let mediaRecorder = null;
let recordedChunks = [];
let renderStartTime = null;
let renderSceneIndex = 0;
let renderAudioContext = null;
let renderDestination = null;
let renderAudioElements = [];

async function startRecording() {
    console.log('🎥 startRecording called');
    
    if (scenes.length === 0) {
        alert('No scenes to render');
        return;
    }
    
    if (isRecording) {
        // Stop recording if already recording
        stopRecording();
        return;
    }
    
    // Confirm with user
    const filename = `video_${<?= $url_podcast_id ?>}_${'<?= $podcast_lang_code ?>'}.mp4`;
    if (!confirm(`🎥 Render video as "${filename}"?\n\nThis will record all ${scenes.length} scenes with audio.`)) {
        return;
    }
    
    // Hide all overlays
    hideAllOverlays();
    
    // Save current scene before starting
    if (currentSceneId) {
        await saveCurrentImageField();
    }

    // Preload all scene media before recording starts
    await preloadAllScenes();
    
    isRecording = true;
    _renderingIndex = -1;
    renderSceneIndex = 0;
    recordedChunks = [];
    showStopBtn();
    
    // Update button state
    updateRenderButtonState(true);
    
    L(`🎥 Starting render: ${filename}`);
    L(`📁 Will save as: ${filename}`);
    
    // Start with first scene
    await renderScene(0);
}


// ========== RENDER SCENE - WITH PRELOAD ==========
// ========== PLAY SCENE IN SEQUENCE - WITH LAYERING ==========
// ========== LAYER NEW MEDIA ON TOP OF EXISTING ==========
async function layerNewMedia(scene) {
    console.log('🔄 Layering new media for scene:', scene.id);
    
    if (!fabricCanvas) return;
    
    const filename = scene.image_file;
    if (!filename) return;
    
    const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
    
    // Get the container
    const container = document.getElementById('canvasContainer');
    
    if (isVideo) {
        // Create new video element for the next scene
        const newVideo = document.createElement('video');
        newVideo.id = 'nextVideo';
        newVideo.muted = true;
        newVideo.loop = true;
        newVideo.playsInline = true;
        newVideo.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 2;  /* Higher z-index to appear on top */
            pointer-events: none;
            opacity: 0;   /* Start invisible */
            transition: opacity 0.3s ease;
        `;
        
        newVideo.src = 'podcast_videos/' + filename + '?t=' + Date.now();
        container.appendChild(newVideo);
        
        // Load and prepare the video
        await new Promise((resolve) => {
            newVideo.onloadedmetadata = () => {
                console.log('✅ Next video loaded, ready to fade in');
                resolve();
            };
            newVideo.load();
        });
        
        // Start playing but keep opacity 0
        await newVideo.play();
        
        // Fade in the new video
        newVideo.style.opacity = '1';
        
        // Update current scene info
        currentSceneIndex = scenes.findIndex(s => s.id == scene.id);
        currentSceneId = scene.id;
        updateSceneIndicator();
        
        // Load new captions immediately (they'll appear on top)
        await loadSceneCaptions(currentSceneId);
        
        // Clear old captions and add new ones
        const objects = fabricCanvas.getObjects();
        objects.forEach(obj => {
            if (obj !== currentBackgroundVideo && obj !== currentBackgroundImage) {
                fabricCanvas.remove(obj);
            }
        });
        
        for (let type in sceneCaptions) {
            const caption = sceneCaptions[type];
            if (caption.text_content) {
                await addMainCaptionToFabric(scene, caption);
            }
        }
        
        // After fade in, remove the old video and rename the new one
        setTimeout(() => {
            const oldVideo = document.getElementById('backgroundVideo');
            if (oldVideo) {
                oldVideo.remove();
            }
            newVideo.id = 'backgroundVideo';
            newVideo.style.zIndex = '1';
            currentBackgroundVideo = newVideo;
        }, 300);
        
    } else {
        // For images, we can fade between them using fabric
        const imagePath = 'podcast_images/' + filename;
        
        // Load new image
        fabric.Image.fromURL(imagePath + '?t=' + Date.now(), (newImg) => {
            // Scale to fit canvas
            const scale = Math.min(
                fabricCanvas.width / newImg.width,
                fabricCanvas.height / newImg.height
            );
            
            const left = (fabricCanvas.width - newImg.width * scale) / 2;
            const top = (fabricCanvas.height - newImg.height * scale) / 2;
            
            newImg.set({
                left: left,
                top: top,
                scaleX: scale,
                scaleY: scale,
                selectable: false,
                evented: false,
                opacity: 0  // Start invisible
            });
            
            // Add to canvas (will be on top)
            fabricCanvas.add(newImg);
            
            // Fade in new image
            newImg.animate('opacity', 1, {
                duration: 300,
                onChange: () => fabricCanvas.renderAll(),
                onComplete: () => {
                    // Remove old background
                    if (currentBackgroundImage) {
                        fabricCanvas.remove(currentBackgroundImage);
                    }
                    currentBackgroundImage = newImg;
                    fabricCanvas.sendToBack(newImg);
                    
                    // Update scene info
                    currentSceneIndex = scenes.findIndex(s => s.id == scene.id);
                    currentSceneId = scene.id;
                    updateSceneIndicator();
                    
                    // Load new captions
                    loadSceneCaptions(currentSceneId).then(() => {
                        // Clear old captions
                        const objects = fabricCanvas.getObjects();
                        objects.forEach(obj => {
                            if (obj !== newImg && obj.captionId) {
                                fabricCanvas.remove(obj);
                            }
                        });
                        
                        // Add new captions
                        for (let type in sceneCaptions) {
                            const caption = sceneCaptions[type];
                            if (caption.text_content) {
                                addMainCaptionToFabric(scene, caption);
                            }
                        }
                    });
                }
            });
        });
    }
}

// ========== MODIFIED RENDER SCENE FOR RECORDING ==========
let _renderingIndex = -1; // lock — prevents duplicate renderScene calls

async function renderScene(index) {
    if (!isRecording || index >= scenes.length) {
        finishRecording();
        return;
    }

    // Duplicate call guard — ignore if this index is already being rendered
    if (_renderingIndex === index) {
        console.warn(`⚠️ renderScene(${index}) called again while already rendering — ignoring`);
        return;
    }
    _renderingIndex = index;
    
    const scene = scenes[index];
    if (!scene) {
        renderScene(index + 1);
        return;
    }
    
    console.log(`🎥 Rendering scene ${index + 1}/${scenes.length}`);

    // ── Start background music on scene 0 ────────────────────
    if (index === 0 && podcastMusicFile) {
        const oldBg = document.getElementById('render-bg-music');
        if (oldBg) { oldBg.pause(); oldBg.remove(); }

        const bgMusic = document.createElement('audio');
        bgMusic.id = 'render-bg-music';
        bgMusic.loop = false;
        bgMusic.volume = 0.4;
        bgMusic.preload = 'auto';
        document.body.appendChild(bgMusic);

        bgMusic.oncanplaythrough = () => {
            bgMusic.oncanplaythrough = null;
            bgMusic.play().catch(e => console.warn(`🎵 Render music blocked:`, e.message));
        };
        bgMusic.onerror = () => {
            if (!bgMusic._triedRelative) {
                bgMusic._triedRelative = true;
                bgMusic.src = 'podcast_music/' + podcastMusicFile;
                bgMusic.load();
            }
        };
        bgMusic.src = '/podcast_music/' + podcastMusicFile;
        bgMusic.load();
    }

    // Yield to audio thread before heavy canvas work
    await new Promise(r => setTimeout(r, 0));
    
    // Navigate to scene
    currentSceneIndex = index;
    currentSceneId = scene.id;
    updateSceneIndicator();
    
    if (fabricCanvas) {
        await loadCurrentSceneToFabric();
        
        if (scene.video_file) {
            await new Promise(r => setTimeout(r, 500));
        } else {
            await new Promise(r => setTimeout(r, 100));
        }
    }
    
    // Get audio file
    let audioFile = scene.audio_file;
    if (!audioFile && audio_files && audio_files[scene.id]) {
        audioFile = audio_files[scene.id];
    }
    
    if (!audioFile) {
        L(`⚠️ Scene ${index + 1} has no audio, skipping...`);
        setTimeout(() => renderScene(index + 1), 500);
        return;
    }

    // ── If music loaded: use audio duration as timer ──────────
    if (podcastMusicFile) {
        const durationAudio = new Audio();
        let metadataLoaded = false;
        durationAudio.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();
        durationAudio.preload = 'metadata';
        durationAudio.onloadedmetadata = () => {
            metadataLoaded = true;
            durationAudio.onloadedmetadata = null;
            durationAudio.onerror = null;
            if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
                startMediaRecorder();
            }
            const sceneDuration = durationAudio.duration * 1000;
            L(`⏱️ Rendering scene ${index + 1} for ${durationAudio.duration.toFixed(2)}s`);
            durationAudio.removeAttribute('src');
            setTimeout(() => {
                if (isRecording) renderScene(index + 1);
            }, sceneDuration);
        };
        durationAudio.onerror = () => {
            if (metadataLoaded) return;
            durationAudio.onloadedmetadata = null;
            durationAudio.onerror = null;
            setTimeout(() => {
                if (isRecording) renderScene(index + 1);
            }, 2000);
        };
        durationAudio.load();
        return;
    }

    // ── No music — play scene audio ───────────────────────────
    const audio = new Audio();
    audio.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();
    renderAudioElements.push(audio);
    
    audio.onloadedmetadata = () => {
        if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
            startMediaRecorder();
        }
        audio.play()
            .then(() => {
                L(`🔊 Rendering scene ${index + 1}`);
                setTimeout(() => renderScene(index + 1), audio.duration * 1000);
            })
            .catch(err => {
                console.error(`❌ Audio error:`, err);
                setTimeout(() => renderScene(index + 1), 500);
            });
    };
    
    audio.onerror = () => {
        setTimeout(() => renderScene(index + 1), 500);
    };
    
    audio.load();
}

// ========== PRELOAD SCENE MEDIA ==========
function preloadSceneMedia(scene) {
    if (!scene || !scene.image_file) return;
    
    const filename = scene.image_file;
    const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
    
    console.log(`⏳ Preloading next scene media: ${filename}`);
    
    if (isVideo) {
        // Preload video
        const video = document.createElement('video');
        video.preload = 'auto';
        video.src = 'podcast_videos/' + filename + '?t=' + Date.now();
        video.load();
        
        // Store in cache
        if (!window.preloadCache) window.preloadCache = {};
        window.preloadCache[filename] = video;
        
        // Remove from cache after 5 seconds (to free memory)
        setTimeout(() => {
            if (window.preloadCache && window.preloadCache[filename]) {
                delete window.preloadCache[filename];
            }
        }, 5000);
        
    } else {
        // Preload image
        const img = new Image();
        img.src = 'podcast_images/' + filename + '?t=' + Date.now();
        
        if (!window.preloadCache) window.preloadCache = {};
        window.preloadCache[filename] = img;
        
        setTimeout(() => {
            if (window.preloadCache && window.preloadCache[filename]) {
                delete window.preloadCache[filename];
            }
        }, 5000);
    }
}
// Start the media recorder - UPDATED WITH HIGHER FPS

// Start the media recorder - UPDATED WITH HIGHER FPS
function startMediaRecorder() {
    console.log('🎥 Starting MediaRecorder');
    
    // Get canvas element
    const canvas = document.getElementById('fabricCanvas');
    if (!canvas) {
        console.error('❌ Canvas not found');
        return;
    }
    
    // Get canvas stream at 30fps for smoother video
    const canvasStream = canvas.captureStream(30);
    
    // Create MediaRecorder with high quality settings
    try {
        // Try different MIME types for better compatibility
        const mimeTypes = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm',
            'video/mp4'
        ];
        
        let options = {
            videoBitsPerSecond: 5000000 // 5 Mbps for high quality
        };
        
        for (const mimeType of mimeTypes) {
            if (MediaRecorder.isTypeSupported(mimeType)) {
                options.mimeType = mimeType;
                console.log(`✅ Using MIME type: ${mimeType}`);
                break;
            }
        }
        
        mediaRecorder = new MediaRecorder(canvasStream, options);
        
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                recordedChunks.push(event.data);
                console.log(`📦 Chunk received: ${(event.data.size / 1024).toFixed(2)} KB`);
            }
        };
        
        mediaRecorder.onstop = () => {
            console.log('⏹️ MediaRecorder stopped');
            saveRecordedVideoToServer();
        };
        
        mediaRecorder.onerror = (error) => {
            console.error('❌ MediaRecorder error:', error);
            L(`❌ Recording error: ${error.message}`);
            stopRecording();
        };
        
        // Start recording with 1 second chunks
        mediaRecorder.start(1000);
        renderStartTime = Date.now();
        L('🎥 Recording started - capturing video backgrounds');
        
    } catch (err) {
        console.error('❌ Failed to start MediaRecorder:', err);
        L(`❌ Failed to start recording: ${err.message}`);
        stopRecording();
    }
}

// Stop recording
function stopRecording() {
    console.log('⏹️ stopRecording called');
    hideStopBtn();
    _renderingIndex = -1;

    // Clear preload cache
    if (window.preloadCache) {
        Object.values(window.preloadCache).forEach(item => {
            if (item.tagName === 'VIDEO') { item.pause(); item.src = ''; }
        });
        window.preloadCache = {};
    }
    
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    
    // Stop all audio elements
    renderAudioElements.forEach(audio => {
        audio.pause();
        audio.src = '';
    });
    renderAudioElements = [];
    
    isRecording = false;
    renderSceneIndex = 0;
    
    updateRenderButtonState(false);
}

// Finish recording and save video
function finishRecording() {
    console.log('🎥 Rendering complete, finishing recording');
    hideStopBtn();
    _renderingIndex = -1;

    // Clear preload cache
    if (window.preloadCache) {
        Object.values(window.preloadCache).forEach(item => {
            if (item.tagName === 'VIDEO') { item.pause(); item.src = ''; }
        });
        window.preloadCache = {};
    }
    
    L('✅ All scenes rendered, finalizing video...');

    // Stop background music
    const bgMusic = document.getElementById('render-bg-music');
    if (bgMusic) {
        bgMusic.pause();
        bgMusic.src = '';
        bgMusic.remove();
    }
    
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    } else {
        // If no recorder, just save what we have
        saveRecordedVideo();
    }
    
    // Restore overlays
    restoreAllOverlays();
    
    // Reset to scene 1
    if (scenes.length > 0) {
        currentSceneIndex = 0;
        currentSceneId = scenes[0].id;
        updateSceneIndicator();
        if (fabricCanvas) {
            setTimeout(async () => {
                await loadCurrentSceneToFabric();
            }, 500);
        }
    }
}

// Save the recorded video
function saveRecordedVideo() {
    console.log('💾 Saving recorded video');
    
    if (recordedChunks.length === 0) {
        L('❌ No video data recorded');
        alert('No video data was recorded. Please try again.');
        return;
    }
    
    // Create blob from chunks
    const blob = new Blob(recordedChunks, {
        type: mediaRecorder ? mediaRecorder.mimeType : 'video/mp4'
    });
    
    // Create filename
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    const langCode = '<?= $podcast_lang_code ?>';
    const filename = `video_${podcastId}_${langCode}.mp4`;
    
    L(`📁 Saving as: ${filename}`);
    L(`📊 File size: ${(blob.size / (1024*1024)).toFixed(2)} MB`);
    
    // Create download link
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    // Show success message
    setTimeout(() => {
        alert(`✅ Video rendered successfully!\n\nFilename: ${filename}\nSize: ${(blob.size / (1024*1024)).toFixed(2)} MB\nScenes: ${scenes.length}`);
    }, 500);
    
    L(`✅ Video saved: ${filename}`);
    
    // Reset chunks
    recordedChunks = [];
}
// Ensure video frames are being drawn to canvas
function ensureVideoFrame() {
    if (!fabricCanvas) return;
    
    // Force canvas to render the current video frame
    fabricCanvas.renderAll();
    
    // Request next frame if we're recording and have video
    if (isRecording && currentBackgroundVideo) {
        requestAnimationFrame(ensureVideoFrame);
    }
}
// Update render button state
function updateRenderButtonState(isRendering) {
    const renderBtn = document.querySelector('button[onclick="startRecording()"]');
    if (renderBtn) {
        if (isRendering) {
            renderBtn.innerHTML = '<span>⏹️</span> Stop';
            renderBtn.style.background = '#dc2626';
        } else {
            renderBtn.innerHTML = '<span>🎞️</span> Render';
            renderBtn.style.background = 'var(--success)';
        }
    }
}

// Override the original startRecording function
window.startRecording = startRecording;

// Also add a function to check if a previous file exists and delete it
async function deletePreviousRenderFile(filename) {
    return new Promise((resolve) => {
        // Check if file exists in podcast_videos directory
        const filePath = 'podcast_videos/' + filename;
        
        // Try to fetch the file to see if it exists
        fetch(filePath, { method: 'HEAD' })
            .then(response => {
                if (response.ok) {
                    console.log(`🗑️ Previous file found: ${filename}`);
                    L(`🗑️ Deleting previous render: ${filename}`);
                    
                    // Since we can't delete files directly from JavaScript,
                    // we'll need a server-side endpoint to handle deletion
                    const fd = new FormData();
                    fd.append('ajax_action', 'delete_video_file');
                    fd.append('filename', filename);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: fd
                    })
                    .then(() => resolve(true))
                    .catch(() => resolve(false));
                } else {
                    resolve(false);
                }
            })
            .catch(() => resolve(false));
    });
}
// ========== MEDIA LIBRARY MODAL VARIABLES ==========
let editingSceneId = null;
let editingImageSlot = null;
let selectedMediaFile = null;
let selectedMediaType = null;
let activeMediaTab = 'images';
let cachedImages = [];
let cachedVideos = [];


// ========== LOAD MEDIA LIBRARY ==========
async function loadMediaLibrary(type) {
    try {
        const fd = new FormData();
        
        if (type === 'images') {
            fd.append('ajax_action', 'get_media_library');
            const {data} = await safeFetch(fd);
            cachedImages = data;
            document.getElementById('tabImagesCount').innerText = data.length;
            
            if (activeMediaTab === 'images') {
                renderMediaGrid(data, '');// <-- THIS LINE NEEDS TO CHANGE
            }
        } else {
            fd.append('ajax_action', 'get_video_library');
            const response = await fetch(location.href, { method: 'POST', body: fd });
            const data = await response.json();
            cachedVideos = data;
            document.getElementById('tabVideosCount').innerText = data.length;
        }
    } catch(e) {
        console.error('Error loading ' + type + ':', e);
        if (activeMediaTab === type) {
            document.getElementById('mediaGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#dc2626">❌ Error loading: ' + e.message + '</div>';
        }
    }
}


function renderVideoGrid(videos) {
    const grid = document.getElementById('mediaGrid');
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No videos found</div>';
        return;
    }
    
    let html = '';
    videos.forEach(vid => {
        if (!vid || !vid.video_name) return; // Skip invalid entries
        
        const name = vid.video_name;
        const videoPath = 'podcast_videos/' + name;
        
        html += `
        <div class="media-item" data-file="${name}" data-type="video" onclick="selectMediaItem(this, '${name}', 'video')">
            <div style="position: relative; width: 100%; height: 70%;">
                <video class="media-preview" preload="metadata" style="width: 100%; height: 100%; object-fit: cover;">
                    <source src="${videoPath}" type="video/mp4">
                </video>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 40px; height: 40px; background: rgba(0,0,0,0.7); border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid rgba(255,255,255,0.5);">
                    <span style="color: white; font-size: 20px; margin-left: 3px;">▶</span>
                </div>
            </div>
            <div class="media-info">
                <div class="media-name" title="${name}">${name.substring(0, 15)}${name.length > 15 ? '...' : ''}</div>
            </div>
            <div class="media-check">✓</div>
        </div>`;
    });
    
    // If no valid videos after filtering
    if (html === '') {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No valid videos found</div>';
    } else {
        grid.innerHTML = html;
    }
}
// ========== SWITCH MEDIA TAB ==========
// REPLACE this function:
// REPLACE this function:
function switchMediaTab(tab) {
	
	 lastSearchTerm = '';
    document.getElementById('mediaSearchInput').value = '';
    activeMediaTab = tab;
    selectedMediaFile = null;
    selectedMediaType = null;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No file selected';
    document.getElementById('mediaSearchInput').value = '';
    
    if (tab === 'images') {
        document.getElementById('tabImages').className = 'modal-tab active';
        document.getElementById('tabVideos').className = 'modal-tab';
        // CHANGE THIS LINE - call with empty string for sceneHashtags
        renderMediaGrid(cachedImages, '');
    } else {
        document.getElementById('tabImages').className = 'modal-tab';
        document.getElementById('tabVideos').className = 'modal-tab active';
        renderVideoGrid(cachedVideos);
    }
}

// ========== RENDER MEDIA GRID WITH HASHTAG MATCHING ==========
function renderMediaGrid(images, sceneHashtags = '') {
    const grid = document.getElementById('mediaGrid');
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">No matching images found</div>';
        return;
    }
    
    // Split scene hashtags for highlighting
    const sceneTags = sceneHashtags.toLowerCase().split(',').map(tag => tag.trim()).filter(tag => tag !== '');
    
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
            `<span style="background: #10b981; color: white; padding: 2px 6px; border-radius: 12px; font-size: 8px; margin-left: 4px; display: inline-block;" title="Matches: ${matchingTags.join(', ')}">${matchCount}</span>` : 
            '';
        
        // Format tags for display with colored highlighting
        let tagsDisplay = '';
        if (allTags.length > 0) {
            tagsDisplay = '<div class="media-tags">';
            allTags.slice(0, 4).forEach(tag => {
                const isMatch = matchingTags.includes(tag.toLowerCase());
                tagsDisplay += `<span style="background: ${isMatch ? '#10b981' : '#e2e8f0'}; color: ${isMatch ? 'white' : '#64748b'};">${tag}</span>`;
            });
            if (allTags.length > 4) {
                tagsDisplay += `<span>+${allTags.length-4}</span>`;
            }
            tagsDisplay += '</div>';
        }
        
        html += `
        <div class="media-item" data-file="${name}" data-tags="${tags}" data-type="image" data-match="${matchCount}" title="${tooltipText}" onclick="selectMediaItem(this, '${name}', 'image')">
            <img src="podcast_images/${name}" class="media-preview" loading="lazy" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100%25\' height=\'100%25\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%231e293b\'/%3E%3Ctext x=\'50\' y=\'50\' font-family=\'Arial\' font-size=\'12\' fill=\'%2394a3b8\' text-anchor=\'middle\' dy=\'.3em\'%3E🖼️%3C/text%3E%3C/svg%3E'">
            <div class="media-info">
                <div class="media-name">
                    <span title="${name}">${name.substring(0, 15)}${name.length > 15 ? '…' : ''}</span>
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

// ========== SELECT MEDIA ITEM ==========
function selectMediaItem(el, fileName, mediaType) {
    document.querySelectorAll('#mediaGrid .media-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    selectedMediaFile = fileName;
    selectedMediaType = mediaType;
    document.getElementById('mediaSelInfo').innerHTML = '✅ Selected: ' + fileName.substring(0, 30) + (fileName.length > 30 ? '...' : '');
    document.getElementById('mediaSelectBtn').disabled = false;
}

// ========== FILTER MEDIA ITEMS ==========
function filterMediaItems() {
    const term = document.getElementById('mediaSearchInput').value.toLowerCase();
    let visible = 0;
    
    document.querySelectorAll('#mediaGrid .media-item').forEach(item => {
        const name = (item.dataset.file || '').toLowerCase();
        const tags = (item.dataset.tags || '').toLowerCase();
        
        if (name.includes(term) || tags.includes(term)) {
            item.style.display = '';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });
}

// ========== CONFIRM MEDIA SELECT ==========
async function confirmMediaSelect() {
    if (!selectedMediaFile || !editingSceneId || !editingImageSlot) return;
    
    if (selectedMediaType !== 'image') {
        alert('Please select an image file');
        return;
    }
    
    L('📁 Assigning image ' + selectedMediaFile + ' to scene #' + editingSceneId + ' slot: ' + editingImageSlot);
    
    try {
        // Get the current prompt
        const textarea = document.getElementById('imagePrompt');
        const currentPrompt = textarea ? textarea.value.trim() : '';
        
        const fd = new FormData();
        fd.append('ajax_action', 'update_scene');
        fd.append('scene_id', editingSceneId);
        fd.append('image_field', editingImageSlot);
        fd.append('image_file', selectedMediaFile);
        fd.append('prompt', currentPrompt);
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message);
        
        // Update the scene in memory
        const scene = scenes.find(s => s.id == editingSceneId);
        if (scene) {
            scene[editingImageSlot] = selectedMediaFile;
            
            // Update the corresponding prompt field
            let promptField = 'prompt';
            if (editingImageSlot === 'image_file_1') promptField = 'prompt_1';
            else if (editingImageSlot === 'image_file_2') promptField = 'prompt_2';
            else if (editingImageSlot === 'image_file_3') promptField = 'prompt_3';
            else if (editingImageSlot === 'image_file_4') promptField = 'prompt_4';
            else if (editingImageSlot === 'image_file_5') promptField = 'prompt_5';
            
            scene[promptField] = currentPrompt;
        }
        
        // Update the canvas if this is the current scene and slot
        if (editingSceneId == currentSceneId && editingImageSlot == currentImageField) {
            const imagePreview = document.getElementById('imagePreview');
            imagePreview.src = 'podcast_images/' + selectedMediaFile + '?t=' + Date.now();
            imagePreview.style.display = 'block';
        }
        
        // Reload thumbnails
        if (editingSceneId == currentSceneId) {
            loadImageThumbnails();
        }
        
        L('✅ Scene #' + editingSceneId + ' updated with image from library');
        
    } catch(e) {
        L('❌ Update failed: ' + e.message);
        alert('Failed to update: ' + e.message);
    }
    
    closeMediaLib();
}
// ========== SEARCH FUNCTIONS ==========
let lastSearchTerm = '';

function handleSearchKeyPress(event) {
    // If user presses Enter, trigger search
    if (event.key === 'Enter') {
        performSearch();
    }
}



function clearSearch() {
    const searchInput = document.getElementById('mediaSearchInput');
    searchInput.value = '';
    lastSearchTerm = '';
    
    // Show all items
    document.querySelectorAll('#mediaGrid .media-item').forEach(item => {
        item.style.display = '';
    });
    
    // Update info
    const visibleCount = document.querySelectorAll('#mediaGrid .media-item').length;
    document.getElementById('mediaSelInfo').innerHTML = `📁 Showing all ${visibleCount} images`;
}


function filterMediaItemsByTerm(term) {
    const grid = document.getElementById('mediaGrid');
    let visibleCount = 0;
    let hasResults = false;
    
    // Remove any existing "no results" message
    const existingMsg = grid.querySelector('.no-results-message');
    if (existingMsg) existingMsg.remove();
    
    document.querySelectorAll('#mediaGrid .media-item').forEach(item => {
        const name = (item.dataset.file || '').toLowerCase();
        const tags = (item.dataset.tags || '').toLowerCase();
        
        // Check for match in filename or hashtags
        let matches = term === '' || name.includes(term) || tags.includes(term);
        
        // Special handling for partial matches like "confident" should match "confidentwoman"
        if (term && !matches) {
            // Check if any tag contains the search term as a substring
            const tagArray = tags.split(',').map(t => t.trim());
            matches = tagArray.some(tag => tag.includes(term));
        }
        
        // Special handling for "supportive friend" searches
        if (term && (term.includes('supportive') || term.includes('friend'))) {
            if (tags.includes('supportivefriend')) {
                matches = true;
            }
        }
        
        if (matches) {
            item.style.display = '';
            visibleCount++;
            hasResults = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update info
    if (term === '') {
        document.getElementById('mediaSelInfo').innerHTML = `📁 Showing all ${visibleCount} images`;
    } else if (!hasResults) {
        document.getElementById('mediaSelInfo').innerHTML = `❌ No matches found for "${term}"`;
        
        // Show a "no results" message in the grid
        const noResultsMsg = document.createElement('div');
        noResultsMsg.className = 'no-results-message';
        noResultsMsg.style.cssText = 'grid-column:1/-1; text-align:center; padding:40px; color:var(--muted); font-style:italic;';
        noResultsMsg.innerHTML = `No images found matching "${term}"<br><small>Try different keywords or clear search</small>`;
        grid.appendChild(noResultsMsg);
    } else {
        document.getElementById('mediaSelInfo').innerHTML = `🔍 Found ${visibleCount} matching image${visibleCount !== 1 ? 's' : ''} for "${term}"`;
    }
}
// ========== AUDIO FUNCTIONS ==========
let audioPlayers = {};
let currentPlayingAudioId = null;

// Load podcast music info from database
<?php
// Get podcast music file if exists
$podcast_music = '';
if ($url_podcast_id > 0) {
    $music_query = mysqli_query($conn, "SELECT music_file FROM hdb_podcasts WHERE id = $url_podcast_id");
    if ($music_query && mysqli_num_rows($music_query) > 0) {
        $music_row = mysqli_fetch_assoc($music_query);
        $podcast_music = $music_row['music_file'] ?? '';
    }
}
?>
let podcastMusicFile = '<?= $podcast_music ?>';





function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}



function updateMusicDisplay() {
    const musicInfo = document.getElementById('musicFileName');
    const musicPlayer = document.getElementById('musicPlayerContainer');
    const audioPlayer = document.getElementById('musicAudioPlayer');
    
    if (!musicInfo || !musicPlayer || !audioPlayer) return;
    
    if (podcastMusicFile) {
        musicInfo.innerHTML = podcastMusicFile;
        musicPlayer.style.display = 'block';
        audioPlayer.src = 'podcast_music/' + podcastMusicFile + '?t=' + Date.now();
        audioPlayer.load();
    } else {
        musicInfo.innerHTML = 'No music selected';
        musicPlayer.style.display = 'none';
    }
}

function createAudioPlayerUI(sceneId, filename) {
    const container = document.getElementById('audioPlayerContainer');
    if (!container) return;

    const existingAudio = document.getElementById('audio_' + sceneId);
    if (existingAudio) {
        existingAudio.pause();
        existingAudio.removeAttribute('src');
        existingAudio.load();
        existingAudio.remove();
    }

    container.innerHTML = '';
    
    const playerContainer = document.createElement('div');
    playerContainer.style.cssText = 'display:flex; align-items:center; gap:12px; background:#f8fafc; border-radius:60px; padding:8px 16px; border:1px solid var(--border);';
    
    const playBtn = document.createElement('button');
    playBtn.style.cssText = 'width:44px; height:44px; background:var(--purple); color:white; border:none; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;';
    playBtn.id = 'play_btn_' + sceneId;
    playBtn.innerHTML = '▶';
    playBtn.onclick = () => toggleAudioPlayback(sceneId);
    
    const timeDisplay = document.createElement('span');
    timeDisplay.style.cssText = 'font-size:12px; color:var(--muted); min-width:80px; text-align:center;';
    timeDisplay.id = 'time_' + sceneId;
    timeDisplay.innerText = '0:00 / 0:00';
    
    const progressContainer = document.createElement('div');
    progressContainer.style.cssText = 'flex-grow:1; height:6px; background:var(--border); border-radius:3px; cursor:pointer; position:relative;';
    progressContainer.id = 'progress_container_' + sceneId;
    progressContainer.onclick = (e) => seekAudio(sceneId, e);
    
    const progressFill = document.createElement('div');
    progressFill.style.cssText = 'height:100%; background:var(--purple); border-radius:3px; width:0%;';
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
        if (currentPlayingAudioId === sceneId) currentPlayingAudioId = null;
        progressFill.style.width = '0%';
        timeDisplay.innerText = '0:00 / ' + formatTime(audio.duration);
    };
    
    audio.onplay = () => {
        playBtn.innerHTML = '⏸';
    };
    
    audio.onpause = () => {
        playBtn.innerHTML = '▶';
    };
    
    playerContainer.appendChild(playBtn);
    playerContainer.appendChild(timeDisplay);
    playerContainer.appendChild(progressContainer);
    playerContainer.appendChild(audio);
    
    container.appendChild(playerContainer);
    audioPlayers['audio_' + sceneId] = audio;
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function toggleAudioPlayback(sceneId) {
    const audio = document.getElementById('audio_' + sceneId);
    const playBtn = document.getElementById('play_btn_' + sceneId);
    
    if (!audio) return;
    
    if (currentPlayingAudioId && currentPlayingAudioId !== sceneId) {
        const currentAudio = document.getElementById('audio_' + currentPlayingAudioId);
        if (currentAudio) {
            currentAudio.pause();
            const currentBtn = document.getElementById('play_btn_' + currentPlayingAudioId);
            if (currentBtn) currentBtn.innerHTML = '▶';
        }
    }
    
    if (audio.paused) {
        audio.play();
        currentPlayingAudioId = sceneId;
    } else {
        audio.pause();
        currentPlayingAudioId = null;
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


// ========== SAVE SCENE VOICE TO DATABASE ==========
async function saveSceneVoiceToDB(sceneId, voiceId) {
    const fd = new FormData();
    fd.append('ajax_action', 'save_scene_settings');
    fd.append('scene_id', sceneId);
    fd.append('voice_id', voiceId);
    
    try {
        const {data} = await safeFetch(fd);
        if (!data.success) {
            throw new Error(data.message || 'Failed to save voice');
        }
        L(`✅ Voice saved to scene: ${voiceId}`);
        return true;
    } catch(e) {
        L(`❌ Error saving voice to scene: ${e.message}`);
        return false;
    }
}

// ========== LOAD AUDIO FOR SCENE WITH EDITABLE TEXT - UPDATED ==========
// ========== CURRENT SCENE AUDIO GENERATION - FIXED ==========
async function generateCurrentAudio() {
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    // Get the latest text from textarea
    const textarea = document.getElementById('sceneAudioText');
    const textContent = textarea ? textarea.value.trim() : scene.text_contents || '';
    
    if (!textContent.trim()) {
        alert('This scene has no text content');
        return;
    }
    
    // Save the text first if it changed
    if (textarea && textarea.value !== scene.text_contents) {
        await saveAudioText(currentSceneId, textContent);
    }
    
    // Get the voice_id from the scene record
    let voiceId = scene.voice_id;
    
    // If no voice_id in scene, try to get from podcast-wide settings as fallback
    if (!voiceId) {
        const voiceSelect = document.getElementById('podcastVoiceSelect');
        voiceId = voiceSelect ? voiceSelect.value : '';
        
        if (!voiceId) {
            alert('No voice selected for this scene. Please set a voice in the Podcast-Wide Settings first, or edit the scene to assign a voice.');
            return;
        }
        
        // Save this voice to the scene for future use
        L(`💾 Saving voice_id to scene for future use: ${voiceId}`);
        await saveSceneVoiceToDB(currentSceneId, voiceId);
        scene.voice_id = voiceId;
    }
    
    L('\n🎨 ===== GENERATING AUDIO FOR SCENE #' + currentSceneId + ' =====');
    L(`📝 Text: "${textContent.substring(0, 100)}${textContent.length > 100 ? '...' : ''}"`);
    L(`🎤 Voice from scene: ${voiceId}`);
    
    // Delete existing audio file if any
    if (scene.audio_file) {
        L(`🗑️ Removing old audio: ${scene.audio_file}`);
        // Note: You might want to actually delete the file here
    }
    
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    const langCode = '<?= $podcast_lang_code ?>';
    const newFilename = `voice_${podcastId}_${currentSceneId}_${langCode}_${Date.now()}.mp3`;
    
    L(`📁 Target filename: ${newFilename}`);
    
    const formData = new FormData();
    formData.append('row_id', currentSceneId);
    formData.append('text', textContent);
    formData.append('lang_code', langCode);
    formData.append('voice_id', voiceId);
    formData.append('rate', '1.0');
    formData.append('filename', newFilename); // Pass desired filename
    
    try {
        L('🔄 Sending request to generate_voice.php...');
        const response = await fetch('generate_voice.php', { 
            method: 'POST', 
            body: formData 
        });
        
        const data = await response.json();
        L(`📡 Response received: ${JSON.stringify(data)}`);
        
        if (data.success) {
            let filename = data.filename;
            if (!filename && data.file) {
                const parts = data.file.split('/');
                filename = parts[parts.length - 1].split('?')[0];
            }
            
            if (!filename) {
                L('❌ No filename in response');
                return;
            }
            
            L(`✅ Audio generated: ${filename}`);
            
            // Update the scene in memory
            scene.audio_file = filename;
			 alert(`✅ Audio file generated successfully!\n\nFilename: ${filename}\nScene: ${currentSceneIndex + 1}`);
            
            // Save to database
            L('💾 Saving to database...');
            await saveSceneAudioToDB(currentSceneId, filename, textContent); // CHANGED LINE
            
            // Update audio player
            createAudioPlayerUI(currentSceneId, filename);
            
            L(`✅ Audio generation complete for scene #${currentSceneId}`);
            
        } else {
            L(`❌ API Error: ${data.message || 'Unknown error'}`);
        }
    } catch (err) {
        L(`❌ Network Error: ${err.message}`);
    }
}



// ========== LOAD AUDIO FOR SCENE WITH EDITABLE TEXT - UPDATED ==========
function loadAudioForScene(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    L(`🔊 Loading audio settings for scene #${sceneId} (Scene ${currentSceneIndex + 1})`);
    
    // Update scene text display with EDITABLE textarea
    const textDisplay = document.getElementById('audioSceneTextDisplay');
    if (textDisplay) {
        // Replace the read-only div with an editable textarea
        const currentText = scene.text_contents || '';
        
        textDisplay.innerHTML = `
            <textarea id="sceneAudioText" 
                style="width: 100%; min-height: 80px; padding: 10px; border: 2px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13px; resize: vertical; background: white; color: var(--text);"
                placeholder="Enter text for this scene...">${escapeHtml(currentText)}</textarea>
            <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                <span id="sceneVoiceInfo" style="font-size: 11px; color: var(--info); background: #e0f2fe; padding: 2px 8px; border-radius: 12px;">
                    🎤 Voice: ${scene.voice_id || 'Not set (will use podcast default)'}
                </span>
                <span id="textCharCount" style="font-size: 11px; color: var(--muted);">${currentText.length} characters</span>
            </div>
        `;
        
        // Add character counter
        const textarea = document.getElementById('sceneAudioText');
        if (textarea) {
            textarea.addEventListener('input', function() {
                const count = this.value.length;
                document.getElementById('textCharCount').innerText = count + ' characters';
                
                // Auto-save after 1 second of no typing
                clearTimeout(window.audioTextTimeout);
                window.audioTextTimeout = setTimeout(() => {
                    saveAudioText(sceneId, this.value);
                }, 1000);
            });
        }
    }
    
    // Create audio player if audio file exists
    const audioContainer = document.getElementById('audioPlayerContainer');
    if (audioContainer) {
        if (scene.audio_file) {
            createAudioPlayerUI(sceneId, scene.audio_file);
            L(`✅ Found existing audio: ${scene.audio_file}`);
        } else {
            audioContainer.innerHTML = '<div style="color:var(--muted); text-align:center; padding:20px; background: #f8fafc; border-radius: 8px;">🎵 No audio generated for this scene yet</div>';
        }
    }
    
    // Load podcast music info
    updateMusicDisplay();
}



// ========== SAVE SCENE AUDIO TO DATABASE - FIXED ==========
async function saveSceneAudioToDB(sceneId, audioFile, textContent = null) {
    const fd = new FormData();
    fd.append('ajax_action', 'save_scene_settings');
    fd.append('scene_id', sceneId);
    fd.append('audio_file', audioFile);
    
    // If text content is provided, save it too
    if (textContent !== null) {
        fd.append('text_contents', textContent);
    }
    
    // PRESERVE voice_id - get it from the scene or use the currently selected one
    const scene = scenes.find(s => s.id == sceneId);
    const voiceToPreserve = scene?.voice_id || document.getElementById('podcastVoiceSelect')?.value || '';
    fd.append('voice_id', voiceToPreserve);
    
    try {
        const {data} = await safeFetch(fd);
        if (!data.success) {
            throw new Error(data.message || 'Failed to save audio');
        }
        L(`✅ Audio file saved to database: ${audioFile}`);
        if (textContent !== null) {
            L(`✅ Text content also saved for scene #${sceneId}`);
        }
        return true;
    } catch(e) {
        L(`❌ Error saving audio to database: ${e.message}`);
        return false;
    }
}

// ========== PODCAST-WIDE FUNCTIONS ==========
function previewSelectedVoice() {
    const voiceSelect = document.getElementById('podcastVoiceSelect');
    if (!voiceSelect) return;
    
    const selectedOption = voiceSelect.options[voiceSelect.selectedIndex];
    
    if (!voiceSelect.value) {
        alert('Please select a voice first');
        return;
    }
    
    // Get sample URL from data attribute
    let sampleUrl = selectedOption.getAttribute('data-sample');
    
    if (!sampleUrl) {
        L('⚠️ No sample available for this voice');
        alert('No sample available for this voice');
        return;
    }
    
    L(`🔊 Playing voice sample`);
    
    const audio = document.getElementById('sampleAudioPlayer');
    audio.src = sampleUrl;
    audio.play()
        .then(() => L('✅ Playing sample'))
        .catch(err => L('❌ Playback error: ' + err.message));
}

// ========== GENERATE ALL AUDIO WITH SELECTED VOICE ==========
async function generateAllAudioWithSelectedVoice() {
    if (!scenes.length) {
        alert('No scenes loaded');
        return;
    }

    const voiceSelect = document.getElementById('podcastVoiceSelect');
    if (!voiceSelect || !voiceSelect.value) {
        alert('Please select a voice first');
        return;
    }

    if (!confirm("Generate audio for ALL scenes using the selected voice?\n\nThis will replace existing audio for all scenes AND update each scene's voice_id to the selected voice.")) return;

    L('\n🎤 ===== BATCH AUDIO GENERATION STARTED =====');
    L(`Total scenes: ${scenes.length}`);
    L(`Voice: ${voiceSelect.value}`);
    
    let completed = 0;
    let failed = 0;
    const total = scenes.length;
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    const langCode = '<?= $podcast_lang_code ?>';
    const newVoiceId = voiceSelect.value;

    for (let i = 0; i < scenes.length; i++) {
        const scene = scenes[i];
        
        L(`\n📌 Scene ${i+1}/${total} (ID: ${scene.id})`);
        
        // Get text content from the scene
        const textContent = scene.text_contents || '';
        if (!textContent.trim()) {
            L(`⚠️ Scene ${i+1} has no text, skipping...`);
            completed++;
            continue;
        }
        
        // First update the scene's voice_id
        L(`💾 Updating scene voice_id to: ${newVoiceId}`);
        await saveSceneVoiceToDB(scene.id, newVoiceId);
        scene.voice_id = newVoiceId;
        
        // Generate filename: voice_podcastId_sceneId_langCode.mp3
        const newFilename = `voice_${podcastId}_${scene.id}_${langCode}.mp3`;
        
        L(`📁 Target: ${newFilename}`);
        
        const formData = new FormData();
        formData.append('row_id', scene.id);
        formData.append('text', textContent);
        formData.append('lang_code', langCode);
        formData.append('voice_id', newVoiceId);
        formData.append('rate', '1.0');
        formData.append('filename', newFilename);
        
        try {
            L('🔄 Generating...');
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
                
                if (filename) {
                    scene.audio_file = filename;
                    
                    // Save both audio file AND text content to ensure they match
                    await saveSceneAudioToDB(scene.id, filename, textContent);
                    
                    L(`✅ Scene ${i+1} complete: ${filename}`);
                    completed++;
                } else {
                    L(`❌ Scene ${i+1}: No filename returned`);
                    failed++;
                }
            } else {
                L(`❌ Scene ${i+1} failed: ${data.message || 'Unknown error'}`);
                failed++;
            }
        } catch (err) {
            L(`❌ Scene ${i+1} network error: ${err.message}`);
            failed++;
        }
        
        // Small delay between generations to avoid overwhelming the server
        await new Promise(r => setTimeout(r, 500));
    }

    L('\n🎤 ===== BATCH GENERATION COMPLETE =====');
    L(`✅ Successful: ${completed}/${total}`);
    L(`❌ Failed: ${failed}/${total}`);
    
    // Reload current scene audio if it was generated
    if (currentSceneId) {
        const currentScene = scenes.find(s => s.id == currentSceneId);
        if (currentScene && currentScene.audio_file) {
            createAudioPlayerUI(currentSceneId, currentScene.audio_file);
        }
    }
    
    // Show summary alert
    alert(`✅ Batch audio generation complete!\n\nSuccessful: ${completed}/${total}\nFailed: ${failed}/${total}`);
}

// ========== SAVE AUDIO TEXT TO DATABASE ==========
async function saveAudioText(sceneId, text) {
    if (!sceneId || !text) return;
    
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    // Only save if text changed
    if (scene.text_contents === text) {
        return;
    }
    
    L(`💾 Saving edited text for scene #${sceneId}...`);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_text'); // Reusing save_prompt but for text_contents
        fd.append('scene_id', sceneId);
        fd.append('prompt', text);
        fd.append('prompt_field', 'text_contents'); // Special handling for text_contents
		  
        const {data} = await safeFetch(fd);
        if (data.success) {
            scene.text_contents = text;
            L(`✅ Text saved for scene #${sceneId} (${text.length} chars)`);
        } else {
            throw new Error(data.message || 'Save failed');
        }
    } catch(e) {
        L(`❌ Failed to save text: ${e.message}`);
    }
}


// ========== UPLOAD BACKGROUND MUSIC ==========
function uploadBackgroundMusic() {
    const fileInput = document.getElementById('musicUploadInput');
    if (fileInput) {
        fileInput.click();
    }
}

// ========== CLEAR BACKGROUND MUSIC ==========
function clearBackgroundMusic() {
    if (!confirm('Remove background music from this podcast?')) return;
    
    L('🗑️ Removing background music');
    podcastMusicFile = '';
    updateMusicDisplay();
    saveMusicToDatabase('');
}

// ========== SAVE MUSIC TO DATABASE ==========
async function saveMusicToDatabase(filename) {
    const fd = new FormData();
    fd.append('ajax_action', 'update_podcast_music');
    fd.append('podcast_id', <?= $url_podcast_id ?>);
    fd.append('music_file', filename);
    
    try {
        const {data} = await safeFetch(fd);
        if (!data.success) {
            console.error('Failed to save music:', data.message);
        } else {
            L(`✅ Music updated: ${filename || 'none'}`);
        }
    } catch(e) {
        console.error('Error saving music:', e);
    }
}

// ========== SETUP EVENT LISTENERS ==========

// ------------------------------- translation functionality 
// ========== TRANSLATE VIDEO FUNCTION (Global) ==========
// Make sure this is defined globally and accessible from the onclick handler
// ========== TRANSLATE VIDEO MODAL - COMPLETE FIX ==========
let translateModal = null;
let translateLanguages = [];
let translateVoices = [];
let selectedTranslateLang = '';
let selectedHostVoice = '';
let selectedGuestVoice = '';
let isTranslating = false;
let targetPodcastId = <?= $url_podcast_id ?: 0 ?>;

// ========== INITIALIZE TRANSLATE MODAL ==========
function initTranslateModal() {
    console.log('Initializing translate modal');
    
    // Remove existing modal if any
    const existingModal = document.getElementById('translateModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Get video type from PHP
    const videoType = '<?= $video_type ?? 'standard' ?>';
    
    // Create modal HTML
    const modalHTML = `
    <div id="translateModal" class="modal-overlay" style="z-index: 10001; display: none;">
        <div class="modal-content" style="max-width: 650px; width: 90%;">
            <div class="modal-header">
                <h3><span style="margin-right: 8px;">🌐</span> Translate Project to New Language</h3>
                <button class="modal-close" onclick="closeTranslateModal()">✕</button>
            </div>
            
            <div class="modal-body" style="padding: 24px; max-height: 70vh; overflow-y: auto;">
                <!-- Current Project Info -->
                <div style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 16px; border-radius: 12px; margin-bottom: 24px; border-left: 4px solid #0f2a44;">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="background: #0f2a44; color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            📋
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 2px;">Current Project</div>
                            <div style="font-weight: 700; color: #0f2a44; font-size: 16px;"><?= htmlspecialchars($podcast_title ?: 'Untitled') ?></div>
                            <div style="display: flex; gap: 8px; margin-top: 4px;">
                                <span style="background: #0f2a44; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;">ID: <?= $url_podcast_id ?></span>
                                <span style="background: #64748b; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;"><?= strtoupper($podcast_lang_code ?: 'EN') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Step 1: Language Selection -->
                <div style="margin-bottom: 28px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <span style="background: #0f2a44; color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">1</span>
                        <span style="font-weight: 700; color: #0f2a44; font-size: 15px;">Select Target Language</span>
                    </div>
                    <select id="translateLangSelect" class="panel-select" style="width: 100%; padding: 14px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 14px;" onchange="onLanguageSelect(this.value)">
                        <option value="">-- Choose a language --</option>
                    </select>
                    <div id="languageLoadStatus" style="font-size: 12px; color: #64748b; margin-top: 6px; display: flex; align-items: center; gap: 6px;">
                        <span class="loading-spinner" style="display: none; width: 12px; height: 12px; border: 2px solid #e2e8f0; border-top-color: #0f2a44; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                        <span id="languageStatusText">Loading languages...</span>
                    </div>
                </div>
                
                <!-- Step 2: Voice Selection (shows after language selected) -->
                <div id="voiceSelectionSection" style="display: none; margin-bottom: 28px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <span style="background: #0f2a44; color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">2</span>
                        <span style="font-weight: 700; color: #0f2a44; font-size: 15px;">Select Voices for <span id="selectedLangName"></span></span>
                    </div>
                    
                    <div style="background: #f8fafc; padding: 20px; border-radius: 16px; border: 2px solid #e2e8f0;">
                        ${videoType === 'podcast' ? `
                        <!-- Podcast: Host and Guest Voices -->
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 20px;">🎙️</span>
                                    <span style="font-weight: 600; color: #0f2a44;">Host Voice</span>
                                </div>
                                <button class="panel-btn" onclick="playVoiceSample('host')" style="background: #8b5cf6; color: white; border: none; padding: 6px 14px; border-radius: 30px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                    <span>🔊</span> Play Sample
                                </button>
                            </div>
                            <select id="hostVoiceSelect" class="panel-select" style="width: 100%; padding: 12px; border-radius: 10px;" onchange="updateTranslateButtonState()">
                                <option value="">Select host voice</option>
                            </select>
                        </div>
                        
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 20px;">👤</span>
                                    <span style="font-weight: 600; color: #0f2a44;">Guest Voice</span>
                                </div>
                                <button class="panel-btn" onclick="playVoiceSample('guest')" style="background: #8b5cf6; color: white; border: none; padding: 6px 14px; border-radius: 30px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                    <span>🔊</span> Play Sample
                                </button>
                            </div>
                            <select id="guestVoiceSelect" class="panel-select" style="width: 100%; padding: 12px; border-radius: 10px;" onchange="updateTranslateButtonState()">
                                <option value="">Select guest voice</option>
                            </select>
                        </div>
                        ` : `
                        <!-- Single video: Narrator Voice -->
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 20px;">🎤</span>
                                    <span style="font-weight: 600; color: #0f2a44;">Narrator Voice</span>
                                </div>
                                <button class="panel-btn" onclick="playVoiceSample('narrator')" style="background: #8b5cf6; color: white; border: none; padding: 6px 14px; border-radius: 30px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                    <span>🔊</span> Play Sample
                                </button>
                            </div>
                            <select id="narratorVoiceSelect" class="panel-select" style="width: 100%; padding: 12px; border-radius: 10px;" onchange="updateTranslateButtonState()">
                                <option value="">Select narrator voice</option>
                            </select>
                        </div>
                        ` }
                    </div>
                    
                    <div id="voiceLoadStatus" style="font-size: 12px; color: #64748b; margin-top: 8px; display: none; align-items: center; gap: 6px;">
                        <span class="loading-spinner" style="width: 12px; height: 12px; border: 2px solid #e2e8f0; border-top-color: #0f2a44; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                        <span>Loading voices...</span>
                    </div>
                </div>
                
                <!-- Warning Message -->
                <div id="translateWarning" style="background: #fef3c7; border: 1px solid #f59e0b; padding: 14px; border-radius: 10px; font-size: 13px; color: #92400e; margin-bottom: 20px; display: none; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">⚠️</span>
                    <span>A version in this language already exists. It will be <strong>deleted and replaced</strong>.</span>
                </div>
                
                <!-- Progress Bar -->
                <div id="translateProgress" style="display: none; margin: 20px 0;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: #0f2a44;">Translation Progress</span>
                        <span id="translateProgressPercent" style="font-size: 13px; font-weight: 600; color: #0f2a44;">0%</span>
                    </div>
                    <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                        <div id="translateProgressFill" style="height: 100%; background: linear-gradient(90deg, #0f2a44, #5fd1ff); width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <div id="translateStatus" style="font-size: 12px; color: #64748b; margin-top: 10px; text-align: center; padding: 8px; background: #f8fafc; border-radius: 8px;">
                        Ready to start translation
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-top: 1px solid #e2e8f0;">
                <div style="font-size: 12px; color: #64748b;">
                    <span id="sceneCount"><?= count($scenes) ?></span> scenes will be translated
                </div>
                <div style="display: flex; gap: 12px;">
                    <button class="panel-btn" onclick="closeTranslateModal()" style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button id="translateBtn" class="panel-btn" onclick="startTranslation()" style="background: #10b981; color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;" disabled>
                        🌐 Translate Now
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hidden audio element for voice samples -->
    <audio id="sampleAudioPlayerTranslate" style="display:none;"></audio>
    
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #translateModal .modal-content {
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
		
		.loading-spinner {
			display: inline-block;
			width: 12px;
			height: 12px;
			border: 2px solid #e2e8f0;
			border-top-color: #0f2a44;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
    </style>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    translateModal = document.getElementById('translateModal');
    
    // Load languages immediately
    loadTranslateLanguages();
    
    console.log('Translate modal initialized');
}

// ========== LOAD LANGUAGES ==========
// ========== LOAD LANGUAGES ==========
async function loadTranslateLanguages() {
    const statusEl = document.getElementById('languageStatusText');
    const spinner = document.querySelector('#languageLoadStatus .loading-spinner');
    
    if (spinner) spinner.style.display = 'inline-block';
    if (statusEl) statusEl.innerText = 'Loading languages...';
    
    try {
        const currentLang = '<?= $podcast_lang_code ?>';
        const fd = new FormData();
        fd.append('ajax_action', 'get_translate_languages');
        fd.append('exclude_lang', currentLang);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        // Get the response text
        const text = await response.text();
        console.log('Languages response preview:', text.substring(0, 200));
        
        // Try to parse JSON
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            console.error('Failed to parse languages response. First 500 chars:', text.substring(0, 500));
            
            // Fallback to default languages
            const fallbackLangs = [
                { lang_code: 'ur', lang_name: 'Urdu' },
                { lang_code: 'ar', lang_name: 'Arabic' },
                { lang_code: 'hi', lang_name: 'Hindi' },
                { lang_code: 'fr', lang_name: 'French' },
                { lang_code: 'es', lang_name: 'Spanish' },
                { lang_code: 'de', lang_name: 'German' },
                { lang_code: 'zh', lang_name: 'Chinese' },
                { lang_code: 'ja', lang_name: 'Japanese' },
                { lang_code: 'ko', lang_name: 'Korean' },
                { lang_code: 'ru', lang_name: 'Russian' }
            ];
            
            translateLanguages = fallbackLangs;
            
            const select = document.getElementById('translateLangSelect');
            select.innerHTML = '<option value="">-- Choose a language --</option>';
            
            fallbackLangs.forEach(lang => {
                if (lang.lang_code !== currentLang) {
                    const flag = getLanguageFlag(lang.lang_code);
                    select.innerHTML += `<option value="${lang.lang_code}">${flag} ${lang.lang_name}</option>`;
                }
            });
            
            if (statusEl) statusEl.innerText = `${fallbackLangs.length} languages loaded (fallback)`;
            if (spinner) spinner.style.display = 'none';
            return;
        }
        
        if (data.success && data.languages && data.languages.length > 0) {
            translateLanguages = data.languages;
            
            const select = document.getElementById('translateLangSelect');
            select.innerHTML = '<option value="">-- Choose a language --</option>';
            
            data.languages.forEach(lang => {
                const flag = getLanguageFlag(lang.lang_code);
                select.innerHTML += `<option value="${lang.lang_code}">${flag} ${lang.lang_name} (${lang.lang_code})</option>`;
            });
            
            if (statusEl) statusEl.innerText = `${data.languages.length} languages loaded`;
        } else {
            throw new Error('No languages returned');
        }
    } catch(e) {
        console.error('Error loading languages:', e);
        if (statusEl) statusEl.innerText = 'Failed to load languages';
    } finally {
        if (spinner) spinner.style.display = 'none';
    }
}

// ========== GET LANGUAGE FLAG ==========
function getLanguageFlag(langCode) {
    const flags = {
        'ur': '🇵🇰', 'ar': '🇸🇦', 'hi': '🇮🇳', 'fr': '🇫🇷', 'es': '🇪🇸',
        'de': '🇩🇪', 'zh': '🇨🇳', 'ja': '🇯🇵', 'ko': '🇰🇷', 'ru': '🇷🇺',
        'pt': '🇵🇹', 'it': '🇮🇹', 'nl': '🇳🇱', 'tr': '🇹🇷', 'pl': '🇵🇱'
    };
    return flags[langCode] || '🌐';
}

// ========== ON LANGUAGE SELECT ==========
async function onLanguageSelect(langCode) {
    selectedTranslateLang = langCode;
    
    const voiceSection = document.getElementById('voiceSelectionSection');
    const translateBtn = document.getElementById('translateBtn');
    const langNameSpan = document.getElementById('selectedLangName');
    
    if (!langCode) {
        voiceSection.style.display = 'none';
        translateBtn.disabled = true;
        translateBtn.style.opacity = '0.5';
        return;
    }
    
    // Update language name display
    const selectedLang = translateLanguages.find(l => l.lang_code === langCode);
    if (langNameSpan && selectedLang) {
        langNameSpan.innerText = selectedLang.lang_name;
    }
    
    // Show voice section
    voiceSection.style.display = 'block';
    translateBtn.disabled = true;
    translateBtn.style.opacity = '0.5';
    
    // Check if language exists
    await checkIfLanguageExists(langCode);
    
    // Load voices
    await loadVoicesForLanguage(langCode);
}

// ========== LOAD VOICES FOR LANGUAGE ==========
async function loadVoicesForLanguage(langCode) {
    const voiceStatus = document.getElementById('voiceLoadStatus');
    const spinner = voiceStatus?.querySelector('.loading-spinner');
    
    if (voiceStatus) voiceStatus.style.display = 'flex';
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_voices_by_language');
        fd.append('lang_code', langCode);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch(e) {
            console.error('Failed to parse voices response:', text.substring(0, 200));
            data = { success: false };
        }
        
        const videoType = '<?= $video_type ?? 'standard' ?>';
        
        if (data.success && data.voices && data.voices.length > 0) {
            translateVoices = data.voices;
            
            if (videoType === 'podcast') {
                // Update host and guest dropdowns
                const hostSelect = document.getElementById('hostVoiceSelect');
                const guestSelect = document.getElementById('guestVoiceSelect');
                
                hostSelect.innerHTML = '<option value="">Select host voice</option>';
                guestSelect.innerHTML = '<option value="">Select guest voice</option>';
                
                data.voices.forEach((voice, index) => {
                    const sampleAttr = voice.sample_voice ? `data-sample="${voice.sample_voice}"` : '';
                    const option = `<option value="${voice.voice_key}" ${sampleAttr}>${voice.voice_name} - ${voice.voice_description || ''}</option>`;
                    hostSelect.innerHTML += option;
                    guestSelect.innerHTML += option;
                });
                
                // Auto-select first for host, second for guest if available
                if (data.voices.length > 0) {
                    hostSelect.value = data.voices[0].voice_key;
                    if (data.voices.length > 1) {
                        guestSelect.value = data.voices[1].voice_key;
                    } else {
                        guestSelect.value = data.voices[0].voice_key;
                    }
                }
            } else {
                // Update narrator dropdown
                const narratorSelect = document.getElementById('narratorVoiceSelect');
                narratorSelect.innerHTML = '<option value="">Select narrator voice</option>';
                
                data.voices.forEach(voice => {
                    const sampleAttr = voice.sample_voice ? `data-sample="${voice.sample_voice}"` : '';
                    narratorSelect.innerHTML += `<option value="${voice.voice_key}" ${sampleAttr}>${voice.voice_name} - ${voice.voice_description || ''}</option>`;
                });
                
                if (data.voices.length > 0) {
                    narratorSelect.value = data.voices[0].voice_key;
                }
            }
            
            if (voiceStatus) voiceStatus.style.display = 'none';
            updateTranslateButtonState();
            
        } else {
            // Fallback voices
            const fallbackVoices = getFallbackVoices(langCode);
            
            if (videoType === 'podcast') {
                const hostSelect = document.getElementById('hostVoiceSelect');
                const guestSelect = document.getElementById('guestVoiceSelect');
                
                hostSelect.innerHTML = '<option value="">Select host voice</option>';
                guestSelect.innerHTML = '<option value="">Select guest voice</option>';
                
                fallbackVoices.forEach((voice, index) => {
                    const option = `<option value="${voice.key}" data-sample="${voice.sample || ''}">${voice.name}</option>`;
                    hostSelect.innerHTML += option;
                    guestSelect.innerHTML += option;
                });
                
                if (fallbackVoices.length > 0) {
                    hostSelect.value = fallbackVoices[0].key;
                    guestSelect.value = fallbackVoices.length > 1 ? fallbackVoices[1].key : fallbackVoices[0].key;
                }
            } else {
                const narratorSelect = document.getElementById('narratorVoiceSelect');
                narratorSelect.innerHTML = '<option value="">Select narrator voice</option>';
                
                fallbackVoices.forEach(voice => {
                    narratorSelect.innerHTML += `<option value="${voice.key}" data-sample="${voice.sample || ''}">${voice.name}</option>`;
                });
                
                if (fallbackVoices.length > 0) {
                    narratorSelect.value = fallbackVoices[0].key;
                }
            }
            
            if (voiceStatus) voiceStatus.style.display = 'none';
            updateTranslateButtonState();
        }
        
    } catch(e) {
        console.error('Error loading voices:', e);
        if (voiceStatus) {
            voiceStatus.innerHTML = '<span style="color: #ef4444;">Failed to load voices</span>';
            setTimeout(() => {
                if (voiceStatus) voiceStatus.style.display = 'none';
            }, 3000);
        }
    }
}

// ========== GET FALLBACK VOICES ==========
function getFallbackVoices(langCode) {
    const voices = {
        'ur': [
            { key: 'ur-PK-AsadNeural', name: 'Asad - Male (Urdu)', sample: '' },
            { key: 'ur-PK-UzmaNeural', name: 'Uzma - Female (Urdu)', sample: '' }
        ],
        'ar': [
            { key: 'ar-SA-HamedNeural', name: 'Hamed - Male (Arabic)', sample: '' },
            { key: 'ar-SA-ZariyahNeural', name: 'Zariyah - Female (Arabic)', sample: '' }
        ],
        'hi': [
            { key: 'hi-IN-MadhurNeural', name: 'Madhur - Male (Hindi)', sample: '' },
            { key: 'hi-IN-SwaraNeural', name: 'Swara - Female (Hindi)', sample: '' }
        ],
        'fr': [
            { key: 'fr-FR-HenriNeural', name: 'Henri - Male (French)', sample: '' },
            { key: 'fr-FR-DeniseNeural', name: 'Denise - Female (French)', sample: '' }
        ],
        'es': [
            { key: 'es-ES-AlvaroNeural', name: 'Alvaro - Male (Spanish)', sample: '' },
            { key: 'es-ES-ElviraNeural', name: 'Elvira - Female (Spanish)', sample: '' }
        ],
        'de': [
            { key: 'de-DE-ConradNeural', name: 'Conrad - Male (German)', sample: '' },
            { key: 'de-DE-KatjaNeural', name: 'Katja - Female (German)', sample: '' }
        ],
        'zh': [
            { key: 'zh-CN-YunxiNeural', name: 'Yunxi - Male (Chinese)', sample: '' },
            { key: 'zh-CN-XiaoxiaoNeural', name: 'Xiaoxiao - Female (Chinese)', sample: '' }
        ]
    };
    
    return voices[langCode] || [
        { key: 'en-US-GuyNeural', name: 'Guy - Male (English)', sample: '' },
        { key: 'en-US-JennyNeural', name: 'Jenny - Female (English)', sample: '' }
    ];
}

// ========== CHECK IF LANGUAGE EXISTS ==========
async function checkIfLanguageExists(langCode) {
    try {
        const podcastTitle = '<?= addslashes($podcast_title) ?>';
        const fd = new FormData();
        fd.append('ajax_action', 'check_language_exists');
        fd.append('title', podcastTitle);
        fd.append('lang_code', langCode);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const data = await response.json();
        
        const warningDiv = document.getElementById('translateWarning');
        if (data.exists) {
            warningDiv.style.display = 'flex';
        } else {
            warningDiv.style.display = 'none';
        }
    } catch(e) {
        console.error('Error checking language:', e);
    }
}

// ========== UPDATE TRANSLATE BUTTON STATE ==========
function updateTranslateButtonState() {
    const translateBtn = document.getElementById('translateBtn');
    const videoType = '<?= $video_type ?? 'standard' ?>';
    
    let enabled = false;
    
    if (videoType === 'podcast') {
        const hostVoice = document.getElementById('hostVoiceSelect')?.value;
        const guestVoice = document.getElementById('guestVoiceSelect')?.value;
        enabled = !!(hostVoice && guestVoice && selectedTranslateLang);
        
        if (enabled) {
            selectedHostVoice = hostVoice;
            selectedGuestVoice = guestVoice;
        }
    } else {
        const narratorVoice = document.getElementById('narratorVoiceSelect')?.value;
        enabled = !!(narratorVoice && selectedTranslateLang);
    }
    
    if (enabled) {
        translateBtn.disabled = false;
        translateBtn.style.opacity = '1';
        translateBtn.style.cursor = 'pointer';
    } else {
        translateBtn.disabled = true;
        translateBtn.style.opacity = '0.5';
        translateBtn.style.cursor = 'default';
    }
}

// ========== PLAY VOICE SAMPLE ==========
function playVoiceSample(type) {
    let select;
    const videoType = '<?= $video_type ?? 'standard' ?>';
    
    if (videoType === 'podcast') {
        select = type === 'host' ? document.getElementById('hostVoiceSelect') : document.getElementById('guestVoiceSelect');
    } else {
        select = document.getElementById('narratorVoiceSelect');
    }
    
    if (!select || !select.value) {
        alert('Please select a voice first');
        return;
    }
    
    const selectedOption = select.options[select.selectedIndex];
    const sampleUrl = selectedOption.getAttribute('data-sample');
    
    if (!sampleUrl) {
        alert('No sample available for this voice');
        return;
    }
    
    const audio = document.getElementById('sampleAudioPlayerTranslate');
    audio.src = sampleUrl;
    audio.play()
        .then(() => L(`🔊 Playing ${type} voice sample`))
        .catch(err => {
            console.error('Playback error:', err);
            alert('Could not play sample. The audio file may be unavailable.');
        });
}

// ========== START TRANSLATION ==========
async function startTranslation() {
    if (isTranslating) return;
    
    const videoType = '<?= $video_type ?? 'standard' ?>';
    let hostVoice, guestVoice, narratorVoice;
    
    if (videoType === 'podcast') {
        hostVoice = document.getElementById('hostVoiceSelect').value;
        guestVoice = document.getElementById('guestVoiceSelect').value;
        
        if (!hostVoice || !guestVoice) {
            alert('Please select both host and guest voices');
            return;
        }
    } else {
        narratorVoice = document.getElementById('narratorVoiceSelect').value;
        
        if (!narratorVoice) {
            alert('Please select a narrator voice');
            return;
        }
    }
    
    if (!selectedTranslateLang) {
        alert('Please select a target language');
        return;
    }
    
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    if (!podcastId) {
        alert('Invalid podcast ID');
        return;
    }
    
    // Check warning
    const warningDiv = document.getElementById('translateWarning');
    if (warningDiv.style.display === 'flex') {
        if (!confirm('⚠️ A version in this language already exists. It will be DELETED and REPLACED. Continue?')) {
            return;
        }
    }
    
    isTranslating = true;
    
    // Show progress
    document.getElementById('translateProgress').style.display = 'block';
    document.getElementById('translateBtn').disabled = true;
    document.getElementById('translateLangSelect').disabled = true;
    
    const totalScenes = <?= count($scenes) ?>;
    let completedScenes = 0;
    
    updateTranslateProgress(5, 'Creating translated project...');
    
    try {
        // STEP 1: Clone podcast with translations
        const formData = new FormData();
        formData.append('action', 'clone_podcast');
        formData.append('podcast_id', podcastId);
        formData.append('target_lang', selectedTranslateLang);
        
        updateTranslateProgress(10, 'Sending translation request...');
        
        const response = await fetch('trans_gen.php', {
            method: 'POST',
            body: formData
        });
        
        const rawText = await response.text();
        let data;
        
        try {
            data = JSON.parse(rawText);
        } catch(e) {
            console.error('Invalid JSON response:', rawText.substring(0, 500));
            throw new Error('Server returned invalid response');
        }
        
        if (!data.success || !data.results || data.results.length === 0) {
            throw new Error(data.error || 'Translation failed');
        }
        
        const result = data.results[0];
        if (result.status !== 'success') {
            throw new Error(result.error || 'Translation failed');
        }
        
        const newPodcastId = result.podcast_id;
        
        updateTranslateProgress(30, `Project created (ID: ${newPodcastId}). Generating audio...`);
        
        // STEP 2: Get scenes for the new podcast
        const scenes = await getScenesForPodcast(newPodcastId);
        
        if (!scenes || scenes.length === 0) {
            throw new Error('No scenes found in translated project');
        }
        
        // STEP 3: Generate audio for each scene
        for (let i = 0; i < scenes.length; i++) {
            const scene = scenes[i];
            const progress = 30 + Math.floor((i / scenes.length) * 60);
            
            updateTranslateProgress(progress, `Generating audio ${i+1}/${scenes.length}...`);
            
            // Determine which voice to use
            let voiceToUse;
            if (videoType === 'podcast') {
                // Alternate between host and guest
                voiceToUse = (i % 2 === 0) ? hostVoice : guestVoice;
            } else {
                voiceToUse = narratorVoice;
            }
            
            // Generate audio
            const audioFormData = new FormData();
            audioFormData.append('row_id', scene.id);
            audioFormData.append('text', scene.text_contents || '');
            audioFormData.append('lang_code', selectedTranslateLang);
            audioFormData.append('voice_id', voiceToUse);
            audioFormData.append('rate', '1.0');
            
            const audioResponse = await fetch('generate_voice.php', {
                method: 'POST',
                body: audioFormData
            });
            
            const audioText = await audioResponse.text();
            let audioData;
            
            try {
                audioData = JSON.parse(audioText);
            } catch(e) {
                console.error('Invalid audio response:', audioText.substring(0, 200));
                continue;
            }
            
            if (audioData.success) {
                let filename = audioData.filename;
                if (!filename && audioData.file) {
                    const parts = audioData.file.split('/');
                    filename = parts[parts.length - 1].split('?')[0];
                }
                
                if (filename) {
                    await updateSceneAudio(scene.id, filename);
                    completedScenes++;
                }
            }
            
            // Small delay to avoid overwhelming the server
            await new Promise(r => setTimeout(r, 300));
        }
        
        updateTranslateProgress(100, `✅ Complete! ${completedScenes}/${scenes.length} scenes with audio`);
        
        // Success message
        setTimeout(() => {
            L(`✅ Translation to ${selectedTranslateLang} complete! New podcast ID: ${newPodcastId}`);
            
            if (confirm(`✅ Translation complete!\n\nNew ${selectedTranslateLang} version created with ${completedScenes} audio files.\n\nGo to the translated project now?`)) {
                window.location.href = `videomaker.php?podcast_id=${newPodcastId}`;
            } else {
                closeTranslateModal();
            }
        }, 500);
        
    } catch(error) {
        console.error('Translation error:', error);
        updateTranslateProgress(0, `❌ Error: ${error.message}`);
        alert(`Translation failed: ${error.message}`);
    } finally {
        isTranslating = false;
        document.getElementById('translateBtn').disabled = false;
        document.getElementById('translateLangSelect').disabled = false;
    }
}

// ========== UPDATE PROGRESS ==========
function updateTranslateProgress(percent, status) {
    const fill = document.getElementById('translateProgressFill');
    const percentSpan = document.getElementById('translateProgressPercent');
    const statusDiv = document.getElementById('translateStatus');
    
    if (fill) fill.style.width = percent + '%';
    if (percentSpan) percentSpan.innerText = percent + '%';
    if (statusDiv) statusDiv.innerText = status;
}

// ========== GET SCENES FOR PODCAST ==========
async function getScenesForPodcast(podcastId) {
    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('ajax_action', 'get_podcast_scenes');
        fd.append('podcast_id', podcastId);
        
        fetch(window.location.href, {
            method: 'POST',
            body: fd
        })
        .then(r => r.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success && data.scenes) {
                    resolve(data.scenes);
                } else {
                    // Fallback: create scenes with same text as current
                    const currentScenes = <?= json_encode($scenes) ?>;
                    const fallbackScenes = currentScenes.map((scene, index) => ({
                        id: podcastId * 1000 + index,
                        text_contents: scene.text_contents || ''
                    }));
                    resolve(fallbackScenes);
                }
            } catch(e) {
                // Fallback
                const currentScenes = <?= json_encode($scenes) ?>;
                const fallbackScenes = currentScenes.map((scene, index) => ({
                    id: podcastId * 1000 + index,
                    text_contents: scene.text_contents || ''
                }));
                resolve(fallbackScenes);
            }
        })
        .catch(() => {
            // Fallback
            const currentScenes = <?= json_encode($scenes) ?>;
            const fallbackScenes = currentScenes.map((scene, index) => ({
                id: podcastId * 1000 + index,
                text_contents: scene.text_contents || ''
            }));
            resolve(fallbackScenes);
        });
    });
}

// ========== UPDATE SCENE AUDIO ==========
async function updateSceneAudio(sceneId, audioFile) {
    const fd = new FormData();
    fd.append('ajax_action', 'save_scene_settings');
    fd.append('scene_id', sceneId);
    fd.append('audio_file', audioFile);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const text = await response.text();
        try {
            const data = JSON.parse(text);
            return data.success;
        } catch(e) {
            return false;
        }
    } catch(e) {
        console.error('Error updating scene audio:', e);
        return false;
    }
}

// ========== CLOSE TRANSLATE MODAL ==========
function closeTranslateModal() {
    if (translateModal) {
        translateModal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Reset state
        isTranslating = false;
        selectedTranslateLang = '';
        selectedHostVoice = '';
        selectedGuestVoice = '';
    }
}

// ========== OPEN TRANSLATE MODAL (GLOBAL) ==========
window.TranslateVideo = function() {
    console.log('TranslateVideo called');
    
    // Initialize modal if not exists
    if (!document.getElementById('translateModal')) {
        initTranslateModal();
    }
    
    translateModal = document.getElementById('translateModal');
    
    if (translateModal) {
        translateModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Reset selections
        selectedTranslateLang = '';
        selectedHostVoice = '';
        selectedGuestVoice = '';
        
        const langSelect = document.getElementById('translateLangSelect');
        if (langSelect) langSelect.value = '';
        
        const voiceSection = document.getElementById('voiceSelectionSection');
        if (voiceSection) voiceSection.style.display = 'none';
        
        const translateBtn = document.getElementById('translateBtn');
        if (translateBtn) {
            translateBtn.disabled = true;
            translateBtn.style.opacity = '0.5';
        }
        
        const warningDiv = document.getElementById('translateWarning');
        if (warningDiv) warningDiv.style.display = 'none';
        
        const progressDiv = document.getElementById('translateProgress');
        if (progressDiv) progressDiv.style.display = 'none';
        
        // Reload languages
        loadTranslateLanguages();
        
        L('🌐 Opening translate modal');
    } else {
        console.error('Failed to create translate modal');
        alert('Could not open translation modal. Please refresh the page.');
    }
};

// ========== TOGGLE ACTION MENU ==========
function toggleActionMenu(event) {
    event.stopPropagation();
    const menu = document.getElementById('actionMenu');
    if (menu) {
        menu.classList.toggle('active');
        console.log('Menu toggled:', menu.classList.contains('active'));
    }
}



// ========== CANVAS UPDATE ==========
function updateCanvas(sceneId) {
    console.log('updateCanvas called with sceneId:', sceneId);
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) {
        console.log('Scene not found:', sceneId);
        return;
    }
    
    const imagePreview = document.getElementById('imagePreview');
    const videoPreview = document.getElementById('videoPreview');
    const videoPlayButton = document.getElementById('videoPlayButton');
    
    // Check if elements exist - these might not be in your HTML anymore
    if (!imagePreview || !videoPreview) {
        console.log('Preview elements not found - skipping canvas update');
        return;
    }
    
    // Show the currently selected image field
    const imageField = currentImageField || 'image_file';
    const filename = scene[imageField];
    
    console.log(`Updating canvas with field ${imageField}:`, filename);
    
    if (filename && filename.trim() !== '') {
        const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
        
        if (isVideo) {
            // It's a video
            videoPreview.src = 'podcast_videos/' + filename + '?t=' + Date.now();
            videoPreview.style.display = 'block';
            imagePreview.style.display = 'none';
            if (videoPlayButton) {
                videoPlayButton.style.display = 'flex';
                videoPlayButton.classList.remove('playing');
            }
            videoPreview.pause();
            console.log('Displaying video:', filename);
        } else {
            // It's an image
            imagePreview.src = 'podcast_images/' + filename + '?t=' + Date.now();
            imagePreview.style.display = 'block';
            videoPreview.style.display = 'none';
            if (videoPlayButton) {
                videoPlayButton.style.display = 'none';
            }
            console.log('Displaying image:', filename);
        }
    } else {
        // No file
        imagePreview.style.display = 'none';
        videoPreview.style.display = 'none';
        if (videoPlayButton) {
            videoPlayButton.style.display = 'none';
        }
        console.log('No media to display for field:', imageField);
    }
    
    // Update overlay icons to show if scene has custom settings
    updateOverlayBadges(sceneId);
}


document.addEventListener('DOMContentLoaded', function() 
{
    try {
        console.log('DOM fully loaded');
        console.log('Scenes loaded:', scenes);
        
        // ===== INITIAL SETUP =====
        
        // Apply scaling to canvas container
        const canvasContainer = document.getElementById('canvasContainer');
        if (canvasContainer) {
            canvasContainer.style.transform = `scale(${CANVAS_SCALE})`;
            canvasContainer.style.transformOrigin = 'top center';
            
            // Adjust margins to compensate for scaling
            const scalePercent = (1 - CANVAS_SCALE) * 100;
            canvasContainer.style.margin = `-${scalePercent/2}px 0`;
        }
        
        // Add development badge to show current scale
        const devBadge = document.createElement('div');
        devBadge.className = 'development-badge';
        devBadge.innerHTML = `⚙️ DEV MODE | Canvas: ${CANVAS_SCALE * 100}%`;
        document.body.appendChild(devBadge);
        
        // ===== SCENE INITIALIZATION =====
        
        // Initialize first scene if scenes exist
        if (scenes.length > 0) {
            // Set current scene to first scene
            currentSceneIndex = 0;
            currentSceneId = scenes[0].id;
            currentImageField = 'image_file';
            
            console.log('Initializing first scene:', currentSceneId);
            console.log('First scene text:', scenes[0].text_contents);
            
            // Update canvas with first scene
            updateCanvas(currentSceneId);
            updateSceneIndicator();
            
            // Load thumbnails for the first scene
            setTimeout(() => {
                loadImageThumbnails();
                loadImagePrompt('image_file');
                
                // Highlight main thumbnail
                const mainThumb = document.querySelector('[onclick="selectImageFile(\'image_file\')"] div:first-child');
                if (mainThumb) {
                    // Reset all thumb borders first
                    document.querySelectorAll('.image-thumb div:first-child').forEach(div => {
                        div.style.border = '1px solid var(--border)';
                    });
                    // Highlight main
                    mainThumb.style.border = '2px solid var(--info)';
                }
            }, 300);
        } else {
            console.log('No scenes found');
            document.getElementById('sceneIndicator').innerText = '0/0';
        }
        
        // ===== UI EVENT LISTENERS =====
        
        // Speed slider sync
        const globalSpeed = document.getElementById('globalCaptionSpeed');
        if (globalSpeed) {
            globalSpeed.addEventListener('input', function(e) {
                document.getElementById('globalSpeedValue').innerText = e.target.value;
            });
        }
        
        const sceneSpeed = document.getElementById('sceneCaptionSpeed');
        if (sceneSpeed) {
            sceneSpeed.addEventListener('input', function(e) {
                document.getElementById('sceneSpeedValue').innerText = e.target.value + 'x';
            });
        }
        
        const audioVolume = document.getElementById('sceneAudioVolume');
        if (audioVolume) {
            audioVolume.addEventListener('input', function(e) {
                document.getElementById('audioVolumeValue').innerText = e.target.value + '%';
            });
        }
        
        // ===== NEW IMAGE BOX UPLOAD HANDLER =====
        
        // File upload handler for logo
        const logoUpload = document.getElementById('sceneLogoUpload');
        if (logoUpload) {
            logoUpload.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    document.getElementById('logoFileName').innerText = e.target.files[0].name;
                }
            });
        }
        
        // NEW IMAGE BOX UPLOAD HANDLER
        const newImageBoxUpload = document.getElementById('newImageBoxUpload');
        if (newImageBoxUpload) {
            newImageBoxUpload.addEventListener('change', async function(e) {
                if (!this.files || this.files.length === 0) return;
                
                const file = this.files[0];
                
                // Validate file type
                if (!file.type.startsWith('image/')) {
                    alert('Please select an image file');
                    return;
                }
                
                // Validate size (max 10MB)
                if (file.size > 10 * 1024 * 1024) {
                    alert('Image must be less than 10MB');
                    return;
                }
                
                L(`📤 Uploading new sticker: ${file.name} (${(file.size/1024).toFixed(2)} KB)`);
                
                const formData = new FormData();
                formData.append('ajax_action', 'upload_sticker_image');  // Use the new handler
                formData.append('image', file);
                formData.append('scene_id', currentSceneId);
                
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const text = await response.text();
                    console.log('Upload response:', text);
                    
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch(e) {
                        console.error('Failed to parse JSON:', text.substring(0, 500));
                        throw new Error('Server returned invalid JSON. Check console for details.');
                    }
                    
                    if (!data.success) {
                        throw new Error(data.message || 'Upload failed');
                    }
                    
                    L(`✅ Sticker uploaded: ${data.image_name} to podcast_stickers/`);
                    
                    // Create new caption with this image
                    await createNewImageBox(data.image_name);
                    
                } catch(error) {
                    console.error('Upload error:', error);
                    alert('Upload failed: ' + error.message);
                    L(`❌ Upload failed: ${error.message}`);
                }
                
                this.value = '';
            });
        }
        
        const menu = document.getElementById('actionMenu');
        if (menu) {
            menu.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }
        
        const imageCaptionUpload = document.getElementById('imageCaptionUpload');
        if (imageCaptionUpload) {
            imageCaptionUpload.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    document.getElementById('imageCaptionFileName').innerText = e.target.files[0].name;
                }
            });
        }
        
        // ===== MEDIA MODAL EVENT LISTENERS =====
        
        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMediaLib();
            }
        });
        
        // Click outside modal to close
        const mediaModal = document.getElementById('mediaLibModal');
        if (mediaModal) {
            mediaModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeMediaLib();
                }
            });
        }
        
        // ===== PROMPT AUTO-SAVE =====
        
        const imagePrompt = document.getElementById('imagePrompt');
        if (imagePrompt) {
            let saveTimeout;
            imagePrompt.addEventListener('input', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    if (currentSceneId && currentImageField) {
                        savePromptToDB(currentSceneId, currentImageField);
                    }
                }, 1000);
            });
        }
        
        // ===== WINDOW RESIZE HANDLER =====
        
        window.addEventListener('resize', function() {
            if (canvasContainer) {
                canvasContainer.style.transform = `scale(${CANVAS_SCALE})`;
            }
        });
        
        // ===== MUSIC UPLOAD HANDLER =====
        
        const musicInput = document.getElementById('musicUploadInput');
        if (musicInput) {
            musicInput.addEventListener('change', async function(e) {
                if (!this.files || this.files.length === 0) return;
                
                const file = this.files[0];
                
                L('📤 Uploading background music: ' + file.name);
                
                const formData = new FormData();
                formData.append('ajax_action', 'upload_podcast_music');
                formData.append('podcast_id', <?= $url_podcast_id ?>);
                formData.append('music_file', file);
                
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const text = await response.text();
                    console.log('Music upload response:', text);
                    
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch(e) {
                        console.error('Failed to parse JSON:', text.substring(0, 200));
                        throw new Error('Invalid server response');
                    }
                    
                    if (data.success) {
                        L('✅ Music uploaded successfully: ' + data.filename);
                        podcastMusicFile = data.filename;
                        updateMusicDisplay();
                        
                        // Show success popup
                        alert(`✅ Background music uploaded successfully!\n\nFilename: ${data.filename}`);
                    } else {
                        L('❌ Upload failed: ' + data.message);
                        alert('Upload failed: ' + data.message);
                    }
                } catch (err) {
                    L('❌ Upload error: ' + err.message);
                    alert('Upload error: ' + err.message);
                }
                
                this.value = '';
            });
        }
        
        // ===== FABRIC CANVAS INITIALIZATION =====
        
        // Initialize Fabric canvas after a short delay
        setTimeout(() => {
            console.log('Initializing Fabric canvas...');
            initFabricCanvas();
        }, 1000);
        
        // ===== TRANSLATE MODAL INITIALIZATION =====
        
        setTimeout(() => {
            if (!document.getElementById('translateModal')) {
                initTranslateModal();
                if (translateModal) {
                    translateModal.style.display = 'none';
                }
            }
        }, 1500);
        
        console.log('Initialization complete');
        
    } catch (error) {
        console.error('Error in DOMContentLoaded:', error);
        console.error('Error stack:', error.stack);
    }
});

// -------------------------------- translation end
// ========== OVERRIDE FUNCTIONS ==========
// Save original functions


// Override openSceneSettings
window.openSceneSettings = function(type) {
    if (typeof originalOpenSceneSettings === 'function') {
        originalOpenSceneSettings(type);
    }
    
    if (type === 'audio') {
        loadAudioForScene(currentSceneId);
        
        // Update sequence number
        const seqNoSpan = document.getElementById('currentSceneSeqNoAudio');
        if (seqNoSpan) {
            const scene = scenes.find(s => s.id == currentSceneId);
            seqNoSpan.innerText = scene?.seq_no || (currentSceneIndex + 1);
        }
    }
};

// Override navigateScene
window.navigateScene = function(direction) {
    if (typeof originalNavigateScene === 'function') {
        originalNavigateScene(direction);
    }
    
    // If audio panel is open, refresh it
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    if (settingsPanel && settingsPanel.classList.contains('active') && window.currentSettingType === 'audio') {
        loadAudioForScene(currentSceneId);
    }
};

// ========== OVERRIDE FUNCTIONS FOR FABRIC INTEGRATION ==========
// Save original functions only if they haven't been saved yet
if (typeof window.originalOpenSceneSettings === 'undefined') {
    window.originalOpenSceneSettings = window.openSceneSettings;
}

if (typeof window.originalNavigateScene === 'undefined') {
    window.originalNavigateScene = window.navigateScene;
}

// Override openSceneSettings
window.openSceneSettings = function(type) {
    // Initialize Fabric if needed
    if (typeof fabricCanvas === 'undefined' || !fabricCanvas) {
        if (typeof initFabricCanvas === 'function') {
            initFabricCanvas();
        }
    }
    
    if (typeof window.originalOpenSceneSettings === 'function') {
        window.originalOpenSceneSettings(type);
    }
    
    if (type === 'audio') {
        loadAudioForScene(currentSceneId);
        
        // Update sequence number
        const seqNoSpan = document.getElementById('currentSceneSeqNoAudio');
        if (seqNoSpan) {
            const scene = scenes.find(s => s.id == currentSceneId);
            seqNoSpan.innerText = scene?.seq_no || (currentSceneIndex + 1);
        }
    }
};

// Override navigateScene
window.navigateScene = async function(direction) {
    if (typeof window.originalNavigateScene === 'function') {
        await window.originalNavigateScene(direction);
    }
    
    // If Fabric canvas exists, update it
    if (typeof fabricCanvas !== 'undefined' && fabricCanvas) {
        if (typeof loadCurrentSceneToFabric === 'function') {
            await loadCurrentSceneToFabric();
        }
    }
};

















function showPositionOptions() {
    alert('Position options would appear here');
}


// Text style toggles (already have these functions)
// Toggle text style (BOLD, ITALIC, UNDERLINE)
async function toggleTextStyle(style) {
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Toggling text style:', style);
    
    const updates = {};
    const captionId = captionText.captionId;
    
    if (style === 'bold') {
        const newValue = captionText.fontWeight === 'bold' ? 'normal' : 'bold';
        captionText.set('fontWeight', newValue);
        updates.fontweight = newValue;
        console.log('Bold set to:', newValue);
    } 
    else if (style === 'italic') {
        const newValue = captionText.fontStyle === 'italic' ? 'normal' : 'italic';
        captionText.set('fontStyle', newValue);
        updates.fontstyle = newValue;
        console.log('Italic set to:', newValue);
    } 
    else if (style === 'underline') {
        const newValue = !captionText.underline;
        captionText.set('underline', newValue);
        updates.underline = newValue ? 1 : 0;
        console.log('Underline set to:', newValue);
    } 
    else if (style === 'linethrough') {
        const newValue = !captionText.linethrough;
        captionText.set('linethrough', newValue);
        updates.linethrough = newValue ? 1 : 0;
        console.log('Strike set to:', newValue);
    }
    
    fabricCanvas.renderAll();
    
    // Update the style indicators
    updateStyleIndicators();
    
    // Save to database if we have a caption ID
    if (captionId && Object.keys(updates).length > 0) {
        await saveCaptionToDatabase(captionId, updates);
        console.log(`✅ Text style saved:`, updates);
    }
}


// ========== FIXED: MASTER CLICK HANDLER ==========
document.addEventListener('click', function(event) {
    const target = event.target;
    
    // 1. Handle action menu (three-dot menu)
    const menu = document.getElementById('actionMenu');
    const threeDot = document.querySelector('.three-dot-menu');
    if (menu && threeDot) {
        if (!threeDot.contains(target) && !menu.contains(target)) {
            menu.classList.remove('active');
        }
    }
    
    // 2. Handle emoji dropdowns
    if (!target.closest('.emoji-dropdown-container')) {
        document.querySelectorAll('.emoji-dropdown').forEach(d => d.style.display = 'none');
    }
    
    // 3. Handle icon sets (text icons, typography icons, audio icons)
    const textIcons = document.getElementById('textIcons');
    const audioIcons = document.getElementById('audioIcons');
    const primaryIcons = document.getElementById('primaryIcons');
    
    // Check if click is on any overlay icon
    const clickedOnIcon = target.closest('.overlay-icon');
    
    // 4. Handle ALL overlays and panels
    // List all overlay/panel IDs that should NOT close when clicked inside
    const overlayIds = [
        'fontFamilyPanel',
        'fontSizePanel',
        'fontSelectorOverlay',
        'fontColorOverlay',
        'bgColorPanel',
        'stylePanel',
        'effectsPanel',
        'alignmentPanel',
        'positionPanel',
        'animationPanel',
        'imageSourcePanel',
        'currentSceneAudioOverlay',
        'podcastAudioOverlay',
        'imageSelectorPanel'
    ];
    
    // Check if click is inside ANY of these overlays
    let clickedInsideOverlay = false;
    for (let id of overlayIds) {
        const overlay = document.getElementById(id);
        if (overlay && (overlay.contains(target) || target === overlay)) {
            clickedInsideOverlay = true;
            break;
        }
    }
    
    // Also check for any element with class 'overlay-panel'
    if (!clickedInsideOverlay && target.closest('.overlay-panel')) {
        clickedInsideOverlay = true;
    }
    
    // If click is inside an overlay, DO NOTHING - let it stay open
    if (clickedInsideOverlay) {
        console.log('Click inside overlay - keeping overlay open');
        event.stopPropagation(); // Prevent any further handling
        return;
    }
    
    // If click is not inside any overlay and not on an icon, close all overlays
    if (!clickedInsideOverlay && !clickedOnIcon) {
        console.log('Click outside overlay and icons - closing overlays and restoring icons');
        
        // Close all overlays first
        closeAllOverlays();
        
        // THEN restore icons based on current mode
        restoreIconsBasedOnMode();
    }
    
    // 5. Special handling for hiding icon sets when clicking outside
    if (!clickedOnIcon) {
        // Hide text icons if they're visible and click is outside
        if (textIcons && textIcons.style.display === 'flex' && !target.closest('#textIcons')) {
            // Don't hide if we're in text mode and an overlay is open
            const anyOverlayOpen = checkAnyOverlayOpen();
            if (!anyOverlayOpen) {
                hideTextIcons();
            }
        }
        
        // Hide audio icons if they're visible and click is outside
        if (audioIcons && audioIcons.style.display === 'flex' && !target.closest('#audioIcons')) {
            const anyOverlayOpen = checkAnyOverlayOpen();
            if (!anyOverlayOpen) {
                hideAudioIcons();
            }
        }
    }
});

// ========== NEW: CHECK IF ANY OVERLAY IS OPEN ==========
function checkAnyOverlayOpen() {
    const overlays = [
        'fontFamilyPanel', 'fontSizePanel', 'fontColorOverlay', 'bgColorPanel',
        'stylePanel', 'effectsPanel', 'alignmentPanel', 'positionPanel',
        'animationPanel', 'imageSourcePanel', 'fontSelectorOverlay',
        'currentSceneAudioOverlay', 'podcastAudioOverlay', 'imageSelectorPanel'
    ];
    
    for (let id of overlays) {
        const el = document.getElementById(id);
        if (el && (el.style.display === 'block' || el.style.display === 'flex')) {
            return true;
        }
    }
    
    // Check for overlay-panel class
    const panels = document.querySelectorAll('.overlay-panel');
    for (let panel of panels) {
        if (panel.style.display === 'block' || panel.style.display === 'flex') {
            return true;
        }
    }
    
    return false;
}

// ========== NEW: RESTORE ICONS BASED ON CURRENT MODE ==========
function restoreIconsBasedOnMode() {
    console.log('Restoring icons based on mode:', window.currentOverlayMode);
    
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    const audioIcons = document.getElementById('audioIcons');
    
    // Restore based on stored mode
    if (window.currentOverlayMode === 'text') {
        if (textIcons) {
            textIcons.style.display = 'flex';
            console.log('✅ Restored text icons');
        }
        if (primaryIcons) primaryIcons.style.display = 'none';
        if (audioIcons) audioIcons.style.display = 'none';
    } 
    else if (window.currentOverlayMode === 'audio') {
        if (audioIcons) {
            audioIcons.style.display = 'flex';
            console.log('✅ Restored audio icons');
        }
        if (primaryIcons) primaryIcons.style.display = 'none';
        if (textIcons) textIcons.style.display = 'none';
    } 
    else {
        if (primaryIcons) {
            primaryIcons.style.display = 'flex';
            console.log('✅ Restored primary icons');
        }
        if (textIcons) textIcons.style.display = 'none';
        if (audioIcons) audioIcons.style.display = 'none';
    }
}

// ========== FIXED: CLOSE ALL OVERLAYS ==========
function closeAllOverlays() {
    console.log('Closing all overlays');
    
    const overlays = [
        'fontFamilyPanel',
        'fontSizePanel',
        'fontSelectorOverlay',
        'fontColorOverlay',
        'bgColorPanel',
        'stylePanel',
        'effectsPanel',
        'alignmentPanel',
        'positionPanel',
        'animationPanel',
        'imageSourcePanel',
        'currentSceneAudioOverlay',
        'podcastAudioOverlay',
        'imageSelectorPanel'
    ];
    
    overlays.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = 'none';
            el.classList.remove('active');
        }
    });
    
    // Also close any panels with class overlay-panel
    document.querySelectorAll('.overlay-panel').forEach(el => {
        el.style.display = 'none';
        el.classList.remove('active');
    });
    
    // DON'T restore icons here - let restoreIconsBasedOnMode handle it
}

// ========== FIXED: CLOSE OVERLAY FUNCTION ==========
function closeOverlay(overlayId) {
    console.log(`🔒 Closing overlay: ${overlayId}`);
    
    const overlay = document.getElementById(overlayId);
    if (overlay) {
        overlay.style.display = 'none';
        overlay.classList.remove('active');
    }
    
    // Check if any other overlays are still open
    const anyOtherOpen = checkAnyOverlayOpen();
    
    // If no other overlays are open, restore icons
    if (!anyOtherOpen) {
        console.log('No other overlays open, restoring icons');
        restoreIconsBasedOnMode();
    }
}

// ========== UPDATE SHOWFONTFAMILYPANEL TO STORE MODE ==========
function showFontFamilyPanel(event) {
    console.log('🎯 Opening font family panel');
    
    // Store current mode (text mode)
    window.currentOverlayMode = 'text';
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE THE SECONDARY ICONS when overlay opens
    const textIcons = document.getElementById('textIcons');
    if (textIcons) {
        textIcons.style.display = 'none';
        console.log('✅ Text icons hidden');
    }
    
    const overlay = document.getElementById('fontFamilyPanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ fontFamilyPanel not found!');
        return;
    }
    
    // Highlight the currently selected font
    if (captionText) {
        const currentFont = captionText.fontFamily;
        highlightCurrentFont(currentFont);
    }
    
    // Position the overlay near the icon
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 280;
        const overlayHeight = 400;
        
        // Position 50px to the right of the icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Boundary checks
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    // Prevent overlay from closing when clicking inside
    overlay.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}
// ========== FIXED: SET FONT FAMILY (DOESN'T CLOSE) ==========
function setFontFamily(font) {
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting font family to:', font);
    
    // Update the text object
    captionText.set('fontFamily', font);
    fabricCanvas.renderAll();
    
    // Update highlight
    highlightCurrentFont(font);
    
    // Save to database
    if (captionText.captionId) {
        saveCaptionToDatabase(captionText.captionId, { fontfamily: font });
    }
    
    // DON'T close the panel - user can select multiple fonts
    // The panel stays open until user clicks outside or back arrow
}

// ========== FIXED: HIGHLIGHT CURRENT FONT ==========
function highlightCurrentFont(fontFamily) {
    const fontOptions = document.querySelectorAll('#fontFamilyPanel .font-option');
    
    fontOptions.forEach(option => {
        const fontName = option.textContent;
        // Check if this option matches the current font
        if (fontFamily.includes(fontName) || fontName.includes(fontFamily)) {
            option.style.background = '#0f2a44';
            option.style.color = 'white';
            option.style.fontWeight = 'bold';
        } else {
            option.style.background = '';
            option.style.color = '';
            option.style.fontWeight = '';
        }
    });
}




// Add a helper function to check if any overlay is open
function isAnyOverlayOpen() {
    const overlays = [
        'fontSelectorOverlay',
        'fontSizeOverlay',
        'fontColorOverlay',
        'bgColorPanel',
        'stylePanel',
        'effectsPanel',
        'alignmentPanel',
        'positionPanel',
        'animationPanel',
        'imageSourcePanel',
        'currentSceneAudioOverlay',
        'podcastAudioOverlay',
        'imageSelectorPanel'
    ];
    
    for (let id of overlays) {
        const el = document.getElementById(id);
        if (el && (el.style.display === 'block' || el.style.display === 'flex')) {
            return true;
        }
    }
    
    // Check for overlay-panel class
    const panels = document.querySelectorAll('.overlay-panel');
    for (let panel of panels) {
        if (panel.style.display === 'block' || panel.style.display === 'flex') {
            return true;
        }
    }
    
    return false;
}

// Also handle Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const typographyIcons = document.getElementById('typographyIcons');
        if (typographyIcons.style.display === 'flex') {
            hideTypographyIcons();
        }
    }
});

// Show typography secondary icons - accepts optional parameter


// Hide typography icons and show primary
// Hide text icons and show primary
function hideTypographyIcons() {
    console.log('Exiting text editing mode');
    
    // Show primary icons
    const primaryIcons = document.getElementById('primaryIcons');
    if (primaryIcons) {
        primaryIcons.style.display = 'flex';
    }
    
    // Hide text icons
    const textIcons = document.getElementById('textIcons');
    if (textIcons) {
        textIcons.style.display = 'none';
    }
    
    // CLOSE ALL OPEN OVERLAYS - Call this twice to be sure
    closeAllOverlays();
    
    // Directly close stylePanel as a backup
    const stylePanel = document.getElementById('stylePanel');
    if (stylePanel) {
        console.log('Directly closing stylePanel');
        stylePanel.style.display = 'none';
    }
    
    // Close the font selector overlay if it's open
    const fontSelector = document.getElementById('fontSelectorOverlay');
    if (fontSelector) {
        fontSelector.style.display = 'none';
        fontSelector.classList.remove('active');
    }
    
    // Optionally disable selection mode
    if (typeof setSelectionMode === 'function') {
        console.log('Disabling selection mode');
        setSelectionMode(false);
    }
    
    // Hide delete icon
    const deleteIcon = document.getElementById('overlay-delete');
    if (deleteIcon) {
        deleteIcon.style.display = 'none';
    }
}


// Show font size picker




// Close pickers
function closeFontSizePicker() {
    document.getElementById('fontSizeOverlay').style.display = 'none';
    document.getElementById('fontSizeOverlay').classList.remove('active');
}

function closeFontColorPicker() {
    document.getElementById('fontColorOverlay').style.display = 'none';
    document.getElementById('fontColorOverlay').classList.remove('active');
}



function showAlignmentOptions() {
    closeAllOverlays();
    document.getElementById('alignmentPanel').style.display = 'block';
}

function showPositionOptions() {
    closeAllOverlays();
    document.getElementById('positionPanel').style.display = 'block';
}

function showAnimationOptions() {
    closeAllOverlays();
    document.getElementById('animationPanel').style.display = 'block';
    if (captionText && captionText.animationSpeed) {
        document.getElementById('animSpeedSlider').value = captionText.animationSpeed;
        document.getElementById('animSpeedDisplay').innerText = captionText.animationSpeed + 'x';
    }
}




// Show color picker
// Track which color mode we're in
let colorPickerMode = 'text'; // 'text' or 'background'

// Show text color picker (🎨 icon)
function showTextColorOptions() {
    console.log('Opening text color picker');
    closeAllOverlays();
    
    // Set mode to text
    colorPickerMode = 'text';
    
    const overlay = document.getElementById('fontColorOverlay');
    const icon = event?.currentTarget;
    
    // Build the overlay content if it's empty
    buildColorOverlayContent();
    
    // Set current values from selected caption
    if (captionText) {
        const currentColor = captionText.fill || '#ffffff';
        setFontColorValue(currentColor);
        
        // Extract opacity if it's rgba
        let currentOpacity = 100;
        if (currentColor.startsWith('rgba')) {
            const match = currentColor.match(/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)/);
            if (match) {
                currentOpacity = Math.round(parseFloat(match[4]) * 100);
            }
        }
        setOpacityValue(currentOpacity);
    }
    
    // Position the overlay
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 280;
        const overlayHeight = 400;
        
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
}

// Build color overlay content
function buildColorOverlayContent() {
    const overlay = document.getElementById('fontColorOverlay');
    
    // Define color swatches
    const COLOR_SWATCHES = [
        "#ffffff", "#000000", "#FF3B30", "#FF9500", "#FFCC00", 
        "#34C759", "#00C7BE", "#007AFF", "#5856D6", "#AF52DE",
        "#FF2D55", "#FF6B6B", "#A8E6CF", "#3D5A80", "#E07A5F"
    ];
    
    // Build swatches HTML
    let swatchesHtml = '';
    COLOR_SWATCHES.forEach(color => {
        swatchesHtml += `<button class="swatch" data-color="${color}" style="background: ${color};" onclick="setFontColor('${color}')" title="${color}"></button>`;
    });
    
    // Add custom color picker
		swatchesHtml += `
		<label class="swatch swatch-custom" title="Custom color">
			<input type="color" value="#8b5cf6" onchange="setBgColor(this.value)">
		</label>
	`;
    
    // Set the overlay content
    overlay.innerHTML = `
        <div class="picker-card">
            <div class="card-header">
                <span class="back-icon-small" onclick="closeFontColorPicker()">←</span>
                Text Color
            </div>
            <div class="card-body">
                <div class="color-swatches">
                    ${swatchesHtml}
                </div>
                
                <div class="divider"></div>
                
                <div class="hex-row">
                    <div class="hex-preview" id="hexPreview" style="background: #ffffff;"></div>
                    <input class="hex-input" id="hexInput" type="text" placeholder="#ffffff" maxlength="7" value="#ffffff" oninput="handleHexInput(this.value)">
                </div>
                
                <div class="opacity-row">
                    <span class="opacity-label">Opacity</span>
                    <input class="opacity-slider" type="range" id="opacitySlider" min="0" max="100" value="100" oninput="handleOpacityChange(this.value)">
                    <span class="opacity-value" id="opacityValue">100%</span>
                </div>
            </div>
        </div>
    `;
}

// Handle hex input
function handleHexInput(value) {
    let val = value.trim();
    if (!val.startsWith('#')) val = '#' + val;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        setFontColor(val);
    }
}

// Handle opacity change
function handleOpacityChange(value) {
    opacity = parseInt(value);
    document.getElementById('opacityValue').innerText = opacity + '%';
    applyFontStyle();
}
// Show background color picker (🖌️ icon)


// Show background color panel
// Show background color panel
function showBgColorOptions(event) {
    console.log('🎯 showBgColorOptions called');
    
    // First, make sure a caption is selected
    if (!captionText || !fabricCanvas.getActiveObject()) {
        console.log('No caption selected, attempting to auto-select...');
        
        const objects = fabricCanvas.getObjects();
        const textObj = objects.find(obj => obj.type === 'textbox' || obj.type === 'text');
        
        if (textObj) {
            fabricCanvas.setActiveObject(textObj);
            fabricCanvas.renderAll();
            captionText = textObj;
            console.log('✅ Auto-selected caption:', textObj.captionId);
        } else {
            console.warn('⚠️ No text captions found on canvas');
            alert('Please select a text caption first');
            return;
        }
    }
    
    // Store current mode - THIS IS CRITICAL
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    const audioIcons = document.getElementById('audioIcons');
    
    // Store which mode we're in
    window.lastMode = 'primary';
    if (primaryIcons && primaryIcons.style.display === 'none') {
        if (textIcons && textIcons.style.display === 'flex') {
            window.lastMode = 'text';
            console.log('📝 Last mode set to: text (from bg panel)');
        } else if (audioIcons && audioIcons.style.display === 'flex') {
            window.lastMode = 'audio';
            console.log('🔊 Last mode set to: audio (from bg panel)');
        }
    } else {
        console.log('📱 Last mode remains: primary');
    }
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE SECONDARY ICONS
    if (textIcons) {
        textIcons.style.display = 'none';
        console.log('Text icons hidden');
    }
    if (audioIcons) {
        audioIcons.style.display = 'none';
    }
    
    // Rest of your function...
    const overlay = document.getElementById('bgColorPanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ bgColorPanel not found!');
        return;
    }
    
    // Set current values from selected caption
    if (captionText) {
        const currentBgColor = captionText.backgroundColor && captionText.backgroundColor !== 'transparent' 
            ? captionText.backgroundColor 
            : '#000000';
        
        const bgColorPicker = document.getElementById('bgColorPicker');
        if (bgColorPicker) bgColorPicker.value = currentBgColor;
        
        const enableCheckbox = document.getElementById('enableBgCheckbox');
        if (enableCheckbox) {
            enableCheckbox.checked = captionText.backgroundColor !== 'transparent';
        }
    }
    
    // Position the overlay
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 280;
        const overlayHeight = 400;
        
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    // IMPORTANT: Prevent overlay from closing when clicking inside
    overlay.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// Function to show ALL secondary icons (both text and audio)
function showAllSecondaryIcons() {
    console.log('🔧 Showing ALL secondary icons');
    
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    const audioIcons = document.getElementById('audioIcons');
    
    // Hide primary icons
    if (primaryIcons) {
        primaryIcons.style.display = 'none';
    }
    
    // Show BOTH secondary icon sets
    if (textIcons) {
        textIcons.style.display = 'flex';
        console.log('✅ Text icons shown');
    }
    
    if (audioIcons) {
        audioIcons.style.display = 'flex';
        console.log('✅ Audio icons shown');
    }
}


// Set background color - AUTO-ENABLE CHECKBOX
async function setBgColor(color) {
    console.log('Setting background color to:', color);
    
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    // Update color picker
    const colorPicker = document.getElementById('bgColorPicker');
    if (colorPicker) colorPicker.value = color;
    
    // AUTO-ENABLE the checkbox
    const enableCheckbox = document.getElementById('enableBgCheckbox');
    if (enableCheckbox && !enableCheckbox.checked) {
        enableCheckbox.checked = true;
        console.log('✅ Checkbox auto-enabled');
    }
    
    // Update canvas with new background color
    captionText.set('backgroundColor', color);
    fabricCanvas.renderAll();
    
    // Save to database
    if (captionText.captionId) {
        try {
            await saveCaptionToDatabase(captionText.captionId, { 
                bg_color: color,
                bg_enabled: 1  // Always set to 1 when color is selected
            });
            console.log('✅ Background saved to database');
        } catch(e) {
            console.error('Error saving background:', e);
        }
    }
}



// Toggle background enable - FIXED VERSION
async function toggleBgEnable(checked) {
    console.log('Toggling background enable:', checked);
    
    if (!captionText) {
        console.warn('No caption selected');
        
        // Uncheck if no caption selected
        const checkbox = document.getElementById('enableBgCheckbox');
        if (checkbox) checkbox.checked = false;
        return;
    }
    
    // Get current background color from picker
    const bgColor = document.getElementById('bgColorPicker').value;
    
    // Update canvas
    if (checked) {
        captionText.set('backgroundColor', bgColor);
        console.log('Background enabled with color:', bgColor);
    } else {
        captionText.set('backgroundColor', 'transparent');
        console.log('Background disabled');
    }
    
    fabricCanvas.renderAll();
    
    // Save to database
    if (captionText.captionId) {
        try {
            await saveCaptionToDatabase(captionText.captionId, { 
                bg_enabled: checked ? 1 : 0,
                bg_color: bgColor
            });
            console.log('✅ Background toggle saved to database');
        } catch(e) {
            console.error('Error saving background toggle:', e);
        }
    }
}



// Update the applyFontStyle function to handle both text and background
function applyFontStyle() {
    if (!captionText) {
        console.log('No caption selected');
        return;
    }
    
    const { r, g, b } = hexToRgb(fontColor);
    const alpha = opacity / 100;
    const cssColor = alpha < 1 
        ? `rgba(${r},${g},${b},${alpha.toFixed(2)})` 
        : fontColor;
    
    if (colorPickerMode === 'text') {
        // Apply to text color
        captionText.set('fill', cssColor);
        
        // Save to database
        if (typeof saveCaptionToDatabase === 'function') {
            saveCaptionToDatabase(captionText.captionId, { 
                fontcolor: fontColor,
                opacity: opacity
            });
        }
        console.log('Applied text color:', cssColor);
        
    } else if (colorPickerMode === 'background') {
        // Apply to background color
        captionText.set('backgroundColor', cssColor);
        
        // Enable background
        captionText.set('bg_enabled', 1);
        
        // Save to database
        if (typeof saveCaptionToDatabase === 'function') {
            saveCaptionToDatabase(captionText.captionId, { 
                bg_color: fontColor,
                bg_enabled: 1,
                bg_opacity: opacity / 100
            });
        }
        console.log('Applied background color:', cssColor);
    }
    
    fabricCanvas.renderAll();
}

// Update the setFontColor function to work with the current mode
function setFontColor(color) {
    if (!isValidHex(color)) return;
    fontColor = color.toLowerCase();
    
    // Update UI
    const hexInput = document.getElementById('hexInput');
    const hexPreview = document.getElementById('hexPreview');
    if (hexInput && document.activeElement !== hexInput) {
        hexInput.value = fontColor;
    }
    if (hexPreview) hexPreview.style.background = fontColor;
    
    // Update swatches
    document.querySelectorAll(".swatch:not(.swatch-custom)").forEach(sw => {
        sw.classList.toggle("active", sw.dataset.color === fontColor);
    });
    
    applyFontStyle();
}

// Update setOpacity to work with current mode
function setOpacity(value) {
    opacity = Math.min(100, Math.max(0, value));
    
    const opacityValue = document.getElementById('opacityValue');
    const opacitySlider = document.getElementById('opacitySlider');
    if (opacityValue) opacityValue.textContent = opacity + "%";
    if (opacitySlider) opacitySlider.value = opacity;
    
    applyFontStyle();
}

// Update setFontColorValue to work with current mode
function setFontColorValue(color) {
    fontColor = color;
    const hexInput = document.getElementById('hexInput');
    const hexPreview = document.getElementById('hexPreview');
    if (hexInput) hexInput.value = color;
    if (hexPreview) hexPreview.style.background = color;
}

// Update closeFontColorPicker to reset mode
function closeFontColorPicker() {
    document.getElementById('fontColorOverlay').style.display = 'none';
    document.getElementById('fontColorOverlay').classList.remove('active');
    colorPickerMode = 'text'; // Reset to default
}

// Close pickers
function closeFontSizePicker() {
    document.getElementById('fontSizeOverlay').style.display = 'none';
    document.getElementById('fontSizeOverlay').classList.remove('active');
}

function closeFontColorPicker() {
    document.getElementById('fontColorOverlay').style.display = 'none';
    document.getElementById('fontColorOverlay').classList.remove('active');
}



// Preview functions
function previewFontSize(value) {
    document.getElementById('fontSizeDisplay').innerText = value + 'px';
    if (captionText) {
        captionText.set('fontSize', parseInt(value));
        fabricCanvas.renderAll();
    }
}

function previewTextColor(value) {
    if (captionText) {
        captionText.set('fill', value);
        fabricCanvas.renderAll();
    }
}
// Preview background color - AUTO-ENABLE CHECKBOX
function previewBgColor(value) {
    if (!captionText) return;
    
    // Auto-enable the checkbox during preview
    const enableCheckbox = document.getElementById('enableBgCheckbox');
    if (enableCheckbox && !enableCheckbox.checked) {
        enableCheckbox.checked = true;
    }
    
    // Apply preview color
    captionText.set('backgroundColor', value);
    fabricCanvas.renderAll();
}





function setTextColor(color) {
    if (captionText) {
        captionText.set('fill', color);
        fabricCanvas.renderAll();
        saveCaptionToDatabase(captionText.captionId, { fontcolor: color });
    }
    closeAllOverlays();
}




// Set text alignment
async function setTextAlignment(alignment) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting text alignment to:', alignment);
    
    // Update the text object
    captionText.set('textAlign', alignment);
    fabricCanvas.renderAll();
    
    // Update alignment indicators
    updateAlignmentIndicators();
    
    // Save to database
    await saveCaptionToDatabase(captionText.captionId, { text_align: alignment });
    
    // Optional: Close overlay after selection
    // closeOverlay('alignmentPanel');
}

function setTextPosition(pos) {
    if (captionText && fabricCanvas) {
        let top;
        const canvasHeight = fabricCanvas.height;
        const textHeight = captionText.height || 100;
        
        if (pos === 'top') top = 50;
        else if (pos === 'center') top = (canvasHeight / 2) - (textHeight / 2);
        else top = canvasHeight - textHeight - 50;
        
        captionText.set('top', Math.round(top));
        fabricCanvas.renderAll();
        saveCaptionToDatabase(captionText.captionId, { 
            position_y: Math.round(top),
            caption_position: pos 
        });
    }
    closeAllOverlays();
}





// Escape key handler
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('typographyIcons').style.display === 'flex') {
            hideTypographyIcons();
        }
        closeAllOverlays();
    }
});


// ========== FORCE SELECTION MODE WITH HANDLES ==========
function forceSelectionMode() {
    console.log('🔧 forceSelectionMode called');
    
    if (!fabricCanvas) return;
    
    // Make sure selection mode is enabled
    isSelectionMode = true;
    fabricCanvas.selection = false;
    fabricCanvas.skipTargetFind = false;
    
    // Update ALL objects with captionId
    fabricCanvas.getObjects().forEach(obj => {
        if (obj.captionId) {
            obj.set({
                selectable: true,
                evented: true,
                hasControls: true,
                hasBorders: true,
                cornerSize: 15,
                transparentCorners: false,
                borderColor: '#00ff00',
                cornerColor: '#ff0000'
            });
            obj.setCoords();
        }
    });
    
    // Get the active object
    const activeObject = fabricCanvas.getActiveObject();
    
    if (activeObject && activeObject.captionId) {
        console.log('📦 Active object found:', activeObject.captionId);
        
        // Force enable all controls
        activeObject.set({
            hasControls: true,
            hasBorders: true,
            cornerSize: 15,
            transparentCorners: false
        });
        
        // CRITICAL: Update coordinates
        activeObject.setCoords();
        
        console.log('✅ Controls enabled for caption:', activeObject.captionId);
    } else {
        console.log('⚠️ No active object with captionId found');
        
        // Try to find and select the first caption
        const firstCaption = fabricCanvas.getObjects().find(obj => obj.captionId);
        
        if (firstCaption) {
            console.log('🔍 Found caption to select:', firstCaption.captionId);
            
            firstCaption.set({
                selectable: true,
                evented: true,
                hasControls: true,
                hasBorders: true,
                cornerSize: 15,
                transparentCorners: false
            });
            
            fabricCanvas.setActiveObject(firstCaption);
            firstCaption.setCoords();
            
            // Set global captionText
            captionText = firstCaption;
            selectedCaptionId = firstCaption.captionId;
            selectedCaptionType = firstCaption.captionType;
        }
    }
    
    fabricCanvas.renderAll();
}

// ========== DEBUG AND FIX TEXT SELECTION ==========
function debugAndFixTextSelection() {
    console.log('🔍 ===== DEBUG TEXT SELECTION =====');
    
    if (!fabricCanvas) {
        console.log('❌ fabricCanvas is null');
        return;
    }
    
    // 1. Check all text objects
    const objects = fabricCanvas.getObjects();
    console.log(`Total objects: ${objects.length}`);
    
    let textObjects = 0;
    objects.forEach((obj, i) => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            textObjects++;
            console.log(`📝 Text object ${i}:`, {
                captionId: obj.captionId,
                selectable: obj.selectable,
                hasControls: obj.hasControls,
                hasBorders: obj.hasBorders,
                evented: obj.evented,
                cornerSize: obj.cornerSize,
                left: obj.left,
                top: obj.top
            });
        }
    });
    
    if (textObjects === 0) {
        console.log('❌ No text objects found on canvas');
        return;
    }
    
    // 2. Force ALL text objects to be selectable
    console.log('🔧 FORCING all text objects to be selectable');
    objects.forEach(obj => {
        if (obj.type === 'textbox' || obj.type === 'text') {
            obj.set({
                selectable: true,
                evented: true,
                hasControls: true,
                hasBorders: true,
                lockMovementX: false,
                lockMovementY: false,
                lockScalingX: false,
                lockScalingY: false,
                cornerSize: 20,
                transparentCorners: false,
                borderColor: '#00ff00',
                cornerColor: '#ff0000'
            });
            obj.setCoords();
        }
    });
    
    // 3. Select the first text object
    const firstText = objects.find(obj => obj.type === 'textbox' || obj.type === 'text');
    if (firstText) {
        fabricCanvas.setActiveObject(firstText);
        firstText.setCoords();
        fabricCanvas.renderAll();
        
        // Update global variables
        captionText = firstText;
        selectedCaptionId = firstText.captionId;
        selectedCaptionType = firstText.captionType;
        
        console.log('✅ Selected first text object:', firstText.captionId);
    }
    
    // 4. Force canvas to recalculate
    fabricCanvas.calcOffset();
    fabricCanvas.renderAll();
    
    console.log('✅ Debug complete - handles should now be visible');
}

// ========== SIMPLIFIED SHOW TYPOGRAPHY ICONS ==========
function showTypographyIcons() {
    console.log('Text editing mode activated');

    // Show text icon bar, hide primary
    document.getElementById('primaryIcons').style.display = 'none';
    document.getElementById('textIcons').style.display = 'flex';
    const delIcon = document.getElementById('deleteIconSecondary');
    if (delIcon) delIcon.style.display = 'flex';

    // Enable selection mode
    setSelectionMode(true);

    if (fabricCanvas) {
        const upperCanvas   = fabricCanvas.upperCanvasEl;
        const iconContainer = document.querySelector('#canvasContainer .icon-container');

        // Upper canvas must receive mouse events
        if (upperCanvas) {
            upperCanvas.style.pointerEvents = 'auto';
        }

        // Icon container transparent — individual icons stay clickable
        if (iconContainer) {
            iconContainer.style.setProperty('pointer-events', 'none', 'important');
            iconContainer.querySelectorAll('.overlay-icon, .nav-arrow, .scene-indicator, button, input, select').forEach(el => {
                el.style.setProperty('pointer-events', 'auto', 'important');
            });
        }

        fabricCanvas.skipTargetFind = false;
        fabricCanvas.defaultCursor  = 'default';
        fabricCanvas.moveCursor     = 'move';
        fabricCanvas.selection      = false;

        fabricCanvas.getObjects().forEach(obj => {
            if (obj.captionId) {
                obj.set({
                    selectable:         true,
                    evented:            true,
                    hasControls:        true,
                    hasBorders:         true,
                    lockMovementX:      false,
                    lockMovementY:      false,
                    lockScalingX:       false,
                    lockScalingY:       false,
                    lockRotation:       true,
                    cornerSize:         14,
                    touchCornerSize:    22,
                    transparentCorners: false,
                    borderColor:        '#2563eb',
                    cornerColor:        '#2563eb',
                    cornerStrokeColor:  '#ffffff',
                    cornerStyle:        'circle',
                    hoverCursor:        'move',
                    moveCursor:         'move'
                });
                obj.setCoords();
            } else {
                obj.set({ selectable: false, evented: false, hoverCursor: 'default' });
            }
        });

        fabricCanvas.calcOffset();

        // Recalculate offset on scroll — handles shift when page scrolls
        window._calcOffsetOnScroll = () => { if (fabricCanvas) fabricCanvas.calcOffset(); };
        window.removeEventListener('scroll', window._calcOffsetOnScroll);
        window.addEventListener('scroll', window._calcOffsetOnScroll, { passive: true });

        // Double-click → enter text editing
        fabricCanvas.off('mouse:dblclick');
        fabricCanvas.on('mouse:dblclick', function(e) {
            if (e.target && e.target.captionId &&
                (e.target.type === 'textbox' || e.target.type === 'text')) {
                fabricCanvas.setActiveObject(e.target);
                e.target.enterEditing();
                e.target.selectAll();
                if (upperCanvas) upperCanvas.style.cursor = 'text';
                fabricCanvas.renderAll();
                console.log('✏️ Text editing started');
            }
        });

        // Restore cursor when editing exits
        fabricCanvas.off('text:editing:exited');
        fabricCanvas.on('text:editing:exited', function() {
            if (upperCanvas) upperCanvas.style.cursor = 'default';
        });

        // Single click → load settings panel
        fabricCanvas.off('mouse:up');
        fabricCanvas.on('mouse:up', function(e) {
            if (!isSelectionMode) return;
            const target = e.target;
            if (target && target.captionId) {
                captionText         = target;
                selectedCaptionId   = target.captionId;
                selectedCaptionType = target.captionType;
                const captionData   = findCaptionById(target.captionId);
                if (captionData) {
                    loadCaptionSettingsToPanel(captionData);
                    updateCaptionPanelHeader(captionData);
                    loadAnimationSettingsFromCaption(captionData);
                }
            }
        });

        // Auto-select first text caption
        const firstCaption = fabricCanvas.getObjects().find(
            obj => obj.captionId && (obj.type === 'textbox' || obj.type === 'text')
        );

        if (firstCaption) {
            fabricCanvas.setActiveObject(firstCaption);
            captionText         = firstCaption;
            selectedCaptionId   = firstCaption.captionId;
            selectedCaptionType = firstCaption.captionType;

            const captionData = findCaptionById(firstCaption.captionId);
            if (captionData) {
                loadCaptionSettingsToPanel(captionData);
                updateCaptionPanelHeader(captionData);
                loadAnimationSettingsFromCaption(captionData);
            }
            console.log('✅ Auto-selected caption:', firstCaption.captionId);
        } else {
            console.log('⚠️ No text captions on canvas');
        }

        fabricCanvas.renderAll();
    }
}

// Hide text icons and show primary
function hideTextIcons() {
    document.getElementById('primaryIcons').style.display = 'flex';
    document.getElementById('textIcons').style.display = 'none';

    // Restore icon container pointer events
    const iconContainer = document.querySelector('.icon-container');
    if (iconContainer) {
        iconContainer.style.pointerEvents = '';
        iconContainer.querySelectorAll('.overlay-icon, .nav-arrow, .scene-indicator, button').forEach(el => {
            el.style.pointerEvents = '';
        });
    }

    // Disable selection mode
    setSelectionMode(false);

    // Remove text-mode specific listeners
    if (fabricCanvas) {
        fabricCanvas.off('mouse:dblclick');
        fabricCanvas.off('mouse:up');
        fabricCanvas.off('text:editing:exited');
        fabricCanvas.discardActiveObject();
        fabricCanvas.renderAll();
    }

    captionText = null;
    selectedCaptionId = null;
    selectedCaptionType = null;

    closeAllPanels();
}

// Placeholder functions for each action
function showFontFamily() {
    // You can replace these with actual functionality
	
	
    showFontSelector();

    //alert('Font family selector would appear here');
}

function showFontSize() {
    alert('Font size selector would appear here');
}

function showTextColor() {
    alert('Text color picker would appear here');
}

function showBgColor() {
    alert('Background color picker would appear here');
}



function showPosition() {
    alert('Position options would appear here');
}

// ========== DISPLAY MODE FUNCTIONS ==========
function setDisplayMode(mode) {
    if (!captionText || !captionText.captionId) {
        console.warn('No caption selected');
        return;
    }
    
    console.log('Setting display mode to:', mode);
    
    // Update UI - remove active class from all
    document.querySelectorAll('.animation-option').forEach(opt => {
        opt.style.borderColor = 'transparent';
        opt.style.background = '#f8fafc';
    });
    
    // Highlight selected
    const selected = document.getElementById('display' + mode.charAt(0).toUpperCase() + mode.slice(1));
    if (selected) {
        selected.style.borderColor = 'var(--success)';
        selected.style.background = '#e0f2fe';
    }
    
    // Save to database
    saveCaptionToDatabase(captionText.captionId, { display_mode: mode });
}

// ========== LOAD ANIMATION SETTINGS FROM CAPTION ==========
function loadAnimationSettingsFromCaption(caption) {
    if (!caption) return;

    // Highlight display mode button
    const displayMode = caption.display_mode || 'full';
    document.querySelectorAll('.animation-option').forEach(opt => {
        opt.style.borderColor = 'transparent';
        opt.style.background = '#f8fafc';
    });
    const displayEl = document.getElementById('display' + displayMode.charAt(0).toUpperCase() + displayMode.slice(1));
    if (displayEl) { displayEl.style.borderColor = 'var(--success)'; displayEl.style.background = '#e0f2fe'; }
    else {
        const def = document.getElementById('displayFull');
        if (def) { def.style.borderColor = 'var(--success)'; def.style.background = '#e0f2fe'; }
    }

    // Highlight animation style button — handle both old and new value formats
    const styleToId = {
        'typewriter': 'animTypewriter',
        'wordReveal': 'animWordReveal',  'word-reveal': 'animWordReveal',
        'fade':       'animFade',        'fade-in':     'animFade',
        'slideUp':    'animSlideUp',     'slide-up':    'animSlideUp',
        'slideDown':  'animSlideDown',   'slide-down':  'animSlideDown',
        'zoom':       'animZoom',        'zoom-in':     'animZoom',
        'pop':        'animPop',
        'bounce':     'animBounce',
        'karaoke':    'animKaraoke',
        'static':     'animStatic',      'none':        'animStatic'
    };
    document.querySelectorAll('.anim-btn').forEach(btn => {
        btn.style.borderColor = 'transparent';
        btn.style.background = '#f8fafc';
    });
    const animStyle = caption.animation_style || 'static';
    const animElId = styleToId[animStyle] || 'animStatic';
    const animEl = document.getElementById(animElId);
    if (animEl) { animEl.style.borderColor = 'var(--success)'; animEl.style.background = '#e0f2fe'; }

    // Load speed
    const speed = parseFloat(caption.animation_speed) || 1.0;
    const slider = document.getElementById('animSpeedSlider');
    const display = document.getElementById('animSpeedDisplay');
    if (slider) slider.value = speed;
    if (display) display.innerText = speed.toFixed(1) + 'x';
}

// Show animation overlay with proper positioning
function showAnimation(event) {
    console.log('🎯 Opening animation options');
    
    // Store current mode
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    
    // Store which mode we're in
    window.lastMode = 'primary';
    if (primaryIcons && primaryIcons.style.display === 'none') {
        if (textIcons && textIcons.style.display === 'flex') {
            window.lastMode = 'text';
            console.log('📝 Last mode set to: text');
        }
    }
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE SECONDARY ICONS
    if (textIcons) {
        textIcons.style.display = 'none';
        console.log('Text icons hidden');
    }
    
    const overlay = document.getElementById('animationPanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ animationPanel not found!');
        return;
    }
    
    // Load current animation settings from selected caption
    if (captionText && captionText.captionId) {
        const caption = findCaptionById(captionText.captionId);
        if (caption) {
            loadAnimationSettingsFromCaption(caption);
        }
    }
    
    // Position the overlay
    if (icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Overlay dimensions
        const overlayWidth = 320;
        const overlayHeight = 580;
        
        // Position to the right of icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Boundary checks
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        // Position below icon
        let top = iconRect.top - canvasRect.top + 20;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
    } else {
        // Center in canvas if no icon
        overlay.style.position = 'absolute';
        overlay.style.left = '50%';
        overlay.style.top = '50%';
        overlay.style.transform = 'translate(-50%, -50%)';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    console.log('Animation overlay displayed');
}






// Close all panels (implement if you have panels)
function closeAllPanels() {
    // Your existing close panels code
}



document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const textIcons = document.getElementById('textIcons');
        if (textIcons.style.display === 'flex') {
            hideTextIcons();
        }
    }
});

// Font data (from the demo)
const FONTS = [
    { id: "bebas",      name: "Bebas Neue",       family: "'Bebas Neue', cursive" },
    { id: "playfair",   name: "Playfair Display",  family: "'Playfair Display', serif" },
    { id: "pacifico",   name: "Pacifico",          family: "'Pacifico', cursive" },
    { id: "oswald",     name: "Oswald",            family: "'Oswald', sans-serif" },
    { id: "dancing",    name: "Dancing Script",    family: "'Dancing Script', cursive" },
    { id: "abril",      name: "Abril Fatface",     family: "'Abril Fatface', cursive" },
    { id: "righteous",  name: "Righteous",         family: "'Righteous', cursive" },
    { id: "lobster",    name: "Lobster",           family: "'Lobster', cursive" },
    { id: "raleway",    name: "Raleway",           family: "'Raleway', sans-serif" },
    { id: "montserrat", name: "Montserrat",        family: "'Montserrat', sans-serif" },
    { id: "inter",      name: "Inter",             family: "'Inter', sans-serif" },
    { id: "arial",      name: "Arial",             family: "Arial, sans-serif" },
    { id: "times",      name: "Times New Roman",   family: "'Times New Roman', serif" },
    { id: "georgia",    name: "Georgia",           family: "Georgia, serif" },
    { id: "courier",    name: "Courier New",       family: "'Courier New', monospace" },
    // Add more fonts as needed
];

let selectedFont = FONTS[0];
let currentQuery = "";

// Function to show the font selector
function showFontSelector() {
    const overlay = document.getElementById('fontSelectorOverlay');
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    // Set current font if caption is selected
    if (captionText) {
        const currentFamily = captionText.fontFamily;
        const matchingFont = FONTS.find(f => f.family === currentFamily);
        if (matchingFont) {
            selectedFont = matchingFont;
        }
    }
    
    renderFontList();
    updatePreview(selectedFont);
}

// Function to hide the font selector
function hideFontSelector() {
    const overlay = document.getElementById('fontSelectorOverlay');
    overlay.style.display = 'none';
    overlay.classList.remove('active');
}

// Select a font and apply to fabric caption
// Select a font and apply to fabric caption - WITH NULL CHECKS
function selectFont(font) {
    console.log('Selecting font:', font.name);
    selectedFont = font;
    
    // Apply to CURRENTLY SELECTED caption
    if (captionText) {
        captionText.set('fontFamily', font.family);
        fabricCanvas.renderAll();
        
        // Save to database
        if (typeof saveCaptionToDatabase === 'function') {
            saveCaptionToDatabase(captionText.captionId, { fontfamily: font.family });
        }
        
        console.log(`✅ Font applied to caption ${captionText.captionId}: ${font.name}`);
    } else {
        console.log('⚠️ No caption selected to apply font');
        showTemporaryMessage('Please select a caption first');
    }
    
    // Update preview (with null checks inside)
    updatePreview(font);
    
    // Re-render font list to show active state
    if (typeof renderFontList === 'function') {
        renderFontList();
    }
}

// Update preview elements
// Update preview elements - WITH NULL CHECKS
function updatePreview(font) {
    console.log('Updating preview for font:', font.name);
    
    // Preview text element
    const previewText = document.getElementById('previewText');
    if (previewText) {
        previewText.style.fontFamily = font.family;
    }
    
    // Preview chars element
    const previewChars = document.getElementById('previewChars');
    if (previewChars) {
        previewChars.style.fontFamily = font.family;
    }
    
    // Preview badge element
    const previewBadge = document.getElementById('previewBadge');
    if (previewBadge) {
        previewBadge.style.fontFamily = font.family;
        previewBadge.textContent = font.name;
    }
    
    // Footer selected element
    const footerSelected = document.getElementById('footerSelected');
    if (footerSelected) {
        footerSelected.style.fontFamily = font.family;
        footerSelected.textContent = font.name;
    }
}

// Render font list with filtering
function renderFontList() {
    const searchTerm = document.getElementById('fontSearchInput').value.toLowerCase();
    const filtered = FONTS.filter(f => 
        f.name.toLowerCase().includes(searchTerm)
    );
    
    const container = document.getElementById('fontListContainer');
    const noResults = document.getElementById('noResults');
    const footerCount = document.getElementById('footerCount');
    
    footerCount.textContent = `${filtered.length} font${filtered.length !== 1 ? 's' : ''}`;
    
    if (filtered.length === 0) {
        noResults.style.display = 'block';
        container.innerHTML = '';
        return;
    }
    
    noResults.style.display = 'none';
    
    let html = '';
    filtered.forEach(font => {
        const isActive = font.id === selectedFont.id;
        html += `
            <button class="font-row ${isActive ? 'active' : ''}" 
                    data-font-id="${font.id}"
                    onclick="selectFontFromList('${font.id}')">
                <span class="font-name" style="font-family:${font.family}">${font.name}</span>
                <span class="font-aa" style="font-family:${font.family}">Aa</span>
                <svg class="font-check" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </button>
        `;
    });
    
    container.innerHTML = html;
}

// Helper function for onclick
function selectFontFromList(fontId) {
    const font = FONTS.find(f => f.id === fontId);
    if (font) {
        selectFont(font);
        // Optional: Auto-hide after selection
        // setTimeout(hideFontSelector, 300);
    }
}

// Search functionality
document.getElementById('fontSearchInput').addEventListener('input', function(e) {
    currentQuery = e.target.value;
    document.getElementById('fontSearchClear').style.display = currentQuery ? 'block' : 'none';
    renderFontList();
});

document.getElementById('fontSearchClear').addEventListener('click', function() {
    document.getElementById('fontSearchInput').value = '';
    currentQuery = '';
    this.style.display = 'none';
    document.getElementById('fontSearchInput').focus();
    renderFontList();
});



// Escape key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideFontSelector();
    }
});



function showFontFamilyOptions() {
    showFontSelector();
}

// Constants
const SIZE_PRESETS = [8, 10, 12, 14, 16, 18, 20, 24, 28, 32, 36, 42, 48, 56, 64, 72, 96, 120];
const COLOR_SWATCHES = [
    "#ffffff", "#000000", "#FF3B30", "#FF9500", "#FFCC00", 
    "#34C759", "#00C7BE", "#007AFF", "#5856D6", "#AF52DE",
    "#FF2D55", "#FF6B6B", "#A8E6CF", "#3D5A80", "#E07A5F"
];

// State
let fontSize = 36;
let fontColor = "#ffffff";
let opacity = 100;


function initSizePicker() {
    const sizeNumber = document.getElementById('sizeNumber');
    const sizeSlider = document.getElementById('sizeSlider');
    const sizeDown = document.getElementById('sizeDown');
    const sizeUp = document.getElementById('sizeUp');
    const sizePresetsEl = document.getElementById('sizePresets');
    
    // Build preset chips
    SIZE_PRESETS.forEach(s => {
        const btn = document.createElement("button");
        btn.className = "size-preset";
        btn.dataset.size = s;
        btn.textContent = s;
        btn.addEventListener("click", () => setFontSize(s));
        sizePresetsEl.appendChild(btn);
    });
    
    // Event listeners
    sizeDown?.addEventListener("click", () => setFontSize(fontSize - 1));
    sizeUp?.addEventListener("click", () => setFontSize(fontSize + 1));
    sizeSlider?.addEventListener("input", e => setFontSize(+e.target.value));
}

function initColorPicker() {
    const swatchesEl = document.getElementById('colorSwatches');
    const hexInput = document.getElementById('hexInput');
    const hexPreview = document.getElementById('hexPreview');
    const opacitySlider = document.getElementById('opacitySlider');
    const opacityValue = document.getElementById('opacityValue');
    
    // Build swatches
    COLOR_SWATCHES.forEach(c => {
        const sw = document.createElement("button");
        sw.className = "swatch";
        sw.dataset.color = c;
        sw.style.background = c;
        sw.title = c;
        sw.addEventListener("click", () => setFontColor(c));
        swatchesEl.appendChild(sw);
    });
    
    // Custom color picker
    const customSwatch = document.createElement("label");
    customSwatch.className = "swatch swatch-custom";
    customSwatch.title = "Custom color";
    const nativePicker = document.createElement("input");
    nativePicker.type = "color";
    nativePicker.value = fontColor;
    nativePicker.addEventListener("input", e => setFontColor(e.target.value));
    customSwatch.appendChild(nativePicker);
    swatchesEl.appendChild(customSwatch);
    
    // Hex input
    hexInput.value = fontColor;
    hexInput.addEventListener("input", e => {
        let val = e.target.value.trim();
        if (!val.startsWith("#")) val = "#" + val;
        if (isValidHex(val)) setFontColor(val);
        if (hexPreview) hexPreview.style.background = isValidHex(val) ? val : '';
    });
    
    // Opacity
    opacitySlider.addEventListener("input", e => {
        opacity = +e.target.value;
        if (opacityValue) opacityValue.textContent = opacity + "%";
        applyFontStyle();
    });
}

// Helper functions
function isValidHex(h) { 
    return /^#[0-9a-fA-F]{6}$/.test(h); 
}

function hexToRgb(hex) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return { r, g, b };
}

// Apply to selected caption
function setFontSize(size) {
    fontSize = Math.min(120, Math.max(8, size));
    
    // Update UI
    const sizeNumber = document.getElementById('sizeNumber');
    const sizeSlider = document.getElementById('sizeSlider');
    if (sizeNumber) sizeNumber.textContent = fontSize;
    if (sizeSlider) sizeSlider.value = fontSize;
    
    // Update preset buttons
    document.querySelectorAll(".size-preset").forEach(btn => {
        btn.classList.toggle("active", +btn.dataset.size === fontSize);
    });
    
    applyFontStyle();
}

function setFontColor(color) {
    if (!isValidHex(color)) return;
    fontColor = color.toLowerCase();
    
    // Update UI
    const hexInput = document.getElementById('hexInput');
    const hexPreview = document.getElementById('hexPreview');
    if (hexInput && document.activeElement !== hexInput) {
        hexInput.value = fontColor;
    }
    if (hexPreview) hexPreview.style.background = fontColor;
    
    // Update swatches
    document.querySelectorAll(".swatch:not(.swatch-custom)").forEach(sw => {
        sw.classList.toggle("active", sw.dataset.color === fontColor);
    });
    
    applyFontStyle();
}

function setOpacity(value) {
    opacity = Math.min(100, Math.max(0, value));
    
    const opacityValue = document.getElementById('opacityValue');
    const opacitySlider = document.getElementById('opacitySlider');
    if (opacityValue) opacityValue.textContent = opacity + "%";
    if (opacitySlider) opacitySlider.value = opacity;
    
    applyFontStyle();
}

function applyFontStyle() {
    if (!captionText) return;
    
    // Apply size
    captionText.set('fontSize', fontSize);
    
    // Apply color with opacity
    const { r, g, b } = hexToRgb(fontColor);
    const alpha = opacity / 100;
    const cssColor = alpha < 1 
        ? `rgba(${r},${g},${b},${alpha.toFixed(2)})` 
        : fontColor;
    
    captionText.set('fill', cssColor);
    
    // Re-render
    fabricCanvas.renderAll();
    
    // Save to database
    if (typeof saveCaptionToDatabase === 'function') {
        saveCaptionToDatabase(captionText.captionId, { 
            fontsize: fontSize,
            fontcolor: fontColor,
            opacity: opacity
        });
    }
}

// Update showFontSizeOptions to hide secondary icons


// Add this function to your JavaScript - place it near your other functions
function showFontSizeOptions(event) {
    console.log('🎯 showFontSizeOptions called');
    
    // Store current mode
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    
    // Store which mode we're in
    window.lastMode = 'primary';
    if (primaryIcons && primaryIcons.style.display === 'none') {
        if (textIcons && textIcons.style.display === 'flex') {
            window.lastMode = 'text';
        }
    }
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE SECONDARY ICONS
    if (textIcons) {
        textIcons.style.display = 'none';
    }
    
    const overlay = document.getElementById('fontSizePanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ fontSizePanel not found!');
        return;
    }
    
    // Set current size from selected caption
    if (captionText) {
        const currentSize = captionText.fontSize || 36;
        updateFontSizeDisplay(currentSize);
        highlightActiveSizePreset(currentSize);
    }
    
    // Position the overlay
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasContainer = document.getElementById('canvasContainer');
        const containerRect = canvasContainer.getBoundingClientRect();
        
        let left = iconRect.left - containerRect.left + 20;
        let top = iconRect.top - containerRect.top + 40;
        
        // Keep within container
        if (left + 280 > containerRect.width) {
            left = containerRect.width - 290;
        }
        if (left < 10) left = 10;
        
        if (top + 500 > containerRect.height) {
            top = containerRect.height - 510;
        }
        if (top < 10) top = 10;
		
        top =70;
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
}

// Make sure these helper functions exist too
function updateFontSizeDisplay(size) {
    const display = document.getElementById('fontSizeNumber');
    const slider = document.getElementById('fontSizeSlider');
    
    if (display) display.textContent = size;
    if (slider) slider.value = size;
}

function highlightActiveSizePreset(size) {
    const presets = document.querySelectorAll('#fontSizePanel .size-preset');
    presets.forEach(btn => {
        const btnSize = parseInt(btn.textContent);
        if (btnSize === size) {
            btn.classList.add('active');
            btn.style.background = '#0f2a44';
            btn.style.color = 'white';
        } else {
            btn.classList.remove('active');
            btn.style.background = '#f8fafc';
            btn.style.color = '#0f2a44';
        }
    });
}


// Build font size overlay content
function buildFontSizeOverlayContent() {
    const overlay = document.getElementById('fontSizeOverlay');
    
    const SIZE_PRESETS = [8, 10, 12, 14, 16, 18, 20, 24, 28, 32, 36, 42, 48, 56, 64, 72, 96, 120];
    
    // Build preset buttons
    let presetsHtml = '';
    SIZE_PRESETS.forEach(size => {
        presetsHtml += `<button class="size-preset" data-size="${size}" onclick="setFontSize(${size})">${size}</button>`;
    });
    
    overlay.innerHTML = `
        <div class="picker-card">
            <div class="card-header">
                <span class="back-icon-small" onclick="closeFontSizePicker()">←</span>
                Font Size
            </div>
            <div class="card-body">
                <!-- Size stepper -->
                <div class="size-stepper">
                    <button class="step-btn" id="sizeDown" onclick="decreaseFontSize()">−</button>
                    <div class="size-display">
                        <span class="size-number" id="sizeNumber">36</span>
                        <span class="size-unit">px</span>
                    </div>
                    <button class="step-btn" id="sizeUp" onclick="increaseFontSize()">+</button>
                </div>
                
                <!-- Slider -->
                <input class="size-slider" type="range" id="sizeSlider" min="8" max="120" value="36" oninput="handleSizeSlider(this.value)">
                
                <!-- Presets -->
                <div class="size-presets" id="sizePresets">
                    ${presetsHtml}
                </div>
            </div>
        </div>
    `;
    
    // Add event listeners
    setTimeout(() => {
        const slider = document.getElementById('sizeSlider');
        if (slider) {
            slider.addEventListener('input', function(e) {
                handleSizeSlider(e.target.value);
            });
        }
    }, 100);
}

// Handle size slider
function handleSizeSlider(value) {
    setFontSize(parseInt(value));
}



// Increase font size
function increaseFontSize() {
    if (captionText) {
        const newSize = Math.min(120, (captionText.fontSize || 36) + 1);
        setFontSize(newSize);
    }
}

// Update setFontSize function
function setFontSize(size) {
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    fontSize = Math.min(120, Math.max(8, size));
    
    // Update UI
    const sizeNumber = document.getElementById('sizeNumber');
    const sizeSlider = document.getElementById('sizeSlider');
    
    if (sizeNumber) sizeNumber.textContent = fontSize;
    if (sizeSlider) sizeSlider.value = fontSize;
    
    // Update preset buttons
    document.querySelectorAll('#fontSizeOverlay .size-preset').forEach(btn => {
        const btnSize = parseInt(btn.dataset.size);
        if (btnSize === fontSize) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    // Apply to caption
    captionText.set('fontSize', fontSize);
    fabricCanvas.renderAll();
    
    // Save to database
    if (captionText.captionId) {
        saveCaptionToDatabase(captionText.captionId, { fontsize: fontSize });
    }
}

// Set font size value (for initialization)
function setFontSizeValue(size) {
    fontSize = size;
    const sizeNumber = document.getElementById('sizeNumber');
    const sizeSlider = document.getElementById('sizeSlider');
    
    if (sizeNumber) sizeNumber.textContent = size;
    if (sizeSlider) sizeSlider.value = size;
    
    // Update preset buttons
    document.querySelectorAll('#fontSizeOverlay .size-preset').forEach(btn => {
        const btnSize = parseInt(btn.dataset.size);
        if (btnSize === size) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

function setFontColorValue(color) {
    fontColor = color;
    const hexInput = document.getElementById('hexInput');
    const hexPreview = document.getElementById('hexPreview');
    if (hexInput) hexInput.value = color;
    if (hexPreview) hexPreview.style.background = color;
}

function setOpacityValue(value) {
    opacity = value;
    const opacityValue = document.getElementById('opacityValue');
    const opacitySlider = document.getElementById('opacitySlider');
    if (opacityValue) opacityValue.textContent = value + "%";
    if (opacitySlider) opacitySlider.value = value;
}
// Test function to verify background color is working
function testBackgroundColor() {
    if (!captionText) {
        console.log('No caption selected');
        return;
    }
    
    console.log('Current caption:', {
        id: captionText.captionId,
        backgroundColor: captionText.backgroundColor,
        bg_enabled: document.getElementById('sceneFontBgEnable')?.checked,
        padding: captionText.padding
    });
    
    // Force background to be visible
    captionText.set({
        backgroundColor: '#ff0000',
        padding: 20
    });
    
    fabricCanvas.renderAll();
    console.log('Background forced to red');
}

// Add keyboard shortcut: Ctrl+Shift+T to test
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.shiftKey && e.key === 'T') {
        testBackgroundColor();
    }
});
// Show style options overlay (for Bold, Italic, Underline)
// Show style options overlay - POSITIONED TO THE RIGHT
function showStyleOptions() {
    console.log('Opening style options');
    closeAllOverlays();
    
    const overlay = document.getElementById('stylePanel');
    const icon = event?.currentTarget;
    
    // Update the indicator states based on current caption
    if (captionText) {
        updateStyleIndicators();
    }
    
    if (overlay && icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Define overlay dimensions
        const overlayWidth = 300;
        const overlayHeight = 350;
        
        // Calculate position - 50px TO THE RIGHT of the icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Make sure it doesn't go off the right edge
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        // Ensure minimum left position
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top; // Align with top of icon
        top =100;
        // Make sure it doesn't go off the bottom
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        // Make sure it doesn't go off the top
        if (top < 10) top = 10;
        
        // Set position and display
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
        overlay.style.display = 'block';
        
        console.log('Style overlay positioned at:', { left, top });
    } else {
        // Fallback - just show it
        if (overlay) {
            overlay.style.display = 'block';
        }
    }
}

// Update the style indicators based on current text styles
// Update style indicators
function updateStyleIndicators() {
    if (!captionText) return;
    
    // Bold indicator
    const boldBtn = document.getElementById('styleBoldBtn');
    const boldIndicator = document.getElementById('styleBoldIndicator');
    const isBold = captionText.fontWeight === 'bold' || captionText.fontWeight === '700';
    
    if (boldBtn) {
        if (isBold) {
            boldBtn.classList.add('active');
            boldBtn.style.borderColor = 'var(--success)';
            if (boldIndicator) {
                boldIndicator.style.background = 'var(--success)';
            }
        } else {
            boldBtn.classList.remove('active');
            boldBtn.style.borderColor = 'transparent';
            if (boldIndicator) {
                boldIndicator.style.background = 'transparent';
            }
        }
    }
    
    // Italic indicator
    const italicBtn = document.getElementById('styleItalicBtn');
    const italicIndicator = document.getElementById('styleItalicIndicator');
    const isItalic = captionText.fontStyle === 'italic';
    
    if (italicBtn) {
        if (isItalic) {
            italicBtn.classList.add('active');
            italicBtn.style.borderColor = 'var(--success)';
            if (italicIndicator) {
                italicIndicator.style.background = 'var(--success)';
            }
        } else {
            italicBtn.classList.remove('active');
            italicBtn.style.borderColor = 'transparent';
            if (italicIndicator) {
                italicIndicator.style.background = 'transparent';
            }
        }
    }
    
    // Underline indicator
    const underlineBtn = document.getElementById('styleUnderlineBtn');
    const underlineIndicator = document.getElementById('styleUnderlineIndicator');
    const isUnderline = captionText.underline === true;
    
    if (underlineBtn) {
        if (isUnderline) {
            underlineBtn.classList.add('active');
            underlineBtn.style.borderColor = 'var(--success)';
            if (underlineIndicator) {
                underlineIndicator.style.background = 'var(--success)';
            }
        } else {
            underlineBtn.classList.remove('active');
            underlineBtn.style.borderColor = 'transparent';
            if (underlineIndicator) {
                underlineIndicator.style.background = 'transparent';
            }
        }
    }
}

// Update alignment indicators
function updateAlignmentIndicators() {
    if (!captionText) return;
    
    const currentAlign = captionText.textAlign || 'left';
    
    // Reset all
    const aligns = ['left', 'center', 'right', 'justify'];
    aligns.forEach(align => {
        const btn = document.getElementById(`align${align.charAt(0).toUpperCase() + align.slice(1)}`);
        const indicator = document.getElementById(`align${align.charAt(0).toUpperCase() + align.slice(1)}Indicator`);
        
        if (btn) {
            if (currentAlign === align) {
                btn.classList.add('active');
                btn.style.borderColor = 'var(--success)';
                if (indicator) {
                    indicator.style.background = 'var(--success)';
                }
            } else {
                btn.classList.remove('active');
                btn.style.borderColor = 'transparent';
                if (indicator) {
                    indicator.style.background = 'transparent';
                }
            }
        }
    });
}

// Show alignment options overlay
// Show alignment options overlay - POSITIONED TO THE RIGHT
function showAlignment() {
    console.log('Opening alignment options');
    closeAllOverlays();
    
    const overlay = document.getElementById('alignmentPanel');
    const icon = event?.currentTarget;
    
    // Update the alignment indicators based on current caption
    if (captionText) {
        updateAlignmentIndicators();
    }
    
    if (overlay && icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Define overlay dimensions
        const overlayWidth = 280;
        const overlayHeight = 260;
        
       let left = iconRect.left - canvasRect.left + 10;
       
        
        // Check if it goes off the right edge
        if (left + overlayWidth > canvasRect.width) {
            // Try moving it left of the icon instead
            left = iconRect.left - canvasRect.left - overlayWidth - 10;
            console.log('Moved to left of icon to fit');
        }
        
        // Ensure minimum left position
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top; // Align with top of icon
        top = 100;
        // Make sure it doesn't go off the bottom
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        // Make sure it doesn't go off the top
        if (top < 10) top = 10;
        
        // Set position and display
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
        overlay.style.display = 'block';
        
        console.log('Alignment overlay positioned at:', { left, top });
    } else {
        // Fallback - just show it
        if (overlay) {
            overlay.style.display = 'block';
        }
    }
}


// Update alignment indicators based on current text alignment
function updateAlignmentIndicators() {
    if (!captionText) return;
    
    const currentAlign = captionText.textAlign || 'left';
    console.log('Current alignment:', currentAlign);
    
    // Reset all indicators
    const alignments = ['left', 'center', 'right', 'justify'];
    alignments.forEach(align => {
        const element = document.getElementById(`align${align.charAt(0).toUpperCase() + align.slice(1)}`);
        const indicator = document.getElementById(`align${align.charAt(0).toUpperCase() + align.slice(1)}Indicator`);
        
        if (element) {
            if (currentAlign === align) {
                element.classList.add('active');
                element.style.borderColor = 'var(--success)';
                element.style.background = '#e0f2fe';
                if (indicator) {
                    indicator.style.background = 'var(--success)';
                    indicator.innerHTML = '✓';
                }
            } else {
                element.classList.remove('active');
                element.style.borderColor = 'transparent';
                element.style.background = '#f8fafc';
                if (indicator) {
                    indicator.style.background = 'transparent';
                    indicator.innerHTML = '';
                }
            }
        }
    });
}
// Show effects overlay
function showEffects() {
    console.log('Opening effects options');
    closeAllOverlays();
    
    const overlay = document.getElementById('effectsPanel');
    const icon = event?.currentTarget;
    
    // Load current effect states from caption
    if (captionText) {
        updateEffectsFromCaption();
    }
    
    if (overlay && icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Define overlay dimensions
        const overlayWidth = 300;
        const overlayHeight = 380;
        
        const canvasCenterX = canvasRect.width / 2;
		const overlayHalfWidth = overlayWidth / 2;

		// Position at canvas center
		let left = canvasCenterX - overlayHalfWidth;

		// Keep within canvas bounds
		if (left < 10) left = 10;
		if (left + overlayWidth > canvasRect.width) {
			left = canvasRect.width - overlayWidth - 10;
		}

		// Move top by 50px (from +40 to +90)
		let top = iconRect.top - canvasRect.top + 90;

		if (top + overlayHeight > canvasRect.height) {
			top = canvasRect.height - overlayHeight - 10;
		}

		if (top < 10) top = 10;
        top = 100;
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
        overlay.style.display = 'block';
    } else {
        if (overlay) overlay.style.display = 'block';
    }
}

// Show position overlay
function showPosition() {
    console.log('Opening position options');
    closeAllOverlays();
    
    const overlay = document.getElementById('positionPanel');
    const icon = event?.currentTarget;
    
    // Update current position indicators
    if (captionText) {
        updatePositionIndicators();
    }
    
    if (overlay && icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 320;
        const overlayHeight = 480;
        
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
        overlay.style.display = 'block';
    } else {
        if (overlay) overlay.style.display = 'block';
    }
}

// Set vertical position (top, center, bottom)
function setVerticalPosition(position) {
    if (!captionText || !fabricCanvas) return;
    
    const canvasHeight = fabricCanvas.height;
    const textHeight = captionText.height * (captionText.scaleY || 1);
    
    let top;
    if (position === 'top') {
        top = 50;
    } else if (position === 'center') {
        top = (canvasHeight / 2) - (textHeight / 2);
    } else if (position === 'bottom') {
        top = canvasHeight - textHeight - 50;
    }
    
    captionText.set('top', Math.round(top));
    captionText.setCoords();
    fabricCanvas.renderAll();
    
    // Save to database
    saveCaptionToDatabase(captionText.captionId, { 
        position_y: Math.round(top),
        caption_position: position 
    });
    
    updatePositionIndicators();
    updatePositionCoordinates();
}

// Set horizontal position (left, center, right)
function setHorizontalPosition(position) {
    if (!captionText || !fabricCanvas) return;
    
    const canvasWidth = fabricCanvas.width;
    const textWidth = captionText.width * (captionText.scaleX || 1);
    
    let left;
    if (position === 'left') {
        left = 50;
    } else if (position === 'center') {
        left = (canvasWidth / 2) - (textWidth / 2);
    } else if (position === 'right') {
        left = canvasWidth - textWidth - 50;
    }
    
    captionText.set('left', Math.round(left));
    captionText.setCoords();
    fabricCanvas.renderAll();
    
    // Save to database
    saveCaptionToDatabase(captionText.captionId, { 
        position_x: Math.round(left)
    });
    
    updatePositionIndicators();
    updatePositionCoordinates();
}

// Set center position (both axes)
function setCenterPosition() {
    if (!captionText || !fabricCanvas) return;
    
    const canvasWidth = fabricCanvas.width;
    const canvasHeight = fabricCanvas.height;
    const textWidth = captionText.width * (captionText.scaleX || 1);
    const textHeight = captionText.height * (captionText.scaleY || 1);
    
    const left = (canvasWidth / 2) - (textWidth / 2);
    const top = (canvasHeight / 2) - (textHeight / 2);
    
    captionText.set({
        left: Math.round(left),
        top: Math.round(top)
    });
    captionText.setCoords();
    fabricCanvas.renderAll();
    
    // Save to database
    saveCaptionToDatabase(captionText.captionId, { 
        position_x: Math.round(left),
        position_y: Math.round(top),
        caption_position: 'center'
    });
    
    updatePositionIndicators();
    updatePositionCoordinates();
}

// Update position indicators
function updatePositionIndicators() {
    if (!captionText) return;
    
    const canvasWidth = fabricCanvas.width;
    const canvasHeight = fabricCanvas.height;
    const textWidth = captionText.width * (captionText.scaleX || 1);
    const textHeight = captionText.height * (captionText.scaleY || 1);
    const left = captionText.left;
    const top = captionText.top;
    
    // Determine vertical position
    let vPos = 'center';
    if (top < canvasHeight * 0.3) vPos = 'top';
    else if (top > canvasHeight * 0.7) vPos = 'bottom';
    
    // Determine horizontal position
    let hPos = 'center';
    if (left < canvasWidth * 0.3) hPos = 'left';
    else if (left > canvasWidth * 0.7) hPos = 'right';
    
    // Update vertical indicators
    const vButtons = ['top', 'center', 'bottom'];
    vButtons.forEach(pos => {
        const btn = document.getElementById(`pos${pos.charAt(0).toUpperCase() + pos.slice(1)}`);
        const indicator = document.getElementById(`pos${pos.charAt(0).toUpperCase() + pos.slice(1)}Indicator`);
        const isActive = (pos === vPos);
        
        if (btn) {
            if (isActive) {
                btn.classList.add('active');
                btn.style.borderColor = 'var(--success)';
                if (indicator) indicator.style.background = 'var(--success)';
            } else {
                btn.classList.remove('active');
                btn.style.borderColor = 'transparent';
                if (indicator) indicator.style.background = 'transparent';
            }
        }
    });
    
    // Update horizontal indicators
    const hButtons = ['left', 'center', 'right'];
    hButtons.forEach(pos => {
        const btnId = pos === 'center' ? 'posHCenter' : `pos${pos.charAt(0).toUpperCase() + pos.slice(1)}`;
        const btn = document.getElementById(btnId);
        const indicator = document.getElementById(btnId + 'Indicator');
        const isActive = (pos === hPos);
        
        if (btn) {
            if (isActive) {
                btn.classList.add('active');
                btn.style.borderColor = 'var(--success)';
                if (indicator) indicator.style.background = 'var(--success)';
            } else {
                btn.classList.remove('active');
                btn.style.borderColor = 'transparent';
                if (indicator) indicator.style.background = 'transparent';
            }
        }
    });
    
    // Update center both indicator
    const centerBtn = document.getElementById('posCenter');
    if (centerBtn) {
        if (vPos === 'center' && hPos === 'center') {
            centerBtn.classList.add('active');
            centerBtn.style.borderColor = 'var(--success)';
        } else {
            centerBtn.classList.remove('active');
            centerBtn.style.borderColor = 'transparent';
        }
    }
    
    updatePositionCoordinates();
}

// Update position coordinates display
function updatePositionCoordinates() {
    if (!captionText) return;
    
    const coordsEl = document.getElementById('positionCoordinates');
    if (coordsEl) {
        coordsEl.innerHTML = `X: ${Math.round(captionText.left)}px, Y: ${Math.round(captionText.top)}px`;
    }
}

// ========== IMAGE CAPTION FUNCTIONS ==========

// Show image source overlay positioned next to the icon
function showImageSourceOverlay(event) {
    console.log('Showing image source overlay');
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Close any other open overlays first
    closeAllOverlays();
    
    const overlay = document.getElementById('imageSourcePanel');
    const icon = event?.currentTarget;
    
    if (overlay && icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Define overlay dimensions
        const overlayWidth = 280;
        const overlayHeight = 220;
        
        // Calculate position - 50px TO THE RIGHT of the icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Make sure it doesn't go off the right edge
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        // Ensure minimum left position
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        // Make sure it doesn't go off the bottom
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        // Make sure it doesn't go off the top
        if (top < 10) top = 10;
        
        // Set position and display
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
        overlay.style.display = 'block';
        
        console.log('Image source overlay positioned at:', { left, top });
    } else {
        // Fallback - just show it
        if (overlay) {
            overlay.style.display = 'block';
        }
    }
}

// Open sticker library for new image box
function openStickerLibraryForNewBox() {
    console.log('📚 Opening sticker library for new image box');
    
    // Close the overlay
    closeOverlay('imageSourcePanel');
    
    // Use the existing openImageLibrary function
    if (typeof openImageLibrary === 'function') {
        // Set a flag to indicate we're creating a new image caption
        window.isCreatingNewImageCaption = true;
        
        // Open the image library
        openImageLibrary();
        
        // Override the confirm button behavior
        setTimeout(() => {
            const selectBtn = document.getElementById('mediaSelectBtn');
            if (selectBtn) {
                // Store original text
                selectBtn.innerHTML = 'Use as Image Caption';
                
                // Override onclick
                const originalOnClick = selectBtn.onclick;
                selectBtn.onclick = function() {
                    if (selectedMediaFile && selectedMediaType === 'image') {
                        createNewImageBox(selectedMediaFile);
                        closeMediaLib();
                    }
                };
            }
        }, 500);
    } else {
        alert('Image library function not available');
    }
}

// Upload image for new box
function uploadImageForNewBox() {
    console.log('📤 Upload button clicked - creating new image box');
    
    // Close the overlay
    closeOverlay('imageSourcePanel');
    
    // Trigger the file input
    document.getElementById('newImageBoxUpload').click();
}




// ========== IMAGE SELECTOR FUNCTIONS ==========

// Current selected image slot
let selectedImageSlot = 'image_file';

// Show the image selector overlay
function showImageSelectorOverlay(event) {
    console.log('Showing image selector overlay');
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Close any other open overlays
    closeAllOverlays();
    
    const overlay = document.getElementById('imageSelectorPanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ Image selector overlay not found!');
        alert('Overlay not found. Please refresh the page.');
        return;
    }
    
    // Load current scene data
    loadImageSlots();
    
    // Position near the icon
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 350;
        const overlayHeight = 420;
        
        // Calculate position - 50px TO THE RIGHT of the icon
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    } else {
        // Fallback - center of screen
        overlay.style.position = 'fixed';
        overlay.style.top = '50%';
        overlay.style.left = '50%';
        overlay.style.transform = 'translate(-50%, -50%)';
    }
    
    overlay.style.display = 'block';
    overlay.style.zIndex = '10000';
    
    // Select the current image field
    if (currentImageField) {
        selectImageSlot(currentImageField);
    } else {
        selectImageSlot('image_file');
    }
    
    console.log('✅ Overlay should now be visible');
}

// Load all image slots from current scene
function loadImageSlots() {
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    console.log('Loading image slots for scene:', scene.id);
    
    const slots = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
    
    slots.forEach(slot => {
        const filename = scene[slot];
        const imgEl = document.getElementById(`slot_img_${slot}`);
        const placeholderEl = document.getElementById(`slot_placeholder_${slot}`);
        
        if (!imgEl || !placeholderEl) return;
        
        if (filename && filename.trim() !== '') {
            const isVideo = filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i);
            
            if (isVideo) {
                imgEl.style.display = 'none';
                placeholderEl.style.display = 'flex';
                placeholderEl.innerHTML = '🎬';
            } else {
                imgEl.src = 'podcast_images/' + filename + '?t=' + Date.now();
                imgEl.style.display = 'block';
                placeholderEl.style.display = 'none';
            }
        } else {
            imgEl.style.display = 'none';
            placeholderEl.style.display = 'flex';
            
            // Set appropriate placeholder text
            if (slot === 'image_file') {
                placeholderEl.innerHTML = 'M';
            } else {
                const num = slot === 'image_file_1' ? '1' : 
                          slot === 'image_file_2' ? '2' : 
                          slot === 'image_file_3' ? '3' : '4';
                placeholderEl.innerHTML = num;
            }
        }
    });
}

// Select an image slot
// Select an image slot
function selectImageSlot(slotName) {
    console.log('Selecting image slot:', slotName);
    
    // Update current image field
    currentImageField = slotName;
    selectedImageSlot = slotName;
    
    // Update UI to show selected slot
    document.querySelectorAll('.image-slot div:first-child').forEach(el => {
        if (el) el.style.border = '1px solid var(--border)';
    });
    
    const selectedEl = document.getElementById(`slot_${slotName}`);
    if (selectedEl) {
        selectedEl.style.border = '2px solid var(--info)';
    }
    
    // Update selected slot name display
    const slotDisplay = document.getElementById('selectedSlotName');
    if (slotDisplay) {
        let displayName = 'Main';
        if (slotName === 'image_file_1') displayName = 'Slot 1 (V1)';
        else if (slotName === 'image_file_2') displayName = 'Slot 2 (V2)';
        else if (slotName === 'image_file_3') displayName = 'Slot 3 (V3)';
        else if (slotName === 'image_file_4') displayName = 'Slot 4 (V4)';
        slotDisplay.innerText = `${slotName} (${displayName})`;
    }
    
    // Load prompt for this slot
    loadSlotPrompt(slotName);
    
    // Show preview if image exists
    showSlotPreview(slotName);
    
    // UPDATE MAIN CANVAS WITH SELECTED IMAGE
    updateCanvas(currentSceneId);
}

// Load prompt for selected slot
function loadSlotPrompt(slotName) {
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    // Map slot to prompt field
    let promptField = 'prompt';
    if (slotName === 'image_file_1') promptField = 'prompt_1';
    else if (slotName === 'image_file_2') promptField = 'prompt_2';
    else if (slotName === 'image_file_3') promptField = 'prompt_3';
    else if (slotName === 'image_file_4') promptField = 'prompt_4';
    
    const promptTextarea = document.getElementById('slotPrompt');
    if (promptTextarea) {
        promptTextarea.value = scene[promptField] || '';
    }
}

// Show preview of selected slot image - UPDATED (no separate preview)
function showSlotPreview(slotName) {
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    const filename = scene[slotName];
    
    // Update the main canvas with the selected image
    if (filename && filename.trim() !== '' && !filename.match(/\.(mp4|webm|mov|avi|mkv|m4v)$/i)) {
        // The main canvas will be updated by updateCanvas() which is called in selectImageSlot
        console.log('Selected image:', filename);
    }
    
    // No separate preview element to update
}

// Upload image for selected slot
function uploadImageForSelectedSlot() {
    console.log('Upload for slot:', selectedImageSlot);
    document.getElementById('slotImageUpload').click();
}

// Open library for selected slot
function openLibraryForSelectedSlot() {
    console.log('Library for slot:', selectedImageSlot);
    
    closeOverlay('imageSelectorPanel');
    
    if (typeof openImageLibrary === 'function') {
        window.editingSceneId = currentSceneId;
        window.editingImageSlot = selectedImageSlot;
        openImageLibrary();
        
        setTimeout(() => {
            const selectBtn = document.getElementById('mediaSelectBtn');
            if (selectBtn) {
                selectBtn.innerHTML = 'Use in Selected Slot';
                selectBtn.onclick = function() {
                    if (selectedMediaFile && selectedMediaType === 'image') {
                        updateSceneImage(editingSceneId, editingImageSlot, selectedMediaFile);
                        closeMediaLib();
                        setTimeout(() => {
                            loadImageSlots();
                            selectImageSlot(editingImageSlot);
                        }, 500);
                    }
                };
            }
        }, 500);
    }
}

// Generate image for selected slot
async function generateImageForSelectedSlot() {
    console.log('Generate for slot:', selectedImageSlot);
    
    const promptTextarea = document.getElementById('slotPrompt');
    const prompt = promptTextarea ? promptTextarea.value.trim() : '';
    
    if (!prompt) {
        alert('Please enter a prompt first');
        return;
    }
    
    await saveSlotPrompt(selectedImageSlot, prompt);
    closeOverlay('imageSelectorPanel');
    
    if (typeof genOne === 'function') {
        await genOne(currentSceneId, selectedImageSlot, true);
        setTimeout(() => {
            loadImageSlots();
            selectImageSlot(selectedImageSlot);
        }, 1000);
    }
}

// Save prompt for selected slot
async function saveSlotPrompt(slotName, prompt) {
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    let promptField = 'prompt';
    if (slotName === 'image_file_1') promptField = 'prompt_1';
    else if (slotName === 'image_file_2') promptField = 'prompt_2';
    else if (slotName === 'image_file_3') promptField = 'prompt_3';
    else if (slotName === 'image_file_4') promptField = 'prompt_4';
    
    if (scene[promptField] === prompt) return;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_prompt');
        fd.append('scene_id', currentSceneId);
        fd.append('prompt', prompt);
        fd.append('prompt_field', promptField);
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            scene[promptField] = prompt;
        }
    } catch(e) {
        console.error('Error saving prompt:', e);
    }
}

// Update scene image
async function updateSceneImage(sceneId, slotName, filename) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    const promptTextarea = document.getElementById('slotPrompt');
    const currentPrompt = promptTextarea ? promptTextarea.value.trim() : '';
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_scene');
        fd.append('scene_id', sceneId);
        fd.append('image_field', slotName);
        fd.append('image_file', filename);
        fd.append('prompt', currentPrompt);
        
        const {data} = await safeFetch(fd);
        if (!data.success) throw new Error(data.message);
        
        scene[slotName] = filename;
        
        let promptField = 'prompt';
        if (slotName === 'image_file_1') promptField = 'prompt_1';
        else if (slotName === 'image_file_2') promptField = 'prompt_2';
        else if (slotName === 'image_file_3') promptField = 'prompt_3';
        else if (slotName === 'image_file_4') promptField = 'prompt_4';
        
        scene[promptField] = currentPrompt;
        
        if (sceneId == currentSceneId && slotName == currentImageField) {
            const imagePreview = document.getElementById('imagePreview');
            if (imagePreview) {
                imagePreview.src = 'podcast_images/' + filename + '?t=' + Date.now();
                imagePreview.style.display = 'block';
            }
        }
        
        L(`✅ ${slotName} updated with image from library`);
        
    } catch(e) {
        alert('Failed to update: ' + e.message);
    }
}

// ========== AUDIO OVERLAY FUNCTIONS ==========

// Show the audio overlay
function showAudioOverlay(event) {
    console.log('Showing audio overlay');
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    // Close any other open overlays
    closeAllOverlays();
    
    const overlay = document.getElementById('audioOverlay');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ Audio overlay not found!');
        alert('Overlay not found. Please refresh the page.');
        return;
    }
    
    // Position near the icon
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        const overlayWidth = 360;
        const overlayHeight = 580;
        
        // Calculate position - 50px TO THE RIGHT of the icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Make sure it doesn't go off the right edge
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        // Ensure minimum left position (margin from left)
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        // Make sure it doesn't go off the bottom
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        // Ensure minimum top position (margin from top)
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    } else {
        // Fallback - center of screen
        overlay.style.position = 'fixed';
        overlay.style.top = '50%';
        overlay.style.left = '50%';
        overlay.style.transform = 'translate(-50%, -50%)';
    }
    
    overlay.style.display = 'block';
    overlay.style.zIndex = '10000';
    
    // Load audio data for current scene
    loadAudioOverlayData(currentSceneId);
}

// Load audio data for the overlay
function loadAudioOverlayData(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    // Update scene text display with EDITABLE textarea
    const textDisplay = document.getElementById('audioOverlaySceneTextDisplay');
    if (textDisplay) {
        const currentText = scene.text_contents || '';
        
        textDisplay.innerHTML = `
            <textarea id="audioOverlaySceneText" 
                style="width: 100%; min-height: 80px; padding: 10px; border: 2px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13px; resize: vertical; background: white; color: var(--text);"
                placeholder="Enter text for this scene...">${escapeHtml(currentText)}</textarea>
            <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                <span id="audioOverlayVoiceInfo" style="font-size: 11px; color: var(--info); background: #e0f2fe; padding: 2px 8px; border-radius: 12px;">
                    🎤 Voice: ${scene.voice_id || 'Not set (will use podcast default)'}
                </span>
                <span id="audioOverlayTextCharCount" style="font-size: 11px; color: var(--muted);">${currentText.length} characters</span>
            </div>
        `;
        
        // Add character counter
        const textarea = document.getElementById('audioOverlaySceneText');
        if (textarea) {
            textarea.addEventListener('input', function() {
                const count = this.value.length;
                document.getElementById('audioOverlayTextCharCount').innerText = count + ' characters';
                
                // Auto-save after 1 second of no typing
                clearTimeout(window.audioOverlayTextTimeout);
                window.audioOverlayTextTimeout = setTimeout(() => {
                    saveAudioOverlayText(sceneId, this.value);
                }, 1000);
            });
        }
    }
    
    // Create audio player if audio file exists
    const audioContainer = document.getElementById('audioOverlayPlayerContainer');
    if (audioContainer) {
        if (scene.audio_file) {
            createAudioOverlayPlayerUI(sceneId, scene.audio_file);
        } else {
            audioContainer.innerHTML = '<div style="color:var(--muted); text-align:center; padding:20px; background: #f8fafc; border-radius: 8px;">🎵 No audio generated for this scene yet</div>';
        }
    }
    
    // Load podcast music info
    updateAudioOverlayMusicDisplay();
}

// Create audio player for overlay
function createAudioOverlayPlayerUI(sceneId, filename) {
    const container = document.getElementById('audioOverlayPlayerContainer');
    if (!container) return;

    const existingAudio = document.getElementById('audio_overlay_' + sceneId);
    if (existingAudio) {
        existingAudio.pause();
        existingAudio.removeAttribute('src');
        existingAudio.load();
        existingAudio.remove();
    }

    container.innerHTML = '';
    
    const playerContainer = document.createElement('div');
    playerContainer.style.cssText = 'display:flex; align-items:center; gap:12px; background:#f8fafc; border-radius:60px; padding:8px 16px; border:1px solid var(--border);';
    
    const playBtn = document.createElement('button');
    playBtn.style.cssText = 'width:44px; height:44px; background:var(--purple); color:white; border:none; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;';
    playBtn.id = 'audio_overlay_play_btn_' + sceneId;
    playBtn.innerHTML = '▶';
    playBtn.onclick = () => toggleAudioOverlayPlayback(sceneId);
    
    const timeDisplay = document.createElement('span');
    timeDisplay.style.cssText = 'font-size:12px; color:var(--muted); min-width:80px; text-align:center;';
    timeDisplay.id = 'audio_overlay_time_' + sceneId;
    timeDisplay.innerText = '0:00 / 0:00';
    
    const progressContainer = document.createElement('div');
    progressContainer.style.cssText = 'flex-grow:1; height:6px; background:var(--border); border-radius:3px; cursor:pointer; position:relative;';
    progressContainer.id = 'audio_overlay_progress_container_' + sceneId;
    progressContainer.onclick = (e) => seekAudioOverlay(sceneId, e);
    
    const progressFill = document.createElement('div');
    progressFill.style.cssText = 'height:100%; background:var(--purple); border-radius:3px; width:0%;';
    progressFill.id = 'audio_overlay_progress_fill_' + sceneId;
    progressContainer.appendChild(progressFill);
    
    const audio = document.createElement('audio');
    audio.id = 'audio_overlay_' + sceneId;
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
        progressFill.style.width = '0%';
        timeDisplay.innerText = '0:00 / ' + formatTime(audio.duration);
    };
    
    audio.onplay = () => {
        playBtn.innerHTML = '⏸';
    };
    
    audio.onpause = () => {
        playBtn.innerHTML = '▶';
    };
    
    playerContainer.appendChild(playBtn);
    playerContainer.appendChild(timeDisplay);
    playerContainer.appendChild(progressContainer);
    playerContainer.appendChild(audio);
    
    container.appendChild(playerContainer);
}

// Toggle audio playback in overlay
function toggleAudioOverlayPlayback(sceneId) {
    const audio = document.getElementById('audio_overlay_' + sceneId);
    const playBtn = document.getElementById('audio_overlay_play_btn_' + sceneId);

    if (!audio) return;
    
    if (audio.paused) {
        audio.play();
    } else {
        audio.pause();
    }
}

// Seek audio in overlay
function seekAudioOverlay(sceneId, event) {
    const audio = document.getElementById('audio_overlay_' + sceneId);
    const container = document.getElementById('audio_overlay_progress_container_' + sceneId);
    if (!audio || !container) return;
    
    const rect = container.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const width = rect.width;
    const percent = clickX / width;
    audio.currentTime = percent * audio.duration;
}

// Save audio text from overlay
async function saveAudioOverlayText(sceneId, text) {
    if (!sceneId || !text) return;
    
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    if (scene.text_contents === text) return;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_text');
        fd.append('scene_id', sceneId);
        fd.append('prompt', text);
        fd.append('prompt_field', 'text_contents');
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            scene.text_contents = text;
        }
    } catch(e) {
        console.error('Error saving text:', e);
    }
}

// Generate current audio from overlay
async function generateCurrentAudioFromOverlay() {
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    const scene = scenes.find(s => s.id == currentSceneId);
    if (!scene) return;
    
    const textarea = document.getElementById('audioOverlaySceneText');
    const textContent = textarea ? textarea.value.trim() : scene.text_contents || '';
    
    if (!textContent.trim()) {
        alert('This scene has no text content');
        return;
    }
    
    if (textarea && textarea.value !== scene.text_contents) {
        await saveAudioOverlayText(currentSceneId, textContent);
    }
    
    let voiceId = scene.voice_id;
    
    if (!voiceId) {
        const voiceSelect = document.getElementById('audioOverlayVoiceSelect');
        voiceId = voiceSelect ? voiceSelect.value : '';
        
        if (!voiceId) {
            alert('No voice selected for this scene. Please set a voice in the Podcast-Wide Settings first.');
            return;
        }
        
        await saveSceneVoiceToDB(currentSceneId, voiceId);
        scene.voice_id = voiceId;
    }
    
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    const langCode = '<?= $podcast_lang_code ?>';
    const newFilename = `voice_${podcastId}_${currentSceneId}_${langCode}_${Date.now()}.mp3`;
    
    const formData = new FormData();
    formData.append('row_id', currentSceneId);
    formData.append('text', textContent);
    formData.append('lang_code', langCode);
    formData.append('voice_id', voiceId);
    formData.append('rate', '1.0');
    formData.append('filename', newFilename);
    
    try {
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
            
            if (filename) {
                scene.audio_file = filename;
                await saveSceneAudioToDB(currentSceneId, filename, textContent);
                createAudioOverlayPlayerUI(currentSceneId, filename);
                createCurrentSceneAudioPlayer(currentSceneId, filename);
                alert(`✅ Audio file generated successfully!`);
            }
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Network error: ' + err.message);
    }
}

// Update music display in overlay
function updateAudioOverlayMusicDisplay() {
    const musicInfo = document.getElementById('audioOverlayMusicFileName');
    const musicPlayer = document.getElementById('audioOverlayMusicPlayerContainer');
    const audioPlayer = document.getElementById('audioOverlayMusicPlayer');
    
    if (!musicInfo || !musicPlayer || !audioPlayer) return;
    
    if (podcastMusicFile) {
        musicInfo.innerHTML = podcastMusicFile;
        musicPlayer.style.display = 'block';
        audioPlayer.src = 'podcast_music/' + podcastMusicFile + '?t=' + Date.now();
        audioPlayer.load();
    } else {
        musicInfo.innerHTML = 'No music selected';
        musicPlayer.style.display = 'none';
    }
}
// Open music library from podcast overlay
// Open music library from overlay
function openMusicLibraryFromOverlay() {
    console.log('Opening music library from overlay');
    
    // Close the podcast audio overlay
    closeOverlay('podcastAudioOverlay');
    
    // Make sure the music library modal exists
    let musicModal = document.getElementById('musicLibraryModal');
    
    if (!musicModal) {
        console.log('Music library modal not found, creating it...');
        // Create the modal if it doesn't exist
        createMusicLibraryModal();
        musicModal = document.getElementById('musicLibraryModal');
    }
    
    // Open the music library
    if (musicModal) {
        musicModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Reset selection
        selectedMusicFile = null;
        const selectBtn = document.getElementById('selectMusicBtn');
        const musicInfo = document.getElementById('musicSelInfo');
        const searchInput = document.getElementById('musicSearchInput');
        
        if (selectBtn) {
            selectBtn.disabled = true;
            selectBtn.style.opacity = '0.5';
        }
        if (musicInfo) musicInfo.innerText = 'No file selected';
        if (searchInput) searchInput.value = '';
        
        // Load music files
        loadMusicLibrary();
    } else {
        console.error('Failed to create music library modal');
        alert('Could not open music library');
    }
}

// Create music library modal if it doesn't exist
function createMusicLibraryModal() {
    console.log('Creating music library modal');
    
    const modalHTML = `
    <div id="musicLibraryModal" class="modal-overlay" style="z-index: 10002; display: none;">
        <div class="modal-content" style="max-width: 800px; width: 90%;">
            <div class="modal-header">
                <h3><span style="margin-right: 8px;">🎵</span> Music Library</h3>
                <button class="modal-close" onclick="closeMusicLibrary()">✕</button>
            </div>
            
            <div class="modal-search">
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="musicSearchInput" placeholder="Search by filename..." onkeyup="filterMusicFiles()" style="flex: 1; padding: 12px; border: 2px solid var(--border); border-radius: 30px; font-size: 14px;">
                    <span id="musicCount" style="padding: 12px; color: var(--muted);">0 files</span>
                </div>
            </div>
            
            <div class="modal-body" style="padding: 16px; max-height: 60vh; overflow-y: auto;">
                <div id="musicGrid" class="music-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                    <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">Loading music library...</div>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px;">
                <div id="musicSelInfo" style="font-size: 13px; color: var(--muted);">No file selected</div>
                <div style="display: flex; gap: 12px;">
                    <button class="panel-btn" onclick="closeMusicLibrary()" style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px;">Cancel</button>
                    <button id="selectMusicBtn" class="panel-btn" onclick="selectMusicForPodcast()" style="background: var(--success); color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;" disabled>Use Selected Music</button>
                </div>
            </div>
        </div>
    </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Update closeMusicLibrary function with null checks
function closeMusicLibrary() {
    console.log('Closing music library');
    
    const modal = document.getElementById('musicLibraryModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    
    // Stop any playing audio
    const audio = document.getElementById('musicPreviewAudio');
    if (audio) {
        audio.pause();
        audio.remove();
    }
}

// Upload background music from podcast overlay
function uploadBackgroundMusicFromOverlay() {
    console.log('Uploading background music from overlay');
    
    // Close the podcast audio overlay
    closeOverlay('podcastAudioOverlay');
    
    // Trigger the music upload input
    document.getElementById('musicUploadInput').click();
}

// Preview selected voice in podcast overlay
function previewSelectedVoiceOverlay() {
    const voiceSelect = document.getElementById('podcastAudioOverlayVoiceSelect');
    if (!voiceSelect) return;
    
    const selectedOption = voiceSelect.options[voiceSelect.selectedIndex];
    
    if (!voiceSelect.value) {
        alert('Please select a voice first');
        return;
    }
    
    // Get sample URL from data attribute
    let sampleUrl = selectedOption.getAttribute('data-sample');
    
    if (!sampleUrl) {
        alert('No sample available for this voice');
        return;
    }
    
    console.log('Playing voice sample:', sampleUrl);
    
    const audio = document.getElementById('sampleAudioPlayer');
    if (audio) {
        audio.src = sampleUrl;
        audio.play().catch(err => {
            console.error('Playback error:', err);
            alert('Could not play sample. The audio file may be unavailable.');
        });
    }
}
// Preview voice in overlay
function previewSelectedVoiceOverlay() {
    const voiceSelect = document.getElementById('audioOverlayVoiceSelect');
    if (!voiceSelect) return;
    
    const selectedOption = voiceSelect.options[voiceSelect.selectedIndex];
    
    if (!voiceSelect.value) {
        alert('Please select a voice first');
        return;
    }
    
    let sampleUrl = selectedOption.getAttribute('data-sample');
    
    if (!sampleUrl) {
        alert('No sample available for this voice');
        return;
    }
    
    const audio = document.getElementById('sampleAudioPlayer');
    audio.src = sampleUrl;
    audio.play().catch(err => alert('Could not play sample.'));
}

// Play podcast voice sample in overlay
function playPodcastVoiceSampleOverlay() {
    previewSelectedVoiceOverlay();
}

// Clear background music in overlay
function clearBackgroundMusicOverlay() {
    if (!confirm('Remove background music from this podcast?')) return;
    
    podcastMusicFile = '';
    updateAudioOverlayMusicDisplay();
    saveMusicToDatabase('');
}

// Open music library from overlay
function openMusicLibraryOverlay() {
    closeOverlay('audioOverlay');
    openMusicLibrary();
}

// Upload background music from overlay
function uploadBackgroundMusicOverlay() {
    closeOverlay('audioOverlay');
    document.getElementById('musicUploadInput').click();
}

// Generate all audio with selected voice from overlay
async function generateAllAudioWithSelectedVoiceOverlay() {
    if (!scenes.length) {
        alert('No scenes loaded');
        return;
    }

    const voiceSelect = document.getElementById('audioOverlayVoiceSelect');
    if (!voiceSelect || !voiceSelect.value) {
        alert('Please select a voice first');
        return;
    }

    if (!confirm("Generate audio for ALL scenes using the selected voice?")) return;

    closeOverlay('audioOverlay');
    
    // Call the existing function
    await generateAllAudioWithSelectedVoice();
}

// ========== AUDIO ICON FUNCTIONS ==========

// Show audio secondary icons (replaces primary)
// Show audio secondary icons (replaces primary)
function showAudioIcons() {
    console.log('Audio editing mode activated');
    
    // Close any scene settings panel if open
    const settingsPanel = document.getElementById('sceneSettingsPanel');
    if (settingsPanel) {
        settingsPanel.classList.remove('active');
    }
    
    // Force hide all scene-setting panels
    document.querySelectorAll('.scene-setting').forEach(panel => {
        panel.classList.add('hidden');
    });
    
    // Reset currentOpenPanel if it exists
    if (typeof currentOpenPanel !== 'undefined') {
        window.currentOpenPanel = null;
    }
    
    // Hide primary icons
    const primaryIcons = document.getElementById('primaryIcons');
    if (primaryIcons) {
        primaryIcons.style.display = 'none';
    }
    
    // Hide text icons if they're visible
    const textIcons = document.getElementById('textIcons');
    if (textIcons) {
        textIcons.style.display = 'none';
    }
    
    // Get the audio icons container
    const audioIcons = document.getElementById('audioIcons');
    if (audioIcons) {
        // Position it at the same place as primary icons were
        audioIcons.style.display = 'flex';
        audioIcons.style.position = 'absolute';
        audioIcons.style.top = '20px';
        audioIcons.style.left = '20px';
        audioIcons.style.zIndex = '1000';
        audioIcons.style.pointerEvents = 'auto';
    }
    
    // Close any open overlays
    closeAllOverlays();
}

// Hide audio icons and show primary
function hideAudioIcons() {
    console.log('Exiting audio editing mode');
    
    // Show primary icons
    const primaryIcons = document.getElementById('primaryIcons');
    if (primaryIcons) {
        primaryIcons.style.display = 'flex';
    }
    
    // Hide audio icons
    const audioIcons = document.getElementById('audioIcons');
    if (audioIcons) {
        audioIcons.style.display = 'none';
    }
    
    // Close any open overlays
    closeAllOverlays();
}


// Show current scene audio overlay
function showCurrentSceneAudioOverlay(event) {
    console.log('Showing current scene audio overlay');
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    closeAllOverlays();
    
    const overlay = document.getElementById('currentSceneAudioOverlay');
    const icon = event?.currentTarget;
    
    positionOverlayNearIcon(overlay, icon, 340, 500);
    
    // Load current scene audio data
    loadCurrentSceneAudioData(currentSceneId);
}

// Load current scene audio data
function loadCurrentSceneAudioData(sceneId) {
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    console.log('Loading audio data for scene:', sceneId, scene);
    
    // Update scene text display
    const textDisplay = document.getElementById('currentSceneAudioTextDisplay');
    if (textDisplay) {
        const currentText = scene.text_contents || '';
        
        // Make it editable with a textarea
        textDisplay.innerHTML = `
            <textarea id="currentSceneAudioText" 
                style="width: 100%; min-height: 80px; padding: 10px; border: 2px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13px; resize: vertical; background: white; color: var(--text);"
                placeholder="Enter text for this scene...">${escapeHtml(currentText)}</textarea>
            <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                <span id="currentSceneVoiceInfo" style="font-size: 11px; color: var(--info); background: #e0f2fe; padding: 2px 8px; border-radius: 12px;">
                    🎤 Voice: ${scene.voice_id || 'Not set'}
                </span>
                <span id="currentSceneTextCharCount" style="font-size: 11px; color: var(--muted);">${currentText.length} characters</span>
            </div>
        `;
        
        // Add character counter and auto-save
        const textarea = document.getElementById('currentSceneAudioText');
        if (textarea) {
            textarea.addEventListener('input', function() {
                const count = this.value.length;
                document.getElementById('currentSceneTextCharCount').innerText = count + ' characters';
                
                clearTimeout(window.currentSceneAudioTimeout);
                window.currentSceneAudioTimeout = setTimeout(() => {
                    saveCurrentSceneAudioText(sceneId, this.value);
                }, 1000);
            });
        }
    }
    
    // Create audio player if audio file exists
    const audioContainer = document.getElementById('currentSceneAudioPlayerContainer');
    if (audioContainer) {
        if (scene.audio_file) {
            console.log('Creating audio player for:', scene.audio_file);
            createCurrentSceneAudioPlayer(sceneId, scene.audio_file);
        } else {
            audioContainer.innerHTML = '<div style="color:var(--muted); text-align:center; padding:20px; background: #f8fafc; border-radius: 8px;">🎵 No audio generated for this scene yet</div>';
        }
    }
}

// Create audio player for current scene
function createCurrentSceneAudioPlayer(sceneId, filename) {
    const container = document.getElementById('currentSceneAudioPlayerContainer');
    if (!container) return;

    const existingAudio = document.getElementById('current_scene_audio_' + sceneId);
    if (existingAudio) {
        existingAudio.pause();
        existingAudio.removeAttribute('src');
        existingAudio.load();
        existingAudio.remove();
    }

    console.log('Creating player for:', filename);
    
    container.innerHTML = '';
    
    const playerContainer = document.createElement('div');
    playerContainer.style.cssText = 'display:flex; align-items:center; gap:12px; background:#f8fafc; border-radius:60px; padding:8px 16px; border:1px solid var(--border);';
    
    const playBtn = document.createElement('button');
    playBtn.style.cssText = 'width:44px; height:44px; background:var(--purple); color:white; border:none; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;';
    playBtn.id = 'current_scene_play_btn_' + sceneId;
    playBtn.innerHTML = '▶';
    
    const timeDisplay = document.createElement('span');
    timeDisplay.style.cssText = 'font-size:12px; color:var(--muted); min-width:80px; text-align:center;';
    timeDisplay.id = 'current_scene_time_' + sceneId;
    timeDisplay.innerText = '0:00 / 0:00';
    
    const progressContainer = document.createElement('div');
    progressContainer.style.cssText = 'flex-grow:1; height:6px; background:var(--border); border-radius:3px; cursor:pointer; position:relative;';
    progressContainer.id = 'current_scene_progress_container_' + sceneId;
    
    const progressFill = document.createElement('div');
    progressFill.style.cssText = 'height:100%; background:var(--purple); border-radius:3px; width:0%;';
    progressFill.id = 'current_scene_progress_fill_' + sceneId;
    progressContainer.appendChild(progressFill);
    
    const audio = document.createElement('audio');
    audio.id = 'current_scene_audio_' + sceneId;
    audio.src = 'podcast_audios/' + filename + '?t=' + Date.now();
    audio.preload = 'metadata';
    
    // Set up audio event listeners
    audio.onloadedmetadata = () => {
        const duration = audio.duration;
        timeDisplay.innerText = '0:00 / ' + formatTime(duration);
        console.log('Audio loaded, duration:', duration);
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
        progressFill.style.width = '0%';
        timeDisplay.innerText = '0:00 / ' + formatTime(audio.duration);
    };
    
    audio.onplay = () => {
        playBtn.innerHTML = '⏸';
    };
    
    audio.onpause = () => {
        playBtn.innerHTML = '▶';
    };
    
    audio.onerror = (e) => {
        console.error('Audio error:', e);
        container.innerHTML = '<div style="color:var(--error); text-align:center; padding:20px;">❌ Error loading audio file</div>';
    };
    
    // Play/pause on button click
    playBtn.onclick = () => {
        if (audio.paused) {
            audio.play().catch(err => {
                console.error('Playback error:', err);
                alert('Could not play audio. File may be missing or corrupted.');
            });
        } else {
            audio.pause();
        }
    };
    
    // Seek on progress bar click
    progressContainer.onclick = (e) => {
        const rect = progressContainer.getBoundingClientRect();
        const clickX = e.clientX - rect.left;
        const width = rect.width;
        const percent = clickX / width;
        audio.currentTime = percent * audio.duration;
    };
    
    playerContainer.appendChild(playBtn);
    playerContainer.appendChild(timeDisplay);
    playerContainer.appendChild(progressContainer);
    playerContainer.appendChild(audio);
    
    container.appendChild(playerContainer);
}

// Save current scene audio text
async function saveCurrentSceneAudioText(sceneId, text) {
    if (!sceneId || !text) return;
    
    const scene = scenes.find(s => s.id == sceneId);
    if (!scene) return;
    
    if (scene.text_contents === text) return;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_text');
        fd.append('scene_id', sceneId);
        fd.append('prompt', text);
        fd.append('prompt_field', 'text_contents');
        
        const {data} = await safeFetch(fd);
        if (data.success) {
            scene.text_contents = text;
            console.log('Text saved for scene', sceneId);
        }
    } catch(e) {
        console.error('Error saving text:', e);
    }
}



// Show podcast-wide audio overlay
function showPodcastAudioOverlay(event) {
    console.log('Showing podcast-wide audio overlay');
    
    if (!currentSceneId) {
        alert('No scene selected');
        return;
    }
    
    closeAllOverlays();
    
    const overlay = document.getElementById('podcastAudioOverlay');
    const icon = event?.currentTarget;
    
    positionOverlayNearIcon(overlay, icon, 330, 500);
    
    // Load podcast music data
    loadPodcastAudioOverlayData();
}

// Helper function to position overlay near icon
function positionOverlayNearIcon(overlay, icon, width, height) {
    if (!overlay) return;
    
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + width > canvasRect.width) {
            left = canvasRect.width - width - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + height > canvasRect.height) {
            top = canvasRect.height - height - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    } else {
        overlay.style.position = 'fixed';
        overlay.style.top = '50%';
        overlay.style.left = '50%';
        overlay.style.transform = 'translate(-50%, -50%)';
    }
    
    overlay.style.display = 'block';
    overlay.style.zIndex = '10000';
}

// Test function to open music library directly
function testOpenMusicLibrary() {
    console.log('Test opening music library');
    const modal = document.getElementById('musicLibraryModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        loadMusicLibrary();
    } else {
        console.error('Music library modal not found!');
        alert('Modal not found. Please check the HTML.');
    }
}
// Load podcast audio overlay data
function loadPodcastAudioOverlayData() {
    console.log('Loading podcast audio overlay data');
    
    // Update voice dropdown to match the main one if it exists
    const mainVoiceSelect = document.getElementById('podcastVoiceSelect');
    const overlayVoiceSelect = document.getElementById('podcastAudioOverlayVoiceSelect');
    
    if (mainVoiceSelect && overlayVoiceSelect && mainVoiceSelect.value) {
        // Sync the selected value
        overlayVoiceSelect.value = mainVoiceSelect.value;
    }
    
    // Update music display
    updatePodcastAudioMusicDisplay();
}
// Update music display in podcast overlay
function updatePodcastAudioMusicDisplay() {
    console.log('Updating podcast audio music display');
    console.log('podcastMusicFile =', podcastMusicFile);
    
    const musicInfo = document.getElementById('podcastAudioMusicFileName');
    const musicPlayer = document.getElementById('podcastAudioMusicPlayerContainer');
    const audioPlayer = document.getElementById('podcastAudioMusicPlayer');
    
    if (!musicInfo) {
        console.log('Music info element not found');
        return;
    }
    
    if (!musicPlayer) {
        console.log('Music player container not found');
        return;
    }
    
    if (!audioPlayer) {
        console.log('Audio player element not found');
        return;
    }
    
    if (podcastMusicFile && podcastMusicFile.trim() !== '') {
        musicInfo.innerHTML = podcastMusicFile;
        musicPlayer.style.display = 'block';
        audioPlayer.src = 'podcast_music/' + podcastMusicFile + '?t=' + Date.now();
        audioPlayer.load();
        console.log('Music display updated with:', podcastMusicFile);
    } else {
        musicInfo.innerHTML = 'No music selected';
        musicPlayer.style.display = 'none';
        console.log('No music selected');
    }
}

function debugModalScroll() {
    const modal = document.getElementById('musicLibraryModal');
    const body = modal.querySelector('.modal-body');
    const grid = document.getElementById('musicGrid');
    
    console.log('===== MODAL DEBUG =====');
    console.log('Modal display:', modal.style.display);
    console.log('Modal classes:', modal.className);
    
    console.log('\nBody element:', body);
    if (body) {
        const styles = window.getComputedStyle(body);
        console.log('Body overflow-y:', styles.overflowY);
        console.log('Body max-height:', styles.maxHeight);
        console.log('Body height:', styles.height);
        console.log('Body clientHeight:', body.clientHeight);
        console.log('Body scrollHeight:', body.scrollHeight);
    }
    
    console.log('\nGrid children:', grid ? grid.children.length : 0);
    if (grid) {
        console.log('Grid height:', grid.clientHeight);
        console.log('Grid parent height:', grid.parentElement.clientHeight);
    }
    
    // Force fix
    if (body) {
        body.style.overflowY = 'auto';
        body.style.maxHeight = '50vh';
        console.log('\n✅ Applied force fix to body');
    }
}
// Function to handle opening overlays from secondary icons
function openOverlayFromSecondary(overlayId, event) {
    console.log('Opening overlay:', overlayId);
    
    // Close all other overlays first
    closeAllOverlays();
    
    // Get the overlay element
    const overlay = document.getElementById(overlayId);
    const icon = event?.currentTarget;
    
    if (!overlay) return;
    
    // HIDE THE SECONDARY ICONS when overlay opens
    const textIcons = document.getElementById('textIcons');
    if (textIcons) {
        textIcons.style.display = 'none';
    }
    
    // Position the overlay near the icon
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Default overlay dimensions (adjust based on overlay type)
        let overlayWidth = 280;
        let overlayHeight = 400;
        
        // Adjust size for specific overlays
        if (overlayId === 'effectsPanel') overlayHeight = 500;
        if (overlayId === 'positionPanel') overlayHeight = 480;
        if (overlayId === 'animationPanel') overlayHeight = 380;
        if (overlayId === 'imageSourcePanel') overlayHeight = 250;
        
        let left = iconRect.left - canvasRect.left + 50;
        
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
}









// Update closeOverlay function

// Handle font size slider
function handleFontSizeSlider(value) {
    const size = parseInt(value);
    updateFontSizeDisplay(size);
    highlightActiveSizePreset(size);
    
    // Apply to caption if selected
    if (captionText) {
        captionText.set('fontSize', size);
        fabricCanvas.renderAll();
    }
}

// Set font size value
function setFontSizeValue(size) {
    updateFontSizeDisplay(size);
    highlightActiveSizePreset(size);
    
    // Update slider
    const slider = document.getElementById('fontSizeSlider');
    if (slider) slider.value = size;
    
    // Apply to caption if selected
    if (captionText) {
        captionText.set('fontSize', size);
        fabricCanvas.renderAll();
        
        // Save to database
        if (captionText.captionId) {
            saveCaptionToDatabase(captionText.captionId, { fontsize: size });
        }
    }
}

// Decrease font size
function decreaseFontSize() {
    console.log('decreaseFontSize called');
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    const currentSize = captionText.fontSize || 36;
    const newSize = Math.max(8, currentSize - 1);
    
    // Update display
    updateFontSizeDisplay(newSize);
    highlightActiveSizePreset(newSize);
    
    // Update slider
    const slider = document.getElementById('fontSizeSlider');
    if (slider) slider.value = newSize;
    
    // Apply to caption
    captionText.set('fontSize', newSize);
    fabricCanvas.renderAll();
    
    // Save to database
    if (captionText.captionId) {
        // Debounce save
        clearTimeout(window.fontSizeTimeout);
        window.fontSizeTimeout = setTimeout(() => {
            saveCaptionToDatabase(captionText.captionId, { fontsize: newSize });
        }, 500);
    }
}

// Increase font size
function increaseFontSize() {
    console.log('increaseFontSize called');
    if (!captionText) {
        console.warn('No caption selected');
        return;
    }
    
    const currentSize = captionText.fontSize || 36;
    const newSize = Math.min(120, currentSize + 1);
    
    // Update display
    updateFontSizeDisplay(newSize);
    highlightActiveSizePreset(newSize);
    
    // Update slider
    const slider = document.getElementById('fontSizeSlider');
    if (slider) slider.value = newSize;
    
    // Apply to caption
    captionText.set('fontSize', newSize);
    fabricCanvas.renderAll();
    
    // Save to database
    if (captionText.captionId) {
        clearTimeout(window.fontSizeTimeout);
        window.fontSizeTimeout = setTimeout(() => {
            saveCaptionToDatabase(captionText.captionId, { fontsize: newSize });
        }, 500);
    }
}

// Update font size display
function updateFontSizeDisplay(size) {
    const display = document.getElementById('fontSizeNumber');
    const slider = document.getElementById('fontSizeSlider');
    
    if (display) display.textContent = size;
    if (slider) slider.value = size;
}

// Highlight active size preset
function highlightActiveSizePreset(size) {
    const presets = document.querySelectorAll('#fontSizePanel .size-preset');
    presets.forEach(btn => {
        const btnSize = parseInt(btn.textContent);
        if (btnSize === size) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

// Show font size panel
function showFontSizePanel(event) {
    console.log('========== FONT SIZE PANEL DEBUG ==========');
    console.log('1. Function called');
    console.log('2. Event object:', event);
    
    // Store current mode
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    
    console.log('3. Primary icons display:', primaryIcons?.style.display);
    console.log('4. Text icons display:', textIcons?.style.display);
    
    // Store which mode we're in
    window.lastMode = 'primary';
    if (primaryIcons && primaryIcons.style.display === 'none') {
        if (textIcons && textIcons.style.display === 'flex') {
            window.lastMode = 'text';
            console.log('5. Last mode set to: text');
        }
    } else {
        console.log('5. Last mode set to: primary');
    }
    
    // Close any other overlays first
    closeAllOverlays();
    console.log('6. Closed all overlays');
    
    // HIDE SECONDARY ICONS
    if (textIcons) {
        textIcons.style.display = 'none';
        console.log('7. Text icons hidden');
    }
    
    const overlay = document.getElementById('fontSizePanel');
    const icon = event?.currentTarget;
    
    console.log('8. Overlay element:', overlay);
    console.log('9. Icon element:', icon);
    
    if (!overlay) {
        console.log('10. ERROR: Overlay not found!');
        return;
    }
    
    // Set current size from selected caption
    if (captionText) {
        const currentSize = captionText.fontSize || 36;
        updateFontSizeDisplay(currentSize);
        highlightActiveSizePreset(currentSize);
        console.log('11. Set current size to:', currentSize);
    }
    
    // Position the overlay
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        console.log('12. Icon position:', iconRect);
        console.log('13. Canvas position:', canvasRect);
        
        const overlayWidth = 280;
        const overlayHeight = 500;
        
        // Calculate position - 50px to the right of icon
        let left = iconRect.left - canvasRect.left + 50;
        console.log('14. Calculated left (before boundary check):', left);
        
        // Boundary checks
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
            console.log('15. Adjusted left (right boundary):', left);
        }
        
        if (left < 10) {
            left = 10;
            console.log('16. Adjusted left (left boundary):', left);
        }
        
        // Align with top of icon
        let top = iconRect.top - canvasRect.top;
        console.log('17. Calculated top (before boundary check):', top);
        
        // Don't go off bottom
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
            console.log('18. Adjusted top (bottom boundary):', top);
        }
        
        // Don't go off top
        if (top < 10) {
            top = 10;
            console.log('19. Adjusted top (top boundary):', top);
        }
        
        console.log('20. FINAL POSITION:', { left, top });
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    } else {
        console.log('10. No icon found, using default position');
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    console.log('21. Overlay displayed');
    
    // Prevent event propagation
    setTimeout(() => {
        overlay.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }, 100);
    
    console.log('========== END DEBUG ==========');
}

// ========== REUSABLE OVERLAY FUNCTIONS ==========

// Store current mode when overlay opens
let currentOverlayMode = null;

// Function to show any overlay with proper icon handling
function showOverlay(overlayId, event) {
    console.log(`🔍 Showing overlay: ${overlayId}`);
    
    // Store which mode we're in before hiding icons
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    const audioIcons = document.getElementById('audioIcons');
    
    // Store current mode
    if (primaryIcons && primaryIcons.style.display === 'flex') {
        currentOverlayMode = 'primary';
        console.log('📱 Stored mode: primary');
    } else if (textIcons && textIcons.style.display === 'flex') {
        currentOverlayMode = 'text';
        console.log('📱 Stored mode: text');
    } else if (audioIcons && audioIcons.style.display === 'flex') {
        currentOverlayMode = 'audio';
        console.log('📱 Stored mode: audio');
    }
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE ALL SECONDARY ICONS
    if (textIcons) textIcons.style.display = 'none';
    if (audioIcons) audioIcons.style.display = 'none';
    // Primary icons remain as they were
    
    // Get the overlay element
    const overlay = document.getElementById(overlayId);
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error(`❌ Overlay not found: ${overlayId}`);
        return;
    }
    
    // Position the overlay near the icon
    if (icon) {
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Default overlay dimensions (adjust per overlay if needed)
        let overlayWidth = 280;
        let overlayHeight = 400;
        
        // Adjust size for specific overlays
        if (overlayId === 'effectsPanel') overlayHeight = 500;
        if (overlayId === 'positionPanel') overlayHeight = 480;
        if (overlayId === 'animationPanel') overlayHeight = 380;
        if (overlayId === 'imageSourcePanel') overlayHeight = 250;
        if (overlayId === 'bgColorPanel') overlayHeight = 400;
        if (overlayId === 'fontFamilyPanel') overlayHeight = 400;
        if (overlayId === 'fontSizePanel') overlayHeight = 500;
        
        let left = iconRect.left - canvasRect.left + 50;
        
        // Boundary checks
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        if (left < 10) left = 10;
        
        let top = iconRect.top - canvasRect.top;
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    // Prevent overlay from closing when clicking inside
    overlay.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}
// ========== SHADOW EFFECT FUNCTIONS ==========
function updateShadow() {
    if (!captionText || !document.getElementById('shadowEnable').checked) return;
    
    const color = document.getElementById('shadowColor').value;
    const blur = parseInt(document.getElementById('shadowBlur').value);
    const offsetX = parseInt(document.getElementById('shadowOffsetX').value);
    const offsetY = parseInt(document.getElementById('shadowOffsetY').value);
    
    // Update display values
    document.getElementById('shadowBlurValue').textContent = blur;
    document.getElementById('shadowOffsetXValue').textContent = offsetX;
    document.getElementById('shadowOffsetYValue').textContent = offsetY;
    
    // Create shadow object
    captionText.set('shadow', {
        color: color,
        blur: blur,
        offsetX: offsetX,
        offsetY: offsetY,
        affectStroke: true,
        nonScaling: true
    });
    
    fabricCanvas.renderAll();
    
    // Debounce save
    clearTimeout(window.shadowTimeout);
    window.shadowTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            shadow_enabled: 1,
            shadow_color: color,
            shadow_blur: blur,
            shadow_offsetX: offsetX,
            shadow_offsetY: offsetY
        });
    }, 500);
}

// ========== GLOW EFFECT FUNCTIONS ==========
function updateGlow() {
    if (!captionText || !document.getElementById('glowEnable').checked) return;
    
    const color = document.getElementById('glowColor').value;
    const blur = parseInt(document.getElementById('glowBlur').value);
    
    document.getElementById('glowBlurValue').textContent = blur;
    
    // Glow is essentially a shadow with no offset
    captionText.set('shadow', {
        color: color,
        blur: blur,
        offsetX: 0,
        offsetY: 0,
        affectStroke: true
    });
    
    fabricCanvas.renderAll();
    
    clearTimeout(window.glowTimeout);
    window.glowTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            glow_enabled: 1,
            glow_color: color,
            glow_blur: blur
        });
    }, 500);
}

// ========== GRADIENT EFFECT FUNCTIONS ==========
function updateGradient() {
    if (!captionText || !document.getElementById('gradientEnable').checked) return;
    
    const color1 = document.getElementById('gradientColor1').value;
    const color2 = document.getElementById('gradientColor2').value;
    const direction = document.getElementById('gradientDirection').value;
    
    // Get text dimensions
    const width = captionText.width || 200;
    const height = captionText.height || 100;
    
    // Set gradient coordinates based on direction
    let x1 = 0, y1 = 0, x2 = 0, y2 = 0;
    
    switch(direction) {
        case 'left-to-right':
            x1 = 0; y1 = 0; x2 = width; y2 = 0;
            break;
        case 'right-to-left':
            x1 = width; y1 = 0; x2 = 0; y2 = 0;
            break;
        case 'top-to-bottom':
            x1 = 0; y1 = 0; x2 = 0; y2 = height;
            break;
        case 'bottom-to-top':
            x1 = 0; y1 = height; x2 = 0; y2 = 0;
            break;
        case 'diagonal':
            x1 = 0; y1 = 0; x2 = width; y2 = height;
            break;
    }
    
    // Apply gradient
    captionText.set('fill', new fabric.Gradient({
        type: 'linear',
        coords: { x1: x1, y1: y1, x2: x2, y2: y2 },
        colorStops: [
            { offset: 0, color: color1 },
            { offset: 1, color: color2 }
        ]
    }));
    
    fabricCanvas.renderAll();
    
    clearTimeout(window.gradientTimeout);
    window.gradientTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            gradient_enabled: 1,
            gradient_color1: color1,
            gradient_color2: color2,
            gradient_direction: direction
        });
    }, 500);
}

// ========== 3D EFFECT FUNCTIONS ==========
function updateEffect3D() {
    if (!captionText || !document.getElementById('effect3DEnable').checked) return;
    
    const depthColor = document.getElementById('effect3DColor').value;
    const depth = parseInt(document.getElementById('effect3DDepth').value);
    
    document.getElementById('effect3DDepthValue').textContent = depth;
    
    // 3D effect using multiple shadows
    const shadows = [];
    
    // Create depth shadows
    for (let i = 1; i <= depth; i++) {
        shadows.push(new fabric.Shadow({
            color: depthColor,
            blur: 2,
            offsetX: i,
            offsetY: i
        }));
    }
    
    // For now, just use the last shadow (simplified)
    captionText.set('shadow', {
        color: depthColor,
        blur: 2,
        offsetX: depth,
        offsetY: depth
    });
    
    fabricCanvas.renderAll();
    
    clearTimeout(window.effect3DTimeout);
    window.effect3DTimeout = setTimeout(() => {
        saveCaptionToDatabase(captionText.captionId, {
            effect3d_enabled: 1,
            effect3d_color: depthColor,
            effect3d_depth: depth
        });
    }, 500);
}

// ========== UPDATED TOGGLE EFFECT FUNCTION ==========
function toggleEffect(effect) {
    if (!captionText) {
        console.warn('No caption selected');
        
        // Uncheck if no caption selected
        const checkbox = document.getElementById(effect + 'Enable');
        if (checkbox) checkbox.checked = false;
        return;
    }
    
    const isEnabled = document.getElementById(effect + 'Enable').checked;
    const controls = document.getElementById(effect + 'Controls');
    
    if (controls) {
        controls.style.display = isEnabled ? 'block' : 'none';
    }
    
    // When enabling an effect, disable conflicting ones
    if (isEnabled) {
        // Disable conflicting effects based on type
        if (effect === 'shadow' || effect === 'glow') {
            // Shadow and glow conflict with each other
            if (effect === 'shadow') {
                document.getElementById('glowEnable').checked = false;
                const glowControls = document.getElementById('glowControls');
                if (glowControls) glowControls.style.display = 'none';
            } else if (effect === 'glow') {
                document.getElementById('shadowEnable').checked = false;
                const shadowControls = document.getElementById('shadowControls');
                if (shadowControls) shadowControls.style.display = 'none';
            }
        } else if (effect === 'gradient') {
            // Gradient conflicts with solid color
            // We'll handle this by setting fill to gradient
        } else if (effect === '3d') {
            // 3D uses shadows, so disable shadow and glow
            document.getElementById('shadowEnable').checked = false;
            document.getElementById('glowEnable').checked = false;
            const shadowControls = document.getElementById('shadowControls');
            const glowControls = document.getElementById('glowControls');
            if (shadowControls) shadowControls.style.display = 'none';
            if (glowControls) glowControls.style.display = 'none';
        }
    }
    
    // Call the appropriate update function
    switch(effect) {
        case 'shadow':
            if (isEnabled) updateShadow(); else removeShadow();
            break;
        case 'glow':
            if (isEnabled) updateGlow(); else removeGlow();
            break;
        case 'outline':
            if (isEnabled) updateOutline(); else removeOutline();
            break;
        case 'stroke':
            if (isEnabled) updateStroke(); else removeStroke();
            break;
        case 'gradient':
            if (isEnabled) updateGradient(); else removeGradient();
            break;
        case '3d':
            if (isEnabled) updateEffect3D(); else removeEffect3D();
            break;
    }
    
    updateActiveEffectsDisplay();
}

// ========== REMOVE EFFECT FUNCTIONS ==========
function removeShadow() {
    if (!captionText) return;
    captionText.set('shadow', null);
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { shadow_enabled: 0 });
}

function removeGlow() {
    if (!captionText) return;
    captionText.set('shadow', null);
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { glow_enabled: 0 });
}

function removeOutline() {
    if (!captionText) return;
    // If stroke is also disabled, remove stroke completely
    if (!document.getElementById('strokeEnable').checked) {
        captionText.set({
            stroke: null,
            strokeWidth: 0
        });
    }
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { outline_enabled: 0 });
}

function removeStroke() {
    if (!captionText) return;
    // If outline is also disabled, remove stroke completely
    if (!document.getElementById('outlineEnable').checked) {
        captionText.set({
            stroke: null,
            strokeWidth: 0
        });
    }
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { stroke_enabled: 0 });
}

function removeGradient() {
    if (!captionText) return;
    // Revert to solid color (use current font color from picker)
    const solidColor = document.getElementById('sceneFontColor').value || '#ffff00';
    captionText.set('fill', solidColor);
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { gradient_enabled: 0 });
}

function removeEffect3D() {
    if (!captionText) return;
    captionText.set('shadow', null);
    fabricCanvas.renderAll();
    saveCaptionToDatabase(captionText.captionId, { effect3d_enabled: 0 });
}

// ========== UPDATE ACTIVE EFFECTS DISPLAY ==========
function updateActiveEffectsDisplay() {
    const display = document.getElementById('activeEffects');
    
    const shadowEnabled = document.getElementById('shadowEnable')?.checked;
    const glowEnabled = document.getElementById('glowEnable')?.checked;
    const outlineEnabled = document.getElementById('outlineEnable')?.checked;
    const strokeEnabled = document.getElementById('strokeEnable')?.checked;
    const gradientEnabled = document.getElementById('gradientEnable')?.checked;
    const effect3DEnabled = document.getElementById('effect3DEnable')?.checked;
    
    let activeEffects = [];
    if (shadowEnabled) activeEffects.push('🌑 Shadow');
    if (glowEnabled) activeEffects.push('✨ Glow');
    if (outlineEnabled) activeEffects.push('🔲 Outline');
    if (strokeEnabled) activeEffects.push('✏️ Stroke');
    if (gradientEnabled) activeEffects.push('🌈 Gradient');
    if (effect3DEnabled) activeEffects.push('🎲 3D');
    
    if (activeEffects.length > 0) {
        display.innerHTML = activeEffects.join(' · ');
        display.style.background = '#e0f2fe';
        display.style.color = '#0369a1';
    } else {
        display.innerHTML = '✨ No effects active';
        display.style.background = '#f1f5f9';
        display.style.color = '#475569';
    }
}


// ========== UPDATED PLAY SCENE IN SEQUENCE WITH ANIMATIONS ==========
async function playSceneInSequence(index) {
    console.log(`\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
    console.log(`▶ playSceneInSequence called: index=${index}, total=${scenes.length}, isPlayingSequence=${isPlayingSequence}`);

    if (!isPlayingSequence || index >= scenes.length) {
        console.log(`🏁 END: isPlayingSequence=${isPlayingSequence}, index=${index}, total=${scenes.length}`);
        stopPlayback();
        return;
    }
    
    const scene = scenes[index];
    if (!scene) {
        console.log(`❌ No scene at index ${index}`);
        stopPlayback();
        return;
    }

    console.log(`🎬 Scene ${index + 1}/${scenes.length} | id=${scene.id} | audio=${scene.audio_file || 'NONE'} | image=${scene.image_file || 'NONE'}`);

    // Start background music on first scene - preload fully before playing
    if (index === 0 && podcastMusicFile) {
        console.log(`🎵 Starting background music: ${podcastMusicFile}`);
        // Remove any stale element first
        const oldBg = document.getElementById('preview-bg-music');
        if (oldBg) { oldBg.pause(); oldBg.remove(); }

        const bgMusic = document.createElement('audio');
        bgMusic.id = 'preview-bg-music';
        bgMusic.loop = false;
        bgMusic.volume = 0.4;
        bgMusic.preload = 'auto';
        document.body.appendChild(bgMusic);

        bgMusic.oncanplaythrough = () => {
            console.log(`🎵 Music buffered — playing`);
            bgMusic.oncanplaythrough = null;
            bgMusic.play().catch(e => console.warn(`🎵 Music play blocked:`, e.message));
        };
        bgMusic.onerror = () => {
            if (!bgMusic._triedRelative) {
                bgMusic._triedRelative = true;
                console.warn(`🎵 Absolute path failed, trying relative`);
                bgMusic.src = 'podcast_music/' + podcastMusicFile;
                bgMusic.load();
            } else {
                console.error(`🎵 Music failed to load: ${podcastMusicFile}`);
            }
        };
        bgMusic.onended = () => console.log(`🎵 Background music ended`);
        // Try absolute path first
        bgMusic.src = '/podcast_music/' + podcastMusicFile;
        bgMusic.load();
    }
    
    // Get audio file
    let audioFile = scene.audio_file;
    if (!audioFile && audio_files && audio_files[scene.id]) {
        audioFile = audio_files[scene.id];
        console.log(`🔄 Audio file from audio_files fallback: ${audioFile}`);
    }
    
    if (!audioFile) {
        console.warn(`⚠️ Scene ${index + 1} has NO audio file — skipping in 500ms`);
        setTimeout(() => playSceneInSequence(index + 1), 500);
        return;
    }

    console.log(`⏳ Loading scene ${index + 1} canvas...`);
    const sceneLoadStart = Date.now();

    // Load the scene — wrapped in setTimeout(0) to yield to the audio
    // thread first, preventing music stutter during heavy canvas ops
    currentSceneIndex = index;
    currentSceneId = scene.id;
    updateSceneIndicator();

    await new Promise(resolve => setTimeout(resolve, 0));

    if (fabricCanvas) {
        await loadCurrentSceneToFabric();
        console.log(`✅ Canvas loaded in ${Date.now() - sceneLoadStart}ms`);
        
        // Start animations for all captions in this scene
        setTimeout(() => {
            const objects = fabricCanvas.getObjects();
            const captionObjects = objects.filter(obj => obj.captionId);
            console.log(`🎞️ Starting animations for ${captionObjects.length} caption(s)`);
            captionObjects.forEach(obj => {
                const audioDuration = getAudioDuration(audioFile);
                startCaptionAnimation(obj, audioDuration);
            });
        }, 500);
    } else {
        console.warn(`⚠️ fabricCanvas is null — skipping canvas load`);
    }

    // Music mode: use scene audio duration as timer, don't play scene audio
    if (podcastMusicFile) {
        console.log(`🎵 Music mode — loading metadata for duration: ${audioFile}`);
        const dur = new Audio();
        let metadataLoaded = false;
        dur.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();
        dur.preload = 'metadata';
        dur.onloadedmetadata = () => {
            metadataLoaded = true;
            const ms = dur.duration * 1000;
            console.log(`⏱️ Scene ${index + 1} audio duration: ${dur.duration.toFixed(2)}s — timer set for ${ms}ms`);
            dur.onloadedmetadata = null;
            dur.onerror = null;
            dur.removeAttribute('src');
            setTimeout(() => {
                console.log(`⏰ Timer fired for scene ${index + 1} — advancing to scene ${index + 2}`);
                if (isPlayingSequence) {
                    stopCaptionAnimation();
                    playSceneInSequence(index + 1);
                } else {
                    console.warn(`⚠️ Timer fired but isPlayingSequence=false — aborting`);
                }
            }, ms);
        };
        dur.onerror = (e) => {
            if (metadataLoaded) return; // onloadedmetadata already handled it
            console.error(`❌ Failed to load audio metadata for scene ${index + 1}:`, e);
            console.log(`⏰ Falling back to 2s timer`);
            dur.onloadedmetadata = null;
            dur.onerror = null;
            stopCaptionAnimation();
            setTimeout(() => {
                if (isPlayingSequence) playSceneInSequence(index + 1);
            }, 2000);
        };
        dur.load();
        console.log(`🎵 Metadata load triggered — returning control`);
        return;
    }
    
    // No music — original behaviour: play scene audio
    console.log(`🔊 No music — playing scene audio: ${audioFile}`);
    let audioPlayer = document.getElementById(`sequence-audio-${scene.id}`);
    if (!audioPlayer) {
        audioPlayer = document.createElement('audio');
        audioPlayer.id = `sequence-audio-${scene.id}`;
        document.body.appendChild(audioPlayer);
    }
    
    audioPlayer.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();
    audioPlayer.load();
    
    currentAudioPlayer = audioPlayer;
    
    audioPlayer.onended = () => {
        console.log(`✅ Scene ${index + 1} audio ended — advancing`);
        stopCaptionAnimation();
        playSceneInSequence(index + 1);
    };
    
    audioPlayer.onerror = (e) => {
        console.error(`❌ Audio playback error scene ${index + 1}:`, e);
        stopCaptionAnimation();
        setTimeout(() => playSceneInSequence(index + 1), 500);
    };
    
    try {
        await audioPlayer.play();
        console.log(`🔊 Playing scene ${index + 1} audio`);
    } catch (err) {
        console.error(`❌ Playback error scene ${index + 1}:`, err);
        stopCaptionAnimation();
        setTimeout(() => playSceneInSequence(index + 1), 500);
    }
}

// Helper function to get audio duration
function getAudioDuration(audioFile) {
    // This would need to be implemented - you might store duration in DB
    return 5; // Default 5 seconds
}

function stopCaptionAnimation() {
    isPlayingAnimation = false;
    if (animationInterval) {
        clearInterval(animationInterval);
        animationInterval = null;
    }
    if (animationFrame) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
    }
    // Restore all caption objects to full text and opacity
    if (fabricCanvas) {
        fabricCanvas.getObjects().forEach(obj => {
            if (obj.captionId) {
                if (obj.originalText) obj.set('text', obj.originalText);
                obj.set({ opacity: 1, scaleX: 1, scaleY: 1 });
            }
        });
        fabricCanvas.renderAll();
    }
}

// ========== CAPTION ANIMATION DISPATCHER ==========
function startCaptionAnimation(captionObj) {
    if (!captionObj) return;

    stopCaptionAnimation();

    // Normalise — UI buttons use camelCase/old names, DB uses kebab-case
    const rawStyle = captionObj.animationStyle || 'none';
    const styleMap = {
        'wordReveal': 'word-reveal',
        'fade':       'fade-in',
        'slideUp':    'slide-up',
        'slideDown':  'slide-down',
        'zoom':       'zoom-in',
        'static':     'none'
    };
    const style = styleMap[rawStyle] || rawStyle;
    const speed = captionObj.animationSpeed || 1.0;
    const fullText = captionObj.originalText || captionObj.text;

    console.log('🎬 Caption animation:', style);
    isPlayingAnimation = true;

    switch (style) {
        case 'typewriter':
            captionObj.originalText = fullText;
            captionObj.set({ text: '', opacity: 1 });
            fabricCanvas.renderAll();
            startTypewriterAnimation(captionObj, 'character', speed);
            break;

        case 'word-reveal':
            captionObj.originalText = fullText;
            captionObj.set({ text: '', opacity: 1 });
            fabricCanvas.renderAll();
            startWordRevealAnimation(captionObj, speed);
            break;

        case 'fade-in':
            captionObj.set('opacity', 0);
            fabricCanvas.renderAll();
            startFadeInAnimation(captionObj, speed);
            break;

        case 'slide-up':
            startSlideAnimation(captionObj, 'up', speed);
            break;

        case 'slide-down':
            startSlideAnimation(captionObj, 'down', speed);
            break;

        case 'zoom-in':
            captionObj.set({ scaleX: 0.1, scaleY: 0.1, opacity: 0 });
            fabricCanvas.renderAll();
            startZoomInAnimation(captionObj, speed);
            break;

        case 'pop':
            startPopAnimation(captionObj, speed);
            break;

        case 'bounce':
            startBounceAnimation(captionObj, speed);
            break;

        case 'karaoke':
            startKaraokeAnimation(captionObj, speed);
            break;

        default:
            captionObj.set('opacity', 1);
            fabricCanvas.renderAll();
            break;
    }
}

// ========== TYPEWRITER ANIMATION ==========
function startTypewriterAnimation(captionObj, displayMode, speed) {
    const fullText = captionObj.originalText || captionObj.text;
    let currentText = '';
    let charIndex = 0;
    
    // Calculate delay based on speed (lower speed = faster)
    const baseDelay = 50; // ms per character
    const delay = baseDelay / speed;
    
    animationInterval = setInterval(() => {
        if (!isPlayingAnimation) {
            clearInterval(animationInterval);
            return;
        }
        
        if (charIndex <= fullText.length) {
            if (displayMode === 'word') {
                // Word by word
                const words = fullText.split(' ');
                currentText = words.slice(0, charIndex).join(' ');
                charIndex++;
            } else if (displayMode === 'line') {
                // Line by line (split by \n)
                const lines = fullText.split('\n');
                currentText = lines.slice(0, charIndex).join('\n');
                charIndex++;
            } else if (displayMode === 'character') {
                // Character by character
                currentText = fullText.substring(0, charIndex);
                charIndex++;
            } else {
                // Full text at once
                currentText = fullText;
                clearInterval(animationInterval);
            }
            
            captionObj.set('text', currentText);
            fabricCanvas.renderAll();
            
            if (charIndex > fullText.length) {
                clearInterval(animationInterval);
            }
        }
    }, delay);
}

// ========== WORD REVEAL ANIMATION ==========
function startWordRevealAnimation(captionObj, displayMode, speed) {
    const fullText = captionObj.text;
    const words = fullText.split(' ');
    let wordIndex = 0;
    
    const baseDelay = 200; // ms per word
    const delay = baseDelay / speed;
    
    // Hide text initially
    captionObj.set('opacity', 0);
    
    animationInterval = setInterval(() => {
        if (!isPlayingAnimation) {
            clearInterval(animationInterval);
            return;
        }
        
        if (wordIndex < words.length) {
            // Show words one by one with fade in
            const revealText = words.slice(0, wordIndex + 1).join(' ');
            captionObj.set('text', revealText);
            captionObj.animate('opacity', 1, {
                duration: 200,
                onChange: fabricCanvas.renderAll.bind(fabricCanvas)
            });
            wordIndex++;
        } else {
            clearInterval(animationInterval);
        }
    }, delay);
}

// ========== FADE IN ANIMATION ==========
function startFadeAnimation(captionObj, displayMode, speed) {
    // Start invisible
    captionObj.set('opacity', 0);
    fabricCanvas.renderAll();
    
    const duration = 1000 / speed; // ms
    
    captionObj.animate('opacity', 1, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
}

// ========== SLIDE UP ANIMATION ==========
function startSlideAnimation(captionObj, direction, displayMode, speed) {
    const originalTop = captionObj.top;
    const startTop = originalTop + 50; // Start 50px below
    
    captionObj.set('top', startTop);
    captionObj.set('opacity', 0);
    fabricCanvas.renderAll();
    
    const duration = 800 / speed; // ms
    
    captionObj.animate('top', originalTop, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
    
    captionObj.animate('opacity', 1, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
}

// ========== ZOOM IN ANIMATION ==========
function startZoomAnimation(captionObj, displayMode, speed) {
    captionObj.set('scaleX', 0.5);
    captionObj.set('scaleY', 0.5);
    captionObj.set('opacity', 0);
    fabricCanvas.renderAll();
    
    const duration = 600 / speed; // ms
    
    captionObj.animate('scaleX', 1, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
    
    captionObj.animate('scaleY', 1, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
    
    captionObj.animate('opacity', 1, {
        duration: duration,
        onChange: fabricCanvas.renderAll.bind(fabricCanvas)
    });
}

// ========== POP ANIMATION ==========
function startPopAnimation(captionObj, displayMode, speed) {
    const scaleStep = 0.2;
    let scale = 0.6;
    
    captionObj.set('scaleX', scale);
    captionObj.set('scaleY', scale);
    captionObj.set('opacity', 0);
    fabricCanvas.renderAll();
    
    const duration = 400 / speed; // ms
    const startTime = Date.now();
    
    function popStep() {
        if (!isPlayingAnimation) return;
        
        const elapsed = Date.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Pop effect: overshoot then settle
        let currentScale;
        if (progress < 0.7) {
            currentScale = 0.6 + (progress / 0.7) * 0.6; // Grow to 1.2
        } else {
            currentScale = 1.2 - ((progress - 0.7) / 0.3) * 0.2; // Settle to 1.0
        }
        
        captionObj.set('scaleX', currentScale);
        captionObj.set('scaleY', currentScale);
        captionObj.set('opacity', Math.min(progress * 1.5, 1));
        fabricCanvas.renderAll();
        
        if (progress < 1) {
            animationFrame = requestAnimationFrame(popStep);
        }
    }
    
    animationFrame = requestAnimationFrame(popStep);
}

// ========== BOUNCE ANIMATION ==========
function startBounceAnimation(captionObj, displayMode, speed) {
    const originalTop = captionObj.top;
    let bounceCount = 0;
    const maxBounces = 3;
    
    const duration = 600 / speed; // ms
    const bounceHeight = 30;
    
    function bounceStep() {
        if (!isPlayingAnimation || bounceCount >= maxBounces) return;
        
        // Bounce up
        captionObj.animate('top', originalTop - bounceHeight, {
            duration: duration / 2,
            onChange: fabricCanvas.renderAll.bind(fabricCanvas),
            onComplete: () => {
                // Bounce down
                captionObj.animate('top', originalTop, {
                    duration: duration / 2,
                    onChange: fabricCanvas.renderAll.bind(fabricCanvas),
                    onComplete: () => {
                        bounceCount++;
                        if (bounceCount < maxBounces) {
                            bounceStep();
                        }
                    }
                });
            }
        });
    }
    
    bounceStep();
}

// ========== KARAOKE HIGHLIGHT ANIMATION ==========
function startKaraokeAnimation(captionObj, displayMode, speed) {
    const fullText = captionObj.text;
    const words = fullText.split(' ');
    let wordIndex = 0;
    
    const baseDelay = 300; // ms per word
    const delay = baseDelay / speed;
    
    // Store original color
    const originalColor = captionObj.fill;
    
    animationInterval = setInterval(() => {
        if (!isPlayingAnimation) {
            clearInterval(animationInterval);
            return;
        }
        
        if (wordIndex < words.length) {
            // Create highlighted version
            const beforeHighlight = words.slice(0, wordIndex).join(' ');
            const currentWord = words[wordIndex];
            const afterHighlight = words.slice(wordIndex + 1).join(' ');
            
            // This requires a more complex approach - for now, just change color of entire text
            captionObj.set('fill', '#ffff00'); // Highlight color
            
            setTimeout(() => {
                captionObj.set('fill', originalColor);
            }, delay * 0.8);
            
            wordIndex++;
        } else {
            clearInterval(animationInterval);
        }
    }, delay);
}

// ========== WORD REVEAL ANIMATION ==========
function startWordRevealAnimation(captionObj, speed) {
    const fullText = captionObj.originalText || captionObj.text;
    const words = fullText.split(' ');
    let wordIndex = 0;
    const delay = 250 / speed;
    animationInterval = setInterval(() => {
        if (!isPlayingAnimation) { clearInterval(animationInterval); return; }
        if (wordIndex <= words.length) {
            captionObj.set({ text: words.slice(0, wordIndex).join(' '), opacity: 1 });
            fabricCanvas.renderAll();
            wordIndex++;
        } else {
            clearInterval(animationInterval);
            captionObj.set('text', fullText);
            fabricCanvas.renderAll();
        }
    }, delay);
}

// ========== FADE IN ANIMATION ==========
function startFadeInAnimation(captionObj, speed) {
    const duration = 1000 / speed;
    const startTime = performance.now();
    function step(now) {
        if (!isPlayingAnimation) return;
        const progress = Math.min((now - startTime) / duration, 1);
        captionObj.set('opacity', progress);
        fabricCanvas.renderAll();
        if (progress < 1) animationFrame = requestAnimationFrame(step);
    }
    animationFrame = requestAnimationFrame(step);
}

// ========== SLIDE ANIMATION ==========
function startSlideAnimation(captionObj, direction, speed) {
    const duration = 600 / speed;
    const startTime = performance.now();
    const finalTop  = captionObj.top;
    const finalLeft = captionObj.left;
    const offset = 80;
    const startTop  = direction === 'up'   ? finalTop  + offset : direction === 'down'  ? finalTop  - offset : finalTop;
    const startLeft = direction === 'left' ? finalLeft + offset : direction === 'right' ? finalLeft - offset : finalLeft;
    captionObj.set({ top: startTop, left: startLeft, opacity: 0 });
    fabricCanvas.renderAll();
    function step(now) {
        if (!isPlayingAnimation) return;
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        captionObj.set({ top: startTop + (finalTop - startTop) * eased, left: startLeft + (finalLeft - startLeft) * eased, opacity: eased });
        captionObj.setCoords();
        fabricCanvas.renderAll();
        if (progress < 1) animationFrame = requestAnimationFrame(step);
    }
    animationFrame = requestAnimationFrame(step);
}

// ========== ZOOM IN ANIMATION ==========
function startZoomInAnimation(captionObj, speed) {
    const duration = 500 / speed;
    const startTime = performance.now();
    function step(now) {
        if (!isPlayingAnimation) return;
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        captionObj.set({ scaleX: eased, scaleY: eased, opacity: eased });
        fabricCanvas.renderAll();
        if (progress < 1) {
            animationFrame = requestAnimationFrame(step);
        } else {
            captionObj.set({ scaleX: 1, scaleY: 1, opacity: 1 });
            fabricCanvas.renderAll();
        }
    }
    animationFrame = requestAnimationFrame(step);
}

// ========== POP ANIMATION ==========
function startPopAnimation(captionObj, speed) {
    const duration = 400 / speed;
    const startTime = performance.now();
    captionObj.set({ scaleX: 0, scaleY: 0, opacity: 1 });
    fabricCanvas.renderAll();
    function step(now) {
        if (!isPlayingAnimation) return;
        const progress = Math.min((now - startTime) / duration, 1);
        const scale = progress < 0.7 ? (progress / 0.7) * 1.2 : 1.2 - ((progress - 0.7) / 0.3) * 0.2;
        captionObj.set({ scaleX: scale, scaleY: scale });
        fabricCanvas.renderAll();
        if (progress < 1) {
            animationFrame = requestAnimationFrame(step);
        } else {
            captionObj.set({ scaleX: 1, scaleY: 1 });
            fabricCanvas.renderAll();
        }
    }
    animationFrame = requestAnimationFrame(step);
}

// ========== BOUNCE ANIMATION ==========
function startBounceAnimation(captionObj, speed) {
    const finalTop = captionObj.top;
    const startTop = finalTop - 120;
    const duration = 500 / speed;
    const startTime = performance.now();
    captionObj.set({ top: startTop, opacity: 1 });
    fabricCanvas.renderAll();
    function step(now) {
        if (!isPlayingAnimation) return;
        const progress = Math.min((now - startTime) / duration, 1);
        let eased;
        if (progress < 0.6)      eased = progress / 0.6;
        else if (progress < 0.8) eased = 1 - 0.3 * ((progress - 0.6) / 0.2);
        else if (progress < 0.9) eased = 0.7 + 0.3 * ((progress - 0.8) / 0.1);
        else                     eased = 1;
        captionObj.set('top', startTop + (finalTop - startTop) * eased);
        captionObj.setCoords();
        fabricCanvas.renderAll();
        if (progress < 1) {
            animationFrame = requestAnimationFrame(step);
        } else {
            captionObj.set('top', finalTop);
            fabricCanvas.renderAll();
        }
    }
    animationFrame = requestAnimationFrame(step);
}


// ========== SHOW KEN BURNS PANEL ==========
function showKenBurnsPanel(event) {
    console.log('🎯 Opening Ken Burns effects panel');
    
    // Store current mode
    const primaryIcons = document.getElementById('primaryIcons');
    const textIcons = document.getElementById('textIcons');
    
    // Store which mode we're in
    window.lastMode = 'primary';
    if (primaryIcons && primaryIcons.style.display === 'none') {
        if (textIcons && textIcons.style.display === 'flex') {
            window.lastMode = 'text';
            console.log('📝 Last mode set to: text');
        }
    }
    
    // Close any other overlays first
    closeAllOverlays();
    
    // HIDE SECONDARY ICONS
    if (textIcons) {
        textIcons.style.display = 'none';
        console.log('Text icons hidden');
    }
    
    const overlay = document.getElementById('kenBurnsPanel');
    const icon = event?.currentTarget;
    
    if (!overlay) {
        console.error('❌ kenBurnsPanel not found!');
        return;
    }
    
    // Load current Ken Burns setting from scene
    if (currentSceneId) {
        const scene = scenes.find(s => s.id == currentSceneId);
        if (scene && scene.ken_burns_effect) {
            highlightKenBurnsEffect(scene.ken_burns_effect);
        } else {
            // Default to zoom-in
            highlightKenBurnsEffect('zoom-in');
        }
    }
    
    // Position the overlay
    if (icon) {
        // Get positions
        const iconRect = icon.getBoundingClientRect();
        const canvasRect = document.getElementById('fabricCanvas').getBoundingClientRect();
        
        // Overlay dimensions
        const overlayWidth = 280;
        const overlayHeight = 350;
        
        // Position to the right of icon
        let left = iconRect.left - canvasRect.left + 50;
        
        // Boundary checks
        if (left + overlayWidth > canvasRect.width) {
            left = canvasRect.width - overlayWidth - 10;
        }
        
        if (left < 10) left = 10;
        
        // Position below icon
        let top = iconRect.top - canvasRect.top + 20;
        
        if (top + overlayHeight > canvasRect.height) {
            top = canvasRect.height - overlayHeight - 10;
        }
        
        if (top < 10) top = 10;
        
        overlay.style.position = 'absolute';
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.zIndex = '10000';
    } else {
        // Center in canvas if no icon
        overlay.style.position = 'absolute';
        overlay.style.left = '50%';
        overlay.style.top = '50%';
        overlay.style.transform = 'translate(-50%, -50%)';
    }
    
    overlay.style.display = 'block';
    overlay.classList.add('active');
    
    console.log('Ken Burns panel displayed');
}

// ========== HIGHLIGHT SELECTED KEN BURNS EFFECT ==========
function highlightKenBurnsEffect(effect) {
    document.querySelectorAll('#kenBurnsPanel .ken-btn').forEach(btn => {
        btn.style.borderColor = 'transparent';
        btn.style.background = '#f8fafc';
    });
    
    // Find and highlight the selected effect
    const effectMap = {
        'zoom-in': '🔍',
        'zoom-out': '🔎',
        'pan-left': '⬅️',
        'pan-right': '➡️',
        'pan-up': '⬆️',
        'pan-down': '⬇️',
        'zoom-pan': '🎯'
    };
    
    const emoji = effectMap[effect];
    if (emoji) {
        document.querySelectorAll('#kenBurnsPanel .ken-btn').forEach(btn => {
            const btnEmoji = btn.querySelector('div:first-child')?.textContent;
            if (btnEmoji === emoji) {
                btn.style.borderColor = 'var(--success)';
                btn.style.background = '#e0f2fe';
            }
        });
    }
}
</script>

</body>
</html>
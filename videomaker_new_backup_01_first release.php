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

    

    // Load defaults from hdb_user_settings for this admin

    $us = null;

    $us_query = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id = $admin_id LIMIT 1");

    if ($us_query && mysqli_num_rows($us_query) > 0) {

        $us = mysqli_fetch_assoc($us_query);

    }



    $default_fontfamily    = !empty($us['fontfamily'])    ? mysqli_real_escape_string($conn, $us['fontfamily'])    : 'Arial';

    $default_fontsize      = !empty($us['fontsize'])      ? (int)$us['fontsize']                                   : 30;

    $default_fontcolor     = !empty($us['fontcolor'])     ? mysqli_real_escape_string($conn, $us['fontcolor'])     : '#ffff00';

    $default_fontcolor_bg  = !empty($us['fontcolor_bg'])  ? mysqli_real_escape_string($conn, $us['fontcolor_bg'])  : '#000000';

    $default_fontweight    = !empty($us['fontweight'])    ? mysqli_real_escape_string($conn, $us['fontweight'])    : 'bold';

    $default_fontbg_enable = isset($us['fontbg_enable'])  ? (int)$us['fontbg_enable']                             : 0;

    $default_caption_style = !empty($us['caption_style']) ? mysqli_real_escape_string($conn, $us['caption_style']) : 'none';

    $default_caption_speed = !empty($us['caption_speed']) ? (float)$us['caption_speed']                           : 1.0;

    $default_text_align    = !empty($us['caption_alignment']) ? mysqli_real_escape_string($conn, $us['caption_alignment']) : 'center';

    $default_position_x    = !empty($us['position_x'])        ? (int)$us['position_x']                                     : 50;

    $default_position_y    = !empty($us['position_y'])        ? (int)$us['position_y']                                     : 200;

    $default_width         = !empty($us['width'])              ? (int)$us['width']                                          : 500;

    // caption_position is always 'center' regardless of user setting



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

             '$default_fontweight', 'normal', '$default_text_align', '$default_fontcolor_bg', $default_fontbg_enable,

             $default_position_x, $default_position_y, $default_width,

             '$default_caption_style', $default_caption_speed, 1, 1)";

    

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

    // JS sends 'story_id', some callers send 'scene_id' — accept both

    $story_id = (int)($_POST['story_id'] ?? $_POST['scene_id'] ?? 0);

    $captions = [];

    if ($story_id > 0) {

        $result = mysqli_query($conn, "SELECT * FROM hdb_captions WHERE story_id = $story_id");

        if ($result) while ($row = mysqli_fetch_assoc($result)) $captions[] = $row;

    }

    echo json_encode(['success' => true, 'captions' => $captions]);

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

// ---------- AJAX: Upload Scene Image (from imageSelectorPanel Upload button) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_scene_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];

    try {

        $scene_id    = (int)$_POST['scene_id'];

        $image_field = mysqli_real_escape_string($conn, $_POST['image_field'] ?? 'image_file');

        $media_type  = ($_POST['media_type'] ?? 'image') === 'video' ? 'video' : 'image';



        if (!isset($_FILES['scene_image']) || $_FILES['scene_image']['error'] !== UPLOAD_ERR_OK) {

            throw new Exception('No file uploaded or upload error: ' . ($_FILES['scene_image']['error'] ?? 'missing'));

        }

        $file = $_FILES['scene_image'];

        if ($file['size'] > 50 * 1024 * 1024) throw new Exception('File too large. Max 50MB');



        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $filename = 'scene_' . $scene_id . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;



        if ($media_type === 'video') {

            $upload_dir = __DIR__ . '/podcast_videos/';

            $db_field   = 'video_file';

        } else {

            $upload_dir = __DIR__ . '/podcast_images/';

            $db_field   = $image_field;

        }

        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);



        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {

            throw new Exception('Failed to save file to server');

        }



        $esc_filename = mysqli_real_escape_string($conn, $filename);

        $sql = "UPDATE hdb_podcast_stories SET `$db_field` = '$esc_filename' WHERE id = $scene_id";

        if (!mysqli_query($conn, $sql)) throw new Exception('DB error: ' . mysqli_error($conn));



        $response['success']  = true;

        $response['filename'] = $filename;

        $response['media_type'] = $media_type;

        $response['message']  = 'Uploaded successfully';

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

        $podcast_id = (int)($_POST['podcast_id'] ?? 0);

        $lang_code  = preg_replace('/[^a-z]/', '', $_POST['lang_code'] ?? 'en');

        $filename   = 'video_' . $podcast_id . '_' . $lang_code . '.webm';



        if ($podcast_id === 0) {

            throw new Exception('Missing podcast_id');

        }



        // Check binary file upload

        if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {

            $err = $_FILES['video_file']['error'] ?? 'no file';

            throw new Exception('File upload error: ' . $err);

        }



        // Create published_videos directory if needed

        $video_dir = __DIR__ . '/published_videos/';

        if (!is_dir($video_dir)) mkdir($video_dir, 0777, true);



        $filepath = $video_dir . $filename;



        // Delete any existing video for this podcast+lang

        if (file_exists($filepath)) {

            unlink($filepath);

            error_log("Deleted previous render: $filename");

        }



        if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $filepath)) {

            throw new Exception('Failed to save video file');

        }



        $filesize = filesize($filepath);

        $response['success']  = true;

        $response['message']  = 'Video saved successfully';

        $response['filename'] = $filename;

        $response['filepath'] = 'published_videos/' . $filename;

        $response['filesize'] = $filesize;

        error_log("Video saved: $filename ($filesize bytes)");



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

    /* Canvas wrapper card — centers the canvas */

    .canvas-wrapper {

        display: flex;

        flex-direction: column;

        align-items: center;

        padding: 0;

        overflow: hidden;

    }

    /* Canvas container fills the card width */

    #canvasContainer {

        position: relative;

        width: 100%;

        background: #000;

        overflow: hidden;

        display: flex;

        align-items: center;

        justify-content: center;

        min-height: 300px;

    }

    /* Fabric canvas centered */

    #canvasContainer canvas {

        display: block;

        margin: auto;

    }

    /* Nav arrows sit at bottom of canvas container */

    .nav-arrows {

        position: absolute;

        bottom: 16px;

        left: 50%;

        transform: translateX(-50%);

        z-index: 100;

    }

    /* Icon container sits top-left */

    .icon-container {

        position: absolute;

        top: 16px;

        left: 16px;

        z-index: 9000;

        pointer-events: none;

    }

       so caption text painted there is visible when a video is the background.

       upper-canvas stays on top for mouse events. */

    #canvasContainer canvas.lower-canvas {

        z-index: 2 !important;

    }

    #canvasContainer canvas.upper-canvas {

        z-index: 3 !important;

    }

    /* Apply-to-all strip shown directly after each overlay header */

    .apply-all-strip {

        padding: 7px 14px;

        border-bottom: 1px solid #e2e8f0;

        background: #f8fafc;

    }

    .apply-all-strip label {

        display: flex;

        align-items: center;

        gap: 8px;

        font-size: 12px;

        font-weight: 600;

        cursor: pointer;

        margin: 0;

        user-select: none;

    }

    .apply-all-strip input[type="checkbox"] {

        width: 15px;

        height: 15px;

        accent-color: var(--info, #3b82f6);

        cursor: pointer;

    }

    /* Disabled state when caption is not main */

    .apply-all-strip.disabled {

        opacity: 0.38;

        pointer-events: none;

    }

    .apply-all-strip.enabled {

        background: #eff6ff;

        border-bottom-color: #bfdbfe;

    }

    .apply-all-strip.enabled label {

        color: #1d4ed8;

    }

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

        <a href="vizard_browser.php">

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

		<div class="canvas-container" id="canvasContainer" style="position:relative;">



			<!-- Action Menu -->

			<div class="action-menu" id="actionMenu">

				<div class="action-menu-item" onclick="playPreview()"><span>▶️</span> Preview</div>

				<div class="action-menu-item" onclick="startRecording()"><span>⏺️</span> Record</div>

				<?php if ($podcast_lang_code === 'en'): ?>

				<div class="action-menu-item" onclick="TranslateVideo()"><span>🌐</span> Translate</div>

				<div class="action-menu-item" onclick="archiveProject()"><span>📦</span> Archive</div>

				<div class="action-menu-item" onclick="deleteProject()" style="color:#dc2626;"><span>🗑️</span> Delete</div>

				<?php endif; ?>

				<div class="action-menu-item" onclick="gobackProjects()"><span>🏠</span> Go Back</div>

			</div>



			<!-- Fabric.js Canvas -->

			<canvas id="fabricCanvas" style="display:block;"></canvas>



			<!-- Floating Stop Button -->

			<div id="floatingStopBtn" onclick="handleStopBtn()" style="display:none;position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:99999;background:#dc2626;color:white;border-radius:30px;padding:10px 24px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.35);">⏹ Stop</div>



			<!-- Icons — top-left, above canvas -->

			<div class="icon-container">

				<div class="primary-icons" id="primaryIcons">

					<div class="overlay-icon" onclick="showTypographyIcons()" title="Text">🅰️</div>

					<div class="overlay-icon" onclick="showImageSelectorOverlay(event)" title="Images">🌄</div>

					<div class="overlay-icon" onclick="showAudioIcons()" title="Audio">🔊</div>

					<div class="overlay-icon" onclick="playPreview()" title="Preview">▶️</div>

					<div class="overlay-icon" onclick="startRecording()" title="Record">⏺️</div>

					<div class="overlay-icon" onclick="toggleActionMenu(event)" title="More">⋮</div>

				</div>

				<div class="secondary-icons" id="textIcons" style="display:none;">

					<div class="overlay-icon back-icon" onclick="hideTextIcons()">←</div>

					<div class="overlay-icon" onclick="showFontFamilyPanel()">🔤</div>

					<div class="overlay-icon" onclick="showFontSizeOptions()">📏</div>

					<div class="overlay-icon" onclick="showTextColorOptions()">🎨</div>

					<div class="overlay-icon" onclick="showBgColorOptions(event)">🖌️</div>

					<div class="overlay-icon" onclick="showStyleOptions()">✒️</div>

					<div class="overlay-icon" onclick="showAlignment()">⬅️</div>

					<div class="overlay-icon" onclick="showEffects()">✨</div>

					<div class="overlay-icon" onclick="showPosition()">📍</div>

					<div class="overlay-icon" onclick="showAnimation()">⚡</div>

					<div class="overlay-icon" onclick="addNewCaption()" style="background:var(--success);color:white;">➕</div>

					<div class="overlay-icon" id="deleteIconSecondary" onclick="deleteSelectedCaption()" style="background:#dc2626;color:white;">🗑️</div>

				</div>

			</div>



			<!-- Nav arrows — bottom-center inside canvas -->

			<div class="nav-arrows">

				<div class="nav-arrow" onclick="navigateScene('prev')">←</div>

				<div class="scene-indicator" id="sceneIndicator">1 / <?= count($scenes) ?></div>

				<div class="nav-arrow" onclick="navigateScene('next')">→</div>

			</div>



		</div><!-- /canvas-container -->

			

<!-- Secondary Icons - Typography (Hidden by default) -->





<!-- Overlay Panels - Appear when secondary icons are clicked -->

<!-- Font Family Panel - Updated to match other overlays -->

<div class="overlay-panel" id="fontFamilyPanel" style="display: none; width: 280px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('fontFamilyPanel')">←</button>

        <span>🔤 Font Family</span>

    </div>

    <div class="apply-all-strip" id="applyAll_fontFamilyPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontFamilyPanel"> Apply to all scenes</label>

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

<div class="overlay-panel" id="fontSizePanel" style="display: none; width: 280px; max-height: 90vh; overflow-y: auto;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('fontSizePanel')">←</button>

        <span>📏 Font Size</span>

    </div>

    <div class="apply-all-strip" id="applyAll_fontSizePanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontSizePanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 20px; max-height: 440px; overflow-y: auto;">

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

    <div class="apply-all-strip" id="applyAll_textColorPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_textColorPanel"> Apply to all scenes</label>

    </div>

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

    <div class="apply-all-strip" id="applyAll_animationPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_animationPanel"> Apply to all scenes</label>

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

<div class="font-color-overlay" id="fontColorOverlay" style="display: none; overflow: visible;">

    <div class="apply-all-strip" id="applyAll_fontColorOverlay" style="display:block; padding:7px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">

        <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;margin:0;"><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontColorOverlay" style="width:15px;height:15px;"> Apply to all scenes</label>

    </div>

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

    <div class="apply-all-strip" id="applyAll_bgColorPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_bgColorPanel"> Apply to all scenes</label>

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

        

        <!-- Background Enable Checkbox - hidden but kept for JS functionality -->

        <input type="checkbox" id="enableBgCheckbox" onchange="toggleBgEnable(this.checked)" style="display:none;">

    </div>

</div>



<!-- Style Overlay - Compact with icons only -->

<div class="overlay-panel" id="stylePanel" style="display: none;">

   <div class="overlay-header">

		<button class="overlay-close" onclick="closeOverlay('stylePanel')">←</button>

		<span>✒️ Text Style</span> 

	</div>

    <div class="apply-all-strip" id="applyAll_stylePanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_stylePanel"> Apply to all scenes</label>

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

    <div class="apply-all-strip" id="applyAll_alignmentPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_alignmentPanel"> Apply to all scenes</label>

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

    <div class="apply-all-strip" id="applyAll_effectsPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_effectsPanel"> Apply to all scenes</label>

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

<div class="overlay-panel" id="positionPanel" style="display: none; max-height: 90vh; overflow-y: auto;">

    <div class="overlay-header">

		<button class="overlay-close" onclick="closeOverlay('positionPanel')">←</button>

		<span>📍 Position</span>

	</div>

    <div class="apply-all-strip" id="applyAll_positionPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_positionPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px; max-height: 480px; overflow-y: auto;">

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

        <input type="file" id="slotImageUpload" accept="image/*,video/*" style="display: none;" onchange="handleSlotFileUpload(this)">

        

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

<!-- ========== MEDIA LIBRARY MODAL (Images & Videos) ========== -->

<div id="mediaLibModal" class="modal-overlay" style="z-index: 10003; display: none;">

    <div class="modal-content" style="max-width: 900px; width: 95%;">

        <div class="modal-header">

            <h3><span style="margin-right: 8px;">🖼️</span> Media Library

                <span style="font-size: 13px; font-weight: 400; color: var(--muted); margin-left: 12px;">

                    Scene: <span id="editSceneId"></span> &nbsp;|&nbsp; Slot: <span id="editSlotName"></span>

                </span>

            </h3>

            <button class="modal-close" onclick="closeMediaLib()">✕</button>

        </div>



        <!-- Tabs -->

        <div style="display: flex; gap: 0; border-bottom: 2px solid var(--border); padding: 0 24px;">

            <button id="tabImages" class="modal-tab active"

                    onclick="switchMediaTab('images')"

                    style="padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; font-size: 14px; border-bottom: 3px solid var(--info); color: var(--info);">

                🖼️ Images <span id="tabImagesCount" style="background:#e2e8f0; border-radius:30px; padding:2px 8px; font-size:12px; margin-left:4px;">0</span>

            </button>

            <button id="tabVideos" class="modal-tab"

                    onclick="switchMediaTab('videos')"

                    style="padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; font-size: 14px; color: var(--muted);">

                🎬 Videos <span id="tabVideosCount" style="background:#e2e8f0; border-radius:30px; padding:2px 8px; font-size:12px; margin-left:4px;">0</span>

            </button>

        </div>



        <!-- Search -->

        <div style="padding: 12px 24px; border-bottom: 1px solid var(--border);">

            <div style="display: flex; gap: 8px; align-items: center;">

                <input type="text" id="mediaSearchInput"

                       placeholder="Search by filename or hashtag..."

                       onkeyup="if(event.key==='Enter') performSearch()"

                       style="flex:1; padding: 10px 16px; border: 2px solid var(--border); border-radius: 30px; font-size: 14px; box-sizing: border-box;">

                <button onclick="performSearch()"

                        style="background: var(--info); color: white; border: none; padding: 10px 20px; border-radius: 30px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;">

                    🔍 Search

                </button>

                <button onclick="document.getElementById('mediaSearchInput').value=''; performSearch();"

                        title="Show all files"

                        style="background: #f1f5f9; color: #475569; border: 1px solid var(--border); padding: 10px 14px; border-radius: 30px; font-size: 13px; cursor: pointer; white-space: nowrap;">

                    ✕ All

                </button>

            </div>

        </div>



        <!-- Grid -->

        <div class="modal-body" style="padding: 16px 24px; max-height: 55vh; overflow-y: auto;">

            <div id="mediaGrid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">

                <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted);">⏳ Loading media...</div>

            </div>

        </div>



        <!-- Lightbox for full-size preview -->

        <div id="mediaLightbox" onclick="closeMediaLightbox()"

             style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:20000; align-items:center; justify-content:center; cursor:zoom-out;">

            <img id="mediaLightboxImg" src="" style="max-width:90vw; max-height:90vh; border-radius:8px; box-shadow:0 8px 40px rgba(0,0,0,0.6);">

            <div id="mediaLightboxName" style="position:fixed; bottom:24px; left:50%; transform:translateX(-50%); color:white; font-size:13px; background:rgba(0,0,0,0.6); padding:6px 16px; border-radius:20px;"></div>

        </div>



        <style>

        .media-item {

            position: relative;

            border-radius: 10px;

            overflow: hidden;

            border: 3px solid transparent;

            cursor: pointer;

            background: #f1f5f9;

            transition: border-color 0.15s, transform 0.15s;

            aspect-ratio: 1;

        }

        .media-item:hover { border-color: var(--info); transform: scale(1.02); }

        .media-item.selected { border-color: var(--success) !important; }

        .media-item .media-preview {

            width: 100%; height: 100%;

            object-fit: cover;

            display: block;

        }

        .media-item .media-overlay {

            position: absolute; inset: 0;

            background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, transparent 50%);

            opacity: 0; transition: opacity 0.15s;

            display: flex; align-items: flex-end; padding: 8px;

        }

        .media-item:hover .media-overlay { opacity: 1; }

        .media-item .media-name {

            color: white; font-size: 11px; font-weight: 600;

            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;

            width: 100%;

        }

        .media-item .media-check {

            position: absolute; top: 6px; right: 6px;

            background: var(--success); color: white;

            width: 22px; height: 22px; border-radius: 50%;

            display: none; align-items: center; justify-content: center;

            font-size: 13px; font-weight: 700;

        }

        .media-item.selected .media-check { display: flex; }

        .media-item .zoom-btn {

            position: absolute; top: 6px; left: 6px;

            background: rgba(0,0,0,0.5); color: white;

            width: 26px; height: 26px; border-radius: 50%;

            display: none; align-items: center; justify-content: center;

            font-size: 14px; cursor: zoom-in; border: none;

        }

        .media-item:hover .zoom-btn { display: flex; }

        .media-tags { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 2px; }

        .media-tags span { font-size: 9px; padding: 1px 5px; border-radius: 8px; }

        </style>



        <!-- Footer -->

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-top: 1px solid var(--border);">

            <div id="mediaSelInfo" style="font-size: 13px; color: var(--muted);">No file selected</div>

            <div style="display: flex; gap: 12px;">

                <button class="panel-btn" onclick="closeMediaLib()"

                        style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px; cursor: pointer;">

                    Cancel

                </button>

                <button id="mediaSelectBtn" class="panel-btn" disabled

                        style="background: var(--success); color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;">

                    Use Selected

                </button>

            </div>

        </div>

    </div>

</div>



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

        <a href="vizard_browser.php">Home</a>

        <a href="profile.php">Profile</a>

        <a href="settings.php">Settings</a>

        <a href="logout.php">Logout</a>

    </div>

    <div>© <?= date('Y') ?> VideoVizard</div>

</footer>



<!-- Media Library Modal -->

<!-- Media Library Modal -->











<script>

// ============================================================

// VIDEOMAKER — Clean JS rewrite

// Key fixes:

//   1. Captions added to fabricCanvas.add() go on lowerCanvasEl

//      BUT the composite recorder needs BOTH lowerCanvasEl AND

//      upperCanvasEl drawn in order for captions to appear.

//   2. startCaptionAnimation called for ALL captions regardless

//      of caption_name (old code only called it for 'main').

//   3. Zero duplicate function definitions.

// ============================================================



// ── Global state ─────────────────────────────────────────────

let scenes           = <?= json_encode($scenes) ?>;

let audio_files      = <?= json_encode($audio_files) ?>;

let currentSceneIndex = 0;

let currentSceneId    = scenes.length > 0 ? scenes[0].id : null;

let currentImageField = 'image_file';

let sceneCaptions     = {};



let fabricCanvas          = null;

let currentBackgroundImage = null;

let currentBackgroundVideo = null;

let captionText = null;   // legacy ref — kept for saveCanvasStateToScene

let logoImage   = null;

let isSelectionMode = false;

let autoSaveTimeout = null;



// Playback

let isPlayingSequence = false;

let currentScenePlayIndex = 0;

let currentAudioPlayer    = null;

let podcastMusicFile      = <?= json_encode($podcast_music ?? '') ?>;



// Animation

let isPlayingAnimation = false;

let animationFrame     = null;

let animationInterval  = null;



// Recording

let isRecording       = false;

let mediaRecorder     = null;

let recordedChunks    = [];

let renderAudioElements = [];



// UI

let currentOpenPanel = null;



// ── Utility ──────────────────────────────────────────────────

function L(m) {

    const el = document.getElementById('logBox');

    if (el) { el.value += m + '\n'; el.scrollTop = el.scrollHeight; }

    console.log(m);

}



async function safeFetch(fd) {

    const r   = await fetch(location.href, { method: 'POST', body: fd });

    const raw = await r.text();

    try { return { data: JSON.parse(raw), raw }; }

    catch(e) { throw new Error('Server non-JSON:\n' + raw.substring(0, 800)); }

}



function updateSceneIndicator() {

    const el = document.getElementById('sceneIndicator');

    if (el) el.textContent = (currentSceneIndex + 1) + ' / ' + scenes.length;

}



// ── Canvas init ───────────────────────────────────────────────

function initFabricCanvas() {

    if (fabricCanvas) return;

    const container   = document.getElementById('canvasContainer');

    let canvasWidth   = Math.floor(container.clientWidth);

    if (canvasWidth < 280) canvasWidth = 280;

    if (canvasWidth > 500) canvasWidth = 500;

    const canvasHeight = Math.round(canvasWidth * 16 / 9);



    fabricCanvas = new fabric.Canvas('fabricCanvas', {

        width: canvasWidth, height: canvasHeight,

        backgroundColor: '#000000',

        preserveObjectStacking: true,

        allowTouchScrolling: false,

        selection: false

    });



    fabricCanvas.on('mouse:down', () => fabricCanvas.calcOffset());

    fabricCanvas.on('touch:start', () => fabricCanvas.calcOffset());

    fabricCanvas.selection = false;



    setupCaptionSelection();

    loadCurrentSceneToFabric();

    console.log('✅ Fabric canvas initialized', canvasWidth, 'x', canvasHeight);

}



// ── Scene loading ─────────────────────────────────────────────

async function loadCurrentSceneToFabric() {

    if (!fabricCanvas || !currentSceneId) return;

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;



    // Stop ongoing animations/loops

    stopCaptionAnimation();

    if (window.kenBurnsFrame)    { cancelAnimationFrame(window.kenBurnsFrame); window.kenBurnsFrame = null; }

    if (window._slideshowTimer)  { clearInterval(window._slideshowTimer);  window._slideshowTimer = null; }

    if (window._videoFrameLoop)  { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }



    // Clear canvas

    fabricCanvas.clear();

    fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));

    currentBackgroundImage = null;

    currentBackgroundVideo = null;

    captionText = null;



    // Remove old DOM video

    const oldVideo = document.getElementById('backgroundVideo');

    if (oldVideo) { oldVideo.pause(); oldVideo.remove(); }

    const playBtn = document.getElementById('videoPlayButton');

    if (playBtn) playBtn.style.display = 'none';



    // Load captions from DB

    sceneCaptions = {};

    await loadSceneCaptions(currentSceneId);



    // ── Background media ──────────────────────────────────────

    const imgSlots   = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4','image_file_5'];

    const isImg = f => f && f.trim() && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);

    const isVid = f => f && f.trim() && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);



    const slideshowFiles = imgSlots.map(s => scene[s]).filter(isImg);

    let mediaLoaded = false;



    if (slideshowFiles.length > 1) {

        await setImageBackground('podcast_images/' + slideshowFiles[0]);

        mediaLoaded = true;

        let idx = 1;

        window._slideshowTimer = setInterval(async () => {

            if (!currentSceneId) { clearInterval(window._slideshowTimer); return; }

            await setImageBackground('podcast_images/' + slideshowFiles[idx % slideshowFiles.length]);

            idx++;

        }, 2500);

    } else if (slideshowFiles.length === 1) {

        await setImageBackground('podcast_images/' + slideshowFiles[0]);

        mediaLoaded = true;

    }



    if (!mediaLoaded) {

        const fn = scene[currentImageField] || scene.image_file;

        if (fn && fn.trim()) {

            // Check preload cache first

            const cached = window.preloadCache && window.preloadCache[fn];

            if (cached && cached.tagName === 'VIDEO') {

                await setVideoBackgroundFromCache(cached);

            } else if (cached && cached.tagName === 'IMG') {

                await setImageBackgroundFromCache(cached);

            } else if (isVid(fn)) {

                await setVideoBackground('podcast_videos/' + fn);

            } else {

                await setImageBackground('podcast_images/' + fn);

            }

        } else {

            fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas));

        }

    }



    // ── Add captions ──────────────────────────────────────────

    let captionsAdded = 0;

    for (const key in sceneCaptions) {

        if (isNaN(key)) continue;    // skip alias keys

        const cap = sceneCaptions[key];

        if (cap.image_file && cap.image_file.trim()) {

            await addImageBoxToCanvas(cap.id, cap.image_file, false);

            captionsAdded++;

        } else if (cap.text_content) {

            await addMainCaptionToFabric(scene, cap);

            captionsAdded++;

        }

    }

    console.log(`✅ ${captionsAdded} caption(s) added`);



    // ── Logo ──────────────────────────────────────────────────

    if (scene.logo_enabled && scene.logo_name) await addLogoToFabric(scene);



    fabricCanvas.renderAll();



    // Lock non-caption objects

    fabricCanvas.discardActiveObject();

    fabricCanvas.forEachObject(obj => {

        if (!obj.captionId) {

            obj.selectable  = false;

            obj.hasControls = false;

            obj.hasBorders  = false;

            obj.evented     = false;

        }

    });

}



// ── Load captions from DB ─────────────────────────────────────

async function loadSceneCaptions(sceneId) {

    const fd = new FormData();

    fd.append('ajax_action', 'get_scene_captions');

    fd.append('story_id', sceneId);   // PHP reads story_id

    fd.append('scene_id', sceneId);   // fallback

    fd.append('podcast_id', <?= (int)$podcast_id ?>);

    try {

        const { data } = await safeFetch(fd);

        if (data.success && data.captions && data.captions.length > 0) {

            data.captions.forEach(cap => {

                sceneCaptions[cap.id] = cap;

                if (!sceneCaptions[cap.caption_type]) sceneCaptions[cap.caption_type] = cap;

            });

            console.log(`✅ Loaded ${data.captions.length} caption(s) for scene ${sceneId}`);

        } else {

            console.warn(`⚠️ No captions for scene ${sceneId}`, data);

        }

    } catch(e) { console.error('loadSceneCaptions error:', e); }

    return sceneCaptions;

}



// ── Add main text caption to Fabric ──────────────────────────

async function addMainCaptionToFabric(scene, captionData) {

    if (!captionData || !captionData.text_content || !fabricCanvas) return;



    const cW = fabricCanvas.width;

    const cH = fabricCanvas.height;



    // Clamp position and width to canvas bounds

    const left     = Math.min(parseInt(captionData.position_x) || 20, cW - 50);

    const top      = Math.min(parseInt(captionData.position_y) || Math.round(cH * 0.6), cH - 60);

    const rawWidth = parseInt(captionData.width) || cW - 40;

    const width    = Math.min(rawWidth, cW - left - 10); // never wider than canvas

    const fontSize = parseInt(captionData.fontsize) || 28;



    let bgColor = 'transparent';

    if (captionData.bg_enabled == 1) {

        bgColor = captionData.bg_color || '#000000';

    }



    let fontWeight = 'normal';

    if (captionData.fontweight === 'bold' || captionData.fontweight === '700') fontWeight = 'bold';

    else if (captionData.fontweight) fontWeight = String(captionData.fontweight);



    const textObj = new fabric.Textbox(captionData.text_content, {

        left, top, width, fontSize,

        fontFamily:      captionData.fontfamily  || 'Arial',

        fill:            captionData.fontcolor   || '#ffffff',

        fontWeight,

        fontStyle:       captionData.fontstyle   || 'normal',

        underline:       captionData.underline   == 1,

        linethrough:     captionData.linethrough == 1,

        textAlign:       captionData.text_align  || 'center',

        backgroundColor: bgColor,

        stroke:      captionData.outline_enabled ? captionData.outline_color :

                     (captionData.stroke_enabled  ? captionData.stroke_color  : null),

        strokeWidth: captionData.outline_enabled ? parseInt(captionData.outline_width || 2) :

                     (captionData.stroke_enabled  ? parseInt(captionData.stroke_width  || 2) : 0),

        paintFirst:  captionData.outline_enabled ? 'stroke' : 'fill',

        lineHeight:  1.2,

        padding:     15,

        opacity:     1,

        // Controls (respect selection mode)

        hasControls: isSelectionMode,

        hasBorders:  isSelectionMode,

        selectable:  isSelectionMode,

        editable:    true,

        evented:     true,

        // Metadata

        captionId:      captionData.id,

        captionType:    captionData.caption_type || 'text',

        animationStyle: captionData.animation_style || 'none',

        animationSpeed: parseFloat(captionData.animation_speed) || 1.0,

        originalText:   captionData.text_content

    });



    // Remove any existing object with same captionId

    const existing = fabricCanvas.getObjects().find(o => o.captionId == captionData.id);

    if (existing) fabricCanvas.remove(existing);



    fabricCanvas.add(textObj);

    textObj.bringToFront();

    fabricCanvas.renderAll();



    // ── FIX: start animation for ALL captions, not just 'main' ──

    const style = captionData.animation_style || 'none';

    if (style && style !== 'none' && style !== 'static') {

        startCaptionAnimation(textObj);

    }



    // Position / size auto-save

    textObj.on('modified', function() {

        autoSaveCaptionProperty(this, 'position_x', Math.round(this.left));

        autoSaveCaptionProperty(this, 'position_y', Math.round(this.top));

        autoSaveCaptionProperty(this, 'width',       Math.round(this.width));

    });

    textObj.on('editing:exited', function() {

        if (this.text !== captionData.text_content) {

            autoSaveCaptionProperty(this, 'text_content', this.text);

        }

    });



    // Keep reference for legacy code

    if (!captionText) captionText = textObj;

    return textObj;

}



// ── Image box captions ────────────────────────────────────────

async function addImageBoxToCanvas(captionId, imageFile, makeSelectable) {

    if (!fabricCanvas || !imageFile) return;

    const cap = sceneCaptions[captionId];

    const src = 'podcast_images/' + imageFile;

    return new Promise(resolve => {

        fabric.Image.fromURL(src, img => {

            if (!img) { resolve(); return; }

            const left  = cap ? (parseInt(cap.position_x) || 50)  : 50;

            const top   = cap ? (parseInt(cap.position_y) || 200) : 200;

            const w     = cap ? (parseInt(cap.width)       || 100) : 100;

            const scale = w / img.width;

            img.set({

                left, top, scaleX: scale, scaleY: scale,

                selectable:  makeSelectable,

                hasControls: makeSelectable,

                hasBorders:  makeSelectable,

                evented:     true,

                captionId, captionType: 'image'

            });

            const ex = fabricCanvas.getObjects().find(o => o.captionId == captionId);

            if (ex) fabricCanvas.remove(ex);

            fabricCanvas.add(img);

            img.bringToFront();

            fabricCanvas.renderAll();

            if (makeSelectable) img.on('modified', function() {

                autoSaveCaptionProperty(this, 'position_x', Math.round(this.left));

                autoSaveCaptionProperty(this, 'position_y', Math.round(this.top));

                autoSaveCaptionProperty(this, 'width', Math.round(this.width * this.scaleX));

            });

            resolve(img);

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Logo ──────────────────────────────────────────────────────

async function addLogoToFabric(scene) {

    if (!scene.logo_name || !fabricCanvas) return;

    return new Promise(resolve => {

        fabric.Image.fromURL('podcast_images/' + scene.logo_name, img => {

            if (!img) { resolve(); return; }

            const size  = parseInt(scene.logo_size || 60);

            const scale = size / Math.max(img.width, img.height);

            const pos   = scene.logo_position || 'top-right';

            const pad   = 20;

            const cW = fabricCanvas.width, cH = fabricCanvas.height;

            const w = img.width * scale, h = img.height * scale;

            const positions = {

                'top-left':     { left: pad,      top: pad },

                'top-right':    { left: cW-w-pad, top: pad },

                'bottom-left':  { left: pad,      top: cH-h-pad },

                'bottom-right': { left: cW-w-pad, top: cH-h-pad },

                'center':       { left: (cW-w)/2, top: (cH-h)/2 }

            };

            const p = positions[pos] || positions['top-right'];

            img.set({ ...p, scaleX: scale, scaleY: scale, selectable: false, hasControls: false, hasBorders: false, evented: false });

            fabricCanvas.add(img);

            img.bringToFront();

            fabricCanvas.renderAll();

            logoImage = img;

            resolve(img);

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Background: image ─────────────────────────────────────────

function setImageBackground(fullPath) {

    return new Promise(resolve => {

        // Stop DOM video

        const vid = document.getElementById('backgroundVideo');

        if (vid) { vid.pause(); vid.remove(); }

        if (currentBackgroundVideo) { fabricCanvas.remove(currentBackgroundVideo); currentBackgroundVideo = null; }

        if (currentBackgroundImage) { fabricCanvas.remove(currentBackgroundImage); currentBackgroundImage = null; }



        fabric.Image.fromURL(fullPath, img => {

            if (!img) { fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas)); resolve(); return; }

            const cW = fabricCanvas.width, cH = fabricCanvas.height;

            const scale = Math.max(cW / img.width, cH / img.height);

            const left  = (cW - img.width  * scale) / 2;

            const top   = (cH - img.height * scale) / 2;

            img.set({ left, top, scaleX: scale, scaleY: scale, selectable: false, evented: false, hasControls: false, hasBorders: false, originX: 'left', originY: 'top' });

            fabricCanvas.add(img);

            fabricCanvas.sendToBack(img);

            currentBackgroundImage = img;

            fabricCanvas.renderAll();

            const scene = scenes.find(s => s.id == currentSceneId);

            applyKenBurnsToCanvas(img, scene && scene.ken_burns_effect ? scene.ken_burns_effect : 'zoom-in');

            resolve();

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Background: video ─────────────────────────────────────────

function setVideoBackground(fullPath) {

    return new Promise(resolve => {

        if (window._videoFrameLoop) { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }

        const old = document.getElementById('backgroundVideo');

        if (old) { old.pause(); old.remove(); }



        const container = document.getElementById('canvasContainer');

        if (!container) { resolve(); return; }



        const video = document.createElement('video');

        video.id = 'backgroundVideo';

        video.muted = true; video.loop = true; video.playsInline = true;

        video.setAttribute('playsinline', '');

        video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:1;pointer-events:none;';

        container.appendChild(video);



        // Canvas transparent so DOM video shows through

        const canvasEl = document.getElementById('fabricCanvas');

        if (canvasEl) canvasEl.style.backgroundColor = 'transparent';

        fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));



        video.src = fullPath + '?t=' + Date.now();

        video.load();

        video.onloadedmetadata = () => {

            video.play()

                .then(() => { currentBackgroundVideo = video; resolve(); })

                .catch(() => resolve());

        };

        video.onerror = () => resolve();

    });

}



function setVideoBackgroundFromCache(cached) {

    return new Promise(resolve => {

        const old = document.getElementById('backgroundVideo');

        if (old) { old.pause(); old.remove(); }

        const container = document.getElementById('canvasContainer');

        if (!container) { resolve(); return; }

        const video = document.createElement('video');

        video.id = 'backgroundVideo'; video.muted = true; video.loop = true; video.playsInline = true;

        video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:1;pointer-events:none;';

        video.src = cached.src;

        container.appendChild(video);

        const canvasEl = document.getElementById('fabricCanvas');

        if (canvasEl) canvasEl.style.backgroundColor = 'transparent';

        fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));

        video.play().then(() => { currentBackgroundVideo = video; resolve(); }).catch(() => resolve());

    });

}



function setImageBackgroundFromCache(cached) {

    return new Promise(resolve => {

        const vid = document.getElementById('backgroundVideo');

        if (vid) { vid.pause(); vid.remove(); }

        if (currentBackgroundVideo) { fabricCanvas.remove(currentBackgroundVideo); currentBackgroundVideo = null; }

        if (currentBackgroundImage) { fabricCanvas.remove(currentBackgroundImage); currentBackgroundImage = null; }

        const cloned   = cached.cloneNode();

        const fabricImg = new fabric.Image(cloned);

        const cW = fabricCanvas.width, cH = fabricCanvas.height;

        const scale = Math.max(cW / fabricImg.width, cH / fabricImg.height);

        fabricImg.set({ left: (cW - fabricImg.width * scale) / 2, top: (cH - fabricImg.height * scale) / 2, scaleX: scale, scaleY: scale, selectable: false, evented: false, hasControls: false, hasBorders: false, originX: 'left', originY: 'top' });

        fabricCanvas.add(fabricImg); fabricCanvas.sendToBack(fabricImg);

        currentBackgroundImage = fabricImg; fabricCanvas.renderAll();

        const scene = scenes.find(s => s.id == currentSceneId);

        applyKenBurnsToCanvas(fabricImg, scene && scene.ken_burns_effect ? scene.ken_burns_effect : 'zoom-in');

        resolve();

    });

}



function cleanupVideo() {

    if (window._slideshowTimer)  { clearInterval(window._slideshowTimer);  window._slideshowTimer = null; }

    if (window._videoFrameLoop)  { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }

    const old = document.getElementById('backgroundVideo');

    if (old) { old.pause(); old.remove(); }

    if (currentBackgroundVideo) {

        if (fabricCanvas) fabricCanvas.remove(currentBackgroundVideo);

        currentBackgroundVideo = null;

    }

}



// ── Ken Burns ─────────────────────────────────────────────────

function applyKenBurnsToCanvas(img, effect) {

    if (window.kenBurnsFrame) { cancelAnimationFrame(window.kenBurnsFrame); window.kenBurnsFrame = null; }

    if (!img || !fabricCanvas) return;

    const cW = fabricCanvas.width, cH = fabricCanvas.height;

    const base = Math.max(cW / img.width, cH / img.height);

    const zoom = base * 1.2;

    const dur  = 6000;

    const t0   = performance.now();

    const states = {

        'zoom-in':   { ss: base, es: zoom, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },

        'zoom-out':  { ss: zoom, es: base, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },

        'pan-left':  { ss: zoom, es: zoom, sl: cW/2+(img.width*zoom-cW)/2,  el: cW/2-(img.width*zoom-cW)/2,  st: cH/2, et: cH/2 },

        'pan-right': { ss: zoom, es: zoom, sl: cW/2-(img.width*zoom-cW)/2,  el: cW/2+(img.width*zoom-cW)/2,  st: cH/2, et: cH/2 },

        'pan-up':    { ss: zoom, es: zoom, sl: cW/2, el: cW/2, st: cH/2+(img.height*zoom-cH)/2, et: cH/2-(img.height*zoom-cH)/2 },

        'pan-down':  { ss: zoom, es: zoom, sl: cW/2, el: cW/2, st: cH/2-(img.height*zoom-cH)/2, et: cH/2+(img.height*zoom-cH)/2 },

        'zoom-pan':  { ss: base, es: zoom, sl: cW/2-40, el: cW/2+40, st: cH/2+30, et: cH/2-30 }

    };

    const s = states[effect] || states['zoom-in'];

    img.set({ scaleX: s.ss, scaleY: s.ss, left: s.sl, top: s.st, originX: 'center', originY: 'center' });

    img.setCoords(); fabricCanvas.renderAll();

    function step(now) {

        if (!currentBackgroundImage || currentBackgroundImage !== img) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = p < 0.5 ? 2*p*p : 1 - Math.pow(-2*p+2, 2)/2;

        img.set({ scaleX: s.ss+(s.es-s.ss)*e, scaleY: s.ss+(s.es-s.ss)*e, left: s.sl+(s.el-s.sl)*e, top: s.st+(s.et-s.st)*e });

        img.setCoords(); fabricCanvas.renderAll();

        if (p < 1) window.kenBurnsFrame = requestAnimationFrame(step);

    }

    window.kenBurnsFrame = requestAnimationFrame(step);

}



// ── Scene navigation ──────────────────────────────────────────

async function navigateScene(direction) {

    if (!scenes.length) return;

    const newIndex = direction === 'prev'

        ? (currentSceneIndex - 1 + scenes.length) % scenes.length

        : (currentSceneIndex + 1) % scenes.length;

    currentSceneIndex = newIndex;

    currentSceneId    = scenes[newIndex].id;

    currentImageField = 'image_file';

    updateSceneIndicator();

    if (fabricCanvas) await loadCurrentSceneToFabric();

}



// ── Caption selection ─────────────────────────────────────────

function setupCaptionSelection() {

    if (!fabricCanvas) return;

    fabricCanvas.on('selection:created', e => {

        const sel = e.selected && e.selected[0];

        if (!sel || !sel.captionId || !isSelectionMode) {

            fabricCanvas.discardActiveObject(); fabricCanvas.renderAll(); return;

        }

        selectCaption(sel.captionId, sel.captionType);

    });

    fabricCanvas.on('selection:updated', e => {

        const sel = e.selected && e.selected[0];

        if (!sel || !sel.captionId || !isSelectionMode) {

            fabricCanvas.discardActiveObject(); fabricCanvas.renderAll(); return;

        }

        selectCaption(sel.captionId, sel.captionType);

    });

    fabricCanvas.on('selection:cleared', () => {

        // optional: close panels

    });

}



function selectCaption(captionId, captionType) {

    // open the typography panel and populate it

    const panel = document.getElementById('scene-typography-captions');

    if (panel) {

        panel.style.display = 'block';

        loadCaptionIntoPanel(captionId);

    }

}



function setSelectionMode(enabled) {

    isSelectionMode = enabled;

    if (!fabricCanvas) return;

    fabricCanvas.skipTargetFind = !enabled;

    fabricCanvas.forEachObject(obj => {

        if (obj.captionId) {

            obj.selectable  = enabled;

            obj.hasControls = enabled;

            obj.hasBorders  = enabled;

            obj.evented     = true;

        }

    });

    fabricCanvas.renderAll();

}



// ── Caption save helpers ──────────────────────────────────────

function findCaptionById(id) {

    for (const key in sceneCaptions) {

        if (sceneCaptions[key] && sceneCaptions[key].id == id) return sceneCaptions[key];

    }

    return null;

}



function autoSaveCaptionProperty(captionObj, property, value) {

    if (!captionObj || !captionObj.captionId) return;

    if (!captionObj._pending) captionObj._pending = {};

    captionObj._pending[property] = value;

    clearTimeout(autoSaveTimeout);

    autoSaveTimeout = setTimeout(async () => {

        const updates = Object.assign({}, captionObj._pending);

        captionObj._pending = {};

        await saveCaptionToDatabase(captionObj.captionId, updates);

    }, 500);

}



async function saveCaptionToDatabase(captionId, updates) {

    if (!captionId) return false;

    const fd = new FormData();

    fd.append('ajax_action', 'save_caption');

    fd.append('caption_id', captionId);

    Object.keys(updates).forEach(k => fd.append(k, updates[k] == null ? '' : updates[k]));

    try {

        const { data } = await safeFetch(fd);

        if (data.success) {

            if (sceneCaptions[captionId]) Object.assign(sceneCaptions[captionId], updates);

            return true;

        }

        return false;

    } catch(e) { console.error('saveCaptionToDatabase:', e); return false; }

}



// ── Caption animations ────────────────────────────────────────

function stopCaptionAnimation() {

    isPlayingAnimation = false;

    if (animationInterval) { clearInterval(animationInterval);  animationInterval = null; }

    if (animationFrame)    { cancelAnimationFrame(animationFrame); animationFrame = null; }

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



function startCaptionAnimation(captionObj) {

    if (!captionObj) return;

    stopCaptionAnimation();

    const styleMap = { wordReveal:'word-reveal', fade:'fade-in', slideUp:'slide-up', slideDown:'slide-down', zoom:'zoom-in', static:'none' };

    const raw   = captionObj.animationStyle || 'none';

    const style = styleMap[raw] || raw;

    const speed = captionObj.animationSpeed || 1.0;

    const full  = captionObj.originalText   || captionObj.text;

    isPlayingAnimation = true;

    console.log('🎬 Caption animation:', style);



    switch (style) {

        case 'typewriter':

            captionObj.originalText = full;

            captionObj.set({ text: '', opacity: 1 });

            fabricCanvas.renderAll();

            _typewriterAnim(captionObj, speed);

            break;

        case 'word-reveal':

            captionObj.originalText = full;

            captionObj.set({ text: '', opacity: 1 });

            fabricCanvas.renderAll();

            _wordRevealAnim(captionObj, speed);

            break;

        case 'fade-in':

            captionObj.set('opacity', 0); fabricCanvas.renderAll();

            _fadeInAnim(captionObj, speed);

            break;

        case 'slide-up':    _slideAnim(captionObj, 'up',   speed); break;

        case 'slide-down':  _slideAnim(captionObj, 'down', speed); break;

        case 'zoom-in':     captionObj.set({ scaleX: 0.1, scaleY: 0.1, opacity: 0 }); fabricCanvas.renderAll(); _zoomInAnim(captionObj, speed); break;

        case 'pop':         _popAnim(captionObj, speed);    break;

        case 'bounce':      _bounceAnim(captionObj, speed); break;

        case 'karaoke':     _karaokeAnim(captionObj, speed); break;

        default:

            captionObj.set('opacity', 1); fabricCanvas.renderAll(); break;

    }

}



function _typewriterAnim(obj, speed) {

    const full = obj.originalText || obj.text;

    let i = 0;

    const delay = 50 / speed;

    animationInterval = setInterval(() => {

        if (!isPlayingAnimation) { clearInterval(animationInterval); return; }

        obj.set('text', full.substring(0, i));

        fabricCanvas.renderAll();

        i++;

        if (i > full.length) clearInterval(animationInterval);

    }, delay);

}



function _wordRevealAnim(obj, speed) {

    const words = (obj.originalText || obj.text).split(' ');

    let i = 0;

    const delay = 150 / speed;

    animationInterval = setInterval(() => {

        if (!isPlayingAnimation) { clearInterval(animationInterval); return; }

        obj.set('text', words.slice(0, i).join(' '));

        fabricCanvas.renderAll();

        i++;

        if (i > words.length) clearInterval(animationInterval);

    }, delay);

}



function _fadeInAnim(obj, speed) {

    const dur = 1000 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        obj.set('opacity', p); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _slideAnim(obj, dir, speed) {

    const origTop = obj.top;

    const offset  = dir === 'up' ? 80 : -80;

    obj.set({ top: origTop + offset, opacity: 0 }); fabricCanvas.renderAll();

    const dur = 600 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = 1 - Math.pow(1 - p, 3);

        obj.set({ top: origTop + offset * (1 - e), opacity: p }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _zoomInAnim(obj, speed) {

    const dur = 600 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = 1 - Math.pow(1 - p, 3);

        obj.set({ scaleX: 0.1 + 0.9*e, scaleY: 0.1 + 0.9*e, opacity: p }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _popAnim(obj, speed) {

    const dur = 400 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const scale = p < 0.5 ? 1 + p * 0.4 : 1.2 - (p - 0.5) * 0.4;

        obj.set({ scaleX: scale, scaleY: scale, opacity: Math.min(p * 2, 1) }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _bounceAnim(obj, speed) {

    const origTop = obj.top;

    const dur  = 800 / speed;

    const t0   = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const bounce = Math.abs(Math.sin(p * Math.PI * 3)) * (1 - p) * 30;

        obj.set({ top: origTop - bounce, opacity: Math.min(p * 2, 1) }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

        else { obj.set('top', origTop); fabricCanvas.renderAll(); }

    }

    animationFrame = requestAnimationFrame(step);

}



function _karaokeAnim(obj, speed) {

    // Simplified: just typewriter at word level

    _wordRevealAnim(obj, speed);

}



// ── Playback (preview) ────────────────────────────────────────

async function playFullSequence() {

    if (!scenes || !scenes.length) { alert('No scenes to play'); return; }

    if (isPlayingSequence) { stopPlayback(); return; }

    if (isRecording) return;

    hideAllOverlays();

    await preloadAllScenes();

    isPlayingSequence = true;

    showStopBtn();

    playSceneInSequence(0);

}



async function preloadAllScenes() {

    window.preloadCache = window.preloadCache || {};

    const stopBtn = document.getElementById('floatingStopBtn');

    if (stopBtn) { stopBtn.style.display = 'block'; stopBtn.style.background = '#f59e0b'; stopBtn.innerText = '⏳ Loading...'; }

    await Promise.all(scenes.map(scene => new Promise(resolve => {

        const fn = scene.image_file;

        if (!fn || window.preloadCache[fn]) { resolve(); return; }

        const isV = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);

        const el  = isV ? document.createElement('video') : new Image();

        if (isV) { el.preload = 'auto'; el.muted = true; }

        el.onload = el.oncanplaythrough = () => { window.preloadCache[fn] = el; resolve(); };

        el.onerror = resolve;

        setTimeout(resolve, isV ? 8000 : 5000);

        el.src = (isV ? 'podcast_videos/' : 'podcast_images/') + fn;

        if (isV) el.load();

    })));

    if (stopBtn) { stopBtn.style.background = '#dc2626'; stopBtn.innerText = '⏹ Stop'; stopBtn.style.display = 'none'; }

}



async function playSceneInSequence(index) {

    if (!isPlayingSequence || index >= scenes.length) { stopPlayback(); return; }

    const scene = scenes[index];

    if (!scene) { stopPlayback(); return; }



    let audioFile = scene.audio_file || (audio_files && audio_files[scene.id]);

    if (!audioFile) { setTimeout(() => playSceneInSequence(index + 1), 500); return; }



    currentSceneIndex = index;

    currentSceneId    = scene.id;

    updateSceneIndicator();



    // Start background music on first scene

    if (index === 0 && podcastMusicFile) _startBgMusic('preview-bg-music');



    await new Promise(r => setTimeout(r, 0));

    if (fabricCanvas) {

        await loadCurrentSceneToFabric();

        // Start caption animations for this scene

        setTimeout(() => {

            fabricCanvas.getObjects().filter(o => o.captionId).forEach(o => startCaptionAnimation(o));

        }, 200);

    }



    if (podcastMusicFile) {

        // Music mode: use audio duration as timer

        const dur = new Audio();

        let done = false;

        dur.preload = 'metadata';

        dur.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

        dur.onloadedmetadata = () => {

            done = true;

            const ms = dur.duration * 1000;

            dur.src = ''; dur.load();

            setTimeout(() => { if (isPlayingSequence) { stopCaptionAnimation(); playSceneInSequence(index+1); } }, ms);

        };

        dur.onerror = () => {

            if (done) return;

            dur.src = ''; dur.load();

            setTimeout(() => { if (isPlayingSequence) playSceneInSequence(index+1); }, 2000);

        };

        dur.load();

        return;

    }



    // No music — play scene audio

    let player = document.getElementById('seq-audio-' + scene.id);

    if (!player) { player = document.createElement('audio'); player.id = 'seq-audio-' + scene.id; document.body.appendChild(player); }

    player.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    player.load();

    currentAudioPlayer = player;

    player.onended = () => { stopCaptionAnimation(); playSceneInSequence(index+1); };

    player.onerror = () => { stopCaptionAnimation(); setTimeout(() => playSceneInSequence(index+1), 500); };

    try { await player.play(); } catch(e) { stopCaptionAnimation(); setTimeout(() => playSceneInSequence(index+1), 500); }

}



function _startBgMusic(id) {

    const old = document.getElementById(id); if (old) { old.pause(); old.remove(); }

    const el  = document.createElement('audio');

    el.id = id; el.loop = false; el.volume = 0.4; el.preload = 'auto';

    document.body.appendChild(el);

    el.oncanplaythrough = () => { el.oncanplaythrough = null; el.play().catch(() => {}); };

    el.src = '/podcast_music/' + podcastMusicFile; el.load();

}



function stopPlayback() {

    isPlayingSequence = false;

    if (currentAudioPlayer) { currentAudioPlayer.pause(); currentAudioPlayer = null; }

    ['preview-bg-music','render-bg-music'].forEach(id => {

        const el = document.getElementById(id); if (el) { el.pause(); el.remove(); }

    });

    stopCaptionAnimation();

    hideStopBtn();

}



function showStopBtn() { const b = document.getElementById('floatingStopBtn'); if (b) b.style.display = 'block'; }

function hideStopBtn()  { const b = document.getElementById('floatingStopBtn'); if (b) b.style.display = 'none';  }

function handleStopBtn() { isRecording ? stopRecording() : stopPlayback(); hideStopBtn(); }



// ── Recording ─────────────────────────────────────────────────

async function startRecording() {

    if (isRecording) { stopRecording(); return; }

    if (!scenes || !scenes.length) { alert('No scenes to record'); return; }

    isRecording = true;

    recordedChunks = [];

    renderAudioElements = [];

    hideAllOverlays();

    await preloadAllScenes();

    showStopBtn();

    await renderScene(0);

}



// Render scene for recording (same as play but triggers MediaRecorder)

let _renderLock = -1;

async function renderScene(index) {

    if (!isRecording || index >= scenes.length) { finishRecording(); return; }

    if (_renderLock === index) { console.warn('renderScene duplicate call ignored'); return; }

    _renderLock = index;



    const scene = scenes[index];

    if (!scene) { renderScene(index + 1); return; }

    L(`🎥 Rendering scene ${index+1}/${scenes.length}`);



    if (index === 0 && podcastMusicFile) _startBgMusic('render-bg-music');



    currentSceneIndex = index;

    currentSceneId    = scene.id;

    updateSceneIndicator();



    await new Promise(r => setTimeout(r, 0));

    if (fabricCanvas) {

        await loadCurrentSceneToFabric();

        // ── FIX: Start animations during recording too ──

        setTimeout(() => {

            fabricCanvas.getObjects().filter(o => o.captionId).forEach(o => startCaptionAnimation(o));

        }, 200);

        await new Promise(r => setTimeout(r, scene.video_file ? 500 : 100));

    }



    let audioFile = scene.audio_file || (audio_files && audio_files[scene.id]);

    if (!audioFile) { L(`⚠️ Scene ${index+1} no audio — skipping`); setTimeout(() => renderScene(index+1), 500); return; }



    if (podcastMusicFile) {

        // Start recorder on scene 0

        if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) startMediaRecorder();

        const dur = new Audio();

        let done = false;

        dur.preload = 'metadata';

        dur.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

        dur.onloadedmetadata = () => {

            done = true;

            const ms = dur.duration * 1000;

            dur.src = ''; dur.load();

            L(`⏱️ Scene ${index+1}: ${(ms/1000).toFixed(1)}s`);

            setTimeout(() => { if (isRecording) renderScene(index+1); }, ms);

        };

        dur.onerror = () => { if (done) return; setTimeout(() => { if (isRecording) renderScene(index+1); }, 2000); };

        dur.load();

        return;

    }



    // No music — play audio

    const audio = new Audio();

    audio.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    renderAudioElements.push(audio);

    audio.onloadedmetadata = () => {

        if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) startMediaRecorder();

        audio.play()

            .then(() => setTimeout(() => renderScene(index+1), audio.duration * 1000))

            .catch(() => setTimeout(() => renderScene(index+1), 500));

    };

    audio.onerror = () => setTimeout(() => renderScene(index+1), 500);

    audio.load();

}



function startMediaRecorder() {

    L('🎥 Starting MediaRecorder');

    const fabricEl = document.getElementById('fabricCanvas');

    if (!fabricEl) { console.error('Canvas not found'); return; }

    const cW = fabricEl.width, cH = fabricEl.height;



    // ── FIX: composite = video BG + lower canvas + UPPER canvas (captions) ──

    let recordingCanvas = document.getElementById('_recordingCanvas');

    if (!recordingCanvas) {

        recordingCanvas = document.createElement('canvas');

        recordingCanvas.id = '_recordingCanvas';

        recordingCanvas.width  = cW;

        recordingCanvas.height = cH;

        recordingCanvas.style.display = 'none';

        document.body.appendChild(recordingCanvas);

    }

    const rCtx = recordingCanvas.getContext('2d');

    const lowerEl = fabricCanvas.lowerCanvasEl || fabricEl;

    const upperEl = fabricCanvas.upperCanvasEl  || null;



    let compositeActive = true;

    function compositeFrame() {

        if (!compositeActive) return;

        rCtx.clearRect(0, 0, cW, cH);

        // 1. Background video (DOM element)

        const vid = document.getElementById('backgroundVideo');

        if (vid && !vid.paused && !vid.ended) {

            rCtx.drawImage(vid, 0, 0, cW, cH);

        } else {

            rCtx.fillStyle = '#000';

            rCtx.fillRect(0, 0, cW, cH);

        }

        // 2. Fabric lower canvas (images, shapes)

        rCtx.drawImage(lowerEl, 0, 0, cW, cH);

        // 3. Fabric upper canvas (captions, text)

        if (upperEl) rCtx.drawImage(upperEl, 0, 0, cW, cH);

        requestAnimationFrame(compositeFrame);

    }

    compositeFrame();

    window._compositeActive = () => { compositeActive = false; };



    const stream = recordingCanvas.captureStream(30);

    const mimeTypes = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'];

    let mimeType = '';

    for (const m of mimeTypes) { if (MediaRecorder.isTypeSupported(m)) { mimeType = m; break; } }

    const opts = mimeType ? { mimeType, videoBitsPerSecond: 5000000 } : {};



    try {

        mediaRecorder = new MediaRecorder(stream, opts);

        mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) recordedChunks.push(e.data); };

        mediaRecorder.onstop = () => {

            L('⏹️ MediaRecorder stopped — saving');

            saveRecordedVideo();

        };

        mediaRecorder.onerror = e => { console.error('MediaRecorder error:', e); stopRecording(); };

        mediaRecorder.start(1000);

        L('✅ Recording started');

    } catch(e) { console.error('MediaRecorder failed:', e); stopRecording(); }

}



function stopRecording() {

    L('⏹️ stopRecording');

    isRecording = false;

    if (window._compositeActive) { window._compositeActive(); window._compositeActive = null; }

    renderAudioElements.forEach(a => { try { a.pause(); } catch(e) {} });

    renderAudioElements = [];

    if (mediaRecorder && mediaRecorder.state !== 'inactive') {

        mediaRecorder.stop();

    } else {

        saveRecordedVideo();

    }

    const bgMusic = document.getElementById('render-bg-music');

    if (bgMusic) { bgMusic.pause(); bgMusic.remove(); }

    hideStopBtn();

}



function finishRecording() {

    L('🏁 All scenes rendered — stopping recorder');

    if (mediaRecorder && mediaRecorder.state !== 'inactive') {

        mediaRecorder.stop();

    } else {

        saveRecordedVideo();

    }

    const bgMusic = document.getElementById('render-bg-music');

    if (bgMusic) { bgMusic.pause(); bgMusic.remove(); }

    hideStopBtn();

    isRecording = false;

}



function saveRecordedVideo() {

    if (!recordedChunks.length) { L('⚠️ No recorded data'); return; }

    const blob = new Blob(recordedChunks, { type: recordedChunks[0].type || 'video/webm' });

    const url  = URL.createObjectURL(blob);

    const ext  = (recordedChunks[0].type || 'webm').includes('mp4') ? 'mp4' : 'webm';

    const a    = document.createElement('a');

    a.href = url; a.download = 'video_' + Date.now() + '.' + ext;

    a.click();

    URL.revokeObjectURL(url);

    L('✅ Video downloaded');



    // Also upload to server

    const fd = new FormData();

    fd.append('ajax_action', 'save_rendered_video');

    fd.append('podcast_id', <?= (int)$podcast_id ?>);

    fd.append('video_file', blob, 'rendered.' + ext);

    safeFetch(fd).then(({ data }) => {

        if (data.success) L('✅ Saved to server: ' + data.filename);

        else L('⚠️ Server save failed: ' + data.message);

    }).catch(e => L('⚠️ Upload error: ' + e.message));

}



// ── UI helpers ────────────────────────────────────────────────

function hideAllOverlays() {

    document.querySelectorAll('.overlay-panel, #scene-typography-captions, #scene-images, #scene-audio, #fontSizeOverlay, #fontColorOverlay, #fontFamilyPanel, #fontSizePanel, #bgColorPanel, #stylePanel, #effectsPanel, #alignmentPanel, #positionPanel, #animationPanel, #imageSourcePanel, #currentSceneAudioOverlay, #podcastAudioOverlay, #imageSelectorPanel, .action-menu, .font-selector-overlay').forEach(el => {

        el.style.display = 'none';

    });

}



function toggleActionMenu(e) {

    const m = document.getElementById('actionMenu');

    if (!m) return;

    m.classList.toggle('active');

    if (e) e.stopPropagation();

}



function openSceneSettings(type) {

    if (!fabricCanvas) initFabricCanvas();

    hideAllOverlays();

    const panel = document.getElementById('scene-' + type) || document.getElementById('sceneSettingsPanel');

    if (panel) { panel.style.display = 'block'; }

}



function closeScenePanel() {

    hideAllOverlays();

    setSelectionMode(false);

}



// ── Panel population helper ───────────────────────────────────

function loadCaptionIntoPanel(captionId) {

    const caption = findCaptionById(captionId);

    if (!caption) return;

    // Populate whichever UI elements exist

    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };

    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || ''; };

    setVal('captionFontFamily',  caption.fontfamily);

    setVal('captionFontSize',    caption.fontsize);

    setVal('captionFontColor',   caption.fontcolor);

    setVal('captionTextAlign',   caption.text_align);

    setVal('captionAnimStyle',   caption.animation_style);

    setVal('captionAnimSpeed',   caption.animation_speed);

}



// ── Image slot management ─────────────────────────────────────

let selectedImageSlot = 'image_file';

function selectImageSlot(slotName) {

    selectedImageSlot = slotName;

    currentImageField = slotName;

    loadCurrentSceneToFabric();

}



async function saveCurrentImageField() {

    // no-op for now — positions saved via autoSaveCaptionProperty

}



// ── Upload helpers ────────────────────────────────────────────

async function uploadSceneImage(input) {

    if (!input.files || !input.files[0]) return;

    const fd = new FormData();

    fd.append('ajax_action', 'upload_scene_image');

    fd.append('story_id', currentSceneId);

    fd.append('image_field', selectedImageSlot);

    fd.append('image', input.files[0]);

    const { data } = await safeFetch(fd);

    if (data.success) {

        const scene = scenes.find(s => s.id == currentSceneId);

        if (scene) scene[selectedImageSlot] = data.filename;

        await loadCurrentSceneToFabric();

    }

}



// ── Init ──────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {

    updateSceneIndicator();

    // Wait for layout to settle before reading container width

    requestAnimationFrame(function() {

        requestAnimationFrame(function() {

            initFabricCanvas();

        });

    });

});



// Close any named overlay panel

function closeOverlay(id) {

    const el = document.getElementById(id);

    if (el) el.style.display = 'none';

}



// These are placeholders — full implementations to be added next

function showTypographyIcons() {

    const el = document.getElementById('textIcons');

    const pri = document.getElementById('primaryIcons');

    if (el) { el.style.display = el.style.display === 'none' ? 'flex' : 'none'; }

    if (pri) pri.style.display = 'none';

}

function hideTextIcons() {

    const el = document.getElementById('textIcons');

    const pri = document.getElementById('primaryIcons');

    if (el) el.style.display = 'none';

    if (pri) pri.style.display = 'flex';

}

function showAudioIcons()            { L('🔊 Audio icons — coming next'); }

function showImageSelectorOverlay(e) { L('🌄 Image selector — coming next'); }

function showImageSourceOverlay(e)   { L('🖼️ Image source — coming next'); }

function showFontFamilyPanel()       { L('🔤 Font family — coming next'); }

function showFontSizeOptions()       { L('📏 Font size — coming next'); }

function showTextColorOptions()      { L('🎨 Text color — coming next'); }

function showBgColorOptions(e)       { L('🖌️ BG color — coming next'); }

function showStyleOptions()          { L('✒️ Style — coming next'); }

function showAlignment()             { L('⬅️ Alignment — coming next'); }

function showEffects()               { L('✨ Effects — coming next'); }

function showPosition()              { L('📍 Position — coming next'); }

function showAnimation()             { L('⚡ Animation — coming next'); }

function addNewCaption()             { L('➕ Add caption — coming next'); }

function deleteSelectedCaption()     { L('🗑️ Delete caption — coming next'); }

function playPreview()               { playFullSequence(); }

function scheduleVideo()             { L('📅 Schedule — coming next'); }

function TranslateVideo()            { L('🌐 Translate — coming next'); }

function archiveProject()            { L('📦 Archive — coming next'); }

function deleteProject()             { if(confirm('Delete this project?')) L('🗑️ Delete — coming next'); }

function gobackProjects()            { window.location.href = 'vizard_browser.php'; }

function saveSceneSettings()         { L('💾 Save settings — coming next'); }

function saveToAllScenes()           { L('💾 Save to all — coming next'); }



// Close action menu when clicking outside

document.addEventListener('click', function(e) {

    const m = document.getElementById('actionMenu');

    if (m && m.classList.contains('active') && !m.contains(e.target)) {

        m.classList.remove('active');

    }

});



</script>

</body> 

</html>


<?php
// update_scene.php
require_once 'dbconnect_hdb.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Log received data for debugging
error_log("update_scene.php received: " . print_r($_POST, true));
error_log("FILES: " . print_r($_FILES, true));

$row_id = (int)$_POST['row_id'];
$text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? '');
$text_display = mysqli_real_escape_string($conn, $_POST['text_display'] ?? '');
$image_prompt = mysqli_real_escape_string($conn, $_POST['image_prompt'] ?? '');
$logo_flag = (int)($_POST['logo_flag'] ?? 1);

// Check for selected server image (from media library)
$selected_server_image = $_POST['selected_server_image'] ?? '';
$selected_server_video = $_POST['selected_server_video'] ?? '';

error_log("Selected server image: " . $selected_server_image);

// Handle file uploads (these take precedence over server selections)
$image_file = '';
$video_file = '';

// Process uploaded image file
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
    $target_dir = "podcast_images/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    
    $file_ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
    $new_filename = "scene_img_{$row_id}_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target_file)) {
        $image_file = $new_filename;
        error_log("Uploaded image: " . $image_file);
    }
}

// Process uploaded video file
if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] == 0) {
    $target_dir = "podcast_videos/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    
    $file_ext = pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION);
    $new_filename = "scene_video_{$row_id}_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($_FILES['video_file']['tmp_name'], $target_file)) {
        $video_file = $new_filename;
        error_log("Uploaded video: " . $video_file);
    }
}

// Build the UPDATE query
$updates = [
    "text_contents = '$text_contents'",
    "text_display = '$text_display'",
    "prompt = '$image_prompt'",
    "logo_flag = $logo_flag"
];

// Add image file if uploaded
if (!empty($image_file)) {
    $updates[] = "image_file = '$image_file'";
} 
// If no upload but server image selected, use that
elseif (!empty($selected_server_image)) {
    $safe_image = mysqli_real_escape_string($conn, $selected_server_image);
    $updates[] = "image_file = '$safe_image'";
    error_log("Using selected server image: $safe_image");
}

// Add video file if uploaded
if (!empty($video_file)) {
    $updates[] = "video_file = '$video_file'";
}
// If no upload but server video selected, use that
elseif (!empty($selected_server_video)) {
    $safe_video = mysqli_real_escape_string($conn, $selected_server_video);
    $updates[] = "video_file = '$safe_video'";
    error_log("Using selected server video: $safe_video");
}

$sql = "UPDATE hdb_podcast_stories SET " . implode(', ', $updates) . " WHERE id = $row_id";
error_log("SQL: " . $sql);

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'message' => 'Scene updated successfully']);
} else {
    error_log("MySQL Error: " . mysqli_error($conn));
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
?>
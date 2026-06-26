<?php
session_start();
require_once 'dbconnect_hdb.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image_name = mysqli_real_escape_string($conn, $_POST['image_name'] ?? '');
    $media_type = mysqli_real_escape_string($conn, $_POST['media_type'] ?? 'image');
    $tags = mysqli_real_escape_string($conn, $_POST['tags'] ?? '');
    
    // Handle multiple new tags from comma-separated input
    $new_tags_json = $_POST['new_tags'] ?? '[]';
    $new_tags = json_decode($new_tags_json, true);
    
    $response = ['success' => false, 'message' => ''];
    
    if (empty($image_name)) {
        $response['message'] = 'Filename is required';
        echo json_encode($response);
        exit;
    }
    
    // Process new tags if any
    $tags_file = 'tags.txt';
    $all_tags = [];
    
    if (file_exists($tags_file)) {
        $all_tags = file($tags_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
    
    // Add new tags if they don't exist
    $tags_added = [];
    foreach ($new_tags as $new_tag) {
        $new_tag = trim($new_tag);
        if (!empty($new_tag) && !in_array($new_tag, $all_tags)) {
            $all_tags[] = $new_tag;
            $tags_added[] = $new_tag;
        }
    }
    
    // Save updated tags back to file if new tags were added
    if (!empty($tags_added)) {
        file_put_contents($tags_file, implode("\n", $all_tags));
        // Add new tags to the tags string as well
        if (!empty($tags)) {
            $tags .= ',' . implode(',', $tags_added);
        } else {
            $tags = implode(',', $tags_added);
        }
    }
    
    // Get admin_id from session
    $add_by = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
    
    // Check if record exists
    $check = mysqli_query($conn, "SELECT id FROM hdb_image_data WHERE image_name = '$image_name'");
    
    if (mysqli_num_rows($check) > 0) {
        // Update existing
        $sql = "UPDATE hdb_image_data SET 
                image_hashtags = '$tags',
                media_type = '$media_type'
                WHERE image_name = '$image_name'";
    } else {
        // Insert new
        $sql = "INSERT INTO hdb_image_data 
                (image_name, image_hashtags, media_type, add_by, created_at) 
                VALUES (
                    '$image_name', 
                    '$tags', 
                    '$media_type', 
                    $add_by, 
                    NOW()
                )";
    }
    
    if (mysqli_query($conn, $sql)) {
        $response['success'] = true;
        $response['message'] = 'Tags saved successfully';
        if (!empty($tags_added)) {
            $response['new_tags'] = $tags_added;
        }
    } else {
        $response['message'] = 'Database error: ' . mysqli_error($conn);
    }
    
    echo json_encode($response);
    exit;
}
?>
<?php
session_start(); // Keep this in case you need other session data later
require_once 'dbconnect_hdb.php';

// Set header for JSON response
header('Content-Type: application/json');

// Get POST data
$filename = $_POST['filename'] ?? '';
$type = $_POST['type'] ?? '';

// 1. Basic Validation
if (empty($filename) || empty($type)) {
    echo json_encode(['success' => false, 'message' => 'Filename and type are required']);
    exit;
}

// 2. Security: Clean the filename to prevent directory traversal
$filename = basename($filename);

// 3. Determine Directory
if ($type === 'image') {
    $directory = 'podcast_images/';
} elseif ($type === 'video') {
    $directory = 'podcast_videos/';
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid media type']);
    exit;
}

$filepath = $directory . $filename;

// 4. Perform Deletion
if (file_exists($filepath)) {
    if (unlink($filepath)) {
        // Also remove from the database so it doesn't show up in "tagged" results
        $escaped_filename = mysqli_real_escape_string($conn, $filename);
        $delete_sql = "DELETE FROM hdb_image_data WHERE image_name = '$escaped_filename'";
        mysqli_query($conn, $delete_sql);

        echo json_encode(['success' => true, 'message' => 'File and DB record deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unlink failed. Check server permissions.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'File not found at: ' . $filepath]);
}
exit;
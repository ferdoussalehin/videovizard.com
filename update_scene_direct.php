<?php
// update_scene_direct.php
require_once 'dbconnect_hdb.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$row_id = (int)$_POST['row_id'];
$image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? ''); // Changed to match JavaScript

if (empty($row_id) || empty($image_file)) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit;
}

// Update only the image_file field
$sql = "UPDATE hdb_podcast_stories SET image_file = '$image_file' WHERE id = $row_id";

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'message' => 'Image updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
?>
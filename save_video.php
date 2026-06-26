<?php
header('Content-Type: application/json');

$uploadDir = 'podcast_videos/';

// Create folder if it doesn't exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No video file received']);
    exit;
}

$title = isset($_POST['title']) ? $_POST['title'] : 'untitled';
// Clean filename
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
$filename = $safeName . '.webm';
$filepath = $uploadDir . $filename;

// If file exists, overwrite it (same title = same file)
if (move_uploaded_file($_FILES['video']['tmp_name'], $filepath)) {
    echo json_encode([
        'success' => true, 
        'filename' => $filename,
        'path' => $filepath
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
?>
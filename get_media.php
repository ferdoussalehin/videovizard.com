<?php
/**
 * get_media.php - Standalone endpoint for fetching media files
 */
session_start();
require_once 'dbconnect_hdb.php';

header('Content-Type: application/json');
error_log("get_media.php accessed with POST: " . print_r($_POST, true));
// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$type = $_POST['type'] ?? 'all';
$response = [];

// Helper function for file size formatting
function formatFileSize($bytes) {
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}

// Get images
if ($type === 'images' || $type === 'all') {
    $image_dir = 'podcast_images/';
    $images = [];
    if (is_dir($image_dir)) {
        $files = scandir($image_dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
                $filepath = $image_dir . $file;
                $images[] = [
                    'name' => $file,
                    'path' => $filepath,
                    'formatted_size' => formatFileSize(filesize($filepath))
                ];
            }
        }
    }
    $response['images'] = $images;
}

// Get videos
if ($type === 'videos' || $type === 'all') {
    $video_dir = 'podcast_videos/';
    $videos = [];
    if (is_dir($video_dir)) {
        $files = scandir($video_dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && preg_match('/\.(mp4|webm|mov|avi|mkv)$/i', $file)) {
                $filepath = $video_dir . $file;
                $videos[] = [
                    'name' => $file,
                    'path' => $filepath,
                    'formatted_size' => formatFileSize(filesize($filepath))
                ];
            }
        }
    }
    $response['videos'] = $videos;
}

echo json_encode(['success' => true, 'data' => $response]);
exit;
?>
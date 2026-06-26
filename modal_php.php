<?php
/**
 * Get media files from server
 * Call this via AJAX to load images and videos
 */

require_once 'dbconnect_hdb.php';

// Get all images from podcast_images folder
function getImageFiles() {
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
                    'size' => filesize($filepath),
                    'formatted_size' => formatFileSize(filesize($filepath)),
                    'type' => 'image'
                ];
            }
        }
    }
    
    return $images;
}

// Get all videos from podcast_videos folder
function getVideoFiles() {
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
                    'size' => filesize($filepath),
                    'formatted_size' => formatFileSize(filesize($filepath)),
                    'type' => 'video'
                ];
            }
        }
    }
    
    return $videos;
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}

// Handle AJAX request for media
if (isset($_POST['action']) && $_POST['action'] === 'get_media') {
    header('Content-Type: application/json');
    
    $type = $_POST['type'] ?? 'all';
    $response = [];
    
    if ($type === 'images' || $type === 'all') {
        $response['images'] = getImageFiles();
    }
    
    if ($type === 'videos' || $type === 'all') {
        $response['videos'] = getVideoFiles();
    }
    
    echo json_encode(['success' => true, 'data' => $response]);
    exit;
}
?>
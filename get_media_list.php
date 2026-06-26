<?php
/**
 * Scans media directories and returns JSON for the modal library.
 */

$imageDir = 'podcast_images/';
$videoDir = 'podcast_videos/';

$response = [
    'images' => [],
    'videos' => []
];

// Helper function to scan directory for specific extensions
function getFiles($dir, $extensions) {
    $results = [];
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions)) {
                $results[] = [
                    'name' => $file,
                    'url' => $dir . $file,
                    'thumb' => $dir . $file // For videos, we use the video itself or a placeholder
                ];
            }
        }
    }
    return $results;
}

$response['images'] = getFiles($imageDir, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
$response['videos'] = getFiles($videoDir, ['mp4', 'webm', 'mov', 'avi']);

header('Content-Type: application/json');
echo json_encode($response);
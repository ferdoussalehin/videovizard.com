<?php
/**
 * get_media_list.php
 * Scans local server directories and returns a JSON list of available media.
 */

// Define your directories relative to this file
$imageDir = 'podcast_images/';
$videoDir = 'podcast_videos/';

$response = [
    'images' => [],
    'videos' => []
];

/**
 * Scans a directory for specific file extensions
 */
function scanMedia($dir, $extensions) {
    $foundFiles = [];
    if (is_dir($dir)) {
        // Get all files and filter out dots
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $pathInfo = pathinfo($file);
            $ext = strtolower($pathInfo['extension'] ?? '');
            
            if (in_array($ext, $extensions)) {
                $foundFiles[] = [
                    'name' => $file,
                    'url' => $dir . $file,
                    'size' => filesize($dir . $file)
                ];
            }
        }
    }
    return $foundFiles;
}

// Populate the response
$response['images'] = scanMedia($imageDir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
$response['videos'] = scanMedia($videoDir, ['mp4', 'webm', 'ogg', 'mov']);

// Return JSON header and data
header('Content-Type: application/json');
echo json_encode($response);
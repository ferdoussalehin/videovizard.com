<?php

/**
 * Standalone Audio Stitching Script
 * --------------------------------
 * - Define input audio files as an array
 * - Define output filename
 * - Uses FFmpeg to stitch audio cleanly
 */

// =======================
// CONFIGURATION
// =======================
require_once __DIR__ . '/script_stiching.php';



//require_once __DIR__ . '/mp3-stitcher.php'; 

$audioFiles = [
    'blog_audios/free_ar_deepening_1.mp3',
    
	'blog_audios/free_ar_deepening_2.mp3' 
	
	
    
];

$outputFile = 'audio_files/free_ar_deepening.mp3';   

// Validate
$validFiles = [];
foreach ($audioFiles as $file) {

    $fullPath = __DIR__ . '/' . ltrim($file, '/');

    //echo "<br>Checking: " . $fullPath;

    if (file_exists($fullPath)) {
        $validFiles[] = $fullPath;
		echo "<br>Checking - file exists : " . $fullPath;
    } else {
        error_log("File not found, skipped: $fullPath");
		echo "<br>file not found............ " . $fullPath;die;
    }
}

if (count($validFiles) < 1) {
    die("Not enough valid audio files to stitch.");
}
//echo "<br>";
//var_dump ($validFiles);
try {
    $stitcher = new MP3Stitcher();
    $stitcher->stitch($validFiles, $outputFile);
    echo "<br>✅ ........Audio stitched successfully: $outputFile";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}

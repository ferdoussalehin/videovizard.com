<?php
// ============================================
// WebM to MP4 Converter
// ============================================

// --- CONFIGURATION ---
$input_file  = '/var/www/html/videovizard.com/published_videos/podcast_102.webm';
$output_file = '/var/www/html/videovizard.com/published_videos/podcast_102.mp4';
$ffmpeg      = '/usr/bin/ffmpeg';

// --- PRE-FLIGHT CHECKS -555555555555555555                       ddddddd--
echo "<h2>WebM → MP4 Converter</h2><pre>";

if (!file_exists($ffmpeg)) {
    die("❌ FFmpeg not found at: $ffmpeg\n");
}
echo "✅ FFmpeg found\n";

if (!file_exists($input_file)) {
    die("❌ Input file not found: $input_file\n");
}
echo "✅ Input file found: $input_file\n";

if (file_exists($output_file)) {
    echo "⚠️  Output file already exists, it will be overwritten\n";
}

// --- BUILD FFMPEG COMMAND ---
$cmd = "$ffmpeg -y -i " . escapeshellarg($input_file) .
       " -c:v libx264" .
       " -preset fast" .
       " -crf 23" .
       " -c:a aac" .
       " -b:a 128k" .
       " -movflags +faststart" .
       " " . escapeshellarg($output_file) .
       " 2>&1";

echo "\n--- Running FFmpeg ---\n";
echo "Command: $cmd\n\n";

// --- RUN CONVERSION ---
$start = microtime(true);
exec($cmd, $output, $return_code);
$elapsed = round(microtime(true) - $start, 2);

echo implode("\n", $output) . "\n";

// --- RESULT ---
echo "\n--- Result ---\n";
if ($return_code === 0 && file_exists($output_file)) {
    $input_size  = round(filesize($input_file) / 1024 / 1024, 2);
    $output_size = round(filesize($output_file) / 1024 / 1024, 2);
    echo "✅ Conversion successful!\n";
    echo "⏱️  Time taken : {$elapsed}s\n";
    echo "📥 Input size  : {$input_size} MB\n";
    echo "📤 Output size : {$output_size} MB\n";
    echo "📁 Saved to    : $output_file\n";
} else {
    echo "❌ Conversion FAILED (exit code: $return_code)\n";
}

echo "</pre>";
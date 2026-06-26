<?php
// FFmpeg Server Check Script

echo "<h2>FFmpeg Server Check</h2>";
echo "<pre>";

// 1. Check if exec() is available
if (!function_exists('exec')) {
    echo "❌ exec() function is DISABLED on this server.\n";
    echo "   FFmpeg cannot be run without exec().\n";
    exit;
}
echo "✅ exec() is available\n\n";

// 2. Try to find FFmpeg path
$possible_paths = [
    'ffmpeg',                      // In system PATH
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/homebrew/bin/ffmpeg',    // macOS Homebrew
    '/snap/bin/ffmpeg',            // Ubuntu snap
];

$ffmpeg_path = null;
foreach ($possible_paths as $path) {
    $output = [];
    $return_code = 0;
    exec("$path -version 2>&1", $output, $return_code);
    if ($return_code === 0 && !empty($output)) {
        $ffmpeg_path = $path;
        break;
    }
}

if (!$ffmpeg_path) {
    echo "❌ FFmpeg NOT FOUND in any common path.\n";
    echo "   Tried: " . implode(', ', $possible_paths) . "\n\n";
} else {
    echo "✅ FFmpeg FOUND at: $ffmpeg_path\n\n";

    // 3. FFmpeg version
    $output = [];
    exec("$ffmpeg_path -version 2>&1", $output);
    echo "--- Version Info ---\n";
    echo implode("\n", array_slice($output, 0, 4)) . "\n\n";

    // 4. Check FFprobe
    $ffprobe_path = str_replace('ffmpeg', 'ffprobe', $ffmpeg_path);
    exec("$ffprobe_path -version 2>&1", $probe_out, $probe_code);
    if ($probe_code === 0) {
        echo "✅ FFprobe FOUND at: $ffprobe_path\n";
        echo $probe_out[0] . "\n\n";
    } else {
        echo "⚠️  FFprobe NOT found at: $ffprobe_path\n\n";
    }

    // 5. Check encoders relevant to video SaaS
    echo "--- Key Encoders ---\n";
    $check_encoders = ['libx264', 'libx265', 'aac', 'mp3', 'libvpx', 'libopus'];
    exec("$ffmpeg_path -encoders 2>&1", $enc_out);
    $enc_list = implode("\n", $enc_out);
    foreach ($check_encoders as $enc) {
        echo (strpos($enc_list, $enc) !== false ? "✅" : "❌") . " $enc\n";
    }

    // 6. Check decoders
    echo "\n--- Key Decoders ---\n";
    exec("$ffmpeg_path -decoders 2>&1", $dec_out);
    $dec_list = implode("\n", $dec_out);
    $check_decoders = ['h264', 'hevc', 'aac', 'mp3', 'vp8', 'vp9'];
    foreach ($check_decoders as $dec) {
        echo (strpos($dec_list, $dec) !== false ? "✅" : "❌") . " $dec\n";
    }

    // 7. Check formats
    echo "\n--- Common Formats ---\n";
    exec("$ffmpeg_path -formats 2>&1", $fmt_out);
    $fmt_list = implode("\n", $fmt_out);
    $check_formats = ['mp4', 'mov', 'webm', 'flv', 'avi', 'mp3', 'wav'];
    foreach ($check_formats as $fmt) {
        echo (strpos($fmt_list, $fmt) !== false ? "✅" : "❌") . " $fmt\n";
    }

    // 8. Which path (confirms it's in PATH)
    $which = shell_exec("which ffmpeg 2>&1");
    echo "\n--- which ffmpeg ---\n";
    echo trim($which) ?: "Not in PATH" ;
    echo "\n";
}

// 9. PHP exec/shell function availability
echo "\n--- PHP Shell Functions ---\n";
$funcs = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open'];
foreach ($funcs as $f) {
    echo (function_exists($f) ? "✅" : "❌") . " $f()\n";
}

// 10. Server info
echo "\n--- Server Info ---\n";
echo "PHP version : " . PHP_VERSION . "\n";
echo "OS          : " . PHP_OS . "\n";
echo "Server      : " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";

echo "</pre>";
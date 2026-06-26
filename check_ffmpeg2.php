<?php
echo "<pre>";
$paths = [
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/ffmpeg/bin/ffmpeg',
    '/bin/ffmpeg',
];
foreach ($paths as $path) {
    echo $path . ' — ' . (file_exists($path) ? 'FOUND' : 'not found') . "\n";
}
echo "</pre>";
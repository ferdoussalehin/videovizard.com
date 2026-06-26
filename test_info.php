<?php
// info.php
echo "<pre>";
echo "CURLOPT_TIMEOUT in videomaker.php:\n";
$content = file_get_contents(__DIR__ . '/videomaker.php');
preg_match_all('/CURLOPT_TIMEOUT.*/', $content, $matches);
print_r($matches[0]);

echo "\nFiles in published_videos:\n";
foreach (glob(__DIR__ . '/published_videos/*') as $f) {
    echo basename($f) . " — " . round(filesize($f)/1024/1024, 2) . " MB\n";
}
echo "</pre>";
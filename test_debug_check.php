<?php
$image_path = '/home/syjy0p3q5yjb/public_html/videovizard.com/podcast_images/host_female_3_p4.png';
$audio_path = '/home/syjy0p3q5yjb/public_html/videovizard.com/podcast_audios/voice_619_3031_en.mp3';

$postData = [
    'source_image' => new CURLFile($image_path),
    'driven_audio' => new CURLFile($audio_path),
    'pose_style'   => '12',
    'exp_scale'    => '1.1',
    'still_mode'   => 'false'
];

$ch = curl_init('https://inaamalvi1--automated-sadtalker-4k-revert-fastapi-app.modal.run/generate');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['X-API-Key: sad-tk-8yNcfGp152Qf'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 900,
    CURLOPT_VERBOSE        => true  // extra detail
]);

$verbose_log = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose_log);

$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

rewind($verbose_log);
$verbose_output = stream_get_contents($verbose_log);

echo "<b>HTTP Code:</b> $http_code<br>";
echo "<b>cURL Error:</b> " . (empty($curl_err) ? 'none' : $curl_err) . "<br>";
echo "<b>Total time:</b> {$total_time}s<br><br>";

echo "<b>Raw Response:</b><br><pre>" . htmlspecialchars($response) . "</pre>";
echo "<b>Verbose log:</b><br><pre>" . htmlspecialchars($verbose_output) . "</pre>";
?>
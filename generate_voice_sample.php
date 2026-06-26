<?php
session_start();
include 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';

header('Content-Type: application/json');

$text = $_POST['text'] ?? '';
$voice_id = $_POST['voice_id'] ?? '';
$lang_code = $_POST['lang_code'] ?? 'en';
$rate = floatval($_POST['rate'] ?? 1.0);
$speed = floatval($_POST['speed'] ?? 1.0);

if (!$text || !$voice_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$audio_dir = __DIR__ . '/podcast_audios/';
if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);

$filename = 'sample_' . time() . '_' . mt_rand(1000, 9999) . '.mp3';
$filepath = $audio_dir . $filename;

// Generate audio with the sample text
$result = generateVoice($text, $voice_id, $rate, $filepath);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'audio_url' => 'podcast_audios/' . $filename
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Generation failed']);
}
?>
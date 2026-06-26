<?php
session_start();
include __DIR__ . '/dbconnect_hdb.php';

$podcast_id = (int)($_GET['podcast_id'] ?? 3);

// Load podcast
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1"));

echo "<pre>";
echo "=== PODCAST ROW ===\n";
echo "id={$row['id']} host_voice={$row['host_voice']} voice_rate={$row['voice_rate']} video_media={$row['video_media']}\n";
echo "script_text length=" . strlen($row['script_text'] ?? '') . "\n\n";

// Test wizard_step2.php include directly
echo "=== TESTING wizard_step2.php include ===\n";

$_POST = [
    'action'      => 'create_scenes_from_podcast',
    'podcast_id'  => $podcast_id,
    'host_voice'  => $row['host_voice'] ?: 'openai:alloy',
    'guest_voice' => $row['guest_voice'] ?: 'openai:alloy',
    'rate'        => $row['voice_rate'] ?: 1.0,
    'lang_code'   => $row['lang_code'] ?: 'en',
];
$_REQUEST = $_POST;

echo "POST being sent: " . json_encode($_POST) . "\n\n";

ob_start();
include __DIR__ . '/wizard_step2.php';
$output = ob_get_clean();

$_POST = []; $_REQUEST = [];

echo "RAW OUTPUT (" . strlen($output) . " chars):\n";
echo htmlspecialchars(substr($output, 0, 2000)) . "\n\n";

$decoded = json_decode($output, true);
echo "JSON DECODE RESULT:\n";
echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
echo "json_last_error: " . json_last_error_msg() . "\n";
echo "</pre>";

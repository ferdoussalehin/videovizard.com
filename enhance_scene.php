<?php
// enhance_all_scenes.php
// Receives all scene texts at once, fires all OpenAI calls in parallel
// via curl_multi_exec, returns all results together.
// This avoids PHP session-lock serialization that occurs when JS fires
// multiple fetch() calls to enhance_scene.php simultaneously.

if (session_status() === PHP_SESSION_NONE) session_start();
session_write_close(); // release session lock immediately — we don't need it

header('Content-Type: application/json');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');

require_once __DIR__ . '/config.php'; // provides $apiKey

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['scenes'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

$scenes      = $data['scenes'];      // array of {system, message, index}
$totalScenes = count($scenes);

// ── Build one curl handle per scene ──────────────────────────────────────────
$mh      = curl_multi_init();
$handles = [];

foreach ($scenes as $idx => $scene) {
    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => $scene['system']],
            ['role' => 'user',   'content' => $scene['message']],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 2000,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    curl_multi_add_handle($mh, $ch);
    $handles[$idx] = $ch;
}

// ── Execute all in parallel ───────────────────────────────────────────────────
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh); // wait for activity rather than busy-looping
} while ($running > 0);

// ── Collect results ───────────────────────────────────────────────────────────
$results = [];

foreach ($handles as $idx => $ch) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body     = curl_multi_getcontent($ch);

    if ($httpCode === 200 && $body) {
        $decoded = json_decode($body, true);
        $text    = $decoded['choices'][0]['message']['content'] ?? '';
        $results[$idx] = ['success' => true, 'response' => $text];
    } else {
        $results[$idx] = ['success' => false, 'error' => 'OpenAI HTTP ' . $httpCode];
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

// Return as ordered array matching input scene order
$ordered = [];
for ($i = 0; $i < $totalScenes; $i++) {
    $ordered[] = $results[$i] ?? ['success' => false, 'error' => 'Missing result'];
}

echo json_encode(['success' => true, 'results' => $ordered]);
?>

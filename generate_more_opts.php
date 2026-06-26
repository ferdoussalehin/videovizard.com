<?php
// generate_more_opts.php
// Accepts: POST JSON { "prompt": "..." }
// Returns: JSON { "success": true, "items": [...] }

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ob_start();
header('Content-Type: application/json');

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

function logIt($msg) {
    error_log(date('Y-m-d H:i:s') . ' [more_opts] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e['message'], 'items' => []]);
    }
});

// ── ChatGPT call ──────────────────────────────────────────────
function callGPT($prompt) {
    require_once __DIR__ . '/config.php';
    $apiKey = $chatgpt_api_key ?? '';

    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.85,
        'max_tokens'  => 600,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError)      return ['success' => false, 'error' => 'cURL: ' . $curlError];
    if ($httpCode !== 200) return ['success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$content) return ['success' => false, 'error' => 'No content in response'];

    return ['success' => true, 'response' => trim($content)];
}

// ── Parse the GPT response into a clean array ─────────────────
function parseItems($raw) {
    $raw = trim($raw);

    // Strip markdown fences
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/i',          '', $raw);
    $raw = trim($raw);

    // Try JSON array first
    $arr = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
        return array_values(array_filter(array_map('trim', $arr)));
    }

    // Fallback: numbered/bulleted lines
    $lines = explode("\n", $raw);
    $items = [];
    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^[\d]+[.)]\s*/', '', $line);
        $line = preg_replace('/^[-*•]\s*/',     '', $line);
        $line = trim($line, '"\'');
        $line = trim($line);
        if ($line !== '') $items[] = $line;
    }
    return $items;
}

// ── Main ─────────────────────────────────────────────────────
try {
    $raw = file_get_contents('php://input');
    logIt('RAW INPUT: ' . substr($raw, 0, 300));

    if (empty($raw)) throw new Exception('Empty request body');

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid JSON: ' . json_last_error_msg());

    $prompt = trim($data['prompt'] ?? '');
    if (empty($prompt)) throw new Exception('prompt field is missing or empty');

    logIt('PROMPT (first 200): ' . substr($prompt, 0, 200));

    $result = callGPT($prompt);
    if (!$result['success']) throw new Exception($result['error']);

    logIt('GPT RESPONSE (first 200): ' . substr($result['response'], 0, 200));

    $items = parseItems($result['response']);
    logIt('PARSED ' . count($items) . ' items');

    ob_clean();
    echo json_encode(['success' => true, 'items' => $items]);

} catch (Throwable $e) {
    logIt('ERROR: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'items' => []]);
}
?>

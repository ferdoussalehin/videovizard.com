<?php
ob_start();
header('Content-Type: application/json');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

function logError($msg) {
    error_log(date("Y-m-d H:i:s") . " - " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL) {
        ob_clean();
        echo json_encode(["success" => false, "error" => "Fatal: " . $error['message'], "titles" => []]);
    }
});

// ---------------- ChatGPT API ----------------
function callChatGPT($prompt, $model = "gpt-4o-mini") {
    require_once __DIR__ . '/config.php';
    $apiKey = $apiKey ?? $chatgpt_api_key ?? '';
    $data = [
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens'  => 1000
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($curl_error)        return ['success' => false, 'error' => $curl_error,        'response' => ''];
    if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code",  'response' => ''];
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return ['success' => true, 'response' => $result['choices'][0]['message']['content']];
    }
    return ['success' => false, 'error' => 'Unexpected response', 'response' => ''];
}

// ---------------- Generate Video Titles ----------------
function generateTitles($niche, $category, $count = 7, $goal = '') {
    $goalLine = $goal ? "Campaign Goal: $goal" : '';
    $prompt = "You are an expert short-form video content creator specialising in '$niche'.
A content creator has chosen the category: '$category'.
{$goalLine}
Generate {$count} specific, engaging video topic ideas for short social media videos (Reels, TikTok, YouTube Shorts) within this category.
Each topic should be punchy, specific, and relevant to '$niche' professionals trying to attract clients.
Return ONLY a valid JSON array of topic strings, no extra text, no numbering, no markdown.
Example format: [\"Topic one here\", \"Topic two here\", \"Topic three here\"]";

    $response = callChatGPT($prompt);
    if (!$response['success']) {
        logError("ChatGPT error: " . $response['error']);
        return [];
    }
    $content = trim($response['response']);
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/^```\s*/i',     '', $content);
    $content = preg_replace('/```\s*$/i',     '', $content);
    $content = trim($content);

    $titles = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($titles)) {
        return array_values($titles);
    }
    $lines = explode("\n", $content);
    return array_values(array_filter(array_map('trim', $lines)));
}

// ---------------- Main ----------------
try {
    $raw = file_get_contents("php://input");
    logError("RAW INPUT (titles): " . $raw);
    $data = json_decode($raw, true);
    if (!$data) throw new Exception("Invalid JSON input");

    $niche    = trim($data['niche']    ?? '');
    $category = trim($data['topic']    ?? $data['category'] ?? ''); // wizard sends as 'topic', campaign sends as 'category'
    $count    = (int)($data['count']   ?? 7);
    $goal     = trim($data['goal']     ?? '');

    if (!$niche)    throw new Exception("Niche is missing");
    if (!$category) throw new Exception("Category is missing");

    // Clamp count to a safe range
    $count = max(7, min($count, 30));

    logError("Niche: $niche | Category: $category | Count: $count | Goal: $goal");

    $titles = generateTitles($niche, $category, $count, $goal);
    ob_clean();
    echo json_encode(['success' => true, 'titles' => $titles]);
    exit;

} catch (Throwable $e) {
    logError("ERROR (titles): " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'titles' => []]);
    exit;
}
?>

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
        echo json_encode(["success" => false, "error" => "Fatal: " . $error['message'], "categories" => []]);
    }
});

// ---------------- ChatGPT API ----------------
function callChatGPT($prompt, $model = "gpt-4o-mini") {
    require_once __DIR__ . '/config.php';
    $apiKey = $chatgpt_api_key ?? '';
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
    if ($curl_error)        return ['success' => false, 'error' => $curl_error,       'response' => ''];
    if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code", 'response' => ''];
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return ['success' => true, 'response' => $result['choices'][0]['message']['content']];
    }
    return ['success' => false, 'error' => 'Unexpected response', 'response' => ''];
}

// ---------------- Generate Categories ----------------
function generateCategories($niche) {
    $prompt = "You are an expert in the niche: $niche.

List 6-8 broad INDUSTRY SUBCATEGORIES within this niche.

A category must represent a distinct segment, specialization, problem area, or target group within the niche — NOT content types, themes, or video ideas.

If the niche is problem-based, categories should be problems or outcomes (e.g., Anxiety, Depression, Weight Loss).
If the niche is industry-based, categories should be market segments or service types (e.g., Residential Real Estate, Commercial Real Estate, Luxury Properties).

Do NOT include anything like tips, trends, strategies, advice, or content formats.

Return ONLY a valid JSON array of category name strings, no extra text.";

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

    $categories = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($categories)) {
        return array_values($categories);
    }
    $lines = explode("\n", $content);
    return array_values(array_filter(array_map('trim', $lines)));
}

// ---------------- Main ----------------
try {
    $raw  = file_get_contents("php://input");
    logError("RAW INPUT (categories): " . $raw);
    $data  = json_decode($raw, true);
    if (!$data) throw new Exception("Invalid JSON input");
    $niche = trim($data['niche'] ?? '');
    if (!$niche) throw new Exception("Niche is missing");
    logError("Niche: " . $niche);

    $categories = generateCategories($niche);
    ob_clean();
    echo json_encode(['success' => true, 'categories' => $categories]);
    exit;

} catch (Throwable $e) {
    logError("ERROR (categories): " . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'categories' => []]);
    exit;
}
?>

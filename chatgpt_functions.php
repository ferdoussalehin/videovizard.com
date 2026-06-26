<?php
// ---------------- ChatGPT API ----------------
function callChatGPT_inam($prompt, $model = "gpt-4o-mini") {
    $myApiKey ="YOUR_API_KEY_HERE"; // <-- replace with your OpenAI key

    $data = [
        'model' => $model,
        'messages' => [
            ['role'=>'user','content'=>$prompt]
        ],
        'temperature'=>0.7,
        'max_tokens'=>16000
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $myApiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['success'=>false,'error'=>$curl_error,'response'=>''];
    if ($http_code !== 200) return ['success'=>false,'error'=>"HTTP $http_code",'response'=>''];

    $result = json_decode($response,true);
    if (isset($result['choices'][0]['message']['content'])) {
        return ['success'=>true,'response'=>$result['choices'][0]['message']['content']];
    }
    return ['success'=>false,'error'=>'Unexpected response structure','response'=>''];
}

// ---------------- Generate Categories ----------------
function generateCategories($niche) {
    $prompt = "You are an expert in the niche: $niche.
List 5-7 broad categories for short videos in this niche.
Do NOT provide specific video topics or titles.
Return ONLY a JSON array of category names.
Example:
[\"Residential Real Estate\", \"Commercial Real Estate\", \"Rental Properties\", \"New Construction\", \"Luxury Properties\"]";

    $response = callChatGPT_inam($prompt);
    if (!$response['success']) return [];

    $content = trim($response['response']);
    $categories = json_decode($content,true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($categories)) return $categories;

    // fallback: split by lines
    $lines = explode("\n", $content);
    return array_values(array_filter(array_map('trim',$lines)));
}

// ---------------- Generate Video Titles ----------------
function generateTitles($niche,$category) {
    $prompt = "You are an expert video content creator.
Generate 5 creative video title ideas for short videos in the niche '$niche' and category '$category'.
Return ONLY a JSON array of titles.";

    $response = callChatGPT_inam($prompt);
    if (!$response['success']) return [];

    $content = trim($response['response']);
    $titles = json_decode($content,true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($titles)) return $titles;

    // fallback: split lines
    $lines = explode("\n",$content);
    return array_values(array_filter(array_map('trim',$lines)));
}
?>
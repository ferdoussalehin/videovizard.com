<?php

function callChatGPT_inam2($prompt, $model = 'gpt-4o-mini', $max_tokens = 2000, $temperature = 0.7) {
    
     error_log("++++++++++++ callChatGPT_inam " . $aiResponse. PHP_EOL, 3, __DIR__ . "/a_debug.log");

    
    // ✅ GET API KEY
    $api_key = "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";  // Replace with your key
    
    if (empty($api_key) || $api_key === 'YOUR_API_KEY_HERE') {
        echo "❌ DEBUG: API key not set\n";
        return [
            'success' => false,
            'response' => '',
            'error' => 'OpenAI API key not configured'
        ];
    }
    
    echo "✅ DEBUG: API key found (length: " . strlen($api_key) . ")\n";
    
    // ✅ PREPARE REQUEST
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => $max_tokens,
        'temperature' => $temperature
    ];
    
    echo "✅ DEBUG: Request prepared\n";
    echo "   Model: $model\n";
    echo "   Max tokens: $max_tokens\n";
    echo "   Prompt length: " . strlen($prompt) . " chars\n";
    
    // ✅ CHECK IF CURL EXISTS
    if (!function_exists('curl_init')) {
        echo "❌ DEBUG: cURL not available\n";
        return [
            'success' => false,
            'response' => '',
            'error' => 'cURL extension not installed'
        ];
    }
    
    echo "✅ DEBUG: cURL is available\n";
    
    // ✅ MAKE API CALL
    echo "🌐 DEBUG: Initiating API call...\n";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);  // Reduced timeout for testing
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);  // Connection timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);  // Verify SSL
    
    echo "📡 DEBUG: Executing request...\n";
    $start_time = microtime(true);
    
    $response = curl_exec($ch);
    
    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    
    echo "⏱️ DEBUG: Request completed in {$duration}s\n";
    echo "📊 DEBUG: HTTP Code: $http_code\n";
    
    curl_close($ch);
    
    // ✅ HANDLE ERRORS
    if ($curl_error) {
        echo "❌ DEBUG: cURL Error #$curl_errno: $curl_error\n";
        return [
            'success' => false,
            'response' => '',
            'error' => "cURL error #$curl_errno: $curl_error"
        ];
    }
    
    if ($http_code !== 200) {
        echo "❌ DEBUG: HTTP Error: $http_code\n";
        echo "   Response: " . substr($response, 0, 200) . "\n";
        
        $error_data = json_decode($response, true);
        $error_message = $error_data['error']['message'] ?? 'Unknown error';
        
        return [
            'success' => false,
            'response' => '',
            'error' => "API error ($http_code): $error_message",
            'raw_response' => $response
        ];
    }
    
    echo "✅ DEBUG: HTTP 200 OK\n";
    
    // ✅ PARSE RESPONSE
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ DEBUG: JSON Parse Error: " . json_last_error_msg() . "\n";
        return [
            'success' => false,
            'response' => '',
            'error' => 'Failed to parse JSON: ' . json_last_error_msg()
        ];
    }
    
    $content = $result['choices'][0]['message']['content'] ?? '';
    
    echo "✅ DEBUG: Success! Response length: " . strlen($content) . " chars\n";
    
    return [
        'success' => true,
        'response' => trim($content),
        'error' => '',
        'usage' => $result['usage'] ?? []
    ];
}
?>
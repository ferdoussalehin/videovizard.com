<?php
function generateAzureSpeech($text, $apiKey, $region) {
    // Step 1: Get token (we know this works!)
    $tokenUrl = "https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken";
    
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Ocp-Apim-Subscription-Key: ' . $apiKey,
        'Content-Length: 0'
    ]);
    
    $token = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return ['success' => false, 'error' => 'Failed to get token'];
    }
    
    // Step 2: Generate speech with token
    $ttsUrl = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1";
    
    $ssml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="en-US">' .
            '<voice name="en-US-AriaNeural">' . 
            htmlspecialchars($text) . 
            '</voice>' .
            '</speak>';
    
    $ch = curl_init($ttsUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/ssml+xml',
        'X-Microsoft-OutputFormat: audio-16khz-128kbitrate-mono-mp3',
        'User-Agent: ZuzooTherapy/1.0'
    ]);
    
    $audioData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        return [
            'success' => true,
            'audio' => $audioData,
            'size' => strlen($audioData)
        ];
    } else {
        return [
            'success' => false,
            'error' => 'TTS failed with code: ' . $httpCode,
            'response' => $audioData
        ];
    }
}

// YOUR CREDENTIALS
$apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';  // Paste your full key
$region = 'canadaeast';

// Test it
$text = "Hello, I'm Zuzoo, your AI hypnotherapy assistant. I'm here to help you feel more relaxed and at peace.";

echo "Generating speech...\n";

$result = generateAzureSpeech($text, $apiKey, $region);

if ($result['success']) {
    file_put_contents('zuzoo_test.mp3', $result['audio']);
    echo "✅ SUCCESS!\n";
    echo "Audio saved: zuzoo_test.mp3\n";
    echo "Size: " . round($result['size'] / 1024, 1) . " KB\n";
} else {
    echo "❌ Error: " . $result['error'] . "\n";
    if (isset($result['response'])) {
        echo "Response: " . substr($result['response'], 0, 200) . "\n";
    }
}
?>
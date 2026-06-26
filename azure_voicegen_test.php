<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
$region = 'canadaeast';

// Create audio_files directory if it doesn't exist
if (!file_exists('audio_files')) {
    mkdir('audio_files', 0777, true);
}

// Get token function
function getAzureToken($apiKey, $region) {
    $ch = curl_init("https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Ocp-Apim-Subscription-Key: ' . $apiKey,
        'Content-Length: 0'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $token = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($status == 200) ? $token : false;
}

// Generate speech function
function generateSpeech($token, $region, $voice, $text, $filename, $rate = '0.9', $pitch = 'medium', $style = 'calm') {
    // Build SSML based on voice capabilities
    $voicesWithStyle = ['en-US-SaraNeural', 'en-US-AriaNeural', 'en-US-GuyNeural', 'en-US-JennyNeural'];
    
    if (in_array($voice, $voicesWithStyle)) {
        $ssml = "<speak version='1.0' xml:lang='en-US'>
            <voice name='{$voice}'>
                <mstts:express-as style='{$style}'>
                    <prosody rate='{$rate}' pitch='{$pitch}'>
                        {$text}
                    </prosody>
                </mstts:express-as>
            </voice>
        </speak>";
    } else {
        $ssml = "<speak version='1.0' xml:lang='en-US'>
            <voice name='{$voice}'>
                <prosody rate='{$rate}' pitch='{$pitch}'>
                    {$text}
                </prosody>
            </voice>
        </speak>";
    }
    
    $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/ssml+xml',
        'X-Microsoft-OutputFormat: audio-16khz-128kbitrate-mono-mp3',
        'User-Agent: PHPTest'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $audio = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status == 200 && strlen($audio) > 1000) {
        file_put_contents($filename, $audio);
        return true;
    }
    return false;
}
?>
</body>
</html>
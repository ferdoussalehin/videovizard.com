<?php
/**
 * Azure TTS Functions for Zuzoo Hypnotherapy - UPDATED VERSION
 * Saves to: audio_messages/action_type.mp3 (lowercase)
 */

const AZURE_API_KEY = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
const AZURE_REGION = 'canadaeast';
const AUDIO_DIR = 'audio_messages';

/**
 * Generate speech audio using Azure Text-to-Speech API
 * @param string $text The text or SSML to convert to speech
 * @param string $filename The filename without extension (will be converted to lowercase)
 * @param string $directory The directory to save in (default: 'audio_messages')
 * @param string $voice Voice name (default: 'en-US-SaraNeural')
 * @param float $rate Speech rate (default: 0.9, range: 0.5-2.0)
 * @param string $pitch Pitch level (default: 'medium')
 * @param string $style Speaking style (default: 'calm')
 * @return array ['success' => bool, 'audio_url' => string, 'filename' => string, 'error' => string]
 */
function generateAzureSpeech(string $text, string $filename, string $directory = 'audio_messages', string $voice = 'en-US-SaraNeural', float $rate = 0.9, string $pitch = 'medium', string $style = 'calm'): array {
    
    // Create directory if it doesn't exist
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }
    
    // Convert filename to lowercase and remove any extension
    $cleanFilename = strtolower(basename($filename, '.mp3'));
    
    // Build full filepath: directory/filename.mp3
    $filepath = $directory . '/' . $cleanFilename . '.mp3';
    
    // Debug log
    error_log("=== AZURE TTS DEBUG ===");
    error_log("Input filename: " . $filename);
    error_log("Directory: " . $directory);
    error_log("Clean filename: " . $cleanFilename);
    error_log("Final filepath: " . $filepath);
    
    // Get Azure token
    $token = getAzureToken(AZURE_API_KEY, AZURE_REGION);
    if (!$token) {
        return [
            'success' => false,
            'error' => 'Failed to get Azure authentication token',
            'audio_url' => null,
            'filename' => null
        ];
    }
    
    // Generate speech
    $success = generateSpeechAudio($token, AZURE_REGION, $voice, $text, $filepath, $rate, $pitch, $style);
    
    if ($success) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $audioUrl = $protocol . '://' . $host . '/' . $filepath;
        
        return [
            'success' => true,
            'audio_url' => $audioUrl,
            'filename' => $filepath,
            'error' => null
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to generate speech audio',
            'audio_url' => null,
            'filename' => null
        ];
    }
}

/**
 * Get Azure authentication token
 */
function getAzureToken(string $apiKey, string $region) {
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
    
    if ($status !== 200) {
        error_log("Azure Token Error: Status {$status}");
    }
    
    return ($status == 200) ? $token : false;
}

/**
 * Generate speech audio file from SSML
 */
function generateSpeechAudio(string $token, string $region, string $voice, string $text, string $filename, float $rate, string $pitch, string $style): bool {
    
    // Voices that support express-as styles
    $voicesWithStyle = [
        'en-US-SaraNeural', 
        'en-US-AriaNeural', 
        'en-US-GuyNeural', 
        'en-US-JennyNeural'
    ];
    
    $namespace = in_array($voice, $voicesWithStyle) ? " xmlns:mstts='http://www.w3.org/2001/mstts'" : "";

    // Build SSML
    if (in_array($voice, $voicesWithStyle)) {
        $ssml = "<speak version='1.0'{$namespace} xml:lang='en-US'>
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
        'User-Agent: ZuzooTTS'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $audio = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status !== 200) {
        error_log("Azure TTS Error: Status {$status}"); 
        return false;
    }
    
    if (strlen($audio) > 1000) {
        file_put_contents($filename, $audio);
        error_log("Audio saved successfully to: " . $filename);
        return true;
    }
    
    error_log("Audio too small: " . strlen($audio) . " bytes");
    return false;
}
?>
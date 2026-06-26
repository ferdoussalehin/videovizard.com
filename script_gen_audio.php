<?php
/**
 * Generates audio from a hypnotherapy script using ElevenLabs API
 * 
 * @param string $script_text The full hypnotherapy script text
 * @param int $user_id User ID for file naming and tracking
 * @param string $voice_id (optional) ElevenLabs voice ID, defaults to 'pNInz6obpgDQGcFmaJgB' (Adam)
 * @return array ['success' => bool, 'filename' => string, 'audio_url' => string, 'error' => string]
 */

// ✅ DEFINE THE FUNCTION FIRST


 
require_once __DIR__ . '/azure_tts_utility.php'; 

function generateAudioFromScript_azure($script_text, $data) 
{
	$preferred_language = $data['preferred_language'] ?? 'english';
	$client_name        = $data['client-name'] ?? 'inamalvi';
    // Use an underscore or dash for better filename separation
    $output_filename = "audio_files/".$data['client_id'] . "-" . $client_name . "_audio.mp3";
    error_log("=======client id not found gen audio n === ".$output_filename, 3, __DIR__ . "/a_debug.log");
	//die;
    // Default Voice ID
    $voice_id = "en-US-TonyNeural";

    // --- CRITICAL FIX: Voice Selection Logic ---
    if ($preferred_language == "english") {
        $voice_id = "en-US-TonyNeural";
    } else if ($preferred_language == "urdu") {
        $voice_id = "ur-PK-AsadNeural"; // Placeholder Urdu Voice
    } else if ($preferred_language == "arabic") {
        $voice_id = "ar-EG-ShakirNeural"; // Placeholder Arabic Voice
    } 
    // Add more language checks (e.g., 'else if ($preferred_language == "french") { ... }')
    // If the language isn't found, it will default to en-US-TonyNeural.

    // --- API Configuration ---
    $azure_apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
    // const definitions are fine outside of a function, use define() inside functions
    define('AZURE_REGION', 'canadaeast'); 
    define('AZURE_API_KEY', $azure_apiKey);

	$token = getAzureToken($azure_apiKey, AZURE_REGION); 
    // --- Token Check (Requires $token to be defined before this point) ---
    if (!$token) {
        header("HTTP/1.1 500 Internal Server Error");
		error_log(">Error: Failed to obtain Azure authentication token  " . $symptoms. PHP_EOL, 3, __DIR__ . "/a_debug.log");
        exit;
    }
	error_log("********* data for real geneate : ".$output_filename, 3, __DIR__ . "/a_debug.log");

    // --- Speech Generation (Requires $text_to_speak to be defined) ---
    $success = generateSpeech($token, AZURE_REGION, $script_text, $voice_id, $output_filename);
    
    if ($success) {
        return $output_filename;
    } else {
        return "ERROR";
    }

}

function generateAudioFromScript($script_text, $user_id, $voice_id = 'pNInz6obpgDQGcFmaJgB') {
    $ELEVENLABS_API_KEY = "sk_ec6e1134b726e22e5b70e13d0d79b50e1f5a30d4fdcbef8d";
    
    // Fixed filename for stitching
    $filename = "client_therapeutic.mp3";
    $filepath = __DIR__ . "/audio_files/" . $filename;
    
    if (!file_exists(__DIR__ . "/audio_files/")) {
        mkdir(__DIR__ . "/audio_files/", 0755, true);
    }
    
    // Delete existing file if it exists
    if (file_exists($filepath)) {
        unlink($filepath);
        error_log("Deleted existing file: $filepath", 3, __DIR__ . "/a_debug.log");
    }
    
    // ✅ UPDATED: Use eleven_v3 model
    $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voice_id}";
    
    $payload = json_encode([
        "text" => $script_text,
        "model_id" => "eleven_v3",  // ✅ CHANGED FROM eleven_monolingual_v1
        "voice_settings" => [
            "stability" => 0.5,
            "similarity_boost" => 0.75,
            "style" => 0.0,
            "use_speaker_boost" => true
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: audio/mpeg",
        "Content-Type: application/json",
        "xi-api-key: " . $ELEVENLABS_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    
    $audio_data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "cURL Error: $error"];
    }
    
    if ($httpCode !== 200) {
        $error_msg = $audio_data;
        if ($json = json_decode($audio_data, true)) {
            $error_msg = $json['detail']['message'] ?? json_encode($json);
        }
        return ['success' => false, 'error' => "ElevenLabs API Error (HTTP $httpCode): $error_msg"];
    }
    
    if (file_put_contents($filepath, $audio_data) === false) {
        return ['success' => false, 'error' => "Failed to save audio file to: $filepath"];
    }
    
    error_log("Saved new audio as: $filepath", 3, __DIR__ . "/a_debug.log");
    
    $base_url = "https://sulaimania/audio_files/";
    $audio_url = $base_url . $filename;
    
    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $filepath,
        'audio_url' => $audio_url,
        'filesize_bytes' => filesize($filepath),
        'error' => ''
    ];
}

/*
// ✅ NOW CALL THE FUNCTION (after it's defined)
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Test data
$test_script = "Welcome to your personalized hypnotherapy session. Take a deep breath and relax. You are safe, calm, and in control.";
$user_id = 1; // ✅ Change to real user ID

// Run the function
$audio_result = generateAudioFromScript($test_script, $user_id);

// ✅ FIXED: Use correct array keys
if ($audio_result['success']) {
    echo "✅ Audio generated successfully!<br>";
    echo "Filename: " . $audio_result['filename'] . "<br>";
    echo "URL: " . $audio_result['audio_url'] . "<br>";
    echo "Size: " . round($audio_result['filesize_bytes'] / 1024, 2) . " KB<br>";
    echo '<audio controls><source src="' . $audio_result['audio_url'] . '" type="audio/mpeg"></audio>';
} else {
    echo "❌ Error: " . $audio_result['error'];
}

die; // End script after test

*/
<?php
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
session_start();
require_once __DIR__ . '/function_store.php';
require_once __DIR__ . '/followup_logic.php';  

 

require_once __DIR__ . '/azure_tts_utility.php';
$azure_apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
$voice_id = "en-US-TonyNeural"; 

// ============================================
// DATABASE CONNECTION
// ============================================
$dbhost = "localhost";
$dbase = "hypnotherapy_db"; 
$dbuser = "inaamalvi1403"; 
$dbpass = "AllahuAkbar786"; 

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
	echo "error 0 .......";
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => mysqli_connect_error()
    ]);
    exit();
}
$logFile = "a_debug.log";


//$token = 'YOUR_AZURE_ACCESS_TOKEN'; // The token you retrieved from the authentication service
define('AZURE_REGION', 'canadaeast'); // Replace with your actual region (e.g., 'westus2')
define('AZURE_API_KEY', $azure_apiKey); 

$text_to_speak = "Hello, this is a test of the Azure text to speech service using a specified voice ID.";
$voice_id = 'en-US-TonyNeural'; // **The new, fifth parameter you added**
$output_filename = 'audio_files/speech_output.mp3';
$region = 'canadaeast';
$text_to_speak = "why not try this para now";
$data =[];
$data['user_id'] = $data['user_id'] ?? 1;
$data['message_type'] = $data['message_type'] ?? '';


 $client_id = 1; // Replace with your actual client_id

$filename = AUDIO_DIR . '/' . $clientId . '-therapeutic_script.mp3';
$token    = getAzureToken(AZURE_API_KEY, AZURE_REGION);

if (!$token) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>Error: Failed to obtain Azure authentication token.</h1>";
    echo "<p>Please check your API key, region, and server logs for details.</p>";
    exit;
}
// Wrap in SSML for Azure TTS



// Get first 500 characters

//$short = substr($newtext, 0, 500);
$short = 'inam, for two years, you ve been experiencing anxiety that shows up as tiredness, gut issue, and high blood pressure, and you feel depressed.<break time="2s"/> And this fills your mind with worries about the future, your job, and marriage.<break time="2s"/> Especially when people are at home, during mostly morning times, these feelings become stronger.<break time="2s"/> Each episode typically lasts about 1 hour.<break time="2s"/>';
echo "<br><br>short is .................................".$short;
$newtext = htmlspecialchars($short);
echo "<br><br>short is ....22222.............................".$newtext;
$rate = 0.9;
$pitch = 'medium';
$style = 'calm';
//$success = generateSpeechAudio($token, AZURE_REGION,  $voice_id,$short, $output_filename);
$success = generateSpeechAudio($token, AZURE_REGION, $voice_id, $short, $filename, $rate, $pitch, $style);
 //          generateSpeechAudio($token, AZURE_REGION, $voice,    $text,  $filename, $rate, $pitch, $style);
// 4. Output Result
if ($success) {
    echo "<h1>Success!</h1>";
    echo "<p>Text converted to speech and saved to: <strong>$output_filename</strong></p>";
    // Optional: Provide a download/play link
    echo '<audio controls><source src="' . $output_filename . '" type="audio/wav">Your browser does not support the audio element.</audio>';
} else {
    // This will trigger if the speech API fails, even if the token worked.
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>Error: Failed to generate speech audio.</h1>";
    echo "<p>See server logs for the specific Azure API error message.</p>";
}

die;



?>
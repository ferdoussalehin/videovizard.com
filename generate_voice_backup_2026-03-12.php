<?php
// 1. Setup
$debugFile = __DIR__ . '/azure_debug.log';
ini_set('error_log', $debugFile);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

include 'azure_tts_utility.php';
include 'dbconnect_hdb.php';

// Try to include getID3 if available, otherwise use fallback
$use_getid3 = false;
if (file_exists(__DIR__ . '/getID3/getid3.php')) {
    require_once __DIR__ . '/getID3/getid3.php';
    $use_getid3 = true;
}

$audioFolder = 'podcast_audios/'; 

// --- 2. Input Data ---
$text       = isset($_POST['text']) ? trim($_POST['text']) : '';
$langCode   = isset($_POST['lang_code']) ? $_POST['lang_code'] : 'en-US';
$voice_id   = isset($_POST['voice_id']) ? $_POST['voice_id'] : 'en-US-GuyNeural';
$rowIdValue = isset($_POST['row_id']) ? $_POST['row_id'] : '';
$rate       = isset($_POST['rate']) ? $_POST['rate'] : '0.85';
$custom_filename = isset($_POST['filename']) ? $_POST['filename'] : '';
$podcast_id = isset($_POST['podcast_id']) ? intval($_POST['podcast_id']) : 0;
$retry_count = isset($_POST['retry_count']) ? intval($_POST['retry_count']) : 0;

if ($text === '' || $rowIdValue === '') {
    error_log("DEBUG: Missing required POST data.");
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

$rowId = intval($rowIdValue);

if (!empty($custom_filename)) {
    $filename = $custom_filename;
} else {
    $filename = "voice_{$rowId}_{$langCode}.mp3";
}
$fullFilePath = $audioFolder . $filename;

// --- 3. Build SSML String ---
$detectedLang = substr($voice_id, 0, 5); 

// Clean the text - remove any existing SSML tags
$text = preg_replace('/<[^>]*>/', '', $text);
$text = trim($text);

// Split into sentences and add appropriate breaks
$sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
$cleanContent = '';

foreach ($sentences as $sentence) {
    $sentence = trim($sentence);
    if ($sentence !== '') {
        // Add a 250ms break between sentences for natural pacing
        $cleanContent .= htmlspecialchars($sentence, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '<break time="250ms"/> ';
    }
}

$ssml = "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='$detectedLang'>";
$ssml .= "<voice name='$voice_id'>";
$ssml .= "<prosody rate='$rate'>$cleanContent</prosody>";
$ssml .= "</voice></speak>";

// LOG THE SSML FOR DEBUGGING
error_log("--- NEW REQUEST (Row $rowId) ---");
error_log("DEBUG SSML: " . $ssml);

// --- 4. Azure Request with Retry Logic ---
$AZURE_REGION  = 'canadaeast';
$AZURE_API_KEY = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';

if (!is_dir($audioFolder)) mkdir($audioFolder, 0777, true);

// Function to get audio duration (fallback methods)
function getAudioDuration($filepath, $text = '') {
    if (!file_exists($filepath)) {
        return estimateDurationFromText($text);
    }
    
    global $use_getid3;
    
    // Method 1: getID3 if available
    if ($use_getid3) {
        try {
            $getID3 = new getID3;
            $fileInfo = $getID3->analyze($filepath);
            
            if (isset($fileInfo['playtime_seconds']) && $fileInfo['playtime_seconds'] > 0) {
                return round($fileInfo['playtime_seconds'], 2);
            }
        } catch (Exception $e) {
            error_log("getID3 error: " . $e->getMessage());
        }
    }
    
    // Method 2: ffprobe if available
    $ffprobe_path = 'ffprobe'; // or full path
    $output = shell_exec("$ffprobe_path -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filepath) . " 2>&1");
    
    if ($output && is_numeric(trim($output))) {
        return round(floatval(trim($output)), 2);
    }
    
    // Method 3: Estimate from file size for MP3 (128kbps = 16KB/s)
    $filesize = filesize($filepath);
    if ($filesize > 0) {
        $estimatedSeconds = $filesize / (128 * 1024 / 8); // 128kbps = 16KB/s
        return round($estimatedSeconds, 2);
    }
    
    // Method 4: Estimate from text
    return estimateDurationFromText($text);
}

function estimateDurationFromText($text) {
    $wordCount = str_word_count($text);
    // Average speaking rate: 150 words per minute = 2.5 words per second
    $estimatedSeconds = $wordCount / 2.5;
    return round($estimatedSeconds, 2);
}

// Function to make Azure request with retry
function makeAzureRequest($ssml, $token, $AZURE_REGION, $retryCount = 0) {
    $url = "https://{$AZURE_REGION}.tts.speech.microsoft.com/cognitiveservices/v1";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/ssml+xml', 
        'X-Microsoft-OutputFormat: audio-16khz-128kbitrate-mono-mp3',
        'User-Agent: PHP-CalmBot'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 second timeout
    
    $audio = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => $http_code == 200 && strlen($audio) > 500,
        'audio' => $audio,
        'http_code' => $http_code,
        'curl_error' => $curl_error,
        'retry_count' => $retryCount
    ];
}

try {
    $token = getAzureToken($AZURE_API_KEY, $AZURE_REGION);
    
    // Make initial request
    $result = makeAzureRequest($ssml, $token, $AZURE_REGION, $retry_count);
    
    // Handle rate limiting (429) with retry
    if ($result['http_code'] == 429 && $retry_count < 3) {
        $waitTime = pow(2, $retry_count) * 2; // Exponential backoff: 2s, 4s, 8s
        error_log("DEBUG: Rate limited (429). Retry {$retry_count}/3. Waiting {$waitTime} seconds...");
        
        sleep($waitTime);
        
        // Try again with incremented retry count
        $result = makeAzureRequest($ssml, $token, $AZURE_REGION, $retry_count + 1);
    }
    
    if ($result['success']) {
        // Save the audio file
        file_put_contents($fullFilePath, $result['audio']);
        
        // Get audio duration
        $audio_duration = getAudioDuration($fullFilePath, $text);
        
        // --- 5. Database Update with Duration ---
        if (isset($conn)) {
            $safe_file = mysqli_real_escape_string($conn, $filename);
            $safe_voice = mysqli_real_escape_string($conn, $voice_id);
            
            // First update the specific scene
            $query = "UPDATE hdb_podcast_stories SET 
                      audio_file = '$safe_file',
                      voice_id = '$safe_voice',
                      voice_rate = '$rate',
                      audio_duration = $audio_duration
                      WHERE id = $rowId";
            
            if(mysqli_query($conn, $query)) {
                error_log("DEBUG: DB Success for row $rowId, duration: {$audio_duration}s");
                
                // If podcast_id is provided, also update the podcast total duration
                if ($podcast_id > 0) {
                    // Calculate total duration for all scenes in this podcast
                    $total_query = "SELECT SUM(audio_duration) as total_duration 
                                   FROM hdb_podcast_stories 
                                   WHERE podcast_id = $podcast_id";
                    $total_result = mysqli_query($conn, $total_query);
                    
                    if ($total_result && mysqli_num_rows($total_result) > 0) {
                        $total_row = mysqli_fetch_assoc($total_result);
                        $total_duration = $total_row['total_duration'] ?: 0;
                        
                        // Update podcast with total duration
                        $update_podcast = "UPDATE hdb_podcasts SET 
                                          total_duration = $total_duration 
                                          WHERE id = $podcast_id";
                        mysqli_query($conn, $update_podcast);
                        error_log("DEBUG: Updated podcast #$podcast_id total duration: {$total_duration}s");
                    }
                }
            } else {
                error_log("DEBUG: DB Error: " . mysqli_error($conn));
            }
        }

        // Return success with duration
        $response = [
            'success' => true, 
            'file' => $fullFilePath,
            'filename' => $filename,
            'duration' => $audio_duration,
            'retry_count' => $result['retry_count']
        ];
        
        echo json_encode($response);
        
    } else {
        // LOG THE FULL ERROR
        error_log("DEBUG: Azure Fail - HTTP {$result['http_code']}");
        if ($result['http_code'] == 429) {
            error_log("DEBUG: Rate limit exceeded after {$result['retry_count']} retries");
        }
        
        $response = [
            'success' => false, 
            'message' => "Azure Error {$result['http_code']}",
            'http_code' => $result['http_code'],
            'retry_count' => $result['retry_count']
        ];
        
        if ($result['curl_error']) {
            $response['curl_error'] = $result['curl_error'];
        }
        
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    error_log("DEBUG: Exception: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
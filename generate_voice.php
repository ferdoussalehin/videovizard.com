<?php
// 1. Setup
$debugFile = __DIR__ . '/azure_debug.log';
ini_set('error_log', $debugFile);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

include 'azure_tts_utility.php';
include 'dbconnect_hdb.php'; 
$audioFolder = 'podcast_audios/'; 

// --- 2. Input Data ---
$text       = isset($_POST['text']) ? trim($_POST['text']) : '';
$langCode   = isset($_POST['lang_code']) ? $_POST['lang_code'] : 'en-US';
$voice_id   = isset($_POST['voice_id']) ? $_POST['voice_id'] : 'en-US-GuyNeural';
$rowIdValue = isset($_POST['row_id']) ? $_POST['row_id'] : '';
$rate       = isset($_POST['rate']) ? $_POST['rate'] : '0.85';
$custom_filename = isset($_POST['filename']) ? $_POST['filename'] : '';

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

if (!is_dir($audioFolder)) mkdir($audioFolder, 0777, true);

// ============================================================
// ROUTE: OpenAI TTS  vs  Azure TTS
// Voice keys stored as "openai_alloy", "openai_nova", etc.
// trigger the OpenAI path. All other keys go to Azure.
// ============================================================

if (stripos($voice_id, 'openai') !== false) {

    // ── OpenAI TTS ───────────────────────────────────────────

    $OPENAI_API_KEY = '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';

    // Strip "openai_" prefix to get bare voice name
    // e.g. "openai_alloy" -> "alloy", "openai_nova" -> "nova"
    $bare_voice = preg_replace('/^openai[_\-:]?/i', '', $voice_id);
    if ($bare_voice === '') $bare_voice = 'alloy';

    error_log("--- NEW REQUEST [OpenAI] (Row $rowId) ---"); 
    error_log("DEBUG voice: $bare_voice | chars: " . strlen($text));

    $payload = json_encode([
        'model' => 'gpt-4o-mini-tts',
        'voice' => $bare_voice,
        'input' => $text
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $OPENAI_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 60
    ]);

    $audio      = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code == 200 && strlen($audio) > 500) {
        file_put_contents($fullFilePath, $audio);

        // DB update
        if (isset($conn)) {
            $safe_file = mysqli_real_escape_string($conn, $filename);
            $query = "UPDATE hdb_podcast_stories SET audio_file = '$safe_file' WHERE id = $rowId";
            if (mysqli_query($conn, $query)) {
                error_log("DEBUG: DB Success for row $rowId");
            } else {
                error_log("DEBUG: DB Error: " . mysqli_error($conn));
            }
        }

        echo json_encode(['success' => true, 'file' => $fullFilePath, 'filename' => $filename]);

    } else {
        // OpenAI returns a JSON error body on failure
        $err_body = json_decode($audio, true);
        $err_msg  = $err_body['error']['message'] ?? ("HTTP $http_code");
        error_log("DEBUG: OpenAI Fail - HTTP $http_code | $err_msg");
        if ($curl_error) error_log("DEBUG: Curl Error: " . $curl_error);

        echo json_encode(['success' => false, 'message' => 'OpenAI TTS Error: ' . $err_msg]);
    }

} else {

    // ── Azure Neural TTS (original logic, unchanged) ─────────

    // Build SSML — same as before
    $detectedLang = substr($voice_id, 0, 5);

    $lines = explode("\n", $text);
    $cleanContent = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $cleanContent .= $line . ' ';
        }
    }

    $ssml  = "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='$detectedLang'>";
    $ssml .= "<voice name='$voice_id'>";
    $ssml .= "<prosody rate='$rate'>$cleanContent</prosody>";
    $ssml .= "</voice></speak>";

    error_log("--- NEW REQUEST [Azure] (Row $rowId) ---");
    error_log("DEBUG SSML: " . $ssml);

    $AZURE_REGION  = 'canadaeast';
    $AZURE_API_KEY = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';

    try {
        $token = getAzureToken($AZURE_API_KEY, $AZURE_REGION);

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

        $audio      = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code == 200 && strlen($audio) > 500) {
            file_put_contents($fullFilePath, $audio);

            // DB update
            if (isset($conn)) {
                $safe_file = mysqli_real_escape_string($conn, $filename);
                $query = "UPDATE hdb_podcast_stories SET audio_file = '$safe_file' WHERE id = $rowId";
                if (mysqli_query($conn, $query)) {
                    error_log("DEBUG: DB Success for row $rowId");
                } else {
                    error_log("DEBUG: DB Error: " . mysqli_error($conn));
                }
            }

            echo json_encode(['success' => true, 'file' => $fullFilePath, 'filename' => $filename]);

        } else {
            error_log("DEBUG: Azure Fail - HTTP $http_code");
            error_log("DEBUG: Azure Response Body: " . $audio);
            if ($curl_error) error_log("DEBUG: Curl Error: " . $curl_error);

            echo json_encode(['success' => false, 'message' => "Azure Error $http_code", 'details' => $audio]);
        }

    } catch (Exception $e) {
        error_log("DEBUG: Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
